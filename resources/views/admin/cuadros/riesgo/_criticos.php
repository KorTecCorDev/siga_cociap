<?php
/**
 * Casos de mayor atención (23/09/2026): la lista corta al inicio del informe.
 *
 * 🔴 «MAYOR ATENCIÓN» YA NO ES UN UMBRAL DE CONTEO. Lo fue mientras el riesgo
 * se medía en competencias (6 o más no aprobatorias); ahora son los que
 * PERMANECEN EN EL GRADO (`PER`), la situación final más grave que contempla el
 * MINEDU: «C» en más de la mitad de las competencias de cuatro o más áreas.
 * No hay que elegir un número: la norma ya lo eligió.
 *
 * Una tabla por NIVEL, porque la regla difiere entre ellos.
 *
 * En el listado completo estos mismos estudiantes llevan la marca «PER» en su
 * franja: aquí se ven juntos, allí con su desglose.
 *
 * @var array $riesgo
 */
$criticos = $riesgo['stats']['criticos'];
if (empty($criticos)) {
    return;
}

$porNivel = [];
foreach ($criticos as $al) {
    $porNivel[(int) $al['grado']['nivel_id']][] = $al;
}
?>
<section class="riesgo-criticos">
    <h2 class="riesgo-h2">Casos de mayor atención &mdash; permanencia en el grado</h2>
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
                        estudiante<?= count($filas) !== 1 ? 's' : '' ?> que permanecería<?= count($filas) !== 1 ? 'n' : '' ?>
                        en el grado si el año terminara con estas notas
                    </th>
                </tr>
                <tr>
                    <th scope="col">Estudiante</th>
                    <th scope="col">Grado y sección</th>
                    <th scope="col" class="riesgo-stat__n">B</th>
                    <th scope="col" class="riesgo-stat__n">C</th>
                    <th scope="col" class="riesgo-stat__n">Evaluadas</th>
                    <th scope="col">Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $al): ?>
                    <tr>
                        <th scope="row">
                            <?= e($al['nombre_completo']) ?>
                            <?php // Solo la excepción, como en `_listado.php`. ?>
                            <?php if ($al['certeza'] === CERTEZA_PROYECTADA): ?>
                                <span class="riesgo-alumno__certeza riesgo-alumno__certeza--proyectada">
                                    Depende de <?= (int) $al['pendientes'] ?> pendiente<?= (int) $al['pendientes'] !== 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </th>
                        <td>
                            <?= e($al['grado']['nombre_display'] . ' ' . $al['seccion_nombre']) ?>
                            <?php // Retorno de grado: se cuenta en su matrícula oficial,
                                  // pero cursó el bimestre en otro grado. ?>
                            <?php if (!empty($al['retorno'])): ?>
                                <span class="riesgo-cur">(cursó en <?= e($al['retorno']['grado_nombre'] . ' ' . $al['retorno']['seccion_nombre']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="riesgo-stat__n"><?= (int) $al['num_b'] ?></td>
                        <td class="riesgo-stat__n"><strong><?= (int) $al['num_c'] ?></strong></td>
                        <td class="riesgo-stat__n"><?= (int) $al['num_competencias'] ?> de <?= (int) $al['competencias_plan'] ?></td>
                        <td><?= e($al['motivo']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>
</section>
