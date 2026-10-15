<?php
/**
 * Ratificación de matrícula (migración 080, 14/10/2026) —
 * `Admin\RatificacionController::index`. Ver docs/modulos/ratificaciones.md.
 *
 * Sin sección elegida: las secciones del año con sus contadores. Con sección:
 * sus estudiantes y la decisión de cada uno. Admin, RA y las secretarías
 * registran; Dirección ve en solo lectura.
 *
 * @var array      $anios         [{ id, anio, estado }]
 * @var array|null $anio          año elegido
 * @var array      $secciones     RatificacionModel::seccionesConContadores()
 * @var array|null $seccion       sección elegida
 * @var array      $estudiantes   RatificacionModel::listarSeccion()
 * @var array      $motivos       motivos VIGENTES del año [{ id, nombre }]
 * @var bool       $puedeEscribir
 */
use App\Models\RatificacionModel;

$etiquetaDecision = ['ratificado' => 'Ratificado', 'no_ratificado' => 'No ratificado'];
$urlBase  = url('admin/ratificaciones');
$anioId   = $anio !== null ? (int) $anio['id'] : 0;
?>

<div class="page-header">
    <?php if ($seccion !== null): ?>
        <a href="<?= $urlBase ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">← Secciones</a>
    <?php else: ?>
        <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Inicio</a>
    <?php endif; ?>
    <div>
        <h1 class="page-title">Ratificación de matrícula</h1>
        <p class="page-subtitle">
            <?php if ($seccion !== null): ?>
                <?= e($seccion['nivel_nombre'] . ' · ' . $seccion['grado_nombre'] . ' "' . $seccion['nombre'] . '"') ?>
                · <?= e((string) $anio['anio']) ?>
            <?php else: ?>
                Decisión de Dirección sobre la continuidad de cada estudiante en el año siguiente.
            <?php endif; ?>
        </p>
    </div>
    <div class="ratif-cabecera-acciones">
        <a href="<?= url('admin/ratificaciones/plantillas') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">Plantillas</a>
        <a href="<?= url('admin/ratificaciones/motivos') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">Motivos</a>
    </div>
</div>

<?php if ($anio === null): ?>
    <div class="empty-state"><p>No hay años académicos registrados.</p></div>
<?php else: ?>

<?php if ($seccion === null): ?>
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

    <?php if ($puedeEscribir && $motivos === []): ?>
        <div class="alert alert--info">
            Para registrar a un estudiante como no ratificado, primero agrega los motivos del año.
            <a href="<?= url('admin/ratificaciones/motivos') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm alert__accion">Agregar motivos</a>
        </div>
    <?php endif; ?>

    <?php if ($secciones === []): ?>
        <div class="empty-state"><p>No hay estudiantes por ratificar en <?= e((string) $anio['anio']) ?>.</p></div>
    <?php else: ?>
        <div class="card">
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Nivel</th>
                            <th>Grado y sección</th>
                            <th class="text-center">Estudiantes</th>
                            <th class="text-center">Ratificados</th>
                            <th class="text-center">No ratificados</th>
                            <th class="text-center">Pendientes</th>
                            <th class="text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secciones as $s): ?>
                            <tr>
                                <td><?= e($s['nivel_nombre']) ?></td>
                                <td><?= e($s['grado_nombre'] . ' "' . $s['nombre'] . '"') ?></td>
                                <td class="text-center"><?= (int) $s['total'] ?></td>
                                <td class="text-center"><?= (int) $s['ratificados'] ?></td>
                                <td class="text-center"><?= (int) $s['no_ratificados'] ?></td>
                                <td class="text-center"><?= (int) $s['pendientes'] ?></td>
                                <td class="text-right">
                                    <a href="<?= $urlBase ?>?anio_id=<?= $anioId ?>&amp;seccion_id=<?= (int) $s['id'] ?>"
                                       class="btn btn--secondary btn--sm">Ver sección</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <?php
    $total      = count($estudiantes);
    $pendientes = count(array_filter($estudiantes, static fn($r) => $r['decision'] === null));
    $noRatif    = count(array_filter($estudiantes, static fn($r) => $r['decision'] === 'no_ratificado'));
    $porEmitir  = count(array_filter($estudiantes, static fn($r) => $r['decision'] !== null && $r['emision_id'] === null));
    $emitidos   = count(array_filter($estudiantes, static fn($r) => $r['emision_id'] !== null));
    ?>
    <div class="ratif-documento mb-md">
        <span class="text-muted">
            <?= $total ?> estudiante(s) · <?= $total - $pendientes - $noRatif ?> ratificado(s) ·
            <?= $noRatif ?> no ratificado(s) · <?= $pendientes ?> pendiente(s) · <?= $emitidos ?> documento(s) emitido(s)
        </span>
        <?php if ($puedeEscribir && $porEmitir > 0): ?>
            <form method="POST" action="<?= url('admin/ratificaciones/seccion/' . (int) $seccion['id'] . '/emitir') ?>"
                  data-confirm="Se emitirá el documento de <?= $porEmitir ?> estudiante(s) con decisión registrada.">
                <?= csrf_field() ?>
                <input type="hidden" name="anio_id" value="<?= $anioId ?>">
                <button type="submit" class="btn btn--primary btn--sm">Emitir documentos de la sección (<?= $porEmitir ?>)</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($puedeEscribir && $motivos === []): ?>
        <div class="alert alert--info">
            Para registrar a un estudiante como no ratificado, primero agrega los motivos del año.
            <a href="<?= url('admin/ratificaciones/motivos') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm alert__accion">Agregar motivos</a>
        </div>
    <?php endif; ?>

    <?php if ($estudiantes === []): ?>
        <div class="empty-state"><p>Esta sección no tiene estudiantes por ratificar.</p></div>
    <?php else: ?>
        <div class="card">
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas ratif-tabla">
                    <thead>
                        <tr>
                            <th class="col-num">N°</th>
                            <th class="col-nombre">Estudiante</th>
                            <th>DNI</th>
                            <th>Decisión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes as $i => $r):
                            $mid      = (int) $r['matricula_id'];
                            $decision = $r['decision'];
                            $fid      = 'ratif-' . $mid;
                            $nombre   = trim($r['apellido_paterno'] . ' ' . $r['apellido_materno']) . ', ' . $r['nombres'];
                        ?>
                            <tr id="m<?= $mid ?>" class="<?= $decision === null ? 'ratif-fila--pendiente' : 'ratif-fila--decidida' ?>">
                                <td class="col-num"><?= $i + 1 ?></td>
                                <td class="col-nombre"><?= e($nombre) ?></td>
                                <td><?= e((string) $r['dni']) ?></td>
                                <td>
                                    <?php if (!$puedeEscribir): ?>
                                        <?php if ($decision === null): ?>
                                            <span class="text-muted">Pendiente</span>
                                        <?php else: ?>
                                            <?= e($etiquetaDecision[$decision]) ?>
                                            <?php if ($decision === 'no_ratificado'): ?>
                                                — <?= e((string) $r['motivo_nombre']) ?>
                                                <?php if ($r['detalle'] !== null): ?>
                                                    <div class="text-sm text-muted"><?= e($r['detalle']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="POST" id="<?= $fid ?>" class="ratif-decision"
                                              action="<?= url('admin/ratificaciones/' . $mid . '/decision') ?>"
                                              data-ratif-form
                                              <?php if ($r['emision_id'] !== null): ?>
                                              data-confirm-cambios="<?= e($nombre) ?> ya tiene un documento emitido. Si guardas estos cambios, ese documento dejará de ser válido."
                                              <?php endif; ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="confirmar_anulacion" value="0">
                                            <select name="decision" class="form-select" data-campo="Decisión"
                                                    aria-label="Decisión para <?= e($nombre) ?>" required>
                                                <option value=""<?= $decision === null ? ' selected' : '' ?> disabled>Pendiente</option>
                                                <?php foreach ($etiquetaDecision as $valor => $texto): ?>
                                                    <option value="<?= $valor ?>"<?= $decision === $valor ? ' selected' : '' ?>><?= $texto ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select name="motivo_id" class="form-select" data-campo="Motivo" data-ratif-motivo
                                                    aria-label="Motivo para <?= e($nombre) ?>">
                                                <option value="">Elige el motivo</option>
                                                <?php // El retirado que ya tenía se conserva como opción: no se le cambia por retirarlo. ?>
                                                <?php if ($r['motivo_id'] !== null && !(int) $r['motivo_activo']): ?>
                                                    <option value="<?= (int) $r['motivo_id'] ?>" selected><?= e((string) $r['motivo_nombre']) ?></option>
                                                <?php endif; ?>
                                                <?php foreach ($motivos as $mo): ?>
                                                    <option value="<?= (int) $mo['id'] ?>"<?= (int) $mo['id'] === (int) $r['motivo_id'] ? ' selected' : '' ?>><?= e($mo['nombre']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="detalle" class="form-input form-input--detalle"
                                                   data-campo="Detalle" data-ratif-detalle
                                                   maxlength="<?= RatificacionModel::DETALLE_MAX ?>"
                                                   placeholder="Detalle (opcional)"
                                                   aria-label="Detalle para <?= e($nombre) ?>"
                                                   value="<?= e((string) ($r['detalle'] ?? '')) ?>">
                                            <button type="submit" class="btn btn--primary btn--sm">Guardar</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php // Documento: emitido (imprimir / anular) o por emitir. Un pendiente no tiene documento. ?>
                                    <?php if ($r['emision_id'] !== null): ?>
                                        <div class="ratif-documento">
                                            <span class="text-muted">Documento emitido el <?= e(date('d/m/Y', strtotime((string) $r['emitido_en']))) ?></span>
                                            <a href="<?= url('admin/ratificaciones/emision/' . (int) $r['emision_id'] . '/imprimir') ?>"
                                               target="_blank" rel="noopener" class="btn btn--secondary btn--sm">Imprimir</a>
                                            <?php if ($puedeEscribir): ?>
                                                <form method="POST" action="<?= url('admin/ratificaciones/' . $mid . '/anular') ?>"
                                                      data-confirm="El documento de <?= e($nombre) ?> dejará de ser válido. Después podrás emitir uno nuevo.">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn--danger btn--sm">Anular</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($puedeEscribir && $decision !== null): ?>
                                        <div class="ratif-documento">
                                            <form method="POST" action="<?= url('admin/ratificaciones/' . $mid . '/emitir') ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn--secondary btn--sm">Emitir documento</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php endif; ?>
