<?php

/**
 * Verificación — el CRITERIO DOCUMENTO del retorno de grado tiene un punto
 * único y nadie lo copia.
 * Uso: php database/verificaciones/verif_matricula_documento.php
 *
 * SOLO LECTURA en lo que persiste: la única escritura vive dentro de una
 * TRANSACCIÓN CON ROLLBACK (aserto 5), porque la rama `revertido` no existe en
 * ninguna base y sin simularla el aserto que de verdad muerde no correría nunca.
 * Se puede correr en PRODUCCIÓN.
 *
 * CONTEXTO
 *   El retorno de grado tiene DOS exclusiones INVERSAS, y confundirlas produce
 *   justo el defecto contrario:
 *
 *     EVALUACIÓN → fuera la OFICIAL   (`roster_evaluacion()`, donde se evalúa)
 *     DOCUMENTO  → fuera la OPERATIVA (`matricula_documento()`, donde se cuenta
 *                                      y a quién se le emite el documento)
 *
 *   La segunda estuvo copiada a mano en TRES sitios (la constante privada de
 *   `BoletaPublicaModel`, el token público de `BoletaController` y el cuadro de
 *   `/matriculas/resumen`) y, al anclar también los chips y los 5 gráficos de esa
 *   pantalla, habrían nacido cinco copias más. Desde el 02/09/2026 sale de
 *   `matricula_documento()` (app/Helpers/helpers.php).
 *
 * QUÉ COMPRUEBA
 *   1. El helper emite EXACTAMENTE la condición esperada y respeta el alias.
 *   2. 🔴 ANTI-HÍBRIDO: la condición NO lleva `WHERE estado`. Es la tercera
 *      forma, la incorrecta: con un retorno `revertido` no excluye NINGUNA de
 *      las dos matrículas y el estudiante cuenta dos veces, con la fila fantasma
 *      cayendo en el grado inferior.
 *   3. La composición de `roster_evaluacion()` es real: su primera condición ES
 *      la de `matriculas_vigentes()`, no una copia que pueda divergir.
 *   4. NADIE fuera de `helpers.php` vuelve a escribir la condición a mano, y los
 *      consumidores declarados sí llaman al helper.
 *   5. COMPORTAMIENTO, no texto: el filtro excluye UNA matrícula por retorno con
 *      el retorno `activo` y también con el retorno `revertido` (simulado), y el
 *      híbrido escrito a mano NO excluye ninguna en el segundo caso — que es la
 *      prueba de que el aserto no es vacuo.
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

$pdo = Core\Database::connect();
$ok  = true;

$chk = static function (string $titulo, bool $pasa, string $detalle = '') use (&$ok): void {
    if (!$pasa) { $ok = false; }
    echo ($pasa ? '  OK   ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};

// ── 1. El helper emite literalmente la condicion ──────────────────────
echo "=== 1. Texto emitido por matricula_documento() ===\n";

$esperado = "AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)";

$chk('emite la condicion esperada con el alias por defecto',
    matricula_documento() === $esperado,
    matricula_documento() === $esperado ? 'exacta' : 'el texto cambio: ' . matricula_documento());

// El alias importa: una consulta que aliase `matriculas` como `mat` recibiria
// SQL invalida si el helper ignorase el parametro, y eso revienta en runtime.
$conAlias = matricula_documento('mat');
$chk('respeta el alias que se le pasa',
    str_contains($conAlias, 'AND mat.id NOT IN') && !str_contains($conAlias, ' m.id'),
    $conAlias);

$chk('matriculas_vigentes() emite su condicion esperada',
    matriculas_vigentes() === "AND m.tipo NOT IN ('trasladado', 'retirado')",
    matriculas_vigentes());

// ── 2. ANTI-HIBRIDO ───────────────────────────────────────────────────
echo "\n=== 2. La condicion NO lleva condicion de estado ===\n";

$chk('matricula_documento() no menciona `WHERE estado`',
    stripos(matricula_documento(), 'WHERE estado') === false);
$chk('matricula_documento() no menciona `activo` ni `revertido`',
    !str_contains(matricula_documento(), 'activo')
    && !str_contains(matricula_documento(), 'revertido'));
// El complemento: el criterio de EVALUACION SI lo lleva. Si un dia los dos
// helpers emitieran lo mismo, uno de los dos estaria mal.
$chk('roster_evaluacion() SI lo lleva (los dos criterios siguen siendo distintos)',
    str_contains(roster_evaluacion(), "WHERE estado = 'activo'")
    && roster_evaluacion() !== matricula_documento());

// ── 3. La composicion de roster_evaluacion() es real ──────────────────
echo "\n=== 3. roster_evaluacion() COMPONE, no copia ===\n";

$chk('la primera condicion de roster_evaluacion() ES matriculas_vigentes()',
    str_starts_with(roster_evaluacion(), matriculas_vigentes()),
    'comparten el filtro por tipo');
$chk('y tambien con un alias distinto',
    str_starts_with(roster_evaluacion('mat'), matriculas_vigentes('mat')));
// 🔴 roster_evaluacion() se recompuso el 02/09/2026 y su texto NO debia cambiar
// ni un byte. `verif_roster_evaluacion.php` es el dueno de ese aserto; aqui se
// deja el puntero para que quien toque la composicion sepa donde mirar.
$chk('roster_evaluacion() NO empieza por la exclusion de la operativa (son inversas)',
    !str_starts_with(roster_evaluacion(), matricula_documento()));

// ── 4. Nadie vuelve a copiar la regla a mano ──────────────────────────
echo "\n=== 4. No hay copias sueltas de la condicion ===\n";

$excepciones = [
    'app/Helpers/helpers.php' => 'el punto unico',
];

$copias = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_PATH));
foreach ($it as $archivo) {
    if ($archivo->getExtension() !== 'php') { continue; }

    $rel = str_replace('\\', '/', substr($archivo->getPathname(), strlen(ROOT_PATH) + 1));
    if (isset($excepciones[$rel])) { continue; }

    // Espacios normalizados: la copia del cuadro estaba partida en tres lineas,
    // asi que buscar el literal de una sola linea no la habria visto.
    $src = preg_replace('/\s+/', ' ', (string) file_get_contents($archivo->getPathname()));

    // La huella de la copia es el subselect CERRANDO sin `WHERE`. Las variantes
    // con `WHERE estado = 'activo'` (SiagieExportModel, paneles del padre y del
    // docente) son listados operativos, no el documento: no son esta regla.
    if (str_contains((string) $src, 'matricula_operativa_id FROM retornos_grado )')
        || str_contains((string) $src, 'matricula_operativa_id FROM retornos_grado)')) {
        $copias[] = $rel;
    }
}

$chk('ningun archivo fuera de helpers.php repite la condicion',
    empty($copias),
    $copias ? implode(', ', $copias) : count($excepciones) . ' excepcion(es) documentada(s)');

// Y el complemento: los consumidores declarados LLAMAN al helper. Sin esto, el
// aserto de arriba se pondria verde borrando la regla en vez de centralizandola.
$consumidores = [
    'app/Models/BoletaPublicaModel.php'            => 'lote de boletas y hub de tokens',
    'app/Controllers/Boleta/BoletaController.php'  => 'token publico',
    'app/Models/MatriculaModel.php'                => 'chips, 5 graficos y cuadro de /matriculas/resumen',
];
foreach ($consumidores as $rel => $que) {
    $src = (string) file_get_contents(ROOT_PATH . '/' . $rel);
    $chk("$rel llama a matricula_documento() ($que)",
        str_contains($src, 'matricula_documento('),
        substr_count($src, 'matricula_documento(') . ' llamada(s)');
}

// El caso especial: getResumen() tiene SEIS consultas y las seis van ancladas.
//
// ⚠️ Se mide sobre el CODIGO, no sobre el texto: los comentarios de ese metodo
// nombran `roster_evaluacion()` para explicar por que NO se usa, y un
// `str_contains` a secas se ponia rojo por esa misma frase.
$sinComentarios = static function (string $php): string {
    $codigo = '';
    foreach (token_get_all("<?php\n" . $php) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $codigo .= is_array($t) ? $t[1] : $t;
    }
    return $codigo;
};

$srcMat = (string) file_get_contents(ROOT_PATH . '/app/Models/MatriculaModel.php');
if (preg_match('/public function getResumen.*?\n    \}/s', $srcMat, $mg)) {
    $codigoResumen = $sinComentarios($mg[0]);
    $chk('getResumen() ya NO llama a roster_evaluacion() (traia el ancla contraria)',
        !str_contains($codigoResumen, 'roster_evaluacion('));
    $chk('getResumen() no escribe `retornos_grado` a mano en ninguna consulta',
        !str_contains($codigoResumen, 'retornos_grado'));
    $chk('getResumen() ancla sus SEIS consultas',
        substr_count($codigoResumen, 'matricula_documento(') >= 2
        && substr_count($codigoResumen, '{$ancla}') === 2
        && substr_count($codigoResumen, '{$doc}') === 4,
        substr_count($codigoResumen, '{$ancla}') . ' chips + '
        . substr_count($codigoResumen, '{$doc}') . ' graficos + 1 fuera-del-conteo');
} else {
    $chk('se pudo aislar el codigo de getResumen()', false);
}

// ── 5. Comportamiento: excluye UNA matricula por retorno, en los 2 estados ──
echo "\n=== 5. Comportamiento en las DOS ramas del retorno ===\n";

$anio = $pdo->query("SELECT id, anio FROM anios_academicos ORDER BY estado = 'activo' DESC, anio DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
$anioId = (int) $anio['id'];
echo "Ano medido: {$anio['anio']} (id {$anioId})\n";

/** Cuenta matriculas del ano aplicando el filtro que se le pase. */
$contar = static function (string $filtro) use ($pdo, $anioId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM matriculas m WHERE m.anio_id = ? {$filtro}");
    $st->execute([$anioId]);
    return (int) $st->fetchColumn();
};

// El HIBRIDO, escrito a mano aqui a proposito: es el control contra el que se
// mide. No sale de ningun helper porque no debe existir en el codigo.
$hibrido = "AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'activo')";

$total = $contar('');
$st = $pdo->prepare("
    SELECT COUNT(*) FROM retornos_grado rg
    INNER JOIN matriculas m ON m.id = rg.matricula_oficial_id
    WHERE m.anio_id = ?
");
$st->execute([$anioId]);
$nRetornos = (int) $st->fetchColumn();

echo "  ({$total} matriculas, {$nRetornos} retorno(s) en el ano)\n";

$chk('con el retorno ACTIVO excluye exactamente 1 matricula por retorno',
    $contar(matricula_documento('m')) === $total - $nRetornos,
    $contar(matricula_documento('m')) . ' de ' . $total);

if ($nRetornos === 0) {
    echo "  [--] sin retornos en el ano: la rama `revertido` no se pudo simular\n";
} else {
    $st = $pdo->prepare("
        SELECT rg.id, rg.estado FROM retornos_grado rg
        INNER JOIN matriculas m ON m.id = rg.matricula_oficial_id
        WHERE m.anio_id = ? LIMIT 1
    ");
    $st->execute([$anioId]);
    $retorno = $st->fetch(PDO::FETCH_ASSOC);
    $estadoOriginal = (string) $retorno['estado'];

    // 🔴 La rama `revertido` no existe en ninguna base: se simula DENTRO DE UNA
    // TRANSACCION CON ROLLBACK. CLAUDE.md prohibe que un script de `database/`
    // limpie con DELETE lo que no creo, y ya paso una vez que una verificacion
    // borro el oficial de B1.
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE retornos_grado SET estado = 'revertido' WHERE id = ?");
        $up->execute([$retorno['id']]);

        $conHelper  = $contar(matricula_documento('m'));
        $conHibrido = $contar($hibrido);

        $chk('con el retorno REVERTIDO sigue excluyendo 1 matricula por retorno',
            $conHelper === $total - 1,
            "{$conHelper} de {$total}");
        // La prueba de que el aserto de arriba no es vacuo.
        $chk('y el HIBRIDO no excluye NINGUNA (por eso duplicaba al estudiante)',
            $conHibrido === $total,
            "el hibrido habria contado {$conHibrido} en vez de " . ($total - 1));
    } finally {
        $pdo->rollBack();
    }

    $st = $pdo->prepare("SELECT estado FROM retornos_grado WHERE id = ?");
    $st->execute([$retorno['id']]);
    $chk('el ROLLBACK dejo el retorno como estaba',
        (string) $st->fetchColumn() === $estadoOriginal,
        "estado = {$estadoOriginal}");
}

echo "\n=== 6. Nadie escribe el filtro de retorno a mano ===\n";
// Hasta el 28/09/2026 quedaban CINCO copias del HIBRIDO en app/ (panel docente
// x2, panel del padre, export SIAGIE x2). Eran correctas solo por su filtro
// `estado = 'aprobada'`, que deja fuera la operativa desactivada por revertir();
// la del panel docente tenia ademas una variante de estado amplio que SI
// duplicaba. Toda consulta que excluya la operativa usa matricula_documento().
// El HIBRIDO es la OPERATIVA filtrada por `estado = 'activo'`. Ojo: excluir la
// operativa con `estado = 'revertido'` es LEGITIMO (roster_evaluacion(): tras
// revertir, el estudiante se evalua en la oficial), asi que el patron exige
// 'activo' y no cualquier WHERE.
$patronHibrido = "/SELECT\\s+matricula_operativa_id\\s+FROM\\s+retornos_grado\\s+WHERE\\s+(?:\\w+\\.)?estado\\s*=\\s*'activo'/i";
$chk('la guarda reconoce el HIBRIDO (no es un aserto vacio)',
    preg_match($patronHibrido, $hibrido ?? "AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'activo')") === 1);
$chk('y NO confunde la exclusion legitima de roster_evaluacion()',
    preg_match($patronHibrido, roster_evaluacion('m')) === 0);
$copias = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $codigo = (string) file_get_contents($f->getPathname());
    if (preg_match_all($patronHibrido, $codigo, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as [, $pos]) {
            $copias[] = str_replace(ROOT_PATH . DIRECTORY_SEPARATOR, '', $f->getPathname())
                . ':' . (substr_count(substr($codigo, 0, $pos), "\n") + 1);
        }
    }
}
$chk('ninguna copia a mano del HIBRIDO en app/ (usar matricula_documento())',
    $copias === [],
    $copias ? implode(', ', $copias) : 'barrido de app/ completo');

echo "\n", $ok ? "TODO OK\n" : "HAY FALLAS\n";
exit($ok ? 0 : 1);
