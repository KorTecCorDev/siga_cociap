<?php

/**
 * Verificación de los CRITERIOS DE CONDUCTA POR AÑO (F6 del módulo auxiliares,
 * migración 067, 28/09/2026). Ver docs/modulos/auxiliares.md §7 F6.
 *
 * Todo lo que escribe va en UNA transacción que se revierte al final: crea un
 * año de prueba (2099), copia, agrega, mueve y retira criterios, y corrige uno
 * del año real. No limpia con DELETE nada que no creó.
 *
 * Uso: php database/verificaciones/verif_criterios_conducta_anio.php
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

date_default_timezone_set(config('timezone'));

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};
$src = fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/' . $f));
$falla = function (callable $fn): bool {
    try { $fn(); return false; } catch (\DomainException $e) { return true; }
};

$k    = new App\Models\CriterioConductaModel();
$cond = new App\Models\ConductaModel();

// ── 1) Esquema (migración 067) ─────────────────────────────────
echo "1) Esquema\n";
$cols = array_column($k->query("SHOW COLUMNS FROM criterios_conducta"), null, 'Field');
$chk('anio_id existe y es obligatorio', isset($cols['anio_id']) && $cols['anio_id']['Null'] === 'NO');
$chk('modificado_en y modificado_por existen', isset($cols['modificado_en'], $cols['modificado_por']));
$chk('ningún criterio sin año', (int) $k->queryOne("SELECT COUNT(*) AS n FROM criterios_conducta WHERE anio_id IS NULL")['n'] === 0);

// ── 2) Punto único y superficies ───────────────────────────────
echo "2) Punto único, rutas y guardas\n";
$cm = $src('app/Models/ConductaModel.php');
// La condición «vigente del año» solo se escribe en criteriosDelAnio (y en la
// variante sin nivel de getCriterios). Una tercera copia es una regresión.
$chk('ConductaModel escribe «eliminado_en IS NULL AND … anio_id» solo en su punto único',
    preg_match_all('/eliminado_en IS NULL AND \{?\$?k\}?\.anio_id/', $cm) === 2);
$chk('las 4 subconsultas de completitud usan criteriosDelAnio con el año de la sección',
    substr_count($cm, "self::criteriosDelAnio('k', 's.anio_id',") === 4);
$chk('ningún otro archivo de app/ consulta criterios_conducta a mano',
    array_values(array_filter(
        array_merge(glob(ROOT_PATH . '/app/Controllers/*/*.php'), glob(ROOT_PATH . '/app/Controllers/*.php')),
        fn($f) => str_contains((string) file_get_contents($f), 'criterios_conducta')
    )) === []);
$chk('la consulta de notas pide los criterios del año DEL PERIODO',
    substr_count($src('app/Controllers/Consulta/ConsultaNotasController.php'), "(int) \$periodo['anio_id']") >= 4);

$rutas = $src('routes/web.php');
$chk('las rutas de criterios van ANTES que /admin/conducta/{id}',
    ($p = strpos($rutas, "'/admin/conducta/criterios',")) !== false && $p < strpos($rutas, "'/admin/conducta/{id}',"));
$ctrl = $src('app/Controllers/Admin/CriterioConductaController.php');
$chk('solo admin y RA escriben (Dirección fuera)',
    str_contains($ctrl, "ROLES_EDITAN = ['admin', 'registro_academico'];")
    && !preg_match('/ROLES_EDITAN\s*=\s*\[[^\]]*DIRECCION/', $ctrl));
foreach (['crear', 'actualizar', 'retirar', 'mover', 'copiar'] as $met) {
    $chk("{$met} pasa por la guarda de escritura (rol + CSRF)",
        (bool) preg_match('/function ' . $met . '\(.*?\n    \{\n        \$anioId = \$this->antesDeEscribir\(\);/s', $ctrl));
}
$chk('antesDeEscribir exige ROLES_EDITAN y CSRF',
    (bool) preg_match('/function antesDeEscribir.*?requireRole\(self::ROLES_EDITAN\);.*?validateCsrf\(\);/s', $ctrl));

// ── 3) Reglas (transacción + rollback) ─────────────────────────
echo "3) Reglas de edición (transacción + rollback)\n";
$activo = $k->queryOne("SELECT id, anio FROM anios_academicos WHERE estado = 'activo' ORDER BY anio LIMIT 1");
$usuario = (int) $k->queryOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' LIMIT 1")['id'];
$filasAntes = (int) $k->queryOne("SELECT COUNT(*) AS n FROM criterios_conducta")['n'];

if ($activo === null) {
    echo "  [SKIP] no hay año activo\n";
} else {
    $anioReal = (int) $activo['id'];
    // Huella de lo que calcula la conducta del año real, para ver que otro año no la toca.
    $huella = fn(): string => md5(json_encode([
        $cond->getCriterios(), $cond->totalCriterios(1), $cond->totalCriterios(2),
        $cond->getProgresoConductaPorSeccion((int) $k->queryOne(
            "SELECT id FROM periodos WHERE anio_id = ? ORDER BY numero DESC LIMIT 1", [$anioReal])['id']),
    ]));
    $antes = $huella();
    $codigosReales = array_column($k->listar($anioReal), 'codigo', 'id');

    $db = Core\Database::get();
    $db->beginTransaction();
    try {
        $k->execute("INSERT INTO anios_academicos (anio, fecha_inicio, fecha_fin, estado)
                     VALUES (2099, '2099-03-01', '2099-12-20', 'planificado')");
        $nuevo = (int) $k->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];

        // Año nuevo: vacío y editable, con el año real como origen de la copia.
        $chk('año nuevo: sin criterios y con edición completa',
            $k->listar($nuevo) === [] && $k->modoEdicion($nuevo) === 'completa');
        $chk('año nuevo: ofrece copiar del año anterior con criterios',
            (int) ($k->anioAnteriorConCriterios($nuevo)['id'] ?? 0) === $anioReal);

        $n = $k->copiarDelAnterior($nuevo);
        $copiados = $k->listar($nuevo);
        $chk("copiar: trae los {$n} criterios, mismos textos y códigos C1..Cn",
            $n === count($copiados) && $n === count($codigosReales)
            && array_column($copiados, 'codigo') === array_map(fn($i) => 'C' . $i, range(1, $n))
            && array_column($copiados, 'texto') === array_column($k->listar($anioReal), 'texto'));
        $chk('copiar dos veces se rechaza (el año ya tiene criterios)', $falla(fn() => $k->copiarDelAnterior($nuevo)));

        $k->crear($nuevo, '  Criterio   de prueba  ', 2);
        $lista = $k->listar($nuevo);
        $ult = end($lista);
        $chk('crear: va al final con el código siguiente, texto limpio y su nivel',
            $ult['codigo'] === 'C' . ($n + 1) && $ult['texto'] === 'Criterio de prueba' && (int) $ult['nivel_id'] === 2);
        $chk('crear: texto vacío o demasiado largo se rechaza',
            $falla(fn() => $k->crear($nuevo, '   ', null))
            && $falla(fn() => $k->crear($nuevo, str_repeat('x', 256), null)));
        $chk('crear: nivel inexistente se rechaza', $falla(fn() => $k->crear($nuevo, 'X', 999)));

        $k->mover((int) $ult['id'], true);
        $lista = $k->listar($nuevo);
        $chk('mover: sube un puesto y los códigos siguen el orden',
            (int) $lista[$n - 1]['id'] === (int) $ult['id'] && $lista[$n - 1]['codigo'] === 'C' . $n);

        $primero = (int) $lista[0]['id'];
        $k->retirar($primero, $usuario);
        $lista = $k->listar($nuevo);
        $chk('retirar: sale de la lista y los códigos se renumeran sin huecos',
            !in_array($primero, array_map('intval', array_column($lista, 'id')), true)
            && array_column($lista, 'codigo') === array_map(fn($i) => 'C' . $i, range(1, count($lista))));

        $k->actualizar((int) $lista[0]['id'], 'Texto nuevo', 1, $usuario);
        $fila = $k->listar($nuevo)[0];
        $chk('editar (sin respuestas): cambia texto y nivel, y registra quién y cuándo',
            $fila['texto'] === 'Texto nuevo' && (int) $fila['nivel_id'] === 1
            && $fila['modificado_en'] !== null && $fila['modificado_por_nombre'] !== '');

        $chk('aislamiento: los criterios del otro año no cambian la conducta del año real', $huella() === $antes);

        // Año real: tiene respuestas → solo redacción.
        if ($k->tieneRespuestas($anioReal)) {
            $chk('año con respuestas: modo «solo redacción»', $k->modoEdicion($anioReal) === 'redaccion');
            $unoReal = $k->listar($anioReal)[0];
            $chk('año con respuestas: no se agrega, retira, mueve ni copia',
                $falla(fn() => $k->crear($anioReal, 'Nuevo', null))
                && $falla(fn() => $k->retirar((int) $unoReal['id'], $usuario))
                && $falla(fn() => $k->mover((int) $unoReal['id'], false))
                && $falla(fn() => $k->copiarDelAnterior($anioReal)));
            $chk('año con respuestas: cambiar el nivel se rechaza',
                $falla(fn() => $k->actualizar((int) $unoReal['id'], $unoReal['texto'], 1, $usuario)));
            $k->actualizar((int) $unoReal['id'], $unoReal['texto'] . ' (corregido)', null, $usuario);
            $corregido = $k->listar($anioReal)[0];
            $chk('año con respuestas: la redacción SÍ se corrige y queda quién y cuándo',
                str_ends_with($corregido['texto'], '(corregido)') && $corregido['modificado_en'] !== null);
            $chk('año con respuestas: los códigos no se tocan',
                array_column($k->listar($anioReal), 'codigo', 'id') === $codigosReales);
        } else {
            echo "  [SKIP] el año activo no tiene conducta registrada: no se prueba el modo «solo redacción»\n";
        }

        // Año cerrado: solo lectura.
        $k->execute("UPDATE anios_academicos SET estado = 'cerrado' WHERE id = ?", [$nuevo]);
        $chk('año cerrado: solo lectura, ni la redacción',
            $k->modoEdicion($nuevo) === 'lectura'
            && $falla(fn() => $k->actualizar((int) $k->listar($nuevo)[0]['id'], 'Otra', 1, $usuario)));
        $chk('un año que no existe es de solo lectura', $k->modoEdicion(999999) === 'lectura');
    } finally {
        $db->rollBack();
    }

    $chk('el rollback no dejó rastro en criterios_conducta',
        (int) $k->queryOne("SELECT COUNT(*) AS n FROM criterios_conducta")['n'] === $filasAntes);
    $chk('el rollback no dejó el año 2099',
        $k->queryOne("SELECT 1 FROM anios_academicos WHERE anio = 2099") === null);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
