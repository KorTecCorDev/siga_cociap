<?php

namespace App\Controllers\Matricula;

use App\Controllers\BaseController;
use App\Models\CambioSeccionModel;
use App\Models\MatriculaModel;
use Core\Session;

/**
 * CambioSeccionController — cambio de sección y su reversión (07/10/2026).
 * Reglas y decisiones: docs/modulos/cambio-seccion.md. Toda la lógica vive en
 * CambioSeccionModel (punto único); aquí solo entrada, permisos y avisos.
 *
 *   POST /matriculas/{id}/cambiar-seccion            → ejecutar
 *   POST /matriculas/{id}/cambiar-seccion/revertir   → revertir el último vigente
 *
 * El formulario vive en el detalle de la matrícula (card «Gestión»), como
 * Desactivar y Exonerar: no hay GET propio.
 */
class CambioSeccionController extends BaseController
{
    private MatriculaModel $matriculas;
    private CambioSeccionModel $cambios;

    public function __construct()
    {
        // Escritura: solo admin y registro académico (dirección es solo lectura).
        $this->requireRole(['admin', 'registro_academico']);
        $this->matriculas = new MatriculaModel();
        $this->cambios    = new CambioSeccionModel();
    }

    // ── POST /matriculas/{id}/cambiar-seccion ────────────────────
    public function store(string $matriculaId): void
    {
        $this->validateCsrf();
        $id = $this->requireMatricula((int) $matriculaId);
        $usuarioId = (int) (Session::user()['id'] ?? 0);

        $correlativo = trim((string) $this->input('rd_correlativo'));
        $fecha       = trim((string) $this->input('rd_fecha'));

        try {
            $res = $this->cambios->ejecutar(
                $id,
                (int) $this->input('seccion_destino_id'),
                $correlativo === '' ? null : (int) $correlativo,
                $fecha === '' ? null : $fecha,
                (string) $this->input('motivo'),
                $usuarioId
            );
        } catch (\InvalidArgumentException $e) {
            $this->redirectWithError(url('matriculas/' . $id), $e->getMessage());
        } catch (\Throwable $e) {
            log_error('Error al cambiar de sección', ['matricula' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo registrar el cambio de sección.');
        }

        // Avisos FUERA de la transacción: que falle uno no tumba el cambio.
        try {
            $this->cambios->avisar($res['cambio_id'], 'cambio', $usuarioId);
        } catch (\Throwable $e) {
            log_error('No se pudo avisar del cambio de sección', [
                'cambio' => $res['cambio_id'], 'error' => $e->getMessage(),
            ]);
        }

        $archivadas = $res['competencias'] + $res['criterios'] + $res['omisiones'];
        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Cambio de sección registrado desde el ' . $res['periodo']
            . ($res['rd_numero'] !== null ? ' (R.D. ' . $res['rd_numero'] . ')' : '') . '.'
            . ($archivadas > 0
                ? ' Las calificaciones que tenía en el ' . $res['periodo'] . ' quedaron archivadas.'
                : ''));
    }

    // ── POST /matriculas/{id}/cambiar-seccion/revertir ───────────
    public function revertir(string $matriculaId): void
    {
        $this->validateCsrf();
        $id = $this->requireMatricula((int) $matriculaId);
        $usuarioId = (int) (Session::user()['id'] ?? 0);

        $ultimo = $this->cambios->ultimoVigente($id);
        if (!$ultimo) {
            $this->redirectWithError(url('matriculas/' . $id), 'No hay un cambio de sección que revertir.');
        }

        try {
            $res = $this->cambios->revertir((int) $ultimo['id'], (string) $this->input('motivo'), $usuarioId);
        } catch (\InvalidArgumentException $e) {
            $this->redirectWithError(url('matriculas/' . $id), $e->getMessage());
        } catch (\Throwable $e) {
            log_error('Error al revertir el cambio de sección', ['matricula' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo revertir el cambio de sección.');
        }

        try {
            $this->cambios->avisar((int) $ultimo['id'], 'reversion', $usuarioId);
        } catch (\Throwable $e) {
            log_error('No se pudo avisar de la reversión', [
                'cambio' => (int) $ultimo['id'], 'error' => $e->getMessage(),
            ]);
        }

        $n = count($res['desconfirmados']);
        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Cambio de sección revertido: el estudiante vuelve a su sección anterior.'
            . ($n > 0
                ? ' ' . $n . ($n === 1 ? ' criterio quedó' : ' criterios quedaron')
                  . ' sin confirmar; sus docentes deben volver a confirmarlos.'
                : '')
            . ($res['omitidas'] > 0
                ? ' ' . $res['omitidas'] . ' nota(s) no se restauraron porque su criterio ya no existe.'
                : ''));
    }

    private function requireMatricula(int $id): int
    {
        if (!$this->matriculas->findById($id)) {
            $this->notFound();
        }
        return $id;
    }
}
