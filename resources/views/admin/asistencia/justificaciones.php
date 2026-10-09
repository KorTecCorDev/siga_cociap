<?php
/**
 * Asistencia — JUSTIFICACIONES de una sección (09/10/2026). Solo lectura: las
 * FJ/TJ registradas por el auxiliar, resumidas por tipo, por motivo y por
 * estudiante, más el detalle por fecha.
 *
 * AUTORIZADA = FJ con el MOTIVO PRINCIPAL (ancla) del bimestre: es la que cuenta
 * como FJ; con otro motivo cuenta como F. La regla la aplica el modelo
 * (`AsistenciaModel::resumenJustificaciones`), no esta vista.
 *
 * Bimestre editable: el BORRADOR, con «Sin confirmar» en lo que aún no cuenta.
 * Historial o sección bloqueada: solo lo OFICIAL.
 *
 * @var array       $seccion          { id, grado_nombre, seccion_nombre, nivel_nombre }
 * @var array|null  $periodoVer       periodo mostrado o null
 * @var array       $periodosNav      pestañas del historial (+ 'cierre')
 * @var bool        $soloLectura      periodo no editable (historial)
 * @var array|null  $cierre           cierre vigente del periodo mostrado
 * @var bool        $porFechas        bimestre por fechas (los demás no tienen motivos)
 * @var array|null  $resumen          AsistenciaModel::resumenJustificaciones()
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 */

$sid     = (int) $seccion['id'];
$pidVer  = $periodoVer ? (int) $periodoVer['id'] : 0;
$oficial = $soloLectura || $cierre !== null;
$diasSemana = [1 => 'Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.', 'Dom.'];
$pastilla = static fn(string $tipo, bool $conMotivo = false): string =>
    '<span class="af-tipo af-tipo--' . strtolower($tipo) . ($conMotivo ? ' af-tipo--con-motivo' : '') . '">' . e($tipo) . '</span>';
?>

<div class="page-header">
    <a href="<?= url('admin/asistencia/' . $sid . ($pidVer ? '?periodo=' . $pidVer : '')) ?>"
       class="btn btn--secondary btn--sm">← Tabla</a>
    <div>
        <h1 class="page-title">
            Justificaciones — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            <?php if ($periodoVer): ?>
                · <strong><?= e($periodoVer['nombre_display']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($periodosNav)): ?>
    <nav class="periodo-tabs" aria-label="Bimestres">
        <?php foreach ($periodosNav as $p):
            $esActual = (int) $p['id'] === $pidVer; ?>
            <a href="<?= url('admin/asistencia/' . $sid . '/justificaciones?periodo=' . (int) $p['id']) ?>"
               class="periodo-tab<?= $esActual ? ' periodo-tab--activa' : '' ?>"
               <?= $esActual ? 'aria-current="page"' : '' ?>>
                <span class="periodo-tab__nombre"><?= e($p['nombre_display']) ?></span>
                <?php if ((bool) $p['editable']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--curso">En curso</span>
                <?php elseif ($p['cierre']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--bloqueado">Aprobado</span>
                <?php else: ?>
                    <span class="periodo-tab__estado">Sin cierre</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if (!$periodoVer): ?>
    <div class="empty-state">
        <p>No hay periodo abierto. Selecciona un bimestre del historial.</p>
    </div>
<?php elseif (!$porFechas): ?>
    <div class="empty-state">
        <p>Este bimestre se registró solo con números: no tiene fechas ni motivos de justificación.</p>
    </div>
<?php elseif ($resumen['kpis']['total'] === 0): ?>
    <div class="empty-state">
        <p>No hay justificaciones registradas en este bimestre.</p>
    </div>
<?php else:
    $k = $resumen['kpis']; ?>

    <?php if (!$oficial): ?>
        <?php // `--info`, no `--warning`: auth.js cierra solos los `--warning`. ?>
        <div class="alert alert--info">
            <span class="btn-icon btn-icon--wait" aria-hidden="true"></span>
            <span>
                Registro <strong>en curso</strong>: incluye lo registrado aunque no esté confirmado.
                Lo marcado como <em>Sin confirmar</em> todavía no cuenta en la boleta.
            </span>
        </div>
    <?php endif; ?>

    <div class="cuadros-kpis just-seccion__kpis">
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['total'] ?></span>
            <span class="cuadros-kpi__t">Justificaciones</span>
        </div>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['fj'] ?></span>
            <span class="cuadros-kpi__t"><?= $pastilla('FJ') ?> Faltas justificadas</span>
        </div>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['fj_autorizadas'] ?></span>
            <span class="cuadros-kpi__t"><?= $pastilla('FJ', true) ?> Autorizadas<?php if (!empty($motivoPrincipal)): ?>
                (<?= e($motivoPrincipal) ?>)<?php endif; ?></span>
        </div>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['fj_como_f'] ?></span>
            <span class="cuadros-kpi__t">FJ que cuentan como <?= $pastilla('F') ?></span>
        </div>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['tj'] ?></span>
            <span class="cuadros-kpi__t"><?= $pastilla('TJ') ?> Tardanzas justificadas</span>
        </div>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['estudiantes'] ?></span>
            <span class="cuadros-kpi__t">Estudiantes con justificaciones</span>
        </div>
    </div>

    <?php if (!empty($motivoPrincipal)): ?>
        <p class="text-sm text-muted just-seccion__nota">
            Solo las FJ con el motivo «<?= e($motivoPrincipal) ?>» cuentan como faltas justificadas;
            con cualquier otro motivo cuentan como faltas (F). Las TJ cuentan con cualquier motivo.
        </p>
    <?php endif; ?>

    <h2 class="asistencia-anexo-titulo">Por motivo</h2>
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
            <thead>
                <tr>
                    <th class="tr-anexo-motivo">Motivo</th>
                    <th class="tr-anexo-tipo"><?= $pastilla('FJ') ?></th>
                    <th class="tr-anexo-tipo"><?= $pastilla('TJ') ?></th>
                    <th class="tr-anexo-tipo">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen['motivos'] as $m): ?>
                    <tr>
                        <td class="tr-anexo-motivo">
                            <?= $m['motivo'] !== '' ? e($m['motivo']) : '&mdash;' ?>
                            <?php if ($m['ancla']): ?>
                                <span class="just-seccion__ancla"><?= $pastilla('FJ', true) ?> Motivo principal</span>
                            <?php endif; ?>
                        </td>
                        <td class="tr-anexo-tipo"><?= (int) $m['fj'] ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $m['tj'] ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $m['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="asistencia-anexo-titulo">Por estudiante</h2>
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
            <thead>
                <tr>
                    <th class="tr-num">N&deg;</th>
                    <th class="tr-nombre">Apellidos y Nombres</th>
                    <th class="tr-anexo-tipo" title="FJ autorizadas"><?= $pastilla('FJ', true) ?></th>
                    <th class="tr-anexo-tipo" title="FJ que cuentan como F">FJ&rarr;F</th>
                    <th class="tr-anexo-tipo"><?= $pastilla('TJ') ?></th>
                    <th class="tr-anexo-tipo">Total</th>
                    <?php if (!$oficial): ?><th class="tr-anexo-estado">Estado</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen['estudiantes'] as $r): ?>
                    <tr>
                        <td class="tr-num"><?= (int) $r['num'] ?></td>
                        <td class="tr-nombre"><?= e($r['nombre']) ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $r['fj_autorizadas'] ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $r['fj_como_f'] ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $r['tj'] ?></td>
                        <td class="tr-anexo-tipo"><?= (int) $r['total'] ?></td>
                        <?php if (!$oficial): ?>
                            <td class="tr-anexo-estado">
                                <span class="af-estado af-estado--<?= $r['confirmado'] ? 'ok' : 'pendiente' ?>">
                                    <?= $r['confirmado'] ? '✓ Confirmado' : 'Sin confirmar' ?>
                                </span>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="asistencia-anexo-titulo">Detalle por fecha</h2>
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
            <thead>
                <tr>
                    <th class="tr-num">N&deg;</th>
                    <th class="tr-nombre">Apellidos y Nombres</th>
                    <th class="tr-anexo-fecha">Fecha</th>
                    <th class="tr-anexo-tipo">Tipo</th>
                    <th class="tr-anexo-motivo">Motivo</th>
                    <th class="tr-anexo-tipo">Cuenta como</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen['detalle'] as $d):
                    $ts = strtotime($d['fecha']); ?>
                    <tr>
                        <td class="tr-num"><?= (int) $d['num'] ?></td>
                        <td class="tr-nombre">
                            <?= e($d['nombre']) ?>
                            <?php if (!$oficial && !$d['confirmado']): ?>
                                <span class="af-estado af-estado--pendiente">Sin confirmar</span>
                            <?php endif; ?>
                        </td>
                        <td class="tr-anexo-fecha"><?= e($diasSemana[(int) date('N', $ts)] . ' ' . date('d/m', $ts)) ?></td>
                        <td class="tr-anexo-tipo"><?= $pastilla($d['tipo'], $d['ancla']) ?></td>
                        <td class="tr-anexo-motivo"><?= $d['motivo'] !== '' ? e($d['motivo']) : '&mdash;' ?></td>
                        <td class="tr-anexo-tipo"><?= $pastilla($d['cuenta']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
