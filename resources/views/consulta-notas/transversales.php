<?php
/**
 * Consulta — agregado TRANSVERSAL de una seccion (solo lectura).
 * Es lo que el tutor congelo al cerrar y lo que llega a la boleta; el crudo por
 * docente se ve dentro de cada carga.
 *
 * @var array $periodo
 * @var array $seccion       [seccion_id, seccion_nombre, grado_nombre, nivel_nombre, nivel_codigo]
 * @var array $cierre        cierre vigente (cerrado_en, cerrado_por_nombre)
 * @var array $competencias  TIC/GAMA del nivel
 * @var array $alumnos       roster [{matricula_id, nombre_completo}]
 * @var array $promedios     [matricula_id => [competencia_id => nota]]
 * @var array $conclusiones  [matricula_id => [competencia_id => texto]]
 */
$nivel = $seccion['nivel_codigo'] === 'prim' ? 'primaria' : 'secundaria';
?>

<div class="page-header">
    <a href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id']) ?>"
       class="btn btn--secondary btn--sm">← Áreas</a>
    <div>
        <?php // El h1 dice lo MISMO que el enlace que trae aqui (consulta-notas/seccion.php).
              // Si dijera "Competencias Transversales" a secas, coincidiria con el separador
              // del registro por carga, que es justo el dato distinto. ?>
        <h1 class="page-title">Promedios de Competencias Transversales</h1>
        <p class="page-subtitle">
            <?= e($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']) ?> ·
            <?= e($seccion['nivel_nombre']) ?> ·
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?>
        </p>
    </div>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<div class="flash flash--info">
    Promedio de todas las cargas de la sección, aprobado y bloqueado por el tutor:
    es el valor que aparece en la boleta. Cerrado el
    <strong><?= fechaLima($cierre['cerrado_en'], 'd/m/Y H:i') ?></strong>
    por <?= e($cierre['cerrado_por_nombre'] ?? '—') ?>.
</div>

<?php if (empty($alumnos)): ?>
    <div class="empty-state"><p>La sección no tiene estudiantes en el roster.</p></div>
<?php else: ?>
<div class="card mb-lg">
    <div class="tabla-notas-wrapper">
        <table class="tabla-resumen tutoria-tabla">
            <thead>
                <tr>
                    <th class="col-num" rowspan="2">N°</th>
                    <th class="col-nombre" rowspan="2">Apellidos y nombres</th>
                    <?php foreach ($competencias as $comp): ?>
                        <th class="th-competencia col-resultado col-resultado--inicio text-center"
                            colspan="2" title="<?= e($comp['nombre_completo']) ?>">
                            <?= e($comp['nombre_corto'] ?? $comp['codigo_minedu']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="col-conclusion" rowspan="2">Conclusiones descriptivas</th>
                </tr>
                <tr>
                    <?php foreach ($competencias as $comp): ?>
                        <th class="col-numeral col-resultado col-resultado--inicio text-center">Numeral</th>
                        <th class="col-literal col-resultado text-center">Literal</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnos as $i => $alumno): ?>
                    <?php $matId = (int) $alumno['matricula_id']; ?>
                    <tr>
                        <td class="col-num"><?= $i + 1 ?></td>
                        <td class="col-nombre"><?= e($alumno['nombre_completo']) ?>
                            <?php $sitChips = $alumno['situaciones'] ?? []; require VIEW_PATH . '/shared/_situacion-chips.php'; ?></td>

                        <?php foreach ($competencias as $comp): ?>
                            <?php
                            $cid     = (int) $comp['id'];
                            $nota    = $promedios[$matId][$cid] ?? null;
                            $literal = $nota !== null ? nota_a_literal((int) $nota, $nivel) : null;
                            ?>
                            <td class="col-numeral col-resultado col-resultado--inicio text-center">
                                <?php if ($nota !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($literal) ?>">
                                        <?= fmt_nota((int) $nota) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-literal col-resultado text-center">
                                <?php if ($literal !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($literal) ?>">
                                        <?= $literal ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>

                        <td class="col-conclusion">
                            <?php $algunTexto = false; ?>
                            <?php foreach ($competencias as $comp): ?>
                                <?php
                                $cid   = (int) $comp['id'];
                                $texto = $conclusiones[$matId][$cid] ?? '';
                                if ($texto === '') { continue; }
                                $algunTexto = true;
                                ?>
                                <p class="conclusion-texto">
                                    <strong><?= e($comp['nombre_corto'] ?? $comp['codigo_minedu']) ?>:</strong>
                                    <?= e($texto) ?>
                                </p>
                            <?php endforeach; ?>
                            <?php if (!$algunTexto): ?>
                                <span class="text-muted text-sm">— sin conclusión</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
