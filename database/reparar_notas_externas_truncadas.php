<?php

/**
 * Repara las notas del colegio de origen cuya COMPETENCIA quedó recortada a
 * 120 caracteres (ver database/migrations/061_notas_externas_competencia_255.sql).
 *
 * Uso:
 *   php database/reparar_notas_externas_truncadas.php              → SIMULA (UPDATE real + ROLLBACK)
 *   php database/reparar_notas_externas_truncadas.php --confirmar  → aplica y hace COMMIT
 *
 * ⚠️ REQUIERE LA 061 APLICADA. Con la columna todavía en VARCHAR(120), el
 * UPDATE volvería a recortar en silencio: el script lo comprueba y ABORTA.
 *
 * QUÉ HACE, EN ORDEN:
 *   PASO 0  huella del servidor (¿dónde estoy?) y ancho de la columna
 *   PASO 1  filas con exactamente 120 caracteres (candidatas a recortadas)
 *   PASO 2  para cada una, el nombre completo en `competencias.nombre_completo`
 *           que EMPIECE por ese texto. Solo se repara si hay UNO (sin
 *           ambigüedad); las demás se listan y NO se tocan.
 *   PASO 3  UPDATE en transacción; ROLLBACK si no hay --confirmar
 *   PASO 4  verificación en CONEXIÓN NUEVA — lo único que prueba el COMMIT
 *
 * Solo toca `competencia_nombre` de filas ya recortadas: no borra nada. Si al
 * completar el nombre la fila chocara con otra ya existente en la UNIQUE
 * (misma matrícula, periodo, área y competencia completa), se informa y se
 * deja como está.
 */

define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');

$confirmar = in_array('--confirmar', $argv, true);

function conexionFresca(): PDO
{
    $c = require CONFIG_PATH . '/database.php';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['database'], $c['charset']);
    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
}

function titulo(string $t): void
{
    echo "\n" . str_repeat('=', 74) . "\n  $t\n" . str_repeat('=', 74) . "\n";
}

const LARGO_RECORTE = 120;

$db = conexionFresca();

// ── PASO 0 ────────────────────────────────────────────────────────────
titulo('PASO 0 — Huella del servidor');
$h = $db->query("SELECT @@hostname AS host, DATABASE() AS bd, VERSION() AS version")->fetch();
echo "   host={$h['host']}  bd={$h['bd']}  version={$h['version']}\n";
echo '   modo: ' . ($confirmar ? 'CONFIRMAR (COMMIT)' : 'SIMULACIÓN (ROLLBACK)') . "\n";

$ancho = (int) $db->query("
    SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_externas'
      AND COLUMN_NAME = 'competencia_nombre'
")->fetchColumn();
echo "   notas_externas.competencia_nombre = VARCHAR($ancho)\n";
if ($ancho < 255) {
    echo "\n   ✗ ABORTA: aplica antes la migración 061. Con VARCHAR($ancho) el UPDATE\n"
       . "     volvería a recortar el nombre en silencio.\n";
    exit(1);
}

// ── PASO 1 ────────────────────────────────────────────────────────────
titulo('PASO 1 — Filas con la competencia en exactamente ' . LARGO_RECORTE . ' caracteres');
$stmt = $db->prepare("
    SELECT id, matricula_id, periodo_nombre, area_nombre, competencia_nombre
    FROM notas_externas
    WHERE CHAR_LENGTH(competencia_nombre) = ?
    ORDER BY id
");
$stmt->execute([LARGO_RECORTE]);
$candidatas = $stmt->fetchAll();
echo '   ' . count($candidatas) . " fila(s)\n";
if (!$candidatas) {
    echo "\n   Nada que reparar.\n";
    exit(0);
}

// ── PASO 2 ────────────────────────────────────────────────────────────
titulo('PASO 2 — Nombre completo en el plan');
$buscar = $db->prepare("
    SELECT DISTINCT nombre_completo
    FROM competencias
    WHERE CHAR_LENGTH(nombre_completo) > ?
      AND LEFT(nombre_completo, ?) = ?
");
$choque = $db->prepare("
    SELECT id FROM notas_externas
    WHERE matricula_id = ? AND periodo_nombre = ? AND area_nombre = ?
      AND competencia_nombre = ? AND id <> ?
");
$reparables = [];
foreach ($candidatas as $f) {
    $buscar->execute([LARGO_RECORTE, LARGO_RECORTE, $f['competencia_nombre']]);
    $opciones = $buscar->fetchAll(PDO::FETCH_COLUMN);
    if (count($opciones) !== 1) {
        echo "   id={$f['id']}  ✗ NO SE TOCA: " . count($opciones) . " coincidencias en el plan\n";
        continue;
    }
    $completo = $opciones[0];
    $choque->execute([$f['matricula_id'], $f['periodo_nombre'], $f['area_nombre'], $completo, $f['id']]);
    if ($choque->fetchColumn()) {
        echo "   id={$f['id']}  ✗ NO SE TOCA: ya existe otra fila con el nombre completo\n";
        continue;
    }
    echo "   id={$f['id']}  matrícula={$f['matricula_id']}  "
       . mb_strlen($f['competencia_nombre']) . ' → ' . mb_strlen($completo) . " caracteres\n"
       . "      $completo\n";
    $reparables[(int) $f['id']] = $completo;
}

if (!$reparables) {
    echo "\n   Ninguna fila reparable sin ambigüedad.\n";
    exit(0);
}

// ── PASO 3 ────────────────────────────────────────────────────────────
titulo('PASO 3 — UPDATE');
$upd = $db->prepare("
    UPDATE notas_externas SET competencia_nombre = ?
    WHERE id = ? AND CHAR_LENGTH(competencia_nombre) = ?
");
$db->beginTransaction();
$total = 0;
try {
    foreach ($reparables as $id => $completo) {
        $upd->execute([$completo, $id, LARGO_RECORTE]);
        $total += $upd->rowCount();
    }
    // Comprobación dentro de la transacción: ninguna quedó recortada otra vez.
    $ids = implode(',', array_map('intval', array_keys($reparables)));
    $mal = (int) $db->query("
        SELECT COUNT(*) FROM notas_externas
        WHERE id IN ($ids) AND CHAR_LENGTH(competencia_nombre) = " . LARGO_RECORTE
    )->fetchColumn();
    if ($mal > 0) {
        throw new RuntimeException("$mal fila(s) siguen en " . LARGO_RECORTE . ' caracteres');
    }
    echo "   $total fila(s) actualizadas\n";
    if ($confirmar) {
        $db->commit();
        echo "   COMMIT\n";
    } else {
        $db->rollBack();
        echo "   ROLLBACK (simulación). Repite con --confirmar para aplicar.\n";
        exit(0);
    }
} catch (Throwable $e) {
    $db->rollBack();
    echo "   ✗ ROLLBACK: " . $e->getMessage() . "\n";
    exit(1);
}

// ── PASO 4 ────────────────────────────────────────────────────────────
titulo('PASO 4 — Verificación en conexión nueva');
$v = conexionFresca();
$ids = implode(',', array_map('intval', array_keys($reparables)));
$ok = (int) $v->query("
    SELECT COUNT(*) FROM notas_externas
    WHERE id IN ($ids) AND CHAR_LENGTH(competencia_nombre) > " . LARGO_RECORTE
)->fetchColumn();
echo "   $ok de " . count($reparables) . ' filas con el nombre completo'
   . ($ok === count($reparables) ? "  ✓\n" : "  ✗\n");
exit($ok === count($reparables) ? 0 : 1);
