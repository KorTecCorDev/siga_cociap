<?php
/**
 * Banda de «Estudiantes en riesgo» en `/admin/cuadros` y su A4 (23/09/2026).
 *
 * Es lo que quedó del bloque 3b cuando el listado se fue a su propia vista
 * (`/admin/cuadros/riesgo`): la cifra que un informe de Dirección necesita en
 * una línea. Forma PAR con `_banda-merito.php` (azul ↔ rojo; ver su comentario).
 *
 * «En riesgo» es la SITUACIÓN FINAL proyectada del MINEDU: los que requieren
 * recuperación (RR) más los que permanecen en el grado (PER). Las cifras salen
 * de `riesgo_resumen()`, el mismo punto único que usa la vista propia: el
 * tablero y el informe no pueden decir números distintos.
 *
 * @var array     $bloques
 * @var bool|null $riesgoEnlace  lo pone la PANTALLA; el A4 no lo define y no
 *                               emite el enlace (en papel sería un enlace muerto).
 */
$res = riesgo_resumen($bloques['situacion'] ?? []);
?>
<div class="cuadros-banda cuadros-banda--riesgo">
    <p class="cuadros-banda__cifra">
        <span class="cuadros-banda__n"><?= (int) $res['total'] ?></span>
        <span class="cuadros-banda__q">
            estudiante<?= $res['total'] !== 1 ? 's' : '' ?> en riesgo académico
            (<strong><?= (int) $res['pct'] ?>%</strong> de <?= (int) $res['evaluados'] ?> evaluados)
        </span>
    </p>
    <ul class="cuadros-banda__datos">
        <li><strong><?= (int) $res['permanencia'] ?></strong> permanecería<?= (int) $res['permanencia'] !== 1 ? 'n' : '' ?> en el grado</li>
        <li><strong><?= (int) $res['recuperacion'] ?></strong> requiere<?= (int) $res['recuperacion'] !== 1 ? 'n' : '' ?> recuperación</li>
        <li><strong><?= (int) $res['grados'] ?> de <?= (int) $res['grados_total'] ?></strong> grados con casos</li>
        <li><strong><?= (int) $res['seguros'] ?></strong> seguro<?= (int) $res['seguros'] !== 1 ? 's' : '' ?>
            &middot; <strong><?= (int) $res['proyectados'] ?></strong> proyectado<?= (int) $res['proyectados'] !== 1 ? 's' : '' ?></li>
    </ul>
    <?php // La cobertura NO es decoracion: en un bimestre a medio calificar las
          // areas sin nota no cuentan y la proyeccion sale optimista. Callarlo
          // convertiria un informe incompleto en una buena noticia. ?>
    <?php if (!$res['cobertura']['completa']): ?>
        <p class="cuadros-banda__nota">
            Proyección parcial: <strong><?= (int) $res['cobertura']['parciales'] ?></strong>
            estudiante<?= $res['cobertura']['parciales'] !== 1 ? 's' : '' ?> todavía con
            competencias de su plan sin calificar.
        </p>
    <?php endif; ?>
    <ul class="riesgo-banda__niveles">
        <?php foreach ($res['por_nivel'] as $niv): ?>
            <li>
                <strong><?= e($niv['nombre']) ?>:</strong>
                <?= (int) $niv['total'] ?> de <?= (int) $niv['evaluados'] ?> (<?= (int) $niv['pct'] ?>%)
                no alcanzaría<?= (int) $niv['total'] !== 1 ? 'n' : '' ?> la promoción<?php if ($niv['permanencia'] > 0): ?>,
                    de los cuales <?= (int) $niv['permanencia'] ?> permanecería<?= (int) $niv['permanencia'] !== 1 ? 'n' : '' ?> en el grado<?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($riesgoEnlace)): ?>
        <p class="riesgo-banda__enlace">
            <a href="<?= url('admin/cuadros/riesgo?periodo_id=' . (int) $periodo['id']) ?>" class="btn btn--secondary btn--sm">
                Ver el informe completo &rarr;
            </a>
        </p>
    <?php endif; ?>
</div>
