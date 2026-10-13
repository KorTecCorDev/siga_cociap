<?php
/**
 * Rectificación de ASISTENCIA (F5, 13/10/2026): la marca de UN día de un
 * bimestre por fechas. Los contadores los recalcula el sistema.
 * @var array      $info        datos del estudiante
 * @var array      $periodo     fila de `periodos`
 * @var array      $dias        [fecha => {marca: sin_tomar|asistio|F|FJ|T|TJ, motivo: ?string}]
 * @var array      $contadores  AsistenciaModel::contadoresDe
 * @var array      $motivos     motivos vigentes (id, nombre, es_principal)
 * @var array|null $old         lo enviado antes de un rechazo (fecha, marca, motivo_id, motivo)
 */
$old    = is_array($old ?? null) ? $old : null;
$volver = url('rectificaciones/matricula/' . (int) $info['matricula_id']);

$diasSemana = [1 => 'Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.', 'Dom.'];
$fechaCorta = static function (string $f) use ($diasSemana): string {
    $ts = strtotime($f);
    return $diasSemana[(int) date('N', $ts)] . ' ' . date('d/m', $ts);
};
$textoMarca = static fn(string $m): string => [
    'sin_tomar' => 'Sin tomar', 'asistio' => 'Asistió',
][$m] ?? $m;
$marcas = [
    'asistio' => 'Asistió',
    'F'       => 'F — Falta',
    'FJ'      => 'FJ — Falta justificada',
    'T'       => 'T — Tardanza',
    'TJ'      => 'TJ — Tardanza justificada',
];
$conMarca = array_filter($dias, static fn(array $d): bool => $d['marca'] !== 'asistio');
$etiquetas = [
    'faltas' => 'Faltas', 'faltas_justificadas' => 'Faltas justificadas',
    'tardanzas' => 'Tardanzas', 'tardanzas_justificadas' => 'Tardanzas justificadas',
];
?>

<div class="page-header">
    <a href="<?= $volver ?>" class="btn btn--secondary btn--sm">← Cancelar</a>
    <div>
        <h1 class="page-title">Rectificar asistencia</h1>
        <p class="page-subtitle">
            <?= e($info['nombre_completo']) ?> ·
            <?= e($info['grado_nombre']) ?> "<?= e($info['seccion_nombre']) ?>" ·
            <?= e($periodo['nombre_display'] ?? '') ?>
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <div class="info-grid">
            <?php foreach ($etiquetas as $campo => $txt): ?>
                <div class="info-item">
                    <span class="info-item__label"><?= e($txt) ?></span>
                    <span class="info-item__value"><?= (int) ($contadores[$campo] ?? 0) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($conMarca !== []): ?>
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Días con incidencia o sin tomar</p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Día</th>
                        <th class="text-center">Marca</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($conMarca as $f => $d): ?>
                    <tr>
                        <td><?= e($fechaCorta($f)) ?></td>
                        <td class="text-center">
                            <?php if (in_array($d['marca'], ['F', 'FJ', 'T', 'TJ'], true)): ?>
                                <span class="af-tipo af-tipo--<?= strtolower($d['marca']) ?>"><?= e($d['marca']) ?></span>
                            <?php else: ?>
                                <span class="text-muted"><?= e($textoMarca($d['marca'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($d['motivo'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="<?= url('rectificaciones/asistencia/guardar') ?>" class="card"
      id="rectAsistenciaForm">
    <div class="card__body">
        <?= csrf_field() ?>
        <input type="hidden" name="matricula_id" value="<?= (int) $info['matricula_id'] ?>">
        <input type="hidden" name="periodo_id"   value="<?= (int) $periodo['id'] ?>">

        <p class="form-section-title">Rectificar un día</p>

        <div class="rect-asis__campos">
            <div class="form-group">
                <label class="form-label" for="fecha">Día <span class="text-danger">*</span></label>
                <select id="fecha" name="fecha" class="form-input" required>
                    <option value="">Elige el día</option>
                    <?php foreach ($dias as $f => $d): ?>
                        <option value="<?= e($f) ?>" <?= ($old['fecha'] ?? '') === $f ? 'selected' : '' ?>>
                            <?= e($fechaCorta($f)) ?> — <?= e($textoMarca($d['marca'])) ?><?= $d['motivo'] ? ' (' . e($d['motivo']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="marca">Nueva marca <span class="text-danger">*</span></label>
                <select id="marca" name="marca" class="form-input" required>
                    <option value="">Elige la marca</option>
                    <?php foreach ($marcas as $valor => $txt): ?>
                        <option value="<?= e($valor) ?>" <?= ($old['marca'] ?? '') === $valor ? 'selected' : '' ?>><?= e($txt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" data-rect-motivo-just hidden>
                <label class="form-label" for="motivo_id">Motivo de la justificación <span class="text-danger">*</span></label>
                <select id="motivo_id" name="motivo_id" class="form-input">
                    <option value="">Elige el motivo</option>
                    <?php foreach ($motivos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) ($old['motivo_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['nombre']) ?><?= (int) $m['es_principal'] === 1 ? ' (principal)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="motivo">Motivo de la rectificación <span class="text-danger">*</span></label>
            <textarea id="motivo" name="motivo" class="form-input" rows="3" required
                      placeholder="Fundamenta el porqué de la corrección."><?= e((string) ($old['motivo'] ?? '')) ?></textarea>
        </div>

        <div class="btn-group form-actions">
            <a href="<?= $volver ?>" class="btn btn--secondary">Cancelar</a>
            <button type="submit" class="btn btn--primary">Aplicar rectificación</button>
        </div>
    </div>
</form>
