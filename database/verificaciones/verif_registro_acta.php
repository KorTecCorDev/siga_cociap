<?php

/**
 * Verificación — REGISTRO AUXILIAR con las reglas del ACTA SIAGIE (28/10/2026).
 * Uso: php database/verificaciones/verif_registro_acta.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN.
 *
 * CONTEXTO (decisiones del usuario, 28/10/2026)
 *   El registro auxiliar sigue EQUIVALENCIAS_ACTA_SIAGIE (helpers.php), resuelta
 *   por `SituacionFinalModel::equivalenciasActa()`:
 *   - Secundaria: Ética y Valores (área de tutoría) se presenta como EDUCACIÓN
 *     RELIGIOSA, con su nota, criterios y conclusión replicados en C51 y C52, y
 *     JAMÁS con su propia competencia.
 *   - 5.º de secundaria: GAMA (CT4) se replica en TODAS las competencias de EPT y
 *     de Arte y Cultura, con TODOS sus criterios por curso y el logro que congeló
 *     el tutor.
 *
 * QUÉ COMPRUEBA (bimestres cerrados del año activo, cada sección de secundaria)
 *   1. Ninguna hoja es la del área de tutoría y ninguna columna ni anexo lleva la
 *      competencia de Ética.
 *   2. La hoja «Educación Religiosa» lleva las competencias del plan de esa área y,
 *      en cada una, el logro y las notas por criterio de Ética de la carga de
 *      tutoría (control escrito A MANO en SQL).
 *   3. En 5.º, las hojas de EPT y Arte llevan en cada competencia los criterios de
 *      GAMA de todas las cargas que la tienen bloqueada y, por fila, el promedio
 *      de GAMA (control a mano); en 1.º–4.º no hay hojas de réplica.
 *   4. La tarjeta del docente tutor se rotula con el área destino.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

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

use App\Models\RegistroAuxiliarModel;

$pdo = Core\Database::connect();
$ok  = true;

$chk = static function (string $titulo, bool $pasa, string $detalle = '') use (&$ok): void {
    if (!$pasa) { $ok = false; }
    echo ($pasa ? '  OK   ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};
$q = static function (string $sql, array $p = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

// Fuentes y destinos, escritos a mano (no desde el resolvedor que se verifica).
$etica = $q("SELECT c.id, c.codigo_minedu FROM competencias c JOIN areas a ON a.id = c.area_id
             JOIN niveles n ON n.id = a.nivel_id WHERE n.codigo LIKE 'sec%' AND a.nombre_boleta = ?", [AREA_ETICA_NOMBRE_BOLETA]);
$gama  = $q("SELECT c.id FROM competencias c JOIN areas a ON a.id = c.area_id JOIN niveles n ON n.id = a.nivel_id
             WHERE n.codigo LIKE 'sec%' AND c.codigo_minedu = 'CT4'");
$area  = static fn(string $cod): ?array => $q("SELECT a.id, a.nombre FROM areas a JOIN niveles n ON n.id = a.nivel_id
             WHERE n.codigo LIKE 'sec%' AND FIND_IN_SET(?, a.codigo_siagie)", [$cod])[0] ?? null;
$plan  = static fn(int $areaId): array => array_column($q("SELECT c.codigo_minedu FROM competencias c LEFT JOIN subareas s ON s.id = c.subarea_id
             WHERE COALESCE(c.area_id, s.area_id) = ? ORDER BY c.orden, c.id", [$areaId]), 'codigo_minedu');
$erel = $area('035');
$ept  = $area('032');
$arte = $area('0001');
$chk('fuentes y destinos únicos en secundaria (Ética, GAMA, 035, 032, 0001)',
    count($etica) === 1 && count($gama) === 1 && $erel && $ept && $arte);
if (!$ok) { echo "\nHAY FALLAS\n"; exit(1); }
$eticaId  = (int) $etica[0]['id'];
$eticaCod = (string) $etica[0]['codigo_minedu'];
$gamaId   = (int) $gama[0]['id'];

$secciones = $q("SELECT s.id, g.numero, CONCAT(g.nombre_display, ' ', s.nombre) nombre FROM secciones s
                 JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
                 JOIN anios_academicos an ON an.id = s.anio_id AND an.estado = 'activo'
                 WHERE n.codigo LIKE 'sec%' ORDER BY g.numero, s.nombre");
$periodos  = array_column($q("SELECT p.id FROM periodos p JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
                              WHERE p.estado = 'cerrado' ORDER BY p.numero"), 'id');

$reg   = new RegistroAuxiliarModel();
$mal   = ['1' => [], '2' => [], '3' => [], '4' => []];
$cuenta = ['erel' => 0, 'filas' => 0, 'replica' => 0, 'gama' => 0];
foreach ($periodos as $p) {
    $p = (int) $p;
    $periodo = $reg->periodo($p);
    foreach ($secciones as $s) {
        $sid = (int) $s['id'];
        $cargas = $reg->cargasDeSeccion($sid, $p);
        if ($cargas === []) { continue; }
        $porArea = [];
        foreach ($cargas as $c) { $porArea[(int) $c['area_id']][] = $c; }
        $hojas = [];
        foreach ($porArea as $grupo) {
            $c0 = $grupo[0];
            $hojas[] = $reg->hoja(['area_nombre' => $c0['area_nombre'], 'area_tipo' => $c0['area_tipo'],
                'grado_nombre' => $c0['grado_nombre'], 'seccion_nombre' => $c0['seccion_nombre'],
                'nivel_nombre' => $c0['nivel_nombre'], 'nivel_codigo' => $c0['nivel_codigo'],
                'es_aula' => !empty($c0['es_aula'])], $grupo, $periodo, false);
        }
        $replicas = $reg->hojasReplicaTransversal($cargas, $periodo);
        $etq = "{$s['nombre']} per $p";

        // 1. Nada de tutoría ni de la competencia de Ética.
        foreach (array_merge($hojas, $replicas) as $h) {
            if ($h['meta']['area_tipo'] === 'tutoria') { $mal['1'][] = "$etq: hoja de tutoría «{$h['area_nombre']}»"; }
            foreach ($h['columnas'] as $col) {
                if ((int) $col['id'] === $eticaId) { $mal['1'][] = "$etq: columna $eticaCod en «{$h['area_nombre']}»"; }
            }
            foreach ($h['anexo_retorno'] ?? [] as $est) {
                foreach ($est['cargas'] as $g) {
                    foreach ($g['competencias'] as $c) {
                        if ((int) $c['id'] === $eticaId) { $mal['1'][] = "$etq: $eticaCod en el anexo del retorno"; }
                    }
                }
            }
        }

        // 2. Educación Religiosa = Ética de la carga de tutoría, replicada.
        $toe = array_values(array_filter($cargas, static fn($c) => $c['area_tipo'] === 'tutoria'));
        $bloqueada = $toe !== [] && $q("SELECT 1 FROM bloqueos_competencia WHERE carga_id = ? AND competencia_id = ? AND periodo_id = ?",
            [(int) $toe[0]['id'], $eticaId, $p]) !== [];
        $her = array_values(array_filter($hojas, static fn($h) => $h['area_nombre'] === $erel['nombre']));
        if ($bloqueada) {
            if (count($her) !== 1) {
                $mal['2'][] = "$etq: " . count($her) . " hojas de Educación Religiosa";
            } else {
                $cuenta['erel']++;
                $codigos = array_column($her[0]['columnas'], 'codigo');
                if ($codigos !== $plan((int) $erel['id'])) {
                    $mal['2'][] = "$etq: columnas " . implode(',', $codigos);
                }
                // Control a mano: logro y notas por criterio de Ética en la carga de tutoría.
                $logro = array_column($q("SELECT matricula_id, nota_numerica FROM calificaciones
                                          WHERE carga_id = ? AND competencia_id = ? AND periodo_id = ?",
                                          [(int) $toe[0]['id'], $eticaId, $p]), 'nota_numerica', 'matricula_id');
                $notas = [];
                foreach ($q("SELECT cc.matricula_id, cc.criterio_id, cc.nota FROM calificaciones_criterio cc
                             JOIN criterios cr ON cr.id = cc.criterio_id
                             WHERE cr.carga_id = ? AND cr.competencia_id = ? AND cr.periodo_id = ?
                               AND cr.eliminado_en IS NULL AND cr.extraordinario = 0",
                             [(int) $toe[0]['id'], $eticaId, $p]) as $r) {
                    $notas[(int) $r['matricula_id']][(int) $r['criterio_id']] = (string) $r['nota'];
                }
                foreach ($her[0]['columnas'] as $col) {
                    // Solo los criterios que el documento PINTA (ordinarios): la
                    // fila trae además los extraordinarios de RA (I Bimestre).
                    $pintados = array_flip(array_map('intval', array_column($col['criterios'] ?? [], 'id')));
                    foreach ($col['lista'] ?? [] as $a) {
                        $mid = (int) $a['matricula_id'];
                        if (($a['cursado_en'] ?? null) !== null) { continue; }   // retorno: fila vacía
                        $cuenta['filas']++;
                        $vistas = array_map('strval', array_filter(
                            array_intersect_key($a['notas_criterios'], $pintados),
                            static fn($n) => $n !== null
                        ));
                        ksort($vistas);
                        $esperadas = $notas[$mid] ?? [];
                        ksort($esperadas);
                        if ((string) $a['promedio'] !== (string) ($logro[$mid] ?? '') || $vistas !== $esperadas) {
                            $mal['2'][] = "$etq {$col['codigo']} mat $mid: {$a['promedio']} vs " . ($logro[$mid] ?? '—');
                        }
                    }
                }
            }
        }

        // 3. GAMA en EPT y Arte (5.º); ninguna réplica en 1.º–4.º.
        if ((int) $s['numero'] !== 5) {
            if ($replicas !== []) { $mal['3'][] = "$etq: hojas de réplica fuera de 5.º"; }
            continue;
        }
        $nombres = array_column($replicas, 'area_nombre');
        sort($nombres);
        $esperado = [$arte['nombre'], $ept['nombre']];
        sort($esperado);
        if ($nombres !== $esperado) {
            $mal['3'][] = "$etq: hojas de réplica [" . implode(', ', $nombres) . ']';
            continue;
        }
        $critCtl = (int) ($q("SELECT COUNT(*) n FROM criterios cr
                              JOIN cargas_academicas ca ON ca.id = cr.carga_id AND ca.seccion_id = ?
                              JOIN bloqueos_competencia bc ON bc.carga_id = cr.carga_id AND bc.competencia_id = cr.competencia_id AND bc.periodo_id = cr.periodo_id
                              WHERE cr.competencia_id = ? AND cr.periodo_id = ? AND cr.eliminado_en IS NULL AND cr.extraordinario = 0",
                              [$sid, $gamaId, $p])[0]['n'] ?? 0);
        $promCtl = array_column($q("SELECT cal.matricula_id, ROUND(AVG(cal.nota_numerica)) pr FROM calificaciones cal
                                    JOIN cargas_academicas ca ON ca.id = cal.carga_id AND ca.seccion_id = ?
                                    JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id AND bc.periodo_id = cal.periodo_id
                                    WHERE cal.competencia_id = ? AND cal.periodo_id = ? GROUP BY cal.matricula_id",
                                    [$sid, $gamaId, $p]), 'pr', 'matricula_id');
        $cerrado = $q("SELECT 1 FROM cierres_transversales WHERE seccion_id = ? AND periodo_id = ? AND anulado_en IS NULL LIMIT 1", [$sid, $p]) !== [];
        foreach ($replicas as $h) {
            $cuenta['replica']++;
            if (array_column($h['columnas'], 'codigo') !== $plan((int) ($h['area_nombre'] === $ept['nombre'] ? $ept['id'] : $arte['id']))) {
                $mal['3'][] = "$etq «{$h['area_nombre']}»: columnas distintas del plan";
            }
            foreach ($h['columnas'] as $col) {
                if ($col['tipo'] !== 'propia') {
                    if ($cerrado && $critCtl > 0) { $mal['3'][] = "$etq «{$h['area_nombre']}» {$col['codigo']}: {$col['tipo']} con cierre"; }
                    continue;
                }
                if (count($col['criterios']) !== $critCtl) {
                    $mal['3'][] = "$etq «{$h['area_nombre']}» {$col['codigo']}: " . count($col['criterios']) . " criterios vs $critCtl";
                }
                foreach ($col['lista'] as $a) {
                    if (($a['cursado_en'] ?? null) !== null) { continue; }
                    $cuenta['gama']++;
                    if ((string) $a['promedio'] !== (string) ($promCtl[(int) $a['matricula_id']] ?? '')) {
                        $mal['3'][] = "$etq «{$h['area_nombre']}» {$col['codigo']} mat {$a['matricula_id']}: {$a['promedio']} vs " . ($promCtl[(int) $a['matricula_id']] ?? '—');
                    }
                }
            }
        }

        // 4. La tarjeta del tutor (una vez por sección).
        if ($p === (int) $periodos[0] && $toe !== [] && $reg->nombreEnRegistro($sid, (int) $toe[0]['area_id']) !== $erel['nombre']) {
            $mal['4'][] = "$etq: la tarjeta del tutor no dice «{$erel['nombre']}»";
        }
    }
}

echo "=== 1. Sin tutoría ni la competencia de Ética ($eticaCod) en el registro ===\n";
$chk('ninguna hoja, columna ni anexo', $mal['1'] === [], implode(' | ', array_slice($mal['1'], 0, 5)));
echo "=== 2. Educación Religiosa = Ética replicada ===\n";
$chk("{$cuenta['erel']} hojas, {$cuenta['filas']} filas×competencia iguales al control", $mal['2'] === [] && $cuenta['erel'] > 0, implode(' | ', array_slice($mal['2'], 0, 5)));
echo "=== 3. 5.º: GAMA replicada en EPT y Arte ===\n";
$chk("{$cuenta['replica']} hojas, {$cuenta['gama']} logros iguales al promedio del control", $mal['3'] === [] && $cuenta['replica'] > 0, implode(' | ', array_slice($mal['3'], 0, 5)));
echo "=== 4. Tarjeta del docente tutor ===\n";
$chk('rotulada como el área destino', $mal['4'] === [], implode(' | ', array_slice($mal['4'], 0, 5)));

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
