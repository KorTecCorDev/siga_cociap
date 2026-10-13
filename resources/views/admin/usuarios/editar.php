<?php
/**
 * @var array $usuario  { id, persona_id, dni, apellido_paterno, apellido_materno,
 *                        nombres, correo, telefono, sexo, rol_id, rol_nombre,
 *                        rol_codigo, estado, ultimo_acceso }
 * @var array $roles    [{ id, nombre, codigo }, ...]
 * @var array $filtro   filtro del buscador de la lista (q, rol, estado), ya validado
 * @var string $volver  la lista con ese filtro
 */
$nombre = \App\Models\UsuarioModel::nombreCompleto($usuario);
?>

<div class="page-header">
    <a href="<?= e($volver) ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Editar Usuario</h1>
        <p class="page-subtitle"><?= e($nombre) ?></p>
    </div>
    <span class="badge <?= $usuario['estado'] === 'activo' ? 'badge--activo' : 'badge--error' ?>">
        <?= $usuario['estado'] === 'activo' ? 'Activo' : 'Inactivo' ?>
    </span>
</div>

<div class="card">
    <div class="card__body">
        <form method="POST"
              action="<?= url('admin/usuarios/' . $usuario['id'] . '/editar') ?>"
              novalidate>
            <?= csrf_field() ?>
            <?php foreach ($filtro as $clave => $valor): ?>
                <input type="hidden" name="filtro_<?= e($clave) ?>" value="<?= e($valor) ?>">
            <?php endforeach; ?>

            <div class="form-grid">

                <!-- ── Sección: Datos personales ──────────────── -->
                <p class="form-section-title">Datos personales</p>

                <div class="form-group">
                    <label class="form-label" for="dni">DNI <span class="text-danger">*</span></label>
                    <input type="text"
                           id="dni"
                           name="dni"
                           class="form-input"
                           maxlength="8"
                           pattern="\d{8}"
                           inputmode="numeric"
                           value="<?= e($usuario['dni']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sexo">Sexo <span class="text-danger">*</span></label>
                    <select id="sexo" name="sexo" class="form-input" required>
                        <option value="">Seleccionar...</option>
                        <option value="M" <?= $usuario['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
                        <option value="F" <?= $usuario['sexo'] === 'F' ? 'selected' : '' ?>>Femenino</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="apellido_paterno">Apellido paterno <span class="text-danger">*</span></label>
                    <input type="text"
                           id="apellido_paterno"
                           name="apellido_paterno"
                           class="form-input"
                           maxlength="60"
                           value="<?= e($usuario['apellido_paterno']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="apellido_materno">Apellido materno <span class="text-danger">*</span></label>
                    <input type="text"
                           id="apellido_materno"
                           name="apellido_materno"
                           class="form-input"
                           maxlength="60"
                           value="<?= e($usuario['apellido_materno']) ?>"
                           required>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label" for="nombres">Nombres <span class="text-danger">*</span></label>
                    <input type="text"
                           id="nombres"
                           name="nombres"
                           class="form-input"
                           maxlength="80"
                           value="<?= e($usuario['nombres']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="correo">Correo electrónico</label>
                    <input type="email"
                           id="correo"
                           name="correo"
                           class="form-input"
                           maxlength="120"
                           value="<?= e($usuario['correo'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="telefono">Teléfono / Celular</label>
                    <input type="tel"
                           id="telefono"
                           name="telefono"
                           class="form-input"
                           maxlength="15"
                           value="<?= e($usuario['telefono'] ?? '') ?>">
                </div>

                <!-- ── Sección: Acceso al sistema ─────────────── -->
                <p class="form-section-title">Acceso al sistema</p>

                <div class="form-group form-group--full">
                    <label class="form-label" for="rol_id">Rol <span class="text-danger">*</span></label>
                    <select id="rol_id" name="rol_id" class="form-input" class="form-input select-rol" required>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= $rol['id'] ?>"
                                    <?= (int) $rol['id'] === (int) $usuario['rol_id'] ? 'selected' : '' ?>>
                                <?= e($rol['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Nueva contraseña</label>
                    <input type="password"
                           id="password"
                           name="password"
                           class="form-input"
                           minlength="8"
                           autocomplete="new-password"
                           placeholder="Dejar en blanco para no cambiar">
                    <span class="pass-hint">Mínimo 8 caracteres. Si dejas el campo vacío, la contraseña actual se conserva.</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirm">Confirmar nueva contraseña</label>
                    <input type="password"
                           id="password_confirm"
                           name="password_confirm"
                           class="form-input"
                           minlength="8"
                           autocomplete="new-password"
                           placeholder="Repetir nueva contraseña">
                </div>

            </div><!-- /.form-grid -->

            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Guardar cambios</button>
                <a href="<?= e($volver) ?>" class="btn btn--secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>
