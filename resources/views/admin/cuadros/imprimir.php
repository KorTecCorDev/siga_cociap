<?php
/**
 * Cuadros estadísticos imprimibles (A4 portrait) — informe de Dirección.
 * Layout: print. Comparte con index.php la composición de bloques (la hace el
 * controlador) y el armado de los gráficos (_chart-data.php).
 *
 * ⚠ `print.php` NO procesa $page_scripts: la librería y el JS se cargan aquí
 * a mano, igual que hace boleta/alumno.php con qrcode.min.js.
 *
 * @var array      $periodo
 * @var array      $bloques
 * @var array|null $serieIds     ids admitidos en las series anuales; null = sin filtro
 * @var array|null $directorEbr  { sello_path }
 */
$pct = static fn(int $parte, int $total): int => $total > 0 ? (int) round($parte / $total * 100) : 0;

require VIEW_PATH . '/admin/cuadros/_chart-data.php';
// Mismos agregados que la pantalla ($c, $a, $lit, $pctLogro, $delta, $asisTot,
// $condCrit, $asisTop): en papel NO se recalculan.
require VIEW_PATH . '/admin/cuadros/_resumen-conducta-asistencia.php';

$k = $bloques['matricula']['kpis'];
?>
<div class="cuadros-print">

    <header class="cuadros-print__head">
        <img class="cuadros-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="cuadros-print__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Cuadros estadísticos &middot; <?= e($periodo['nombre_display']) ?> <?= e((string) $periodo['anio']) ?></p>
            <p class="cuadros-print__sub"><strong>Estado del bimestre:</strong> <?= e($periodo['estado']) ?></p>
        </div>
    </header>

    <div class="cuadros-print__meta">
        <span><strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?></span>
    </div>

    <?php // ── 1. MATRÍCULA ────────────────────────────────────────── ?>
    <section class="cuadros-print__bloque">
        <h2 class="cuadros-print__h2">Matrícula del año</h2>
        <div class="cuadros-kpis">
            <div class="cuadros-kpi"><span class="cuadros-kpi__n"><?= (int) $k['aprobadas'] ?></span><span class="cuadros-kpi__t">Aprobadas</span></div>
            <div class="cuadros-kpi"><span class="cuadros-kpi__n"><?= (int) $k['pendientes'] ?></span><span class="cuadros-kpi__t">Pendientes</span></div>
            <div class="cuadros-kpi"><span class="cuadros-kpi__n"><?= (int) $k['desactivadas'] ?></span><span class="cuadros-kpi__t">Desactivadas</span></div>
            <div class="cuadros-kpi"><span class="cuadros-kpi__n"><?= (int) $k['secciones'] ?></span><span class="cuadros-kpi__t">Secciones</span></div>
            <div class="cuadros-kpi"><span class="cuadros-kpi__n"><?= e((string) $k['promedio_seccion']) ?></span><span class="cuadros-kpi__t">Promedio por sección</span></div>
        </div>
    </section>

    <?php // ── 2. CALIFICACIONES ───────────────────────────────────── ?>
    <section class="cuadros-print__bloque">
        <h2 class="cuadros-print__h2">Calificaciones del bimestre</h2>

        <?php if (empty($bloques['calificaciones']['niveles'])): ?>
            <p class="cuadros-print__vacio">Este bimestre todavía no tiene calificaciones registradas.</p>
        <?php else: ?>
            <table class="tabla-resumen">
                <thead>
                    <tr>
                        <th>Nivel</th>
                        <th class="text-center">Calificaciones</th>
                        <th class="text-center">AD</th>
                        <th class="text-center">A</th>
                        <th class="text-center">B</th>
                        <th class="text-center">C</th>
                        <th class="text-center">% en logro</th>
                        <th class="text-center">Estudiantes</th>
                        <?php // "Promedio en C", no "En riesgo": cuenta el promedio general
                              // bajo NOTA_MIN_B por nivel, que es otra pregunta que la
                              // seccion "Estudiantes en riesgo" de mas abajo (3 C o mas, por
                              // grado). Ver el comentario gemelo en index.php. ?>
                        <th class="text-center">Promedio en C</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bloques['calificaciones']['niveles'] as $n): ?>
                        <tr>
                            <td><strong><?= e($n['nivel_nombre']) ?></strong></td>
                            <td class="text-center"><?= (int) $n['total_calif'] ?></td>
                            <td class="text-center"><?= (int) $n['ad'] ?></td>
                            <td class="text-center"><?= (int) $n['a'] ?></td>
                            <td class="text-center"><?= (int) $n['b'] ?></td>
                            <td class="text-center"><?= (int) $n['c'] ?></td>
                            <td class="text-center"><strong><?= (int) $n['pct_logro'] ?>%</strong></td>
                            <td class="text-center"><?= (int) $n['total_estudiantes'] ?></td>
                            <td class="text-center"><?= (int) $n['en_riesgo'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php $resumen = $bloques['calificaciones']; ?>
            <div class="cuadros-panel">
                <?php require VIEW_PATH . '/director/anios/_panel-bimestre.php'; ?>
            </div>

            <?php if (isset($chartData['evolucion'])): ?>
                <div class="cuadros-print__chart">
                    <h3 class="cuadros-print__h3">Evolución del % en logro por bimestre</h3>
                    <div id="chart-evolucion"></div>
                    <?php $t = $chartTablas['evolucion'] ?? null; ?>
                    <?php // La nota explica COMO leer el grafico y en papel es donde mas
                          // falta hace: nadie puede preguntar. La de la brecha avisa de que
                          // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                    <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
                </div>
                <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php // ── 3. ORDEN DE MÉRITO ──────────────────────────────────── ?>
    <section class="cuadros-print__bloque">
        <h2 class="cuadros-print__h2">Orden de mérito</h2>

        <?php if (!empty($bloques['empates'])): ?>
            <p class="cuadros-print__aviso">
                Hay <?= count($bloques['empates']) ?> grado(s) con empates sin resolver:
                el orden todavía no es oficializable.
            </p>
        <?php endif; ?>

        <?php if (empty($bloques['merito']['por_grado'])): ?>
            <p class="cuadros-print__vacio">Este bimestre todavía no tiene ranking.</p>
        <?php else: ?>
            <?php require VIEW_PATH . '/admin/cuadros/_banda-merito.php'; ?>
            <table class="tabla-resumen">
                <thead>
                    <tr>
                        <th>Grado</th>
                        <th>Nivel</th>
                        <th class="text-center">Estudiantes</th>
                        <th>Primer puesto</th>
                        <th class="text-center">Promedio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bloques['merito']['por_grado'] as $g): ?>
                        <tr>
                            <td><strong><?= e($g['grado']['nombre_display']) ?></strong></td>
                            <td><?= e($g['grado']['nivel_nombre']) ?></td>
                            <td class="text-center"><?= (int) $g['total'] ?></td>
                            <td><?= e($g['mejor']['nombre_completo'] ?? '—') ?></td>
                            <td class="text-center"><?= e((string) ($g['mejor']['promedio_general'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (isset($chartData['brecha'])): ?>
                <div class="cuadros-print__chart">
                    <h3 class="cuadros-print__h3">Brecha interna de cada grado</h3>
                    <div id="chart-brecha"></div>
                    <?php $t = $chartTablas['brecha'] ?? null; ?>
                    <?php // La nota explica COMO leer el grafico y en papel es donde mas
                          // falta hace: nadie puede preguntar. La de la brecha avisa de que
                          // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                    <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
                </div>
                <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php // ── 3b. ESTUDIANTES EN RIESGO ───────────────────────────── ?>
    <?php // `--tabla` porque el bloque es un listado, no un panel: son las
          // tablas por grado las que deben poder cortar entre hojas sin partir
          // un grado por la mitad. ?>
    <section class="cuadros-print__bloque cuadros-print__bloque--tabla">
        <h2 class="cuadros-print__h2">Estudiantes en riesgo</h2>

        <?php
        $hayRiesgo = (bool) array_filter(
            $bloques['merito']['por_grado'] ?? [],
            static fn(array $g): bool => !empty($g['en_riesgo'])
        );
        ?>
        <?php if (!$hayRiesgo): ?>
            <?php // En papel el vacio importa MAS que en pantalla: el informe se
                  // archiva, y "no hay nadie en riesgo" y "todavia no se puede
                  // saber" son afirmaciones muy distintas para quien lo lea. ?>
            <p class="cuadros-print__vacio">
                <?php if (empty($bloques['merito']['por_grado'])): ?>
                    Este bimestre todavía no tiene competencias bloqueadas: aún no puede
                    determinarse quién está en riesgo.
                <?php else: ?>
                    Ningún estudiante acumula
                    <?= (int) ($bloques['merito']['riesgo_min_c'] ?? 3) ?> competencias en C
                    o más en este bimestre.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php // ⚠️ AQUI NO SE DEFINE `$riesgoInteractivo`, y es DELIBERADO: el
                  // partial lo lee con `!empty()`, asi que en papel no salen ni el
                  // buscador ni los chips ni el contador. No "arreglar" la variable
                  // que falta — es el mismo idioma que `$abierta` en
                  // `_tabla-grafico.php`, donde un <details> cerrado imprimia una
                  // hoja en blanco. La banda de cifras SI sale: es dato, no control. ?>
            <?php require VIEW_PATH . '/admin/cuadros/_estudiantes-riesgo.php'; ?>
        <?php endif; ?>
    </section>

    <?php // ── 4. CONDUCTA ─────────────────────────────────────────── ?>
    <?php // En papel NO hay pestañas: el informe se lee entero de una vez, y un
          // bloque oculto en una hoja impresa simplemente no existe. ?>
    <section class="cuadros-print__bloque">
        <h2 class="cuadros-print__h2">Conducta</h2>
        <div class="cuadros-kpis">
            <?php // Sin conducta calificada NO se imprimen las tarjetas de
                  // resultado: un "En logro 0 · 0%" en un papel se lee como un
                  // resultado pesimo, cuando lo que pasa es que aun no hay nada
                  // que medir. Las de proceso si van: son las que dicen por
                  // donde va el bimestre. ?>
            <?php if ($lit['total'] > 0): ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $lit['logro'] ?></span>
                <span class="cuadros-kpi__t">En logro (AD + A) &middot; <?= $pctLogro ?>%</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $lit['b'] ?></span>
                <span class="cuadros-kpi__t">En proceso (B)</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $lit['c'] ?></span>
                <span class="cuadros-kpi__t">En inicio (C)</span>
            </div>
            <?php if ($delta !== null): ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= $delta > 0 ? '+' : '' ?><?= number_format($delta, 1) ?></span>
                <span class="cuadros-kpi__t">Puntos frente a <?= e($nombrePrevio) ?></span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $lit['total'] ?></span>
                <span class="cuadros-kpi__t">Estudiantes con conducta calificada</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $c['cerradas'] ?>/<?= (int) $c['secciones'] ?></span>
                <span class="cuadros-kpi__t">Conducta cerrada</span>
            </div>
            <?php // Las dos etapas pendientes SOLO llegaban al papel por la leyenda
                  // del grafico de embudo, y ese grafico no se registra cuando la
                  // suma es cero: el informe podia quedarse sin decir a quien esta
                  // esperando el cierre. Son cifras de PROCESO, asi que van fuera
                  // del `if` de resultado, igual que "Conducta cerrada". ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $c['pend_tutor'] ?></span>
                <span class="cuadros-kpi__t">Esperan al tutor</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $c['pend_auxiliar'] ?></span>
                <span class="cuadros-kpi__t">Esperan al auxiliar</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= $pct((int) $c['calificados'], (int) $c['esperados']) ?>%</span>
                <span class="cuadros-kpi__t">Calificados en conducta</span>
            </div>
        </div>
        <?php if ($lit['total'] === 0): ?>
            <p class="cuadros-print__vacio">Este bimestre todavía no tiene conducta calificada.</p>
        <?php endif; ?>

        <?php if (isset($chartData['conductaLiterales'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Distribución de conducta por nivel</h3>
                <div id="chart-conducta-literales"></div>
                <?php $t = $chartTablas['conductaLiterales'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['conductaEvolucion'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Evolución del % en logro de conducta</h3>
                <div id="chart-conducta-evolucion"></div>
                <?php $t = $chartTablas['conductaEvolucion'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['conductaEmbudo'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Etapa del cierre de conducta</h3>
                <div id="chart-conducta-embudo"></div>
                <?php $t = $chartTablas['conductaEmbudo'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['conductaSecciones'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Secciones con menor avance en conducta</h3>
                <div id="chart-conducta-secciones"></div>
                <?php $t = $chartTablas['conductaSecciones'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['conductaCriterios'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Criterios con mayor incumplimiento</h3>
                <div id="chart-conducta-criterios"></div>
                <?php $t = $chartTablas['conductaCriterios'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>
    </section>

    <?php // La matriz va en su PROPIO bloque: es una tabla larga y el
          // `page-break-inside: avoid` de `.cuadros-print__bloque` la empujaria
          // entera a la hoja siguiente en vez de dejarla partirse. ?>
    <?php if (!empty($condCrit['criterios'])): ?>
    <section class="cuadros-print__bloque cuadros-print__bloque--tabla">
        <h2 class="cuadros-print__h2">Incumplimiento por sección</h2>
        <?php require VIEW_PATH . '/admin/cuadros/_matriz-criterios.php'; ?>
    </section>
    <?php endif; ?>

    <?php // ── 5. ASISTENCIA ───────────────────────────────────────── ?>
    <section class="cuadros-print__bloque">
        <h2 class="cuadros-print__h2">Asistencia</h2>
        <div class="cuadros-kpis">
            <?php // Sin NINGUN registro de asistencia, "0 faltas" y "100 % sin
                  // incidencias" serian datos falsos, no ausentes: nadie ha
                  // tomado asistencia todavia. Es el mismo error que ya se
                  // corrigio una vez en la boleta. ?>
            <?php if ((int) $a['registrados'] > 0): ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $asisTot['faltas'] ?></span>
                <span class="cuadros-kpi__t">Faltas sin justificar</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $asisTot['tardanzas'] ?></span>
                <span class="cuadros-kpi__t">Tardanzas sin justificar</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= $pct((int) $asisTot['sin_incidencias'], (int) $asisTot['alumnos']) ?>%</span>
                <span class="cuadros-kpi__t">Sin ninguna incidencia</span>
            </div>
            <?php endif; ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $a['completas'] ?>/<?= (int) $a['secciones'] ?></span>
                <span class="cuadros-kpi__t">Asistencia completa</span>
            </div>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= $pct((int) $a['registrados'], (int) $a['esperados']) ?>%</span>
                <span class="cuadros-kpi__t">Cobertura de asistencia</span>
            </div>
        </div>
        <?php if ((int) $a['registrados'] === 0): ?>
            <p class="cuadros-print__vacio">Este bimestre todavía no tiene asistencia registrada.</p>
        <?php endif; ?>

        <?php if (isset($chartData['asisEvolucion'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Evolución de faltas y tardanzas sin justificar</h3>
                <div id="chart-asis-evolucion"></div>
                <?php $t = $chartTablas['asisEvolucion'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['asisJustificacion'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Justificadas frente a sin justificar, por nivel</h3>
                <div id="chart-asis-justificacion"></div>
                <?php $t = $chartTablas['asisJustificacion'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['asisFaltas'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Faltas sin justificar por sección</h3>
                <div id="chart-asis-faltas"></div>
                <?php $t = $chartTablas['asisFaltas'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>

        <?php if (isset($chartData['asisTardanzas'])): ?>
            <div class="cuadros-print__chart">
                <h3 class="cuadros-print__h3">Tardanzas sin justificar por sección</h3>
                <div id="chart-asis-tardanzas"></div>
                <?php $t = $chartTablas['asisTardanzas'] ?? null; ?>
                <?php // La nota explica COMO leer el grafico y en papel es donde mas
                      // falta hace: nadie puede preguntar. La de la brecha avisa de que
                      // la distancia entre barras es la dispersion del grado, no su nivel. ?>
                <p class="cuadros-print__nota"><?= $t['nota'] ?></p>
            </div>
            <?php $abierta = true; require VIEW_PATH . '/admin/cuadros/_tabla-grafico.php'; ?>
        <?php endif; ?>
    </section>

    <?php if (!empty($asisTop)): ?>
    <section class="cuadros-print__bloque cuadros-print__bloque--tabla">
        <h2 class="cuadros-print__h2">Estudiantes con más faltas</h2>
        <?php require VIEW_PATH . '/admin/cuadros/_top-incidencias.php'; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($bloques['reaperturas'])): ?>
        <section class="cuadros-print__bloque">
            <h2 class="cuadros-print__h2">Reaperturas del bimestre</h2>
            <p><?= count($bloques['reaperturas']) ?> reapertura(s) registrada(s), auditadas con su motivo.</p>
        </section>
    <?php endif; ?>

    <?php if ($directorEbr && !empty($directorEbr['sello_path'])): ?>
        <footer class="cuadros-print__footer">
            <div class="cuadros-print__sello-bloque">
                <img class="cuadros-print__sello" src="<?= url($directorEbr['sello_path']) ?>"
                     alt="" aria-hidden="true">
            </div>
        </footer>
    <?php endif; ?>

</div>

<?php if ($chartData): ?>
    <script type="application/json" id="cuadros-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <script src="<?= url('js/frappe-charts.min.js') ?>"></script>
    <script src="<?= url('js/cuadros.js') ?>"></script>
<?php endif; ?>
