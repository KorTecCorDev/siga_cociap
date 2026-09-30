<?php
/**
 * Justificaciones — DÍAS CRÍTICOS del colegio (29/09/2026): las fechas con más
 * estudiantes ausentes (F o FJ), los primeros con sus empates. Compartido por la
 * pantalla y el A4.
 *
 * @var array $just  bloque `justificaciones` de componerBloques()
 */
if (empty($just['criticos'])) {
    return;
}
?>
<div class="tabla-notas-wrapper">
    <table class="tabla-notas cuadros-top">
        <caption class="cuadros-top__caption">Días con más estudiantes ausentes</caption>
        <thead>
            <tr>
                <th class="col-nombre">Fecha</th>
                <th>Día</th>
                <th class="text-center cuadros-top__n">Ausentes</th>
                <th class="text-center cuadros-top__n">% de estudiantes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($just['criticos'] as $d): ?>
                <tr>
                    <td class="col-nombre"><?= e($d['fecha']) ?></td>
                    <td><?= e($d['dia']) ?></td>
                    <td class="text-center"><?= (int) $d['ausentes'] ?></td>
                    <td class="text-center"><?= $d['pct'] === null ? '—' : e(str_replace('.', ',', (string) $d['pct'])) . '%' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
