<?php

namespace App\Models;

/**
 * RatificacionModel — RATIFICACIÓN DE MATRÍCULA (migración 080, 14/10/2026).
 * Ver docs/modulos/ratificaciones.md.
 *
 * Dirección decide, al final del año, qué estudiantes continúan el año
 * siguiente; admin, RA y las dos secretarías REGISTRAN esa decisión aquí.
 *
 * 🔴 PUNTO ÚNICO de dos cosas:
 *  1. El UNIVERSO (`sqlUniverso()`): a quién se le decide. Matrículas del año
 *     en CUALQUIER estado (pendiente/aprobada/desactivado), menos:
 *       · trasladados y retirados — `matriculas_vigentes()`;
 *       · la matrícula OPERATIVA de un retorno de grado — `matricula_documento()`
 *         (el documento se emite con la OFICIAL, Regla A);
 *       · 5.º de secundaria, que egresa: se identifica por `niveles.codigo` y
 *         `grados.numero`, NUNCA por id (difiere entre entornos).
 *  2. La DECISIÓN (`guardarDecision()`). Tres estados: sin fila = PENDIENTE (el
 *     olvido nunca produce una carta negativa), `ratificado`, `no_ratificado`
 *     (exige motivo; lo exige también el CHECK `chk_ratif_decision_motivo`).
 *     Cambiar la decisión de quien ya tiene documento emitido ANULA esa emisión
 *     en la misma transacción.
 */
class RatificacionModel extends BaseModel
{
    protected string $table = 'ratificaciones';

    /** Quién REGISTRA, emite y anula (decisión del usuario, 14/10/2026). */
    public const ROLES_REGISTRAN = [
        'admin', 'registro_academico',
        'secretaria_academica', 'secretaria_administrativa',
    ];

    /** Quién VE el módulo: los que registran y Dirección, en solo lectura. */
    public const ROLES_VEN = [...self::ROLES_REGISTRAN, ...ROLES_DIRECCION];

    public const DECISIONES = ['ratificado', 'no_ratificado'];

    public const DETALLE_MAX = 500;

    /**
     * Condiciones del UNIVERSO, ya con `AND` inicial, sobre los alias de la
     * consulta: matrícula, grado y nivel. Toda consulta del módulo que liste o
     * valide estudiantes pasa por aquí.
     */
    public static function sqlUniverso(string $m = 'm', string $g = 'g', string $n = 'n'): string
    {
        return matriculas_vigentes($m) . "\n"
            . '              ' . matricula_documento($m) . "\n"
            . "              AND NOT ({$n}.codigo = 'sec' AND {$g}.numero = 5)";
    }

    /** Años académicos, el más reciente primero (selector de la pantalla). */
    public function listarAnios(): array
    {
        return $this->query("SELECT id, anio, estado FROM anios_academicos ORDER BY anio DESC");
    }

    /** Año por id, o null. */
    public function anio(int $anioId): ?array
    {
        return $this->queryOne("SELECT id, anio, estado FROM anios_academicos WHERE id = ?", [$anioId]);
    }

    /**
     * Secciones del año que tienen estudiantes en el universo, con sus
     * contadores. Una sección de 5.º de secundaria no aparece (no tiene
     * universo).
     *
     * @return list<array{id:int, nombre:string, grado_nombre:string, nivel_nombre:string,
     *                    total:int, ratificados:int, no_ratificados:int, pendientes:int,
     *                    emitidos:int}>
     */
    public function seccionesConContadores(int $anioId): array
    {
        return $this->query("
            SELECT s.id, s.nombre,
                   g.nombre_display AS grado_nombre,
                   n.nombre         AS nivel_nombre,
                   COUNT(*)                                             AS total,
                   SUM(r.decision = 'ratificado')                       AS ratificados,
                   SUM(r.decision = 'no_ratificado')                    AS no_ratificados,
                   SUM(r.id IS NULL)                                    AS pendientes,
                   SUM(e.id IS NOT NULL)                                AS emitidos
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            INNER JOIN niveles   n ON n.id = g.nivel_id
            LEFT  JOIN ratificaciones r ON r.matricula_id = m.id
            LEFT  JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
            WHERE m.anio_id = ?
              " . self::sqlUniverso() . "
            GROUP BY s.id, s.nombre, g.nombre_display, n.nombre, n.id, g.numero
            ORDER BY n.id, g.numero, s.nombre
        ", [$anioId]);
    }

    /** Sección del año con su grado y nivel, o null. */
    public function seccion(int $seccionId, int $anioId): ?array
    {
        return $this->queryOne("
            SELECT s.id, s.nombre, s.anio_id,
                   g.nombre_display AS grado_nombre, g.numero AS grado_numero,
                   n.nombre AS nivel_nombre, n.codigo AS nivel_codigo
            FROM secciones s
            INNER JOIN grados  g ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            WHERE s.id = ? AND s.anio_id = ?
        ", [$seccionId, $anioId]);
    }

    /**
     * Estudiantes de una sección dentro del universo, en orden alfabético, con su
     * decisión (NULL = pendiente) y su emisión vigente, si la hay.
     */
    public function listarSeccion(int $seccionId): array
    {
        return $this->query("
            SELECT m.id AS matricula_id, m.estado AS matricula_estado,
                   p.dni, p.apellido_paterno, p.apellido_materno, p.nombres,
                   r.id AS ratificacion_id, r.decision, r.motivo_id, r.detalle,
                   rm.nombre AS motivo_nombre, rm.activo AS motivo_activo,
                   e.id AS emision_id, e.emitido_en
            FROM matriculas m
            INNER JOIN estudiantes e0 ON e0.id = m.estudiante_id
            INNER JOIN personas    p  ON p.id  = e0.persona_id
            INNER JOIN secciones   s  ON s.id  = m.seccion_id
            INNER JOIN grados      g  ON g.id  = s.grado_id
            INNER JOIN niveles     n  ON n.id  = g.nivel_id
            LEFT  JOIN ratificaciones       r  ON r.matricula_id = m.id
            LEFT  JOIN ratificacion_motivos rm ON rm.id = r.motivo_id
            LEFT  JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
            WHERE m.seccion_id = ?
              " . self::sqlUniverso() . "
            ORDER BY " . orden_alfabetico('p') . "
        ", [$seccionId]);
    }

    /**
     * Matrícula SI está en el universo (con su año y sección), o null. Es la
     * validación de servidor de toda escritura: una matrícula fuera del
     * universo no recibe decisión aunque se fuerce su id en el POST.
     */
    public function matriculaDelUniverso(int $matriculaId): ?array
    {
        return $this->queryOne("
            SELECT m.id, m.anio_id, m.seccion_id
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            INNER JOIN niveles   n ON n.id = g.nivel_id
            WHERE m.id = ?
              " . self::sqlUniverso() . "
        ", [$matriculaId]);
    }

    /** Decisión de una matrícula con su emisión vigente (null = pendiente). */
    public function decisionDe(int $matriculaId): ?array
    {
        return $this->queryOne("
            SELECT r.id, r.decision, r.motivo_id, r.detalle, e.id AS emision_id
            FROM ratificaciones r
            LEFT JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
            WHERE r.matricula_id = ?
        ", [$matriculaId]);
    }

    /**
     * Registra o cambia la decisión de una matrícula. Valida en servidor todo lo
     * que la pantalla ya valida en el navegador.
     *
     * Si la matrícula tiene un documento EMITIDO y la decisión cambia, la
     * emisión se ANULA en la misma transacción. Como el aviso del navegador
     * puede no ejecutarse, el cambio exige `$confirmaAnulacion`: sin él se
     * rechaza, y no se anula nada a ciegas.
     *
     * @return bool false si no había nada que cambiar
     * @throws \DomainException con el motivo del rechazo (texto para el usuario)
     */
    public function guardarDecision(
        int $matriculaId,
        string $decision,
        ?int $motivoId,
        ?string $detalle,
        int $userId,
        bool $confirmaAnulacion
    ): bool {
        $mat = $this->matriculaDelUniverso($matriculaId);
        if ($mat === null) {
            throw new \DomainException('Este estudiante no está en la lista de ratificación.');
        }
        if (!in_array($decision, self::DECISIONES, true)) {
            throw new \DomainException('Elige si el estudiante es ratificado o no ratificado.');
        }

        $detalle = $detalle !== null ? trim($detalle) : null;
        if ($decision === 'ratificado') {
            // Un ratificado no lleva motivo ni detalle (también lo exige el CHECK).
            $motivoId = null;
            $detalle  = null;
        } else {
            if (!$motivoId) {
                throw new \DomainException('Elige el motivo de la no ratificación.');
            }
            $detalle = ($detalle === '') ? null : $detalle;
            if ($detalle !== null && mb_strlen($detalle) > self::DETALLE_MAX) {
                throw new \DomainException('El detalle no debe superar ' . self::DETALLE_MAX . ' caracteres.');
            }
        }

        $actual = $this->decisionDe($matriculaId);

        if ($decision === 'no_ratificado') {
            // El motivo debe ser del MISMO año. Uno retirado solo se acepta si es
            // el que ya tenía (no se le cambia a nadie por haberlo retirado).
            $motivo = $this->queryOne(
                "SELECT id, activo FROM ratificacion_motivos WHERE id = ? AND anio_id = ?",
                [$motivoId, (int) $mat['anio_id']]
            );
            $esElQueTenia = $actual !== null && (int) ($actual['motivo_id'] ?? 0) === $motivoId;
            if ($motivo === null || (!(int) $motivo['activo'] && !$esElQueTenia)) {
                throw new \DomainException('El motivo elegido no está disponible.');
            }
        }

        if ($actual !== null
            && $actual['decision'] === $decision
            && (int) ($actual['motivo_id'] ?? 0) === (int) ($motivoId ?? 0)
            && ($actual['detalle'] ?? null) === $detalle
        ) {
            return false;
        }

        $tieneEmision = $actual !== null && $actual['emision_id'] !== null;
        if ($tieneEmision && !$confirmaAnulacion) {
            throw new \DomainException(
                'Este estudiante ya tiene un documento emitido. Confirma el cambio para continuar.'
            );
        }

        // Transacción propia solo si el llamador no abrió una (la conexión es
        // compartida y PDO no anida), como `AuxiliarSeccionModel`: así un
        // verificador lo envuelve todo en la suya y revierte, sin DELETE.
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            if ($actual === null) {
                $this->execute("
                    INSERT INTO ratificaciones (matricula_id, decision, motivo_id, detalle, registrado_por)
                    VALUES (?, ?, ?, ?, ?)
                ", [$matriculaId, $decision, $motivoId, $detalle, $userId]);
            } else {
                if ($tieneEmision) {
                    $this->anularEmision((int) $actual['emision_id'], $userId);
                }
                $this->execute("
                    UPDATE ratificaciones
                    SET decision = ?, motivo_id = ?, detalle = ?,
                        actualizado_por = ?, actualizado_en = NOW()
                    WHERE id = ?
                ", [$decision, $motivoId, $detalle, $userId, (int) $actual['id']]);
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
        return true;
    }

    /**
     * Anula una emisión VIGENTE. No abre transacción propia: la llama quien ya
     * la tiene abierta (cambio de decisión) o la acción de anular.
     * Con `vigente = NULL` la decisión queda libre para una emisión nueva
     * (UNIQUE `uq_rate_vigente`); el QR de la anulada pasa a decir «anulado».
     */
    public function anularEmision(int $emisionId, int $userId): void
    {
        $this->execute("
            UPDATE ratificacion_emisiones
            SET vigente = NULL, anulado_por = ?, anulado_en = NOW()
            WHERE id = ? AND vigente = 1
        ", [$userId, $emisionId]);
    }
}
