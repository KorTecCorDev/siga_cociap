<?php

/**
 * Verificación de la CONFIRMACIÓN de conducta (29/09/2026, migración 068,
 * decisión B2 del usuario). Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Qué protege:
 *   1) ESTRUCTURA: ninguna lectura oficial de `ConductaModel` lee la tabla cruda
 *      `conducta_respuestas`; todas pasan por `RESPUESTAS_OFICIALES`. Las únicas
 *      lecturas crudas permitidas están listadas aquí y tienen su porqué.
 *   2) DATOS, con las DOS ramas de cada regla: un BORRADOR no aparece en tutor,
 *      boleta ni completitud; al CONFIRMAR, sí. Editar desconfirma; reenviar el
 *      mismo valor, no. Confirmar a medias se rechaza. El bloqueo del auxiliar
 *      exige todo confirmado; el FORZADO del director, no.
 *
 * 🔴 NO DEJA RASTRO. Todo va dentro de UNA transacción que se revierte al
 * final. Los métodos del modelo abren su propia transacción y `BaseModel` no
 * admite anidarlas: por eso se prueban con una SUBCLASE que las convierte en
 * SAVEPOINTs de la transacción externa. Nunca borra con DELETE lo que no creó.
 *
 * Uso: php database/verificaciones/verif_confirmacion_conducta.php
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');

require ROOT_PATH . '/app/Helpers/helpers.php';

spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/'] as $pre => $base) {
        if (str_starts_with($c, $pre)) {
            $f = ROOT_PATH . $base . str_replace('\\', '/', substr($c, strlen($pre))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});

date_default_timezone_set(config('timezone'));

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};

/** ConductaModel con sus transacciones convertidas en SAVEPOINTs (ver cabecera). */
final class ConductaModelPrueba extends App\Models\ConductaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_conducta'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_conducta'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_conducta'); }
    public function pdo(): PDO               { return $this->db; }
}

$m = new ConductaModelPrueba();

// ── 1) Estructura: el punto único ─────────────────────────────────
echo "1) Ninguna lectura oficial lee la tabla cruda\n";
$src = (string) file_get_contents(ROOT_PATH . '/app/Models/ConductaModel.php');
$src = str_replace("\r\n", "\n", $src);

// Lecturas crudas PERMITIDAS, cada una con su motivo:
//   - la propia definición de RESPUESTAS_OFICIALES (`FROM conducta_respuestas rr`);
//   - getRegistroLegado: pregunta si EXISTE matriz (borrador incluido);
//   - upsertRespuestas: lee lo guardado para saber si algo cambió.
// El editor (getEstudiantesParaRegistro) elige el origen por variable.
// Solo líneas de CÓDIGO: los docblocks citan la tabla al explicar la regla.
$codigo = implode("\n", array_filter(explode("\n", $src),
    static fn(string $l): bool => !preg_match('/^\s*(\*|\/\/|\/\*)/', $l)));
preg_match_all('/FROM conducta_respuestas\b[^\n]*/', $codigo, $crudas);
$chk('solo 3 lecturas crudas de conducta_respuestas (definición, legado y upsert): ' . count($crudas[0]),
    count($crudas[0]) === 3);
$chk('la definición de RESPUESTAS_OFICIALES exige la confirmación',
    (bool) preg_match('/RESPUESTAS_OFICIALES = "\(SELECT.*?INNER JOIN conducta_confirmaciones/s', $src));
$usos = substr_count($src, 'self::RESPUESTAS_OFICIALES');
$chk("las lecturas oficiales usan el punto único ({$usos} usos, se esperan ≥ 12)", $usos >= 12);
$retorno = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Matricula/RetornoGradoController.php');
$chk('el retorno de grado mueve también conducta_confirmaciones',
    str_contains($retorno, "'conducta_respuestas', 'conducta_confirmaciones'"));
$director = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Director/BloqueoController.php');
$chk('el bloqueo del director es FORZADO (decisión b)',
    (bool) preg_match('/conductaModel->bloquearRA\([^;]*, true\);/', $director));

// ── 2) Datos: las dos ramas, sin rastro ───────────────────────────
echo "2) Borrador vs. confirmado (transacción revertida)\n";

// Una sección del año activo SIN cierre en el bimestre en curso y con un
// estudiante del roster SIN respuestas: el caso limpio.
$per = $m->queryOne("SELECT p.id FROM periodos p INNER JOIN anios_academicos a ON a.id = p.anio_id
                     WHERE a.estado = 'activo' AND p.estado = 'activo' ORDER BY p.numero LIMIT 1");
$fila = $per ? $m->queryOne("
    SELECT m.id AS matricula_id, m.seccion_id, g.nivel_id
    FROM matriculas m
    INNER JOIN secciones s ON s.id = m.seccion_id
    INNER JOIN grados g    ON g.id = s.grado_id
    INNER JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    WHERE 1 = 1 " . roster_evaluacion('m') . "
      AND NOT EXISTS (SELECT 1 FROM cierres_conducta z
                      WHERE z.seccion_id = m.seccion_id AND z.periodo_id = ? AND z.anulado_en IS NULL)
      AND NOT EXISTS (SELECT 1 FROM conducta_respuestas r
                      WHERE r.matricula_id = m.id AND r.periodo_id = ?)
    ORDER BY m.id LIMIT 1
", [(int) $per['id'], (int) $per['id']]) : null;
$usuario = $m->queryOne("SELECT u.id FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1");

if (!$fila || !$usuario) {
    $chk('hay un bimestre activo con un estudiante sin conducta registrada (escenario de prueba)', false);
} else {
    $pid = (int) $per['id'];
    $mid = (int) $fila['matricula_id'];
    $sid = (int) $fila['seccion_id'];
    $uid = (int) $usuario['id'];
    $criterios = $m->getCriterios((int) $fila['nivel_id']);
    $ids   = array_map(static fn($c) => (int) $c['id'], $criterios);
    $total = count($ids);
    printf("     escenario: matrícula %d, sección %d, periodo %d, %d criterios\n", $mid, $sid, $pid, $total);

    $todas   = array_fill_keys($ids, 1);
    $mitad   = array_slice($todas, 0, intdiv($total, 2), true);
    $tutorDe = function () use ($m, $sid, $pid, $total, $mid): ?array {
        foreach ($m->getEstudiantesParaTutor($sid, $pid, $total) as $e) {
            if ((int) $e['matricula_id'] === $mid) { return $e; }
        }
        return null;
    };
    $registroDe = function (bool $solo) use ($m, $sid, $pid, $mid): ?array {
        foreach ($m->getEstudiantesParaRegistro($sid, $pid, $solo) as $e) {
            if ((int) $e['matricula_id'] === $mid) { return $e; }
        }
        return null;
    };
    $completos = fn(): int => $m->completitudSeccion($sid, $pid, $total)['completos'];
    // ¿El tutor recibe una nota de este estudiante? (`??` no sirve aquí: un
    // `null` legítimo se confundiría con «no está».)
    $tutorSinNota = function () use ($tutorDe): bool {
        $e = $tutorDe();
        return $e !== null && array_key_exists('nota_ra', $e) && $e['nota_ra'] === null;
    };

    $pdo = $m->pdo();
    $pdo->beginTransaction();
    try {
        $base = $completos();

        // a) Borrador a medias: no cuenta en nada.
        $r = $m->guardarBorrador($mid, $pid, $mitad, $uid);
        $chk('a) el borrador a medias se guarda', $r['ok'] && !$r['confirmado']);
        $chk('a) el EDITOR ve el borrador', count($registroDe(false)['respuestas'] ?? []) === count($mitad));
        $chk('a) la grilla de SOLO LECTURA no lo ve', ($registroDe(true)['respuestas'] ?? null) === []);
        $chk('a) el tutor no recibe nota de un borrador', $tutorSinNota());

        // b) Borrador COMPLETO: sigue sin contar (no es el visto bueno).
        $m->guardarBorrador($mid, $pid, $todas, $uid);
        $chk('b) un borrador completo NO cuenta para el bloqueo', $completos() === $base);
        $chk('b) ni da nota al tutor', $tutorSinNota());

        // c) Confirmar a medias se rechaza.
        $chk('c) confirmar con criterios faltantes se rechaza', !$m->confirmar($mid, $pid, $mitad, $uid, $ids));
        $chk('c) y no deja confirmación', !$m->estaConfirmado($mid, $pid));

        // d) Confirmar completo: ahora sí cuenta.
        $chk('d) confirmar completo funciona', $m->confirmar($mid, $pid, $todas, $uid, $ids));
        $chk('d) queda confirmado', $m->estaConfirmado($mid, $pid));
        $chk('d) cuenta para el bloqueo', $completos() === $base + 1);
        $chk('d) el tutor ve la nota (20 con todo Sí)', (int) ($tutorDe()['nota_ra'] ?? -1) === 20);
        $chk('d) la grilla de solo lectura lo ve', count($registroDe(true)['respuestas'] ?? []) === $total);

        // e) Reenviar la MISMA marca no es un cambio: sigue confirmado.
        $r = $m->guardarBorrador($mid, $pid, [$ids[0] => 1], $uid);
        $chk('e) reenviar el mismo valor NO desconfirma', $r['ok'] && $r['confirmado']);

        // f) Cambiar una marca DESCONFIRMA y sale de lo oficial.
        $r = $m->guardarBorrador($mid, $pid, [$ids[0] => 0], $uid);
        $chk('f) cambiar una marca desconfirma', $r['ok'] && !$r['confirmado'] && !$m->estaConfirmado($mid, $pid));
        $chk('f) deja de contar para el bloqueo', $completos() === $base);
        $chk('f) el tutor deja de ver la nota', $tutorSinNota());

        // g) Bloqueo: el del auxiliar exige todo confirmado; el forzado, no.
        $c = $m->completitudSeccion($sid, $pid, $total);
        if ($c['completos'] < $c['esperados']) {
            $chk('g) bloquear SIN forzar con pendientes se rechaza',
                !$m->bloquearRA($sid, $pid, $uid, $total)['ok']);
            $chk('g) bloquear FORZADO (director) con pendientes se acepta',
                $m->bloquearRA($sid, $pid, $uid, $total, true)['ok']);

            // h) Con la sección bloqueada, la boleta ve lo confirmado y no el borrador.
            $chk('h) boleta del periodo: el borrador sale sin conducta (guion)',
                $m->getParaPeriodo($mid, $pid) === null);
            $m->confirmar($mid, $pid, $todas, $uid, $ids);
            $chk('h) boleta del periodo: al confirmar, aparece (AD)',
                $m->getParaPeriodo($mid, $pid) === 'AD');
        } else {
            $chk('g) escenario con pendientes para probar el bloqueo', false);
        }

        // i) Vía extraordinaria: un borrador no la cierra; lo confirmado, sí.
        $m->guardarBorrador($mid, $pid, [$ids[0] => 1, $ids[1] => 0], $uid); // desconfirma
        $lanzo = false;
        try { $m->registrarLiteralExtraordinario($mid, $pid, 'A', 'verif', $uid); }
        catch (\RuntimeException) { $lanzo = true; }
        $chk('i) con solo borrador, la vía extraordinaria NO se cierra por la matriz', !$lanzo);
    } finally {
        $pdo->rollBack();
    }

    $rastro = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM conducta_respuestas     WHERE matricula_id = ? AND periodo_id = ?) AS r,
        (SELECT COUNT(*) FROM conducta_confirmaciones WHERE matricula_id = ? AND periodo_id = ?) AS c,
        (SELECT COUNT(*) FROM cierres_conducta WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL) AS z
    ", [$mid, $pid, $mid, $pid, $sid, $pid]);
    $chk('el rollback no dejó rastro (respuestas, confirmación ni cierre)',
        (int) $rastro['r'] === 0 && (int) $rastro['c'] === 0 && (int) $rastro['z'] === 0);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
