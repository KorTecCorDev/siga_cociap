<?php
/**
 * MENÚ de una celda de asistencia por fechas (30/09/2026; reemplaza al select
 * suelto del motivo). Lo comparten la grilla mensual y la vista por estudiante;
 * `asistencia-fechas.js` lo ancla a la celda tocada.
 *
 *   - «✓ Asistió», «F» y «T» guardan al tocarlos y cierran.
 *   - «FJ» y «TJ» NO guardan: muestran el motivo y «Guardar» se habilita solo con
 *     uno elegido. Una justificada sin motivo no existe (CHECK de la migración 070).
 *   - «Cancelar», tocar fuera o Esc no cambian NADA: la celda queda como estaba.
 *
 * Las opciones del motivo salen del CATÁLOGO (`AsistenciaMotivoModel::vigentes`),
 * no de una lista copiada en el JS. La opción vacía es solo un rótulo: no se
 * puede elegir ni guardar.
 *
 * @var array $motivos [{id, nombre}]
 */

$opcionesMenu = [
    ''   => ['Asistió',              'asistio'],
    'F'  => ['Falta',                'f'],
    'T'  => ['Tardanza',             't'],
    'FJ' => ['Falta justificada',    'fj'],
    'TJ' => ['Tardanza justificada', 'tj'],
];
?>
<div id="af-menu" class="af-menu" hidden role="dialog" aria-labelledby="af-menu-titulo">
    <p id="af-menu-titulo" class="af-menu__titulo"></p>
    <div class="af-menu__opciones">
        <?php foreach ($opcionesMenu as $tipo => [$nombre, $clase]): ?>
            <button type="button" class="af-menu__op af-menu__op--<?= $clase ?>" data-tipo="<?= e($tipo) ?>">
                <span class="af-tipo af-tipo--<?= $clase ?>"><?= $tipo === '' ? '✓' : e($tipo) ?></span>
                <?= e($nombre) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="af-menu__motivo" hidden>
        <label class="form-label" for="af-menu-motivo">Motivo de la justificación</label>
        <select id="af-menu-motivo" class="form-select">
            <option value="" disabled selected>— Elige el motivo —</option>
            <?php foreach ($motivos as $mo): ?>
                <option value="<?= (int) $mo['id'] ?>"<?= !empty($mo['es_principal']) ? ' data-principal="1"' : '' ?>><?= e($mo['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn--primary btn--sm af-menu__guardar" disabled>Guardar</button>
    </div>
    <button type="button" class="btn btn--secondary btn--sm af-menu__cancelar">Cancelar</button>
</div>
