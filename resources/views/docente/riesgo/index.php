<?php
/**
 * Estudiantes en riesgo de MI sección — panel del tutor (23/09/2026).
 * Solo lectura.
 *
 * Mismo bloque que Dirección imprime en el lote por tutor (`_seccion.php`).
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU: requiere
 * recuperación (RR) o permanece en el grado (PER). Solo bimestres PUBLICADOS
 * del nivel (compuerta 044). La sección la fija el servidor (`tutor_id`),
 * nunca la URL.
 *
 * @var array      $seccion      fila de `SeccionModel::seccionesDelAnio()`
 * @var array      $periodos     bimestres publicados de su nivel
 * @var array|null $periodo      el elegido
 * @var array|null $bloque       `SituacionFinalModel::deSeccion()`
 * @var bool       $noPublicado  se pidió un bimestre que aún no está publicado
 */
$pid = $periodo ? (int) $periodo['id'] : 0;

// Aislado en un closure: `_seccion.php` redefine `$riesgo` para sus partials.
$pintarSeccion = static function (array $bloque): void {
    require VIEW_PATH . '/admin/cuadros/riesgo/_seccion.php';
};
?>

<div class="page-header">
    <a href="<?= url('docente/inicio') ?>" class="btn btn--secondary btn--sm">&larr; Volver</a>
    <div>
        <h1 class="page-title page-title--wf page-title--riesgo">Estudiantes en riesgo académico</h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?> &mdash; <?= e($seccion['grado_nombre']) ?> &mdash;
            Sección <?= e($seccion['nombre']) ?>. Estudiantes de tu sección que, con las notas
            de este bimestre, <strong>no alcanzarían la promoción de grado</strong>, con su
            desglose. Solo lectura.
        </p>
    </div>
</div>

<?php if ($noPublicado): ?>
    <div class="alert alert--info">
        Ese bimestre <strong>todavía no está publicado</strong>: el informe se habilita cuando
        se publican las boletas de tu nivel.
        <?php if ($periodo): ?>
            Se muestra <strong><?= e($periodo['nombre_display']) ?></strong>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$periodo): ?>
    <div class="empty-state">
        <p>Todavía no hay ningún bimestre publicado para tu nivel. El informe aparecerá aquí
           cuando se publiquen las boletas del primero.</p>
    </div>
<?php else: ?>
    <div class="tutoria-bimestres">
        <?php foreach ($periodos as $p): ?>
            <a href="<?= url('docente/tutoria/riesgo?periodo_id=' . (int) $p['id']) ?>"
               class="tutoria-bimestres__item<?= (int) $p['id'] === $pid ? ' tutoria-bimestres__item--activo' : '' ?>">
                <?= e($p['nombre_display']) ?>
            </a>
        <?php endforeach; ?>
        <a href="<?= url('docente/tutoria/riesgo/imprimir?periodo_id=' . $pid) ?>"
           class="btn btn--secondary btn--sm" target="_blank" rel="noopener">&#128424; Imprimir</a>
    </div>

    <?php $pintarSeccion($bloque); ?>
<?php endif; ?>
