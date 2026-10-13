<?php
/**
 * Auxiliar — FALTAS Y JUSTIFICACIONES de TODAS sus secciones (13/10/2026).
 * Solo lectura: KPIs, una fila por sección y la tabla por estudiante (con su
 * sección y sus fechas) y por motivo. El «Seguimiento de faltas» vive solo en la
 * vista de cada sección (decisión del usuario).
 *
 * Mismo VOCABULARIO que la vista de la sección («Faltas justificadas» ·
 * «Cuentan como falta» · «Tardanzas justificadas»), con su leyenda y su tabla
 * por motivo como parciales compartidos. Sin aviso ni marcas de confirmación.
 * El cuerpo es `_reporte-justificaciones.php`, compartido con el A4.
 *
 * Cada sección se cuenta como en su vista: borrador si está en curso, lo
 * OFICIAL si el bimestre ya no es editable o la sección está bloqueada. La suma
 * la hace `AsistenciaModel::consolidarJustificaciones`, no esta vista.
 *
 * @var array|null  $periodoVer       periodo mostrado o null
 * @var array       $periodosNav      bimestres con secciones del auxiliar
 * @var bool        $porFechas        bimestre por fechas (los demás no tienen motivos)
 * @var array|null  $reporte          AsistenciaModel::consolidarJustificaciones()
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 */

$pidVer = $periodoVer ? (int) $periodoVer['id'] : 0;
?>

<div class="page-header">
    <a href="<?= url('auxiliar/inicio') ?>" class="btn btn--secondary btn--sm">← Inicio</a>
    <div>
        <h1 class="page-title">Faltas y justificaciones — Mis secciones</h1>
        <?php if ($periodoVer): ?>
            <p class="page-subtitle"><strong><?= e($periodoVer['nombre_display']) ?></strong></p>
        <?php endif; ?>
    </div>
    <?php // A4 del mismo reporte (13/10/2026), en pestaña nueva como Acompañamiento. ?>
    <?php if ($reporte !== null): ?>
        <a href="<?= url('auxiliar/justificaciones/imprimir?periodo=' . $pidVer) ?>"
           target="_blank" rel="noopener" class="btn btn--secondary btn--sm">🖨 Imprimir</a>
    <?php endif; ?>
</div>

<?php if (count($periodosNav) > 1): ?>
    <nav class="periodo-tabs" aria-label="Bimestres">
        <?php foreach ($periodosNav as $p):
            $esActual = (int) $p['id'] === $pidVer; ?>
            <a href="<?= url('auxiliar/justificaciones?periodo=' . (int) $p['id']) ?>"
               class="periodo-tab<?= $esActual ? ' periodo-tab--activa' : '' ?>"
               <?= $esActual ? 'aria-current="page"' : '' ?>>
                <span class="periodo-tab__nombre"><?= e($p['nombre_display']) ?></span>
                <?php if ((bool) $p['editable']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--curso">En curso</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if (!$periodoVer): ?>
    <div class="empty-state">
        <p>No tienes secciones a tu cargo en este bimestre.</p>
    </div>
<?php elseif (!$porFechas): ?>
    <div class="empty-state">
        <p>Este bimestre se registró solo con números: no tiene fechas ni motivos de justificación.</p>
    </div>
<?php else: ?>
    <?php require VIEW_PATH . '/auxiliar/_reporte-justificaciones.php'; ?>
<?php endif; ?>
