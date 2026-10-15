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
 *   6. Plantillas (Fase 2): validación de bloques, render sin XSS, versiones
 *      (reescribe la no congelada, crea la siguiente sobre la congelada),
 *      huella contra ediciones simultáneas, CHECK de bloques y duplicar.
 *   7. Emisión (Fase 3): pendiente sin documento, congelado, versión nueva al
 *      editar, copia del motivo, token propio, anulación, porToken() solo
 *      autenticidad, lote por sección con plantilla faltante.
 *      Fase 4: lote solo con emisiones vigentes y aviso al matricular (año
 *      siguiente por número, solo no ratificado vigente).
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
use App\Models\RatificacionPlantillaModel;

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
    // Versión siguiente a la más alta: el año real puede tener ya sus plantillas.
    $pdo->prepare("INSERT INTO ratificacion_plantillas (anio_id, tipo, version, titulo, creado_por)
                   SELECT ?, 'no_ratificado', COALESCE(MAX(version), 0) + 1, 'Prueba', ?
                   FROM ratificacion_plantillas WHERE anio_id = ? AND tipo = 'no_ratificado'")
        ->execute([$anioId, $uid, $anioId]);
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

    // ── 6. Plantillas por bloques (Fase 2) ───────────────────────
    // Sobre el año temporal 2099 (sin plantillas) y 2100, que se crea aquí.
    echo "\n=== 6. Plantillas por bloques ===\n";
    $pla = new RatificacionPlantillaModel();
    $parrafo = fn(string $t): array => ['tipo' => 'parrafo', 'texto' => $t];
    $tabla   = fn(array $f): array => ['tipo' => 'tabla', 'tabla_json' => json_encode($f)];

    $ok($rechaza(fn() => $pla->validarBloques('Ti', [$parrafo('Hola')])) !== null, 'título de 2 caracteres: rechazado');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [])) !== null, 'sin bloques: rechazado');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [['tipo' => 'video']])) !== null, 'tipo de bloque inválido: rechazado');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [$parrafo('   ')])) !== null, 'párrafo vacío: rechazado');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [$parrafo('Hola {nombre}')])) !== null, 'variable desconocida {nombre}: rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [$tabla([['a', 'b'], ['c']])])) !== null, 'tabla con filas desiguales: rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [$tabla([['', ''], [' ', '']])])) !== null, 'tabla sin texto: rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [$tabla([array_fill(0, 9, 'x')])])) !== null, 'tabla de 9 columnas: rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [['tipo' => 'tabla', 'tabla_json' => '{"a":1}']])) !== null, 'tabla que no es lista de filas: rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [['tipo' => 'imagen', 'imagen_archivo' => '../x.png', 'imagen_tamano' => 'media']])) !== null,
        'imagen con ruta (../): rechazada');
    $ok($rechaza(fn() => $pla->validarBloques('Título', [['tipo' => 'imagen', 'imagen_archivo' => 'a.png', 'imagen_tamano' => 'gigante']])) !== null,
        'imagen con tamaño inválido: rechazada');
    $val = $pla->validarBloques('  Constancia  ', [$parrafo("Línea 1\r\nLínea 2 {estudiante}"), ['tipo' => 'salto'],
        $tabla([['Cab', 'Otra'], [' a ', 'b']])]);
    $ok($val['titulo'] === 'Constancia' && $val['bloques'][0]['texto'] === "Línea 1\nLínea 2 {estudiante}"
        && $val['bloques'][2]['tabla_json'] === '[["Cab","Otra"],["a","b"]]',
        'validarBloques() recorta el título, normaliza saltos y celdas');

    $html = RatificacionPlantillaModel::renderParrafo("<script>x</script> **{estudiante}** {dni}\nfin",
        ['estudiante' => '<b>ÑUFLO**</b>', 'dni' => '1"2']);
    $ok(!str_contains($html, '<script') && !str_contains($html, '<b>') && str_contains($html, '&lt;script&gt;'),
        'renderParrafo(): el texto y los datos se escapan (sin XSS)');
    $ok(str_contains($html, '<strong>&lt;b&gt;ÑUFLO**&lt;/b&gt;</strong>') && str_contains($html, '1&quot;2'),
        'renderParrafo(): **negrita** y variables sobre el texto escapado');
    $ok(str_contains($html, '<br>'), 'renderParrafo(): respeta los saltos de línea');

    $pl1 = $pla->validarBloques('Constancia', [$parrafo('Uno'), $parrafo('Dos')]);
    $ok($pla->guardar($anioOtro, 'ratificado', $pl1, $uid, '') === true, 'primera plantilla: guardada');
    $v1 = $pla->vigente($anioOtro, 'ratificado');
    $ok((int) $v1['version'] === 1 && count($pla->bloques((int) $v1['id'])) === 2, '… como versión 1 con sus 2 bloques');
    $h1 = $pla->huellaVigente($anioOtro, 'ratificado');
    $ok($pla->guardar($anioOtro, 'ratificado', $pl1, $uid, $h1) === false, 'mismo contenido: «sin cambios» (false)');
    $ok($rechaza(fn() => $pla->guardar($anioOtro, 'ratificado', $pl1, $uid, '')) !== null,
        'huella vieja (otra persona guardó entretanto): rechazado');

    $pl2 = $pla->validarBloques('Constancia', [$parrafo('Solo uno')]);
    $ok($pla->guardar($anioOtro, 'ratificado', $pl2, $uid, $h1) === true, 'NO congelada: se guarda el cambio');
    $v1b = $pla->vigente($anioOtro, 'ratificado');
    $ok((int) $v1b['id'] === (int) $v1['id'] && (int) $v1b['version'] === 1
        && count($pla->bloques((int) $v1['id'])) === 1, '… reescribe la MISMA versión (1 bloque)');

    $pdo->prepare("UPDATE ratificacion_plantillas SET congelada_en = NOW() WHERE id = ?")->execute([(int) $v1['id']]);
    $pl3 = $pla->validarBloques('Constancia nueva', [$parrafo('Versión dos'), ['tipo' => 'salto']]);
    $ok($pla->guardar($anioOtro, 'ratificado', $pl3, $uid, $pla->huellaVigente($anioOtro, 'ratificado')) === true,
        'congelada: se guarda el cambio');
    $v2 = $pla->vigente($anioOtro, 'ratificado');
    $ok((int) $v2['version'] === 2 && (int) $v2['id'] !== (int) $v1['id'] && $v2['congelada_en'] === null,
        '… como versión 2 (nueva, sin congelar)');
    $ok(count($pla->bloques((int) $v1['id'])) === 1 && $pla->plantilla((int) $v1['id'])['titulo'] === 'Constancia',
        '… y la versión 1 queda intacta');

    $ok($bdRechaza("INSERT INTO ratificacion_bloques (plantilla_id, orden, tipo) VALUES (?, 9, 'parrafo')", [(int) $v2['id']]),
        'CHECK: párrafo sin texto → rechazado');
    $ok($bdRechaza("INSERT INTO ratificacion_bloques (plantilla_id, orden, tipo, tabla_json) VALUES (?, 9, 'tabla', 'no-json')", [(int) $v2['id']]),
        'CHECK: tabla con JSON inválido → rechazado');
    $ok($bdRechaza("INSERT INTO ratificacion_bloques (plantilla_id, orden, tipo) VALUES (?, 1, 'salto')", [(int) $v2['id']]),
        'UNIQUE: dos bloques con el mismo orden → rechazado');

    // Duplicar del año anterior: 2100 copia de 2099 (por número de año).
    $pdo->exec("INSERT INTO anios_academicos (anio, fecha_inicio, fecha_fin, estado) VALUES (2100, '2100-03-01', '2100-12-20', 'planificado')");
    $anio2100 = (int) $pdo->lastInsertId();
    $ok($rechaza(fn() => $pla->duplicarDelAnioAnterior($anio2100, 'no_ratificado', $uid)) !== null,
        'duplicar sin plantilla del año anterior: rechazado');
    $pla->duplicarDelAnioAnterior($anio2100, 'ratificado', $uid);
    $copia = $pla->vigente($anio2100, 'ratificado');
    $ok($copia !== null && (int) $copia['version'] === 1 && $copia['titulo'] === 'Constancia nueva'
        && count($pla->bloques((int) $copia['id'])) === 2, 'duplicar: copia la vigente de 2099 como versión 1 de 2100');
    $ok($rechaza(fn() => $pla->duplicarDelAnioAnterior($anio2100, 'ratificado', $uid)) !== null,
        'duplicar cuando el año ya tiene plantilla: rechazado');

    // ── 7. Emisión, anulación y verificación (Fase 3) ────────────
    // Sobre el año real de $normal; todo se revierte. $normal llega aquí
    // `ratificado` y con su emisión de prueba ANULADA (sección 3).
    echo "\n=== 7. Emisión, anulación y verificación ===\n";
    $sinDecision = $sujeto("m.seccion_id = {$secNormal} AND m.id <> {$normal}
        AND m.tipo IN ('continuador','nuevo')
        AND m.id NOT IN (SELECT matricula_id FROM ratificaciones)
        AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)");
    $ok($sinDecision > 0 && $rechaza(fn() => $rat->emitir($sinDecision, $uid)) !== null,
        "PENDIENTE ({$sinDecision}): emitir rechazado (el olvido no produce documento)");
    $ok($rechaza(fn() => $rat->emitir($quinto, $uid)) !== null, 'fuera del universo (5.º sec): emitir rechazado');

    // Plantilla de ratificado del año real con contenido propio de la prueba.
    $plR = $pla->validarBloques('Verif F3', [$parrafo('Ratificado {estudiante}')]);
    $pla->guardar($anioId, 'ratificado', $plR, $uid, $pla->huellaVigente($anioId, 'ratificado'));
    $vigR = $pla->vigente($anioId, 'ratificado');

    $rat->emitir($normal, $uid);
    $em1 = $pdo->query("SELECT e.* FROM ratificacion_emisiones e JOIN ratificaciones r ON r.id = e.ratificacion_id
                        WHERE r.matricula_id = {$normal} AND e.vigente = 1")->fetch(PDO::FETCH_ASSOC);
    $ok($em1 !== false && (int) $em1['plantilla_id'] === (int) $vigR['id'] && $em1['decision'] === 'ratificado'
        && $em1['motivo_texto'] === null, 'emitir: emisión vigente con la plantilla vigente del tipo, sin motivo');
    $ok((bool) preg_match('/^[a-f0-9]{32}$/', (string) $em1['token'])
        && (int) $uno("SELECT COUNT(*) FROM matriculas WHERE token_acceso = ?", [$em1['token']]) === 0,
        'el token es propio (32 hex) y no es el token_acceso de una matrícula');
    $ok($pla->plantilla((int) $vigR['id'])['congelada_en'] !== null, 'emitir CONGELA la plantilla');
    $ok($rechaza(fn() => $rat->emitir($normal, $uid)) !== null, 'segunda emisión con una vigente: rechazada');

    $plR2 = $pla->validarBloques('Verif F3 editada', [$parrafo('Otro texto')]);
    $pla->guardar($anioId, 'ratificado', $plR2, $uid, $pla->huellaVigente($anioId, 'ratificado'));
    $ok((int) $pla->vigente($anioId, 'ratificado')['version'] === (int) $vigR['version'] + 1
        && (int) $uno("SELECT plantilla_id FROM ratificacion_emisiones WHERE id = ?", [$em1['id']]) === (int) $vigR['id'],
        'editar tras emitir crea versión nueva; la emisión conserva la suya');

    // Verificación pública por token: solo autenticidad.
    $pub = $rat->porToken((string) $em1['token']);
    $ok($pub !== null && (int) $pub['vigente'] === 1, 'porToken(): emisión vigente → vigente');
    $ok($pub !== null && array_keys($pub) === ['estudiante', 'anio', 'emitido_en', 'vigente', 'anulado_en'],
        'porToken(): solo nombre, año, fechas y estado (nunca DNI, tipo ni motivo)');
    $ok($rat->porToken(bin2hex(random_bytes(16))) === null && $rat->porToken("' OR 1=1 --") === null
        && $rat->porToken(strtoupper((string) $em1['token'])) === null,
        'porToken(): desconocido, mal formado o en mayúsculas → null');

    $doc = $rat->emisionParaDocumento((int) $em1['id']);
    $vars = RatificacionModel::varsDeEmision($doc, ["madre" => "Madre"]);
    $ok($doc !== null && $vars['anio_siguiente'] === (string) ((int) $doc['anio'] + 1)
        && array_keys($vars) === array_keys(RatificacionPlantillaModel::VARIABLES),
        'emisionParaDocumento() + varsDeEmision(): todas las variables del párrafo');

    // No ratificado: el texto del motivo queda COPIADO al emitir.
    $rat->guardarDecision($normal, 'no_ratificado', $motivoId, 'Detalle F3', $uid, true);
    $ok($rat->porToken((string) $em1['token'])['vigente'] === null,
        'cambiar la decisión con confirmación anula la emisión: el QR dice anulado');
    $rat->emitir($normal, $uid);
    $em2 = $pdo->query("SELECT e.* FROM ratificacion_emisiones e JOIN ratificaciones r ON r.id = e.ratificacion_id
                        WHERE r.matricula_id = {$normal} AND e.vigente = 1")->fetch(PDO::FETCH_ASSOC);
    $ok($em2['motivo_texto'] === 'Texto oficial de prueba del verificador.' && $em2['detalle_texto'] === 'Detalle F3'
        && $em2['token'] !== $em1['token'], 'no ratificado: copia texto oficial y detalle, con QR nuevo');
    $mot->actualizar($motivoId, 'Motivo de prueba verif', 'Texto oficial CAMBIADO después de emitir.');
    $ok($uno("SELECT motivo_texto FROM ratificacion_emisiones WHERE id = ?", [$em2['id']]) === 'Texto oficial de prueba del verificador.',
        'editar el motivo NO reescribe el documento emitido');

    // Fase 4: lote y aviso al matricular, con la emisión NO ratificada vigente.
    $ok(in_array((int) $em2['id'], $rat->emisionesVigentesDeSeccion($secNormal), true)
        && !in_array((int) $em1['id'], $rat->emisionesVigentesDeSeccion($secNormal), true),
        'lote: entra la emisión vigente, no la anulada');
    $estNormal = (int) $uno("SELECT estudiante_id FROM matriculas WHERE id = ?", [$normal]);
    $anioNum   = (int) $uno("SELECT anio FROM anios_academicos WHERE id = ?", [$anioId]);
    $aviso = $rat->avisoNoRatificado($estNormal, $anioNum + 1);
    $ok($aviso !== null && (int) $aviso['anio'] === $anioNum,
        'aviso al matricular en el año SIGUIENTE (por número): sí, con el año del documento');
    $ok($rat->avisoNoRatificado($estNormal, $anioNum) === null
        && $rat->avisoNoRatificado($estNormal, $anioNum + 2) === null,
        'aviso: no sale en el mismo año ni dos años después');

    // Anular (acción explícita).
    $rat->anular($normal, $uid);
    $ok($rat->avisoNoRatificado($estNormal, $anioNum + 1) === null, 'aviso: no sale con el documento anulado');
    $ok($rat->porToken((string) $em2['token'])['vigente'] === null && $rat->decisionDe($normal)['decision'] === 'no_ratificado',
        'anular: el QR dice anulado y la decisión se conserva');
    $ok($rechaza(fn() => $rat->anular($normal, $uid)) !== null, 'anular sin documento vigente: rechazado');

    // Lote por sección sin plantilla de no ratificados: emite al resto y lo informa.
    $pdo->prepare("UPDATE ratificacion_plantillas SET anio_id = ? WHERE anio_id = ? AND tipo = 'no_ratificado'")
        ->execute([$anioOtro, $anioId]);
    $rat->guardarDecision($sinDecision, 'ratificado', null, null, $uid, false);
    $esperados = (int) $uno("
        SELECT COUNT(*) FROM ratificaciones r JOIN matriculas m ON m.id = r.matricula_id
        LEFT JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
        WHERE m.seccion_id = ? AND r.decision = 'ratificado' AND e.id IS NULL", [$secNormal]);
    $noRatifSec = (int) $uno("
        SELECT COUNT(*) FROM ratificaciones r JOIN matriculas m ON m.id = r.matricula_id
        LEFT JOIN ratificacion_emisiones e ON e.ratificacion_id = r.id AND e.vigente = 1
        WHERE m.seccion_id = ? AND r.decision = 'no_ratificado' AND e.id IS NULL", [$secNormal]);
    $pendSec = (int) $uno("SELECT COUNT(*) FROM matriculas m WHERE m.seccion_id = ?
        AND m.id NOT IN (SELECT matricula_id FROM ratificaciones)", [$secNormal]);
    $res = $rat->emitirSeccion($secNormal, $uid);
    $ok($res['emitidos'] === $esperados && ($res['sin_plantilla']['no_ratificado'] ?? 0) === $noRatifSec
        && !isset($res['sin_plantilla']['ratificado']),
        "lote: emite {$esperados} ratificado(s) e informa {$noRatifSec} no ratificado(s) sin plantilla");
    $ok($rat->decisionDe($normal)['emision_id'] === null && $rat->decisionDe($sinDecision)['emision_id'] !== null,
        'lote: el no ratificado queda sin documento; el ratificado lo recibe');
    $ok((int) $uno("SELECT COUNT(*) FROM matriculas m WHERE m.seccion_id = ?
        AND m.id NOT IN (SELECT matricula_id FROM ratificaciones)", [$secNormal]) === $pendSec,
        'lote: los pendientes siguen pendientes y sin documento');
    $ok($rat->emitirSeccion($secNormal, $uid)['emitidos'] === 0, 'lote repetido: no emite nada dos veces');
    $estSin = (int) $uno("SELECT estudiante_id FROM matriculas WHERE id = ?", [$sinDecision]);
    $ok($rat->avisoNoRatificado($estSin, $anioNum + 1) === null, 'aviso: un RATIFICADO con documento no lo recibe');
} finally {
    $pdo->rollBack();
}

// ── 4. Guardas del controlador (estático) ────────────────────────
echo "\n=== 4. Guardas del controlador ===\n";
$src = file_get_contents(APP_PATH . '/Controllers/Admin/RatificacionController.php');
$ok(str_contains($src, '$this->requireRole(RatificacionModel::ROLES_VEN);'),
    'el constructor deja entrar a ROLES_VEN (incluye Dirección)');
$lectura   = ['index', 'motivos', 'imprimir', 'lote'];
$escritura = ['guardarDecision', 'crearMotivo', 'actualizarMotivo', 'retirarMotivo', 'reactivarMotivo',
              'emitir', 'emitirSeccion', 'anular'];
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
// El controlador de plantillas (Fase 2), con la misma regla.
$srcP = file_get_contents(APP_PATH . '/Controllers/Admin/RatificacionPlantillaController.php');
$ok(str_contains($srcP, '$this->requireRole(RatificacionModel::ROLES_VEN);'),
    'plantillas: el constructor deja entrar a ROLES_VEN');
$lecturaP   = ['index', 'vistaPrevia', 'imagen'];
$escrituraP = ['guardar', 'duplicar'];
preg_match_all('/public function (\w+)\(/', $srcP, $m);
$sinDecidirP = array_diff(array_values(array_diff($m[1], ['__construct'])), $lecturaP, $escrituraP);
$ok($sinDecidirP === [], 'plantillas: todo método público está clasificado'
    . ($sinDecidirP ? ' | SIN DECIDIR: ' . implode(', ', $sinDecidirP) : ''));
foreach ($escrituraP as $metodo) {
    $patron = '/public function ' . $metodo . '\([^)]*\)(?:\s*:\s*\w+)?\s*\r?\n\s*\{\s*\r?\n'
            . '\s*\$this->requireRole\(RatificacionModel::ROLES_REGISTRAN\);\s*\r?\n\s*\$this->validateCsrf\(\);/';
    $ok((bool) preg_match($patron, $srcP), "plantillas {$metodo}: requireRole(ROLES_REGISTRAN) + CSRF como primeras sentencias");
}
// El parcial de bloque se incluye SOLO dentro de su función aislada: incluido
// suelto, su `$i` de filas de tabla pisaba la clave del bloque siguiente y el
// formulario perdía la tabla (visto en Chrome el 14/10/2026).
$vistaP = file_get_contents(ROOT_PATH . '/resources/views/admin/ratificaciones/plantillas.php');
$ok(substr_count($vistaP, "_bloque-editor.php'") === 1
    && (bool) preg_match('/static function \(string \$clave, array \$b\): void \{\s*include __DIR__ \. \'\/_bloque-editor\.php\';/', $vistaP),
    'plantillas.php incluye _bloque-editor.php una sola vez, dentro de una función (ámbito aislado)');
// Verificación pública (Fase 3): sin sesión y solo por porToken().
$srcV = file_get_contents(APP_PATH . '/Controllers/RatificacionVerificacionController.php');
$ok(!str_contains($srcV, 'requireRole') && !str_contains($srcV, 'requireAuth') && str_contains($srcV, '->porToken($token)'),
    'verificación pública: sin login y solo por porToken()');
// Solo el CÓDIGO: los comentarios del modelo nombran la columna para prohibirla.
$codigoModelo = implode('', array_map(
    static fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
    token_get_all(file_get_contents(APP_PATH . '/Models/RatificacionModel.php'))
));
$ok(!str_contains($codigoModelo, 'token_acceso'),
    'RatificacionModel nunca usa matriculas.token_acceso (abre la boleta)');
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
$ok((int) $uno("SELECT COUNT(*) FROM anios_academicos WHERE anio IN (2099, 2100)") === 0, 'los años temporales 2099 y 2100 no quedaron');

echo "\n" . ($fallos === 0 ? "TODO OK" : "FALLAS: {$fallos}") . "\n";
exit($fallos === 0 ? 0 : 1);
