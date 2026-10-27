<?php
/**
 * REGISTRO AUXILIAR DE EVALUACIÓN DE APRENDIZAJES — A4 horizontal (layout print).
 *
 * Reestructurado el 27/10/2026 sobre el modelo del colegio (REG_AUX_*_2026.xlsm):
 * UNA HOJA POR SECCIÓN + ÁREA con
 *   1. la MATRIZ (hoja REG-NUM): competencias del área en grupos de columnas
 *      (criterios + LOGRO), RESUMEN y COMP-TRA; las competencias de otra carga
 *      van EN BLANCO. Al pie, estadísticas por competencia, lugar y fecha y firmas;
 *   2. las CONCLUSIONES DESCRIPTIVAS del área (hoja CD), en hoja nueva;
 *   3. las extraordinarias de RA, si las hay.
 *
 * UN documento con numeración continua (`@page paisaje`; NO doc-lote).
 * Solo lo OFICIAL: competencias bloqueadas. Reglas: docs/modulos/calificaciones.md.
 *
 * Lo usan dos módulos (27/10/2026): el registro del DOCENTE
 * (`$modo = 'docente'`, solo sus cargas) y el del ÁREA COMPLETA de
 * /consulta-notas (`$modo = 'area'`, todas las cargas y docentes).
 * Si el área no cabe en el ancho de la hoja, la matriz y las conclusiones
 * se reparten en PARTES por competencias enteras
 * (`RegistroAuxiliarModel::partesMatriz` / `partesConclusiones`).
 *
 * @var string $modo     'docente' | 'area'
 * @var array  $periodo  con anio y lema_oficial
 * @var array  $hojas    RegistroAuxiliarModel::hoja() + `docentes`
 *                       [{nombre, detalle|null}] y `firmas` [{nombre|null, cargo}]
 */
$omisionSiglas = [
    'ausencia_injustificada' => 'AI',
    'ausencia_justificada'   => 'AJ',
    'abandono'               => 'AR',
    'no_aplico'              => 'NA',
];

// Cada parcial en su propio ámbito: no ven ni pisan las variables del bucle.
$pintarMatriz = static function (array $hoja, array $periodo, string $modo, array $omisionSiglas): void {
    require VIEW_PATH . '/shared/registro-auxiliar/_matriz.php';
};
$pintarConclusiones = static function (array $hoja, array $periodo): void {
    require VIEW_PATH . '/shared/registro-auxiliar/_cd.php';
};
$pintarExtraordinarias = static function (array $extraordinariasCarga): void {
    require VIEW_PATH . '/consulta-notas/_extraordinarias-carga.php';
};
?>
<div class="ra-print">
    <?php foreach ($hojas as $hoja): ?>
        <section class="ra-hoja">
            <?php $pintarMatriz($hoja, $periodo, $modo, $omisionSiglas); ?>
            <?php $pintarConclusiones($hoja, $periodo); ?>
            <?php $pintarExtraordinarias($hoja['extraordinarias']); ?>
        </section>
    <?php endforeach; ?>
</div>
