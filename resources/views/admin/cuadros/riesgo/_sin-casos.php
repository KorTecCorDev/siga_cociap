<?php
/**
 * Texto de «nadie en riesgo» (24/09/2026). PUNTO ÚNICO de las cinco vistas que
 * lo dicen: la banda de `/admin/cuadros` y su A4, `/admin/cuadros/riesgo` y su
 * A4, y el bloque por sección (lote por tutor y panel del tutor). Emite SOLO el
 * texto: cada vista conserva su envoltorio y su clase.
 *
 * 🔴 CERO CASOS NO ES UNA BUENA NOTICIA SI LA PROYECCIÓN ES PARCIAL. Nació
 * porque en un bimestre abierto (B3: 23 evaluados, los 23 a medio calificar, y
 * 500 sin una sola nota) todas las vistas decían «todos alcanzarían la
 * promoción», sin aviso: el aviso de cobertura vivía en `_resumen.php`, que
 * con cero casos no se pinta. Con cobertura parcial o estudiantes sin datos,
 * el texto lo dice y da las cifras.
 *
 * @var array  $res      salida de `riesgo_resumen()` (ya filtrada si corresponde)
 * @var string $sinCasosAlcance  complemento del sujeto: '', 'de esta selección', 'de esta sección'…
 */
$evaluados = (int) $res['evaluados'];
$parciales = (int) $res['cobertura']['parciales'];
$sinDatos  = (int) $res['sin_datos'];
$sujeto    = $sinCasosAlcance !== '' ? ' ' . $sinCasosAlcance : '';
?>
<?php if ($evaluados === 0): ?>
    Todavía no hay estudiantes evaluados<?= e($sujeto) ?> en este bimestre: aún no puede
    determinarse la situación final de nadie.
<?php elseif ($parciales === 0 && $sinDatos === 0): ?>
    Con el último nivel registrado de cada competencia, <?= $evaluados === 1 ? 'el estudiante evaluado' : 'los ' . $evaluados . ' estudiantes evaluados' ?><?= e($sujeto) ?>
    alcanzaría<?= $evaluados !== 1 ? 'n' : '' ?> la promoción de grado.
<?php else: ?>
    Por ahora <?= $evaluados === 1 ? 'el estudiante evaluado' : 'ninguno de los ' . $evaluados . ' estudiantes evaluados' ?><?= e($sujeto) ?>
    <?= $evaluados === 1 ? 'no' : '' ?> queda en riesgo, pero <strong>la proyección es parcial</strong>:
    <?php if ($parciales > 0): ?>
        <?= $parciales ?> todavía con competencias de su plan sin calificar<?= $sinDatos > 0 ? ' y' : '.' ?>
    <?php endif; ?>
    <?php if ($sinDatos > 0): ?>
        <?= $sinDatos ?> sin ninguna competencia evaluada, que queda<?= $sinDatos !== 1 ? 'n' : '' ?> fuera del cálculo.
    <?php endif; ?>
<?php endif; ?>
