<?php
/**
 * Asistencia POR FECHAS — un estudiante por pantalla (29/09/2026, migración 069),
 * pensada para el celular del auxiliar. Calendario del bimestre mes a mes
 * (semanas × lunes–viernes) con celdas grandes, los 4 totales del bimestre y la
 * lista de sus justificaciones con su motivo.
 *
 * 🔴 NO TIENE JS PROPIO: respeta el contrato de DOM de `_grilla-fechas.php`
 * (`.af-fila`, `.af-celda[data-fecha]`, `.af-total`, `.af-estado`…), así que
 * `asistencia-fechas.js` lo maneja igual que la grilla. `registro-estudiante.js`
 * añade «← Anterior» / «Confirmar y siguiente →».
 *
 * Variables heredadas de `estudiante.php`: $est, $inc, $pos, $ultimo, $csrfToken,
 * $periodo, $siguienteUrl, $anteriorUrl, $calendario, $dias, $motivos.
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
         data-sin-motivo-otros="0"
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
                <dt><?= e($corta) ?></dt>
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
                $col  = 'af-col-' . (int) date('N', strtotime($d['fecha']));
                $x    = $dias[$d['fecha']] ?? null;
                $tipo = $x['tipo'] ?? '';
                $mot  = $x['motivo_id'] ?? null;
            ?>
                <?php if ($d['marcable']): ?>
                    <button type="button" class="af-celda af-celda--grande <?= $col ?><?= $tipo !== '' ? ' af-celda--' . strtolower($tipo) : '' ?>"
                            data-fecha="<?= e($d['fecha']) ?>" data-tipo="<?= e($tipo) ?>"
                            data-motivo="<?= $mot !== null ? (int) $mot : '' ?>"
                            aria-label="<?= e($d['fecha'] . ': ' . ($tipo !== '' ? $tipo : 'sin incidencia')) ?>">
                        <span class="af-celda__num"><?= (int) $d['dia'] ?></span>
                        <span class="af-celda__tipo"><?= e($tipo) ?></span>
                    </button>
                <?php else: ?>
                    <span class="af-celda af-celda--grande af-celda--solo af-celda--futuro <?= $col ?>" title="<?= e($d['fecha']) ?>">
                        <span class="af-celda__num"><?= (int) $d['dia'] ?></span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php // Sus FJ/TJ con el motivo: tocar una abre el select (lo arma el JS). ?>
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

<?php require VIEW_PATH . '/admin/asistencia/_af-motivo.php'; ?>
