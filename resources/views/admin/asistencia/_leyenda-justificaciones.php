<?php
/**
 * Leyenda de las JUSTIFICACIONES (13/10/2026). PUNTO ÚNICO del vocabulario que
 * comparten la vista de la sección (`admin/asistencia/justificaciones.php`) y el
 * reporte del auxiliar (`auxiliar/justificaciones.php`). Cada categoría lleva
 * la pastilla de la grilla (`_pastilla-categoria.php`) y lo que VALE:
 *   «Faltas justificadas» · «Cuentan como falta» · «Tardanzas justificadas».
 * La regla la aplica el modelo (`AsistenciaEstadisticaModel::tipoQueCuenta`);
 * esto solo la explica.
 *
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 */
$explicaciones = [
    'fj_autorizadas' => ['Faltas justificadas', !empty($motivoPrincipal)
        ? 'con «' . $motivoPrincipal . '» (ícono de documento).'
        : 'con el motivo principal (ícono de documento).'],
    'fj_como_f'      => ['Cuentan como falta', 'justificadas con cualquier otro motivo. En la boleta suman como faltas.'],
    'tj'             => ['Tardanzas justificadas', 'con cualquier motivo.'],
];
?>
<ul class="just-leyenda">
    <?php foreach ($explicaciones as $cat => [$titulo, $texto]): ?>
        <li>
            <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
            <span><strong><?= e($titulo) ?>:</strong> <?= e($texto) ?></span>
        </li>
    <?php endforeach; ?>
</ul>
