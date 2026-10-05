<?php
/**
 * @var array $matricula  matrícula OFICIAL (grado/sección SIAGIE)
 * @var array $retorno    retorno activo (incluye grado_destino, seccion_destino)
 * @var array $periodos   bimestres cerrados CURSADOS en el grado operativo (con criterios)
 * @var array $registros  asistencia y conducta del bimestre en curso que se mueven a la oficial
 */
$mid = (int) $matricula['id'];
?>

<div class="page-header">
    <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary btn--sm">← Ver matrícula</a>
    <div>
        <h1 class="page-title">Revertir retorno de grado</h1>
        <p class="page-subtitle"><?= e($matricula['nombre_completo']) ?> · Grado oficial: <?= e($matricula['grado_nombre'] ?? '—') ?></p>
    </div>
</div>

<div class="retorno-aviso">
    <strong>⚠ Caso especial.</strong> El estudiante vuelve a calificarse en su
    grado/sección OFICIAL
    <strong><?= e(($matricula['grado_nombre'] ?? '') . ' ' . ($matricula['seccion_nombre'] ?? '')) ?></strong>.
    El grado operativo
    <strong><?= e(($retorno['grado_destino'] ?? '—') . ' ' . ($retorno['seccion_destino'] ?? '')) ?></strong>
    se desactivará. La boleta siempre muestra el
    grado/sección oficial y consolida automáticamente las notas de los bimestres
    cursados en el grado operativo.
</div>

<div class="card">
    <div class="card__body">
        <p class="form-section-title">Bimestres que se consolidan</p>
        <?php if (empty($periodos)): ?>
            <p class="text-muted">El grado operativo aún no tiene notas registradas; no hay nada que consolidar.</p>
        <?php else: ?>
            <ul class="mat-pendientes__list">
                <?php foreach ($periodos as $p): ?>
                    <li><?= e($p['nombre_display']) ?> (cerrado)</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="form-section-title">Asistencia y conducta del bimestre en curso</p>
        <?php if (empty($registros)): ?>
            <p class="text-muted">El grado operativo no tiene asistencia ni conducta registradas en el bimestre en curso; no hay nada que mover.</p>
        <?php else: ?>
            <p class="text-muted">Pasan a la matrícula oficial; el estudiante termina el bimestre en su sección oficial.</p>
            <ul class="mat-pendientes__list">
                <?php foreach ($registros as $r): ?>
                    <li>
                        <?= e($r['nombre_display']) ?>:
                        conducta <?= (int) $r['conducta'] > 0
                            ? ((int) $r['conducta_confirmada'] > 0 ? '(confirmada)' : '(borrador)')
                            : '(sin registro)' ?>
                        · asistencia <?= (int) $r['incidencias'] ?> incidencia(s)
                        <?= (int) $r['asistencia_confirmada'] > 0 ? '(confirmada)' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="<?= url('matriculas/' . $mid . '/retorno/revertir') ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group form-group--full">
                    <label class="form-label" for="motivo">Motivo de la reversión <span class="text-danger">*</span></label>
                    <textarea id="motivo" name="motivo" class="form-input" rows="4" required
                              placeholder="Describe por qué el estudiante vuelve a su grado oficial y desde cuándo (por ejemplo: retornó al inicio del III Bimestre)..."></textarea>
                </div>
                <?php if (!empty($registros)): ?>
                    <div class="form-group form-group--full">
                        <label class="form-check">
                            <input type="checkbox" name="como_borrador" value="1">
                            <span>
                                El estudiante <strong>ya no estaba</strong> en el grado operativo cuando se
                                registraron estos datos (la reversión se comunica tarde): moverlos
                                <strong>como borrador</strong>, para que el personal de su sección oficial
                                los revise y los confirme. No se borra ninguna respuesta ni ninguna fecha.
                            </span>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--danger"
                        onclick="return confirm('¿Confirmas la reversión? El estudiante volverá a calificarse en su grado oficial.')">
                    Revertir retorno
                </button>
                <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
