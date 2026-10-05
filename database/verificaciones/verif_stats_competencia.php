<?php

/**
 * Verificación — estadísticas por competencia (contadores del resumen).
 * Uso: php database/verificaciones/verif_stats_competencia.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Corre en PRODUCCIÓN.
 *
 * CONTEXTO
 *   El bloque de estadísticas que va encima de la tabla de alumnos no consulta
 *   nada: deriva todo del `$alumnos` que ya devuelve
 *   `CalificacionModel::getResumenCompetencia()`. Eso lo hace barato, pero
 *   también significa que un cambio en ese método o en el roster mueve las
 *   cifras sin que nadie lo note. Estos asertos son el aviso.
 *
 * QUÉ COMPRUEBA
 *   1. Los tres cuadres del universo, sobre competencias REALES: el roster no
 *      pierde a nadie, la distribución AD/A/B/C suma exactamente los evaluados,
 *      y aprobados + desaprobados = evaluados (los no evaluados van aparte).
 *   2. Que el corte de aprobación MUERDE EN SUS DOS RAMAS: el mismo literal B
 *      aprueba en secundaria y desaprueba en primaria. Una guarda nueva sin
 *      prueba de sus dos ramas es media guarda.
 *   3. Que un EXONERADO CON NOTA en la base no entra a evaluados ni a aprobados.
 *      Es el caso que justifica sacarlos del universo, y el único que un array
 *      inventado no reproduce: exonerar NO borra las notas anteriores.
 *   4. Que el contador de "evaluados" coincide con lo que dice la BASE (un
 *      COUNT escrito a mano aquí, no derivado del helper: si ambos salieran de
 *      la misma fuente el aserto no probaría nada).
 *   5. Que el parcial RENDERIZA con datos reales y publica las cifras del
 *      helper, y que las tres vistas que deben incluirlo lo incluyen.
 */

define('ROOT_PATH',  dirname(__DIR__, 2));
define('APP_PATH',   ROOT_PATH . '/app');
define('CORE_PATH',  ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VIEW_PATH',  ROOT_PATH . '/resources/views');

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
    echo ($pasa ? '  OK    ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};

// ---- 0. Muestra: competencias reales con notas, de AMBOS niveles -----
// Se toman de `calificaciones` porque es donde vive el promedio final; el
// bloque se pinta con criterios confirmados, no hace falta que esten bloqueadas.
//
// La muestra se pide NIVEL POR NIVEL a proposito: con un solo LIMIT sobre el
// conjunto, primaria (que tiene mas cargas) se llevaba las 40 filas y la mitad
// de los asertos no llegaba a medir secundaria.
$muestra = [];
foreach (['prim', 'sec'] as $nivel) {
    $st = $pdo->prepare("
        SELECT cal.carga_id, cal.competencia_id, cal.periodo_id,
               n.codigo AS nivel_codigo,
               COUNT(*) AS notas
        FROM calificaciones cal
        INNER JOIN cargas_academicas ca ON ca.id = cal.carga_id
        INNER JOIN secciones s          ON s.id  = ca.seccion_id
        INNER JOIN grados g             ON g.id  = s.grado_id
        INNER JOIN niveles n            ON n.id  = g.nivel_id
        WHERE n.codigo = ?
        GROUP BY cal.carga_id, cal.competencia_id, cal.periodo_id, n.codigo
        HAVING notas >= 5
        ORDER BY RAND()
        LIMIT 20
    ");
    $st->execute([$nivel]);
    $muestra = array_merge($muestra, $st->fetchAll(PDO::FETCH_ASSOC));
}

// Y ademas, SIEMPRE, las competencias de cargas con alguna exoneracion viva.
// Son poquisimas (2 en la copia de agosto de 2026), asi que al azar no salen
// casi nunca — y son justo el caso que justifica sacarlos del universo.
$conExoneracion = $pdo->query("
    SELECT DISTINCT cal.carga_id, cal.competencia_id, cal.periodo_id,
           n.codigo AS nivel_codigo, 0 AS notas
    FROM exoneraciones ex
    INNER JOIN matriculas m         ON m.id  = ex.matricula_id AND ex.revocado_en IS NULL
    INNER JOIN cargas_academicas ca ON ca.seccion_id = m.seccion_id
    INNER JOIN calificaciones cal   ON cal.carga_id  = ca.id
    INNER JOIN secciones s          ON s.id = ca.seccion_id
    INNER JOIN grados g             ON g.id = s.grado_id
    INNER JOIN niveles n            ON n.id = g.nivel_id
    WHERE ca.area_id = ex.area_id
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$yaEsta = static function (array $m, array $lista): bool {
    foreach ($lista as $x) {
        if ($x['carga_id'] === $m['carga_id']
            && $x['competencia_id'] === $m['competencia_id']
            && $x['periodo_id'] === $m['periodo_id']) {
            return true;
        }
    }
    return false;
};
foreach ($conExoneracion as $m) {
    if (!$yaEsta($m, $muestra)) { $muestra[] = $m; }
}

if (!$muestra) {
    fwrite(STDERR, "ABORTA: no hay calificaciones con las que medir.\n");
    exit(1);
}

$porNivel = array_count_values(array_column($muestra, 'nivel_codigo'));
printf("== Muestra: %d competencias (prim: %d, sec: %d) ==\n\n",
    count($muestra), $porNivel['prim'] ?? 0, $porNivel['sec'] ?? 0);

$chk('la muestra cubre los DOS niveles',
    !empty($porNivel['prim']) && !empty($porNivel['sec']),
    'prim=' . ($porNivel['prim'] ?? 0) . ' sec=' . ($porNivel['sec'] ?? 0));

$calModel = new App\Models\CalificacionModel();
$exoModel = new App\Models\ExoneracionModel();

$anioDe = [];
$casos  = [];

foreach ($muestra as $m) {
    $periodoId = (int) $m['periodo_id'];
    if (!isset($anioDe[$periodoId])) {
        $st = $pdo->prepare('SELECT anio_id FROM periodos WHERE id = ?');
        $st->execute([$periodoId]);
        $anioDe[$periodoId] = (int) $st->fetchColumn();
    }

    $resumen    = $calModel->getResumenCompetencia(
        (int) $m['carga_id'], (int) $m['competencia_id'], $periodoId
    );
    $exonerados = $exoModel->getActivasParaCarga((int) $m['carga_id'], $anioDe[$periodoId]);

    $casos[] = [
        'meta'       => $m,
        'alumnos'    => $resumen['alumnos'],
        'exonerados' => $exonerados,
        'stats'      => stats_competencia($resumen['alumnos'], $exonerados, $m['nivel_codigo']),
    ];
}

// ---- 1. Los tres cuadres del universo --------------------------------
echo "\n=== 1. Cuadre del universo ===\n";

$falloRoster = $falloLit = $falloAprob = null;
foreach ($casos as $c) {
    $s   = $c['stats'];
    $etq = "carga {$c['meta']['carga_id']} / comp {$c['meta']['competencia_id']}";

    if ($s['evaluados'] + $s['no_evaluados'] + $s['exonerados'] !== $s['total']) {
        $falloRoster ??= $etq;
    }
    if (array_sum($s['literales']) !== $s['evaluados']) {
        $falloLit ??= $etq;
    }
    if ($s['aprobados'] + $s['desaprobados'] !== $s['evaluados']) {
        $falloAprob ??= $etq;
    }
}

$chk('evaluados + sin evaluar + exonerados == total del roster',
    $falloRoster === null, $falloRoster ?? count($casos) . ' competencias');
$chk('AD + A + B + C == evaluados',
    $falloLit === null, $falloLit ?? count($casos) . ' competencias');
$chk('aprobados + desaprobados == evaluados',
    $falloAprob === null, $falloAprob ?? count($casos) . ' competencias');

// ---- 2. El corte de aprobacion muerde en SUS DOS RAMAS ---------------
echo "\n=== 2. El corte por nivel, en sus dos ramas ===\n";

$chk('en SECUNDARIA el literal B aprueba',        nota_es_aprobatoria('B', 'sec'));
$chk('en PRIMARIA el mismo literal B NO aprueba', !nota_es_aprobatoria('B', 'prim'));
$chk('C no aprueba en ninguno de los dos',
    !nota_es_aprobatoria('C', 'sec') && !nota_es_aprobatoria('C', 'prim'));
$chk('AD y A aprueban en los dos',
    nota_es_aprobatoria('AD', 'prim') && nota_es_aprobatoria('A', 'prim')
    && nota_es_aprobatoria('AD', 'sec') && nota_es_aprobatoria('A', 'sec'));
$chk('sin nota (literal null) NO aprueba',
    !nota_es_aprobatoria(null, 'sec') && !nota_es_aprobatoria(null, 'prim'));

// La rama que importa de verdad: la MISMA competencia contada con los dos
// cortes tiene que diferir en exactamente el numero de B.
$conB = null;
foreach ($casos as $c) {
    if ($c['stats']['literales']['B'] > 0) { $conB = $c; break; }
}
if ($conB === null) {
    $chk('hay alguna competencia con B en la muestra', false,
        'sin B no se puede medir la diferencia entre niveles');
} else {
    $comoPrim = stats_competencia($conB['alumnos'], $conB['exonerados'], 'prim');
    $comoSec  = stats_competencia($conB['alumnos'], $conB['exonerados'], 'sec');
    $chk('la misma competencia da MENOS aprobados en primaria que en secundaria',
        $comoSec['aprobados'] - $comoPrim['aprobados'] === $comoPrim['literales']['B'],
        "prim={$comoPrim['aprobados']} sec={$comoSec['aprobados']} "
        . "(B={$comoPrim['literales']['B']})");
}

// ---- 3. Exonerado CON nota en la base --------------------------------
echo "\n=== 3. Exonerados fuera del universo ===\n";

$conExo = array_values(array_filter(
    $casos, static fn(array $c): bool => $c['stats']['exonerados'] > 0
));

if (!$conExo) {
    // No es un fallo del codigo, pero SI hay que decirlo: el aserto que
    // justifica la regla no llego a ejecutarse sobre datos reales.
    echo "  AVISO  la muestra no contiene exonerados: el caso real no se midio\n";
    $chk('el filtro de exonerados funciona (medido con datos sinteticos)',
        stats_competencia(
            [['matricula_id' => 1, 'promedio' => 17, 'literal' => 'A']], [1], 'sec'
        )['evaluados'] === 0);
} else {
    $falloExo = null;
    $exoConNota = 0;
    foreach ($conExo as $c) {
        $exoSet = array_flip($c['exonerados']);
        foreach ($c['alumnos'] as $a) {
            if (isset($exoSet[$a['matricula_id']]) && $a['promedio'] !== null) { $exoConNota++; }
        }
        // Recuento a mano, sin pasar por el helper.
        $vivos = array_filter(
            $c['alumnos'],
            static fn(array $a): bool =>
                !isset($exoSet[$a['matricula_id']]) && $a['promedio'] !== null
        );
        if (count($vivos) !== $c['stats']['evaluados']) {
            $falloExo ??= "carga {$c['meta']['carga_id']}";
        }
    }
    $chk('los exonerados no entran a evaluados, tengan nota o no',
        $falloExo === null,
        $falloExo ?? count($conExo) . ' competencias, ' . $exoConNota . ' exo con nota');
}

// ---- 4. "Evaluados" contra un COUNT escrito a mano -------------------
echo "\n=== 4. Contraste contra la base ===\n";

$falloCount = null;
foreach ($casos as $c) {
    $m = $c['meta'];
    // Control independiente: cuenta las filas de `calificaciones` del mismo
    // roster que pinta la tabla, sin pasar por el helper ni por el modelo.
    //
    // 🔴 El filtro por SECCION DE LA CARGA no es decorativo. Hay notas cuya
    // matricula ya no pertenece a la seccion de la carga —un cambio de seccion
    // a mitad de bimestre deja la nota donde se curso—, y el roster del modelo
    // las excluye. Sin esta condicion el control las contaba y acusaba una
    // divergencia que no existe (medido: carga 118, matricula 692).
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM calificaciones cal
        INNER JOIN matriculas m ON m.id = cal.matricula_id
        WHERE cal.carga_id = ? AND cal.competencia_id = ? AND cal.periodo_id = ?
          AND m.seccion_id = (SELECT seccion_id FROM cargas_academicas WHERE id = cal.carga_id)
          AND m.tipo  NOT IN ('trasladado', 'retirado')
          -- Retorno de grado por TRAMO (05/10/2026, migracion 073), escrito a mano:
          -- la operativa cuenta en los bimestres que curso (aunque revertida quede
          -- `desactivado`), la oficial en los demas.
          AND (m.estado IN ('aprobada', 'pendiente')
               OR m.id IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido'))
          AND NOT EXISTS (
              SELECT 1 FROM retornos_grado r
              JOIN periodos pd ON pd.id = r.periodo_desde_id
              LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
              JOIN periodos px ON px.id = cal.periodo_id
              WHERE (r.matricula_oficial_id = m.id
                     AND px.numero >= pd.numero
                     AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero)))
                 OR (r.matricula_operativa_id = m.id
                     AND NOT (px.numero >= pd.numero
                              AND (r.estado = 'activo' OR (ph.id IS NOT NULL AND px.numero <= ph.numero))))
          )
    ");
    $st->execute([$m['carga_id'], $m['competencia_id'], $m['periodo_id']]);
    $enBase = (int) $st->fetchColumn();

    // El control no sabe de exoneraciones: se le restan las del propio caso.
    $exoSet = array_flip($c['exonerados']);
    $exoConNota = 0;
    foreach ($c['alumnos'] as $a) {
        if (isset($exoSet[$a['matricula_id']]) && $a['promedio'] !== null) { $exoConNota++; }
    }

    if ($enBase - $exoConNota !== $c['stats']['evaluados']) {
        $falloCount ??= "carga {$m['carga_id']} / comp {$m['competencia_id']}: "
            . "base=" . ($enBase - $exoConNota) . " helper={$c['stats']['evaluados']}";
    }
}
$chk('el contador de evaluados coincide con la base, competencia a competencia',
    $falloCount === null, $falloCount ?? count($casos) . ' competencias');

// ---- 5. El parcial renderiza y publica las cifras --------------------
echo "\n=== 5. Render del parcial y puntos de inclusion ===\n";

// Se elige un caso CON evaluados: con el universo vacio el parcial calla, y
// entonces los asertos de contenido no probarian nada.
$caso = null;
foreach ($casos as $c) {
    if ($c['stats']['evaluados'] > 0) { $caso = $c; break; }
}
if ($caso === null) {
    fwrite(STDERR, "ABORTA: ninguna competencia de la muestra tiene evaluados.\n");
    exit(1);
}

$alumnos     = $caso['alumnos'];
$exonerados  = $caso['exonerados'];
$nivelCodigo = $caso['meta']['nivel_codigo'];
$s           = $caso['stats'];

ob_start();
try {
    require VIEW_PATH . '/shared/_stats-competencia.php';
} catch (\Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, 'ABORTA: el parcial lanzo una excepcion: ' . $e->getMessage() . "\n");
    exit(1);
}
$html = ob_get_clean();

$chk('el parcial renderiza con contenido', strlen($html) > 300, strlen($html) . ' bytes');
$chk('publica el numero de evaluados',
    str_contains($html, '>' . $s['evaluados'] . '</span>'));
$chk('pinta una tarjeta por contador',
    substr_count($html, 'class="stats-comp__kpi"') === ($s['exonerados'] > 0 ? 5 : 4),
    substr_count($html, 'class="stats-comp__kpi"') . ' tarjetas');
$chk('la barra lleva descripcion accesible', str_contains($html, 'role="img"'));
$chk('cada literal con estudiantes tiene su segmento',
    count(array_filter($s['literales'])) === substr_count($html, 'stats-comp__seg '),
    substr_count($html, 'stats-comp__seg ') . ' segmentos');
$chk('la leyenda repite las cifras en texto (no solo el color)',
    substr_count($html, 'stats-comp__cant') === 4);
$chk('cantidad y porcentaje van en elementos SEPARADOS',
    substr_count($html, 'stats-comp__pct"') === 4,
    substr_count($html, 'stats-comp__pct"') . ' porcentajes');
// El borde de color de cada tramo sale de su clase de literal: un tramo sin ella
// se pintaria sin contorno, y ninguna prueba de servidor lo notaria.
$chk('cada tramo pintado lleva su clase de literal',
    substr_count($html, 'stats-comp__seg ')
        === substr_count($html, 'stats-comp__seg stats-comp__seg--'),
    substr_count($html, 'stats-comp__seg stats-comp__seg--') . ' tramos con literal');

// ---- 5 bis. La barra reparte en FRACCIONES y no redondea la cifra ----
// Caso a medida: 23 A + 5 B sobre 28 evaluados da 82.1 % y 17.9 %, dos
// porcentajes CON decimal y uno a cada lado del umbral del 25 %.
$statsAlumnos = array_merge(
    array_map(static fn(int $i): array
        => ['matricula_id' => $i, 'promedio' => 16, 'literal' => 'A'], range(1, 23)),
    array_map(static fn(int $i): array
        => ['matricula_id' => $i, 'promedio' => 12, 'literal' => 'B'], range(24, 28))
);
$statsExonerados = [];
$statsNivel      = 'sec';
ob_start();
require VIEW_PATH . '/shared/_stats-competencia.php';
$htmlDec = ob_get_clean();

$chk('la barra reparte en fracciones `fr`, no en anchos `%`',
    str_contains($htmlDec, 'grid-template-columns: minmax(0, 82.1fr) minmax(0, 17.9fr)')
    && !str_contains($htmlDec, 'style="width:'));

// 🔴 REQUISITO EXPLICITO DEL USUARIO (02/09/2026): la cifra de dentro de la
// barra es la EXACTA, con su decimal. Un `round()` al entero diria "A 82%".
$chk('la etiqueta de la barra conserva el decimal (no redondea al entero)',
    str_contains($htmlDec, '>A 82.1%</span>')
    && !str_contains($htmlDec, '>A 82%</span>'));

// Y esa cifra tiene que ser LA MISMA que la de la leyenda, o el bloque se
// contradice a si mismo a dos centimetros de distancia.
$chk('barra y leyenda dicen la misma cifra',
    substr_count($htmlDec, '82.1%') >= 2 && substr_count($htmlDec, '17.9%') >= 2);

// Las dos bandas de umbral: el tramo grande se etiqueta siempre; el mediano
// solo en pantalla ancha, y para eso sale marcado con `--media`.
$chk('el tramo >=25% se etiqueta siempre y el de 8-25% solo en pantalla ancha',
    str_contains($htmlDec, 'class="stats-comp__seg-etq">A 82.1%')
    && str_contains($htmlDec, 'class="stats-comp__seg-etq stats-comp__seg-etq--media">B 17.9%'));

// Un tramo por debajo del 8% no lleva etiqueta: no cabria ni en escritorio.
// 1 de 29 = 3.4 %. La cifra sigue estando en la leyenda.
//
// ⚠️ Se RECONSTRUYE el array entero, no vale `$statsAlumnos[] = ...`: el parcial
// hace `unset($statsAlumnos)` al salir —a proposito, para no contaminar la
// siguiente vuelta del bucle— asi que aqui la variable ya no existe y el append
// crearia un array de UN elemento. Ese descuido hizo fallar este aserto la
// primera vez que se escribio.
$statsAlumnos = array_merge(
    array_map(static fn(int $i): array
        => ['matricula_id' => $i, 'promedio' => 16, 'literal' => 'A'], range(1, 23)),
    array_map(static fn(int $i): array
        => ['matricula_id' => $i, 'promedio' => 12, 'literal' => 'B'], range(24, 28)),
    [['matricula_id' => 99, 'promedio' => 5, 'literal' => 'C']]
);
$statsExonerados = [];
$statsNivel      = 'sec';
ob_start();
require VIEW_PATH . '/shared/_stats-competencia.php';
$htmlMin = ob_get_clean();
$chk('el tramo <8% se pinta pero SIN etiqueta (la cifra queda en la leyenda)',
    str_contains($htmlMin, 'stats-comp__seg stats-comp__seg--c')
    && !str_contains($htmlMin, '>C 3.4%</span>')
    && str_contains($htmlMin, '(3.4%)'));

// Con el universo vacio no debe emitir NADA: una fila de ceros no informa.
$alumnos = []; $exonerados = [];
ob_start();
require VIEW_PATH . '/shared/_stats-competencia.php';
$chk('con el roster vacio no emite nada', trim(ob_get_clean()) === '');

foreach (['docente/resumen-competencia.php', 'consulta-notas/_tabla.php',
          'docente/tutoria.php'] as $v) {
    $chk("$v incluye el parcial",
        str_contains(file_get_contents(VIEW_PATH . '/' . $v), '/shared/_stats-competencia.php'));
}
// La cuarta pantalla (historial del docente) lo recibe a traves de _tabla.php.
$chk('docente/historial-carga.php sigue delegando en _tabla.php',
    str_contains(file_get_contents(VIEW_PATH . '/docente/historial-carga.php'),
        '/consulta-notas/_tabla.php'));

// ---- 6. El parcial NO se contamina entre vueltas del bucle -----------
// `consulta-notas/carga.php` y `docente/tutoria.php` lo montan DENTRO de un
// foreach. Si el parcial no limpiara sus variables con prefijo, la segunda
// competencia repetiria las cifras de la primera y nadie lo notaria: los
// numeros seguirian siendo plausibles.
echo "\n=== 6. Dos competencias seguidas no se contaminan ===\n";

$unoAlto = [['matricula_id' => 1, 'promedio' => 19, 'literal' => 'AD']];
$unoBajo = [['matricula_id' => 1, 'promedio' => 5,  'literal' => 'C']];

$salidas = [];
foreach ([$unoAlto, $unoBajo] as $datos) {
    $statsAlumnos    = $datos;
    $statsExonerados = [];
    $statsNivel      = 'sec';
    $statsTitulo     = 'Competencia de prueba';
    ob_start();
    require VIEW_PATH . '/shared/_stats-competencia.php';
    $salidas[] = ob_get_clean();
}

$chk('la 2.a vuelta NO repite las cifras de la 1.a',
    $salidas[0] !== $salidas[1]
    && str_contains($salidas[0], 'AD</span>')
    && substr_count($salidas[1], 'stats-comp__seg ') === 1,
    'la 1.a da AD, la 2.a da C');
$chk('el titulo opcional se pinta cuando se pasa',
    substr_count($salidas[0], 'stats-comp__titulo') === 1);
$chk('las variables con prefijo quedan limpias tras el require',
    !isset($statsAlumnos) && !isset($statsTitulo));

// ---- 7. El SASS esta compilado y ATADO a los chips de la tabla -------
echo "\n=== 7. Estilos servidos ===\n";
$css = file_get_contents(ROOT_PATH . '/public/css/app.css');
$chk('public/css/app.css trae las clases del bloque (gulp build corrido)',
    str_contains($css, '.stats-comp__kpi') && str_contains($css, '.stats-comp__seg--ad'));

$regla = static function (string $css, string $selector): string {
    return preg_match('/' . preg_quote($selector, '/') . '\{([^}]*)\}/', $css, $m) ? $m[1] : '';
};

// 🔴 LA REGRESION QUE ORIGINO TODO ESTO (02/09/2026). La barra era flex con
// anchos en `%` y los extremos negociaban las esquinas con `:first-child` /
// `:last-child`, las dos con el ATAJO `border-radius`. Un tramo unico al 100 %
// casa con LAS DOS reglas: misma especificidad, gana la ultima ENTERA —el atajo
// no se fusiona, reemplaza las cuatro esquinas— y el lado IZQUIERDO salia recto.
// El aserto que habia solo miraba que las reglas MENCIONARAN `border-radius`,
// que lo hacian, asi que dio verde a la barra rota.
//
// Ahora cada tramo se redondea SOLO y el caso del 100 % dejo de existir. Estos
// dos asertos vigilan que nadie vuelva a introducir la negociacion de esquinas.
$chk('ningun tramo depende de :first-child/:last-child para su radio',
    $regla($css, '.stats-comp__seg:first-child') === ''
    && $regla($css, '.stats-comp__seg:last-child') === '');
$chk('cada tramo trae su propio radio (un tramo al 100% sale redondeado)',
    str_contains($regla($css, '.stats-comp__seg'), 'border-radius:'));

// La rejilla es lo que permite separar los tramos SIN falsear las proporciones:
// descuenta los `gap` antes de repartir las fracciones. Con flex y `%` no se
// podia, y por eso la barra iba pegada.
$barra = $regla($css, '.stats-comp__barra');
$chk('la barra es una REJILLA con gap (proporciones exactas al separar)',
    str_contains($barra, 'display:grid') && str_contains($barra, 'gap:'));

// Sin `min-width:0` la etiqueta impondria un ancho minimo al tramo y el reparto
// dejaria de ser proporcional — justo lo que la rejilla vino a garantizar.
$chk('el tramo no deja que su etiqueta le imponga un ancho minimo',
    str_contains($regla($css, '.stats-comp__seg'), 'min-width:0'));

// 🔴 EL ASERTO QUE MAS FALTA HACE. `clean-css` 4.2.3 no conoce `@container` y lo
// borra EN SILENCIO al minificar, sin fallar `gulp build`: el SASS se ve
// correcto en el repo y la regla no llega al navegador. Aqui se comprueba que la
// media query que hace de sustituta SI esta en el CSS servido. Si algun dia sube
// el minificador y se pasa a `@container`, este aserto es el que hay que cambiar.
$chk('la etiqueta del tramo mediano llega al CSS servido (no la comio el minificador)',
    str_contains($css, '.stats-comp__seg-etq--media')
    && preg_match('/@media[^{]*min-width:\s*900px[^{]*\{[^@]*\.stats-comp__seg-etq--media\{display:block\}/', $css) === 1);

// El punto del bloque es que el lector reconozca "el color de AD" sin leer la
// leyenda. Si alguien retoca `.nota-literal` y no la barra, ese vinculo se
// rompe EN SILENCIO: los dos siguen siendo azules, pero ya no el mismo azul.
// Son OCHO valores, no cuatro: el tramo toma del chip el `background` para el
// relleno y el `color` para el borde.
$propiedadDe = static function (string $css, string $selector, string $prop): ?string {
    if (!preg_match('/' . preg_quote($selector, '/') . '\{([^}]*)\}/', $css, $m)) {
        return null;
    }
    return preg_match('/(?:^|;)' . $prop . ':(#[0-9a-f]{3,8})/i', $m[1], $c)
        ? strtolower($c[1])
        : null;
};
$vars = preg_match('/\.stats-comp\{([^}]*)\}/', $css, $m) ? $m[1] : '';

$leerVar = static function (string $vars, string $nombre): ?string {
    return preg_match('/--' . $nombre . ':\s*(#[0-9a-f]{3,8})/i', $vars, $mv)
        ? strtolower($mv[1])
        : null;
};

$desajuste = null;
$atados    = 0;
foreach (['ad', 'a', 'b', 'c'] as $lit) {
    // relleno del tramo  <-  background del chip
    // borde del tramo    <-  color (texto) del chip
    $pares = [
        ['relleno', $propiedadDe($css, '.nota-literal--' . $lit, 'background'),
                    $leerVar($vars, 'sc-' . $lit)],
        ['borde',   $propiedadDe($css, '.nota-literal--' . $lit, 'color'),
                    $leerVar($vars, 'sc-' . $lit . '-ink')],
    ];
    foreach ($pares as [$que, $chip, $barra]) {
        if ($chip === null || $barra === null || $chip !== $barra) {
            $desajuste ??= strtoupper($lit) . " ($que): chip=$chip barra=$barra";
        } else {
            $atados++;
        }
    }
}
$chk('los 8 colores de la barra son los MISMOS que los chips .nota-literal',
    $desajuste === null, $desajuste ?? "$atados valores atados (relleno + borde x4)");

echo "\n", $ok
    ? "RESULTADO: OK - los contadores por competencia cuadran con la base.\n"
    : "RESULTADO: HAY FALLOS\n";
exit($ok ? 0 : 1);
