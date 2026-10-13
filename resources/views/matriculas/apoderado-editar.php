<?php
/**
 * Editar un apoderado vinculado (12/10/2026).
 * @var array $matricula
 * @var array $vinculo           ApoderadoModel::getVinculo()
 * @var array $tiposVinculo
 * @var string[] $otrosEstudiantes
 * @var bool  $puedeEditarPersona
 */
$mid     = (int) $matricula['id'];
$vid     = (int) $vinculo['vinculo_id'];
$baseUrl = 'matriculas/' . $mid . '/apoderado/' . $vid;
$dniFijo = (int) $vinculo['tiene_cuenta'] === 1;
?>

<div class="page-header">
    <a href="<?= url('matriculas/' . $mid . '/apoderado') ?>" class="btn btn--secondary btn--sm">← Apoderados</a>
    <div>
        <h1 class="page-title">Editar apoderado</h1>
        <p class="page-subtitle"><?= e($vinculo['nombre_completo']) ?> · apoderado de <?= e($matricula['nombre_completo']) ?></p>
    </div>
</div>

<!-- Vínculo: solo afecta a este estudiante -->
<div class="card mb-md">
    <div class="card__body">
        <form method="POST" action="<?= url($baseUrl . '/vinculo') ?>">
            <?= csrf_field() ?>
            <p class="form-section-title">Vínculo con <?= e($matricula['nombre_completo']) ?></p>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="tipo_vinculo">Tipo de vínculo <span class="text-danger">*</span></label>
                    <select id="tipo_vinculo" name="tipo_vinculo" class="form-input" required>
                        <?php foreach ($tiposVinculo as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $val === $vinculo['tipo_vinculo'] ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <span class="form-label">Responsabilidad</span>
                    <span class="pass-hint"><?= $vinculo['es_responsable'] ? 'Responsable de matrícula' : 'Apoderado adicional' ?></span>
                </div>
            </div>
            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Guardar vínculo</button>
            </div>
        </form>
    </div>
</div>

<!-- Persona: ficha compartida con sus otros estudiantes -->
<?php if ($puedeEditarPersona): ?>
<div class="card">
    <div class="card__body">
        <form method="POST" action="<?= url($baseUrl . '/persona') ?>"<?php if (!empty($otrosEstudiantes)): ?>
              data-confirm-cambios="¿Guardar estos cambios en los datos de <?= e($vinculo['nombre_completo']) ?>?"
              data-confirm-nota="También se actualizarán para: <?= e(implode(', ', $otrosEstudiantes)) ?>."<?php endif; ?>>
            <?= csrf_field() ?>
            <p class="form-section-title">Datos del apoderado</p>
            <?php if (!empty($otrosEstudiantes)): ?>
                <div class="flash flash--warning">
                    Estos datos también se actualizarán para:
                    <strong><?= e(implode(', ', $otrosEstudiantes)) ?></strong>.
                </div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="ap_dni">DNI <span class="text-danger">*</span></label>
                    <input type="text" id="ap_dni" name="dni" class="form-input" data-campo="DNI" maxlength="8" pattern="\d{8}"
                           inputmode="numeric" required value="<?= e($vinculo['dni']) ?>"<?= $dniFijo ? ' readonly' : '' ?>>
                    <?php if ($dniFijo): ?>
                        <span class="pass-hint">El DNI de esta persona no se puede modificar desde matrículas.</span>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="apellido_paterno">Apellido paterno <span class="text-danger">*</span></label>
                    <input type="text" id="apellido_paterno" name="apellido_paterno" class="form-input" data-campo="Apellido paterno" data-mayus maxlength="60"
                           required value="<?= e($vinculo['apellido_paterno']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="apellido_materno">Apellido materno <span class="text-danger">*</span></label>
                    <input type="text" id="apellido_materno" name="apellido_materno" class="form-input" data-campo="Apellido materno" data-mayus maxlength="60"
                           required value="<?= e($vinculo['apellido_materno']) ?>">
                </div>
                <div class="form-group form-group--full">
                    <label class="form-label" for="nombres">Nombres <span class="text-danger">*</span></label>
                    <input type="text" id="nombres" name="nombres" class="form-input" data-campo="Nombres" data-mayus maxlength="100"
                           required value="<?= e($vinculo['nombres']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" class="form-input" data-campo="Teléfono" maxlength="15"
                           value="<?= e((string) ($vinculo['telefono'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="correo">Correo (opcional)</label>
                    <input type="email" id="correo" name="correo" class="form-input" data-campo="Correo" maxlength="120"
                           value="<?= e((string) ($vinculo['correo'] ?? '')) ?>">
                </div>
            </div>
            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Guardar datos</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
