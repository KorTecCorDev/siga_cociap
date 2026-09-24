<?php
/**
 * Situación final PENDIENTE (24/09/2026): solo en el PERIODO FINAL.
 *
 * 🔴 NO ES RIESGO NI PROMOCIÓN. En el último bimestre la situación ya no se
 * proyecta, y si al estudiante le falta alguna competencia de su plan no se
 * afirma nada hasta completarla (`SITUACION_PENDIENTE`). Es una RED DE
 * SEGURIDAD: la regla del periodo final exige evaluar todas las competencias,
 * así que esta lista solo aparece tras un cierre forzado o una válvula de RA.
 *
 * @var array $riesgo
 */
$filas = [];
foreach ($riesgo['filtrado'] as $g) {
    foreach ($g['pendiente_final'] ?? [] as $al) {
        $filas[] = $al + ['grado' => $g['grado']];
    }
}
if ($filas === []) {
    return;
}
?>
<section class="riesgo-automatica">
    <h2 class="riesgo-h2">Situación final pendiente</h2>
    <p class="riesgo-nota">
        Estamos en el <strong>periodo final</strong> y a estos estudiantes les falta el nivel de
        logro de alguna competencia de su plan: su situación final <strong>no se determina</strong>
        hasta completarla. No entran en ninguna de las cifras de riesgo.
    </p>
    <div class="tabla-notas-wrapper">
    <table class="riesgo-stat riesgo-stat--ancha">
        <thead>
            <tr>
                <th scope="col">Estudiante</th>
                <th scope="col">Grado y sección</th>
                <th scope="col" class="riesgo-stat__n">Pendientes</th>
                <th scope="col">Áreas</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($filas as $al): ?>
                <tr>
                    <th scope="row"><?= e($al['nombre_completo']) ?></th>
                    <td><?= e($al['grado']['nombre_display'] . ' ' . $al['seccion_nombre'] . ' de ' . $al['grado']['nivel_nombre']) ?></td>
                    <td class="riesgo-stat__n"><?= (int) $al['pendientes'] ?></td>
                    <td><?= e(implode(', ', $al['areas_pendientes'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
