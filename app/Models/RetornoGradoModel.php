<?php

namespace App\Models;

/**
 * RetornoGradoModel — PUNTO ÚNICO del RETORNO DE GRADO (05/10/2026, migración 073).
 *
 * Un retorno da al estudiante DOS matrículas del mismo año:
 *  - la OFICIAL (grado SIAGIE), que es su IDENTIDAD en todo documento;
 *  - la OPERATIVA (grado inferior), donde cursa mientras el retorno está activo.
 *
 * Cada bimestre vive ENTERO en una sola de las dos (Regla A: los datos no se
 * copian ni se mueven entre bimestres cerrados). Cuál es lo dice el TRAMO
 * guardado en `retornos_grado`, no se deduce de los datos:
 *
 *   la operativa cubre el bimestre P  ⟺  P >= desde
 *        AND (estado = 'activo' OR (hasta IS NOT NULL AND P <= hasta))
 *
 *  - `desde` lo fija store() (primer bimestre sin cerrar al registrar);
 *  - `hasta` lo fija revertir() (último bimestre cerrado del tramo). NULL en un
 *    retorno revertido = tramo VACÍO (no llegó a cursar ningún bimestre allá).
 *
 * Hasta el 05/10/2026 cada consulta DEDUCÍA el tramo («la matrícula que tiene
 * notas en ese periodo»), cada una a su manera, y al revertir el retorno #1
 * fallaron en cadena:
 *  - el lote de boletas, la situación final y los cuadros;
 *  - además, 22 promedios del I copiados a la operativa por el antiguo
 *    INSERT IGNORE hacían creer que el I se cursó en 1.° B.
 *
 * LAS CUATRO PREGUNTAS, cada una con su respuesta única:
 *
 *  | Pregunta                                 | PHP                     | SQL                                    |
 *  |------------------------------------------|-------------------------|----------------------------------------|
 *  | ¿Dónde se evalúa HOY? (grillas)          | matriculaDeHoy()        | roster_evaluacion()     (helpers.php)  |
 *  | ¿Dónde cursó el bimestre P? (historia)   | matriculaDelPeriodo()   | sqlCursoElPeriodo()                    |
 *  | ¿Con qué matrícula se documenta?         | identidad()             | matricula_documento()   (helpers.php)  |
 *  | ¿Qué matrículas componen al estudiante?  | fuentes()               | sqlFuentes()                           |
 *
 * ⚠️ EXCEPCIÓN de las vistas de SOLO LECTURA de notas (28/10/2026):
 * `sqlRosterLecturaDelPeriodo()` lista SIEMPRE la oficial y NUNCA la operativa,
 * también en el tramo, y los datos del tramo se leen de la operativa por la
 * fila oficial (`oficialesEnTramo()`, `sqlDatosDelPeriodo()`). Ver su docblock.
 * No toca ninguna de las cuatro preguntas.
 *
 * `roster_evaluacion()` y `matricula_documento()` se quedan en helpers.php con su
 * texto intacto (sus verificadores comparan el literal), pero forman parte de
 * esta familia: HOY es el caso particular de «el bimestre en curso».
 *
 * ⚠️ Un retorno nunca cruza de nivel ni de año, y hay UNO como máximo por
 * matrícula (UNIQUE en las dos columnas). Toda regla académica (situación
 * final, talleres, ciclo) usa el grado de la IDENTIDAD.
 */
class RetornoGradoModel extends BaseModel
{
    protected string $table = 'retornos_grado';

    /** @var array<int, ?array> vínculo memorizado por matrícula (dentro de la request) */
    private array $memo = [];

    /** @var array<int, int> número de cada periodo, memorizado */
    private array $numeros = [];

    // ── API PHP ──────────────────────────────────────────────────────────

    /**
     * El retorno en el que participa la matrícula (como oficial o como
     * operativa), o null. Números de periodo ya resueltos.
     *
     * @return array{id:int, oficial:int, operativa:int, estado:string,
     *               desde_id:int, desde:int, hasta_id:?int, hasta:?int,
     *               fecha_reversion:?string}|null
     */
    public function vinculo(int $matriculaId): ?array
    {
        if (array_key_exists($matriculaId, $this->memo)) {
            return $this->memo[$matriculaId];
        }
        $r = $this->queryOne("
            SELECT r.id, r.matricula_oficial_id, r.matricula_operativa_id, r.estado,
                   r.fecha_reversion,
                   r.periodo_desde_id, pd.numero AS desde,
                   r.periodo_hasta_id, ph.numero AS hasta
            FROM retornos_grado r
            INNER JOIN periodos pd ON pd.id = r.periodo_desde_id
            LEFT  JOIN periodos ph ON ph.id = r.periodo_hasta_id
            WHERE r.matricula_oficial_id = ? OR r.matricula_operativa_id = ?
            LIMIT 1
        ", [$matriculaId, $matriculaId]);

        $v = $r ? [
            'id'        => (int) $r['id'],
            'oficial'   => (int) $r['matricula_oficial_id'],
            'operativa' => (int) $r['matricula_operativa_id'],
            'estado'    => (string) $r['estado'],
            'desde_id'  => (int) $r['periodo_desde_id'],
            'desde'     => (int) $r['desde'],
            'hasta_id'  => $r['periodo_hasta_id'] !== null ? (int) $r['periodo_hasta_id'] : null,
            'hasta'     => $r['hasta'] !== null ? (int) $r['hasta'] : null,
            'fecha_reversion' => $r['fecha_reversion'] !== null ? (string) $r['fecha_reversion'] : null,
        ] : null;

        if ($v) {
            $this->memo[$v['oficial']] = $v;
            $this->memo[$v['operativa']] = $v;
        }
        return $this->memo[$matriculaId] = $v;
    }

    /** ¿El tramo del retorno cubre el bimestre de ese número? */
    public static function cubre(array $vinculo, int $numeroPeriodo): bool
    {
        return $numeroPeriodo >= $vinculo['desde']
            && ($vinculo['estado'] === 'activo'
                || ($vinculo['hasta'] !== null && $numeroPeriodo <= $vinculo['hasta']));
    }

    /** Matrícula con la que se DOCUMENTA al estudiante: la oficial. */
    public function identidad(int $matriculaId): int
    {
        $v = $this->vinculo($matriculaId);
        return $v ? $v['oficial'] : $matriculaId;
    }

    /** Matrícula donde se evalúa HOY: la operativa mientras el retorno está activo. */
    public function matriculaDeHoy(int $matriculaId): int
    {
        $v = $this->vinculo($matriculaId);
        if (!$v) {
            return $matriculaId;
        }
        return $v['estado'] === 'activo' ? $v['operativa'] : $v['oficial'];
    }

    /** Matrícula que CURSÓ el bimestre: donde viven sus notas, asistencia y conducta. */
    public function matriculaDelPeriodo(int $matriculaId, int $periodoId): int
    {
        $v = $this->vinculo($matriculaId);
        if (!$v) {
            return $matriculaId;
        }
        return self::cubre($v, $this->numeroPeriodo($periodoId)) ? $v['operativa'] : $v['oficial'];
    }

    /**
     * Las matrículas que componen al estudiante: [operativa, oficial], o solo
     * la propia. Para preguntas de EXISTENCIA («¿le falta este dato?»); para LEER
     * datos de un bimestre se usa matriculaDelPeriodo().
     *
     * @return int[]
     */
    public function fuentes(int $matriculaId): array
    {
        $v = $this->vinculo($matriculaId);
        return $v ? [$v['operativa'], $v['oficial']] : [$matriculaId];
    }

    /** 'oficial' | 'operativa' | null (sin retorno). */
    public function rol(int $matriculaId): ?string
    {
        $v = $this->vinculo($matriculaId);
        if (!$v) {
            return null;
        }
        return $v['oficial'] === $matriculaId ? 'oficial' : 'operativa';
    }

    /**
     * Nombres de los bimestres que el estudiante CURSÓ en la operativa según el
     * tramo cerrado de un retorno REVERTIDO («II Bimestre», «I y II Bimestre»),
     * o null si el tramo está vacío o el retorno sigue activo (tramo abierto).
     * Para rotular en la interfaz, nunca para decidir de dónde leer.
     */
    public function nombresDelTramo(array $vinculo): ?string
    {
        if ($vinculo['estado'] === 'activo' || $vinculo['hasta'] === null) {
            return null;
        }
        $nombres = array_column($this->query("
            SELECT p.nombre_display
            FROM periodos p
            INNER JOIN periodos pd ON pd.id = ?
            WHERE p.anio_id = pd.anio_id AND p.numero BETWEEN ? AND ?
            ORDER BY p.numero
        ", [$vinculo['desde_id'], $vinculo['desde'], $vinculo['hasta']]), 'nombre_display');

        if (count($nombres) <= 1) {
            return $nombres[0] ?? null;
        }
        // «I Bimestre», «II Bimestre» → «I y II Bimestre».
        $numerales = array_map(static fn(string $n): string => trim(str_replace('Bimestre', '', $n)), $nombres);
        $ultimo    = array_pop($numerales);
        return implode(', ', $numerales) . ' y ' . $ultimo . ' Bimestre';
    }

    /**
     * GUARDA DE GESTIÓN de una matrícula que participa de un retorno (F4,
     * 05/10/2026). Devuelve el motivo del rechazo, o null si la acción procede.
     *
     *  - La OPERATIVA (activa o revertida) no se gestiona nunca: activarla,
     *    desactivarla, retirarla o trasladarla desharía en silencio el retorno o
     *    la devolvería a los rosters. Toda la gestión vive en la oficial.
     *  - La OFICIAL de un retorno ACTIVO no se retira ni se traslada: el
     *    estudiante cursa en la operativa, que quedaría en las grillas. Primero
     *    se revierte el retorno.
     *
     * $accion: 'activar' | 'desactivar' | 'retirar' | 'revertir_retiro' | 'trasladar'.
     */
    public function bloqueoGestion(int $matriculaId, string $accion): ?string
    {
        $v = $this->vinculo($matriculaId);
        if (!$v) {
            return null;
        }
        if ($v['operativa'] === $matriculaId) {
            return 'Es la matrícula operativa de un retorno de grado: no se gestiona. '
                 . 'Toda la gestión del estudiante se hace desde su matrícula oficial.';
        }
        if ($v['estado'] === 'activo' && in_array($accion, ['retirar', 'trasladar'], true)) {
            return 'El estudiante tiene un retorno de grado activo y cursa en la matrícula operativa. '
                 . 'Revierte el retorno antes de ' . ($accion === 'retirar' ? 'retirarlo.' : 'trasladarlo.');
        }
        return null;
    }

    /**
     * VISTAS DE LECTURA (28/10/2026): de las matrículas dadas, las OFICIALES cuyo
     * tramo cubre el periodo, con la operativa donde CURSARON ese bimestre y su
     * sección («1° B»). Su fila sale en la sección oficial
     * (sqlRosterLecturaDelPeriodo) y sus datos del bimestre se leen de la
     * operativa: es matriculaDelPeriodo() en lote, con la sección para rotular.
     *
     * @param  int[] $matriculaIds
     * @return array<int, array{operativa: int, seccion_id: int, seccion: string}>
     */
    public function oficialesEnTramo(array $matriculaIds, int $periodoId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $matriculaIds))));
        if ($ids === []) {
            return [];
        }
        $numero = $this->numeroPeriodo($periodoId);
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $out    = [];
        foreach ($this->query("
            SELECT r.matricula_oficial_id, r.matricula_operativa_id, r.estado,
                   pd.numero AS desde, ph.numero AS hasta,
                   mo.seccion_id, CONCAT(g.nombre_display, ' ', s.nombre) AS seccion
            FROM retornos_grado r
            INNER JOIN periodos   pd ON pd.id = r.periodo_desde_id
            LEFT  JOIN periodos   ph ON ph.id = r.periodo_hasta_id
            INNER JOIN matriculas mo ON mo.id = r.matricula_operativa_id
            INNER JOIN secciones  s  ON s.id  = mo.seccion_id
            INNER JOIN grados     g  ON g.id  = s.grado_id
            WHERE r.matricula_oficial_id IN ({$in})
        ", $ids) as $r) {
            $vinculo = [
                'estado' => (string) $r['estado'],
                'desde'  => (int) $r['desde'],
                'hasta'  => $r['hasta'] !== null ? (int) $r['hasta'] : null,
            ];
            if (self::cubre($vinculo, $numero)) {
                $out[(int) $r['matricula_oficial_id']] = [
                    'operativa'  => (int) $r['matricula_operativa_id'],
                    'seccion_id' => (int) $r['seccion_id'],
                    'seccion'    => (string) $r['seccion'],
                ];
            }
        }
        return $out;
    }

    /** Número (1..4) de un periodo, memorizado. */
    public function numeroPeriodo(int $periodoId): int
    {
        if (!isset($this->numeros[$periodoId])) {
            $this->numeros[$periodoId] = (int) ($this->queryOne(
                "SELECT numero FROM periodos WHERE id = ?", [$periodoId]
            )['numero'] ?? 0);
        }
        return $this->numeros[$periodoId];
    }

    // ── Escritura del tramo (la usan store() y revertir()) ─────────────────

    /** Primer bimestre SIN cerrar del año: donde empieza el tramo de un retorno nuevo. */
    public function primerPeriodoSinCerrar(int $anioId): ?array
    {
        return $this->queryOne("
            SELECT id, numero, nombre_display FROM periodos
            WHERE anio_id = ? AND estado <> 'cerrado'
            ORDER BY numero LIMIT 1
        ", [$anioId]);
    }

    /**
     * Fin del tramo al revertir: el último bimestre CERRADO desde `desde`, o null
     * si el retorno no llegó a cubrir ninguno completo (tramo vacío). Los
     * bimestres sin cerrar vuelven a la oficial (revertir() mueve sus datos).
     */
    public function finDelTramoAlRevertir(array $vinculo): ?int
    {
        $r = $this->queryOne("
            SELECT p.id FROM periodos p
            INNER JOIN periodos pd ON pd.id = ?
            WHERE p.anio_id = pd.anio_id AND p.estado = 'cerrado' AND p.numero >= pd.numero
            ORDER BY p.numero DESC LIMIT 1
        ", [$vinculo['desde_id']]);
        return $r ? (int) $r['id'] : null;
    }

    /**
     * Mapa operativa → oficial de TODOS los retornos, en una sola consulta.
     * Para recorrer listados enteros sin una consulta por matrícula:
     * `identidad = $mapa[$m] ?? $m`.
     *
     * @return array<int, int>
     */
    public function mapaIdentidades(): array
    {
        $out = [];
        foreach ($this->query("SELECT matricula_operativa_id, matricula_oficial_id FROM retornos_grado") as $r) {
            $out[(int) $r['matricula_operativa_id']] = (int) $r['matricula_oficial_id'];
        }
        return $out;
    }

    /** Olvida lo memorizado (tras escribir un retorno dentro de la misma request). */
    public function olvidar(): void
    {
        $this->memo = [];
    }

    // ── Fragmentos SQL ───────────────────────────────────────────────────

    /**
     * Expresión «el tramo de `$r` cubre el periodo `$px`», con `$pd`/`$ph` los
     * periodos desde/hasta (este último por LEFT JOIN). Nunca da NULL: `hasta`
     * solo se compara cuando existe.
     */
    private static function sqlCubre(string $r, string $pd, string $ph, string $px): string
    {
        return "({$px}.numero >= {$pd}.numero
                 AND ({$r}.estado = 'activo'
                      OR ({$ph}.id IS NOT NULL AND {$px}.numero <= {$ph}.numero)))";
    }

    /**
     * «`$m` es la matrícula que CURSÓ el periodo `$colPeriodo`». Excluye la
     * oficial dentro del tramo y la operativa fuera de él; una matrícula sin
     * retorno pasa siempre. Va correlacionado por la columna del periodo de la
     * consulta que lo incrusta (`cal.periodo_id`, `cr.periodo_id`,
     * `i.periodo_id`…), sin placeholders.
     *
     * Para TODA lectura histórica o por bimestre: mérito, alerta de evaluación
     * incompleta, situación final, estadísticas de los cuadros, lote de boletas.
     * NO para las grillas de hoy (eso es `roster_evaluacion()`).
     */
    public static function sqlCursoElPeriodo(string $m, string $colPeriodo): string
    {
        $cubre = self::sqlCubre('rgt_r', 'rgt_d', 'rgt_h', 'rgt_x');
        $desde = "FROM retornos_grado rgt_r
                    INNER JOIN periodos rgt_d ON rgt_d.id = rgt_r.periodo_desde_id
                    LEFT  JOIN periodos rgt_h ON rgt_h.id = rgt_r.periodo_hasta_id
                    INNER JOIN periodos rgt_x ON rgt_x.id = {$colPeriodo}";
        return "AND {$m}.id NOT IN (
                  SELECT rgt_r.matricula_oficial_id {$desde}
                  WHERE {$cubre}
              )
              AND {$m}.id NOT IN (
                  SELECT rgt_r.matricula_operativa_id {$desde}
                  WHERE NOT {$cubre}
              )";
    }

    /**
     * ROSTER DE EVALUACIÓN DE UN BIMESTRE: quién se evalúa (o se evaluó) en el
     * periodo `$colPeriodo`. Es `roster_evaluacion()` generalizado a cualquier
     * bimestre: mismas exclusiones por `tipo` (`matriculas_vigentes()`) y, del
     * retorno, la matrícula que CURSÓ ese bimestre según el tramo.
     *
     * En el bimestre en curso da exactamente lo mismo que `roster_evaluacion()`
     * (el tramo de un retorno activo cubre todo lo no cerrado; el de uno
     * revertido ya no). En un bimestre CERRADO es lo correcto: las grillas,
     * estadísticas y extraordinarias de ese bimestre muestran a quien lo cursó.
     *
     * `$colPeriodo` es una columna de la consulta o un id literal (int).
     */
    public static function sqlRosterDelPeriodo(string $m, string $colPeriodo): string
    {
        return matriculas_vigentes($m) . "\n              " . self::sqlCursoElPeriodo($m, $colPeriodo);
    }

    /**
     * ROSTER DE LECTURA DE UN BIMESTRE — PUNTO ÚNICO (28/10/2026): quién CURSÓ
     * el periodo `$colPeriodo` en la sección `$seccion`, para las vistas y
     * documentos de SOLO LECTURA (registro auxiliar, historial del docente,
     * /consulta-notas). Nunca el `tipo` ni el `estado` de HOY.
     *
     * Difiere de `sqlRosterDelPeriodo()` en tres cosas, a propósito:
     *  · un TRASLADADO o RETIRADO entra si tiene un DATO OFICIAL de ese periodo
     *    (decisión del usuario: «por el dato, siempre que esté bloqueado y
     *    aprobado»): una calificación de una competencia BLOQUEADA de una carga
     *    de la sección, conducta confirmada o del cierre, o asistencia
     *    confirmada. El ancla es la misma para todas las vistas: el estudiante
     *    aparece igual en todas las pestañas del bimestre. Sin dato, no entra;
     *  · RETORNO DE GRADO (decisión del usuario, 28/10/2026: «solo es válida su
     *    matrícula real, nunca la operativa»): la OPERATIVA no sale nunca, ni
     *    activa ni revertida, y la OFICIAL sale SIEMPRE en su sección, también
     *    en los bimestres de su tramo, con el chip «Retorno de grado»
     *    (SituacionEstudianteModel) y los datos de ese bimestre LEÍDOS de la
     *    operativa (oficialesEnTramo / sqlDatosDelPeriodo): nada se copia ni se
     *    mueve, solo cambia la fila por la que se leen. Por competencia: logro
     *    y conclusión; los criterios son de otra carga y no se trasladan.
     *    Boleta, mérito, SIAGIE, situación final y grillas no cambian;
     *  · no filtra por `estado` (como `roster_evaluacion()`): el desactivado
     *    por deuda se evalúa y se ve.
     *
     * ⚠️ NO usarlo en grillas de captura ni en validaciones (bloqueo, cierre):
     * allí manda `roster_evaluacion()` / `sqlRosterDelPeriodo()`, y meter a un
     * trasladado exigiría su conclusión antes de bloquear.
     *
     * `$colPeriodo` y `$seccion`: columnas de la consulta o ids literales;
     * nunca entrada de usuario.
     */
    public static function sqlRosterLecturaDelPeriodo(string $m, string $colPeriodo, int|string $seccion): string
    {
        return "AND ({$m}.tipo NOT IN ('trasladado', 'retirado')
                   OR EXISTS (SELECT 1 FROM calificaciones rlc
                              INNER JOIN cargas_academicas rlca ON rlca.id = rlc.carga_id
                              INNER JOIN bloqueos_competencia rlb
                                      ON rlb.carga_id       = rlc.carga_id
                                     AND rlb.competencia_id = rlc.competencia_id
                                     AND rlb.periodo_id     = rlc.periodo_id
                              WHERE rlc.matricula_id = {$m}.id
                                AND rlc.periodo_id   = {$colPeriodo}
                                AND rlca.seccion_id  = {$seccion})
                   OR EXISTS (SELECT 1 FROM conducta_confirmaciones rlcc
                              WHERE rlcc.matricula_id = {$m}.id AND rlcc.periodo_id = {$colPeriodo})
                   OR EXISTS (SELECT 1 FROM calificaciones_conducta rlcd
                              WHERE rlcd.matricula_id = {$m}.id AND rlcd.periodo_id = {$colPeriodo}
                                AND (rlcd.literal IS NOT NULL OR rlcd.nota_tutor IS NOT NULL))
                   OR EXISTS (SELECT 1 FROM inasistencias rli
                              WHERE rli.matricula_id = {$m}.id AND rli.periodo_id = {$colPeriodo}
                                AND " . AsistenciaModel::sqlConfirmada('rli') . "))
              AND {$m}.id NOT IN (SELECT rlo.matricula_operativa_id FROM retornos_grado rlo)";
    }

    /**
     * Expresión: la matrícula de la que se LEEN los datos del periodo
     * `$colPeriodo` para la fila de `$m` en una vista de LECTURA (28/10/2026):
     * si `$m` es la OFICIAL de un retorno cuyo tramo cubre ese periodo, su
     * OPERATIVA (ahí viven sus datos: Regla A); si no, `$m` mismo. Gemelo SQL de
     * oficialesEnTramo() / matriculaDelPeriodo(), visto desde la fila oficial
     * que lista sqlRosterLecturaDelPeriodo. Nunca da NULL.
     *
     * Solo para las uniones a datos POR MATRÍCULA (conducta, asistencia,
     * promedios transversales); las calificaciones por competencia se buscan
     * además en la carga EQUIVALENTE (CalificacionModel::getResumenCompetencia).
     */
    public static function sqlDatosDelPeriodo(string $m, string $colPeriodo): string
    {
        $cubre = self::sqlCubre('rgdp_r', 'rgdp_d', 'rgdp_h', 'rgdp_x');
        return "COALESCE((SELECT rgdp_r.matricula_operativa_id
                            FROM retornos_grado rgdp_r
                            INNER JOIN periodos rgdp_d ON rgdp_d.id = rgdp_r.periodo_desde_id
                            LEFT  JOIN periodos rgdp_h ON rgdp_h.id = rgdp_r.periodo_hasta_id
                            INNER JOIN periodos rgdp_x ON rgdp_x.id = {$colPeriodo}
                           WHERE rgdp_r.matricula_oficial_id = {$m}.id
                             AND {$cubre}
                           LIMIT 1), {$m}.id)";
    }

    /**
     * Expresión: la IDENTIDAD (matrícula oficial) del estudiante dueño de la
     * matrícula `$col`. Para agrupar datos de las dos matrículas bajo un solo
     * estudiante (combinada con sqlCursoElPeriodo, que elige de cuál se lee cada
     * bimestre).
     */
    public static function sqlIdentidad(string $col): string
    {
        return "COALESCE((SELECT rgi.matricula_oficial_id FROM retornos_grado rgi
                          WHERE rgi.matricula_operativa_id = {$col}), {$col})";
    }

    /**
     * Subconsulta para `IN (...)`: `$m` y su pareja de retorno, en cualquier
     * dirección. Para preguntas de EXISTENCIA sobre el estudiante completo
     * («¿la boleta ya tiene asistencia de este bimestre?»).
     */
    public static function sqlFuentes(string $m): string
    {
        return "(
                    SELECT {$m}.id
                    UNION SELECT rgf.matricula_oficial_id   FROM retornos_grado rgf
                          WHERE rgf.matricula_operativa_id = {$m}.id
                    UNION SELECT rgf.matricula_operativa_id FROM retornos_grado rgf
                          WHERE rgf.matricula_oficial_id   = {$m}.id
                )";
    }

    /**
     * «`$m` es la OPERATIVA de un retorno revertido». Queda `desactivado` al
     * revertir, pero sus bimestres del tramo siguen siendo suyos: toda consulta
     * que filtre por `estado = 'aprobada'` la añade como excepción para no
     * perderlos (como `OrdenMeritoModel::ROSTER_MERITO`).
     */
    public static function sqlOperativaRevertida(string $m): string
    {
        return "{$m}.id IN (SELECT rgo.matricula_operativa_id FROM retornos_grado rgo
                            WHERE rgo.estado = 'revertido')";
    }

    /**
     * JOIN del ROL de `$m` en su retorno (activo o revertido), con alias `$r`.
     * Columnas que habilita (ver sqlColumnasRol): retorno_rol, retorno_estado,
     * retorno_oficial, retorno_operativa.
     */
    public static function sqlJoinRol(string $m, string $r = 'rgr'): string
    {
        return "LEFT JOIN retornos_grado {$r}
                       ON {$r}.matricula_oficial_id = {$m}.id
                       OR {$r}.matricula_operativa_id = {$m}.id";
    }

    /** Columnas del rol (acompañan a sqlJoinRol). */
    public static function sqlColumnasRol(string $m, string $r = 'rgr'): string
    {
        return "CASE WHEN {$r}.matricula_oficial_id   = {$m}.id THEN 'oficial'
                     WHEN {$r}.matricula_operativa_id = {$m}.id THEN 'operativa'
                END                          AS retorno_rol,
                {$r}.estado                  AS retorno_estado,
                {$r}.matricula_oficial_id    AS retorno_oficial,
                {$r}.matricula_operativa_id  AS retorno_operativa";
    }
}
