<?php

/**
 * Verificación de las ESTADÍSTICAS DE JUSTIFICACIONES (29/09/2026), bloque
 * «Justificaciones» de `/admin/cuadros`. Ver
 * docs/modulos/confirmacion-y-asistencia-por-fechas.md (segunda ronda, punto 6).
 *
 * Qué protege, con las DOS ramas de cada regla:
 *   - Un bimestre SIN fechas devuelve null (el bloque no se pinta); uno por
 *     fechas, las estadísticas.
 *   - Solo lo CONFIRMADO cuenta: un borrador no mueve ninguna cifra; al
 *     confirmarlo, sí.
 *   - Alerta de verbales: 5 o más justificaciones Y MÁS de la mitad verbales.
 *     Exactamente la mitad NO entra.
 *   - Lista de ausencias: entra quien más falta, con su motivo más frecuente;
 *     los empates en el último puesto amplían el corte (`topConEmpates`).
 *
 * Mide DIFERENCIAS contra las cifras reales del bimestre (antes/después), así
 * funciona con cualquier dato local.
 *
 * 🔴 NO DEJA RASTRO: una transacción que se revierte; los métodos del modelo
 * abren la suya y se prueban con una SUBCLASE que las vuelve SAVEPOINTs (mismo
 * recurso que `verif_asistencia_fechas.php`).
 *
 * Uso: php database/verificaciones/verif_asistencia_estadisticas.php
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

final class AsistenciaModelPrueba extends App\Models\AsistenciaModel
{
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_estadistica'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_estadistica'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_estadistica'); }
    public function pdo(): PDO               { return $this->db; }
}

$m   = new AsistenciaModelPrueba();
$est = new App\Models\AsistenciaEstadisticaModel();

// ── 1) Rama sin fechas / con fechas ──────────────────────────────
echo "1) Qué bimestres tienen estadísticas\n";
$hist = $m->queryOne("SELECT id FROM periodos WHERE asistencia_por_fechas = 0 ORDER BY id LIMIT 1");
$chk('un bimestre SIN fechas devuelve null', $hist !== null && $est->justificaciones((int) $hist['id']) === null);
$per = $m->queryOne("SELECT p.* FROM periodos p INNER JOIN anios_academicos a ON a.id = p.anio_id
                     WHERE a.estado = 'activo' AND p.estado = 'activo' AND p.asistencia_por_fechas = 1
                     ORDER BY p.numero LIMIT 1");
$chk('un bimestre POR FECHAS devuelve las estadísticas',
    $per !== null && is_array($est->justificaciones((int) $per['id'])));

// ── 2) topConEmpates (función pura) ──────────────────────────────
echo "2) Corte de las listas con empates\n";
$items = array_map(fn($v) => ['v' => $v], [5, 4, 3, 3, 1, 0]);
$chk('un empate en el 3.er puesto amplía el corte (5,4,3,3)',
    array_column(App\Models\AsistenciaModel::topConEmpates($items, 'v', 3), 'v') === [5, 4, 3, 3]);
$chk('los ceros no entran a la lista',
    !in_array(0, array_column(App\Models\AsistenciaModel::topConEmpates($items, 'v', 10), 'v'), true));

// ── 3) Datos (transacción revertida) ─────────────────────────────
echo "3) Borrador frente a confirmado, alerta de verbales (transacción revertida)\n";
$pid = (int) ($per['id'] ?? 0);
// Dos estudiantes de UNA sección con nómina aprobada, sin registro en el bimestre.
$sec = $per ? $m->queryOne("
    SELECT m.seccion_id
    FROM matriculas m
    INNER JOIN secciones s ON s.id = m.seccion_id AND s.estado_nomina = 'aprobada'
    INNER JOIN anios_academicos a ON a.id = m.anio_id AND a.estado = 'activo'
    WHERE 1 = 1 " . roster_evaluacion('m') . "
      AND NOT EXISTS (SELECT 1 FROM cierres_asistencia z
                      WHERE z.seccion_id = m.seccion_id AND z.periodo_id = ? AND z.anulado_en IS NULL)
      AND NOT EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = m.id AND i.periodo_id = ?)
    GROUP BY m.seccion_id HAVING COUNT(*) >= 2
    ORDER BY m.seccion_id LIMIT 1
", [$pid, $pid]) : null;
$par = $sec ? $m->query("
    SELECT m.id FROM matriculas m
    WHERE m.seccion_id = ? " . roster_evaluacion('m') . "
      AND NOT EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = m.id AND i.periodo_id = ?)
    ORDER BY m.id LIMIT 2
", [(int) $sec['seccion_id'], $pid]) : [];
$usuario = $m->queryOne("SELECT u.id FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1");
$verbal  = $m->queryOne("SELECT id, nombre FROM asistencia_motivos WHERE codigo = ? AND retirado_en IS NULL",
    [App\Models\AsistenciaMotivoModel::CODIGO_VERBAL]);
// «Otro» = el ANCLA del bimestre (076): sus FJ cuentan como FJ; las verbales, como F.
$anclaId = $per ? (new App\Models\AsistenciaMotivoModel())->anclaDelPeriodo((int) $per['id']) : null;
$otro    = $anclaId !== null
    ? $m->queryOne("SELECT id, nombre FROM asistencia_motivos WHERE id = ? AND codigo <> ? AND retirado_en IS NULL",
        [$anclaId, App\Models\AsistenciaMotivoModel::CODIGO_VERBAL])
    : null;
$dias    = $per ? App\Models\AsistenciaModel::diasMarcables($per) : [];

if (count($par) < 2 || !$usuario || !$verbal || !$otro || count($dias) < 6) {
    $chk('escenario de prueba (2 estudiantes sin registro, motivos verbal y otro, ≥ 6 días)', false);
} else {
    [$a, $b] = [(int) $par[0]['id'], (int) $par[1]['id']];
    $uid = (int) $usuario['id'];
    printf("     escenario: matrículas %d y %d, sección %d, periodo %d\n", $a, $b, (int) $sec['seccion_id'], $pid);

    // 6 faltas justificadas: `$nVerbales` verbales y el resto con el otro motivo.
    $marcar = function (int $mid, int $nVerbales) use ($m, $pid, $dias, $uid, $verbal, $otro): void {
        foreach (array_slice($dias, 0, 6) as $i => $d) {
            $m->marcarDia($mid, $pid, $d, 'FJ', (int) ($i < $nVerbales ? $verbal['id'] : $otro['id']), $uid);
        }
    };
    $nombreDe = fn(int $mid): string => (string) $m->queryOne("
        SELECT CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS n
        FROM matriculas m INNER JOIN estudiantes e ON e.id = m.estudiante_id
        INNER JOIN personas p ON p.id = e.persona_id WHERE m.id = ?", [$mid])['n'];
    $alerta = fn(array $j): array => array_column($j['verbales'], 'nombre');
    $enTop  = function (array $j, string $nombre): ?array {
        foreach ($j['top'] as $s) {
            foreach ($s['alumnos'] as $al) {
                if ($al['nombre'] === $nombre) { return $al; }
            }
        }
        return null;
    };
    [$nomA, $nomB] = [$nombreDe($a), $nombreDe($b)];

    $pdo = $m->pdo();
    $pdo->beginTransaction();
    try {
        $antes = $est->justificaciones($pid);

        // a) Borradores: nada se mueve.
        $marcar($a, 5);   // 6 FJ, 5 verbales → más de la mitad
        $marcar($b, 3);   // 6 FJ, 3 verbales → exactamente la mitad
        $borr = $est->justificaciones($pid);
        $chk('a) un BORRADOR no mueve los totales',
            $borr['kpis']['totales'] === $antes['kpis']['totales']
            && $borr['kpis']['estudiantes'] === $antes['kpis']['estudiantes']);
        $chk('a) un BORRADOR no entra a la alerta ni a la lista',
            !in_array($nomA, $alerta($borr), true) && $enTop($borr, $nomA) === null);
        $chk('a) un BORRADOR no cuenta en los usos por motivo',
            $borr['motivos'] == $antes['motivos']);

        // b) Confirmados: sí.
        $chk('b) confirmar los dos funciona',
            $m->confirmar($a, $pid, $uid)['ok'] && $m->confirmar($b, $pid, $uid)['ok']);
        $desp = $est->justificaciones($pid);
        // 12 marcas FJ: 4 con el ancla (cuentan como FJ) y 8 verbales (cuentan como
        // F, la misma regla que la boleta; 08/10/2026, migración 076).
        $chk('b) CONFIRMADO: +4 FJ (ancla) y +8 F (las verbales), igual que los contadores',
            $desp['kpis']['totales']['FJ'] === $antes['kpis']['totales']['FJ'] + 4
            && $desp['kpis']['totales']['F'] === $antes['kpis']['totales']['F'] + 8);
        $chk('b) CONFIRMADO: +12 justificaciones registradas y +8 verbales (por marca)',
            $desp['kpis']['totales']['justificaciones'] === $antes['kpis']['totales']['justificaciones'] + 12
            && $desp['kpis']['totales']['verbales'] === $antes['kpis']['totales']['verbales'] + 8);
        $suma = fn(int $mid): array => $m->queryOne("SELECT faltas, faltas_justificadas FROM inasistencias
                                                    WHERE matricula_id = ? AND periodo_id = ?", [$mid, $pid]);
        $chk('b) las estadísticas cuentan F/FJ como los contadores oficiales (A: F5 FJ1 · B: F3 FJ3)',
            $suma($a) == ['faltas' => 5, 'faltas_justificadas' => 1]
            && $suma($b) == ['faltas' => 3, 'faltas_justificadas' => 3]);
        $chk('b) el % de verbales nunca supera 100', ($desp['kpis']['pct_verbales'] ?? 0) <= 100);
        $chk('b) devuelve el nombre del motivo principal (tooltip del icono FJ)',
            ($desp['motivo_principal'] ?? null) === $otro['nombre']);
        $chk('b) CONFIRMADO: +2 estudiantes con registro', $desp['kpis']['estudiantes'] === $antes['kpis']['estudiantes'] + 2);
        $usoVerbal = fn(array $j): int => (int) array_sum(array_map(
            fn($x) => $x['motivo'] === $verbal['nombre'] ? $x['FJ'] : 0, $j['motivos']));
        $chk('b) CONFIRMADO: usos del motivo verbal +8', $usoVerbal($desp) === $usoVerbal($antes) + 8);

        // c) Alerta: más de la mitad entra; exactamente la mitad no.
        $chk('c) 6 justificaciones, 5 verbales: ENTRA a la alerta', in_array($nomA, $alerta($desp), true));
        $chk('c) 6 justificaciones, 3 verbales (la mitad justa): NO entra', !in_array($nomB, $alerta($desp), true));

        // d) Menos de 5 justificaciones no entra aunque todas sean verbales.
        $m->marcarDia($a, $pid, $dias[5], null, null, $uid);
        $m->marcarDia($a, $pid, $dias[4], null, null, $uid);
        $m->confirmar($a, $pid, $uid);
        $chk('d) 4 justificaciones, todas verbales: NO entra (mínimo 5)',
            !in_array($nomA, $alerta($est->justificaciones($pid)), true));

        // e) Lista de ausencias con motivo más frecuente.
        $alB = $enTop($est->justificaciones($pid), $nomB);
        $chk('e) quien más falta en su sección figura en la lista',
            $alB !== null && $alB['ausencias'] === 6 && $alB['FJ'] === 3 && $alB['F'] === 3);
        $chk('e) su motivo más frecuente sale del empate 3-3 por nombre (el primero alfabético)',
            $alB !== null && $alB['motivo'] === min($verbal['nombre'], $otro['nombre']));
    } finally {
        $pdo->rollBack();
    }

    $rastro = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_incidencias WHERE matricula_id IN (?, ?) AND periodo_id = ?) AS x,
        (SELECT COUNT(*) FROM inasistencias          WHERE matricula_id IN (?, ?) AND periodo_id = ?) AS i
    ", [$a, $b, $pid, $a, $b, $pid]);
    $chk('el rollback no dejó rastro', (int) $rastro['x'] === 0 && (int) $rastro['i'] === 0);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
