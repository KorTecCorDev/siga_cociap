<?php
/**
 * Estudiantes en riesgo de MI sección — A4 vertical del tutor (layout print,
 * 23/09/2026). Misma cabecera y mismo bloque que una hoja del lote de Dirección
 * (`admin/cuadros/riesgo/tutores.php`): el tutor imprime lo mismo que le
 * entregarían. Documento de trabajo, sin sello.
 *
 * @var array $seccion
 * @var array $periodo
 * @var array $bloque   `OrdenMeritoModel::riesgoDeSeccion()`
 */
$alcance = $seccion['grado_nombre'] . ' ' . $seccion['nombre'] . ' de ' . $seccion['nivel_nombre'];
$contarB = true;

$pintarSeccion = static function (array $bloque, array $opts): void {
    require VIEW_PATH . '/admin/cuadros/riesgo/_seccion.php';
};
?>
<div class="riesgo-print riesgo-lote">
    <div class="riesgo-lote__hoja">
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_cabecera.php'; ?>
        <?php $pintarSeccion($bloque, [
            'contar_b'    => true,
            'min'         => App\Models\OrdenMeritoModel::RIESGO_MIN_C,
            'critico_min' => App\Models\OrdenMeritoModel::RIESGO_CRITICO,
        ]); ?>
    </div>
</div>
