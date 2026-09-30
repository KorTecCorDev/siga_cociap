<?php

/**
 * Desactivar o trasladar a un estudiante apaga SOLO la cuenta de rol `padre`
 * de sus apoderados, NUNCA la de un apoderado que además es personal del
 * colegio (docente, auxiliar…). Ver ApoderadoModel::desactivarUsuarioDeEstudiante.
 *
 * Prueba las DOS ramas dentro de una transacción que se revierte al final:
 *   A) un apoderado con cuenta de docente conserva su cuenta activa;
 *   B) un apoderado con cuenta de padre queda inactivo (el comportamiento que sí se quiere).
 *
 * Uso: php database/verificaciones/verif_desactivar_solo_padre.php
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

$m  = new App\Models\ApoderadoModel();
$db = Core\Database::get();

$estadoDe = fn(int $uid): string => (string) $m->queryOne("SELECT estado FROM usuarios WHERE id = ?", [$uid])['estado'];

$db->beginTransaction();
try {
    // Escenario controlado: un estudiante con DOS apoderados, uno con cuenta de
    // docente y otro con cuenta de padre. Se arma todo aquí para no depender de
    // qué datos traiga la base.
    $est = $m->queryOne("SELECT id FROM estudiantes ORDER BY id LIMIT 1");
    $rolId = fn(string $c): int => (int) $m->queryOne("SELECT id FROM roles WHERE codigo = ?", [$c])['id'];

    $crear = function (string $dni, string $rol, string $vinculo) use ($m, $est, $rolId): int {
        $m->execute("INSERT INTO personas (dni, apellido_paterno, apellido_materno, nombres)
                     VALUES (?, 'PRUEBA', 'VERIF', 'Apoderado')", [$dni]);
        $pid = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        $m->execute("INSERT INTO usuarios (persona_id, rol_id, password_hash, estado) VALUES (?, ?, 'x', 'activo')",
            [$pid, $rolId($rol)]);
        $uid = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        $m->execute("INSERT INTO apoderados (persona_id) VALUES (?)", [$pid]);
        $aid = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        // vincularEstudiante es idempotente por (estudiante, tipo): cada uno con su tipo.
        $m->vincularEstudiante($aid, (int) $est['id'], $vinculo, false);
        return $uid;
    };

    $tipos = array_column($m->query("SELECT DISTINCT tipo_vinculo FROM vinculo_familiar"), 'tipo_vinculo');
    $col = $m->queryOne("SHOW COLUMNS FROM vinculo_familiar LIKE 'tipo_vinculo'");
    preg_match_all("/'([^']+)'/", (string) $col['Type'], $enum);
    $enum = $enum[1] ?: $tipos;

    // Se liberan dos tipos de vínculo en el estudiante de prueba (dentro de la
    // transacción) para que los dos apoderados nuevos no se pisen entre sí.
    $m->execute("DELETE FROM vinculo_familiar WHERE estudiante_id = ? AND tipo_vinculo IN (?, ?)",
        [(int) $est['id'], $enum[0], $enum[1]]);

    $docente = $crear('99000011', 'docente', $enum[0]);
    $padre   = $crear('99000012', 'padre',   $enum[1]);

    $m->desactivarUsuarioDeEstudiante((int) $est['id']);

    $chk('A) el apoderado que es DOCENTE conserva su cuenta activa', $estadoDe($docente) === 'activo');
    $chk('B) el apoderado con cuenta de PADRE queda inactivo', $estadoDe($padre) === 'inactivo');
} finally {
    $db->rollBack();
}

$chk('el rollback no dejó las personas de prueba',
    $m->queryOne("SELECT id FROM personas WHERE dni IN ('99000011','99000012')") === null);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
