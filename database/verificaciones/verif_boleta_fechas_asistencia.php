<?php

/**
 * Verificación — las FECHAS de asistencia de la boleta CUADRAN con sus totales.
 * Uso: php database/verificaciones/verif_boleta_fechas_asistencia.php
 *
 * SOLO LECTURA: no escribe nada.
 *
 * Desde el 17/10/2026 la boleta lista las fechas de cada incidencia
 * (`AsistenciaModel::fechasParaBoleta`, vía `BoletaModel::armar`), agrupadas por
 * el contador en que CUENTAN: una FJ con motivo distinto del ancla sale en
 * «Faltas». Esto comprueba, para cada estudiante con asistencia en un bimestre
 * por fechas, que los días de los tramos de cada categoría suman EXACTAMENTE el
 * contador que la misma boleta muestra. Si alguien cambia la regla de conteo en
 * un sitio y no en el otro, la boleta diría «3 faltas» con 2 fechas: esto falla.
 *
 * También comprueba que:
 *   - los bimestres por totales (I y II, vía extraordinaria) no traen fechas;
 *   - un bimestre que el umbral 'oficial' no muestra no trae fechas (no se fugan
 *     fechas de un bimestre sin cerrar o sin publicar a la familia);
 *   - `hay_fechas` coincide con lo que trae la boleta.
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

use App\Models\AsistenciaModel;
use App\Models\BoletaModel;

$boleta = new BoletaModel();
$asis   = new AsistenciaModel();

$fallos = 0;
$fallo  = static function (string $msg) use (&$fallos): void {
    $fallos++;
    echo "  FALLO: {$msg}\n";
};

// Estudiantes con fila de asistencia en algún bimestre por fechas.
$filas = $asis->query("
    SELECT DISTINCT i.matricula_id, i.periodo_id
    FROM inasistencias i
    INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
    ORDER BY i.periodo_id, i.matricula_id
");

$revisados = $conFechas = $fjComoF = 0;
foreach ($filas as $f) {
    $mid = (int) $f['matricula_id'];
    $pid = (int) $f['periodo_id'];

    // 'todos' muestra el bimestre en curso: es el umbral con más datos.
    $data = $boleta->armar($mid, $pid, 'todos', true);
    if ($data === null) {
        continue;
    }
    $revisados++;
    $hay = false;

    foreach ($data['asistencia']['bimestres'] as $b) {
        $etq = "mat {$mid} bim {$b['numero']}";
        if (!empty($b['sin_registro'])) {
            if ($b['fechas'] !== null) {
                $fallo("{$etq}: sin registro pero trae fechas");
            }
            continue;
        }
        $porFechas = $asis->periodoPorFechas((int) $b['id']);
        if (!$porFechas) {
            if ($b['fechas'] !== null) {
                $fallo("{$etq}: bimestre por totales con fechas");
            }
            continue;
        }
        if ($b['fechas'] === null) {
            continue;   // vía extraordinaria: por totales
        }
        foreach (AsistenciaModel::CAMPOS as $campo) {
            $dias = array_sum(array_column($b['fechas'][$campo] ?? [], 'dias'));
            if ($dias !== (int) $b['datos'][$campo]) {
                $fallo("{$etq} {$campo}: {$dias} días en fechas, total {$b['datos'][$campo]}");
            }
            if ($dias > 0) {
                $hay = true;
            }
        }
        $conFechas++;
    }
    if ($hay !== (bool) $data['asistencia']['hay_fechas']) {
        $fallo("mat {$mid}: hay_fechas no coincide");
    }

    // 'oficial' no puede traer fechas de un bimestre que no muestra.
    $ofi = $boleta->armar($mid, $pid, 'oficial', true);
    foreach ($ofi['asistencia']['bimestres'] ?? [] as $b) {
        if (!empty($b['sin_registro']) && $b['fechas'] !== null) {
            $fallo("mat {$mid} bim {$b['numero']}: 'oficial' trae fechas de un bimestre que no muestra");
        }
    }
}

// ¿Se ejercitó la regla de la FJ fuera del ancla? (si no hay casos, se avisa)
$fjComoF = (int) ($asis->queryOne("
    SELECT COUNT(*) AS n
    FROM asistencia_incidencias x
    INNER JOIN periodos p ON p.id = x.periodo_id
    WHERE x.tipo = 'FJ'
      AND COALESCE(x.motivo_id, 0) <> COALESCE(p.asistencia_motivo_principal_id,
          (SELECT id FROM asistencia_motivos WHERE es_principal = 1 LIMIT 1), 0)
")['n'] ?? 0);

echo "Estudiantes revisados: {$revisados}. Bimestres con fechas: {$conFechas}. "
   . "FJ fuera del ancla en los datos: {$fjComoF}. Fallos: {$fallos}.\n";
if ($fjComoF === 0) {
    echo "  AVISO: no hay FJ fuera del ancla; la regla 'cuenta como falta' no se ejercitó.\n";
}
echo $fallos === 0 ? "OK\n" : "HAY FALLOS\n";
exit($fallos === 0 ? 0 : 1);
