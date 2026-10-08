<?php

namespace App\Models;

/**
 * TrasladoModel
 * Constancias de traslado de salida (libro oficial del COCIAP).
 *
 * Numeración: correlativo por año académico, formato N° 000-{AÑO}-CAVVG-DA.
 * El número es ÚNICO solo entre constancias 'vigente' del año; una 'anulado'
 * LIBERA su número para reutilizarse. Por eso la unicidad se valida aquí, no
 * con un UNIQUE de BD (ver migración 016).
 */
class TrasladoModel extends BaseModel
{
    protected string $table = 'traslados';

    /** Catálogo de motivos: valor BD => etiqueta mostrada. */
    public const MOTIVOS = [
        'cambio_domicilio' => 'Cambio de domicilio',
        'economico'        => 'Motivos económicos',
        'familiar'         => 'Motivos familiares',
        'salud'            => 'Motivos de salud',
        'otro'             => 'Otros',
    ];

    // ── Numeración ───────────────────────────────────────────────

    /** Config anual: lema oficial y correlativo inicial del año. */
    public function getConfigAnio(int $anioId): ?array
    {
        return $this->queryOne(
            "SELECT id, anio, lema_oficial, correlativo_traslado_inicial
             FROM anios_academicos WHERE id = ? LIMIT 1",
            [$anioId]
        );
    }

    /**
     * Correlativo sugerido: el SIGUIENTE al último emitido en el año
     * (MAX(correlativo) + 1), para continuar la secuencia oficial. Considera
     * todas las constancias (vigentes y anuladas), porque una anulada igual fue
     * "emitida". No baja del correlativo inicial configurado para el año.
     *
     * Los números liberados por anulación NO se autosugieren, pero siguen
     * disponibles para reuso manual (lo permite correlativoDisponible(), que
     * solo bloquea números en uso por constancias 'vigentes').
     *
     * NUMERACIÓN COMPARTIDA (07/10/2026, migración 075): las R.D. de cambio de
     * sección usan la MISMA secuencia, así que el máximo se toma de las dos
     * tablas (incluidos los cambios revertidos, que también fueron emitidos).
     */
    public function siguienteCorrelativo(int $anioId, int $inicial): int
    {
        $r = $this->queryOne(
            "SELECT GREATEST(
                        COALESCE((SELECT MAX(correlativo)    FROM traslados       WHERE anio_id = ?), 0),
                        COALESCE((SELECT MAX(rd_correlativo) FROM cambios_seccion WHERE anio_id = ?), 0)
                    ) AS maxc",
            [$anioId, $anioId]
        );
        $max = (int) ($r['maxc'] ?? 0);
        return max($max + 1, max(1, $inicial));
    }

    /**
     * ¿El correlativo está libre en el año? PUNTO ÚNICO de la numeración
     * COMPARTIDA de R.D. (07/10/2026, migración 075): está ocupado si lo usa una
     * constancia de traslado 'vigente' O un cambio de sección 'vigente'. Una
     * constancia anulada o un cambio revertido LIBERAN su número.
     * (`$exceptoId` excluye una constancia de traslado, p.ej. al re-editar.)
     */
    public function correlativoDisponible(int $anioId, int $correlativo, ?int $exceptoId = null): bool
    {
        $sql = "SELECT id FROM traslados
                WHERE anio_id = ? AND correlativo = ? AND estado = 'vigente'";
        $params = [$anioId, $correlativo];
        if ($exceptoId !== null) {
            $sql .= " AND id <> ?";
            $params[] = $exceptoId;
        }
        $sql .= " LIMIT 1";
        if ($this->queryOne($sql, $params) !== null) {
            return false;
        }
        return $this->queryOne(
            "SELECT id FROM cambios_seccion
             WHERE anio_id = ? AND rd_correlativo = ? AND estado = 'vigente'
             LIMIT 1",
            [$anioId, $correlativo]
        ) === null;
    }

    /** Formatea el número oficial: N° 003-2026-CAVVG-DA */
    public static function formatearNumero(int $correlativo, int $anio, string $sufijo): string
    {
        return sprintf('N° %03d-%d-%s', $correlativo, $anio, $sufijo);
    }

    // ── Altas / consultas ────────────────────────────────────────

    /** ¿La matrícula ya tiene una constancia vigente? */
    public function getVigentePorMatricula(int $matriculaId): ?array
    {
        return $this->queryOne(
            "SELECT * FROM traslados
             WHERE matricula_id = ? AND estado = 'vigente'
             ORDER BY id DESC LIMIT 1",
            [$matriculaId]
        );
    }

    /** Última constancia (de cualquier estado) de una matrícula. */
    public function getUltimaPorMatricula(int $matriculaId): ?array
    {
        return $this->queryOne(
            "SELECT * FROM traslados
             WHERE matricula_id = ?
             ORDER BY id DESC LIMIT 1",
            [$matriculaId]
        );
    }

    /** Constancia con datos del estudiante, grado, nivel y año (para imprimir). */
    public function getDetalle(int $id): ?array
    {
        return $this->queryOne("
            SELECT
                t.*,
                a.anio,
                a.lema_oficial,
                p.dni,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS estudiante_nombre,
                g.nombre_display AS grado_nombre,
                n.nombre         AS nivel_nombre,
                s.nombre         AS seccion_nombre,
                pe.nombre_display AS periodo_nombre,
                CONCAT(pg.apellido_paterno,' ',pg.nombres) AS generada_por_nombre
            FROM traslados t
            INNER JOIN matriculas m       ON m.id = t.matricula_id
            INNER JOIN estudiantes e      ON e.id = m.estudiante_id
            INNER JOIN personas p         ON p.id = e.persona_id
            INNER JOIN anios_academicos a ON a.id = t.anio_id
            LEFT  JOIN secciones s        ON s.id = m.seccion_id
            LEFT  JOIN grados g           ON g.id = s.grado_id
            LEFT  JOIN niveles n          ON n.id = g.nivel_id
            LEFT  JOIN periodos pe        ON pe.id = t.periodo_id
            LEFT  JOIN usuarios ug        ON ug.id = t.generada_por
            LEFT  JOIN personas pg        ON pg.id = ug.persona_id
            WHERE t.id = ?
            LIMIT 1
        ", [$id]);
    }

    /** Listado del registro oficial, filtrable por año y estado. */
    public function listar(?int $anioId = null, ?string $estado = null): array
    {
        $cond   = ['1 = 1'];
        $params = [];
        if ($anioId) {
            $cond[]   = 't.anio_id = ?';
            $params[] = $anioId;
        }
        if ($estado) {
            $cond[]   = 't.estado = ?';
            $params[] = $estado;
        }
        $where = implode(' AND ', $cond);

        return $this->query("
            SELECT
                t.id, t.numero_constancia, t.correlativo, t.fecha_constancia,
                t.ie_destino_nombre, t.estado, t.matricula_id, t.veces_impresa,
                a.anio,
                p.dni,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS estudiante_nombre,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre
            FROM traslados t
            INNER JOIN matriculas m       ON m.id = t.matricula_id
            INNER JOIN estudiantes e      ON e.id = m.estudiante_id
            INNER JOIN personas p         ON p.id = e.persona_id
            INNER JOIN anios_academicos a ON a.id = t.anio_id
            LEFT  JOIN secciones s        ON s.id = m.seccion_id
            LEFT  JOIN grados g           ON g.id = s.grado_id
            WHERE {$where}
            ORDER BY t.anio_id DESC, t.correlativo ASC
        ", $params);
    }

    /** Marca una constancia como anulada (libera su número). */
    public function anular(int $id, string $motivo, int $usuarioId): bool
    {
        return $this->execute(
            "UPDATE traslados
             SET estado = 'anulado', anulado_motivo = ?, anulado_en = NOW(), anulado_por = ?
             WHERE id = ? AND estado = 'vigente'",
            [$motivo, $usuarioId, $id]
        );
    }

    /** Incrementa el contador de impresiones. */
    public function registrarImpresion(int $id): void
    {
        $this->execute(
            "UPDATE traslados SET veces_impresa = veces_impresa + 1 WHERE id = ?",
            [$id]
        );
    }
}
