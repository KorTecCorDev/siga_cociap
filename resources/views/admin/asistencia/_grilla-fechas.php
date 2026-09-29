<?php
/**
 * Grilla MENSUAL de asistencia por fechas (29/09/2026, migración 069): filas =
 * estudiantes, columnas = días hábiles del mes dentro del bimestre, como la
 * planilla de papel. Al final de cada fila, los 4 contadores DEL BIMESTRE
 * (calculados de las fechas: invariante de `AsistenciaModel`) y su estado.
 *
 * Editable: tocar una celda recorre · → F → FJ → T → TJ → · y se AUTOGUARDA
 * como borrador (`asistencia-fechas.js`); al llegar a FJ/TJ se abre el select
 * del motivo. «Confirmar todo» da el visto bueno; a un estudiante suelto se le
 * confirma desde su vista individual. SIN columna «Estado» (decisión del usuario,
 * 29/09/2026): el estado lo dice la FRANJA del N° inicial (verde confirmado, ámbar
 * sin confirmar) y la fila termina en el N° final, junto a los totales. Los días
 * futuros se ven, pero no se marcan.
 *
 * 🔴 CONTRATO DE DOM con `asistencia-fechas.js`: `.af-fila[data-matricula-id]
 * [data-periodo-id][data-csrf][data-confirmada][data-sin-motivo-otros]`,
 * `.af-celda[data-fecha][data-tipo][data-motivo]`, `.af-total[data-campo]`,
 * `#af-motivo`. La vista por estudiante usa el mismo contrato y además
 * `.af-estado`, `.af-confirmar` y `.asistencia-status` (el JS los trata como
 * opcionales: la grilla no los tiene).
 *
 * @var array  $estudiantes [{ matricula_id, nombre_completo, incidencias{...,confirmado} }]
 * @var array  $fechas      { calendario, mesVer, incidencias[mid][fecha], motivos, progreso }
 * @var bool   $editable
 * @var int    $pidVer
 * @var string $csrfToken
 * @var array  $seccion
 */

use App\Models\AsistenciaModel;

$mes         = $fechas['calendario'][$fechas['mesVer']] ?? ['nombre' => '', 'dias' => []];
$diasMes     = $mes['dias'];
$fechasMes   = array_flip(array_column($diasMes, 'fecha'));
$campos      = AsistenciaModel::CAMPOS;
$abrev       = ['faltas' => 'F', 'faltas_justificadas' => 'FJ', 'tardanzas' => 'T', 'tardanzas_justificadas' => 'TJ'];
$urlMes      = static fn(string $clave): string =>
    url('admin/asistencia/' . (int) $seccion['id'] . '?periodo=' . $pidVer . '&mes=' . $clave);
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
    <div class="conducta-toolbar">
        <form method="post" action="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/confirmar-todo') ?>"
              class="af-confirmar-todo">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary btn--sm">
                <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Confirmar todo
            </button>
        </form>
        <span class="conducta-toolbar__hint text-muted">
            Toca un día para marcar F, FJ, T o TJ. Cada marca se guarda sola como borrador; solo lo confirmado cuenta.
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
                <?php foreach ($diasMes as $d): ?>
                    <th class="af-th-dia<?= $d['marcable'] ? '' : ' af-th-dia--futuro' ?>" title="<?= e($d['fecha']) ?>">
                        <span class="af-th-dia__abrev"><?= e($d['abrev']) ?></span>
                        <span class="af-th-dia__num"><?= (int) $d['dia'] ?></span>
                    </th>
                <?php endforeach; ?>
                <?php foreach ($campos as $i => $c): ?>
                    <th class="asistencia-th-contador<?= $i === 0 ? ' col-resultado--inicio' : '' ?> col-resultado"
                        title="Total del bimestre"><?= $abrev[$c] ?></th>
                <?php endforeach; ?>
                <?php // N° REPETIDO al final, PEGADO a los totales (decisión del usuario):
                      // en una pantalla ancha la fila no se pierde al llevar la vista del
                      // nombre al final. ?>
                <th class="col-num-fin">N°</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $i => $est):
                $mid   = (int) $est['matricula_id'];
                $inc   = $est['incidencias'];
                $dias  = $fechas['incidencias'][$mid] ?? [];
                // FJ/TJ sin motivo en OTROS meses: el JS solo ve este mes.
                $sinMotivoOtros = 0;
                foreach ($dias as $f => $x) {
                    if (!isset($fechasMes[$f]) && in_array($x['tipo'], ['FJ', 'TJ'], true) && $x['motivo_id'] === null) {
                        $sinMotivoOtros++;
                    }
                }
            ?>
                <tr class="asistencia-fila af-fila<?= !empty($inc['confirmado']) ? ' asistencia-fila--registrada' : '' ?>"
                    <?php if ($editable): ?>data-matricula-id="<?= $mid ?>"
                    data-periodo-id="<?= $pidVer ?>"
                    data-csrf="<?= e($csrfToken) ?>"
                    data-confirmada="<?= !empty($inc['confirmado']) ? '1' : '0' ?>"
                    data-registrada="<?= !empty($inc['registrado']) ? '1' : '0' ?>"
                    data-sin-motivo-otros="<?= $sinMotivoOtros ?>"<?php endif; ?>>
                    <td class="col-num"><?= $i + 1 ?></td>
                    <td class="col-nombre">
                        <?= e($est['nombre_completo']) ?>
                        <?php if (!empty($inc['extraordinaria'])): ?>
                            <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($diasMes as $d):
                        $x    = $dias[$d['fecha']] ?? null;
                        $tipo = $x['tipo'] ?? '';
                        $mot  = $x['motivo_id'] ?? null;
                        // `$tituloCelda`, nunca `$titulo`: ese es el <title> de la página
                        // (el layout lo lee después de incluir esta vista).
                        $tituloCelda = $d['fecha'] . ($x && $x['motivo'] ? ' · ' . $x['motivo'] : '');
                    ?>
                        <td class="af-td-dia">
                            <?php if ($editable && $d['marcable']): ?>
                                <button type="button" class="af-celda<?= $tipo !== '' ? ' af-celda--' . strtolower($tipo) : '' ?>"
                                        data-fecha="<?= e($d['fecha']) ?>" data-tipo="<?= e($tipo) ?>"
                                        data-motivo="<?= $mot !== null ? (int) $mot : '' ?>"
                                        title="<?= e($tituloCelda) ?>"
                                        aria-label="<?= e($est['nombre_completo'] . ', ' . $d['fecha'] . ': ' . ($tipo !== '' ? $tipo : 'sin incidencia')) ?>"><?= e($tipo) ?></button>
                            <?php else: ?>
                                <span class="af-celda af-celda--solo<?= $tipo !== '' ? ' af-celda--' . strtolower($tipo) : '' ?><?= $mot !== null ? ' af-celda--con-motivo' : '' ?><?= $d['marcable'] ? '' : ' af-celda--futuro' ?>"
                                      title="<?= e($tituloCelda) ?>"><?= e($tipo) ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php foreach ($campos as $j => $c): ?>
                        <td class="asistencia-td-valor af-total col-resultado<?= $j === 0 ? ' col-resultado--inicio' : '' ?>"
                            data-campo="<?= $c ?>"><?= (int) $inc[$c] ?></td>
                    <?php endforeach; ?>
                    <td class="col-num-fin"><?= $i + 1 ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="tabla-pie tabla-pie--suelto">
    <p class="tabla-pie__leyenda">
        <span class="tabla-pie__item"><strong>F</strong> Falta</span>
        <span class="tabla-pie__item"><strong>FJ</strong> Falta justificada</span>
        <span class="tabla-pie__item"><strong>T</strong> Tardanza</span>
        <span class="tabla-pie__item"><strong>TJ</strong> Tardanza justificada</span>
        <span class="tabla-pie__item tabla-pie__item--bloque">
            Los totales son del <strong>bimestre entero</strong>, no solo del mes. Los días en gris aún no
            transcurren.<?php if ($editable): ?> Franja del N°: <strong>verde</strong> confirmado,
            <strong>ámbar</strong> sin confirmar. Para confirmar a un solo estudiante, usa
            <em>Registrar por estudiante</em>.<?php endif; ?> Una justificación con <strong>!</strong> no tiene motivo: sin él no se puede confirmar;
            el icono de documento indica que ya lo tiene.
        </span>
    </p>
</div>

<?php if ($editable): ?>
    <?php $motivos = $fechas['motivos']; require VIEW_PATH . '/admin/asistencia/_af-motivo.php'; ?>
<?php endif; ?>
