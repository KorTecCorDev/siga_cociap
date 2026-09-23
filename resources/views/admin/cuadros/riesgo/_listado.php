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
 * resumen; los filtros de secciones y de B en primaria sí, porque van por URL.
 *
 * En la lente «primaria solo C» la franja conserva la distribución completa
 * (AD · A · B · C): es la del estudiante, no la cifra de riesgo, y sin la B no
 * sumaría sus competencias evaluadas (decisión del usuario, 23/09/2026).
 *
 * @var array $riesgo
 */
$lista = array_values(array_filter($riesgo['filtrado'], static fn(array $g): bool => !empty($g['en_riesgo'])));
?>
<section class="riesgo-listado">
    <h2 class="riesgo-h2">Listado por grado</h2>
    <?php foreach ($lista as $g):
        $gr    = $g['grado'];
        // `total` = competidores del MÉRITO (el «de N» del puesto); `evaluados`
        // = denominador del RIESGO, por matrícula oficial. Solo difieren en los
        // grados con un retorno de grado.
        $total     = (int) $g['total'];
        $evaluados = (int) ($g['evaluados'] ?? $g['total']);
        $rot   = riesgo_rotulo($gr['nivel_codigo'], $riesgo['contar_b'] ?? true);
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
                        <th scope="colgroup" colspan="5">
                            <?= e($gr['nombre_display'] . ' de ' . $gr['nivel_nombre']) ?>
                            <span>&mdash; <?= count($g['en_riesgo']) ?> de <?= $evaluados ?> estudiantes evaluados
                            &middot; se cuentan las competencias <?= e($rot) ?></span>
                        </th>
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
                    $det = $al['detalle'];
                ?>
                    <tbody class="riesgo-alumno<?= $al['critico'] ? ' riesgo-alumno--critico' : '' ?>"
                           data-riesgo-fila data-buscar="<?= e($buscar) ?>">
                        <tr class="riesgo-alumno__franja">
                            <th scope="rowgroup" colspan="5">
                                <span class="riesgo-alumno__nombre"><?= e($al['nombre_completo']) ?></span>
                                <?php if ($al['critico']): ?>
                                    <span class="riesgo-alumno__marca">Mayor atención</span>
                                <?php endif; ?>
                                <span class="riesgo-alumno__dato">
                                    Sección <?= e($al['seccion_nombre']) ?>
                                    <?php if (!empty($al['retorno'])):
                                        // Retorno de grado: se lista en su matrícula OFICIAL,
                                        // pero compite en el mérito donde se evalúa. El puesto
                                        // es de ese grado, y se dice cuál (decisión del usuario).
                                        $rt = $al['retorno']; ?>
                                        &middot; <span class="riesgo-cur">Retorno de grado: se evalúa en
                                        <?= e($rt['grado']['nombre_display'] . ' ' . $rt['seccion_nombre'] . ' de ' . $rt['grado']['nivel_nombre']) ?>,
                                        puesto <?= (int) $al['puesto'] ?> de <?= (int) $rt['total'] ?></span>
                                    <?php else: ?>
                                        &middot; Puesto <?= (int) $al['puesto'] ?> de <?= $total ?>
                                    <?php endif; ?>
                                    &middot; Promedio <?= e(number_format((float) $al['promedio_general'], 2)) ?>
                                    &middot; <strong><?= (int) $al['conteo'] ?> <?= e($rot) ?></strong>
                                    de <?= (int) $al['num_competencias'] ?>
                                    (AD <?= (int) $al['num_ad'] ?> &middot; A <?= (int) $al['num_a'] ?>
                                    &middot; B <?= (int) $al['num_b'] ?> &middot; C <?= (int) $al['num_c'] ?>)
                                </span>
                            </th>
                        </tr>
                        <?php if ($det === null): ?>
                            <?php // En un bimestre cerrado la fila viene del snapshot y
                                  // el desglose se calcula en vivo: si no cuadran, se
                                  // calla en vez de mostrar dos cifras distintas. ?>
                            <tr>
                                <td colspan="5" class="riesgo-alumno__aviso">
                                    El desglose no coincide con el dato oficial del cierre (las notas
                                    cambiaron después de cerrar el bimestre), así que se omite para no
                                    mostrar dos cifras distintas.
                                </td>
                            </tr>
                        <?php else: ?>
<?php // ⚠️ SIN INDENTAR a propósito: se repite ~1 400 veces en B1 y no hay
      // minificador de HTML en el pipeline (ver la nota de peso del 07/09 en
      // docs/modulos/usuarios-direccion.md). ?>
<?php foreach ($det as $d): ?>
<tr>
<td><?= e($d['area']) ?><?php if ($d['curso'] !== null): ?> <span class="riesgo-cur">&middot; <?= e($d['curso']) ?></span><?php endif; ?></td>
<td><?php if ($d['codigo'] !== null): ?><span class="riesgo-cod"><?= e($d['codigo']) ?></span> <?php endif; ?><?= e($d['competencia']) ?></td>
<td class="riesgo-tabla__num riesgo-tabla__lit"><?= e($d['literal']) ?></td>
<td class="riesgo-tabla__num"><?= (int) $d['nota'] ?></td>
<td><?= e($d['docente']) ?></td>
</tr>
<?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>
