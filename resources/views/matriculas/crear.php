<?php
/**
 * @var int    $paso
 * @var array  $anioActivo
 * @var array  $grados
 * @var array  $secciones
 * @var string $dni
 * @var array|null $estudiante
 * @var bool   $yaMatriculado
 * @var array|null $noRatificado  RatificacionModel::avisoNoRatificado() { anio, emitido_en }
 */
$pasos = [1 => 'Estudiante', 2 => 'Apoderado', 3 => 'Documentos'];
?>

<div class="page-header">
    <a href="<?= url('matriculas') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Nueva matrícula</h1>
        <p class="page-subtitle">Año académico <?= e((string) $anioActivo['anio']) ?></p>
    </div>
</div>

<!-- Wizard -->
<div class="wizard-steps">
    <?php foreach ($pasos as $n => $label): ?>
        <div class="wizard-steps__item <?= $n === $paso ? 'wizard-steps__item--activo' : ($n < $paso ? 'wizard-steps__item--completado' : '') ?>">
            <span class="wizard-steps__num"><?= $n < $paso ? '✓' : $n ?></span>
            <span><?= $n ?>. <?= $label ?></span>
        </div>
        <?php if ($n < 3): ?><span class="wizard-steps__sep"></span><?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Paso 1: buscar estudiante por DNI -->
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Paso 1 — Buscar estudiante</p>
        <form method="GET" action="<?= url('matriculas/crear') ?>">
            <div class="busqueda-dni">
                <input type="text" name="dni" class="form-input" maxlength="8" pattern="\d{8}"
                       inputmode="numeric" placeholder="DNI del estudiante (8 dígitos)"
                       value="<?= e($dni) ?>" autofocus required>
                <button type="submit" class="btn btn--primary">Buscar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($dni !== '' && $estudiante && $yaMatriculado): ?>
    <div class="flash flash--warning">Este estudiante ya tiene una matrícula registrada en el año activo.</div>
<?php endif; ?>

<?php if (!empty($noRatificado)): ?>
    <div class="alert alert--warning">
        Este estudiante <strong>no fue ratificado</strong> para continuar en el colegio: tiene un documento de
        no ratificación del año <?= e((string) $noRatificado['anio']) ?>, emitido el
        <?= e(date('d/m/Y', strtotime((string) $noRatificado['emitido_en']))) ?>.
    </div>
<?php endif; ?>

<!-- Formulario de matrícula -->
<div class="card">
    <div class="card__body">
        <form method="POST" action="<?= url('matriculas/crear') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="form-grid">

                <?php if ($estudiante): ?>
                    <!-- Estudiante existente (datos en solo lectura) -->
                    <p class="form-section-title">Estudiante encontrado</p>
                    <div class="form-group">
                        <label class="form-label">DNI</label>
                        <input type="text" name="dni" class="form-input" value="<?= e($estudiante['dni'] ?? $dni) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Apellido paterno</label>
                        <input type="text" class="form-input" value="<?= e($estudiante['apellido_paterno']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Apellido materno</label>
                        <input type="text" class="form-input" value="<?= e($estudiante['apellido_materno']) ?>" readonly>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label">Nombres</label>
                        <input type="text" class="form-input" value="<?= e($estudiante['nombres']) ?>" readonly>
                    </div>
                <?php else: ?>
                    <!-- Estudiante nuevo -->
                    <p class="form-section-title">Datos del estudiante nuevo</p>

                    <!-- Alta provisional: el estudiante aún no tiene DNI. Genera
                         un código temporal y deja la matrícula en 'pendiente'
                         (aparece para calificar; se regulariza antes de activar). -->
                    <div class="form-group form-group--full">
                        <label class="form-check">
                            <input type="checkbox" id="provisional" name="provisional" value="1"
                                   data-provisional-toggle>
                            <span>El estudiante aún no tiene DNI (registro provisional)</span>
                        </label>
                        <span class="pass-hint" data-provisional-hint hidden>
                            Se generará un código provisional. La matrícula quedará
                            <strong>pendiente</strong>: aparecerá en la lista del docente para
                            calificar, pero deberás registrar el DNI real y los documentos antes
                            de activarla.
                        </span>
                    </div>

                    <div class="form-group" data-dni-group>
                        <label class="form-label" for="dni">DNI <span class="text-danger">*</span></label>
                        <input type="text" id="dni" name="dni" class="form-input" maxlength="8" pattern="\d{8}"
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
                        <label class="form-label" for="fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="sexo">Sexo</label>
                        <select id="sexo" name="sexo" class="form-input">
                            <option value="">Seleccionar...</option>
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Datos de matrícula -->
                <p class="form-section-title">Datos de la matrícula</p>

                <div class="form-group">
                    <label class="form-label" for="tipo">Tipo <span class="text-danger">*</span></label>
                    <select id="tipo" name="tipo" class="form-input" required>
                        <option value="continuador">Continuador</option>
                        <option value="nuevo">Nuevo</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="grado_id">Grado de destino <span class="text-danger">*</span></label>
                    <select id="grado_id" name="grado_id" class="form-input" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($grados as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= e($g['nivel_nombre'] . ' — ' . $g['nombre_display']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="seccion_id">Sección (sugerida por el sistema)</label>
                    <select id="seccion_id" name="seccion_id" class="form-input">
                        <option value="">Sugerir automáticamente</option>
                        <?php foreach ($secciones as $s): ?>
                            <option value="<?= $s['id'] ?>" data-grado="<?= $s['grado_id'] ?>">
                                <?= e($s['grado_nombre'] . ' ' . $s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="pass-hint">Si lo dejas vacío, el sistema asigna la sección con menos matrículas.</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="serie_recibo">Serie del recibo <span class="text-danger">*</span></label>
                    <input type="text" id="serie_recibo" name="serie_recibo" class="form-input" maxlength="30" required>
                </div>

            </div><!-- /.form-grid -->

            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Continuar → Apoderado</button>
                <a href="<?= url('matriculas') ?>" class="btn btn--secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
