<?php
/**
 * Ranking por SECCIÓN (read-only). Ranking interno de cada sección. NO otorga
 * media beca: esa solo la define el orden de mérito del GRADO.
 *
 * SIRVE A DOS MÓDULOS con la misma mecánica (patrón de `archivar.php`), y la
 * diferencia son solo las URLs de vuelta y del ranking por grado:
 *   - DOCENTE  (`/docente/ranking-seccion/{p}`)  — claustro, bajo la compuerta
 *     de publicación 044: solo ve los niveles ya publicados.
 *   - STAFF    (`/director/ranking-seccion/{p}`) — admin/RA/directores, SIN
 *     compuerta: la necesitan antes de publicar, para decidir.
 * Los defaults conservan el comportamiento del docente, que es quien la
 * estrenó: una vista sin `$rutaBase` sigue funcionando igual que antes.
 *
 * @var array  $periodo
 * @var array  $ranking     [grado_id => ['grado'=>..., 'secciones'=>[sec=>[...]]]]
 * @var string $rutaBase      ruta del selector (botón Volver)
 * @var string $rutaMerito    ruta del orden de mérito por grado del mismo módulo
 * @var bool   $provisional   periodo aún ABIERTO: el cálculo es en vivo y se mueve
 * @var bool   $hayPendientes hay empates sin resolver (solo lo pasa el staff:
 *                            es quien puede resolverlos, el docente no)
 */
$rutaBase      = $rutaBase      ?? 'docente/ranking-seccion';
$rutaMerito    = $rutaMerito    ?? 'docente/orden-merito';
$provisional   = $provisional   ?? false;
$hayPendientes = $hayPendientes ?? false;
?>

<div class="page-header">
    <a href="<?= url($rutaBase) ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title page-title--wf page-title--ranking">Ranking <span class="merito-tag merito-tag--seccion">por sección</span></h1>
        <p class="page-subtitle"><?= e($periodo['nombre_display']) ?> — <?= e($periodo['anio']) ?></p>
    </div>
</div>

<div class="merito-aviso merito-aviso--seccion">
    Ranking <strong>interno de cada sección</strong>. Ser el 1.° de la sección
    <strong>NO otorga media beca</strong>: esa solo la obtiene el 1.° del grado.
    Para la media beca, mira el
    <a href="<?= url($rutaMerito . '/' . $periodo['id']) ?>">Orden de mérito del grado</a>.
</div>

<?php if ($provisional): ?>
    <div class="merito-aviso">
        <span class="badge badge--activo">Provisional</span>
        El bimestre sigue <strong>abierto</strong>: este ranking se calcula EN VIVO sobre las
        competencias ya <strong>bloqueadas</strong>, así que <strong>cambia</strong> conforme
        los docentes registran y aprueban. El ranking definitivo se congela al cerrar.
    </div>
<?php endif; ?>

<?php if ($hayPendientes): ?>
    <div class="merito-aviso">
        <strong>Hay empates sin resolver.</strong> Las filas resaltadas comparten puesto:
        el orden entre ellas <strong>aún no es oficializable</strong> y se define desde el
        <a href="<?= url($rutaMerito . '/' . $periodo['id']) ?>">orden de mérito del grado</a>.
    </div>
<?php endif; ?>

<?php if (empty($ranking)): ?>
    <div class="empty-state"><p>No hay calificaciones registradas en este periodo.</p></div>
<?php else: ?>

    <?php $i = 0; foreach ($ranking as $gradoId => $data): $i++; ?>
        <details class="card mb-lg" <?= $i === 1 ? 'open' : '' ?>>
            <summary class="card__header card__header--toggle">
                <div class="card__header-left">
                    <h2 class="card__title">
                        <?= e($data['grado']['nivel_nombre']) ?> — <?= e($data['grado']['nombre_display']) ?>
                    </h2>
                    <span class="badge badge--info"><?= count($data['secciones']) ?> secciones</span>
                </div>
                <svg class="card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <polyline points="6,9 12,15 18,9"/>
                </svg>
            </summary>

            <div class="card__body">
                <?php if (empty($data['secciones'])): ?>
                    <p class="text-muted">Sin calificaciones registradas.</p>
                <?php else: ?>
                    <?php $j = 0; foreach ($data['secciones'] as $secNombre => $estudiantes): $j++;
                        // Distincion de seccion IGUAL que /docente/mis-cargas: la LETRA
                        // (recuadro) es el identificador; el color es monocromo azul de
                        // "Mis cargas" (no paleta por letra). Estandariza la lectura.
                        $secLetra = mb_strtoupper(mb_substr((string) $secNombre, 0, 1));
                    ?>
                        <details class="merito-seccion-acordeon" <?= $j === 1 ? 'open' : '' ?>>
                            <summary class="merito-seccion-acordeon__head">
                                <span class="merito-seccion-acordeon__rotulo">Sección</span>
                                <span class="merito-seccion-acordeon__letra"><?= e($secLetra) ?></span>
                                <span class="badge badge--info"><?= count($estudiantes) ?> estudiantes</span>
                                <svg class="card__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <polyline points="6,9 12,15 18,9"/>
                                </svg>
                            </summary>
                            <div class="tabla-responsive">
                                <table class="tabla-ranking">
                                    <thead>
                                        <tr>
                                            <th class="col-puesto text-center">Puesto</th>
                                            <th class="col-nombre">Apellidos y nombres</th>
                                            <th class="text-center">Comp.</th>
                                            <th class="text-center">Promedio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($estudiantes as $est): ?>
                                            <?php $pendiente = !empty($est['empate_pendiente']); ?>
                                            <tr class="<?= $pendiente ? 'fila-empate' : '' ?>">
                                                <td class="col-puesto text-center">
                                                    <span class="puesto puesto--<?= $est['puesto'] <= 3 ? $est['puesto'] : 'normal' ?>"><?= (int) $est['puesto'] ?>°</span>
                                                </td>
                                                <td class="col-nombre"><?= e($est['apellido_paterno'] . ' ' . $est['apellido_materno'] . ', ' . $est['nombres']) ?></td>
                                                <td class="text-center"><?= (int) $est['num_competencias'] ?></td>
                                                <td class="text-center"><strong><?= sprintf('%05.2f', $est['promedio_general']) ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>

<?php endif; ?>
