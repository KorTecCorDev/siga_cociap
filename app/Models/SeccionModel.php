<?php

namespace App\Models;

class SeccionModel extends BaseModel
{
    protected string $table = 'secciones';

    /**
     * Secciones de un año con su grado, nivel y tutor ACTUAL (23/09/2026).
     *
     * Alimenta el filtro por sección del informe de estudiantes en riesgo y el
     * bloque por tutor (lote de Dirección y panel del tutor). `secciones.tutor_id`
     * es el tutor de HOY: no hay historial, así que un informe de un bimestre
     * anterior nombra al tutor actual (los encabezados lo dicen).
     *
     * @return array<int, array{id:int, grado_id:int, nombre:string, grado_numero:int,
     *         grado_nombre:string, nivel_id:int, nivel_nombre:string, nivel_codigo:string,
     *         tutor_id:?int, tutor_nombre:?string}>
     */
    public function seccionesDelAnio(int $anioId): array
    {
        $filas = $this->query("
            SELECT s.id, s.grado_id, s.nombre,
                   g.numero         AS grado_numero,
                   g.nombre_display AS grado_nombre,
                   n.id             AS nivel_id,
                   n.nombre         AS nivel_nombre,
                   n.codigo         AS nivel_codigo,
                   s.tutor_id,
                   p.apellido_paterno, p.apellido_materno, p.nombres
            FROM secciones s
            INNER JOIN grados g   ON g.id = s.grado_id
            INNER JOIN niveles n  ON n.id = g.nivel_id
            LEFT  JOIN usuarios u ON u.id = s.tutor_id
            LEFT  JOIN personas p ON p.id = u.persona_id
            WHERE s.anio_id = ?
            ORDER BY n.id, g.numero, s.nombre
        ", [$anioId]);

        return array_map(static fn(array $r): array => [
            'id'           => (int) $r['id'],
            'grado_id'     => (int) $r['grado_id'],
            'nombre'       => (string) $r['nombre'],
            'grado_numero' => (int) $r['grado_numero'],
            'grado_nombre' => (string) $r['grado_nombre'],
            'nivel_id'     => (int) $r['nivel_id'],
            'nivel_nombre' => (string) $r['nivel_nombre'],
            'nivel_codigo' => (string) $r['nivel_codigo'],
            'tutor_id'     => $r['tutor_id'] !== null ? (int) $r['tutor_id'] : null,
            'tutor_nombre' => !empty($r['apellido_paterno'])
                ? trim($r['apellido_paterno'] . ' ' . $r['apellido_materno'] . ', ' . $r['nombres'])
                : null,
        ], $filas);
    }

    public function listarConTutor(): array
    {
        return $this->query("
            SELECT
                s.id,
                s.nombre          AS seccion_nombre,
                s.es_unidocente,
                s.estado_nomina,
                s.tutor_id,
                g.nombre_display  AS grado_nombre,
                g.numero          AS grado_numero,
                n.nombre          AS nivel_nombre,
                n.id              AS nivel_id,
                a.anio,
                p.apellido_paterno AS tutor_apellido_paterno,
                p.apellido_materno AS tutor_apellido_materno,
                p.nombres          AS tutor_nombres,
                p.dni              AS tutor_dni,
                COUNT(m.id)        AS total_matriculados
            FROM secciones s
            INNER JOIN grados g           ON g.id  = s.grado_id
            INNER JOIN niveles n          ON n.id  = g.nivel_id
            INNER JOIN anios_academicos a ON a.id  = s.anio_id
            LEFT  JOIN usuarios u         ON u.id  = s.tutor_id
            LEFT  JOIN personas p         ON p.id  = u.persona_id
            LEFT  JOIN matriculas m       ON m.seccion_id = s.id AND m.estado = 'aprobada'
            WHERE a.estado IN ('planificado', 'activo')
            GROUP BY
                s.id, s.nombre, s.es_unidocente, s.estado_nomina, s.tutor_id,
                g.nombre_display, g.numero, n.nombre, n.id, a.anio,
                p.apellido_paterno, p.apellido_materno, p.nombres, p.dni
            ORDER BY a.anio DESC, n.id, g.numero, s.nombre
        ");
    }

    public function listarDocentes(): array
    {
        return $this->query("
            SELECT
                u.id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.dni,
                (
                    SELECT s.id FROM secciones s
                    INNER JOIN anios_academicos a ON a.id = s.anio_id
                    WHERE s.tutor_id = u.id
                      AND a.estado IN ('planificado', 'activo')
                    LIMIT 1
                ) AS tutor_seccion_id
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN roles r    ON r.id = u.rol_id
            WHERE r.codigo = 'docente'
              AND u.estado = 'activo'
            ORDER BY " . orden_alfabetico('p') . "
        ");
    }

    /**
     * Asigna (o quita) el tutor de una sección.
     *
     * Desde la migración 019 las transversales (TIC/GAMA) las registra cada
     * docente en su propia carga y el tutor solo agrega conclusiones y cierra
     * el bimestre desde /docente/tutoria — por eso YA NO se crea ni reactiva
     * la carga transversal del tutor. Si quedara una activa de un flujo
     * anterior, se desactiva aquí para mantener el invariante.
     */
    public function asignarTutor(int $seccionId, ?int $tutorId): void
    {
        $this->beginTransaction();
        try {
            $seccion = $this->queryOne("
                SELECT s.id, s.anio_id, g.nivel_id
                FROM secciones s
                INNER JOIN grados g ON g.id = s.grado_id
                WHERE s.id = ?
            ", [$seccionId]);

            if (!$seccion) {
                throw new \RuntimeException('Sección no encontrada.');
            }

            $this->execute(
                "UPDATE secciones SET tutor_id = ? WHERE id = ?",
                [$tutorId, $seccionId]
            );

            // Garantiza que ninguna carga transversal de tutor quede activa.
            $this->execute("
                UPDATE cargas_academicas ca
                INNER JOIN areas a ON a.id = ca.area_id AND a.tipo = 'transversal'
                SET ca.estado = 'inactiva'
                WHERE ca.seccion_id = ?
                  AND ca.anio_id    = ?
                  AND ca.estado     = 'activa'
            ", [$seccionId, (int) $seccion['anio_id']]);

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}
