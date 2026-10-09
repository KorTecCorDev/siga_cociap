<?php
/**
 * Asistencia — registro de incidencias por seccion.
 * Con selector de bimestre: el periodo editable se registra; los demas
 * (historial) se muestran en SOLO LECTURA y, si estan aprobados y
 * bloqueados, permiten imprimir la copia oficial del registro.
 *
 * @var array       $seccion      { id, grado_nombre, seccion_nombre, nivel_nombre }
 * @var array|null  $periodoVer   periodo mostrado { id, nombre_display, editable, ... } o null
 * @var array       $periodosNav  periodos del año activo + ['cierre' => cierre vigente|null]
 * @var bool        $soloLectura  true = periodo no editable (historial)
 * @var array|null  $cierre       cierre vigente del periodo mostrado o null
 * @var array|null  $firmas       AuxiliarSeccionModel::firmasDelRegistro() si hay cierre
 * @var array       $estudiantes  [{ matricula_id, nombre_completo, incidencias{...} }]
 * @var array       $totales      AsistenciaModel::totalesIncidencias($estudiantes)
 * @var int         $topeMax      valor máximo por contador (espejo del backend)
 * @var bool        $porFechas    bimestre por fechas (migración 069): grilla mensual
 * @var array       $fechas       datos de la grilla por fechas (ver _grilla-fechas.php)
 *
 * La TABLA vive en `_tabla-incidencias.php`, compartida con la consulta de
 * Direccion. Las variables de arriba son el contrato que ese partial espera.
 */

$csrfToken = \Core\Session::csrfToken();
$bloqueada = $cierre !== null;
$editable  = !$soloLectura && !$bloqueada;
$pidVer    = $periodoVer ? (int) $periodoVer['id'] : 0;
?>

<div class="page-header">
    <a href="<?= url('admin/asistencia') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">
            Asistencia — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            <?php if ($periodoVer): ?>
                · <strong><?= e($periodoVer['nombre_display']) ?></strong>
                <?php if ($soloLectura): ?> · Solo lectura<?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
    <?php // Segunda entrada del registro (un estudiante por pantalla). Solo
          // cuando hay algo que registrar: la pantalla no muestra historial. ?>
    <?php if ($periodoVer && $editable && !empty($estudiantes)): ?>
        <a href="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/estudiante') ?>"
           class="btn btn--primary btn--sm">Registrar por estudiante</a>
    <?php endif; ?>
</div>

<?php if (!empty($periodosNav)): ?>
    <nav class="periodo-tabs" aria-label="Bimestres">
        <?php foreach ($periodosNav as $p):
            $esActual   = (int) $p['id'] === $pidVer;
            $pEditable  = (bool) $p['editable'];
        ?>
            <a href="<?= url('admin/asistencia/' . (int) $seccion['id'] . '?periodo=' . (int) $p['id']) ?>"
               class="periodo-tab<?= $esActual ? ' periodo-tab--activa' : '' ?>"
               <?= $esActual ? 'aria-current="page"' : '' ?>>
                <span class="periodo-tab__nombre"><?= e($p['nombre_display']) ?></span>
                <?php if ($pEditable): ?>
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

<div id="asistencia-feedback" class="asistencia-feedback" hidden role="status" aria-live="polite"></div>

<?php if (!$periodoVer): ?>
    <div class="empty-state">
        <p>No hay periodo abierto para edición. Selecciona un bimestre del historial.</p>
    </div>
<?php elseif (empty($estudiantes)): ?>
    <div class="empty-state">
        <p>No hay estudiantes matriculados en esta sección.</p>
    </div>
<?php else: ?>

<?php if ($bloqueada): ?>
    <div class="alert alert--info">
        <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
        <span>
            Asistencia <strong>bloqueada y aprobada por <?= e($firmas['bloqueo_nombre'] ?? '') ?></strong>
            (<?= e($firmas['bloqueo_rol'] ?? '') ?>)
            el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?>.
            Para corregir, solicita el desbloqueo a Dirección.
        </span>
        <a href="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/imprimir/' . $pidVer) ?>"
           target="_blank" rel="noopener" class="btn btn--secondary btn--sm alert__accion">
            🖨 Imprimir registro
        </a>
    </div>
<?php endif; ?>

<?php if ($porFechas): ?>
    <?php // Bimestre POR FECHAS (29/09/2026): grilla mensual día × estudiante. ?>
    <?php require VIEW_PATH . '/admin/asistencia/_grilla-fechas.php'; ?>
<?php else: ?>
    <?php // La tabla es un PARTIAL COMPARTIDO con la consulta de Direccion: el mismo
          // dato se pintaba en dos plantillas distintas. Ver _tabla-incidencias.php.
          require VIEW_PATH . '/admin/asistencia/_tabla-incidencias.php'; ?>
<?php endif; ?>

<?php if ($editable && $porFechas):
    $prog     = $fechas['progreso'];
    $sinTomar = count($fechas['sinTomar'] ?? []);
    $terminado = !empty($fechas['terminado']);
    $completo = $terminado && $prog['esperados'] > 0 && $prog['registrados'] >= $prog['esperados'] && $sinTomar === 0; ?>
    <?php // Exige a TODOS confirmados (decisión b, 29/09/2026), desde el
          // 30/09/2026 TODO día con su lista tomada y, desde el 09/10/2026, que
          // haya llegado el último día del bimestre; el servidor lo vuelve a
          // comprobar en `AsistenciaModel::bloquearRA`. ?>
    <form method="post" action="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/bloquear') ?>"
          class="conducta-bloqueo-form"
          onsubmit="return confirm('¿Bloquear y aprobar la asistencia de toda la sección? Después de esta acción ya no podrás modificarla.');">
        <?= csrf_field() ?>
        <div class="conducta-bloqueo-info">
            Confirmados: <strong><?= (int) $prog['registrados'] ?>/<?= (int) $prog['esperados'] ?></strong>
            · Días sin tomar: <strong class="af-sin-tomar-total"><?= $sinTomar ?></strong>
            <?php if (!$terminado): ?>
                <span class="text-muted">— podrás bloquear desde el <?= e($fechas['finDdmm']) ?>, último día del bimestre</span>
            <?php elseif (!$completo): ?>
                <span class="text-muted">— confirma a todos y pasa lista de cada día para poder bloquear</span>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn--success" <?= $completo ? '' : 'disabled' ?>>
            <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>Bloquear y aprobar
        </button>
    </form>
<?php elseif ($editable): ?>
    <form method="post" action="<?= url('admin/asistencia/' . (int) $seccion['id'] . '/bloquear') ?>"
          class="conducta-bloqueo-form"
          onsubmit="return confirm('¿Bloquear y aprobar la asistencia de toda la sección? Después de esta acción ya no podrás modificarla.');">
        <?= csrf_field() ?>
        <div class="conducta-bloqueo-info">
            Las filas sin guardar se consideran <strong>0 incidencias</strong>.
        </div>
        <button type="submit" class="btn btn--success">
            <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>Bloquear y aprobar
        </button>
    </form>
<?php endif; ?>

<?php endif; ?>
