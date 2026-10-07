<?php

/**
 * Verificación — ROSTER POR BIMESTRE de las consultas que preguntan por un
 * bimestre (cambio de sección, 07/10/2026).
 * Uso: php database/verificaciones/verif_seccion_del_periodo.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, dentro de una TRANSACCIÓN QUE TERMINA EN ROLLBACK.
 *
 * LA REGLA: una consulta de UN BIMESTRE lista a quien CURSÓ ese bimestre en la
 * sección (CambioSeccionModel::sqlEnSeccionDelPeriodo), no a quien está HOY.
 * Consultas ya convertidas (crece en la fase 4; inventario en
 * docs/modulos/cambio-seccion.md § 6):
 *   - CalificacionModel::getResumenCompetencia (historial, resumen, bloqueo)
 *
 * QUÉ COMPRUEBA
 *   1. Sin cambios de sección que la toquen, la condición nueva da el MISMO
 *      roster que `seccion_id = S`, en cada sección y bimestre.
 *   2. Tras un cambio en el bimestre en curso: en un bimestre CERRADO el
 *      estudiante sigue en el resumen de la carga de ORIGEN (con su nota) y NO
 *      aparece en el de DESTINO; en el bimestre en curso, al revés.
 *   3. ROLLBACK.
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

use App\Models\CambioSeccionModel;

$pdo     = Core\Database::connect();
$cal     = new App\Models\CalificacionModel();
$cambios = new CambioSeccionModel();
$tras    = new App\Models\TrasladoModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};

$anio = (int) $pdo->query("SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
$periodos = $pdo->query("SELECT id, numero, estado FROM periodos WHERE anio_id = {$anio} ORDER BY numero")
    ->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== 1. Sin cambios que la toquen: mismo roster que seccion_id = S ===\n";
$tocadas = array_map('intval', $pdo->query("SELECT seccion_origen_id FROM cambios_seccion
                                            UNION SELECT seccion_destino_id FROM cambios_seccion")
    ->fetchAll(PDO::FETCH_COLUMN));
$distintas = 0; $revisadas = 0;
foreach ($pdo->query("SELECT id FROM secciones WHERE anio_id = {$anio}")->fetchAll(PDO::FETCH_COLUMN) as $s) {
    $s = (int) $s;
    if (in_array($s, $tocadas, true)) { continue; }
    foreach ($periodos as $p) {
        $viejo = $pdo->query("SELECT GROUP_CONCAT(m.id ORDER BY m.id) FROM matriculas m WHERE m.seccion_id = {$s}")->fetchColumn();
        $nuevo = $pdo->query("SELECT GROUP_CONCAT(m.id ORDER BY m.id) FROM matriculas m WHERE "
            . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $s, (int) $p['id']))->fetchColumn();
        $revisadas++;
        if ($viejo !== $nuevo) { $distintas++; }
    }
}
$ok($distintas === 0, "{$revisadas} combinaciones sección × bimestre: 0 diferencias");

echo "\n=== 2. Escenario: cambio en el bimestre en curso ===\n";
$cerrado = null; $abierto = null;
foreach ($periodos as $p) {
    if ($p['estado'] === 'cerrado') { $cerrado = $p; }
    elseif ($abierto === null) { $abierto = $p; }
}
// Sujeto: aprobada, sin retorno, con nota BLOQUEADA en el bimestre cerrado y
// con una carga equivalente (misma área) en otra sección del grado.
$suj = $cerrado && $abierto ? $pdo->query("
    SELECT m.id AS mid, m.seccion_id AS origen, c.carga_id AS carga_o, c.competencia_id AS comp,
           c.nota_numerica AS nota, s2.id AS destino, ca2.id AS carga_d
    FROM matriculas m
    JOIN secciones s ON s.id = m.seccion_id
    JOIN calificaciones c ON c.matricula_id = m.id AND c.periodo_id = {$cerrado['id']}
    JOIN cargas_academicas ca ON ca.id = c.carga_id AND ca.seccion_id = m.seccion_id
    JOIN bloqueos_competencia bc ON bc.carga_id = c.carga_id AND bc.competencia_id = c.competencia_id
                                AND bc.periodo_id = c.periodo_id
    JOIN secciones s2 ON s2.grado_id = s.grado_id AND s2.anio_id = s.anio_id AND s2.id <> s.id
    JOIN cargas_academicas ca2 ON ca2.seccion_id = s2.id
         AND COALESCE(ca2.area_id, (SELECT area_id FROM subareas WHERE id = ca2.subarea_id))
           = COALESCE(ca.area_id,  (SELECT area_id FROM subareas WHERE id = ca.subarea_id))
    WHERE m.estado = 'aprobada' AND m.tipo IN ('continuador','nuevo')
      AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado UNION SELECT matricula_operativa_id FROM retornos_grado)
      AND m.id NOT IN (SELECT matricula_id FROM cambios_seccion)
    ORDER BY m.id LIMIT 1")->fetch(PDO::FETCH_ASSOC) : false;

if (!$suj) {
    echo "  (sin escenario evaluable: hace falta un bimestre cerrado y otro abierto)\n";
} else {
    $mid = (int) $suj['mid'];
    $en = function (array $r) use ($mid): ?array {
        foreach ($r['alumnos'] as $a) { if ((int) $a['matricula_id'] === $mid) { return $a; } }
        return null;
    };
    $pdo->beginTransaction();
    try {
        $ok($en($cal->getResumenCompetencia((int) $suj['carga_o'], (int) $suj['comp'], (int) $cerrado['id'])) !== null,
            "antes: matrícula {$mid} en el resumen de ORIGEN del bimestre {$cerrado['numero']}");

        $cambios->ejecutar($mid, (int) $suj['destino'], $tras->siguienteCorrelativo($anio, 1),
                           date('Y-m-d'), 'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());

        $fila = $en($cal->getResumenCompetencia((int) $suj['carga_o'], (int) $suj['comp'], (int) $cerrado['id']));
        $ok($fila !== null && (int) $fila['promedio'] === (int) $suj['nota'],
            "después: SIGUE en el resumen de ORIGEN del bimestre cerrado, con su nota ({$suj['nota']})");
        $ok($en($cal->getResumenCompetencia((int) $suj['carga_d'], (int) $suj['comp'], (int) $cerrado['id'])) === null,
            'y NO aparece (vacío) en el de DESTINO de ese bimestre');
        $ok($en($cal->getResumenCompetencia((int) $suj['carga_d'], (int) $suj['comp'], (int) $abierto['id'])) !== null,
            "en el bimestre en curso ({$abierto['numero']}) aparece en DESTINO");
        $ok($en($cal->getResumenCompetencia((int) $suj['carga_o'], (int) $suj['comp'], (int) $abierto['id'])) === null,
            'y ya NO en ORIGEN');
    } finally {
        $pdo->rollBack();
    }
    $ok((int) $pdo->query("SELECT seccion_id FROM matriculas WHERE id = {$mid}")->fetchColumn() === (int) $suj['origen'],
        '3. ROLLBACK: la matrícula vuelve a su sección');
}

echo "\n=== 4. Bloque A — la boleta de un bimestre cursado en otra sección ===\n";
// Tras un cambio en el bimestre en curso, el bimestre CERRADO se publica con
// el CIERRE (conducta y transversales) y el TUTOR de la sección de ORIGEN. Para
// que la prueba distinga, se ANULAN los cierres de DESTINO en ese bimestre: si
// el código leyera la sección de hoy, la boleta perdería esos datos.
if ($suj) {
    $conducta = new App\Models\ConductaModel();
    $boleta   = new App\Models\BoletaModel();
    $tutorDe  = new ReflectionMethod($boleta, 'getTutorSeccion');
    $tutorDe->setAccessible(true);
    $transv   = new ReflectionMethod($cal, 'getTransversalesAgregadas');
    $transv->setAccessible(true);
    $pid      = (int) $cerrado['id'];
    $origenS  = (int) $suj['origen'];
    $destinoS = (int) $suj['destino'];

    $pdo->beginTransaction();
    try {
        $condAntes  = $conducta->getParaPeriodo($mid, $pid);
        $transAntes = $transv->invoke($cal, $mid, $pid);
        $tutorAntes = $tutorDe->invoke($boleta, $mid, $pid);

        $cambios->ejecutar($mid, $destinoS, $tras->siguienteCorrelativo($anio, 1), date('Y-m-d'),
                           'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());
        $pdo->exec("UPDATE cierres_conducta SET anulado_en = NOW()
                    WHERE seccion_id = {$destinoS} AND periodo_id = {$pid} AND anulado_en IS NULL");
        $pdo->exec("UPDATE cierres_transversales SET anulado_en = NOW()
                    WHERE seccion_id = {$destinoS} AND periodo_id = {$pid} AND anulado_en IS NULL");

        $ok($conducta->getParaPeriodo($mid, $pid) === $condAntes,
            'conducta del bimestre cerrado: sale con el cierre de ORIGEN (' . var_export($condAntes, true) . ')');
        $ok($transv->invoke($cal, $mid, $pid) == $transAntes,
            'transversales del bimestre cerrado: salen con el cierre de ORIGEN (' . count($transAntes) . ')');
        $ok($tutorDe->invoke($boleta, $mid, $pid) == $tutorAntes,
            'el tutor de ese bimestre es el de ORIGEN (' . ($tutorAntes['nombre'] ?? '—') . ')');
        $porAnio = $conducta->getParaBoleta($mid, $anio);
        $ok(($porAnio[$pid] ?? null) === $condAntes, 'getParaBoleta (todo el año) coincide');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n=== 5. Bloque B — rectificación de un bimestre cursado en otra sección ===\n";
// Tras el cambio, lo que se ofrece insertar en un bimestre CERRADO debe estar en
// las cargas de la sección donde se CURSÓ (y no repetir lo que ya tiene allí).
if ($suj) {
    $rect = new App\Models\RectificacionModel();
    $pdo->beginTransaction();
    try {
        $antes = array_filter($rect->getCompetenciasInsertables($mid), fn($f) => (int) $f['periodo_id'] === (int) $cerrado['id']);
        $cambios->ejecutar($mid, (int) $suj['destino'], $tras->siguienteCorrelativo($anio, 1), date('Y-m-d'),
                           'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());
        $despues = array_filter($rect->getCompetenciasInsertables($mid), fn($f) => (int) $f['periodo_id'] === (int) $cerrado['id']);
        $secciones = array_values(array_unique(array_map(fn($f) => (int) $pdo->query(
            "SELECT seccion_id FROM cargas_academicas WHERE id = " . (int) $f['carga_id'])->fetchColumn(), $despues)));
        $ok($secciones === [] || $secciones === [(int) $suj['origen']],
            "en el bimestre cerrado solo ofrece cargas de ORIGEN");
        $ok(count($despues) === count($antes),
            'ofrece lo mismo que antes del cambio (' . count($antes) . '): no inventa faltantes en DESTINO');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n=== 6. Bloque C — acta SIAGIE de un bimestre cursado en otra sección ===\n";
if ($suj) {
    $siagie = new App\Models\SiagieExportModel();
    $ids = fn(array $r) => array_map('intval', array_column($r, 'matricula_id'));
    $pdo->beginTransaction();
    try {
        $cambios->ejecutar($mid, (int) $suj['destino'], $tras->siguienteCorrelativo($anio, 1), date('Y-m-d'),
                           'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());
        $pc = (int) $cerrado['id'];
        $ok(in_array($mid, $ids($siagie->estudiantesDeSeccion((int) $suj['origen'], $pc)), true),
            'el acta del bimestre cerrado de ORIGEN lo incluye');
        $ok(!in_array($mid, $ids($siagie->estudiantesDeSeccion((int) $suj['destino'], $pc)), true),
            'el acta de DESTINO de ese bimestre no lo incluye');
        $ok(in_array($mid, $ids($siagie->estudiantesDeSeccion((int) $suj['destino'])), true),
            'sin periodo, la sección de HOY (comportamiento anterior intacto)');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n=== 7. Bloque D — panel del tutor de un bimestre cursado en otra sección ===\n";
if ($suj) {
    $tm = new App\Models\TransversalModel();
    $pdo->beginTransaction();
    try {
        $pc = (int) $cerrado['id'];
        $tieneTransv = isset($tm->getPromediosSeccion((int) $suj['origen'], $pc)[$mid]);
        $cambios->ejecutar($mid, (int) $suj['destino'], $tras->siguienteCorrelativo($anio, 1), date('Y-m-d'),
                           'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());
        $ok(isset($tm->getPromediosSeccion((int) $suj['origen'], $pc)[$mid]) === $tieneTransv,
            'el tutor de ORIGEN conserva sus transversales de ese bimestre' . ($tieneTransv ? '' : ' (no tenía)'));
        $ok(!isset($tm->getPromediosSeccion((int) $suj['destino'], $pc)[$mid]),
            'el tutor de DESTINO no lo ve en ese bimestre');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n=== 8. Bloque E — conducta de un bimestre cursado en otra sección ===\n";
if ($suj) {
    $cm  = new App\Models\ConductaModel();
    $ids = fn(array $r) => array_map('intval', array_column($r, 'matricula_id'));
    $pdo->beginTransaction();
    try {
        $pc = (int) $cerrado['id'];
        $cambios->ejecutar($mid, (int) $suj['destino'], $tras->siguienteCorrelativo($anio, 1), date('Y-m-d'),
                           'verificación', (int) $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn());
        $ok(in_array($mid, $ids($cm->getEstudiantesParaRegistro((int) $suj['origen'], $pc)), true)
            && in_array($mid, $ids($cm->getEstudiantesParaTutor((int) $suj['origen'], $pc, 10)), true),
            'el registro y el panel de conducta de ORIGEN lo conservan en ese bimestre');
        $ok(!in_array($mid, $ids($cm->getEstudiantesParaRegistro((int) $suj['destino'], $pc)), true),
            'el de DESTINO no lo lista en ese bimestre');
        $ok(in_array($mid, $ids($cm->getEstudiantesParaRegistro((int) $suj['destino'], (int) $abierto['id'])), true),
            'en el bimestre en curso lo registra DESTINO');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n" . ($fallos === 0 ? "TODO OK\n" : "FALLAS: {$fallos}\n");
exit($fallos === 0 ? 0 : 1);
