<?php
/**
 * Criterios de conducta de un año (F6, 28/09/2026) — `Admin\CriterioConductaController::index`.
 * Admin y RA editan según el modo del año; Dirección ve en solo lectura.
 *
 * @var array      $anios      [{id, anio, estado}]
 * @var array      $anio       año mostrado {id, anio, estado}
 * @var array      $criterios  CriterioConductaModel::listar()
 * @var string     $modo       'completa' | 'redaccion' | 'lectura'
 * @var array|null $anterior   año anterior con criterios {id, anio, criterios} (solo si este no tiene)
 * @var array      $niveles    [{id, nombre}]
 * @var int        $editarId   criterio en edición (0 = ninguno)
 */
$anioId   = (int) $anio['id'];
$editor   = has_role(['admin', 'registro_academico']);
$completa = $modo === 'completa';
$urlAnio  = static fn(int $id, string $extra = ''): string => url('admin/conducta/criterios?anio=' . $id . $extra);
$nivelDe  = static fn(?string $nombre): string => $nombre ?? 'Ambos niveles';
?>

<div class="page-header">
    <?php if ($editor): ?>
        <a href="<?= url('admin/conducta') ?>" class="btn btn--secondary btn--sm">← Conducta</a>
    <?php else: ?>
        <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <?php endif; ?>
    <div>
        <h1 class="page-title">Criterios de conducta</h1>
        <p class="page-subtitle">
            Año <?= e((string) $anio['anio']) ?> · <?= count($criterios) ?> <?= count($criterios) === 1 ? 'criterio' : 'criterios' ?>.
            Cada estudiante se califica con Sí / No en cada uno.
        </p>
    </div>
</div>

<?php if (count($anios) > 1): ?>
    <nav class="criterios-anios" aria-label="Año académico">
        <?php foreach ($anios as $a): ?>
            <a href="<?= $urlAnio((int) $a['id']) ?>"
               class="criterios-anios__opcion<?= (int) $a['id'] === $anioId ? ' is-activa' : '' ?>"
               <?= (int) $a['id'] === $anioId ? 'aria-current="page"' : '' ?>><?= e((string) $a['anio']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($editor && $modo === 'redaccion'): ?>
    <div class="alert alert--info">
        <span>
            El <?= e((string) $anio['anio']) ?> ya tiene conducta registrada: aquí solo se puede
            <strong>corregir la redacción</strong>. Agregar, retirar o reordenar criterios vale desde el año siguiente.
        </span>
    </div>
<?php elseif ($editor && $anio['estado'] === 'cerrado'): ?>
    <div class="alert alert--info"><span>Año cerrado: sus criterios son de solo lectura.</span></div>
<?php endif; ?>

<?php if ($criterios === []): ?>
    <div class="empty-state">
        <p>El <?= e((string) $anio['anio']) ?> aún no tiene criterios de conducta.</p>
        <?php if ($completa && $anterior): ?>
            <form method="POST" action="<?= url('admin/conducta/criterios/copiar') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="anio_id" value="<?= $anioId ?>">
                <button type="submit" class="btn btn--primary">
                    Copiar los <?= (int) $anterior['criterios'] ?> criterios del <?= e((string) $anterior['anio']) ?>
                </button>
            </form>
        <?php elseif ($completa): ?>
            <p class="text-sm text-muted">Agrega el primero con el formulario de abajo.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas criterios-tabla">
                <thead>
                    <tr>
                        <th class="criterios-tabla__codigo">Código</th>
                        <th>Criterio</th>
                        <th class="criterios-tabla__nivel">Nivel</th>
                        <?php if ($modo !== 'lectura'): ?><th class="text-right">Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($criterios as $i => $k): $kid = (int) $k['id']; ?>
                        <?php if ($modo !== 'lectura' && $editarId === $kid): ?>
                            <tr class="criterios-tabla__editando">
                                <td class="criterios-tabla__codigo"><?= e((string) $k['codigo']) ?></td>
                                <td colspan="3">
                                    <form method="POST" action="<?= url('admin/conducta/criterios/' . $kid . '/editar') ?>"
                                          class="criterios-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="anio_id" value="<?= $anioId ?>">
                                        <label class="form-label" for="texto-<?= $kid ?>">Criterio</label>
                                        <textarea id="texto-<?= $kid ?>" name="texto" class="form-input" rows="2"
                                                  maxlength="<?= App\Models\CriterioConductaModel::TEXTO_MAX ?>" required><?= e($k['texto']) ?></textarea>
                                        <?php if ($completa): ?>
                                            <label class="form-label" for="nivel-<?= $kid ?>">Nivel</label>
                                            <select id="nivel-<?= $kid ?>" name="nivel_id" class="form-input criterios-form__nivel">
                                                <option value="">Ambos niveles</option>
                                                <?php foreach ($niveles as $n): ?>
                                                    <option value="<?= (int) $n['id'] ?>" <?= (int) $n['id'] === (int) $k['nivel_id'] ? 'selected' : '' ?>><?= e($n['nombre']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <?php // Solo redacción: el nivel viaja tal cual (un select deshabilitado no se envía). ?>
                                            <input type="hidden" name="nivel_id" value="<?= (int) $k['nivel_id'] ?>">
                                            <p class="text-sm text-muted">Nivel: <?= e($nivelDe($k['nivel_nombre'])) ?> (no se puede cambiar este año).</p>
                                        <?php endif; ?>
                                        <div class="criterios-form__acciones">
                                            <button type="submit" class="btn btn--primary btn--sm">Guardar</button>
                                            <a href="<?= $urlAnio($anioId) ?>" class="btn btn--secondary btn--sm">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="criterios-tabla__codigo"><?= e((string) $k['codigo']) ?></td>
                                <td>
                                    <?= e($k['texto']) ?>
                                    <?php if (!empty($k['modificado_en'])): ?>
                                        <span class="criterios-tabla__traza">
                                            Corregido<?= $k['modificado_por_nombre'] !== '' ? ' por ' . e($k['modificado_por_nombre']) : '' ?>
                                            el <?= e(date('d/m/Y', strtotime($k['modificado_en']))) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="criterios-tabla__nivel"><?= e($nivelDe($k['nivel_nombre'])) ?></td>
                                <?php if ($modo !== 'lectura'): ?>
                                    <td class="criterios-tabla__acciones">
                                        <a href="<?= $urlAnio($anioId, '&editar=' . $kid) ?>" class="btn btn--secondary btn--sm">Editar</a>
                                        <?php if ($completa): ?>
                                            <?php foreach (['arriba' => ['↑', 'Subir', $i > 0], 'abajo' => ['↓', 'Bajar', $i < count($criterios) - 1]] as $dir => [$flecha, $rotulo, $puede]): ?>
                                                <?php if ($puede): ?>
                                                    <form method="POST" action="<?= url('admin/conducta/criterios/' . $kid . '/mover') ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="anio_id" value="<?= $anioId ?>">
                                                        <input type="hidden" name="direccion" value="<?= $dir ?>">
                                                        <button type="submit" class="btn btn--secondary btn--sm" aria-label="<?= $rotulo ?> <?= e((string) $k['codigo']) ?>" title="<?= $rotulo ?>"><?= $flecha ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <form method="POST" action="<?= url('admin/conducta/criterios/' . $kid . '/retirar') ?>"
                                                  data-confirm="¿Retirar el criterio <?= e((string) $k['codigo']) ?>? Los códigos de los siguientes se correrán.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="anio_id" value="<?= $anioId ?>">
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

<?php if ($completa): ?>
    <form method="POST" action="<?= url('admin/conducta/criterios/crear') ?>" class="card criterios-nuevo">
        <div class="card__body">
            <?= csrf_field() ?>
            <input type="hidden" name="anio_id" value="<?= $anioId ?>">
            <p class="form-section-title">Agregar criterio</p>
            <label class="form-label" for="texto-nuevo">Criterio <span class="text-danger">*</span></label>
            <textarea id="texto-nuevo" name="texto" class="form-input" rows="2"
                      maxlength="<?= App\Models\CriterioConductaModel::TEXTO_MAX ?>" required></textarea>
            <label class="form-label" for="nivel-nuevo">Nivel</label>
            <select id="nivel-nuevo" name="nivel_id" class="form-input criterios-form__nivel">
                <option value="">Ambos niveles</option>
                <?php foreach ($niveles as $n): ?>
                    <option value="<?= (int) $n['id'] ?>"><?= e($n['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="criterios-form__acciones">
                <button type="submit" class="btn btn--primary">Agregar</button>
            </div>
        </div>
    </form>
<?php endif; ?>
