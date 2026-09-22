<?php

namespace App\Models;

/**
 * NotificacionModel
 *
 * Bandeja interna por usuario. Dos orígenes:
 *   - 'sistema'    → las genera un evento de la aplicación (hoy: llegan notas
 *                    del colegio de origen de un estudiante de tu sección).
 *   - 'comunicado' → las redacta admin / registro académico.
 *
 * ⚠️ NO es la tabla `alertas`. Aquella va TUTOR → PADRE (lleva `tutor_id` y
 * `matricula_id`, y solo la lee /padre/alertas); nadie le escribe y tiene 0
 * filas. Otra dirección, otro público: se deja intacta. Ver migración 058.
 *
 * Los TIPOS son una lista cerrada EN PHP, no un ENUM en base de datos: añadir
 * uno nuevo no debe exigir una migración.
 */
class NotificacionModel extends BaseModel
{
    protected string $table = 'notificaciones';

    /** Notas que el estudiante trae de su colegio de origen. */
    public const TIPO_NOTAS_ORIGEN = 'notas_origen';
    /** Mensaje redactado por admin / registro académico. */
    public const TIPO_COMUNICADO   = 'comunicado';

    public const TIPOS = [
        self::TIPO_NOTAS_ORIGEN => 'Notas del colegio de origen',
        self::TIPO_COMUNICADO   => 'Comunicado',
    ];

    /**
     * Roles que RECIBEN notificaciones. Los de dirección (16/09/2026) reciben
     * COMUNICADOS y tienen campana, pero no avisos automáticos: no tienen
     * cargas. Siguen siendo de solo lectura; la única escritura que hacen es
     * marcar leídas las de SU bandeja (estado personal, no dato académico).
     */
    public const ROLES_RECEPTORES = ['docente', 'registro_academico', 'admin', ...ROLES_DIRECCION];

    /**
     * Roles que pueden REDACTAR comunicados. Deliberadamente NO incluye a los
     * tres de ROLES_DIRECCION: son SOLO LECTURA en todo el sistema.
     */
    public const ROLES_EMISORES = ['admin', 'registro_academico'];

    /**
     * Destinos de un comunicado. Se pueden COMBINAR: los destinatarios se unen,
     * se deduplican por usuario y se excluye a quien envía. La clave es lo que
     * se guarda en `comunicados.destinos` (migración 060).
     */
    public const DESTINO_DOCENTES       = 'docentes';
    public const DESTINO_SECCION        = 'seccion';
    public const DESTINO_DIRECCION      = 'direccion';
    public const DESTINO_ADMINISTRATIVO = 'administrativo';

    public const DESTINOS = [
        self::DESTINO_DOCENTES       => 'Todos los docentes',
        self::DESTINO_SECCION        => 'Docentes de una sección',
        self::DESTINO_DIRECCION      => 'Dirección',
        self::DESTINO_ADMINISTRATIVO => 'Personal administrativo',
    ];

    /** Roles del destino «Personal administrativo». */
    public const ROLES_ADMINISTRATIVOS = ['registro_academico', 'admin'];

    /** Alta de una notificación suelta. Devuelve el id creado. */
    public function crear(array $data): int
    {
        $this->execute("
            INSERT INTO notificaciones
                (usuario_id, tipo, origen, titulo, mensaje, enlace, emisor_id, comunicado_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            (int) $data['usuario_id'],
            (string) $data['tipo'],
            (string) ($data['origen'] ?? 'sistema'),
            (string) $data['titulo'],
            (string) $data['mensaje'],
            $data['enlace']    ?? null,
            isset($data['emisor_id']) ? (int) $data['emisor_id'] : null,
            isset($data['comunicado_id']) ? (int) $data['comunicado_id'] : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Notifica a TODOS los docentes con carga activa en la sección de una
     * matrícula. Un solo aviso por docente aunque tenga varias cargas allí.
     *
     * AVISOS REPETIDOS (16/09/2026): si el docente ya tiene un aviso SIN LEER
     * del mismo tipo y enlace —p. ej. RA vuelve a guardar para corregir—, se
     * REFRESCA ese aviso (texto y fecha) en vez de crear otro. Si ya lo leyó,
     * se crea uno nuevo: es información nueva para él.
     *
     * ⚠️ `cargas_academicas.docente_id` apunta DIRECTO a `usuarios.id` (no hay
     * tabla `docentes`); comprobado antes de escribir esta consulta.
     *
     * @return int Cuántos docentes quedaron notificados (nuevos o refrescados).
     */
    public function crearParaDocentesDeSeccion(
        int $matriculaId,
        string $tipo,
        string $titulo,
        string $mensaje,
        ?string $enlace = null,
        ?int $emisorId = null
    ): int {
        $docentes = $this->query("
            SELECT DISTINCT ca.docente_id
            FROM matriculas m
            INNER JOIN cargas_academicas ca
                    ON ca.seccion_id = m.seccion_id
                   AND ca.anio_id    = m.anio_id
                   AND ca.estado     = 'activa'
            INNER JOIN usuarios u
                    ON u.id     = ca.docente_id
                   AND u.estado = 'activo'
            WHERE m.id = ?
        ", [$matriculaId]);

        foreach ($docentes as $d) {
            $pendiente = $this->queryOne("
                SELECT id
                FROM notificaciones
                WHERE usuario_id = ? AND tipo = ? AND enlace <=> ? AND leida_en IS NULL
                ORDER BY id DESC
                LIMIT 1
            ", [(int) $d['docente_id'], $tipo, $enlace]);

            if ($pendiente !== null) {
                $this->execute("
                    UPDATE notificaciones
                    SET titulo = ?, mensaje = ?, created_at = NOW()
                    WHERE id = ?
                ", [$titulo, $mensaje, (int) $pendiente['id']]);
                continue;
            }

            $this->crear([
                'usuario_id' => (int) $d['docente_id'],
                'tipo'       => $tipo,
                'origen'     => $emisorId === null ? 'sistema' : 'comunicado',
                'titulo'     => $titulo,
                'mensaje'    => $mensaje,
                'enlace'     => $enlace,
                'emisor_id'  => $emisorId,
            ]);
        }

        return count($docentes);
    }

    /** Usuarios de un rol (para dirigir un comunicado a todo un rol). */
    public function usuariosDeRol(string $rolCodigo): array
    {
        return $this->usuariosDeRoles([$rolCodigo]);
    }

    /** Usuarios activos de cualquiera de los roles dados. */
    public function usuariosDeRoles(array $rolCodigos): array
    {
        if ($rolCodigos === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($rolCodigos), '?'));

        return $this->query("
            SELECT u.id
            FROM usuarios u
            INNER JOIN roles r ON r.id = u.rol_id
            WHERE r.codigo IN ($marcas) AND u.estado = 'activo'
        ", array_values($rolCodigos));
    }

    /**
     * Docentes ACTIVOS con carga activa en una sección del año activo. El
     * filtro por `usuarios.estado` (22/09/2026) faltaba aquí, aunque los otros
     * destinos y crearParaDocentesDeSeccion() sí lo aplicaban.
     */
    public function docentesDeSeccion(int $seccionId): array
    {
        return $this->query("
            SELECT DISTINCT ca.docente_id AS id
            FROM cargas_academicas ca
            INNER JOIN anios_academicos aa ON aa.id = ca.anio_id AND aa.estado = 'activo'
            INNER JOIN usuarios u          ON u.id = ca.docente_id AND u.estado = 'activo'
            WHERE ca.seccion_id = ? AND ca.estado = 'activa'
        ", [$seccionId]);
    }

    /**
     * Secciones del año ACTIVO: las mismas que puede resolver
     * docentesDeSeccion(). Ofrecer las de un año planificado daba siempre
     * «No hay destinatarios».
     */
    public function seccionesParaComunicado(): array
    {
        return $this->query("
            SELECT s.id, s.nombre AS seccion_nombre,
                   g.nombre_display AS grado_nombre, n.nombre AS nivel_nombre
            FROM secciones s
            INNER JOIN anios_academicos aa ON aa.id = s.anio_id AND aa.estado = 'activo'
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            ORDER BY n.id, g.numero, s.nombre
        ");
    }

    /**
     * Destinatarios de un comunicado: une los destinos elegidos, deduplica por
     * usuario y EXCLUYE a quien envía (lo que mandó lo ve en el historial).
     *
     * @param string[] $destinos claves de self::DESTINOS (ya validadas)
     * @return int[] ids de usuario
     */
    public function destinatariosDeComunicado(array $destinos, ?int $seccionId, int $emisorId): array
    {
        $filas = [];
        if (in_array(self::DESTINO_DOCENTES, $destinos, true)) {
            array_push($filas, ...$this->usuariosDeRol('docente'));
        }
        if (in_array(self::DESTINO_SECCION, $destinos, true) && $seccionId !== null) {
            array_push($filas, ...$this->docentesDeSeccion($seccionId));
        }
        if (in_array(self::DESTINO_DIRECCION, $destinos, true)) {
            array_push($filas, ...$this->usuariosDeRoles(ROLES_DIRECCION));
        }
        if (in_array(self::DESTINO_ADMINISTRATIVO, $destinos, true)) {
            array_push($filas, ...$this->usuariosDeRoles(self::ROLES_ADMINISTRATIVOS));
        }

        $ids = [];
        foreach ($filas as $f) {
            $id = (int) $f['id'];
            if ($id !== $emisorId) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Envía un comunicado: guarda el ENVÍO en `comunicados` (migración 060) y
     * una notificación por destinatario enlazada a él. La transacción la abre
     * el controlador.
     *
     * @param string[] $destinos
     * @param int[]    $usuarioIds
     * @return int id del comunicado
     */
    public function enviarComunicado(
        int $emisorId,
        string $titulo,
        string $mensaje,
        array $destinos,
        ?int $seccionId,
        array $usuarioIds
    ): int {
        $this->execute("
            INSERT INTO comunicados (emisor_id, titulo, mensaje, destinos, seccion_id)
            VALUES (?, ?, ?, ?, ?)
        ", [$emisorId, $titulo, $mensaje, implode(',', $destinos), $seccionId]);
        $comunicadoId = (int) $this->db->lastInsertId();

        foreach ($usuarioIds as $uid) {
            $this->crear([
                'usuario_id'    => (int) $uid,
                'tipo'          => self::TIPO_COMUNICADO,
                'origen'        => 'comunicado',
                'titulo'        => $titulo,
                'mensaje'       => $mensaje,
                'enlace'        => null,
                'emisor_id'     => $emisorId,
                'comunicado_id' => $comunicadoId,
            ]);
        }

        return $comunicadoId;
    }

    /**
     * Historial de comunicados enviados. Lo ven TODOS los emisores (admin y
     * RA), para no mandar dos veces lo mismo. Incluye cuántos lo leyeron.
     */
    public function comunicadosEnviados(int $limite = 100): array
    {
        return $this->query("
            SELECT
                c.id, c.titulo, c.mensaje, c.destinos, c.created_at,
                TRIM(CONCAT(p.apellido_paterno, ' ', p.nombres)) AS emisor,
                niv.nombre       AS nivel_nombre,
                g.nombre_display AS grado_nombre,
                s.nombre         AS seccion_nombre,
                COUNT(n.id)                                AS destinatarios,
                COALESCE(SUM(n.leida_en IS NOT NULL), 0)   AS leidos
            FROM comunicados c
            LEFT JOIN usuarios u    ON u.id   = c.emisor_id
            LEFT JOIN personas p    ON p.id   = u.persona_id
            LEFT JOIN secciones s   ON s.id   = c.seccion_id
            LEFT JOIN grados g      ON g.id   = s.grado_id
            LEFT JOIN niveles niv   ON niv.id = g.nivel_id
            LEFT JOIN notificaciones n ON n.comunicado_id = c.id
            GROUP BY c.id, c.titulo, c.mensaje, c.destinos, c.created_at,
                     p.apellido_paterno, p.nombres, niv.nombre, g.nombre_display, s.nombre
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT " . (int) $limite . "
        ");
    }

    /** Bandeja del usuario: primero las no leídas, y dentro, las más nuevas. */
    public function paraUsuario(int $usuarioId, int $limite = 100): array
    {
        return $this->query("
            SELECT
                n.id, n.tipo, n.origen, n.titulo, n.mensaje, n.enlace,
                n.leida_en, n.created_at,
                TRIM(CONCAT(p.apellido_paterno, ' ', p.nombres)) AS emisor
            FROM notificaciones n
            LEFT JOIN usuarios u ON u.id = n.emisor_id
            LEFT JOIN personas p ON p.id = u.persona_id
            WHERE n.usuario_id = ?
            ORDER BY (n.leida_en IS NULL) DESC, n.created_at DESC
            LIMIT " . (int) $limite . "
        ", [$usuarioId]);
    }

    /**
     * Cuántas no leídas tiene el usuario. Es la consulta que se ejecuta en CADA
     * página (alimenta la campana de la barra), así que va servida por
     * `idx_bandeja (usuario_id, leida_en, created_at)`.
     */
    public function contarNoLeidas(int $usuarioId): int
    {
        $fila = $this->queryOne("
            SELECT COUNT(*) AS n
            FROM notificaciones
            WHERE usuario_id = ? AND leida_en IS NULL
        ", [$usuarioId]);

        return (int) ($fila['n'] ?? 0);
    }

    /**
     * Marca una notificación como leída. Filtra por `usuario_id` a propósito:
     * nadie puede marcar la de otro aunque adivine el id.
     */
    public function marcarLeida(int $id, int $usuarioId): bool
    {
        return $this->execute("
            UPDATE notificaciones
            SET leida_en = NOW()
            WHERE id = ? AND usuario_id = ? AND leida_en IS NULL
        ", [$id, $usuarioId]);
    }

    /** Marca como leídas todas las del usuario. */
    public function marcarTodasLeidas(int $usuarioId): bool
    {
        return $this->execute("
            UPDATE notificaciones
            SET leida_en = NOW()
            WHERE usuario_id = ? AND leida_en IS NULL
        ", [$usuarioId]);
    }
}
