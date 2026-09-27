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

    <?php if ((int) $res['evaluados'] > 0): ?>
        <h3 class="riesgo-seccion__bloque">Estudiantes en riesgo académico</h3>
    <?php endif; ?>
    <?php if ((int) $res['evaluados'] === 0): ?>
        <p class="riesgo-seccion__vacio">
            Esta sección todavía no tiene estudiantes evaluados en el bimestre (aún no hay
            competencias bloqueadas).
        </p>
    <?php elseif ((int) $res['total'] === 0): ?>
        <?php $sinCasosAlcance = 'de esta sección';
              $completa = $res['cobertura']['completa'] && (int) $res['sin_datos'] === 0; ?>
        <p class="riesgo-seccion__vacio<?= $completa ? ' riesgo-seccion__vacio--bien' : '' ?>">
            <?php require VIEW_PATH . '/admin/cuadros/riesgo/_sin-casos.php'; ?>
        </p>
    <?php else: ?>
        <div class="cuadros-banda cuadros-banda--riesgo riesgo-banda">
            <p class="cuadros-banda__cifra">
                <span class="cuadros-banda__n"><?= (int) $res['total'] ?></span>
                <span class="cuadros-banda__q">
                    estudiante<?= $res['total'] !== 1 ? 's' : '' ?> no alcanzaría<?= $res['total'] !== 1 ? 'n' : '' ?> la promoción,
                    de <strong><?= (int) $res['evaluados'] ?></strong> evaluados
                    (<strong><?= (int) $res['pct'] ?>%</strong>)
                </span>
            </p>
            <ul class="cuadros-banda__datos">
                <li><strong><?= (int) $res['permanencia'] ?></strong> permanecería<?= (int) $res['permanencia'] !== 1 ? 'n' : '' ?> en el grado (PER)</li>
                <li><strong><?= (int) $res['recuperacion'] ?></strong> requiere<?= (int) $res['recuperacion'] !== 1 ? 'n' : '' ?> recuperación (RR)</li>
                <?php if ((int) $res['proyectados'] > 0): ?>
                    <li><strong><?= (int) $res['proyectados'] ?></strong> depende<?= (int) $res['proyectados'] !== 1 ? 'n' : '' ?>
                        de competencias aún sin calificar</li>
                <?php endif; ?>
                <?php foreach (riesgo_fuera_del_calculo($res) as $frase): ?>
                    <li><?= e($frase) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$res['cobertura']['completa']): ?>
                <p class="riesgo-banda__cobertura">
                    <strong>Proyección parcial:</strong> faltan competencias por calificar; la
                    marca <em>Depende de N pendientes</em> señala a quien todavía pueden sacar del riesgo.
                </p>
            <?php endif; ?>
        </div>

        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_criticos.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_concentracion.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_leer.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_listado.php'; ?>
    <?php endif; ?>

    <?php // Fuera del `if`: el seguimiento son PROMOVIDOS (y 1.º de primaria,
          // que no tiene riesgo por definición), así que existe aunque la
          // sección no tenga ningún caso de riesgo. ?>
    <?php if ((int) $res['evaluados'] > 0): ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_seguimiento.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_pendiente-final.php'; ?>
    <?php endif; ?>
</section>
