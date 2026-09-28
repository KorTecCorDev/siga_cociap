<?php

namespace App\Models;

/**
 * AuxiliarSeccionModel — qué auxiliar académico tiene a cargo cada sección,
 * por bimestre (migración 066, 28/09/2026). Ver docs/modulos/auxiliares.md.
 *
 * MODELO «DESDE BIMESTRE»: una fila de `auxiliar_secciones` dice «desde el
 * bimestre X, la sección S la tiene el auxiliar A», y vale hasta que otra fila
 * posterior de la misma sección la reemplace. De ahí salen la herencia
 * automática entre bimestres y el historial de los ya cerrados.
 *
 * 🔴 PUNTO ÚNICO de dos reglas:
 *   - «¿Quién es el auxiliar vigente de S en P?» → `vigentesSql()`. Todas las
 *     consultas de este modelo la usan; no se vuelve a escribir a mano.
 *   - «¿Puede este usuario registrar la sección S en P?» → `puedeRegistrar()`.
 *     Toda guarda de servidor de conducta, asistencia y documentos pasa por aquí.
 */
class AuxiliarSeccionModel extends BaseModel
{
    protected string $table = 'auxiliar_secciones';

    /**
     * Roles que registran CUALQUIER sección, con o sin auxiliar asignado: son el
     * respaldo cuando un auxiliar falta o una sección queda sin asignar
     * (decisión del usuario, 28/09/2026). Admin y RA también son los que asignan.
     */
    public const ROLES_TODAS_LAS_SECCIONES = ['admin', 'registro_academico'];

    /**
     * Fragmento SQL: una fila por sección con su asignación VIGENTE en el
     * periodo `:p` (placeholder posicional: el llamador pasa el periodo UNA vez
     * por cada `?` del fragmento, hoy dos). Columnas: seccion_id, auxiliar_id
     * (NULL = sin auxiliar), desde_periodo_id, desde_numero.
     *
     * La vigente es la fila de mayor `periodos.numero` <= el del periodo pedido,
     * dentro del MISMO año académico. Un bimestre posterior al pedido nunca
     * cuenta: así un bimestre cerrado conserva a su auxiliar para siempre.
     */
    private function vigentesSql(): string
    {
        return "
            SELECT a.seccion_id, a.auxiliar_id, a.desde_periodo_id, pd.numero AS desde_numero
            FROM auxiliar_secciones a
            INNER JOIN periodos pd ON pd.id = a.desde_periodo_id
            INNER JOIN periodos pp ON pp.id = ?
                                  AND pp.anio_id = pd.anio_id
                                  AND pd.numero <= pp.numero
            WHERE pd.numero = (
                SELECT MAX(pd2.numero)
                FROM auxiliar_secciones a2
                INNER JOIN periodos pd2 ON pd2.id = a2.desde_periodo_id
                INNER JOIN periodos pp2 ON pp2.id = ?
                                       AND pp2.anio_id = pd2.anio_id
                                       AND pd2.numero <= pp2.numero
                WHERE a2.seccion_id = a.seccion_id
            )
        ";
    }

    /** El bimestre ACTIVO del año activo (el sistema no permite dos a la vez). */
    public function periodoActivo(): ?array
    {
        return $this->queryOne("
            SELECT p.id, p.numero, p.nombre_display, p.anio_id, a.anio
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
            WHERE p.estado = 'activo'
            LIMIT 1
        ");
    }

    /**
     * Auxiliar vigente de una sección en un periodo, con su nombre.
     * NULL si la sección no tiene auxiliar en ese periodo.
     *
     * @return array{auxiliar_id:int, nombre:string, sexo:?string, desde_periodo_id:int}|null
     */
    public function vigente(int $seccionId, int $periodoId): ?array
    {
        $fila = $this->queryOne("
            SELECT v.auxiliar_id, v.desde_periodo_id, p.sexo,
                   p.apellido_paterno, p.apellido_materno, p.nombres
            FROM (" . $this->vigentesSql() . ") v
            INNER JOIN usuarios u ON u.id = v.auxiliar_id
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE v.seccion_id = ?
        ", [$periodoId, $periodoId, $seccionId]);

        if ($fila === null) {
            return null;
        }
        return [
            'auxiliar_id'      => (int) $fila['auxiliar_id'],
            'nombre'           => self::nombreCompleto($fila),
            'sexo'             => $fila['sexo'] ?? null,
            'desde_periodo_id' => (int) $fila['desde_periodo_id'],
        ];
    }

    /**
     * IDs de las secciones que un auxiliar tiene a cargo en un periodo.
     *
     * @return int[]
     */
    public function seccionesDe(int $auxiliarId, int $periodoId): array
    {
        $filas = $this->query("
            SELECT v.seccion_id
            FROM (" . $this->vigentesSql() . ") v
            WHERE v.auxiliar_id = ?
        ", [$periodoId, $periodoId, $auxiliarId]);

        return array_map('intval', array_column($filas, 'seccion_id'));
    }

    /**
     * ¿Puede este usuario registrar (y bloquear) la sección en ese periodo?
     *
     * - Admin y RA: siempre (respaldo).
     * - Auxiliar: solo si es el vigente de ESA sección en ESE periodo.
     * - Cualquier otro rol: nunca.
     *
     * Decide SOLO el alcance por sección. Si el periodo está abierto a edición
     * lo sigue decidiendo cada controlador, como hasta hoy.
     */
    public function puedeRegistrar(array $usuario, int $seccionId, int $periodoId): bool
    {
        $rol = (string) ($usuario['rol_codigo'] ?? '');

        if (in_array($rol, self::ROLES_TODAS_LAS_SECCIONES, true)) {
            return true;
        }
        if ($rol !== ROL_AUXILIAR) {
            return false;
        }
        $vigente = $this->vigente($seccionId, $periodoId);
        return $vigente !== null && $vigente['auxiliar_id'] === (int) ($usuario['id'] ?? 0);
    }

    /**
     * Secciones del año de un periodo, con su auxiliar vigente en ese periodo.
     * `cambiado_aqui` = la asignación se fijó EN este periodo (no es heredada).
     */
    public function listarParaPeriodo(int $periodoId): array
    {
        $filas = $this->query("
            SELECT s.id, s.nombre AS seccion_nombre,
                   g.numero AS grado_numero, g.nombre_display AS grado_nombre,
                   n.id AS nivel_id, n.nombre AS nivel_nombre,
                   v.auxiliar_id, v.desde_periodo_id, v.desde_numero,
                   pd.nombre_display AS desde_nombre,
                   pe.apellido_paterno, pe.apellido_materno, pe.nombres, pe.dni
            FROM periodos per
            INNER JOIN secciones s ON s.anio_id = per.anio_id
            INNER JOIN grados g    ON g.id = s.grado_id
            INNER JOIN niveles n   ON n.id = g.nivel_id
            LEFT JOIN (" . $this->vigentesSql() . ") v ON v.seccion_id = s.id
            LEFT JOIN periodos pd  ON pd.id = v.desde_periodo_id
            LEFT JOIN usuarios u   ON u.id = v.auxiliar_id
            LEFT JOIN personas pe  ON pe.id = u.persona_id
            WHERE per.id = ?
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId, $periodoId]);

        return array_map(static fn(array $r): array => [
            'id'             => (int) $r['id'],
            'seccion_nombre' => (string) $r['seccion_nombre'],
            'grado_numero'   => (int) $r['grado_numero'],
            'grado_nombre'   => (string) $r['grado_nombre'],
            'nivel_id'       => (int) $r['nivel_id'],
            'nivel_nombre'   => (string) $r['nivel_nombre'],
            'auxiliar_id'    => $r['auxiliar_id'] !== null ? (int) $r['auxiliar_id'] : null,
            'auxiliar_nombre'=> $r['auxiliar_id'] !== null ? self::nombreCompleto($r) : null,
            'auxiliar_dni'   => $r['dni'] ?? null,
            'desde_nombre'   => $r['desde_nombre'] ?? null,
            'cambiado_aqui'  => $r['desde_periodo_id'] !== null
                                && (int) $r['desde_periodo_id'] === $periodoId,
        ], $filas);
    }

    /**
     * Secciones del año donde cada auxiliar tiene a un HIJO/A (es su apoderado).
     *
     * Regla del usuario (28/09/2026): se PERMITE asignarle esa sección, pero la
     * pantalla de asignación lo AVISA. «Estar en la sección» es el roster de
     * evaluación (`roster_evaluacion()`): los mismos estudiantes que registrará.
     *
     * @return array<int, int[]> auxiliar_id => [seccion_id, …]
     */
    public function seccionesConHijos(int $anioId): array
    {
        $filas = $this->query("
            SELECT DISTINCT u.id AS auxiliar_id, m.seccion_id
            FROM usuarios u
            INNER JOIN roles r             ON r.id = u.rol_id AND r.codigo = ?
            INNER JOIN apoderados ap       ON ap.persona_id = u.persona_id
            INNER JOIN vinculo_familiar vf ON vf.apoderado_id = ap.id
            INNER JOIN matriculas m        ON m.estudiante_id = vf.estudiante_id
                                          AND m.anio_id = ?
                                          " . roster_evaluacion('m') . "
            WHERE u.estado = 'activo'
        ", [ROL_AUXILIAR, $anioId]);

        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f['auxiliar_id']][] = (int) $f['seccion_id'];
        }
        return $out;
    }

    /** Usuarios ACTIVOS con el rol de auxiliar, en orden alfabético. */
    public function listarAuxiliares(): array
    {
        return $this->query("
            SELECT u.id, p.apellido_paterno, p.apellido_materno, p.nombres, p.dni
            FROM usuarios u
            INNER JOIN personas p ON p.id = u.persona_id
            INNER JOIN roles r    ON r.id = u.rol_id
            WHERE r.codigo = ?
              AND u.estado = 'activo'
            ORDER BY " . orden_alfabetico('p') . "
        ", [ROL_AUXILIAR]);
    }

    /**
     * Asigna (o quita, con NULL) el auxiliar de una sección DESDE el bimestre
     * activo. Solo el activo: los cerrados quedan fijos (decisión del usuario).
     *
     * Deja la tabla sin filas redundantes, para que «heredado» siga siendo cierto:
     *   - si el nuevo valor es el que ya rige, no hace nada;
     *   - si es el que se heredaba del bimestre anterior, borra la fila de ESTE
     *     bimestre (vuelve a heredar) — solo esa, nunca una de otro bimestre;
     *   - en otro caso, crea o sobrescribe la fila de este bimestre.
     *
     * @throws \DomainException con un mensaje para el usuario si no procede.
     */
    public function asignar(int $seccionId, ?int $auxiliarId, int $asignadoPor): void
    {
        $periodo = $this->periodoActivo();
        if ($periodo === null) {
            throw new \DomainException('No hay un bimestre activo: las asignaciones solo se hacen durante un bimestre abierto.');
        }
        $periodoId = (int) $periodo['id'];

        $seccion = $this->queryOne(
            "SELECT id FROM secciones WHERE id = ? AND anio_id = ?",
            [$seccionId, (int) $periodo['anio_id']]
        );
        if ($seccion === null) {
            throw new \DomainException('La sección no pertenece al año académico activo.');
        }

        if ($auxiliarId !== null) {
            $valido = $this->queryOne("
                SELECT u.id FROM usuarios u
                INNER JOIN roles r ON r.id = u.rol_id
                WHERE u.id = ? AND r.codigo = ? AND u.estado = 'activo'
            ", [$auxiliarId, ROL_AUXILIAR]);
            if ($valido === null) {
                throw new \DomainException('El usuario elegido no es un auxiliar académico activo.');
            }
        }

        $actual = $this->vigente($seccionId, $periodoId);
        if (($actual['auxiliar_id'] ?? null) === $auxiliarId) {
            return;
        }

        // Transacción propia solo si el llamador no abrió una (la conexión es
        // compartida y PDO no anida): así un verificador puede envolverlo todo
        // en su transacción y revertir, sin DELETE de limpieza.
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $anterior = $this->vigenteAntesDe($seccionId, $periodo);
            if ($anterior === $auxiliarId) {
                $this->execute(
                    "DELETE FROM auxiliar_secciones WHERE seccion_id = ? AND desde_periodo_id = ?",
                    [$seccionId, $periodoId]
                );
            } else {
                $this->execute("
                    INSERT INTO auxiliar_secciones
                        (seccion_id, desde_periodo_id, auxiliar_id, asignado_por, asignado_en)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        auxiliar_id  = VALUES(auxiliar_id),
                        asignado_por = VALUES(asignado_por),
                        asignado_en  = VALUES(asignado_en)
                ", [$seccionId, $periodoId, $auxiliarId, $asignadoPor, date('Y-m-d H:i:s')]);
            }
            if ($propia) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }
    }

    /**
     * Auxiliar que la sección heredaría si ESTE bimestre no tuviera fila propia:
     * el de la última fila de un bimestre ANTERIOR del mismo año (NULL si no hay).
     */
    private function vigenteAntesDe(int $seccionId, array $periodo): ?int
    {
        $fila = $this->queryOne("
            SELECT a.auxiliar_id
            FROM auxiliar_secciones a
            INNER JOIN periodos pd ON pd.id = a.desde_periodo_id
            WHERE a.seccion_id = ? AND pd.anio_id = ? AND pd.numero < ?
            ORDER BY pd.numero DESC
            LIMIT 1
        ", [$seccionId, (int) $periodo['anio_id'], (int) $periodo['numero']]);

        return ($fila !== null && $fila['auxiliar_id'] !== null) ? (int) $fila['auxiliar_id'] : null;
    }

    /** «PATERNO MATERNO, Nombres» — el formato de los documentos del colegio. */
    private static function nombreCompleto(array $r): string
    {
        return trim(
            mb_strtoupper(trim(($r['apellido_paterno'] ?? '') . ' ' . ($r['apellido_materno'] ?? '')))
            . ', ' . ($r['nombres'] ?? '')
        );
    }
}
