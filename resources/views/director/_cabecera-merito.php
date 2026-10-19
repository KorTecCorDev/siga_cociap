<?php
/**
 * Cabecera institucional de cada hoja del acta de mérito (19/10/2026). Mismo
 * lenguaje que la del Acompañamiento pedagógico (`admin/cuadros/riesgo/_cabecera.php`):
 * logo a la izquierda, institución, título de la hoja y una línea de datos.
 * Conserva los datos oficiales de la cabecera anterior (MINEDU · DRE · UGEL y
 * Cód. Modular), porque es un acta que se firma y se archiva.
 *
 * FUSIONA la antigua banda `.reporte-titulo` (decisión del usuario, 19/10/2026):
 * el título de la hoja («ORDEN DE MÉRITO — 4° — SECUNDARIA — II BIMESTRE 2026»)
 * va en UNA línea con un solo estilo y tamaño, y el conteo de áreas y
 * estudiantes pasa a la línea de datos. Así la hoja gana el alto que permite
 * subir la letra de la tabla sin que ningún grado se parta.
 *
 * @var string $institucion
 * @var string $codModular
 * @var array  $periodo
 * @var string $tituloHoja  título completo de ESA hoja
 * @var string $metaHoja    «6 áreas a promediar · 53 estudiantes»
 * @var string $impreso     fecha y hora de impresión
 */
?>
<header class="cuadros-print__head">
    <img class="cuadros-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
    <div class="cuadros-print__titulo">
        <h1><?= e($institucion ?? '') ?></h1>
        <p class="cuadros-print__sub">
            MINEDU &middot; DRE Áncash &middot; UGEL Huaraz &middot;
            <strong>Cód. Modular:</strong> <?= e($codModular) ?>
        </p>
        <p class="merito-doc__titulo"><?= e($tituloHoja) ?></p>
        <p class="cuadros-print__sub">
            <strong><?= e($metaHoja) ?></strong>
            &middot; <strong>Estado del bimestre:</strong> <?= e($periodo['estado'] ?? '') ?>
            &middot; <strong>Fecha de impresión:</strong> <?= e($impreso) ?>
        </p>
    </div>
</header>
