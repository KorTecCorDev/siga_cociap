<?php
/**
 * Select del MOTIVO de una FJ/TJ (29/09/2026). Lo comparten la grilla mensual
 * y la vista por estudiante; `asistencia-fechas.js` lo ancla a la celda.
 * Las opciones salen del CATÁLOGO (`AsistenciaMotivoModel::vigentes`), no de
 * una lista copiada en el JS.
 *
 * @var array $motivos [{id, nombre}]
 */
?>
<div id="af-motivo" class="af-motivo" hidden role="dialog" aria-label="Motivo de la justificación">
    <label class="form-label" for="af-motivo-select">Motivo de la justificación</label>
    <select id="af-motivo-select" class="form-select">
        <option value="">— Elige el motivo —</option>
        <?php foreach ($motivos as $mo): ?>
            <option value="<?= (int) $mo['id'] ?>"><?= e($mo['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn--secondary btn--sm af-motivo__cerrar">Cerrar</button>
</div>
