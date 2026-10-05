<?php
/**
 * @var array $matriculas
 * @var array $filtros
 * @var array $anios
 * @var array $grados
 * @var array $secciones
 * @var int    $total
 * @var int    $pagina
 * @var int    $total_pags
 * @var string $orden  columna activa de orden (clave de MatriculaModel::ORDENABLES)
 * @var string $dir    'asc' | 'desc'
 */

// Etiquetas legibles de estado.
$labelEstado = fn(string $e): string => match($e) {
    'pendiente'   => 'Pendiente',
    'aprobada'    => 'Aprobado',
    'desactivado' => 'Desactivado',
    default       => ucfirst($e),
};
$labelTipo = fn(string $t): string => match($t) {
    'continuador' => 'Continuador',
    'nuevo'       => 'Nuevo',
    'trasladado'  => 'Trasladado',
    default       => ucfirst($t),
};

// Filtros activos (sin paginación ni orden) reutilizados al paginar y al ordenar.
$baseFiltros = array_filter([
    'anio_id'    => $filtros['anio_id']    ?? null,
    'grado_id'   => $filtros['grado_id']   ?? null,
    'seccion_id' => $filtros['seccion_id'] ?? null,
    'estado'     => $filtros['estado']     ?? null,
    'tipo'       => $filtros['tipo']       ?? null,
    'search'     => $filtros['search']     ?? null,
]);

// Conserva filtros + orden actual al paginar.
$qs = fn(int $p): string => http_build_query(
    $baseFiltros + ['orden' => $orden, 'dir' => $dir, 'pagina' => $p]
);

// URL para ordenar por una columna: si ya es la activa, alterna dirección; si no,
// arranca en asc. Resetea a la página 1 (el dataset se reordena completo).
$ordenUrl = function (string $key) use ($baseFiltros, $orden, $dir): string {
    $nuevaDir = ($orden === $key && $dir === 'asc') ? 'desc' : 'asc';
    return url('matriculas') . '?' . http_build_query(
        $baseFiltros + ['orden' => $key, 'dir' => $nuevaDir]
    );
};

// Encabezado ordenable: enlace con flecha en la columna activa.
$thOrden = function (string $key, string $label, string $thClass = '') use ($ordenUrl, $orden, $dir): void {
    $activo = $orden === $key;
    $flecha = $activo ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $cls    = 'th-orden' . ($activo ? ' th-orden--activo' : '');
    echo '<th' . ($thClass ? ' class="' . $thClass . '"' : '') . '>'
        . '<a class="' . $cls . '" href="' . e($ordenUrl($key)) . '">'
        . e($label) . $flecha . '</a></th>';
};
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Matrículas</h1>
        <p class="page-subtitle"><?= (int) $total ?> matrícula<?= $total !== 1 ? 's' : '' ?> encontrada<?= $total !== 1 ? 's' : '' ?></p>
    </div>
    <div class="btn-group">
        <?php // "Resumen" es lectura: lo ve todo el que entra a la grilla, directores incluidos. ?>
        <a href="<?= url('matriculas/resumen') ?>" class="btn btn--secondary">Resumen</a>
        <?php // Estos DOS exigen admin/RA en su destino, asi que no caben bajo
              // $puedeMatricular: las secretarias matriculan pero aqui recibian 403,
              // igual que los directores. Ver el comentario del controlador. ?>
        <?php if ($puedeGestionar): ?>
            <a href="<?= url('matriculas/nomina/imprimir' . (!empty($baseFiltros) ? '?' . http_build_query($baseFiltros) : '')) ?>"
               class="btn btn--secondary" target="_blank" rel="noopener">Nómina detallada</a>
            <a href="<?= url('traslados') ?>" class="btn btn--secondary">Traslados</a>
        <?php endif; ?>
        <?php if ($puedeMatricular): ?>
        <a href="<?= url('matriculas/crear') ?>" class="btn btn--primary">+ Nueva matrícula</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filtros ─────────────────────────────────────────── -->
<div class="card mb-md">
    <div class="card__body">
        <form method="GET" action="<?= url('matriculas') ?>">
            <input type="hidden" name="orden" value="<?= e($orden) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="mat-filtros">
                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="anio_id">Año académico</label>
                    <select id="anio_id" name="anio_id" class="form-input">
                        <?php foreach ($anios as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (int) ($filtros['anio_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                <?= e((string) $a['anio']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="grado_id">Grado</label>
                    <select id="grado_id" name="grado_id" class="form-input">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= (int) ($filtros['grado_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                                <?= e($g['nivel_nombre'] . ' — ' . $g['nombre_display']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="seccion_id">Sección</label>
                    <select id="seccion_id" name="seccion_id" class="form-input">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int) ($filtros['seccion_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['grado_nombre'] . ' ' . $s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="estado">Estado</label>
                    <select id="estado" name="estado" class="form-input">
                        <option value="">Todos</option>
                        <?php foreach (['pendiente' => 'Pendiente', 'aprobada' => 'Aprobado', 'desactivado' => 'Desactivado'] as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= ($filtros['estado'] ?? '') === $val ? 'selected' : '' ?>><?= $lab ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="tipo">Tipo</label>
                    <select id="tipo" name="tipo" class="form-input">
                        <option value="">Todos</option>
                        <?php foreach (['continuador' => 'Continuador', 'nuevo' => 'Nuevo', 'trasladado' => 'Trasladado'] as $val => $lab): ?>
                            <option value="<?= $val ?>" <?= ($filtros['tipo'] ?? '') === $val ? 'selected' : '' ?>><?= $lab ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mat-filtros__campo">
                    <label class="mat-filtros__label" for="search">DNI o nombre</label>
                    <input type="text" id="search" name="search" class="form-input"
                           value="<?= e((string) ($filtros['search'] ?? '')) ?>" placeholder="Buscar...">
                </div>

                <div class="mat-filtros__acciones">
                    <button type="submit" class="btn btn--primary btn--sm">Filtrar</button>
                    <a href="<?= url('matriculas') ?>" class="btn btn--secondary btn--sm">Limpiar</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (empty($matriculas)): ?>
    <div class="card"><div class="card__body"><div class="empty-state">
        <p>No hay matrículas que coincidan con los filtros.</p>
    </div></div></div>
<?php else: ?>

<div class="tabla-toolbar">
    <span class="tabla-toolbar__label">Ordenar:</span>
    <a class="orden-chip<?= $orden === 'modificacion' ? ' orden-chip--activo' : '' ?>"
       href="<?= e($ordenUrl('modificacion')) ?>">
        Modificación reciente
        <?php if ($orden === 'modificacion'): ?><span aria-hidden="true"><?= $dir === 'asc' ? '▲' : '▼' ?></span><?php endif; ?>
    </a>
    <a class="orden-chip<?= $orden === 'estudiante' ? ' orden-chip--activo' : '' ?>"
       href="<?= e($ordenUrl('estudiante')) ?>">
        Alfabéticamente
        <?php if ($orden === 'estudiante'): ?><span aria-hidden="true"><?= $dir === 'asc' ? 'A–Z' : 'Z–A' ?></span><?php endif; ?>
    </a>
</div>

<div class="card">
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas">
            <thead>
                <tr>
                    <th>N°</th>
                    <?php $thOrden('estudiante', 'Estudiante'); ?>
                    <?php $thOrden('dni', 'DNI'); ?>
                    <?php $thOrden('grado', 'Grado / Sección'); ?>
                    <th>Tipo</th>
                    <?php $thOrden('estado', 'Estado', 'text-center'); ?>
                    <th>Apoderado responsable</th>
                    <?php $thOrden('registro', 'Registro'); ?>
                    <th class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = ($pagina - 1) * 25; foreach ($matriculas as $m): $i++;
                    // Rol dentro de un retorno de grado (ver MatriculaModel::listar).
                    // La operativa —activa o REVERTIDA— es solo informativa; la
                    // oficial lleva su marca mientras el retorno está activo.
                    $retornoActivo      = ($m['retorno_estado'] ?? null) === 'activo';
                    $esOficialRetorno   = ($m['retorno_rol'] ?? null) === 'oficial' && $retornoActivo;
                    $esOperativaRetorno = ($m['retorno_rol'] ?? null) === 'operativa';
                ?>
                <tr class="<?= $esOperativaRetorno ? 'mat-fila-operativa' : '' ?>">
                    <td class="text-sm"><?= $i ?></td>
                    <td><?= e($m['nombre_completo']) ?></td>
                    <td class="text-sm"><?= e($m['dni']) ?><?php if (es_dni_provisional($m['dni'])): ?> <span class="badge-provisional" title="DNI provisional — pendiente de regularizar">prov.</span><?php endif; ?></td>
                    <td class="text-sm">
                        <?= e(($m['grado_nombre'] ?? '—') . ' ' . ($m['seccion_nombre'] ?? '')) ?>
                    </td>
                    <td>
                        <span class="matricula-badge matricula-badge--<?= e($m['tipo']) ?>"><?= $labelTipo($m['tipo']) ?></span>
                        <?php if ($esOficialRetorno): ?>
                        <span class="matricula-badge matricula-badge--oficial" title="Matrícula oficial de SIAGIE: la boleta usa este grado/sección.">Oficial</span>
                        <?php elseif ($esOperativaRetorno): ?>
                        <?php if ($retornoActivo): ?>
                        <span class="matricula-badge matricula-badge--operativa" title="Matrícula operativa de un retorno: el estudiante cursa aquí. Solo informativa.">Operativa</span>
                        <?php else: ?>
                        <span class="matricula-badge matricula-badge--operativa" title="Matrícula operativa de un retorno ya revertido: conserva los bimestres que cursó aquí. No es una baja. Solo informativa.">Operativa · revertido</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="matricula-badge matricula-badge--<?= e($m['estado']) ?>"><?= $labelEstado($m['estado']) ?></span>
                        <?php if (!empty($m['motivo_estado'])): ?>
                        <span class="matricula-motivo"><?= e($m['motivo_estado']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= e($m['apoderado_responsable'] ?? '—') ?></td>
                    <td class="text-sm text-muted"><?= $m['fecha_registro'] ? fecha_es($m['fecha_registro']) : '—' ?></td>
                    <td class="text-right">
                        <?php if ($esOperativaRetorno): ?>
                            <span class="mat-info-operativa">Solo informativa</span>
                            <a href="<?= url('matriculas/' . (int) $m['retorno_oficial']) ?>" class="btn btn--secondary btn--sm">Ver oficial →</a>
                        <?php else: ?>
                            <a href="<?= url('matriculas/' . $m['id']) ?>" class="btn btn--secondary btn--sm">Ver</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pags > 1): ?>
<div class="mat-paginacion">
    <?php if ($pagina > 1): ?>
        <a href="<?= url('matriculas?' . $qs($pagina - 1)) ?>" class="btn btn--secondary btn--sm">← Anterior</a>
    <?php endif; ?>
    <span class="mat-paginacion__info">Página <?= $pagina ?> de <?= $total_pags ?></span>
    <?php if ($pagina < $total_pags): ?>
        <a href="<?= url('matriculas?' . $qs($pagina + 1)) ?>" class="btn btn--secondary btn--sm">Siguiente →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
