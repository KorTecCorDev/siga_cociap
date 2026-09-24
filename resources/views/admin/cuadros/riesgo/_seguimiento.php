<?php
/**
 * Estudiantes en SEGUIMIENTO pedagógico (24/09/2026; nació el 23/09 como la
 * lista de 1.º de primaria).
 *
 * 🔴 NO ES UNA LISTA DE RIESGO, Y POR ESO VA APARTE. Son estudiantes que SÍ
 * serían promovidos —o tienen promoción automática: 1.º de primaria, cuadro
 * del literal A, sub numeral 5.1.3 de la RVM 00094-2020-MINEDU— pero acumulan
 * competencias bajas. Decir de ellos que «no alcanzarían la promoción» sería
 * falso; callarlos, perder la señal que el tutor necesita. No entran en
 * ninguna cifra de riesgo.
 *
 * El umbral es `seguimiento_pedagogico()` (punto único en `helpers.php`), el
 * mismo para 1.º que para el resto de primaria: aquí solo se CITA, con sus
 * constantes, nunca se escribe a mano.
 *
 * Mismo marcado que `_listado.php` (tabla por grado, franja por estudiante,
 * desglose de sus competencias no aprobatorias), con marca NEUTRA: un color de
 * semáforo diría «todo bien» o «alarma», y no es ninguna de las dos.
 *
 * @var array $riesgo
 */
$lista = array_values(array_filter($riesgo['filtrado'], static fn(array $g): bool => !empty($g['seguimiento'])));
if (empty($lista)) {
    return;
}
?>
<section class="riesgo-seguimiento">
    <h2 class="riesgo-h2">Estudiantes en seguimiento</h2>
    <p class="riesgo-nota">
        <strong>No están en riesgo</strong>: serían promovidos (en 1.º de primaria la promoción es
        automática) y no entran en ninguna de las cifras anteriores. Se listan porque acumulan
        competencias bajas que conviene acompañar: en primaria, <strong><?= (int) SEGUIMIENTO_MIN_B ?>
        o más en B</strong> o <strong><?= (int) SEGUIMIENTO_MIN_C ?> o más en C</strong>; en
        secundaria, <strong><?= (int) SEGUIMIENTO_MIN_C ?> o más en C</strong>.
    </p>
    <?php foreach ($lista as $g):
        $gr = $g['grado']; ?>
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
                            <span>&mdash; <?= count($g['seguimiento']) ?> de <?= (int) $g['evaluados'] ?>
                            estudiantes evaluados en seguimiento<?= grado_promocion_automatica($gr['nivel_codigo'], (int) $gr['numero']) ? ' &middot; promoción automática' : '' ?></span>
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
                <?php foreach ($g['seguimiento'] as $al):
                    $buscar = $al['nombre_completo'] . ' ' . $al['seccion_nombre'] . ' '
                            . $gr['nombre_display'] . ' ' . $gr['nivel_nombre'];
                ?>
                    <tbody class="riesgo-alumno" data-riesgo-fila data-buscar="<?= e($buscar) ?>">
                        <tr class="riesgo-alumno__franja">
                            <th scope="rowgroup" colspan="5"><div class="riesgo-alumno__franja-cont">
                                <span class="riesgo-alumno__nombre"><?= e($al['nombre_completo']) ?></span>
                                <span class="riesgo-alumno__marca riesgo-alumno__marca--pro">
                                    <?= $al['automatica'] ? 'Promoción automática' : e(SITUACION_PRO . ' · ' . situacion_rotulo(SITUACION_PRO)) ?>
                                </span>
                                <span class="riesgo-alumno__dato">
                                    Sección <?= e($al['seccion_nombre']) ?>
                                    <?php if (!empty($al['retorno'])):
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
                                </span>
                            </div></th>
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
