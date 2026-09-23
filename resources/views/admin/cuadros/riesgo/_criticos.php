<?php
/**
 * Casos de mayor atención (23/09/2026): la lista corta al inicio del informe.
 * Umbral `OrdenMeritoModel::RIESGO_CRITICO` contado con la regla por nivel
 * (primaria B+C, secundaria C). Una tabla por NIVEL, porque el número de la
 * columna cuenta cosas distintas en cada uno y el rótulo tiene que decirlo.
 *
 * En el listado completo estos mismos estudiantes llevan la marca
 * «MAYOR ATENCIÓN» en su franja: aquí se ven juntos, allí con su desglose.
 *
 * @var array $riesgo
 */
$criticos = $riesgo['stats']['criticos'];
$cb       = $riesgo['contar_b'] ?? true;
if (empty($criticos)) {
    return;
}

$porNivel = [];
foreach ($criticos as $al) {
    $porNivel[(int) $al['grado']['nivel_id']][] = $al;
}
?>
<section class="riesgo-criticos">
    <h2 class="riesgo-h2">Casos de mayor atención</h2>
    <?php foreach ($porNivel as $filas):
        $gr0 = $filas[0]['grado']; ?>
        <?php // El rótulo del nivel va DENTRO del <thead> y no en un <caption>,
              // igual que en `_listado.php`: la tabla puede seguir en la hoja
              // siguiente, y así el rótulo se repite arriba y nunca queda solo al pie. ?>
        <div class="tabla-notas-wrapper">
        <table class="riesgo-stat riesgo-stat--ancha riesgo-criticos__tabla">
            <thead>
                <tr class="riesgo-criticos__nivel">
                    <th scope="colgroup" colspan="6">
                        <?= e($gr0['nivel_nombre']) ?> &mdash; <?= count($filas) ?>
                        estudiante<?= count($filas) !== 1 ? 's' : '' ?> con
                        <?= (int) $riesgo['critico_min'] ?> o más competencias <?= e(riesgo_rotulo($gr0['nivel_codigo'], $cb)) ?>
                    </th>
                </tr>
                <tr>
                    <th scope="col">Estudiante</th>
                    <th scope="col">Grado y sección</th>
                    <th scope="col" class="riesgo-stat__n">Competencias <?= e(riesgo_rotulo($gr0['nivel_codigo'], $cb)) ?></th>
                    <th scope="col" class="riesgo-stat__n">Evaluadas</th>
                    <th scope="col" class="riesgo-stat__n">Promedio</th>
                    <th scope="col" class="riesgo-stat__n">Puesto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $al): ?>
                    <tr>
                        <th scope="row"><?= e($al['nombre_completo']) ?></th>
                        <td><?= e($al['grado']['nombre_display'] . ' ' . $al['seccion_nombre']) ?></td>
                        <td class="riesgo-stat__n">
                            <strong><?= (int) $al['conteo'] ?></strong>
                            <?php if (in_array('B', riesgo_literales($al['grado']['nivel_codigo'], $cb), true)): ?>
                                <span class="riesgo-cur">(B <?= (int) $al['num_b'] ?> &middot; C <?= (int) $al['num_c'] ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="riesgo-stat__n"><?= (int) $al['num_competencias'] ?></td>
                        <td class="riesgo-stat__n"><?= e(number_format((float) $al['promedio_general'], 2)) ?></td>
                        <td class="riesgo-stat__n">
                            <?= (int) $al['puesto'] ?>
                            <?php // Retorno de grado: el puesto es del grado donde se evalúa. ?>
                            <?php if (!empty($al['retorno'])): ?>
                                <span class="riesgo-cur">(en <?= e($al['retorno']['grado']['nombre_display'] . ' ' . $al['retorno']['seccion_nombre']) ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>
</section>
