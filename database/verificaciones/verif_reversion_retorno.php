<?php

/**
 * Verificación — REVERSIÓN de un retorno de grado (05/10/2026).
 * Uso: php database/verificaciones/verif_reversion_retorno.php
 *
 * NO ESCRIBE NADA. Todo va en transacciones que nunca se confirman: la
 * simulación de la alerta hace ROLLBACK, y cada caso del controlador corre en
 * un SUBPROCESO cuya conexión ignora los commit (al salir, MySQL deshace todo).
 * Lleva el guard de secretos: no se corre en producción.
 *
 * POR QUÉ EXISTE
 *   El 05/10/2026 se pidió revertir el retorno #1 porque la estudiante había
 *   vuelto a su grado oficial al inicio del III Bimestre y se comunicó tarde.
 *   Revertir con un bimestre en curso destapó tres huecos:
 *     - la alerta de evaluación incompleta solo conocía el retorno ACTIVO: la
 *       operativa revertida salía incompleta en cada criterio nuevo de su ex
 *       sección y bloqueaba el cierre del bimestre de todo el colegio;
 *     - revertir() no devolvía la asistencia ni la conducta del bimestre en
 *       curso: quedaban en la operativa, fuera de todo roster;
 *     - el candado solo miraba `calificaciones`.
 *
 * QUÉ COMPRUEBA (las dos ramas de cada guarda)
 *   1. ALERTA: con el retorno activo la operativa sale incompleta en un criterio
 *      nuevo de su sección; revertido, ya no. Y la alerta usa el punto único.
 *   2. MOVIMIENTO: revertir mueve la asistencia y la conducta del bimestre en
 *      curso a la oficial, CON su confirmación, o COMO BORRADOR con la casilla.
 *   3. GUARDAS: rechaza con un cierre de conducta vigente, con un criterio con
 *      nota sin promedio y con filas propias de la oficial; y no toca nada.
 *   4. TRAMO REAL (08/10/2026): en los retornos de la BD, uno activo tiene el
 *      tramo abierto y uno revertido lo cierra en un bimestre CERRADO. Si no, el
 *      roster del bimestre en curso pone al estudiante en la sección equivocada.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
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

use App\Controllers\Matricula\RetornoGradoController;
use App\Models\ControlOperativoModel;

const TABLAS = ['inasistencias', 'asistencia_incidencias', 'asistencia_presencias', 'conducta_respuestas',
                'conducta_confirmaciones', 'calificaciones_conducta'];

/** Retorno a probar: el activo más reciente o, si no hay, el último (se reactiva en la simulación). */
function retornoDePrueba(PDO $pdo): ?array
{
    $r = $pdo->query("
        SELECT r.id, r.estado, r.matricula_oficial_id AS oficial, r.matricula_operativa_id AS operativa,
               mo.seccion_id AS sec_operativa, mf.seccion_id AS sec_oficial, mf.anio_id
        FROM retornos_grado r
        JOIN matriculas mo ON mo.id = r.matricula_operativa_id
        JOIN matriculas mf ON mf.id = r.matricula_oficial_id
        ORDER BY (r.estado = 'activo') DESC, r.id DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Deja el retorno como estaba ANTES de revertir (dentro de la transacción):
 * activo, tramo abierto, operativa aprobada, los registros de los bimestres sin
 * cerrar de vuelta en la operativa y sin cierres vigentes en sus secciones.
 */
function prepararActivo(PDO $pdo, array $r): void
{
    $pdo->exec("UPDATE retornos_grado SET estado = 'activo', fecha_reversion = NULL, periodo_hasta_id = NULL,
                motivo_reversion = NULL, revertido_por = NULL WHERE id = {$r['id']}");
    $pdo->exec("UPDATE matriculas SET estado = 'aprobada' WHERE id = {$r['operativa']}");
    foreach (TABLAS as $t) {
        $pdo->exec("UPDATE {$t} SET matricula_id = {$r['operativa']} WHERE matricula_id = {$r['oficial']}
                    AND periodo_id IN (SELECT id FROM periodos WHERE estado <> 'cerrado')");
    }
    foreach (['cierres_conducta', 'cierres_asistencia'] as $t) {
        $pdo->exec("UPDATE {$t} SET anulado_en = NOW() WHERE anulado_en IS NULL
                    AND seccion_id IN ({$r['sec_operativa']}, {$r['sec_oficial']})
                    AND periodo_id IN (SELECT id FROM periodos WHERE estado <> 'cerrado')");
    }
}

/** Criterio nuevo de un periodo sin cerrar en la sección operativa (fixture). */
function criterioNuevo(PDO $pdo, array $r): ?array
{
    $base = $pdo->query("
        SELECT ca.id AS carga_id, c.id AS comp_id, p.id AS periodo_id
        FROM cargas_academicas ca
        JOIN competencias c ON (ca.subarea_id IS NOT NULL AND c.subarea_id = ca.subarea_id)
                            OR (ca.subarea_id IS NULL AND c.area_id = ca.area_id)
        JOIN areas a ON a.id = COALESCE((SELECT sa.area_id FROM subareas sa WHERE sa.id = c.subarea_id), c.area_id)
        JOIN periodos p ON p.estado = 'activo' AND p.anio_id = ca.anio_id
        WHERE ca.seccion_id = {$r['sec_operativa']} AND ca.estado = 'activa'
          AND a.tipo NOT IN ('transversal', 'tutoria')
        ORDER BY ca.id, c.id LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$base) { return null; }
    $pdo->prepare("INSERT INTO criterios (carga_id, competencia_id, periodo_id, nombre, orden)
                   VALUES (?, ?, ?, 'VERIF reversion', 99)")
        ->execute([$base['carga_id'], $base['comp_id'], $base['periodo_id']]);
    $base['criterio_id'] = (int) $pdo->lastInsertId();
    return $base;
}

// ── Modo SUBPROCESO: ejecuta el revertir() REAL ──────────────────────────
// redirect() termina con `exit`, así que cada caso corre en su propio proceso.
// La conexión es la de la app, pero ignora beginTransaction/commit/rollBack del
// controlador: hay UNA transacción real que nunca se confirma.
if (($argv[1] ?? '') === '--caso') {
    $caso = $argv[2];
    $cfg  = require CONFIG_PATH . '/database.php';
    $pdo  = new class (
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"]
    ) extends PDO {
        public function beginTransaction(): bool { return true; }
        public function commit(): bool { return true; }
        public function rollBack(): bool { return true; }
        public function abrirReal(): void { parent::beginTransaction(); }
    };
    $pdo->abrirReal();
    (new ReflectionProperty(Core\Database::class, 'instance'))->setValue(null, $pdo);

    $r = retornoDePrueba($pdo);
    prepararActivo($pdo, $r);
    $enCurso = implode(',', $pdo->query("SELECT id FROM periodos WHERE estado <> 'cerrado'")->fetchAll(PDO::FETCH_COLUMN));

    if ($caso === 'cierre') {
        $per = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO cierres_conducta (seccion_id, periodo_id, ra_bloqueado_en, ra_bloqueado_por)
                    VALUES ({$r['sec_operativa']}, {$per}, NOW(), 1)");
    } elseif ($caso === 'criterio') {
        $c = criterioNuevo($pdo, $r);
        $pdo->exec("INSERT INTO calificaciones_criterio (criterio_id, matricula_id, nota, registrado_en)
                    VALUES ({$c['criterio_id']}, {$r['operativa']}, 15, NOW())");
    } elseif ($caso === 'oficial_propia') {
        $per = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO inasistencias (matricula_id, periodo_id, registrado_por)
                    VALUES ({$r['oficial']}, {$per}, 1)");
    }

    $antes = [];
    foreach (TABLAS as $t) {
        $antes[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t} WHERE matricula_id = {$r['operativa']} AND periodo_id IN ({$enCurso})")->fetchColumn();
    }
    $antes['asistencia_confirmada'] = (int) $pdo->query("SELECT COUNT(*) FROM inasistencias WHERE matricula_id = {$r['operativa']} AND periodo_id IN ({$enCurso}) AND confirmado_en IS NOT NULL")->fetchColumn();

    register_shutdown_function(function () use ($pdo, $r, $enCurso, $antes): void {
        $estado = static fn(int $m, string $t): int
            => (int) $pdo->query("SELECT COUNT(*) FROM {$t} WHERE matricula_id = {$m} AND periodo_id IN ({$enCurso})")->fetchColumn();
        $out = [
            'flash_error' => $_SESSION['_flash']['error'] ?? null,
            'flash_ok'    => $_SESSION['_flash']['success'] ?? null,
            'retorno'     => $pdo->query("SELECT estado FROM retornos_grado WHERE id = {$r['id']}")->fetchColumn(),
            'hasta'       => $pdo->query("SELECT periodo_hasta_id FROM retornos_grado WHERE id = {$r['id']}")->fetchColumn(),
            'hasta_esperado' => $pdo->query("SELECT p.id FROM periodos p JOIN retornos_grado r ON r.id = {$r['id']}
                                JOIN periodos pd ON pd.id = r.periodo_desde_id
                                WHERE p.anio_id = pd.anio_id AND p.estado = 'cerrado' AND p.numero >= pd.numero
                                ORDER BY p.numero DESC LIMIT 1")->fetchColumn(),
            'operativa'   => $pdo->query("SELECT estado FROM matriculas WHERE id = {$r['operativa']}")->fetchColumn(),
            'antes'       => $antes,
            'oficial'     => [], 'en_operativa' => [],
            'oficial_asistencia_confirmada' => (int) $pdo->query("SELECT COUNT(*) FROM inasistencias WHERE matricula_id = {$r['oficial']} AND periodo_id IN ({$enCurso}) AND confirmado_en IS NOT NULL")->fetchColumn(),
        ];
        foreach (TABLAS as $t) {
            $out['oficial'][$t]      = $estado((int) $r['oficial'], $t);
            $out['en_operativa'][$t] = $estado((int) $r['operativa'], $t);
        }
        echo json_encode($out);
    });

    $_SESSION['auth_user']   = ['id' => 1, 'rol_codigo' => 'admin', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
    $_SESSION['_csrf_token'] = 'verif';
    $_POST = ['_csrf_token' => 'verif', 'motivo' => 'VERIF reversion'];
    if ($caso === 'borrador') { $_POST['como_borrador'] = '1'; }
    (new RetornoGradoController())->revertir((string) $r['oficial']);
    exit(0);
}

// ── Proceso principal ───────────────────────────────────────────────────
$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo = Core\Database::connect();
printf("bd=%s\n\n", $pdo->query('SELECT DATABASE()')->fetchColumn());

$r = retornoDePrueba($pdo);
if (!$r) {
    echo "Sin retornos de grado en la BD: nada que verificar.\n";
    exit(0);
}
printf("Retorno #%d (%s): oficial %d, operativa %d\n\n", $r['id'], $r['estado'], $r['oficial'], $r['operativa']);

// ── 1. Alerta de evaluación incompleta ──────────────────────────────────
echo "1. Alerta de evaluación incompleta con el anclaje por bimestre\n";
$pdo->beginTransaction();
try {
    prepararActivo($pdo, $r);
    $c = criterioNuevo($pdo, $r);
    if (!$c) {
        $chk('hay una carga activa en la sección operativa para el fixture', false);
    } else {
        // Un compañero con nota: el criterio «se evaluó» y a la operativa le falta.
        $comp = (int) $pdo->query("SELECT id FROM matriculas WHERE seccion_id = {$r['sec_operativa']}
                                   AND id <> {$r['operativa']} " . roster_evaluacion('matriculas') . " LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO calificaciones_criterio (criterio_id, matricula_id, nota, registrado_en)
                    VALUES ({$c['criterio_id']}, {$comp}, 15, NOW())");
        $co = new ControlOperativoModel();
        $enAlerta = fn(): array => array_column($co->alertasEvaluacionIncompleta((int) $c['periodo_id']), 'matricula_id');

        $chk('retorno ACTIVO: la operativa sale incompleta (se evalúa allí)', in_array((int) $r['operativa'], array_map('intval', $enAlerta()), true));
        $pdo->exec("UPDATE retornos_grado SET estado = 'revertido' WHERE id = {$r['id']}");
        $pdo->exec("UPDATE matriculas SET estado = 'desactivado' WHERE id = {$r['operativa']}");
        $chk('retorno REVERTIDO: la operativa ya NO sale (antes bloqueaba el cierre)', !in_array((int) $r['operativa'], array_map('intval', $enAlerta()), true));
    }
} finally {
    $pdo->rollBack();
}
$src = file_get_contents(APP_PATH . '/Models/ControlOperativoModel.php');
$chk('la alerta usa el punto único RetornoGradoModel::sqlCursoElPeriodo', str_contains($src, 'RetornoGradoModel::sqlCursoElPeriodo('));
$chk('y no conserva la copia a mano de la mitad «activo»',
    !str_contains($src, "AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado WHERE estado = 'activo')"));

// ── 2 y 3. revertir() real, en subproceso ───────────────────────────────
$llamar = static function (string $caso): array {
    $cmd = sprintf('php %s --caso %s', escapeshellarg(__FILE__), $caso);
    $j   = json_decode((string) shell_exec($cmd), true);
    return is_array($j) ? $j : ['flash_error' => '(sin JSON)', 'retorno' => null];
};
$retornosAntes = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, estado)) FROM retornos_grado")->fetchColumn();

echo "\n2. Movimiento de la asistencia y la conducta del bimestre en curso\n";
foreach (['confirmado' => false, 'borrador' => true] as $caso => $borrador) {
    $s = $llamar($caso);
    $chk("[{$caso}] revierte sin error", $s['retorno'] === 'revertido' && $s['flash_error'] === null, (string) ($s['flash_error'] ?? ''));
    $chk("[{$caso}] la operativa queda desactivada", ($s['operativa'] ?? null) === 'desactivado');
    $chk("[{$caso}] el tramo se cierra en el último bimestre cerrado (" . var_export($s['hasta_esperado'] ?? null, true) . ")",
        ($s['hasta'] ?? 'x') === ($s['hasta_esperado'] ?? 'y'));
    $hayAlgo = array_sum(array_intersect_key($s['antes'] ?? [], array_flip(TABLAS))) > 0;
    if (!$hayAlgo) {
        echo "  [-- ] [{$caso}] la operativa no tiene registros en curso: movimiento OMITIDO\n";
        continue;
    }
    foreach (TABLAS as $t) {
        $esperado = ($borrador && $t === 'conducta_confirmaciones') ? 0 : $s['antes'][$t];
        $chk("[{$caso}] {$t}: {$s['antes'][$t]} → oficial {$s['oficial'][$t]}, operativa {$s['en_operativa'][$t]}",
            $s['oficial'][$t] === $esperado && $s['en_operativa'][$t] === 0);
    }
    $esperadoConf = $borrador ? 0 : $s['antes']['asistencia_confirmada'];
    $chk("[{$caso}] asistencia confirmada en la oficial = {$esperadoConf}", $s['oficial_asistencia_confirmada'] === $esperadoConf);
}

echo "\n3. Guardas: rechazan y no tocan nada\n";
foreach ([
    'cierre'         => 'registros cerrados',
    'criterio'       => 'ya tiene evaluación',
    'oficial_propia' => 'registros propios',
] as $caso => $frase) {
    $s = $llamar($caso);
    $chk("[{$caso}] rechaza", str_contains((string) $s['flash_error'], $frase), (string) $s['flash_error']);
    $chk("[{$caso}] el retorno sigue activo y nada se movió",
        $s['retorno'] === 'activo' && ($s['en_operativa'] ?? null) == array_intersect_key($s['antes'] ?? [], array_flip(TABLAS)));
}

$chk("\nlos subprocesos no dejaron nada escrito",
    $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, estado)) FROM retornos_grado")->fetchColumn() === $retornosAntes
    && (int) $pdo->query("SELECT COUNT(*) FROM criterios WHERE nombre = 'VERIF reversion'")->fetchColumn() === 0);

// ── 4. TRAMO de los retornos REALES (08/10/2026) ───────────────────────
// El roster de cada bimestre (`sqlRosterDelPeriodo`) da lo mismo que
// `roster_evaluacion()` en el bimestre en curso SOLO si el tramo de un retorno
// revertido termina en un bimestre CERRADO, que es lo que pone `revertir()`.
// El relleno de la 073 dejó en una BD local `hasta` = el III sin cerrar: la
// estudiante salía en 1.° B y faltaba en 2.° B (asistencia, conducta y notas),
// y ninguna verificación del retorno lo veía porque todas usan fixtures.
echo "\n4. Tramo guardado de los retornos reales\n";
$malos = $pdo->query("
    SELECT r.id, r.estado, pd.numero AS desde, ph.numero AS hasta, ph.estado AS estado_hasta
    FROM retornos_grado r
    INNER JOIN periodos pd ON pd.id = r.periodo_desde_id
    LEFT  JOIN periodos ph ON ph.id = r.periodo_hasta_id
    WHERE (r.estado = 'activo'    AND r.periodo_hasta_id IS NOT NULL)
       OR (r.estado = 'revertido' AND r.periodo_hasta_id IS NOT NULL
           AND (ph.estado <> 'cerrado' OR ph.numero < pd.numero))
")->fetchAll(PDO::FETCH_ASSOC);
$detalle = implode('; ', array_map(static fn(array $x): string =>
    "#{$x['id']} {$x['estado']} desde {$x['desde']} hasta {$x['hasta']} ({$x['estado_hasta']})", $malos));
$chk('un retorno ACTIVO tiene el tramo abierto, y uno REVERTIDO lo cierra en un bimestre CERRADO ≥ desde '
    . '(si falla: corregir el tramo, ver database/reparar_retorno_1_tramo.sql)', $malos === [], $detalle);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
