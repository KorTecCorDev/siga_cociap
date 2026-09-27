<?php
/**
 * @var array  $niveles     [{ id, nombre }]
 * @var int    $nivelActivo
 * @var array  $areas       [{ id, nombre, tipo, activa, orden }]
 * @var int    $areaId
 * @var ?array $area        { id, nombre, nombre_boleta, alias_boleta, nombre_siagie,
 *                            tipo, orden, activa, nivel_id, nivel_nombre,
 *                            subareas:     [{id, nombre, orden, competencias: [...]}],
 *                            competencias: [{id, codigo_minedu, nombre_completo, nombre_corto, orden}] }
 */

$tipoBadge = fn(string $tipo): string => match($tipo) {
    'con_subareas' => 'Con subáreas',
    'area_curso'   => 'Área-curso',
    'transversal'  => 'Transversal',
    // Taller (migración 064): cuenta para la situación final solo en los años
    // y grados que la UGEL aprobó (`talleres_aprobacion`, bloque de abajo).
    'taller'       => 'Taller',
    default        => $tipo,
};

$tipoAbrev = fn(string $tipo): string => match($tipo) {
    'con_subareas' => 'C/sub',
    'transversal'  => 'Transv.',
    'taller'       => 'Taller',
    default        => 'Curso',
};

$primerAreaId = !empty($areas) ? (int)$areas[0]['id'] : 0;
$ultimaAreaId = !empty($areas) ? (int)end($areas)['id'] : 0;

// Reúne todas las competencias del área para renderizar sus modales al final
$todasLasCompetencias = [];
if ($area) {
    if ($area['tipo'] === 'con_subareas') {
        foreach ($area['subareas'] as $sa) {
            foreach ($sa['competencias'] as $c) {
                $todasLasCompetencias[] = $c;
            }
        }
    } else {
        $todasLasCompetencias = $area['competencias'];
    }
}
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">&#8592; Dashboard</a>
    <div>
        <h1 class="page-title">Currículo Académico</h1>
        <p class="page-subtitle">Áreas, subáreas y competencias del MINEDU</p>
    </div>
</div>

<div class="curr-layout">

    <!-- ── Panel lateral ──────────────────────────────────────── -->
    <aside class="curr-sidebar">

        <div class="curr-sidebar__tabs">
            <?php foreach ($niveles as $n): ?>
            <a href="<?= e(url('admin/curriculum?nivel=' . $n['id'])) ?>"
               class="curr-sidebar__tab<?= $nivelActivo == $n['id'] ? ' curr-sidebar__tab--activo' : '' ?>">
                <?= e($n['nombre']) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <nav class="curr-sidebar__nav">
            <?php if (empty($areas)): ?>
            <p class="curr-sidebar__vacio">Sin áreas registradas.</p>
            <?php else: ?>
            <?php foreach ($areas as $a): ?>
            <div class="curr-sidebar__item-row<?= $areaId == $a['id'] ? ' curr-sidebar__item-row--activo' : '' ?><?= !$a['activa'] ? ' curr-sidebar__item-row--inactiva' : '' ?>">

                <a href="<?= e(url('admin/curriculum?nivel=' . $nivelActivo . '&area=' . $a['id'])) ?>"
                   class="curr-sidebar__item">
                    <span class="curr-sidebar__item-nombre"><?= e($a['nombre']) ?></span>
                    <span class="curr-tipo-badge curr-tipo-badge--<?= e($a['tipo']) ?>">
                        <?= e($tipoAbrev($a['tipo'])) ?>
                    </span>
                </a>

                <div class="curr-sidebar__item-mover">
                    <form method="POST"
                          action="<?= e(url('admin/curriculum/areas/' . $a['id'] . '/mover')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="nivel_id"  value="<?= $nivelActivo ?>">
                        <input type="hidden" name="area_id"   value="<?= $areaId ?>">
                        <input type="hidden" name="direccion" value="up">
                        <button type="submit" class="curr-mover-btn" title="Mover arriba"
                                <?= $a['id'] == $primerAreaId ? 'disabled' : '' ?>>&#8593;</button>
                    </form>
                    <form method="POST"
                          action="<?= e(url('admin/curriculum/areas/' . $a['id'] . '/mover')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="nivel_id"  value="<?= $nivelActivo ?>">
                        <input type="hidden" name="area_id"   value="<?= $areaId ?>">
                        <input type="hidden" name="direccion" value="down">
                        <button type="submit" class="curr-mover-btn" title="Mover abajo"
                                <?= $a['id'] == $ultimaAreaId ? 'disabled' : '' ?>>&#8595;</button>
                    </form>
                </div>

            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </nav>

    </aside>

    <!-- ── Panel de detalle ───────────────────────────────────── -->
    <section class="curr-detail">

        <?php if (!$area): ?>
        <p class="curr-detail__vacio">Selecciona un área del panel lateral.</p>

        <?php else: ?>

        <!-- Header del área -->
        <div class="curr-detail__header">
            <div class="curr-detail__header-info">
                <div class="curr-detail__header-top">
                    <h2 class="curr-detail__nombre"><?= e($area['nombre']) ?></h2>
                    <span class="curr-tipo-badge curr-tipo-badge--<?= e($area['tipo']) ?> curr-tipo-badge--md">
                        <?= e($tipoBadge($area['tipo'])) ?>
                    </span>
                    <?php if (!$area['activa']): ?>
                    <span class="curr-badge-inactiva">Inactiva</span>
                    <?php endif; ?>
                </div>
                <?php if ($area['nombre_boleta'] || $area['nombre_siagie'] || $area['codigo_siagie']): ?>
                <div class="curr-detail__meta">
                    <?php if ($area['nombre_boleta']): ?>
                    <span class="curr-meta-item">
                        <strong>Boleta:</strong>
                        <?= e($area['nombre_boleta']) ?>
                        <?php if ($area['alias_boleta']): ?>
                        <em><?= e($area['alias_boleta']) ?></em>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($area['nombre_siagie']): ?>
                    <span class="curr-meta-item">
                        <strong>SIAGIE:</strong> <?= e($area['nombre_siagie']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($area['codigo_siagie']): ?>
                    <span class="curr-meta-item">
                        <strong>Hoja:</strong> <code><?= e($area['codigo_siagie']) ?></code>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="curr-detail__header-actions">
                <button type="button" class="btn btn--secondary btn--sm"
                        onclick="Modal.abrir('modal-area-<?= $area['id'] ?>')">
                    Editar área
                </button>
                <form method="POST"
                      action="<?= e(url('admin/curriculum/areas/' . $area['id'] . '/toggle')) ?>"
                      class="curr-toggle-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="nivel_id" value="<?= (int)$area['nivel_id'] ?>">
                    <button type="submit"
                            class="btn btn--sm <?= $area['activa'] ? 'btn--danger' : 'btn--primary' ?>">
                        <?= $area['activa'] ? 'Desactivar' : 'Activar' ?>
                    </button>
                </form>
            </div>
        </div>

        <?php // ── Aprobación de la UGEL (solo talleres, migración 064) ─────
              // Por AÑO y GRADO. Decide si el taller cuenta para la situación
              // final (promoción) de los estudiantes de ese grado ese año. La
              // boleta y el orden de mérito lo incluyen siempre. ?>
        <?php if ($area['tipo'] === 'taller' && $anioSel): ?>
        <div class="curr-aprobacion">
            <div class="curr-aprobacion__cab">
                <h3 class="curr-aprobacion__titulo">Aprobación de la UGEL &mdash; plan de estudios <?= e($anioSel['anio']) ?></h3>
                <?php if (count($anios) > 1): ?>
                <form method="GET" action="<?= e(url('admin/curriculum')) ?>" class="curr-aprobacion__anio">
                    <input type="hidden" name="nivel" value="<?= (int) $area['nivel_id'] ?>">
                    <input type="hidden" name="area" value="<?= (int) $area['id'] ?>">
                    <label for="aprob-anio" class="form-label">Año</label>
                    <select id="aprob-anio" name="anio" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anios as $an): ?>
                        <option value="<?= (int) $an['id'] ?>" <?= (int) $an['id'] === (int) $anioSel['id'] ? 'selected' : '' ?>>
                            <?= e($an['anio']) ?> (<?= e($an['estado']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
            <p class="curr-aprobacion__nota">
                Marca los grados en los que la <strong>UGEL</strong> incluyó este taller en el plan de
                estudios <?= e($anioSel['anio']) ?>. Si un estudiante está en retorno de grado, cuenta el
                grado de su matrícula oficial.
                Solo en esos grados el taller <strong>cuenta para la situación final</strong>
                (promoción, recuperación o permanencia), como en el SIAGIE. Sin marcar, no cuenta.
                La boleta y el orden de mérito lo incluyen siempre. Cambiarlo recalcula los informes
                de riesgo de todo ese año, también los ya impresos.
            </p>
            <form method="POST" action="<?= e(url('admin/curriculum/areas/' . $area['id'] . '/aprobacion')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="nivel_id" value="<?= (int) $area['nivel_id'] ?>">
                <input type="hidden" name="anio_id" value="<?= (int) $anioSel['id'] ?>">
                <div class="curr-aprobacion__grados">
                    <?php foreach ($aprobacion as $g): ?>
                    <label class="curr-aprobacion__grado<?= (int) $g['secciones'] === 0 ? ' curr-aprobacion__grado--sin-carga' : '' ?>">
                        <input type="checkbox" name="grados[]" value="<?= (int) $g['grado_id'] ?>"
                               <?= (int) $g['aprobado'] === 1 ? 'checked' : '' ?>>
                        <?= e($g['grado']) ?>
                        <span class="curr-aprobacion__secc">
                            <?= (int) $g['secciones'] ?> secci<?= (int) $g['secciones'] === 1 ? 'ón' : 'ones' ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php // Resolución Directoral DEL COLEGIO que crea el taller: una por
                      // taller y año. Es el sustento; no decide si el taller cuenta. ?>
                <div class="curr-aprobacion__pie">
                    <span class="form-label">Resolución Directoral del colegio que crea el taller (opcional)</span>
                    <input type="text" id="rd-numero" name="rd_numero" class="form-input" maxlength="60"
                           placeholder="N.º de la RD" aria-label="Número de la Resolución Directoral"
                           value="<?= e($resolucion['numero'] ?? '') ?>">
                    <input type="date" id="rd-fecha" name="rd_fecha" class="form-input curr-aprobacion__fecha"
                           aria-label="Fecha de la Resolución Directoral"
                           value="<?= e($resolucion['fecha'] ?? '') ?>">
                    <button type="submit" class="btn btn--primary btn--sm">Guardar</button>
                </div>
                <?php $ultimo = null;
                      foreach ($aprobacion as $g) {
                          if ($g['actualizado_en'] !== null && ($ultimo === null || $g['actualizado_en'] > $ultimo['actualizado_en'])) { $ultimo = $g; }
                      } ?>
                <?php if ($ultimo): ?>
                <p class="curr-aprobacion__audit">
                    Último cambio: <?= e($ultimo['actualizado_por']) ?> &middot; <?= e(date('d/m/Y H:i', strtotime($ultimo['actualizado_en']))) ?>
                </p>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- Contenido: área con subáreas -->
        <?php if ($area['tipo'] === 'con_subareas'): ?>

            <?php if (empty($area['subareas'])): ?>
            <p class="curr-detail__vacio">Sin subáreas registradas para esta área.</p>
            <?php endif; ?>

            <?php foreach ($area['subareas'] as $sa): ?>
            <div class="curr-subarea">
                <div class="curr-subarea__header">
                    <div class="curr-subarea__titulo">
                        <span class="curr-subarea__nombre"><?= e($sa['nombre']) ?></span>
                        <span class="curr-subarea__orden">orden <?= (int)$sa['orden'] ?></span>
                    </div>
                    <button type="button" class="btn btn--secondary btn--sm"
                            onclick="Modal.abrir('modal-subarea-<?= $sa['id'] ?>')">
                        Editar
                    </button>
                </div>

                <?php foreach ($sa['competencias'] as $c): ?>
                <div class="curr-competencia">
                    <?php if ($c['codigo_minedu']): ?>
                    <span class="curr-competencia__codigo"><?= e($c['codigo_minedu']) ?></span>
                    <?php endif; ?>
                    <div class="curr-competencia__info">
                        <span class="curr-competencia__nombre-completo"><?= e($c['nombre_completo']) ?></span>
                        <?php if ($c['nombre_corto']): ?>
                        <span class="curr-competencia__nombre-corto"><?= e($c['nombre_corto']) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn--secondary btn--sm"
                            onclick="Modal.abrir('modal-comp-<?= $c['id'] ?>')">
                        Editar
                    </button>
                </div>
                <?php endforeach; ?>

                <?php if (empty($sa['competencias'])): ?>
                <p class="curr-subarea__sin-comp">Sin competencias registradas.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        <?php else: ?>
        <!-- Contenido: área-curso o transversal — competencias directas -->

            <?php if (empty($area['competencias'])): ?>
            <p class="curr-detail__vacio">Sin competencias registradas para esta área.</p>
            <?php endif; ?>

            <div class="curr-competencias-lista">
                <?php foreach ($area['competencias'] as $c): ?>
                <div class="curr-competencia">
                    <?php if ($c['codigo_minedu']): ?>
                    <span class="curr-competencia__codigo"><?= e($c['codigo_minedu']) ?></span>
                    <?php endif; ?>
                    <div class="curr-competencia__info">
                        <span class="curr-competencia__nombre-completo"><?= e($c['nombre_completo']) ?></span>
                        <?php if ($c['nombre_corto']): ?>
                        <span class="curr-competencia__nombre-corto"><?= e($c['nombre_corto']) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn--secondary btn--sm"
                            onclick="Modal.abrir('modal-comp-<?= $c['id'] ?>')">
                        Editar
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
        <?php endif; // end if $area ?>

    </section>
</div>

<?php if (!$area) return; ?>

<!-- ══════════════════════════════════════════════════════════════
     Modales — solo se renderizan para el área actualmente visible
     ══════════════════════════════════════════════════════════════ -->

<!-- Modal: Editar área -->
<div id="modal-area-<?= $area['id'] ?>" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Editar área</h3>
            <button type="button" class="modal-cerrar"
                    data-modal-cerrar="modal-area-<?= $area['id'] ?>">&#215;</button>
        </div>
        <form method="POST"
              action="<?= e(url('admin/curriculum/areas/' . $area['id'] . '/editar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="nivel_id" value="<?= (int)$area['nivel_id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="area-nombre">Nombre</label>
                    <input type="text" id="area-nombre" name="nombre" class="form-input"
                           value="<?= e($area['nombre']) ?>" required maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label" for="area-nombre-boleta">Nombre en boleta</label>
                    <input type="text" id="area-nombre-boleta" name="nombre_boleta"
                           class="form-input" maxlength="120"
                           value="<?= e($area['nombre_boleta'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="area-alias-boleta">Alias en boleta</label>
                    <input type="text" id="area-alias-boleta" name="alias_boleta"
                           class="form-input" maxlength="80"
                           placeholder="Ej: (Ética y Valores)"
                           value="<?= e($area['alias_boleta'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="area-nombre-siagie">Nombre SIAGIE</label>
                    <input type="text" id="area-nombre-siagie" name="nombre_siagie"
                           class="form-input" maxlength="120"
                           value="<?= e($area['nombre_siagie'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="area-codigo-siagie">Codigo de hoja SIAGIE</label>
                    <input type="text" id="area-codigo-siagie" name="codigo_siagie"
                           class="form-input" maxlength="40"
                           placeholder="Ej: 063 (o 0006,0007)"
                           pattern="\s*\d{2,4}(\s*,\s*\d{2,4})*\s*"
                           value="<?= e($area['codigo_siagie'] ?? '') ?>">
                    <p class="form-hint">
                        Prefijo del nombre de la hoja en el archivo del SIAGIE
                        (<code>063-MATE</code> &rarr; <code>063</code>). Sin codigo, el area
                        <strong>no se vuelca</strong> al acta. Dos areas del mismo nivel no
                        pueden compartir codigo.
                    </p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="area-orden">Orden</label>
                    <input type="number" id="area-orden" name="orden"
                           class="form-input curr-input-orden"
                           value="<?= (int)$area['orden'] ?>" min="0" max="99">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary"
                        data-modal-cerrar="modal-area-<?= $area['id'] ?>">
                    <span class="btn-icon btn-icon--back" aria-hidden="true"></span>
                    Cancelar
                </button>
                <button type="submit" class="btn btn--primary">
                    <span class="btn-icon btn-icon--save" aria-hidden="true"></span>
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modales: subáreas -->
<?php foreach ($area['subareas'] as $sa): ?>
<div id="modal-subarea-<?= $sa['id'] ?>" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Editar subárea</h3>
            <button type="button" class="modal-cerrar"
                    data-modal-cerrar="modal-subarea-<?= $sa['id'] ?>">&#215;</button>
        </div>
        <form method="POST"
              action="<?= e(url('admin/curriculum/subareas/' . $sa['id'] . '/editar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="area_id"  value="<?= (int)$area['id'] ?>">
            <input type="hidden" name="nivel_id" value="<?= (int)$area['nivel_id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="sa-nombre-<?= $sa['id'] ?>">Nombre</label>
                    <input type="text" id="sa-nombre-<?= $sa['id'] ?>" name="nombre"
                           class="form-input" value="<?= e($sa['nombre']) ?>"
                           required maxlength="80">
                </div>
                <div class="form-group">
                    <label class="form-label" for="sa-orden-<?= $sa['id'] ?>">Orden</label>
                    <input type="number" id="sa-orden-<?= $sa['id'] ?>" name="orden"
                           class="form-input curr-input-orden"
                           value="<?= (int)$sa['orden'] ?>" min="0" max="99">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary"
                        data-modal-cerrar="modal-subarea-<?= $sa['id'] ?>">
                    <span class="btn-icon btn-icon--back" aria-hidden="true"></span>
                    Cancelar
                </button>
                <button type="submit" class="btn btn--primary">
                    <span class="btn-icon btn-icon--save" aria-hidden="true"></span>
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- Modales: competencias -->
<?php foreach ($todasLasCompetencias as $c): ?>
<div id="modal-comp-<?= $c['id'] ?>" class="modal-overlay" hidden>
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Editar competencia</h3>
            <button type="button" class="modal-cerrar"
                    data-modal-cerrar="modal-comp-<?= $c['id'] ?>">&#215;</button>
        </div>
        <form method="POST"
              action="<?= e(url('admin/curriculum/competencias/' . $c['id'] . '/editar')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="area_id"  value="<?= (int)$area['id'] ?>">
            <input type="hidden" name="nivel_id" value="<?= (int)$area['nivel_id'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="c-codigo-<?= $c['id'] ?>">Código MINEDU</label>
                    <input type="text" id="c-codigo-<?= $c['id'] ?>" name="codigo_minedu"
                           class="form-input curr-input-codigo"
                           value="<?= e($c['codigo_minedu'] ?? '') ?>" maxlength="5"
                           placeholder="Ej: C14">
                </div>
                <div class="form-group">
                    <label class="form-label" for="c-nombre-<?= $c['id'] ?>">Nombre completo</label>
                    <textarea id="c-nombre-<?= $c['id'] ?>" name="nombre_completo"
                              class="form-input" rows="3" required><?= e($c['nombre_completo']) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="c-corto-<?= $c['id'] ?>">Nombre corto</label>
                    <input type="text" id="c-corto-<?= $c['id'] ?>" name="nombre_corto"
                           class="form-input" value="<?= e($c['nombre_corto'] ?? '') ?>"
                           maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label" for="c-orden-<?= $c['id'] ?>">Orden</label>
                    <input type="number" id="c-orden-<?= $c['id'] ?>" name="orden"
                           class="form-input curr-input-orden"
                           value="<?= (int)$c['orden'] ?>" min="0" max="99">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary"
                        data-modal-cerrar="modal-comp-<?= $c['id'] ?>">
                    <span class="btn-icon btn-icon--back" aria-hidden="true"></span>
                    Cancelar
                </button>
                <button type="submit" class="btn btn--primary">
                    <span class="btn-icon btn-icon--save" aria-hidden="true"></span>
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
