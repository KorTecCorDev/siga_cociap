<?php

/**
 * Verificación de las GUARDAS DE LA CONCLUSIÓN DESCRIPTIVA (F0 del plan de
 * bloqueos, 09/10/2026). Uso: php database/verificaciones/verif_guardas_conclusion.php
 *
 * Mide los DOS lados de cada guarda —bloquear y dejar pasar—:
 *  1. La ruta vieja `POST /docente/calificaciones/conclusion` ya no existe (ni
 *     la ruta ni el método): escribía la conclusión de cualquier carga.
 *  2. `errorBloqueoCompetencia` exige en el SERVIDOR la conclusión obligatoria
 *     (primaria B/C, secundaria C). Antes solo la exigía `resumen.js`. Las
 *     transversales no aplican (la conclusión es del tutor).
 *  3. `guardarConclusionAlumno` (controlador real, subproceso): rechaza la carga
 *     ajena, el plazo vencido y la competencia bloqueada.
 *
 * ESCRIBE dentro de una TRANSACCIÓN con ROLLBACK (vacía una conclusión para
 * probar la guarda). Lleva el guard de secretos: NO en producción.
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

// ── Modo SUBPROCESO: ejecuta el `guardarConclusionAlumno()` REAL ─────────
// `json()` termina con `exit`: cada caso corre en su propio proceso, dentro de
// una transacción que NUNCA hace commit.
// Uso interno: --conclusion <usuario> <carga> <competencia> <matricula> <plazo_vencido 0|1>
if (($argv[1] ?? '') === '--conclusion') {
    [, , $usuarioId, $cargaId, $compId, $matId, $vencido] = $argv;
    $pdo = Core\Database::connect();
    $pdo->beginTransaction();
    $activo = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE periodos SET limite_notas = ? WHERE id = ?")
        ->execute([$vencido === '1' ? '2000-01-01 00:00:00' : '2999-12-31 00:00:00', $activo]);
    $_SESSION['auth_user']   = ['id' => (int) $usuarioId, 'rol_codigo' => 'docente', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
    $_SESSION['_csrf_token'] = 'verif';
    $_POST = ['_csrf_token' => 'verif', 'matricula_id' => $matId, 'conclusion' => 'Texto de prueba del verificador.'];
    (new CalificacionController())->guardarConclusionAlumno($cargaId, $compId);
    exit(0);
}

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo = Core\Database::connect();

// ── 1. La ruta vieja se retiró ───────────────────────────────────────────
echo "1. Ruta vieja de conclusión retirada\n";
$rutas = (string) file_get_contents(ROOT_PATH . '/routes/web.php');
$chk('no hay ruta POST /docente/calificaciones/conclusion',
    !preg_match("~->post\(\s*'/docente/calificaciones/conclusion'~", $rutas));
$chk('CalificacionController ya no tiene guardarConclusion()',
    !method_exists(CalificacionController::class, 'guardarConclusion'));
$chk('el JS ya no llama a la ruta vieja',
    !str_contains((string) file_get_contents(ROOT_PATH . '/resources/js/calificaciones.js'), '/docente/calificaciones/conclusion`'));

// ── 2. Conclusión obligatoria en el servidor ─────────────────────────────
echo "2. errorBloqueoCompetencia exige la conclusión obligatoria\n";
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
$validar = static function (array $c) use ($guarda, $ctrl, $pdo): ?string {
    $per = $pdo->query("SELECT id, anio_id FROM periodos WHERE id = " . (int) $c['periodo_id'])->fetch(PDO::FETCH_ASSOC);
    return $guarda->invoke($ctrl, (int) $c['carga_id'], (int) $c['competencia_id'], $per, false, (string) $c['nivel_codigo']);
};

// Candidatas: competencias que un DOCENTE aprobó (pasaron por resumen.js) y que
// tienen al menos una nota con conclusión obligatoria escrita.
$candidatas = static fn(bool $transversal): array => $pdo->query("
    SELECT k.carga_id, k.competencia_id, k.periodo_id, k.matricula_id, n.codigo AS nivel_codigo
    FROM calificaciones k
    JOIN bloqueos_competencia b ON b.carga_id = k.carga_id AND b.competencia_id = k.competencia_id
                               AND b.periodo_id = k.periodo_id AND b.origen = 'docente'
    JOIN cargas_academicas ca ON ca.id = k.carga_id
    JOIN secciones s ON s.id = ca.seccion_id
    JOIN grados g    ON g.id = s.grado_id
    JOIN niveles n   ON n.id = g.nivel_id
    JOIN competencias c ON c.id = k.competencia_id
    LEFT JOIN areas a   ON a.id = c.area_id
    WHERE " . ($transversal ? "a.tipo = 'transversal'" : "(a.tipo IS NULL OR a.tipo <> 'transversal')") . "
      AND k.nota_numerica < " . NOTA_MIN_B . "
      " . ($transversal ? '' : "AND TRIM(COALESCE(k.conclusion_descriptiva, '')) <> ''") . "
    ORDER BY k.periodo_id DESC, k.carga_id
    LIMIT 40
")->fetchAll(PDO::FETCH_ASSOC);

$caso = null;
foreach ($candidatas(false) as $c) {
    if ($validar($c) === null) { $caso = $c; break; }
}
if ($caso === null) {
    $chk('hay una competencia académica aprobada por el docente con conclusión obligatoria', false);
} else {
    $chk('con la conclusión escrita: la guarda deja pasar', $validar($caso) === null);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE calificaciones SET conclusion_descriptiva = '  ' WHERE matricula_id = ? AND carga_id = ? AND competencia_id = ? AND periodo_id = ?")
            ->execute([$caso['matricula_id'], $caso['carga_id'], $caso['competencia_id'], $caso['periodo_id']]);
        $msg = (string) $validar($caso);
        $chk('con la conclusión vacía (solo espacios): la guarda bloquea',
            str_contains($msg, 'conclusión(es) descriptiva(s) obligatoria(s)'), $msg);
    } finally {
        $pdo->rollBack();
    }
    $chk('rollback: vuelve a dejar pasar', $validar($caso) === null);
}

$trans = null;
foreach ($candidatas(true) as $c) {
    if ($validar($c) === null) { $trans = $c; break; }
}
if ($trans === null) {
    echo "  [SKIP] no hay transversal aprobada por el docente con nota en C en esta BD\n";
} else {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE calificaciones SET conclusion_descriptiva = NULL WHERE matricula_id = ? AND carga_id = ? AND competencia_id = ? AND periodo_id = ?")
            ->execute([$trans['matricula_id'], $trans['carga_id'], $trans['competencia_id'], $trans['periodo_id']]);
        $chk('transversal sin conclusión: NO se exige (la registra el tutor)', $validar($trans) === null);
    } finally {
        $pdo->rollBack();
    }
}

// ── 3. guardarConclusionAlumno (controlador real) ────────────────────────
echo "3. guardarConclusionAlumno — carga, plazo y bloqueo (subproceso)\n";
$llamar = static function (int $usuario, array $c, bool $vencido): array {
    $cmd = sprintf('php %s --conclusion %d %d %d %d %d', escapeshellarg(__FILE__),
        $usuario, $c['carga_id'], $c['competencia_id'], $c['matricula_id'], $vencido ? 1 : 0);
    $r = json_decode((string) shell_exec($cmd), true);
    return is_array($r) ? $r : ['success' => null, 'mensaje' => '(sin JSON)'];
};
$bloq = $pdo->query("
    SELECT k.carga_id, k.competencia_id, k.matricula_id, ca.docente_id
    FROM calificaciones k
    JOIN periodos per ON per.id = k.periodo_id AND per.estado = 'activo'
    JOIN cargas_academicas ca ON ca.id = k.carga_id AND ca.estado = 'activa' AND ca.docente_id IS NOT NULL
    JOIN bloqueos_competencia b ON b.carga_id = k.carga_id AND b.competencia_id = k.competencia_id AND b.periodo_id = k.periodo_id
    ORDER BY k.carga_id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$bloq) {
    $chk('hay una nota de una competencia bloqueada en el periodo activo', false);
} else {
    $otro  = (int) $pdo->query("SELECT id FROM usuarios WHERE id <> " . (int) $bloq['docente_id'] . " ORDER BY id LIMIT 1")->fetchColumn();
    $antes = (string) $pdo->query("SELECT MD5(GROUP_CONCAT(COALESCE(conclusion_descriptiva,'') ORDER BY id)) FROM calificaciones")->fetchColumn();

    $r = $llamar($otro, $bloq, false);
    $chk('otro usuario: se rechaza por la carga', $r['success'] === false && $r['mensaje'] === 'Carga no encontrada.', (string) $r['mensaje']);
    $r = $llamar((int) $bloq['docente_id'], $bloq, true);
    $chk('dueño con plazo VENCIDO: se rechaza por el plazo', $r['success'] === false && str_contains((string) $r['mensaje'], 'plazo'), (string) $r['mensaje']);
    $r = $llamar((int) $bloq['docente_id'], $bloq, false);
    $chk('dueño con plazo vigente: pasa carga y plazo, y lo detiene el bloqueo',
        $r['success'] === false && $r['mensaje'] === 'Competencia bloqueada.', (string) $r['mensaje']);
    $chk('los subprocesos no dejaron conclusiones escritas',
        (string) $pdo->query("SELECT MD5(GROUP_CONCAT(COALESCE(conclusion_descriptiva,'') ORDER BY id)) FROM calificaciones")->fetchColumn() === $antes);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
