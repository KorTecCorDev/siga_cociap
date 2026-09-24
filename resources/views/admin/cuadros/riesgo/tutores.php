<?php
/**
 * Estudiantes en riesgo — LOTE por tutor, A4 vertical (layout print, 23/09/2026).
 *
 * Una sección por bloque, CADA UNA EN HOJA NUEVA (`riesgo-lote__hoja`, salto
 * antes de todas salvo la primera), con la cabecera institucional y su tutor
 * actual: Dirección imprime una vez y reparte, y cada tutor se lleva solo sus
 * hojas. Una sección sin casos también lleva su hoja corta —la buena noticia
 * también se entrega—.
 *
 * El bloque es `_seccion.php`, el mismo que ve el tutor en su panel.
 *
 * @var array $periodo
 * @var array $riesgo   salida de componerRiesgo() (alcance)
 * @var array $bloques  salida de riesgo_por_seccion()
 */
// Aislado en un closure: `_seccion.php` redefine `$riesgo` para sus partials
// y no debe pisar el de esta página.
$pintarSeccion = static function (array $bloque): void {
    require VIEW_PATH . '/admin/cuadros/riesgo/_seccion.php';
};
?>
<div class="riesgo-print riesgo-lote">
    <?php foreach ($bloques as $bloque):
        $sec     = $bloque['seccion'];
        $alcance = $sec['grado_nombre'] . ' ' . $sec['nombre'] . ' de ' . $sec['nivel_nombre']; ?>
        <div class="riesgo-lote__hoja">
            <?php require VIEW_PATH . '/admin/cuadros/riesgo/_cabecera.php'; ?>
            <?php $pintarSeccion($bloque); ?>
        </div>
    <?php endforeach; ?>
</div>
