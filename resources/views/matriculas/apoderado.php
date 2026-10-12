<?php
/**
 * @var int   $paso
 * @var array $matricula
 * @var array $tiposVinculo
 * @var array $vinculos
 * @var string $dni
 * @var array|null $apoderado
 */
$pasos = [1 => 'Estudiante', 2 => 'Apoderado', 3 => 'Documentos'];
$mid   = (int) $matricula['id'];

// Otros estudiantes del apoderado encontrado (sin contar al de esta matrícula):
// si los hay, el vínculo exige confirmación (también en el servidor).
$otrosEstudiantes = [];
foreach (($apoderado['vinculos'] ?? []) as $vin) {
    if ((int) $vin['estudiante_id'] !== (int) $matricula['estudiante_id']) {
        $otrosEstudiantes[(int) $vin['estudiante_id']] = $vin['estudiante_nombre'];
    }
}
?>

<div class="page-header">
    <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary btn--sm">← Ver matrícula</a>
    <div>
        <h1 class="page-title">Apoderado</h1>
        <p class="page-subtitle"><?= e($matricula['nombre_completo']) ?> · <?= e(($matricula['grado_nombre'] ?? '') . ' ' . ($matricula['seccion_nombre'] ?? '')) ?></p>
    </div>
</div>

<div class="wizard-steps">
    <?php foreach ($pasos as $n => $label): ?>
        <div class="wizard-steps__item <?= $n === $paso ? 'wizard-steps__item--activo' : ($n < $paso ? 'wizard-steps__item--completado' : '') ?>">
            <span class="wizard-steps__num"><?= $n < $paso ? '✓' : $n ?></span>
            <span><?= $n ?>. <?= $label ?></span>
        </div>
        <?php if ($n < 3): ?><span class="wizard-steps__sep"></span><?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Apoderados ya vinculados -->
<?php if (!empty($vinculos)): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Apoderados vinculados (<?= count($vinculos) ?>/3)</p>
        <?php foreach ($vinculos as $v): ?>
            <div class="apoderado-card <?= $v['es_responsable'] ? 'apoderado-card--responsable' : '' ?>">
                <div class="apoderado-card__head">
                    <span class="apoderado-card__nombre"><?= e($v['nombre_completo']) ?></span>
                    <span class="matricula-badge matricula-badge--continuador">
                        <?= e($tiposVinculo[$v['tipo_vinculo']] ?? $v['tipo_vinculo']) ?>
                    </span>
                </div>
                <div class="apoderado-card__meta">
                    DNI <?= e($v['dni']) ?>
                    <?= $v['telefono'] ? ' · Tel. ' . e($v['telefono']) : '' ?>
                    <?= $v['es_responsable'] ? ' · Responsable de matrícula' : '' ?>
                </div>
                <?php $vid = (int) $v['vinculo_id']; ?>
                <div class="apoderado-card__acciones">
                    <a href="<?= url('matriculas/' . $mid . '/apoderado/' . $vid . '/editar') ?>" class="btn btn--secondary btn--sm">Editar</a>
                    <?php // El responsable no se quita ni se «hace responsable»: se traslada desde otro apoderado. ?>
                    <?php if (!$v['es_responsable']): ?>
                        <form method="POST" action="<?= url('matriculas/' . $mid . '/apoderado/' . $vid . '/responsable') ?>"
                              data-confirm="¿Hacer a <?= e($v['nombre_completo']) ?> responsable de matrícula? El responsable actual pasará a ser apoderado adicional.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--secondary btn--sm">Hacer responsable</button>
                        </form>
                        <form method="POST" action="<?= url('matriculas/' . $mid . '/apoderado/' . $vid . '/quitar') ?>"
                              data-confirm="¿Quitar a <?= e($v['nombre_completo']) ?> como apoderado de este estudiante? Sus datos y sus vínculos con otros estudiantes se conservan.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn--danger btn--sm">Quitar</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Buscar apoderado por DNI -->
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Buscar apoderado por DNI</p>
        <form method="GET" action="<?= url('matriculas/' . $mid . '/apoderado') ?>">
            <div class="busqueda-dni">
                <input type="text" name="dni" class="form-input" maxlength="8" pattern="\d{8}"
                       inputmode="numeric" placeholder="DNI del apoderado" value="<?= e($dni) ?>" required>
                <button type="submit" class="btn btn--primary">Buscar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($dni !== '' && !$apoderado): ?>
    <div class="flash flash--warning">No se encontró un apoderado con ese DNI. Complétalo abajo para crearlo.</div>
<?php endif; ?>

<!-- Vincular apoderado -->
<div class="card">
    <div class="card__body">
        <form method="POST" action="<?= url('matriculas/' . $mid . '/apoderado') ?>"<?= empty($otrosEstudiantes) ? ' novalidate' : '' ?>>
            <?= csrf_field() ?>
            <?php if ($apoderado): ?>
                <input type="hidden" name="apoderado_id" value="<?= (int) $apoderado['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <?php if ($apoderado): ?>
                    <p class="form-section-title">Apoderado encontrado</p>
                    <div class="form-group form-group--full">
                        <label class="form-label">Nombre</label>
                        <input type="text" class="form-input" value="<?= e($apoderado['nombre_completo']) ?>" readonly>
                    </div>
                    <?php if (!empty($otrosEstudiantes)): ?>
                        <div class="form-group form-group--full">
                            <div class="flash flash--warning">
                                Este apoderado ya está vinculado a:
                                <strong><?= e(implode(', ', $otrosEstudiantes)) ?></strong>.
                                Verifica que sea la persona correcta antes de vincularla.
                            </div>
                            <label class="form-check">
                                <input type="checkbox" name="confirmar_vinculo" value="1" required>
                                Confirmo que <?= e($apoderado['nombre_completo']) ?> es apoderado(a) de
                                <?= e($matricula['nombre_completo']) ?>, además de los estudiantes indicados.
                            </label>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="form-section-title">Datos del apoderado nuevo</p>
                    <div class="form-group">
                        <label class="form-label" for="ap_dni">DNI <span class="text-danger">*</span></label>
                        <input type="text" id="ap_dni" name="dni" class="form-input" maxlength="8" pattern="\d{8}"
                               inputmode="numeric" value="<?= e($dni) ?>" placeholder="12345678" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="apellido_paterno">Apellido paterno <span class="text-danger">*</span></label>
                        <input type="text" id="apellido_paterno" name="apellido_paterno" class="form-input" maxlength="60" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="apellido_materno">Apellido materno <span class="text-danger">*</span></label>
                        <input type="text" id="apellido_materno" name="apellido_materno" class="form-input" maxlength="60" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="nombres">Nombres <span class="text-danger">*</span></label>
                        <input type="text" id="nombres" name="nombres" class="form-input" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="telefono">Teléfono</label>
                        <input type="tel" id="telefono" name="telefono" class="form-input" maxlength="15">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="correo">Correo (opcional)</label>
                        <input type="email" id="correo" name="correo" class="form-input" maxlength="120">
                    </div>
                <?php endif; ?>

                <p class="form-section-title">Vínculo</p>
                <div class="form-group">
                    <label class="form-label" for="tipo_vinculo">Tipo de vínculo <span class="text-danger">*</span></label>
                    <select id="tipo_vinculo" name="tipo_vinculo" class="form-input" required>
                        <?php foreach ($tiposVinculo as $val => $label): ?>
                            <option value="<?= e($val) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Responsabilidad</label>
                    <label class="form-check">
                        <input type="checkbox" name="es_responsable" value="1"> Es responsable de matrícula
                    </label>
                </div>
            </div>

            <div class="btn-group form-actions">
                <button type="submit" name="accion" value="continuar" class="btn btn--primary">Vincular y continuar →</button>
                <button type="submit" name="accion" value="agregar_otro" class="btn btn--secondary">Vincular y agregar otro</button>
            </div>
        </form>
    </div>
</div>
