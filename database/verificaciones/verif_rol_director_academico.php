<?php

/**
 * Verificación de la FASE 2 — rol Director académico (migración 055).
 * Solo lectura sobre la BD.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');

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

$um    = new App\Models\UsuarioModel();
$roles = $um->query("SELECT id, nombre, codigo, descripcion FROM roles ORDER BY id");
$porCodigo = array_column($roles, null, 'codigo');

echo "1) El rol existe con los valores exactos\n";
$r = $porCodigo['director_academico'] ?? null;
$chk('existe el rol director_academico', $r !== null);
if ($r) {
    $chk("nombre = 'Director académico' (con tilde)", $r['nombre'] === 'Director académico');
    $chk("descripcion = 'Supervisión académica en solo lectura'",
        $r['descripcion'] === 'Supervisión académica en solo lectura');
}
// Antes afirmaba «9 filas en roles»: un aserto que caduca con cada rol nuevo
// (cayó con la 065, auxiliar académico). Lo que protegía es que re-ejecutar la
// 055 no duplique el rol, y eso se mide sin depender del total.
$chk('director_academico aparece una sola vez (sin duplicar al re-ejecutar)',
    count(array_filter($roles, fn($r) => $r['codigo'] === 'director_academico')) === 1);

echo "\n2) INTEGRACION — los 3 codigos de ROLES_DIRECCION existen en la BD\n";
// Esta es la comprobacion que atrapa un typo en la constante: hasta ahora
// 'director_academico' era un codigo que no correspondia a ningun rol real.
foreach (ROLES_DIRECCION as $codigo) {
    $chk("'$codigo' es un roles.codigo real", isset($porCodigo[$codigo]));
}

echo "\n3) El formulario de usuarios lo ofrece\n";
$codigosListados = array_column($um->listarRoles(), 'codigo');
$chk('listarRoles() incluye director_academico',
    in_array('director_academico', $codigosListados, true));
$chk('listarRoles() no filtra ningun rol', count($codigosListados) === count($roles));

echo "\n4) Quien tiene el rol NO firma documentos oficiales\n";
//
// ⚠️ Aqui vivia la asercion "0 usuarios con el rol nuevo", que CADUCABA por
// diseno: en cuanto alguien crea el primer Director academico —el paso que hay
// que dar— daba rojo sin que nada estuviera mal. Paso el 24/08/2026.
//
// Lo que si debe cumplirse siempre: solo el Director EBR firma boletas, actas y
// reportes. El conteo se IMPRIME como dato, no se juzga.
$n = $um->queryOne("
    SELECT COUNT(*) AS n FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    WHERE r.codigo = 'director_academico'
");
$firmantes = $um->queryOne("
    SELECT COUNT(*) AS n
    FROM director_ebr_historial h
    INNER JOIN usuarios u ON u.id = h.usuario_id
    INNER JOIN roles r    ON r.id = u.rol_id
    WHERE r.codigo <> 'director_ebr'
      AND h.hasta IS NULL
");
$chk('ningun NO-Director EBR figura como firmante vigente',
    (int) $firmantes['n'] === 0,
    (int) $n['n'] . ' usuario(s) con el rol director_academico');

echo "\n5) Color compilado — los 3 directores en el mismo grupo\n";
$css = file_get_contents(ROOT_PATH . '/public/css/app.css');
foreach (ROLES_DIRECCION as $codigo) {
    $chk("app.css trae .usuario-avatar--$codigo",
        str_contains($css, "usuario-avatar--$codigo"));
}
// Comparten declaracion: los tres selectores deben caer en la MISMA regla, con
// un unico background. Sin depender del orden: el compilador los reordena
// alfabeticamente (academico, ebr, general), no en el orden del fuente.
preg_match_all('/([^{}]*usuario-avatar--director[^{}]*)\{([^}]*)\}/', $css, $reglas, PREG_SET_ORDER);
$unaRegla = null;
foreach ($reglas as $r) {
    $cuantos = 0;
    foreach (ROLES_DIRECCION as $codigo) {
        if (str_contains($r[1], "usuario-avatar--$codigo")) { $cuantos++; }
    }
    if ($cuantos === 3) { $unaRegla = $r; break; }
}
$chk('los 3 caen en una sola regla', $unaRegla !== null);
$chk('esa regla declara un unico background',
    $unaRegla !== null && preg_match_all('/background:/', $unaRegla[2]) === 1);

echo "\n6) El badge del listado de usuarios lo cubre\n";
$vista = file_get_contents(ROOT_PATH . '/resources/views/admin/usuarios/index.php');
$chk('$badgeRol resuelve por ROLES_DIRECCION, no por literales',
    str_contains($vista, 'in_array($codigo, ROLES_DIRECCION, true)'));

echo "\n", $ok ? "== FASE 2 EN VERDE ==\n" : "== HAY FALLOS ==\n";
exit($ok ? 0 : 1);
