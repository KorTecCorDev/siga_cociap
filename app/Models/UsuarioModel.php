<?php

namespace App\Models;

/**
 * UsuarioModel
 * Gestión de usuarios del sistema SIGA-COCIAP.
 */
class UsuarioModel extends BaseModel
{
    protected string $table = 'usuarios';

    /**
     * Busca un usuario por el DNI de su persona asociada.
     * Retorna todos los datos necesarios para la sesión.
     */
    public function findByDni(string $dni): ?array
    {
        return $this->queryOne("
            SELECT
                u.id,
                u.password_hash,
                u.estado,
                u.sesion_token,
                p.id        AS persona_id,
                p.dni,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.correo,
                p.sexo,
                r.id        AS rol_id,
                r.nombre    AS rol_nombre,
                r.codigo    AS rol_codigo
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN roles r    ON r.id = u.rol_id
            WHERE p.dni = ?
            LIMIT 1
        ", [$dni]);
    }

    /**
     * Registra el token de sesión activa y el último acceso.
     * Garantiza sesión única: si otro dispositivo inicia sesión,
     * el token del anterior queda inválido.
     */
    public function registrarAcceso(int $id, string $token): void
    {
        $this->execute("
            UPDATE usuarios
            SET sesion_token = ?,
                ultimo_acceso = NOW()
            WHERE id = ?
        ", [$token, $id]);
    }

    /**
     * Invalida el token de sesión al hacer logout.
     */
    public function cerrarSesion(int $id): void
    {
        $this->execute("
            UPDATE usuarios
            SET sesion_token = NULL
            WHERE id = ?
        ", [$id]);
    }

    /**
     * Verifica que el token guardado en sesión coincida con el de la BD.
     * Protege contra sesiones duplicadas.
     */
    public function tokenValido(int $id, string $token): bool
    {
        $resultado = $this->queryOne("
            SELECT sesion_token FROM usuarios WHERE id = ? LIMIT 1
        ", [$id]);

        return $resultado && hash_equals($resultado['sesion_token'] ?? '', $token);
    }

    /**
     * Cambia la contraseña de un usuario (solo Administrador / Registro Académico).
     */
    public function cambiarPassword(int $id, string $nuevaPassword): bool
    {
        $hash = password_hash($nuevaPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        return $this->update($id, ['password_hash' => $hash]);
    }

    /**
     * Lista todos los usuarios con datos de persona y rol.
     */
    public function listarTodos(): array
    {
        return $this->query("
            SELECT
                u.id,
                u.estado,
                u.ultimo_acceso,
                p.dni,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.correo,
                p.telefono,
                r.nombre AS rol_nombre,
                r.codigo AS rol_codigo
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN roles r    ON r.id = u.rol_id
            ORDER BY r.id, " . orden_alfabetico('p', 2) . "
        ");
    }

    /**
     * Lo que cada usuario dicta y tutorea en el año ACTIVO, para el buscador de
     * /admin/usuarios. El tutor es el de HOY (`secciones.tutor_id`): sirve para
     * ubicar a la persona, no para documentos. La carga TOE no se lista: la
     * cubre `tutor`. `aula` = secciones unidocentes de las que es tutor (REGLA
     * es_aula: es_unidocente Y tutor_id = docente).
     *
     * @return array<int, array{cursos: array<string, string[]>, tutor: string[], aula: string[]}>
     */
    public function contextoAcademico(): array
    {
        $contexto = [];
        $vacio    = ['cursos' => [], 'tutor' => [], 'aula' => []];

        $cargas = $this->query("
            SELECT ca.docente_id AS usuario_id,
                   COALESCE(sa.nombre, a.nombre) AS curso,
                   n.nombre AS nivel, g.nombre_display AS grado, s.nombre AS seccion
            FROM cargas_academicas ca
            INNER JOIN anios_academicos an ON an.id = ca.anio_id AND an.estado = 'activo'
            INNER JOIN secciones s         ON s.id  = ca.seccion_id
            INNER JOIN grados g            ON g.id  = s.grado_id
            INNER JOIN niveles n           ON n.id  = g.nivel_id
            LEFT JOIN subareas sa          ON sa.id = ca.subarea_id
            LEFT JOIN areas a              ON a.id  = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.estado = 'activa'
              AND (a.tipo IS NULL OR a.tipo <> 'tutoria')
            ORDER BY g.nivel_id, g.numero, s.nombre
        ");
        foreach ($cargas as $c) {
            if ($c['curso'] === null) {
                continue;
            }
            $id = (int) $c['usuario_id'];
            $contexto[$id] ??= $vacio;
            $etiqueta  = self::etiquetaSeccion($c['nivel'], $c['grado'], $c['seccion']);
            $secciones = &$contexto[$id]['cursos'][$c['curso']];
            $secciones ??= [];
            if (!in_array($etiqueta, $secciones, true)) {
                $secciones[] = $etiqueta;
            }
            unset($secciones);
        }

        $tutorias = $this->query("
            SELECT s.tutor_id AS usuario_id,
                   n.nombre AS nivel, g.nombre_display AS grado, s.nombre AS seccion,
                   s.es_unidocente
            FROM secciones s
            INNER JOIN anios_academicos an ON an.id = s.anio_id AND an.estado = 'activo'
            INNER JOIN grados g            ON g.id  = s.grado_id
            INNER JOIN niveles n           ON n.id  = g.nivel_id
            WHERE s.tutor_id IS NOT NULL
            ORDER BY g.nivel_id, g.numero, s.nombre
        ");
        foreach ($tutorias as $t) {
            $id = (int) $t['usuario_id'];
            $contexto[$id] ??= $vacio;
            $contexto[$id][(int) $t['es_unidocente'] === 1 ? 'aula' : 'tutor'][]
                = self::etiquetaSeccion($t['nivel'], $t['grado'], $t['seccion']);
        }

        return $contexto;
    }

    /**
     * Sección con su nivel abreviado («Prim. 4° B»): el mismo grado y letra
     * existen en primaria y en secundaria.
     */
    public static function etiquetaSeccion(string $nivel, string $grado, string $seccion): string
    {
        $n       = mb_strtolower($nivel);
        $prefijo = match (true) {
            str_starts_with($n, 'prim') => 'Prim.',
            str_starts_with($n, 'sec')  => 'Sec.',
            default                     => $nivel,
        };
        return $prefijo . ' ' . $grado . ' ' . $seccion;
    }

    /**
     * Nombre completo formateado: APELLIDOS, Nombres
     */
    public static function nombreCompleto(array $usuario): string
    {
        return strtoupper($usuario['apellido_paterno'] . ' ' . $usuario['apellido_materno'])
            . ', ' . ucwords(strtolower($usuario['nombres']));
    }

    // ── Métodos para gestión CRUD (Admin) ────────────────────────

    public function findById(int $id): ?array
    {
        return $this->queryOne("
            SELECT
                u.id,
                u.rol_id,
                u.estado,
                u.ultimo_acceso,
                p.id               AS persona_id,
                p.dni,
                p.nombres,
                p.apellido_paterno,
                p.apellido_materno,
                p.correo,
                p.telefono,
                p.sexo,
                r.nombre           AS rol_nombre,
                r.codigo           AS rol_codigo
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN roles r    ON r.id = u.rol_id
            WHERE u.id = ?
            LIMIT 1
        ", [$id]);
    }

    public function listarRoles(): array
    {
        return $this->query("SELECT id, nombre, codigo FROM roles ORDER BY id");
    }

    public function existeDni(string $dni, ?int $excluirUsuarioId = null): bool
    {
        if ($excluirUsuarioId !== null) {
            $r = $this->queryOne("
                SELECT u.id FROM usuarios u
                INNER JOIN personas p ON p.id = u.persona_id
                WHERE p.dni = ? AND u.id != ?
                LIMIT 1
            ", [$dni, $excluirUsuarioId]);
        } else {
            $r = $this->queryOne("SELECT id FROM personas WHERE dni = ? LIMIT 1", [$dni]);
        }
        return $r !== null;
    }

    /**
     * Persona ya registrada con ese DNI, con lo que hace falta para decidir si
     * se le puede crear una cuenta (28/09/2026). NULL si el DNI no existe.
     *
     * `personas.dni` es único y el login es por DNI: una persona tiene como
     * máximo UNA cuenta. Una persona que ya existe (apoderado, estudiante…) y
     * aún no tiene cuenta se REUTILIZA; nunca se duplica.
     */
    public function personaPorDni(string $dni): ?array
    {
        return $this->queryOne("
            SELECT p.id, p.dni, p.apellido_paterno, p.apellido_materno, p.nombres,
                   p.sexo, p.correo, p.telefono,
                   u.id     AS usuario_id,
                   r.nombre AS usuario_rol,
                   EXISTS(SELECT 1 FROM apoderados  a WHERE a.persona_id = p.id) AS es_apoderado,
                   EXISTS(SELECT 1 FROM estudiantes e WHERE e.persona_id = p.id) AS es_estudiante
            FROM personas p
            LEFT JOIN usuarios u ON u.persona_id = p.id
            LEFT JOIN roles r    ON r.id = u.rol_id
            WHERE p.dni = ?
            LIMIT 1
        ", [$dni]);
    }

    /**
     * Crea la cuenta sobre una persona YA registrada (sin cuenta).
     *
     * Decisión del usuario (28/09/2026): valen los datos YA registrados. Solo se
     * COMPLETAN los que la persona no tiene (sexo, correo, teléfono); nunca se
     * sobrescribe lo que ya estaba — p. ej. el celular que la familia dio en la
     * matrícula. El UNIQUE de `usuarios.persona_id` impide dos cuentas.
     */
    public function crearSobrePersona(int $personaId, array $completar, string $password, int $rolId): int
    {
        // Transacción propia solo si el llamador no abrió una (conexión compartida,
        // PDO no anida): un verificador puede envolverla y revertir.
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $this->execute("
                UPDATE personas
                SET sexo     = COALESCE(sexo, ?),
                    correo   = COALESCE(NULLIF(correo, ''), ?),
                    telefono = COALESCE(NULLIF(telefono, ''), ?)
                WHERE id = ?
            ", [$completar['sexo'] ?? null, $completar['correo'] ?? null, $completar['telefono'] ?? null, $personaId]);

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->execute(
                "INSERT INTO usuarios (persona_id, rol_id, password_hash, estado) VALUES (?, ?, ?, 'activo')",
                [$personaId, $rolId, $hash]
            );
            $usuarioId = (int) $this->db->lastInsertId();

            if ($propia) {
                $this->commit();
            }
            return $usuarioId;
        } catch (\Exception $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }
    }

    public function crearConPersona(array $datosPersona, string $password, int $rolId): int
    {
        $this->beginTransaction();
        try {
            $cols  = implode(', ', array_keys($datosPersona));
            $ph    = implode(', ', array_fill(0, count($datosPersona), '?'));
            $this->execute("INSERT INTO personas ({$cols}) VALUES ({$ph})", array_values($datosPersona));
            $personaId = (int) $this->db->lastInsertId();

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->execute(
                "INSERT INTO usuarios (persona_id, rol_id, password_hash, estado) VALUES (?, ?, ?, 'activo')",
                [$personaId, $rolId, $hash]
            );
            $usuarioId = (int) $this->db->lastInsertId();

            $this->commit();
            return $usuarioId;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function actualizarConPersona(
        int $usuarioId,
        int $personaId,
        array $datosPersona,
        int $rolId,
        ?string $password
    ): void {
        $this->beginTransaction();
        try {
            $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($datosPersona)));
            $this->execute(
                "UPDATE personas SET {$set} WHERE id = ?",
                [...array_values($datosPersona), $personaId]
            );
            $this->execute("UPDATE usuarios SET rol_id = ? WHERE id = ?", [$rolId, $usuarioId]);

            if ($password !== null && $password !== '') {
                $this->cambiarPassword($usuarioId, $password);
            }

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function toggleEstado(int $id): void
    {
        $this->execute("
            UPDATE usuarios
            SET estado = IF(estado = 'activo', 'inactivo', 'activo')
            WHERE id = ?
        ", [$id]);
    }

    public function contarPorRolCodigo(string $codigoRol): int
    {
        $r = $this->queryOne("
            SELECT COUNT(*) AS total
            FROM usuarios u
            INNER JOIN roles r ON r.id = u.rol_id
            WHERE r.codigo = ? AND u.estado = 'activo'
        ", [$codigoRol]);
        return (int) ($r['total'] ?? 0);
    }
}
