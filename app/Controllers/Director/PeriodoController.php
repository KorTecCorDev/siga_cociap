<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\AnioAcademicoModel;
use App\Models\ControlOperativoModel;
use App\Models\OrdenMeritoModel;
use App\Models\PublicacionBoletaModel;
use App\Models\TutorPeriodoModel;
use Core\Session;

/**
 * PeriodoController
 * Gestión de bimestres dentro de un año académico:
 * edición de fechas, apertura, cierre (con bloqueo automático e indicadores)
 * y reapertura excepcional.
 */
class PeriodoController extends BaseController
{
    /**
     * Quien ESCRIBE. Los directores entran a este controlador y VEN, pero desde
     * el 24/08/2026 no operan: su rol es de supervision en solo lectura. Se
     * valida en cada metodo de escritura (no ocultando el boton en la vista):
     * esconder la UI no es control de acceso.
     */
    private const ROLES_ESCRIBEN = ['admin', 'registro_academico'];

    private AnioAcademicoModel     $model;
    private PublicacionBoletaModel $publicacionModel;

    private const ROLES = ['admin', 'registro_academico', ...ROLES_DIRECCION];

    public function __construct()
    {
        $this->requireRole(self::ROLES);
        $this->model            = new AnioAcademicoModel();
        // Mismo PDO singleton -> sus escrituras entran en las transacciones de
        // cerrar()/reabrir() sin manejo extra.
        $this->publicacionModel = new PublicacionBoletaModel();
    }

    // POST /director/periodos/{id}/editar
    public function editar(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id      = (int) $id;
        $periodo = $this->model->getPeriodo($id);

        if (!$periodo) {
            $this->redirectWithError(url('director/anios'), 'Bimestre no encontrado.');
        }

        $volverUrl = url('director/anios/' . $periodo['anio_id']);

        $fechaInicio = trim((string) $this->input('fecha_inicio', ''));
        $fechaFin    = trim((string) $this->input('fecha_fin', ''));
        $limiteRaw   = trim((string) $this->input('limite_notas', ''));

        if ($fechaInicio === '' || $fechaFin === '') {
            $this->redirectWithError($volverUrl, 'La fecha de inicio y la de fin son obligatorias.');
        }
        if ($fechaFin <= $fechaInicio) {
            $this->redirectWithError($volverUrl, 'La fecha de fin debe ser posterior a la de inicio.');
        }

        // El input datetime-local llega como "Y-m-dTH:i"; lo normalizamos a datetime SQL.
        $limiteNotas = null;
        if ($limiteRaw !== '') {
            $limiteNotas = str_replace('T', ' ', $limiteRaw);
            if (strlen($limiteNotas) === 16) {
                $limiteNotas .= ':00';
            }
        }

        $this->model->actualizarFechasPeriodo($id, $fechaInicio, $fechaFin, $limiteNotas);

        $this->redirectWithSuccess($volverUrl, "Fechas del {$periodo['nombre_display']} actualizadas.");
    }

    // POST /director/periodos/{id}/abrir
    public function abrir(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id      = (int) $id;
        $periodo = $this->model->getPeriodo($id);

        if (!$periodo) {
            $this->redirectWithError(url('director/anios'), 'Bimestre no encontrado.');
        }

        $volverUrl = url('director/anios/' . $periodo['anio_id']);

        if ($periodo['estado'] !== 'pendiente') {
            $this->redirectWithError($volverUrl, 'Solo se puede abrir un bimestre pendiente.');
        }
        if ($periodo['anio_estado'] !== 'activo') {
            $this->redirectWithError($volverUrl, 'El año académico debe estar activo para abrir un bimestre.');
        }
        if ($this->model->tieneBimestreActivo((int) $periodo['anio_id'])) {
            $this->redirectWithError($volverUrl, 'Ya hay un bimestre activo en este año. Ciérralo antes de abrir otro.');
        }
        // Orden cronológico: no se puede abrir un bimestre si uno anterior sigue
        // sin abrir. Evita saltarse bimestres (lo que distorsiona el "vigente").
        if ($this->model->hayBimestrePrevioPendiente((int) $periodo['anio_id'], (int) $periodo['numero'])) {
            $this->redirectWithError($volverUrl, 'Debes abrir los bimestres en orden: hay un bimestre anterior que aún no se ha abierto.');
        }

        $this->model->setEstadoPeriodo($id, 'activo');
        $this->redirectWithSuccess($volverUrl, "{$periodo['nombre_display']} abierto. Los docentes ya pueden registrar notas.");
    }

    // POST /director/periodos/{id}/cerrar
    public function cerrar(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id      = (int) $id;
        $periodo = $this->model->getPeriodo($id);

        if (!$periodo) {
            $this->redirectWithError(url('director/anios'), 'Bimestre no encontrado.');
        }

        $volverUrl = url('director/anios/' . $periodo['anio_id']);

        if ($periodo['estado'] !== 'activo') {
            $this->redirectWithError($volverUrl, 'Solo se puede cerrar un bimestre activo.');
        }

        // Todos los empates del orden de mérito deben estar resueltos antes de
        // cerrar: el snapshot oficial (Fase 2) congela un ranking definitivo, así
        // que un empate pendiente al cierre quedaría petrificado sin resolver.
        $empates = (new OrdenMeritoModel())->gradosConEmpatesPendientes($id);
        if (!empty($empates)) {
            $this->redirectWithError(
                $volverUrl,
                'No se puede cerrar: hay empates sin resolver en el orden de mérito ('
                . implode('; ', array_unique($empates))
                . '). Resuélvelos antes de cerrar el bimestre.'
            );
        }

        // Rediseño 2 (P4): tampoco se cierra con evaluación incompleta. Un alumno
        // con notas en blanco SIN motivo (criterios que sus compañeros de sección
        // sí tienen y a él le faltan, sin omisión ni exoneración) requiere acción
        // humana: el docente registra la nota o la omisión. El detalle está en el
        // Centro de control. Cerrar OFICIALIZA el mérito, así que debe estar íntegro.
        $incompletos = (new ControlOperativoModel())->alertasEvaluacionIncompleta($id);
        if (!empty($incompletos)) {
            $this->redirectWithError(
                $volverUrl,
                'No se puede cerrar: hay ' . count($incompletos) . ' estudiante(s) con '
                . 'notas en blanco sin motivo (evaluación incompleta). Regístrales la nota '
                . 'o la omisión (motivo) antes de cerrar. Revisa el detalle en el Centro de control.'
            );
        }

        $usuarioId   = (int) (Session::user()['id'] ?? 0);
        $tipoRanking = 'oficial';

        // REGLA DEL PERIODO FINAL (decisión del usuario, 01/10/2026): el cierre
        // del último periodo del año ADMITE competencias vacías, pero solo con
        // confirmación explícita. La primera vez se detiene y muestra qué queda
        // vacío y cuántos estudiantes quedarían con la situación final PENDIENTE;
        // RA las completa después con la calificación extraordinaria.
        $periodoFinal   = $this->model->esPeriodoFinal($id);
        $confirmaVacias = $this->input('confirmar_vacias') === '1';
        $avisoFinal     = null;

        try {
            $this->model->beginTransaction();
            // Bloquea competencias propias + transversales (TIC/GAMA) de cada carga.
            $this->model->bloquearCompetenciasPendientes($id, $usuarioId);
            // El conteo va DESPUÉS del bloqueo forzado: desde aquí
            // `bloqueos_competencia` es el universo canónico de cada carga (sin
            // una quinta copia de la regla de «carga dueña»), y la situación
            // final —mismo PDO— ya lee lo que este cierre va a oficializar.
            if ($periodoFinal) {
                $avisoFinal = $this->model->avisoPeriodoFinal($id);
                if (!$confirmaVacias && $avisoFinal !== null) {
                    $this->model->rollback();
                    Session::flash('cierre_vacias', ['periodo_id' => $id] + $avisoFinal);
                    $this->redirectWithError(
                        $volverUrl,
                        "No se cerró el {$periodo['nombre_display']}: es el último bimestre del año "
                        . 'y quedan competencias sin evaluar. Revisa el detalle y confirma el cierre '
                        . 'si quieres continuar.'
                    );
                }
            }
            // Cierra las transversales por seccion para que agreguen en boleta
            // (respeta los cierres que el tutor ya hizo).
            $this->model->crearCierresTransversalesPendientes($id, $usuarioId);
            $this->model->setEstadoPeriodo($id, 'cerrado');
            // TUTOR DEL BIMESTRE (071): congela el tutor de cada sección para los
            // documentos de este bimestre. Inmutable: un re-cierre tras reabrir
            // conserva el primero. Mismo PDO singleton → misma transacción.
            (new TutorPeriodoModel())->congelarPeriodo($id, $usuarioId);
            // Cierre de boletas: deja la boleta en estado OFICIAL. El flag asegura
            // que, si luego se REABRE, vuelva a BORRADOR hasta re-cerrar.
            $this->model->marcarBoletasAprobadas($id, $usuarioId);
            // COMPUERTA DE PUBLICACION (044): CERRAR NUNCA PUBLICA. Publicar es
            // siempre un acto separado de RA/admin, por nivel y con fecha/hora.
            // Lo unico que hace el cierre es RESTAURAR una publicacion que una
            // reapertura previa habia suspendido (no crea filas nuevas): asi el
            // ciclo reabrir -> re-cerrar devuelve exactamente el estado anterior.
            $this->publicacionModel->restaurarPorCierre($id);
            // Recién DESPUÉS se congela el orden de mérito (primero boletas, luego
            // mérito). Mismo PDO singleton → entra en esta misma transacción.
            // registrarRanking respeta la INMUTABILIDAD (migr. 046): si el bimestre
            // YA estuvo publicado, NO toca el oficial y guarda una versión rectificada.
            $tipoRanking = (new OrdenMeritoModel())->registrarRanking(
                $id, $usuarioId, 'Cierre de bimestre'
            );
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error cerrando bimestre', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError($volverUrl, 'No se pudo cerrar el bimestre. Intenta de nuevo.');
        }

        // Redirige a la vista del año con el flag para abrir el modal de indicadores.
        $notaRanking = $tipoRanking === 'rectificado'
            ? ' El orden de mérito oficial NO cambió (bimestre ya publicado); se registró'
              . ' una versión rectificada en el Centro de control.'
            : '';
        $notaFinal = $avisoFinal !== null
            ? ' ' . AnioAcademicoModel::textoAvisoPeriodoFinal($avisoFinal)
              . ' Regístralas con la calificación extraordinaria (Rectificaciones).'
            : '';
        $this->redirectWithSuccess(
            url('director/anios/' . $periodo['anio_id']) . '?cerrado=' . $id,
            "{$periodo['nombre_display']} cerrado. Las competencias pendientes quedaron bloqueadas."
            . $notaRanking . $notaFinal
        );
    }

    // POST /director/periodos/{id}/reabrir
    public function reabrir(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id      = (int) $id;
        $periodo = $this->model->getPeriodo($id);

        if (!$periodo) {
            $this->redirectWithError(url('director/anios'), 'Bimestre no encontrado.');
        }

        $volverUrl = url('director/anios/' . $periodo['anio_id']);

        if ($periodo['estado'] !== 'cerrado') {
            $this->redirectWithError($volverUrl, 'Solo se puede reabrir un bimestre cerrado.');
        }
        if ($periodo['anio_estado'] === 'cerrado') {
            $this->redirectWithError($volverUrl, 'No se puede reabrir un bimestre de un año cerrado.');
        }
        if ($this->model->tieneBimestreActivo((int) $periodo['anio_id'])) {
            $this->redirectWithError($volverUrl, 'Ya hay un bimestre activo en este año. Ciérralo antes de reabrir otro.');
        }

        // El motivo es obligatorio: reabrir es excepcional y debe quedar auditado.
        $motivo = trim((string) $this->input('motivo', ''));
        if (mb_strlen($motivo) < 10) {
            $this->redirectWithError(
                $volverUrl,
                'Debes indicar el motivo de la reapertura (mínimo 10 caracteres).'
            );
        }
        $motivo = mb_substr($motivo, 0, 500);

        $usuarioId = (int) (Session::user()['id'] ?? 0);

        try {
            $this->model->beginTransaction();
            $this->model->setEstadoPeriodo($id, 'activo');
            // Reabrir NO libera bloqueos automáticamente: las competencias
            // finalizadas-vacías (origen='docente', aprobadas sin notas) deben
            // permanecer bloqueadas. Para liberar los bloqueos del cierre forzado
            // (origen='cierre') el director usa el botón manual del panel de
            // bloqueos. Solo se deja traza del motivo de la reapertura.
            $this->model->registrarReapertura($id, $motivo, $usuarioId, 0);
            // COMPUERTA DE PUBLICACION (044): reabrir SUSPENDE la publicacion en
            // todos los niveles — las familias dejan de ver esas boletas mientras
            // el bimestre vuelve a estar en juego. Es REVERSIBLE a proposito:
            // volver a cerrarlo restaura la publicacion previa sin republicar.
            $this->publicacionModel->suspenderPorReapertura($id);
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error reabriendo bimestre', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError($volverUrl, 'No se pudo reabrir el bimestre. Intenta de nuevo.');
        }

        $this->redirectWithSuccess(
            $volverUrl,
            "{$periodo['nombre_display']} reabierto. Los bloqueos se conservan; "
            . 'usa el panel de bloqueos para liberar los del cierre forzado si hace falta.'
        );
    }

    // GET /director/periodos/{id}/stats
    public function stats(string $id): void
    {
        $id      = (int) $id;
        $periodo = $this->model->getPeriodo($id);

        if (!$periodo) {
            $this->redirectWithError(url('director/anios'), 'Bimestre no encontrado.');
        }

        $this->view('director/anios/stats', [
            'titulo'      => 'Indicadores — ' . $periodo['nombre_display'] . ' ' . $periodo['anio'],
            'periodo'     => $periodo,
            'stats'       => $this->model->getStatsCierre($id),
            'resumen'     => $this->model->getResumenBimestre($id),
            'reaperturas' => $this->model->getReaperturas($id),
        ]);
    }
}
