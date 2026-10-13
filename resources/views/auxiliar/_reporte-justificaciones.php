<?php
/**
 * CUERPO del reporte «Faltas y justificaciones — Mis secciones» (13/10/2026).
 * PUNTO ÚNICO de la pantalla (`auxiliar/justificaciones.php`) y su A4
 * (`auxiliar/justificaciones-imprimir.php`): el papel no puede decir otra cifra
 * ni otro orden. Datos de `Auxiliar\PanelController::componerReporte()`.
 *
 * Orden: KPIs → leyenda → Por sección (gráfico + tabla) → Por estudiante → Por
 * motivo. La única diferencia del A4 (`$impreso`): la columna «Detalle» (un
 * enlace, que en papel no sirve) pasa a ser «Registro» —«Bloqueado el dd/mm» o
 * «En curso»—, para que una hoja suelta no se lea como oficial cuando aún es
 * borrador (decisión del usuario).
 *
 * @var array       $reporte          AsistenciaModel::consolidarJustificaciones()
 * @var array       $periodoVer       bimestre mostrado
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 * @var bool        $impreso          true en el A4
 */
$impreso      = $impreso ?? false;
$pidVer       = (int) $periodoVer['id'];
$k            = $reporte['kpis'];
$motivosTabla = $reporte['motivos'];
$categorias   = ['fj_autorizadas' => 'Faltas justificadas', 'fj_como_f' => 'Cuentan como falta', 'tj' => 'Tardanzas justificadas'];
$diasSemana   = [1 => 'Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.', 'Dom.'];
// «Lun. 08/09» a partir de AAAA-MM-DD.
$fechaCorta = static function (string $f) use ($diasSemana): string {
    $ts = strtotime($f);
    return $diasSemana[(int) date('N', $ts)] . ' ' . date('d/m', $ts);
};
// GRÁFICO: solo con 2+ secciones y alguna FJ, que es cuando hay algo que
// comparar. Dos series apiladas (por qué, en `justificaciones-auxiliar.js`).
$hayGrafico = count($reporte['secciones']) >= 2 && (int) $k['fj'] > 0;
?>
<div class="cuadros-kpis just-seccion__kpis">
    <?php foreach ($categorias as $cat => $titulo): ?>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k[$cat] ?></span>
            <span class="cuadros-kpi__t">
                <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?> <?= e($titulo) ?>
            </span>
        </div>
    <?php endforeach; ?>
    <div class="cuadros-kpi">
        <span class="cuadros-kpi__n"><?= (int) $k['estudiantes'] ?></span>
        <span class="cuadros-kpi__t">Estudiantes con justificaciones</span>
    </div>
</div>

<?php require VIEW_PATH . '/admin/asistencia/_leyenda-justificaciones.php'; ?>

<h2 class="asistencia-anexo-titulo">Por sección</h2>

<?php if ($hayGrafico): ?>
    <?php // La tabla de abajo, con las mismas cifras, es su vista accesible. ?>
    <div class="card mb-md just-grafico">
        <div class="card__header"><h3 class="card__title">Faltas justificadas por sección</h3></div>
        <div class="card__body">
            <div id="chart-just-aux"></div>
            <p class="text-sm text-muted">
                Cada barra es el total de faltas que se justificaron en la sección, y se divide
                en las que cuentan como justificadas y las que cuentan como falta.
                (P) primaria, (S) secundaria.
            </p>
        </div>
    </div>
<?php endif; ?>
<div class="tabla-notas-wrapper">
    <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
        <thead>
            <tr>
                <th class="just-seccion__seccion">Sección</th>
                <?php foreach ($categorias as $cat => $titulo): ?>
                    <th class="tr-anexo-cifra">
                        <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                        <span class="just-seccion__rotulo"><?= e($titulo) ?></span>
                    </th>
                <?php endforeach; ?>
                <th class="tr-anexo-cifra">Estudiantes</th>
                <th class="tr-anexo-estado"><?= $impreso ? 'Registro' : 'Detalle' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reporte['secciones'] as $s): ?>
                <tr>
                    <td class="just-seccion__seccion"><?= e($s['etiqueta']) ?></td>
                    <?php foreach (array_keys($categorias) as $cat): ?>
                        <td class="tr-anexo-cifra"><?= (int) $s[$cat] ?></td>
                    <?php endforeach; ?>
                    <td class="tr-anexo-cifra"><?= (int) $s['estudiantes'] ?></td>
                    <td class="tr-anexo-estado">
                        <?php if (!$impreso): ?>
                            <a href="<?= url('admin/asistencia/' . (int) $s['id'] . '/justificaciones?periodo=' . $pidVer) ?>"
                               class="btn btn--secondary btn--sm">Ver &rarr;</a>
                        <?php elseif ($s['bloqueado_en'] !== null): ?>
                            Bloqueado el <?= e(fechaLima($s['bloqueado_en'], 'd/m')) ?>
                        <?php elseif (!$s['oficial']): ?>
                            En curso
                        <?php else: ?>
                            Sin bloquear
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($k['total'] === 0): ?>
    <div class="empty-state">
        <p>No hay justificaciones registradas en este bimestre.</p>
    </div>
<?php else: ?>

<h2 class="asistencia-anexo-titulo">Por estudiante</h2>
<div class="tabla-notas-wrapper">
    <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
        <thead>
            <tr>
                <th class="just-seccion__seccion">Sección</th>
                <th class="tr-num">N&deg;</th>
                <th class="tr-nombre">Apellidos y Nombres</th>
                <?php foreach ($categorias as $cat => $titulo): ?>
                    <th class="tr-anexo-fechas">
                        <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                        <span class="just-seccion__rotulo"><?= e($titulo) ?></span>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reporte['estudiantes'] as $r): ?>
                <tr>
                    <td class="just-seccion__seccion"><?= e($r['seccion']) ?></td>
                    <td class="tr-num"><?= (int) $r['num'] ?></td>
                    <td class="tr-nombre"><?= e($r['nombre']) ?></td>
                    <?php foreach (array_keys($categorias) as $cat): ?>
                        <td class="tr-anexo-fechas">
                            <?php require VIEW_PATH . '/admin/asistencia/_celda-fechas.php'; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="asistencia-anexo-titulo">Por motivo</h2>
<?php require VIEW_PATH . '/admin/asistencia/_tabla-motivos.php'; ?>
<?php endif; ?>

<?php if ($hayGrafico): ?>
    <?php // JSON_HEX_TAG: las etiquetas de sección las escribe el usuario y un
          // "</script>" dentro cerraría el bloque (mismo cuidado que /admin/cuadros). ?>
    <script type="application/json" id="just-aux-data"><?= json_encode([
        'labels'       => array_column($reporte['secciones'], 'etiqueta'),
        'justificadas' => array_map('intval', array_column($reporte['secciones'], 'fj_autorizadas')),
        'comoFalta'    => array_map('intval', array_column($reporte['secciones'], 'fj_como_f')),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script src="<?= url('js/frappe-charts.min.js') ?>"></script>
    <script src="<?= url('js/justificaciones-auxiliar.js') ?>"></script>
<?php endif; ?>
