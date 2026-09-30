<?php
/**
 * Vista: asistencia de la sección en SOLO LECTURA (24/08/2026).
 *
 * Cuarto registro del bimestre en la capa de supervisión. A diferencia de
 * transversales y conducta, NO exige cierre: se muestra EN VIVO. El estado del
 * cierre aparece como dato en la cabecera, no como candado.
 *
 * 🔴 La TABLA es `admin/asistencia/_tabla-incidencias.php`, el partial que
 * comparte con Registro Académico. Antes esta vista reimplementaba la tabla con
 * `tabla-resumen` + `text-center`, sin ancho fijo en los contadores: el mismo
 * dato con dos aspectos distintos y dos sitios que tocar. No reintroducir una
 * tabla propia aquí.
 *
 * @var array      $periodo
 * @var array      $seccion  { seccion_id, seccion_nombre, grado_nombre, nivel_nombre }
 * @var array      $alumnos  [{ matricula_id, nombre_completo, incidencias{...} }]
 * @var array      $totales  AsistenciaModel::totalesIncidencias($alumnos)
 * @var array|null $cierre   cierre vigente { ra_bloqueado_en, ra_nombre, ... } o null
 * @var array|null $fechasDetalle   bimestre por fechas: [matricula_id => «F: 03/09 · FJ: 10/09»]
 * @var array      $justificaciones anexo (AsistenciaModel::justificaciones); vacío si no hay
 */
$volver = url('consulta-notas/' . (int) $periodo['id'] . '/seccion/' . (int) $seccion['seccion_id']);

// Contrato del partial compartido: esta pantalla NUNCA edita.
$estudiantes = $alumnos;
$editable    = false;
$pidVer      = (int) $periodo['id'];
?>

<div class="page-header">
    <a href="<?= $volver ?>" class="btn btn--secondary btn--sm">&larr; Sección</a>
    <div>
        <h1 class="page-title">Asistencia</h1>
        <p class="page-subtitle">
            <?= e($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']) ?>
            &middot; <?= e($seccion['nivel_nombre']) ?>
            &middot; <?= e($periodo['nombre_display']) ?>
        </p>
    </div>
    <span class="badge badge--activo">Solo lectura</span>
</div>

<?php // 🔴 EL ESTADO SE LEE DE `ra_bloqueado_en`, que es como se llama la columna
      // en `cierres_asistencia`. Hasta el 25/08/2026 esta vista preguntaba por
      // `bloqueado_en`, una clave que NO existe: `empty()` no avisa de una clave
      // ausente, asi que la pantalla decia SIEMPRE "en curso" — tambien en las 23
      // secciones de B1 y B2 que estaban bloqueadas y aprobadas.
      //
      // Y va como `alert`, no como parrafo gris: es el mismo hecho que Registro
      // Academico ensena con este patron, y era el dato mas importante de la
      // pantalla puesto en el elemento mas debil. ?>
<?php if ($cierre): ?>
    <div class="alert alert--info">
        <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
        <span>
            Asistencia <strong>bloqueada y aprobada</strong>
            el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?><?php
                if (!empty($cierre['ra_nombre'])): ?> por <?= e($cierre['ra_nombre']) ?><?php
                endif; ?>.
        </span>
        <a href="<?= url('admin/asistencia/' . (int) $seccion['seccion_id'] . '/imprimir/' . $pidVer) ?>"
           target="_blank" rel="noopener" class="btn btn--secondary btn--sm alert__accion">
            🖨 Imprimir registro
        </a>
    </div>
<?php else: ?>
    <div class="alert alert--warning">
        <span class="btn-icon btn-icon--wait" aria-hidden="true"></span>
        <span>
            Registro <strong>en curso</strong>: todavía puede modificarse.
            Lo que se ve aquí es el estado de este momento, no un registro aprobado.
        </span>
    </div>
<?php endif; ?>

<?php if (empty($alumnos)): ?>
    <div class="empty-state"><p>Esta sección no tiene estudiantes en el roster.</p></div>
<?php else: ?>
    <p class="text-sm text-muted mb-md">
        <strong><?= (int) $totales['registrados'] ?></strong> de <?= count($alumnos) ?>
        estudiantes con registro confirmado.
        Para modificar se usa <em>Asistencia</em> (auxiliar académico o Registro Académico).
    </p>

    <?php require VIEW_PATH . '/admin/asistencia/_tabla-incidencias.php'; ?>

    <?php // Anexo «Detalle de justificaciones» debajo de la tabla (29/09/2026). ?>
    <?php if (!empty($justificaciones)): ?>
        <h2 class="asistencia-anexo-titulo">Detalle de justificaciones</h2>
        <div class="tabla-notas-wrapper">
            <?php $claseAnexo = 'tabla-notas asistencia-tabla tabla-anexo'; require VIEW_PATH . '/admin/asistencia/_anexo-justificaciones.php'; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
