<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AsistenciaModel;
use Core\Session;
use Core\View;

class AsistenciaController extends BaseController
{
    /** Tope duro por contador (HTML5 max + validación server). Punto único en el modelo. */
    private const TOPE_MAX = AsistenciaModel::TOPE_MAX;

    /**
     * Quien OPERA el registro de asistencia: ve el índice, la grilla editable,
     * guarda y bloquea. Cuando se cree el rol auxiliar_academico, se añade aquí.
     *
     * Los directores NO entran aquí: solo al imprimible (ver `imprimir`). Por eso
     * el constructor admite el superconjunto y cada método se valida por separado
     * — mismo patrón que `ControlOperativoController::ROLES_PUBLICAN`. Esconder
     * el enlace no es control de acceso.
     */
    private const ROLES_REGISTRAN = ['admin', 'registro_academico'];

    private AsistenciaModel $model;

    public function __construct()
    {
        $this->requireRole([...self::ROLES_REGISTRAN, ...ROLES_DIRECCION]);
        $this->model = new AsistenciaModel();
    }

    // GET /admin/asistencia
    public function index(): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $secciones = $this->model->listarSeccionesActivas();
        $periodos  = $this->model->listarPeriodosActivos();

        // Periodo abierto actual: el progreso del índice refleja el llenado
        // del bimestre en curso. Si no hay periodo activo, no hay barra.
        $periodoActivo = null;
        foreach ($periodos as $p) {
            if ((bool) $p['editable']) {
                $periodoActivo = $p;
                break;
            }
        }

        $progreso = $periodoActivo
            ? $this->model->getProgresoPorSeccion((int) $periodoActivo['id'])
            : [];

        $porNivel = [];
        foreach ($secciones as $s) {
            $porNivel[$s['nivel_nombre']][] = $s;
        }

        $this->view('admin/asistencia/index', [
            'titulo'        => 'Asistencia — Incidencias',
            'porNivel'      => $porNivel,
            'periodoActivo' => $periodoActivo,
            'progreso'      => $progreso,
        ]);
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

    // GET /admin/asistencia/{seccion_id}   (?periodo={id} = historial solo lectura)
    public function seccion(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $seccionId = (int) $seccionId;
        $periodos  = $this->model->listarPeriodosActivos();

        if (empty($periodos)) {
            $this->redirectWithError(url('admin/asistencia'), 'No hay periodos configurados.');
        }

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
                $this->redirectWithError(url('admin/asistencia/' . $seccionId), 'Periodo no encontrado.');
            }
        }
        $soloLectura = $periodoVer !== null && !((bool) $periodoVer['editable']);

        // Estado de cierre por periodo para las pestañas del historial.
        // Los bimestres 'pendiente' (futuros) no se listan: sin datos que ver.
        $periodosNav = [];
        foreach ($periodos as $p) {
            if ($p['estado'] === 'pendiente') {
                continue;
            }
            $p['cierre'] = $this->model->getCierreVigente($seccionId, (int) $p['id']);
            $periodosNav[] = $p;
        }

        $estudiantes = $cierre = null;
        if ($periodoVer) {
            $pid         = (int) $periodoVer['id'];
            $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $pid);
            $cierre      = $this->model->getCierreVigente($seccionId, $pid);
        }

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        // La grilla se bloquea con el cierre de la seccion (ademas del periodo).
        $bloqueada = $cierre !== null;

        $this->view('admin/asistencia/seccion', [
            'titulo'       => 'Asistencia — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'      => $seccion,
            'periodoVer'   => $periodoVer,
            'periodosNav'  => $periodosNav,
            'soloLectura'  => $soloLectura,
            'cierre'       => $cierre,
            'estudiantes'  => $estudiantes ?? [],
            // Los totales salen del MISMO roster que se pinta (punto unico en el
            // modelo), no de una consulta paralela que podria contar otras filas.
            'totales'      => AsistenciaModel::totalesIncidencias($estudiantes ?? []),
            'topeMax'      => self::TOPE_MAX,
            'page_scripts' => ($soloLectura || $bloqueada) ? [] : ['asistencia'],
        ]);
    }

    // POST /admin/asistencia/{seccion_id}/bloquear  (RA bloquea/aprueba la seccion)
    public function bloquear(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        $periodoActivo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((bool) $p['editable']) {
                $periodoActivo = $p;
                break;
            }
        }
        if (!$periodoActivo) {
            $this->redirectWithError(url('admin/asistencia/' . $seccionId), 'No hay periodo abierto para edición.');
        }

        $res = $this->model->bloquearRA(
            $seccionId,
            (int) $periodoActivo['id'],
            (int) Session::user()['id']
        );

        if ($res['ok']) {
            $this->redirectWithSuccess(url('admin/asistencia/' . $seccionId), $res['mensaje']);
        }
        $this->redirectWithError(url('admin/asistencia/' . $seccionId), $res['mensaje']);
    }

    // GET /admin/asistencia/{seccion_id}/imprimir/{periodo_id}
    // Copia imprimible del registro aprobado y bloqueado (contadores de
    // incidencias) con encabezado formal y espacios de firma.
    public function imprimir(string $seccionId, string $periodoId): void
    {
        $seccionId = (int) $seccionId;
        $periodoId = (int) $periodoId;

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        $periodo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((int) $p['id'] === $periodoId) {
                $periodo = $p;
                break;
            }
        }
        if (!$periodo) {
            $this->redirectWithError(url('admin/asistencia/' . $seccionId), 'Periodo no encontrado.');
        }

        $cierre = $this->model->getCierreDetalle($seccionId, $periodoId);
        if (!$cierre) {
            $this->redirectWithError(
                url('admin/asistencia/' . $seccionId . '?periodo=' . $periodoId),
                'Solo se puede imprimir un registro aprobado y bloqueado.'
            );
        }

        $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $periodoId);

        View::setLayout('print');
        $this->view('admin/asistencia/imprimir', [
            'titulo'      => 'Registro de Asistencia — ' . $seccion['grado_nombre'] . ' '
                . $seccion['seccion_nombre'] . ' — ' . $periodo['nombre_display'],
            'seccion'     => $seccion,
            'periodo'     => $periodo,
            'estudiantes' => $estudiantes,
            'cierre'      => $cierre,
            'institucion' => config('institucion'),
        ]);
    }

    // POST /admin/asistencia/guardar  (AJAX, batch por fila)
    public function guardar(): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
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

        // Si la seccion ya esta bloqueada, no se puede editar (debe desbloquear Dirección).
        $seccionId = $this->model->seccionDeMatricula($matriculaId);
        if ($seccionId === null) {
            $this->json(['success' => false, 'mensaje' => 'Matrícula no encontrada.'], 404);
        }

        // La matricula tiene que estar en el roster de la seccion (mismo que la
        // grilla del docente). Cubre la pestaña abierta desde antes del cambio.
        if (!$this->model->matriculaEnRoster($matriculaId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta matrícula no forma parte del registro de asistencia de la sección.',
            ], 403);
        }
        if ($this->model->getCierreVigente($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'La asistencia de esta sección ya fue bloqueada; no se puede editar.'], 403);
        }

        // Saneamiento y validación de los 4 contadores. Cualquier valor
        // fuera de [0..TOPE_MAX] o no numérico se rechaza con 400.
        $campos = [
            'faltas'                 => $this->input('faltas'),
            'faltas_justificadas'    => $this->input('faltas_justificadas'),
            'tardanzas'              => $this->input('tardanzas'),
            'tardanzas_justificadas' => $this->input('tardanzas_justificadas'),
        ];

        $valores = [];
        foreach ($campos as $nombre => $crudo) {
            $crudo = trim((string) $crudo);
            if ($crudo === '' || !ctype_digit($crudo)) {
                $this->json([
                    'success' => false,
                    'mensaje' => "El campo {$nombre} debe ser un entero entre 0 y " . self::TOPE_MAX . '.',
                ], 400);
            }
            $n = (int) $crudo;
            if ($n < 0 || $n > self::TOPE_MAX) {
                $this->json([
                    'success' => false,
                    'mensaje' => "El campo {$nombre} debe estar entre 0 y " . self::TOPE_MAX . '.',
                ], 400);
            }
            $valores[$nombre] = $n;
        }

        $ok = $this->model->guardar(
            $matriculaId,
            $periodoId,
            $valores['faltas'],
            $valores['faltas_justificadas'],
            $valores['tardanzas'],
            $valores['tardanzas_justificadas'],
            $userId
        );

        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Guardado.' : 'Error al guardar.',
        ], $ok ? 200 : 500);
    }
}
