<?php

namespace App\Controllers\Matricula;

use App\Controllers\BaseController;
use App\Models\MatriculaModel;
use App\Models\ApoderadoModel;
use App\Models\EstudianteModel;
use App\Models\TrasladoModel;
use App\Models\ExoneracionModel;
use App\Models\NotaAutorizadaSiagieModel;
use App\Models\DirectorEbrModel;
use App\Models\OrdenMeritoModel;
use App\Models\NotaExternaModel;
use App\Models\NotificacionModel;
use App\Models\AnioAcademicoModel;
use App\Models\RectificacionModel;
use Core\Session;
use Core\View;

/**
 * MatriculaController
 * Wizard de matrícula en 3 pasos (estudiante → apoderado → documentos),
 * detalle, activación/desactivación y notas externas (traslados).
 *
 * Reglas clave:
 *  - Toda matrícula nace en estado='pendiente'.
 *  - Solo admin y registro_academico pueden activar/desactivar.
 *  - Las secretarías solo crean y registran documentos.
 *  - Serie del recibo obligatoria siempre.
 */
class MatriculaController extends BaseController
{
    private MatriculaModel $model;
    private ApoderadoModel $apoderados;
    private EstudianteModel $estudiantes;
    private TrasladoModel $traslados;
    private ExoneracionModel $exoneraciones;
    private NotaAutorizadaSiagieModel $notasAut;
    private OrdenMeritoModel $ordenMerito;
    private NotaExternaModel $notasExternasModel;

    /** Tipos de vínculo disponibles: valor BD => etiqueta mostrada. */
    private const TIPOS_VINCULO = [
        'padre'     => 'Padre',
        'madre'     => 'Madre',
        'apoderado' => 'Apoderado',
        'apoderada' => 'Apoderada',
        'abuelo'    => 'Abuelo',
        'abuela'    => 'Abuela',
        'tio'       => 'Tío',
        'tia'       => 'Tía',
        'padrino'   => 'Padrino',
        'madrina'   => 'Madrina',
        'hermano'   => 'Hermano',
        'hermana'   => 'Hermana',
        'primo'     => 'Primo',
        'prima'     => 'Prima',
    ];

    /** Documentos requeridos según el tipo de matrícula. */
    private const DOCS_NUEVO = [
        'recibo_pago'            => 'Recibo de pago',
        'certificado_estudios'   => 'Certificado de estudios',
        'boleta_siagie'          => 'Boleta SIAGIE',
        'ficha_matricula_siagie' => 'Ficha de matrícula SIAGIE',
        'dni_estudiante'         => 'DNI del estudiante',
        'dni_padre'              => 'DNI del padre',
        'dni_madre'              => 'DNI de la madre',
        'dni_apoderado'          => 'DNI del apoderado',
    ];
    private const DOCS_CONTINUADOR = [
        'recibo_pago' => 'Recibo de pago',
    ];

    /**
     * Documentos OBLIGATORIOS para activar una matrícula 'nuevo'. El resto de
     * DOCS_NUEVO es ideal pero opcional. Adicionalmente se exige al menos UNO
     * del grupo DOCS_DNI_APODERADO.
     */
    private const DOCS_OBLIGATORIOS_NUEVO = [
        'recibo_pago', 'certificado_estudios', 'boleta_siagie',
        'ficha_matricula_siagie', 'dni_estudiante',
    ];
    /** Grupo "al menos uno": basta cualquiera de estos DNI de apoderado. */
    private const DOCS_DNI_APODERADO = ['dni_padre', 'dni_madre', 'dni_apoderado'];

    /**
     * Quien MATRICULA. Es EXACTAMENTE la lista que tenia el constructor antes
     * del 24/08/2026, movida aqui sin quitar ni sumar a nadie: las secretarias
     * siguen dando de alta matriculas, registrando apoderados, subiendo
     * documentos y anotando notas externas, igual que hasta ahora.
     *
     * Existe porque al abrir el constructor a los directores (solo lectura)
     * habia CUATRO metodos de escritura sin guarda propia. Guardarlos con esta
     * constante deja el permiso donde estaba y cierra la puerta a los nuevos.
     */
    private const ROLES_MATRICULAN = [
        'admin', 'registro_academico',
        'secretaria_academica', 'secretaria_administrativa',
    ];

    public function __construct()
    {
        // Los DIRECTORES entran desde el 24/08/2026, en SOLO LECTURA: consultan
        // la grilla, el detalle y el resumen, y abren la boleta interna de
        // gestion igual que las secretarias.
        //
        // ⚠️ ABRIR ESTE CONSTRUCTOR NO ALCANZA. Cuatro metodos de ESCRITURA de
        // esta clase no tenian guarda propia y confiaban solo en el: `store`
        // (crear matricula), `storeApoderado`, `storeDocumentos` y
        // `storeNotasExternas`. Sumar roles aqui sin guardarlos les habria dado
        // el alta de matriculas. Llevan su requireRole desde el mismo commit,
        // junto con los 4 formularios GET que los alimentan.
        $this->requireRole([
            'admin', 'registro_academico',
            'secretaria_academica', 'secretaria_administrativa',
            ...ROLES_DIRECCION,
        ]);
        $this->model       = new MatriculaModel();
        $this->apoderados  = new ApoderadoModel();
        $this->estudiantes = new EstudianteModel();
        $this->traslados   = new TrasladoModel();
        $this->exoneraciones = new ExoneracionModel();
        $this->notasAut    = new NotaAutorizadaSiagieModel();
        $this->ordenMerito = new OrdenMeritoModel();
        $this->notasExternasModel = new NotaExternaModel();
    }

    /** Catálogo de tipos de vínculo (para reutilizar desde otros módulos). */
    public static function tiposVinculo(): array
    {
        return self::TIPOS_VINCULO;
    }

    // ── GET /matriculas ──────────────────────────────────────────
    public function index(): void
    {
        $anioActivo = $this->estudiantes->anioActivo();
        $anioFiltro = (int) ($this->query('anio_id') ?: ($anioActivo['id'] ?? 0));

        // Orden seguro: validar contra la lista blanca del modelo (default modificación/desc).
        $orden = array_key_exists((string) $this->query('orden'), MatriculaModel::ORDENABLES)
            ? (string) $this->query('orden')
            : 'modificacion';
        $dir = strtolower((string) $this->query('dir')) === 'asc' ? 'asc' : 'desc';

        $filtros = [
            'anio_id'    => $anioFiltro ?: null,
            'grado_id'   => (int) $this->query('grado_id') ?: null,
            'seccion_id' => (int) $this->query('seccion_id') ?: null,
            'estado'     => $this->query('estado') ?: null,
            'tipo'       => $this->query('tipo') ?: null,
            'search'     => trim((string) $this->query('search', '')) ?: null,
            'orden'      => $orden,
            'dir'        => $dir,
        ];

        $porPagina = 25;
        $pagina    = max(1, (int) $this->query('pagina', 1));
        $total     = $this->model->contar($filtros);
        $totalPags = max(1, (int) ceil($total / $porPagina));
        $pagina    = min($pagina, $totalPags);

        $filtros['limit']  = $porPagina;
        $filtros['offset'] = ($pagina - 1) * $porPagina;

        $matriculas = $this->model->listar($filtros);

        $this->view('matriculas/index', [
            'titulo'      => 'Matrículas',
            // Los directores consultan la grilla; el alta es de quien matricula.
            'puedeMatricular' => has_role(self::ROLES_MATRICULAN),
            // 🔴 NO es lo mismo que $puedeMatricular. "Traslados" y "Nomina
            // detallada" exigen admin/RA en su destino (TrasladoController:39 y
            // nominaImprimir:266), asi que las secretarias —que SI matriculan—
            // tambien recibian 403 al pulsarlos, igual que los directores.
            // Mismo flag y mismo valor que en `show` (linea 797).
            'puedeGestionar'  => has_role(['admin', 'registro_academico']),
            'matriculas'  => $matriculas,
            'filtros'     => $filtros,
            'anios'       => $this->model->listarAnios(),
            'grados'      => $this->model->listarGrados(),
            'secciones'   => $anioFiltro ? $this->model->listarSecciones($anioFiltro) : [],
            'total'       => $total,
            'pagina'      => $pagina,
            'total_pags'  => $totalPags,
            'orden'       => $orden,
            'dir'         => $dir,
        ]);
    }

    // ── GET /matriculas/resumen ──────────────────────────────────
    /** Dashboard de estadísticas de matrícula (KPIs + gráficos) por año. */
    public function resumen(): void
    {
        $anioActivo = $this->estudiantes->anioActivo();
        $anioFiltro = (int) ($this->query('anio_id') ?: ($anioActivo['id'] ?? 0));
        $nivelId    = (int) $this->query('nivel_id') ?: null;

        $anios   = $this->model->listarAnios();
        $resumen = $anioFiltro
            ? $this->model->getResumen($anioFiltro)
            : ['kpis' => [], 'por_grado' => [], 'por_tipo' => [], 'por_genero' => []];

        // Cuadro cruzado por grado (panorama de todos los estados del año).
        $cuadro = $anioFiltro ? $this->model->getCuadroMatricula($anioFiltro, $nivelId) : [];

        $anioSel = null;
        foreach ($anios as $a) {
            if ((int) $a['id'] === $anioFiltro) { $anioSel = $a; break; }
        }

        $this->view('matriculas/resumen', [
            'titulo'  => 'Resumen de matrículas',
            'resumen' => $resumen,
            'cuadro'  => $cuadro,
            'anios'   => $anios,
            'niveles' => $this->model->listarNiveles(),
            'anioId'  => $anioFiltro,
            'nivelId' => $nivelId,
            'anioSel' => $anioSel,
        ]);
    }

    // ── GET /matriculas/resumen/imprimir ─────────────────────────
    /**
     * Cuadro de matrícula imprimible (A4 portrait) para el comité directivo.
     * Solo admin y registro_academico. Panorama por grado del año (filtro
     * opcional por nivel). Conteo único por matrícula oficial.
     */
    public function resumenImprimir(): void
    {
        // Los DIRECTORES se suman el 24/08/2026. Este documento es, en palabras
        // de su propio docblock, "para el comite directivo": eran justo el
        // destinatario que no podia abrirlo.
        $this->requireRole(['admin', 'registro_academico', ...ROLES_DIRECCION]);

        $anioActivo = $this->estudiantes->anioActivo();
        $anioFiltro = (int) ($this->query('anio_id') ?: ($anioActivo['id'] ?? 0));
        $nivelId    = (int) $this->query('nivel_id') ?: null;

        $cuadro = $anioFiltro ? $this->model->getCuadroMatricula($anioFiltro, $nivelId) : [];

        $anioLabel = '';
        foreach ($this->model->listarAnios() as $an) {
            if ((int) $an['id'] === $anioFiltro) { $anioLabel = (string) $an['anio']; break; }
        }
        $nivelLabel = 'Todos los niveles';
        if ($nivelId) {
            foreach ($this->model->listarNiveles() as $nv) {
                if ((int) $nv['id'] === $nivelId) { $nivelLabel = $nv['nombre']; break; }
            }
        }
        $directorEbr = $anioFiltro
            ? (new DirectorEbrModel())->getVigenteEnFecha($anioFiltro)
            : null;

        View::setLayout('print');
        $this->view('matriculas/resumen-imprimir', [
            'titulo'      => 'Cuadro de matrícula',
            'cuadro'      => $cuadro,
            'anioLabel'   => $anioLabel,
            'nivelLabel'  => $nivelLabel,
            'directorEbr' => $directorEbr,
        ]);
    }

    // ── GET /matriculas/nomina/imprimir ──────────────────────────
    /**
     * Nómina detallada imprimible (reporte al comité directivo). Solo admin y
     * registro_academico. Eje global con los MISMOS filtros del index, agrupada
     * por sección; cada sección cierra con su cuadro resumen. Retorno de grado:
     * el alumno aparece en su sección oficial Y en la operativa (regla R3).
     */
    public function nominaImprimir(): void
    {
        $this->requireRole(['admin', 'registro_academico']);

        $anioActivo = $this->estudiantes->anioActivo();
        $anioFiltro = (int) ($this->query('anio_id') ?: ($anioActivo['id'] ?? 0));

        $filtros = [
            'anio_id'    => $anioFiltro ?: null,
            'grado_id'   => (int) $this->query('grado_id') ?: null,
            'seccion_id' => (int) $this->query('seccion_id') ?: null,
            'estado'     => $this->query('estado') ?: null,
            'tipo'       => $this->query('tipo') ?: null,
            'search'     => trim((string) $this->query('search', '')) ?: null,
        ];

        $alumnos = $this->model->listarParaNomina($filtros);

        // Agrupar por sección preservando el orden (nivel→grado→sección→apellidos).
        $grupos = [];
        foreach ($alumnos as $a) {
            $sid = (int) ($a['seccion_id'] ?? 0);
            if (!isset($grupos[$sid])) {
                $grupos[$sid] = [
                    'seccion_id'     => $sid,
                    'nivel_nombre'   => $a['nivel_nombre']   ?? '',
                    'grado_nombre'   => $a['grado_nombre']   ?? '',
                    'seccion_nombre' => $a['seccion_nombre'] ?? '',
                    'alumnos'        => [],
                ];
            }
            $grupos[$sid]['alumnos'][] = $a;
        }
        foreach ($grupos as &$g) {
            $g['resumen'] = $this->resumenSeccionNomina($g['alumnos']);
        }
        unset($g);

        // Año del reporte (etiqueta) y sello del Director EBR vigente de ese año.
        $anioLabel = '';
        foreach ($this->model->listarAnios() as $an) {
            if ((int) $an['id'] === $anioFiltro) { $anioLabel = (string) $an['anio']; break; }
        }
        $directorEbr = $anioFiltro
            ? (new DirectorEbrModel())->getVigenteEnFecha($anioFiltro)
            : null;

        View::setLayout('print');
        $this->view('matriculas/nomina-imprimir', [
            'titulo'       => 'Nómina detallada de matrículas',
            'grupos'       => array_values($grupos),
            'totalGeneral' => count($alumnos),
            'filtrosTexto' => $this->describirFiltrosNomina($filtros),
            'anioLabel'    => $anioLabel,
            'directorEbr'  => $directorEbr,
        ]);
    }

    /**
     * Cuadro resumen de una sección de la nómina. Cuenta las filas mostradas en
     * esa sección; el género NO contabiliza a quien no tiene registro (sexo NULL).
     * Lleva además el detalle de retorno de grado de la sección:
     *  - cursan_aqui: filas operativas (el alumno cursa en esta aula).
     *  - informativa: filas oficiales cuyo alumno cursa en otro grado.
     */
    private function resumenSeccionNomina(array $alumnos): array
    {
        $r = [
            'total'       => count($alumnos),
            'tipo'        => ['nuevo' => 0, 'continuador' => 0, 'trasladado' => 0, 'retirado' => 0],
            'estado'      => ['aprobada' => 0, 'pendiente' => 0, 'desactivado' => 0],
            'genero'      => ['M' => 0, 'F' => 0],
            'cursan_aqui' => 0,
            'informativa' => 0,
        ];
        foreach ($alumnos as $a) {
            if (isset($r['tipo'][$a['tipo']]))     { $r['tipo'][$a['tipo']]++; }
            if (isset($r['estado'][$a['estado']])) { $r['estado'][$a['estado']]++; }
            if ($a['sexo'] === 'M' || $a['sexo'] === 'F') { $r['genero'][$a['sexo']]++; }
            if (!empty($a['retorno_oficial_id']))   { $r['cursan_aqui']++; }
            if (!empty($a['retorno_operativa_id'])) { $r['informativa']++; }
        }
        return $r;
    }

    /** Texto legible de los filtros aplicados, para el encabezado del reporte. */
    private function describirFiltrosNomina(array $f): string
    {
        $estados = ['aprobada' => 'Aprobado', 'pendiente' => 'Pendiente', 'desactivado' => 'Desactivado'];
        $tipos   = ['nuevo' => 'Nuevo', 'continuador' => 'Continuador', 'trasladado' => 'Trasladado', 'retirado' => 'Retirado'];

        $partes = [];
        if (!empty($f['grado_id'])) {
            foreach ($this->model->listarGrados() as $g) {
                if ((int) $g['id'] === (int) $f['grado_id']) {
                    $partes[] = 'Grado: ' . $g['nivel_nombre'] . ' ' . $g['nombre_display'];
                    break;
                }
            }
        }
        if (!empty($f['seccion_id']) && !empty($f['anio_id'])) {
            foreach ($this->model->listarSecciones((int) $f['anio_id']) as $s) {
                if ((int) $s['id'] === (int) $f['seccion_id']) {
                    $partes[] = 'Sección: ' . $s['grado_nombre'] . ' ' . $s['nombre'];
                    break;
                }
            }
        }
        if (!empty($f['estado'])) { $partes[] = 'Estado: ' . ($estados[$f['estado']] ?? $f['estado']); }
        if (!empty($f['tipo']))   { $partes[] = 'Tipo: ' . ($tipos[$f['tipo']] ?? $f['tipo']); }
        if (!empty($f['search'])) { $partes[] = 'Búsqueda: ' . $f['search']; }

        return $partes ? implode(' · ', $partes) : 'Todos los registros del año';
    }

    // ── GET /matriculas/crear ────────────────────────────────────
    public function create(): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $anioActivo = $this->estudiantes->anioActivo();
        if (!$anioActivo) {
            $this->redirectWithError(url('matriculas'), 'No hay un año académico activo.');
        }

        // Búsqueda de estudiante por DNI (paso 1).
        $dni        = trim((string) $this->query('dni', ''));
        $estudiante = null;
        $yaMatriculado = false;
        $seccionSugerida = null;

        if ($dni !== '') {
            $estudiante = $this->model->estudiantePorDni($dni);
            if ($estudiante) {
                $yaMatriculado = $this->model->existeMatricula(
                    (int) $estudiante['estudiante_id'],
                    (int) $anioActivo['id']
                );
            }
        }

        $this->view('matriculas/crear', [
            'titulo'          => 'Nueva matrícula',
            'paso'            => 1,
            'anioActivo'      => $anioActivo,
            'grados'          => $this->model->listarGrados(),
            'secciones'       => $this->model->listarSecciones((int) $anioActivo['id']),
            'dni'             => $dni,
            'estudiante'      => $estudiante,
            'yaMatriculado'   => $yaMatriculado,
            'seccionSugerida' => $seccionSugerida,
            'page_scripts'    => ['matriculas'],
        ]);
    }

    // ── POST /matriculas/crear ───────────────────────────────────
    public function store(): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $this->validateCsrf();

        $anioActivo = $this->estudiantes->anioActivo();
        if (!$anioActivo) {
            $this->redirectWithError(url('matriculas'), 'No hay un año académico activo.');
        }
        $anioId = (int) $anioActivo['id'];

        $dni          = trim((string) $this->input('dni', ''));
        $gradoId      = (int) $this->input('grado_id');
        $tipo         = in_array($this->input('tipo'), ['continuador', 'nuevo'], true)
            ? $this->input('tipo') : 'continuador';
        $serieRecibo  = trim((string) $this->input('serie_recibo', ''));
        $seccionPost  = (int) $this->input('seccion_id');
        $provisional  = (bool) $this->input('provisional');

        // ── Alta PROVISIONAL (estudiante sin DNI todavía) ────────────────
        // El padre matricula al hijo pero no dejó el DNI ni los documentos de
        // traslado. Se registra con un código provisional para que el docente
        // pueda calificarlo (la matrícula 'pendiente' aparece en su grilla); el
        // DNI real y los documentos se regularizan antes de activar. La serie
        // del recibo es OPCIONAL aquí (se exige antes de activar).
        if ($provisional) {
            if (!$gradoId) {
                $this->redirectWithError(url('matriculas/crear'),
                    'Selecciona el grado de destino.');
            }
            $apePat  = mb_strtoupper(trim((string) $this->input('apellido_paterno')));
            $nombres = mb_strtoupper(trim((string) $this->input('nombres')));
            if ($apePat === '' || $nombres === '') {
                $this->redirectWithError(url('matriculas/crear'),
                    'El registro provisional requiere al menos apellido paterno y nombres.');
            }

            $datosPersona = [
                'apellido_paterno' => $apePat,
                'apellido_materno' => mb_strtoupper(trim((string) $this->input('apellido_materno'))),
                'nombres'          => $nombres,
                'fecha_nacimiento' => $this->input('fecha_nacimiento') ?: null,
                'sexo'             => in_array($this->input('sexo'), ['M', 'F'], true)
                    ? $this->input('sexo') : null,
            ];
            try {
                $estudianteId = $this->model->crearEstudianteProvisional($datosPersona);
            } catch (\Exception $e) {
                log_error('Error al crear estudiante provisional', ['error' => $e->getMessage()]);
                $this->redirectWithError(url('matriculas/crear'),
                    'No se pudo registrar al estudiante provisional.');
            }

            $seccionId = $seccionPost ?: $this->resolverSeccion($estudianteId, $gradoId, $anioId, $tipo);

            $matriculaId = $this->model->crear([
                'estudiante_id'  => $estudianteId,
                'seccion_id'     => $seccionId,
                'anio_id'        => $anioId,
                'tipo'           => $tipo,
                'serie_recibo'   => $serieRecibo !== '' ? $serieRecibo : null,
                'registrado_por' => (int) (Session::user()['id'] ?? 0),
            ]);
            $this->model->update($matriculaId, [
                'motivo_estado' => 'Registro provisional — pendiente de DNI y documentos',
            ]);

            $this->redirectWithSuccess(
                url('matriculas/' . $matriculaId . '/apoderado'),
                'Matrícula provisional creada sin DNI. Regulariza el DNI real y los documentos antes de activarla.'
            );
        }

        // Serie del recibo obligatoria SIEMPRE.
        if ($serieRecibo === '') {
            $this->redirectWithError(url('matriculas/crear?dni=' . urlencode($dni)),
                'La serie del recibo es obligatoria.');
        }
        if ($dni === '' || !ctype_digit($dni) || strlen($dni) !== 8) {
            $this->redirectWithError(url('matriculas/crear'), 'DNI inválido (8 dígitos).');
        }
        if (!$gradoId) {
            $this->redirectWithError(url('matriculas/crear?dni=' . urlencode($dni)),
                'Selecciona el grado de destino.');
        }

        // 1) Estudiante: existente o nuevo.
        $existente = $this->model->estudiantePorDni($dni);
        if ($existente) {
            $estudianteId = (int) $existente['estudiante_id'];
        } else {
            $datosPersona = [
                'dni'              => $dni,
                'apellido_paterno' => mb_strtoupper(trim((string) $this->input('apellido_paterno'))),
                'apellido_materno' => mb_strtoupper(trim((string) $this->input('apellido_materno'))),
                'nombres'          => mb_strtoupper(trim((string) $this->input('nombres'))),
                'fecha_nacimiento' => $this->input('fecha_nacimiento') ?: null,
                'sexo'             => in_array($this->input('sexo'), ['M', 'F'], true)
                    ? $this->input('sexo') : null,
            ];
            if ($datosPersona['apellido_paterno'] === '' || $datosPersona['nombres'] === '') {
                $this->redirectWithError(url('matriculas/crear?dni=' . urlencode($dni)),
                    'Faltan datos del estudiante nuevo.');
            }
            try {
                $estudianteId = $this->model->crearEstudianteConPersona($datosPersona);
            } catch (\Exception $e) {
                log_error('Error al crear estudiante', ['error' => $e->getMessage()]);
                $this->redirectWithError(url('matriculas/crear'),
                    'No se pudo registrar al estudiante.');
            }
        }

        // 2) Evitar matrícula duplicada en el mismo año.
        if ($this->model->existeMatricula($estudianteId, $anioId)) {
            $this->redirectWithError(url('matriculas'),
                'El estudiante ya tiene una matrícula registrada este año.');
        }

        // 3) Sección: la posteada o la sugerida por el sistema.
        $seccionId = $seccionPost ?: $this->resolverSeccion($estudianteId, $gradoId, $anioId, $tipo);

        // 4) Crear matrícula (siempre 'pendiente').
        $matriculaId = $this->model->crear([
            'estudiante_id'  => $estudianteId,
            'seccion_id'     => $seccionId,
            'anio_id'        => $anioId,
            'tipo'           => $tipo,
            'serie_recibo'   => $serieRecibo,
            'registrado_por' => (int) (Session::user()['id'] ?? 0),
        ]);

        $this->redirectWithSuccess(
            url('matriculas/' . $matriculaId . '/apoderado'),
            'Matrícula creada en estado pendiente. Ahora vincula al apoderado.'
        );
    }

    /**
     * Resuelve la sección sugerida: continuador → misma letra del año
     * anterior; en otro caso (o si no se encuentra) → menos poblada.
     */
    private function resolverSeccion(int $estudianteId, int $gradoId, int $anioId, string $tipo): ?int
    {
        if ($tipo === 'continuador') {
            $anterior = $this->model->seccionAnioAnterior($estudianteId, (int) date('Y'));
            if ($anterior) {
                foreach ($this->model->listarSecciones($anioId, $gradoId) as $s) {
                    if ($s['nombre'] === $anterior['nombre']) {
                        return (int) $s['id'];
                    }
                }
            }
        }
        $sug = $this->model->sugerirSeccion($gradoId, $anioId);
        return $sug ? (int) $sug['id'] : null;
    }

    // ── GET /matriculas/{id}/apoderado ───────────────────────────
    public function apoderado(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $matricula = $this->requireMatricula((int) $id);

        $dni       = trim((string) $this->query('dni', ''));
        $apoderado = $dni !== '' ? $this->apoderados->buscarPorDni($dni) : null;

        $this->view('matriculas/apoderado', [
            'titulo'        => 'Matrícula — Apoderado',
            'paso'          => 2,
            'matricula'     => $matricula,
            'tiposVinculo'  => self::TIPOS_VINCULO,
            'vinculos'      => $this->apoderados->getVinculos((int) $matricula['estudiante_id']),
            'dni'           => $dni,
            'apoderado'     => $apoderado,
        ]);
    }

    // ── POST /matriculas/{id}/apoderado ──────────────────────────
    public function storeApoderado(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $this->validateCsrf();
        $matricula    = $this->requireMatricula((int) $id);
        $estudianteId = (int) $matricula['estudiante_id'];
        $anioId       = (int) $matricula['anio_id'];

        $tipoVinculo  = $this->input('tipo_vinculo');
        if (!array_key_exists($tipoVinculo, self::TIPOS_VINCULO)) {
            $this->redirectWithError(url('matriculas/' . $id . '/apoderado'),
                'Tipo de vínculo inválido.');
        }
        $esResponsable = (bool) $this->input('es_responsable');

        // Máximo 3 apoderados por estudiante (salvo que se actualice un tipo ya existente).
        $vinculos = $this->apoderados->getVinculos($estudianteId);
        $tiposActuales = array_column($vinculos, 'tipo_vinculo');
        if (count($vinculos) >= 3 && !in_array($tipoVinculo, $tiposActuales, true)) {
            $this->redirectWithError(url('matriculas/' . $id . '/apoderado'),
                'Un estudiante admite máximo 3 apoderados.');
        }

        // Apoderado existente (por id) o nuevo.
        $apoderadoId = (int) $this->input('apoderado_id');
        if (!$apoderadoId) {
            $dni = trim((string) $this->input('dni', ''));
            if ($dni === '' || !ctype_digit($dni) || strlen($dni) !== 8) {
                $this->redirectWithError(url('matriculas/' . $id . '/apoderado'),
                    'DNI del apoderado inválido (8 dígitos).');
            }
            try {
                $apoderadoId = $this->apoderados->crear([
                    'dni'              => $dni,
                    'apellido_paterno' => mb_strtoupper(trim((string) $this->input('apellido_paterno'))),
                    'apellido_materno' => mb_strtoupper(trim((string) $this->input('apellido_materno'))),
                    'nombres'          => mb_strtoupper(trim((string) $this->input('nombres'))),
                    'telefono'         => trim((string) $this->input('telefono')) ?: null,
                    'correo'           => trim((string) $this->input('correo')) ?: null,
                ]);
            } catch (\Exception $e) {
                log_error('Error al crear apoderado', ['error' => $e->getMessage()]);
                $this->redirectWithError(url('matriculas/' . $id . '/apoderado'),
                    'No se pudo registrar al apoderado.');
            }
        }

        // Máximo 3 estudiantes activos por apoderado en el año.
        if ($this->apoderados->contarHijosActivos($apoderadoId, $anioId) >= 3
            && !$this->yaVinculado($vinculos, $apoderadoId)) {
            $this->redirectWithError(url('matriculas/' . $id . '/apoderado'),
                'Este apoderado ya tiene 3 estudiantes vinculados este año.');
        }

        $this->apoderados->vincularEstudiante($apoderadoId, $estudianteId, $tipoVinculo, $esResponsable);

        // "Agregar otro" vuelve al paso 2; "continuar" pasa a documentos.
        if ($this->input('accion') === 'agregar_otro') {
            $this->redirectWithSuccess(url('matriculas/' . $id . '/apoderado'),
                'Apoderado vinculado. Puedes agregar otro.');
        }
        $this->redirectWithSuccess(url('matriculas/' . $id . '/documentos'),
            'Apoderado vinculado. Ahora registra los documentos.');
    }

    private function yaVinculado(array $vinculos, int $apoderadoId): bool
    {
        foreach ($vinculos as $v) {
            if ((int) $v['apoderado_id'] === $apoderadoId) {
                return true;
            }
        }
        return false;
    }

    // ── GET /matriculas/{id}/documentos ──────────────────────────
    public function documentos(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $matricula = $this->requireMatricula((int) $id);
        $requeridos = $matricula['tipo'] === 'nuevo' ? self::DOCS_NUEVO : self::DOCS_CONTINUADOR;

        // Mapea el estado actual de cada documento ya registrado.
        $actuales = [];
        foreach ($this->model->getDocumentos((int) $id) as $d) {
            $actuales[$d['tipo_documento']] = $d;
        }

        // Marcado de obligatoriedad para activar (el resto es opcional/ideal).
        $obligatorios = $matricula['tipo'] === 'nuevo'
            ? self::DOCS_OBLIGATORIOS_NUEVO
            : array_keys(self::DOCS_CONTINUADOR);

        $this->view('matriculas/documentos', [
            'titulo'       => 'Matrícula — Documentos',
            'paso'         => 3,
            'matricula'    => $matricula,
            'requeridos'   => $requeridos,
            'actuales'     => $actuales,
            'obligatorios' => $obligatorios,
            'grupoDni'     => $matricula['tipo'] === 'nuevo' ? self::DOCS_DNI_APODERADO : [],
        ]);
    }

    // ── POST /matriculas/{id}/documentos ─────────────────────────
    public function storeDocumentos(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $this->validateCsrf();
        $matricula  = $this->requireMatricula((int) $id);
        $usuarioId  = (int) (Session::user()['id'] ?? 0);
        $requeridos = $matricula['tipo'] === 'nuevo' ? self::DOCS_NUEVO : self::DOCS_CONTINUADOR;

        // Serie de recibo obligatoria — se actualiza siempre.
        $serie = trim((string) $this->input('serie_recibo', ''));
        if ($serie === '') {
            $this->redirectWithError(url('matriculas/' . $id . '/documentos'),
                'La serie del recibo es obligatoria.');
        }
        $this->model->update((int) $id, ['serie_recibo' => $serie]);

        $entregados = (array) ($this->input('entregado', []));
        $observaciones = (array) ($this->input('observacion', []));

        foreach ($requeridos as $tipo => $label) {
            $this->model->registrarDocumento(
                (int) $id,
                $tipo,
                isset($entregados[$tipo]),
                $usuarioId,
                trim((string) ($observaciones[$tipo] ?? '')) ?: null
            );
        }

        // Mantener el estado correcto: una matrícula 'aprobada' que quedó
        // incompleta (p.ej. se desmarcó un documento) vuelve a 'pendiente'
        // anotando los requisitos faltantes como motivo visible.
        $actual = $this->requireMatricula((int) $id);
        $faltan = $this->pendientesParaActivar($actual);
        if ($actual['estado'] === 'aprobada' && !empty($faltan)) {
            // Degradar a 'pendiente' la SACA del orden de mérito de los bimestres
            // cerrados no publicados (roster = matrículas aprobadas, 12/08/2026).
            // Es el TERCER sitio que mueve `matriculas.estado`, junto a activar()
            // y desactivar(): desmarcar un documento entregado también reescribe
            // el documento oficial, así que va en transacción y se avisa.
            $efectos = [];
            $this->model->beginTransaction();
            try {
                $this->model->cambiarEstado((int) $id, 'pendiente', $usuarioId,
                    'Faltan requisitos: ' . implode('; ', $faltan));
                $efectos = $this->ordenMerito->sincronizarRosterPorMatricula((int) $id, $usuarioId);
                $this->model->commit();
            } catch (\Exception $e) {
                $this->model->rollback();
                log_error('Error al degradar matrícula a pendiente', ['id' => $id, 'error' => $e->getMessage()]);
                $this->redirectWithError(url('matriculas/' . $id),
                    'Se registraron los documentos, pero no se pudo actualizar el estado de la matrícula.');
            }

            $this->redirectWithSuccess(url('matriculas/' . $id),
                'Documentos actualizados. La matrícula volvió a PENDIENTE porque aún faltan requisitos.'
                . OrdenMeritoModel::describirEfectosRoster($efectos));
        }

        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Documentos registrados correctamente.');
    }

    // ── GET /matriculas/{id} ─────────────────────────────────────
    public function show(string $id): void
    {
        $matricula  = $this->requireMatricula((int) $id);

        // Si es la matrícula OPERATIVA de un retorno activo, su detalle no es
        // accesible: es solo informativa. Toda la gestión vive en la oficial.
        $oficialId = $this->model->oficialSiEsOperativaEnRetornoActivo((int) $id);
        if ($oficialId !== null) {
            $this->redirectWithError(url('matriculas/' . $oficialId),
                'Esa es la matrícula operativa de un retorno de grado (solo informativa). La gestión se hace desde la matrícula oficial.');
        }

        $retorno    = $this->model->queryOne("
            SELECT r.*, g.nombre_display AS grado_destino
            FROM retornos_grado r
            INNER JOIN matriculas mo ON mo.id = r.matricula_operativa_id
            LEFT  JOIN secciones s   ON s.id = mo.seccion_id
            LEFT  JOIN grados g      ON g.id = s.grado_id
            WHERE r.matricula_oficial_id = ?
            LIMIT 1
        ", [(int) $id]);

        // Boleta INTERNA de gestion: la vista enlaza por id a
        // Boleta\BoletaController (mismo flujo que la del docente, muestra
        // BORRADOR mientras el bimestre no cierra), autenticada por rol. El
        // enlace publico por token (solo oficial) es otro camino, aparte.

        // Exoneraciones vigentes de la matrícula + opciones de registro (solo
        // roles de gestión; el registro va a Admin\ExoneracionController con el
        // candado de notas vivas).
        $puedeGestionar = has_role(['admin', 'registro_academico']);
        // Apoderados, documentos y notas externas los tocan TAMBIEN las
        // secretarias, asi que no caben bajo $puedeGestionar (admin/RA). Sin
        // este flag, los directores —que desde el 24/08/2026 entran a ver el
        // detalle— verian esos tres botones y se toparian con un 403.
        $puedeMatricular = has_role(self::ROLES_MATRICULAN);

        // Registrar notas de un estudiante que llegó tarde (18/09/2026): SIN
        // filtro por tipo o estado —ningún flag detecta el caso, lo decide
        // quien registra—, salvo el trasladado de SALIDA, que ya no está en el
        // colegio. Los bimestres pendientes solo los ve admin/RA, que son
        // quienes pueden abrir la grilla de Rectificación.
        $registraLlegadaTarde = $matricula['tipo'] !== 'trasladado';
        $pendientesExtra = ($registraLlegadaTarde && $puedeGestionar)
            ? (new RectificacionModel())->insertablesPorPeriodo((int) $id)
            : [];

        $this->view('matriculas/show', [
            'registraLlegadaTarde' => $registraLlegadaTarde,
            'pendientesExtra'      => $pendientesExtra,
            'titulo'       => 'Detalle de matrícula',
            'matricula'    => $matricula,
            'vinculos'     => $this->apoderados->getVinculos((int) $matricula['estudiante_id']),
            'documentos'   => $this->model->getDocumentos((int) $id),
            'notasExternas'=> $this->notasExternasModel->getDeMatricula((int) $id),
            'tiposVinculo' => self::TIPOS_VINCULO,
            'retorno'      => $retorno,
            'traslado'     => $this->traslados->getUltimaPorMatricula((int) $id),
            'puedeGestionar' => $puedeGestionar,
            'puedeMatricular' => $puedeMatricular,
            'exoneraciones'  => $this->exoneraciones->getVigentesPorMatricula((int) $id),
            'opcionesExoneracion' => $puedeGestionar
                ? $this->exoneraciones->getOpcionesParaSeccion(
                    (int) $matricula['seccion_id'],
                    (int) $matricula['anio_id']
                  )
                : [],
            'pendientes'   => $this->pendientesParaActivar($matricula),
            // En retorno de grado la evaluación (y las notas autorizadas) viven en
            // la matrícula OPERATIVA; la card apunta ahí.
            'notasAutSiagie' => $this->notasAut->getTodasPorMatricula((int) ($retorno['matricula_operativa_id'] ?? $id)),
            'matNotasSiagie' => (int) ($retorno['matricula_operativa_id'] ?? $id),
            'page_scripts' => ['matriculas'],
        ]);
    }

    // ── POST /matriculas/{id}/estudiante ─────────────────────────
    /**
     * Actualiza los DATOS PERSONALES del estudiante (tabla personas, compartida
     * por todos sus años). NO toca grado, sección ni estado de la matrícula
     * (eso se gestiona por Retorno/Traslado y por el flujo de estado). Solo
     * admin y registro_academico. Ante error: NO se conservan los cambios; el
     * detalle se recarga con el último registro guardado.
     */
    public function actualizarEstudiante(string $id): void
    {
        $this->requireRole(['admin', 'registro_academico']);
        $this->validateCsrf();

        $id        = (int) $id;
        $matricula = $this->model->findById($id);
        if (!$matricula) {
            $this->redirectWithError(url('matriculas'), 'Matrícula no encontrada.');
        }
        $volver    = url('matriculas/' . $id);
        $personaId = (int) $matricula['persona_id'];

        $dni      = trim((string) $this->input('dni', ''));
        $apePat   = trim((string) $this->input('apellido_paterno', ''));
        $apeMat   = trim((string) $this->input('apellido_materno', ''));
        $nombres  = trim((string) $this->input('nombres', ''));

        if ($apePat === '' || $apeMat === '' || $nombres === '') {
            $this->redirectWithError($volver, 'Apellidos y nombres son obligatorios.');
        }
        if (!ctype_digit($dni) || strlen($dni) !== 8) {
            $this->redirectWithError($volver, 'DNI inválido (8 dígitos).');
        }
        if ($this->model->dniEnUsoPorOtra($dni, $personaId)) {
            $this->redirectWithError($volver,
                'Ese DNI ya pertenece a otra persona registrada. Verifica si el estudiante '
                . 'ya existía en el sistema; la fusión de registros debe hacerse manualmente.');
        }

        $datos = [
            'dni'              => $dni,
            'apellido_paterno' => mb_strtoupper($apePat),
            'apellido_materno' => mb_strtoupper($apeMat),
            'nombres'          => mb_strtoupper($nombres),
            'fecha_nacimiento' => $this->input('fecha_nacimiento') ?: null,
            'sexo'             => in_array($this->input('sexo'), ['M', 'F'], true)
                ? $this->input('sexo') : null,
        ];

        try {
            $this->model->actualizarDatosPersonales($personaId, $datos);
        } catch (\Exception $e) {
            log_error('Error actualizando datos del estudiante', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError($volver, 'No se pudieron guardar los cambios. Intenta de nuevo.');
        }

        $this->redirectWithSuccess($volver, 'Datos del estudiante actualizados.');
    }

    /**
     * Lista los requisitos pendientes para que una matrícula pueda quedar 'activo'.
     * Una matrícula solo se considera COMPLETA cuando tiene al menos un apoderado
     * vinculado, serie de recibo y todos los documentos requeridos (según su tipo)
     * marcados como entregados. Devuelve etiquetas legibles; arreglo vacío = completa.
     */
    private function pendientesParaActivar(array $matricula): array
    {
        $faltan = [];
        $id     = (int) $matricula['id'];

        // 0) DNI real: un alta provisional (código 'P…') no puede activarse hasta
        //    reemplazar el código por el DNI real del estudiante. Esto impide que
        //    un DNI falso llegue a boletas / orden de mérito.
        if (es_dni_provisional($matricula['dni'] ?? null)) {
            $faltan[] = 'Reemplazar el DNI provisional por el DNI real del estudiante';
        }

        // 1) Al menos un apoderado vinculado.
        if (empty($this->apoderados->getVinculos((int) $matricula['estudiante_id']))) {
            $faltan[] = 'Vincular al menos un apoderado';
        }

        // 2) Serie del recibo.
        if (trim((string) ($matricula['serie_recibo'] ?? '')) === '') {
            $faltan[] = 'Registrar la serie del recibo';
        }

        // 3) Documentos obligatorios entregados. No se exige TODA la lista de
        //    DOCS_NUEVO (el resto es ideal pero opcional): solo el subconjunto
        //    obligatorio + al menos un DNI de apoderado del grupo flexible.
        $entregados = [];
        foreach ($this->model->getDocumentos($id) as $d) {
            $entregados[$d['tipo_documento']] = (int) $d['entregado'] === 1;
        }

        if ($matricula['tipo'] === 'nuevo') {
            foreach (self::DOCS_OBLIGATORIOS_NUEVO as $tipo) {
                if (empty($entregados[$tipo])) {
                    $faltan[] = 'Documento: ' . self::DOCS_NUEVO[$tipo];
                }
            }
            // Grupo "al menos uno": DNI del padre, de la madre o del apoderado.
            $tieneDniApoderado = false;
            foreach (self::DOCS_DNI_APODERADO as $tipo) {
                if (!empty($entregados[$tipo])) {
                    $tieneDniApoderado = true;
                    break;
                }
            }
            if (!$tieneDniApoderado) {
                $faltan[] = 'Documento: DNI del padre, de la madre o del apoderado (al menos uno)';
            }
        } else {
            foreach (self::DOCS_CONTINUADOR as $tipo => $label) {
                if (empty($entregados[$tipo])) {
                    $faltan[] = 'Documento: ' . $label;
                }
            }
        }

        return $faltan;
    }

    // ── POST /matriculas/{id}/activar ────────────────────────────
    public function activar(string $id): void
    {
        $this->validateCsrf();
        $this->requireRole(['admin', 'registro_academico']);
        $matricula = $this->requireMatricula((int) $id);

        // Solo se activa una matrícula COMPLETA (sin nada pendiente).
        $faltan = $this->pendientesParaActivar($matricula);
        if (!empty($faltan)) {
            $this->redirectWithError(url('matriculas/' . $id),
                'No se puede activar: la matrícula está incompleta. Pendiente — '
                . implode('; ', $faltan) . '.');
        }

        $usuarioId = (int) (Session::user()['id'] ?? 0);

        // Si la matrícula venía de un traslado o de un retiro quedó marcada
        // 'trasladado'/'retirado'. Al reactivar se restaura el ORIGEN real
        // preservado en `tipo_anterior` (reversibilidad 100%), de modo que vuelva
        // a su tipo nuevo/continuador y reaparezca en calificaciones. Fallback a
        // 'continuador' solo si no hubiera respaldo (datos previos a esta lógica).
        $cambiaTipo = in_array($matricula['tipo'] ?? '', ['trasladado', 'retirado'], true);

        // ¿Este acto mueve el ROSTER del orden de mérito? Desde el 12/08/2026 el
        // roster exige `estado='aprobada'`, así que aprobar una matrícula la
        // REINCORPORA (reversión simétrica, igual que revertir un retiro). Se
        // comprueba antes de escribir: `$matricula` trae el estado previo.
        // La condición evita el recálculo cuando activar no cambia nada — una
        // reactivación de una matrícula ya aprobada y del mismo tipo—, porque
        // `calcularFilasRanking` recorre el periodo entero.
        $mueveRoster = $cambiaTipo || ($matricula['estado'] ?? '') !== 'aprobada';

        // El cambio de estado y el ajuste del ranking son UN SOLO hecho: o se
        // activa y el orden de mérito queda coherente, o no ocurre ninguna.
        $efectos = [];
        $this->model->beginTransaction();
        try {
            // Activar = estado 'aprobada' (único estado vigente). El motivo se
            // limpia (null) porque ya no hay pendientes que reportar.
            $this->model->cambiarEstado((int) $id, 'aprobada', $usuarioId, null);

            // Reactivar las boletas públicas que se apagaron al desactivar/trasladar,
            // para que la matrícula reactivada vuelva a exponer su boleta.
            $this->model->execute(
                "UPDATE boletas_publicas SET activa = 1 WHERE matricula_id = ?",
                [(int) $id]
            );

            if ($cambiaTipo) {
                $original = $matricula['tipo_anterior'] ?? null;
                $this->model->update((int) $id, [
                    'tipo'          => in_array($original, ['continuador', 'nuevo'], true)
                        ? $original : 'continuador',
                    'tipo_anterior' => null,
                ]);
            }

            if ($mueveRoster) {
                $efectos = $this->ordenMerito->sincronizarRosterPorMatricula((int) $id, $usuarioId);
            }

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al activar matrícula', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo activar la matrícula.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Matrícula activada.' . OrdenMeritoModel::describirEfectosRoster($efectos));
    }

    // ── POST /matriculas/{id}/desactivar ─────────────────────────
    // Baja administrativa: apaga la matrícula PERO conserva su tipo
    // (continuador/nuevo). Reversible al reactivar. El traslado de salida (con
    // constancia + tipo='trasladado') vive en TrasladoController.
    public function desactivar(string $id): void
    {
        $this->validateCsrf();
        $this->requireRole(['admin', 'registro_academico']);
        $matricula = $this->requireMatricula((int) $id);
        $usuarioId = (int) (Session::user()['id'] ?? 0);

        // El motivo de la desactivación es OBLIGATORIO (queda visible junto al
        // estado). Sin él no se procesa la baja.
        $motivo = trim((string) $this->input('motivo'));
        if ($motivo === '') {
            $this->redirectWithError(url('matriculas/' . $id),
                'Debes indicar el motivo de la desactivación.');
        }

        $efectos = [];
        $this->model->beginTransaction();
        try {
            // estado=desactivado conservando el tipo; apaga login del apoderado
            // y códigos de boleta pública del periodo activo.
            $this->model->cambiarEstado((int) $id, 'desactivado', $usuarioId, $motivo);
            $this->apoderados->desactivarUsuarioDeEstudiante((int) $matricula['estudiante_id']);

            // Sale del orden de mérito de los bimestres cerrados no publicados:
            // desde el 12/08/2026 el roster exige `estado='aprobada'`. Va DENTRO
            // de la transacción — la baja y el ajuste del ranking son un solo
            // hecho. Solo actúa si venía de 'aprobada'; si ya estaba pendiente o
            // desactivada no formaba parte del documento.
            if (($matricula['estado'] ?? '') === 'aprobada') {
                $efectos = $this->ordenMerito->sincronizarRosterPorMatricula((int) $id, $usuarioId);
            }

            // Apaga las boletas públicas de TODOS los periodos de la matrícula,
            // no solo el activo: un alumno desactivado no debe exponer ninguna
            // boleta (la baja puede ocurrir en un bimestre posterior al de la
            // boleta ya generada e impresa).
            $this->model->execute(
                "UPDATE boletas_publicas SET activa = 0 WHERE matricula_id = ?",
                [(int) $id]
            );

            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al desactivar matrícula', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo desactivar la matrícula.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Matrícula desactivada.' . OrdenMeritoModel::describirEfectosRoster($efectos));
    }

    // ── POST /matriculas/{id}/retirar ────────────────────────────
    // Marca una matrícula ya DESACTIVADA como 'retirado': el estudiante ya no
    // asiste (sin traslado oficial) y NO debe calificarse. Lo excluye de los
    // rosters de calificaciones, conducta, transversales y tutoría (igual que un
    // trasladado), pero SIN constancia: su boleta sigue siendo BORRADOR interna.
    // El tipo real se preserva en `tipo_anterior` para poder revertir. Migración 045.
    public function retirar(string $id): void
    {
        $this->validateCsrf();
        $this->requireRole(['admin', 'registro_academico']);
        $matricula = $this->requireMatricula((int) $id);

        // Solo sobre una baja administrativa (desactivado) de tipo continuador o
        // nuevo. Un trasladado ya está fuera de los rosters; un retirado ya lo está.
        if ($matricula['estado'] !== 'desactivado'
            || !in_array($matricula['tipo'] ?? '', ['continuador', 'nuevo'], true)) {
            $this->redirectWithError(url('matriculas/' . $id),
                'Solo se puede marcar como retirada una matrícula desactivada de tipo continuador o nuevo.');
        }

        // El cambio de tipo y el ajuste del orden de mérito son UN SOLO hecho:
        // si el ranking falla, el retiro no se registra a medias.
        $this->model->beginTransaction();
        try {
            $this->model->update((int) $id, [
                'tipo'          => 'retirado',
                'tipo_anterior' => $matricula['tipo'],
            ]);
            $efectos = $this->ordenMerito->sincronizarRosterPorMatricula(
                (int) $id, (int) (Session::user()['id'] ?? 0)
            );
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al marcar retiro', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo marcar la matrícula como retirada.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Matrícula marcada como retirada: el estudiante deja de aparecer en calificaciones y conducta.'
            . OrdenMeritoModel::describirEfectosRoster($efectos));
    }

    // ── POST /matriculas/{id}/revertir-retiro ────────────────────
    // Deshace el marcado 'retirado': restaura el tipo real (tipo_anterior) y el
    // estudiante vuelve a los rosters de evaluación. La matrícula sigue
    // 'desactivado' (el retiro solo cambió el tipo, no el estado).
    public function revertirRetiro(string $id): void
    {
        $this->validateCsrf();
        $this->requireRole(['admin', 'registro_academico']);
        $matricula = $this->requireMatricula((int) $id);

        if (($matricula['tipo'] ?? '') !== 'retirado') {
            $this->redirectWithError(url('matriculas/' . $id),
                'Esta matrícula no está marcada como retirada.');
        }

        $original = $matricula['tipo_anterior'] ?? null;

        // Reversión SIMÉTRICA (decisión del usuario, 11/08/2026): al volver a
        // continuador/nuevo el estudiante REGRESA al orden de mérito de los
        // bimestres cerrados no publicados. Sus notas nunca se borraron, así que
        // el motor lo recalcula igual. Que revertir deshaga de verdad evita que
        // un retiro marcado por error lo deje fuera del documento para siempre.
        $this->model->beginTransaction();
        try {
            $this->model->update((int) $id, [
                'tipo'          => in_array($original, ['continuador', 'nuevo'], true) ? $original : 'continuador',
                'tipo_anterior' => null,
            ]);
            $efectos = $this->ordenMerito->sincronizarRosterPorMatricula(
                (int) $id, (int) (Session::user()['id'] ?? 0)
            );
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            log_error('Error al revertir retiro', ['id' => $id, 'error' => $e->getMessage()]);
            $this->redirectWithError(url('matriculas/' . $id), 'No se pudo revertir el retiro.');
        }

        $this->redirectWithSuccess(url('matriculas/' . $id),
            'Se revirtió el retiro: el estudiante vuelve a aparecer en calificaciones y conducta.'
            . OrdenMeritoModel::describirEfectosRoster($efectos));
    }

    // ── GET /matriculas/{id}/notas-externas ──────────────────────
    //
    // NOTAS DEL COLEGIO DE ORIGEN. Regla del colegio (10/09/2026): NO entran en
    // la boleta del COCIAP —la boleta lleva solo lo cursado aquí—; son
    // INFORMATIVAS para los docentes con carga en la sección del estudiante.
    //
    // ⚠️ Ya NO se exige `tipo === 'nuevo'`. Ese candado dejaba la pantalla fuera
    // del alcance de la mitad de los casos reales: de los 6 estudiantes que hoy
    // llegaron con un bimestre cerrado por delante, 3 figuran como
    // 'continuador'. El `tipo` no distingue este caso —y `tipo_matricula` menos
    // aún: sus 173 filas 'traslado_entrada' tienen el bimestre completo—, así
    // que la decisión de si corresponde registrar es de quien registra, no de
    // un flag. Ver docs/modulos/matriculas.md.
    public function notasExternas(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $matricula = $this->requireMatricula((int) $id);

        $mid       = (int) $id;
        $curricula = $this->notasExternasModel->curriculaParaImportar($mid);
        $yaHay     = $this->notasExternasModel->getDeMatricula($mid);

        // Los bimestres del año, UNA sola lectura para los dos usos que tienen
        // aquí: el importador y las casillas de la card. Sale de
        // `AnioAcademicoModel::getPeriodos()`, que ya existía y los devuelve
        // ordenados por número — antes eran dos consultas idénticas escritas a
        // mano en este mismo método.
        $periodos = (new AnioAcademicoModel())->getPeriodos((int) $matricula['anio_id']);

        // ── Importación de la currícula ──────────────────────────
        //
        // Transcribir a mano el informe del colegio anterior son 27-29
        // competencias POR BIMESTRE. Como la mayoría de colegios sigue el mismo
        // Currículo Nacional, se traen las nuestras ya escritas.
        //
        // ⚠️ NO ESCRIBE NADA: solo PRE-RELLENA las filas del formulario de
        // siempre, que siguen siendo editables. Guardar sigue siendo el POST.
        // Por eso el camino manual —el de una currícula extranjera— queda
        // intacto: es literalmente el mismo formulario, solo que en blanco.
        $periodosPedidos = array_map('intval', (array) $this->query('periodos', []));
        $areasPedidas    = array_map('intval', (array) $this->query('areas', []));
        $filasImportadas = [];
        $importar        = (bool) $this->query('importar');

        // 🔴 IMPORTAR SIN ELEGIR NADA NO PUEDE SER UN SILENCIO. Sin esta guarda
        // la pantalla recargaba idéntica y parecía que el botón no funcionaba.
        // El JS tampoco deja enviar el formulario vacío: la comprobación está
        // en las dos capas, como el resto del proyecto.
        if ($importar && ($periodosPedidos === [] || $areasPedidas === [])) {
            Session::flash('warning', $periodosPedidos === []
                ? 'Marca al menos un bimestre para traer sus competencias.'
                : 'Marca al menos un área para traer sus competencias.');
            redirect(url('matriculas/' . $mid . '/notas-externas'));
        }

        if ($importar) {
            // Lo ya registrado se excluye: importar dos veces no duplica filas
            // en pantalla. La clave es la misma que la UNIQUE (migración 059).
            $registradas = [];
            foreach ($yaHay as $n) {
                $registradas[$n['periodo_nombre'] . '||' . $n['area_nombre'] . '||' . $n['competencia_nombre']] = true;
            }

            foreach ($periodos as $p) {
                if (!in_array((int) $p['id'], $periodosPedidos, true)) {
                    continue;
                }
                foreach ($curricula as $area) {
                    if (!in_array((int) $area['area_id'], $areasPedidas, true)) {
                        continue;
                    }
                    foreach ($area['competencias'] as $comp) {
                        $clave = $p['nombre_display'] . '||' . $area['area_nombre'] . '||' . $comp;
                        if (isset($registradas[$clave])) {
                            continue;
                        }
                        $filasImportadas[] = [
                            'periodo_nombre'     => $p['nombre_display'],
                            'area_nombre'        => $area['area_nombre'],
                            'area_id'            => (int) $area['area_id'],
                            'competencia_nombre' => $comp,
                        ];
                    }
                }
            }
        }

        $this->view('matriculas/notas-externas', [
            'titulo'    => 'Notas del colegio de origen',
            'matricula' => $matricula,
            'notas'     => $yaHay,
            'areas'     => $this->notasExternasModel->areasDeLaSeccion($mid),
            'curricula' => $curricula,
            'periodos'  => $periodos,
            'filasImportadas' => $filasImportadas,
            'page_scripts'    => ['notas-externas'],
        ]);
    }

    // ── POST /matriculas/{id}/notas-externas ─────────────────────
    //
    // Alta EN LOTE: el informe de progreso del colegio de origen llega como un
    // documento entero, así que se captura entero y en una transacción. Las
    // filas incompletas se omiten sin error (el formulario nace con filas de
    // más para que no haya que ir añadiéndolas de una en una).
    public function storeNotasExternas(string $id): void
    {
        $this->requireRole(self::ROLES_MATRICULAN);
        $this->validateCsrf();
        $matricula = $this->requireMatricula((int) $id);

        $volver  = url('matriculas/' . $id . '/notas-externas');
        $colegio = trim((string) $this->input('colegio_origen')) ?: null;
        $areas   = (array) $this->input('area_nombre', []);
        $comps   = (array) $this->input('competencia_nombre', []);
        $periodos = (array) $this->input('periodo_nombre', []);
        $literales = (array) $this->input('nota_literal', []);
        $areaIds  = (array) $this->input('area_id', []);

        if ($colegio !== null && mb_strlen($colegio) > NotaExternaModel::MAX_COLEGIO) {
            $this->redirectWithError($volver,
                'El nombre del colegio de origen pasa de ' . NotaExternaModel::MAX_COLEGIO . ' caracteres.');
        }

        $filas    = [];
        $sinNota  = 0;   // filas con competencia pero sin calificación: se omiten
        foreach ($areas as $i => $areaNombre) {
            $areaNombre = trim((string) $areaNombre);
            $comp       = trim((string) ($comps[$i] ?? ''));
            $periodo    = trim((string) ($periodos[$i] ?? ''));
            $literal    = (string) ($literales[$i] ?? '');

            // 🔴 SIN NOTA, NO SE REGISTRA — y no es un error.
            //
            // Antes se omitía solo la fila con los CUATRO campos vacíos, lo que
            // bastaba mientras el formulario nacía en blanco. Con el importador
            // deja de valer: una fila importada llega con periodo, área y
            // competencia llenos y la nota vacía, así que importar 58 y llenar
            // 20 reventaba el guardado en la fila 21. Y es el caso NORMAL: el
            // informe de origen no trae todas las competencias del plan.
            //
            // Las omitidas se cuentan y se dicen en el mensaje de éxito, para
            // que la omisión no sea silenciosa.
            if ($literal === '') {
                if ($areaNombre !== '' || $comp !== '' || $periodo !== '') {
                    $sinNota++;
                }
                continue;
            }

            // Con nota, los tres datos que la identifican son obligatorios.
            if ($areaNombre === '' || $comp === '' || $periodo === ''
                || !in_array($literal, NotaExternaModel::LITERALES, true)) {
                $this->redirectWithError($volver,
                    'La fila ' . ($i + 1) . ' tiene nota pero le falta área, competencia o periodo.');
            }

            // 🔴 RECHAZAR, NO RECORTAR: sin modo estricto, MariaDB cortaría el
            // exceso en silencio y guardaría otra competencia (migración 061).
            if (mb_strlen($periodo) > NotaExternaModel::MAX_PERIODO
                || mb_strlen($areaNombre) > NotaExternaModel::MAX_AREA
                || mb_strlen($comp) > NotaExternaModel::MAX_COMPETENCIA) {
                $this->redirectWithError($volver,
                    'La fila ' . ($i + 1) . ' tiene un texto demasiado largo (periodo hasta '
                    . NotaExternaModel::MAX_PERIODO . ', área hasta ' . NotaExternaModel::MAX_AREA
                    . ' y competencia hasta ' . NotaExternaModel::MAX_COMPETENCIA . ' caracteres).');
            }

            $filas[] = [
                'periodo_nombre'     => $periodo,
                'competencia_nombre' => $comp,
                'area_nombre'        => $areaNombre,
                'area_id'            => (int) ($areaIds[$i] ?? 0) ?: null,
                'nota_literal'       => $literal,
            ];
        }

        if ($filas === []) {
            $this->redirectWithError($volver, 'No ingresaste ninguna nota.');
        }

        // La transacción la owna el controlador: el modelo ya no la abre, para
        // que el lote se pueda envolver desde fuera (PDO no anida).
        $this->notasExternasModel->beginTransaction();
        try {
            $n = $this->notasExternasModel->registrarLote(
                (int) $id, $filas, $colegio, (int) (Session::user()['id'] ?? 0)
            );
            $this->notasExternasModel->commit();
        } catch (\Exception $e) {
            $this->notasExternasModel->rollback();
            log_error('Error al registrar notas del colegio de origen', [
                'matricula' => (int) $id, 'filas' => count($filas),
                'error' => $e->getMessage(),
            ]);
            $this->redirectWithError($volver, 'No se pudo registrar. No se guardó ninguna nota.');
        }

        // Aviso a los docentes con carga en su sección: estas notas NO salen en
        // la boleta, así que sin la notificación el docente no sabría que
        // existen. Fuera de la transacción del lote a propósito: que falle el
        // aviso no puede tumbar un registro ya válido.
        $avisados = 0;
        try {
            $avisados = (new NotificacionModel())->crearParaDocentesDeSeccion(
                (int) $id,
                NotificacionModel::TIPO_NOTAS_ORIGEN,
                'Notas del colegio de origen: ' . $matricula['nombre_completo'],
                'Se registraron las calificaciones que ' . $matricula['nombre_completo']
                    . ' trae de su colegio anterior. Son informativas: no aparecen en la boleta del COCIAP.',
                'docente/notas-origen/' . (int) $id
            );
        } catch (\Exception $e) {
            log_error('No se pudo notificar a los docentes de las notas de origen', [
                'matricula' => (int) $id, 'error' => $e->getMessage(),
            ]);
        }

        $this->redirectWithSuccess($volver,
            $n . ($n === 1 ? ' nota registrada' : ' notas registradas') . '.'
            // Las filas sin calificación se omiten a propósito (el informe de
            // origen no trae todas las competencias del plan), pero se dicen:
            // una omisión silenciosa parecería una pérdida de datos.
            . ($sinNota > 0
                ? ' Se omitieron ' . $sinNota . ($sinNota === 1 ? ' fila sin nota.' : ' filas sin nota.')
                : '')
            . ($avisados > 0
                ? ' Se avisó a ' . $avisados . ($avisados === 1 ? ' docente' : ' docentes') . ' de su sección.'
                : ''));
    }

    // ── Notas autorizadas por dirección para SIAGIE (informe aparte) ─────
    //
    // Dirección ordena consignar una nota para un alumno NO evaluado por
    // ausencia justificada (salud, accidente, viaje), VÁLIDA SOLO PARA EL
    // SIAGIE. No toca calificaciones, boleta ni orden de mérito: solo rellena
    // la celda en blanco del export. Candado: la competencia debe tener omisión
    // justificada, estar bloqueada y sin nota real. Ver NotaAutorizadaSiagieModel.

    /** GET /matriculas/{id}/notas-siagie — pantalla de gestión. */
    public function notasSiagie(string $id): void
    {
        // En retorno de grado la evaluación vive en la operativa: operamos ahí.
        $eval      = $this->matriculaEvaluacion((int) $id);
        $matricula = $this->requireMatricula($eval);
        if (!has_role(['admin', 'registro_academico'])) {
            $this->redirectWithError(url('matriculas/' . $id),
                'Solo Registro académico / dirección gestiona las notas autorizadas.');
        }
        $seccionId = (int) $matricula['seccion_id'];

        // Un bloque por bimestre del año con elegibles o ya registradas.
        $periodos = $this->model->query("
            SELECT id, numero, nombre_display, estado
            FROM periodos WHERE anio_id = ? ORDER BY numero
        ", [(int) $matricula['anio_id']]);

        $bloques = [];
        foreach ($periodos as $per) {
            $pid         = (int) $per['id'];
            $elegibles   = $this->notasAut->competenciasElegibles($eval, $seccionId, $pid);
            $registradas = $this->notasAut->getDetalle($eval, $pid);
            if ($elegibles === [] && $registradas === []) {
                continue;
            }
            $bloques[] = [
                'periodo'     => $per,
                'elegibles'   => $elegibles,
                'registradas' => $registradas,
            ];
        }

        $this->view('matriculas/notas-siagie', [
            'titulo'    => 'Notas autorizadas para SIAGIE',
            'matricula' => $matricula,
            'bloques'   => $bloques,
            'nivel'     => mb_strtolower((string) ($matricula['nivel_nombre'] ?? '')),
        ]);
    }

    /** POST /matriculas/{id}/notas-siagie — registra/actualiza una nota autorizada. */
    public function storeNotaSiagie(string $id): void
    {
        $this->validateCsrf();
        $eval      = $this->matriculaEvaluacion((int) $id);
        $matricula = $this->requireMatricula($eval);
        if (!has_role(['admin', 'registro_academico'])) {
            $this->redirectWithError(url('matriculas/' . $id), 'No autorizado.');
        }
        $volver     = url('matriculas/' . $eval . '/notas-siagie');
        $seccionId  = (int) $matricula['seccion_id'];
        $competencia= (int) $this->input('competencia_id');
        $periodo    = (int) $this->input('periodo_id');
        $literal    = (string) $this->input('nota_literal');
        $conclusion = trim((string) $this->input('conclusion_descriptiva'));
        $resolucion = trim((string) $this->input('resolucion'));

        if (!in_array($literal, ['AD', 'A', 'B', 'C'], true)) {
            $this->redirectWithError($volver, 'Nota literal inválida.');
        }
        if ($resolucion === '') {
            $this->redirectWithError($volver, 'La resolución/autorización de dirección es obligatoria.');
        }
        // Candado de servidor: omisión justificada + bloqueada + sin nota real.
        if (!$this->notasAut->esElegible($eval, $seccionId, $periodo, $competencia)) {
            $this->redirectWithError($volver,
                'Esa competencia no es autorizable: requiere omisión justificada, competencia bloqueada y sin nota real.');
        }
        // Conclusión según las reglas vigentes de la escala.
        $nivel = mb_strtolower((string) ($matricula['nivel_nombre'] ?? ''));
        if (conclusion_es_obligatoria($literal, $nivel) && $conclusion === '') {
            $this->redirectWithError($volver,
                'Para esta nota literal, la conclusión descriptiva es obligatoria.');
        }

        $this->notasAut->registrar([
            'matricula_id'           => $eval,
            'competencia_id'         => $competencia,
            'periodo_id'             => $periodo,
            'nota_literal'           => $literal,
            'conclusion_descriptiva' => $conclusion !== '' ? $conclusion : null,
            'resolucion'             => $resolucion,
            'registrado_por'         => (int) (Session::user()['id'] ?? 0),
        ]);

        $this->redirectWithSuccess($volver, 'Nota autorizada registrada.');
    }

    /** POST /matriculas/{id}/notas-siagie/eliminar — quita una nota autorizada. */
    public function eliminarNotaSiagie(string $id): void
    {
        $this->validateCsrf();
        $eval = $this->matriculaEvaluacion((int) $id);
        $this->requireMatricula($eval);
        if (!has_role(['admin', 'registro_academico'])) {
            $this->redirectWithError(url('matriculas/' . $id), 'No autorizado.');
        }
        $this->notasAut->eliminar((int) $this->input('reg_id'), $eval);
        $this->redirectWithSuccess(url('matriculas/' . $eval . '/notas-siagie'),
            'Nota autorizada eliminada.');
    }

    /** GET /matriculas/{id}/notas-siagie/informe — informe imprimible (respaldo). */
    public function informeNotaSiagie(string $id): void
    {
        $eval      = $this->matriculaEvaluacion((int) $id);
        $matricula = $this->requireMatricula($eval);
        if (!has_role(['admin', 'registro_academico'])) {
            $this->redirectWithError(url('matriculas/' . $id), 'No autorizado.');
        }

        // Todas las autorizadas del alumno, agrupadas por bimestre.
        $periodos = $this->model->query("
            SELECT id, numero, nombre_display
            FROM periodos WHERE anio_id = ? ORDER BY numero
        ", [(int) $matricula['anio_id']]);

        $bloques = [];
        foreach ($periodos as $per) {
            $registradas = $this->notasAut->getDetalle($eval, (int) $per['id']);
            if ($registradas !== []) {
                $bloques[] = ['periodo' => $per, 'registradas' => $registradas];
            }
        }

        $director = (new DirectorEbrModel())->getVigenteEnFecha((int) $matricula['anio_id']);

        View::setLayout('print');
        $this->view('matriculas/notas-siagie-informe', [
            'titulo'    => 'Informe de notas autorizadas — SIAGIE',
            'matricula' => $matricula,
            'bloques'   => $bloques,
            'director'  => $director,
        ]);
    }

    /**
     * Matrícula donde vive la EVALUACIÓN del alumno. En retorno de grado el
     * alumno se evalúa (y sus omisiones/notas autorizadas viven) en la matrícula
     * OPERATIVA, aunque la gestión general se haga desde la oficial. Fuera de
     * retorno devuelve el mismo id. Espeja la unión de `boletaContexto`.
     */
    private function matriculaEvaluacion(int $id): int
    {
        $r = $this->model->queryOne(
            "SELECT matricula_operativa_id FROM retornos_grado
             WHERE matricula_oficial_id = ? OR matricula_operativa_id = ?
             ORDER BY id DESC LIMIT 1",
            [$id, $id]
        );
        return $r ? (int) $r['matricula_operativa_id'] : $id;
    }

    /** Carga la matrícula o muestra 404 si no existe. */
    private function requireMatricula(int $id): array
    {
        $matricula = $this->model->findById($id);
        if (!$matricula) {
            // `notFound()` es el punto único del 404 (BaseController): responde
            // 404 y carga `shared/404.php` SIN layout. Con `view()` esa página
            // —que es un HTML completo— quedaba anidada dentro del layout.
            $this->notFound();
        }
        return $matricula;
    }
}
