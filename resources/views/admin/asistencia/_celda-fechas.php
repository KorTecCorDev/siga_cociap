<?php
/**
 * CELDA de fechas de la tabla de justificaciones (13/10/2026), compartida por la
 * vista de la sección y el reporte del auxiliar: la cantidad y, debajo, una
 * FICHA por fecha. Los días lectivos SEGUIDOS van en una sola ficha de tramo
 * («Lun. 17/08 al Vie. 25/09 · 28 días»): una ausencia larga se lee de un
 * vistazo. Los tramos los arma `AsistenciaModel::tramosDeFechas`.
 *
 * Las fichas llevan el COLOR de su categoría (13/10/2026, pedido del usuario),
 * con el mismo patrón que las pastillas del encabezado: FJ con el icono de
 * documento (faltas justificadas), FJ sin icono (cuentan como falta) y TJ. Se
 * distinguen de una pastilla por la forma: la pastilla lleva la sigla; la
 * ficha, la fecha.
 *
 * @var array    $r           fila de resumenJustificaciones()['estudiantes']
 * @var string   $cat         fj_autorizadas | fj_como_f | tj
 * @var \Closure $fechaCorta  'AAAA-MM-DD' → «Lun. 08/09»
 */
$tramosCelda = $r['tramos'][$cat] ?? [];
$claseFicha  = 'just-fecha just-fecha--' . ['fj_autorizadas' => 'fj-doc', 'fj_como_f' => 'fj', 'tj' => 'tj'][$cat];
?>
<?php if ($tramosCelda === []): ?>
    <span class="text-muted">&mdash;</span>
<?php else: ?>
    <strong class="just-celda__n"><?= count($r['fechas'][$cat]) ?></strong>
    <span class="just-celda__fechas">
        <?php foreach ($tramosCelda as $t): ?>
            <?php if ($t['dias'] === 1): ?>
                <span class="<?= $claseFicha ?>"><?= e($fechaCorta($t['desde'])) ?></span>
            <?php else: ?>
                <span class="<?= $claseFicha ?> just-fecha--tramo">
                    <?= e($fechaCorta($t['desde'])) ?> al <?= e($fechaCorta($t['hasta'])) ?>
                    <span class="just-fecha__dias">· <?= (int) $t['dias'] ?> días</span>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </span>
<?php endif; ?>
