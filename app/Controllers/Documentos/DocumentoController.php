<?php

namespace App\Controllers\Documentos;

use App\Controllers\BaseController;
use App\Models\AsistenciaModel;
use App\Models\AuxiliarSeccionModel;
use App\Models\NominaDocenteModel;
use App\Models\PlanillaAsistenciaModel;
use Core\Session;
use Core\View;

/**
 * Documentos de consulta del día a día que comparten varios roles (28/09/2026):
 * la nómina de docentes (F4b) y la planilla de asistencia manual (F4c).
 * Ver docs/modulos/auxiliares.md.
 *
 * SOLO LEE. El acceso se guarda POR MÉTODO (cada documento decide sus roles):
 * admin, RA y Dirección ven todo el colegio. El auxiliar entra SOLO a la
 * nómina de docentes, acotada a SUS secciones del bimestre en curso
 * (`AuxiliarSeccionModel::seccionesDe`); la planilla NO es para él.
 */
class DocumentoController extends BaseController
{
    /** Quien ve el documento de TODO el colegio (decisión D9). */
    private const ROLES_COLEGIO = ['admin', 'registro_academico', ...ROLES_DIRECCION];

    // GET /documentos/nomina-docentes[?nivel=ID]
    // Decisiones D8/D9 y las del 28/09/2026: por nivel con filtro; el DNI solo
    // para admin, RA y Dirección; el auxiliar ve a los docentes de sus
    // secciones, con todas sus cargas.
    public function nominaDocentes(): void
    {
        $this->requireRole([...self::ROLES_COLEGIO, ROL_AUXILIAR]);

        $esAuxiliar = Session::hasRole(ROL_AUXILIAR);
        $seccionIds = null;
        if ($esAuxiliar) {
            $seccionIds = $this->seccionesDelAuxiliar();
            if ($seccionIds === []) {
                $this->forbidden();
            }
        }

        $niveles = (new NominaDocenteModel())->documento($seccionIds);

        // El filtro solo acepta niveles que el documento trae: nunca ids a mano.
        $nivelId = (int) $this->query('nivel', 0);
        $visibles = isset($niveles[$nivelId]) ? [$nivelId => $niveles[$nivelId]] : $niveles;

        $anio = (new AuxiliarSeccionModel())->periodoActivo();

        View::setLayout('print');
        $this->view('documentos/nomina-docentes', [
            'titulo'    => 'Nómina de docentes',
            'bodyClass' => 'doc-landscape',
            'niveles'   => $visibles,
            'filtro'    => array_map(static fn($n) => $n['nombre'], $niveles),
            'nivelId'   => isset($niveles[$nivelId]) ? $nivelId : 0,
            'conDni'    => !$esAuxiliar,
            'anio'      => $anio['anio'] ?? null,
        ]);
    }

    // GET /documentos/planilla-asistencia
    // Página de selección: sección, modo (en blanco / con fechas), mes y formato.
    // SOLO admin, RA y Dirección (28/09/2026, modifica D6/D9): el auxiliar no
    // tiene la planilla, para que registre en el sistema y deje el papel.
    public function planillaAsistencia(): void
    {
        $this->requireRole(self::ROLES_COLEGIO);

        $periodo    = (new AuxiliarSeccionModel())->periodoActivo();
        $asistencia = new AsistenciaModel();

        $this->view('documentos/planilla-asistencia', [
            'titulo'      => 'Planilla de asistencia',
            'periodo'     => $periodo,
            'secciones'   => $periodo ? $asistencia->listarSeccionesActivas() : [],
            // Estudiantes por sección con el MISMO roster que la planilla, para
            // avisar antes de pedir un Excel que no admite tantas filas.
            'estudiantes' => $periodo ? array_map(
                static fn($p) => $p['esperados'], $asistencia->getProgresoPorSeccion((int) $periodo['id'])
            ) : [],
            'meses'       => $periodo ? PlanillaAsistenciaModel::mesesDelPeriodo($periodo) : [],
            'filasExcel'  => PlanillaAsistenciaModel::FILAS_EXCEL,
            'page_scripts' => ['planilla-asistencia'],
        ]);
    }

    // GET /documentos/planilla-asistencia/generar?seccion=ID&modo=blanco|mes&mes=AAAA-MM&formato=pdf|xlsx
    public function planillaGenerar(): void
    {
        $this->requireRole(self::ROLES_COLEGIO);
        $volver = url('documentos/planilla-asistencia');

        $aux     = new AuxiliarSeccionModel();
        $periodo = $aux->periodoActivo();
        if ($periodo === null) {
            $this->redirectWithError($volver, 'No hay un bimestre en curso.');
        }

        $planilla = new PlanillaAsistenciaModel();
        $seccion  = $planilla->seccion((int) $this->query('seccion', 0));
        if ($seccion === null) {
            $this->redirectWithError($volver, 'Elige una sección.');
        }

        $modo    = (string) $this->query('modo', 'blanco');
        $formato = (string) $this->query('formato', 'pdf');
        if (!in_array($modo, ['blanco', 'mes'], true) || !in_array($formato, ['pdf', 'xlsx'], true)) {
            $this->redirectWithError($volver, 'Opciones no válidas.');
        }
        $mes = null;
        if ($modo === 'mes') {
            $mes = PlanillaAsistenciaModel::mesesDelPeriodo($periodo)[(string) $this->query('mes', '')] ?? null;
            if ($mes === null) {
                $this->redirectWithError($volver, 'Elige un mes del ' . $periodo['nombre_display'] . '.');
            }
        }

        $pid      = (int) $periodo['id'];
        $auxiliar = $aux->vigente((int) $seccion['id'], $pid);
        $alumnos  = array_column(
            (new AsistenciaModel())->getEstudiantesConIncidencias((int) $seccion['id'], $pid),
            'nombre_completo'
        );
        $nombre = PlanillaAsistenciaModel::nombreArchivo($seccion);

        if ($formato === 'xlsx') {
            // La plantilla trae 30 filas: no se trunca a nadie, se avisa.
            if (count($alumnos) > PlanillaAsistenciaModel::FILAS_EXCEL) {
                $this->redirectWithError($volver, 'La sección ' . $seccion['grado_nombre'] . ' '
                    . $seccion['seccion_nombre'] . ' tiene ' . count($alumnos) . ' estudiantes y el Excel admite '
                    . PlanillaAsistenciaModel::FILAS_EXCEL . '. Descarga el PDF, que no tiene límite.');
            }
            $ruta = $planilla->generarExcel($seccion, $periodo, $auxiliar, $alumnos, $mes);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
            header('Content-Length: ' . filesize($ruta));
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: no-cache');
            readfile($ruta);
            @unlink($ruta);
            exit;
        }

        // PDF: vista print A4 horizontal con el mismo formato que el Excel. El
        // <title> es el nombre del archivo: «Guardar como PDF» lo propone.
        $dias = [];
        if ($mes !== null) {
            foreach (PlanillaAsistenciaModel::diasPlanilla($mes['anio'], $mes['mes']) as $d) {
                $dias[$d['indice']] = $d;
            }
        }
        // Mismas filas que el Excel (punto único); el alto, con el alto útil del
        // PDF, en décimas de mm: la clase `planilla-print--alto-N` lo traduce.
        $filas = PlanillaAsistenciaModel::filasGrilla(count($alumnos));
        View::setLayout('print');
        $this->view('documentos/planilla-asistencia-imprimir', [
            'titulo'     => $nombre,
            'bodyClass'  => 'doc-landscape',
            'seccion'    => $seccion,
            'periodo'    => $periodo,
            'auxiliar'   => $auxiliar['nombre'] ?? '',
            'alumnos'    => $alumnos,
            'mes'        => $mes,
            'dias'       => $dias,
            'filas'      => $filas,
            'altoDecimas' => (int) floor(10 * PlanillaAsistenciaModel::altoFilaMm(
                $filas, PlanillaAsistenciaModel::ALTO_UTIL_PDF_MM)),
        ]);
    }

    /**
     * Secciones a cargo del auxiliar en el bimestre EN CURSO (documentos del día
     * a día: sin historial). Vacío si no hay bimestre activo o no tiene ninguna.
     *
     * @return int[]
     */
    private function seccionesDelAuxiliar(): array
    {
        $aux     = new AuxiliarSeccionModel();
        $periodo = $aux->periodoActivo();
        if ($periodo === null) {
            return [];
        }
        return $aux->seccionesDe((int) Session::user()['id'], (int) $periodo['id']);
    }
}
