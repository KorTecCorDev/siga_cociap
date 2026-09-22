<?php
/**
 * @var array $carga
 * @var array $periodo
 * @var array $competencia
 * @var array $criterios
 * @var array  $alumnos
 * @var bool   $bloqueada
 * @var string $volverUrl
 */

$nivelCodigo   = $carga['nivel_codigo'];
$esTransversal = !empty($competencia['es_transversal']);
?>

<div class="page-header">
    <a href="<?= e($volverUrl) ?>"
       class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">
            <?php
            // Subárea → mostrar nombre de la subárea
            // Área-curso → mostrar nombre completo de la competencia
            // Transversal → siempre el nombre de la competencia (TIC/GAMA)
            $titulo = (!$esTransversal && $carga['area_tipo'] === 'con_subareas')
                ? ($carga['nombre_display'] ?? $carga['area_nombre'])
                : $competencia['nombre_completo'];

            $tituloCorto = mb_strlen($titulo) > 60
                ? mb_substr($titulo, 0, 60) . '...'
                : $titulo;
            ?>
            <span title="<?= e($titulo) ?>">
                <?= e($tituloCorto) ?>
            </span>
        </h1>
            <p class="page-subtitle">
                <?= e($carga['grado_nombre']) ?> —
                Sección <?= e($carga['seccion_nombre']) ?> —
                <?= e($periodo['nombre_display']) ?>
            </p>
    </div>
    <?php if ($bloqueada): ?>
        <span class="badge badge--error">🔒 Bloqueada</span>
    <?php endif; ?>
</div>

<?php if ($bloqueada): ?>
    <div class="flash flash--warning">
        Esta competencia ya fue aprobada y bloqueada.
        No se pueden realizar más cambios.
    </div>
<?php endif; ?>

<!-- Tabla de resumen -->
<div class="card mb-lg">
    <div class="card__header">
        <h2 class="card__title">
            <?= e($competencia['nombre_completo']) ?>
        </h2>
        <span class="competencia-card__codigo">
            <?= e($competencia['codigo_minedu'] ?? '') ?>
        </span>
    </div>

    <?php if (empty($alumnos)): ?>
        <div class="card__body">
            <p class="text-muted">No hay alumnos matriculados.</p>
        </div>
    <?php elseif (empty($criterios)): ?>
        <!-- Competencia sin criterios ni calificaciones -->
        <div class="card__body">
            <?php if ($bloqueada): ?>
                <p class="text-muted">
                    Esta competencia fue bloqueada sin calificaciones registradas
                    (no se trabajó en el <?= e($periodo['nombre_display']) ?>).
                </p>
            <?php else: ?>
                <div class="flash flash--warning">
                    No se registraron criterios ni calificaciones para esta competencia
                    en el <?= e($periodo['nombre_display']) ?>.
                    Si no fue trabajada en el bimestre, confírmalo para cerrarla.
                </div>
                <div class="resumen-footer">
                    <button class="btn btn--success" id="btn-confirmar-sin-notas"
                            data-carga-id="<?= $carga['id'] ?>"
                            data-competencia-id="<?= $competencia['id'] ?>"
                            data-volver-url="<?= e($volverUrl) ?>">
                        <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>
                        Confirmar: no se trabajó este bimestre
                    </button>
                    <span id="resumen-status"></span>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <?php require VIEW_PATH . '/shared/_stats-competencia.php'; ?>

        <?php
        // La extraordinaria de RA no se pinta como columna (18/09/2026): se
        // explica solo en la tarjeta de abajo.
        $criteriosVisibles = criterios_ordinarios($criterios);
        ?>

        <div class="tabla-responsive">
            <table class="tabla-resumen">
                <thead>
                    <tr>
                        <th class="col-num">N°</th>
                        <th class="col-nombre">Apellidos y nombres</th>
                        <!-- Criterios con tooltip (nombre completo + descripción) -->
                        <?php foreach ($criteriosVisibles as $criterio): ?>
                            <?php
                            $tooltipCriterio = $criterio['nombre']
                                . (!empty($criterio['descripcion'])
                                    ? "\n\n" . $criterio['descripcion'] : '');
                            ?>
                            <th class="col-criterio text-center" title="<?= e($tooltipCriterio) ?>">
                                <span class="criterio-header">
                                    <?= e(mb_strlen($criterio['nombre']) > 15
                                        ? mb_substr($criterio['nombre'], 0, 15) . '...'
                                        : $criterio['nombre']) ?>
                                </span>
                            </th>
                        <?php endforeach; ?>
                        <th class="col-numeral col-resultado col-resultado--inicio text-center"
                            title="Promedio de los criterios (calculado automáticamente)">Promedio numeral</th>
                        <th class="col-literal col-resultado text-center">Literal</th>
                        <th class="col-conclusion">Conclusión descriptiva</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $exoneradosSet = array_flip($exonerados ?? []);
                    foreach ($alumnos as $i => $alumno):
                        $esExonerado = isset($exoneradosSet[$alumno['matricula_id']]);
                        $promedio    = $alumno['promedio'];
                        $literal     = $alumno['literal'];
                        $esOblig     = false;

                        if (!$esExonerado && $literal !== null) {
                            if ($nivelCodigo === 'prim' && in_array($literal, ['B','C'])) {
                                $esOblig = true;
                            }
                            if ($nivelCodigo === 'sec' && $literal === 'C') {
                                $esOblig = true;
                            }
                        }
                    ?>
                        <tr class="<?= $esExonerado ? 'fila-exonerado' : ($esOblig && empty($alumno['conclusion_descriptiva']) ? 'fila-pendiente' : '') ?>">
                            <td class="col-num"><?= $i + 1 ?></td>
                            <td class="col-nombre"><?= e($alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'] . ', ' . $alumno['nombres']) ?></td>

                            <!-- Notas por criterio -->
                            <?php foreach ($criteriosVisibles as $criterio): ?>
                                <td class="col-criterio text-center">
                                    <?php if ($esExonerado): ?>
                                        <span class="exo-badge" title="Exonerado(a)">EXO</span>
                                    <?php else:
                                        $nc = $alumno['notas_criterios'][$criterio['id']] ?? null;
                                        $om = $alumno['omisiones_criterios'][$criterio['id']] ?? null;
                                    ?>
                                        <?php if ($nc !== null): ?>
                                            <?= fmt_nota((int) $nc) ?>
                                        <?php elseif ($om !== null): ?>
                                            <span class="omision-badge"
                                                  title="<?= e(\App\Models\OmisionCriterioModel::etiqueta($om)) ?>">—</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <!-- Promedio (numeral calculado) -->
                            <td class="col-numeral col-resultado col-resultado--inicio text-center">
                                <?php if ($esExonerado): ?>
                                    <span class="exo-badge" title="Exonerado(a)">EXO</span>
                                <?php elseif ($promedio !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($literal) ?>">
                                        <?= fmt_nota((int)$promedio) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Literal -->
                            <td class="col-literal col-resultado text-center">
                                <?php if ($esExonerado): ?>
                                    <span class="exo-badge exo-badge--lit" title="Exonerado(a)">EXO</span>
                                <?php elseif ($literal !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($literal) ?>">
                                        <?= $literal ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <!-- Conclusión descriptiva -->
                            <td class="col-conclusion">
                                <?php if ($esTransversal): ?>
                                    <span class="text-muted text-sm">
                                        La registra el tutor al cierre del bimestre
                                    </span>
                                <?php elseif ($esExonerado): ?>
                                    <span class="text-muted text-sm">Exonerado(a) — no aplica</span>
                                <?php elseif (!$bloqueada && $promedio !== null): ?>
                                    <div class="conclusion-alumno">
                                        <textarea
                                            class="form-input textarea-conclusion-alumno"
                                            rows="2"
                                            maxlength="500"
                                            data-matricula-id="<?= $alumno['matricula_id'] ?>"
                                            data-carga-id="<?= $carga['id'] ?>"
                                            data-competencia-id="<?= $competencia['id'] ?>"
                                            data-obligatorio="<?= $esOblig ? '1' : '0' ?>"
                                            placeholder="<?= $esOblig ? '* Obligatoria' : 'Opcional...' ?>"
                                        ><?= e($alumno['conclusion_descriptiva'] ?? '') ?></textarea>
                                        <?php if ($esOblig): ?>
                                            <small class="obligatorio">
                                                * Requerida para nota <?= $literal ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (!empty($alumno['conclusion_descriptiva'])): ?>
                                    <p class="conclusion-texto">
                                        <?= e($alumno['conclusion_descriptiva']) ?>
                                    </p>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php // Calificaciones extraordinarias de RA (partial compartido con la grilla). ?>
        <?php require VIEW_PATH . '/docente/_extraordinaria-info.php'; ?>

        <!-- Botones de acción -->
        <?php // El aviso de que la conclusión transversal la registra el tutor se
              // quitó de aquí el 22/09/2026: cada fila de la tabla ya lo dice en su
              // celda de conclusión, así que en el pie era la segunda copia. ?>
        <?php if (!$bloqueada): ?>
            <div class="resumen-footer">
                <?php if (!$esTransversal): ?>
                    <button class="btn btn--primary" id="btn-guardar-conclusiones">
                        <span class="btn-icon btn-icon--save" aria-hidden="true"></span>
                        Guardar conclusiones
                    </button>
                <?php endif; ?>
                <button class="btn btn--success btn--aprobar"
                        id="btn-aprobar-bloquear"
                        data-carga-id="<?= $carga['id'] ?>"
                        data-competencia-id="<?= $competencia['id'] ?>"
                        <?= $esTransversal ? '' : 'disabled' ?>>
                    <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>
                    Aprobar y bloquear
                </button>
                <span id="resumen-status"></span>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
