<?php
/**
 * Estudiantes en riesgo: los que acumulan RIESGO_MIN_C competencias en C o más,
 * agrupados por grado. Compartido por la pantalla y el imprimible A4.
 *
 * 🔴 SALE DEL MOTOR OFICIAL DEL MÉRITO, no de un promedio suelto. `num_c`,
 * `puesto` y `promedio_general` vienen de la MISMA fila de
 * `OrdenMeritoModel::rankingGrado`, así que las cifras de una fila no pueden
 * contradecirse entre sí: se cuentan sobre el mismo universo (solo competencias
 * BLOQUEADAS, sin transversales salvo Ética en secundaria, sin áreas exoneradas
 * y sin notas extraordinarias). `num_a` lo DERIVA el modelo por resta.
 *
 * 🔴 NO ES EL MISMO "EN RIESGO" QUE EL DEL BLOQUE DE CALIFICACIONES. Aquél es
 * el promedio general por debajo de NOTA_MIN_B, contado por NIVEL
 * (`AnioAcademicoModel::getResumenBimestre`). Son dos preguntas distintas y las
 * cifras se separan mucho —medido el 04/09/2026 en B2: 0 por promedio, 77 por
 * número de C—. Desde el 07/09/2026 ya NO comparten rótulo: aquella columna se
 * llama "Promedio en C". El pie lo sigue diciendo en una línea, pero la
 * confusión está resuelta en el origen, no con una nota al pie.
 *
 * El filtrado de grados vive AQUI y no en cada vista: la pantalla y el A4 lo
 * comparten, y duplicarlo es como acabarian mostrando listas distintas. Lo
 * mismo vale para las cifras de la banda: agregarlas en las dos vistas seria la
 * enesima copia a mano de este repositorio, esperando a divergir.
 *
 * @var array     $bloques            salida de CuadrosEstadisticosController::componerBloques
 * @var bool|null $riesgoInteractivo  lo pone el LLAMADOR. La pantalla lo pone a
 *                true; `imprimir.php` no lo define a proposito, y sin el no se
 *                emiten ni el buscador ni los chips. Mismo idioma que `$abierta`
 *                en `_tabla-grafico.php`: el partial es uno, las superficies dos.
 *                Desde el 22/09/2026 el flag decide tambien el FORMATO de las
 *                tablas: sin el, se delega en `_estudiantes-riesgo-print.php`
 *                (informe agrupado). Banda, filtrado y cifras siguen aqui.
 */

$porGrado     = $bloques['merito']['por_grado'] ?? [];
$riesgoGrados = array_values(array_filter(
    $porGrado,
    static fn(array $g): bool => !empty($g['en_riesgo'])
));
$riesgoMinC = (int) ($bloques['merito']['riesgo_min_c'] ?? 3);

if (empty($riesgoGrados)) {
    return;
}

$interactivo = !empty($riesgoInteractivo);

// Cifras de la banda. PUNTO UNICO en `helpers.php`: el indice de anclas de
// `index.php` pinta el mismo total ANTES de llegar aqui, y el verificador
// asevera contra la misma cuenta. Sumarlo a mano en cada sitio es la copia
// latente de siempre.
$res = riesgo_resumen($porGrado);

// Conteos por nivel y por grado para los chips. Salen de la MISMA lista que se
// pinta abajo, no de una consulta ni de un recuento aparte: si un chip dijera
// otro numero que su tabla, el filtro dejaria de ser creible.
$porNivel = [];
foreach ($riesgoGrados as $g) {
    $nid = (int) $g['grado']['nivel_id'];
    $porNivel[$nid] ??= ['nombre' => $g['grado']['nivel_nombre'], 'n' => 0];
    $porNivel[$nid]['n'] += count($g['en_riesgo']);
}

// ── Pie «como leer» ──────────────────────────────────────────────────
// El texto es UNO para las dos superficies y se coloca segun cual sea: en
// pantalla al final, como siempre; en papel ANTES de las tablas (22/09/2026),
// porque quien lee una hoja impresa no puede preguntar y la explicacion al
// final llegaba tarde. En papel lleva ademas titulo y la escala literal, que
// sale de `escala_rangos()` —nunca umbrales escritos a mano—.
//
// Va suelto (`--suelto`) porque no cuelga de ningun wrapper: dentro se
// desplaza con el scroll horizontal y la card lo recorta por su overflow.
$pintarPie = static function (bool $interactivo, int $riesgoMinC): void { ?>
    <div class="tabla-pie tabla-pie--suelto<?= $interactivo ? '' : ' riesgo-informe__leer' ?>">
        <?php if (!$interactivo): ?>
            <p class="riesgo-informe__leer-titulo"><strong>Cómo leer este listado</strong></p>
        <?php endif; ?>
        <p class="text-sm text-muted">
            Se lista a cada estudiante con <strong><?= $riesgoMinC ?> competencias en C
            o más</strong> en el bimestre, ordenado de más C a menos; a igual número de C,
            primero el promedio más bajo. No hay tope por grado: un grado puede aportar una
            fila o quince, y un grado sin ningún caso no aparece.
        </p>
        <p class="text-sm text-muted">
            Las cifras salen del <strong>orden de mérito</strong>: cuentan solo las competencias
            ya bloqueadas por el docente o por el cierre, sin las áreas exoneradas ni las notas
            extraordinarias. En un bimestre abierto la lista <strong>crece conforme se bloquean
            competencias</strong>. Los conteos AD, A, B y C suman el total de competencias evaluadas.
        </p>
        <?php if (!$interactivo): ?>
            <p class="text-sm text-muted">
                Escala:
                <?php $rangos = escala_rangos(); $partes = []; ?>
                <?php foreach ($rangos as $lit => $rango) {
                    $partes[] = '<strong>' . e($lit) . '</strong> ' . e($rango)
                              . ' (' . e(descripcion_literal($lit)) . ')';
                } ?>
                <?= implode(' &middot; ', $partes) ?>.
                Cada estudiante aparece una vez, con su sección, su puesto en el grado y su
                promedio; debajo, una fila por cada competencia en C, con el área, el curso,
                la nota y el docente que la registró.
            </p>
        <?php endif; ?>
        <p class="text-sm text-muted">
            No confundir con <strong>«Promedio en C»</strong> del bloque de Calificaciones:
            aquélla cuenta a quien tiene el <strong>promedio general</strong> por debajo de
            <?= (int) NOTA_MIN_B ?>, por nivel. Se puede acumular varias C y aun así aprobar
            de promedio.
        </p>
    </div>
<?php };
?>

<div class="cuadros-riesgo">

    <?php // ── Banda de magnitud ─────────────────────────────────────────
          // Va en las DOS superficies: en el A4 es justo la cifra que un
          // informe de Direccion necesita en la primera linea del bloque.
          //
          // 🔴 El peso visual es DE LA SECCION, no de las personas. La banda
          // lleva el azul institucional (el del topbar y el login), que no es un
          // color de estado ni un tono de wayfinding; las filas de abajo siguen
          // neutras. Ver el comentario de `.cuadros-top` en _cuadros.scss. ?>
    <div class="cuadros-banda cuadros-banda--riesgo">
        <p class="cuadros-banda__cifra">
            <span class="cuadros-banda__n"><?= (int) $res['total'] ?></span>
            <span class="cuadros-banda__q">
                estudiante<?= $res['total'] !== 1 ? 's' : '' ?> con
                <strong><?= $riesgoMinC ?> competencias en C o más</strong> en el bimestre
            </span>
        </p>
        <ul class="cuadros-banda__datos">
            <li><strong><?= (int) $res['grados'] ?> de <?= (int) $res['grados_total'] ?></strong> grados con casos</li>
            <li><strong><?= (int) $res['pct'] ?>%</strong> del alumnado evaluado</li>
            <li>caso más alto: <strong><?= (int) $res['max_c'] ?> C</strong></li>
        </ul>
    </div>

    <?php if ($interactivo): ?>
    <?php // NACE OCULTO y lo destapa el JS. Sin JS los chips serian controles
          // muertos y el buscador un campo que no busca; escondidos, la seccion
          // se sigue leyendo entera, que es lo que el A4 demuestra que basta.
          // Misma regla que las pestanas: sin JS la pagina sigue correcta. ?>
    <div class="cuadros-riesgo__filtros" id="riesgo-filtros" hidden>
        <div class="cuadros-riesgo__campo">
            <label class="form-label" for="riesgo-buscar">Buscar estudiante</label>
            <input type="search" id="riesgo-buscar" class="form-input"
                   placeholder="Apellido, nombre, sección o grado"
                   autocomplete="off" spellcheck="false">
        </div>

        <div class="cuadros-riesgo__grupo">
            <span class="cuadros-riesgo__rotulo" id="riesgo-rot-nivel">Nivel</span>
            <div class="cuadros-riesgo__chips" role="group" aria-labelledby="riesgo-rot-nivel">
                <button type="button" class="orden-chip orden-chip--activo"
                        data-riesgo-nivel="" aria-pressed="true">
                    Todos <span class="cuadros-riesgo__cont"><?= (int) $res['total'] ?></span>
                </button>
                <?php foreach ($porNivel as $nid => $niv): ?>
                    <button type="button" class="orden-chip"
                            data-riesgo-nivel="<?= (int) $nid ?>" aria-pressed="false">
                        <?= e($niv['nombre']) ?>
                        <span class="cuadros-riesgo__cont"><?= (int) $niv['n'] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cuadros-riesgo__grupo">
            <span class="cuadros-riesgo__rotulo" id="riesgo-rot-grado">Grado</span>
            <div class="cuadros-riesgo__chips" role="group" aria-labelledby="riesgo-rot-grado">
                <button type="button" class="orden-chip orden-chip--activo"
                        data-riesgo-grado="" aria-pressed="true">Todos</button>
                <?php foreach ($riesgoGrados as $g): ?>
                    <?php // El rotulo lleva el codigo de nivel porque `nombre_display`
                          // es solo "1°" y hay un 1.o en cada nivel: sin el, dos chips
                          // distintos se llamarian igual. ?>
                    <button type="button" class="orden-chip"
                            data-riesgo-grado="<?= (int) $g['grado']['id'] ?>"
                            data-riesgo-de-nivel="<?= (int) $g['grado']['nivel_id'] ?>"
                            aria-pressed="false">
                        <?= e($g['grado']['nombre_display'] . ' ' . $g['grado']['nivel_codigo']) ?>
                        <span class="cuadros-riesgo__cont"><?= count($g['en_riesgo']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php // El contador nace con la cifra COMPLETA y es verdad sin JS. Es
          // `role="status"` para que un lector de pantalla oiga el resultado del
          // filtro, que si no solo existe como filas que desaparecen. ?>
    <p class="cuadros-riesgo__contador" id="riesgo-contador" role="status" aria-live="polite">
        Mostrando <?= (int) $res['total'] ?> de <?= (int) $res['total'] ?> estudiantes.
    </p>

    <?php // El CUARTO estado vacio de esta seccion (los otros tres viven en las
          // vistas): "tu filtro no encontro a nadie", que no es lo mismo que
          // "no hay nadie en riesgo" ni que "todavia no se puede saber". ?>
    <div class="empty-state cuadros-riesgo__sin-resultados" id="riesgo-sin-resultados" hidden>
        <p>Ningún estudiante de la lista coincide con la búsqueda o el filtro.</p>
    </div>
    <?php else: ?>
    <?php // ── PAPEL: informe agrupado (22/09/2026) ─────────────────────────
          // Otro marcado, no el de pantalla con estilos distintos: la rejilla de
          // nueve columnas + una sub-tabla por estudiante imprimia el nombre dos
          // veces y 118 encabezados al mismo nivel que el de la tabla. Ver el
          // partial. El pie «como leer» va ANTES de las tablas. ?>
    <?php $pintarPie(false, $riesgoMinC); ?>
    <?php require VIEW_PATH . '/admin/cuadros/_estudiantes-riesgo-print.php'; ?>
</div>
<?php return; ?>
    <?php endif; ?>

    <?php // UNA TABLA POR GRADO, no una sola con columna "Grado". Es el patron
          // que ya resolvio el listado de inasistencias (T2) en esta misma
          // pantalla: con muchas filas seguidas el encabezado de columnas se va
          // de pantalla y los numeros dejan de significar nada. Medido: 118
          // filas en B1.
          //
          // El rotulo va en un <caption>, NO en un <hN>: queda asociado a SU
          // tabla para un lector de pantalla y no depende del nivel de
          // encabezado del contenedor, que es <h3> en la pantalla y <h2> en el
          // imprimible.
          //
          // ⚠️ La `class` del <table> es LITERAL para
          // `verif_direccion_superficies.php` (regex de L431) y la cadena
          // `cuadros-top__bloque--riesgo` se cuenta con substr_count sobre TODO
          // el HTML: no tocar una ni emitir la otra en ningun otro sitio. Los
          // ganchos del filtro van por eso en atributos `data-*` propios. ?>
    <?php foreach ($riesgoGrados as $g): ?>
        <div class="cuadros-top__bloque cuadros-top__bloque--riesgo"
             data-riesgo-bloque
             data-nivel="<?= (int) $g['grado']['nivel_id'] ?>"
             data-grado="<?= (int) $g['grado']['id'] ?>">
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas cuadros-top cuadros-top--riesgo">
                    <caption class="cuadros-top__caption">
                        <?= e($g['grado']['nombre_display'] . ' — ' . $g['grado']['nivel_nombre']) ?>
                        <span class="text-muted text-sm">
                            (<?= count($g['en_riesgo']) ?> de <?= (int) $g['total'] ?>)
                        </span>
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="col-nombre">Estudiante</th>
                            <th scope="col" class="cuadros-top__sec">Sección</th>
                            <th scope="col" class="text-center cuadros-top__n">Puesto</th>
                            <th scope="col" class="text-center cuadros-top__n">Promedio</th>
                            <?php // El perfil de literales completo, no solo el numero de C:
                                  // responde "que tan grave es" sin salir de la pagina. Sale
                                  // de columnas que el ranking YA traia, sin ninguna consulta
                                  // nueva (`num_a` lo deriva el modelo por resta). ?>
                            <th scope="col" class="text-center cuadros-top__lit">AD</th>
                            <th scope="col" class="text-center cuadros-top__lit">A</th>
                            <th scope="col" class="text-center cuadros-top__lit">B</th>
                            <th scope="col" class="text-center cuadros-top__lit">C</th>
                            <th scope="col" class="text-center cuadros-top__n">Competencias</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['en_riesgo'] as $al): ?>
                            <?php
                            // Lo que el buscador mira. Va crudo: el JS normaliza los
                            // dos lados (NFD + sin tildes), asi que normalizarlo aqui
                            // ademas solo abriria la puerta a que las dos
                            // normalizaciones dejen de coincidir.
                            $buscar = $al['nombre_completo'] . ' ' . $al['seccion_nombre']
                                    . ' ' . $g['grado']['nombre_display']
                                    . ' ' . $g['grado']['nivel_codigo']
                                    . ' ' . $g['grado']['nivel_nombre'];
                            ?>
                            <tr data-riesgo-fila
                                data-nivel="<?= (int) $g['grado']['nivel_id'] ?>"
                                data-grado="<?= (int) $g['grado']['id'] ?>"
                                data-buscar="<?= e($buscar) ?>">
                                <?php // `th scope="row"` y no `td`: con nueve columnas, un
                                      // lector de pantalla necesita saber de quien es cada
                                      // celda. El aspecto se iguala al de un `td` dentro de
                                      // `--riesgo`, para no alterar el listado hermano. ?>
                                <th scope="row" class="col-nombre"><?= e($al['nombre_completo']) ?></th>
                                <td class="cuadros-top__sec"><?= e($al['seccion_nombre']) ?></td>
                                <td class="text-center text-muted">
                                    <?= (int) $al['puesto'] ?> de <?= (int) $g['total'] ?>
                                </td>
                                <td class="text-center">
                                    <?= e(number_format((float) $al['promedio_general'], 2)) ?>
                                </td>
                                <td class="text-center text-muted"><?= (int) $al['num_ad'] ?></td>
                                <td class="text-center text-muted"><?= (int) $al['num_a'] ?></td>
                                <td class="text-center text-muted"><?= (int) $al['num_b'] ?></td>
                                <?php // `--destaca` marca el dato que trae a esta persona a la
                                      // lista, igual que en el listado de inasistencias: sin el,
                                      // una fila con buen promedio y 6 C parece un error. ?>
                                <td class="text-center cuadros-top__v--destaca"><?= (int) $al['num_c'] ?></td>
                                <td class="text-center text-muted"><?= (int) $al['num_competencias'] ?></td>
                            </tr>

                            <?php // ── Desglose: QUE competencias estan en C ──────────────
                                  // Fila HERMANA con `colspan`, no un `<tr>` que se muestra y
                                  // oculta: es el patron que ya resolvio esto en el panel de
                                  // bloqueos (`director/bloqueos/index.php`), con `<details>`
                                  // nativo y cero JS.
                                  //
                                  // ⚠️ SIN `data-riesgo-fila`. Ese atributo es lo que cuenta
                                  // `cuadros-riesgo.js` para el TOTAL y para "Mostrando N de
                                  // 118": si esta fila lo llevara, el contador diria el doble.
                                  // Lleva `data-riesgo-detalle` + `data-de`, y el JS la oculta
                                  // junto a su fila madre.
                                  //
                                  // 🔴 `detalle_c === null` NO es lo mismo que `[]`. El modelo
                                  // pone NULL cuando el desglose EN VIVO no cuadra con el
                                  // `num_c` de la fila —que en un bimestre cerrado viene del
                                  // snapshot congelado, y el snapshot no guarda detalle—. Ahi
                                  // se dice que no se puede mostrar, en vez de pintar un
                                  // desglose que contradice a su propia fila.
                                  $det = $al['detalle_c'] ?? null; ?>
                            <tr class="fila-riesgo-detalle"
                                data-riesgo-detalle
                                data-de="<?= (int) $al['matricula_id'] ?>">
                                <td colspan="9">
                                    <?php if ($det === null): ?>
                                        <p class="riesgo-detalle__aviso">
                                            El desglose no coincide con el dato oficial del cierre
                                            (las notas cambiaron despues de cerrar el bimestre), asi
                                            que se omite para no mostrar dos cifras distintas.
                                        </p>
                                    <?php elseif (!empty($det)): ?>
                                        <?php // Nace cerrado: 778 filas abiertas de golpe (B1)
                                              // convertirian la seccion en un muro. El navegador
                                              // ya sabe abrirlo al buscar con Ctrl+F.
                                              //
                                              // ⚠️ El rotulo NO puede contener la cadena "En
                                              // riesgo": hay un aserto que exige que ese texto
                                              // nombre UNA sola cosa en la pagina. ?>
                                        <details class="riesgo-detalle">
                                            <summary class="riesgo-detalle__summary">
                                                Ver las <?= count($det) ?> competencias en C
                                            </summary>

                                        <table class="tabla-notas riesgo-detalle__tabla">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Área</th>
                                                    <th scope="col">Curso</th>
                                                    <th scope="col">Competencia</th>
                                                    <th scope="col" class="text-center">Nota</th>
                                                    <th scope="col">Docente</th>
                                                </tr>
                                            </thead>
                                            <tbody>
<?php // ⚠️ ESTE BUCLE VA SIN INDENTAR, Y NO ES DESCUIDO. Se repite 778 veces en
      // B1 y esta anidado ocho niveles: con la sangria natural, cada fila
      // arrastraba ~250 bytes de espacios. Medido el 07/09/2026 sobre la
      // seccion completa: 1 289 KB con sangria contra 486 KB sin ella — el
      // 62 % del peso del bloque eran espacios en blanco, y el imprimible
      // entero pasaba de 425 KB a 1,5 MB.
      // No hay minificador de HTML en el pipeline (gulp solo toca SASS y JS),
      // asi que el unico sitio donde se puede arreglar es aqui.
      foreach ($det as $d): ?>
<tr>
<td><?= e($d['area']) ?></td>
<td class="text-muted"><?= $d['curso'] !== null ? e($d['curso']) : '&mdash;' ?></td>
<td><?php if ($d['codigo'] !== null): ?><span class="competencia-card__codigo competencia-card__codigo--solo"><?= e($d['codigo']) ?></span> <?php endif; ?><?= e($d['competencia']) ?></td>
<td class="text-center cuadros-top__v--destaca"><?= (int) $d['nota'] ?></td>
<td class="text-muted"><?= e($d['docente']) ?></td>
</tr>
<?php endforeach; ?>
                                            </tbody>
                                        </table>

                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php // El pie es UNO SOLO, fuera de todos los bloques: repetirlo en cada
          // grado seria ruido. En pantalla va al final, como siempre; en papel
          // va ANTES de las tablas (ver `$pintarPie` arriba). ?>
    <?php if ($interactivo) { $pintarPie(true, $riesgoMinC); } ?>
</div>
