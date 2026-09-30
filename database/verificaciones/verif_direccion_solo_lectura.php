<?php

/**
 * Verificación de la FASE 3 — retirada de la escritura a los directores.
 * Solo lectura sobre el código fuente.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/app/Helpers/helpers.php';

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};
$leer = fn(string $r): string => file_get_contents(ROOT_PATH . $r);

// ── 1. Cada metodo de escritura lleva su guarda ──────────────────
$plan = [
    '/app/Controllers/Director/AnioAcademicoController.php'    => ['create', 'store', 'activar', 'cerrar'],
    '/app/Controllers/Director/PeriodoController.php'          => ['editar', 'abrir', 'cerrar', 'reabrir'],
    '/app/Controllers/Director/CargaAcademicaController.php'   => ['create', 'store', 'edit', 'update', 'toggleEstado'],
    '/app/Controllers/Director/ReemplazoDocenteController.php' => ['form', 'reemplazar'],
    '/app/Controllers/Director/OrdenMeritoController.php'      => ['desempate', 'guardarDesempate'],
    '/app/Controllers/Admin/ControlOperativoController.php'    => ['aprobarBimestre', 'anularAprobacion'],
    '/app/Controllers/Director/BloqueoController.php'          => [
        'bloquear', 'limpiarBloqueosCierre', 'desbloquear',
        'cerrarTransversal', 'reabrirTransversal', 'liberarTransversalCompetencia',
        'bloquearConducta', 'cerrarConducta', 'reabrirConducta',
        'bloquearAsistencia', 'reabrirAsistencia',
    ],
];

echo "1) Guarda de escritura como PRIMERA sentencia de cada metodo\n";
$totalMetodos = 0;
foreach ($plan as $rel => $metodos) {
    $src = $leer($rel);
    $mal = [];
    foreach ($metodos as $m) {
        $totalMetodos++;
        $patron = '/public function ' . preg_quote($m, '/')
                . '\([^)]*\)(?:\s*:\s*\w+)?\s*\r?\n\s*\{\s*\r?\n\s*\$this->requireRole\(self::ROLES_ESCRIBEN\);/';
        if (!preg_match($patron, $src)) { $mal[] = $m; }
    }
    $chk(basename($rel) . ' — ' . count($metodos) . ' metodos guardados'
        . ($mal ? ' | SIN GUARDA: ' . implode(', ', $mal) : ''), empty($mal));
}
$chk("total de metodos de escritura guardados = 30", $totalMetodos === 30);

echo "\n2) ROLES_ESCRIBEN vale lo mismo en los 7 controladores\n";
foreach (array_keys($plan) as $rel) {
    preg_match('/private const ROLES_ESCRIBEN = (\[[^\]]*\]);/', $leer($rel), $m);
    $chk(basename($rel) . " = ['admin','registro_academico']",
        isset($m[1]) && eval("return {$m[1]};") === ['admin', 'registro_academico']);
}

echo "\n3) Constructores — quien ENTRA (lectura)\n";
$entra = [
    '/app/Controllers/Director/BloqueoController.php'          => "['admin', 'registro_academico', ...ROLES_DIRECCION]",
    '/app/Controllers/Director/ReemplazoDocenteController.php' => "['admin', 'registro_academico', ...ROLES_DIRECCION]",
    '/app/Controllers/Matricula/RetornoGradoController.php'    => "['admin', 'registro_academico']",
    '/app/Controllers/Admin/DirectorEbrController.php'         => "['admin', 'registro_academico']",
];
foreach ($entra as $rel => $esperado) {
    $chk(basename($rel) . " entra con $esperado", str_contains($leer($rel), $esperado));
}
$chk('RetornoGradoController ya NO deja entrar a ningun director',
    !str_contains($leer('/app/Controllers/Matricula/RetornoGradoController.php'), 'ROLES_DIRECCION')
    && !str_contains($leer('/app/Controllers/Matricula/RetornoGradoController.php'), "'director_ebr'"));

echo "\n4) INTEGRIDAD DE VISTAS — toda vista que usa \$puedeEscribir lo recibe\n";
// Es la comprobacion que atrapo el bug del Centro de Control: el flag se habia
// insertado en la rama de salida temprana y NO en la que pinta los botones.
$vistas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/resources/views'));
foreach ($it as $f) {
    if (!str_ends_with($f->getFilename(), '.php')) { continue; }
    if (!str_contains(file_get_contents($f->getPathname()), '$puedeEscribir')) { continue; }
    $rel = str_replace('\\', '/', $f->getPathname());
    $vistas[] = substr($rel, strpos($rel, 'resources/views/') + 16, -4);
}

$ctrls = '';
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/Controllers'));
foreach ($it2 as $f) {
    if (str_ends_with($f->getFilename(), '.php')) { $ctrls .= file_get_contents($f->getPathname()); }
}

foreach ($vistas as $v) {
    // Cada llamada a esa vista debe pasar 'puedeEscribir' en su array.
    preg_match_all('/view\(\x27' . preg_quote($v, '/') . '\x27,\s*\[(.{0,2500}?)\]\);/s', $ctrls, $mm);
    $llamadas = $mm[1] ?? [];
    $sinFlag  = array_filter($llamadas, fn($a) => !str_contains($a, "'puedeEscribir'"));
    $chk("$v — " . count($llamadas) . ' render(s), todos con el flag',
        count($llamadas) > 0 && empty($sinFlag));
}

echo "\n5) Sintaxis de todo lo tocado\n";
$sinErrores = true;
foreach (array_merge(array_keys($plan), array_keys($entra)) as $rel) {
    exec('php -l ' . escapeshellarg(ROOT_PATH . $rel) . ' 2>&1', $o, $rc);
    if ($rc !== 0) { $sinErrores = false; }
}
$chk('todos los controladores compilan', $sinErrores);

// ─────────────────────────────────────────────────────────────────────────
echo "\n6) NINGUNA VISTA OFRECE UN BOTON QUE ACABE EN 403\n";
// 🔴 EL ASERTO QUE FALTABA, Y POR QUE (02/09/2026).
//
// El bloque 4 pregunta «¿las vistas que USAN $puedeEscribir lo reciben?». Es una
// comprobacion de CONSISTENCIA sobre las vistas ya convertidas, y por eso NO
// puede ver una vista que nunca adopto el flag — que es exactamente el modo de
// fallo que se escapo: NUEVE botones en seis vistas que un director veia y cuyo
// destino le devolvia un 403.
//
// Este bloque hace la pregunta util: ¿alguna vista que un director PUEDE VER
// ofrece un control hacia una ruta que NO puede abrir? Se deriva todo del
// codigo —`routes/web.php` mapea ruta -> Controlador@metodo— en vez de mantener
// una lista de excepciones a mano, que caducaria con el siguiente boton.
//
// Tres precisiones que costaron un falso positivo cada una:
//   · Una vista solo alcanzable por escritores (un formulario de alta) NO
//     necesita gate interno: el director nunca llega a ella.
//   · Hay que quedarse con la ruta de MEJOR AJUSTE. Si no, `/admin/asistencia`
//     se lleva por delante a `/admin/asistencia/{id}/imprimir/{p}`, que es la de
//     verdad y si esta permitida.
//   · El gate se detecta con una PILA, no mirando atras N lineas: en
//     `matriculas/show.php` el bloque protegido tiene 180 lineas y varios `if`
//     anidados dentro, asi que el `endif;` mas cercano no dice nada.
$leerVista = static function (string $ruta): array {
    return preg_split('/\r?\n/', file_get_contents($ruta));
};
/** Trozos literales de una ruta: 'a/{id}/b' -> ['a/', '/b'] */
$trozosDe = static function (string $ruta): array {
    return array_values(array_filter(preg_split('/\{[^}]+\}/', trim($ruta, '/')),
        static fn(string $t): bool => $t !== ''));
};
/** ¿Aparecen todos los trozos, en orden, en la linea? Devuelve el peso o null. */
$casa = static function (string $linea, array $trozos): ?int {
    $pos = 0; $peso = 0;
    foreach ($trozos as $t) {
        $pos = strpos($linea, $t, $pos);
        if ($pos === false) { return null; }
        $pos += strlen($t); $peso += strlen($t);
    }
    return $peso;
};
/** Trocea un controlador en [metodo => cuerpo]. */
$metodosDe = static function (string $src): array {
    $out = [];
    if (preg_match_all('/public function (\w+)\(.*?(?=\n    (?:public|private|protected) function |\n\}\s*$)/s',
        $src, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) { $out[$m[1]] = $m[0]; }
    }
    return $out;
};
/** ¿El cuerpo tiene una guarda propia que EXCLUYE a los directores? */
$excluye = static function (string $cuerpo): bool {
    return preg_match('/\$this->requireRole\((.{0,120}?)\);/s', $cuerpo, $m) === 1
        && !str_contains($m[1], 'ROLES_DIRECCION');
};

$rutasSrc = file_get_contents(ROOT_PATH . '/routes/web.php');
preg_match_all("/\\\$router->(?:get|post)\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
    $rutasSrc, $mr, PREG_SET_ORDER);

$todas = []; $ctrlSrc = [];
foreach ($mr as [, $ruta, $destino]) {
    [$ctrl, $metodo] = array_pad(explode('@', $destino), 2, '');
    $a = ROOT_PATH . '/app/Controllers/' . str_replace('\\', '/', $ctrl) . '.php';
    if (!is_file($a)) { continue; }
    $ctrlSrc[$ctrl] ??= file_get_contents($a);
    $ms = $metodosDe($ctrlSrc[$ctrl]);
    if (!isset($ms[$metodo])) { continue; }
    $tr = $trozosDe($ruta);
    if (!$tr) { continue; }
    $todas[] = ['ruta' => $ruta, 'destino' => $destino,
                'trozos' => $tr, 'cerrada' => $excluye($ms[$metodo])];
}
$nCerradas = count(array_filter($todas, static fn(array $r): bool => $r['cerrada']));
$chk('se derivan las rutas cerradas a direccion desde routes/web.php'
    . " ({$nCerradas} de " . count($todas) . ')', $nCerradas > 20);

// Vistas alcanzables: el constructor deja entrar al director y el metodo que las
// renderiza no lo excluye.
$alcanzables = [];
$itC = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/Controllers'));
foreach ($itC as $fc) {
    if (!str_ends_with($fc->getFilename(), '.php')) { continue; }
    $ms = $metodosDe(file_get_contents($fc->getPathname()));
    if (!isset($ms['__construct']) || !str_contains($ms['__construct'], 'ROLES_DIRECCION')) { continue; }
    foreach ($ms as $nombre => $cuerpo) {
        if ($nombre === '__construct' || $excluye($cuerpo)) { continue; }
        if (preg_match_all("/\\\$this->view\('([^']+)'/", $cuerpo, $mv)) {
            foreach ($mv[1] as $v) { $alcanzables[$v] = true; }
        }
    }
}
$chk('se identifican las vistas que un director alcanza (' . count($alcanzables) . ')',
    count($alcanzables) > 15);

$sinGate = [];
$itV = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/resources/views'));
foreach ($itV as $fv) {
    if (!str_ends_with($fv->getFilename(), '.php')) { continue; }
    $p   = str_replace('\\', '/', $fv->getPathname());
    $rel = substr($p, strpos($p, 'resources/views/') + 16);
    if (!isset($alcanzables[substr($rel, 0, -4)])) { continue; }

    $L = $leerVista($fv->getPathname());
    $pila = [];
    // `if (` de sintaxis alternativa partido en varias lineas (30/09/2026): se
    // apila cuando aparece su `):`. Sin esto, su `endif` desapilaba el gate de
    // fuera (`matriculas/show.php`, condicion de «Marcar retirado»): falso positivo.
    $ifAbierto = null;
    foreach ($L as $i => $linea) {
        if ($ifAbierto !== null) {
            if (preg_match('/\)\s*:/', $linea)) { $pila[] = $ifAbierto; $ifAbierto = null; }
            elseif (str_contains($linea, '{')) { $ifAbierto = null; }
        }
        $dentroDeGate = in_array(true, $pila, true);

        if (str_contains($linea, 'url(')) {
            $mejor = null; $mejorPeso = -1;
            foreach ($todas as $r) {
                $peso = $casa($linea, $r['trozos']);
                if ($peso !== null && $peso > $mejorPeso) { $mejorPeso = $peso; $mejor = $r; }
            }
            if ($mejor && $mejor['cerrada'] && !$dentroDeGate) {
                $sinGate[] = $rel . ':' . ($i + 1) . ' -> ' . $mejor['ruta'];
            }
        }

        foreach (preg_split('/(?=\bif\s*\(|\bendif\s*;)/', $linea) as $trozo) {
            if (preg_match('/^if\s*\(.*\)\s*:/', $trozo)) {
                $pila[] = str_contains($trozo, 'if ($puede') || str_contains($trozo, 'if (has_role');
            } elseif (preg_match('/^if\s*\(/', $trozo) && !str_contains($trozo, '{')
                      && !str_contains($trozo, ')')) {
                $ifAbierto = str_contains($trozo, 'if ($puede') || str_contains($trozo, 'if (has_role');
            } elseif (str_starts_with($trozo, 'endif')) {
                array_pop($pila);
            }
        }
    }
}
$sinGate = array_unique($sinGate);
if ($sinGate) { echo '       ' . implode("\n       ", $sinGate) . "\n"; }
$chk('ninguna vista alcanzable por un director ofrece una ruta cerrada sin gate',
    $sinGate === []);

echo "\n", $ok ? "== FASE 3 EN VERDE ==\n" : "== HAY FALLOS ==\n";
exit($ok ? 0 : 1);
