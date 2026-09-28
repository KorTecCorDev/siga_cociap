<?php

namespace App\Controllers\Auxiliar;

use App\Controllers\BaseController;
use App\Models\AsistenciaModel;
use App\Models\AuxiliarSeccionModel;
use App\Models\ConductaModel;
use Core\Session;

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

            foreach ($conducta->listarSeccionesActivas() as $s) {
                $sid = (int) $s['id'];
                if (!in_array($sid, $mias, true)) {
                    continue;
                }
                $c = $progC[$sid] ?? ['esperados' => 0, 'calificados' => 0];
                $a = $progA[$sid] ?? ['esperados' => 0, 'registrados' => 0];

                $secciones[] = [
                    'id'         => $sid,
                    'etiqueta'   => trim($s['grado_nombre'] . ' ' . $s['seccion_nombre']),
                    'nivel'      => $s['nivel_nombre'],
                    'estudiantes' => $a['esperados'],
                    'conducta'   => self::estado(
                        $conducta->getCierreVigente($sid, $pid), $editable,
                        $c['calificados'], $c['esperados'], true
                    ),
                    'asistencia' => self::estado(
                        $asistencia->getCierreVigente($sid, $pid), $editable,
                        $a['registrados'], $a['esperados'], false
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
            'bloqConducta'   => count(array_filter($secciones, static fn($s) => $s['conducta']['bloqueada'])),
            'bloqAsistencia' => count(array_filter($secciones, static fn($s) => $s['asistencia']['bloqueada'])),
            'diasCierre'     => $diasCierre,
        ]);
    }

    /**
     * Estado de una sección para el semáforo del panel (regla del panel docente,
     * docs/modulos/ui.md: el color dice DE QUIÉN depende la acción).
     *   - verde  = bloqueada: ya no le pide nada al auxiliar;
     *   - gris   = no puede actuar (fuera de plazo, o sección sin estudiantes);
     *   - ámbar  = le toca: «Registrados X de N», o «Listo para bloquear» cuando
     *              la conducta está completa. En asistencia no existe ese paso:
     *              un estudiante sin registro cuenta como 0 incidencias.
     *
     * @return array{bloqueada:bool, badge:string, texto:string}
     */
    private static function estado(?array $cierre, bool $editable, int $hechos, int $esperados, bool $conCompletitud): array
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
        if ($conCompletitud && $hechos >= $esperados) {
            return ['bloqueada' => false, 'badge' => 'warning', 'texto' => 'Listo para bloquear'];
        }
        return ['bloqueada' => false, 'badge' => 'warning', 'texto' => "Registrados {$hechos} de {$esperados}"];
    }
}
