<?php
/**
 * Motivos de NO ratificación, por año (migración 080, 14/10/2026) —
 * `Admin\RatificacionController::motivos`. Ver docs/modulos/ratificaciones.md.
 *
 * Cada motivo lleva el párrafo oficial que redacta Dirección. Admin, RA y las
 * secretarías editan; Dirección ve en solo lectura. Reusa las clases del
 * catálogo de motivos de asistencia (`_criterios-conducta.scss`).
 *
 * @var array      $anios         [{ id, anio, estado }]
 * @var array|null $anio          año elegido
 * @var array      $motivos       RatificacionMotivoModel::listar() (vigentes primero)
 * @var int        $editarId      motivo en edición (0 = ninguno)
 * @var bool       $puedeEscribir
 */
use App\Models\RatificacionMotivoModel as Motivo;

$anioId    = $anio !== null ? (int) $anio['id'] : 0;
$urlBase   = url('admin/ratificaciones/motivos');
$vigentes  = array_values(array_filter($motivos, static fn($m) => (int) $m['activo'] === 1));
$retirados = array_values(array_filter($motivos, static fn($m) => (int) $m['activo'] === 0));
?>

<div class="page-header">
    <a href="<?= url('admin/ratificaciones') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">← Ratificación</a>
    <div>
        <h1 class="page-title">Motivos de no ratificación</h1>
        <p class="page-subtitle">
            <?= $anio !== null ? e((string) $anio['anio']) . ' · ' : '' ?>
            El texto oficial de cada motivo es el que se imprime en el documento del estudiante.
        </p>
    </div>
</div>

<?php if ($anio === null): ?>
    <div class="empty-state"><p>No hay años académicos registrados.</p></div>
<?php else: ?>

<?php if (count($anios) > 1): ?>
    <form method="GET" action="<?= $urlBase ?>" class="card mb-md">
        <div class="card__body form-inline">
            <label class="form-label" for="anio_id">Año</label>
            <select id="anio_id" name="anio_id" class="form-select">
                <?php foreach ($anios as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $anioId ? ' selected' : '' ?>><?= e((string) $a['anio']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--secondary btn--sm">Ver</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($vigentes === []): ?>
    <div class="empty-state"><p>No hay motivos vigentes para <?= e((string) $anio['anio']) ?>.</p></div>
<?php else: ?>
    <div class="card">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas criterios-tabla">
                <thead>
                    <tr>
                        <th>Motivo</th>
                        <th class="criterios-tabla__nivel">Usos</th>
                        <?php if ($puedeEscribir): ?><th class="text-right">Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vigentes as $m): $mid = (int) $m['id']; ?>
                        <?php if ($puedeEscribir && $editarId === $mid): ?>
                            <tr class="criterios-tabla__editando">
                                <td colspan="3">
                                    <form method="POST" action="<?= url('admin/ratificaciones/motivos/' . $mid . '/editar') ?>"
                                          class="criterios-form">
                                        <?= csrf_field() ?>
                                        <label class="form-label" for="nombre-<?= $mid ?>">Motivo</label>
                                        <input type="text" id="nombre-<?= $mid ?>" name="nombre" class="form-input"
                                               value="<?= e($m['nombre']) ?>"
                                               minlength="<?= Motivo::NOMBRE_MIN ?>" maxlength="<?= Motivo::NOMBRE_MAX ?>" required>
                                        <label class="form-label" for="texto-<?= $mid ?>">Texto oficial</label>
                                        <textarea id="texto-<?= $mid ?>" name="texto_oficial" class="form-input" rows="6"
                                                  minlength="<?= Motivo::TEXTO_MIN ?>" maxlength="<?= Motivo::TEXTO_MAX ?>" required><?= e($m['texto_oficial']) ?></textarea>
                                        <?php if ((int) $m['usos'] > 0): ?>
                                            <p class="text-sm text-muted">
                                                Lo tienen <?= (int) $m['usos'] ?> estudiante(s). Los documentos ya emitidos conservan el texto anterior.
                                            </p>
                                        <?php endif; ?>
                                        <div class="criterios-form__acciones">
                                            <button type="submit" class="btn btn--primary btn--sm">Guardar</button>
                                            <a href="<?= $urlBase ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <strong><?= e($m['nombre']) ?></strong>
                                    <div class="ratif-texto-oficial"><?= e($m['texto_oficial']) ?></div>
                                </td>
                                <td class="criterios-tabla__nivel"><?= (int) $m['usos'] ?></td>
                                <?php if ($puedeEscribir): ?>
                                    <td class="criterios-tabla__acciones">
                                        <a href="<?= $urlBase ?>?anio_id=<?= $anioId ?>&amp;editar=<?= $mid ?>" class="btn btn--secondary btn--sm">Editar</a>
                                        <form method="POST" action="<?= url('admin/ratificaciones/motivos/' . $mid . '/retirar') ?>"
                                              data-confirm="¿Retirar «<?= e($m['nombre']) ?>»? Dejará de ofrecerse; los estudiantes que ya lo tienen lo conservan.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn--danger btn--sm">Retirar</button>
                                        </form>
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

<?php if ($puedeEscribir): ?>
    <form method="POST" action="<?= url('admin/ratificaciones/motivos/crear') ?>" class="card criterios-nuevo">
        <div class="card__body">
            <?= csrf_field() ?>
            <input type="hidden" name="anio_id" value="<?= $anioId ?>">
            <p class="form-section-title">Agregar motivo para <?= e((string) $anio['anio']) ?></p>
            <label class="form-label" for="nombre-nuevo">Motivo <span class="text-danger">*</span></label>
            <input type="text" id="nombre-nuevo" name="nombre" class="form-input"
                   minlength="<?= Motivo::NOMBRE_MIN ?>" maxlength="<?= Motivo::NOMBRE_MAX ?>" required>
            <label class="form-label" for="texto-nuevo">Texto oficial <span class="text-danger">*</span></label>
            <textarea id="texto-nuevo" name="texto_oficial" class="form-input" rows="6"
                      minlength="<?= Motivo::TEXTO_MIN ?>" maxlength="<?= Motivo::TEXTO_MAX ?>" required></textarea>
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
            <p class="form-section-title">Retirados (ya no se ofrecen; los estudiantes que los tienen los conservan)</p>
            <ul class="text-sm">
                <?php foreach ($retirados as $m): ?>
                    <li>
                        <?= e($m['nombre']) ?> — <?= (int) $m['usos'] ?> uso(s)
                        <?php if ($puedeEscribir): ?>
                            <form method="POST" action="<?= url('admin/ratificaciones/motivos/' . (int) $m['id'] . '/reactivar') ?>" class="form-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn--secondary btn--sm">Reactivar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>
