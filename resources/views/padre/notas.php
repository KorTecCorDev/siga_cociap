<?php
/**
 * @var array       $hijo
 * @var array       $periodo
 * @var array       $areas
 * @var string|null $conducta    Literal de conducta del periodo activo (null = sin nota)
 * @var string      $tokenBoleta Token permanente para el enlace público de la boleta
 */
?>

<div class="page-header">
    <a href="<?= url('padre/inicio') ?>" class="btn btn--secondary btn--sm">
        ← Volver
    </a>
    <div>
        <h1 class="page-title">Notas de <?= e($hijo['nombres']) ?></h1>
        <p class="page-subtitle">
            <?= e($hijo['nivel_nombre']) ?> —
            <?= e($hijo['grado_nombre']) ?> —
            Sección <?= e($hijo['seccion_nombre']) ?> —
            <?= e($periodo['nombre_display']) ?>
        </p>
    </div>
    <div class="btn-group">
        <?php if (!empty($areas)): ?>
        <a href="<?= url('boleta/digital/' . $tokenBoleta) ?>"
           class="btn btn--primary btn--sm"
           target="_blank">
            Ver boleta digital
        </a>
        <a href="<?= url('boleta/ver/' . $tokenBoleta) ?>"
           class="btn btn--secondary btn--sm"
           target="_blank">
            🖨 Imprimir
        </a>
        <?php else: ?>
        <?php // Sin calificaciones oficiales: la boleta saldría vacía. Botones
              // visibles pero inertes; el motivo lo dice el empty-state de abajo
              // (21/09/2026). ?>
        <span class="btn btn--primary btn--sm is-disabled" aria-disabled="true">Ver boleta digital</span>
        <span class="btn btn--secondary btn--sm is-disabled" aria-disabled="true">🖨 Imprimir</span>
        <?php endif; ?>
        <a href="<?= url('padre/orden-merito') ?>" class="btn btn--secondary btn--sm">
            Orden de mérito
        </a>
        <a href="<?= url('padre/ranking-seccion') ?>" class="btn btn--secondary btn--sm">
            Ranking por sección
        </a>
    </div>
</div>

<?php if (empty($areas)): ?>
    <div class="empty-state">
        <p>Aún no hay calificaciones registradas para este periodo.</p>
    </div>
<?php else: ?>

    <?php foreach ($areas as $areaNombre => $competencias): ?>
        <div class="competencia-card mb-md">
            <div class="competencia-card__header">
                <h3 class="competencia-card__nombre"><?= e($areaNombre) ?></h3>
            </div>

            <?php foreach ($competencias as $comp): ?>
                <div class="competencia-card__body">

                    <!-- Nombre de la competencia -->
                    <div class="nota-competencia">
                        <div class="nota-competencia__nombre">
                            <?php if (empty($comp['es_unidocente']) && ($comp['area_tipo'] ?? '') === 'con_subareas' && !empty($comp['subarea_nombre'])): ?>
                                <small class="text-muted"><?= e($comp['subarea_nombre']) ?> — </small>
                            <?php endif; ?>
                            <span class="competencia-card__codigo">
                                <?= e($comp['codigo_minedu'] ?? '') ?>
                            </span>
                            <?= e($comp['competencia_nombre']) ?>
                        </div>

                        <!-- Nota final -->
                        <div class="nota-competencia__resultado">
                            <?php
                            $nota    = $comp['nota_numerica'] ?? null;
                            $literal = $nota !== null
                                ? \App\Models\CalificacionModel::toLiteral((int)$nota)
                                : null;
                            ?>
                            <?php if ($nota !== null): ?>
                                <?php if ($hijo['escala_boleta'] === 'ambas'): ?>
                                    <span class="nota-num"><?= fmt_nota((int)$nota) ?></span>
                                <?php endif; ?>
                                <span class="nota-literal nota-literal--<?= strtolower($literal) ?>">
                                    <?= $literal ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">Sin nota</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Criterios -->
                    <?php if (!empty($comp['criterios'])): ?>
                        <table class="tabla-criterios">
                            <thead>
                                <tr>
                                    <th>Criterio</th>
                                    <th class="text-center">Nota</th>
                                    <?php if ($hijo['escala_boleta'] === 'ambas'): ?>
                                        <th class="text-center">Literal</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comp['criterios'] as $criterio): ?>
                                    <tr>
                                        <td>
                                            <?= e($criterio['criterio_nombre']) ?>
                                            <?php if (!empty($criterio['criterio_descripcion'])): ?>
                                                <span class="criterio-desc">
                                                    <?= e($criterio['criterio_descripcion']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $criterio['nota'] !== null ? fmt_nota((int)$criterio['nota']) : '—' ?>
                                        </td>
                                        <?php if ($hijo['escala_boleta'] === 'ambas'): ?>
                                            <td class="text-center">
                                                <?php if ($criterio['nota'] !== null): ?>
                                                    <?= \App\Models\CalificacionModel::toLiteral(
                                                        (int)$criterio['nota']
                                                    ) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <!-- Conclusión descriptiva -->
                    <?php if (!empty($comp['conclusion_descriptiva'])): ?>
                        <div class="conclusion">
                            <span class="conclusion__label">
                                Conclusión descriptiva:
                            </span>
                            <?= e($comp['conclusion_descriptiva']) ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php if ($conducta !== null): ?>
<div class="competencia-card mb-md competencia-card--conducta">
    <div class="competencia-card__header">
        <h3 class="competencia-card__nombre">Conducta</h3>
    </div>
    <div class="competencia-card__body">
        <div class="nota-competencia">
            <div class="nota-competencia__nombre">
                Comportamiento — <?= e($periodo['nombre_display']) ?>
            </div>
            <div class="nota-competencia__resultado">
                <span class="nota-literal nota-literal--<?= strtolower($conducta) ?> nota-literal--lg">
                    <?= e($conducta) ?>
                </span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>