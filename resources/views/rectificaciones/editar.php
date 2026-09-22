<?php
/**
 * Formulario de rectificación por criterio de UNA competencia.
 * @var array $info          datos del estudiante (incl. nivel_codigo)
 * @var array $meta          encabezado de la competencia (nota/conclusión actuales, flags)
 * @var array $criterios     criterios activos con la nota actual del alumno
 * @var int   $cargaId
 * @var int   $competenciaId
 * @var int   $periodoId
 * @var array $literalesConclusion literales que EXIGEN conclusión en este nivel
 * @var array|null $old        lo escrito antes de un rechazo del servidor
 *                             (notas por criterio, conclusion, motivo)
 */
$esPrimaria = ($info['nivel_codigo'] ?? '') === 'prim';
$old        = is_array($old ?? null) ? $old : null;
$oldNotas   = is_array($old['notas'] ?? null) ? $old['notas'] : [];

$nombreComp = $meta['nombre_corto'] ?: $meta['competencia_nombre'];
if (($meta['area_tipo'] ?? '') === 'con_subareas' && !empty($meta['subarea_nombre'])) {
    $nombreComp = $meta['subarea_nombre'] . ' — ' . $nombreComp;
}
$notaActual    = $meta['nota_actual'] !== null ? (int) $meta['nota_actual'] : null;
$literalActual = $notaActual !== null ? nota_a_literal($notaActual) : '—';
$obligatoriaTxt = $esPrimaria
    ? 'Obligatoria cuando el resultado sea B o C.'
    : 'Obligatoria cuando el resultado sea C.';
$volver = url('rectificaciones/matricula/' . (int) $info['matricula_id']);
?>

<div class="page-header">
    <a href="<?= $volver ?>" class="btn btn--secondary btn--sm">← Cancelar</a>
    <div>
        <h1 class="page-title">Rectificar calificación</h1>
        <p class="page-subtitle">
            <?= e($info['nombre_completo']) ?> ·
            <?= e($info['grado_nombre']) ?> "<?= e($info['seccion_nombre']) ?>"
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <div class="info-grid">
            <div class="info-item"><span class="info-item__label">Área</span><span class="info-item__value"><?= e($meta['nombre_boleta'] ?: $meta['area_nombre'] ?: '—') ?></span></div>
            <div class="info-item"><span class="info-item__label">Competencia</span><span class="info-item__value"><?= e($nombreComp) ?></span></div>
            <div class="info-item"><span class="info-item__label">Bimestre</span><span class="info-item__value"><?= e($meta['periodo_nombre'] ?? '—') ?></span></div>
            <div class="info-item">
                <span class="info-item__label">Nota actual</span>
                <span class="info-item__value">
                    <?php if ($esPrimaria): ?>
                        <?= e($literalActual) ?>
                    <?php else: ?>
                        <?= fmt_nota($notaActual) ?> · <?= e($literalActual) ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="<?= url('rectificaciones/guardar') ?>" class="card"
      id="rectEditarForm"
      data-nota-min-ad="<?= NOTA_MIN_AD ?>"
      data-nota-min-a="<?= NOTA_MIN_A ?>"
      data-nota-min-b="<?= NOTA_MIN_B ?>"
      data-literales-conclusion="<?= e(implode(',', $literalesConclusion ?? [])) ?>">
    <div class="card__body">
        <?= csrf_field() ?>
        <input type="hidden" name="matricula_id"   value="<?= (int) $info['matricula_id'] ?>">
        <input type="hidden" name="carga_id"        value="<?= (int) $cargaId ?>">
        <input type="hidden" name="competencia_id"  value="<?= (int) $competenciaId ?>">
        <input type="hidden" name="periodo_id"      value="<?= (int) $periodoId ?>">

        <p class="form-section-title">Notas por criterio (0–20)</p>
        <p class="text-sm text-muted mb-md">
            La nota final de la competencia se recalcula como el promedio de los criterios.
        </p>

        <!-- Promedio en vivo (numeral + literal): se actualiza al editar las notas -->
        <div class="rect-preview" id="rectPreview" data-rect-literal="" aria-live="polite">
            <span class="rect-preview__label">Promedio resultante</span>
            <span class="rect-preview__valor">
                <span class="rect-preview__num" data-rect-num>—</span>
                <span class="rect-preview__sep" aria-hidden="true">·</span>
                <span class="rect-preview__lit" data-rect-lit>—</span>
            </span>
        </div>

        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Criterio</th>
                        <th class="text-center">Nota (0–20)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($criterios as $cr):
                        // Tras un rechazo manda lo que se ESCRIBIÓ, no lo de la BD:
                        // repintar la BD hacía guardar la nota vieja sin aviso.
                        $valorCrit = array_key_exists((int) $cr['id'], $oldNotas)
                            ? trim((string) $oldNotas[(int) $cr['id']])
                            : ($cr['nota'] !== null ? (string) (int) $cr['nota'] : '');
                    ?>
                    <tr>
                        <td>
                            <div class="rect-comp__nombre"><?= e($cr['nombre']) ?></div>
                            <?php if (!empty($cr['descripcion'])): ?>
                                <div class="rect-criterio__desc text-sm text-muted"><?= e($cr['descripcion']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <input type="number" min="0" max="20" step="1"
                                   class="form-input rect-nota-input"
                                   name="notas[<?= (int) $cr['id'] ?>]"
                                   value="<?= e($valorCrit) ?>"
                                   inputmode="numeric">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-group mt-md">
            <label class="form-label" for="conclusion">
                Conclusión descriptiva
                <span class="text-danger" data-rect-concl-obligatoria hidden>*</span>
            </label>
            <textarea id="conclusion" name="conclusion" class="form-input" rows="3"
                      placeholder="Conclusión descriptiva (opcional según el resultado)."><?= e($old !== null ? (string) ($old['conclusion'] ?? '') : ($meta['conclusion_actual'] ?? '')) ?></textarea>
            <p class="text-sm text-muted"><?= $obligatoriaTxt ?></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="motivo">Motivo de la rectificación <span class="text-danger">*</span></label>
            <textarea id="motivo" name="motivo" class="form-input" rows="3" required
                      placeholder="Fundamenta el porqué de la corrección."><?= e((string) ($old['motivo'] ?? '')) ?></textarea>
        </div>

        <div class="btn-group form-actions">
            <a href="<?= $volver ?>" class="btn btn--secondary">Cancelar</a>
            <button type="submit" class="btn btn--primary">Aplicar rectificación</button>
        </div>
    </div>
</form>
