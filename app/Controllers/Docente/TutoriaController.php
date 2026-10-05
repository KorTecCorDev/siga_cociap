<?php

namespace App\Controllers\Docente;

use App\Controllers\BaseController;
use App\Models\CalificacionModel;
use App\Models\ConclusionReplicaModel;
use App\Models\TransversalModel;
use Core\Session;

/**
 * TutoriaController
 * Vista del tutor de sección: promedios agregados de TIC/GAMA,
 * conclusiones descriptivas y cierre transversal del bimestre.
 *
 * El cierre solo procede cuando todas las cargas de la sección han aprobado
 * sus competencias TRANSVERSALES y no falta ninguna conclusión obligatoria
 * (B/C primaria, C secundaria). ⚠️ Desde el 06/08/2026 las competencias
 * ACADÉMICAS ya no condicionan el cierre: no participan del promedio que se
 * congela (`getPromediosSeccion` filtra `tipo='transversal'`), así que exigirlas
 * hacía esperar al tutor por notas que no cambiaban su resultado.
 *
 * La tabla de promedios se muestra SIEMPRE, pero escribir conclusiones exige el
 * promedio definitivo: el guard vive en `guardarConclusion`, no solo en la vista.
 */
class TutoriaController extends BaseController
{
    private TransversalModel  $transModel;
    private CalificacionModel $calModel;
    private ConclusionReplicaModel $replicaModel;

    public function __construct()
    {
        $this->requireRole(['docente', 'admin']);
        $this->transModel = new TransversalModel();
        $this->calModel   = new CalificacionModel();
        $this->replicaModel = new ConclusionReplicaModel();
    }

    /**
     * GET /docente/tutoria[/{periodo_id}]
     * Panel de tutoría con selector de bimestre.
     */
    public function index(string $periodoId = ''): void
    {
        $user    = Session::user();
        $seccion = $this->transModel->getSeccionDelTutor((int) $user['id']);

        if (!$seccion) {
            $this->redirectWithError(
                url('docente/mis-cargas'),
                'No eres tutor(a) de ninguna sección este año.'
            );
        }

        // Solo bimestres cerrados y el activo; nunca los pendientes.
        $periodos = $this->calModel->query("
            SELECT p.id, p.numero, p.nombre_display, p.estado
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
            WHERE p.estado IN ('cerrado', 'activo')
            ORDER BY p.numero
        ");

        $periodoSel = null;
        if ($periodoId !== '') {
            foreach ($periodos as $p) {
                if ((int) $p['id'] === (int) $periodoId) {
                    $periodoSel = $p;
                    break;
                }
            }
        } else {
            foreach ($periodos as $p) {
                if ($p['estado'] === 'activo') {
                    $periodoSel = $p;
                    break;
                }
            }
            $periodoSel = $periodoSel ?? ($periodos[0] ?? null);
        }

        if (!$periodoSel) {
            $this->redirectWithError(url('docente/mis-cargas'), 'Bimestre no encontrado.');
        }

        $pid = (int) $periodoSel['id'];
        $sid = (int) $seccion['id'];

        $estadoCargas = $this->transModel->estadoCargasSeccion($sid, $pid);
        $cierre       = $this->transModel->getCierreVigente($sid, $pid);
        $listo        = $estadoCargas['total'] > 0
                     && $estadoCargas['bloqueadas'] >= $estadoCargas['total'];

        $competencias = $this->transModel->getCompetencias((int) $seccion['nivel_id']);
        $alumnos      = $this->getAlumnosSeccion($sid);
        $promedios    = $this->transModel->getPromediosSeccion($sid, $pid);
        $conclusiones = $this->transModel->getConclusionesSeccion($sid, $pid);

        // Réplicas del acta SIAGIE (02/10/2026): con GAMA en C en 5.º, una
        // conclusión por área destino. Vacío en los demás grados.
        $replicas      = $this->replicaModel->requeridas($sid, (string) $seccion['nivel_codigo'], $promedios);
        $textosReplica = $replicas !== [] ? $this->replicaModel->getSeccion($sid, $pid) : [];

        $this->view('docente/tutoria', [
            'titulo'       => 'Tutoría — Sección ' . $seccion['nombre'],
            'seccion'      => $seccion,
            'periodos'     => $periodos,
            'periodoSel'   => $periodoSel,
            'estadoCargas' => $estadoCargas,
            'cierre'       => $cierre,
            'listo'        => $listo,
            'competencias' => $competencias,
            'alumnos'      => $alumnos,
            'promedios'    => $promedios,
            'conclusiones' => $conclusiones,
            'replicas'     => $replicas,
            'textosReplica' => $textosReplica,
            'page_scripts' => ['tutoria'],
        ]);
    }

    /**
     * POST /docente/tutoria/{periodo_id}/conclusion
     * Guarda la conclusión transversal de UN alumno (AJAX).
     */
    public function guardarConclusion(string $periodoId): void
    {
        $this->validateCsrf();

        $user    = Session::user();
        $seccion = $this->transModel->getSeccionDelTutor((int) $user['id']);
        if (!$seccion) {
            $this->json(['success' => false, 'mensaje' => 'No eres tutor(a) de una sección.'], 403);
        }

        $periodoId     = (int) $periodoId;
        $matriculaId   = (int) $this->input('matricula_id');
        $competenciaId = (int) $this->input('competencia_id');
        $conclusion    = trim($this->input('conclusion', ''));

        if (!$matriculaId || !$competenciaId) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }

        // La matrícula debe pertenecer a la sección del tutor.
        $pertenece = $this->calModel->queryOne("
            SELECT id FROM matriculas WHERE id = ? AND seccion_id = ?
        ", [$matriculaId, (int) $seccion['id']]);
        if (!$pertenece) {
            $this->json(['success' => false, 'mensaje' => 'El alumno no pertenece a tu sección.'], 403);
        }

        // Con cierre vigente las conclusiones quedan congeladas.
        if ($this->transModel->getCierreVigente((int) $seccion['id'], $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'El bimestre transversal ya está cerrado.'], 403);
        }

        // El tutor SOLO ESCRIBE con el promedio DEFINITIVO (decisión 06/08/2026).
        // Desde esa fecha la tabla de promedios se muestra también en estado
        // parcial, pero en solo lectura: una conclusión redactada sobre un
        // promedio incompleto puede terminar describiendo una nota que después
        // cambia (de B a A), y ese error lo paga la familia en la boleta.
        // ⚠️ El guard va AQUÍ y no solo en la vista: ocultar el textarea es
        // cosmético mientras el endpoint siga aceptando el POST.
        $estado = $this->transModel->estadoCargasSeccion((int) $seccion['id'], $periodoId);
        if ($estado['total'] === 0 || $estado['bloqueadas'] < $estado['total']) {
            $faltan = $estado['total'] - $estado['bloqueadas'];
            $this->json([
                'success' => false,
                'mensaje' => 'Aún no puedes registrar conclusiones: faltan ' . $faltan
                    . ' competencia(s) transversal(es) por aprobar en la sección. '
                    . 'El promedio todavía puede cambiar.',
            ], 403);
        }

        $ok = $this->transModel->guardarConclusion(
            $matriculaId, $competenciaId, $periodoId, $conclusion, (int) $user['id']
        );

        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Conclusión guardada.' : 'Error al guardar.',
        ]);
    }

    /**
     * POST /docente/tutoria/{periodo_id}/conclusion-replica
     * Guarda la conclusión de RÉPLICA de UN alumno para UN área destino del
     * acta SIAGIE (AJAX). Mismas guardas que `guardarConclusion` más una: el
     * área debe ser destino de réplica de la sección.
     */
    public function guardarConclusionReplica(string $periodoId): void
    {
        $this->validateCsrf();

        $user    = Session::user();
        $seccion = $this->transModel->getSeccionDelTutor((int) $user['id']);
        if (!$seccion) {
            $this->json(['success' => false, 'mensaje' => 'No eres tutor(a) de una sección.'], 403);
        }

        $periodoId   = (int) $periodoId;
        $sid         = (int) $seccion['id'];
        $matriculaId = (int) $this->input('matricula_id');
        $areaId      = (int) $this->input('area_id');
        $conclusion  = trim($this->input('conclusion', ''));

        if (!$matriculaId || !$areaId) {
            $this->json(['success' => false, 'mensaje' => 'Datos incompletos.'], 400);
        }
        if (mb_strlen($conclusion) > 500) {
            $this->json(['success' => false, 'mensaje' => 'La conclusión no puede pasar de 500 caracteres.'], 400);
        }

        $pertenece = $this->calModel->queryOne("
            SELECT id FROM matriculas WHERE id = ? AND seccion_id = ?
        ", [$matriculaId, $sid]);
        if (!$pertenece) {
            $this->json(['success' => false, 'mensaje' => 'El alumno no pertenece a tu sección.'], 403);
        }

        if (!in_array($areaId, array_column($this->replicaModel->destinosDeSeccion($sid), 'area_id'), true)) {
            $this->json(['success' => false, 'mensaje' => 'Esa área no recibe réplica en tu sección.'], 400);
        }

        if ($this->transModel->getCierreVigente($sid, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'El bimestre transversal ya está cerrado.'], 403);
        }

        // Promedio DEFINITIVO, como en `guardarConclusion`: la réplica describe
        // la misma nota y no se escribe sobre un promedio que aún puede cambiar.
        $estado = $this->transModel->estadoCargasSeccion($sid, $periodoId);
        if ($estado['total'] === 0 || $estado['bloqueadas'] < $estado['total']) {
            $this->json([
                'success' => false,
                'mensaje' => 'Aún no puedes registrar conclusiones: el promedio todavía puede cambiar.',
            ], 403);
        }

        $ok = $this->replicaModel->guardar(
            $matriculaId, $areaId, $periodoId, $conclusion, (int) $user['id']
        );

        $this->json([
            'success' => $ok,
            'mensaje' => $ok ? 'Conclusión guardada.' : 'Error al guardar.',
        ]);
    }

    /**
     * POST /docente/tutoria/{periodo_id}/cerrar
     * Registra el cierre transversal de la sección. Valida en servidor:
     * todas las cargas bloqueadas + ninguna conclusión obligatoria pendiente.
     */
    public function cerrar(string $periodoId): void
    {
        $this->validateCsrf();

        $user    = Session::user();
        $seccion = $this->transModel->getSeccionDelTutor((int) $user['id']);
        if (!$seccion) {
            $this->json(['success' => false, 'mensaje' => 'No eres tutor(a) de una sección.'], 403);
        }

        $periodoId = (int) $periodoId;
        $sid       = (int) $seccion['id'];

        if ($this->transModel->getCierreVigente($sid, $periodoId)) {
            $this->json(['success' => false, 'mensaje' => 'Este bimestre ya está cerrado.'], 400);
        }

        $estado = $this->transModel->estadoCargasSeccion($sid, $periodoId);
        if ($estado['total'] === 0 || $estado['bloqueadas'] < $estado['total']) {
            $this->json([
                'success' => false,
                'mensaje' => 'Aún hay cargas sin aprobar sus transversales: '
                    . $estado['bloqueadas'] . ' de ' . $estado['total']
                    . ' competencias transversales aprobadas en la sección.',
            ], 400);
        }

        $pendientes = $this->transModel->conclusionesObligatoriasPendientes(
            $sid, $periodoId, $seccion['nivel_codigo']
        );
        if ($pendientes > 0) {
            $this->json([
                'success' => false,
                'mensaje' => 'Falta(n) ' . $pendientes . ' conclusión(es) obligatoria(s) '
                    . 'antes de cerrar el bimestre transversal.',
            ], 400);
        }

        $ok = $this->transModel->cerrar($sid, $periodoId, (int) $user['id']);

        $this->json([
            'success' => $ok,
            'mensaje' => $ok
                ? 'Bimestre transversal cerrado. TIC/GAMA ya aparecen en las boletas.'
                : 'No se pudo registrar el cierre.',
        ]);
    }

    // ── Privados ─────────────────────────────────────────────────

    private function getAlumnosSeccion(int $seccionId): array
    {
        return $this->calModel->query("
            SELECT
                m.id AS matricula_id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                ) AS nombre_completo
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            WHERE m.seccion_id = ?
              AND m.tipo      NOT IN ('trasladado', 'retirado')
            ORDER BY " . orden_alfabetico('p') . "
        ", [$seccionId]);
    }
}
