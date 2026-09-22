<?php

/**
 * Verificación de las FASES 4 a 7.
 * Incluye render REAL de las vistas nuevas contra datos de la base: es lo único
 * que atrapa una clave inexistente o una variable no pasada.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('VIEW_PATH', ROOT_PATH . '/resources/views');

require ROOT_PATH . '/app/Helpers/helpers.php';
spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/', 'App\\Controllers\\' => '/app/Controllers/'] as $p => $b) {
        if (str_starts_with($c, $p)) {
            $f = ROOT_PATH . $b . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$leer = fn(string $r): string => file_get_contents(ROOT_PATH . $r);

// ── FASE 4 ───────────────────────────────────────────────────────
echo "FASE 4 — matriculas en lectura\n";
$mc = $leer('/app/Controllers/Matricula/MatriculaController.php');
$chk('el constructor deja entrar a los directores', str_contains($mc, '...ROLES_DIRECCION'));

foreach (['create', 'store', 'apoderado', 'storeApoderado', 'documentos', 'storeDocumentos', 'notasExternas', 'storeNotasExternas'] as $m) {
    $patron = '/public function ' . preg_quote($m, '/') . '\([^)]*\)(?:\s*:\s*\w+)?\s*\r?\n\s*\{\s*\r?\n\s*\$this->requireRole\(self::ROLES_MATRICULAN\);/';
    $chk("$m() blindado con ROLES_MATRICULAN", (bool) preg_match($patron, $mc));
}
preg_match('/private const ROLES_MATRICULAN = (\[.*?\]);/s', $mc, $m);
$chk('ROLES_MATRICULAN conserva EXACTAMENTE los roles de antes',
    isset($m[1]) && eval("return {$m[1]};") === ['admin', 'registro_academico', 'secretaria_academica', 'secretaria_administrativa']);

$bc = $leer('/app/Controllers/Boleta/BoletaController.php');
$chk('la boleta de gestion admite a los directores (2 metodos)',
    substr_count($bc, "'secretaria_administrativa', ...ROLES_DIRECCION") === 2);
$chk('resumenImprimir admite a los directores',
    (bool) preg_match('/resumenImprimir.*?ROLES_DIRECCION/s', $mc));

// ── FASE 5 ───────────────────────────────────────────────────────
echo "\nFASE 5 — navegacion\n";
$ac = $leer('/app/Controllers/Auth/AuthController.php');
$dc = $leer('/app/Controllers/DashboardController.php');
$chk('AuthController ya no manda a los directores a /director/anios',
    !preg_match("/'director_\w+'\s*=>\s*url\('director\/anios'\)/", $ac));
$chk('DashboardController tampoco',
    !preg_match("/'director_\w+'\s*=>\s*url\('director\/anios'\)/", $dc));
$chk('los directores entran al grupo con cards', str_contains($dc, 'rolesConCards'));

// Cards por rol, evaluando el arreglo real de la vista.
$src = $leer('/resources/views/dashboard/index.php');
preg_match('/\$grupos = (\[.*?\n\];)/s', $src, $mm);
$grupos = eval('return ' . rtrim($mm[1], ';') . ';');
$cards = [];
foreach ($grupos as $g) { foreach ($g as $mod) { $cards[$mod['url']] = $mod['roles']; } }

// La 11.a entro el 24/08 con el explorador de criterios. La asercion compara la
// LISTA EXACTA, no el numero: si manana se cuela una card de escritura, o falta
// una de estas, sigue fallando igual.
$esperadasDirector = [
    'director/anios', 'director/cargas', 'matriculas', 'admin/buscar-estudiante',
    'admin/control', 'consulta-notas', 'consulta-notas/criterios', 'admin/cuadros',
    'director/bloqueos', 'director/orden-merito', 'director/ranking-seccion',
];
foreach (ROLES_DIRECCION as $rol) {
    $suyas = array_keys(array_filter($cards, fn($r) => in_array($rol, $r, true)));
    sort($suyas); $esp = $esperadasDirector; sort($esp);
    $chk("$rol ve las 11 cards previstas", $suyas === $esp, count($suyas) . ' cards');
}
$chk('ninguna card de ESCRITURA se le coló al director',
    empty(array_intersect(array_keys(array_filter($cards, fn($r) => in_array('director_ebr', $r, true))),
        ['admin/conducta', 'admin/asistencia', 'rectificaciones', 'admin/boletas-publicas',
         'admin/actas-siagie', 'admin/usuarios', 'admin/director-ebr', 'admin/secciones', 'admin/curriculum'])));

// ── Un glifo, un concepto ─────────────────────────────────────────────
// El wayfinding del sistema (docs/modulos/ui.md) fija el color por concepto
// "para que ubiquen el acceso sin leer". El GLIFO manda igual: si dos cards
// comparten icono, ese atajo deja de funcionar. Pasaba con tres pares —la
// medalla significaba a la vez "merito" y "cuadros", y encima contradecia al
// panel del docente, que ya la usa como wayfinding de merito—.
//
// La forma en que esto regresa es que una card NUEVA reuse un icono existente
// porque ninguno le pega; nada avisa, y el dashboard queda con dos glifos
// iguales.
$iconos = [];
foreach ($grupos as $g) { foreach ($g as $mod) { $iconos[] = $mod['icon']; } }

// La unica pareja admitida hoy: no hay icono con el que sustituir a ninguna de
// las dos. Al resolverla, quitarla de aqui (y entonces la lista queda vacia).
$duplicadosOk = ['users-group-rounded.svg'];
$repetidos = array_keys(array_filter(
    array_count_values($iconos),
    fn(int $n, string $i): bool => $n > 1 && !in_array($i, $duplicadosOk, true),
    ARRAY_FILTER_USE_BOTH
));
$chk('ninguna card del dashboard comparte icono',
    empty($repetidos),
    $repetidos ? implode(', ', $repetidos) : count($iconos) . ' cards, '
        . count(array_unique($iconos)) . ' iconos distintos');

// Un icono mal escrito NO da error: pinta un <img> roto que nadie ve hasta
// abrir el dashboard.
$sinArchivo = array_values(array_filter(
    array_unique($iconos),
    fn(string $i): bool => !file_exists(ROOT_PATH . '/public/assets/icons/' . $i)
));
$chk('todos los iconos del dashboard existen en disco',
    empty($sinArchivo),
    $sinArchivo ? implode(', ', $sinArchivo) : count(array_unique($iconos)) . ' archivo(s)');

// ── FASE 6 ───────────────────────────────────────────────────────
echo "\nFASE 6 — superficies nuevas de consulta\n";
$rutas = $leer('/routes/web.php');
foreach ([
    '/consulta-notas/{periodo_id}/seccion/{seccion_id}/asistencia',
    '/consulta-notas/{periodo_id}/docentes',
    '/consulta-notas/{periodo_id}/docente/{docente_id}',
    '/director/cargas/seccion/{seccion_id}/horario',
    '/admin/cuadros',
    '/admin/cuadros/imprimir',
    '/consulta-notas/{periodo_id}/criterios',
    '/consulta-notas/{periodo_id}/criterios/imprimir',
    '/consulta-notas/criterios',
] as $r) {
    $chk("ruta registrada: $r", str_contains($rutas, "'$r'"));
}
// La de 5 segmentos ANTES que la de 4 (el router ancla por orden).
$chk('el horario por seccion se registra ANTES que /seccion/{id}',
    strpos($rutas, "'/director/cargas/seccion/{seccion_id}/horario'")
    < strpos($rutas, "'/director/cargas/seccion/{seccion_id}'"));

// El literal de 2 segmentos y el /imprimir van ANTES que el patron que podria
// tragarselos (el router ancla por orden de registro).
$chk('/consulta-notas/criterios se registra ANTES que {periodo_id}/criterios',
    strpos($rutas, "'/consulta-notas/criterios'")
    < strpos($rutas, "'/consulta-notas/{periodo_id}/criterios'"));
$chk('/criterios/imprimir se registra ANTES que /criterios',
    strpos($rutas, "'/consulta-notas/{periodo_id}/criterios/imprimir'")
    < strpos($rutas, "'/consulta-notas/{periodo_id}/criterios'"));
$chk('/admin/cuadros/imprimir se registra ANTES que /admin/cuadros',
    strpos($rutas, "'/admin/cuadros/imprimir'") < strpos($rutas, "'/admin/cuadros'"));

// El imprimible NO puede reusar el arbol de la pantalla: un <details> cerrado
// no imprime su contenido.
//
// Se miran los TOKENS, no el texto: el docblock de la vista NOMBRA la etiqueta
// a proposito (documenta por que no la usa), y un str_contains daria un rojo
// falso. Solo cuenta el HTML que la vista emite (T_INLINE_HTML).
$htmlPrint = '';
foreach (token_get_all($leer('/resources/views/consulta-notas/criterios-imprimir.php')) as $tk) {
    if (is_array($tk) && $tk[0] === T_INLINE_HTML) { $htmlPrint .= $tk[1]; }
}
$chk('el imprimible de criterios no emite <details>', !str_contains($htmlPrint, '<details'));

$css = $leer('/public/css/app.css');
// `criterio-item__desc` era la lista por competencia, retirada el 24/08 con el
// rediseno de lectura; la clase vigente del cuerpo de la carga es la tabla.
$chk('el SASS nuevo esta compilado en app.css',
    str_contains($css, 'criterios-tabla') && str_contains($css, 'cuadros-kpi__n'));

// ── FASE 7 — render real ────────────────────────────────────────
echo "\nFASE 7 — el tablero compone (render real)\n";
// Se miran los TOKENS de PHP, no el texto: el docblock de la clase menciona la
// palabra SELECT a proposito (documenta que aqui no se escribe ninguno), y un
// grep plano se acusa a si mismo.
$codigo = '';
foreach (token_get_all($leer('/app/Controllers/Admin/CuadrosEstadisticosController.php')) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $codigo .= is_array($t) ? $t[1] : $t;
}
$chk('CuadrosEstadisticosController no escribe NINGUN SELECT (fuera de comentarios)',
    !preg_match('/\bSELECT\b/i', $codigo));

// ── El `hidden` de la seccion de riesgo tiene que GANAR (07/09/2026) ──
// 🔴 ESTE ASERTO EXISTE POR UN FALLO REAL, y mide el CSS SERVIDO porque desde
// PHP el defecto es invisible: el HTML llevaba el atributo `hidden` puesto y
// correcto, y el elemento se veia igual.
//
// La causa es la cascada: `[hidden] { display: none }` vive en la hoja del
// NAVEGADOR con especificidad (0,0,1), y cualquier `display: flex` escrito
// sobre una clase es (0,1,0) y le gana. Le pasaba a dos cosas de esta seccion:
//   · `.cuadros-riesgo__filtros`, que nace `hidden` para que sin JS no queden
//     en pantalla un buscador que no busca y unos chips que no filtran —o sea,
//     la defensa entera era inerte, y solo se habria notado el dia que fallara
//     el JS—;
//   · `.orden-chip`, que es `inline-flex`: el JS ocultaba los chips de los
//     grados de otro nivel y se seguian viendo los once.
//
// Es un aserto de CADENA sobre el CSS compilado, como los de
// `verif_banners_aviso.php`: no ejecuta el navegador, pero si alguien reescribe
// el bloque y se lleva la regla por delante, aqui falla.
$css = $leer('/public/css/app.css');
foreach ([
    '.cuadros-riesgo__filtros[hidden]' => 'la barra de filtros',
    '.orden-chip[hidden]'              => 'los chips de grado',
] as $regla => $queEs) {
    $chk("el CSS servido deja que `hidden` oculte $queEs",
        str_contains($css, $regla) && str_contains($css, $regla . '{display:none'),
        str_contains($css, $regla) ? $regla : "falta $regla en app.css (¿sin gulp build?)");
}

// ── Responsive de /admin/cuadros (07/09/2026) ─────────────────────────
// 🔴 SE MIDE EL CSS SERVIDO PORQUE NINGUNA PRUEBA DE SERVIDOR VE UNA MEDIA
// QUERY. El HTML es idéntico en móvil y en escritorio: lo único que cambia es
// qué reglas aplican, y eso solo existe en `app.css`. Sin estos asertos, una
// recompilación a medias o un `@media` borrado dejan la pantalla rota en el
// dispositivo que más se usa y todo lo demás sigue en verde.
//
// Línea base medida con un iframe a 371px ANTES de este trabajo: 37 de 45
// tablas con scroll lateral (peor 2,8x) y la barra de filtros ocupando 333px
// de alto. Después: peor 2,2x y 194px.
//
// ⚠️ Son asertos sobre CSS minificado: comprueban que la regla EXISTE, no que se
// vea bien. Lo segundo se mira en el navegador.
//
// ⚠️ Y van con REGEX, no con `str_contains` de la declaración literal, porque
// AUTOPREFIXER REORDENA Y AÑADE PROPIEDADES. La primera versión buscaba
// `.cuadros-riesgo__chips{flex-wrap:nowrap` y falló con la regla correcta ya
// compilada: gulp emite `{-ms-flex-wrap:nowrap;flex-wrap:nowrap;...}` y el
// prefijo se cuela justo delante. Un aserto de CSS compilado no puede dar por
// hecho ni el orden ni la vecindad de las declaraciones.
$movil = [
    '~\.cuadros-top--riesgo\{[^}]*min-width:700px~'
        => 'la tabla de riesgo se comprime en móvil (950 -> 700px)',
    '~\.cuadros-riesgo__chips\{[^}]*flex-wrap:nowrap[^}]*overflow-x:auto~'
        => 'los chips de filtro se desplazan en vez de apilarse',
];
foreach ($movil as $regla => $queEs) {
    $chk("el CSS servido trae $queEs",
        (bool) preg_match($regla, $css),
        preg_match($regla, $css) ? 'presente' : 'no está compilado (¿sin gulp build?)');
}

// `.form-inline` se usaba SIN EXISTIR desde que nació el tablero: la clase
// estaba en el marcado y no había ni una regla para ella en todo el SASS.
$chk('la clase `.form-inline` del selector de bimestre existe en el CSS',
    str_contains($css, '.form-inline{'),
    str_contains($css, '.form-inline{') ? 'definida' : 'se usa en la vista pero no está definida');

// ── El A4 de cuadros no imprime estilos de pantalla (08/09/2026) ──────
// 🔴 ESTA HOJA ES LA UNICA DE LAS 14 QUE REUTILIZA LOS COMPONENTES DE TABLA DE
// PANTALLA (`.tabla-notas`, `.tabla-resumen`). Las otras trece definen su tabla
// desde cero —`.criterios-print__tabla`, `.resumen-print .cuadro-matricula`…— y
// por eso ninguna sufre esta familia de fugas. Compartir los partials entre
// pantalla y papel es CORRECTO (los datos no divergen), pero obliga a un bloque
// de reseteo explícito, y ese reseteo se había ido escribiendo a trocitos.
//
// Lo que fallaba: `.cuadros-print` fija el `font-size` en el `<table>`, pero
// `_tables.scss` lo declara OTRA VEZ sobre `.tabla-notas th` / `.tabla-resumen
// th` con (0,1,1). Un elemento con declaración propia no hereda la de su tabla,
// así que las SEIS tablas del informe imprimían el cuerpo a 8-10px y la
// cabecera a 12px — con `color:#475569` y `white-space:nowrap` de propina.
// Es el mismo fallo que `_cuadros.scss` ya había corregido para UNA celda
// (`.cuadros-top--riesgo tbody th.col-nombre`) sin generalizarlo al `thead`.
//
// ⚠️ REGEX Y NO `str_contains` DE LA DECLARACION LITERAL: además de que
// autoprefixer reordena, cssnano FUSIONA selectores con el mismo cuerpo y los
// reordena alfabéticamente (comprobado: la regla de grises sale como
// `.cuadros-print .cuadros-kpi__t,.cuadros-print .riesgo-detalle__aviso,…`).
// Ni el selector ni el orden de las declaraciones son estables.
$papel = [
    '~\.cuadros-print thead th\{[^}]*font-size:inherit~'
        => 'la cabecera del A4 hereda el tamaño de SU tabla (no los 12px de pantalla)',
    '~\.cuadros-print thead th\{[^}]*white-space:normal~'
        => 'los encabezados largos pueden partirse (sin el `nowrap` de .tabla-resumen)',
    '~\.cuadros-print\{[^}]*min-width:718px~'
        => 'la hoja conserva sus 718px en cualquier ventana (visor fiel)',
    '~\.cuadros-print \.competencia-card__codigo\{[^}]*font-size:inherit~'
        => 'el código de competencia se imprime como texto, no como pastilla naranja',
    '~\.cuadros-print \.cuadros-matriz__den\{[^}]*font-size:7px~'
        => 'el denominador de la matriz es más chico que su celda, no más grande',
    '~\.cuadros-print \.col-nombre\{[^}]*min-width:0~'
        => 'la columna de nombre suelta el suelo de 200px de pantalla',
];
foreach ($papel as $regla => $queEs) {
    $chk("el CSS servido trae que $queEs",
        (bool) preg_match($regla, $css),
        preg_match($regla, $css) ? 'presente' : 'no está compilado (¿sin gulp build?)');
}

// ── Dos asertos que vigilan la CAUSA, no el sintoma ───────────────────
// Son los que habrian atrapado esto el primer dia.

// Si alguien vuelve a escribir un tamaño en px sobre una cabecera del A4, es
// que ha vuelto a COPIAR el valor en vez de heredarlo, y la proxima tabla
// volvera a descuadrarse en silencio.
$chk('ninguna regla de `.cuadros-print` fija en px el tamaño de una cabecera',
    !preg_match('~\.cuadros-print[^{}]*thead th\{[^}]*font-size:\d~', $css),
    'la cabecera debe heredar de su tabla');

// 🔴 `min-width` y `max-width` de la hoja tienen que ser EL MISMO NUMERO. Si
// divergen, el documento vuelve a reflowearse en pantalla estrecha y —lo que
// no se ve venir— los graficos de Frappe vuelven a nacer del ancho de la
// VENTANA: `cuadros.js` se ejecuta antes que `print-fit.js` y mide el
// contenedor en ese momento. Con la hoja fluida, el PDF de un movil salia con
// los 11 graficos al 43 % del ancho del papel.
preg_match('~\.cuadros-print\{([^}]*)\}~', $css, $mmHoja);
$cuerpoHoja = $mmHoja[1] ?? '';
$chk('la hoja de cuadros tiene ancho FIJO (min-width == max-width)',
    str_contains($cuerpoHoja, 'min-width:718px') && str_contains($cuerpoHoja, 'max-width:718px'),
    $cuerpoHoja ?: 'no existe la regla .cuadros-print');

// ── El visor del layout print, que es RAIZ COMPARTIDA (08/09/2026) ────
// ⚠️ ESTOS TRES ASERTOS NO SON DE DIRECCION: vigilan `_boleta.scss` y
// `print-fit.js`, que usan las 14 vistas del layout print. Viven aqui porque
// este es el unico verificador que ya lee el CSS SERVIDO con este metodo y
// porque el cambio existe PARA que funcione el visor de `.cuadros-print`, cuyo
// aserto hermano esta justo arriba. Si algun dia nace un
// `verif_layout_print.php`, su sitio es ese.
$compartida = [
    '~body\.boleta-body\{[^}]*min-width:210mm~'
        => 'la hoja A4 simulada no se encoge en una ventana estrecha',
    '~\.boleta-acciones\{[^}]*--doc-escala-inv~'
        => 'los botones del documento compensan la escala del visor en móvil',
];
foreach ($compartida as $regla => $queEs) {
    $chk("el CSS servido trae que $queEs",
        (bool) preg_match($regla, $css),
        preg_match($regla, $css) ? 'presente' : 'no está compilado (¿sin gulp build?)');
}

// 🔴 LA PROPIEDAD SE PUBLICA EN UNA RAMA Y SE LIMPIA EN LA OTRA, y las dos
// hacen falta. Si se calculara siempre, en escritorio `screen.width` vale 1920
// contra una hoja de 794 y los botones saldrian ENCOGIDOS a 0,41. Y si no se
// limpiara al volver a caber, un telefono que rota se quedaria con los botones
// agrandados. Es la prueba de las DOS ramas de la guarda, no solo de una.
$jsFit = $leer('/resources/js/print-fit.js');
$chk('print-fit.js publica la escala al encoger Y la limpia al volver a caber',
    substr_count($jsFit, "setProperty(\n                '--doc-escala-inv'") === 1
        || (substr_count($jsFit, "'--doc-escala-inv'") === 2
            && str_contains($jsFit, "removeProperty('--doc-escala-inv')")),
    substr_count($jsFit, "'--doc-escala-inv'") . ' referencia(s) a la propiedad');

$anio = new App\Models\AnioAcademicoModel();
$periodos = $anio->query("SELECT p.id, p.numero, p.nombre_display, p.estado, p.anio_id, a.anio
    FROM periodos p INNER JOIN anios_academicos a ON a.id = p.anio_id
    WHERE p.estado IN ('activo','cerrado') ORDER BY a.anio DESC, p.numero ASC");

foreach ($periodos as $p) {
    $pid = (int) $p['id'];
    $aid = (int) $p['anio_id'];
    $etiquetaP = $p['nombre_display'] . ' ' . $p['anio'];

    $conductaModel = new App\Models\ConductaModel();
    $asisModel     = new App\Models\AsistenciaModel();

    $conducta = $conductaModel->getResumenSeccionesPorPeriodo($pid);
    $asis     = $asisModel->getProgresoPorSeccion($pid);

    // ── La evolucion anual NO puede divergir del resumen del bimestre ──
    // getEvolucionAnual() duplica a proposito el SQL del bloque 1 de
    // getResumenBimestre(). Esta asercion es lo que hace segura esa
    // duplicacion: los compara CELDA A CELDA contra datos reales, no contra
    // el texto de la consulta. Si alguien mueve un umbral o el universo en
    // uno solo de los dos, aqui salta.
    $evo     = $anio->getEvolucionAnual($aid);
    $resumen = $anio->getResumenBimestre($pid);

    $celdas = [];
    foreach ($evo['niveles'] as $nv) {
        foreach ($nv['serie'] as $celda) {
            if ((int) $celda['periodo_id'] === $pid) {
                $celdas[(int) $nv['nivel_id']] = $celda;
            }
        }
    }

    $descuadres = [];
    foreach ($resumen['niveles'] as $nv) {
        $celda = $celdas[(int) $nv['nivel_id']] ?? null;
        if ($celda === null) {
            $descuadres[] = $nv['nivel_nombre'] . ': la evolucion no trae este bimestre';
            continue;
        }
        foreach (['ad', 'a', 'b', 'c', 'total_calif'] as $k) {
            if ((int) $celda[$k] !== (int) $nv[$k]) {
                $descuadres[] = $nv['nivel_nombre'] . ".$k: evolucion " . (int) $celda[$k]
                    . ' vs resumen ' . (int) $nv[$k];
            }
        }
    }
    $chk("la evolucion anual cuadra con el resumen de $etiquetaP",
        empty($descuadres),
        $descuadres ? $descuadres[0] : count($resumen['niveles']) . ' nivel(es) contrastado(s)');

    // Frappe Charts exige values.length === labels.length; un hueco desplaza
    // la linea entera SIN dar error. El relleno lo hace el modelo, asi que
    // se comprueba aqui y no en la vista.
    $desparejas = [];
    foreach ($evo['niveles'] as $nv) {
        if (count($nv['serie']) !== count($evo['periodos'])) {
            $desparejas[] = $nv['nivel_nombre'] . ': ' . count($nv['serie'])
                . ' puntos para ' . count($evo['periodos']) . ' bimestres';
        }
    }
    $chk("las series de la evolucion son paralelas al eje X ($etiquetaP)",
        empty($desparejas),
        $desparejas ? $desparejas[0] : count($evo['periodos']) . ' bimestre(s) en el eje');

    $resConducta = ['secciones' => count($conducta), 'cerradas' => 0, 'pend_tutor' => 0, 'pend_auxiliar' => 0, 'esperados' => 0, 'calificados' => 0];
    foreach ($conducta as $f) {
        $resConducta['esperados']   += (int) $f['esperados'];
        $resConducta['calificados'] += (int) $f['calificados'];
        if (!empty($f['tutor_cerrado_en']))      { $resConducta['cerradas']++; }
        elseif (!empty($f['ra_bloqueado_en']))   { $resConducta['pend_tutor']++; }
        else                                     { $resConducta['pend_auxiliar']++; }
    }
    $resAsis = ['secciones' => count($asis), 'completas' => 0, 'esperados' => 0, 'registrados' => 0];
    foreach ($asis as $a) {
        $resAsis['esperados'] += (int) $a['esperados'];
        $resAsis['registrados'] += (int) $a['registrados'];
        if ((int) $a['esperados'] > 0 && (int) $a['registrados'] >= (int) $a['esperados']) { $resAsis['completas']++; }
    }

    $datos = [
        'periodos' => $periodos,
        'periodo'  => $p,
        'bloques'  => [
            'matricula'      => (new App\Models\MatriculaModel())->getResumen($aid),
            'calificaciones' => $resumen,
            'evolucion'      => $evo,
            // Detalle crudo por seccion: el controlador ya lo trae para
            // resumirConducta() y la vista lo grafica sin consultar de nuevo.
            'conducta_secciones' => $conducta,
            'merito'         => $anio->getStatsCierre($pid),
            'empates'        => (new App\Models\OrdenMeritoModel())->gradosConEmpatesPendientes($pid),
            'reaperturas'    => $anio->getReaperturas($pid),
            'conducta'       => $resConducta,
            'asistencia'     => $resAsis,

            // Resultado de conducta y asistencia (27/08/2026). Si estas claves
            // faltaran aqui, las lecturas `??` de las vistas se quedarian sin
            // cubrir y este verificador seguiria verde con la pantalla rota.
            'conducta_literales'   => $conductaModel->getDistribucionLiteralesAnual($aid),
            'conducta_criterios'   => $conductaModel->getIncumplimientoCriterios($pid),
            'asistencia_secciones' => $asisModel->getIncidenciasPorSeccion($pid),
            'asistencia_top'       => $asisModel->getTopIncidenciasPorSeccion($pid),
            'asistencia_evolucion' => $asisModel->getEvolucionIncidenciasAnual($aid),
        ],
    ];

    // Render REAL, capturando cualquier warning/notice como fallo.
    //
    // 🔴 EN SU PROPIO AMBITO, no en el de este script. Con `extract()` + include
    // aqui mismo, cualquier variable de la vista o de sus partials pisa las de
    // este verificador: paso de verdad el 27/08/2026: un `foreach (... as $p)`
    // dentro de un partial se llevo por delante `$p`, la fila del periodo, y el
    // imprimible reventó DESPUÉS con un error que no tenía nada que ver.
    // `Core\View` ya renderiza dentro de un metodo, asi que la aplicacion real
    // nunca corrio ese riesgo; este script sí.
    $render = static function (array $vars, string $vista): string {
        ob_start();
        extract($vars);
        include ROOT_PATH . '/resources/views/admin/cuadros/' . $vista . '.php';
        return (string) ob_get_clean();
    };

    $errores = [];
    set_error_handler(function ($no, $str) use (&$errores) { $errores[] = $str; return true; });
    $html = $render($datos, 'index');
    restore_error_handler();

    $chk("cuadros renderiza $etiquetaP sin avisos",
        empty($errores) && strlen($html) > 2000,
        $errores ? $errores[0] : strlen($html) . ' bytes · conducta ' . $resConducta['cerradas'] . '/' . $resConducta['secciones']
            . ' · asistencia ' . $resAsis['registrados'] . '/' . $resAsis['esperados']);

    // ── Contrato de las pestañas ──────────────────────────────────────
    // Sin esto, una pestaña sin panel (o un panel sin pestaña) deja un bloque
    // entero invisible y NADA falla: la pagina renderiza perfecta y con menos
    // contenido del que deberia.
    preg_match_all('~data-tab="([^"]+)"~', $html, $mTabs);
    preg_match_all('~data-panel="([^"]+)"~', $html, $mPanels);
    $huerfanos = array_merge(
        array_diff($mTabs[1] ?? [], $mPanels[1] ?? []),
        array_diff($mPanels[1] ?? [], $mTabs[1] ?? [])
    );

    // Y cada `aria-controls` tiene que apuntar a un id que exista.
    preg_match_all('~aria-controls="([^"]+)"~', $html, $mCtrl);
    foreach ($mCtrl[1] ?? [] as $idPanel) {
        if (!str_contains($html, 'id="' . $idPanel . '"')) {
            $huerfanos[] = "aria-controls=$idPanel sin destino";
        }
    }

    $chk("las pestañas de $etiquetaP cuadran con sus paneles",
        empty($huerfanos),
        $huerfanos ? implode(', ', $huerfanos) : count($mTabs[1] ?? []) . ' pestaña(s)');

    // Exactamente una activa por grupo, y su panel sin `hidden`: sin JavaScript
    // la pagina tiene que mostrar algo, y con el, tabs.js parte de ese estado.
    $activas = substr_count($html, 'aria-selected="true"');
    $grupos  = substr_count($html, 'role="tablist"');
    $chk("cada grupo de pestañas de $etiquetaP nace con UNA activa",
        $grupos > 0 && $activas === $grupos,
        "$activas activa(s) para $grupos grupo(s)");

    // 🔴 CADA SERIE CALCULADA TIENE QUE TENER SU CONTENEDOR. Es el fallo mas
    // silencioso de esta pantalla: `_chart-data.php` produce el dato, el JSON
    // lo lleva al navegador, cuadros.js registra la fabrica... y no hay ningun
    // <div> donde dibujarlo. No hay error, no hay hueco: el grafico simplemente
    // no existe. Se descubrio asi el 27/08/2026, con la evolucion de conducta
    // desapareciendo del bimestre en curso justo cuando mas sirve.
    preg_match_all('~<div id="(chart-[a-z-]+)"~', $html, $mCharts);
    $contenedores = $mCharts[1] ?? [];

    // clave de $chartData -> id del contenedor que la dibuja.
    $mapaGraficos = [
        'evolucion'          => 'chart-evolucion',
        'brecha'             => 'chart-brecha',
        'conductaEmbudo'     => 'chart-conducta-embudo',
        'conductaSecciones'  => 'chart-conducta-secciones',
        'conductaLiterales'  => 'chart-conducta-literales',
        'conductaEvolucion'  => 'chart-conducta-evolucion',
        'conductaCriterios'  => 'chart-conducta-criterios',
        'asisFaltas'         => 'chart-asis-faltas',
        'asisTardanzas'      => 'chart-asis-tardanzas',
        'asisEvolucion'      => 'chart-asis-evolucion',
        'asisJustificacion'  => 'chart-asis-justificacion',
    ];

    $sinContenedor = [];
    if (preg_match('~<script type="application/json" id="cuadros-data">(.*?)</script>~s', $html, $mTmp)) {
        foreach (array_keys((array) json_decode($mTmp[1], true)) as $clave) {
            $destino = $mapaGraficos[$clave] ?? null;
            if ($destino === null) {
                $sinContenedor[] = "$clave no esta en el mapa del verificador";
            } elseif (!in_array($destino, $contenedores, true)) {
                $sinContenedor[] = "$clave sin <div id=\"$destino\">";
            }
        }
    }

    $chk("cada grafico de $etiquetaP tiene donde dibujarse",
        empty($sinContenedor),
        $sinContenedor ? $sinContenedor[0] : count($contenedores) . ' contenedor(es)');

    // ── Cada seccion lleva SU tabla, con SU encabezado ────────────────
    // Requisito explicito (28/08/2026): con una sola tabla de 180 filas el
    // encabezado de columnas se iba de pantalla al desplazarse y los numeros
    // dejaban de significar nada. Es justo lo que una futura "simplificacion"
    // volveria a juntar, y nada mas lo impediria.
    $nSecciones = count($datos['bloques']['asistencia_top']);
    $nBloques   = substr_count($html, 'class="cuadros-top__bloque"');

    // Una tabla por seccion, y cada una con su <caption> y su <thead>. Ambos se
    // cuentan DENTRO de las tablas de este partial, nunca como clases sueltas
    // por la pagina: desde el 04/09/2026 el listado de "estudiantes en riesgo"
    // reutiliza estas mismas clases base, y un `substr_count` global sumaba sus
    // tablas a estas. El `<table>` de aquel lleva el modificador `--riesgo`, asi
    // que este patron —con la comilla de cierre— no lo captura.
    preg_match_all('~<table class="tabla-notas cuadros-top">(.*?)</table>~s', $html, $mTablas);
    $nTablas     = count($mTablas[1] ?? []);
    $sinCabecera = 0;
    foreach ($mTablas[1] ?? [] as $cuerpo) {
        if (!str_contains($cuerpo, '<caption') || !str_contains($cuerpo, '<thead>')) {
            $sinCabecera++;
        }
    }

    $chk("cada seccion de $etiquetaP tiene su propia tabla con encabezado",
        $nTablas === $nSecciones && $nBloques === $nSecciones && $sinCabecera === 0,
        $sinCabecera > 0
            ? "$sinCabecera tabla(s) sin caption o sin thead"
            : "$nTablas tabla(s) para $nSecciones seccion(es)");

    // ── Estudiantes en riesgo (04/09/2026) ────────────────────────────
    // Mismo contrato que el listado de arriba, una tabla por GRADO. Los
    // modificadores `--riesgo` son los que hacen que estos conteos no se mezclen
    // con los de inasistencias, que usan las mismas clases base.
    $gradosRiesgo = array_values(array_filter(
        $datos['bloques']['merito']['por_grado'],
        static fn(array $g): bool => !empty($g['en_riesgo'])
    ));
    $nRiesgo = count($gradosRiesgo);
    $bRiesgo = substr_count($html, 'cuadros-top__bloque--riesgo');

    // El caption y el thead se comprueban DENTRO de cada tabla de riesgo, no
    // contando clases sueltas por la pagina: asi el aserto no puede cuadrar por
    // casualidad con las tablas del listado de inasistencias.
    preg_match_all(
        '~<table class="tabla-notas cuadros-top cuadros-top--riesgo">(.*?)</table>~s',
        $html, $mRiesgo
    );
    $tRiesgo = count($mRiesgo[1] ?? []);
    $mancas  = 0;
    foreach ($mRiesgo[1] ?? [] as $cuerpo) {
        if (!str_contains($cuerpo, '<caption') || !str_contains($cuerpo, '<thead>')) {
            $mancas++;
        }
    }

    $chk("estudiantes en riesgo de $etiquetaP: una tabla por grado, con encabezado",
        $tRiesgo === $nRiesgo && $bRiesgo === $nRiesgo && $mancas === 0,
        $mancas > 0
            ? "$mancas tabla(s) sin caption o sin thead"
            : "$tRiesgo tabla(s) para $nRiesgo grado(s) con casos");

    // La seccion SIEMPRE esta, con o sin casos: si desaparece cuando nadie llega
    // al umbral, el lector no distingue "no hay nadie en riesgo" de "se rompio".
    $chk("la seccion de riesgo existe en $etiquetaP aunque este vacia",
        str_contains($html, 'id="cuadros-g-riesgo"'),
        $nRiesgo > 0 ? "$nRiesgo grado(s)" : 'sin casos, con estado vacio');

    // Coherencia del dato: todo el que aparece llega al umbral, y el conteo de
    // filas cuadra con lo que devolvio el modelo. Sin esto, un `>=` mal escrito
    // en la vista listaria a gente de mas y las tablas seguirian bien formadas.
    $umbral   = (int) $datos['bloques']['merito']['riesgo_min_c'];
    $bajoUmbral = 0;
    $filas      = 0;
    foreach ($gradosRiesgo as $g) {
        foreach ($g['en_riesgo'] as $al) {
            $filas++;
            if ((int) $al['num_c'] < $umbral) { $bajoUmbral++; }
        }
    }
    $chk("nadie por debajo de $umbral C en la lista de $etiquetaP",
        $bajoUmbral === 0,
        $bajoUmbral > 0 ? "$bajoUmbral fila(s) indebidas" : "$filas fila(s)");

    // ── El JSON que consume cuadros.js ────────────────────────────────
    // Es el unico contrato de la pantalla que PHP no puede romper de forma
    // visible: un desajuste entre labels y values no da error, solo dibuja
    // un grafico corrido. Y con $chartData vacio, PHP serializa [] y el JS
    // encontraria undefined sin que nadie se entere.
    // Se inicializa FUERA del `if`: un bimestre sin datos no emite el tag, y
    // los asertos del A4 de mas abajo lo leen igual. Sin esto, ese caso daba
    // "variable indefinida" justo en el escenario menos probado.
    $nGraficos = 0;

    if (preg_match('~<script type="application/json" id="cuadros-data">(.*?)</script>~s', $html, $mJson)) {
        $payload = json_decode($mJson[1], true);
        $problemas = [];

        if (!is_array($payload)) {
            $problemas[] = 'json_decode fallo: ' . json_last_error_msg();
        } else {
            if (isset($payload['evolucion'])) {
                $nLabels = count($payload['evolucion']['labels']);
                foreach ($payload['evolucion']['datasets'] as $ds) {
                    if (count($ds['values']) !== $nLabels) {
                        $problemas[] = 'evolucion/' . $ds['name'] . ': ' . count($ds['values'])
                            . ' valores para ' . $nLabels . ' etiquetas';
                    }
                }
            }
            $conEjes = [
                ['brecha',             ['mejor', 'peor']],
                ['conductaEmbudo',     ['values']],
                ['conductaSecciones',  ['values']],
                // El eje de criterios lleva ADEMAS el texto completo de cada
                // uno para el tooltip: un eje de codigos C1..C10 no dice nada
                // solo. Si `textos` se descuadra, el tooltip miente.
                ['conductaCriterios',  ['values', 'textos']],
                ['asisFaltas',         ['values']],
                ['asisTardanzas',      ['values']],
            ];
            foreach ($conEjes as [$clave, $ejes]) {
                if (!isset($payload[$clave])) {
                    continue;
                }
                $nLabels = count($payload[$clave]['labels']);
                foreach ($ejes as $eje) {
                    if (count($payload[$clave][$eje]) !== $nLabels) {
                        $problemas[] = "$clave/$eje: " . count($payload[$clave][$eje])
                            . " valores para $nLabels etiquetas";
                    }
                }
            }

            // Las de datasets se comprueban igual que `evolucion`.
            foreach (['conductaLiterales', 'conductaEvolucion', 'asisEvolucion', 'asisJustificacion'] as $clave) {
                if (!isset($payload[$clave])) {
                    continue;
                }
                $nLabels = count($payload[$clave]['labels']);
                foreach ($payload[$clave]['datasets'] as $ds) {
                    if (count($ds['values']) !== $nLabels) {
                        $problemas[] = "$clave/" . ($ds['name'] ?? '?') . ': ' . count($ds['values'])
                            . " valores para $nLabels etiquetas";
                    }
                }
            }
        }

        $chk("el JSON de graficos de $etiquetaP es valido y esta cuadrado",
            empty($problemas),
            $problemas ? $problemas[0] : implode(', ', array_keys($payload)));

        // ── Tabla de valores por grafico (04/09/2026) ─────────────────
        // Gemelo del aserto "cada grafico tiene donde dibujarse", que nacio de
        // un grafico con JSON y sin <div>. Aqui el fallo simetrico: un grafico
        // dibujado cuyos numeros solo existen en el tooltip — invisible en
        // papel, en movil y para quien navega con teclado.
        $nGraficos = count($payload);   // inicializado a 0 mas arriba
        $nTablasV  = substr_count($html, 'class="tabla-notas cuadros-valores__tabla"');
        $chk("cada grafico de $etiquetaP tiene su tabla de valores",
            $nTablasV === $nGraficos,
            "$nTablasV tabla(s) para $nGraficos grafico(s)");

        // Coherencia celda a celda contra el MISMO JSON que dibuja el grafico.
        // Si la tabla se armara por su cuenta, papel y pantalla podrian decir
        // cifras distintas del mismo bimestre sin que nada fallara.
        //
        // Cada tabla se asocia a SU grafico por posicion: es la primera que
        // aparece detras del <div id="chart-…"> correspondiente. Comprobar solo
        // que el numero exista "en alguna parte del HTML" seria un aserto
        // inerte —casi cualquier cifra aparece en una pagina de 300 KB—, que es
        // peor que no tenerlo.
        $idDeClave = [
            'evolucion' => 'chart-evolucion',                'brecha' => 'chart-brecha',
            'conductaEmbudo' => 'chart-conducta-embudo',     'conductaSecciones' => 'chart-conducta-secciones',
            'conductaLiterales' => 'chart-conducta-literales', 'conductaEvolucion' => 'chart-conducta-evolucion',
            'conductaCriterios' => 'chart-conducta-criterios', 'asisFaltas' => 'chart-asis-faltas',
            'asisTardanzas' => 'chart-asis-tardanzas',       'asisEvolucion' => 'chart-asis-evolucion',
            'asisJustificacion' => 'chart-asis-justificacion',
        ];

        $descuadres = [];
        $contrastados = 0;
        foreach ($payload as $clave => $d) {
            $ancla = $idDeClave[$clave] ?? null;
            if ($ancla === null) {
                $descuadres[] = "$clave: grafico sin id conocido en el verificador";
                continue;
            }

            $pos = strpos($html, 'id="' . $ancla . '"');
            if ($pos === false) {
                $descuadres[] = "$clave: no hay <div id=\"$ancla\">";
                continue;
            }

            // Primera tabla de valores por detras de ese div.
            if (!preg_match(
                '~<table class="tabla-notas cuadros-valores__tabla">(.*?)</table>~s',
                substr($html, $pos), $mTabla
            )) {
                $descuadres[] = "$clave: sin tabla de valores detras de su grafico";
                continue;
            }

            // Celdas numericas de esa tabla, en orden de lectura (fila a fila).
            preg_match_all('~<td class="text-center">\s*([^<\s]+)\s*</td>~', $mTabla[1], $mCeldas);
            $enTabla = $mCeldas[1];

            // El JSON va por SERIES (columnas) y la tabla por FILAS: se
            // transpone antes de comparar, o el orden no coincidiria nunca.
            if (isset($d['datasets'])) {
                $series = array_map(static fn($ds) => $ds['values'], $d['datasets']);
            } elseif (isset($d['mejor'])) {
                $series = [$d['mejor'], $d['peor']];
            } else {
                $series = [$d['values'] ?? []];
            }

            $esperado = [];
            foreach (($d['labels'] ?? []) as $i => $_) {
                foreach ($series as $s) {
                    $esperado[] = (string) ($s[$i] ?? '');
                }
            }

            if (count($enTabla) !== count($esperado)) {
                $descuadres[] = "$clave: " . count($enTabla) . ' celdas para '
                    . count($esperado) . ' valores';
                continue;
            }
            foreach ($esperado as $i => $v) {
                if ($enTabla[$i] !== $v) {
                    $descuadres[] = "$clave celda $i: tabla '{$enTabla[$i]}' vs json '$v'";
                    break;
                }
            }
            $contrastados++;
        }
        $chk("los valores tabulados de $etiquetaP salen del mismo JSON",
            empty($descuadres),
            $descuadres ? $descuadres[0] : "$contrastados grafico(s), celda a celda");
    } else {
        // Sin datos no se emite el tag: es correcto, pero que se vea.
        $chk("cuadros omite el JSON de graficos en $etiquetaP (sin datos)", true, 'sin tag cuadros-data');
    }

    // ── La version A4 tambien se renderiza de verdad ──────────────────
    // Comparte datos con la pantalla, pero es OTRA vista: sin esto, una
    // clave que solo ella lea (directorEbr, por ejemplo) reventaria en
    // produccion con el verificador en verde.
    $datosPrint = [
        'titulo'      => 'Cuadros estadisticos',
        'periodo'     => $p,
        'bloques'     => $datos['bloques'],
        'directorEbr' => (new App\Models\DirectorEbrModel())->getVigenteEnFecha($aid),
    ];

    $erroresPrint = [];
    set_error_handler(function ($no, $str) use (&$erroresPrint) { $erroresPrint[] = $str; return true; });
    $htmlPrint = $render($datosPrint, 'imprimir');
    restore_error_handler();

    $chk("el imprimible A4 renderiza $etiquetaP sin avisos",
        empty($erroresPrint) && strlen($htmlPrint) > 2000,
        $erroresPrint ? $erroresPrint[0] : strlen($htmlPrint) . ' bytes'
            . ($datosPrint['directorEbr'] ? ' · con sello' : ' · sin director vigente'));

    // El papel se lee entero de una vez: si heredara las pestañas, dos tercios
    // del informe saldrian en blanco y nadie lo notaria hasta imprimirlo.
    $chk("el imprimible de $etiquetaP no lleva pestañas ni paneles ocultos",
        !str_contains($htmlPrint, 'role="tab"')
            && !str_contains($htmlPrint, 'role="tablist"')
            && !preg_match('~<div[^>]*\shidden~', $htmlPrint),
        'sin role=tab ni hidden');

    // ── Toda tabla del A4 tiene que estar dimensionada (08/09/2026) ───
    // 🔴 LA RED QUE FALTABA. El informe reutiliza los componentes de tabla de
    // PANTALLA, cuyo `th` trae su propio `font-size:12px` desde
    // `_tables.scss`. Una tabla nueva que no lleve una de estas clases no la
    // dimensiona nadie: se imprimira a 14px con el resto de la hoja a 8px, sin
    // ningun error y sin que ningun otro aserto lo note. Asi empezo esto.
    // Se mide sobre el HTML RENDERIZADO, no sobre la vista, para que cuente
    // tambien las tablas que emiten los partials compartidos.
    $dimensionadas = ['tabla-resumen', 'cuadros-valores__tabla', 'cuadros-matriz',
                      'cuadros-top', 'riesgo-detalle__tabla'];
    preg_match_all('~<table[^>]*class="([^"]*)"~', $htmlPrint, $mTablas);
    foreach (array_unique($mTablas[1]) as $clasesTabla) {
        $cubierta = (bool) array_filter(
            $dimensionadas,
            static fn(string $c): bool => str_contains($clasesTabla, $c)
        );
        $chk("en $etiquetaP la tabla `$clasesTabla` del A4 tiene tamaño de letra propio",
            $cubierta,
            $cubierta ? 'dimensionada por .cuadros-print' : 'heredaria los 12px de pantalla');
    }

    // ── Las tablas de valores en el A4 (04/09/2026) ───────────────────
    // 🔴 NI UN `<details>` EN EL PAPEL. Un `<details>` cerrado no imprime su
    // contenido: la tabla saldria en blanco, sin ningun error, y el informe
    // volveria a quedarse sin los numeros que este trabajo vino a poner. Es el
    // mismo motivo por el que el explorador de criterios tiene vista aparte —y
    // ya hay un aserto gemelo para aquel, arriba en este mismo archivo.
    $tablasA4 = substr_count($htmlPrint, 'class="tabla-notas cuadros-valores__tabla"');
    $chk("el imprimible de $etiquetaP trae sus tablas de valores desplegadas",
        !str_contains($htmlPrint, '<details') && $tablasA4 === $nGraficos,
        str_contains($htmlPrint, '<details')
            ? 'hay un <details>: no se imprimiria'
            : "$tablasA4 tabla(s) para $nGraficos grafico(s)");

    // La tabla es HERMANA de `.cuadros-print__chart`, nunca su hija: ese
    // contenedor lleva `page-break-inside: avoid` y con la tabla dentro el
    // bloque entero saltaria de hoja dejando media pagina en blanco.
    $chk("en $etiquetaP ninguna tabla de valores cuelga de un bloque no partible",
        !preg_match(
            '~<div class="cuadros-print__chart">(?:(?!</div>).)*cuadros-valores__tabla~s',
            $htmlPrint
        ),
        'todas fuera de .cuadros-print__chart');

    // Los KPIs que la pantalla mostraba y el papel no. "Esperan al tutor" y
    // "Esperan al auxiliar" llegaban al A4 SOLO por la leyenda del grafico de
    // embudo, que no se registra cuando la suma es cero: el informe podia
    // quedarse sin decir a quien esta esperando el cierre.
    $kpisPapel = ['Esperan al tutor', 'Esperan al auxiliar'];
    $faltan = array_values(array_filter(
        $kpisPapel,
        static fn(string $k): bool => !str_contains($htmlPrint, $k)
    ));
    $chk("el imprimible de $etiquetaP trae los KPIs de proceso de conducta",
        empty($faltan),
        $faltan ? 'falta: ' . $faltan[0] : implode(' · ', $kpisPapel));

    // Cada grafico impreso lleva su nota de lectura: en papel nadie puede
    // preguntar que significa lo que esta viendo.
    $chk("cada grafico impreso de $etiquetaP lleva su nota de lectura",
        substr_count($htmlPrint, 'cuadros-print__nota') === $nGraficos,
        substr_count($htmlPrint, 'cuadros-print__nota') . " nota(s) para $nGraficos grafico(s)");

    // ── Rediseño de "Estudiantes en riesgo" (07/09/2026) ──────────────
    // El partial es UNO y las superficies son DOS, separadas por el flag
    // `$riesgoInteractivo` que pone el llamador. Lo que se vigila aqui es
    // exactamente ese reparto: el DATO va a las dos, el CONTROL solo a la
    // pantalla. Sin aserto, "arreglar" la variable que le falta al A4 —que
    // parece un olvido y no lo es— imprime un buscador en cada informe.
    $res = riesgo_resumen($datos['bloques']['merito']['por_grado']);

    $chk("la banda de riesgo de $etiquetaP esta en pantalla y en papel, con la misma cifra",
        $nRiesgo === 0
            ? !str_contains($html, 'cuadros-banda--riesgo') && !str_contains($htmlPrint, 'cuadros-banda--riesgo')
            : str_contains($html, 'cuadros-banda--riesgo')
                && str_contains($htmlPrint, 'cuadros-banda--riesgo')
                && substr_count($html, '>' . $res['total'] . '</span>') > 0,
        $nRiesgo === 0
            ? 'sin casos: no hay banda que pintar'
            : $res['total'] . ' estudiante(s) · ' . $res['pct'] . '% de ' . $res['evaluados']);

    // El total de la banda sale del PUNTO UNICO `riesgo_resumen()`, no de una
    // suma escrita a mano en la vista: si alguien la vuelve a sumar in situ,
    // este aserto sigue verde pero el de abajo —la cuenta de filas— es el que
    // ata la cifra al dato.
    // El PAR merito <-> riesgo: las dos bandas existen y llevan acentos DISTINTOS.
    // Sin esto, un refactor que dejara las dos con el mismo modificador borraria
    // en silencio la unica pista visual que separa "los mejores" de "los que
    // necesitan apoyo", y la pagina seguiria renderizando perfecta.
    $conRanking = !empty($datos['bloques']['merito']['por_grado']);
    foreach ([['pantalla', $html], ['papel', $htmlPrint]] as [$dondeB, $docB]) {
        $chk("el par merito/riesgo se distingue en $dondeB de $etiquetaP",
            substr_count($docB, 'cuadros-banda--merito') === ($conRanking ? 1 : 0)
                && substr_count($docB, 'cuadros-banda--riesgo') === ($nRiesgo > 0 ? 1 : 0),
            'merito=' . substr_count($docB, 'cuadros-banda--merito')
                . ' riesgo=' . substr_count($docB, 'cuadros-banda--riesgo'));
    }

    $chk("la cifra de la banda de $etiquetaP cuadra con las filas listadas",
        $res['total'] === $filas,
        $res['total'] . ' en la banda · ' . $filas . ' fila(s) en las tablas');

    // Los controles: en pantalla si, en papel NO. Y en pantalla nacen `hidden`
    // —los destapa cuadros-riesgo.js—, para que sin JS no queden un buscador
    // que no busca y unos chips que no filtran.
    $controles = ['id="riesgo-filtros"', 'id="riesgo-contador"', 'id="riesgo-sin-resultados"'];
    $enPantalla = $enPapel = 0;
    foreach ($controles as $c) {
        if (str_contains($html, $c))      { $enPantalla++; }
        if (str_contains($htmlPrint, $c)) { $enPapel++; }
    }
    $chk("los controles de riesgo de $etiquetaP son de pantalla, no de papel",
        $enPapel === 0 && $enPantalla === ($nRiesgo > 0 ? count($controles) : 0),
        $enPapel > 0
            ? "$enPapel control(es) impresos"
            : "$enPantalla en pantalla · 0 en papel");

    $chk("la barra de filtros de $etiquetaP nace oculta (sin JS no hay controles muertos)",
        $nRiesgo === 0 || str_contains($html, 'id="riesgo-filtros" hidden'),
        $nRiesgo === 0 ? 'sin casos' : 'hidden presente');

    // El script que la destapa va FUERA del `if ($chartData)`: un bimestre sin
    // ni un grafico tambien necesita filtrar, y sin el script la barra se queda
    // oculta para siempre.
    $chk("cuadros-riesgo.js se carga en $etiquetaP haya o no graficos",
        str_contains($html, 'js/cuadros-riesgo.js') && !str_contains($htmlPrint, 'cuadros-riesgo.js'),
        $nGraficos . ' grafico(s) en este bimestre');

    // ── Desglose de las C (07/09/2026) ────────────────────────────────
    // El desglose va a las DOS superficies, pero de forma distinta: en pantalla
    // dentro de un `<details>` plegado, en papel suelto. La diferencia la pone
    // el mismo flag `$riesgoInteractivo`.
    //
    // 🔴 EL ASERTO QUE DE VERDAD IMPORTA ES EL DEL PAPEL, y ya existe unas
    // lineas mas arriba: `!str_contains($htmlPrint, '<details')`. Un `<details>`
    // cerrado NO IMPRIME SU CONTENIDO, asi que si alguien "simplifica" el
    // partial y emite el `<details>` tambien en el A4, el informe saldria con
    // 778 filas en blanco y sin ningun error. Aqui se comprueba lo
    // complementario: que el desglose ESTE en el papel.
    $detPantalla = substr_count($html, 'data-riesgo-detalle');
    $detPapel    = substr_count($htmlPrint, 'data-riesgo-detalle');

    $chk("el desglose de C esta en las dos superficies de $etiquetaP",
        $detPantalla === $filas && $detPapel === $filas,
        "pantalla $detPantalla · papel $detPapel · $filas estudiante(s)");

    $chk("el desglose de $etiquetaP se pliega en pantalla y va suelto en papel",
        ($filas === 0 || str_contains($html, '<details class="riesgo-detalle"'))
            && !str_contains($htmlPrint, 'riesgo-detalle"><summary')
            && !str_contains($htmlPrint, '<details'),
        $filas === 0 ? 'sin casos' : substr_count($html, '<details class="riesgo-detalle"') . ' plegado(s) en pantalla, 0 en papel');

    // 🔴 LA FILA DEL DESGLOSE NO PUEDE LLEVAR `data-riesgo-fila`. Ese atributo
    // es lo que `cuadros-riesgo.js` cuenta para el TOTAL y para "Mostrando N de
    // 118": si se le colara, el contador diria el doble y nadie veria un error.
    preg_match_all('~<tr class="fila-riesgo-detalle"[^>]*>~', $html, $mDet);
    $contaminadas = 0;
    foreach ($mDet[0] ?? [] as $tag) {
        if (str_contains($tag, 'data-riesgo-fila')) { $contaminadas++; }
    }
    $chk("las filas de desglose de $etiquetaP no cuentan como estudiantes",
        $contaminadas === 0,
        $contaminadas > 0
            ? "$contaminadas fila(s) con data-riesgo-fila"
            : count($mDet[0] ?? []) . ' fila(s) de desglose');

    // ── Indice de anclas ──────────────────────────────────────────────
    // Un ancla a un `id` que la pagina no emitio es un enlace que no lleva a
    // ninguna parte, y Reaperturas es condicional: las entradas se ARMAN.
    preg_match_all('~<a class="orden-chip" href="#([\w-]+)"~', $html, $mAnclas);
    $rotas = array_values(array_filter(
        $mAnclas[1] ?? [],
        static fn(string $id): bool => !str_contains($html, 'id="' . $id . '"')
    ));
    $chk("el indice de $etiquetaP no tiene anclas rotas",
        !empty($mAnclas[1]) && empty($rotas),
        $rotas ? 'rota: #' . $rotas[0] : count($mAnclas[1] ?? []) . ' ancla(s)');

    $chk("el indice de $etiquetaP no se imprime",
        !str_contains($htmlPrint, 'cuadros-indice'));

    // ── La colision de rotulos no vuelve ──────────────────────────────
    // Hasta el 07/09/2026 "En riesgo" nombraba DOS cifras distintas en la misma
    // pantalla: la columna del bloque de Calificaciones (promedio general bajo
    // NOTA_MIN_B, por nivel) y esta seccion (3 C o mas, por grado). En B2 daban
    // 0 y 77. Aquella columna se llama ahora "Promedio en C", asi que el rotulo
    // debe quedar en UN solo sitio: el <h2> de la seccion.
    foreach ([['pantalla', $html], ['papel', $htmlPrint]] as [$dondeEtq, $doc]) {
        $chk("\"En riesgo\" nombra una sola cosa en $dondeEtq de $etiquetaP",
            substr_count($doc, 'En riesgo') === 0,
            substr_count($doc, 'Estudiantes en riesgo') . ' vez/veces "Estudiantes en riesgo"'
                . ' · ' . substr_count($doc, 'Promedio en C') . ' vez/veces "Promedio en C"');
    }

    // ── Coherencia de la distribucion de conducta ─────────────────────
    // Gemelo del aserto que ya compara getEvolucionAnual con getResumenBimestre:
    // los estudiantes con literal compuesto no pueden pasar de los calificados
    // que cuenta el panel del director, que mira la MISMA grilla desde otra
    // consulta. Es <= y no ==: `calificados` exige las 10 respuestas completas,
    // mientras que el I Bimestre (legado) tiene literal sin ninguna respuesta.
    $litTotal = 0;
    foreach ($datos['bloques']['conducta_literales']['niveles'] as $nv) {
        foreach ($nv['serie'] as $celda) {
            if ((int) $celda['periodo_id'] === $pid) {
                $litTotal += (int) $celda['total'];
            }
        }
    }
    $esLegado = empty($datos['bloques']['conducta_criterios']['criterios']);
    $chk("la distribucion de conducta de $etiquetaP cuadra con el avance",
        $esLegado || $litTotal <= (int) $resConducta['esperados'],
        $litTotal . ' con literal de ' . $resConducta['esperados'] . ' esperados'
            . ($esLegado ? ' (bimestre legado)' : ''));

    // ── El literal NO se recalcula por un camino paralelo ─────────────
    // ESTE es el aserto que prueba que `getDistribucionLiteralesAnual` pasa por
    // `componerLiteral()` y no por una copia nueva de la aritmetica.
    //
    // Se contrasta contra `getEstudiantesParaTutor`, que es la OTRA copia de esa
    // aritmetica que ya vive en el repositorio (calcula `literal_final` en PHP,
    // alumno a alumno, para la grilla del tutor). Comparar el agregado nuevo
    // contra ella cubre las dos a la vez: si cualquiera de las tres —el helper,
    // la grilla o el tablero— se desvia, los conteos dejan de cuadrar.
    //
    // El universo tiene que ser identico: mismo roster, mismo periodo, sin mirar
    // el cierre. Por eso se comparan literal a literal y no solo el total.
    $porGrilla  = ['AD' => 0, 'A' => 0, 'B' => 0, 'C' => 0];
    $porTablero = ['AD' => 0, 'A' => 0, 'B' => 0, 'C' => 0];

    foreach ($conducta as $sec) {
        $totalCrit = $conductaModel->totalCriterios((int) $sec['nivel_id']);
        foreach ($conductaModel->getEstudiantesParaTutor((int) $sec['seccion_id'], $pid, $totalCrit) as $al) {
            if (!empty($al['literal_final'])) {
                $porGrilla[$al['literal_final']]++;
            }
        }
    }

    foreach ($datos['bloques']['conducta_literales']['niveles'] as $nv) {
        foreach ($nv['serie'] as $celda) {
            if ((int) $celda['periodo_id'] !== $pid) {
                continue;
            }
            foreach (['AD' => 'ad', 'A' => 'a', 'B' => 'b', 'C' => 'c'] as $lit => $campo) {
                $porTablero[$lit] += (int) $celda[$campo];
            }
        }
    }

    $fmt = static fn(array $x): string => "AD{$x['AD']} A{$x['A']} B{$x['B']} C{$x['C']}";
    $chk("el literal de conducta de $etiquetaP sale de un solo sitio",
        $porGrilla === $porTablero,
        $porGrilla === $porTablero
            ? 'tablero y grilla del tutor coinciden: ' . $fmt($porTablero)
            : 'tablero ' . $fmt($porTablero) . ' != grilla ' . $fmt($porGrilla));
}

// ── DIRECCION SOLO VE BIMESTRES CERRADOS (08/09/2026) ─────────────
//
// Extiende al bimestre entero la "regla del dato oficial": el director ve el
// AVANCE en vivo, pero todo DATO que se le muestre debe estar aprobado y
// bloqueado. Un bimestre a medio llenar da porcentajes, rankings y una linea de
// tendencia con la misma pinta que los del bimestre cerrado.
//
// 🔴 LA MITAD QUE SE ROMPE EN SILENCIO es la otra: `admin` y `registro_academico`
// tienen que SEGUIR viendo el activo. Por eso cada guarda se prueba en sus DOS
// ramas, nunca solo en la restringida.
echo "\nDIRECCION — solo bimestres cerrados\n";

$sesionComo = static function (?string $rol): void {
    // `Session::hasRole()` lee `$_SESSION['auth_user']['rol_codigo']` y no exige
    // sesion iniciada: en CLI basta con poner la clave. Sin ella, `has_role()`
    // devuelve false, que es justo el caso "sin restriccion".
    if ($rol === null) { unset($_SESSION['auth_user']); return; }
    $_SESSION['auth_user'] = ['rol_codigo' => $rol];
};

$ctrlClase = new ReflectionClass(App\Controllers\Admin\CuadrosEstadisticosController::class);
$ctrl      = $ctrlClase->newInstanceWithoutConstructor();
$propModel = $ctrlClase->getProperty('controlModel');
$propModel->setAccessible(true);
$propModel->setValue($ctrl, new App\Models\ControlOperativoModel());

$privado = static function (string $metodo, array $args) use ($ctrlClase, $ctrl) {
    $m = $ctrlClase->getMethod($metodo);
    $m->setAccessible(true);
    return $m->invokeArgs($ctrl, $args);
};

$sesionComo(null);
$todos     = $privado('periodosVisibles', []);
$noCerrado = null;
foreach ($todos as $fila) {
    if (($fila['estado'] ?? '') !== 'cerrado') { $noCerrado = $fila; break; }
}

// ── Rama restringida: los tres roles de Direccion ────────────────
foreach (ROLES_DIRECCION as $rol) {
    $sesionComo($rol);

    $chk("$rol: solo_bimestres_cerrados() lo reconoce", solo_bimestres_cerrados());

    $lista    = $privado('periodosVisibles', []);
    $abiertos = array_values(array_filter($lista, static fn(array $x): bool => $x['estado'] !== 'cerrado'));
    $chk("$rol: la lista del selector trae SOLO bimestres cerrados",
        $lista !== [] && $abiertos === [],
        count($lista) . ' bimestre(s), ' . count($abiertos) . ' abierto(s)');

    // El defecto es el ULTIMO cerrado. `getPeriodos()` ordena `anio DESC,
    // numero ASC`, asi que `[0]` es el bimestre 1: el mas VIEJO del anio.
    [$porDefecto, $fuera] = $privado('elegirPeriodo', [$lista, 0]);
    $mayor = max(array_map(static fn(array $x): int => (int) $x['numero'], $lista));
    $chk("$rol: el bimestre por defecto es el ULTIMO cerrado, no el primero",
        $porDefecto !== null && (int) $porDefecto['numero'] === $mayor && !$fuera,
        $porDefecto ? $porDefecto['nombre_display'] . ' (numero ' . $porDefecto['numero'] . ')' : 'ninguno');

    if ($noCerrado) {
        [$pedido, $fueraPedido] = $privado('elegirPeriodo', [$lista, (int) $noCerrado['id']]);
        $chk("$rol: un ?periodo_id NO cerrado no se resuelve (cae al ultimo cerrado y avisa)",
            $fueraPedido && $pedido !== null && (int) $pedido['id'] !== (int) $noCerrado['id'],
            'pidio ' . $noCerrado['nombre_display'] . ' (' . $noCerrado['estado'] . ') y recibio '
                . ($pedido['nombre_display'] ?? 'nada'));
    }

    $ids = $privado('serieIds', [$lista]);
    $chk("$rol: las series anuales se cortan por ids, y ninguno es de un bimestre abierto",
        is_array($ids) && $ids !== [] && ($noCerrado === null || !in_array((int) $noCerrado['id'], $ids, true)),
        is_array($ids) ? implode(',', $ids) : 'null');
}

// ── Rama que se rompe en silencio: admin y registro academico ────
foreach (['admin', 'registro_academico'] as $rol) {
    $sesionComo($rol);

    $chk("$rol: solo_bimestres_cerrados() NO lo alcanza", !solo_bimestres_cerrados());

    $lista = $privado('periodosVisibles', []);
    $chk("$rol: la lista del selector SIGUE trayendo el bimestre abierto",
        $lista == $todos,
        count($lista) . ' bimestre(s), identica a la de siempre');

    if ($noCerrado) {
        [$pedido, $fueraPedido] = $privado('elegirPeriodo', [$lista, (int) $noCerrado['id']]);
        $chk("$rol: un ?periodo_id NO cerrado SI se resuelve, como hasta hoy",
            !$fueraPedido && $pedido !== null && (int) $pedido['id'] === (int) $noCerrado['id'],
            $pedido['nombre_display'] ?? 'nada');
    }

    $chk("$rol: las series anuales van SIN filtro (null), no con la lista recortada",
        $privado('serieIds', [$lista]) === null);
}
$sesionComo(null);

// ── El corte de las series, en las dos ramas y sin depender de los datos ──
// 🔴 Con fuente SINTETICA a proposito. Medir esto contra la base solo prueba
// algo si el bimestre abierto tiene notas en los DOS niveles; en una maquina
// donde tenga 0, `$bimestresComparables` ya lo descarta por su cuenta y el
// aserto pasaria sin haber ejercido nada.
$serieNivel = static fn(string $n, array $vals): array => [
    'nivel_nombre' => $n,
    'serie' => array_map(
        static fn(array $v): array => [
            'periodo_id' => $v[0], 'total_calif' => 100, 'total' => 100, 'pct_logro' => $v[1],
            'ad' => 10, 'a' => 10, 'b' => 5, 'c' => 5,
        ],
        $vals
    ),
];
$fuenteFalsa = [
    'periodos' => [
        ['id' => 901, 'nombre' => 'B-uno'],
        ['id' => 902, 'nombre' => 'B-dos'],
        ['id' => 903, 'nombre' => 'B-tres'],
    ],
    'niveles' => [
        $serieNivel('Primaria',   [[901, 50], [902, 60], [903, 70]]),
        $serieNivel('Secundaria', [[901, 55], [902, 65], [903, 75]]),
    ],
];
$bloquesFalsos = [
    'evolucion'            => $fuenteFalsa,
    'conducta_literales'   => $fuenteFalsa,
    'asistencia_evolucion' => [
        ['periodo_id' => 901, 'periodo_nombre' => 'B-uno',  'registrados' => 5, 'faltas' => 3, 'tardanzas' => 2],
        ['periodo_id' => 902, 'periodo_nombre' => 'B-dos',  'registrados' => 5, 'faltas' => 4, 'tardanzas' => 1],
        ['periodo_id' => 903, 'periodo_nombre' => 'B-tres', 'registrados' => 5, 'faltas' => 9, 'tardanzas' => 9],
    ],
];

// En su propio ambito, por lo mismo que el render de arriba.
$armarGraficos = static function (array $bloques, ?array $serieIds): array {
    $periodo = ['id' => 902, 'nombre_display' => 'B-dos', 'anio' => 2026, 'anio_id' => 1, 'estado' => 'cerrado'];
    require ROOT_PATH . '/resources/views/admin/cuadros/_chart-data.php';
    return ['datos' => $chartData, 'meta' => $metaGraficos];
};

$sinFiltro = $armarGraficos($bloquesFalsos, null);
$conFiltro = $armarGraficos($bloquesFalsos, [901, 902]);

foreach (['evolucion' => 'G2 calificaciones', 'conductaEvolucion' => 'G7 conducta', 'asisEvolucion' => 'G11 asistencia'] as $g => $queEs) {
    $sin = $sinFiltro['datos'][$g]['labels'] ?? [];
    $con = $conFiltro['datos'][$g]['labels'] ?? [];
    $chk("$queEs: sin filtro el eje trae los tres bimestres", $sin === ['B-uno', 'B-dos', 'B-tres'], implode(' ', $sin));
    $chk("$queEs: con filtro el eje suelta el bimestre abierto", $con === ['B-uno', 'B-dos'], implode(' ', $con));
}

// ⚠️ G6 comparte fuente con G7 pero se recorta al bimestre a la vista: si el
// corte se aplicara a la FUENTE en vez de al eje de las series, desapareceria.
foreach (['sin filtro' => $sinFiltro, 'con filtro' => $conFiltro] as $caso => $res) {
    $chk("G6 (literales de conducta del bimestre) sigue en pie $caso",
        !empty($res['datos']['conductaLiterales']['labels']));
}

// La nota de lectura tiene que decir el criterio REAL de lo que se ve.
foreach (['evolucion', 'conductaEvolucion', 'asisEvolucion'] as $g) {
    $chk("la nota de `$g` nombra el corte solo cuando esta activo",
        str_contains($conFiltro['meta'][$g]['nota'], 'cerrados')
        && !str_contains($sinFiltro['meta'][$g]['nota'], 'cerrados'));
}

// ── Un solo dueño de la regla ────────────────────────────────────
// El corte de las series viaja por IDS: solo G2 expone `estado` en su salida
// —G7 y G11 no—, asi que preguntar por el estado en la vista funcionaria en uno
// de los tres graficos y en los otros dos pasaria de largo sin fallar.
$chartSrc = $leer('/resources/views/admin/cuadros/_chart-data.php');
$chk('_chart-data.php corta por ids, no por estado',
    !str_contains($chartSrc, "'cerrado'"));
$chk('el controlador no vuelve a escribir la comparacion con `cerrado`',
    !str_contains($leer('/app/Controllers/Admin/CuadrosEstadisticosController.php'), "'cerrado'"));

// La UNICA mencion legitima de la constante en el tablero es el `requireRole`
// del constructor, que decide QUIEN ENTRA. Decidir QUE VE es otra pregunta y la
// responde `solo_bimestres_cerrados()`: una segunda mencion aqui seria la regla
// de audiencia escrita a mano por segunda vez.
$ctrlSrc = $leer('/app/Controllers/Admin/CuadrosEstadisticosController.php');
$chk('el controlador nombra ROLES_DIRECCION una sola vez, y es el requireRole del constructor',
    substr_count($ctrlSrc, 'ROLES_DIRECCION') === 1
    && (bool) preg_match('/requireRole\(\[[^\]]*\.\.\.ROLES_DIRECCION\]\)/', $ctrlSrc),
    substr_count($ctrlSrc, 'ROLES_DIRECCION') . ' mencion(es)');

$decideRol = [];
foreach ([
    '/resources/views/admin/cuadros/index.php',
    '/resources/views/admin/cuadros/imprimir.php',
    '/resources/views/admin/cuadros/_chart-data.php',
] as $f) {
    if (str_contains($leer($f), 'ROLES_DIRECCION')) { $decideRol[] = $f; }
}
$chk('ninguna vista del tablero decide la audiencia a mano: la decide solo_bimestres_cerrados()',
    $decideRol === [], implode(', ', $decideRol));

$helpersSrc = $leer('/app/Helpers/helpers.php');
foreach (['solo_bimestres_cerrados', 'periodos_cerrados', 'ultimo_periodo_cerrado'] as $fn) {
    $chk("`$fn()` vive en helpers.php (punto unico)", str_contains($helpersSrc, "function $fn("));
}
echo "\n", $ok ? "== FASES 4-7 EN VERDE ==\n" : "== HAY FALLOS ==\n";
exit($ok ? 0 : 1);
