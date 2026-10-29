<?php
/**
 * Vista: dashboard de estadísticas de matrícula.
 *
 * @var array      $resumen  ['kpis'=>..., 'por_grado'=>[...], 'por_tipo'=>[...], 'por_genero'=>...]
 * @var array      $anios    [{id, anio, ...}]
 * @var int        $anioId
 * @var array|null $anioSel
 */
$kpis       = $resumen['kpis']        ?? [];
$porGrado   = $resumen['por_grado']   ?? [];
$porSeccion = $resumen['por_seccion'] ?? [];
$porTipo    = $resumen['por_tipo']    ?? [];
$genero   = $resumen['por_genero'] ?? ['m' => 0, 'f' => 0, 'sin_dato' => 0, 'cobertura' => 0];

// Se mide por ESTUDIANTES, no por aprobadas: un año recién abierto puede tener
// todo en `pendiente` y aun así hay algo que contar. Con `aprobadas` esa pantalla
// salía vacía diciendo que no hay estudiantes, que era falso.
$hayDatos = !empty($kpis) && ($kpis['estudiantes'] ?? 0) > 0;

$labelTipo = fn(string $t): string => match ($t) {
    'continuador' => 'Continuador',
    'nuevo'       => 'Nuevo',
    'trasladado'  => 'Trasladado',
    'retirado'    => 'Retirado',
    default       => ucfirst($t),
};

// 🔴 LOS CUATRO valores del enum `matriculas.tipo`, en orden canónico -> color
// estable. `retirado` FALTABA desde la migración 045 y este gráfico lo descartaba
// EN SILENCIO: el pie no sumaba el total de matriculados y nadie lo notaba,
// porque un pie no promete cuadrar. Mismo defecto que tenía el cuadro de abajo.
// ⚠️ `$tipoOrden` y `$tipoColor` se tocan SIEMPRE JUNTOS: un tipo en el orden sin
// su color revienta con un "undefined array key" al armar $tipoColors.
$tipoOrden = ['continuador', 'nuevo', 'trasladado', 'retirado'];
$tipoMap   = [];
foreach ($porTipo as $t) {
    $tipoMap[$t['tipo']] = $t['n'];
}
$tipoColor  = [
    'continuador' => '#1e6fa8',
    'nuevo'       => '#0d9488',
    // Trasladado y retirado: el color del TEXTO de su chip de situación
    // (`.matricula-badge--trasladado` / `--retirado`, _matriculas.scss;
    // 28/10/2026). Si cambia allí, cambia aquí.
    'trasladado'  => '#64748b',
    'retirado'    => '#b91c4b',
];
$tipoLabels = [];
$tipoValues = [];
$tipoColors = [];
foreach ($tipoOrden as $t) {
    if (!empty($tipoMap[$t])) {
        $tipoLabels[] = $labelTipo($t);
        $tipoValues[] = $tipoMap[$t];
        $tipoColors[] = $tipoColor[$t];
    }
}

// Etiqueta corta de grado para el eje (ej: "1° Prim").
$labelGrado = static function (array $g): string {
    return $g['grado_numero'] . '° ' . ucfirst((string) ($g['nivel_codigo'] ?: $g['nivel_nombre']));
};

// Etiqueta corta de sección (ej: "1°A P" — inicial del nivel para distinguir
// 1°A Primaria de 1°A Secundaria).
$labelSeccion = static function (array $s): string {
    $ini = mb_strtoupper(mb_substr((string) $s['nivel_codigo'], 0, 1));
    return $s['grado_numero'] . '°' . $s['seccion_nombre'] . ' ' . $ini;
};

// Estructura de datos que consume Frappe Charts (sin JS inline: JSON en un tag).
$chartData = [
    'porGrado' => [
        'labels' => array_map($labelGrado, $porGrado),
        'values' => array_map(static fn($g) => $g['n'], $porGrado),
    ],
    'porSeccion' => [
        'labels' => array_map($labelSeccion, $porSeccion),
        'values' => array_map(static fn($s) => $s['n'], $porSeccion),
    ],
    'generoSeccion' => [
        'labels'  => array_map($labelSeccion, $porSeccion),
        'm'       => array_map(static fn($s) => $s['m'], $porSeccion),
        'f'       => array_map(static fn($s) => $s['f'], $porSeccion),
        'sinDato' => array_map(static fn($s) => $s['sin_dato'], $porSeccion),
    ],
    'porTipo' => [
        'labels' => $tipoLabels,
        'values' => $tipoValues,
        'colors' => $tipoColors,
    ],
    'genero' => [
        'labels' => ['Masculino', 'Femenino', 'Sin dato'],
        'values' => [(int) $genero['m'], (int) $genero['f'], (int) $genero['sin_dato']],
        'colors' => ['#1e6fa8', '#0d9488', '#9ca3af'],
    ],
];
?>

<?php // Orden canonico del page-header (ver docs/modulos/ui.md): back-link ·
      // <div> SIN CLASE con titulo y subtitulo · acciones. El back-link iba DENTRO
      // del div, asi que no era hijo directo del header y el selector de año no
      // se empujaba a la derecha como en las otras 79 vistas. ?>
<div class="page-header">
    <a href="<?= url('matriculas') ?>" class="btn btn--secondary btn--sm">← Volver a matrículas</a>
    <div>
        <h1 class="page-title">Resumen de matrículas</h1>
        <p class="page-subtitle">
            <?= $anioSel ? 'Año académico ' . e((string) $anioSel['anio']) : 'Sin año seleccionado' ?>
        </p>
    </div>
    <?php if (!empty($anios)): ?>
        <form method="GET" action="<?= url('matriculas/resumen') ?>" class="control-selector">
            <label for="anio_id" class="form-label">Año</label>
            <select name="anio_id" id="anio_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($anios as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $anioId ? 'selected' : '' ?>>
                        <?= e((string) $a['anio']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (!$hayDatos): ?>
    <div class="empty-state">
        <p>No hay estudiantes en este año para generar estadísticas.</p>
    </div>
<?php else: ?>

    <?php
    // ── KPIs ────────────────────────────────────────────────────────────
    // Marcado del COMPONENTE `.stats-comp__kpi`, no de un `.resumen-kpi` propio:
    // era una de CINCO copias de la misma tarjeta de cifra en el proyecto, y esta
    // es la unica que es componente global (ya importado en app.scss). Aqui se usa
    // sin el envoltorio `.stats-comp`, que solo aporta padding y borde inferior.
    //
    // El color va en el NUMERO (`__n--ok/--err/--muted`), no en un borde lateral:
    // es la convencion del componente.
    $fueraDelConteo = (int) ($kpis['trasladados'] ?? 0) + (int) ($kpis['retirados'] ?? 0);
    ?>
    <div class="stats-comp__kpis mb-lg">
        <div class="stats-comp__kpi">
            <span class="stats-comp__n stats-comp__n--ok"><?= (int) $kpis['estudiantes'] ?></span>
            <span class="stats-comp__t">Estudiantes</span>
            <span class="stats-comp__kpi-pct">todos los estados</span>
        </div>
        <div class="stats-comp__kpi">
            <span class="stats-comp__n"><?= (int) $kpis['secciones'] ?></span>
            <span class="stats-comp__t">Secciones</span>
        </div>
        <div class="stats-comp__kpi">
            <span class="stats-comp__n"><?= (int) $kpis['promedio_seccion'] ?></span>
            <span class="stats-comp__t">Promedio por sección</span>
        </div>
        <div class="stats-comp__kpi">
            <span class="stats-comp__n stats-comp__n--muted"><?= (int) $kpis['desactivadas'] ?></span>
            <span class="stats-comp__t">Matrículas desactivadas</span>
        </div>
        <div class="stats-comp__kpi">
            <?php // Sin `--err` a proposito: en este componente el rojo significa
                  // DESAPROBADO, y una matricula pendiente no es un fallo sino algo
                  // por resolver. En cero se apaga; con algo, color normal para que
                  // se lea sin gritar. El componente no tiene un `--warn`, y anadirlo
                  // por una sola pantalla no compensa. ?>
            <span class="stats-comp__n <?= ($kpis['pendientes'] ?? 0) > 0 ? '' : 'stats-comp__n--muted' ?>"><?= (int) $kpis['pendientes'] ?></span>
            <span class="stats-comp__t">Matrículas pendientes</span>
        </div>
        <?php // Si el total no cuadra con lo que alguien recuerda, la pantalla lo
              // explica en vez de parecer un error. Mismo criterio que el
              // "Exonerados · fuera del conteo" de _stats-competencia.php. ?>
        <?php if ($fueraDelConteo > 0): ?>
            <div class="stats-comp__kpi">
                <span class="stats-comp__n stats-comp__n--muted"><?= $fueraDelConteo ?></span>
                <span class="stats-comp__t">Trasladados y retirados &middot; fuera del conteo</span>
                <span class="stats-comp__kpi-pct">
                    <?= (int) $kpis['trasladados'] ?> trasladados &middot; <?= (int) $kpis['retirados'] ?> retirados
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gráficos -->
    <div class="resumen-charts">
        <div class="card resumen-chart resumen-chart--ancho">
            <div class="card__header"><h2 class="card__title">Matriculados por grado</h2></div>
            <div class="card__body"><div id="chart-grado"></div></div>
        </div>

        <div class="card resumen-chart resumen-chart--ancho">
            <div class="card__header"><h2 class="card__title">Matriculados por sección</h2></div>
            <div class="card__body"><div id="chart-seccion"></div></div>
        </div>

        <div class="card resumen-chart resumen-chart--ancho">
            <div class="card__header card__header--between">
                <h2 class="card__title">Varones y mujeres por sección</h2>
                <span class="badge <?= $genero['cobertura'] >= 80 ? 'badge--activo' : 'badge--warning' ?>">
                    <?= e((string) $genero['cobertura']) ?>% con dato
                </span>
            </div>
            <div class="card__body">
                <div id="chart-genero-seccion"></div>
                <?php if ($genero['cobertura'] < 80): ?>
                    <p class="resumen-nota">
                        Mientras no se registre el sexo de los estudiantes, las barras se
                        muestran como “Sin dato”. Se irán coloreando por sexo conforme se capture.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card resumen-chart">
            <div class="card__header"><h2 class="card__title">Por tipo de matrícula</h2></div>
            <div class="card__body"><div id="chart-tipo"></div></div>
        </div>

        <div class="card resumen-chart">
            <div class="card__header card__header--between">
                <h2 class="card__title">Por género</h2>
                <span class="badge <?= $genero['cobertura'] >= 80 ? 'badge--activo' : 'badge--warning' ?>">
                    <?= e((string) $genero['cobertura']) ?>% con dato
                </span>
            </div>
            <div class="card__body">
                <div id="chart-genero"></div>
                <?php if ($genero['cobertura'] < 80): ?>
                    <p class="resumen-nota">
                        El sexo de muchos estudiantes aún no está registrado en su matrícula.
                        El gráfico se completará a medida que se capture el dato.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script type="application/json" id="resumen-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script src="<?= url('js/frappe-charts.min.js') ?>"></script>
    <script src="<?= url('js/matriculas-resumen.js') ?>"></script>

<?php endif; ?>

<!-- Cuadro de matrícula por grado (panorama de todos los estados) -->
<?php
// Filtros activos del cuadro para arrastrarlos al botón de impresión.
$cuadroQs = http_build_query(array_filter([
    'anio_id'  => $anioId ?: null,
    'nivel_id' => ($nivelId ?? null) ?: null,
]));
?>
<div class="card cuadro-card">
    <div class="card__header card__header--between">
        <h2 class="card__title">Cuadro de matrícula por grado</h2>
        <div class="cuadro-card__acciones">
            <form method="GET" action="<?= url('matriculas/resumen') ?>" class="cuadro-card__filtro">
                <input type="hidden" name="anio_id" value="<?= (int) $anioId ?>">
                <label for="nivel_id" class="form-label">Nivel</label>
                <select name="nivel_id" id="nivel_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos los niveles</option>
                    <?php foreach (($niveles ?? []) as $nv): ?>
                        <option value="<?= (int) $nv['id'] ?>" <?= (int) ($nivelId ?? 0) === (int) $nv['id'] ? 'selected' : '' ?>>
                            <?= e($nv['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="<?= url('matriculas/resumen/imprimir' . ($cuadroQs ? '?' . $cuadroQs : '')) ?>"
               class="btn btn--secondary btn--sm" target="_blank" rel="noopener">🖨 Imprimir cuadro</a>
        </div>
    </div>
    <div class="card__body">
        <?php if (empty($cuadro)): ?>
            <p class="empty-state">No hay matrículas registradas para el año y nivel seleccionados.</p>
        <?php else: ?>
            <div class="cuadro-card__scroll">
                <?php require VIEW_PATH . '/matriculas/_cuadro-matricula.php'; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
