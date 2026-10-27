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
 * @var array  $periodo  con anio y lema_oficial
 * @var string $docente
 * @var array  $hojas    CalificacionController::hojaRegistroAuxiliar()
 * @var array  $firmas   [{nombre|null, cargo}]
 */
$omisionSiglas = [
    'ausencia_injustificada' => 'AI',
    'ausencia_justificada'   => 'AJ',
    'abandono'               => 'AR',
    'no_aplico'              => 'NA',
];

// Cada parcial en su propio ámbito: no ven ni pisan las variables del bucle.
$pintarMatriz = static function (array $hoja, array $periodo, string $docente, array $firmas, array $omisionSiglas): void {
    require VIEW_PATH . '/docente/_registro-auxiliar-matriz.php';
};
$pintarConclusiones = static function (array $hoja, array $periodo, string $docente): void {
    require VIEW_PATH . '/docente/_registro-auxiliar-cd.php';
};
$pintarExtraordinarias = static function (array $extraordinariasCarga): void {
    require VIEW_PATH . '/consulta-notas/_extraordinarias-carga.php';
};
?>
<div class="ra-print">
    <?php foreach ($hojas as $hoja): ?>
        <section class="ra-hoja">
            <?php $pintarMatriz($hoja, $periodo, $docente, $firmas, $omisionSiglas); ?>
            <?php $pintarConclusiones($hoja, $periodo, $docente); ?>
            <?php $pintarExtraordinarias($hoja['extraordinarias']); ?>
        </section>
    <?php endforeach; ?>
</div>
