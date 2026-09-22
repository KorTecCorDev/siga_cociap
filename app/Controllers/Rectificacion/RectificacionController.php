<?php

namespace App\Controllers\Rectificacion;

use App\Controllers\BaseController;
use App\Models\RectificacionModel;
use App\Models\CalificacionModel;
use App\Models\CriterioModel;
use App\Models\OrdenMeritoModel;
use App\Models\TransversalModel;
use App\Models\AsistenciaModel;
use App\Models\ConductaModel;
use Core\Session;

/**
 * RectificacionController
 * Módulo GENERAL de rectificación de calificaciones.
 *
 * Permite a Registro Académico corregir, con auditoría obligatoria, una
 * calificación que YA salió del flujo normal del docente (periodo cerrado
 * y/o competencia bloqueada). El módulo ORQUESTA y AUDITA; la escritura de
 * notas la delega a CalificacionModel (criterios → promedio → conclusión).
 *
 * Control de seguridad (invariante): rol (admin/registro_academico) +
 * estado RECTIFICABLE (RectificacionModel::esRectificable) + motivo
 * obligatorio + traza en `rectificaciones_calificacion`. Si la competencia
 * está abierta y desbloqueada NO se rectifica aquí: se corrige por el flujo
 * del docente.
 */
class RectificacionController extends BaseController
{
    private RectificacionModel $model;
    private CalificacionModel  $calModel;
    private CriterioModel      $critModel;
    private OrdenMeritoModel   $ordenMeritoModel;
    private TransversalModel   $transModel;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico']);
        $this->model            = new RectificacionModel();
        $this->calModel         = new CalificacionModel();
        $this->critModel        = new CriterioModel();
        $this->ordenMeritoModel = new OrdenMeritoModel();
        $this->transModel       = new TransversalModel();
    }

    /**
     * GET /rectificaciones — buscador de estudiante, estudiantes con
     * competencias SIN NOTA en bimestres cerrados (filtrable, 21/09/2026) e
     * historial reciente. Los filtros llegan por GET y el modelo los reduce a
     * enteros; nunca se interpolan.
     */
    public function index(): void
    {
        $filtros = [
            'periodo_id' => (int) $this->query('periodo_id', 0),
            'nivel_id'   => (int) $this->query('nivel_id', 0),
            'grado_id'   => (int) $this->query('grado_id', 0),
            'seccion_id' => (int) $this->query('seccion_id', 0),
        ];

        $this->view('rectificaciones/index', [
            'titulo'       => 'Rectificación de calificaciones',
            'pendientes'   => $this->model->matriculasSinNotasEnCerrados($filtros),
            'filtros'      => $filtros,
            'opciones'     => $this->model->opcionesFiltroPendientes(),
            'historial'    => $this->model->getHistorial(20),
            'page_scripts' => ['buscador-estudiante'],
        ]);
    }

    /** GET /rectificaciones/matricula/{id} — competencias rectificables. */
    public function matricula(string $id): void
    {
        $matriculaId = (int) $id;
        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }

        // Agrupa las competencias rectificables por bimestre para la vista.
        $competencias = $this->model->getCompetenciasRectificables($matriculaId);
        $porPeriodo   = [];
        foreach ($competencias as $c) {
            $pid = (int) $c['periodo_id'];
            if (!isset($porPeriodo[$pid])) {
                $porPeriodo[$pid] = [
                    'periodo_id'     => $pid,
                    'periodo_numero' => (int) $c['periodo_numero'],
                    'periodo_nombre' => $c['periodo_nombre'],
                    'periodo_estado' => $c['periodo_estado'],
                    'items'          => [],
                ];
            }
            $porPeriodo[$pid]['items'][] = $c;
        }

        // Competencias SIN calificación del alumno (cerradas/bloqueadas) →
        // candidatas a calificación EXTRAORDINARIA, agrupadas por bimestre.
        $insertables    = $this->model->getCompetenciasInsertables($matriculaId);
        $porPeriodoIns  = [];
        foreach ($insertables as $c) {
            $pid = (int) $c['periodo_id'];
            if (!isset($porPeriodoIns[$pid])) {
                $porPeriodoIns[$pid] = [
                    'periodo_id'     => $pid,
                    'periodo_numero' => (int) $c['periodo_numero'],
                    'periodo_nombre' => $c['periodo_nombre'],
                    'periodo_estado' => $c['periodo_estado'],
                    'items'          => [],
                ];
            }
            $porPeriodoIns[$pid]['items'][] = $c;
        }

        $this->view('rectificaciones/matricula', [
            'titulo'        => 'Rectificación — ' . $info['nombre_completo'],
            'info'          => $info,
            'porPeriodo'    => array_values($porPeriodo),
            'porPeriodoIns' => array_values($porPeriodoIns),
            'historial'     => $this->model->getHistorial(20, $matriculaId),
        ]);
    }

    /**
     * GET /rectificaciones/extraordinaria?matricula=&carga=&competencia=&periodo=
     * Formulario de CALIFICACIÓN EXTRAORDINARIA: alta de nota (con motivo)
     * a un alumno SIN calificación en una competencia de un bimestre CERRADO.
     * La nota va a boleta y SIAGIE; NO cuenta en el orden de mérito.
     */
    public function extraordinaria(): void
    {
        $matriculaId   = (int) $this->query('matricula');
        $cargaId       = (int) $this->query('carga');
        $competenciaId = (int) $this->query('competencia');
        $periodoId     = (int) $this->query('periodo');

        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }

        // Invariante de seguridad: solo tuplas insertables (sin nota previa,
        // bimestre cerrado, no exonerado, carga de su sección).
        if (!$this->model->esInsertable($matriculaId, $cargaId, $competenciaId, $periodoId)) {
            $this->redirectWithError(
                url('rectificaciones/matricula/' . $matriculaId),
                'Esa competencia no admite calificación extraordinaria (el alumno ya tiene nota, está exonerado, o el bimestre no está cerrado).'
            );
        }

        // Metadatos de la competencia elegida (desde la misma fuente que la lista).
        $meta = null;
        foreach ($this->model->getCompetenciasInsertables($matriculaId) as $c) {
            if ((int) $c['carga_id'] === $cargaId
                && (int) $c['competencia_id'] === $competenciaId
                && (int) $c['periodo_id'] === $periodoId) {
                $meta = $c;
                break;
            }
        }
        if ($meta === null) {
            $this->notFound();
        }

        $this->view('rectificaciones/extraordinaria', [
            'titulo'        => 'Calificación extraordinaria',
            'info'          => $info,
            'meta'          => $meta,
            'cargaId'       => $cargaId,
            'competenciaId' => $competenciaId,
            'periodoId'     => $periodoId,
        ]);
    }

    /**
     * POST /rectificaciones/extraordinaria/guardar
     * Alta de la calificación extraordinaria: criterio único confirmado
     * (extraordinario=1) + nota del alumno + promedio (= la nota) marcado
     * `extraordinaria=1` + conclusión + auditoría tipo 'extraordinaria'.
     * NO regenera el snapshot del mérito: el flag la excluye del ranking,
     * así que el orden vigente no cambia.
     */
    public function guardarExtraordinaria(): void
    {
        $this->validateCsrf();

        $matriculaId   = (int) $this->input('matricula_id');
        $cargaId       = (int) $this->input('carga_id');
        $competenciaId = (int) $this->input('competencia_id');
        $periodoId     = (int) $this->input('periodo_id');
        $motivo        = trim((string) $this->input('motivo', ''));
        $conclusion    = trim((string) $this->input('conclusion', ''));
        $notaRaw       = $this->input('nota', '');
        $usuarioId     = (int) (Session::user()['id'] ?? 0);

        $volverForm = url('rectificaciones/extraordinaria?matricula=' . $matriculaId
            . '&carga=' . $cargaId . '&competencia=' . $competenciaId . '&periodo=' . $periodoId);
        $volverLista = url('rectificaciones/matricula/' . $matriculaId);

        // ── Validaciones de entrada ──────────────────────────────
        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }
        if ($motivo === '') {
            $this->redirectWithError($volverForm, 'El motivo de la calificación extraordinaria es obligatorio.');
        }
        if ($notaRaw === '' || $notaRaw === null || !is_numeric($notaRaw)) {
            $this->redirectWithError($volverForm, 'Ingresa la nota (0-20).');
        }
        $nota = max(0, min(20, (int) $notaRaw));

        // Invariante de seguridad: estado insertable (re-chequeo en el POST).
        if (!$this->model->esInsertable($matriculaId, $cargaId, $competenciaId, $periodoId)) {
            $this->redirectWithError($volverLista,
                'Esa competencia no admite calificación extraordinaria (el alumno ya tiene nota, está exonerado, o la competencia sigue en el flujo del docente).');
        }

        $literal = nota_a_literal($nota);
        if (CalificacionModel::conclusionObligatoria($literal, (string) $info['nivel_codigo']) && $conclusion === '') {
            $this->redirectWithError($volverForm,
                'La conclusión descriptiva es obligatoria para el literal ' . $literal . ' en este nivel.');
        }

        // ── Escritura atómica ────────────────────────────────────
        $this->model->beginTransaction();
        try {
            $this->escribirExtraordinaria(
                $matriculaId, $cargaId, $competenciaId, $periodoId,
                $nota, $conclusion, $motivo, $usuarioId
            );
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al registrar calificación extraordinaria', [
                'matricula' => $matriculaId, 'carga' => $cargaId,
                'competencia' => $competenciaId, 'periodo' => $periodoId,
                'error' => $e->getMessage(),
            ]);
            $this->redirectWithError($volverForm, 'No se pudo registrar la calificación extraordinaria.');
        }

        $avisoBoleta = $this->calModel->queryOne(
            "SELECT estado FROM periodos WHERE id = ?", [$periodoId]
        );
        $extraAviso = ($avisoBoleta && $avisoBoleta['estado'] === 'cerrado')
            ? ' La nota ya es visible en la boleta de la familia (bimestre cerrado).'
            : '';

        $this->redirectWithSuccess($volverLista,
            'Calificación extraordinaria registrada (' . fmt_nota($nota) . ' · ' . $literal . ').'
            . ' No cuenta para el orden de mérito.' . $extraAviso);
    }

    /**
     * PUNTO ÚNICO de escritura de UNA calificación extraordinaria: criterio
     * único confirmado (extraordinario=1) + nota del alumno + promedio
     * (= la nota) marcado `extraordinaria=1` + conclusión + auditoría
     * `tipo='extraordinaria'`.
     *
     * ⚠️ NO abre ni cierra transacción: la owna quien llama. Así el alta
     * INDIVIDUAL y la del LOTE comparten exactamente la misma escritura y no
     * pueden divergir en silencio (el repo ya tuvo reglas copiadas a mano que
     * se separaron sin sintoma). Lanza excepción si algo falla, para que el
     * llamante haga rollback.
     *
     * @return int La nota final registrada en `calificaciones`.
     */
    private function escribirExtraordinaria(
        int $matriculaId,
        int $cargaId,
        int $competenciaId,
        int $periodoId,
        int $nota,
        string $conclusion,
        string $motivo,
        int $usuarioId,
        bool $esTransversal = false
    ): int {
        // Criterio único "Calificación extraordinaria" (nace confirmado:
        // el promedio agregado y el blindaje anti-fantasma lo exigen).
        $criterioId = $this->critModel->obtenerOCrearExtraordinario(
            $cargaId, $competenciaId, $periodoId, $usuarioId
        );
        if ($criterioId <= 0) {
            throw new \RuntimeException('No se pudo obtener el criterio extraordinario.');
        }

        $this->calModel->guardarNotaCriterio($criterioId, $matriculaId, $nota);

        // Promedio del alumno = su única nota viva confirmada (la extraordinaria).
        $promedio = $this->calModel->calcularPromedio($matriculaId, $cargaId, $competenciaId, $periodoId);
        if ($promedio === null) {
            throw new \RuntimeException('No se pudo calcular el promedio extraordinario.');
        }
        $notaFinal = (int) round($promedio);

        $this->calModel->guardarNotaFinal(
            $matriculaId, $cargaId, $periodoId, $competenciaId, $notaFinal, $usuarioId
        );
        $this->calModel->marcarCalificacionExtraordinaria(
            $matriculaId, $cargaId, $competenciaId, $periodoId
        );
        // Sin bloqueo, la boleta no la mostraría (competencia que nadie de la
        // sección evaluó). No-op si ya estaba bloqueada.
        $this->calModel->asegurarBloqueoExtraordinaria(
            $cargaId, $competenciaId, $periodoId, $usuarioId
        );
        // ⚠️ La conclusión de una TRANSVERSAL no vive en `calificaciones`: la
        // boleta la lee de `conclusiones_transversales`, que escribe el tutor.
        // Guardarla en el sitio equivocado la dejaría registrada e INVISIBLE.
        if ($conclusion !== '') {
            if ($esTransversal) {
                $this->transModel->guardarConclusion(
                    $matriculaId, $competenciaId, $periodoId, $conclusion, $usuarioId
                );
            } else {
                $this->calModel->actualizarConclusion(
                    $matriculaId, $cargaId, $competenciaId, $periodoId, $conclusion
                );
            }
        }
        $this->model->registrar([
            'matricula_id'        => $matriculaId,
            'carga_id'            => $cargaId,
            'periodo_id'          => $periodoId,
            'competencia_id'      => $competenciaId,
            'tipo'                => 'extraordinaria',
            'nota_anterior'       => null,
            'nota_nueva'          => $notaFinal,
            'conclusion_anterior' => null,
            'conclusion_nueva'    => $conclusion !== '' ? $conclusion : null,
            'motivo'              => $motivo,
            'rectificado_por'     => $usuarioId,
        ]);

        return $notaFinal;
    }

    /**
     * Etiqueta legible de una fila de getCompetenciasInsertables, para los
     * mensajes de error del lote: antepone la subárea en áreas con subáreas,
     * igual que hacen las vistas del módulo.
     */
    private function etiquetaInsertable(array $c): string
    {
        $nombre = $c['nombre_corto'] ?: $c['competencia_nombre'];
        if (($c['area_tipo'] ?? '') === 'con_subareas' && !empty($c['subarea_nombre'])) {
            $nombre = $c['subarea_nombre'] . ' — ' . $nombre;
        }
        return ($c['nombre_boleta'] ?: $c['area_nombre'] ?: '—') . ' · ' . $nombre;
    }

    /**
     * GET /rectificaciones/extraordinaria/lote?matricula=&periodo=
     * Grilla de CALIFICACIÓN EXTRAORDINARIA EN LOTE: todas las competencias
     * sin nota del alumno en UN bimestre, en una sola pantalla y con un
     * motivo común.
     *
     * Nació porque el alta de una en una obligaba a 25-27 pasadas por el
     * formulario para un alumno matriculado después del cierre. El motor es
     * el mismo: cambia la captura, no la regla.
     */
    public function extraordinariaLote(): void
    {
        $matriculaId = (int) $this->query('matricula');
        $periodoId   = (int) $this->query('periodo');

        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }

        // Misma fuente que la lista y que el alta individual: si una fila no
        // sale de aqui, no es insertable y no se pinta.
        $items = [];
        foreach ($this->model->getCompetenciasInsertables($matriculaId) as $c) {
            if ((int) $c['periodo_id'] === $periodoId) {
                $items[] = $c;
            }
        }
        // Las TRANSVERSALES llegan por su propia consulta (carga dueña derivada,
        // cierre vigente exigido): para la grilla son una fila más.
        foreach ($this->model->getTransversalesInsertables($matriculaId) as $c) {
            if ((int) $c['periodo_id'] === $periodoId) {
                $items[] = $c;
            }
        }
        // CONDUCTA y ASISTENCIA (22/09/2026, migración 063): dos filas más de
        // la grilla, con su propio comportamiento. El lote abre aunque no
        // quede ninguna competencia, si falta alguna de las dos.
        $pend = $this->model->conductaAsistenciaPendientes($matriculaId, $periodoId);
        if ($items === [] && !$pend['conducta'] && !$pend['asistencia']) {
            $this->redirectWithError(
                url('rectificaciones/matricula/' . $matriculaId),
                'Ese bimestre no tiene competencias, conducta ni asistencia pendientes de registro extraordinario.'
            );
        }

        // Agrupadas por área para que la grilla se lea como la boleta.
        $porArea = [];
        foreach ($items as $c) {
            $aid = (int) $c['area_id'];
            if (!isset($porArea[$aid])) {
                $porArea[$aid] = [
                    'area_id'     => $aid,
                    'area_nombre' => $c['nombre_boleta'] ?: $c['area_nombre'] ?: '—',
                    'items'       => [],
                ];
            }
            $porArea[$aid]['items'][] = $c;
        }

        // Literales que EXIGEN conclusión descriptiva en este nivel. Sale del
        // mismo punto único que valida el POST (conclusionObligatoria), para
        // que la grilla no reimplemente la regla: primaria B y C, secundaria
        // solo C. El JS revela el campo usando esta lista.
        $literalesConclusion = $this->literalesConclusion((string) $info['nivel_codigo']);

        $this->view('rectificaciones/extraordinaria-lote', [
            'titulo'       => 'Calificación extraordinaria en lote',
            'info'         => $info,
            // Sin competencias pendientes, el bimestre sale de la consulta de
            // conducta/asistencia (que solo devuelve periodos CERRADOS).
            'periodo'      => $items !== []
                ? [
                    'id'     => $periodoId,
                    'nombre' => $items[0]['periodo_nombre'],
                    'estado' => $items[0]['periodo_estado'],
                ]
                : $pend['periodo'],
            'porArea'      => array_values($porArea),
            'competencias' => count($items),
            'pendConducta'   => $pend['conducta'],
            'pendAsistencia' => $pend['asistencia'],
            'topeAsistencia' => AsistenciaModel::TOPE_MAX,
            'total'        => count($items) + (int) $pend['conducta'] + (int) $pend['asistencia'],
            'literalesConclusion' => $literalesConclusion,
            'old'          => Session::getFlash('lote_old'),
            'page_scripts' => ['rectificaciones-lote'],
        ]);
    }

    /**
     * POST /rectificaciones/extraordinaria/lote/guardar
     * Alta en LOTE. Valida TODAS las filas antes de escribir ninguna y
     * escribe el lote entero en UNA transacción: o entra completo o no entra
     * nada. Las filas sin nota se omiten sin error (no toda competencia del
     * bimestre tiene por qué convalidarse).
     *
     * NO regenera el snapshot del mérito: el flag `extraordinaria` excluye
     * estas notas del ranking, así que el orden vigente no cambia.
     *
     * CONDUCTA y ASISTENCIA (22/09/2026, migración 063) viajan en el mismo
     * POST como dos filas opcionales, con el mismo motivo y en la misma
     * transacción. Solo donde el alumno no tiene registro (nunca pisan), y el
     * orden de mérito no las lee.
     */
    public function guardarExtraordinariaLote(): void
    {
        $this->validateCsrf();

        $matriculaId  = (int) $this->input('matricula_id');
        $periodoId    = (int) $this->input('periodo_id');
        $motivo       = trim((string) $this->input('motivo', ''));
        $notas        = (array) $this->input('nota', []);
        $conclusiones = (array) $this->input('conclusion', []);
        // Filas de conducta y asistencia (migración 063): opcionales, como una
        // competencia vacía.
        $conductaLit  = strtoupper(trim((string) $this->input('conducta_literal', '')));
        $asistRaw     = (array) $this->input('asistencia', []);
        $usuarioId    = (int) (Session::user()['id'] ?? 0);

        $volverForm  = url('rectificaciones/extraordinaria/lote?matricula=' . $matriculaId
            . '&periodo=' . $periodoId);
        $volverLista = url('rectificaciones/matricula/' . $matriculaId);
        $entrada     = [
            'motivo' => $motivo, 'notas' => $notas, 'conclusiones' => $conclusiones,
            'conducta' => $conductaLit, 'asistencia' => $asistRaw,
        ];

        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }
        if ($motivo === '') {
            $this->volverConEntrada($volverForm, 'El motivo de la calificación extraordinaria es obligatorio.', 'lote_old', $entrada);
        }

        // Metadatos del bimestre indexados por la misma clave "carga-competencia"
        // que emiten los inputs de la grilla.
        $meta = [];
        foreach ($this->model->getCompetenciasInsertables($matriculaId) as $c) {
            if ((int) $c['periodo_id'] === $periodoId) {
                $meta[(int) $c['carga_id'] . '-' . (int) $c['competencia_id']] = $c;
            }
        }
        foreach ($this->model->getTransversalesInsertables($matriculaId) as $c) {
            if ((int) $c['periodo_id'] === $periodoId) {
                $meta[(int) $c['carga_id'] . '-' . (int) $c['competencia_id']] = $c;
            }
        }

        $nivel = (string) $info['nivel_codigo'];
        $filas = [];

        // ── Validación COMPLETA antes de escribir nada ───────────
        foreach ($notas as $clave => $notaRaw) {
            $clave   = (string) $clave;
            $notaRaw = trim((string) $notaRaw);
            if ($notaRaw === '') {
                continue;   // fila vacia: no se registra, y no es un error
            }
            if (!isset($meta[$clave])) {
                $this->volverConEntrada($volverForm,
                    'Una de las competencias enviadas no pertenece a este bimestre.', 'lote_old', $entrada);
            }
            $etiqueta = $this->etiquetaInsertable($meta[$clave]);
            if (!is_numeric($notaRaw)) {
                $this->volverConEntrada($volverForm,
                    'La nota de ' . $etiqueta . ' no es un número.', 'lote_old', $entrada);
            }

            $partes        = explode('-', $clave);
            $cargaId       = (int) $partes[0];
            $competenciaId = (int) $partes[1];
            $nota          = max(0, min(20, (int) $notaRaw));

            // Invariante de seguridad: re-chequeo del estado insertable POR
            // FILA. Ir en lote no lo salta. Las TRANSVERSALES tienen su propio
            // candado (carga dueña + cierre vigente), así que van por él.
            $esTransversal = !empty($meta[$clave]['es_transversal']);
            $admitida = $esTransversal
                ? $this->model->esInsertableTransversal($matriculaId, $cargaId, $competenciaId, $periodoId)
                : $this->model->esInsertable($matriculaId, $cargaId, $competenciaId, $periodoId);
            if (!$admitida) {
                $this->redirectWithError($volverLista,
                    $etiqueta . ' ya no admite calificación extraordinaria (el alumno ya tiene nota, está exonerado, o la competencia volvió al flujo del docente).');
            }

            $conclusion = trim((string) ($conclusiones[$clave] ?? ''));
            $literal    = nota_a_literal($nota);
            if (CalificacionModel::conclusionObligatoria($literal, $nivel) && $conclusion === '') {
                $this->volverConEntrada($volverForm,
                    'La conclusión descriptiva es obligatoria para el literal ' . $literal
                    . ' en este nivel: ' . $etiqueta . '.', 'lote_old', $entrada);
            }

            $filas[] = [
                'carga_id'       => $cargaId,
                'competencia_id' => $competenciaId,
                'nota'           => $nota,
                'conclusion'     => $conclusion,
                'transversal'    => $esTransversal,
            ];
        }

        // ── Conducta: literal directo, solo donde no hay registro ──
        $conductaModel = new ConductaModel();
        if ($conductaLit !== '') {
            if (!in_array($conductaLit, ['AD', 'A', 'B', 'C'], true)) {
                $this->volverConEntrada($volverForm,
                    'La conducta debe ser AD, A, B o C.', 'lote_old', $entrada);
            }
            // Invariante de seguridad, como las notas: re-chequeo en servidor.
            if (!$conductaModel->admiteExtraordinaria($matriculaId, $periodoId)) {
                $this->redirectWithError($volverLista,
                    'La conducta de este bimestre ya no admite registro extraordinario (el alumno ya tiene conducta, o su sección no tiene la conducta cerrada).');
            }
        }

        // ── Asistencia: los 4 contadores, solo donde no hay fila ───
        // Los 4 vacíos = fila omitida. Con alguno lleno, un vacío vale 0: un 0
        // es un DATO («sin incidencias»), y sin fila la boleta pinta guion.
        $asistencia = null;
        $algunaLlena = false;
        foreach (AsistenciaModel::CAMPOS as $campo) {
            if (trim((string) ($asistRaw[$campo] ?? '')) !== '') {
                $algunaLlena = true;
            }
        }
        $asistenciaModel = new AsistenciaModel();
        if ($algunaLlena) {
            $asistencia = [];
            foreach (AsistenciaModel::CAMPOS as $campo) {
                $crudo = trim((string) ($asistRaw[$campo] ?? ''));
                if ($crudo === '') {
                    $crudo = '0';
                }
                if (!ctype_digit($crudo) || (int) $crudo > AsistenciaModel::TOPE_MAX) {
                    $this->volverConEntrada($volverForm,
                        'Cada contador de asistencia debe ser un entero entre 0 y '
                        . AsistenciaModel::TOPE_MAX . '.', 'lote_old', $entrada);
                }
                $asistencia[$campo] = (int) $crudo;
            }
            if (!$asistenciaModel->admiteExtraordinaria($matriculaId, $periodoId)) {
                $this->redirectWithError($volverLista,
                    'La asistencia de este bimestre ya no admite registro extraordinario (el alumno ya tiene su registro).');
            }
        }

        if ($filas === [] && $conductaLit === '' && $asistencia === null) {
            $this->volverConEntrada($volverForm,
                'No ingresaste ninguna nota, conducta ni asistencia.', 'lote_old', $entrada);
        }

        // ── Escritura atómica del lote completo ──────────────────
        // Notas, conducta y asistencia en UNA transacción: entra todo o nada.
        $this->model->beginTransaction();
        try {
            foreach ($filas as $f) {
                $this->escribirExtraordinaria(
                    $matriculaId, $f['carga_id'], $f['competencia_id'], $periodoId,
                    $f['nota'], $f['conclusion'], $motivo, $usuarioId, $f['transversal']
                );
            }
            if ($conductaLit !== '') {
                $conductaModel->registrarLiteralExtraordinario(
                    $matriculaId, $periodoId, $conductaLit, $motivo, $usuarioId
                );
            }
            if ($asistencia !== null) {
                $asistenciaModel->registrarExtraordinaria(
                    $matriculaId, $periodoId,
                    $asistencia['faltas'], $asistencia['faltas_justificadas'],
                    $asistencia['tardanzas'], $asistencia['tardanzas_justificadas'],
                    $motivo, $usuarioId
                );
            }
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al registrar el lote de calificaciones extraordinarias', [
                'matricula' => $matriculaId, 'periodo' => $periodoId,
                'filas' => count($filas), 'error' => $e->getMessage(),
            ]);
            $this->volverConEntrada($volverForm,
                'No se pudo registrar el lote. No se guardó ninguna nota.', 'lote_old', $entrada);
        }

        $avisoBoleta = $this->calModel->queryOne(
            "SELECT estado FROM periodos WHERE id = ?", [$periodoId]
        );
        $extraAviso = ($avisoBoleta && $avisoBoleta['estado'] === 'cerrado')
            ? ' Ya son visibles en la boleta de la familia (bimestre cerrado).'
            : '';

        $n = count($filas);
        $partes = [];
        if ($n > 0) {
            $partes[] = $n . ($n === 1 ? ' calificación extraordinaria registrada' : ' calificaciones extraordinarias registradas')
                . '. No cuentan para el orden de mérito.';
        }
        if ($conductaLit !== '') {
            $partes[] = 'Conducta ' . $conductaLit . ' registrada.';
        }
        if ($asistencia !== null) {
            $partes[] = 'Asistencia registrada.';
        }
        $this->redirectWithSuccess($volverLista, implode(' ', $partes) . $extraAviso);
    }
    /**
     * Literales que EXIGEN conclusión descriptiva en un nivel. Sale del mismo
     * punto único que valida el POST (conclusionObligatoria): primaria B y C,
     * secundaria solo C. Las vistas lo pasan al JS en data-* para que el
     * cliente no reimplemente la regla.
     */
    private function literalesConclusion(string $nivelCodigo): array
    {
        $out = [];
        foreach (['AD', 'A', 'B', 'C'] as $lit) {
            if (CalificacionModel::conclusionObligatoria($lit, $nivelCodigo)) {
                $out[] = $lit;
            }
        }
        return $out;
    }

    /**
     * Rechazo que CONSERVA lo escrito: guarda la entrada en un flash y vuelve
     * al formulario, que la repinta. Antes el rechazo repintaba las notas de
     * la BD y el reenvío guardaba la nota VIEJA sin aviso (así falló la prueba
     * §B1 del 18/09/2026: una rectificación 17→10 quedó registrada 17→17).
     */
    private function volverConEntrada(string $url, string $mensaje, string $clave, array $entrada): never
    {
        Session::flash($clave, $entrada);
        $this->redirectWithError($url, $mensaje);
    }

    /**
     * GET /rectificaciones/editar?matricula=&carga=&competencia=&periodo=
     * Formulario de rectificación por criterio de UNA competencia.
     */
    public function editar(): void
    {
        $matriculaId   = (int) $this->query('matricula');
        $cargaId       = (int) $this->query('carga');
        $competenciaId = (int) $this->query('competencia');
        $periodoId     = (int) $this->query('periodo');

        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }

        // Invariante de seguridad: solo competencias rectificables.
        if (!$this->model->esRectificable($matriculaId, $cargaId, $competenciaId, $periodoId)) {
            $this->redirectWithError(
                url('rectificaciones/matricula/' . $matriculaId),
                'Esa competencia no es rectificable (debe estar bloqueada o en un bimestre cerrado).'
            );
        }

        $detalle = $this->model->getDetalleCompetencia($matriculaId, $cargaId, $competenciaId, $periodoId);
        if (!$detalle) {
            $this->notFound();
        }

        $this->view('rectificaciones/editar', [
            'titulo'        => 'Rectificar calificación',
            'info'          => $info,
            'meta'          => $detalle['meta'],
            'criterios'     => $detalle['criterios'],
            'cargaId'       => $cargaId,
            'competenciaId' => $competenciaId,
            'periodoId'     => $periodoId,
            'literalesConclusion' => $this->literalesConclusion((string) $info['nivel_codigo']),
            // Lo que se escribio antes de un rechazo del servidor (ver
            // volverConEntrada): sin esto el formulario se repintaba con las
            // notas de la BD y reenviarlo guardaba la nota VIEJA sin aviso.
            'old'           => Session::getFlash('rect_old'),
            'page_scripts'  => ['rectificaciones'],
        ]);
    }

    /**
     * POST /rectificaciones/guardar
     * Aplica la rectificación por criterio + recálculo + conclusión, deja
     * la traza de auditoría y regenera el snapshot del orden de mérito.
     */
    public function guardar(): void
    {
        $this->validateCsrf();

        $matriculaId   = (int) $this->input('matricula_id');
        $cargaId       = (int) $this->input('carga_id');
        $competenciaId = (int) $this->input('competencia_id');
        $periodoId     = (int) $this->input('periodo_id');
        $motivo        = trim((string) $this->input('motivo', ''));
        $conclusion    = trim((string) $this->input('conclusion', ''));
        $notasPost     = $this->input('notas', []);
        $usuarioId     = (int) (Session::user()['id'] ?? 0);

        $volverEditar = url('rectificaciones/editar?matricula=' . $matriculaId
            . '&carga=' . $cargaId . '&competencia=' . $competenciaId . '&periodo=' . $periodoId);
        $volverLista  = url('rectificaciones/matricula/' . $matriculaId);
        $entrada      = [
            'notas'      => is_array($notasPost) ? $notasPost : [],
            'conclusion' => $conclusion,
            'motivo'     => $motivo,
        ];

        // ── Validaciones de entrada ──────────────────────────────
        $info = $this->model->getMatriculaInfo($matriculaId);
        if (!$info) {
            $this->notFound();
        }
        if ($motivo === '') {
            $this->volverConEntrada($volverEditar, 'El motivo de la rectificación es obligatorio.', 'rect_old', $entrada);
        }
        // Invariante de seguridad: estado rectificable.
        if (!$this->model->esRectificable($matriculaId, $cargaId, $competenciaId, $periodoId)) {
            $this->redirectWithError($volverLista,
                'Esa competencia no es rectificable (debe estar bloqueada o en un bimestre cerrado).');
        }

        $detalle = $this->model->getDetalleCompetencia($matriculaId, $cargaId, $competenciaId, $periodoId);
        if (!$detalle) {
            $this->notFound();
        }
        $criterios = $detalle['criterios'];
        if (empty($criterios)) {
            $this->redirectWithError($volverLista,
                'Esta competencia no tiene criterios registrados; no puede rectificarse por criterio.');
        }

        // Solo se aceptan notas de criterios válidos de esta competencia.
        $idsValidos = array_map(static fn($c) => (int) $c['id'], $criterios);
        $notas      = [];
        if (is_array($notasPost)) {
            foreach ($notasPost as $cid => $valor) {
                $cid = (int) $cid;
                if (in_array($cid, $idsValidos, true) && $valor !== '' && $valor !== null) {
                    $notas[$cid] = max(0, min(20, (int) $valor));
                }
            }
        }
        if (empty($notas)) {
            $this->volverConEntrada($volverEditar, 'Ingresa al menos una nota de criterio.', 'rect_old', $entrada);
        }

        // Estado ANTERIOR (para la traza).
        $notaAnterior       = $detalle['meta']['nota_actual'] !== null ? (int) $detalle['meta']['nota_actual'] : null;
        $conclusionAnterior = $detalle['meta']['conclusion_actual'];
        $nivelCodigo        = (string) $info['nivel_codigo'];

        // ── Escritura atómica ────────────────────────────────────
        $this->model->beginTransaction();
        try {
            foreach ($notas as $criterioId => $nota) {
                $this->calModel->guardarNotaCriterio($criterioId, $matriculaId, $nota);
            }

            $promedio = $this->calModel->calcularPromedio($matriculaId, $cargaId, $competenciaId, $periodoId);
            if ($promedio === null) {
                $this->model->rollback();
                $this->volverConEntrada($volverEditar, 'No se pudo calcular el promedio. Revisa las notas.', 'rect_old', $entrada);
            }
            $notaNueva = (int) round($promedio);
            $literal   = nota_a_literal($notaNueva);

            // Conclusión obligatoria según literal + nivel.
            if (CalificacionModel::conclusionObligatoria($literal, $nivelCodigo) && $conclusion === '') {
                $this->model->rollback();
                $this->volverConEntrada($volverEditar,
                    'La conclusión descriptiva es obligatoria para el literal ' . $literal . ' en este nivel.',
                    'rect_old', $entrada);
            }

            $this->calModel->guardarNotaFinal(
                $matriculaId, $cargaId, $periodoId, $competenciaId, $notaNueva, $usuarioId
            );
            $this->calModel->actualizarConclusion(
                $matriculaId, $cargaId, $competenciaId, $periodoId, $conclusion
            );

            $this->model->registrar([
                'matricula_id'        => $matriculaId,
                'carga_id'            => $cargaId,
                'periodo_id'          => $periodoId,
                'competencia_id'      => $competenciaId,
                'nota_anterior'       => $notaAnterior,
                'nota_nueva'          => $notaNueva,
                'conclusion_anterior' => $conclusionAnterior,
                'conclusion_nueva'    => $conclusion !== '' ? $conclusion : null,
                'motivo'              => $motivo,
                'rectificado_por'     => $usuarioId,
            ]);

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al rectificar calificación', [
                'matricula' => $matriculaId, 'carga' => $cargaId,
                'competencia' => $competenciaId, 'periodo' => $periodoId,
                'error' => $e->getMessage(),
            ]);
            $this->volverConEntrada($volverEditar, 'No se pudo aplicar la rectificación.', 'rect_old', $entrada);
        }

        // ── Regeneración del orden de mérito + aviso de empate ────
        // registrarRanking respeta la INMUTABILIDAD (migr. 046): si el bimestre YA
        // estuvo publicado, el oficial NO cambia y se guarda una versión rectificada
        // (visible solo en el Centro de control). Lee la corrección ya aplicada.
        $avisoEmpate = '';
        $notaRanking = '';
        try {
            // `exigirMismoRoster = true`: una rectificación corrige NOTAS, nunca
            // decide quién pertenece al documento. Si el roster ya no coincide
            // con el del snapshot, no se reescribe nada y se avisa (ver la guarda
            // en OrdenMeritoModel::registrarRanking).
            $tipoRanking = $this->ordenMeritoModel->registrarRanking(
                $periodoId, $usuarioId, 'Rectificación de notas', true
            );
            if ($tipoRanking === 'roster_cambiado') {
                $notaRanking = ' ATENCIÓN: el orden de mérito NO se actualizó porque la lista de '
                    . 'estudiantes del bimestre cambió desde que se cerró (algún traslado o retiro '
                    . 'sin sincronizar). Regularízalo desde la matrícula afectada y vuelve a '
                    . 'guardar esta rectificación.';
            }
            if ($tipoRanking === 'rectificado') {
                $notaRanking = ' El orden de mérito oficial (publicado) no se modificó; '
                    . 'la nueva versión quedó registrada en el Centro de control.';
            }
            if ($this->ordenMeritoModel->gradoTieneEmpateLivePendiente((int) $info['grado_id'], $periodoId)) {
                $avisoEmpate = ' Atención: el ranking del grado quedó con un empate pendiente de '
                    . 'resolver; coordina con el director para definirlo.';
            }
        } catch (\Exception $e) {
            log_error('Error al regenerar orden de mérito tras rectificación', [
                'periodo' => $periodoId, 'error' => $e->getMessage(),
            ]);
            $avisoEmpate = ' (No se pudo regenerar el orden de mérito automáticamente; revísalo manualmente.)';
        }

        // Una nota que nacio EXTRAORDINARIA sigue siendolo tras corregirla (el
        // flag sobrevive al recalculo), asi que no mueve el orden de merito. Sin
        // este aviso, RA buscaba el cambio en el ranking y no lo encontraba.
        $avisoExtra = '';
        $filaCal = $this->calModel->queryOne("
            SELECT extraordinaria FROM calificaciones
            WHERE matricula_id = ? AND carga_id = ? AND competencia_id = ? AND periodo_id = ?
        ", [$matriculaId, $cargaId, $competenciaId, $periodoId]);
        if ($filaCal && (int) $filaCal['extraordinaria'] === 1) {
            $avisoExtra = ' Esta nota es extraordinaria: no cuenta para el orden de mérito.';
        }

        $this->redirectWithSuccess($volverLista,
            'Rectificación aplicada.' . $avisoExtra . $notaRanking . $avisoEmpate);
    }

}
