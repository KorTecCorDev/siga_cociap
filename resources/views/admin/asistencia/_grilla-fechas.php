<?php
/**
 * Grilla MENSUAL de asistencia por fechas (29/09/2026, migración 069): filas =
 * estudiantes, columnas = días hábiles del mes dentro del bimestre, como la
 * planilla de papel. Al final de cada fila, los 4 contadores DEL BIMESTRE
 * (calculados de las fechas: invariante de `AsistenciaModel`).
 *
 * LISTA DEL DÍA (30/09/2026, migración 070): cada día de la sección está
 * «Sin tomar» hasta que se pasa lista (botón del encabezado, o cualquier marca);
 * desde entonces todo estudiante sin incidencia ese día sale con ✓ (asistió).
 * Tocar una celda abre el MENÚ (`_af-menu.php`): ✓ · F · T · FJ · TJ; FJ/TJ
 * exigen motivo y cancelar no cambia nada. Cada marca se AUTOGUARDA como
 * borrador; «Confirmar todo» da el visto bueno. SIN columna «Estado»: el estado
 * lo dice la FRANJA del N° (verde confirmado, ámbar sin confirmar).
 *
 * 🔴 CONTRATO DE DOM con `asistencia-fechas.js`: `.af-fila[data-matricula-id]
 * [data-periodo-id][data-csrf][data-nombre][data-confirmada][data-registrada]`,
 * celdas de `_af-celda.php`, `.af-total[data-campo]`,
 * `.af-lista[data-fecha][data-accion]`, `th.af-th-dia[data-fecha][data-tomada]`,
 * `[data-jornada-url][data-csrf]` y `#af-menu`. La vista por estudiante usa el
 * mismo contrato y además `.af-estado`, `.af-confirmar` y `.asistencia-status`
 * (el JS los trata como opcionales).
 *
 * @var array  $estudiantes [{ matricula_id, nombre_completo, incidencias{...,confirmado} }]
 * @var array  $fechas      { calendario, mesVer, incidencias[mid][fecha], motivos,
 *                            progreso, jornadas[fecha], sinTomar, hoy }
 * @var bool   $editable
 * @var int    $pidVer
 * @var string $csrfToken
 * @var array  $seccion
 */

use App\Models\AsistenciaModel;

$mes         = $fechas['calendario'][$fechas['mesVer']] ?? ['nombre' => '', 'dias' => []];
$diasMes     = $mes['dias'];
$jornadas    = $fechas['jornadas'] ?? [];
$presencias  = $fechas['presencias'] ?? [];   // ✓ propios (077)
$pendientes  = $fechas['pendientes'] ?? [];   // sin cubrir por día (077)
$motPrincipal = $fechas['motivoPrincipalId'] ?? null;   // icono de documento en `_af-celda.php`
$campos      = AsistenciaModel::CAMPOS;
$abrev       = ['faltas' => 'F', 'faltas_justificadas' => 'FJ', 'tardanzas' => 'T', 'tardanzas_justificadas' => 'TJ'];
$nombreTipo  = ['faltas' => 'Falta', 'faltas_justificadas' => 'Falta justificada', 'tardanzas' => 'Tardanza', 'tardanzas_justificadas' => 'Tardanza justificada'];
$urlMes      = static fn(string $clave): string =>
    url('admin/asistencia/' . (int) $seccion['id'] . '?periodo=' . $pidVer . '&mes=' . $clave);
$ddmm        = static fn(string $f): string => substr($f, 8, 2) . '/' . substr($f, 5, 2);
// Aviso de HOY (09/10/2026): solo si hoy es día marcable y el mes mostrado lo incluye.
$hoy         = $fechas['hoy'] ?? date('Y-m-d');
$avisoHoy    = $fechas['avisoHoy'] ?? null;
$mostrarHoy  = $avisoHoy !== null && substr($hoy, 0, 7) === $fechas['mesVer'];
?>

<?php if (count($fechas['calendario']) > 1): ?>
    <nav class="af-meses" aria-label="Mes">
        <?php foreach ($fechas['calendario'] as $clave => $m): ?>
            <a href="<?= $urlMes($clave) ?>"
               class="af-meses__opcion<?= $clave === $fechas['mesVer'] ? ' is-activa' : '' ?>"
               <?= $clave === $fechas['mesVer'] ? 'aria-current="page"' : '' ?>><?= e($m['nombre']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($editable): ?>
    <?php // AVISO DE HOY (09/10/2026, reemplaza a «Pasar lista de hoy» y al texto
          // de ayuda): dice cómo va el día y su botón dice lo que va a pasar.
          // `--info` siempre: es un aviso informativo (el autocierre de auth.js
          // solo corre en el login, layouts/auth.php).
          // `asistencia-fechas.js` lo repinta con cada marca. ?>
    <?php if ($mostrarHoy):
        $fechaHoyTxt = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][(int) date('N', strtotime($hoy))]
                     . ' ' . $ddmm($hoy); ?>
        <div class="alert alert--info af-aviso-hoy" data-fecha="<?= e($hoy) ?>"
             data-total="<?= count($estudiantes) ?>" data-faltan="<?= (int) $avisoHoy['faltan'] ?>">
            <strong>Hoy, <?= e($fechaHoyTxt) ?>:</strong>
            <span class="af-aviso-hoy__texto"><?php
                if ((int) $avisoHoy['faltan'] === 0): ?>lista completa · <?= (int) $avisoHoy['faltas'] ?> falta(s) · <?= (int) $avisoHoy['tardanzas'] ?> tardanza(s).<?php
                elseif ((int) $avisoHoy['faltan'] === count($estudiantes)): ?>todavía no se toma lista.<?php
                else: ?>faltan <strong><?= (int) $avisoHoy['faltan'] ?></strong> estudiante(s) por marcar.<?php
                endif; ?></span>
            <button type="button" class="btn btn--success btn--sm alert__accion af-lista af-lista--hoy"
                    data-fecha="<?= e($hoy) ?>"<?= (int) $avisoHoy['faltan'] === 0 ? ' hidden' : '' ?>><?=
                (int) $avisoHoy['faltan'] === count($estudiantes)
                    ? 'Todos asistieron'
                    : 'Marcar a los ' . (int) $avisoHoy['faltan'] . ' como asistentes' ?></button>
        </div>
    <?php endif; ?>

    <div class="conducta-toolbar" data-jornada-url="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/jornada') ?>"
         data-csrf="<?= e($csrfToken) ?>">
        <form method="post" action="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/confirmar-todo') ?>"
              class="af-confirmar-todo">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary btn--sm">
                <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Confirmar todo
            </button>
        </form>
    </div>
<?php endif; ?>

<?php // `af-scroll`: el contenedor tiene alto propio y el encabezado queda FIJO al
      // bajar (29/09/2026); en la esquina fija va el MES, para no perder qué mes y
      // qué día se está marcando en el celular. ?>
<div class="tabla-notas-wrapper af-scroll">
    <table class="tabla-notas asistencia-tabla af-grilla">
        <thead>
            <tr>
                <th class="col-num">N°</th>
                <th class="col-nombre">
                    <span class="af-th-mes"><?= e($mes['nombre']) ?></span>
                    Apellidos y Nombres
                </th>
                <?php // Encabezado del día con su ESTADO (09/10/2026, migración 077):
                      // ⚠ = faltan estudiantes por marcar (tocar = ✓ a los que
                      // faltan); ✓ = día completo, SOLO indicador (sin «deshacer»). ?>
                <?php foreach ($diasMes as $d):
                    $f        = $d['fecha'];
                    $faltanTh = (int) ($pendientes[$f] ?? 0);
                    $completo = $faltanTh === 0;
                    $claseTh  = $d['no_lectivo'] !== null ? ' af-th-dia--no-lectivo'
                        : (!$d['marcable'] ? ' af-th-dia--futuro' : ($completo ? '' : ' af-th-dia--sin-tomar'));
                ?>
                    <th class="af-th-dia<?= $claseTh ?>" data-fecha="<?= e($f) ?>" data-tomada="<?= isset($jornadas[$f]) ? '1' : '0' ?>"
                        title="<?= e($f . ($d['no_lectivo'] !== null ? ' · No lectivo: ' . $d['no_lectivo'] : '')) ?>">
                        <span class="af-th-dia__abrev"><?= e($d['abrev']) ?></span>
                        <span class="af-th-dia__num"><?= (int) $d['dia'] ?></span>
                        <?php if ($editable && $d['marcable'] && !$completo): ?>
                            <button type="button" class="af-lista af-th-dia__lista" data-fecha="<?= e($f) ?>"
                                    title="<?= e('Faltan ' . $faltanTh . ' · tocar para marcar ✓ a los que faltan') ?>"
                                    aria-label="<?= e('Marcar ✓ a los que faltan el ' . $ddmm($f)) ?>">⚠</button>
                        <?php elseif ($d['marcable']): ?>
                            <span class="af-th-dia__lista af-th-dia__lista--solo"
                                  title="<?= $completo ? 'Día completo' : 'Faltan estudiantes por marcar' ?>"><?= $completo ? '✓' : '⚠' ?></span>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <?php // Pastilla con el COLOR del tipo (30/09/2026): el mismo de las
                      // celdas, para que el código de colores se aprenda. ?>
                <?php foreach ($campos as $i => $c): ?>
                    <th class="asistencia-th-contador<?= $i === 0 ? ' col-resultado--inicio' : '' ?> col-resultado"
                        title="<?= e($nombreTipo[$c]) ?> · total del bimestre">
                        <?php // FJ = la pastilla de una celda FJ con el motivo principal (076). ?>
                        <?php if ($c === 'faltas_justificadas' && !empty($fechas['motivoPrincipal'])): ?>
                            <?php $nombreAnclaFj = $fechas['motivoPrincipal']; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php'; ?>
                        <?php else: ?>
                            <span class="af-tipo af-tipo--<?= strtolower($abrev[$c]) ?>"><?= $abrev[$c] ?></span>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $i => $est):
                $mid   = (int) $est['matricula_id'];
                $inc   = $est['incidencias'];
                $dias  = $fechas['incidencias'][$mid] ?? [];
            ?>
                <tr class="asistencia-fila af-fila<?= !empty($inc['confirmado']) ? ' asistencia-fila--registrada' : '' ?>"
                    <?php if ($editable): ?>data-matricula-id="<?= $mid ?>"
                    data-periodo-id="<?= $pidVer ?>"
                    data-csrf="<?= e($csrfToken) ?>"
                    data-nombre="<?= e($est['nombre_completo']) ?>"
                    data-confirmada="<?= !empty($inc['confirmado']) ? '1' : '0' ?>"
                    data-registrada="<?= !empty($inc['registrado']) ? '1' : '0' ?>"<?php endif; ?>>
                    <td class="col-num"><?= $i + 1 ?></td>
                    <td class="col-nombre">
                        <?= e($est['nombre_completo']) ?>
                        <?php if (!empty($inc['extraordinaria'])): ?>
                            <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($diasMes as $d): ?>
                        <td class="af-td-dia">
                            <?php
                            $x        = $dias[$d['fecha']] ?? null;
                            // ✓ = su propia marca o la lista de la sección (077).
                            // Solo lectura: se muestran las incidencias CONFIRMADAS. Un
                            // estudiante sin confirmar (bloqueo forzado del director)
                            // no lleva ✓: sus incidencias no se ven y el ✓ mentiría.
                            $tomada   = (isset($jornadas[$d['fecha']]) || isset($presencias[$mid][$d['fecha']]))
                                        && ($editable || !empty($inc['confirmado']));
                            $grande   = false;
                            $colClase = '';
                            $etiqueta = (string) $est['nombre_completo'];
                            require VIEW_PATH . '/admin/asistencia/_af-celda.php';
                            ?>
                        </td>
                    <?php endforeach; ?>
                    <?php foreach ($campos as $j => $c): ?>
                        <td class="asistencia-td-valor af-total col-resultado<?= $j === 0 ? ' col-resultado--inicio' : '' ?>"
                            data-campo="<?= $c ?>"><?= (int) $inc[$c] ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // La LEYENDA vive en `_leyenda-fechas.php` y la incluye `seccion.php`
      // DESPUÉS del bloqueo (09/10/2026): «Bloquear y aprobar» queda a la vista. ?>

<?php if ($editable): ?>
    <?php $motivos = $fechas['motivos']; require VIEW_PATH . '/admin/asistencia/_af-menu.php'; ?>
<?php endif; ?>
