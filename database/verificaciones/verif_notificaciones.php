<?php

/**
 * Verificación — MÓDULO DE NOTIFICACIONES (migraciones 058 y 060).
 * Uso: php database/verificaciones/verif_notificaciones.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila. Cumple la regla del proyecto ("ningún script
 * de database/ debe limpiar con DELETE lo que no creó").
 *
 * Nació el 16/09/2026 al separar estos asertos de `verif_notas_origen.php`
 * (bloques 5, 6, 7 y 7b), donde el módulo quedaba sin verificador propio.
 *
 * QUÉ COMPRUEBA
 *   1. AVISO del sistema: uno por docente de la sección, ni uno de más.
 *   2. REPETIDOS: volver a avisar REFRESCA el sin leer; si ya se leyó, crea otro.
 *   3. Usuarios INACTIVOS no reciben avisos.
 *   4. AISLAMIENTO: marcar leída la de un usuario no toca la de otro.
 *   5. `alertas` (tutor → padre) sigue intacta.
 *   6. ROLES: dirección recibe pero NO emite; padres fuera.
 *   7. DESTINOS combinables: sin duplicados, sin el emisor, cada grupo completo.
 *   8. ENVÍO + HISTORIAL: comunicado enlazado y conteo de leídos.
 *   9. Secciones ofrecidas = año activo; gates del controlador por método.
 *  10. ROLLBACK: todos los contadores vuelven a su valor inicial.
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

use App\Models\NotificacionModel as N;

$pdo    = Core\Database::connect();
$notifs = new N();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};
$columna = function (string $sql, array $p = []) use ($pdo): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

if ($contar("SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comunicados'") === 0) {
    echo "Falta la migración 060 (tabla `comunicados`). Aplícala antes de verificar.\n";
    exit(1);
}

// ── Sujeto: una matrícula del año activo con docentes activos con carga ──
$mid = (int) $pdo->query("
    SELECT m.id
    FROM matriculas m
    JOIN anios_academicos aa ON aa.id = m.anio_id AND aa.estado = 'activo'
    WHERE (SELECT COUNT(DISTINCT ca.docente_id)
             FROM cargas_academicas ca
             JOIN usuarios u ON u.id = ca.docente_id AND u.estado = 'activo'
            WHERE ca.seccion_id = m.seccion_id AND ca.anio_id = m.anio_id
              AND ca.estado = 'activa') >= 2
    ORDER BY m.id LIMIT 1
")->fetchColumn();

if ($mid === 0) { echo "No hay matrículas con 2+ docentes activos. Nada que verificar.\n"; exit(0); }

$idsDocentes = $columna("
    SELECT DISTINCT ca.docente_id
    FROM matriculas m
    JOIN cargas_academicas ca
      ON ca.seccion_id = m.seccion_id AND ca.anio_id = m.anio_id AND ca.estado = 'activa'
    JOIN usuarios u ON u.id = ca.docente_id AND u.estado = 'activo'
    WHERE m.id = ?
", [$mid]);
sort($idsDocentes);
[$docA, $docB] = $idsDocentes;

$emisor = (int) $pdo->query("
    SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
    WHERE r.codigo = 'admin' AND u.estado = 'activo' ORDER BY u.id LIMIT 1
")->fetchColumn();

echo "\n=== SUJETO ===\n";
echo "  matrícula {$mid} · docentes activos con carga: " . count($idsDocentes)
    . " · emisor (admin): {$emisor}\n";

$antes = [
    'notificaciones' => $contar("SELECT COUNT(*) FROM notificaciones"),
    'comunicados'    => $contar("SELECT COUNT(*) FROM comunicados"),
    'alertas'        => $contar("SELECT COUNT(*) FROM alertas"),
];
$activosAntes = $contar("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo'");

// Enlace propio: no choca con avisos reales sin leer de esa matrícula.
$enlace = 'docente/notas-origen/' . $mid . '?verif=' . bin2hex(random_bytes(4));
$sinLeerDe = fn(int $uid, string $enl): int => $contar(
    "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND enlace = ? AND leida_en IS NULL",
    [$uid, $enl]
);
$filasDe = fn(int $uid, string $enl): int => $contar(
    "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND enlace = ?", [$uid, $enl]
);

$pdo->beginTransaction();
try {
    echo "\n=== 1. AVISO DEL SISTEMA — uno por docente, ni uno de más ===\n";
    $creadas = $notifs->crearParaDocentesDeSeccion($mid, N::TIPO_NOTAS_ORIGEN,
        'Verificación v1', 'Mensaje de verificación (se revierte).', $enlace);
    $ok($creadas === count($idsDocentes),
        "avisados: {$creadas} = docentes activos de la sección (" . count($idsDocentes) . ")");
    $ok($contar("SELECT COUNT(*) FROM notificaciones") === $antes['notificaciones'] + $creadas,
        "filas nuevas en notificaciones: +{$creadas}");

    echo "\n=== 2. REPETIDOS — refrescar el sin leer, crear si ya se leyó ===\n";
    $trasPrimera = $contar("SELECT COUNT(*) FROM notificaciones");
    $notifs->crearParaDocentesDeSeccion($mid, N::TIPO_NOTAS_ORIGEN,
        'Verificación v2', 'Mensaje corregido (se revierte).', $enlace);
    $ok($contar("SELECT COUNT(*) FROM notificaciones") === $trasPrimera,
        'guardar otra vez NO crea filas mientras el aviso siga sin leer');
    $ok($sinLeerDe($docA, $enlace) === 1, "el docente {$docA} sigue con UN solo aviso sin leer");
    $ok($contar("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND enlace = ? AND titulo = 'Verificación v2'",
        [$docA, $enlace]) === 1, 'el aviso refrescado lleva el texto nuevo');

    $idA = $contar("SELECT id FROM notificaciones WHERE usuario_id = ? AND enlace = ?", [$docA, $enlace]);
    $notifs->marcarLeida($idA, $docA);
    $notifs->crearParaDocentesDeSeccion($mid, N::TIPO_NOTAS_ORIGEN,
        'Verificación v3', 'Tercera versión (se revierte).', $enlace);
    $ok($filasDe($docA, $enlace) === 2 && $sinLeerDe($docA, $enlace) === 1,
        "el docente {$docA}, que YA lo leyó, recibe uno nuevo (2 filas, 1 sin leer)");
    $ok($filasDe($docB, $enlace) === 1,
        "el docente {$docB}, que no lo leyó, sigue con una sola fila");

    echo "\n=== 3. INACTIVOS — no reciben avisos ===\n";
    $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?")->execute([$docB]);
    $enlace2 = $enlace . '-inactivo';
    $avisados = $notifs->crearParaDocentesDeSeccion($mid, N::TIPO_NOTAS_ORIGEN,
        'Verificación inactivo', 'Se revierte.', $enlace2);
    $ok($filasDe($docB, $enlace2) === 0 && $avisados === count($idsDocentes) - 1,
        "el docente {$docB} inactivo queda fuera ({$avisados} avisados)");

    echo "\n=== 4. AISLAMIENTO entre bandejas ===\n";
    $noLeidasB = $notifs->contarNoLeidas($docB);
    $idNuevaA  = $contar("SELECT id FROM notificaciones WHERE usuario_id = ? AND enlace = ? AND leida_en IS NULL",
        [$docA, $enlace]);
    $notifs->marcarLeida($idNuevaA, $docA);
    $ok($notifs->contarNoLeidas($docB) === $noLeidasB,
        "la bandeja del docente {$docB} no se movió ({$noLeidasB} sin leer)");
    $idDeB = $contar("SELECT id FROM notificaciones WHERE usuario_id = ? AND enlace = ?", [$docB, $enlace]);
    $notifs->marcarLeida($idDeB, $docA);   // usuario equivocado a propósito
    $ok($sinLeerDe($docB, $enlace) === 1, 'nadie puede marcar la notificación de otro aunque adivine el id');

    echo "\n=== 5. `alertas` (tutor → padre) intacta ===\n";
    $ok($contar("SELECT COUNT(*) FROM alertas") === $antes['alertas'],
        "alertas sigue en {$antes['alertas']} filas: son mecanismos distintos");

    echo "\n=== 6. ROLES ===\n";
    $solapan = array_intersect(N::ROLES_EMISORES, ROLES_DIRECCION);
    $ok($solapan === [], 'ROLES_EMISORES no incluye ningún rol de dirección');
    $ok(array_diff(ROLES_DIRECCION, N::ROLES_RECEPTORES) === [],
        'los tres roles de dirección SÍ reciben (campana y comunicados)');
    $ok(in_array('docente', N::ROLES_RECEPTORES, true), 'los docentes reciben');
    $ok(array_diff(N::ROLES_ADMINISTRATIVOS, N::ROLES_RECEPTORES) === [],
        'el personal administrativo recibe');
    $ok(!in_array('padre', N::ROLES_RECEPTORES, true),
        'los padres quedan fuera (su superficie sigue oscura)');

    echo "\n=== 7. DESTINOS combinables ===\n";
    $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id = ?")->execute([$docB]);
    $todos = array_keys(N::DESTINOS);
    $seccionId = $contar("SELECT seccion_id FROM matriculas WHERE id = ?", [$mid]);
    $dest = $notifs->destinatariosDeComunicado($todos, $seccionId, $emisor);
    $ok(count($dest) === count(array_unique($dest)), 'sin duplicados (' . count($dest) . ' destinatarios)');
    $ok(!in_array($emisor, $dest, true), "el emisor {$emisor} no se envía a sí mismo");

    $rolesIn = fn(array $r): array => $columna(
        "SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
         WHERE u.estado = 'activo' AND r.codigo IN (" . implode(',', array_fill(0, count($r), '?')) . ")",
        $r
    );
    $esperados = array_unique(array_merge(
        $rolesIn(['docente']), $idsDocentes, $rolesIn(ROLES_DIRECCION), $rolesIn(N::ROLES_ADMINISTRATIVOS)
    ));
    $esperados = array_values(array_diff($esperados, [$emisor]));
    sort($esperados); $d = $dest; sort($d);
    $ok($d === $esperados, 'la unión de los 4 destinos es exactamente la esperada');

    $soloSeccion = $notifs->destinatariosDeComunicado([N::DESTINO_SECCION], $seccionId, $emisor);
    sort($soloSeccion);
    $ok($soloSeccion === $idsDocentes, 'solo «sección» = docentes activos con carga en ella');
    // La rama que EXCLUYE (22/09/2026): antes nadie probaba un inactivo aquí.
    $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?")->execute([$docB]);
    $sinB = $notifs->destinatariosDeComunicado([N::DESTINO_SECCION], $seccionId, $emisor);
    sort($sinB);
    $ok($sinB === array_values(array_diff($idsDocentes, [$docB, $emisor])),
        "solo «sección» deja fuera al docente {$docB} inactivo y conserva a los demás");
    $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id = ?")->execute([$docB]);
    $soloDir = $notifs->destinatariosDeComunicado([N::DESTINO_DIRECCION], null, $emisor);
    $ok(array_diff($soloDir, $rolesIn(ROLES_DIRECCION)) === [], 'solo «dirección» no trae a nadie más');

    echo "\n=== 8. ENVÍO + HISTORIAL ===\n";
    $cid = $notifs->enviarComunicado($emisor, 'Verificación comunicado', 'Se revierte.',
        [N::DESTINO_SECCION, N::DESTINO_DIRECCION], $seccionId,
        $notifs->destinatariosDeComunicado([N::DESTINO_SECCION, N::DESTINO_DIRECCION], $seccionId, $emisor));
    $enlazadas = $contar("SELECT COUNT(*) FROM notificaciones WHERE comunicado_id = ?", [$cid]);
    $ok($contar("SELECT COUNT(*) FROM comunicados") === $antes['comunicados'] + 1, 'un envío = una fila en comunicados');
    $ok($enlazadas > 0 && $contar("SELECT COUNT(*) FROM notificaciones WHERE comunicado_id = ? AND origen = 'comunicado' AND emisor_id = ?",
        [$cid, $emisor]) === $enlazadas, "{$enlazadas} notificaciones enlazadas con origen y emisor correctos");

    $fila = array_values(array_filter($notifs->comunicadosEnviados(), fn($c) => (int) $c['id'] === $cid))[0] ?? null;
    $ok($fila !== null && (int) $fila['destinatarios'] === $enlazadas && (int) $fila['leidos'] === 0,
        'el historial lo lista con N destinatarios y 0 leídos');
    $ok($fila !== null && $fila['destinos'] === 'seccion,direccion' && !empty($fila['seccion_nombre']),
        'guarda los destinos elegidos y la sección');

    $idCom = $contar("SELECT id FROM notificaciones WHERE comunicado_id = ? AND usuario_id = ?", [$cid, $docA]);
    $notifs->marcarLeida($idCom, $docA);
    $fila = array_values(array_filter($notifs->comunicadosEnviados(), fn($c) => (int) $c['id'] === $cid))[0] ?? null;
    $ok($fila !== null && (int) $fila['leidos'] === 1, 'al leerlo un docente, el historial marca 1 leído');

    echo "\n=== 9. SECCIONES y GATES ===\n";
    $secc = $notifs->seccionesParaComunicado();
    $fuera = 0;
    foreach ($secc as $s) {
        $fuera += $contar("SELECT COUNT(*) FROM secciones s JOIN anios_academicos aa ON aa.id = s.anio_id
                           WHERE s.id = ? AND aa.estado <> 'activo'", [(int) $s['id']]);
    }
    $ok($secc !== [] && $fuera === 0, count($secc) . ' secciones ofrecidas, todas del año activo');

    $src = file_get_contents(APP_PATH . '/Controllers/NotificacionController.php');
    $cuerpo = function (string $metodo) use ($src): string {
        preg_match('/function ' . $metodo . '\(\): void\s*\{(.*?)\n    \}/s', $src, $m);
        return $m[1] ?? '';
    };
    foreach (['comunicado', 'guardarComunicado', 'enviados'] as $m) {
        $ok(str_contains($cuerpo($m), 'requireRole(NotificacionModel::ROLES_EMISORES)'),
            "{$m}() exige ROLES_EMISORES");
    }
    foreach (['leer', 'leerTodas'] as $m) {
        $ok($cuerpo($m) !== '' && !str_contains($cuerpo($m), 'requireRole'),
            "{$m}() sin gate de rol: dirección marca SU bandeja (excepción acotada)");
    }

} catch (Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $fallos++;
} finally {
    $pdo->rollBack();
}

echo "\n=== 10. ROLLBACK — la base vuelve a su estado inicial ===\n";
foreach ($antes as $k => $v) {
    $final = $contar("SELECT COUNT(*) FROM {$k}");
    $ok($final === $v, "{$k}: {$v} → {$final}");
}
$ok($contar("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo'") === $activosAntes,
    "usuarios activos: {$activosAntes} (el docente desactivado para la prueba volvió)");

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — avisos sin repetir, destinos combinables e historial.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
