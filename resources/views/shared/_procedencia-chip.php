<?php
/**
 * Chip de PROCEDENCIA de una nota — partial compartido.
 *
 * Se incluye con dos variables sueltas, no con un array, para que la llamada
 * quepa en una línea dentro de una tabla densa:
 *
 *     <?php $proc = 'origen'; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
 *
 * @var string $proc       clave de PROCEDENCIAS_NOTA (helpers.php)
 * @var bool   $procIcono  pintar el icono (cards y cabeceras) o no (grillas densas)
 * @var bool   $procLargo  usar el nombre largo en vez del corto
 */
$__p = procedencia_nota($proc ?? '');
if ($__p === null) {
    // Clave desconocida: no se pinta nada y no se revienta la vista. Las
    // variables se limpian AQUÍ TAMBIÉN, o un chip que no llegó a pintarse
    // le heredaría el icono o el nombre largo al siguiente `require`.
    unset($__p, $procIcono, $procLargo);
    return;
}
$__conIcono = !empty($procIcono);
$__texto    = !empty($procLargo) ? $__p['nombre'] : $__p['corto'];
?>
<span class="proc-chip proc-chip--<?= e($proc) ?><?= $__p['ambar'] ? ' proc-chip--ambar' : '' ?>">
    <?php if ($__conIcono): ?>
        <span class="proc-chip__ico proc-chip__ico--<?= e($__p['icono']) ?>" aria-hidden="true"></span>
    <?php endif; ?><?= e($__texto) ?>
</span>
<?php
// Las variables se limpian para que un segundo require en la misma vista no
// herede el icono o el nombre largo del anterior.
unset($__p, $__conIcono, $__texto, $procIcono, $procLargo);
