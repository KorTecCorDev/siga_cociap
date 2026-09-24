<?php
/**
 * 1.º de primaria — seguimiento pedagógico (23/09/2026).
 *
 * 🔴 NO ES UNA LISTA DE RIESGO, Y POR ESO VA APARTE. En 1.º de primaria la
 * promoción es AUTOMÁTICA (cuadro del literal A, sub numeral 5.1.3 de la
 * RVM 00094-2020-MINEDU): ningún estudiante de ese grado puede requerir
 * recuperación ni permanecer, así que decir de él que «no alcanzaría la
 * promoción» sería sencillamente falso.
 *
 * Pero sus competencias en B y C siguen siendo la señal pedagógica que el tutor
 * necesita —en primaria la B tampoco aprueba—, así que se listan en su propia
 * sección, rotulada, en vez de ocultarlas o de mezclarlas con el riesgo
 * (decisión del usuario). No entran en ninguna cifra de riesgo.
 *
 * @var array $riesgo
 */
$lista = array_values(array_filter($riesgo['filtrado'], static fn(array $g): bool => !empty($g['automatica'])));
if (empty($lista)) {
    return;
}
?>
<section class="riesgo-automatica">
    <h2 class="riesgo-h2">1.º de primaria &mdash; seguimiento pedagógico</h2>
    <p class="riesgo-nota">
        La promoción en 1.º de primaria es <strong>automática</strong>: estos estudiantes
        <strong>no están en riesgo</strong> y no entran en ninguna de las cifras anteriores.
        Se listan porque sus competencias por debajo de A son la señal que conviene atender.
    </p>
    <?php foreach ($lista as $g): ?>
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
                            <?= e($g['grado']['nombre_display'] . ' de ' . $g['grado']['nivel_nombre']) ?>
                            <span>&mdash; <?= count($g['automatica']) ?> de <?= (int) $g['evaluados'] ?>
                            estudiantes evaluados con competencias por debajo de A &middot; promoción automática</span>
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
                <?php foreach ($g['automatica'] as $al):
                    $buscar = $al['nombre_completo'] . ' ' . $al['seccion_nombre'] . ' '
                            . $g['grado']['nombre_display'] . ' ' . $g['grado']['nivel_nombre'];
                ?>
                    <tbody class="riesgo-alumno" data-riesgo-fila data-buscar="<?= e($buscar) ?>">
                        <tr class="riesgo-alumno__franja">
                            <th scope="rowgroup" colspan="5">
                                <span class="riesgo-alumno__nombre"><?= e($al['nombre_completo']) ?></span>
                                <span class="riesgo-alumno__dato">
                                    Sección <?= e($al['seccion_nombre']) ?>
                                    &middot; <?= (int) $al['num_competencias'] ?> de <?= (int) $al['competencias_plan'] ?> competencias evaluadas
                                    (AD <?= (int) $al['num_ad'] ?> &middot; A <?= (int) $al['num_a'] ?>
                                    &middot; B <?= (int) $al['num_b'] ?> &middot; C <?= (int) $al['num_c'] ?>)
                                </span>
                            </th>
                        </tr>
<?php // ⚠️ SIN INDENTAR a propósito, igual que en `_listado.php`. ?>
<?php foreach ($al['detalle'] as $d): ?>
<tr>
<td><?= e($d['area']) ?><?php if ($d['curso'] !== null): ?> <span class="riesgo-cur">&middot; <?= e($d['curso']) ?></span><?php endif; ?></td>
<td><?php if ($d['codigo'] !== null): ?><span class="riesgo-cod"><?= e($d['codigo']) ?></span> <?php endif; ?><?= e($d['competencia']) ?><?php if (!empty($d['arrastrada'])): ?> <span class="riesgo-cur">&middot; <?= e($d['periodo']) ?></span><?php endif; ?></td>
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
