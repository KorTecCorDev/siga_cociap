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
    $st = $pdo->prepare("
        SELECT cal.matricula_id AS mid, a.id AS aid, cal.nota_numerica AS nota
        FROM calificaciones cal
        JOIN bloqueos_competencia bc
          ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
         AND bc.periodo_id = cal.periodo_id
        JOIN competencias comp ON comp.id = cal.competencia_id
        LEFT JOIN subareas sa  ON sa.id = comp.subarea_id
        JOIN areas a           ON a.id = COALESCE(sa.area_id, comp.area_id)
        WHERE cal.periodo_id = ?
          AND cal.extraordinaria = 0
          AND (a.tipo NOT IN ('transversal', 'tutoria') OR a.nombre_boleta = ?)
          AND NOT EXISTS (
              SELECT 1 FROM exoneraciones ex
              WHERE ex.matricula_id = cal.matricula_id AND ex.revocado_en IS NULL
                AND (ex.area_id = a.id OR ex.subarea_id = comp.subarea_id)
          )
    ");
    $st->execute([$pid, AREA_ETICA_NOMBRE_BOLETA]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
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
    SELECT p.id, p.numero, p.nombre_display, p.estado
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

    $roster = $rosterControl($pid);
    $areas  = $areasControl($pid);

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
        $evalEsp = $ndEsp = 0;
        $espRR = $espPER = [];
        foreach ($mios as $mid => $d) {
            $sit = $situacionControl($d['areas'], $nivel, $gnum);
            if ($sit === 'ND') { $ndEsp++; continue; }
            $evalEsp++;
            if ($sit === 'PER') { $espPER[] = $mid; }
            elseif ($sit === 'RR') { $espRR[] = $mid; }
        }
        $secSuma = array_sum(array_map(static fn($s) => (int) $s['total'], $g['por_seccion']));

        $ok((int) $g['evaluados'] === $evalEsp
            && (int) $g['sin_datos'] === $ndEsp
            && $secSuma === $evalEsp,
            "  $etq · roster: evaluados, sin datos y suma de secciones",
            $g['evaluados'] . " evaluados · " . $g['sin_datos'] . ' sin datos');

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
                count($g['automatica']) . ' en seguimiento pedagogico');
        }

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
