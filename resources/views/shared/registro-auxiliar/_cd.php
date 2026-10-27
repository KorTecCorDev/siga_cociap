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
 * PARTES (27/10/2026): como máximo `CD_COMPETENCIAS_POR_PARTE` competencias por
 * tabla (`partesConclusiones`), cada parte en hoja nueva con «Parte X de Y»; las
 * transversales, en la última.
 *
 * @var array  $hoja
 * @var array  $periodo
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
$partes  = \App\Models\RegistroAuxiliarModel::partesConclusiones($propias, count($transv));
$nPartes = count($partes);
$tituloHoja = 'Registro auxiliar de conclusiones descriptivas';
$notaHoja   = 'Logro del bimestre y conclusión descriptiva';
?>
<div class="ra-cd">
    <?php require VIEW_PATH . '/shared/registro-auxiliar/_cabecera.php'; ?>

    <?php foreach ($partes as $k => $parte):
        $propParte = [];
        foreach ($parte['columnas'] as $i) {
            $propParte[] = $propias[$i];
        }
        $trParte = $parte['final'] ? $transv : [];
        // Logro = 2 columnas (numeral | literal, cada uno centrado en la suya y sin
        // línea entre ellas, 27/10/2026) + la conclusión.
        $totalColumnas = 2 + 3 * count($propParte) + count($trParte);
        // Transversales de varias cargas: una fila más con el código de la
        // competencia de cada carga sobre sus CT, como en la matriz.
        $trGrupos   = \App\Models\RegistroAuxiliarModel::gruposTransversales($trParte);
        $filasGrupo = $trGrupos !== [] ? 2 : 1; ?>
    <table class="ra-m ra-cd__tabla ra-parte">
        <colgroup>
            <col class="ra-m__c-num">
            <col class="ra-cd__c-nombre">
            <?php foreach ($propParte as $c): ?>
                <col class="ra-cd__c-num"><col class="ra-cd__c-lit"><col>
            <?php endforeach; ?>
            <?php foreach ($trParte as $t): ?><col class="ra-m__c-tr"><?php endforeach; ?>
        </colgroup>
        <thead>
            <tr class="ra-m__id">
                <th colspan="<?= $totalColumnas ?>">Conclusiones descriptivas &middot; <?= e($identificacion) ?><?php if ($nPartes > 1): ?> &middot; <span class="ra-m__parte">Parte <?= $k + 1 ?> de <?= $nPartes ?></span><?php endif; ?></th>
            </tr>
            <tr class="ra-m__grupos">
                <th rowspan="<?= $filasGrupo + 1 ?>" scope="col">N°</th>
                <th rowspan="<?= $filasGrupo + 1 ?>" scope="col" class="ra-m__izq">Apellidos y nombres</th>
                <?php foreach ($propParte as $c): ?>
                    <th scope="colgroup" rowspan="<?= $filasGrupo ?>" colspan="3" class="ra-m__grupo"><?= e((string) $c['codigo']) ?></th>
                <?php endforeach; ?>
                <?php if ($trParte !== []): ?>
                    <th scope="colgroup" colspan="<?= count($trParte) ?>" class="ra-m__grupo ra-m__grupo--tr">Comp. transv.</th>
                <?php endif; ?>
            </tr>
            <?php if ($trGrupos !== []): ?>
                <tr class="ra-m__subgrupos">
                    <?php foreach ($trGrupos as $g): ?>
                        <th scope="colgroup" colspan="<?= $g['span'] ?>" class="ra-m__grupo ra-m__grupo--tr"><?= e($g['grupo']) ?></th>
                    <?php endforeach; ?>
                </tr>
            <?php endif; ?>
            <tr class="ra-m__cols">
                <?php foreach ($propParte as $c): ?>
                    <th scope="colgroup" colspan="2">Logro</th>
                    <th scope="col" class="ra-m__izq">Conclusión descriptiva</th>
                <?php endforeach; ?>
                <?php foreach ($trParte as $t): ?>
                    <th scope="col" class="ra-m__tr"><?= e((string) $t['competencia']['codigo_minedu']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php foreach ($hoja['roster'] as $i => $al):
    $mid = (int) $al['matricula_id']; ?>
<tr>
<td class="ra-m__num"><?= $i + 1 ?></td>
<td class="ra-m__izq"><?= e($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ', ' . $al['nombres']) ?></td>
<?php foreach ($propParte as $c):
    $a   = $c['alumnos'][$mid] ?? null;
    $exo = isset($c['exoSet'][$mid]); ?>
<?php if ($exo): ?>
<td class="ra-cd__logro" colspan="2"><span class="exo-badge">EXO</span></td>
<?php elseif ($a !== null && $a['promedio'] !== null): ?>
<td class="ra-cd__logro ra-cd__num"><span class="nota-numeral nota-numeral--<?= e(strtolower((string) $a['literal'])) ?>"><?= fmt_nota((int) $a['promedio']) ?></span></td>
<td class="ra-cd__logro ra-cd__lit"><span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span></td>
<?php else: ?>
<td class="ra-cd__logro" colspan="2">—</td>
<?php endif; ?>
<td class="ra-m__izq ra-cd__texto"><?php if ($exo): ?><span class="ra-m__muted">Exonerado(a) — no aplica</span><?php elseif (!empty($a['conclusion_descriptiva'])): ?><?= e($a['conclusion_descriptiva']) ?><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<?php foreach ($trParte as $t):
    $a   = $t['alumnos'][$mid] ?? null;
    $exo = isset($t['exoSet'][$mid]); ?>
<td class="ra-m__tr"><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['promedio'] !== null): ?><span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php endforeach; ?>
    <?php if ($transv !== []): ?>
        <p class="ra-cd__nota">La conclusión descriptiva de las competencias transversales la registra el tutor de la sección.</p>
    <?php endif; ?>
</div>
