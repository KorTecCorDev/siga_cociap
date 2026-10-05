<?php

/**
 * Verificación — CICLO DE VIDA del retorno de grado con TRAMO (05/10/2026,
 * migración 073, punto único `RetornoGradoModel`).
 * Uso: php database/verificaciones/verif_retorno_ciclo.php
 *
 * NO ESCRIBE NADA. Corre en UNA transacción real que nunca se confirma: la
 * conexión de la app ignora los beginTransaction/commit/rollBack de los
 * controladores, y cada rama se aísla con un SAVEPOINT. Los redirect() de los
 * controladores se convierten en excepción para poder encadenar los pasos.
 * Lleva el guard de secretos: no se corre en producción.
 *
 * POR QUÉ EXISTE
 *   Hasta el 05/10/2026 cada consulta DEDUCÍA en qué matrícula cursó el
 *   estudiante cada bimestre, y al revertir el retorno #1 fallaron en cadena
 *   el lote de boletas, la situación final, los cuadros y la boleta. Desde la
 *   073 el tramo se guarda; esto prueba el ciclo completo con el código real.
 *
 * QUÉ COMPRUEBA
 *   A. Retorno #1 (datos reales): cada bimestre sale UNA vez, desde la matrícula
 *      que lo cursó, en mérito, alerta, situación, lote y boleta.
 *   B. Ciclo sobre un estudiante de prueba:
 *      1. retorno con el bimestre en curso y sin notas → `desde` = primer sin cerrar;
 *      2. reversión en el MISMO bimestre → tramo VACÍO (`hasta` NULL);
 *      3. reversión con notas de la operativa sin cerrar → BLOQUEADA;
 *      4. la misma reversión tras CERRAR el bimestre → `hasta` = ese bimestre;
 *      5. segundo retorno sobre la misma matrícula → RECHAZADO (también sobre la
 *         operativa);
 *      6. retorno a una sección de OTRO nivel → RECHAZADO.
 *      En cada estado, las invariantes de A.
 *   C. Guardas de gestión (F4): la operativa no se activa, desactiva, retira ni
 *      traslada; la oficial de un retorno ACTIVO no se retira ni se traslada.
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VIEW_PATH', ROOT_PATH . '/resources/views');

if (is_file('/home/u761410128/siga_secrets/database.php')) {
    fwrite(STDERR, "ABORTA: detectado el archivo de secretos de PRODUCCION.\n");
    exit(2);
}

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

use App\Controllers\Matricula\MatriculaController;
use App\Controllers\Matricula\RetornoGradoController;
use App\Controllers\Matricula\TrasladoController;
use App\Models\BoletaModel;
use App\Models\BoletaPublicaModel;
use App\Models\ControlOperativoModel;
use App\Models\OrdenMeritoModel;
use App\Models\RetornoGradoModel;
use App\Models\SituacionFinalModel;

/** Señal de redirect: el controlador terminó (con éxito o con error). */
final class Redirigio extends RuntimeException
{
    public function __construct(public readonly string $tipo, public readonly string $texto)
    {
        parent::__construct($texto);
    }
}

// ── Conexión que nunca confirma ─────────────────────────────────────────
$cfg = require CONFIG_PATH . '/database.php';
$pdo = new class (
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
    $cfg['username'], $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"]
) extends PDO {
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function abrirReal(): void { parent::beginTransaction(); }
    public function deshacerReal(): void { parent::rollBack(); }
};
(new ReflectionProperty(Core\Database::class, 'instance'))->setValue(null, $pdo);
$pdo->abrirReal();

$_SESSION['auth_user']   = ['id' => 1, 'rol_codigo' => 'admin', 'nombres' => 'Prueba', 'apellido_paterno' => 'Verificador'];
$_SESSION['_csrf_token'] = 'verif';

/**
 * Llama a una acción del controlador REAL (subclase anónima que convierte el
 * redirect en excepción) y devuelve [tipo, texto] del flash.
 */
function accion(string $clase, string $metodo, string $id, array $post = []): array
{
    $_POST = ['_csrf_token' => 'verif'] + $post;
    $ctrl = match ($clase) {
        'retorno' => new class extends RetornoGradoController {
            protected function redirectWithSuccess(string $url, string $m): never { throw new Redirigio('ok', $m); }
            protected function redirectWithError(string $url, string $m): never { throw new Redirigio('error', $m); }
        },
        'matricula' => new class extends MatriculaController {
            protected function redirectWithSuccess(string $url, string $m): never { throw new Redirigio('ok', $m); }
            protected function redirectWithError(string $url, string $m): never { throw new Redirigio('error', $m); }
        },
        'traslado' => new class extends TrasladoController {
            protected function redirectWithSuccess(string $url, string $m): never { throw new Redirigio('ok', $m); }
            protected function redirectWithError(string $url, string $m): never { throw new Redirigio('error', $m); }
        },
    };
    try {
        ob_start();
        $ctrl->$metodo($id);
        ob_end_clean();
        return ['sin_redirect', ''];
    } catch (Redirigio $r) {
        ob_end_clean();
        return [$r->tipo, $r->texto];
    }
}

$ok  = true;
$chk = function (string $t, bool $c, string $extra = '') use (&$ok) {
    printf("  [%s] %s%s\n", $c ? 'OK ' : 'FAIL', $t, $extra !== '' ? " — $extra" : '');
    $ok = $ok && $c;
};
$col = static fn(string $sql) => $pdo->query($sql)->fetchColumn();

printf("bd=%s\n", $col('SELECT DATABASE()'));
$anioId   = (int) $col("SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1");
$periodos = $pdo->query("SELECT id, numero, estado FROM periodos WHERE anio_id = {$anioId} ORDER BY numero")
                ->fetchAll(PDO::FETCH_ASSOC);

/**
 * INVARIANTES de un estudiante con retorno (oficial $of, operativa $op): en cada
 * bimestre con datos posibles sale UNA vez y desde la matrícula que lo CURSÓ.
 * El tramo esperado se calcula A MANO, no con RetornoGradoModel.
 */
$invariantes = function (string $tag, int $of, int $op) use ($pdo, $periodos, $chk): void {
    $r = $pdo->query("SELECT r.estado, pd.numero AS desde, ph.numero AS hasta
                      FROM retornos_grado r JOIN periodos pd ON pd.id = r.periodo_desde_id
                      LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id
                      WHERE r.matricula_oficial_id = {$of}")->fetch(PDO::FETCH_ASSOC);
    $grados = $pdo->query("SELECT DISTINCT s.grado_id FROM matriculas m JOIN secciones s ON s.id = m.seccion_id
                           WHERE m.id IN ({$of}, {$op})")->fetchAll(PDO::FETCH_COLUMN);
    $ofUbic = $pdo->query("SELECT s.grado_id, s.nombre FROM matriculas m JOIN secciones s ON s.id = m.seccion_id
                           WHERE m.id = {$of}")->fetch(PDO::FETCH_ASSOC);
    $ofGrado   = (int) $ofUbic['grado_id'];
    $ofSeccion = (string) $ofUbic['nombre'];

    $om = new OrdenMeritoModel();
    $live = new ReflectionMethod($om, 'rankingGradoLive');
    $live->setAccessible(true);
    $fallos = [];

    foreach ($periodos as $p) {
        $num = (int) $p['numero'];
        $pid = (int) $p['id'];
        if ($p['estado'] === 'pendiente') { continue; }
        $cubre   = $num >= (int) $r['desde']
                && ($r['estado'] === 'activo' || ($r['hasta'] !== null && $num <= (int) $r['hasta']));
        $cursada = $cubre ? $op : $of;
        $otra    = $cubre ? $of : $op;

        // Punto único contra el cálculo a mano.
        $rg = new RetornoGradoModel();
        if ($rg->matriculaDelPeriodo($of, $pid) !== $cursada || $rg->matriculaDelPeriodo($op, $pid) !== $cursada) {
            $fallos[] = "B{$num} matriculaDelPeriodo";
        }

        // Mérito en vivo: nunca la otra; como mucho una vez.
        $veces = 0;
        foreach ($grados as $g) {
            foreach ($live->invoke($om, (int) $g, $pid) as $fila) {
                $m = (int) $fila['matricula_id'];
                if ($m === $otra) { $fallos[] = "B{$num} merito trae la matricula que NO curso ({$m})"; }
                if ($m === $of || $m === $op) { $veces++; }
            }
        }
        if ($veces > 1) { $fallos[] = "B{$num} merito: {$veces} veces"; }

        // Alerta de evaluación incompleta: nunca la otra.
        foreach ((new ControlOperativoModel())->alertasEvaluacionIncompleta($pid) as $a) {
            if ((int) $a['matricula_id'] === $otra) { $fallos[] = "B{$num} alerta trae la matricula que NO curso"; break; }
        }

        // Situación final: como mucho UNA fila del estudiante, REUBICADA en el
        // grado y la sección OFICIALES (la fila conserva el id de la matrícula que
        // cursó el bimestre; así lo vigila también verif_riesgo_situacion_bd).
        $filas = 0;
        foreach ((new SituacionFinalModel())->porGrado($pid) as $g) {
            foreach (array_merge($g['en_riesgo'], $g['seguimiento']) as $al) {
                $m = (int) $al['matricula_id'];
                if ($m !== $of && $m !== $op) { continue; }
                $filas++;
                if ($m === $otra) { $fallos[] = "B{$num} situacion trae la matricula que NO curso"; }
                if ((int) $g['grado']['id'] !== $ofGrado || (string) $al['seccion_nombre'] !== $ofSeccion) {
                    $fallos[] = "B{$num} situacion fuera de la seccion oficial";
                }
            }
        }
        if ($filas > 1) { $fallos[] = "B{$num} situacion: {$filas} filas"; }

        // Lote de boletas: solo la oficial, como mucho una vez.
        $lote = array_map('intval', array_column((new BoletaPublicaModel())->getEstudiantesParaPeriodo($pid), 'matricula_id'));
        if (in_array($op, $lote, true)) { $fallos[] = "B{$num} el lote trae la operativa"; }
        if (count(array_keys($lote, $of, true)) > 1) { $fallos[] = "B{$num} el lote repite la oficial"; }
        // ...y la oficial ESTÁ si la matrícula que cursó tiene alguna competencia
        // bloqueada en el bimestre (el defecto del II: 2.° B pasaba de 19 a 18).
        $esperaLote = (bool) $pdo->query("
            SELECT (SELECT estado FROM matriculas WHERE id = {$of}) = 'aprobada' AND EXISTS (
                SELECT 1 FROM calificaciones c JOIN bloqueos_competencia bc
                    ON bc.carga_id = c.carga_id AND bc.competencia_id = c.competencia_id AND bc.periodo_id = c.periodo_id
                WHERE c.matricula_id = {$cursada} AND c.periodo_id = {$pid})")->fetchColumn();
        if ($esperaLote !== in_array($of, $lote, true)) {
            $fallos[] = "B{$num} lote: se esperaba " . ($esperaLote ? 'que estuviera' : 'que NO estuviera');
        }

        // Boleta: la misma entrando por cualquiera de las dos.
        $b = new BoletaModel();
        if (json_encode($b->armar($of, $pid, 'todos', true)) !== json_encode($b->armar($op, $pid, 'todos', true))) {
            $fallos[] = "B{$num} la boleta difiere segun la matricula de entrada";
        }
    }
    $chk("{$tag}: cada bimestre UNA vez y desde la matricula que lo curso", $fallos === [],
        $fallos ? implode('; ', array_slice($fallos, 0, 4)) : 'merito, alerta, situacion, lote y boleta');
};

try {
    // ── A. Retorno real ──────────────────────────────────────────────────
    echo "\nA. Retornos existentes\n";
    $reales = $pdo->query("SELECT matricula_oficial_id AS o, matricula_operativa_id AS p, estado FROM retornos_grado ORDER BY id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    if (!$reales) { echo "  (sin retornos en la BD)\n"; }
    foreach ($reales as $re) {
        $invariantes("retorno {$re['o']}/{$re['p']} ({$re['estado']})", (int) $re['o'], (int) $re['p']);
    }

    // ── B. Ciclo sobre un estudiante de prueba ───────────────────────────
    echo "\nB. Ciclo de vida sobre un estudiante de prueba\n";
    // Primaria 3.° o superior, sin retorno, vigente, con notas en algún bimestre cerrado.
    $of = (int) $col("
        SELECT m.id FROM matriculas m
        JOIN secciones s ON s.id = m.seccion_id JOIN grados g ON g.id = s.grado_id
        WHERE m.anio_id = {$anioId} AND m.estado = 'aprobada' AND m.tipo IN ('nuevo', 'continuador')
          AND g.numero >= 3 AND g.nivel_id = (SELECT nivel_id FROM grados WHERE id = (
                SELECT s2.grado_id FROM matriculas m2 JOIN secciones s2 ON s2.id = m2.seccion_id
                WHERE m2.id = (SELECT MIN(matricula_oficial_id) FROM retornos_grado)))
          AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado)
          AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
          AND EXISTS (SELECT 1 FROM calificaciones c JOIN periodos p ON p.id = c.periodo_id
                      WHERE c.matricula_id = m.id AND p.estado = 'cerrado')
        ORDER BY m.id LIMIT 1");
    if (!$of) {
        $of = (int) $col("SELECT m.id FROM matriculas m JOIN secciones s ON s.id = m.seccion_id
                          JOIN grados g ON g.id = s.grado_id
                          WHERE m.anio_id = {$anioId} AND m.estado = 'aprobada' AND g.numero >= 3
                            AND m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado)
                          ORDER BY m.id LIMIT 1");
    }
    $info = $pdo->query("SELECT s.grado_id, g.numero, g.nivel_id FROM matriculas m JOIN secciones s ON s.id = m.seccion_id
                         JOIN grados g ON g.id = s.grado_id WHERE m.id = {$of}")->fetch(PDO::FETCH_ASSOC);
    $destino = (int) $col("SELECT s.id FROM secciones s JOIN grados g ON g.id = s.grado_id
                           WHERE s.anio_id = {$anioId} AND g.nivel_id = {$info['nivel_id']}
                             AND g.numero = {$info['numero']} - 1 ORDER BY s.id LIMIT 1");
    $otroNivel = (int) $col("SELECT s.id FROM secciones s JOIN grados g ON g.id = s.grado_id
                             WHERE s.anio_id = {$anioId} AND g.nivel_id <> {$info['nivel_id']} ORDER BY s.id LIMIT 1");
    printf("  estudiante de prueba: matricula %d (grado %d.°), destino seccion %d\n", $of, $info['numero'], $destino);

    // Sin evaluación en los bimestres sin cerrar (el candado de store() la exige).
    $sinCerrar = "SELECT id FROM periodos WHERE estado <> 'cerrado'";
    $pdo->exec("DELETE cc FROM calificaciones_criterio cc JOIN criterios k ON k.id = cc.criterio_id
                WHERE cc.matricula_id = {$of} AND k.periodo_id IN ({$sinCerrar})");
    $pdo->exec("DELETE oc FROM omisiones_criterio oc JOIN criterios k ON k.id = oc.criterio_id
                WHERE oc.matricula_id = {$of} AND k.periodo_id IN ({$sinCerrar})");
    $pdo->exec("DELETE FROM calificaciones WHERE matricula_id = {$of} AND periodo_id IN ({$sinCerrar})");

    $retornoDe = fn(int $m) => $pdo->query("SELECT r.*, pd.numero AS desde_num, ph.numero AS hasta_num
        FROM retornos_grado r JOIN periodos pd ON pd.id = r.periodo_desde_id
        LEFT JOIN periodos ph ON ph.id = r.periodo_hasta_id WHERE r.matricula_oficial_id = {$m}")->fetch(PDO::FETCH_ASSOC);
    $primerSinCerrar = $pdo->query("SELECT id, numero FROM periodos WHERE anio_id = {$anioId} AND estado <> 'cerrado'
                                    ORDER BY numero LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // 6. Otro nivel (antes de crear nada).
    if ($otroNivel) {
        [$t, $m] = accion('retorno', 'store', (string) $of, ['seccion_destino_id' => (string) $otroNivel, 'motivo' => 'VERIF']);
        $chk('6. retorno a una seccion de OTRO nivel: rechazado', $t === 'error' && !$retornoDe($of), $m);
    }

    // 1. Retorno con el bimestre en curso, sin notas.
    [$t, $m] = accion('retorno', 'store', (string) $of, ['seccion_destino_id' => (string) $destino, 'motivo' => 'VERIF ciclo']);
    $ret = $retornoDe($of);
    $chk('1. retorno registrado', $t === 'ok' && $ret !== false, $m);
    if (!$ret) { throw new RuntimeException('sin retorno de prueba: no se puede seguir'); }
    $op = (int) $ret['matricula_operativa_id'];
    $chk("1. desde = primer bimestre sin cerrar (B{$primerSinCerrar['numero']}), hasta NULL",
        (int) $ret['periodo_desde_id'] === (int) $primerSinCerrar['id'] && $ret['periodo_hasta_id'] === null);
    $invariantes('1. retorno ACTIVO', $of, $op);

    // 5. Segundo retorno: sobre la oficial y sobre la operativa.
    [$t, $m] = accion('retorno', 'store', (string) $of, ['seccion_destino_id' => (string) $destino, 'motivo' => 'VERIF']);
    $chk('5. segundo retorno sobre la oficial: rechazado', $t === 'error' && str_contains($m, 'ya tiene'), $m);
    // Destino inferior a la OPERATIVA, para que el rechazo sea el de «ya tiene retorno».
    $destino2 = (int) $col("SELECT s.id FROM secciones s JOIN grados g ON g.id = s.grado_id
                            WHERE s.anio_id = {$anioId} AND g.nivel_id = {$info['nivel_id']}
                              AND g.numero = {$info['numero']} - 2 ORDER BY s.id LIMIT 1");
    [$t, $m] = accion('retorno', 'store', (string) $op, ['seccion_destino_id' => (string) ($destino2 ?: $destino), 'motivo' => 'VERIF']);
    $chk('5. retorno sobre la operativa: rechazado', $t === 'error'
        && ($destino2 ? str_contains($m, 'operativa de un retorno') : true)
        && (int) $col("SELECT COUNT(*) FROM retornos_grado WHERE matricula_oficial_id = {$op}") === 0, $m);

    // C. Guardas de gestión con el retorno ACTIVO.
    echo "\nC. Guardas de gestion (retorno activo)\n";
    foreach (['activar', 'desactivar', 'retirar', 'revertirRetiro'] as $met) {
        [$t, $m] = accion('matricula', $met, (string) $op, ['motivo' => 'VERIF']);
        $chk("operativa: {$met} rechazado", $t === 'error' && str_contains($m, 'operativa de un retorno'), $m);
    }
    [$t, $m] = accion('traslado', 'form', (string) $op);
    $chk('operativa: trasladar rechazado', $t === 'error' && str_contains($m, 'operativa de un retorno'), $m);
    [$t, $m] = accion('traslado', 'form', (string) $of);
    $chk('oficial con retorno ACTIVO: trasladar rechazado', $t === 'error' && str_contains($m, 'Revierte el retorno'), $m);
    $pdo->exec("UPDATE matriculas SET estado = 'desactivado' WHERE id = {$of}");
    [$t, $m] = accion('matricula', 'retirar', (string) $of);
    $chk('oficial con retorno ACTIVO: retirar rechazado', $t === 'error' && str_contains($m, 'Revierte el retorno'), $m);
    $pdo->exec("UPDATE matriculas SET estado = 'aprobada' WHERE id = {$of}");
    $chk('las guardas no tocaron la operativa',
        $col("SELECT CONCAT(estado, '/', tipo) FROM matriculas WHERE id = {$op}") === 'aprobada/continuador');

    echo "\nB (cont.)\n";
    // La reversión exige reabrir los cierres vigentes de conducta y asistencia de
    // las dos secciones en los bimestres sin cerrar (guarda 2 de bloqueoReversion,
    // probada en verif_reversion_retorno). Aquí se reabren para seguir el ciclo.
    $secOf = (int) $col("SELECT seccion_id FROM matriculas WHERE id = {$of}");
    foreach (['cierres_conducta', 'cierres_asistencia'] as $t) {
        $pdo->exec("UPDATE {$t} SET anulado_en = NOW() WHERE anulado_en IS NULL
                    AND seccion_id IN ({$destino}, {$secOf}) AND periodo_id IN ({$sinCerrar})");
    }
    $pdo->exec('SAVEPOINT ciclo_activo');

    // 2. Reversión en el MISMO bimestre, sin notas → tramo vacío.
    [$t, $m] = accion('retorno', 'revertir', (string) $of, ['motivo' => 'VERIF mismo bimestre']);
    $ret = $retornoDe($of);
    $chk('2. reversion en el mismo bimestre: procede', $t === 'ok' && $ret['estado'] === 'revertido', $m);
    $chk('2. tramo VACIO (hasta NULL)', $ret['periodo_hasta_id'] === null);
    $invariantes('2. retorno REVERTIDO con tramo vacio', $of, $op);
    [$t, $m] = accion('retorno', 'store', (string) $of, ['seccion_destino_id' => (string) $destino, 'motivo' => 'VERIF']);
    $chk('5. nuevo retorno tras revertir: rechazado (uno por año)', $t === 'error' && str_contains($m, 'revertido'), $m);
    [$t, $m] = accion('matricula', 'activar', (string) $op);
    $chk('C. operativa REVERTIDA: activar rechazado', $t === 'error' && str_contains($m, 'operativa de un retorno'), $m);
    [$t, $m] = accion('traslado', 'form', (string) $of);
    $chk('C. oficial de un retorno REVERTIDO: la guarda de retorno ya no aplica',
        !str_contains($m, 'Revierte el retorno') && !str_contains($m, 'operativa de un retorno'), $m ?: $t);

    $pdo->exec('ROLLBACK TO SAVEPOINT ciclo_activo');

    // 3. Reversión con NOTAS de la operativa en un bimestre sin cerrar → bloqueada.
    // Fixture: un criterio nuevo del bimestre en curso en la sección destino.
    $base = $pdo->query("
        SELECT ca.id AS carga_id, c.id AS comp_id
        FROM cargas_academicas ca
        JOIN competencias c ON (ca.subarea_id IS NOT NULL AND c.subarea_id = ca.subarea_id)
                            OR (ca.subarea_id IS NULL AND c.area_id = ca.area_id)
        WHERE ca.seccion_id = {$destino} AND ca.estado = 'activa'
        ORDER BY ca.id, c.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $crit = null;
    if ($base) {
        $pdo->prepare("INSERT INTO criterios (carga_id, competencia_id, periodo_id, nombre, orden)
                       VALUES (?, ?, ?, 'VERIF ciclo', 99)")
            ->execute([$base['carga_id'], $base['comp_id'], $primerSinCerrar['id']]);
        $crit = (int) $pdo->lastInsertId();
    }
    if (!$crit) {
        $chk('3. hay un criterio del bimestre en curso en la seccion destino (fixture)', false);
    } else {
        $pdo->exec("INSERT INTO calificaciones_criterio (criterio_id, matricula_id, nota, registrado_en)
                    VALUES ({$crit}, {$op}, 15, NOW())");
        [$t, $m] = accion('retorno', 'revertir', (string) $of, ['motivo' => 'VERIF con notas']);
        $chk('3. reversion con notas sin cerrar: BLOQUEADA', $t === 'error' && $retornoDe($of)['estado'] === 'activo', $m);
        $invariantes('3. retorno ACTIVO con notas en la operativa', $of, $op);

        // 4. Se cierra ese bimestre: la reversión procede y el tramo lo incluye.
        $pdo->exec("UPDATE periodos SET estado = 'cerrado' WHERE id = {$primerSinCerrar['id']}");
        [$t, $m] = accion('retorno', 'revertir', (string) $of, ['motivo' => 'VERIF tras cierre']);
        $ret = $retornoDe($of);
        $chk('4. reversion tras cerrar el bimestre: procede', $t === 'ok' && $ret['estado'] === 'revertido', $m);
        $chk("4. hasta = el bimestre cerrado (B{$primerSinCerrar['numero']})",
            (int) $ret['periodo_hasta_id'] === (int) $primerSinCerrar['id'], 'hasta=' . var_export($ret['periodo_hasta_id'], true));
        $periodos = $pdo->query("SELECT id, numero, estado FROM periodos WHERE anio_id = {$anioId} ORDER BY numero")
                        ->fetchAll(PDO::FETCH_ASSOC);
        $invariantes('4. retorno REVERTIDO con un bimestre cursado', $of, $op);
        $chk('4. ese bimestre se lee de la operativa',
            (new RetornoGradoModel())->matriculaDelPeriodo($of, (int) $primerSinCerrar['id']) === $op);
    }
} finally {
    $pdo->deshacerReal();
}

$chk("\nnada quedo escrito",
    (int) Core\Database::connect()->query("SELECT COUNT(*) FROM retornos_grado WHERE motivo LIKE 'VERIF%'")->fetchColumn() === 0);

echo $ok ? "\nTODO OK\n" : "\nHAY FALLOS\n";
exit($ok ? 0 : 1);
