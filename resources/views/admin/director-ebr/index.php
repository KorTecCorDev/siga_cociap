<?php
/**
 * @var array  $anios        [{id, anio, estado, director_actual, director_usuario_id,
 *                              historial_id_vigente, firma_path_vigente, sello_path_vigente}]
 * @var array  $historiales  [anio_id => [{id, nombre_completo, desde, hasta, firma_path,
 *                              sello_path, asignado_por_nombre, asignado_en}]]
 * @var array  $usuarios     [{id, nombre_completo}]
 */
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <h1 class="page-title">Director EBR</h1>
</div>

<?php if (empty($usuarios)): ?>
    <div class="flash flash--warning">
        No hay usuarios con rol <strong>Director EBR</strong> activos.
        <?php if (has_role('admin')): ?>
            Crea uno desde <a href="<?= url('admin/usuarios') ?>">Gestión de Usuarios</a>
        <?php else: ?>
            El administrador debe crearlo en Gestión de Usuarios
        <?php endif; ?>
        antes de hacer una asignación.
    </div>
<?php endif; ?>

<?php if (empty($anios)): ?>
    <div class="empty-state"><p>No hay años académicos registrados.</p></div>
<?php else: ?>

    <?php foreach ($anios as $anio): ?>
        <?php
        $historial     = $historiales[$anio['id']] ?? [];
        $tieneActual   = !empty($anio['director_actual']);
        $historialId   = $anio['historial_id_vigente'];
        $firmavigente  = $anio['firma_path_vigente'];
        $sellovigente  = $anio['sello_path_vigente'];
        $estadoBadge   = match($anio['estado']) {
            'activo'  => 'badge--activo',
            'cerrado' => 'badge--error',
            default   => 'badge--info',
        };
        $estadoLabel = match($anio['estado']) {
            'activo'  => 'Activo',
            'cerrado' => 'Cerrado',
            default   => 'Planificado',
        };
        ?>

        <div class="card deb-card">

            <div class="card__header deb-card__header">
                <div class="deb-card__titulo">
                    <h2 class="card__title">Año académico <?= e($anio['anio']) ?></h2>
                    <span class="badge <?= $estadoBadge ?>"><?= $estadoLabel ?></span>
                </div>
                <div class="deb-card__actual">
                    <?php if ($tieneActual): ?>
                        <span class="deb-vigente-label">Director EBR vigente:</span>
                        <span class="deb-vigente-nombre"><?= e($anio['director_actual']) ?></span>
                    <?php else: ?>
                        <span class="deb-sin-asignar">Sin Director EBR asignado</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card__body deb-card__body">

                <!-- ── Imágenes del director vigente ─────────────── -->
                <?php if ($tieneActual && ($firmavigente || $sellovigente)): ?>
                    <div class="deb-imagenes-preview">
                        <?php if ($firmavigente): ?>
                            <div class="deb-imagen-bloque">
                                <span class="deb-imagen-label">Firma actual</span>
                                <div class="deb-imagen-wrap deb-imagen-wrap--firma">
                                    <img src="<?= url($firmavigente) ?>"
                                         alt="Firma Director EBR"
                                         class="deb-imagen-preview">
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($sellovigente): ?>
                            <div class="deb-imagen-bloque">
                                <span class="deb-imagen-label">Sello actual</span>
                                <div class="deb-imagen-wrap deb-imagen-wrap--sello">
                                    <img src="<?= url($sellovigente) ?>"
                                         alt="Sello Director EBR"
                                         class="deb-imagen-preview">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- ── Actualizar imágenes del director vigente ───── -->
                <?php if ($tieneActual && $historialId): ?>
                    <form method="POST"
                          action="<?= url('admin/director-ebr/' . $historialId . '/imagenes') ?>"
                          enctype="multipart/form-data"
                          class="deb-form deb-form--imagenes">
                        <?= csrf_field() ?>

                        <p class="deb-form__titulo-sec">
                            Actualizar imágenes del director vigente
                        </p>

                        <div class="deb-form__fields deb-form__fields--imagenes">
                            <div class="form-group">
                                <label class="form-label">
                                    Firma PNG
                                    <span class="form-hint">Max. 2 MB · fondo transparente recomendado</span>
                                </label>
                                <input type="file"
                                       name="firma"
                                       accept="image/png"
                                       class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">
                                    Sello PNG
                                    <span class="form-hint">Max. 2 MB · fondo transparente recomendado</span>
                                </label>
                                <input type="file"
                                       name="sello"
                                       accept="image/png"
                                       class="form-input">
                            </div>
                            <div class="deb-form__action">
                                <button type="submit" class="btn btn--secondary">
                                    Guardar imágenes
                                </button>
                            </div>
                        </div>

                    </form>
                <?php endif; ?>

                <!-- ── Asignar nuevo director ─────────────────────── -->
                <?php if (!empty($usuarios)): ?>
                    <form method="POST"
                          action="<?= url('admin/director-ebr/' . $anio['id'] . '/asignar') ?>"
                          enctype="multipart/form-data"
                          class="deb-form">
                        <?= csrf_field() ?>

                        <p class="deb-form__titulo-sec">
                            <?= $tieneActual ? 'Cambiar Director EBR' : 'Asignar Director EBR' ?>
                        </p>

                        <div class="deb-form__fields">
                            <div class="form-group">
                                <label class="form-label">Usuario</label>
                                <select name="usuario_id" class="form-input deb-select" required>
                                    <option value="">— Seleccionar —</option>
                                    <?php foreach ($usuarios as $u): ?>
                                        <option value="<?= $u['id'] ?>"
                                            <?= ($anio['director_usuario_id'] == $u['id']) ? 'selected' : '' ?>>
                                            <?= e($u['nombre_completo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Vigente desde</label>
                                <input type="date" name="desde" class="form-input"
                                       value="<?= date('Y-m-d') ?>" required>
                                <span class="form-hint">Puede ser fecha pasada o futura.</span>
                            </div>
                        </div>

                        <div class="deb-form__fields deb-form__fields--imagenes">
                            <div class="form-group">
                                <label class="form-label">
                                    Firma PNG <span class="text-muted">(opcional)</span>
                                    <span class="form-hint">Max. 2 MB</span>
                                </label>
                                <input type="file" name="firma" accept="image/png" class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">
                                    Sello PNG <span class="text-muted">(opcional)</span>
                                    <span class="form-hint">Max. 2 MB</span>
                                </label>
                                <input type="file" name="sello" accept="image/png" class="form-input">
                            </div>
                            <div class="deb-form__action">
                                <button type="submit" class="btn btn--primary">
                                    <?= $tieneActual ? 'Cambiar director' : 'Asignar director' ?>
                                </button>
                            </div>
                        </div>

                    </form>
                <?php endif; ?>

                <!-- ── Historial ──────────────────────────────────── -->
                <?php if (!empty($historial)): ?>
                    <div class="deb-historial">
                        <h3 class="deb-historial__titulo">Historial de cambios</h3>
                        <table class="tabla-base deb-tabla">
                            <thead>
                                <tr>
                                    <th>Director EBR</th>
                                    <th>Desde</th>
                                    <th>Hasta</th>
                                    <th>Firma</th>
                                    <th>Sello</th>
                                    <th>Registrado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $h): ?>
                                    <tr class="<?= $h['hasta'] === null ? 'deb-fila-vigente' : '' ?>">
                                        <td class="deb-td-nombre">
                                            <?php if ($h['hasta'] === null): ?>
                                                <span class="badge badge--activo deb-badge-vigente">Vigente</span>
                                            <?php endif; ?>
                                            <?= e($h['nombre_completo']) ?>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($h['desde'])) ?></td>
                                        <td>
                                            <?= $h['hasta'] !== null
                                                ? date('d/m/Y', strtotime($h['hasta']))
                                                : '<span class="text-muted">—</span>'
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($h['firma_path']): ?>
                                                <img src="<?= url($h['firma_path']) ?>"
                                                     alt="Firma"
                                                     class="deb-thumb">
                                            <?php else: ?>
                                                <span class="text-muted text-sm">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($h['sello_path']): ?>
                                                <img src="<?= url($h['sello_path']) ?>"
                                                     alt="Sello"
                                                     class="deb-thumb">
                                            <?php else: ?>
                                                <span class="text-muted text-sm">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-sm text-muted">
                                            <?= date('d/m/Y H:i', strtotime($h['asignado_en'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted deb-sin-historial">
                        Aún no se ha asignado ningún Director EBR para este año.
                    </p>
                <?php endif; ?>

            </div>
        </div>

    <?php endforeach; ?>

<?php endif; ?>
