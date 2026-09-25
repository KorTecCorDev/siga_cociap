<?php
/**
 * Vista: Acompañamiento pedagógico (23/09/2026; nació como «Estudiantes en
 * riesgo académico» y se amplió el 24/09 con el bloque de SEGUIMIENTO). Solo
 * lectura. Dos bloques que no se suman: EN RIESGO (RR/PER) y EN SEGUIMIENTO
 * (competencias bajas, `seguimiento_pedagogico()`; desde el 25/09/2026 incluye
 * también RR/PER, que salen en los dos).
 *
 * Salió del bloque 3b de `/admin/cuadros`, que conserva solo la banda con un
 * enlace aquí. Roles: admin, registro académico y los tres directores
 * (Dirección solo ve bimestres cerrados).
 *
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU: requiere
 * recuperación (RR) o permanece en el grado (PER).
 *
 * Filtros: varias SECCIONES de cualquier grado y nivel (`secciones[]`) van por
 * URL, en un formulario con «Aplicar»: recargan y recalculan el resumen, y los
 * botones Imprimir los llevan al A4 (se imprime lo filtrado) y al LOTE por
 * tutor (una sección por hoja). El rótulo de cada grado y los atajos por nivel
 * son ENLACES que marcan todas sus secciones, para que funcionen sin JS. El
 * buscador es solo de pantalla.
 *
 * @var array      $periodos
 * @var array|null $periodo
 * @var array|null $riesgo   salida de componerRiesgo()
 * @var bool       $avisoNoCerrado
 */
// Enlace a esta vista (o a sus A4) conservando el bimestre y la selección.
// «Todas las secciones» es la ausencia de `secciones`. `http_build_query`
// emite `secciones[0]=…`, que PHP lee igual.
$base = static function (array $secciones, string $ruta = 'admin/cuadros/acompanamiento') use ($periodo): string {
    $q = ['periodo_id' => (int) $periodo['id']];
    if ($secciones) { $q['secciones'] = array_values($secciones); }
    return $ruta . '?' . http_build_query($q);
};
$sel = $riesgo ? $riesgo['secciones_ids'] : [];
?>

<div class="page-header">
    <a href="<?= url('admin/cuadros' . ($periodo ? '?periodo_id=' . (int) $periodo['id'] : '')) ?>"
       class="btn btn--secondary btn--sm">&larr; Cuadros estadísticos</a>
    <div>
        <h1 class="page-title">Acompañamiento pedagógico</h1>
        <p class="page-subtitle">
            <strong>En riesgo académico:</strong> estudiantes que, con el último nivel registrado de
            cada competencia hasta este bimestre, <strong>no alcanzarían la promoción de grado</strong>
            según la norma del MINEDU (requieren recuperación o permanecerían en el grado), con su
            desglose y dónde se concentran los casos. <strong>En seguimiento:</strong> estudiantes con
            competencias bajas que conviene acompañar, estén o no en riesgo. Solo lectura.
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <form method="GET" action="<?= url('admin/cuadros/acompanamiento') ?>" class="form-inline">
            <label for="periodo_id" class="form-label">Bimestre</label>
            <select name="periodo_id" id="periodo_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($periodos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $periodo && (int) $p['id'] === (int) $periodo['id'] ? 'selected' : '' ?>>
                        <?= e($p['nombre_display']) ?> <?= e($p['anio']) ?>
                        (<?= e($p['estado']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php // Cambiar de bimestre CONSERVA los filtros; una sección que
                  // no sea del año de ese bimestre la descarta el controlador. ?>
            <?php foreach ($sel as $sid): ?>
                <input type="hidden" name="secciones[]" value="<?= (int) $sid ?>">
            <?php endforeach; ?>
            <noscript><button type="submit" class="btn btn--primary btn--sm">Ver</button></noscript>
        </form>
        <?php if ($periodo && $riesgo && ($riesgo['stats']['resumen']['total'] > 0 || $riesgo['stats']['resumen']['seguimiento'] > 0)): ?>
            <a href="<?= url($base($sel, 'admin/cuadros/acompanamiento/imprimir')) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                &#128424; Imprimir<?= $sel ? ' lo filtrado' : ' informe' ?>
            </a>
        <?php endif; ?>
        <?php // El lote va aunque la selección no tenga casos: cada tutor recibe
              // su hoja, y «nadie llega al umbral» también se entrega. ?>
        <?php if ($periodo && $riesgo && !empty($riesgo['por_grado'])): ?>
            <a href="<?= url($base($sel, 'admin/cuadros/acompanamiento/tutores')) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                &#128424; Imprimir por tutor
                (<?= $sel ? count($sel) : count($riesgo['secciones']) ?> secci<?= ($sel ? count($sel) : count($riesgo['secciones'])) !== 1 ? 'ones' : 'ón' ?>,
                una por hoja)
            </a>
        <?php endif; ?>

        <?php if (!empty($avisoNoCerrado) && $periodo): ?>
            <div class="alert alert--info">
                Ese bimestre <strong>todavía no está cerrado</strong>. Dirección ve solo
                bimestres cerrados, así que se muestra
                <strong><?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?></strong>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$periodo || !$riesgo): ?>
    <div class="empty-state">
        <?php if (solo_bimestres_cerrados()): ?>
            <p>Todavía no hay ningún bimestre cerrado. En cuanto se cierre el primero aparecerá aquí.</p>
        <?php else: ?>
            <p>No hay bimestres disponibles.</p>
        <?php endif; ?>
    </div>
<?php elseif (empty($riesgo['por_grado'])): ?>
    <div class="empty-state">
        <p>Este bimestre todavía no tiene competencias bloqueadas: hasta que los docentes aprueben
           y bloqueen sus notas no puede proyectarse ninguna situación final.</p>
    </div>
<?php else: ?>

    <?php // ── Filtros ─────────────────────────────────────────────────────
          // Formulario GET con «Aplicar»: las casillas no recargan solas, para
          // poder marcar varias secciones de una vez. Sin marcar = todas. El
          // rótulo del grado y los atajos son enlaces (funcionan sin JS). El
          // `title` nombra a su tutor actual. ?>
    <form method="GET" action="<?= url('admin/cuadros/acompanamiento') ?>" class="cuadros-riesgo__filtros cuadros-riesgo__form">
        <input type="hidden" name="periodo_id" value="<?= (int) $periodo['id'] ?>">

        <div class="cuadros-riesgo__niveles">
            <?php foreach ($riesgo['niveles'] as $niv):
                $idsNivel = []; ?>
                <fieldset class="cuadros-riesgo__nivel">
                    <legend class="cuadros-riesgo__rotulo"><?= e($niv['nombre']) ?></legend>
                    <?php foreach ($niv['grados'] as $gr):
                        $idsGrado = array_map(static fn(array $s): int => (int) $s['id'], $gr['secciones']);
                        $idsNivel = array_merge($idsNivel, $idsGrado); ?>
                        <div class="cuadros-riesgo__grado">
                            <a class="cuadros-riesgo__grado-rot" href="<?= url($base($idsGrado)) ?>"
                               title="Ver todas las secciones de <?= e($gr['nombre'] . ' de ' . $niv['nombre']) ?>"><?= e($gr['nombre']) ?></a>
                            <div class="cuadros-riesgo__chips">
                                <?php foreach ($gr['secciones'] as $s):
                                    $sid = (int) $s['id']; ?>
                                    <label class="orden-chip cuadros-riesgo__casilla"
                                           title="Tutor(a): <?= e($s['tutor_nombre'] ?? 'sin tutor asignado') ?>">
                                        <input type="checkbox" name="secciones[]" value="<?= $sid ?>"
                                               <?= in_array($sid, $sel, true) ? 'checked' : '' ?>>
                                        <?= e($s['nombre']) ?>
                                        <span class="cuadros-riesgo__cont"><?= (int) $s['casos'] ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <a class="cuadros-riesgo__atajo" href="<?= url($base($idsNivel)) ?>">Solo <?= e(mb_strtolower($niv['nombre'])) ?></a>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <div class="cuadros-riesgo__acciones">
            <button type="submit" class="btn btn--primary btn--sm">Aplicar</button>
            <?php if ($sel): ?>
                <a class="cuadros-riesgo__atajo" href="<?= url($base([])) ?>">Todas las secciones</a>
            <?php endif; ?>
        </div>
    </form>

    <h2 class="riesgo-h2">Estudiantes en riesgo académico</h2>
    <?php if ($riesgo['stats']['resumen']['total'] === 0): ?>
        <div class="empty-state">
            <p><?php $res = $riesgo['stats']['resumen']; $sinCasosAlcance = $sel ? 'de esta selección' : ''; ?>
               <?php require VIEW_PATH . '/admin/cuadros/riesgo/_sin-casos.php'; ?></p>
        </div>
    <?php else: ?>

        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_resumen.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_leer.php'; ?>
        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_criticos.php'; ?>

        <?php // Buscador: nace oculto, lo destapa cuadros-riesgo.js. ?>
        <div class="cuadros-riesgo__filtros riesgo-buscador" id="riesgo-filtros" hidden>
            <div class="cuadros-riesgo__campo">
                <label class="form-label" for="riesgo-buscar">Buscar estudiante en el listado</label>
                <input type="search" id="riesgo-buscar" class="form-input"
                       placeholder="Apellido, nombre, sección o grado"
                       autocomplete="off" spellcheck="false">
            </div>
            <p class="cuadros-riesgo__contador" id="riesgo-contador" role="status" aria-live="polite"></p>
        </div>
        <div class="empty-state cuadros-riesgo__sin-resultados" id="riesgo-sin-resultados" hidden>
            <p>Ningún estudiante del listado coincide con la búsqueda.</p>
        </div>

        <?php require VIEW_PATH . '/admin/cuadros/riesgo/_listado.php'; ?>
    <?php endif; ?>

    <?php // Fuera del `if`: el seguimiento incluye promovidos, así que existe aunque
          // la selección no tenga ningún caso de riesgo (`total` = 0). ?>
    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_seguimiento.php'; ?>
    <?php require VIEW_PATH . '/admin/cuadros/riesgo/_pendiente-final.php'; ?>

<?php endif; ?>

<script src="<?= url('js/cuadros-riesgo.js') ?>"></script>
