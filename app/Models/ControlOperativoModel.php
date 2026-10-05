<?php

namespace App\Models;

/**
 * ControlOperativoModel
 * Detección (solo lectura) de inconsistencias operativas para el Centro de Control.
 *
 * Cada método devuelve una lista de ítems para mostrar y enlazar al módulo donde se
 * corrige. NO modifica datos: el panel solo detecta y enlaza.
 */
class ControlOperativoModel extends BaseModel
{
    protected string $table = 'periodos';

    private CalificacionModel $calModel;
    private SeccionModel      $seccionModel;

    public function __construct()
    {
        parent::__construct();
        $this->calModel     = new CalificacionModel();
        $this->seccionModel = new SeccionModel();
    }

    // ── Selector de periodo / año ────────────────────────────────

    /** Periodos visibles (activos o cerrados), patrón de OrdenMeritoController::index. */
    public function getPeriodos(): array
    {
        return $this->query("
            SELECT p.id, p.nombre_display, p.numero, p.estado, p.boletas_aprobadas_en, p.anio_id, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado IN ('activo', 'cerrado')
            ORDER BY a.anio DESC, p.numero ASC
        ");
    }

    /** Periodo por defecto: el activo; si no hay, el más reciente visible. */
    public function getPeriodoPorDefecto(): ?array
    {
        $activo = $this->queryOne("
            SELECT p.id, p.nombre_display, p.numero, p.estado, p.boletas_aprobadas_en, p.anio_id, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.estado = 'activo'
            ORDER BY a.anio DESC, p.numero ASC
            LIMIT 1
        ");
        if ($activo) return $activo;
        $periodos = $this->getPeriodos();
        return $periodos[0] ?? null;
    }

    public function getPeriodo(int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT p.id, p.nombre_display, p.numero, p.estado, p.boletas_aprobadas_en, p.anio_id, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$periodoId]);
    }

    // ── Chequeo 1: empates de orden de mérito sin resolver ───────

    /**
     * Grados del periodo con ≥1 grupo de empate irreducible aún sin resolver.
     *
     * DELEGA en `OrdenMeritoModel::gradosConEmpatesPendientesDetalle`, que es el
     * MISMO cálculo que usa la pantalla donde se resuelven (`/director/orden-merito`)
     * y el guard del cierre. No replicar la cascada aquí: este método tuvo su propia
     * copia desde el 08/06/2026 y se quedó congelada en la tupla de 3 conteos
     * (num_c|num_b|num_ad), sin los criterios de regularidad alta (`num_alto`,
     * `num_16`) que el motor real incorporó ese mismo día. Resultado: la card
     * inventaba empates que el motor deshace solo y que, al no existir para la
     * pantalla de resolución, eran IRRESOLUBLES — no se limpiaban nunca.
     * De paso arrastraba el roster viejo (`estado='aprobada'` en vez del filtro por
     * `tipo`), no excluía las notas extraordinarias ni las no bloqueadas (P2) y
     * contaba toda la tutoría en lugar de solo Ética y Valores.
     */
    public function empatesPendientes(int $periodoId): array
    {
        return (new OrdenMeritoModel())->gradosConEmpatesPendientesDetalle($periodoId);
    }

    // ── Chequeo 2: competencias con notas pero sin bloquear ──────

    /**
     * Secciones con competencias que tienen notas (num_criterios > 0) pero el docente
     * aún no las bloqueó → la boleta de ese grado no se puede emitir completa.
     */
    public function competenciasSinBloquear(int $periodoId): array
    {
        $todas = $this->calModel->getCompetenciasPorPeriodo($periodoId);

        $porSeccion = [];
        foreach ($todas as $c) {
            $tieneNotas  = (int) ($c['num_criterios'] ?? 0) > 0;
            $sinBloqueo  = ($c['bloqueo_id'] ?? null) === null;
            if (!$tieneNotas || !$sinBloqueo) {
                continue;
            }
            $sid = (int) $c['seccion_id'];
            if (!isset($porSeccion[$sid])) {
                $porSeccion[$sid] = [
                    'seccion_id'     => $sid,
                    'nivel_nombre'   => $c['nivel_nombre'],
                    'grado_nombre'   => $c['grado_nombre'],
                    'seccion_nombre' => $c['seccion_nombre'],
                    'n_competencias' => 0,
                ];
            }
            $porSeccion[$sid]['n_competencias']++;
        }

        return array_values($porSeccion);
    }

    // ── Chequeo 5: competencias fantasma (integridad) ────────────

    /**
     * Competencias FANTASMA: BLOQUEADAS y con calificaciones pero SIN ningún
     * criterio vivo. Estado inconsistente (origen del bug de la boleta de 2°B):
     * aparecería en la boleta pese a no haber sido evaluada. El guard de
     * getBoletaAlumno ya la oculta y la migración 033 la purga; este chequeo la
     * SACA A LA LUZ para que cualquier huérfano nuevo se detecte al instante,
     * no meses después en una boleta. Detección por patrón, sin IDs.
     */
    public function competenciasFantasma(int $periodoId): array
    {
        return $this->query("
            SELECT
                n.nombre             AS nivel_nombre,
                g.nombre_display     AS grado_nombre,
                sec.nombre           AS seccion_nombre,
                a.nombre             AS area_nombre,
                comp.codigo_minedu   AS competencia_codigo,
                comp.nombre_completo AS competencia_nombre,
                CONCAT(pd.apellido_paterno, ' ', pd.nombres) AS docente,
                (SELECT COUNT(*) FROM calificaciones c
                   WHERE c.carga_id       = b.carga_id
                     AND c.competencia_id = b.competencia_id
                     AND c.periodo_id     = b.periodo_id) AS n_calificaciones
            FROM (SELECT DISTINCT carga_id, competencia_id, periodo_id
                  FROM bloqueos_competencia WHERE periodo_id = ?) b
            INNER JOIN cargas_academicas cg ON cg.id   = b.carga_id
            INNER JOIN competencias comp    ON comp.id = b.competencia_id
            LEFT  JOIN subareas suba        ON suba.id = cg.subarea_id
            INNER JOIN areas a              ON a.id    = COALESCE(cg.area_id, suba.area_id)
            INNER JOIN secciones sec        ON sec.id  = cg.seccion_id
            INNER JOIN grados g             ON g.id    = sec.grado_id
            INNER JOIN niveles n            ON n.id    = g.nivel_id
            LEFT  JOIN usuarios ud          ON ud.id   = cg.docente_id
            LEFT  JOIN personas pd          ON pd.id   = ud.persona_id
            WHERE EXISTS (
                    SELECT 1 FROM calificaciones c
                    WHERE c.carga_id       = b.carga_id
                      AND c.competencia_id = b.competencia_id
                      AND c.periodo_id     = b.periodo_id
                  )
              AND NOT EXISTS (
                    SELECT 1 FROM criterios cr
                    WHERE cr.carga_id       = b.carga_id
                      AND cr.competencia_id = b.competencia_id
                      AND cr.periodo_id     = b.periodo_id
                      AND cr.eliminado_en   IS NULL
                  )
            ORDER BY n.nombre, g.numero, sec.nombre, a.nombre
        ", [$periodoId]);
    }

    // ── Chequeo 3: secciones sin tutor (año activo) ──────────────

    public function seccionesSinTutor(): array
    {
        $secciones = $this->seccionModel->listarConTutor();
        return array_values(array_filter(
            $secciones,
            static fn($s) => empty($s['tutor_id'])
        ));
    }

    // ── Chequeo 4: matrículas pendientes de activar ──────────────

    /**
     * Matrículas del año en estado 'pendiente' (proceso de matrícula sin completar).
     * El enum real es pendiente/activo/desactivado/aprobada (migr. 014). NO se filtra
     * por `observaciones` porque las matrículas aprobadas la usan como traza normal
     * (datos de origen, cód. modular) — no es señal de inconsistencia.
     */
    public function matriculasPendientes(int $anioId): array
    {
        return $this->query("
            SELECT
                m.id,
                m.estado,
                m.observaciones,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.dni,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre,
                n.nombre         AS nivel_nombre
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            INNER JOIN secciones s   ON s.id = m.seccion_id
            INNER JOIN grados g      ON g.id = s.grado_id
            INNER JOIN niveles n     ON n.id = g.nivel_id
            WHERE m.anio_id = ?
              AND m.estado = 'pendiente'
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('p', 1) . "
        ", [$anioId]);
    }

    // ── Reporte F5: Incidencias del cierre forzado ───────────────

    /**
     * Reporte de INCIDENCIAS de un bimestre: competencias que se bloquearon de
     * forma automática al aprobar/cerrar el bimestre (bloqueos_competencia con
     * origen='cierre') porque el docente no las había bloqueado a tiempo.
     *
     * Etiqueta NEUTRAL "Incidencias": NO es una sanción, solo deja traza de qué
     * cargas quedaron forzadas. Se agrupa por docente (un bimestre forzado puede
     * dejar cientos de filas crudas; el resumen por docente es lo accionable).
     *
     * Distingue las forzadas "sin avance" (sin ningún criterio registrado en esa
     * carga/competencia/periodo) de las que sí tenían criterios pero el docente
     * nunca llegó a aprobarlas.
     *
     * Retorna ['docentes' => [...], 'resumen' => ['competencias','cargas','docentes','sin_avance']].
     */
    public function incidenciasCierre(int $periodoId): array
    {
        $docentes = $this->query("
            SELECT
                ca.docente_id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                COUNT(DISTINCT bc.carga_id)                      AS n_cargas,
                COUNT(*)                                         AS n_competencias,
                SUM(CASE WHEN cr.cnt IS NULL THEN 1 ELSE 0 END)  AS sin_avance,
                MAX(bc.bloqueado_en)                             AS forzado_en
            FROM bloqueos_competencia bc
            INNER JOIN cargas_academicas ca ON ca.id = bc.carga_id
            INNER JOIN usuarios u           ON u.id  = ca.docente_id
            INNER JOIN personas p           ON p.id  = u.persona_id
            LEFT JOIN (
                SELECT carga_id, competencia_id, periodo_id, COUNT(*) AS cnt
                FROM criterios
                WHERE eliminado_en IS NULL
                GROUP BY carga_id, competencia_id, periodo_id
            ) cr ON cr.carga_id       = bc.carga_id
                AND cr.competencia_id  = bc.competencia_id
                AND cr.periodo_id      = bc.periodo_id
            WHERE bc.origen     = 'cierre'
              AND bc.periodo_id = ?
            GROUP BY ca.docente_id, p.apellido_paterno, p.apellido_materno, p.nombres
            ORDER BY n_competencias DESC, p.apellido_paterno " . COLLATE_ES . ",
                     p.nombres " . COLLATE_ES . "
        ", [$periodoId]);

        $resumen = ['competencias' => 0, 'cargas' => 0, 'docentes' => 0, 'sin_avance' => 0];

        foreach ($docentes as &$d) {
            $d['n_cargas']        = (int) $d['n_cargas'];
            $d['n_competencias']  = (int) $d['n_competencias'];
            $d['sin_avance']      = (int) $d['sin_avance'];
            $d['nombre_completo'] = $d['apellido_paterno'] . ' '
                . $d['apellido_materno'] . ', ' . $d['nombres'];

            $resumen['competencias'] += $d['n_competencias'];
            $resumen['cargas']       += $d['n_cargas'];
            $resumen['sin_avance']   += $d['sin_avance'];
        }
        unset($d);

        $resumen['docentes'] = count($docentes);

        return ['docentes' => $docentes, 'resumen' => $resumen];
    }

    // ── Chequeo 6 (rediseño 2): alerta de evaluación incompleta ──────

    /**
     * ALERTA DE EVALUACIÓN INCOMPLETA (P4). Alumnos con criterios en blanco
     * INJUSTIFICADOS: criterios de las cargas de su SECCIÓN que otros compañeros
     * SÍ tienen con nota (se evaluaron), pero a ellos les faltan y NO están
     * cubiertos por omisión (motivo) ni por exoneración. Compara dentro de la
     * sección (respeta la autonomía docente entre secciones, P4a) y en el mismo
     * universo del mérito (incluye Ética, excluye transversal/tutoría). Se resuelve
     * registrando la nota o la omisión desde el módulo del docente. Prerrequisito
     * del cierre del bimestre. Devuelve una fila por alumno con su detalle.
     *
     * Solo cargas ACTIVAS: una carga dada de baja (por ejemplo, al reasignar la
     * sección) conserva sus criterios, y sin este filtro sus blancos aparecerían
     * como alertas que nadie puede resolver — el docente ya no tiene la carga.
     * Mismo criterio que el resto del sistema (CalificacionModel, TransversalModel,
     * ExoneracionModel, AnioAcademicoModel).
     */
    public function alertasEvaluacionIncompleta(int $periodoId): array
    {
        $filas = $this->query("
            SELECT
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS alumno,
                n.nombre         AS nivel_nombre,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre,
                comp.nombre_completo AS competencia,
                cr.nombre        AS criterio,
                cr.carga_id      AS carga_id
            FROM matriculas m
            INNER JOIN secciones s          ON s.id  = m.seccion_id
            INNER JOIN grados g             ON g.id  = s.grado_id
            INNER JOIN niveles n            ON n.id  = g.nivel_id
            INNER JOIN estudiantes e        ON e.id  = m.estudiante_id
            INNER JOIN personas p           ON p.id  = e.persona_id
            INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
                                           AND ca.estado     = 'activa'
            INNER JOIN criterios cr         ON cr.carga_id      = ca.id
                                           AND cr.periodo_id    = ?
                                           AND cr.eliminado_en  IS NULL
                                           AND cr.extraordinario = 0
            INNER JOIN competencias comp    ON comp.id = cr.competencia_id
            LEFT  JOIN subareas sa          ON sa.id   = comp.subarea_id
            INNER JOIN areas a              ON a.id    = COALESCE(sa.area_id, comp.area_id)
            WHERE m.tipo NOT IN ('trasladado', 'retirado')
              -- MISMO universo que el mérito (`OrdenMeritoModel::rankingGradoLive`):
              -- Ética y Valores entra; el resto de la tutoría y las transversales, no.
              --
              -- Historia, porque este filtro ya divergió una vez: entre el 04/08 y el
              -- 05/08/2026 el mérito excluyó a Ética y esta alerta la mantuvo A
              -- PROPÓSITO (vigila la COMPLETITUD del registro, no el ranking: a Ética le
              -- falta una nota que va a boleta y a SIAGIE igual). Al decidirse que Ética
              -- SÍ cuenta en el mérito de toda secundaria, los dos universos volvieron a
              -- coincidir y la excepción dejó de ser una divergencia deliberada.
              --
              -- ⚠️ Si el mérito vuelve a cambiar, revisar ESTE filtro y también
              -- `database/verificaciones/alerta_evaluacion_incompleta.sql`, que es una
              -- tercera copia de la misma regla.
              AND (a.tipo NOT IN ('transversal', 'tutoria')
                   OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "')
              -- El criterio SÍ se evaluó en la sección (algún alumno tiene nota).
              AND EXISTS (SELECT 1 FROM calificaciones_criterio cc2 WHERE cc2.criterio_id = cr.id)
              -- Al alumno le falta la nota…
              AND NOT EXISTS (SELECT 1 FROM calificaciones_criterio cc
                              WHERE cc.criterio_id = cr.id AND cc.matricula_id = m.id)
              -- …y no la justificó con omisión (motivo)…
              AND NOT EXISTS (SELECT 1 FROM omisiones_criterio oc
                              WHERE oc.criterio_id = cr.id AND oc.matricula_id = m.id)
              -- …ni con exoneración del área.
              AND NOT EXISTS (SELECT 1 FROM exoneraciones ex
                              WHERE ex.matricula_id = m.id AND ex.area_id = a.id
                                AND ex.revocado_en IS NULL)
              -- Anclaje de retorno POR BIMESTRE, el mismo del mérito (punto único
              -- `RetornoGradoModel::sqlCursoElPeriodo`, por el TRAMO). Hasta el 05/10/2026 aquí solo
              -- vivía la mitad «oficial de un retorno activo»: con el retorno
              -- REVERTIDO, la operativa —fuera de todo roster— salía incompleta en
              -- cada criterio nuevo de su ex sección y bloqueaba el cierre.
              -- ⚠️ NO usa `roster_evaluacion()` (helpers.php): esto pertenece al
              -- universo del ORDEN DE MÉRITO, que tiene su propio punto único
              -- (`OrdenMeritoModel::ROSTER_MERITO`) y exige `estado='aprobada'`.
              " . RetornoGradoModel::sqlCursoElPeriodo('m', 'cr.periodo_id') . "
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('p') . ",
                     comp.nombre_completo, cr.orden
        ", [$periodoId]);

        $porAlumno = [];
        foreach ($filas as $f) {
            $mid = (int) $f['matricula_id'];
            if (!isset($porAlumno[$mid])) {
                $porAlumno[$mid] = [
                    'matricula_id'   => $mid,
                    'alumno'         => $f['alumno'],
                    'nivel_nombre'   => $f['nivel_nombre'],
                    'grado_nombre'   => $f['grado_nombre'],
                    'seccion_nombre' => $f['seccion_nombre'],
                    'blancos'        => [],
                ];
            }
            $porAlumno[$mid]['blancos'][] = [
                'competencia' => $f['competencia'],
                'criterio'    => $f['criterio'],
                'carga_id'    => (int) $f['carga_id'],
            ];
        }

        return array_values($porAlumno);
    }
}
