<?php
/**
 * Documento de RATIFICACIÓN — A4 portrait (layout print). Migración 080,
 * 14/10/2026. Ver docs/modulos/ratificaciones.md.
 *
 * Esqueleto FIJO (membrete, título, datos del estudiante, motivo, lugar y
 * fecha, firma, pie) + el CUERPO por bloques de la plantilla. Reusa el estilo
 * de la constancia de traslado (`_constancia.scss`); lo propio va en
 * `_ratificacion.scss`.
 *
 * Con `$vistaPrevia` (plantillas) lleva datos de ejemplo, marca de agua y ni
 * firma, ni sello, ni QR. Una emisión (Fase 3) lleva firma y sello del Director
 * EBR vigente en la fecha de emisión y el QR de verificación; si está ANULADA,
 * el sello «ANULADA» y sin firma ni sello.
 *
 * @var bool        $vistaPrevia
 * @var bool        $anulada       solo emisión (por defecto false)
 * @var string|null $urlVerificar  URL del QR, solo emisión
 * @var array       $plantilla     { titulo, tipo }
 * @var array       $bloques       RatificacionPlantillaModel::bloquesParaDocumento()
 * @var array       $vars          valores de las variables (estudiante, dni, …)
 * @var string      $lema          lema oficial del año
 * @var array       $institucion   config('institucion_datos')
 * @var string      $codModular
 * @var string|null $motivoTexto   texto oficial del motivo (solo no ratificado)
 * @var string|null $detalleTexto
 * @var string      $fecha         Y-m-d
 * @var array|null  $directorEbr   { nombre_completo, sexo, firma_path, sello_path }
 */
$inst = $institucion;
$anulada      = $anulada ?? false;
$urlVerificar = $urlVerificar ?? null;
// Firma y sello solo en el documento definitivo y vigente (criterio de la boleta).
$conFirma     = !$vistaPrevia && !$anulada;
$cargoDirector = match($directorEbr['sexo'] ?? null) {
    'F'     => 'Directora de Educación Básica Regular',
    'M'     => 'Director de Educación Básica Regular',
    default => 'Director(a) de Educación Básica Regular',
};
$tamanoClase = ['pequena' => 'ratif-doc__img--pequena', 'media' => 'ratif-doc__img--media', 'completa' => 'ratif-doc__img--completa'];
?>

<article class="constancia ratif-doc">

    <?php if ($vistaPrevia): ?>
        <div class="ratif-doc__marca" aria-hidden="true">VISTA PREVIA</div>
    <?php elseif ($anulada): ?>
        <div class="constancia-anulada-sello">ANULADA</div>
    <?php endif; ?>

    <!-- ── Membrete (el de la constancia de traslado) ── -->
    <?php if ($lema !== ''): ?>
        <div class="constancia-lema"><?= e($lema) ?></div>
    <?php endif; ?>

    <header class="constancia-header">
        <div class="constancia-header__logo">
            <img src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        </div>
        <div class="constancia-header__centro">
            <div class="constancia-header__rector"><?= e($inst['ente_rector'] ?? '') ?></div>
            <div class="constancia-header__colegio"><?= e($inst['nombre_oficial'] ?? '') ?></div>
            <?php if (!empty($inst['eslogan'])): ?>
                <div class="constancia-header__eslogan">«<?= e($inst['eslogan']) ?>»</div>
            <?php endif; ?>
            <div class="constancia-header__datos">
                Código Modular: <?= e($codModular) ?> &middot; Código de Local: <?= e($inst['codigo_local'] ?? '') ?>
            </div>
            <?php if (!empty($inst['resoluciones'])): ?>
                <div class="constancia-header__datos"><?= e($inst['resoluciones']) ?></div>
            <?php endif; ?>
        </div>
    </header>

    <hr class="constancia-regla">

    <h1 class="constancia-titulo"><?= e($plantilla['titulo']) ?></h1>

    <!-- ── Datos del estudiante (fijos) ── -->
    <dl class="ratif-doc__datos">
        <div><dt>Estudiante</dt><dd><?= e($vars['estudiante']) ?></dd></div>
        <div><dt>DNI</dt><dd><?= e($vars['dni']) ?></dd></div>
        <div><dt>Grado y sección</dt><dd><?= e($vars['grado'] . ' "' . $vars['seccion'] . '"') ?></dd></div>
        <div><dt>Nivel</dt><dd><?= e($vars['nivel']) ?></dd></div>
        <div><dt>Año académico</dt><dd><?= e($vars['anio']) ?></dd></div>
        <div><dt>Año siguiente</dt><dd><?= e($vars['anio_siguiente']) ?></dd></div>
    </dl>

    <!-- ── Cuerpo por bloques ── -->
    <div class="constancia-cuerpo ratif-doc__cuerpo">
        <?php foreach ($bloques as $b): ?>
            <?php if ($b['tipo'] === 'parrafo'): ?>
                <?php // `html` ya viene escapado por RatificacionPlantillaModel::renderParrafo(). ?>
                <p><?= $b['html'] ?></p>
            <?php elseif ($b['tipo'] === 'tabla' && $b['filas'] !== []): ?>
                <table class="ratif-doc__tabla">
                    <thead>
                        <tr><?php foreach ($b['filas'][0] as $celda): ?><th><?= e($celda) ?></th><?php endforeach; ?></tr>
                    </thead>
                    <?php if (count($b['filas']) > 1): ?>
                        <tbody>
                            <?php foreach (array_slice($b['filas'], 1) as $fila): ?>
                                <tr><?php foreach ($fila as $celda): ?><td><?= e($celda) ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            <?php elseif ($b['tipo'] === 'imagen'): ?>
                <figure class="ratif-doc__figura">
                    <img src="<?= url('admin/ratificaciones/plantillas/imagen/' . rawurlencode($b['imagen_archivo'])) ?>"
                         alt="" class="ratif-doc__img <?= $tamanoClase[$b['imagen_tamano']] ?? '' ?>">
                </figure>
            <?php elseif ($b['tipo'] === 'salto'): ?>
                <div class="ratif-doc__salto" aria-hidden="true"></div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($motivoTexto !== null): ?>
            <div class="ratif-doc__motivo">
                <p class="ratif-doc__motivo-titulo">Motivo</p>
                <p class="ratif-doc__motivo-texto"><?= e($motivoTexto) ?></p>
                <?php if (!empty($detalleTexto)): ?>
                    <p class="ratif-doc__motivo-texto"><?= e($detalleTexto) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <p class="constancia-lugar-fecha">
        <?= e($inst['lugar'] ?? 'Huaraz') ?>, <?= fecha_es($fecha) ?>.
    </p>

    <!-- ── Firma y sello del Director EBR ── -->
    <div class="ratif-doc__firmas">
        <div class="ratif-doc__sello">
            <?php if ($conFirma && !empty($directorEbr['sello_path'])): ?>
                <img src="<?= url($directorEbr['sello_path']) ?>" alt="" aria-hidden="true"
                     class="ratif-doc__sello-img">
            <?php endif; ?>
        </div>
        <div class="constancia-firma">
            <div class="constancia-firma__espacio">
                <?php if ($conFirma && !empty($directorEbr['firma_path'])): ?>
                    <img src="<?= url($directorEbr['firma_path']) ?>" alt="" aria-hidden="true"
                         class="constancia-firma__img">
                <?php endif; ?>
            </div>
            <div class="constancia-firma__linea"></div>
            <?php if (!empty($directorEbr['nombre_completo'])): ?>
                <div class="constancia-firma__nombre"><?= e($directorEbr['nombre_completo']) ?></div>
            <?php endif; ?>
            <div class="constancia-firma__cargo"><?= $cargoDirector ?></div>
        </div>
        <div class="ratif-doc__sello" aria-hidden="true"></div>
    </div>

    <?php if ($urlVerificar !== null && !$vistaPrevia): ?>
        <!-- ── QR de autenticidad (solo dice si el documento es auténtico y vigente) ── -->
        <div class="ratif-doc__qr">
            <div class="ratif-doc__qr-code" data-qr-url="<?= e($urlVerificar) ?>"></div>
            <div class="ratif-doc__qr-texto">
                <p class="ratif-doc__qr-titulo">Verificación del documento</p>
                <p>Escanea el código QR para comprobar que este documento es auténtico y está vigente.</p>
                <code class="ratif-doc__qr-url"><?= e($urlVerificar) ?></code>
            </div>
        </div>
    <?php endif; ?>

    <footer class="constancia-pie">
        <div class="constancia-pie__inst">
            <?= e($inst['direccion'] ?? '') ?><?php if (!empty($inst['ubicacion'])): ?> &middot; <?= e($inst['ubicacion']) ?><?php endif; ?>
            <?php if (!empty($inst['telefonos'])): ?><br>Tel. <?= e($inst['telefonos']) ?><?php endif; ?>
        </div>
        <div class="constancia-pie__siga">
            Documento elaborado e impreso por el SIGA-COCIAP &middot; <?= date('d/m/Y H:i') ?>
        </div>
    </footer>

</article>

<?php if ($urlVerificar !== null && !$vistaPrevia && !defined('RATIF_QR_SCRIPT_LOADED')): define('RATIF_QR_SCRIPT_LOADED', true); ?>
<?php // El layout `print` no carga page_scripts: mismo patrón que boleta/alumno.php (QR local, sin terceros). ?>
<script src="<?= url('js/qrcode.min.js') ?>"></script>
<script>
(function () {
    function generarQRs() {
        document.querySelectorAll('.ratif-doc [data-qr-url]').forEach(function (el) {
            if (el.dataset.qrInit) return;
            el.dataset.qrInit = '1';
            var u = el.getAttribute('data-qr-url');
            if (u && typeof QRCode !== 'undefined') {
                new QRCode(el, { text: u, width: 80, height: 80, correctLevel: QRCode.CorrectLevel.M });
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', generarQRs);
    } else {
        generarQRs();
    }
})();
</script>
<?php endif; ?>
