<?php
/**
 * Vista: GESTOR del orden de mérito (staff; 19/10/2026). Reúne en una pantalla
 * el orden de mérito por GRADO (define la media beca) y el ranking por SECCIÓN
 * (interno, no la otorga), que antes eran dos pantallas. Mismo patrón que el
 * Acompañamiento pedagógico: las SECCIONES se eligen en un formulario GET con
 * «Aplicar» (`secciones[]`, sin marcar = todas); el rótulo de cada grado y los
 * atajos por nivel son ENLACES que marcan todas sus secciones, para que
 * funcionen sin JS. Los botones Imprimir llevan la misma selección al acta.
 *
 * El ranking de un GRADO se muestra siempre completo: elegir una sección no
 * recorta el puesto de nadie, solo dice qué grados y secciones se ven.
 *
 * @var array $periodo
 * @var array $periodos
 * @var array $merito          salida de componerMerito()
 * @var bool  $provisional     periodo aún ABIERTO: el cálculo es en vivo y se mueve
 * @var bool  $puedeEscribir   solo admin/RA resuelven empates
 * @var bool  $tieneDesempates
 */
$sel = $merito['secciones_ids'];

// Enlace a esta vista (o al acta) conservando el bimestre y la selección.
$base = static function (array $secciones, string $sufijo = '', array $extra = []) use ($periodo): string {
    $q = $extra;
    if ($secciones) { $q['secciones'] = array_values($secciones); }
    return 'director/orden-merito/' . (int) $periodo['id'] . $sufijo . ($q ? '?' . http_build_query($q) : '');
};

$nSecciones = 0;
foreach ($merito['ranking'] as $data) { $nSecciones += count($data['por_seccion']); }
$nGrados = count($merito['ranking']);

$hayPendientes = false;
foreach ($merito['ranking'] as $data) {
    foreach ($data['general'] as $est) {
        if (!empty($est['empate_pendiente'])) { $hayPendientes = true; break 2; }
    }
}
?>

<div class="page-header">
    <a href="<?= url('director/orden-merito') ?>"
       class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Orden de mérito</h1>
        <p class="page-subtitle">
            <?= e($periodo['nombre_display']) ?> — <?= e($periodo['anio']) ?>.
            Orden de mérito del <strong>grado</strong> (define la media beca) y ranking
            <strong>interno de cada sección</strong> (no la otorga). Elige las secciones e
            imprime solo lo filtrado.
        </p>
    </div>
    <?php if (!empty($tieneDesempates)): ?>
        <div class="btn-group">
            <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempates') ?>"
               class="btn btn--secondary btn--sm">
                📋 Acta de desempates
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="card mb-md">
    <div class="card__body">
        <form method="GET" action="<?= url('director/orden-merito') ?>" class="form-inline">
            <label for="periodo_id" class="form-label">Bimestre</label>
            <select name="periodo_id" id="periodo_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($periodos as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $periodo['id'] ? 'selected' : '' ?>>
                        <?= e($p['nombre_display']) ?> <?= e($p['anio']) ?>
                        (<?= e($p['estado']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php // Cambiar de bimestre CONSERVA los filtros; una sección sin
                  // ranking en ese bimestre la descarta el controlador. ?>
            <?php foreach ($sel as $sid): ?>
                <input type="hidden" name="secciones[]" value="<?= (int) $sid ?>">
            <?php endforeach; ?>
            <noscript><button type="submit" class="btn btn--primary btn--sm">Ver</button></noscript>
        </form>
        <?php if (!empty($merito['ranking'])): ?>
            <a href="<?= url($base($sel, '/imprimir', ['tipo' => 'grado'])) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                &#128424; Imprimir mérito por grado
                (<?= $nGrados ?> grado<?= $nGrados !== 1 ? 's' : '' ?>)
            </a>
            <a href="<?= url($base($sel, '/imprimir', ['tipo' => 'seccion'])) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">
                &#128424; Imprimir por sección
                (<?= $nSecciones ?> secci<?= $nSecciones !== 1 ? 'ones' : 'ón' ?>, una por hoja)
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($provisional): ?>
    <div class="merito-aviso">
        <span class="badge badge--activo">Provisional</span>
        El bimestre sigue <strong>abierto</strong>: este ranking se calcula EN VIVO sobre las
        competencias ya <strong>bloqueadas</strong>, así que <strong>cambia</strong> conforme
        los docentes registran y aprueban. El ranking definitivo se congela al cerrar.
    </div>
<?php endif; ?>

<?php if (empty($merito['niveles'])): ?>
    <div class="empty-state">
        <p>No hay calificaciones registradas en este periodo.</p>
    </div>
<?php else: ?>

    <?php // ── Filtros ─────────────────────────────────────────────────────
          // Formulario GET con «Aplicar»: las casillas no recargan solas, para
          // poder marcar varias secciones de una vez. Sin marcar = todas. El
          // `title` nombra a su tutor del bimestre (congelado al cerrarlo). ?>
    <form method="GET" action="<?= url('director/orden-merito/' . (int) $periodo['id']) ?>" class="cuadros-riesgo__filtros cuadros-riesgo__form">
        <div class="cuadros-riesgo__niveles">
            <?php foreach ($merito['niveles'] as $niv):
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
                                        <span class="cuadros-riesgo__cont"><?= (int) $s['n'] ?></span>
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

    <p class="merito-alcance"><strong>Alcance:</strong> <?= e($merito['alcance']) ?></p>

    <?php if ($hayPendientes): ?>
        <div class="merito-aviso">
            <strong>Hay empates sin resolver.</strong> Las filas resaltadas comparten puesto:
            el orden entre ellas <strong>aún no es oficializable</strong>.
        </div>
    <?php endif; ?>

    <?php $gradoIdx = 0; foreach ($merito['ranking'] as $gradoId => $data): $gradoIdx++; ?>
        <details class="card mb-lg" <?= $gradoIdx === 1 ? 'open' : '' ?>>
            <summary class="card__header card__header--toggle">
                <div class="card__header-left">
                    <h2 class="card__title">
                        <?= e($data['grado']['nivel_nombre']) ?> —
                        <?= e($data['grado']['nombre_display']) ?>
                    </h2>
                    <span class="badge badge--info">
                        <?= count($data['general']) ?> estudiantes
                    </span>
                </div>
                <svg class="card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="6,9 12,15 18,9"/>
                </svg>
            </summary>

            <?php if (empty($data['general'])): ?>
                <div class="card__body">
                    <p class="text-muted">Sin calificaciones registradas.</p>
                </div>
            <?php else: ?>

                <?php
                $pendienteGrado = false;
                foreach ($data['general'] as $est) {
                    if (!empty($est['empate_pendiente'])) { $pendienteGrado = true; break; }
                }
                ?>
                <?php if ($pendienteGrado): ?>
                    <div class="alerta-empate">
                        <span class="alerta-empate__texto">
                            ⚠ Hay un empate irreducible en este grado: ni los criterios de
                            regularidad alta (notas 15-16 y 16) lo separan. El puesto en disputa
                            debe resolverse antes de cerrar el bimestre.
                        </span>
                        <?php /* El AVISO lo ve todo el mundo —el director necesita
                                 saber que el grado tiene un empate sin resolver—;
                                 el boton que RESUELVE, solo quien escribe. Desde el
                                 17/10/2026 la Direccion entra al mismo expediente en
                                 solo lectura (sin formulario). */ ?>
                        <?php if ($puedeEscribir): ?>
                        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempate/' . $data['grado']['id']) ?>"
                           class="btn btn--primary btn--sm">Resolver empate</a>
                        <?php else: ?>
                        <a href="<?= url('director/orden-merito/' . $periodo['id'] . '/desempate/' . $data['grado']['id']) ?>"
                           class="btn btn--secondary btn--sm">Ver expediente</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <h3 class="merito-gestor__h3">Orden de mérito del grado</h3>
                <div class="tabla-responsive">
                <table class="tabla-ranking">
                    <thead>
                        <tr>
                            <th class="col-puesto text-center">Puesto</th>
                            <th class="col-nombre">Apellidos y nombres</th>
                            <th class="text-center">Sección</th>
                            <th class="text-center">Comp.</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Promedio</th>
                            <th class="text-center">Distinción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['general'] as $est): ?>
                            <?php $pendiente = !empty($est['empate_pendiente']); ?>
                            <tr class="<?= $est['media_beca'] ? 'fila-media-beca' : '' ?> <?= $pendiente ? 'fila-empate' : '' ?>">
                                <td class="col-puesto text-center">
                                    <span class="puesto puesto--<?= $est['puesto'] <= 3 ? $est['puesto'] : 'normal' ?>">
                                        <?= $est['puesto'] ?>°
                                    </span>
                                </td>
                                <td class="col-nombre">
                                    <?= e($est['apellido_paterno'] . ' ' .
                                        $est['apellido_materno'] . ', ' .
                                        $est['nombres']) ?>
                                </td>
                                <td class="text-center">
                                    <?= e($est['seccion_nombre']) ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $est['num_competencias'] ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $est['total_notas'] ?>
                                </td>
                                <td class="text-center">
                                    <strong><?= sprintf('%05.2f', $est['promedio_general']) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($pendiente): ?>
                                        <span class="badge badge--warning">
                                            ⚠ Empate por resolver
                                        </span>
                                    <?php elseif ($est['media_beca']): ?>
                                        <span class="badge badge--activo">
                                            🏆 Media beca
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!empty($data['por_seccion'])): ?>
                    <div class="card__body">
                        <h3 class="merito-gestor__h3">
                            Ranking por sección
                            <span class="merito-gestor__nota">interno de cada sección: no otorga media beca</span>
                        </h3>
                        <?php $j = 0; foreach ($data['por_seccion'] as $secNombre => $estudiantes): $j++;
                            // Distincion de seccion IGUAL que /docente/mis-cargas: la LETRA
                            // (recuadro) es el identificador.
                            $secLetra = mb_strtoupper(mb_substr((string) $secNombre, 0, 1));
                        ?>
                            <details class="merito-seccion-acordeon" <?= $j === 1 ? 'open' : '' ?>>
                                <summary class="merito-seccion-acordeon__head">
                                    <span class="merito-seccion-acordeon__rotulo">Sección</span>
                                    <span class="merito-seccion-acordeon__letra"><?= e($secLetra) ?></span>
                                    <span class="badge badge--info"><?= count($estudiantes) ?> estudiantes</span>
                                    <svg class="card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <polyline points="6,9 12,15 18,9"/>
                                    </svg>
                                </summary>
                                <div class="tabla-responsive">
                                    <table class="tabla-ranking">
                                        <thead>
                                            <tr>
                                                <th class="col-puesto text-center">Puesto</th>
                                                <th class="col-nombre">Apellidos y nombres</th>
                                                <th class="text-center">Comp.</th>
                                                <th class="text-center">Promedio</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($estudiantes as $est): ?>
                                                <?php $pendiente = !empty($est['empate_pendiente']); ?>
                                                <tr class="<?= $pendiente ? 'fila-empate' : '' ?>">
                                                    <td class="col-puesto text-center">
                                                        <span class="puesto puesto--<?= $est['puesto'] <= 3 ? $est['puesto'] : 'normal' ?>"><?= (int) $est['puesto'] ?>°</span>
                                                    </td>
                                                    <td class="col-nombre"><?= e($est['apellido_paterno'] . ' ' . $est['apellido_materno'] . ', ' . $est['nombres']) ?></td>
                                                    <td class="text-center"><?= (int) $est['num_competencias'] ?></td>
                                                    <td class="text-center"><strong><?= sprintf('%05.2f', $est['promedio_general']) ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </details>
    <?php endforeach; ?>

<?php endif; ?>
