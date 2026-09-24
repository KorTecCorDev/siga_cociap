<?php

/**
 * Verificación — la REGLA DE SITUACIÓN FINAL del MINEDU (PRO / RR / PER).
 * Uso: php database/verificaciones/verif_situacion_final.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN (por eso NO lleva el guard de secretos que sí tienen los que escriben).
 *
 * CONTEXTO
 *   Hasta el 23/09/2026 «estudiante en riesgo» era un conteo GLOBAL inventado
 *   por el colegio (primaria B+C >= 3 · secundaria C >= 3). Desde entonces es la
 *   situación final que define el MINEDU —RVM N° 00094-2020-MINEDU, modificada
 *   por la RVM N° 048-2024-MINEDU—, que se cuenta POR ÁREA y cambia según el
 *   grado sea final de ciclo o intermedio. Riesgo = RR + PER.
 *
 * QUÉ COMPRUEBA
 *   1. `mitad_competencias()` respeta la convención literal de la norma para los
 *      números impares (5 -> 3, 3 -> 2, 1 -> 1).
 *   2. `grado_final_de_ciclo()` y `grado_promocion_automatica()` marcan los
 *      grados correctos en los dos niveles.
 *   3. `situacion_final()` sobre una tabla de casos SINTÉTICOS, uno por rama de
 *      la norma, escritos a mano AQUÍ. No tocan la base de datos: la regla es
 *      pura, y probarla contra datos reales solo diría qué pasó este bimestre,
 *      no si la regla es la de la norma.
 *
 *   El cuadre del informe contra la BD vive en `verif_riesgo_situacion_bd.php`.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';

$ok = true;

$chk = static function (string $titulo, bool $pasa, string $detalle = '') use (&$ok): void {
    if (!$pasa) { $ok = false; }
    echo ($pasa ? '  OK    ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};

/** Un área: n competencias, de las cuales $ab en AD/A, $b en B y $c en C. */
$area = static fn(int $ab, int $b, int $c, string $nombre = 'Area'): array => [
    'nombre' => $nombre,
    'n'      => $ab + $b + $c,
    'n_ab'   => $ab,
    'n_b'    => $b,
    'n_c'    => $c,
];

/** $veces copias del mismo área. */
$rep = static fn(array $a, int $veces): array => array_fill(0, $veces, $a);

// ── 1. La convención de «la mitad» ───────────────────────────────────────────
echo "=== 1. mitad_competencias() — convencion literal de la norma ===\n";

// La norma lo dice con estos numeros exactos: 5 -> 3 y 3 -> 2. El area de una
// sola competencia (EPT, Taller de Pre-Calculo, Etica) -> esa unica.
$chk('area de 5 competencias: la mitad son 3', mitad_competencias(5) === 3, (string) mitad_competencias(5));
$chk('area de 3 competencias: la mitad son 2', mitad_competencias(3) === 2, (string) mitad_competencias(3));
$chk('area de 1 competencia: la mitad es esa unica', mitad_competencias(1) === 1, (string) mitad_competencias(1));
$chk('area de 4 competencias: la mitad son 2', mitad_competencias(4) === 2, (string) mitad_competencias(4));
$chk('area de 2 competencias: la mitad es 1', mitad_competencias(2) === 1, (string) mitad_competencias(2));

// ── 2. Grados finales de ciclo y promoción automática ────────────────────────
echo "\n=== 2. Grados finales de ciclo y promocion automatica ===\n";

$finPrim = array_values(array_filter([1,2,3,4,5,6], static fn($g) => grado_final_de_ciclo('prim', $g)));
$finSec  = array_values(array_filter([1,2,3,4,5],   static fn($g) => grado_final_de_ciclo('sec',  $g)));

$chk('primaria: finales de ciclo = 2, 4 y 6',   $finPrim === [2, 4, 6], implode(',', $finPrim));
$chk('secundaria: finales de ciclo = 2 y 5',    $finSec  === [2, 5],    implode(',', $finSec));
$chk('promocion automatica solo en 1.o de primaria',
    grado_promocion_automatica('prim', 1)
    && !grado_promocion_automatica('prim', 2)
    && !grado_promocion_automatica('sec', 1));
// Los nombres largos tienen que dar lo mismo que los codigos cortos: pasar uno
// por el otro no debe caer en silencio del lado de secundaria.
$chk('acepta el nombre largo del nivel igual que el codigo',
    grado_final_de_ciclo('primaria', 6) && grado_promocion_automatica('primaria', 1));

// ── 3. Casos sintéticos, uno por rama de la norma ────────────────────────────
echo "\n=== 3. situacion_final() — una rama de la norma por caso ===\n";

$casos = [
    // ── PRIMARIA ────────────────────────────────────────────────────────────
    [
        'prim', 1, 'PRO',
        'prim 1.o: promocion automatica aunque todo este en C',
        $rep($area(0, 0, 3), 8),
    ],
    [
        'prim', 2, 'PRO',
        'prim 2.o (final de ciclo): 4 areas con la mitad en A/AD y cero C',
        array_merge($rep($area(2, 1, 0), 4), $rep($area(0, 3, 0), 4)),
    ],
    [
        'prim', 2, 'RR',
        'prim 2.o: las mismas 4 areas pero UNA SOLA competencia en C',
        array_merge($rep($area(2, 1, 0), 4), $rep($area(0, 3, 0), 3), [$area(0, 2, 1)]),
    ],
    [
        'prim', 6, 'RR',
        'prim 6.o: cero C pero solo 3 areas con la mitad en A/AD (se exigen 4)',
        array_merge($rep($area(2, 1, 0), 3), $rep($area(0, 3, 0), 5)),
    ],
    [
        'prim', 3, 'PRO',
        'prim 3.o (intermedio): la mitad en B o superior en TODAS, con C en el resto',
        $rep($area(0, 2, 1), 8),
    ],
    [
        'prim', 3, 'RR',
        'prim 3.o: un area sin la mitad en B o superior',
        array_merge($rep($area(0, 2, 1), 7), [$area(0, 1, 2)]),
    ],
    [
        'prim', 4, 'PER',
        'prim 4.o: 4 areas con MAS de la mitad de sus competencias en C',
        array_merge($rep($area(0, 0, 3), 4), $rep($area(3, 0, 0), 4)),
    ],

    // ── SECUNDARIA ──────────────────────────────────────────────────────────
    [
        'sec', 1, 'PRO',
        'sec 1.o (intermedio): la mitad en B o superior en TODAS las areas',
        $rep($area(0, 2, 1), 10),
    ],
    [
        'sec', 4, 'RR',
        'sec 4.o: un area sin la mitad en B o superior',
        array_merge($rep($area(1, 1, 1), 9), [$area(0, 1, 2)]),
    ],
    [
        'sec', 2, 'PRO',
        'sec 2.o (final de ciclo): 3 areas con la mitad en A/AD y cero C',
        array_merge($rep($area(2, 1, 0), 3), $rep($area(0, 3, 0), 7)),
    ],
    [
        'sec', 5, 'RR',
        'sec 5.o: 3 areas en A/AD pero UNA SOLA competencia en C',
        array_merge($rep($area(2, 1, 0), 3), $rep($area(0, 3, 0), 6), [$area(0, 2, 1)]),
    ],

    // ── El area de UNA competencia (EPT, Pre-Calculo, Etica) ────────────────
    [
        'sec', 1, 'RR',
        'sec 1.o: un area de UNA competencia en C reprueba el area entera',
        array_merge($rep($area(3, 0, 0), 9), [$area(0, 0, 1, 'Educacion para el Trabajo')]),
    ],
];

foreach ($casos as [$nivel, $grado, $esperado, $titulo, $areas]) {
    $real = situacion_final($areas, $nivel, $grado);
    $chk($titulo, $real === $esperado, "esperado $esperado, dio $real");
}

// ── 4. «La mitad o más» (sec 2.o/5.o) NO es «más de la mitad» (el resto) ─────
echo "\n=== 4. El umbral de permanencia difiere entre grados ===\n";

// 4 areas de 3 competencias con EXACTAMENTE 2 en C (la mitad de 3 es 2):
//   · «la mitad o mas»  -> cuenta  -> secundaria 2.o y 5.o permanecen
//   · «mas de la mitad» -> NO cuenta -> secundaria 1.o, 3.o y 4.o solo recuperan
// Ademas ninguna de esas areas llega a la mitad en B o superior (1 < 2), asi que
// en los grados intermedios tampoco hay promocion: el caso aisla el umbral.
$frontera = array_merge($rep($area(1, 0, 2), 4), $rep($area(3, 0, 0), 6));

$chk('sec 2.o: 4 areas con la MITAD de sus competencias en C -> PER',
    situacion_final($frontera, 'sec', 2) === SITUACION_PER,
    situacion_final($frontera, 'sec', 2));
$chk('sec 1.o: el mismo alumno -> RR (exige MAS de la mitad)',
    situacion_final($frontera, 'sec', 1) === SITUACION_RR,
    situacion_final($frontera, 'sec', 1));

// ── 5. Riesgo, huecos de datos y rótulos ─────────────────────────────────────
echo "\n=== 5. Riesgo, huecos de datos y rotulos ===\n";

$chk('riesgo = RR + PER, nunca PRO',
    situacion_es_riesgo(SITUACION_RR)
    && situacion_es_riesgo(SITUACION_PER)
    && !situacion_es_riesgo(SITUACION_PRO));

// Sin un area evaluada no se inventa ni un PRO ni un dato EN CONTRA.
$nd = situacion_final_analisis([], 'sec', 1);
$chk('sin competencias evaluadas -> ND, y ND no es riesgo',
    $nd['situacion'] === SITUACION_SIN_DATOS && !situacion_es_riesgo($nd['situacion']),
    $nd['situacion']);

// El motivo es lo que lee el tutor: tiene que existir y no contradecir la sigla.
$rr = situacion_final_analisis(
    array_merge($rep($area(3, 0, 0), 9), [$area(0, 0, 1, 'Educacion para el Trabajo')]),
    'sec',
    1
);
$chk('el motivo de un RR nombra el area que falla',
    $rr['situacion'] === SITUACION_RR && str_contains($rr['motivo'], 'Educacion para el Trabajo'),
    $rr['motivo']);

$pro = situacion_final_analisis($rep($area(0, 2, 1), 8), 'prim', 3);
$chk('un PRO tambien trae motivo', $pro['motivo'] !== '', $pro['motivo']);

foreach ([SITUACION_PRO, SITUACION_RR, SITUACION_PER, SITUACION_SIN_DATOS] as $sig) {
    $chk("rotulo de $sig no esta vacio", situacion_rotulo($sig) !== '', situacion_rotulo($sig));
}

echo "\n", $ok ? "TODO OK\n" : "HAY FALLAS\n";
exit($ok ? 0 : 1);
