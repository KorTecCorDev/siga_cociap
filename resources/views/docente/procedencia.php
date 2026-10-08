<?php
/**
 * CAMBIO DE SECCIÓN — calificaciones de la sección ANTERIOR, SOLO LECTURA
 * (fase 3, 07/10/2026; docs/modulos/cambio-seccion.md, decisiones 2, 4, 5 y 15).
 *
 * Por cada carga que el docente le dicta HOY al estudiante, dos bloques que NO
 * se mezclan, en este orden:
 *  1. OFICIALES — bimestres cerrados cursados en otra sección: las competencias
 *     bloqueadas de la carga equivalente de origen, con el detalle por criterio
 *     (reutiliza consulta-notas/_tabla.php, filtrada a este estudiante).
 *  2. REFERENCIA (NO oficial) — lo que tenía registrado en el bimestre en curso
 *     al cambiar de sección; quedó archivado y no cuenta para nada.
 *
 * @var array $estudiante  nombre_completo, grado_nombre, seccion_nombre
 * @var array $porCarga    [{carga, oficiales[], referencia{cambio, competencias}}]
 * @var bool  $gestiona    admin/RA (vuelve a la matrícula, no a Mis cargas)
 */
$mid = (int) $estudiante['id'];
?>

<div class="page-header">
    <a href="<?= url($gestiona ? 'matriculas/' . $mid : 'docente/mis-cargas') ?>"
       class="btn btn--secondary btn--sm">← <?= $gestiona ? 'Matrícula' : 'Mis cargas' ?></a>
    <div>
        <h1 class="page-title">
            Calificaciones de su sección anterior
            <?php $proc = PROCEDENCIA_OTRA_SECCION; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
        </h1>
        <p class="page-subtitle">
            <?= e($estudiante['nombre_completo']) ?> ·
            hoy en <?= e($estudiante['grado_nombre'] . ' ' . $estudiante['seccion_nombre']) ?>
        </p>
    </div>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<div class="flash flash--warning">
    El estudiante cambió de sección. Aquí ves, en <strong>solo lectura</strong>, lo que le
    calificaron en su sección anterior: primero las calificaciones <strong>oficiales</strong>
    de los bimestres cerrados y, al final, como <strong>referencia no oficial</strong>, lo que
    tenía registrado en el bimestre en curso cuando cambió. En tu sección se le califica
    desde cero.
</div>

<?php foreach ($porCarga as $pc): ?>
    <?php
    $carga       = $pc['carga'];
    $nivelCodigo = $carga['nivel_codigo'];
    $ref         = $pc['referencia'];
    $hayAlgo     = $pc['oficiales'] !== [] || $ref['competencias'] !== [];
    ?>
    <div class="card mb-lg" id="carga-<?= (int) $carga['id'] ?>">
        <div class="card__header">
            <h2 class="card__title"><?= e($carga['nombre_display'] ?? $carga['area_nombre']) ?></h2>
        </div>
        <div class="card__body">
            <?php if (!$hayAlgo): ?>
                <p class="text-muted">No tiene calificaciones de su sección anterior en esta área.</p>
            <?php endif; ?>

            <?php foreach ($pc['oficiales'] as $of): ?>
                <h3 class="procedencia__periodo">
                    <?= e($of['periodo']) ?> · cursado en
                    <?= e($estudiante['grado_nombre'] . ' ' . $of['seccion']) ?>
                    <?php if (!empty($of['docente'])): ?>
                        · <span class="text-muted">docente: <?= e($of['docente']) ?></span>
                    <?php endif; ?>
                </h3>
                <?php if ($of['bloques'] === []): ?>
                    <p class="text-muted">Sin competencias oficiales registradas en ese bimestre.</p>
                <?php endif; ?>
                <?php foreach ($of['bloques'] as $bloque): ?>
                    <?php
                    $competencia = $bloque['competencia'];
                    $criterios   = $bloque['criterios'];
                    $alumnos     = $bloque['alumnos'];
                    $exonerados  = $of['exonerados'];
                    $periodo     = ['nombre_display' => $of['periodo']];
                    // Una sola fila: las estadísticas («100 % en AD») confundirían.
                    // Con el array vacío _stats-competencia.php no pinta nada.
                    $statsAlumnos = [];
                    ?>
                    <div class="procedencia__competencia">
                        <p class="procedencia__competencia-titulo">
                            <?= e($competencia['nombre_completo']) ?>
                            <span class="competencia-card__codigo"><?= e($competencia['codigo_minedu'] ?? '') ?></span>
                        </p>
                        <?php require VIEW_PATH . '/consulta-notas/_tabla.php'; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php if ($ref['competencias'] !== []): ?>
                <div class="procedencia-referencia">
                    <p class="procedencia-referencia__titulo">
                        Referencia NO oficial · <?= e($ref['cambio']['periodo_nombre']) ?> en
                        <?= e($estudiante['grado_nombre'] . ' ' . $ref['cambio']['origen_nombre']) ?>
                    </p>
                    <p class="procedencia-referencia__nota">
                        Es lo que tenía registrado cuando cambió de sección. Quedó archivado:
                        no va a la boleta ni cuenta para nada.
                    </p>
                    <?php foreach ($ref['competencias'] as $rc): ?>
                        <p class="procedencia__competencia-titulo">
                            <?= e($rc['competencia']['nombre_completo'] ?? '') ?>
                            <span class="competencia-card__codigo"><?= e($rc['competencia']['codigo_minedu'] ?? '') ?></span>
                        </p>
                        <div class="tabla-responsive">
                            <table class="tabla-resumen">
                                <thead>
                                    <tr>
                                        <th>Criterio</th>
                                        <th class="text-center">Nota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rc['criterios'] as $cr): ?>
                                    <tr>
                                        <td><?= e($cr['nombre']) ?></td>
                                        <td class="text-center">
                                            <?php if ($cr['nota'] !== null): ?>
                                                <?= fmt_nota((int) $cr['nota']) ?>
                                            <?php else: ?>
                                                <span class="omision-badge"
                                                      title="<?= e(\App\Models\OmisionCriterioModel::etiqueta($cr['omision'])) ?>">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($rc['promedio'] !== null): ?>
                                    <tr>
                                        <td><strong>Promedio</strong></td>
                                        <td class="text-center"><strong><?= fmt_nota((int) $rc['promedio']) ?></strong></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($rc['conclusion'])): ?>
                            <p class="procedencia-referencia__nota">Conclusión: <?= e($rc['conclusion']) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
