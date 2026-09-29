<?php
/**
 * Firmas del registro impreso de asistencia (Auxiliar Responsable + Personal de
 * Registro Académico). Van al final del DOCUMENTO: al pie de la tabla si no hay
 * anexo, o al final del «Detalle de incidencias» si lo hay (29/09/2026).
 *
 * @var array $firmas AuxiliarSeccionModel::firmasDelRegistro()
 */
?>
<footer class="reporte-footer">
    <div class="reporte-footer__bloque">
        <div class="reporte-footer__espacio-firma"></div>
        <div class="reporte-footer__linea"></div>
        <?php if (!empty($firmas['auxiliar'])): ?>
            <div class="reporte-footer__nombre"><?= e($firmas['auxiliar']) ?></div>
        <?php endif; ?>
        <div class="reporte-footer__cargo">Auxiliar Responsable</div>
    </div>
    <div class="reporte-footer__bloque">
        <div class="reporte-footer__espacio-firma"></div>
        <div class="reporte-footer__linea"></div>
        <?php if (!empty($firmas['ra'])): ?>
            <div class="reporte-footer__nombre"><?= e($firmas['ra']) ?></div>
        <?php endif; ?>
        <div class="reporte-footer__cargo">Personal de Registro Académico</div>
    </div>
</footer>
