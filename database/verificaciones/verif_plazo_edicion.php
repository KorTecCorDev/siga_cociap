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

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
