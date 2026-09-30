<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\CargaAcademicaModel;
use App\Models\DirectorEbrModel;
use App\Models\HorarioModel;
use Core\Session;
use Core\View;

class CargaAcademicaController extends BaseController
{
    /**
     * Quien ESCRIBE. Los directores entran a este controlador y VEN, pero desde
     * el 24/08/2026 no operan: su rol es de supervision en solo lectura. Se
     * valida en cada metodo de escritura (no ocultando el boton en la vista):
     * esconder la UI no es control de acceso.
     */
    private const ROLES_ESCRIBEN = ['admin', 'registro_academico'];

    private CargaAcademicaModel $model;

    private const DIAS  = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes'];
    private const ROLES = ['admin', 'registro_academico', ...ROLES_DIRECCION];

    public function __construct()
    {
        $this->requireRole(self::ROLES);
        $this->model = new CargaAcademicaModel();
    }

    // GET /director/cargas
    public function index(): void
    {
        $secciones = $this->model->listarSeccionesConCargas();
        $porNivel  = [];
        foreach ($secciones as $s) {
            $porNivel[$s['nivel_nombre']][] = $s;
        }

        $this->view('director/cargas/index', [
            'titulo'   => 'Cargas Académicas',
            'porNivel' => $porNivel,
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
        ]);
    }

    // GET /director/cargas/seccion/{seccion_id}
    public function porSeccion(string $seccionId): void
    {
        $seccionId = (int) $seccionId;
        $seccion   = $this->model->findSeccion($seccionId);

        if (!$seccion) {
            $this->redirectWithError(url('director/cargas'), 'Sección no encontrada.');
        }

        $cargas = $this->model->listarPorSeccion($seccionId);

        // Sección unidocente: se agrupa por ÁREA (misma lectura que el docente
        // de aula, que ve el área consolidada). Cada grupo suma las horas y
        // reúne los horarios de sus cargas — el horario real del área puede
        // vivir en cualquiera de sus subárea-cargas (las demás quedan "sin
        // horario propio"). En polidocente la vista lista filas planas.
        $grupos = null;
        if (!empty($seccion['es_unidocente'])) {
            $grupos = [];
            foreach ($cargas as $c) {
                $aid = (int) $c['area_real_id'];
                if (!isset($grupos[$aid])) {
                    $grupos[$aid] = [
                        'area_nombre' => $c['area_nombre'],
                        'cargas'      => [],
                        'total_horas' => 0,
                        'horarios'    => [],
                    ];
                }
                $grupos[$aid]['cargas'][] = $c;
                $grupos[$aid]['total_horas'] += (int) $c['horas_semanales'];
                if (!empty($c['horario_resumen'])) {
                    $grupos[$aid]['horarios'][] = $c['horario_resumen'];
                }
            }
        }

        $this->view('director/cargas/seccion', [
            // Vista de SOLO LECTURA para los directores: sin controles.
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
            'titulo'  => 'Cargas — ' . $seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre'],
            'seccion' => $seccion,
            'cargas'  => $cargas,
            'grupos'  => $grupos,
        ]);
    }

    /**
     * GET /director/cargas/seccion/{seccion_id}/horario
     *
     * Horario semanal de la SECCIÓN en grilla de doble entrada (24/08/2026).
     * Hasta hoy `/director/cargas/seccion/{id}` solo mostraba `horario_resumen`,
     * un string por carga: la grilla por sección no existía para ningún rol,
     * aunque las 23 secciones tienen horario cargado (444 sesiones).
     *
     * Consume `HorarioModel` y el parcial `shared/_horario-grilla.php`, los
     * MISMOS que el horario del docente. Es lectura: la ven también los
     * directores.
     */
    public function horarioSeccion(string $seccionId): void
    {
        // El armado del documento es de HorarioModel (punto único compartido con
        // el horario de sección del auxiliar académico, 28/09/2026).
        $datos = (new HorarioModel())->documentoSeccion((int) $seccionId);

        if ($datos === null) {
            $this->redirectWithError(url('director/cargas'), 'Sección no encontrada.');
        }

        View::setLayout('print');
        $this->view('director/horario-seccion', array_merge($datos, [
            'titulo' => 'Horario — ' . $datos['seccion']['grado_nombre'] . ' ' . $datos['seccion']['seccion_nombre'],
        ]));
    }

    // GET /director/cargas/crear
    public function create(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $preselSeccionId = (int) ($_GET['seccion_id'] ?? 0);
        $preselDocenteId = 0;

        if ($preselSeccionId > 0) {
            $seccion = $this->model->findSeccion($preselSeccionId);
            if ($seccion && $seccion['es_unidocente'] && $seccion['tutor_id']) {
                $preselDocenteId = (int) $seccion['tutor_id'];
            }
        }

        $this->view('director/cargas/crear', $this->datosFormulario() + [
            'titulo'          => 'Nueva Carga Académica',
            'page_scripts'    => ['cargas'],
            'preselSeccionId' => $preselSeccionId,
            'preselDocenteId' => $preselDocenteId,
        ]);
    }

    // POST /director/cargas/crear
    public function store(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();

        [$datosCarga, $sesiones, $error] = $this->procesarFormulario();

        if ($error) {
            $this->redirectWithError(url('director/cargas/crear'), $error);
        }

        if ($this->model->existeCarga(
            $datosCarga['seccion_id'],
            $datosCarga['subarea_id'],
            $datosCarga['area_id']
        )) {
            $this->redirectWithError(
                url('director/cargas/crear'),
                'Ya existe una carga para esta sección con el área/subárea seleccionada.'
            );
        }

        $conflictos = $this->model->verificarSolapes($sesiones);
        if ($conflictos) {
            $this->redirectWithError(url('director/cargas/crear'), $this->msgConflicto($conflictos[0]));
        }

        try {
            $this->model->crearConHorario($datosCarga, $sesiones);
        } catch (\Exception $e) {
            log_error('Error creando carga', ['msg' => $e->getMessage()]);
            $this->redirectWithError(
                url('director/cargas/crear'),
                'Error al registrar la carga. Verifica que no haya conflictos de horario.'
            );
        }

        $this->redirectWithSuccess(
            url('director/cargas/seccion/' . $datosCarga['seccion_id']),
            'Carga académica registrada correctamente.'
        );
    }

    // GET /director/cargas/{id}/editar
    public function edit(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $carga = $this->model->findById((int) $id);
        if (!$carga) {
            $this->redirectWithError(url('director/cargas'), 'Carga no encontrada.');
        }

        // sesionesMap por día como LISTA de rangos (una carga puede tener varios
        // bloques no consecutivos el mismo día). getSesionesDeCarga ya ordena por
        // día y hora_inicio, así que los rangos llegan ordenados.
        $sesiones    = $this->model->getSesionesDeCarga((int) $id);
        $sesionesMap = [];
        foreach ($sesiones as $s) {
            $sesionesMap[$s['dia_semana']][] = [
                'hora_inicio' => $s['hora_inicio'],
                'hora_fin'    => $s['hora_fin'],
            ];
        }

        $this->view('director/cargas/editar', $this->datosFormulario($id) + [
            'titulo'       => 'Editar Carga Académica',
            'carga'        => $carga,
            'sesionesMap'  => $sesionesMap,
            'page_scripts' => ['cargas'],
        ]);
    }

    // POST /director/cargas/{id}/editar
    public function update(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id = (int) $id;

        $carga = $this->model->findById($id);
        if (!$carga) {
            $this->redirectWithError(url('director/cargas'), 'Carga no encontrada.');
        }

        [$datosCarga, $sesiones, $error] = $this->procesarFormulario();

        if ($error) {
            $this->redirectWithError(url("director/cargas/{$id}/editar"), $error);
        }

        // Blindaje: el cambio de docente NO se hace por edicion (perderia la
        // auditoria del trabajo del saliente). Se deriva al proceso oficial de
        // Reemplazo de docente, que congela un snapshot antes de reasignar.
        if ((int) $datosCarga['docente_id'] !== (int) $carga['docente_id']) {
            $this->redirectWithError(
                url("director/cargas/{$id}/reemplazar"),
                'Para cambiar el docente usa el proceso de Reemplazo de docente: '
                . 'conserva la auditoria del trabajo del saliente. Aqui solo se edita '
                . 'area, subarea y horario.'
            );
        }

        if ($this->model->existeCarga(
            $datosCarga['seccion_id'],
            $datosCarga['subarea_id'],
            $datosCarga['area_id'],
            $id
        )) {
            $this->redirectWithError(
                url("director/cargas/{$id}/editar"),
                'Ya existe otra carga para esta sección con el área/subárea seleccionada.'
            );
        }

        $conflictos = $this->model->verificarSolapes($sesiones, $id);
        if ($conflictos) {
            $this->redirectWithError(
                url("director/cargas/{$id}/editar"),
                $this->msgConflicto($conflictos[0])
            );
        }

        try {
            $this->model->actualizarConHorario($id, $datosCarga, $sesiones);
        } catch (\Exception $e) {
            log_error('Error actualizando carga', ['id' => $id, 'msg' => $e->getMessage()]);
            $this->redirectWithError(
                url("director/cargas/{$id}/editar"),
                'Error al actualizar la carga académica.'
            );
        }

        $this->redirectWithSuccess(url('director/cargas'), 'Carga académica actualizada correctamente.');
    }

    // POST /director/cargas/{id}/estado
    public function toggleEstado(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id = (int) $id;

        $carga = $this->model->findById($id);
        if (!$carga) {
            $this->redirectWithError(url('director/cargas'), 'Carga no encontrada.');
        }

        // Blindaje al DESACTIVAR una carga que ya tiene trabajo (criterios/notas)
        // en el bimestre activo: se permite, pero exige un motivo (confirmacion)
        // y deja traza. Cubre el caso legitimo (carga creada por error con notas
        // de prueba) sin abrir un atajo silencioso. Para CAMBIAR de docente el
        // camino es Reemplazo, no desactivar.
        $desactivando = $carga['estado'] === 'activa';
        if ($desactivando && $this->model->tieneTrabajoEnPeriodoActivo($id)) {
            $motivo = trim((string) $this->input('motivo', ''));
            if ($motivo === '') {
                $this->redirectWithError(
                    url('director/cargas/seccion/' . (int) $carga['seccion_id']),
                    'Esta carga ya tiene notas en el bimestre activo. Para desactivarla '
                    . 'indica un motivo en la confirmacion. Si solo cambia el docente, '
                    . 'usa Reemplazo de docente.'
                );
            }
            log_error('Carga desactivada con trabajo en bimestre activo', [
                'carga_id'   => $id,
                'seccion_id' => (int) $carga['seccion_id'],
                'usuario_id' => Session::user()['id'] ?? null,
                'motivo'     => $motivo,
            ]);
        }

        $this->model->toggleEstado($id);
        $nuevo = $carga['estado'] === 'activa' ? 'desactivada' : 'activada';
        $this->redirectWithSuccess(url('director/cargas'), "Carga {$nuevo} correctamente.");
    }

    // ── Métodos privados ──────────────────────────────────────

    private function datosFormulario(int $excludeCargaId = 0): array
    {
        // area_id y subarea_id ya ocupados por seccion en el año activo.
        // Se excluye la carga actual al editar (excludeCargaId > 0).
        $rawOcupadas = $this->model->query("
            SELECT ca.seccion_id, ca.area_id, ca.subarea_id
            FROM cargas_academicas ca
            INNER JOIN secciones s        ON s.id  = ca.seccion_id
            INNER JOIN anios_academicos a ON a.id  = s.anio_id
            WHERE ca.estado  = 'activa'
              AND a.estado   IN ('planificado', 'activo')
              AND ca.id      != ?
        ", [$excludeCargaId]);

        $ocupadas = [];
        foreach ($rawOcupadas as $c) {
            $sid = (int) $c['seccion_id'];
            if (!isset($ocupadas[$sid])) {
                $ocupadas[$sid] = ['areas' => [], 'subareas' => []];
            }
            if ($c['area_id'] !== null) {
                $ocupadas[$sid]['areas'][] = (int) $c['area_id'];
            }
            if ($c['subarea_id'] !== null) {
                $ocupadas[$sid]['subareas'][] = (int) $c['subarea_id'];
            }
        }

        $rawHorarios = $this->model->query("
            SELECT ca.seccion_id, bh.dia_semana,
                   MAX(bh.hora_fin) AS ultima_fin
            FROM cargas_academicas ca
            INNER JOIN sesiones_horario sh ON sh.carga_id = ca.id
            INNER JOIN bloques_horario bh  ON bh.id       = sh.bloque_id
            INNER JOIN secciones s         ON s.id        = ca.seccion_id
            INNER JOIN anios_academicos a  ON a.id        = s.anio_id
            WHERE ca.estado = 'activa'
              AND a.estado  IN ('planificado', 'activo')
              AND ca.id     != ?
            GROUP BY ca.seccion_id, bh.dia_semana
        ", [$excludeCargaId]);

        $horarios = [];
        foreach ($rawHorarios as $h) {
            $sid = (int) $h['seccion_id'];
            $horarios[$sid][$h['dia_semana']] = substr($h['ultima_fin'], 0, 5);
        }

        $rawBloques = $this->model->query("
            SELECT ca.docente_id, bh.dia_semana,
                   bh.hora_inicio, bh.hora_fin
            FROM cargas_academicas ca
            INNER JOIN sesiones_horario sh ON sh.carga_id = ca.id
            INNER JOIN bloques_horario bh  ON bh.id       = sh.bloque_id
            INNER JOIN secciones s         ON s.id        = ca.seccion_id
            INNER JOIN anios_academicos a  ON a.id        = s.anio_id
            WHERE ca.estado = 'activa'
              AND a.estado  IN ('planificado', 'activo')
              AND ca.id     != ?
            ORDER BY ca.docente_id, bh.dia_semana, bh.hora_inicio
        ", [$excludeCargaId]);

        $bloquesDocentes = [];
        foreach ($rawBloques as $b) {
            $did   = (int) $b['docente_id'];
            $dia   = $b['dia_semana'];
            $rango = substr($b['hora_inicio'], 0, 5) . '-' . substr($b['hora_fin'], 0, 5);
            $bloquesDocentes[$did][$dia][] = $rango;
        }

        // Bloques COMPLETOS por seccion/dia (para el tooltip de huecos libres).
        // Analogo a $bloquesDocentes pero agrupado por seccion; $horarios (arriba)
        // conserva solo el MAX(hora_fin) que alimenta el hint "Libre desde".
        $rawBloquesSeccion = $this->model->query("
            SELECT ca.seccion_id, bh.dia_semana,
                   bh.hora_inicio, bh.hora_fin
            FROM cargas_academicas ca
            INNER JOIN sesiones_horario sh ON sh.carga_id = ca.id
            INNER JOIN bloques_horario bh  ON bh.id       = sh.bloque_id
            INNER JOIN secciones s         ON s.id        = ca.seccion_id
            INNER JOIN anios_academicos a  ON a.id        = s.anio_id
            WHERE ca.estado = 'activa'
              AND a.estado  IN ('planificado', 'activo')
              AND ca.id     != ?
            ORDER BY ca.seccion_id, bh.dia_semana, bh.hora_inicio
        ", [$excludeCargaId]);

        $bloquesSeccion = [];
        foreach ($rawBloquesSeccion as $b) {
            $sid   = (int) $b['seccion_id'];
            $dia   = $b['dia_semana'];
            $rango = substr($b['hora_inicio'], 0, 5) . '-' . substr($b['hora_fin'], 0, 5);
            $bloquesSeccion[$sid][$dia][] = $rango;
        }

        // Hora oficial de inicio de clases por año (limite inferior del tooltip).
        $rawInicio = $this->model->query("
            SELECT c.anio_id, TIME_FORMAT(c.hora_inicio_clases, '%H:%i') AS inicio
            FROM configuracion_horario c
            INNER JOIN anios_academicos a ON a.id = c.anio_id
            WHERE a.estado IN ('planificado', 'activo')
        ");

        $horaInicioClases = [];
        foreach ($rawInicio as $r) {
            if ($r['inicio'] !== null) {
                $horaInicioClases[(int) $r['anio_id']] = $r['inicio'];
            }
        }

        return [
            'secciones'        => $this->model->listarSecciones(),
            'docentes'         => $this->model->listarDocentes(),
            'areas'            => $this->model->listarAreas(),
            'subareas'         => $this->model->listarSubareas(),
            'dias'             => self::DIAS,
            'ocupadas'         => $ocupadas,
            'horarios'         => $horarios,
            'bloquesDocentes'  => $bloquesDocentes,
            'bloquesSeccion'   => $bloquesSeccion,
            'horaInicioClases' => $horaInicioClases,
        ];
    }

    /**
     * Lee el POST, valida, resuelve bloques y devuelve [datosCarga, sesiones, error].
     */
    private function procesarFormulario(): array
    {
        $seccionId      = (int) $this->input('seccion_id', 0);
        $docenteId      = (int) $this->input('docente_id', 0);
        $areaId         = (int) $this->input('area_id', 0);
        $subareaId      = (int) $this->input('subarea_id', 0);
        $diasSeleccionados = $_POST['dias_check'] ?? [];
        $horasInicio    = $_POST['hora_inicio']  ?? [];
        $horasFin       = $_POST['hora_fin']     ?? [];

        if ($seccionId <= 0) return [null, null, 'Debes seleccionar una sección.'];
        if ($docenteId <= 0) return [null, null, 'Debes seleccionar un docente.'];
        if ($areaId    <= 0) return [null, null, 'Debes seleccionar un área.'];

        $area = $this->model->queryOne("SELECT id, tipo FROM areas WHERE id = ? LIMIT 1", [$areaId]);
        if (!$area) return [null, null, 'Área no válida.'];

        $vincSubareaId = null;
        $vincAreaId    = null;

        if ($area['tipo'] === 'con_subareas') {
            if ($subareaId <= 0) return [null, null, 'El área seleccionada requiere elegir una subárea.'];
            $vincSubareaId = $subareaId;
        } else {
            $vincAreaId = $areaId;
        }

        $seccion = $this->model->queryOne(
            "SELECT anio_id, tutor_id FROM secciones WHERE id = ? LIMIT 1",
            [$seccionId]
        );
        if (!$seccion) return [null, null, 'Sección no válida.'];
        $anioId = (int) $seccion['anio_id'];

        // Tutoría (TOE): es la hora del tutor con su sección; solo el tutor de
        // esa sección puede dictarla.
        if ($area['tipo'] === 'tutoria') {
            $tutorId = (int) ($seccion['tutor_id'] ?? 0);
            if ($tutorId === 0) {
                return [null, null, 'La sección no tiene tutor asignado. Asigna el tutor antes de crear la carga de Tutoría.'];
            }
            if ($docenteId !== $tutorId) {
                return [null, null, 'La carga de Tutoría solo puede asignarse al tutor de la sección.'];
            }
        }

        $datosCarga = [
            'docente_id'      => $docenteId,
            'seccion_id'      => $seccionId,
            'anio_id'         => $anioId,
            'subarea_id'      => $vincSubareaId,
            'area_id'         => $vincAreaId,
            'horas_semanales' => 0,
        ];

        // Sin horario propio: la carga se dicta dentro del horario de otra
        // carga del área (subáreas que comparten bloques con el mismo docente)
        // o su horario aún no se registra. Se guarda sin sesiones; los días
        // del POST se ignoran (el JS los deshabilita al marcar el checkbox).
        if ((int) $this->input('sin_horario', 0) === 1) {
            return [$datosCarga, [], null];
        }

        if (empty($diasSeleccionados)) {
            return [null, null, 'Debes seleccionar al menos un día con horario.'];
        }

        $sesiones        = [];
        $horasAcademicas = 0;
        $configId        = null;
        $duracionHora    = 45;

        foreach (self::DIAS as $dia) {
            if (!in_array($dia, $diasSeleccionados, true)) continue;

            // N bloques por día: los inputs llegan como arreglos paralelos
            // hora_inicio[dia][] / hora_fin[dia][]. Una fila totalmente vacía se
            // ignora (el docente agregó un bloque y no lo usó); una a medias es error.
            $inicios = (array) ($horasInicio[$dia] ?? []);
            $fines   = (array) ($horasFin[$dia]    ?? []);

            $rangosDia = [];
            foreach ($inicios as $idx => $hi) {
                $horaInicio = trim((string) $hi);
                $horaFin    = trim((string) ($fines[$idx] ?? ''));

                if ($horaInicio === '' && $horaFin === '') continue;
                if ($horaInicio === '' || $horaFin === '') {
                    return [null, null, "Falta hora de inicio o fin en un bloque del día " . ucfirst($dia) . "."];
                }
                if ($horaFin <= $horaInicio) {
                    return [null, null, "La hora de fin debe ser mayor a la de inicio (" . ucfirst($dia) . ")."];
                }
                $rangosDia[] = ['inicio' => $horaInicio, 'fin' => $horaFin];
            }

            if (empty($rangosDia)) {
                return [null, null, "Debes ingresar al menos un bloque horario para el día " . ucfirst($dia) . "."];
            }

            // Regla (a): los rangos del MISMO día no se pueden solapar entre sí.
            if ($sol = $this->solapeInterno($rangosDia)) {
                return [null, null, "El día " . ucfirst($dia) . " tiene bloques que se solapan entre sí ({$sol['a']} y {$sol['b']})."];
            }

            if ($configId === null) {
                $configId     = $this->model->getOrCreateConfiguracion($anioId);
                $duracionHora = $this->model->getDuracionHoraMin($configId);
            }

            foreach ($rangosDia as $r) {
                [$h1, $m1] = array_map('intval', explode(':', $r['inicio']));
                [$h2, $m2] = array_map('intval', explode(':', $r['fin']));
                $minutos = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
                // Horas académicas: redondeo POR BLOQUE según la duración de la
                // hora configurada (45→1, 90→2). Misma regla que el horario
                // imprimible y la migración 030.
                $horasAcademicas += (int) round($minutos / $duracionHora);

                $bloqueId = $this->model->getOrCreateBloque($configId, $dia, $r['inicio'], $r['fin']);

                // Se arrastran dia/hora/config para que verificarSolapes pueda
                // comparar por tiempo (no solo por bloque_id). crearConHorario /
                // actualizarConHorario solo leen bloque_id/seccion_id/docente_id.
                $sesiones[] = [
                    'bloque_id'   => $bloqueId,
                    'seccion_id'  => $seccionId,
                    'docente_id'  => $docenteId,
                    'dia'         => $dia,
                    'hora_inicio' => $r['inicio'],
                    'hora_fin'    => $r['fin'],
                    'config_id'   => $configId,
                ];
            }
        }

        if (empty($sesiones)) {
            return [null, null, 'Debes seleccionar al menos un día con horario.'];
        }

        $datosCarga['horas_semanales'] = $horasAcademicas;

        return [$datosCarga, $sesiones, null];
    }

    /**
     * Detecta el primer par de rangos que se solapan dentro de un mismo día
     * (mismo envío). Solape ESTRICTO: contiguos no cuentan. Devuelve los dos
     * rangos en conflicto como texto, o null si no hay solape.
     */
    private function solapeInterno(array $rangos): ?array
    {
        $n = count($rangos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $rangos[$i];
                $b = $rangos[$j];
                if ($a['inicio'] < $b['fin'] && $b['inicio'] < $a['fin']) {
                    return [
                        'a' => $a['inicio'] . '-' . $a['fin'],
                        'b' => $b['inicio'] . '-' . $b['fin'],
                    ];
                }
            }
        }
        return null;
    }

    private function msgConflicto(array $c): string
    {
        $dia = ucfirst($c['dia_semana']);
        if ($c['tipo_conflicto'] === 'seccion') {
            return "Conflicto de horario: la sección ya tiene una clase el {$dia} de {$c['hora_inicio']} a {$c['hora_fin']}.";
        }
        return "Conflicto de horario: el docente ya tiene otra clase el {$dia} de {$c['hora_inicio']} a {$c['hora_fin']}.";
    }
}
