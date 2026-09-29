<?php
/**
 * Planilla de asistencia manual — página de selección (28/09/2026).
 * `Documentos\DocumentoController::planillaAsistencia`. Solo admin, RA y Dirección.
 *
 * @var array|null $periodo      bimestre activo {id, nombre_display, anio, fecha_inicio, fecha_fin}
 * @var array      $secciones    AsistenciaModel::listarSeccionesActivas()
 * @var array      $estudiantes  seccion_id => n.º de estudiantes (roster de la planilla)
 * @var array      $meses        'AAAA-MM' => {anio, mes, nombre}
 * @var int        $filasExcel   filas de estudiantes que admite el Excel
 */
$nivelActual = null;
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Planilla de asistencia</h1>
        <p class="page-subtitle">
            Planilla por días para registrar la asistencia a mano, en PDF o Excel.
            <?php if ($periodo): ?>
                · <?= e($periodo['nombre_display']) ?> <?= e((string) $periodo['anio']) ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$periodo): ?>
    <div class="alert alert--warning">
        <span>No hay un bimestre en curso. La planilla se genera durante un bimestre abierto.</span>
    </div>
<?php else: ?>

    <div class="card">
        <div class="card__body">
            <form method="GET" action="<?= url('documentos/planilla-asistencia/generar') ?>" id="formPlanilla"
                  data-filas-excel="<?= (int) $filasExcel ?>">
                <div class="form-grid">

                    <div class="form-group">
                        <label class="form-label" for="seccion">Sección <span class="text-danger">*</span></label>
                        <select id="seccion" name="seccion" class="form-input" required>
                            <option value="">Elige una sección</option>
                            <?php foreach ($secciones as $s):
                                $n = (int) ($estudiantes[(int) $s['id']] ?? 0); ?>
                                <?php if ($s['nivel_nombre'] !== $nivelActual): ?>
                                    <?php if ($nivelActual !== null): ?></optgroup><?php endif; ?>
                                    <optgroup label="<?= e($s['nivel_nombre']) ?>">
                                    <?php $nivelActual = $s['nivel_nombre']; ?>
                                <?php endif; ?>
                                <option value="<?= (int) $s['id'] ?>" data-estudiantes="<?= $n ?>">
                                    <?= e($s['grado_nombre'] . ' ' . $s['seccion_nombre']) ?>
                                    · <?= $n ?> <?= $n === 1 ? 'estudiante' : 'estudiantes' ?><?= $n > $filasExcel ? ' (solo PDF)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($nivelActual !== null): ?></optgroup><?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="modo">Días</label>
                        <select id="modo" name="modo" class="form-input">
                            <option value="blanco">En blanco (los días se escriben a mano)</option>
                            <option value="mes">Con las fechas hábiles de un mes</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="mes">Mes</label>
                        <select id="mes" name="mes" class="form-input" disabled>
                            <?php foreach ($meses as $clave => $m): ?>
                                <option value="<?= e($clave) ?>"><?= e($m['nombre'] . ' ' . $m['anio']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="form-hint">
                        Lista a los estudiantes que se registran en Asistencia, en orden alfabético.
                        El Excel admite hasta <?= (int) $filasExcel ?> estudiantes; el PDF no tiene límite.
                    </p>
                    <div class="form-group--full" id="planillaAviso" hidden>
                        <div class="alert alert--warning">
                            <span>Esta sección tiene más de <?= (int) $filasExcel ?> estudiantes: descarga el PDF.</span>
                        </div>
                    </div>
                </div>

                <div class="form-actions planilla-acciones">
                    <button type="submit" name="formato" value="pdf" formtarget="_blank" class="btn btn--primary">
                        Ver PDF
                    </button>
                    <button type="submit" name="formato" value="xlsx" class="btn btn--secondary" id="btnExcel">
                        Descargar Excel
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>
