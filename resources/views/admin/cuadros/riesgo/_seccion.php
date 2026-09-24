<?php
/**
 * Bloque de riesgo de UNA sección, pensado para su tutor (23/09/2026).
 *
 * PUNTO ÚNICO del informe que va a cada tutor: lo pintan el lote de Dirección
 * (`tutores.php`, una sección por hoja) y el panel del tutor
 * (`docente/riesgo/`). Los datos salen de `riesgo_por_seccion()`.
 *
 * Contenido enfocado al tutor (decisión del usuario): resumen corto de la
 * sección, los casos de permanencia, concentración por área y docente, «Cómo
 * leer» y el listado con desglose. Sin las tablas de distribución por
 * grado/sección, que con una sola sección no dicen nada.
 *
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU (RR o PER).
 *
 * El tutor que se nombra es el ACTUAL (`secciones.tutor_id` no guarda
 * historial): el encabezado lo dice.
 *
 * Se incluye desde un closure (`$pintarSeccion`), así que sus variables no se
 * filtran a la página que lo llama.
 *
 * @var array $bloque  {seccion, filtrado, stats} de `riesgo_por_seccion()`
 */
$sec = $bloque['seccion'];
$res = $bloque['stats']['resumen'];

// Los partials del informe leen `$riesgo`: se arma aquí con los datos de ESTA
// sección, para que describan exactamente lo mismo que el resumen corto.
$riesgo = [
    'stats'    => $bloque['stats'],
    'filtrado' => $bloque['filtrado'],
];
?>
<section class="riesgo-seccion" data-riesgo-seccion="<?= (int) $sec['id'] ?>">
    <div class="riesgo-seccion__cab">
        <h2 class="riesgo-seccion__titulo">
            <?= e($sec['grado_nombre'] . ' ' . $sec['nombre'] . ' de ' . $sec['nivel_nombre']) ?>
        </h2>
        <p class="riesgo-seccion__tutor">
            Tutor(a) actual:
            <strong><?= $sec['tutor_nombre'] !== null ? e($sec['tutor_nombre']) : 'sin tutor asignado' ?></strong>
        </p>
    </div>

    <?php if ((int) $res['evaluados'] === 0): ?>
        <p class="riesgo-seccion__vacio">
            Esta sección todavía no tiene estudiantes evaluados en el bimestre (aún no hay
            competencias bloqueadas).
        </p>
    <?php elseif ((int) $res['total'] === 0): ?>
        <p class="riesgo-seccion__vacio riesgo-seccion__vacio--bien">
            Con las notas de este bimestre, los <?= (int) $res['evaluados'] ?> estudiantes
            evaluados de esta sección alcanzarían la promoción de grado.
        </p>
    <?php else: ?>
        <div class="cuadros-banda cuadros-banda--riesgo riesgo-banda">
            <p class="cuadros-banda__cifra">
                <span class="cuadros-banda__n"><?= (int) $res['total'] ?></span>
                <span class="cuadros-banda__q">
                    estudiante<?= $res['total'] !== 1 ? 's' : '' ?> no alcanzarían la promoción,
                    de <strong><?= (int) $res['evaluados'] ?></strong> evaluados
                    (<strong><?= (int) $res['pct'] ?>%</strong>)
                </span>
            </p>
            <ul class="cuadros-banda__datos">
                <li><strong><?= (int) $res['permanencia'] ?></strong> permanecerían en el grado (PER)</li>
                <li><strong><?= (int) $res['recuperacion'] ?></strong> requieren recuperación (RR)</li>
            </ul>
            <?php if (!$res['cobertura']['completa']): ?>
                <p class="riesgo-banda__cobertura">
                    <strong>Proyección parcial:</strong> todavía faltan áreas por calificar, así que
                    la situación final puede empeorar al completarse el bimestre.
                </p>
            <?php endif; ?>
        </div>

        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_criticos.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_concentracion.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_leer.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_listado.php'; ?>
    <?php endif; ?>

    <?php // Fuera del `if`: un 1.º de primaria no tiene riesgo por definición,
          // y su tutor igual necesita la lista de seguimiento. ?>
    <?php if ((int) $res['evaluados'] > 0): ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_automatica.php'; ?>
    <?php endif; ?>
</section>
