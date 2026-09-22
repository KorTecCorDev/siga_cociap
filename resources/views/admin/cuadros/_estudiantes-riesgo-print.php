<?php
/**
 * Estudiantes en riesgo — formato de INFORME AGRUPADO para el A4 (22/09/2026).
 * Lo incluye SOLO `_estudiantes-riesgo.php` en su rama de papel; la pantalla
 * conserva su rejilla de nueve columnas con el desglose plegado.
 *
 * 🔴 POR QUE OTRO MARCADO Y NO LA REJILLA DE PANTALLA CON OTROS ESTILOS. En
 * papel la rejilla llevaba, debajo de cada estudiante, una sub-tabla con su
 * propio <caption> («Competencias en C · NOMBRE») y su propio <thead>: el
 * nombre salia dos veces y habia 118 encabezados (B1) con el mismo gris y el
 * mismo peso que el de la tabla, apilados ademas con el que se repite en cada
 * hoja. Nadie sabia que encabezado mandaba sobre que fila.
 *
 * Aqui hay UNA tabla por grado con UN encabezado —el del desglose, que es lo
 * que tiene columnas— y cada estudiante es una FRANJA DE GRUPO: un <tbody>
 * propio cuya primera fila es un `th scope="rowgroup"` con su nombre y su
 * perfil. El <tbody> lleva `break-inside: avoid`: un estudiante nunca queda
 * partido entre hojas ni su franja huerfana al pie.
 *
 * ⚠️ Sin tablas anidadas (lo vigila `verif_direccion_superficies.php`) y sin
 * `cuadros-top--riesgo` ni `cuadros-top__bloque--riesgo`: esas clases son de
 * la rejilla de pantalla —la primera trae nueve anchos en % que desarmarian
 * estas cinco columnas, la segunda la cuenta el verificador en pantalla—.
 * `data-riesgo-detalle` SI va, uno por estudiante: el verificador lo cuenta en
 * papel para asegurar que el desglose sigue impreso.
 *
 * @var array $riesgoGrados  grados con casos, ya filtrados por el partial padre
 */
?>
<?php foreach ($riesgoGrados as $g): ?>
    <?php $totalGrado = (int) $g['total']; ?>
    <div class="cuadros-top__bloque riesgo-informe__grado">
        <table class="tabla-notas cuadros-top riesgo-informe">
            <caption class="riesgo-informe__caption">
                <?= e($g['grado']['nombre_display'] . ' de ' . $g['grado']['nivel_nombre']) ?>
                <span class="riesgo-informe__caption-n">
                    &mdash; <?= count($g['en_riesgo']) ?> de los <?= $totalGrado ?>
                    estudiantes evaluados del grado
                </span>
            </caption>
            <thead>
                <tr>
                    <th scope="col" class="riesgo-informe__area">Área</th>
                    <th scope="col" class="riesgo-informe__curso">Curso</th>
                    <th scope="col" class="riesgo-informe__comp">Competencia en C</th>
                    <th scope="col" class="riesgo-informe__nota text-center">Nota</th>
                    <th scope="col" class="riesgo-informe__docente">Docente</th>
                </tr>
            </thead>
            <?php foreach ($g['en_riesgo'] as $al): ?>
                <?php $det = $al['detalle_c'] ?? null; ?>
                <tbody class="riesgo-informe__alumno" data-riesgo-detalle>
                    <tr class="riesgo-informe__grupo">
                        <th scope="rowgroup" colspan="5">
                            <span class="riesgo-informe__nombre"><?= e($al['nombre_completo']) ?></span>
                            <span class="riesgo-informe__dato">
                                Sección <?= e($al['seccion_nombre']) ?>
                                &middot; Puesto <?= (int) $al['puesto'] ?> de <?= $totalGrado ?>
                                &middot; Promedio <?= e(number_format((float) $al['promedio_general'], 2)) ?>
                            </span>
                            <span class="riesgo-informe__dato">
                                <strong><?= (int) $al['num_c'] ?> competencias en C</strong>
                                de <?= (int) $al['num_competencias'] ?> evaluadas
                                (AD <?= (int) $al['num_ad'] ?> &middot; A <?= (int) $al['num_a'] ?>
                                &middot; B <?= (int) $al['num_b'] ?> &middot; C <?= (int) $al['num_c'] ?>)
                            </span>
                        </th>
                    </tr>
                    <?php if ($det === null): ?>
                        <?php // Mismo guard que en pantalla: en un bimestre cerrado la fila
                              // viene del snapshot y el desglose en vivo puede no cuadrar. ?>
                        <tr>
                            <td colspan="5" class="riesgo-detalle__aviso">
                                El desglose no coincide con el dato oficial del cierre (las notas
                                cambiaron después de cerrar el bimestre), así que se omite para no
                                mostrar dos cifras distintas.
                            </td>
                        </tr>
                    <?php else: ?>
<?php // ⚠️ SIN INDENTAR a proposito: se repite 778 veces en B1. Ver la nota de
      // peso en `_estudiantes-riesgo.php`. ?>
<?php foreach ($det as $d): ?>
<tr>
<td><?= e($d['area']) ?></td>
<td><?= $d['curso'] !== null ? e($d['curso']) : '&mdash;' ?></td>
<td><?php if ($d['codigo'] !== null): ?><span class="competencia-card__codigo competencia-card__codigo--solo"><?= e($d['codigo']) ?></span> <?php endif; ?><?= e($d['competencia']) ?></td>
<td class="text-center"><strong><?= (int) $d['nota'] ?></strong></td>
<td><?= e($d['docente']) ?></td>
</tr>
<?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            <?php endforeach; ?>
        </table>
    </div>
<?php endforeach; ?>
