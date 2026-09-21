<?php
/**
 * NOTAS DEL COLEGIO DE ORIGEN — captura en lote.
 *
 * El Informe de Progreso del colegio anterior llega como un documento entero,
 * así que se transcribe entero: el formulario nace con varias filas y se pueden
 * añadir más. Las filas en blanco se descartan sin error.
 *
 * ⚠️ Estas notas NO van a la boleta del COCIAP (regla del colegio): son
 * informativas para los docentes con carga en la sección del estudiante. El
 * caso contrario —notas NUESTRAS que no se registraron a tiempo— va por la
 * calificación extraordinaria, desde /rectificaciones.
 *
 * @var array $matricula
 * @var array $notas            ya registradas
 * @var array $areas            áreas del plan de SU sección, para el mapeo opcional
 * @var array $curricula        áreas con sus competencias, para la card de importar
 * @var array $periodos         bimestres del año, para elegir cuáles importar
 * @var array $filasImportadas  filas pre-rellenadas por el importador (puede estar vacío)
 */
$mid             = (int) $matricula['id'];
$filasImportadas = $filasImportadas ?? [];
$curricula       = $curricula ?? [];
$periodos        = $periodos ?? [];

// Filas del formulario: primero las IMPORTADAS —pre-rellenadas pero igual de
// editables que cualquier otra— y después unas cuantas en blanco, que es como
// nace la pantalla cuando no se importa nada. Es el mismo formulario en los dos
// casos: por eso el camino manual para una currícula extranjera sigue intacto.
$filas = $filasImportadas;
for ($i = 0, $blancas = ($filasImportadas === [] ? 6 : 3); $i < $blancas; $i++) {
    $filas[] = ['periodo_nombre' => '', 'area_nombre' => '', 'area_id' => 0, 'competencia_nombre' => ''];
}
?>

<div class="page-header">
    <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary btn--sm">← Ver matrícula</a>
    <div>
        <h1 class="page-title">
            Notas del colegio de origen
            <?php $proc = PROCEDENCIA_ORIGEN; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
        </h1>
        <p class="page-subtitle"><?= e($matricula['nombre_completo']) ?></p>
    </div>
</div>

<div class="flash flash--warning">
    Estas calificaciones <strong>no aparecen en la boleta del COCIAP</strong> ni cuentan
    para el orden de mérito: el Informe de Progreso que emite el colegio lleva solo lo
    cursado aquí. Sirven para que los <strong>docentes con carga en su sección</strong>
    sepan con qué llega el estudiante, y se les avisa al guardarlas.
    <br>
    Si lo que necesitas es registrar notas que <strong>sí se evaluaron en el COCIAP</strong>
    y no llegaron a ingresarse, eso va por
    <a href="<?= url('rectificaciones/matricula/' . $mid) ?>">Rectificar o completar calificaciones</a>.
</div>

<?php // ── Importar de nuestra currícula ────────────────────────────────
      // Va ANTES del formulario a propósito: importar lo recarga, así que el
      // orden natural es elegir primero y teclear las notas después. ?>
<?php if ($curricula !== [] && $periodos !== []): ?>
<div class="card mb-md notas-origen__importar">
    <div class="card__body">
        <p class="form-section-title">Importar de nuestra currícula</p>
        <p class="text-muted text-sm">
            La mayoría de colegios sigue el mismo <strong>Currículo Nacional del MINEDU</strong>
            que el COCIAP, así que puedes traer sus áreas y competencias ya escritas y
            limitarte a poner las notas. Todo lo importado queda <strong>editable</strong>.
            <br>
            Si el estudiante viene de una currícula distinta —otro país, por ejemplo—,
            ignora esta card y usa el formulario de abajo: acepta cualquier plan.
        </p>

        <form method="GET" action="<?= url('matriculas/' . $mid . '/notas-externas') ?>"
              data-importar-form>
            <input type="hidden" name="importar" value="1">

            <p class="form-section-title">1. Bimestres</p>
            <div class="notas-origen__casillas">
                <?php foreach ($periodos as $p): ?>
                    <label class="notas-origen__casilla">
                        <input type="checkbox" name="periodos[]" value="<?= (int) $p['id'] ?>">
                        <span>
                            <?= e($p['nombre_display']) ?>
                            <?php if ($p['estado'] !== 'cerrado'): ?>
                                <span class="text-muted text-sm">(<?= e($p['estado']) ?>)</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="form-section-title">2. Áreas</p>
            <div class="btn-group mb-md">
                <button type="button" class="btn btn--secondary btn--sm" data-areas-todas>Marcar todas</button>
                <button type="button" class="btn btn--secondary btn--sm" data-areas-ninguna>Ninguna</button>
            </div>
            <div class="notas-origen__casillas">
                <?php foreach ($curricula as $area): ?>
                    <label class="notas-origen__casilla">
                        <input type="checkbox" name="areas[]" value="<?= (int) $area['area_id'] ?>"
                               data-area-casilla checked>
                        <span>
                            <?= e($area['area_nombre']) ?>
                            <span class="text-muted text-sm">
                                (<?= count($area['competencias']) ?>)
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flash flash--warning mt-md">
                Al traer las competencias <strong>se recarga el formulario</strong> y se pierde
                lo que hayas tecleado. Importa primero y escribe las notas después.
            </div>

            <div class="btn-group form-actions">
                <button type="submit" class="btn btn--primary">Traer competencias</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="<?= url('matriculas/' . $mid . '/notas-externas') ?>"
      id="notasOrigenForm" class="notas-origen">
    <?= csrf_field() ?>

    <div class="card mb-md">
        <div class="card__body">
            <div class="form-group">
                <label class="form-label" for="colegio_origen">Colegio de origen</label>
                <input type="text" id="colegio_origen" name="colegio_origen" class="form-input"
                       maxlength="<?= \App\Models\NotaExternaModel::MAX_COLEGIO ?>" value="<?= e($notas[0]['colegio_origen'] ?? '') ?>"
                       placeholder="Nombre de la institución educativa anterior">
                <p class="text-sm text-muted">Se aplica a todas las filas de este envío.</p>
            </div>
        </div>
    </div>

    <div class="card mb-md">
        <div class="card__body">
            <p class="form-section-title">Calificaciones del informe de origen</p>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas notas-origen__tabla">
                    <thead>
                        <tr>
                            <th>Periodo</th>
                            <th>Área (como la llama el otro colegio)</th>
                            <th>Competencia</th>
                            <th>Nota</th>
                            <th>Área de nuestro plan</th>
                        </tr>
                    </thead>
                    <tbody data-notas-origen-cuerpo>
                        <?php foreach ($filas as $f): ?>
                        <tr class="notas-origen__fila">
                            <td>
                                <input type="text" name="periodo_nombre[]" class="form-input"
                                       maxlength="<?= \App\Models\NotaExternaModel::MAX_PERIODO ?>" placeholder="I Bimestre"
                                       value="<?= e($f['periodo_nombre']) ?>"
                                       aria-label="Periodo">
                            </td>
                            <td>
                                <input type="text" name="area_nombre[]" class="form-input"
                                       maxlength="<?= \App\Models\NotaExternaModel::MAX_AREA ?>" placeholder="Matemática"
                                       value="<?= e($f['area_nombre']) ?>"
                                       aria-label="Área del colegio de origen">
                            </td>
                            <td>
                                <input type="text" name="competencia_nombre[]" class="form-input"
                                       maxlength="<?= \App\Models\NotaExternaModel::MAX_COMPETENCIA ?>" placeholder="Resuelve problemas de cantidad"
                                       value="<?= e($f['competencia_nombre']) ?>"
                                       aria-label="Competencia">
                            </td>
                            <td>
                                <select name="nota_literal[]" class="form-input" aria-label="Nota literal">
                                    <option value="">—</option>
                                    <option value="AD">AD</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                </select>
                            </td>
                            <td>
                                <select name="area_id[]" class="form-input" aria-label="Área de nuestro plan">
                                    <option value="">Sin mapear</option>
                                    <?php foreach ($areas as $a): ?>
                                        <option value="<?= (int) $a['id'] ?>"
                                            <?= (int) $f['area_id'] === (int) $a['id'] ? ' selected' : '' ?>>
                                            <?= e($a['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-muted mt-md">
                El <strong>área de nuestro plan</strong> es opcional: solo sirve para
                resaltarle la fila al docente que dicta esa área. Si el otro colegio
                trabaja algo sin equivalente aquí, déjalo sin mapear.
            </p>

            <div class="btn-group">
                <button type="button" class="btn btn--secondary btn--sm" data-notas-origen-agregar>
                    + Añadir fila
                </button>
            </div>
        </div>
    </div>

    <div class="btn-group form-actions">
        <a href="<?= url('matriculas/' . $mid) ?>" class="btn btn--secondary">Cancelar</a>
        <button type="submit" class="btn btn--primary">Guardar notas de origen</button>
    </div>
</form>

<div class="card">
    <div class="card__body">
        <p class="form-section-title">Ya registradas (<?= count($notas) ?>)</p>
        <?php if (empty($notas)): ?>
            <div class="empty-state"><p>Todavía no hay notas del colegio de origen.</p></div>
        <?php else: ?>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead>
                        <tr>
                            <th>Periodo</th><th>Área (origen)</th><th>Competencia</th>
                            <th class="text-center">Nota</th><th>Área nuestra</th><th>Colegio origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notas as $n): ?>
                        <tr>
                            <td class="text-sm"><?= e($n['periodo_nombre']) ?></td>
                            <td class="text-sm"><?= e($n['area_nombre']) ?></td>
                            <td class="text-sm"><?= e($n['competencia_nombre']) ?></td>
                            <td class="text-center"><span class="matricula-badge matricula-badge--nuevo"><?= e($n['nota_literal']) ?></span></td>
                            <td class="text-sm text-muted"><?= e($n['area_mapeada_boleta'] ?: $n['area_mapeada'] ?: 'Sin mapear') ?></td>
                            <td class="text-sm text-muted"><?= e($n['colegio_origen'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-muted mt-md">
                Volver a guardar la misma competencia y periodo <strong>reemplaza</strong> la
                nota anterior; no se duplica.
            </p>
        <?php endif; ?>
    </div>
</div>
