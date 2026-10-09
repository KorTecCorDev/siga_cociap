<?php

/**
 * Verificación del GUARD DEL CIERRE para conducta y asistencia (F3 del plan de
 * bloqueos, 09/10/2026). Uso: php database/verificaciones/verif_guard_cierre_registros.php
 *
 * Mide los DOS lados de la guarda —bloquear y dejar pasar—:
 *  1. `AnioAcademicoModel::registrosSinBloquear`: con todo bloqueado no devuelve
 *     nada; sin la asistencia de una sección, sin la etapa 1 o sin la etapa 2 de
 *     conducta de otra, nombra exactamente esas secciones en su registro. El
 *     universo son TODAS las secciones del año.
 *  2. Los bimestres ya cerrados cumplen la regla (no se valida hacia atrás, pero
 *     sirve de referencia: si no la cumplen, avisa).
 *  3. `PeriodoController::cerrar` real (subproceso): con un registro sin bloquear
 *     no cierra y el bimestre sigue activo.
 *
 * ESCRIBE dentro de TRANSACCIONES con ROLLBACK. Guard de secretos: NO en producción.
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

use App\Models\AnioAcademicoModel;

// ── Modo SUBPROCESO: `cerrar()` real; nunca hace commit ──────────────────
// Uso interno: --cerrar <usuario> <periodo>
if (($argv[1] ?? '') === '--cerrar') {
    [, , $usuarioId, $periodoId] = $argv;
    $pdo = Core\Database::connect();
    $pdo->beginTransaction();
    register_shutdown_function(static function () use ($pdo, $periodoId): void {
        echo json_encode([
            'flash'  => $_SESSION['_flash'] ?? [],
            'estado' => $pdo->query("SELECT estado FROM periodos WHERE id = " . (int) $periodoId)->fetchColumn(),
        ], JSON_UNESCAPED_UNICODE);
    });
    $_SESSION['_csrf_token'] = 'verif';
    $_SESSION['auth_user']   = ['id' => (int) $usuarioId, 'rol_codigo' => 'admin', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
    $_POST = ['_csrf_token' => 'verif'];
    (new App\Controllers\Director\PeriodoController())->cerrar($periodoId);
    exit(0);
}

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo  = Core\Database::connect();
$anio = new AnioAcademicoModel();

$activo = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
$admin  = (int) $pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1")->fetchColumn();
if (!$activo || !$admin) {
    $chk('hay un bimestre activo y un admin', false);
    exit(1);
}
$secciones = $pdo->query("
    SELECT s.id, CONCAT(n.nombre, ' ', g.nombre_display, ' ', s.nombre) AS etiqueta
    FROM secciones s JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
    WHERE s.anio_id = (SELECT anio_id FROM periodos WHERE id = $activo)
    ORDER BY n.id, g.numero, s.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// ── 1. Las dos ramas en el bimestre activo ───────────────────────────────
echo "1. registrosSinBloquear — las dos ramas (bimestre activo, en transacción)\n";
$pdo->beginTransaction();
try {
    // Escenario: TODO bloqueado (las dos etapas de conducta y la asistencia).
    $pdo->exec("UPDATE cierres_conducta SET anulado_en = NOW() WHERE periodo_id = $activo AND anulado_en IS NULL");
    $pdo->exec("UPDATE cierres_asistencia SET anulado_en = NOW() WHERE periodo_id = $activo AND anulado_en IS NULL");
    $insC = $pdo->prepare("INSERT INTO cierres_conducta (seccion_id, periodo_id, ra_bloqueado_por, tutor_cerrado_en, tutor_cerrado_por) VALUES (?, ?, ?, NOW(), ?)");
    $insA = $pdo->prepare("INSERT INTO cierres_asistencia (seccion_id, periodo_id, ra_bloqueado_por) VALUES (?, ?, ?)");
    foreach ($secciones as $s) {
        $insC->execute([$s['id'], $activo, $admin, $admin]);
        $insA->execute([$s['id'], $activo, $admin]);
    }
    $r = $anio->registrosSinBloquear($activo);
    $chk('con todo bloqueado (' . count($secciones) . ' secciones): deja pasar', $r === [], json_encode($r, JSON_UNESCAPED_UNICODE));

    // Quita la asistencia de la 1.ª sección, la etapa 1 de la 2.ª y la 2 de la 3.ª.
    [$s1, $s2, $s3] = [$secciones[0], $secciones[1], $secciones[2]];
    $pdo->exec("UPDATE cierres_asistencia SET anulado_en = NOW() WHERE periodo_id = $activo AND seccion_id = {$s1['id']} AND anulado_en IS NULL");
    $pdo->exec("UPDATE cierres_conducta SET anulado_en = NOW() WHERE periodo_id = $activo AND seccion_id = {$s2['id']} AND anulado_en IS NULL");
    $pdo->exec("UPDATE cierres_conducta SET tutor_cerrado_en = NULL WHERE periodo_id = $activo AND seccion_id = {$s3['id']} AND anulado_en IS NULL");
    $r = $anio->registrosSinBloquear($activo);
    $chk('sin la asistencia de una sección: la nombra', ($r['asistencia'] ?? []) === [$s1['etiqueta']], json_encode($r['asistencia'] ?? [], JSON_UNESCAPED_UNICODE));
    $chk('sin la etapa 1 de conducta: la nombra en «conducta» (no en «tutor»)',
        ($r['conducta'] ?? []) === [$s2['etiqueta']], json_encode($r['conducta'] ?? [], JSON_UNESCAPED_UNICODE));
    $chk('sin la etapa 2: la nombra en «conducta_tutor»', ($r['conducta_tutor'] ?? []) === [$s3['etiqueta']], json_encode($r['conducta_tutor'] ?? [], JSON_UNESCAPED_UNICODE));
    $txt = AnioAcademicoModel::textoRegistrosSinBloquear($r);
    $chk('el mensaje nombra registro y secciones', str_contains($txt, 'Asistencia sin bloquear (1): ' . $s1['etiqueta'])
        && str_contains($txt, 'Conducta sin cerrar por el tutor (1): ' . $s3['etiqueta']), $txt);
} finally {
    $pdo->rollBack();
}

// ── 2. Bimestres cerrados (referencia) ───────────────────────────────────
echo "2. Bimestres cerrados (referencia, no se validan hacia atrás)\n";
foreach ($pdo->query("SELECT id, nombre_display FROM periodos WHERE estado = 'cerrado' ORDER BY numero")->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $r = $anio->registrosSinBloquear((int) $p['id']);
    $chk("{$p['nombre_display']}: cumple la regla", $r === [], AnioAcademicoModel::textoRegistrosSinBloquear($r));
}

// ── 3. cerrar() real ─────────────────────────────────────────────────────
echo "3. PeriodoController::cerrar (subproceso)\n";
$faltanHoy = $anio->registrosSinBloquear($activo);
$r = json_decode((string) shell_exec('php ' . escapeshellarg(__FILE__) . " --cerrar $admin $activo"), true);
$error = (string) ($r['flash']['error'] ?? '');
if ($faltanHoy === []) {
    echo "  [SKIP] en esta BD el bimestre activo ya tiene todo bloqueado\n";
} elseif (str_contains($error, 'empates') || str_contains($error, 'evaluación incompleta')) {
    echo "  [SKIP] un guard anterior (empates / evaluación incompleta) detiene el cierre antes: $error\n";
} else {
    $chk('con registros sin bloquear: no cierra y lo explica',
        str_contains($error, 'faltan registros por bloquear'), $error);
    $chk('el bimestre sigue activo', ($r['estado'] ?? '') === 'activo');
}
// Pase o no el subproceso, el ORDEN queda protegido: el guard va antes de la
// transacción (si fuera dentro, el cierre forzado ya habría escrito).
$src    = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Director/PeriodoController.php');
$cuerpo = substr($src, (int) strpos($src, 'public function cerrar('));
$pGuard = strpos($cuerpo, 'registrosSinBloquear(');
$pTx    = strpos($cuerpo, 'beginTransaction(');
$chk('cerrar() llama al guard ANTES de abrir la transacción', $pGuard !== false && $pTx !== false && $pGuard < $pTx);
$chk('rollback: el bimestre activo sigue activo en la base',
    $pdo->query("SELECT estado FROM periodos WHERE id = $activo")->fetchColumn() === 'activo');

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
