<?php

/**
 * Verificación de la LISTA DEL DÍA, los DÍAS NO LECTIVOS y la JUSTIFICACIÓN
 * SIEMPRE CON MOTIVO (30/09/2026, migración 070). Ver
 * docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Qué protege, con las DOS ramas de cada regla:
 *   1) ESTRUCTURA: `marcarDia` YA NO toma la lista (09/10/2026, migración 077:
 *      cada marca afirma solo sobre su estudiante); no existe «deshacer»;
 *      `bloquearRA` exige `pendientesPorDia`; rutas literales `no-lectivos`
 *      antes de `{id}`; el CHECK `chk_justificada_con_motivo` existe en la base.
 *   2) DATOS: FJ/TJ sin motivo rechazadas (modelo y CHECK); pasar lista en día
 *      válido e inválido; no lectivo con y sin incidencias, repetido, sábado, y
 *      su efecto (no marcable, listas borradas, quitar lo devuelve); bloqueo con
 *      y sin días sin tomar, y forzado. Los ✓ por estudiante se prueban en
 *      `verif_asistencia_presencias.php`.
 *
 * 🔴 NO DEJA RASTRO: una transacción que se revierte; los métodos que abren la
 * suya se prueban con SUBCLASES que las vuelven SAVEPOINTs (mismo recurso que
 * `verif_asistencia_fechas.php`). Nunca borra lo que no creó fuera de ella.
 *
 * Uso: php database/verificaciones/verif_asistencia_jornadas.php
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

final class AsistenciaModelPruebaJ extends App\Models\AsistenciaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_aj'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_aj'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_aj'); }
    public function pdo(): PDO               { return $this->db; }
}

final class JornadaModelPrueba extends App\Models\AsistenciaJornadaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_jm'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_jm'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_jm'); }
}

$m = new AsistenciaModelPruebaJ();
$j = new JornadaModelPrueba();

// ── 1) Estructura ─────────────────────────────────────────────────
echo "1) Puntos únicos y ganchos\n";
$src = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/app/Models/AsistenciaModel.php'));
$cuerpoDe = function (string $src, string $metodo): string {
    return preg_match('/function ' . $metodo . '\(.*?\n    \}\n/s', $src, $mm) ? $mm[0] : '';
};
$chk('marcarDia YA NO toma la lista de la sección (077)', !str_contains($cuerpoDe($src, 'marcarDia'), '->tomar('));
$chk('marcarDia valida el día sin los NO LECTIVOS', str_contains($cuerpoDe($src, 'marcarDia'), '->noLectivos()'));
$chk('bloquearRA exige cada estudiante cada día (pendientesPorDia)', str_contains($cuerpoDe($src, 'bloquearRA'), 'pendientesPorDia('));
$chk('no existe «deshacer lista» (077)', !method_exists(App\Models\AsistenciaJornadaModel::class, 'deshacer'));
$chk('diasMarcables sigue reusando el día hábil de la planilla',
    str_contains($cuerpoDe($src, 'diasMarcables'), 'PlanillaAsistenciaModel::diasPlanilla('));
$rutas = (string) file_get_contents(ROOT_PATH . '/routes/web.php');
$pNl   = strpos($rutas, "'/admin/asistencia/no-lectivos'");
$pId   = strpos($rutas, "'/admin/asistencia/{id}'");
$chk('ruta literal /no-lectivos ANTES del patrón GET /{id}', $pNl !== false && $pId !== false && $pNl < $pId);
$chk('ruta POST /{id}/jornada registrada', str_contains($rutas, "'/admin/asistencia/{id}/jornada'"));
$chk('existe el CHECK chk_justificada_con_motivo',
    $m->queryOne("SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
                    AND CONSTRAINT_NAME = 'chk_justificada_con_motivo'") !== null);
$chk('no quedan FJ/TJ sin motivo en la base',
    (int) $m->queryOne("SELECT COUNT(*) AS n FROM asistencia_incidencias
                        WHERE tipo IN ('FJ','TJ') AND motivo_id IS NULL")['n'] === 0);

// ── 2) Datos ──────────────────────────────────────────────────────
echo "2) Lista del día, no lectivos y motivo (transacción revertida)\n";

$per = $m->queryOne("SELECT p.* FROM periodos p INNER JOIN anios_academicos a ON a.id = p.anio_id
                     WHERE a.estado = 'activo' AND p.estado = 'activo' AND p.asistencia_por_fechas = 1
                     ORDER BY p.numero LIMIT 1");
$fila = $per ? $m->queryOne("
    SELECT m.id AS matricula_id, m.seccion_id
    FROM matriculas m
    INNER JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    WHERE 1 = 1 " . roster_evaluacion('m') . "
      AND NOT EXISTS (SELECT 1 FROM cierres_asistencia z
                      WHERE z.seccion_id = m.seccion_id AND z.periodo_id = ? AND z.anulado_en IS NULL)
    ORDER BY m.id LIMIT 1
", [(int) $per['id']]) : null;
$usuario = $m->queryOne("SELECT u.id FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1");
$motivo  = $m->queryOne("SELECT id FROM asistencia_motivos WHERE retirado_en IS NULL ORDER BY orden LIMIT 1");

$pid = (int) ($per['id'] ?? 0);
$sid = (int) ($fila['seccion_id'] ?? 0);
$dias = $per ? App\Models\AsistenciaModel::diasMarcables($per, null, $j->noLectivos()) : [];
// Días sin ninguna incidencia en TODO el colegio (no lectivo) y en la sección.
$libres = array_values(array_filter($dias, fn($d) =>
    (int) $m->queryOne("SELECT COUNT(*) AS n FROM asistencia_incidencias WHERE fecha = ?", [$d])['n'] === 0));

if (!$fila || !$usuario || !$motivo || count($libres) < 3) {
    $chk('escenario de prueba (bimestre por fechas activo, estudiante, motivo y ≥ 3 días sin incidencias)', false);
} else {
    $mid = (int) $fila['matricula_id'];
    $uid = (int) $usuario['id'];
    $mot = (int) $motivo['id'];
    [$d1, $d2, $d3] = [$libres[0], $libres[1], $libres[2]];
    printf("     escenario: matrícula %d, sección %d, periodo %d, días libres %s · %s · %s\n", $mid, $sid, $pid, $d1, $d2, $d3);

    $antes = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ?) AS jor,
        (SELECT COUNT(*) FROM asistencia_dias_no_lectivos) AS nl,
        (SELECT COUNT(*) FROM asistencia_incidencias WHERE matricula_id = ? AND periodo_id = ?) AS inc,
        (SELECT COUNT(*) FROM asistencia_presencias) AS pres,
        (SELECT COUNT(*) FROM cierres_asistencia WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL) AS z
    ", [$sid, $pid, $mid, $pid, $sid, $pid]);
    $listasDe = fn(string $d): int => (int) $m->queryOne(
        "SELECT COUNT(*) AS n FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?",
        [$sid, $pid, $d])['n'];
    // Sin «deshacer» en la app (077): para preparar un día SIN lista se borra a
    // mano DENTRO de la transacción que se revierte.
    $quitarLista = fn(string $d) => $m->execute(
        "DELETE FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?", [$sid, $pid, $d]);

    $pdo = $m->pdo();
    $pdo->beginTransaction();
    try {
        // a) Justificación siempre con motivo.
        $chk('a) marcarDia de una FJ SIN motivo se rechaza', !$m->marcarDia($mid, $pid, $d1, 'FJ', null, $uid)['ok']);
        $chk('a) marcarDia de una TJ SIN motivo se rechaza', !$m->marcarDia($mid, $pid, $d1, 'TJ', null, $uid)['ok']);
        $r = $m->marcarDia($mid, $pid, $d1, 'FJ', $mot, $uid);
        $chk('a) con motivo, la FJ se guarda', $r['ok']);
        $pdo->exec('SAVEPOINT verif_chk');
        $lanzo = false;
        try {
            $m->execute("UPDATE asistencia_incidencias SET motivo_id = NULL WHERE matricula_id = ? AND fecha = ?", [$mid, $d1]);
        } catch (\PDOException) {
            $lanzo = true;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT verif_chk');
        $chk('a) la BASE rechaza dejar una FJ sin motivo (CHECK)', $lanzo);

        // b) y c) Ninguna marca individual toma la lista (077; antes sí la tomaba).
        $quitarLista($d1);
        $r = $m->marcarDia($mid, $pid, $d1, 'F', null, $uid);
        $chk('c) marcar una F NO toma la lista del día', $r['ok'] && !array_key_exists('jornada_tomada', $r) && $listasDe($d1) === 0);
        $m->marcarDia($mid, $pid, $d1, null, null, $uid);
        $chk('c) «✓ asistió» tampoco toma la lista', $listasDe($d1) === 0);

        // d) Pasar lista: día válido sí; sábado y futuro no. Repetir no duplica.
        $quitarLista($d2);
        $chk('d) pasar lista en un día marcable funciona', $j->pasarLista($sid, $per, $d2, $uid)['ok'] && $listasDe($d2) === 1);
        $j->pasarLista($sid, $per, $d2, $uid);
        $chk('d) pasar lista otra vez no la duplica', $listasDe($d2) === 1);
        $sabado = null;
        for ($d = new DateTimeImmutable($per['fecha_inicio']); $d->format('Y-m-d') <= $per['fecha_fin']; $d = $d->modify('+1 day')) {
            if ($d->format('N') === '6') { $sabado = $d->format('Y-m-d'); break; }
        }
        $chk('d) pasar lista un SÁBADO se rechaza', $sabado === null || !$j->pasarLista($sid, $per, $sabado, $uid)['ok']);
        $manana = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $chk('d) pasar lista un día FUTURO se rechaza', !$j->pasarLista($sid, $per, $manana, $uid)['ok']);

        // e) No lectivo.
        $m->marcarDia($mid, $pid, $d3, 'F', null, $uid);
        $chk('e) declarar no lectivo un día CON incidencias se rechaza', !$j->declararNoLectivo($d3, 'Prueba', $uid)['ok']);
        $m->marcarDia($mid, $pid, $d3, null, null, $uid);
        $chk('e) sin incidencias, declararlo funciona', $j->declararNoLectivo($d3, 'Feriado de prueba', $uid)['ok']);
        $chk('e) y borra las listas de ese día', $listasDe($d3) === 0);
        $chk('e) un día no lectivo ya no se puede marcar', !$m->marcarDia($mid, $pid, $d3, 'F', null, $uid)['ok']);
        $chk('e) ni pasar lista', !$j->pasarLista($sid, $per, $d3, $uid)['ok']);
        $chk('e) y no cuenta como día sin tomar', !in_array($d3, $j->diasSinTomar($sid, $per), true));
        $chk('e) declararlo otra vez se rechaza', !$j->declararNoLectivo($d3, 'Otra', $uid)['ok']);
        $chk('e) un SÁBADO no se declara', $sabado === null || !$j->declararNoLectivo($sabado, 'Prueba', $uid)['ok']);
        $chk('e) un motivo vacío se rechaza', !$j->declararNoLectivo($d2, '   ', $uid)['ok']);
        $j->quitarNoLectivo($d3);
        $chk('e) al quitarlo, el día vuelve a estar SIN TOMAR', in_array($d3, $j->diasSinTomar($sid, $per), true));

        // f) Bloqueo: con todos confirmados, exige 0 días sin tomar.
        $m->confirmarSeccion($sid, $pid, $uid);
        $prog = $m->getProgresoPorSeccion($pid)[$sid] ?? ['esperados' => 0, 'registrados' => 0];
        $chk('f) escenario: todos confirmados', $prog['esperados'] > 0 && $prog['registrados'] >= $prog['esperados']);
        $r = $m->bloquearRA($sid, $pid, $uid);
        $chk('f) bloquear con días SIN TOMAR se rechaza', !$r['ok'] && str_contains($r['mensaje'], 'sin marcar'));
        foreach ($j->diasSinTomar($sid, $per) as $f) { $j->pasarLista($sid, $per, $f, $uid); }
        $chk('f) con todos los días tomados, bloquear se acepta', $j->diasSinTomar($sid, $per) === [] && $m->bloquearRA($sid, $pid, $uid)['ok']);
    } finally {
        $pdo->rollBack();
    }

    // g) Forzado del director: pasa aunque haya días sin tomar (otra transacción).
    $pdo->beginTransaction();
    try {
        $quitarLista($d2);
        $chk('g) escenario: hay días sin tomar', $j->diasSinTomar($sid, $per) !== []);
        $chk('g) el bloqueo FORZADO (director) no exige los días', $m->bloquearRA($sid, $pid, $uid, true)['ok']);
    } finally {
        $pdo->rollBack();
    }

    $despues = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ?) AS jor,
        (SELECT COUNT(*) FROM asistencia_dias_no_lectivos) AS nl,
        (SELECT COUNT(*) FROM asistencia_incidencias WHERE matricula_id = ? AND periodo_id = ?) AS inc,
        (SELECT COUNT(*) FROM asistencia_presencias) AS pres,
        (SELECT COUNT(*) FROM cierres_asistencia WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL) AS z
    ", [$sid, $pid, $mid, $pid, $sid, $pid]);
    $chk('el rollback no dejó rastro (listas, no lectivos, incidencias ni cierre)', $antes == $despues);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
