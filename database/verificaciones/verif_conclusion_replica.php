<?php

/**
 * Verificación — conclusiones de RÉPLICA del acta SIAGIE (migración 072).
 * Uso: php database/verificaciones/verif_conclusion_replica.php
 *
 * SOLO LECTURA salvo la simulación, que va en una TRANSACCIÓN CON ROLLBACK y
 * no deja nada escrito.
 *
 * POR QUÉ EXISTE
 *   En 5.º de secundaria la nota de GAMA llena también las actas de EPT (032)
 *   y Arte y Cultura (0001). Desde el 02/10/2026, con GAMA en C el tutor
 *   escribe una conclusión PROPIA por área destino, y el cierre transversal la
 *   exige. Son tres puntos que deben estar de acuerdo —helper, control de
 *   cierre y llenador—, y el repo ya vio divergir reglas copiadas a mano.
 *
 * QUÉ COMPRUEBA
 *   1. `replicas_conclusion_acta()`: solo GAMA en 5.º (032 + 0001). Ética
 *      (035) NO es réplica: una fuente, un área.
 *   2. `destinosDeSeccion()` resuelve GAMA (CT4) y las áreas destino por su
 *      código SIAGIE en 5.º, y nada en 4.º.
 *   3. SIMULACIÓN (rollback): GAMA en C en 5.º suma 2 pendientes al control de
 *      cierre; guardarlas las descuenta; el llenador las lee; con GAMA
 *      aprobatoria no se piden; en 4.º, GAMA en C no pide réplicas.
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
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';
date_default_timezone_set(config('timezone'));

use App\Models\ConclusionReplicaModel;
use App\Models\SiagieExportModel;
use App\Models\TransversalModel;

$pdo = Core\Database::get();

$fallos = 0;
$ok = function (bool $cond, string $etiqueta, string $detalle = '') use (&$fallos): void {
    printf("  %-5s %-66s %s\n", $cond ? 'OK' : '***', $etiqueta, $detalle);
    if (!$cond) { $fallos++; }
};

echo "== Huella ==\n";
printf("  bd=%s\n\n", $pdo->query('SELECT DATABASE()')->fetchColumn());

// ── 1. Helper ────────────────────────────────────────────────────────
echo "== 1. replicas_conclusion_acta() ==\n";
$hojas = fn(string $n, int $g): array => array_column(replicas_conclusion_acta($n, $g), 'codigo_hoja');
$h5 = $hojas('sec', 5);
sort($h5);
$ok($h5 === ['0001', '032'], 'secundaria 5.º: 032 y 0001', implode(',', $h5));
$ok($hojas('sec', 4) === [], 'secundaria 4.º: sin réplicas');
$ok($hojas('sec', 3) === [], 'Ética (035) no es réplica: secundaria 3.º sin réplicas');
$ok($hojas('prim', 5) === [], 'primaria 5.º: sin réplicas');
$ok($hojas('secundaria', 5) === $hojas('sec', 5), 'acepta el nombre largo del nivel');

// ── 2. Destinos por sección ──────────────────────────────────────────
echo "\n== 2. destinosDeSeccion() ==\n";
$secciones = $pdo->query("
    SELECT s.id, s.nombre, g.numero
    FROM secciones s
    JOIN grados g  ON g.id = s.grado_id
    JOIN niveles n ON n.id = g.nivel_id AND n.codigo = 'sec'
    JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
    WHERE g.numero IN (4, 5)
    ORDER BY g.numero DESC, s.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$sec5 = null; $sec4 = null;
foreach ($secciones as $s) {
    if ((int) $s['numero'] === 5 && $sec5 === null) { $sec5 = $s; }
    if ((int) $s['numero'] === 4 && $sec4 === null) { $sec4 = $s; }
}
$ok($sec5 !== null && $sec4 !== null, 'hay secciones de 4.º y 5.º de secundaria');

$rep  = new ConclusionReplicaModel();
$gama = (int) $pdo->query("
    SELECT c.id FROM competencias c JOIN areas a ON a.id = c.area_id
    JOIN niveles n ON n.id = a.nivel_id AND n.codigo = 'sec'
    WHERE c.codigo_minedu = 'CT4'
")->fetchColumn();

$d5 = $rep->destinosDeSeccion((int) $sec5['id']);
$codigosArea = $pdo->query("
    SELECT a.id, a.codigo_siagie FROM areas a JOIN niveles n ON n.id = a.nivel_id AND n.codigo = 'sec'
")->fetchAll(PDO::FETCH_KEY_PAIR);
$ok(count($d5) === 2, '5.º: dos áreas destino', (string) count($d5));
$ok(array_unique(array_column($d5, 'competencia_id')) === [$gama], '5.º: la fuente es GAMA (CT4)', "id {$gama}");
$codDestino = array_map(fn($d) => $codigosArea[$d['area_id']] ?? '?', $d5);
sort($codDestino);
$ok($codDestino === ['0001', '032'], '5.º: áreas con código SIAGIE 032 y 0001', implode(',', $codDestino));
$ok($rep->destinosDeSeccion((int) $sec4['id']) === [], '4.º: sin áreas destino');

// ── 3. Simulación con rollback ───────────────────────────────────────
echo "\n== 3. Simulación (rollback) ==\n";
$periodo = (int) $pdo->query("
    SELECT p.id FROM periodos p JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
    WHERE p.estado = 'cerrado' ORDER BY p.numero DESC LIMIT 1
")->fetchColumn();
$trans = new TransversalModel();
$usuario = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();

/** Primer alumno de la sección con GAMA en el periodo. */
$alumnoConGama = function (int $seccionId) use ($trans, $periodo, $gama): ?int {
    foreach ($trans->getPromediosSeccion($seccionId, $periodo) as $mid => $porComp) {
        if (isset($porComp[$gama])) { return (int) $mid; }
    }
    return null;
};
$ponerGama = function (int $mid, int $nota) use ($pdo, $gama, $periodo): void {
    $pdo->prepare("UPDATE calificaciones SET nota_numerica = ? WHERE matricula_id = ? AND competencia_id = ? AND periodo_id = ?")
        ->execute([$nota, $mid, $gama, $periodo]);
};

$pdo->beginTransaction();
try {
    $sid5 = (int) $sec5['id'];
    $mid5 = $alumnoConGama($sid5);
    $ok($mid5 !== null, "5.º {$sec5['nombre']}: alumno con GAMA en el periodo {$periodo}");

    // Base con GAMA aprobatoria: no se piden réplicas (rama «dejar pasar»).
    $ponerGama($mid5, 15);
    $prom = $trans->getPromediosSeccion($sid5, $periodo);
    $ok(!isset($rep->requeridas($sid5, 'sec', $prom)[$mid5]), 'GAMA en A: no pide réplicas');
    $base = $trans->conclusionesObligatoriasPendientes($sid5, $periodo, 'sec');

    // GAMA en C: +1 de GAMA (si no tenía conclusión) +2 de réplica (rama «bloquear»).
    $ponerGama($mid5, 8);
    $prom = $trans->getPromediosSeccion($sid5, $periodo);
    $req  = $rep->requeridas($sid5, 'sec', $prom);
    $ok(isset($req[$mid5][$gama]) && count($req[$mid5][$gama]['destinos']) === 2, 'GAMA en C: pide 2 réplicas');
    $tieneGama = trim((string) ($trans->getConclusionesSeccion($sid5, $periodo)[$mid5][$gama] ?? '')) !== '';
    $conC = $trans->conclusionesObligatoriasPendientes($sid5, $periodo, 'sec');
    $esperado = $base + ($tieneGama ? 0 : 1) + 2;
    $ok($conC === $esperado, 'el control de cierre suma las 2 réplicas', "{$base} → {$conC} (esperado {$esperado})");

    // Guardar las dos: el control las descuenta y el llenador las lee.
    foreach ($req[$mid5][$gama]['destinos'] as $d) {
        $rep->guardar($mid5, $d['area_id'], $periodo, 'Prueba de réplica ' . $d['area_nombre'], $usuario);
    }
    $trasGuardar = $trans->conclusionesObligatoriasPendientes($sid5, $periodo, 'sec');
    $ok($trasGuardar === $conC - 2, 'guardadas, el control las descuenta', "{$conC} → {$trasGuardar}");
    $export = (new SiagieExportModel())->conclusionesReplica($mid5, $periodo);
    $ok(count($export) === 2 && str_starts_with(reset($export), 'Prueba de réplica'), 'el llenador lee las 2 por área', (string) count($export));

    // Texto vacío = borrar (mismo patrón que la conclusión de GAMA).
    $d0 = $req[$mid5][$gama]['destinos'][0];
    $rep->guardar($mid5, $d0['area_id'], $periodo, '   ', $usuario);
    $ok(!isset($rep->getSeccion($sid5, $periodo)[$mid5][$d0['area_id']]), 'texto vacío borra la réplica');

    // 4.º: GAMA en C no pide réplicas.
    $sid4 = (int) $sec4['id'];
    $mid4 = $alumnoConGama($sid4);
    if ($mid4 !== null) {
        $ponerGama($mid4, 8);
        $prom4 = $trans->getPromediosSeccion($sid4, $periodo);
        $ok($rep->requeridas($sid4, 'sec', $prom4) === [], '4.º con GAMA en C: sin réplicas');
    } else {
        $ok(false, "4.º {$sec4['nombre']}: no hay alumno con GAMA para simular");
    }
} finally {
    $pdo->rollBack();
}
$quedan = (int) $pdo->query("SELECT COUNT(*) FROM conclusiones_replica_acta WHERE conclusion LIKE 'Prueba de réplica%'")->fetchColumn();
$ok($quedan === 0, 'rollback: no quedó ninguna fila de prueba');

echo "\n" . ($fallos === 0 ? 'TODO OK — 0 fallo(s)' : "FALLOS: {$fallos}") . "\n";
exit($fallos === 0 ? 0 : 1);
