<?php
/**
 * Historial del docente — grilla criterio-a-criterio de SU carga en un bimestre
 * cerrado, SOLO LECTURA. Reutiliza el parcial consulta-notas/_tabla.php.
 * @var array $carga         [nombre_display, area_nombre, grado_nombre, seccion_nombre, nivel_nombre, nivel_codigo]
 * @var array $periodo
 * @var array $competencias  [{competencia, criterios, alumnos}]
 * @var array $exonerados    matricula_ids exonerados de la carga
 * @var array $extraordinariasCarga  extraordinarias de RA (sección final)
 */
$nivelCodigo = $carga['nivel_codigo'];
?>

<div class="page-header">
    <a href="<?= url('docente/mis-cargas?periodo_id=' . (int) $periodo['id']) ?>"
       class="btn btn--secondary btn--sm">← Mis cargas</a>
    <div>
        <h1 class="page-title"><?= e($carga['nombre_display'] ?? $carga['area_nombre']) ?></h1>
        <p class="page-subtitle">
            <?= e($carga['grado_nombre'] . ' ' . $carga['seccion_nombre']) ?> ·
            <?= e($carga['nivel_nombre']) ?> ·
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?>
        </p>
    </div>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<div class="flash flash--warning">
    Estás viendo un bimestre cerrado. Las notas son de solo lectura.
</div>

<?php if (empty($competencias)): ?>
    <div class="empty-state">
        <p>No hay competencias oficiales (bloqueadas) en este bimestre para esta carga.</p>
    </div>
<?php else: ?>
    <?php foreach ($competencias as $bloque): ?>
        <?php
        $competencia     = $bloque['competencia'];
        $criterios       = $bloque['criterios'];
        $alumnos         = $bloque['alumnos'];
        ?>
        <div class="card mb-lg">
            <div class="card__header">
                <h2 class="card__title"><?= e($competencia['nombre_completo']) ?></h2>
                <span class="competencia-card__codigo"><?= e($competencia['codigo_minedu'] ?? '') ?></span>
            </div>
            <?php require VIEW_PATH . '/consulta-notas/_tabla.php'; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require VIEW_PATH . '/consulta-notas/_extraordinarias-carga.php'; ?>
