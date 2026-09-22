<?php
/**
 * Tabla compacta de CALIFICACIONES EXTRAORDINARIAS de UNA competencia
 * (22/09/2026). Punto único del marcado de las filas: lo incluyen la sección
 * única de la carga (`consulta-notas/_extraordinarias-carga.php`) y la card de
 * la grilla/resumen del docente (`docente/_extraordinaria-info.php`).
 *
 * Quién registró, cuándo y por qué se dicen UNA vez por grupo, no en cada
 * alumno: en Ética del I Bimestre 2026 las 23-29 notas de la sección comparten
 * los tres datos y la lista los repetía en cada renglón. Si una competencia
 * mezcla motivos (carga 432: 28 + 2), sale una tabla por grupo, el más grande
 * primero; nunca se repite un motivo ni se atribuye a quien no corresponde.
 *
 * @var array $filasEx filas normalizadas: estudiante, nota, registrador,
 *                     rectificado_en, motivo (orden alfabético del alumno)
 */
$gruposEx = [];
foreach ($filasEx as $exFila) {
    $fechaEx = !empty($exFila['rectificado_en']) ? substr((string) $exFila['rectificado_en'], 0, 10) : '';
    $claveEx = ($exFila['registrador'] ?? '') . '|' . $fechaEx . '|' . trim((string) ($exFila['motivo'] ?? ''));
    $gruposEx[$claveEx]['fecha']       = $fechaEx;
    $gruposEx[$claveEx]['registrador'] = $exFila['registrador'] ?? '';
    $gruposEx[$claveEx]['motivo']      = trim((string) ($exFila['motivo'] ?? ''));
    $gruposEx[$claveEx]['filas'][]     = $exFila;
}
// usort es estable (PHP 8): a igual tamaño se conserva el orden de aparición.
$gruposEx = array_values($gruposEx);
usort($gruposEx, fn($a, $b) => count($b['filas']) <=> count($a['filas']));
?>
<?php foreach ($gruposEx as $grupoEx): ?>
    <?php $totalEx = count($grupoEx['filas']); ?>
    <p class="extraordinaria-info__comun">
        <strong><?= $totalEx ?> <?= $totalEx === 1 ? 'nota' : 'notas' ?></strong>
        <?php if ($grupoEx['fecha'] !== ''): ?>
            · <?= $totalEx === 1 ? 'Registrada' : 'Registradas' ?> por
            <?= e($grupoEx['registrador'] ?: 'Registro Académico') ?>
            el <?= e(fecha_es($grupoEx['fecha'])) ?>
        <?php endif; ?>
        <?php if ($grupoEx['motivo'] !== ''): ?>
            <span class="extraordinaria-info__motivo">Motivo: <?= e($grupoEx['motivo']) ?></span>
        <?php endif; ?>
    </p>
    <div class="tabla-responsive extraordinaria-info__tabla">
        <table class="tabla-resumen">
            <thead>
                <tr>
                    <th class="col-num">N°</th>
                    <th class="col-nombre">Apellidos y nombres</th>
                    <th class="col-numeral text-center">Nota</th>
                    <th class="col-literal text-center">Literal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grupoEx['filas'] as $iEx => $filaEx): ?>
                    <?php
                    $notaEx    = (int) $filaEx['nota'];
                    $literalEx = nota_a_literal($notaEx);
                    ?>
                    <tr>
                        <td class="col-num"><?= $iEx + 1 ?></td>
                        <td class="col-nombre"><?= e($filaEx['estudiante']) ?></td>
                        <td class="col-numeral text-center">
                            <span class="nota-numeral nota-numeral--<?= strtolower($literalEx) ?>">
                                <?= fmt_nota($notaEx) ?>
                            </span>
                        </td>
                        <td class="col-literal text-center">
                            <span class="nota-literal nota-literal--<?= strtolower($literalEx) ?>">
                                <?= e($literalEx) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>
