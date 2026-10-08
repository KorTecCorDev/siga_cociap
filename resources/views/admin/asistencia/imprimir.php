<?php
/**
 * Vista: Registro imprimible de Asistencia — A4 portrait (layout print).
 * Copia oficial de los contadores de incidencias aprobados y bloqueados,
 * con encabezado formal, fecha de impresión y espacios de firma
 * (Auxiliar Responsable + Personal de Registro Académico).
 *
 * @var array  $seccion      { grado_nombre, seccion_nombre, nivel_nombre }
 * @var array  $periodo      { nombre_display, anio }
 * @var array  $estudiantes  [{ matricula_id, nombre_completo, incidencias{...} }]
 * @var array  $cierre       { ra_bloqueado_en, ra_nombre }
 * @var array  $firmas       AuxiliarSeccionModel::firmasDelRegistro() — nombres de las
 *                           dos líneas de firma y quién bloqueó con su rol real
 * @var string $institucion
 * @var bool   $porFechas    bimestre por fechas (29/09/2026): siglas en las columnas y
 *                           guion (no ceros) para quien no tiene registro confirmado
 * @var array  $detalle      filas del anexo «Detalle de incidencias»
 * @var string|null $motivoPrincipal ancla del bimestre (076): icono de la columna FJ
 *                           (AsistenciaModel::detalleIncidencias), en hoja aparte
 *
 * Con anexo, las FIRMAS van al final del anexo, no de la hoja principal: se firma
 * el documento completo (decisión del usuario, 29/09/2026). Sin anexo, al final
 * de la tabla, como siempre.
 */

$hoy       = (new DateTime())->format('d/m/Y');
$campos    = ['faltas', 'faltas_justificadas', 'tardanzas', 'tardanzas_justificadas'];
$porFechas = $porFechas ?? false;
$detalle   = $detalle ?? [];
$conAnexo  = $porFechas && $detalle !== [];
?>

<div class="reporte-pagina registro-doc">

    <header class="boleta-header">
        <div class="boleta-header__logo-wrap">
            <img src="<?= url('assets/img/logo_cociap.png') ?>"
                 alt="COCIAP" class="boleta-header__logo">
        </div>
        <div class="boleta-header__centro">
            <div class="boleta-header__ugel">MINEDU &middot; DRE Áncash &middot; UGEL Huaraz</div>
            <div class="boleta-header__colegio"><?= e($institucion ?? '') ?></div>
            <div class="boleta-header__titulo">
                Registro de Asistencia &mdash; <?= e($periodo['nombre_display'] ?? '') ?> <?= e((string) ($periodo['anio'] ?? '')) ?>
            </div>
        </div>
        <div class="boleta-header__fecha-wrap">
            <div class="boleta-header__fecha-label">Impresión</div>
            <div class="boleta-header__fecha"><?= $hoy ?></div>
        </div>
    </header>

    <div class="reporte-titulo">
        <div class="reporte-titulo__grupo">
            <span class="reporte-titulo__principal">
                <?= e($seccion['grado_nombre']) ?> &laquo;<?= e($seccion['seccion_nombre']) ?>&raquo;
            </span>
            <span class="reporte-titulo__sub">
                &mdash; <?= e($seccion['nivel_nombre']) ?> &mdash; Incidencias de asistencia del bimestre
            </span>
        </div>
        <div class="reporte-titulo__meta">
            <span class="reporte-titulo__badge"><?= count($estudiantes) ?> estudiantes</span>
        </div>
    </div>

    <table class="tabla-registro<?= $porFechas ? ' tabla-registro--fechas' : '' ?>">
        <thead>
            <tr>
                <th class="tr-num">N&deg;</th>
                <th class="tr-nombre">Apellidos y Nombres</th>
                <?php // Por fechas, las columnas son estrechas: van las siglas de la leyenda. ?>
                <th class="tr-contador"><?= $porFechas ? 'F' : 'Faltas' ?></th>
                <th class="tr-contador"><?php if ($porFechas && !empty($motivoPrincipal)):
                    $nombreAnclaFj = $motivoPrincipal; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php';
                else: ?><?= $porFechas ? 'FJ' : 'Faltas justif.' ?><?php endif; ?></th>
                <th class="tr-contador"><?= $porFechas ? 'T' : 'Tardanzas' ?></th>
                <th class="tr-contador"><?= $porFechas ? 'TJ' : 'Tardanzas justif.' ?></th>
                <?php // Sin columna «Fechas»: van en el anexo (decisión del usuario). ?>
                <?php // N° REPETIDO al final (decisión del usuario, 29/09/2026). ?>
                <th class="tr-num">N&deg;</th>
            </tr>
        </thead>
        <tbody>
            <?php $hayExtraordinaria = false;
            foreach ($estudiantes as $idx => $est):
                $inc = $est['incidencias'];
                // Fila de la via EXTRAORDINARIA (migracion 063): se imprime
                // marcada y con su nota al pie.
                $marca = !empty($inc['extraordinaria']) ? '*' : '';
                if ($marca !== '') { $hayExtraordinaria = true; }
            ?>
                <tr>
                    <td class="tr-num"><?= $idx + 1 ?></td>
                    <td class="tr-nombre"><?= e($est['nombre_completo']) . $marca ?></td>
                    <?php // Por fechas: sin registro CONFIRMADO no hay dato → guion, como
                          // en la boleta (un 0 afirmaría «no faltó» de alguien sin registro). ?>
                    <?php $sinDato = $porFechas && empty($inc['registrado']); ?>
                    <?php foreach ($campos as $campo): ?>
                        <td class="tr-contador"><?= $sinDato ? '—' : (int) $inc[$campo] ?></td>
                    <?php endforeach; ?>
                    <td class="tr-num"><?= $idx + 1 ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="registro-doc__nota">
        <?php if ($porFechas): ?>
            F: falta · FJ: falta justificada · T: tardanza · TJ: tardanza justificada.
            <?php if (!empty($motivoPrincipal)): ?>
                <?php $nombreAnclaFj = $motivoPrincipal; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php'; ?>
                solo cuenta las justificadas con «<?= e($motivoPrincipal) ?>»; con otro motivo cuentan como F.
            <?php endif; ?>
            Los contadores se calculan de las fechas registradas. Un estudiante sin registro
            confirmado figura con guion (—): no tiene asistencia en la boleta de este bimestre.
        <?php else: ?>
            Los estudiantes sin registro guardado figuran con 0 incidencias en todos los
            contadores (estado válido confirmado al bloquear la sección).
        <?php endif; ?>
    </p>

    <p class="registro-doc__traza">
        Registro bloqueado y aprobado por <strong><?= e($firmas['bloqueo_nombre']) ?></strong>
        (<?= e($firmas['bloqueo_rol']) ?>) el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?>.
    </p>

    <?php if ($hayExtraordinaria): ?>
        <p class="registro-doc__traza">
            * Inasistencias registradas por Registro Académico con el bimestre cerrado
            (calificación extraordinaria).
        </p>
    <?php endif; ?>

    <?php if ($conAnexo): ?>
        <p class="registro-doc__adjunto">
            Se adjunta en hoja aparte el <strong>Detalle de incidencias</strong>
            (<?= count($detalle) ?> <?= count($detalle) === 1 ? 'registro' : 'registros' ?>): la fecha de
            cada falta y tardanza, y el motivo de cada justificación. Las firmas van al final del detalle.
        </p>
    <?php else: ?>
        <?php require __DIR__ . '/_firmas-registro.php'; ?>
    <?php endif; ?>

</div>
<?php // ANEXO «Detalle de incidencias» (decisión del usuario, 29/09/2026): en HOJA
      // APARTE, para que no se corte a mitad de la hoja principal. Una fila por
      // cada fecha con F, FJ, T o TJ, y el motivo de cada justificación; el N° es el
      // de la lista de arriba. Reemplaza a la columna «Fechas» de la tabla, y las
      // FIRMAS van al final, porque se firma el documento completo. ?>
<?php if ($conAnexo): ?>
    <div class="reporte-pagina registro-doc registro-doc--anexo">
        <div class="reporte-titulo">
            <div class="reporte-titulo__grupo">
                <span class="reporte-titulo__principal">Detalle de incidencias</span>
                <span class="reporte-titulo__sub">
                    &mdash; <?= e($seccion['grado_nombre']) ?> &laquo;<?= e($seccion['seccion_nombre']) ?>&raquo;
                    &mdash; <?= e($seccion['nivel_nombre']) ?> &mdash; <?= e($periodo['nombre_display'] ?? '') ?> <?= e((string) ($periodo['anio'] ?? '')) ?>
                </span>
            </div>
            <div class="reporte-titulo__meta">
                <span class="reporte-titulo__badge"><?= count($detalle) ?> <?= count($detalle) === 1 ? 'registro' : 'registros' ?></span>
            </div>
        </div>
        <?php $justificaciones = $detalle; $claseAnexo = 'tabla-registro tabla-anexo';
              require VIEW_PATH . '/admin/asistencia/_anexo-justificaciones.php'; ?>
        <p class="registro-doc__nota">
            F: falta · FJ: falta justificada · T: tardanza · TJ: tardanza justificada. El motivo
            solo corresponde a las justificadas. El N° es el de la lista del registro.
        </p>

        <?php require __DIR__ . '/_firmas-registro.php'; ?>
    </div>
<?php endif; ?>
