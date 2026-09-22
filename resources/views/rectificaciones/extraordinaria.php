<?php
/**
 * Formulario de CALIFICACIÓN EXTRAORDINARIA: alta de nota (con motivo) a un
 * alumno SIN calificación en una competencia de un bimestre CERRADO. La nota se
 * registra en un criterio único "Calificación extraordinaria" (confirmado,
 * atribuido a RA): va a boleta y SIAGIE, NO cuenta en el orden de mérito.
 * @var array $info          datos del estudiante (incl. nivel_codigo)
 * @var array $meta          fila de getCompetenciasInsertables (área, competencia, periodo, flags)
 * @var int   $cargaId
 * @var int   $competenciaId
 * @var int   $periodoId
 */
$esPrimaria = ($info['nivel_codigo'] ?? '') === 'prim';

$nombreComp = $meta['nombre_corto'] ?: $meta['competencia_nombre'];
if (($meta['area_tipo'] ?? '') === 'con_subareas' && !empty($meta['subarea_nombre'])) {
    $nombreComp = $meta['subarea_nombre'] . ' — ' . $nombreComp;
}
$noTrabajada = (int) ($meta['notas_seccion'] ?? 0) === 0;
$obligatoriaTxt = $esPrimaria
    ? 'Obligatoria cuando el resultado sea B o C.'
    : 'Obligatoria cuando el resultado sea C.';
$volver = url('rectificaciones/matricula/' . (int) $info['matricula_id']);
?>

<div class="page-header">
    <a href="<?= $volver ?>" class="btn btn--secondary btn--sm">← Cancelar</a>
    <div>
        <h1 class="page-title">Calificación extraordinaria</h1>
        <p class="page-subtitle">
            <?= e($info['nombre_completo']) ?> ·
            <?= e($info['grado_nombre']) ?> "<?= e($info['seccion_nombre']) ?>"
        </p>
    </div>
</div>

<?php if ($flash_error): ?>
    <div class="flash flash--error"><?= e($flash_error) ?></div>
<?php endif; ?>

<div class="card mb-md">
    <div class="card__body">
        <div class="info-grid">
            <div class="info-item"><span class="info-item__label">Área</span><span class="info-item__value"><?= e($meta['nombre_boleta'] ?: $meta['area_nombre'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-item__label">Competencia</span><span class="info-item__value"><?= e($nombreComp) ?></span></div>
            <div class="info-item"><span class="info-item__label">Bimestre</span><span class="info-item__value"><?= e($meta['periodo_nombre'] ?? '—') ?></span></div>
            <div class="info-item">
                <span class="info-item__label">Situación</span>
                <span class="info-item__value">
                    <?= $noTrabajada
                        ? 'No trabajada por el docente (sección sin notas)'
                        : 'Sin nota individual (la sección sí tiene notas)' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="flash flash--warning">
    La calificación extraordinaria se registra en un criterio único a nombre de
    Registro Académico, <strong>separado del registro ordinario del docente</strong>.
    Aparece en la boleta del estudiante y se exporta al SIAGIE, pero
    <strong>no cuenta para el orden de mérito</strong>. Si el bimestre está
    cerrado, la familia la verá en la boleta apenas la registres.
</div>

<form method="POST" action="<?= url('rectificaciones/extraordinaria/guardar') ?>" class="card">
    <div class="card__body">
        <?= csrf_field() ?>
        <input type="hidden" name="matricula_id"   value="<?= (int) $info['matricula_id'] ?>">
        <input type="hidden" name="carga_id"        value="<?= (int) $cargaId ?>">
        <input type="hidden" name="competencia_id"  value="<?= (int) $competenciaId ?>">
        <input type="hidden" name="periodo_id"      value="<?= (int) $periodoId ?>">

        <p class="form-section-title">Nota de la competencia (0–20)</p>
        <p class="text-sm text-muted mb-md">
            Es la nota final del estudiante en esta competencia: no se promedia
            con otros criterios (el estudiante no tiene notas del docente aquí).
        </p>

        <div class="form-group">
            <label class="form-label" for="nota">Nota <span class="text-danger">*</span></label>
            <input type="number" id="nota" name="nota" min="0" max="20" step="1"
                   class="form-input rect-nota-input" inputmode="numeric" required>
        </div>

        <div class="form-group mt-md">
            <label class="form-label" for="conclusion">Conclusión descriptiva</label>
            <textarea id="conclusion" name="conclusion" class="form-input" rows="3"
                      placeholder="Conclusión descriptiva (opcional según el resultado)."></textarea>
            <p class="text-sm text-muted"><?= $obligatoriaTxt ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="motivo">Motivo de la calificación extraordinaria <span class="text-danger">*</span></label>
            <textarea id="motivo" name="motivo" class="form-input" rows="3" required
                      placeholder="Fundamenta la autorización (p. ej. evaluación de recuperación aplicada el ... por ausencia justificada)."></textarea>
            <p class="text-sm text-muted">
                El motivo queda en la auditoría y el docente lo verá junto a la nota
                en sus vistas de solo lectura.
            </p>
        </div>

        <div class="btn-group form-actions">
            <a href="<?= $volver ?>" class="btn btn--secondary">Cancelar</a>
            <button type="submit" class="btn btn--primary">Registrar calificación</button>
        </div>
    </div>
</form>
