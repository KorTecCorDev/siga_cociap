<?php
/**
 * ENCABEZADO de la columna FJ (08/10/2026, migración 076): la MISMA pastilla que
 * una celda FJ con el MOTIVO PRINCIPAL del calendario (contorno de FJ + icono de
 * documento en la esquina). Dice que el contador SOLO cuenta esas faltas; con
 * cualquier otro motivo cuentan como F. PUNTO ÚNICO del encabezado: lo usan el
 * registro de asistencia, su imprimible, la consulta de Dirección y los cuadros.
 *
 * Solo se pinta con ancla (bimestres POR FECHAS). En los de solo números (I y II
 * 2026) FJ no distinguía motivo: quien lo incluye conserva allí su rótulo de
 * siempre.
 *
 * @var string|null $nombreAnclaFj  nombre del motivo principal del bimestre, o null
 */
if (!empty($nombreAnclaFj)):
    $textoPastillaFj = 'Faltas justificadas: solo cuenta las de «' . $nombreAnclaFj . '»';
?><span class="af-tipo af-tipo--fj af-tipo--con-motivo" title="<?= e($textoPastillaFj) ?>" aria-label="<?= e($textoPastillaFj) ?>">FJ</span><?php
endif;
