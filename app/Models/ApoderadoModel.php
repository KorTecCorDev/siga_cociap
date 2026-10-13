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

    /**
     * Persona registrada con ese DNI, sea o no apoderado (puede ser docente,
     * estudiante…). Para que el alta de un apoderado «nuevo» no reutilice en
     * silencio a otra persona.
     */
    public function personaPorDni(string $dni): ?array
    {
        return $this->queryOne("
            SELECT
                p.id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo
            FROM personas p
            WHERE p.dni = ?
            LIMIT 1
        ", [$dni]);
    }

    /**
     * Nombres de los estudiantes vinculados al apoderado, sin contar a uno dado
     * (el de la matrícula en curso). Base de la confirmación al vincular.
     */
    public function otrosEstudiantesVinculados(int $apoderadoId, int $exceptoEstudianteId): array
    {
        return array_column($this->query("
            SELECT CONCAT(pe.apellido_paterno,' ',pe.apellido_materno,', ',pe.nombres) AS estudiante_nombre
            FROM vinculo_familiar vf
            INNER JOIN estudiantes e  ON e.id = vf.estudiante_id
            INNER JOIN personas    pe ON pe.id = e.persona_id
            WHERE vf.apoderado_id = ?
              AND vf.estudiante_id <> ?
            GROUP BY vf.estudiante_id, pe.apellido_paterno, pe.apellido_materno, pe.nombres
            ORDER BY " . orden_alfabetico('pe') . "
        ", [$apoderadoId, $exceptoEstudianteId]), 'estudiante_nombre');
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
                // Solo COMPLETA teléfono/correo vacíos; nunca reemplaza un dato ya
                // registrado (12/10/2026: un DNI equivocado pisó el contacto de otro
                // apoderado). Ver docs/modulos/matriculas.md.
                $this->execute(
                    "UPDATE personas SET telefono = COALESCE(NULLIF(telefono, ''), ?, telefono),
                                         correo   = COALESCE(NULLIF(correo, ''), ?, correo)
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
     * ese tipo para el estudiante, reasigna el apoderado.
     *
     * La responsabilidad NUNCA se pierde por revincular (GREATEST): antes, volver
     * a vincular al responsable con la casilla desmarcada lo dejaba en 0 y el
     * estudiante quedaba sin responsable. Marcarla la TRASLADA (un solo
     * responsable por estudiante) vía `asignarResponsable`.
     * Devuelve el id del vínculo.
     */
    public function vincularEstudiante(
        int $apoderadoId,
        int $estudianteId,
        string $tipoVinculo,
        bool $esResponsable
    ): int {
        $this->beginTransaction();
        try {
            $this->execute("
                INSERT INTO vinculo_familiar
                    (estudiante_id, apoderado_id, tipo_vinculo, es_responsable)
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                    apoderado_id   = VALUES(apoderado_id),
                    es_responsable = GREATEST(es_responsable, VALUES(es_responsable))
            ", [$estudianteId, $apoderadoId, $tipoVinculo]);

            $vinculoId = (int) ($this->queryOne(
                "SELECT id FROM vinculo_familiar WHERE estudiante_id = ? AND tipo_vinculo = ? LIMIT 1",
                [$estudianteId, $tipoVinculo]
            )['id'] ?? 0);

            if ($esResponsable && $vinculoId) {
                $this->asignarResponsable($estudianteId, $vinculoId);
            }
            $this->commit();
            return $vinculoId;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Un vínculo de ESE estudiante, con los datos de la persona y si tiene cuenta
     * de acceso (su usuario es el DNI). Null si el vínculo no es del estudiante.
     */
    public function getVinculo(int $vinculoId, int $estudianteId): ?array
    {
        return $this->queryOne("
            SELECT
                vf.id AS vinculo_id,
                vf.tipo_vinculo,
                vf.es_responsable,
                a.id AS apoderado_id,
                p.id AS persona_id,
                p.dni,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                p.telefono,
                p.correo,
                CONCAT(p.apellido_paterno,' ',p.apellido_materno,', ',p.nombres) AS nombre_completo,
                EXISTS (SELECT 1 FROM usuarios u WHERE u.persona_id = p.id) AS tiene_cuenta
            FROM vinculo_familiar vf
            INNER JOIN apoderados a ON a.id = vf.apoderado_id
            INNER JOIN personas p   ON p.id = a.persona_id
            WHERE vf.id = ? AND vf.estudiante_id = ?
            LIMIT 1
        ", [$vinculoId, $estudianteId]);
    }

    /**
     * Quita un vínculo ADICIONAL del estudiante. Jamás el del responsable: lo
     * garantiza el propio WHERE, no solo la vista. La persona y sus vínculos con
     * otros estudiantes no se tocan. Devuelve true si borró.
     */
    public function quitarVinculo(int $vinculoId, int $estudianteId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM vinculo_familiar
            WHERE id = ? AND estudiante_id = ? AND es_responsable = 0
        ");
        $stmt->execute([$vinculoId, $estudianteId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Traslada la responsabilidad de matrícula al vínculo dado: queda UN solo
     * responsable por estudiante (los demás pasan a adicionales).
     */
    public function asignarResponsable(int $estudianteId, int $vinculoId): bool
    {
        // Sin un vínculo válido de ESE estudiante no se toca nada (si no, el
        // UPDATE dejaría a todos sus vínculos sin responsable).
        $existe = $this->queryOne(
            "SELECT id FROM vinculo_familiar WHERE id = ? AND estudiante_id = ? LIMIT 1",
            [$vinculoId, $estudianteId]
        );
        if (!$existe) {
            return false;
        }
        return $this->execute(
            "UPDATE vinculo_familiar SET es_responsable = (id = ?) WHERE estudiante_id = ?",
            [$vinculoId, $estudianteId]
        );
    }

    /** Cambia el tipo de un vínculo del estudiante (el UNIQUE por tipo lo valida el controlador). */
    public function cambiarTipoVinculo(int $vinculoId, int $estudianteId, string $tipo): bool
    {
        return $this->execute(
            "UPDATE vinculo_familiar SET tipo_vinculo = ? WHERE id = ? AND estudiante_id = ?",
            [$tipo, $vinculoId, $estudianteId]
        );
    }

    /**
     * Actualiza identidad y contacto de la persona del apoderado. La ficha es
     * COMPARTIDA: el cambio llega a todos sus estudiantes (y a su cuenta, si la
     * tiene; por eso el controlador no deja cambiar el DNI de quien tiene cuenta).
     */
    public function actualizarPersona(int $personaId, array $d): bool
    {
        return $this->execute("
            UPDATE personas
               SET dni = ?, apellido_paterno = ?, apellido_materno = ?,
                   nombres = ?, telefono = ?, correo = ?
             WHERE id = ?
        ", [
            $d['dni'],
            $d['apellido_paterno'],
            $d['apellido_materno'],
            $d['nombres'],
            $d['telefono'],
            $d['correo'],
            $personaId,
        ]);
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
                vf.id AS vinculo_id,
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
