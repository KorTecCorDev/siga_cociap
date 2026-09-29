<?php

namespace App\Models;

/**
 * CriterioConductaModel — gestión de los CRITERIOS DE CONDUCTA de cada año
 * (F6 del módulo auxiliares, 28/09/2026, migración 067). Lo que se LEE para
 * registrar y calcular la conducta sigue en `ConductaModel` (punto único
 * `criteriosDelAnio`); aquí solo se administran.
 *
 * REGLAS (decisiones del usuario, docs/modulos/auxiliares.md §7 F6), todas en
 * `validarEdicion()`, nunca en el controlador:
 *   - Año CERRADO: solo lectura.
 *   - Año con RESPUESTAS registradas: solo se corrige la REDACCIÓN (no se
 *     agrega, retira, reordena ni cambia de nivel), y queda quién y cuándo.
 *   - Año sin respuestas: edición completa. Los códigos C1..Cn se renumeran por
 *     orden; es seguro porque aún no hay nada registrado ni impreso con ellos.
 */
class CriterioConductaModel extends BaseModel
{
    protected string $table = 'criterios_conducta';

    public const TEXTO_MAX = 255;

    /** Años académicos, el más reciente primero. */
    public function anios(): array
    {
        return $this->query("SELECT id, anio, estado FROM anios_academicos ORDER BY anio DESC");
    }

    public function anio(int $anioId): ?array
    {
        return $this->queryOne("SELECT id, anio, estado FROM anios_academicos WHERE id = ?", [$anioId]);
    }

    /** Niveles para el selector («Ambos» = NULL lo agrega la vista). */
    public function niveles(): array
    {
        return $this->query("SELECT id, nombre FROM niveles ORDER BY id");
    }

    /** Criterios vigentes de un año, con su nivel y su última corrección. */
    public function listar(int $anioId): array
    {
        return $this->query("
            SELECT k.id, k.codigo, k.texto, k.orden, k.nivel_id, n.nombre AS nivel_nombre,
                   k.modificado_en,
                   CASE WHEN pm.id IS NULL THEN ''
                        ELSE CONCAT(pm.apellido_paterno, ' ', pm.apellido_materno, ', ', pm.nombres)
                   END AS modificado_por_nombre
            FROM criterios_conducta k
            LEFT JOIN niveles  n  ON n.id  = k.nivel_id
            LEFT JOIN usuarios um ON um.id = k.modificado_por
            LEFT JOIN personas pm ON pm.id = um.persona_id
            WHERE k.anio_id = ? AND k.eliminado_en IS NULL
            ORDER BY k.orden, k.id
        ", [$anioId]);
    }

    /** ¿Hay conducta registrada con los criterios de ese año? (congela la lista) */
    public function tieneRespuestas(int $anioId): bool
    {
        return $this->queryOne("
            SELECT 1 FROM conducta_respuestas r
            INNER JOIN criterios_conducta k ON k.id = r.criterio_id
            WHERE k.anio_id = ?
            LIMIT 1
        ", [$anioId]) !== null;
    }

    /** El año anterior más reciente que tiene criterios vigentes (origen de la copia). */
    public function anioAnteriorConCriterios(int $anioId): ?array
    {
        return $this->queryOne("
            SELECT a.id, a.anio, COUNT(k.id) AS criterios
            FROM anios_academicos a
            INNER JOIN anios_academicos actual ON actual.id = ?
            INNER JOIN criterios_conducta k ON k.anio_id = a.id AND k.eliminado_en IS NULL
            WHERE a.anio < actual.anio
            GROUP BY a.id, a.anio
            ORDER BY a.anio DESC
            LIMIT 1
        ", [$anioId]);
    }

    /**
     * Qué se puede hacer con los criterios de un año:
     * 'lectura' (año cerrado), 'redaccion' (tiene respuestas) o 'completa'.
     */
    public function modoEdicion(int $anioId): string
    {
        $anio = $this->anio($anioId);
        if ($anio === null || $anio['estado'] === 'cerrado') {
            return 'lectura';
        }
        return $this->tieneRespuestas($anioId) ? 'redaccion' : 'completa';
    }

    public function crear(int $anioId, string $texto, ?int $nivelId): void
    {
        $this->validarEdicion($anioId, 'completa');
        $texto = $this->validarTexto($texto);
        $this->validarNivel($nivelId);

        $orden = (int) ($this->queryOne("
            SELECT COALESCE(MAX(orden), 0) AS m FROM criterios_conducta
            WHERE anio_id = ? AND eliminado_en IS NULL
        ", [$anioId])['m'] ?? 0) + 1;

        $this->execute("
            INSERT INTO criterios_conducta (anio_id, texto, nivel_id, orden, creado_en)
            VALUES (?, ?, ?, ?, ?)
        ", [$anioId, $texto, $nivelId, $orden, date('Y-m-d H:i:s')]);
        $this->renumerar($anioId);
    }

    /**
     * Corrige la redacción (siempre que el año no esté cerrado) y, solo si el año
     * aún no tiene respuestas, también el nivel. Registra quién y cuándo.
     */
    public function actualizar(int $id, string $texto, ?int $nivelId, int $usuarioId): void
    {
        $k = $this->criterio($id);
        $modo = $this->validarEdicion((int) $k['anio_id'], 'redaccion');
        $texto = $this->validarTexto($texto);
        $this->validarNivel($nivelId);

        $nivelActual = $k['nivel_id'] === null ? null : (int) $k['nivel_id'];
        if ($nivelId !== $nivelActual && $modo !== 'completa') {
            throw new \DomainException('Este año ya tiene conducta registrada: solo se puede corregir la redacción, no el nivel.');
        }

        $this->execute("
            UPDATE criterios_conducta
               SET texto = ?, nivel_id = ?, modificado_en = ?, modificado_por = ?
             WHERE id = ?
        ", [$texto, $nivelId, date('Y-m-d H:i:s'), $usuarioId, $id]);
    }

    public function retirar(int $id, int $usuarioId): void
    {
        $k = $this->criterio($id);
        $this->validarEdicion((int) $k['anio_id'], 'completa');
        $this->execute("
            UPDATE criterios_conducta SET eliminado_en = ?, eliminado_por = ? WHERE id = ?
        ", [date('Y-m-d H:i:s'), $usuarioId, $id]);
        $this->renumerar((int) $k['anio_id']);
    }

    /** Sube o baja un criterio un puesto. */
    public function mover(int $id, bool $subir): void
    {
        $k = $this->criterio($id);
        $anioId = (int) $k['anio_id'];
        $this->validarEdicion($anioId, 'completa');

        $ids = array_map('intval', array_column($this->listar($anioId), 'id'));
        $pos = array_search($id, $ids, true);
        $otra = $subir ? $pos - 1 : $pos + 1;
        if ($pos === false || $otra < 0 || $otra >= count($ids)) {
            return;   // ya está en el extremo
        }
        [$ids[$pos], $ids[$otra]] = [$ids[$otra], $ids[$pos]];
        foreach ($ids as $i => $cid) {
            $this->execute("UPDATE criterios_conducta SET orden = ? WHERE id = ?", [$i + 1, $cid]);
        }
        $this->renumerar($anioId);
    }

    /**
     * Copia los criterios vigentes del año anterior con criterios a un año que no
     * tiene ninguno. Devuelve cuántos copió.
     */
    public function copiarDelAnterior(int $anioId): int
    {
        $this->validarEdicion($anioId, 'completa');
        if ($this->listar($anioId) !== []) {
            throw new \DomainException('Este año ya tiene criterios: solo se copia a un año sin criterios.');
        }
        $origen = $this->anioAnteriorConCriterios($anioId);
        if ($origen === null) {
            throw new \DomainException('No hay un año anterior con criterios para copiar.');
        }
        $ahora = date('Y-m-d H:i:s');
        foreach ($this->listar((int) $origen['id']) as $k) {
            $this->execute("
                INSERT INTO criterios_conducta (anio_id, codigo, texto, nivel_id, orden, creado_en)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$anioId, $k['codigo'], $k['texto'], $k['nivel_id'], $k['orden'], $ahora]);
        }
        $this->renumerar($anioId);
        return (int) $origen['criterios'];
    }

    // ── Reglas ──────────────────────────────────────────────────

    /**
     * Corta con DomainException si el año no admite la edición pedida.
     * $necesita: 'completa' (agregar/retirar/mover/copiar) o 'redaccion'.
     * Devuelve el modo del año.
     */
    private function validarEdicion(int $anioId, string $necesita): string
    {
        $modo = $this->modoEdicion($anioId);
        if ($modo === 'lectura') {
            throw new \DomainException('Ese año académico está cerrado: sus criterios son de solo lectura.');
        }
        if ($necesita === 'completa' && $modo !== 'completa') {
            throw new \DomainException('Este año ya tiene conducta registrada: solo se puede corregir la redacción de los criterios. Los cambios de criterios valen desde el año siguiente.');
        }
        return $modo;
    }

    private function validarTexto(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            throw new \DomainException('Escribe el criterio.');
        }
        if (mb_strlen($texto) > self::TEXTO_MAX) {
            throw new \DomainException('El criterio no puede pasar de ' . self::TEXTO_MAX . ' caracteres.');
        }
        return $texto;
    }

    private function validarNivel(?int $nivelId): void
    {
        if ($nivelId !== null && $this->queryOne("SELECT 1 FROM niveles WHERE id = ?", [$nivelId]) === null) {
            throw new \DomainException('Nivel no válido.');
        }
    }

    private function criterio(int $id): array
    {
        $k = $this->queryOne("
            SELECT id, anio_id, nivel_id FROM criterios_conducta WHERE id = ? AND eliminado_en IS NULL
        ", [$id]);
        if ($k === null) {
            throw new \DomainException('Ese criterio no existe o ya fue retirado.');
        }
        return $k;
    }

    /**
     * Orden 1..n sin huecos y código C1..Cn por ese orden. SOLO se llama en un
     * año sin respuestas (lo garantiza validarEdicion): con respuestas, los
     * códigos ya figuran en registros impresos y no se tocan (migración 056).
     */
    private function renumerar(int $anioId): void
    {
        foreach ($this->listar($anioId) as $i => $k) {
            $this->execute("UPDATE criterios_conducta SET orden = ?, codigo = ? WHERE id = ?",
                [$i + 1, 'C' . ($i + 1), (int) $k['id']]);
        }
    }
}
