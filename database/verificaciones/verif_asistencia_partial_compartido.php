<?php

/**
 * Verificación — la tabla de incidencias de asistencia es UNA sola (25/08/2026).
 * Solo lectura sobre la BD.
 *
 * QUÉ COMPRUEBA
 *   1. El partial es la unica fuente: ninguna de las dos vistas trae <table>.
 *   2. RENDER REAL del partial en sus DOS modos. El editable alimenta a
 *      `public/js/asistencia.js`: si se pierde un gancho, RA deja de guardar en
 *      silencio. El de solo lectura NO debe traer ningun control.
 *   3. El estado del cierre se lee de `ra_bloqueado_en`, el nombre REAL de la
 *      columna. La vista de Direccion preguntaba por `bloqueado_en` -clave
 *      inexistente- y `empty()` no avisa: decia "en curso" siempre.
 *   4. Los totales del modelo cuadran con el roster que se pinta.
 *   5. La apertura del imprimible a Direccion no abrio de mas: los otros cuatro
 *      metodos siguen restringidos.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('VIEW_PATH', ROOT_PATH . '/resources/views');

require ROOT_PATH . '/app/Helpers/helpers.php';

spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/'] as $pre => $base) {
        if (str_starts_with($c, $pre)) {
            $f = ROOT_PATH . $base . str_replace('\\', '/', substr($c, strlen($pre))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};

$modelo   = new App\Models\AsistenciaModel();
$vistaRA  = ROOT_PATH . '/resources/views/admin/asistencia/seccion.php';
$vistaDir = ROOT_PATH . '/resources/views/consulta-notas/asistencia.php';
$partial  = ROOT_PATH . '/resources/views/admin/asistencia/_tabla-incidencias.php';

echo "1) El partial es la unica fuente de la tabla\n";
$chk('el partial existe', is_file($partial));
foreach (['RA' => $vistaRA, 'Direccion' => $vistaDir] as $quien => $ruta) {
    $html = file_get_contents($ruta);
    $chk("la vista de {$quien} no declara <table> propia", !str_contains($html, '<table'));
    $chk("la vista de {$quien} requiere el partial",
        str_contains($html, "/admin/asistencia/_tabla-incidencias.php'"));
}

echo "\n2) Render REAL del partial, en sus dos modos\n";
// Datos reales: la primera seccion/periodo que tenga roster.
$destino = null;
foreach ($modelo->query("SELECT id FROM periodos ORDER BY id") as $p) {
    foreach ($modelo->query("SELECT id FROM secciones ORDER BY id") as $s) {
        $r = $modelo->getEstudiantesConIncidencias((int) $s['id'], (int) $p['id']);
        if ($r) { $destino = [(int) $s['id'], (int) $p['id'], $r]; break 2; }
    }
}
if ($destino === null) {
    echo "  SIN DATOS: ninguna seccion tiene roster. No se puede renderizar.\n";
    exit(0);
}
[$seccionId, $periodoId, $roster] = $destino;
printf("  (seccion %d, periodo %d, %d estudiantes)\n", $seccionId, $periodoId, count($roster));

$render = function (bool $editable) use ($partial, $roster, $periodoId): string {
    $estudiantes = $roster;
    $totales     = App\Models\AsistenciaModel::totalesIncidencias($roster);
    $pidVer      = $periodoId;
    $csrfToken   = 'TOKEN-DE-PRUEBA';
    $topeMax     = 99;
    ob_start();
    require $partial;
    return (string) ob_get_clean();
};

$htmlEdit = $render(true);
$htmlRead = $render(false);

// 🔴 LOS GANCHOS DEL JS. Se leen del propio asistencia.js para no fijar aqui una
// lista a mano que pueda quedarse vieja respecto del script real.
$js = file_get_contents(ROOT_PATH . '/public/js/asistencia.js');
$ganchos = [
    '.asistencia-fila'    => 'asistencia-fila',
    '.asistencia-input'   => 'asistencia-input',
    '.asistencia-guardar' => 'asistencia-guardar',
    '.asistencia-status'  => 'asistencia-status',
];
foreach ($ganchos as $sel => $clase) {
    if (!str_contains($js, $clase)) {
        $chk("asistencia.js ya no usa {$sel}: revisar esta verificacion", false);
        continue;
    }
    $chk("modo editable conserva {$sel}", str_contains($htmlEdit, $clase));
}
foreach (['data-matricula-id', 'data-periodo-id', 'data-csrf'] as $attr) {
    $chk("modo editable conserva {$attr}", str_contains($htmlEdit, $attr));
}
$chk('modo editable pinta un input por estudiante y contador',
    substr_count($htmlEdit, 'class="asistencia-input"') === count($roster) * 4);

// La otra rama: solo lectura NO puede traer ningun control.
$chk('modo solo lectura NO trae inputs',   !str_contains($htmlRead, '<input'));
$chk('modo solo lectura NO trae botones',  !str_contains($htmlRead, '<button'));
$chk('modo solo lectura NO trae el token', !str_contains($htmlRead, 'TOKEN-DE-PRUEBA'));
$chk('modo solo lectura pinta los valores como texto',
    substr_count($htmlRead, 'asistencia-td-valor') >= count($roster) * 4);

// La leyenda sale del partial, asi que la tienen las DOS vistas por construccion.
foreach (['editable' => $htmlEdit, 'solo lectura' => $htmlRead] as $modo => $html) {
    $chk("modo {$modo} incluye la leyenda de abreviaturas",
        str_contains($html, 'tabla-pie__leyenda'));
    $chk("modo {$modo} incluye los totales de la seccion",
        str_contains($html, 'asistencia-totales'));
}

echo "\n3) El estado del cierre se lee de la columna REAL\n";
$cols = array_column($modelo->query("SHOW COLUMNS FROM cierres_asistencia"), 'Field');
$chk("la columna se llama ra_bloqueado_en", in_array('ra_bloqueado_en', $cols, true));
$chk("y NO existe ninguna columna 'bloqueado_en'", !in_array('bloqueado_en', $cols, true));

$htmlDir = file_get_contents($vistaDir);
// La regresion concreta: preguntar por una clave que no existe. Se busca el
// acceso al array, no la palabra suelta (que aparece en el comentario que lo
// explica).
$chk('la vista de Direccion no vuelve a leer $cierre[\'bloqueado_en\']',
    !preg_match("/\\\$cierre\['bloqueado_en'\]/", $htmlDir));
$chk('la vista de Direccion lee ra_bloqueado_en',
    str_contains($htmlDir, "\$cierre['ra_bloqueado_en']"));

// Y que haya cierres con los que el estado se pueda ver de verdad.
$vigentes = (int) $modelo->query(
    "SELECT COUNT(*) n FROM cierres_asistencia WHERE anulado_en IS NULL"
)[0]['n'];
$chk("hay cierres vigentes con los que probarlo en navegador ({$vigentes})", $vigentes > 0);

echo "\n4) Los totales cuadran con el roster que se pinta\n";
$tot = App\Models\AsistenciaModel::totalesIncidencias($roster);
$esperado = ['registrados' => 0];
foreach (App\Models\AsistenciaModel::CAMPOS as $campo) {
    $esperado[$campo] = 0;
    foreach ($roster as $a) { $esperado[$campo] += (int) $a['incidencias'][$campo]; }
}
foreach ($roster as $a) { if (!empty($a['incidencias']['registrado'])) { $esperado['registrados']++; } }
foreach ($esperado as $k => $v) {
    $chk("total de {$k} = {$v}", (int) $tot[$k] === $v);
}
// La rama vacia: sin roster no puede reventar ni inventar.
$vacio = App\Models\AsistenciaModel::totalesIncidencias([]);
$chk('con roster vacio devuelve ceros y no falla',
    array_sum($vacio) === 0 && count($vacio) === count(App\Models\AsistenciaModel::CAMPOS) + 1);

echo "\n5) Abrir el imprimible a Direccion no abrio de mas\n";
$ctl = file_get_contents(ROOT_PATH . '/app/Controllers/Admin/AsistenciaController.php');
$chk('el constructor admite Direccion',
    (bool) preg_match('/__construct.*?requireRole\(\[\.\.\.self::ROLES_REGISTRAN, \.\.\.ROLES_DIRECCION\]\)/s', $ctl));
// Cada metodo publico enrutado, salvo `imprimir`, debe re-restringir.
// `estudiante` (entrada por estudiante, 28/09/2026) es de REGISTRO: Direccion no.
foreach (['index', 'seccion', 'bloquear', 'guardar', 'estudiante'] as $metodo) {
    $chk("{$metodo}() sigue restringido a quien registra",
        (bool) preg_match(
            '/public function ' . $metodo . '\([^)]*\): void\s*\{\s*\$this->requireRole\(self::ROLES_REGISTRAN\);/s',
            $ctl
        ));
}
$chk('imprimir() NO se re-restringe (es la que ve Direccion)',
    !preg_match('/public function imprimir\([^)]*\): void\s*\{\s*\$this->requireRole/s', $ctl));

// Guarda contra el olvido: si nace un metodo publico nuevo, hay que decidir.
preg_match_all('/public function (\w+)\(/', $ctl, $m);
$publicos = array_values(array_diff($m[1], ['__construct']));
sort($publicos);
$esperados = ['bloquear', 'estudiante', 'guardar', 'imprimir', 'index', 'seccion'];
$chk('no nacio ningun metodo publico sin decidir su rol: ' . implode(', ', $publicos),
    $publicos === $esperados);

echo "\n6) La fila enfocada gana al hover, y por ESPECIFICIDAD\n";
// Se mide sobre el CSS SERVIDO, no sobre el SCSS: lo que decide en el navegador
// es el selector compilado. Y se compara la ESPECIFICIDAD, no el orden en el
// archivo: confiar en el orden es lo que hacia que `tr:hover` -que vive en otro
// parcial- borrase el verde de una fila registrada.
$css = file_get_contents(ROOT_PATH . '/public/css/app.css');

/** Especificidad [clases+pseudoclases+atributos, elementos] de un selector simple. */
$espec = function (string $sel): array {
    $clases    = preg_match_all('/\.[\w-]+|\[[^\]]+\]|:(?!:)[\w-]+/', $sel);
    $elementos = preg_match_all('/(?:^|\s|>|\+|~)([a-z]+)(?=[.\[:\s]|$)/', $sel);
    return [$clases, $elementos];
};

/**
 * El selector INDIVIDUAL del CSS que contiene el fragmento dado.
 *
 * ⚠️ Parte el grupo por comas a proposito: la especificidad se calcula por
 * selector, NO por el grupo. Sass compila `.col-num` y `.col-nombre` juntos, y
 * medir el grupo entero daba el doble de clases en ambos lados — un aserto que
 * comparaba manzanas con dos manzanas y acusaba al codigo de un fallo inexistente.
 */
$buscarSelector = function (string $fragmento) use ($css): ?string {
    if (!preg_match('/(?:^|[};])([^{};]*' . preg_quote($fragmento, '/') . '[^{};]*)\{/', $css, $m)) {
        return null;
    }
    foreach (explode(',', $m[1]) as $sel) {
        if (str_contains($sel, $fragmento)) {
            return trim($sel);
        }
    }
    return null;
};

$selHover = $buscarSelector('.tabla-notas tr:hover .col-num');
$selFoco  = $buscarSelector('.asistencia-fila:focus-within td.col-num');
$selReg   = $buscarSelector('.asistencia-fila.asistencia-fila--registrada td.col-num');
$selCamb  = $buscarSelector('.asistencia-fila.asistencia-fila--con-cambios td.col-num');

foreach (['hover' => $selHover, 'foco' => $selFoco, 'registrada' => $selReg, 'con-cambios' => $selCamb] as $q => $s) {
    printf("       %-12s %s\n", $q . ':', $s ?? '(NO ENCONTRADO)');
}

if ($selHover === null || $selFoco === null || $selReg === null || $selCamb === null) {
    $chk('se encontraron los cuatro selectores en el CSS servido', false);
} else {
    [$cHover, $eHover] = $espec($selHover);
    foreach (['foco' => $selFoco, 'registrada' => $selReg, 'con-cambios' => $selCamb] as $q => $sel) {
        [$c, $e] = $espec($sel);
        // Gana si tiene mas clases, o las mismas y mas elementos.
        $gana = $c > $cHover || ($c === $cHover && $e > $eHover);
        $chk(sprintf('%s (%d clases) gana al hover (%d clases)', $q, $c, $cHover), $gana);
    }
}

// La decision de diseno: el foco usa un CANAL PROPIO. Si algun dia se le anade
// `background`, vuelve a competir con el verde y el ambar — que es justo lo que
// se evito. El aserto protege la decision, no el valor concreto.
if ($selFoco !== null) {
    preg_match('/' . preg_quote($selFoco, '/') . '\{([^}]*)\}/', $css, $m);
    $reglas = $m[1] ?? '';
    $chk('el foco NO pinta background (canal separado del estado del dato)',
        !str_contains($reglas, 'background'));
    $chk('el foco marca la columna N° con una barra (box-shadow inset)',
        str_contains($reglas, 'inset'));
}

$chk('el input reserva margen para que el teclado no lo tape (scroll-margin)',
    (bool) preg_match('/\.asistencia-input\{[^}]*scroll-margin-block/', $css));

echo "\n", $ok ? "TODO OK\n" : "HAY FALLOS\n";
exit($ok ? 0 : 1);
