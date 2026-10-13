<?php
/**
 * Tabla «Por motivo» de las JUSTIFICACIONES (13/10/2026), compartida por la vista
 * de la sección y el reporte del auxiliar. Cuenta lo REGISTRADO con cada motivo;
 * el motivo principal lleva su marca.
 *
 * Encabezados con las pastillas de la pantalla (13/10/2026). «Faltas» lleva las
 * DOS FJ (con y sin documento) porque la columna junta los dos efectos: la fila
 * del motivo principal justifica y las demás cuentan como falta. Una sola FJ sin
 * icono diría «cuentan como falta» para toda la columna (ver la leyenda).
 *
 * @var array       $motivosTabla     [{motivo, ancla, fj, tj, total}] de
 *                                    resumenJustificaciones() o consolidarJustificaciones()
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 */
?>
<div class="tabla-notas-wrapper">
    <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
        <thead>
            <tr>
                <th class="tr-anexo-motivo">Motivo</th>
                <th class="tr-anexo-cifra">
                    <?php foreach (['fj_autorizadas', 'fj_como_f'] as $cat): ?>
                        <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                    <?php endforeach; ?>
                    <span class="just-seccion__rotulo">Faltas</span>
                </th>
                <th class="tr-anexo-cifra">
                    <?php $cat = 'tj'; require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                    <span class="just-seccion__rotulo">Tardanzas</span>
                </th>
                <th class="tr-anexo-tipo">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($motivosTabla as $m): ?>
                <tr>
                    <?php // Motivo principal (13/10/2026, pedido del usuario): se IDENTIFICA, no
                          // se escribe. Nombre en negrita + el icono de documento de la
                          // grilla, las pastillas y las fichas; la leyenda lo explica. La
                          // etiqueta accesible conserva el texto para lector y mouse. ?>
                    <td class="tr-anexo-motivo<?= $m['ancla'] ? ' just-motivo--ancla' : '' ?>">
                        <?= $m['motivo'] !== '' ? e($m['motivo']) : '&mdash;' ?>
                        <?php if ($m['ancla']): ?>
                            <span class="just-motivo__doc" role="img" aria-label="Motivo principal" title="Motivo principal"></span>
                        <?php endif; ?>
                    </td>
                    <td class="tr-anexo-cifra"><?= (int) $m['fj'] ?></td>
                    <td class="tr-anexo-cifra"><?= (int) $m['tj'] ?></td>
                    <td class="tr-anexo-tipo"><?= (int) $m['total'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
