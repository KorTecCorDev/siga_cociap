<?php
/**
 * Listado de estudiantes en riesgo con su desglose (23/09/2026). MISMO marcado
 * en pantalla y en el A4 (decisión del usuario): una tabla por grado, cada
 * estudiante una FRANJA de grupo (`<tbody>` propio) y debajo sus competencias
 * de riesgo, a la vista —sin `<details>`, regla «datos a la vista» del 21/09—.
 *
 * 🔴 EL TÍTULO DEL GRADO VA DENTRO DEL `<thead>`, NO EN UN `<caption>`. Es la
 * maquetación «B», elegida tras imprimir a PDF tres candidatas con los datos
 * reales de B1 (199 estudiantes, ~1 400 filas) y revisar cada hoja:
 *   · con `<caption>` o un título suelto encima, Chrome dejaba el título y el
 *     encabezado al pie de una hoja y el primer estudiante en la siguiente
 *     (7 casos en B1: el defecto que originó este rediseño);
 *   · dentro del `<thead>` el título se repite arriba de CADA hoja con las
 *     columnas, y Chrome no deja un `<thead>` solo al pie: 0 casos, 39 hojas.
 * Cada `<tbody>` lleva `break-inside: avoid`: un estudiante nunca se parte (el
 * más largo medido tiene 20 filas y cabe holgado en una hoja).
 *
 * ⚠️ Área y curso comparten columna A PROPÓSITO: con columnas separadas el
 * nombre del docente se partía en dos líneas en casi cada fila y el informe
 * pasaba de 39 a 58 hojas.
 *
 * `data-riesgo-fila` va en el `<tbody>` de cada estudiante: es lo que cuenta y
 * oculta el buscador (`cuadros-riesgo.js`). El buscador NO recalcula el
 * resumen; el filtro por secciones sí, porque va por URL.
 *
 * 🔴 LA FRANJA YA NO DICE PUESTO NI PROMEDIO (23/09/2026). Los decía cuando el
 * riesgo era un subproducto del ranking del mérito. Ahora la pregunta es si el
 * estudiante será PROMOVIDO, no en qué puesto quedó, y además el roster es
 * otro: las matrículas `pendiente` se evalúan y reciben boleta pero NO compiten
 * en el mérito, así que habrían tenido que mostrar un puesto vacío. En su lugar
 * van la SITUACIÓN FINAL, el motivo que la produce y la cobertura.
 *
 * El desglose lista las competencias NO APROBATORIAS del nivel (primaria B y C,
 * secundaria solo C; `nota_es_aprobatoria()`). No es la regla de la promoción
 * —esa se cuenta por ÁREA, y el motivo de la franja la resume—: es lo que el
 * tutor tiene que remontar.
 *
 * @var array $riesgo
 */
$lista = array_values(array_filter($riesgo['filtrado'], static fn(array $g): bool => !empty($g['en_riesgo'])));
?>
<section class="riesgo-listado">
    <h2 class="riesgo-h2">Listado por grado</h2>
    <?php foreach ($lista as $g):
        $gr        = $g['grado'];
        $evaluados = (int) ($g['evaluados'] ?? 0);
        // La regla del MINEDU depende del GRADO, no solo del nivel: en los
        // finales de ciclo una sola «C» impide la promoción. Decirlo en la
        // cabecera evita que la lista parezca arbitraria.
        $ciclo = grado_final_de_ciclo($gr['nivel_codigo'], (int) $gr['numero'])
            ? 'grado final de ciclo: la promoción exige B o superior en TODAS las competencias'
            : 'grado intermedio: la promoción exige la mitad o más en B o superior en cada área';
    ?>
        <div class="riesgo-grado" data-riesgo-bloque>
            <div class="tabla-notas-wrapper">
            <table class="riesgo-tabla">
                <colgroup>
                    <col class="riesgo-tabla__c-area">
                    <col class="riesgo-tabla__c-comp">
                    <col class="riesgo-tabla__c-lit">
                    <col class="riesgo-tabla__c-nota">
                    <col class="riesgo-tabla__c-doc">
                </colgroup>
                <thead>
                    <tr class="riesgo-tabla__grado">
                        <th scope="colgroup" colspan="5"><div class="riesgo-tabla__grado-cont">
                            <?= e($gr['nombre_display'] . ' de ' . $gr['nivel_nombre']) ?>
                            <span>&mdash; <?= count($g['en_riesgo']) ?> de <?= $evaluados ?> estudiantes evaluados
                            &middot; <?= e($ciclo) ?></span>
                        </div></th>
                    </tr>
                    <tr class="riesgo-tabla__cols">
                        <th scope="col">Área &middot; curso</th>
                        <th scope="col">Competencia</th>
                        <th scope="col" class="riesgo-tabla__num">Lit.</th>
                        <th scope="col" class="riesgo-tabla__num">Nota</th>
                        <th scope="col">Docente</th>
                    </tr>
                </thead>
                <?php foreach ($g['en_riesgo'] as $al):
                    $buscar = $al['nombre_completo'] . ' ' . $al['seccion_nombre'] . ' '
                            . $gr['nombre_display'] . ' ' . $gr['nivel_nombre'];
                    $per = $al['situacion'] === SITUACION_PER;
                ?>
                    <tbody class="riesgo-alumno<?= $per ? ' riesgo-alumno--critico' : '' ?>"
                           data-riesgo-fila data-buscar="<?= e($buscar) ?>">
                        <tr class="riesgo-alumno__franja">
                            <?php // El `div` es lo que queda fijo a la izquierda en
                                  // móvil (SASS): sin él, nombre, marca y motivo se
                                  // iban con el desplazamiento horizontal de la tabla. ?>
                            <th scope="rowgroup" colspan="5"><div class="riesgo-alumno__franja-cont">
                                <span class="riesgo-alumno__nombre"><?= e($al['nombre_completo']) ?></span>
                                <span class="riesgo-alumno__marca riesgo-alumno__marca--<?= e(strtolower($al['situacion'])) ?>">
                                    <?= e($al['situacion']) ?> &middot; <?= e(situacion_rotulo($al['situacion'])) ?>
                                </span>
                                <?php // Certeza (24/09/2026): se marca SOLO la excepción —el
                                      // riesgo que lo pendiente todavía puede salvar—. Una marca
                                      // «Seguro» en casi cada fila tapaba el caso raro y sonaba
                                      // a definitivo en un informe que es proyección. ?>
                                <?php if ($al['certeza'] === CERTEZA_PROYECTADA): ?>
                                    <span class="riesgo-alumno__certeza riesgo-alumno__certeza--proyectada">
                                        Depende de <?= (int) $al['pendientes'] ?> pendiente<?= (int) $al['pendientes'] !== 1 ? 's' : '' ?>
                                    </span>
                                <?php endif; ?>
                                <span class="riesgo-alumno__dato">
                                    Sección <?= e($al['seccion_nombre']) ?>
                                    <?php if (!empty($al['retorno'])):
                                        // Retorno de grado: se lista en su matrícula OFICIAL,
                                        // que es la que fija su grado y, con él, la regla que
                                        // se le aplica. Se dice dónde cursó el bimestre.
                                        $rt = $al['retorno']; ?>
                                        &middot; <span class="riesgo-cur">Retorno de grado: cursó este bimestre en
                                        <?= e($rt['grado_nombre'] . ' ' . $rt['seccion_nombre'] . ' de ' . $rt['nivel_nombre']) ?></span>
                                    <?php endif; ?>
                                    &middot; <?= (int) $al['num_competencias'] ?> de <?= (int) $al['competencias_plan'] ?> competencias evaluadas
                                    <?php if ($al['arrastradas'] > 0): ?>
                                        (<?= (int) $al['arrastradas'] ?> de bimestres anteriores)
                                    <?php endif; ?>
                                    (AD <?= (int) $al['num_ad'] ?> &middot; A <?= (int) $al['num_a'] ?>
                                    &middot; B <?= (int) $al['num_b'] ?> &middot; C <?= (int) $al['num_c'] ?>)
                                    <?php if ($al['pendientes'] > 0): ?>
                                        &middot; <?= (int) $al['pendientes'] ?> pendiente<?= $al['pendientes'] !== 1 ? 's' : '' ?>
                                    <?php endif; ?>
                                </span>
                                <span class="riesgo-alumno__motivo"><?= e($al['motivo']) ?></span>
                            </div></th>
                        </tr>
                        <?php // Columnas repetidas bajo CADA estudiante (24/09/2026): en
                              // pantalla el <thead> del grado queda lejos al desplazarse. ?>
                        <tr class="riesgo-tabla__cols riesgo-alumno__cols">
                            <th scope="col">Área &middot; curso</th>
                            <th scope="col">Competencia</th>
                            <th scope="col" class="riesgo-tabla__num">Lit.</th>
                            <th scope="col" class="riesgo-tabla__num">Nota</th>
                            <th scope="col">Docente</th>
                        </tr>
<?php // ⚠️ SIN INDENTAR a propósito: se repite ~1 400 veces en B1 y no hay
      // minificador de HTML en el pipeline (ver la nota de peso del 07/09 en
      // docs/modulos/usuarios-direccion.md). ?>
<?php foreach ($al['detalle'] as $d): ?>
<tr>
<td><?= e($d['area']) ?><?php if ($d['curso'] !== null): ?> <span class="riesgo-cur">&middot; <?= e($d['curso']) ?></span><?php endif; ?></td>
<td><?php if ($d['codigo'] !== null): ?><span class="riesgo-cod"><?= e($d['codigo']) ?></span> <?php endif; ?><?= e($d['competencia']) ?><?php if (!empty($d['arrastrada'])): ?> <span class="riesgo-cur">&middot; <?= e($d['periodo']) ?></span><?php endif; ?><?php if (!empty($d['efecto'])): ?> <span class="riesgo-efecto riesgo-efecto--<?= e($d['efecto']) ?>"><?= e(situacion_efecto_rotulo($d['efecto'])) ?></span><?php endif; ?></td>
<td class="riesgo-tabla__num riesgo-tabla__lit"><?= e($d['literal']) ?></td>
<td class="riesgo-tabla__num"><?= (int) $d['nota'] ?></td>
<td><?= e($d['docente']) ?></td>
</tr>
<?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>
