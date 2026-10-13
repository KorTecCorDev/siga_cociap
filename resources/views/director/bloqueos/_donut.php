<?php
/**
 * Donut de estado de un panel del hub de bloqueos.
 *
 * @var array $donut [
 *     'total'     => int,     // universo del panel (competencias o secciones)
 *     'unidad'    => string,  // "competencias", "secciones"...
 *     'aria'      => string,  // etiqueta accesible del grafico
 *     'segmentos' => [['label' => string, 'num' => int, 'mod' => 'ok'|'warn'|'err'], ...],
 * ]
 * El primer segmento es el "completado" que va al centro. Los arcos se pintan
 * en el orden recibido, empezando a las 12 en punto.
 */

$_dColores = ['ok' => '#16a34a', 'warn' => '#d97706', 'err' => '#dc2626'];
$_dTotal   = (int) $donut['total'];
$_dOffset  = 25; // 12 en punto (circunferencia = 100)
$_dArcos   = [];

foreach ($donut['segmentos'] as $_dSeg) {
    $_dPct    = $_dTotal > 0 ? (int) $_dSeg['num'] / $_dTotal * 100 : 0;
    $_dArcos[] = $_dSeg + [
        'pct'    => $_dPct,
        'dash'   => round($_dPct, 2) . ' ' . round(100 - $_dPct, 2),
        'offset' => round($_dOffset, 2),
    ];
    $_dOffset -= $_dPct;
}
?>
<div class="bloqueos-donut card mb-md">
    <div class="bloqueos-donut__svg-wrap" role="img" aria-label="<?= e($donut['aria']) ?>">
        <svg viewBox="0 0 42 42" class="bloqueos-donut__svg">
            <!-- Fondo -->
            <circle cx="21" cy="21" r="15.9155" fill="none"
                    stroke="#e5e7eb" stroke-width="4"/>
            <?php foreach ($_dArcos as $_dArco): ?>
            <?php if ($_dArco['pct'] > 0): ?>
            <circle cx="21" cy="21" r="15.9155" fill="none"
                    stroke="<?= $_dColores[$_dArco['mod']] ?>" stroke-width="4"
                    stroke-dasharray="<?= $_dArco['dash'] ?>"
                    stroke-dashoffset="<?= $_dArco['offset'] ?>"/>
            <?php endif; ?>
            <?php endforeach; ?>
            <!-- Texto central -->
            <text x="21" y="19.5" class="bloqueos-donut__pct-svg"><?= number_format($_dArcos[0]['pct'], 2) ?>%</text>
            <text x="21" y="24"   class="bloqueos-donut__sub-svg">completado</text>
        </svg>
    </div>

    <div class="bloqueos-donut__leyenda">
        <p class="bloqueos-donut__titulo">
            <?= $_dTotal ?> <?= e($donut['unidad']) ?> &mdash; <?= e($periodo['nombre_display']) ?>
        </p>
        <?php foreach ($_dArcos as $_dArco): ?>
        <div class="bloqueos-donut__item">
            <span class="bloqueos-donut__dot bloqueos-donut__dot--<?= $_dArco['mod'] ?>"></span>
            <span class="bloqueos-donut__label"><?= e($_dArco['label']) ?></span>
            <strong class="bloqueos-donut__num"><?= (int) $_dArco['num'] ?></strong>
            <span class="bloqueos-donut__pct-txt"><?= number_format($_dArco['pct'], 2) ?>%</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
