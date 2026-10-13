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
 * $periodo, $siguienteUrl, $anteriorUrl, $calendario, $dias, $motivos, $jornadas,
 * $presentes (✓ propios, migración 077), $motivoPrincipal, $motivoPrincipalId.
 */

use App\Models\AsistenciaModel;

$etiquetasAf = [
    'faltas'                 => ['F',  'Faltas'],
    'faltas_justificadas'    => ['FJ', 'Faltas justificadas'],
    'tardanzas'              => ['T',  'Tardanzas'],
    'tardanzas_justificadas' => ['TJ', 'Tardanzas justificadas'],
];
$cabeceraSemana = ['L', 'Mar.', 'Mié.', 'J', 'V'];
$motPrincipal   = $motivoPrincipalId ?? null;   // icono de documento en `_af-celda.php`
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

        <?php // Totales del BIMESTRE: los calcula el servidor de las fechas. Van
              // DENTRO de la cabecera fija (09/10/2026, pedido del usuario): al
              // recorrer los últimos meses siguen a la vista. ?>
        <dl class="af-totales">
            <?php foreach (AsistenciaModel::CAMPOS as $c): [$corta, $larga] = $etiquetasAf[$c]; ?>
                <div class="af-totales__item" title="<?= e($larga) ?>">
                    <dt><?php if ($c === 'faltas_justificadas' && !empty($motivoPrincipal)):
                            $nombreAnclaFj = $motivoPrincipal; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php';
                        else: ?><span class="af-tipo af-tipo--<?= strtolower($corta) ?>"><?= e($corta) ?></span><?php endif; ?></dt>
                    <dd class="af-total" data-campo="<?= $c ?>"><?= (int) $inc[$c] ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>

        <span class="asistencia-status" aria-live="polite"></span>
    </header>

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
                // ✓ = su propia marca o la lista de la sección (077).
                $tomada   = isset($jornadas[$d['fecha']]) || isset($presentes[$d['fecha']]);
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
            <?php if (!empty($motivoPrincipal)): ?>
                <span class="tabla-pie__item tabla-pie__item--bloque">
                    En el total <strong>F</strong> también se cuentan las FJ cuyo motivo no es
                    «<?= e($motivoPrincipal) ?>».
                </span>
            <?php endif; ?>
        </p>
    </div>

    <?php // Sus FJ/TJ con el motivo (09/10/2026): chips con el color de la pastilla
          // del contador y una tabla ordenada por fecha. Los arma el JS en cada
          // cambio; tocar un chip o una fila lleva al día y abre su menú. ?>
    <div class="af-justificaciones" hidden>
        <h3 class="af-justificaciones__titulo">Justificaciones</h3>
        <ul class="af-justificaciones__chips"></ul>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas asistencia-tabla tabla-anexo af-justificaciones__tabla">
                <thead>
                    <tr>
                        <th class="tr-num">N&deg;</th>
                        <th class="tr-anexo-fecha">Fecha</th>
                        <th class="tr-anexo-tipo">Tipo</th>
                        <th class="tr-anexo-motivo">Motivo</th>
                        <th class="tr-anexo-tipo">Cuenta como</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="registro-estudiante__acciones">
        <?php if ($anteriorUrl !== null): ?>
            <a href="<?= e($anteriorUrl) ?>" class="btn btn--secondary" data-accion="anterior">← Anterior</a>
        <?php endif; ?>
        <button type="button" class="btn btn--primary" data-accion="siguiente">
            <?= $ultimo ? 'Confirmar y volver a la tabla' : 'Confirmar y siguiente →' ?>
        </button>
    </div>
</section>

<?php require VIEW_PATH . '/admin/asistencia/_af-menu.php'; ?>
