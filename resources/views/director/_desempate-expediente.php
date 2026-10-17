<?php
/**
 * Parcial: EXPEDIENTE de un grupo empatado (asistencia, conducta y boleta).
 * Lo comparten la pantalla de resolución, el acta en pantalla y el acta
 * impresa, para que lo que se vio al decidir sea lo que se archiva (17/10/2026).
 * Los datos salen de `DesempateMeritoModel::expediente()`.
 *
 * @var array  $exp       Resultado de DesempateMeritoModel::expediente()
 * @var array  $columnas  [ ['mid' => int, 'etiqueta' => string] ] en el orden a mostrar
 * @var string $modo      'pantalla' | 'acta'
 */
$esActa    = ($modo ?? 'pantalla') === 'acta';
$tabla     = $esActa ? 'tabla-exp-acta' : 'tabla-ranking tabla-expediente';
$subtitulo = $esActa ? 'acta-resolucion__subtitulo' : 'acta-subtitulo';
$leyenda   = $esActa ? 'acta-resolucion__leyenda' : 'acta-aclaratoria';
$wrapper   = $esActa ? 'expediente-bloque' : 'tabla-notas-wrapper expediente-bloque';

$periodosExp = $exp['periodos'] ?? [];
$disputado   = (int) ($exp['periodo_id'] ?? 0);
$nPer        = count($periodosExp);
$abrev       = static fn(array $p): string => 'B' . (int) $p['numero'];

// Celda de nota: numeral y literal en los dos niveles (la diferencia que el
// literal oculta es justo lo que se está dirimiendo). EXO sin numeral.
$celdaNota = static function (?array $b): string {
    if ($b === null) {
        return '—';
    }
    if ($b['nota'] === null) {
        return !empty($b['literal']) ? e($b['literal']) : '—';
    }
    return (int) $b['nota'] . ' ' . e((string) $b['literal']);
};
$contadores = [
    'faltas'                 => 'F',
    'faltas_justificadas'    => 'FJ',
    'tardanzas'              => 'T',
    'tardanzas_justificadas' => 'TJ',
];
?>

<?php if (!empty($exp['alumnos'])): ?>

<?php /* ── Asistencia ───────────────────────────────────────── */ ?>
<div class="<?= $subtitulo ?>">Asistencia</div>
<p class="<?= $leyenda ?>">
    F: faltas · FJ: faltas justificadas · T: tardanzas · TJ: tardanzas justificadas.
    Cada conteo es independiente. «—»: sin registro de ese bimestre.
</p>
<div class="<?= $wrapper ?>">
<table class="<?= $tabla ?>">
    <thead>
        <tr>
            <th rowspan="2" class="exp-nombre">Estudiante</th>
            <?php foreach ($periodosExp as $p): ?>
                <th colspan="4" class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?>">
                    <?= e($abrev($p)) ?>
                </th>
            <?php endforeach; ?>
            <th colspan="4" class="text-center">Acumulado</th>
        </tr>
        <tr>
            <?php for ($i = 0; $i <= $nPer; $i++): ?>
                <?php foreach ($contadores as $sigla): ?>
                    <th class="text-center"><?= $sigla ?></th>
                <?php endforeach; ?>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($columnas as $col): ?>
            <?php $al = $exp['alumnos'][$col['mid']] ?? null; if ($al === null) { continue; } ?>
            <tr>
                <td class="exp-nombre"><?= e($col['etiqueta']) ?></td>
                <?php foreach ($periodosExp as $p): ?>
                    <?php $b = $al['asistencia']['bimestres'][(int) $p['id']] ?? null; ?>
                    <?php foreach ($contadores as $k => $_): ?>
                        <td class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?>">
                            <?= ($b === null || $b['sin_registro']) ? '—' : (int) $b['datos'][$k] ?>
                        </td>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php foreach ($contadores as $k => $_): ?>
                    <td class="text-center"><strong><?= (int) $al['asistencia']['acumulado'][$k] ?></strong></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php /* ── Conducta ─────────────────────────────────────────── */ ?>
<div class="<?= $subtitulo ?>">Conducta</div>
<div class="<?= $wrapper ?>">
<table class="<?= $tabla ?>">
    <thead>
        <tr>
            <th class="exp-nombre">Estudiante</th>
            <?php foreach ($periodosExp as $p): ?>
                <th class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?>"><?= e($abrev($p)) ?></th>
            <?php endforeach; ?>
            <th class="text-center">Criterios cumplidos</th>
            <th>Criterios no cumplidos</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($columnas as $col): ?>
            <?php $al = $exp['alumnos'][$col['mid']] ?? null; if ($al === null) { continue; } ?>
            <?php $d = $al['conducta_detalle']; ?>
            <tr>
                <td class="exp-nombre"><?= e($col['etiqueta']) ?></td>
                <?php foreach ($periodosExp as $p): ?>
                    <td class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?>">
                        <?= isset($al['conducta'][(int) $p['id']]) ? e($al['conducta'][(int) $p['id']]) : '—' ?>
                    </td>
                <?php endforeach; ?>
                <td class="text-center">
                    <?= $d['visible'] ? (int) $d['cumplidos'] . ' de ' . (int) $d['total'] : '—' ?>
                </td>
                <td class="exp-criterios">
                    <?php if (!$d['visible']): ?>
                        —
                    <?php elseif ($d['no_cumplidos'] === []): ?>
                        Ninguno
                    <?php else: ?>
                        <?php foreach ($d['no_cumplidos'] as $nc): ?>
                            <span class="exp-criterio"><strong><?= e($nc['codigo']) ?></strong> <?= e($nc['texto']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($d['visible'] && $d['sin_respuesta'] > 0): ?>
                        <span class="exp-criterio">Sin respuesta: <?= (int) $d['sin_respuesta'] ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="<?= $leyenda ?>">
    Literal de conducta por bimestre y, del bimestre en disputa, los criterios cumplidos.
    «—»: sin registro de ese bimestre.
</p>

<?php /* ── Boleta: trayectoria por competencia ─────────────── */ ?>
<div class="<?= $subtitulo ?>">Boleta de notas (hasta el bimestre en disputa)</div>
<p class="<?= $leyenda ?>">
    Nota numeral y literal de cada competencia por bimestre, tal como figura en la boleta.
    EXO: área exonerada. «—»: sin calificación. Las notas que forman el promedio del
    orden de mérito son las del detalle por competencia.
</p>
<div class="<?= $wrapper ?>">
<table class="<?= $tabla ?> tabla-exp-boleta">
    <thead>
        <tr>
            <th rowspan="2" class="exp-nombre">Competencia</th>
            <?php foreach ($columnas as $col): ?>
                <th colspan="<?= $nPer ?>" class="text-center exp-grupo"><?= e($col['etiqueta']) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($columnas as $col): ?>
                <?php foreach ($periodosExp as $i => $p): ?>
                    <th class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?><?= $i === 0 ? ' exp-grupo' : '' ?>">
                        <?= e($abrev($p)) ?>
                    </th>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($exp['competencias'] as $area => $comps): ?>
            <tr class="exp-area">
                <td colspan="<?= 1 + $nPer * count($columnas) ?>"><?= e($area) ?></td>
            </tr>
            <?php foreach ($comps as $cid => $nombre): ?>
                <tr>
                    <td class="exp-nombre"><?= e($nombre) ?></td>
                    <?php foreach ($columnas as $col): ?>
                        <?php $al = $exp['alumnos'][$col['mid']] ?? null; ?>
                        <?php foreach ($periodosExp as $i => $p): ?>
                            <?php $b = $al['areas'][$area][$cid][(int) $p['id']] ?? null; ?>
                            <td class="text-center<?= (int) $p['id'] === $disputado ? ' exp-disputado' : '' ?><?= $i === 0 ? ' exp-grupo' : '' ?>">
                                <?= $celdaNota($b) ?>
                            </td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php endif; ?>
