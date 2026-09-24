<?php
/**
 * Estudiantes en riesgo académico — A4 vertical (layout print, 23/09/2026).
 *
 * Documento de TRABAJO: sin sello del Director EBR. Imprime LO FILTRADO
 * (?secciones[]) y el encabezado dice qué alcance se aplicó, para que una hoja
 * suelta no se lea como el informe completo.
 *
 * Mismos partials que la pantalla: el papel no puede decir otra cifra. La
 * maquetación del listado y por qué el título del grado va en el `<thead>`
 * está en `_listado.php`.
 *
 * @var array $periodo
 * @var array $riesgo
 */
// Alcance agrupado nivel → grado: un grado con TODAS sus secciones elegidas se
// nombra entero («5°»); si no, sección por sección («6° A»). Sin selección,
// todos. Sale de las mismas secciones validadas que recortaron la lista.
$alcance = riesgo_alcance($riesgo['niveles'], $riesgo['secciones_ids']);
?>
<div class="riesgo-print">

    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_cabecera.php'; ?>

    <?php if ($riesgo['stats']['resumen']['total'] === 0): ?>
        <p class="cuadros-print__vacio">
            <?php if (empty($riesgo['por_grado'])): ?>
                Este bimestre todavía no tiene competencias bloqueadas: aún no puede determinarse
                la situación final de ningún estudiante.
            <?php else: ?>
                <?php $res = $riesgo['stats']['resumen']; $sinCasosAlcance = 'de este alcance'; ?>
                <?php require VIEW_PATH . '/admin/cuadros/riesgo/_sin-casos.php'; ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_resumen.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_leer.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_criticos.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_listado.php'; ?>
    <?php endif; ?>

    <?php // Fuera del `if` por el mismo motivo que en la pantalla. ?>
    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_automatica.php'; ?>
    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_pendiente-final.php'; ?>
</div>
