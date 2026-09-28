<?php
/**
 * Conducta — ENTRADA POR ESTUDIANTE (28/09/2026). Segunda entrada del registro,
 * al lado de la grilla: un estudiante por pantalla, los criterios uno bajo otro
 * y botones grandes, para el auxiliar que registra desde el celular.
 *
 * 🔴 NO TIENE JS DE GUARDADO PROPIO. El bloque `.conducta-fila` respeta el
 * MISMO contrato de DOM que la grilla (`data-matricula`, `data-periodo`,
 * `data-csrf`, `data-total`, `.cc-toggle[data-criterio][data-valor]`,
 * `.cc-btn[data-v]`, `.cc-nota`, `.conducta-status`), así que `conducta.js`
 * lo maneja tal cual: nota en vivo, Sí/No, «Marcar Sí» y guardado por el mismo
 * endpoint. `registro-estudiante.js` solo añade la navegación. Cambiar esos
 * nombres rompe las DOS entradas.
 *
 * @var array  $seccion      { id, grado_nombre, seccion_nombre, nivel_nombre, nivel_id }
 * @var array  $periodo      periodo editable en curso
 * @var array  $criterios    [{ id, codigo, texto, orden }]
 * @var array  $estudiantes  [{ matricula_id, nombre_completo, respuestas[criterio_id] }]
 * @var int    $pos          índice del estudiante mostrado en $estudiantes
 * @var string $siguienteUrl URL del siguiente estudiante, o la grilla tras el último
 */

$csrfToken = \Core\Session::csrfToken();
$total     = count($criterios);
$est       = $estudiantes[$pos];
$resp      = $est['respuestas'];
$ultimo    = $pos === count($estudiantes) - 1;
$sid       = (int) $seccion['id'];
?>

<div class="page-header">
    <a href="<?= url('admin/conducta/' . $sid) ?>" class="btn btn--secondary btn--sm">← Grilla</a>
    <div>
        <h1 class="page-title">
            Conducta — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            · <strong><?= e($periodo['nombre_display']) ?></strong>
            · Estudiante <?= $pos + 1 ?> de <?= count($estudiantes) ?>
        </p>
    </div>
</div>

<?php // Selector de estudiante: funciona sin JS (botón «Ir»); con JS cambia solo
      // al elegir y avisa si hay marcas sin guardar. ✓ = registro completo. ?>
<form method="get" action="<?= url('admin/conducta/' . $sid . '/estudiante') ?>"
      class="registro-estudiante-selector" data-selector-estudiante>
    <label class="form-label" for="selector-estudiante">Estudiante</label>
    <select id="selector-estudiante" name="m" class="form-select">
        <?php foreach ($estudiantes as $i => $alumno):
            $completo = count($alumno['respuestas']) >= $total; ?>
            <option value="<?= (int) $alumno['matricula_id'] ?>" <?= $i === $pos ? 'selected' : '' ?>>
                <?= $i + 1 ?>. <?= e($alumno['nombre_completo']) ?><?= $completo ? ' ✓' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn--secondary btn--sm">Ir</button>
</form>

<div id="conducta-feedback" class="conducta-feedback" hidden role="status" aria-live="polite"></div>

<section class="conducta-fila registro-estudiante <?= count($resp) >= $total ? 'conducta-fila--guardada' : 'conducta-fila--pendiente' ?>"
         data-matricula="<?= (int) $est['matricula_id'] ?>"
         data-periodo="<?= (int) $periodo['id'] ?>"
         data-csrf="<?= e($csrfToken) ?>"
         data-total="<?= $total ?>"
         data-siguiente="<?= e($siguienteUrl) ?>">

    <header class="registro-estudiante__cabecera">
        <h2 class="registro-estudiante__nombre"><?= e($est['nombre_completo']) ?></h2>
        <div class="registro-estudiante__nota">
            Nota: <span class="cc-nota">—</span>
            <span class="conducta-status" aria-live="polite"></span>
        </div>
    </header>

    <ol class="registro-estudiante__criterios">
        <?php foreach ($criterios as $c):
            $val = $resp[(int) $c['id']] ?? null; // null | 0 | 1
        ?>
            <li class="registro-estudiante__criterio">
                <span class="registro-estudiante__texto">
                    <span class="competencia-card__codigo"><?= e($c['codigo']) ?></span>
                    <?= e($c['texto']) ?>
                </span>
                <div class="cc-toggle cc-toggle--grande" data-criterio="<?= (int) $c['id'] ?>"
                     data-valor="<?= $val === null ? '' : (int) $val ?>"
                     role="group" aria-label="<?= e($c['codigo']) ?>: <?= e($c['texto']) ?>">
                    <button type="button" class="cc-btn cc-btn--si<?= $val === 1 ? ' cc-btn--activo' : '' ?>"
                            data-v="1" aria-pressed="<?= $val === 1 ? 'true' : 'false' ?>">✓ Sí</button>
                    <button type="button" class="cc-btn cc-btn--no<?= $val === 0 ? ' cc-btn--activo' : '' ?>"
                            data-v="0" aria-pressed="<?= $val === 0 ? 'true' : 'false' ?>">✗ No</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <div class="registro-estudiante__acciones">
        <button type="button" id="conducta-autollenar" class="btn btn--secondary"
                title="Marcar Sí en los criterios sin responder (no cambia los ya marcados)">
            <span class="btn-icon btn-icon--saveall" aria-hidden="true"></span> Marcar Sí
        </button>
        <button type="button" class="btn btn--secondary conducta-guardar" data-accion="guardar">
            <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Guardar
        </button>
        <button type="button" class="btn btn--primary" data-accion="siguiente">
            <?= $ultimo ? 'Guardar y volver a la grilla' : 'Guardar y siguiente →' ?>
        </button>
    </div>
</section>
