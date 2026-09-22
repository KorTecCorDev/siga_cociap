<?php
/**
 * NOTAS DEL COLEGIO DE ORIGEN — vista de SOLO LECTURA para el docente.
 *
 * El estudiante llegó trasladado y trae un Informe de Progreso de su colegio
 * anterior. Esas calificaciones NO entran en la boleta del COCIAP: son
 * informativas, para que el docente sepa con qué llega.
 *
 * Se muestra el informe COMPLETO —el plan del otro colegio no coincide con el
 * nuestro y recortarlo escondería lo que no tiene equivalente— con las filas
 * del área que ESTE docente le dicta RESALTADAS.
 *
 * @var array  $estudiante  nombre_completo, grado_nombre, seccion_nombre
 * @var array  $porPeriodo  periodo => filas[]
 * @var int[]  $misAreas    ids de área en los que el docente tiene carga
 * @var string $colegio     colegio de origen (puede ser null)
 * @var int    $total
 */
?>

<div class="page-header">
    <a href="<?= url('notificaciones') ?>" class="btn btn--secondary btn--sm">← Notificaciones</a>
    <div>
        <h1 class="page-title">
            Notas del colegio de origen
            <?php $proc = PROCEDENCIA_ORIGEN; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
        </h1>
        <p class="page-subtitle">
            <?= e($estudiante['nombre_completo']) ?> ·
            <?= e($estudiante['grado_nombre']) ?> "<?= e($estudiante['seccion_nombre']) ?>"
        </p>
    </div>
</div>

<?php // El colegio de origen, a la vista y no perdido en el subtítulo (21/09/2026).
      // Es opcional al registrar: si no se anotó, no se pinta nada. ?>
<?php if (!empty($colegio)): ?>
<div class="card mb-md">
    <div class="card__body">
        <div class="info-grid">
            <div class="info-item">
                <span class="info-item__label">Colegio de origen</span>
                <span class="info-item__value"><?= e($colegio) ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="flash flash--warning">
    <?php // Recortado el 22/09/2026: el banner dice qué son y qué hacer. Las
          // reglas de boleta y de orden de mérito son política de Registro
          // Académico y el docente no puede accionarlas. ?>
    Estas <?= (int) $total ?> calificaciones vienen del <strong>colegio anterior</strong> del
    estudiante y son <strong>solo informativas</strong>:
    <strong>no tienes que hacer nada con ellas</strong>.
    Sirven para que sepas con qué nivel llega.
    <?php if (!empty($misAreas)): ?>
        Las filas <strong>resaltadas</strong> corresponden al área que tú le dictas.
    <?php endif; ?>
</div>

<?php foreach ($porPeriodo as $periodoNombre => $filas): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title"><?= e($periodoNombre) ?></p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas notas-origen__lectura">
                <thead>
                    <tr>
                        <th>Área (colegio de origen)</th>
                        <th>Competencia</th>
                        <th class="text-center">Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $n):
                        $mia = $n['area_id'] !== null
                            && in_array((int) $n['area_id'], $misAreas, true);
                    ?>
                    <tr<?= $mia ? ' class="notas-origen__fila--mia"' : '' ?>>
                        <td>
                            <div class="rect-comp__nombre"><?= e($n['area_nombre']) ?></div>
                            <?php if ($mia): ?>
                                <span class="notas-origen__marca">Tu área</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <?= e($n['competencia_nombre']) ?>
                            <?php // La conclusión del informe de origen (migración 062), si
                                  // el otro colegio la emitió. ?>
                            <?php if (!empty($n['conclusion_descriptiva'])): ?>
                                <p class="conclusion-texto"><?= e($n['conclusion_descriptiva']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="notas-origen__literal" data-literal="<?= e($n['nota_literal']) ?>">
                                <?= e($n['nota_literal']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
