<?php

namespace App\Models;

/**
 * RatificacionMotivoModel — CATÁLOGO de motivos de NO ratificación, POR AÑO
 * (migración 080, 14/10/2026). Ver docs/modulos/ratificaciones.md.
 *
 * Cada motivo lleva el PÁRRAFO OFICIAL que redacta Dirección: el sistema no
 * inventa ninguna redacción. El documento emitido COPIA ese texto, así que
 * corregirlo aquí no reescribe las cartas ya entregadas.
 *
 * Un motivo que ya se usó NO se borra: se RETIRA (deja de ofrecerse; las
 * decisiones que lo tienen lo conservan). Nunca hay DELETE en este modelo.
 */
class RatificacionMotivoModel extends BaseModel
{
    protected string $table = 'ratificacion_motivos';

    public const NOMBRE_MIN = 3;
    public const NOMBRE_MAX = 100;
    public const TEXTO_MIN  = 10;
    public const TEXTO_MAX  = 5000;

    /** Motivos del año (vigentes primero) con cuántas decisiones los usan. */
    public function listar(int $anioId): array
    {
        return $this->query("
            SELECT rm.id, rm.nombre, rm.texto_oficial, rm.activo,
                   (SELECT COUNT(*) FROM ratificaciones r WHERE r.motivo_id = rm.id) AS usos
            FROM ratificacion_motivos rm
            WHERE rm.anio_id = ?
            ORDER BY rm.activo DESC, rm.nombre
        ", [$anioId]);
    }

    /** Motivos VIGENTES del año (los que se ofrecen al decidir). */
    public function vigentes(int $anioId): array
    {
        return $this->query("
            SELECT id, nombre FROM ratificacion_motivos
            WHERE anio_id = ? AND activo = 1
            ORDER BY nombre
        ", [$anioId]);
    }

    /** @throws \DomainException */
    public function crear(int $anioId, string $nombre, string $texto): int
    {
        [$nombre, $texto] = $this->validar($nombre, $texto);
        $this->nombreLibreOFalla($anioId, $nombre, 0);
        $this->execute("
            INSERT INTO ratificacion_motivos (anio_id, nombre, texto_oficial) VALUES (?, ?, ?)
        ", [$anioId, $nombre, $texto]);
        return (int) $this->db->lastInsertId();
    }

    /** @throws \DomainException */
    public function actualizar(int $id, string $nombre, string $texto): void
    {
        $motivo = $this->existeOFalla($id);
        [$nombre, $texto] = $this->validar($nombre, $texto);
        $this->nombreLibreOFalla((int) $motivo['anio_id'], $nombre, $id);
        $this->execute("
            UPDATE ratificacion_motivos SET nombre = ?, texto_oficial = ? WHERE id = ?
        ", [$nombre, $texto, $id]);
    }

    /** Deja de ofrecerse; lo ya decidido lo conserva. @throws \DomainException */
    public function retirar(int $id): void
    {
        $this->existeOFalla($id);
        $this->execute("UPDATE ratificacion_motivos SET activo = 0 WHERE id = ?", [$id]);
    }

    /** Vuelve a ofrecerse. @throws \DomainException */
    public function reactivar(int $id): void
    {
        $this->existeOFalla($id);
        $this->execute("UPDATE ratificacion_motivos SET activo = 1 WHERE id = ?", [$id]);
    }

    /** Año del motivo, o null si no existe (para volver a su pantalla). */
    public function anioDe(int $id): ?int
    {
        $r = $this->queryOne("SELECT anio_id FROM ratificacion_motivos WHERE id = ?", [$id]);
        return $r !== null ? (int) $r['anio_id'] : null;
    }

    // ── Apoyo ───────────────────────────────────────────────────

    /** @return array{0:string,1:string} nombre y texto normalizados */
    private function validar(string $nombre, string $texto): array
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        $texto  = trim(str_replace("\r\n", "\n", $texto));
        $ln = mb_strlen($nombre);
        if ($ln < self::NOMBRE_MIN || $ln > self::NOMBRE_MAX) {
            throw new \DomainException(
                'El nombre del motivo debe tener entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres.'
            );
        }
        $lt = mb_strlen($texto);
        if ($lt < self::TEXTO_MIN || $lt > self::TEXTO_MAX) {
            throw new \DomainException(
                'El texto oficial debe tener entre ' . self::TEXTO_MIN . ' y ' . self::TEXTO_MAX . ' caracteres.'
            );
        }
        return [$nombre, $texto];
    }

    private function nombreLibreOFalla(int $anioId, string $nombre, int $exceptoId): void
    {
        $r = $this->queryOne(
            "SELECT id FROM ratificacion_motivos WHERE anio_id = ? AND nombre = ? AND id <> ?",
            [$anioId, $nombre, $exceptoId]
        );
        if ($r !== null) {
            throw new \DomainException('Ya existe un motivo con ese nombre en este año.');
        }
    }

    private function existeOFalla(int $id): array
    {
        $r = $this->queryOne("SELECT id, anio_id FROM ratificacion_motivos WHERE id = ?", [$id]);
        if ($r === null) {
            throw new \DomainException('El motivo no existe.');
        }
        return $r;
    }
}
