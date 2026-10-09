<?php

namespace App\Models;

class AsistenciaModel extends BaseModel
{
    // ── PUNTOS ÚNICOS de «oficial» (29/09/2026, migraciones 068 y 069) ──
    //
    // Desde el autoguardado, una fila de `inasistencias` puede ser un BORRADOR
    // (`confirmado_en` NULL): queda «al aire» y no cuenta en nada. Hay DOS
    // preguntas distintas y cada una tiene su fragmento; ninguna consulta vuelve
    // a escribir la condición a mano:
    //   - CONFIRMADA → monitoreo (progreso, cuadros de Dirección) y la vía
    //     extraordinaria: miran lo que ya tiene el visto bueno de quien registra.
    //   - VISIBLE    → la BOLETA: confirmada Y con la asistencia de SU sección
    //     bloqueada y aprobada (cierre vigente). Decisión del usuario del
    //     29/09/2026: la asistencia pasa a exigir el bloqueo, como la conducta.
    // Protegido por `verif_asistencia_fechas.php`.

    /** Condición SQL: la fila `$i` de `inasistencias` está CONFIRMADA. */
    public static function sqlConfirmada(string $i = 'i'): string
    {
        return "{$i}.confirmado_en IS NOT NULL";
    }

    /**
     * Condición SQL: la fila `$i` es VISIBLE en boleta = confirmada y con cierre
     * de asistencia vigente en la sección de SU matrícula, en SU periodo. Va por
     * la matrícula de la fila (no la de la boleta) porque en un retorno de grado
     * cada bimestre vive en la matrícula donde se cursó (Regla A).
     */
    public static function sqlVisible(string $i = 'i'): string
    {
        return self::sqlConfirmada($i) . " AND EXISTS (
                    SELECT 1 FROM cierres_asistencia zv
                    INNER JOIN matriculas mv ON mv.seccion_id = zv.seccion_id
                    WHERE mv.id = {$i}.matricula_id AND zv.periodo_id = {$i}.periodo_id
                      AND zv.anulado_en IS NULL)";
    }

    // ── Consultas para el panel de registro ─────────────────────

    /** Secciones del año activo con info de nivel/grado para el índice. */
    public function listarSeccionesActivas(): array
    {
        return $this->query("
            SELECT
                s.id,
                s.nombre          AS seccion_nombre,
                g.nombre_display  AS grado_nombre,
                g.numero          AS grado_numero,
                n.nombre          AS nivel_nombre,
                n.id              AS nivel_id,
                a.id              AS anio_id,
                a.anio
            FROM secciones s
            INNER JOIN grados            g ON g.id = s.grado_id
            INNER JOIN niveles           n ON n.id = g.nivel_id
            INNER JOIN anios_academicos  a ON a.id = s.anio_id
            WHERE a.estado = 'activo'
              AND s.estado_nomina = 'aprobada'
            ORDER BY n.id, g.numero, s.nombre
        ");
    }

    /**
     * Periodos del año activo con flag de edición.
     * "editable" solo es true cuando el periodo está en estado 'activo'
     * y dentro del límite de notas. Mismo criterio que ConductaModel para
     * mantener coherencia entre módulos.
     *
     * ZONA HORARIA — el "ahora" lo calcula PHP (`America/Lima`, aplicado en
     * public/index.php) y viaja como parámetro preparado. NO usar NOW(): el
     * MySQL de produccion corre en UTC, 5 horas adelantado, y este flag se
     * apagaba 5 horas ANTES que el guard real de escritura
     * (`periodoEditable`, que compara con `time()` en PHP). Misma regla que
     * `PublicacionBoletaModel::ahora()`.
     */
    public function listarPeriodosActivos(): array
    {
        return $this->query("
            SELECT
                p.id,
                p.numero,
                p.nombre_display,
                p.estado,
                p.limite_notas,
                -- Asistencia por fechas (migración 069): el calendario necesita el
                -- rango del bimestre y saber si el bimestre se registra por fechas.
                p.fecha_inicio,
                p.fecha_fin,
                p.asistencia_por_fechas,
                a.anio,
                (
                    p.estado = 'activo'
                    AND (p.limite_notas IS NULL OR ? <= p.limite_notas)
                ) AS editable
            FROM periodos p
            INNER JOIN anios_academicos a ON a.id = p.anio_id
            WHERE a.estado = 'activo'
            ORDER BY p.numero
        ", [date('Y-m-d H:i:s')]);
    }

    /**
     * Progreso de llenado de incidencias por sección para un periodo dado.
     * Una sección está "al X%" según cuántas matrículas tienen su fila
     * CONFIRMADA (incluso con todos los contadores en cero, porque fue una
     * acción consciente de quien registra). Un borrador no cuenta (29/09/2026):
     * `registrados` es «confirmados», y es lo que exige el bloqueo.
     * Devuelve [seccion_id => ['esperados' => N, 'registrados' => M]].
     */
    public function getProgresoPorSeccion(int $periodoId): array
    {
        $rows = $this->query("
            SELECT
                s.id                         AS seccion_id,
                COUNT(DISTINCT m.id)         AS esperados,
                COUNT(DISTINCT i.matricula_id) AS registrados
            FROM secciones s
            INNER JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
            LEFT JOIN matriculas m
                   -- Quien CURSÓ el bimestre en la sección (cambio de sección).
                   ON " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', 's.id', $periodoId) . "
                  AND m.anio_id    = s.anio_id
                  -- Roster de evaluacion (punto unico en helpers.php): los
                  -- 'esperados' tienen que contar exactamente a quienes
                  -- aparecen en la grilla, o el avance miente.
                  " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
            LEFT JOIN inasistencias i
                   ON i.matricula_id = m.id
                  AND i.periodo_id   = ?
                  AND " . self::sqlConfirmada('i') . "
            WHERE s.estado_nomina = 'aprobada'
            GROUP BY s.id
        ", [$periodoId]);

        $mapa = [];
        foreach ($rows as $r) {
            $mapa[(int) $r['seccion_id']] = [
                'esperados'   => (int) $r['esperados'],
                'registrados' => (int) $r['registrados'],
            ];
        }
        return $mapa;
    }

    /**
     * Los 4 contadores de incidencias, en el orden en que se muestran.
     * PUNTO ÚNICO: lo usan el partial de la tabla, los totales y el imprimible.
     */
    public const CAMPOS = ['faltas', 'faltas_justificadas', 'tardanzas', 'tardanzas_justificadas'];

    /**
     * Tope duro por contador. PUNTO ÚNICO: lo usan la grilla de RA
     * (`Admin\AsistenciaController`) y la vía extraordinaria del lote. Vivía
     * como constante privada del controlador; se movió aquí el 22/09/2026 para
     * que la segunda vía no lo copiara.
     */
    public const TOPE_MAX = 99;

    /**
     * Suma los 4 contadores de un roster ya cargado, más cuántos tienen registro.
     *
     * Recibe la salida de `getEstudiantesConIncidencias` en vez de consultar otra
     * vez: el total tiene que ser el de LAS FILAS QUE SE PINTAN, no el de una
     * consulta paralela que podría aplicar otro roster. Es el mismo motivo por el
     * que el roster de asistencia es el de notas y no uno propio.
     *
     * @param  array $alumnos salida de getEstudiantesConIncidencias()
     * @return array{faltas:int,faltas_justificadas:int,tardanzas:int,tardanzas_justificadas:int,registrados:int}
     */
    public static function totalesIncidencias(array $alumnos): array
    {
        $totales = array_fill_keys(self::CAMPOS, 0) + ['registrados' => 0];

        foreach ($alumnos as $a) {
            $inc = $a['incidencias'] ?? [];
            foreach (self::CAMPOS as $campo) {
                $totales[$campo] += (int) ($inc[$campo] ?? 0);
            }
            if (!empty($inc['registrado'])) {
                $totales['registrados']++;
            }
        }
        return $totales;
    }

    /**
     * Estudiantes de una sección con sus incidencias del periodo.
     * Devuelve una fila por estudiante con los 4 contadores (en 0 si no hay
     * registro) y `confirmado` (29/09/2026).
     *
     * $soloConfirmadas:
     *   - false → el EDITOR (grilla y vista por estudiante): ve su BORRADOR.
     *   - true  → SOLO LECTURA (imprimible, Dirección): un borrador cuenta como
     *     «sin registro» (en 0, `registrado` = false), porque aún no es oficial.
     */
    public function getEstudiantesConIncidencias(int $seccionId, int $periodoId, bool $soloConfirmadas = false): array
    {
        $alumnos = $this->query("
            SELECT
                m.id  AS matricula_id,
                CONCAT(
                    p.apellido_paterno, ' ',
                    p.apellido_materno, ', ',
                    p.nombres
                )     AS nombre_completo,
                p.dni
            FROM matriculas m
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            -- Quien CURSÓ el bimestre en la sección (cambio de sección, 07/10/2026).
            WHERE " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $seccionId, $periodoId) . "
              -- Roster de evaluacion (punto unico en helpers.php): el registro
              -- de asistencia debe cubrir exactamente a quien se evalua. El
              -- porque de cada condicion vive en el docblock del helper.
              " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
              AND m.anio_id    = (SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1)
            ORDER BY " . orden_alfabetico('p') . "
        ");

        if (empty($alumnos)) {
            return [];
        }

        $matriculaIds = array_column($alumnos, 'matricula_id');
        $placeholders = implode(',', array_fill(0, count($matriculaIds), '?'));

        $registros = $this->query("
            SELECT
                matricula_id,
                faltas,
                faltas_justificadas,
                tardanzas,
                tardanzas_justificadas,
                extraordinaria,
                confirmado_en
            FROM inasistencias i
            WHERE matricula_id IN ($placeholders)
              AND periodo_id = ?
              " . ($soloConfirmadas ? 'AND ' . self::sqlConfirmada('i') : '') . "
        ", array_merge($matriculaIds, [$periodoId]));

        $index = [];
        foreach ($registros as $r) {
            $index[(int) $r['matricula_id']] = [
                'faltas'                 => (int) $r['faltas'],
                'faltas_justificadas'    => (int) $r['faltas_justificadas'],
                'tardanzas'              => (int) $r['tardanzas'],
                'tardanzas_justificadas' => (int) $r['tardanzas_justificadas'],
                'registrado'             => true,
                // Fila creada por la vía extraordinaria de RA (migración 063).
                'extraordinaria'         => (int) $r['extraordinaria'] === 1,
                'confirmado'             => $r['confirmado_en'] !== null,
            ];
        }

        foreach ($alumnos as &$a) {
            $mid = (int) $a['matricula_id'];
            $a['incidencias'] = $index[$mid] ?? [
                'faltas'                 => 0,
                'faltas_justificadas'    => 0,
                'tardanzas'              => 0,
                'tardanzas_justificadas' => 0,
                'registrado'             => false,
                'extraordinaria'         => false,
                'confirmado'             => false,
            ];
        }

        return $alumnos;
    }

    // ── Guardar / actualizar ─────────────────────────────────────

    /**
     * Upsert atómico de los 4 contadores para una matrícula y periodo.
     * Si no había fila, se crea con registrado_por; si ya existía, se
     * actualiza dejando registrado_por con el último usuario que escribió
     * y modificado_en con NOW().
     */
    public function guardar(
        int $matriculaId,
        int $periodoId,
        int $faltas,
        int $faltasJustificadas,
        int $tardanzas,
        int $tardanzasJustificadas,
        int $userId
    ): bool {
        // 🔴 En un bimestre POR FECHAS los contadores solo los escribe
        // `recalcularContadores` (invariante de la migración 069). Escribirlos
        // aquí a mano los desincronizaría de sus fechas.
        if ($this->periodoPorFechas($periodoId)) {
            return false;
        }
        return $this->execute("
            INSERT INTO inasistencias
                (matricula_id, periodo_id, faltas, faltas_justificadas,
                 tardanzas, tardanzas_justificadas, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                faltas                  = VALUES(faltas),
                faltas_justificadas     = VALUES(faltas_justificadas),
                tardanzas               = VALUES(tardanzas),
                tardanzas_justificadas  = VALUES(tardanzas_justificadas),
                registrado_por          = VALUES(registrado_por),
                modificado_en           = NOW()
        ", [
            $matriculaId, $periodoId,
            $faltas, $faltasJustificadas,
            $tardanzas, $tardanzasJustificadas,
            $userId,
        ]);
    }

    // ── ASISTENCIA POR FECHAS (29/09/2026, migración 069) ────────
    //
    // 🔴 INVARIANTE: en un periodo con `asistencia_por_fechas = 1`, toda fila de
    // `inasistencias` con `extraordinaria = 0` tiene sus 4 contadores IGUALES al
    // conteo de sus `asistencia_incidencias`. Los escribe SOLO
    // `recalcularContadores()`, en la misma transacción que cada cambio de día:
    // fechas y números no pueden contradecirse porque hay una sola fuente.
    // El conteo lo define `sqlConteoContadores()`: una FJ va a
    // `faltas_justificadas` SOLO con el ANCLA del bimestre
    // (`AsistenciaMotivoModel::anclaDelPeriodo`); con otro motivo, a `faltas`
    // (08/10/2026, migración 076). La única otra escritura es
    // `recontarPorCambioDeAncla()`, con la misma regla, al trasladar el ancla.
    //
    // Flujo (el de los docentes): marcar un día = BORRADOR (desconfirma si
    // cambió algo); «Confirmar» = visto bueno (exige motivo en cada FJ/TJ);
    // «Bloquear y aprobar» = visto final.

    public const TIPOS = ['F', 'FJ', 'T', 'TJ'];

    /**
     * Tipo → contador de `inasistencias` (mismo orden que CAMPOS). ⚠️ Una FJ con
     * un motivo distinto del ancla del bimestre NO va a `faltas_justificadas` sino a
     * `faltas`: la regla completa está en `sqlConteoContadores()`.
     */
    public const TIPO_CAMPO = [
        'F'  => 'faltas',
        'FJ' => 'faltas_justificadas',
        'T'  => 'tardanzas',
        'TJ' => 'tardanzas_justificadas',
    ];

    /** Periodo (id, fechas, modo) o null. */
    public function periodo(int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT id, numero, nombre_display, estado, limite_notas,
                   fecha_inicio, fecha_fin, asistencia_por_fechas
            FROM periodos WHERE id = ?
        ", [$periodoId]);
    }

    /** ¿El bimestre se registra por fechas? */
    public function periodoPorFechas(int $periodoId): bool
    {
        return (int) ($this->periodo($periodoId)['asistencia_por_fechas'] ?? 0) === 1;
    }

    /**
     * Días MARCABLES del bimestre: lunes a viernes entre su inicio y su fin, sin
     * días FUTUROS (nadie falta mañana) y, desde el 30/09/2026 (migración 070),
     * sin los días NO LECTIVOS que se le pasen. Sigue siendo pura: quien llama
     * lee los no lectivos de `AsistenciaJornadaModel::noLectivos()`; sin ellos
     * (estadísticas) se comporta como antes. La definición de «día hábil» es la
     * de la planilla (`PlanillaAsistenciaModel::diasPlanilla`): no se escribe otra.
     *
     * @param array{fecha_inicio:string, fecha_fin:string} $periodo
     * @param array<string, string> $noLectivos [fecha => motivo]
     * @return list<string> fechas 'AAAA-MM-DD' en orden
     */
    public static function diasMarcables(array $periodo, ?string $hoy = null, array $noLectivos = []): array
    {
        $ini = substr((string) ($periodo['fecha_inicio'] ?? ''), 0, 10);
        $fin = substr((string) ($periodo['fecha_fin'] ?? ''), 0, 10);
        if ($ini === '' || $fin === '') {
            return [];
        }
        $tope = min($fin, $hoy ?? date('Y-m-d'));

        $out = [];
        foreach (PlanillaAsistenciaModel::mesesDelPeriodo($periodo) as $mes) {
            foreach (PlanillaAsistenciaModel::diasPlanilla($mes['anio'], $mes['mes']) as $d) {
                $f = sprintf('%04d-%02d-%02d', $mes['anio'], $mes['mes'], $d['dia']);
                if ($f >= $ini && $f <= $tope && !isset($noLectivos[$f])) {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    /**
     * CALENDARIO del bimestre para las pantallas: por mes, los días hábiles
     * (lun–vie) que caen dentro del bimestre, con `marcable` = no es futuro NI
     * no lectivo, y `no_lectivo` = su motivo (o null). Misma definición de día
     * hábil que `diasMarcables` y la planilla.
     *
     * @param array<string, string> $noLectivos [fecha => motivo]
     * @return array<string, array{nombre:string, anio:int, mes:int,
     *         dias:list<array{fecha:string, dia:int, abrev:string, marcable:bool, no_lectivo:?string}>}>
     *         clave 'AAAA-MM'; los meses sin ningún día del bimestre no salen
     */
    public static function calendario(array $periodo, ?string $hoy = null, array $noLectivos = []): array
    {
        $ini = substr((string) ($periodo['fecha_inicio'] ?? ''), 0, 10);
        $fin = substr((string) ($periodo['fecha_fin'] ?? ''), 0, 10);
        $hoy ??= date('Y-m-d');
        if ($ini === '' || $fin === '') {
            return [];
        }

        $out = [];
        foreach (PlanillaAsistenciaModel::mesesDelPeriodo($periodo) as $clave => $mes) {
            $dias = [];
            foreach (PlanillaAsistenciaModel::diasPlanilla($mes['anio'], $mes['mes']) as $d) {
                $f = sprintf('%04d-%02d-%02d', $mes['anio'], $mes['mes'], $d['dia']);
                if ($f >= $ini && $f <= $fin) {
                    $nl     = $noLectivos[$f] ?? null;
                    $dias[] = ['fecha' => $f, 'dia' => $d['dia'], 'abrev' => $d['abrev'],
                               'marcable' => $f <= $hoy && $nl === null, 'no_lectivo' => $nl];
                }
            }
            if ($dias !== []) {
                $out[$clave] = ['nombre' => $mes['nombre'], 'anio' => $mes['anio'], 'mes' => $mes['mes'], 'dias' => $dias];
            }
        }
        return $out;
    }

    /**
     * Resumen COMPACTO de las fechas de UN estudiante para el imprimible y la
     * consulta de Dirección: «F: 03/09, 17/09 · FJ: 10/09». Sin el motivo: el
     * motivo de cada justificación va en el anexo «Detalle de justificaciones»
     * (`justificaciones()`), decisión del usuario del 29/09/2026 —repetido aquí
     * desbordaba la columna—. Vacío si no tiene incidencias.
     *
     * @param array<string, array{tipo:string}> $dias salida de incidenciasDe()[mid]
     */
    public static function resumenFechas(array $dias): string
    {
        $porTipo = [];
        foreach ($dias as $fecha => $x) {
            $porTipo[$x['tipo']][] = substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2);
        }
        $partes = [];
        foreach (self::TIPOS as $t) {
            if (!empty($porTipo[$t])) {
                $partes[] = $t . ': ' . implode(', ', $porTipo[$t]);
            }
        }
        return implode(' · ', $partes);
    }

    /**
     * Filas del anexo «DETALLE DE JUSTIFICACIONES» (29/09/2026): una por cada FJ
     * o TJ, en el orden de la lista y por fecha. Lo usa la consulta de Dirección
     * (debajo de la tabla, que ya tiene su columna «Fechas»).
     *
     * @param array $estudiantes  roster en orden de lista (getEstudiantesConIncidencias)
     * @param array $incidencias  incidenciasDe(...) [mid => [fecha => {tipo, motivo}]]
     * @return list<array{num:int, nombre:string, fecha:string, tipo:string, motivo:string}>
     */
    public static function justificaciones(array $estudiantes, array $incidencias): array
    {
        return self::detalleIncidencias($estudiantes, $incidencias, ['FJ', 'TJ']);
    }

    /**
     * Filas del anexo «DETALLE DE INCIDENCIAS» del imprimible (29/09/2026): una
     * por cada fecha con F, FJ, T o TJ (motivo solo en FJ/TJ), en el orden de la
     * lista y por fecha. Reemplaza a la columna «Fechas» del registro impreso
     * (decisión del usuario): ninguna fecha sale del documento.
     *
     * @param list<string> $tipos  tipos que entran (por defecto, los cuatro)
     * @return list<array{num:int, nombre:string, fecha:string, tipo:string, motivo:string}>
     */
    public static function detalleIncidencias(array $estudiantes, array $incidencias, array $tipos = self::TIPOS): array
    {
        $filas = [];
        foreach (array_values($estudiantes) as $i => $est) {
            foreach ($incidencias[(int) $est['matricula_id']] ?? [] as $fecha => $x) {
                if (!in_array($x['tipo'], $tipos, true)) {
                    continue;
                }
                $filas[] = [
                    'num'    => $i + 1,
                    'nombre' => (string) $est['nombre_completo'],
                    'fecha'  => substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4),
                    'tipo'   => $x['tipo'],
                    'motivo' => (string) ($x['motivo'] ?? ''),
                ];
            }
        }
        return $filas;
    }

    /**
     * Incidencias por fecha de varias matrículas en un periodo, con el nombre
     * del motivo. Con $soloConfirmadas, solo las de filas CONFIRMADAS y que no
     * sean de la vía extraordinaria (lectura oficial: imprimible, Dirección).
     *
     * @return array<int, array<string, array{tipo:string, motivo_id:?int, motivo:?string}>>
     *         [matricula_id => [fecha => …]]
     */
    public function incidenciasDe(array $matriculaIds, int $periodoId, bool $soloConfirmadas = false): array
    {
        $ids = array_values(array_filter(array_map('intval', $matriculaIds)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $filtro = $soloConfirmadas
            ? "AND EXISTS (SELECT 1 FROM inasistencias i
                           WHERE i.matricula_id = x.matricula_id AND i.periodo_id = x.periodo_id
                             AND i.extraordinaria = 0 AND " . self::sqlConfirmada('i') . ")"
            : '';

        $out = [];
        foreach ($this->query("
            SELECT x.matricula_id, x.fecha, x.tipo, x.motivo_id, am.nombre AS motivo
            FROM asistencia_incidencias x
            LEFT JOIN asistencia_motivos am ON am.id = x.motivo_id
            WHERE x.periodo_id = ? AND x.matricula_id IN ({$ph})
            {$filtro}
            ORDER BY x.matricula_id, x.fecha
        ", array_merge([$periodoId], $ids)) as $r) {
            $out[(int) $r['matricula_id']][(string) $r['fecha']] = [
                'tipo'      => (string) $r['tipo'],
                'motivo_id' => $r['motivo_id'] !== null ? (int) $r['motivo_id'] : null,
                'motivo'    => $r['motivo'],
            ];
        }
        return $out;
    }

    /**
     * 🔴 PUNTO ÚNICO de la REGLA DE CONTEO de los 4 contadores (08/10/2026,
     * migración 076): las 4 expresiones, en el orden de CAMPOS, sobre
     * `asistencia_incidencias x`.
     *
     * Una FALTA solo es justificada con el ANCLA del bimestre (`$anclaId`, de
     * `AsistenciaMotivoModel::anclaDelPeriodo`); una FJ con otro motivo cuenta
     * como F. Las TARDANZAS justificadas suman todos los motivos. La marca del
     * día NO cambia (sigue siendo FJ con su motivo): solo dónde se cuenta.
     * Lo usan `recalcularContadores`, `recontarPorCambioDeAncla` y
     * `verif_asistencia_fechas.php`.
     *
     * @return list<string>  [faltas, faltas_justificadas, tardanzas, tardanzas_justificadas]
     */
    public static function sqlConteoContadores(?int $anclaId): array
    {
        $ancla = (int) ($anclaId ?? 0); // entero: se interpola sin riesgo
        // COALESCE: sin motivo (no debería existir: CHECK de la 070) la FJ cuenta
        // como F, nunca se pierde de los dos contadores por un NULL.
        $fjPrincipal = "(x.tipo = 'FJ' AND COALESCE(x.motivo_id, 0) = {$ancla})";
        return [
            "COALESCE(SUM(x.tipo = 'F' OR (x.tipo = 'FJ' AND NOT {$fjPrincipal})), 0)",
            "COALESCE(SUM({$fjPrincipal}), 0)",
            "COALESCE(SUM(x.tipo = 'T'),  0)",
            "COALESCE(SUM(x.tipo = 'TJ'), 0)",
        ];
    }

    /**
     * 🔴 PUNTO ÚNICO que escribe los 4 contadores de un bimestre por fechas:
     * los CUENTA de `asistencia_incidencias` (regla de `sqlConteoContadores`) y
     * deja la fila como BORRADOR (desconfirmada). Crea la fila si no existía.
     * Sin transacción propia: la owna quien llama (`marcarDia`, `confirmar`).
     */
    private function recalcularContadores(int $matriculaId, int $periodoId, int $userId): void
    {
        $ancla  = (new AsistenciaMotivoModel())->anclaDelPeriodo($periodoId);
        $conteo = implode(",\n                   ", self::sqlConteoContadores($ancla));
        $this->execute("
            INSERT INTO inasistencias
                (matricula_id, periodo_id, faltas, faltas_justificadas,
                 tardanzas, tardanzas_justificadas, registrado_por)
            SELECT ?, ?,
                   {$conteo},
                   ?
            FROM asistencia_incidencias x
            WHERE x.matricula_id = ? AND x.periodo_id = ?
            ON DUPLICATE KEY UPDATE
                faltas                 = VALUES(faltas),
                faltas_justificadas    = VALUES(faltas_justificadas),
                tardanzas              = VALUES(tardanzas),
                tardanzas_justificadas = VALUES(tardanzas_justificadas),
                registrado_por         = VALUES(registrado_por),
                modificado_en          = NOW(),
                confirmado_en          = NULL,
                confirmado_por         = NULL
        ", [$matriculaId, $periodoId, $userId, $matriculaId, $periodoId]);
    }

    /**
     * RECUENTO por CAMBIO DE ANCLA (08/10/2026, migración 076): rehace los 4
     * contadores de toda fila de un bimestre por fechas que aún sigue al ancla
     * del catálogo (sin ancla congelada), con la misma regla de
     * `sqlConteoContadores`. NO desconfirma (decisión del usuario: las marcas
     * del día no cambian, solo dónde se cuentan). Los bimestres congelados no se
     * tocan. Sin transacción propia: la owna `AsistenciaMotivoModel::hacerPrincipal`.
     *
     * @return int  filas cuyos contadores cambiaron
     */
    public function recontarPorCambioDeAncla(int $anclaId): int
    {
        [$f, $fj, $t, $tj] = self::sqlConteoContadores($anclaId);
        $stmt = $this->db->prepare("
            UPDATE inasistencias i
            INNER JOIN periodos p ON p.id = i.periodo_id
                   AND p.asistencia_por_fechas = 1 AND p.asistencia_motivo_principal_id IS NULL
            INNER JOIN (
                SELECT x.matricula_id, x.periodo_id,
                       {$f} AS f, {$fj} AS fj, {$t} AS t, {$tj} AS tj
                FROM asistencia_incidencias x
                GROUP BY x.matricula_id, x.periodo_id
            ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
            SET i.faltas = c.f, i.faltas_justificadas = c.fj,
                i.tardanzas = c.t, i.tardanzas_justificadas = c.tj,
                i.modificado_en = NOW()
            WHERE i.extraordinaria = 0
              AND (i.faltas <> c.f OR i.faltas_justificadas <> c.fj
                OR i.tardanzas <> c.t OR i.tardanzas_justificadas <> c.tj)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Los 4 contadores y el estado de confirmación de la fila (o ceros). */
    public function contadoresDe(int $matriculaId, int $periodoId): array
    {
        $r = $this->queryOne("
            SELECT faltas, faltas_justificadas, tardanzas, tardanzas_justificadas, confirmado_en
            FROM inasistencias WHERE matricula_id = ? AND periodo_id = ?
        ", [$matriculaId, $periodoId]);

        $out = [];
        foreach (self::CAMPOS as $c) {
            $out[$c] = (int) ($r[$c] ?? 0);
        }
        $out['confirmado'] = ($r['confirmado_en'] ?? null) !== null;
        return $out;
    }

    /**
     * AUTOGUARDADO de UN día: marca (`$tipo` en TIPOS) o desmarca (`$tipo` null
     * = «✓ asistió») la fecha. FJ/TJ EXIGEN motivo del catálogo vigente (30/09/2026,
     * migración 070: también un CHECK en la base). Si algo CAMBIÓ, recalcula los
     * contadores y la fila queda como borrador. En la MISMA transacción toma la
     * LISTA DEL DÍA de la sección (`AsistenciaJornadaModel::tomar`): cualquier
     * marca significa que ese día se pasó lista. Valida el DÍA contra
     * `diasMarcables` (sin no lectivos).
     *
     * @return array{ok:bool, mensaje:string, contadores?:array, jornada_tomada?:bool}
     */
    public function marcarDia(int $matriculaId, int $periodoId, string $fecha, ?string $tipo, ?int $motivoId, int $userId): array
    {
        $periodo = $this->periodo($periodoId);
        if ($periodo === null || (int) $periodo['asistencia_por_fechas'] !== 1) {
            return ['ok' => false, 'mensaje' => 'Este bimestre no se registra por fechas.'];
        }
        $jornadas = new AsistenciaJornadaModel();
        if (!in_array($fecha, self::diasMarcables($periodo, null, $jornadas->noLectivos()), true)) {
            return ['ok' => false, 'mensaje' => 'Ese día no se puede marcar (fuera del bimestre, fin de semana, no lectivo o futuro).'];
        }
        if ($tipo !== null && !in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'mensaje' => 'Tipo de incidencia no válido.'];
        }
        // El motivo es solo de las justificadas (también lo impide un CHECK).
        if ($tipo === null || !in_array($tipo, ['FJ', 'TJ'], true)) {
            $motivoId = null;
        }
        // 🔴 Una justificada SIN motivo no existe, ni como borrador (30/09/2026).
        if ($motivoId === null && in_array($tipo, ['FJ', 'TJ'], true)) {
            return ['ok' => false, 'mensaje' => 'Elige el motivo de la justificación.'];
        }
        if ($motivoId !== null && !(new AsistenciaMotivoModel())->esVigente($motivoId)) {
            return ['ok' => false, 'mensaje' => 'Motivo no válido o retirado.'];
        }
        $seccionId = $this->seccionDeMatricula($matriculaId);
        if ($seccionId === null) {
            return ['ok' => false, 'mensaje' => 'Matrícula no encontrada.'];
        }

        $this->beginTransaction();
        try {
            $jornadas->tomar($seccionId, $periodoId, $fecha, $userId);

            $previa = $this->queryOne("
                SELECT id, tipo, motivo_id FROM asistencia_incidencias
                WHERE matricula_id = ? AND fecha = ?
                FOR UPDATE
            ", [$matriculaId, $fecha]);

            $antes   = $previa ? [$previa['tipo'], $previa['motivo_id'] !== null ? (int) $previa['motivo_id'] : null] : [null, null];
            $cambio  = $antes !== [$tipo, $motivoId];

            if ($cambio) {
                if ($tipo === null) {
                    $this->execute("DELETE FROM asistencia_incidencias WHERE id = ?", [(int) $previa['id']]);
                } else {
                    $this->execute("
                        INSERT INTO asistencia_incidencias
                            (matricula_id, periodo_id, fecha, tipo, motivo_id, registrado_por)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            tipo = VALUES(tipo), motivo_id = VALUES(motivo_id),
                            registrado_por = VALUES(registrado_por), modificado_en = NOW()
                    ", [$matriculaId, $periodoId, $fecha, $tipo, $motivoId, $userId]);
                }
                $this->recalcularContadores($matriculaId, $periodoId, $userId);
            }
            $this->commit();
        } catch (\Throwable $ex) {
            $this->rollback();
            log_error('asistencia.marcarDia', ['err' => $ex->getMessage()]);
            return ['ok' => false, 'mensaje' => 'Error al guardar el día.'];
        }

        return ['ok' => true, 'mensaje' => 'Guardado como borrador.',
                'contadores' => $this->contadoresDe($matriculaId, $periodoId), 'jornada_tomada' => true];
    }

    /**
     * CONFIRMAR el registro de un estudiante en un bimestre por fechas: exige
     * motivo en TODA FJ/TJ (desde la 070 es solo DEFENSA: el CHECK
     * `chk_justificada_con_motivo` ya impide guardarlas sin él), recalcula los contadores (garantiza el invariante)
     * y marca la fila como confirmada. Sin incidencias, confirma una fila en
     * ceros («sin incidencias», que es un DATO). Todo en una transacción.
     *
     * @return array{ok:bool, mensaje:string, sin_motivo?:int}
     */
    public function confirmar(int $matriculaId, int $periodoId, int $userId): array
    {
        if (!$this->periodoPorFechas($periodoId)) {
            return ['ok' => false, 'mensaje' => 'Este bimestre no se registra por fechas.'];
        }

        $this->beginTransaction();
        try {
            $sinMotivo = (int) ($this->queryOne("
                SELECT COUNT(*) AS n FROM asistencia_incidencias
                WHERE matricula_id = ? AND periodo_id = ?
                  AND tipo IN ('FJ', 'TJ') AND motivo_id IS NULL
            ", [$matriculaId, $periodoId])['n'] ?? 0);

            if ($sinMotivo > 0) {
                $this->rollback();
                return [
                    'ok'         => false,
                    'mensaje'    => "Falta el motivo de {$sinMotivo} " . ($sinMotivo === 1 ? 'justificación' : 'justificaciones') . '.',
                    'sin_motivo' => $sinMotivo,
                ];
            }

            $this->recalcularContadores($matriculaId, $periodoId, $userId);
            $this->execute("
                UPDATE inasistencias SET confirmado_en = NOW(), confirmado_por = ?
                WHERE matricula_id = ? AND periodo_id = ?
            ", [$userId, $matriculaId, $periodoId]);
            $this->commit();
            return ['ok' => true, 'mensaje' => 'Confirmado.'];
        } catch (\Throwable $ex) {
            $this->rollback();
            log_error('asistencia.confirmar', ['err' => $ex->getMessage()]);
            return ['ok' => false, 'mensaje' => 'Error al confirmar.'];
        }
    }

    /**
     * «Confirmar todo»: confirma a cada estudiante del roster aún sin confirmar.
     * Los que tienen justificaciones sin motivo se saltan y se devuelven.
     *
     * @return array{confirmados:int, sin_motivo:list<string>}
     */
    public function confirmarSeccion(int $seccionId, int $periodoId, int $userId): array
    {
        $res = ['confirmados' => 0, 'sin_motivo' => []];
        foreach ($this->getEstudiantesConIncidencias($seccionId, $periodoId) as $a) {
            if (!empty($a['incidencias']['confirmado'])) {
                continue;
            }
            $r = $this->confirmar((int) $a['matricula_id'], $periodoId, $userId);
            if ($r['ok']) {
                $res['confirmados']++;
            } elseif (isset($r['sin_motivo'])) {
                $res['sin_motivo'][] = (string) $a['nombre_completo'];
            }
        }
        return $res;
    }

    // ── Vía EXTRAORDINARIA (bimestre cerrado, migración 063) ─────

    /**
     * Condición SQL: la matrícula `$m` NO tiene fila de asistencia en el
     * periodo `$per`. PUNTO ÚNICO de «le falta asistencia» para la vía
     * extraordinaria: la usan el lote (qué filas ofrece), su POST (re-chequeo)
     * y el listado de /rectificaciones (quién aparece).
     *
     * Sin fila ⟺ la boleta pinta GUION (F1, `tieneRegistroUnion`). Una fila
     * con los 4 contadores en 0 es un DATO («sin incidencias») y no se toca.
     *
     * 🔴 Mira TODAS las fuentes que une la boleta (retorno de grado), no solo
     * `$m`: la boleta SUMA la asistencia entre fuentes, así que una fila nueva
     * donde la oficial ya tiene la suya duplicaría las faltas.
     *
     * @param string $m   alias de `matriculas` en la consulta que la incrusta
     * @param string $per alias de `periodos`
     */
    public static function sqlSinRegistro(string $m = 'm', string $per = 'per'): string
    {
        // Solo lo CONFIRMADO cierra la vía (29/09/2026): un borrador que quedó
        // al aire no es asistencia registrada. `registrarExtraordinaria` pisa ese
        // borrador en vez de chocar con la UNIQUE.
        return "NOT EXISTS (
                    SELECT 1 FROM inasistencias ix
                    WHERE ix.periodo_id = {$per}.id
                      AND ix.matricula_id IN " . CalificacionModel::sqlFuentesBoleta($m) . "
                      AND " . self::sqlConfirmada('ix') . "
                )";
    }

    /**
     * ¿Admite asistencia EXTRAORDINARIA? Periodo CERRADO, matrícula del
     * roster de evaluación y sin fila previa. Es el re-chequeo del POST: el
     * lote no escribe sin esto.
     */
    public function admiteExtraordinaria(int $matriculaId, int $periodoId): bool
    {
        $fila = $this->queryOne("
            SELECT 1 AS ok
            FROM matriculas m
            INNER JOIN periodos per ON per.id = ? AND per.anio_id = m.anio_id
            WHERE m.id = ?
              AND per.estado = 'cerrado'
              " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
              AND " . self::sqlSinRegistro('m', 'per') . "
        ", [$periodoId, $matriculaId]);

        return $fila !== null;
    }

    /**
     * Alta EXTRAORDINARIA de los 4 contadores de un bimestre cerrado.
     *
     * 🔴 NUNCA PISA LO CONFIRMADO. Si ya hay una fila CONFIRMADA, lanza y la
     * transacción del llamante hace rollback. Si solo hay un BORRADOR que quedó
     * al aire (29/09/2026: la vía mira solo lo confirmado), lo reemplaza. Los 4
     * contadores son INDEPENDIENTES (ver admin.md): no se restan ni se derivan.
     * Queda CONFIRMADA al registrarse: es el acto de RA, no un borrador. Es por
     * diseño 4 números sin fechas (`extraordinaria = 1` la saca del invariante
     * de la asistencia por fechas).
     *
     * ⚠️ No abre transacción: la owna quien llama (el lote escribe notas,
     * conducta y asistencia juntas). Mismo criterio que
     * NotaExternaModel::registrarLote.
     */
    public function registrarExtraordinaria(
        int $matriculaId,
        int $periodoId,
        int $faltas,
        int $faltasJustificadas,
        int $tardanzas,
        int $tardanzasJustificadas,
        string $motivo,
        int $userId
    ): void {
        $previa = $this->queryOne("
            SELECT id, confirmado_en FROM inasistencias
            WHERE matricula_id = ? AND periodo_id = ?
            FOR UPDATE
        ", [$matriculaId, $periodoId]);

        if ($previa !== null && $previa['confirmado_en'] !== null) {
            throw new \RuntimeException('El estudiante ya tiene asistencia confirmada en ese bimestre.');
        }

        $valores = [$faltas, $faltasJustificadas, $tardanzas, $tardanzasJustificadas, $motivo, $userId, $userId];
        if ($previa !== null) {
            $this->execute("
                UPDATE inasistencias
                SET faltas = ?, faltas_justificadas = ?, tardanzas = ?, tardanzas_justificadas = ?,
                    extraordinaria = 1, motivo_extraordinaria = ?, registrado_por = ?,
                    confirmado_en = NOW(), confirmado_por = ?, modificado_en = NOW()
                WHERE id = ? AND confirmado_en IS NULL
            ", [...$valores, (int) $previa['id']]);
            return;
        }

        $this->execute("
            INSERT INTO inasistencias
                (matricula_id, periodo_id, faltas, faltas_justificadas,
                 tardanzas, tardanzas_justificadas,
                 extraordinaria, motivo_extraordinaria, registrado_por,
                 confirmado_en, confirmado_por)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), ?)
        ", [$matriculaId, $periodoId, ...$valores]);
    }

    // ── Consultas para boleta ────────────────────────────────────

    /**
     * Incidencias de una matrícula en un periodo específico (para la boleta).
     * Devuelve los 4 contadores en cero si no hay fila guardada, así la
     * boleta nunca falla por falta de datos.
     */
    public function getDelBimestre(int $matriculaId, int $periodoId): array
    {
        // Solo lo VISIBLE (confirmado + sección bloqueada), 29/09/2026.
        $row = $this->queryOne("
            SELECT i.faltas, i.faltas_justificadas, i.tardanzas, i.tardanzas_justificadas
            FROM inasistencias i
            WHERE i.matricula_id = ? AND i.periodo_id = ?
              AND " . self::sqlVisible('i') . "
        ", [$matriculaId, $periodoId]);

        if (!$row) {
            return [
                'faltas'                 => 0,
                'faltas_justificadas'    => 0,
                'tardanzas'              => 0,
                'tardanzas_justificadas' => 0,
            ];
        }

        return [
            'faltas'                 => (int) $row['faltas'],
            'faltas_justificadas'    => (int) $row['faltas_justificadas'],
            'tardanzas'              => (int) $row['tardanzas'],
            'tardanzas_justificadas' => (int) $row['tardanzas_justificadas'],
        ];
    }

    /**
     * Acumulado anual hasta el periodo dado (incluido).
     * Suma los contadores de todos los periodos del año académico cuyo
     * "numero" sea <= al numero del periodo de la boleta. Esto evita que
     * una boleta del I Bimestre incluya datos de bimestres posteriores.
     */
    public function getAcumuladoAnual(int $matriculaId, int $periodoIdHasta): array
    {
        $row = $this->queryOne("
            SELECT
                COALESCE(SUM(i.faltas), 0)                 AS faltas,
                COALESCE(SUM(i.faltas_justificadas), 0)    AS faltas_justificadas,
                COALESCE(SUM(i.tardanzas), 0)              AS tardanzas,
                COALESCE(SUM(i.tardanzas_justificadas), 0) AS tardanzas_justificadas
            FROM inasistencias i
            INNER JOIN periodos p_ref ON p_ref.id = ?
            INNER JOIN periodos p_row ON p_row.id = i.periodo_id
            WHERE i.matricula_id = ?
              AND p_row.anio_id  = p_ref.anio_id
              AND p_row.numero  <= p_ref.numero
              -- Solo lo VISIBLE, igual que el bimestre suelto (29/09/2026).
              AND " . self::sqlVisible('i') . "
        ", [$periodoIdHasta, $matriculaId]);

        return [
            'faltas'                 => (int) ($row['faltas']                 ?? 0),
            'faltas_justificadas'    => (int) ($row['faltas_justificadas']    ?? 0),
            'tardanzas'              => (int) ($row['tardanzas']              ?? 0),
            'tardanzas_justificadas' => (int) ($row['tardanzas_justificadas'] ?? 0),
        ];
    }

    // ── Variantes por UNIÓN para el retorno de grado ─────────────
    // Una estudiante en retorno tiene dos matrículas (oficial + operativa) y su
    // asistencia queda repartida por bimestre. La boleta (siempre la oficial)
    // suma ambas: por bimestre solo una tiene datos, así que la suma no infla.
    // Con una sola matrícula se comportan igual que los métodos base.

    public function getDelBimestreUnion(array $matriculaIds, int $periodoId): array
    {
        if (count($matriculaIds) <= 1) {
            return $this->getDelBimestre((int) ($matriculaIds[0] ?? 0), $periodoId);
        }
        return $this->sumarAsistencias(array_map(
            fn($id) => $this->getDelBimestre((int) $id, $periodoId),
            $matriculaIds
        ));
    }

    /**
     * ¿Alguna de estas matrículas tiene FILA de asistencia en el periodo?
     *
     * Es la pregunta que `getDelBimestre` no puede responder: devuelve los 4
     * contadores en CERO tanto cuando el registro dice "no faltó ningún día"
     * como cuando NADIE registró nada. Para la boleta esa diferencia es todo:
     * un cero se lee como dato real, y en el segundo caso no hay dato.
     *
     * ⚠️ Va por UNIÓN igual que `getDelBimestreUnion`, y no es un detalle: en un
     * retorno de grado la asistencia queda repartida por bimestre entre la
     * oficial y la operativa. Preguntando matrícula por matrícula, la boleta de
     * B1 de la operativa 692 —que no tiene fila propia— saldría en guion pese a
     * que su fila de B1 vive en la oficial 190. Medido: es exactamente el caso
     * del retorno #1, en los dos sentidos.
     *
     * Sin fila NO es lo mismo que "sin incidencias": el registro escribe una
     * fila por alumno aunque vaya en cero (medido: 197 filas así en B1 y 173 en
     * B2), y esas conservan su 0.
     */
    public function tieneRegistroUnion(array $matriculaIds, int $periodoId): bool
    {
        $ids = array_values(array_filter(array_map('intval', $matriculaIds)));
        if (empty($ids)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // «Tiene registro» = tiene fila VISIBLE (29/09/2026): un borrador o una
        // sección sin bloquear se pintan con guion, no con ceros.
        return (bool) $this->queryOne("
            SELECT 1 AS hay
            FROM inasistencias i
            WHERE i.periodo_id = ?
              AND i.matricula_id IN ({$placeholders})
              AND " . self::sqlVisible('i') . "
            LIMIT 1
        ", array_merge([$periodoId], $ids));
    }

    public function getAcumuladoAnualUnion(array $matriculaIds, int $periodoIdHasta): array
    {
        if (count($matriculaIds) <= 1) {
            return $this->getAcumuladoAnual((int) ($matriculaIds[0] ?? 0), $periodoIdHasta);
        }
        return $this->sumarAsistencias(array_map(
            fn($id) => $this->getAcumuladoAnual((int) $id, $periodoIdHasta),
            $matriculaIds
        ));
    }

    /** Suma campo a campo los contadores de asistencia de varias matrículas. */
    private function sumarAsistencias(array $items): array
    {
        $keys = ['faltas', 'faltas_justificadas', 'tardanzas', 'tardanzas_justificadas'];
        $out  = array_fill_keys($keys, 0);
        foreach ($items as $it) {
            foreach ($keys as $k) {
                $out[$k] += (int) ($it[$k] ?? 0);
            }
        }
        return $out;
    }

    // ── Cierre (aprobacion y bloqueo) por seccion ────────────────
    // Espejo de una sola etapa del cierre de conducta: RA bloquea la
    // seccion y el registro queda de solo lectura. Sin fila = 0
    // incidencias (estado valido), asi que NO exige completitud.

    /** Cierre vigente (anulado_en IS NULL) de una seccion+periodo, o null. */
    public function getCierreVigente(int $seccionId, int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT * FROM cierres_asistencia
            WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL
            ORDER BY id DESC LIMIT 1
        ", [$seccionId, $periodoId]);
    }

    /** Cierre vigente con el nombre completo de quien bloqueó (para el imprimible). */
    public function getCierreDetalle(int $seccionId, int $periodoId): ?array
    {
        return $this->queryOne("
            SELECT ca.*,
                   CONCAT(pr.apellido_paterno, ' ', pr.apellido_materno, ', ', pr.nombres) AS ra_nombre
            FROM cierres_asistencia ca
            INNER JOIN usuarios ur ON ur.id = ca.ra_bloqueado_por
            INNER JOIN personas pr ON pr.id = ur.persona_id
            WHERE ca.seccion_id = ? AND ca.periodo_id = ? AND ca.anulado_en IS NULL
            ORDER BY ca.id DESC LIMIT 1
        ", [$seccionId, $periodoId]);
    }

    /**
     * El auxiliar (o RA) bloquea/aprueba el registro de asistencia de la sección.
     *
     * Desde el 29/09/2026 exige a TODOS los estudiantes CONFIRMADOS (decisión b
     * del usuario). $forzado = true lo usa SOLO el director
     * (`Director\BloqueoController::bloquearAsistencia`): bloquea aunque falten,
     * y lo no confirmado queda fuera de la boleta (guion, `sqlVisible`).
     *
     * @return array{ok:bool,mensaje:string}
     */
    public function bloquearRA(int $seccionId, int $periodoId, int $userId, bool $forzado = false): array
    {
        if ($this->getCierreVigente($seccionId, $periodoId)) {
            return ['ok' => false, 'mensaje' => 'La asistencia de esta seccion ya esta bloqueada.'];
        }
        if (!$forzado) {
            $p = $this->getProgresoPorSeccion($periodoId)[$seccionId] ?? ['esperados' => 0, 'registrados' => 0];
            if ($p['registrados'] < $p['esperados']) {
                return ['ok' => false, 'mensaje' =>
                    "Faltan estudiantes por confirmar ({$p['registrados']}/{$p['esperados']}). " .
                    'Confirma el registro de todos antes de bloquear.'];
            }
            // Bimestre por fechas: TODO día marcable con su lista tomada
            // (30/09/2026, migración 070). El director (forzado) no lo exige.
            $periodo = $this->periodo($periodoId);
            if ($periodo !== null && (int) $periodo['asistencia_por_fechas'] === 1) {
                // Y no antes del ÚLTIMO día del bimestre (09/10/2026): `diasSinTomar`
                // no ve los días futuros, y tras el bloqueo ya no se registrarían.
                if (!self::bimestreTerminado($periodo)) {
                    return ['ok' => false, 'mensaje' =>
                        'El bimestre aún no termina: podrás bloquear desde el ' .
                        self::ddmm((string) $periodo['fecha_fin']) . ', su último día.'];
                }
                $sinTomar = (new AsistenciaJornadaModel())->diasSinTomar($seccionId, $periodo);
                if ($sinTomar !== []) {
                    $lista = implode(', ', array_map(static fn(string $f): string => substr($f, 8, 2) . '/' . substr($f, 5, 2), $sinTomar));
                    return ['ok' => false, 'mensaje' =>
                        'Hay ' . count($sinTomar) . " día(s) sin lista tomada ({$lista}). " .
                        'Pasa lista de esos días (o que se declaren no lectivos) antes de bloquear.'];
                }
            }
        }
        $ok = $this->execute("
            INSERT INTO cierres_asistencia (seccion_id, periodo_id, ra_bloqueado_en, ra_bloqueado_por)
            VALUES (?, ?, NOW(), ?)
        ", [$seccionId, $periodoId, $userId]);

        return ['ok' => $ok, 'mensaje' => $ok ? 'Asistencia bloqueada y aprobada.' : 'Error al bloquear.'];
    }

    /**
     * ¿Ya llegó el ÚLTIMO día del bimestre? El auxiliar/RA no bloquea antes
     * (09/10/2026); el director (forzado) sí. «Hoy» lo da PHP (America/Lima),
     * no NOW() de MySQL — mismo criterio que `listarPeriodosActivos`.
     *
     * @param array{fecha_fin:string} $periodo
     */
    public static function bimestreTerminado(array $periodo, ?string $hoy = null): bool
    {
        return ($hoy ?? date('Y-m-d')) >= substr((string) ($periodo['fecha_fin'] ?? ''), 0, 10);
    }

    /** 'AAAA-MM-DD…' → 'DD/MM'. */
    public static function ddmm(string $fecha): string
    {
        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2);
    }

    /**
     * Ids de las secciones con la asistencia BLOQUEADA (cierre vigente) en un
     * periodo, para el badge del índice.
     *
     * @return array<int, true> [seccion_id => true]
     */
    public function seccionesBloqueadas(int $periodoId): array
    {
        $out = [];
        foreach ($this->query("
            SELECT DISTINCT seccion_id FROM cierres_asistencia
            WHERE periodo_id = ? AND anulado_en IS NULL
        ", [$periodoId]) as $r) {
            $out[(int) $r['seccion_id']] = true;
        }
        return $out;
    }

    /** Desbloqueo (director/admin): anula el cierre vigente con traza. */
    public function anularCierre(int $seccionId, int $periodoId, int $userId, string $motivo): bool
    {
        $c = $this->getCierreVigente($seccionId, $periodoId);
        if (!$c) {
            return false;
        }
        return $this->execute("
            UPDATE cierres_asistencia
            SET anulado_en = NOW(), anulado_por = ?, motivo_anulacion = ?
            WHERE id = ?
        ", [$userId, $motivo, (int) $c['id']]);
    }

    /**
     * Secciones del año del periodo con su estado de cierre de asistencia
     * (para el panel de bloqueos del director). Espejo del resumen de conducta,
     * sin requisito de tutor: la asistencia es de la seccion.
     */
    public function getResumenSeccionesPorPeriodo(int $periodoId): array
    {
        $secciones = $this->query("
            SELECT s.id              AS seccion_id,
                   s.nombre          AS seccion_nombre,
                   g.numero          AS grado_numero,
                   g.nombre_display  AS grado_nombre,
                   n.id              AS nivel_id,
                   n.nombre          AS nivel_nombre,
                   ca.id             AS cierre_id,
                   ca.ra_bloqueado_en
            FROM secciones s
            INNER JOIN grados g  ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            LEFT JOIN cierres_asistencia ca
                ON  ca.seccion_id = s.id
                AND ca.periodo_id = ?
                AND ca.anulado_en IS NULL
            WHERE s.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
              AND s.estado_nomina = 'aprobada'
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId]);

        // Avance de llenado (registrados/esperados) reusando la query del indice.
        $progreso = $this->getProgresoPorSeccion($periodoId);
        foreach ($secciones as &$s) {
            $p = $progreso[(int) $s['seccion_id']] ?? ['esperados' => 0, 'registrados' => 0];
            $s['esperados']   = $p['esperados'];
            $s['registrados'] = $p['registrados'];
        }
        unset($s);

        return $secciones;
    }

    // ── Estadistica para el tablero de Direccion ─────────────────
    //
    // 🔴 SEMANTICA DE LOS 4 CONTADORES, que es lo primero que se malinterpreta:
    // `faltas` y `faltas_justificadas` son COLUMNAS INDEPENDIENTES, no un total
    // y su subconjunto. `faltas` ya son las faltas SIN JUSTIFICACION. Medido en
    // los datos: hay 159 filas con `faltas_justificadas > faltas` y 78 con
    // `tardanzas_justificadas > tardanzas`, imposible si una contuviera a la
    // otra. NUNCA restar una de otra para obtener "las no justificadas".

    /**
     * Los 4 contadores agregados por SECCION, mas cuanta gente los acumula.
     *
     * Alimenta la comparativa entre secciones del tablero y el grafico de
     * justificadas vs sin justificar. Misma armazon y MISMO ROSTER que
     * `getProgresoPorSeccion`: si contaran universos distintos, dos cifras de
     * la misma pantalla se contradirian.
     *
     * `sin_incidencias` cuenta a quien no tiene NI faltas NI tardanzas sin
     * justificar, incluidos los que no tienen fila: no tener registro y tener
     * un registro en cero se ven igual aqui a proposito —es un indicador de
     * panorama, no de completitud; para eso esta `registrados`—.
     */
    public function getIncidenciasPorSeccion(int $periodoId): array
    {
        return $this->query("
            SELECT
                s.id             AS seccion_id,
                s.nombre         AS seccion_nombre,
                g.numero         AS grado_numero,
                n.id             AS nivel_id,
                n.nombre         AS nivel_nombre,
                n.codigo         AS nivel_codigo,
                COUNT(DISTINCT m.id) AS alumnos,
                COALESCE(SUM(i.faltas), 0)                 AS faltas,
                COALESCE(SUM(i.faltas_justificadas), 0)    AS faltas_justificadas,
                COALESCE(SUM(i.tardanzas), 0)              AS tardanzas,
                COALESCE(SUM(i.tardanzas_justificadas), 0) AS tardanzas_justificadas,
                COUNT(DISTINCT CASE WHEN i.faltas    > 0 THEN m.id END) AS con_faltas,
                COUNT(DISTINCT CASE WHEN i.tardanzas > 0 THEN m.id END) AS con_tardanzas,
                COUNT(DISTINCT CASE
                    WHEN COALESCE(i.faltas, 0) = 0 AND COALESCE(i.tardanzas, 0) = 0
                    THEN m.id END) AS sin_incidencias,
                COUNT(DISTINCT i.matricula_id) AS registrados
            FROM secciones s
            INNER JOIN grados  g ON g.id = s.grado_id
            INNER JOIN niveles n ON n.id = g.nivel_id
            LEFT JOIN matriculas m
                   -- Quien CURSÓ el bimestre en la sección (cambio de sección).
                   ON " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', 's.id', $periodoId) . "
                  AND m.anio_id    = s.anio_id
                  -- Roster de evaluacion (punto unico en helpers.php).
                  " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
            LEFT JOIN inasistencias i
                   ON i.matricula_id = m.id
                  AND i.periodo_id   = ?
                  -- Monitoreo: solo lo CONFIRMADO, sin exigir el bloqueo.
                  AND " . self::sqlConfirmada('i') . "
            WHERE s.estado_nomina = 'aprobada'
              AND s.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
            GROUP BY s.id
            ORDER BY n.id, g.numero, s.nombre
        ", [$periodoId, $periodoId]);
    }

    /**
     * 🔴 PUNTO ÚNICO de «los `$tope` primeros, CON SUS EMPATES» (extraído el
     * 29/09/2026 de `getTopIncidenciasPorSeccion`, donde vivía como closure, para
     * que la lista de ausencias de las estadísticas de justificaciones no fuera
     * una segunda copia).
     *
     * Descarta los que tienen `$campo` en 0, ordena de mayor a menor —dentro de un
     * empate conserva el orden de llegada (el alfabético de la consulta)— y deja
     * los `$tope` primeros, AMPLIADO solo lo justo para no partir el empate del
     * último puesto: cortar en seco elegiría entre ex aequo por apellido,
     * arbitrario y, en un listado que señala personas, injusto.
     *
     * ⚠️ La alternativa «los `$tope` VALORES distintos más altos» se probó y se
     * descartó: en una sección con la incidencia repartida, el tercer valor
     * distinto es un 1, y la lista pasa de 3 a 8 personas —de «quienes acumulan
     * más» a «todo el que llegó tarde una vez»—. Medido: 250 filas en B2 frente a
     * las ~120 de esta regla.
     *
     * @param list<array> $items filas con la clave `$campo`
     * @return list<array>
     */
    public static function topConEmpates(array $items, string $campo, int $tope = 3): array
    {
        $items = array_values(array_filter(
            $items,
            static fn(array $x): bool => (int) $x[$campo] > 0
        ));
        if (empty($items)) {
            return [];
        }

        // usort no es estable: el índice de llegada hace de segundo criterio.
        $orden = array_keys($items);
        usort($orden, static fn(int $x, int $y): int
            => [(int) $items[$y][$campo], $x] <=> [(int) $items[$x][$campo], $y]);
        $items = array_map(static fn(int $i): array => $items[$i], $orden);

        if (count($items) <= $tope) {
            return $items;
        }

        $corte = (int) $items[$tope - 1][$campo];
        return array_values(array_filter(
            $items,
            static fn(array $x): bool => (int) $x[$campo] >= $corte
        ));
    }

    /**
     * Estudiantes con alguna incidencia SIN JUSTIFICAR, ya agrupados por seccion
     * y recortados a los `$tope` de mas faltas y los `$tope` de mas tardanzas.
     *
     * ⚠️ EL CORTE INCLUYE LOS EMPATES. Si en una seccion el tercer puesto tiene
     * 2 faltas y hay cuatro estudiantes con 2, salen los cuatro. Cortar en seco
     * elegiria entre ex aequo por orden alfabetico, que es arbitrario y, en un
     * listado que senala personas, injusto. Por eso una seccion puede traer mas
     * de `$tope` filas y es correcto.
     *
     * Es un indicador INFORMATIVO. La normativa vigente no contempla el retiro
     * automatico por exceso de inasistencias: cualquier decision se sustenta y
     * evalua caso por caso. Nada en el sistema debe derivar una consecuencia de
     * esta lista, y por eso el metodo NO recibe umbral alguno.
     *
     * Una sola consulta (≈528 filas como mucho) y el recorte en PHP: con 23
     * secciones, una consulta por seccion serian 46 viajes a la base de datos.
     *
     * @return list<array> una fila por seccion con `top_faltas` y `top_tardanzas`
     */
    public function getTopIncidenciasPorSeccion(int $periodoId, int $tope = 3): array
    {
        $filas = $this->query("
            SELECT
                s.id     AS seccion_id,
                s.nombre AS seccion_nombre,
                g.numero AS grado_numero,
                n.id     AS nivel_id,
                n.codigo AS nivel_codigo,
                m.id     AS matricula_id,
                CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS nombre_completo,
                i.faltas,
                i.faltas_justificadas,
                i.tardanzas,
                i.tardanzas_justificadas
            FROM inasistencias i
            INNER JOIN matriculas m
                    ON m.id = i.matricula_id
                   -- Roster de evaluacion (punto unico en helpers.php).
                   " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            -- La sección donde CURSÓ el bimestre (cambio de sección).
            INNER JOIN secciones   s ON s.id = " . CambioSeccionModel::sqlSeccionDelPeriodo('m', $periodoId) . " AND s.estado_nomina = 'aprobada'
            INNER JOIN grados      g ON g.id = s.grado_id
            INNER JOIN niveles     n ON n.id = g.nivel_id
            WHERE i.periodo_id = ?
              AND (i.faltas > 0 OR i.tardanzas > 0)
              -- Monitoreo: solo lo CONFIRMADO (un borrador aún puede cambiar).
              AND " . self::sqlConfirmada('i') . "
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('p') . "
        ", [$periodoId]);

        // Los `$tope` primeros con sus empates: PUNTO ÚNICO en `topConEmpates`
        // (29/09/2026), que también usa la lista de ausencias de las
        // estadísticas de justificaciones.
        $recortar = static fn(array $items, string $campo): array => self::topConEmpates($items, $campo, $tope);

        $secciones = [];
        foreach ($filas as $f) {
            $sid = (int) $f['seccion_id'];
            if (!isset($secciones[$sid])) {
                $secciones[$sid] = [
                    'seccion_id'   => $sid,
                    'nivel_id'     => (int) $f['nivel_id'],
                    'nivel_codigo' => (string) $f['nivel_codigo'],
                    // Misma etiqueta que el resto del tablero: sin la inicial del
                    // nivel habria dos "1° A", uno de primaria y otro de secundaria.
                    'etq'          => (int) $f['grado_numero'] . '° ' . $f['seccion_nombre']
                        . ' (' . strtoupper(substr((string) $f['nivel_codigo'], 0, 1)) . ')',
                    'items'        => [],
                ];
            }
            $secciones[$sid]['items'][] = $f;
        }

        $salida = [];
        foreach ($secciones as $s) {
            $porFaltas    = $recortar($s['items'], 'faltas');
            $porTardanzas = $recortar($s['items'], 'tardanzas');
            unset($s['items']);

            // UNA FILA POR ESTUDIANTE, no dos listas. Quien destaca en ambas
            // aparecia dos veces con la mitad del dato cada vez; asi se lee el
            // caso completo de un vistazo y la tabla no repite nombres.
            // `por_*` dice en cual de las dos entro, para que la vista destaque
            // el numero que lo trajo aqui.
            $alumnos = [];
            foreach ([['por_faltas', $porFaltas], ['por_tardanzas', $porTardanzas]] as [$marca, $lista]) {
                foreach ($lista as $a) {
                    $mid = (int) $a['matricula_id'];
                    if (!isset($alumnos[$mid])) {
                        $alumnos[$mid] = [
                            'matricula_id'           => $mid,
                            'nombre_completo'        => (string) $a['nombre_completo'],
                            'faltas'                 => (int) $a['faltas'],
                            'faltas_justificadas'    => (int) $a['faltas_justificadas'],
                            'tardanzas'              => (int) $a['tardanzas'],
                            'tardanzas_justificadas' => (int) $a['tardanzas_justificadas'],
                            'por_faltas'             => false,
                            'por_tardanzas'          => false,
                        ];
                    }
                    $alumnos[$mid][$marca] = true;
                }
            }

            $alumnos = array_values($alumnos);
            usort($alumnos, static fn(array $x, array $y): int
                => [$y['faltas'], $y['tardanzas']] <=> [$x['faltas'], $x['tardanzas']]);

            // Una seccion sin nadie con incidencias sin justificar no se muestra.
            if ($alumnos) {
                $s['alumnos'] = $alumnos;
                $salida[]     = $s;
            }
        }

        return $salida;
    }

    /**
     * Los 4 contadores agregados por BIMESTRE del año, sobre el mismo roster.
     * Alimenta la linea historica del tablero. `registrados` permite distinguir
     * el bimestre en cero del bimestre que nadie ha registrado todavia: sin ese
     * dato la linea caeria a 0 y se leeria como una mejora que no ocurrio.
     */
    public function getEvolucionIncidenciasAnual(int $anioId): array
    {
        return $this->query("
            SELECT
                p.id             AS periodo_id,
                p.numero         AS periodo_numero,
                p.nombre_display AS periodo_nombre,
                COALESCE(SUM(i.faltas), 0)                 AS faltas,
                COALESCE(SUM(i.faltas_justificadas), 0)    AS faltas_justificadas,
                COALESCE(SUM(i.tardanzas), 0)              AS tardanzas,
                COALESCE(SUM(i.tardanzas_justificadas), 0) AS tardanzas_justificadas,
                COUNT(DISTINCT i.matricula_id)             AS registrados
            FROM periodos p
            LEFT JOIN inasistencias i ON i.periodo_id = p.id
                   -- Monitoreo: solo lo CONFIRMADO.
                   AND " . self::sqlConfirmada('i') . "
            LEFT JOIN matriculas m
                   ON m.id = i.matricula_id
                  -- Roster de evaluacion (punto unico en helpers.php).
                  " . RetornoGradoModel::sqlRosterDelPeriodo('m', 'p.id') . "
            LEFT JOIN secciones s ON s.id = m.seccion_id AND s.estado_nomina = 'aprobada'
            WHERE p.anio_id = ?
              AND (i.id IS NULL OR (m.id IS NOT NULL AND s.id IS NOT NULL))
            GROUP BY p.id
            ORDER BY p.numero
        ", [$anioId]);
    }

    /** seccion_id de una matricula, o null si no existe (gate de guardado). */
    public function seccionDeMatricula(int $matriculaId): ?int
    {
        $row = $this->queryOne("
            SELECT seccion_id FROM matriculas WHERE id = ?
        ", [$matriculaId]);
        return $row ? (int) $row['seccion_id'] : null;
    }

    /**
     * true si la matricula pertenece al ROSTER de asistencia (mismo criterio
     * que getEstudiantesConIncidencias). Guard del endpoint de guardado: la
     * grilla ya no pinta a los excluidos, pero una pestaña abierta desde antes
     * del cambio si podria enviarlos.
     */
    public function matriculaEnRoster(int $matriculaId): bool
    {
        $row = $this->queryOne("
            SELECT 1 AS ok
            FROM matriculas m
            WHERE m.id = ?
              " . roster_evaluacion('m') . "
        ", [$matriculaId]);

        return $row !== null;
    }

    // ── Verificación de edición ──────────────────────────────────

    /**
     * true si el periodo está abierto para edición de asistencia.
     * Mismo criterio que ConductaModel::periodoEditable.
     */
    public function periodoEditable(int $periodoId): bool
    {
        $p = $this->queryOne("
            SELECT estado, limite_notas
            FROM periodos WHERE id = ?
        ", [$periodoId]);

        if (!$p || $p['estado'] !== 'activo') {
            return false;
        }
        if ($p['limite_notas'] && strtotime($p['limite_notas']) < time()) {
            return false;
        }
        return true;
    }
}
