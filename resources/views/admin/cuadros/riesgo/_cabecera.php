<?php
/**
 * Cabecera institucional del A4 de riesgo (23/09/2026). La comparten el informe
 * (`imprimir.php`) y cada sección del lote por tutor (`tutores.php`), para que
 * toda hoja suelta diga de dónde sale, qué bimestre y qué alcance.
 *
 * @var array  $periodo
 * @var string $alcance   texto ya armado («Primaria: 5°, 6° A · Secundaria: 1° B»)
 */
?>
<header class="cuadros-print__head">
    <img class="cuadros-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
    <div class="cuadros-print__titulo">
        <h1><?= e(config('institucion')) ?></h1>
        <p>Estudiantes en riesgo académico &middot; <?= e($periodo['nombre_display']) ?> <?= e((string) $periodo['anio']) ?></p>
        <p class="cuadros-print__sub">
            <strong>Alcance:</strong> <?= e($alcance) ?>
            &middot; <strong>Estado del bimestre:</strong> <?= e($periodo['estado']) ?>
            &middot; <strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?>
        </p>
        <?php // Una hoja suelta tiene que decir QUE regla la produjo: sin esto se
              // lee como una lista de castigo y no como la proyeccion normativa. ?>
        <p class="cuadros-print__sub">
            Situación final proyectada según la RVM 00094-2020-MINEDU (modificada por la
            RVM 048-2024-MINEDU): requiere recuperación (RR) o permanece en el grado (PER).
        </p>
    </div>
</header>
