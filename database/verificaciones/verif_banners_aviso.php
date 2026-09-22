<?php

/**
 * Verificación — EL BANNER DE AVISO, punto único y responsive (02/09/2026).
 * No toca la base de datos: mide el CSS servido y el marcado de las vistas.
 *
 * EL FALLO QUE ESTO ANCLA: `.flash` y `.alert` eran los dos
 * `display: flex; align-items: center` SIN `flex-wrap`. El contenido de un
 * banner es UNA FRASE, y flex la blockifica: cada `<strong>` pasa a ser un item
 * propio y cada tramo de texto contiguo pasa a ser un item ANONIMO. Un banner de
 *
 *     Texto... Cerrado el <strong>02/09/2026</strong> por Juan Perez.
 *
 * no salia como frase sino como TRES columnas raquiticas de alturas distintas
 * que `align-items: center` ademas descuadraba entre si. Medido en Chrome con un
 * banner de 340px ANTES del arreglo: items en x=204 / x=340 / x=431, alturas
 * 143px / 42px / 80px. A 1100px tambien estaba roto, solo que menos obvio.
 *
 * Los banners que se veian bien lo hacian POR CASUALIDAD (un unico nodo de
 * texto = un solo item anonimo). Por eso los asertos miran la PROPIEDAD —que el
 * contenedor no vuelva a ser flex ni grid— y no un padding ni un color: fijar
 * valores convertiria cualquier retoque legitimo del diseno en un fallo.
 *
 * Y ancla ademas el PUNTO UNICO. Habia TRES declarantes del mismo componente:
 * `components/_alerts.scss`, `.flash` en `components/_cards.scss` y una copia
 * entera de `.alert` en `pages/_auth.scss` que —por ir despues en el @import—
 * ganaba EN TODA LA APP. Mismo fallo que el de `.tabla-leyenda` que ancla
 * `verif_zona_resultado.php`.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('VIEW_PATH', ROOT_PATH . '/resources/views');
define('SASS_PATH', ROOT_PATH . '/resources/sass');

$ok  = true;
$chk = function (string $t, bool $c, string $detalle = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $detalle !== '' ? "  ->  $detalle" : '');
    $ok = $ok && $c;
};

$css = file_get_contents(ROOT_PATH . '/public/css/app.css');

/**
 * Devuelve los grupos de selectores cuyo texto contiene $frag, con su cuerpo.
 *
 * ⚠️ SIN prefijo `(?:^|[};])`, a diferencia de `verif_zona_resultado.php`. Alli
 * se usa con `preg_match` (una sola regla) y funciona; con `preg_match_all` NO:
 * el prefijo consume el `}` de cierre, asi que la regla SIGUIENTE se queda sin
 * su delimitador y no casa. Se cae una de cada dos y el aserto acusa al CSS de
 * algo que no pasa. No hace falta: `[^{};]*` ya no puede cruzar un `}`, asi que
 * el selector empieza solo donde acaba la regla anterior.
 */
$reglas = static function (string $css, string $frag): array {
    $out = [];
    if (!preg_match_all('/([^{};]*)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($m as $r) {
        if (str_contains($r[1], $frag)) {
            $out[] = ['selector' => trim($r[1]), 'cuerpo' => $r[2]];
        }
    }
    return $out;
};

// ---- 1. PUNTO UNICO -------------------------------------------------
echo "\n=== 1. Un solo declarante del banner ===\n";

// El grupo BASE: el que declara `display`. Antes habia dos (`_alerts.scss` y la
// copia de `_auth.scss`) mas el de `.flash` en `_cards.scss`.
$base = array_values(array_filter(
    $reglas($css, '.alert'),
    static fn(array $r): bool => preg_match('/^\.alert(,|$)/', $r['selector']) === 1
                                 && str_contains($r['cuerpo'], 'display:')
));

$chk('un unico bloque base declara el banner (antes eran 2)',
    count($base) === 1, count($base) . ' bloque(s)');

if (count($base) !== 1) {
    fwrite(STDERR, "ABORTA: sin un bloque base unico el resto de asertos no significa nada.\n");
    exit(1);
}
$sel = $base[0]['selector'];
$cuerpo = $base[0]['cuerpo'];

// `.flash` comparte ruleset en vez de tener el suyo: es lo que impide que los
// dos nombres vuelvan a divergir en silencio.
$chk('`.flash` comparte el MISMO bloque que `.alert` (alias, no componente aparte)',
    str_contains($sel, '.alert') && str_contains($sel, '.flash'), $sel);

$chk('no queda ningun bloque que declare `.flash` por su cuenta',
    count(array_filter($reglas($css, '.flash'),
        static fn(array $r): bool => preg_match('/^\.flash\{?(,|$)/', $r['selector']) === 1
                                     && !str_contains($r['selector'], '.alert'))) === 0);

// Asertos sobre el FUENTE: impiden que la definicion vuelva a un archivo de pagina.
$auth   = file_get_contents(SASS_PATH . '/pages/_auth.scss');
$cierre = file_get_contents(SASS_PATH . '/pages/_registro-cierre.scss');
$cards  = file_get_contents(SASS_PATH . '/components/_cards.scss');

$chk('pages/_auth.scss ya no redefine `.alert`',
    preg_match('/^\s*\.alert\s*\{/m', $auth) !== 1);
$chk('pages/_registro-cierre.scss ya no se lleva `alert__accion`',
    !str_contains($cierre, 'alert__accion'));
// `components/cards` se importa DESPUES de `components/alerts`: cualquier
// `.flash` que vuelva a ese archivo gana por orden y reimpone el flex.
$chk('components/_cards.scss ya no declara `.flash`',
    preg_match('/^\s*\.flash\s*\{/m', $cards) !== 1);

// ---- 2. LA PROPIEDAD REPARADA ---------------------------------------
echo "\n=== 2. El contenedor deja fluir la frase ===\n";

$chk('el banner NO es flex ni grid (el aserto que ancla la regresion)',
    !preg_match('/(?:^|;)display:\s*(flex|grid|inline-flex|inline-grid)/', $cuerpo),
    trim(explode(';', $cuerpo)[0]));
$chk('declara line-height (la frase tiene que poder envolver)',
    str_contains($cuerpo, 'line-height:'));
$chk('declara overflow-wrap (un nombre largo no desborda la caja)',
    str_contains($cuerpo, 'overflow-wrap:'));
// El hueco del icono va en el padding, no en un `gap`: asi TODAS las lineas
// quedan alineadas bajo la primera en vez de meterse debajo del icono.
$chk('reserva el hueco del icono con padding-left, no con gap',
    str_contains($cuerpo, 'padding-left:') && !str_contains($cuerpo, 'gap:'));

// ---- 3. EL ICONO ----------------------------------------------------
echo "\n=== 3. Icono automatico por variante ===\n";

$antes = array_values(array_filter($reglas($css, '::before'),
    static fn(array $r): bool => str_contains($r['selector'], '.alert')
                                 && str_contains($r['cuerpo'], 'mask-size')));
$chk('el icono existe y sale del flujo (position:absolute)',
    count($antes) === 1 && str_contains($antes[0]['cuerpo'], 'position:absolute'));

// 🔴 El icono se ancla a la PRIMERA LINEA, no al centro del bloque. El
// `align-items:center` de antes lo dejaba flotando en mitad de un banner de
// cuatro lineas, lejos del texto que califica. Se comprueba la relacion, no el
// valor: `top` tiene que coincidir con el padding-top del banner.
preg_match('/(?:^|;)padding:\s*([0-9]+)px/', $cuerpo, $mp);
preg_match('/(?:^|;)top:\s*([0-9]+)px/', $antes[0]['cuerpo'] ?? '', $mt);
$chk('el icono se ancla a la PRIMERA linea (top == padding-top del banner)',
    isset($mp[1], $mt[1]) && $mp[1] === $mt[1],
    ($mp[1] ?? '?') . 'px de padding / ' . ($mt[1] ?? '?') . 'px de top');

foreach (['error', 'success', 'warning', 'info'] as $v) {
    $rv = array_values(array_filter($reglas($css, ".alert--$v"),
        static fn(array $r): bool => str_contains($r['cuerpo'], 'mask-image')));
    $tieneAmbos = count($rv) === 1
        && str_contains($rv[0]['selector'], ".alert--$v")
        && str_contains($rv[0]['selector'], ".flash--$v");
    // Y que el SVG exista de verdad: una ruta rota no da error, deja el banner
    // con un hueco en blanco donde deberia ir el icono.
    $svg = null;
    if ($rv && preg_match('#mask-image:url\(["\']?\.\./([^"\')]+)#', $rv[0]['cuerpo'], $ms)) {
        $svg = ROOT_PATH . '/public/' . $ms[1];
    }
    $chk("la variante --$v pinta icono con los DOS nombres y su SVG existe",
        $tieneAmbos && $svg !== null && is_file($svg),
        $svg !== null ? basename($svg) : 'sin mask-image');
}

// La guarda anti-duplicado: hay dos banners cuyo icono manual dice algo que la
// variante no (un candado en un `--info`, un reloj de arena en un `--warning`).
$chk('la guarda :has() anti-icono-doble sigue en el CSS SERVIDO',
    str_contains($css, ':has(.alert__icon)::before')
    && str_contains($css, ':has(.btn-icon)::before'));

// 🔴 Nace del hallazgo del 02/09: `clean-css` 4.2.3 borra `@container` EN
// SILENCIO. Lo que se escribe en SCSS no es necesariamente lo que llega al
// navegador, asi que la comprobacion se hace sobre el CSS servido, y de paso se
// vigila que nadie meta un `@container` creyendo que funciona.
$chk('el minificador no ha dejado ningun @container muerto en el CSS',
    !str_contains($css, '@container'));

// ---- 4. LA ACCION ---------------------------------------------------
echo "\n=== 4. El boton dentro del banner ===\n";

$accion = $reglas($css, '.alert__accion');
$chk('`alert__accion` existe y se alinea a la derecha en su propia linea',
    count($accion) === 1
    && str_contains($accion[0]['cuerpo'], 'display:block')
    && preg_match('/margin:[^;]*auto/', $accion[0]['cuerpo']) === 1);

// ---- 5. EL MARCADO DE LAS VISTAS ------------------------------------
echo "\n=== 5. Marcado ===\n";

/** Extrae el HTML interno de cada banner, contando divs para cerrar bien. */
$bannersDe = static function (string $src): array {
    $out = [];
    $n = preg_match_all('/<div\s+class="([^"]*)"/i', $src, $m, PREG_OFFSET_CAPTURE);
    for ($i = 0; $i < $n; $i++) {
        $clases = preg_split('/\s+/', trim($m[1][$i][0]));
        $esBanner = (bool) array_filter($clases, static fn(string $c): bool
            => $c === 'alert' || $c === 'flash'
               || str_starts_with($c, 'alert--') || str_starts_with($c, 'flash--'));
        if (!$esBanner) { continue; }             // descarta `alerta-item`, `alerta-empate`
        $ini = strpos($src, '>', $m[0][$i][1]);
        $pos = $ini; $nivel = 1;
        while ($nivel > 0 && $pos !== false) {
            $ab = stripos($src, '<div', $pos + 1);
            $ce = stripos($src, '</div', $pos + 1);
            if ($ce === false) { break; }
            if ($ab !== false && $ab < $ce) { $nivel++; $pos = $ab; }
            else                            { $nivel--; $pos = $ce; }
        }
        $out[] = substr($src, $ini + 1, max(0, $pos - $ini - 1));
    }
    return $out;
};

$vistas = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(VIEW_PATH));
$conGlifo = [];
$totalBanners = 0;
$conAccion = 0;
foreach ($vistas as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $src = file_get_contents($f->getPathname());
    foreach ($bannersDe($src) as $cuerpoBanner) {
        $totalBanners++;
        if (str_contains($cuerpoBanner, 'alert__accion')) { $conAccion++; }
        // 🔴 El glifo a mano es el modo natural de romper el icono automatico:
        // `:has()` solo ve ELEMENTOS, asi que un caracter suelto no lo suprime y
        // el banner sale con DOS iconos. Habia nueve; se borraron el 02/09.
        if (preg_match('/[\x{2705}\x{2713}\x{2714}\x{26A0}\x{26A1}\x{274C}\x{2139}\x{1F512}]/u', $cuerpoBanner)) {
            $conGlifo[] = str_replace(VIEW_PATH . DIRECTORY_SEPARATOR, '', $f->getPathname());
        }
    }
}

$chk('el barrido encuentra los banners del proyecto',
    $totalBanners >= 40, "$totalBanners banners");
$chk('ningun banner trae glifo a mano (saldria con DOS iconos)',
    $conGlifo === [], $conGlifo === [] ? 'limpio' : implode(', ', array_unique($conGlifo)));
$chk('los banners con boton siguen usando alert__accion',
    $conAccion === 3, "$conAccion con accion");

// Delimita el alcance: `alerta-item` y `alerta-empate` son OTROS componentes
// (pages/_dashboard.scss) que un grep por `class="alert` captura por prefijo.
// Contarlos como banners inflaba el inventario de 47 a 55.
foreach (['padre/alertas.php' => 'alerta-item',
          'director/orden-merito-periodo.php' => 'alerta-empate'] as $v => $clase) {
    $src = file_get_contents(VIEW_PATH . '/' . $v);
    $chk("$v usa `$clase`, que NO es este componente",
        str_contains($src, $clase) && $bannersDe($src) === []);
}

// ── QUE DICE UN AVISO (22/09/2026) ──────────────────────────────────────
// Un aviso dice QUE PASO y QUE HACER; no la MECANICA interna del sistema ni
// POLITICA que su lector no puede accionar. Estos asertos anclan los recortes
// del barrido completo: son textos, asi que se comprueba que la frase retirada
// NO ha vuelto, no que exista una redaccion concreta.
$ctrlMat = file_get_contents(ROOT_PATH . '/app/Controllers/Matricula/MatriculaController.php');
$chk('el aviso de notas de origen no explica la politica de la boleta',
    !str_contains($ctrlMat, 'Son informativas: no aparecen en la boleta'));
$chk('el flash de notas de origen no cuenta a cuantos docentes se aviso',
    !str_contains($ctrlMat, 'Se avisó a '));
$chk('el flash SI sigue diciendo las filas sin nota que se omitieron',
    str_contains($ctrlMat, 'Se omitieron '));

$docOrigen = file_get_contents(VIEW_PATH . '/docente/notas-origen.php');
$chk('el banner del docente no le explica boleta ni orden de merito',
    !str_contains($docOrigen, 'no aparecen en su boleta')
    && !str_contains($docOrigen, 'no cuentan para el orden de mérito'));
$chk('pero si le dice que no tiene que hacer nada con ellas',
    str_contains($docOrigen, 'no tienes que hacer nada con ellas'));

$lote = file_get_contents(VIEW_PATH . '/rectificaciones/extraordinaria-lote.php');
$chk('el motivo del lote no promete que el docente lo vera (ya no es cierto)',
    !str_contains($lote, 'el\n                docente lo verá')
    && !str_contains($lote, 'docente lo verá junto a la calificación'));

$ranking = file_get_contents(VIEW_PATH . '/docente/ranking-seccion-periodo.php');
$chk('el aviso de empates no le nombra al docente el algoritmo interno',
    !str_contains($ranking, 'cascada de desempate'));

$resumen = file_get_contents(VIEW_PATH . '/docente/resumen-competencia.php');
$chk('la conclusion transversal se explica UNA vez en el resumen, no dos',
    substr_count($resumen, 'la registra el tutor') + substr_count($resumen, 'las registra el tutor') === 1);

echo "\nRESULTADO: " . ($ok ? 'OK - el banner es punto unico y deja fluir la frase.' : 'HAY FALLOS') . "\n";
exit($ok ? 0 : 1);
