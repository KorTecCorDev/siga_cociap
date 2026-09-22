<?php
/**
 * Conducta — ETAPA 2 (panel del tutor).
 *
 * @var array       $seccion       { id, nombre, grado_nombre, nivel_nombre, nivel_id }
 * @var array       $periodos      [{ id, numero, nombre_display, estado }]
 * @var array       $periodoSel    { id, nombre_display, estado }
 * @var array|null  $cierre        cierre vigente de RA o null (Etapa 1 no hecha)
 * @var array|null  $legadoInfo    { usuario, registrado_en } si el bimestre es
 *                                 legado (literal directo, sin matriz) o null
 * @var array       $estudiantes   [{ matricula_id, nombre_completo, nota_ra, nota_tutor, nota_final, literal_final }]
 * @var bool        $editable      true si se puede editar/cerrar
 * @var bool        $cerradoTutor  true si el tutor ya cerro
 */

$csrfToken = \Core\Session::csrfToken();
$pid       = (int) $periodoSel['id'];
?>

<div class="page-header">
    <a href="<?= url('docente/inicio') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title page-title--wf page-title--conducta">Conducta — Sección <?= e($seccion['nombre']) ?></h1>
        <p class="page-subtitle">
            <?= e($seccion['grado_nombre']) ?> <?= e($seccion['nivel_nombre']) ?>
            · <strong><?= e($periodoSel['nombre_display']) ?></strong>
        </p>
    </div>
</div>

<div class="conducta-bimestres">
    <?php foreach ($periodos as $p): ?>
        <a href="<?= url('docente/conducta/' . (int) $p['id']) ?>"
           class="conducta-bimestre-tab<?= (int) $p['id'] === $pid ? ' conducta-bimestre-tab--activo' : '' ?>">
            <?= e($p['nombre_display']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div id="conducta-feedback" class="conducta-feedback" hidden role="status" aria-live="polite"></div>

<?php if (!$cierre && !$legadoInfo): ?>

    <div class="conducta-espera">
        <div class="conducta-espera__icono" aria-hidden="true"><span class="btn-icon btn-icon--bigwait"></span></div>
        <h2 class="conducta-espera__titulo">Conducta pendiente de registro</h2>
        <p class="conducta-espera__texto">
            Todavía los auxiliares académicos no han registrado sus calificaciones de conducta
            de esta sección para el <strong><?= e($periodoSel['nombre_display']) ?></strong>.
            Consulte con Registro Académico para más información.
        </p>
    </div>

<?php elseif (empty($estudiantes)): ?>

    <div class="empty-state"><p>No hay estudiantes matriculados en esta sección.</p></div>

<?php else: ?>

    <?php if ($legadoInfo): ?>
        <div class="alert alert--info">
            <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
            <span>
                Las calificaciones de conducta fueron registradas por
                <strong><?= e($legadoInfo['usuario']) ?></strong>
                el <?= e(fechaLima($legadoInfo['registrado_en'])) ?>,
                los criterios de conducta fueron implementados en el siguiente bimestre.
            </span>
        </div>
    <?php elseif ($cerradoTutor): ?>
        <div class="alert alert--success">
            <span>
                Conducta <strong>bloqueada y aprobada</strong> el <?= e(fechaLima($cierre['tutor_cerrado_en'])) ?>.
                Para modificarla, solicita el desbloqueo a Registro Académico.
            </span>
        </div>
    <?php else: ?>
        <div class="alert alert--info">
            <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
            <span>
                Se aprobó las notas de conducta el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?>.
                Puedes agregar tu nota (00–20).
                Cuando termines, <strong>bloquea y aprueba</strong>.
            </span>
        </div>
    <?php endif; ?>

    <!-- Detalle de los criterios Si/No de los auxiliares, en solo lectura
         (07/07/2026). Solo con cierre vigente de RA y matriz (los bimestres
         legados no tienen criterios que consultar). -->
    <?php if (!$legadoInfo): ?>
        <div class="mb-md">
            <a href="<?= url('docente/conducta/' . $pid . '/criterios') ?>" class="btn btn--secondary btn--sm">
                Ver criterios evaluados por los auxiliares (lectura)
            </a>
        </div>
    <?php endif; ?>

    <div class="card mb-lg">
        <div class="card__header">
            <h2 class="card__title">Comportamiento</h2>
            <span class="competencia-card__codigo"><?= e($periodoSel['nombre_display']) ?></span>
        </div>

        <div class="tabla-notas-wrapper">
            <table class="tabla-notas conducta-tutor-tabla">
                <thead>
                    <tr>
                        <th class="col-num">N°</th>
                        <th class="col-nombre">Apellidos y nombres</th>
                        <th class="col-numeral text-center" title="Nota de Registro Académico">Nota del Auxiliar</th>
                        <th class="col-numeral text-center" title="Tu nota (opcional, 00–20)">Tu nota</th>
                        <th class="col-numeral col-resultado col-resultado--inicio text-center" title="Promedio final entre la nota del auxiliar y la tuya (.5 a favor, calculado)">Promedio numeral</th>
                        <th class="col-literal col-resultado text-center">Literal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estudiantes as $i => $est):
                        $esLegado = !empty($est['es_legado']);
                        $notaRa   = $est['nota_ra'];        // int|null
                        $notaFin  = $est['nota_final'];     // int|null
                        $litFin   = $est['literal_final'];  // string|null
                        $litRa    = $notaRa !== null ? nota_a_literal((int) $notaRa) : null;
                    ?>
                        <tr class="conducta-tutor-fila<?= $esLegado ? ' conducta-tutor-fila--legado' : '' ?>"
                            data-matricula="<?= (int) $est['matricula_id'] ?>"
                            data-periodo="<?= $pid ?>"
                            data-csrf="<?= e($csrfToken) ?>"
                            data-nota-ra="<?= $notaRa !== null ? (int) $notaRa : '' ?>">
                            <td class="col-num"><?= $i + 1 ?></td>
                            <td class="col-nombre"><?= e($est['nombre_completo']) ?></td>

                            <!-- Nota de Registro Académico (Auxiliar) -->
                            <td class="col-numeral text-center">
                                <?php if ($notaRa !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($litRa) ?>">
                                        <?= fmt_nota((int) $notaRa) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" title="Registro por literal directo, sin criterios">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Nota del tutor (editable / lectura) -->
                            <td class="col-numeral text-center">
                                <?php if ($editable && !$esLegado): ?>
                                    <input type="text" class="input-nota conducta-nota-tutor"
                                           inputmode="numeric" pattern="(0?[0-9]|1[0-9]|20)" maxlength="2"
                                           autocomplete="off"
                                           value="<?= $est['nota_tutor'] !== null ? fmt_nota((int) $est['nota_tutor']) : '' ?>"
                                           placeholder="—"
                                           aria-label="Tu nota para <?= e($est['nombre_completo']) ?>">
                                    <span class="conducta-status" aria-live="polite"></span>
                                <?php elseif (!$esLegado && $est['nota_tutor'] !== null):
                                    $litTut = nota_a_literal((int) $est['nota_tutor']); ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($litTut) ?>">
                                        <?= fmt_nota((int) $est['nota_tutor']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Numeral final (promedio) -->
                            <td class="col-numeral col-resultado col-resultado--inicio text-center">
                                <?php if ($notaFin !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($litFin) ?> conducta-final__nota">
                                        <?= fmt_nota((int) $notaFin) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Literal final -->
                            <td class="col-literal col-resultado text-center">
                                <?php if ($litFin !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($litFin) ?> conducta-final__lit"<?= $esLegado ? ' title="I Bimestre (legado)"' : '' ?>>
                                        <?= e($litFin) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($editable): ?>
            <form id="conducta-cerrar-form" class="resumen-footer"
                  data-action="<?= url('docente/conducta/' . $pid . '/cerrar') ?>"
                  data-csrf="<?= e($csrfToken) ?>">
                <button type="submit" class="btn btn--success">
                    <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>
                    Bloquear y aprobar
                </button>
                <span class="text-muted text-sm">Verifique correctamente los datos.</span>
            </form>
        <?php endif; ?>
    </div><!-- /.card -->

<?php endif; ?>
