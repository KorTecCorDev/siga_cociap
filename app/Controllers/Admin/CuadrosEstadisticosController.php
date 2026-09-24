<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AnioAcademicoModel;
use App\Models\AsistenciaModel;
use App\Models\ConductaModel;
use App\Models\ControlOperativoModel;
use App\Models\DirectorEbrModel;
use App\Models\MatriculaModel;
use App\Models\OrdenMeritoModel;
use App\Models\SeccionModel;
use App\Models\SituacionFinalModel;
use Core\View;

/**
 * CuadrosEstadisticosController
 *
 * Tablero de indicadores del bimestre para DIRECCIÓN (24/08/2026): reúne en una
 * pantalla los cinco registros que hasta hoy vivían desperdigados —matrícula en
 * `/matriculas/resumen`, calificaciones en `/director/periodos/{id}/stats`,
 * conducta y asistencia solo dentro de sus pantallas de escritura, y el mérito
 * en su propio módulo—.
 *
 * 🔴 REGLA DE ORO: esta clase COMPONE, no calcula. Cada bloque llama al método
 * que ya existe y ya se usa en otra pantalla. NO se escribe un SELECT nuevo aquí
 * ni se reimplementa ninguna regla de negocio: duplicar reglas a mano es el
 * patrón con el que ya divergieron cuatro veces en este repositorio (la cascada
 * de empates, el retorno de grado, el universo del mérito y la "carga dueña" de
 * las transversales). Si un indicador hace falta y no existe, se añade al MODELO
 * que lo posee y se llama desde aquí.
 *
 * Es de SOLO LECTURA: no expone ninguna acción.
 */
class CuadrosEstadisticosController extends BaseController
{
    private AnioAcademicoModel $anioModel;
    private MatriculaModel     $matriculaModel;
    private ConductaModel      $conductaModel;
    private AsistenciaModel    $asistenciaModel;
    private OrdenMeritoModel   $meritoModel;
    private ControlOperativoModel $controlModel;
    private SeccionModel       $seccionModel;
    private SituacionFinalModel $situacionModel;

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico', ...ROLES_DIRECCION]);

        $this->anioModel       = new AnioAcademicoModel();
        $this->matriculaModel  = new MatriculaModel();
        $this->conductaModel   = new ConductaModel();
        $this->asistenciaModel = new AsistenciaModel();
        $this->meritoModel     = new OrdenMeritoModel();
        $this->controlModel    = new ControlOperativoModel();
        $this->seccionModel    = new SeccionModel();
        $this->situacionModel  = new SituacionFinalModel();
    }

    /**
     * GET /admin/cuadros  (acepta ?periodo_id)
     */
    public function index(): void
    {
        // El selector de bimestre sale de `ControlOperativoModel`, que ya lo
        // resuelve (lista + "por defecto"). Escribir aqui el SELECT habria sido
        // la QUINTA copia de esa misma consulta en el repositorio: ya vive en
        // ese modelo y, copiada a mano, en tres controladores mas.
        $periodos = $this->periodosVisibles();

        [$periodo, $fueraDeAlcance] = $this->elegirPeriodo(
            $periodos,
            (int) ($this->query('periodo_id') ?? 0)
        );

        if (!$periodo) {
            $this->view('admin/cuadros/index', [
                'titulo'   => 'Cuadros estadísticos',
                'periodos' => $periodos,
                'periodo'  => null,
                'bloques'  => null,
                'serieIds' => $this->serieIds($periodos),
                'avisoNoCerrado' => false,
            ]);
            return;
        }

        $this->view('admin/cuadros/index', [
            'titulo'   => 'Cuadros estadísticos',
            'periodos' => $periodos,
            'periodo'  => $periodo,
            'bloques'  => $this->componerBloques($periodo),
            'serieIds' => $this->serieIds($periodos),
            // La pantalla no niega el documento: cae al ultimo cerrado y lo
            // dice. Negarlo dejaria al director ante un 404 sin saber por que.
            'avisoNoCerrado' => $fueraDeAlcance,
        ]);
    }

    /**
     * GET /admin/cuadros/imprimir  (acepta ?periodo_id)
     *
     * Version A4 del tablero, para las reuniones de Dirección y los informes.
     * Comparte con index() la composición de bloques: si cada uno armara la
     * suya, la pantalla y el papel podrían decir cifras distintas del MISMO
     * bimestre. Los roles los hereda del constructor.
     */
    public function imprimir(): void
    {
        $periodos = $this->periodosVisibles();

        [$periodo, $fueraDeAlcance] = $this->elegirPeriodo(
            $periodos,
            (int) ($this->query('periodo_id') ?? 0)
        );

        // Un documento sin bimestre no existe: aqui no hay estado vacio que
        // mostrar, a diferencia de la pantalla. Y uno del bimestre equivocado
        // tampoco: el A4 va firmado con el sello del Director EBR, asi que
        // aqui NO se cae al ultimo cerrado, se niega.
        if (!$periodo || $fueraDeAlcance) {
            $this->notFound();
        }

        View::setLayout('print');
        $this->view('admin/cuadros/imprimir', [
            'titulo'      => 'Cuadros estadísticos',
            'periodo'     => $periodo,
            'bloques'     => $this->componerBloques($periodo),
            'serieIds'    => $this->serieIds($periodos),
            'directorEbr' => (new DirectorEbrModel())->getVigenteEnFecha((int) $periodo['anio_id']),
        ]);
    }

    /**
     * GET /admin/cuadros/riesgo  (acepta ?periodo_id, ?secciones[], ?primaria=c)
     *
     * Informe de estudiantes en riesgo académico (23/09/2026). Nació como el
     * bloque 3b del tablero y se sacó a su propia vista: con la regla de
     * primaria (B+C) el listado pasa de ~120 a ~200 estudiantes y ~1 400 filas
     * de desglose, que no caben en un tablero. En `/admin/cuadros` queda la
     * banda con un enlace aquí.
     *
     * Mismos roles y misma regla de bimestres que el tablero (Dirección solo
     * cerrados): los hereda del constructor y de `elegirPeriodo()`.
     */
    public function riesgo(): void
    {
        $periodos = $this->periodosVisibles();

        [$periodo, $fueraDeAlcance] = $this->elegirPeriodo(
            $periodos,
            (int) ($this->query('periodo_id') ?? 0)
        );

        $this->view('admin/cuadros/riesgo/index', [
            'titulo'         => 'Estudiantes en riesgo académico',
            'periodos'       => $periodos,
            'periodo'        => $periodo,
            'riesgo'         => $periodo ? $this->componerRiesgo($periodo) : null,
            'avisoNoCerrado' => $fueraDeAlcance,
        ]);
    }

    /**
     * GET /admin/cuadros/riesgo/imprimir  (acepta ?periodo_id, ?secciones[])
     *
     * A4 vertical del informe. Imprime LO FILTRADO (decisión del usuario,
     * 23/09/2026): sin filtro son ~40 hojas en B1, y el que prepara una reunión
     * de un nivel o un tutor de un grado no necesita las demás.
     *
     * Documento de TRABAJO: sin sello del Director EBR (a diferencia del A4 del
     * tablero). Como el tablero, niega el bimestre fuera de alcance en vez de
     * caer a otro: un papel no puede decir un bimestre y mostrar otro.
     */
    public function riesgoImprimir(): void
    {
        $periodos = $this->periodosVisibles();

        [$periodo, $fueraDeAlcance] = $this->elegirPeriodo(
            $periodos,
            (int) ($this->query('periodo_id') ?? 0)
        );

        if (!$periodo || $fueraDeAlcance) {
            $this->notFound();
        }

        View::setLayout('print');
        $this->view('admin/cuadros/riesgo/imprimir', [
            'titulo'  => 'Estudiantes en riesgo académico',
            'periodo' => $periodo,
            'riesgo'  => $this->componerRiesgo($periodo),
        ]);
    }

    /**
     * GET /admin/cuadros/riesgo/tutores  (acepta ?periodo_id, ?secciones[])
     *
     * LOTE para repartir a los tutores (23/09/2026): un bloque por sección,
     * cada uno en hoja nueva y con su tutor ACTUAL en el encabezado, para que
     * cada tutor se lleve solo sus hojas. Sin selección van las 23 secciones.
     * Mismo recorte que la pantalla (`componerRiesgo()`), y el bloque es el
     * mismo que ve el tutor en su panel (`riesgo_por_seccion()`).
     *
     * Documento de TRABAJO, sin sello; niega el bimestre fuera de alcance como
     * `riesgoImprimir()`.
     */
    public function riesgoTutores(): void
    {
        $periodos = $this->periodosVisibles();

        [$periodo, $fueraDeAlcance] = $this->elegirPeriodo(
            $periodos,
            (int) ($this->query('periodo_id') ?? 0)
        );

        if (!$periodo || $fueraDeAlcance) {
            $this->notFound();
        }

        $riesgo = $this->componerRiesgo($periodo);

        View::setLayout('print');
        $this->view('admin/cuadros/riesgo/tutores', [
            'titulo'  => 'Estudiantes en riesgo por tutor',
            'periodo' => $periodo,
            'riesgo'  => $riesgo,
            'bloques' => riesgo_por_seccion(
                $riesgo['por_grado'],
                $riesgo['elegidas'] ?: $riesgo['secciones']
            ),
        ]);
    }

    /**
     * Datos del informe de riesgo. PUNTO ÚNICO de la pantalla y su A4, por el
     * mismo motivo que `componerBloques()`: si cada uno armara los suyos, el
     * papel podría decir otra cifra que la pantalla.
     *
     * Compone, no calcula: la lista sale de `SituacionFinalModel::porGrado()`
     * (la regla del MINEDU incluida) y el filtro y las estadísticas de
     * funciones puras de `helpers.php`. Aquí no hay ni un SELECT.
     *
     * 🔴 YA NO HAY LENTE «PRIMARIA SOLO C» (23/09/2026). Existió mientras el
     * riesgo fue un conteo de competencias no aprobatorias y servía para mirar
     * primaria con el listón de secundaria. La regla es ahora la situación
     * final del MINEDU, que no se cuenta por literales sueltos sino por áreas y
     * por grado: no hay nada que recortar, y ofrecer media regla sería ofrecer
     * una cifra que no significa nada. Los filtros por SECCIÓN se conservan.
     */
    private function componerRiesgo(array $periodo): array
    {
        $porGrado  = $this->situacionModel->porGrado((int) $periodo['id']);
        $secciones = $this->seccionModel->seccionesDelAnio((int) $periodo['anio_id']);

        // ?secciones[] llega de la URL y se VALIDA contra las secciones del año
        // del bimestre. `query()` entrega `$_GET` en crudo: un escalar o un
        // arreglo anidado se descartan (ojo: `(int)` de un arreglo vale 1, y
        // seleccionaría en silencio la sección id 1). Un id que no existe se
        // descarta en vez de dejar la pantalla vacía sin explicación.
        $porId = array_column($secciones, null, 'id');
        $crudo = $this->query('secciones');
        $ids   = [];
        foreach (is_array($crudo) ? $crudo : [] as $v) {
            if (is_string($v) && ctype_digit($v) && isset($porId[(int) $v])) {
                $ids[(int) $v] = (int) $v;
            }
        }
        $ids     = array_values($ids);
        $elegida = array_map(static fn(int $id): array => $porId[$id], $ids);

        $base     = $porGrado;
        $filtrado = riesgo_filtrar_secciones($base, $elegida);

        // Secciones agrupadas nivel → grado para el formulario, con los casos
        // de cada una en el modo actual (el contador de su casilla).
        $casos = [];
        foreach ($base as $g) {
            foreach ($g['en_riesgo'] as $al) {
                $k = (int) $g['grado']['id'] . '|' . $al['seccion_nombre'];
                $casos[$k] = ($casos[$k] ?? 0) + 1;
            }
        }
        $niveles = [];
        foreach ($secciones as $s) {
            $nid = $s['nivel_id'];
            $gid = $s['grado_id'];
            $niveles[$nid] ??= ['nombre' => $s['nivel_nombre'], 'codigo' => $s['nivel_codigo'], 'grados' => []];
            $niveles[$nid]['grados'][$gid] ??= ['nombre' => $s['grado_nombre'], 'secciones' => []];
            $niveles[$nid]['grados'][$gid]['secciones'][] = $s + [
                'casos' => $casos[$gid . '|' . $s['nombre']] ?? 0,
            ];
        }

        return [
            'por_grado'     => $base,
            'filtrado'      => $filtrado,
            'stats'         => riesgo_estadisticas($filtrado),
            'niveles'       => $niveles,
            'secciones'     => $secciones,
            'secciones_ids' => $ids,
            'elegidas'      => $elegida,
        ];
    }

    /**
     * Los bimestres que esta audiencia puede ver en este tablero.
     *
     * 🔴 DIRECCIÓN SOLO VE BIMESTRES CERRADOS (08/09/2026). Un bimestre a medio
     * llenar da porcentajes, rankings y una linea de tendencia con la misma
     * pinta que los del bimestre cerrado. `admin` y `registro_academico`
     * conservan el activo a proposito: son quienes vigilan el avance.
     *
     * El corte va en PHP sobre lo que YA devuelve el modelo. `getPeriodos()` es
     * compartido con `/admin/control` y no se toca, y esta clase no escribe
     * consultas (regla de oro del docblock, con verificador que la vigila).
     */
    private function periodosVisibles(): array
    {
        $periodos = $this->controlModel->getPeriodos();

        return solo_bimestres_cerrados() ? periodos_cerrados($periodos) : $periodos;
    }

    /**
     * Resuelve el bimestre a mostrar dentro de los visibles.
     *
     * @return array{0: array|null, 1: bool} el periodo, y si se pidio uno que
     *         esta FUERA de los visibles (la pantalla lo avisa, el papel lo niega).
     */
    private function elegirPeriodo(array $periodos, int $periodoId): array
    {
        // Sin restriccion, exactamente el camino de siempre: el `?periodo_id`
        // se resuelve contra la tabla aunque no este en la lista del selector.
        if (!solo_bimestres_cerrados()) {
            return [
                $periodoId > 0
                    ? $this->controlModel->getPeriodo($periodoId)
                    : $this->controlModel->getPeriodoPorDefecto(),
                false,
            ];
        }

        // Con restriccion, el `?periodo_id` tiene que estar en la lista: la
        // fila ya viene ahi con los mismos campos, asi que no hace falta
        // volver a pedirla —y de paso queda VALIDADA, que es lo que faltaba—.
        foreach ($periodos as $p) {
            if ((int) $p['id'] === $periodoId) {
                return [$p, false];
            }
        }

        // ⚠️ NO vale `getPeriodoPorDefecto()`: devuelve el activo y, sin el, el
        // PRIMERO de la lista, que con `anio DESC, numero ASC` es el bimestre 1.
        return [ultimo_periodo_cerrado($periodos), $periodoId > 0];
    }

    /**
     * Ids admitidos en las tres series ANUALES del tablero (G2, G7 y G11).
     *
     * `null` = sin filtro, y es lo que reciben `admin` y `registro_academico`:
     * su tablero queda byte a byte como estaba. Las series las arman los
     * modelos del año entero —que no se tocan, porque `verif_direccion_superficies`
     * exige que traigan la celda del bimestre ACTIVO—, asi que el recorte vive
     * en la vista y necesita saber por que ids cortar.
     */
    private function serieIds(array $periodos): ?array
    {
        if (!solo_bimestres_cerrados()) {
            return null;
        }

        return array_map(static fn(array $p): int => (int) $p['id'], $periodos);
    }

    /**
     * Reune los bloques del bimestre pidiendoselos a su dueño. Punto UNICO:
     * lo comparten la pantalla y su imprimible, para que no puedan mostrar
     * cifras distintas del mismo bimestre.
     */
    private function componerBloques(array $periodo): array
    {
        $periodoId = (int) $periodo['id'];
        $anioId    = (int) $periodo['anio_id'];

        // ── Los cinco bloques, cada uno desde su dueño ───────────────
        $resumenMatricula = $this->matriculaModel->getResumen($anioId);
        $conducta         = $this->conductaModel->getResumenSeccionesPorPeriodo($periodoId);
        $asistencia       = $this->asistenciaModel->getProgresoPorSeccion($periodoId);

        return [
            'matricula'      => $resumenMatricula,
            'calificaciones' => $this->anioModel->getResumenBimestre($periodoId),
            // La evolucion se ancla al año del BIMESTRE ELEGIDO, no al año
            // activo: al mirar un bimestre de un año pasado la serie tiene
            // que ser la de aquel año.
            'evolucion'      => $this->anioModel->getEvolucionAnual($anioId),
            'merito'         => $this->anioModel->getStatsCierre($periodoId),
            // El RIESGO ACADÉMICO es un bloque propio desde el 23/09/2026: ya
            // no es un subproducto del ranking del mérito sino la situación
            // final que proyecta `SituacionFinalModel`, con su propio roster.
            // La banda lo lee de aquí; el informe completo lo recompone en
            // `componerRiesgo()` porque además filtra por sección.
            'situacion'      => $this->situacionModel->porGrado($periodoId),
            'empates'        => $this->meritoModel->gradosConEmpatesPendientes($periodoId),
            'reaperturas'    => $this->anioModel->getReaperturas($periodoId),
            'conducta'       => $this->resumirConducta($conducta),
            // El detalle por seccion ya vino en la consulta de arriba; se pasa
            // crudo para graficarlo, sin volver a pedirlo.
            'conducta_secciones' => $conducta,
            'asistencia'     => $this->resumirAsistencia($asistencia),

            // ── Resultado de conducta y asistencia (27/08/2026) ──────
            // Hasta hoy este bloque solo medía el AVANCE DEL PROCESO (cuántas
            // secciones cerraron, qué porcentaje del roster se llenó) y no
            // decía nada del RESULTADO. Cada clave la calcula el modelo dueño:
            // aquí no hay ni un SELECT.
            //
            // Las dos series anuales se anclan al año del BIMESTRE ELEGIDO, no
            // al año activo, por el mismo motivo que `evolucion`: al mirar un
            // bimestre de un año pasado la comparación tiene que ser la de
            // aquel año.
            'conducta_literales'   => $this->conductaModel->getDistribucionLiteralesAnual($anioId),
            'conducta_criterios'   => $this->conductaModel->getIncumplimientoCriterios($periodoId),
            'asistencia_secciones' => $this->asistenciaModel->getIncidenciasPorSeccion($periodoId),
            'asistencia_top'       => $this->asistenciaModel->getTopIncidenciasPorSeccion($periodoId),
            'asistencia_evolucion' => $this->asistenciaModel->getEvolucionIncidenciasAnual($anioId),
        ];
    }

    /**
     * Cuenta las secciones por etapa del ciclo de conducta. Las etapas y sus
     * nombres son los de `/director/bloqueos`: RA bloquea (etapa 1), el tutor
     * cierra (etapa 2).
     */
    private function resumirConducta(array $filas): array
    {
        $out = [
            'secciones' => count($filas),
            'cerradas'  => 0,
            'pend_tutor'    => 0,
            'pend_auxiliar' => 0,
            'esperados'     => 0,
            'calificados'   => 0,
        ];

        foreach ($filas as $f) {
            $out['esperados']   += (int) $f['esperados'];
            $out['calificados'] += (int) $f['calificados'];

            if (!empty($f['tutor_cerrado_en'])) {
                $out['cerradas']++;
            } elseif (!empty($f['ra_bloqueado_en'])) {
                $out['pend_tutor']++;
            } else {
                $out['pend_auxiliar']++;
            }
        }

        return $out;
    }

    /** Cobertura del registro de asistencia sobre el roster del bimestre. */
    private function resumirAsistencia(array $progreso): array
    {
        $out = ['secciones' => count($progreso), 'completas' => 0, 'esperados' => 0, 'registrados' => 0];

        foreach ($progreso as $p) {
            $esp = (int) $p['esperados'];
            $reg = (int) $p['registrados'];
            $out['esperados']   += $esp;
            $out['registrados'] += $reg;
            if ($esp > 0 && $reg >= $esp) {
                $out['completas']++;
            }
        }

        return $out;
    }
}
