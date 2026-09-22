<?php
/**
 * Calificación extraordinaria — Registro Académico: sección ÚNICA al final de
 * la carga (21/09/2026). La usan /consulta-notas/{p}/carga/{c} y el historial
 * del docente (por carga y por área).
 *
 * Antes iba un bloque bajo CADA competencia, y solo si la competencia tenía
 * tabla. Ahora va una vez, al final, agrupada por competencia, e incluye las
 * competencias sin tabla propia en la carga: el alumno puede traer del colegio
 * de origen competencias que aquí no se trabajaron.
 *
 * @var array $extraordinariasCarga filas de RectificacionModel::getExtraordinariasDeCargas
 */
if (empty($extraordinariasCarga)) {
    return;
}

$porCompetencia = [];
foreach ($extraordinariasCarga as $ex) {
    $clave = (int) $ex['carga_id'] . '-' . (int) $ex['competencia_id'];
    $porCompetencia[$clave]['nombre'] = $ex['competencia_nombre'];
    $porCompetencia[$clave]['codigo'] = $ex['codigo_minedu'] ?? '';
    $porCompetencia[$clave]['filas'][] = $ex;
}
?>
<div class="extraordinaria-info extraordinaria-info--carga">
    <p class="extraordinaria-info__titulo">
        Calificación extraordinaria — Registro Académico
    </p>
    <p class="extraordinaria-info__leyenda">
        Las siguientes calificaciones <strong>no forman parte del registro
        ordinario del docente en este bimestre</strong>: fueron ingresadas por
        Registro Académico con autorización, por el motivo registrado.
    </p>
    <?php foreach ($porCompetencia as $comp): ?>
        <p class="extraordinaria-info__competencia">
            <?= e($comp['nombre']) ?>
            <?php if ($comp['codigo'] !== ''): ?>
                <span class="competencia-card__codigo"><?= e($comp['codigo']) ?></span>
            <?php endif; ?>
        </p>
        <?php
        $filasEx = $comp['filas'];
        require VIEW_PATH . '/shared/_extraordinarias-tabla.php';
        ?>
    <?php endforeach; ?>
</div>
