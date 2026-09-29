<?php
/**
 * Justificaciones — LISTAS DE ESTUDIANTES (29/09/2026). Compartido por la
 * pantalla y el A4.
 *
 *   1. Los que MÁS SE AUSENTAN contando también las faltas justificadas, por
 *      sección (los 3 primeros con sus empates), con su motivo más frecuente.
 *   2. Alerta de control: 5 o más justificaciones y más de la mitad verbales.
 *
 * 🔴 SON INDICADORES INFORMATIVOS, como `_top-incidencias.php`: sin umbrales de
 * sanción, sin rojo y sin la palabra «riesgo». Aquí hay personas, no procesos.
 * Una tabla por sección con su rótulo en <caption> (mismo patrón y porqué que
 * `_top-incidencias.php`).
 *
 * @var array $just  bloque `justificaciones` de componerBloques()
 */
?>
<?php if (empty($just['top'])): ?>
    <p class="empty-state">Ningún estudiante tiene faltas confirmadas en este bimestre.</p>
<?php else: ?>
    <?php foreach ($just['top'] as $s): ?>
        <div class="cuadros-top__bloque">
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas cuadros-top">
                    <caption class="cuadros-top__caption"><?= e($s['etq']) ?></caption>
                    <thead>
                        <tr>
                            <th class="col-nombre">Estudiante</th>
                            <th class="text-center cuadros-top__n">Ausencias</th>
                            <th class="text-center cuadros-top__n">F</th>
                            <th class="text-center cuadros-top__n">FJ</th>
                            <th class="text-center cuadros-top__n">T</th>
                            <th class="text-center cuadros-top__n">TJ</th>
                            <th>Motivo más frecuente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($s['alumnos'] as $al): ?>
                            <tr>
                                <td class="col-nombre"><?= e($al['nombre']) ?></td>
                                <td class="text-center cuadros-top__v--destaca"><?= (int) $al['ausencias'] ?></td>
                                <td class="text-center"><?= (int) $al['F'] ?></td>
                                <td class="text-center"><?= (int) $al['FJ'] ?></td>
                                <td class="text-center text-muted"><?= (int) $al['T'] ?></td>
                                <td class="text-center text-muted"><?= (int) $al['TJ'] ?></td>
                                <td><?= $al['motivo'] !== '' ? e($al['motivo']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="tabla-pie tabla-pie--suelto">
        <p class="text-sm text-muted">
            Indicador <strong>informativo</strong>. Ausencias = faltas sin justificar + faltas justificadas.
            Se listan los tres estudiantes con más ausencias de cada sección, ampliando el corte para no
            partir un empate en el último puesto.
        </p>
    </div>
<?php endif; ?>

<?php if (!empty($just['verbales'])): ?>
    <div class="cuadros-top__bloque">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas cuadros-top">
                <caption class="cuadros-top__caption">
                    Justificaciones mayoritariamente verbales
                    (<?= App\Models\AsistenciaEstadisticaModel::MIN_JUSTIFICACIONES_VERBALES ?> o más, más de la mitad verbales)
                </caption>
                <thead>
                    <tr>
                        <th class="col-nombre">Estudiante</th>
                        <th>Sección</th>
                        <th class="text-center cuadros-top__n">Justificaciones</th>
                        <th class="text-center cuadros-top__n">Verbales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($just['verbales'] as $v): ?>
                        <tr>
                            <td class="col-nombre"><?= e($v['nombre']) ?></td>
                            <td><?= e($v['etq']) ?></td>
                            <td class="text-center"><?= (int) $v['justificaciones'] ?></td>
                            <td class="text-center cuadros-top__v--destaca"><?= (int) $v['verbales'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
