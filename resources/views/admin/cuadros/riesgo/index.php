<?php
/**
 * Vista: Estudiantes en riesgo académico (23/09/2026). Solo lectura.
 *
 * Salió del bloque 3b de `/admin/cuadros`, que conserva solo la banda con un
 * enlace aquí. Roles: admin, registro académico y los tres directores
 * (Dirección solo ve bimestres cerrados).
 *
 * Filtros: varios grados de cualquier nivel (`grados[]`) y la lente «primaria
 * solo C» (`primaria=c`) van por URL, en un formulario con «Aplicar»: recargan y
 * recalculan el resumen, y el botón Imprimir los lleva al A4 (se imprime lo
 * filtrado). Los atajos por nivel son ENLACES, para que funcionen sin JS. El
 * buscador es solo de pantalla, para ubicar a una persona, y no toca las cifras.
 *
 * @var array      $periodos
 * @var array|null $periodo
 * @var array|null $riesgo   salida de componerRiesgo()
 * @var bool       $avisoNoCerrado
 */
// Enlace a esta vista (o a su A4) conservando el bimestre y el modo pedido.
// «Todos los grados» es la ausencia de `grados`; «B y C», la de `primaria`.
// `http_build_query` emite `grados[0]=…`, que PHP lee igual que `grados[]=`.
$pideC = $riesgo && $riesgo['pide_solo_c'];
$base  = static function (array $grados, string $ruta = 'admin/cuadros/riesgo') use ($periodo, $pideC): string {
    $q = ['periodo_id' => (int) $periodo['id']];
    if ($grados) { $q['grados'] = array_values($grados); }
    if ($pideC)  { $q['primaria'] = 'c'; }
    return $ruta . '?' . http_build_query($q);
};
$sel = $riesgo ? $riesgo['grados_ids'] : [];
?>

<div class="page-header">
    <a href="<?= url('admin/cuadros' . ($periodo ? '?periodo_id=' . (int) $periodo['id'] : '')) ?>"
       class="btn btn--secondary btn--sm">&larr; Cuadros estadísticos</a>
    <div>
        <h1 class="page-title">Estudiantes en riesgo académico</h1>
        <p class="page-subtitle">
            Estudiantes que acumulan competencias no aprobadas en el bimestre, con su
            desglose y dónde se concentran los casos. Solo lectura.
        </p>
    </div>
</div>

<div class="card mb-md">
    <div class="card__body">
        <form method="GET" action="<?= url('admin/cuadros/riesgo') ?>" class="form-inline">
            <label for="periodo_id" class="form-label">Bimestre</label>
            <select name="periodo_id" id="periodo_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($periodos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $periodo && (int) $p['id'] === (int) $periodo['id'] ? 'selected' : '' ?>>
                        <?= e($p['nombre_display']) ?> <?= e($p['anio']) ?>
                        (<?= e($p['estado']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php // Cambiar de bimestre CONSERVA los filtros; un grado que no
                  // exista en el otro bimestre lo descarta el controlador. ?>
            <?php foreach ($sel as $gid): ?>
                <input type="hidden" name="grados[]" value="<?= (int) $gid ?>">
            <?php endforeach; ?>
            <?php if ($pideC): ?>
                <input type="hidden" name="primaria" value="c">
            <?php endif; ?>
            <noscript><button type="submit" class="btn btn--primary btn--sm">Ver</button></noscript>
        </form>
        <?php if ($periodo && $riesgo && $riesgo['stats']['resumen']['total'] > 0): ?>
            <a href="<?= url($base($sel, 'admin/cuadros/riesgo/imprimir')) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                &#128424; Imprimir<?= $sel || !$riesgo['contar_b'] ? ' lo filtrado' : ' informe' ?>
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
           y bloqueen sus notas no se puede saber quién está en riesgo.</p>
    </div>
<?php else:
    // Casos por grado en el modo actual, para el contador de cada casilla.
    $casos = [];
    foreach ($riesgo['por_grado'] as $g) {
        $casos[(int) $g['grado']['id']] = count($g['en_riesgo']);
    } ?>

    <?php // ── Filtros ─────────────────────────────────────────────────────
          // Formulario GET con «Aplicar»: las casillas no recargan solas, para
          // poder marcar varios grados de una vez. Sin marcar = todos. Los
          // atajos son enlaces (funcionan sin JS). El contador de cada grado
          // sale del modo actual (con o sin B). ?>
    <form method="GET" action="<?= url('admin/cuadros/riesgo') ?>" class="cuadros-riesgo__filtros cuadros-riesgo__form">
        <input type="hidden" name="periodo_id" value="<?= (int) $periodo['id'] ?>">

        <div class="cuadros-riesgo__niveles">
            <?php foreach ($riesgo['niveles'] as $niv):
                $ids = array_map(static fn(array $gr): int => (int) $gr['id'], $niv['grados']); ?>
                <fieldset class="cuadros-riesgo__nivel">
                    <legend class="cuadros-riesgo__rotulo"><?= e($niv['nombre']) ?></legend>
                    <div class="cuadros-riesgo__chips">
                        <?php foreach ($niv['grados'] as $gr):
                            $gid = (int) $gr['id']; ?>
                            <label class="orden-chip cuadros-riesgo__casilla">
                                <input type="checkbox" name="grados[]" value="<?= $gid ?>"
                                       <?= in_array($gid, $sel, true) ? 'checked' : '' ?>>
                                <?= e($gr['nombre_display']) ?>
                                <span class="cuadros-riesgo__cont"><?= (int) ($casos[$gid] ?? 0) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <a class="cuadros-riesgo__atajo" href="<?= url($base($ids)) ?>">Solo <?= e(mb_strtolower($niv['nombre'])) ?></a>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <fieldset class="cuadros-riesgo__modo">
            <legend class="cuadros-riesgo__rotulo">Primaria cuenta</legend>
            <label><input type="radio" name="primaria" value="" <?= $pideC ? '' : 'checked' ?>> B y C (regla oficial)</label>
            <label><input type="radio" name="primaria" value="c" <?= $pideC ? 'checked' : '' ?>> Solo C</label>
            <small class="cuadros-riesgo__nota">No afecta a secundaria, que siempre cuenta solo C.</small>
        </fieldset>

        <div class="cuadros-riesgo__acciones">
            <button type="submit" class="btn btn--primary btn--sm">Aplicar</button>
            <?php if ($sel): ?>
                <a class="cuadros-riesgo__atajo" href="<?= url($base([])) ?>">Todos los grados</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($riesgo['stats']['resumen']['total'] === 0): ?>
        <div class="empty-state">
            <p>Ningún estudiante <?= $sel || !$riesgo['contar_b'] ? 'de esta selección ' : '' ?>llega
               al umbral de riesgo en este bimestre.</p>
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

<?php endif; ?>

<script src="<?= url('js/cuadros-riesgo.js') ?>"></script>
