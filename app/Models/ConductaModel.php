<?php

namespace App\Models;

/**
 * ConductaModel — modelo NUMERICO de conducta (rediseno migracion 021).
 *
 * Dos etapas (patron Tutoria/Transversales):
 *   1. Registro Academico responde 10 criterios Si/No por alumno -> nota RA =
 *      (Si / total_criterios) * 20. Bloquea la seccion (cierres_conducta) ->
 *      la conducta se hace visible en boleta (final = nota RA sola).
 *   2. El tutor, solo si RA ya bloqueo, agrega su nota 00-20 opcional (final =
 *      promedio, .5 a favor del estudiante) y cierra/aprueba la seccion.
 *
 * El literal SIEMPRE sale de nota_a_literal() (escala oficial 18/14/11).
 * Las filas del I Bimestre (literal directo, sin respuestas) son legado.
 */
class ConductaModel extends BaseModel
{
    // ── Catalogos / listados ─────────────────────────────────────

    /** Secciones del año activo (nomina aprobada) agrupables por nivel. */
    public function listarSeccionesActivas(): array
    {
        return $this->query("
            SELECT
                s.id,
                s.nombre          AS seccion_nombre,
                g.nombre_display  AS grado_nombre,
                g.numero          AS grado_numero,
                g.nivel_id        AS nivel_id,
                n.nombre          AS nivel_nombre,
                a.id              AS anio_id,
                a.anio
            FROM secciones s
            INNER JOIN grados g             ON g.id = s.grado_id
            INNER JOIN niveles n            ON n.id = g.nivel_id
            INNER JOIN anios_academicos a   ON a.id = s.anio_id
            WHERE a.estado = 'activo'
              AND s.estado_nomina = 'aprobada'
            ORDER BY n.id, g.numero, s.nombre
        ");
    }

    /**
     * Periodos del año activo con flag de edicion (mismo criterio que el resto).
     *
     * ZONA HORARIA — el "ahora" lo calcula PHP y viaja como parámetro
     * preparado, igual que en `periodoEditable()` (que usa `time()`) unas
     * lineas mas abajo. NO usar NOW(): el MySQL de produccion corre en UTC,
     * 5 horas adelantado, y el flag se apagaba antes que el guard real.
     */
    public function listarPeriodosActivos(): array
    {
        return $this->query("
            SELECT
                p.id, p.numero, p.nombre_display, p.estado, p.limite_notas, a.anio,
                (
                    p.estado = 'activo'
                    AND (p.limite_notas IS NULL OR ? <= p.limite_notas)
                ) AS editable
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE a.estado = 'activo'
            ORDER BY p.numero
        ", [date('Y-m-d H:i:s')]);
    }

    /** Criterios vigentes; si se pasa $nivelId, incluye los de ambos (nivel_id NULL). */
    public function getCriterios(?int $nivelId = null): array
    {
        if ($nivelId === null) {
            $filas = $this->query("
                SELECT id, codigo, texto, orden, nivel_id
                FROM criterios_conducta
                WHERE eliminado_en IS NULL
                ORDER BY orden, id
            ");
        } else {
            $filas = $this->query("
                SELECT id, codigo, texto, orden, nivel_id
                FROM criterios_conducta
                WHERE eliminado_en IS NULL
                  AND (nivel_id IS NULL OR nivel_id = ?)
                ORDER BY orden, id
            ", [$nivelId]);
        }

        // 🔴 EL CODIGO SALE DE AQUI, NO DE LAS VISTAS. Se rotulaba a mano como
        // `C{$i + 1}` en el imprimible y en la grilla del tutor: dos copias de la
        // misma regla, y a punto de ser tres. Ahora es un campo mas de la fila.
        //
        // El fallback POSICIONAL cubre un criterio que nazca sin codigo (la
        // columna admite NULL): es exactamente como se rotulaba antes de la
        // migracion 056, asi que no cambia el comportamiento historico.
        //
        // ⚠️ El fallback depende de la POSICION en ESTA lista, que esta filtrada
        // por nivel. Por eso el codigo de la columna es el bueno: un criterio con
        // `nivel_id` haria que la misma posicion significara criterios distintos
        // en primaria y en secundaria.
        foreach ($filas as $i => $f) {
            $codigo = trim((string) ($f['codigo'] ?? ''));
            $filas[$i]['codigo'] = $codigo !== '' ? $codigo : 'C' . ($i + 1);
        }
        return $filas;
    }

    /** Total de criterios vigentes que aplican a un nivel (para la formula y completitud). */
    public function totalCriterios(int $nivelId): int
    {
        $row = $this->queryOne("
            SELECT COUNT(*) AS total
            FROM criterios_conducta
            WHERE eliminado_en IS NULL
              AND (nivel_id IS NULL OR nivel_id = ?)
        ", [$nivelId]);
        return (int) ($row['total'] ?? 0);
    }

    /** [seccion_id, nivel_id] de una matricula (para validar en el guardado). */
    public function contextoMatricula(int $matriculaId): ?array
    {
        return $this->queryOne("
            SELECT m.seccion_id, g.nivel_id
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            WHERE m.id = ?
        ", [$matriculaId]);
    }

    // ── Indice: progreso y estado de bloqueo por seccion ─────────

    /**
     * Por seccion: esperados, calificados (alumnos con sus N criterios completos),
     * y estado del cierre (bloqueada por RA / cerrada por tutor). Una sola query.
     */
    public function getProgresoConductaPorSeccion(int $periodoId): array
    {
        $rows = $this->query("
            SELECT
                s.id AS seccion_id,
                COUNT(DISTINCT m.id) AS esperados,
                COUNT(DISTINCT CASE
                    WHEN sub.respondidos > 0 AND sub.respondidos >= (
                        SELECT COUNT(*) FROM criterios_conducta k
                        WHERE k.eliminado_en IS NULL
                          AND (k.nivel_id IS NULL OR k.nivel_id = g.nivel_id)
                    ) THEN m.id END) AS calificados,
                MAX(z.id IS NOT NULL)              AS bloqueada,
                MAX(z.tutor_cerrado_en IS NOT NULL) AS cerrada_tutor
            FROM secciones s
            INNER JOIN grados g ON g.id = s.grado_id
            INNER JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
            LEFT JOIN matriculas m
                   ON m.seccion_id = s.id AND m.anio_id = s.anio_id
                  -- Roster de evaluacion (punto unico en helpers.php).
                  " . roster_evaluacion('m') . "
            LEFT JOIN (
                SELECT matricula_id, COUNT(*) AS respondidos
                FROM conducta_respuestas WHERE periodo_id = ?
                GROUP BY matricula_id
            ) sub ON sub.matricula_id = m.id
            LEFT JOIN cierres_conducta z
                   ON z.seccion_id = s.id AND z.periodo_id = ? AND z.anulado_en IS NULL
            WHERE s.estado_nomina = 'aprobada'
            GROUP BY s.id, g.nivel_id
        ", [$periodoId, $periodoId]);

        $mapa = [];
        foreach ($rows as $r) {
            $mapa[(int) $r['seccion_id']] = [
                'esperados'     => (int) $r['esperados'],
                'calificados'   => (int) $r['calificados'],
                'bloqueada'     => (bool) $r['bloqueada'],
                'cerrada_tutor' => (bool) $r['cerrada_tutor'],
            ];
        }
        return $mapa;
    }

    // ── Etapa 1: Registro Academico (respuestas Si/No) ───────────

    /**
     * Alumnos de la seccion con su matriz de respuestas [criterio_id => 0|1].
     * Para la grilla de registro de RA.
     */
    public function getEstudiantesParaRegistro(int $seccionId, int $periodoId): array
    {
        $alumnos = $this->query("
            SELECT
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo,
                -- Literal directo SIN matriz: el de la via extraordinaria de un
                -- bimestre cerrado (migracion 063). El historial de RA lo pinta
                -- en la columna de nota en vez de dejar la fila en blanco.
                cc.literal                        AS literal_directo,
                COALESCE(cc.extraordinaria, 0)    AS extraordinaria
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            LEFT JOIN calificaciones_conducta cc
                   ON cc.matricula_id = m.id AND cc.periodo_id = ?
            WHERE m.seccion_id = ?
              -- Mismo roster que el docente al ingresar notas (getAlumnosSeccion):
              -- TODOS los matriculados de la seccion (aprobada, pendiente e incluso
              -- desactivado por baja administrativa/deuda: siguen asistiendo). El
              -- Roster de evaluacion (punto unico en helpers.php).
              " . roster_evaluacion('m') . "
              AND m.anio_id = (SELECT id FROM anios_academicos WHERE estado='activo' LIMIT 1)
            ORDER BY " . orden_alfabetico('p') . "
        ", [$periodoId, $seccionId]);

        if (empty($alumnos)) {
            return [];
        }

        $ids = array_column($alumnos, 'matricula_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $resp = $this->query("
            SELECT matricula_id, criterio_id, respuesta
            FROM conducta_respuestas
            WHERE periodo_id = ? AND matricula_id IN ($ph)
        ", array_merge([$periodoId], $ids));

        $idx = [];
        foreach ($resp as $r) {
            $idx[(int) $r['matricula_id']][(int) $r['criterio_id']] = (int) $r['respuesta'];
        }

        foreach ($alumnos as &$a) {
            $a['respuestas'] = $idx[(int) $a['matricula_id']] ?? [];
        }
        return $alumnos;
    }

    /**
     * Literales legados de la seccion (I Bimestre: literal directo en
     * calificaciones_conducta, sin matriz de respuestas). Mismo roster que
     * getEstudiantesParaRegistro; literal NULL si el alumno no tiene registro.
     * Para el historial de solo lectura de RA.
     */
    public function getLiteralesLegado(int $seccionId, int $periodoId): array
    {
        return $this->query("
            SELECT
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo,
                cc.literal,
                COALESCE(cc.extraordinaria, 0) AS extraordinaria
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            LEFT JOIN calificaciones_conducta cc
                ON cc.matricula_id = m.id AND cc.periodo_id = ?
            WHERE m.seccion_id = ?
              -- Roster de evaluacion (punto unico en helpers.php).
              " . roster_evaluacion('m') . "
              AND m.anio_id = (SELECT id FROM anios_academicos WHERE estado='activo' LIMIT 1)
            ORDER BY " . orden_alfabetico('p') . "
        ", [$periodoId, $seccionId]);
    }

    /**
     * Datos del registro legado de la seccion (bimestre con literal directo,
     * anterior a la matriz de criterios): quien registro y cuando (ultimo
     * guardado). Devuelve null si la seccion tiene matriz de respuestas en el
     * periodo (modelo nuevo) o si no hay literales registrados.
     */
    public function getRegistroLegado(int $seccionId, int $periodoId): ?array
    {
        $hayMatriz = $this->queryOne("
            SELECT 1
            FROM conducta_respuestas r
            INNER JOIN matriculas m ON m.id = r.matricula_id
            WHERE m.seccion_id = ? AND r.periodo_id = ?
            LIMIT 1
        ", [$seccionId, $periodoId]);
        if ($hayMatriz) {
            return null;
        }

        $registro = $this->queryOne("
            SELECT
                CONCAT(p.nombres, ' ', p.apellido_paterno, ' ', p.apellido_materno) AS usuario,
                cc.registrado_en
            FROM calificaciones_conducta cc
            INNER JOIN matriculas m ON m.id = cc.matricula_id
            INNER JOIN usuarios   u ON u.id = cc.registrado_por
            INNER JOIN personas   p ON p.id = u.persona_id
            WHERE m.seccion_id = ? AND cc.periodo_id = ? AND cc.literal IS NOT NULL
              -- La via extraordinaria (migracion 063) NO es el registro de la
              -- seccion: un literal que RA ingreso hoy para un alumno que llego
              -- tarde haria decir al banner del tutor «registradas por RA, hoy».
              AND cc.extraordinaria = 0
            ORDER BY cc.registrado_en DESC
            LIMIT 1
        ", [$seccionId, $periodoId]);

        return $registro ?: null;
    }

    /**
     * Upsert atomico de las respuestas de un alumno. Exige que esten TODOS los
     * criterios de $criterioIds (los 10 son obligatorios). Devuelve false si falta
     * alguno o ante error de BD.
     * @param array<int,int> $respuestas [criterio_id => 0|1]
     * @param array<int>     $criterioIds ids de criterios vigentes del nivel
     */
    public function guardarRespuestas(int $matriculaId, int $periodoId, array $respuestas, int $userId, array $criterioIds): bool
    {
        foreach ($criterioIds as $cid) {
            if (!array_key_exists($cid, $respuestas)) {
                return false; // incompleto: no se permiten respuestas en blanco
            }
        }

        $this->beginTransaction();
        try {
            $sql = "INSERT INTO conducta_respuestas
                        (matricula_id, periodo_id, criterio_id, respuesta, registrado_por)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        respuesta      = VALUES(respuesta),
                        registrado_por = VALUES(registrado_por),
                        modificado_en  = NOW()";
            foreach ($criterioIds as $cid) {
                $this->execute($sql, [
                    $matriculaId, $periodoId, $cid, $respuestas[$cid] ? 1 : 0, $userId,
                ]);
            }
            $this->commit();
            return true;
        } catch (\Throwable $ex) {
            $this->rollback();
            log_error('conducta.guardarRespuestas', ['err' => $ex->getMessage()]);
            return false;
        }
    }

    // ── Completitud y cierre por seccion ─────────────────────────

    /** [esperados, completos]: alumnos aprobados vs. los que tienen sus N respuestas. */
    public function completitudSeccion(int $seccionId, int $periodoId, int $totalCriterios): array
    {
        $row = $this->queryOne("
            SELECT
                COUNT(*) AS esperados,
                SUM(CASE WHEN sub.respondidos >= ? THEN 1 ELSE 0 END) AS completos
            FROM matriculas m
            LEFT JOIN (
                SELECT matricula_id, COUNT(*) AS respondidos
                FROM conducta_respuestas WHERE periodo_id = ?
                GROUP BY matricula_id
            ) sub ON sub.matricula_id = m.id
            WHERE m.seccion_id = ?
              -- Roster de evaluacion (punto unico en helpers.php): la compuerta de
              -- completitud debe contar exactamente a quienes aparecen en la grilla.
              " . roster_evaluacion('m') . "
              AND m.anio_id = (SELECT id FROM anios_academicos WHERE estado='activo' LIMIT 1)
        ", [$totalCriterios, $periodoId, $seccionId]);

        return [
            'esperados' => (int) ($row['esperados'] ?? 0),
            'completos' => (int) ($row['completos'] ?? 0),
        ];
    }

    /**
     * Resumen por seccion para el panel del director (espejo del de
     * transversales): datos de la seccion + tutor, estado de las DOS etapas
     * del cierre de conducta y completitud (alumnos calificados / esperados).
     * Solo secciones del año del periodo CON tutor asignado (la etapa 2 lo exige).
     */
    public function getResumenSeccionesPorPeriodo(int $periodoId): array
    {
        $secciones = $this->query("
            SELECT s.id              AS seccion_id,
                   s.nombre          AS seccion_nombre,
                   g.numero          AS grado_numero,
                   g.nombre_display  AS grado_nombre,
                   n.id              AS nivel_id,
                   n.nombre          AS nivel_nombre,
                   n.codigo          AS nivel_codigo,
                   CONCAT(pu.apellido_paterno, ', ', pu.nombres) AS tutor_nombre,
                   cc.id             AS cierre_id,
                   cc.ra_bloqueado_en,
                   cc.tutor_cerrado_en
            FROM secciones s
            INNER JOIN grados g    ON g.id  = s.grado_id
            INNER JOIN niveles n   ON n.id  = g.nivel_id
            INNER JOIN usuarios u  ON u.id  = s.tutor_id
            INNER JOIN personas pu ON pu.id = u.persona_id
            LEFT JOIN cierres_conducta cc
                ON  cc.seccion_id = s.id
                AND cc.periodo_id = ?
                AND cc.anulado_en IS NULL
            WHERE s.anio_id   = (SELECT anio_id FROM periodos WHERE id = ?)
              AND s.tutor_id IS NOT NULL
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId]);

        // Completitud (esperados/calificados) reutilizando la query del indice de RA.
        $progreso = $this->getProgresoConductaPorSeccion($periodoId);
        foreach ($secciones as &$s) {
            $p = $progreso[(int) $s['seccion_id']] ?? ['esperados' => 0, 'calificados' => 0];
            $s['esperados']   = $p['esperados'];
            $s['calificados'] = $p['calificados'];
        }
        unset($s);

        return $secciones;
    }

    /** Cierre vigente (anulado_en IS NULL) de una seccion+periodo, o null. */
    public function getCierreVigente(int $seccionId, int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT * FROM cierres_conducta
            WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL
            ORDER BY id DESC LIMIT 1
        ", [$seccionId, $periodoId]);
    }

    /** Cierre vigente con los nombres de quien bloqueó/cerró (para el imprimible). */
    public function getCierreDetalle(int $seccionId, int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT cc.*,
                   CONCAT(pr.apellido_paterno, ' ', pr.apellido_materno, ', ', pr.nombres) AS ra_nombre,
                   CONCAT(pt.apellido_paterno, ' ', pt.apellido_materno, ', ', pt.nombres) AS tutor_nombre
            FROM cierres_conducta cc
            INNER JOIN usuarios ur ON ur.id = cc.ra_bloqueado_por
            INNER JOIN personas pr ON pr.id = ur.persona_id
            LEFT JOIN usuarios ut  ON ut.id = cc.tutor_cerrado_por
            LEFT JOIN personas pt  ON pt.id = ut.persona_id
            WHERE cc.seccion_id = ? AND cc.periodo_id = ? AND cc.anulado_en IS NULL
            ORDER BY cc.id DESC LIMIT 1
        ", [$seccionId, $periodoId]);
    }

    /**
     * Etapa 1: RA bloquea/aprueba la seccion. Precondicion: completitud total.
     * @return array{ok:bool,mensaje:string}
     */
    public function bloquearRA(int $seccionId, int $periodoId, int $userId, int $totalCriterios): array
    {
        if ($this->getCierreVigente($seccionId, $periodoId)) {
            return ['ok' => false, 'mensaje' => 'La conducta de esta seccion ya esta bloqueada.'];
        }
        $c = $this->completitudSeccion($seccionId, $periodoId, $totalCriterios);
        if ($c['esperados'] === 0) {
            return ['ok' => false, 'mensaje' => 'No hay estudiantes matriculados en esta seccion.'];
        }
        if ($c['completos'] < $c['esperados']) {
            return ['ok' => false, 'mensaje' =>
                "Faltan estudiantes por calificar ({$c['completos']}/{$c['esperados']}). " .
                'Complete los criterios de todos antes de bloquear.'];
        }
        $ok = $this->execute("
            INSERT INTO cierres_conducta (seccion_id, periodo_id, ra_bloqueado_en, ra_bloqueado_por)
            VALUES (?, ?, NOW(), ?)
        ", [$seccionId, $periodoId, $userId]);

        return ['ok' => $ok, 'mensaje' => $ok ? 'Conducta bloqueada y aprobada.' : 'Error al bloquear.'];
    }

    /**
     * Etapa 2: el tutor cierra/aprueba. Precondicion: RA ya bloqueo.
     * @return array{ok:bool,mensaje:string}
     */
    public function cerrarTutor(int $seccionId, int $periodoId, int $userId): array
    {
        $cierre = $this->getCierreVigente($seccionId, $periodoId);
        if (!$cierre) {
            return ['ok' => false, 'mensaje' =>
                'Todavia los auxiliares academicos no han registrado sus calificaciones de conducta. ' .
                'Consulte con Registro Academico para mas informacion.'];
        }
        if ($cierre['tutor_cerrado_en']) {
            return ['ok' => false, 'mensaje' => 'La conducta de esta seccion ya fue cerrada por el tutor.'];
        }
        $ok = $this->execute("
            UPDATE cierres_conducta
            SET tutor_cerrado_en = NOW(), tutor_cerrado_por = ?
            WHERE id = ?
        ", [$userId, (int) $cierre['id']]);

        return ['ok' => $ok, 'mensaje' => $ok ? 'Conducta cerrada y aprobada.' : 'Error al cerrar.'];
    }

    /** Desbloqueo (admin/director): anula el cierre vigente con traza. */
    public function anularCierre(int $seccionId, int $periodoId, int $userId, string $motivo): bool
    {
        $c = $this->getCierreVigente($seccionId, $periodoId);
        if (!$c) {
            return false;
        }
        return $this->execute("
            UPDATE cierres_conducta
            SET anulado_en = NOW(), anulado_por = ?, motivo_anulacion = ?
            WHERE id = ?
        ", [$userId, $motivo, (int) $c['id']]);
    }

    // ── Etapa 2: nota del tutor ──────────────────────────────────

    /** Upsert de la nota del tutor (00-20). $nota === null limpia la nota. */
    public function guardarNotaTutor(int $matriculaId, int $periodoId, ?int $nota, int $userId): bool
    {
        if ($nota === null) {
            return $this->execute("
                UPDATE calificaciones_conducta
                SET nota_tutor = NULL, modificado_en = NOW()
                WHERE matricula_id = ? AND periodo_id = ?
            ", [$matriculaId, $periodoId]);
        }
        return $this->execute("
            INSERT INTO calificaciones_conducta
                (matricula_id, periodo_id, literal, nota_tutor, registrado_por)
            VALUES (?, ?, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE
                nota_tutor     = VALUES(nota_tutor),
                registrado_por = VALUES(registrado_por),
                modificado_en  = NOW()
        ", [$matriculaId, $periodoId, $nota, $userId]);
    }

    // ── Via EXTRAORDINARIA (bimestre cerrado, migracion 063) ─────

    /**
     * Condicion SQL: la matricula `$m` ADMITE conducta extraordinaria en el
     * periodo `$per`. PUNTO UNICO de la regla: la usan el lote (que filas
     * ofrece), su POST (re-chequeo) y el listado de /rectificaciones.
     *
     *   1. SIN REGISTRO: ni literal, ni nota del tutor, ni matriz de criterios.
     *      La via extraordinaria nunca pisa un dato existente.
     *   2. CIERRE DE CONDUCTA VIGENTE en su seccion. Sin el, la boleta no
     *      pinta la conducta (`getParaPeriodo`, campo `visible`): el literal
     *      quedaria registrado e INVISIBLE. Mismo candado que las
     *      transversales del lote.
     *
     * 🔴 El «sin registro» mira TODAS las fuentes que une la boleta (retorno de
     * grado), no solo `$m`: en la matricula 692 el I Bimestre vive en la
     * oficial 190, y la boleta ya lo muestra.
     *
     * @param string $m   alias de `matriculas` en la consulta que la incrusta
     * @param string $per alias de `periodos`
     */
    public static function sqlAdmiteExtraordinaria(string $m = 'm', string $per = 'per'): string
    {
        $fuentes = CalificacionModel::sqlFuentesBoleta($m);
        return "NOT EXISTS (
                    SELECT 1 FROM calificaciones_conducta ccx
                    WHERE ccx.periodo_id = {$per}.id
                      AND ccx.matricula_id IN {$fuentes}
                      AND (ccx.literal IS NOT NULL OR ccx.nota_tutor IS NOT NULL)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM conducta_respuestas rx
                    WHERE rx.periodo_id = {$per}.id
                      AND rx.matricula_id IN {$fuentes}
                )
                AND EXISTS (
                    SELECT 1 FROM cierres_conducta zx
                    WHERE zx.seccion_id = {$m}.seccion_id AND zx.periodo_id = {$per}.id
                      AND zx.anulado_en IS NULL
                )";
    }

    /**
     * ¿Admite conducta EXTRAORDINARIA? Periodo CERRADO, roster de evaluacion
     * y la regla de `sqlAdmiteExtraordinaria`. Re-chequeo del POST del lote.
     */
    public function admiteExtraordinaria(int $matriculaId, int $periodoId): bool
    {
        $fila = $this->queryOne("
            SELECT 1 AS ok
            FROM matriculas m
            INNER JOIN periodos per ON per.id = ? AND per.anio_id = m.anio_id
            WHERE m.id = ?
              AND per.estado = 'cerrado'
              " . roster_evaluacion('m') . "
              AND " . self::sqlAdmiteExtraordinaria('m', 'per') . "
        ", [$periodoId, $matriculaId]);

        return $fila !== null;
    }

    /**
     * Alta EXTRAORDINARIA de la conducta de un bimestre cerrado, como LITERAL
     * DIRECTO (decision del usuario, 22/09/2026). La boleta lo lee sin cambios:
     * sin matriz de criterios, `componerLiteral` devuelve el literal.
     *
     * 🔴 NUNCA PISA. Puede existir una fila vacia (literal y nota del tutor en
     * NULL, p. ej. una nota del tutor que se borro): esa se completa. Si trae
     * cualquier dato, o si hay matriz de criterios, lanza y la transaccion del
     * llamante hace rollback.
     *
     * ⚠️ No abre transaccion: la owna quien llama (el lote).
     */
    public function registrarLiteralExtraordinario(
        int $matriculaId,
        int $periodoId,
        string $literal,
        string $motivo,
        int $userId
    ): void {
        if (!in_array($literal, ['AD', 'A', 'B', 'C'], true)) {
            throw new \InvalidArgumentException('Literal de conducta no valido: ' . $literal);
        }

        $matriz = $this->queryOne("
            SELECT 1 AS x FROM conducta_respuestas
            WHERE matricula_id = ? AND periodo_id = ? LIMIT 1
        ", [$matriculaId, $periodoId]);
        if ($matriz !== null) {
            throw new \RuntimeException('El alumno ya tiene conducta registrada por criterios.');
        }

        $fila = $this->queryOne("
            SELECT id, literal, nota_tutor
            FROM calificaciones_conducta
            WHERE matricula_id = ? AND periodo_id = ?
            FOR UPDATE
        ", [$matriculaId, $periodoId]);

        if ($fila === null) {
            $this->execute("
                INSERT INTO calificaciones_conducta
                    (matricula_id, periodo_id, literal, nota_tutor,
                     extraordinaria, motivo_extraordinaria, registrado_por)
                VALUES (?, ?, ?, NULL, 1, ?, ?)
            ", [$matriculaId, $periodoId, $literal, $motivo, $userId]);
            return;
        }

        if ($fila['literal'] !== null || $fila['nota_tutor'] !== null) {
            throw new \RuntimeException('El alumno ya tiene conducta registrada.');
        }
        $this->execute("
            UPDATE calificaciones_conducta
            SET literal = ?, extraordinaria = 1, motivo_extraordinaria = ?,
                registrado_por = ?, modificado_en = NOW()
            WHERE id = ? AND literal IS NULL AND nota_tutor IS NULL
        ", [$literal, $motivo, $userId, (int) $fila['id']]);
    }

    /**
     * Alumnos de la seccion con nota RA derivada, nota del tutor y vista previa de
     * la final/literal. Para el panel del tutor.
     */
    public function getEstudiantesParaTutor(int $seccionId, int $periodoId, int $totalCriterios): array
    {
        $rows = $this->query("
            SELECT
                m.id AS matricula_id,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo,
                cc.nota_tutor,
                cc.literal AS literal_legado,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = ? AND r.respuesta = 1) AS si,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = ?) AS respondidos
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            LEFT JOIN calificaciones_conducta cc ON cc.matricula_id = m.id AND cc.periodo_id = ?
            WHERE m.seccion_id = ?
              -- Roster de evaluacion (punto unico en helpers.php).
              " . roster_evaluacion('m') . "
              AND m.anio_id = (SELECT id FROM anios_academicos WHERE estado='activo' LIMIT 1)
            ORDER BY " . orden_alfabetico('p') . "
        ", [$periodoId, $periodoId, $periodoId, $seccionId]);

        foreach ($rows as &$r) {
            $respondidos = (int) $r['respondidos'];
            $si          = (int) $r['si'];
            $nt          = $r['nota_tutor'] !== null ? (int) $r['nota_tutor'] : null;

            if ($respondidos > 0 && $totalCriterios > 0) {
                // Modelo nuevo (B2+): nota RA derivada de las respuestas Si/No.
                $notaRA = ($si / $totalCriterios) * 20;
                $final  = $this->promediar($notaRA, $nt);

                $r['nota_ra']       = (int) round($notaRA, 0, PHP_ROUND_HALF_UP);
                $r['nota_final']    = $final;
                $r['literal_final'] = nota_a_literal($final);
                $r['es_legado']     = false;
            } elseif ($r['literal_legado'] !== null) {
                // Legado (I Bimestre): literal directo, sin nota numerica.
                // Mismo criterio que componerLiteral() (la nota del tutor no aplica).
                $r['nota_ra']       = null;
                $r['nota_final']    = null;
                $r['literal_final'] = $r['literal_legado'];
                $r['es_legado']     = true;
            } else {
                // Sin datos para este periodo.
                $r['nota_ra']       = null;
                $r['nota_final']    = null;
                $r['literal_final'] = null;
                $r['es_legado']     = false;
            }
        }
        unset($r);
        return $rows;
    }

    // ── Calculo de la nota final ─────────────────────────────────

    /**
     * Promedio peso igual con redondeo A FAVOR del estudiante (.5 sube). Si el tutor
     * no califico ($notaTutor === null), la final es la nota RA sola.
     */
    private function promediar(float $notaRA, ?int $notaTutor): int
    {
        $valor = $notaTutor !== null ? ($notaRA + $notaTutor) / 2 : $notaRA;
        return (int) round($valor, 0, PHP_ROUND_HALF_UP);
    }

    // ── Lectura para boleta / panel del padre ────────────────────

    /**
     * [periodo_id => literal] de los periodos del año cuya conducta esta VISIBLE
     * (cierre vigente con ra_bloqueado). B2+ deriva el literal de la nota final;
     * B1 (legado) usa el literal directo. Usado en boleta y panel del padre.
     */
    public function getParaBoleta(int $matriculaId, int $anioId): array
    {
        $rows = $this->query("
            SELECT
                p.id                                   AS periodo_id,
                g.nivel_id                             AS nivel_id,
                cc.literal                             AS literal_legado,
                cc.nota_tutor                          AS nota_tutor,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = p.id AND r.respuesta = 1) AS si,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = p.id) AS respondidos,
                (SELECT COUNT(*) FROM criterios_conducta k
                  WHERE k.eliminado_en IS NULL
                    AND (k.nivel_id IS NULL OR k.nivel_id = g.nivel_id)) AS total_criterios,
                EXISTS(SELECT 1 FROM cierres_conducta z
                  WHERE z.seccion_id = m.seccion_id AND z.periodo_id = p.id
                    AND z.anulado_en IS NULL) AS visible
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            INNER JOIN periodos  p ON p.anio_id = ?
            LEFT JOIN calificaciones_conducta cc ON cc.matricula_id = m.id AND cc.periodo_id = p.id
            WHERE m.id = ?
        ", [$anioId, $matriculaId]);

        $result = [];
        foreach ($rows as $r) {
            if (!$r['visible']) {
                continue;
            }
            $literal = $this->componerLiteral(
                (int) $r['si'],
                (int) $r['respondidos'],
                (int) $r['total_criterios'],
                $r['nota_tutor'] !== null ? (int) $r['nota_tutor'] : null,
                $r['literal_legado']
            );
            if ($literal !== null) {
                $result[(int) $r['periodo_id']] = $literal;
            }
        }
        return $result;
    }

    /**
     * Versión por UNIÓN para el retorno de grado: fusiona la conducta de varias
     * matrículas del mismo estudiante (oficial + operativa) en un único mapa
     * [periodo_id => literal]. Las matrículas vienen ordenadas con la oficial al
     * final, de modo que gana ante un eventual choque en el mismo periodo.
     * Con una sola matrícula se comporta igual que getParaBoleta().
     */
    public function getParaBoletaUnion(array $matriculaIds, int $anioId): array
    {
        if (count($matriculaIds) <= 1) {
            return $this->getParaBoleta((int) ($matriculaIds[0] ?? 0), $anioId);
        }

        $out = [];
        foreach ($matriculaIds as $id) {
            $out = array_replace($out, $this->getParaBoleta((int) $id, $anioId));
        }
        return $out;
    }

    /** Conducta (literal) de un alumno en un solo periodo, o null si no es visible. */
    public function getParaPeriodo(int $matriculaId, int $periodoId): ?string
    {
        $r = $this->queryOne("
            SELECT
                g.nivel_id,
                cc.literal    AS literal_legado,
                cc.nota_tutor AS nota_tutor,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = ? AND r.respuesta = 1) AS si,
                (SELECT COUNT(*) FROM conducta_respuestas r
                  WHERE r.matricula_id = m.id AND r.periodo_id = ?) AS respondidos,
                (SELECT COUNT(*) FROM criterios_conducta k
                  WHERE k.eliminado_en IS NULL
                    AND (k.nivel_id IS NULL OR k.nivel_id = g.nivel_id)) AS total_criterios,
                EXISTS(SELECT 1 FROM cierres_conducta z
                  WHERE z.seccion_id = m.seccion_id AND z.periodo_id = ?
                    AND z.anulado_en IS NULL) AS visible
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            LEFT JOIN calificaciones_conducta cc ON cc.matricula_id = m.id AND cc.periodo_id = ?
            WHERE m.id = ?
        ", [$periodoId, $periodoId, $periodoId, $periodoId, $matriculaId]);

        if (!$r || !$r['visible']) {
            return null;
        }
        return $this->componerLiteral(
            (int) $r['si'],
            (int) $r['respondidos'],
            (int) $r['total_criterios'],
            $r['nota_tutor'] !== null ? (int) $r['nota_tutor'] : null,
            $r['literal_legado']
        );
    }

    /**
     * Compone el literal final para boleta: modelo nuevo (hay respuestas de RA) ->
     * deriva de la nota final; legado B1 (sin respuestas) -> literal directo.
     */
    private function componerLiteral(int $si, int $respondidos, int $total, ?int $notaTutor, ?string $literalLegado): ?string
    {
        if ($respondidos > 0 && $total > 0) {
            $notaRA = ($si / $total) * 20;
            return nota_a_literal($this->promediar($notaRA, $notaTutor));
        }
        return $literalLegado; // puede ser null si no hay nada
    }

    // ── Estadistica para el tablero de Direccion ─────────────────

    /**
     * Distribucion de literales de conducta por NIVEL y por BIMESTRE del año.
     *
     * Alimenta el tablero de `/admin/cuadros`: cuantos estudiantes estan en AD,
     * A, B y C, y como evoluciona el "en logro" (AD+A) a lo largo del año.
     *
     * 🔴 EL LITERAL NO SE CALCULA AQUI. La consulta trae los ingredientes en
     * crudo (si / respondidos / total_criterios / nota_tutor / literal legado) y
     * el literal lo compone `componerLiteral()`, el MISMO metodo que usan la
     * boleta y el panel del padre. Reimplementar `(si/total)*20` o los umbrales
     * 18/14/11 en SQL habria creado una cuarta copia de esa aritmetica —ya hay
     * tres— y las copias de este repositorio divergen sin sintoma.
     *
     * Ademas es lo unico que hace bien el I Bimestre: es LEGADO (0 respuestas,
     * `literal` escrito directo) y `componerLiteral()` ya ramifica por eso.
     *
     * NO filtra por `cierres_conducta`: el tablero es de monitoreo y tiene que
     * servir con el bimestre en curso a medio llenar. La visibilidad en boleta
     * es otra pregunta y la responden `getParaBoleta`/`getParaPeriodo`.
     *
     * La forma de salida es la de `AnioAcademicoModel::getEvolucionAnual` a
     * proposito: la vista de graficos ya sabe recorrerla, y la serie va SIEMPRE
     * paralela al eje X (una celda por bimestre del año, aunque valga 0).
     *
     * @return array{periodos: list<array>, niveles: list<array>}
     */
    public function getDistribucionLiteralesAnual(int $anioId): array
    {
        $filas = $this->query("
            SELECT
                p.id             AS periodo_id,
                p.numero         AS periodo_numero,
                p.nombre_display AS periodo_nombre,
                n.id             AS nivel_id,
                n.nombre         AS nivel_nombre,
                n.codigo         AS nivel_codigo,
                cc.literal       AS literal_legado,
                cc.nota_tutor    AS nota_tutor,
                COALESCE(r.si, 0)          AS si,
                COALESCE(r.respondidos, 0) AS respondidos,
                (SELECT COUNT(*) FROM criterios_conducta k
                  WHERE k.eliminado_en IS NULL
                    AND (k.nivel_id IS NULL OR k.nivel_id = n.id)) AS total_criterios
            FROM periodos p
            INNER JOIN secciones  s ON s.anio_id = p.anio_id AND s.estado_nomina = 'aprobada'
            INNER JOIN grados     g ON g.id = s.grado_id
            INNER JOIN niveles    n ON n.id = g.nivel_id
            INNER JOIN matriculas m
                    ON m.seccion_id = s.id AND m.anio_id = s.anio_id
                   -- Roster de evaluacion (punto unico en helpers.php).
                   " . roster_evaluacion('m') . "
            LEFT JOIN calificaciones_conducta cc
                   ON cc.matricula_id = m.id AND cc.periodo_id = p.id
            LEFT JOIN (
                SELECT matricula_id, periodo_id,
                       SUM(respuesta = 1) AS si,
                       COUNT(*)           AS respondidos
                FROM conducta_respuestas
                GROUP BY matricula_id, periodo_id
            ) r ON r.matricula_id = m.id AND r.periodo_id = p.id
            WHERE p.anio_id = ?
            ORDER BY p.numero, n.id
        ", [$anioId]);

        // Eje X: todos los bimestres del año, con o sin dato. Recortarlo aqui
        // dejaria series de distinta longitud y el grafico saldria corrido.
        $periodos = $this->query("
            SELECT id, numero, nombre_display
            FROM periodos WHERE anio_id = ? ORDER BY numero
        ", [$anioId]);

        $vacia   = ['ad' => 0, 'a' => 0, 'b' => 0, 'c' => 0, 'total' => 0];
        $niveles = [];   // [nivel_id => ['nivel_*' => ..., 'celdas' => [periodo_id => conteos]]]

        foreach ($filas as $f) {
            $nid = (int) $f['nivel_id'];
            $pid = (int) $f['periodo_id'];

            if (!isset($niveles[$nid])) {
                $niveles[$nid] = [
                    'nivel_id'     => $nid,
                    'nivel_nombre' => $f['nivel_nombre'],
                    'nivel_codigo' => $f['nivel_codigo'],
                    'celdas'       => [],
                ];
                foreach ($periodos as $p) {
                    $niveles[$nid]['celdas'][(int) $p['id']] = $vacia;
                }
            }

            $literal = $this->componerLiteral(
                (int) $f['si'],
                (int) $f['respondidos'],
                (int) $f['total_criterios'],
                $f['nota_tutor'] !== null ? (int) $f['nota_tutor'] : null,
                $f['literal_legado']
            );

            // Sin literal el alumno NO entra al denominador: contarlo como si
            // estuviera en C convertiria "todavia no lo han calificado" en un
            // resultado malo, que es justo lo contrario.
            if ($literal === null) {
                continue;
            }

            $niveles[$nid]['celdas'][$pid][strtolower($literal)]++;
            $niveles[$nid]['celdas'][$pid]['total']++;
        }

        $salida = [];
        foreach ($niveles as $n) {
            $serie = [];
            foreach ($periodos as $p) {
                $pid = (int) $p['id'];
                $c   = $n['celdas'][$pid] ?? $vacia;
                $tot = (int) $c['total'];
                $log = (int) $c['ad'] + (int) $c['a'];

                $serie[] = [
                    'periodo_id' => $pid,
                    'ad'         => (int) $c['ad'],
                    'a'          => (int) $c['a'],
                    'b'          => (int) $c['b'],
                    'c'          => (int) $c['c'],
                    'total'      => $tot,
                    'logro'      => $log,
                    'pct_logro'  => $tot > 0 ? round($log / $tot * 100, 1) : 0.0,
                ];
            }

            unset($n['celdas']);
            $n['serie'] = $serie;
            $salida[]   = $n;
        }

        return [
            'periodos' => array_map(static fn(array $p): array => [
                'id'     => (int) $p['id'],
                'numero' => (int) $p['numero'],
                'nombre' => (string) $p['nombre_display'],
            ], $periodos),
            'niveles'  => $salida,
        ];
    }

    /**
     * Incumplimiento de cada criterio de conducta, a nivel institucional y por
     * seccion. Es la lectura agregada de la grilla Si/No de los auxiliares.
     *
     * "Incumplimiento" = `respuesta = 0` (el boton "No cumple" de la grilla).
     * Se devuelve tanto el conteo como el porcentaje sobre las respuestas
     * REGISTRADAS, no sobre el roster: un criterio a medio responder no debe
     * parecer cumplido.
     *
     * ⚠️ Con el I Bimestre devuelve las dos listas VACIAS y no es un error: es
     * el bimestre legado, que se registro con un literal directo y nunca tuvo
     * grilla de criterios. La vista tiene que decirlo, no dejar un hueco mudo.
     *
     * El `codigo` sale de la columna (migracion 056) con el mismo fallback
     * posicional que `getCriterios()`: rotularlo a mano en la vista fue la
     * regresion que este modulo ya tuvo una vez.
     *
     * @return array{criterios: list<array>, secciones: list<array>}
     */
    public function getIncumplimientoCriterios(int $periodoId): array
    {
        $filas = $this->query("
            SELECT
                k.id     AS criterio_id,
                k.codigo AS criterio_codigo,
                k.texto  AS criterio_texto,
                k.orden  AS criterio_orden,
                s.id     AS seccion_id,
                s.nombre AS seccion_nombre,
                g.numero AS grado_numero,
                n.id     AS nivel_id,
                n.codigo AS nivel_codigo,
                COUNT(*)             AS respondidos,
                SUM(r.respuesta = 0) AS no_cumple
            FROM conducta_respuestas r
            INNER JOIN criterios_conducta k ON k.id = r.criterio_id
            INNER JOIN matriculas m
                    ON m.id = r.matricula_id
                   -- Roster de evaluacion (punto unico en helpers.php).
                   " . roster_evaluacion('m') . "
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            INNER JOIN niveles   n ON n.id = g.nivel_id
            WHERE r.periodo_id = ?
              AND k.eliminado_en IS NULL
            GROUP BY k.id, s.id
            ORDER BY n.id, g.numero, s.nombre, k.orden, k.id
        ", [$periodoId]);

        if (empty($filas)) {
            return ['criterios' => [], 'secciones' => []];
        }

        $criterios = [];   // [criterio_id => totales institucionales]
        $secciones = [];   // [seccion_id  => fila + matriz por criterio]

        foreach ($filas as $f) {
            $kid = (int) $f['criterio_id'];
            $sid = (int) $f['seccion_id'];
            $res = (int) $f['respondidos'];
            $no  = (int) $f['no_cumple'];

            if (!isset($criterios[$kid])) {
                $criterios[$kid] = [
                    'id'          => $kid,
                    'codigo'      => trim((string) ($f['criterio_codigo'] ?? '')),
                    'texto'       => (string) $f['criterio_texto'],
                    'orden'       => (int) $f['criterio_orden'],
                    'respondidos' => 0,
                    'no_cumple'   => 0,
                ];
            }
            $criterios[$kid]['respondidos'] += $res;
            $criterios[$kid]['no_cumple']   += $no;

            if (!isset($secciones[$sid])) {
                $secciones[$sid] = [
                    'seccion_id'   => $sid,
                    'nivel_codigo' => (string) $f['nivel_codigo'],
                    // Misma etiqueta que el resto del tablero: primaria y
                    // secundaria repiten los numeros de grado y sin la inicial
                    // del nivel habria dos "1° A" indistinguibles.
                    'etq'          => (int) $f['grado_numero'] . '° ' . $f['seccion_nombre']
                        . ' (' . strtoupper(substr((string) $f['nivel_codigo'], 0, 1)) . ')',
                    'por_criterio' => [],
                ];
            }
            $secciones[$sid]['por_criterio'][$kid] = [
                'respondidos' => $res,
                'no_cumple'   => $no,
                'pct'         => $res > 0 ? round($no / $res * 100, 1) : 0.0,
            ];
        }

        // El fallback posicional es el mismo de getCriterios(): un criterio que
        // naciera sin codigo se rotula por su sitio en ESTA lista, como antes de
        // la migracion 056.
        $criterios = array_values($criterios);
        usort($criterios, static fn(array $x, array $y): int
            => [$x['orden'], $x['id']] <=> [$y['orden'], $y['id']]);

        foreach ($criterios as $i => $k) {
            if ($k['codigo'] === '') {
                $criterios[$i]['codigo'] = 'C' . ($i + 1);
            }
            $criterios[$i]['pct'] = $k['respondidos'] > 0
                ? round($k['no_cumple'] / $k['respondidos'] * 100, 1)
                : 0.0;
        }

        return ['criterios' => $criterios, 'secciones' => array_values($secciones)];
    }

    // ── Verificacion de edicion ──────────────────────────────────

    /** true si el periodo esta abierto para edicion. */
    public function periodoEditable(int $periodoId): bool
    {
        $p = $this->queryOne("SELECT estado, limite_notas FROM periodos WHERE id = ?", [$periodoId]);
        if (!$p || $p['estado'] !== 'activo') {
            return false;
        }
        if ($p['limite_notas'] && strtotime($p['limite_notas']) < time()) {
            return false;
        }
        return true;
    }
}
