<?php
/**
 * Consulta de calificaciones — areas/cargas oficiales de una seccion.
 * @var array      $periodo
 * @var array|null $seccion  null si la seccion no tiene notas oficiales
 * @var array      $cargas   [{carga_id, area_nombre, subarea_nombre, docente, competencias, transversales}]
 * @var bool       $tieneTransversales  cierre transversal vigente
 * @var bool       $tieneConducta       conducta con sus DOS etapas y sin anular
 */
?>

<div class="page-header">
    <a href="<?= url('consulta-notas?periodo_id=' . (int) $periodo['id']) ?>"
       class="btn btn--secondary btn--sm">← Secciones</a>
    <div>
        <h1 class="page-title">
            <?= $seccion ? e($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']) : 'Sección' ?>
        </h1>
        <p class="page-subtitle">
            <?= $seccion ? e($seccion['nivel_nombre']) . ' · ' : '' ?>
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?> — solo notas oficiales
        </p>
    </div>
    <?php // Registro auxiliar de TODAS las áreas (con la hoja de transversales de
          // la sección). Estilo propio del registro auxiliar para reconocerlo sin
          // leer. Solo en un bimestre cerrado: en otro, la ruta responde 404. ?>
    <?php if ($seccion && $periodo['estado'] === 'cerrado'): ?>
        <a class="btn btn--registro btn--sm" target="_blank"
           href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id'] . '/registro-auxiliar') ?>">
            <span class="btn-icon btn-icon--registro" aria-hidden="true"></span>
            Registro auxiliar de calificaciones
        </a>
    <?php endif; ?>
</div>

<?php // Registros de nivel SECCION (no cuelgan de ninguna carga). Transversales
      // y conducta solo aparecen cuando estan oficialmente cerrados; si no, su
      // ruta responde 404.
      //
      // ASISTENCIA es la excepcion (24/08/2026): se muestra EN VIVO y por eso
      // su tarjeta esta SIEMPRE. Una inasistencia ya ocurrio: no es una nota
      // sujeta a aprobacion del docente. ?>
<?php if ($seccion): ?>
    <?php // Los dos encabezados marcan la frontera que de verdad separa esta
          // pantalla: arriba lo que es de la SECCION, abajo lo que es por CARGA.
          // Es la misma frontera que distingue las dos caras de las transversales,
          // asi que ubicar las listas ya desambigua antes de leer ningun rotulo. ?>
    <section class="dash-grupo" aria-labelledby="cn-grupo-seccion">
        <h2 id="cn-grupo-seccion" class="dash-grupo__titulo">Registros de la sección</h2>
        <ul class="consulta-cargas">
            <?php if ($tieneTransversales): ?>
                <li>
                    <a class="consulta-carga"
                       href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id'] . '/transversales') ?>">
                        <span>
                            <?php // "Promedios" en el TITULO, no solo en la linea de abajo: el
                                  // registro crudo del docente se llamaba igual que esto, y la
                                  // distincion relegada al subtitulo solo funciona cuando se
                                  // ven las dos a la vez. Ver consulta-notas/carga.php. ?>
                            <span class="consulta-carga__area">Promedios de Competencias Transversales</span>
                            <span class="consulta-carga__docente">Aprobados y bloqueados por el tutor — es lo que va a la boleta</span>
                        </span>
                        <span class="consulta-carga__meta">Ver →</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if ($tieneConducta): ?>
                <li>
                    <a class="consulta-carga"
                       href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id'] . '/conducta') ?>">
                        <span>
                            <span class="consulta-carga__area">Conducta</span>
                            <span class="consulta-carga__docente">Cerrada por auxiliar y tutor</span>
                        </span>
                        <span class="consulta-carga__meta">Ver →</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a class="consulta-carga"
                   href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id'] . '/asistencia') ?>">
                    <span>
                        <span class="consulta-carga__area">Asistencia</span>
                        <span class="consulta-carga__docente">Faltas y tardanzas del bimestre — en vivo</span>
                    </span>
                    <span class="consulta-carga__meta">Ver &rarr;</span>
                </a>
            </li>
        </ul>
    </section>
<?php endif; ?>

<?php if (empty($cargas)): ?>
    <div class="empty-state"><p>Esta sección no tiene notas oficiales en este periodo.</p></div>
<?php else: ?>
    <section class="dash-grupo" aria-labelledby="cn-grupo-cargas">
        <h2 id="cn-grupo-cargas" class="dash-grupo__titulo">Áreas y cargas</h2>
        <ul class="consulta-cargas">
            <?php foreach ($cargas as $c): ?>
                <?php
                $area = $c['subarea_nombre']
                    ? $c['area_nombre'] . ' — ' . $c['subarea_nombre']
                    : $c['area_nombre'];
                $nTransv = (int) ($c['transversales'] ?? 0);
                ?>
                <li>
                    <a class="consulta-carga"
                       href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/carga/' . (int) $c['carga_id']) ?>">
                        <span>
                            <span class="consulta-carga__area"><?= e($area) ?></span>
                            <span class="consulta-carga__docente"><?= e($c['docente'] ?: 'Sin docente') ?></span>
                        </span>
                        <span class="consulta-carga__meta">
                            <?php // "del docente": estas son las transversales CRUDAS que registro
                                  // este docente en su carga, no los promedios del tutor de arriba. ?>
                            <?= (int) $c['competencias'] ?> competencia(s)<?= $nTransv > 0 ? ' · incl. ' . $nTransv . ' transv. del docente' : '' ?> →
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
