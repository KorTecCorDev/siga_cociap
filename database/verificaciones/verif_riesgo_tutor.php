<?php

/**
 * Verificación del RIESGO POR TUTOR (23/09/2026): el panel del tutor
 * (`/docente/tutoria/acompanamiento`), su A4 y su card en `/docente/inicio`.
 *
 * Solo lectura. Lo que escribe para simular un bimestre despublicado va dentro
 * de una TRANSACCIÓN con ROLLBACK (regla del repo: ningún script de database/
 * deja escrito lo que no creó).
 *
 * Mide los DOS lados de cada candado —dejar pasar y bloquear—:
 *  · la sección sale de `tutor_id` en servidor, nunca de la URL;
 *  · solo bimestres PUBLICADOS del nivel (compuerta 044);
 *  · la card del panel dice lo mismo que el informe al que lleva.
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
$_SERVER['HTTP_HOST'] = 'localhost';
$_SESSION = [];

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra ? " — $extra" : '');
    $ok = $ok && $c;
};
$pdo = Core\Database::connect();

use App\Controllers\Docente\PanelController;
use App\Controllers\Docente\RiesgoTutorController;
use App\Models\SituacionFinalModel;

// Instancia sin constructor (el constructor exige sesión) con sus modelos.
$armar = static function (string $clase, array $modelos): array {
    $rc   = new ReflectionClass($clase);
    $ctrl = $rc->newInstanceWithoutConstructor();
    foreach ($modelos as $prop => $obj) {
        $p = $rc->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($ctrl, $obj);
    }
    return [$rc, $ctrl];
};
[$rcT, $tutorCtrl] = $armar(RiesgoTutorController::class, [
    'transModel'       => new App\Models\TransversalModel(),
    'seccionModel'     => new App\Models\SeccionModel(),
    'situacionModel'   => new SituacionFinalModel(),
    'publicacionModel' => new App\Models\PublicacionBoletaModel(),
    'controlModel'     => new App\Models\ControlOperativoModel(),
]);
$contexto = static function (array $get) use ($rcT, $tutorCtrl): array {
    $_GET = $get;
    $m = $rcT->getMethod('contexto');
    $m->setAccessible(true);
    $r = $m->invoke($tutorCtrl);
    $_GET = [];
    return $r;
};
$como = static function (int $usuarioId): void {
    $_SESSION['auth_user'] = ['id' => $usuarioId, 'rol_codigo' => 'docente', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
};
$render = static function (string $vista, array $vars): array {
    $errores = [];
    set_error_handler(function ($no, $str) use (&$errores) { $errores[] = $str; return true; });
    ob_start();
    (static function () use ($vista, $vars) { extract($vars); include VIEW_PATH . "/$vista.php"; })();
    $out = (string) ob_get_clean();
    restore_error_handler();
    return [$out, $errores];
};

// ── El caso de prueba: la sección OFICIAL de un retorno de grado ─────────
// Es la que más ramas ejerce: recibe una fila reubicada desde otro grado.
$caso = $pdo->query("
    SELECT s.id AS seccion_id, s.tutor_id, r.matricula_operativa_id AS op
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    JOIN secciones s   ON s.id  = mo.seccion_id
    WHERE s.tutor_id IS NOT NULL
    ORDER BY r.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$caso) {
    $caso = $pdo->query("SELECT id AS seccion_id, tutor_id, 0 AS op FROM secciones WHERE tutor_id IS NOT NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$tutorId   = (int) $caso['tutor_id'];
$seccionId = (int) $caso['seccion_id'];
$otra      = (int) $pdo->query("SELECT id FROM secciones WHERE tutor_id IS NOT NULL AND id <> $seccionId ORDER BY id LIMIT 1")->fetchColumn();
$noTutor   = (int) $pdo->query("
    SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
    WHERE r.codigo = 'docente' AND u.id NOT IN (SELECT tutor_id FROM secciones WHERE tutor_id IS NOT NULL)
    ORDER BY u.id LIMIT 1
")->fetchColumn();

// ── 1. La sección sale de tutor_id, nunca de la URL ─────────────────────
echo "SECCIÓN DEL TUTOR — resuelta en servidor\n";
$como($tutorId);
[$sec, $periodos, $periodo, $noPub] = $contexto([]);
[$sec2]                             = $contexto(['seccion_id' => (string) $otra, 'secciones' => [(string) $otra], 'seccion' => (string) $otra]);
$chk('el tutor obtiene SU sección', (int) $sec['id'] === $seccionId,
    $sec['grado_nombre'] . ' ' . $sec['nombre'] . ' de ' . $sec['nivel_nombre']);
$chk('ningún parámetro de la URL le cambia la sección', (int) $sec2['id'] === $seccionId);
$chk('un docente SIN tutoría no obtiene sección (el controlador redirige)',
    $noTutor === 0 || (new App\Models\TransversalModel())->getSeccionDelTutor($noTutor) === null,
    $noTutor ? "usuario $noTutor" : 'no hay docentes sin tutoría');
$srcT = file_get_contents(ROOT_PATH . '/app/Controllers/Docente/RiesgoTutorController.php');
$chk('el controlador no lee ninguna sección de la petición',
    !preg_match("~query\\('secci~", $srcT) && !preg_match('~\\$_GET~', $srcT));

// ── 2. Compuerta 044: solo bimestres PUBLICADOS de su nivel ─────────────
echo "\nBIMESTRES — solo publicados del nivel\n";
$anioSec = (int) $pdo->query("SELECT anio_id FROM secciones WHERE id = $seccionId")->fetchColumn();
$pub     = array_map('intval', array_keys((new App\Models\PublicacionBoletaModel())->periodosPublicados($anioSec, (int) $sec['nivel_id'])));
$idsPer  = array_map(fn($p) => (int) $p['id'], $periodos);
$a = $idsPer; $b = $pub; sort($a); sort($b);
$chk('lista exactamente los bimestres publicados de su nivel', $a === $b,
    'publicados: ' . (implode(', ', array_map(fn($p) => $p['nombre_display'], $periodos)) ?: 'ninguno'));
$noPublicadoId = (int) $pdo->query("
    SELECT p.id FROM periodos p
    WHERE p.anio_id = (SELECT anio_id FROM secciones WHERE id = $seccionId)
      AND p.id NOT IN (" . ($idsPer ? implode(',', $idsPer) : '0') . ")
    ORDER BY p.numero LIMIT 1
")->fetchColumn();
if ($noPublicadoId) {
    [, , $pX, $npX] = $contexto(['periodo_id' => (string) $noPublicadoId]);
    $chk('un bimestre SIN publicar pedido por URL no se acepta (y se avisa)',
        $npX === true && ($pX === null || (int) $pX['id'] !== $noPublicadoId), "periodo $noPublicadoId");
}
if ($periodos) {
    [, , $pOk, $npOk] = $contexto(['periodo_id' => (string) $periodos[0]['id']]);
    $chk('un bimestre publicado pedido por URL sí se acepta',
        $npOk === false && (int) $pOk['id'] === (int) $periodos[0]['id']);
    $chk('por defecto, el último publicado', (int) $periodo['id'] === (int) end($periodos)['id'] && $noPub === false);

    // Despublicar (suspender) el último en una transacción: deja de ofrecerse.
    $ultimoId = (int) end($periodos)['id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE periodos_publicacion SET suspendida_en = NOW() WHERE periodo_id = ? AND nivel_id = ?')
            ->execute([$ultimoId, (int) $sec['nivel_id']]);
        [, $perS, , $npS] = $contexto(['periodo_id' => (string) $ultimoId]);
        $chk('un bimestre SUSPENDIDO desaparece de la lista y se rechaza por URL',
            !in_array($ultimoId, array_map(fn($p) => (int) $p['id'], $perS), true) && $npS === true);
    } finally {
        $pdo->rollBack();
    }
    [, $perR] = $contexto([]);
    $chk('el rollback dejó la publicación como estaba', count($perR) === count($periodos));
}

// ── 3. El informe: su sección, con la regla oficial ─────────────────────
echo "\nINFORME DEL TUTOR\n";
if ($periodo) {
    $situacion = new SituacionFinalModel();
    $bloque = $situacion->deSeccion((int) $periodo['id'], $sec);
    // A mano sobre la lista oficial: filas de ESTA sección (grado + nombre).
    $esperado = 0;
    $conRetorno = false;
    foreach ($situacion->porGrado((int) $periodo['id']) as $g) {
        if ((int) $g['grado']['id'] !== (int) $sec['grado_id']) { continue; }
        foreach ($g['en_riesgo'] as $al) {
            if ($al['seccion_nombre'] === $sec['nombre']) {
                $esperado++;
                $conRetorno = $conRetorno || (int) $al['matricula_id'] === (int) $caso['op'];
            }
        }
    }
    $ajenas = 0;
    foreach ($bloque['filtrado'] as $g) {
        foreach ($g['en_riesgo'] as $al) { $ajenas += $al['seccion_nombre'] === $sec['nombre'] ? 0 : 1; }
    }
    $chk('el informe trae solo los estudiantes de su sección',
        (int) $bloque['stats']['resumen']['total'] === $esperado && $ajenas === 0,
        "$esperado estudiante(s)" . ($conRetorno ? ' · incluye el retorno de grado' : ''));
    if ((int) $caso['op'] > 0) {
        $chk('el retorno de grado aparece en el informe del tutor de su sección OFICIAL', $conRetorno || $esperado === 0);
    }

    [$hI, $eI] = $render('docente/riesgo/index', ['seccion' => $sec, 'periodos' => $periodos, 'periodo' => $periodo, 'bloque' => $bloque, 'noPublicado' => false]);
    [$hP, $eP] = $render('docente/riesgo/imprimir', ['seccion' => $sec, 'periodo' => $periodo, 'bloque' => $bloque]);
    // Filas = riesgo + seguimiento (24/09/2026): las dos listas usan la franja.
    $filasEsp = $esperado + array_sum(array_map(fn($g) => count($g['seguimiento']), $bloque['filtrado']));
    $chk('pantalla y A4 del tutor renderizan sin avisos, con su tutor y sus filas',
        $eI === [] && $eP === []
            && substr_count($hI, 'data-riesgo-fila') === $filasEsp && substr_count($hP, 'data-riesgo-fila') === $filasEsp
            && str_contains($hI, 'Tutor(a) actual:') && str_contains($hP, 'Tutor(a) actual:'),
        ($eI[0] ?? $eP[0] ?? "$filasEsp fila(s) en cada una ($esperado de riesgo)"));
    // La regla es la del MINEDU y es UNA: el informe habla de situacion final,
    // no de umbrales de conteo, y no ofrece variantes de lectura.
    $chk('el informe del tutor habla de la situacion final, sin umbrales de conteo',
        !str_contains($hI . $hP, 'name="primaria"')
            && !str_contains($hI . $hP, 'o más competencias')
            && (str_contains($hI, 'promoción') || str_contains($hI, 'promocion')));
    $chk('ninguna vista del tutor lleva CSS inline',
        !preg_match('~\sstyle="~', file_get_contents(VIEW_PATH . '/docente/riesgo/index.php') . file_get_contents(VIEW_PATH . '/docente/riesgo/imprimir.php')));
}

// ── 4. La card del panel dice lo mismo que el informe ───────────────────
echo "\nCARD EN /docente/inicio\n";
[$rcP, $panel] = $armar(PanelController::class, [
    'calModel'      => new App\Models\CalificacionModel(),
    'transModel'    => new App\Models\TransversalModel(),
    'conductaModel' => new App\Models\ConductaModel(),
    'horarioModel'  => new App\Models\HorarioModel(),
]);
$panelHtml = static function (int $usuarioId) use ($panel, $como): array {
    $como($usuarioId);
    Core\View::setLayout('__sin_layout_verificador__');
    $errores = [];
    set_error_handler(function ($no, $str) use (&$errores) { $errores[] = $str; return true; });
    ob_start();
    $panel->index();
    $out = (string) ob_get_clean();
    restore_error_handler();
    return [$out, $errores];
};
[$hC, $eC] = $panelHtml($tutorId);
$ultimo = (new App\Models\PublicacionBoletaModel())
    ->ultimoPeriodoPublicadoPorNivel($anioSec)[(int) $sec['nivel_id']] ?? null;
if (preg_match('~<a href="[^"]*docente/tutoria/acompanamiento" class="card dpanel-card dpanel-card--riesgo">(.*?)</a>~s', $hC, $mC)) {
    $txt = trim(preg_replace('~\s+~', ' ', strip_tags($mC[1])));
    if ($ultimo) {
        $res = (new SituacionFinalModel())->deSeccion((int) $ultimo['id'], $sec)['stats']['resumen'];
        $chk('la card del tutor muestra las cifras del ÚLTIMO bimestre publicado, iguales al informe',
            str_contains($txt, $ultimo['nombre_display'])
                && str_contains($txt, $res['total'] . ' de ' . $res['evaluados'] . ' no alcanzarían la promoción')
                && str_contains($txt, $res['permanencia'] . ' permanecerían en el grado') && $eC === [],
            $txt);
    } else {
        $chk('sin bimestre publicado, la card lo dice', str_contains($txt, 'Aún no hay bimestre publicado'), $txt);
    }
} else {
    $chk('la card de riesgo aparece para el tutor', false, $eC[0] ?? 'no se encontró la card');
}
if ($noTutor) {
    [$hN, $eN] = $panelHtml($noTutor);
    $chk('un docente sin tutoría NO ve la card', !str_contains($hN, 'dpanel-card--riesgo') && $eN === [], $eN[0] ?? '');
}

// ── 5. Rutas y estilos ──────────────────────────────────────────────────
echo "\nRUTAS Y ESTILOS\n";
$rutas = file_get_contents(ROOT_PATH . '/routes/web.php');
$pos   = static fn(string $r) => strpos($rutas, "'$r'");
$chk('las rutas del tutor van ANTES de /docente/tutoria/{periodo_id}',
    $pos('/docente/tutoria/acompanamiento') !== false && $pos('/docente/tutoria/acompanamiento/imprimir') !== false
        && $pos('/docente/tutoria/acompanamiento') < $pos('/docente/tutoria/{periodo_id}')
        && $pos('/docente/tutoria/acompanamiento/imprimir') < $pos('/docente/tutoria/{periodo_id}'));
$chk('la ruta del lote de Dirección va ANTES de /admin/cuadros',
    $pos('/admin/cuadros/acompanamiento/tutores') !== false && $pos('/admin/cuadros/acompanamiento/tutores') < $pos('/admin/cuadros'));
$css = file_get_contents(ROOT_PATH . '/public/css/app.css');
$chk('el CSS servido salta de hoja entre secciones del lote',
    (bool) preg_match('~\.riesgo-lote__hoja\s*\+\s*\.riesgo-lote__hoja\s*\{[^}]*break-before:\s*page~', $css));
$chk('el CSS servido trae la card y el h1 del tutor con su icono',
    (bool) preg_match('~\.dpanel-card--riesgo\{[^}]*warning\.svg~', str_replace(' ', '', $css))
        && (bool) preg_match('~\.page-title--riesgo\{[^}]*warning\.svg~', str_replace(' ', '', $css)));

unset($_SESSION['auth_user']);
echo "\n", $ok ? "== RIESGO POR TUTOR EN VERDE ==\n" : "== HAY FALLOS ==\n";
exit($ok ? 0 : 1);
