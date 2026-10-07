<?php

/**
 * Verificación — NUMERACIÓN COMPARTIDA DE R.D. (traslado + cambio de sección).
 * Uso: php database/verificaciones/verif_numeracion_rd.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila.
 *
 * LA REGLA (decidida el 07/10/2026, migración 075)
 *   Las constancias de traslado y las R.D. de cambio de sección comparten UN
 *   correlativo por año (N° 000-{AÑO}-CAVVG-DA). Se admite cualquier número
 *   LIBRE. Ocupan: constancia 'vigente' y cambio 'vigente'. Liberan: constancia
 *   anulada y cambio revertido. La sugerencia es el máximo de las DOS tablas + 1.
 *   PUNTO ÚNICO: TrasladoModel::correlativoDisponible / siguienteCorrelativo.
 *
 * QUÉ COMPRUEBA (las dos ramas de cada guarda)
 *   1. Un número de constancia vigente está OCUPADO; uno nuevo está LIBRE.
 *   2. Un cambio de sección VIGENTE ocupa su número (también para el traslado).
 *   3. La sugerencia salta por encima del número del cambio de sección.
 *   4. Un cambio REVERTIDO libera su número, pero la sugerencia no baja.
 *   5. ROLLBACK: cambios_seccion vuelve a su conteo inicial.
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

$pdo       = Core\Database::connect();
$traslados = new App\Models\TrasladoModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};

// ── Sujeto: año activo, una matrícula con otra sección del mismo grado ──
$fila = $pdo->query("
    SELECT m.id AS matricula_id, m.anio_id, m.seccion_id AS origen,
           (SELECT s2.id FROM secciones s2
             WHERE s2.grado_id = s.grado_id AND s2.anio_id = s.anio_id AND s2.id <> s.id
             LIMIT 1) AS destino,
           (SELECT p.id FROM periodos p WHERE p.anio_id = m.anio_id ORDER BY p.numero LIMIT 1) AS periodo_id
    FROM matriculas m
    JOIN secciones s ON s.id = m.seccion_id
    JOIN anios_academicos aa ON aa.id = m.anio_id AND aa.estado = 'activo'
    WHERE EXISTS (SELECT 1 FROM secciones s3
                   WHERE s3.grado_id = s.grado_id AND s3.anio_id = s.anio_id AND s3.id <> s.id)
    ORDER BY m.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$fila) { echo "No hay grados con dos secciones en el año activo. Nada que verificar.\n"; exit(0); }

$anioId  = (int) $fila['anio_id'];
$config  = $traslados->getConfigAnio($anioId);
$inicial = (int) ($config['correlativo_traslado_inicial'] ?? 1);
$usuario = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();
$cambiosAntes = $contar("SELECT COUNT(*) FROM cambios_seccion");

$pdo->beginTransaction();
try {
    echo "\n=== 1. Constancias de traslado ===\n";
    $vigente = $contar("SELECT correlativo FROM traslados
                         WHERE anio_id = ? AND estado = 'vigente' ORDER BY id LIMIT 1", [$anioId]);
    if ($vigente > 0) {
        $ok(!$traslados->correlativoDisponible($anioId, $vigente),
            "el número {$vigente} (constancia vigente) está OCUPADO");
    } else {
        echo "  (sin constancias vigentes en el año: rama no evaluable)\n";
    }
    $sugerido = $traslados->siguienteCorrelativo($anioId, $inicial);
    $ok($traslados->correlativoDisponible($anioId, $sugerido),
        "el sugerido {$sugerido} está LIBRE");

    echo "\n=== 2. Un cambio de sección VIGENTE ocupa su número ===\n";
    $pdo->prepare("
        INSERT INTO cambios_seccion
            (matricula_id, anio_id, periodo_id, seccion_origen_id, seccion_destino_id,
             rd_correlativo, rd_numero, rd_fecha, motivo, movido_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'verificación', ?)
    ")->execute([
        (int) $fila['matricula_id'], $anioId, (int) $fila['periodo_id'],
        (int) $fila['origen'], (int) $fila['destino'],
        $sugerido, 'N° ' . $sugerido . ' (verificación)', $usuario,
    ]);
    $cambioId = (int) $pdo->lastInsertId();
    $ok(!$traslados->correlativoDisponible($anioId, $sugerido),
        "el número {$sugerido} queda OCUPADO también para el traslado");

    echo "\n=== 3. La sugerencia mira las dos tablas ===\n";
    $ok($traslados->siguienteCorrelativo($anioId, $inicial) === $sugerido + 1,
        'la sugerencia pasa a ' . ($sugerido + 1));

    echo "\n=== 4. Un cambio REVERTIDO libera su número ===\n";
    $pdo->prepare("UPDATE cambios_seccion SET estado = 'revertido' WHERE id = ?")->execute([$cambioId]);
    $ok($traslados->correlativoDisponible($anioId, $sugerido),
        "el número {$sugerido} vuelve a estar LIBRE");
    $ok($traslados->siguienteCorrelativo($anioId, $inicial) === $sugerido + 1,
        'la sugerencia NO baja (el revertido también fue emitido)');
} finally {
    $pdo->rollBack();
}

echo "\n=== 5. ROLLBACK ===\n";
$ok($contar("SELECT COUNT(*) FROM cambios_seccion") === $cambiosAntes,
    "cambios_seccion vuelve a {$cambiosAntes} filas");

echo "\n" . ($fallos === 0 ? "TODO OK\n" : "FALLAS: {$fallos}\n");
exit($fallos === 0 ? 0 : 1);
