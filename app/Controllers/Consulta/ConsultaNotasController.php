<?php

namespace App\Controllers\Consulta;

use App\Controllers\BaseController;
use App\Models\AsistenciaModel;
use App\Models\CalificacionModel;
use App\Models\CriterioModel;
use App\Models\OmisionCriterioModel;
use App\Models\ExoneracionModel;
use App\Models\TransversalModel;
use App\Models\ConductaModel;
use App\Models\ControlOperativoModel;
use Core\View;

/**
 * ConsultaNotasController
 * Consulta de calificaciones en SOLO LECTURA (capa de supervision).
 *
 * Eje de navegacion: PERIODO -> SECCION -> AREA/CARGA -> grilla criterio-a-
 * criterio. Muestra UNICAMENTE lo OFICIAL (competencias con bloqueo), igual
 * criterio que la boleta. NO edita: para corregir se usa /rectificaciones
 * (que ya audita). Reutiliza la capa de datos de CalificacionModel
 * (getCompetenciasPorPeriodo para navegar, getResumenCompetencia para el
 * detalle) y no introduce metodos de modelo nuevos.
 *
 * Roles: admin, registro_academico, director_general, director_ebr. El filtro
 * por nivel del director_ebr NO se aplica aqui (mismo comportamiento que
 * /director/bloqueos, que estos roles ya usan sin restriccion de nivel).
 */
class ConsultaNotasController extends BaseController
{
    /**
     * Tope de criterios por debajo del cual un resultado FILTRADO se despliega
     * entero, para leerlo de corrido sin abrir nada.
     *
     * Sale de la densidad real del dato: una SECCION completa tiene ~119
     * criterios y un DOCENTE ~135, que son las dos unidades con las que trabaja
     * el director; 200 las cubre a las dos. Un NIVEL entero (1325) se queda
     * fuera a proposito: ahi se abren las secciones y las cargas siguen
     * plegadas, o la pagina seria inabarcable.
     */
    private const CRITERIOS_ABRIR_TODO = 200;

    private CalificacionModel    $calModel;
    private OmisionCriterioModel $omisionModel;
    private ExoneracionModel     $exoModel;
    private TransversalModel     $transModel;
    private ConductaModel        $conductaModel;
    private AsistenciaModel      $asistenciaModel;
    private CriterioModel        $criterioModel;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico', ...ROLES_DIRECCION]);
        $this->calModel      = new CalificacionModel();
        $this->criterioModel = new CriterioModel();
        $this->omisionModel  = new OmisionCriterioModel();
        $this->exoModel      = new ExoneracionModel();
        $this->transModel    = new TransversalModel();
        $this->conductaModel = new ConductaModel();
        $this->asistenciaModel = new AsistenciaModel();
    }

    /**
     * Roster canonico de una seccion (matricula_id + nombre), con las exclusiones
     * de retorno de grado ya aplicadas. Se reusa `ConductaModel::getEstudiantesParaTutor`
     * A PROPOSITO en vez de escribir otro SELECT: es el mismo roster que ven el
     * tutor y la grilla del docente, y duplicar ese filtro a mano es exactamente
     * como nacieron los bugs de asistencia del 04/08/2026. Los campos de conducta
     * que trae de propina los usa F3; F2 solo necesita id y nombre.
     */
    private function rosterSeccion(int $seccionId, int $periodoId, int $nivelId): array
    {
        return $this->conductaModel->getEstudiantesParaTutor(
            $seccionId,
            $periodoId,
            $this->conductaModel->totalCriterios($nivelId)
        );
    }

    /** Periodos disponibles para el selector (activos + cerrados). */
    private function listarPeriodos(): array
    {
        return $this->calModel->query("
            SELECT p.id, p.numero, p.nombre_display, p.estado, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");
    }

    /**
     * Salto de bimestre desde el selector comun de la barra (`_nav.php`).
     *
     * En las pestañas Docentes y Criterios el periodo viaja en la RUTA, pero el
     * <select> solo puede mandarlo como query. Si apunta a OTRO periodo se redirige
     * a la MISMA pestaña con el periodo nuevo: cambiar de bimestre nunca cambia de
     * eje. La regla vive aqui y solo aqui — son las 2 pestañas que la necesitan.
     *
     * ⚠️ SOLO cuando el bimestre CAMBIA (`!== $periodoId`): en un envio normal la
     * query trae el periodo vigente y no se debe redirigir.
     */
    private function saltarDePeriodo(int $periodoId, string $ruta): void
    {
        $destino = (int) ($this->query('periodo_id') ?? 0);
        if ($destino > 0 && $destino !== $periodoId) {
            redirect(url('consulta-notas/' . $destino . '/' . $ruta));   // redirect() es `never`
        }
    }

    /** Nombre completo del docente desde una fila de getCompetenciasPorPeriodo. */
    private function nombreDocente(array $c): string
    {
        $apellidos = trim(($c['docente_apellido'] ?? '') . ' ' . ($c['docente_materno'] ?? ''));
        $nombres   = trim($c['docente_nombres'] ?? '');

        if ($apellidos === '' && $nombres === '') {
            return '';
        }
        return $nombres !== '' ? trim($apellidos . ', ' . $nombres) : $apellidos;
    }

    private function getPeriodo(int $periodoId): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);
    }

    /**
     * Competencias OFICIALES (con bloqueo) del periodo. Filtra en PHP sobre
     * getCompetenciasPorPeriodo para no duplicar la query de navegacion.
     */
    private function competenciasOficiales(int $periodoId): array
    {
        return array_values(array_filter(
            $this->calModel->getCompetenciasPorPeriodo($periodoId),
            fn($c) => $c['bloqueo_id'] !== null
        ));
    }

    /**
     * Competencias TRANSVERSALES (TIC/GAMA) con CONTENIDO REAL de las cargas
     * indicadas, indexadas por carga: [carga_id => [ {competencia_id, ...}, ... ]].
     *
     * ⚠️ POR QUE NO SALEN POR LA VIA NORMAL: `getCompetenciasPorPeriodo` une
     * competencia<->carga por el AREA DE LA CARGA, y las transversales cuelgan de
     * un area propia (`tipo='transversal'`), asi que ese JOIN no puede alcanzarlas
     * — el vinculo transversal<->carga no existe en el esquema, se resuelve por
     * NIVEL. Es el mismo limite que las deja fuera del panel de bloqueos.
     *
     * 🔴 EL BLOQUEO NO ES SENAL DE CONTENIDO, y por eso hace falta el EXISTS:
     * el cierre forzado propaga bloqueos en cascada, asi que hay 820 bloqueos
     * transversales sobre 410 cargas en CADA bimestre, pero en B1 solo 23 cargas
     * tienen notas. Copiar el criterio del resto de la pantalla ("mostrar lo que
     * tenga bloqueo") pintaria 387 bloques VACIOS en B1. La condicion de
     * contenido no es opcional.
     */
    private function transversalesConContenido(array $cargaIds, int $periodoId): array
    {
        $ids = array_values(array_unique(array_map('intval', $cargaIds)));
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $filas = $this->calModel->query("
            SELECT bc.carga_id,
                   bc.bloqueado_en,
                   comp.id              AS competencia_id,
                   comp.nombre_completo,
                   comp.nombre_corto,
                   comp.codigo_minedu,
                   comp.orden
            FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            INNER JOIN areas a           ON a.id    = comp.area_id AND a.tipo = 'transversal'
            WHERE bc.periodo_id = ?
              AND bc.carga_id IN ({$ph})
              AND EXISTS (
                  SELECT 1 FROM calificaciones cal
                  WHERE cal.carga_id       = bc.carga_id
                    AND cal.competencia_id = comp.id
                    AND cal.periodo_id     = bc.periodo_id
              )
            ORDER BY bc.carga_id, comp.orden
        ", array_merge([$periodoId], $ids));

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['carga_id']][] = $f;
        }
        return $out;
    }

    /** GET /consulta-notas — selector de periodo + secciones con notas oficiales. */
    public function index(): void
    {
        $periodos  = $this->listarPeriodos();
        $periodoId = (int) ($this->query('periodo_id') ?? 0);
        $periodo   = null;
        $secciones = [];

        if ($periodoId) {
            $periodo = $this->getPeriodo($periodoId);
            if ($periodo) {
                $bySec = [];
                foreach ($this->competenciasOficiales($periodoId) as $c) {
                    $sid = (int) $c['seccion_id'];
                    if (!isset($bySec[$sid])) {
                        $bySec[$sid] = [
                            'seccion_id'     => $sid,
                            'seccion_nombre' => $c['seccion_nombre'],
                            'grado_nombre'   => $c['grado_nombre'],
                            'grado_numero'   => (int) $c['grado_numero'],
                            'nivel_nombre'   => $c['nivel_nombre'],
                            'nivel_id'       => (int) $c['nivel_id'],
                            'cargas'         => [],
                            'competencias'   => 0,
                        ];
                    }
                    $bySec[$sid]['cargas'][(int) $c['carga_id']] = true;
                    $bySec[$sid]['competencias']++;
                }
                foreach ($bySec as &$s) {
                    $s['areas'] = count($s['cargas']);
                    unset($s['cargas']);
                }
                unset($s);

                $secciones = array_values($bySec);
                usort($secciones, fn($a, $b) =>
                    [$a['nivel_id'], $a['grado_numero'], $a['seccion_nombre']]
                    <=> [$b['nivel_id'], $b['grado_numero'], $b['seccion_nombre']]
                );
            }
        }

        $this->view('consulta-notas/index', [
            'titulo'    => 'Consulta de calificaciones',
            'periodos'  => $periodos,
            'periodoId' => $periodoId,
            'periodo'   => $periodo,
            'secciones' => $secciones,
        ]);
    }

    /** GET /consulta-notas/{periodo_id}/seccion/{seccion_id} — areas/cargas de la seccion. */
    public function seccion(string $periodoId, string $seccionId): void
    {
        $periodoId = (int) $periodoId;
        $seccionId = (int) $seccionId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['seccion_id'] === $seccionId
        ));

        $seccion = null;
        $cargas  = [];
        if (!empty($filas)) {
            $primera = $filas[0];
            $seccion = [
                'seccion_id'     => $seccionId,
                'seccion_nombre' => $primera['seccion_nombre'],
                'grado_nombre'   => $primera['grado_nombre'],
                'nivel_nombre'   => $primera['nivel_nombre'],
                'nivel_id'       => (int) $primera['nivel_id'],
            ];

            $byCarga = [];
            foreach ($filas as $c) {
                $cid = (int) $c['carga_id'];
                if (!isset($byCarga[$cid])) {
                    $byCarga[$cid] = [
                        'carga_id'       => $cid,
                        'area_nombre'    => $c['area_nombre'],
                        'subarea_nombre' => $c['subarea_nombre'],
                        'docente'        => $this->nombreDocente($c),
                        'competencias'   => 0,
                        'transversales'  => 0,
                    ];
                }
                $byCarga[$cid]['competencias']++;
            }

            // El contador de la tarjeta debe incluir las transversales, o mentiria
            // respecto a los bloques que la vista de carga va a pintar.
            $transv = $this->transversalesConContenido(array_keys($byCarga), $periodoId);
            foreach ($transv as $cid => $lista) {
                if (isset($byCarga[$cid])) {
                    $byCarga[$cid]['transversales'] = count($lista);
                    $byCarga[$cid]['competencias'] += count($lista);
                }
            }

            $cargas = array_values($byCarga);
        }

        // Las dos entradas de nivel SECCION (no de carga). Solo se ofrecen si el
        // registro esta OFICIALMENTE terminado (decision D3): cierre transversal
        // vigente / conducta con sus DOS etapas y sin anular. Lo que esta a medias
        // no aparece — es la misma promesa que ya hace la pantalla con las notas.
        $tieneTransversales = false;
        $tieneConducta      = false;
        if ($seccion) {
            $tieneTransversales = $this->transModel->getCierreVigente($seccionId, $periodoId) !== null;
            $cierreC            = $this->conductaModel->getCierreDetalle($seccionId, $periodoId);
            $tieneConducta      = $cierreC !== null
                && !empty($cierreC['ra_bloqueado_en'])
                && !empty($cierreC['tutor_cerrado_en']);
        }

        $this->view('consulta-notas/seccion', [
            'titulo'             => 'Consulta de calificaciones',
            'periodo'            => $periodo,
            'seccion'            => $seccion,
            'cargas'             => $cargas,
            'tieneTransversales' => $tieneTransversales,
            'tieneConducta'      => $tieneConducta,
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/transversales
     * Agregado transversal de la seccion: el promedio por competencia que
     * EFECTIVAMENTE llega a la boleta, con la conclusion del tutor.
     *
     * Es la otra cara de las transversales crudas que se ven dentro de cada carga:
     * aquellas son lo que registro cada docente; esto es lo que el tutor congelo
     * al cerrar. Sin cierre vigente no hay agregado que mostrar (`getTransversalesAgregadas`
     * aplica el mismo corte en la boleta), asi que la ruta responde 404 — no basta
     * con ocultar el enlace: la URL queda en marcadores.
     */
    public function transversales(string $periodoId, string $seccionId): void
    {
        $periodoId = (int) $periodoId;
        $seccionId = (int) $seccionId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        $cierre = $this->transModel->getCierreVigente($seccionId, $periodoId);
        if (!$cierre) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['seccion_id'] === $seccionId
        ));
        if (empty($filas)) {
            $this->notFound();
        }
        $primera = $filas[0];
        $nivelId = (int) $primera['nivel_id'];

        $seccion = [
            'seccion_id'     => $seccionId,
            'seccion_nombre' => $primera['seccion_nombre'],
            'grado_nombre'   => $primera['grado_nombre'],
            'nivel_nombre'   => $primera['nivel_nombre'],
            'nivel_codigo'   => $primera['nivel_codigo'],
        ];

        // ⚠️ Los promedios vienen indexados POR MATRICULA y con competencia_id
        // como clave interna: hay que cruzarlos con el roster, nunca asumir orden.
        $this->view('consulta-notas/transversales', [
            'titulo'       => 'Transversales — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'periodo'      => $periodo,
            'seccion'      => $seccion,
            'cierre'       => $cierre,
            'competencias' => $this->transModel->getCompetencias($nivelId),
            'alumnos'      => $this->rosterSeccion($seccionId, $periodoId, $nivelId),
            'promedios'    => $this->transModel->getPromediosSeccion($seccionId, $periodoId),
            'conclusiones' => $this->transModel->getConclusionesSeccion($seccionId, $periodoId),
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/conducta
     * Conducta de la seccion en SOLO LECTURA. Entra aqui —y no ampliando los
     * roles de /admin/conducta— porque aquella pantalla tiene botones de
     * escritura y no es de solo lectura por diseno (decision D2).
     *
     * Cierra un hueco real: director_general y director_ebr no tenian NINGUNA
     * forma de ver la conducta de una seccion.
     *
     * ⚠️ B1 y B2 NO comparten modelo: B1 es legado (literal directo, 0 respuestas)
     * y B2+ deriva la nota de las respuestas Si/No. `getEstudiantesParaTutor`
     * resuelve las dos y marca cada fila con `es_legado`; la vista ramifica.
     */
    public function conducta(string $periodoId, string $seccionId): void
    {
        $periodoId = (int) $periodoId;
        $seccionId = (int) $seccionId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        // Gate D3: las DOS etapas cumplidas y sin anular.
        $cierre = $this->conductaModel->getCierreDetalle($seccionId, $periodoId);
        if (!$cierre || empty($cierre['ra_bloqueado_en']) || empty($cierre['tutor_cerrado_en'])) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['seccion_id'] === $seccionId
        ));
        if (empty($filas)) {
            $this->notFound();
        }
        $primera = $filas[0];
        $nivelId = (int) $primera['nivel_id'];

        $alumnos  = $this->rosterSeccion($seccionId, $periodoId, $nivelId);
        $esLegado = !empty($alumnos) && !empty($alumnos[0]['es_legado']);

        $this->view('consulta-notas/conducta', [
            'titulo'    => 'Conducta — ' . $primera['grado_nombre'] . ' ' . $primera['seccion_nombre'],
            'periodo'   => $periodo,
            'seccion'   => [
                'seccion_id'     => $seccionId,
                'seccion_nombre' => $primera['seccion_nombre'],
                'grado_nombre'   => $primera['grado_nombre'],
                'nivel_nombre'   => $primera['nivel_nombre'],
            ],
            'cierre'    => $cierre,
            'alumnos'   => $alumnos,
            'criterios' => $esLegado ? [] : $this->conductaModel->getCriterios($nivelId),
            'esLegado'  => $esLegado,
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/conducta/criterios
     * La grilla Si/No por alumno, aprobada y bloqueada, en SOLO LECTURA.
     *
     * 🔴 REUSA `docente/conducta-criterios.php` — la vista que ya existia para el
     * tutor. Es exactamente el mismo dato en solo lectura, y su propio docblock
     * ya la declaraba "espejo de admin/conducta/seccion.php en su estado
     * bloqueado": escribir otra habria sido la TERCERA copia de la misma grilla.
     * Lo unico que cambia es el chrome (volver + clase del titulo), que viaja
     * como variables; la vista no sabe quien la mira.
     *
     * Mismo gate que la pantalla de conducta: las DOS etapas cumplidas y sin
     * anular. Sin eso no hay registro aprobado que ensenar, y la ruta responde
     * 404 — no basta con esconder el enlace: la URL queda en marcadores.
     */
    public function conductaCriterios(string $periodoId, string $seccionId): void
    {
        $periodoId = (int) $periodoId;
        $seccionId = (int) $seccionId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        $cierre = $this->conductaModel->getCierreDetalle($seccionId, $periodoId);
        if (!$cierre || empty($cierre['ra_bloqueado_en']) || empty($cierre['tutor_cerrado_en'])) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['seccion_id'] === $seccionId
        ));
        if (empty($filas)) {
            $this->notFound();
        }
        $primera = $filas[0];
        $nivelId = (int) $primera['nivel_id'];

        $estudiantes = $this->conductaModel->getEstudiantesParaRegistro($seccionId, $periodoId);

        // B1 legado (literal directo): no existe matriz de respuestas que mostrar.
        $hayRespuestas = false;
        foreach ($estudiantes as $e) {
            if (!empty($e['respuestas'])) {
                $hayRespuestas = true;
                break;
            }
        }

        $this->view('docente/conducta-criterios', [
            'titulo'  => 'Criterios de conducta — ' . $primera['grado_nombre'] . ' ' . $primera['seccion_nombre'],
            'seccion' => [
                'id'           => $seccionId,
                'nombre'       => $primera['seccion_nombre'],
                'grado_nombre' => $primera['grado_nombre'],
                'nivel_nombre' => $primera['nivel_nombre'],
                'nivel_id'     => $nivelId,
            ],
            'periodo'       => $periodo,
            'cierre'        => $cierre,
            'criterios'     => $this->conductaModel->getCriterios($nivelId),
            'estudiantes'   => $estudiantes,
            'hayRespuestas' => $hayRespuestas,
            // El chrome de ESTA entrada: se vuelve a la conducta de la seccion, y
            // el titulo va sin el wayfinding del panel del docente.
            'volverUrl'     => url('consulta-notas/' . $periodoId . '/seccion/' . $seccionId . '/conducta'),
            'tituloClase'   => 'page-title',
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/docentes
     *
     * EJE POR DOCENTE (24/08/2026). La pantalla solo navegaba
     * periodo -> seccion -> carga; para responder "que registro este docente"
     * habia que recorrer las 23 secciones a mano.
     *
     * Sin metodos de modelo nuevos: se agrupa la misma lista de competencias
     * oficiales que ya alimenta el resto del controlador.
     */
    public function docentes(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }
        $this->saltarDePeriodo($periodoId, 'docentes');

        $porDocente = [];
        foreach ($this->competenciasOficiales($periodoId) as $c) {
            $did = (int) $c['docente_id'];
            if (!isset($porDocente[$did])) {
                $porDocente[$did] = [
                    'docente_id'   => $did,
                    'nombre'       => $this->nombreDocente($c),
                    'cargas'       => [],
                    'competencias' => 0,
                    'secciones'    => [],
                ];
            }
            $porDocente[$did]['cargas'][(int) $c['carga_id']] = true;
            $porDocente[$did]['secciones'][(int) $c['seccion_id']] = true;
            $porDocente[$did]['competencias']++;
        }

        foreach ($porDocente as &$d) {
            $d['n_cargas']    = count($d['cargas']);
            $d['n_secciones'] = count($d['secciones']);
            unset($d['cargas'], $d['secciones']);
        }
        unset($d);

        // Alfabetico por el nombre ya compuesto (apellidos primero).
        usort($porDocente, fn($a, $b) => strcoll($a['nombre'], $b['nombre']));

        $this->view('consulta-notas/docentes', [
            'titulo'   => 'Docentes — ' . $periodo['nombre_display'],
            'periodo'  => $periodo,
            'periodos' => $this->listarPeriodos(),   // selector comun de la barra
            'docentes' => array_values($porDocente),
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/docente/{docente_id}
     * Cargas oficiales de un docente en el periodo. Enlaza a la MISMA vista de
     * carga del eje por seccion: es el mismo destino por otro camino.
     */
    public function docente(string $periodoId, string $docenteId): void
    {
        $periodoId = (int) $periodoId;
        $docenteId = (int) $docenteId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['docente_id'] === $docenteId
        ));
        if (empty($filas)) {
            $this->notFound();
        }

        $cargas = [];
        foreach ($filas as $c) {
            $cid = (int) $c['carga_id'];
            if (!isset($cargas[$cid])) {
                $cargas[$cid] = [
                    'carga_id'       => $cid,
                    'area_nombre'    => $c['area_nombre'],
                    'subarea_nombre' => $c['subarea_nombre'] ?? null,
                    'grado_nombre'   => $c['grado_nombre'],
                    'seccion_nombre' => $c['seccion_nombre'],
                    'nivel_nombre'   => $c['nivel_nombre'],
                    'grado_numero'   => (int) $c['grado_numero'],
                    'competencias'   => 0,
                ];
            }
            $cargas[$cid]['competencias']++;
        }

        $cargas = array_values($cargas);
        usort($cargas, fn($a, $b) => [$a['nivel_nombre'], $a['grado_numero'], $a['seccion_nombre'], $a['area_nombre']]
                                 <=> [$b['nivel_nombre'], $b['grado_numero'], $b['seccion_nombre'], $b['area_nombre']]);

        $this->view('consulta-notas/docente', [
            'titulo'  => 'Cargas de ' . $this->nombreDocente($filas[0]),
            'periodo' => $periodo,
            'docente' => ['id' => $docenteId, 'nombre' => $this->nombreDocente($filas[0])],
            'cargas'  => $cargas,
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/asistencia
     *
     * Asistencia de la sección en SOLO LECTURA (24/08/2026). Cierra el cuarto
     * registro del bimestre: hasta hoy la asistencia solo existía en
     * `/admin/asistencia`, que es la pantalla de ESCRITURA de Registro
     * Académico — dirección no tenía ninguna forma de consultarla.
     *
     * ⚠️ A DIFERENCIA de transversales y conducta, esta vista NO exige cierre:
     * se muestra EN VIVO (decisión del usuario, 24/08/2026). El criterio del
     * resto de la pantalla es "solo el dato aprobado y bloqueado", pero una
     * inasistencia no es una calificación sujeta a aprobación docente — ya
     * ocurrió. El estado del cierre se muestra como dato, no como candado.
     *
     * El roster sale de `AsistenciaModel::getEstudiantesConIncidencias`, que es
     * el MISMO de la grilla de notas (`getAlumnosSeccion`): sin filtrar por
     * `estado` y con las exclusiones de retorno de grado. No se reescribe ese
     * filtro a mano — así nacieron los bugs de asistencia del 04/08.
     */
    public function asistencia(string $periodoId, string $seccionId): void
    {
        $periodoId = (int) $periodoId;
        $seccionId = (int) $seccionId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        // La sección debe pertenecer al periodo (mismo anclaje que conducta).
        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['seccion_id'] === $seccionId
        ));
        if (empty($filas)) {
            $this->notFound();
        }
        $primera = $filas[0];

        $alumnos = $this->asistenciaModel->getEstudiantesConIncidencias($seccionId, $periodoId);

        // Totales de la seccion. PUNTO UNICO en el modelo: los calculaba a mano
        // aqui, y la vista de Registro Academico no los tenia. Ahora las dos
        // pantallas suman con la misma funcion sobre el mismo roster.
        $totales = AsistenciaModel::totalesIncidencias($alumnos);

        $this->view('consulta-notas/asistencia', [
            'titulo'  => 'Asistencia — ' . $primera['grado_nombre'] . ' ' . $primera['seccion_nombre'],
            'periodo' => $periodo,
            'seccion' => [
                'seccion_id'     => $seccionId,
                'seccion_nombre' => $primera['seccion_nombre'],
                'grado_nombre'   => $primera['grado_nombre'],
                'nivel_nombre'   => $primera['nivel_nombre'],
            ],
            'alumnos' => $alumnos,
            'totales' => $totales,
            'cierre'  => $this->asistenciaModel->getCierreDetalle($seccionId, $periodoId),
        ]);
    }

    /** GET /consulta-notas/{periodo_id}/carga/{carga_id} — grillas read-only de la carga. */
    public function carga(string $periodoId, string $cargaId): void
    {
        $periodoId = (int) $periodoId;
        $cargaId   = (int) $cargaId;

        $periodo = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        $filas = array_values(array_filter(
            $this->competenciasOficiales($periodoId),
            fn($c) => (int) $c['carga_id'] === $cargaId
        ));
        if (empty($filas)) {
            $this->notFound();
        }

        $primera = $filas[0];
        $carga = [
            'id'             => $cargaId,
            'seccion_id'     => (int) $primera['seccion_id'],
            'seccion_nombre' => $primera['seccion_nombre'],
            'grado_nombre'   => $primera['grado_nombre'],
            'nivel_nombre'   => $primera['nivel_nombre'],
            'nivel_codigo'   => $primera['nivel_codigo'],
            'area_nombre'    => $primera['area_nombre'],
            'subarea_nombre' => $primera['subarea_nombre'],
            'docente'        => $this->nombreDocente($primera),
        ];

        $exonerados   = $this->exoModel->getActivasParaCarga($cargaId, (int) $periodo['anio_id']);
        $competencias = [];

        foreach ($filas as $c) {
            $competenciaId = (int) $c['competencia_id'];

            $info = $this->calModel->queryOne("
                SELECT c.*, (a.tipo = 'transversal') AS es_transversal
                FROM competencias c
                LEFT JOIN areas a ON a.id = c.area_id
                WHERE c.id = ?
            ", [$competenciaId]);

            $resumen = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, $periodoId);

            // Enriquecer cada alumno con sus omisiones por criterio (igual que el
            // resumen del docente: el badge "—" del casillero omitido).
            $omisionesPorCriterio = [];
            foreach ($resumen['criterios'] as $cr) {
                $omisionesPorCriterio[(int) $cr['id']] =
                    $this->omisionModel->getPorCriterio((int) $cr['id']);
            }
            foreach ($resumen['alumnos'] as &$al) {
                $al['omisiones_criterios'] = [];
                $mid = (int) $al['matricula_id'];
                foreach ($omisionesPorCriterio as $critId => $porMat) {
                    if (isset($porMat[$mid])) {
                        $al['omisiones_criterios'][$critId] = $porMat[$mid];
                    }
                }
            }
            unset($al);

            $competencias[] = [
                'competencia'     => $info,
                'criterios'       => $resumen['criterios'],
                'alumnos'         => $resumen['alumnos'],
                'bloqueado_en'    => $c['bloqueado_en'],
                'es_transversal'  => false,
            ];
        }

        // Transversales (TIC/GAMA) de esta carga, al final y solo si tienen
        // contenido. Se pintan con el MISMO parcial `_tabla.php`: getResumenCompetencia
        // funciona igual sobre una competencia transversal (verificado con sonda:
        // devuelve las mismas claves), asi que basta con anadirlas al array.
        foreach ($this->transversalesConContenido([$cargaId], $periodoId)[$cargaId] ?? [] as $t) {
            $competenciaId = (int) $t['competencia_id'];
            $resumen = $this->calModel->getResumenCompetencia($cargaId, $competenciaId, $periodoId);

            $competencias[] = [
                'competencia'     => [
                    'id'              => $competenciaId,
                    'nombre_completo' => $t['nombre_completo'],
                    'nombre_corto'    => $t['nombre_corto'],
                    'codigo_minedu'   => $t['codigo_minedu'],
                    'es_transversal'  => 1,
                ],
                'criterios'       => $resumen['criterios'],
                'alumnos'         => $resumen['alumnos'],
                'bloqueado_en'    => $t['bloqueado_en'],
                'es_transversal'  => true,
            ];
        }

        $this->view('consulta-notas/carga', [
            'titulo'       => 'Consulta — ' . $carga['grado_nombre'] . ' ' . $carga['seccion_nombre'],
            'periodo'      => $periodo,
            'carga'        => $carga,
            'competencias' => $competencias,
            // Sección ÚNICA al final (21/09/2026), con las competencias que no
            // tienen tabla propia en esta carga.
            'extraordinariasCarga' => (new \App\Models\RectificacionModel())
                ->getExtraordinariasDeCargas([$cargaId], $periodoId),
            'exonerados'   => $exonerados,
        ]);
    }

    /**
     * Arbol de criterios del periodo:
     *   SECCION -> CARGA (area + docente) -> COMPETENCIA -> criterios.
     *
     * Mismo universo que el resto de la pantalla: SOLO competencias con bloqueo
     * (`competenciasOficiales`), mas las TRANSVERSALES con contenido real. Sin
     * ese segundo bloque el explorador se dejaria fuera 743 de los 2731
     * criterios de un bimestre (27 %) sin decirlo: `getCompetenciasPorPeriodo`
     * une competencia<->carga por el AREA de la carga y las transversales
     * cuelgan de un area propia, asi que ese JOIN no puede alcanzarlas.
     *
     * ⚠️ NO hay filtro de TEXTO, y es deliberado (24/08/2026): el director no
     * conoce los criterios —son de los docentes—, asi que buscarlos por su
     * redaccion no es una forma de navegar que el pueda usar. La pantalla se
     * recorre solo por lo que SI conoce: nivel, grado, seccion y docente.
     *
     * @param array $filtros nivel (id) | grado (numero) | seccion (id) |
     *                       docente (id); 0 = sin filtrar.
     * @return array{secciones: array, total: int, con_descripcion: int,
     *               niveles: array, grados: array, seccionesCat: array, docentes: array}
     */
    private function arbolCriterios(int $periodoId, array $filtros = []): array
    {
        $filas = $this->competenciasOficiales($periodoId);

        // Catalogos de los selectores: salen de las MISMAS filas, sin una
        // consulta extra. Se calculan ANTES de filtrar, o el propio filtro
        // vaciaria su selector y no habria como volver atras.
        $niveles = $grados = $seccionesCat = $docentes = [];
        foreach ($filas as $c) {
            $niveles[(int) $c['nivel_id']] = $c['nivel_nombre'];

            // 🔴 EL GRADO SE IDENTIFICA POR SU ID, NUNCA POR `numero`: el numero
            // COLISIONA entre niveles —1° de primaria y 1° de secundaria son los
            // dos `1`—, asi que indexar por numero fundia los 11 grados reales en
            // 6 opciones y `?grado=1` devolvia a la vez 1°A/B de primaria y
            // 1°A/B/C de secundaria. La etiqueta lleva el nivel porque el nombre
            // por si solo tampoco distingue con el selector de nivel en "Todos".
            $grados[(int) $c['grado_id']] = [
                'etiqueta' => $c['grado_nombre'] . ' ' . $c['nivel_nombre'],
                'nivel_id' => (int) $c['nivel_id'],
                'orden'    => [(int) $c['nivel_id'], (int) $c['grado_numero']],
            ];

            // ⚠️ UN DOCENTE NO PERTENECE A UN NIVEL: 2 de los 35 con carga activa
            // dictan en primaria Y en secundaria. Por eso se guarda el CONJUNTO de
            // secciones donde dicta y la cascada resuelve por PERTENENCIA; con un
            // atributo unico ("el nivel del docente") esos 2 desaparecerian del
            // selector en uno de los dos niveles y sin ningun sintoma.
            $did = (int) $c['docente_id'];
            if (!isset($docentes[$did])) {
                $docentes[$did] = ['nombre' => $this->nombreDocente($c), 'secciones' => []];
            }
            $docentes[$did]['secciones'][(int) $c['seccion_id']] = true;

            $seccionesCat[(int) $c['seccion_id']] = [
                'etiqueta' => $c['grado_nombre'] . ' ' . $c['seccion_nombre'] . ' — ' . $c['nivel_nombre'],
                'nivel_id' => (int) $c['nivel_id'],
                'grado_id' => (int) $c['grado_id'],
                'orden'    => [(int) $c['nivel_id'], (int) $c['grado_numero'], $c['seccion_nombre']],
            ];
        }
        ksort($niveles);
        // Los grados se ordenan por [nivel, numero], no por su id: el id sigue el
        // orden de carga de la tabla y no tiene por que ser el pedagogico.
        uasort($grados, fn($a, $b) => $a['orden'] <=> $b['orden']);
        uasort($seccionesCat, fn($a, $b) => $a['orden'] <=> $b['orden']);
        // Alfabetico por el nombre ya compuesto (apellidos primero), igual que
        // el eje por docente. `orden_alfabetico()` NO sirve aqui: genera SQL.
        uasort($docentes, fn($a, $b) => strcoll($a['nombre'], $b['nombre']));
        // Las secciones de cada docente viajan a la vista como LISTA (se juntaron
        // como claves para deduplicar sin coste).
        foreach ($docentes as $id => $d) {
            $docentes[$id]['secciones'] = array_keys($d['secciones']);
        }

        // Filtros estructurados: reducen el universo ANTES de armar el arbol.
        $fNivel   = (int) ($filtros['nivel']   ?? 0);
        $fGrado   = (int) ($filtros['grado']   ?? 0);
        $fSeccion = (int) ($filtros['seccion'] ?? 0);
        $fDocente = (int) ($filtros['docente'] ?? 0);

        // 🔴 UN FILTRO QUE NO EXISTE EN EL CATALOGO DE ESTE PERIODO SE DESCARTA.
        // Los catalogos se recalculan por bimestre: un valor traido de otro
        // —un enlace viejo, un marcador guardado, la URL editada a mano— no
        // llega a pintarse como <option>, asi que el selector muestra "Todas"
        // MIENTRAS la consulta sigue filtrando por el valor invisible: pantalla
        // vacia y ningun filtro a la vista que la explique. Se cae aqui, en el
        // punto unico, y los filtros ya saneados se devuelven al controlador
        // para que la vista marque exactamente lo que se esta filtrando.
        //
        // ⚠️ Esto valida EXISTENCIA, no coherencia entre filtros: que el grado
        // pertenezca al nivel elegido lo resuelve la cascada del cliente, y
        // duplicar esa regla aqui es el patron de fallo que arrastra el repo.
        if ($fNivel   && !isset($niveles[$fNivel]))         { $fNivel   = 0; }
        if ($fGrado   && !isset($grados[$fGrado]))          { $fGrado   = 0; }
        if ($fSeccion && !isset($seccionesCat[$fSeccion]))  { $fSeccion = 0; }
        if ($fDocente && !isset($docentes[$fDocente]))      { $fDocente = 0; }

        $filtros = [
            'nivel'   => $fNivel,
            'grado'   => $fGrado,
            'seccion' => $fSeccion,
            'docente' => $fDocente,
        ];

        if ($fNivel || $fGrado || $fSeccion || $fDocente) {
            $filas = array_values(array_filter($filas, fn($c) =>
                (!$fNivel   || (int) $c['nivel_id']   === $fNivel)
             && (!$fGrado   || (int) $c['grado_id']   === $fGrado)
             && (!$fSeccion || (int) $c['seccion_id'] === $fSeccion)
             && (!$fDocente || (int) $c['docente_id'] === $fDocente)
            ));
        }

        // Criterios vivos del periodo, indexados por "carga-competencia". Una
        // sola consulta para todo el bimestre (ver CriterioModel).
        // Sin el extraordinario de RA (18/09/2026): no es un criterio que el
        // docente haya definido, y esta pantalla (y su imprimible) lista esos.
        $porPar = [];
        foreach (criterios_ordinarios($this->criterioModel->getCriteriosPorPeriodo($periodoId)) as $cr) {
            $porPar[(int) $cr['carga_id'] . '-' . (int) $cr['competencia_id']][] = $cr;
        }

        $secciones     = [];
        $seccionDeCarga = [];

        foreach ($filas as $c) {
            $sid = (int) $c['seccion_id'];
            $cid = (int) $c['carga_id'];
            $seccionDeCarga[$cid] = $sid;

            if (!isset($secciones[$sid])) {
                $secciones[$sid] = [
                    'seccion_id'     => $sid,
                    'seccion_nombre' => $c['seccion_nombre'],
                    'grado_nombre'   => $c['grado_nombre'],
                    'nivel_nombre'   => $c['nivel_nombre'],
                    'cargas'         => [],
                ];
            }
            if (!isset($secciones[$sid]['cargas'][$cid])) {
                $secciones[$sid]['cargas'][$cid] = [
                    'carga_id'       => $cid,
                    'area_nombre'    => $c['area_nombre'],
                    'subarea_nombre' => $c['subarea_nombre'],
                    'docente'        => $this->nombreDocente($c),
                    'competencias'   => [],
                ];
            }

            $secciones[$sid]['cargas'][$cid]['competencias'][] = [
                'competencia_id'   => (int) $c['competencia_id'],
                'nombre'           => $c['competencia_nombre'],
                'codigo'           => $c['competencia_codigo'],
                'bloqueado_en'     => $c['bloqueado_en'],
                'es_transversal'   => false,
                'criterios'        => $porPar[$cid . '-' . (int) $c['competencia_id']] ?? [],
            ];
        }

        // Transversales: se anexan a su carga, despues de las academicas, igual
        // que las pinta la vista de carga.
        foreach ($this->transversalesConContenido(array_keys($seccionDeCarga), $periodoId) as $cid => $lista) {
            $sid = $seccionDeCarga[$cid] ?? null;
            if ($sid === null) {
                continue;
            }
            foreach ($lista as $t) {
                $secciones[$sid]['cargas'][$cid]['competencias'][] = [
                    'competencia_id' => (int) $t['competencia_id'],
                    'nombre'         => $t['nombre_completo'],
                    // Las transversales vienen por otra consulta, que YA traia el
                    // codigo: la clave se llama igual para que la vista no tenga
                    // que saber de que rama salio cada competencia.
                    'codigo'         => $t['codigo_minedu'],
                    'bloqueado_en'   => $t['bloqueado_en'],
                    'es_transversal' => true,
                    'criterios'      => $porPar[$cid . '-' . (int) $t['competencia_id']] ?? [],
                ];
            }
        }

        // Poda y contadores. Una competencia BLOQUEADA puede no tener ningun
        // criterio (el cierre forzado bloquea igual): esos nodos se caen, o la
        // pantalla se llenaria de cargas vacias.
        $total   = 0;
        $conDesc = 0;
        $salida  = [];

        foreach ($secciones as $s) {
            $cargasVivas = [];

            foreach ($s['cargas'] as $carga) {
                $compsVivas = [];

                foreach ($carga['competencias'] as $comp) {

                    $comp['n_criterios'] = count($comp['criterios']);
                    if ($comp['n_criterios'] === 0) {
                        continue;
                    }

                    // El 82 % de los criterios NO tiene descripcion (2233 de 2731
                    // en B2). La vista ya no pinta "Sin descripcion" en cada uno:
                    // deja la celda vacia y ensena este contador por carga.
                    $comp['n_con_desc'] = 0;
                    foreach ($comp['criterios'] as $cr) {
                        if (trim((string) $cr['descripcion']) !== '') {
                            $conDesc++;
                            $comp['n_con_desc']++;
                        }
                    }
                    $total       += $comp['n_criterios'];
                    $compsVivas[] = $comp;
                }

                if (empty($compsVivas)) {
                    continue;
                }

                $carga['competencias']   = $compsVivas;
                $carga['n_competencias'] = count($compsVivas);
                $carga['n_criterios']    = array_sum(array_column($compsVivas, 'n_criterios'));
                $carga['n_con_desc']     = array_sum(array_column($compsVivas, 'n_con_desc'));
                $cargasVivas[]           = $carga;
            }

            if (empty($cargasVivas)) {
                continue;
            }

            $s['cargas']      = $cargasVivas;
            $s['n_cargas']    = count($cargasVivas);
            $s['n_criterios'] = array_sum(array_column($cargasVivas, 'n_criterios'));
            $s['n_con_desc']  = array_sum(array_column($cargasVivas, 'n_con_desc'));
            $salida[]         = $s;
        }

        return [
            'secciones'       => $salida,
            'total'           => $total,
            'con_descripcion' => $conDesc,
            'niveles'         => $niveles,
            'grados'          => $grados,
            'seccionesCat'    => $seccionesCat,
            'docentes'        => $docentes,
            // Los filtros EFECTIVOS, ya sin los valores que no existen en este
            // periodo. Quien pinta la pantalla debe usar estos y no los que
            // llegaron por la URL, o el selector volveria a decir una cosa
            // mientras la consulta hace otra.
            'filtros'         => $filtros,
        ];
    }

    /**
     * GET /consulta-notas/criterios
     * Entrada sin periodo (la card del dashboard): salta al bimestre por
     * defecto. Reusa `ControlOperativoModel::getPeriodoPorDefecto()`, el mismo
     * punto unico que usan los Cuadros estadisticos, en vez de decidir aqui
     * cual es "el bimestre de hoy".
     */
    public function criteriosInicio(): void
    {
        $periodo = (new ControlOperativoModel())->getPeriodoPorDefecto();
        if (!$periodo) {
            $this->notFound();
        }
        redirect(url('consulta-notas/' . (int) $periodo['id'] . '/criterios'));
    }

    /**
     * GET /consulta-notas/{periodo_id}/criterios
     * Explorador de criterios de evaluacion del bimestre, en solo lectura.
     */
    public function criterios(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        // 🔴 CAMBIAR DE BIMESTRE LIMPIA LOS CUATRO FILTROS (25/08/2026), y ese es
        // el proposito del redirect: al soltar la query, los filtros no viajan al
        // bimestre nuevo. Antes los arrastraba, y eso mantenia vigente una consulta
        // pensada para OTRO periodo: al saltar a un bimestre en curso —donde el
        // universo es mucho menor— la pantalla salia vacia con los selectores en
        // "Todas". Se cambia de bimestre para ver el bimestre, no para repetir la
        // busqueda. La mecanica del salto vive en `saltarDePeriodo()`, compartida
        // con la pestaña Docentes.
        //
        // ⚠️ Desde el 26/08/2026 el <select> del bimestre ya NO esta en el
        // formulario de filtros: vive en la card comun de `_nav.php`, encima de la
        // barra de pestañas. Elegir el bimestre ya seleccionado no dispara `change`,
        // asi que no hay forma de limpiar los filtros sin cambiar de bimestre.
        $this->saltarDePeriodo($periodoId, 'criterios');

        $filtros = [
            'nivel'   => (int) ($this->query('nivel')   ?? 0),
            'grado'   => (int) ($this->query('grado')   ?? 0),
            'seccion' => (int) ($this->query('seccion') ?? 0),
            'docente' => (int) ($this->query('docente') ?? 0),
        ];

        $arbol = $this->arbolCriterios($periodoId, $filtros);
        // Los filtros EFECTIVOS: `arbolCriterios` ya descarto los que no existen
        // en este periodo. La vista tiene que marcar estos.
        $filtros = $arbol['filtros'];

        // ── Cuanto se despliega solo ─────────────────────────────────
        // El objetivo de la pantalla es MOSTRAR los criterios, no esconderlos
        // detras de clics: en cuanto el director acota a algo concreto —una
        // seccion, un docente— se abre TODO y puede leerlo de corrido. Sin
        // acotar, el arbol hace de indice: 23 secciones plegadas con sus
        // contadores, que es lo unico que cabe en una pantalla.
        //
        // El tope existe para el caso intermedio (un nivel entero son 1325
        // criterios): ahi se abren las secciones y las cargas quedan plegadas.
        $hayFiltro = (bool) array_filter($filtros);
        $abrirTodo = $hayFiltro && $arbol['total'] <= self::CRITERIOS_ABRIR_TODO;
        $abrirSecc = $hayFiltro && !$abrirTodo;

        $this->view('consulta-notas/criterios', [
            'titulo'         => 'Criterios de evaluacion — ' . $periodo['nombre_display'],
            'periodo'        => $periodo,
            'periodos'       => $this->listarPeriodos(),
            'secciones'      => $arbol['secciones'],
            'total'          => $arbol['total'],
            'conDescripcion' => $arbol['con_descripcion'],
            'niveles'        => $arbol['niveles'],
            'grados'         => $arbol['grados'],
            'seccionesCat'   => $arbol['seccionesCat'],
            'docentes'       => $arbol['docentes'],
            'filtros'        => $filtros,
            'abrirTodo'      => $abrirTodo,
            'abrirSecciones' => $abrirSecc,
            'page_scripts'   => ['criterios'],
        ]);
    }

    /**
     * GET /consulta-notas/{periodo_id}/criterios/imprimir
     * El mismo arbol en A4 vertical, con TODO desplegado: <details> cerrado no
     * imprime su contenido, asi que el imprimible no puede reusar la vista.
     */
    public function criteriosImprimir(string $periodoId): void
    {
        $periodoId = (int) $periodoId;
        $periodo   = $this->getPeriodo($periodoId);
        if (!$periodo) {
            $this->notFound();
        }

        // El imprimible hereda los MISMOS filtros que la pantalla: se imprime lo
        // que se esta viendo, no el bimestre entero.
        $filtros = [
            'nivel'   => (int) ($this->query('nivel')   ?? 0),
            'grado'   => (int) ($this->query('grado')   ?? 0),
            'seccion' => (int) ($this->query('seccion') ?? 0),
            'docente' => (int) ($this->query('docente') ?? 0),
        ];
        $arbol   = $this->arbolCriterios($periodoId, $filtros);
        // Mismos filtros efectivos que la pantalla: el papel dice lo que filtro.
        $filtros = $arbol['filtros'];

        View::setLayout('print');
        $this->view('consulta-notas/criterios-imprimir', [
            'titulo'       => 'Criterios de evaluacion — ' . $periodo['nombre_display'],
            'periodo'      => $periodo,
            'secciones'    => $arbol['secciones'],
            'total'        => $arbol['total'],
            'filtros'      => $filtros,
            'niveles'      => $arbol['niveles'],
            'grados'       => $arbol['grados'],
            'seccionesCat' => $arbol['seccionesCat'],
            'docentes'     => $arbol['docentes'],
        ]);
    }
}
