<?php

namespace App\Controllers\Director;

use App\Controllers\BaseController;
use App\Models\AnioAcademicoModel;
use App\Models\SituacionFinalModel;
use Core\Session;

/**
 * AnioAcademicoController
 * Gestión de años académicos: listado, creación, activación y cierre.
 * Los bimestres de cada año se gestionan en PeriodoController.
 */
class AnioAcademicoController extends BaseController
{
    /**
     * Quien ESCRIBE. Los directores entran a este controlador y VEN, pero desde
     * el 24/08/2026 no operan: su rol es de supervision en solo lectura. Se
     * valida en cada metodo de escritura (no ocultando el boton en la vista):
     * esconder la UI no es control de acceso.
     */
    private const ROLES_ESCRIBEN = ['admin', 'registro_academico'];

    private AnioAcademicoModel $model;

    private const ROLES = ['admin', 'registro_academico', ...ROLES_DIRECCION];

    public function __construct()
    {
        $this->requireRole(self::ROLES);
        $this->model = new AnioAcademicoModel();
    }

    // GET /director/anios
    public function index(): void
    {
        $this->view('director/anios/index', [
            'titulo' => 'Año académico',
            'anios'  => $this->model->listarAnios(),
            // Los directores VEN esta pantalla pero no operan en ella: la vista
            // se pinta sin controles. El permiso real lo hace requireRole en
            // cada metodo de escritura; esto es UX, no control de acceso.
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
        ]);
    }

    // GET /director/anios/crear
    public function create(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->view('director/anios/crear', [
            'titulo'        => 'Nuevo año académico',
            'anioSugerido'  => (int) date('Y') + 1,
        ]);
    }

    // POST /director/anios/crear
    public function store(): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();

        $anio = (int) $this->input('anio', 0);

        if ($anio < 2000 || $anio > 2100) {
            $this->redirectWithError(url('director/anios/crear'), 'Ingresa un año válido (entre 2000 y 2100).');
        }

        if ($this->model->existeAnio($anio)) {
            $this->redirectWithError(url('director/anios/crear'), "El año académico {$anio} ya existe.");
        }

        try {
            $anioId = $this->model->crearAnio($anio);
        } catch (\Exception $e) {
            $this->redirectWithError(url('director/anios/crear'), 'No se pudo crear el año académico. Intenta de nuevo.');
        }

        $this->redirectWithSuccess(
            url('director/anios/' . $anioId),
            "Año académico {$anio} creado con sus 4 bimestres. Ajusta las fechas y actívalo cuando corresponda."
        );
    }

    // GET /director/anios/{id}
    public function show(string $id): void
    {
        $id   = (int) $id;
        $anio = $this->model->find($id);

        if (!$anio) {
            $this->redirectWithError(url('director/anios'), 'Año académico no encontrado.');
        }

        $periodos = $this->model->getPeriodos($id);

        // Si venimos de cerrar un bimestre, preparamos sus indicadores para el modal.
        $cerradoId  = (int) ($this->query('cerrado', 0));
        $statsCierre = null;
        $periodoCerrado = null;
        $reaperturasCierre = [];
        if ($cerradoId > 0) {
            foreach ($periodos as $p) {
                if ((int) $p['id'] === $cerradoId) {
                    $periodoCerrado = $p;
                    break;
                }
            }
            if ($periodoCerrado) {
                $statsCierre = $this->model->getStatsCierre($cerradoId);
                $reaperturasCierre = $this->model->getReaperturas($cerradoId);
            }
        }

        $this->view('director/anios/show', [
            // Vista de SOLO LECTURA para los directores: sin controles.
            'puedeEscribir' => has_role(self::ROLES_ESCRIBEN),
            'titulo'            => 'Año académico ' . $anio['anio'],
            'anio'              => $anio,
            'periodos'          => $periodos,
            'page_scripts'      => ['anio-academico'],
            'cerradoId'         => $cerradoId,
            'periodoCerrado'    => $periodoCerrado,
            'statsCierre'       => $statsCierre,
            'reaperturasCierre' => $reaperturasCierre,
            // Cierre del PERIODO FINAL detenido por competencias vacías: el
            // detalle y el botón de confirmación (PeriodoController::cerrar).
            'cierreVacias'      => Session::getFlash('cierre_vacias'),
        ]);
    }

    // POST /director/anios/{id}/activar
    public function activar(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id   = (int) $id;
        $anio = $this->model->find($id);

        if (!$anio) {
            $this->redirectWithError(url('director/anios'), 'Año académico no encontrado.');
        }
        if ($anio['estado'] === 'activo') {
            $this->redirectWithError(url('director/anios/' . $id), 'El año académico ya está activo.');
        }
        if ($anio['estado'] === 'cerrado') {
            $this->redirectWithError(url('director/anios/' . $id), 'No se puede activar un año académico cerrado.');
        }

        try {
            $this->model->activarAnio($id);
        } catch (\Exception $e) {
            $this->redirectWithError(url('director/anios/' . $id), 'No se pudo activar el año académico.');
        }

        $this->redirectWithSuccess(
            url('director/anios/' . $id),
            "Año académico {$anio['anio']} activado. Cualquier otro año activo fue cerrado."
        );
    }

    // POST /director/anios/{id}/cerrar
    public function cerrar(string $id): void
    {
        $this->requireRole(self::ROLES_ESCRIBEN);
        $this->validateCsrf();
        $id   = (int) $id;
        $anio = $this->model->find($id);

        if (!$anio) {
            $this->redirectWithError(url('director/anios'), 'Año académico no encontrado.');
        }
        if ($anio['estado'] === 'cerrado') {
            $this->redirectWithError(url('director/anios/' . $id), 'El año académico ya está cerrado.');
        }
        if ($this->model->tieneBimestreActivo($id)) {
            $this->redirectWithError(
                url('director/anios/' . $id),
                'No se puede cerrar el año: aún hay un bimestre activo. Ciérralo primero.'
            );
        }

        // SITUACIÓN FINAL DEFINITIVA (decisión del usuario, 01/10/2026): cerrar
        // el año es irreversible, así que exige que la situación final de TODOS
        // los estudiantes vigentes sea definitiva —ninguno en PEND ni sin datos
        // en el periodo final—. Punto único: `riesgo_resumen()['definitiva']`
        // sobre `SituacionFinalModel`. Lo pendiente se completa con la
        // calificación extraordinaria (Rectificaciones).
        $periodos = $this->model->getPeriodos($id);
        $final    = $periodos ? $periodos[array_key_last($periodos)] : null;
        if ($final !== null) {
            if ($final['estado'] !== 'cerrado') {
                $this->redirectWithError(
                    url('director/anios/' . $id),
                    "No se puede cerrar el año: el {$final['nombre_display']} aún no está cerrado."
                );
            }
            $resumen = riesgo_resumen((new SituacionFinalModel())->porGrado((int) $final['id']));
            if (!$resumen['definitiva']) {
                $this->redirectWithError(
                    url('director/anios/' . $id),
                    sprintf(
                        'No se puede cerrar el año: la situación final aún no es definitiva '
                        . '(%d estudiante(s) con la situación final pendiente y %d sin datos en el %s). '
                        . 'Complétalas con la calificación extraordinaria y revísalas en '
                        . 'Cuadros › Acompañamiento pedagógico.',
                        $resumen['pendiente_final'], $resumen['sin_datos'], $final['nombre_display']
                    )
                );
            }
        }

        $this->model->cerrarAnio($id);

        $this->redirectWithSuccess(
            url('director/anios/' . $id),
            "Año académico {$anio['anio']} cerrado."
        );
    }
}
