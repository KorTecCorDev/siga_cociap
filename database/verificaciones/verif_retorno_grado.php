<?php

/**
 * Verificación — RETORNO DE GRADO: la boleta se emite con la matrícula OFICIAL.
 * Uso: php database/verificaciones/verif_retorno_grado.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN (por eso NO lleva el guard de secretos que sí tienen los que escriben).
 *
 * CONTEXTO (05/08/2026). Un retorno de grado da al estudiante DOS matrículas del
 * mismo año: la OFICIAL (grado SIAGIE) y la OPERATIVA (grado donde asiste). La
 * REGLA A del colegio dice:
 *   - las notas viven donde se registraron: antes del retorno en la oficial,
 *     desde el retorno en la operativa (no se copian ni se mueven);
 *   - la evaluación y el mérito ocurren en la matrícula del grado que cursa;
 *   - la BOLETA se emite SIEMPRE con la matrícula OFICIAL, uniendo ambas fuentes.
 *
 * `BoletaPublicaModel` no conocía el retorno, y como el retorno reparte las notas
 * por bimestre entre dos secciones, el lote de boletas salía mal de dos formas
 * opuestas según el bimestre:
 *   - B1 (notas en AMBAS): el estudiante aparecía DOS VECES (517 filas / 516 alumnos).
 *   - B2 (notas solo en la operativa): DESAPARECÍA de su sección oficial.
 *
 * QUÉ COMPRUEBA
 *   1. EQUIVALENCIA: para cada periodo, el listado nuevo y el viejo coinciden
 *      EXCEPTO en las matrículas de retorno. Es la prueba de no-regresión: el
 *      cambio no puede mover a nadie más.
 *   2. UNICIDAD: ningún estudiante aparece dos veces en el mismo periodo, y las
 *      matrículas operativas no aparecen nunca.
 *   3. PRESENCIA: cada retorno con notas en el periodo está presente, y en su
 *      sección OFICIAL.
 *   4. CONTADORES: `total_aprobables` por sección cuadra con el tamaño real del
 *      lote de esa sección (si divergen, RA ve un número y le salen otras tantas).
 *   5. SOLAPE DE FUENTES: ninguna de las uniones de la boleta recibe dato de las
 *      DOS matrículas en el mismo bimestre. La de asistencia SUMA, así que un
 *      solape ahí infla el contador de la boleta en silencio.
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

$pdo    = Core\Database::connect();
$modelo = new App\Models\BoletaPublicaModel();
$asis   = new App\Models\AsistenciaModel();

$fallos = 0;
$ok     = static function (bool $cond, string $msg) use (&$fallos): void {
    echo($cond ? "  OK   " : "  FALLA ") . $msg . "\n";
    if (!$cond) { $fallos++; }
};

// ── Candado: un retorno NUNCA cruza de nivel (24/09/2026) ────────
// Regla del usuario: «un estudiante de 1.º de secundaria jamás puede retornar
// a 6.º de primaria». El retorno es a un grado INFERIOR del MISMO nivel y del
// mismo año. Lo impiden `create()` (la lista de secciones) y `store()` (la
// validacion); aqui se vigilan las dos cosas: el DATO (por si alguien lo carga
// a mano) y el CODIGO (por si alguien afloja el filtro).
echo "Candado de nivel del retorno de grado\n";
$cruzados = (int) $pdo->query("
    SELECT COUNT(*)
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id   JOIN secciones so ON so.id = mo.seccion_id
    JOIN grados go     ON go.id = so.grado_id
    JOIN matriculas mp ON mp.id = r.matricula_operativa_id JOIN secciones sp ON sp.id = mp.seccion_id
    JOIN grados gp     ON gp.id = sp.grado_id
    WHERE gp.nivel_id <> go.nivel_id OR gp.numero >= go.numero OR mp.anio_id <> mo.anio_id
")->fetchColumn();
$ok($cruzados === 0, "ningun retorno cruza de nivel, sube de grado o cambia de año ($cruzados)");
$srcRet = (string) file_get_contents(ROOT_PATH . '/app/Controllers/Matricula/RetornoGradoController.php');
$ok(preg_match('~function store\(.*?g\.nivel_id = \?.*?>= \(int\) \$oficial\[\'grado_numero\'\]~s', $srcRet) === 1,
    'store() valida mismo nivel y grado inferior');
$ok(preg_match('~function create\(.*?g\.nivel_id = \?\s+AND g\.numero < \?~s', $srcRet) === 1,
    'create() solo ofrece secciones del mismo nivel y de grado inferior');
echo "\n";

// ── Contexto: retornos y periodos ────────────────────────────────
$retornos = $pdo->query("
    SELECT r.id, r.matricula_oficial_id, r.matricula_operativa_id, r.estado, r.fecha_retorno,
           CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres) AS alumno,
           so.id AS seccion_oficial_id, CONCAT(go.nombre_display,' ',so.nombre) AS grado_oficial,
           sp.id AS seccion_operativa_id, CONCAT(gp.nombre_display,' ',sp.nombre) AS grado_operativo
    FROM retornos_grado r
    INNER JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    INNER JOIN estudiantes e ON e.id = mo.estudiante_id
    INNER JOIN personas p    ON p.id = e.persona_id
    INNER JOIN secciones so  ON so.id = mo.seccion_id
    INNER JOIN grados go     ON go.id = so.grado_id
    INNER JOIN matriculas mp ON mp.id = r.matricula_operativa_id
    INNER JOIN secciones sp  ON sp.id = mp.seccion_id
    INNER JOIN grados gp     ON gp.id = sp.grado_id
    ORDER BY r.id
")->fetchAll(PDO::FETCH_ASSOC);

$periodos = $pdo->query("
    SELECT id, numero, nombre_display, estado FROM periodos
    WHERE anio_id = (SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1)
    ORDER BY numero
")->fetchAll(PDO::FETCH_ASSOC);

echo "=== CONTEXTO ===\n";
if (!$retornos) {
    echo "  No hay retornos de grado registrados. Las comprobaciones 1-4 son triviales.\n";
}
foreach ($retornos as $r) {
    echo "  Retorno #{$r['id']} ({$r['estado']}, {$r['fecha_retorno']}): {$r['alumno']}\n"
       . "      oficial   m{$r['matricula_oficial_id']}  -> {$r['grado_oficial']} (sec {$r['seccion_oficial_id']})\n"
       . "      operativa m{$r['matricula_operativa_id']} -> {$r['grado_operativo']} (sec {$r['seccion_operativa_id']})\n";
}

$idsOperativas = array_map('intval', array_column($retornos, 'matricula_operativa_id'));
$idsOficiales  = array_map('intval', array_column($retornos, 'matricula_oficial_id'));
$idsRetorno    = array_merge($idsOperativas, $idsOficiales);

/**
 * Listado con la lógica VIEJA (anterior al 05/08/2026): ancla solo en la propia
 * matrícula y no sabe nada de retornos. Copia literal de la query que había.
 */
$listadoViejo = static function (int $periodoId) use ($pdo): array {
    $st = $pdo->prepare("
        SELECT DISTINCT m.id
        FROM matriculas m
        INNER JOIN estudiantes e ON e.id   = m.estudiante_id
        INNER JOIN personas per  ON per.id = e.persona_id
        INNER JOIN secciones s   ON s.id   = m.seccion_id
        INNER JOIN grados g      ON g.id   = s.grado_id
        INNER JOIN calificaciones cal
            ON cal.matricula_id = m.id AND cal.periodo_id = ?
        INNER JOIN bloqueos_competencia bc
            ON bc.carga_id       = cal.carga_id
           AND bc.competencia_id = cal.competencia_id
           AND bc.periodo_id     = cal.periodo_id
        WHERE m.estado = 'aprobada'
    ");
    $st->execute([$periodoId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

// ── 1. EQUIVALENCIA con la lógica vieja ──────────────────────────
echo "\n=== 1. Equivalencia con la logica anterior (solo pueden cambiar los retornos) ===\n";
foreach ($periodos as $p) {
    $pid    = (int) $p['id'];
    $nuevo  = array_map('intval', array_column($modelo->getMatriculasAprobadasParaBoleta($pid), 'matricula_id'));
    $viejo  = $listadoViejo($pid);
    $salen  = array_values(array_diff($viejo, $nuevo));
    $entran = array_values(array_diff($nuevo, $viejo));
    $ajenos = array_values(array_diff(array_merge($salen, $entran), $idsRetorno));

    $detalle = sprintf(
        "%-14s viejo=%3d nuevo=%3d | salen=%s | entran=%s",
        $p['nombre_display'], count($viejo), count($nuevo),
        $salen ? implode(',', $salen) : '-',
        $entran ? implode(',', $entran) : '-'
    );
    $ok(empty($ajenos), $detalle . ($ajenos ? '  <<< AFECTA A MATRICULAS AJENAS: ' . implode(',', $ajenos) : ''));
}

// ── 2. UNICIDAD y ausencia de operativas ─────────────────────────
echo "\n=== 2. Un estudiante por periodo, y ninguna matricula operativa ===\n";
foreach ($periodos as $p) {
    $pid   = (int) $p['id'];
    $filas = $modelo->getMatriculasAprobadasParaBoleta($pid);

    $porNombre = [];
    foreach ($filas as $f) { $porNombre[$f['nombre_completo']][] = $f['matricula_id']; }
    $repetidos = array_filter($porNombre, static fn($v) => count($v) > 1);

    $operativasListadas = array_values(array_intersect(
        array_map('intval', array_column($filas, 'matricula_id')),
        $idsOperativas
    ));

    $ok(
        empty($repetidos) && empty($operativasListadas),
        sprintf('%-14s %3d boletas | repetidos=%d | operativas listadas=%s',
            $p['nombre_display'], count($filas), count($repetidos),
            $operativasListadas ? implode(',', $operativasListadas) : '0')
    );
    foreach ($repetidos as $nom => $ids) {
        echo "         repetido: $nom -> m" . implode(' + m', $ids) . "\n";
    }
}

// ── 3. PRESENCIA del retorno en su seccion OFICIAL ───────────────
echo "\n=== 3. Cada retorno con notas aparece, y en su seccion OFICIAL ===\n";
foreach ($retornos as $r) {
    foreach ($periodos as $p) {
        $pid = (int) $p['id'];

        // ¿Alguna de las dos matrículas tiene competencias bloqueadas en el periodo?
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc
                    ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
                   AND bc.periodo_id = cal.periodo_id
            WHERE cal.periodo_id = ? AND cal.matricula_id IN (?, ?)
        ");
        $st->execute([$pid, (int) $r['matricula_oficial_id'], (int) $r['matricula_operativa_id']]);
        if ((int) $st->fetchColumn() === 0) {
            echo "  --   {$p['nombre_display']}: sin notas bloqueadas, no corresponde boleta\n";
            continue;
        }

        $enOficial = array_column(
            $modelo->getMatriculasAprobadasParaBoleta($pid, (int) $r['seccion_oficial_id']), 'matricula_id'
        );
        $enOperativa = array_column(
            $modelo->getMatriculasAprobadasParaBoleta($pid, (int) $r['seccion_operativa_id']), 'matricula_id'
        );

        $estaDondeDebe = in_array((int) $r['matricula_oficial_id'], array_map('intval', $enOficial), true);
        $noEstaDondeNo = !array_intersect(array_map('intval', $enOperativa), $idsRetorno);

        $ok($estaDondeDebe && $noEstaDondeNo, sprintf(
            '%-14s presente en %s = %s | ausente de %s = %s',
            $p['nombre_display'], $r['grado_oficial'], $estaDondeDebe ? 'si' : 'NO',
            $r['grado_operativo'], $noEstaDondeNo ? 'si' : 'NO'
        ));
    }
}

// ── 4. CONTADORES por seccion == tamaño real del lote ────────────
echo "\n=== 4. total_aprobables por seccion == boletas que realmente salen ===\n";
foreach ($periodos as $p) {
    $pid       = (int) $p['id'];
    $descuadre = [];
    foreach ($modelo->getSeccionesParaPeriodo($pid) as $s) {
        $real = count($modelo->getMatriculasAprobadasParaBoleta($pid, (int) $s['seccion_id']));
        if ((int) $s['total_aprobables'] !== $real) {
            $descuadre[] = "{$s['grado_nombre']} {$s['seccion_nombre']}"
                         . " (dice {$s['total_aprobables']}, salen $real)";
        }
    }
    $ok(empty($descuadre), sprintf('%-14s %s', $p['nombre_display'],
        $descuadre ? 'DESCUADRE: ' . implode(' | ', $descuadre) : 'todas las secciones cuadran'));
}

// ── 5. SOLAPE de fuentes en las uniones de la boleta ─────────────
echo "\n=== 5. Solape oficial/operativa por bimestre (la union de asistencia SUMA) ===\n";
foreach ($retornos as $r) {
    $of = (int) $r['matricula_oficial_id'];
    $op = (int) $r['matricula_operativa_id'];

    foreach ($periodos as $p) {
        $pid    = (int) $p['id'];
        $avisos = [];

        // Asistencia: la unión SUMA -> un solape infla el contador de la boleta.
        $st = $pdo->prepare("SELECT matricula_id, faltas, faltas_justificadas, tardanzas,
                                    tardanzas_justificadas
                             FROM inasistencias WHERE periodo_id = ? AND matricula_id IN (?, ?)");
        $st->execute([$pid, $of, $op]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($filas) > 1) {
            $suma = array_sum(array_column($filas, 'faltas'));
            $avisos[] = "ASISTENCIA en ambas (la boleta sumaria faltas=$suma)";
        }

        // Conducta: array_replace por periodo -> gana la OFICIAL, tapa a la operativa.
        foreach (['conducta_respuestas', 'calificaciones_conducta'] as $tabla) {
            $st = $pdo->prepare("SELECT COUNT(DISTINCT matricula_id) FROM $tabla
                                 WHERE periodo_id = ? AND matricula_id IN (?, ?)");
            $st->execute([$pid, $of, $op]);
            if ((int) $st->fetchColumn() > 1) { $avisos[] = "$tabla en ambas"; }
        }

        // Calificaciones: array_merge por [competencia][periodo] -> gana la OFICIAL.
        // No duplica filas, pero si las notas difieren la operativa queda tapada.
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM calificaciones a
            INNER JOIN calificaciones b
                    ON b.matricula_id = ? AND b.competencia_id = a.competencia_id
                   AND b.periodo_id = a.periodo_id AND b.nota_numerica <> a.nota_numerica
            WHERE a.matricula_id = ? AND a.periodo_id = ?
        ");
        $st->execute([$op, $of, $pid]);
        if ((int) $st->fetchColumn() > 0) {
            $avisos[] = 'CALIFICACIONES en ambas con nota DISTINTA (gana la oficial)';
        }

        $ok(empty($avisos), sprintf('%-14s %s', $p['nombre_display'],
            $avisos ? implode(' | ', $avisos) : 'sin solape'));
    }

    // Lo que la boleta muestra REALMENTE, como control final.
    echo "      asistencia segun la boleta (union real):\n";
    $ctx = (new App\Models\CalificacionModel())->boletaContexto($of);
    foreach ($periodos as $p) {
        $u = $asis->getDelBimestreUnion($ctx['fuentes'], (int) $p['id']);
        echo "        {$p['nombre_display']}: faltas={$u['faltas']} fj={$u['faltas_justificadas']}"
           . " tard={$u['tardanzas']} tj={$u['tardanzas_justificadas']}\n";
    }
}

echo "\n" . str_repeat('-', 70) . "\n";
echo $fallos === 0
    ? "RESULTADO: OK — la boleta se emite por la matricula oficial, sin duplicados ni ausencias.\n"
    : "RESULTADO: $fallos comprobacion(es) FALLARON.\n";
exit($fallos === 0 ? 0 : 1);
