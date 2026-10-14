<?php

/**
 * Verificación — RATIFICACIÓN DE MATRÍCULA (migración 080, 14/10/2026).
 * Uso: php database/verificaciones/verif_ratificaciones.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila (regla del proyecto). Nunca hace DELETE.
 *
 * LAS REGLAS (docs/modulos/ratificaciones.md)
 *  - Universo: matrículas del año en cualquier estado, sin trasladados, sin
 *    retirados, sin la operativa de un retorno y sin 5.º de secundaria.
 *  - Sin fila = pendiente. `no_ratificado` exige motivo (modelo y CHECK);
 *    `ratificado` no lleva motivo ni detalle.
 *  - El motivo es del MISMO año y vigente (salvo el que ya tenía).
 *  - Cambiar la decisión con documento emitido exige confirmación y ANULA la
 *    emisión en la misma operación.
 *  - Solo lectura de Dirección: cada método de escritura del controlador
 *    empieza con `requireRole(RatificacionModel::ROLES_REGISTRAN)`.
 *
 * QUÉ COMPRUEBA
 *   0. Las cinco tablas de la 080 existen.
 *   1. Universo (las dos ramas de cada exclusión) y coherencia de contadores.
 *   2. CHECK de la BD: no_ratificado sin motivo, ratificado con motivo.
 *   3. guardarDecision(): validaciones, «sin cambios», anulación con confirmación.
 *   4. Guardas del controlador (estático, sin BD).
 *   5. ROLLBACK: todo vuelve a su valor inicial.
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

use App\Models\RatificacionModel;
use App\Models\RatificacionMotivoModel;

$pdo = Core\Database::connect();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$uno = function (string $sql, array $p = []) use ($pdo): mixed {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetchColumn();
};
/** Mensaje de la DomainException, o null si no lanzó. */
$rechaza = function (callable $fn): ?string {
    try { $fn(); return null; } catch (\DomainException $e) { return $e->getMessage(); }
};
/** true si la sentencia la rechaza la BD (CHECK / UNIQUE). */
$bdRechaza = function (string $sql, array $p = []) use ($pdo): bool {
    try { $pdo->prepare($sql)->execute($p); return false; } catch (\PDOException $e) { return true; }
};

// ── 0. Tablas ────────────────────────────────────────────────────
echo "\n=== 0. Tablas de la migración 080 ===\n";
$tablas = ['ratificacion_plantillas', 'ratificacion_bloques', 'ratificacion_motivos',
           'ratificaciones', 'ratificacion_emisiones'];
foreach ($tablas as $t) {
    $existe = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    $ok($existe, "existe {$t}");
    if (!$existe) {
        echo "\nAplica primero database/migrations/080_ratificaciones.sql\n";
        exit(1);
    }
}

$antes = [];
foreach ($tablas as $t) { $antes[$t] = (int) $uno("SELECT COUNT(*) FROM {$t}"); }
$antesEstados = $pdo->query("SELECT id, estado, tipo FROM matriculas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$rat = new RatificacionModel();
$mot = new RatificacionMotivoModel();
$uid = (int) $uno("SELECT id FROM usuarios ORDER BY id LIMIT 1");

/** Una matrícula que cumple la condición SQL (sobre m, g, n), o 0. */
$sujeto = function (string $cond) use ($uno): int {
    return (int) $uno("
        SELECT m.id FROM matriculas m
        JOIN secciones s ON s.id = m.seccion_id
        JOIN grados g ON g.id = s.grado_id
        JOIN niveles n ON n.id = g.nivel_id
        WHERE {$cond} ORDER BY m.id LIMIT 1");
};

$pdo->beginTransaction();
try {
    // ── 1. Universo ──────────────────────────────────────────────
    echo "\n=== 1. Universo ===\n";
    $enUniverso = fn(int $id): bool => $rat->matriculaDelUniverso($id) !== null;

    $normal = $sujeto("m.tipo IN ('continuador','nuevo') AND m.estado = 'aprobada'
        AND NOT (n.codigo = 'sec' AND g.numero = 5)
        AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
        AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado)");
    $ok($normal > 0 && $enUniverso($normal), "matrícula aprobada común ({$normal}): DENTRO");

    foreach (['trasladado', 'retirado'] as $tipo) {
        $id = $sujeto("m.tipo = '{$tipo}' AND NOT (n.codigo = 'sec' AND g.numero = 5)");
        if ($id > 0) {
            $ok(!$enUniverso($id), "{$tipo} ({$id}): FUERA");
        } else {
            echo "  [--]   sin {$tipo} en la BD para probar\n";
        }
    }

    $quinto = $sujeto("n.codigo = 'sec' AND g.numero = 5 AND m.tipo IN ('continuador','nuevo')");
    $ok($quinto > 0 && !$enUniverso($quinto), "5.º de secundaria ({$quinto}): FUERA");
    $cuartoSec = $sujeto("n.codigo = 'sec' AND g.numero = 4 AND m.tipo IN ('continuador','nuevo')");
    $ok($cuartoSec > 0 && $enUniverso($cuartoSec), "4.º de secundaria ({$cuartoSec}): DENTRO");
    $quintoPrim = $sujeto("n.codigo = 'prim' AND g.numero = 5 AND m.tipo IN ('continuador','nuevo')");
    $ok($quintoPrim > 0 && $enUniverso($quintoPrim), "5.º de PRIMARIA ({$quintoPrim}): DENTRO (no confundir con secundaria)");

    $retorno = $pdo->query("SELECT matricula_oficial_id, matricula_operativa_id, estado FROM retornos_grado LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    if ($retorno) {
        $ok(!$enUniverso((int) $retorno['matricula_operativa_id']),
            "retorno {$retorno['estado']}: la OPERATIVA ({$retorno['matricula_operativa_id']}) FUERA");
        $ok($enUniverso((int) $retorno['matricula_oficial_id']),
            "retorno {$retorno['estado']}: la OFICIAL ({$retorno['matricula_oficial_id']}) DENTRO");
    } else {
        echo "  [--]   sin retornos de grado para probar\n";
    }

    // Todos los ESTADOS entran: se prueba moviendo el estado dentro de la transacción.
    foreach (['pendiente', 'desactivado'] as $estado) {
        $pdo->prepare("UPDATE matriculas SET estado = ? WHERE id = ?")->execute([$estado, $normal]);
        $ok($enUniverso($normal), "estado '{$estado}': DENTRO");
    }
    $pdo->prepare("UPDATE matriculas SET estado = 'aprobada' WHERE id = ?")->execute([$normal]);

    // Contadores coherentes con un conteo independiente del universo.
    $anioId = (int) $uno("SELECT anio_id FROM matriculas WHERE id = ?", [$normal]);
    $secciones = $rat->seccionesConContadores($anioId);
    $sumaTotal = array_sum(array_map(static fn($s) => (int) $s['total'], $secciones));
    $directo = (int) $uno("
        SELECT COUNT(*) FROM matriculas m
        JOIN secciones s ON s.id = m.seccion_id JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
        WHERE m.anio_id = ? AND m.tipo NOT IN ('trasladado','retirado')
          AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
          AND NOT (n.codigo = 'sec' AND g.numero = 5)", [$anioId]);
    $ok($sumaTotal === $directo, "suma de contadores por sección ({$sumaTotal}) = conteo directo ({$directo})");
    $ok(array_filter($secciones, static fn($s) => (int) $s['total'] !== (int) $s['ratificados']
            + (int) $s['no_ratificados'] + (int) $s['pendientes']) === [],
        'en cada sección: total = ratificados + no ratificados + pendientes');

    $secNormal = (int) $uno("SELECT seccion_id FROM matriculas WHERE id = ?", [$normal]);
    $lista = $rat->listarSeccion($secNormal);
    $ok(in_array($normal, array_map(static fn($r) => (int) $r['matricula_id'], $lista), true),
        'listarSeccion() incluye al sujeto');

    // ── 2. CHECK de la BD ────────────────────────────────────────
    echo "\n=== 2. Integridad en la BD ===\n";
    $ok($bdRechaza("INSERT INTO ratificaciones (matricula_id, decision, registrado_por) VALUES (?, 'no_ratificado', ?)",
        [$normal, $uid]), 'CHECK: no_ratificado SIN motivo → rechazado');

    $motivoId = $mot->crear($anioId, 'Motivo de prueba verif', 'Texto oficial de prueba del verificador.');
    $ok($bdRechaza("INSERT INTO ratificaciones (matricula_id, decision, motivo_id, registrado_por) VALUES (?, 'ratificado', ?, ?)",
        [$normal, $motivoId, $uid]), 'CHECK: ratificado CON motivo → rechazado');

    // ── 3. guardarDecision() ─────────────────────────────────────
    echo "\n=== 3. guardarDecision() ===\n";
    $ok($rechaza(fn() => $rat->guardarDecision($quinto, 'ratificado', null, null, $uid, false)) !== null,
        'fuera del universo (5.º sec): rechazado');
    $ok($rechaza(fn() => $rat->guardarDecision($normal, 'otra', null, null, $uid, false)) !== null,
        'decisión inválida: rechazada');
    $ok($rechaza(fn() => $rat->guardarDecision($normal, 'no_ratificado', null, null, $uid, false)) !== null,
        'no_ratificado sin motivo: rechazado por el modelo');
    $ok($rechaza(fn() => $rat->guardarDecision($normal, 'no_ratificado', $motivoId, str_repeat('x', 501), $uid, false)) !== null,
        'detalle de 501 caracteres: rechazado');

    // Motivo de OTRO año: año temporal 2099 (se revierte).
    $pdo->exec("INSERT INTO anios_academicos (anio, fecha_inicio, fecha_fin, estado) VALUES (2099, '2099-03-01', '2099-12-20', 'planificado')");
    $anioOtro = (int) $pdo->lastInsertId();
    $motivoOtro = $mot->crear($anioOtro, 'Motivo de otro año', 'Texto oficial de otro año para la prueba.');
    $ok($rechaza(fn() => $rat->guardarDecision($normal, 'no_ratificado', $motivoOtro, null, $uid, false)) !== null,
        'motivo de OTRO año: rechazado');

    $ok($rat->guardarDecision($normal, 'ratificado', $motivoId, 'ignorar', $uid, false) === true,
        'ratificado: guardado (motivo y detalle se descartan)');
    $fila = $rat->decisionDe($normal);
    $ok($fila['decision'] === 'ratificado' && $fila['motivo_id'] === null && $fila['detalle'] === null,
        'la fila guardada no lleva motivo ni detalle');
    $ok($rat->guardarDecision($normal, 'ratificado', null, null, $uid, false) === false,
        'mismos valores: «sin cambios» (false), no escribe');

    $ok($rat->guardarDecision($normal, 'no_ratificado', $motivoId, '  Detalle  ', $uid, false) === true,
        'cambio a no_ratificado sin documento emitido: no pide confirmación');
    $fila = $rat->decisionDe($normal);
    $ok($fila['detalle'] === 'Detalle', 'el detalle se guarda recortado');

    // Motivo RETIRADO: lo conserva quien ya lo tenía; no se asigna a otro.
    $mot->retirar($motivoId);
    $ok($rat->guardarDecision($normal, 'no_ratificado', $motivoId, 'Otro detalle', $uid, false) === true,
        'motivo retirado: quien ya lo tenía puede conservarlo');
    $ok($rechaza(fn() => $rat->guardarDecision($cuartoSec, 'no_ratificado', $motivoId, null, $uid, false)) !== null,
        'motivo retirado: no se asigna a otro estudiante');
    $mot->reactivar($motivoId);

    // Documento emitido (fila mínima de plantilla + emisión, se revierte).
    $pdo->prepare("INSERT INTO ratificacion_plantillas (anio_id, tipo, titulo, creado_por) VALUES (?, 'no_ratificado', 'Prueba', ?)")
        ->execute([$anioId, $uid]);
    $plantillaId = (int) $pdo->lastInsertId();
    $ratId = (int) $fila['id'];
    $pdo->prepare("INSERT INTO ratificacion_emisiones (ratificacion_id, plantilla_id, decision, motivo_texto, token, emitido_por)
                   VALUES (?, ?, 'no_ratificado', 'x', ?, ?)")
        ->execute([$ratId, $plantillaId, bin2hex(random_bytes(16)), $uid]);
    $emisionId = (int) $pdo->lastInsertId();

    $ok($bdRechaza("INSERT INTO ratificacion_emisiones (ratificacion_id, plantilla_id, decision, motivo_texto, token, emitido_por)
                    VALUES (?, ?, 'no_ratificado', 'x', ?, ?)", [$ratId, $plantillaId, bin2hex(random_bytes(16)), $uid]),
        'UNIQUE: una segunda emisión VIGENTE → rechazada');

    $ok($rechaza(fn() => $rat->guardarDecision($normal, 'ratificado', null, null, $uid, false)) !== null,
        'con documento emitido y SIN confirmación: rechazado');
    $ok((int) $uno("SELECT COUNT(*) FROM ratificacion_emisiones WHERE id = ? AND vigente = 1", [$emisionId]) === 1,
        '… y la emisión sigue vigente');
    $ok($rat->guardarDecision($normal, 'no_ratificado', $motivoId, 'Otro detalle', $uid, false) === false,
        'con documento emitido, sin cambios: no pide confirmación ni anula');

    $ok($rat->guardarDecision($normal, 'ratificado', null, null, $uid, true) === true,
        'con documento emitido y CON confirmación: guardado');
    $em = $pdo->query("SELECT vigente, anulado_en, anulado_por FROM ratificacion_emisiones WHERE id = {$emisionId}")->fetch(PDO::FETCH_ASSOC);
    $ok($em['vigente'] === null && $em['anulado_en'] !== null && (int) $em['anulado_por'] === $uid,
        '… y la emisión quedó ANULADA, con quién y cuándo');
    $ok($rat->decisionDe($normal)['emision_id'] === null, '… y la decisión queda sin emisión vigente');

    // Motivos: nombre repetido y textos fuera de rango.
    $ok($rechaza(fn() => $mot->crear($anioId, 'Motivo de prueba verif', 'Texto suficientemente largo.')) !== null,
        'motivo con nombre repetido en el año: rechazado');
    $ok($rechaza(fn() => $mot->crear($anioId, 'Corto', 'breve')) !== null,
        'motivo con texto oficial demasiado corto: rechazado');
} finally {
    $pdo->rollBack();
}

// ── 4. Guardas del controlador (estático) ────────────────────────
echo "\n=== 4. Guardas del controlador ===\n";
$src = file_get_contents(APP_PATH . '/Controllers/Admin/RatificacionController.php');
$ok(str_contains($src, '$this->requireRole(RatificacionModel::ROLES_VEN);'),
    'el constructor deja entrar a ROLES_VEN (incluye Dirección)');
$lectura   = ['index', 'motivos'];
$escritura = ['guardarDecision', 'crearMotivo', 'actualizarMotivo', 'retirarMotivo', 'reactivarMotivo'];
preg_match_all('/public function (\w+)\(/', $src, $m);
$publicos = array_values(array_diff($m[1], ['__construct']));
$sinDecidir = array_diff($publicos, $lectura, $escritura);
$ok($sinDecidir === [], 'todo método público está clasificado como lectura o escritura'
    . ($sinDecidir ? ' | SIN DECIDIR: ' . implode(', ', $sinDecidir) : ''));
foreach ($escritura as $metodo) {
    $patron = '/public function ' . $metodo . '\([^)]*\)(?:\s*:\s*\w+)?\s*\r?\n\s*\{\s*\r?\n'
            . '\s*\$this->requireRole\(RatificacionModel::ROLES_REGISTRAN\);\s*\r?\n\s*\$this->validateCsrf\(\);/';
    $ok((bool) preg_match($patron, $src), "{$metodo}: requireRole(ROLES_REGISTRAN) + CSRF como primeras sentencias");
}
$ok(array_intersect(ROLES_DIRECCION, RatificacionModel::ROLES_REGISTRAN) === [],
    'ningún rol de Dirección está en ROLES_REGISTRAN (solo lectura)');
$ok(array_diff(ROLES_DIRECCION, RatificacionModel::ROLES_VEN) === [],
    'los tres directores están en ROLES_VEN');

// ── 5. ROLLBACK ──────────────────────────────────────────────────
echo "\n=== 5. ROLLBACK: nada quedó escrito ===\n";
foreach ($tablas as $t) {
    $ok((int) $uno("SELECT COUNT(*) FROM {$t}") === $antes[$t], "{$t}: {$antes[$t]} filas, igual que antes");
}
$ok($pdo->query("SELECT id, estado, tipo FROM matriculas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $antesEstados,
    'matriculas: estados y tipos idénticos');
$ok((int) $uno("SELECT COUNT(*) FROM anios_academicos WHERE anio = 2099") === 0, 'el año temporal 2099 no quedó');

echo "\n" . ($fallos === 0 ? "TODO OK" : "FALLAS: {$fallos}") . "\n";
exit($fallos === 0 ? 0 : 1);
