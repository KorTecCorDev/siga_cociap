<?php

/**
 * Verificación del «SEGUIMIENTO DE FALTAS» y del REPORTE DEL AUXILIAR
 * (13/10/2026), vista «Faltas y justificaciones». Ver
 * docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Qué protege, con las DOS ramas de cada regla:
 *   - Umbral `UMBRAL_SEGUIMIENTO_FALTAS`: con una falta menos NO entra; con
 *     exactamente el umbral, SÍ.
 *   - Falta = la que CUENTA como F: una F o una FJ fuera del ancla. Una FJ con el
 *     ancla, una T o una TJ NO suman.
 *   - Con los DATOS REALES del bimestre por fechas: la cifra de cada estudiante es
 *     la del contador `faltas` de `inasistencias` (lo que va a la boleta), y quien
 *     no aparece tiene menos faltas que el umbral.
 *   - `consolidarJustificaciones` suma lo mismo que las secciones por separado.
 *
 * 🔴 SOLO LEE: no escribe nada en la BD.
 *
 * Uso: php database/verificaciones/verif_seguimiento_faltas.php
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');

require ROOT_PATH . '/app/Helpers/helpers.php';

spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/'] as $pre => $base) {
        if (str_starts_with($c, $pre)) {
            $f = ROOT_PATH . $base . str_replace('\\', '/', substr($c, strlen($pre))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});

date_default_timezone_set(config('timezone'));

use App\Models\AsistenciaModel;
use App\Models\AsistenciaMotivoModel;

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};

$U = AsistenciaModel::UMBRAL_SEGUIMIENTO_FALTAS;

// ── 1) Función pura con datos sintéticos ─────────────────────────
echo "1) Umbral y qué cuenta como falta (umbral = {$U})\n";
$ancla = 7;
$dia   = fn(int $n): string => sprintf('2026-09-%02d', $n);
$marcas = function (array $tipos) use ($dia): array {
    $out = [];
    foreach (array_values($tipos) as $i => [$tipo, $motivo]) {
        $out[$dia($i + 1)] = ['tipo' => $tipo, 'motivo_id' => $motivo, 'motivo' => ''];
    }
    return $out;
};
$est = [
    ['matricula_id' => 1, 'nombre_completo' => 'UNO',  'incidencias' => ['confirmado' => true]],
    ['matricula_id' => 2, 'nombre_completo' => 'DOS',  'incidencias' => ['confirmado' => true]],
    ['matricula_id' => 3, 'nombre_completo' => 'TRES', 'incidencias' => ['confirmado' => false]],
];
$inc = [
    // Umbral − 1 faltas, más marcas que NO cuentan (FJ con ancla, T, TJ).
    1 => $marcas(array_merge(array_fill(0, $U - 1, ['F', null]),
                             [['FJ', $ancla], ['T', null], ['TJ', 3]])),
    // Exactamente el umbral: F + FJ fuera del ancla.
    2 => $marcas(array_merge(array_fill(0, $U - 1, ['F', null]), [['FJ', 3]])),
    // Más que el umbral.
    3 => $marcas(array_fill(0, $U + 2, ['F', null])),
];
$seg = AsistenciaModel::seguimientoFaltas($est, $inc, $ancla);
$por = array_column($seg, null, 'matricula_id');

$chk('con ' . ($U - 1) . ' faltas NO entra (FJ con ancla, T y TJ no suman)', !isset($por[1]));
$chk("con {$U} faltas SÍ entra (una FJ fuera del ancla cuenta como falta)", isset($por[2]) && $por[2]['faltas'] === $U);
$chk('desglose F / FJ→F', isset($por[2]) && $por[2]['f'] === $U - 1 && $por[2]['fj_como_f'] === 1);
$chk('orden: más faltas primero', array_column($seg, 'matricula_id') === [3, 2]);
$chk('última falta = la fecha más reciente', isset($por[3]) && $por[3]['ultima'] === $dia($U + 2));
$chk('lleva el estado de confirmación', isset($por[3]) && $por[3]['confirmado'] === false);

// ── 1b) Fechas por lo que CUENTAN (13/10/2026) ───────────────────
echo "1b) Cada fecha en la categoría en que cuenta\n";
$r1  = AsistenciaModel::resumenJustificaciones($est, $inc, $ancla);
$fil = array_column($r1['estudiantes'], null, 'num');
// UNO: FJ con el ancla en el día U, TJ en el día U+2.
$chk('FJ con el ancla → «Faltas justificadas», con su fecha',
    ($fil[1]['fechas']['fj_autorizadas'] ?? null) === [$dia($U)] && ($fil[1]['fechas']['fj_como_f'] ?? null) === []);
$chk('TJ → «Tardanzas justificadas»', ($fil[1]['fechas']['tj'] ?? null) === [$dia($U + 2)]);
// DOS: FJ con otro motivo en el día U.
$chk('FJ con otro motivo → «Cuentan como falta», nunca en las justificadas',
    ($fil[2]['fechas']['fj_como_f'] ?? null) === [$dia($U)] && ($fil[2]['fechas']['fj_autorizadas'] ?? null) === []);
$cuadra = true;
foreach ($r1['estudiantes'] as $e) {
    foreach (['fj_autorizadas', 'fj_como_f', 'tj'] as $c) {
        $cuadra = $cuadra && count($e['fechas'][$c]) === $e[$c];
    }
}
$chk('el número de fechas de cada categoría es su cifra', $cuadra);

// ── 1c) Tramos de días seguidos (13/10/2026) ─────────────────────
echo "1c) Tramos de días lectivos seguidos\n";
// Lectivos: lun 07 a vie 11/09 y lun 14 a vie 18/09, con el MIÉ 16 NO lectivo.
$lect = ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11',
         '2026-09-14', '2026-09-15', '2026-09-17', '2026-09-18'];
$tr = fn(array $f): array => array_map(fn($t) => [$t['desde'], $t['hasta'], $t['dias']],
                                       AsistenciaModel::tramosDeFechas($f, $lect));
$chk('el fin de semana NO corta el tramo (vie 11 → lun 14)',
    $tr(['2026-09-11', '2026-09-14']) === [['2026-09-11', '2026-09-14', 2]]);
$chk('un día NO LECTIVO no corta el tramo (mar 15 → jue 17)',
    $tr(['2026-09-15', '2026-09-17']) === [['2026-09-15', '2026-09-17', 2]]);
$chk('un día lectivo sin marca SÍ corta (lun 07 y mié 09)',
    $tr(['2026-09-07', '2026-09-09']) === [['2026-09-07', '2026-09-07', 1], ['2026-09-09', '2026-09-09', 1]]);
$chk('el orden de entrada no importa', $tr(['2026-09-09', '2026-09-08', '2026-09-07'])
    === [['2026-09-07', '2026-09-09', 3]]);
$chk('una fecha fuera de los lectivos queda sola', $tr(['2026-09-12', '2026-09-14'])
    === [['2026-09-12', '2026-09-12', 1], ['2026-09-14', '2026-09-14', 1]]);

// ── 2) Consolidado (función pura) ────────────────────────────────
echo "2) Reporte de varias secciones\n";
$r2 = AsistenciaModel::resumenJustificaciones([$est[0]], [1 => $inc[1]], $ancla);
$cons = AsistenciaModel::consolidarJustificaciones([
    ['seccion' => ['id' => 10, 'etiqueta' => 'A'], 'oficial' => true,  'resumen' => $r1],
    ['seccion' => ['id' => 11, 'etiqueta' => 'B'], 'oficial' => false, 'resumen' => $r2],
]);
$chk('los KPIs suman los de cada sección', $cons['kpis']['total'] === $r1['kpis']['total'] + $r2['kpis']['total']
    && $cons['kpis']['estudiantes'] === $r1['kpis']['estudiantes'] + $r2['kpis']['estudiantes']);
$chk('los motivos suman el mismo total', array_sum(array_column($cons['motivos'], 'total')) === $cons['kpis']['total']);
$chk('una fila por sección, con su estado', array_column($cons['secciones'], 'oficial') === [true, false]);
$chk('cada estudiante lleva su sección', array_column($cons['estudiantes'], 'seccion')
    === array_merge(array_fill(0, count($r1['estudiantes']), 'A'), array_fill(0, count($r2['estudiantes']), 'B')));

// ── 3) Datos reales: cuadra con el contador de la boleta ─────────
echo "3) Datos reales del bimestre por fechas (solo lectura)\n";
$m   = new AsistenciaModel();
$per = $m->queryOne("SELECT p.id, p.nombre_display FROM periodos p
                     INNER JOIN anios_academicos a ON a.id = p.anio_id
                     WHERE a.estado = 'activo' AND p.asistencia_por_fechas = 1
                     -- El que tiene más registros: el IV puede estar vacío.
                     ORDER BY (SELECT COUNT(*) FROM inasistencias i WHERE i.periodo_id = p.id) DESC,
                              p.numero DESC
                     LIMIT 1");
if ($per === null) {
    echo "  (sin bimestre por fechas en esta BD: se omite)\n";
} else {
    $pid    = (int) $per['id'];
    $anclaR = (new AsistenciaMotivoModel())->anclaDelPeriodo($pid);
    $revisados = $enSeguimiento = 0;
    $malos = [];
    $perFull  = $m->periodo($pid);
    $lectR    = AsistenciaModel::diasMarcables($perFull, (string) $perFull['fecha_fin'],
        (new App\Models\AsistenciaJornadaModel())->noLectivos($perFull));
    $tramosOk = true;
    $nTramos  = $nFechas = 0;
    foreach ($m->listarSeccionesActivas() as $s) {
        $alumnos = $m->getEstudiantesConIncidencias((int) $s['id'], $pid, true);
        $incR    = $m->incidenciasDe(array_column($alumnos, 'matricula_id'), $pid, true);
        // Tramos: la suma de sus días es la cifra de la celda, sin perder ni repetir.
        foreach (AsistenciaModel::resumenJustificaciones($alumnos, $incR, $anclaR, $lectR)['estudiantes'] as $e) {
            foreach ($e['tramos'] as $c => $lista) {
                $tramosOk = $tramosOk && array_sum(array_column($lista, 'dias')) === count($e['fechas'][$c]);
                $nTramos += count($lista);
                $nFechas += count($e['fechas'][$c]);
            }
        }
        $segR    = array_column(AsistenciaModel::seguimientoFaltas($alumnos, $incR, $anclaR), null, 'matricula_id');
        foreach ($alumnos as $a) {
            $c = $a['incidencias'];
            if (empty($c['registrado']) || !empty($c['extraordinaria'])) {
                continue;
            }
            $revisados++;
            $mid    = (int) $a['matricula_id'];
            $faltas = (int) $c['faltas'];
            if (isset($segR[$mid])) {
                $enSeguimiento++;
                if ($segR[$mid]['faltas'] !== $faltas) {
                    $malos[] = "m{$mid}: seguimiento {$segR[$mid]['faltas']} ≠ contador {$faltas}";
                }
            } elseif ($faltas >= $U) {
                $malos[] = "m{$mid}: contador {$faltas} ≥ {$U} pero no está en seguimiento";
            }
        }
    }
    echo "  {$per['nombre_display']}: {$revisados} estudiantes confirmados, {$enSeguimiento} en seguimiento\n";
    $chk('la cifra de cada estudiante es la del contador `faltas` (boleta)', $malos === []);
    $chk("los tramos suman exactamente las fechas de cada celda ({$nFechas} fechas → {$nTramos} fichas)", $tramosOk);
    foreach (array_slice($malos, 0, 10) as $x) {
        echo "      {$x}\n";
    }
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
