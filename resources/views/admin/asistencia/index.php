<?php
/**
 * @var array       $porNivel       { nivel_nombre => [ seccion[] ] }
 * @var array|null  $periodoActivo  { id, nombre_display, ... } o null
 * @var array       $progreso       [ seccion_id => [esperados, registrados] ]
 */
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Asistencia — Incidencias</h1>
        <p class="page-subtitle">
            <?php if ($periodoActivo): ?>
                Selecciona una sección para registrar las incidencias del
                <strong><?= e($periodoActivo['nombre_display']) ?></strong>.
            <?php else: ?>
                No hay periodo abierto. Comunícate con Registro Académico.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (empty($porNivel)): ?>
    <div class="empty-state">
        <?php // Al auxiliar la lista vacía no significa que el colegio no tenga
              // secciones, sino que ninguna está a SU cargo en este bimestre. ?>
        <?php if (has_role(ROL_AUXILIAR)): ?>
            <p>Aún no tienes secciones asignadas en este bimestre. Comunícate con Registro Académico para que te las asignen.</p>
        <?php else: ?>
            <p>No hay secciones activas configuradas para el año académico en curso.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php foreach ($porNivel as $nivel => $secciones): ?>
        <h2 class="asistencia-nivel-titulo"><?= e($nivel) ?></h2>
        <div class="asistencia-secciones-grid">
            <?php foreach ($secciones as $s):
                $datos        = $progreso[(int) $s['id']] ?? ['esperados' => 0, 'registrados' => 0];
                $esperados    = $datos['esperados'];
                $registrados  = $datos['registrados'];
                $pct          = $esperados > 0 ? (int) round($registrados * 100 / $esperados) : 0;
                $completo     = $esperados > 0 && $registrados >= $esperados;
            ?>
                <a href="<?= url('admin/asistencia/' . $s['id']) ?>"
                   class="asistencia-seccion-card">
                    <span class="asistencia-seccion-card__grado"><?= e($s['grado_nombre']) ?></span>
                    <span class="asistencia-seccion-card__nombre">Sección <?= e($s['seccion_nombre']) ?></span>

                    <?php if ($periodoActivo): ?>
                        <div class="asistencia-progreso<?= $completo ? ' asistencia-progreso--completo' : '' ?>">
                            <?php if ($esperados === 0): ?>
                                <span class="asistencia-progreso__vacio">Sin estudiantes</span>
                            <?php else: ?>
                                <div class="asistencia-progreso__track"
                                     role="progressbar"
                                     aria-valuenow="<?= $pct ?>"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     aria-label="Progreso de registro de incidencias">
                                    <div class="asistencia-progreso__fill" style="--pct:<?= $pct ?>%"></div>
                                </div>
                                <div class="asistencia-progreso__meta">
                                    <span class="asistencia-progreso__conteo">
                                        <?= $registrados ?>/<?= $esperados ?>
                                    </span>
                                    <span class="asistencia-progreso__pct"><?= $pct ?>%</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
