<?php

namespace App\Models;

/**
 * AsistenciaMotivoModel — CATÁLOGO de motivos de justificación de la asistencia
 * (29/09/2026, migración 069). Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Toda FJ/TJ necesita un motivo de este catálogo para poder CONFIRMARSE, y se
 * guarda por su id para poder sacar reportes. Es el PUNTO ÚNICO de la lista: la
 * vista y el servidor la leen de aquí (a diferencia de los motivos de omisión de
 * calificaciones, copiados en BD, PHP y JS).
 *
 * Un motivo que ya se usó NO se borra: se RETIRA (deja de ofrecerse; sus
 * registros y reportes se conservan). Gestionan admin y RA; Dirección lee.
 */
class AsistenciaMotivoModel extends BaseModel
{
    protected string $table = 'asistencia_motivos';

    /**
     * Código ESTABLE del motivo «Justificación verbal» (sembrado por la migración
     * 069). Lo usa la alerta de «justificaciones mayoritariamente verbales» de
     * las estadísticas: se identifica por código, NUNCA por id ni por nombre
     * (el nombre se puede corregir en la pantalla; el código no cambia).
     */
    public const CODIGO_VERBAL = 'verbal';

    /** Motivos que se OFRECEN al registrar (no retirados), en su orden. */
    public function vigentes(): array
    {
        return $this->query("
            SELECT id, codigo, nombre, orden
            FROM asistencia_motivos
            WHERE retirado_en IS NULL
            ORDER BY orden, id
        ");
    }

    /** ¿Existe y no está retirado? (guarda de escritura: no se asigna uno retirado). */
    public function esVigente(int $motivoId): bool
    {
        return $this->queryOne("
            SELECT 1 AS ok FROM asistencia_motivos WHERE id = ? AND retirado_en IS NULL
        ", [$motivoId]) !== null;
    }

    // ── Gestión (pantalla de motivos: admin y RA; Dirección lee) ─────

    public const NOMBRE_MIN = 3;
    public const NOMBRE_MAX = 120;

    /**
     * Todos los motivos (vigentes primero, en su orden; luego los retirados) con
     * cuántas justificaciones los usan y quién los corrigió o retiró.
     */
    public function listar(): array
    {
        return $this->query("
            SELECT am.id, am.codigo, am.nombre, am.orden, am.retirado_en, am.modificado_en,
                   (SELECT COUNT(*) FROM asistencia_incidencias x WHERE x.motivo_id = am.id) AS usos,
                   CASE WHEN pm.id IS NULL THEN ''
                        ELSE CONCAT(pm.apellido_paterno, ', ', pm.nombres) END AS modificado_por_nombre
            FROM asistencia_motivos am
            LEFT JOIN usuarios um ON um.id = am.modificado_por
            LEFT JOIN personas pm ON pm.id = um.persona_id
            ORDER BY am.retirado_en IS NOT NULL, am.orden, am.id
        ");
    }

    /** Nombre limpio y válido, o \DomainException con el motivo. */
    private function nombreValido(string $nombre, ?int $excepto = null): string
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        $largo  = mb_strlen($nombre);
        if ($largo < self::NOMBRE_MIN || $largo > self::NOMBRE_MAX) {
            throw new \DomainException('El motivo debe tener entre ' . self::NOMBRE_MIN . ' y ' . self::NOMBRE_MAX . ' caracteres.');
        }
        $repetido = $this->queryOne("
            SELECT 1 AS x FROM asistencia_motivos
            WHERE retirado_en IS NULL AND LOWER(nombre) = LOWER(?) AND id <> ?
        ", [$nombre, $excepto ?? 0]);
        if ($repetido !== null) {
            throw new \DomainException('Ya existe un motivo con ese nombre.');
        }
        return $nombre;
    }

    /**
     * Código ESTABLE para reportes, derivado del nombre al crear (sin tildes, en
     * minúsculas, con guion bajo). Nunca cambia al renombrar: los reportes se
     * agrupan por id y el código solo lo hace legible.
     */
    private function codigoNuevo(string $nombre): string
    {
        // Reemplazo explícito: `iconv(...//TRANSLIT)` en Windows convierte «ó» en
        // «'o» y el código salía como «justificaci_on».
        $ascii = strtr(mb_strtolower($nombre), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '', '_');
        $base = substr($base !== '' ? $base : 'motivo', 0, 34);
        $codigo = $base;
        for ($n = 2; $this->queryOne("SELECT 1 AS x FROM asistencia_motivos WHERE codigo = ?", [$codigo]); $n++) {
            $codigo = $base . '_' . $n;
        }
        return $codigo;
    }

    public function crear(string $nombre, int $userId): void
    {
        $nombre = $this->nombreValido($nombre);
        $orden  = (int) ($this->queryOne("SELECT COALESCE(MAX(orden), 0) + 1 AS o FROM asistencia_motivos")['o'] ?? 1);
        $this->execute("
            INSERT INTO asistencia_motivos (codigo, nombre, orden, creado_por) VALUES (?, ?, ?, ?)
        ", [$this->codigoNuevo($nombre), $nombre, $orden, $userId]);
    }

    /**
     * Renombrar: permitido aunque el motivo ya se haya usado (corrige cómo se lee;
     * los registros apuntan al id). Queda la traza de quién y cuándo.
     */
    public function renombrar(int $id, string $nombre, int $userId): void
    {
        $this->vigenteOFalla($id);
        $nombre = $this->nombreValido($nombre, $id);
        $this->execute("
            UPDATE asistencia_motivos SET nombre = ?, modificado_en = NOW(), modificado_por = ?
            WHERE id = ?
        ", [$nombre, $userId, $id]);
    }

    /**
     * RETIRAR (nunca borrar): deja de ofrecerse; sus justificaciones y reportes
     * se conservan. No se retira el ÚLTIMO vigente: sin ningún motivo, ninguna
     * FJ/TJ podría confirmarse.
     */
    public function retirar(int $id, int $userId): void
    {
        $this->vigenteOFalla($id);
        $vigentes = (int) ($this->queryOne("SELECT COUNT(*) AS n FROM asistencia_motivos WHERE retirado_en IS NULL")['n'] ?? 0);
        if ($vigentes <= 1) {
            throw new \DomainException('Debe quedar al menos un motivo: sin él no se podría confirmar ninguna justificación.');
        }
        $this->execute("
            UPDATE asistencia_motivos SET retirado_en = NOW(), retirado_por = ? WHERE id = ?
        ", [$userId, $id]);
    }

    /** Sube o baja un motivo vigente intercambiando su orden con el vecino. */
    public function mover(int $id, bool $arriba): void
    {
        $this->vigenteOFalla($id);
        $vigentes = $this->vigentes();
        $pos = array_search($id, array_map(static fn($m) => (int) $m['id'], $vigentes), true);
        $otro = $vigentes[$arriba ? $pos - 1 : $pos + 1] ?? null;
        if ($pos === false || $otro === null) {
            return;
        }
        // Posición en la lista como orden: deja los órdenes consecutivos aunque
        // hubiera empates o huecos.
        foreach ($vigentes as $i => $m) {
            $orden = $i + 1;
            if ((int) $m['id'] === $id)            { $orden = $arriba ? $i : $i + 2; }
            if ((int) $m['id'] === (int) $otro['id']) { $orden = $arriba ? $i + 2 : $i; }
            $this->execute("UPDATE asistencia_motivos SET orden = ? WHERE id = ?", [$orden, (int) $m['id']]);
        }
    }

    private function vigenteOFalla(int $id): void
    {
        if (!$this->esVigente($id)) {
            throw new \DomainException('Ese motivo no existe o ya fue retirado.');
        }
    }
}
