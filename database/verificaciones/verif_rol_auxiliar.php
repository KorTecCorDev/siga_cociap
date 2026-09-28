<?php

/**
 * Verificación del rol AUXILIAR ACADÉMICO y de sus asignaciones por bimestre
 * (migraciones 065 y 066, 28/09/2026). Ver docs/modulos/auxiliares.md.
 *
 * Todo lo que escribe va dentro de UNA transacción que se revierte al final:
 * crea dos auxiliares de prueba y asignaciones, y no deja rastro. Nunca limpia
 * con DELETE lo que no creó.
 *
 * Uso: php database/verificaciones/verif_rol_auxiliar.php
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

$m = new App\Models\AuxiliarSeccionModel();

// ── 1) Catálogo y constante ──────────────────────────────────────
echo "1) El rol existe y la constante apunta a él\n";
$rol = $m->queryOne("SELECT id, nombre, codigo, descripcion FROM roles WHERE codigo = ?", [ROL_AUXILIAR]);
$chk("existe el rol '" . ROL_AUXILIAR . "'", $rol !== null);
$chk("nombre = 'Auxiliar académico' (con tilde)", ($rol['nombre'] ?? '') === 'Auxiliar académico');
$dup = $m->queryOne("SELECT COUNT(*) AS n FROM roles GROUP BY codigo HAVING COUNT(*) > 1 LIMIT 1");
$chk('ningún código de rol duplicado', $dup === null);
$chk('existe la tabla auxiliar_secciones',
    $m->queryOne("SHOW TABLES LIKE 'auxiliar_secciones'") !== null);

// ── 2) Superficies que no pueden leer la constante ───────────────
echo "2) Aterrizaje, dashboard, rutas y CSS\n";
$src = fn(string $f): string => (string) file_get_contents(ROOT_PATH . '/' . $f);
$chk('AuthController::redirigirPorRol tiene destino para ROL_AUXILIAR',
    str_contains($src('app/Controllers/Auth/AuthController.php'), "ROL_AUXILIAR        => url('auxiliar/inicio')"));
$chk('DashboardController no lo deja caer a /login (bucle)',
    str_contains($src('app/Controllers/DashboardController.php'), "ROL_AUXILIAR => url('auxiliar/inicio')"));
$chk('rutas GET /admin/auxiliares y POST .../asignar registradas',
    str_contains($src('routes/web.php'), "'/admin/auxiliares',")
    && str_contains($src('routes/web.php'), "'/admin/auxiliares/{seccion_id}/asignar'"));
$chk('el CSS compilado trae el color del avatar del auxiliar',
    str_contains($src('public/css/app.css'), '.usuario-avatar--auxiliar_academico'));

// ── 3) Reglas de asignación (dentro de una transacción) ─────────
echo "3) Asignación por bimestre (transacción + rollback)\n";
$activo = $m->periodoActivo();
if ($activo === null) {
    echo "  [SKIP] no hay bimestre activo en esta base: no se pueden probar las asignaciones\n";
} else {
    $pActivo  = (int) $activo['id'];
    $anterior = $m->queryOne("SELECT id FROM periodos WHERE anio_id = ? AND numero < ? ORDER BY numero DESC LIMIT 1",
        [(int) $activo['anio_id'], (int) $activo['numero']]);
    $siguiente = $m->queryOne("SELECT id FROM periodos WHERE anio_id = ? AND numero > ? ORDER BY numero LIMIT 1",
        [(int) $activo['anio_id'], (int) $activo['numero']]);
    $secs = $m->query("SELECT id FROM secciones WHERE anio_id = ? ORDER BY id LIMIT 2", [(int) $activo['anio_id']]);
    $admin = $m->queryOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' LIMIT 1");
    $docente = $m->queryOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'docente' AND u.estado = 'activo' LIMIT 1");

    $filasAntes = (int) $m->queryOne("SELECT COUNT(*) AS n FROM auxiliar_secciones")['n'];

    $db = Core\Database::get();
    $db->beginTransaction();
    try {
        // Dos auxiliares de prueba.
        $crearAux = function (string $dni, string $nombre) use ($m, $rol): int {
            $m->execute("INSERT INTO personas (dni, apellido_paterno, apellido_materno, nombres, sexo)
                         VALUES (?, 'PRUEBA', 'VERIF', ?, 'F')", [$dni, $nombre]);
            $pid = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
            $m->execute("INSERT INTO usuarios (persona_id, rol_id, password_hash, estado)
                         VALUES (?, ?, 'x', 'activo')", [$pid, (int) $rol['id']]);
            return (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        };
        $a = $crearAux('99000001', 'Auxiliar A');
        $b = $crearAux('99000002', 'Auxiliar B');
        [$s1, $s2] = [(int) $secs[0]['id'], (int) $secs[1]['id']];
        $por = (int) $admin['id'];
        $uA  = ['id' => $a, 'rol_codigo' => ROL_AUXILIAR];
        $uB  = ['id' => $b, 'rol_codigo' => ROL_AUXILIAR];
        $filasDe = fn(int $s): int => (int) $m->queryOne(
            "SELECT COUNT(*) AS n FROM auxiliar_secciones WHERE seccion_id = ?", [$s])['n'];

        $m->asignar($s1, $a, $por);
        $chk('asignar: A es el vigente en el bimestre activo', ($m->vigente($s1, $pActivo)['auxiliar_id'] ?? 0) === $a);
        if ($siguiente) {
            $chk('herencia: A sigue vigente en el bimestre siguiente sin nueva fila',
                ($m->vigente($s1, (int) $siguiente['id'])['auxiliar_id'] ?? 0) === $a);
        }
        if ($anterior) {
            $chk('historial: el bimestre anterior NO recibe la asignación',
                $m->vigente($s1, (int) $anterior['id']) === null);
        }
        $chk('seccionesDe(A) = solo su sección', $m->seccionesDe($a, $pActivo) === [$s1]);

        // puedeRegistrar: las DOS ramas.
        $chk('puedeRegistrar: A en su sección → SÍ', $m->puedeRegistrar($uA, $s1, $pActivo));
        $chk('puedeRegistrar: B en la sección de A → NO', !$m->puedeRegistrar($uB, $s1, $pActivo));
        $chk('puedeRegistrar: A en una sección ajena → NO', !$m->puedeRegistrar($uA, $s2, $pActivo));
        if ($anterior) {
            $chk('puedeRegistrar: A en un bimestre anterior a su asignación → NO',
                !$m->puedeRegistrar($uA, $s1, (int) $anterior['id']));
        }
        $chk('puedeRegistrar: Registro Académico en cualquier sección → SÍ',
            $m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'registro_academico'], $s2, $pActivo));
        $chk('puedeRegistrar: admin → SÍ', $m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'admin'], $s2, $pActivo));
        $chk('puedeRegistrar: docente → NO', !$m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'docente'], $s1, $pActivo));
        $chk('puedeRegistrar: director → NO', !$m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'director_ebr'], $s1, $pActivo));

        // Reasignar dentro del mismo bimestre sobrescribe su fila.
        $m->asignar($s1, $b, $por);
        $chk('cambiar en el mismo bimestre sobrescribe (1 fila, vigente B)',
            $filasDe($s1) === 1 && ($m->vigente($s1, $pActivo)['auxiliar_id'] ?? 0) === $b);

        // Quitar sin nada heredado: vuelve a «sin fila».
        $m->asignar($s1, null, $por);
        $chk('quitar sin herencia previa no deja filas', $filasDe($s1) === 0 && $m->vigente($s1, $pActivo) === null);

        if ($anterior) {
            // Asignación histórica de un bimestre anterior (A), heredada por el activo.
            $m->execute("INSERT INTO auxiliar_secciones (seccion_id, desde_periodo_id, auxiliar_id, asignado_por, asignado_en)
                         VALUES (?, ?, ?, ?, ?)", [$s1, (int) $anterior['id'], $a, $por, date('Y-m-d H:i:s')]);
            $lista = array_column($m->listarParaPeriodo($pActivo), null, 'id');
            $chk('listarParaPeriodo marca la asignación como HEREDADA',
                $lista[$s1]['auxiliar_id'] === $a && $lista[$s1]['cambiado_aqui'] === false);

            $m->asignar($s1, $b, $por);
            $lista = array_column($m->listarParaPeriodo($pActivo), null, 'id');
            $chk('cambiar crea la fila del bimestre activo y la marca como propia',
                $filasDe($s1) === 2 && $lista[$s1]['cambiado_aqui'] === true);

            $m->asignar($s1, $a, $por);
            $chk('volver al heredado borra SOLO la fila del activo (queda la histórica)',
                $filasDe($s1) === 1 && ($m->vigente($s1, (int) $anterior['id'])['auxiliar_id'] ?? 0) === $a);
        }

        // Aviso de hijo/a en la sección: A pasa a ser apoderado de un estudiante
        // del roster de s1; B no tiene hijos. Las dos ramas.
        $alumno = $m->queryOne("SELECT m.estudiante_id FROM matriculas m
                                WHERE m.seccion_id = ? " . roster_evaluacion('m') . " LIMIT 1", [$s1]);
        if ($alumno) {
            $personaA = (int) $m->queryOne("SELECT persona_id FROM usuarios WHERE id = ?", [$a])['persona_id'];
            $m->execute("INSERT INTO apoderados (persona_id) VALUES (?)", [$personaA]);
            $apA = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
            // Tipo de vínculo libre para ese estudiante (UNIQUE estudiante+tipo).
            $col = $m->queryOne("SHOW COLUMNS FROM vinculo_familiar LIKE 'tipo_vinculo'");
            preg_match_all("/'([^']+)'/", (string) $col['Type'], $enum);
            $usados = array_column($m->query("SELECT tipo_vinculo FROM vinculo_familiar WHERE estudiante_id = ?",
                [(int) $alumno['estudiante_id']]), 'tipo_vinculo');
            $libre = array_values(array_diff($enum[1], $usados))[0] ?? null;
            if ($libre !== null) {
                $m->execute("INSERT INTO vinculo_familiar (apoderado_id, estudiante_id, tipo_vinculo, es_responsable)
                             VALUES (?, ?, ?, 0)", [$apA, (int) $alumno['estudiante_id'], $libre]);
                $hijos = $m->seccionesConHijos((int) $activo['anio_id']);
                $chk('aviso: A (apoderado de un alumno de la sección) queda marcado en ella',
                    in_array($s1, $hijos[$a] ?? [], true));
                $chk('aviso: A NO queda marcado en una sección sin hijos suyos',
                    !in_array($s2, $hijos[$a] ?? [], true));
                $chk('aviso: B (sin hijos) no aparece', !isset($hijos[$b]));
            } else {
                echo "  [SKIP] el alumno de prueba no tiene tipo de vínculo libre\n";
            }
        }

        // Validaciones.
        $falla = function (callable $fn): bool {
            try { $fn(); return false; } catch (\DomainException $e) { return true; }
        };
        $chk('rechaza asignar a un usuario que no es auxiliar',
            $docente === null || $falla(fn() => $m->asignar($s2, (int) $docente['id'], $por)));
        $chk('rechaza una sección que no existe en el año activo',
            $falla(fn() => $m->asignar(999999, $a, $por)));

        $m->execute("UPDATE periodos SET estado = 'cerrado' WHERE id = ?", [$pActivo]);
        $chk('sin bimestre activo no se puede asignar', $falla(fn() => $m->asignar($s2, $a, $por)));
    } finally {
        $db->rollBack();
    }

    $filasDespues = (int) $m->queryOne("SELECT COUNT(*) AS n FROM auxiliar_secciones")['n'];
    $chk('el rollback no dejó rastro en auxiliar_secciones', $filasDespues === $filasAntes);
    $chk('el rollback devolvió el bimestre a activo', $m->periodoActivo() !== null);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
