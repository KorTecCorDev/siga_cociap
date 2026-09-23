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
                estudiante<?= $res['total'] !== 1 ? 's' : '' ?> en riesgo académico de
                <strong><?= (int) $res['evaluados'] ?></strong> evaluados
                (<strong><?= (int) $res['pct'] ?>%</strong>)
            </span>
        </p>
        <ul class="cuadros-banda__datos">
            <li><strong><?= (int) $res['criticos'] ?></strong> de mayor atención</li>
            <li><strong><?= (int) $res['grados'] ?> de <?= (int) $res['grados_total'] ?></strong> grados con casos</li>
        </ul>
        <?php // Una línea por nivel: la regla cambia de uno a otro, y una cifra
              // global sola no diría qué se contó. ?>
        <ul class="riesgo-banda__niveles">
            <?php foreach ($res['por_nivel'] as $niv): ?>
                <li>
                    <strong><?= e($niv['nombre']) ?>:</strong>
                    <?= (int) $niv['total'] ?> de <?= (int) $niv['evaluados'] ?>
                    (<?= (int) $niv['pct'] ?>%) con <?= (int) $riesgo['min'] ?> o más
                    competencias <?= e($niv['rotulo']) ?>
                    &middot; <?= (int) $niv['criticos'] ?> con <?= (int) $riesgo['critico_min'] ?> o más
                </li>
            <?php endforeach; ?>
        </ul>
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
                    <th scope="col" class="riesgo-stat__n">Mayor atención</th>
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
                    <th scope="col" class="riesgo-stat__n">Mayor atención</th>
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
