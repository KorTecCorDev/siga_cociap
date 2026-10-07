<?php

namespace App\Models;

/**
 * CambioSeccionModel — PUNTO ÚNICO del CAMBIO DE SECCIÓN (07/10/2026, migración 075).
 * Plan y decisiones: docs/modulos/cambio-seccion.md.
 *
 * Un cambio de sección conserva la MISMA matrícula (`UPDATE seccion_id`), mismo
 * grado y año. Lo que es de la matrícula viaja solo (asistencia, conducta,
 * exoneraciones...). Lo que es de la CARGA no puede viajar sin cambiar de dueño:
 *
 *  - Bimestres CERRADOS: las notas se QUEDAN en la carga de origen. No se tocan.
 *  - Bimestres NO cerrados: lo vivo del estudiante en las cargas de origen
 *    (promedios, notas por criterio, omisiones) se ARCHIVA aquí y se RETIRA de
 *    las tablas vivas. Si quedara, el bloqueo de origen lo filtraría a la boleta
 *    y la competencia saldría duplicada. Destino califica de cero.
 *
 * LA PREGUNTA HISTÓRICA — ¿en qué sección cursó el bimestre P?
 *
 *   sección(P) = destino del último cambio VIGENTE con periodo <= P
 *                si no hay: origen del primer cambio VIGENTE con periodo > P
 *                si no hay ningún cambio: matriculas.seccion_id
 *
 *   PHP: seccionDelPeriodo()      SQL: sqlSeccionDelPeriodo()
 *
 * Un cambio REVERTIDO deja de contar en la pregunta: la sección vuelve a ser la
 * de origen en todos los bimestres.
 *
 * ⚠️ El retorno de grado es otra cosa (dos matrículas): una matrícula OPERATIVA
 * nunca cambia de sección, ni una OFICIAL con retorno activo (decisión 11).
 */
class CambioSeccionModel extends BaseModel
{
    protected string $table = 'cambios_seccion';

    // ── La pregunta histórica ────────────────────────────────────────────

    /**
     * Expresión SQL: id de la sección en la que la matrícula `$m` cursó el
     * periodo `$colPeriodo` (una columna de la consulta o un id entero).
     */
    public static function sqlSeccionDelPeriodo(string $m, string|int $colPeriodo): string
    {
        $px = is_int($colPeriodo) ? (string) $colPeriodo : $colPeriodo;
        return "COALESCE(
                (SELECT csd.seccion_destino_id
                   FROM cambios_seccion csd
                   INNER JOIN periodos csd_p ON csd_p.id = csd.periodo_id
                   INNER JOIN periodos csd_x ON csd_x.id = {$px}
                  WHERE csd.matricula_id = {$m}.id
                    AND csd.estado = 'vigente'
                    AND csd_p.numero <= csd_x.numero
                  ORDER BY csd_p.numero DESC, csd.id DESC
                  LIMIT 1),
                (SELECT cso.seccion_origen_id
                   FROM cambios_seccion cso
                   INNER JOIN periodos cso_p ON cso_p.id = cso.periodo_id
                   INNER JOIN periodos cso_x ON cso_x.id = {$px}
                  WHERE cso.matricula_id = {$m}.id
                    AND cso.estado = 'vigente'
                    AND cso_p.numero > cso_x.numero
                  ORDER BY cso_p.numero ASC, cso.id ASC
                  LIMIT 1),
                {$m}.seccion_id)";
    }

    /**
     * Condición SQL: la matrícula `$m` cursó el periodo `$colPeriodo` en la
     * sección `$seccionId`. Para los ROSTERS DE UN BIMESTRE (historial, resumen).
     *
     * El primer término deja usar el índice de `seccion_id` (solo se evalúa la
     * expresión para los de la sección y para quienes tienen algún cambio que
     * la toque); el segundo es la regla, de sqlSeccionDelPeriodo().
     */
    public static function sqlEnSeccionDelPeriodo(string $m, int $seccionId, string|int $colPeriodo): string
    {
        return "({$m}.seccion_id = {$seccionId}
                 OR {$m}.id IN (SELECT cse.matricula_id FROM cambios_seccion cse
                                 WHERE cse.seccion_origen_id = {$seccionId}
                                    OR cse.seccion_destino_id = {$seccionId}))
                AND " . self::sqlSeccionDelPeriodo($m, $colPeriodo) . " = {$seccionId}";
    }

    /** Sección en la que la matrícula cursó el periodo (ver cabecera). */
    public function seccionDelPeriodo(int $matriculaId, int $periodoId): ?int
    {
        $r = $this->queryOne(
            "SELECT " . self::sqlSeccionDelPeriodo('m', $periodoId) . " AS seccion_id
             FROM matriculas m WHERE m.id = ?",
            [$matriculaId]
        );
        return ($r && $r['seccion_id'] !== null) ? (int) $r['seccion_id'] : null;
    }

    // ── Consultas ────────────────────────────────────────────────────────

    /** El último cambio VIGENTE de la matrícula (el único que se puede revertir), o null. */
    public function ultimoVigente(int $matriculaId): ?array
    {
        return $this->queryOne("
            SELECT cs.*, p.numero AS periodo_numero, p.nombre_display AS periodo_nombre,
                   so.nombre AS origen_nombre, sd.nombre AS destino_nombre
            FROM cambios_seccion cs
            INNER JOIN periodos  p  ON p.id  = cs.periodo_id
            INNER JOIN secciones so ON so.id = cs.seccion_origen_id
            INNER JOIN secciones sd ON sd.id = cs.seccion_destino_id
            WHERE cs.matricula_id = ? AND cs.estado = 'vigente'
            ORDER BY p.numero DESC, cs.id DESC
            LIMIT 1
        ", [$matriculaId]);
    }

    /** Historial completo de cambios de la matrícula (vigentes y revertidos). */
    public function historial(int $matriculaId): array
    {
        return $this->query("
            SELECT cs.*, p.nombre_display AS periodo_nombre,
                   so.nombre AS origen_nombre, sd.nombre AS destino_nombre
            FROM cambios_seccion cs
            INNER JOIN periodos  p  ON p.id  = cs.periodo_id
            INNER JOIN secciones so ON so.id = cs.seccion_origen_id
            INNER JOIN secciones sd ON sd.id = cs.seccion_destino_id
            WHERE cs.matricula_id = ?
            ORDER BY cs.movido_en DESC, cs.id DESC
        ", [$matriculaId]);
    }

    /** Secciones del mismo grado y año, distintas de la actual. */
    public function seccionesDestino(int $matriculaId): array
    {
        return $this->query("
            SELECT s2.id, s2.nombre
            FROM matriculas m
            INNER JOIN secciones s  ON s.id = m.seccion_id
            INNER JOIN secciones s2 ON s2.grado_id = s.grado_id
                                   AND s2.anio_id  = s.anio_id
                                   AND s2.id      <> s.id
            WHERE m.id = ?
            ORDER BY s2.nombre
        ", [$matriculaId]);
    }

    /** Primer periodo NO cerrado del año: desde ahí rige el destino. */
    public function periodoDelCambio(int $anioId): ?array
    {
        return $this->queryOne("
            SELECT id, numero, nombre_display
            FROM periodos
            WHERE anio_id = ? AND estado <> 'cerrado'
            ORDER BY numero
            LIMIT 1
        ", [$anioId]);
    }

    // ── Elegibilidad ─────────────────────────────────────────────────────

    /**
     * Motivo por el que la matrícula NO puede cambiar de sección, o null si
     * puede. Si se da `$destinoId`, valida también el destino.
     */
    public function motivoNoElegible(int $matriculaId, ?int $destinoId = null): ?string
    {
        $m = $this->queryOne("
            SELECT m.id, m.estado, m.tipo, m.anio_id, m.seccion_id, s.grado_id
            FROM matriculas m
            INNER JOIN secciones s ON s.id = m.seccion_id
            WHERE m.id = ?
        ", [$matriculaId]);
        if (!$m) {
            return 'Matrícula no encontrada.';
        }
        if (!in_array($m['estado'], ['aprobada', 'pendiente'], true)
            || !in_array($m['tipo'], ['continuador', 'nuevo'], true)) {
            return 'Solo se puede cambiar de sección una matrícula aprobada o pendiente, '
                . 'que no esté trasladada ni retirada.';
        }

        // Retorno de grado (decisión 11): nunca la operativa; la oficial solo
        // si su retorno ya se revirtió.
        $retorno = (new RetornoGradoModel())->vinculo($matriculaId);
        if ($retorno !== null) {
            if ($retorno['operativa'] === $matriculaId) {
                return 'Esta matrícula es la operativa de un retorno de grado: no se puede cambiar de sección.';
            }
            if ($retorno['estado'] === 'activo') {
                return 'El estudiante tiene un retorno de grado activo: no se puede cambiar de sección.';
            }
        }

        if ($this->periodoDelCambio((int) $m['anio_id']) === null) {
            return 'Todos los bimestres del año están cerrados.';
        }

        if ($destinoId !== null) {
            $d = $this->queryOne(
                "SELECT id, grado_id, anio_id FROM secciones WHERE id = ?",
                [$destinoId]
            );
            if (!$d || (int) $d['grado_id'] !== (int) $m['grado_id']
                || (int) $d['anio_id'] !== (int) $m['anio_id']) {
                return 'La sección de destino debe ser del mismo grado y año.';
            }
            if ((int) $d['id'] === (int) $m['seccion_id']) {
                return 'La sección de destino es la sección actual.';
            }
        }
        return null;
    }

    // ── Ejecución ────────────────────────────────────────────────────────

    /**
     * Qué es mover la matrícula a `$destinoId`, frente a su último cambio vigente:
     *  - 'normal'  : un cambio de sección con R.D. obligatoria;
     *  - 'regreso' : vuelve a la sección de ORIGEN del último cambio y el
     *                bimestre de ese cambio YA CERRÓ → cambio nuevo SIN R.D.
     *                (decisión del usuario, 07/10/2026);
     *  - 'revertir': vuelve a esa sección y el bimestre del cambio SIGUE
     *                ABIERTO → no es un cambio nuevo, es revertir().
     */
    public function tipoDeMovimiento(int $matriculaId, int $destinoId): string
    {
        $ult = $this->ultimoVigente($matriculaId);
        if (!$ult || (int) $ult['seccion_origen_id'] !== $destinoId) {
            return 'normal';
        }
        return $this->periodoCerrado((int) $ult['periodo_id']) ? 'regreso' : 'revertir';
    }

    /** ¿El periodo está cerrado? */
    public function periodoCerrado(int $periodoId): bool
    {
        return $this->queryOne(
            "SELECT 1 AS x FROM periodos WHERE id = ? AND estado = 'cerrado'",
            [$periodoId]
        ) !== null;
    }

    /**
     * Cambia de sección la matrícula. TRANSACCIÓN propia (o la del llamador,
     * si ya hay una abierta: así lo prueba el verificador con rollback).
     *
     * La R.D. (`$rdCorrelativo` + `$rdFecha`) es obligatoria salvo en un
     * 'regreso' (ver tipoDeMovimiento), donde es opcional.
     *
     * @return array{cambio_id:int, rd_numero:?string, periodo:string, regreso:bool,
     *               competencias:int, criterios:int, omisiones:int}
     * @throws \InvalidArgumentException si algo no es válido (mensaje para el usuario)
     */
    public function ejecutar(
        int $matriculaId,
        int $destinoId,
        ?int $rdCorrelativo,
        ?string $rdFecha,
        string $motivo,
        int $usuarioId
    ): array {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \InvalidArgumentException('El motivo es obligatorio.');
        }
        $rdFecha = ($rdFecha === null || trim($rdFecha) === '') ? null : trim($rdFecha);
        $conRd   = $rdCorrelativo !== null || $rdFecha !== null;
        if ($conRd) {
            if ($rdCorrelativo === null || $rdCorrelativo < 1) {
                throw new \InvalidArgumentException('El número de R.D. debe ser mayor o igual a 1.');
            }
            $f = $rdFecha !== null ? \DateTime::createFromFormat('Y-m-d', $rdFecha) : false;
            if (!$f || $f->format('Y-m-d') !== $rdFecha) {
                throw new \InvalidArgumentException('La fecha de la R.D. no es válida.');
            }
        }

        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            // Se re-lee y re-valida DENTRO de la transacción (defensa anti-carrera).
            $m = $this->queryOne(
                "SELECT id, anio_id, seccion_id FROM matriculas WHERE id = ? FOR UPDATE",
                [$matriculaId]
            );
            $motivoNo = $this->motivoNoElegible($matriculaId, $destinoId);
            if (!$m || $motivoNo !== null) {
                throw new \InvalidArgumentException($motivoNo ?? 'Matrícula no encontrada.');
            }
            $tipo = $this->tipoDeMovimiento($matriculaId, $destinoId);
            if ($tipo === 'revertir') {
                throw new \InvalidArgumentException(
                    'El estudiante vuelve a su sección anterior en el mismo bimestre: '
                    . 'corresponde revertir el cambio, no registrar uno nuevo.'
                );
            }
            if ($tipo === 'normal' && !$conRd) {
                throw new \InvalidArgumentException('El número y la fecha de la R.D. son obligatorios.');
            }
            $anioId   = (int) $m['anio_id'];
            $origenId = (int) $m['seccion_id'];
            $periodo  = $this->periodoDelCambio($anioId);

            $rdNumero = null;
            if ($conRd) {
                // Numeración COMPARTIDA con los traslados (punto único).
                $traslados = new TrasladoModel();
                if (!$traslados->correlativoDisponible($anioId, $rdCorrelativo)) {
                    throw new \InvalidArgumentException(
                        'El número ' . $rdCorrelativo . ' ya está en uso por otra resolución vigente. Elige otro.'
                    );
                }
                $config   = $traslados->getConfigAnio($anioId);
                $sufijo   = config('institucion_datos')['sufijo_constancia'] ?? 'CAVVG-DA';
                $rdNumero = TrasladoModel::formatearNumero(
                    $rdCorrelativo, (int) ($config['anio'] ?? date('Y')), $sufijo
                );
            }

            $cambioId = $this->create([
                'matricula_id'       => $matriculaId,
                'anio_id'            => $anioId,
                'periodo_id'         => (int) $periodo['id'],
                'seccion_origen_id'  => $origenId,
                'seccion_destino_id' => $destinoId,
                'rd_correlativo'     => $conRd ? $rdCorrelativo : null,
                'rd_numero'          => $rdNumero,
                'rd_fecha'           => $rdFecha,
                'motivo'             => $motivo,
                'movido_por'         => $usuarioId,
            ]);

            $conteo = $this->archivarYRetirar($cambioId, 'origen', $matriculaId, $origenId, $anioId);

            $this->execute(
                "UPDATE matriculas SET seccion_id = ? WHERE id = ?",
                [$destinoId, $matriculaId]
            );

            if ($propia) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }

        return [
            'cambio_id' => $cambioId,
            'rd_numero' => $rdNumero,
            'periodo'   => (string) $periodo['nombre_display'],
            'regreso'   => $tipo === 'regreso',
        ] + $conteo;
    }

    // ── Reversión ────────────────────────────────────────────────────────

    /**
     * Competencias de ORIGEN que ya están BLOQUEADAS y recibirían notas
     * restauradas. Mientras haya alguna, NO se revierte (opción a, decisión del
     * usuario del 07/10/2026): la reversión nunca toca una competencia oficial;
     * primero se desbloquea con el flujo de siempre.
     *
     * @return array<int, array{carga_id:int, competencia:string, codigo:?string}>
     */
    public function bloqueosQueImpidenRevertir(int $cambioId): array
    {
        return $this->query("
            SELECT DISTINCT bc.carga_id, comp.nombre_completo AS competencia,
                   comp.codigo_minedu AS codigo
            FROM (
                SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_criterio
                 WHERE cambio_id = ? AND lado = 'origen'
                UNION
                SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_omision
                 WHERE cambio_id = ? AND lado = 'origen'
                UNION
                SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_competencia
                 WHERE cambio_id = ? AND lado = 'origen'
            ) a
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = a.carga_id
                   AND bc.competencia_id = a.competencia_id
                   AND bc.periodo_id     = a.periodo_id
            INNER JOIN competencias comp ON comp.id = a.competencia_id
            ORDER BY bc.carga_id, comp.nombre_completo
        ", [$cambioId, $cambioId, $cambioId]);
    }

    /**
     * Motivo por el que el cambio NO se puede revertir, o null si se puede.
     * Solo el ÚLTIMO cambio vigente, con su bimestre AÚN ABIERTO (si ya cerró,
     * volver es un 'regreso': un cambio nuevo) y sin bloqueos en origen.
     */
    public function motivoNoRevertible(int $cambioId): ?string
    {
        $c = $this->queryOne("
            SELECT cs.*, p.estado AS periodo_estado, m.seccion_id AS seccion_actual, m.tipo
            FROM cambios_seccion cs
            INNER JOIN periodos   p ON p.id = cs.periodo_id
            INNER JOIN matriculas m ON m.id = cs.matricula_id
            WHERE cs.id = ?
        ", [$cambioId]);
        if (!$c || $c['estado'] !== 'vigente') {
            return 'El cambio de sección no existe o ya fue revertido.';
        }
        $ult = $this->ultimoVigente((int) $c['matricula_id']);
        if (!$ult || (int) $ult['id'] !== $cambioId
            || (int) $c['seccion_actual'] !== (int) $c['seccion_destino_id']) {
            return 'Solo se puede revertir el último cambio de sección del estudiante.';
        }
        if ($c['periodo_estado'] === 'cerrado') {
            return 'El bimestre de este cambio ya cerró: para volver a la sección anterior '
                . 'registra un nuevo cambio de sección.';
        }
        if (!in_array($c['tipo'], ['continuador', 'nuevo'], true)) {
            return 'La matrícula está trasladada o retirada.';
        }
        $bloqueos = $this->bloqueosQueImpidenRevertir($cambioId);
        if ($bloqueos !== []) {
            $lista = array_map(
                fn($b) => ($b['codigo'] ? $b['codigo'] . ' ' : '') . $b['competencia'],
                $bloqueos
            );
            return 'En la sección de origen ya están bloqueadas competencias que recibirían '
                . 'las notas restauradas. Desbloquéalas primero: ' . implode('; ', $lista) . '.';
        }
        return null;
    }

    /**
     * Revierte el cambio: archiva lo que registró destino (lado 'destino'),
     * restaura lo archivado de origen, DESCONFIRMA los criterios que reciben
     * notas restauradas (decisión 16), devuelve la matrícula a origen y marca el
     * cambio 'revertido' (libera su número de R.D.). TRANSACCIÓN.
     *
     * Lo que no se puede restaurar (criterio eliminado después) se omite y se
     * cuenta en `omitidas`.
     *
     * @return array{restauradas:int, omitidas:int, desconfirmados:int[],
     *               archivadas_destino:array{competencias:int, criterios:int, omisiones:int}}
     * @throws \InvalidArgumentException
     */
    public function revertir(int $cambioId, string $motivo, int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new \InvalidArgumentException('El motivo de la reversión es obligatorio.');
        }

        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->beginTransaction();
        }
        try {
            $c = $this->queryOne("SELECT * FROM cambios_seccion WHERE id = ? FOR UPDATE", [$cambioId]);
            $no = $this->motivoNoRevertible($cambioId);
            if (!$c || $no !== null) {
                throw new \InvalidArgumentException($no ?? 'El cambio de sección no existe.');
            }
            $matriculaId = (int) $c['matricula_id'];
            $origenId    = (int) $c['seccion_origen_id'];
            $destinoId   = (int) $c['seccion_destino_id'];
            $anioId      = (int) $c['anio_id'];

            // 1) Lo de destino se archiva (simétrico: no se pierde nada).
            $archDestino = $this->archivarYRetirar($cambioId, 'destino', $matriculaId, $destinoId, $anioId);

            // 2) Criterios VIVOS que recibirán algo (antes de insertar).
            $criterios = array_map('intval', array_column($this->query("
                SELECT DISTINCT a.criterio_id FROM (
                    SELECT criterio_id FROM cambios_seccion_criterio WHERE cambio_id = ? AND lado = 'origen'
                    UNION
                    SELECT criterio_id FROM cambios_seccion_omision  WHERE cambio_id = ? AND lado = 'origen'
                ) a
                INNER JOIN criterios cr ON cr.id = a.criterio_id AND cr.eliminado_en IS NULL
            ", [$cambioId, $cambioId]), 'criterio_id'));

            // 3) Notas por criterio y omisiones (solo criterios vivos).
            $this->execute("
                INSERT INTO calificaciones_criterio (criterio_id, matricula_id, nota, registrado_en, modificado_en)
                SELECT a.criterio_id, ?, a.nota, a.registrado_en, a.modificado_en
                FROM cambios_seccion_criterio a
                INNER JOIN criterios cr ON cr.id = a.criterio_id AND cr.eliminado_en IS NULL
                WHERE a.cambio_id = ? AND a.lado = 'origen'
            ", [$matriculaId, $cambioId]);
            $this->execute("
                INSERT INTO omisiones_criterio (criterio_id, matricula_id, motivo, registrado_en, registrado_por)
                SELECT a.criterio_id, ?, a.motivo, a.registrado_en, a.registrado_por
                FROM cambios_seccion_omision a
                INNER JOIN criterios cr ON cr.id = a.criterio_id AND cr.eliminado_en IS NULL
                WHERE a.cambio_id = ? AND a.lado = 'origen'
            ", [$matriculaId, $cambioId]);

            // 4) Promedios: solo donde el estudiante vuelve a tener nota viva
            //    (invariante «fila en calificaciones ⟺ nota viva»). Con el
            //    criterio desconfirmado, el promedio es transitorio: se rehace
            //    cuando el docente vuelva a confirmar.
            $this->execute("
                INSERT INTO calificaciones
                    (matricula_id, carga_id, periodo_id, competencia_id, nota_numerica,
                     conclusion_descriptiva, extraordinaria, registrado_en, modificado_en, registrado_por)
                SELECT ?, a.carga_id, a.periodo_id, a.competencia_id, a.nota_numerica,
                       a.conclusion_descriptiva, a.extraordinaria, a.registrado_en, a.modificado_en, a.registrado_por
                FROM cambios_seccion_competencia a
                WHERE a.cambio_id = ? AND a.lado = 'origen'
                  AND EXISTS (
                      SELECT 1 FROM calificaciones_criterio cc
                      INNER JOIN criterios cr ON cr.id = cc.criterio_id
                      WHERE cc.matricula_id   = ?
                        AND cr.carga_id       = a.carga_id
                        AND cr.competencia_id = a.competencia_id
                        AND cr.periodo_id     = a.periodo_id
                        AND cr.eliminado_en   IS NULL
                  )
            ", [$matriculaId, $cambioId, $matriculaId]);

            // 5) Desconfirmar (punto único del criterio). Solo los confirmados.
            $desconfirmados = [];
            $criterioModel  = new CriterioModel();
            foreach ($criterios as $critId) {
                $conf = $this->queryOne(
                    "SELECT 1 AS x FROM criterios WHERE id = ? AND confirmado_en IS NOT NULL",
                    [$critId]
                );
                if ($conf) {
                    $criterioModel->desconfirmar($critId);
                    $desconfirmados[] = $critId;
                }
            }

            // 6) La matrícula vuelve y el cambio queda revertido (libera su R.D.).
            $this->execute("UPDATE matriculas SET seccion_id = ? WHERE id = ?", [$origenId, $matriculaId]);
            $this->execute("
                UPDATE cambios_seccion
                SET estado = 'revertido', revertido_por = ?, revertido_en = NOW(), motivo_reversion = ?
                WHERE id = ?
            ", [$usuarioId, $motivo, $cambioId]);

            $restauradas = (int) ($this->queryOne("
                SELECT COUNT(*) AS n FROM cambios_seccion_criterio a
                INNER JOIN criterios cr ON cr.id = a.criterio_id AND cr.eliminado_en IS NULL
                WHERE a.cambio_id = ? AND a.lado = 'origen'
            ", [$cambioId])['n'] ?? 0);
            $omitidas = $this->filasArchivadas('cambios_seccion_criterio', $cambioId, 'origen') - $restauradas;

            if ($propia) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $this->rollback();
            }
            throw $e;
        }

        return [
            'restauradas'        => $restauradas,
            'omitidas'           => $omitidas,
            'desconfirmados'     => $desconfirmados,
            'archivadas_destino' => $archDestino,
        ];
    }

    // ── Avisos ───────────────────────────────────────────────────────────

    /**
     * Avisa del cambio (o de su reversión) — decisión 8: tutor, docentes y
     * auxiliar de ORIGEN y de DESTINO, y DIRECCIÓN. Un aviso por persona (sin
     * duplicar a quien tiene varios papeles) y nunca a quien hizo la operación.
     * El llamador lo invoca FUERA de la transacción del dato.
     *
     * @return int cuántas personas quedaron avisadas
     */
    public function avisar(int $cambioId, string $evento, int $emisorId): int
    {
        $c = $this->queryOne("
            SELECT cs.*, p.nombre_display AS periodo_nombre,
                   g.nombre_display AS grado, so.nombre AS origen, sd.nombre AS destino,
                   TRIM(CONCAT(per.apellido_paterno, ' ', per.apellido_materno, ', ', per.nombres)) AS alumno
            FROM cambios_seccion cs
            INNER JOIN periodos    p   ON p.id   = cs.periodo_id
            INNER JOIN secciones   so  ON so.id  = cs.seccion_origen_id
            INNER JOIN secciones   sd  ON sd.id  = cs.seccion_destino_id
            INNER JOIN grados      g   ON g.id   = so.grado_id
            INNER JOIN matriculas  m   ON m.id   = cs.matricula_id
            INNER JOIN estudiantes e   ON e.id   = m.estudiante_id
            INNER JOIN personas    per ON per.id = e.persona_id
            WHERE cs.id = ?
        ", [$cambioId]);
        if (!$c) {
            return 0;
        }

        $notif = new NotificacionModel();
        $aux   = new AuxiliarSeccionModel();
        $periodoId = (int) $c['periodo_id'];

        // Personal de una sección: tutor + docentes con carga + auxiliar vigente.
        $personal = function (int $seccionId) use ($notif, $aux, $periodoId): array {
            $ids = array_map(fn($d) => (int) $d['id'], $notif->docentesDeSeccion($seccionId));
            $tutor = $this->queryOne(
                "SELECT s.tutor_id FROM secciones s
                 INNER JOIN usuarios u ON u.id = s.tutor_id AND u.estado = 'activo'
                 WHERE s.id = ?",
                [$seccionId]
            );
            if ($tutor) {
                $ids[] = (int) $tutor['tutor_id'];
            }
            $a = $aux->vigente($seccionId, $periodoId);
            if ($a) {
                $ids[] = (int) $a['auxiliar_id'];
            }
            return $ids;
        };

        $origen   = (int) $c['seccion_origen_id'];
        $destino  = (int) $c['seccion_destino_id'];
        $deOrigen = $personal($origen);
        $deDestino = $personal($destino);
        $direccion = array_map(fn($u) => (int) $u['id'], $notif->usuariosDeRoles(ROLES_DIRECCION));

        $alumno = (string) $c['alumno'];
        $de     = $c['grado'] . ' ' . $c['origen'];
        $a      = $c['grado'] . ' ' . $c['destino'];
        $rd     = $c['rd_numero'] !== null
            ? ' (R.D. ' . $c['rd_numero'] . ')'
            : ' (regreso a su sección anterior)';

        if ($evento === 'reversion') {
            $titulo = 'Cambio de sección revertido: ' . $alumno;
            $base   = $alumno . ' vuelve a ' . $de . '. Se revirtió su cambio a ' . $a . '.';
            $msgOrigen = $base . ' Se restauraron sus calificaciones del ' . $c['periodo_nombre']
                . '; los criterios que las recibieron quedaron sin confirmar y hay que volver a confirmarlos.';
            $msgDestino = $base . ' Ya no está en tu lista; lo que le registraste en el '
                . $c['periodo_nombre'] . ' quedó archivado.';
        } else {
            $titulo = 'Cambio de sección: ' . $alumno;
            $base   = $alumno . ' pasa de ' . $de . ' a ' . $a . ' desde el ' . $c['periodo_nombre'] . $rd . '.';
            $msgOrigen = $base . ' Ya no está en tu lista. Sus calificaciones de bimestres cerrados '
                . 'se quedan en esta sección.';
            $msgDestino = $base . ' En el ' . $c['periodo_nombre'] . ' se le califica desde cero; '
                . 'sus calificaciones de bimestres anteriores son de solo lectura.';
        }

        // Un aviso por persona: si alguien está en origen Y destino, prima destino.
        $mensajes = [];
        foreach ($direccion as $id) { $mensajes[$id] = $base; }
        foreach ($deOrigen as $id)  { $mensajes[$id] = $msgOrigen; }
        foreach ($deDestino as $id) { $mensajes[$id] = $msgDestino; }
        unset($mensajes[$emisorId]);

        foreach ($mensajes as $usuarioId => $mensaje) {
            $notif->crear([
                'usuario_id' => $usuarioId,
                'tipo'       => NotificacionModel::TIPO_CAMBIO_SECCION,
                'titulo'     => mb_substr($titulo, 0, 150),
                'mensaje'    => $mensaje,
            ]);
        }
        return count($mensajes);
    }

    /**
     * Copia al archivo y RETIRA de las tablas vivas todo lo del estudiante en
     * las cargas de `$seccionId`, en los periodos NO cerrados del año.
     * Solo criterios vivos (`eliminado_en IS NULL`): las notas de un criterio
     * ya eliminado se conservan donde están, como en el resto del sistema.
     *
     * @return array{competencias:int, criterios:int, omisiones:int}
     */
    private function archivarYRetirar(
        int $cambioId,
        string $lado,
        int $matriculaId,
        int $seccionId,
        int $anioId
    ): array {
        $p = [$cambioId, $lado, $matriculaId, $seccionId, $anioId];

        // 1) Promedios por competencia.
        $this->execute("
            INSERT INTO cambios_seccion_competencia
                (cambio_id, lado, carga_id, competencia_id, periodo_id, nota_numerica,
                 conclusion_descriptiva, extraordinaria, registrado_en, modificado_en, registrado_por)
            SELECT ?, ?, c.carga_id, c.competencia_id, c.periodo_id, c.nota_numerica,
                   c.conclusion_descriptiva, c.extraordinaria, c.registrado_en, c.modificado_en, c.registrado_por
            FROM calificaciones c
            INNER JOIN cargas_academicas ca ON ca.id = c.carga_id
            INNER JOIN periodos pe          ON pe.id = c.periodo_id
            WHERE c.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
        ", $p);
        $competencias = $this->filasArchivadas('cambios_seccion_competencia', $cambioId, $lado);

        // 2) Notas por criterio (criterio denormalizado).
        $this->execute("
            INSERT INTO cambios_seccion_criterio
                (cambio_id, lado, criterio_id, carga_id, competencia_id, periodo_id,
                 criterio_nombre, criterio_orden, nota, registrado_en, modificado_en)
            SELECT ?, ?, cr.id, cr.carga_id, cr.competencia_id, cr.periodo_id,
                   cr.nombre, cr.orden, cc.nota, cc.registrado_en, cc.modificado_en
            FROM calificaciones_criterio cc
            INNER JOIN criterios cr         ON cr.id = cc.criterio_id
            INNER JOIN cargas_academicas ca ON ca.id = cr.carga_id
            INNER JOIN periodos pe          ON pe.id = cr.periodo_id
            WHERE cc.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
              AND cr.eliminado_en IS NULL
        ", $p);
        $criterios = $this->filasArchivadas('cambios_seccion_criterio', $cambioId, $lado);

        // 3) Omisiones por criterio.
        $this->execute("
            INSERT INTO cambios_seccion_omision
                (cambio_id, lado, criterio_id, carga_id, competencia_id, periodo_id,
                 criterio_nombre, motivo, registrado_en, registrado_por)
            SELECT ?, ?, cr.id, cr.carga_id, cr.competencia_id, cr.periodo_id,
                   cr.nombre, oc.motivo, oc.registrado_en, oc.registrado_por
            FROM omisiones_criterio oc
            INNER JOIN criterios cr         ON cr.id = oc.criterio_id
            INNER JOIN cargas_academicas ca ON ca.id = cr.carga_id
            INNER JOIN periodos pe          ON pe.id = cr.periodo_id
            WHERE oc.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
              AND cr.eliminado_en IS NULL
        ", $p);
        $omisiones = $this->filasArchivadas('cambios_seccion_omision', $cambioId, $lado);

        // 4) Retiro de lo vivo, ya archivado (mismo universo exacto).
        $q = [$matriculaId, $seccionId, $anioId];
        $this->execute("
            DELETE cc FROM calificaciones_criterio cc
            INNER JOIN criterios cr         ON cr.id = cc.criterio_id
            INNER JOIN cargas_academicas ca ON ca.id = cr.carga_id
            INNER JOIN periodos pe          ON pe.id = cr.periodo_id
            WHERE cc.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
              AND cr.eliminado_en IS NULL
        ", $q);
        $this->execute("
            DELETE oc FROM omisiones_criterio oc
            INNER JOIN criterios cr         ON cr.id = oc.criterio_id
            INNER JOIN cargas_academicas ca ON ca.id = cr.carga_id
            INNER JOIN periodos pe          ON pe.id = cr.periodo_id
            WHERE oc.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
              AND cr.eliminado_en IS NULL
        ", $q);
        $this->execute("
            DELETE c FROM calificaciones c
            INNER JOIN cargas_academicas ca ON ca.id = c.carga_id
            INNER JOIN periodos pe          ON pe.id = c.periodo_id
            WHERE c.matricula_id = ? AND ca.seccion_id = ?
              AND pe.anio_id = ? AND pe.estado <> 'cerrado'
        ", $q);

        return ['competencias' => $competencias, 'criterios' => $criterios, 'omisiones' => $omisiones];
    }

    private function filasArchivadas(string $tabla, int $cambioId, string $lado): int
    {
        // $tabla es siempre un literal de esta clase (nunca entrada de usuario).
        $r = $this->queryOne(
            "SELECT COUNT(*) AS n FROM {$tabla} WHERE cambio_id = ? AND lado = ?",
            [$cambioId, $lado]
        );
        return (int) ($r['n'] ?? 0);
    }
}
