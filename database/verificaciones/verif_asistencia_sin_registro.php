<?php

/**
 * Verificación — la asistencia de un bimestre SIN REGISTRO sale en guion, no en 0.
 * Uso: php database/verificaciones/verif_asistencia_sin_registro.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN (por eso NO lleva el guard de secretos que sí tienen los que escriben).
 *
 * QUÉ CORRIGE (F1 del registro retroactivo, 07/08/2026)
 *   `AsistenciaModel::getDelBimestre` devuelve los 4 contadores en CERO cuando no
 *   hay fila en `inasistencias`, y `armar()` no distinguía ese caso del cero real.
 *   Resultado: la boleta de un alumno que llegó DESPUÉS de un bimestre afirmaba
 *   "0 faltas" de un bimestre que no cursó. Es un dato FALSO, no ausente.
 *
 * QUÉ COMPRUEBA
 *   1. UNIVERSO: qué pares (matrícula, bimestre) cambian de "0" a guion, y que
 *      todos tengan motivo — o no cursó (sin notas), o nadie le registró.
 *   2. NO-REGRESIÓN: quien tiene fila con los 4 contadores en CERO conserva su 0.
 *      Es la distinción que da sentido al cambio; si esto falla, el cambio está mal.
 *   3. RETORNO DE GRADO: cada bimestre se lee de la matrícula que lo CURSÓ (tramo,
 *      migración 073, 05/10/2026). La boleta de la oficial sale en guion ⟺ esa
 *      matrícula no tiene fila VISIBLE (confirmada y con cierre vigente) en el
 *      bimestre. Hasta esa fecha la pregunta iba por unión de las dos matrículas
 *      y el aserto contaba cualquier fila, también un borrador.
 *   4. TOTAL ANUAL: no se mueve. Las columnas que pasan a guion aportaban 0.
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

$pdo      = Core\Database::connect();
$boletas  = new App\Models\BoletaModel();
$asisMod  = new App\Models\AsistenciaModel();
$fallos   = 0;

/** Devuelve el bloque de asistencia de una boleta indexado por numero de bimestre. */
$bloque = function (int $matriculaId, int $periodoId, string $modo) use ($boletas): array {
    $d = $boletas->armar($matriculaId, $periodoId, $modo, true);
    $out = [];
    foreach (($d['asistencia']['bimestres'] ?? []) as $b) {
        $out[(int) $b['numero']] = $b;
    }
    return $out;
};

$ok = function (bool $cond, string $msg) use (&$fallos): void {
    echo($cond ? "  OK    " : "  FALLO ");
    echo $msg . "\n";
    if (!$cond) { $fallos++; }
};

$anioId = (int) $pdo->query(
    "SELECT id FROM anios_academicos WHERE estado = 'activo' ORDER BY anio DESC LIMIT 1"
)->fetchColumn();

// El bimestre de referencia para armar: el ultimo cerrado (o el activo si no hay).
$verPeriodo = (int) $pdo->query("
    SELECT id FROM periodos WHERE anio_id = {$anioId}
    ORDER BY (estado = 'cerrado') DESC, numero DESC LIMIT 1
")->fetchColumn();

echo "=== Verificacion: asistencia sin registro -> guion ===\n";
echo "Anio {$anioId} · boleta armada sobre el periodo {$verPeriodo} · umbral 'todos'\n";

// ─────────────────────────────────────────────────────────── 1. UNIVERSO
echo "\n--- 1. UNIVERSO DEL CAMBIO: pares sin fila de asistencia ---\n";

$sinFila = $pdo->query("
    SELECT m.id AS matricula, p.id AS periodo, p.numero, p.nombre_display AS bimestre,
           m.tipo, m.estado,
           CONCAT(pe.apellido_paterno, ' ', pe.nombres) AS estudiante,
           COALESCE(c.n, 0) AS notas
    FROM matriculas m
    CROSS JOIN periodos p
    LEFT JOIN inasistencias i ON i.matricula_id = m.id AND i.periodo_id = p.id
    LEFT JOIN (SELECT matricula_id, periodo_id, COUNT(*) n FROM calificaciones
               GROUP BY matricula_id, periodo_id) c
           ON c.matricula_id = m.id AND c.periodo_id = p.id
    JOIN estudiantes e ON e.id = m.estudiante_id
    JOIN personas   pe ON pe.id = e.persona_id
    WHERE m.anio_id = {$anioId} AND p.anio_id = {$anioId}
      AND i.matricula_id IS NULL
      AND p.estado IN ('cerrado', 'activo')
    ORDER BY p.numero, m.id
")->fetchAll(PDO::FETCH_ASSOC);

echo "  Pares (matricula, bimestre) sin fila en bimestres cerrados/activos: "
   . count($sinFila) . "\n";
foreach ($sinFila as $f) {
    printf("    B%d  m=%-4d %-34s %-12s notas=%d\n",
        $f['numero'], $f['matricula'], mb_substr($f['estudiante'], 0, 34), $f['tipo'], $f['notas']);
}

// ────────────────────────────────────────────────── 2. NO-REGRESION (cero real)
echo "\n--- 2. NO-REGRESION: fila con los 4 contadores en CERO conserva su 0 ---\n";

$cerosReales = $pdo->query("
    SELECT i.matricula_id, i.periodo_id, p.numero
    FROM inasistencias i
    JOIN matriculas m ON m.id = i.matricula_id
    JOIN periodos   p ON p.id = i.periodo_id
    WHERE m.anio_id = {$anioId}
      AND i.faltas = 0 AND i.faltas_justificadas = 0
      AND i.tardanzas = 0 AND i.tardanzas_justificadas = 0
    ORDER BY i.periodo_id, i.matricula_id
    LIMIT 40
")->fetchAll(PDO::FETCH_ASSOC);

echo "  Muestra de filas 'registrado sin incidencias': " . count($cerosReales) . "\n";
$malCero = 0;
foreach ($cerosReales as $c) {
    $b = $bloque((int) $c['matricula_id'], $verPeriodo, 'todos');
    $col = $b[(int) $c['numero']] ?? null;
    if ($col === null || !empty($col['sin_registro'])) {
        $malCero++;
        printf("    FALLO m=%d B%d salio en guion teniendo fila en cero\n",
            $c['matricula_id'], $c['numero']);
    }
}
$ok($malCero === 0, "ninguna fila en cero real se convirtio en guion ({$malCero} fallos)");

// ─────────────────────────────────────────────── 3. RETORNO DE GRADO (union)
echo "\n--- 3. RETORNO DE GRADO: cada bimestre, de la matricula que lo curso ---\n";

// Tramo escrito a mano (no por RetornoGradoModel): el control no se apoya en el
// codigo que vigila.
$retornos = $pdo->query("
    SELECT r.matricula_oficial_id AS oficial, r.matricula_operativa_id AS operativa,
           r.estado, pd.numero AS desde, ph.numero AS hasta
    FROM retornos_grado r
    JOIN periodos pd ON pd.id = r.periodo_desde_id
    LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
    ORDER BY r.id
")->fetchAll(PDO::FETCH_ASSOC);
// Fila VISIBLE = confirmada y con cierre de asistencia vigente en su seccion.
$visible = static function (int $mid, int $pid) use ($pdo): bool {
    return (bool) $pdo->query("SELECT 1 FROM inasistencias i
        JOIN matriculas mv ON mv.id = i.matricula_id
        JOIN cierres_asistencia z ON z.seccion_id = mv.seccion_id
                                 AND z.periodo_id = i.periodo_id AND z.anulado_en IS NULL
        WHERE i.matricula_id = {$mid} AND i.periodo_id = {$pid}
          AND i.confirmado_en IS NOT NULL LIMIT 1")->fetchColumn();
};

if (!$retornos) {
    echo "  (no hay retornos registrados; bloque no aplica)\n";
}
foreach ($retornos as $r) {
    $of = (int) $r['oficial'];
    $op = (int) $r['operativa'];
    echo "  retorno oficial={$of} operativa={$op}\n";

    foreach ($pdo->query("SELECT id, numero FROM periodos WHERE anio_id = {$anioId}
                          AND estado IN ('cerrado','activo') ORDER BY numero") as $p) {
        $pid = (int) $p['id'];
        $num = (int) $p['numero'];

        $cubre   = $num >= (int) $r['desde']
                && ($r['estado'] === 'activo' || ($r['hasta'] !== null && $num <= (int) $r['hasta']));
        $cursada = $cubre ? $op : $of;
        $hay     = $visible($cursada, $pid);

        $reg = $asisMod->tieneRegistroUnion([$cursada], $pid);
        $ok($reg === $hay,
            "B{$num}: curso en " . ($cubre ? "la operativa {$op}" : "la oficial {$of}")
            . ", fila visible=" . var_export($hay, true) . " (modelo: " . var_export($reg, true) . ")");

        // La boleta se rotula con la OFICIAL y lee la columna de la que curso:
        // guion <=> sin fila visible. Las DOS ramas.
        $b = $bloque($of, $verPeriodo, 'todos');
        $col = $b[$num] ?? null;
        if ($col !== null) {
            $ok(empty($col['sin_registro']) === $hay,
                "B{$num}: la boleta de la oficial {$of} " . ($hay ? 'NO sale' : 'sale') . " en guion");
        }
    }
}

// ──────────────────────────────────────────────────────── 4. TOTAL ANUAL
echo "\n--- 4. EL TOTAL ANUAL NO SE MUEVE ---\n";
echo "  (las columnas que pasan a guion aportaban 0, asi que el total es identico)\n";

$muestra = $pdo->query("
    SELECT DISTINCT m.id FROM matriculas m
    JOIN inasistencias i ON i.matricula_id = m.id
    WHERE m.anio_id = {$anioId} ORDER BY m.id LIMIT 25
")->fetchAll(PDO::FETCH_COLUMN);

$totalMal = 0;
foreach ($muestra as $mid) {
    $d = $boletas->armar((int) $mid, $verPeriodo, 'todos', true);
    $tot = $d['asistencia']['total'] ?? null;
    if ($tot === null) { continue; }

    $suma = ['faltas' => 0, 'faltas_justificadas' => 0, 'tardanzas' => 0, 'tardanzas_justificadas' => 0];
    foreach (($d['asistencia']['bimestres'] ?? []) as $b) {
        if (!empty($b['sin_registro'])) { continue; }
        foreach ($suma as $k => $_) { $suma[$k] += (int) $b['datos'][$k]; }
    }
    foreach ($suma as $k => $v) {
        if ((int) $tot[$k] !== $v) {
            $totalMal++;
            printf("    FALLO m=%d %s: total=%d suma_columnas=%d\n", $mid, $k, $tot[$k], $v);
        }
    }
}
$ok($totalMal === 0, "el total coincide con la suma de columnas con registro ({$totalMal} fallos)");

echo "\n=== RESULTADO: " . ($fallos === 0 ? "TODO OK" : "{$fallos} FALLO(S)") . " ===\n";
exit($fallos === 0 ? 0 : 1);
