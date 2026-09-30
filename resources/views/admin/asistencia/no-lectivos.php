<?php
/**
 * Días NO LECTIVOS de la asistencia (30/09/2026, migración 070) —
 * `Admin\AsistenciaCalendarioController::index`. Feriados y suspensiones de TODO
 * el colegio: no se marcan en la grilla ni se exigen al bloquear. Admin y RA
 * editan; Dirección ve en solo lectura. Reusa las clases de la pantalla de
 * motivos / criterios de conducta (`_criterios-conducta.scss`).
 *
 * @var array $dias   AsistenciaJornadaModel::listarNoLectivos() (más recientes primero)
 * @var bool  $editor admin o RA
 */
?>

<div class="page-header">
    <?php if ($editor): ?>
        <a href="<?= url('admin/asistencia') ?>" class="btn btn--secondary btn--sm">← Asistencia</a>
    <?php else: ?>
        <a href="<?= url('admin/asistencia/motivos') ?>" class="btn btn--secondary btn--sm">← Motivos</a>
    <?php endif; ?>
    <div>
        <h1 class="page-title">Días no lectivos</h1>
        <p class="page-subtitle">
            Feriados y suspensiones de clase de todo el colegio. Esos días no se toma lista ni se
            marcan incidencias, y no se exigen al bloquear la asistencia.
        </p>
    </div>
</div>

<?php if ($dias === []): ?>
    <div class="empty-state"><p>No hay días no lectivos declarados.</p></div>
<?php else: ?>
    <div class="card">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas criterios-tabla">
                <thead>
                    <tr>
                        <th class="criterios-tabla__nivel">Fecha</th>
                        <th>Motivo</th>
                        <?php if ($editor): ?><th class="text-right">Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dias as $d): $fecha = (string) $d['fecha']; ?>
                        <tr>
                            <td class="criterios-tabla__nivel"><?= e(date('d/m/Y', strtotime($fecha))) ?></td>
                            <td>
                                <?= e($d['motivo']) ?>
                                <span class="criterios-tabla__traza">
                                    Registrado<?= !empty($d['registrado_por']) ? ' por ' . e($d['registrado_por']) : '' ?>
                                    el <?= e(date('d/m/Y', strtotime($d['registrado_en']))) ?>
                                </span>
                            </td>
                            <?php if ($editor): ?>
                                <td class="criterios-tabla__acciones">
                                    <form method="POST" action="<?= url('admin/asistencia/no-lectivos/' . $fecha . '/quitar') ?>"
                                          data-confirm="¿Volver a hacer lectivo el <?= e(date('d/m/Y', strtotime($fecha))) ?>? Cada sección lo tendrá «Sin tomar» y deberá pasar lista.">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn--danger btn--sm">Quitar</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($editor): ?>
    <form method="POST" action="<?= url('admin/asistencia/no-lectivos/crear') ?>" class="card criterios-nuevo">
        <div class="card__body">
            <?= csrf_field() ?>
            <p class="form-section-title">Declarar día no lectivo</p>
            <label class="form-label" for="nl-fecha">Fecha <span class="text-danger">*</span></label>
            <input type="date" id="nl-fecha" name="fecha" class="form-input" required>
            <label class="form-label" for="nl-motivo">Motivo <span class="text-danger">*</span></label>
            <input type="text" id="nl-motivo" name="motivo" class="form-input" maxlength="160" required
                   placeholder="Ej.: Combate de Angamos (feriado nacional)">
            <p class="text-sm text-muted">
                Solo se puede declarar un día sin incidencias registradas. Las listas ya tomadas ese día se quitan.
            </p>
            <div class="criterios-form__acciones">
                <button type="submit" class="btn btn--primary">Declarar no lectivo</button>
            </div>
        </div>
    </form>
<?php endif; ?>
