<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AuxiliarSeccionModel;
use App\Models\ConductaModel;
use Core\Session;
use Core\View;

/**
 * Conducta — ETAPA 1 (auxiliar académico; Registro Académico como respaldo).
 * Registra los criterios Si/No por alumno y bloquea/aprueba la seccion.
 * La ETAPA 2 (tutor) vive en Docente\ConductaTutorController.
 */
class ConductaController extends BaseController
{
    private ConductaModel $model;
    private AuxiliarSeccionModel $aux;

    public function __construct()
    {
        // Registran admin, Registro Academico y, desde el 28/09/2026, el
        // auxiliar academico. El auxiliar solo sobre SUS secciones del
        // bimestre: lo decide AuxiliarSeccionModel::puedeRegistrar() por metodo.
        $this->requireRole(['admin', 'registro_academico', ROL_AUXILIAR]);
        $this->model = new ConductaModel();
        $this->aux   = new AuxiliarSeccionModel();
    }

    /** ¿Puede el usuario de la sesión registrar esa sección en ese periodo? */
    private function puede(int $seccionId, int $periodoId): bool
    {
        return $this->aux->puedeRegistrar(Session::user() ?? [], $seccionId, $periodoId);
    }

    /** Devuelve el primer periodo editable del año activo, o null. */
    private function periodoActivo(): ?array
    {
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((bool) $p['editable']) {
                return $p;
            }
        }
        return null;
    }

    /** Busca una seccion del año activo por id, o null. */
    private function buscarSeccion(int $seccionId): ?array
    {
        foreach ($this->model->listarSeccionesActivas() as $s) {
            if ((int) $s['id'] === $seccionId) {
                return $s;
            }
        }
        return null;
    }

    // GET /admin/conducta   (?periodo={id} = bimestre cerrado, historial)
    public function index(): void
    {
        $secciones     = $this->model->listarSeccionesActivas();
        $periodos      = $this->model->listarPeriodosActivos();
        $periodoActivo = $this->periodoActivo();

        // Periodo mostrado: el cerrado pedido por ?periodo= o el editable en curso.
        $periodoParam = (int) ($this->query('periodo') ?? 0);
        $periodoVer   = $periodoActivo;
        if ($periodoParam && (!$periodoActivo || $periodoParam !== (int) $periodoActivo['id'])) {
            $periodoVer = null;
            foreach ($periodos as $p) {
                if ((int) $p['id'] === $periodoParam && $p['estado'] === 'cerrado') {
                    $periodoVer = $p;
                    break;
                }
            }
            if (!$periodoVer) {
                $this->redirectWithError(url('admin/conducta'), 'Periodo no encontrado.');
            }
        }
        $esHistorial = $periodoVer !== null && !((bool) $periodoVer['editable']);

        // Opciones del select: el periodo editable + los cerrados (sin pendientes).
        $periodosNav = [];
        foreach ($periodos as $p) {
            if ((bool) $p['editable'] || $p['estado'] === 'cerrado') {
                $periodosNav[] = $p;
            }
        }

        $progreso = $periodoVer
            ? $this->model->getProgresoConductaPorSeccion((int) $periodoVer['id'])
            : [];

        // El auxiliar ve SOLO las secciones que tuvo a su cargo en el bimestre
        // mostrado (el en curso o el cerrado del historial). Admin y RA, todas.
        if (has_role(ROL_AUXILIAR)) {
            $mias = $periodoVer
                ? $this->aux->seccionesDe((int) Session::user()['id'], (int) $periodoVer['id'])
                : [];
            $secciones = array_values(array_filter(
                $secciones,
                fn($s) => in_array((int) $s['id'], $mias, true)
            ));
        }

        $porNivel = [];
        foreach ($secciones as $s) {
            $porNivel[$s['nivel_nombre']][] = $s;
        }

        $this->view('admin/conducta/index', [
            'titulo'        => 'Calificaciones de Conducta',
            'porNivel'      => $porNivel,
            'periodoActivo' => $periodoActivo,
            'periodoVer'    => $periodoVer,
            'periodosNav'   => $periodosNav,
            'esHistorial'   => $esHistorial,
            'progreso'      => $progreso,
        ]);
    }

    // GET /admin/conducta/{seccion_id}   (?periodo={id} = historial solo lectura)
    public function seccion(string $seccionId): void
    {
        $seccionId     = (int) $seccionId;
        $seccion       = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/conducta'), 'Sección no encontrada.');
        }

        $periodos      = $this->model->listarPeriodosActivos();
        $periodoActivo = null;
        foreach ($periodos as $p) {
            if ((bool) $p['editable']) {
                $periodoActivo = $p;
                break;
            }
        }

        // Periodo mostrado: el pedido por ?periodo= (si pertenece al año activo)
        // o el editable en curso. Un periodo no editable se muestra SOLO LECTURA.
        $periodoParam = (int) ($this->query('periodo') ?? 0);
        $periodoVer   = $periodoActivo;
        if ($periodoParam) {
            $periodoVer = null;
            foreach ($periodos as $p) {
                if ((int) $p['id'] === $periodoParam) {
                    $periodoVer = $p;
                    break;
                }
            }
            if (!$periodoVer) {
                $this->redirectWithError(url('admin/conducta/' . $seccionId), 'Periodo no encontrado.');
            }
        }
        $soloLectura = $periodoVer !== null && !((bool) $periodoVer['editable']);

        // Auxiliar: solo una sección que tuvo a su cargo EN el bimestre mostrado.
        $esAuxiliar = has_role(ROL_AUXILIAR);
        if ($esAuxiliar && ($periodoVer === null || !$this->puede($seccionId, (int) $periodoVer['id']))) {
            $this->forbidden();
        }

        // Estado de cierre por periodo para las pestañas del historial.
        // Los bimestres 'pendiente' (futuros) no se listan: sin datos que ver.
        // Al auxiliar solo se le listan los bimestres en que tuvo la sección.
        $periodosNav = [];
        foreach ($periodos as $p) {
            if ($p['estado'] === 'pendiente') {
                continue;
            }
            if ($esAuxiliar && !$this->puede($seccionId, (int) $p['id'])) {
                continue;
            }
            $p['cierre'] = $this->model->getCierreVigente($seccionId, (int) $p['id']);
            $periodosNav[] = $p;
        }

        $nivelId   = (int) $seccion['nivel_id'];
        $criterios = $this->model->getCriterios($nivelId);

        $estudiantes = $cierre = $firmas = null;
        $completitud = ['esperados' => 0, 'completos' => 0];
        $legado      = [];
        if ($periodoVer) {
            $pid         = (int) $periodoVer['id'];
            $estudiantes = $this->model->getEstudiantesParaRegistro($seccionId, $pid);
            $cierre      = $this->model->getCierreVigente($seccionId, $pid);
            if ($cierre) {
                $firmas = $this->aux->firmasDelRegistro($seccionId, $pid, (int) $cierre['ra_bloqueado_por']);
            }
            $completitud = $this->model->completitudSeccion($seccionId, $pid, count($criterios));

            // Bimestre legado (B1): sin matriz de respuestas pero con literal
            // directo en calificaciones_conducta -> se muestra en solo lectura.
            if ($soloLectura) {
                $hayRespuestas = false;
                foreach ($estudiantes as $est) {
                    if (!empty($est['respuestas'])) {
                        $hayRespuestas = true;
                        break;
                    }
                }
                if (!$hayRespuestas) {
                    $filas      = $this->model->getLiteralesLegado($seccionId, $pid);
                    $conLiteral = array_filter($filas, static fn($f) => $f['literal'] !== null);
                    if (!empty($conLiteral)) {
                        $legado = $filas;
                    }
                }
            }
        }

        $this->view('admin/conducta/seccion', [
            'titulo'       => 'Conducta — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'      => $seccion,
            'periodoVer'   => $periodoVer,
            'periodosNav'  => $periodosNav,
            'soloLectura'  => $soloLectura,
            'criterios'    => $criterios,
            'estudiantes'  => $estudiantes ?? [],
            'legado'       => $legado,
            'cierre'       => $cierre,
            'firmas'       => $firmas,
            'completitud'  => $completitud,
            'page_scripts' => $soloLectura ? [] : ['conducta'],
        ]);
    }

    // GET /admin/conducta/{seccion_id}/imprimir/{periodo_id}
    // Copia imprimible del registro aprobado y bloqueado (grilla de criterios
    // Si/No + nota RA) con encabezado formal y espacios de firma.
    public function imprimir(string $seccionId, string $periodoId): void
    {
        $seccionId = (int) $seccionId;
        $periodoId = (int) $periodoId;

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/conducta'), 'Sección no encontrada.');
        }

        $periodo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((int) $p['id'] === $periodoId) {
                $periodo = $p;
                break;
            }
        }
        if (!$periodo) {
            $this->redirectWithError(url('admin/conducta/' . $seccionId), 'Periodo no encontrado.');
        }
        if (!$this->puede($seccionId, $periodoId)) {
            $this->forbidden();
        }

        $cierre = $this->model->getCierreDetalle($seccionId, $periodoId);
        if (!$cierre) {
            $this->redirectWithError(
                url('admin/conducta/' . $seccionId . '?periodo=' . $periodoId),
                'Solo se puede imprimir un registro aprobado y bloqueado.'
            );
        }

        $criterios   = $this->model->getCriterios((int) $seccion['nivel_id']);
        $estudiantes = $this->model->getEstudiantesParaRegistro($seccionId, $periodoId);

        // Bimestre legado (B1): literal directo, sin matriz de criterios que imprimir.
        $hayRespuestas = false;
        foreach ($estudiantes as $est) {
            if (!empty($est['respuestas'])) {
                $hayRespuestas = true;
                break;
            }
        }
        if (!$hayRespuestas) {
            $this->redirectWithError(
                url('admin/conducta/' . $seccionId . '?periodo=' . $periodoId),
                'Este bimestre se registró con el modelo anterior (sin matriz de criterios); no hay registro imprimible.'
            );
        }

        View::setLayout('print');
        $this->view('admin/conducta/imprimir', [
            'titulo'      => 'Registro de Conducta — ' . $seccion['grado_nombre'] . ' '
                . $seccion['seccion_nombre'] . ' — ' . $periodo['nombre_display'],
            'seccion'     => $seccion,
            'periodo'     => $periodo,
            'criterios'   => $criterios,
            'estudiantes' => $estudiantes,
            'cierre'      => $cierre,
            'firmas'      => $this->aux->firmasDelRegistro($seccionId, $periodoId, (int) $cierre['ra_bloqueado_por']),
            'institucion' => config('institucion'),
        ]);
    }

    // GET /admin/conducta/{seccion_id}/estudiante   (?m={matricula_id})
    // Segunda entrada del registro (28/09/2026): UN estudiante por pantalla, con
    // los criterios uno bajo otro, pensada para el celular del auxiliar. Convive
    // con la grilla y guarda por el MISMO endpoint (`guardar`), así que no añade
    // ninguna regla de escritura. Es solo para REGISTRAR: sin bimestre editable,
    // o con la sección ya bloqueada, devuelve a la grilla, que resuelve el resto.
    public function estudiante(string $seccionId): void
    {
        $seccionId = (int) $seccionId;
        $seccion   = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/conducta'), 'Sección no encontrada.');
        }

        $grilla  = url('admin/conducta/' . $seccionId);
        $periodo = $this->periodoActivo();
        // Mismo corte que la grilla: al auxiliar, sin bimestre en curso no le
        // queda ninguna sección suya.
        if ($periodo === null && has_role(ROL_AUXILIAR)) {
            $this->forbidden();
        }
        if ($periodo === null) {
            $this->redirectWithError($grilla, 'No hay periodo abierto para edición.');
        }
        $pid = (int) $periodo['id'];
        if (!$this->puede($seccionId, $pid)) {
            $this->forbidden();
        }
        if ($this->model->getCierreVigente($seccionId, $pid)) {
            $this->redirectWithError($grilla, 'La conducta de esta sección ya fue bloqueada; no se puede editar.');
        }

        $criterios   = $this->model->getCriterios((int) $seccion['nivel_id']);
        $estudiantes = $this->model->getEstudiantesParaRegistro($seccionId, $pid);
        if (empty($criterios) || empty($estudiantes)) {
            redirect($grilla);
        }

        $pos = 0;
        $m   = (int) ($this->query('m') ?? 0);
        if ($m) {
            $pos = array_search($m, array_map('intval', array_column($estudiantes, 'matricula_id')), true);
            if ($pos === false) {
                $this->redirectWithError(
                    url('admin/conducta/' . $seccionId . '/estudiante'),
                    'Ese estudiante no forma parte del registro de conducta de la sección.'
                );
            }
        }

        $siguiente = $estudiantes[$pos + 1] ?? null;

        $this->view('admin/conducta/estudiante', [
            'titulo'       => 'Conducta — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'      => $seccion,
            'periodo'      => $periodo,
            'criterios'    => $criterios,
            'estudiantes'  => $estudiantes,
            'pos'          => $pos,
            'siguienteUrl' => $siguiente
                ? url('admin/conducta/' . $seccionId . '/estudiante?m=' . (int) $siguiente['matricula_id'])
                : $grilla,
            'page_scripts' => ['conducta', 'registro-estudiante'],
        ]);
    }

    // POST /admin/conducta/guardar  (AJAX — respuestas de un alumno)
    public function guardar(): void
    {
        $this->validateCsrf();

        $matriculaId = (int) $this->input('matricula_id');
        $periodoId   = (int) $this->input('periodo_id');
        $userId      = (int) Session::user()['id'];

        if (!$matriculaId || !$periodoId) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }
        if (!$this->model->periodoEditable($periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'El periodo no está disponible para edición.'], 403);
        }

        $ctx = $this->model->contextoMatricula($matriculaId);
        if (!$ctx) {
            $this->json(['success' => false, 'mensaje' => 'Matrícula no encontrada.'], 404);
        }
        $seccionId = (int) $ctx['seccion_id'];
        $nivelId   = (int) $ctx['nivel_id'];

        if (!$this->puede($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Esta sección no está a tu cargo en este bimestre.'], 403);
        }
        // La matricula tiene que estar en el roster de la seccion (el mismo que
        // pinta la grilla). Faltaba desde siempre: asistencia ya lo exigia.
        if (!$this->model->matriculaEnRoster($matriculaId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta matrícula no forma parte del registro de conducta de la sección.',
            ], 403);
        }

        // Si la seccion ya esta bloqueada, RA no puede editar (debe desbloquear admin).
        if ($this->model->getCierreVigente($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'La conducta de esta sección ya fue bloqueada; no se puede editar.'], 403);
        }

        $criterios   = $this->model->getCriterios($nivelId);
        $criterioIds = array_map(static fn($c) => (int) $c['id'], $criterios);
        $respIn      = $this->input('respuestas', []);
        if (!is_array($respIn)) {
            $respIn = [];
        }

        // Los criterios son OBLIGATORIOS: todos deben venir con 0 o 1.
        $respuestas = [];
        foreach ($criterioIds as $cid) {
            $v = $respIn[$cid] ?? null;
            if ($v === null || !in_array((string) $v, ['0', '1'], true)) {
                $this->json([
                    'success' => false,
                    'mensaje' => 'Debes responder Sí/No en los ' . count($criterioIds) . ' criterios.',
                ], 400);
            }
            $respuestas[$cid] = (int) $v;
        }

        $ok = $this->model->guardarRespuestas($matriculaId, $periodoId, $respuestas, $userId, $criterioIds);
        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Guardado.' : 'Error al guardar.',
        ], $ok ? 200 : 500);
    }

    // POST /admin/conducta/{seccion_id}/bloquear  (RA bloquea/aprueba la seccion)
    public function bloquear(string $seccionId): void
    {
        $this->validateCsrf();
        $seccionId = (int) $seccionId;

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/conducta'), 'Sección no encontrada.');
        }
        $periodoActivo = $this->periodoActivo();
        if (!$periodoActivo) {
            $this->redirectWithError(url('admin/conducta/' . $seccionId), 'No hay periodo abierto para edición.');
        }
        if (!$this->puede($seccionId, (int) $periodoActivo['id'])) {
            $this->forbidden();
        }

        $total = $this->model->totalCriterios((int) $seccion['nivel_id']);
        $res   = $this->model->bloquearRA(
            $seccionId,
            (int) $periodoActivo['id'],
            (int) Session::user()['id'],
            $total
        );

        if ($res['ok']) {
            $this->redirectWithSuccess(url('admin/conducta/' . $seccionId), $res['mensaje']);
        }
        $this->redirectWithError(url('admin/conducta/' . $seccionId), $res['mensaje']);
    }
}
