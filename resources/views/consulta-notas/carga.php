<?php
/**
 * Consulta de calificaciones — grillas criterio-a-criterio (solo lectura) de
 * una carga. Reutiliza el mismo lenguaje visual del resumen del docente vía el
 * parcial _tabla.php; aqui NO hay inputs ni botones (solo lo oficial).
 * @var array $periodo
 * @var array $carga         [id, grado_nombre, seccion_nombre, nivel_nombre, nivel_codigo, area_nombre, subarea_nombre, docente, area_id]
 * @var array $competencias  [{competencia, criterios, alumnos, bloqueado_en}]
 * @var array $exonerados    matricula_ids exonerados de la carga
 * @var array $extraordinariasCarga  extraordinarias de RA de la carga (sección final)
 */
$area = $carga['subarea_nombre']
    ? $carga['area_nombre'] . ' — ' . $carga['subarea_nombre']
    : $carga['area_nombre'];
$nivelCodigo = $carga['nivel_codigo'];
?>

<div class="page-header">
    <a href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $carga['seccion_id']) ?>"
       class="btn btn--secondary btn--sm">← Áreas</a>
    <div>
        <h1 class="page-title"><?= e($area) ?></h1>
        <p class="page-subtitle">
            <?= e($carga['grado_nombre'] . ' ' . $carga['seccion_nombre']) ?> ·
            <?= e($carga['nivel_nombre']) ?> ·
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?>
            — Docente: <?= e($carga['docente'] ?: 'Sin docente') ?>
        </p>
    </div>
    <?php // El área ENTERA (todas sus cargas), no solo esta carga, a la derecha
          // como en la sección. Solo en un bimestre cerrado: en otro, la ruta
          // responde 404. La hoja de transversales de la SECCIÓN va solo si se
          // marca (decisión del usuario, 27/10/2026); el servidor solo acepta 1. ?>
    <?php if ($periodo['estado'] === 'cerrado' && $carga['area_id'] > 0): ?>
        <form method="GET" target="_blank" class="cn-registro"
              action="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $carga['seccion_id'] . '/registro-auxiliar') ?>">
            <input type="hidden" name="area_id" value="<?= (int) $carga['area_id'] ?>">
            <button type="submit" class="btn btn--registro btn--sm">
                <span class="btn-icon btn-icon--registro" aria-hidden="true"></span>
                Registro auxiliar del área
            </button>
            <label class="form-check cn-registro__opcion">
                <input type="checkbox" name="transversales" value="1">
                Incluir transversales de la sección
            </label>
        </form>
    <?php endif; ?>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<?php $transversalAbierto = false; ?>
<?php foreach ($competencias as $bloque): ?>
    <?php
    $competencia     = $bloque['competencia'];
    $criterios       = $bloque['criterios'];
    $alumnos         = $bloque['alumnos'];
    $esTransversal   = !empty($bloque['es_transversal']);
    ?>

    <?php // Separador ANTES del primer bloque transversal: el docente las registra
          // en su carga, pero no son del area — conviene que se lea de un vistazo.
          if ($esTransversal && !$transversalAbierto): $transversalAbierto = true; ?>
        <div class="transversales-separador">
            <?php // "registro del docente" en el TITULO: sin eso, este bloque y los
                  // promedios del tutor (consulta-notas/transversales.php) se llamaban
                  // igual, y son datos distintos — este es el insumo, aquel el resultado. ?>
            <h2 class="transversales-separador__titulo">Competencias Transversales — Registro del docente</h2>
            <p class="transversales-separador__desc">
                TIC y Aprendizaje aut&oacute;nomo, registradas por este docente en su
                carga. El promedio que llega a la boleta lo agrega el tutor al cerrar
                el bimestre transversal de la secci&oacute;n.
            </p>
        </div>
    <?php endif; ?>

    <div class="card mb-lg<?= $esTransversal ? ' competencia-card--transversal' : '' ?>">
        <div class="card__header">
            <h2 class="card__title"><?= e($competencia['nombre_completo']) ?></h2>
            <span class="competencia-card__codigo"><?= e($competencia['codigo_minedu'] ?? '') ?></span>
        </div>
        <?php require VIEW_PATH . '/consulta-notas/_tabla.php'; ?>
    </div>
<?php endforeach; ?>

<?php require VIEW_PATH . '/consulta-notas/_extraordinarias-carga.php'; ?>
