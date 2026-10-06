<?php

/**
 * Verificación — BORRADORES DE FORMULARIOS (migración 074, 06/10/2026).
 * Uso: php database/verificaciones/verif_borradores.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila.
 *
 * LA REGLA QUE SE VERIFICA: un borrador NUNCA toca tablas oficiales, es de UN
 * usuario, y solo se elimina tras un guardado oficial exitoso.
 *
 * QUÉ COMPRUEBA
 *   1. La clave: solo tipos de la lista blanca y enteros positivos (S2).
 *   2. Contexto: matrícula existente y periodo de SU año, en las dos ramas.
 *   3. Guardar / leer / actualizar; revisión que avanza.
 *   4. Conflicto (S8): una revisión vieja NO pisa; responde la vigente.
 *   5. Aislamiento (S1): el borrador de otro usuario no se lee; deOtros()
 *      devuelve nombre y fecha, NUNCA el contenido (S11).
 *   6. Ninguna tabla oficial cambia con borradores (la comprobación central).
 *   7. FK con CASCADE a usuario y matrícula (S10, datos de menores).
 *   8. El POST oficial elimina el borrador DESPUÉS del commit; el endpoint
 *      valida CSRF, rol, throttle y toma el usuario de la sesión.
 *   9. ROLLBACK: la tabla vuelve a su estado inicial.
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
require_once APP_PATH . '/Helpers/helpers.php';

use App\Models\BorradorModel;

$pdo = Core\Database::connect();
$bm  = new BorradorModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};

// ── Sujetos: una matrícula, un periodo de su año, otro de OTRO año, dos usuarios
$m = $pdo->query("
    SELECT m.id AS matricula, p.id AS periodo
    FROM matriculas m INNER JOIN periodos p ON p.anio_id = m.anio_id
    ORDER BY m.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$periodoAjeno = (int) $pdo->query("
    SELECT p.id FROM periodos p
    WHERE p.anio_id <> (SELECT anio_id FROM matriculas WHERE id = {$m['matricula']})
    LIMIT 1
")->fetchColumn();
$usuarios = $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
[$u1, $u2] = array_map('intval', $usuarios);
$mid = (int) $m['matricula'];
$pid = (int) $m['periodo'];

echo "\n=== SUJETOS === matrícula {$mid}, periodo {$pid}, usuarios {$u1} y {$u2}\n";

$oficiales = [];
foreach (['calificaciones', 'criterios', 'bloqueos_competencia', 'notas_externas',
          'notificaciones', 'conducta_respuestas', 'inasistencias', 'rectificaciones_calificacion'] as $t) {
    if ($contar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$t]) === 1) {
        $oficiales[$t] = $contar("SELECT COUNT(*) FROM `{$t}`");
    }
}
$antes = $contar("SELECT COUNT(*) FROM borradores_formulario");

$pdo->beginTransaction();
try {
    echo "\n=== 1. CLAVE — lista blanca y enteros (S2) ===\n";
    $ok(BorradorModel::clave('rect_lote', ['matricula' => $mid, 'periodo' => $pid]) === "m{$mid}-p{$pid}", 'clave bien formada');
    $ok(BorradorModel::clave('inventado', ['matricula' => 1, 'periodo' => 1]) === null, 'tipo fuera de la lista → null');
    $ok(BorradorModel::clave('rect_lote', ['matricula' => '5 OR 1=1', 'periodo' => 1]) === null, 'id no numérico → null');
    $ok(BorradorModel::clave('rect_lote', ['matricula' => -3, 'periodo' => 1]) === null, 'id negativo → null');
    $ok(BorradorModel::clave('rect_lote', ['matricula' => 5]) === null, 'falta un id → null');
    $ok(BorradorModel::clave('rect_lote', ['matricula' => ['x'], 'periodo' => 1]) === null, 'arreglo → null');

    echo "\n=== 2. CONTEXTO — las dos ramas ===\n";
    $ok($bm->contextoValido('rect_lote', ['matricula' => $mid, 'periodo' => $pid]), 'matrícula + periodo de su año → válido');
    $ok(!$bm->contextoValido('rect_lote', ['matricula' => 99999999, 'periodo' => $pid]), 'matrícula inexistente → inválido');
    if ($periodoAjeno > 0) {
        $ok(!$bm->contextoValido('rect_lote', ['matricula' => $mid, 'periodo' => $periodoAjeno]), 'periodo de OTRO año → inválido');
    }

    echo "\n=== 3. GUARDAR / LEER / ACTUALIZAR ===\n";
    $clave = BorradorModel::clave('rect_lote', ['matricula' => $mid, 'periodo' => $pid]);
    $xss   = '</script><img src=x onerror=alert(1)>';
    $r1 = $bm->guardar($u1, $mid, 'rect_lote', $clave, json_encode(['motivo' => 'uno', 'conclusion' => ['c1' => $xss]]), 0);
    $ok($r1 === ['ok' => true, 'revision' => 1], 'alta nueva → revisión 1');
    $leido = $bm->obtener($u1, 'rect_lote', $clave);
    $ok(($leido['datos']['motivo'] ?? null) === 'uno', 'se lee lo guardado');
    $ok(($leido['datos']['conclusion']['c1'] ?? null) === $xss, 'el texto se guarda tal cual (se escapa al PINTAR, no al guardar)');
    $r2 = $bm->guardar($u1, $mid, 'rect_lote', $clave, json_encode(['motivo' => 'dos']), 1);
    $ok($r2 === ['ok' => true, 'revision' => 2], 'actualizar con la revisión vigente → 2');

    echo "\n=== 4. CONFLICTO (S8) ===\n";
    $r3 = $bm->guardar($u1, $mid, 'rect_lote', $clave, json_encode(['motivo' => 'pestaña vieja']), 1);
    $ok($r3 === ['ok' => false, 'revision' => 2], 'revisión vieja NO pisa y devuelve la vigente');
    $ok(($bm->obtener($u1, 'rect_lote', $clave)['datos']['motivo'] ?? null) === 'dos', 'el contenido vigente sigue intacto');
    $r4 = $bm->guardar($u1, $mid, 'rect_lote', $clave, json_encode(['motivo' => 'x']), 0);
    $ok($r4['ok'] === false, 'revisión 0 con un borrador existente → conflicto (no duplica ni pisa)');

    echo "\n=== 5. AISLAMIENTO (S1, S11) ===\n";
    $ok($bm->obtener($u2, 'rect_lote', $clave) === null, 'otro usuario NO lee el borrador ajeno');
    $otros = $bm->deOtros('rect_lote', $clave, $u2);
    $ok(count($otros) === 1, 'deOtros() avisa que existe uno');
    $ok(array_keys($otros[0]) === ['nombre', 'actualizado_en'], 'deOtros() trae solo nombre y fecha, nunca datos');
    $ok($bm->deOtros('rect_lote', $clave, $u1) === [], 'el dueño no se ve a sí mismo como «otro»');
    $bm->guardar($u2, $mid, 'rect_lote', $clave, json_encode(['motivo' => 'de u2']), 0);
    $ok(($bm->obtener($u1, 'rect_lote', $clave)['datos']['motivo'] ?? null) === 'dos', 'el borrador de u2 no altera el de u1');

    echo "\n=== 6. NINGUNA TABLA OFICIAL CAMBIA ===\n";
    foreach ($oficiales as $t => $n) {
        $ok($contar("SELECT COUNT(*) FROM `{$t}`") === $n, "{$t}: {$n} filas, sin cambios");
    }

    echo "\n=== 7. FK CON CASCADE (S10) ===\n";
    $reglas = $pdo->query("
        SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        JOIN information_schema.KEY_COLUMN_USAGE kcu
          ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = 'borradores_formulario'
    ")->fetchAll(PDO::FETCH_ASSOC);
    $porTabla = array_column($reglas, 'DELETE_RULE', 'REFERENCED_TABLE_NAME');
    $ok(($porTabla['usuarios'] ?? '') === 'CASCADE', 'borrar un usuario arrastra sus borradores');
    $ok(($porTabla['matriculas'] ?? '') === 'CASCADE', 'borrar una matrícula arrastra sus borradores');

    echo "\n=== 8. ELIMINAR solo tras el guardado oficial + guardas del endpoint ===\n";
    $bm->eliminar($u1, 'rect_lote', $clave);
    $ok($bm->obtener($u1, 'rect_lote', $clave) === null, 'eliminar() borra el del dueño');
    $ok($bm->obtener($u2, 'rect_lote', $clave) !== null, '…y no toca el de otro usuario');

    $ctrl = file_get_contents(APP_PATH . '/Controllers/Rectificacion/RectificacionController.php');
    $ini  = strpos($ctrl, 'public function guardarExtraordinariaLote');
    $fin  = strpos($ctrl, 'public function', $ini + 10);
    $post = substr($ctrl, $ini, $fin - $ini);
    $posCommit   = strpos($post, '$this->model->commit()');
    $posEliminar = strpos($post, "->eliminar(\$usuarioId, 'rect_lote'");
    $ok($posCommit !== false && $posEliminar !== false && $posEliminar > $posCommit,
        'el POST del lote elimina el borrador DESPUÉS del commit');
    $ok(substr_count($post, '->eliminar(') === 1, 'y en ningún otro punto del POST (no en los rechazos)');

    $ep = file_get_contents(APP_PATH . '/Controllers/BorradorController.php');
    $ok(str_contains($ep, '$this->validateCsrf()'), 'el endpoint valida CSRF (S4)');
    $ok(str_contains($ep, "Session::hasRole(self::ROLES[\$tipo])"), 'el endpoint exige el rol del tipo');
    $ok(str_contains($ep, "Throttle::hit('borrador:'"), 'el endpoint limita la frecuencia (S6)');
    $ok(str_contains($ep, "(int) Session::user()['id']") && !str_contains($ep, "input('usuario"), 'el dueño sale de la sesión, nunca del request (S1)');
    $ok(!str_contains($ep, "input('clave'"), 'el cliente no envía la clave (S2)');
    $ok(str_contains($ep, 'BorradorModel::MAX_BYTES'), 'el endpoint aplica el tope de tamaño (S6)');

    $vista = file_get_contents(ROOT_PATH . '/resources/views/rectificaciones/extraordinaria-lote.php');
    $ok(!preg_match('/<\?=\s*\$old\[/', $vista) && !str_contains($vista, '<?= $borrador'), 'la vista no imprime datos del borrador sin e() (S3)');
} catch (Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $fallos++;
} finally {
    $pdo->rollBack();
}

echo "\n=== 9. ROLLBACK ===\n";
$ok($contar("SELECT COUNT(*) FROM borradores_formulario") === $antes, "borradores_formulario vuelve a {$antes}");

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — los borradores no tocan lo oficial y son de un solo usuario.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
