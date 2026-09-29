<?php
/**
 * Anexo de fechas (29/09/2026), en el orden de la lista. Dos usos, cada uno con
 * su clase de tabla:
 *   - consulta de Dirección, debajo de la tabla: «Detalle de justificaciones»,
 *     solo FJ/TJ (`AsistenciaModel::justificaciones`);
 *   - imprimible del registro, en hoja aparte: «Detalle de incidencias», las
 *     cuatro (`AsistenciaModel::detalleIncidencias`); F y T van con guion en
 *     el motivo.
 *
 * @var list<array{num:int, nombre:string, fecha:string, tipo:string, motivo:string}> $justificaciones
 * @var string $claseAnexo  clases de la <table> (impresión o pantalla)
 */
?>
<table class="<?= e($claseAnexo) ?>">
    <thead>
        <tr>
            <th class="tr-num">N&deg;</th>
            <th class="tr-nombre">Apellidos y Nombres</th>
            <th class="tr-anexo-fecha">Fecha</th>
            <th class="tr-anexo-tipo">Tipo</th>
            <th class="tr-anexo-motivo">Motivo</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($justificaciones as $j): ?>
            <tr>
                <td class="tr-num"><?= (int) $j['num'] ?></td>
                <td class="tr-nombre"><?= e($j['nombre']) ?></td>
                <td class="tr-anexo-fecha"><?= e($j['fecha']) ?></td>
                <td class="tr-anexo-tipo"><?= e($j['tipo']) ?></td>
                <td class="tr-anexo-motivo"><?= $j['motivo'] !== '' ? e($j['motivo']) : '&mdash;' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
