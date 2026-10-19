<?php

/**
 * Verificación de INTEGRIDAD ESTÁTICA del código (19/10/2026).
 * Uso: php database/verificaciones/verif_integridad_rutas.php
 *
 * Nació del 500 de `/admin/cuadros` del 19/10/2026: dos vistas incluían
 * `admin/cuadros/acompanamiento/_sin-casos.php`, que nunca existió (la carpeta
 * es `riesgo/`; lo que se renombró el 24/09 fue la URL). El `require` estaba en
 * una rama que solo corre cuando NADIE está en riesgo, así que vivió tres
 * semanas sin ejecutarse hasta que se abrió el IV Bimestre. Un error así no
 * depende de los datos: se detecta leyendo el código, antes del deploy.
 *
 *  1. Todo `require`/`include` con ruta LITERAL (`VIEW_PATH`, `ROOT_PATH`,
 *     `APP_PATH`, `CORE_PATH`, `CONFIG_PATH` o `__DIR__` + '...') apunta a un
 *     archivo que existe. Los que arman la ruta con variables se listan aparte
 *     (no se pueden comprobar sin ejecutarlos).
 *  2. Toda vista pedida con nombre literal (`$this->view`, `View::render`,
 *     `View::make`, `View::partial`) y todo layout de `View::setLayout` existen.
 *  3. Toda ruta de `routes/web.php` apunta a un controlador y a un método
 *     PÚBLICO que existen (por reflexión: carga la clase, no la instancia).
 *  4. `php -l` sin errores en todo el código PHP de la aplicación.
 *
 * SOLO LECTURA: no abre la BD ni escribe nada. Se puede correr en cualquier
 * entorno. Lo ejecuta el hook `pre-push` (`.githooks/pre-push`).
 * Sale con 0 si todo está bien y con 1 si algo falla.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH', ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('VIEW_PATH', ROOT_PATH . '/resources/views');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/', 'App\\Controllers\\' => '/app/Controllers/',
              'App\\Middleware\\' => '/app/Middleware/', 'App\\Siagie\\' => '/app/Siagie/'] as $p => $b) {
        if (str_starts_with($c, $p)) {
            $f = ROOT_PATH . $b . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});
require CONFIG_PATH . '/app.php';
require APP_PATH . '/Helpers/helpers.php';

$ok  = true;
$chk = function (string $t, bool $c, array $detalle = []) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    foreach ($detalle as $d) {
        echo "        - $d\n";
    }
    $ok = $ok && $c;
};

/** Ruta relativa al repo, con barras normales, para los mensajes. */
$rel = static fn(string $f): string => ltrim(str_replace([ROOT_PATH, '\\'], ['', '/'], $f), '/');

/** Todos los .php de las carpetas de la aplicación. */
$archivos = [];
foreach (['app', 'core', 'resources', 'routes', 'config', 'public', 'database'] as $dir) {
    if (!is_dir(ROOT_PATH . '/' . $dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $archivos[] = $f->getPathname();
        }
    }
}
sort($archivos);
// El código que corre la aplicación; `database/` solo entra al lint.
$codigoApp = array_values(array_filter(
    $archivos,
    static fn(string $f): bool => !str_starts_with($rel($f), 'database/')
));

$numLinea = static fn(string $src, int $offset): int => substr_count($src, "\n", 0, $offset) + 1;

/**
 * Fuente SIN comentarios: un `require` o una ruta citados en un docblock no
 * son código. Cada comentario se cambia por sus saltos de línea, para que los
 * números de línea de los mensajes sigan siendo los del archivo.
 */
$fuente = static function (string $f): string {
    $out = '';
    foreach (token_get_all(file_get_contents($f)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n"));
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    return $out;
};

// ── 1. require / include con ruta literal ──────────────────────
echo "1. require / include\n";
$constantes = ['VIEW_PATH' => VIEW_PATH, 'ROOT_PATH' => ROOT_PATH, 'APP_PATH' => APP_PATH,
               'CORE_PATH' => CORE_PATH, 'CONFIG_PATH' => CONFIG_PATH];
$rotos = [];
$dinamicos = [];
$total = 0;
foreach ($codigoApp as $f) {
    $src = $fuente($f);
    preg_match_all(
        '/\b(?:require|include)(?:_once)?\s*\(?\s*(VIEW_PATH|ROOT_PATH|APP_PATH|CORE_PATH|CONFIG_PATH|__DIR__)\s*\.\s*([^;]+?)\s*\)?\s*;/',
        $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($m as $hit) {
        $base  = $hit[1][0] === '__DIR__' ? dirname($f) : $constantes[$hit[1][0]];
        $resto = trim($hit[2][0]);
        $donde = $rel($f) . ':' . $numLinea($src, $hit[0][1]);
        if (!preg_match("/^'([^'\\\\]+)'$/", $resto, $lit)) {
            $dinamicos[] = "$donde  ({$hit[1][0]} . $resto)";
            continue;
        }
        $total++;
        if (!is_file($base . $lit[1])) {
            $rotos[] = "$donde  →  {$hit[1][0]} . '{$lit[1]}'";
        }
    }
}
$chk("$total rutas literales, todas existen", !$rotos, $rotos);
if ($dinamicos) {
    echo "  [INFO] " . count($dinamicos) . " con ruta armada en ejecución (no se comprueban):\n";
    foreach ($dinamicos as $d) {
        echo "        - $d\n";
    }
}

// ── 2. Vistas y layouts pedidos por nombre ─────────────────────
echo "2. Vistas y layouts\n";
$rotas = [];
$totalVistas = 0;
foreach ($codigoApp as $f) {
    $src = $fuente($f);
    preg_match_all(
        "/(?:->view|View::(?:render|make|partial))\\(\\s*'([^']+)'/",
        $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($m as $hit) {
        $totalVistas++;
        $archivo = VIEW_PATH . '/' . str_replace('.', '/', $hit[1][0]) . '.php';
        if (!is_file($archivo)) {
            $rotas[] = $rel($f) . ':' . $numLinea($src, $hit[0][1]) . "  →  vista '{$hit[1][0]}'";
        }
    }
    preg_match_all("/View::setLayout\\(\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $hit) {
        $totalVistas++;
        if (!is_file(VIEW_PATH . '/layouts/' . $hit[1][0] . '.php')) {
            $rotas[] = $rel($f) . ':' . $numLinea($src, $hit[0][1]) . "  →  layout '{$hit[1][0]}'";
        }
    }
}
$chk("$totalVistas vistas y layouts con nombre literal, todos existen", !$rotas, $rotas);

// ── 3. Rutas → controlador@método ──────────────────────────────
echo "3. Rutas de routes/web.php\n";
$web = $fuente(ROOT_PATH . '/routes/web.php');
preg_match_all(
    "/\\\$router->(\\w+)\\(\\s*'([^']*)'\\s*,\\s*'([^'@]+)@([^']+)'/",
    $web, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
);
$malas = [];
foreach ($m as $hit) {
    $donde  = 'routes/web.php:' . $numLinea($web, $hit[0][1]) . "  {$hit[2][0]}";
    $clase  = 'App\\Controllers\\' . $hit[3][0];
    $metodo = $hit[4][0];
    if (!class_exists($clase)) {
        $malas[] = "$donde  →  no existe la clase $clase";
        continue;
    }
    $rc = new ReflectionClass($clase);
    if (!$rc->hasMethod($metodo) || !$rc->getMethod($metodo)->isPublic()) {
        $malas[] = "$donde  →  $clase no tiene el método público $metodo()";
    }
}
// Toda línea que registra una ruta tiene que haber entrado al barrido: si una
// cambia de forma (comillas dobles, closure…) se avisa en vez de saltarla.
$registradas = preg_match_all('/\$router->\w+\(/', $web);
$chk(count($m) . ' rutas, todas apuntan a un método público existente', !$malas, $malas);
$chk("el barrido leyó las $registradas rutas registradas", $registradas === count($m),
    $registradas === count($m) ? [] : ['hay rutas con un formato que el verificador no reconoce']);

// ── 4. php -l ──────────────────────────────────────────────────
echo "4. Sintaxis (php -l)\n";
$errores = [];
$cola = $archivos;
$activos = [];
$paralelo = 8;
while ($cola || $activos) {
    while ($cola && count($activos) < $paralelo) {
        $f = array_shift($cola);
        $p = proc_open([PHP_BINARY, '-l', $f], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $activos[] = [$p, $pipes, $f];
    }
    foreach ($activos as $i => [$p, $pipes, $f]) {
        if (proc_get_status($p)['running']) {
            continue;
        }
        $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($p);
        if ($codigo !== 0 && !str_contains($salida, 'No syntax errors')) {
            $errores[] = $rel($f) . ': ' . trim(preg_replace('/\s+/', ' ', $salida));
        }
        unset($activos[$i]);
    }
    usleep(10000);
}
$chk(count($archivos) . ' archivos PHP sin errores de sintaxis', !$errores, $errores);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
