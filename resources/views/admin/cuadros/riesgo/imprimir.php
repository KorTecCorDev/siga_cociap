<?php
/**
 * Estudiantes en riesgo académico — A4 vertical (layout print, 23/09/2026).
 *
 * Documento de TRABAJO: sin sello del Director EBR. Imprime LO FILTRADO
 * (?grados[], ?primaria=c) y el encabezado dice qué filtro se aplicó, para que una hoja
 * suelta no se lea como el informe completo.
 *
 * Mismos partials que la pantalla: el papel no puede decir otra cifra. La
 * maquetación del listado y por qué el título del grado va en el `<thead>`
 * está en `_listado.php`.
 *
 * @var array $periodo
 * @var array $riesgo
 */
// Alcance agrupado por nivel: «Primaria: 5.º, 6.º · Secundaria: 1.º». Sin
// selección, todos. Sale de los mismos grados validados que recortaron la lista.
$filtro = 'Todos los niveles y grados';
if ($riesgo['grados_ids']) {
    $partes = [];
    foreach ($riesgo['niveles'] as $niv) {
        $sel = array_filter($niv['grados'], static fn(array $gr): bool =>
            in_array((int) $gr['id'], $riesgo['grados_ids'], true));
        if ($sel) {
            $partes[] = $niv['nombre'] . ': ' . implode(', ', array_column($sel, 'nombre_display'));
        }
    }
    $filtro = implode(' · ', $partes);
}
?>
<div class="riesgo-print">

    <header class="cuadros-print__head">
        <img class="cuadros-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="cuadros-print__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Estudiantes en riesgo académico &middot; <?= e($periodo['nombre_display']) ?> <?= e((string) $periodo['anio']) ?></p>
            <p class="cuadros-print__sub">
                <strong>Alcance:</strong> <?= e($filtro) ?>
                &middot; <strong>Estado del bimestre:</strong> <?= e($periodo['estado']) ?>
                &middot; <strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?>
            </p>
            <?php // Una hoja suelta en modo «solo C» no puede leerse como la regla oficial. ?>
            <?php if (!$riesgo['contar_b']): ?>
                <p class="cuadros-print__sub">
                    <strong>Primaria: solo competencias en C</strong> (la regla oficial cuenta B y C).
                </p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($riesgo['stats']['resumen']['total'] === 0): ?>
        <p class="cuadros-print__vacio">
            <?php if (empty($riesgo['por_grado'])): ?>
                Este bimestre todavía no tiene competencias bloqueadas: aún no puede determinarse
                quién está en riesgo.
            <?php else: ?>
                Ningún estudiante de este alcance llega al umbral de riesgo en el bimestre.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_resumen.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_leer.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_criticos.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_listado.php'; ?>
    <?php endif; ?>
</div>
