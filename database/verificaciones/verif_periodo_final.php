<?php

/**
 * Verificación de la REGLA DEL PERIODO FINAL (regla del 10/08/2026,
 * implementada el 01/10/2026).
 * Uso: php database/verificaciones/verif_periodo_final.php
 *
 * En el último periodo del año (mayor `numero`) el docente pierde la autonomía
 * de B1-B3: todas las competencias deben evaluarse, o el estudiante queda con
 * la situación final PENDIENTE. El cierre ADMITE vacías, pero solo con
 * confirmación (decisión del usuario, 01/10/2026).
 *
 * Mide los DOS lados de cada guarda —bloquear y dejar pasar—:
 *  1. `AnioAcademicoModel::esPeriodoFinal` — punto único (MAX(numero), no el 4).
 *  2. `competenciasVaciasDelPeriodo` — cuadra con una copia de control escrita
 *     aquí; una nota de un estudiante VIGENTE la llena, la de un TRASLADADO no;
 *     una sección entera exonerada del área no cuenta como vacía.
 *  3. `avisoPeriodoFinal` — con vacías, avisa y cuenta estudiantes PEND.
 *  4. La guarda del docente (`errorBloqueoCompetencia`): «No se evaluó» se
 *     rechaza en el periodo final, se permite fuera de él y se permite si todo
 *     el roster está exonerado.
 *  5. `riesgo_resumen()['definitiva']` sobre el periodo final simulado.
 *
 * ESCRIBE dentro de una TRANSACCIÓN con ROLLBACK (simula que el II Bimestre es
 * el último del año, una nota, un traslado y exoneraciones). Lleva el guard de
 * secretos: NO en producción.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');
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

use App\Controllers\Docente\CalificacionController;
use App\Models\AnioAcademicoModel;
use App\Models\SituacionFinalModel;

// ── Modo SUBPROCESO: ejecuta el `bloquear()` REAL ────────────────────────
// `json()` termina con `exit`, así que cada caso corre en su propio proceso.
// Abre una transacción y NUNCA hace commit: al salir, MySQL la deshace.
// Uso interno: php verif_periodo_final.php --bloquear <usuario> <carga> <competencia> <plazo_vencido 0|1>
if (($argv[1] ?? '') === '--bloquear') {
    [, , $usuarioId, $cargaId, $compId, $vencido] = $argv;
    $pdo = Core\Database::connect();
    $pdo->beginTransaction();
    $activo = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE periodos SET limite_notas = ? WHERE id = ?")
        ->execute([$vencido === '1' ? '2000-01-01 00:00:00' : '2999-12-31 00:00:00', $activo]);
    $_SESSION['auth_user']   = ['id' => (int) $usuarioId, 'rol_codigo' => 'docente', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
    $_SESSION['_csrf_token'] = 'verif';
    $_POST = ['_csrf_token' => 'verif'];
    (new CalificacionController())->bloquear($cargaId, $compId);
    exit(0);
}

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo  = Core\Database::connect();
$anio = new AnioAcademicoModel();

const PERIODO_ENSAYO = 2;   // II Bimestre: cerrado, con 59 vacías medidas el 01/10/2026
$MSG_FINAL = 'En el último bimestre del año';

// Controlador real sin constructor (el constructor exige sesión).
$rc   = new ReflectionClass(CalificacionController::class);
$ctrl = $rc->newInstanceWithoutConstructor();
foreach ([
    'calModel'     => new App\Models\CalificacionModel(),
    'critModel'    => new App\Models\CriterioModel(),
    'omisionModel' => new App\Models\OmisionCriterioModel(),
    'exoModel'     => new App\Models\ExoneracionModel(),
] as $prop => $obj) {
    $p = $rc->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($ctrl, $obj);
}
$guarda = $rc->getMethod('errorBloqueoCompetencia');
$guarda->setAccessible(true);
$periodoArr = $pdo->query("SELECT id, anio_id FROM periodos WHERE id = " . PERIODO_ENSAYO)->fetch(PDO::FETCH_ASSOC);
$bloquearSinNotas = static fn(int $carga, int $comp): ?string
    => $guarda->invoke($ctrl, $carga, $comp, $periodoArr, true, ''); // nivel: no aplica a «No se evaluó»

// Copia de CONTROL de las vacías, escrita aquí a propósito (sin exoneraciones:
// en el ensayo no hay secciones enteras exoneradas, y el paso 2c lo prueba aparte).
$control = static fn(): int => (int) $pdo->query("
    SELECT COUNT(*) FROM bloqueos_competencia bc
    JOIN cargas_academicas ca ON ca.id = bc.carga_id AND ca.estado = 'activa'
    WHERE bc.periodo_id = " . PERIODO_ENSAYO . "
      AND NOT EXISTS (SELECT 1 FROM calificaciones k JOIN matriculas m ON m.id = k.matricula_id
                      WHERE k.carga_id = bc.carga_id AND k.competencia_id = bc.competencia_id
                        AND k.periodo_id = bc.periodo_id AND m.tipo NOT IN ('trasladado','retirado'))
")->fetchColumn();

$esVacia = static function (int $carga, int $comp) use ($anio): bool {
    foreach ($anio->competenciasVaciasDelPeriodo(PERIODO_ENSAYO) as $v) {
        if ((int) $v['carga_id'] === $carga && (int) $v['competencia_id'] === $comp) {
            return true;
        }
    }
    return false;
};

// Dos pares vacíos ACADÉMICOS, sin criterios vivos, de cargas distintas.
$pares = $pdo->query("
    SELECT bc.carga_id, bc.competencia_id, ca.seccion_id,
           COALESCE(ca.area_id, sa.area_id) AS area_id, ca.subarea_id
    FROM bloqueos_competencia bc
    JOIN cargas_academicas ca ON ca.id = bc.carga_id AND ca.estado = 'activa'
    LEFT JOIN subareas sa ON sa.id = ca.subarea_id
    JOIN areas a ON a.id = COALESCE(ca.area_id, sa.area_id) AND a.tipo NOT IN ('transversal','tutoria')
    WHERE bc.periodo_id = " . PERIODO_ENSAYO . "
      AND NOT EXISTS (SELECT 1 FROM calificaciones k WHERE k.carga_id = bc.carga_id
                        AND k.competencia_id = bc.competencia_id AND k.periodo_id = bc.periodo_id)
      AND NOT EXISTS (SELECT 1 FROM criterios cr WHERE cr.carga_id = bc.carga_id
                        AND cr.competencia_id = bc.competencia_id AND cr.periodo_id = bc.periodo_id
                        AND cr.eliminado_en IS NULL)
    GROUP BY bc.carga_id
    ORDER BY bc.carga_id
    LIMIT 2
")->fetchAll(PDO::FETCH_ASSOC);
if (count($pares) < 2) {
    fwrite(STDERR, "No hay dos pares vacíos en el periodo de ensayo: no se puede verificar.\n");
    exit(1);
}
[$parA, $parB] = $pares;

// ── 1. Punto único del periodo final ─────────────────────────────────────
echo "1. esPeriodoFinal — MAX(numero) del año\n";
$finalReal = (int) $pdo->query("
    SELECT p.id FROM periodos p
    WHERE p.numero = (SELECT MAX(numero) FROM periodos WHERE anio_id = p.anio_id)
    ORDER BY p.anio_id DESC LIMIT 1
")->fetchColumn();
$chk('el último periodo del año es final', $anio->esPeriodoFinal($finalReal), "periodo $finalReal");
$chk('el periodo de ensayo NO es final (todavía)', !$anio->esPeriodoFinal(PERIODO_ENSAYO));
$chk('un periodo inexistente no es final', !$anio->esPeriodoFinal(999999));

// ── 4a. Guarda del docente FUERA del periodo final: deja pasar ───────────
echo "4a. «No se evaluó» fuera del periodo final\n";
$r = $bloquearSinNotas((int) $parA['carga_id'], (int) $parA['competencia_id']);
$chk('fuera del periodo final NO salta la guarda nueva',
    $r === null || !str_contains($r, $MSG_FINAL), $r ?? 'permitido');

$pdo->beginTransaction();
try {
    // Simula que el periodo de ensayo es el último del año.
    $pdo->exec("UPDATE periodos SET numero = 99 WHERE id = " . PERIODO_ENSAYO);
    $chk('simulado: el periodo de ensayo pasa a ser final', $anio->esPeriodoFinal(PERIODO_ENSAYO));
    $chk('simulado: el que era final deja de serlo',
        $finalReal === PERIODO_ENSAYO || !$anio->esPeriodoFinal($finalReal));

    // ── 2. Competencias vacías ───────────────────────────────────────────
    echo "2. competenciasVaciasDelPeriodo\n";
    $vacias = $anio->competenciasVaciasDelPeriodo(PERIODO_ENSAYO);
    $chk('cuadra con la copia de control', count($vacias) === $control(),
        count($vacias) . ' vs ' . $control());
    $chk('el par A está vacío', $esVacia((int) $parA['carga_id'], (int) $parA['competencia_id']));

    // 2a. Una nota de un estudiante VIGENTE la llena.
    $mat = (int) $pdo->query("
        SELECT m.id FROM matriculas m
        JOIN periodos per ON per.id = " . PERIODO_ENSAYO . " AND per.anio_id = m.anio_id
        WHERE m.seccion_id = {$parA['seccion_id']} AND m.tipo NOT IN ('trasladado','retirado')
        ORDER BY m.id LIMIT 1
    ")->fetchColumn();
    $usuario = (int) $pdo->query("SELECT id FROM usuarios ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO calificaciones (matricula_id, carga_id, periodo_id, competencia_id, nota_numerica, registrado_por)
                   VALUES (?, ?, ?, ?, 15, ?)")
        ->execute([$mat, $parA['carga_id'], PERIODO_ENSAYO, $parA['competencia_id'], $usuario]);
    $chk('con una nota de un vigente deja de estar vacía',
        !$esVacia((int) $parA['carga_id'], (int) $parA['competencia_id']));

    // 2b. Si ese estudiante se TRASLADA, su nota ya no cuenta.
    $tipoOriginal = (string) $pdo->query("SELECT tipo FROM matriculas WHERE id = $mat")->fetchColumn();
    $pdo->exec("UPDATE matriculas SET tipo = 'trasladado' WHERE id = $mat");
    $chk('si el único evaluado es TRASLADADO, vuelve a estar vacía',
        $esVacia((int) $parA['carga_id'], (int) $parA['competencia_id']));
    $pdo->exec("UPDATE matriculas SET tipo = 'retirado' WHERE id = $mat");
    $chk('si el único evaluado es RETIRADO, también',
        $esVacia((int) $parA['carga_id'], (int) $parA['competencia_id']));

    // 2c. Sección entera exonerada del área: no hay nada que evaluar.
    $chk('el par B está vacío antes de exonerar', $esVacia((int) $parB['carga_id'], (int) $parB['competencia_id']));
    $vigentesB = $pdo->query("
        SELECT m.id, m.anio_id FROM matriculas m
        JOIN periodos per ON per.id = " . PERIODO_ENSAYO . " AND per.anio_id = m.anio_id
        WHERE m.seccion_id = {$parB['seccion_id']} AND m.tipo NOT IN ('trasladado','retirado')
    ")->fetchAll(PDO::FETCH_ASSOC);
    $insExo = $pdo->prepare("INSERT INTO exoneraciones (matricula_id, anio_id, area_id, subarea_id, motivo, registrado_por)
                             VALUES (?, ?, ?, NULL, 'verif_periodo_final', ?)");
    $primero = array_shift($vigentesB);
    foreach ($vigentesB as $m) {
        $insExo->execute([$m['id'], $m['anio_id'], $parB['area_id'], $usuario]);
    }
    $chk('con UN estudiante sin exonerar sigue vacía',
        $esVacia((int) $parB['carga_id'], (int) $parB['competencia_id']));
    $r = $bloquearSinNotas((int) $parB['carga_id'], (int) $parB['competencia_id']);
    $chk('…y el docente no puede marcar «No se evaluó»', $r !== null && str_contains($r, $MSG_FINAL), $r ?? 'permitido');
    $insExo->execute([$primero['id'], $primero['anio_id'], $parB['area_id'], $usuario]);
    $chk('con TODA la sección exonerada ya no cuenta como vacía',
        !$esVacia((int) $parB['carga_id'], (int) $parB['competencia_id']));

    // ── 4b. Guarda del docente EN el periodo final ───────────────────────
    echo "4b. «No se evaluó» en el periodo final\n";
    $r = $bloquearSinNotas((int) $parB['carga_id'], (int) $parB['competencia_id']);
    $chk('todo el roster exonerado: la guarda nueva NO salta',
        $r === null || !str_contains($r, $MSG_FINAL), $r ?? 'permitido');
    $pdo->prepare("UPDATE matriculas SET tipo = ? WHERE id = ?")->execute([$tipoOriginal, $mat]);
    $pdo->prepare("DELETE FROM calificaciones WHERE matricula_id = ? AND carga_id = ? AND periodo_id = ? AND competencia_id = ?")
        ->execute([$mat, $parA['carga_id'], PERIODO_ENSAYO, $parA['competencia_id']]);
    $r = $bloquearSinNotas((int) $parA['carga_id'], (int) $parA['competencia_id']);
    $chk('competencia sin criterios: se rechaza', $r !== null && str_contains($r, $MSG_FINAL), $r ?? 'permitido');

    // ── 3. El aviso del cierre ───────────────────────────────────────────
    echo "3. avisoPeriodoFinal\n";
    $aviso = $anio->avisoPeriodoFinal(PERIODO_ENSAYO);
    $chk('con vacías hay aviso', $aviso !== null);
    if ($aviso !== null) {
        $chk('total de vacías = las del modelo', $aviso['total_vacias'] === count($anio->competenciasVaciasDelPeriodo(PERIODO_ENSAYO)));
        $chk('la muestra no supera 40 filas', count($aviso['vacias']) <= 40);
        $chk('hay estudiantes con la situación final PENDIENTE', $aviso['pendientes'] > 0, (string) $aviso['pendientes']);
        $chk('el texto nombra las tres cifras',
            str_contains(AnioAcademicoModel::textoAvisoPeriodoFinal($aviso), (string) $aviso['total_vacias']));
    }

    // ── 5. definitiva ────────────────────────────────────────────────────
    echo "5. riesgo_resumen()['definitiva'] en el periodo final simulado\n";
    $res = riesgo_resumen((new SituacionFinalModel())->porGrado(PERIODO_ENSAYO));
    $chk('es periodo final', $res['periodo_final'] === true);
    $chk('con PEND no es definitiva', $res['pendiente_final'] > 0 && $res['definitiva'] === false);
} finally {
    $pdo->rollBack();
}

// El rollback devolvió todo.
$chk('rollback: el periodo de ensayo vuelve a no ser final', !$anio->esPeriodoFinal(PERIODO_ENSAYO));
$chk('rollback: las vacías vuelven a la cifra de control', count($anio->competenciasVaciasDelPeriodo(PERIODO_ENSAYO)) === $control());

// ── 6. ANCLA única del periodo final (01/10/2026) ───────────────────────
echo "6. ultimoPeriodoDelAnio — el ancla que consumen todos\n";
foreach ($pdo->query("SELECT DISTINCT anio_id FROM periodos")->fetchAll(PDO::FETCH_COLUMN) as $anioId) {
    $esperado = (int) $pdo->query("SELECT id FROM periodos WHERE anio_id = " . (int) $anioId . " ORDER BY numero DESC LIMIT 1")->fetchColumn();
    $ancla    = $anio->ultimoPeriodoDelAnio((int) $anioId);
    $chk("año $anioId: el ancla es el de mayor numero", $ancla !== null && (int) $ancla['id'] === $esperado);
    $todos = true;
    foreach ($pdo->query("SELECT id FROM periodos WHERE anio_id = " . (int) $anioId)->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $todos = $todos && ($anio->esPeriodoFinal((int) $pid) === ((int) $pid === $esperado));
    }
    $chk("año $anioId: esPeriodoFinal coincide con el ancla en cada periodo", $todos);
}
$chk('un año sin periodos no tiene último', $anio->ultimoPeriodoDelAnio(999999) === null);
$dp = new ReflectionMethod(SituacionFinalModel::class, 'datosPeriodo');
$dp->setAccessible(true);
$chk('SituacionFinalModel::datosPeriodo usa el ancla',
    $dp->invoke(new SituacionFinalModel(), $finalReal)[1] === true
    && $dp->invoke(new SituacionFinalModel(), PERIODO_ENSAYO)[1] === false);
// Ninguna copia a mano sobrevive: el `MAX(numero) FROM periodos` y
// `getUltimoBimestreDelAnio` solo pueden vivir en el ancla. Se busca la forma
// EXACTA de la copia: `AuxiliarSeccionModel::vigentesSql` también hace un
// `MAX(numero)`, pero sobre `auxiliar_secciones` (asignación vigente), que es
// otra pregunta y no debe saltar.
$copias = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || $f->getFilename() === 'AnioAcademicoModel.php') {
        continue;
    }
    $src = (string) file_get_contents($f->getPathname());
    if (str_contains($src, 'getUltimoBimestreDelAnio') || preg_match('/MAX\(\s*\w*\.?numero\s*\)\s*FROM\s+periodos\b/i', $src)) {
        $copias[] = $f->getFilename();
    }
}
$chk('ninguna copia a mano del «último periodo» fuera del ancla', $copias === [], implode(', ', $copias));

// ── 7. bloquear(): dueño de la carga y plazo estricto (01/10/2026) ───────
echo "7. bloquear() — dueño de la carga y plazo (controlador real, subproceso)\n";
$carga = $pdo->query("
    SELECT ca.id, ca.docente_id, c.id AS comp_id
    FROM cargas_academicas ca
    JOIN periodos per ON per.estado = 'activo' AND per.anio_id = ca.anio_id
    JOIN competencias c ON (ca.subarea_id IS NOT NULL AND c.subarea_id = ca.subarea_id)
                        OR (ca.subarea_id IS NULL AND c.area_id = ca.area_id)
    WHERE ca.estado = 'activa' AND ca.docente_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM bloqueos_competencia b WHERE b.carga_id = ca.id
                        AND b.competencia_id = c.id AND b.periodo_id = per.id)
    ORDER BY ca.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$carga) {
    $chk('hay una carga del periodo activo con una competencia sin bloquear', false);
} else {
    $otro   = (int) $pdo->query("SELECT id FROM usuarios WHERE id <> " . (int) $carga['docente_id'] . " ORDER BY id LIMIT 1")->fetchColumn();
    $llamar = static function (int $usuario, bool $vencido) use ($carga): array {
        $cmd = sprintf('php %s --bloquear %d %d %d %d', escapeshellarg(__FILE__),
            $usuario, $carga['id'], $carga['comp_id'], $vencido ? 1 : 0);
        $r = json_decode((string) shell_exec($cmd), true);
        return is_array($r) ? $r : ['success' => null, 'mensaje' => '(sin JSON)'];
    };
    $bloqueosAntes = (int) $pdo->query("SELECT COUNT(*) FROM bloqueos_competencia")->fetchColumn();

    $r = $llamar($otro, false);
    $chk('otro usuario: se rechaza por la carga', $r['success'] === false && $r['mensaje'] === 'Carga no encontrada.', (string) $r['mensaje']);
    $r = $llamar((int) $carga['docente_id'], true);
    $chk('dueño con plazo VENCIDO: se rechaza por el plazo', $r['success'] === false && str_contains((string) $r['mensaje'], 'plazo'), (string) $r['mensaje']);
    $r = $llamar((int) $carga['docente_id'], false);
    $chk('dueño con plazo vigente: pasa las dos guardas',
        $r['mensaje'] !== '(sin JSON)' && $r['mensaje'] !== 'Carga no encontrada.'
        && !str_contains((string) $r['mensaje'], 'plazo'), (string) $r['mensaje']);
    $chk('los subprocesos no dejaron bloqueos escritos',
        (int) $pdo->query("SELECT COUNT(*) FROM bloqueos_competencia")->fetchColumn() === $bloqueosAntes);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
