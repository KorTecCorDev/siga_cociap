<?php
/**
 * Vista: Notas autorizadas por dirección para SIAGIE (informe aparte).
 *
 * @var array  $matricula
 * @var array  $bloques  [ ['periodo'=>..., 'elegibles'=>[...], 'registradas'=>[...],
 *                          'old'=>borrador, 'borrador'=>avisos (migración 074)], ... ]
 * @var string $nivel    'primaria' | 'secundaria' (para la regla de conclusión)
 */
$mid = (int) $matricula['id'];
?>

<div class="page-header">
    <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary btn--sm">← Ver matrícula</a>
    <div>
        <h1 class="page-title">Notas autorizadas para SIAGIE</h1>
        <p class="page-subtitle"><?= e($matricula['nombre_completo']) ?> · <?= e(($matricula['grado_numero'] ?? '') . ($matricula['seccion_nombre'] ?? '')) ?></p>
    </div>
</div>

<div class="retorno-aviso mb-md">
    Registra aquí las notas que <strong>dirección autoriza</strong> para este alumno cuando
    <strong>no fue evaluado</strong> (salud, accidente, viaje u otro motivo). Son
    <strong>válidas solo para el SIAGIE</strong>: no aparecen en la boleta ni cuentan para el orden de
    mérito. Solo se pueden autorizar competencias donde el docente dejó una <strong>omisión
    registrada</strong> (con cualquier motivo), ya <strong>bloqueadas</strong> y sin nota real. La
    conclusión descriptiva es obligatoria si la nota es <strong>B o C</strong> (primaria) o
    <strong>C</strong> (secundaria).
</div>

<?php if (empty($bloques)): ?>
    <div class="card">
        <div class="card__body">
            <div class="empty-state">
                <p>No hay competencias autorizables ni notas registradas.</p>
                <p class="text-sm text-muted">
                    Aparecerán cuando el alumno tenga una omisión registrada (cualquier motivo) en
                    una competencia bloqueada y sin nota real.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($bloques as $b): $per = $b['periodo']; $pid = (int) $per['id']; ?>
<div class="card mb-md">
    <div class="card__header card__header--between">
        <h2 class="card__title"><?= e($per['nombre_display']) ?></h2>
        <span class="badge badge--<?= $per['estado'] === 'cerrado' ? 'activo' : 'info' ?>"><?= e($per['estado']) ?></span>
    </div>
    <div class="card__body">

        <!-- Registradas -->
        <p class="form-section-title">Registradas (<?= count($b['registradas']) ?>)</p>
        <?php if (empty($b['registradas'])): ?>
            <div class="empty-state"><p>Sin notas autorizadas en este bimestre.</p></div>
        <?php else: ?>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Área</th><th>Competencia</th><th class="text-center">Nota</th>
                            <th>Conclusión</th><th>Resolución</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($b['registradas'] as $r): ?>
                        <tr>
                            <td class="text-sm"><?= e($r['area_nombre'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($r['competencia_nombre']) ?></td>
                            <td class="text-center"><span class="matricula-badge matricula-badge--nuevo"><?= e($r['nota_literal']) ?></span></td>
                            <td class="text-sm text-muted"><?= e($r['conclusion_descriptiva'] ?? '—') ?></td>
                            <td class="text-sm text-muted"><?= e($r['resolucion']) ?></td>
                            <td>
                                <form method="POST" action="<?= url('matriculas/' . $mid . '/notas-siagie/eliminar') ?>"
                                      onsubmit="return confirm('¿Eliminar esta nota autorizada?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reg_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn--danger btn--sm">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Autorizar una competencia elegible -->
        <?php if (!empty($b['elegibles'])): ?>
            <?php // Borrador de ESTE bimestre (06/10/2026): vuelve a los campos por $old.
                  $old = is_array($b['old'] ?? null) ? $b['old'] : [];
                  $oldTxt = static fn(string $k): string => is_string($old[$k] ?? null) ? $old[$k] : '';
                  $borrador = $b['borrador'] ?? [];
                  require VIEW_PATH . '/shared/_borrador-avisos.php'; ?>
            <form method="POST" action="<?= url('matriculas/' . $mid . '/notas-siagie') ?>"
                  data-borrador-tipo="notas_siagie"
                  data-borrador-ctx-matricula="<?= $mid ?>"
                  data-borrador-ctx-periodo="<?= $pid ?>"
                  data-borrador-revision="<?= (int) ($borrador['revision'] ?? 0) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="periodo_id" value="<?= $pid ?>">
                <div class="form-grid">
                    <p class="form-section-title">Autorizar competencia</p>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="comp-<?= $pid ?>">Competencia <span class="text-danger">*</span></label>
                        <select id="comp-<?= $pid ?>" name="competencia_id" class="form-input" required>
                            <option value="">— elige —</option>
                            <?php foreach ($b['elegibles'] as $c): ?>
                                <option value="<?= (int) $c['competencia_id'] ?>"<?= $oldTxt('competencia_id') === (string) (int) $c['competencia_id'] ? ' selected' : '' ?>>
                                    <?= e(($c['area_nombre'] ? $c['area_nombre'] . ' — ' : '') . $c['competencia_nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="lit-<?= $pid ?>">Nota literal <span class="text-danger">*</span></label>
                        <select id="lit-<?= $pid ?>" name="nota_literal" class="form-input" required>
                            <option value="">—</option>
                            <?php foreach (['AD' => 'Logro destacado', 'A' => 'Logro esperado', 'B' => 'En proceso', 'C' => 'En inicio'] as $lit => $txt): ?>
                                <option value="<?= $lit ?>"<?= $oldTxt('nota_literal') === $lit ? ' selected' : '' ?>><?= $lit ?> — <?= $txt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="conc-<?= $pid ?>">Conclusión descriptiva</label>
                        <textarea id="conc-<?= $pid ?>" name="conclusion_descriptiva" class="form-input" rows="2"
                                  placeholder="Obligatoria si la nota es B/C (primaria) o C (secundaria)"><?= e($oldTxt('conclusion_descriptiva')) ?></textarea>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="res-<?= $pid ?>">Resolución / autorización de dirección <span class="text-danger">*</span></label>
                        <input type="text" id="res-<?= $pid ?>" name="resolucion" class="form-input" maxlength="255"
                               placeholder="Ej.: Autorizado por Dirección — Resolución N.° ___ / motivo" required
                               value="<?= e($oldTxt('resolucion')) ?>">
                    </div>
                </div>
                <p class="text-sm text-muted" data-borrador-estado aria-live="polite"></p>
                <div class="btn-group form-actions">
                    <button type="submit" class="btn btn--primary">Autorizar nota</button>
                </div>
            </form>
        <?php else: ?>
            <p class="text-sm text-muted">No quedan competencias autorizables en este bimestre.</p>
        <?php endif; ?>

    </div>
</div>
<?php endforeach; ?>

<?php if (!empty($bloques)): ?>
<div class="btn-group">
    <a href="<?= url('matriculas/' . $mid . '/notas-siagie/informe') ?>" target="_blank" class="btn btn--secondary">Informe imprimible</a>
</div>
<?php endif; ?>
