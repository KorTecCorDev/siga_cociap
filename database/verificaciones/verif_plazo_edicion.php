<?php

/**
 * Verificación del PUNTO ÚNICO DE LA COMPUERTA TEMPORAL (F1 del plan de
 * bloqueos, 09/10/2026). Uso: php database/verificaciones/verif_plazo_edicion.php
 *
 *  1. A/B: `EdicionPeriodoModel` devuelve lo mismo que las reglas VIEJAS —copiadas
 *     aquí a mano como control— en cada periodo, para cada estado y en las
 *     fronteras de la fecha. Única diferencia admitida y documentada: un
 *     periodo `pendiente`, que la vieja de calificaciones daba por abierto.
 *  2. Los delegados (`periodoEstaBloqueado`, `periodoEditable` x2 y la columna
 *     `editable` de los dos `listarPeriodosActivos`) siguen al punto único.
 *  3. `diasParaCierre`: ya no dice «0 días» después de vencer.
 *  4. Ninguna copia de la regla sobrevive en `app/`, y la etapa 2 del tutor
 *     pregunta con el rol DOCENTE.
 *
 * ESCRIBE dentro de una TRANSACCIÓN con ROLLBACK (cambia estado y límite de los
 * periodos). Lleva el guard de secretos: NO en producción.
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

// ── Modo SUBPROCESO: `PeriodoController::editar` real; nunca hace commit ──
// Uso interno: --editar <usuario> <periodo> <fecha_inicio> <fecha_fin> <limite_notas> <limite_aux>
// (los límites en formato datetime-local, «-» = vacío).
if (($argv[1] ?? '') === '--editar') {
    [, , $usuarioId, $periodoId, $ini, $fin, $lim, $aux] = $argv;
    $pdo = Core\Database::connect();
    $pdo->beginTransaction();
    register_shutdown_function(static function () use ($pdo, $periodoId): void {
        echo json_encode([
            'flash' => $_SESSION['_flash'] ?? [],
            'fila'  => $pdo->query("SELECT limite_notas, limite_auxiliares FROM periodos WHERE id = " . (int) $periodoId)->fetch(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
    });
    $_SESSION['_csrf_token'] = 'verif';
    $_SESSION['auth_user']   = ['id' => (int) $usuarioId, 'rol_codigo' => 'admin', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
    $_POST = ['_csrf_token' => 'verif', 'fecha_inicio' => $ini, 'fecha_fin' => $fin,
              'limite_notas' => $lim === '-' ? '' : $lim, 'limite_auxiliares' => $aux === '-' ? '' : $aux];
    (new App\Controllers\Director\PeriodoController())->editar($periodoId);
    exit(0);
}

use App\Models\AsistenciaModel;
use App\Models\CalificacionModel;
use App\Models\ConductaModel;
use App\Models\EdicionPeriodoModel as E;

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo = Core\Database::connect();

// Copias de CONTROL de las reglas viejas, escritas aquí a propósito.
$viejaCalifBloqueado = static function (?array $p): bool {
    if (!$p) return true;
    if ($p['estado'] === 'cerrado') return true;
    if ($p['limite_notas'] === null) return false;
    return strtotime($p['limite_notas']) < time();
};
$viejaAuxEditable = static function (?array $p): bool {
    if (!$p || $p['estado'] !== 'activo') return false;
    if ($p['limite_notas'] && strtotime($p['limite_notas']) < time()) return false;
    return true;
};

$edicion = new E();
$calif   = new CalificacionModel();
$cond    = new ConductaModel();
$asis    = new AsistenciaModel();
$flagDe  = static function (array $lista, int $pid): ?bool {
    foreach ($lista as $p) {
        if ((int) $p['id'] === $pid) { return (bool) $p['editable']; }
    }
    return null;
};

$periodos = $pdo->query("
    SELECT p.id FROM periodos p JOIN anios_academicos a ON a.id = p.anio_id
    WHERE a.estado = 'activo' ORDER BY p.numero
")->fetchAll(PDO::FETCH_COLUMN);

echo "1-2. A/B contra las reglas viejas y los delegados\n";
$fronteras = [
    'límite 2 h en el futuro' => date('Y-m-d H:i:s', time() + 7200),
    'límite 2 h en el pasado' => date('Y-m-d H:i:s', time() - 7200),
    'límite en 1 min'         => date('Y-m-d H:i:s', time() + 60),
    'sin límite (NULL)'       => null,
];
$casos = 0;
$fallos = [];
$pdo->beginTransaction();
try {
    foreach ($periodos as $pid) {
        $pid = (int) $pid;
        foreach (['activo', 'cerrado', 'pendiente'] as $estado) {
            foreach ($fronteras as $nombre => $limite) {
                $pdo->prepare("UPDATE periodos SET estado = ?, limite_notas = ? WHERE id = ?")
                    ->execute([$estado, $limite, $pid]);
                $fila = $pdo->query("SELECT estado, limite_notas FROM periodos WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
                $casos++;
                $donde = "periodo $pid · $estado · $nombre";

                $nuevoDoc = $edicion->esEditable($pid, E::ROL_DOCENTE);
                $nuevoAux = $edicion->esEditable($pid, E::ROL_AUXILIAR);

                // Auxiliar = la vieja regla de conducta/asistencia, sin excepción.
                if ($nuevoAux !== $viejaAuxEditable($fila)) { $fallos[] = "auxiliar: $donde"; }
                // Docente = la vieja de calificaciones, salvo `pendiente`.
                $esperadoDoc = $estado === 'pendiente' ? false : !$viejaCalifBloqueado($fila);
                if ($nuevoDoc !== $esperadoDoc) { $fallos[] = "docente: $donde"; }

                // Delegados.
                if ($calif->periodoEstaBloqueado($pid) !== !$nuevoDoc)       { $fallos[] = "periodoEstaBloqueado: $donde"; }
                if ($cond->periodoEditable($pid) !== $nuevoAux)              { $fallos[] = "Conducta::periodoEditable: $donde"; }
                if ($asis->periodoEditable($pid) !== $nuevoAux)              { $fallos[] = "Asistencia::periodoEditable: $donde"; }
                if ($flagDe($cond->listarPeriodosActivos(), $pid) !== $nuevoAux) { $fallos[] = "flag conducta: $donde"; }
                if ($flagDe($asis->listarPeriodosActivos(), $pid) !== $nuevoAux) { $fallos[] = "flag asistencia: $donde"; }

                // El motivo dice POR QUÉ.
                $motivo   = $edicion->motivoNoEditable($pid, E::ROL_DOCENTE);
                $esperado = match (true) {
                    $estado === 'cerrado'  => E::MOTIVO_CERRADO,
                    $estado === 'pendiente' => E::MOTIVO_NO_INICIADO,
                    $limite !== null && strtotime($limite) < time() => E::MOTIVO_PLAZO_VENCIDO,
                    default => null,
                };
                if ($motivo !== $esperado) { $fallos[] = "motivo: $donde (" . var_export($motivo, true) . ')'; }
            }
        }
    }
    $diverge = 0;
    foreach ($periodos as $pid) {
        $pdo->prepare("UPDATE periodos SET estado = 'pendiente', limite_notas = NULL WHERE id = ?")->execute([(int) $pid]);
        $fila = $pdo->query("SELECT estado, limite_notas FROM periodos WHERE id = " . (int) $pid)->fetch(PDO::FETCH_ASSOC);
        if (!$viejaCalifBloqueado($fila) && !$edicion->esEditable((int) $pid, E::ROL_DOCENTE)) { $diverge++; }
    }
} finally {
    $pdo->rollBack();
}
$chk(count($periodos) . " periodos × 3 estados × 4 fronteras: el punto único coincide con las reglas viejas y los delegados lo siguen",
    $fallos === [], $fallos ? implode(' | ', array_slice($fallos, 0, 5)) : "$casos casos");
$chk('la única diferencia es la documentada: `pendiente` deja de ser editable para el docente',
    $diverge === count($periodos), "$diverge de " . count($periodos));
$chk('un periodo inexistente no es editable (se trata como cerrado)',
    !$edicion->esEditable(999999, E::ROL_DOCENTE) && $edicion->motivoNoEditable(999999, E::ROL_AUXILIAR) === E::MOTIVO_CERRADO);
$chk('rollback: los periodos vuelven a como estaban',
    (int) $pdo->query("SELECT COUNT(*) FROM periodos WHERE estado = 'activo'")->fetchColumn() === 1);

echo "3. diasParaCierre\n";
$t = strtotime('2026-10-09 12:00:00');
$chk('sin límite → null', E::diasParaCierre(null, $t) === null && E::diasParaCierre('', $t) === null);
$chk('vence en 30 min → 1 día para el cierre', E::diasParaCierre('2026-10-09 12:30:00', $t) === 1);
$chk('vence en 7 días justos → 7', E::diasParaCierre('2026-10-16 12:00:00', $t) === 7);
$chk('venció hace 30 min → -1 (antes: «0 días para el cierre»)', E::diasParaCierre('2026-10-09 11:30:00', $t) === -1);
$chk('venció hace 3 días y medio → -4', E::diasParaCierre('2026-10-06 00:00:00', $t) === -4);
$chk('justo en el límite → 0', E::diasParaCierre('2026-10-09 12:00:00', $t) === 0);

echo "4. Sin copias y con el rol correcto\n";
$copias = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || $f->getFilename() === 'EdicionPeriodoModel.php') {
        continue;
    }
    foreach (file($f->getPathname()) as $n => $linea) {
        if (str_contains($linea, 'limite_notas')
            && (str_contains($linea, 'strtotime(') || preg_match('/<=\s*\w*\.?limite_notas/', $linea))) {
            $copias[] = $f->getFilename() . ':' . ($n + 1);
        }
    }
}
$chk('ninguna comparación de `limite_notas` fuera del punto único', $copias === [], implode(', ', $copias));
$tutor = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Docente/ConductaTutorController.php');
$chk('la etapa 2 del tutor pregunta con ROL_DOCENTE (no con el del auxiliar)',
    str_contains($tutor, 'EdicionPeriodoModel::ROL_DOCENTE') && !str_contains($tutor, '->periodoEditable('));

// ── 5. Fechas por ROL (F4, migración 078) ────────────────────────────────
echo "5. Fecha propia de los auxiliares (limite_auxiliares)\n";
$tieneCol = (bool) $pdo->query("SHOW COLUMNS FROM periodos LIKE 'limite_auxiliares'")->fetch();
$chk('la migración 078 está aplicada en esta BD', $tieneCol);
$activo = (int) $pdo->query("SELECT id FROM periodos WHERE estado = 'activo' LIMIT 1")->fetchColumn();
if ($tieneCol && $activo) {
    $pdo->beginTransaction();
    try {
        $fut = date('Y-m-d H:i:s', time() + 7200);
        $pas = date('Y-m-d H:i:s', time() - 7200);
        $pdo->prepare("UPDATE periodos SET limite_notas = ?, limite_auxiliares = ? WHERE id = ?")->execute([$fut, $pas, $activo]);
        $chk('auxiliares VENCIDA y docentes vigente: el auxiliar no registra',
            !$edicion->esEditable($activo, E::ROL_AUXILIAR)
            && $edicion->motivoNoEditable($activo, E::ROL_AUXILIAR) === E::MOTIVO_PLAZO_VENCIDO);
        $chk('…y el docente (y el tutor) sí', $edicion->esEditable($activo, E::ROL_DOCENTE) && !$calif->periodoEstaBloqueado($activo));
        $chk('…conducta, asistencia y sus flags siguen la fecha del auxiliar',
            !$cond->periodoEditable($activo) && !$asis->periodoEditable($activo)
            && $flagDe($cond->listarPeriodosActivos(), $activo) === false
            && $flagDe($asis->listarPeriodosActivos(), $activo) === false);
        $fila = $pdo->query("SELECT estado, limite_notas, limite_auxiliares FROM periodos WHERE id = $activo")->fetch(PDO::FETCH_ASSOC);
        $chk('limiteDe: cada rol lee su fecha', E::limiteDe($fila, E::ROL_AUXILIAR) === $pas && E::limiteDe($fila, E::ROL_DOCENTE) === $fut);

        $pdo->prepare("UPDATE periodos SET limite_auxiliares = NULL WHERE id = ?")->execute([$activo]);
        $chk('auxiliares VACÍA: vale la de docentes', $edicion->esEditable($activo, E::ROL_AUXILIAR) === $edicion->esEditable($activo, E::ROL_DOCENTE)
            && E::limiteDe($pdo->query("SELECT estado, limite_notas, limite_auxiliares FROM periodos WHERE id = $activo")->fetch(PDO::FETCH_ASSOC), E::ROL_AUXILIAR) === $fut);
    } finally {
        $pdo->rollBack();
    }

    // editar(): validación del orden de las fechas (controlador real).
    $admin = (int) $pdo->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1")->fetchColumn();
    $per   = $pdo->query("SELECT fecha_inicio, fecha_fin, limite_notas, limite_auxiliares FROM periodos WHERE id = $activo")->fetch(PDO::FETCH_ASSOC);
    $fin   = substr($per['fecha_fin'], 0, 10);
    $ed = static fn(string $lim, string $aux): array => json_decode((string) shell_exec(sprintf(
        'php %s --editar %d %d %s %s %s %s', escapeshellarg(__FILE__), $admin, $activo,
        substr($per['fecha_inicio'], 0, 10), $fin, escapeshellarg($lim), escapeshellarg($aux))), true) ?? [];
    $antesFin = date('Y-m-d', strtotime("$fin -1 day")) . 'T10:00';
    $r = $ed("{$fin}T23:00", $antesFin);
    $chk('editar: auxiliares ANTES del último día del bimestre se rechaza',
        str_contains((string) ($r['flash']['error'] ?? ''), 'anterior al último día'), (string) ($r['flash']['error'] ?? json_encode($r)));
    $r = $ed("{$fin}T10:00", "{$fin}T12:00");
    $chk('editar: auxiliares DESPUÉS de la de notas se rechaza',
        str_contains((string) ($r['flash']['error'] ?? ''), 'posterior a la fecha límite de notas'), (string) ($r['flash']['error'] ?? json_encode($r)));
    $r = $ed("{$fin}T23:00", "{$fin}T12:00");
    $chk('editar: en orden se guarda (dentro de la transacción del subproceso)',
        isset($r['flash']['success']) && ($r['fila']['limite_auxiliares'] ?? '') === "$fin 12:00:00", json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ed("{$fin}T23:00", '-');
    $chk('editar: auxiliares vacía se guarda como NULL',
        isset($r['flash']['success']) && is_array($r['fila'] ?? null)
        && array_key_exists('limite_auxiliares', $r['fila']) && $r['fila']['limite_auxiliares'] === null,
        json_encode($r, JSON_UNESCAPED_UNICODE));
    $chk('rollback: las fechas del bimestre activo quedaron como estaban',
        $pdo->query("SELECT limite_notas, limite_auxiliares FROM periodos WHERE id = $activo")->fetch(PDO::FETCH_ASSOC)
        === ['limite_notas' => $per['limite_notas'], 'limite_auxiliares' => $per['limite_auxiliares']]);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
