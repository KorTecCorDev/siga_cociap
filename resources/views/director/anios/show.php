<?php
/**
 * @var array      $anio            { id, anio, fecha_inicio, fecha_fin, estado }
 * @var array      $periodos        [{ id, numero, nombre_display, fecha_inicio, fecha_fin, limite_notas, estado }]
 * @var int        $cerradoId       id del bimestre recién cerrado (0 si no aplica)
 * @var array|null $periodoCerrado  datos del bimestre recién cerrado
 * @var array|null $statsCierre     indicadores del cierre para el modal
 * @var array|null $cierreVacias    cierre del periodo final detenido (vacias, total_vacias, pendientes, sin_datos)
 */

$badgePeriodo = fn(string $estado): string => match ($estado) {
    'activo'  => 'badge--activo',
    'cerrado' => 'badge--warning',
    default   => 'badge--sin-notas',
};
$labelPeriodo = fn(string $estado): string => match ($estado) {
    'activo'  => 'Activo',
    'cerrado' => 'Cerrado',
    default   => 'Pendiente',
};

// Solo puede haber un bimestre activo a la vez en el año.
$hayActivo  = false;
foreach ($periodos as $p) {
    if ($p['estado'] === 'activo') { $hayActivo = true; break; }
}
$anioActivo = $anio['estado'] === 'activo';

// Convierte 'Y-m-d H:i:s' → 'Y-m-dTH:i' para el input datetime-local.
$toLocalInput = function (?string $dt): string {
    if (empty($dt)) return '';
    return substr(str_replace(' ', 'T', $dt), 0, 16);
};
?>

<div class="page-header">
    <a href="<?= url('director/anios') ?>" class="btn btn--secondary btn--sm">&larr; Años académicos</a>
    <div>
        <h1 class="page-title">
            Año académico <?= e($anio['anio']) ?>
            <span class="badge <?= $badgePeriodo($anio['estado']) ?>"><?= $labelPeriodo($anio['estado']) ?></span>
        </h1>
        <p class="page-subtitle">
            <?= e(fecha_es($anio['fecha_inicio'])) ?> &ndash; <?= e(fecha_es($anio['fecha_fin'])) ?>
        </p>
    </div>

    <div class="btn-group">
        <?php /* Acciones del AÑO: solo para quien escribe. El director las ve
                 reflejadas en el badge de estado, pero no las opera. */ ?>
        <?php if ($puedeEscribir && $anio['estado'] === 'planificado'): ?>
            <form method="POST" action="<?= url('director/anios/' . $anio['id'] . '/activar') ?>"
                  data-confirm="¿Activar el año académico <?= e($anio['anio']) ?>? Cualquier otro año activo se cerrará.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary">Activar año</button>
            </form>
        <?php elseif ($puedeEscribir && $anio['estado'] === 'activo'): ?>
            <form method="POST" action="<?= url('director/anios/' . $anio['id'] . '/cerrar') ?>"
                  data-confirm="¿Cerrar el año académico <?= e($anio['anio']) ?>? No podrás reabrirlo.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--secondary">Cerrar año</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
// Cierre del PERIODO FINAL detenido (01/10/2026): quedan competencias sin
// ninguna nota. Se muestra el detalle y el cierre se confirma aparte; RA las
// completa después con la calificación extraordinaria.
$cv = $cierreVacias ?? null;
$cvPeriodo = null;
if (is_array($cv)) {
    foreach ($periodos as $p) {
        if ((int) $p['id'] === (int) $cv['periodo_id'] && $p['estado'] === 'activo') { $cvPeriodo = $p; break; }
    }
}
?>
<?php if ($cvPeriodo && $puedeEscribir): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h2 class="card__title">Cierre del <?= e($cvPeriodo['nombre_display']) ?>: quedan competencias sin evaluar</h2>
    </div>
    <div class="card__body">
        <div class="flash flash--warning">
            Es el último bimestre del año. <?= e(\App\Models\AnioAcademicoModel::textoAvisoPeriodoFinal($cv)) ?>
            Si cierras igual, esos estudiantes quedan con la situación final pendiente hasta que
            Registro Académico registre la calificación extraordinaria, y esas notas no entran
            al orden de mérito. Para que el docente las evalúe, amplía el plazo y desbloquéalas
            desde el panel de bloqueos.
        </div>
        <?php if (!empty($cv['vacias'])): ?>
        <div class="tabla-responsive">
            <table class="tabla-ranking">
                <thead>
                    <tr><th>Sección</th><th>Área</th><th>Competencia</th><th>Docente</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cv['vacias'] as $v): ?>
                    <tr>
                        <td><?= e($v['seccion']) ?></td>
                        <td><?= e($v['area']) ?></td>
                        <td><?= e($v['competencia']) ?></td>
                        <td><?= e($v['docente'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) $cv['total_vacias'] > count($cv['vacias'])): ?>
            <p class="text-muted">Se muestran <?= count($cv['vacias']) ?> de <?= (int) $cv['total_vacias'] ?>.</p>
        <?php endif; ?>
        <?php endif; ?>
        <form method="POST" action="<?= url('director/periodos/' . $cvPeriodo['id'] . '/cerrar') ?>"
              data-confirm="¿Cerrar el <?= e($cvPeriodo['nombre_display']) ?> dejando estas competencias sin evaluar? Quedarán pendientes de la calificación extraordinaria.">
            <?= csrf_field() ?>
            <input type="hidden" name="confirmar_vacias" value="1">
            <button type="submit" class="btn btn--sm btn--warning">Cerrar igual el <?= e($cvPeriodo['nombre_display']) ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="bimestres-grid">
    <?php foreach ($periodos as $p):
        $estado = $p['estado'];
        $limite = $p['limite_notas'];
    ?>
    <div class="bimestre-card bimestre-card--<?= e($estado) ?>">
        <div class="bimestre-card__head">
            <h2 class="bimestre-card__titulo"><?= e($p['nombre_display']) ?></h2>
            <span class="badge <?= $badgePeriodo($estado) ?>"><?= $labelPeriodo($estado) ?></span>
        </div>

        <dl class="bimestre-card__datos">
            <div>
                <dt>Inicio</dt>
                <dd><?= e(fecha_es($p['fecha_inicio'])) ?></dd>
            </div>
            <div>
                <dt>Fin</dt>
                <dd><?= e(fecha_es($p['fecha_fin'])) ?></dd>
            </div>
            <div>
                <dt>Límite de notas</dt>
                <dd>
                    <?php if ($limite): ?>
                        <?= e(fecha_es($limite)) ?> &middot; <?= e(substr($limite, 11, 5)) ?>
                    <?php else: ?>
                        <span class="text-muted">Sin fecha límite</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <div class="bimestre-card__acciones">
            <?php if ($puedeEscribir): ?>
            <button type="button"
                    class="btn btn--sm btn--secondary"
                    onclick="abrirModalFechas(this)"
                    data-periodo-id="<?= (int) $p['id'] ?>"
                    data-nombre="<?= e($p['nombre_display']) ?>"
                    data-inicio="<?= e($p['fecha_inicio']) ?>"
                    data-fin="<?= e($p['fecha_fin']) ?>"
                    data-limite="<?= e($toLocalInput($limite)) ?>">
                Editar fechas
            </button>

            <?php if ($estado === 'pendiente' && $anioActivo && !$hayActivo): ?>
                <form method="POST" action="<?= url('director/periodos/' . $p['id'] . '/abrir') ?>"
                      data-confirm="¿Abrir el <?= e($p['nombre_display']) ?>? Los docentes podrán registrar notas.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--sm btn--primary">Abrir</button>
                </form>
            <?php elseif ($estado === 'pendiente' && !$anioActivo): ?>
                <span class="bimestre-card__nota">Activa el año para abrir bimestres</span>
            <?php elseif ($estado === 'pendiente' && $hayActivo): ?>
                <span class="bimestre-card__nota">Cierra el bimestre activo primero</span>
            <?php endif; ?>

            <?php if ($estado === 'activo'): ?>
                <form method="POST" action="<?= url('director/periodos/' . $p['id'] . '/cerrar') ?>"
                      data-confirm="¿Cerrar el <?= e($p['nombre_display']) ?>? Se bloquearán automáticamente todas las competencias pendientes (con lo que tengan).">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--sm btn--primary">Cerrar bimestre</button>
                </form>
            <?php endif; ?>
            <?php endif; /* $puedeEscribir — fin de las acciones del bimestre */ ?>

            <?php if ($estado === 'cerrado'): ?>
                <a href="<?= url('director/periodos/' . $p['id'] . '/stats') ?>" class="btn btn--sm btn--secondary">
                    Ver indicadores
                </a>
                <?php if ($puedeEscribir && $anio['estado'] !== 'cerrado' && !$hayActivo): ?>
                <button type="button" class="btn btn--sm btn--warning"
                        onclick="abrirModalReabrir(this)"
                        data-periodo-id="<?= e($p['id']) ?>"
                        data-nombre="<?= e($p['nombre_display']) ?>">
                    Reabrir
                </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal: editar fechas del bimestre -->
<div class="modal-overlay" id="modalFechas" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title" id="modalFechasTitulo">Editar fechas</h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar="modalFechas" aria-label="Cerrar">&times;</button>
        </div>
        <form method="POST" id="formFechas" novalidate>
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="fecha_inicio">Fecha de inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fecha_fin">Fecha de fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="limite_notas">Fecha límite para registrar notas</label>
                    <input type="datetime-local" name="limite_notas" id="limite_notas" class="form-input">
                    <p class="form-hint">
                        Opcional. Pasada esta fecha y hora, los docentes no podrán registrar
                        ni modificar notas del bimestre. Déjalo vacío para no aplicar límite.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary" data-modal-cerrar="modalFechas">Cancelar</button>
                <button type="submit" class="btn btn--primary">Guardar fechas</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: reabrir bimestre (motivo obligatorio) -->
<div class="modal-overlay" id="modalReabrir" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title" id="modalReabrirTitulo">Reabrir bimestre</h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar="modalReabrir" aria-label="Cerrar">&times;</button>
        </div>
        <form method="POST" id="formReabrir">
            <?= csrf_field() ?>
            <div class="modal-body">
                <p class="form-hint">
                    Reabrir un bimestre es una acción excepcional. Se liberarán las
                    competencias que el cierre bloqueó automáticamente sin notas; las
                    aprobadas por los docentes se conservan. Indica el motivo para dejar
                    constancia de quién y por qué reabre.
                </p>
                <div class="form-group">
                    <label class="form-label" for="motivo">Motivo de la reapertura</label>
                    <textarea name="motivo" id="motivo" class="form-input" rows="3"
                              minlength="10" maxlength="500" required
                              placeholder="Ej.: Correccion de notas de Matematica solicitada por direccion."></textarea>
                    <p class="form-hint">Mínimo 10 caracteres. Quedará registrado con tu usuario y la fecha.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary" data-modal-cerrar="modalReabrir">Cancelar</button>
                <button type="submit" class="btn btn--warning">Reabrir bimestre</button>
            </div>
        </form>
    </div>
</div>

<?php if ($periodoCerrado && $statsCierre): ?>
<!-- Modal: indicadores del cierre (se abre automáticamente tras cerrar) -->
<div class="modal-overlay" id="modalCierre" data-autoabrir="1" hidden>
    <div class="modal-box modal-box--ancho">
        <div class="modal-header">
            <h2 class="modal-title">Cierre de <?= e($periodoCerrado['nombre_display']) ?></h2>
            <button type="button" class="modal-cerrar" data-modal-cerrar="modalCierre" aria-label="Cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <?php $stats = $statsCierre; require __DIR__ . '/_stats-contenido.php'; ?>
            <?php $reaperturas = $reaperturasCierre; require __DIR__ . '/_reaperturas.php'; ?>
        </div>
        <div class="modal-footer">
            <a href="<?= url('director/periodos/' . $periodoCerrado['id'] . '/stats') ?>" class="btn btn--secondary">
                Ver en página completa
            </a>
            <button type="button" class="btn btn--primary" data-modal-cerrar="modalCierre">Entendido</button>
        </div>
    </div>
</div>
<?php endif; ?>
