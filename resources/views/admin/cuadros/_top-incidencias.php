<?php
/**
 * Estudiantes con más faltas y tardanzas SIN JUSTIFICAR, por sección.
 * Compartido por la pantalla y el imprimible A4.
 *
 * 🔴 ES UN INDICADOR INFORMATIVO Y ASÍ SE PRESENTA. La normativa vigente no
 * contempla el retiro automático por exceso de inasistencias: cualquier
 * decisión debe sustentarse y evaluarse caso por caso. Por eso esta tabla no
 * tiene umbral, no marca a nadie en rojo y no usa la palabra "riesgo". El rojo
 * y el ámbar de este sistema significan estado de un proceso (ver
 * `docs/modulos/ui.md`), y aquí no hay ningún proceso que señalar: hay personas.
 *
 * Una fila por estudiante, no dos listas: quien destaca en faltas y en
 * tardanzas aparecía dos veces con la mitad del dato cada vez.
 *
 * @var array $asisTop  salida de AsistenciaModel::getTopIncidenciasPorSeccion
 * @var string|null $asisAncla ancla del bimestre por fechas (076): «F. just.» pasa a la
 *                             pastilla FJ con motivo principal (`_pastilla-fj.php`)
 */

if (empty($asisTop)) {
    return;
}
?>

<?php // UNA TABLA POR SECCION, no una sola con filas de grupo. Con 180 filas
      // seguidas el encabezado de columnas se iba de pantalla y los numeros
      // dejaban de significar nada; y el limite entre una seccion y la
      // siguiente era una unica fila, facil de pasar por alto.
      //
      // Mismo patron que `consulta-notas/criterios-imprimir.php`, que ya
      // resuelve en este repo el caso "muchas tablas pequenas, cada una
      // rotulada": el rotulo va en un <caption>, NO en un <hN>. El <caption>
      // queda asociado a SU tabla para un lector de pantalla y evita el
      // problema de nivel de encabezado: el padre es <h3> en pantalla y <h2>
      // en el imprimible, asi que cualquier <hN> fijo saltaria un nivel en
      // una de las dos vistas. ?>
<?php foreach ($asisTop as $s): ?>
    <div class="cuadros-top__bloque">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas cuadros-top">
                <caption class="cuadros-top__caption"><?= e($s['etq']) ?></caption>
                <thead>
                    <tr>
                        <th class="col-nombre">Estudiante</th>
                        <th class="text-center cuadros-top__n">Faltas</th>
                        <th class="text-center cuadros-top__n">Tardanzas</th>
                        <th class="text-center cuadros-top__n"><?php if (!empty($asisAncla)):
                            $nombreAnclaFj = $asisAncla; require VIEW_PATH . '/admin/asistencia/_pastilla-fj.php';
                        else: ?>F. just.<?php endif; ?></th>
                        <th class="text-center cuadros-top__n">T. just.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($s['alumnos'] as $al): ?>
                        <tr>
                            <td class="col-nombre"><?= e($al['nombre_completo']) ?></td>
                            <?php // `--destaca` marca el contador que trajo a esta persona a la
                                  // lista: sin el, una fila con 0 faltas y 9 tardanzas parece un
                                  // error de la tabla en vez de un caso de tardanzas. ?>
                            <td class="text-center<?= $al['por_faltas'] ? ' cuadros-top__v--destaca' : '' ?>">
                                <?= (int) $al['faltas'] ?>
                            </td>
                            <td class="text-center<?= $al['por_tardanzas'] ? ' cuadros-top__v--destaca' : '' ?>">
                                <?= (int) $al['tardanzas'] ?>
                            </td>
                            <td class="text-center text-muted"><?= (int) $al['faltas_justificadas'] ?></td>
                            <td class="text-center text-muted"><?= (int) $al['tardanzas_justificadas'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php // El pie es UNO SOLO y al final, fuera de todos los bloques: repetir las
      // dos notas 23 veces seria ruido, y la advertencia normativa pesa mas
      // leida una vez que diluida en cada tabla. Va suelto (`--suelto`) porque
      // ya no cuelga de ningun wrapper. ?>
<div class="tabla-pie tabla-pie--suelto">
    <p class="text-sm text-muted">
        Indicador <strong>informativo</strong>. Se listan los tres estudiantes con más
        faltas y los tres con más tardanzas de cada sección, ampliando el corte para
        no partir un empate en el último puesto. Las columnas resaltadas son las
        <strong>sin justificación</strong>; las dos últimas, las justificadas, que se
        cuentan aparte y no se descuentan de las primeras.
    </p>
    <p class="text-sm text-muted">
        La normativa vigente <strong>no contempla el retiro automático por exceso de
        inasistencias</strong>: cualquier decisión debe sustentarse y evaluarse caso
        por caso.
    </p>
</div>
