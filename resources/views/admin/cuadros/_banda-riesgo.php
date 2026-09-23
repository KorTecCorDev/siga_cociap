<?php
/**
 * Banda de «Estudiantes en riesgo» en `/admin/cuadros` y su A4 (23/09/2026).
 *
 * Es lo que quedó del bloque 3b cuando el listado se fue a su propia vista
 * (`/admin/cuadros/riesgo`): la cifra que un informe de Dirección necesita en
 * una línea, con la regla de cada nivel. Forma PAR con `_banda-merito.php`
 * (azul ↔ rojo; ver su comentario).
 *
 * Las cifras salen de `riesgo_resumen()`, el mismo punto único que usa la vista
 * propia: el tablero y el informe no pueden decir números distintos.
 *
 * @var array     $bloques
 * @var bool|null $riesgoEnlace  lo pone la PANTALLA; el A4 no lo define y no
 *                               emite el enlace (en papel sería un enlace muerto).
 */
$res = riesgo_resumen($bloques['merito']['por_grado'] ?? []);
?>
<div class="cuadros-banda cuadros-banda--riesgo">
    <p class="cuadros-banda__cifra">
        <span class="cuadros-banda__n"><?= (int) $res['total'] ?></span>
        <span class="cuadros-banda__q">
            estudiante<?= $res['total'] !== 1 ? 's' : '' ?> en riesgo académico
            (<strong><?= (int) $res['pct'] ?>%</strong> de <?= (int) $res['evaluados'] ?> evaluados)
        </span>
    </p>
    <ul class="cuadros-banda__datos">
        <li><strong><?= (int) $res['criticos'] ?></strong> de mayor atención</li>
        <li><strong><?= (int) $res['grados'] ?> de <?= (int) $res['grados_total'] ?></strong> grados con casos</li>
    </ul>
    <ul class="riesgo-banda__niveles">
        <?php foreach ($res['por_nivel'] as $niv): ?>
            <li>
                <strong><?= e($niv['nombre']) ?>:</strong>
                <?= (int) $niv['total'] ?> de <?= (int) $niv['evaluados'] ?> (<?= (int) $niv['pct'] ?>%)
                con <?= (int) ($bloques['merito']['riesgo_min_c'] ?? 3) ?> o más competencias <?= e($niv['rotulo']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($riesgoEnlace)): ?>
        <p class="riesgo-banda__enlace">
            <a href="<?= url('admin/cuadros/riesgo?periodo_id=' . (int) $periodo['id']) ?>" class="btn btn--secondary btn--sm">
                Ver el informe completo &rarr;
            </a>
        </p>
    <?php endif; ?>
</div>
