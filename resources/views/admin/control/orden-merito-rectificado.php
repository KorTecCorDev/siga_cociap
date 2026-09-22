<?php
/**
 * Vista: Orden de mérito RECTIFICADO (no oficial, no publicado) — solo lectura.
 *
 * Aparece cuando un cierre o una rectificación de notas tocó un bimestre ya
 * publicado: por inmutabilidad (migración 046) el oficial no cambió y el nuevo
 * cálculo se registró aparte. Mismo marcado que el ranking oficial, sin acciones.
 *
 * @var array      $periodo
 * @var array|null $info     [{generado_en, motivo, generado_por_nombre, num_alumnos}]
 * @var array      $ranking  [gradoId => {grado, estudiantes[]}]
 * @var array      $oficial  matricula_id => {puesto, promedio} del snapshot oficial
 */
$oficial = $oficial ?? [];

// Estudiantes del oficial que NO están en esta versión (trasladados o
// retirados después del cierre): corren los puestos de sus compañeros sin
// que nadie haya cambiado de nota.
$enRectificado = [];
foreach ($ranking as $data) {
    foreach ($data['estudiantes'] as $est) {
        $enRectificado[(int) $est['matricula_id']] = true;
    }
}
$fueraDelRectificado = count(array_diff_key($oficial, $enRectificado));
?>

<div class="page-header">
    <a href="<?= url('admin/control?periodo_id=' . (int) $periodo['id']) ?>"
       class="btn btn--secondary btn--sm">← Volver al Centro de control</a>
    <div>
        <h1 class="page-title">Orden de mérito rectificado</h1>
        <p class="page-subtitle">
            <?= e($periodo['nombre_display']) ?>
            <span class="badge badge--warning">No oficial · no publicado</span>
        </p>
    </div>
</div>

<div class="card mb-lg">
    <div class="card__body">
        <p>
            Esta es una versión <strong>no oficial</strong> del orden de mérito. El
            documento oficial de este bimestre ya fue publicado y <strong>no cambia</strong>;
            esta versión refleja un recálculo posterior (cierre o rectificación de notas)
            y se conserva solo para consulta interna.
        </p>
        <p class="text-muted">
            Las columnas <strong>Puesto oficial</strong> y <strong>Cambio</strong> comparan
            cada estudiante con el documento publicado. Van <strong>resaltadas</strong> las
            filas cuyo <strong>promedio cambió</strong> por una rectificación. Las
            <strong>calificaciones extraordinarias no cuentan</strong> para el orden de
            mérito: corregir una no mueve el ranking, y un estudiante con todas sus notas
            extraordinarias no aparece aquí.
        </p>
        <?php if ($fueraDelRectificado > 0): ?>
            <p class="text-muted">
                <strong><?= $fueraDelRectificado ?> estudiante(s) del documento oficial ya no
                están en esta versión</strong> (trasladados o retirados después del cierre).
                Por eso muchos puestos se corren aunque su nota no haya cambiado.
            </p>
        <?php endif; ?>
        <?php if (!empty($info)): ?>
            <p>
                Generado el
                <strong><?= e(date('d/m/Y H:i', strtotime($info['generado_en']))) ?></strong>
                <?php if (!empty($info['generado_por_nombre'])): ?>
                    por <?= e($info['generado_por_nombre']) ?>
                <?php endif; ?>
                · <?= (int) $info['num_alumnos'] ?> alumno(s).
            </p>
            <?php if (!empty($info['motivo'])): ?>
                <p class="text-muted"><strong>Motivo:</strong> <?= e($info['motivo']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($ranking)): ?>
    <div class="empty-state">
        <p>No hay una versión rectificada registrada para este bimestre.</p>
    </div>
<?php else: ?>

    <?php $gradoIdx = 0; foreach ($ranking as $data): $gradoIdx++; ?>
        <details class="card mb-lg" <?= $gradoIdx === 1 ? 'open' : '' ?>>
            <summary class="card__header card__header--toggle">
                <div class="card__header-left">
                    <h2 class="card__title">
                        <?= e($data['grado']['nivel_nombre']) ?> —
                        <?= e($data['grado']['nombre_display']) ?>
                    </h2>
                    <span class="badge badge--info">
                        <?= count($data['estudiantes']) ?> estudiantes
                    </span>
                </div>
                <svg class="card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="6,9 12,15 18,9"/>
                </svg>
            </summary>

            <?php if (empty($data['estudiantes'])): ?>
                <div class="card__body">
                    <p class="text-muted">Sin calificaciones registradas.</p>
                </div>
            <?php else: ?>
                <div class="tabla-responsive">
                <table class="tabla-ranking">
                    <thead>
                        <tr>
                            <th class="col-puesto text-center">Puesto</th>
                            <th class="text-center">Puesto oficial</th>
                            <th class="text-center">Cambio</th>
                            <th class="col-nombre">Apellidos y nombres</th>
                            <th class="text-center">Sección</th>
                            <th class="text-center">Comp.</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Promedio</th>
                            <th class="text-center">Distinción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['estudiantes'] as $est):
                            // Comparación con el OFICIAL: cuántos puestos se movió
                            // (positivo = subió) o si no estaba en el documento.
                            $of      = $oficial[(int) $est['matricula_id']] ?? null;
                            $delta   = $of !== null ? $of['puesto'] - (int) $est['puesto'] : null;
                            // Se resalta lo que CAMBIÓ DE NOTA, no de puesto: el
                            // puesto también se mueve si el roster difiere del
                            // oficial (B1: 13 que salieron), y eso no es una
                            // rectificación. Medido el 18/09: 121 puestos movidos
                            // por roster frente a 1 promedio rectificado.
                            $cambio  = $of === null
                                || abs($of['promedio'] - (float) $est['promedio_general']) > 0.001;
                            $clases  = trim(($est['media_beca'] ? 'fila-media-beca ' : '')
                                . ($cambio ? 'rect-merito__fila--cambio' : ''));
                        ?>
                            <tr class="<?= $clases ?>">
                                <td class="col-puesto text-center">
                                    <span class="puesto puesto--<?= $est['puesto'] <= 3 ? $est['puesto'] : 'normal' ?>">
                                        <?= $est['puesto'] ?>°
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?= $of !== null ? (int) $of['puesto'] . '°' : '—' ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($of === null): ?>
                                        <span class="rect-merito__delta rect-merito__delta--nuevo">nuevo</span>
                                    <?php elseif ($delta > 0): ?>
                                        <span class="rect-merito__delta rect-merito__delta--sube">↑ <?= $delta ?></span>
                                    <?php elseif ($delta < 0): ?>
                                        <span class="rect-merito__delta rect-merito__delta--baja">↓ <?= abs($delta) ?></span>
                                    <?php else: ?>
                                        <span class="rect-merito__delta">=</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-nombre">
                                    <?= e($est['apellido_paterno'] . ' ' .
                                        $est['apellido_materno'] . ', ' .
                                        $est['nombres']) ?>
                                </td>
                                <td class="text-center"><?= e($est['seccion_nombre']) ?></td>
                                <td class="text-center"><?= (int) $est['num_competencias'] ?></td>
                                <td class="text-center"><?= (int) $est['total_notas'] ?></td>
                                <td class="text-center">
                                    <strong><?= sprintf('%05.2f', $est['promedio_general']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($est['media_beca']): ?>
                                        <span class="badge badge--activo">🏆 Media beca</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

<?php endif; ?>
