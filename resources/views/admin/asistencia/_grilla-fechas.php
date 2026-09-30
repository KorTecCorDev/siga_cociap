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
$campos      = AsistenciaModel::CAMPOS;
$abrev       = ['faltas' => 'F', 'faltas_justificadas' => 'FJ', 'tardanzas' => 'T', 'tardanzas_justificadas' => 'TJ'];
$nombreTipo  = ['faltas' => 'Falta', 'faltas_justificadas' => 'Falta justificada', 'tardanzas' => 'Tardanza', 'tardanzas_justificadas' => 'Tardanza justificada'];
$urlMes      = static fn(string $clave): string =>
    url('admin/asistencia/' . (int) $seccion['id'] . '?periodo=' . $pidVer . '&mes=' . $clave);
$ddmm        = static fn(string $f): string => substr($f, 8, 2) . '/' . substr($f, 5, 2);
// «Pasar lista de hoy»: solo si hoy es un día marcable del bimestre aún sin lista.
$hoy         = $fechas['hoy'] ?? date('Y-m-d');
$hoySinTomar = $editable && in_array($hoy, $fechas['sinTomar'] ?? [], true);
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
    <div class="conducta-toolbar" data-jornada-url="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/jornada') ?>"
         data-csrf="<?= e($csrfToken) ?>">
        <?php if ($hoySinTomar): ?>
            <button type="button" class="btn btn--success btn--sm af-lista af-lista--hoy" data-fecha="<?= e($hoy) ?>" data-accion="tomar">
                ✓ Pasar lista de hoy
            </button>
        <?php endif; ?>
        <form method="post" action="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/confirmar-todo') ?>"
              class="af-confirmar-todo">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary btn--sm">
                <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Confirmar todo
            </button>
        </form>
        <span class="conducta-toolbar__hint text-muted">
            Pasa lista y todos quedan con ✓; luego toca solo a quien faltó o llegó tarde.
            Cada marca se guarda sola como borrador; solo lo confirmado cuenta.
        </span>
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
                <?php // Encabezado del día con el ESTADO DE SU LISTA (30/09/2026):
                      // ⚠ = sin tomar (tocar = pasar lista), ✓ = tomada (tocar =
                      // deshacer; el servidor lo niega si hay incidencias). ?>
                <?php foreach ($diasMes as $d):
                    $f       = $d['fecha'];
                    $tomada  = isset($jornadas[$f]);
                    $claseTh = $d['no_lectivo'] !== null ? ' af-th-dia--no-lectivo'
                        : (!$d['marcable'] ? ' af-th-dia--futuro' : ($tomada ? '' : ' af-th-dia--sin-tomar'));
                ?>
                    <th class="af-th-dia<?= $claseTh ?>" data-fecha="<?= e($f) ?>" data-tomada="<?= $tomada ? '1' : '0' ?>"
                        title="<?= e($f . ($d['no_lectivo'] !== null ? ' · No lectivo: ' . $d['no_lectivo'] : '')) ?>">
                        <span class="af-th-dia__abrev"><?= e($d['abrev']) ?></span>
                        <span class="af-th-dia__num"><?= (int) $d['dia'] ?></span>
                        <?php if ($editable && $d['marcable']): ?>
                            <button type="button" class="af-lista af-th-dia__lista" data-fecha="<?= e($f) ?>"
                                    data-accion="<?= $tomada ? 'deshacer' : 'tomar' ?>"
                                    title="<?= $tomada ? 'Lista tomada · tocar para deshacer' : 'Sin tomar · tocar para pasar lista' ?>"
                                    aria-label="<?= e(($tomada ? 'Deshacer la lista del ' : 'Pasar lista del ') . $ddmm($f)) ?>"><?= $tomada ? '✓' : '⚠' ?></button>
                        <?php elseif ($d['marcable']): ?>
                            <span class="af-th-dia__lista af-th-dia__lista--solo"><?= $tomada ? '✓' : '⚠' ?></span>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
                <?php // Pastilla con el COLOR del tipo (30/09/2026): el mismo de las
                      // celdas, para que el código de colores se aprenda. ?>
                <?php foreach ($campos as $i => $c): ?>
                    <th class="asistencia-th-contador<?= $i === 0 ? ' col-resultado--inicio' : '' ?> col-resultado"
                        title="<?= e($nombreTipo[$c]) ?> · total del bimestre">
                        <span class="af-tipo af-tipo--<?= strtolower($abrev[$c]) ?>"><?= $abrev[$c] ?></span>
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
                            // Solo lectura: se muestran las incidencias CONFIRMADAS. Un
                            // estudiante sin confirmar (bloqueo forzado del director)
                            // no lleva ✓: sus incidencias no se ven y el ✓ mentiría.
                            $tomada   = isset($jornadas[$d['fecha']]) && ($editable || !empty($inc['confirmado']));
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

<div class="tabla-pie tabla-pie--suelto">
    <p class="tabla-pie__leyenda">
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--asistio">✓</span> Asistió (lista tomada)</span>
        <?php foreach ($campos as $c): ?>
            <span class="tabla-pie__item">
                <span class="af-tipo af-tipo--<?= strtolower($abrev[$c]) ?>"><?= $abrev[$c] ?></span> <?= e($nombreTipo[$c]) ?>
            </span>
        <?php endforeach; ?>
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--sin-tomar">⚠</span> Sin tomar lista</span>
        <span class="tabla-pie__item"><span class="af-tipo af-tipo--nl">NL</span> No lectivo</span>
        <span class="tabla-pie__item tabla-pie__item--bloque">
            Los totales son del <strong>bimestre entero</strong>, no solo del mes. Los días en gris aún no
            transcurren. En el encabezado, <strong>⚠</strong> marca un día sin lista tomada<?php if ($editable): ?>:
            tócalo para pasar lista<?php endif; ?>. Toda justificación lleva su motivo (icono de documento).<?php if ($editable): ?>
            Franja del N°: <strong>verde</strong> confirmado, <strong>ámbar</strong> sin confirmar. Para confirmar a un
            solo estudiante, usa <em>Registrar por estudiante</em>.<?php endif; ?>
        </span>
    </p>
</div>

<?php if ($editable): ?>
    <?php $motivos = $fechas['motivos']; require VIEW_PATH . '/admin/asistencia/_af-menu.php'; ?>
<?php endif; ?>
