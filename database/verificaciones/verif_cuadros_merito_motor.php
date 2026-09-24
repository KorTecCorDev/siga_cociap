<?php

/**
 * Verificación — los indicadores por grado salen del MOTOR OFICIAL del mérito.
 * Uso: php database/verificaciones/verif_cuadros_merito_motor.php
 *
 * SOLO LECTURA: no escribe nada, no abre transacciones. Corre en PRODUCCIÓN
 * (por eso NO lleva el guard del archivo de secretos).
 *
 * POR QUÉ EXISTE
 *   Hasta el 04/09/2026 `AnioAcademicoModel::getStatsCierre` calculaba su propio
 *   ranking por grado (`getRankingGrado`, privado), una copia simplificada del
 *   universo del mérito: sin exigir competencias BLOQUEADAS, sin excluir
 *   extraordinarias ni áreas exoneradas, con la TOE entera en vez de solo Ética,
 *   y sin ROSTER_MERITO, anclaje de retorno ni cascada de desempate. Alimentaba
 *   tres pantallas —`/admin/cuadros`, su imprimible A4 y
 *   `/director/periodos/{id}/stats`— con cifras plausibles y falsas.
 *
 *   Medido el 04/09/2026 ANTES de migrar, en el bimestre ABIERTO: 1.º primaria
 *   anunciaba un primer puesto con 22 competidores donde el orden de mérito
 *   tiene CERO (nada bloqueado aún), y el último puesto de 1.º secundaria salía
 *   12.50 contra los 15.00 reales. En bimestre CERRADO las dos fuentes
 *   coincidían: el defecto no tenía síntoma para quien mirara un bimestre ya
 *   cerrado, que es lo que lo mantuvo vivo.
 *
 *   Es otra instancia del patrón de fallo del repositorio (cascada de empates,
 *   retorno de grado, universo del mérito, carga dueña de las transversales).
 *   Este verificador es lo que impide que vuelva: si alguien reescribe un
 *   ranking dentro de `AnioAcademicoModel`, aquí falla.
 *
 * QUÉ COMPRUEBA, para CADA periodo con ranking:
 *   1. IDENTIDAD DE FUENTE: `getStatsCierre()['por_grado']` cuadra grado a grado
 *      con `OrdenMeritoModel::rankingGrado` — mismos grados y en su orden, mismo
 *      `total`, misma matrícula en el primer puesto y mismo promedio.
 *   2. `peores` sale de la cola de ese mismo ranking y nunca incluye al 1.er puesto.
 *   3. NO HAY RANKING PROPIO: ninguna consulta de `AnioAcademicoModel` ordena
 *      estudiantes por promedio. Las dos de arriba miden datos; ésta impide que
 *      la copia renazca.
 *
 *      ⚠️ Lo que se prohíbe es el RANKING, no el promedio. `getResumenBimestre`
 *      sigue promediando por estudiante a propósito, y debe seguir haciéndolo:
 *      su «en riesgo» es OTRA pregunta —promedio general por debajo de
 *      NOTA_MIN_B, contado por nivel— y alimenta el bloque de Calificaciones de
 *      la misma pantalla.
 *
 * 🔴 EL RIESGO ACADÉMICO YA NO SE VERIFICA AQUÍ (23/09/2026). Vivió en
 *   `statsPorGrado` mientras fue un conteo de competencias en C, y de ahí
 *   heredaba el roster del mérito (`estado = 'aprobada'`) y sus agregados
 *   globales. Ahora es la SITUACIÓN FINAL del MINEDU, que se cuenta POR ÁREA y
 *   tiene modelo y roster propios. Sus asertos están en
 *   `verif_situacion_final.php` (la regla, con casos sintéticos y sin tocar la
 *   BD) y en `verif_riesgo_situacion_bd.php` (el cuadre con los datos reales,
 *   incluida la simulación del retorno de grado). Los tres tienen que quedar en
 *   verde.
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
date_default_timezone_set(config('timezone'));

use App\Models\AnioAcademicoModel;
use App\Models\OrdenMeritoModel;

$pdo = Core\Database::connect();

$fallos = 0;
$ok = function (bool $cond, string $etiqueta, string $detalle = '') use (&$fallos): void {
    printf("  %-5s %-58s %s\n", $cond ? 'OK' : '***', $etiqueta, $detalle);
    if (!$cond) { $fallos++; }
};

echo "== Huella ==\n";
printf("  bd=%s · usuario=%s · so=%s\n\n",
    $pdo->query("SELECT DATABASE()")->fetchColumn(),
    $pdo->query("SELECT USER()")->fetchColumn(),
    $pdo->query("SELECT @@version_compile_os")->fetchColumn());

$periodos = $pdo->query("
    SELECT p.id, p.numero, p.nombre_display, p.estado
    FROM periodos p
    ORDER BY p.anio_id, p.numero
")->fetchAll(PDO::FETCH_ASSOC);

// ── 1-2. Los datos, periodo a periodo ─────────────────────────────
$gradosMedidos = 0;

foreach ($periodos as $p) {
    $pid = (int) $p['id'];

    // Instancias NUEVAS por periodo: `debeUsarSnapshot` está memoizado, y
    // reutilizarlas podría esconder un fallo de memoización entre bimestres.
    $stats  = (new AnioAcademicoModel())->getStatsCierre($pid);
    $merito = new OrdenMeritoModel();

    $porGrado = $stats['por_grado'];
    if (!$porGrado) {
        printf("== %s (%s) ==  sin ranking, se omite\n\n", $p['nombre_display'], $p['estado']);
        continue;
    }

    printf("== %s (%s) ==\n", $p['nombre_display'], $p['estado']);

    // Los grados de la fachada tienen que ser los del motor, en su mismo orden.
    // El motor puede enumerar un grado que luego no devuelve filas y la fachada
    // lo omite, así que se compara como SUBCONJUNTO EN ORDEN, no como igualdad.
    $gradosStats = array_map(static fn($g) => (int) $g['grado']['id'], $porGrado);
    $gradosMotor = array_map('intval', array_column($merito->gradosConRanking($pid), 'id'));
    $ok($gradosStats === array_values(array_filter(
            $gradosMotor,
            static fn($id) => in_array($id, $gradosStats, true)
        )),
        'los grados salen de gradosConRanking, en su orden',
        count($gradosStats) . ' de ' . count($gradosMotor));

    foreach ($porGrado as $g) {
        $gid  = (int) $g['grado']['id'];
        $etq  = $g['grado']['nombre_display'] . ' ' . $g['grado']['nivel_codigo'];
        $rank = $merito->rankingGrado($gid, $pid);
        $gradosMedidos++;

        // 1. Identidad de fuente.
        $mismoTotal = (int) $g['total'] === count($rank);
        $mismoUno   = (int) $g['mejor']['matricula_id'] === (int) $rank[0]['matricula_id']
                   && (float) $g['mejor']['promedio_general'] === (float) $rank[0]['promedio_general'];
        $ok($mismoTotal && $mismoUno, "  $etq · total y 1.er puesto = rankingGrado",
            $g['total'] . ' alumnos · ' . $g['mejor']['promedio_general']);

        // 2. `peores` es la cola del MISMO ranking y excluye al primer puesto.
        $colaEsperada = array_values(array_filter(
            array_slice($rank, -2),
            static fn($e) => (int) $e['matricula_id'] !== (int) $rank[0]['matricula_id']
        ));
        $ok(array_map('intval', array_column($g['peores'], 'matricula_id'))
            === array_map('intval', array_column($colaEsperada, 'matricula_id')),
            "  $etq · peores = cola del ranking, sin el 1.er puesto",
            count($g['peores']) . ' fila(s)');

        // 2b. El perfil de literales CUADRA (07/09/2026). `num_a` es la única de
        // las cuatro cifras que no sale de una consulta: se DERIVA por resta en
        // `statsPorGrado`, porque AD/A/B/C son disjuntos y exhaustivos sobre
        // 00-20. Si esa premisa dejara de ser cierta —una nota nula que COUNT()
        // sí cuenta, un tramo de la escala que se solapa, un SUM() que cambia de
        // condición— `num_a` saldría negativo o descuadrado y la tabla enseñaría
        // un perfil plausible y falso, sin ningún error.
        //
        // Se mide en las DOS rutas de `rankingGrado`: en vivo y desde el
        // snapshot, que trae las mismas cuatro columnas de otra tabla.
        $descuadre = 0;
        foreach (array_merge([$g['mejor']], $g['peores']) as $al) {
            $suma = (int) $al['num_ad'] + (int) $al['num_a']
                  + (int) $al['num_b']  + (int) $al['num_c'];
            if ($suma !== (int) $al['num_competencias'] || (int) $al['num_a'] < 0) {
                $descuadre++;
            }
        }
        $ok($descuadre === 0,
            "  $etq · perfil AD+A+B+C = competencias, y A nunca es negativo",
            $descuadre > 0 ? "$descuadre fila(s) descuadradas" : 'cuadra');
    }
    echo "\n";
}

echo "== Cobertura de la medición ==\n";
// Un verificador que pasa en verde sin haber ejercitado la rama que vigila es
// peor que uno roto: avisa en vez de dar luz verde sobre una premisa falsa.
$ok($gradosMedidos > 0, 'la comparación midió grados de verdad', "$gradosMedidos grado(s)");

// ── 3. La copia no puede renacer ──────────────────────────────────
echo "\n== Estructura ==\n";
$fuente = file_get_contents(APP_PATH . '/Models/AnioAcademicoModel.php');

// Se miran solo las CADENAS del archivo (donde viven los SQL), nunca los
// comentarios: el docblock de getStatsCierre nombra a getRankingGrado a
// propósito, para contar por qué se fue.
$cadenas = '';
foreach (token_get_all($fuente) as $t) {
    if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
        $cadenas .= ' ' . $t[1];
    }
}
// La copia borrada se reconocía por dos marcas que su sustituto NO tiene:
// exponía `promedio_general` por estudiante y lo ordenaba para dar puestos.
// El AVG a secas NO sirve como marca: `getResumenBimestre` promedia por
// estudiante de forma legítima (ver la cabecera).
$ok(!str_contains($cadenas, 'promedio_general'),
    'AnioAcademicoModel no expone promedio_general por estudiante',
    'esa columna es del motor del merito');
$ok(!preg_match('~ORDER\s+BY\s+promedio~i', $cadenas),
    'AnioAcademicoModel no ordena estudiantes por promedio',
    'ordenar por promedio es rankear');
$ok(str_contains($fuente, 'statsPorGrado'),
    'getStatsCierre delega en OrdenMeritoModel::statsPorGrado');

// ── 4. El mérito ya no calcula el riesgo ──────────────────────────
// Es el aserto que impide que el riesgo vuelva a colarse aquí dentro. Si
// alguien reintroduce `en_riesgo` en `statsPorGrado`, nacerían dos fuentes
// para la misma pregunta y volverían a divergir.
echo "\n== El riesgo tiene otro dueno ==\n";
$fuenteMerito = file_get_contents(APP_PATH . '/Models/OrdenMeritoModel.php');
$claves = [];
foreach ($periodos as $p) {
    foreach ((new AnioAcademicoModel())->getStatsCierre((int) $p['id'])['por_grado'] as $g) {
        $claves += $g;
    }
}
$ok(!array_key_exists('en_riesgo', $claves) && !array_key_exists('evaluados', $claves),
    'statsPorGrado ya no devuelve en_riesgo ni evaluados',
    implode(', ', array_keys($claves)) ?: 'sin grados en estos datos');
$ok(!str_contains($fuenteMerito, 'RIESGO_MIN_C') && !str_contains($fuenteMerito, 'riesgo_conteo'),
    'OrdenMeritoModel no conserva umbrales de riesgo',
    'la regla vive en SituacionFinalModel');
$ok(is_file(APP_PATH . '/Models/SituacionFinalModel.php'),
    'existe el modelo dueno de la situacion final');

echo "\n";
printf("%s — %d fallo(s)\n", $fallos === 0 ? 'TODO OK' : 'HAY FALLOS', $fallos);
exit($fallos === 0 ? 0 : 1);
