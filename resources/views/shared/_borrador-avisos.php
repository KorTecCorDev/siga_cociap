<?php
/**
 * Avisos del BORRADOR de un formulario (migración 074, 06/10/2026). Los valores
 * ya vienen pintados en los campos por el servidor; aquí solo se avisa.
 *
 * @var array $borrador  restaurado (bool), actualizado_en (?string), otros (lista nombre/fecha)
 */
$borrador = is_array($borrador ?? null) ? $borrador : [];
?>
<?php if (!empty($borrador['restaurado']) && !empty($borrador['actualizado_en'])): ?>
<div class="flash flash--info">
    Se recuperó tu borrador del <strong><?= e(date('d/m/Y H:i', strtotime((string) $borrador['actualizado_en']))) ?></strong>.
    Revisa los datos antes de guardar.
</div>
<?php endif; ?>
<?php foreach (($borrador['otros'] ?? []) as $otro): ?>
<div class="flash flash--warning">
    <strong><?= e((string) $otro['nombre']) ?></strong> tiene un borrador sin registrar de este formulario
    (<?= e(date('d/m/Y H:i', strtotime((string) $otro['actualizado_en']))) ?>).
</div>
<?php endforeach; ?>
