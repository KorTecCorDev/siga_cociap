<?php

/**
 * Verificación — el informe de RIESGO ACADÉMICO cuadra con la base de datos.
 * Uso: php database/verificaciones/verif_riesgo_situacion_bd.php
 *
 * SOLO LECTURA salvo la simulación del retorno de grado, que va en una
 * TRANSACCIÓN CON ROLLBACK y no deja nada escrito.
 *
 * POR QUÉ EXISTE
 *   Hasta el 23/09/2026 «estudiante en riesgo» era un conteo global inventado
 *   (primaria B+C >= 3 · secundaria C >= 3) que salía del motor del mérito.
 *   Ahora es la SITUACIÓN FINAL del MINEDU (`SituacionFinalModel`), que se
 *   cuenta POR ÁREA, cambia según el grado sea final de ciclo o intermedio, y
 *   tiene su propio roster. Son cuatro reglas nuevas conviviendo, y este
 *   repositorio ya vio divergir cuatro reglas copiadas a mano sin síntoma.
 *
 *   La regla PURA la verifica `verif_situacion_final.php` con casos sintéticos.
 *   Aquí se comprueba lo otro: que sobre los datos reales el modelo seleccione
 *   a la gente correcta.
 *
 * QUÉ COMPRUEBA, para CADA periodo con datos:
 *   1. ROSTER: `evaluados` + `sin_datos` = el roster anclado contado con una
 *      consulta ESCRITA AQUÍ A MANO, y la suma de secciones = `evaluados`.
 *   2. SELECCIÓN: `en_riesgo` son exactamente los que la regla del MINEDU,
 *      aplicada AQUÍ A MANO sobre conteos por área sacados de otra consulta
 *      propia, marca como RR o PER. Si el helper se rompe, el verificador no
 *      hereda el mismo error.
 *   3. ORDEN: permanencia primero, luego más C, luego más B.
 *   4. PERFIL: AD+A+B+C = competencias evaluadas, y A nunca es negativo.
 *   5. DESGLOSE: cuadra con las competencias no aprobatorias del nivel y cada
 *      literal corresponde a su nota.
 *   6. 1.º DE PRIMARIA nunca aparece en riesgo (promoción automática).
 *   7. RETORNO DE GRADO: la fila se cuenta en la matrícula OFICIAL, y las tres
 *      ramas que los datos reales no ejercen se SIMULAN con rollback.
 *   8. Las dos preguntas de «riesgo» de `/admin/cuadros` siguen siendo dos.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

spl_autoload_register(function (string $class): void {
    $map = ['Core\\' => CORE_PATH . '/', 'App\\Models\\' => APP_PATH . '/Models/'];
    foreach ($map as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require_once $file; return; }
        }
    }
});
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';
date_default_timezone_set(config('timezone'));

use App\Models\AnioAcademicoModel;
use App\Models\SituacionFinalModel;

$pdo = Core\Database::connect();

$fallos = 0;
$ok = function (bool $cond, string $etiqueta, string $detalle = '') use (&$fallos): void {
    printf("  %-5s %-62s %s\n", $cond ? 'OK' : '***', $etiqueta, $detalle);
    if (!$cond) { $fallos++; }
};

echo "== Huella ==\n";
printf("  bd=%s · usuario=%s · so=%s\n\n",
    $pdo->query("SELECT DATABASE()")->fetchColumn(),
    $pdo->query("SELECT USER()")->fetchColumn(),
    $pdo->query("SELECT @@version_compile_os")->fetchColumn());

// ─────────────────────────────────────────────────────────────────────────────
// Controles ESCRITOS A MANO. No llaman al modelo ni a los helpers de la regla:
// si salieran de la misma fuente, los asertos no probarían nada.
// ─────────────────────────────────────────────────────────────────────────────

/** Roster anclado del periodo: matricula_id => [grado_id, nivel_codigo, grado_numero, seccion]. */
$rosterControl = static function (int $pid) use ($pdo): array {
    $st = $pdo->prepare("
        SELECT m.id, s.nombre AS sec, g.id AS gid, g.numero AS gnum, n.codigo AS nivel
        FROM matriculas m
        JOIN periodos per ON per.anio_id = m.anio_id AND per.id = ?
        JOIN secciones s  ON s.id = m.seccion_id
        JOIN grados g     ON g.id = s.grado_id
        JOIN niveles n    ON n.id = g.nivel_id
        WHERE m.tipo NOT IN ('trasladado', 'retirado')
          AND m.id NOT IN (
              SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo'
              UNION
              SELECT r.matricula_oficial_id
              FROM retornos_grado r
              JOIN calificaciones c2 ON c2.matricula_id = r.matricula_operativa_id AND c2.periodo_id = ?
              WHERE r.estado = 'revertido'
          )
    ");
    $st->execute([$pid, $pid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = [
            'gid'   => (int) $r['gid'],
            'gnum'  => (int) $r['gnum'],
            'nivel' => (string) $r['nivel'],
            'sec'   => (string) $r['sec'],
        ];
    }
    return $out;
};

/** Conteos por área de cada matrícula: [mid][area_id] => [n, ab, b, c]. */
$areasControl = static function (int $pid) use ($pdo): array {
    // Periodo elegido: su numero, su anio y si es el FINAL (mayor numero).
    $pp = $pdo->prepare("SELECT anio_id, numero,
                                numero = (SELECT MAX(numero) FROM periodos x WHERE x.anio_id = p.anio_id) AS fin
                         FROM periodos p WHERE id = ?");
    $pp->execute([$pid]);
    $per = $pp->fetch(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT cal.matricula_id AS mid, a.id AS aid, cal.competencia_id AS cid,
               cal.nota_numerica AS nota, pe.numero AS num
        FROM calificaciones cal
        JOIN bloqueos_competencia bc
          ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
         AND bc.periodo_id = cal.periodo_id
        JOIN periodos pe       ON pe.id = cal.periodo_id AND pe.anio_id = ?
        JOIN competencias comp ON comp.id = cal.competencia_id
        LEFT JOIN subareas sa  ON sa.id = comp.subarea_id
        JOIN areas a           ON a.id = COALESCE(sa.area_id, comp.area_id)
        WHERE pe.numero <= ?
          -- Las extraordinarias CUENTAN (24/09/2026): son notas oficiales de
          -- boleta y SIAGIE. El merito, en cambio, las sigue excluyendo.
          -- Los TALLERES no cuentan (migracion 064: la UGEL no los aprobo).
          -- Un TALLER cuenta solo si la UGEL lo aprobo para el año y grado de la
          -- matricula (talleres_aprobacion, migracion 064).
          AND (a.tipo NOT IN ('transversal', 'tutoria', 'taller') OR a.nombre_boleta = ?
               OR (a.tipo = 'taller' AND EXISTS (
                   -- Grado de la matricula OFICIAL (retorno de grado).
                   SELECT 1 FROM matriculas mx
                   LEFT JOIN retornos_grado rx ON rx.matricula_operativa_id = mx.id
                   JOIN matriculas ox ON ox.id = COALESCE(rx.matricula_oficial_id, mx.id)
                   JOIN secciones sx ON sx.id = ox.seccion_id
                   JOIN talleres_aprobacion tx ON tx.area_id = a.id AND tx.anio_id = ox.anio_id
                                              AND tx.grado_id = sx.grado_id AND tx.aprobado = 1
                   WHERE mx.id = cal.matricula_id)))
          AND NOT EXISTS (
              SELECT 1 FROM exoneraciones ex
              WHERE ex.matricula_id = cal.matricula_id AND ex.revocado_en IS NULL
                AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
          )
    ");
    $st->execute([(int) $per['anio_id'], (int) $per['numero'], AREA_ETICA_NOMBRE_BOLETA]);

    // ULTIMO NIVEL REGISTRADO por competencia (como el SIAGIE); en el periodo
    // final, solo sus propias notas. Escrito aqui a mano, sin el modelo.
    $ultima = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($per['fin'] && (int) $r['num'] !== (int) $per['numero']) { continue; }
        $k = $r['mid'] . '|' . $r['cid'];
        if (!isset($ultima[$k]) || (int) $r['num'] > (int) $ultima[$k]['num']) { $ultima[$k] = $r; }
    }

    $out = [];
    foreach ($ultima as $r) {
        $mid = (int) $r['mid'];
        $aid = (int) $r['aid'];
        $n   = (int) $r['nota'];
        $out[$mid][$aid] ??= ['n' => 0, 'ab' => 0, 'b' => 0, 'c' => 0];
        $out[$mid][$aid]['n']++;
        // Los tramos van escritos con las constantes de la escala, que son el
        // punto unico; lo que NO se hereda es la regla de la promocion.
        if ($n >= NOTA_MIN_A)      { $out[$mid][$aid]['ab']++; }
        elseif ($n >= NOTA_MIN_B)  { $out[$mid][$aid]['b']++; }
        else                       { $out[$mid][$aid]['c']++; }
    }
    return $out;
};

/**
 * La regla del MINEDU, escrita AQUÍ a mano. Devuelve 'PRO', 'RR', 'PER' o 'ND'.
 * Es la copia de control: si `situacion_final()` se desvía, aquí no se desvía
 * con ella.
 */
$situacionControl = static function (array $areas, string $nivel, int $grado): string {
    $prim  = str_starts_with($nivel, 'prim');
    if ($areas === []) { return 'ND'; }
    if ($prim && $grado === 1) { return 'PRO'; }

    $final = in_array($grado, $prim ? [2, 4, 6] : [2, 5], true);

    $nAb = $nB = $cMas = $cMitad = $cTot = 0;
    foreach ($areas as $a) {
        $mitad = (int) ceil($a['n'] / 2);
        $cTot += $a['c'];
        if ($a['ab'] >= $mitad)             { $nAb++; }
        if ($a['ab'] + $a['b'] >= $mitad)   { $nB++; }
        if ($a['c'] >  $mitad)              { $cMas++; }
        if ($a['c'] >= $mitad)              { $cMitad++; }
    }

    $promovido = $final
        ? ($nAb >= ($prim ? 4 : 3) && $cTot === 0)
        : ($nB === count($areas));
    if ($promovido) { return 'PRO'; }

    $areasC = (!$prim && $final) ? $cMitad : $cMas;
    return $areasC >= 4 ? 'PER' : 'RR';
};

/** Mapa operativa => grado/sección OFICIAL, con su propia consulta. */
$oficialDe = [];
foreach ($pdo->query("
    SELECT r.matricula_operativa_id AS op, s.grado_id AS gid, s.nombre AS sec
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    JOIN secciones s   ON s.id  = mo.seccion_id
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oficialDe[(int) $r['op']] = ['gid' => (int) $r['gid'], 'sec' => (string) $r['sec']];
}

$periodos = $pdo->query("
    SELECT p.id, p.anio_id, p.numero, p.nombre_display, p.estado
    FROM periodos p ORDER BY p.anio_id, p.numero
")->fetchAll(PDO::FETCH_ASSOC);

// ── 1-6. Periodo a periodo ───────────────────────────────────────────────────
$gradosMedidos = $filasRiesgo = $filasDetalle = $reubicados = $perMedidos = 0;

foreach ($periodos as $p) {
    $pid      = (int) $p['id'];
    $porGrado = (new SituacionFinalModel())->porGrado($pid);
    if (!$porGrado) {
        printf("== %s (%s) ==  sin datos, se omite\n\n", $p['nombre_display'], $p['estado']);
        continue;
    }
    printf("== %s (%s) ==\n", $p['nombre_display'], $p['estado']);

    // Los que YA NO PERTENECEN (24/09/2026): trasladados y retirados que
    // cursaron ESTE bimestre, contados a mano con los literales del enum.
    $fueraCtl = ['trasladado' => 0, 'retirado' => 0];
    foreach ($pdo->query("SELECT m.tipo, COUNT(*) n FROM matriculas m
        WHERE m.tipo IN ('trasladado', 'retirado')
          AND m.id IN (SELECT matricula_id FROM calificaciones WHERE periodo_id = $pid)
        GROUP BY m.tipo")->fetchAll(PDO::FETCH_ASSOC) as $f) { $fueraCtl[$f['tipo']] = (int) $f['n']; }
    $resF = riesgo_resumen($porGrado);
    $ok($resF['trasladados'] === $fueraCtl['trasladado'] && $resF['retirados'] === $fueraCtl['retirado'],
        '  ya no pertenecen: trasladados y retirados que cursaron el bimestre',
        "{$resF['trasladados']} trasladados · {$resF['retirados']} retirados");

    $roster = $rosterControl($pid);
    $areas  = $areasControl($pid);

    // Incorporados DESPUÉS del bimestre, escritos a mano con otra forma (el
    // primer bimestre con notas de la matrícula es posterior a este): el modelo
    // los saca de «sin datos» a su propio contador (24/09/2026).
    $incCtl = array_fill_keys(array_map('intval', array_column($pdo->query("
        SELECT c.matricula_id FROM calificaciones c
        INNER JOIN periodos pp ON pp.id = c.periodo_id
        WHERE pp.anio_id = " . (int) $p['anio_id'] . "
        GROUP BY c.matricula_id
        HAVING MIN(pp.numero) > " . (int) $p['numero'])->fetchAll(PDO::FETCH_ASSOC), 'matricula_id')), true);

    // Control por grado OFICIAL: cada matrícula del roster cae en el grado de
    // su matrícula oficial si es la operativa de un retorno.
    $espPorGrado = [];
    foreach ($roster as $mid => $r) {
        $gid = isset($oficialDe[$mid]) ? $oficialDe[$mid]['gid'] : $r['gid'];
        $sec = isset($oficialDe[$mid]) ? $oficialDe[$mid]['sec'] : $r['sec'];
        // El grado y el nivel de la regla son los del grado DESTINO.
        $espPorGrado[$gid][$mid] = ['sec' => $sec, 'areas' => $areas[$mid] ?? []];
    }

    foreach ($porGrado as $g) {
        $gid   = (int) $g['grado']['id'];
        $nivel = (string) $g['grado']['nivel_codigo'];
        $gnum  = (int) $g['grado']['numero'];
        $etq   = $g['grado']['nombre_display'] . ' ' . $nivel;
        $gradosMedidos++;

        $mios = $espPorGrado[$gid] ?? [];

        // 1. Roster y denominadores.
        $evalEsp = $ndEsp = $incEsp = 0;
        $espRR = $espPER = [];
        foreach ($mios as $mid => $d) {
            $sit = $situacionControl($d['areas'], $nivel, $gnum);
            if ($sit === 'ND') { isset($incCtl[$mid]) ? $incEsp++ : $ndEsp++; continue; }
            $evalEsp++;
            if ($sit === 'PER') { $espPER[] = $mid; }
            elseif ($sit === 'RR') { $espRR[] = $mid; }
        }
        $secSuma = array_sum(array_map(static fn($s) => (int) $s['total'], $g['por_seccion']));

        $ok((int) $g['evaluados'] === $evalEsp
            && (int) $g['sin_datos'] === $ndEsp
            && (int) $g['cobertura']['incorporados'] === $incEsp
            && $secSuma === $evalEsp,
            "  $etq · roster: evaluados, sin datos, incorporados y suma de secciones",
            $g['evaluados'] . " evaluados · " . $g['sin_datos'] . ' sin datos · '
                . $g['cobertura']['incorporados'] . ' incorporados despues');

        // 2. Selección: exactamente RR + PER.
        $esperados = array_merge($espRR, $espPER);
        sort($esperados);
        $obtenidos = array_map('intval', array_column($g['en_riesgo'], 'matricula_id'));
        $comparar  = $obtenidos;
        sort($comparar);

        $sitOk = true;
        foreach ($g['en_riesgo'] as $al) {
            $esperada = in_array((int) $al['matricula_id'], $espPER, true) ? 'PER' : 'RR';
            $sitOk = $sitOk && $al['situacion'] === $esperada;
        }
        $ok($comparar === $esperados && $sitOk,
            "  $etq · en riesgo = RR + PER de la regla escrita a mano",
            count($obtenidos) . ' de ' . $evalEsp . ' · ' . count($espPER) . ' PER');
        $perMedidos += count($espPER);

        // 3. Orden: permanencia primero, luego más C, luego más B.
        $clave = static fn(array $a): array => [
            $a['situacion'] === SITUACION_PER ? 1 : 0, (int) $a['num_c'], (int) $a['num_b'],
        ];
        $ordenOk = true;
        for ($i = 1; $i < count($g['en_riesgo']); $i++) {
            if ($clave($g['en_riesgo'][$i - 1]) < $clave($g['en_riesgo'][$i])) {
                $ordenOk = false;
            }
        }
        $ok($ordenOk, "  $etq · orden: permanencia, luego más C, luego más B");

        // 4. Perfil de literales.
        $descuadre = 0;
        foreach ($g['en_riesgo'] as $al) {
            $suma = (int) $al['num_ad'] + (int) $al['num_a'] + (int) $al['num_b'] + (int) $al['num_c'];
            if ($suma !== (int) $al['num_competencias'] || (int) $al['num_a'] < 0) { $descuadre++; }
        }
        $ok($descuadre === 0, "  $etq · perfil AD+A+B+C = competencias, y A nunca es negativo",
            $descuadre > 0 ? "$descuadre fila(s)" : count($obtenidos) . ' fila(s)');

        // 5. Desglose: las competencias NO aprobatorias del nivel, y su literal.
        $malDesglose = $malaNota = 0;
        foreach ($g['en_riesgo'] as $al) {
            $noAprob = (int) $al['num_c'] + (nota_es_aprobatoria('B', $nivel) ? 0 : (int) $al['num_b']);
            if (count($al['detalle']) !== $noAprob) { $malDesglose++; }
            foreach ($al['detalle'] as $d) {
                if ($d['literal'] !== nota_a_literal((int) $d['nota'])
                    || nota_es_aprobatoria($d['literal'], $nivel)) {
                    $malaNota++;
                }
            }
            $filasDetalle += count($al['detalle']);
        }
        $ok($malDesglose === 0 && $malaNota === 0,
            "  $etq · el desglose cuadra con la fila y solo lista no aprobatorias",
            $malDesglose > 0 ? "$malDesglose fila(s)" : ($malaNota > 0 ? "$malaNota nota(s)" : 'cuadra'));

        // 6. 1.º de primaria: promoción automática, nunca en riesgo.
        if ($nivel === 'prim' && $gnum === 1) {
            $ok($g['en_riesgo'] === [],
                "  $etq · promocion automatica: nunca en riesgo",
                count($g['seguimiento']) . ' en seguimiento pedagogico');
        }

        // 6b. Seguimiento (24/09/2026): nunca a la vez en riesgo; solo PRO o
        // promocion automatica, y cada fila cumple el umbral de su nivel.
        $idsRiesgo = array_column($g['en_riesgo'], 'matricula_id');
        $segMal = 0;
        foreach ($g['seguimiento'] as $al) {
            if (in_array($al['matricula_id'], $idsRiesgo, true)
                || !($al['automatica'] || $al['situacion'] === SITUACION_PRO)
                || !seguimiento_pedagogico($al['num_b'], $al['num_c'], $nivel)) {
                $segMal++;
            }
        }
        $ok($segMal === 0, "  $etq · seguimiento: solo PRO/automatica sobre el umbral y fuera del riesgo",
            $segMal > 0 ? "$segMal fila(s) indebidas" : count($g['seguimiento']) . ' en seguimiento');

        // 7a. Reubicación del retorno. Un aserto por GRADO, no por alumno: con
        // 250 filas la salida se volvía ilegible y un fallo se perdía dentro.
        $retMal = $retAqui = 0;
        foreach ($g['en_riesgo'] as $al) {
            $mid = (int) $al['matricula_id'];
            if (isset($oficialDe[$mid])) {
                $reubicados++;
                $retAqui++;
                if (empty($al['retorno']) || $al['seccion_nombre'] !== $oficialDe[$mid]['sec']
                    || $gid !== $oficialDe[$mid]['gid']) {
                    $retMal++;
                }
            } elseif (!empty($al['retorno'])) {
                $retMal++;   // marca de retorno en una fila que no lo es
            }
        }
        if ($retAqui > 0 || $retMal > 0) {
            $ok($retMal === 0, "  $etq · los retornos se cuentan en su matricula OFICIAL",
                "$retAqui reubicada(s)" . ($retMal > 0 ? ", $retMal mal" : ''));
        }

        $filasRiesgo += count($obtenidos);
    }
    echo "\n";
}

echo "== Cobertura de la medicion ==\n";
// Un verificador que pasa en verde sin ejercitar la rama que vigila es peor que
// uno roto: avisa en vez de dar luz verde sobre una premisa falsa.
$ok($gradosMedidos > 0, 'se midieron grados de verdad', "$gradosMedidos grado(s)");
$ok($filasRiesgo > 0,   'la rama "en riesgo" es observable en estos datos', "$filasRiesgo fila(s)");
$ok($filasDetalle > 0,  'el desglose es observable en estos datos', "$filasDetalle fila(s)");
$ok($perMedidos > 0,    'la rama PERMANENCIA es observable en estos datos', "$perMedidos caso(s)");
$ok($oficialDe === [] || $reubicados > 0, 'la reubicacion del retorno es observable',
    count($oficialDe) . " retorno(s) · $reubicados fila(s)");

// ── 7b. Las ramas del retorno que los datos de hoy NO ejercen ────────────────
// El único retorno real tiene la MISMA letra de sección en los dos grados, no
// cruza de nivel y está activo: un modelo que conservara la sección operativa,
// aplicara la regla del grado operativo o ignorara los revertidos pasaría en
// verde. Se simulan dentro de una TRANSACCIÓN CON ROLLBACK.
echo "\n== Retorno de grado simulado (transaccion + rollback) ==\n";
$ret = $pdo->query("
    SELECT r.id, r.matricula_operativa_id AS op, r.matricula_oficial_id AS ofi,
           s.grado_id AS gid, s.anio_id, s.nombre AS sec, n.codigo AS nivel
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    JOIN secciones s   ON s.id  = mo.seccion_id
    JOIN grados g      ON g.id  = s.grado_id
    JOIN niveles n     ON n.id  = g.nivel_id
    ORDER BY r.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Un periodo donde la operativa esté en riesgo: sirve para las tres ramas.
$pSim = null;
if ($ret) {
    foreach ($periodos as $p) {
        foreach ((new SituacionFinalModel())->porGrado((int) $p['id']) as $g) {
            foreach ($g['en_riesgo'] as $al) {
                if ((int) $al['matricula_id'] === (int) $ret['op']) { $pSim = (int) $p['id']; }
            }
        }
        if ($pSim) { break; }
    }
}

if (!$pSim) {
    $ok($ret === false, 'hay un retorno en riesgo para simular',
        $ret ? 'sin caso: la rama queda SIN MEDIR' : 'no hay retornos registrados');
} else {
    $buscar = static function () use ($pSim, $ret): ?array {
        foreach ((new SituacionFinalModel())->porGrado($pSim) as $g) {
            foreach ($g['en_riesgo'] as $al) {
                if ((int) $al['matricula_id'] === (int) $ret['op']) {
                    return ['grado' => $g['grado'], 'fila' => $al];
                }
            }
        }
        return null;
    };

    $pdo->beginTransaction();
    try {
        // (A) Revertido: sigue contando en la matrícula OFICIAL.
        $pdo->prepare("UPDATE retornos_grado SET estado = 'revertido' WHERE id = ?")->execute([$ret['id']]);
        $x = $buscar();
        $ok($x !== null && (int) $x['grado']['id'] === (int) $ret['gid'] && !empty($x['fila']['retorno']),
            'un retorno REVERTIDO tambien se cuenta en la matricula oficial',
            $x ? $x['grado']['nombre_display'] . ' ' . $x['grado']['nivel_codigo'] : 'no aparece');
        $pdo->prepare("UPDATE retornos_grado SET estado = 'activo' WHERE id = ?")->execute([$ret['id']]);

        // (B) Otra sección oficial del mismo grado, con OTRA letra.
        $otra = $pdo->prepare("SELECT id, nombre FROM secciones WHERE grado_id = ? AND anio_id = ? AND nombre <> ? ORDER BY nombre LIMIT 1");
        $otra->execute([$ret['gid'], $ret['anio_id'], $ret['sec']]);
        $otra = $otra->fetch(PDO::FETCH_ASSOC);
        if ($otra) {
            $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$otra['id'], $ret['ofi']]);
            $x = $buscar();
            $ok($x !== null && $x['fila']['seccion_nombre'] === $otra['nombre']
                    && ($x['fila']['retorno']['seccion_nombre'] ?? '') !== $otra['nombre'],
                'la fila reubicada lleva la seccion OFICIAL, no la operativa',
                ($x['fila']['seccion_nombre'] ?? '?') . ' (oficial) · '
                    . ($x['fila']['retorno']['seccion_nombre'] ?? '?') . ' (operativa)');
        } else {
            $ok(false, 'hay otra seccion en el grado oficial para simular');
        }

        // (C) Grado oficial de OTRO grado: manda la regla del grado OFICIAL, que
        //     es lo que decide si es final de ciclo — la diferencia que mas
        //     pesa en la norma.
        $otroGrado = $pdo->prepare("
            SELECT s.id, g.id AS gid, g.numero, n.codigo
            FROM secciones s JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
            WHERE s.anio_id = ? AND n.codigo <> ?
            ORDER BY g.numero LIMIT 1
        ");
        $otroGrado->execute([$ret['anio_id'], $ret['nivel']]);
        $otroGrado = $otroGrado->fetch(PDO::FETCH_ASSOC);
        if ($otroGrado) {
            $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$otroGrado['id'], $ret['ofi']]);
            $areasSim = $areasControl($pSim)[(int) $ret['op']] ?? [];
            $esperada = $situacionControl($areasSim, (string) $otroGrado['codigo'], (int) $otroGrado['numero']);
            $x = $buscar();
            $obtenida = $x['fila']['situacion'] ?? ($esperada === 'PRO' ? 'PRO' : 'no aparece');
            $ok($esperada === 'PRO' ? $x === null : ($x !== null
                    && (int) $x['grado']['id'] === (int) $otroGrado['gid']
                    && $x['fila']['situacion'] === $esperada),
                'un retorno que cruza de grado se juzga con la regla del grado OFICIAL',
                "esperada $esperada · obtenida $obtenida");
        } else {
            $ok(false, 'hay un grado de otro nivel para simular');
        }
    } finally {
        $pdo->rollBack();
    }
    $x = $buscar();
    $ok($x !== null && (int) $x['grado']['id'] === (int) $ret['gid'] && $x['fila']['seccion_nombre'] === $ret['sec'],
        'el rollback dejo el retorno como estaba',
        $x ? $x['grado']['nombre_display'] . ' ' . $x['fila']['seccion_nombre'] : 'no aparece');
}

// ── 7b. La cobertura se RECORTA con el filtro por sección (24/09/2026) ────────
// Antes `riesgo_filtrar_secciones()` dejaba la cobertura y los `sin_datos` del
// grado entero: filtrar a una sección decía «52 sin su plan completo» con 47
// evaluados. Cada sección, sola, no puede tener más parciales que evaluados, y
// la suma de sus secciones tiene que reproducir el grado.
echo "\n== Cobertura por seccion ==\n";
foreach ($periodos as $p) {
    $porGr = (new SituacionFinalModel())->porGrado((int) $p['id']);
    $malas = [];
    foreach ($porGr as $g) {
        $gid = (int) $g['grado']['id'];
        $sumParc = $sumSin = 0;
        foreach (array_keys($g['cobertura_seccion'] ?? []) as $nombre) {
            $sel = riesgo_filtrar_secciones($porGr, [['grado_id' => $gid, 'nombre' => (string) $nombre]]);
            $r   = riesgo_resumen($sel);
            if ($r['cobertura']['parciales'] > $r['evaluados']) {
                $malas[] = $g['grado']['nombre_display'] . " $nombre: {$r['cobertura']['parciales']} parciales / {$r['evaluados']} evaluados";
            }
            $sumParc += $r['cobertura']['parciales'];
            $sumSin  += $r['sin_datos'];
        }
        if ($sumParc !== (int) $g['cobertura']['parciales'] || $sumSin !== (int) $g['sin_datos']) {
            $malas[] = $g['grado']['nombre_display'] . " {$g['grado']['nivel_nombre']}: secciones $sumParc/$sumSin vs grado "
                     . "{$g['cobertura']['parciales']}/{$g['sin_datos']}";
        }
    }
    $ok($malas === [], "{$p['nombre_display']}: la cobertura filtrada por seccion cuadra con su grado",
        implode('; ', array_slice($malas, 0, 3)));
}

// ── 7c. Certeza contra el PLAN completo (24/09/2026) ─────────────────────────
// Copia de control: el plan por competencia con su propia consulta y las cotas
// con `$situacionControl` (la regla escrita a mano). Rellenar lo pendiente con
// AD da el mejor caso; con C, el peor. Un riesgo es seguro si ni el mejor caso
// es PRO; una promocion es proyectada si el peor caso ya no lo es.
echo "\n== Certeza (seguro / proyectado) ==\n";
$planControl = [];
foreach ($pdo->query("
    SELECT m.id AS mid, a.id AS aid, COUNT(DISTINCT comp.id) AS plan
    FROM matriculas m
    JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id AND ca.estado = 'activa'
    JOIN competencias comp
      ON (ca.subarea_id IS NOT NULL AND comp.subarea_id = ca.subarea_id)
      OR (ca.subarea_id IS NULL AND (comp.area_id = ca.area_id
          OR comp.subarea_id IN (SELECT id FROM subareas WHERE area_id = ca.area_id)))
    LEFT JOIN subareas sa ON sa.id = comp.subarea_id
    JOIN areas a ON a.id = COALESCE(sa.area_id, comp.area_id)
    WHERE (a.tipo NOT IN ('transversal', 'tutoria', 'taller') OR a.nombre_boleta = '" . AREA_ETICA_NOMBRE_BOLETA . "'
           OR (a.tipo = 'taller' AND EXISTS (
               SELECT 1 FROM matriculas mx
               LEFT JOIN retornos_grado rx ON rx.matricula_operativa_id = mx.id
               JOIN matriculas ox ON ox.id = COALESCE(rx.matricula_oficial_id, mx.id)
               JOIN secciones sx ON sx.id = ox.seccion_id
               JOIN talleres_aprobacion tx ON tx.area_id = a.id AND tx.anio_id = ox.anio_id
                                          AND tx.grado_id = sx.grado_id AND tx.aprobado = 1
               WHERE mx.id = m.id)))
      AND NOT EXISTS (SELECT 1 FROM exoneraciones ex WHERE ex.matricula_id = m.id AND ex.revocado_en IS NULL
                      AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id))
    GROUP BY m.id, a.id
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $planControl[(int) $r['mid']][(int) $r['aid']] = (int) $r['plan'];
}
$cota = static function (array $ev, array $plan, string $relleno): array {
    $out = [];
    foreach ($plan + array_map(static fn($a) => $a['n'], $ev) as $aid => $_) {
        $a    = $ev[$aid] ?? ['n' => 0, 'ab' => 0, 'b' => 0, 'c' => 0];
        $p    = max($a['n'], $plan[$aid] ?? $a['n']);
        $miss = $p - $a['n'];
        $out[] = ['n' => $p, 'ab' => $a['ab'] + ($relleno === 'AD' ? $miss : 0),
                  'b' => $a['b'], 'c' => $a['c'] + ($relleno === 'C' ? $miss : 0)];
    }
    return $out;
};
foreach ($periodos as $p) {
    $pid   = (int) $p['id'];
    $porGr = (new SituacionFinalModel())->porGrado($pid);
    if (!$porGr) { continue; }
    $areasP = $areasControl($pid);
    $malas  = [];
    $proProy = 0;
    foreach ($porGr as $g) {
        $nv = (string) $g['grado']['nivel_codigo'];
        $gn = (int) $g['grado']['numero'];
        foreach ($g['en_riesgo'] as $al) {
            $mid = (int) $al['matricula_id'];
            $sb  = $situacionControl($cota($areasP[$mid] ?? [], $planControl[$mid] ?? [], 'AD'), $nv, $gn);
            $esp = in_array($sb, ['RR', 'PER'], true) ? CERTEZA_SEGURA : CERTEZA_PROYECTADA;
            if ($al['certeza'] !== $esp) {
                $malas[] = "{$al['nombre_completo']}: modelo {$al['certeza']}, control $esp";
            }
        }
        $proProy += (int) ($g['cobertura']['pro_proyectados'] ?? 0);
    }
    $r = riesgo_resumen($porGr);
    $ok($malas === [] && $r['seguros'] + $r['proyectados'] === $r['total'],
        "{$p['nombre_display']}: certeza de los {$r['total']} en riesgo cuadra con la regla a mano "
            . "({$r['seguros']} seguros, {$r['proyectados']} proyectados)",
        implode('; ', array_slice($malas, 0, 3)));
    $ok($proProy === $r['pro_proyectados'], "{$p['nombre_display']}: promovidos proyectados = suma de grados",
        "$proProy vs {$r['pro_proyectados']}");
}

// ── 7d. Talleres fuera y último nivel registrado (24/09/2026) ────────────────
echo "\n== Talleres y ultimo nivel registrado ==\n";
$talleres = array_column($pdo->query("SELECT id, nombre FROM areas WHERE tipo = 'taller'")->fetchAll(PDO::FETCH_ASSOC), 'nombre', 'id');
$ok(count($talleres) === 2, 'la migracion 064 marco los dos talleres como tipo taller', implode(', ', $talleres));
$nombresTaller = array_flip($talleres);
foreach ($periodos as $p) {
    $pid   = (int) $p['id'];
    $porGr = (new SituacionFinalModel())->porGrado($pid);
    $conTaller = $arr = $arrMal = 0;
    foreach ($porGr as $g) {
        foreach (array_merge($g['en_riesgo'], $g['seguimiento']) as $al) {
            foreach ($al['detalle'] as $d) {
                if (isset($nombresTaller[$d['area']])) { $conTaller++; }
                if (!empty($d['arrastrada'])) {
                    $arr++;
                    if ($d['periodo_numero'] >= (int) $p['numero']) { $arrMal++; }
                }
            }
        }
    }
    $ok($conTaller === 0, "{$p['nombre_display']}: ninguna nota de taller entra al calculo", "$conTaller notas de taller");
    $ok($arrMal === 0, "{$p['nombre_display']}: toda nota arrastrada viene de un bimestre ANTERIOR ($arr en el desglose)", "$arrMal mal");
    if ((int) $p['numero'] === 1) {
        $ok($arr === 0, 'I Bimestre: no hay nada que arrastrar', "$arr");
    }
}

// ── 7e. Aprobacion de la UGEL por año y grado, SIMULADA (transaccion + rollback)
// En 2026 no hay ningun taller aprobado, asi que la rama «aprobado» no la
// ejercen los datos reales: se simula y se deshace. Nunca se borra nada que no
// se haya creado aqui (regla del repo).
echo "\n== Aprobacion de talleres simulada (transaccion + rollback) ==\n";
$tallerRm = $pdo->query("SELECT a.id, a.nivel_id FROM areas a WHERE a.tipo = 'taller'
                         AND a.nombre = 'Taller de Razonamiento Matemático'")->fetch(PDO::FETCH_ASSOC);
$p1 = $pdo->query("SELECT id, anio_id FROM periodos WHERE numero = 1 ORDER BY anio_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($tallerRm && $p1) {
    $g1 = $pdo->prepare("SELECT id FROM grados WHERE nivel_id = ? AND numero = 1");
    $g1->execute([$tallerRm['nivel_id']]);
    $grado1 = (int) $g1->fetchColumn();
    $usr = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();

    // Plan de competencias por matricula y grado, para comparar antes/despues.
    $planDe = static function () use ($p1): array {
        $out = [];
        foreach ((new SituacionFinalModel())->porGrado((int) $p1['id']) as $g) {
            foreach (array_merge($g['en_riesgo'], $g['seguimiento']) as $al) {
                $out[(int) $al['matricula_id']] = [(int) $g['grado']['id'], (int) $al['competencias_plan'], $al['situacion']];
            }
        }
        return $out;
    };
    $resumenDe = static fn(): array => riesgo_resumen((new SituacionFinalModel())->porGrado((int) $p1['id']));

    $antes    = $planDe();
    $resAntes = $resumenDe();
    $pdo->beginTransaction();
    try {
        // (A) Aprobado SOLO en 1.o: crece el plan de 1.o y de nadie mas.
        $pdo->prepare("INSERT INTO talleres_aprobacion (area_id, anio_id, grado_id, aprobado, actualizado_por)
                       VALUES (?, ?, ?, 1, ?)")
            ->execute([$tallerRm['id'], $p1['anio_id'], $grado1, $usr]);
        $desp = $planDe();
        $crece1 = $creceOtro = 0;
        foreach ($desp as $mid => [$gid, $plan]) {
            if (!isset($antes[$mid])) { continue; }
            if ($plan > $antes[$mid][1]) { $gid === $grado1 ? $crece1++ : $creceOtro++; }
        }
        $ok($creceOtro === 0, 'aprobado solo en 1.o: ningun otro grado incorpora el taller', "$creceOtro de otros grados");
        $resA = $resumenDe();
        $ok($crece1 > 0 || $resA != $resAntes, 'aprobado en 1.o: el taller entra al calculo de 1.o',
            "$crece1 filas de 1.o con plan mayor");

        // (B) Retirado (aprobado = 0, la fila se conserva): vuelve a no contar.
        $pdo->prepare("UPDATE talleres_aprobacion SET aprobado = 0 WHERE area_id = ? AND anio_id = ? AND grado_id = ?")
            ->execute([$tallerRm['id'], $p1['anio_id'], $grado1]);
        $resB = $resumenDe();
        $ok($resB['total'] === $resAntes['total'] && $resB['cobertura']['parciales'] === $resAntes['cobertura']['parciales'],
            'aprobacion retirada (aprobado = 0): el resultado vuelve a ser el de sin aprobar',
            "{$resB['total']} vs {$resAntes['total']}");

        // (C) RETORNO DE GRADO: manda el grado de la matricula OFICIAL, jamas
        // el de la operativa (decision del usuario, 24/09/2026). El unico
        // retorno real es de primaria (sin talleres), asi que se REAPUNTA:
        // operativa = un alumno de 1.o sec con notas del taller; oficial = una
        // matricula de 2.o sec. Todo dentro de la transaccion.
        $ret = $pdo->query("SELECT id FROM retornos_grado ORDER BY id LIMIT 1")->fetchColumn();
        $g2  = $pdo->prepare("SELECT id FROM grados WHERE nivel_id = ? AND numero = 2");
        $g2->execute([$tallerRm['nivel_id']]);
        $grado2 = (int) $g2->fetchColumn();
        $op = $pdo->prepare("
            SELECT cal.matricula_id FROM calificaciones cal
            JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
                                        AND bc.periodo_id = cal.periodo_id
            JOIN competencias c ON c.id = cal.competencia_id
            JOIN matriculas m ON m.id = cal.matricula_id JOIN secciones s ON s.id = m.seccion_id
            WHERE c.area_id = ? AND cal.periodo_id = ? AND s.grado_id = ? LIMIT 1");
        $op->execute([$tallerRm['id'], $p1['id'], $grado1]);
        $opMid = (int) $op->fetchColumn();
        $of = $pdo->prepare("SELECT m.id FROM matriculas m JOIN secciones s ON s.id = m.seccion_id
                             WHERE s.grado_id = ? AND m.anio_id = ? LIMIT 1");
        $of->execute([$grado2, $p1['anio_id']]);
        $ofMid = (int) $of->fetchColumn();
        if ($ret && $opMid && $ofMid) {
            $pdo->prepare("UPDATE retornos_grado SET matricula_operativa_id = ?, matricula_oficial_id = ?, estado = 'activo' WHERE id = ?")
                ->execute([$opMid, $ofMid, $ret]);
            $planDeMid = static function (int $mid) use ($p1): ?int {
                foreach ((new SituacionFinalModel())->porGrado((int) $p1['id']) as $g) {
                    foreach (array_merge($g['en_riesgo'], $g['seguimiento']) as $al) {
                        if ((int) $al['matricula_id'] === $mid) { return (int) $al['competencias_plan']; }
                    }
                }
                return null;
            };
            $pdo->exec("DELETE FROM talleres_aprobacion WHERE area_id = " . (int) $tallerRm['id'] . " AND anio_id = " . (int) $p1['anio_id']);
            $sinAprob = $planDeMid($opMid);
            $pdo->prepare("INSERT INTO talleres_aprobacion (area_id, anio_id, grado_id, aprobado, actualizado_por) VALUES (?, ?, ?, 1, ?)")
                ->execute([$tallerRm['id'], $p1['anio_id'], $grado2, $usr]);
            $conOficial = $planDeMid($opMid);
            $pdo->prepare("UPDATE talleres_aprobacion SET grado_id = ? WHERE area_id = ? AND anio_id = ?")
                ->execute([$grado1, $tallerRm['id'], $p1['anio_id']]);
            $conOperativa = $planDeMid($opMid);
            // Solo cuenta si la fila existe en la lista (en riesgo o 1.o): si el
            // alumno sale PRO no aparece, y la prueba se declara no ejercida.
            if ($sinAprob !== null && $conOficial !== null && $conOperativa !== null) {
                $ok($conOficial > $sinAprob && $conOperativa === $sinAprob,
                    'retorno: el taller cuenta por el grado OFICIAL (2.o), no por el de la operativa (1.o)',
                    "plan sin aprobar $sinAprob, aprobado en oficial $conOficial, aprobado en operativa $conOperativa");
            } else {
                echo "  (retorno simulado: el alumno elegido no figura en la lista; se omite)
";
            }
        }
    } finally {
        $pdo->rollBack();
    }
    $quedan = (int) $pdo->query("SELECT COUNT(*) FROM talleres_aprobacion")->fetchColumn();
    $ok($resumenDe()['total'] === $resAntes['total'], "el rollback dejo todo como estaba ($quedan aprobaciones reales)");
} else {
    echo "  (sin Taller de Razonamiento Matematico o sin I Bimestre: se omite)\n";
}

// ── 8. Las dos preguntas de «riesgo» siguen siendo dos ───────────────────────
// Se miden juntas a propósito: comparten pantalla y rótulo, y la tentación
// recurrente de este repositorio es unificar dos reglas que solo se PARECEN.
// «Promedio en C» es el promedio general por debajo de NOTA_MIN_B, por nivel;
// el riesgo académico es la situación final por grado. No se unifican.
echo "\n== Los dos 'en riesgo' de /admin/cuadros ==\n";
foreach ($periodos as $p) {
    $pid   = (int) $p['id'];
    $porGr = (new SituacionFinalModel())->porGrado($pid);
    if (!$porGr) { continue; }

    $porPromedio = 0;
    foreach ((new AnioAcademicoModel())->getResumenBimestre($pid) as $nivel) {
        $porPromedio += (int) ($nivel['en_riesgo'] ?? 0);
    }
    $res = riesgo_resumen($porGr);

    printf("  %-14s promedio<%d por nivel: %-4d · situacion final (RR+PER): %-4d (PER %d)\n",
        $p['nombre_display'], NOTA_MIN_B, $porPromedio, $res['total'], $res['permanencia']);
}
$ok(true, 'las dos cifras se miden por separado y no se unifican',
    'universos distintos a proposito');

echo "\n";
printf("%s — %d fallo(s)\n", $fallos === 0 ? 'TODO OK' : 'HAY FALLOS', $fallos);
exit($fallos === 0 ? 0 : 1);
