<?php
/**
 * Vista: Acta de resoluciones de desempate — consulta en pantalla.
 *
 * @var array $periodo       { id, anio, nombre_display }
 * @var array $resoluciones  [ { grado_nombre, nivel_nombre, motivo, resuelto_en,
 *                               resuelto_por_nombre, alumnos[ {orden_manual, apellido_*,
 *                               nombres, seccion_nombre, promedio_general, num_*,
 *                               puesto_grado} ], criterios[], comparativo, expediente } ]
 */
$fmtFecha = static function (?string $f): string {
    if (empty($f)) {
        return '—';
    }
    try {
        return (new DateTime($f))->format('d/m/Y H:i');
    } catch (\Exception $e) {
        return e($f);
    }
};
?>

<div class="page-header">
    <a href="<?= url('director/orden-merito/' . $periodo['id']) ?>"
       class="btn btn--secondary btn--sm">← Volver al ranking</a>
    <div>
        <h1 class="page-title">Acta de desempates</h1>
        <p class="page-subtitle">
            <?= e($periodo['nombre_display']) ?> — <?= e($periodo['anio']) ?>
        </p>
    </div>
    <?php if (!empty($resoluciones)): ?>
        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempates/imprimir') ?>"
           class="btn btn--primary btn--sm" target="_blank">
            🖨 Imprimir acta
        </a>
    <?php endif; ?>
</div>

<div class="card mb-lg">
    <div class="card__body">
        <p class="text-muted">
            Estas son las decisiones tomadas para dirimir los empates irreducibles del orden
            de mérito: casos en que la cascada automática (promedio exacto → menos C → menos B
            → más AD → más notas 15-16 → más notas 16) no separó a los alumnos, o tenían distinto
            número de competencias por exoneración. Cada resolución indica el orden asignado, quién la tomó y el motivo
            registrado, como garantía frente a docentes y padres de familia.
        </p>
    </div>
</div>

<?php if (empty($resoluciones)): ?>
    <div class="empty-state">
        <p>No se registraron resoluciones de empate en este periodo.</p>
    </div>
<?php else: ?>

    <?php foreach ($resoluciones as $res): ?>
        <div class="card mb-lg">
            <div class="card__header card__header--between">
                <h2 class="card__title">
                    <?= e($res['nivel_nombre']) ?> — <?= e($res['grado_nombre']) ?>
                </h2>
                <span class="badge badge--activo">✓ Resuelto</span>
            </div>

            <div class="card__body">
                <p class="acta-meta">
                    Resuelto por <strong><?= e($res['resuelto_por_nombre']) ?></strong>
                    · <?= $fmtFecha($res['resuelto_en']) ?>
                </p>

                <div class="tabla-responsive">
                <table class="tabla-ranking">
                    <thead>
                        <tr>
                            <th class="col-puesto text-center">Orden</th>
                            <th class="col-nombre">Apellidos y nombres</th>
                            <th class="text-center">Sección</th>
                            <th class="text-center">Comp.</th>
                            <th class="text-center">Promedio</th>
                            <th class="text-center">AD / B / C</th>
                            <th class="text-center">15-16 / 16</th>
                            <th class="text-center">Puesto en el grado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($res['alumnos'] as $al): ?>
                            <tr>
                                <td class="col-puesto text-center"><strong><?= (int) $al['orden_manual'] ?>°</strong></td>
                                <td class="col-nombre">
                                    <?= e($al['apellido_paterno'] . ' ' .
                                        $al['apellido_materno'] . ', ' . $al['nombres']) ?>
                                </td>
                                <td class="text-center"><?= e($al['seccion_nombre']) ?></td>
                                <td class="text-center">
                                    <?= $al['num_competencias'] !== null ? (int) $al['num_competencias'] : '—' ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($al['promedio_general'] !== null): ?>
                                        <strong><?= number_format((float) $al['promedio_general'], 2) ?></strong>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($al['num_ad'] !== null): ?>
                                        <?= (int) $al['num_ad'] ?> / <?= (int) $al['num_b'] ?> / <?= (int) $al['num_c'] ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?= $al['num_alto'] !== null ? (int) $al['num_alto'] . ' / ' . (int) $al['num_16'] : '—' ?>
                                </td>
                                <td class="text-center">
                                    <?= $al['puesto_grado'] !== null ? (int) $al['puesto_grado'] . '°' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!empty($res['comparativo'])): ?>
                    <h3 class="acta-subtitulo">Detalle por competencia</h3>
                    <p class="acta-aclaratoria">
                        Las filas <span class="acta-aclaratoria__marca">resaltadas</span> son competencias
                        en las que obtuvieron el mismo literal (AD/A/B/C) pero distinta nota numeral:
                        la diferencia existe, pero se compensó en el promedio.
                    </p>
                    <div class="tabla-notas-wrapper">
                        <table class="tabla-ranking tabla-comparativo">
                            <thead>
                                <tr>
                                    <th>Competencia</th>
                                    <?php foreach ($res['alumnos'] as $al): ?>
                                        <th class="text-center">
                                            <?= (int) $al['orden_manual'] ?>° <?= e($al['apellido_paterno']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($res['comparativo'] as $row): ?>
                                    <tr class="<?= !empty($row['resaltar']) ? 'fila-resaltada' : '' ?>">
                                        <td><?= e($row['label']) ?></td>
                                        <?php foreach ($res['alumnos'] as $al): ?>
                                            <?php
                                            $mid = (int) $al['matricula_id'];
                                            $n   = $row['notas'][$mid] ?? null;
                                            $lit = $row['literales'][$mid] ?? null;
                                            ?>
                                            <td class="text-center">
                                                <?= $n !== null ? (int) $n . ' (' . e($lit) . ')' : '—' ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php
                $exp      = $res['expediente'] ?? [];
                $columnas = array_map(static fn($al) => [
                    'mid'      => (int) $al['matricula_id'],
                    'etiqueta' => (int) $al['orden_manual'] . '° ' . $al['apellido_paterno'],
                ], $res['alumnos']);
                $modo     = 'pantalla';
                require VIEW_PATH . '/director/_desempate-expediente.php';
                ?>

                <div class="acta-motivo">
                    <span class="acta-motivo__label">Criterios aplicados</span>
                    <p class="acta-motivo__texto">
                        <?= !empty($res['criterios']) ? e(implode(' · ', $res['criterios'])) : 'No registrados' ?>
                    </p>
                    <span class="acta-motivo__label">Motivo de la decisión</span>
                    <p class="acta-motivo__texto"><?= e($res['motivo']) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
