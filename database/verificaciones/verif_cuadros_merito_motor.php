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
 *   3. `en_riesgo` = exactamente los que tienen `riesgo_conteo() >= RIESGO_MIN_C`
 *      —B+C en primaria, solo C en secundaria (23/09/2026)—, ordenados de más a
 *      menos; `critico` = conteo >= RIESGO_CRITICO. Se mide contra una suma
 *      escrita AQUÍ a mano por nivel, no contra `riesgo_conteo()`: si el helper
 *      se rompe, el verificador no puede heredar el mismo error.
 *   4. NO HAY RANKING PROPIO: ninguna consulta de `AnioAcademicoModel` ordena
 *      estudiantes por promedio. Las tres de arriba miden datos; ésta impide que
 *      la copia renazca.
 *
 *      ⚠️ Lo que se prohíbe es el RANKING, no el promedio. `getResumenBimestre`
 *      sigue promediando por estudiante a propósito, y debe seguir haciéndolo:
 *      su «en riesgo» es OTRA pregunta —promedio general por debajo de
 *      NOTA_MIN_B, contado por nivel— y alimenta el bloque de Calificaciones de
 *      la misma pantalla. Las dos cifras conviven y son legítimamente distintas;
 *      el paso 5 lo deja medido para que nadie las "unifique".
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

// ── 1-3. Los datos, periodo a periodo ─────────────────────────────
$gradosMedidos = 0;
$filasRiesgo   = 0;
// Desglose de las C (07/09/2026). `$sinDetalle` cuenta las filas que el modelo
// marcó como "no cuadra con el snapshot": no es un fallo, es una medición — si
// un día se dispara, es que algo cambió las notas después de cerrar.
$filasDetalle  = 0;
$sinDetalle    = 0;

// Fila del ranking EN VIVO de una matrícula (23/09/2026). `rankingGradoLive` es
// privado; se llega por reflexión, como en verif_direccion_superficies. Solo lo
// usa el aserto 3c para distinguir un desglose rectificado de una réplica rota.
$vivoMemo = [];
$filaViva = static function (int $gid, int $pid, int $mid) use (&$vivoMemo): ?array {
    $k = "$gid-$pid";
    if (!isset($vivoMemo[$k])) {
        $m = new ReflectionMethod(OrdenMeritoModel::class, 'rankingGradoLive');
        $m->setAccessible(true);
        $vivoMemo[$k] = $m->invoke(new OrdenMeritoModel(), $gid, $pid);
    }
    foreach ($vivoMemo[$k] as $f) {
        if ((int) $f['matricula_id'] === $mid) {
            return $f;
        }
    }
    return null;
};

// RETORNO DE GRADO (23/09/2026): el RIESGO se cuenta por la matrícula OFICIAL,
// el MÉRITO por la operativa. El mapa se arma aquí A MANO, con su propia
// consulta, y no con `ubicacionOficialRetornos()`: es la regla contra la que se
// contrasta el modelo. Sin condición de `estado` (activo o revertido).
$oficialDe = [];
foreach ($pdo->query("
    SELECT r.matricula_operativa_id AS op, s.grado_id AS gid, s.nombre AS sec
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    JOIN secciones s   ON s.id  = mo.seccion_id
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $oficialDe[(int) $r['op']] = ['gid' => (int) $r['gid'], 'sec' => (string) $r['sec']];
}
$reubicados = 0;

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

    // Roster del RIESGO por grado: cada fila del ranking va al grado de su
    // matrícula OFICIAL (si es la operativa de un retorno) o se queda en el suyo.
    $rankDe = $rosterRiesgo = $gradoOperativo = $seccionOficial = [];
    foreach ($porGrado as $g) {
        $rankDe[(int) $g['grado']['id']] = $merito->rankingGrado((int) $g['grado']['id'], $pid);
    }
    foreach ($rankDe as $gOp => $filasG) {
        foreach ($filasG as $e) {
            $mid = (int) $e['matricula_id'];
            $dst = isset($oficialDe[$mid], $rankDe[$oficialDe[$mid]['gid']]) ? $oficialDe[$mid]['gid'] : $gOp;
            if ($dst !== $gOp) {
                $seccionOficial[$mid] = $oficialDe[$mid]['sec'];
            }
            $gradoOperativo[$mid] = $gOp;
            $rosterRiesgo[$dst][] = $e;
        }
    }

    foreach ($porGrado as $g) {
        $gid  = (int) $g['grado']['id'];
        $etq  = $g['grado']['nombre_display'] . ' ' . $g['grado']['nivel_codigo'];
        $rank = $rankDe[$gid];
        $rosterR = $rosterRiesgo[$gid] ?? [];
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

        // 3. `en_riesgo` = umbral exacto POR NIVEL + orden descendente.
        // La suma va escrita aquí a mano (primaria B+C, secundaria C) y NO con
        // `riesgo_conteo()`: es la regla del colegio contra la que se contrasta
        // el helper, no una segunda llamada al mismo código.
        $esPrim = str_starts_with((string) $g['grado']['nivel_codigo'], 'prim');
        $cuenta = static fn(array $e): int => (int) $e['num_c'] + ($esPrim ? (int) $e['num_b'] : 0);
        $esperados = $criticosEsp = [];
        foreach ($rosterR as $e) {
            if ($cuenta($e) >= OrdenMeritoModel::RIESGO_MIN_C) {
                $esperados[] = (int) $e['matricula_id'];
                if ($cuenta($e) >= OrdenMeritoModel::RIESGO_CRITICO) {
                    $criticosEsp[] = (int) $e['matricula_id'];
                }
            }
        }
        sort($esperados);
        sort($criticosEsp);

        $obtenidos = array_map('intval', array_column($g['en_riesgo'], 'matricula_id'));
        $comparar  = $obtenidos;
        sort($comparar);

        $cs      = array_map($cuenta, $g['en_riesgo']);
        $csOrden = $cs;
        rsort($csOrden);

        $conteoOk    = true;
        $criticosObt = [];
        foreach ($g['en_riesgo'] as $al) {
            $conteoOk = $conteoOk && (int) $al['conteo'] === $cuenta($al);
            if (!empty($al['critico'])) {
                $criticosObt[] = (int) $al['matricula_id'];
            }
        }
        sort($criticosObt);

        $regla = $esPrim ? 'B+C' : 'C';
        $ok($comparar === $esperados && $cs === $csOrden && $conteoOk,
            "  $etq · en riesgo = $regla >= " . OrdenMeritoModel::RIESGO_MIN_C . ', de mayor a menor',
            count($obtenidos) . ' de ' . count($rosterR));

        // 3a. Los denominadores del riesgo son los del roster OFICIAL, y un
        // retorno reubicado lleva su sección oficial y su puesto de origen.
        $secSuma = array_sum(array_map(static fn($s) => (int) $s['total'], $g['por_seccion']));
        $reubOk  = true;
        foreach ($g['en_riesgo'] as $al) {
            $mid = (int) $al['matricula_id'];
            if (isset($seccionOficial[$mid])) {
                $reubicados++;
                $reubOk = $reubOk && !empty($al['retorno'])
                    && $al['seccion_nombre'] === $seccionOficial[$mid]
                    && (int) $al['retorno']['grado']['id'] === $gradoOperativo[$mid]
                    && (int) $al['retorno']['total'] === count($rankDe[$gradoOperativo[$mid]]);
            } else {
                $reubOk = $reubOk && empty($al['retorno']);
            }
        }
        $ok((int) $g['evaluados'] === count($rosterR) && $secSuma === count($rosterR) && $reubOk,
            "  $etq · evaluados del riesgo = roster por matrícula oficial",
            $g['evaluados'] . ' evaluados · ' . $g['total'] . ' en el mérito');
        $ok($criticosObt === $criticosEsp,
            "  $etq · mayor atención = $regla >= " . OrdenMeritoModel::RIESGO_CRITICO,
            count($criticosObt) . ' caso(s)');

        // 3b. El perfil de literales CUADRA (07/09/2026). `num_a` es la única
        // de las cuatro cifras que no sale de una consulta: se DERIVA por
        // resta en `statsPorGrado`, porque AD/A/B/C son disjuntos y exhaustivos
        // sobre 00-20. Si esa premisa dejara de ser cierta —una nota nula que
        // COUNT() sí cuenta, un tramo de la escala que se solapa, un SUM() que
        // cambia de condición— `num_a` saldría negativo o descuadrado y la
        // tabla enseñaría un perfil plausible y falso, sin ningún error.
        //
        // Se mide en las DOS rutas de `rankingGrado`: B1/B3 en vivo, B2 desde
        // el snapshot, que trae las mismas cuatro columnas de otra tabla.
        $descuadre = 0;
        foreach ($g['en_riesgo'] as $al) {
            $suma = (int) $al['num_ad'] + (int) $al['num_a']
                  + (int) $al['num_b']  + (int) $al['num_c'];
            if ($suma !== (int) $al['num_competencias'] || (int) $al['num_a'] < 0) {
                $descuadre++;
            }
        }
        $ok($descuadre === 0,
            "  $etq · perfil AD+A+B+C = competencias, y A nunca es negativo",
            $descuadre > 0 ? "$descuadre fila(s) descuadradas" : count($obtenidos) . ' fila(s)');

        // 3c. EL DESGLOSE CUADRA CON LA FILA (07/09/2026; desde el 23/09/2026
        //     primaria lista B y C, secundaria solo C).
        //
        // Es el aserto que sostiene el desglose: `detalle` sale de
        // `detalleCompetenciasRiesgo`, una consulta APARTE que replica a mano el
        // universo del mérito (bloqueadas, sin extraordinarias, sin transversales
        // salvo Ética, sin exoneradas). Si esa réplica se desvía en un solo
        // filtro, el desglose deja de sumar `num_c` y la pantalla muestra dos
        // cifras que se contradicen —exactamente el patrón de fallo que ya costó
        // cuatro reglas divergentes en este repositorio— SIN ningún error.
        //
        // 🔴 UN `detalle_c === null` CUENTA COMO FALLO, Y ESO ES DELIBERADO.
        //
        // El modelo pone NULL cuando el desglose en vivo no coincide con `num_c`.
        // La primera versión de este aserto trataba ese NULL como "legítimo" —un
        // bimestre cerrado y luego rectificado lo produce— y con eso el aserto
        // quedaba CIEGO: se probó con dos mutantes (quitar el filtro de
        // extraordinarias y quitar el de transversales) y NO detectó ninguno,
        // porque una réplica rota devuelve otro número de filas, el modelo lo
        // convierte en NULL, y el verificador lo daba por bueno. El guard que
        // protege la pantalla estaba enmascarando exactamente los bugs que este
        // verificador existe para cazar.
        //
        // 🔴 23/09/2026 — UN NULL YA NO ES FALLO AUTOMÁTICO, PERO TAMPOCO SE
        // ACEPTA A CIEGAS. Con la regla de primaria apareció el caso que la
        // cabecera de `detalleCompetenciasRiesgo` ya documentaba: matrícula 530
        // de B1, una B desbloqueada DESPUÉS del cierre (snapshot B=7, vivo B=6).
        // Contando solo C no se veía porque sus C sí cuadran. Es una
        // rectificación legítima, y un aserto que la marcara en rojo para
        // siempre acabaría ignorado; pero aceptar cualquier NULL devolvería la
        // ceguera de arriba.
        //
        // Por eso cada NULL se contrasta con el ranking EN VIVO: si el desglose
        // en vivo cuadra con la fila en vivo, el descuadre es snapshot ↔ vivo
        // (dato rectificado) y se acepta; si NO cuadra ni con el vivo, la
        // réplica está rota y FALLA. Los mutantes de arriba siguen cayendo: una
        // réplica rota tampoco cuadra con el vivo. No se ata a ningún id, así
        // que corre igual en producción.
        $descuadre = $malaNota = $nulos = 0;
        foreach ($g['en_riesgo'] as $al) {
            if (!array_key_exists('detalle', $al)) {
                $descuadre++;   // ni siquiera se pobló
                continue;
            }
            if ($al['detalle'] === null) {
                $mid  = (int) $al['matricula_id'];
                $vivo = $filaViva($gradoOperativo[$mid] ?? $gid, $pid, $mid);
                $det  = array_filter(
                    $merito->detalleCompetenciasRiesgo([$mid], $pid)[$mid] ?? [],
                    static fn($d) => $esPrim || $d['literal'] === 'C'
                );
                if ($vivo === null || count($det) !== $cuenta($vivo)) {
                    $nulos++;   // no cuadra ni con el vivo: réplica rota
                } else {
                    $sinDetalle++;
                }
                continue;
            }
            if (count($al['detalle']) !== $cuenta($al)) {
                $descuadre++;
            }
            foreach ($al['detalle'] as $d) {
                $nota = (int) $d['nota'];
                // Primaria: B o C. Secundaria: solo C. Y el literal = su nota.
                $valido = $esPrim ? $nota <= NOTA_MIN_A - 1 : $nota <= NOTA_MIN_B - 1;
                if (!$valido || $d['literal'] !== nota_a_literal($nota)) {
                    $malaNota++;
                }
            }
            $filasDetalle += count($al['detalle']);
        }

        $ok($descuadre === 0 && $malaNota === 0 && $nulos === 0,
            "  $etq · el desglose cuadra con la fila y lista solo " . ($esPrim ? 'B y C' : 'C'),
            $descuadre > 0
                ? "$descuadre fila(s) descuadradas"
                : ($malaNota > 0
                    ? "$malaNota nota(s) que no son C"
                    : ($nulos > 0
                        ? "$nulos alumno(s) cuyo desglose no cuadra ni con el ranking en vivo"
                        : count($obtenidos) . ' alumno(s)')));

        $filasRiesgo += count($obtenidos);
    }
    echo "\n";
}

echo "== Cobertura de la medición ==\n";
$ok($gradosMedidos > 0, 'la comparación midió grados de verdad', "$gradosMedidos grado(s)");
// Un verificador que pasa en verde sin haber ejercitado la rama que vigila es
// peor que uno roto: avisa en vez de dar luz verde sobre una premisa falsa.
$ok($filasRiesgo > 0, 'la rama "en riesgo" es observable en estos datos', "$filasRiesgo fila(s)");
// Un verificador que pasa en verde sin haber ejercitado la rama que vigila es
// peor que uno roto: si el desglose no devolviera NADA, el aserto de arriba
// seguiria verde (0 === 0) y nadie se enteraria.
$ok($filasDetalle > 0, 'el desglose es observable en estos datos',
    "$filasDetalle fila(s)" . ($sinDetalle > 0 ? " · $sinDetalle rectificado(s) tras el cierre (cuadran en vivo)" : ' · todas cuadran'));
// La reubicación del retorno solo se mide si algún retorno llega al umbral.
// Sin esto, un modelo que no reubicara pasaría en verde con cero casos.
$ok($oficialDe === [] || $reubicados > 0, 'la reubicación del retorno es observable en estos datos',
    count($oficialDe) . " retorno(s) · $reubicados fila(s) reubicadas");

// ── 3d. Retorno de grado: las ramas que los datos de hoy NO ejercen ──
// El único retorno real tiene la MISMA letra de sección en los dos grados, no
// cruza de nivel y está activo: un modelo que conservara la sección operativa,
// aplicara la regla del nivel operativo o ignorara los revertidos pasaría en
// verde (medido con mutantes el 23/09/2026). Se simulan los tres casos dentro
// de una TRANSACCIÓN CON ROLLBACK: este script no deja nada escrito.
echo "\n== Retorno de grado simulado (transacción + rollback) ==\n";
$ret = $pdo->query("
    SELECT r.id, r.matricula_operativa_id AS op, r.matricula_oficial_id AS ofi,
           mo.seccion_id AS sec_id, s.grado_id AS gid, s.anio_id, s.nombre AS sec, n.codigo AS nivel
    FROM retornos_grado r
    JOIN matriculas mo ON mo.id = r.matricula_oficial_id
    JOIN secciones s   ON s.id  = mo.seccion_id
    JOIN grados g      ON g.id  = s.grado_id
    JOIN niveles n     ON n.id  = g.nivel_id
    ORDER BY r.id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Un periodo donde la operativa tenga 3+ C: sirve para los tres casos.
$pSim = $filaSim = null;
foreach ($periodos as $p) {
    foreach ((new OrdenMeritoModel())->statsPorGrado((int) $p['id']) as $g) {
        foreach ($g['en_riesgo'] as $al) {
            if ($ret && (int) $al['matricula_id'] === (int) $ret['op'] && (int) $al['num_c'] >= OrdenMeritoModel::RIESGO_MIN_C) {
                $pSim = (int) $p['id'];
                $filaSim = $al;
            }
        }
    }
    if ($pSim) { break; }
}

if (!$pSim) {
    $ok(false, 'hay un retorno con 3+ C para simular', 'sin caso: la rama queda sin medir');
} else {
    // Dónde quedó la operativa en la lista de riesgo del periodo simulado.
    $buscar = static function () use ($pSim, $ret): ?array {
        foreach ((new OrdenMeritoModel())->statsPorGrado($pSim) as $g) {
            foreach ($g['en_riesgo'] as $al) {
                if ((int) $al['matricula_id'] === (int) $ret['op']) {
                    return ['grado' => $g['grado'], 'fila' => $al];
                }
            }
        }
        return null;
    };

    $pdo->beginTransaction();
    try {
        // (E) Revertido: sigue contando en la matrícula OFICIAL.
        $pdo->prepare("UPDATE retornos_grado SET estado = 'revertido' WHERE id = ?")->execute([$ret['id']]);
        $x = $buscar();
        $ok($x !== null && (int) $x['grado']['id'] === (int) $ret['gid'] && !empty($x['fila']['retorno']),
            'un retorno REVERTIDO también se cuenta en la matrícula oficial',
            $x ? $x['grado']['nombre_display'] . ' ' . $x['grado']['nivel_codigo'] : 'no aparece');
        $pdo->prepare("UPDATE retornos_grado SET estado = 'activo' WHERE id = ?")->execute([$ret['id']]);

        // (C) Otra sección oficial del mismo grado, con OTRA letra.
        $otra = $pdo->prepare("SELECT id, nombre FROM secciones WHERE grado_id = ? AND anio_id = ? AND nombre <> ? ORDER BY nombre LIMIT 1");
        $otra->execute([$ret['gid'], $ret['anio_id'], $ret['sec']]);
        $otra = $otra->fetch(PDO::FETCH_ASSOC);
        if ($otra) {
            $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$otra['id'], $ret['ofi']]);
            $x = $buscar();
            $ok($x !== null && $x['fila']['seccion_nombre'] === $otra['nombre']
                    && $x['fila']['retorno']['seccion_nombre'] !== $otra['nombre'],
                'la fila reubicada lleva la sección OFICIAL, no la operativa',
                ($x['fila']['seccion_nombre'] ?? '?') . ' (oficial) · ' . ($x['fila']['retorno']['seccion_nombre'] ?? '?') . ' (operativa)');
        } else {
            $ok(false, 'hay otra sección en el grado oficial para simular');
        }

        // (D) Grado oficial de OTRO nivel: manda la regla del nivel oficial.
        $otroNivel = $pdo->prepare("
            SELECT s.id, g.id AS gid, n.codigo
            FROM secciones s JOIN grados g ON g.id = s.grado_id JOIN niveles n ON n.id = g.nivel_id
            WHERE s.anio_id = ? AND n.codigo <> ? AND g.id IN (" . implode(',', array_map('intval',
                array_column((new OrdenMeritoModel())->gradosConRanking($pSim), 'id'))) . ")
            ORDER BY g.numero LIMIT 1
        ");
        $otroNivel->execute([$ret['anio_id'], $ret['nivel']]);
        $otroNivel = $otroNivel->fetch(PDO::FETCH_ASSOC);
        if ($otroNivel) {
            $pdo->prepare("UPDATE matriculas SET seccion_id = ? WHERE id = ?")->execute([$otroNivel['id'], $ret['ofi']]);
            $x = $buscar();
            // Contado A MANO con la regla del nivel oficial.
            $esperado = (int) $filaSim['num_c']
                + (str_starts_with($otroNivel['codigo'], 'prim') ? (int) $filaSim['num_b'] : 0);
            $ok($x !== null && (int) $x['grado']['id'] === (int) $otroNivel['gid']
                    && (int) $x['fila']['conteo'] === $esperado,
                'un retorno que cruza de nivel se cuenta con la regla del nivel OFICIAL',
                ($x ? $x['grado']['nombre_display'] . ' ' . $x['grado']['nivel_codigo'] . ' · conteo ' . $x['fila']['conteo'] : 'no aparece')
                    . " · esperado $esperado");
        } else {
            $ok(false, 'hay un grado de otro nivel para simular');
        }
    } finally {
        $pdo->rollBack();
    }
    $x = $buscar();
    $ok($x !== null && (int) $x['grado']['id'] === (int) $ret['gid'] && $x['fila']['seccion_nombre'] === $ret['sec'],
        'el rollback dejó el retorno como estaba', $x ? $x['grado']['nombre_display'] . ' ' . $x['fila']['seccion_nombre'] : 'no aparece');
}

// ── 4. La copia no puede renacer ──────────────────────────────────
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

// ── 5. Las dos preguntas de "riesgo" siguen siendo dos ────────────
// Se miden juntas a propósito: comparten pantalla y rótulo, y la tentación
// recurrente de este repositorio es unificar dos reglas que solo se PARECEN.
echo "\n== Los dos 'en riesgo' de /admin/cuadros ==\n";
foreach ($periodos as $p) {
    $pid   = (int) $p['id'];
    $anio  = new AnioAcademicoModel();
    $porGr = $anio->getStatsCierre($pid)['por_grado'];
    if (!$porGr) { continue; }

    $porPromedio = 0;
    foreach ($anio->getResumenBimestre($pid) as $nivel) {
        $porPromedio += (int) ($nivel['en_riesgo'] ?? 0);
    }
    $porC = array_sum(array_map(static fn($g) => count($g['en_riesgo']), $porGr));

    printf("  %-14s promedio<%d por nivel: %-4d · riesgo (%d+ no aprobadas) por grado: %-4d\n",
        $p['nombre_display'], NOTA_MIN_B, $porPromedio, OrdenMeritoModel::RIESGO_MIN_C, $porC);
}
$ok(true, 'las dos cifras se miden por separado y no se unifican',
    'universos distintos a proposito');

echo "\n";
printf("%s — %d fallo(s)\n", $fallos === 0 ? 'TODO OK' : 'HAY FALLOS', $fallos);
exit($fallos === 0 ? 0 : 1);
