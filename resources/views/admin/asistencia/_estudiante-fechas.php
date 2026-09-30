<?php
/**
 * Asistencia POR FECHAS — un estudiante por pantalla (29/09/2026, migración 069),
 * pensada para el celular del auxiliar. Calendario del bimestre mes a mes
 * (semanas × lunes–viernes) con celdas grandes, los 4 totales del bimestre y la
 * lista de sus justificaciones con su motivo.
 *
 * 🔴 NO TIENE JS PROPIO: respeta el contrato de DOM de `_grilla-fechas.php`
 * (`.af-fila`, celdas de `_af-celda.php`, `.af-total`, `.af-estado`…), así que
 * `asistencia-fechas.js` lo maneja igual que la grilla, con el mismo MENÚ por
 * celda (`_af-menu.php`, 30/09/2026). La LISTA DEL DÍA es de la sección: aquí no
 * hay botón «Pasar lista» (va en la grilla), pero cualquier marca la toma.
 * `registro-estudiante.js` añade «← Anterior» / «Confirmar y siguiente →».
 *
 * Variables heredadas de `estudiante.php`: $est, $inc, $pos, $ultimo, $csrfToken,
 * $periodo, $siguienteUrl, $anteriorUrl, $calendario, $dias, $motivos, $jornadas.
 */

use App\Models\AsistenciaModel;

$etiquetasAf = [
    'faltas'                 => ['F',  'Faltas'],
    'faltas_justificadas'    => ['FJ', 'Faltas justificadas'],
    'tardanzas'              => ['T',  'Tardanzas'],
    'tardanzas_justificadas' => ['TJ', 'Tardanzas justificadas'],
];
$cabeceraSemana = ['L', 'Mar.', 'Mié.', 'J', 'V'];
?>

<section class="asistencia-fila af-fila registro-estudiante<?= !empty($inc['confirmado']) ? ' asistencia-fila--registrada' : '' ?>"
         data-matricula-id="<?= (int) $est['matricula_id'] ?>"
         data-periodo-id="<?= (int) $periodo['id'] ?>"
         data-csrf="<?= e($csrfToken) ?>"
         data-confirmada="<?= !empty($inc['confirmado']) ? '1' : '0' ?>"
         data-registrada="<?= !empty($inc['registrado']) ? '1' : '0' ?>"
         data-nombre="<?= e($est['nombre_completo']) ?>"
         data-siguiente="<?= e($siguienteUrl) ?>">

    <header class="registro-estudiante__cabecera">
        <span class="registro-estudiante__numero" title="N.° de lista">N.° <?= $pos + 1 ?></span>
        <h2 class="registro-estudiante__nombre"><?= e($est['nombre_completo']) ?></h2>
        <span class="af-estado"></span>
        <span class="asistencia-status" aria-live="polite"></span>
    </header>

    <?php // Totales del BIMESTRE: los calcula el servidor de las fechas. ?>
    <dl class="af-totales">
        <?php foreach (AsistenciaModel::CAMPOS as $c): [$corta, $larga] = $etiquetasAf[$c]; ?>
            <div class="af-totales__item" title="<?= e($larga) ?>">
                <dt><span class="af-tipo af-tipo--<?= strtolower($corta) ?>"><?= e($corta) ?></span></dt>
                <dd class="af-total" data-campo="<?= $c ?>"><?= (int) $inc[$c] ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

    <?php foreach ($calendario as $clave => $mes): ?>
        <h3 class="af-mes__titulo"><?= e($mes['nombre']) ?></h3>
        <div class="af-mes" role="grid" aria-label="<?= e($mes['nombre']) ?>">
            <?php foreach ($cabeceraSemana as $cab): ?>
                <span class="af-mes__cab" role="columnheader"><?= e($cab) ?></span>
            <?php endforeach; ?>
            <?php foreach ($mes['dias'] as $d):
                // Columna = día de la semana (1 = lunes … 5 = viernes), por CLASE
                // (nunca CSS inline): la grilla de 5 columnas deja sola los huecos
                // de un mes o bimestre que no empieza en lunes.
                $colClase = 'af-col-' . (int) date('N', strtotime($d['fecha']));
                $x        = $dias[$d['fecha']] ?? null;
                $tomada   = isset($jornadas[$d['fecha']]);
                $editable = true;
                $grande   = true;
                $etiqueta = '';
                require VIEW_PATH . '/admin/asistencia/_af-celda.php';
            endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php // Leyenda de colores (30/09/2026): la misma pastilla que la grilla. ?>
    <div class="tabla-pie tabla-pie--suelto">
        <p class="tabla-pie__leyenda">
            <span class="tabla-pie__item"><span class="af-tipo af-tipo--asistio">✓</span> Asistió</span>
            <?php foreach (AsistenciaModel::CAMPOS as $c): [$corta, $larga] = $etiquetasAf[$c]; ?>
                <span class="tabla-pie__item">
                    <span class="af-tipo af-tipo--<?= strtolower($corta) ?>"><?= e($corta) ?></span> <?= e($larga) ?>
                </span>
            <?php endforeach; ?>
            <span class="tabla-pie__item"><span class="af-tipo af-tipo--sin-tomar">⚠</span> Sin tomar lista</span>
            <span class="tabla-pie__item"><span class="af-tipo af-tipo--nl">NL</span> No lectivo</span>
        </p>
    </div>

    <?php // Sus FJ/TJ con el motivo: tocar una abre el menú del día (lo arma el JS). ?>
    <ul class="af-justificaciones" hidden></ul>

    <div class="registro-estudiante__acciones">
        <?php if ($anteriorUrl !== null): ?>
            <a href="<?= e($anteriorUrl) ?>" class="btn btn--secondary" data-accion="anterior">← Anterior</a>
        <?php endif; ?>
        <button type="button" class="btn btn--primary" data-accion="siguiente">
            <?= $ultimo ? 'Confirmar y volver a la grilla' : 'Confirmar y siguiente →' ?>
        </button>
    </div>
</section>

<?php require VIEW_PATH . '/admin/asistencia/_af-menu.php'; ?>
