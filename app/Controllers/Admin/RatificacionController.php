<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Matricula\MatriculaController;
use App\Models\DirectorEbrModel;
use App\Models\RatificacionModel;
use App\Models\RatificacionMotivoModel;
use App\Models\RatificacionPlantillaModel;
use Core\Session;
use Core\View;

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

    // POST /admin/ratificaciones/{matricula_id}/emitir
    public function emitir(string $matriculaId): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $mat = $this->model->matriculaDelUniverso((int) $matriculaId);
        if ($mat === null) {
            $this->notFound();
        }
        $volver = $this->urlSeccion($mat);
        try {
            $this->model->emitir((int) $mat['id'], (int) Session::user()['id']);
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }
        $this->redirectWithSuccess($volver . '#m' . (int) $mat['id'], 'Documento emitido.');
    }

    // POST /admin/ratificaciones/seccion/{seccion_id}/emitir
    public function emitirSeccion(string $seccionId): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $seccion = $this->model->seccion((int) $seccionId, (int) $this->input('anio_id', 0));
        if ($seccion === null) {
            $this->notFound();
        }
        $volver = $this->urlSeccion(['anio_id' => $seccion['anio_id'], 'seccion_id' => $seccion['id']]);

        $r = $this->model->emitirSeccion((int) $seccion['id'], (int) Session::user()['id']);

        $nombres = ['ratificado' => 'ratificados', 'no_ratificado' => 'no ratificados'];
        $faltan  = [];
        foreach ($r['sin_plantilla'] as $tipo => $n) {
            $faltan[] = $n . ' sin emitir: falta la plantilla de ' . $nombres[$tipo] . '.';
        }
        if ($r['emitidos'] === 0 && $faltan === []) {
            $this->redirectWithError($volver, 'No hay documentos por emitir en esta sección.');
        }
        $mensaje = $r['emitidos'] . ' documento(s) emitido(s).' . ($faltan !== [] ? ' ' . implode(' ', $faltan) : '');
        if ($faltan !== []) {
            $this->redirectWithError($volver, $mensaje);
        }
        $this->redirectWithSuccess($volver, $mensaje);
    }

    // POST /admin/ratificaciones/{matricula_id}/anular
    public function anular(string $matriculaId): void
    {
        $this->requireRole(RatificacionModel::ROLES_REGISTRAN);
        $this->validateCsrf();

        $mat = $this->model->matriculaDelUniverso((int) $matriculaId);
        if ($mat === null) {
            $this->notFound();
        }
        $volver = $this->urlSeccion($mat);
        try {
            $this->model->anular((int) $mat['id'], (int) Session::user()['id']);
        } catch (\DomainException $e) {
            $this->redirectWithError($volver, $e->getMessage());
        }
        $this->redirectWithSuccess($volver . '#m' . (int) $mat['id'], 'Documento anulado.');
    }

    // GET /admin/ratificaciones/emision/{id}/imprimir
    // Lectura: reimprime la MISMA emisión (mismo QR). Dirección también puede
    // (decisión del usuario, 15/10/2026). Una anulada sale con el sello ANULADA.
    public function imprimir(string $id): void
    {
        $em = $this->model->emisionParaDocumento((int) $id);
        if ($em === null) {
            $this->notFound();
        }
        $doc = $this->datosDocumento($em, new RatificacionPlantillaModel(), new DirectorEbrModel());

        View::setLayout('print');
        $this->view('admin/ratificaciones/documento', $doc + [
            'titulo' => $doc['plantilla']['titulo'] . ' — ' . $doc['vars']['estudiante'],
        ]);
    }

    // GET /admin/ratificaciones/seccion/{seccion_id}/lote?anio_id=
    // Lectura: un PDF por documento VIGENTE de la sección, en un ZIP (patrón
    // de `admin/boletas-publicas/archivar`). Mismo acceso que imprimir.
    public function lote(string $seccionId): void
    {
        $seccion = $this->model->seccion((int) $seccionId, (int) $this->query('anio_id', 0));
        if ($seccion === null) {
            $this->notFound();
        }
        $plantillas = new RatificacionPlantillaModel();
        $directores = new DirectorEbrModel();

        $documentos = [];
        foreach ($this->model->emisionesVigentesDeSeccion((int) $seccion['id']) as $emisionId) {
            $em  = $this->model->emisionParaDocumento($emisionId);
            $doc = $this->datosDocumento($em, $plantillas, $directores);
            // Nombre del PDF: APELLIDOS_NOMBRES (sin DNI), como el archivo de boletas.
            $doc['nombre_archivo'] = preg_replace('/[^\p{L}\p{N}_]+/u', '', str_replace([' ', ','], ['_', ''],
                mb_strtoupper((string) $em['estudiante'])));
            $documentos[] = $doc;
        }

        $grado = mb_strtoupper(preg_replace('/[°\s.]+/u', '', (string) $seccion['grado_nombre']));
        View::setLayout('print');
        $this->view('admin/ratificaciones/lote', [
            'titulo'     => 'Documentos de ratificación — ' . $seccion['grado_nombre'] . ' "' . $seccion['nombre'] . '"',
            'documentos' => $documentos,
            'nombreZip'  => mb_strtoupper(str_replace(' ', '_', (string) $seccion['nivel_nombre']))
                . "_{$grado}_" . mb_strtoupper((string) $seccion['nombre'])
                . '_RATIFICACION_' . (int) ($this->model->anio((int) $seccion['anio_id'])['anio'] ?? 0) . '.zip',
            // Lote: sin «Página X de Y» (contaría toda la impresión). Ver _boleta.scss.
            'bodyClass'  => 'doc-lote',
        ]);
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

    /**
     * Variables de `admin/ratificaciones/documento` para una emisión
     * (`RatificacionModel::emisionParaDocumento()`): lo comparten la impresión
     * individual y el lote. Firma y sello: el Director EBR vigente en la fecha
     * de emisión. Código modular según el nivel.
     */
    private function datosDocumento(array $em, RatificacionPlantillaModel $plantillas, DirectorEbrModel $directores): array
    {
        $plantilla = $plantillas->plantilla((int) $em['plantilla_id']);
        $anio      = $plantillas->anio((int) $em['anio_id']);
        $vars      = RatificacionModel::varsDeEmision($em, MatriculaController::tiposVinculo());
        $datos     = config('institucion_datos') ?? [];
        $fecha     = substr((string) $em['emitido_en'], 0, 10);

        return [
            'vistaPrevia'  => false,
            'anulada'      => $em['vigente'] === null,
            'plantilla'    => $plantilla,
            'bloques'      => $plantillas->bloquesParaDocumento((int) $plantilla['id'], $vars),
            'vars'         => $vars,
            'lema'         => (string) ($anio['lema_oficial'] ?? ''),
            'institucion'  => $datos,
            'codModular'   => (string) ($em['nivel_codigo'] === 'prim'
                ? ($datos['codigo_modular_primaria'] ?? '')
                : ($datos['codigo_modular_secundaria'] ?? '')),
            'motivoTexto'  => $em['decision'] === 'no_ratificado' ? (string) $em['motivo_texto'] : null,
            'detalleTexto' => $em['detalle_texto'],
            'fecha'        => $fecha,
            'directorEbr'  => $directores->getVigenteEnFecha((int) $em['anio_id'], $fecha),
            'urlVerificar' => url('ratificacion/verificar/' . $em['token']),
        ];
    }

    /** Pantalla de la sección de una matrícula ({ anio_id, seccion_id }). */
    private function urlSeccion(array $mat): string
    {
        return url('admin/ratificaciones?anio_id=' . (int) $mat['anio_id']
            . '&seccion_id=' . (int) $mat['seccion_id']);
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
