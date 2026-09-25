<?php
/**
 * Resumen estadístico del informe de riesgo (23/09/2026). Compartido por la
 * pantalla y el A4: las cifras salen de `riesgo_estadisticas()` sobre la lista
 * YA FILTRADA, así que describen exactamente lo que se ve o se imprime.
 *
 * Tablas con una barra de proporción dibujada en CSS, sin librería de
 * gráficos (decisión del usuario): se imprime nítida, no parte hojas y el
 * número va siempre al lado. La barra NO lleva `style` inline (regla del
 * repo): el ancho es una clase `riesgo-barra__v--N` que genera SASS (0-100).
 *
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU: requiere
 * recuperación (RR) o permanece en el grado (PER). Ver `situacion_final()`.
 *
 * @var array $riesgo  salida de CuadrosEstadisticosController::componerRiesgo
 */
$st  = $riesgo['stats'];
$res = $st['resumen'];

// Barra de proporción: `$n` sobre `$de`, redondeada a entero para la clase.
$barra = static function (int $n, int $de): string {
    $p = $de > 0 ? (int) round($n / $de * 100) : 0;
    return '<span class="riesgo-barra" aria-hidden="true"><span class="riesgo-barra__v riesgo-barra__v--'
         . max(0, min(100, $p)) . '"></span></span>';
};

// Etiqueta de un grado: «5.º Primaria».
$etqGrado = static fn(array $gr): string => $gr['nombre_display'] . ' ' . $gr['nivel_nombre'];
?>
<div class="riesgo-resumen">

    <?php // ── Banda de magnitud ───────────────────────────────────────── ?>
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
            <li><strong><?= (int) $res['grados'] ?> de <?= (int) $res['grados_total'] ?></strong> grados con casos</li>
            <?php // Certeza: solo la excepción (24/09/2026). Cuántos todavía
                  // pueden salvarse con lo pendiente; si no hay ninguno, callar. ?>
            <?php if ((int) $res['proyectados'] > 0): ?>
                <li><strong><?= (int) $res['proyectados'] ?></strong> depende<?= (int) $res['proyectados'] !== 1 ? 'n' : '' ?>
                    de competencias aún sin calificar (lo pendiente puede salvarlo<?= (int) $res['proyectados'] !== 1 ? 's' : '' ?>)</li>
            <?php endif; ?>
        </ul>
        <?php // Una línea por nivel: la regla del MINEDU cambia de uno a otro, y
              // una cifra global sola no diría qué se contó. ?>
        <ul class="riesgo-banda__niveles">
            <?php foreach ($res['por_nivel'] as $niv): ?>
                <li>
                    <strong><?= e($niv['nombre']) ?>:</strong>
                    <?= (int) $niv['total'] ?> de <?= (int) $niv['evaluados'] ?>
                    (<?= (int) $niv['pct'] ?>%) no alcanzaría<?= (int) $niv['total'] !== 1 ? 'n' : '' ?> la promoción
                    &middot; <?= (int) $niv['permanencia'] ?> permanecería<?= (int) $niv['permanencia'] !== 1 ? 'n' : '' ?> en el grado
                </li>
            <?php endforeach; ?>
        </ul>
        <?php // La COBERTURA decide cuánto vale esta proyección. Un bimestre a
              // medio calificar no cuenta las áreas sin nota, así que sale
              // optimista: callarlo convertiría un informe incompleto en una
              // buena noticia. ?>
        <?php if (!$res['cobertura']['completa']): ?>
            <p class="riesgo-banda__cobertura">
                <strong>Proyección parcial.</strong>
                <?= (int) $res['cobertura']['parciales'] ?>
                estudiante<?= $res['cobertura']['parciales'] !== 1 ? 's' : '' ?>
                todavía no tiene<?= $res['cobertura']['parciales'] !== 1 ? 'n' : '' ?> calificadas
                todas las competencias de su plan: la situación se proyectó con las competencias
                que ya tienen nivel de logro, y la marca <em>Depende de N pendientes</em> señala a
                quien lo pendiente todavía puede sacar del riesgo.
            </p>
        <?php endif; ?>
        <?php $fuera = riesgo_fuera_del_calculo($res); ?>
        <?php if ($fuera !== [] || $res['seguimiento'] > 0 || $res['pro_proyectados'] > 0 || $res['pendiente_final'] > 0): ?>
            <ul class="cuadros-banda__datos">
                <?php if ($res['pro_proyectados'] > 0): ?>
                    <li><strong><?= (int) $res['pro_proyectados'] ?></strong> promovido<?= (int) $res['pro_proyectados'] !== 1 ? 's' : '' ?>
                        cuya promoción todavía depende de competencias pendientes</li>
                <?php endif; ?>
                <?php if ($res['pendiente_final'] > 0): ?>
                    <li><strong><?= (int) $res['pendiente_final'] ?></strong> con la situación final pendiente
                        (competencias sin nota en el periodo final)</li>
                <?php endif; ?>
                <?php // Sin datos, incorporados después y los que ya no pertenecen
                      // al colegio: tres grupos, texto único en `riesgo_fuera_del_calculo()`. ?>
                <?php foreach ($fuera as $frase): ?>
                    <li><?= e($frase) ?></li>
                <?php endforeach; ?>
                <?php if ($res['seguimiento'] > 0): ?>
                    <li><strong><?= (int) $res['seguimiento'] ?></strong> en seguimiento: con competencias bajas, estén o no en
                        riesgo; fuera de las cifras de riesgo (ver su bloque)</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php // ── Distribución por grado y por sección ─────────────────────── ?>
    <div class="riesgo-resumen__par">
        <div class="tabla-notas-wrapper">
        <table class="riesgo-stat">
            <caption>Distribución por grado</caption>
            <thead>
                <tr>
                    <th scope="col">Grado</th>
                    <th scope="col" class="riesgo-stat__n">Evaluados</th>
                    <th scope="col" class="riesgo-stat__n">En riesgo</th>
                    <th scope="col" class="riesgo-stat__barra">% del grado</th>
                    <th scope="col" class="riesgo-stat__n">Permanencia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($st['grados'] as $g): ?>
                    <tr>
                        <th scope="row"><?= e($etqGrado($g['grado'])) ?></th>
                        <td class="riesgo-stat__n"><?= (int) $g['total'] ?></td>
                        <td class="riesgo-stat__n"><strong><?= (int) $g['casos'] ?></strong></td>
                        <td class="riesgo-stat__barra"><?= $barra($g['casos'], $g['total']) ?> <?= (int) $g['pct'] ?>%</td>
                        <td class="riesgo-stat__n"><?= (int) $g['criticos'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="tabla-notas-wrapper">
        <table class="riesgo-stat">
            <caption>Distribución por sección</caption>
            <thead>
                <tr>
                    <th scope="col">Sección</th>
                    <th scope="col" class="riesgo-stat__n">Evaluados</th>
                    <th scope="col" class="riesgo-stat__n">En riesgo</th>
                    <th scope="col" class="riesgo-stat__barra">% de la sección</th>
                    <th scope="col" class="riesgo-stat__n">Permanencia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($st['secciones'] as $s): ?>
                    <tr>
                        <th scope="row"><?= e($s['grado']['nombre_display'] . ' ' . $s['seccion_nombre'] . ' ' . $s['grado']['nivel_codigo']) ?></th>
                        <td class="riesgo-stat__n"><?= (int) $s['total'] ?></td>
                        <td class="riesgo-stat__n"><strong><?= (int) $s['casos'] ?></strong></td>
                        <td class="riesgo-stat__barra"><?= $barra($s['casos'], $s['total']) ?> <?= (int) $s['pct'] ?>%</td>
                        <td class="riesgo-stat__n"><?= (int) $s['criticos'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_concentracion.php'; ?>
</div>
