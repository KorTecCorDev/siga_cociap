<?php

namespace App\Controllers;

use App\Models\AnioAcademicoModel;
use App\Models\BoletaPublicaModel;
use App\Models\CalificacionModel;
use App\Models\ConductaModel;
use App\Models\DirectorEbrModel;
use App\Models\ExoneracionModel;
use App\Models\OmisionCriterioModel;
use Core\View;

class BoletaPublicaController extends BaseController
{
    private BoletaPublicaModel $bpModel;
    private CalificacionModel  $calModel;
    private ConductaModel      $conductaModel;
    private DirectorEbrModel   $dirModel;
    private ExoneracionModel   $exoModel;
    private OmisionCriterioModel $omisionModel;

    public function __construct()
    {
        // Sin requireAuth() ni requireRole() — acceso público sin login
        $this->bpModel       = new BoletaPublicaModel();
        $this->calModel      = new CalificacionModel();
        $this->conductaModel = new ConductaModel();
        $this->dirModel      = new DirectorEbrModel();
        $this->exoModel      = new ExoneracionModel();
        $this->omisionModel  = new OmisionCriterioModel();
    }

    /** GET /boleta-publica — formulario para ingresar código */
    public function formulario(): void
    {
        View::setLayout('digital');
        $this->view('boleta-publica/formulario', [
            'titulo'      => 'Consulta de Boleta',
            'institucion' => config('institucion'),
        ]);
    }

    /** POST /boleta-publica/consultar — valida código y renderiza boleta */
    public function consultar(): void
    {
        $this->validateCsrf();

        // Rate limiting: frena el escaneo/fuerza bruta de códigos.
        // Máx 15 intentos por IP cada 5 minutos.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
        if (\Core\Throttle::hit('boleta-publica:' . $ip, 15, 300)) {
            http_response_code(429);
            $this->mostrarFormularioConError(
                'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.',
                ''
            );
            return;
        }

        $codigo = trim(strtoupper($_POST['codigo_acceso'] ?? ''));

        if (empty($codigo)) {
            $this->mostrarFormularioConError('Ingresa un código de acceso.', $codigo);
            return;
        }

        // Charset/longitud antes de tocar BD: el código solo lleva mayúsculas,
        // dígitos y guiones (formato COCIAP-2026-B1-XXXXXX). Filtra basura y
        // payloads sin acoplarse al formato exacto (robusto a cambios futuros).
        // Mismo mensaje que "no válido" para no revelar si el formato acertó.
        if (!preg_match('/^[A-Z0-9-]{1,40}$/', $codigo)) {
            $this->mostrarFormularioConError('Código no válido. Verifica e intenta de nuevo.', $codigo);
            return;
        }

        $registro = $this->bpModel->getPorCodigo($codigo);

        if (!$registro) {
            $this->mostrarFormularioConError('Código no válido. Verifica e intenta de nuevo.', $codigo);
            return;
        }

        $matriculaId = (int) $registro['matricula_id'];
        $periodoId   = (int) $registro['periodo_id'];
        $data        = $this->buildBoletaPublicaData($matriculaId, $periodoId);

        if (!$data) {
            $this->mostrarFormularioConError('No se pudo cargar la boleta. Contacta al colegio.', $codigo);
            return;
        }

        View::setLayout('digital');
        $this->view('boleta-publica/boleta', array_merge($data, [
            'titulo'     => 'Boleta — ' . $data['alumno']['nombre_completo'],
            'url_boleta' => url('boleta-publica') . '?codigo=' . urlencode($codigo),
            'codigo'     => $codigo,
        ]));
    }

    // ── Helpers privados ────────────────────────────────────────

    private function mostrarFormularioConError(string $error, string $codigo): void
    {
        View::setLayout('digital');
        $this->view('boleta-publica/formulario', [
            'titulo'      => 'Consulta de Boleta',
            'institucion' => config('institucion'),
            'error'       => $error,
            'codigo'      => $codigo,
        ]);
    }

    private function buildBoletaPublicaData(int $matriculaId, int $periodoId): ?array
    {
        // Retorno de grado: la boleta pública también se rotula con la matrícula
        // oficial (grado/sección SIAGIE) y une las notas de las matrículas
        // involucradas. En el caso normal, identidad y única fuente coinciden.
        $ctx       = $this->calModel->boletaContexto($matriculaId);
        $identidad = (int) $ctx['identidad'];
        $fuentes   = $ctx['fuentes'];

        $alumno  = $this->getAlumno($identidad);
        $periodo = $this->getPeriodo($periodoId);

        if (!$alumno || !$periodo) return null;

        $periodos = $this->getPeriodosDelAnio((int) $periodo['anio_id']);

        $datosPorPeriodo = [];
        foreach ($periodos as $p) {
            $rows = [];
            foreach ($fuentes as $mid) {
                $rows = array_merge($rows, $this->calModel->getBoletaAlumno((int) $mid, $p['id']));
            }
            $datosPorPeriodo[$p['id']] = $rows;
        }

        $anioId = (int) $periodo['anio_id'];
        // Logro anual = nota del ULTIMO bimestre del anio, visible solo si esta
        // cerrado (ancla unica: AnioAcademicoModel::ultimoPeriodoDelAnio).
        $ultimoBim        = (new AnioAcademicoModel())->ultimoPeriodoDelAnio($anioId);
        $ultimoBimestreId = $ultimoBim ? (int) $ultimoBim['id'] : 0;
        $ultimoCerrado    = $ultimoBim !== null && $ultimoBim['estado'] === 'cerrado';
        $areas  = $this->buildAreasConBimestres($datosPorPeriodo, $periodos, $ultimoBimestreId, $ultimoCerrado);
        $exoData = $this->exoModel->getConCompetenciasParaBoletaUnion($fuentes, $anioId);
        $areas  = ExoneracionModel::inyectarEnAreas($areas, $exoData, $periodos);

        return [
            'alumno'      => $alumno,
            'periodos'    => $periodos,
            'areas'       => $areas,
            'conducta'    => $this->conductaModel->getParaBoletaUnion($fuentes, $anioId),
            'omisiones'   => $this->omisionModel->getPorMatriculaAnioUnion($fuentes, $anioId),
            'institucion' => config('institucion'),
            'tutor'       => $this->getTutorSeccion($identidad),
            'directorEbr' => $this->dirModel->getVigenteEnFecha($anioId),
        ];
    }

    private function getTutorSeccion(int $matriculaId): ?array
    {
        $seccion = $this->calModel->queryOne("
            SELECT s.tutor_id
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            WHERE m.id = ?
            LIMIT 1
        ", [$matriculaId]);

        $tutorId = (int) ($seccion['tutor_id'] ?? 0);
        if (!$tutorId) {
            return null;
        }

        $persona = $this->calModel->queryOne("
            SELECT p.apellido_paterno, p.apellido_materno, p.nombres, p.sexo
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE u.id = ?
            LIMIT 1
        ", [$tutorId]);

        if (!$persona || empty($persona['apellido_paterno'])) {
            return null;
        }

        return [
            'nombre' => $persona['apellido_paterno'] . ' '
                      . $persona['apellido_materno'] . ', '
                      . $persona['nombres'],
            'sexo'   => $persona['sexo'],
        ];
    }

    private function getAlumno(int $matriculaId): ?array
    {
        return $this->calModel->queryOne("
            SELECT
                m.id                AS matricula_id,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.dni,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                )                   AS nombre_completo,
                g.nombre_display    AS grado_nombre,
                s.nombre            AS seccion_nombre,
                n.nombre            AS nivel_nombre,
                n.codigo            AS nivel_codigo,
                n.escala_boleta,
                a.anio              AS anio_academico
            FROM matriculas m
            INNER JOIN estudiantes e       ON e.id = m.estudiante_id
            INNER JOIN personas p          ON p.id = e.persona_id
            INNER JOIN secciones s         ON s.id = m.seccion_id
            INNER JOIN grados g            ON g.id = s.grado_id
            INNER JOIN niveles n           ON n.id = g.nivel_id
            INNER JOIN anios_academicos a  ON a.id = m.anio_id
            WHERE m.id = ?
            LIMIT 1
        ", [$matriculaId]);
    }

    private function getPeriodo(int $periodoId): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.id, p.anio_id, a.anio,
                   CONCAT(p.nombre_display, ' — ', a.anio) AS nombre_display
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
            LIMIT 1
        ", [$periodoId]);
    }

    private function getPeriodosDelAnio(int $anioId): array
    {
        // F4 — Boleta publica (familias): SOLO bimestres CERRADOS (oficiales). El
        // borrador (Hito A) nunca se expone a los padres. La vista publica es
        // exclusiva de familias, asi que el filtro va directo en la query.
        return $this->calModel->query("
            SELECT id, numero, nombre_display
            FROM periodos
            WHERE anio_id = ? AND estado = 'cerrado'
            ORDER BY numero
        ", [$anioId]);
    }

    /**
     * ⚠️ COPIA DORMIDA, DIVERGENTE DESDE EL 05/08/2026. Este controlador es la
     * consulta publica por codigo, cuyas rutas estan comentadas en routes/web.php.
     * `BoletaModel::buildAreasConBimestres` —el que usan las 9 entradas vivas—
     * ahora siembra el ESQUELETO DEL PLAN: muestra todas las competencias que la
     * seccion dicta, con guion donde no hay nota. Esta copia sigue construyendo
     * las filas SOLO a partir de las notas.
     * Si algun dia se reactivan esas rutas, hay que portar el esqueleto aqui (o,
     * mejor, hacer que este controlador use BoletaModel) o el mismo alumno vera
     * dos documentos con distinto numero de filas.
     */
    private function buildAreasConBimestres(
        array $datosPorPeriodo,
        array $periodos,
        int $ultimoBimestreId,
        bool $ultimoCerrado
    ): array {
        $areas = [];

        foreach ($datosPorPeriodo as $periodoId => $notas) {
            foreach ($notas as $nota) {
                $nombreArea = $nota['nombre_boleta'] ?? $nota['area_nombre'];
                if (!empty($nota['alias_boleta'])) {
                    $nombreArea .= ' ' . $nota['alias_boleta'];
                }
                $compId = $nota['competencia_id'];

                if (!isset($areas[$nombreArea][$compId])) {
                    $prefijoSubarea = '';
                    if (($nota['area_tipo'] ?? '') === 'con_subareas' && !empty($nota['subarea_nombre'])) {
                        $prefijoSubarea = $nota['subarea_nombre'] . ' — ';
                    }
                    $areas[$nombreArea][$compId] = [
                        'nombre'            => trim(
                            $prefijoSubarea .
                            ($nota['codigo_minedu'] ? $nota['codigo_minedu'] . '. ' : '') .
                            ($nota['nombre_corto'] ?? $nota['competencia_nombre'] ?? '')
                        ),
                        'nombre_largo'      => trim($prefijoSubarea . ($nota['competencia_nombre'] ?? '')),
                        'subarea_nombre'    => ($nota['area_tipo'] ?? '') === 'con_subareas' ? ($nota['subarea_nombre'] ?? '') : '',
                        'competencia_texto' => $nota['competencia_nombre'] ?? '',
                        'bimestres'         => [],
                    ];
                }

                $notaNum = isset($nota['nota_numerica']) ? (int) $nota['nota_numerica'] : null;
                $areas[$nombreArea][$compId]['bimestres'][$periodoId] = [
                    'nota'       => $notaNum,
                    'literal'    => $notaNum !== null ? CalificacionModel::toLiteral($notaNum) : null,
                    'conclusion' => $nota['conclusion_descriptiva'] ?? null,
                ];
            }
        }

        // Logro anual = literal de la nota del ULTIMO bimestre del anio, SOLO si
        // ese bimestre esta cerrado (paridad con BoletaModel). No se promedia.
        foreach ($areas as &$comps) {
            foreach ($comps as &$comp) {
                $b = $ultimoCerrado ? ($comp['bimestres'][$ultimoBimestreId] ?? null) : null;
                $comp['literal_final'] = ($b !== null && $b['nota'] !== null)
                    ? $b['literal']
                    : null;
            }
        }
        unset($comps, $comp);

        return $areas;
    }
}
