<?php
/**
 * Archivado de boletas en PDF — layout print.
 * html2pdf.js convierte cada boleta a PDF; JSZip las empaqueta por sección.
 *
 * Sirve a DOS documentos con la misma mecánica ($esBorrador los separa):
 *   - ARCHIVO (por defecto): boleta oficial, con QR y firma. Exige bimestre
 *     cerrado (el guard vive en el controlador).
 *   - BORRADOR: el documento de la vista previa, para circularlo por Drive y
 *     recoger el visto bueno de los docentes antes de cerrar. Sin QR ni firma,
 *     con marca de agua en cada PDF.
 *
 * @var array  $periodo     { id, numero, nombre_display, anio }
 * @var array  $boletasData [{ alumno, periodos, areas, conducta, institucion,
 *                             url_boleta, nombre_archivo, carpeta }]
 * @var string $titulo
 * @var bool   $esBorrador
 */

$esBorrador = $esBorrador ?? false;

if (empty($boletasData)): ?>
<p style="text-align:center;padding:20mm;font-family:Arial,sans-serif">
    No hay boletas generadas para este período.
</p>
<?php return; endif;

// Agrupar por carpeta (sección) solo para info del encabezado
$carpetas = array_unique(array_column($boletasData, 'carpeta'));
sort($carpetas);

// Nombre del ZIP según si hay filtro de sección o es descarga de todo el período
$_bim = mb_strtoupper(str_replace(' ', '_', trim($periodo['nombre_display'])));
if ($esBorrador) { $_bim .= '_BORRADOR'; }
if ($seccionFiltro) {
    // Por sección: NIVEL_GRADO_SECCION_BIMESTRE.zip
    $_a       = $boletasData[0]['alumno'];
    $_nivel   = mb_strtoupper(str_replace(' ', '_', trim($_a['nivel_nombre'])));
    $_grado   = mb_strtoupper(preg_replace('/[°\s.]+/', '', trim($_a['grado_nombre'])));
    $_seccion = mb_strtoupper(trim($_a['seccion_nombre']));
    $nombreZip = "{$_nivel}_{$_grado}_{$_seccion}_{$_bim}.zip";
    unset($_a, $_nivel, $_grado, $_seccion);
} else {
    // Todo el período: BIMESTRE_ANIO.zip
    $nombreZip = "{$_bim}_{$periodo['anio']}.zip";
}
unset($_bim);
?>

<!-- ── Panel de progreso ─────────────────────────────────── -->
<div id="archivo-progreso" class="archivo-progreso">
    <div class="archivo-progreso__header">
        <span class="archivo-progreso__icono" id="archivo-icono"><span class="btn-icon btn-icon--wait" aria-hidden="true"></span></span>
        <div class="archivo-progreso__textos">
            <strong id="archivo-status"><?= $esBorrador ? 'Preparando borradores...' : 'Iniciando archivado...' ?></strong>
            <span id="archivo-detalle" class="archivo-progreso__detalle"></span>
        </div>
        <span id="archivo-contador" class="archivo-progreso__contador">
            0 / <?= count($boletasData) ?>
        </span>
    </div>
    <div class="archivo-progreso__barra-wrap">
        <div id="archivo-barra" class="archivo-progreso__barra"></div>
    </div>
    <div class="archivo-progreso__meta">
        <?= count($boletasData) ?> boleta(s) &middot;
        <?= count($carpetas) ?> sección(es):
        <?= e(implode(', ', $carpetas)) ?>
    </div>
</div>

<!-- ── Boletas para procesamiento ────────────────────────── -->
<div id="archivo-items" class="archivo-items-wrap" aria-hidden="true">
<?php foreach ($boletasData as $boletaData):
    extract($boletaData, EXTR_OVERWRITE);
    $vistaPrevia = $esBorrador;
?>
<div class="boleta-archivo-item"
     data-nombre-archivo="<?= e($boletaData['nombre_archivo']) ?>"
     data-carpeta="<?= e($boletaData['carpeta']) ?>">
    <?php // La marca de agua la trae la propia boleta al recibir $vistaPrevia
          // (punto único, ver boleta/_marca-borrador.php). ?>
    <?php include VIEW_PATH . '/boleta/alumno.php'; ?>
</div>
<div class="boleta-salto-pagina"></div>
<?php endforeach; ?>
</div>

<script>var ARCHIVO_ZIP_NOMBRE = <?= json_encode($nombreZip) ?>;</script>
<script src="<?= url('js/jszip.min.js') ?>"></script>
<script src="<?= url('js/html2pdf.bundle.min.js') ?>"></script>
<script src="<?= url('js/pdf-paginar.js') ?>"></script>
<script src="<?= url('js/archivar-boletas.js') ?>"></script>
