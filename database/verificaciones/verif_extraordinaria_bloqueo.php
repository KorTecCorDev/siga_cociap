<?php

/**
 * Verificación — una CALIFICACIÓN EXTRAORDINARIA asegura el bloqueo de su
 * competencia (21/09/2026).
 * Uso: php database/verificaciones/verif_extraordinaria_bloqueo.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila. El DELETE de un bloqueo que hace para montar
 * el escenario también se revierte (regla del proyecto: nunca limpiar con
 * DELETE lo que no creó FUERA de una transacción).
 *
 * EL HUECO QUE CIERRA (calificaciones.md, «Hueco conocido»)
 *   `escribirExtraordinaria` no creaba la fila de `bloqueos_competencia`, y la
 *   boleta solo muestra competencias bloqueadas. Una competencia que nadie de
 *   la sección evaluó pierde su bloqueo del cierre al limpiar los fantasmas;
 *   si RA le registraba una extraordinaria, la nota quedaba INVISIBLE.
 *
 * Se ejercita el método REAL (`RectificacionController::escribirExtraordinaria`,
 * privado) por reflexión, no una copia de sus pasos.
 *
 * QUÉ COMPRUEBA — las DOS ramas
 *   A. Competencia SIN bloqueo: tras escribir, existe el bloqueo con
 *      origen='cierre' y la nota APARECE en la boleta.
 *   B. Competencia YA bloqueada: sigue habiendo UNA fila y su origen no cambia.
 *   C. `eliminarBloqueosDeCierre` (limpieza de fantasmas) NO borra el bloqueo
 *      creado en A.
 *   D. ROLLBACK: todo vuelve a su estado inicial.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VIEW_PATH', ROOT_PATH . '/resources/views');

spl_autoload_register(function (string $class): void {
    $map = [
        'Core\\'            => CORE_PATH . '/',
        'App\\Models\\'      => APP_PATH . '/Models/',
        'App\\Controllers\\' => APP_PATH . '/Controllers/',
    ];
    foreach ($map as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require_once $file; return; }
        }
    }
});
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';

$pdo     = Core\Database::connect();
$rectMod = new App\Models\RectificacionModel();
$boletas = new App\Models\BoletaModel();
$anioMod = new App\Models\AnioAcademicoModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};

// Controlador real sin constructor (el constructor exige sesión), con sus
// modelos inyectados por reflexión.
$ctrlRef = new ReflectionClass(App\Controllers\Rectificacion\RectificacionController::class);
$ctrl    = $ctrlRef->newInstanceWithoutConstructor();
foreach ([
    'model'            => $rectMod,
    'calModel'         => new App\Models\CalificacionModel(),
    'critModel'        => new App\Models\CriterioModel(),
    'ordenMeritoModel' => new App\Models\OrdenMeritoModel(),
    'transModel'       => new App\Models\TransversalModel(),
] as $prop => $valor) {
    $p = $ctrlRef->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($ctrl, $valor);
}
$escribir = $ctrlRef->getMethod('escribirExtraordinaria');
$escribir->setAccessible(true);

// ── Sujeto: la matrícula con más competencias insertables ordinarias ─────
$sujeto = null;
foreach ($rectMod->matriculasSinNotasEnCerrados([]) as $f) {
    $items = array_values(array_filter(
        $rectMod->getCompetenciasInsertables((int) $f['matricula_id']),
        static fn (array $c): bool => (int) $c['periodo_id'] === (int) $f['periodo_id']
    ));
    if (count($items) >= 2) { $sujeto = ['mid' => (int) $f['matricula_id'], 'items' => $items]; break; }
}
if ($sujeto === null) {
    echo "No hay matrícula con 2+ competencias insertables. Nada que verificar.\n";
    exit(0);
}
$mid     = $sujeto['mid'];
$usuario = (int) $pdo->query("SELECT id FROM usuarios WHERE rol_id = 1 LIMIT 1")->fetchColumn();
[$itA, $itB] = [$sujeto['items'][0], $sujeto['items'][1]];
$periodo = (int) $itA['periodo_id'];
echo "\n  Sujeto: matrícula {$mid}, periodo {$periodo}.\n";

$bloqueo = function (array $it) use ($pdo, $periodo): array {
    $st = $pdo->prepare("SELECT origen FROM bloqueos_competencia
                         WHERE carga_id = ? AND competencia_id = ? AND periodo_id = ?");
    $st->execute([(int) $it['carga_id'], (int) $it['competencia_id'], $periodo]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$celda = function (?array $b, int $compId) use ($periodo) {
    foreach ($b['areas'] ?? [] as $competencias) {
        if (isset($competencias[$compId])) {
            return $competencias[$compId]['bimestres'][$periodo]['nota'] ?? null;
        }
    }
    return null;
};

$antes = [
    'bloqueos_competencia' => $contar("SELECT COUNT(*) FROM bloqueos_competencia"),
    'calificaciones'       => $contar("SELECT COUNT(*) FROM calificaciones"),
    'rectificaciones_calificacion' => $contar("SELECT COUNT(*) FROM rectificaciones_calificacion"),
];

$pdo->beginTransaction();
try {
    echo "\n=== A. Competencia SIN bloqueo: se crea y la nota llega a la boleta ===\n";
    // Escenario: si la competencia A está bloqueada, se le quita el bloqueo
    // DENTRO de la transacción (el ROLLBACK lo devuelve).
    $pdo->prepare("DELETE FROM bloqueos_competencia WHERE carga_id = ? AND competencia_id = ? AND periodo_id = ?")
        ->execute([(int) $itA['carga_id'], (int) $itA['competencia_id'], $periodo]);
    $ok($bloqueo($itA) === [], 'antes: la competencia A no tiene bloqueo');

    $escribir->invoke($ctrl, $mid, (int) $itA['carga_id'], (int) $itA['competencia_id'], $periodo,
        16, '', 'Verificación automática (se revierte).', $usuario, false);

    $ok($bloqueo($itA) === ['cierre'], "después: UN bloqueo con origen 'cierre' (" . implode(',', $bloqueo($itA)) . ')');
    $nota = $celda($boletas->armar($mid, $periodo, 'archivo', true), (int) $itA['competencia_id']);
    $ok((int) $nota === 16, 'la nota 16 APARECE en la boleta (' . var_export($nota, true) . ')');

    echo "\n=== B. Competencia YA bloqueada: no se duplica ni cambia su origen ===\n";
    $origenB = $bloqueo($itB);
    if ($origenB === []) {
        echo "     (la competencia B no tenía bloqueo en esta BD: se le crea uno 'docente' para la rama)\n";
        $pdo->prepare("INSERT INTO bloqueos_competencia (carga_id, competencia_id, periodo_id, bloqueado_por, origen)
                       VALUES (?, ?, ?, ?, 'docente')")
            ->execute([(int) $itB['carga_id'], (int) $itB['competencia_id'], $periodo, $usuario]);
        $origenB = ['docente'];
    }
    $escribir->invoke($ctrl, $mid, (int) $itB['carga_id'], (int) $itB['competencia_id'], $periodo,
        12, '', 'Verificación automática (se revierte).', $usuario, false);
    $ok($bloqueo($itB) === $origenB, 'sigue UNA fila con el mismo origen (' . implode(',', $bloqueo($itB)) . ')');

    echo "\n=== C. La limpieza de fantasmas NO borra el bloqueo creado ===\n";
    $anioMod->eliminarBloqueosDeCierre($periodo);
    $ok($bloqueo($itA) === ['cierre'], 'el bloqueo de A sobrevive a eliminarBloqueosDeCierre');
} catch (Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $fallos++;
} finally {
    $pdo->rollBack();
}

echo "\n=== D. ROLLBACK — la base vuelve a su estado inicial ===\n";
foreach ($antes as $tabla => $v) {
    $final = $contar("SELECT COUNT(*) FROM {$tabla}");
    $ok($final === $v, "{$tabla}: {$v} → {$final}");
}

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — la extraordinaria ya no queda invisible.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
