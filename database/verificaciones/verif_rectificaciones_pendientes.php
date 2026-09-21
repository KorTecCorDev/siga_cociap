<?php

/**
 * Verificación — LISTADO de /rectificaciones: estudiantes SIN NINGUNA nota en un
 * bimestre cerrado (21/09/2026).
 * Uso: php database/verificaciones/verif_rectificaciones_pendientes.php
 *
 * SOLO LECTURA: no escribe nada. Se puede correr en PRODUCCIÓN.
 *
 * QUÉ COMPRUEBA
 *   1. El listado coincide con un CONTROL escrito aquí a mano (no derivado del
 *      modelo): pares (matrícula, bimestre cerrado) sin ninguna fila en
 *      `calificaciones`, del roster de evaluación del año activo.
 *   2. El «Calificar (N)» de cada fila es EXACTAMENTE el total que da
 *      `insertablesPorPeriodo` a esa matrícula y bimestre: ficha, lote y
 *      listado leen el mismo SQL.
 *   3. Punto único de "insertable": `esInsertable` ya no lleva su copia a mano
 *      de la consulta; pregunta a `getCompetenciasInsertables`. Y sus DOS ramas
 *      siguen respondiendo: una tupla insertable real → true; la misma con otra
 *      competencia ya calificada → false.
 *   4. Los filtros: cada uno reduce al subconjunto correcto, y un ámbito
 *      inexistente da 0 filas (no el listado entero).
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

$pdo  = Core\Database::connect();
$rect = new App\Models\RectificacionModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$pares = static fn(array $filas): array => array_map(
    static fn(array $f): string => (int) $f['matricula_id'] . '-' . (int) $f['periodo_id'],
    $filas
);

echo "\n=== 1. El listado contra un control escrito a mano ===\n";
$listado = $rect->matriculasSinNotasEnCerrados([]);
$control = $pdo->query("
    SELECT m.id AS matricula_id, per.id AS periodo_id
    FROM matriculas m
    JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    JOIN periodos per ON per.anio_id = m.anio_id AND per.estado = 'cerrado'
    WHERE m.tipo NOT IN ('trasladado', 'retirado')
      AND m.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')
      AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido')
      AND NOT EXISTS (SELECT 1 FROM calificaciones c
                      WHERE c.matricula_id = m.id AND c.periodo_id = per.id)
")->fetchAll(PDO::FETCH_ASSOC);
$a = $pares($listado); sort($a);
$b = $pares($control); sort($b);
$ok($a === $b, 'mismos pares (matrícula, bimestre) que el control: ' . count($a) . ' / ' . count($b));
$ok(count($a) === count(array_unique($a)), 'sin filas repetidas');

echo "\n=== 2. «Calificar (N)» = insertablesPorPeriodo ===\n";
$difieren = 0;
foreach ($listado as $f) {
    $esperado = 0;
    foreach ($rect->insertablesPorPeriodo((int) $f['matricula_id']) as $p) {
        if ((int) $p['periodo_id'] === (int) $f['periodo_id']) { $esperado = (int) $p['total']; }
    }
    if ($esperado !== (int) $f['total']) {
        $difieren++;
        echo "     {$f['matricula_id']} / periodo {$f['periodo_id']}: listado {$f['total']} vs ficha {$esperado}\n";
    }
}
$ok($difieren === 0, 'el conteo de cada fila coincide con el de la ficha (' . count($listado) . ' filas)');

echo "\n=== 3. Punto único de «insertable» y las dos ramas de esInsertable ===\n";
$src = file_get_contents(APP_PATH . '/Models/RectificacionModel.php');
$ok(substr_count($src, "AND a.tipo <> 'transversal'") === 1,
    'la condición de ordinarias vive en UN solo SQL (sqlInsertables)');
$ok(substr_count($src, 'INNER JOIN cierres_transversales ct') === 1,
    'la de transversales, también (sqlTransversalesInsertables)');

$candidata = null;
foreach ($listado as $f) {
    $ins = $rect->getCompetenciasInsertables((int) $f['matricula_id']);
    if ($ins) { $candidata = $ins[0]; break; }
}
if ($candidata === null) {
    echo "     (no hay tupla insertable en esta BD: rama TRUE sin probar)\n";
} else {
    $ok($rect->esInsertable((int) $candidata['matricula_id'], (int) $candidata['carga_id'],
            (int) $candidata['competencia_id'], (int) $candidata['periodo_id']) === true,
        'rama TRUE: una tupla del universo insertable se acepta');
}
$yaCalificada = $pdo->query("
    SELECT cal.matricula_id, cal.carga_id, cal.competencia_id, cal.periodo_id
    FROM calificaciones cal JOIN periodos per ON per.id = cal.periodo_id AND per.estado = 'cerrado'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$ok($yaCalificada && $rect->esInsertable((int) $yaCalificada['matricula_id'], (int) $yaCalificada['carga_id'],
        (int) $yaCalificada['competencia_id'], (int) $yaCalificada['periodo_id']) === false,
    'rama FALSE: una competencia ya calificada NO es insertable');

echo "\n=== 4. Filtros ===\n";
$periodos = array_unique(array_map('intval', array_column($listado, 'periodo_id')));
foreach ($periodos as $pid) {
    $sub = $rect->matriculasSinNotasEnCerrados(['periodo_id' => $pid]);
    $esp = array_values(array_filter($listado, static fn($f) => (int) $f['periodo_id'] === $pid));
    $ok($pares($sub) === $pares($esp), "bimestre {$pid}: " . count($sub) . ' filas, todas de ese bimestre');
}
if ($listado) {
    $sec = (int) $pdo->query('SELECT seccion_id FROM matriculas WHERE id = ' . (int) $listado[0]['matricula_id'])->fetchColumn();
    $sub = $rect->matriculasSinNotasEnCerrados(['seccion_id' => $sec, 'nivel_id' => 999999]);
    $ok($sub !== [] && in_array((int) $listado[0]['matricula_id'], array_map('intval', array_column($sub, 'matricula_id')), true),
        'la sección manda sobre un nivel incoherente (no devuelve 0 por el nivel)');
}
$ok($rect->matriculasSinNotasEnCerrados(['seccion_id' => 99999999]) === [],
    'una sección inexistente da 0 filas, no el listado entero');

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — el listado, su conteo y la ficha leen la misma regla.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
