<?php
/**
 * «Cómo leer este informe» (23/09/2026). Uno para la pantalla y el A4. En papel
 * va ANTES de las tablas: quien lee una hoja impresa no puede preguntar.
 *
 * Ningún número ni letra de la regla está escrito a mano: la escala sale de
 * `escala_rangos()` y las condiciones de promoción, de la propia norma que
 * implementa `situacion_final()`.
 *
 * Cuatro GRUPOS con título en 2 columnas que fluyen (27/09/2026; antes, 11
 * viñetas seguidas): la regla y los dos bloques a la izquierda, las notas que
 * entran y cómo se lee cada estudiante a la derecha (el orden del DOM es el de
 * lectura en móvil). La regla por grado es una tabla y los distintivos, una
 * leyenda. Siempre a la vista (regla «datos a la vista»), también en el A4.
 *
 * @var array $riesgo
 */
$res = $riesgo['stats']['resumen'];
?>
<div class="riesgo-leer">
    <p class="riesgo-leer__titulo">Cómo leer este informe</p>
    <div class="riesgo-leer__grupos">

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">La regla del MINEDU (RVM N.º 094-2020-MINEDU)</h3>
            <ul>
                <li>
                    <strong>En riesgo</strong> = no alcanzaría la promoción de grado. Es la situación
                    final del MINEDU: <strong>RR</strong> (requiere recuperación) o <strong>PER</strong>
                    (permanece en el grado).
                </li>
                <li>
                    <strong>Se cuenta por ÁREA</strong>, no por competencias sueltas. «La mitad» de un
                    área de 5 competencias son 3; de una de 3, son 2; de una sola, esa única.
                </li>
            </ul>
            <table class="riesgo-leer__tabla">
                <thead>
                    <tr>
                        <th scope="col">Grado</th>
                        <th scope="col">Para ser promovido</th>
                        <th scope="col">Una sola «C»</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Final de ciclo
                            <span>2.º, 4.º y 6.º de primaria &middot; 2.º y 5.º de secundaria</span></th>
                        <td>B o superior en <strong>todas</strong> las competencias</td>
                        <td>Manda a recuperación</td>
                    </tr>
                    <tr>
                        <th scope="row">Intermedio
                            <span>los demás grados</span></th>
                        <td>La mitad o más en B o superior <strong>en cada área</strong></td>
                        <td>Se admite en el resto del área</td>
                    </tr>
                </tbody>
            </table>
            <ul>
                <li>
                    <strong>1.º de primaria</strong>: promoción automática, nunca está en riesgo. Si
                    acumula competencias bajas, va al seguimiento.
                </li>
            </ul>
        </section>

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">Riesgo y seguimiento</h3>
            <ul>
                <li>
                    <strong>Seguimiento</strong>: estudiantes que <em>sí</em> serían promovidos pero
                    acumulan competencias bajas. En primaria, <?= (int) SEGUIMIENTO_MIN_B ?> o más en B
                    o <?= (int) SEGUIMIENTO_MIN_C ?> o más en C; en secundaria,
                    <?= (int) SEGUIMIENTO_MIN_C ?> o más en C. No es una situación final del MINEDU: es
                    una señal para acompañarlos antes de que lleguen al riesgo.
                </li>
                <li>
                    <strong>No se repiten</strong>: cada estudiante está en un solo bloque. Su suma es
                    el total de acompañamiento pedagógico; el porcentaje de riesgo cuenta solo el riesgo.
                </li>
            </ul>
        </section>

        <?php // Abre la 2.ª columna (en pantalla ancha y en el A4). ?>
        <section class="riesgo-leer__grupo riesgo-leer__grupo--col2">
            <h3 class="riesgo-leer__h">Qué notas entran</h3>
            <ul>
                <li>
                    <strong>El último nivel registrado</strong> de cada competencia hasta este bimestre:
                    si no se evaluó ahora, cuenta la del bimestre anterior y el desglose dice de cuál. En el <strong>último bimestre del año</strong>, solo sus
                    propias notas.
                </li>
                <li>
                    <strong>Entran</strong> las competencias bloqueadas, notas extraordinarias incluidas,
                    sin las áreas exoneradas.
                </li>
                <li>
                    <strong>No entran</strong> las competencias transversales ni los talleres.
                </li>
                <li>
                    <strong>«Depende de N pendientes»</strong>: «la mitad» se cuenta sobre
                    <em>todas</em> las competencias del área, también las aún no evaluadas. La marca
                    señala a quien <strong>todavía podría ser promovido</strong> si lo pendiente sale bien.
                </li>
                <?php if (!$res['cobertura']['completa']): ?>
                    <li>
                        <strong>Proyección parcial</strong>: <?= (int) $res['cobertura']['parciales'] ?>
                        estudiante<?= $res['cobertura']['parciales'] !== 1 ? 's' : '' ?> con competencias
                        de su plan sin calificar; su franja dice cuántas tiene evaluadas y pendientes.
                    </li>
                <?php endif; ?>
                <li>
                    <strong>Nada es definitivo antes del último bimestre</strong>: las notas de los
                    bimestres siguientes reemplazan a las de hoy.
                </li>
            </ul>
        </section>

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">Cómo leer cada estudiante</h3>
            <ul>
                <li>
                    <strong>Orden</strong>: primero la permanencia y, dentro, quien acumula más «C».
                </li>
                <li>
                    <strong>Motivo</strong>: por qué es RR o PER; en un RR, además, cuántas de las 4
                    áreas de la permanencia ya reúne.
                </li>
                <li>
                    <strong>Desglose</strong>: sus competencias <strong>no aprobatorias</strong> con
                    área, curso, literal, nota y docente. Es lo que hay que remontar, no la cuenta de
                    la regla. Su distintivo dice qué efecto tiene cada una:
                </li>
            </ul>
            <dl class="riesgo-leer__leyenda">
                <dt><?php $efecto = EFECTO_PER; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Solo en quien permanece en el grado: su área es una de las 4 con la mayoría en C.</dd>
                <dt><?php $efecto = EFECTO_RR; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Su área no llega a la mitad en B o superior (en los grados finales de ciclo, toda C).</dd>
                <dt><?php $efecto = EFECTO_LIMITE; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Su área cumple justo: una C más la haría fallar.</dd>
                <dt class="riesgo-leer__sin">Sin distintivo</dt>
                <dd>No pone en riesgo la promoción, aunque sea no aprobatoria.</dd>
            </dl>
        </section>

    </div>
    <p class="riesgo-leer__pie">
        <strong>Escala:</strong>
        <?php foreach (escala_rangos() as $lit => $rango): ?>
            <?php // Chip de las calificaciones (`.nota-literal`), el mismo de la
                  // consulta de notas y del resumen del docente (27/09/2026). ?>
            <span class="riesgo-leer__lit"><span class="nota-literal nota-literal--<?= e(strtolower($lit)) ?>"><?= e($lit) ?></span> <?= e($rango) ?> (<?= e(descripcion_literal($lit)) ?>)</span>
        <?php endforeach; ?>
        &middot; En primaria aprueban AD y A; en secundaria, también B. &middot; No confundir con <strong>«Promedio en C»</strong> de los cuadros
        estadísticos: cuenta a quien tiene el promedio general por debajo de <?= (int) NOTA_MIN_B ?>.
    </p>
</div>
