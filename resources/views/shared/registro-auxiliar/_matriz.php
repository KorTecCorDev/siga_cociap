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
 * PARTES (27/10/2026): si el área no cabe en el ancho de la hoja, la matriz se
 * reparte en varias tablas por competencias ENTERAS (`partesMatriz`), cada una en
 * hoja nueva, repitiendo N° y nombres y diciendo «Parte X de Y». La leyenda va
 * una vez (arriba); el Resumen, en la última parte, y el pie, tras ella.
 *
 * Logro, Resumen y transversales en PAR numeral | literal, los dos niveles
 * (27/10/2026). Las transversales llevan además sus criterios Cr1…CrN con sus
 * notas, como las propias (28/10/2026), y se reparten en bloques por carga:
 * pueden empezar en una parte anterior; el Resumen, siempre en la última.
 *
 * Las filas de trasladados y retirados que CURSARON el bimestre (roster de
 * lectura, 28/10/2026) llevan un chip junto al nombre.
 *
 * @var array    $hoja           con `firmas` [{nombre|null, cargo}]
 * @var array    $periodo
 * @var string   $modo           'docente' | 'area'
 * @var array    $omisionSiglas
 * @var callable $par            celdas numeral | literal (documento.php)
 * @var callable $celdasCriterios celdas de los criterios de una fila (documento.php)
 *
 * La fila de la oficial de un retorno en su tramo llega VACÍA
 * (RegistroAuxiliarModel::vaciarTramo): lo cursado en la operativa va en el
 * anexo (`_anexo-retorno.php`, 28/10/2026).
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
    // En el área completa entran todas las cargas: una columna `otra` es una
    // competencia del plan sin carga en la sección.
    'otra'      => $modo === 'area' ? 'Sin carga' : 'Otra carga',
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
$partes  = \App\Models\RegistroAuxiliarModel::partesMatriz($columnas, $transv);
// Columnas de una transversal: sus criterios + el par del logro; sin evaluar, el par en blanco.
$colsTr  = static fn(array $t): int => ($t['evaluada'] ? count($t['criterios']) : 0) + 2;
// Con varias cargas, el código de la competencia de su subárea antecede a la CT en la leyenda.
$trConCriterios = array_values(array_filter($transv, static fn(array $t): bool => $t['evaluada'] && $t['criterios'] !== []));
// Leyenda: cada CT una sola vez, con las cargas que la evalúan (código de la
// cabecera + subárea) y las que no (28/10/2026: sin repetir la descripción).
$ctLeyenda = [];
foreach ($transv as $t) {
    $k = (int) $t['competencia']['id'];
    $ctLeyenda[$k] ??= ['competencia' => $t['competencia'], 'cargas' => []];
    $rotulo = $t['grupo'] === null ? null
        : ((string) $t['grupo'] === (string) $t['subarea'] ? (string) $t['grupo'] : $t['grupo'] . ' ' . $t['subarea']);
    $ctLeyenda[$k]['cargas'][] = ['rotulo' => $rotulo, 'evaluada' => $t['evaluada']];
}
$nPartes = count($partes);
$firmas  = $hoja['firmas'];
$tituloHoja = 'Registro auxiliar de evaluación de aprendizajes';
$notaHoja   = 'Notas vigesimales de 00 a 20';
require VIEW_PATH . '/shared/registro-auxiliar/_cabecera.php';
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
            <?php foreach ($ctLeyenda as $ct): ?>
                <li>
                    <span class="competencia-card__codigo"><?= e((string) $ct['competencia']['codigo_minedu']) ?></span>
                    <?= e($ct['competencia']['nombre_completo']) ?>
                    <span class="ra-tag">Transversal</span>
                    <?php foreach ($ct['cargas'] as $cg): ?>
                        <?php if ($cg['rotulo'] === null): ?>
                            <?php if (!$cg['evaluada']): ?><span class="ra-tag ra-tag--noeval">No evaluada</span><?php endif; ?>
                        <?php elseif ($cg['evaluada']): ?>
                            <span class="ra-tag"><?= e($cg['rotulo']) ?></span>
                        <?php else: ?>
                            <span class="ra-tag ra-tag--noeval">No evaluada: <?= e($cg['rotulo']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if ($propias !== [] || $trConCriterios !== []):
        // Tabla a todo el ancho: una fila por competencia, una columna por criterio
        // (Cr1…CrN = los mismos rótulos de la matriz). Como tabla de ancho fijo no
        // se desacomoda con el número ni el largo de los criterios. Las
        // transversales con criterios, al final (28/10/2026).
        $maxCr = max(array_map(static fn(array $c): int => count($c['criterios']), array_merge($propias, $trConCriterios))); ?>
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
                        <?php for ($n = 1; $n <= $maxCr; $n++): ?><th scope="col"><span class="ra-cr">Cr<?= $n ?></span></th><?php endfor; ?>
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
                    <?php foreach ($trConCriterios as $t): ?>
                        <tr>
                            <th scope="row"><?php if ($t['grupo'] !== null): ?><span class="competencia-card__codigo"><?= e((string) $t['grupo']) ?></span> <?php endif; ?><span class="competencia-card__codigo"><?= e((string) $t['competencia']['codigo_minedu']) ?></span></th>
                            <?php for ($n = 0; $n < $maxCr; $n++): ?>
                                <?php if (isset($t['criterios'][$n])): ?>
                                    <td><?= e($t['criterios'][$n]['nombre']) ?></td>
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
    <?php foreach ($partes as $k => $parte):
        // Columnas de ESTA parte (con su índice en el área, que da el código
        // «Comp. N» cuando falta el MINEDU); Resumen y transversales, solo en la última.
        $colsParte = [];
        foreach ($parte['columnas'] as $i) {
            $colsParte[$i] = $columnas[$i];
        }
        $resParte = $parte['final'] ? $columnas : [];
        $trParte  = [];
        foreach ($parte['transversales'] as $i) {
            $trParte[] = $transv[$i];
        }
        // Logro, Resumen y transversales: PAR numeral | literal (2 columnas);
        // las transversales, además, sus criterios.
        $trColumnas = array_sum(array_map($colsTr, $trParte));
        $totalColumnas = 2 + 2 * count($resParte) + $trColumnas;
        foreach ($colsParte as $c) {
            $totalColumnas += $c['tipo'] === 'propia' ? count($c['criterios']) + 2 : 1;
        } ?>
    <table class="ra-m ra-parte">
        <colgroup>
            <col class="ra-m__c-num">
            <col class="ra-m__c-nombre">
            <?php foreach ($colsParte as $c): ?>
                <?php if ($c['tipo'] === 'propia'): ?>
                    <?php foreach ($c['criterios'] as $cr): ?><col><?php endforeach; ?>
                    <col class="ra-m__c-pnum"><col class="ra-m__c-plit">
                <?php else: ?>
                    <col class="ra-m__c-vacia">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($resParte as $c): ?><col class="ra-m__c-pnum"><col class="ra-m__c-plit"><?php endforeach; ?>
            <?php foreach ($trParte as $t): ?>
                <?php if ($t['evaluada']): ?>
                    <?php foreach ($t['criterios'] as $cr): ?><col><?php endforeach; ?>
                <?php endif; ?>
                <col class="ra-m__c-pnum"><col class="ra-m__c-plit">
            <?php endforeach; ?>
        </colgroup>
        <thead>
            <tr class="ra-m__id">
                <th colspan="<?= $totalColumnas ?>">Registro auxiliar &middot; <?= e($identificacion) ?><?php if ($nPartes > 1): ?> &middot; <span class="ra-m__parte">Parte <?= $k + 1 ?> de <?= $nPartes ?></span><?php endif; ?></th>
            </tr>
            <?php // Filas de cabecera sobre la de rótulos (`ra-m__cols`): «Comp. transv.»;
                  // con transversales de varias cargas, el código de la competencia de
                  // cada carga sobre sus CT (sin el nombre del curso); y el código de
                  // cada CT sobre sus criterios. Las demás celdas de grupo bajan
                  // las filas que haga falta para cubrirlas.
                  $trGrupos   = \App\Models\RegistroAuxiliarModel::gruposTransversales($trParte);
                  $filasGrupo = $trParte === [] ? 1 : 2 + ($trGrupos !== [] ? 1 : 0); ?>
            <tr class="ra-m__grupos">
                <th rowspan="<?= $filasGrupo + 1 ?>" scope="col">N°</th>
                <th rowspan="<?= $filasGrupo + 1 ?>" scope="col" class="ra-m__izq">Apellidos y nombres</th>
                <?php foreach ($colsParte as $i => $c): ?>
                    <th scope="colgroup" rowspan="<?= $filasGrupo ?>" colspan="<?= $c['tipo'] === 'propia' ? count($c['criterios']) + 2 : 1 ?>"
                        class="ra-m__grupo<?= $c['tipo'] !== 'propia' ? ' ra-m__grupo--vacio' : '' ?>">
                        <?= e($codigo($c, $i)) ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($resParte !== []): ?>
                    <th scope="colgroup" rowspan="<?= $filasGrupo ?>" colspan="<?= 2 * count($resParte) ?>" class="ra-m__grupo ra-m__grupo--res">Resumen</th>
                <?php endif; ?>
                <?php if ($trParte !== []): ?>
                    <th scope="colgroup" colspan="<?= $trColumnas ?>" class="ra-m__grupo ra-m__grupo--tr">Comp. transv.</th>
                <?php endif; ?>
            </tr>
            <?php if ($trGrupos !== []):
                // El span de cada grupo cuenta CT; aquí hacen falta sus columnas.
                $j = 0; ?>
                <tr class="ra-m__subgrupos">
                    <?php foreach ($trGrupos as $g):
                        $span = 0;
                        for ($n = 0; $n < $g['span']; $n++, $j++) {
                            $span += $colsTr($trParte[$j]);
                        } ?>
                        <th scope="colgroup" colspan="<?= $span ?>" class="ra-m__grupo ra-m__grupo--tr"><?= e($g['grupo']) ?></th>
                    <?php endforeach; ?>
                </tr>
            <?php endif; ?>
            <?php if ($trParte !== []): ?>
                <tr class="ra-m__subgrupos">
                    <?php foreach ($trParte as $t): ?>
                        <th scope="colgroup" colspan="<?= $colsTr($t) ?>" class="ra-m__grupo ra-m__grupo--tr"><?= e((string) $t['competencia']['codigo_minedu']) ?></th>
                    <?php endforeach; ?>
                </tr>
            <?php endif; ?>
            <tr class="ra-m__cols">
                <?php foreach ($colsParte as $c): ?>
                    <?php if ($c['tipo'] === 'propia'): ?>
                        <?php foreach ($c['criterios'] as $n => $cr): ?>
                            <th scope="col" class="ra-m__cr" title="<?= e($cr['nombre']) ?>"><span class="ra-cr">Cr<?= $n + 1 ?></span></th>
                        <?php endforeach; ?>
                        <th scope="colgroup" colspan="2" class="ra-m__logro">Logro</th>
                    <?php else: ?>
                        <th scope="col" class="ra-m__vacia"></th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($resParte as $i => $c): ?>
                    <th scope="colgroup" colspan="2" class="ra-m__res"><?= e($codigo($c, $i)) ?></th>
                <?php endforeach; ?>
                <?php foreach ($trParte as $t): ?>
                    <?php if ($t['evaluada']): ?>
                        <?php foreach ($t['criterios'] as $n => $cr): ?>
                            <th scope="col" class="ra-m__cr" title="<?= e($cr['nombre']) ?>"><span class="ra-cr">Cr<?= $n + 1 ?></span></th>
                        <?php endforeach; ?>
                        <th scope="colgroup" colspan="2" class="ra-m__logro">Logro</th>
                    <?php else: ?>
                        <th scope="colgroup" colspan="2" class="ra-m__vacia"></th>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
<?php // ⚠️ Filas SIN INDENTAR: se repiten por estudiante y columna. ?>
<?php foreach ($roster as $i => $al):
    $mid = (int) $al['matricula_id']; ?>
<tr>
<td class="ra-m__num"><?= $i + 1 ?></td>
<td class="ra-m__izq"><?= e($al['apellido_paterno'] . ' ' . $al['apellido_materno'] . ', ' . $al['nombres']) ?><?php $sitChips = $al['situaciones'] ?? []; $sitImpreso = true; require VIEW_PATH . '/shared/_situacion-chips.php'; ?></td>
<?php foreach ($colsParte as $c): ?>
<?php if ($c['tipo'] !== 'propia'): ?>
<td class="ra-m__vacia"></td>
<?php continue; endif;
    $a   = $c['alumnos'][$mid] ?? null;
    $exo = isset($c['exoSet'][$mid]); ?>
<?php $celdasCriterios($c['criterios'], $a, $exo); ?>
<?php $par($a, $exo, 'ra-m__logro'); ?>
<?php endforeach; ?>
<?php foreach ($resParte as $c): ?>
<?php if ($c['tipo'] !== 'propia'): ?>
<?php $par(null, false, 'ra-m__res ra-m__vacia', true); ?>
<?php continue; endif; ?>
<?php $par($c['alumnos'][$mid] ?? null, isset($c['exoSet'][$mid]), 'ra-m__res'); ?>
<?php endforeach; ?>
<?php foreach ($trParte as $t):
    $a   = $t['alumnos'][$mid] ?? null;
    $exo = isset($t['exoSet'][$mid]); ?>
<?php if ($t['evaluada']) { $celdasCriterios($t['criterios'], $a, $exo); } ?>
<?php $par($a, $exo, 'ra-m__tr', !$t['evaluada']); ?>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
        </tbody>
    </table>
    <?php endforeach; ?>

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

        <?php // Más de 3 firmas (área completa con varios docentes): filas de 3. ?>
        <footer class="reporte-footer ra-firmas<?= count($firmas) > 3 ? ' ra-firmas--varias' : '' ?>">
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
