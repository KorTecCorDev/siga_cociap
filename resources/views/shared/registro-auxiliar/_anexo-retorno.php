<?php
/**
 * Registro auxiliar — ANEXO DEL RETORNO DE GRADO (28/10/2026), en hoja nueva tras
 * las conclusiones descriptivas del área.
 *
 * Regla del registro auxiliar: TODAS las calificaciones de cada criterio. La
 * fila de la estudiante en la matriz va vacía (decisión del usuario: «fila vacía
 * + anexo») y aquí va todo lo que cursó en su matrícula OPERATIVA en el bimestre:
 * por cada carga equivalente de esa sección, cada competencia bloqueada con SUS
 * criterios (Cr1…CrN, los de esa carga), la nota de cada uno, el logro y la
 * conclusión; debajo, la leyenda de esos criterios.
 *
 * Datos: RegistroAuxiliarModel::anexoRetorno() → `$hoja['anexo_retorno']`.
 *
 * @var array    $hoja
 * @var array    $periodo
 * @var callable $par              celdas numeral | literal (documento.php)
 * @var callable $celdasCriterios  celdas de los criterios (documento.php)
 */
$anexo = $hoja['anexo_retorno'] ?? [];
if ($anexo === []) {
    return;
}
$mh = $hoja['meta'];
$identificacion = $hoja['area_nombre']
    . ($hoja['subareas'] !== [] ? ' (' . implode(', ', $hoja['subareas']) . ')' : '')
    . ' · ' . trim($mh['grado_nombre'] . ' ' . $mh['seccion_nombre']) . ' de ' . $mh['nivel_nombre']
    . ' · ' . $periodo['nombre_display'] . ' ' . $periodo['anio'];
// La cabecera NO nombra a los docentes de la hoja: estas notas las registraron
// los de la sección operativa, y cada carga lo dice en su fila de grupo.
$hojaCab    = ['docentes' => []] + $hoja;
$tituloHoja = 'Anexo · Calificaciones del retorno de grado';
$notaHoja   = 'Notas vigesimales de 00 a 20';
?>
<section class="ra-anexo">
    <?php (static function (array $hoja, array $periodo, string $tituloHoja, string $notaHoja): void {
        require VIEW_PATH . '/shared/registro-auxiliar/_cabecera.php';
    })($hojaCab, $periodo, $tituloHoja, $notaHoja); ?>

    <?php foreach ($anexo as $est):
        $maxCr = 1;
        foreach ($est['cargas'] as $g) {
            foreach ($g['competencias'] as $comp) {
                $maxCr = max($maxCr, count($comp['criterios']));
            }
        }
        // Código + conclusión + Cr1…CrN + par del logro.
        $totalColumnas = 1 + $maxCr + 2 + 1; ?>
        <p class="ra-anexo__quien">
            <strong><?= e($est['nombre']) ?></strong><?php $sitChips = [['clave' => 'retorno']]; $sitImpreso = true; require VIEW_PATH . '/shared/_situacion-chips.php'; ?>
            &mdash; cursó el <?= e($periodo['nombre_display']) ?> en <?= e($est['seccion']) ?> de <?= e($mh['nivel_nombre']) ?>
            (matrícula operativa). Sus calificaciones de ese bimestre, con los criterios de esa sección.
        </p>

        <?php if ($est['cargas'] === []): ?>
            <p class="ra-print__vacio">No tiene calificaciones oficiales de esta área en <?= e($est['seccion']) ?>.</p>
        <?php else: ?>
        <table class="ra-m ra-anexo__tabla">
            <colgroup>
                <col class="ra-anexo__c-comp">
                <?php for ($n = 0; $n < $maxCr; $n++): ?><col class="ra-anexo__c-cr"><?php endfor; ?>
                <col class="ra-m__c-pnum"><col class="ra-m__c-plit">
                <col>
            </colgroup>
            <thead>
                <tr class="ra-m__id">
                    <th colspan="<?= $totalColumnas ?>">Anexo &middot; <?= e($identificacion) ?> &middot; cursado en <?= e($est['seccion']) ?></th>
                </tr>
                <tr class="ra-m__cols">
                    <th scope="col">Comp.</th>
                    <?php for ($n = 1; $n <= $maxCr; $n++): ?><th scope="col"><span class="ra-cr">Cr<?= $n ?></span></th><?php endfor; ?>
                    <th scope="colgroup" colspan="2">Logro</th>
                    <th scope="col" class="ra-m__izq">Conclusión descriptiva</th>
                </tr>
            </thead>
            <tbody>
<?php // ⚠️ Filas SIN INDENTAR, como la matriz. ?>
<?php foreach ($est['cargas'] as $g): ?>
<tr class="ra-anexo__grupo"><th colspan="<?= $totalColumnas ?>" scope="rowgroup" class="ra-m__izq"><?= e($g['nombre']) ?><?= $g['docente'] !== null ? ' · Registró: ' . e($g['docente']) : '' ?></th></tr>
<?php foreach ($g['competencias'] as $comp):
    $a = $comp['alumno']; ?>
<tr>
<th scope="row"><span class="competencia-card__codigo"><?= e($comp['codigo']) ?></span></th>
<?php $celdasCriterios($comp['criterios'], $a, $comp['exo']); ?>
<?php for ($n = count($comp['criterios']); $n < $maxCr; $n++): ?><td class="ra-m__vacia"></td><?php endfor; ?>
<?php $par($a, $comp['exo'], 'ra-m__logro'); ?>
<td class="ra-m__izq ra-cd__texto"><?= trim((string) ($a['conclusion_descriptiva'] ?? '')) !== '' ? e($a['conclusion_descriptiva']) : '—' ?></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
            </tbody>
        </table>

        <?php // Leyenda de SUS criterios (los de la sección operativa), como la principal. ?>
        <div class="ra-leyenda__bloque ra-leyenda__bloque--ancho ra-anexo__leyenda">
            <p class="ra-leyenda__tit">Criterios de evaluación de <?= e($est['seccion']) ?></p>
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
                    <?php foreach ($est['cargas'] as $g): ?>
                        <?php if (count($est['cargas']) > 1): ?>
                            <tr><th scope="rowgroup" colspan="<?= $maxCr + 1 ?>" class="ra-anexo__grupo-crit"><?= e($g['nombre']) ?></th></tr>
                        <?php endif; ?>
                        <?php foreach ($g['competencias'] as $comp): ?>
                            <tr>
                                <th scope="row"><span class="competencia-card__codigo"><?= e($comp['codigo']) ?></span></th>
                                <?php for ($n = 0; $n < $maxCr; $n++): ?>
                                    <?php if (isset($comp['criterios'][$n])): ?>
                                        <td><?= e($comp['criterios'][$n]['nombre']) ?></td>
                                    <?php else: ?>
                                        <td class="ra-crit__vacia"></td>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
