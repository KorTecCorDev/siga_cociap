<?php
/**
 * Leyenda de la grilla por fechas (09/10/2026, decisión del usuario): solo los
 * símbolos y UNA línea. Va DESPUÉS del bloque «Bloquear y aprobar» para que el
 * auxiliar lo tenga a la vista; antes estaba entre la grilla y el bloqueo, con
 * un párrafo que explicaba reglas internas del conteo.
 *
 * @var array $fechas  datos de la grilla (usa 'motivoPrincipal')
 */

use App\Models\AsistenciaModel;

$abrevLey  = ['faltas' => 'F', 'faltas_justificadas' => 'FJ', 'tardanzas' => 'T', 'tardanzas_justificadas' => 'TJ'];
$nombreLey = ['faltas' => 'Falta', 'faltas_justificadas' => 'Falta justificada', 'tardanzas' => 'Tardanza', 'tardanzas_justificadas' => 'Tardanza justificada'];
?>
<div class="tabla-pie tabla-pie--suelto">
    <p class="tabla-pie__leyenda">
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--asistio">✓</span> Asistió</span>
        <?php foreach (AsistenciaModel::CAMPOS as $c): ?>
            <span class="tabla-pie__item">
                <span class="af-tipo af-tipo--<?= strtolower($abrevLey[$c]) ?>"><?= $abrevLey[$c] ?></span> <?= e($nombreLey[$c]) ?>
            </span>
        <?php endforeach; ?>
        <?php if (!empty($fechas['motivoPrincipal'])): ?>
            <span class="tabla-pie__item">
                <span class="af-tipo af-tipo--fj af-tipo--con-motivo">FJ</span> <?= e($fechas['motivoPrincipal']) ?>
            </span>
        <?php endif; ?>
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--sin-tomar">⚠</span> Sin tomar lista</span>
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--nl">NL</span> No lectivo</span>
        <span class="tabla-pie__item tabla-pie__item--bloque">
            Los totales son del <strong>bimestre entero</strong>, no solo del mes.
        </span>
    </p>
</div>
