<?php

/**
 * Verificación — CAMBIO DE SECCIÓN (fase 1: punto único + ejecutar).
 * Uso: php database/verificaciones/verif_cambio_seccion.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila (regla del proyecto).
 *
 * LAS REGLAS (docs/modulos/cambio-seccion.md, 07/10/2026)
 *  - Bimestres CERRADOS: las notas se quedan en la carga de origen, intactas.
 *  - Bimestres NO cerrados: lo vivo en origen se ARCHIVA y se RETIRA; destino
 *    califica de cero.
 *  - sección(P): destino desde el periodo del cambio; origen antes.
 *  - Guardas: operativa de retorno NO; oficial con retorno activo NO (revertido
 *    SÍ); destino del mismo grado y año, distinto del actual; R.D. libre.
 *
 * QUÉ COMPRUEBA
 *   1. Sin cambios, sección(P) = matriculas.seccion_id.
 *   2. Guardas, en sus DOS ramas.
 *   3. ejecutar(): archivo completo = lo que había vivo; origen queda sin filas
 *      vivas en los periodos no cerrados; los cerrados, idénticos; la boleta de
 *      los cerrados no cambia; la matrícula queda en destino.
 *   4. sección(P) por bimestre tras el cambio.
 *   5. Un cambio REVERTIDO deja de contar en sección(P).
 *   6. ROLLBACK: todo vuelve a su valor inicial.
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

$pdo     = Core\Database::connect();
$cambios = new App\Models\CambioSeccionModel();
$cal     = new App\Models\CalificacionModel();
$tras    = new App\Models\TrasladoModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};
$rechaza = function (callable $fn): ?string {
    try { $fn(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
};

// Filas VIVAS de una matrícula en las cargas de una sección, por estado de periodo.
$vivas = function (int $mid, int $sec, bool $cerrados) use ($contar): array {
    $cond = $cerrados ? "= 'cerrado'" : "<> 'cerrado'";
    return [
        'cal'  => $contar("SELECT COUNT(*) FROM calificaciones c
                    JOIN cargas_academicas ca ON ca.id = c.carga_id
                    JOIN periodos pe ON pe.id = c.periodo_id
                    WHERE c.matricula_id = ? AND ca.seccion_id = ? AND pe.estado {$cond}", [$mid, $sec]),
        'crit' => $contar("SELECT COUNT(*) FROM calificaciones_criterio cc
                    JOIN criterios cr ON cr.id = cc.criterio_id
                    JOIN cargas_academicas ca ON ca.id = cr.carga_id
                    JOIN periodos pe ON pe.id = cr.periodo_id
                    WHERE cc.matricula_id = ? AND ca.seccion_id = ? AND pe.estado {$cond}
                      AND cr.eliminado_en IS NULL", [$mid, $sec]),
        'omi'  => $contar("SELECT COUNT(*) FROM omisiones_criterio oc
                    JOIN criterios cr ON cr.id = oc.criterio_id
                    JOIN cargas_academicas ca ON ca.id = cr.carga_id
                    JOIN periodos pe ON pe.id = cr.periodo_id
                    WHERE oc.matricula_id = ? AND ca.seccion_id = ? AND pe.estado {$cond}
                      AND cr.eliminado_en IS NULL", [$mid, $sec]),
    ];
};

// ── Sujeto: aprobada, sin retorno, con otra sección en su grado y con datos
//    VIVOS en algún periodo no cerrado (para que el archivo no sea trivial). ──
$suj = $pdo->query("
    SELECT m.id, m.anio_id, m.seccion_id AS origen, s.grado_id,
           (SELECT s2.id FROM secciones s2 WHERE s2.grado_id = s.grado_id
               AND s2.anio_id = s.anio_id AND s2.id <> s.id ORDER BY s2.id LIMIT 1) AS destino
    FROM matriculas m
    JOIN secciones s ON s.id = m.seccion_id
    JOIN anios_academicos aa ON aa.id = m.anio_id AND aa.estado = 'activo'
    WHERE m.estado = 'aprobada' AND m.tipo IN ('continuador','nuevo')
      AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado)
      AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
      AND EXISTS (SELECT 1 FROM secciones s3 WHERE s3.grado_id = s.grado_id
                     AND s3.anio_id = s.anio_id AND s3.id <> s.id)
      AND EXISTS (SELECT 1 FROM calificaciones_criterio cc
                    JOIN criterios cr ON cr.id = cc.criterio_id
                    JOIN periodos pe ON pe.id = cr.periodo_id
                   WHERE cc.matricula_id = m.id AND pe.estado <> 'cerrado'
                     AND cr.eliminado_en IS NULL)
    ORDER BY m.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$suj) { echo "No hay una matrícula con datos vivos en un bimestre abierto. Nada que verificar.\n"; exit(0); }

$mid     = (int) $suj['id'];
$anioId  = (int) $suj['anio_id'];
$origen  = (int) $suj['origen'];
$destino = (int) $suj['destino'];
$usuario = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();
$periodos = $pdo->query("SELECT id, numero, estado FROM periodos WHERE anio_id = {$anioId} ORDER BY numero")
    ->fetchAll(PDO::FETCH_ASSOC);
$periodoCambio = $cambios->periodoDelCambio($anioId);
echo "\n=== SUJETO === matrícula {$mid} · sección {$origen} → {$destino} · rige desde {$periodoCambio['nombre_display']}\n";

$tablas = ['cambios_seccion', 'cambios_seccion_competencia', 'cambios_seccion_criterio',
           'cambios_seccion_omision', 'calificaciones', 'calificaciones_criterio', 'omisiones_criterio'];
$antesGlobal = [];
foreach ($tablas as $t) { $antesGlobal[$t] = $contar("SELECT COUNT(*) FROM {$t}"); }

$pdo->beginTransaction();
try {
    echo "\n=== 1. Sin cambios, sección(P) = sección actual ===\n";
    foreach ($periodos as $p) {
        $ok($cambios->seccionDelPeriodo($mid, (int) $p['id']) === $origen, "periodo {$p['numero']} → {$origen}");
    }

    echo "\n=== 2. Guardas (las dos ramas) ===\n";
    $ok($cambios->motivoNoElegible($mid, $destino) === null, 'sujeto con destino válido: ELEGIBLE');
    $ok($cambios->motivoNoElegible($mid, $origen) !== null, 'destino = sección actual: RECHAZADO');
    $otroGrado = (int) $pdo->query("SELECT id FROM secciones WHERE anio_id = {$anioId}
                                     AND grado_id <> {$suj['grado_id']} LIMIT 1")->fetchColumn();
    $ok($cambios->motivoNoElegible($mid, $otroGrado) !== null, 'destino de otro grado: RECHAZADO');
    foreach ($pdo->query("SELECT matricula_oficial_id AS ofi, matricula_operativa_id AS ope, estado
                          FROM retornos_grado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ok($cambios->motivoNoElegible((int) $r['ope']) !== null,
            "operativa {$r['ope']} de un retorno: RECHAZADA");
        $ofi = $cambios->motivoNoElegible((int) $r['ofi']);
        if ($r['estado'] === 'activo') {
            $ok($ofi !== null, "oficial {$r['ofi']} con retorno ACTIVO: RECHAZADA");
        } else {
            $ok($ofi === null || !str_contains($ofi, 'retorno'),
                "oficial {$r['ofi']} con retorno REVERTIDO: no la frena el retorno");
        }
    }
    $libre = $tras->siguienteCorrelativo($anioId, 1);
    $ocupado = $contar("SELECT correlativo FROM traslados WHERE anio_id = ? AND estado = 'vigente' LIMIT 1", [$anioId]);
    if ($ocupado > 0) {
        $ok($rechaza(fn() => $cambios->ejecutar($mid, $destino, $ocupado, date('Y-m-d'), 'x', $usuario)) !== null,
            "R.D. {$ocupado} ocupada por un traslado: RECHAZADA");
    }
    $ok($rechaza(fn() => $cambios->ejecutar($mid, $destino, $libre, date('Y-m-d'), '   ', $usuario)) !== null,
        'motivo vacío: RECHAZADO');
    $ok($rechaza(fn() => $cambios->ejecutar($mid, $destino, $libre, '2026-02-30', 'x', $usuario)) !== null,
        'fecha inválida: RECHAZADA');
    $ok($contar("SELECT COUNT(*) FROM cambios_seccion") === $antesGlobal['cambios_seccion'],
        'los rechazos no dejaron ninguna fila');

    echo "\n=== 3. ejecutar() ===\n";
    $abiertoAntes = $vivas($mid, $origen, false);
    $cerradoAntes = $vivas($mid, $origen, true);
    $boletaAntes = [];
    foreach ($periodos as $p) {
        if ($p['estado'] === 'cerrado') { $boletaAntes[$p['id']] = $cal->getBoletaAlumno($mid, (int) $p['id']); }
    }

    $res = $cambios->ejecutar($mid, $destino, $libre, date('Y-m-d'), 'verificación', $usuario);
    echo "  → cambio {$res['cambio_id']} · {$res['rd_numero']} · archivadas: "
        . "{$res['competencias']} competencias, {$res['criterios']} criterios, {$res['omisiones']} omisiones\n";

    $ok($res['competencias'] === $abiertoAntes['cal']
        && $res['criterios'] === $abiertoAntes['crit']
        && $res['omisiones'] === $abiertoAntes['omi'],
        'el archivo tiene exactamente lo que estaba vivo en los bimestres no cerrados');
    $ok($res['criterios'] > 0, 'el archivo no es trivial (hay notas por criterio)');
    $ok($vivas($mid, $origen, false) === ['cal' => 0, 'crit' => 0, 'omi' => 0],
        'origen queda SIN filas vivas en los bimestres no cerrados');
    $ok($vivas($mid, $origen, true) === $cerradoAntes, 'los bimestres CERRADOS de origen quedan idénticos');
    foreach ($boletaAntes as $pid => $b) {
        $ok($cal->getBoletaAlumno($mid, (int) $pid) == $b, "boleta del periodo {$pid} (cerrado) sin cambios");
    }
    $ok($contar("SELECT seccion_id FROM matriculas WHERE id = ?", [$mid]) === $destino, 'la matrícula queda en destino');
    $ok($res['rd_numero'] === App\Models\TrasladoModel::formatearNumero(
            $libre, (int) $tras->getConfigAnio($anioId)['anio'],
            config('institucion_datos')['sufijo_constancia'] ?? 'CAVVG-DA'),
        'la R.D. usa la nomenclatura del traslado');
    $ok(!$tras->correlativoDisponible($anioId, $libre), "la R.D. {$libre} queda OCUPADA");

    echo "\n=== 4. sección(P) por bimestre ===\n";
    foreach ($periodos as $p) {
        $esperada = (int) $p['numero'] >= (int) $periodoCambio['numero'] ? $destino : $origen;
        $ok($cambios->seccionDelPeriodo($mid, (int) $p['id']) === $esperada,
            "periodo {$p['numero']} → {$esperada}");
    }
    $sqlOk = $contar("SELECT " . App\Models\CambioSeccionModel::sqlSeccionDelPeriodo('m', 'px.id') . "
                       FROM matriculas m JOIN periodos px ON px.id = ? WHERE m.id = ?",
                     [(int) $periodos[0]['id'], $mid]);
    $ok($sqlOk === $origen, 'la versión SQL con columna coincide con la PHP');

    echo "\n=== 5. Un cambio REVERTIDO no cuenta ===\n";
    $pdo->prepare("UPDATE cambios_seccion SET estado = 'revertido' WHERE id = ?")->execute([$res['cambio_id']]);
    $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$origen, $mid]);
    foreach ($periodos as $p) {
        $ok($cambios->seccionDelPeriodo($mid, (int) $p['id']) === $origen, "periodo {$p['numero']} → {$origen}");
    }
} finally {
    $pdo->rollBack();
}

echo "\n=== 5b. REGRESO a la sección anterior (decisión del 07/10/2026) ===\n";
$cerrado = $pdo->query("SELECT id, numero FROM periodos WHERE anio_id = {$anioId} AND estado = 'cerrado'
                        ORDER BY numero DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
try {
    $ok($rechaza(fn() => $cambios->ejecutar($mid, $destino, null, null, 'x', $usuario)) !== null,
        'cambio NORMAL sin R.D.: RECHAZADO');

    // Mismo bimestre abierto: ir y volver es REVERTIR, no un cambio nuevo.
    $cambios->ejecutar($mid, $destino, $tras->siguienteCorrelativo($anioId, 1), date('Y-m-d'), 'ida', $usuario);
    $ok($cambios->tipoDeMovimiento($mid, $origen) === 'revertir', 'volver en el MISMO bimestre abierto = revertir');
    $ok($rechaza(fn() => $cambios->ejecutar($mid, $origen, null, null, 'vuelta', $usuario)) !== null,
        'ejecutar() rechaza esa vuelta (corresponde revertir)');
} finally {
    $pdo->rollBack();
}

if ($cerrado) {
    $pdo->beginTransaction();
    try {
        // Ida registrada en un bimestre YA CERRADO (como las matrículas 259/339).
        $pdo->prepare("INSERT INTO cambios_seccion (matricula_id, anio_id, periodo_id, seccion_origen_id,
                         seccion_destino_id, rd_correlativo, rd_numero, rd_fecha, motivo, movido_por)
                       VALUES (?, ?, ?, ?, ?, 999, 'ida (verificación)', CURDATE(), 'ida', ?)")
            ->execute([$mid, $anioId, (int) $cerrado['id'], $origen, $destino, $usuario]);
        $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$destino, $mid]);

        $ok($cambios->tipoDeMovimiento($mid, $origen) === 'regreso',
            "volver tras cerrar el bimestre {$cerrado['numero']} = regreso");
        $ok($cambios->tipoDeMovimiento($mid, $otroGrado) === 'normal', 'ir a otra sección = normal');
        $res = $cambios->ejecutar($mid, $origen, null, null, 'regreso', $usuario);
        $ok($res['regreso'] === true && $res['rd_numero'] === null, 'el REGRESO se registra SIN R.D.');
        $ok($contar("SELECT COUNT(*) FROM cambios_seccion WHERE id = ? AND rd_correlativo IS NULL",
                [$res['cambio_id']]) === 1, 'y no ocupa ningún número');
        foreach ($periodos as $p) {
            $esperada = ((int) $p['numero'] >= (int) $cerrado['numero']
                         && (int) $p['numero'] < (int) $periodoCambio['numero']) ? $destino : $origen;
            $ok($cambios->seccionDelPeriodo($mid, (int) $p['id']) === $esperada,
                "ida y vuelta: periodo {$p['numero']} → {$esperada}");
        }
    } finally {
        $pdo->rollBack();
    }
} else {
    echo "  (sin bimestres cerrados: el regreso no es evaluable)\n";
}

echo "\n=== 5c. REVERTIR (mismo bimestre abierto; opción a del 07/10/2026) ===\n";
$pdo->beginTransaction();
try {
    $vivoAntes = $vivas($mid, $origen, false);
    // Criterios de origen CONFIRMADOS que recibirán algo del estudiante: una
    // nota O una omisión (restaurar cualquiera de las dos muta el criterio).
    $confirmadosAntes = array_map('intval', $pdo->query("
        SELECT DISTINCT cr.id FROM criterios cr
        JOIN cargas_academicas ca ON ca.id = cr.carga_id
        JOIN periodos pe ON pe.id = cr.periodo_id
        WHERE ca.seccion_id = {$origen} AND pe.estado <> 'cerrado'
          AND cr.eliminado_en IS NULL AND cr.confirmado_en IS NOT NULL
          AND (EXISTS (SELECT 1 FROM calificaciones_criterio cc
                        WHERE cc.criterio_id = cr.id AND cc.matricula_id = {$mid})
            OR EXISTS (SELECT 1 FROM omisiones_criterio oc
                        WHERE oc.criterio_id = cr.id AND oc.matricula_id = {$mid}))")
        ->fetchAll(PDO::FETCH_COLUMN));

    $nro = $tras->siguienteCorrelativo($anioId, 1);
    $res = $cambios->ejecutar($mid, $destino, $nro, date('Y-m-d'), 'ida', $usuario);
    $cid = $res['cambio_id'];

    // Destino le registra una nota (debe ARCHIVARSE con lado = 'destino').
    $critDestino = (int) $pdo->query("
        SELECT cr.id FROM criterios cr
        JOIN cargas_academicas ca ON ca.id = cr.carga_id
        JOIN periodos pe ON pe.id = cr.periodo_id
        WHERE ca.seccion_id = {$destino} AND pe.estado <> 'cerrado' AND cr.eliminado_en IS NULL
        LIMIT 1")->fetchColumn();
    if ($critDestino > 0) {
        $pdo->prepare("INSERT INTO calificaciones_criterio (criterio_id, matricula_id, nota) VALUES (?, ?, 15)")
            ->execute([$critDestino, $mid]);
    }

    // Guarda (a), rama BLOQUEA: un bloqueo en origen sobre lo archivado.
    $bloqReales = $cambios->bloqueosQueImpidenRevertir($cid);
    $creado = false;
    if ($bloqReales === []) {
        $a = $pdo->query("SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_criterio
                          WHERE cambio_id = {$cid} AND lado = 'origen' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO bloqueos_competencia (carga_id, competencia_id, periodo_id, bloqueado_por)
                       VALUES (?, ?, ?, ?)")
            ->execute([$a['carga_id'], $a['competencia_id'], $a['periodo_id'], $usuario]);
        $creado = true;
    }
    $ok($cambios->motivoNoRevertible($cid) !== null, 'con competencia BLOQUEADA en origen: NO se revierte');
    $ok($rechaza(fn() => $cambios->revertir($cid, 'x', $usuario)) !== null, 'revertir() lo rechaza');

    // Rama DEJA PASAR: sin esos bloqueos (dentro de la transacción; el rollback los repone).
    $pdo->exec("DELETE bc FROM bloqueos_competencia bc
                JOIN (SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_criterio
                       WHERE cambio_id = {$cid} AND lado = 'origen'
                      UNION SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_omision
                       WHERE cambio_id = {$cid} AND lado = 'origen'
                      UNION SELECT carga_id, competencia_id, periodo_id FROM cambios_seccion_competencia
                       WHERE cambio_id = {$cid} AND lado = 'origen') a
                  ON a.carga_id = bc.carga_id AND a.competencia_id = bc.competencia_id
                 AND a.periodo_id = bc.periodo_id");
    echo "  (bloqueos de origen " . ($creado ? 'creados' : 'reales') . " para la rama que bloquea)\n";
    $ok($cambios->motivoNoRevertible($cid) === null, 'sin bloqueos en origen: SÍ se revierte');
    $ok($rechaza(fn() => $cambios->revertir($cid, '  ', $usuario)) !== null, 'motivo vacío: RECHAZADO');

    $rev = $cambios->revertir($cid, 'la familia desiste', $usuario);
    echo "  → restauradas {$rev['restauradas']}, omitidas {$rev['omitidas']}, desconfirmados "
        . count($rev['desconfirmados']) . ", archivadas de destino {$rev['archivadas_destino']['criterios']}\n";

    $ok($vivas($mid, $origen, false)['crit'] === $vivoAntes['crit']
        && $vivas($mid, $origen, false)['omi'] === $vivoAntes['omi'],
        'origen recupera todas sus notas por criterio y omisiones');
    $ok($vivas($mid, $origen, false)['cal'] <= $vivoAntes['cal'],
        'los promedios restaurados no superan los que había');
    $ok($vivas($mid, $destino, false) === ['cal' => 0, 'crit' => 0, 'omi' => 0], 'destino queda sin filas vivas');
    if ($critDestino > 0) {
        $ok($rev['archivadas_destino']['criterios'] >= 1, 'lo de destino quedó ARCHIVADO (lado destino)');
    }
    sort($confirmadosAntes);
    $des = $rev['desconfirmados']; sort($des);
    $ok($des === $confirmadosAntes, 'se desconfirman EXACTAMENTE los criterios confirmados que reciben notas');
    $ok($contar("SELECT COUNT(*) FROM criterios WHERE confirmado_en IS NOT NULL AND id IN ("
            . (implode(',', $des) ?: '0') . ")") === 0, 'y quedan sin confirmar');
    $ok($contar("SELECT seccion_id FROM matriculas WHERE id = ?", [$mid]) === $origen, 'la matrícula vuelve a origen');
    $ok($contar("SELECT COUNT(*) FROM cambios_seccion WHERE id = ? AND estado = 'revertido'", [$cid]) === 1,
        'el cambio queda REVERTIDO');
    $ok($tras->correlativoDisponible($anioId, $nro), "la R.D. {$nro} queda LIBRE");
    foreach ($periodos as $p) {
        $ok($cambios->seccionDelPeriodo($mid, (int) $p['id']) === $origen, "periodo {$p['numero']} → {$origen}");
    }
    $ok($rechaza(fn() => $cambios->revertir($cid, 'x', $usuario)) !== null, 'revertir dos veces: RECHAZADO');
} finally {
    $pdo->rollBack();
}

if ($cerrado) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO cambios_seccion (matricula_id, anio_id, periodo_id, seccion_origen_id,
                         seccion_destino_id, motivo, movido_por) VALUES (?, ?, ?, ?, ?, 'ida', ?)")
            ->execute([$mid, $anioId, (int) $cerrado['id'], $origen, $destino, $usuario]);
        $cid = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$destino, $mid]);
        $ok($cambios->motivoNoRevertible($cid) !== null, 'cambio de un bimestre CERRADO: NO se revierte (es regreso)');
    } finally {
        $pdo->rollBack();
    }
}

echo "\n=== 5d. AVISOS (decisión 8) ===\n";
$antesNotif = $contar("SELECT COUNT(*) FROM notificaciones");
$pdo->beginTransaction();
try {
    $res = $cambios->ejecutar($mid, $destino, $tras->siguienteCorrelativo($anioId, 1), date('Y-m-d'), 'avisos', $usuario);
    $per = (int) $periodoCambio['id'];
    $aux = new App\Models\AuxiliarSeccionModel();
    $esperados = [];
    foreach ([$origen, $destino] as $sec) {
        foreach ($pdo->query("SELECT DISTINCT ca.docente_id FROM cargas_academicas ca
                              JOIN anios_academicos aa ON aa.id = ca.anio_id AND aa.estado = 'activo'
                              JOIN usuarios u ON u.id = ca.docente_id AND u.estado = 'activo'
                              WHERE ca.seccion_id = {$sec} AND ca.estado = 'activa'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $esperados[(int) $d] = true;
        }
        $t = (int) $pdo->query("SELECT s.tutor_id FROM secciones s JOIN usuarios u ON u.id = s.tutor_id
                                 AND u.estado = 'activo' WHERE s.id = {$sec}")->fetchColumn();
        if ($t) { $esperados[$t] = true; }
        $a = $aux->vigente($sec, $per);
        if ($a) { $esperados[$a['auxiliar_id']] = true; }
    }
    $marcas = implode(',', array_map(fn($r) => "'" . $r . "'", ROLES_DIRECCION));
    foreach ($pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                          WHERE r.codigo IN ({$marcas}) AND u.estado = 'activo'")->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $esperados[(int) $d] = true;
    }
    // El emisor es un docente de destino a propósito: no debe recibir su propio aviso.
    $emisor = (int) array_key_first($esperados);
    unset($esperados[$emisor]);

    $n = $cambios->avisar($res['cambio_id'], 'cambio', $emisor);
    $recibidos = array_map('intval', $pdo->query("SELECT usuario_id FROM notificaciones
        WHERE tipo = 'cambio_seccion' AND id > (SELECT COALESCE(MAX(id), 0) - {$n} FROM notificaciones)")
        ->fetchAll(PDO::FETCH_COLUMN));
    sort($recibidos);
    $esp = array_keys($esperados); sort($esp);
    $ok($n === count($esperados) && $recibidos === $esp,
        "avisados {$n}: tutores, docentes y auxiliares de las dos secciones + dirección, sin repetir");
    $ok(!in_array($emisor, $recibidos, true), 'quien hace la operación NO recibe aviso');
} finally {
    $pdo->rollBack();
}
$ok($contar("SELECT COUNT(*) FROM notificaciones") === $antesNotif, 'notificaciones vuelve a su conteo');

echo "\n=== 5e. PROCEDENCIA: lo que ve el docente de destino (fase 3) ===\n";
$pdo->beginTransaction();
try {
    $res = $cambios->ejecutar($mid, $destino, $tras->siguienteCorrelativo($anioId, 1), date('Y-m-d'), 'procedencia', $usuario);
    $proc = $cambios->procedenciasEnSeccion($destino);
    $ok(isset($proc[$mid]), 'el estudiante lleva el chip en la sección de destino');

    $fuera = $cambios->bimestresCursadosFuera($mid, $destino);
    $cerradosAnio = array_values(array_filter($periodos, fn($p) => $p['estado'] === 'cerrado'));
    $ok(count($fuera) === count($cerradosAnio)
        && array_column($fuera, 'seccion_id') === array_fill(0, count($cerradosAnio), $origen),
        'los bimestres CERRADOS se ven como cursados en la sección de origen (' . count($fuera) . ')');

    // Una carga de destino con su equivalente en origen que tenga nota archivada.
    $cargaD = $pdo->query("
        SELECT ca.*, COALESCE(ca.area_id, sa.area_id) AS area_resuelta_id
        FROM cargas_academicas ca LEFT JOIN subareas sa ON sa.id = ca.subarea_id
        WHERE ca.seccion_id = {$destino} AND ca.estado = 'activa'
          AND EXISTS (SELECT 1 FROM cambios_seccion_criterio a
                      JOIN cargas_academicas co ON co.id = a.carga_id
                      LEFT JOIN subareas so ON so.id = co.subarea_id
                      WHERE a.cambio_id = {$res['cambio_id']}
                        AND COALESCE(co.area_id, so.area_id) = COALESCE(ca.area_id, sa.area_id))
        LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cargaD) {
        $eq = $cambios->cargasEquivalentes($cargaD, $origen);
        $ok($eq !== [], 'la carga de destino tiene su equivalente en origen');
        $ref = $cambios->referenciaArchivada($mid, $destino, array_map(fn($e) => (int) $e['id'], $eq));
        $nCrit = array_sum(array_map(fn($c) => count($c['criterios']), $ref['competencias']));
        $esperado = $contar("SELECT COUNT(*) FROM cambios_seccion_criterio WHERE cambio_id = ? AND lado = 'origen'
                              AND carga_id IN (" . implode(',', array_map(fn($e) => (int) $e['id'], $eq)) . ")",
                            [$res['cambio_id']])
                  + $contar("SELECT COUNT(*) FROM cambios_seccion_omision WHERE cambio_id = ? AND lado = 'origen'
                              AND carga_id IN (" . implode(',', array_map(fn($e) => (int) $e['id'], $eq)) . ")",
                            [$res['cambio_id']]);
        $ok($nCrit === $esperado && $nCrit > 0,
            "la referencia muestra lo archivado de esa área ({$nCrit} criterios), solo de su carga equivalente");
    } else {
        echo "  (sin carga de destino con equivalente archivado: rama no evaluable)\n";
    }
    $ok($cambios->referenciaArchivada($mid, $origen, [1])['competencias'] === [],
        'en la sección de ORIGEN no hay referencia (decisión 15)');
} finally {
    $pdo->rollBack();
}

echo "\n=== 6. ROLLBACK ===\n";
foreach ($tablas as $t) {
    $ok($contar("SELECT COUNT(*) FROM {$t}") === $antesGlobal[$t], "{$t} vuelve a {$antesGlobal[$t]} filas");
}
$ok($contar("SELECT seccion_id FROM matriculas WHERE id = ?", [$mid]) === $origen, 'la matrícula vuelve a su sección');

echo "\n" . ($fallos === 0 ? "TODO OK\n" : "FALLAS: {$fallos}\n");
exit($fallos === 0 ? 0 : 1);
