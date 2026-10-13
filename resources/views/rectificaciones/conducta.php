<?php
/**
 * Rectificación de CONDUCTA (F5, 13/10/2026): los Sí/No oficiales de un
 * estudiante en un bimestre. La nota final la recalcula el sistema con la del
 * tutor; aquí no se escribe.
 * @var array       $info        datos del estudiante
 * @var array       $periodo     fila de `periodos`
 * @var array       $criterios   ConductaModel::getCriterios (id, codigo, texto)
 * @var array       $respuestas  [criterio_id => 0|1] oficiales
 * @var string|null $literal     literal actual
 * @var bool        $tutorCerro  el tutor ya cerró la etapa 2
 * @var array|null  $old         lo enviado antes de un rechazo (resp, motivo)
 */
$old     = is_array($old ?? null) ? $old : null;
$oldResp = is_array($old['resp'] ?? null) ? $old['resp'] : [];
$volver  = url('rectificaciones/matricula/' . (int) $info['matricula_id']);
?>

<div class="page-header">
    <a href="<?= $volver ?>" class="btn btn--secondary btn--sm">← Cancelar</a>
    <div>
        <h1 class="page-title">Rectificar conducta</h1>
        <p class="page-subtitle">
            <?= e($info['nombre_completo']) ?> ·
            <?= e($info['grado_nombre']) ?> "<?= e($info['seccion_nombre']) ?>"
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <div class="info-grid">
            <div class="info-item"><span class="info-item__label">Bimestre</span><span class="info-item__value"><?= e($periodo['nombre_display'] ?? '—') ?></span></div>
            <div class="info-item"><span class="info-item__label">Literal actual</span><span class="info-item__value"><?= e($literal ?? '—') ?></span></div>
            <div class="info-item">
                <span class="info-item__label">Cierre del tutor</span>
                <span class="info-item__value"><?= $tutorCerro ? 'Cerrado' : 'Pendiente' ?></span>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="<?= url('rectificaciones/conducta/guardar') ?>" class="card">
    <div class="card__body">
        <?= csrf_field() ?>
        <input type="hidden" name="matricula_id" value="<?= (int) $info['matricula_id'] ?>">
        <input type="hidden" name="periodo_id"   value="<?= (int) $periodo['id'] ?>">

        <p class="form-section-title">Criterios de conducta</p>
        <p class="text-sm text-muted mb-md">
            Cambia solo los criterios que corresponda. El literal se recalcula con la nota del tutor.
        </p>

        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Criterio</th>
                        <th class="text-center">Registrado</th>
                        <th class="text-center">Rectificación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($criterios as $k):
                        $cid = (int) $k['id'];
                        if (!array_key_exists($cid, $respuestas)) {
                            continue; // sin respuesta oficial: no hay nada que rectificar
                        }
                        $actual = (int) $respuestas[$cid];
                        $valor  = array_key_exists($cid, $oldResp) ? (string) $oldResp[$cid] : (string) $actual;
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($k['codigo']) ?></strong>
                            <span class="rect-comp__nombre"><?= e($k['texto']) ?></span>
                        </td>
                        <td class="text-center"><?= $actual === 1 ? 'Sí' : 'No' ?></td>
                        <td class="text-center">
                            <div class="rect-sino" role="radiogroup" aria-label="<?= e($k['codigo']) ?>">
                                <label class="rect-sino__op">
                                    <input type="radio" name="resp[<?= $cid ?>]" value="1" <?= $valor === '1' ? 'checked' : '' ?> required>
                                    <span>Sí</span>
                                </label>
                                <label class="rect-sino__op">
                                    <input type="radio" name="resp[<?= $cid ?>]" value="0" <?= $valor === '0' ? 'checked' : '' ?>>
                                    <span>No</span>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-group mt-md">
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
