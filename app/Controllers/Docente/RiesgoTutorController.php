<?php

namespace App\Controllers\Docente;

use App\Controllers\BaseController;
use App\Models\ControlOperativoModel;
use App\Models\SituacionFinalModel;
use App\Models\PublicacionBoletaModel;
use App\Models\SeccionModel;
use App\Models\TransversalModel;
use Core\Session;
use Core\View;

/**
 * RiesgoTutorController — estudiantes en riesgo de LA sección del tutor
 * (23/09/2026). Solo lectura.
 *
 * Es el mismo bloque que Dirección imprime en el lote por tutor
 * (`/admin/cuadros/riesgo/tutores`): `SituacionFinalModel::deSeccion()` →
 * `_seccion.php`. Aquí no se calcula nada.
 *
 * 🔴 DOS CANDADOS, los dos en SERVIDOR:
 *  1. La sección NUNCA sale de la URL: es la de `secciones.tutor_id` del
 *     usuario en sesión (`getSeccionDelTutor`). No hay parámetro con el que
 *     pedir otra.
 *  2. Solo bimestres PUBLICADOS del nivel de la sección (compuerta 044,
 *     `periodosPublicados`): el informe muestra puestos del orden de mérito, y
 *     el claustro ve el mérito solo cuando su nivel está publicado. Un bimestre
 *     cerrado sin publicar no se lista ni se acepta por `?periodo_id`.
 *
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU —requiere
 * recuperación o permanece en el grado—, la misma regla que ve Dirección. El
 * tutor que se nombra es el ACTUAL; ve todos los bimestres publicados de su
 * sección.
 */
class RiesgoTutorController extends BaseController
{
    private TransversalModel       $transModel;
    private SeccionModel           $seccionModel;
    private SituacionFinalModel    $situacionModel;
    private PublicacionBoletaModel $publicacionModel;
    private ControlOperativoModel  $controlModel;

    public function __construct()
    {
        $this->requireRole(['docente']);
        $this->transModel       = new TransversalModel();
        $this->seccionModel     = new SeccionModel();
        $this->situacionModel   = new SituacionFinalModel();
        $this->publicacionModel = new PublicacionBoletaModel();
        $this->controlModel     = new ControlOperativoModel();
    }

    /**
     * GET /docente/tutoria/riesgo  (acepta ?periodo_id, solo publicados)
     */
    public function index(): void
    {
        [$seccion, $periodos, $periodo, $noPublicado] = $this->contexto();

        $this->view('docente/riesgo/index', [
            'titulo'      => 'Estudiantes en riesgo — ' . $seccion['grado_nombre'] . ' ' . $seccion['nombre'],
            'seccion'     => $seccion,
            'periodos'    => $periodos,
            'periodo'     => $periodo,
            'bloque'      => $periodo ? $this->situacionModel->deSeccion((int) $periodo['id'], $seccion) : null,
            'noPublicado' => $noPublicado,
        ]);
    }

    /**
     * GET /docente/tutoria/riesgo/imprimir  (acepta ?periodo_id, solo publicados)
     *
     * Un papel no puede decir un bimestre y mostrar otro: si se pide uno que no
     * está publicado, se niega en vez de caer al último.
     */
    public function imprimir(): void
    {
        [$seccion, , $periodo, $noPublicado] = $this->contexto();

        if (!$periodo || $noPublicado) {
            $this->notFound();
        }

        View::setLayout('print');
        $this->view('docente/riesgo/imprimir', [
            'titulo'  => 'Estudiantes en riesgo — ' . $seccion['grado_nombre'] . ' ' . $seccion['nombre'],
            'seccion' => $seccion,
            'periodo' => $periodo,
            'bloque'  => $this->situacionModel->deSeccion((int) $periodo['id'], $seccion),
        ]);
    }

    /**
     * Sección del tutor en sesión, sus bimestres publicados y el elegido.
     *
     * @return array{0: array, 1: array, 2: ?array, 3: bool} la sección (fila de
     *         `seccionesDelAnio`), los bimestres publicados, el elegido (el
     *         pedido si está publicado; si no, el último publicado) y si se
     *         pidió uno que NO está publicado.
     */
    private function contexto(): array
    {
        $propia = $this->transModel->getSeccionDelTutor((int) Session::user()['id']);
        if (!$propia) {
            $this->redirectWithError(url('docente/inicio'), 'No eres tutor(a) de ninguna sección este año.');
        }

        $anioId  = (int) $propia['anio_id'];
        $seccion = array_column($this->seccionModel->seccionesDelAnio($anioId), null, 'id')[(int) $propia['id']];

        $publicados = $this->publicacionModel->periodosPublicados($anioId, (int) $propia['nivel_id']);
        $periodos   = array_values(array_filter(
            $this->controlModel->getPeriodos(),
            static fn(array $p): bool => (int) $p['anio_id'] === $anioId && isset($publicados[(int) $p['id']])
        ));

        $pedido  = (int) ($this->query('periodo_id') ?? 0);
        $periodo = null;
        foreach ($periodos as $p) {
            if ((int) $p['id'] === $pedido) {
                $periodo = $p;
            }
        }
        $noPublicado = $pedido > 0 && $periodo === null;

        // Por defecto, el último publicado (`getPeriodos` ordena por número ASC).
        $periodo ??= $periodos ? $periodos[count($periodos) - 1] : null;

        return [$seccion, $periodos, $periodo, $noPublicado];
    }
}
