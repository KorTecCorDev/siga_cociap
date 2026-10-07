<?php

/**
 * Registro A POSTERIORI de los dos cambios de sección hechos a mano antes de
 * que existiera el módulo (fase 5 del plan; docs/modulos/cambio-seccion.md,
 * decisión 12). Intercambio dentro de 4.° de PRIMARIA desde el II Bimestre:
 *
 *   GALICIA MENDOZA (DNI 79674182)  4.° A → 4.° B   R.D. N° 059-2026-CAVVG-DA
 *   DIEGO LOPEZ     (DNI 79970103)  4.° B → 4.° A   R.D. N° 060-2026-CAVVG-DA
 *   Ambas R.D. del 01/09/2026. El cambio rige desde el II: lo dicen los DATOS
 *   (notas del I en la sección de origen, del II en la actual), no la fecha.
 *
 * Uso:
 *   php database/aplicar_cambios_seccion_259_339.php              → SIMULA (ensayo real + ROLLBACK)
 *   php database/aplicar_cambios_seccion_259_339.php --confirmar  → aplica y hace COMMIT
 *
 * SOLO REGISTRA EL EVENTO (una fila en `cambios_seccion` por estudiante). NO
 * mueve notas ni cambia `matriculas.seccion_id`: los estudiantes ya están en su
 * sección actual y sus notas, donde se cursaron. Por eso NO usa
 * CambioSeccionModel::ejecutar() (archivaría las notas del bimestre en curso).
 *
 * Se ancla por DNI y por NOMBRE de grado y sección, nunca por id: los ids
 * difieren entre entornos. Requiere la migración 075.
 *
 * PASOS: 0 huella del servidor · 1 veredicto por caso (aborta si alguno no es
 * PUEDE_REGISTRAR) · 2 INSERT en transacción (ROLLBACK sin --confirmar) ·
 * 3 verificación EN CONEXIÓN NUEVA.
 *
 * Reversión (si hiciera falta): DELETE de esas dos filas de `cambios_seccion`
 * (no tienen archivo asociado). Libera las R.D. 059 y 060.
 */

define('ROOT_PATH', dirname(__DIR__));
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
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';
date_default_timezone_set(config('timezone'));

$confirmar = in_array('--confirmar', $argv, true);

const NIVEL   = 'Primaria';
const GRADO   = '4°';
const PERIODO = 2;              // II Bimestre: desde aquí rige la sección nueva
const RD_FECHA = '2026-09-01';
const CASOS = [
    ['dni' => '79674182', 'origen' => 'A', 'destino' => 'B', 'rd' => 59],
    ['dni' => '79970103', 'origen' => 'B', 'destino' => 'A', 'rd' => 60],
];

function conexionFresca(): PDO
{
    $c = require CONFIG_PATH . '/database.php';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['database'], $c['charset']);
    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
}

function titulo(string $t): void {
    echo "\n" . str_repeat('=', 74) . "\n  $t\n" . str_repeat('=', 74) . "\n";
}

$pdo  = Core\Database::connect();
$tras = new App\Models\TrasladoModel();

titulo('PASO 0 — HUELLA DEL SERVIDOR (captúrala: prueba el entorno)');
$h = $pdo->query("SELECT @@hostname AS host, DATABASE() AS bd, VERSION() AS version, NOW() AS ahora")->fetch();
foreach ($h as $k => $v) { echo "   {$k}: {$v}\n"; }
echo '   modo: ' . ($confirmar ? 'APLICAR (--confirmar)' : 'SIMULACIÓN (ROLLBACK)') . "\n";

titulo('PASO 1 — VEREDICTO POR CASO');
$anio = $pdo->query("SELECT id, anio FROM anios_academicos WHERE estado = 'activo' LIMIT 1")->fetch();
$per  = $pdo->prepare("SELECT id FROM periodos WHERE anio_id = ? AND numero = ?");
$per->execute([(int) $anio['id'], PERIODO]);
$periodoId = (int) $per->fetchColumn();
$movidoPor = (int) $pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                                WHERE r.codigo = 'admin' AND u.estado = 'activo' ORDER BY u.id LIMIT 1")->fetchColumn();
$sufijo = config('institucion_datos')['sufijo_constancia'] ?? 'CAVVG-DA';

$secc = $pdo->prepare("SELECT s.id FROM secciones s JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
                       WHERE s.anio_id = ? AND n.nombre = ? AND g.nombre_display = ? AND s.nombre = ?");
$notasEn = $pdo->prepare("SELECT COUNT(*) FROM calificaciones c
                          JOIN cargas_academicas ca ON ca.id = c.carga_id
                          JOIN periodos p ON p.id = c.periodo_id
                          WHERE c.matricula_id = ? AND ca.seccion_id = ? AND p.numero = ? AND p.anio_id = ?");

$plan = [];
$abortar = false;
foreach (CASOS as $caso) {
    $m = $pdo->prepare("SELECT m.id, m.seccion_id, m.estado, m.tipo,
                               CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS estudiante
                        FROM matriculas m JOIN estudiantes e ON e.id = m.estudiante_id
                        JOIN personas p ON p.id = e.persona_id
                        WHERE p.dni = ? AND m.anio_id = ?");
    $m->execute([$caso['dni'], (int) $anio['id']]);
    $mat = $m->fetch();
    $ids = [];
    foreach (['origen', 'destino'] as $lado) {
        $secc->execute([(int) $anio['id'], NIVEL, GRADO, $caso[$lado]]);
        $ids[$lado] = (int) $secc->fetchColumn();
    }
    $veredicto = 'PUEDE_REGISTRAR';
    $detalle = '';
    if (!$mat) {
        $veredicto = 'NO_TOCAR_SIN_MATRICULA';
    } elseif (!$ids['origen'] || !$ids['destino'] || !$periodoId) {
        $veredicto = 'NO_TOCAR_SECCION_O_PERIODO_NO_EXISTE';
    } elseif ((int) $mat['seccion_id'] !== $ids['destino']) {
        $veredicto = 'NO_TOCAR_NO_ESTA_EN_DESTINO';
    } elseif ((int) $pdo->query("SELECT COUNT(*) FROM cambios_seccion WHERE matricula_id = {$mat['id']}")->fetchColumn() > 0) {
        $veredicto = 'YA_REGISTRADO';
    } elseif (!$tras->correlativoDisponible((int) $anio['id'], $caso['rd'])) {
        $veredicto = 'NO_TOCAR_RD_OCUPADA';
    } else {
        $notasEn->execute([(int) $mat['id'], $ids['origen'], 1, (int) $anio['id']]);
        $iEnOrigen = (int) $notasEn->fetchColumn();
        $notasEn->execute([(int) $mat['id'], $ids['destino'], PERIODO, (int) $anio['id']]);
        $iiEnDestino = (int) $notasEn->fetchColumn();
        $detalle = "notas del I en origen: {$iEnOrigen} · del II en destino: {$iiEnDestino}";
        if ($iEnOrigen === 0 || $iiEnDestino === 0) {
            $veredicto = 'NO_TOCAR_LOS_DATOS_NO_LO_CONFIRMAN';
        }
    }
    printf("   %s · %s %s → %s · R.D. %03d · %s\n     %s\n",
        $mat['estudiante'] ?? $caso['dni'], GRADO, $caso['origen'], $caso['destino'], $caso['rd'],
        $veredicto, $detalle);
    if ($veredicto !== 'PUEDE_REGISTRAR') {
        $abortar = $abortar || $veredicto !== 'YA_REGISTRADO';
        continue;
    }
    $plan[] = $caso + ['matricula_id' => (int) $mat['id'], 'ids' => $ids];
}
if ($abortar) {
    echo "\n   ABORTADO: algún caso no se puede registrar. No se escribió nada.\n";
    exit(1);
}
if ($plan === []) {
    echo "\n   Nada que hacer: ya estaban registrados.\n";
    exit(0);
}

titulo('PASO 2 — INSERT ' . ($confirmar ? '(COMMIT)' : '(SIMULACIÓN → ROLLBACK)'));
$pdo->beginTransaction();
$ins = $pdo->prepare("INSERT INTO cambios_seccion
    (matricula_id, anio_id, periodo_id, seccion_origen_id, seccion_destino_id,
     rd_correlativo, rd_numero, rd_fecha, motivo, movido_por)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($plan as $c) {
    $numero = App\Models\TrasladoModel::formatearNumero($c['rd'], (int) $anio['anio'], $sufijo);
    $ins->execute([
        $c['matricula_id'], (int) $anio['id'], $periodoId, $c['ids']['origen'], $c['ids']['destino'],
        $c['rd'], $numero, RD_FECHA,
        'Registro a posteriori: el cambio de sección se hizo antes de existir el módulo; '
            . 'el estudiante cursa en ' . GRADO . ' ' . $c['destino'] . ' desde el II Bimestre.',
        $movidoPor,
    ]);
    echo "   + matrícula {$c['matricula_id']}: {$numero}\n";
}
$cs = new App\Models\CambioSeccionModel();
foreach ($plan as $c) {
    $sI  = $cs->seccionDelPeriodo($c['matricula_id'], (int) $pdo->query(
        "SELECT id FROM periodos WHERE anio_id = {$anio['id']} AND numero = 1")->fetchColumn());
    $sII = $cs->seccionDelPeriodo($c['matricula_id'], $periodoId);
    $bien = $sI === $c['ids']['origen'] && $sII === $c['ids']['destino'];
    echo '   ' . ($bien ? '[OK]' : '[FALLA]') . " matrícula {$c['matricula_id']}: I → sección {$sI}, II → sección {$sII}\n";
    if (!$bien) { $pdo->rollBack(); echo "   ABORTADO.\n"; exit(1); }
}
if ($confirmar) {
    $pdo->commit();
} else {
    $pdo->rollBack();
    echo "\n   SIMULACIÓN: nada quedó escrito. Repite con --confirmar para aplicar.\n";
    exit(0);
}

titulo('PASO 3 — VERIFICACIÓN EN CONEXIÓN NUEVA');
$f = conexionFresca();
foreach ($f->query("SELECT cs.matricula_id, cs.rd_numero, cs.rd_fecha, cs.estado, so.nombre AS origen, sd.nombre AS destino
                    FROM cambios_seccion cs JOIN secciones so ON so.id = cs.seccion_origen_id
                    JOIN secciones sd ON sd.id = cs.seccion_destino_id
                    ORDER BY cs.id") as $r) {
    echo "   matrícula {$r['matricula_id']} · {$r['origen']} → {$r['destino']} · {$r['rd_numero']} del {$r['rd_fecha']} · {$r['estado']}\n";
}
