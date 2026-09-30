<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AuxiliarSeccionModel;
use Core\Session;

/**
 * Asignación de secciones a los auxiliares académicos (28/09/2026).
 * Ver docs/modulos/auxiliares.md.
 *
 * Asignan admin y Registro Académico. Solo se asigna el bimestre ACTIVO y la
 * asignación se hereda sola a los siguientes; los bimestres cerrados quedan
 * fijos (decisiones del usuario). Las reglas viven en AuxiliarSeccionModel.
 */
class AuxiliarController extends BaseController
{
    private const ROLES_ASIGNAN = ['admin', 'registro_academico'];

    private AuxiliarSeccionModel $model;

    public function __construct()
    {
        $this->requireRole(self::ROLES_ASIGNAN);
        $this->model = new AuxiliarSeccionModel();
    }

    // GET /admin/auxiliares
    public function index(): void
    {
        $periodo = $this->model->periodoActivo();

        // Auxiliar => secciones donde tiene un hijo/a: se permite asignarlas,
        // pero se avisa (en la fila y al elegirlo en el modal).
        $conHijos = $periodo ? $this->model->seccionesConHijos((int) $periodo['anio_id']) : [];

        $auxiliares = $this->model->listarAuxiliares();
        $auxiliaresJson = array_map(fn($a) => [
            'id'            => (int) $a['id'],
            'nombre'        => mb_strtoupper($a['apellido_paterno'] . ' ' . $a['apellido_materno'])
                               . ', ' . $a['nombres'],
            'dni'           => $a['dni'],
            'seccionesHijo' => $conHijos[(int) $a['id']] ?? [],
        ], $auxiliares);

        $this->view('admin/auxiliares/index', [
            'titulo'         => 'Auxiliares y secciones',
            'periodo'        => $periodo,
            'secciones'      => $periodo ? $this->model->listarParaPeriodo((int) $periodo['id']) : [],
            'conHijos'       => $conHijos,
            'auxiliaresJson' => $auxiliaresJson,
            'page_scripts'   => ['auxiliares'],
        ]);
    }

    // POST /admin/auxiliares/{seccion_id}/asignar  (AJAX)
    public function asignar(string $seccionId): void
    {
        $this->validateCsrf();

        $raw        = $this->input('auxiliar_id');
        $auxiliarId = ($raw === '' || $raw === null) ? null : (int) $raw;

        try {
            $this->model->asignar((int) $seccionId, $auxiliarId, (int) Session::user()['id']);
            $this->json([
                'success' => true,
                'mensaje' => $auxiliarId ? 'Auxiliar asignado correctamente.' : 'Auxiliar retirado de la sección.',
            ]);
        } catch (\DomainException $e) {
            $this->json(['success' => false, 'mensaje' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[AuxiliarController::asignar] ' . $e->getMessage());
            $this->json(['success' => false, 'mensaje' => 'No se pudo guardar la asignación. Intenta de nuevo.'], 500);
        }
    }
}
