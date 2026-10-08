<?php
/**
 * Agregados de CONDUCTA y ASISTENCIA para el tablero de Dirección.
 * Lo comparten la pantalla (index.php) y su imprimible (imprimir.php), por el
 * mismo motivo que `_chart-data.php`: si cada vista sumara por su cuenta, el
 * papel y la pantalla podrían decir cifras distintas del MISMO bimestre.
 *
 * Aquí solo se PLIEGA lo que los modelos ya calcularon (sumas y un porcentaje).
 * Ninguna regla de negocio nueva: el literal de conducta lo compone
 * `ConductaModel::componerLiteral()` y los contadores de asistencia salen tal
 * cual de `inasistencias`.
 *
 * Toda clave se lee con `??` porque `verif_direccion_superficies.php` arma su
 * propio `$bloques` y renderiza estas vistas de verdad: una clave inesperada
 * sería un warning y, para él, un FAIL.
 *
 * @var array|null $bloques
 * @var array|null $periodo
 *
 * Salida:
 * @var array      $c              resumen de proceso de conducta (ya venía del controlador)
 * @var array      $a              resumen de proceso de asistencia (ídem)
 * @var array      $condCrit       {criterios, secciones} de incumplimiento
 * @var array      $asisTop        estudiantes con más incidencias, por sección
 * @var array      $lit            conteo AD/A/B/C del bimestre a la vista
 * @var int        $pctLogro       % en logro (AD+A) del bimestre a la vista
 * @var float|null $delta          puntos porcentuales frente al bimestre previo
 * @var string     $nombrePrevio   nombre de ese bimestre previo
 * @var array      $asisTot        los 4 contadores agregados del bimestre
 */

$c        = $bloques['conducta'] ?? ['secciones' => 0, 'cerradas' => 0, 'pend_tutor' => 0,
                                     'pend_auxiliar' => 0, 'esperados' => 0, 'calificados' => 0];
$a        = $bloques['asistencia'] ?? ['secciones' => 0, 'completas' => 0, 'esperados' => 0, 'registrados' => 0];
$condLit  = $bloques['conducta_literales'] ?? ['periodos' => [], 'niveles' => []];
$condCrit = $bloques['conducta_criterios'] ?? ['criterios' => [], 'secciones' => []];
$asisSecc = $bloques['asistencia_secciones'] ?? [];
$asisTop  = $bloques['asistencia_top'] ?? [];
$asisAncla = $bloques['asistencia_ancla'] ?? null;   // icono de la columna FJ (076)

$pidVista = (int) ($periodo['id'] ?? 0);

// ── Conducta: reparto de literales del bimestre a la vista ───────────
// Se suman los dos niveles. El desglose por nivel lo dibuja el gráfico G6;
// aquí interesa la cifra institucional que va en las tarjetas.
$lit = ['ad' => 0, 'a' => 0, 'b' => 0, 'c' => 0, 'total' => 0, 'logro' => 0];

// Y de paso el mismo reparto de CADA bimestre, para poder comparar con el
// anterior sin volver a recorrer la estructura.
$porPeriodo = [];

foreach ($condLit['niveles'] ?? [] as $n) {
    foreach ($n['serie'] as $celda) {
        $pidCelda = (int) $celda['periodo_id'];
        if (!isset($porPeriodo[$pidCelda])) {
            $porPeriodo[$pidCelda] = ['total' => 0, 'logro' => 0];
        }
        $porPeriodo[$pidCelda]['total'] += (int) $celda['total'];
        $porPeriodo[$pidCelda]['logro'] += (int) $celda['logro'];

        if ($pidCelda === $pidVista) {
            foreach (['ad', 'a', 'b', 'c', 'total', 'logro'] as $campo) {
                $lit[$campo] += (int) $celda[$campo];
            }
        }
    }
}

$pctLogro = $lit['total'] > 0 ? (int) round($lit['logro'] / $lit['total'] * 100) : 0;

// ── Comparación con el bimestre anterior ─────────────────────────────
// El anterior CON DATO, no el inmediatamente anterior: si el I Bimestre no se
// registró, comparar contra su 0 % diría "hemos mejorado 90 puntos", que es
// exactamente lo contrario de la verdad (no había nada que medir).
$delta        = null;
$nombrePrevio = '';

if ($lit['total'] > 0) {
    $previo = null;
    foreach ($condLit['periodos'] ?? [] as $per) {
        if ((int) $per['id'] === $pidVista) {
            break;
        }
        if ((int) ($porPeriodo[(int) $per['id']]['total'] ?? 0) > 0) {
            $previo = $per;
        }
    }

    if ($previo) {
        $d = $porPeriodo[(int) $previo['id']];
        $delta        = round($pctLogro - ($d['logro'] / $d['total'] * 100), 1);
        $nombrePrevio = (string) $previo['nombre'];
    }
}

// ── Asistencia: los 4 contadores del bimestre, agregados ─────────────
// 🔴 `faltas` y `tardanzas` YA son las no justificadas: en `inasistencias` los
// cuatro contadores son columnas independientes, no un total y su subconjunto.
// Nunca restar (ver el docblock de AsistenciaModel).
$asisTot = ['alumnos' => 0, 'faltas' => 0, 'faltas_justificadas' => 0,
            'tardanzas' => 0, 'tardanzas_justificadas' => 0, 'sin_incidencias' => 0];

foreach ($asisSecc as $s) {
    foreach (array_keys($asisTot) as $campo) {
        $asisTot[$campo] += (int) ($s[$campo] ?? 0);
    }
}
