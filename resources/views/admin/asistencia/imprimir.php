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
 * @var string $institucion
 */

$hoy    = (new DateTime())->format('d/m/Y');
$campos = ['faltas', 'faltas_justificadas', 'tardanzas', 'tardanzas_justificadas'];
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

    <table class="tabla-registro">
        <thead>
            <tr>
                <th class="tr-num">N&deg;</th>
                <th class="tr-nombre">Apellidos y Nombres</th>
                <th class="tr-contador">Faltas</th>
                <th class="tr-contador">Faltas justif.</th>
                <th class="tr-contador">Tardanzas</th>
                <th class="tr-contador">Tardanzas justif.</th>
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
                    <?php foreach ($campos as $campo): ?>
                        <td class="tr-contador"><?= (int) $inc[$campo] ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="registro-doc__nota">
        Los estudiantes sin registro guardado figuran con 0 incidencias en todos los
        contadores (estado válido confirmado al bloquear la sección).
    </p>

    <p class="registro-doc__traza">
        Registro bloqueado y aprobado por <strong><?= e($cierre['ra_nombre']) ?></strong>
        (Registro Académico) el <?= e(fechaLima($cierre['ra_bloqueado_en'])) ?>.
    </p>

    <?php if ($hayExtraordinaria): ?>
        <p class="registro-doc__traza">
            * Inasistencias registradas por Registro Académico con el bimestre cerrado
            (calificación extraordinaria).
        </p>
    <?php endif; ?>

    <footer class="reporte-footer">
        <div class="reporte-footer__bloque">
            <div class="reporte-footer__espacio-firma"></div>
            <div class="reporte-footer__linea"></div>
            <div class="reporte-footer__cargo">Auxiliar Responsable</div>
        </div>
        <div class="reporte-footer__bloque">
            <div class="reporte-footer__espacio-firma"></div>
            <div class="reporte-footer__linea"></div>
            <div class="reporte-footer__cargo">Personal de Registro Académico</div>
        </div>
    </footer>

</div>
