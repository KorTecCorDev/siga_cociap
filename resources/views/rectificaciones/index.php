<?php
/**
 * Rectificación de calificaciones — buscador de estudiante + historial.
 * @var array $historial  últimas rectificaciones (auditoría)
 */
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Rectificación de calificaciones</h1>
        <p class="page-subtitle">
            Corrige, notas ya aprobadas o bloqueadas.
            Busca al estudiante para empezar.
        </p>
    </div>
    <a href="<?= url('consulta-notas') ?>" class="btn btn--secondary btn--sm">Consultar notas (lectura)</a>
</div>

<div class="card">
    <div class="card__body">
        <div class="buscador">
            <label class="form-label" for="buscadorInput">Buscar estudiante</label>
            <div class="buscador__campo">
                <svg class="buscador__icono" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M20 20l-3.2-3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input type="search"
                       id="buscadorInput"
                       class="form-input buscador__input"
                       placeholder="Escriba el DNI o los apellidos y nombres…"
                       autocomplete="off"
                       autofocus>
                <button type="button" class="buscador__limpiar" id="buscadorLimpiar" aria-label="Limpiar búsqueda" hidden>✕</button>
                <span class="buscador__spinner" id="buscadorSpinner" hidden></span>
            </div>
            <p class="buscador__hint text-sm text-muted">
                <span class="buscador__hint-icono" aria-hidden="true">💡</span>
                Escriba al menos 2 caracteres. Al elegir un estudiante verá sus
                competencias rectificables (cerradas o bloqueadas).
            </p>
        </div>
    </div>
</div>

<!-- data-target-base redirige las tarjetas del buscador hacia este módulo.
     data-retornos="separar": en un retorno de grado cada matrícula guarda notas
     distintas (Regla A), así que salen las dos tarjetas, marcadas. -->
<div id="buscadorResultados" class="buscador-resultados" aria-live="polite"
     data-target-base="/rectificaciones/matricula/"
     data-retornos="separar"></div>

<?php
// ── Estudiantes SIN NINGUNA nota en un bimestre cerrado (21/09/2026) ──
// RectificacionModel::matriculasSinNotasEnCerrados. Desde el 22/09/2026 entra
// también quien tiene sus notas pero le falta la conducta o la asistencia
// (migración 063). El «Calificar (N)» cuenta exactamente las filas del lote. Filtros por GET, sin JS: si se
// eligen varios ámbitos, manda el más específico (sección > grado > nivel).
$f = $filtros ?? ['periodo_id' => 0, 'nivel_id' => 0, 'grado_id' => 0, 'seccion_id' => 0];
$niveles = [];
$grados  = [];
foreach ($opciones['secciones'] ?? [] as $s) {
    $niveles[(int) $s['nivel_id']] = $s['nivel'];
    $grados[(int) $s['nivel_id']][(int) $s['grado_id']] = $s['grado'];
}
$hayFiltro = array_filter($f) !== [];
?>
<div class="card mt-md">
    <div class="card__body">
        <p class="form-section-title">Estudiantes sin calificación en bimestres cerrados</p>
        <p class="text-sm text-muted">
            Estudiantes que no tienen <strong>ninguna</strong> calificación en un bimestre
            ya cerrado (por ejemplo, porque llegaron tarde), o a quienes les falta la
            conducta o la asistencia. Se completan con la calificación extraordinaria.
        </p>

        <form method="GET" action="<?= url('rectificaciones') ?>" class="rect-filtros mb-md">
            <div class="form-group">
                <label class="form-label" for="fPeriodo">Bimestre</label>
                <select id="fPeriodo" name="periodo_id" class="form-select">
                    <option value="0">Todos los cerrados</option>
                    <?php foreach ($opciones['periodos'] ?? [] as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $f['periodo_id'] ? ' selected' : '' ?>>
                            <?= e($p['nombre_display']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="fNivel">Nivel</label>
                <select id="fNivel" name="nivel_id" class="form-select">
                    <option value="0">Todos</option>
                    <?php foreach ($niveles as $nid => $nNombre): ?>
                        <option value="<?= $nid ?>"<?= $nid === $f['nivel_id'] ? ' selected' : '' ?>><?= e($nNombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="fGrado">Grado</label>
                <select id="fGrado" name="grado_id" class="form-select">
                    <option value="0">Todos</option>
                    <?php foreach ($grados as $nid => $gs): ?>
                        <optgroup label="<?= e($niveles[$nid]) ?>">
                            <?php foreach ($gs as $gid => $gNombre): ?>
                                <option value="<?= $gid ?>"<?= $gid === $f['grado_id'] ? ' selected' : '' ?>><?= e($gNombre) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="fSeccion">Sección</label>
                <select id="fSeccion" name="seccion_id" class="form-select">
                    <option value="0">Todas</option>
                    <?php $grupo = null; foreach ($opciones['secciones'] ?? [] as $s):
                        $rotulo = $s['nivel'] . ' — ' . $s['grado'];
                        if ($rotulo !== $grupo): ?>
                            <?= $grupo !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($rotulo) ?>">
                        <?php $grupo = $rotulo; endif; ?>
                        <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $f['seccion_id'] ? ' selected' : '' ?>>
                            <?= e($s['grado'] . ' "' . $s['nombre'] . '"') ?>
                        </option>
                    <?php endforeach; ?>
                    <?= $grupo !== null ? '</optgroup>' : '' ?>
                </select>
            </div>

            <div class="rect-filtros__acciones">
                <button type="submit" class="btn btn--primary btn--sm">Filtrar</button>
                <?php if ($hayFiltro): ?>
                    <a href="<?= url('rectificaciones') ?>" class="btn btn--secondary btn--sm">Quitar filtros</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($pendientes)): ?>
            <div class="empty-state">
                <p><?= $hayFiltro
                    ? 'Ningún estudiante con registros pendientes para este filtro.'
                    : 'Todos los estudiantes tienen calificaciones, conducta y asistencia en los bimestres cerrados.' ?></p>
            </div>
        <?php else: ?>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Estudiante</th>
                            <th>Sección</th>
                            <th>Bimestre</th>
                            <th>Falta</th>
                            <th class="text-center">Por calificar</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $pe): ?>
                        <tr>
                            <td class="text-sm"><?= e($pe['nombre']) ?></td>
                            <td class="text-sm"><?= e($pe['nivel'] . ' — ' . $pe['grado'] . ' "' . $pe['seccion'] . '"') ?></td>
                            <td class="text-sm"><?= e($pe['periodo_nombre']) ?></td>
                            <td class="text-sm">
                                <?= e(implode(', ', array_filter([
                                    ((int) ($pe['competencias'] ?? 0) > 0 || !empty($pe['sin_notas'])) ? 'Notas' : '',
                                    !empty($pe['falta_conducta']) ? 'Conducta' : '',
                                    !empty($pe['falta_asistencia']) ? 'Asistencia' : '',
                                ]))) ?>
                            </td>
                            <td class="text-center"><strong><?= (int) $pe['total'] ?></strong></td>
                            <td>
                                <div class="btn-group">
                                    <?php // total 0: no hay carga activa ni competencia que admita la
                                          // extraordinaria; el lote se abriría vacío. ?>
                                    <?php if ((int) $pe['total'] > 0): ?>
                                    <a href="<?= url('rectificaciones/extraordinaria/lote?matricula=' . (int) $pe['matricula_id'] . '&periodo=' . (int) $pe['periodo_id']) ?>"
                                       class="btn btn--primary btn--sm">Calificar (<?= (int) $pe['total'] ?>)</a>
                                    <?php endif; ?>
                                    <a href="<?= url('rectificaciones/matricula/' . (int) $pe['matricula_id']) ?>"
                                       class="btn btn--secondary btn--sm">Ver detalle</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-muted">
                <?= count($pendientes) ?> fila(s) —
                <?= count(array_unique(array_column($pendientes, 'matricula_id'))) ?> estudiante(s).
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($historial)): ?>
<div class="card mt-md">
    <div class="card__body">
        <p class="form-section-title">Rectificaciones recientes</p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Estudiante</th>
                        <th>Competencia</th>
                        <th>Bimestre</th>
                        <th class="text-center">Antes</th>
                        <th class="text-center">Después</th>
                        <th>Motivo</th>
                        <th>Rectificó</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $h): ?>
                    <tr>
                        <td class="text-sm"><?= e(fecha_es(substr((string) $h['rectificado_en'], 0, 10))) ?></td>
                        <td class="text-sm"><?= e($h['estudiante']) ?></td>
                        <td class="text-sm">
                            <?= e($h['competencia_nombre'] ?? '—') ?>
                            <?php if (($h['tipo'] ?? 'rectificacion') === 'extraordinaria'): ?>
                                <span class="rect-chip rect-chip--extraordinaria">Extraordinaria</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($h['periodo_nombre'] ?? '—') ?></td>
                        <td class="text-center"><?= fmt_nota($h['nota_anterior'] !== null ? (int) $h['nota_anterior'] : null) ?></td>
                        <td class="text-center"><strong><?= fmt_nota($h['nota_nueva'] !== null ? (int) $h['nota_nueva'] : null) ?></strong></td>
                        <td class="text-sm rect-motivo"><?= e($h['motivo']) ?></td>
                        <td class="text-sm"><?= e($h['rectificador'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
