<?php
/**
 * Consulta — CONDUCTA de una seccion (solo lectura).
 * Sin formularios ni JS: para escribir esta /admin/conducta, que es admin+RA.
 *
 * ⚠️ Dos modelos distintos segun el bimestre:
 *   · LEGADO (I Bimestre, anterior a la migracion 021): literal directo, sin
 *     criterios ni respuestas Si/No -> tabla simple nombre + literal.
 *   · NUEVO (II Bimestre+): nota RA derivada de las respuestas + nota del tutor
 *     -> grilla con las dos y la final.
 * `getEstudiantesParaTutor` resuelve las dos y marca `es_legado`.
 *
 * @var array $periodo
 * @var array $seccion    [seccion_id, seccion_nombre, grado_nombre, nivel_nombre]
 * @var array $cierre     cierre con las dos etapas (ra_nombre, tutor_nombre, fechas)
 * @var array $alumnos    [{matricula_id, nombre_completo, nota_ra, nota_tutor, nota_final, literal_final, si, respondidos, es_legado}]
 * @var array $criterios  criterios de conducta del nivel (vacio si es legado)
 * @var bool  $esLegado
 *
 * Compartida desde el 13/10/2026 con `Admin\ConductaController::resultado` (la
 * nota final que ve el auxiliar). Lo único que cambia es a dónde llevan los dos
 * enlaces; sin estas variables, los de la consulta de Dirección:
 * @var string|null $urlVolver     «volver»
 * @var string|null $textoVolver   rótulo del «volver»
 * @var string|null $urlCriterios  grilla Sí / No
 */
$urlVolver    ??= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id']);
$textoVolver  ??= '← Áreas';
$urlCriterios ??= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id'] . '/conducta/criterios');
?>

<div class="page-header">
    <a href="<?= $urlVolver ?>"
       class="btn btn--secondary btn--sm"><?= e($textoVolver) ?></a>
    <div>
        <h1 class="page-title">Conducta</h1>
        <p class="page-subtitle">
            <?= e($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']) ?> ·
            <?= e($seccion['nivel_nombre']) ?> ·
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?>
        </p>
    </div>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<div class="flash flash--info">
    Cerrada en sus dos etapas:
    bloqueada por <strong><?= e($cierre['ra_nombre'] ?? '—') ?></strong>
    el <?= fechaLima($cierre['ra_bloqueado_en'], 'd/m/Y H:i') ?>,
    cerrada por <strong><?= e($cierre['tutor_nombre'] ?? '—') ?></strong>
    el <?= fechaLima($cierre['tutor_cerrado_en'], 'd/m/Y H:i') ?>.
    <?php if ($esLegado): ?>
        <br>Este bimestre usa el <strong>registro legado</strong>: solo literal,
        sin criterios ni nota numérica.
    <?php endif; ?>
</div>

<?php if (empty($alumnos)): ?>
    <div class="empty-state"><p>La sección no tiene estudiantes en el roster.</p></div>
<?php else: ?>
<div class="card mb-lg">
    <div class="tabla-notas-wrapper">
        <table class="tabla-resumen">
            <thead>
                <tr>
                    <th class="col-num">N°</th>
                    <th class="col-nombre">Apellidos y nombres</th>
                    <?php if ($esLegado): ?>
                        <?php // Tambien aqui la zona se abre con el separador, aunque sea de una
                              // sola columna: la regla es la misma en las dos ramas. ?>
                        <th class="col-literal col-resultado col-resultado--inicio text-center">Literal</th>
                    <?php else: ?>
                        <?php // El DETALLE va antes y la ZONA DE RESULTADO cierra la fila: el
                              // separador de `col-resultado--inicio` abre un bloque que no debe
                              // quedar interrumpido por una columna suelta a su derecha. La
                              // leyenda de abajo explica las tres notas y el Si/total. ?>
                        <th class="text-center">Sí / total</th>
                        <th class="col-numeral col-resultado col-resultado--inicio text-center">Nota auxiliar</th>
                        <th class="col-numeral col-resultado text-center">Nota tutor</th>
                        <th class="col-numeral col-resultado text-center">Final</th>
                        <th class="col-literal col-resultado text-center">Literal</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnos as $i => $a): ?>
                    <?php $lit = $a['literal_final']; ?>
                    <tr>
                        <td class="col-num"><?= $i + 1 ?></td>
                        <td class="col-nombre"><?= e($a['nombre_completo']) ?>
                            <?php $sitChips = $a['situaciones'] ?? []; require VIEW_PATH . '/shared/_situacion-chips.php'; ?></td>

                        <?php if ($esLegado): ?>
                            <td class="col-literal col-resultado col-resultado--inicio text-center">
                                <?php if ($lit !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($lit) ?>"><?= e($lit) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <?php // Mismo orden que el thead: detalle primero, resultado al final. ?>
                            <td class="text-center text-sm text-muted">
                                <?= (int) $a['si'] ?> / <?= count($criterios) ?>
                            </td>
                            <td class="col-numeral col-resultado col-resultado--inicio text-center">
                                <?= $a['nota_ra'] !== null ? fmt_nota((int) $a['nota_ra']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="col-numeral col-resultado text-center">
                                <?= $a['nota_tutor'] !== null ? fmt_nota((int) $a['nota_tutor']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="col-numeral col-resultado text-center">
                                <?php if ($a['nota_final'] !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($lit ?? '') ?>">
                                        <?= fmt_nota((int) $a['nota_final']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-literal col-resultado text-center">
                                <?php if ($lit !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($lit) ?>"><?= e($lit) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$esLegado): ?>
        <?php // 🔴 EL PIE VA FUERA DEL WRAPPER. Dentro se desplazaba con el scroll
              // horizontal y el `overflow: hidden` del card lo recortaba en las
              // esquinas redondeadas. Ver `.tabla-pie` en components/_tables.scss.
              //
              // Estas explicaciones vivian solo en `title`, o sea un tooltip: no
              // existe en movil ni con teclado. Y aqui pesan mas que en otras
              // tablas — son tres columnas numericas seguidas que se confunden. ?>
        <div class="tabla-pie">
            <p class="tabla-pie__leyenda">
                <span class="tabla-pie__item"><strong>Sí / total</strong> criterios respondidos con Sí</span>
                <span class="tabla-pie__item"><strong>Nota auxiliar</strong> derivada de las respuestas registradas</span>
                <span class="tabla-pie__item"><strong>Nota tutor</strong> la que registra el tutor de la sección</span>
                <span class="tabla-pie__item tabla-pie__item--bloque">
                    <strong>Final</strong> y <strong>Literal</strong> son la nota que va a la boleta.
                </span>
            </p>
        </div>
    <?php endif; ?>
</div>

<?php if (!$esLegado && !empty($criterios)): ?>
    <div class="card mb-lg">
        <div class="card__header">
            <h2 class="card__title">Criterios evaluados (<?= count($criterios) ?>)</h2>
            <?php // La grilla Si/No por alumno NO cabe en esta pantalla (10 columnas
                  // mas el roster). Vive en su propia vista, en solo lectura. ?>
            <a class="btn btn--secondary btn--sm"
               href="<?= $urlCriterios ?>">
                Ver la grilla Sí / No &rarr;
            </a>
        </div>
        <div class="card__body">
            <?php // El CODIGO va delante, y sale del modelo: es el mismo que rotula las
                  // columnas del imprimible y de la grilla, asi que el director puede
                  // cruzar el papel con la pantalla. Nunca recalcularlo aqui. ?>
            <ul class="criterios-codigos">
                <?php foreach ($criterios as $cr): ?>
                    <li>
                        <span class="competencia-card__codigo"><?= e($cr['codigo']) ?></span>
                        <?= e($cr['texto']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
