<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\RatificacionModel;
use App\Models\RatificacionMotivoModel;
use Core\Session;

/**
 * RATIFICACIÓN DE MATRÍCULA (migración 080, 14/10/2026) — decisiones por
 * sección y catálogo de motivos. Ver docs/modulos/ratificaciones.md.
 *
 * Acceso mixto, como `Admin\AsistenciaController`: el constructor admite el
 * superconjunto (`RatificacionModel::ROLES_VEN`, que incluye a Dirección en
 * SOLO LECTURA) y CADA método de escritura empieza con
 * `$this->requireRole(RatificacionModel::ROLES_REGISTRAN)`. Por eso no entra en
 * `verif_direccion_solo_lectura.php` (que exige `ROLES_ESCRIBEN` = admin + RA);
 * lo vigila `verif_ratificaciones.php`, que además falla si nace un método
 * público sin decidir su rol.
 *
 * Las reglas viven en los modelos; aquí solo se lee la petición y se responde.
 */
class RatificacionController extends BaseController
{
    private RatificacionModel $model;
    private RatificacionMotivoModel $motivos;

    public function __construct()
    {
        $this->requireRole(RatificacionModel::ROLES_VEN);
        $this->model   = new RatificacionModel();
        $this->motivos = new RatificacionMotivoModel();
    }

    // GET /admin/ratificaciones?anio_id=&seccion_id=
    public function index(): void
    {
        $anios = $this->model->listarAnios();
        $anio  = $this->anioElegido($anios);

        $seccion     = null;
        $estudiantes = [];
        if ($anio !== null && (int) $this->query('seccion_id', 0) > 0) {
            $seccion = $this->model->seccion((int) $this->query('seccion_id'), (int) $anio['id']);
            if ($seccion === null) {
                $this->notFound();
            }
            $estudiantes = $this->model->listarSeccion((int) $seccion['id']);
        }

        $this->view('admin/ratificaciones/index', [
            'titulo'        => 'Ratificación de matrícula',
            'anios'         => $anios,
            'anio'          => $anio,
            'secciones'     => $anio !== null ? $this->model->seccionesConContadores((int) $anio['id']) : [],
            'seccion'       => $seccion,
            'estudiantes'   => $estudiantes,
            'motivos'       => $anio !== null ? $this->motivos->vigentes((int) $anio['id']) : [],
            'puedeEscribir' => has_role(RatificacionModel::ROLES_REGISTRAN),
            'page_scripts'  => ['ratificacion'],
        ]);
    }

    // POST /admin/ratificaciones/{matricula_id}/decision
    public function guardarDecision(string $matriculaId): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $mat = $this->model->matriculaDelUniverso((int) $matriculaId);
        if ($mat === null) {
            $this->notFound();
        }
        // Con error se vuelve al inicio de la página, donde se pinta el mensaje;
        // al guardar, a la fila (su franja ya muestra el cambio).
        $volver = url('admin/ratificaciones?anio_id=' . (int) $mat['anio_id']
            . '&seccion_id=' . (int) $mat['seccion_id']);

        try {
            $cambio = $this->model->guardarDecision(
                (int) $mat['id'],
                (string) $this->input('decision', ''),
                (int) $this->input('motivo_id', 0) ?: null,
                $this->input('detalle') !== null ? (string) $this->input('detalle') : null,
                (int) Session::user()['id'],
                $this->input('confirmar_anulacion') === '1'
            );
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }

        $this->redirectWithSuccess($volver . '#m' . (int) $mat['id'], $cambio ? 'Decisión guardada.' : 'No había cambios que guardar.');
    }

    // GET /admin/ratificaciones/motivos?anio_id=
    public function motivos(): void
    {
        $anios = $this->model->listarAnios();
        $anio  = $this->anioElegido($anios);

        $this->view('admin/ratificaciones/motivos', [
            'titulo'        => 'Motivos de no ratificación',
            'anios'         => $anios,
            'anio'          => $anio,
            'motivos'       => $anio !== null ? $this->motivos->listar((int) $anio['id']) : [],
            'editarId'      => (int) $this->query('editar', 0),
            'puedeEscribir' => has_role(RatificacionModel::ROLES_REGISTRAN),
            'page_scripts'  => ['ratificacion'],
        ]);
    }

    // POST /admin/ratificaciones/motivos/crear
    public function crearMotivo(): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anio = $this->model->anio((int) $this->input('anio_id', 0));
        if ($anio === null) {
            $this->notFound();
        }
        $this->intentarMotivo((int) $anio['id'], fn() => $this->motivos->crear(
            (int) $anio['id'],
            (string) $this->input('nombre', ''),
            (string) $this->input('texto_oficial', '')
        ), 'Motivo agregado.');
    }

    // POST /admin/ratificaciones/motivos/{id}/editar
    public function actualizarMotivo(string $id): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anioId = $this->anioDelMotivoOFalla((int) $id);
        $this->intentarMotivo($anioId, fn() => $this->motivos->actualizar(
            (int) $id,
            (string) $this->input('nombre', ''),
            (string) $this->input('texto_oficial', '')
        ), 'Motivo actualizado.');
    }

    // POST /admin/ratificaciones/motivos/{id}/retirar
    public function retirarMotivo(string $id): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anioId = $this->anioDelMotivoOFalla((int) $id);
        $this->intentarMotivo($anioId, fn() => $this->motivos->retirar((int) $id), 'Motivo retirado.');
    }

    // POST /admin/ratificaciones/motivos/{id}/reactivar
    public function reactivarMotivo(string $id): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $anioId = $this->anioDelMotivoOFalla((int) $id);
        $this->intentarMotivo($anioId, fn() => $this->motivos->reactivar((int) $id), 'Motivo reactivado.');
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /**
     * Año pedido por `?anio_id=`, o el activo, o el más reciente. Null solo si
     * no hay ningún año registrado.
     */
    private function anioElegido(array $anios): ?array
    {
        $pedido = (int) $this->query('anio_id', 0);
        foreach ($anios as $a) {
            if ($pedido > 0 && (int) $a['id'] === $pedido) {
                return $a;
            }
        }
        if ($pedido > 0) {
            $this->notFound();
        }
        foreach ($anios as $a) {
            if ($a['estado'] === 'activo') {
                return $a;
            }
        }
        return $anios[0] ?? null;
    }

    private function anioDelMotivoOFalla(int $id): int
    {
        $anioId = $this->motivos->anioDe($id);
        if ($anioId === null) {
            $this->notFound();
        }
        return $anioId;
    }

    /** Ejecuta la acción; una regla rota vuelve a la pantalla con su mensaje. */
    private function intentarMotivo(int $anioId, callable $accion, string $exito): void
    {
        $volver = url('admin/ratificaciones/motivos?anio_id=' . $anioId);
        try {
            $accion();
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }
        $this->redirectWithSuccess($volver, $exito);
    }
}
