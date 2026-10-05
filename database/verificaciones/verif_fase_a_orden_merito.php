<?php

/**
 * Verificación — ROSTER del orden de mérito: quién compite y quién no.
 * SOLO LECTURA. Uso: php database/verificaciones/verif_fase_a_orden_merito.php
 *
 * > Nació el 24/07/2026 para la Fase A del rediseño, que cambió el criterio de
 * > `estado='aprobada'` a `tipo NOT IN ('trasladado','retirado')`. El 12/08/2026
 * > el usuario DEROGÓ esa mitad: al orden de mérito solo entran las matrículas
 * > APROBADAS. El filtro por `tipo` se conserva, así que los casos de traslado,
 * > retiro y anclaje de retorno siguen siendo los mismos. Punto único de la
 * > regla: `OrdenMeritoModel::ROSTER_MERITO`.
 *
 * Comprueba, sobre el dataset de referencia:
 *  - `pendiente` y `desactivado` quedan FUERA del ranking,
 *  - `aprobada` entra,
 *  - `trasladado` / `retirado` quedan FUERA,
 *  - el retorno de grado sigue anclado (operativa compite, oficial excluida),
 *  - B1 no tiene empates pendientes con el roster vigente.
 *
 * Los casos de control de B1 van por ID (dataset de referencia); los de estado se
 * DERIVAN de la base, porque qué matrícula está pendiente cambia cada semana.
 *
 * OJO — lee el cálculo EN VIVO (`rankingGradoLive`, por reflexión), NO el wrapper
 * snapshot-aware `rankingGrado`. Lo que aquí se verifica es el FILTRO del roster,
 * y B1 congeló un documento con la regla ESPECIAL de la Fase C (roster SIN filtro
 * de tipo, 528 filas): leído desde el snapshot, un `trasladado` SÍ aparece y las
 * aserciones de abajo dirían lo contrario de lo que prueban. Mismo motivo por el
 * que `gradosConEmpatesPendientes` usa el vivo.
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
date_default_timezone_set(config('timezone'));

$m = new \App\Models\AnioAcademicoModel();
$o = new \App\Models\OrdenMeritoModel();

// Ranking EN VIVO (ver cabecera): el snapshot de B1 usa la regla especial Fase C.
$live = new ReflectionMethod(\App\Models\OrdenMeritoModel::class, 'rankingGradoLive');
$live->setAccessible(true);

$fallos = 0;
$evaluar = function (int $mat, int $periodoId, bool $debeEstar, string $etiqueta)
        use ($m, $o, $live, &$fallos): void {
    $g = $m->query("
        SELECT g.id FROM matriculas mm
        JOIN secciones s ON s.id = mm.seccion_id
        JOIN grados g    ON g.id = s.grado_id
        WHERE mm.id = ?", [$mat]);
    $gradoId = (int) ($g[0]['id'] ?? 0);

    $enRanking = false; $puesto = null;
    foreach ($live->invoke($o, $gradoId, $periodoId) as $f) {
        if ((int) $f['matricula_id'] === $mat) { $enRanking = true; $puesto = $f['puesto']; }
    }

    $bien = ($enRanking === $debeEstar);
    if (!$bien) { $fallos++; }
    printf("  %-5s mat=%-4s grado=%-3s => %-10s (puesto %-4s) esperado: %s\n",
        $bien ? 'OK' : '***', $mat, $gradoId,
        $enRanking ? 'EN RANKING' : 'excluida', $puesto ?? '-', $etiqueta);
};

// ── Casos de control de B1, por ID (dataset de referencia) ──────────────────
echo "=== Roster del mérito — casos de control (PERIODO 1 / B1) ===\n";
$evaluar(541, 1, false, 'FUERA (retirado)');
$evaluar(308, 1, false, 'FUERA (trasladado)');
// Retorno #1 (tramo II–II, migración 073): el I Bimestre lo CURSÓ en la oficial
// (el retorno es del 21/06). Hasta el 05/10/2026 este caso esperaba lo contrario
// porque el anclaje se DEDUCÍA de «tener notas», y la operativa arrastra 22
// promedios del I copiados por el antiguo INSERT IGNORE. El snapshot publicado
// del I (que la ubica en 1.°) es inmutable y no se toca: esto es el cálculo en vivo.
$evaluar(692, 1, false, 'FUERA (retorno: el I esta fuera del tramo de la operativa)');
$evaluar(190, 1, true,  'DENTRO (retorno, oficial: curso el I en su grado)');
$evaluar(692, 2, true,  'DENTRO (retorno: el II esta en el tramo de la operativa)');

// ── Casos de ESTADO, derivados de la base ───────────────────────────────────
// Se busca, en cada periodo con notas bloqueadas, una matrícula por estado.
// `pendiente` y `desactivado` deben quedar fuera; `aprobada`, dentro.
echo "\n=== Roster del mérito — por ESTADO de matrícula (regla del 12/08/2026) ===\n";
$periodos = $m->query("
    SELECT DISTINCT cal.periodo_id AS id, p.nombre_display
    FROM calificaciones cal
    INNER JOIN periodos p ON p.id = cal.periodo_id
    INNER JOIN bloqueos_competencia bc
            ON bc.carga_id       = cal.carga_id
           AND bc.competencia_id = cal.competencia_id
           AND bc.periodo_id     = cal.periodo_id
    ORDER BY p.numero");

$vistos = 0;
foreach ($periodos as $per) {
    $periodoId = (int) $per['id'];
    foreach (['pendiente' => false, 'desactivado' => false, 'aprobada' => true] as $estado => $debeEstar) {
        // Una matrícula de ese estado, con notas BLOQUEADAS en el periodo y que
        // no sea la operativa de un retorno revertido (esa es la excepción y
        // tiene su propia prueba en verif_roster_merito_estado.php).
        $cand = $m->query("
            SELECT m2.id
            FROM matriculas m2
            INNER JOIN calificaciones cal ON cal.matricula_id = m2.id AND cal.periodo_id = ?
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id       = cal.carga_id
                   AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id     = cal.periodo_id
            WHERE m2.estado = ?
              AND m2.tipo IN ('continuador','nuevo')
              AND m2.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
              AND m2.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')
            LIMIT 1", [$periodoId, $estado]);

        if (!$cand) { continue; }
        $vistos++;
        printf("  [%s]\n", $per['nombre_display'] . ' · ' . $estado);
        $evaluar((int) $cand[0]['id'], $periodoId, $debeEstar,
            ($debeEstar ? 'DENTRO' : 'FUERA') . ' (' . $estado . ')');
    }
}
if ($vistos === 0) {
    echo "  (sin matrículas que cubran los tres estados: nada que comprobar)\n";
}

echo "\n=== Empates pendientes de B1 con el roster vigente ===\n";
$emp = $o->gradosConEmpatesPendientes(1);
echo empty($emp) ? "  OK    SIN empates pendientes\n"
                  : ("  ***   PENDIENTES: " . implode(' | ', array_unique($emp)) . "\n");
if (!empty($emp)) { $fallos++; }

echo "\n", str_repeat('-', 72), "\n";
echo $fallos === 0 ? "RESULTADO: OK\n" : "RESULTADO: {$fallos} FALLO(S)\n";
exit($fallos === 0 ? 0 : 1);
