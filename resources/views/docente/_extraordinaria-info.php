<?php
/**
 * Card informativa de CALIFICACIONES EXTRAORDINARIAS de una competencia.
 * NO salen del registro ordinario del docente: las registró RA con
 * autorización (motivo abajo). Punto único de su marcado: lo incluyen el
 * resumen de competencia y la grilla del docente, donde la extraordinaria
 * ya NO se pinta como un criterio más (18/09/2026).
 *
 * @var array $extraordinarias filas de RectificacionModel::getExtraordinariasDeCompetencia
 */
if (empty($extraordinarias)) {
    return;
}
?>
<div class="extraordinaria-info">
    <p class="extraordinaria-info__titulo">
        Calificación extraordinaria — Registro Académico
    </p>
    <p class="extraordinaria-info__leyenda">
        Las siguientes calificaciones <strong>no forman parte de tu registro
        ordinario del bimestre</strong>: fueron ingresadas por Registro
        Académico con autorización, por el motivo registrado.
    </p>
    <?php
    // Esta consulta sale de la auditoría (`nota_nueva`); la tabla compartida
    // espera `nota`, como la de la sección de la carga.
    $filasEx = array_map(
        fn($ex) => $ex + ['nota' => $ex['nota_nueva']],
        $extraordinarias
    );
    require VIEW_PATH . '/shared/_extraordinarias-tabla.php';
    ?>
</div>
