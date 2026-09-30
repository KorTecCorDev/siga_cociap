<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CriterioConductaModel;
use Core\Session;

/**
 * Gestión de los CRITERIOS DE CONDUCTA de cada año (F6 del módulo auxiliares,
 * 28/09/2026, migración 067). Ver docs/modulos/auxiliares.md §7 F6.
 *
 * Guardas POR MÉTODO: admin y RA editan; Dirección solo ve (sus tres roles son
 * de SOLO LECTURA, invariante de CLAUDE.md). Las reglas de qué se puede editar
 * en cada año viven en `CriterioConductaModel`, no aquí.
 */
class CriterioConductaController extends BaseController
{
    private const ROLES_VEN    = ['admin', 'registro_academico', ...ROLES_DIRECCION];
    private const ROLES_EDITAN = ['admin', 'registro_academico'];

    private CriterioConductaModel $model;

    public function __construct()
    {
        $this->model = new CriterioConductaModel();
    }

    // GET /admin/conducta/criterios[?anio=ID]
    public function index(): void
    {
        $this->requireRole(self::ROLES_VEN);

        $anios = $this->model->anios();
        if ($anios === []) {
            $this->notFound();
        }
        // Año pedido, o el activo, o el más reciente.
        $pedido = (int) $this->query('anio', 0);
        $anio   = null;
        foreach ($anios as $a) {
            if ((int) $a['id'] === $pedido) { $anio = $a; break; }
        }
        if ($anio === null) {
            $activos = array_values(array_filter($anios, static fn($a) => $a['estado'] === 'activo'));
            $anio    = $activos[0] ?? $anios[0];
        }
        $anioId = (int) $anio['id'];

        $criterios = $this->model->listar($anioId);
        $this->view('admin/conducta/criterios', [
            'titulo'       => 'Criterios de conducta',
            'anios'        => $anios,
            'anio'         => $anio,
            'criterios'    => $criterios,
            // Dirección y un año cerrado ven en solo lectura.
            'modo'         => has_role(self::ROLES_EDITAN) ? $this->model->modoEdicion($anioId) : 'lectura',
            'anterior'     => $criterios === [] ? $this->model->anioAnteriorConCriterios($anioId) : null,
            'niveles'      => $this->model->niveles(),
            'editarId'     => (int) $this->query('editar', 0),
            'page_scripts' => ['criterios-conducta'],
        ]);
    }

    // POST /admin/conducta/criterios/crear
    public function crear(): void
    {
        $anioId = $this->antesDeEscribir();
        $this->intentar($anioId, fn() => $this->model->crear(
            $anioId, (string) $this->input('texto', ''), $this->nivel()
        ), 'Criterio agregado.');
    }

    // POST /admin/conducta/criterios/{id}/editar
    public function actualizar(string $id): void
    {
        $anioId = $this->antesDeEscribir();
        $this->intentar($anioId, fn() => $this->model->actualizar(
            (int) $id, (string) $this->input('texto', ''), $this->nivel(), (int) Session::user()['id']
        ), 'Criterio actualizado.');
    }

    // POST /admin/conducta/criterios/{id}/retirar
    public function retirar(string $id): void
    {
        $anioId = $this->antesDeEscribir();
        $this->intentar($anioId, fn() => $this->model->retirar((int) $id, (int) Session::user()['id']),
            'Criterio retirado.');
    }

    // POST /admin/conducta/criterios/{id}/mover   (direccion=arriba|abajo)
    public function mover(string $id): void
    {
        $anioId = $this->antesDeEscribir();
        $this->intentar($anioId, fn() => $this->model->mover((int) $id, $this->input('direccion') === 'arriba'),
            'Orden actualizado.');
    }

    // POST /admin/conducta/criterios/copiar
    public function copiar(): void
    {
        $anioId = $this->antesDeEscribir();
        $n = 0;
        $this->intentar($anioId, function () use ($anioId, &$n) {
            $n = $this->model->copiarDelAnterior($anioId);
        }, null);
        $this->redirectWithSuccess($this->volver($anioId), "Se copiaron {$n} criterios del año anterior. Revísalos y ajústalos si hace falta.");
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /** Guarda de escritura común: rol, CSRF y el año del formulario. */
    private function antesDeEscribir(): int
    {
        $this->requireRole(self::ROLES_EDITAN);
        $this->validateCsrf();
        return (int) $this->input('anio_id', 0);
    }

    /** Ejecuta la acción; una regla rota vuelve a la pantalla con su mensaje. */
    private function intentar(int $anioId, callable $accion, ?string $exito): void
    {
        try {
            $accion();
        } catch (\DomainException $e) {
            $this->redirectWithError($this->volver($anioId), $e->getMessage());
        }
        if ($exito !== null) {
            $this->redirectWithSuccess($this->volver($anioId), $exito);
        }
    }

    /** «Ambos niveles» llega vacío y se guarda NULL. */
    private function nivel(): ?int
    {
        $n = (int) $this->input('nivel_id', 0);
        return $n > 0 ? $n : null;
    }

    private function volver(int $anioId): string
    {
        return url('admin/conducta/criterios' . ($anioId > 0 ? '?anio=' . $anioId : ''));
    }
}
