<?php
/**
 * Vista: Expediente y resolución de un empate irreducible del orden de mérito.
 *
 * Desde el 17/10/2026 muestra, por grupo, el EXPEDIENTE completo (por qué
 * empatan, detalle por competencia, asistencia, conducta y boleta) y el
 * formulario con criterios aplicados + motivo. La Dirección la ve en solo
 * lectura: sin `puedeEscribir` no se pinta el formulario.
 *
 * @var array $periodo        { id, anio, nombre_display }
 * @var array $grado          { id, nombre_display, nivel_nombre }
 * @var array $grupos         [empate_clave => [ filas del ranking en vivo: matricula_id,
 *                              apellido_*, nombres, seccion_nombre, num_competencias,
 *                              promedio_general, num_c, num_b, num_ad, num_alto, num_16 ]]
 * @var array $evidencia      [empate_clave => {comparativo, expediente}]
 * @var array $criterios      DesempateMeritoModel::CRITERIOS
 * @var bool  $puedeEscribir
 */
?>

<div class="page-header">
    <a href="<?= url('director/orden-merito/' . $periodo['id']) ?>"
       class="btn btn--secondary btn--sm">← Volver al ranking</a>
    <div>
        <h1 class="page-title"><?= $puedeEscribir ? 'Resolver empate' : 'Expediente del empate' ?></h1>
        <p class="page-subtitle">
            <?= e($grado['nivel_nombre']) ?> — <?= e($grado['nombre_display']) ?> ·
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?>
        </p>
    </div>
</div>

<?php if (empty($grupos)): ?>
    <div class="empty-state">
        <p>No hay empates por resolver en este grado.</p>
    </div>
<?php else: ?>

    <div class="card mb-lg">
        <div class="card__body">
            <p class="text-muted">
                Estos estudiantes tienen el mismo promedio y una distribución de calificaciones
                que la cascada automática no puede separar (o un número de competencias
                distinto por exoneración). Debajo de cada grupo está su expediente: el detalle
                por competencia, la asistencia, la conducta y la boleta hasta este bimestre.
                <?php if ($puedeEscribir): ?>
                    Con <strong>Resolver este empate</strong> asignas el orden del puesto en
                    disputa (1 = puesto superior), marcas los criterios que aplicaste e
                    indicas el motivo. La decisión queda registrada en el acta junto con
                    este expediente.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php
    // Con más de un grupo, una PESTAÑA por grupo (componente global `.tabs` +
    // `js/tabs.js`): cada expediente es largo y apilados obligaban a recorrer
    // todos para llegar al siguiente. El servidor deja visible el primero.
    $conPestanas = count($grupos) > 1;
    $nGrupo      = 0;
    ?>
    <?php if ($conPestanas): ?>
        <div class="tabs" role="tablist" aria-label="Grupos empatados" data-tabs="desempate">
            <?php foreach ($grupos as $alumnos): $nGrupo++; ?>
                <button type="button" class="tab<?= $nGrupo === 1 ? ' tab--activa' : '' ?>"
                        role="tab" id="tab-grupo-<?= $nGrupo ?>"
                        data-tab="grupo-<?= $nGrupo ?>" aria-controls="panel-grupo-<?= $nGrupo ?>"
                        aria-selected="<?= $nGrupo === 1 ? 'true' : 'false' ?>"
                        <?= $nGrupo === 1 ? '' : 'tabindex="-1"' ?>>
                    Grupo <?= $nGrupo ?><span class="tab__n"><?= count($alumnos) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php $nGrupo = 0; foreach ($grupos as $clave => $alumnos): $nGrupo++; ?>
        <?php
        $ev       = $evidencia[$clave] ?? ['comparativo' => [], 'expediente' => []];
        $nDistinto = count(array_unique(array_map(static fn($a) => (int) $a['num_competencias'], $alumnos))) > 1;
        $columnas = array_map(static fn($a) => [
            'mid'      => (int) $a['matricula_id'],
            'etiqueta' => $a['apellido_paterno'] . ' ' . $a['apellido_materno'],
        ], $alumnos);
        // Estudiantes del grupo para el modal de resolución (lo llena desempate.js).
        $alumnosModal = array_map(static fn($a) => [
            'mid'    => (int) $a['matricula_id'],
            'nombre' => $a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ', ' . $a['nombres'],
        ], array_values($alumnos));
        ?>
        <?php if ($conPestanas): ?>
            <div id="panel-grupo-<?= $nGrupo ?>" class="tab-panel" role="tabpanel"
                 data-panel="grupo-<?= $nGrupo ?>" aria-labelledby="tab-grupo-<?= $nGrupo ?>"
                 <?= $nGrupo === 1 ? '' : 'hidden' ?>>
        <?php endif; ?>
        <div class="card mb-lg">
            <div class="card__header">
                <h2 class="card__title">
                    <?= $conPestanas ? 'Grupo ' . $nGrupo . ' · ' : '' ?>Empate de <?= count($alumnos) ?> estudiantes
                </h2>
                <?php if ($puedeEscribir): ?>
                    <button type="button" class="btn btn--primary btn--sm"
                            data-desempate-abrir
                            data-grupo-titulo="<?= e($conPestanas ? 'Grupo ' . $nGrupo : 'Grupo empatado') ?>"
                            data-alumnos="<?= e(json_encode($alumnosModal, JSON_UNESCAPED_UNICODE)) ?>">
                        Resolver este empate
                    </button>
                <?php endif; ?>
            </div>

            <div class="card__body">
                <div class="acta-subtitulo">Por qué empatan</div>
                <p class="acta-aclaratoria">
                    <?= $nDistinto
                        ? 'Tienen el mismo promedio con distinto número de competencias evaluadas.'
                        : 'Coinciden en el promedio y en los cinco conteos de la cascada.' ?>
                </p>
                <div class="tabla-responsive">
                <table class="tabla-ranking">
                    <thead>
                        <tr>
                            <th class="col-nombre">Apellidos y nombres</th>
                            <th class="text-center">Sección</th>
                            <th class="text-center">Comp.</th>
                            <th class="text-center">Promedio</th>
                            <th class="text-center">C</th>
                            <th class="text-center">B</th>
                            <th class="text-center">AD</th>
                            <th class="text-center">Notas 15-16</th>
                            <th class="text-center">Notas 16</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alumnos as $a): ?>
                            <tr>
                                <td class="col-nombre">
                                    <?= e($a['apellido_paterno'] . ' ' .
                                        $a['apellido_materno'] . ', ' . $a['nombres']) ?>
                                </td>
                                <td class="text-center"><?= e($a['seccion_nombre']) ?></td>
                                <td class="text-center"><?= (int) $a['num_competencias'] ?></td>
                                <td class="text-center">
                                    <strong><?= sprintf('%05.2f', $a['promedio_general']) ?></strong>
                                </td>
                                <td class="text-center"><?= (int) $a['num_c'] ?></td>
                                <td class="text-center"><?= (int) $a['num_b'] ?></td>
                                <td class="text-center"><?= (int) $a['num_ad'] ?></td>
                                <td class="text-center"><?= (int) $a['num_alto'] ?></td>
                                <td class="text-center"><?= (int) $a['num_16'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if (!empty($ev['comparativo'])): ?>
                    <div class="acta-subtitulo">Detalle por competencia (<?= e($periodo['nombre_display']) ?>)</div>
                    <p class="acta-aclaratoria">
                        Las notas que forman el promedio. Las filas
                        <span class="acta-aclaratoria__marca">resaltadas</span> son competencias con
                        el mismo literal (AD/A/B/C) pero distinta nota numeral.
                    </p>
                    <div class="tabla-notas-wrapper">
                        <table class="tabla-ranking tabla-comparativo">
                            <thead>
                                <tr>
                                    <th>Competencia</th>
                                    <?php foreach ($columnas as $col): ?>
                                        <th class="text-center"><?= e($col['etiqueta']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ev['comparativo'] as $row): ?>
                                    <tr class="<?= !empty($row['resaltar']) ? 'fila-resaltada' : '' ?>">
                                        <td><?= e($row['label']) ?></td>
                                        <?php foreach ($columnas as $col): ?>
                                            <?php
                                            $n   = $row['notas'][$col['mid']] ?? null;
                                            $lit = $row['literales'][$col['mid']] ?? null;
                                            ?>
                                            <td class="text-center">
                                                <?= $n !== null ? (int) $n . ' (' . e($lit) . ')' : '—' ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php
                $exp  = $ev['expediente'];
                $modo = 'pantalla';
                require VIEW_PATH . '/director/_desempate-expediente.php';
                ?>
            </div>
        </div>
        <?php if ($conPestanas): ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($puedeEscribir): ?>
        <?php
        // UN SOLO formulario para todos los grupos: cada botón "Resolver este
        // empate" abre este modal (Modal de app.js) y desempate.js pinta las filas
        // de orden con los estudiantes de ese grupo. El servidor identifica el
        // grupo por las matrículas de `orden[]`, igual que antes.
        ?>
        <div class="modal-overlay" id="modalDesempate" hidden>
            <div class="modal-box modal-box--ancho" role="dialog" aria-modal="true"
                 aria-labelledby="modalDesempateTitulo">
                <div class="modal-header">
                    <h2 class="modal-title" id="modalDesempateTitulo">Resolver empate</h2>
                    <button type="button" class="modal-cerrar" data-modal-cerrar="modalDesempate" aria-label="Cerrar">✕</button>
                </div>
                <form method="POST"
                      action="<?= url('director/orden-merito/' . $periodo['id'] . '/desempate/' . $grado['id']) ?>"
                      class="desempate-form" data-desempate-form>
                    <?= csrf_field() ?>
                    <div class="modal-body">
                        <p class="text-muted">
                            Asigna el orden del puesto en disputa (1 = puesto superior).
                        </p>
                        <div class="tabla-responsive">
                        <table class="tabla-ranking">
                            <thead>
                                <tr>
                                    <th class="col-puesto text-center">Orden</th>
                                    <th class="col-nombre">Apellidos y nombres</th>
                                </tr>
                            </thead>
                            <tbody data-desempate-filas></tbody>
                        </table>
                        </div>

                        <fieldset class="desempate-criterios">
                            <legend class="form-label">
                                Criterios aplicados <span class="text-danger">*</span>
                            </legend>
                            <?php foreach ($criterios as $valor => $etiqueta): ?>
                                <label class="form-check">
                                    <input type="checkbox" name="criterio[]" value="<?= e($valor) ?>" data-criterio>
                                    <span><?= e($etiqueta) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <p class="form-error" data-criterio-error hidden>Marca al menos un criterio aplicado.</p>
                        </fieldset>

                        <div class="form-group mt-md">
                            <label class="form-label" for="motivo-desempate">
                                Motivo de la decisión <span class="text-danger">*</span>
                            </label>
                            <textarea id="motivo-desempate" name="motivo"
                                      class="form-input" rows="3" maxlength="500" required
                                      placeholder="Ej.: a igual rendimiento, se prioriza por acuerdo del comité directivo del..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn--secondary" data-modal-cerrar="modalDesempate">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn--primary" data-desempate-guardar>
                            Guardar resolución
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($conPestanas): ?>
        <script src="<?= url('js/tabs.js') ?>"></script>
    <?php endif; ?>
    <?php if ($puedeEscribir): ?>
        <script src="<?= url('js/desempate.js') ?>"></script>
    <?php endif; ?>

<?php endif; ?>
