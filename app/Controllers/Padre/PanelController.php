<?php

namespace App\Controllers\Padre;

use App\Controllers\BaseController;
use App\Models\BoletaPublicaModel;
use App\Models\CalificacionModel;
use App\Models\ConductaModel;
use App\Models\OrdenMeritoModel;
use App\Models\PublicacionBoletaModel;
use Core\Session;

/**
 * PanelController
 * Panel del padre de familia.
 */
class PanelController extends BaseController
{
    private CalificacionModel      $calModel;
    private ConductaModel          $conductaModel;
    private BoletaPublicaModel     $bpModel;
    private PublicacionBoletaModel $publicacionModel;
    private OrdenMeritoModel       $ordenModel;

    public function __construct()
    {
        $this->requireRole(['padre', 'admin', 'registro_academico']);
        $this->calModel         = new CalificacionModel();
        $this->conductaModel    = new ConductaModel();
        $this->bpModel          = new BoletaPublicaModel();
        $this->publicacionModel = new PublicacionBoletaModel();
        $this->ordenModel       = new OrdenMeritoModel();
    }

    /**
     * GET /padre/inicio
     * Panel principal del padre.
     */
    public function index(): void
    {
        $user     = Session::user();
        $hijo     = $this->getHijo($user['id']);
        $periodo  = $this->getPeriodoActivo();
        $alertas  = $hijo ? $this->getAlertas($hijo['matricula_id']) : [];

        $this->view('padre/inicio', [
            'titulo'  => 'Panel del padre',
            'hijo'    => $hijo,
            'periodo' => $periodo,
            'alertas' => $alertas,
        ]);
    }

    /**
     * GET /padre/notas
     * Ver notas del hijo en el periodo activo.
     */
    public function notas(): void
    {
        $user = Session::user();
        $hijo = $this->getHijo($user['id']);

        if (!$hijo) {
            $this->redirectWithError(
                url('padre/inicio'),
                'No se encontró información del estudiante.'
            );
        }

        // F4 — El padre solo ve hasta el ULTIMO bimestre CERRADO (oficial). El
        // bimestre activo (registro/borrador) no se expone: el borrador del cierre
        // forzado bloquea todas las competencias y se filtraria como definitivo.
        // COMPUERTA DE PUBLICACION (044): ademas exige que el bimestre este
        // PUBLICADO al nivel del hijo — cerrar ya no basta. Por eso se resuelve
        // despues de conocer al hijo (la publicacion es por nivel).
        $periodo = $this->getPeriodoVigentePadre((int) $hijo['nivel_id']);

        if (!$periodo) {
            $this->redirectWithError(
                url('padre/inicio'),
                'Aún no hay notas publicadas. Las boletas se habilitan cuando el colegio las publica, en la fecha de entrega.'
            );
        }

        // Retorno de grado: durante la nivelación las notas del periodo viven en
        // la matrícula operativa; se leen por unión bajo la identidad oficial.
        $fuentes = $this->calModel->boletaContexto((int) $hijo['matricula_id'])['fuentes'];

        $notas = [];
        foreach ($fuentes as $mid) {
            $notas = array_merge(
                $notas,
                $this->calModel->getBoletaAlumno((int) $mid, (int) $periodo['id'])
            );
        }

        // Agrupar notas por área, UNA FILA POR COMPETENCIA. La indexación por
        // competencia_id no es cosmética: con un retorno de grado se leen dos
        // matrículas (operativa + oficial) y una competencia calificada en ambas
        // llegaba repetida, mostrando la misma nota dos veces. Es lo que hace la
        // boleta oficial en BoletaModel::buildAreasConBimestres.
        //
        // GANA LA PRIMERA FUENTE, que boletaContexto devuelve en orden
        // [operativa, oficial]: manda el grado que el alumno CURSA. Si la nota de
        // esa competencia solo existe en la oficial, esa se usa (no se pierde
        // ningún dato). Cuando ambas la tienen, el promedio es el mismo pero el
        // desglose por criterios puede colgar de cargas del grado oficial —de
        // otro docente y con la nota repetida para no alterar el promedio—, y ese
        // desglose no se le muestra a la familia.
        $areas = [];
        foreach ($notas as $nota) {
            $areaNombre = $nota['nombre_boleta'] ?? $nota['area_nombre'];
            if ($nota['alias_boleta']) {
                $areaNombre .= ' ' . $nota['alias_boleta'];
            }
            $compId = (int) $nota['competencia_id'];
            if (isset($areas[$areaNombre][$compId])) {
                continue;
            }

            // Solo criterios CON nota. getBoletaAlumno devuelve todos los criterios
            // definidos en la carga, tengan nota del alumno o no, y la vista pinta
            // los vacíos como '—'. En un retorno de grado la matrícula operativa
            // trae los criterios de la carga del grado oficial SIN ninguna nota, así
            // que sin este filtro la familia vería una tabla entera de guiones.
            // Tampoco el criterio extraordinario de RA (18/09/2026): no es un
            // criterio del docente; la nota sigue en la competencia.
            if (!empty($nota['criterios'])) {
                $nota['criterios'] = array_values(array_filter(
                    criterios_ordinarios($nota['criterios']),
                    static fn(array $c): bool => ($c['nota'] ?? null) !== null
                ));
            }

            $areas[$areaNombre][$compId] = $nota;
        }

        // Conducta del periodo: la que tenga la fuente con cierre vigente.
        $conducta = null;
        foreach ($fuentes as $mid) {
            $conducta = $this->conductaModel->getParaPeriodo((int) $mid, (int) $periodo['id']);
            if ($conducta !== null) {
                break;
            }
        }

        // QR/enlace permanente por token (identidad oficial): mismo enlace que el
        // padre escanea de la boleta impresa, estable todo el año.
        $tokenBoleta = $this->bpModel->getOCrearToken((int) $hijo['matricula_id']);

        $this->view('padre/notas', [
            'titulo'      => 'Notas de ' . $hijo['nombres'],
            'hijo'        => $hijo,
            'periodo'     => $periodo,
            'areas'       => $areas,
            'conducta'    => $conducta,
            'tokenBoleta' => $tokenBoleta,
        ]);
    }

    /**
     * GET /padre/orden-merito
     * Orden de mérito del GRADO del hijo (rediseño 2, fase 6). Compite todo el
     * grado; el 1.er puesto obtiene la media beca.
     */
    public function ordenMerito(): void
    {
        [$hijo, $periodo, $ctx] = $this->contextoMerito();

        $this->view('padre/orden-merito', [
            'titulo'        => 'Orden de mérito',
            'hijo'          => $hijo,
            'periodo'       => $periodo,
            // Integridad: se rotula con el grado/sección donde el alumno COMPITE
            // (la matrícula operativa si hay retorno), nunca con el oficial: los
            // datos de la tabla son de ese grado y no deben mezclarse rótulos.
            'gradoNombre'   => $ctx['grado_nombre'],
            'nivelNombre'   => $ctx['nivel_nombre'],
            'estudiantes'   => $ctx['ranking'],
            'matriculaHijo' => $ctx['matricula_id'],
        ]);
    }

    /**
     * GET /padre/ranking-seccion
     * Ranking interno de la SECCIÓN del hijo. NO otorga media beca: solo el
     * orden de mérito del grado la define.
     */
    public function rankingSeccion(): void
    {
        [$hijo, $periodo, $ctx] = $this->contextoMerito();

        // Solo la sección del hijo: el resto de secciones del grado no le compete.
        $porSeccion = $this->ordenModel->rankingPorSeccion((int) $ctx['grado_id'], (int) $periodo['id']);
        $seccion    = $ctx['seccion_nombre'];

        $this->view('padre/ranking-seccion', [
            'titulo'        => 'Ranking por sección',
            'hijo'          => $hijo,
            'periodo'       => $periodo,
            // Ver nota de integridad en ordenMerito().
            'gradoNombre'   => $ctx['grado_nombre'],
            'nivelNombre'   => $ctx['nivel_nombre'],
            'seccionNombre' => $seccion,
            'estudiantes'   => $porSeccion[$seccion] ?? [],
            'matriculaHijo' => $ctx['matricula_id'],
        ]);
    }

    /**
     * Contexto común de las dos superficies de mérito: hijo, periodo publicado y
     * ubicación del alumno en el ranking. Aborta con redirección si falta algo.
     *
     * La compuerta es la MISMA que la de las notas (getPeriodoVigentePadre): el
     * mérito se libera junto con las boletas, por nivel (rediseño 2). Nunca se
     * expone el ranking de un bimestre no publicado.
     *
     * Retorno de grado: el alumno compite con su matrícula OPERATIVA (grado real
     * de asistencia), mientras que getHijo devuelve siempre la OFICIAL. Por eso se
     * recorren las fuentes de boletaContexto y se toma la que realmente aparece en
     * el ranking, en vez de asumir el grado de la matrícula oficial.
     *
     * @return array{0: array, 1: array, 2: array}  [hijo, periodo, contexto]
     */
    private function contextoMerito(): array
    {
        $hijo = $this->getHijo(Session::user()['id']);

        if (!$hijo) {
            $this->redirectWithError(url('padre/inicio'), 'No se encontró información del estudiante.');
        }

        $periodo = $this->getPeriodoVigentePadre((int) $hijo['nivel_id']);

        if (!$periodo) {
            $this->redirectWithError(
                url('padre/inicio'),
                'Aún no hay resultados publicados. El orden de mérito se habilita cuando el colegio publica las boletas.'
            );
        }

        $periodoId = (int) $periodo['id'];
        $fuentes   = $this->calModel->boletaContexto((int) $hijo['matricula_id'])['fuentes'];

        // Grado y sección de cada matrícula candidata (la operativa va primero).
        $marcadores = implode(',', array_fill(0, count($fuentes), '?'));
        $candidatas = $this->calModel->query("
            SELECT m.id, s.grado_id, m.seccion_id, s.nombre AS seccion_nombre,
                   g.nombre_display AS grado_nombre, n.nombre AS nivel_nombre
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            WHERE m.id IN ({$marcadores})
        ", $fuentes);

        foreach ($candidatas as $c) {
            $ranking = $this->ordenModel->rankingGrado((int) $c['grado_id'], $periodoId);
            foreach ($ranking as $fila) {
                if ((int) $fila['matricula_id'] === (int) $c['id']) {
                    return [$hijo, $periodo, [
                        'grado_id'       => (int) $c['grado_id'],
                        'grado_nombre'   => $c['grado_nombre'],
                        'nivel_nombre'   => $c['nivel_nombre'],
                        'seccion_nombre' => $fila['seccion_nombre'],
                        'matricula_id'   => (int) $c['id'],
                        'ranking'        => $ranking,
                    ]];
                }
            }
        }

        // Sin puesto: el alumno no entró al ranking (por ejemplo, sin competencias
        // bloqueadas en el bimestre). Se muestra igual el de su grado, sin resaltar.
        $propia = $candidatas[0] ?? null;

        if (!$propia) {
            $this->redirectWithError(url('padre/notas'), 'No se pudo ubicar la matrícula del estudiante.');
        }

        return [$hijo, $periodo, [
            'grado_id'       => (int) $propia['grado_id'],
            'grado_nombre'   => $propia['grado_nombre'],
            'nivel_nombre'   => $propia['nivel_nombre'],
            'seccion_nombre' => $propia['seccion_nombre'],
            'matricula_id'   => 0,
            'ranking'        => $this->ordenModel->rankingGrado((int) $propia['grado_id'], $periodoId),
        ]];
    }

    /**
     * GET /padre/alertas
     * Ver alertas del tutor.
     */
    public function alertas(): void
    {
        $user    = Session::user();
        $hijo    = $this->getHijo($user['id']);
        $alertas = $hijo ? $this->getAlertas($hijo['matricula_id']) : [];

        $this->view('padre/alertas', [
            'titulo'  => 'Alertas',
            'hijo'    => $hijo,
            'alertas' => $alertas,
        ]);
    }

    // ── Métodos privados ─────────────────────────────────────

    private function getPeriodoActivo(): ?array
    {
        return $this->calModel->queryOne("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado = 'activo'
            LIMIT 1
        ");
    }

    /**
     * F4 — Periodo "vigente" para el padre: el ultimo bimestre CERRADO (oficial)
     * del anio activo. El borrador (Hito A, periodo aun 'activo' con boletas
     * aprobadas) NUNCA se expone a las familias; solo lo oficial.
     *
     * COMPUERTA DE PUBLICACION (044): ademas debe estar PUBLICADO al nivel del
     * hijo. Cerrar un bimestre ya no lo muestra aqui; publicarlo si. Los
     * periodos publicados se piden a PublicacionBoletaModel (punto unico de
     * verdad) y se cruzan en PHP para no consultar la tabla desde aqui.
     */
    private function getPeriodoVigentePadre(int $nivelId): ?array
    {
        $cerrados = $this->calModel->query("
            SELECT p.*, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE a.estado = 'activo' AND p.estado = 'cerrado'
            ORDER BY p.numero DESC
        ");

        if (!$cerrados) {
            return null;
        }

        $publicados = $this->publicacionModel->periodosPublicados(
            (int) $cerrados[0]['anio_id'],
            $nivelId
        );

        foreach ($cerrados as $p) {
            if (isset($publicados[(int) $p['id']])) {
                return $p;
            }
        }
        return null;
    }

    private function getHijo(int $usuarioId): ?array
    {
        return $this->calModel->queryOne("
            SELECT
                e.id            AS estudiante_id,
                m.id            AS matricula_id,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.dni,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                )               AS nombre_completo,
                g.id            AS grado_id,
                g.nombre_display AS grado_nombre,
                m.seccion_id,
                s.nombre        AS seccion_nombre,
                n.id            AS nivel_id,
                n.nombre        AS nivel_nombre,
                n.codigo        AS nivel_codigo,
                n.escala_boleta,
                m.estado        AS estado_matricula
            FROM usuarios u
            INNER JOIN personas pa      ON pa.id = u.persona_id
            INNER JOIN apoderados ap    ON ap.persona_id = pa.id
            INNER JOIN vinculo_familiar vf ON vf.apoderado_id = ap.id
            INNER JOIN estudiantes e    ON e.id = vf.estudiante_id
            INNER JOIN personas p       ON p.id = e.persona_id
            INNER JOIN matriculas m     ON m.estudiante_id = e.id
            INNER JOIN secciones s      ON s.id = m.seccion_id
            INNER JOIN grados g         ON g.id = s.grado_id
            INNER JOIN niveles n        ON n.id = g.nivel_id
            INNER JOIN anios_academicos a ON a.id = m.anio_id
            WHERE u.id      = ?
              AND a.estado  = 'activo'
              AND m.estado  = 'aprobada'
              -- Retorno de grado: el padre siempre ve la matrícula OFICIAL
              -- (grado/sección SIAGIE), nunca la operativa del grado inferior.
              AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'activo')
            LIMIT 1
        ", [$usuarioId]);
    }

    private function getAlertas(int $matriculaId): array
    {
        return $this->calModel->query("
            SELECT
                al.id,
                al.tipo,
                al.mensaje,
                al.leida,
                al.created_at,
                CONCAT(pt.nombres, ' ', pt.apellido_paterno) AS tutor_nombre
            FROM alertas al
            INNER JOIN usuarios ut  ON ut.id  = al.tutor_id
            INNER JOIN personas pt  ON pt.id  = ut.persona_id
            WHERE al.matricula_id = ?
            ORDER BY al.created_at DESC
        ", [$matriculaId]);
    }
}