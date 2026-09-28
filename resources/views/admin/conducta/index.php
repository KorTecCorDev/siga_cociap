<?php
/**
 * @var array       $porNivel       { nivel_nombre => [ seccion[] ] }
 * @var array|null  $periodoActivo  periodo editable en curso o null
 * @var array|null  $periodoVer     periodo mostrado (editable o cerrado) o null
 * @var array       $periodosNav    periodos del select: editable + cerrados
 * @var bool        $esHistorial    true = periodo cerrado (solo lectura)
 * @var array       $progreso       [ seccion_id => [esperados, calificados, bloqueada, cerrada_tutor] ]
 */
$pidVer = $periodoVer ? (int) $periodoVer['id'] : 0;
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Calificaciones de Conducta</h1>
        <p class="page-subtitle">
            <?php if ($esHistorial): ?>
                Historial del <strong><?= e($periodoVer['nombre_display']) ?></strong>
                — solo lectura. Selecciona una sección para ver su registro.
            <?php elseif ($periodoActivo): ?>
                Selecciona una sección para registrar las calificaciones del
                <strong><?= e($periodoActivo['nombre_display']) ?></strong>.
            <?php else: ?>
                No hay periodo abierto. Comunícate con Registro Académico.
            <?php endif; ?>
        </p>
    </div>
    <?php if (!empty($periodosNav)): ?>
        <form method="GET" action="<?= url('admin/conducta') ?>" class="conducta-periodo-selector">
            <label for="periodo" class="form-label">Bimestre</label>
            <select name="periodo" id="periodo" class="form-select"
                    onchange="this.form.submit()">
                <?php foreach ($periodosNav as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        <?= (int) $p['id'] === $pidVer ? 'selected' : '' ?>>
                        <?= e($p['nombre_display']) ?>
                        <?= (bool) $p['editable'] ? '(en curso)' : '(cerrado)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($porNivel)): ?>
    <div class="empty-state">
        <?php // Al auxiliar la lista vacía no significa que el colegio no tenga
              // secciones, sino que ninguna estuvo a SU cargo en ese bimestre. ?>
        <?php if (has_role(ROL_AUXILIAR) && $esHistorial): ?>
            <p>No tuviste secciones a tu cargo en este bimestre.</p>
        <?php elseif (has_role(ROL_AUXILIAR)): ?>
            <p>Aún no tienes secciones asignadas en este bimestre. Comunícate con Registro Académico para que te las asignen.</p>
        <?php else: ?>
            <p>No hay secciones activas configuradas para el año académico en curso.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php foreach ($porNivel as $nivel => $secciones): ?>
        <h2 class="conducta-nivel-titulo"><?= e($nivel) ?></h2>
        <div class="conducta-secciones-grid">
            <?php foreach ($secciones as $s):
                $datos       = $progreso[(int) $s['id']] ?? ['esperados' => 0, 'calificados' => 0, 'bloqueada' => false, 'cerrada_tutor' => false];
                $esperados   = $datos['esperados'];
                $calificados = $datos['calificados'];
                $bloqueada   = !empty($datos['bloqueada']);
                $cerradaTut  = !empty($datos['cerrada_tutor']);
                $pct         = $esperados > 0 ? (int) round($calificados * 100 / $esperados) : 0;
                $completo    = $esperados > 0 && $calificados >= $esperados;
            ?>
                <a href="<?= url('admin/conducta/' . $s['id'] . ($esHistorial ? '?periodo=' . $pidVer : '')) ?>"
                   class="conducta-seccion-card<?= $bloqueada ? ' conducta-seccion-card--bloqueada' : '' ?>">
                    <span class="conducta-seccion-card__grado"><?= e($s['grado_nombre']) ?></span>
                    <span class="conducta-seccion-card__nombre">Sección <?= e($s['seccion_nombre']) ?></span>

                    <?php if ($bloqueada): ?>
                        <span class="conducta-estado-badge conducta-estado-badge--<?= $cerradaTut ? 'cerrada' : 'bloqueada' ?>">
                            <?= $cerradaTut ? '✓ Cerrada por el tutor' : '🔒 Bloqueada' ?>
                        </span>
                    <?php elseif ($esHistorial): ?>
                        <span class="text-muted">Sin cierre</span>
                    <?php endif; ?>

                    <?php if (!$esHistorial && $periodoActivo && !$bloqueada): ?>
                        <div class="conducta-progreso<?= $completo ? ' conducta-progreso--completo' : '' ?>">
                            <?php if ($esperados === 0): ?>
                                <span class="conducta-progreso__vacio">Sin estudiantes</span>
                            <?php else: ?>
                                <div class="conducta-progreso__track"
                                     role="progressbar"
                                     aria-valuenow="<?= $pct ?>"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     aria-label="Progreso de conducta">
                                    <div class="conducta-progreso__fill" style="--pct:<?= $pct ?>%"></div>
                                </div>
                                <div class="conducta-progreso__meta">
                                    <span class="conducta-progreso__conteo">
                                        <?= $calificados ?>/<?= $esperados ?>
                                    </span>
                                    <span class="conducta-progreso__pct"><?= $pct ?>%</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
