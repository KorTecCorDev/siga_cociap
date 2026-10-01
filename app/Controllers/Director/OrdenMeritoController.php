<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\CalificacionModel;
use App\Models\DesempateMeritoModel;
use App\Models\DirectorEbrModel;
use App\Models\OrdenMeritoModel;
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
        $periodos = $this->calModel->query("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");

        $this->view('director/orden-merito', [
            'titulo'   => 'Orden de mérito',
            'periodos' => $periodos,
        ]);
    }

    /**
     * GET /director/ranking-seccion
     * Selector de periodo del ranking por SECCION. Mismo universo que index()
     * (activos y cerrados): el staff lo consulta durante el bimestre y despues.
     */
    public function seccionIndex(): void
    {
        $periodos = $this->calModel->query("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");

        $this->view('director/orden-merito', [
            'titulo'   => 'Ranking por sección',
            'rutaBase' => 'director/ranking-seccion',
            'periodos' => $periodos,
        ]);
    }

    /**
     * GET /director/ranking-seccion/{periodo_id}
     * Ranking interno de cada seccion, para admin/RA/directores.
     *
     * ⚠️ A DIFERENCIA DEL DOCENTE, NO APLICA LA COMPUERTA DE PUBLICACION (044):
     * el claustro y las familias ven el merito cuando su nivel se publica, pero
     * el staff necesita revisarlo ANTES —es el insumo para decidir—. Es el mismo
     * criterio que ya sigue `porPeriodo()` con el ranking por grado, y lo que
     * anticipa el comentario de `Docente\OrdenMeritoController::verSelector`
     * ("el director lo ve en vivo desde su modulo").
     *
     * La fuente es `rankingPorSeccion`, que es snapshot-aware: bimestre cerrado
     * -> ranking CONGELADO; activo -> calculo en vivo sobre lo ya bloqueado.
     */
    public function seccionPorPeriodo(string $periodoId): void
    {
        $periodoId = (int) $periodoId;

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);

        if (!$periodo) {
            $this->redirectWithError(
                url('director/ranking-seccion'),
                'Periodo no encontrado.'
            );
        }

        // Snapshot-aware, igual que el imprimible: para un bimestre cerrado
        // enumera desde el snapshot (incluye grados congelados, como la seccion
        // operativa de un retorno de grado).
        $ranking = [];
        foreach ($this->ordenMeritoModel->gradosConRanking($periodoId) as $grado) {
            $gid = (int) $grado['id'];
            $ranking[$gid] = [
                'grado'     => $grado,
                // Sin limite: TODOS los estudiantes de cada seccion, como el
                // reporte imprimible (un top-N escondería a quien se consulta).
                'secciones' => $this->calcularRankingPorSeccion($gid, $periodoId),
            ];
        }

        // Mismo aviso que el imprimible: con empates sin resolver el orden no es
        // oficializable. Reutiliza el helper de esta misma clase.
        $hayPendientes = false;
        foreach ($ranking as $data) {
            foreach ($data['secciones'] as $estudiantes) {
                if ($this->rankingTienePendientes($estudiantes)) {
                    $hayPendientes = true;
                    break 2;
                }
            }
        }

        $this->view('docente/ranking-seccion-periodo', [
            'titulo'        => 'Ranking por sección — ' . $periodo['nombre_display'],
            'periodo'       => $periodo,
            'ranking'       => $ranking,
            'rutaBase'      => 'director/ranking-seccion',
            'rutaMerito'    => 'director/orden-merito',
            'provisional'   => ($periodo['estado'] ?? '') === 'activo',
            'hayPendientes' => $hayPendientes,
        ]);
    }

    /**
     * GET /director/orden-merito/{periodo_id}
     * Muestra el ranking de todos los grados en un periodo.
     */
    public function porPeriodo(string $periodoId): void
    {
        $periodoId = (int) $periodoId;

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);

        if (!$periodo) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        // Obtener grados con estudiantes calificados
        // Snapshot-aware: para un bimestre cerrado enumera desde el snapshot
        // (incluye grados congelados como la sección operativa de un retorno).
        $grados = $this->ordenMeritoModel->gradosConRanking($periodoId);

        // Calcular ranking por cada grado
        $ranking = [];
        foreach ($grados as $grado) {
            $ranking[$grado['id']] = [
                'grado'      => $grado,
                'estudiantes'=> $this->calcularRanking(
                    $grado['id'],
                    $periodoId
                ),
            ];
        }

        $this->view('director/orden-merito-periodo', [
            // Vista de SOLO LECTURA para los directores: sin controles.
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
            'titulo'          => 'Orden de mérito — ' . $periodo['nombre_display'],
            'periodo'         => $periodo,
            'ranking'         => $ranking,
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
                $al['puesto_grado']     = $r['puesto'] ?? null;
            }
            unset($al);

            // Cuadro comparativo por competencia (numeral + literal) del grupo.
            $res['comparativo'] = $this->desempateModel->getComparativoCompetencias(
                array_map(static fn($a) => (int) $a['matricula_id'], $res['alumnos']),
                $periodoId
            );
        }
        unset($res);

        return ['periodo' => $periodo, 'resoluciones' => $resoluciones];
    }

    /**
     * GET /director/orden-merito/{periodo_id}/imprimir
     * Reporte imprimible: ranking general + primeros puestos por sección.
     */
    public function imprimir(string $periodoId): void
    {
        $periodoId = (int) $periodoId;

        $periodo = $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);

        if (!$periodo) {
            $this->redirectWithError(
                url('director/orden-merito'),
                'Periodo no encontrado.'
            );
        }

        // Snapshot-aware: para un bimestre cerrado enumera desde el snapshot
        // (incluye grados congelados como la sección operativa de un retorno).
        $grados = $this->ordenMeritoModel->gradosConRanking($periodoId);

        $ranking = [];
        foreach ($grados as $grado) {
            $ranking[$grado['id']] = [
                'grado'       => $grado,
                'conteos'     => $this->getConteosGrado($grado['id'], $periodoId),
                'general'     => $this->calcularRanking($grado['id'], $periodoId),
                'por_seccion' => $this->calcularRankingPorSeccion($grado['id'], $periodoId),
                'tutores'     => $this->getTutoresPorGrado($grado['id'], $periodoId),
            ];
        }

        // Si hay algún empate sin resolver, el reporte no es oficializable.
        $hayPendientes = false;
        foreach ($ranking as $data) {
            if ($this->rankingTienePendientes($data['general'])) {
                $hayPendientes = true;
                break;
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
            'institucion'   => config('institucion'),
            'directorEbr'   => $this->getDirectorEbr($periodo),
            'hayPendientes' => $hayPendientes,
        ]);
    }

    /**
     * GET /director/orden-merito/{periodo_id}/desempate/{grado_id}
     * Formulario para resolver los empates irreducibles de un grado.
     *
     * Resolver un empate es ESCRITURA: los directores NO entran (403). Habia
     * aqui un segundo `requireRole` que SI los incluia, codigo MUERTO por ser
     * mas permisivo que el de arriba; se retiro el 02/09/2026 porque al leerlo
     * hacia creer lo contrario de lo que el metodo hace.
     */
    public function desempate(string $periodoId, string $gradoId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);

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

        // Detectar los grupos pendientes a partir del ranking general del grado.
        $estudiantes = $this->calcularRanking($gradoId, $periodoId);
        $grupos = [];
        foreach ($estudiantes as $est) {
            if (!empty($est['empate_pendiente'])) {
                $grupos[$est['empate_clave']][] = $est;
            }
        }

        $this->view('director/orden-merito-desempate', [
            'titulo'  => 'Resolver empate — ' . $grado['nombre_display'],
            'periodo' => $periodo,
            'grado'   => $grado,
            'grupos'  => $grupos,
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

        $motivo = trim((string) $this->input('motivo', ''));
        $orden  = $this->input('orden', []); // [matricula_id => posicion]

        if ($motivo === '' || !is_array($orden) || count($orden) < 2) {
            $this->redirectWithError($destino, 'Falta el motivo o el orden del desempate.');
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
                $motivo
            );
        } catch (\Exception $e) {
            $this->redirectWithError($destino, 'No se pudo guardar la resolución del empate.');
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
              AND m.estado = 'aprobada'
              -- Retorno de grado: el estudiante compite en su grado OPERATIVO.
              -- Se excluye la matrícula oficial (la operativa, en grado inferior,
              -- entra como 'aprobada' y rankea con su grado real de asistencia).
              AND m.id NOT IN (
                  SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo'
              )
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