<?php

/**
 * Verificación — el ROSTER DE EVALUACIÓN tiene un punto único y nadie lo copia.
 * Uso: php database/verificaciones/verif_roster_evaluacion.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Se puede correr en
 * PRODUCCIÓN (por eso NO lleva el guard de secretos que sí tienen los que escriben).
 *
 * CONTEXTO
 *   Hasta el 27/08/2026 las tres condiciones que definen "a quién se evalúa"
 *   estaban copiadas A MANO en NUEVE consultas de cuatro archivos. Copiar reglas
 *   de negocio a mano es el patrón con el que ya divergieron cuatro veces en este
 *   repositorio, y sin síntoma visible. Ahora salen de `roster_evaluacion()`
 *   (app/Helpers/helpers.php).
 *
 * QUÉ COMPRUEBA
 *   1. El helper emite EXACTAMENTE las tres condiciones esperadas, y respeta el
 *      alias que se le pasa. Es el aserto que detecta que alguien "mejore" el
 *      texto del SQL y cambie el universo sin darse cuenta.
 *   2. NADIE fuera de helpers.php y de las excepciones DOCUMENTADAS vuelve a
 *      escribir el par de exclusiones de retorno con el filtro de `tipo` al lado.
 *      Es lo que impide que nazca la copia número diez.
 *   3. Equivalencia de COMPORTAMIENTO: para cada sección del año activo, los
 *      cuatro métodos de conducta que usan el helper devuelven exactamente las
 *      mismas matrículas que una consulta de control ESCRITA A MANO aquí (no
 *      derivada del helper: si ambos salieran de la misma fuente, el aserto no
 *      probaría nada).
 *
 *   La equivalencia de `getAlumnosSeccion` y de los dos métodos de asistencia ya
 *   la cubre `verif_roster_asistencia.php`, que compara contra su propia copia
 *   de control. Los dos verificadores tienen que quedar verdes.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

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

$pdo = Core\Database::connect();
$ok  = true;

$chk = static function (string $titulo, bool $pasa, string $detalle = '') use (&$ok): void {
    if (!$pasa) { $ok = false; }
    echo ($pasa ? '  OK   ' : '  FALLA '), $titulo, $detalle !== '' ? "  ->  $detalle" : '', "\n";
};

// ── 1. El helper emite literalmente las tres condiciones ──────────────
echo "=== 1. Texto emitido por roster_evaluacion() ===\n";

$esperado = "AND m.tipo NOT IN ('trasladado', 'retirado')\n"
    . "              AND m.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')\n"
    . "              AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido')";

$chk('emite las 3 condiciones esperadas con el alias por defecto',
    roster_evaluacion() === $esperado,
    roster_evaluacion() === $esperado ? '3 condiciones' : 'el texto cambio');

// El alias importa: una consulta que aliase `matriculas` como `mat` recibiria
// SQL invalida si el helper ignorase el parametro, y eso revienta en runtime,
// no aqui. Mejor que salte en el verificador.
$conAlias = roster_evaluacion('mat');
$chk('respeta el alias que se le pasa',
    substr_count($conAlias, 'mat.') === 3 && !str_contains($conAlias, ' m.'),
    substr_count($conAlias, 'mat.') . ' referencias a mat.');

// ── 2. Nadie vuelve a copiar la regla a mano ──────────────────────────
echo "\n=== 2. No hay copias sueltas del filtro ===\n";

// Las tres consultas que legitimamente NO usan el helper, con su motivo. Si
// alguna deja de ser excepcion, se quita de aqui y se migra.
$excepciones = [
    'app/Helpers/helpers.php'                => 'el punto unico',
    'app/Models/CalificacionModel.php'       => "anade estado IN ('aprobada','pendiente')",
    'app/Models/OrdenMeritoModel.php'        => 'universo del merito (ROSTER_MERITO)',
    'app/Models/ControlOperativoModel.php'   => 'universo del merito',
];

$copias = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_PATH));
foreach ($it as $archivo) {
    if ($archivo->getExtension() !== 'php') { continue; }

    $rel = str_replace('\\', '/', substr($archivo->getPathname(), strlen(ROOT_PATH) + 1));
    if (isset($excepciones[$rel])) { continue; }

    $src = (string) file_get_contents($archivo->getPathname());

    // La huella de la copia es el filtro de `tipo` JUNTO a la exclusion de la
    // operativa revertida. Buscar solo `retornos_grado` daria falsos positivos:
    // la boleta y el export SIAGIE usan exclusiones DISTINTAS y correctas.
    if (str_contains($src, "matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido'")
        && str_contains($src, "NOT IN ('trasladado', 'retirado')")) {
        $copias[] = $rel;
    }
}

$chk('ningun archivo fuera de las excepciones repite el filtro',
    empty($copias),
    $copias ? implode(', ', $copias) : count($excepciones) . ' excepcion(es) documentada(s)');

// ── 3. Equivalencia de comportamiento en conducta ─────────────────────
echo "\n=== 3. Los metodos de conducta devuelven el roster de control ===\n";

/**
 * Consulta de CONTROL, escrita a mano y a proposito: si saliera del helper,
 * comparar una con otra no probaria nada.
 */
$rosterControl = static function (int $seccionId) use ($pdo): array {
    $st = $pdo->prepare("
        SELECT m.id
        FROM matriculas m
        WHERE m.seccion_id = ?
          AND m.tipo NOT IN ('trasladado', 'retirado')
          AND m.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')
          AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido')
          AND m.anio_id = (SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1)
    ");
    $st->execute([$seccionId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);
    return $ids;
};

$conducta = new App\Models\ConductaModel();

$secciones = $pdo->query("
    SELECT s.id, s.nombre, g.numero AS grado, g.nivel_id
    FROM secciones s
    INNER JOIN grados g            ON g.id = s.grado_id
    INNER JOIN anios_academicos a  ON a.id = s.anio_id AND a.estado = 'activo'
    WHERE s.estado_nomina = 'aprobada'
    ORDER BY g.nivel_id, g.numero, s.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// El bimestre EN CURSO (primer periodo no cerrado). Hasta el 07/10/2026 servía
// «un periodo cualquiera» porque el roster no dependía del periodo; desde el
// cambio de sección SÍ depende (lista por bimestre, CambioSeccionModel): en un
// bimestre cerrado cursado en otra sección el estudiante está donde lo cursó.
// En el bimestre en curso la sección del periodo es la de HOY, así que el
// control escrito a mano (m.seccion_id) sigue siendo válido y lo que se prueba
// —las exclusiones por tipo y por retorno— no cambia. El roster por bimestre
// lo protege verif_seccion_del_periodo.php.
$periodo = $pdo->query("
    SELECT p.id FROM periodos p
    INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
    ORDER BY (p.estado = 'cerrado'), p.numero LIMIT 1
")->fetchColumn();

$desajustes = [];
$progreso   = $conducta->getProgresoConductaPorSeccion((int) $periodo);

foreach ($secciones as $s) {
    $sid      = (int) $s['id'];
    $esperados = $rosterControl($sid);
    $total     = $conducta->totalCriterios((int) $s['nivel_id']);

    $registro = array_map(
        static fn(array $a): int => (int) $a['matricula_id'],
        $conducta->getEstudiantesParaRegistro($sid, (int) $periodo)
    );
    sort($registro);

    $tutor = array_map(
        static fn(array $a): int => (int) $a['matricula_id'],
        $conducta->getEstudiantesParaTutor($sid, (int) $periodo, $total)
    );
    sort($tutor);

    $legado = array_map(
        static fn(array $a): int => (int) $a['matricula_id'],
        $conducta->getLiteralesLegado($sid, (int) $periodo)
    );
    sort($legado);

    $etq = $s['grado'] . '° ' . $s['nombre'];

    if ($registro !== $esperados) { $desajustes[] = "$etq getEstudiantesParaRegistro"; }
    if ($tutor    !== $esperados) { $desajustes[] = "$etq getEstudiantesParaTutor"; }
    if ($legado   !== $esperados) { $desajustes[] = "$etq getLiteralesLegado"; }

    $esp = (int) ($progreso[$sid]['esperados'] ?? -1);
    if ($esp !== count($esperados)) {
        $desajustes[] = "$etq getProgresoConductaPorSeccion ($esp != " . count($esperados) . ')';
    }

    // completitudSeccion cuenta sobre el mismo universo: sus 'esperados' tienen
    // que ser los mismos, o la compuerta de cierre se abriria de menos o de mas.
    $comp = $conducta->completitudSeccion($sid, (int) $periodo, $total);
    if ((int) ($comp['esperados'] ?? -1) !== count($esperados)) {
        $desajustes[] = "$etq completitudSeccion";
    }
}

$chk('los 5 metodos de ConductaModel cuadran con el control en las '
    . count($secciones) . ' secciones',
    empty($desajustes),
    $desajustes ? $desajustes[0] : count($secciones) * 5 . ' comprobaciones');

echo "\n", $ok
    ? "RESULTADO: OK — el roster de evaluacion tiene un solo dueno.\n"
    : "RESULTADO: HAY FALLOS\n";
exit($ok ? 0 : 1);
