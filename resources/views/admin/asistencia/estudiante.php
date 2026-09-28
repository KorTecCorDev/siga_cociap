<?php
/**
 * Asistencia — ENTRADA POR ESTUDIANTE (28/09/2026). Segunda entrada del
 * registro, al lado de la grilla: un estudiante por pantalla, los 4 contadores
 * grandes con botones −/+, para el auxiliar que registra desde el celular.
 *
 * 🔴 NO TIENE JS DE GUARDADO PROPIO. El bloque `.asistencia-fila` respeta el
 * MISMO contrato de DOM que la grilla (`data-matricula-id`, `data-periodo-id`,
 * `data-csrf`, `.asistencia-input[name][data-inicial]`, `.asistencia-guardar`,
 * `.asistencia-status`; ver `_tabla-incidencias.php`), así que `asistencia.js`
 * lo maneja tal cual: saneamiento, «con cambios», Enter y guardado por el mismo
 * endpoint. `registro-estudiante.js` solo añade los −/+ y la navegación.
 *
 * @var array  $seccion      { id, grado_nombre, seccion_nombre, nivel_nombre }
 * @var array  $periodo      periodo editable en curso
 * @var array  $estudiantes  [{ matricula_id, nombre_completo, incidencias{...} }]
 * @var int    $pos          índice del estudiante mostrado en $estudiantes
 * @var string $siguienteUrl URL del siguiente estudiante, o la grilla tras el último
 * @var int    $topeMax      valor máximo por contador (espejo del backend)
 */

use App\Models\AsistenciaModel;

$csrfToken = \Core\Session::csrfToken();
$est       = $estudiantes[$pos];
$inc       = $est['incidencias'];
$ultimo    = $pos === count($estudiantes) - 1;
$sid       = (int) $seccion['id'];

// Mismas etiquetas que la leyenda de la grilla (`_tabla-incidencias.php`).
$etiquetas = [
    'faltas'                 => ['F',  'Faltas'],
    'faltas_justificadas'    => ['FJ', 'Faltas justificadas'],
    'tardanzas'              => ['T',  'Tardanzas'],
    'tardanzas_justificadas' => ['TJ', 'Tardanzas justificadas'],
];
?>

<div class="page-header">
    <a href="<?= url('admin/asistencia/' . $sid) ?>" class="btn btn--secondary btn--sm">← Grilla</a>
    <div>
        <h1 class="page-title">
            Asistencia — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            · <strong><?= e($periodo['nombre_display']) ?></strong>
            · Estudiante <?= $pos + 1 ?> de <?= count($estudiantes) ?>
        </p>
    </div>
</div>

<?php // Selector de estudiante: funciona sin JS (botón «Ir»); con JS cambia solo
      // al elegir y avisa si hay cambios sin guardar. ✓ = tiene registro guardado. ?>
<form method="get" action="<?= url('admin/asistencia/' . $sid . '/estudiante') ?>"
      class="registro-estudiante-selector" data-selector-estudiante>
    <label class="form-label" for="selector-estudiante">Estudiante</label>
    <select id="selector-estudiante" name="m" class="form-select">
        <?php foreach ($estudiantes as $i => $alumno): ?>
            <option value="<?= (int) $alumno['matricula_id'] ?>" <?= $i === $pos ? 'selected' : '' ?>>
                <?= $i + 1 ?>. <?= e($alumno['nombre_completo']) ?><?= !empty($alumno['incidencias']['registrado']) ? ' ✓' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn--secondary btn--sm">Ir</button>
</form>

<div id="asistencia-feedback" class="asistencia-feedback" hidden role="status" aria-live="polite"></div>

<section class="asistencia-fila registro-estudiante<?= !empty($inc['registrado']) ? ' asistencia-fila--registrada' : '' ?>"
         data-matricula-id="<?= (int) $est['matricula_id'] ?>"
         data-periodo-id="<?= (int) $periodo['id'] ?>"
         data-csrf="<?= e($csrfToken) ?>"
         data-siguiente="<?= e($siguienteUrl) ?>">

    <header class="registro-estudiante__cabecera">
        <h2 class="registro-estudiante__nombre"><?= e($est['nombre_completo']) ?></h2>
        <span class="asistencia-status" aria-live="polite"></span>
    </header>

    <div class="registro-estudiante__contadores">
        <?php foreach (AsistenciaModel::CAMPOS as $campo):
            $val = (int) $inc[$campo];
            [$corta, $larga] = $etiquetas[$campo]; ?>
            <div class="registro-estudiante__contador">
                <label class="registro-estudiante__etiqueta" for="c-<?= $campo ?>">
                    <strong><?= e($corta) ?></strong> <?= e($larga) ?>
                </label>
                <div class="registro-estudiante__stepper">
                    <button type="button" class="btn btn--secondary registro-estudiante__paso"
                            data-paso="-1" aria-label="Restar una en <?= e($larga) ?>">−</button>
                    <input type="number" id="c-<?= $campo ?>"
                           class="asistencia-input registro-estudiante__input"
                           name="<?= $campo ?>"
                           min="0"
                           max="<?= (int) $topeMax ?>"
                           step="1"
                           inputmode="numeric"
                           autocomplete="off"
                           value="<?= $val ?>"
                           data-inicial="<?= $val ?>">
                    <button type="button" class="btn btn--secondary registro-estudiante__paso"
                            data-paso="1" aria-label="Sumar una en <?= e($larga) ?>">+</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="registro-estudiante__acciones">
        <button type="button" class="btn btn--secondary asistencia-guardar">
            <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Guardar
        </button>
        <button type="button" class="btn btn--primary" data-accion="siguiente">
            <?= $ultimo ? 'Guardar y volver a la grilla' : 'Guardar y siguiente →' ?>
        </button>
    </div>
</section>
