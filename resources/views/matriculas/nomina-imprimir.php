<?php
/**
 * Nómina detallada de matrículas (reporte al comité directivo) — A4 horizontal.
 * Layout: print. Agrupada por sección; cada sección cierra con su cuadro resumen.
 * Retorno de grado (R3): el alumno aparece en su sección oficial Y en la operativa.
 *
 * @var array       $grupos        [{ seccion_id, nivel_nombre, grado_nombre, seccion_nombre, alumnos[], resumen }]
 * @var int         $totalGeneral
 * @var string      $filtrosTexto
 * @var string      $anioLabel
 * @var array|null  $directorEbr   { sello_path, nombre_completo }
 */
$labelEstado = fn(string $e): string => match ($e) {
    'aprobada'    => 'Aprobado',
    'pendiente'   => 'Pendiente',
    'desactivado' => 'Desactivado',
    default       => ucfirst($e),
};
$labelTipo = fn(string $t): string => match ($t) {
    'continuador' => 'Continuador',
    'nuevo'       => 'Nuevo',
    'trasladado'  => 'Trasladado',
    default       => ucfirst($t),
};
?>
<div class="nomina-det">

    <header class="nomina-det__head">
        <img class="nomina-det__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
        <div class="nomina-det__titulo">
            <h1><?= e(config('institucion')) ?></h1>
            <p>Nómina detallada de matrículas<?= $anioLabel !== '' ? ' &middot; ' . e($anioLabel) : '' ?></p>
            <p class="nomina-det__filtros"><strong>Filtros:</strong> <?= e($filtrosTexto) ?></p>
        </div>
    </header>

    <div class="nomina-det__meta">
        <span><strong>Total de registros:</strong> <?= (int) $totalGeneral ?></span>
        <span><strong>Fecha de impresión:</strong> <?= e(date('d/m/Y H:i')) ?></span>
    </div>

    <?php if (empty($grupos)): ?>
        <p class="nomina-det__vacio">No hay matrículas que coincidan con los filtros aplicados.</p>
    <?php endif; ?>

    <?php foreach ($grupos as $grupo): $r = $grupo['resumen']; ?>
        <section class="nomina-det__grupo">
            <h2 class="nomina-det__seccion">
                <?php if ($grupo['seccion_id'] === 0): ?>
                    Sin sección asignada
                <?php else: ?>
                    <?= e($grupo['nivel_nombre']) ?> &middot;
                    <?= e($grupo['grado_nombre']) ?> &middot; Sección <?= e($grupo['seccion_nombre']) ?>
                <?php endif; ?>
                <span class="nomina-det__seccion-n"><?= count($grupo['alumnos']) ?> matrículas</span>
            </h2>

            <table class="nomina-det__tabla">
                <thead>
                    <tr>
                        <th class="nomina-det__c-num">N°</th>
                        <th class="nomina-det__c-dni">DNI</th>
                        <th>Apellidos y nombres</th>
                        <th class="nomina-det__c-gen">Género</th>
                        <th class="nomina-det__c-cel">Celular apoderado</th>
                        <th class="nomina-det__c-tipo">Tipo</th>
                        <th class="nomina-det__c-estado">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grupo['alumnos'] as $i => $a):
                        // Retorno de grado (ver MatriculaModel::listarParaNomina).
                        $rolRetorno  = $a['retorno_rol'] ?? null;
                        $activo      = ($a['retorno_estado'] ?? null) === 'activo';
                        $esOficial   = $rolRetorno === 'oficial'   && $activo;   // cursa en otro grado (informativa)
                        $esOperativa = $rolRetorno === 'operativa' && $activo;   // cursa AQUÍ
                        $esRevertida = $rolRetorno === 'operativa' && !$activo;  // cursó aquí; ya volvió (informativa)
                        // La operativa revertida queda `desactivado`, pero NO es una baja.
                        $baja        = $a['estado'] === 'desactivado' && !$esRevertida;
                        $clases = [];
                        if ($baja) { $clases[] = 'nomina-det__fila--baja'; }
                        if ($esOficial || $esOperativa || $esRevertida) { $clases[] = 'nomina-det__fila--retorno'; }
                    ?>
                        <tr class="<?= implode(' ', $clases) ?>">
                            <td class="nomina-det__c-num"><?= $i + 1 ?></td>
                            <td class="nomina-det__c-dni"><?= e($a['dni'] ?? '—') ?></td>
                            <td>
                                <?= e($a['nombre_completo']) ?>
                                <?php if ($esOperativa): ?>
                                    <span class="nomina-det__retorno-nota">↩ Retorno de grado · cursa aquí (oficial: <?= e($a['retorno_pareja_ubic'] ?: '—') ?>)</span>
                                <?php elseif ($esOficial): ?>
                                    <span class="nomina-det__retorno-nota">↪ Retorno de grado · cursa en <?= e($a['retorno_pareja_ubic'] ?: '—') ?> (fila informativa)</span>
                                <?php elseif ($esRevertida): ?>
                                    <span class="nomina-det__retorno-nota">↪ Retorno de grado revertido · <?= !empty($a['retorno_tramo']) ? 'cursó aquí el ' . e($a['retorno_tramo']) : 'no completó ningún bimestre aquí' ?>; volvió a <?= e($a['retorno_pareja_ubic'] ?: '—') ?> (fila informativa)</span>
                                <?php endif; ?>
                                <?php if ($baja && !empty($a['motivo_estado'])): ?>
                                    <span class="nomina-det__motivo">⊘ Motivo de baja: <?= e($a['motivo_estado']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nomina-det__c-gen"><?= in_array($a['sexo'], ['M', 'F'], true) ? e($a['sexo']) : '—' ?></td>
                            <td class="nomina-det__c-cel"><?= !empty($a['apoderado_telefono']) ? e($a['apoderado_telefono']) : '—' ?></td>
                            <td class="nomina-det__c-tipo"><?= $labelTipo($a['tipo']) ?></td>
                            <td class="nomina-det__c-estado"><?= $labelEstado($a['estado']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="nomina-det__resumen">
                <span class="nomina-det__resumen-titulo">Resumen <?= $grupo['seccion_id'] === 0 ? 'sin sección' : 'Sección ' . e($grupo['seccion_nombre']) ?> — Total: <?= (int) $r['total'] ?></span>
                <span><strong>Tipo:</strong> Nuevo <?= $r['tipo']['nuevo'] ?> · Continuador <?= $r['tipo']['continuador'] ?> · Trasladado <?= $r['tipo']['trasladado'] ?></span>
                <span><strong>Estado:</strong> Aprobado <?= $r['estado']['aprobada'] ?> · Pendiente <?= $r['estado']['pendiente'] ?> · Desactivado <?= $r['estado']['desactivado'] ?></span>
                <span><strong>Género:</strong> M <?= $r['genero']['M'] ?> · F <?= $r['genero']['F'] ?> <em class="nomina-det__nota-mini">(sin contar sin registro)</em></span>
                <?php if ($r['cursan_aqui'] > 0 || $r['informativa'] > 0): ?>
                    <span class="nomina-det__resumen-retorno">
                        <strong>Retorno de grado:</strong>
                        <?php if ($r['cursan_aqui'] > 0): ?><?= $r['cursan_aqui'] ?> cursa(n) aquí<?php endif; ?>
                        <?php if ($r['cursan_aqui'] > 0 && $r['informativa'] > 0): ?> · <?php endif; ?>
                        <?php if ($r['informativa'] > 0): ?><?= $r['informativa'] ?> fila(s) informativa(s) (cursa(n) en otro grado)<?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($directorEbr && !empty($directorEbr['sello_path'])): ?>
        <footer class="nomina-det__footer">
            <div class="nomina-det__sello-bloque">
                <img class="nomina-det__sello" src="<?= url($directorEbr['sello_path']) ?>"
                     alt="" aria-hidden="true">
            </div>
        </footer>
    <?php endif; ?>

</div>
