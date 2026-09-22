<?php

namespace App\Models;

/**
 * TransversalModel
 * Competencias transversales (TIC/GAMA) registradas por cada docente
 * en su carga + cierre bimestral del tutor de sección.
 *
 * Regla de agregación ÚNICA (cubre B1 con la carga del tutor y B2+ con
 * las cargas de docentes): promedio de los promedios por carga bloqueada,
 * por alumno + competencia + periodo. Cada fila de `calificaciones` ya ES
 * el promedio de la carga, así que basta AVG sobre las filas con bloqueo.
 */
class TransversalModel extends BaseModel
{
    protected string $table = 'cierres_transversales';

    // ── Competencias transversales por nivel ─────────────────────

    public function getCompetencias(int $nivelId): array
    {
        return $this->query("
            SELECT c.id, c.codigo_minedu, c.nombre_corto, c.nombre_completo, c.orden
            FROM competencias c
            INNER JOIN areas a ON a.id = c.area_id
            WHERE a.tipo     = 'transversal'
              AND a.nivel_id = ?
            ORDER BY c.orden
        ", [$nivelId]);
    }

    // ── Cierres ──────────────────────────────────────────────────

    /** Cierre vigente (anulado_en IS NULL) de una sección + periodo. */
    public function getCierreVigente(int $seccionId, int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT ct.*,
                   CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres)
                       AS cerrado_por_nombre
            FROM cierres_transversales ct
            INNER JOIN usuarios u ON u.id = ct.cerrado_por
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE ct.seccion_id = ?
              AND ct.periodo_id = ?
              AND ct.anulado_en IS NULL
            ORDER BY ct.cerrado_en DESC
            LIMIT 1
        ", [$seccionId, $periodoId]);
    }

    /** Registra el cierre del tutor. Falla si ya hay uno vigente. */
    public function cerrar(int $seccionId, int $periodoId, int $usuarioId): bool
    {
        if ($this->getCierreVigente($seccionId, $periodoId)) {
            return false;
        }
        return $this->execute("
            INSERT INTO cierres_transversales (seccion_id, periodo_id, cerrado_por)
            VALUES (?, ?, ?)
        ", [$seccionId, $periodoId, $usuarioId]);
    }

    /**
     * Anula el cierre vigente de una sección + periodo con traza de
     * quién/cuándo/por qué. Retorna true si había uno que anular.
     */
    public function anularCierreVigente(
        int $seccionId,
        int $periodoId,
        int $usuarioId,
        string $motivo
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE cierres_transversales
            SET anulado_en       = NOW(),
                anulado_por      = ?,
                motivo_anulacion = ?
            WHERE seccion_id = ?
              AND periodo_id = ?
              AND anulado_en IS NULL
        ");
        $stmt->execute([$usuarioId, mb_substr($motivo, 0, 500), $seccionId, $periodoId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Resumen del estado transversal por sección, para el panel del director.
     * Cada sección con tutor del año del periodo, con su cierre vigente si lo
     * hay. El estado REAL de las transversales lo gobierna el cierre (lo que
     * habilita TIC/GAMA en la boleta), NO la carga transversal heredada (que
     * quedó inactiva en la migración 019); por eso se evalúa por sección.
     */
    public function getResumenSeccionesPorPeriodo(int $periodoId): array
    {
        return $this->query("
            SELECT s.id              AS seccion_id,
                   s.nombre          AS seccion_nombre,
                   g.numero          AS grado_numero,
                   g.nombre_display  AS grado_nombre,
                   n.id              AS nivel_id,
                   n.nombre          AS nivel_nombre,
                   n.codigo          AS nivel_codigo,
                   CONCAT(pu.apellido_paterno, ', ', pu.nombres) AS tutor_nombre,
                   ct.id             AS cierre_id,
                   ct.cerrado_en
            FROM secciones s
            INNER JOIN grados g    ON g.id  = s.grado_id
            INNER JOIN niveles n   ON n.id  = g.nivel_id
            INNER JOIN usuarios u  ON u.id  = s.tutor_id
            INNER JOIN personas pu ON pu.id = u.persona_id
            LEFT JOIN cierres_transversales ct
                ON  ct.seccion_id = s.id
                AND ct.periodo_id = ?
                AND ct.anulado_en IS NULL
            WHERE s.anio_id   = (SELECT anio_id FROM periodos WHERE id = ?)
              AND s.tutor_id IS NOT NULL
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId]);
    }

    // ── Agregación de promedios ──────────────────────────────────

    /**
     * Promedios transversales agregados de UNA matrícula en un periodo:
     * [competencia_id => nota_redondeada]. Solo cuenta calificaciones de
     * cargas que el docente aprobó/bloqueó.
     */
    public function getPromediosMatricula(int $matriculaId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT cal.competencia_id,
                   ROUND(AVG(cal.nota_numerica)) AS promedio
            FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc
                ON  bc.carga_id       = cal.carga_id
                AND bc.competencia_id = cal.competencia_id
                AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            INNER JOIN areas a           ON a.id = comp.area_id AND a.tipo = 'transversal'
            WHERE cal.matricula_id = ?
              AND cal.periodo_id   = ?
            GROUP BY cal.competencia_id
        ", [$matriculaId, $periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['competencia_id']] = (int) $f['promedio'];
        }
        return $out;
    }

    /**
     * Promedios transversales agregados de TODA una sección en un periodo:
     * [matricula_id => [competencia_id => nota]]. Excluye traslados de salida.
     */
    public function getPromediosSeccion(int $seccionId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT cal.matricula_id,
                   cal.competencia_id,
                   ROUND(AVG(cal.nota_numerica)) AS promedio
            FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc
                ON  bc.carga_id       = cal.carga_id
                AND bc.competencia_id = cal.competencia_id
                AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            INNER JOIN areas a           ON a.id = comp.area_id AND a.tipo = 'transversal'
            INNER JOIN matriculas m      ON m.id = cal.matricula_id
            WHERE m.seccion_id   = ?
              AND m.tipo        NOT IN ('trasladado', 'retirado')
              AND cal.periodo_id = ?
            GROUP BY cal.matricula_id, cal.competencia_id
        ", [$seccionId, $periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['matricula_id']][(int) $f['competencia_id']] = (int) $f['promedio'];
        }
        return $out;
    }

    // ── Conclusiones ─────────────────────────────────────────────

    /** [matricula_id => [competencia_id => conclusion]] de una sección. */
    public function getConclusionesSeccion(int $seccionId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT ct.matricula_id, ct.competencia_id, ct.conclusion
            FROM conclusiones_transversales ct
            INNER JOIN matriculas m ON m.id = ct.matricula_id
            WHERE m.seccion_id = ?
              AND ct.periodo_id = ?
        ", [$seccionId, $periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['matricula_id']][(int) $f['competencia_id']] = $f['conclusion'];
        }
        return $out;
    }

    /** [competencia_id => conclusion] de una matrícula en un periodo. */
    public function getConclusionesMatricula(int $matriculaId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT competencia_id, conclusion
            FROM conclusiones_transversales
            WHERE matricula_id = ?
              AND periodo_id   = ?
        ", [$matriculaId, $periodoId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['competencia_id']] = $f['conclusion'];
        }
        return $out;
    }

    /** Upsert de conclusión; texto vacío la elimina. */
    public function guardarConclusion(
        int $matriculaId,
        int $competenciaId,
        int $periodoId,
        string $conclusion,
        int $usuarioId
    ): bool {
        $conclusion = trim($conclusion);

        if ($conclusion === '') {
            return $this->execute("
                DELETE FROM conclusiones_transversales
                WHERE matricula_id = ? AND competencia_id = ? AND periodo_id = ?
            ", [$matriculaId, $competenciaId, $periodoId]);
        }

        return $this->execute("
            INSERT INTO conclusiones_transversales
                (matricula_id, competencia_id, periodo_id, conclusion, registrado_por)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                conclusion     = VALUES(conclusion),
                registrado_por = VALUES(registrado_por)
        ", [$matriculaId, $competenciaId, $periodoId, $conclusion, $usuarioId]);
    }

    // ── Estado de la sección (para la vista del tutor y la card) ─

    /**
     * Sección a cargo del tutor en el año activo (NULL si no es tutor).
     */
    public function getSeccionDelTutor(int $usuarioId): ?array
    {
        return $this->queryOne("
            SELECT s.id, s.nombre, s.es_unidocente,
                   g.nombre_display AS grado_nombre,
                   n.id     AS nivel_id,
                   n.nombre AS nivel_nombre,
                   n.codigo AS nivel_codigo,
                   n.escala_boleta
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            INNER JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
            WHERE s.tutor_id = ?
            LIMIT 1
        ", [$usuarioId]);
    }

    /**
     * Avance de aprobación TRANSVERSAL de las cargas ACTIVAS de la sección en un
     * periodo: cuántas competencias TIC/GAMA aporta cada carga y cuántas están ya
     * bloqueadas. Retorna ['total' => N, 'bloqueadas' => M, 'cargas' => detalle[]].
     *
     * ⚠️ CUENTA SOLO LAS TRANSVERSALES (desde el 06/08/2026). Antes sumaba también
     * las competencias PROPIAS de cada carga, y eso acoplaba el cierre del tutor a
     * las académicas SIN NINGUNA RAZÓN DE DATO: el promedio que el cierre congela
     * sale de `getPromediosSeccion`, que filtra `a.tipo = 'transversal'` — las
     * académicas no participan de lo que se cierra. El tutor esperaba por notas que
     * no iban a cambiar su resultado. Medido en B2: en 10 de 23 secciones la última
     * académica llegó DESPUÉS que la última transversal, hasta 47 h después.
     *
     * ⚠️ CONSECUENCIA ACEPTADA: al poder cerrar antes, se alarga la ventana en la
     * que un desbloqueo académico posterior ANULA EL CIERRE del tutor
     * (`BloqueoController::desbloquear`), que debe repetirlo. Se conserva a
     * propósito: si cambian las notas del estudiante, su conclusión descriptiva
     * puede dejar de ser precisa aunque el promedio transversal no se mueva.
     *   - Desde el **07/08/2026** eso es lo ÚNICO que hace: ya NO se liberan los
     *     bloqueos TIC/GAMA de la carga (`liberarTransversalesDeCarga` quedó
     *     DORMIDO). Antes sí, y el efecto era que este gate bajaba su numerador
     *     —el tutor no podía re-cerrar hasta que el DOCENTE volviera a aprobar sus
     *     transversales—. Ahora los bloqueos quedan intactos, el gate sigue
     *     cuadrando y el tutor re-cierra sin depender de nadie.
     *   - Cifra que conviene no repetir mal: de las 48 anulaciones sobre 71 cierres
     *     de B2, **solo 2 vinieron de este camino**; las otras 46 son
     *     `limpiarBloqueosCierre` (medido el 07/08/2026).
     *
     * ⚠️ La lógica de "carga dueña" que usan total_comp y comp_bloqueadas está
     * escrita en CUATRO SITIOS (mantenerlos en sync; si cambia uno, revisar los
     * otros tres):
     *   1. CalificacionController::calificaciones            (formulario del docente)
     *   2. AQUI                                              (gate del cierre del tutor)
     *   3. CalificacionModel::cargaDuenaTransversales
     *   4. AnioAcademicoModel::bloquearCompetenciasPendientes (cierre forzado)
     * El sitio 4 no la aplicaba hasta el 06/08/2026: el cierre inventaba bloqueos
     * en cargas TOE y no-dueñas (130 en B2, ver migración 051). Ese mismo defecto ya
     * había inflado ANTES el numerador de este método (53/41) — entonces se parcheó
     * el conteo, no el origen; ahora está corregido el origen.
     *
     * Una carga con `total_comp = 0` (TOE, o no dueña en unidocente) NO aporta nada
     * al gate: se devuelve igual en 'cargas' para que la vista pueda distinguir
     * "no aplica" de "pendiente".
     */
    public function estadoCargasSeccion(int $seccionId, int $periodoId): array
    {
        $cargas = $this->query("
            SELECT
                ca.id,
                COALESCE(sa.nombre, a.nombre) AS nombre_display,
                CONCAT(pu.apellido_paterno, ' ', pu.nombres) AS docente_nombre,
                (
                    -- Transversales TIC/GAMA: cada docente las registra en su
                    -- carga, asi que cada carga suma +N (su universo TIC/GAMA).
                    -- Las competencias PROPIAS de la carga YA NO SUMAN (06/08/2026):
                    -- no participan del promedio que el cierre congela, ver docblock.
                    -- UNIDOCENTE: el mismo docente dicta todas las subareas de un
                    -- area, asi que las TIC/GAMA cuentan UNA vez por area (en la
                    -- carga dueña = subarea de menor orden); las demas subareas
                    -- suman 0, o el cierre del tutor nunca cuadraria (se exigiria
                    -- bloquear TIC/GAMA en cada subarea cuando solo viven en la
                    -- dueña). Para polidocente cada carga suma las suyas.
                    CASE WHEN s.es_unidocente = 1
                              AND ca.id <> (
                                  SELECT cad.id FROM cargas_academicas cad
                                  LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                                  WHERE cad.seccion_id = ca.seccion_id
                                    AND cad.estado     = 'activa'
                                    AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                                  ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                              )
                         THEN 0
                         ELSE (
                            SELECT COUNT(*)
                            FROM competencias ct
                            INNER JOIN areas at2 ON at2.id = ct.area_id
                            WHERE at2.tipo     = 'transversal'
                              AND at2.nivel_id = nv.id
                         )
                    END
                ) AS total_comp,
                (
                    -- Bloqueadas: SOLO las transversales, con la MISMA lógica de
                    -- dueña que total_comp (una vez por área, incluidos los
                    -- especialistas Inglés/Ed. Física). Las académicas de la carga
                    -- YA NO SE CUENTAN (06/08/2026): el numerador y el denominador
                    -- se movieron juntos, así que el gate sigue cuadrando.
                    -- Antes se contaban TODOS los bloqueos de la carga; tras un
                    -- cierre que bloquea TIC/GAMA en cada subárea, las no-dueña
                    -- sumaban transversales que el total (dueña) no cuenta,
                    -- inflando el numerador por encima del total (ej. 53/41) y
                    -- habilitando las conclusiones antes de tiempo. El denominador
                    -- NO cambia: las transversales de los especialistas siguen
                    -- siendo obligatorias (las conclusiones promedian TODAS las
                    -- áreas de la sección, no solo las de la unidocente).
                    (
                        CASE WHEN s.es_unidocente = 1
                                  AND ca.id <> (
                                      SELECT cad.id FROM cargas_academicas cad
                                      LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                                      WHERE cad.seccion_id = ca.seccion_id
                                        AND cad.estado     = 'activa'
                                        AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                                      ORDER BY COALESCE(sad.orden, 0), cad.id LIMIT 1
                                  )
                             THEN 0
                             ELSE (
                                SELECT COUNT(*) FROM bloqueos_competencia bct
                                INNER JOIN competencias compt ON compt.id = bct.competencia_id
                                INNER JOIN areas at2 ON at2.id = compt.area_id AND at2.tipo = 'transversal'
                                WHERE bct.carga_id = ca.id AND bct.periodo_id = ? AND at2.nivel_id = nv.id
                             )
                        END
                    )
                ) AS comp_bloqueadas
            FROM cargas_academicas ca
            INNER JOIN secciones s ON s.id  = ca.seccion_id
            INNER JOIN grados g    ON g.id  = s.grado_id
            INNER JOIN niveles nv  ON nv.id = g.nivel_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas a     ON a.id  = COALESCE(ca.area_id, sa.area_id)
            LEFT  JOIN usuarios u  ON u.id  = ca.docente_id
            LEFT  JOIN personas pu ON pu.id = u.persona_id
            WHERE ca.seccion_id = ?
              AND ca.estado     = 'activa'
              -- Fuera del cierre transversal: la carga transversal independiente
              -- (modelo viejo) Y la carga de tutoria (Etica y Valores, 07/07/2026).
              -- La TOE no registra TIC/GAMA (exclusion del formulario) y su
              -- competencia propia tiene su propio ciclo de bloqueo: no debe
              -- condicionar ni inflar el cierre del tutor.
              AND (a.tipo IS NULL OR a.tipo NOT IN ('transversal', 'tutoria'))
            ORDER BY a.orden, sa.id
        ", [$periodoId, $seccionId]);

        $total = $bloqueadas = 0;
        foreach ($cargas as $c) {
            $total      += (int) $c['total_comp'];
            $bloqueadas += (int) $c['comp_bloqueadas'];
        }

        return ['total' => $total, 'bloqueadas' => $bloqueadas, 'cargas' => $cargas];
    }

    /**
     * Conclusiones obligatorias FALTANTES según el literal agregado:
     * primaria exige en B y C; secundaria solo en C.
     */
    public function conclusionesObligatoriasPendientes(
        int $seccionId,
        int $periodoId,
        string $nivelCodigo
    ): int {
        $nivel        = $nivelCodigo === 'prim' ? 'primaria' : 'secundaria';
        $promedios    = $this->getPromediosSeccion($seccionId, $periodoId);
        $conclusiones = $this->getConclusionesSeccion($seccionId, $periodoId);

        $pendientes = 0;
        foreach ($promedios as $matriculaId => $porComp) {
            foreach ($porComp as $compId => $nota) {
                $literal = nota_a_literal((int) $nota, $nivel);
                if (!conclusion_es_obligatoria($literal, $nivel)) {
                    continue;
                }
                if (empty($conclusiones[$matriculaId][$compId])) {
                    $pendientes++;
                }
            }
        }
        return $pendientes;
    }

    /**
     * Bloqueos de competencias TRANSVERSALES del periodo, fila por
     * (carga, competencia), para el panel del director.
     *
     * Solo devuelve las cargas que TIENEN al menos un bloqueo transversal: es lo
     * único sobre lo que hay algo que hacer, y así se evita reescribir aquí —por
     * quinta vez— la regla de "carga dueña" (una carga sin bloqueo no aparece,
     * que es exactamente lo que el panel necesita saber).
     *
     * ⚠️ Estas filas NO salen en `getCompetenciasPorPeriodo`: ese JOIN une
     * competencia↔carga por el área de la CARGA, y las transversales cuelgan de
     * un área propia (`tipo='transversal'`). Por eso el panel principal nunca las
     * mostró y la única vía para liberarlas era la cascada de una académica.
     *
     * `num_notas` permite avisar de cuántas calificaciones vuelven a ser
     * editables, y `origen` distingue lo que aprobó el docente de lo que forzó
     * el cierre.
     */
    public function getBloqueosTransversalesPorPeriodo(int $periodoId): array
    {
        return $this->query("
            SELECT ca.seccion_id,
                   bc.id                AS bloqueo_id,
                   bc.origen,
                   bc.bloqueado_en,
                   ca.id                AS carga_id,
                   COALESCE(sa.nombre, ar.nombre) AS carga_nombre,
                   CONCAT(pu.apellido_paterno, ', ', pu.nombres) AS docente_nombre,
                   comp.id              AS competencia_id,
                   comp.nombre_corto    AS competencia_nombre,
                   comp.orden           AS competencia_orden,
                   (
                       SELECT COUNT(*) FROM calificaciones cal
                       WHERE cal.carga_id       = ca.id
                         AND cal.competencia_id = comp.id
                         AND cal.periodo_id     = bc.periodo_id
                   )                    AS num_notas
            FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            INNER JOIN areas a           ON a.id    = comp.area_id AND a.tipo = 'transversal'
            INNER JOIN cargas_academicas ca ON ca.id = bc.carga_id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas ar    ON ar.id = COALESCE(ca.area_id, sa.area_id)
            LEFT  JOIN usuarios u  ON u.id  = ca.docente_id
            LEFT  JOIN personas pu ON pu.id = u.persona_id
            WHERE bc.periodo_id = ?
            ORDER BY ca.seccion_id,
                     COALESCE(sa.nombre, ar.nombre) " . COLLATE_ES . ",
                     comp.orden
        ", [$periodoId]);
    }

    /**
     * Libera (elimina) los bloqueos de las competencias TRANSVERSALES (TIC/GAMA)
     * de una carga en un periodo. Retorna cuántas se liberaron.
     *
     * 🔴 DORMIDO DESDE EL 07/08/2026 — SIN NINGÚN LLAMADOR, a propósito.
     * Existía para la cascada de `BloqueoController::desbloquear`: al desbloquear
     * una competencia académica se liberaban también las TIC/GAMA de la carga,
     * porque no eran filas del panel y habrían quedado "bloqueadas e inalcanzables".
     * Ese motivo murió el 06/08 con el desbloqueo granular por competencia
     * (`liberarTransversalCompetencia`). La cascada se retiró porque obligaba al
     * docente a re-aprobar transversales que nadie tocó y bloqueaba al tutor:
     * al borrar esos bloqueos bajaba el numerador de `estadoCargasSeccion`, así que
     * el tutor no podía re-cerrar hasta que el docente volviera a aprobarlas.
     *
     * ⚠️ NO REINTRODUCIR sin decidirlo de nuevo: la granularidad del desbloqueo
     * transversal es una decisión explícita del usuario (07/08/2026). Si algún día
     * hace falta "reabrir la carga completa", que sea una acción propia y declarada,
     * no un efecto colateral de desbloquear una competencia académica.
     * Se conserva el método (no se borra) por el patrón del repo: dormir antes que
     * eliminar, para no perder la implementación si la decisión se revierte.
     */
    public function liberarTransversalesDeCarga(int $cargaId, int $periodoId): int
    {
        $stmt = $this->db->prepare("
            DELETE bc FROM bloqueos_competencia bc
            INNER JOIN competencias comp ON comp.id = bc.competencia_id
            INNER JOIN areas a           ON a.id   = comp.area_id
            WHERE bc.carga_id   = ?
              AND bc.periodo_id = ?
              AND a.tipo        = 'transversal'
        ");
        $stmt->execute([$cargaId, $periodoId]);
        return $stmt->rowCount();
    }

    /**
     * Anula los cierres vigentes del periodo cuyas secciones aparecen en
     * la lista (usado al reabrir un bimestre). Retorna cuántos anuló.
     */
    public function anularCierresDeSecciones(
        array $seccionIds,
        int $periodoId,
        int $usuarioId,
        string $motivo
    ): int {
        $anulados = 0;
        foreach (array_unique(array_map('intval', $seccionIds)) as $sid) {
            if ($this->anularCierreVigente($sid, $periodoId, $usuarioId, $motivo)) {
                $anulados++;
            }
        }
        return $anulados;
    }

    /**
     * Secciones del periodo con bloqueos del CIERRE FORZADO (origen='cierre')
     * — son las que se verán afectadas al liberar esos bloqueos de forma manual
     * desde el panel. Su cierre transversal vigente debe anularse con traza.
     */
    public function seccionesConBloqueosDeCierre(int $periodoId): array
    {
        $filas = $this->query("
            SELECT DISTINCT ca.seccion_id
            FROM bloqueos_competencia bc
            INNER JOIN cargas_academicas ca ON ca.id = bc.carga_id
            WHERE bc.periodo_id = ?
              AND bc.origen     = 'cierre'
              -- Mismo universo que eliminarBloqueosDeCierre, que conserva los
              -- de competencias con extraordinarias: sin esto se anularía el
              -- cierre de una sección a la que no se le liberó nada.
              AND " . CalificacionModel::SIN_EXTRAORDINARIAS_BC . "
        ", [$periodoId]);

        return array_map(static fn($f) => (int) $f['seccion_id'], $filas);
    }
}
