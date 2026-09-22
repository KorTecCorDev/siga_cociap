<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\AsistenciaModel;
use App\Models\CalificacionModel;
use App\Models\ConductaModel;
use App\Models\TransversalModel;
use Core\Session;

class BloqueoController extends BaseController
{
    /**
     * Quien ESCRIBE. Los directores entran a este controlador y VEN, pero
     * desde el 24/08/2026 no operan: su rol es de supervision en solo
     * lectura. Se valida en cada metodo de escritura, NO ocultando el boton
     * en la vista: esconder la UI no es control de acceso.
     */
    private const ROLES_ESCRIBEN = ['admin', 'registro_academico'];

    private CalificacionModel $calModel;
    private TransversalModel  $transModel;
    private ConductaModel     $conductaModel;
    private AsistenciaModel   $asistenciaModel;

    public function __construct()
    {
        // `registro_academico` ENTRA desde el 24/08/2026 (decision D1). Antes el
        // dashboard le ofrecia la card "Bloqueos del bimestre" y el controlador
        // le devolvia 403: la card mentia. Y al retirar la escritura a los
        // directores, sin RA este panel —la herramienta con la que se opera cada
        // cierre de bimestre— habria quedado solo en manos de `admin`.
        $this->requireRole(['admin', 'registro_academico', ...ROLES_DIRECCION]);
        $this->calModel        = new CalificacionModel();
        $this->transModel      = new TransversalModel();
        $this->conductaModel   = new ConductaModel();
        $this->asistenciaModel = new AsistenciaModel();
    }

    /**
     * GET /director/bloqueos
     * Lista todas las competencias del periodo seleccionado con su estado.
     */
    public function index(): void
    {
        $periodos = $this->calModel->query("
            SELECT p.id, p.numero, p.nombre_display, p.estado, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");

        $periodoId    = (int) ($this->query('periodo_id') ?? 0);
        $competencias = [];
        $periodo      = null;
        $stats        = ['total' => 0, 'bloqueadas' => 0, 'pendientes' => 0, 'sin_criterios' => 0, 'cierre_forzado' => 0];

        if ($periodoId) {
            $periodo = $this->calModel->queryOne("
                SELECT p.*, a.anio
                FROM periodos p
                INNER JOIN anios_academicos a ON a.id = p.anio_id
                WHERE p.id = ?
            ", [$periodoId]);

            if ($periodo) {
                $competencias           = $this->calModel->getCompetenciasPorPeriodo($periodoId);
                $stats['total']         = count($competencias);
                $stats['bloqueadas']    = count(array_filter($competencias, fn($c) => $c['bloqueo_id'] !== null));
                $stats['sin_criterios'] = count(array_filter($competencias, fn($c) => $c['bloqueo_id'] === null && (int)$c['num_criterios'] === 0));
                $stats['pendientes']    = $stats['total'] - $stats['bloqueadas'] - $stats['sin_criterios'];
                $stats['cierre_forzado'] = count(array_filter($competencias, fn($c) => ($c['bloqueo_origen'] ?? null) === 'cierre'));

                // Agrupar por docente y calcular conteos
                $statsDocentes = [];
                foreach ($competencias as $c) {
                    $did = (int) $c['docente_id'];
                    if (!isset($statsDocentes[$did])) {
                        $statsDocentes[$did] = [
                            'apellido'      => $c['docente_apellido'],
                            'nombres'       => $c['docente_nombres'],
                            'total'         => 0,
                            'bloqueadas'    => 0,
                            'pendientes'    => 0,
                            'sin_criterios' => 0,
                        ];
                    }
                    $d = &$statsDocentes[$did];
                    $d['total']++;
                    if ($c['bloqueo_id'] !== null) {
                        $d['bloqueadas']++;
                    } elseif ((int) $c['num_criterios'] === 0) {
                        $d['sin_criterios']++;
                    } else {
                        $d['pendientes']++;
                    }
                    unset($d);
                }

                // Ordenar: más sin_criterios primero; en empate, más pendientes primero
                usort($statsDocentes, function ($a, $b) {
                    if ($b['sin_criterios'] !== $a['sin_criterios']) {
                        return $b['sin_criterios'] - $a['sin_criterios'];
                    }
                    return $b['pendientes'] - $a['pendientes'];
                });

                // Top 5 con algún incumplimiento
                $topCriticos = array_slice(
                    array_values(array_filter(
                        $statsDocentes,
                        fn($d) => $d['sin_criterios'] > 0 || $d['pendientes'] > 0
                    )),
                    0, 5
                );

                // Avance por nivel educativo
                $statsPorNivel = [];
                foreach ($competencias as $c) {
                    $nid = (int) $c['nivel_id'];
                    if (!isset($statsPorNivel[$nid])) {
                        $statsPorNivel[$nid] = [
                            'nombre'     => $c['nivel_nombre'],
                            'total'      => 0,
                            'bloqueadas' => 0,
                        ];
                    }
                    $statsPorNivel[$nid]['total']++;
                    if ($c['bloqueo_id'] !== null) {
                        $statsPorNivel[$nid]['bloqueadas']++;
                    }
                }

                // Secciones al 100%
                $_secStats = [];
                foreach ($competencias as $c) {
                    $sid = (int) $c['seccion_id'];
                    if (!isset($_secStats[$sid])) {
                        $_secStats[$sid] = ['total' => 0, 'bloqueadas' => 0];
                    }
                    $_secStats[$sid]['total']++;
                    if ($c['bloqueo_id'] !== null) {
                        $_secStats[$sid]['bloqueadas']++;
                    }
                }
                $totalSecciones     = count($_secStats);
                $seccionesCompletas = count(array_filter(
                    $_secStats,
                    fn($s) => $s['total'] > 0 && $s['bloqueadas'] === $s['total']
                ));

                // Días restantes para el cierre
                $diasRestantes = null;
                if (!empty($periodo['limite_notas'])) {
                    $diasRestantes = (int) ceil(
                        (strtotime($periodo['limite_notas']) - time()) / 86400
                    );
                }
            }
        }

        // Estado transversal por sección (TIC/GAMA del tutor) — bloque aparte,
        // manejable (cerrar/reabrir) sin afectar las estadísticas académicas.
        // El estado lo gobierna el CIERRE de la sección, no la carga transversal
        // heredada (inactiva). 'lista' = todas las cargas propias bloqueadas.
        $transversales = [];
        $transStats    = ['total' => 0, 'cerradas' => 0];
        if ($periodoId && $periodo) {
            // Bloqueos de competencia (TIC/GAMA) por carga, agrupados por sección
            // y luego por carga: son el OTRO nivel, el que impide al DOCENTE
            // editar. El cierre del tutor (abajo) no los toca, y no aparecen en el
            // panel académico porque cuelgan de un área tipo='transversal'.
            $bloqPorSeccion = [];
            foreach ($this->transModel->getBloqueosTransversalesPorPeriodo($periodoId) as $b) {
                $sid = (int) $b['seccion_id'];
                $cid = (int) $b['carga_id'];
                if (!isset($bloqPorSeccion[$sid][$cid])) {
                    $bloqPorSeccion[$sid][$cid] = [
                        'carga_nombre'   => $b['carga_nombre'],
                        'docente_nombre' => $b['docente_nombre'],
                        'competencias'   => [],
                    ];
                }
                $bloqPorSeccion[$sid][$cid]['competencias'][] = $b;
            }

            foreach ($this->transModel->getResumenSeccionesPorPeriodo($periodoId) as $s) {
                // Mismos estados que las competencias académicas: Bloqueada (cierre
                // vigente) o Pendiente. La validación de readiness vive en cerrarTransversal.
                $sid             = (int) $s['seccion_id'];
                $s['cerrada']    = $s['cierre_id'] !== null;
                $s['cargas']     = array_values($bloqPorSeccion[$sid] ?? []);
                $s['n_bloqueos'] = array_sum(array_map(
                    static fn(array $c): int => count($c['competencias']),
                    $s['cargas']
                ));
                $transversales[] = $s;
            }
            $transStats['total']    = count($transversales);
            $transStats['cerradas'] = count(array_filter($transversales, fn($s) => $s['cerrada']));
        }

        // Conducta por sección: dos etapas (auxiliar académico → tutor). El
        // director puede forzar ambas o reabrir. 'estado' resume las dos etapas.
        $conducta      = [];
        $conductaStats = ['total' => 0, 'bloqueadas' => 0, 'cerradas' => 0];
        if ($periodoId && $periodo) {
            foreach ($this->conductaModel->getResumenSeccionesPorPeriodo($periodoId) as $s) {
                $bloqueada = $s['ra_bloqueado_en']   !== null;   // etapa 1 (auxiliar)
                $cerrada   = $s['tutor_cerrado_en']  !== null;   // etapa 2 (tutor)
                $s['bloqueada'] = $bloqueada;
                $s['cerrada']   = $cerrada;
                $s['estado']    = $cerrada ? 'cerrada' : ($bloqueada ? 'pendiente_tutor' : 'pendiente_auxiliar');
                $conducta[]     = $s;
            }
            $conductaStats['total']      = count($conducta);
            $conductaStats['bloqueadas'] = count(array_filter($conducta, fn($s) => $s['bloqueada']));
            $conductaStats['cerradas']   = count(array_filter($conducta, fn($s) => $s['cerrada']));
        }

        // Asistencia por sección: una sola etapa (Registro Académico bloquea).
        // El director puede forzar el bloqueo o reabrir. Sin fila = 0 incidencias
        // (estado válido), así que el bloqueo no exige completitud.
        $asistencia      = [];
        $asistenciaStats = ['total' => 0, 'bloqueadas' => 0];
        if ($periodoId && $periodo) {
            foreach ($this->asistenciaModel->getResumenSeccionesPorPeriodo($periodoId) as $s) {
                $s['bloqueada'] = $s['cierre_id'] !== null;
                $asistencia[]   = $s;
            }
            $asistenciaStats['total']      = count($asistencia);
            $asistenciaStats['bloqueadas'] = count(array_filter($asistencia, fn($s) => $s['bloqueada']));
        }

        $this->view('director/bloqueos/index', [
            // Vista de SOLO LECTURA para los directores: sin controles.
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
            'titulo'             => 'Gestión de bloqueos',
            'periodos'           => $periodos,
            'periodoId'          => $periodoId,
            'periodo'            => $periodo,
            'competencias'       => $competencias,
            'transversales'      => $transversales,
            'transStats'         => $transStats,
            'conducta'           => $conducta,
            'conductaStats'      => $conductaStats,
            'asistencia'         => $asistencia,
            'asistenciaStats'    => $asistenciaStats,
            'stats'              => $stats,
            'statsDocentes'      => $statsDocentes   ?? [],
            'topCriticos'        => $topCriticos     ?? [],
            'statsPorNivel'      => $statsPorNivel   ?? [],
            'totalSecciones'     => $totalSecciones  ?? 0,
            'seccionesCompletas' => $seccionesCompletas ?? 0,
            'diasRestantes'      => $diasRestantes   ?? null,
            'page_scripts'       => ['bloqueos'],
        ]);
    }

    /**
     * POST /director/bloqueos/{id}/desbloquear
     * Elimina el bloqueo para que el docente pueda editar las notas.
     *
     * Reapertura de carga: cada competencia —incluidas las transversales
     * TIC/GAMA— se bloquea y desbloquea de forma independiente, así que solo
     * se libera la competencia indicada. Si la sección tenía cierre transversal
     * vigente se ANULA con traza de quién/por qué (la nota agregada TIC/GAMA de
     * la boleta depende del cierre, no de cada bloqueo individual).
     */
    /**
     * Guard comun de las CUATRO reaperturas del panel (competencia, transversal,
     * conducta, asistencia): ninguna procede con el bimestre cerrado.
     *
     * El motivo es el mismo en las cuatro: `periodoEditable`/`periodoEstaBloqueado`
     * cortan por `estado='cerrado'` SIN mirar el bloqueo, asi que reabrir no
     * habilita a nadie a corregir. Y en tres de ellas, ademas, el dato
     * DESAPARECE del documento mientras tanto: la boleta pinta solo competencias
     * bloqueadas, `getTransversalesAgregadas` exige cierre vigente y
     * `ConductaModel::getParaPeriodo` devuelve null sin el. La asistencia es la
     * excepcion —`getDelBimestre` lee `inasistencias` directo— pero reabrirla
     * tampoco sirve de nada, por eso el guard es igual. Cada llamada pasa SU
     * mensaje: el efecto no es identico y el aviso no debe mentir.
     *
     * La via correcta siempre es reabrir el bimestre (`PeriodoController::reabrir`).
     * Mismo criterio que `limpiarBloqueosCierre`, que ya lo exigia.
     */
    private function abortarSiPeriodoCerrado(int $periodoId, string $back, string $mensaje): void
    {
        $periodo = $this->calModel->queryOne(
            "SELECT estado FROM periodos WHERE id = ?", [$periodoId]
        );
        if (!$periodo || $periodo['estado'] !== 'activo') {
            $this->redirectWithError($back, $mensaje);
        }
    }

    /**
     * Guard de los desbloqueos individuales (académica y transversal): no se
     * libera una competencia con calificaciones EXTRAORDINARIAS de RA
     * (decisión del usuario, 18/09/2026). Por qué, en
     * `CalificacionModel::SIN_EXTRAORDINARIAS_BC`; la liberación en bloque del
     * cierre forzado aplica la misma regla y conserva esas competencias.
     */
    private function abortarSiHayExtraordinarias(array $bloqueo, string $back): void
    {
        $alumnos = $this->calModel->alumnosConExtraordinaria(
            (int) $bloqueo['carga_id'],
            (int) $bloqueo['competencia_id'],
            (int) $bloqueo['periodo_id']
        );
        if ($alumnos === []) {
            return;
        }

        $lista = implode('; ', array_slice($alumnos, 0, 5))
            . (count($alumnos) > 5 ? ' y ' . (count($alumnos) - 5) . ' más' : '');
        $this->redirectWithError(
            $back,
            'No se puede desbloquear: ' . count($alumnos) . ' estudiante(s) tienen calificación '
            . 'extraordinaria de Registro Académico en esta competencia (' . $lista . '). '
            . 'Si el docente volviera a editarla, esa nota se mezclaría con las suyas.'
        );
    }

    public function desbloquear(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id   = (int) $id;
        $user = Session::user();

        $bloqueo = $this->calModel->queryOne("
            SELECT bc.id, bc.periodo_id, bc.carga_id, bc.competencia_id,
                   ca.seccion_id,
                   comp.nombre_corto AS competencia_nombre
            FROM bloqueos_competencia bc
            INNER JOIN cargas_academicas ca ON ca.id  = bc.carga_id
            INNER JOIN competencias comp    ON comp.id = bc.competencia_id
            WHERE bc.id = ?
        ", [$id]);

        if (!$bloqueo) {
            $this->redirectWithError(url('director/bloqueos'), 'Bloqueo no encontrado.');
        }

        $periodoId = (int) $bloqueo['periodo_id'];
        $cargaId   = (int) $bloqueo['carga_id'];
        $back      = url("director/bloqueos?periodo_id={$periodoId}");

        // La boleta muestra SOLO competencias bloqueadas: con el bimestre
        // cerrado, quitar el bloqueo saca la nota del documento de todos los
        // alumnos de la carga.
        $this->abortarSiPeriodoCerrado(
            $periodoId,
            $back,
            'No se puede desbloquear con el bimestre cerrado: la competencia desapareceria '
            . 'de la boleta y el docente seguiria sin poder editarla. Reabre el bimestre primero.'
        );
        $this->abortarSiHayExtraordinarias($bloqueo, $back);

        try {
            $this->calModel->beginTransaction();

            $ok = $this->calModel->desbloquearCompetencia($id);
            if (!$ok) {
                $this->calModel->rollback();
                $this->redirectWithError($back, 'No se pudo desbloquear la competencia.');
            }

            // ⚠️ AQUI YA NO HAY CASCADA SOBRE LAS TRANSVERSALES (07/08/2026).
            // Hasta hoy se llamaba a `liberarTransversalesDeCarga`, que borraba los
            // bloqueos TIC/GAMA de la carga. Su motivo era que las transversales no
            // son filas de este panel y quedarian "bloqueadas e inalcanzables"; eso
            // dejo de ser cierto el 06/08 con el desbloqueo granular por competencia
            // (`liberarTransversalCompetencia`, desplegable de cada seccion).
            // Mantenerla obligaba al DOCENTE a re-aprobar TIC/GAMA que nadie habia
            // tocado y, peor, bajaba el numerador de `estadoCargasSeccion`, con lo
            // que el TUTOR no podia re-cerrar hasta que el docente volviera a
            // aprobarlas. Ahora se desbloquea SOLO la competencia pedida.
            //
            // El cierre del tutor SI se anula, y es deliberado: aunque el promedio
            // transversal no cambie —`getPromediosSeccion` solo lee bloqueos de
            // competencias transversales, y esta no lo es—, si cambian las notas del
            // estudiante la CONCLUSION DESCRIPTIVA que el tutor escribio puede dejar
            // de ser precisa. Decision del usuario (07/08/2026).
            // Como los bloqueos transversales quedan intactos, el gate sigue
            // cuadrando y el tutor puede revisar y re-cerrar de inmediato, sin
            // depender de que el docente haga nada.
            $this->transModel->anularCierreVigente(
                (int) $bloqueo['seccion_id'],
                $periodoId,
                (int) $user['id'],
                'Desbloqueo de la competencia "' . ($bloqueo['competencia_nombre'] ?? $bloqueo['competencia_id'])
                    . '" (carga ' . $cargaId . ') por el director.'
            );

            $this->calModel->commit();
        } catch (\Exception $e) {
            $this->calModel->rollback();
            log_error('Error desbloqueando competencia', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError($back, 'No se pudo desbloquear la competencia.');
        }

        $this->redirectWithSuccess(
            $back,
            'Competencia desbloqueada. El docente puede volver a editar sus notas. '
            . 'Las competencias transversales (TIC/GAMA) de la carga NO se tocaron: '
            . 'si tambien hay que reabrirlas, usa el desplegable de la seccion en la '
            . 'pestana de transversales. Si la seccion tenia cierre transversal, quedo '
            . 'anulado para que el tutor revise sus conclusiones y lo repita.'
        );
    }

    /**
     * POST /director/bloqueos/limpiar-cierre
     * Libera de forma MANUAL todos los bloqueos del cierre forzado
     * (origen='cierre') del periodo: las competencias que el docente nunca
     * aprobó y que el cierre del bimestre bloqueó automáticamente. Los bloqueos
     * del docente (origen='docente', incluidas las finalizadas-vacías) se
     * conservan. Requiere el bimestre reabierto (estado 'activo'). Anula los
     * cierres transversales de las secciones afectadas con traza.
     */
    public function limpiarBloqueosCierre(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();

        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        $periodo = $this->calModel->queryOne("
            SELECT id, estado, nombre_display FROM periodos WHERE id = ?
        ", [$periodoId]);
        if (!$periodo) {
            $this->redirectWithError(url('director/bloqueos'), 'Bimestre no encontrado.');
        }
        if ($periodo['estado'] !== 'activo') {
            $this->redirectWithError(
                $back,
                'Solo se pueden liberar los bloqueos del cierre forzado con el bimestre reabierto (activo). Reábrelo primero.'
            );
        }

        $anioModel = new \App\Models\AnioAcademicoModel();
        $liberadas = 0;
        try {
            $anioModel->beginTransaction();
            // Identificar secciones afectadas ANTES de borrar (para anular su cierre transversal).
            $seccionesAfectadas = $this->transModel->seccionesConBloqueosDeCierre($periodoId);
            $liberadas = $anioModel->eliminarBloqueosDeCierre($periodoId);
            if ($liberadas > 0 && !empty($seccionesAfectadas)) {
                $this->transModel->anularCierresDeSecciones(
                    $seccionesAfectadas,
                    $periodoId,
                    (int) $user['id'],
                    'Liberación manual de bloqueos del cierre forzado por el director.'
                );
            }
            $anioModel->commit();
        } catch (\Exception $e) {
            $anioModel->rollback();
            log_error('Error liberando bloqueos del cierre', ['periodo' => $periodoId, 'error' => $e->getMessage()]);
            $this->redirectWithError($back, 'No se pudieron liberar los bloqueos del cierre forzado.');
        }

        // Las que tienen calificaciones extraordinarias se conservan (misma
        // regla que el desbloqueo individual): se informa cuántas.
        $conservadas = $anioModel->bloqueosDeCierreConExtraordinarias($periodoId);
        $avisoConservadas = $conservadas > 0
            ? " Se conservaron {$conservadas} bloqueada(s) porque tienen calificaciones extraordinarias de Registro Académico."
            : '';

        if ($liberadas > 0) {
            $this->redirectWithSuccess(
                $back,
                "Se liberaron {$liberadas} competencia(s) del cierre forzado. Los docentes pueden volver a editarlas."
                . $avisoConservadas
            );
        }
        $this->redirectWithError(
            $back,
            'No había bloqueos del cierre forzado para liberar en este periodo.' . $avisoConservadas
        );
    }

    /**
     * POST /director/bloqueos/bloquear
     * Bloquea manualmente una competencia desde el panel del director.
     */
    public function bloquear(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();

        $cargaId       = (int) $this->input('carga_id');
        $competenciaId = (int) $this->input('competencia_id');
        $periodoId     = (int) $this->input('periodo_id');
        $user          = Session::user();

        if (!$cargaId || !$competenciaId || !$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Datos incompletos.');
        }

        $ok = $this->calModel->bloquearCompetencia(
            $cargaId, $competenciaId, $periodoId, $user['id']
        );

        if ($ok) {
            $this->redirectWithSuccess(
                url("director/bloqueos?periodo_id={$periodoId}"),
                'Competencia bloqueada correctamente.'
            );
        }

        $this->redirectWithError(
            url("director/bloqueos?periodo_id={$periodoId}"),
            'No se pudo bloquear la competencia.'
        );
    }

    /**
     * POST /director/bloqueos/transversal/{seccion_id}/cerrar
     * Cierra (aprueba) las competencias transversales de la sección. Igual que
     * el tutor: valida que todas las cargas estén bloqueadas y que no falten
     * conclusiones obligatorias. El cierre es lo que habilita TIC/GAMA en la boleta.
     */
    public function cerrarTransversal(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        $sec = $this->calModel->queryOne("
            SELECT n.codigo AS nivel_codigo
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE s.id = ?
        ", [$seccionId]);
        if (!$sec) {
            $this->redirectWithError($back, 'Sección no encontrada.');
        }

        if ($this->transModel->getCierreVigente($seccionId, $periodoId)) {
            $this->redirectWithError($back, 'Las transversales de esta sección ya están cerradas.');
        }

        $estado = $this->transModel->estadoCargasSeccion($seccionId, $periodoId);
        if ($estado['total'] === 0 || $estado['bloqueadas'] < $estado['total']) {
            $this->redirectWithError(
                $back,
                'No se puede cerrar: faltan competencias transversales por bloquear ('
                . $estado['bloqueadas'] . ' de ' . $estado['total'] . ').'
            );
        }

        $pendientes = $this->transModel->conclusionesObligatoriasPendientes(
            $seccionId, $periodoId, $sec['nivel_codigo']
        );
        if ($pendientes > 0) {
            $this->redirectWithError(
                $back,
                'No se puede cerrar: faltan ' . $pendientes . ' conclusión(es) obligatoria(s).'
            );
        }

        $ok = $this->transModel->cerrar($seccionId, $periodoId, (int) $user['id']);
        if ($ok) {
            $this->redirectWithSuccess($back,
                'Competencias transversales cerradas. TIC/GAMA ya aparecen en las boletas de la sección.');
        }
        $this->redirectWithError($back, 'No se pudo cerrar las competencias transversales.');
    }

    /**
     * POST /director/bloqueos/transversal/{seccion_id}/reabrir
     * Anula el cierre transversal vigente de la sección (las TIC/GAMA dejan de
     * aparecer en la boleta hasta que el tutor vuelva a cerrar). No toca los
     * bloqueos por docente: solo reabre la aprobación del tutor.
     */
    public function reabrirTransversal(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        // Sin cierre vigente, `getTransversalesAgregadas` corta y TIC/GAMA salen
        // de la boleta de toda la seccion.
        $this->abortarSiPeriodoCerrado(
            $periodoId,
            $back,
            'No se puede reabrir con el bimestre cerrado: las competencias transversales '
            . '(TIC/GAMA) desaparecerian de la boleta de la seccion y el tutor seguiria sin '
            . 'poder editarlas. Reabre el bimestre primero.'
        );

        $ok = $this->transModel->anularCierreVigente(
            $seccionId, $periodoId, (int) $user['id'],
            'Reapertura del cierre transversal por el director desde el panel de bloqueos.'
        );

        if ($ok) {
            $this->redirectWithSuccess($back,
                'Cierre transversal anulado. El tutor puede editar las conclusiones y volver a cerrar.');
        }
        $this->redirectWithError($back, 'No había un cierre transversal vigente para anular.');
    }

    /**
     * POST /director/bloqueos/transversal-competencia/{bloqueo_id}/liberar
     * Libera UNA competencia transversal (TIC o GAMA) de UNA carga, para que su
     * docente pueda corregir lo que aprobó por error.
     *
     * POR QUÉ EXISTE: las transversales NO son filas del panel principal
     * (`getCompetenciasPorPeriodo` une por el área de la CARGA y ellas cuelgan de
     * un área propia), así que hasta el 06/08/2026 la única vía era la CASCADA:
     * desbloquear una competencia ACADÉMICA de la misma carga, que además la
     * sacaba a ella de la boleta y liberaba las DOS transversales de golpe. Y si
     * la carga no tenía ninguna académica bloqueada —estado alcanzable, porque
     * bloquear transversales primero está permitido— no había vía ninguna.
     *
     * ⚠️ ANULA EL CIERRE TRANSVERSAL de la sección, igual que la cascada: el
     * agregado de la boleta (`getTransversalesAgregadas`) exige cierre vigente y
     * promedia SOLO lo bloqueado, así que dejarlo en pie mostraría a las familias
     * un promedio que ya no se corresponde con lo que hay bloqueado.
     */
    public function liberarTransversalCompetencia(string $bloqueoId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id   = (int) $bloqueoId;
        $user = Session::user();

        // El anclaje EXIGE que sea transversal: este endpoint no puede servir
        // para desbloquear una académica saltándose la cascada de `desbloquear`.
        $bloqueo = $this->calModel->queryOne("
            SELECT bc.id, bc.periodo_id, bc.carga_id, bc.competencia_id,
                   ca.seccion_id,
                   comp.nombre_corto AS competencia_nombre
            FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            INNER JOIN areas a           ON a.id    = comp.area_id AND a.tipo = 'transversal'
            INNER JOIN cargas_academicas ca ON ca.id = bc.carga_id
            WHERE bc.id = ?
        ", [$id]);

        if (!$bloqueo) {
            $this->redirectWithError(
                url('director/bloqueos'),
                'Bloqueo no encontrado o no corresponde a una competencia transversal.'
            );
        }

        $periodoId = (int) $bloqueo['periodo_id'];
        $back      = url("director/bloqueos?periodo_id={$periodoId}");

        $this->abortarSiPeriodoCerrado(
            $periodoId,
            $back,
            'No se puede liberar con el bimestre cerrado: la competencia transversal '
            . 'desapareceria de la boleta y el docente seguiria sin poder editarla. '
            . 'Reabre el bimestre primero.'
        );
        $this->abortarSiHayExtraordinarias($bloqueo, $back);

        try {
            $this->calModel->beginTransaction();

            if (!$this->calModel->desbloquearCompetencia($id)) {
                $this->calModel->rollback();
                $this->redirectWithError($back, 'No se pudo liberar la competencia transversal.');
            }

            $this->transModel->anularCierreVigente(
                (int) $bloqueo['seccion_id'],
                $periodoId,
                (int) $user['id'],
                'Liberacion de la competencia transversal "' . $bloqueo['competencia_nombre']
                    . '" (carga ' . (int) $bloqueo['carga_id'] . ') por el director.'
            );

            $this->calModel->commit();
        } catch (\Exception $e) {
            $this->calModel->rollback();
            log_error('Error liberando competencia transversal', [
                'bloqueo' => $id, 'error' => $e->getMessage(),
            ]);
            $this->redirectWithError($back, 'No se pudo liberar la competencia transversal.');
        }

        $this->redirectWithSuccess(
            $back,
            'Competencia transversal "' . $bloqueo['competencia_nombre'] . '" liberada. '
            . 'El docente puede volver a editarla y aprobarla. Si la seccion tenia cierre '
            . 'transversal, quedo anulado hasta que el tutor lo repita.'
        );
    }

    /**
     * POST /director/bloqueos/conducta/{seccion_id}/bloquear
     * Etapa 1 forzada por el director: bloquea/aprueba la conducta como lo haría
     * el auxiliar académico (hoy Registro Académico). Respeta la regla de negocio:
     * exige que TODOS los estudiantes estén calificados (validado en bloquearRA).
     */
    public function bloquearConducta(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        $nivelId = $this->nivelIdDeSeccion($seccionId);
        if ($nivelId === null) {
            $this->redirectWithError($back, 'Sección no encontrada.');
        }

        $total = $this->conductaModel->totalCriterios($nivelId);
        $res   = $this->conductaModel->bloquearRA($seccionId, $periodoId, (int) $user['id'], $total);

        if ($res['ok']) {
            $this->redirectWithSuccess($back, $res['mensaje']);
        }
        $this->redirectWithError($back, $res['mensaje']);
    }

    /**
     * POST /director/bloqueos/conducta/{seccion_id}/cerrar
     * Etapa 2 forzada por el director: cierra/aprueba la conducta como lo haría
     * el tutor. Precondición (en cerrarTutor): la etapa 1 (auxiliar) ya hecha.
     */
    public function cerrarConducta(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        $res = $this->conductaModel->cerrarTutor($seccionId, $periodoId, (int) $user['id']);

        if ($res['ok']) {
            $this->redirectWithSuccess($back, $res['mensaje']);
        }
        $this->redirectWithError($back, $res['mensaje']);
    }

    /**
     * POST /director/bloqueos/conducta/{seccion_id}/reabrir
     * Anula el cierre de conducta vigente (cualquiera de las dos etapas) con
     * traza, para permitir correcciones. Libre (sin precondición de negocio).
     */
    public function reabrirConducta(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        // Sin cierre vigente, `ConductaModel::getParaPeriodo` devuelve null (su
        // campo `visible`) y la conducta sale de la boleta de toda la seccion.
        $this->abortarSiPeriodoCerrado(
            $periodoId,
            $back,
            'No se puede reabrir con el bimestre cerrado: la conducta desapareceria de la '
            . 'boleta de la seccion y nadie podria corregirla. Reabre el bimestre primero.'
        );

        $ok = $this->conductaModel->anularCierre(
            $seccionId, $periodoId, (int) $user['id'],
            'Reapertura del cierre de conducta por el director desde el panel de bloqueos.'
        );

        if ($ok) {
            $this->redirectWithSuccess($back,
                'Conducta reabierta. El auxiliar académico puede corregir y volver a cerrar.');
        }
        $this->redirectWithError($back, 'No había un cierre de conducta vigente para anular.');
    }

    /**
     * POST /director/bloqueos/asistencia/{seccion_id}/bloquear
     * Bloqueo forzado por el director: aprueba el registro de asistencia como
     * lo haría Registro Académico. Sin precondición de completitud (sin fila
     * de incidencias = 0, estado válido).
     */
    public function bloquearAsistencia(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        if ($this->nivelIdDeSeccion($seccionId) === null) {
            $this->redirectWithError($back, 'Sección no encontrada.');
        }

        $res = $this->asistenciaModel->bloquearRA($seccionId, $periodoId, (int) $user['id']);

        if ($res['ok']) {
            $this->redirectWithSuccess($back, $res['mensaje']);
        }
        $this->redirectWithError($back, $res['mensaje']);
    }

    /**
     * POST /director/bloqueos/asistencia/{seccion_id}/reabrir
     * Anula el cierre de asistencia vigente con traza, para permitir
     * correcciones de Registro Académico.
     */
    public function reabrirAsistencia(string $seccionId): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $periodoId = (int) $this->input('periodo_id');
        $user      = Session::user();

        if (!$periodoId) {
            $this->redirectWithError(url('director/bloqueos'), 'Periodo no especificado.');
        }
        $back = url("director/bloqueos?periodo_id={$periodoId}");

        // A diferencia de las otras tres, la asistencia NO sale de la boleta
        // (`getDelBimestre` lee `inasistencias` sin mirar el cierre). Pero
        // `AsistenciaModel::periodoEditable` exige el periodo `activo`, asi que
        // reabrir dejaria la seccion en un estado que nadie puede tocar. El
        // mensaje lo dice tal cual: aqui no se pierde ningun dato.
        $this->abortarSiPeriodoCerrado(
            $periodoId,
            $back,
            'No se puede reabrir con el bimestre cerrado: nadie podria registrar ni corregir '
            . 'asistencia hasta reabrirlo. Reabre el bimestre primero.'
        );

        $ok = $this->asistenciaModel->anularCierre(
            $seccionId, $periodoId, (int) $user['id'],
            'Reapertura del cierre de asistencia por el director desde el panel de bloqueos.'
        );

        if ($ok) {
            $this->redirectWithSuccess($back,
                'Asistencia reabierta. Registro Académico puede corregir y volver a bloquear.');
        }
        $this->redirectWithError($back, 'No había un cierre de asistencia vigente para anular.');
    }

    /** nivel_id de una sección, o null si no existe. */
    private function nivelIdDeSeccion(int $seccionId): ?int
    {
        $row = $this->calModel->queryOne("
            SELECT g.nivel_id
            FROM secciones s
            INNER JOIN grados g ON g.id = s.grado_id
            WHERE s.id = ?
        ", [$seccionId]);
        return $row ? (int) $row['nivel_id'] : null;
    }
}
