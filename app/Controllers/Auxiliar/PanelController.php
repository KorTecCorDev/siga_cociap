<?php

namespace App\Controllers\Auxiliar;

use App\Controllers\BaseController;
use App\Models\AsistenciaJornadaModel;
use App\Models\AsistenciaModel;
use App\Models\AuxiliarSeccionModel;
use App\Models\ConductaModel;
use App\Models\DirectorEbrModel;
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
                        $jornadas ? count($jornadas->diasSinTomar($sid, $perAsis)) : 0
                    ),
                ];
            }
        }

        // Días para el cierre: la misma fecha límite que corta la edición de
        // conducta y asistencia (`periodoEditable`), igual que en el panel docente.
        $diasCierre = null;
        if ($periodo !== null && !empty($periodo['limite_notas'])) {
            $diasCierre = (int) ceil((strtotime($periodo['limite_notas']) - time()) / 86400);
        }

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
     *
     * @return array{bloqueada:bool, badge:string, texto:string}
     */
    private static function estado(?array $cierre, bool $editable, int $hechos, int $esperados, bool $conCompletitud, int $sinTomar = 0): array
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
        if ($conCompletitud && $hechos >= $esperados) {
            return ['bloqueada' => false, 'badge' => 'warning', 'texto' => 'Listo para bloquear'];
        }
        return ['bloqueada' => false, 'badge' => 'warning', 'texto' => "Confirmados {$hechos} de {$esperados}"];
    }
}
