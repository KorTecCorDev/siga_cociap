<?php

/**
 * Verificación de la COHERENCIA DE LOS BLOQUEOS (F2 del plan de bloqueos,
 * 09/10/2026). Uso: php database/verificaciones/verif_coherencia_bloqueos.php
 *
 * Mide los DOS lados de cada guarda —bloquear y dejar pasar—:
 *  1. Semáforo del auxiliar (`Auxiliar\PanelController::estado`): antes del
 *     último día de un bimestre por fechas dice «Bloqueo desde el dd/mm» (gris),
 *     no «Listo para bloquear»; los días sin tomar siguen teniendo prioridad.
 *  2. Transversales del tutor (`Docente\TutoriaController`, controlador real en
 *     subproceso): con el plazo de los docentes vencido se rechaza; vigente,
 *     pasa la guarda; un bimestre cerrado también se rechaza.
 *  3. Los forzados del panel (`Director\BloqueoController`, subproceso) se
 *     rechazan con el bimestre cerrado y pasan la guarda con el activo.
 *
 * ESCRIBE dentro de TRANSACCIONES con ROLLBACK (cada subproceso abre la suya y
 * nunca hace commit). Lleva el guard de secretos: NO en producción.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('VIEW_PATH', ROOT_PATH . '/resources/views');

if (is_file('/home/u761410128/siga_secrets/database.php')) {
    fwrite(STDERR, "ABORTA: detectado el archivo de secretos de PRODUCCION.\n");
    exit(2);
}

require ROOT_PATH . '/app/Helpers/helpers.php';
spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/', 'App\\Controllers\\' => '/app/Controllers/'] as $p => $b) {
        if (str_starts_with($c, $p)) {
            $f = ROOT_PATH . $b . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});
$_SERVER['HTTP_HOST'] = 'localhost';
$_SESSION = [];

// ── Modo SUBPROCESO ──────────────────────────────────────────────────────
// Uso interno: --tutor <usuario> <periodo> <plazo_vencido 0|1>
//              --forzar <usuario> <accion> <seccion> <periodo>
// `json()` y `redirect()` terminan con `exit`: la salida es el JSON, o el flash
// que deja la redirección (lo imprime el shutdown).
if (in_array($argv[1] ?? '', ['--tutor', '--forzar'], true)) {
    $pdo = Core\Database::connect();
    $pdo->beginTransaction();
    $_SESSION['_csrf_token'] = 'verif';
    if ($argv[1] === '--tutor') {
        [, , $usuarioId, $periodoId, $vencido] = $argv;
        $activo = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE periodos SET limite_notas = ? WHERE id = ?")
            ->execute([$vencido === '1' ? '2000-01-01 00:00:00' : '2999-12-31 00:00:00', $activo]);
        $_SESSION['auth_user'] = ['id' => (int) $usuarioId, 'rol_codigo' => 'docente', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
        $_POST = ['_csrf_token' => 'verif'];
        (new App\Controllers\Docente\TutoriaController())->cerrar($periodoId);
    } else {
        [, , $usuarioId, $accion, $seccionId, $periodoId] = $argv;
        register_shutdown_function(static function (): void {
            echo json_encode(['flash' => $_SESSION['_flash'] ?? []], JSON_UNESCAPED_UNICODE);
        });
        $_SESSION['auth_user'] = ['id' => (int) $usuarioId, 'rol_codigo' => 'registro_academico', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
        // carga/competencia distintas de 0: con 0, `bloquear()` corta antes por
        // «Datos incompletos» y la guarda del periodo nunca se ejercita.
        $_POST = ['_csrf_token' => 'verif', 'periodo_id' => $periodoId, 'carga_id' => '1', 'competencia_id' => '1'];
        $ctrl = new App\Controllers\Director\BloqueoController();
        $accion === 'bloquear' ? $ctrl->bloquear() : $ctrl->$accion($seccionId);
    }
    exit(0);
}

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo = Core\Database::connect();
$sub = static function (string $args): array {
    $r = json_decode((string) shell_exec('php ' . escapeshellarg(__FILE__) . ' ' . $args), true);
    return is_array($r) ? $r : ['success' => null, 'mensaje' => '(sin JSON)'];
};

// ── 1. Semáforo del auxiliar ─────────────────────────────────────────────
echo "1. Semáforo del auxiliar\n";
$estado = new ReflectionMethod(App\Controllers\Auxiliar\PanelController::class, 'estado');
$estado->setAccessible(true);
$e = static fn(...$a) => $estado->invoke(null, ...$a);
$r = $e(null, true, 20, 20, true, 0, '31/10');
$chk('todos confirmados ANTES del último día: gris «Bloqueo desde el 31/10»',
    $r['texto'] === 'Bloqueo desde el 31/10' && $r['badge'] === 'espera', $r['texto']);
$r = $e(null, true, 20, 20, true, 0, null);
$chk('todos confirmados y el bimestre terminado: «Listo para bloquear»', $r['texto'] === 'Listo para bloquear', $r['texto']);
$r = $e(null, true, 20, 20, true, 3, '31/10');
$chk('con días sin tomar, eso va primero (es lo que le toca hacer)', $r['texto'] === 'Días sin tomar: 3', $r['texto']);
$r = $e(null, true, 12, 20, true, 0, '31/10');
$chk('con confirmados pendientes, sigue el contador', $r['texto'] === 'Confirmados 12 de 20', $r['texto']);

// ── 2. Transversales del tutor ───────────────────────────────────────────
echo "2. Transversales del tutor bajo el plazo de los docentes\n";
$tutor = $pdo->query("
    SELECT s.tutor_id FROM secciones s
    JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
    WHERE s.tutor_id IS NOT NULL ORDER BY s.id LIMIT 1
")->fetchColumn();
$activo  = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
$cerrado = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'cerrado' ORDER BY numero DESC LIMIT 1")->fetchColumn();
if (!$tutor || !$activo) {
    $chk('hay un tutor del año activo y un bimestre activo', false);
} else {
    $r = $sub("--tutor $tutor $activo 1");
    $chk('plazo VENCIDO: el cierre del tutor se rechaza por el plazo',
        $r['success'] === false && $r['mensaje'] === 'El plazo para registrar en este bimestre venció.', (string) $r['mensaje']);
    $r = $sub("--tutor $tutor $activo 0");
    $chk('plazo vigente: pasa la guarda (lo detiene otra regla o cierra)',
        $r['mensaje'] !== '(sin JSON)' && !str_contains((string) $r['mensaje'], 'plazo')
        && !str_contains((string) $r['mensaje'], 'no está disponible'), (string) $r['mensaje']);
    if ($cerrado) {
        $r = $sub("--tutor $tutor $cerrado 0");
        $chk('bimestre CERRADO por la URL: se rechaza',
            $r['success'] === false && $r['mensaje'] === 'Este bimestre no está disponible para registrar.', (string) $r['mensaje']);
    }
}

// ── 3. Forzados del panel ────────────────────────────────────────────────
echo "3. Forzados del panel solo con el bimestre activo\n";
$ra      = (int) $pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo IN ('registro_academico','admin') ORDER BY u.id LIMIT 1")->fetchColumn();
$seccion = (int) $pdo->query("SELECT s.id FROM secciones s JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo' ORDER BY s.id LIMIT 1")->fetchColumn();
if (!$ra || !$seccion || !$cerrado) {
    $chk('hay un usuario RA/admin, una sección y un bimestre cerrado', false);
} else {
    foreach (['bloquear', 'cerrarTransversal', 'bloquearConducta', 'cerrarConducta', 'bloquearAsistencia'] as $accion) {
        $r = $sub("--forzar $ra $accion $seccion $cerrado");
        $chk("$accion con el bimestre CERRADO: se rechaza",
            ($r['flash']['error'] ?? '') === 'Este bimestre ya está cerrado.', json_encode($r['flash'] ?? $r, JSON_UNESCAPED_UNICODE));
        $r = $sub("--forzar $ra $accion $seccion $activo");
        $chk("$accion con el bimestre activo: pasa la guarda",
            isset($r['flash']) && $r['flash'] !== []
            && !in_array($r['flash']['error'] ?? '', ['Este bimestre ya está cerrado.', 'Datos incompletos.'], true),
            json_encode($r['flash'] ?? $r, JSON_UNESCAPED_UNICODE));
    }
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
