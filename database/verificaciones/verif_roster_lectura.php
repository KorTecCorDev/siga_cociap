<?php

/**
 * Verificación — ROSTER DE LECTURA de un bimestre (28/10/2026).
 * Uso: php database/verificaciones/verif_roster_lectura.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN.
 *
 * CONTEXTO
 *   El registro auxiliar, el historial del docente y /consulta-notas armaban la
 *   lista de un bimestre con el `tipo` y el `estado` de HOY: un trasladado o
 *   retirado desaparecía de los bimestres que sí cursó, con sus notas oficiales
 *   (15 matrículas en la BD local al medirlo). Desde esta fecha las vistas de
 *   solo lectura usan `RetornoGradoModel::sqlRosterLecturaDelPeriodo`.
 *
 * QUÉ COMPRUEBA
 *   1. Todo trasladado/retirado con una calificación BLOQUEADA en una carga de
 *      la sección y el periodo aparece en los bloques del registro auxiliar
 *      (`bloquesBloqueadosDeCarga`), comparado contra una consulta de control
 *      ESCRITA A MANO aquí.
 *   2. Ningún trasladado/retirado SIN dato oficial del periodo entra al roster
 *      de lectura de la sección.
 *   3. El roster de lectura CONTIENE al de evaluación del periodo
 *      (`sqlRosterDelPeriodo`): solo añade, nunca quita.
 *   4. Retorno de grado (decisión del usuario, 28/10/2026: «solo es válida su
 *      matrícula real»): la OPERATIVA no entra NUNCA y la OFICIAL entra en
 *      TODOS los bimestres, en la sección donde los cursó.
 *   5. Sin el flag, `getResumenCompetencia` sigue sin trasladados ni retirados
 *      (la validación del bloqueo no cambió).
 *   6. Las llamadas de /consulta-notas pasan el flag de lectura.
 *   7. CHIPS DE SITUACIÓN (`SituacionEstudianteModel`): cada fila de lectura
 *      lleva exactamente las claves que da un control ESCRITO A MANO aquí
 *      (salida por `tipo`, retorno en su tramo, cambio de sección «viene»/«pasó»
 *      con la sección correcta), y toda clave existe en SITUACIONES_ESTUDIANTE.
 *   8. Barrido: ninguna vista pinta a mano un chip de situación (todas pasan por
 *      `shared/_situacion-chips.php`) ni queda rastro de `etiqueta_salida()`.
 *   9. Retorno: en su tramo, la fila OFICIAL muestra los datos de la operativa
 *      —cada logro y conclusión bloqueados (en la carga de la misma área de la
 *      sección oficial; si no hay, FALLA: escenario 6, va en «Sin carga»), con
 *      los criterios fundidos en la sección donde cursó; conducta, asistencia y
 *      promedio transversal—, y FUERA del tramo, sus propias notas sin fundir.
 *      (Es lo que ven las PANTALLAS de lectura.)
 *  10. El REGISTRO IMPRESO (decisión del usuario: «fila vacía + anexo»): la fila
 *      oficial del tramo va vacía en la matriz y en la CD, y el anexo trae CADA
 *      nota por criterio y cada logro de la operativa exactamente una vez
 *      (contra un conteo a mano); la hoja de transversales de la sección deja la
 *      fila vacía y pone en su anexo el promedio del tutor de la sección operativa.
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

use App\Models\CalificacionModel;
use App\Models\CambioSeccionModel;
use App\Models\RegistroAuxiliarModel;
use App\Models\RetornoGradoModel;

$pdo = Core\Database::connect();
$ok  = true;

$chk = static function (string $titulo, bool $pasa, string $detalle = '') use (&$ok): void {
    if (!$pasa) { $ok = false; }
    echo ($pasa ? '  OK   ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};
$col = static function (string $sql, array $p = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

$periodos = $col("SELECT p.id FROM periodos p
                  INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
                  WHERE p.estado IN ('activo', 'cerrado') ORDER BY p.numero");
$secciones = $col("SELECT DISTINCT ca.seccion_id FROM cargas_academicas ca
                   INNER JOIN anios_academicos a ON a.id = ca.anio_id AND a.estado = 'activo'
                   WHERE ca.estado = 'activa' ORDER BY ca.seccion_id");

// Roster de lectura de la sección, directo del fragmento.
$rosterLectura = static function (int $s, int $p) use ($col): array {
    return $col("SELECT m.id FROM matriculas m
                 WHERE " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $s, $p) . "
                 " . RetornoGradoModel::sqlRosterLecturaDelPeriodo('m', (string) $p, $s));
};
$rosterEval = static function (int $s, int $p) use ($col): array {
    return $col("SELECT m.id FROM matriculas m
                 WHERE " . CambioSeccionModel::sqlEnSeccionDelPeriodo('m', $s, $p) . "
                 " . RetornoGradoModel::sqlRosterDelPeriodo('m', (string) $p));
};

// ── 1. Trasladados/retirados con nota bloqueada → en el registro auxiliar ──
echo "=== 1. Trasladados y retirados con nota oficial aparecen en el registro ===\n";
$reg = new RegistroAuxiliarModel();
$faltan = [];
$casos  = 0;
foreach ($periodos as $p) {
    foreach ($secciones as $s) {
        // Control A MANO: nota en calificaciones de una competencia bloqueada,
        // en una carga de la sección, de una matrícula que ya no está.
        $control = $col("
            SELECT DISTINCT cal.matricula_id
            FROM calificaciones cal
            INNER JOIN cargas_academicas ca ON ca.id = cal.carga_id AND ca.seccion_id = ? AND ca.estado = 'activa'
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id = cal.periodo_id
            INNER JOIN matriculas m ON m.id = cal.matricula_id
            WHERE cal.periodo_id = ? AND m.tipo IN ('trasladado', 'retirado')
        ", [$s, $p]);
        if ($control === []) {
            continue;
        }
        $vistos = [];
        foreach ($col("SELECT id FROM cargas_academicas WHERE seccion_id = ? AND estado = 'activa'", [$s]) as $cid) {
            foreach ($reg->bloquesBloqueadosDeCarga($cid, $p) as $b) {
                foreach ($b['alumnos'] as $a) {
                    $vistos[(int) $a['matricula_id']] = true;
                }
            }
        }
        foreach ($control as $mid) {
            $casos++;
            if (!isset($vistos[$mid])) {
                $faltan[] = "mat $mid (sec $s, per $p)";
            }
        }
    }
}
$chk("$casos pares (matrícula, bimestre) de trasladados/retirados con nota oficial", $faltan === [],
    $faltan === [] ? 'todos presentes' : implode(', ', array_slice($faltan, 0, 10)));

// ── 2. Sin dato oficial, no entra ────────────────────────────────────────
echo "=== 2. Trasladados y retirados SIN dato oficial no entran ===\n";
$intrusos = [];
foreach ($periodos as $p) {
    foreach ($secciones as $s) {
        $ids = $rosterLectura($s, $p);
        if ($ids === []) {
            continue;
        }
        $ph = implode(',', $ids);
        foreach ($col("
            SELECT m.id FROM matriculas m
            WHERE m.id IN ($ph) AND m.tipo IN ('trasladado', 'retirado')
              AND NOT EXISTS (SELECT 1 FROM calificaciones c
                              INNER JOIN cargas_academicas ca ON ca.id = c.carga_id AND ca.seccion_id = ?
                              INNER JOIN bloqueos_competencia b ON b.carga_id = c.carga_id
                                     AND b.competencia_id = c.competencia_id AND b.periodo_id = c.periodo_id
                              WHERE c.matricula_id = m.id AND c.periodo_id = ?)
              AND NOT EXISTS (SELECT 1 FROM conducta_confirmaciones x WHERE x.matricula_id = m.id AND x.periodo_id = ?)
              AND NOT EXISTS (SELECT 1 FROM calificaciones_conducta x WHERE x.matricula_id = m.id AND x.periodo_id = ?
                                 AND (x.literal IS NOT NULL OR x.nota_tutor IS NOT NULL))
              AND NOT EXISTS (SELECT 1 FROM inasistencias x WHERE x.matricula_id = m.id AND x.periodo_id = ?
                                 AND x.confirmado_en IS NOT NULL)
        ", [$s, $p, $p, $p, $p]) as $mid) {
            $intrusos[] = "mat $mid (sec $s, per $p)";
        }
    }
}
$chk('ningún trasladado/retirado sin dato oficial', $intrusos === [], implode(', ', array_slice($intrusos, 0, 10)));

// ── 3. Lectura ⊇ evaluación ──────────────────────────────────────────────
echo "=== 3. El roster de lectura contiene al de evaluación ===\n";
// Única salida permitida: la OPERATIVA de un retorno (la reemplaza la oficial, §4).
$operativas = array_flip($col("SELECT matricula_operativa_id FROM retornos_grado"));
$perdidos = [];
$extra    = [];
foreach ($periodos as $p) {
    foreach ($secciones as $s) {
        $lec  = $rosterLectura($s, $p);
        $eval = $rosterEval($s, $p);
        foreach (array_diff($eval, $lec) as $mid) {
            if (!isset($operativas[$mid])) {
                $perdidos[] = "mat $mid (sec $s, per $p)";
            }
        }
        foreach (array_diff($lec, $eval) as $mid) {
            $extra[] = $mid;
        }
    }
}
$chk('nadie del roster de evaluación se pierde (salvo la operativa de un retorno)', $perdidos === [], implode(', ', array_slice($perdidos, 0, 10)));
$oficiales = array_flip($col("SELECT matricula_oficial_id FROM retornos_grado"));
$extraOfi  = count(array_filter($extra, static fn(int $m): bool => isset($oficiales[$m])));
echo '       (lectura añade ' . count($extra) . ' pares matrícula-bimestre: '
   . (count($extra) - $extraOfi) . " de trasladados/retirados y $extraOfi de la oficial de un retorno en su tramo)\n";

// ── 4. Retorno de grado: solo la matrícula real ──────────────────────────
echo "=== 4. Retorno de grado: la oficial siempre, la operativa nunca ===\n";
$st = $pdo->query("SELECT r.*, pd.numero AS desde_n, ph.numero AS hasta_n
                   FROM retornos_grado r
                   INNER JOIN periodos pd ON pd.id = r.periodo_desde_id
                   LEFT  JOIN periodos ph ON ph.id = r.periodo_hasta_id");
$retornos = $st->fetchAll(PDO::FETCH_ASSOC);
$numeroDe = static fn(int $p): int => (int) $pdo->query("SELECT numero FROM periodos WHERE id = $p")->fetchColumn();
$enTramo  = static fn(array $r, int $n): bool => $n >= (int) $r['desde_n']
    && ($r['estado'] === 'activo' || ($r['hasta_n'] !== null && $n <= (int) $r['hasta_n']));
$operativaVista = [];
$oficialFalta   = [];
foreach ($retornos as $r) {
    $ofi = (int) $r['matricula_oficial_id'];
    $ope = (int) $r['matricula_operativa_id'];
    foreach ($periodos as $p) {
        // La operativa no puede aparecer en NINGUNA sección.
        foreach ($secciones as $s) {
            if (in_array($ope, $rosterLectura($s, $p), true)) {
                $operativaVista[] = "operativa $ope en sec $s per $p";
            }
        }
        // La oficial, en la sección donde cursó ese bimestre (si aún está o tiene dato).
        $sec = CambioSeccionModel::sqlSeccionDelPeriodo('m', $p);
        $s   = (int) $pdo->query("SELECT $sec FROM matriculas m WHERE m.id = $ofi")->fetchColumn();
        $tipo = (string) $pdo->query("SELECT tipo FROM matriculas WHERE id = $ofi")->fetchColumn();
        if (!in_array($tipo, ['trasladado', 'retirado'], true) && !in_array($ofi, $rosterLectura($s, $p), true)) {
            $oficialFalta[] = "oficial $ofi falta en sec $s per $p";
        }
    }
}
$chk(count($retornos) . ' retorno(s): la operativa no sale en ningún bimestre', $operativaVista === [], implode(', ', $operativaVista));
$chk('la oficial sale en todos los bimestres, en su sección', $oficialFalta === [], implode(', ', $oficialFalta));

// ── 5. Sin flag, el resumen sigue como antes ─────────────────────────────
echo "=== 5. getResumenCompetencia sin flag no cambia de universo ===\n";
$cal = new CalificacionModel();
$conTras = [];
$muestras = 0;
// Las filas ya no traen `tipo` (el chip sale de SituacionEstudianteModel).
$salidas = array_flip($col("SELECT id FROM matriculas WHERE tipo IN ('trasladado', 'retirado')"));
foreach ($pdo->query("SELECT DISTINCT bc.carga_id, bc.competencia_id, bc.periodo_id
                      FROM bloqueos_competencia bc
                      INNER JOIN cargas_academicas ca ON ca.id = bc.carga_id
                      INNER JOIN calificaciones cal ON cal.carga_id = bc.carga_id
                             AND cal.competencia_id = bc.competencia_id AND cal.periodo_id = bc.periodo_id
                      INNER JOIN matriculas m ON m.id = cal.matricula_id AND m.tipo IN ('trasladado', 'retirado')
                      LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $muestras++;
    foreach ([false, true] as $lectura) {
        $res = $cal->getResumenCompetencia((int) $f['carga_id'], (int) $f['competencia_id'], (int) $f['periodo_id'], false, $lectura);
        $hay = array_filter($res['alumnos'], static fn($a) => isset($salidas[(int) $a['matricula_id']]));
        $conTras[$lectura ? 'lectura' : 'sin'][] = $hay !== [];
    }
}
$chk("sin flag: 0 trasladados/retirados ($muestras competencias con uno de ellos)",
    !in_array(true, $conTras['sin'] ?? [], true));
$chk('con flag: aparecen en todas esas competencias',
    $muestras > 0 && !in_array(false, $conTras['lectura'] ?? [], true));

// ── 6. /consulta-notas pasa el flag ──────────────────────────────────────
echo "=== 6. Las llamadas de /consulta-notas usan el roster de lectura ===\n";
$src = file_get_contents(APP_PATH . '/Controllers/Consulta/ConsultaNotasController.php');
$reglas = [
    'getResumenCompetencia'        => '/getResumenCompetencia\([^;]*\$periodoId, false, true\)/',
    'getEstudiantesParaRegistro'   => '/getEstudiantesParaRegistro\([^;]*, true, true\)/',
    'getEstudiantesConIncidencias' => '/getEstudiantesConIncidencias\([^;]*, true, true\)/',
    'getPromediosSeccion'          => '/getPromediosSeccion\([^;]*, true\)/',
    'getConclusionesSeccion'       => '/getConclusionesSeccion\([^;]*, true\)/',
];
foreach ($reglas as $metodo => $re) {
    $total = preg_match_all('/' . $metodo . '\(/', $src);
    $bien  = preg_match_all($re, $src);
    $chk("$metodo: $bien de $total llamadas con el flag", $total > 0 && $bien === $total);
}
$chk('getEstudiantesParaTutor: rosterSeccion pasa el flag',
    (bool) preg_match('/getEstudiantesParaTutor\(\s*\$seccionId,\s*\$periodoId,\s*\$this->conductaModel->totalCriterios\(\$nivelId, \$anioId\),\s*true\s*\)/', $src));

// ── 7. Chips de situación: modelo contra control a mano ──────────────────
echo "=== 7. Chips de situación de cada fila ===\n";
$sit       = new App\Models\SituacionEstudianteModel();
$tipos     = [];
foreach ($pdo->query("SELECT id, tipo FROM matriculas") as $r) {
    $tipos[(int) $r['id']] = (string) $r['tipo'];
}
$cambios = $pdo->query("
    SELECT cs.matricula_id, cs.seccion_origen_id, cs.seccion_destino_id, p.numero,
           CONCAT(go.nombre_display, ' ', so.nombre) AS origen,
           CONCAT(gd.nombre_display, ' ', sd.nombre) AS destino
    FROM cambios_seccion cs
    INNER JOIN periodos p ON p.id = cs.periodo_id
    INNER JOIN secciones so ON so.id = cs.seccion_origen_id INNER JOIN grados go ON go.id = so.grado_id
    INNER JOIN secciones sd ON sd.id = cs.seccion_destino_id INNER JOIN grados gd ON gd.id = sd.grado_id
    WHERE cs.estado = 'vigente'
    ORDER BY p.numero, cs.id")->fetchAll(PDO::FETCH_ASSOC);
$difs    = [];
$conteo  = [];
foreach ($periodos as $p) {
    $n = $numeroDe($p);
    foreach ($secciones as $s) {
        $ids = $rosterLectura($s, $p);
        if ($ids === []) {
            continue;
        }
        $modelo = $sit->deMatriculas($ids, $s, $p);
        foreach ($ids as $mid) {
            // Control: las mismas cuatro reglas, escritas sin el modelo.
            $esperado = [];
            if (in_array($tipos[$mid] ?? '', ['trasladado', 'retirado'], true)) {
                $esperado[] = $tipos[$mid];
            }
            foreach ($retornos as $r) {
                if ((int) $r['matricula_oficial_id'] === $mid && $enTramo($r, $n)) {
                    $esperado[] = 'retorno';
                }
            }
            $viene = null;
            $paso  = null;
            foreach ($cambios as $c) {
                if ((int) $c['matricula_id'] !== $mid) {
                    continue;
                }
                if ((int) $c['seccion_destino_id'] === $s && (int) $c['numero'] <= $n) {
                    $viene = 'cambio_viene:' . $c['origen'];
                }
                if ($paso === null && (int) $c['seccion_origen_id'] === $s && (int) $c['numero'] > $n) {
                    $paso = 'cambio_paso:' . $c['destino'];
                }
            }
            if ($viene !== null) { $esperado[] = $viene; }
            if ($paso !== null)  { $esperado[] = $paso; }

            $obtenido = array_map(
                static fn(array $c): string => $c['clave'] . ($c['dato'] !== null ? ':' . $c['dato'] : ''),
                $modelo[$mid] ?? []
            );
            foreach ($obtenido as $o) {
                $clave = explode(':', $o)[0];
                $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
                if (situacion_estudiante($clave, 'x') === null) {
                    $difs[] = "clave '$clave' fuera del catálogo";
                }
            }
            if ($obtenido !== $esperado) {
                $difs[] = "mat $mid sec $s per $p: modelo [" . implode(', ', $obtenido) . '] vs control [' . implode(', ', $esperado) . ']';
            }
        }
    }
}
ksort($conteo);
$chk('cada fila lleva exactamente los chips del control (y en ese orden)', $difs === [], implode(' | ', array_slice($difs, 0, 8)));
echo '       (chips: ' . ($conteo === [] ? 'ninguno' : implode(', ', array_map(
    static fn(string $k, int $v): string => "$k $v", array_keys($conteo), $conteo))) . ")\n";
$chk('el ancla de la regla existe en la BD (hay al menos un chip de cada tipo medible)',
    isset($conteo['trasladado']) && isset($conteo['cambio_viene']) && isset($conteo['cambio_paso'])
    && ($retornos === [] || isset($conteo['retorno'])));

// ── 8. Barrido: ningún chip de situación a mano ──────────────────────────
echo "=== 8. Ninguna vista pinta a mano un chip de situación ===\n";
$aMano = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/resources/views', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php' || $f->getFilename() === '_situacion-chips.php') {
        continue;
    }
    $txt = file_get_contents($f->getPathname());
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT_PATH) + 1));
    // Las clases de situación (no las de ESTADO ni los usos de color ajenos,
    // que siguen con --continuador/--nuevo: ver docs/modulos/ui.md).
    // Solo dentro de un atributo class (un comentario que nombre la clase no cuenta).
    if (preg_match('/class="[^"]*matricula-badge--(trasladado|retirado|oficial|operativa|retorno|cambio)\b/', $txt)
        || preg_match('/matricula-badge--<\?=\s*e\(\$\w+\[.tipo.\]\)/', $txt)
        || str_contains($txt, 'etiqueta_salida') || str_contains($txt, 'ra-tag--salida')) {
        $aMano[] = $rel;
    }
}
$chk('todas pasan por shared/_situacion-chips.php', $aMano === [], implode(', ', $aMano));
$chk('helpers.php ya no define etiqueta_salida()', !function_exists('etiqueta_salida'));

// ── 9. Retorno: los datos del tramo llegan a la fila OFICIAL ─────────────
echo "=== 9. Retorno: los datos del tramo se leen por la fila oficial ===\n";
$con    = new App\Models\ConductaModel();
$asi    = new App\Models\AsistenciaModel();
$trm    = new App\Models\TransversalModel();
$logros = 0;
$malR   = [];
$one    = static function (string $sql, array $p = []) use ($pdo): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
foreach ($retornos as $r) {
    $ofi = (int) $r['matricula_oficial_id'];
    $ope = (int) $r['matricula_operativa_id'];
    foreach ($periodos as $p) {
        $s = (int) $pdo->query("SELECT " . CambioSeccionModel::sqlSeccionDelPeriodo('m', $p) . " FROM matriculas m WHERE m.id = $ofi")->fetchColumn();
        if (!$enTramo($r, $numeroDe($p))) {
            // Rama contraria: fuera del tramo, la fila oficial lee SUS datos.
            $st = $pdo->prepare("SELECT cal.carga_id, cal.competencia_id, cal.nota_numerica FROM calificaciones cal
                                 JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id
                                      AND bc.competencia_id = cal.competencia_id AND bc.periodo_id = cal.periodo_id
                                 WHERE cal.matricula_id = ? AND cal.periodo_id = ?");
            $st->execute([$ofi, $p]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $fila = array_values(array_filter(
                    $cal->getResumenCompetencia((int) $c['carga_id'], (int) $c['competencia_id'], $p, false, true)['alumnos'],
                    static fn($a) => (int) $a['matricula_id'] === $ofi
                ))[0] ?? null;
                $logrosPropios = ($logrosPropios ?? 0) + 1;
                if ($fila === null || (string) $fila['promedio'] !== (string) $c['nota_numerica'] || $fila['cursado_en'] !== null) {
                    $malR[] = "fuera del tramo (per $p, carga {$c['carga_id']}): la fila oficial no muestra su propia nota";
                }
            }
            continue;
        }
        $secOpe = (string) $pdo->query("SELECT CONCAT(g.nombre_display, ' ', s.nombre) FROM matriculas m
                                        JOIN secciones s ON s.id = m.seccion_id JOIN grados g ON g.id = s.grado_id
                                        WHERE m.id = $ope")->fetchColumn();
        // Control A MANO: cada calificación BLOQUEADA de la operativa en el bimestre.
        $st = $pdo->prepare("
            SELECT cal.competencia_id, cal.nota_numerica, cal.conclusion_descriptiva,
                   COALESCE(ca.area_id, sa.area_id) AS area
            FROM calificaciones cal
            JOIN cargas_academicas ca ON ca.id = cal.carga_id
            LEFT JOIN subareas sa ON sa.id = ca.subarea_id
            JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id
                 AND bc.competencia_id = cal.competencia_id AND bc.periodo_id = cal.periodo_id
            WHERE cal.matricula_id = ? AND cal.periodo_id = ?");
        $st->execute([$ope, $p]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            // Cargas de la sección oficial, de la misma área, con esa competencia bloqueada.
            $cargasOfi = $col("
                SELECT ca.id FROM cargas_academicas ca
                LEFT JOIN subareas sa ON sa.id = ca.subarea_id
                JOIN bloqueos_competencia bc ON bc.carga_id = ca.id AND bc.competencia_id = ? AND bc.periodo_id = ?
                WHERE ca.seccion_id = ? AND COALESCE(ca.area_id, sa.area_id) = ?",
                [(int) $c['competencia_id'], $p, $s, (int) $c['area']]);
            if ($cargasOfi === []) {
                $malR[] = "comp {$c['competencia_id']} per $p: sin columna en la sección oficial (escenario 6: debe ir en «Sin carga»)";
                continue;
            }
            foreach ($cargasOfi as $cid) {
                $res  = $cal->getResumenCompetencia($cid, (int) $c['competencia_id'], $p, false, true);
                $fila = null;
                foreach ($res['alumnos'] as $a) {
                    if ((int) $a['matricula_id'] === $ofi) { $fila = $a; }
                    if ((int) $a['matricula_id'] === $ope) { $malR[] = "la operativa $ope sale en la carga $cid"; }
                }
                $logros++;
                if ($fila === null) {
                    $malR[] = "oficial $ofi falta en carga $cid comp {$c['competencia_id']} per $p";
                } elseif ((string) $fila['promedio'] !== (string) $c['nota_numerica']
                    || (string) $fila['conclusion_descriptiva'] !== (string) $c['conclusion_descriptiva']) {
                    $malR[] = "carga $cid comp {$c['competencia_id']} per $p: fila {$fila['promedio']} vs operativa {$c['nota_numerica']}";
                } elseif (($fila['cursado_en'] ?? null) !== $secOpe || array_filter($fila['notas_criterios'], static fn($n) => $n !== null) !== []) {
                    $malR[] = "carga $cid comp {$c['competencia_id']} per $p: criterios sin fundir o sin «$secOpe»";
                }
            }
        }
        // Conducta (nota del tutor y respuestas oficiales) y asistencia confirmada.
        $ccOpe = $one("SELECT nota_tutor, literal FROM calificaciones_conducta WHERE matricula_id = ? AND periodo_id = ?", [$ope, $p]);
        $resp  = (int) ($one("SELECT COUNT(*) n FROM conducta_respuestas r JOIN conducta_confirmaciones c
                              ON c.matricula_id = r.matricula_id AND c.periodo_id = r.periodo_id
                              WHERE r.matricula_id = ? AND r.periodo_id = ?", [$ope, $p])['n'] ?? 0);
        $tut = array_values(array_filter($con->getEstudiantesParaTutor($s, $p, 10, true), static fn($a) => (int) $a['matricula_id'] === $ofi))[0] ?? null;
        if ($tut === null || (string) $tut['nota_tutor'] !== (string) ($ccOpe['nota_tutor'] ?? '') || (int) $tut['respondidos'] !== $resp) {
            $malR[] = "conducta per $p: fila " . json_encode([$tut['nota_tutor'] ?? null, $tut['respondidos'] ?? null]) . " vs operativa " . json_encode([$ccOpe['nota_tutor'] ?? null, $resp]);
        }
        $inOpe = $one("SELECT faltas, tardanzas FROM inasistencias i WHERE matricula_id = ? AND periodo_id = ? AND "
                      . App\Models\AsistenciaModel::sqlConfirmada('i'), [$ope, $p]);
        $aRow = array_values(array_filter($asi->getEstudiantesConIncidencias($s, $p, true, true), static fn($a) => (int) $a['matricula_id'] === $ofi))[0] ?? null;
        if ($aRow === null || $aRow['incidencias']['faltas'] !== (int) ($inOpe['faltas'] ?? 0)
            || $aRow['incidencias']['registrado'] !== ($inOpe !== null)) {
            $malR[] = "asistencia per $p: fila " . json_encode($aRow['incidencias'] ?? null) . " vs operativa " . json_encode($inOpe);
        }
        // Promedio transversal del tutor: el de la operativa.
        $ctOpe = [];
        foreach ($pdo->query("SELECT cal.competencia_id, ROUND(AVG(cal.nota_numerica)) pr FROM calificaciones cal
                              JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id AND bc.periodo_id = cal.periodo_id
                              JOIN competencias comp ON comp.id = cal.competencia_id
                              JOIN areas a ON a.id = comp.area_id AND a.tipo = 'transversal'
                              WHERE cal.matricula_id = $ope AND cal.periodo_id = $p GROUP BY cal.competencia_id") as $x) {
            $ctOpe[(int) $x['competencia_id']] = (int) $x['pr'];
        }
        ksort($ctOpe);
        $ctFila = $trm->getPromediosSeccion($s, $p, true)[$ofi] ?? [];
        ksort($ctFila);
        if ($ctFila !== $ctOpe) {
            $malR[] = "transversales per $p: fila " . json_encode($ctFila) . " vs operativa " . json_encode($ctOpe);
        }
    }
}
$chk(count($retornos) . " retorno(s): $logros logros del tramo en la fila oficial, con conducta, asistencia y transversales; "
    . ($logrosPropios ?? 0) . ' notas propias fuera del tramo, sin fundir',
    $malR === [] && ($retornos === [] || ($logros > 0 && ($logrosPropios ?? 0) > 0)), implode(' | ', array_slice($malR, 0, 6)));

// ── 10. Registro impreso: fila vacía + ANEXO con cada criterio ───────────
echo "=== 10. Registro impreso: fila vacía y anexo completo del retorno ===\n";
$regM   = new RegistroAuxiliarModel();
$malA   = [];
$notasA = 0;
foreach ($retornos as $r) {
    $ofi = (int) $r['matricula_oficial_id'];
    $ope = (int) $r['matricula_operativa_id'];
    foreach ($periodos as $p) {
        if (!$enTramo($r, $numeroDe($p))) {
            continue;
        }
        $s = (int) $pdo->query("SELECT " . CambioSeccionModel::sqlSeccionDelPeriodo('m', $p) . " FROM matriculas m WHERE m.id = $ofi")->fetchColumn();
        // Control A MANO: cada nota por criterio (ordinario, vivo) de la operativa en
        // competencias BLOQUEADAS del bimestre, y cada logro.
        $control = (int) $one("
            SELECT COUNT(*) n FROM calificaciones_criterio cc
            JOIN criterios cr ON cr.id = cc.criterio_id
            JOIN bloqueos_competencia bc ON bc.carga_id = cr.carga_id AND bc.competencia_id = cr.competencia_id AND bc.periodo_id = cr.periodo_id
            WHERE cc.matricula_id = ? AND cr.periodo_id = ? AND cr.eliminado_en IS NULL AND cr.extraordinario = 0 AND cc.nota IS NOT NULL",
            [$ope, $p])['n'];
        $controlLogros = (int) $one("
            SELECT COUNT(*) n FROM calificaciones cal
            JOIN bloqueos_competencia bc ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id AND bc.periodo_id = cal.periodo_id
            WHERE cal.matricula_id = ? AND cal.periodo_id = ? AND cal.nota_numerica IS NOT NULL", [$ope, $p])['n'];
        // El documento: las hojas del área completa, como ConsultaNotasController.
        $periodo = $regM->periodo($p);
        $porArea = [];
        foreach ($regM->cargasDeSeccion($s, $p) as $c) {
            $porArea[(int) $c['area_id']][] = $c;
        }
        $vistas = 0;
        $logrosA = 0;
        foreach ($porArea as $grupo) {
            $c0   = $grupo[0];
            $hoja = $regM->hoja([
                'area_nombre' => $c0['area_nombre'], 'area_tipo' => $c0['area_tipo'], 'grado_nombre' => $c0['grado_nombre'],
                'seccion_nombre' => $c0['seccion_nombre'], 'nivel_nombre' => $c0['nivel_nombre'],
                'nivel_codigo' => $c0['nivel_codigo'], 'es_aula' => !empty($c0['es_aula']),
            ], $grupo, $periodo, false);
            // Fila de la oficial VACÍA en la matriz y en la CD.
            foreach (array_merge($hoja['columnas'], $hoja['transversales']) as $col) {
                $fila = $col['alumnos'][$ofi] ?? null;
                if ($fila !== null && ($fila['promedio'] !== null || array_filter($fila['notas_criterios'], static fn($n) => $n !== null) !== []
                        || trim((string) ($fila['conclusion_descriptiva'] ?? '')) !== '')) {
                    $malA[] = "per $p {$c0['area_nombre']}: la fila oficial no va vacía";
                    break;
                }
            }
            foreach ($hoja['anexo_retorno'] as $est) {
                if ($est['matricula_id'] !== $ofi) {
                    $malA[] = "anexo de una matrícula que no es la oficial ($est[matricula_id])";
                    continue;
                }
                foreach ($est['cargas'] as $g) {
                    foreach ($g['competencias'] as $comp) {
                        $vistas += count(array_filter($comp['alumno']['notas_criterios'], static fn($n) => $n !== null));
                        $logrosA += $comp['alumno']['promedio'] !== null ? 1 : 0;
                    }
                }
            }
        }
        $notasA += $vistas;
        if ($vistas !== $control || $logrosA !== $controlLogros) {
            $malA[] = "per $p: anexo con $vistas notas por criterio y $logrosA logros; la operativa tiene $control y $controlLogros";
        }
        // Hoja de transversales de la sección (mismos modelos que
        // ConsultaNotasController::transversalesSeccion): fila vacía y, en el
        // anexo, el promedio que congeló el tutor de la sección operativa.
        $cierre = $trm->getCierreVigente($s, $p);
        if ($cierre !== null) {
            $nivelId = (int) $pdo->query("SELECT g.nivel_id FROM secciones s JOIN grados g ON g.id = s.grado_id WHERE s.id = $s")->fetchColumn();
            $ts = $regM->conAnexoRetornoTransversales([
                'cierre'       => $cierre,
                'alumnos'      => $con->getEstudiantesParaTutor($s, $p, 10, true),
                'promedios'    => $trm->getPromediosSeccion($s, $p, true),
                'conclusiones' => $trm->getConclusionesSeccion($s, $p, true),
                'competencias' => $trm->getCompetencias($nivelId),
            ], $p);
            $tsVistas = ($tsVistas ?? 0) + 1;
            $ax = $ts['anexo'][0] ?? null;   // un retorno por sección en este bimestre (se exige abajo)
            $opeSec = (int) $pdo->query("SELECT seccion_id FROM matriculas WHERE id = $ope")->fetchColumn();
            $esperado = $trm->getCierreVigente($opeSec, $p) !== null ? ($trm->getPromediosSeccion($opeSec, $p)[$ope] ?? []) : [];
            ksort($esperado);
            $obtenido = $ax['promedios'] ?? null;
            if (is_array($obtenido)) { ksort($obtenido); }
            if (isset($ts['promedios'][$ofi]) || isset($ts['conclusiones'][$ofi])) {
                $malA[] = "per $p: la fila oficial de la hoja de transversales no va vacía";
            } elseif (count($ts['anexo']) !== 1 || $obtenido !== $esperado) {
                $malA[] = "per $p: anexo de transversales " . json_encode($obtenido) . " vs tutor de la operativa " . json_encode($esperado);
            }
        }
    }
}
$chk(count($retornos) . " retorno(s): fila vacía y $notasA notas por criterio en el anexo, cada una UNA vez; "
    . ($tsVistas ?? 0) . ' hoja(s) de transversales con su anexo',
    $malA === [] && ($retornos === [] || $notasA > 0), implode(' | ', array_slice($malA, 0, 6)));

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
