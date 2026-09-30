<?php
/**
 * Motivos de justificación de la asistencia (29/09/2026, migración 069) —
 * `Admin\AsistenciaMotivoController::index`. Admin y RA editan; Dirección ve
 * en solo lectura. Reusa las clases de la pantalla de criterios de conducta
 * (`_criterios-conducta.scss`): es el mismo tipo de catálogo.
 *
 * @var array $motivos  AsistenciaMotivoModel::listar() (vigentes primero, luego retirados)
 * @var bool  $editor   admin o RA
 * @var int   $editarId motivo en edición (0 = ninguno)
 */
$vigentes  = array_values(array_filter($motivos, static fn($m) => $m['retirado_en'] === null));
$retirados = array_values(array_filter($motivos, static fn($m) => $m['retirado_en'] !== null));
$urlBase   = url('admin/asistencia/motivos');
?>

<div class="page-header">
    <?php if ($editor): ?>
        <a href="<?= url('admin/asistencia') ?>" class="btn btn--secondary btn--sm">← Asistencia</a>
    <?php else: ?>
        <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Inicio</a>
    <?php endif; ?>
    <div>
        <h1 class="page-title">Motivos de justificación</h1>
        <p class="page-subtitle">
            Toda falta o tardanza justificada (FJ, TJ) lleva uno de estos motivos: sin él no se guarda.
            Se guardan para los reportes.
        </p>
    </div>
    <?php // Dirección llega aquí desde su card; los días no lectivos van al lado. ?>
    <a href="<?= url('admin/asistencia/no-lectivos') ?>" class="btn btn--secondary btn--sm">Días no lectivos</a>
</div>

<?php if ($vigentes === []): ?>
    <div class="empty-state"><p>No hay motivos vigentes.</p></div>
<?php else: ?>
    <div class="card">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas criterios-tabla">
                <thead>
                    <tr>
                        <th>Motivo</th>
                        <th class="criterios-tabla__nivel">Usos</th>
                        <?php if ($editor): ?><th class="text-right">Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vigentes as $i => $m): $mid = (int) $m['id']; ?>
                        <?php if ($editor && $editarId === $mid): ?>
                            <tr class="criterios-tabla__editando">
                                <td colspan="3">
                                    <form method="POST" action="<?= url('admin/asistencia/motivos/' . $mid . '/editar') ?>"
                                          class="criterios-form">
                                        <?= csrf_field() ?>
                                        <label class="form-label" for="nombre-<?= $mid ?>">Motivo</label>
                                        <input type="text" id="nombre-<?= $mid ?>" name="nombre" class="form-input"
                                               value="<?= e($m['nombre']) ?>"
                                               minlength="<?= App\Models\AsistenciaMotivoModel::NOMBRE_MIN ?>"
                                               maxlength="<?= App\Models\AsistenciaMotivoModel::NOMBRE_MAX ?>" required>
                                        <?php if ((int) $m['usos'] > 0): ?>
                                            <p class="text-sm text-muted">
                                                Ya lo usan <?= (int) $m['usos'] ?> justificación(es): el nuevo nombre también
                                                se verá en ellas.
                                            </p>
                                        <?php endif; ?>
                                        <div class="criterios-form__acciones">
                                            <button type="submit" class="btn btn--primary btn--sm">Guardar</button>
                                            <a href="<?= $urlBase ?>" class="btn btn--secondary btn--sm">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <?= e($m['nombre']) ?>
                                    <?php if (!empty($m['modificado_en'])): ?>
                                        <span class="criterios-tabla__traza">
                                            Corregido<?= $m['modificado_por_nombre'] !== '' ? ' por ' . e($m['modificado_por_nombre']) : '' ?>
                                            el <?= e(date('d/m/Y', strtotime($m['modificado_en']))) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="criterios-tabla__nivel"><?= (int) $m['usos'] ?></td>
                                <?php if ($editor): ?>
                                    <td class="criterios-tabla__acciones">
                                        <a href="<?= $urlBase ?>?editar=<?= $mid ?>" class="btn btn--secondary btn--sm">Editar</a>
                                        <?php foreach (['arriba' => ['↑', 'Subir', $i > 0], 'abajo' => ['↓', 'Bajar', $i < count($vigentes) - 1]] as $dir => [$flecha, $rotulo, $puede]): ?>
                                            <?php if ($puede): ?>
                                                <form method="POST" action="<?= url('admin/asistencia/motivos/' . $mid . '/mover') ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="direccion" value="<?= $dir ?>">
                                                    <button type="submit" class="btn btn--secondary btn--sm" aria-label="<?= $rotulo ?> <?= e($m['nombre']) ?>" title="<?= $rotulo ?>"><?= $flecha ?></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (count($vigentes) > 1): ?>
                                            <form method="POST" action="<?= url('admin/asistencia/motivos/' . $mid . '/retirar') ?>"
                                                  data-confirm="¿Retirar «<?= e($m['nombre']) ?>»? Dejará de ofrecerse; las justificaciones que ya lo usan lo conservan.">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn--danger btn--sm">Retirar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($editor): ?>
    <form method="POST" action="<?= url('admin/asistencia/motivos/crear') ?>" class="card criterios-nuevo">
        <div class="card__body">
            <?= csrf_field() ?>
            <p class="form-section-title">Agregar motivo</p>
            <label class="form-label" for="nombre-nuevo">Motivo <span class="text-danger">*</span></label>
            <input type="text" id="nombre-nuevo" name="nombre" class="form-input"
                   minlength="<?= App\Models\AsistenciaMotivoModel::NOMBRE_MIN ?>"
                   maxlength="<?= App\Models\AsistenciaMotivoModel::NOMBRE_MAX ?>" required>
            <div class="criterios-form__acciones">
                <button type="submit" class="btn btn--primary">Agregar</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php // «Datos a la vista»: los retirados se muestran si existen (no detrás de un <details>). ?>
<?php if ($retirados !== []): ?>
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Retirados (ya no se ofrecen; sus registros se conservan)</p>
            <ul class="text-sm">
                <?php foreach ($retirados as $m): ?>
                    <li>
                        <?= e($m['nombre']) ?> — <?= (int) $m['usos'] ?> uso(s),
                        retirado el <?= e(date('d/m/Y', strtotime($m['retirado_en']))) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
