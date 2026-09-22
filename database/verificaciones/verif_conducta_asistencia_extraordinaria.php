<?php

/**
 * Verificación — CONDUCTA y ASISTENCIA EXTRAORDINARIAS de un bimestre cerrado
 * (22/09/2026, migración 063).
 * Uso: php database/verificaciones/verif_conducta_asistencia_extraordinaria.php
 *
 * ESCRIBE DENTRO DE UNA TRANSACCIÓN Y HACE ROLLBACK: no deja nada en la BD.
 *
 * QUÉ COMPRUEBA
 *   0. La migración 063 está aplicada (si no, aborta: el resto no tiene sentido).
 *   1. Las reglas de admisión tienen UN solo dueño: el lote, su POST y el listado
 *      responden lo mismo para cada fila del listado de /rectificaciones.
 *   2. RAMA QUE DEJA PASAR: en un alumno sin conducta ni asistencia en un
 *      bimestre cerrado, el alta entra, la BOLETA pasa de guion al dato, la fila
 *      queda marcada como extraordinaria con su motivo, y el alumno sale del
 *      listado. Un 0 en asistencia se registra como dato (no como guion).
 *   3. RAMA QUE BLOQUEA: no admite (y el alta lanza) si ya hay literal, nota del
 *      tutor, matriz de criterios o fila de asistencia; ni en un bimestre que no
 *      está cerrado. La vía extraordinaria nunca pisa.
 *      (En la rama 2 también: el banner legado del tutor NO cambia al registrar
 *      un literal extraordinario en su sección.)
 *   4. RETORNO DE GRADO: si el dato vive en la otra matrícula que une la
 *      boleta, NO se ofrece. Sin esto, la asistencia se sumaba dos veces.
 *   5. El tope de asistencia es UN solo valor (AsistenciaModel::TOPE_MAX).
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

use App\Models\AsistenciaModel;
use App\Models\BoletaModel;
use App\Models\ConductaModel;
use App\Models\RectificacionModel;

$pdo      = Core\Database::connect();
$rect     = new RectificacionModel();
$conducta = new ConductaModel();
$asist    = new AsistenciaModel();
$boletas  = new BoletaModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$lanza = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

echo "\n=== 0. Migración 063 ===\n";
$cols = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('inasistencias', 'calificaciones_conducta')
      AND COLUMN_NAME IN ('extraordinaria', 'motivo_extraordinaria')
")->fetchColumn();
$ok($cols === 4, "las 4 columnas nuevas existen ({$cols}/4)");
if ($cols !== 4) {
    echo "\nABORTADO: aplica database/migrations/063_conducta_asistencia_extraordinaria.sql\n\n";
    exit(1);
}

echo "\n=== 1. Una sola regla: el listado, el lote y el POST responden lo mismo ===\n";
$listado = $rect->matriculasSinNotasEnCerrados([]);
$difieren = 0;
foreach ($listado as $f) {
    $mid  = (int) $f['matricula_id'];
    $pid  = (int) $f['periodo_id'];
    $pend = $rect->conductaAsistenciaPendientes($mid, $pid);
    if ($pend['conducta'] !== $f['falta_conducta']
        || $pend['asistencia'] !== $f['falta_asistencia']
        || $conducta->admiteExtraordinaria($mid, $pid) !== $f['falta_conducta']
        || $asist->admiteExtraordinaria($mid, $pid) !== $f['falta_asistencia']) {
        $difieren++;
        echo "     {$mid} / periodo {$pid} no coincide\n";
    }
}
$ok($difieren === 0, 'listado, lote y re-chequeo del POST coinciden en las ' . count($listado) . ' filas');

// Caso para las ramas: el primero del listado al que le falten LAS DOS cosas y
// que tenga alguna nota en el bimestre (la boleta solo abre la columna si el
// bimestre aporta notas).
$caso = null;
foreach ($listado as $f) {
    if ($f['falta_conducta'] && $f['falta_asistencia'] && !$f['sin_notas']) { $caso = $f; break; }
}

echo "\n=== 2. Rama que DEJA PASAR (con rollback) ===\n";
if ($caso === null) {
    echo "     (no hay alumno con notas y sin conducta ni asistencia en esta BD: rama sin probar)\n";
} else {
    $mid = (int) $caso['matricula_id'];
    $pid = (int) $caso['periodo_id'];
    $sec = (int) $pdo->query("SELECT seccion_id FROM matriculas WHERE id = {$mid}")->fetchColumn();
    $usr = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();
    echo "     caso: matrícula {$mid}, periodo {$pid} ({$caso['nombre']})\n";

    $asisBoleta = static function () use ($boletas, $mid, $pid): ?array {
        $d = $boletas->armar($mid, $pid, 'todos', true);
        foreach (($d['asistencia']['bimestres'] ?? []) as $b) {
            if ((int) $b['id'] === $pid) { return $b; }
        }
        return null;
    };
    $condBoleta = static function () use ($boletas, $mid, $pid): ?string {
        $d = $boletas->armar($mid, $pid, 'todos', true);
        return $d['conducta'][$pid] ?? null;
    };

    $bannerAntes = $conducta->getRegistroLegado($sec, $pid);
    $antesA = $asisBoleta();
    $ok($condBoleta() === null, 'antes: la boleta no tiene conducta en ese bimestre (guion)');
    $ok($antesA !== null && $antesA['sin_registro'] === true, 'antes: la asistencia de la boleta sale en guion');

    $pdo->beginTransaction();
    try {
        $conducta->registrarLiteralExtraordinario($mid, $pid, 'A', 'VERIFICACION 063', $usr);
        $asist->registrarExtraordinaria($mid, $pid, 0, 0, 0, 0, 'VERIFICACION 063', $usr);

        $ok($condBoleta() === 'A', 'después: la boleta muestra la conducta A');
        $b = $asisBoleta();
        $ok($b !== null && $b['sin_registro'] === false && array_sum($b['datos']) === 0,
            'después: la asistencia sale como DATO 0, no como guion');

        $fc = $pdo->query("SELECT extraordinaria, motivo_extraordinaria, literal FROM calificaciones_conducta
                           WHERE matricula_id = {$mid} AND periodo_id = {$pid}")->fetch(PDO::FETCH_ASSOC);
        $fa = $pdo->query("SELECT extraordinaria, motivo_extraordinaria FROM inasistencias
                           WHERE matricula_id = {$mid} AND periodo_id = {$pid}")->fetch(PDO::FETCH_ASSOC);
        $ok($fc && (int) $fc['extraordinaria'] === 1 && $fc['motivo_extraordinaria'] === 'VERIFICACION 063',
            'la conducta queda marcada como extraordinaria, con su motivo');
        $ok($fa && (int) $fa['extraordinaria'] === 1 && $fa['motivo_extraordinaria'] === 'VERIFICACION 063',
            'la asistencia queda marcada como extraordinaria, con su motivo');

        // Ya no admite: la segunda vez no pisa.
        $ok(!$conducta->admiteExtraordinaria($mid, $pid), 'con el literal ya registrado, la conducta NO se ofrece otra vez');
        $ok(!$asist->admiteExtraordinaria($mid, $pid), 'con la fila ya registrada, la asistencia NO se ofrece otra vez');
        $ok($lanza(fn() => $asist->registrarExtraordinaria($mid, $pid, 1, 0, 0, 0, 'x', $usr)),
            'un segundo alta de asistencia LANZA (la UNIQUE lo impide)');
    } finally {
        $pdo->rollBack();
    }

    // Banner legado: se comprueba con otra transacción, sobre la misma sección.
    $pdo->beginTransaction();
    try {
        $conducta->registrarLiteralExtraordinario($mid, $pid, 'AD', 'VERIFICACION 063', $usr);
        $ok($conducta->getRegistroLegado($sec, $pid) == $bannerAntes,
            'el banner legado del tutor NO cambia (sigue diciendo quién registró la sección)');
    } finally {
        $pdo->rollBack();
    }
    $ok($condBoleta() === null && $asisBoleta()['sin_registro'] === true, 'tras el rollback, todo queda como estaba');
}

echo "\n=== 3. Rama que BLOQUEA ===\n";
// Alumno con literal de conducta YA registrado en un bimestre cerrado.
$conLiteral = $pdo->query("
    SELECT cc.matricula_id, cc.periodo_id FROM calificaciones_conducta cc
    JOIN periodos p ON p.id = cc.periodo_id AND p.estado = 'cerrado'
    WHERE cc.literal IS NOT NULL LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($conLiteral) {
    $m = (int) $conLiteral['matricula_id']; $p = (int) $conLiteral['periodo_id'];
    $ok(!$conducta->admiteExtraordinaria($m, $p), 'con literal previo: no admite');
    $pdo->beginTransaction();
    try {
        $ok($lanza(fn() => $conducta->registrarLiteralExtraordinario($m, $p, 'C', 'x', 1)), 'con literal previo: el alta lanza');
    } finally { $pdo->rollBack(); }
}
// Con nota del tutor.
$conTutor = $pdo->query("
    SELECT cc.matricula_id, cc.periodo_id FROM calificaciones_conducta cc
    JOIN periodos p ON p.id = cc.periodo_id AND p.estado = 'cerrado'
    WHERE cc.nota_tutor IS NOT NULL LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($conTutor) {
    $ok(!$conducta->admiteExtraordinaria((int) $conTutor['matricula_id'], (int) $conTutor['periodo_id']),
        'con nota del tutor: no admite');
}
// Con matriz de criterios.
$conMatriz = $pdo->query("
    SELECT r.matricula_id, r.periodo_id FROM conducta_respuestas r
    JOIN periodos p ON p.id = r.periodo_id AND p.estado = 'cerrado' LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($conMatriz) {
    $m = (int) $conMatriz['matricula_id']; $p = (int) $conMatriz['periodo_id'];
    $ok(!$conducta->admiteExtraordinaria($m, $p), 'con matriz de criterios: no admite');
    $pdo->beginTransaction();
    try {
        $ok($lanza(fn() => $conducta->registrarLiteralExtraordinario($m, $p, 'C', 'x', 1)), 'con matriz de criterios: el alta lanza');
    } finally { $pdo->rollBack(); }
}
// Con fila de asistencia.
$conAsist = $pdo->query("
    SELECT i.matricula_id, i.periodo_id FROM inasistencias i
    JOIN periodos p ON p.id = i.periodo_id AND p.estado = 'cerrado' LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($conAsist) {
    $m = (int) $conAsist['matricula_id']; $p = (int) $conAsist['periodo_id'];
    $ok(!$asist->admiteExtraordinaria($m, $p), 'con fila de asistencia: no admite');
    $pdo->beginTransaction();
    try {
        $ok($lanza(fn() => $asist->registrarExtraordinaria($m, $p, 1, 0, 0, 0, 'x', 1)), 'con fila de asistencia: el alta lanza');
    } finally { $pdo->rollBack(); }
}
// Bimestre NO cerrado: ni con el alumno sin registro.
$noCerrado = $pdo->query("
    SELECT m.id AS matricula_id, p.id AS periodo_id
    FROM matriculas m
    JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    JOIN periodos p ON p.anio_id = m.anio_id AND p.estado <> 'cerrado'
    WHERE m.tipo NOT IN ('trasladado', 'retirado')
      AND NOT EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = m.id AND i.periodo_id = p.id)
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($noCerrado) {
    $m = (int) $noCerrado['matricula_id']; $p = (int) $noCerrado['periodo_id'];
    $ok(!$asist->admiteExtraordinaria($m, $p) && !$conducta->admiteExtraordinaria($m, $p),
        'bimestre no cerrado: no admite ni conducta ni asistencia');
    $pend = $rect->conductaAsistenciaPendientes($m, $p);
    $ok($pend['periodo'] === null && !$pend['conducta'] && !$pend['asistencia'],
        'bimestre no cerrado: el lote no ofrece las filas');
}
$ok($lanza(fn() => $conducta->registrarLiteralExtraordinario(1, 1, 'X', 'x', 1)), 'un literal que no es AD/A/B/C lanza');

echo "\n=== 4. Retorno de grado: se mira la UNIÓN que lee la boleta ===\n";
// Caso vivido el 22/09/2026 (matrícula 692): el bimestre de la operativa sin
// filas propias, pero la OFICIAL sí las tiene y la boleta ya las muestra. Si
// se ofreciera, la asistencia se SUMARÍA a la de la oficial: faltas dobles.
$retornos = $pdo->query("
    SELECT rg.matricula_operativa_id AS operativa, rg.matricula_oficial_id AS oficial, p.id AS periodo_id
    FROM retornos_grado rg
    JOIN matriculas m ON m.id = rg.matricula_operativa_id
    JOIN periodos p ON p.anio_id = m.anio_id AND p.estado = 'cerrado'
    WHERE EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = rg.matricula_oficial_id AND i.periodo_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = rg.matricula_operativa_id AND i.periodo_id = p.id)
")->fetchAll(PDO::FETCH_ASSOC);
if ($retornos === []) {
    echo "     (no hay retorno con el dato solo en la oficial: rama sin probar)\n";
}
foreach ($retornos as $r) {
    $ok(!$asist->admiteExtraordinaria((int) $r['operativa'], (int) $r['periodo_id']),
        "operativa {$r['operativa']} / periodo {$r['periodo_id']}: su oficial {$r['oficial']} ya tiene asistencia → no se ofrece");
}
$retCond = $pdo->query("
    SELECT rg.matricula_operativa_id AS operativa, rg.matricula_oficial_id AS oficial, p.id AS periodo_id
    FROM retornos_grado rg
    JOIN matriculas m ON m.id = rg.matricula_operativa_id
    JOIN periodos p ON p.anio_id = m.anio_id AND p.estado = 'cerrado'
    WHERE EXISTS (SELECT 1 FROM calificaciones_conducta cc WHERE cc.matricula_id = rg.matricula_oficial_id
                    AND cc.periodo_id = p.id AND (cc.literal IS NOT NULL OR cc.nota_tutor IS NOT NULL))
      AND NOT EXISTS (SELECT 1 FROM calificaciones_conducta cc WHERE cc.matricula_id = rg.matricula_operativa_id
                    AND cc.periodo_id = p.id)
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($retCond as $r) {
    $ok(!$conducta->admiteExtraordinaria((int) $r['operativa'], (int) $r['periodo_id']),
        "operativa {$r['operativa']} / periodo {$r['periodo_id']}: su oficial {$r['oficial']} ya tiene conducta → no se ofrece");
}

echo "\n=== 5. Tope de asistencia: un solo valor ===\n";
$ctrl = file_get_contents(APP_PATH . '/Controllers/Admin/AsistenciaController.php');
$ok(str_contains($ctrl, 'TOPE_MAX = AsistenciaModel::TOPE_MAX'), 'la grilla de RA lee el tope del modelo');
$lote = file_get_contents(APP_PATH . '/Controllers/Rectificacion/RectificacionController.php');
$ok(str_contains($lote, 'AsistenciaModel::TOPE_MAX') && !preg_match('/>\s*99\b/', $lote), 'el lote usa el mismo tope, sin copiarlo');

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — la vía extraordinaria llena el guion sin pisar ningún registro.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
