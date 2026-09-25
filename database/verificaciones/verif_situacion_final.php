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

// ── Competencias PENDIENTES: acotamiento y certeza (24/09/2026) ──────────────
// En B1-B3 el docente elige que competencias evalua: casi toda area esta a
// medias. `situacion_final_proyectar()` calcula la sigla con lo evaluado y la
// prueba contra el PLAN completo (pendientes = AD / = C).
echo "\n=== Pendientes: situacion_final_proyectar() ===\n";

/** Un area con su plan: $plan competencias, de las evaluadas $ab/$b/$c. */
$areaP = static fn(int $ab, int $b, int $c, int $plan, string $nombre = 'Area'): array =>
    ['plan' => $plan] + $area($ab, $b, $c, $nombre);

// Intermedio (sec 3.o): Matematica de 4 con 1 sola C evaluada -> hoy RR, pero
// con AD en las 3 pendientes el area llegaria a la mitad -> PROYECTADO.
$base = $rep($areaP(2, 0, 0, 2), 8);
$p = situacion_final_proyectar(array_merge($base, [$areaP(0, 0, 1, 4, 'Matematica')]), 'sec', 3);
$chk('intermedio: area de 4 con 1 C evaluada -> RR proyectado',
    $p['situacion'] === SITUACION_RR && $p['certeza'] === CERTEZA_PROYECTADA && $p['pendientes'] === 3
        && str_contains($p['motivo'], 'Matematica'),
    "{$p['situacion']} {$p['certeza']} pend={$p['pendientes']}");

// La misma C en un area de UNA competencia, sin pendientes -> RR seguro.
$p = situacion_final_proyectar(array_merge($base, [$areaP(0, 0, 1, 1)]), 'sec', 3);
$chk('sin pendientes: RR seguro', $p['situacion'] === SITUACION_RR && $p['certeza'] === CERTEZA_SEGURA,
    "{$p['situacion']} {$p['certeza']}");

// Final de ciclo (sec 5.o): todo AD pero con pendientes -> PRO PROYECTADO: una
// sola C en lo pendiente impide la promocion.
$p = situacion_final_proyectar(array_merge($rep($areaP(2, 0, 0, 2), 7), [$areaP(1, 0, 0, 3)]), 'sec', 5);
$chk('final de ciclo con pendientes: PRO nunca seguro',
    $p['situacion'] === SITUACION_PRO && $p['certeza'] === CERTEZA_PROYECTADA, "{$p['situacion']} {$p['certeza']}");

// Un area del plan sin NINGUNA evaluada (n = 0) cuenta como pendiente y no
// entra a la sigla.
$p = situacion_final_proyectar(array_merge($base, [$areaP(0, 0, 0, 3, 'Ingles')]), 'sec', 3);
$chk('area del plan sin evaluar: fuera de la sigla, dentro de las pendientes',
    $p['areas'] === 8 && $p['pendientes'] === 3 && $p['areas_pendientes'] === ['Ingles'],
    "areas={$p['areas']} pend={$p['pendientes']}");

// Periodo FINAL: con pendientes no se afirma nada; completo, certeza segura.
$p = situacion_final_proyectar(array_merge($base, [$areaP(0, 0, 1, 4)]), 'sec', 3, true);
$chk('periodo final con pendientes -> PENDIENTE, que no es riesgo',
    $p['situacion'] === SITUACION_PENDIENTE && !situacion_es_riesgo($p['situacion']), $p['situacion']);
$p = situacion_final_proyectar(array_merge($base, [$areaP(0, 0, 1, 1)]), 'sec', 3, true);
$chk('periodo final completo -> sigla con certeza segura',
    $p['situacion'] === SITUACION_RR && $p['certeza'] === CERTEZA_SEGURA, "{$p['situacion']} {$p['certeza']}");

// 1.o de primaria y sin datos: no hay nada que acotar.
$p = situacion_final_proyectar([$areaP(0, 0, 2, 4)], 'prim', 1);
$chk('1.o de primaria: automatica, sin certeza', $p['automatica'] && $p['certeza'] === null, (string) $p['certeza']);
$p = situacion_final_proyectar([$areaP(0, 0, 0, 4)], 'sec', 2);
$chk('solo areas sin evaluar -> ND, sin certeza',
    $p['situacion'] === SITUACION_SIN_DATOS && $p['certeza'] === null, $p['situacion']);

// MONOTONIA de las cotas, que es lo que permite una certeza de dos valores:
// si la sigla es PRO el mejor caso es PRO; si es riesgo, el peor caso tambien.
mt_srand(20260924);
$rotas = 0;
for ($i = 0; $i < 3000; $i++) {
    $areas = [];
    for ($k = 0, $na = mt_rand(1, 11); $k < $na; $k++) {
        $plan = mt_rand(1, 5);
        $n    = mt_rand(0, $plan);
        $ab   = mt_rand(0, $n); $b = mt_rand(0, $n - $ab);
        $areas[] = $areaP($ab, $b, $n - $ab - $b, $plan);
    }
    $nivel = mt_rand(0, 1) ? 'prim' : 'sec';
    $grado = mt_rand(2, $nivel === 'prim' ? 6 : 5);
    $x = situacion_final_proyectar($areas, $nivel, $grado);
    if ($x['situacion'] === SITUACION_SIN_DATOS) { continue; }
    if ($x['situacion'] === SITUACION_PRO && $x['mejor'] !== SITUACION_PRO) { $rotas++; }
    if (situacion_es_riesgo($x['situacion']) && !situacion_es_riesgo($x['peor'])) { $rotas++; }
}
$chk('monotonia de las cotas en 3000 casos aleatorios', $rotas === 0, "$rotas rotos");

foreach ([SITUACION_PRO, SITUACION_RR, SITUACION_PER, SITUACION_SIN_DATOS, SITUACION_PENDIENTE] as $sig) {
    $chk("rotulo de $sig no esta vacio", situacion_rotulo($sig) !== '', situacion_rotulo($sig));
}

echo "\n=== Seguimiento pedagogico (24/09/2026) ===\n";
// Umbral literal de la decision: primaria >= 3 B o >= 3 C (cada literal por
// separado), secundaria >= 3 C. Los casos del borde, en las dos ramas.
$chk('seguimiento: umbrales en 3', SEGUIMIENTO_MIN_B === 3 && SEGUIMIENTO_MIN_C === 3);
$chk('prim: 3 B entra',          seguimiento_pedagogico(3, 0, 'prim'));
$chk('prim: 3 C entra',          seguimiento_pedagogico(0, 3, 'prim'));
$chk('prim: 2 B + 1 C NO entra', !seguimiento_pedagogico(2, 1, 'prim'));
$chk('prim: 2 B + 2 C NO entra', !seguimiento_pedagogico(2, 2, 'prim'));
$chk('prim: nivel largo primaria se normaliza', seguimiento_pedagogico(3, 0, 'primaria'));
$chk('sec: 3 C entra',           seguimiento_pedagogico(0, 3, 'sec'));
$chk('sec: 2 C NO entra',        !seguimiento_pedagogico(9, 2, 'sec'));
$chk('sec: 3 B NO entra (la B aprueba en secundaria)', !seguimiento_pedagogico(3, 0, 'sec'));

echo "\n=== Efecto de cada competencia: chips (24/09/2026) ===\n";
// Analiza y devuelve el efecto de una fila del area $i con literal $lit.
$efecto = function (array $areas, string $niv, int $gr, int $i, string $lit): ?string {
    $an = situacion_final_analisis($areas, $niv, $gr);
    // Como el modelo: sin veredicto del area (promocion automatica), sin chip.
    return isset($an["por_area"][$i]) ? situacion_efecto_competencia($an["por_area"][$i], $lit, $an) : null;
};
$ar = fn(int $ab, int $b, int $c, string $nom = "X") => ["id" => crc32($nom), "nombre" => $nom, "n" => $ab + $b + $c, "n_ab" => $ab, "n_b" => $b, "n_c" => $c];
$bien = array_map(fn($k) => $ar(3, 0, 0, "A$k"), range(1, 5));

// Intermedio (prim 5.o): area de 3 con 2 C -> no llega a la mitad (2) en B o superior.
$chk("intermedio: C de un area que no llega -> Causa RR",
    $efecto(array_merge($bien, [$ar(0, 1, 2)]), "prim", 5, 5, "C") === EFECTO_RR);
$chk("intermedio: B de esa misma area -> sin chip (la B suma)",
    $efecto(array_merge($bien, [$ar(0, 1, 2)]), "prim", 5, 5, "B") === null);
// Area de 3 con 1 C: cumple justo (2 de 3) -> en el limite.
$chk("intermedio: area que cumple justo -> En el limite",
    $efecto(array_merge($bien, [$ar(0, 2, 1)]), "prim", 5, 5, "C") === EFECTO_LIMITE);
// Area de 4 con 1 C: cumple con holgura (3 de 4, mitad 2) -> sin chip.
$chk("intermedio: area con holgura -> sin chip",
    $efecto(array_merge($bien, [$ar(0, 3, 1)]), "prim", 5, 5, "C") === null);
// El chip responde a la SITUACION del estudiante (24/09/2026). Un RR con un area
// de 4 con 3 C (que tambien sumaria a PER) lleva "Causa RR", no "Suma a PER".
$chk("RR: area con C en mas de la mitad -> Causa RR (no Suma a PER)",
    $efecto(array_merge($bien, [$ar(0, 1, 3)]), "sec", 3, 5, "C") === EFECTO_RR);
$an = situacion_final_analisis(array_merge($bien, [$ar(0, 1, 3, "Mat")]), "sec", 3);
$chk("RR: el motivo dice cuantas de las 4 areas de PER reune",
    str_contains($an["motivo"], "Reúne 1 de las 4 áreas"), $an["motivo"]);
// PER: 4 areas con C en mas de la mitad -> sus filas en C llevan "Suma a PER".
$cuatro = [$ar(0,1,3,"P"), $ar(0,1,3,"Q"), $ar(0,1,3,"R"), $ar(0,1,3,"S"), $ar(3,0,0,"T")];
$chk("PER: C de un area que forma las 4 -> Suma a PER",
    $efecto($cuatro, "sec", 3, 0, "C") === EFECTO_PER);
$chk("PER: fila de un area que no suma -> sin chip",
    $efecto($cuatro, "sec", 3, 4, "C") === null);
// Final de ciclo (sec 5.o): areas de 4 con 2 C -> la mitad o mas cuenta para PER.
$cuatroF = [$ar(2,0,2,"P"), $ar(2,0,2,"Q"), $ar(2,0,2,"R"), $ar(2,0,2,"S"), $ar(3,0,0,"T")];
$chk("sec final PER: C en la mitad -> Suma a PER (corte >=)",
    $efecto($cuatroF, "sec", 5, 0, "C") === EFECTO_PER);
$chk("prim final: esas mismas areas no son PER (corte >) -> Causa RR",
    $efecto($cuatroF, "prim", 6, 0, "C") === EFECTO_RR);
// El mismo caso en prim 6.o (final): la mitad NO basta para PER (corte >) -> Causa RR.
$chk("prim final: C en la mitad -> Causa RR (corte >)",
    $efecto(array_merge($bien, [$ar(2, 0, 2)]), "prim", 6, 5, "C") === EFECTO_RR);
// Final de ciclo: una sola C en un area holgada -> Causa RR igual.
$chk("final de ciclo: toda C -> Causa RR",
    $efecto(array_merge($bien, [$ar(3, 0, 1)]), "sec", 2, 5, "C") === EFECTO_RR);
// Prim 4.o (final) sin C y con solo 2 areas en A/AD (se exigen 4): las B de las areas sin la mitad en A/AD causan el RR.
$sinAb = [$ar(3,0,0,"P"), $ar(3,0,0,"Q"), $ar(0,3,0,"R"), $ar(0,3,0,"S"), $ar(0,3,0,"T")];
$chk("prim final sin C con falta de A/AD -> sus B causan RR",
    $efecto($sinAb, "prim", 4, 2, "B") === EFECTO_RR);
$sinAb2 = [$ar(3,0,0,"P"), $ar(3,0,0,"Q"), $ar(3,0,0,"U"), $ar(3,0,0,"V"), $ar(0,3,0,"R")];
$chk("prim final con las 4 areas en A/AD -> la B no causa nada",
    $efecto($sinAb2, "prim", 4, 4, "B") === null);
$chk("1.o de primaria: nunca chip",
    $efecto([$ar(0, 0, 3)], "prim", 1, 0, "C") === null);
$chk("rotulos de los chips", situacion_efecto_rotulo(EFECTO_PER) === "PER"
    && situacion_efecto_rotulo(EFECTO_RR) === "RR" && situacion_efecto_rotulo(EFECTO_LIMITE) === "En el límite");

echo "\n", $ok ? "TODO OK\n" : "HAY FALLAS\n";
exit($ok ? 0 : 1);
