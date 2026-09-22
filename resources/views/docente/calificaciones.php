<?php
/**
 * @var array  $carga
 * @var array  $periodo
 * @var array  $competencias
 * @var array  $alumnos
 * @var bool   $bloqueado
 * @var array  $bloqueos
 * @var array  $notasExistentes
 */
?>

<div class="page-header">
    <a href="<?= url('docente/mis-cargas') ?>"
       class="btn btn--secondary btn--sm">← Mis cargas</a>
    <div>
        <?php
        // Unidocente en SU seccion a cargo (es_unidocente): no dicta subareas,
        // dicta el area-curso -> muestra solo el nombre del area. Fuera de su
        // seccion (carga de especialista) conserva el nombre de la subarea.
        $tituloCarga = !empty($carga['es_unidocente'])
            ? $carga['area_nombre']
            : $carga['nombre_display'];
        ?>
        <h1 class="page-title"><?= e($tituloCarga) ?></h1>
        <p class="page-subtitle">
            <?= 
            e($carga['nivel_nombre']) ?> —
            <?= 
            e($carga['grado_nombre']) ?> —
            Sección <?= e($carga['seccion_nombre']) ?> —
            <?= e($periodo['nombre_display'] ?? '') ?>
        </p>
    </div>
    <?php if ($bloqueado): ?>
        <span class="badge badge--error">⚠ Periodo bloqueado</span>
    <?php endif; ?>
</div>

<?php if ($bloqueado): ?>
    <div class="flash flash--warning">
        El plazo para registrar calificaciones ha vencido.
        Comunícate con Registro Académico.
    </div>
<?php endif; ?>

<?php if (empty($alumnos)): ?>
    <div class="empty-state">
        <p>No hay alumnos matriculados y aprobados en esta sección.</p>
    </div>
<?php else: ?>

    <?php
    // Primer criterio pendiente: recorre competencias y criterios en orden
    // visual y devuelve el primero que aún no tiene a todos los alumnos
    // calificados. Las competencias bloqueadas se ignoran porque ya no se
    // editan. Si todo esta al 100 por ciento, queda en null y el banner
    // de "continuar pendiente" no se renderiza.
    $exoneradosFlip = array_flip($exonerados ?? []);
    $totalAlumnos = count(array_filter(
        $alumnos,
        fn($a) => !isset($exoneradosFlip[$a['matricula_id']])
    ));
    $siguientePendiente = null;
    if (!$bloqueado) {
        foreach ($competencias as $_c) {
            if (in_array($_c['id'], $bloqueos ?? [])) continue;
            foreach ($_c['criterios'] ?? [] as $_cri) {
                // La extraordinaria de RA no es un pendiente del docente.
                if (!empty($_cri['extraordinario'])) continue;
                $calificados = count($notasExistentes[$_cri['id']] ?? []);
                if ($calificados < $totalAlumnos) {
                    $siguientePendiente = [
                        'criterio_id'        => (int) $_cri['id'],
                        'criterio_nombre'    => $_cri['nombre'],
                        'competencia_nombre' => $_c['nombre_completo'],
                    ];
                    break 2;
                }
            }
        }
        unset($_c, $_cri);
    }
    ?>

    <?php if ($siguientePendiente): ?>
        <div class="calificaciones-pendiente" role="note">
            <span class="calificaciones-pendiente__icono" aria-hidden="true">→</span>
            <span class="calificaciones-pendiente__texto">
                Próximo pendiente:
                <strong><?= e($siguientePendiente['competencia_nombre']) ?></strong>
                <span class="calificaciones-pendiente__sep" aria-hidden="true">›</span>
                <strong><?= e($siguientePendiente['criterio_nombre']) ?></strong>
            </span>
            <button type="button"
                    class="btn btn--primary btn--sm calificaciones-pendiente__btn"
                    data-criterio-target="<?= $siguientePendiente['criterio_id'] ?>">
                Ir a este criterio
            </button>
        </div>
    <?php endif; ?>

    <?php $separadorAcademico = false; $separadorTransversal = false; ?>
    <?php foreach ($competencias as $competencia): ?>
        <?php
        $compBloqueada  = in_array($competencia['id'], $bloqueos ?? []);
        $esTransversal  = !empty($competencia['es_transversal']);

        // carga_id de ESTA competencia. En la vista de área (unidocente) cada
        // card pertenece a una subárea-carga distinta; en la vista de carga
        // única todas comparten $carga['id'] (fallback). Los endpoints de
        // guardar/confirmar/aprobar/resumen siguen siendo por carga.
        $compCargaId = (int) ($competencia['carga_id'] ?? $carga['id']);

        // ── Estado del botón de cabecera de la competencia ───────────
        //  1) Académica sin criterios            → "No se evaluó" (terminal)
        //  2) Con criterios, alguno pendiente    → "Ver resumen" BLOQUEADO
        //  3) ≥1 criterio y TODOS confirmados    → "Ver resumen" accesible
        // El desbloqueo NO lo da el autosave: solo el clic en "Confirmar" sella
        // `criterios.confirmado_en`. Editar u omitir un criterio confirmado lo
        // vuelve pendiente y re-bloquea el botón hasta re-Confirmar. Un criterio
        // vacío (sin confirmar) también lo mantiene bloqueado. El candado de
        // aprobación sigue viviendo dentro del resumen, en el botón "Aprobar".
        $criteriosLive    = $competencia['criterios'] ?? [];
        $tieneCriterios   = !empty($criteriosLive);
        $todosConfirmados = $tieneCriterios;
        foreach ($criteriosLive as $cr) {
            if (empty($cr['confirmado_en'])) { $todosConfirmados = false; break; }
        }
        $sinNotasBloqueada = $compBloqueada
            && (int) ($competencia['alumnos_calificados'] ?? 0) === 0;
        $resumenAccesible  = $compBloqueada || $todosConfirmados;
        // "No se evaluó" es una acción de escritura: no se ofrece en un periodo
        // bloqueado (igual que "Confirmar" y "Agregar criterio"). Tampoco se
        // ofrece si dejaría la carga sin ninguna calificación (piso de carga):
        // el docente debe evaluar al menos una competencia. El servidor lo
        // revalida; el director puede forzarlo desde el panel de bloqueos.
        $mostrarNoEvaluo   = !$esTransversal && !$compBloqueada
            && !$tieneCriterios && !$bloqueado
            && ($permiteNoEvaluar ?? true);

        // Estado inicial del card transversal: verde si ya tiene notas
        // guardadas en alguno de sus criterios (sin parpadeo al cargar; el JS
        // mantiene el color en vivo al editar/guardar). Espeja --con-notas del
        // criterio al card completo, solo para transversales.
        $transConNotas = false;
        if ($esTransversal) {
            foreach ($competencia['criterios'] ?? [] as $cr) {
                if (!empty($notasExistentes[$cr['id']])) {
                    $transConNotas = true;
                    break;
                }
            }
        }

        if (!$esTransversal && !$separadorAcademico) {
            $separadorAcademico = true; ?>
            <div class="academicas-separador">
                <h2 class="academicas-separador__titulo">Competencias del área</h2>
                <p class="academicas-separador__desc">
                    Las competencias oficiales del área. Registra sus criterios y notas,
                    y aprueba cada una para bloquearla.
                </p>
            </div>
        <?php }

        if ($esTransversal && !$separadorTransversal) {
            $separadorTransversal = true; ?>
            <div class="transversales-separador">
                <h2 class="transversales-separador__titulo">Competencias Transversales</h2>
                <p class="transversales-separador__desc">
                    TIC y GAMA se registran en cada carga igual que tus competencias
                    (criterios y notas) y se aprueban y bloquean por separado, desde el
                    resumen de cada una. Las conclusiones descriptivas las registra el
                    tutor de la sección al cierre del bimestre.
                </p>
            </div>
        <?php } ?>

        <div class="competencia-card<?= $esTransversal ? ' competencia-card--transversal' : ' competencia-card--academica' ?><?= $transConNotas ? ' competencia-card--con-notas' : '' ?>"
             id="comp-<?= $competencia['id'] ?>">

            <!-- Encabezado -->
            <div class="competencia-card__header">
                <div>
                    <span class="competencia-card__codigo">
                        <?= e($competencia['codigo_minedu'] ?? '') ?>
                    </span>
                    <?php if ($esTransversal): ?>
                        <span class="badge badge--info">Transversal</span>
                    <?php endif; ?>
                    <h3 class="competencia-card__nombre">
                        <?= e($competencia['nombre_completo']) ?>
                    </h3>
                </div>
                <div class="competencia-card__acciones">
                    <?php if ($compBloqueada): ?>
                        <?php if ($sinNotasBloqueada): ?>
                            <span class="badge badge--info">No evaluada</span>
                        <?php else: ?>
                            <span class="badge badge--error">🔒 Aprobada</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($mostrarNoEvaluo): ?>
                        <button type="button" class="btn btn--sm btn-no-evaluo"
                                data-carga-id="<?= $compCargaId ?>"
                                data-competencia-id="<?= $competencia['id'] ?>">
                            No se evaluó
                        </button>
                    <?php else: ?>
                        <a href="<?= url('docente/calificaciones/' . $compCargaId . '/resumen/' . $competencia['id']) ?>"
                           class="btn btn--secondary btn--sm<?= !$resumenAccesible ? ' btn-ver-resumen--bloqueado' : '' ?>"
                           <?= !$resumenAccesible ? 'tabindex="-1" aria-disabled="true" title="Confirma todos los criterios (sin pendientes ni vacíos) para acceder al resumen"' : '' ?>>
                            <span class="btn-icon btn-icon--view" aria-hidden="true"></span>
                            Ver resumen
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cuerpo -->
            <div class="competencia-card__body">

                <?php
                // Calificación extraordinaria de RA (18/09/2026): NO se pinta
                // como un criterio más, solo esta card informativa. El criterio
                // `extraordinario` sigue existiendo en los datos (boleta, SIAGIE
                // y promedio lo leen), pero no es parte del registro del docente.
                $extraordinarias = $extraordinariasPorComp[$compCargaId . '-' . (int) $competencia['id']] ?? [];
                require VIEW_PATH . '/docente/_extraordinaria-info.php';
                $criteriosOrdinarios = criterios_ordinarios($competencia['criterios'] ?? []);
                ?>

                <?php if ($compBloqueada): ?>
                    <!-- Mensaje de bloqueada — sin botón extra -->
                    <div class="flash flash--warning">
                        <?php if ($sinNotasBloqueada): ?>
                            Esta competencia se marcó como <strong>no evaluada</strong>
                            en este bimestre: se cierra sin notas y no aparece en la boleta.
                        <?php else: ?>
                            Esta competencia fue aprobada y bloqueada.
                            Las notas ya no pueden modificarse.
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Criterios disponibles -->
                    <?php if (empty($criteriosOrdinarios)): ?>
                        <p class="text-muted mb-md">
                            Sin criterios aún. Agrega uno para comenzar.
                        </p>
                    <?php else: ?>

                        <?php foreach ($criteriosOrdinarios as $ci => $criterio): ?>
                            <?php
                            $tieneCals       = !empty($notasExistentes[$criterio['id']]);
                            $calificadosCrit = count($notasExistentes[$criterio['id']] ?? []);
                            $completoCrit    = $calificadosCrit >= $totalAlumnos && $totalAlumnos > 0;
                            // Criterio de CALIFICACIÓN EXTRAORDINARIA (RA): solo
                            // lectura para el docente en cualquier estado — no se
                            // edita, elimina ni confirma; el servidor lo rechaza.
                            $esExtraCrit     = !empty($criterio['extraordinario']);
                            ?>
                            <div class="criterio-bloque<?= $tieneCals ? ' criterio-bloque--con-notas' : '' ?>"
                                 id="criterio-<?= $criterio['id'] ?>">

                                <div class="criterio-bloque__header">
                                    <div class="criterio-bloque__titulo">
                                        <?php if (!$compBloqueada && !$bloqueado): ?>
                                            <span class="criterio-bloque__estado<?= empty($criterio['confirmado_en']) ? ' criterio-bloque__estado--pendiente' : '' ?>"
                                                  title="Falta confirmar este criterio"
                                                  aria-hidden="true"></span>
                                        <?php endif; ?>
                                        <span class="criterio-bloque__caret"></span>
                                        <div class="criterio-bloque__identidad">
                                            <h4 class="criterio-bloque__nombre">
                                                <?= e($criterio['nombre']) ?>
                                                <?php if ($esExtraCrit): ?>
                                                    <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                                                <?php endif; ?>
                                            </h4>
                                            <?php if ($esExtraCrit): ?>
                                                <p class="criterio-bloque__descripcion criterio-bloque__descripcion--extra">
                                                    Esta calificación no forma parte de tu registro ordinario del
                                                    bimestre: fue ingresada por Registro Académico con autorización.
                                                    El motivo está registrado en el módulo de Rectificación
                                                    (visible en el resumen y el historial de la competencia).
                                                </p>
                                            <?php elseif (!empty($criterio['descripcion'])): ?>
                                                <p class="criterio-bloque__descripcion">
                                                    <?= e($criterio['descripcion']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="criterio-bloque__progreso<?= $completoCrit ? ' criterio-bloque__progreso--completo' : '' ?>"
                                              title="Alumnos con nota guardada">
                                            <?= $calificadosCrit ?> de <?= $totalAlumnos ?>
                                        </span>
                                    </div>
                                    <?php if (!$bloqueado && !$esExtraCrit): ?>
                                        <div class="criterio-bloque__acciones">
                                            <button
                                                class="btn btn--secondary btn--sm btn-renombrar-criterio"
                                                data-criterio-id="<?= $criterio['id'] ?>"
                                                data-descripcion="<?= e($criterio['descripcion'] ?? '') ?>">
                                                Editar
                                            </button>
                                            <button
                                                class="btn btn--danger btn--sm btn-eliminar-criterio"
                                                data-criterio-id="<?= $criterio['id'] ?>"
                                                data-nombre="<?= e($criterio['nombre']) ?>"
                                                data-tiene-calificaciones="<?= $tieneCals ? '1' : '0' ?>">
                                                Eliminar
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="criterio-bloque__content">
                                    <form class="form-notas"
                                          data-criterio-id="<?= $criterio['id'] ?>"
                                          data-competencia-id="<?= $competencia['id'] ?>"
                                          data-carga-id="<?= $compCargaId ?>">
                                        <?= csrf_field() ?>

                                        <div class="tabla-notas-wrapper">
                                        <table class="tabla-notas">
                                            <thead>
                                                <tr>
                                                    <th class="col-num">N°</th>
                                                    <th class="col-nombre">Apellidos y nombres</th>
                                                    <th class="text-center">Nota (0-20)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $exoneradosSet = array_flip($exonerados ?? []);
                                                foreach ($alumnos as $i => $alumno):
                                                    $esExonerado = isset($exoneradosSet[$alumno['matricula_id']]);
                                                ?>
                                                    <tr class="<?= $esExonerado ? 'fila-exonerado' : '' ?>">
                                                        <td class="col-num"><?= $i + 1 ?></td>
                                                        <td class="col-nombre"><?= e($alumno['nombre_completo']) ?></td>
                                                        <td class="text-center">
                                                            <?php if ($esExonerado): ?>
                                                                <span class="exo-badge"
                                                                      title="Alumno exonerado de esta área — no requiere nota">EXO</span>
                                                            <?php elseif ($esExtraCrit): ?>
                                                                <?php $notaExtra = $notasExistentes[$criterio['id']][$alumno['matricula_id']] ?? null; ?>
                                                                <span class="nota-extraordinaria"
                                                                      title="Calificación extraordinaria de Registro Académico — solo lectura">
                                                                    <?= $notaExtra !== null ? fmt_nota((int) $notaExtra) : '—' ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <?php $valorInicial = isset($notasExistentes[$criterio['id']][$alumno['matricula_id']]) ? fmt_nota((int)$notasExistentes[$criterio['id']][$alumno['matricula_id']]) : ''; ?>
                                                                <input
                                                                    type="text"
                                                                    inputmode="numeric"
                                                                    pattern="(0?[0-9]|1[0-9]|20)"
                                                                    class="input-nota"
                                                                    name="notas[<?= $alumno['matricula_id'] ?>]"
                                                                    maxlength="2"
                                                                    autocomplete="off"
                                                                    <?= $bloqueado ? 'disabled' : '' ?>
                                                                    placeholder="—"
                                                                    value="<?= $valorInicial ?>"
                                                                    data-nota-inicial="<?= $valorInicial ?>"
                                                                    data-matricula-id="<?= $alumno['matricula_id'] ?>"
                                                                >
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        </div>

                                        <?php if (!$bloqueado && !$esExtraCrit): ?>
                                            <div class="form-notas__footer">
                                                <button type="submit"
                                                        class="btn btn--primary">
                                                    <span class="btn-icon btn-icon--shield-check" aria-hidden="true"></span>
                                                    Confirmar
                                                </button>
                                                <span class="form-notas__status"></span>
                                            </div>
                                        <?php endif; ?>

                                    </form>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                    <!-- Agregar criterio — solo si no está bloqueado -->
                    <?php if (!$bloqueado): ?>
                        <div class="agregar-criterio">
                            <div class="agregar-criterio__fila">
                                <div class="agregar-criterio__campo">
                                    <input
                                        type="text"
                                        class="form-input input-nuevo-criterio"
                                        placeholder="Verbo observable + qué evalúa + condición o cualidad esperada"
                                        maxlength="100"
                                    >
                                    <span class="contador-chars" data-contador-de="nombre">0/100</span>
                                </div>
                                <button
                                    class="btn btn--primary btn-agregar-criterio"
                                    data-carga-id="<?= $compCargaId ?>"
                                    data-competencia-id="<?= $competencia['id'] ?>">
                                    + Agregar criterio
                                </button>
                            </div>
                            <textarea
                                class="form-input input-nuevo-criterio-desc"
                                placeholder="Descripción del criterio (opcional)"
                                rows="2"></textarea>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
                <!-- fin compBloqueada -->

            </div>

        </div>

    <?php endforeach; ?>

<?php endif; ?>

<!-- ── Modal de omisiones ────────────────────────────────────────
     Oculto por defecto. El JS lo puebla y lo muestra cuando el
     docente intenta guardar dejando alumnos sin calificar.
     Los selects usan los mismos valores ENUM de omisiones_criterio.
-->
<div id="omision-modal" class="omision-modal" hidden aria-modal="true" role="dialog" aria-labelledby="omision-modal-titulo">
    <div class="omision-modal__backdrop"></div>
    <div class="omision-modal__dialog">
        <div class="omision-modal__header">
            <h3 class="omision-modal__titulo" id="omision-modal-titulo">
                Alumnos sin calificar — registra el motivo
            </h3>
            <p class="omision-modal__desc">
                Los siguientes alumnos quedarán sin nota en este criterio.
                Al calcular el promedio, los campos vacíos
                <strong>no cuentan como cero</strong>: se excluyen del cálculo.
                Selecciona el motivo de cada uno para poder guardar.
            </p>
        </div>
        <div class="omision-modal__lista" id="omision-lista">
            <!-- Filas insertadas dinámicamente por JS -->
        </div>
        <div class="omision-modal__footer">
            <button type="button" class="btn btn--secondary" id="omision-cancelar">
                Cancelar
            </button>
            <button type="button" class="btn btn--primary" id="omision-confirmar" disabled>
                Confirmar y guardar
            </button>
            <span class="omision-modal__status" id="omision-status"></span>
        </div>
    </div>
</div>
