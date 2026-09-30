<?php
/**
 * @var array $roles  [{ id, nombre, codigo }, ...]
 */
?>

<div class="page-header">
    <a href="<?= url('admin/usuarios') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Nuevo Usuario</h1>
        <p class="page-subtitle">Completa los datos para crear la cuenta de acceso.</p>
    </div>
</div>

<div class="card">
    <div class="card__body">
        <form method="POST" action="<?= url('admin/usuarios/crear') ?>" novalidate>
            <?= csrf_field() ?>

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
                           placeholder="12345678"
                           required
                           autofocus>
                </div>

                <?php // Lo llena usuario-dni.js al completar el DNI. `hidden` va en el
                      // envoltorio SIN clase: `.alert` declara display:block. ?>
                <div class="form-group--full" id="dniAviso" hidden>
                    <p class="alert" id="dniAvisoTexto"></p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="sexo">Sexo <span class="text-danger">*</span></label>
                    <select id="sexo" name="sexo" class="form-input" required>
                        <option value="">Seleccionar...</option>
                        <option value="M">Masculino</option>
                        <option value="F">Femenino</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="apellido_paterno">Apellido paterno <span class="text-danger">*</span></label>
                    <input type="text"
                           id="apellido_paterno"
                           name="apellido_paterno"
                           class="form-input"
                           maxlength="60"
                           placeholder="QUISPE"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="apellido_materno">Apellido materno <span class="text-danger">*</span></label>
                    <input type="text"
                           id="apellido_materno"
                           name="apellido_materno"
                           class="form-input"
                           maxlength="60"
                           placeholder="FLORES"
                           required>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label" for="nombres">Nombres <span class="text-danger">*</span></label>
                    <input type="text"
                           id="nombres"
                           name="nombres"
                           class="form-input"
                           maxlength="80"
                           placeholder="JUAN CARLOS"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="correo">Correo electrónico</label>
                    <input type="email"
                           id="correo"
                           name="correo"
                           class="form-input"
                           maxlength="120"
                           placeholder="usuario@cociap.edu.pe">
                </div>

                <div class="form-group">
                    <label class="form-label" for="telefono">Teléfono / Celular</label>
                    <input type="tel"
                           id="telefono"
                           name="telefono"
                           class="form-input"
                           maxlength="15"
                           placeholder="943 000 000">
                </div>

                <!-- ── Sección: Acceso al sistema ─────────────── -->
                <p class="form-section-title">Acceso al sistema</p>

                <div class="form-group form-group--full">
                    <label class="form-label" for="rol_id">Rol <span class="text-danger">*</span></label>
                    <select id="rol_id" name="rol_id" class="form-input" class="form-input select-rol" required>
                        <option value="">Seleccionar rol...</option>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= $rol['id'] ?>">
                                <?= e($rol['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña <span class="text-danger">*</span></label>
                    <input type="password"
                           id="password"
                           name="password"
                           class="form-input"
                           minlength="8"
                           autocomplete="new-password"
                           required>
                    <span class="pass-hint">Mínimo 8 caracteres.</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirm">Confirmar contraseña <span class="text-danger">*</span></label>
                    <input type="password"
                           id="password_confirm"
                           name="password_confirm"
                           class="form-input"
                           minlength="8"
                           autocomplete="new-password"
                           required>
                </div>

            </div><!-- /.form-grid -->

            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Crear usuario</button>
                <a href="<?= url('admin/usuarios') ?>" class="btn btn--secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>
