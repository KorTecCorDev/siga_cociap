<?php

/**
 * Verificación de la RECTIFICACIÓN DE CONDUCTA Y ASISTENCIA (F5, 13/10/2026,
 * migración 079). Ver docs/modulos/cierre-cuatro-registros.md §0.
 *
 * Qué protege, con las DOS ramas de cada regla:
 *   - Conducta: un registro CONFIRMADO y BLOQUEADO es rectificable; al cambiar un
 *     criterio la confirmación SIGUE (lo rectificado es oficial), el literal sale
 *     de la misma aritmética que la boleta y queda la traza. Sin bloqueo ni
 *     cierre del bimestre, o sin confirmar, NO es rectificable. Sin cambios, no
 *     hay nada que rectificar.
 *   - Asistencia por fechas: sin bloqueo NO es rectificable; con bloqueo SÍ.
 *     F → FJ con el ancla mueve un contador de faltas a justificadas y CONSERVA
 *     la confirmación ORIGINAL; FJ sin motivo se rechaza; la misma marca se
 *     rechaza; un día «sin tomar» → «asistió» no toca contadores ni confirmación.
 *
 * 🔴 Escribe SOLO dentro de una transacción que termina en ROLLBACK: no deja
 * residuos (regla de database/: nunca limpiar con DELETE lo que no se creó).
 *
 * Uso: php database/verificaciones/verif_rectificacion_registros.php
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

use App\Models\AsistenciaModel;
use App\Models\AsistenciaMotivoModel;
use App\Models\CambioSeccionModel;
use App\Models\ConductaModel;
use App\Models\RectificacionModel;

$fallos = 0;
$ok = static function (bool $cond, string $txt) use (&$fallos): void {
    echo ($cond ? '  OK   ' : '  FALLA ') . $txt . "\n";
    if (!$cond) { $fallos++; }
};

$rect       = new RectificacionModel();
$conducta   = new ConductaModel();
$asistencia = new AsistenciaModel();
$usuario    = (int) ($rect->queryOne("SELECT id FROM usuarios ORDER BY id LIMIT 1")['id'] ?? 0);

$rect->beginTransaction();
try {
    // ── 1. CONDUCTA ──────────────────────────────────────────────
    echo "1. Conducta\n";
    // Un estudiante confirmado con la etapa 1 bloqueada en un periodo activo.
    $c = $rect->queryOne("
        SELECT m.id AS matricula_id, cf.periodo_id, z.id AS cierre_id
        FROM conducta_confirmaciones cf
        INNER JOIN matriculas m ON m.id = cf.matricula_id
        INNER JOIN periodos per ON per.id = cf.periodo_id AND per.estado = 'activo'
        INNER JOIN cierres_conducta z
                ON z.seccion_id = " . CambioSeccionModel::sqlSeccionDelPeriodo('m', 'per.id') . "
               AND z.periodo_id = per.id AND z.anulado_en IS NULL AND z.ra_bloqueado_en IS NOT NULL
        WHERE EXISTS (SELECT 1 FROM conducta_respuestas r
                      WHERE r.matricula_id = m.id AND r.periodo_id = per.id)
        LIMIT 1
    ");
    if ($c === null) {
        echo "  SKIP sin un registro de conducta confirmado y bloqueado en un periodo activo\n";
    } else {
        $m = (int) $c['matricula_id'];
        $p = (int) $c['periodo_id'];
        $ok($rect->conductaRectificable($m, $p), 'confirmado + bloqueado → rectificable');

        $resp = $conducta->respuestasOficiales($m, $p);
        $cid  = (int) array_key_first($resp);
        $ids  = array_map('intval', array_keys($resp));

        $ok($conducta->rectificar($m, $p, $resp, $usuario, $ids) === [], 'mismos valores → sin cambios');

        $antes   = $conducta->getParaPeriodo($m, $p, false);
        $cambios = $conducta->rectificar($m, $p, [$cid => 1 - $resp[$cid]], $usuario, $ids);
        $ok($cambios === [$cid => [$resp[$cid], 1 - $resp[$cid]]], 'un criterio cambiado → un cambio con antes/después');
        $ok($conducta->estaConfirmado($m, $p), 'la confirmación SIGUE tras rectificar');

        // Literal esperado con la aritmética de la boleta (si/total*20 con el tutor).
        $info  = $rect->getMatriculaInfo($m);
        $total = $conducta->totalCriterios((int) $info['nivel_id'], (int) $info['anio_id']);
        $si    = array_sum($conducta->respuestasOficiales($m, $p));
        $nt    = $rect->queryOne("SELECT nota_tutor FROM calificaciones_conducta WHERE matricula_id = ? AND periodo_id = ?", [$m, $p]);
        $nt    = ($nt && $nt['nota_tutor'] !== null) ? (int) $nt['nota_tutor'] : null;
        $notaRA = ($si / $total) * 20;
        $final  = (int) round($nt !== null ? ($notaRA + $nt) / 2 : $notaRA, 0, PHP_ROUND_HALF_UP);
        $despues = $conducta->getParaPeriodo($m, $p, false);
        $ok($despues === nota_a_literal($final), "literal recalculado = el de la boleta ({$antes} → {$despues})");

        $n0 = (int) $rect->queryOne("SELECT COUNT(*) AS n FROM rectificaciones_conducta")['n'];
        $rect->registrarConducta($m, $p, $cambios, $antes, $despues, 'verif', $usuario);
        $n1 = (int) $rect->queryOne("SELECT COUNT(*) AS n FROM rectificaciones_conducta")['n'];
        $ok($n1 === $n0 + 1, 'una fila de traza por criterio cambiado');

        // Sin bloqueo (cierre anulado) y con el periodo activo → no rectificable.
        $rect->execute("UPDATE cierres_conducta SET anulado_en = NOW() WHERE id = ?", [(int) $c['cierre_id']]);
        $ok(!$rect->conductaRectificable($m, $p), 'periodo activo sin bloqueo → NO rectificable');

        // Sin confirmar → no rectificable (aunque vuelva el bloqueo).
        $rect->execute("UPDATE cierres_conducta SET anulado_en = NULL WHERE id = ?", [(int) $c['cierre_id']]);
        $rect->execute("DELETE FROM conducta_confirmaciones WHERE matricula_id = ? AND periodo_id = ?", [$m, $p]);
        $ok(!$rect->conductaRectificable($m, $p), 'sin confirmar → NO rectificable');
    }

    // ── 2. ASISTENCIA POR FECHAS ─────────────────────────────────
    echo "2. Asistencia\n";
    $a = $rect->queryOne("
        SELECT x.matricula_id, x.periodo_id, x.fecha, i.confirmado_en, i.confirmado_por,
               " . CambioSeccionModel::sqlSeccionDelPeriodo('m', 'x.periodo_id') . " AS seccion_id
        FROM asistencia_incidencias x
        INNER JOIN matriculas m    ON m.id = x.matricula_id
        INNER JOIN periodos per    ON per.id = x.periodo_id AND per.asistencia_por_fechas = 1
                                  AND per.estado = 'activo'
        INNER JOIN inasistencias i ON i.matricula_id = x.matricula_id AND i.periodo_id = x.periodo_id
                                  AND i.confirmado_en IS NOT NULL AND i.extraordinaria = 0
        WHERE x.tipo = 'F'
        LIMIT 1
    ");
    if ($a === null) {
        echo "  SKIP sin una F confirmada en un periodo por fechas activo\n";
    } else {
        $m = (int) $a['matricula_id'];
        $p = (int) $a['periodo_id'];
        $f = (string) $a['fecha'];
        $sid = (int) $a['seccion_id'];

        $rect->execute("UPDATE cierres_asistencia SET anulado_en = NOW() WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL", [$sid, $p]);
        $ok(!$rect->asistenciaRectificable($m, $p), 'periodo activo sin bloqueo → NO rectificable');
        $rect->execute("INSERT INTO cierres_asistencia (seccion_id, periodo_id, ra_bloqueado_por) VALUES (?, ?, ?)", [$sid, $p, $usuario]);
        $ok($rect->asistenciaRectificable($m, $p), 'confirmada + bloqueada → rectificable');

        $antes = $asistencia->contadoresDe($m, $p);
        $ancla = (new AsistenciaMotivoModel())->anclaDelPeriodo($p);

        $r = $asistencia->rectificarDia($m, $p, $f, 'FJ', null, $usuario);
        $ok(!$r['ok'], 'FJ sin motivo → rechazada');

        $r = $asistencia->rectificarDia($m, $p, $f, 'F', null, $usuario);
        $ok(!$r['ok'], 'la misma marca → rechazada (sin cambios)');

        $r = $asistencia->rectificarDia($m, $p, $f, 'FJ', $ancla, $usuario);
        $ok($r['ok'] && $r['anterior'] === ['F', null], 'F → FJ con el ancla → ok, anterior = F');
        $desp = $asistencia->contadoresDe($m, $p);
        $ok($desp['faltas'] === $antes['faltas'] - 1
            && $desp['faltas_justificadas'] === $antes['faltas_justificadas'] + 1,
            'contadores: una falta pasa a justificada');
        $fila = $rect->queryOne("SELECT confirmado_en, confirmado_por FROM inasistencias WHERE matricula_id = ? AND periodo_id = ?", [$m, $p]);
        $ok($fila['confirmado_en'] === $a['confirmado_en'] && (int) $fila['confirmado_por'] === (int) $a['confirmado_por'],
            'la confirmación ORIGINAL se conserva');

        $n0 = (int) $rect->queryOne("SELECT COUNT(*) AS n FROM rectificaciones_asistencia")['n'];
        $rect->registrarAsistencia($m, $p, $f, $r['anterior'], 'FJ', $ancla, 'verif', $usuario);
        $n1 = (int) $rect->queryOne("SELECT COUNT(*) AS n FROM rectificaciones_asistencia")['n'];
        $ok($n1 === $n0 + 1, 'traza registrada');

        // Un día «sin tomar» del mismo estudiante → «asistió».
        $per      = $asistencia->periodo($p);
        $jornadas = new \App\Models\AsistenciaJornadaModel();
        $sinTomar = null;
        foreach (AsistenciaModel::diasMarcables($per, null, $jornadas->noLectivos()) as $d) {
            if ($asistencia->marcaDelDia($m, $p, $d)[0] === 'sin_tomar') { $sinTomar = $d; break; }
        }
        if ($sinTomar === null) {
            // Se fabrica uno: se quita la lista de la sección y el ✓ propio de un día sin incidencia.
            foreach (AsistenciaModel::diasMarcables($per, null, $jornadas->noLectivos()) as $d) {
                if ($asistencia->marcaDelDia($m, $p, $d)[0] === 'asistio') {
                    $rect->execute("DELETE FROM asistencia_jornadas WHERE seccion_id = ? AND periodo_id = ? AND fecha = ?", [$sid, $p, $d]);
                    $rect->execute("DELETE FROM asistencia_presencias WHERE matricula_id = ? AND fecha = ?", [$m, $d]);
                    $sinTomar = $d;
                    break;
                }
            }
        }
        if ($sinTomar === null) {
            echo "  SKIP sin un día para probar «sin tomar» → «asistió»\n";
        } else {
            $c0 = $asistencia->contadoresDe($m, $p);
            $r  = $asistencia->rectificarDia($m, $p, $sinTomar, null, null, $usuario);
            $c1 = $asistencia->contadoresDe($m, $p);
            $ok($r['ok'] && $r['anterior'] === ['sin_tomar', null], "sin tomar → asistió ({$sinTomar})");
            $ok($c0 === $c1, 'sin tomar → asistió no toca contadores ni confirmación');
            $ok($asistencia->marcaDelDia($m, $p, $sinTomar)[0] === 'asistio', 'el día queda cubierto');
        }

        // Fila extraordinaria → rechazada.
        $rect->execute("UPDATE inasistencias SET extraordinaria = 1 WHERE matricula_id = ? AND periodo_id = ?", [$m, $p]);
        $r = $asistencia->rectificarDia($m, $p, $f, 'F', null, $usuario);
        $ok(!$r['ok'], 'fila extraordinaria → rechazada');
        $ok(!$rect->asistenciaRectificable($m, $p), 'fila extraordinaria → NO rectificable');
    }
} finally {
    $rect->rollback();
}

echo $fallos === 0 ? "\nTODO OK\n" : "\n{$fallos} FALLO(S)\n";
exit($fallos === 0 ? 0 : 1);
