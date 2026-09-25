<?php
/**
 * Chip de EFECTO de una competencia en la situación final (24/09/2026).
 * PER y RR llevan un signo de alerta a la izquierda; el SVG pinta en
 * `currentColor`, así que toma el rojo o el ámbar del chip (también en el A4).
 * El límite va sin signo: no pone en riesgo, avisa.
 *
 * @var string $efecto  EFECTO_PER | EFECTO_RR | EFECTO_LIMITE
 */
$efectoAlerta = $efecto === EFECTO_PER || $efecto === EFECTO_RR;
?><span class="riesgo-efecto riesgo-efecto--<?= e($efecto) ?>"><?php if ($efectoAlerta): ?><svg class="riesgo-efecto__icono" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3.45 18.11 10.21 4.58c.74-1.48 2.84-1.48 3.58 0l6.76 13.53c.67 1.33-.3 2.89-1.79 2.89H5.24c-1.49 0-2.46-1.56-1.79-2.89Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1" fill="currentColor"/></svg><?php endif; ?><?= e(situacion_efecto_rotulo($efecto)) ?></span>
