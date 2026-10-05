<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\EstudianteModel;
use App\Models\OrdenMeritoModel;
use App\Models\RetornoGradoModel;

class BuscadorEstudianteController extends BaseController
{
    private EstudianteModel  $model;
    private OrdenMeritoModel $ordenMeritoModel;

    public function __construct()
    {
        // El rol fósil 'secretaria' salió el 24/08/2026: no corresponde a ningún
        // `roles.codigo` real (los de la base son `secretaria_academica` y
        // `secretaria_administrativa`), así que nunca concedió acceso a nadie.
        // Mismo caso que el destino muerto retirado de AuthController el 22/08.
        $this->requireRole([
            'admin',
            'registro_academico',
            ...ROLES_DIRECCION,
        ]);
        $this->model            = new EstudianteModel();
        $this->ordenMeritoModel = new OrdenMeritoModel();
    }

    // GET /admin/buscar-estudiante
    public function index(): void
    {
        $anio = $this->model->anioActivo();

        $this->view('admin/buscar-estudiante/index', [
            'titulo'       => 'Buscador de Estudiantes',
            'anioActivo'   => $anio,
            'page_scripts' => ['buscador-estudiante'],
        ]);
    }

    // GET /admin/buscar-estudiante/api?q=...
    public function buscar(): void
    {
        $termino = trim((string) $this->query('q', ''));
        $anio    = $this->model->anioActivo();

        if (!$anio) {
            $this->json([
                'success' => false,
                'mensaje' => 'No hay un año académico activo configurado.',
            ]);
            return;
        }

        if (mb_strlen($termino) < 2) {
            $this->json([
                'success'     => true,
                'resultados'  => [],
                'anio'        => (int) $anio['anio'],
                'termino'     => $termino,
            ]);
            return;
        }

        // El puesto del orden de mérito corresponde al ULTIMO bimestre cerrado.
        // Si aún no hay ninguno cerrado (estamos en el I Bimestre), no hay orden
        // de mérito vigente y no se calcula puesto.
        $bimestre   = $this->model->ultimoBimestreCerrado((int) $anio['id']);
        $periodoId  = $bimestre ? (int) $bimestre['id'] : null;

        $filas = $this->model->buscarEnAnioActivo($termino, (int) $anio['id']);

        // Puesto del orden de mérito: misma fuente que el ranking oficial
        // (OrdenMeritoModel, con cascada de desempate y resolución manual).
        $puestos = [];
        if ($periodoId !== null && $filas) {
            // Incluye el grado de la PAREJA de un retorno: el puesto de la oficial
            // puede salir de ahí aunque la fila operativa no haya entrado al LIMIT.
            $gradoIds = array_filter(array_merge(
                array_map(static fn($f) => (int) ($f['grado_id'] ?? 0), $filas),
                array_map(static fn($f) => (int) ($f['retorno_grado_id'] ?? 0), $filas)
            ));
            if ($gradoIds) {
                $puestos = $this->ordenMeritoModel->puestosPorGrado($gradoIds, $periodoId);
            }
        }

        $retornos = new RetornoGradoModel();

        $resultados = array_map(function (array $f) use ($puestos, $retornos, $periodoId): array {
            $tutor = !empty($f['tutor_apellido_paterno'])
                ? mb_strtoupper($f['tutor_apellido_paterno']) . ' '
                . mb_strtoupper($f['tutor_apellido_materno']) . ', '
                . $f['tutor_nombres']
                : null;

            $puesto = $puestos[(int) $f['matricula_id']]['puesto'] ?? null;

            // Retorno de grado, ACTIVO o REVERTIDO (05/10/2026): la oficial es la
            // gestión y el documento. El puesto sale de la matrícula que CURSÓ el
            // último bimestre cerrado (tramo), que puede ser cualquiera de las dos.
            // El JS decide si agrupa (buscador) o separa (Rectificación).
            $retorno = null;
            if (($f['retorno_rol'] ?? null) === 'oficial') {
                $pareja = $f['retorno_grado_nombre'] . ' "' . $f['retorno_seccion_nombre'] . '"';
                $v      = $retornos->vinculo((int) $f['matricula_id']);
                $puestoMid = $periodoId !== null
                    ? $retornos->matriculaDelPeriodo((int) $f['matricula_id'], $periodoId)
                    : (int) $f['matricula_id'];
                $puestoR = $puestos[$puestoMid]['puesto'] ?? null;

                if ($f['retorno_estado'] === 'activo') {
                    $texto = 'Retorno de grado: cursa en ' . $pareja;
                } else {
                    $tramo = $v ? $retornos->nombresDelTramo($v) : null;
                    $texto = 'Retorno revertido'
                        . (!empty($v['fecha_reversion']) ? ' el ' . date('d/m/Y', strtotime($v['fecha_reversion'])) : '')
                        . ($tramo !== null
                            ? ' · cursó el ' . $tramo . ' en ' . $pareja
                            : ' · no completó ningún bimestre en ' . $pareja);
                }
                $retorno = [
                    'rol'       => 'oficial',
                    'estado'    => $f['retorno_estado'],
                    'texto'     => $texto,
                    'puesto'    => $puestoR !== null ? (int) $puestoR : null,
                    // null = el puesto es del grado de la propia fila.
                    'puesto_en' => $puestoMid !== (int) $f['matricula_id'] ? $pareja : null,
                ];
            } elseif (($f['retorno_rol'] ?? null) === 'operativa') {
                $retorno = [
                    'rol'        => 'operativa',
                    'estado'     => $f['retorno_estado'],
                    'oficial_id' => (int) $f['retorno_oficial'],
                ];
            }

            return [
                'matricula_id' => (int) $f['matricula_id'],
                'dni'      => $f['dni'],
                'nombre'   => mb_strtoupper($f['apellido_paterno']) . ' '
                            . mb_strtoupper($f['apellido_materno']) . ', '
                            . $f['nombres'],
                'sexo'     => $f['sexo'],
                'nivel'    => $f['nivel_nombre'],
                'grado'    => $f['grado_nombre'],
                'seccion'  => $f['seccion_nombre'],
                'estado'   => $f['matricula_estado'],
                'tutor'    => $tutor,
                'puesto'   => $puesto !== null ? (int) $puesto : null,
                'retorno'  => $retorno,
            ];
        }, $filas);

        $this->json([
            'success'          => true,
            'resultados'       => $resultados,
            'anio'             => (int) $anio['anio'],
            'bimestre'         => $bimestre['nombre_display'] ?? null,
            'tieneOrdenMerito' => $bimestre !== null,
            'termino'          => $termino,
        ]);
    }
}
