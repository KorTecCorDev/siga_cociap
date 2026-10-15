<?php
/**
 * Documento de RATIFICACIÓN — A4 vertical (layout print). Migración 080; diseño
 * del 15/10/2026. Ver docs/modulos/ratificaciones.md § «Diseño del documento».
 *
 * DOS documentos con el mismo esqueleto sobrio (cabecera del informe de
 * Acompañamiento pedagógico: logo a la izquierda, institución, regla negra):
 *  - `ratificado`    → CONTRATO de reserva de matrícula: cuadro de LAS PARTES
 *                      (el colegio, representado por el Director EBR; el
 *                      apoderado responsable con su DNI; el estudiante), las
 *                      cláusulas (bloques) y DOS firmas: Director y apoderado.
 *  - `no_ratificado` → COMUNICADO (carta): cuadro de datos, cuerpo, motivo y
 *                      una sola firma, la del Director.
 * Sin apoderado responsable registrado, sus datos quedan en blanco para
 * llenarlos a mano: el documento no se bloquea.
 *
 * Con `$vistaPrevia` (plantillas) lleva datos de ejemplo, marca de agua y ni
 * firma, ni sello, ni QR. Una emisión lleva firma y sello del Director EBR
 * vigente en la fecha de emisión y el QR; ANULADA, el sello «ANULADA» y sin
 * firma ni sello. Todo el estilo vive en `_ratificacion.scss` (`.ratif-doc`).
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
$inst         = $institucion;
$anulada      = $anulada ?? false;
$urlVerificar = $urlVerificar ?? null;
$esContrato   = ($plantilla['tipo'] ?? '') === 'ratificado';
// Firma y sello solo en el documento definitivo y vigente (criterio de la boleta).
$conFirma     = !$vistaPrevia && !$anulada;
$cargoDirector = match($directorEbr['sexo'] ?? null) {
    'F'     => 'Directora de Educación Básica Regular',
    'M'     => 'Director de Educación Básica Regular',
    default => 'Director(a) de Educación Básica Regular',
};
$tamanoClase = ['pequena' => 'ratif-doc__img--pequena', 'media' => 'ratif-doc__img--media', 'completa' => 'ratif-doc__img--completa'];
$apoderado   = trim((string) ($vars['apoderado'] ?? ''));
$gradoTexto  = $vars['grado'] . ' "' . $vars['seccion'] . '" de ' . $vars['nivel'];
?>

<article class="ratif-doc<?= $esContrato ? ' ratif-doc--contrato' : ' ratif-doc--comunicado' ?>">

    <?php if ($vistaPrevia): ?>
        <div class="ratif-doc__marca" aria-hidden="true">VISTA PREVIA</div>
    <?php elseif ($anulada): ?>
        <div class="ratif-doc__marca ratif-doc__marca--anulada" aria-hidden="true">ANULADA</div>
    <?php endif; ?>

    <!-- ── Cabecera institucional (estilo del informe de Acompañamiento) ── -->
    <header class="ratif-doc__cabecera">
        <img class="ratif-doc__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="ratif-doc__inst">
            <p class="ratif-doc__rector"><?= e($inst['ente_rector'] ?? '') ?></p>
            <p class="ratif-doc__colegio"><?= e($inst['nombre_oficial'] ?? '') ?></p>
            <p class="ratif-doc__inst-datos">
                Código Modular: <?= e($codModular) ?> &middot; Código de Local: <?= e($inst['codigo_local'] ?? '') ?>
                <?php if (!empty($inst['resoluciones'])): ?>&middot; <?= e($inst['resoluciones']) ?><?php endif; ?>
            </p>
        </div>
        <?php // El QR va en la cabecera: se ve sin buscarlo y no compite con las firmas. ?>
        <div class="ratif-doc__verif">
            <?php if ($urlVerificar !== null && !$vistaPrevia): ?>
                <div class="ratif-doc__qr-code" data-qr-url="<?= e($urlVerificar) ?>"></div>
                <p class="ratif-doc__verif-texto">Verificación</p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($lema !== ''): ?>
        <p class="ratif-doc__lema">«<?= e($lema) ?>»</p>
    <?php endif; ?>

    <?php // Sin subtítulo fijo: el título lo redacta Dirección en la plantilla. ?>
    <h1 class="ratif-doc__titulo"><?= e($plantilla['titulo']) ?></h1>

    <!-- ── Partes (contrato) o datos del destinatario (comunicado) ── -->
    <table class="ratif-doc__partes">
        <?php if ($esContrato): ?>
            <tr>
                <th scope="row">El colegio</th>
                <td>
                    <?= e($inst['nombre_oficial'] ?? '') ?>, representado por su
                    <?= e($cargoDirector) ?><?php if (!empty($directorEbr['nombre_completo'])): ?>,
                    <strong><?= e($directorEbr['nombre_completo']) ?></strong><?php endif; ?>.
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <th scope="row"><?= $esContrato ? 'El apoderado' : 'Apoderado(a)' ?></th>
            <td>
                <?php if ($apoderado !== ''): ?>
                    <strong><?= e($apoderado) ?></strong>
                    &middot; DNI <?= e($vars['apoderado_dni']) ?>
                    <?php if ($vars['parentesco'] !== ''): ?>&middot; <?= e($vars['parentesco']) ?><?php endif; ?>
                <?php else: ?>
                    <span class="ratif-doc__llenar">Nombre:</span>
                    <span class="ratif-doc__llenar">DNI:</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?= $esContrato ? 'El estudiante' : 'Estudiante' ?></th>
            <td>
                <strong><?= e($vars['estudiante']) ?></strong> &middot; DNI <?= e($vars['dni']) ?>
                <br><?= e($gradoTexto) ?> &middot; Año académico <?= e($vars['anio']) ?>
            </td>
        </tr>
    </table>

    <!-- ── Cuerpo por bloques (las cláusulas o el texto del comunicado) ── -->
    <div class="ratif-doc__cuerpo">
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
            <section class="ratif-doc__motivo">
                <h2 class="ratif-doc__motivo-titulo">Motivo</h2>
                <p class="ratif-doc__motivo-texto"><?= e($motivoTexto) ?></p>
                <?php if (!empty($detalleTexto)): ?>
                    <p class="ratif-doc__motivo-texto"><?= e($detalleTexto) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <!-- ── Lugar, fecha y firmas: no se separan entre hojas ── -->
    <div class="ratif-doc__cierre">
        <p class="ratif-doc__lugar-fecha"><?= e($inst['lugar'] ?? 'Huaraz') ?>, <?= fecha_es($fecha) ?>.</p>

        <div class="ratif-doc__firmas<?= $esContrato ? '' : ' ratif-doc__firmas--una' ?>">
            <div class="ratif-doc__firma">
                <div class="ratif-doc__firma-espacio">
                    <?php if ($conFirma && !empty($directorEbr['sello_path'])): ?>
                        <img src="<?= url($directorEbr['sello_path']) ?>" alt="" aria-hidden="true" class="ratif-doc__sello-img">
                    <?php endif; ?>
                    <?php if ($conFirma && !empty($directorEbr['firma_path'])): ?>
                        <img src="<?= url($directorEbr['firma_path']) ?>" alt="" aria-hidden="true" class="ratif-doc__firma-img">
                    <?php endif; ?>
                </div>
                <div class="ratif-doc__firma-linea"></div>
                <p class="ratif-doc__firma-nombre"><?= e($directorEbr['nombre_completo'] ?? '') ?></p>
                <p class="ratif-doc__firma-cargo"><?= e($cargoDirector) ?></p>
                <?php if ($esContrato): ?><p class="ratif-doc__firma-parte">Por el colegio</p><?php endif; ?>
            </div>

            <?php if ($esContrato): ?>
                <div class="ratif-doc__firma">
                    <div class="ratif-doc__firma-espacio"></div>
                    <div class="ratif-doc__firma-linea"></div>
                    <p class="ratif-doc__firma-nombre"><?= $apoderado !== '' ? e($apoderado) : '&nbsp;' ?></p>
                    <p class="ratif-doc__firma-cargo">DNI <?= $apoderado !== '' ? e($vars['apoderado_dni']) : '' ?></p>
                    <p class="ratif-doc__firma-parte">El apoderado</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="ratif-doc__pie">
        <p>
            <?= e($inst['direccion'] ?? '') ?><?php if (!empty($inst['ubicacion'])): ?> &middot; <?= e($inst['ubicacion']) ?><?php endif; ?>
            <?php if (!empty($inst['telefonos'])): ?> &middot; Tel. <?= e($inst['telefonos']) ?><?php endif; ?>
        </p>
        <?php if ($urlVerificar !== null && !$vistaPrevia): ?>
            <p class="ratif-doc__pie-verif">Verifica la autenticidad de este documento escaneando el código QR o en:</p>
            <p class="ratif-doc__pie-verif"><?= e($urlVerificar) ?></p>
        <?php endif; ?>
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
