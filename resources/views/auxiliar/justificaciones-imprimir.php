<?php
/**
 * Faltas y justificaciones — Mis secciones: A4 vertical del auxiliar (layout
 * print, 13/10/2026). Misma estructura que el imprimible de Acompañamiento
 * pedagógico (`admin/cuadros/riesgo/imprimir.php`):
 *   - cabecera institucional con el bimestre, el ALCANCE (auxiliar y secciones),
 *     el estado del bimestre y la fecha de impresión, para que una hoja suelta
 *     diga de dónde sale;
 *   - el MISMO cuerpo que la pantalla (`_reporte-justificaciones.php`): el papel
 *     no puede decir otra cifra. La leyenda impresa dice qué regla produjo los
 *     números;
 *   - documento de TRABAJO, sin firma (decisión del usuario).
 *
 * El contenedor es `.cuadros-print` a propósito: su ancho FIJO de 718px es lo
 * que hace que Frappe dibuje el gráfico del ancho de la hoja (ver su comentario
 * en `_cuadros.scss`).
 *
 * @var array       $periodoVer
 * @var array       $reporte          AsistenciaModel::consolidarJustificaciones()
 * @var string|null $motivoPrincipal
 * @var string      $auxiliar         «Apellidos, Nombres» del auxiliar en sesión
 */
$impreso = true;
?>
<div class="cuadros-print just-print">
    <header class="cuadros-print__head">
        <img class="cuadros-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="cuadros-print__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Faltas y justificaciones &middot; <?= e($periodoVer['nombre_display']) ?> <?= e((string) $periodoVer['anio']) ?></p>
            <p class="cuadros-print__sub">
                <strong>Auxiliar:</strong> <?= e($auxiliar) ?>
                &middot; <strong>Secciones:</strong> <?= e(implode(', ', array_column($reporte['secciones'], 'etiqueta'))) ?>
            </p>
            <p class="cuadros-print__sub">
                <strong>Estado del bimestre:</strong> <?= e($periodoVer['estado']) ?>
                &middot; <strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?>
            </p>
        </div>
    </header>

    <?php require VIEW_PATH . '/auxiliar/_reporte-justificaciones.php'; ?>
</div>
