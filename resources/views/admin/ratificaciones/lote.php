<?php
/**
 * LOTE de documentos de ratificación de una sección — layout print (Fase 4,
 * 15/10/2026). Ver docs/modulos/ratificaciones.md.
 *
 * Mismo mecanismo que `admin/boletas-publicas/archivar.php`: html2pdf convierte
 * cada documento VIGENTE a PDF y JSZip los empaqueta (`resources/js/ratificacion-lote.js`).
 *
 * @var array  $documentos  variables de documento.php + nombre_archivo, por emisión
 * @var string $nombreZip
 */

// Cada documento se pinta en su PROPIO ámbito: incluido suelto, las variables
// de uno (p. ej. `$b` del bucle de bloques) pisarían las del siguiente.
$pintar = static function (array $doc): void {
    extract($doc, EXTR_SKIP);
    include __DIR__ . '/documento.php';
};
?>

<?php if ($documentos === []): ?>
    <div class="empty-state"><p>Esta sección no tiene documentos emitidos.</p></div>
<?php return; endif; ?>

<div id="archivo-progreso" class="archivo-progreso" data-zip-nombre="<?= e($nombreZip) ?>">
    <div class="archivo-progreso__header">
        <span class="archivo-progreso__icono" id="archivo-icono"><span class="btn-icon btn-icon--wait" aria-hidden="true"></span></span>
        <div class="archivo-progreso__textos">
            <strong id="archivo-status">Preparando documentos...</strong>
            <span id="archivo-detalle" class="archivo-progreso__detalle"></span>
        </div>
        <span id="archivo-contador" class="archivo-progreso__contador">0 / <?= count($documentos) ?></span>
    </div>
    <div class="archivo-progreso__barra-wrap">
        <div id="archivo-barra" class="archivo-progreso__barra"></div>
    </div>
    <div class="archivo-progreso__meta"><?= count($documentos) ?> documento(s)</div>
</div>

<div id="archivo-items" class="archivo-items-wrap" aria-hidden="true">
    <?php foreach ($documentos as $doc): ?>
        <div class="ratif-lote-item" data-nombre-archivo="<?= e($doc['nombre_archivo']) ?>">
            <?php $pintar($doc); ?>
        </div>
    <?php endforeach; ?>
</div>

<script src="<?= url('js/jszip.min.js') ?>"></script>
<script src="<?= url('js/html2pdf.bundle.min.js') ?>"></script>
<script src="<?= url('js/pdf-paginar.js') ?>"></script>
<script src="<?= url('js/ratificacion-lote.js') ?>"></script>
