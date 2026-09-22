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

    public function __construct()
    {
        $this->requireRole(['admin', 'registro_academico', ...ROLES_DIRECCION]);

        $this->anioModel       = new AnioAcademicoModel();
        $this->matriculaModel  = new MatriculaModel();
        $this->conductaModel   = new ConductaModel();
        $this->asistenciaModel = new AsistenciaModel();
        $this->meritoModel     = new OrdenMeritoModel();
        $this->controlModel    = new ControlOperativoModel();
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
