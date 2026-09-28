<?php

namespace App\Models;

/**
 * ApoderadoModel
 * Gestión de apoderados y su vínculo familiar con los estudiantes.
 *
 * Tipos de vínculo disponibles (enum vinculo_familiar.tipo_vinculo):
 *   padre, madre, apoderado, apoderada, abuelo, abuela, tio, tia,
 *   padrino, madrina, hermano, hermana, primo, prima.
 * (Sin tildes en BD; la vista las muestra con tilde.)
 */
class ApoderadoModel extends BaseModel
{
    protected string $table = 'apoderados';

    /**
     * Busca un apoderado por el DNI de su persona.
     * Incluye la lista de estudiantes a los que está vinculado.
     */
    public function buscarPorDni(string $dni): ?array
    {
        $apoderado = $this->queryOne("
            SELECT
                a.id,
                a.persona_id,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.telefono,
                p.correo,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo
            FROM apoderados a
            INNER JOIN personas p ON p.id = a.persona_id
            WHERE p.dni = ?
            LIMIT 1
        ", [$dni]);

        if (!$apoderado) {
            return null;
        }

        $apoderado['vinculos'] = $this->query("
            SELECT
                vf.tipo_vinculo,
                vf.es_responsable,
                e.id AS estudiante_id,
                CONCAT(pe.apellido_paterno,' ',pe.apellido_materno,', ',pe.nombres) AS estudiante_nombre
            FROM vinculo_familiar vf
            INNER JOIN estudiantes e  ON e.id = vf.estudiante_id
            INNER JOIN personas    pe ON pe.id = e.persona_id
            WHERE vf.apoderado_id = ?
            ORDER BY " . orden_alfabetico('pe', 1) . "
        ", [(int) $apoderado['id']]);

        return $apoderado;
    }

    public function findById(int $id): ?array
    {
        return $this->queryOne("
            SELECT
                a.id,
                a.persona_id,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.telefono,
                p.correo,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo
            FROM apoderados a
            INNER JOIN personas p ON p.id = a.persona_id
            WHERE a.id = ?
            LIMIT 1
        ", [$id]);
    }

    /**
     * Crea un apoderado (persona + apoderado) en una transacción.
     * Si la persona ya existe por DNI la reutiliza; si ya es apoderado,
     * devuelve el apoderado existente. Retorna apoderado_id.
     */
    public function crear(array $datos): int
    {
        $this->beginTransaction();
        try {
            $persona = $this->queryOne(
                "SELECT id FROM personas WHERE dni = ? LIMIT 1",
                [$datos['dni']]
            );

            if ($persona) {
                $personaId = (int) $persona['id'];
                // Actualiza teléfono/correo si llegaron datos nuevos.
                $this->execute(
                    "UPDATE personas SET telefono = COALESCE(?, telefono),
                                         correo   = COALESCE(?, correo)
                     WHERE id = ?",
                    [$datos['telefono'] ?? null, $datos['correo'] ?? null, $personaId]
                );
            } else {
                $this->execute("
                    INSERT INTO personas
                        (dni, apellido_paterno, apellido_materno, nombres, telefono, correo)
                    VALUES (?, ?, ?, ?, ?, ?)
                ", [
                    $datos['dni'],
                    $datos['apellido_paterno'],
                    $datos['apellido_materno'],
                    $datos['nombres'],
                    $datos['telefono'] ?? null,
                    $datos['correo'] ?? null,
                ]);
                $personaId = (int) $this->db->lastInsertId();
            }

            $ya = $this->queryOne(
                "SELECT id FROM apoderados WHERE persona_id = ? LIMIT 1",
                [$personaId]
            );
            if ($ya) {
                $apoderadoId = (int) $ya['id'];
            } else {
                $this->execute(
                    "INSERT INTO apoderados (persona_id) VALUES (?)",
                    [$personaId]
                );
                $apoderadoId = (int) $this->db->lastInsertId();
            }

            $this->commit();
            return $apoderadoId;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Vincula un apoderado a un estudiante con un tipo de vínculo.
     * Idempotente por el UNIQUE (estudiante_id, tipo_vinculo): si ya existe
     * ese tipo para el estudiante, reasigna el apoderado y la responsabilidad.
     */
    public function vincularEstudiante(
        int $apoderadoId,
        int $estudianteId,
        string $tipoVinculo,
        bool $esResponsable
    ): bool {
        return $this->execute("
            INSERT INTO vinculo_familiar
                (estudiante_id, apoderado_id, tipo_vinculo, es_responsable)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                apoderado_id   = VALUES(apoderado_id),
                es_responsable = VALUES(es_responsable)
        ", [$estudianteId, $apoderadoId, $tipoVinculo, $esResponsable ? 1 : 0]);
    }

    /**
     * Estudiantes vinculados a un apoderado en un año académico, con grado,
     * sección, tutor y estado de matrícula.
     */
    public function getHijos(int $apoderadoId, int $anioId): array
    {
        return $this->query("
            SELECT
                e.id AS estudiante_id,
                vf.tipo_vinculo,
                vf.es_responsable,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo,
                m.id            AS matricula_id,
                m.estado        AS estado_matricula,
                g.nombre_display AS grado,
                s.nombre        AS seccion,
                CONCAT(tp.apellido_paterno,' ',tp.nombres) AS tutor
            FROM vinculo_familiar vf
            INNER JOIN estudiantes e ON e.id = vf.estudiante_id
            INNER JOIN personas p    ON p.id = e.persona_id
            LEFT  JOIN matriculas m  ON m.estudiante_id = e.id AND m.anio_id = ?
            LEFT  JOIN secciones s   ON s.id = m.seccion_id
            LEFT  JOIN grados g      ON g.id = s.grado_id
            LEFT  JOIN usuarios tu   ON tu.id = s.tutor_id
            LEFT  JOIN personas tp   ON tp.id = tu.persona_id
            WHERE vf.apoderado_id = ?
            ORDER BY " . orden_alfabetico('p', 1) . "
        ", [$anioId, $apoderadoId]);
    }

    /**
     * Cuenta estudiantes vinculados a un apoderado con matrícula vigente
     * (activa/aprobada/pendiente) en el año dado. Regla: máximo 3.
     */
    public function contarHijosActivos(int $apoderadoId, int $anioId): int
    {
        $r = $this->queryOne("
            SELECT COUNT(*) AS total
            FROM vinculo_familiar vf
            INNER JOIN matriculas m
                ON m.estudiante_id = vf.estudiante_id AND m.anio_id = ?
            WHERE vf.apoderado_id = ?
              AND m.estado IN ('aprobada','pendiente')
        ", [$anioId, $apoderadoId]);
        return (int) ($r['total'] ?? 0);
    }

    /** Todos los apoderados vinculados a un estudiante (con datos de persona). */
    public function getVinculos(int $estudianteId): array
    {
        return $this->query("
            SELECT
                vf.tipo_vinculo,
                vf.es_responsable,
                a.id AS apoderado_id,
                p.dni,
                p.telefono,
                p.correo,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo
            FROM vinculo_familiar vf
            INNER JOIN apoderados a ON a.id = vf.apoderado_id
            INNER JOIN personas p   ON p.id = a.persona_id
            WHERE vf.estudiante_id = ?
            ORDER BY vf.es_responsable DESC, vf.tipo_vinculo
        ", [$estudianteId]);
    }

    /**
     * Desactiva el usuario (login) del apoderado responsable del estudiante,
     * si tuviera cuenta. Usado al desactivar/trasladar una matrícula.
     * Retorna cuántas cuentas se desactivaron.
     *
     * 🔴 SOLO cuentas de rol `padre` (28/09/2026). Una misma persona puede ser
     * apoderado Y personal del colegio (docente, auxiliar…): `personas.dni` es
     * único y el login es por DNI, así que tiene UNA sola cuenta. Sin este filtro,
     * desactivar por deuda o trasladar a su hijo le apagaba la cuenta de trabajo
     * (había 3 docentes apoderados expuestos). Ver docs/modulos/matriculas.md.
     */
    public function desactivarUsuarioDeEstudiante(int $estudianteId): int
    {
        $this->execute("
            UPDATE usuarios u
            INNER JOIN roles r            ON r.id = u.rol_id AND r.codigo = 'padre'
            INNER JOIN apoderados a       ON a.persona_id = u.persona_id
            INNER JOIN vinculo_familiar vf ON vf.apoderado_id = a.id
            SET u.estado = 'inactivo'
            WHERE vf.estudiante_id = ?
        ", [$estudianteId]);
        return 1;
    }
}
