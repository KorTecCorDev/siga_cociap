<?php
/**
 * Vista: Acta imprimible de Resolución de Empates — A4 portrait
 *
 * @var array       $periodo       { id, anio, nombre_display, numero }
 * @var array       $resoluciones  [ { grado_nombre, nivel_nombre, motivo, resuelto_en,
 *                                     resuelto_por_nombre, alumnos[...] } ]
 * @var string      $institucion
 * @var array|null  $directorEbr   { nombre_completo, sexo, firma_path }
 */
$hoy = (new DateTime())->format('d/m/Y');

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

$cargoDirector = match($directorEbr['sexo'] ?? null) {
    'F'     => 'Directora E.B.R.',
    'M'     => 'Director E.B.R.',
    default => 'Director(a) E.B.R.',
};

$totalResoluciones = count($resoluciones);
?>

<div class="reporte-pagina acta-doc">

    <header class="boleta-header">
        <div class="boleta-header__logo-wrap">
            <img src="<?= url('assets/img/logo_cociap.png') ?>"
                 alt="COCIAP" class="boleta-header__logo">
        </div>
        <div class="boleta-header__centro">
            <div class="boleta-header__ugel">MINEDU &middot; DRE Áncash &middot; UGEL Huaraz</div>
            <div class="boleta-header__colegio"><?= e($institucion ?? '') ?></div>
            <div class="boleta-header__titulo">
                Acta de Resolución de Empates &mdash; Orden de Mérito <?= e($periodo['anio'] ?? '') ?>
            </div>
        </div>
        <div class="boleta-header__fecha-wrap">
            <div class="boleta-header__fecha-label">Impresión</div>
            <div class="boleta-header__fecha"><?= $hoy ?></div>
        </div>
    </header>

    <div class="reporte-titulo">
        <div class="reporte-titulo__grupo">
            <span class="reporte-titulo__principal">Acta de Resolución de Empates</span>
            <span class="reporte-titulo__sub">
                &mdash; <?= e($periodo['nombre_display'] ?? '') ?> &mdash; <?= e($periodo['anio'] ?? '') ?>
            </span>
        </div>
        <div class="reporte-titulo__meta">
            <span class="reporte-titulo__badge">
                <?= $totalResoluciones ?> resolución<?= $totalResoluciones !== 1 ? 'es' : '' ?>
            </span>
        </div>
    </div>

    <p class="acta-doc__intro">
        El presente documento deja constancia de las decisiones adoptadas para dirimir los
        empates irreducibles del orden de mérito del periodo indicado. Un empate es irreducible
        cuando la cascada automática (promedio exacto &rarr; menos C &rarr; menos B &rarr; más AD
        &rarr; más notas 15-16 &rarr; más notas 16) no logra separar a los estudiantes, o cuando
        estos presentan distinto número de competencias por exoneración. En esos casos el puesto en disputa lo resuelve la autoridad
        competente, dejando registrado el orden asignado y el motivo de la decisión.
    </p>

    <?php if (empty($resoluciones)): ?>
        <p class="reporte-vacio">No se registraron resoluciones de empate en este periodo.</p>
    <?php else: ?>

        <?php foreach ($resoluciones as $res): ?>
            <div class="acta-resolucion acta-resolucion--expediente">
                <div class="acta-resolucion__cab">
                    <span class="acta-resolucion__grado">
                        <?= e($res['nivel_nombre']) ?> &mdash; <?= e($res['grado_nombre']) ?>
                    </span>
                    <span class="acta-resolucion__resuelto">
                        Resuelto por <?= e($res['resuelto_por_nombre']) ?> &middot; <?= $fmtFecha($res['resuelto_en']) ?>
                    </span>
                </div>

                <table class="tabla-acta">
                    <thead>
                        <tr>
                            <th class="ta-orden">Orden</th>
                            <th class="ta-nombre">Apellidos y Nombres</th>
                            <th class="ta-seccion">Secc.</th>
                            <th class="ta-comp">Comp.</th>
                            <th class="ta-promedio">Promedio</th>
                            <th class="ta-dist">AD / B / C</th>
                            <th class="ta-dist">15-16 / 16</th>
                            <th class="ta-puesto">Puesto grado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($res['alumnos'] as $al): ?>
                            <tr>
                                <td class="ta-orden"><strong><?= (int) $al['orden_manual'] ?>°</strong></td>
                                <td class="ta-nombre">
                                    <?= e($al['apellido_paterno'] . ' ' .
                                        $al['apellido_materno'] . ', ' . $al['nombres']) ?>
                                </td>
                                <td class="ta-seccion"><?= e($al['seccion_nombre']) ?></td>
                                <td class="ta-comp">
                                    <?= $al['num_competencias'] !== null ? (int) $al['num_competencias'] : '—' ?>
                                </td>
                                <td class="ta-promedio">
                                    <?= $al['promedio_general'] !== null
                                        ? number_format((float) $al['promedio_general'], 2)
                                        : '—' ?>
                                </td>
                                <td class="ta-dist">
                                    <?php if ($al['num_ad'] !== null): ?>
                                        <?= (int) $al['num_ad'] ?> / <?= (int) $al['num_b'] ?> / <?= (int) $al['num_c'] ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="ta-dist">
                                    <?= $al['num_alto'] !== null ? (int) $al['num_alto'] . ' / ' . (int) $al['num_16'] : '—' ?>
                                </td>
                                <td class="ta-puesto">
                                    <?= $al['puesto_grado'] !== null ? (int) $al['puesto_grado'] . '°' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($res['comparativo'])): ?>
                    <div class="acta-resolucion__subtitulo">Detalle por competencia</div>
                    <table class="tabla-comp-acta">
                        <thead>
                            <tr>
                                <th class="tca-comp">Competencia</th>
                                <?php foreach ($res['alumnos'] as $al): ?>
                                    <th class="tca-alumno">
                                        <?= (int) $al['orden_manual'] ?>° <?= e($al['apellido_paterno']) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($res['comparativo'] as $row): ?>
                                <tr class="<?= !empty($row['resaltar']) ? 'fila-resaltada-acta' : '' ?>">
                                    <td class="tca-comp"><?= e($row['label']) ?></td>
                                    <?php foreach ($res['alumnos'] as $al): ?>
                                        <?php
                                        $mid = (int) $al['matricula_id'];
                                        $n   = $row['notas'][$mid] ?? null;
                                        $lit = $row['literales'][$mid] ?? null;
                                        ?>
                                        <td class="tca-alumno">
                                            <?= $n !== null ? (int) $n . ' (' . e($lit) . ')' : '—' ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="acta-resolucion__leyenda">
                        Las filas resaltadas indican competencias con el mismo literal (AD/A/B/C)
                        pero distinta nota numeral; esa diferencia se compensó en el promedio.
                    </p>
                <?php endif; ?>

                <?php
                $exp      = $res['expediente'] ?? [];
                $columnas = array_map(static fn($al) => [
                    'mid'      => (int) $al['matricula_id'],
                    'etiqueta' => (int) $al['orden_manual'] . '° ' . $al['apellido_paterno'],
                ], $res['alumnos']);
                $modo     = 'acta';
                require VIEW_PATH . '/director/_desempate-expediente.php';
                ?>

                <div class="acta-resolucion__motivo">
                    <span class="acta-resolucion__motivo-label">Criterios aplicados:</span>
                    <?= !empty($res['criterios']) ? e(implode(' · ', $res['criterios'])) : 'No registrados' ?>
                    <br>
                    <span class="acta-resolucion__motivo-label">Motivo de la decisión:</span>
                    <?= e($res['motivo']) ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

    <footer class="reporte-footer">
        <div class="reporte-footer__bloque">
            <div class="reporte-footer__espacio-firma">
                <?php if (!empty($directorEbr['firma_path'])): ?>
                    <img src="<?= url($directorEbr['firma_path']) ?>"
                         alt=""
                         aria-hidden="true"
                         class="reporte-footer__firma-img">
                <?php endif; ?>
            </div>
            <div class="reporte-footer__linea"></div>
            <?php if (!empty($directorEbr['nombre_completo'])): ?>
                <div class="reporte-footer__nombre"><?= e($directorEbr['nombre_completo']) ?></div>
            <?php endif; ?>
            <div class="reporte-footer__cargo"><?= $cargoDirector ?></div>
        </div>
    </footer>

</div>
