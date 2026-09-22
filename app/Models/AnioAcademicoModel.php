<?php

namespace App\Models;

/**
 * AnioAcademicoModel
 * Gestiona años académicos y sus bimestres (periodos):
 * creación, activación, cierre y los indicadores de cierre de cada bimestre.
 */
class AnioAcademicoModel extends BaseModel
{
    protected string $table = 'anios_academicos';

    /**
     * Plantilla de bimestres por defecto para un año nuevo.
     * Fechas referenciales del calendario escolar peruano (editables después).
     * [numero, nombre_display, inicio (MM-DD), fin (MM-DD)]
     */
    private const BIMESTRES_DEFAULT = [
        [1, 'I Bimestre',   '03-01', '05-15'],
        [2, 'II Bimestre',  '05-18', '07-17'],
        [3, 'III Bimestre', '08-03', '10-02'],
        [4, 'IV Bimestre',  '10-05', '12-18'],
    ];

    /**
     * Memo de getEvolucionAnual() por año. El verificador de superficies de
     * Direccion reutiliza UNA sola instancia de este modelo a lo largo de su
     * bucle de periodos, asi que sin esto recalcularia la misma serie una vez
     * por bimestre del mismo año.
     */
    private array $cacheEvolucion = [];

    // ── Años ──────────────────────────────────────────────────

    /** Lista todos los años con el conteo de bimestres por estado. */
    public function listarAnios(): array
    {
        return $this->query("
            SELECT
                a.*,
                (SELECT COUNT(*) FROM periodos p WHERE p.anio_id = a.id)                          AS total_bimestres,
                (SELECT COUNT(*) FROM periodos p WHERE p.anio_id = a.id AND p.estado = 'activo')   AS bimestres_activos,
                (SELECT COUNT(*) FROM periodos p WHERE p.anio_id = a.id AND p.estado = 'cerrado')  AS bimestres_cerrados
            FROM anios_academicos a
            ORDER BY a.anio DESC
        ");
    }

    /** Verifica si ya existe un año con ese valor. */
    public function existeAnio(int $anio): bool
    {
        return $this->findBy('anio', $anio) !== null;
    }

    /**
     * Crea un año académico nuevo con sus 4 bimestres por defecto.
     * Solo genera el año y los periodos: no toca secciones ni cargas.
     * Retorna el ID del año creado.
     */
    public function crearAnio(int $anio): int
    {
        $this->beginTransaction();
        try {
            $this->execute("
                INSERT INTO anios_academicos (anio, fecha_inicio, fecha_fin, estado)
                VALUES (?, ?, ?, 'planificado')
            ", [
                $anio,
                sprintf('%d-03-01', $anio),
                sprintf('%d-12-18', $anio),
            ]);
            $anioId = (int) $this->db->lastInsertId();

            foreach (self::BIMESTRES_DEFAULT as [$numero, $nombre, $inicio, $fin]) {
                $this->execute("
                    INSERT INTO periodos
                        (anio_id, numero, tipo, nombre_display,
                         fecha_inicio, fecha_fin, limite_notas, estado)
                    VALUES (?, ?, 'bimestre', ?, ?, ?, NULL, 'pendiente')
                ", [
                    $anioId,
                    $numero,
                    $nombre,
                    sprintf('%d-%s', $anio, $inicio),
                    sprintf('%d-%s', $anio, $fin),
                ]);
            }

            $this->commit();
            return $anioId;
        } catch (\Exception $e) {
            $this->rollback();
            log_error('Error creando año académico', [
                'anio'  => $anio,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Activa un año académico. Solo puede haber uno activo a la vez:
     * cualquier otro año en estado 'activo' se cierra.
     */
    public function activarAnio(int $id): void
    {
        $this->beginTransaction();
        try {
            $this->execute("
                UPDATE anios_academicos
                SET estado = 'cerrado'
                WHERE estado = 'activo' AND id != ?
            ", [$id]);

            $this->execute("
                UPDATE anios_academicos SET estado = 'activo' WHERE id = ?
            ", [$id]);

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            log_error('Error activando año académico', ['id' => $id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Cierra un año académico. */
    public function cerrarAnio(int $id): bool
    {
        return $this->execute("
            UPDATE anios_academicos SET estado = 'cerrado' WHERE id = ?
        ", [$id]);
    }

    // ── Bimestres (periodos) ──────────────────────────────────

    /** Lista los bimestres de un año, ordenados por número. */
    public function getPeriodos(int $anioId): array
    {
        return $this->query("
            SELECT p.*
            FROM periodos p
            WHERE p.anio_id = ?
            ORDER BY p.numero
        ", [$anioId]);
    }

    /** Obtiene un bimestre con datos de su año. */
    public function getPeriodo(int $id): ?array
    {
        return $this->queryOne("
            SELECT p.*, a.anio, a.estado AS anio_estado
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE p.id = ?
        ", [$id]);
    }

    /** Indica si el año tiene algún bimestre activo distinto del indicado. */
    public function tieneBimestreActivo(int $anioId, int $exceptoPeriodoId = 0): bool
    {
        $fila = $this->queryOne("
            SELECT id FROM periodos
            WHERE anio_id = ? AND estado = 'activo' AND id != ?
            LIMIT 1
        ", [$anioId, $exceptoPeriodoId]);

        return $fila !== null;
    }

    /**
     * ¿Existe algún bimestre ANTERIOR (numero menor) que aún no se ha abierto
     * (estado 'pendiente')? Se usa para forzar la apertura en orden cronológico
     * (B1 → B2 → B3 → B4) y evitar que se abra un bimestre futuro saltándose uno
     * previo, lo que distorsionaba el "orden de mérito vigente".
     */
    public function hayBimestrePrevioPendiente(int $anioId, int $numero): bool
    {
        $fila = $this->queryOne("
            SELECT id FROM periodos
            WHERE anio_id = ? AND numero < ? AND estado = 'pendiente'
            LIMIT 1
        ", [$anioId, $numero]);

        return $fila !== null;
    }

    /** Actualiza las fechas y la fecha límite de notas de un bimestre. */
    public function actualizarFechasPeriodo(
        int $id,
        string $fechaInicio,
        string $fechaFin,
        ?string $limiteNotas
    ): bool {
        return $this->execute("
            UPDATE periodos
            SET fecha_inicio = ?, fecha_fin = ?, limite_notas = ?
            WHERE id = ?
        ", [$fechaInicio, $fechaFin, $limiteNotas, $id]);
    }

    /** Cambia el estado de un bimestre. */
    public function setEstadoPeriodo(int $id, string $estado): bool
    {
        return $this->execute("
            UPDATE periodos SET estado = ? WHERE id = ?
        ", [$estado, $id]);
    }

    /**
     * Bloquea automáticamente todas las competencias del periodo que aún
     * no estén bloqueadas (cierre forzado del bimestre). Se bloquean con lo
     * que tengan, aunque no tengan notas. Idempotente (INSERT IGNORE sobre
     * la clave única uq_bloqueo). Retorna cuántas se bloquearon en esta llamada.
     */
    public function bloquearCompetenciasPendientes(int $periodoId, int $usuarioId): int
    {
        // 1) Competencias PROPIAS de cada carga activa (por su subarea/area).
        $stmtPropias = $this->db->prepare("
            INSERT IGNORE INTO bloqueos_competencia
                (carga_id, competencia_id, periodo_id, bloqueado_por, origen)
            SELECT ca.id, comp.id, ?, ?, 'cierre'
            FROM cargas_academicas ca
            INNER JOIN competencias comp ON (
                (ca.subarea_id IS NOT NULL AND comp.subarea_id = ca.subarea_id)
                OR
                (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND comp.area_id = ca.area_id)
            )
            WHERE ca.estado  = 'activa'
              AND ca.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
        ");
        $stmtPropias->execute([$periodoId, $usuarioId, $periodoId]);

        // 2) Competencias TRANSVERSALES (TIC/GAMA): viven en un area
        //    tipo='transversal' del NIVEL de la carga (carga -> seccion -> grado
        //    -> nivel), distinta del area propia, por eso el JOIN de arriba no
        //    las alcanza. Cada docente las registra en su propia carga, asi que
        //    se bloquean por carga igual que en la aprobacion del docente
        //    (Variante 1). Sin esto el cierre dejaba las TIC/GAMA sin bloquear.
        //
        //    ⚠️ EL UNIVERSO DEBE SER EL MISMO QUE EL DEL FORMULARIO DEL DOCENTE
        //    (CalificacionController::calificaciones). Hasta el 06/08/2026 este
        //    SELECT recorria TODAS las cargas activas sin las dos exclusiones que
        //    el formulario si aplica, e inventaba bloqueos en cargas que ningun
        //    docente puede llegar a bloquear: en B2 fueron 130 competencias en 65
        //    cargas (46 en TOE + 84 en no-dueñas) que /admin/control reportaba como
        //    "el docente no las bloqueo", acusando a 23 docentes de un olvido
        //    inexistente (olvidos reales medidos: CERO). Limpieza de lo ya creado:
        //    migracion 051.
        //
        //    REGLA DE "CARGA DUEÑA" — ESCRITA EN CUATRO SITIOS (mantenerlos en
        //    sync; si cambia uno, revisar los otros tres):
        //      1. CalificacionController::calificaciones  (formulario del docente)
        //      2. TransversalModel::estadoCargasSeccion   (gate del cierre del tutor)
        //      3. CalificacionModel::cargaDuenaTransversales
        //      4. AQUI                                    (cierre forzado)
        $stmtTrans = $this->db->prepare("
            INSERT IGNORE INTO bloqueos_competencia
                (carga_id, competencia_id, periodo_id, bloqueado_por, origen)
            SELECT ca.id, comp.id, ?, ?, 'cierre'
            FROM cargas_academicas ca
            INNER JOIN secciones s ON s.id = ca.seccion_id
            INNER JOIN grados    g ON g.id = s.grado_id
            INNER JOIN areas     a ON a.tipo = 'transversal' AND a.nivel_id = g.nivel_id
            INNER JOIN competencias comp ON comp.area_id = a.id
            LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
            LEFT  JOIN areas    ar ON ar.id = COALESCE(ca.area_id, sa.area_id)
            WHERE ca.estado  = 'activa'
              AND ca.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
              -- (A) La carga de TUTORIA (Etica y Valores) NO lleva transversales:
              --     el formulario no se las adjunta (decision 07/07/2026).
              AND (ar.tipo IS NULL OR ar.tipo <> 'tutoria')
              -- (B) UNIDOCENTE: las TIC/GAMA se registran UNA sola vez por area,
              --     en la carga dueña (subarea de menor orden). Las demas cargas
              --     del area nunca las reciben en el formulario.
              AND (s.es_unidocente = 0 OR ca.id = (
                    SELECT cad.id
                    FROM cargas_academicas cad
                    LEFT JOIN subareas sad ON sad.id = cad.subarea_id
                    WHERE cad.seccion_id = ca.seccion_id
                      AND cad.estado     = 'activa'
                      AND COALESCE(cad.area_id, sad.area_id) = COALESCE(ca.area_id, sa.area_id)
                    ORDER BY COALESCE(sad.orden, 0), cad.id
                    LIMIT 1
              ))
        ");
        $stmtTrans->execute([$periodoId, $usuarioId, $periodoId]);

        return $stmtPropias->rowCount() + $stmtTrans->rowCount();
    }

    /**
     * Crea el cierre transversal (cierres_transversales) de cada seccion con
     * cargas activas que aun NO tenga uno vigente, para que las TIC/GAMA queden
     * agregadas y visibles en boleta tras el cierre forzado (getTransversalesAgregadas
     * exige cierre vigente). Idempotente: NO duplica los cierres que el tutor ya
     * hizo (los respeta por el NOT EXISTS sobre anulado_en IS NULL). El cleanup
     * manual del panel de bloqueos los anula via seccionesConBloqueosDeCierre.
     * Retorna cuantos cierres creo.
     */
    public function crearCierresTransversalesPendientes(int $periodoId, int $usuarioId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO cierres_transversales (seccion_id, periodo_id, cerrado_por)
            SELECT DISTINCT s.id, ?, ?
            FROM secciones s
            INNER JOIN cargas_academicas ca
                ON ca.seccion_id = s.id AND ca.estado = 'activa'
            WHERE s.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
              AND NOT EXISTS (
                  SELECT 1 FROM cierres_transversales ct
                  WHERE ct.seccion_id = s.id
                    AND ct.periodo_id = ?
                    AND ct.anulado_en IS NULL
              )
        ");
        $stmt->execute([$periodoId, $usuarioId, $periodoId, $periodoId]);
        return $stmt->rowCount();
    }

    /**
     * HITO A del cierre de bimestre — "Bloquear y aprobar el bimestre": fuerza el
     * bloqueo de las competencias pendientes (deja traza de INCIDENCIAS via
     * bloqueos con origen='cierre'), crea los cierres transversales pendientes y
     * marca el bimestre con boletas en BORRADOR (vista previa para los docentes).
     * El periodo SIGUE 'activo' (el cierre definitivo es el Hito B). Retorna
     * cuantas competencias se forzaron (numero de incidencias generadas).
     */
    public function aprobarBoletasBimestre(int $periodoId, int $usuarioId): int
    {
        $forzadas = $this->bloquearCompetenciasPendientes($periodoId, $usuarioId);
        $this->crearCierresTransversalesPendientes($periodoId, $usuarioId);
        $this->execute(
            "UPDATE periodos SET boletas_aprobadas_en = NOW(), boletas_aprobadas_por = ?
             WHERE id = ?",
            [$usuarioId, $periodoId]
        );
        return $forzadas;
    }

    /**
     * Revierte el HITO A (BORRADOR -> EN REGISTRO). Solo si el periodo sigue
     * 'activo' (un bimestre ya cerrado no se "des-aprueba" aqui: se reabre). NO
     * toca los bloqueos: los forzados se liberan desde el panel de bloqueos.
     */
    public function anularAprobacionBoletas(int $periodoId): bool
    {
        return $this->execute(
            "UPDATE periodos SET boletas_aprobadas_en = NULL, boletas_aprobadas_por = NULL
             WHERE id = ? AND estado = 'activo'",
            [$periodoId]
        );
    }

    /**
     * Marca el bimestre con boletas aprobadas SIN pisar el dato si ya venia del
     * Hito A. Lo invoca el cierre (Hito B): el estado 'cerrado' ya hace oficiales
     * las boletas, pero dejar el flag puesto asegura que, si luego se REABRE el
     * bimestre, el estado de boleta derive a BORRADOR (no a 'registro') y los
     * padres dejen de verlo hasta re-cerrarlo.
     */
    public function marcarBoletasAprobadas(int $periodoId, int $usuarioId): bool
    {
        return $this->execute(
            "UPDATE periodos
             SET boletas_aprobadas_en  = COALESCE(boletas_aprobadas_en, NOW()),
                 boletas_aprobadas_por = COALESCE(boletas_aprobadas_por, ?)
             WHERE id = ?",
            [$usuarioId, $periodoId]
        );
    }

    /**
     * Elimina los bloqueos del CIERRE FORZADO de un periodo (origen='cierre'):
     * las competencias que el docente nunca aprobó y que el cierre del bimestre
     * bloqueó automáticamente. Se invoca SOLO de forma manual desde el panel de
     * bloqueos (NO al reabrir). Los bloqueos del docente (origen='docente'),
     * incluidas las competencias finalizadas-vacías, se conservan siempre.
     * Retorna cuántos se eliminaron.
     *
     * Se CONSERVAN los de competencias con calificaciones extraordinarias
     * (18/09/2026, `CalificacionModel::SIN_EXTRAORDINARIAS_BC`): son justo las
     * «No se evaluó» que RA completa, y liberarlas mezclaría su nota con las
     * del docente. `bloqueosDeCierreConExtraordinarias` dice cuántas quedaron.
     */
    public function eliminarBloqueosDeCierre(int $periodoId): int
    {
        $stmt = $this->db->prepare("
            DELETE bc FROM bloqueos_competencia bc
            WHERE bc.periodo_id = ?
              AND bc.origen     = 'cierre'
              AND " . CalificacionModel::SIN_EXTRAORDINARIAS_BC . "
        ");
        $stmt->execute([$periodoId]);
        return $stmt->rowCount();
    }

    /**
     * Bloqueos del cierre forzado que `eliminarBloqueosDeCierre` NO libera
     * porque su competencia tiene calificaciones extraordinarias.
     */
    public function bloqueosDeCierreConExtraordinarias(int $periodoId): int
    {
        $r = $this->queryOne("
            SELECT COUNT(*) AS n
            FROM bloqueos_competencia bc
            WHERE bc.periodo_id = ?
              AND bc.origen     = 'cierre'
              AND NOT " . CalificacionModel::SIN_EXTRAORDINARIAS_BC . "
        ", [$periodoId]);
        return (int) ($r['n'] ?? 0);
    }

    /**
     * Registra una reapertura de bimestre en la bitácora de auditoría.
     * El motivo es obligatorio (lo valida el controlador). Guarda también
     * cuántos bloqueos sin notas se liberaron en esa reapertura.
     */
    public function registrarReapertura(
        int $periodoId,
        string $motivo,
        int $usuarioId,
        int $bloqueosLiberados
    ): bool {
        return $this->execute("
            INSERT INTO reaperturas_periodo
                (periodo_id, motivo, bloqueos_liberados, reabierto_por)
            VALUES (?, ?, ?, ?)
        ", [$periodoId, $motivo, $bloqueosLiberados, $usuarioId]);
    }

    /** Historial de reaperturas de un bimestre, de la más reciente a la más antigua. */
    public function getReaperturas(int $periodoId): array
    {
        return $this->query("
            SELECT
                rp.*,
                CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS reabierto_por_nombre
            FROM reaperturas_periodo rp
            INNER JOIN usuarios u ON u.id = rp.reabierto_por
            INNER JOIN personas p ON p.id = u.persona_id
            WHERE rp.periodo_id = ?
            ORDER BY rp.reabierto_en DESC
        ", [$periodoId]);
    }

    // ── Indicadores de cierre ─────────────────────────────────

    /**
     * Indicadores de cierre de un bimestre:
     *  - por cada grado: primer puesto, los 2 de menor rendimiento, total de
     *    competidores y los estudiantes en riesgo (3 o más competencias en C)
     *  - top de docentes que bloquearon todas sus competencias más rápido
     *
     * 🔴 ES UNA FACHADA: el bloque por grado lo calcula
     * `OrdenMeritoModel::statsPorGrado`, el modelo DUEÑO del ranking. Hasta el
     * 04/09/2026 este método tenía aquí su propio `getRankingGrado`, una copia
     * simplificada del universo del mérito que devolvía cifras plausibles y
     * falsas en el bimestre abierto (el porqué, medido, está en el docblock de
     * `statsPorGrado`). Se conserva la firma y el shape para no tocar a sus tres
     * consumidores: `/admin/cuadros`, `/director/periodos/{id}/stats` y el modal
     * de cierre.
     *
     * NO escribir aquí ninguna consulta de ranking: sería reabrir la copia.
     */
    public function getStatsCierre(int $periodoId): array
    {
        return [
            'por_grado' => (new OrdenMeritoModel())->statsPorGrado($periodoId),
            'docentes'  => $this->getDocentesMasRapidos($periodoId),
            // El umbral viaja CON los datos para que la vista pueda rotularlo
            // sin importar el modelo ni hardcodear un 3 que quedaría mudo si la
            // constante cambia.
            'riesgo_min_c' => OrdenMeritoModel::RIESGO_MIN_C,
        ];
    }

    /**
     * Indicadores globales del bimestre, separados por nivel (Primaria/Secundaria):
     *  - distribución de literales AD/A/B/C (contando cada calificación de competencia)
     *  - % en logro (AD+A) vs en proceso/inicio (B+C)
     *  - estudiantes en riesgo (promedio general en C)
     *  - histograma de estudiantes según cuántas competencias tienen en C
     * Excluye competencias transversales, igual que el ranking.
     */
    public function getResumenBimestre(int $periodoId): array
    {
        // 1) Distribución de literales a nivel de calificación.
        $dist = $this->query("
            SELECT
                n.id     AS nivel_id,
                n.nombre AS nivel_nombre,
                n.codigo AS nivel_codigo,
                SUM(cal.nota_numerica >= " . NOTA_MIN_AD . ")                              AS ad,
                SUM(cal.nota_numerica >= " . NOTA_MIN_A . " AND cal.nota_numerica < " . NOTA_MIN_AD . ")   AS a,
                SUM(cal.nota_numerica >= " . NOTA_MIN_B . " AND cal.nota_numerica < " . NOTA_MIN_A . ")   AS b,
                SUM(cal.nota_numerica < " . NOTA_MIN_B . ")                               AS c,
                COUNT(*)                                                  AS total_calif
            FROM calificaciones cal
            INNER JOIN matriculas m      ON m.id    = cal.matricula_id
            INNER JOIN secciones s       ON s.id    = m.seccion_id
            INNER JOIN grados g          ON g.id    = s.grado_id
            INNER JOIN niveles n         ON n.id    = g.nivel_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa       ON sa.id   = comp.subarea_id
            INNER JOIN areas ar          ON ar.id   = COALESCE(sa.area_id, comp.area_id)
            WHERE cal.periodo_id = ?
              AND m.estado       = 'aprobada'
              AND ar.tipo       != 'transversal'
            GROUP BY n.id, n.nombre, n.codigo
            ORDER BY n.id
        ", [$periodoId]);

        // 2) Agregados por estudiante (promedio + nº de C) → riesgo e histograma.
        //
        // "En riesgo" y "nº de C" son la MISMA pregunta: estar por debajo de B. El
        // umbral sale de `NOTA_MIN_B` (helpers.php), igual que el bloque 1 de este
        // mismo método — hasta el 22/08/2026 estos dos estaban hardcodeados en 11 y
        // quedaban fuera del inventario de excepciones de CLAUDE.md, así que un
        // cambio de escala (ya hubo uno el 10/06) los habría desincronizado en
        // silencio mientras el resto del panel se movía.
        $alumnos = $this->query("
            SELECT
                n.id AS nivel_id,
                COUNT(*)                                    AS total_estudiantes,
                SUM(prom.promedio < " . NOTA_MIN_B . ")     AS en_riesgo,
                SUM(prom.num_c = 1)            AS c1,
                SUM(prom.num_c = 2)            AS c2,
                SUM(prom.num_c = 3)            AS c3,
                SUM(prom.num_c >= 4)           AS c4plus,
                SUM(prom.num_c >= 1)           AS con_c
            FROM (
                SELECT
                    m.id        AS matricula_id,
                    s.grado_id,
                    AVG(cal.nota_numerica)                          AS promedio,
                    SUM(cal.nota_numerica < " . NOTA_MIN_B . ")     AS num_c
                FROM calificaciones cal
                INNER JOIN matriculas m      ON m.id    = cal.matricula_id
                INNER JOIN secciones s       ON s.id    = m.seccion_id
                INNER JOIN competencias comp ON comp.id = cal.competencia_id
                LEFT  JOIN subareas sa       ON sa.id   = comp.subarea_id
                INNER JOIN areas ar          ON ar.id   = COALESCE(sa.area_id, comp.area_id)
                WHERE cal.periodo_id = ?
                  AND m.estado       = 'aprobada'
                  AND ar.tipo       != 'transversal'
                GROUP BY m.id, s.grado_id
            ) prom
            INNER JOIN grados g  ON g.id = prom.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            GROUP BY n.id
        ", [$periodoId]);

        // Indexar los agregados por estudiante por nivel.
        $porNivelAlumnos = [];
        foreach ($alumnos as $row) {
            $porNivelAlumnos[(int) $row['nivel_id']] = $row;
        }

        $niveles = [];
        foreach ($dist as $d) {
            $nivelId = (int) $d['nivel_id'];
            $total   = (int) $d['total_calif'];
            $ad = (int) $d['ad']; $a = (int) $d['a'];
            $b  = (int) $d['b'];  $c = (int) $d['c'];

            $pct = fn(int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0.0;
            $deg = fn(int $n): float => $total > 0 ? round($n / $total * 360, 2) : 0.0;

            // Cortes acumulados para el conic-gradient del donut.
            $degAd = $deg($ad);
            $degA  = $degAd + $deg($a);
            $degB  = $degA + $deg($b);

            $logro   = $ad + $a;
            $proceso = $b + $c;

            $alu = $porNivelAlumnos[$nivelId] ?? null;

            $niveles[] = [
                'nivel_id'          => $nivelId,
                'nivel_nombre'      => $d['nivel_nombre'],
                'nivel_codigo'      => $d['nivel_codigo'],
                'total_calif'       => $total,
                'ad' => $ad, 'a' => $a, 'b' => $b, 'c' => $c,
                'pct_ad' => $pct($ad), 'pct_a' => $pct($a),
                'pct_b'  => $pct($b),  'pct_c' => $pct($c),
                'deg_ad' => $degAd, 'deg_a' => $degA, 'deg_b' => $degB,
                'logro'        => $logro,
                'proceso'      => $proceso,
                'pct_logro'    => $pct($logro),
                'pct_proceso'  => $pct($proceso),
                'total_estudiantes' => (int) ($alu['total_estudiantes'] ?? 0),
                'en_riesgo'         => (int) ($alu['en_riesgo'] ?? 0),
                'con_c'             => (int) ($alu['con_c'] ?? 0),
                'hist' => [
                    'c1'     => (int) ($alu['c1']     ?? 0),
                    'c2'     => (int) ($alu['c2']     ?? 0),
                    'c3'     => (int) ($alu['c3']     ?? 0),
                    'c4plus' => (int) ($alu['c4plus'] ?? 0),
                ],
            ];
        }

        return ['niveles' => $niveles];
    }

    /**
     * Evolucion de la distribucion literal a lo largo de los bimestres de UN
     * AÑO, separada por nivel. Alimenta las lineas del tablero de Direccion
     * (/admin/cuadros), que necesita comparar bimestres entre si.
     *
     * 🔴 GEMELO del bloque 1 de getResumenBimestre(): MISMO universo
     * (m.estado='aprobada', ar.tipo != 'transversal', area efectiva via
     * COALESCE(sa.area_id, comp.area_id)) y MISMOS umbrales (NOTA_MIN_*).
     * Si uno cambia, cambia el otro: verif_direccion_superficies.php compara
     * celda a celda que ambos coincidan para el periodo seleccionado.
     *
     * NO se implementa como un bucle de getResumenBimestre() por periodo:
     * ese metodo hace 2 consultas (la segunda con tabla derivada + GROUP BY
     * m.id), o sea 8 por render, y ademas el controlador acabaria decidiendo
     * que periodos entran y como se rellenan los huecos — reglas de negocio
     * que pertenecen aqui.
     *
     * El eje X SIEMPRE trae los bimestres del año (incluidos los 'pendiente')
     * y cada serie viene rellena y paralela a el: Frappe Charts exige que
     * values.length === labels.length, y un hueco desplaza la linea entera
     * sin dar error.
     */
    public function getEvolucionAnual(int $anioId): array
    {
        if (isset($this->cacheEvolucion[$anioId])) {
            return $this->cacheEvolucion[$anioId];
        }

        // Eje X: los bimestres del año, en orden, existan o no sus notas.
        $periodos = $this->getPeriodos($anioId);

        // Distribucion literal por bimestre y nivel, de una sola pasada.
        //
        // Nota: NO se filtra `cal.nota_numerica IS NOT NULL` aunque parezca
        // correcto. COUNT(*) cuenta las filas con nota nula y SUM() no, asi
        // que ad+a+b+c puede ser < total_calif. Eso ya ocurre en el gemelo;
        // "arreglarlo" solo aqui haria que la linea de un bimestre no cuadre
        // con la fila de ese mismo bimestre en la tabla de al lado.
        $filas = $this->query("
            SELECT
                cal.periodo_id                                            AS periodo_id,
                n.id     AS nivel_id,
                n.nombre AS nivel_nombre,
                n.codigo AS nivel_codigo,
                SUM(cal.nota_numerica >= " . NOTA_MIN_AD . ")                              AS ad,
                SUM(cal.nota_numerica >= " . NOTA_MIN_A . " AND cal.nota_numerica < " . NOTA_MIN_AD . ")   AS a,
                SUM(cal.nota_numerica >= " . NOTA_MIN_B . " AND cal.nota_numerica < " . NOTA_MIN_A . ")   AS b,
                SUM(cal.nota_numerica < " . NOTA_MIN_B . ")                               AS c,
                COUNT(*)                                                  AS total_calif
            FROM calificaciones cal
            INNER JOIN periodos p        ON p.id    = cal.periodo_id
            INNER JOIN matriculas m      ON m.id    = cal.matricula_id
            INNER JOIN secciones s       ON s.id    = m.seccion_id
            INNER JOIN grados g          ON g.id    = s.grado_id
            INNER JOIN niveles n         ON n.id    = g.nivel_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN subareas sa       ON sa.id   = comp.subarea_id
            INNER JOIN areas ar          ON ar.id   = COALESCE(sa.area_id, comp.area_id)
            WHERE p.anio_id  = ?
              AND m.estado   = 'aprobada'
              AND ar.tipo   != 'transversal'
            GROUP BY cal.periodo_id, n.id, n.nombre, n.codigo
            ORDER BY n.id
        ", [$anioId]);

        // Indexar por nivel y, dentro, por periodo.
        $porNivel      = [];
        $periodosConDato = [];
        foreach ($filas as $f) {
            $nivelId   = (int) $f['nivel_id'];
            $periodoId = (int) $f['periodo_id'];

            if (!isset($porNivel[$nivelId])) {
                $porNivel[$nivelId] = [
                    'nivel_id'     => $nivelId,
                    'nivel_nombre' => $f['nivel_nombre'],
                    'nivel_codigo' => $f['nivel_codigo'],
                    'celdas'       => [],
                ];
            }
            $porNivel[$nivelId]['celdas'][$periodoId] = $f;
            $periodosConDato[$periodoId] = true;
        }

        $ejeX = [];
        foreach ($periodos as $p) {
            $ejeX[] = [
                'id'         => (int) $p['id'],
                'numero'     => (int) $p['numero'],
                'nombre'     => $p['nombre_display'],
                'estado'     => $p['estado'],
                'con_datos'  => isset($periodosConDato[(int) $p['id']]),
            ];
        }

        // Serie rellena y paralela al eje X. El relleno es 0 (un hecho: cero
        // calificaciones), NO null: que un bimestre vacio se pinte como hueco
        // o como cero es decision de la vista, no del modelo.
        $niveles = [];
        foreach ($porNivel as $nivel) {
            $serie = [];
            foreach ($ejeX as $p) {
                $f = $nivel['celdas'][$p['id']] ?? null;

                $total = (int) ($f['total_calif'] ?? 0);
                $ad    = (int) ($f['ad'] ?? 0);
                $a     = (int) ($f['a']  ?? 0);
                $b     = (int) ($f['b']  ?? 0);
                $c     = (int) ($f['c']  ?? 0);

                // Misma guarda que el gemelo: en SQL x/0 da NULL y arrastraria
                // nulos hasta el JSON.
                $pct = fn(int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0.0;

                $logro   = $ad + $a;
                $proceso = $b + $c;

                $serie[] = [
                    'periodo_id'   => $p['id'],
                    'total_calif'  => $total,
                    'ad' => $ad, 'a' => $a, 'b' => $b, 'c' => $c,
                    'pct_ad' => $pct($ad), 'pct_a' => $pct($a),
                    'pct_b'  => $pct($b),  'pct_c' => $pct($c),
                    'logro'        => $logro,
                    'proceso'      => $proceso,
                    'pct_logro'    => $pct($logro),
                    'pct_proceso'  => $pct($proceso),
                ];
            }

            $niveles[] = [
                'nivel_id'     => $nivel['nivel_id'],
                'nivel_nombre' => $nivel['nivel_nombre'],
                'nivel_codigo' => $nivel['nivel_codigo'],
                'serie'        => $serie,
            ];
        }

        $this->cacheEvolucion[$anioId] = ['periodos' => $ejeX, 'niveles' => $niveles];

        return $this->cacheEvolucion[$anioId];
    }

    /**
     * Docentes que bloquearon el 100% de las competencias que les correspondían
     * en el periodo, ordenados por mayor anticipación frente a la fecha límite.
     * Como el límite es el mismo para todo el bimestre, "mayor margen" equivale
     * a "último bloqueo más temprano". Devuelve hasta $limite docentes.
     */
    public function getDocentesMasRapidos(int $periodoId, int $limite = 5): array
    {
        $periodo = $this->queryOne("
            SELECT limite_notas, fecha_fin FROM periodos WHERE id = ?
        ", [$periodoId]);

        if (!$periodo) {
            return [];
        }

        // Referencia para el margen: la fecha límite de notas si existe,
        // si no, el fin del bimestre al cierre del día.
        $referencia = $periodo['limite_notas'] ?? ($periodo['fecha_fin'] . ' 23:59:59');
        $tsRef      = strtotime($referencia);

        $limite = max(1, $limite);
        $docentes = $this->query("
            SELECT
                ca.docente_id,
                p.apellido_paterno,
                p.apellido_materno,
                p.nombres,
                COUNT(*)        AS total_comp,
                COUNT(bc.id)    AS bloqueadas,
                MAX(bc.bloqueado_en) AS ultimo_bloqueo
            FROM cargas_academicas ca
            INNER JOIN competencias comp ON (
                (ca.subarea_id IS NOT NULL AND comp.subarea_id = ca.subarea_id)
                OR
                (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND comp.area_id = ca.area_id)
            )
            INNER JOIN usuarios u ON u.id = ca.docente_id
            INNER JOIN personas p ON p.id = u.persona_id
            LEFT JOIN bloqueos_competencia bc
                ON  bc.carga_id       = ca.id
                AND bc.competencia_id = comp.id
                AND bc.periodo_id     = ?
            WHERE ca.estado  = 'activa'
              AND ca.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
            GROUP BY ca.docente_id, p.apellido_paterno, p.apellido_materno, p.nombres
            HAVING total_comp = bloqueadas AND total_comp > 0
            ORDER BY ultimo_bloqueo ASC
            LIMIT " . (int) $limite . "
        ", [$periodoId, $periodoId]);

        foreach ($docentes as &$d) {
            $d['nombre_completo'] = $d['apellido_paterno'] . ' '
                . $d['apellido_materno'] . ', ' . $d['nombres'];

            $tsBloqueo      = strtotime((string) $d['ultimo_bloqueo']);
            $margenSegundos = $tsRef - $tsBloqueo;
            $d['a_tiempo']  = $margenSegundos >= 0;
            $d['margen']    = $this->formatearMargen(abs($margenSegundos));
        }
        unset($d);

        return $docentes;
    }

    /** Formatea una cantidad de segundos como "Nd Nh" o "Nh Nmin". */
    private function formatearMargen(int $segundos): string
    {
        $dias  = intdiv($segundos, 86400);
        $horas = intdiv($segundos % 86400, 3600);
        $mins  = intdiv($segundos % 3600, 60);

        if ($dias > 0) {
            return $dias . ' d ' . $horas . ' h';
        }
        if ($horas > 0) {
            return $horas . ' h ' . $mins . ' min';
        }
        return $mins . ' min';
    }
}
