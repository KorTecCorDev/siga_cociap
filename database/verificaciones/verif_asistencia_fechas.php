<?php

/**
 * Verificación de la ASISTENCIA POR FECHAS y su CONFIRMACIÓN (29/09/2026,
 * migraciones 068 y 069). Ver docs/modulos/confirmacion-y-asistencia-por-fechas.md.
 *
 * Qué protege:
 *   1) ESTRUCTURA: cada lectura oficial de `AsistenciaModel` pasa por su punto
 *      único (`sqlVisible` para la boleta, `sqlConfirmada` para monitoreo y vía
 *      extraordinaria); los contadores de un bimestre por fechas solo los escribe
 *      `recalcularContadores`; el retorno de grado mueve las fechas; las rutas
 *      literales van antes que los patrones {id}.
 *   2) DATOS, con las DOS ramas de cada regla: días válidos e inválidos, motivo
 *      solo en FJ/TJ, confirmar sin motivo (rechazado) y con motivo, reenviar vs.
 *      cambiar, desmarcar, INVARIANTE contadores = fechas, boleta visible solo
 *      con confirmación Y cierre, bloqueo normal vs. forzado, guardado viejo de
 *      números rechazado, vía extraordinaria sobre borrador vs. confirmado.
 *
 * 🔴 NO DEJA RASTRO: una transacción que se revierte; los métodos del modelo
 * abren la suya y se prueban con una SUBCLASE que las vuelve SAVEPOINTs (mismo
 * recurso que `verif_confirmacion_conducta.php`).
 *
 * Uso: php database/verificaciones/verif_asistencia_fechas.php
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
    public function beginTransaction(): void { $this->db->exec('SAVEPOINT verif_asistencia'); }
    public function commit(): void           { $this->db->exec('RELEASE SAVEPOINT verif_asistencia'); }
    public function rollback(): void         { $this->db->exec('ROLLBACK TO SAVEPOINT verif_asistencia'); }
    public function pdo(): PDO               { return $this->db; }
}

$m = new AsistenciaModelPrueba();

// ── 1) Estructura ─────────────────────────────────────────────────
echo "1) Puntos únicos y ganchos\n";
$src = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/app/Models/AsistenciaModel.php'));
$cuerpoDe = function (string $src, string $metodo): string {
    return preg_match('/function ' . $metodo . '\(.*?\n    \}\n/s', $src, $mm) ? $mm[0] : '';
};
foreach (['getDelBimestre', 'getAcumuladoAnual', 'tieneRegistroUnion'] as $met) {
    $chk("boleta: {$met} lee solo lo VISIBLE (sqlVisible)", str_contains($cuerpoDe($src, $met), 'self::sqlVisible('));
}
foreach (['getProgresoPorSeccion', 'getIncidenciasPorSeccion', 'getTopIncidenciasPorSeccion',
          'getEvolucionIncidenciasAnual', 'sqlSinRegistro'] as $met) {
    $chk("monitoreo/extraordinaria: {$met} lee solo lo CONFIRMADO", str_contains($cuerpoDe($src, $met), 'self::sqlConfirmada('));
}
$chk('sqlVisible exige confirmación Y cierre vigente',
    (bool) preg_match('/function sqlVisible.*?sqlConfirmada.*?cierres_asistencia.*?anulado_en IS NULL/s', $src));
$chk('el guardado viejo de 4 números se niega en un bimestre por fechas',
    str_contains($cuerpoDe($src, 'guardar'), 'periodoPorFechas('));
$chk('marcarDia recalcula los contadores', str_contains($cuerpoDe($src, 'marcarDia'), 'recalcularContadores('));
$chk('confirmar recalcula antes de confirmar', str_contains($cuerpoDe($src, 'confirmar'), 'recalcularContadores('));
$chk('diasMarcables reusa la definición de día hábil de la planilla',
    str_contains($cuerpoDe($src, 'diasMarcables'), 'PlanillaAsistenciaModel::diasPlanilla('));
$retorno = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Matricula/RetornoGradoController.php');
$chk('el retorno de grado mueve asistencia_incidencias con inasistencias',
    str_contains($retorno, "'inasistencias', 'asistencia_incidencias'"));
$rutas = (string) file_get_contents(ROOT_PATH . '/routes/web.php');
$pDia  = strpos($rutas, "'/admin/asistencia/dia'");
$pConf = strpos($rutas, "'/admin/asistencia/confirmar'");
$pId   = strpos($rutas, "'/admin/asistencia/{id}/bloquear'");
$chk('rutas literales /dia y /confirmar ANTES de los patrones {id}',
    $pDia !== false && $pConf !== false && $pId !== false && $pDia < $pId && $pConf < $pId);
$director = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Director/BloqueoController.php');
$chk('el bloqueo de asistencia del director es FORZADO (decisión b)',
    (bool) preg_match('/asistenciaModel->bloquearRA\([^;]*, true\);/', $director));

// ── 2) Datos ──────────────────────────────────────────────────────
echo "2) Fechas, contadores y confirmación (transacción revertida)\n";

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
      AND NOT EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = m.id AND i.periodo_id = ?)
    ORDER BY m.id LIMIT 1
", [(int) $per['id'], (int) $per['id']]) : null;
$usuario = $m->queryOne("SELECT u.id FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'admin' ORDER BY u.id LIMIT 1");
$motivo  = $m->queryOne("SELECT id FROM asistencia_motivos WHERE retirado_en IS NULL ORDER BY orden LIMIT 1");
$dias    = $per ? App\Models\AsistenciaModel::diasMarcables($per) : [];

if (!$fila || !$usuario || !$motivo || count($dias) < 3) {
    $chk('escenario de prueba (bimestre por fechas activo, estudiante sin registro, motivo y ≥ 3 días)', false);
} else {
    $pid = (int) $per['id'];
    $mid = (int) $fila['matricula_id'];
    $sid = (int) $fila['seccion_id'];
    $uid = (int) $usuario['id'];
    $mot = (int) $motivo['id'];
    [$d1, $d2, $d3] = [$dias[0], $dias[1], $dias[2]];
    printf("     escenario: matrícula %d, sección %d, periodo %d, días %s · %s · %s\n", $mid, $sid, $pid, $d1, $d2, $d3);

    $cont   = fn(): array => $m->contadoresDe($mid, $pid);
    $fechas = fn(): array => $m->incidenciasDe([$mid], $pid)[$mid] ?? [];
    // INVARIANTE: los 4 contadores = conteo de las fechas por tipo.
    $invariante = function () use ($cont, $fechas): bool {
        $c = $cont();
        $n = ['F' => 0, 'FJ' => 0, 'T' => 0, 'TJ' => 0];
        foreach ($fechas() as $x) { $n[$x['tipo']]++; }
        foreach (App\Models\AsistenciaModel::TIPO_CAMPO as $t => $campo) {
            if ($c[$campo] !== $n[$t]) { return false; }
        }
        return true;
    };
    // Un sábado y un día fuera del bimestre.
    $sabado = null;
    for ($d = new DateTimeImmutable($per['fecha_inicio']); $d->format('Y-m-d') <= $per['fecha_fin']; $d = $d->modify('+1 day')) {
        if ($d->format('N') === '6') { $sabado = $d->format('Y-m-d'); break; }
    }
    $fuera = (new DateTimeImmutable($per['fecha_inicio']))->modify('-3 days')->format('Y-m-d');

    $pdo = $m->pdo();
    $pdo->beginTransaction();
    try {
        // a) Días inválidos: rechazados sin escribir nada.
        $chk('a) un SÁBADO se rechaza', $sabado === null || !$m->marcarDia($mid, $pid, $sabado, 'F', null, $uid)['ok']);
        $chk('a) un día FUERA del bimestre se rechaza', !$m->marcarDia($mid, $pid, $fuera, 'F', null, $uid)['ok']);
        $manana = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        if ($manana <= $per['fecha_fin'] && (int) (new DateTimeImmutable('tomorrow'))->format('N') <= 5) {
            $chk('a) un día FUTURO se rechaza', !$m->marcarDia($mid, $pid, $manana, 'F', null, $uid)['ok']);
        }
        $chk('a) un tipo inválido se rechaza', !$m->marcarDia($mid, $pid, $d1, 'X', null, $uid)['ok']);
        $chk('a) nada quedó escrito', $fechas() === [] && $cont()['faltas'] === 0);

        // b) Marcar F: borrador, contador calculado.
        $r = $m->marcarDia($mid, $pid, $d1, 'F', $mot, $uid);
        $chk('b) marcar F se guarda como borrador', $r['ok'] && $cont()['faltas'] === 1 && !$cont()['confirmado']);
        // (`??` no sirve: un null legítimo se confundiría con «no está».)
        $dia1 = $fechas()[$d1] ?? null;
        $chk('b) el motivo en una F se descarta (solo FJ/TJ lo llevan)',
            $dia1 !== null && array_key_exists('motivo_id', $dia1) && $dia1['motivo_id'] === null);

        // c) FJ/TJ SIN motivo: ya no se guardan, ni como borrador (30/09/2026,
        //    migración 070). Antes se guardaban y solo se frenaban al confirmar.
        $chk('c) marcar una FJ SIN motivo se rechaza', !$m->marcarDia($mid, $pid, $d2, 'FJ', null, $uid)['ok']);
        $chk('c) marcar una TJ SIN motivo se rechaza', !$m->marcarDia($mid, $pid, $d2, 'TJ', null, $uid)['ok']);
        $chk('c) y el día quedó sin incidencia', !isset($fechas()[$d2]));

        // d) Con motivo, confirma.
        $m->marcarDia($mid, $pid, $d2, 'FJ', $mot, $uid);
        $chk('d) con motivo, confirmar funciona', $m->confirmar($mid, $pid, $uid)['ok'] && $cont()['confirmado']);
        $chk('d) contadores F1 FJ1 T0 TJ0', ($c = $cont()) && $c['faltas'] === 1 && $c['faltas_justificadas'] === 1
            && $c['tardanzas'] === 0 && $c['tardanzas_justificadas'] === 0);
        $chk('d) INVARIANTE contadores = fechas', $invariante());

        // e) Reenviar lo mismo no desconfirma; cambiar sí.
        $m->marcarDia($mid, $pid, $d1, 'F', null, $uid);
        $chk('e) reenviar el mismo día NO desconfirma', $cont()['confirmado']);
        $m->marcarDia($mid, $pid, $d3, 'T', null, $uid);
        $chk('e) marcar otro día DESCONFIRMA', !$cont()['confirmado'] && $cont()['tardanzas'] === 1);
        $chk('e) INVARIANTE tras el cambio', $invariante());

        // f) Desmarcar recalcula.
        $m->marcarDia($mid, $pid, $d3, null, null, $uid);
        $chk('f) desmarcar borra el día y recalcula', !isset($fechas()[$d3]) && $cont()['tardanzas'] === 0);
        $chk('f) INVARIANTE tras desmarcar', $invariante());

        // g) El guardado viejo de números se niega en un bimestre por fechas.
        $chk('g) guardar 4 números sueltos se niega', !$m->guardar($mid, $pid, 9, 9, 9, 9, $uid) && $cont()['faltas'] === 1);

        // h) Boleta: visible solo con confirmación Y cierre.
        $m->confirmar($mid, $pid, $uid);
        $chk('h) confirmado SIN cierre: la boleta pinta guion', !$m->tieneRegistroUnion([$mid], $pid)
            && $m->getDelBimestre($mid, $pid)['faltas'] === 0);
        $prog = $m->getProgresoPorSeccion($pid)[$sid] ?? ['esperados' => 0, 'registrados' => 0];
        if ($prog['registrados'] < $prog['esperados']) {
            $chk('h) bloquear SIN forzar con pendientes se rechaza', !$m->bloquearRA($sid, $pid, $uid)['ok']);
        }
        $chk('h) bloquear FORZADO se acepta', $m->bloquearRA($sid, $pid, $uid, true)['ok']);
        $chk('h) confirmado CON cierre: la boleta lo ve (F1 FJ1)', $m->tieneRegistroUnion([$mid], $pid)
            && $m->getDelBimestre($mid, $pid)['faltas'] === 1 && $m->getDelBimestre($mid, $pid)['faltas_justificadas'] === 1);
        $m->execute("UPDATE inasistencias SET confirmado_en = NULL WHERE matricula_id = ? AND periodo_id = ?", [$mid, $pid]);
        $chk('h) borrador CON cierre: la boleta pinta guion', !$m->tieneRegistroUnion([$mid], $pid));

        // i) Vía extraordinaria: pisa un borrador; nunca lo confirmado.
        $m->registrarExtraordinaria($mid, $pid, 2, 0, 0, 0, 'verif', $uid);
        $chk('i) la vía extraordinaria reemplaza un BORRADOR y queda confirmada',
            $cont()['faltas'] === 2 && $cont()['confirmado']);
        $lanzo = false;
        try { $m->registrarExtraordinaria($mid, $pid, 3, 0, 0, 0, 'verif', $uid); }
        catch (\RuntimeException) { $lanzo = true; }
        $chk('i) sobre algo CONFIRMADO, la vía extraordinaria se niega', $lanzo);
    } finally {
        $pdo->rollBack();
    }

    $rastro = $m->queryOne("SELECT
        (SELECT COUNT(*) FROM asistencia_incidencias WHERE matricula_id = ? AND periodo_id = ?) AS x,
        (SELECT COUNT(*) FROM inasistencias          WHERE matricula_id = ? AND periodo_id = ?) AS i,
        (SELECT COUNT(*) FROM cierres_asistencia WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL) AS z
    ", [$mid, $pid, $mid, $pid, $sid, $pid]);
    $chk('el rollback no dejó rastro (fechas, contadores ni cierre)',
        (int) $rastro['x'] === 0 && (int) $rastro['i'] === 0 && (int) $rastro['z'] === 0);
}

// ── 2b) Catálogo de motivos ──────────────────────────────────────
echo "2b) Catálogo de motivos (transacción revertida)\n";
$mm   = new App\Models\AsistenciaMotivoModel();
$uid2 = (int) ($usuario['id'] ?? 0);
$falla = function (callable $f): bool {
    try { $f(); } catch (\DomainException) { return true; }
    return false;
};
$pdo2 = $m->pdo();
$pdo2->beginTransaction();
try {
    $antes = count($mm->vigentes());
    $mm->crear('  Cita   médica  ', $uid2);
    $nuevo = $mm->queryOne("SELECT id, codigo, nombre FROM asistencia_motivos ORDER BY id DESC LIMIT 1");
    $chk('crear: limpia espacios y deriva un código estable sin tildes',
        $nuevo['nombre'] === 'Cita médica' && $nuevo['codigo'] === 'cita_medica');
    $chk('crear: un nombre repetido (sin distinguir mayúsculas) se rechaza',
        $falla(fn() => $mm->crear('CITA MÉDICA', $uid2)));
    $chk('crear: un nombre de menos de 3 caracteres se rechaza', $falla(fn() => $mm->crear('ab', $uid2)));

    $mm->renombrar((int) $nuevo['id'], 'Cita médica programada', $uid2);
    $ren = $mm->queryOne("SELECT codigo, nombre, modificado_por FROM asistencia_motivos WHERE id = ?", [(int) $nuevo['id']]);
    $chk('renombrar: cambia el nombre, NO el código, y deja traza',
        $ren['nombre'] === 'Cita médica programada' && $ren['codigo'] === 'cita_medica' && (int) $ren['modificado_por'] === $uid2);

    $mm->retirar((int) $nuevo['id'], $uid2);
    $chk('retirar: deja de ofrecerse pero la fila se conserva',
        count($mm->vigentes()) === $antes && !$mm->esVigente((int) $nuevo['id'])
        && $mm->queryOne("SELECT 1 AS x FROM asistencia_motivos WHERE id = ?", [(int) $nuevo['id']]) !== null);
    $chk('retirar: un motivo retirado no se puede asignar',
        !($m->marcarDia((int) ($fila['matricula_id'] ?? 0), (int) ($per['id'] ?? 0), $dias[0] ?? '', 'FJ', (int) $nuevo['id'], $uid2)['ok'] ?? true));

    // El ÚLTIMO vigente no se puede retirar.
    $vig = $mm->vigentes();
    foreach (array_slice($vig, 1) as $v) { $mm->retirar((int) $v['id'], $uid2); }
    $chk('retirar: el ÚLTIMO motivo vigente no se puede retirar',
        $falla(fn() => $mm->retirar((int) $vig[0]['id'], $uid2)) && count($mm->vigentes()) === 1);
} finally {
    $pdo2->rollBack();
}
$chk('el rollback devolvió el catálogo a como estaba', count($mm->vigentes()) >= 2
    && $mm->queryOne("SELECT 1 AS x FROM asistencia_motivos WHERE codigo = 'cita_medica'") === null);

// ── 3) Los bimestres de solo números siguen intactos ─────────────
echo "3) I y II Bimestre (histórico de números)\n";
$hist = $m->query("SELECT id, asistencia_por_fechas FROM periodos WHERE estado = 'cerrado'");
$chk('los bimestres cerrados siguen como histórico de números (asistencia_por_fechas = 0)',
    $hist !== [] && array_sum(array_map(static fn($p) => (int) $p['asistencia_por_fechas'], $hist)) === 0);
$sinConf = $m->queryOne("SELECT COUNT(*) AS n FROM inasistencias i INNER JOIN periodos p ON p.id = i.periodo_id
                         WHERE p.estado = 'cerrado' AND i.confirmado_en IS NULL");
$chk('ninguna fila de un bimestre cerrado quedó como borrador', (int) $sinConf['n'] === 0);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
