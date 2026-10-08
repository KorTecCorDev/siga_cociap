<?php
/**
 * UNA celda-día de la asistencia por fechas (30/09/2026). PUNTO ÚNICO de sus
 * estados: lo usan la grilla mensual (`_grilla-fechas.php`) y el calendario por
 * estudiante (`_estudiante-fechas.php`, con `$grande`).
 *
 *   no lectivo        «NL» gris, sin acción (motivo en el title)
 *   futuro            gris punteado, sin acción
 *   con incidencia    pastilla F/FJ/T/TJ; icono de documento SOLO en FJ/TJ con
 *                     el MOTIVO PRINCIPAL (08/10/2026)
 *   lista tomada      ✓ (asistió): la sección pasó lista y no hay incidencia
 *   sin tomar         vacía: nadie pasó lista ese día, no se afirma nada
 *
 * 🔴 CONTRATO DE DOM con `asistencia-fechas.js` (modo editable):
 * `button.af-celda[data-fecha][data-tipo][data-motivo][data-tomada]`; el JS
 * repinta con `pintarCelda()` usando las MISMAS clases que aquí.
 *
 * @var array       $d          día del calendario {fecha, dia, marcable, no_lectivo}
 * @var array|null  $x          incidencia del día {tipo, motivo_id, motivo} o null
 * @var bool        $tomada     la sección tiene la lista del día
 * @var bool        $editable   pintar botón (true) o texto de solo lectura
 * @var bool        $grande     celda grande del calendario (con el número del día)
 * @var string      $etiqueta   nombre del estudiante para el aria-label (grilla)
 * @var string      $colClase   clase de columna del calendario (`af-col-N`), o ''
 * @var int|null    $motPrincipal ancla del bimestre (`AsistenciaMotivoModel::anclaDelPeriodo`)
 */

$tipo  = $x['tipo'] ?? '';
$mot   = $x['motivo_id'] ?? null;
$nl    = $d['no_lectivo'] ?? null;
$texto = $tipo !== '' ? $tipo : ($tomada ? '✓' : '');

$clases = ['af-celda'];
if ($grande)            { $clases[] = 'af-celda--grande'; }
if ($colClase !== '')   { $clases[] = $colClase; }
if ($nl !== null)       { $clases[] = 'af-celda--no-lectivo'; $texto = 'NL'; }
elseif (!$d['marcable']) { $clases[] = 'af-celda--futuro'; $texto = ''; }
elseif ($tipo !== '')   { $clases[] = 'af-celda--' . strtolower($tipo); }
elseif ($tomada)        { $clases[] = 'af-celda--asistio'; }
else                    { $clases[] = 'af-celda--sin-tomar'; }
if ($mot !== null && $nl === null && $mot === ($motPrincipal ?? null)) { $clases[] = 'af-celda--con-motivo'; }

// `$tituloCelda`, nunca `$titulo`: ese es el <title> de la página.
$tituloCelda = $d['fecha'] . match (true) {
    $nl !== null        => ' · No lectivo: ' . $nl,
    !$d['marcable']     => '',
    $tipo !== ''        => ($x['motivo'] ? ' · ' . $x['motivo'] : ''),
    $tomada             => ' · Asistió',
    default             => ' · Sin tomar lista',
};
$accesible = trim($etiqueta . ' ' . $tituloCelda);
?>
<?php if ($editable && $d['marcable']): ?>
    <button type="button" class="<?= implode(' ', $clases) ?>"
            data-fecha="<?= e($d['fecha']) ?>" data-tipo="<?= e($tipo) ?>"
            data-motivo="<?= $mot !== null ? (int) $mot : '' ?>"
            data-tomada="<?= $tomada ? '1' : '0' ?>"
            title="<?= e($tituloCelda) ?>" aria-label="<?= e($accesible) ?>">
        <?php if ($grande): ?>
            <span class="af-celda__num"><?= (int) $d['dia'] ?></span>
            <span class="af-celda__tipo"><?= e($texto) ?></span>
        <?php else: ?><?= e($texto) ?><?php endif; ?>
    </button>
<?php else: ?>
    <span class="<?= implode(' ', $clases) ?> af-celda--solo" title="<?= e($tituloCelda) ?>">
        <?php if ($grande): ?>
            <span class="af-celda__num"><?= (int) $d['dia'] ?></span>
            <span class="af-celda__tipo"><?= e($texto) ?></span>
        <?php else: ?><?= e($texto) ?><?php endif; ?>
    </span>
<?php endif; ?>
