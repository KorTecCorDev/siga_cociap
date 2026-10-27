<?php
/**
 * Registro auxiliar — CONCLUSIONES DESCRIPTIVAS del área (modelo CD del colegio,
 * 27/10/2026). En hoja nueva, después de la matriz. Por estudiante: logro
 * (número y literal) y conclusión de cada competencia APROBADA de las cargas
 * elegidas; de las transversales, solo el logro (su conclusión la registra el
 * tutor). Las competencias de otra carga no aparecen: no hay nada que mostrar.
 *
 * El `<thead>` se repite en cada hoja y cada `<tr>` es indivisible.
 *
 * @var array  $hoja
 * @var array  $periodo
 * @var string $docente
 */
$propias = array_values(array_filter($hoja['columnas'], static fn(array $c): bool => $c['tipo'] === 'propia'));
$transv  = array_values(array_filter($hoja['transversales'], static fn(array $t): bool => $t['evaluada']));
if ($propias === [] || $hoja['roster'] === []) {
    return;
}
foreach ($propias as &$c) {
    $c['exoSet'] = array_flip($c['exo']);
}
unset($c);
foreach ($transv as &$t) {
    $t['exoSet'] = array_flip($t['exo']);
}
unset($t);
$mh = $hoja['meta'];
$identificacion = $hoja['area_nombre']
    . ($hoja['subareas'] !== [] ? ' (' . implode(', ', $hoja['subareas']) . ')' : '')
    . ' · ' . trim($mh['grado_nombre'] . ' ' . $mh['seccion_nombre']) . ' de ' . $mh['nivel_nombre']
    . ' · ' . $periodo['nombre_display'] . ' ' . $periodo['anio'];
$totalColumnas = 2 + 2 * count($propias) + count($transv);
$tituloHoja = 'Registro auxiliar de conclusiones descriptivas';
$notaHoja   = 'Logro del bimestre y conclusión descriptiva';
?>
<div class="ra-cd">
    <?php require VIEW_PATH . '/docente/_registro-auxiliar-cabecera.php'; ?>

    <table class="ra-m ra-cd__tabla">
        <colgroup>
            <col class="ra-m__c-num">
            <col class="ra-cd__c-nombre">
            <?php foreach ($propias as $c): ?>
                <col class="ra-cd__c-logro"><col>
            <?php endforeach; ?>
            <?php foreach ($transv as $t): ?><col class="ra-m__c-tr"><?php endforeach; ?>
        </colgroup>
        <thead>
            <tr class="ra-m__id">
                <th colspan="<?= $totalColumnas ?>">Conclusiones descriptivas &middot; <?= e($identificacion) ?></th>
            </tr>
            <tr class="ra-m__grupos">
                <th rowspan="2" scope="col">N°</th>
                <th rowspan="2" scope="col" class="ra-m__izq">Apellidos y nombres</th>
                <?php foreach ($propias as $c): ?>
                    <th scope="colgroup" colspan="2" class="ra-m__grupo"><?= e((string) $c['codigo']) ?></th>
                <?php endforeach; ?>
                <?php if ($transv !== []): ?>
                    <th scope="colgroup" colspan="<?= count($transv) ?>" class="ra-m__grupo ra-m__grupo--tr">Comp. transv.</th>
                <?php endif; ?>
            </tr>
            <tr class="ra-m__cols">
                <?php foreach ($propias as $c): ?>
                    <th scope="col">Logro</th>
                    <th scope="col" class="ra-m__izq">Conclusión descriptiva</th>
                <?php endforeach; ?>
                <?php foreach ($transv as $t): ?>
                    <th scope="col" class="ra-m__tr"><?= e((string) $t['competencia']['codigo_minedu']) ?><?php if ($t['subarea'] !== null): ?><span class="ra-m__sub"><?= e($t['subarea']) ?></span><?php endif; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($hoja['roster'] as $i => $al):
    $mid = (int) $al['matricula_id']; ?>
<tr>
<td class="ra-m__num"><?= $i + 1 ?></td>
<td class="ra-m__izq"><?= e($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ', ' . $al['nombres']) ?></td>
<?php foreach ($propias as $c):
    $a   = $c['alumnos'][$mid] ?? null;
    $exo = isset($c['exoSet'][$mid]); ?>
<td class="ra-cd__logro"><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['promedio'] !== null): ?><span class="nota-numeral nota-numeral--<?= e(strtolower((string) $a['literal'])) ?>"><?= fmt_nota((int) $a['promedio']) ?></span> <span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span><?php else: ?>—<?php endif; ?></td>
<td class="ra-m__izq ra-cd__texto"><?php if ($exo): ?><span class="ra-m__muted">Exonerado(a) — no aplica</span><?php elseif (!empty($a['conclusion_descriptiva'])): ?><?= e($a['conclusion_descriptiva']) ?><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<?php foreach ($transv as $t):
    $a   = $t['alumnos'][$mid] ?? null;
    $exo = isset($t['exoSet'][$mid]); ?>
<td class="ra-m__tr"><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['promedio'] !== null): ?><span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($transv !== []): ?>
        <p class="ra-cd__nota">La conclusión descriptiva de las competencias transversales la registra el tutor de la sección.</p>
    <?php endif; ?>
</div>
