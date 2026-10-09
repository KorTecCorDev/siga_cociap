<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AsistenciaJornadaModel;
use App\Models\AsistenciaModel;
use App\Models\AsistenciaMotivoModel;
use App\Models\AuxiliarSeccionModel;
use Core\Session;
use Core\View;

class AsistenciaController extends BaseController
{
    /** Tope duro por contador (HTML5 max + validación server). Punto único en el modelo. */
    private const TOPE_MAX = AsistenciaModel::TOPE_MAX;

    /**
     * Quien OPERA el registro de asistencia: ve el índice, la grilla editable,
     * guarda y bloquea. Desde el 28/09/2026 incluye al AUXILIAR ACADÉMICO, pero
     * solo sobre SUS secciones del bimestre: ese alcance no lo da el rol, lo
     * decide `AuxiliarSeccionModel::puedeRegistrar()` en cada método.
     *
     * Los directores NO entran aquí: solo al imprimible (ver `imprimir`). Por eso
     * el constructor admite el superconjunto y cada método se valida por separado
     * — mismo patrón que `ControlOperativoController::ROLES_PUBLICAN`. Esconder
     * el enlace no es control de acceso.
     */
    private const ROLES_REGISTRAN = ['admin', 'registro_academico', ROL_AUXILIAR];

    private AsistenciaModel $model;
    private AuxiliarSeccionModel $aux;

    public function __construct()
    {
        $this->requireRole([...self::ROLES_REGISTRAN, ...ROLES_DIRECCION]);
        $this->model = new AsistenciaModel();
        $this->aux   = new AuxiliarSeccionModel();
    }

    /** ¿Puede el usuario de la sesión registrar esa sección en ese periodo? */
    private function puede(int $seccionId, int $periodoId): bool
    {
        return $this->aux->puedeRegistrar(Session::user() ?? [], $seccionId, $periodoId);
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
        // Secciones ya bloqueadas en el bimestre en curso: su card lleva
        // «🔒 Bloqueada» en lugar de la barra (09/10/2026, como conducta).
        $bloqueadas = $periodoActivo
            ? $this->model->seccionesBloqueadas((int) $periodoActivo['id'])
            : [];

        // El auxiliar ve SOLO sus secciones del bimestre en curso; sin bimestre
        // abierto no tiene ninguna. Admin y RA siguen viendo todas.
        if (has_role(ROL_AUXILIAR)) {
            $mias = $periodoActivo
                ? $this->aux->seccionesDe((int) Session::user()['id'], (int) $periodoActivo['id'])
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

        $this->view('admin/asistencia/index', [
            'titulo'        => 'Asistencia — Incidencias',
            'porNivel'      => $porNivel,
            'periodoActivo' => $periodoActivo,
            'progreso'      => $progreso,
            'bloqueadas'    => $bloqueadas,
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

    /**
     * Bimestre que muestra una vista de la sección (la tabla y sus justificaciones):
     * el pedido por ?periodo= (si pertenece al año activo) o el editable en curso.
     * Un periodo no editable se muestra SOLO LECTURA. Corta con 403 al auxiliar
     * fuera de su sección y arma las pestañas del historial.
     *
     * @return array{0: ?array, 1: bool, 2: array} [periodoVer, soloLectura, periodosNav]
     */
    private function resolverPeriodoVista(int $seccionId): array
    {
        $periodos = $this->model->listarPeriodosActivos();

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

        // Auxiliar: solo una sección que tuvo a su cargo EN el bimestre mostrado.
        // Sin bimestre que mostrar no hay nada suyo que ver.
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

        return [$periodoVer, $soloLectura, $periodosNav];
    }

    // GET /admin/asistencia/{seccion_id}   (?periodo={id} = historial solo lectura)
    public function seccion(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $seccionId = (int) $seccionId;
        [$periodoVer, $soloLectura, $periodosNav] = $this->resolverPeriodoVista($seccionId);

        $estudiantes = $cierre = $firmas = null;
        if ($periodoVer) {
            $pid    = (int) $periodoVer['id'];
            $cierre = $this->model->getCierreVigente($seccionId, $pid);
            // Editable: el BORRADOR del auxiliar. Historial o sección bloqueada:
            // solo lo OFICIAL (confirmado), que es lo que cuenta.
            $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $pid, $soloLectura || $cierre !== null);
            if ($cierre) {
                $firmas = $this->aux->firmasDelRegistro($seccionId, $pid, (int) $cierre['ra_bloqueado_por']);
            }
        }

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        // La grilla se bloquea con el cierre de la seccion (ademas del periodo).
        $bloqueada = $cierre !== null;
        $editable  = !$soloLectura && !$bloqueada;

        // ASISTENCIA POR FECHAS (29/09/2026): grilla mensual día × estudiante.
        // I y II Bimestre siguen con la tabla de 4 números (histórico).
        $porFechas = $periodoVer !== null && (int) ($periodoVer['asistencia_por_fechas'] ?? 0) === 1;
        $fechas    = [];
        if ($porFechas) {
            // Lista del día y no lectivos (30/09/2026, migración 070).
            $jornadasM  = new AsistenciaJornadaModel();
            $noLectivos = $jornadasM->noLectivos($periodoVer);
            $calendario = AsistenciaModel::calendario($periodoVer, null, $noLectivos);
            $mesVer     = (string) ($this->query('mes') ?? '');
            if (!isset($calendario[$mesVer])) {
                // Por defecto: el mes de HOY si cae en el bimestre; si no (historial),
                // el último mes con algún día ya transcurrido; si no, el primero.
                $mesVer = (string) array_key_first($calendario);
                foreach ($calendario as $clave => $mes) {
                    if ($mes['dias'][0]['marcable']) {
                        $mesVer = $clave;
                    }
                }
                if (isset($calendario[date('Y-m')])) {
                    $mesVer = date('Y-m');
                }
            }
            $motivosM = new AsistenciaMotivoModel();
            $ancla    = $motivosM->anclaDelPeriodo((int) $periodoVer['id']);
            $ids      = array_column($estudiantes ?? [], 'matricula_id');
            $incid    = $this->model->incidenciasDe($ids, (int) $periodoVer['id'], !$editable);
            // Cada estudiante, cada día (09/10/2026, migración 077): sin cubrir
            // por día, para el ⚠ del encabezado, el pie y el aviso de hoy.
            $pendientes = $jornadasM->pendientesPorDia($seccionId, $periodoVer, $ids);
            $hoy        = date('Y-m-d');
            $avisoHoy   = null;
            if ($editable && isset($pendientes[$hoy])) {
                $avisoHoy = ['fecha' => $hoy, 'faltan' => $pendientes[$hoy], 'faltas' => 0, 'tardanzas' => 0];
                foreach ($incid as $dias) {
                    $t = $dias[$hoy]['tipo'] ?? null;
                    if ($t === 'F' || $t === 'FJ') { $avisoHoy['faltas']++; }
                    if ($t === 'T' || $t === 'TJ') { $avisoHoy['tardanzas']++; }
                }
            }
            $fechas = [
                'calendario' => $calendario,
                'mesVer'     => $mesVer,
                'incidencias'=> $incid,
                // ✓ propios (077). Mismo corte que la lista: en solo lectura la
                // vista solo los pinta en estudiantes CONFIRMADOS.
                'presencias' => $jornadasM->presenciasDe($ids, (int) $periodoVer['id']),
                'pendientes' => $pendientes,
                'avisoHoy'   => $avisoHoy,
                'motivos'    => $motivosM->vigentes($ancla),
                // ANCLA del bimestre (08/10/2026, migración 076): leyenda de cómo
                // se cuenta una FJ e icono de documento solo en FJ/TJ con ella.
                'motivoPrincipal'   => $motivosM->nombreDe($ancla),
                'motivoPrincipalId' => $ancla,
                'progreso'   => $this->model->getProgresoPorSeccion((int) $periodoVer['id'])[$seccionId]
                                ?? ['esperados' => 0, 'registrados' => 0],
                'jornadas'   => $jornadasM->tomadasDe($seccionId, (int) $periodoVer['id']),
                'sinTomar'   => array_keys(array_filter($pendientes, static fn(int $n): bool => $n > 0)),
                'hoy'        => $hoy,
                // No se bloquea antes del último día del bimestre (09/10/2026).
                'terminado'  => AsistenciaModel::bimestreTerminado($periodoVer),
                'finDdmm'    => AsistenciaModel::ddmm((string) $periodoVer['fecha_fin']),
            ];
        }

        $this->view('admin/asistencia/seccion', [
            'titulo'       => 'Asistencia — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'      => $seccion,
            'periodoVer'   => $periodoVer,
            'periodosNav'  => $periodosNav,
            'soloLectura'  => $soloLectura,
            'cierre'       => $cierre,
            'firmas'       => $firmas,
            'estudiantes'  => $estudiantes ?? [],
            // Los totales salen del MISMO roster que se pinta (punto unico en el
            // modelo), no de una consulta paralela que podria contar otras filas.
            'totales'      => AsistenciaModel::totalesIncidencias($estudiantes ?? []),
            'topeMax'      => self::TOPE_MAX,
            'porFechas'    => $porFechas,
            'fechas'       => $fechas,
            'page_scripts' => $editable ? [$porFechas ? 'asistencia-fechas' : 'asistencia'] : [],
        ]);
    }

    // GET /admin/asistencia/{seccion_id}/justificaciones   (?periodo={id})
    // Detalle de las JUSTIFICACIONES de la sección (09/10/2026): cuántas por tipo,
    // por motivo y por estudiante, y cuáles están AUTORIZADAS (FJ con el motivo
    // principal). Mismos roles y alcance que la tabla. Solo lectura: editable →
    // el BORRADOR (marcando lo sin confirmar); historial o bloqueada → lo OFICIAL.
    public function justificaciones(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $seccionId = (int) $seccionId;
        [$periodoVer, $soloLectura, $periodosNav] = $this->resolverPeriodoVista($seccionId);

        $seccion = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        $porFechas = $periodoVer !== null && (int) ($periodoVer['asistencia_por_fechas'] ?? 0) === 1;
        $cierre = $resumen = null;
        $motivoPrincipal = null;
        if ($porFechas) {
            $pid     = (int) $periodoVer['id'];
            $cierre  = $this->model->getCierreVigente($seccionId, $pid);
            $oficial = $soloLectura || $cierre !== null;
            $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $pid, $oficial);
            $motivosM = new AsistenciaMotivoModel();
            $ancla    = $motivosM->anclaDelPeriodo($pid);
            $resumen  = AsistenciaModel::resumenJustificaciones(
                $estudiantes,
                $this->model->incidenciasDe(array_column($estudiantes, 'matricula_id'), $pid, $oficial),
                $ancla
            );
            $motivoPrincipal = $motivosM->nombreDe($ancla);
        }

        $this->view('admin/asistencia/justificaciones', [
            'titulo'          => 'Justificaciones — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'         => $seccion,
            'periodoVer'      => $periodoVer,
            'periodosNav'     => $periodosNav,
            'soloLectura'     => $soloLectura,
            'cierre'          => $cierre,
            'porFechas'       => $porFechas,
            'resumen'         => $resumen,
            'motivoPrincipal' => $motivoPrincipal,
        ]);
    }

    // GET /admin/asistencia/{seccion_id}/estudiante   (?m={matricula_id})
    // Segunda entrada del registro (28/09/2026): UN estudiante por pantalla, con
    // los 4 contadores grandes y botones −/+, pensada para el celular del
    // auxiliar. Convive con la grilla y guarda por el MISMO endpoint (`guardar`),
    // así que no añade ninguna regla de escritura. Es solo para REGISTRAR: sin
    // bimestre editable, o con la sección ya bloqueada, devuelve a la grilla.
    public function estudiante(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $seccionId = (int) $seccionId;
        $seccion   = $this->buscarSeccion($seccionId);
        if (!$seccion) {
            $this->redirectWithError(url('admin/asistencia'), 'Sección no encontrada.');
        }

        $grilla  = url('admin/asistencia/' . $seccionId);
        $periodo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((bool) $p['editable']) {
                $periodo = $p;
                break;
            }
        }
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
            $this->redirectWithError($grilla, 'La asistencia de esta sección ya fue bloqueada; no se puede editar.');
        }

        $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $pid);
        if (empty($estudiantes)) {
            redirect($grilla);
        }

        $pos = 0;
        $m   = (int) ($this->query('m') ?? 0);
        if ($m) {
            $pos = array_search($m, array_map('intval', array_column($estudiantes, 'matricula_id')), true);
            if ($pos === false) {
                $this->redirectWithError(
                    url('admin/asistencia/' . $seccionId . '/estudiante'),
                    'Ese estudiante no forma parte del registro de asistencia de la sección.'
                );
            }
        }

        $siguiente = $estudiantes[$pos + 1] ?? null;
        $anterior  = $pos > 0 ? $estudiantes[$pos - 1] : null;
        $urlDe     = fn(array $e): string => url('admin/asistencia/' . $seccionId . '/estudiante?m=' . (int) $e['matricula_id']);

        // Por fechas (29/09/2026): calendario del bimestre entero y sus fechas.
        $porFechas = (int) ($periodo['asistencia_por_fechas'] ?? 0) === 1;
        $mid       = (int) $estudiantes[$pos]['matricula_id'];
        $motivosM  = new AsistenciaMotivoModel();
        $ancla     = $porFechas ? $motivosM->anclaDelPeriodo((int) $periodo['id']) : null;

        $this->view('admin/asistencia/estudiante', [
            'titulo'       => 'Asistencia — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion'      => $seccion,
            'periodo'      => $periodo,
            'estudiantes'  => $estudiantes,
            'pos'          => $pos,
            'siguienteUrl' => $siguiente ? $urlDe($siguiente) : $grilla,
            // «← Anterior» (29/09/2026): sin anterior (el primero) no se pinta.
            'anteriorUrl'  => $anterior ? $urlDe($anterior) : null,
            'topeMax'      => self::TOPE_MAX,
            'porFechas'    => $porFechas,
            'calendario'   => $porFechas
                ? AsistenciaModel::calendario($periodo, null, (new AsistenciaJornadaModel())->noLectivos($periodo)) : [],
            'jornadas'     => $porFechas ? (new AsistenciaJornadaModel())->tomadasDe($seccionId, (int) $periodo['id']) : [],
            'dias'         => $porFechas ? ($this->model->incidenciasDe([$mid], (int) $periodo['id'])[$mid] ?? []) : [],
            // ✓ propios del estudiante (077): la celda va con ✓ por ellos o por la lista.
            'presentes'    => $porFechas ? ((new AsistenciaJornadaModel())->presenciasDe([$mid], (int) $periodo['id'])[$mid] ?? []) : [],
            'motivos'      => $porFechas ? $motivosM->vigentes($ancla) : [],
            // ANCLA del bimestre (08/10/2026): leyenda e icono de documento.
            'motivoPrincipal'   => $motivosM->nombreDe($ancla),
            'motivoPrincipalId' => $ancla,
            'page_scripts' => [$porFechas ? 'asistencia-fechas' : 'asistencia', 'registro-estudiante'],
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
        if (!$this->puede($seccionId, (int) $periodoActivo['id'])) {
            $this->forbidden();
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
        // Admin, RA y Dirección imprimen cualquier sección; el auxiliar, solo
        // las que tuvo a su cargo en ESE bimestre.
        if (has_role(ROL_AUXILIAR) && !$this->puede($seccionId, $periodoId)) {
            $this->forbidden();
        }

        $cierre = $this->model->getCierreDetalle($seccionId, $periodoId);
        if (!$cierre) {
            $this->redirectWithError(
                url('admin/asistencia/' . $seccionId . '?periodo=' . $periodoId),
                'Solo se puede imprimir un registro aprobado y bloqueado.'
            );
        }

        // El registro impreso es el OFICIAL: solo lo confirmado (29/09/2026).
        $estudiantes = $this->model->getEstudiantesConIncidencias($seccionId, $periodoId, true);

        // Por fechas: anexo «Detalle de incidencias» en hoja aparte, con TODAS las
        // fechas (F, FJ, T, TJ) y el motivo de cada justificación. Reemplaza a la
        // columna «Fechas» de la tabla (decisión del usuario, 29/09/2026).
        $porFechas = (int) ($periodo['asistencia_por_fechas'] ?? 0) === 1;
        $detalle   = [];
        if ($porFechas) {
            $incidencias = $this->model->incidenciasDe(array_column($estudiantes, 'matricula_id'), $periodoId, true);
            $detalle     = AsistenciaModel::detalleIncidencias($estudiantes, $incidencias);
        }

        View::setLayout('print');
        $this->view('admin/asistencia/imprimir', [
            'titulo'      => 'Registro de Asistencia — ' . $seccion['grado_nombre'] . ' '
                . $seccion['seccion_nombre'] . ' — ' . $periodo['nombre_display'],
            'seccion'     => $seccion,
            'periodo'     => $periodo,
            'estudiantes' => $estudiantes,
            'cierre'      => $cierre,
            'firmas'      => $this->aux->firmasDelRegistro($seccionId, $periodoId, (int) $cierre['ra_bloqueado_por']),
            'institucion' => config('institucion'),
            'porFechas'   => $porFechas,
            'detalle'     => $detalle,
            // Ancla del bimestre (076): icono y nota de la columna FJ.
            'motivoPrincipal' => $porFechas
                ? (new AsistenciaMotivoModel())->nombreDe((new AsistenciaMotivoModel())->anclaDelPeriodo($periodoId)) : null,
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
        if (!$this->puede($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Esta sección no está a tu cargo en este bimestre.'], 403);
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
        // En un bimestre POR FECHAS los 4 números se calculan de las fechas
        // (migración 069): este guardado de números sueltos ya no aplica.
        if ($this->model->periodoPorFechas($periodoId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Este bimestre se registra por fechas. Recarga la página.',
            ], 409);
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

    // ── ASISTENCIA POR FECHAS (29/09/2026, migración 069) ─────────

    /**
     * GUARDIÁN COMÚN de las escrituras de UN estudiante por fechas (`dia` y
     * `confirmar`): CSRF, periodo editable y por fechas, sección a cargo
     * (`puede`), roster y sección no bloqueada. Responde el JSON de error y
     * corta si algo falla.
     *
     * @return array{matricula:int, periodo:int, usuario:int}
     */
    private function escrituraFechasValidada(): array
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $this->validateCsrf();

        $matriculaId = (int) $this->input('matricula_id');
        $periodoId   = (int) $this->input('periodo_id');

        if (!$matriculaId || !$periodoId) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }
        if (!$this->model->periodoEditable($periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'El periodo no está disponible para edición.'], 403);
        }
        if (!$this->model->periodoPorFechas($periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Este bimestre no se registra por fechas.'], 409);
        }
        $seccionId = $this->model->seccionDeMatricula($matriculaId);
        if ($seccionId === null) {
            $this->json(['success' => false, 'mensaje' => 'Matrícula no encontrada.'], 404);
        }
        if (!$this->puede($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Esta sección no está a tu cargo en este bimestre.'], 403);
        }
        if (!$this->model->matriculaEnRoster($matriculaId)) {
            $this->json([
                'success' => false,
                'mensaje' => 'Esta matrícula no forma parte del registro de asistencia de la sección.',
            ], 403);
        }
        if ($this->model->getCierreVigente($seccionId, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'La asistencia de esta sección ya fue bloqueada; no se puede editar.'], 403);
        }

        return ['matricula' => $matriculaId, 'periodo' => $periodoId, 'usuario' => (int) Session::user()['id'],
                'seccion' => $seccionId];
    }

    // POST /admin/asistencia/dia  (AJAX — AUTOGUARDADO de un día, BORRADOR)
    // fecha=AAAA-MM-DD · tipo=F|FJ|T|TJ o vacío (desmarcar) · motivo_id opcional (FJ/TJ).
    public function dia(): void
    {
        $w = $this->escrituraFechasValidada();

        $fecha = trim((string) $this->input('fecha'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->json(['success' => false, 'mensaje' => 'Fecha no válida.'], 400);
        }
        $tipo = trim((string) $this->input('tipo'));
        $tipo = $tipo === '' ? null : $tipo;
        if ($tipo !== null && !in_array($tipo, AsistenciaModel::TIPOS, true)) {
            $this->json(['success' => false, 'mensaje' => 'Tipo de incidencia no válido.'], 400);
        }
        $motivo = (int) $this->input('motivo_id');

        $res = $this->model->marcarDia($w['matricula'], $w['periodo'], $fecha, $tipo, $motivo ?: null, $w['usuario']);
        // Días con estudiantes sin marcar (077): una marca individual puede
        // completar un día, así que el pie de la grilla se refresca con esto.
        $periodo = $this->model->periodo($w['periodo']);
        $this->json([
            'success'    => $res['ok'],
            'mensaje'    => $res['mensaje'],
            'contadores' => $res['contadores'] ?? null,
            'sin_tomar'  => $periodo ? count((new AsistenciaJornadaModel())->diasSinTomar($w['seccion'], $periodo)) : null,
        ], $res['ok'] ? 200 : 400);
    }

    // POST /admin/asistencia/{seccion_id}/jornada  (AJAX — LISTA DEL DÍA, 30/09/2026)
    // fecha=AAAA-MM-DD. «Pasar lista» deja con ✓ a todos los que no tienen marca
    // propia ese día. Sin «deshacer» desde el 09/10/2026 (migración 077): un día se
    // corrige marcando a cada estudiante.
    public function jornada(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;

        $fecha = trim((string) $this->input('fecha'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $this->json(['success' => false, 'mensaje' => 'Datos no válidos.'], 400);
        }

        $periodo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((bool) $p['editable']) {
                $periodo = $p;
                break;
            }
        }
        if ($periodo === null || !$this->model->periodoEditable((int) $periodo['id'])) {
            $this->json(['success' => false, 'mensaje' => 'El periodo no está disponible para edición.'], 403);
        }
        $pid = (int) $periodo['id'];
        if ((int) ($periodo['asistencia_por_fechas'] ?? 0) !== 1) {
            $this->json(['success' => false, 'mensaje' => 'Este bimestre no se registra por fechas.'], 409);
        }
        if (!$this->buscarSeccion($seccionId)) {
            $this->json(['success' => false, 'mensaje' => 'Sección no encontrada.'], 404);
        }
        if (!$this->puede($seccionId, $pid)) {
            $this->json(['success' => false, 'mensaje' => 'Esta sección no está a tu cargo en este bimestre.'], 403);
        }
        if ($this->model->getCierreVigente($seccionId, $pid)) {
            $this->json(['success' => false, 'mensaje' => 'La asistencia de esta sección ya fue bloqueada; no se puede editar.'], 403);
        }

        $jornadas = new AsistenciaJornadaModel();
        $res = $jornadas->pasarLista($seccionId, $periodo, $fecha, (int) Session::user()['id']);

        $this->json([
            'success'   => $res['ok'],
            'mensaje'   => $res['mensaje'],
            'tomada'    => $jornadas->estaTomada($seccionId, $pid, $fecha),
            'sin_tomar' => count($jornadas->diasSinTomar($seccionId, $periodo)),
        ], $res['ok'] ? 200 : 400);
    }

    // POST /admin/asistencia/confirmar  (AJAX — visto bueno de UN estudiante)
    public function confirmar(): void
    {
        $w   = $this->escrituraFechasValidada();
        $res = $this->model->confirmar($w['matricula'], $w['periodo'], $w['usuario']);
        $this->json([
            'success'    => $res['ok'],
            'mensaje'    => $res['mensaje'],
            'contadores' => $res['ok'] ? $this->model->contadoresDe($w['matricula'], $w['periodo']) : null,
        ], $res['ok'] ? 200 : 400);
    }

    // POST /admin/asistencia/{seccion_id}/confirmar-todo  («Confirmar todo»)
    public function confirmarTodo(string $seccionId): void
    {
        $this->requireRole(self::ROLES_REGISTRAN);
        $this->validateCsrf();
        $seccionId = (int) $seccionId;
        $grilla    = url('admin/asistencia/' . $seccionId);

        $periodo = null;
        foreach ($this->model->listarPeriodosActivos() as $p) {
            if ((bool) $p['editable']) {
                $periodo = $p;
                break;
            }
        }
        if ($periodo === null) {
            $this->redirectWithError($grilla, 'No hay periodo abierto para edición.');
        }
        $pid = (int) $periodo['id'];
        if (!$this->puede($seccionId, $pid)) {
            $this->forbidden();
        }
        if ((int) $periodo['asistencia_por_fechas'] !== 1) {
            $this->redirectWithError($grilla, 'Este bimestre no se registra por fechas.');
        }
        if ($this->model->getCierreVigente($seccionId, $pid)) {
            $this->redirectWithError($grilla, 'La asistencia de esta sección ya fue bloqueada; no se puede editar.');
        }

        $res = $this->model->confirmarSeccion($seccionId, $pid, (int) Session::user()['id']);
        $msg = "{$res['confirmados']} estudiante(s) confirmado(s).";
        if ($res['sin_motivo'] !== []) {
            $this->redirectWithError($grilla, $msg . ' Falta el motivo de alguna justificación en: '
                . implode(', ', $res['sin_motivo']) . '.');
        }
        $this->redirectWithSuccess($grilla, $msg);
    }
}
