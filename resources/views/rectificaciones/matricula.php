<?php
/**
 * Competencias rectificables de una matrícula, agrupadas por bimestre.
 * @var array $info           datos del estudiante (incl. nivel_codigo)
 * @var array $porPeriodo     [{periodo_id, periodo_nombre, periodo_estado, items[]}]
 * @var array $porPeriodoIns  competencias SIN nota del alumno (solo bimestres cerrados)
 *                            → candidatas a calificación EXTRAORDINARIA
 * @var array $historial      rectificaciones previas de esta matrícula
 */
$esPrimaria    = ($info['nivel_codigo'] ?? '') === 'prim';
$porPeriodoIns = $porPeriodoIns ?? [];

/** Etiqueta de competencia: antepone la subárea en áreas con subáreas. */
$labelComp = static function (array $c): string {
    $nombre = $c['nombre_corto'] ?: $c['competencia_nombre'];
    if (($c['area_tipo'] ?? '') === 'con_subareas' && !empty($c['subarea_nombre'])) {
        return $c['subarea_nombre'] . ' — ' . $nombre;
    }
    return $nombre;
};
?>

<div class="page-header">
    <a href="<?= url('rectificaciones') ?>" class="btn btn--secondary btn--sm">← Buscar otro</a>
    <div>
        <h1 class="page-title"><?= e($info['nombre_completo']) ?></h1>
        <p class="page-subtitle">
            DNI <?= e($info['dni']) ?> ·
            <?= e($info['nivel_nombre']) ?> ·
            <?= e($info['grado_nombre']) ?> "<?= e($info['seccion_nombre']) ?>"
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <p class="rect-aviso">
            Solo aparecen las competencias <strong>bloqueadas o de un bimestre cerrado</strong>
            .
        </p>
    </div>
</div>

<?php if (empty($porPeriodo)): ?>
    <div class="empty-state">
        <p>Este estudiante no tiene competencias rectificables (ninguna nota cerrada o bloqueada).</p>
    </div>
<?php else: ?>
    <?php foreach ($porPeriodo as $per): ?>
    <div class="card mb-md">
        <div class="card__body">
            <p class="form-section-title">
                <?= e($per['periodo_nombre'] ?? ('Bimestre ' . $per['periodo_numero'])) ?>
                <?php if ($per['periodo_estado'] === 'cerrado'): ?>
                    <span class="rect-chip rect-chip--cerrado">Cerrado</span>
                <?php endif; ?>
            </p>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Área / Competencia</th>
                            <th class="text-center">Nota actual</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($per['items'] as $c):
                            $nota    = $c['nota_numerica'] !== null ? (int) $c['nota_numerica'] : null;
                            $literal = $nota !== null ? nota_a_literal($nota) : '—';
                            $tieneCriterios = (int) $c['tiene_criterios'] > 0;
                            $urlEditar = url('rectificaciones/editar?matricula=' . (int) $info['matricula_id']
                                . '&carga=' . (int) $c['carga_id']
                                . '&competencia=' . (int) $c['competencia_id']
                                . '&periodo=' . (int) $c['periodo_id']);
                        ?>
                        <tr>
                            <td>
                                <div class="rect-comp__area"><?= e($c['nombre_boleta'] ?: $c['area_nombre'] ?: '—') ?></div>
                                <div class="rect-comp__nombre"><?= e($labelComp($c)) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($esPrimaria): ?>
                                    <strong><?= e($literal) ?></strong>
                                <?php else: ?>
                                    <strong><?= fmt_nota($nota) ?></strong> · <?= e($literal) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int) $c['bloqueada'] === 1): ?>
                                    <span class="rect-chip rect-chip--bloqueada">Bloqueada</span>
                                <?php else: ?>
                                    <span class="rect-chip rect-chip--cerrado">Bimestre cerrado</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($tieneCriterios): ?>
                                    <a href="<?= $urlEditar ?>" class="btn btn--primary btn--sm">Rectificar</a>
                                <?php else: ?>
                                    <span class="text-sm text-muted" title="Sin criterios registrados: no se puede rectificar por criterio.">Sin criterios</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($conductaRect)): ?>
    <div class="card mb-md">
        <div class="card__body">
            <p class="form-section-title">Conducta</p>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Bimestre</th>
                            <th class="text-center">Literal actual</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conductaRect as $c): ?>
                        <tr>
                            <td><?= e($c['periodo_nombre'] ?? ('Bimestre ' . $c['periodo_numero'])) ?></td>
                            <td class="text-center"><strong><?= e($c['literal'] ?? '—') ?></strong></td>
                            <td class="text-center">
                                <?php if ($c['periodo_estado'] === 'cerrado'): ?>
                                    <span class="rect-chip rect-chip--cerrado">Bimestre cerrado</span>
                                <?php else: ?>
                                    <span class="rect-chip rect-chip--bloqueada">Bloqueada</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= url('rectificaciones/conducta?matricula=' . (int) $info['matricula_id'] . '&periodo=' . (int) $c['periodo_id']) ?>"
                                   class="btn btn--primary btn--sm">Rectificar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($asistenciaRect)): ?>
    <div class="card mb-md">
        <div class="card__body">
            <p class="form-section-title">Asistencia</p>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Bimestre</th>
                            <th class="text-center">Faltas</th>
                            <th class="text-center">Faltas justificadas</th>
                            <th class="text-center">Tardanzas</th>
                            <th class="text-center">Tardanzas justificadas</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($asistenciaRect as $a): ?>
                        <tr>
                            <td><?= e($a['periodo_nombre'] ?? ('Bimestre ' . $a['periodo_numero'])) ?></td>
                            <?php foreach (\App\Models\AsistenciaModel::CAMPOS as $campo): ?>
                                <td class="text-center"><?= (int) $a['contadores'][$campo] ?></td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php if ($a['periodo_estado'] === 'cerrado'): ?>
                                    <span class="rect-chip rect-chip--cerrado">Bimestre cerrado</span>
                                <?php else: ?>
                                    <span class="rect-chip rect-chip--bloqueada">Bloqueada</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= url('rectificaciones/asistencia?matricula=' . (int) $info['matricula_id'] . '&periodo=' . (int) $a['periodo_id']) ?>"
                                   class="btn btn--primary btn--sm">Rectificar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($porPeriodoIns)): ?>
    <div class="card mb-md">
        <div class="card__body">
            <p class="form-section-title">
                Competencias sin calificación — calificación extraordinaria
            </p>
            <p class="rect-aviso mb-md">
                Este estudiante <strong>no tiene nota</strong> en estas competencias
                de bimestres cerrados. Puedes registrarle una
                <strong>calificación extraordinaria</strong> (con motivo obligatorio):
                aparece en la boleta y se exporta al SIAGIE, pero
                <strong>no cuenta para el orden de mérito</strong>.
            </p>
            <?php foreach ($porPeriodoIns as $per): ?>
                <p class="form-section-title">
                    <?= e($per['periodo_nombre'] ?? ('Bimestre ' . $per['periodo_numero'])) ?>
                    <?php if ($per['periodo_estado'] === 'cerrado'): ?>
                        <span class="rect-chip rect-chip--cerrado">Cerrado</span>
                    <?php endif; ?>
                </p>
                <div class="rect-lote-cta">
                    <a href="<?= url('rectificaciones/extraordinaria/lote?matricula=' . (int) $info['matricula_id'] . '&periodo=' . (int) $per['periodo_id']) ?>"
                       class="btn btn--primary btn--sm">
                        Calificar todo el bimestre (<?= count($per['items']) ?>)
                    </a>
                    <span class="rect-aviso">
                        Una sola pantalla con las <?= count($per['items']) ?> competencias y un
                        motivo común. Las que dejes vacías no se registran.
                    </span>
                </div>
                <div class="tabla-notas-wrapper">
                    <table class="tabla-notas">
                        <thead>
                            <tr>
                                <th>Área / Competencia</th>
                                <th class="text-center">Situación</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($per['items'] as $c):
                                $noTrabajada = (int) $c['notas_seccion'] === 0;
                                $urlExtra = url('rectificaciones/extraordinaria?matricula=' . (int) $info['matricula_id']
                                    . '&carga=' . (int) $c['carga_id']
                                    . '&competencia=' . (int) $c['competencia_id']
                                    . '&periodo=' . (int) $c['periodo_id']);
                            ?>
                            <tr>
                                <td>
                                    <div class="rect-comp__area"><?= e($c['nombre_boleta'] ?: $c['area_nombre'] ?: '—') ?></div>
                                    <div class="rect-comp__nombre"><?= e($labelComp($c)) ?></div>
                                </td>
                                <td class="text-center text-sm">
                                    <?php if ($noTrabajada): ?>
                                        No trabajada por el docente (sección sin notas)
                                    <?php else: ?>
                                        Sin nota individual (la sección sí tiene notas)
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int) $c['bloqueada'] === 1): ?>
                                        <span class="rect-chip rect-chip--bloqueada">Bloqueada</span>
                                    <?php else: ?>
                                        <span class="rect-chip rect-chip--cerrado">Bimestre cerrado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="<?= $urlExtra ?>" class="btn btn--primary btn--sm">Calificar</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($historial)): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Historial de rectificaciones de este estudiante</p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Competencia</th>
                        <th>Bimestre</th>
                        <th class="text-center">Antes</th>
                        <th class="text-center">Después</th>
                        <th>Motivo</th>
                        <th>Rectificó</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $h): ?>
                    <tr>
                        <td class="text-sm"><?= e(fecha_es(substr((string) $h['rectificado_en'], 0, 10))) ?></td>
                        <td class="text-sm">
                            <?= e($h['competencia_nombre'] ?? '—') ?>
                            <?php if (($h['tipo'] ?? 'rectificacion') === 'extraordinaria'): ?>
                                <span class="rect-chip rect-chip--extraordinaria">Extraordinaria</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($h['periodo_nombre'] ?? '—') ?></td>
                        <td class="text-center"><?= fmt_nota($h['nota_anterior'] !== null ? (int) $h['nota_anterior'] : null) ?></td>
                        <td class="text-center"><strong><?= fmt_nota($h['nota_nueva'] !== null ? (int) $h['nota_nueva'] : null) ?></strong></td>
                        <td class="text-sm rect-motivo"><?= e($h['motivo']) ?></td>
                        <td class="text-sm"><?= e($h['rectificador'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$marcaTexto = static fn(string $m): string => [
    'sin_tomar' => 'Sin tomar', 'asistio' => 'Asistió',
][$m] ?? $m;
$histCond = $historialReg['conducta'] ?? [];
$histAsis = $historialReg['asistencia'] ?? [];
?>

<?php if (!empty($histCond)): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Historial de rectificaciones de conducta</p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Bimestre</th>
                        <th>Criterio</th>
                        <th class="text-center">Antes</th>
                        <th class="text-center">Después</th>
                        <th class="text-center">Literal</th>
                        <th>Motivo</th>
                        <th>Rectificó</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($histCond as $h): ?>
                    <tr>
                        <td class="text-sm"><?= e(fecha_es(substr((string) $h['rectificado_en'], 0, 10))) ?></td>
                        <td class="text-sm"><?= e($h['periodo_nombre'] ?? '—') ?></td>
                        <td class="text-sm"><strong><?= e($h['criterio_codigo'] ?? '') ?></strong> <?= e($h['criterio_texto'] ?? '') ?></td>
                        <td class="text-center"><?= (int) $h['respuesta_anterior'] === 1 ? 'Sí' : 'No' ?></td>
                        <td class="text-center"><strong><?= (int) $h['respuesta_nueva'] === 1 ? 'Sí' : 'No' ?></strong></td>
                        <td class="text-center"><?= e($h['literal_anterior'] ?? '—') ?> → <strong><?= e($h['literal_nuevo'] ?? '—') ?></strong></td>
                        <td class="text-sm rect-motivo"><?= e($h['motivo']) ?></td>
                        <td class="text-sm"><?= e($h['rectificador'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($histAsis)): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Historial de rectificaciones de asistencia</p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Bimestre</th>
                        <th>Día</th>
                        <th class="text-center">Antes</th>
                        <th class="text-center">Después</th>
                        <th>Motivo</th>
                        <th>Rectificó</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($histAsis as $h): ?>
                    <tr>
                        <td class="text-sm"><?= e(fecha_es(substr((string) $h['rectificado_en'], 0, 10))) ?></td>
                        <td class="text-sm"><?= e($h['periodo_nombre'] ?? '—') ?></td>
                        <td class="text-sm"><?= e(fecha_es((string) $h['fecha'])) ?></td>
                        <td class="text-center text-sm">
                            <?= e($marcaTexto((string) $h['marca_anterior'])) ?>
                            <?php if (!empty($h['motivo_anterior'])): ?>
                                <span class="text-muted">(<?= e($h['motivo_anterior']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-sm">
                            <strong><?= e($marcaTexto((string) $h['marca_nueva'])) ?></strong>
                            <?php if (!empty($h['motivo_nuevo'])): ?>
                                <span class="text-muted">(<?= e($h['motivo_nuevo']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm rect-motivo"><?= e($h['motivo']) ?></td>
                        <td class="text-sm"><?= e($h['rectificador'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
