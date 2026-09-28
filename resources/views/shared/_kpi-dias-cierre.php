<?php
/**
 * KPI «días para el cierre» de los paneles (`.dpanel-kpi`) — PUNTO ÚNICO
 * (28/09/2026). Lo incluyen `docente/inicio.php` y `auxiliar/inicio.php`;
 * antes cada vista llevaba su propia copia de los umbrales.
 *
 * Umbrales: ≤ 3 días rojo · ≤ 7 ámbar · más, verde. Pasada la fecha, gris con
 * «días desde el cierre»; sin fecha límite, gris con «—».
 *
 * @var int|null $diasCierre días hasta `periodos.limite_notas` (negativo = ya pasó)
 */

if ($diasCierre === null)  { $diasMod = 'muted'; $diasTxt = '—';             $diasSub = 'Sin fecha límite'; }
elseif ($diasCierre < 0)   { $diasMod = 'muted'; $diasTxt = abs($diasCierre); $diasSub = 'días desde el cierre'; }
elseif ($diasCierre <= 3)  { $diasMod = 'err';   $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }
elseif ($diasCierre <= 7)  { $diasMod = 'warn';  $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }
else                       { $diasMod = 'ok';    $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }
?>
<div class="dpanel-kpi">
    <span class="dpanel-kpi__num dpanel-kpi__num--<?= $diasMod ?>"><?= $diasTxt ?></span>
    <span class="dpanel-kpi__label"><?= $diasSub ?></span>
</div>
