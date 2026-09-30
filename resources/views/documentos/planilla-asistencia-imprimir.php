<?php
/**
 * Planilla de asistencia manual en PDF (A4 horizontal). MISMO diseño que la
 * plantilla Excel (`resources/plantillas/registro-asistencia.xlsx`, rediseño del
 * 28/09/2026). Layout: print. `Documentos\DocumentoController::planillaGenerar`.
 *
 * Filas: las del punto único `PlanillaAsistenciaModel::filasGrilla()` (estudiantes
 * + hasta 3 libres), con el alto que llena la hoja (`planilla-print--alto-N`,
 * N en décimas de mm). A diferencia del Excel, no tiene tope de filas.
 *
 * @var array      $seccion      PlanillaAsistenciaModel::seccion()
 * @var array      $periodo      bimestre activo {nombre_display}
 * @var string     $auxiliar     auxiliar vigente ('' si no hay)
 * @var string[]   $alumnos      nombres en orden alfabético
 * @var array|null $mes          {anio, mes, nombre} o null = en blanco
 * @var array      $dias         indice 0..24 => {dia, abrev} (vacío en blanco)
 * @var int        $filas
 * @var int        $altoDecimas  alto de fila en décimas de mm
 */
use App\Models\PlanillaAsistenciaModel as Planilla;

// Una casilla de día se sombrea si hay mes y ese día no existe (decisión 28/09/2026).
$claseDia = static function (int $i) use ($mes, $dias): string {
    return 'planilla-print__dia'
        . ($i % 5 === 4 ? ' planilla-print__dia--fin' : '')
        . ($mes !== null && !isset($dias[$i]) ? ' planilla-print__dia--sin' : '');
};
$franja = [
    ['GRADO Y SECCIÓN', trim($seccion['grado_nombre'] . ' ' . $seccion['seccion_nombre']), 'grado'],
    ['BIMESTRE',        $periodo['nombre_display'], 'bimestre'],
    ['MES',             $mes !== null ? mb_strtoupper($mes['nombre']) : '', 'mes'],
    ['TUTOR(A)',        Planilla::nombreQueCabe($seccion['tutor_nombre'], Planilla::MAX_TUTOR), 'tutor'],
    ['AUXILIAR',        Planilla::nombreQueCabe($auxiliar, Planilla::MAX_AUXILIAR), 'auxiliar'],
];
?>
<div class="planilla-print planilla-print--alto-<?= (int) $altoDecimas ?>">

    <header class="planilla-print__head">
        <div class="planilla-print__inst">
            <img class="planilla-print__logo" src="<?= url('assets/img/logo_cociap.png') ?>" alt="COCIAP">
            <div class="planilla-print__inst-texto">
                <p class="planilla-print__unasam">UNIVERSIDAD NACIONAL SANTIAGO ANTÚNEZ DE MAYOLO</p>
                <p class="planilla-print__lema">"Una nueva Universidad para el desarrollo"</p>
                <p class="planilla-print__colegio"><?= e(mb_strtoupper(config('institucion'))) ?></p>
            </div>
        </div>
        <div class="planilla-print__titulo">
            <h1>REGISTRO AUXILIAR DE ASISTENCIA - <?= e((string) $seccion['anio']) ?></h1>
            <p class="planilla-print__nivel">NIVEL <?= e(mb_strtoupper($seccion['nivel_nombre'])) ?></p>
        </div>
    </header>

    <?php // Franja de datos a lo ancho. Casilla vacía = se completa a mano. ?>
    <div class="planilla-print__franja">
        <?php foreach ($franja as [$rotulo, $valor, $mod]): ?>
            <div class="planilla-print__dato planilla-print__dato--<?= $mod ?>">
                <span class="planilla-print__dato-rotulo"><?= e($rotulo) ?></span>
                <span class="planilla-print__dato-valor"><?= e($valor) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <table class="planilla-print__tabla">
        <thead>
            <tr>
                <th rowspan="3" class="planilla-print__num">N°</th>
                <th rowspan="3" class="planilla-print__nombre">APELLIDOS Y NOMBRES</th>
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <th colspan="5" class="planilla-print__semana">SEMANA <?= $s ?></th>
                <?php endfor; ?>
                <?php foreach (array_keys(Planilla::LEYENDA) as $sigla): ?>
                    <th rowspan="3" class="planilla-print__total"><?= e($sigla) ?></th>
                <?php endforeach; ?>
                <th rowspan="3" class="planilla-print__num">N°</th>
            </tr>
            <tr class="planilla-print__fila-num">
                <?php for ($i = 0; $i < 25; $i++): ?>
                    <th class="<?= $claseDia($i) ?>"><?= isset($dias[$i]) ? sprintf('%02d', $dias[$i]['dia']) : '' ?></th>
                <?php endfor; ?>
            </tr>
            <tr class="planilla-print__fila-abrev">
                <?php for ($i = 0; $i < 25; $i++): ?>
                    <th class="<?= $claseDia($i) ?>"><?= isset($dias[$i]) ? e($dias[$i]['abrev']) : '' ?></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody class="planilla-print__cuerpo">
            <?php for ($f = 0; $f < $filas; $f++): ?>
                <tr>
                    <td class="planilla-print__num"><?= $f + 1 ?></td>
                    <?php
                        // Si no cabe: primer nombre + inicial del segundo (punto único
                        // del modelo). Si ni así cabe, letra menor: nunca se corta.
                        $nombre = Planilla::nombreQueCabe($alumnos[$f] ?? '', Planilla::MAX_NOMBRE);
                        $largo  = mb_strlen($nombre);
                        $mod    = $largo > 42 ? ' planilla-print__nombre--muy-largo'
                                : ($largo > 36 ? ' planilla-print__nombre--largo' : '');
                    ?>
                    <td class="planilla-print__nombre<?= $mod ?>"><?= e($nombre) ?></td>
                    <?php for ($i = 0; $i < 25; $i++): ?>
                        <td class="<?= $claseDia($i) ?>"></td>
                    <?php endfor; ?>
                    <?php foreach (Planilla::LEYENDA as $_): ?><td class="planilla-print__total"></td><?php endforeach; ?>
                    <td class="planilla-print__num"><?= $f + 1 ?></td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <p class="planilla-print__leyenda">
        <?php foreach (Planilla::LEYENDA as $sigla => $texto): ?>
            <span><strong><?= e($sigla) ?></strong> = <?= e($texto) ?></span>
        <?php endforeach; ?>
        <span>En blanco = asistió</span>
    </p>

    <footer class="planilla-print__pie">
        <span>CAVVG/HZ/<?= e((string) $seccion['anio']) ?></span>
        <span class="planilla-print__slogan">"Colegio científico con Valores"</span>
        <span>FECHA DE IMPRESIÓN: <?= e(date('d/m/Y')) ?></span>
    </footer>

</div>
