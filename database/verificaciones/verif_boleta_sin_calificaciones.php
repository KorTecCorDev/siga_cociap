<?php

/**
 * Verificación — BOLETA DE UN ESTUDIANTE SIN CALIFICACIONES OFICIALES (21/09/2026).
 * Uso: php database/verificaciones/verif_boleta_sin_calificaciones.php
 *
 * SOLO LECTURA: no escribe nada. Se puede correr en PRODUCCIÓN.
 *
 * CONTEXTO
 *   Las boletas internas (docente y gestión) devolvían 404 cuando el alumno no
 *   tenía ningún bimestre publicable con competencias bloqueadas, y la ficha de
 *   matrícula ofrecía los botones igual. Ahora la ruta pinta un AVISO y la ficha
 *   desactiva los botones. Las dos decisiones leen la MISMA regla:
 *   `BoletaModel::periodoPublicableConNotas`.
 *
 * QUÉ COMPRUEBA
 *   1. La regla, en sus DOS ramas, contra una consulta de control escrita aquí a
 *      mano: para cada matrícula del año activo, "tiene periodo" ⟺ existe una
 *      calificación bloqueada en un bimestre cerrado o activo con Hito A.
 *   2. Punto único: el controlador ya no guarda su copia privada, y la ruta y la
 *      ficha llaman al modelo.
 *   3. La ruta: sin periodo → aviso (no 404); matrícula inexistente → 404.
 *   4. Las vistas: la ficha desactiva los botones con el motivo; el aviso es una
 *      página suelta con el botón Cerrar de los documentos.
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

$pdo    = Core\Database::connect();
$boleta = new App\Models\BoletaModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};

echo "\n=== 1. La regla, en sus dos ramas, contra un control a mano ===\n";
// Control escrito a mano (NO derivado del modelo): matrículas del año activo con
// alguna calificación BLOQUEADA en un bimestre cerrado o activo con Hito A.
$conControl = array_map('intval', $pdo->query("
    SELECT DISTINCT cal.matricula_id
    FROM calificaciones cal
    JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id
                                AND bc.competencia_id = cal.competencia_id
                                AND bc.periodo_id = cal.periodo_id
    JOIN periodos p ON p.id = cal.periodo_id
    JOIN matriculas m ON m.id = cal.matricula_id
    JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    WHERE p.estado = 'cerrado'
       OR (p.estado = 'activo' AND p.boletas_aprobadas_en IS NOT NULL)
")->fetchAll(PDO::FETCH_COLUMN));
$conControl = array_flip($conControl);

$matriculas = $pdo->query("
    SELECT m.id, m.anio_id FROM matriculas m
    JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
")->fetchAll(PDO::FETCH_ASSOC);

$con = $sin = $difieren = 0;
foreach ($matriculas as $m) {
    $tiene = $boleta->periodoPublicableConNotas((int) $m['anio_id'], (int) $m['id']) !== null;
    $tiene ? $con++ : $sin++;
    if ($tiene !== isset($conControl[(int) $m['id']])) {
        $difieren++;
        echo "     difiere: matrícula {$m['id']}\n";
    }
}
$ok($difieren === 0, "el modelo coincide con el control en las " . count($matriculas) . " matrículas del año");
$ok($con > 0, "rama CON boleta ejercitada ({$con} matrículas)");
$ok($sin > 0, "rama SIN boleta ejercitada ({$sin} matrículas)"
    . ($sin === 0 ? ' — no hay ninguna sin notas en esta BD: la rama no se probó' : ''));

echo "\n=== 2. Punto único ===\n";
$ctrl = file_get_contents(APP_PATH . '/Controllers/Boleta/BoletaController.php');
$mat  = file_get_contents(APP_PATH . '/Controllers/Matricula/MatriculaController.php');
$ok(!str_contains($ctrl, 'function periodoPublicableConNotas'),
    'BoletaController ya no tiene su copia privada de la regla');
$ok(substr_count($ctrl, '$this->boletaModel->periodoPublicableConNotas(') === 2,
    'las dos rutas internas (docente y gestión) leen la regla del modelo');
$ok(str_contains($mat, 'BoletaModel())->periodoPublicableConNotas('),
    'la ficha de matrícula lee la MISMA regla para decidir los botones');
$ok(str_contains($mat, "\$esTrasladado = \$matricula['estado'] === 'desactivado' && \$matricula['tipo'] === 'trasladado'"),
    'la ficha aplica el mismo corte del trasladado (solo bimestres cerrados) que la ruta');

echo "\n=== 3. La ruta: aviso, no 404 ===\n";
$ok(substr_count($ctrl, '$this->sinCalificaciones();') === 2,
    'sin periodo, las dos rutas pintan el aviso');
$ok(str_contains($ctrl, "if (!\$mat) {\n            \$this->notFound();")
    || str_contains($ctrl, "if (!\$mat) {\r\n            \$this->notFound();"),
    'una matrícula INEXISTENTE sigue siendo 404 en gestión');

echo "\n=== 4. Las vistas ===\n";
$show  = file_get_contents(ROOT_PATH . '/resources/views/matriculas/show.php');
$aviso = file_get_contents(ROOT_PATH . '/resources/views/boleta/sin-calificaciones.php');
$ok(str_contains($show, "!empty(\$tieneBoleta)") && str_contains($show, 'is-disabled" aria-disabled="true">Ver boleta digital'),
    'la ficha desactiva los botones cuando no hay boleta');
$ok(str_contains($show, 'Aún no tiene calificaciones oficiales.'), 'y dice el motivo');
$ok(str_contains($aviso, 'btn-boleta--cerrar') && str_contains($aviso, "js/print-fit.js"),
    'el aviso lleva el botón Cerrar de los documentos (pestaña nueva)');
$ok(str_contains($aviso, "css/errores.css") && !str_contains($aviso, '<style'),
    'el aviso usa errores.css, sin CSS inline');

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — sin notas, aviso y botones inertes con la misma regla.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
