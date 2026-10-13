<?php

namespace App\Models;

/**
 * AsistenciaJornadaModel — LISTA DEL DÍA, ✓ POR ESTUDIANTE y DÍAS NO LECTIVOS
 * (30/09/2026, migración 070; presencias desde el 09/10/2026, migración 077).
 * Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md (décima ronda).
 *
 * 🔴 Un día de un estudiante está CUBIERTO si tiene una incidencia, o su propio
 * «✓ asistió» (presencia), o si su sección tiene la LISTA de ese día («todos los
 * que no tienen marca propia asistieron»). Sin nada de eso está «Sin tomar» y la
 * pantalla no afirma nada. Sirve a los dos modos del auxiliar: en bloque (lista)
 * o uno por uno (presencias); ninguna marca individual toma la lista.
 *
 * Es el PUNTO ÚNICO de las tres tablas; nadie más las escribe:
 *   - `asistencia_jornadas`: una fila por sección, BIMESTRE y fecha (los
 *     bimestres se solapan). Solo la crea «Pasar lista» (⚠ del día o el aviso de
 *     hoy). No se deshace (decisión del usuario, 09/10/2026): un día se corrige
 *     marcando a cada estudiante, y un día no lectivo borra sus listas solo.
 *   - `asistencia_presencias`: el ✓ de un estudiante en un día. La escribe
 *     `AsistenciaModel::marcarDia`, que en su transacción garantiza que nunca
 *     conviva con una incidencia del mismo día.
 *   - `asistencia_dias_no_lectivos`: feriados y suspensiones de TODO el colegio.
 *     Un día no lectivo no se marca ni se exige al bloquear.
 *
 * En 2027 el ingreso por QR escribirá presencias con `origen = 'qr'`.
 */
class AsistenciaJornadaModel extends BaseModel
{
    protected string $table = 'asistencia_jornadas';

    public const ORIGENES = ['manual', 'qr', 'migracion'];

    /**
     * Listas TOMADAS de una sección en un bimestre.
     *
     * @return array<string, array{origen:string, tomada_en:string, tomada_por:?string}>
     *         [fecha 'AAAA-MM-DD' => …]
     */
    public function tomadasDe(int $seccionId, int $periodoId): array
    {
        $out = [];
        foreach ($this->query("
            SELECT j.fecha, j.origen, j.tomada_en,
                   CONCAT(p.nombres, ' ', p.apellido_paterno) AS tomada_por
            FROM asistencia_jornadas j
            LEFT JOIN usuarios u ON u.id = j.tomada_por
            LEFT JOIN personas p ON p.id = u.persona_id
            WHERE j.seccion_id = ? AND j.periodo_id = ?
            ORDER BY j.fecha
        ", [$seccionId, $periodoId]) as $r) {
            $out[(string) $r['fecha']] = [
                'origen'     => (string) $r['origen'],
                'tomada_en'  => (string) $r['tomada_en'],
                'tomada_por' => $r['tomada_por'],
            ];
        }
        return $out;
    }

    /**
     * Toma la lista del día (idempotente: si ya estaba, no cambia nada). Solo la
     * llama `pasarLista`: desde la 077 ninguna marca individual la toma.
     */
    public function tomar(int $seccionId, int $periodoId, string $fecha, ?int $userId, string $origen = 'manual'): void
    {
        $this->execute("
            INSERT IGNORE INTO asistencia_jornadas (seccion_id, periodo_id, fecha, origen, tomada_por)
            VALUES (?, ?, ?, ?, ?)
        ", [$seccionId, $periodoId, $fecha, $origen, $userId]);
    }

    /** ¿La sección tiene la lista de ese día tomada en ese bimestre? */
    public function estaTomada(int $seccionId, int $periodoId, string $fecha): bool
    {
        return $this->queryOne("
            SELECT 1 AS ok FROM asistencia_jornadas
            WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?
        ", [$seccionId, $periodoId, $fecha]) !== null;
    }

    /**
     * «Pasar lista»: valida el día contra los marcables del bimestre y la toma.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function pasarLista(int $seccionId, array $periodo, string $fecha, int $userId): array
    {
        if (!in_array($fecha, AsistenciaModel::diasMarcables($periodo, null, $this->noLectivos()), true)) {
            return ['ok' => false, 'mensaje' => 'Ese día no se puede tomar (fuera del bimestre, fin de semana, no lectivo o futuro).'];
        }
        $this->tomar($seccionId, (int) $periodo['id'], $fecha, $userId);
        return ['ok' => true, 'mensaje' => 'Lista tomada.'];
    }

    // ── ✓ por estudiante (migración 077) ─────────────────────────

    /**
     * «✓ asistió» de UN estudiante en un día (idempotente). SIN transacción
     * propia: la abre `AsistenciaModel::marcarDia`, que antes borra la incidencia
     * del día (presencia e incidencia nunca conviven).
     */
    public function marcarPresente(int $matriculaId, int $periodoId, string $fecha, int $userId): void
    {
        $this->execute("
            INSERT INTO asistencia_presencias (matricula_id, periodo_id, fecha, registrado_por)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE periodo_id = VALUES(periodo_id)
        ", [$matriculaId, $periodoId, $fecha, $userId]);
    }

    /** Quita el ✓ propio de un día (al marcarle una incidencia). Sin transacción propia. */
    public function quitarPresente(int $matriculaId, string $fecha): void
    {
        $this->execute("
            DELETE FROM asistencia_presencias WHERE matricula_id = ? AND fecha = ?
        ", [$matriculaId, $fecha]);
    }

    /**
     * ✓ propios de varias matrículas en un bimestre.
     *
     * @return array<int, array<string, true>> [matricula_id => [fecha => true]]
     */
    public function presenciasDe(array $matriculaIds, int $periodoId): array
    {
        $ids = array_values(array_filter(array_map('intval', $matriculaIds)));
        if ($ids === []) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->query("
            SELECT matricula_id, fecha FROM asistencia_presencias
            WHERE periodo_id = ? AND matricula_id IN ({$ph})
        ", array_merge([$periodoId], $ids)) as $r) {
            $out[(int) $r['matricula_id']][(string) $r['fecha']] = true;
        }
        return $out;
    }

    /**
     * Estudiantes SIN CUBRIR por día: para cada día marcable del bimestre (hasta
     * hoy, sin no lectivos), cuántos del roster no tienen incidencia, ni ✓ propio,
     * ni la lista de la sección. El roster es el de la grilla
     * (`getEstudiantesConIncidencias`); quien ya lo tiene lo pasa en $matriculaIds.
     *
     * @return array<string, int> [fecha => sin cubrir] (incluye los días en 0)
     */
    public function pendientesPorDia(int $seccionId, array $periodo, ?array $matriculaIds = null, ?string $hoy = null): array
    {
        $pid = (int) $periodo['id'];
        $ids = $matriculaIds ?? array_column(
            (new AsistenciaModel())->getEstudiantesConIncidencias($seccionId, $pid), 'matricula_id'
        );
        $ids = array_values(array_filter(array_map('intval', $ids)));

        $tomadas  = $this->tomadasDe($seccionId, $pid);
        $cubiertos = [];   // [fecha => [matricula_id => true]]
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->query("
                SELECT matricula_id, fecha FROM asistencia_incidencias
                WHERE periodo_id = ? AND matricula_id IN ({$ph})
                UNION
                SELECT matricula_id, fecha FROM asistencia_presencias
                WHERE periodo_id = ? AND matricula_id IN ({$ph})
            ", array_merge([$pid], $ids, [$pid], $ids)) as $r) {
                $cubiertos[(string) $r['fecha']][(int) $r['matricula_id']] = true;
            }
        }

        $out = [];
        foreach (AsistenciaModel::diasMarcables($periodo, $hoy, $this->noLectivos()) as $f) {
            $out[$f] = isset($tomadas[$f]) ? 0 : count($ids) - count($cubiertos[$f] ?? []);
        }
        return $out;
    }

    /**
     * Días MARCABLES del bimestre (hasta hoy, sin no lectivos) con AL MENOS UN
     * estudiante sin cubrir (077: cada estudiante, cada día). El bloqueo del
     * auxiliar/RA exige que esté vacío.
     *
     * @return list<string>
     */
    public function diasSinTomar(int $seccionId, array $periodo, ?string $hoy = null, ?array $matriculaIds = null): array
    {
        return array_keys(array_filter(
            $this->pendientesPorDia($seccionId, $periodo, $matriculaIds, $hoy),
            static fn(int $n): bool => $n > 0
        ));
    }

    // ── Días no lectivos (todo el colegio) ───────────────────────

    /**
     * Días no lectivos, opcionalmente solo los que caen en un bimestre.
     *
     * @return array<string, string> [fecha => motivo]
     */
    public function noLectivos(?array $periodo = null): array
    {
        $sql    = "SELECT fecha, motivo FROM asistencia_dias_no_lectivos";
        $params = [];
        if ($periodo !== null) {
            $sql   .= " WHERE fecha BETWEEN ? AND ?";
            $params = [substr((string) $periodo['fecha_inicio'], 0, 10), substr((string) $periodo['fecha_fin'], 0, 10)];
        }
        $out = [];
        foreach ($this->query($sql . " ORDER BY fecha", $params) as $r) {
            $out[(string) $r['fecha']] = (string) $r['motivo'];
        }
        return $out;
    }

    /** Listado para la pantalla de gestión, con quién lo registró. */
    public function listarNoLectivos(): array
    {
        return $this->query("
            SELECT d.fecha, d.motivo, d.registrado_en,
                   CONCAT(p.nombres, ' ', p.apellido_paterno) AS registrado_por
            FROM asistencia_dias_no_lectivos d
            LEFT JOIN usuarios u ON u.id = d.registrado_por
            LEFT JOIN personas p ON p.id = u.persona_id
            ORDER BY d.fecha DESC
        ");
    }

    /**
     * Declara un día NO LECTIVO para todo el colegio. Rechaza fines de semana,
     * días repetidos y días en que ALGÚN estudiante ya tiene incidencia (habría
     * que quitarla antes: no se borran datos de paso). Las listas y los ✓ propios
     * de ese día se borran en la misma transacción: sin incidencias no llevan
     * ningún dato que cuente.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function declararNoLectivo(string $fecha, string $motivo, int $userId): array
    {
        $motivo = trim($motivo);
        $d = \DateTime::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha) {
            return ['ok' => false, 'mensaje' => 'Fecha no válida.'];
        }
        if ((int) $d->format('N') > 5) {
            return ['ok' => false, 'mensaje' => 'Los sábados y domingos ya no son días de clase.'];
        }
        if ($motivo === '' || mb_strlen($motivo) > 160) {
            return ['ok' => false, 'mensaje' => 'Escribe el motivo (hasta 160 caracteres).'];
        }
        if (isset($this->noLectivos()[$fecha])) {
            return ['ok' => false, 'mensaje' => 'Ese día ya está declarado no lectivo.'];
        }
        $n = (int) ($this->queryOne("SELECT COUNT(*) AS n FROM asistencia_incidencias WHERE fecha = ?", [$fecha])['n'] ?? 0);
        if ($n > 0) {
            return ['ok' => false, 'mensaje' => "Ese día ya tiene {$n} incidencia(s) registrada(s). Quítalas antes de declararlo no lectivo."];
        }

        $this->beginTransaction();
        try {
            $this->execute("
                INSERT INTO asistencia_dias_no_lectivos (fecha, motivo, registrado_por) VALUES (?, ?, ?)
            ", [$fecha, $motivo, $userId]);
            $this->execute("DELETE FROM asistencia_jornadas WHERE fecha = ?", [$fecha]);
            // Los ✓ propios (077) corren la suerte de la lista: no suman a ningún
            // contador, y como no hay «quitar marca» rechazarlos dejaría el día
            // trabado para siempre (decisión del 09/10/2026).
            $this->execute("DELETE FROM asistencia_presencias WHERE fecha = ?", [$fecha]);
            $this->commit();
        } catch (\Throwable $ex) {
            $this->rollback();
            log_error('asistencia.declararNoLectivo', ['err' => $ex->getMessage()]);
            return ['ok' => false, 'mensaje' => 'Error al guardar el día no lectivo.'];
        }
        return ['ok' => true, 'mensaje' => 'Día declarado no lectivo.'];
    }

    /** Vuelve a hacer lectivo un día (queda «Sin tomar» en cada sección). */
    public function quitarNoLectivo(string $fecha): array
    {
        $this->execute("DELETE FROM asistencia_dias_no_lectivos WHERE fecha = ?", [$fecha]);
        return ['ok' => true, 'mensaje' => 'El día vuelve a ser lectivo.'];
    }
}
