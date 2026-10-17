<?php
/**
 * @var array $periodo
 * @var array $ranking
 */
?>

<div class="page-header">
    <a href="<?= url('director/orden-merito') ?>"
       class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Orden de mérito</h1>
        <p class="page-subtitle">
            <?= e($periodo['nombre_display']) ?> — <?= e($periodo['anio']) ?>
        </p>
    </div>
    <div class="btn-group">
        <a href="<?= url('director/ranking-seccion/' . $periodo['id']) ?>"
           class="btn btn--secondary btn--sm">
            Ranking por sección
        </a>
        <?php if (!empty($tieneDesempates)): ?>
            <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempates') ?>"
               class="btn btn--secondary btn--sm">
                📋 Acta de desempates
            </a>
        <?php endif; ?>
        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/imprimir') ?>"
           class="btn btn--primary btn--sm" target="_blank">
            🖨 Imprimir reporte
        </a>
    </div>
</div>

<?php if (empty($ranking)): ?>
    <div class="empty-state">
        <p>No hay calificaciones registradas en este periodo.</p>
    </div>
<?php else: ?>

    <?php $gradoIdx = 0; foreach ($ranking as $gradoId => $data): $gradoIdx++; ?>
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

                <?php
                $hayPendientes = false;
                foreach ($data['estudiantes'] as $est) {
                    if (!empty($est['empate_pendiente'])) { $hayPendientes = true; break; }
                }
                ?>
                <?php if ($hayPendientes): ?>
                    <div class="alerta-empate">
                        <span class="alerta-empate__texto">
                            ⚠ Hay un empate irreducible en este grado: ni los criterios de
                            regularidad alta (notas 15-16 y 16) lo separan. El puesto en disputa
                            debe resolverlo la Dirección, Registro Académico o Administración.
                        </span>
                        <?php /* El AVISO lo ve todo el mundo —el director necesita
                                 saber que el grado tiene un empate sin resolver—;
                                 el boton que RESUELVE, solo quien escribe. Desde el
                                 17/10/2026 la Direccion entra al mismo expediente en
                                 solo lectura (sin formulario). */ ?>
                        <?php if ($puedeEscribir): ?>
                        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempate/' . $data['grado']['id']) ?>"
                           class="btn btn--primary btn--sm">Resolver empate</a>
                        <?php else: ?>
                        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempate/' . $data['grado']['id']) ?>"
                           class="btn btn--secondary btn--sm">Ver expediente</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="tabla-responsive">
                <table class="tabla-ranking">
                    <thead>
                        <tr>
                            <th class="col-puesto text-center">Puesto</th>
                            <th class="col-nombre">Apellidos y nombres</th>
                            <th class="text-center">Sección</th>
                            <th class="text-center">Comp.</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Promedio</th>
                            <th class="text-center">Distinción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['estudiantes'] as $est): ?>
                            <?php $pendiente = !empty($est['empate_pendiente']); ?>
                            <tr class="<?= $est['media_beca'] ? 'fila-media-beca' : '' ?> <?= $pendiente ? 'fila-empate' : '' ?>">
                                <td class="col-puesto text-center">
                                    <span class="puesto puesto--<?= $est['puesto'] <= 3 ? $est['puesto'] : 'normal' ?>">
                                        <?= $est['puesto'] ?>°
                                    </span>
                                </td>
                                <td class="col-nombre">
                                    <?= e($est['apellido_paterno'] . ' ' .
                                        $est['apellido_materno'] . ', ' .
                                        $est['nombres']) ?>
                                </td>
                                <td class="text-center">
                                    <?= e($est['seccion_nombre']) ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $est['num_competencias'] ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $est['total_notas'] ?>
                                </td>
                                <td class="text-center">
                                    <strong><?= sprintf('%05.2f', $est['promedio_general']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($pendiente): ?>
                                        <span class="badge badge--warning">
                                            ⚠ Empate por resolver
                                        </span>
                                    <?php elseif ($est['media_beca']): ?>
                                        <span class="badge badge--activo">
                                            🏆 Media beca
                                        </span>
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