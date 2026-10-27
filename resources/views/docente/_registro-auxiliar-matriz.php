<?php
/**
 * Registro auxiliar — MATRIZ de un área (modelo REG-NUM del colegio, 27/10/2026).
 *
 * Una fila por estudiante; cada competencia del plan del área es un grupo de
 * columnas: criterios (Cr1…CrN) + LOGRO si es de una carga elegida y está
 * aprobada; UNA columna EN BLANCO si es de otra carga, si aún no se aprueba o si
 * se declaró «No evaluada». Después, RESUMEN (literal por competencia) y COMP-TRA
 * (transversales de cada carga elegida).
 *
 * 🔴 Cortes de hoja: los rótulos de columnas viven en el `<thead>` (se repite en
 * cada hoja; es bajo a propósito: los nombres largos van en la leyenda de arriba,
 * no en la cabecera, o Chrome deja de repetirlo). Cada `<tr>` es indivisible. El
 * pie (estadísticas + lugar y fecha + firmas) es UN bloque indivisible: la firma
 * nunca empieza una hoja sola.
 *
 * @var array  $hoja
 * @var array  $periodo
 * @var string $docente
 * @var array  $firmas
 * @var array  $omisionSiglas
 */
$columnas  = $hoja['columnas'];
$transv    = $hoja['transversales'];
$roster    = $hoja['roster'];
$nivel     = $hoja['meta']['nivel_codigo'];
$propias   = array_values(array_filter($columnas, static fn(array $c): bool => $c['tipo'] === 'propia'));
// Exonerados como conjunto, una vez por columna (se consulta en cada celda).
foreach ($columnas as &$c) {
    $c['exoSet'] = array_flip($c['exo'] ?? []);
}
unset($c);
foreach ($transv as &$t) {
    $t['exoSet'] = array_flip($t['exo']);
}
unset($t);
$codigo    = static fn(array $c, int $i): string => (string) ($c['codigo'] ?: 'Comp. ' . ($i + 1));
$marcas    = [
    'otra'      => 'Otra carga',
    'pendiente' => 'Aún no aprobada',
    'noeval'    => 'No evaluada',
];
// Identificación del área: va en el `<thead>` (se repite en cada hoja) y en el
// pie, para que ninguna hoja suelta quede sin decir de qué área es.
$mh = $hoja['meta'];
$identificacion = $hoja['area_nombre']
    . ($hoja['subareas'] !== [] ? ' (' . implode(', ', $hoja['subareas']) . ')' : '')
    . ' · ' . trim($mh['grado_nombre'] . ' ' . $mh['seccion_nombre']) . ' de ' . $mh['nivel_nombre']
    . ' · ' . $periodo['nombre_display'] . ' ' . $periodo['anio'];
$totalColumnas = 2 + count($columnas) + count($transv);
foreach ($columnas as $c) {
    $totalColumnas += $c['tipo'] === 'propia' ? count($c['criterios']) + 1 : 1;
}
$tituloHoja = 'Registro auxiliar de evaluación de aprendizajes';
$notaHoja   = 'Notas vigesimales de 00 a 20';
require VIEW_PATH . '/docente/_registro-auxiliar-cabecera.php';
?>

<div class="ra-leyenda">
    <div class="ra-leyenda__bloque">
        <p class="ra-leyenda__tit">Competencias del área</p>
        <ul class="ra-leyenda__lista">
            <?php foreach ($columnas as $i => $c): ?>
                <li>
                    <span class="competencia-card__codigo"><?= e($codigo($c, $i)) ?></span>
                    <?= e($c['nombre']) ?>
                    <?php if (isset($marcas[$c['tipo']])): ?><span class="ra-tag ra-tag--<?= e($c['tipo']) ?>"><?= e($marcas[$c['tipo']]) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php foreach ($transv as $t): ?>
                <li>
                    <span class="competencia-card__codigo"><?= e((string) $t['competencia']['codigo_minedu']) ?></span>
                    <?= e($t['competencia']['nombre_completo']) ?>
                    <span class="ra-tag">Transversal<?= $t['subarea'] !== null ? ' · ' . e($t['subarea']) : '' ?></span>
                    <?php if (!$t['evaluada']): ?><span class="ra-tag ra-tag--noeval">No evaluada</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($propias !== []):
        // Tabla a todo el ancho: una fila por competencia, una columna por criterio
        // (Cr1…CrN = los mismos rótulos de la matriz). Como tabla de ancho fijo no
        // se desacomoda con el número ni el largo de los criterios.
        $maxCr = max(array_map(static fn(array $c): int => count($c['criterios']), $propias)); ?>
        <div class="ra-leyenda__bloque ra-leyenda__bloque--ancho">
            <p class="ra-leyenda__tit">Criterios de evaluación</p>
            <table class="ra-crit">
                <colgroup>
                    <col class="ra-crit__c-comp">
                    <?php for ($n = 0; $n < $maxCr; $n++): ?><col><?php endfor; ?>
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Comp.</th>
                        <?php for ($n = 1; $n <= $maxCr; $n++): ?><th scope="col">Cr<?= $n ?></th><?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($propias as $c): ?>
                        <tr>
                            <th scope="row"><span class="competencia-card__codigo"><?= e((string) $c['codigo']) ?></span></th>
                            <?php for ($n = 0; $n < $maxCr; $n++): ?>
                                <?php if (isset($c['criterios'][$n])): ?>
                                    <td><?= e($c['criterios'][$n]['nombre']) ?></td>
                                <?php else: ?>
                                    <td class="ra-crit__vacia"></td>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="ra-leyenda__escala">
        <strong>Escala:</strong>
        <?php foreach (escala_rangos() as $lit => $rango): ?>
            <span class="ra-leyenda__item"><span class="nota-literal nota-literal--<?= e(strtolower($lit)) ?>"><?= e($lit) ?></span> <?= e($rango) ?> (<?= e(descripcion_literal($lit)) ?>)</span>
        <?php endforeach; ?>
        &middot; <strong>Omisiones:</strong>
        <?php foreach ($omisionSiglas as $motivo => $sigla): ?>
            <span class="ra-leyenda__item"><?= e($sigla) ?> = <?= e(\App\Models\OmisionCriterioModel::etiqueta($motivo)) ?></span>
        <?php endforeach; ?>
        &middot; EXO = exonerado(a)
    </p>
</div>

<?php if ($roster === []): ?>
    <p class="ra-print__vacio">
        Esta área aún no tiene competencias aprobadas en el <?= e($periodo['nombre_display']) ?>.
    </p>
<?php else: ?>
    <table class="ra-m">
        <colgroup>
            <col class="ra-m__c-num">
            <col class="ra-m__c-nombre">
            <?php foreach ($columnas as $c): ?>
                <?php if ($c['tipo'] === 'propia'): ?>
                    <?php foreach ($c['criterios'] as $cr): ?><col><?php endforeach; ?>
                    <col class="ra-m__c-logro">
                <?php else: ?>
                    <col class="ra-m__c-vacia">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($columnas as $c): ?><col class="ra-m__c-res"><?php endforeach; ?>
            <?php foreach ($transv as $t): ?><col class="ra-m__c-tr"><?php endforeach; ?>
        </colgroup>
        <thead>
            <tr class="ra-m__id">
                <th colspan="<?= $totalColumnas ?>">Registro auxiliar &middot; <?= e($identificacion) ?></th>
            </tr>
            <tr class="ra-m__grupos">
                <th rowspan="2" scope="col">N°</th>
                <th rowspan="2" scope="col" class="ra-m__izq">Apellidos y nombres</th>
                <?php foreach ($columnas as $i => $c): ?>
                    <th scope="colgroup" colspan="<?= $c['tipo'] === 'propia' ? count($c['criterios']) + 1 : 1 ?>"
                        class="ra-m__grupo<?= $c['tipo'] !== 'propia' ? ' ra-m__grupo--vacio' : '' ?>">
                        <?= e($codigo($c, $i)) ?>
                    </th>
                <?php endforeach; ?>
                <th scope="colgroup" colspan="<?= count($columnas) ?>" class="ra-m__grupo ra-m__grupo--res">Resumen</th>
                <?php if ($transv !== []): ?>
                    <th scope="colgroup" colspan="<?= count($transv) ?>" class="ra-m__grupo ra-m__grupo--tr">Comp. transv.</th>
                <?php endif; ?>
            </tr>
            <tr class="ra-m__cols">
                <?php foreach ($columnas as $c): ?>
                    <?php if ($c['tipo'] === 'propia'): ?>
                        <?php foreach ($c['criterios'] as $n => $cr): ?>
                            <th scope="col" title="<?= e($cr['nombre']) ?>">Cr<?= $n + 1 ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="ra-m__logro">Logro</th>
                    <?php else: ?>
                        <th scope="col" class="ra-m__vacia"></th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($columnas as $i => $c): ?>
                    <th scope="col" class="ra-m__res"><?= e($codigo($c, $i)) ?></th>
                <?php endforeach; ?>
                <?php foreach ($transv as $t): ?>
                    <th scope="col" class="ra-m__tr"><?= e((string) $t['competencia']['codigo_minedu']) ?><?php if ($t['subarea'] !== null): ?><span class="ra-m__sub"><?= e($t['subarea']) ?></span><?php endif; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php // ⚠️ Filas SIN INDENTAR: se repiten por estudiante y columna. ?>
<?php foreach ($roster as $i => $al):
    $mid = (int) $al['matricula_id']; ?>
<tr>
<td class="ra-m__num"><?= $i + 1 ?></td>
<td class="ra-m__izq"><?= e($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ', ' . $al['nombres']) ?></td>
<?php foreach ($columnas as $c): ?>
<?php if ($c['tipo'] !== 'propia'): ?>
<td class="ra-m__vacia"></td>
<?php continue; endif;
    $a   = $c['alumnos'][$mid] ?? null;
    $exo = isset($c['exoSet'][$mid]); ?>
<?php foreach ($c['criterios'] as $cr):
    $nc = $a['notas_criterios'][$cr['id']] ?? null;
    $om = $a['omisiones_criterios'][$cr['id']] ?? null; ?>
<td><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($nc !== null): ?><?= fmt_nota((int) $nc) ?><?php elseif ($om !== null): ?><span class="omision-badge" title="<?= e(\App\Models\OmisionCriterioModel::etiqueta($om)) ?>"><?= e($omisionSiglas[$om] ?? '—') ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<td class="ra-m__logro"><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['promedio'] !== null): ?><span class="nota-numeral nota-numeral--<?= e(strtolower((string) $a['literal'])) ?>"><?= fmt_nota((int) $a['promedio']) ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<?php foreach ($columnas as $c): ?>
<?php if ($c['tipo'] !== 'propia'): ?>
<td class="ra-m__res ra-m__vacia"></td>
<?php continue; endif;
    $a   = $c['alumnos'][$mid] ?? null;
    $exo = isset($c['exoSet'][$mid]); ?>
<td class="ra-m__res"><?php if ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['literal'] !== null): ?><span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
<?php foreach ($transv as $t):
    $a   = $t['alumnos'][$mid] ?? null;
    $exo = isset($t['exoSet'][$mid]); ?>
<td class="ra-m__tr"><?php if (!$t['evaluada']): ?><?php elseif ($exo): ?><span class="exo-badge">EXO</span><?php elseif ($a !== null && $a['literal'] !== null): ?><span class="nota-literal nota-literal--<?= e(strtolower((string) $a['literal'])) ?>"><?= e($a['literal']) ?></span><?php else: ?>—<?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
        </tbody>
    </table>

    <?php // Pie indivisible: estadísticas + lugar y fecha + firmas (la firma nunca sola). ?>
    <div class="ra-cierre">
        <?php if ($propias !== []):
            $stats = [];
            foreach ($propias as $c) {
                $stats[] = stats_competencia($c['lista'], $c['exo'], $nivel);
            }
            $hayExo = array_sum(array_column($stats, 'exonerados')) > 0;
            $cortes = implode(' + ', $stats[0]['aprobatorios']); ?>
            <div class="ra-cierre__stats">
            <table class="ra-stats">
                <thead>
                    <tr>
                        <th scope="col" class="ra-stats__rot">Proceso de logro</th>
                        <?php foreach ($propias as $c): ?><th scope="col"><?= e((string) $c['codigo']) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr><th scope="row" class="ra-stats__rot">Matriculados</th><?php foreach ($stats as $s): ?><td><?= count($roster) ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row" class="ra-stats__rot">Evaluados</th><?php foreach ($stats as $s): ?><td><?= (int) $s['evaluados'] ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row" class="ra-stats__rot">No evaluados</th><?php foreach ($stats as $s): ?><td><?= (int) $s['no_evaluados'] ?></td><?php endforeach; ?></tr>
                    <?php if ($hayExo): ?>
                        <tr><th scope="row" class="ra-stats__rot">Exonerados</th><?php foreach ($stats as $s): ?><td><?= (int) $s['exonerados'] ?></td><?php endforeach; ?></tr>
                    <?php endif; ?>
                    <tr><th scope="row" class="ra-stats__rot">Aprobados (<?= e($cortes) ?>)</th><?php foreach ($stats as $s): ?><td><?= (int) $s['aprobados'] ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row" class="ra-stats__rot">Desaprobados</th><?php foreach ($stats as $s): ?><td><?= (int) $s['desaprobados'] ?></td><?php endforeach; ?></tr>
                </tbody>
            </table>
            <table class="ra-stats">
                <thead>
                    <tr>
                        <th scope="col" class="ra-stats__rot">Niveles de logro</th>
                        <?php foreach ($propias as $c): ?><th scope="col"><?= e((string) $c['codigo']) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (escala_rangos() as $lit => $rango): ?>
                        <tr>
                            <th scope="row" class="ra-stats__rot"><span class="nota-literal nota-literal--<?= e(strtolower($lit)) ?>"><?= e($lit) ?></span> <?= e(descripcion_literal($lit)) ?> (<?= e($rango) ?>)</th>
                            <?php foreach ($stats as $s): ?><td><?= (int) $s['literales'][$lit] ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

        <div class="ra-cierre__firmas">
        <p class="ra-cierre__fecha">
            <span class="ra-cierre__id"><?= e($identificacion) ?></span>
            <?= e(config('institucion_datos')['lugar'] ?? 'Huaraz') ?>, <?= e(fecha_es(date('Y-m-d'))) ?>
        </p>

        <footer class="reporte-footer ra-firmas">
            <?php foreach ($firmas as $f): ?>
                <div class="reporte-footer__bloque">
                    <div class="reporte-footer__espacio-firma"></div>
                    <div class="reporte-footer__linea"></div>
                    <?php if (!empty($f['nombre'])): ?>
                        <div class="reporte-footer__nombre"><?= e($f['nombre']) ?></div>
                    <?php endif; ?>
                    <div class="reporte-footer__cargo"><?= e($f['cargo']) ?></div>
                </div>
            <?php endforeach; ?>
        </footer>
        </div>
    </div>
<?php endif; ?>
