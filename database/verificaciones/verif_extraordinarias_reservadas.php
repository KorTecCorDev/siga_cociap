<?php

/**
 * Verificación — extraordinarias RESERVADAS A DIRECCIÓN (21/09/2026).
 * Uso: php database/verificaciones/verif_extraordinarias_reservadas.php
 *
 * SOLO LECTURA: no escribe nada. Se puede correr en PRODUCCIÓN.
 *
 * LA EXCEPCIÓN
 *   Las extraordinarias de ÉTICA Y VALORES del I BIMESTRE 2026 las ingresó
 *   Registro Académico por acuerdo de dirección (nadie evaluó Ética ese
 *   bimestre). Su EXPLICACIÓN (motivo, autor) no se muestra al DOCENTE; sí a
 *   admin, Registro Académico y los directores. La nota sigue en tablas y
 *   boletas. Punto único: RectificacionModel::sqlSinReservadasDireccion.
 *
 * QUÉ COMPRUEBA — las dos ramas
 *   1. Para cada carga+periodo con extraordinarias: la lista PARA DOCENTE es la
 *      lista completa MENOS exactamente las reservadas (control escrito a mano,
 *      por nombre_boleta de Ética + número de bimestre + año).
 *   2. Rama "no se oculta": las extraordinarias de Ética de OTRO bimestre y las
 *      de otras áreas del I Bimestre siguen en la lista del docente.
 *   3. Lo mismo por competencia (grilla y resumen del docente).
 *   4. Cableado: las 4 pantallas del docente piden la lista "para docente" con
 *      el rol real, y /consulta-notas (dirección y RA) NO.
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

use App\Models\RectificacionModel;

$pdo  = Core\Database::connect();
$rect = new RectificacionModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$clave = static fn (array $r): string => (int) $r['matricula_id'] . '-' . (int) $r['competencia_id'];

// Control a mano: las extraordinarias reservadas, por (carga, periodo).
$st = $pdo->prepare("
    SELECT cal.carga_id, cal.periodo_id, cal.matricula_id, cal.competencia_id
    FROM calificaciones cal
    JOIN competencias c ON c.id = cal.competencia_id
    JOIN areas a        ON a.id = c.area_id AND a.nombre_boleta = ?
    JOIN periodos p     ON p.id = cal.periodo_id AND p.numero = 1
    JOIN anios_academicos y ON y.id = p.anio_id AND y.anio = 2026
    WHERE cal.extraordinaria = 1
");
$st->execute([AREA_ETICA_NOMBRE_BOLETA]);
$reservadas = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $reservadas[(int) $r['carga_id'] . '|' . (int) $r['periodo_id']][] = $clave($r);
}
$totalReservadas = array_sum(array_map('count', $reservadas));

echo "\n=== 1. Lista del docente = completa − reservadas ({$totalReservadas} reservadas) ===\n";
$grupos = $pdo->query("
    SELECT DISTINCT carga_id, periodo_id FROM calificaciones WHERE extraordinaria = 1
")->fetchAll(PDO::FETCH_ASSOC);
$difieren = 0; $ocultadas = 0; $gruposConReservadas = 0;
foreach ($grupos as $g) {
    $k = (int) $g['carga_id'] . '|' . (int) $g['periodo_id'];
    $todas   = array_map($clave, $rect->getExtraordinariasDeCargas([(int) $g['carga_id']], (int) $g['periodo_id']));
    $docente = array_map($clave, $rect->getExtraordinariasDeCargas([(int) $g['carga_id']], (int) $g['periodo_id'], true));
    $esperado = array_values(array_diff($todas, $reservadas[$k] ?? []));
    sort($esperado); sort($docente);
    if ($esperado !== $docente) { $difieren++; echo "     difiere: carga {$g['carga_id']}, periodo {$g['periodo_id']}\n"; }
    $ocultadas += count($todas) - count($docente);
    if (!empty($reservadas[$k])) { $gruposConReservadas++; }
}
$ok($difieren === 0, 'coincide en los ' . count($grupos) . ' pares carga+periodo con extraordinarias');
$ok($ocultadas === $totalReservadas, "se ocultan exactamente las reservadas ({$ocultadas} de {$totalReservadas})");
$ok($gruposConReservadas > 0, "rama OCULTAR ejercitada ({$gruposConReservadas} cargas)");

echo "\n=== 2. Lo que NO se oculta ===\n";
$st = $pdo->prepare("
    SELECT cal.carga_id, cal.periodo_id, cal.matricula_id, cal.competencia_id
    FROM calificaciones cal
    JOIN competencias c ON c.id = cal.competencia_id
    JOIN areas a        ON a.id = c.area_id AND a.nombre_boleta = ?
    JOIN periodos p     ON p.id = cal.periodo_id AND p.numero <> 1
    WHERE cal.extraordinaria = 1 LIMIT 1
");
$st->execute([AREA_ETICA_NOMBRE_BOLETA]);
$eticaOtro = $st->fetch(PDO::FETCH_ASSOC);
if ($eticaOtro) {
    $docente = array_map($clave, $rect->getExtraordinariasDeCargas([(int) $eticaOtro['carga_id']], (int) $eticaOtro['periodo_id'], true));
    $ok(in_array($clave($eticaOtro), $docente, true), 'una extraordinaria de Ética de OTRO bimestre sigue visible al docente');
} else {
    echo "     (no hay extraordinarias de Ética fuera del I Bimestre: rama sin probar)\n";
}
$st = $pdo->prepare("
    SELECT cal.carga_id, cal.periodo_id, cal.matricula_id, cal.competencia_id
    FROM calificaciones cal
    JOIN competencias c ON c.id = cal.competencia_id
    LEFT JOIN areas a   ON a.id = c.area_id
    JOIN periodos p     ON p.id = cal.periodo_id AND p.numero = 1
    WHERE cal.extraordinaria = 1 AND (a.nombre_boleta IS NULL OR a.nombre_boleta <> ?) LIMIT 1
");
$st->execute([AREA_ETICA_NOMBRE_BOLETA]);
$otraArea = $st->fetch(PDO::FETCH_ASSOC);
if ($otraArea) {
    $docente = array_map($clave, $rect->getExtraordinariasDeCargas([(int) $otraArea['carga_id']], (int) $otraArea['periodo_id'], true));
    $ok(in_array($clave($otraArea), $docente, true), 'una extraordinaria de OTRA área del I Bimestre sigue visible al docente');
}

echo "\n=== 3. Por competencia (grilla y resumen del docente) ===\n";
$difComp = 0; $n = 0;
foreach ($reservadas as $k => $lista) {
    [$carga, $periodo] = array_map('intval', explode('|', $k));
    foreach (array_unique(array_map(static fn ($c) => (int) explode('-', $c)[1], $lista)) as $comp) {
        $n++;
        $todas   = $rect->getExtraordinariasDeCompetencia($carga, $comp, $periodo);
        $docente = $rect->getExtraordinariasDeCompetencia($carga, $comp, $periodo, true);
        if ($todas === [] || $docente !== []) { $difComp++; }
    }
}
$ok($n > 0 && $difComp === 0, "en las {$n} competencias reservadas: dirección ve la lista, el docente la ve vacía");

echo "\n=== 4. Cableado ===\n";
$doc  = file_get_contents(APP_PATH . '/Controllers/Docente/CalificacionController.php');
$cons = file_get_contents(APP_PATH . '/Controllers/Consulta/ConsultaNotasController.php');
$ok(preg_match_all("/getExtraordinariasDe(Cargas|Competencia)\([^;]*Session::hasRole\('docente'\)\)/", $doc) === 4,
    'las 4 llamadas del docente (historial, área, grilla, resumen) pasan el rol real');
$ok(preg_match_all('/getExtraordinariasDe(Cargas|Competencia)\(/', $doc) === 4,
    'y no hay otra llamada del docente sin el filtro');
$ok(!str_contains($cons, "hasRole('docente')") && str_contains($cons, 'getExtraordinariasDeCargas([$cargaId], $periodoId)'),
    '/consulta-notas (dirección y RA) sigue viendo todas');

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — las reservadas a dirección no se explican al docente, y nada más cambia.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
