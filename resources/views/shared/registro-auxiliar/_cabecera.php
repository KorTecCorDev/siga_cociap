<?php
/**
 * Cabecera de una hoja del registro auxiliar (matriz y conclusiones), con los
 * datos del modelo del colegio: nivel, grado, sección, área, sub-área, docente
 * y bimestre. El lema del año sale de `anios_academicos.lema_oficial`.
 *
 * En el registro del área completa, la fila «Docentes» nombra a cada docente
 * con sus subáreas («Apellidos, Nombres (Aritmética, Álgebra) · …»).
 *
 * @var array  $hoja        con `docentes` [{nombre, detalle|null}]
 * @var array  $periodo
 * @var string $tituloHoja  «REGISTRO AUXILIAR DE …»
 * @var string $notaHoja    texto a la derecha («Notas vigesimales de 00 a 20»)
 */
$m = $hoja['meta'];
$docentesCab = implode(' · ', array_map(
    static fn(array $d): string => $d['nombre'] . ($d['detalle'] !== null ? ' (' . $d['detalle'] . ')' : ''),
    $hoja['docentes']
));
?>
<header class="ra-cab">
    <?php if (!empty($periodo['lema_oficial'])): ?>
        <p class="ra-cab__lema"><?= e($periodo['lema_oficial']) ?></p>
    <?php endif; ?>
    <div class="ra-cab__fila">
        <img class="ra-cab__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="ra-cab__titulo">
            <h1><?= e($tituloHoja) ?> &middot; <?= e(mb_strtoupper($m['nivel_nombre'])) ?> <?= e((string) $periodo['anio']) ?></h1>
            <p><?= e(config('institucion')) ?></p>
        </div>
        <p class="ra-cab__nota"><?= e($notaHoja) ?></p>
    </div>
    <dl class="ra-cab__datos">
        <div><dt>Nivel</dt><dd><?= e($m['nivel_nombre']) ?></dd></div>
        <div><dt>Grado</dt><dd><?= e($m['grado_nombre']) ?></dd></div>
        <div><dt>Sección</dt><dd><?= e($m['seccion_nombre']) ?></dd></div>
        <?php // Sin área: la hoja de transversales de la SECCIÓN. ?>
        <?php if ($hoja['area_nombre'] !== null): ?>
            <div><dt><?= $m['area_tipo'] === 'taller' ? 'Taller' : 'Área curricular' ?></dt><dd><?= e($hoja['area_nombre']) ?></dd></div>
        <?php endif; ?>
        <?php if ($hoja['subareas'] !== []): ?>
            <div><dt>Sub-área</dt><dd><?= e(implode(', ', $hoja['subareas'])) ?></dd></div>
        <?php endif; ?>
        <div><dt>Bimestre</dt><dd><?= e($periodo['nombre_display']) ?></dd></div>
        <?php if ($hoja['docentes'] !== []): ?>
            <div><dt><?= count($hoja['docentes']) > 1 ? 'Docentes' : 'Docente' ?></dt><dd><?= e($docentesCab) ?></dd></div>
        <?php endif; ?>
    </dl>
</header>
