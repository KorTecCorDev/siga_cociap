<?php

/**
 * Verificación — NOTAS DEL COLEGIO DE ORIGEN.
 * Uso: php database/verificaciones/verif_notas_origen.php
 *
 * ⚠️ ESCRIBE PARA PROBAR, siempre dentro de una TRANSACCIÓN QUE TERMINA EN
 * ROLLBACK: no deja ni una fila. Cumple la regla del proyecto ("ningún script
 * de database/ debe limpiar con DELETE lo que no creó").
 *
 * LA REGLA QUE SE VERIFICA (decidida el 10/09/2026)
 *   El Informe de Progreso que emite el COCIAP lleva SOLO las calificaciones
 *   cursadas aquí. Lo que el estudiante trae de su colegio anterior es
 *   INFORMATIVO y su público son los docentes con carga en su sección.
 *
 *   De ahí que la comprobación central sea NEGATIVA: registrar notas de origen
 *   NO puede alterar ni una celda de la boleta. Si algún día una de estas notas
 *   aparece en una boleta, este script tiene que ponerse rojo.
 *
 * QUÉ COMPRUEBA
 *   1. La boleta NO cambia al registrar notas de origen (ni una celda).
 *   2. El orden de mérito del bimestre NO se mueve.
 *   3. GUARDA del docente, en sus DOS ramas: con carga → accede; sin carga → no.
 *   4. RESALTADO: las áreas marcadas son exactamente las cargas de ese docente.
 *   5-7b. (mudados a verif_notificaciones.php el 16/09/2026)
 *   8. ROLLBACK: todos los contadores vuelven a su valor inicial.
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
$externas = new App\Models\NotaExternaModel();
$boletas  = new App\Models\BoletaModel();
$meritos  = new App\Models\OrdenMeritoModel();

$fallos = 0;
$ok = function (bool $cond, string $texto) use (&$fallos): void {
    if (!$cond) { $fallos++; }
    echo ($cond ? '  [OK]   ' : '  [FALLA] ') . $texto . "\n";
};
$contar = function (string $sql, array $p = []) use ($pdo): int {
    $st = $pdo->prepare($sql); $st->execute($p);
    return (int) $st->fetchColumn();
};

// ── Sujeto: una matrícula del año activo con cargas y docentes ──
$fila = $pdo->query("
    SELECT m.id, s.grado_id,
           CONCAT(per.apellido_paterno, ' ', per.nombres) AS alumno
    FROM matriculas m
    JOIN estudiantes e ON e.id = m.estudiante_id
    JOIN personas per  ON per.id = e.persona_id
    JOIN secciones s   ON s.id = m.seccion_id
    JOIN anios_academicos aa ON aa.id = m.anio_id AND aa.estado = 'activo'
    WHERE EXISTS (SELECT 1 FROM cargas_academicas ca
                   WHERE ca.seccion_id = m.seccion_id AND ca.estado = 'activa')
    ORDER BY m.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$fila) { echo "No hay matrículas con cargas activas. Nada que verificar.\n"; exit(0); }

$mid     = (int) $fila['id'];
$gradoId = (int) $fila['grado_id'];
$periodo = 1;

$docentes = $pdo->prepare("
    SELECT DISTINCT ca.docente_id
    FROM matriculas m
    INNER JOIN cargas_academicas ca
            ON ca.seccion_id = m.seccion_id AND ca.anio_id = m.anio_id AND ca.estado = 'activa'
    WHERE m.id = ?
");
$docentes->execute([$mid]);
$idsDocentes = array_map('intval', $docentes->fetchAll(PDO::FETCH_COLUMN));

echo "\n=== SUJETO ===\n";
echo "  matrícula {$mid} ({$fila['alumno']}) · docentes con carga en su sección: "
    . count($idsDocentes) . "\n";

$conDocente = $idsDocentes[0] ?? 0;
$sinDocente = (int) $pdo->query("
    SELECT u.id FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id AND r.codigo = 'docente'
    WHERE u.id NOT IN (" . (implode(',', $idsDocentes) ?: '0') . ")
    LIMIT 1
")->fetchColumn();

$antes = [
    'notas_externas' => $contar("SELECT COUNT(*) FROM notas_externas"),
    'notificaciones' => $contar("SELECT COUNT(*) FROM notificaciones"),
    'alertas'        => $contar("SELECT COUNT(*) FROM alertas"),
];
$boletaAntes  = $boletas->armar($mid, $periodo, 'archivo', true);
$rankingAntes = $meritos->rankingGrado($gradoId, $periodo);

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

$areasSeccion = $externas->areasDeLaSeccion($mid);
$areaMapeada  = (int) ($areasSeccion[0]['id'] ?? 0);

$pdo->beginTransaction();
try {
    echo "\n=== 1-2. REGISTRO — la boleta y el mérito NO se mueven ===\n";

    $externas->registrarLote($mid, [
        ['periodo_nombre' => 'I Bimestre', 'competencia_nombre' => 'Verificación A',
         'area_nombre' => 'Área de prueba', 'area_id' => $areaMapeada, 'nota_literal' => 'A'],
        ['periodo_nombre' => 'I Bimestre', 'competencia_nombre' => 'Verificación B',
         'area_nombre' => 'Área sin equivalente', 'area_id' => null, 'nota_literal' => 'B'],
    ], 'IE de verificación (se revierte)', 1);

    $ok($contar("SELECT COUNT(*) FROM notas_externas") === $antes['notas_externas'] + 2,
        "notas de origen registradas: +2");

    $cA = $celdas($boletaAntes);
    $cD = $celdas($boletas->armar($mid, $periodo, 'archivo', true));
    $ok($cA === $cD, "la boleta del bimestre queda IDÉNTICA (" . count($cA) . " celdas comparadas)");

    $rankingDespues = $meritos->rankingGrado($gradoId, $periodo);
    $mismoOrden = count($rankingAntes) === count($rankingDespues);
    if ($mismoOrden) {
        foreach ($rankingAntes as $i => $f) {
            if (($f['puesto'] ?? null) !== ($rankingDespues[$i]['puesto'] ?? null)
                || (int) $f['matricula_id'] !== (int) $rankingDespues[$i]['matricula_id']) {
                $mismoOrden = false; break;
            }
        }
    }
    $ok($mismoOrden, "el orden de mérito del bimestre no cambia (" . count($rankingAntes) . " filas)");

    echo "\n=== 3. GUARDA DEL DOCENTE — sus DOS ramas ===\n";
    $ok($externas->docenteTieneAcceso($mid, $conDocente) === true,
        "docente {$conDocente}, CON carga en la sección → accede");
    $ok($externas->docenteTieneAcceso($mid, $sinDocente) === false,
        "docente {$sinDocente}, SIN carga en la sección → NO accede (404)");

    echo "\n=== 4. RESALTADO — las áreas marcadas son las cargas reales ===\n";
    $areasDoc = $externas->areasDelDocenteEnSeccion($mid, $conDocente);
    $areasReales = $pdo->prepare("
        SELECT DISTINCT COALESCE(ca.area_id, sa.area_id) AS area_id
        FROM matriculas m
        INNER JOIN cargas_academicas ca
                ON ca.seccion_id = m.seccion_id AND ca.anio_id = m.anio_id AND ca.estado = 'activa'
        LEFT  JOIN subareas sa ON sa.id = ca.subarea_id
        WHERE m.id = ? AND ca.docente_id = ?
    ");
    $areasReales->execute([$mid, $conDocente]);
    $esperadas = array_map('intval', array_filter($areasReales->fetchAll(PDO::FETCH_COLUMN)));
    sort($areasDoc); sort($esperadas);
    $ok($areasDoc === $esperadas,
        "áreas resaltadas [" . implode(',', $areasDoc) . "] = cargas reales ["
        . implode(',', $esperadas) . "]");
    $ok($externas->areasDelDocenteEnSeccion($mid, $sinDocente) === [],
        "un docente sin carga no resalta ninguna fila");

    // Los bloques 5, 6, 7 y 7b (aviso a docentes, aislamiento entre bandejas,
    // `alertas` intacta y roles) se mudaron el 16/09/2026 a
    // verif_notificaciones.php, el verificador propio de ese módulo.

    // ── 7c. IMPORTADOR DE CURRÍCULA + migración 059 ───────────────
    echo "\n=== 7c. IMPORTADOR — la currícula y la clave única con área ===\n";

    $curricula = $externas->curriculaParaImportar($mid);
    $totalComp = 0;
    foreach ($curricula as $a) { $totalComp += count($a['competencias']); }

    $ok($totalComp >= 20 && count($curricula) >= 8,
        "la currícula de su sección trae {$totalComp} competencias en " . count($curricula) . " áreas");

    // Las SUBÁREAS son organización interna del COCIAP y NO deben importarse.
    $conSubarea = 0;
    foreach ($curricula as $a) {
        foreach ($a['competencias'] as $c) {
            if (str_contains($c, ' — ') || str_contains($c, ' | ')) { $conSubarea++; }
        }
    }
    $ok($conSubarea === 0, "ninguna competencia arrastra la subárea en su nombre ({$conSubarea})");

    // El <select> de mapeo y el importador tienen que ofrecer LAS MISMAS áreas:
    // si divergen, la fila importada apunta a un área que el select no tiene.
    $idsCurricula = array_map(static fn (array $a): int => (int) $a['area_id'], $curricula);
    $idsSelect    = array_map(static fn (array $a): int => (int) $a['id'], $externas->areasDeLaSeccion($mid));
    sort($idsCurricula); sort($idsSelect);
    $ok($idsCurricula === $idsSelect,
        'el select de áreas ofrece exactamente las del importador (' . count($idsSelect) . ')');

    // 🔴 EL BLOQUE QUE PRUEBA LA MIGRACIÓN 059.
    // Dos áreas evalúan la misma competencia del MINEDU: con la clave vieja la
    // segunda pisaba a la primera y se perdía una fila EN SILENCIO.
    $porNombre = [];
    foreach ($curricula as $a) {
        foreach ($a['competencias'] as $c) { $porNombre[$c][] = $a['area_nombre']; }
    }
    $repetidas = array_filter($porNombre, static fn (array $v): bool => count($v) > 1);
    echo '  (informativo) competencias que dos áreas comparten: ' . count($repetidas) . "\n";

    $filasImport = [];
    foreach ($curricula as $a) {
        foreach ($a['competencias'] as $c) {
            $filasImport[] = [
                'periodo_nombre'     => 'I Bimestre',
                'competencia_nombre' => $c,
                'area_nombre'        => $a['area_nombre'],
                'area_id'            => (int) $a['area_id'],
                'nota_literal'       => 'A',
            ];
        }
    }
    $antesImport = $contar("SELECT COUNT(*) FROM notas_externas WHERE matricula_id = ?", [$mid]);
    $externas->registrarLote($mid, $filasImport, 'IE de verificación', 1);
    $despuesImport = $contar("SELECT COUNT(*) FROM notas_externas WHERE matricula_id = ?", [$mid]);

    $ok($despuesImport - $antesImport === $totalComp,
        "importar {$totalComp} competencias guarda " . ($despuesImport - $antesImport)
        . " filas (esperado {$totalComp}; con la clave vieja se perdían las repetidas)");

    if ($repetidas !== []) {
        $nombreRep = array_key_first($repetidas);
        $filasRep = $contar(
            "SELECT COUNT(*) FROM notas_externas WHERE matricula_id = ? AND competencia_nombre = ?",
            [$mid, $nombreRep]
        );
        $ok($filasRep === count($repetidas[$nombreRep]),
            'la competencia compartida por ' . count($repetidas[$nombreRep])
            . " áreas conserva sus {$filasRep} filas");
    }

    echo "\n=== 7d. PROCEDENCIAS — el punto único de las categorías ===\n";
    $ok(count(PROCEDENCIAS_NOTA) === 3, 'hay 3 procedencias declaradas');
    foreach ([PROCEDENCIA_EXTRAORDINARIA, PROCEDENCIA_ORIGEN, PROCEDENCIA_SIAGIE] as $clave) {
        $p = procedencia_nota($clave);
        $ok($p !== null && $p['corto'] !== '' && $p['nombre'] !== '' && $p['destino'] !== '',
            "'{$clave}': nombre, chip y destino completos");
    }
    $iconos = array_column(PROCEDENCIAS_NOTA, 'icono');
    $ok(count($iconos) === count(array_unique($iconos)),
        'ninguna procedencia comparte icono con otra (' . implode(', ', $iconos) . ')');
    foreach ($iconos as $ico) {
        $ok(is_file(ROOT_PATH . '/public/assets/icons/' . $ico . '.svg'), "el icono {$ico}.svg existe");
    }
    $ambar = array_filter(PROCEDENCIAS_NOTA, static fn (array $p): bool => $p['ambar']);
    $ok(count($ambar) === 1 && array_key_first($ambar) === PROCEDENCIA_EXTRAORDINARIA,
        'solo la extraordinaria usa el ámbar: es la única que llega a la boleta');

    // ── 7e. UNA SOLA LECTURA Y GUARDA EN LAS DOS CAPAS ────────────
    // (correcciones de la revisión previa al despliegue, 11/09/2026)
    echo "\n=== 7e. Importador — lectura única de la currícula y guarda del formulario ===\n";

    // La currícula es la consulta MÁS CARA de la pantalla y se pedía DOS veces:
    // una para la card y otra dentro de `areasDeLaSeccion()`, que deriva de
    // ella. Ahora se memoriza por matrícula, y aquí se MIDE de verdad contando
    // las consultas de esta conexión, no se declara.
    //
    // ⚠️ Instancia NUEVA a propósito: `$externas` ya leyó la currícula en el
    // bloque 7c, así que con ella la "primera" lectura saldría de la memoria y
    // la comprobación se acusaría sola.
    $fresco   = new App\Models\NotaExternaModel();
    $consultas = static function () use ($fresco): int {
        $f = $fresco->query("SHOW SESSION STATUS LIKE 'Questions'");
        return (int) ($f[0]['Value'] ?? 0);
    };

    $base    = $consultas();
    $fresco->curriculaParaImportar($mid);
    $costePrimera = $consultas() - $base - 1;   // -1: la consulta del propio contador

    $base = $consultas();
    $fresco->curriculaParaImportar($mid);
    $fresco->areasDeLaSeccion($mid);
    $costeRepetir = $consultas() - $base - 1;

    $ok($costePrimera > 0, "la 1.ª lectura de la currícula cuesta {$costePrimera} consulta(s)");
    $ok($costeRepetir === 0,
        "repetirla y pedir `areasDeLaSeccion()` cuesta {$costeRepetir}: las dos salen de la memoria");

    $otraMat = (int) $pdo->query(
        "SELECT id FROM matriculas WHERE id <> {$mid} ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($otraMat > 0) {
        $base = $consultas();
        $fresco->curriculaParaImportar($otraMat);
        $costeOtra = $consultas() - $base - 1;
        $ok($costeOtra > 0,
            "otra matrícula ({$otraMat}) NO se sirve de la memoria ajena: {$costeOtra} consulta(s)");
    }

    // La guarda de "importar sin elegir nada" vive en el controlador y acaba en
    // `redirect()`, que hace exit: invocarla aquí mataría el script antes del
    // ROLLBACK. Lo que se ancla es que siga estando EN LAS DOS CAPAS, que es
    // justo lo que se pierde sin avisar en una refactorización.
    $ctrlSrc = file_get_contents(APP_PATH . '/Controllers/Matricula/MatriculaController.php');
    $jsSrc   = file_get_contents(ROOT_PATH . '/resources/js/notas-externas.js');

    $ok(str_contains($ctrlSrc, '$importar && ($periodosPedidos === [] || $areasPedidas === [])'),
        'el servidor bloquea importar sin bimestres Y sin áreas (las dos condiciones)');
    $ok(str_contains($ctrlSrc, "Session::flash('warning'"),
        'y lo dice con un aviso, en vez de recargar en silencio');
    $ok(str_contains($jsSrc, 'data-importar-form') && str_contains($jsSrc, 'form-error'),
        'el cliente lleva la guarda gemela, con su mensaje junto al botón');

    // Los bimestres salen de `AnioAcademicoModel::getPeriodos()`. Las únicas
    // consultas de periodos que quedan a mano en el controlador son las DOS
    // preexistentes de notas autorizadas SIAGIE.
    $ok(substr_count($ctrlSrc, 'FROM periodos') === 2,
        'el controlador conserva solo sus 2 consultas de periodos preexistentes ('
        . substr_count($ctrlSrc, 'FROM periodos') . ')');
    $ok(str_contains($ctrlSrc, 'AnioAcademicoModel())->getPeriodos'),
        'el importador y las casillas leen los bimestres del modelo, una sola vez');

    echo "\n=== 7f. RECORTE SILENCIOSO — ancho de columnas (migración 061) ===\n";
    // El `sql_mode` no es estricto: lo que no cabe se RECORTA sin error. Por eso
    // las constantes MAX_* del modelo tienen que ser EXACTAMENTE el ancho de su
    // columna (si la columna crece y la constante no, se rechaza de más; si la
    // constante crece y la columna no, vuelve el recorte silencioso).
    $anchos = [];
    foreach ($pdo->query("
        SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH AS ancho
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_externas'
    ")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $anchos[$c['COLUMN_NAME']] = (int) $c['ancho'];
    }
    foreach ([
        'periodo_nombre'     => App\Models\NotaExternaModel::MAX_PERIODO,
        'area_nombre'        => App\Models\NotaExternaModel::MAX_AREA,
        'competencia_nombre' => App\Models\NotaExternaModel::MAX_COMPETENCIA,
        'colegio_origen'     => App\Models\NotaExternaModel::MAX_COLEGIO,
    ] as $col => $max) {
        $ok(($anchos[$col] ?? 0) === $max, "{$col}: columna " . ($anchos[$col] ?? '?') . " = constante {$max}");
    }

    $maxPlan = $contar("SELECT MAX(CHAR_LENGTH(nombre_completo)) FROM competencias");
    $ok($maxPlan <= App\Models\NotaExternaModel::MAX_COMPETENCIA,
        "la competencia más larga del plan ({$maxPlan}) cabe en la columna");

    // Ida y vuelta con la competencia más larga del plan: se guarda ENTERA.
    $larga = (string) $pdo->query("
        SELECT nombre_completo FROM competencias
        ORDER BY CHAR_LENGTH(nombre_completo) DESC LIMIT 1
    ")->fetchColumn();
    $externas->registrarLote($mid, [[
        'periodo_nombre' => 'Verif 7f', 'area_nombre' => 'Área 7f',
        'competencia_nombre' => $larga, 'nota_literal' => 'A',
    ]], null, 1);
    $guardada = (string) $pdo->query("
        SELECT competencia_nombre FROM notas_externas
        WHERE matricula_id = {$mid} AND periodo_nombre = 'Verif 7f'
    ")->fetchColumn();
    $ok($guardada === $larga, 'la competencia de ' . mb_strlen($larga) . ' caracteres vuelve entera ('
        . mb_strlen($guardada) . ')');

    // La guarda del servidor rechaza (no recorta): anclada en el fuente, porque
    // invocarla acaba en redirect()+exit y mataría el script antes del ROLLBACK.
    $ok(str_contains($ctrlSrc, 'mb_strlen($comp) > NotaExternaModel::MAX_COMPETENCIA'),
        'el servidor rechaza una competencia más larga que la columna');
    $vistaSrc = file_get_contents(ROOT_PATH . '/resources/views/matriculas/notas-externas.php');
    $ok(str_contains($vistaSrc, 'maxlength="<?= \App\Models\NotaExternaModel::MAX_COMPETENCIA ?>"'),
        'el formulario usa la misma constante en su maxlength');
    $ok($contar("SELECT COUNT(*) FROM notas_externas WHERE CHAR_LENGTH(competencia_nombre) = 120") === 0,
        'no queda ninguna competencia recortada a 120 (reparar_notas_externas_truncadas.php)');

} catch (Throwable $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
    $fallos++;
} finally {
    $pdo->rollBack();
}

echo "\n=== 8. ROLLBACK — la base vuelve a su estado inicial ===\n";
foreach ($antes as $k => $v) {
    $final = $contar("SELECT COUNT(*) FROM {$k}");
    $ok($final === $v, "{$k}: {$v} → {$final} (debe volver a {$v})");
}

echo "\n" . ($fallos === 0
    ? "TODO CORRECTO — las notas de origen no tocan la boleta ni el mérito.\n"
    : "{$fallos} COMPROBACIÓN(ES) FALLIDA(S).\n") . "\n";
exit($fallos === 0 ? 0 : 1);
