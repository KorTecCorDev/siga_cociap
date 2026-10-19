<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\CalificacionModel;
use App\Models\DesempateMeritoModel;
use App\Models\DirectorEbrModel;
use App\Models\OrdenMeritoModel;
use App\Models\RetornoGradoModel;
use App\Models\TutorPeriodoModel;
use Core\Session;
use Core\View;

/**
 * OrdenMeritoController
 * Ranking bimestral por grado.
 */
class OrdenMeritoController extends BaseController
{
    /**
     * Quien ESCRIBE. Los directores entran a este controlador y VEN, pero
     * desde el 24/08/2026 no operan: su rol es de supervision en solo
     * lectura. Se valida en cada metodo de escritura, NO ocultando el boton
     * en la vista: esconder la UI no es control de acceso.
     */
    private const ROLES_ESCRIBEN = ['admin', 'registro_academico'];

    private CalificacionModel    $calModel;
    private DirectorEbrModel     $dirModel;
    private DesempateMeritoModel $desempateModel;
    private OrdenMeritoModel     $ordenMeritoModel;

    public function __construct()
    {
        $this->requireRole([
            'admin', 'registro_academico',
            ...ROLES_DIRECCION,
        ]);
        $this->calModel         = new CalificacionModel();
        $this->dirModel         = new DirectorEbrModel();
        $this->desempateModel   = new DesempateMeritoModel();
        $this->ordenMeritoModel = new OrdenMeritoModel();
    }

    /**
     * GET /director/orden-merito
     * Lista los periodos disponibles para ver el ranking.
     */
    public function index(): void
    {
        // El <select> de bimestre del gestor envía aquí `?periodo_id` (con la
        // selección de secciones): se reenvía a la URL del periodo.
        $crudo = $this->query('periodo_id');
        if (is_string($crudo) && ctype_digit($crudo)) {
            redirect($this->rutaGestor((int) $crudo, $this->idsCrudos()));
        }

        $this->view('director/orden-merito', [
            'titulo'   => 'Orden de mérito',
            'periodos' => $this->periodosDisponibles(),
        ]);
    }

    /**
     * GET /director/ranking-seccion
     * El ranking por SECCIÓN vive desde el 19/10/2026 en el mismo gestor que el
     * orden de mérito por grado (`porPeriodo`): la ruta vieja redirige.
     */
    public function seccionIndex(): void
    {
        $this->redirigirRutaRenombrada('director/ranking-seccion', 'director/orden-merito');
    }

    /**
     * GET /director/ranking-seccion/{periodo_id}
     * Redirige al gestor del mismo periodo (19/10/2026), con su query string.
     */
    public function seccionPorPeriodo(string $periodoId): void
    {
        $this->redirigirRutaRenombrada('director/ranking-seccion', 'director/orden-merito');
    }

    /**
     * GET /director/orden-merito/{periodo_id}
     * GESTOR del mérito (19/10/2026, mismo patrón que el Acompañamiento
     * pedagógico): orden de mérito por GRADO y ranking por SECCIÓN en una sola
     * pantalla, filtrados por `?secciones[]`. Sin marcar = todas.
     *
     * ⚠️ NO APLICA LA COMPUERTA DE PUBLICACION (044): el claustro y las familias
     * ven el merito cuando su nivel se publica, pero el staff necesita revisarlo
     * ANTES —es el insumo para decidir—.
     *
     * Las fuentes (`rankingGrado`, `rankingPorSeccion`) son snapshot-aware:
     * bimestre cerrado -> ranking CONGELADO; activo -> en vivo sobre lo bloqueado.
     */
    public function porPeriodo(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->buscarPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        $merito = $this->componerMerito($periodoId);

        $this->view('director/orden-merito-periodo', [
            // Vista de SOLO LECTURA para los directores: sin controles.
            'puedeEscribir'   => has_role(self::ROLES_ESCRIBEN),
            'titulo'          => 'Orden de mérito — ' . $periodo['nombre_display'],
            'periodo'         => $periodo,
            'periodos'        => $this->periodosDisponibles(),
            'merito'          => $merito,
            'provisional'     => ($periodo['estado'] ?? '') === 'activo',
            'tieneDesempates' => $this->desempateModel->contarPorPeriodo($periodoId) > 0,
        ]);
    }

    /**
     * GET /director/orden-merito/{periodo_id}/desempates
     * Acta de las resoluciones de empate del periodo (pantalla de consulta).
     * Documenta qué decisión se tomó, quién la tomó y el motivo, para poder
     * explicarla a docentes y padres de familia.
     */
    public function desempates(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $data = $this->buildActaData($periodoId);

        if ($data === null) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        $this->view('director/desempates', [
            'titulo'       => 'Acta de desempates — ' . $data['periodo']['nombre_display'],
            'periodo'      => $data['periodo'],
            'resoluciones' => $data['resoluciones'],
        ]);
    }

    /**
     * GET /director/orden-merito/{periodo_id}/desempates/imprimir
     * Acta imprimible (A4 portrait) con cabecera institucional y firma del
     * Director EBR vigente: constancia oficial de las resoluciones de empate.
     */
    public function desempatesImprimir(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $data = $this->buildActaData($periodoId);

        if ($data === null) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        View::setLayout('print');
        $this->view('director/desempates-acta', [
            'titulo'       => 'Acta de Resolución de Empates — '
                . $data['periodo']['nombre_display'] . ' ' . $data['periodo']['anio'],
            'periodo'      => $data['periodo'],
            'resoluciones' => $data['resoluciones'],
            'institucion'  => config('institucion'),
            'directorEbr'  => $this->getDirectorEbr($data['periodo']),
        ]);
    }

    /**
     * Carga común del acta de desempates: periodo + resoluciones enriquecidas con
     * las métricas (promedio, distribución AD/B/C, puesto) tomadas de la fuente
     * única del ranking, para que coincidan exactamente con el orden de mérito.
     * Retorna null si el periodo no existe.
     */
    private function buildActaData(int $periodoId): ?array
    {
        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);

        if (!$periodo) {
            return null;
        }

        $resoluciones = $this->desempateModel->getActaPorPeriodo($periodoId);

        // Mapa matricula_id => métricas, calculado una vez por grado.
        $rankingCache = [];
        foreach ($resoluciones as &$res) {
            $gradoId = (int) $res['grado_id'];
            if (!isset($rankingCache[$gradoId])) {
                $mapa = [];
                foreach ($this->calcularRanking($gradoId, $periodoId) as $fila) {
                    $mapa[(int) $fila['matricula_id']] = $fila;
                }
                $rankingCache[$gradoId] = $mapa;
            }
            $mapa = $rankingCache[$gradoId];

            foreach ($res['alumnos'] as &$al) {
                $r = $mapa[(int) $al['matricula_id']] ?? null;
                $al['promedio_general'] = $r['promedio_general'] ?? null;
                $al['num_competencias'] = $r !== null ? (int) $r['num_competencias'] : null;
                $al['num_ad']           = $r !== null ? (int) $r['num_ad'] : null;
                $al['num_b']            = $r !== null ? (int) $r['num_b']  : null;
                $al['num_c']            = $r !== null ? (int) $r['num_c']  : null;
                $al['num_alto']         = $r !== null ? (int) $r['num_alto'] : null;
                $al['num_16']           = $r !== null ? (int) $r['num_16']   : null;
                $al['puesto_grado']     = $r['puesto'] ?? null;
            }
            unset($al);

            // Cuadro comparativo por competencia (numeral + literal) del grupo y
            // expediente completo (boleta, asistencia, conducta): lo MISMO que se
            // vio al decidir, desde el mismo punto único (17/10/2026).
            $ids = array_map(static fn($a) => (int) $a['matricula_id'], $res['alumnos']);
            $res['comparativo'] = $this->desempateModel->getComparativoCompetencias($ids, $periodoId);
            $res['expediente']  = $this->desempateModel->expediente($ids, $periodoId);
        }
        unset($res);

        return ['periodo' => $periodo, 'resoluciones' => $resoluciones];
    }

    /**
     * GET /director/orden-merito/{periodo_id}/imprimir
     * Acta imprimible (MODELO OFICIAL, docs/modulos/orden-merito.md §373).
     *
     * Filtros del gestor (19/10/2026), ambos opcionales:
     *   · `?secciones[]` — sin marcar = todas.
     *   · `?tipo=grado`   — solo las hojas de mérito de los grados tocados.
     *     `?tipo=seccion` — solo una hoja por cada sección elegida.
     *     Sin `tipo`      — el legajo: cada grado y a continuación sus secciones.
     * Sin ninguno de los dos sale el legajo completo de siempre.
     */
    public function imprimir(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->buscarPeriodo($periodoId);

        if (!$periodo) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        $tipo = $this->query('tipo');
        $tipo = in_array($tipo, ['grado', 'seccion'], true) ? $tipo : null;

        $merito = $this->componerMerito($periodoId);

        $ranking = [];
        foreach ($merito['ranking'] as $gid => $data) {
            $ranking[$gid] = $data + [
                'conteos' => $this->getConteosGrado($gid, $periodoId),
            ];
        }

        // Si hay algún empate sin resolver EN LO QUE SE IMPRIME, el reporte no
        // es oficializable.
        $hayPendientes = false;
        foreach ($ranking as $data) {
            if ($tipo !== 'seccion' && $this->rankingTienePendientes($data['general'])) {
                $hayPendientes = true;
                break;
            }
            if ($tipo === 'grado') {
                continue;
            }
            foreach ($data['por_seccion'] as $estudiantes) {
                if ($this->rankingTienePendientes($estudiantes)) {
                    $hayPendientes = true;
                    break 2;
                }
            }
        }

        // A4 VERTICAL (sin `doc-landscape`): la tabla solo necesita ~74mm de
        // columnas fijas, así que el ancho apaisado se desperdiciaba y el alto
        // (190mm) partía los grados grandes dejando las firmas huérfanas. En
        // retrato entran 55 filas por hoja (medido): 10 de los 11 grados caben
        // enteros con su bloque de firmas al pie.
        View::setLayout('print');
        $this->view('director/reporte-merito', [
            'titulo'        => 'Orden de mérito — ' . $periodo['nombre_display'] . ' ' . $periodo['anio'],
            'periodo'       => $periodo,
            'ranking'       => $ranking,
            'hojasGrado'    => $tipo !== 'seccion',
            'hojasSeccion'  => $tipo !== 'grado',
            'institucion'   => config('institucion'),
            'directorEbr'   => $this->getDirectorEbr($periodo),
            'hayPendientes' => $hayPendientes,
            // Cada hoja es un documento firmable: sin «Página X de Y» (contaría
            // todo el reporte). Ver _boleta.scss.
            'bodyClass'     => 'doc-lote',
        ]);
    }

    /**
     * Datos del gestor y del acta filtrada (19/10/2026). Un solo armado para la
     * pantalla y el A4, para que lo filtrado en pantalla sea lo que se imprime.
     *
     * Las casillas salen de las secciones QUE TIENEN ranking en el periodo (lo
     * que se puede mostrar e imprimir), no del catálogo del año.
     *
     * Devuelve:
     *   · `ranking`         [grado_id => {grado, general, por_seccion, tutores}],
     *                       solo los grados tocados por la selección. El ranking
     *                       del GRADO va siempre completo (no se recortan filas);
     *                       `por_seccion` solo trae las secciones elegidas.
     *   · `niveles`         nivel → grado → secciones {id, nombre, n, tutor_nombre},
     *                       con la forma que espera `riesgo_alcance()`.
     *   · `secciones_ids`   la selección validada (vacía = todas).
     *   · `alcance`         texto de la selección.
     *   · `total_secciones` cuántas secciones con ranking tiene el periodo.
     */
    private function componerMerito(int $periodoId): array
    {
        // Snapshot-aware: para un bimestre cerrado enumera desde el snapshot
        // (incluye grados congelados como la sección operativa de un retorno).
        $todo    = [];
        $niveles = [];
        $validos = [];
        foreach ($this->ordenMeritoModel->gradosConRanking($periodoId) as $grado) {
            $gid        = (int) $grado['id'];
            $porSeccion = $this->calcularRankingPorSeccion($gid, $periodoId);
            $tutores    = $this->getTutoresPorGrado($gid, $periodoId);

            $secciones = [];
            foreach ($porSeccion as $nombre => $filas) {
                $sid = (int) ($filas[0]['seccion_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $validos[$sid] = true;
                $secciones[]   = [
                    'id'           => $sid,
                    'nombre'       => (string) $nombre,
                    'n'            => count($filas),
                    'tutor_nombre' => $tutores[$nombre]['nombre'] ?? null,
                ];
            }

            $nid = (int) $grado['nivel_id'];
            $niveles[$nid] ??= ['nombre' => $grado['nivel_nombre'], 'grados' => []];
            $niveles[$nid]['grados'][$gid] = [
                'nombre'    => $grado['nombre_display'],
                'secciones' => $secciones,
            ];

            $todo[$gid] = [
                'grado'       => $grado,
                'por_seccion' => $porSeccion,
                'tutores'     => $tutores,
            ];
        }

        // ?secciones[] llega de la URL y se VALIDA contra las secciones con
        // ranking del periodo (mismo criterio que el Acompañamiento): un escalar
        // o un arreglo anidado se descartan (`(int)` de un arreglo vale 1), y un
        // id que no existe también.
        $ids = [];
        foreach ($this->idsCrudos() as $id) {
            if (isset($validos[$id])) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);

        $ranking = [];
        foreach ($todo as $gid => $data) {
            $porSeccion = $data['por_seccion'];
            if ($ids !== []) {
                $porSeccion = array_filter(
                    $porSeccion,
                    static fn(array $filas): bool => in_array((int) ($filas[0]['seccion_id'] ?? 0), $ids, true)
                );
                if ($porSeccion === []) {
                    continue;
                }
            }
            $ranking[$gid] = [
                'grado'       => $data['grado'],
                'general'     => $this->calcularRanking($gid, $periodoId),
                'por_seccion' => $porSeccion,
                'tutores'     => $data['tutores'],
            ];
        }

        return [
            'ranking'         => $ranking,
            'niveles'         => $niveles,
            'secciones_ids'   => $ids,
            'alcance'         => riesgo_alcance($niveles, $ids),
            'total_secciones' => count($validos),
        ];
    }

    /**
     * Ids de `?secciones[]` tal como llegan, solo los que son dígitos. NO dice si
     * existen: eso lo decide `componerMerito()` contra el periodo.
     */
    private function idsCrudos(): array
    {
        $crudo = $this->query('secciones');
        $ids   = [];
        foreach (is_array($crudo) ? $crudo : [] as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }
        return $ids;
    }

    /** URL del gestor de un periodo con una selección de secciones. */
    private function rutaGestor(int $periodoId, array $secciones): string
    {
        $q = $secciones ? '?' . http_build_query(['secciones' => array_values($secciones)]) : '';
        return '/director/orden-merito/' . $periodoId . $q;
    }

    /** Periodo con su año, o null. */
    private function buscarPeriodo(int $periodoId): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]) ?: null;
    }

    /** Periodos que se pueden consultar: activos y cerrados. */
    private function periodosDisponibles(): array
    {
        return $this->calModel->query("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");
    }

    /**
     * GET /director/orden-merito/{periodo_id}/desempate/{grado_id}
     * Expediente de los empates irreducibles de un grado y formulario para
     * resolverlos.
     *
     * Desde el 17/10/2026 los directores ENTRAN a ver el expediente en solo
     * lectura (decisión del usuario): el formulario solo se pinta con
     * `puedeEscribir`, y resolver sigue guardado en `guardarDesempate`, que es
     * donde está el control de acceso de la escritura.
     */
    public function desempate(string $periodoId, string $gradoId): void
    {
        $periodoId = (int) $periodoId;
        $gradoId   = (int) $gradoId;

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);

        $grado = $this->calModel->queryOne("
            SELECT g.id, g.numero, g.nombre_display, n.nombre AS nivel_nombre
            FROM grados g
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE g.id = ?
        ", [$gradoId]);

        if (!$periodo || !$grado) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo o grado no encontrado.'
            );
        }

        // Grupos pendientes EN VIVO (no snapshot-aware): los mismos que ven el
        // guard del cierre y la card del Centro de Control. Ver
        // OrdenMeritoModel::gruposEmpatePendientesLive.
        $grupos = $this->ordenMeritoModel->gruposEmpatePendientesLive($gradoId, $periodoId);

        $evidencia = [];
        foreach ($grupos as $clave => $alumnos) {
            $ids = array_map(static fn($a) => (int) $a['matricula_id'], $alumnos);
            $evidencia[$clave] = [
                'comparativo' => $this->desempateModel->getComparativoCompetencias($ids, $periodoId),
                'expediente'  => $this->desempateModel->expediente($ids, $periodoId),
            ];
        }

        $this->view('director/orden-merito-desempate', [
            'titulo'        => 'Resolver empate — ' . $grado['nombre_display'],
            'periodo'       => $periodo,
            'grado'         => $grado,
            'grupos'        => $grupos,
            'evidencia'     => $evidencia,
            'criterios'     => DesempateMeritoModel::CRITERIOS,
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
        ]);
    }

    /**
     * POST /director/orden-merito/{periodo_id}/desempate/{grado_id}
     * Guarda la resolución manual de un empate irreducible (con motivo, auditada).
     */
    public function guardarDesempate(string $periodoId, string $gradoId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();

        $periodoId = (int) $periodoId;
        $gradoId   = (int) $gradoId;
        $destino   = url('director/orden-merito/' . $periodoId);

        $motivo    = trim((string) $this->input('motivo', ''));
        $orden     = $this->input('orden', []); // [matricula_id => posicion]
        $criterios = $this->input('criterio', []);
        $volver    = url('director/orden-merito/' . $periodoId . '/desempate/' . $gradoId);

        if ($motivo === '' || !is_array($orden) || count($orden) < 2) {
            $this->redirectWithError($destino, 'Falta el motivo o el orden del desempate.');
        }

        // Criterios aplicados (migración 081): al menos uno y todos de la lista.
        if (!is_array($criterios) || $criterios === []) {
            $this->redirectWithError($volver, 'Marca al menos un criterio aplicado.');
        }
        $criterios = array_values(array_unique($criterios, SORT_REGULAR));
        foreach ($criterios as $c) {
            if (!is_string($c) || !isset(DesempateMeritoModel::CRITERIOS[$c])) {
                $this->redirectWithError($volver, 'Revisa los criterios marcados.');
            }
        }
        if (mb_strlen($motivo) > 500) {
            $this->redirectWithError($volver, 'El motivo no puede superar los 500 caracteres.');
        }

        // Validar que las posiciones formen una permutación estricta 1..n (sin repetir).
        $posiciones = array_map('intval', array_values($orden));
        sort($posiciones);
        $esperado = range(1, count($orden));
        if ($posiciones !== $esperado) {
            $this->redirectWithError(
                $destino,
                'El orden debe asignar posiciones distintas y consecutivas (1, 2, 3...).'
            );
        }

        // Ordenar las matrículas por la posición designada.
        $ordenMatriculas = array_map('intval', array_keys($orden));
        usort($ordenMatriculas, static fn($a, $b) => (int) $orden[$a] <=> (int) $orden[$b]);

        $usuario = Session::user();
        try {
            $this->desempateModel->guardar(
                $periodoId,
                $gradoId,
                $ordenMatriculas,
                (int) ($usuario['id'] ?? 0),
                $motivo,
                $criterios
            );
        } catch (\Exception $e) {
            $this->redirectWithError($volver, 'No se pudo guardar la resolución del empate.');
        }

        $this->redirectWithSuccess($destino, 'Empate resuelto y registrado.');
    }

    /** ¿Alguna fila del ranking tiene un empate sin resolver? */
    private function rankingTienePendientes(array $estudiantes): bool
    {
        foreach ($estudiantes as $est) {
            if (!empty($est['empate_pendiente'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ranking de estudiantes de un grado en un periodo (con cascada de desempate).
     * Delega en OrdenMeritoModel — fuente única compartida con el buscador.
     */
    private function calcularRanking(int $gradoId, int $periodoId): array
    {
        return $this->ordenMeritoModel->rankingGrado($gradoId, $periodoId);
    }

    /**
     * Agrupa y rankea estudiantes dentro de cada sección del grado.
     * Retorna array [seccion_nombre => [top-N estudiantes con puesto]].
     */
    /**
     * Devuelve el tutor por sección para el grado dado, el DEL BIMESTRE del acta
     * (congelado al cerrarlo, `TutorPeriodoModel`; en curso → el actual).
     * Clave: seccion_nombre → {nombre, sexo}|null.
     */
    private function getTutoresPorGrado(int $gradoId, int $periodoId): array
    {
        $secciones = $this->calModel->query("
            SELECT s.id, s.nombre AS seccion_nombre
            FROM secciones s
            INNER JOIN grados g ON g.id = s.grado_id
            WHERE g.id = ?
            ORDER BY s.nombre
        ", [$gradoId]);

        $delPeriodo = (new TutorPeriodoModel())->tutoresDeSecciones(
            array_column($secciones, 'id'),
            $periodoId
        );

        $tutores = [];
        foreach ($secciones as $sec) {
            $tutor = $delPeriodo[(int) $sec['id']] ?? null;
            $tutores[$sec['seccion_nombre']] = $tutor === null ? null : [
                'nombre' => $tutor['nombre'],
                'sexo'   => $tutor['sexo'] ?? null,
            ];
        }

        return $tutores;
    }

    /**
     * Cuenta áreas y competencias distintas calificadas en el grado+periodo.
     * Excluye competencias transversales del conteo.
     */
    private function getConteosGrado(int $gradoId, int $periodoId): array
    {
        $resultado = $this->calModel->queryOne("
            SELECT
                COUNT(DISTINCT COALESCE(sa.area_id, comp.area_id)) AS num_areas,
                COUNT(DISTINCT cal.competencia_id)                  AS num_competencias
            FROM calificaciones cal
            INNER JOIN matriculas m       ON m.id    = cal.matricula_id
            INNER JOIN secciones s        ON s.id    = m.seccion_id
            INNER JOIN grados g           ON g.id    = s.grado_id
            INNER JOIN competencias comp  ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa        ON sa.id   = comp.subarea_id
            INNER JOIN areas a            ON a.id    = COALESCE(sa.area_id, comp.area_id)
            WHERE g.id           = ?
              AND cal.periodo_id = ?
              -- Retorno de grado: compite donde CURSÓ el bimestre (tramo, punto
              -- único RetornoGradoModel), igual que el ranking. La operativa
              -- revertida (`desactivado`) sigue contando en sus bimestres.
              AND (m.estado = 'aprobada' OR " . RetornoGradoModel::sqlOperativaRevertida('m') . ")
              " . RetornoGradoModel::sqlCursoElPeriodo('m', 'cal.periodo_id') . "
              AND a.tipo        != 'transversal'
        ", [$gradoId, $periodoId]);

        return [
            'num_areas'        => (int) ($resultado['num_areas']        ?? 0),
            'num_competencias' => (int) ($resultado['num_competencias'] ?? 0),
        ];
    }

    /**
     * Director EBR vigente hoy para el año del periodo.
     * Siempre usa la fecha actual: el reporte se imprime y firma hoy,
     * por quien ejerce el cargo en este momento.
     */
    private function getDirectorEbr(array $periodo): ?array
    {
        return $this->dirModel->getVigenteEnFecha((int) $periodo['anio_id']);
    }

    /**
     * Ranking por sección dentro del grado (con cascada de desempate por sección).
     * Delega en OrdenMeritoModel — fuente única compartida.
     */
    private function calcularRankingPorSeccion(
        int $gradoId,
        int $periodoId,
        int $limite = 0
    ): array {
        return $this->ordenMeritoModel->rankingPorSeccion($gradoId, $periodoId, $limite);
    }
}