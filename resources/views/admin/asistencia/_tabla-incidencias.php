<?php
/**
 * Tabla de incidencias de asistencia — FUENTE ÚNICA (25/08/2026).
 *
 * La usan las DOS pantallas que muestran este registro:
 *   - `admin/asistencia/seccion.php`      → Registro Académico (editable e historial)
 *   - `consulta-notas/asistencia.php`     → Dirección (solo lectura)
 *
 * Antes eran dos tablas distintas para el mismo dato: RA con `asistencia-tabla`
 * y la consulta reimplementada con `tabla-resumen` + `text-center`, sin ancho
 * fijo en los contadores. Cualquier retoque había que acordarse de hacerlo dos
 * veces. El modo solo lectura NO se invento aqui: ya existía en la vista de RA
 * para el historial de bimestres cerrados.
 *
 * ⚠️ EL MODO EDITABLE ALIMENTA A `public/js/asistencia.js`, que engancha por
 * `.asistencia-fila`, `.asistencia-input`, `.asistencia-guardar`,
 * `.asistencia-status` y los `data-matricula-id` / `data-periodo-id` /
 * `data-csrf`. Cambiar cualquiera de esos nombres rompe el guardado de RA en
 * producción, en silencio: el JS simplemente deja de encontrar las filas.
 *
 * @var array  $estudiantes [{ matricula_id, nombre_completo, incidencias{...} }]
 * @var array  $totales     salida de AsistenciaModel::totalesIncidencias()
 * @var bool   $editable    true = inputs + guardar (RA); false = solo lectura
 * @var int    $pidVer      periodo mostrado (viaja al JS como data-periodo-id)
 * @var string $csrfToken   solo se usa si $editable
 * @var int    $topeMax     solo se usa si $editable
 */

use App\Models\AsistenciaModel;

$campos    = AsistenciaModel::CAMPOS;
$editable  = $editable ?? false;
$csrfToken = $csrfToken ?? '';
$topeMax   = $topeMax ?? 99;

// Abreviaturas de las columnas. El `title` se conserva, pero NO es la
// explicacion: un tooltip no existe en movil ni con teclado. La leyenda de
// abajo es la que de verdad explica, y por eso sale de la misma tabla.
$abreviaturas = [
    'faltas'                 => ['F',  'Faltas'],
    'faltas_justificadas'    => ['FJ', 'Faltas justificadas'],
    'tardanzas'              => ['T',  'Tardanzas'],
    'tardanzas_justificadas' => ['TJ', 'Tardanzas justificadas'],
];
?>

<div class="tabla-notas-wrapper">
    <table class="tabla-notas asistencia-tabla">
        <thead>
            <tr>
                <th class="col-num">N°</th>
                <th class="col-nombre">Apellidos y Nombres</th>
                <?php foreach ($campos as $campo): ?>
                    <th class="asistencia-th-contador" title="<?= e($abreviaturas[$campo][1]) ?>">
                        <?= e($abreviaturas[$campo][0]) ?>
                    </th>
                <?php endforeach; ?>
                <?php if ($editable): ?>
                    <th class="asistencia-th-acciones">Acción</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $i => $est): $inc = $est['incidencias']; ?>
                <?php // Los data-* son EXCLUSIVAMENTE para el JS de guardado, asi que
                      // solo se emiten en modo editable: en solo lectura no hay script
                      // que los lea y no se siembra el token CSRF en una pagina que
                      // nunca escribe. ?>
                <tr class="asistencia-fila<?= !empty($inc['registrado']) ? ' asistencia-fila--registrada' : '' ?>"
                    <?php if ($editable): ?>data-matricula-id="<?= (int) $est['matricula_id'] ?>"
                    data-periodo-id="<?= (int) $pidVer ?>"
                    data-csrf="<?= e($csrfToken) ?>"<?php endif; ?>>
                    <td class="col-num"><?= $i + 1 ?></td>
                    <td class="col-nombre">
                        <?= e($est['nombre_completo']) ?>
                        <?php // Fila de la via extraordinaria de RA (migracion 063). ?>
                        <?php if (!empty($inc['extraordinaria'])): ?>
                            <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                        <?php endif; ?>
                    </td>

                    <?php foreach ($campos as $campo): $val = (int) $inc[$campo]; ?>
                        <?php if ($editable): ?>
                            <td class="asistencia-td-input">
                                <input type="number"
                                       class="asistencia-input"
                                       name="<?= $campo ?>"
                                       min="0"
                                       max="<?= (int) $topeMax ?>"
                                       step="1"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       value="<?= $val ?>"
                                       data-inicial="<?= $val ?>"
                                       aria-label="<?= e($abreviaturas[$campo][1]) ?> de <?= e($est['nombre_completo']) ?>">
                            </td>
                        <?php else: ?>
                            <td class="asistencia-td-valor"><?= $val ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($editable): ?>
                        <td class="asistencia-td-acciones">
                            <button type="button" class="btn btn--primary btn--sm asistencia-guardar">
                                Guardar
                            </button>
                            <span class="asistencia-status" aria-live="polite"></span>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <?php // Totales de la seccion. En RA sirven para cuadrar el registro ANTES
              // de bloquearlo, que es el momento en que importan. ?>
        <tfoot>
            <tr class="asistencia-totales">
                <td colspan="2">Total de la sección</td>
                <?php foreach ($campos as $campo): ?>
                    <td class="asistencia-td-valor"><?= (int) ($totales[$campo] ?? 0) ?></td>
                <?php endforeach; ?>
                <?php if ($editable): ?>
                    <td></td>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>
</div>

<?php // `.tabla-pie` y `.tabla-pie__leyenda` son del sistema (components/_tables.scss),
      // no de esta pantalla: la grilla de conducta usa las mismas. El pie va FUERA
      // del wrapper de scroll a proposito — dentro se desplaza con la tabla y lo
      // recorta el `overflow: hidden` del card. Aqui no hay card, de ahi --suelto. ?>
<div class="tabla-pie tabla-pie--suelto">
<p class="tabla-pie__leyenda">
    <?php foreach ($abreviaturas as $campo => [$corta, $larga]): ?>
        <span class="tabla-pie__item">
            <strong><?= e($corta) ?></strong> <?= e($larga) ?>
        </span>
    <?php endforeach; ?>
    <span class="tabla-pie__item tabla-pie__item--bloque tabla-pie__item--registrada">
        Fila resaltada: estudiante <strong>con registro</strong> guardado.
        Sin resaltar se muestra en cero, que no es lo mismo que cero incidencias confirmadas.
    </span>
</p>
</div>
