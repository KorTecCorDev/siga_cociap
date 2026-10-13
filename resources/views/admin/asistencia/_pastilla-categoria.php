<?php
/**
 * PASTILLA de una categoría de la vista «Faltas y justificaciones» (13/10/2026).
 * Regla: UNA pastilla y UN texto. La pastilla es la MISMA que el auxiliar ve en
 * su grilla, y el texto que la acompaña dice lo que esa marca VALE. Nunca dos
 * pastillas que se contradigan («FJ → F»).
 *
 *   fj_autorizadas → FJ con el icono de documento (motivo principal), vía el
 *                    punto único `_pastilla-fj.php`
 *   fj_como_f      → FJ sin icono: justificada con otro motivo, cuenta como falta
 *   tj             → TJ
 *   f              → F (falta sin justificar)
 *
 * @var string      $cat              una de las claves de arriba
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 */
if ($cat === 'fj_autorizadas') {
    $nombreAnclaFj = $motivoPrincipal ?? null;
    if (!empty($nombreAnclaFj)) {
        require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php';
    } else {
        ?><span class="af-tipo af-tipo--fj af-tipo--con-motivo">FJ</span><?php
    }
} else {
    $sigla = ['fj_como_f' => 'FJ', 'tj' => 'TJ', 'f' => 'F'][$cat];
    ?><span class="af-tipo af-tipo--<?= strtolower($sigla) ?>"><?= $sigla ?></span><?php
}
