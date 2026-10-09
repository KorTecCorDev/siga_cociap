<?php

/**
 * Verificación del ✓ POR ESTUDIANTE (09/10/2026, migración 077). Ver
 * docs/modulos/confirmacion-y-asistencia-por-fechas.md (décima ronda).
 *
 * Qué protege, con las DOS ramas de cada regla:
 *   1) ESTRUCTURA: `marcarDia` escribe y borra la presencia; el retorno de grado
 *      mueve `asistencia_presencias`; en la base ningún día tiene presencia E
 *      incidencia a la vez.
 *   2) DATOS: un ✓ o una F individual cubren SOLO a ese estudiante (no toman la
 *      lista); ✓ ↔ incidencia se reemplazan (nunca conviven); «Pasar lista»
 *      cubre al resto y respeta las marcas propias; un ✓ no toca contadores ni
 *      desconfirma; un día no lectivo se lleva los ✓; el bloqueo se niega con UN
 *      estudiante sin marcar y se acepta al marcarlo.
 *
 * 🔴 NO DEJA RASTRO: una transacción que se revierte; los métodos que abren la
 * suya se prueban con SUBCLASES que las vuelven SAVEPOINTs (mismo recurso que
 * `verif_asistencia_jornadas.php`). Nunca borra lo que no creó fuera de ella.
 *
 * Uso: php database/verificaciones/verif_asistencia_presencias.php
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

final class AsistenciaModelPruebaP extends App\Models\AsistenciaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_ap'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_ap'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_ap'); }
    public function pdo(): PDO               { return $this->db; }
}

final class JornadaModelPruebaP extends App\Models\AsistenciaJornadaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_jp'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_jp'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_jp'); }
}

$m = new AsistenciaModelPruebaP();
$j = new JornadaModelPruebaP();

// ── 1) Estructura ─────────────────────────────────────────────────
echo "1) Ganchos y coherencia de la base\n";
$src = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/app/Models/AsistenciaModel.php'));
$marcar = preg_match('/function marcarDia\(.*?\n    \}\n/s', $src, $mm) ? $mm[0] : '';
$chk('marcarDia guarda el ✓ propio (marcarPresente)', str_contains($marcar, '->marcarPresente('));
$chk('marcarDia lo borra al marcar una incidencia (quitarPresente)', str_contains($marcar, '->quitarPresente('));
$ret = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Matricula/RetornoGradoController.php');
$chk('el retorno de grado MUEVE asistencia_presencias',
    preg_match("/TABLAS_DEL_BIMESTRE\s*=\s*\[[^\]]*'asistencia_presencias'/s", $ret) === 1);
$chk('en la base, ningún día tiene presencia E incidencia a la vez',
    (int) $m->queryOne("SELECT COUNT(*) AS n FROM asistencia_presencias p
                        INNER JOIN asistencia_incidencias x ON x.matricula_id = p.matricula_id AND x.fecha = p.fecha")['n'] === 0);

// ── 2) Datos ──────────────────────────────────────────────────────
echo "2) ✓ por estudiante (transacción revertida)\n";

$per = $m->queryOne("SELECT p.* FROM periodos p INNER JOIN anios_academicos a ON a.id = p.anio_id
                     WHERE a.estado = 'activo' AND p.estado = 'activo' AND p.asistencia_por_fechas = 1
                     ORDER BY p.numero LIMIT 1");
$pid = (int) ($per['id'] ?? 0);
// Sección sin cierre en el bimestre, con al menos 2 estudiantes en la grilla.
$sid = 0;
$roster = [];
if ($per) {
    foreach ($m->query("SELECT s.id FROM secciones s
                        INNER JOIN anios_academicos a ON a.id = s.anio_id AND a.estado = 'activo'
                        WHERE s.estado_nomina = 'aprobada'
                          AND NOT EXISTS (SELECT 1 FROM cierres_asistencia z
                                          WHERE z.seccion_id = s.id AND z.periodo_id = ? AND z.anulado_en IS NULL)
                        ORDER BY s.id", [$pid]) as $s) {
        $ids = array_map('intval', array_column($m->getEstudiantesConIncidencias((int) $s['id'], $pid), 'matricula_id'));
        if (count($ids) >= 2) { $sid = (int) $s['id']; $roster = $ids; break; }
    }
}
$usuario = $m->queryOne("SELECT u.id FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1");
$dias = $per ? App\Models\AsistenciaModel::diasMarcables($per, null, $j->noLectivos()) : [];
// Días sin incidencias ni ✓ en TODO el colegio (para poder declararlos no lectivos).
$libres = array_values(array_filter($dias, fn($d) =>
    (int) $m->queryOne("SELECT (SELECT COUNT(*) FROM asistencia_incidencias WHERE fecha = ?)
                             + (SELECT COUNT(*) FROM asistencia_presencias  WHERE fecha = ?) AS n", [$d, $d])['n'] === 0));

if (!$sid || !$usuario || count($libres) < 2) {
    $chk('escenario de prueba (bimestre por fechas, sección con ≥ 2 estudiantes, ≥ 2 días libres)', false);
} else {
    $uid = (int) $usuario['id'];
    [$a, $b] = [$roster[0], $roster[1]];
    $n = count($roster);
    [$d1, $d2] = [$libres[0], $libres[1]];
    printf("     escenario: sección %d (%d estudiantes), periodo %d, días %s · %s\n", $sid, $n, $pid, $d1, $d2);

    $listasDe = fn(string $d): int => (int) $m->queryOne(
        "SELECT COUNT(*) AS n FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?",
        [$sid, $pid, $d])['n'];
    $presente = fn(int $mid, string $d): bool => $m->queryOne(
        "SELECT 1 AS x FROM asistencia_presencias WHERE matricula_id = ? AND fecha = ?", [$mid, $d]) !== null;
    $incidencia = fn(int $mid, string $d): ?string => $m->queryOne(
        "SELECT tipo FROM asistencia_incidencias WHERE matricula_id = ? AND fecha = ?", [$mid, $d])['tipo'] ?? null;
    $faltan = fn(string $d): int => $j->pendientesPorDia($sid, $per)[$d] ?? -1;

    $antes = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_jornadas) AS jor,
        (SELECT COUNT(*) FROM asistencia_presencias) AS pres,
        (SELECT COUNT(*) FROM asistencia_incidencias) AS inc,
        (SELECT COUNT(*) FROM asistencia_dias_no_lectivos) AS nl,
        (SELECT COUNT(*) FROM cierres_asistencia WHERE anulado_en IS NULL) AS z,
        (SELECT COUNT(*) FROM inasistencias WHERE confirmado_en IS NOT NULL) AS conf");

    $pdo = $m->pdo();
    $pdo->beginTransaction();
    try {
        // Días de prueba SIN lista (dentro de la transacción).
        foreach ([$d1, $d2] as $d) {
            $m->execute("DELETE FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?", [$sid, $pid, $d]);
        }
        $chk('a) escenario: sin lista, nadie está cubierto ese día', $faltan($d1) === $n);

        // b) Un ✓ individual cubre SOLO a ese estudiante.
        $contA = $m->contadoresDe($a, $pid);
        $r = $m->marcarDia($a, $pid, $d1, null, null, $uid);
        $chk('b) «✓ asistió» de A se guarda como presencia', $r['ok'] && $presente($a, $d1));
        $chk('b) y NO toma la lista de la sección', $listasDe($d1) === 0);
        $chk('b) cubre solo a A (faltan n − 1)', $faltan($d1) === $n - 1);
        $chk('b) B sigue sin marca propia', !$presente($b, $d1) && $incidencia($b, $d1) === null);
        $chk('b) el ✓ no toca los contadores de A', array_diff_assoc($m->contadoresDe($a, $pid), $contA) === []);

        // c) ✓ ↔ incidencia se reemplazan: nunca conviven.
        $m->marcarDia($a, $pid, $d1, 'F', null, $uid);
        $chk('c) una F de A borra su ✓ y guarda la F', !$presente($a, $d1) && $incidencia($a, $d1) === 'F');
        $chk('c) A sigue cubierto (faltan n − 1)', $faltan($d1) === $n - 1);
        $m->marcarDia($a, $pid, $d1, null, null, $uid);
        $chk('c) volver a ✓ borra la F y repone el ✓', $presente($a, $d1) && $incidencia($a, $d1) === null);
        $chk('c) y los contadores de A vuelven a los de antes',
            array_diff_assoc(array_diff_key($m->contadoresDe($a, $pid), ['confirmado' => 1]),
                             array_diff_key($contA, ['confirmado' => 1])) === []);

        // d) Una F individual tampoco toma la lista.
        $m->marcarDia($b, $pid, $d1, 'F', null, $uid);
        $chk('d) una F de B NO toma la lista', $listasDe($d1) === 0 && $faltan($d1) === $n - 2);

        // e) Pasar lista cubre al resto y respeta las marcas propias.
        $chk('e) pasar lista funciona', $j->pasarLista($sid, $per, $d1, $uid)['ok']);
        $chk('e) el día queda completo', $faltan($d1) === 0);
        $chk('e) el ✓ de A y la F de B siguen ahí', $presente($a, $d1) && $incidencia($b, $d1) === 'F');

        // f) Un ✓ en un día sin cubrir no desconfirma.
        $m->confirmar($a, $pid, $uid);
        $chk('f) escenario: A confirmado', $m->contadoresDe($a, $pid)['confirmado']);
        $m->marcarDia($a, $pid, $d2, null, null, $uid);
        $chk('f) un ✓ nuevo NO desconfirma a A', $m->contadoresDe($a, $pid)['confirmado']);
        $m->marcarDia($a, $pid, $d2, 'T', null, $uid);
        $chk('f) una incidencia SÍ lo desconfirma', !$m->contadoresDe($a, $pid)['confirmado']);
        $m->marcarDia($a, $pid, $d2, null, null, $uid);

        // g) Día no lectivo: con una incidencia se niega; con solo ✓, se los lleva.
        $chk('g) declarar no lectivo con la F de B se rechaza', !$j->declararNoLectivo($d1, 'Prueba', $uid)['ok']);
        $m->marcarDia($b, $pid, $d1, null, null, $uid);
        $chk('g) con solo ✓, declararlo funciona', $j->declararNoLectivo($d1, 'Feriado de prueba', $uid)['ok']);
        $chk('g) y borra los ✓ de ese día', !$presente($a, $d1) && !$presente($b, $d1));
        $j->quitarNoLectivo($d1);

        // h) Bloqueo: cada estudiante, cada día. Uno sin marcar lo niega.
        foreach ($j->diasSinTomar($sid, $per) as $f) {
            if ($f !== $d2) { $j->pasarLista($sid, $per, $f, $uid); }
        }
        foreach (array_slice($roster, 0, -1) as $mid) {
            $m->marcarDia($mid, $pid, $d2, null, null, $uid);
        }
        $ultimo = $roster[$n - 1];
        $m->confirmarSeccion($sid, $pid, $uid);
        $chk('h) escenario: solo d2 tiene 1 estudiante sin marcar', $j->diasSinTomar($sid, $per) === [$d2] && $faltan($d2) === 1);
        $r = $m->bloquearRA($sid, $pid, $uid);
        $chk('h) bloquear con UN estudiante sin marcar se rechaza', !$r['ok'] && str_contains($r['mensaje'], '(1 estudiante)'));
        $m->marcarDia($ultimo, $pid, $d2, null, null, $uid);
        $m->confirmarSeccion($sid, $pid, $uid);
        $chk('h) al marcarlo, bloquear se acepta', $j->diasSinTomar($sid, $per) === [] && $m->bloquearRA($sid, $pid, $uid)['ok']);
    } finally {
        $pdo->rollBack();
    }

    $despues = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_jornadas) AS jor,
        (SELECT COUNT(*) FROM asistencia_presencias) AS pres,
        (SELECT COUNT(*) FROM asistencia_incidencias) AS inc,
        (SELECT COUNT(*) FROM asistencia_dias_no_lectivos) AS nl,
        (SELECT COUNT(*) FROM cierres_asistencia WHERE anulado_en IS NULL) AS z,
        (SELECT COUNT(*) FROM inasistencias WHERE confirmado_en IS NOT NULL) AS conf");
    $chk('el rollback no dejó rastro (listas, ✓, incidencias, no lectivos, cierres, confirmaciones)', $antes == $despues);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
