<?php
/**
 * FECHAS de una categoría de asistencia en la boleta (17/10/2026): una ficha por
 * fecha suelta («10/09») o por tramo de días lectivos seguidos («17/08 al 25/09
 * (28 días)»). Solo la boleta DIGITAL: la imprimible es una sola hoja y no lleva
 * fechas (18/10/2026). Su aspecto lo da `$claseFecha`. Sin motivo: la boleta dice cuándo, no
 * por qué (decisión del usuario). Los tramos los arma
 * `AsistenciaModel::fechasParaBoleta`.
 *
 * @var list<array{desde:string, hasta:string, dias:int}> $tramos
 * @var string $claseFecha  clase BEM de cada ficha
 */
use App\Models\AsistenciaModel;

foreach ($tramos as $t):
    $textoFecha = (int) $t['dias'] === 1
        ? AsistenciaModel::ddmm($t['desde'])
        : AsistenciaModel::ddmm($t['desde']) . ' al ' . AsistenciaModel::ddmm($t['hasta'])
          . ' (' . (int) $t['dias'] . ' días)';
?>
    <span class="<?= e($claseFecha) ?>"><?= e($textoFecha) ?></span>
<?php endforeach; ?>
