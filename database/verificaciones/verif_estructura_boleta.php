<?php

/**
 * Verificación — estructura anual completa en TODAS las vistas de boleta, sin
 * filtrar datos de bimestres que aún no corresponden.
 * Uso: php database/verificaciones/verif_estructura_boleta.php
 *
 * SECCIONES 1-3: SOLO LECTURA. SECCION 4: escribe dentro de una TRANSACCION que
 * termina en ROLLBACK, y se OMITE en produccion (guarda del archivo de secretos).
 * Se puede correr en PRODUCCION: alli solo corren las secciones 1-3.
 *
 * CONTEXTO (04/08/2026). La REGLA DE FORMATO del 09/07/2026 —las 4 columnas de
 * bimestre siempre, aunque esten vacias— se habia aplicado solo a
 * `/boleta/ver/{token}` y a la boleta del trasladado. La IMPRESION MASIVA
 * (`/admin/boletas-publicas/{id}/boletas-alumno`) y el ZIP de archivo llamaban a
 * `armar()` sin el 4º parametro, asi que colapsaban columnas: el documento que RA
 * firma y entrega salia con OTRO formato que el que la familia abre por QR, siendo
 * el MISMO componente (`boleta/alumno.php`). Ahora las 9 entradas piden estructura
 * anual completa.
 *
 * QUE COMPRUEBA, para cada umbral:
 *   1. ESTRUCTURA: siempre 4 columnas de bimestre (o tantas como periodos tenga el
 *      anio), independiente de cuantos esten cerrados.
 *   2. DATOS: abrir las columnas NO relaja el guard. Los bimestres que aportan
 *      notas siguen siendo los que corresponden al umbral:
 *        - 'oficial'  -> cerrados Y publicados al nivel del alumno;
 *        - 'archivo'  -> cerrados;
 *        - 'borrador' -> cerrados o activos con Hito A;
 *        - 'todos'    -> todos.
 *      Es la aserción de seguridad: una columna vacia es formato, no una fuga.
 *   3. CONTROL: con y sin estructuraCompleta aportan datos los mismos bimestres.
 *   4. ESCENARIOS (17/09/2026): con los datos reales los cuatro umbrales suelen
 *      COINCIDIR (todo lo cerrado esta publicado, no hay Hito A en curso, nada
 *      bloqueado en el bimestre activo), asi que la seccion 2 no puede ver una
 *      fuga. Aqui se fuerzan, sobre un bimestre con notas en boleta, los tres
 *      estados que separan umbrales: cerrado SIN publicar, activo CON Hito A y
 *      activo en registro. Cada umbral debe mostrar u ocultar ese bimestre.
 *   4b. INVARIANTE (17/09/2026): sin bloqueo, una nota NO llega a ningun umbral
 *      —ni a la vista previa de RA—. Ningun otro verificador del repo cubre la
 *      regla «boleta = solo competencias BLOQUEADAS», y la seccion 2 no puede:
 *      su esperado sale de la misma consulta que podria perder el JOIN.
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

$pdo     = Core\Database::connect();
$boletas = new App\Models\BoletaModel();
$fallos  = 0;

$check = static function (string $caso, string $esperado, string $obtenido) use (&$fallos): void {
    if ($esperado === $obtenido) {
        printf("  OK    %-50s [%s]\n", $caso, $obtenido);
        return;
    }
    $fallos++;
    printf("  ***   %-50s esperado [%s] · obtenido [%s]\n", $caso, $esperado, $obtenido);
};

// Alumno con notas reales, para que "aporta datos" signifique algo.
// SIN EXONERACIONES (17/09/2026): `ExoneracionModel::inyectarEnAreas` escribe el
// literal EXO en TODOS los periodos, y `$conDatos` lo contaria como dato en los
// cuatro bimestres, dando por fuga lo que es formato. Hoy no mordia por azar.
$mat = $pdo->query("
    SELECT cal.matricula_id, m.seccion_id
    FROM calificaciones cal
    INNER JOIN matriculas m ON m.id = cal.matricula_id
    WHERE m.estado = 'aprobada' AND cal.nota_numerica IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM exoneraciones ex WHERE ex.matricula_id = cal.matricula_id)
    GROUP BY cal.matricula_id
    HAVING COUNT(*) > 5
    ORDER BY cal.matricula_id LIMIT 1
")->fetch();
if (!$mat) {
    fwrite(STDERR, "ABORTA: no hay ninguna matricula con notas para probar.\n");
    exit(1);
}
$matriculaId = (int) $mat['matricula_id'];

$periodos = $pdo->query("
    SELECT id, numero, estado, boletas_aprobadas_en
    FROM periodos
    WHERE anio_id = (SELECT id FROM anios_academicos WHERE estado = 'activo' LIMIT 1)
    ORDER BY numero
")->fetchAll();
$totalPeriodos = count($periodos);

// Periodos PUBLICADOS para el nivel de este alumno. Se pregunta al MISMO punto
// que usa la boleta (`BoletaModel` línea ~99) en vez de replicar la regla aquí.
//
// 🔴 POR QUÉ SE DELEGA, Y NO SE COPIA (22/08/2026): este bloque tenía su propia
// copia de la compuerta —`primera_publicacion_en IS NOT NULL`— que NO es la
// regla que aplica la boleta. `periodosPublicados()` corta por `publica_en <=
// ahora` y ni siquiera mira ese sello. La divergencia estuvo LATENTE mientras
// todas las publicaciones fueron INMEDIATAS (con ellas ambas ramas coinciden) y
// se activó en cuanto venció la primera publicación PROGRAMADA —B2, el 13 y 14
// de agosto—, cuyas filas conservan `primera_publicacion_en` en NULL: el
// verificador daba por NO publicado un bimestre que las familias ya veían, y
// acusaba de fuga a un guard que funcionaba.
//
// Que el esperado salga del mismo modelo NO debilita esta verificación: aquí se
// prueba el GUARD DE DATOS de la boleta (que 'oficial' deje pasar solo cerrados
// Y publicados), no la compuerta en sí. La compuerta tiene su propio verificador
// con escenarios forzados: `verif_merito_nomina_compuerta.php`.
$ctx = $pdo->prepare("
    SELECT g.nivel_id, m.anio_id
    FROM matriculas m
    INNER JOIN secciones s ON s.id = m.seccion_id
    INNER JOIN grados    g ON g.id = s.grado_id
    WHERE m.id = ?
");
$ctx->execute([$matriculaId]);
$ctxAlumno = $ctx->fetch();
if (!$ctxAlumno) {
    fwrite(STDERR, "ABORTA: no se pudo resolver el nivel del alumno de prueba.\n");
    exit(1);
}
$publicados = array_keys((new App\Models\PublicacionBoletaModel())->periodosPublicados(
    (int) $ctxAlumno['anio_id'],
    (int) $ctxAlumno['nivel_id']
));

// Bimestres cuya BOLETA trae notas de este alumno. Sin esto, el umbral 'todos'
// esperaria los 4 y fallaria por los que nadie califico todavia: el guard puede
// DEJAR PASAR un bimestre y aun asi no haber nada que mostrar.
//
// Se pregunta al MISMO punto que arma la boleta (`CalificacionModel::getBoletaAlumno`,
// por cada fuente de `boletaContexto`), no a `calificaciones` en crudo.
//
// 🔴 POR QUÉ SE DELEGA (17/09/2026): el conteo crudo era una SEGUNDA copia, más
// débil, de «qué notas llegan a la boleta»: ignoraba el invariante de competencias
// BLOQUEADAS y el blindaje de criterio vivo y confirmado (migración 033). Se puso
// ROJO en la laptop cuando la matrícula de prueba recibió UNA nota de B3 sin
// bloquear (08/09/2026, dato normal a mitad de bimestre): 'todos' esperaba
// [1 2 3] y la boleta, correctamente, daba [1 2]. El veredicto dependía del equipo
// —verde en el escritorio, con B3 vacío—, no del código. Es la misma familia de
// fallo que la copia de la compuerta corregida arriba el 22/08.
//
// Delegar no debilita la prueba: aquí se prueba la COMPUERTA por umbral
// (`periodoAportaNotas`), no la consulta de notas.
$calModel     = new App\Models\CalificacionModel();
$fuentes      = $calModel->boletaContexto($matriculaId)['fuentes'];
$numsConNotas = [];
foreach ($periodos as $p) {
    foreach ($fuentes as $fuente) {
        $filas   = $calModel->getBoletaAlumno((int) $fuente, (int) $p['id']);
        $conNota = array_filter($filas, static fn (array $f): bool => ($f['nota_numerica'] ?? null) !== null);
        if ($conNota !== []) {
            $numsConNotas[] = (int) $p['numero'];
            break;
        }
    }
}

/** Numeros de bimestre que DEBEN aportar notas: los que el umbral deja pasar Y tienen notas. */
$esperaDatos = static function (string $modo) use ($periodos, $publicados, $numsConNotas): array {
    $out = [];
    foreach ($periodos as $p) {
        $estado = boleta_estado_bimestre($p['estado'], $p['boletas_aprobadas_en']);
        $ok = match ($modo) {
            'oficial'  => $estado === 'oficial' && in_array((int) $p['id'], $publicados, true),
            'archivo'  => $estado === 'oficial',
            'borrador' => $estado !== 'registro',
            'todos'    => true,
        };
        if ($ok && in_array((int) $p['numero'], $numsConNotas, true)) {
            $out[] = (int) $p['numero'];
        }
    }
    return $out;
};

/** Numeros de bimestre que REALMENTE traen alguna nota en la boleta armada. */
$conDatos = static function (array $data): array {
    $porId = [];
    foreach ($data['periodos'] as $p) { $porId[(int) $p['id']] = (int) $p['numero']; }
    $nums = [];
    foreach ($data['areas'] as $area) {
        foreach ($area as $comp) {
            foreach (($comp['bimestres'] ?? []) as $pid => $b) {
                if (!is_array($b)) { continue; }
                $tieneNota = ($b['literal'] ?? null) !== null || ($b['nota'] ?? null) !== null;
                if ($tieneNota && isset($porId[(int) $pid])) { $nums[$porId[(int) $pid]] = true; }
            }
        }
    }
    $nums = array_keys($nums);
    sort($nums);
    return $nums;
};

$verPeriodo = (int) $periodos[0]['id'];

echo "=== Escenario (matricula {$matriculaId}) ===\n";
foreach ($periodos as $p) {
    printf("   bimestre %d: estado=%-9s hito_A=%-2s publicado=%s\n",
        $p['numero'], $p['estado'], $p['boletas_aprobadas_en'] ? 'si' : 'no',
        in_array((int) $p['id'], $publicados, true) ? 'si' : 'no');
}

echo "\n=== 1. ESTRUCTURA: {$totalPeriodos} columnas en todos los umbrales ===\n";
foreach (['oficial', 'archivo', 'borrador', 'todos'] as $modo) {
    $d = $boletas->armar($matriculaId, $verPeriodo, $modo, true);
    $nums = array_map(fn($p) => (int) $p['numero'], $d['periodos']);
    $check("'{$modo}' con estructuraCompleta", implode(' ', range(1, $totalPeriodos)), implode(' ', $nums));
}

echo "\n=== 2. DATOS: abrir columnas NO relaja el guard ===\n";
foreach (['oficial', 'archivo', 'borrador', 'todos'] as $modo) {
    $d = $boletas->armar($matriculaId, $verPeriodo, $modo, true);
    $check("'{$modo}' aporta notas solo de", implode(' ', $esperaDatos($modo)), implode(' ', $conDatos($d)));
}

echo "\n=== 3. Control: sin estructuraCompleta los datos son LOS MISMOS ===\n";
echo "    (si difirieran, el flag estaria filtrando datos y no solo formato)\n";
foreach (['oficial', 'archivo'] as $modo) {
    $con = $conDatos($boletas->armar($matriculaId, $verPeriodo, $modo, true));
    $sin = $conDatos($boletas->armar($matriculaId, $verPeriodo, $modo, false));
    $check("'{$modo}': mismos bimestres con datos", implode(' ', $sin), implode(' ', $con));
}

echo "\n=== 4. ESCENARIOS: cada umbral discrimina (transaccion + ROLLBACK) ===\n";

// Guarda: esta seccion ESCRIBE, aunque termine en ROLLBACK. En produccion se
// omite y las secciones 1-3, de solo lectura, siguen valiendo alli.
if (is_file('/home/u761410128/siga_secrets/database.php')) {
    echo "  AVISO seccion omitida: detectado el archivo de secretos de PRODUCCION.\n";
} else {
    // Bimestre de prueba: el ultimo CERRADO y PUBLICADO al nivel del alumno cuya
    // boleta trae notas. Sobre el se simulan los estados que separan umbrales.
    $pPrueba = null;
    foreach (array_reverse($periodos) as $p) {
        if ($p['estado'] === 'cerrado'
            && in_array((int) $p['id'], $publicados, true)
            && in_array((int) $p['numero'], $numsConNotas, true)) {
            $pPrueba = $p;
            break;
        }
    }

    if ($pPrueba === null) {
        // No es un fallo del sistema, pero un verde aqui no significaria nada.
        echo "  AVISO no hay bimestre cerrado, publicado y con notas en boleta: esta seccion NO discrimina.\n";
    } else {
        $pid   = (int) $pPrueba['id'];
        $num   = (int) $pPrueba['numero'];
        $nivel = (int) $ctxAlumno['nivel_id'];

        $huella = static function () use ($pdo, $pid, $nivel): string {
            $a = $pdo->prepare("SELECT estado, COALESCE(boletas_aprobadas_en, '-') FROM periodos WHERE id = ?");
            $a->execute([$pid]);
            $b = $pdo->prepare("SELECT COALESCE(suspendida_en, '-') FROM periodos_publicacion WHERE periodo_id = ? AND nivel_id = ?");
            $b->execute([$pid, $nivel]);
            return implode(' / ', $a->fetch(PDO::FETCH_NUM)) . ' / publicacion: ' . implode(',', $b->fetchAll(PDO::FETCH_COLUMN));
        };
        $huellaAntes = $huella();
        echo "    bimestre de prueba: {$num} (periodo {$pid}), nivel {$nivel}\n";

        // [escenario, estado del periodo, Hito A, publicacion suspendida, umbrales donde DEBE aportar]
        // Se fijan los TRES campos en cada escenario: el resultado no depende del orden.
        $escenarios = [
            ['cerrado y publicado',  'cerrado', false, false, ['oficial', 'archivo', 'borrador', 'todos']],
            ['cerrado SIN publicar', 'cerrado', false, true,  ['archivo', 'borrador', 'todos']],
            ['activo CON Hito A',    'activo',  true,  false, ['borrador', 'todos']],
            ['activo en registro',   'activo',  false, false, ['todos']],
        ];

        $updPeriodo = $pdo->prepare("UPDATE periodos SET estado = ?, boletas_aprobadas_en = ? WHERE id = ?");
        $updPublica = $pdo->prepare("UPDATE periodos_publicacion SET suspendida_en = ? WHERE periodo_id = ? AND nivel_id = ?");

        $pdo->beginTransaction();
        try {
            foreach ($escenarios as [$nombre, $estado, $hitoA, $suspendida, $aportaEn]) {
                $ahora = date('Y-m-d H:i:s');
                $updPeriodo->execute([$estado, $hitoA ? $ahora : null, $pid]);
                $updPublica->execute([$suspendida ? $ahora : null, $pid, $nivel]);
                echo "  -- {$nombre}\n";
                foreach (['oficial', 'archivo', 'borrador', 'todos'] as $modo) {
                    $debe   = in_array($modo, $aportaEn, true);
                    $aporta = in_array($num, $conDatos($boletas->armar($matriculaId, $verPeriodo, $modo, true)), true);
                    $check(
                        sprintf("'%s' %s el bimestre %d", $modo, $debe ? 'muestra' : 'oculta', $num),
                        $debe ? 'aporta' : 'no aporta',
                        $aporta ? 'aporta' : 'no aporta'
                    );
                }
            }
        } finally {
            $pdo->rollBack();
        }

        $check('ROLLBACK: periodo y publicacion intactos', $huellaAntes, $huella());

        // ── 4b. INVARIANTE: sin bloqueo la nota no llega a NINGUN umbral ──
        // Es el invariante mayor de la boleta ("solo competencias BLOQUEADAS") y
        // NINGUN verificador del repo lo cubria: si alguien quitara el INNER JOIN a
        // `bloqueos_competencia` de getBoletaAlumno, el esperado y el obtenido de la
        // seccion 2 se moverian JUNTOS —los dos leen de ahi— y esto seguiria verde.
        // Por eso se prueba quitando el bloqueo y exigiendo que la celda desaparezca.
        $enFuentes = implode(',', array_fill(0, count($fuentes), '?'));
        $stCelda   = $pdo->prepare("
            SELECT cal.carga_id, cal.competencia_id, comp.nombre_corto
            FROM calificaciones cal
            INNER JOIN bloqueos_competencia bc ON bc.carga_id       = cal.carga_id
                                              AND bc.competencia_id = cal.competencia_id
                                              AND bc.periodo_id     = cal.periodo_id
            INNER JOIN competencias comp ON comp.id = cal.competencia_id
            LEFT  JOIN areas a           ON a.id    = comp.area_id
            WHERE cal.matricula_id IN ({$enFuentes})
              AND cal.periodo_id    = ?
              AND cal.nota_numerica IS NOT NULL
              -- Las transversales se agregan aparte (promedio por carga + cierre del
              -- tutor): quitar UN bloqueo no tiene por que borrar su fila.
              AND (a.tipo IS NULL OR a.tipo <> 'transversal')
            ORDER BY cal.id
            LIMIT 1
        ");
        $stCelda->execute([...array_map('intval', $fuentes), $pid]);
        $celda = $stCelda->fetch();

        if (!$celda) {
            echo "  AVISO sin competencia academica bloqueada en el bimestre {$num}: no se prueba el invariante.\n";
        } else {
            /** Celdas CON dato del bimestre de prueba, en toda la boleta. */
            $celdasConDato = static function (array $d) use ($pid): int {
                $n = 0;
                foreach ($d['areas'] as $area) {
                    foreach ($area as $comp) {
                        $b = $comp['bimestres'][$pid] ?? null;
                        if (is_array($b) && (($b['literal'] ?? null) !== null || ($b['nota'] ?? null) !== null)) {
                            $n++;
                        }
                    }
                }
                return $n;
            };

            $antes = [];
            foreach (['oficial', 'archivo', 'borrador', 'todos'] as $modo) {
                $antes[$modo] = $celdasConDato($boletas->armar($matriculaId, $verPeriodo, $modo, true));
            }
            $bloqueosAntes = (int) $pdo->query("SELECT COUNT(*) FROM bloqueos_competencia")->fetchColumn();
            echo "  -- sin el bloqueo de «{$celda['nombre_corto']}» (carga {$celda['carga_id']})\n";

            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare("DELETE FROM bloqueos_competencia WHERE carga_id = ? AND competencia_id = ? AND periodo_id = ?");
                $del->execute([(int) $celda['carga_id'], (int) $celda['competencia_id'], $pid]);
                $check('el bloqueo de prueba se retiro (1 fila)', '1', (string) $del->rowCount());

                foreach (['oficial', 'archivo', 'borrador', 'todos'] as $modo) {
                    $check(
                        sprintf("'%s' pierde esa celda del bimestre %d", $modo, $num),
                        (string) ($antes[$modo] - 1),
                        (string) $celdasConDato($boletas->armar($matriculaId, $verPeriodo, $modo, true))
                    );
                }
            } finally {
                $pdo->rollBack();
            }

            $check('ROLLBACK: los bloqueos vuelven', (string) $bloqueosAntes,
                (string) (int) $pdo->query("SELECT COUNT(*) FROM bloqueos_competencia")->fetchColumn());
        }
    }
}

echo "\n" . ($fallos === 0
    ? "RESULTADO: OK — estructura anual completa sin fuga de datos.\n"
    : "RESULTADO: {$fallos} FALLO(S).\n");

exit($fallos === 0 ? 0 : 1);
