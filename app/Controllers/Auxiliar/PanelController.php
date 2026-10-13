<?php

namespace App\Controllers\Auxiliar;

use App\Controllers\BaseController;
use App\Models\AsistenciaJornadaModel;
use App\Models\AsistenciaModel;
use App\Models\AsistenciaMotivoModel;
use App\Models\AuxiliarSeccionModel;
use App\Models\ConductaModel;
use App\Models\DirectorEbrModel;
use App\Models\EdicionPeriodoModel;
use App\Models\HorarioModel;
use App\Models\NominaModel;
use Core\Session;
use Core\View;

/**
 * Panel del AUXILIAR ACADÉMICO (28/09/2026): `/auxiliar/inicio`, a donde llega
 * al iniciar sesión. Ver docs/modulos/auxiliares.md.
 *
 * SOLO LEE. Resume, para cada sección que el auxiliar tiene a cargo en el
 * bimestre en curso, cuánto lleva registrado de conducta y de asistencia y si
 * ya la bloqueó. Registrar y bloquear se hace en las pantallas de siempre
 * (`/admin/conducta`, `/admin/asistencia`), que deciden el permiso con
 * `AuxiliarSeccionModel::puedeRegistrar()`: este panel no concede nada.
 */
class PanelController extends BaseController
{
    public function __construct()
    {
        $this->requireRole([ROL_AUXILIAR]);
    }

    // GET /auxiliar/inicio
    public function inicio(): void
    {
        $conducta   = new ConductaModel();
        $asistencia = new AsistenciaModel();

        // Bimestre EN CURSO: el activo, esté o no dentro de plazo. Fuera de plazo
        // se sigue mostrando (en gris): el auxiliar tiene que saber que ya no
        // puede registrar, no encontrarse un panel vacío.
        $periodo = null;
        foreach ($conducta->listarPeriodosActivos() as $p) {
            if ($p['estado'] === 'activo') {
                $periodo = $p;
                break;
            }
        }

        $secciones = [];
        if ($periodo !== null) {
            $pid      = (int) $periodo['id'];
            $editable = (bool) $periodo['editable'];
            $mias     = (new AuxiliarSeccionModel())->seccionesDe((int) Session::user()['id'], $pid);
            $progC    = $conducta->getProgresoConductaPorSeccion($pid);
            $progA    = $asistencia->getProgresoPorSeccion($pid);
            // Bimestre por fechas: el bloqueo exige además la LISTA DEL DÍA de cada
            // día marcable (30/09/2026, migración 070). Sin esto el panel diría
            // «Listo para bloquear» y el bloqueo se negaría.
            $perAsis  = $asistencia->periodo($pid);
            $jornadas = ($perAsis && (int) $perAsis['asistencia_por_fechas'] === 1) ? new AsistenciaJornadaModel() : null;
            // Y no se bloquea antes del ÚLTIMO día del bimestre (09/10/2026), la
            // misma regla de `AsistenciaModel::bloquearRA`.
            $asisDesde = ($jornadas && !AsistenciaModel::bimestreTerminado($perAsis))
                ? AsistenciaModel::ddmm((string) $perAsis['fecha_fin'])
                : null;
            $todas    = array_values(array_filter(
                $conducta->listarSeccionesActivas(),
                static fn($s) => in_array((int) $s['id'], $mias, true)
            ));
            // Matriculados de la NÓMINA (documento: solo 'aprobada'), del mismo
            // modelo que la imprime, para que la card y el documento cuadren. No
            // es el roster de evaluación de los KPIs: ahí también van los
            // 'pendiente', que se registran pero aún no figuran en la nómina.
            // Los niveles salen de las propias secciones: nunca ids a mano.
            $nomina   = array_column(
                (new NominaModel())->resumenPorSeccion(array_unique(array_column($todas, 'nivel_id'))),
                'n', 'seccion_id'
            );

            foreach ($todas as $s) {
                $sid = (int) $s['id'];
                $c = $progC[$sid] ?? ['esperados' => 0, 'calificados' => 0];
                $a = $progA[$sid] ?? ['esperados' => 0, 'registrados' => 0];

                $secciones[] = [
                    'id'           => $sid,
                    'etiqueta'     => trim($s['grado_nombre'] . ' ' . $s['seccion_nombre']),
                    'nivel'        => $s['nivel_nombre'],
                    'estudiantes'  => $a['esperados'],
                    'matriculados' => (int) ($nomina[$sid] ?? 0),
                    'conducta'     => self::estado(
                        $conducta->getCierreVigente($sid, $pid), $editable,
                        $c['calificados'], $c['esperados'], true
                    ),
                    // Desde el 29/09/2026 la asistencia también exige confirmar a
                    // todos antes de bloquear: tiene su «Listo para bloquear».
                    'asistencia'   => self::estado(
                        $asistencia->getCierreVigente($sid, $pid), $editable,
                        $a['registrados'], $a['esperados'], true,
                        $jornadas ? count($jornadas->diasSinTomar($sid, $perAsis)) : 0,
                        $asisDesde
                    ),
                ];
            }
        }

        // Días para el cierre: la fecha límite que corta la edición de conducta y
        // asistencia. Punto único `EdicionPeriodoModel` (09/10/2026).
        $diasCierre = $periodo !== null
            ? EdicionPeriodoModel::diasParaCierre(EdicionPeriodoModel::limiteDe($periodo, EdicionPeriodoModel::ROL_AUXILIAR))
            : null;

        $this->view('auxiliar/inicio', [
            'titulo'         => 'Inicio',
            'periodo'        => $periodo,
            'secciones'      => $secciones,
            'estudiantes'    => array_sum(array_column($secciones, 'estudiantes')),
            // Avance = % de secciones BLOQUEADAS (08/10/2026, decisión del usuario):
            // mismo cálculo que «Avance del bimestre» del panel docente.
            'avConducta'     => self::avance($secciones, 'conducta'),
            'avAsistencia'   => self::avance($secciones, 'asistencia'),
            'diasCierre'     => $diasCierre,
        ]);
    }

    // GET /auxiliar/nomina/{seccion_id}/imprimir
    // La MISMA nómina A4 del docente (`docente/nomina-imprimir.php`, decisión
    // D5), con los datos de `NominaModel`, pero solo de una sección a su cargo.
    public function nominaImprimir(string $seccionId): void
    {
        $seccionId = (int) $seccionId;
        $this->exigirSeccionPropia($seccionId);

        $nomina  = new NominaModel();
        $seccion = $nomina->seccion($seccionId);
        if (!$seccion) {
            $this->notFound();
        }

        $anio = (new AuxiliarSeccionModel())->periodoActivo();
        $anio = $anio ? ['id' => (int) $anio['anio_id'], 'anio' => $anio['anio']] : null;

        View::setLayout('print');
        $this->view('docente/nomina-imprimir', [
            'titulo'      => 'Nómina ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'     => $seccion,
            'alumnos'     => $nomina->matriculados([(int) $seccion['nivel_id']], $seccionId),
            'directorEbr' => $anio ? (new DirectorEbrModel())->getVigenteEnFecha($anio['id']) : null,
            'anio'        => $anio,
        ]);
    }

    // GET /auxiliar/horario/{seccion_id}
    // El MISMO documento que ve Dirección (`director/horario-seccion.php`,
    // decisión D16), armado por `HorarioModel::documentoSeccion`.
    public function horario(string $seccionId): void
    {
        $seccionId = (int) $seccionId;
        $this->exigirSeccionPropia($seccionId);

        $datos = (new HorarioModel())->documentoSeccion($seccionId);
        if ($datos === null) {
            $this->notFound();
        }

        View::setLayout('print');
        $this->view('director/horario-seccion', array_merge($datos, [
            'titulo' => 'Horario — ' . $datos['seccion']['grado_nombre'] . ' ' . $datos['seccion']['seccion_nombre'],
        ]));
    }

    // GET /auxiliar/justificaciones   (?periodo={id})
    // Reporte de JUSTIFICACIONES de TODAS las secciones a su cargo (13/10/2026):
    // resumen por sección + tablas consolidadas. Los datos los arma
    // `componerReporte()`, el mismo que usa el A4.
    public function justificaciones(): void
    {
        $this->view('auxiliar/justificaciones', array_merge($this->componerReporte(), [
            'titulo' => 'Faltas y justificaciones — Mis secciones',
        ]));
    }

    // GET /auxiliar/justificaciones/imprimir   (?periodo={id})
    // A4 vertical del reporte (13/10/2026), con la estructura del imprimible de
    // Acompañamiento pedagógico: cabecera institucional con bimestre, alcance y
    // fecha de impresión, y los MISMOS parciales que la pantalla. Documento de
    // TRABAJO, sin firma (decisión del usuario): el registro oficial firmado es
    // el imprimible de asistencia de cada sección.
    public function justificacionesImprimir(): void
    {
        $datos = $this->componerReporte();
        // Sin bimestre suyo, o uno de solo números, no hay nada que imprimir.
        if ($datos['reporte'] === null) {
            $this->notFound();
        }

        $u = Session::user() ?? [];
        View::setLayout('print');
        $this->view('auxiliar/justificaciones-imprimir', array_merge($datos, [
            'titulo'   => 'Faltas y justificaciones — ' . $datos['periodoVer']['nombre_display'],
            'auxiliar' => trim(($u['apellido_paterno'] ?? '') . ' ' . ($u['apellido_materno'] ?? '') . ', ' . ($u['nombres'] ?? ''), ' ,'),
        ]));
    }

    /**
     * Datos del reporte de justificaciones del auxiliar. PUNTO ÚNICO de la
     * pantalla y su A4 (mismo motivo que `componerRiesgo()` en los cuadros: si
     * cada uno armara los suyos, el papel podría decir otra cifra).
     *
     * Cada sección se lee igual que su vista «Faltas y justificaciones»: borrador
     * si está en curso, lo OFICIAL si el bimestre ya no es editable o la sección
     * está bloqueada. La suma la hace `AsistenciaModel::consolidarJustificaciones`;
     * aquí no hay reglas.
     *
     * @return array{periodoVer:?array, periodosNav:array, porFechas:bool,
     *               reporte:?array, motivoPrincipal:?string}
     */
    private function componerReporte(): array
    {
        $asistencia = new AsistenciaModel();
        $aux        = new AuxiliarSeccionModel();
        $usuarioId  = (int) Session::user()['id'];

        // Bimestres con secciones suyas (los 'pendiente' no tienen datos).
        $periodosNav = [];
        foreach ($asistencia->listarPeriodosActivos() as $p) {
            if ($p['estado'] !== 'pendiente' && $aux->seccionesDe($usuarioId, (int) $p['id']) !== []) {
                $periodosNav[] = $p;
            }
        }

        // El pedido por ?periodo= (si es uno de los suyos); si no, el activo.
        $periodoVer   = null;
        $periodoParam = (int) ($this->query('periodo') ?? 0);
        foreach ($periodosNav as $p) {
            if ($periodoParam ? (int) $p['id'] === $periodoParam : $p['estado'] === 'activo') {
                $periodoVer = $p;
                break;
            }
        }

        $porFechas = $periodoVer !== null && (int) ($periodoVer['asistencia_por_fechas'] ?? 0) === 1;
        $reporte   = null;
        $motivoPrincipal = null;
        if ($porFechas) {
            $pid      = (int) $periodoVer['id'];
            $editable = (bool) $periodoVer['editable'];
            $mias     = $aux->seccionesDe($usuarioId, $pid);
            $motivosM = new AsistenciaMotivoModel();
            $ancla    = $motivosM->anclaDelPeriodo($pid);
            $motivoPrincipal = $motivosM->nombreDe($ancla);
            // Días lectivos del bimestre ENTERO: unen en tramos las fechas seguidas.
            $lectivos = AsistenciaModel::diasMarcables($periodoVer, (string) $periodoVer['fecha_fin'],
                (new AsistenciaJornadaModel())->noLectivos($periodoVer));

            $porSeccion = [];
            foreach ($asistencia->listarSeccionesActivas() as $s) {
                $sid = (int) $s['id'];
                if (!in_array($sid, $mias, true)) {
                    continue;
                }
                $cierre  = $asistencia->getCierreVigente($sid, $pid);
                $oficial = !$editable || $cierre !== null;
                $est     = $asistencia->getEstudiantesConIncidencias($sid, $pid, $oficial);
                $porSeccion[] = [
                    // «1° A (P)»: el grado y la sección se repiten entre niveles. Misma
                    // convención (P)/(S) que los cuadros estadísticos. `bloqueado_en`
                    // alimenta la columna «Registro» del A4.
                    'seccion' => ['id' => $sid, 'etiqueta' => trim($s['grado_nombre'] . ' ' . $s['seccion_nombre'])
                                  . ' (' . mb_strtoupper(mb_substr((string) $s['nivel_nombre'], 0, 1)) . ')',
                                  'bloqueado_en' => $cierre['ra_bloqueado_en'] ?? null],
                    'oficial' => $oficial,
                    'resumen' => AsistenciaModel::resumenJustificaciones(
                        $est,
                        $asistencia->incidenciasDe(array_column($est, 'matricula_id'), $pid, $oficial),
                        $ancla,
                        $lectivos
                    ),
                ];
            }
            $reporte = AsistenciaModel::consolidarJustificaciones($porSeccion);
        }

        return [
            'periodoVer'      => $periodoVer,
            'periodosNav'     => $periodosNav,
            'porFechas'       => $porFechas,
            'reporte'         => $reporte,
            'motivoPrincipal' => $motivoPrincipal,
        ];
    }

    /**
     * Corta con 403 si la sección no está a cargo del auxiliar en el bimestre
     * EN CURSO. Documentos del día a día: no hay historial por bimestre.
     * El permiso sale del punto único `AuxiliarSeccionModel::puedeRegistrar()`.
     */
    private function exigirSeccionPropia(int $seccionId): void
    {
        $aux     = new AuxiliarSeccionModel();
        $periodo = $aux->periodoActivo();
        if ($periodo === null || !$aux->puedeRegistrar(Session::user() ?? [], $seccionId, (int) $periodo['id'])) {
            $this->forbidden();
        }
    }

    /**
     * AVANCE de un módulo en el panel: % de las secciones a cargo con ese módulo
     * BLOQUEADO (decisión del usuario, 08/10/2026; antes se mostraba la fracción
     * «1/2»). Mismo redondeo que «Avance del bimestre» del panel docente
     * (`Docente\PanelController`). Sin secciones, 0.
     */
    private static function avance(array $secciones, string $modulo): int
    {
        $n = count($secciones);
        if ($n === 0) {
            return 0;
        }
        $bloqueadas = count(array_filter($secciones, static fn($s) => $s[$modulo]['bloqueada']));
        return (int) round($bloqueadas / $n * 100);
    }

    /**
     * Estado de una sección para el semáforo del panel (regla del panel docente,
     * docs/modulos/ui.md: el color dice DE QUIÉN depende la acción).
     *   - verde  = bloqueada: ya no le pide nada al auxiliar;
     *   - gris   = no puede actuar (fuera de plazo, o sección sin estudiantes);
     *   - ámbar  = le toca: «Confirmados X de N», o «Listo para bloquear» cuando
     *              todos están confirmados (29/09/2026: conducta y asistencia
     *              cuentan solo lo CONFIRMADO; un borrador no avanza el contador).
     *
     *   - asistencia por fechas (30/09/2026): con todos confirmados pero días sin
     *     lista tomada, «Días sin tomar: N» en lugar de «Listo para bloquear».
     *   - asistencia por fechas antes del último día del bimestre (09/10/2026):
     *     gris «Bloqueo desde el dd/mm». Antes decía «Listo para bloquear» y el
     *     bloqueo se negaba.
     *
     * @return array{bloqueada:bool, badge:string, texto:string}
     */
    private static function estado(?array $cierre, bool $editable, int $hechos, int $esperados, bool $conCompletitud, int $sinTomar = 0, ?string $bloqueoDesde = null): array
    {
        if ($cierre !== null) {
            return ['bloqueada' => true, 'badge' => 'activo',
                    'texto' => 'Bloqueada el ' . fechaLima($cierre['ra_bloqueado_en'], 'd/m/Y')];
        }
        if (!$editable) {
            return ['bloqueada' => false, 'badge' => 'espera', 'texto' => 'Fuera de plazo'];
        }
        if ($esperados === 0) {
            return ['bloqueada' => false, 'badge' => 'espera', 'texto' => 'Sin estudiantes'];
        }
        if ($conCompletitud && $hechos >= $esperados && $sinTomar > 0) {
            return ['bloqueada' => false, 'badge' => 'warning', 'texto' => "Días sin tomar: {$sinTomar}"];
        }
        if ($conCompletitud && $hechos >= $esperados && $bloqueoDesde !== null) {
            return ['bloqueada' => false, 'badge' => 'espera', 'texto' => "Bloqueo desde el {$bloqueoDesde}"];
        }
        if ($conCompletitud && $hechos >= $esperados) {
            return ['bloqueada' => false, 'badge' => 'warning', 'texto' => 'Listo para bloquear'];
        }
        return ['bloqueada' => false, 'badge' => 'warning', 'texto' => "Confirmados {$hechos} de {$esperados}"];
    }
}
