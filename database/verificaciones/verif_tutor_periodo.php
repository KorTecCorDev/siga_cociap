<?php

/**
 * Verificación — el TUTOR DEL BIMESTRE en los documentos (migración 071).
 * Uso: php database/verificaciones/verif_tutor_periodo.php
 *
 * CONTEXTO (01/10/2026). `secciones.tutor_id` es el tutor de HOY. Cambiarlo a
 * mitad de año reescribía hacia atrás el nombre y la firma del tutor en las
 * boletas y actas de los bimestres cerrados. Ahora el cierre congela el tutor
 * de cada sección (`TutorPeriodoModel`) y los documentos lo leen de ahí.
 *
 * QUÉ COMPRUEBA (las DOS ramas de cada regla):
 *   1. Antes de cambiar nada, la boleta nombra al tutor actual.
 *   2. Tras CAMBIAR de tutor con el III en curso:
 *      - 'archivo' (último visible = II cerrado) → el tutor CONGELADO (el anterior).
 *      - 'todos' (vista previa con el III en curso) → el tutor NUEVO.
 *      - `seccionesDelAnio(anio, II)` rotula al anterior pero `tutor_id` es el nuevo.
 *   3. Se SIMULA el cierre del III (estado + congelarPeriodo) sin publicarlo:
 *      - 'archivo' (último cerrado = III) → el NUEVO.
 *      - 'oficial' (último publicado = II) → el ANTERIOR (tutor y notas del mismo bimestre).
 *   4. INMUTABLE: volver a cambiar de tutor y re-congelar el III no pisa la fila.
 *   5. Sin bimestre → tutor actual; fila congelada con tutor NULL → null (no cae al actual).
 *
 * ESCRIBE (cambia el tutor, simula el cierre), pero TODO corre dentro de una
 * TRANSACCIÓN con ROLLBACK; el paso 6 comprueba que el tutor volvió a su valor.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Guarda: nunca contra produccion (este script ESCRIBE, aunque haga rollback).
if (is_file('/home/u761410128/siga_secrets/database.php')) {
    fwrite(STDERR, "ABORTA: detectado el archivo de secretos de PRODUCCION.\n");
    exit(1);
}

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

$pdo    = Core\Database::connect();
$fallos = 0;

$check = static function (string $caso, string $esperado, string $obtenido) use (&$fallos): void {
    if ($esperado === $obtenido) {
        printf("  OK    %-62s [%s]\n", $caso, $obtenido);
        return;
    }
    $fallos++;
    printf("  ***   %-62s esperado [%s] · obtenido [%s]\n", $caso, $esperado, $obtenido);
};

// ── Escenario ─────────────────────────────────────────────────────────
$tablaExiste = $pdo->query("SHOW TABLES LIKE 'secciones_tutor_periodo'")->fetchColumn();
if (!$tablaExiste) {
    fwrite(STDERR, "ABORTA: falta la migracion 071 (secciones_tutor_periodo).\n");
    exit(1);
}

$activo = $pdo->query("
    SELECT p.id, p.anio_id, p.numero FROM periodos p
    INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
    WHERE p.estado = 'activo' ORDER BY p.numero LIMIT 1
")->fetch();
$cerrado = $activo ? $pdo->query("
    SELECT id FROM periodos WHERE anio_id = {$activo['anio_id']} AND estado = 'cerrado'
    ORDER BY numero DESC LIMIT 1
")->fetchColumn() : false;
if (!$activo || !$cerrado) {
    fwrite(STDERR, "ABORTA: hace falta un bimestre ACTIVO y uno CERRADO en el año activo.\n");
    exit(1);
}
$pidActivo  = (int) $activo['id'];
$pidCerrado = (int) $cerrado;

// Matrícula normal (sin retorno) de una sección con tutor, cuyo nivel tenga
// publicado el último bimestre cerrado (para el caso 'oficial').
$mat = $pdo->query("
    SELECT m.id AS matricula_id, s.id AS seccion_id, s.tutor_id
    FROM matriculas m
    INNER JOIN secciones s ON s.id = m.seccion_id AND s.anio_id = {$activo['anio_id']}
    INNER JOIN grados g    ON g.id = s.grado_id
    INNER JOIN periodos_publicacion pp
            ON pp.periodo_id = $pidCerrado AND pp.nivel_id = g.nivel_id
           AND pp.publica_en <= NOW() AND pp.despublicada_en IS NULL AND pp.suspendida_en IS NULL
    WHERE m.estado = 'aprobada' AND m.tipo IN ('continuador', 'nuevo') AND s.tutor_id IS NOT NULL
      AND m.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado)
      AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
    ORDER BY s.id, m.id LIMIT 1
")->fetch();
if (!$mat) {
    fwrite(STDERR, "ABORTA: no hay una matricula regular en una seccion con tutor y bimestre publicado.\n");
    exit(1);
}
$mid       = (int) $mat['matricula_id'];
$sid       = (int) $mat['seccion_id'];
$tutorOrig = (int) $mat['tutor_id'];

// Dos docentes activos que NO son tutores de ninguna sección del año.
$libres = $pdo->query("
    SELECT u.id FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id AND r.codigo = 'docente'
    WHERE u.estado = 'activo'
      AND u.id NOT IN (SELECT tutor_id FROM secciones WHERE tutor_id IS NOT NULL)
    ORDER BY u.id LIMIT 2
")->fetchAll(PDO::FETCH_COLUMN);
if (count($libres) < 2) {
    fwrite(STDERR, "ABORTA: hacen falta 2 docentes activos sin tutoria.\n");
    exit(1);
}
[$tutorNuevo, $tutorTercero] = array_map('intval', $libres);

$nombre = static function (int $usuarioId) use ($pdo): string {
    $st = $pdo->prepare("
        SELECT CONCAT(p.apellido_paterno, ' ', p.apellido_materno, ', ', p.nombres)
        FROM usuarios u INNER JOIN personas p ON p.id = u.persona_id WHERE u.id = ?
    ");
    $st->execute([$usuarioId]);
    return (string) $st->fetchColumn();
};
$nomOrig  = $nombre($tutorOrig);
$nomNuevo = $nombre($tutorNuevo);

$boletas  = new App\Models\BoletaModel();
$tutores  = new App\Models\TutorPeriodoModel();
$seccionM = new App\Models\SeccionModel();

$tutorBoleta = static function (string $datos) use ($boletas, $mid, $pidActivo): string {
    $b = $boletas->armar($mid, $pidActivo, $datos, true);
    return $b === null ? '(sin boleta)' : (string) ($b['tutor']['nombre'] ?? '(sin tutor)');
};

echo "Escenario: matricula $mid · seccion $sid · bimestre cerrado $pidCerrado · activo $pidActivo\n";
echo "           tutor original $tutorOrig · nuevo $tutorNuevo · tercero $tutorTercero\n\n";

$pdo->beginTransaction();
try {
    // Garantiza la fila congelada del bimestre cerrado (lo que hace 071).
    $tutores->congelarPeriodo($pidCerrado, 0);

    echo "=== 1. Sin cambio de tutor ===\n";
    $check("'archivo' nombra al tutor actual", $nomOrig, $tutorBoleta('archivo'));

    echo "=== 2. Cambio de tutor con el bimestre en curso ===\n";
    $pdo->prepare("UPDATE secciones SET tutor_id = ? WHERE id = ?")->execute([$tutorNuevo, $sid]);
    $check("'archivo' (ultimo cerrado) sigue con el congelado", $nomOrig, $tutorBoleta('archivo'));
    $check("'oficial' (ultimo publicado) sigue con el congelado", $nomOrig, $tutorBoleta('oficial'));
    $check("'todos' (vista previa con el bimestre en curso) = nuevo", $nomNuevo, $tutorBoleta('todos'));
    $fila = array_column($seccionM->seccionesDelAnio((int) $activo['anio_id'], $pidCerrado), null, 'id')[$sid];
    $check('seccionesDelAnio(cerrado): rotulo = congelado', $nomOrig, (string) $fila['tutor_nombre']);
    $check('seccionesDelAnio(cerrado): tutor_id = el de HOY', (string) $tutorNuevo, (string) $fila['tutor_id']);
    $fila = array_column($seccionM->seccionesDelAnio((int) $activo['anio_id']), null, 'id')[$sid];
    $check('seccionesDelAnio sin periodo: rotulo = el de HOY', $nomNuevo, (string) $fila['tutor_nombre']);

    echo "=== 3. Se cierra el bimestre en curso (sin publicarlo) ===\n";
    $pdo->prepare("UPDATE periodos SET estado = 'cerrado' WHERE id = ?")->execute([$pidActivo]);
    $tutores->congelarPeriodo($pidActivo, 0);
    $check("'archivo' (ultimo cerrado = el recien cerrado) = nuevo", $nomNuevo, $tutorBoleta('archivo'));
    $check("'oficial' (ultimo publicado = el anterior) = congelado", $nomOrig, $tutorBoleta('oficial'));

    echo "=== 4. Inmutable: re-congelar no pisa ===\n";
    $pdo->prepare("UPDATE secciones SET tutor_id = ? WHERE id = ?")->execute([$tutorTercero, $sid]);
    $tutores->congelarPeriodo($pidActivo, 0);
    $check('el recien cerrado sigue con el nuevo', $nomNuevo, (string) ($tutores->tutorDe($sid, $pidActivo)['nombre'] ?? ''));
    $check('el anterior sigue con el original', $nomOrig, (string) ($tutores->tutorDe($sid, $pidCerrado)['nombre'] ?? ''));

    echo "=== 5. Respaldos ===\n";
    $check('sin bimestre -> tutor de HOY', $nombre($tutorTercero), (string) ($tutores->tutorDe($sid, null)['nombre'] ?? ''));
    $pendiente = $pdo->query("
        SELECT id FROM periodos WHERE anio_id = {$activo['anio_id']} AND estado = 'pendiente' LIMIT 1
    ")->fetchColumn();
    if ($pendiente) {
        $check('bimestre sin congelar -> tutor de HOY', $nombre($tutorTercero),
            (string) ($tutores->tutorDe($sid, (int) $pendiente)['nombre'] ?? ''));
        $pdo->prepare("
            INSERT INTO secciones_tutor_periodo (seccion_id, periodo_id, tutor_id) VALUES (?, ?, NULL)
        ")->execute([$sid, (int) $pendiente]);
        $check('congelado SIN tutor -> null (no cae al de HOY)', '(null)',
            $tutores->tutorDe($sid, (int) $pendiente) === null ? '(null)' : 'cayo al actual');
    } else {
        echo "  --    (no hay bimestre pendiente: se omiten los dos casos de respaldo)\n";
    }
} finally {
    $pdo->rollBack();
}

echo "=== 6. Rollback ===\n";
$st = $pdo->prepare("SELECT tutor_id FROM secciones WHERE id = ?");
$st->execute([$sid]);
$check('el tutor volvio a su valor original', (string) $tutorOrig, (string) $st->fetchColumn());
$st = $pdo->prepare("SELECT estado FROM periodos WHERE id = ?");
$st->execute([$pidActivo]);
$check('el bimestre en curso sigue activo', 'activo', (string) $st->fetchColumn());

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos FALLA(S)\n";
exit($fallos === 0 ? 0 : 1);
