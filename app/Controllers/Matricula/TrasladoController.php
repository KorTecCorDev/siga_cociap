<?php

namespace App\Controllers\Matricula;

use App\Controllers\BaseController;
use App\Models\MatriculaModel;
use App\Models\TrasladoModel;
use App\Models\ApoderadoModel;
use App\Models\EstudianteModel;
use App\Models\DirectorEbrModel;
use App\Models\OrdenMeritoModel;
use Core\Session;
use Core\View;

/**
 * TrasladoController
 * Constancias de traslado de SALIDA. Flujo:
 *   GET  /matriculas/{id}/trasladar  → formulario
 *   POST /matriculas/{id}/trasladar  → registra la constancia (correlativo
 *        oficial) Y da de baja la matrícula (estado=desactivado, tipo=trasladado,
 *        apaga login del apoderado y boletas públicas) en UNA transacción.
 *   GET  /traslados                  → registro oficial (libro)
 *   GET  /traslados/{id}/imprimir    → constancia A4 (layout print)
 *   POST /traslados/{id}/anular      → anula (motivo+fecha+usuario, libera el N°)
 *
 * Solo admin y registro_academico.
 */
class TrasladoController extends BaseController
{
    private MatriculaModel  $matriculas;
    private TrasladoModel   $traslados;
    private ApoderadoModel  $apoderados;
    private EstudianteModel $estudiantes;
    private DirectorEbrModel $directores;
    private OrdenMeritoModel $ordenMerito;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico']);
        $this->matriculas  = new MatriculaModel();
        $this->traslados   = new TrasladoModel();
        $this->apoderados  = new ApoderadoModel();
        $this->estudiantes = new EstudianteModel();
        $this->directores  = new DirectorEbrModel();
        $this->ordenMerito = new OrdenMeritoModel();
    }

    // ── GET /matriculas/{id}/trasladar ───────────────────────────
    public function form(string $matriculaId): void
    {
        $matricula = $this->requireMatricula((int) $matriculaId);

        // Solo se traslada una matrícula vigente (activa).
        if ($matricula['estado'] !== 'aprobada') {
            $this->redirectWithError(url('matriculas/' . $matriculaId),
                'Solo se puede trasladar una matrícula activa.');
        }

        // ¿Ya tiene una constancia vigente? → ir a imprimirla.
        $vigente = $this->traslados->getVigentePorMatricula((int) $matriculaId);
        if ($vigente) {
            $this->redirectWithError(url('traslados/' . $vigente['id'] . '/imprimir'),
                'Esta matrícula ya tiene una constancia de traslado vigente (N° '
                . $vigente['numero_constancia'] . ').');
        }

        $anioId  = (int) $matricula['anio_id'];
        $config  = $this->traslados->getConfigAnio($anioId);
        $inicial = (int) ($config['correlativo_traslado_inicial'] ?? 1);

        // Apoderado responsable para prefijar el solicitante.
        $responsable = null;
        foreach ($this->apoderados->getVinculos((int) $matricula['estudiante_id']) as $v) {
            if ((int) $v['es_responsable'] === 1) { $responsable = $v; break; }
        }

        $periodos = $this->matriculas->query(
            "SELECT id, numero, nombre_display FROM periodos WHERE anio_id = ? ORDER BY numero",
            [$anioId]
        );

        $this->view('matriculas/trasladar', [
            'titulo'            => 'Constancia de traslado',
            'matricula'         => $matricula,
            'motivos'           => TrasladoModel::MOTIVOS,
            'periodos'          => $periodos,
            'periodoActivoId'   => (int) ($this->estudiantes->periodoActivo($anioId)['id'] ?? 0),
            'correlativoSugerido' => $this->traslados->siguienteCorrelativo($anioId, $inicial),
            'anio'              => (int) ($config['anio'] ?? date('Y')),
            'responsable'       => $responsable,
            'tiposVinculo'      => MatriculaController::tiposVinculo(),
        ]);
    }

    // ── POST /matriculas/{id}/trasladar ──────────────────────────
    public function store(string $matriculaId): void
    {
        $this->validateCsrf();
        $matricula = $this->requireMatricula((int) $matriculaId);
        $usuarioId = (int) (Session::user()['id'] ?? 0);
        $id        = (int) $matriculaId;

        if ($matricula['estado'] !== 'aprobada') {
            $this->redirectWithError(url('matriculas/' . $id),
                'Solo se puede trasladar una matrícula activa.');
        }
        if ($this->traslados->getVigentePorMatricula($id)) {
            $this->redirectWithError(url('matriculas/' . $id),
                'Esta matrícula ya tiene una constancia de traslado vigente.');
        }

        $anioId  = (int) $matricula['anio_id'];
        $config  = $this->traslados->getConfigAnio($anioId);
        $anio    = (int) ($config['anio'] ?? date('Y'));
        $inicial = (int) ($config['correlativo_traslado_inicial'] ?? 1);

        // ── Validación de entradas ──
        $ieNombre  = trim((string) $this->input('ie_destino_nombre'));
        $ieModular = trim((string) $this->input('ie_destino_codigo_modular'));
        $fecha     = trim((string) $this->input('fecha_constancia'));
        $motivo    = (string) $this->input('motivo');
        $correlativo = (int) $this->input('correlativo');

        $err = function (string $msg) use ($id): void {
            $this->redirectWithError(url('matriculas/' . $id . '/trasladar'), $msg);
        };

        if ($ieNombre === '' || $ieModular === '') {
            $err('El nombre y el código modular de la IE destino son obligatorios.');
        }
        if ($fecha === '' || !$this->fechaValida($fecha)) {
            $err('La fecha de la constancia es obligatoria y debe ser válida.');
        }
        if (!array_key_exists($motivo, TrasladoModel::MOTIVOS)) {
            $err('Selecciona un motivo de traslado válido.');
        }
        if ($correlativo < 1) {
            $err('El número de constancia debe ser mayor o igual a 1.');
        }
        if (!$this->traslados->correlativoDisponible($anioId, $correlativo)) {
            $err('El número ' . $correlativo . ' ya está en uso por otra constancia vigente de '
                . $anio . '. Elige otro.');
        }

        $sufijo = config('institucion_datos')['sufijo_constancia'] ?? 'CAVVG-DA';
        $numero = TrasladoModel::formatearNumero($correlativo, $anio, $sufijo);

        $datos = [
            'matricula_id'              => $id,
            'anio_id'                   => $anioId,
            'correlativo'               => $correlativo,
            'numero_constancia'         => $numero,
            'ie_destino_nombre'         => mb_substr($ieNombre, 0, 200),
            'ie_destino_codigo_modular' => mb_substr($ieModular, 0, 30),
            'ie_destino_ugel'           => $this->nullable($this->input('ie_destino_ugel'), 150),
            'ie_destino_ubicacion'      => $this->nullable($this->input('ie_destino_ubicacion'), 200),
            'fecha_constancia'          => $fecha,
            'periodo_id'                => ((int) $this->input('periodo_id')) ?: null,
            'motivo'                    => $motivo,
            'motivo_detalle'            => $this->nullable($this->input('motivo_detalle'), 300),
            'solicitante_nombre'        => $this->nullable($this->input('solicitante_nombre'), 200),
            'solicitante_dni'           => $this->nullable($this->input('solicitante_dni'), 8),
            'solicitante_parentesco'    => $this->nullable($this->input('solicitante_parentesco'), 40),
            'situacion_academica'       => $this->nullable($this->input('situacion_academica'), 300),
            'observaciones'             => $this->nullable($this->input('observaciones'), 500),
            'generada_por'              => $usuarioId,
        ];

        $this->matriculas->beginTransaction();
        try {
            // Defensa anti-carrera: re-verifica el número dentro de la transacción.
            if (!$this->traslados->correlativoDisponible($anioId, $correlativo)) {
                throw new \RuntimeException('correlativo ocupado');
            }

            $trasladoId = $this->traslados->create($datos);

            // Baja por traslado: estado=desactivado + tipo=trasladado (preserva el
            // origen en tipo_anterior para reversibilidad), apaga login del
            // apoderado y TODAS las boletas públicas de la matrícula.
            $this->matriculas->cambiarEstado($id, 'desactivado', $usuarioId,
                'Traslado de salida: ' . (TrasladoModel::MOTIVOS[$motivo] ?? $motivo));
            $cambios = ['tipo' => 'trasladado'];
            if (($matricula['tipo'] ?? '') !== 'trasladado') {
                $cambios['tipo_anterior'] = $matricula['tipo'];
            }
            $this->matriculas->update($id, $cambios);

            $this->apoderados->desactivarUsuarioDeEstudiante((int) $matricula['estudiante_id']);

            $this->matriculas->execute(
                "UPDATE boletas_publicas SET activa = 0 WHERE matricula_id = ?",
                [$id]
            );

            // El trasladado sale del orden de mérito de los bimestres cerrados
            // que aún no se publicaron (punto único; los publicados no se tocan).
            // Va DENTRO de la transacción: el PDO es singleton compartido, así
            // que o se traslada y se ajusta el ranking, o no pasa ninguna de las dos.
            $efectosMerito = $this->ordenMerito->sincronizarRosterPorMatricula($id, $usuarioId);

            $this->matriculas->commit();
        } catch (\Exception $e) {
            $this->matriculas->rollback();
            log_error('Error al registrar traslado', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id . '/trasladar'),
                'No se pudo registrar el traslado. Verifica el número de constancia e intenta de nuevo.');
        }

        $this->redirectWithSuccess(url('traslados/' . $trasladoId . '/imprimir'),
            'Constancia ' . $numero . ' registrada. La matrícula quedó trasladada.'
            . OrdenMeritoModel::describirEfectosRoster($efectosMerito));
    }

    // ── GET /traslados ───────────────────────────────────────────
    public function index(): void
    {
        $anioActivo = $this->estudiantes->anioActivo();
        $anioId     = (int) ($this->query('anio_id') ?: ($anioActivo['id'] ?? 0));
        $estado     = in_array($this->query('estado'), ['vigente', 'anulado'], true)
            ? $this->query('estado') : null;

        $this->view('traslados/index', [
            'titulo'    => 'Constancias de traslado',
            'traslados' => $this->traslados->listar($anioId ?: null, $estado),
            'anios'     => $this->matriculas->listarAnios(),
            'anioId'    => $anioId,
            'estado'    => $estado,
        ]);
    }

    // ── GET /traslados/{id}/imprimir ─────────────────────────────
    public function imprimir(string $id): void
    {
        $traslado = $this->traslados->getDetalle((int) $id);
        if (!$traslado) {
            // Punto único del 404 (BaseController): sin layout, porque
            // `shared/404.php` es una página HTML completa.
            $this->notFound();
        }

        // Código modular y resolución según el nivel del estudiante.
        $datos      = config('institucion_datos') ?? [];
        $esPrimaria = stripos((string) ($traslado['nivel_nombre'] ?? ''), 'prim') !== false;
        $codModular = $esPrimaria
            ? ($datos['codigo_modular_primaria'] ?? '')
            : ($datos['codigo_modular_secundaria'] ?? '');

        $director = $this->directores->getVigenteEnFecha(
            (int) $traslado['anio_id'],
            $traslado['fecha_constancia'] ?: null
        );

        if ($traslado['estado'] === 'vigente') {
            $this->traslados->registrarImpresion((int) $id);
        }

        View::setLayout('print');
        $this->view('traslados/constancia', [
            'titulo'      => 'Constancia de traslado ' . $traslado['numero_constancia'],
            'traslado'    => $traslado,
            'institucion' => $datos,
            'codModular'  => $codModular,
            'motivoLabel' => TrasladoModel::MOTIVOS[$traslado['motivo']] ?? $traslado['motivo'],
            'directorEbr' => $director,
        ]);
    }

    // ── POST /traslados/{id}/anular ──────────────────────────────
    public function anular(string $id): void
    {
        $this->validateCsrf();
        $traslado = $this->traslados->find((int) $id);
        if (!$traslado) {
            $this->redirectWithError(url('traslados'), 'Constancia no encontrada.');
        }
        if ($traslado['estado'] !== 'vigente') {
            $this->redirectWithError(url('traslados'), 'La constancia ya estaba anulada.');
        }

        $motivo = trim((string) $this->input('motivo_anulacion'));
        if ($motivo === '') {
            $this->redirectWithError(url('traslados'),
                'Indica el motivo de anulación de la constancia.');
        }

        $this->traslados->anular((int) $id, mb_substr($motivo, 0, 300),
            (int) (Session::user()['id'] ?? 0));

        $this->redirectWithSuccess(url('traslados'),
            'Constancia ' . $traslado['numero_constancia'] . ' anulada. Su número queda disponible.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** Recorta y normaliza a NULL un campo de texto opcional. */
    private function nullable(mixed $valor, int $max): ?string
    {
        $v = trim((string) $valor);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Valida una fecha en formato YYYY-MM-DD real. */
    private function fechaValida(string $fecha): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $fecha);
        return $d !== false && $d->format('Y-m-d') === $fecha;
    }

    private function requireMatricula(int $id): array
    {
        $matricula = $this->matriculas->findById($id);
        if (!$matricula) {
            // Punto único del 404 (BaseController): sin layout, porque
            // `shared/404.php` es una página HTML completa.
            $this->notFound();
        }
        return $matricula;
    }
}
