<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AsistenciaMotivoModel;
use Core\Session;

/**
 * Gestión del CATÁLOGO de motivos de justificación de la asistencia
 * (29/09/2026, migración 069). Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Mismo reparto que los criterios de conducta (`CriterioConductaController`):
 * guardas POR MÉTODO; admin y RA editan, Dirección solo ve (sus tres roles son
 * de SOLO LECTURA). Las reglas (nombre, código estable, retirar en vez de
 * borrar, nunca retirar el último) viven en `AsistenciaMotivoModel`.
 */
class AsistenciaMotivoController extends BaseController
{
    private const ROLES_VEN    = ['admin', 'registro_academico', ...ROLES_DIRECCION];
    private const ROLES_EDITAN = ['admin', 'registro_academico'];

    private AsistenciaMotivoModel $model;

    public function __construct()
    {
        $this->model = new AsistenciaMotivoModel();
    }

    // GET /admin/asistencia/motivos
    public function index(): void
    {
        $this->requireRole(self::ROLES_VEN);

        $this->view('admin/asistencia/motivos', [
            'titulo'       => 'Motivos de justificación',
            'motivos'      => $this->model->listar(),
            'editor'       => has_role(self::ROLES_EDITAN),
            'editarId'     => (int) $this->query('editar', 0),
            // Reusa el JS de confirmación de los criterios de conducta ([data-confirm]).
            'page_scripts' => ['criterios-conducta'],
        ]);
    }

    // POST /admin/asistencia/motivos/crear
    public function crear(): void
    {
        $uid = $this->antesDeEscribir();
        $this->intentar(fn() => $this->model->crear((string) $this->input('nombre', ''), $uid), 'Motivo agregado.');
    }

    // POST /admin/asistencia/motivos/{id}/editar
    public function actualizar(string $id): void
    {
        $uid = $this->antesDeEscribir();
        $this->intentar(fn() => $this->model->renombrar((int) $id, (string) $this->input('nombre', ''), $uid),
            'Motivo actualizado.');
    }

    // POST /admin/asistencia/motivos/{id}/retirar
    public function retirar(string $id): void
    {
        $uid = $this->antesDeEscribir();
        $this->intentar(fn() => $this->model->retirar((int) $id, $uid), 'Motivo retirado.');
    }

    // POST /admin/asistencia/motivos/{id}/principal
    // Traslada el ANCLA (08/10/2026, migración 076): se recuentan las faltas de los
    // bimestres en curso; se niega con secciones ya bloqueadas.
    public function principal(string $id): void
    {
        $uid  = $this->antesDeEscribir();
        $filas = 0;
        $this->intentar(function () use ($id, $uid, &$filas): void {
            $filas = $this->model->hacerPrincipal((int) $id, $uid);
        }, fn(): string => 'Motivo principal actualizado. Se recontaron las faltas de '
            . $filas . ' registro(s) de asistencia del bimestre en curso.');
    }

    // POST /admin/asistencia/motivos/{id}/mover   (direccion=arriba|abajo)
    public function mover(string $id): void
    {
        $this->antesDeEscribir();
        $this->intentar(fn() => $this->model->mover((int) $id, $this->input('direccion') === 'arriba'),
            'Orden actualizado.');
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /** Guarda de escritura común: rol y CSRF. Devuelve el usuario. */
    private function antesDeEscribir(): int
    {
        $this->requireRole(self::ROLES_EDITAN);
        $this->validateCsrf();
        return (int) Session::user()['id'];
    }

    /**
     * Ejecuta la acción; una regla rota vuelve a la pantalla con su mensaje. El
     * mensaje de éxito puede ser un callable cuando depende del resultado.
     */
    private function intentar(callable $accion, string|callable $exito): void
    {
        $volver = url('admin/asistencia/motivos');
        try {
            $accion();
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }
        $this->redirectWithSuccess($volver, is_callable($exito) ? $exito() : $exito);
    }
}
