<?php

/**
 * Verificación — CALIFICACIÓN EXTRAORDINARIA EN LOTE.
 * Uso: php database/verificaciones/verif_extraordinaria_lote.php
 *
 * ⚠️ ESTE SCRIPT ESCRIBE PARA PROBAR, y por eso lo hace SIEMPRE dentro de una
 * TRANSACCIÓN QUE TERMINA EN ROLLBACK: no deja ni una fila. Cumple la regla del
 * proyecto ("ningún script de database/ debe limpiar con DELETE lo que no creó").
 * Se puede correr en producción, pero conviene hacerlo fuera de horario de uso.
 *
 * QUÉ SE AÑADIÓ
 *   El alta de calificación extraordinaria (migración 042) era de UNA competencia
 *   por vez: un alumno matriculado después del cierre necesita 25-27 altas, o sea
 *   25-27 pasadas por el formulario. Se añadió una GRILLA que captura el bimestre
 *   entero con un motivo común. El MOTOR no cambió: las dos vías escriben por el
 *   mismo punto único (RectificacionController::escribirExtraordinaria).
 *
 * QUÉ COMPRUEBA
 *   1. UNIVERSO: qué matrículas tienen competencias insertables y cuántas.
 *   2. LOTE: escribir N competencias deja exactamente N filas en `calificaciones`
 *      con extraordinaria=1 y N en `rectificaciones_calificacion`.
 *   3. GUARDA: tras escribir, esas mismas tuplas dejan de ser insertables (no se
 *      pueden duplicar reenviando el formulario).
 *   4. MÉRITO: el ranking del grado en ese bimestre NO cambia ni una posición.
 *      Es el riesgo real: son notas de un bimestre publicado y bajo candado 046.
 *   5. BOLETA: gana exactamente las filas nuevas y ninguna existente se altera.
 *   6. ROLLBACK: al terminar, los contadores vuelven a su valor inicial.
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
require_once APP_PATH . '/Helpers/helpers.php';

$pdo     = Core\Database::connect();
$rectMod = new App\Models\RectificacionModel();
$calMod  = new App\Models\CalificacionModel();
$critMod = new App\Models\CriterioModel();
$meritos = new App\Models\OrdenMeritoModel();
$boletas = new App\Models\BoletaModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};

echo "\n=== 1. UNIVERSO — matrículas con competencias insertables ===\n";

$anio = (int) $pdo->query("SELECT id FROM anios_academicos WHERE estado='activo' LIMIT 1")->fetchColumn();
$candidatas = $pdo->query("
    SELECT m.id, CONCAT(per.apellido_paterno, ' ', per.nombres) AS alumno,
           per.dni, g.nombre_display AS grado, s.nombre AS seccion, s.grado_id
    FROM matriculas m
    JOIN estudiantes e ON e.id = m.estudiante_id
    JOIN personas per  ON per.id = e.persona_id
    JOIN secciones s   ON s.id = m.seccion_id
    JOIN grados g      ON g.id = s.grado_id
    WHERE m.anio_id = {$anio}
      AND NOT EXISTS (SELECT 1 FROM calificaciones c
                       WHERE c.matricula_id = m.id AND c.periodo_id = 1)
    ORDER BY m.id
")->fetchAll(PDO::FETCH_ASSOC);

if ($candidatas === []) {
    echo "  No hay matrículas sin notas en el I Bimestre. Nada que verificar.\n";
    exit(0);
}
foreach ($candidatas as $c) {
    $ins = 0;
    foreach ($rectMod->getCompetenciasInsertables((int) $c['id']) as $fila) {
        if ((int) $fila['periodo_id'] === 1) { $ins++; }
    }
    printf("  matrícula %-5s %-38s %s %s · insertables en B1: %d\n",
        $c['id'], $c['alumno'], $c['grado'], $c['seccion'], $ins);
}

// Sujeto de prueba: la primera candidata con competencias insertables en B1.
$sujeto = null;
foreach ($candidatas as $c) {
    $items = array_values(array_filter(
        $rectMod->getCompetenciasInsertables((int) $c['id']),
        static fn (array $f): bool => (int) $f['periodo_id'] === 1
    ));
    if ($items !== []) { $sujeto = $c; $sujeto['items'] = $items; break; }
}
if ($sujeto === null) {
    echo "  Ninguna candidata tiene competencias insertables. Nada que verificar.\n";
    exit(0);
}

$mid      = (int) $sujeto['id'];
$gradoId  = (int) $sujeto['grado_id'];
$periodo  = 1;
$items    = $sujeto['items'];
$n        = count($items);
$usuario  = (int) $pdo->query("SELECT id FROM usuarios WHERE rol_id = 1 LIMIT 1")->fetchColumn();

echo "\n  Sujeto de prueba: matrícula {$mid} ({$sujeto['alumno']}), {$n} competencias en B1.\n";

// ── Estado ANTES ────────────────────────────────────────────────
$antes = [
    'calificaciones'  => $contar("SELECT COUNT(*) FROM calificaciones WHERE matricula_id = ? AND periodo_id = ?", [$mid, $periodo]),
    'extraordinarias' => $contar("SELECT COUNT(*) FROM calificaciones WHERE extraordinaria = 1"),
    'rectificaciones' => $contar("SELECT COUNT(*) FROM rectificaciones_calificacion WHERE tipo = 'extraordinaria'"),
    'criterios'       => $contar("SELECT COUNT(*) FROM criterios WHERE extraordinario = 1"),
];
$rankingAntes = $meritos->rankingGrado($gradoId, $periodo);
$boletaAntes  = $boletas->armar($mid, $periodo, 'archivo', true);

echo "\n=== 2. LOTE — se escriben las {$n} competencias en UNA transacción ===\n";

$pdo->beginTransaction();
try {
    foreach ($items as $c) {
        $cargaId       = (int) $c['carga_id'];
        $competenciaId = (int) $c['competencia_id'];
        $nota          = 15;                       // literal A en la escala vigente
        $conclusion    = 'Verificación automática (se revierte).';

        $criterioId = $critMod->obtenerOCrearExtraordinario($cargaId, $competenciaId, $periodo, $usuario);
        if ($criterioId <= 0) { throw new RuntimeException('sin criterio extraordinario'); }

        $calMod->guardarNotaCriterio($criterioId, $mid, $nota);
        $promedio = $calMod->calcularPromedio($mid, $cargaId, $competenciaId, $periodo);
        if ($promedio === null) { throw new RuntimeException('sin promedio'); }
        $notaFinal = (int) round($promedio);

        $calMod->guardarNotaFinal($mid, $cargaId, $periodo, $competenciaId, $notaFinal, $usuario);
        $calMod->marcarCalificacionExtraordinaria($mid, $cargaId, $competenciaId, $periodo);
        $calMod->actualizarConclusion($mid, $cargaId, $competenciaId, $periodo, $conclusion);

        $rectMod->registrar([
            'matricula_id'        => $mid,
            'carga_id'            => $cargaId,
            'periodo_id'          => $periodo,
            'competencia_id'      => $competenciaId,
            'tipo'                => 'extraordinaria',
            'nota_anterior'       => null,
            'nota_nueva'          => $notaFinal,
            'conclusion_anterior' => null,
            'conclusion_nueva'    => $conclusion,
            'motivo'              => 'Verificación automática del lote (se revierte).',
            'rectificado_por'     => $usuario,
        ]);
    }

    $despues = [
        'calificaciones'  => $contar("SELECT COUNT(*) FROM calificaciones WHERE matricula_id = ? AND periodo_id = ?", [$mid, $periodo]),
        'extraordinarias' => $contar("SELECT COUNT(*) FROM calificaciones WHERE extraordinaria = 1"),
        'rectificaciones' => $contar("SELECT COUNT(*) FROM rectificaciones_calificacion WHERE tipo = 'extraordinaria'"),
    ];

    $ok($despues['calificaciones'] === $antes['calificaciones'] + $n,
        "calificaciones del alumno en B1: {$antes['calificaciones']} → {$despues['calificaciones']} (esperado +{$n})");
    $ok($despues['extraordinarias'] === $antes['extraordinarias'] + $n,
        "marcadas extraordinaria=1: {$antes['extraordinarias']} → {$despues['extraordinarias']} (esperado +{$n})");
    $ok($despues['rectificaciones'] === $antes['rectificaciones'] + $n,
        "auditoría tipo='extraordinaria': {$antes['rectificaciones']} → {$despues['rectificaciones']} (esperado +{$n})");

    echo "\n=== 3. GUARDA — las tuplas escritas ya no son insertables ===\n";
    $siguenInsertables = 0;
    foreach ($items as $c) {
        if ($rectMod->esInsertable($mid, (int) $c['carga_id'], (int) $c['competencia_id'], $periodo)) {
            $siguenInsertables++;
        }
    }
    $ok($siguenInsertables === 0,
        "competencias que seguirían admitiendo alta tras escribirlas: {$siguenInsertables} (esperado 0)");

    echo "\n=== 4. MÉRITO — el ranking del bimestre no se mueve ===\n";
    $rankingDespues = $meritos->rankingGrado($gradoId, $periodo);
    $difieren = 0;
    $mapaAntes = [];
    foreach ($rankingAntes as $f) { $mapaAntes[(int) $f['matricula_id']] = $f['puesto'] ?? null; }
    foreach ($rankingDespues as $f) {
        $id = (int) $f['matricula_id'];
        if (!array_key_exists($id, $mapaAntes) || $mapaAntes[$id] !== ($f['puesto'] ?? null)) {
            $difieren++;
        }
    }
    $ok(count($rankingAntes) === count($rankingDespues),
        "filas del ranking: " . count($rankingAntes) . " → " . count($rankingDespues));
    $ok($difieren === 0, "puestos que cambian de posición: {$difieren} (esperado 0)");

    echo "\n=== 5. BOLETA — gana las filas nuevas y no altera ninguna previa ===\n";
    $boletaDespues = $boletas->armar($mid, $periodo, 'archivo', true);

    // Estructura real de armar(): areas[<nombre de área>][<competencia_id>]
    //                             ['bimestres'][<periodo>]['nota'|'literal'].
    // Una competencia SIN celda en ese bimestre no aparece en 'bimestres'.
    $celdas = static function (?array $b) use ($periodo): array {
        $out = [];
        foreach ($b['areas'] ?? [] as $areaNombre => $competencias) {
            foreach ($competencias as $compId => $comp) {
                $celda = $comp['bimestres'][$periodo] ?? null;
                $out[$areaNombre . '#' . $compId] = $celda === null
                    ? null
                    : (($celda['nota'] ?? '-') . '|' . ($celda['literal'] ?? '-'));
            }
        }
        return $out;
    };
    $cAntes   = $celdas($boletaAntes);
    $cDespues = $celdas($boletaDespues);

    $conDatoAntes   = count(array_filter($cAntes,   static fn ($v) => $v !== null));
    $conDatoDespues = count(array_filter($cDespues, static fn ($v) => $v !== null));

    // Ninguna celda que YA tenía dato puede cambiar de valor.
    $alteradas = 0;
    foreach ($cAntes as $k => $v) {
        if ($v !== null && array_key_exists($k, $cDespues) && $cDespues[$k] !== $v) {
            $alteradas++;
        }
    }
    $ok($alteradas === 0, "celdas de B1 con dato previo que cambian: {$alteradas} (esperado 0)");
    $ok($conDatoDespues === $conDatoAntes + $n,
        "celdas con dato en B1: {$conDatoAntes} → {$conDatoDespues} (esperado +{$n})");

    // ── 5b. COMPETENCIAS TRANSVERSALES ───────────────────────────
    //
    // Van por un camino propio (carga DUEÑA derivada del dato + cierre del
    // tutor vigente) y su CONCLUSIÓN vive en `conclusiones_transversales`, no
    // en `calificaciones`. Guardarla en el sitio equivocado la dejaría
    // registrada e INVISIBLE, así que eso es lo que más se comprueba aquí.
    echo "\n=== 5b. TRANSVERSALES — carga dueña y conclusión en su tabla ===\n";

    $transMod = new App\Models\TransversalModel();
    $trans = array_values(array_filter(
        $rectMod->getTransversalesInsertables($mid),
        static fn (array $r): bool => (int) $r['periodo_id'] === $periodo
    ));

    if ($trans === []) {
        echo "  (sin transversales insertables para este sujeto; bloque omitido)\n";
    } else {
        $t0    = $trans[0];
        $cid   = (int) $t0['competencia_id'];
        $cargaT = (int) $t0['carga_id'];

        $ok($cargaT > 0, "la carga dueña se derivó del dato (carga {$cargaT}), no se eligió a dedo");
        $ok($rectMod->esInsertableTransversal($mid, $cargaT, $cid, $periodo) === true,
            'con la carga dueña -> admitida');
        $ok($rectMod->esInsertableTransversal($mid, 99999, $cid, $periodo) === false,
            'con una carga ajena -> RECHAZADA');
        $ok($rectMod->esInsertable($mid, $cargaT, $cid, $periodo) === false,
            'la guarda ORDINARIA sigue excluyendo transversales (la 042 no se derogó)');

        $conclAntes = $contar(
            "SELECT COUNT(*) FROM conclusiones_transversales WHERE matricula_id = ? AND periodo_id = ?",
            [$mid, $periodo]
        );

        // Nota 12 = literal B: en primaria EXIGE conclusión, que es el caso interesante.
        $notaT  = 12;
        $textoT = 'Conclusión de verificación (se revierte).';
        $critT  = $critMod->obtenerOCrearExtraordinario($cargaT, $cid, $periodo, $usuario);
        $calMod->guardarNotaCriterio($critT, $mid, $notaT);
        $promT  = $calMod->calcularPromedio($mid, $cargaT, $cid, $periodo);
        $calMod->guardarNotaFinal($mid, $cargaT, $periodo, $cid, (int) round($promT), $usuario);
        $calMod->marcarCalificacionExtraordinaria($mid, $cargaT, $cid, $periodo);
        $transMod->guardarConclusion($mid, $cid, $periodo, $textoT, $usuario);

        $ok($contar("SELECT COUNT(*) FROM conclusiones_transversales WHERE matricula_id = ? AND periodo_id = ?", [$mid, $periodo])
                === $conclAntes + 1,
            'la conclusión entró en conclusiones_transversales');

        $boletaT = $boletas->armar($mid, $periodo, 'archivo', true);
        $celdaT  = null;
        foreach ($boletaT['areas'] as $comps) {
            if (isset($comps[$cid])) { $celdaT = $comps[$cid]['bimestres'][$periodo] ?? null; }
        }
        $ok($celdaT !== null, 'la transversal YA tiene celda en la boleta');
        if ($celdaT !== null) {
            $ok((int) $celdaT['nota'] === $notaT, "la nota de la boleta es {$notaT}");
            $ok(trim((string) $celdaT['conclusion']) === $textoT,
                'la conclusión SALE en la boleta (si esto falla, quedó registrada e invisible)');
        }

        $restantes = array_filter(
            $rectMod->getTransversalesInsertables($mid),
            static fn (array $r): bool => (int) $r['periodo_id'] === $periodo
        );
        $ok(count($restantes) === count($trans) - 1,
            'la registrada deja de ofrecerse (' . count($restantes) . ' de ' . count($trans) . ')');
    }

} catch (Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $fallos++;
} finally {
    $pdo->rollBack();
}

echo "\n=== 6. ROLLBACK — la base vuelve a su estado inicial ===\n";
$final = [
    'calificaciones'  => $contar("SELECT COUNT(*) FROM calificaciones WHERE matricula_id = ? AND periodo_id = ?", [$mid, $periodo]),
    'extraordinarias' => $contar("SELECT COUNT(*) FROM calificaciones WHERE extraordinaria = 1"),
    'rectificaciones' => $contar("SELECT COUNT(*) FROM rectificaciones_calificacion WHERE tipo = 'extraordinaria'"),
    'criterios'       => $contar("SELECT COUNT(*) FROM criterios WHERE extraordinario = 1"),
];
foreach ($antes as $k => $v) {
    $ok($final[$k] === $v, "{$k}: {$v} → {$final[$k]} (debe volver a {$v})");
}

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — el lote escribe lo esperado y no mueve mérito ni boleta.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
