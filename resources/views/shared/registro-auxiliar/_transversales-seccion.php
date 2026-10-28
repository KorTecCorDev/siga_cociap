<?php
/**
 * Registro auxiliar del ÁREA COMPLETA — hoja «Competencias transversales de la
 * sección» (27/10/2026). Va al final del documento, en hoja nueva, SIN firmas
 * (decisión del usuario: firman los documentos con toda la información).
 *
 * Por estudiante y por competencia transversal: el logro OFICIAL de la sección
 * (promedio de lo registrado en todas sus cargas, el que va a la boleta) en par
 * numeral | literal, y la conclusión descriptiva del tutor. Mismos datos que
 * /consulta-notas/{p}/seccion/{s}/transversales, y con el mismo corte: solo con
 * el cierre vigente del tutor. NUNCA sale en el registro del docente.
 *
 * @var array    $ts       ConsultaNotasController::transversalesSeccion()
 * @var array    $periodo
 * @var callable $par      celdas numeral | literal (documento.php)
 */
$hoja = [
    'meta'        => $ts['meta'],
    'area_nombre' => null,
    'subareas'    => [],
    'docentes'    => [],
];
$identificacion = trim($ts['meta']['grado_nombre'] . ' ' . $ts['meta']['seccion_nombre']) . ' de ' . $ts['meta']['nivel_nombre']
    . ' · ' . $periodo['nombre_display'] . ' ' . $periodo['anio'];
$competencias  = $ts['competencias'];
$totalColumnas = 2 + 3 * count($competencias);
$tituloHoja = 'Competencias transversales de la sección';
$notaHoja   = 'Logro de la sección y conclusión descriptiva';
?>
<section class="ra-hoja ra-ts">
    <?php require VIEW_PATH . '/shared/registro-auxiliar/_cabecera.php'; ?>

    <?php if ($ts['cierre'] === null): ?>
        <p class="ra-print__vacio">
            Las competencias transversales de la sección aún no están cerradas en el <?= e($periodo['nombre_display']) ?>.
        </p>
    <?php elseif ($ts['alumnos'] === []): ?>
        <p class="ra-print__vacio">La sección no tiene estudiantes en este bimestre.</p>
    <?php else: ?>
        <div class="ra-leyenda">
            <ul class="ra-leyenda__lista">
                <?php foreach ($competencias as $comp): ?>
                    <li>
                        <span class="competencia-card__codigo"><?= e((string) $comp['codigo_minedu']) ?></span>
                        <?= e($comp['nombre_completo']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="ra-leyenda__escala">Logro oficial de la sección (el que va a la boleta) y conclusión descriptiva del tutor.</p>
        </div>

        <table class="ra-m">
            <colgroup>
                <col class="ra-m__c-num">
                <col class="ra-m__c-nombre">
                <?php foreach ($competencias as $comp): ?>
                    <col class="ra-m__c-pnum"><col class="ra-m__c-plit"><col>
                <?php endforeach; ?>
            </colgroup>
            <thead>
                <tr class="ra-m__id">
                    <th colspan="<?= $totalColumnas ?>">Competencias transversales &middot; <?= e($identificacion) ?></th>
                </tr>
                <tr class="ra-m__grupos">
                    <th rowspan="2" scope="col">N°</th>
                    <th rowspan="2" scope="col" class="ra-m__izq">Apellidos y nombres</th>
                    <?php foreach ($competencias as $comp): ?>
                        <th scope="colgroup" colspan="3" class="ra-m__grupo ra-m__grupo--tr"><?= e((string) $comp['codigo_minedu']) ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr class="ra-m__cols">
                    <?php foreach ($competencias as $comp): ?>
                        <th scope="colgroup" colspan="2">Logro</th>
                        <th scope="col" class="ra-m__izq">Conclusión descriptiva</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php // ⚠️ Filas SIN INDENTAR: se repiten por estudiante y competencia. ?>
<?php foreach ($ts['alumnos'] as $i => $al):
    $mid = (int) $al['matricula_id']; ?>
<tr>
<td class="ra-m__num"><?= $i + 1 ?></td>
<td class="ra-m__izq"><?= e($al['nombre_completo']) ?></td>
<?php foreach ($competencias as $comp):
    $cid   = (int) $comp['id'];
    $nota  = $ts['promedios'][$mid][$cid] ?? null;
    $texto = $ts['conclusiones'][$mid][$cid] ?? ''; ?>
<?php $par($nota !== null ? ['promedio' => (int) $nota, 'literal' => nota_a_literal((int) $nota)] : null, false, 'ra-m__tr'); ?>
<td class="ra-m__izq ra-cd__texto"><?= $texto !== '' ? e($texto) : '—' ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
