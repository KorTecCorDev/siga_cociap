<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AsistenciaJornadaModel;
use Core\Session;

/**
 * DÍAS NO LECTIVOS de la asistencia (30/09/2026, migración 070): feriados y
 * suspensiones de TODO el colegio. Un día no lectivo no se marca ni se exige al
 * bloquear. Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Mismo reparto que los motivos (`AsistenciaMotivoController`): guardas POR
 * MÉTODO; admin y RA editan, Dirección solo ve. Las reglas (fin de semana, día
 * con incidencias, repetido) viven en `AsistenciaJornadaModel`.
 */
class AsistenciaCalendarioController extends BaseController
{
    private const ROLES_VEN    = ['admin', 'registro_academico', ...ROLES_DIRECCION];
    private const ROLES_EDITAN = ['admin', 'registro_academico'];

    private AsistenciaJornadaModel $model;

    public function __construct()
    {
        $this->model = new AsistenciaJornadaModel();
    }

    // GET /admin/asistencia/no-lectivos
    public function index(): void
    {
        $this->requireRole(self::ROLES_VEN);

        $this->view('admin/asistencia/no-lectivos', [
            'titulo'       => 'Días no lectivos',
            'dias'         => $this->model->listarNoLectivos(),
            'editor'       => has_role(self::ROLES_EDITAN),
            // Reusa el JS de confirmación de los criterios de conducta ([data-confirm]).
            'page_scripts' => ['criterios-conducta'],
        ]);
    }

    // POST /admin/asistencia/no-lectivos/crear   (fecha, motivo)
    public function crear(): void
    {
        $uid = $this->antesDeEscribir();
        $this->responder($this->model->declararNoLectivo(
            trim((string) $this->input('fecha', '')),
            (string) $this->input('motivo', ''),
            $uid
        ));
    }

    // POST /admin/asistencia/no-lectivos/{fecha}/quitar
    public function quitar(string $fecha): void
    {
        $this->antesDeEscribir();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->redirectWithError(url('admin/asistencia/no-lectivos'), 'Fecha no válida.');
        }
        $this->responder($this->model->quitarNoLectivo($fecha));
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /** Guarda de escritura común: rol y CSRF. Devuelve el usuario. */
    private function antesDeEscribir(): int
    {
        $this->requireRole(self::ROLES_EDITAN);
        $this->validateCsrf();
        return (int) Session::user()['id'];
    }

    /** @param array{ok:bool, mensaje:string} $res */
    private function responder(array $res): void
    {
        $volver = url('admin/asistencia/no-lectivos');
        if ($res['ok']) {
            $this->redirectWithSuccess($volver, $res['mensaje']);
        }
        $this->redirectWithError($volver, $res['mensaje']);
    }
}
