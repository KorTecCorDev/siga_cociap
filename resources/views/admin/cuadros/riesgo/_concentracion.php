<?php
/**
 * Concentración de casos por área, docente y competencia, POR NIVEL
 * (23/09/2026; extraída de `_resumen.php` para reutilizarla en el bloque por
 * tutor, `_seccion.php`). Cuenta SOLO a los estudiantes de la lista recibida:
 * es «dónde se concentran los casos», no la distribución de notas.
 *
 * @var array $riesgo  con `stats` (de `riesgo_estadisticas()`)
 */
$st  = $riesgo['stats'];
$res = $st['resumen'];

// Barra de proporción: `$n` sobre `$de`, redondeada a entero para la clase
// (sin `style` inline; ver `_resumen.php`).
$barra ??= static function (int $n, int $de): string {
    $p = $de > 0 ? (int) round($n / $de * 100) : 0;
    return '<span class="riesgo-barra" aria-hidden="true"><span class="riesgo-barra__v riesgo-barra__v--'
         . max(0, min(100, $p)) . '"></span></span>';
};
?>
<?php // ── Concentración por área, competencia y docente, POR NIVEL ──────
      // Por nivel porque lo que se lista lo es: son las competencias NO
      // aprobatorias de cada nivel (primaria B y C, secundaria solo C).
      // Mezclarlas sumaría cosas distintas. ?>
<?php foreach ($res['por_nivel'] as $niv):
    $nid     = (int) $niv['nivel_id'];
    // En secundaria la B aprueba, así que no hay columna B que mostrar.
    $conB    = !nota_es_aprobatoria('B', (string) $niv['codigo']);
    $totNiv  = (int) $niv['total'];
    $areas   = $st['areas'][$nid] ?? [];
    $comps   = $st['competencias'][$nid] ?? [];
    $docs    = $st['docentes'][$nid] ?? [];
    if ($totNiv === 0) { continue; }
?>
    <section class="riesgo-conc">
        <h3 class="riesgo-conc__titulo">
            Concentración de casos &middot; <?= e($niv['nombre']) ?>
            <span>(<?= $totNiv ?> estudiantes; se cuentan sus competencias
            <?= $conB ? 'en B o C' : 'en C' ?>)</span>
        </h3>

        <div class="riesgo-resumen__par">
            <table class="riesgo-stat">
                <caption>Por área</caption>
                <thead>
                    <tr>
                        <th scope="col">Área</th>
                        <th scope="col" class="riesgo-stat__barra">Estudiantes</th>
                        <?php if ($conB): ?><th scope="col" class="riesgo-stat__n">B</th><?php endif; ?>
                        <th scope="col" class="riesgo-stat__n">C</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($areas as $a): ?>
                        <tr>
                            <th scope="row"><?= e($a['nombre']) ?></th>
                            <td class="riesgo-stat__barra"><?= $barra($a['estudiantes'], $totNiv) ?> <?= (int) $a['estudiantes'] ?></td>
                            <?php if ($conB): ?><td class="riesgo-stat__n"><?= (int) $a['B'] ?></td><?php endif; ?>
                            <td class="riesgo-stat__n"><?= (int) $a['C'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <table class="riesgo-stat">
                <caption>Por docente</caption>
                <thead>
                    <tr>
                        <th scope="col">Docente</th>
                        <th scope="col" class="riesgo-stat__barra">Estudiantes</th>
                        <?php if ($conB): ?><th scope="col" class="riesgo-stat__n">B</th><?php endif; ?>
                        <th scope="col" class="riesgo-stat__n">C</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $d): ?>
                        <tr>
                            <th scope="row"><?= e($d['nombre']) ?></th>
                            <td class="riesgo-stat__barra"><?= $barra($d['estudiantes'], $totNiv) ?> <?= (int) $d['estudiantes'] ?></td>
                            <?php if ($conB): ?><td class="riesgo-stat__n"><?= (int) $d['B'] ?></td><?php endif; ?>
                            <td class="riesgo-stat__n"><?= (int) $d['C'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <table class="riesgo-stat riesgo-stat--ancha">
            <caption>Las <?= count($comps) ?> competencias con más casos</caption>
            <thead>
                <tr>
                    <th scope="col">Competencia</th>
                    <th scope="col">Área · curso</th>
                    <th scope="col" class="riesgo-stat__barra">Estudiantes</th>
                    <?php if ($conB): ?><th scope="col" class="riesgo-stat__n">B</th><?php endif; ?>
                    <th scope="col" class="riesgo-stat__n">C</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comps as $c): ?>
                    <tr>
                        <th scope="row">
                            <?php if ($c['codigo'] !== null): ?><span class="riesgo-cod"><?= e($c['codigo']) ?></span> <?php endif; ?>
                            <?= e($c['nombre']) ?>
                        </th>
                        <td><?= e($c['area']) ?><?php if ($c['curso'] !== null): ?> <span class="riesgo-cur">&middot; <?= e($c['curso']) ?></span><?php endif; ?></td>
                        <td class="riesgo-stat__barra"><?= $barra($c['estudiantes'], $totNiv) ?> <?= (int) $c['estudiantes'] ?></td>
                        <?php if ($conB): ?><td class="riesgo-stat__n"><?= (int) $c['B'] ?></td><?php endif; ?>
                        <td class="riesgo-stat__n"><?= (int) $c['C'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>

