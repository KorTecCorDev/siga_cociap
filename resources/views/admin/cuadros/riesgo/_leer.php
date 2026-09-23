<?php
/**
 * «Cómo leer este informe» (23/09/2026). Uno para la pantalla y el A4. En papel
 * va ANTES de las tablas: quien lee una hoja impresa no puede preguntar.
 *
 * Los umbrales y literales salen de las constantes y de `riesgo_literales()` /
 * `escala_rangos()`: ningún número ni letra de la regla está escrito a mano.
 *
 * @var array $riesgo
 */
$min  = (int) $riesgo['min'];
$crit = (int) $riesgo['critico_min'];
$cb   = $riesgo['contar_b'] ?? true;
?>
<div class="riesgo-leer">
    <p class="riesgo-leer__titulo"><strong>Cómo leer este informe</strong></p>
    <ul>
        <li>
            <strong>Primaria:</strong> entra quien tiene <strong><?= $min ?> o más competencias
            <?= e(riesgo_rotulo('prim', $cb)) ?></strong><?= $cb ? ' (sumadas)' : '' ?>; es de <strong>mayor atención</strong>
            con <?= $crit ?> o más. En primaria la B no es aprobatoria.
        </li>
        <?php if (!$cb): ?>
            <li>
                <strong>Vista «solo C» en primaria:</strong> la regla oficial cuenta B y C; aquí se
                dejan fuera las B, así que la lista, las cifras y el desglose solo cuentan
                competencias en C. No afecta a secundaria.
            </li>
        <?php endif; ?>
        <li>
            <strong>Secundaria:</strong> entra quien tiene <strong><?= $min ?> o más competencias
            <?= e(riesgo_rotulo('sec')) ?></strong>; es de <strong>mayor atención</strong> con
            <?= $crit ?> o más.
        </li>
        <li>
            Las cifras salen del <strong>orden de mérito</strong>: cuentan solo las competencias ya
            bloqueadas, sin áreas exoneradas ni notas extraordinarias. En un bimestre abierto la
            lista crece conforme se bloquean competencias.
        </li>
        <li>
            Cada grado se ordena de más a menos competencias en riesgo; a igual número, primero quien
            tiene más C y luego el promedio más bajo. Debajo de cada estudiante van sus competencias
            en riesgo con el área, el curso, el literal, la nota y el docente que la registró.
        </li>
        <li>
            Escala:
            <?php $partes = [];
            foreach (escala_rangos() as $lit => $rango) {
                $partes[] = '<strong>' . e($lit) . '</strong> ' . e($rango) . ' (' . e(descripcion_literal($lit)) . ')';
            } ?>
            <?= implode(' &middot; ', $partes) ?>.
        </li>
        <li>
            No confundir con <strong>«Promedio en C»</strong> de los cuadros estadísticos: aquélla
            cuenta a quien tiene el promedio general por debajo de <?= (int) NOTA_MIN_B ?>.
        </li>
    </ul>
</div>
