<?php

namespace App\Models;

/**
 * AsistenciaJornadaModel — LISTA DEL DÍA y DÍAS NO LECTIVOS (30/09/2026,
 * migración 070). Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * 🔴 «✓ asistió» NO es un dato por estudiante: es «la sección tiene su lista
 * tomada ese día» + «el estudiante no tiene incidencia ese día». Sin lista, el
 * día está «Sin tomar» y la pantalla no afirma nada. Es el PUNTO ÚNICO de las
 * dos tablas; nadie más las escribe:
 *   - `asistencia_jornadas`: una fila por sección, BIMESTRE y fecha (los
 *     bimestres se solapan). La crea cualquier marca (`AsistenciaModel::marcarDia`
 *     llama a `tomar` en su transacción) o el botón «Pasar lista». Solo se
 *     deshace si ningún estudiante de la sección tiene incidencia ese día.
 *   - `asistencia_dias_no_lectivos`: feriados y suspensiones de TODO el colegio.
 *     Un día no lectivo no se marca ni se exige al bloquear.
 *
 * En 2027 el ingreso por QR abrirá la misma lista con `origen = 'qr'`.
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
     * Toma la lista del día (idempotente: si ya estaba, no cambia nada). SIN
     * transacción propia: la abre quien llama (`marcarDia`) o `pasarLista`.
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

    /**
     * Deshace la lista de un día: SOLO si ningún estudiante de la sección tiene
     * incidencia ese día en el bimestre (decisión del usuario, 30/09/2026). Con
     * incidencias, primero hay que quitarlas: así nunca se borra un dato de paso.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function deshacer(int $seccionId, int $periodoId, string $fecha): array
    {
        if ($this->incidenciasDelDia($seccionId, $periodoId, $fecha) > 0) {
            return ['ok' => false, 'mensaje' => 'Ese día tiene incidencias registradas: quítalas antes de deshacer la lista.'];
        }
        $this->execute("
            DELETE FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?
        ", [$seccionId, $periodoId, $fecha]);
        return ['ok' => true, 'mensaje' => 'Lista deshecha.'];
    }

    /** Incidencias de CUALQUIER matrícula de la sección ese día y bimestre. */
    public function incidenciasDelDia(int $seccionId, int $periodoId, string $fecha): int
    {
        return (int) ($this->queryOne("
            SELECT COUNT(*) AS n
            FROM asistencia_incidencias x
            INNER JOIN matriculas m ON m.id = x.matricula_id
            -- Quien CURSÓ el bimestre en la sección (cambio de sección, 07/10/2026).
            WHERE " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $seccionId, $periodoId) . "
              AND x.periodo_id = ? AND x.fecha = ?
        ", [$periodoId, $fecha])['n'] ?? 0);
    }

    /**
     * Días MARCABLES del bimestre (hasta hoy, sin no lectivos) que la sección aún
     * no tiene tomados. El bloqueo del auxiliar/RA exige que esté vacío.
     *
     * @return list<string>
     */
    public function diasSinTomar(int $seccionId, array $periodo, ?string $hoy = null): array
    {
        $tomadas = $this->tomadasDe($seccionId, (int) $periodo['id']);
        return array_values(array_filter(
            AsistenciaModel::diasMarcables($periodo, $hoy, $this->noLectivos()),
            static fn(string $f): bool => !isset($tomadas[$f])
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
     * que quitarla antes: no se borran datos de paso). Las listas de ese día se
     * borran en la misma transacción: sin incidencias no llevan ningún dato.
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
