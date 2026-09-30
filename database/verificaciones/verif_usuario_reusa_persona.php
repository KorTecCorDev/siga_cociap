<?php

/**
 * «Nuevo usuario» con un DNI ya registrado (p. ej. un apoderado que pasa a ser
 * auxiliar): se REUTILIZA la persona y valen sus datos registrados; solo se
 * completan los vacíos (decisión del usuario, 28/09/2026).
 *
 * Transacción + rollback: no deja rastro.
 * Uso: php database/verificaciones/verif_usuario_reusa_persona.php
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

$m  = new App\Models\UsuarioModel();
$db = Core\Database::get();
$rolAux = (int) $m->queryOne("SELECT id FROM roles WHERE codigo = ?", [ROL_AUXILIAR])['id'];
$personas = (int) $m->queryOne("SELECT COUNT(*) AS n FROM personas")['n'];

$db->beginTransaction();
try {
    // Apoderado registrado por matrícula: sin sexo ni correo, con celular.
    $m->execute("INSERT INTO personas (dni, apellido_paterno, apellido_materno, nombres, telefono)
                 VALUES ('99000021', 'APODERADO', 'REGISTRADO', 'DATOS ORIGINALES', '900111222')");
    $pid = (int) $db->lastInsertId();
    $m->execute("INSERT INTO apoderados (persona_id) VALUES (?)", [$pid]);

    $p = $m->personaPorDni('99000021');
    $chk('personaPorDni encuentra al apoderado, sin cuenta', $p !== null && $p['usuario_id'] === null && (bool) $p['es_apoderado']);
    $chk('personaPorDni devuelve NULL para un DNI inexistente', $m->personaPorDni('99000029') === null);

    // El formulario trae datos DISTINTOS: no deben pisar lo registrado.
    $uid = $m->crearSobrePersona($pid, ['sexo' => 'F', 'correo' => 'aux@prueba.pe', 'telefono' => '999999999'], 'clave-prueba', $rolAux);
    $d = $m->queryOne("SELECT p.*, u.rol_id FROM personas p JOIN usuarios u ON u.persona_id = p.id WHERE u.id = ?", [$uid]);

    $chk('la cuenta se creó sobre la MISMA persona (no se duplicó)', (int) $d['id'] === $pid);
    $chk('los nombres registrados NO cambiaron', $d['nombres'] === 'DATOS ORIGINALES' && $d['apellido_paterno'] === 'APODERADO');
    $chk('el celular registrado NO se sobrescribió', $d['telefono'] === '900111222');
    $chk('el sexo vacío SÍ se completó', $d['sexo'] === 'F');
    $chk('el correo vacío SÍ se completó', $d['correo'] === 'aux@prueba.pe');
    $chk('la cuenta tiene el rol elegido', (int) $d['rol_id'] === $rolAux);

    $p2 = $m->personaPorDni('99000021');
    $chk('ahora personaPorDni reporta que YA tiene cuenta', $p2['usuario_id'] !== null);

    $segunda = false;
    try { $m->crearSobrePersona($pid, [], 'otra-clave', $rolAux); } catch (\Throwable $e) { $segunda = true; }
    $chk('una segunda cuenta para la misma persona se rechaza (UNIQUE persona_id)', $segunda);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$chk('el rollback no dejó personas de prueba',
    (int) $m->queryOne("SELECT COUNT(*) AS n FROM personas")['n'] === $personas);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
