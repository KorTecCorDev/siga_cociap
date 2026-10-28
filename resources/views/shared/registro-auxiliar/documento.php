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
 * @var array|null $transversalesSeccion  solo modo área: hoja final de la sección
 */
$omisionSiglas = [
    'ausencia_injustificada' => 'AI',
    'ausencia_justificada'   => 'AJ',
    'abandono'               => 'AR',
    'no_aplico'              => 'NA',
];

// PAR numeral | literal (Logro, Resumen y transversales; 27/10/2026, los dos
// niveles): dos celdas, cada valor centrado en la suya y sin línea entre ellas.
// EXO, «—» y la celda en blanco ocupan las dos. `$a` = fila de alumno con
// `promedio` y `literal` (getResumenCompetencia: literal ⟺ promedio).
$par = static function (?array $a, bool $exo, string $clase, bool $enBlanco = false): void {
    if ($enBlanco) {
        echo '<td class="' . e($clase) . '" colspan="2"></td>';
    } elseif ($exo) {
        echo '<td class="' . e($clase) . '" colspan="2"><span class="exo-badge">EXO</span></td>';
    } elseif ($a !== null && $a['promedio'] !== null) {
        $lit = (string) $a['literal'];
        $mod = e(strtolower($lit));
        echo '<td class="' . e($clase) . ' ra-m__pn"><span class="nota-numeral nota-numeral--' . $mod . '">' . fmt_nota((int) $a['promedio']) . '</span></td>'
           . '<td class="' . e($clase) . ' ra-m__pl"><span class="nota-literal nota-literal--' . $mod . '">' . e($lit) . '</span></td>';
    } else {
        echo '<td class="' . e($clase) . '" colspan="2">—</td>';
    }
};

// Cada parcial en su propio ámbito: no ven ni pisan las variables del bucle.
$pintarMatriz = static function (array $hoja, array $periodo, string $modo, array $omisionSiglas, callable $par): void {
    require VIEW_PATH . '/shared/registro-auxiliar/_matriz.php';
};
$pintarConclusiones = static function (array $hoja, array $periodo, callable $par): void {
    require VIEW_PATH . '/shared/registro-auxiliar/_cd.php';
};
$pintarExtraordinarias = static function (array $extraordinariasCarga): void {
    require VIEW_PATH . '/consulta-notas/_extraordinarias-carga.php';
};
$pintarTransversales = static function (array $ts, array $periodo, callable $par): void {
    require VIEW_PATH . '/shared/registro-auxiliar/_transversales-seccion.php';
};
// Solo el registro del ÁREA COMPLETA la trae (ConsultaNotasController); el del
// docente nunca: él solo ve sus propias notas.
$transversalesSeccion = $modo === 'area' ? ($transversalesSeccion ?? null) : null;
?>
<div class="ra-print">
    <?php foreach ($hojas as $hoja): ?>
        <section class="ra-hoja">
            <?php $pintarMatriz($hoja, $periodo, $modo, $omisionSiglas, $par); ?>
            <?php $pintarConclusiones($hoja, $periodo, $par); ?>
            <?php $pintarExtraordinarias($hoja['extraordinarias']); ?>
        </section>
    <?php endforeach; ?>
    <?php if ($transversalesSeccion !== null): ?>
        <?php $pintarTransversales($transversalesSeccion, $periodo, $par); ?>
    <?php endif; ?>
</div>
