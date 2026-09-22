<?php
/**
 * Conducta — ETAPA 1 (Registro Academico): grilla de criterios Si/No.
 * Con selector de bimestre: el periodo editable se registra; los demas
 * (historial) se muestran en SOLO LECTURA y, si estan aprobados y
 * bloqueados, permiten imprimir la copia oficial del registro.
 *
 * @var array       $seccion      { id, grado_nombre, seccion_nombre, nivel_nombre, nivel_id }
 * @var array|null  $periodoVer   periodo mostrado { id, nombre_display, editable, ... } o null
 * @var array       $periodosNav  periodos del año activo + ['cierre' => cierre vigente|null]
 * @var bool        $soloLectura  true = periodo no editable (historial)
 * @var array       $criterios    [{ id, texto, orden }]
 * @var array       $estudiantes  [{ matricula_id, nombre_completo, respuestas[criterio_id] }]
 * @var array       $legado       [{ matricula_id, nombre_completo, literal }] — solo
 *                                bimestre legado (literal directo) en solo lectura
 * @var array|null  $cierre       cierre vigente del periodo mostrado o null
 * @var array       $completitud  { esperados, completos }
 */

$csrfToken = \Core\Session::csrfToken();
$total     = count($criterios);
$bloqueada = $cierre !== null;
$cerradaT  = $bloqueada && !empty($cierre['tutor_cerrado_en']);
$completo  = $completitud['esperados'] > 0 && $completitud['completos'] >= $completitud['esperados'];
$pidVer    = $periodoVer ? (int) $periodoVer['id'] : 0;
?>

<div class="page-header">
    <a href="<?= url('admin/conducta') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">
            Conducta — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            <?php if ($periodoVer): ?>
                · <strong><?= e($periodoVer['nombre_display']) ?></strong>
                <?php if ($soloLectura): ?> · Solo lectura<?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($periodosNav)): ?>
    <nav class="periodo-tabs" aria-label="Bimestres">
        <?php foreach ($periodosNav as $p):
            $esActual = (int) $p['id'] === $pidVer;
            $editable = (bool) $p['editable'];
        ?>
            <a href="<?= url('admin/conducta/' . (int) $seccion['id'] . '?periodo=' . (int) $p['id']) ?>"
               class="periodo-tab<?= $esActual ? ' periodo-tab--activa' : '' ?>"
               <?= $esActual ? 'aria-current="page"' : '' ?>>
                <span class="periodo-tab__nombre"><?= e($p['nombre_display']) ?></span>
                <?php if ($editable): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--curso">En curso</span>
                <?php elseif ($p['cierre']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--bloqueado">Aprobado</span>
                <?php else: ?>
                    <span class="periodo-tab__estado">Sin cierre</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<div id="conducta-feedback" class="conducta-feedback" hidden role="status" aria-live="polite"></div>

<?php if (!$periodoVer): ?>
    <div class="empty-state"><p>No hay periodo abierto para edición. Selecciona un bimestre del historial.</p></div>
<?php elseif (empty($estudiantes)): ?>
    <div class="empty-state"><p>No hay estudiantes matriculados en esta sección.</p></div>
<?php elseif (empty($criterios)): ?>
    <div class="empty-state"><p>No hay criterios de conducta configurados para este nivel.</p></div>
<?php else: ?>

<?php
// Bimestre legado (B1): se registro con literal directo, sin matriz de criterios.
$hayRespuestas = false;
foreach ($estudiantes as $est) {
    if (!empty($est['respuestas'])) {
        $hayRespuestas = true;
        break;
    }
}
?>

<?php if ($bloqueada): ?>
    <div class="alert alert--<?= $cerradaT ? 'success' : 'info' ?>">
        <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
        <span>
            <?php if ($cerradaT): ?>
                Conducta <strong>bloqueada y aprobada por el tutor(a)</strong>
                el <?= e(fechaLima($cierre['tutor_cerrado_en'])) ?>.
            <?php else: ?>
                Conducta <strong>bloqueada y aprobada por Registro Académico</strong>
                el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?>. En espera del cierre del tutor.
            <?php endif; ?>
            Para corregir, solicita el desbloqueo a Dirección.
        </span>
        <?php if ($hayRespuestas): ?>
            <a href="<?= url('admin/conducta/' . (int) $seccion['id'] . '/imprimir/' . $pidVer) ?>"
               target="_blank" rel="noopener" class="btn btn--secondary btn--sm alert__accion">
                🖨 Imprimir registro
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($soloLectura && !$hayRespuestas): ?>

    <?php if (!empty($legado)): ?>

        <div class="alert alert--info">
            <span>Este bimestre se registró con el modelo anterior: calificación
            literal directa, sin matriz de criterios. Se muestra el literal
            registrado por alumno.</span>
        </div>

        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th class="col-num">N°</th>
                        <th class="col-nombre">Apellidos y Nombres</th>
                        <th>Conducta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($legado as $idx => $fila): ?>
                        <tr>
                            <td class="col-num"><?= $idx + 1 ?></td>
                            <td class="col-nombre"><?= e($fila['nombre_completo']) ?></td>
                            <td>
                                <?php if ($fila['literal'] !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($fila['literal']) ?>">
                                        <?= e($fila['literal']) ?>
                                    </span>
                                    <?php if (!empty($fila['extraordinaria'])): ?>
                                        <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted" title="Sin registro en este bimestre">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <div class="empty-state">
            <p>Este bimestre se registró con el modelo anterior (calificación literal
            directa), por lo que no hay una matriz de criterios que mostrar.</p>
        </div>

    <?php endif; ?>

<?php else: ?>

<details class="conducta-criterios-leyenda">
    <summary>Ver los <?= $total ?> criterios (✓ = cumple · ✗ = no cumple)</summary>
    <ol class="conducta-criterios-lista">
        <?php foreach ($criterios as $c): ?>
            <li><?= e($c['texto']) ?></li>
        <?php endforeach; ?>
    </ol>
</details>

<?php if (!$bloqueada && !$soloLectura): ?>
    <div class="conducta-toolbar">
        <button type="button" id="conducta-autollenar" class="btn btn--secondary btn--sm"
                title="Marcar Sí en los criterios sin responder (no cambia las excepciones)">
            <span class="btn-icon btn-icon--saveall" aria-hidden="true"></span> Marcar Sí
        </button>
        <button type="button" id="conducta-guardar-todos" class="btn btn--primary btn--sm"
                title="Guardar todas las filas pendientes">
            <span class="btn-icon btn-icon--save" aria-hidden="true"></span> Guardar todo
        </button>
        <span class="conducta-toolbar__hint text-muted">
            “Marcar Sí” rellena solo lo que falte.
        </span>
    </div>
<?php endif; ?>

<div class="tabla-notas-wrapper conducta-scroll">
    <table class="tabla-notas conducta-grilla">
        <thead>
            <tr>
                <th class="col-num">N°</th>
                <th class="col-nombre">Apellidos y Nombres</th>
                <?php // El codigo sale del DATO (migracion 056), nunca de la posicion:
                      // reordenar o dar de baja un criterio corria las etiquetas de
                      // esta grilla respecto del imprimible ya firmado. ?>
                <?php foreach ($criterios as $c): ?>
                    <th class="conducta-th-crit" title="<?= e($c['texto']) ?>"><?= e($c['codigo']) ?></th>
                <?php endforeach; ?>
                <th class="conducta-th-nota" title="Nota de Registro Académico (Sí ÷ <?= $total ?> × 20)">Nota</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $idx => $est):
                $resp     = $est['respuestas'];
                $guardado = count($resp) >= $total;
                // En solo lectura la nota RA se calcula en el servidor (sin JS).
                $notaRa = $litRa = null;
                if ($soloLectura && $total > 0 && count($resp) >= $total) {
                    $si     = count(array_filter($resp, fn($v) => (int) $v === 1));
                    $notaRa = (int) round(($si / $total) * 20, 0, PHP_ROUND_HALF_UP);
                    $litRa  = nota_a_literal($notaRa);
                }
            ?>
                <tr class="conducta-fila <?= $guardado ? 'conducta-fila--guardada' : 'conducta-fila--pendiente' ?>"
                    data-matricula="<?= (int) $est['matricula_id'] ?>"
                    data-periodo="<?= $pidVer ?>"
                    data-csrf="<?= e($csrfToken) ?>"
                    data-total="<?= $total ?>">
                    <td class="col-num"><?= $idx + 1 ?></td>
                    <td class="col-nombre"><?= e($est['nombre_completo']) ?></td>

                    <?php foreach ($criterios as $c):
                        $val = $resp[(int) $c['id']] ?? null; // null | 0 | 1
                    ?>
                        <td class="conducta-td-crit">
                            <div class="cc-toggle" data-criterio="<?= (int) $c['id'] ?>"
                                 data-valor="<?= $val === null ? '' : (int) $val ?>"
                                 role="group" aria-label="Criterio para <?= e($est['nombre_completo']) ?>">
                                <button type="button" class="cc-btn cc-btn--si<?= $val === 1 ? ' cc-btn--activo' : '' ?>"
                                        data-v="1" title="Cumple" aria-label="Cumple" <?= ($bloqueada || $soloLectura) ? 'disabled' : '' ?>>✓</button>
                                <button type="button" class="cc-btn cc-btn--no<?= $val === 0 ? ' cc-btn--activo' : '' ?>"
                                        data-v="0" title="No cumple" aria-label="No cumple" <?= ($bloqueada || $soloLectura) ? 'disabled' : '' ?>>✗</button>
                            </div>
                        </td>
                    <?php endforeach; ?>

                    <td class="conducta-td-nota">
                        <?php if ($soloLectura): ?>
                            <?php if ($notaRa !== null): ?>
                                <span class="nota-numeral nota-numeral--<?= strtolower($litRa) ?>">
                                    <?= fmt_nota($notaRa) ?>
                                </span>
                            <?php elseif (empty($resp) && !empty($est['literal_directo'])): ?>
                                <?php // Conducta por literal directo, sin matriz: la via
                                      // extraordinaria de un bimestre cerrado (migracion 063). ?>
                                <span class="nota-literal nota-literal--<?= strtolower($est['literal_directo']) ?>">
                                    <?= e($est['literal_directo']) ?>
                                </span>
                                <?php if (!empty($est['extraordinaria'])): ?>
                                    <?php $proc = PROCEDENCIA_EXTRAORDINARIA; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" title="Registro incompleto">—</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="cc-nota">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!$bloqueada && !$soloLectura): ?>
    <form method="post" action="<?= url('admin/conducta/' . (int) $seccion['id'] . '/bloquear') ?>"
          class="conducta-bloqueo-form"
          onsubmit="return confirm('¿Bloquear y aprobar la conducta de toda la sección? Después solo Dirección podrá desbloquearla.');">
        <?= csrf_field() ?>
        <div class="conducta-bloqueo-info">
            Completos: <strong><?= $completitud['completos'] ?>/<?= $completitud['esperados'] ?></strong>
            <?php if (!$completo): ?>
                <span class="text-muted">— faltan estudiantes por calificar</span>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn--success" <?= $completo ? '' : 'disabled' ?>>
            <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>Bloquear y aprobar
        </button>
    </form>
<?php endif; ?>

<?php endif; ?>

<?php endif; ?>
