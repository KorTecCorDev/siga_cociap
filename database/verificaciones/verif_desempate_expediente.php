<?php

/**
 * Verificación — el expediente del desempate CUADRA con el motor del mérito.
 * Uso: php database/verificaciones/verif_desempate_expediente.php
 *
 * SOLO LECTURA: no escribe nada.
 *
 * Para cada periodo activo o cerrado y cada grupo empatado —los pendientes en vivo
 * y los ya resueltos en el acta—, comprueba que el cuadro comparativo por
 * competencia (`DesempateMeritoModel::getComparativoCompetencias`) cuenta y suma
 * EXACTAMENTE las notas con las que el ranking en vivo calculó el promedio:
 * mismo `num_competencias` y mismo `total_notas` por matrícula.
 *
 * Existe porque hasta el 17/10/2026 el comparativo no aplicaba los filtros del
 * motor (bloqueadas, no extraordinarias, regla de Ética, áreas exoneradas) y
 * podía enseñar notas que el promedio empatado no contaba. Los filtros viven
 * en `OrdenMeritoModel::sqlNotasQueCuentan` y siguen copiados en las dos
 * consultas del motor: si alguien mueve una regla en un solo sitio, esto falla.
 *
 * También ejercita `expediente()` (estructura mínima) y la pantalla en vivo
 * (`gruposEmpatePendientesLive`) frente al guard del cierre.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Guarda: nunca contra producción.
if (is_file('/home/u761410128/siga_secrets/database.php')) {
    fwrite(STDERR, "ABORTA: detectado el archivo de secretos de PRODUCCION.\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    $map = ['Core\\' => CORE_PATH . '/', 'App\\Models\\' => APP_PATH . '/Models/'];
    foreach ($map as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require_once $file; return; }
        }
    }
});
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';

$orden     = new App\Models\OrdenMeritoModel();
$desempate = new App\Models\DesempateMeritoModel();

// El ranking EN VIVO es privado a propósito (nadie fuera del modelo debe leerlo
// sin el wrapper); aquí se lee para comparar, solo en esta verificación.
$live = new ReflectionMethod(App\Models\OrdenMeritoModel::class, 'rankingGradoLive');
$live->setAccessible(true);

$periodos = $desempate->query("
    SELECT id, nombre_display FROM periodos
    WHERE estado IN ('activo', 'cerrado') ORDER BY anio_id, numero
");

$fallos = 0;
$grupos = 0;

foreach ($periodos as $p) {
    $pid = (int) $p['id'];

    // Grupos a revisar: [grado_id => [lista de matricula_id]].
    $porGrado = [];
    foreach ($desempate->getActaPorPeriodo($pid) as $res) {
        $porGrado[(int) $res['grado_id']][] = array_map(
            static fn($a) => (int) $a['matricula_id'], $res['alumnos']
        );
    }
    foreach ($orden->gradosConEmpatesPendientesDetalle($pid) as $g) {
        $gid = (int) $g['grado_id'];
        $pend = $orden->gruposEmpatePendientesLive($gid, $pid);
        if (count($pend) !== (int) $g['n_grupos']) {
            echo "FALLO {$p['nombre_display']} grado {$gid}: la pantalla ve " . count($pend)
               . " grupos y el guard del cierre {$g['n_grupos']}.\n";
            $fallos++;
        }
        foreach ($pend as $filas) {
            $porGrado[$gid][] = array_map(static fn($f) => (int) $f['matricula_id'], $filas);
        }
    }

    foreach ($porGrado as $gid => $listas) {
        $metricas = [];
        foreach ($live->invoke($orden, $gid, $pid) as $f) {
            $metricas[(int) $f['matricula_id']] = $f;
        }

        foreach ($listas as $ids) {
            $grupos++;
            $comp = $desempate->getComparativoCompetencias($ids, $pid);

            foreach ($ids as $mid) {
                $n = 0; $suma = 0;
                foreach ($comp as $c) {
                    if (($c['notas'][$mid] ?? null) !== null) {
                        $n++;
                        $suma += $c['notas'][$mid];
                    }
                }
                $m = $metricas[$mid] ?? null;
                // Fuera del ranking en vivo (p. ej. dejó de competir tras una
                // resolución histórica): no hay promedio con qué cuadrar.
                if ($m === null) {
                    continue;
                }
                if ($n !== (int) $m['num_competencias'] || $suma !== (int) $m['total_notas']) {
                    echo "FALLO {$p['nombre_display']} grado {$gid} matricula {$mid}: comparativo "
                       . "{$n} notas / suma {$suma}; motor {$m['num_competencias']} / {$m['total_notas']}.\n";
                    $fallos++;
                }
            }

            $exp = $desempate->expediente($ids, $pid);
            $ultimo = end($exp['periodos']);
            if (count($exp['alumnos']) !== count($ids) || !$ultimo || (int) $ultimo['id'] !== $pid) {
                echo "FALLO {$p['nombre_display']} grado {$gid}: expediente incompleto para "
                   . implode(',', $ids) . ".\n";
                $fallos++;
            }
        }
    }
}

echo "Grupos revisados: {$grupos}. Fallos: {$fallos}.\n";
echo $fallos === 0 ? "OK\n" : "HAY FALLOS\n";
exit($fallos === 0 ? 0 : 1);
