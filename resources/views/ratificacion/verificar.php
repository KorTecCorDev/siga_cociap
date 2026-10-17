<?php
/**
 * Verificación PÚBLICA del documento de ratificación (QR). Layout: digital.
 * `RatificacionVerificacionController::verificar`. Ver docs/modulos/ratificaciones.md.
 *
 * Solo autenticidad: nombre, año, fecha de emisión y estado. Nunca DNI, tipo
 * ni motivo. Token desconocido o mal formado: el mismo mensaje.
 *
 * @var string     $institucion
 * @var array|null $doc  RatificacionModel::porToken()
 */
?>

<div class="bpf-wrap">

    <header class="bpf-header">
        <img src="<?= url('assets/img/logo_cociap.png') ?>"
             alt="Logo COCIAP"
             class="bpf-header__logo">
        <h1 class="bpf-header__colegio"><?= e($institucion ?? '') ?></h1>
        <p class="bpf-header__ugel">MINEDU · DRE Áncash · UGEL Huaraz</p>
        <p class="bpf-header__titulo">Verificación de documento</p>
    </header>

    <main class="bpf-main">
        <div class="bpf-card ratif-verif">
            <?php if ($doc === null): ?>
                <div class="bpf-error" role="alert">
                    No se encontró ningún documento con este código. Verifica que escaneaste el código QR completo.
                </div>
            <?php else: ?>
                <?php if ($doc['vigente'] !== null): ?>
                    <span class="ratif-verif__estado ratif-verif__estado--vigente">Documento auténtico y vigente</span>
                <?php else: ?>
                    <span class="ratif-verif__estado ratif-verif__estado--anulado">Documento anulado</span>
                <?php endif; ?>

                <dl class="ratif-verif__datos">
                    <dt>Estudiante</dt>
                    <dd><?= e($doc['estudiante']) ?></dd>
                    <dt>Año académico</dt>
                    <dd><?= e((string) $doc['anio']) ?></dd>
                    <dt>Fecha de emisión</dt>
                    <dd><?= fecha_es(substr((string) $doc['emitido_en'], 0, 10)) ?></dd>
                    <?php if ($doc['vigente'] === null): ?>
                        <dt>Fecha de anulación</dt>
                        <dd><?= fecha_es(substr((string) $doc['anulado_en'], 0, 10)) ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if ($doc['vigente'] === null): ?>
                    <p class="bpf-card__desc">Este documento ya no es válido.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <p class="bpf-ayuda">
            ¿Tienes dudas sobre este documento? Acércate a la secretaría del colegio.
        </p>
    </main>

    <footer class="bpf-footer">
        <p>SIGA-COCIAP &mdash; Sistema de Gestión Académica</p>
    </footer>

</div>
