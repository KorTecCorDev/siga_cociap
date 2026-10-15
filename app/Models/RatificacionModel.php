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
                // Mismo candado que `emitir`: si entretanto se emitió o anuló el
                // documento, la confirmación que trae el usuario ya no aplica.
                $ahora = $this->queryOne("
                    SELECT e.id AS emision_id
                    FROM ratificaciones r
                    LEFT JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
                    WHERE r.id = ?
                    FOR UPDATE
                ", [(int) $actual['id']]);
                if (($ahora['emision_id'] ?? null) !== $actual['emision_id']) {
                    throw new \DomainException('Los datos de este estudiante cambiaron mientras los editabas. Vuelve a intentarlo.');
                }
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

    // ── Emisión (Fase 3) ────────────────────────────────────────

    /**
     * EMITE el documento de una matrícula: lo que después se imprime y lleva QR.
     *
     *  - Solo con decisión registrada (pendiente = sin documento) y sin otra
     *    emisión vigente (también lo frena el UNIQUE `uq_rate_vigente`).
     *  - Usa la plantilla VIGENTE del año y tipo y la CONGELA bajo el mismo
     *    candado que `RatificacionPlantillaModel::guardar()`: editarla después
     *    crea una versión nueva y esta emisión conserva la suya.
     *  - Copia el texto oficial del motivo y el detalle: editar el catálogo no
     *    reescribe un documento entregado.
     *  - Token propio de 32 hex. NUNCA `matriculas.token_acceso` (abre la boleta).
     *
     * @throws \DomainException con el motivo del rechazo (texto para el usuario)
     */
    public function emitir(int $matriculaId, int $userId): void
    {
        if ($this->matriculaDelUniverso($matriculaId) === null) {
            throw new \DomainException('Este estudiante no está en la lista de ratificación.');
        }

        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $plantillas = [];
            $this->emitirEnTransaccion($matriculaId, $userId, $plantillas);
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
     * Emite a TODA la sección: a quienes tienen decisión y aún no tienen
     * documento vigente. Los pendientes nunca se emiten. Si falta la plantilla
     * de un tipo, se emite a los demás y se informa a cuántos no (decisión del
     * usuario, 15/10/2026). Todo en una transacción.
     *
     * @return array{emitidos:int, sin_plantilla:array<string,int>} sin_plantilla: tipo => cuántos
     */
    public function emitirSeccion(int $seccionId, int $userId): array
    {
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $porEmitir = $this->query("
                SELECT m.id AS matricula_id, m.anio_id, r.decision
                FROM matriculas m
                INNER JOIN secciones s ON s.id = m.seccion_id
                INNER JOIN grados    g ON g.id = s.grado_id
                INNER JOIN niveles   n ON n.id = g.nivel_id
                INNER JOIN ratificaciones r ON r.matricula_id = m.id
                LEFT  JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
                WHERE m.seccion_id = ? AND e.id IS NULL
                  " . self::sqlUniverso() . "
            ", [$seccionId]);

            $emitidos     = 0;
            $sinPlantilla = [];
            $plantillas   = [];
            foreach ($porEmitir as $fila) {
                $tipo = (string) $fila['decision'];
                if (!$this->hayPlantilla((int) $fila['anio_id'], $tipo)) {
                    $sinPlantilla[$tipo] = ($sinPlantilla[$tipo] ?? 0) + 1;
                    continue;
                }
                $this->emitirEnTransaccion((int) $fila['matricula_id'], $userId, $plantillas);
                $emitidos++;
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
        return ['emitidos' => $emitidos, 'sin_plantilla' => $sinPlantilla];
    }

    /** ¿El año tiene alguna plantilla del tipo? (el lote salta a quien no). */
    private function hayPlantilla(int $anioId, string $tipo): bool
    {
        return $this->queryOne(
            "SELECT 1 FROM ratificacion_plantillas WHERE anio_id = ? AND tipo = ? LIMIT 1",
            [$anioId, $tipo]
        ) !== null;
    }

    /**
     * Núcleo de la emisión, dentro de una transacción ya abierta. `$plantillas`
     * guarda la plantilla ya bloqueada y congelada de cada tipo (lote).
     *
     * @throws \DomainException también si el año no tiene plantilla del tipo
     */
    private function emitirEnTransaccion(int $matriculaId, int $userId, array &$plantillas): void
    {
        // Candado sobre la decisión: `guardarDecision` toma el mismo, así un
        // cambio y una emisión simultáneos no se cruzan.
        $rat = $this->queryOne("
            SELECT r.id, r.decision, r.detalle, m.anio_id, rm.texto_oficial
            FROM ratificaciones r
            INNER JOIN matriculas m ON m.id = r.matricula_id
            LEFT  JOIN ratificacion_motivos rm ON rm.id = r.motivo_id
            WHERE r.matricula_id = ?
            FOR UPDATE
        ", [$matriculaId]);
        if ($rat === null) {
            throw new \DomainException('Registra primero la decisión del estudiante.');
        }
        $vigente = $this->queryOne(
            "SELECT id FROM ratificacion_emisiones WHERE ratificacion_id = ? AND vigente = 1",
            [(int) $rat['id']]
        );
        if ($vigente !== null) {
            throw new \DomainException('Este estudiante ya tiene su documento emitido.');
        }

        $tipo = (string) $rat['decision'];
        if (!isset($plantillas[$tipo])) {
            // Mismo candado que RatificacionPlantillaModel::guardar().
            $plantilla = $this->queryOne("
                SELECT id, congelada_en
                FROM ratificacion_plantillas
                WHERE anio_id = ? AND tipo = ?
                ORDER BY version DESC
                LIMIT 1
                FOR UPDATE
            ", [(int) $rat['anio_id'], $tipo]);
            if ($plantilla === null) {
                throw new \DomainException(
                    $tipo === 'ratificado'
                        ? 'Antes de emitir, prepara la plantilla del documento de ratificados.'
                        : 'Antes de emitir, prepara la plantilla del documento de no ratificados.'
                );
            }
            if ($plantilla['congelada_en'] === null) {
                $this->execute(
                    "UPDATE ratificacion_plantillas SET congelada_en = NOW() WHERE id = ?",
                    [(int) $plantilla['id']]
                );
            }
            $plantillas[$tipo] = (int) $plantilla['id'];
        }

        $this->execute("
            INSERT INTO ratificacion_emisiones
                (ratificacion_id, plantilla_id, decision, motivo_texto, detalle_texto, token, emitido_por)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [
            (int) $rat['id'],
            $plantillas[$tipo],
            $tipo,
            $tipo === 'no_ratificado' ? (string) $rat['texto_oficial'] : null,
            $tipo === 'no_ratificado' ? $rat['detalle'] : null,
            bin2hex(random_bytes(16)),
            $userId,
        ]);
    }

    /**
     * Anula el documento VIGENTE de una matrícula (acción explícita). La
     * decisión se conserva: se puede volver a emitir, con un QR nuevo.
     *
     * @throws \DomainException si no hay documento vigente
     */
    public function anular(int $matriculaId, int $userId): void
    {
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $fila = $this->queryOne("
                SELECT r.id, e.id AS emision_id
                FROM ratificaciones r
                LEFT JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
                WHERE r.matricula_id = ?
                FOR UPDATE
            ", [$matriculaId]);
            if ($fila === null || $fila['emision_id'] === null) {
                throw new \DomainException('Este estudiante no tiene un documento emitido.');
            }
            $this->anularEmision((int) $fila['emision_id'], $userId);
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
     * Todo lo que el DOCUMENTO de una emisión necesita, o null. Los datos del
     * estudiante, su grado y el apoderado se leen AHORA (decisión del usuario,
     * 14/10/2026: una reimpresión sale con los datos corregidos); el motivo y
     * el detalle salen de la copia guardada al emitir.
     */
    public function emisionParaDocumento(int $emisionId): ?array
    {
        return $this->queryOne("
            SELECT e.id, e.plantilla_id, e.decision, e.motivo_texto, e.detalle_texto,
                   e.token, e.vigente, e.emitido_en, e.anulado_en,
                   m.id AS matricula_id, m.anio_id, m.seccion_id, a.anio,
                   p.dni,
                   CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS estudiante,
                   s.nombre AS seccion_nombre, g.nombre_display AS grado_nombre,
                   n.nombre AS nivel_nombre, n.codigo AS nivel_codigo,
                   CONCAT(pa.apellido_paterno, ' ', pa.apellido_materno, ', ', pa.nombres) AS apoderado,
                   pa.dni AS apoderado_dni, vf.tipo_vinculo
            FROM ratificacion_emisiones e
            INNER JOIN ratificaciones   r ON r.id = e.ratificacion_id
            INNER JOIN matriculas       m ON m.id = r.matricula_id
            INNER JOIN anios_academicos a ON a.id = m.anio_id
            INNER JOIN estudiantes     es ON es.id = m.estudiante_id
            INNER JOIN personas         p ON p.id = es.persona_id
            INNER JOIN secciones        s ON s.id = m.seccion_id
            INNER JOIN grados           g ON g.id = s.grado_id
            INNER JOIN niveles          n ON n.id = g.nivel_id
            -- El apoderado RESPONSABLE (uno por estudiante; sin él, NULL y el
            -- documento deja sus datos en blanco para llenarlos a mano).
            LEFT  JOIN vinculo_familiar vf ON vf.id = (
                       SELECT v2.id FROM vinculo_familiar v2
                       WHERE v2.estudiante_id = m.estudiante_id AND v2.es_responsable = 1
                       ORDER BY v2.id LIMIT 1)
            LEFT  JOIN apoderados      ap ON ap.id = vf.apoderado_id
            LEFT  JOIN personas        pa ON pa.id = ap.persona_id
            WHERE e.id = ?
        ", [$emisionId]);
    }

    /**
     * Emisiones VIGENTES de una sección (dentro del universo), en orden
     * alfabético: lo que entra al lote de PDF (Fase 4).
     *
     * @return list<int> ids de emisión
     */
    public function emisionesVigentesDeSeccion(int $seccionId): array
    {
        $filas = $this->query("
            SELECT e.id
            FROM matriculas m
            INNER JOIN estudiantes es ON es.id = m.estudiante_id
            INNER JOIN personas    p  ON p.id  = es.persona_id
            INNER JOIN secciones   s  ON s.id  = m.seccion_id
            INNER JOIN grados      g  ON g.id  = s.grado_id
            INNER JOIN niveles     n  ON n.id  = g.nivel_id
            INNER JOIN ratificaciones r ON r.matricula_id = m.id
            INNER JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
            WHERE m.seccion_id = ?
              " . self::sqlUniverso() . "
            ORDER BY " . orden_alfabetico('p') . "
        ", [$seccionId]);
        return array_map(static fn(array $f): int => (int) $f['id'], $filas);
    }

    /**
     * AVISO al matricular (Fase 4): si el estudiante tiene un documento de NO
     * ratificación VIGENTE del año anterior (por número de año, nunca por id),
     * devuelve { anio, emitido_en }; si no, null. No bloquea nada: solo informa.
     */
    public function avisoNoRatificado(int $estudianteId, int $anioActual): ?array
    {
        return $this->queryOne("
            SELECT a.anio, e.emitido_en
            FROM ratificacion_emisiones e
            INNER JOIN ratificaciones   r ON r.id = e.ratificacion_id
            INNER JOIN matriculas       m ON m.id = r.matricula_id
            INNER JOIN anios_academicos a ON a.id = m.anio_id
            WHERE m.estudiante_id = ? AND a.anio = ?
              AND e.vigente = 1 AND e.decision = 'no_ratificado'
            ORDER BY e.emitido_en DESC
            LIMIT 1
        ", [$estudianteId, $anioActual - 1]);
    }

    /**
     * Valores de las variables del párrafo para una emisión (ver VARIABLES).
     * `$etiquetasVinculo` traduce `tipo_vinculo` a su texto («tio» → «Tío»); es
     * el catálogo de `MatriculaController::tiposVinculo()`, que pasa el controlador.
     */
    public static function varsDeEmision(array $em, array $etiquetasVinculo = []): array
    {
        $vinculo = (string) ($em['tipo_vinculo'] ?? '');
        return [
            'estudiante'     => (string) $em['estudiante'],
            'dni'            => (string) $em['dni'],
            'grado'          => (string) $em['grado_nombre'],
            'seccion'        => (string) $em['seccion_nombre'],
            'nivel'          => (string) $em['nivel_nombre'],
            'anio'           => (string) $em['anio'],
            'anio_siguiente' => (string) ((int) $em['anio'] + 1),
            'apoderado'      => (string) ($em['apoderado'] ?? ''),
            'apoderado_dni'  => (string) ($em['apoderado_dni'] ?? ''),
            'parentesco'     => $vinculo === '' ? '' : (string) ($etiquetasVinculo[$vinculo] ?? ucfirst($vinculo)),
        ];
    }

    /**
     * Verificación PÚBLICA por el token del QR: SOLO autenticidad (nombre, año,
     * fecha de emisión y si sigue vigente). Nunca DNI, tipo ni motivo.
     * Null si el token no existe.
     */
    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return $this->queryOne("
            SELECT CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS estudiante,
                   a.anio, e.emitido_en, e.vigente, e.anulado_en
            FROM ratificacion_emisiones e
            INNER JOIN ratificaciones   r ON r.id = e.ratificacion_id
            INNER JOIN matriculas       m ON m.id = r.matricula_id
            INNER JOIN anios_academicos a ON a.id = m.anio_id
            INNER JOIN estudiantes     es ON es.id = m.estudiante_id
            INNER JOIN personas         p ON p.id = es.persona_id
            WHERE e.token = ?
        ", [$token]);
    }
}

