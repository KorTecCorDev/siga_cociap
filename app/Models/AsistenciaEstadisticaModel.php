<?php

namespace App\Models;

/**
 * AsistenciaEstadisticaModel — ESTADÍSTICAS DE JUSTIFICACIONES para Dirección
 * (29/09/2026). Bloque «Justificaciones» de `/admin/cuadros` y de su A4. Ver
 * docs/modulos/confirmacion-y-asistencia-por-fechas.md (segunda ronda, punto 6).
 *
 * Qué responde: ¿cuánto se justifica, por qué, dónde, cuándo y quién? El bloque
 * de Asistencia que ya existía cuenta lo NO justificado; este mira el resto.
 *
 * Reglas (decisiones del usuario):
 *   - Solo bimestres POR FECHAS (desde el III 2026): sin fechas no hay qué medir.
 *   - Solo lo CONFIRMADO, como el resto de los cuadros. Un borrador queda al aire.
 *   - La vía extraordinaria (4 números sin fechas) no entra: no tiene fechas ni
 *     motivos. Tampoco entra al denominador.
 *   - Roster de evaluación (`roster_evaluacion`), secciones con nómina aprobada.
 *   - Listas que señalan personas: informativas, sin umbrales de sanción.
 *
 * 🔴 UNA SOLA BASE. Dos consultas (roster con su estado de confirmación, e
 * incidencias confirmadas) y todos los indicadores se agregan en PHP sobre esas
 * mismas filas: así la tasa, las secciones, las semanas y las listas no pueden
 * contarse sobre universos distintos y contradecirse entre sí.
 *
 * Los LÍMITES de estas cifras NO se muestran en pantalla (decisión del usuario):
 * los feriados cuentan como días hábiles (la tasa sale algo más alta), el
 * historial empieza en el III, no hay umbrales, y si se cambia el motivo de una
 * justificación solo queda el último.
 */
class AsistenciaEstadisticaModel extends BaseModel
{
    /** Justificaciones mínimas para la alerta de «mayoritariamente verbales». */
    public const MIN_JUSTIFICACIONES_VERBALES = 5;

    /** Tope de las listas por sección y de los días críticos (con empates). */
    public const TOPE_LISTAS = 3;
    public const TOPE_DIAS   = 5;

    private const DIAS_SEMANA = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];

    /**
     * Todas las estadísticas de justificaciones de un bimestre, o null si el
     * bimestre no se registra por fechas.
     */
    public function justificaciones(int $periodoId): ?array
    {
        $periodo = (new AsistenciaModel())->periodo($periodoId);
        if ($periodo === null || (int) $periodo['asistencia_por_fechas'] !== 1) {
            return null;
        }

        $dias = AsistenciaModel::diasMarcables($periodo);
        [$estudiantes, $secciones] = $this->roster($periodoId);
        $incidencias = $this->incidencias($periodoId, array_keys($estudiantes));
        $ancla       = (new AsistenciaMotivoModel())->anclaDelPeriodo($periodoId);

        // Contadores y motivos por estudiante, desde las fechas.
        foreach ($incidencias as $x) {
            $e = &$estudiantes[$x['matricula_id']];
            // F/FJ/T/TJ con la MISMA regla que los contadores oficiales (08/10/2026,
            // migración 076): una FJ cuenta como FJ solo con el ANCLA del bimestre;
            // con otro motivo, como F. Decisión del usuario: estas cifras se alinean
            // con la boleta.
            $e[self::tipoQueCuenta($x, $ancla)]++;
            // Lo que mide el MOTIVO (usos por motivo, verbales y su alerta) sigue
            // contando cada MARCA justificada tal como se registró.
            if (in_array($x['tipo'], ['FJ', 'TJ'], true)) {
                $e['justificaciones']++;
                $clave = $x['motivo'] ?? '';
                $e['motivos'][$clave] = ($e['motivos'][$clave] ?? 0) + 1;
                if ($x['motivo_codigo'] === AsistenciaMotivoModel::CODIGO_VERBAL) {
                    $e['verbales']++;
                }
            }
            unset($e);
        }

        $confirmados = array_filter($estudiantes, static fn(array $e): bool => $e['confirmado']);

        return [
            // Ancla del bimestre: el icono de la columna FJ dice con qué motivo cuenta.
            'motivo_principal' => (new AsistenciaMotivoModel())->nombreDe($ancla),
            'dias_habiles' => count($dias),
            'kpis'         => $this->kpis($confirmados, count($dias), count($estudiantes)),
            'niveles'      => $this->porNivel($confirmados, count($dias), $estudiantes),
            'motivos'      => $this->porMotivo($incidencias),
            'secciones'    => $this->porSeccion($confirmados, $secciones),
            'semanas'      => $this->porSemana($incidencias, $dias),
            'dias_semana'  => $this->porDiaSemana($incidencias, $dias),
            'top'          => $this->topAusencias($confirmados, $secciones),
            'verbales'     => $this->alertaVerbales($confirmados, $secciones),
            'criticos'     => $this->diasCriticos($incidencias, count($confirmados)),
            'oportunidad'  => $this->oportunidad($incidencias, $estudiantes, $secciones),
        ];
    }

    /**
     * Contador en que cae una marca: el mismo de `AsistenciaModel::sqlConteoContadores`
     * (una FJ fuera del ancla cuenta como F). Mantener las dos en sintonía: lo
     * comprueba `verif_asistencia_estadisticas.php`. Pública desde el 09/10/2026:
     * la usa también `AsistenciaModel::resumenJustificaciones` (vista de la sección).
     */
    public static function tipoQueCuenta(array $x, ?int $ancla): string
    {
        return ($x['tipo'] === 'FJ' && (int) ($x['motivo_id'] ?? 0) !== (int) ($ancla ?? 0)) ? 'F' : (string) $x['tipo'];
    }

    // ── Base ────────────────────────────────────────────────────────

    /**
     * Roster del bimestre con su estado. Un estudiante cuenta como CONFIRMADO si
     * su fila está confirmada y NO es de la vía extraordinaria.
     *
     * @return array{0: array<int, array>, 1: array<int, array>} [estudiantes por
     *         matrícula, secciones por id]
     */
    private function roster(int $periodoId): array
    {
        $filas = $this->query("
            SELECT m.id AS matricula_id,
                   CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS nombre,
                   s.id AS seccion_id, s.nombre AS seccion_nombre, g.numero AS grado_numero,
                   n.id AS nivel_id, n.nombre AS nivel_nombre, n.codigo AS nivel_codigo,
                   (i.id IS NOT NULL AND " . AsistenciaModel::sqlConfirmada('i') . " AND i.extraordinaria = 0) AS confirmado
            FROM secciones s
            INNER JOIN grados      g ON g.id = s.grado_id
            INNER JOIN niveles     n ON n.id = g.nivel_id
            -- Quien CURSÓ el bimestre en la sección (cambio de sección, 07/10/2026).
            INNER JOIN matriculas  m ON " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', 's.id', $periodoId) . " AND m.anio_id = s.anio_id
                   " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $periodoId) . "
            INNER JOIN estudiantes e ON e.id = m.estudiante_id
            INNER JOIN personas    p ON p.id = e.persona_id
            LEFT  JOIN inasistencias i ON i.matricula_id = m.id AND i.periodo_id = ?
            WHERE s.estado_nomina = 'aprobada'
              AND s.anio_id = (SELECT anio_id FROM periodos WHERE id = ?)
            ORDER BY n.id, g.numero, s.nombre, " . orden_alfabetico('p') . "
        ", [$periodoId, $periodoId]);

        $estudiantes = $secciones = [];
        foreach ($filas as $f) {
            $sid = (int) $f['seccion_id'];
            $secciones[$sid] ??= [
                'seccion_id' => $sid,
                'nivel_id'   => (int) $f['nivel_id'],
                'nivel'      => (string) $f['nivel_nombre'],
                // Misma etiqueta que el resto del tablero: primaria y secundaria
                // repiten los grados.
                'etq'        => (int) $f['grado_numero'] . '° ' . $f['seccion_nombre']
                    . ' (' . strtoupper(substr((string) $f['nivel_codigo'], 0, 1)) . ')',
                'esperados'  => 0,
                'confirmados'=> 0,
            ];
            $secciones[$sid]['esperados']++;
            $confirmado = (bool) $f['confirmado'];
            if ($confirmado) {
                $secciones[$sid]['confirmados']++;
            }
            $estudiantes[(int) $f['matricula_id']] = [
                'matricula_id' => (int) $f['matricula_id'],
                'nombre'       => (string) $f['nombre'],
                'seccion_id'   => $sid,
                'nivel_id'     => (int) $f['nivel_id'],
                'confirmado'   => $confirmado,
                'F' => 0, 'FJ' => 0, 'T' => 0, 'TJ' => 0,
                // Marcas FJ/TJ de cualquier motivo: base de los verbales y su alerta.
                'justificaciones' => 0,
                'verbales'     => 0,
                'motivos'      => [],
            ];
        }
        return [$estudiantes, $secciones];
    }

    /**
     * Incidencias CONFIRMADAS (y no extraordinarias) del bimestre, solo de
     * matrículas del roster.
     */
    private function incidencias(int $periodoId, array $matriculas): array
    {
        if ($matriculas === []) {
            return [];
        }
        $filas = $this->query("
            SELECT x.matricula_id, x.fecha, x.tipo, x.motivo_id, x.registrado_en,
                   am.codigo AS motivo_codigo, am.nombre AS motivo
            FROM asistencia_incidencias x
            INNER JOIN inasistencias i
                    ON i.matricula_id = x.matricula_id AND i.periodo_id = x.periodo_id
                   AND " . AsistenciaModel::sqlConfirmada('i') . " AND i.extraordinaria = 0
            LEFT  JOIN asistencia_motivos am ON am.id = x.motivo_id
            WHERE x.periodo_id = ?
            ORDER BY x.fecha
        ", [$periodoId]);

        $roster = array_flip($matriculas);
        $out = [];
        foreach ($filas as $f) {
            if (isset($roster[(int) $f['matricula_id']])) {
                $f['matricula_id'] = (int) $f['matricula_id'];
                $out[] = $f;
            }
        }
        return $out;
    }

    // ── Indicadores ─────────────────────────────────────────────────

    /** Suma de contadores de un grupo de estudiantes. */
    private static function sumar(array $grupo): array
    {
        $t = ['F' => 0, 'FJ' => 0, 'T' => 0, 'TJ' => 0, 'justificaciones' => 0, 'verbales' => 0];
        foreach ($grupo as $e) {
            foreach ($t as $k => $_) {
                $t[$k] += $e[$k];
            }
        }
        return $t;
    }

    private static function pct(int|float $parte, int|float $total): ?float
    {
        return $total > 0 ? round($parte / $total * 100, 1) : null;
    }

    /**
     * Tarjetas: tasa de asistencia, % de faltas y de tardanzas justificadas, %
     * de justificaciones verbales y registro confirmado.
     *
     * Tasa de asistencia = 1 − (F + FJ) ÷ (estudiantes confirmados × días hábiles).
     */
    private function kpis(array $confirmados, int $dias, int $esperados): array
    {
        $t = self::sumar($confirmados);
        $n = count($confirmados);
        $ausencias = $t['F'] + $t['FJ'];

        return [
            'estudiantes'       => $n,
            'esperados'         => $esperados,
            'tasa_asistencia'   => $n > 0 && $dias > 0 ? round(100 - $ausencias / ($n * $dias) * 100, 1) : null,
            'pct_fj'            => self::pct($t['FJ'], $t['F'] + $t['FJ']),
            'pct_tj'            => self::pct($t['TJ'], $t['T'] + $t['TJ']),
            // Sobre las MARCAS justificadas (cualquier motivo), no sobre FJ + TJ:
            // una FJ verbal ya cuenta como F y el % saldría por encima de 100.
            'pct_verbales'      => self::pct($t['verbales'], $t['justificaciones']),
            'pct_confirmado'    => self::pct($n, $esperados),
            'totales'           => $t,
        ];
    }

    /** Los mismos indicadores por nivel (Primaria frente a Secundaria). */
    private function porNivel(array $confirmados, int $dias, array $todos): array
    {
        $grupos = $esperados = [];
        foreach ($todos as $e) {
            $esperados[$e['nivel_id']] = ($esperados[$e['nivel_id']] ?? 0) + 1;
        }
        foreach ($confirmados as $e) {
            $grupos[$e['nivel_id']][] = $e;
        }
        $nombres = $this->query("SELECT id, nombre FROM niveles ORDER BY id");

        $out = [];
        foreach ($nombres as $niv) {
            $nid = (int) $niv['id'];
            if (!isset($esperados[$nid])) {
                continue;
            }
            $out[] = ['nivel' => (string) $niv['nombre']]
                + $this->kpis($grupos[$nid] ?? [], $dias, $esperados[$nid]);
        }
        return $out;
    }

    /** Usos de cada motivo, separando FJ de TJ, de mayor a menor. */
    private function porMotivo(array $incidencias): array
    {
        $m = [];
        foreach ($incidencias as $x) {
            if (!in_array($x['tipo'], ['FJ', 'TJ'], true)) {
                continue;
            }
            $nombre = (string) ($x['motivo'] ?? 'Sin motivo');
            $m[$nombre] ??= ['motivo' => $nombre, 'FJ' => 0, 'TJ' => 0];
            $m[$nombre][$x['tipo']]++;
        }
        $m = array_values($m);
        usort($m, static fn(array $a, array $b): int => ($b['FJ'] + $b['TJ']) <=> ($a['FJ'] + $a['TJ']));
        return $m;
    }

    /**
     * Por sección, POR ESTUDIANTE confirmado (una sección grande no parece peor
     * solo por tener más alumnos), de mayor a menor total.
     */
    private function porSeccion(array $confirmados, array $secciones): array
    {
        $grupos = [];
        foreach ($confirmados as $e) {
            $grupos[$e['seccion_id']][] = $e;
        }
        $out = [];
        foreach ($grupos as $sid => $g) {
            $t = self::sumar($g);
            $n = count($g);
            $fila = ['etq' => $secciones[$sid]['etq'], 'estudiantes' => $n];
            foreach (['F', 'FJ', 'T', 'TJ'] as $k) {
                $fila[$k] = round($t[$k] / $n, 2);
            }
            $fila['total'] = round(($t['F'] + $t['FJ'] + $t['T'] + $t['TJ']) / $n, 2);
            $out[] = $fila;
        }
        usort($out, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        return $out;
    }

    /**
     * Tendencia SEMANAL: promedio DIARIO de faltas y de tardanzas en el colegio,
     * por semana (lunes a viernes). Promedio y no total: la primera y la última
     * semana del bimestre suelen ser incompletas y un total las haría parecer
     * mejores de lo que fueron.
     */
    private function porSemana(array $incidencias, array $dias): array
    {
        $semanas = [];
        foreach ($dias as $f) {
            $lunes = date('Y-m-d', strtotime($f . ' -' . ((int) date('N', strtotime($f)) - 1) . ' days'));
            $semanas[$lunes] ??= ['semana' => $lunes, 'dias' => 0, 'faltas' => 0, 'tardanzas' => 0];
            $semanas[$lunes]['dias']++;
        }
        foreach ($incidencias as $x) {
            $f = (string) $x['fecha'];
            $lunes = date('Y-m-d', strtotime($f . ' -' . ((int) date('N', strtotime($f)) - 1) . ' days'));
            if (!isset($semanas[$lunes])) {
                continue;
            }
            $semanas[$lunes][in_array($x['tipo'], ['F', 'FJ'], true) ? 'faltas' : 'tardanzas']++;
        }
        return array_values(array_map(static fn(array $s): array => [
            'etq'       => date('d/m', strtotime($s['semana'])),
            'dias'      => $s['dias'],
            'faltas'    => round($s['faltas'] / $s['dias'], 1),
            'tardanzas' => round($s['tardanzas'] / $s['dias'], 1),
        ], $semanas));
    }

    /**
     * Patrón por DÍA DE LA SEMANA: promedio de faltas y tardanzas por cada lunes,
     * martes… transcurrido. Si lunes y viernes destacan, hay un hallazgo.
     */
    private function porDiaSemana(array $incidencias, array $dias): array
    {
        $cuantos = array_fill(1, 5, 0);
        foreach ($dias as $f) {
            $cuantos[(int) date('N', strtotime($f))]++;
        }
        $faltas = $tardanzas = array_fill(1, 5, 0);
        foreach ($incidencias as $x) {
            $n = (int) date('N', strtotime((string) $x['fecha']));
            if ($n > 5) {
                continue;
            }
            in_array($x['tipo'], ['F', 'FJ'], true) ? $faltas[$n]++ : $tardanzas[$n]++;
        }
        $out = [];
        foreach (self::DIAS_SEMANA as $n => $nombre) {
            $out[] = [
                'dia'       => $nombre,
                'faltas'    => $cuantos[$n] > 0 ? round($faltas[$n] / $cuantos[$n], 1) : 0,
                'tardanzas' => $cuantos[$n] > 0 ? round($tardanzas[$n] / $cuantos[$n], 1) : 0,
            ];
        }
        return $out;
    }

    /**
     * Por sección, los estudiantes que MÁS SE AUSENTAN contando también las
     * faltas justificadas (F + FJ), los 3 primeros con sus empates
     * (`AsistenciaModel::topConEmpates`), con su motivo más frecuente. El cuadro
     * de Asistencia solo cuenta lo NO justificado: quien acumula faltas
     * justificadas no aparecía en ninguna parte.
     */
    private function topAusencias(array $confirmados, array $secciones): array
    {
        $grupos = [];
        foreach ($confirmados as $e) {
            $e['ausencias'] = $e['F'] + $e['FJ'];
            $grupos[$e['seccion_id']][] = $e;
        }
        $out = [];
        foreach ($secciones as $sid => $s) {
            $top = AsistenciaModel::topConEmpates($grupos[$sid] ?? [], 'ausencias', self::TOPE_LISTAS);
            if ($top === []) {
                continue;
            }
            $out[] = [
                'etq'     => $s['etq'],
                'alumnos' => array_map(static fn(array $e): array => [
                    'nombre'    => $e['nombre'],
                    'F' => $e['F'], 'FJ' => $e['FJ'], 'T' => $e['T'], 'TJ' => $e['TJ'],
                    'ausencias' => $e['ausencias'],
                    'motivo'    => self::motivoFrecuente($e['motivos']),
                ], $top),
            ];
        }
        return $out;
    }

    /** Motivo más usado por un estudiante (empate: el primero por nombre). */
    private static function motivoFrecuente(array $motivos): string
    {
        if ($motivos === []) {
            return '';
        }
        ksort($motivos);
        arsort($motivos);
        return (string) array_key_first($motivos);
    }

    /**
     * Alerta de control: estudiantes con 5 o MÁS justificaciones (FJ + TJ) y más
     * de la mitad verbales (`mas_de_la_mitad`, punto único de helpers.php).
     */
    private function alertaVerbales(array $confirmados, array $secciones): array
    {
        $out = [];
        foreach ($confirmados as $e) {
            // Marcas justificadas de cualquier motivo (no FJ + TJ: una FJ verbal ya
            // cuenta como F, y es justo la que esta alerta vigila).
            $just = $e['justificaciones'];
            if ($just >= self::MIN_JUSTIFICACIONES_VERBALES && mas_de_la_mitad($e['verbales'], $just)) {
                $out[] = [
                    'nombre'          => $e['nombre'],
                    'etq'             => $secciones[$e['seccion_id']]['etq'],
                    'justificaciones' => $just,
                    'verbales'        => $e['verbales'],
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int
            => [$b['verbales'], $b['justificaciones']] <=> [$a['verbales'], $a['justificaciones']]);
        return $out;
    }

    /**
     * Días CRÍTICOS del colegio: las fechas con más estudiantes ausentes (F o
     * FJ), los 5 primeros con sus empates, con el % sobre los confirmados.
     */
    private function diasCriticos(array $incidencias, int $confirmados): array
    {
        $porDia = [];
        foreach ($incidencias as $x) {
            if (in_array($x['tipo'], ['F', 'FJ'], true)) {
                $porDia[(string) $x['fecha']] = ($porDia[(string) $x['fecha']] ?? 0) + 1;
            }
        }
        $filas = [];
        foreach ($porDia as $fecha => $n) {
            $filas[] = [
                'fecha'    => date('d/m/Y', strtotime($fecha)),
                'dia'      => self::DIAS_SEMANA[(int) date('N', strtotime($fecha))] ?? '',
                'ausentes' => $n,
                'pct'      => self::pct($n, $confirmados),
            ];
        }
        return AsistenciaModel::topConEmpates($filas, 'ausentes', self::TOPE_DIAS);
    }

    /**
     * OPORTUNIDAD DEL REGISTRO (decisión del usuario): por sección, cuántos días
     * pasan en promedio entre la fecha de la incidencia y el momento en que se
     * registró por primera vez, y qué % se registró el mismo día o al siguiente.
     * `registrado_en` es el primer guardado de ese día: cambiar el tipo después
     * no lo mueve.
     */
    private function oportunidad(array $incidencias, array $estudiantes, array $secciones): array
    {
        $grupos = [];
        foreach ($incidencias as $x) {
            $sid  = $estudiantes[$x['matricula_id']]['seccion_id'];
            $dias = max(0, (int) floor((strtotime(substr((string) $x['registrado_en'], 0, 10))
                - strtotime((string) $x['fecha'])) / 86400));
            $grupos[$sid][] = $dias;
        }
        $out = [];
        foreach ($grupos as $sid => $lista) {
            $n = count($lista);
            $out[] = [
                'etq'           => $secciones[$sid]['etq'],
                'registros'     => $n,
                'promedio_dias' => round(array_sum($lista) / $n, 1),
                'pct_a_tiempo'  => self::pct(count(array_filter($lista, static fn(int $d): bool => $d <= 1)), $n),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['promedio_dias'] <=> $a['promedio_dias']);
        return $out;
    }
}
