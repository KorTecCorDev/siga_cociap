<?php
/**
 * «Cómo leer este informe» (23/09/2026). Uno para la pantalla y el A4. En papel
 * va ANTES de las tablas: quien lee una hoja impresa no puede preguntar.
 *
 * Ningún número ni letra de la regla está escrito a mano: la escala sale de
 * `escala_rangos()` y las condiciones de promoción, de la propia norma que
 * implementa `situacion_final()`.
 *
 * @var array $riesgo
 */
$res = $riesgo['stats']['resumen'];
?>
<div class="riesgo-leer">
    <p class="riesgo-leer__titulo"><strong>Cómo leer este informe</strong></p>
    <ul>
        <li>
            <strong>Qué es estar en riesgo.</strong> Es la <strong>situación final</strong> que
            define el MINEDU: <strong>RR</strong> (requiere recuperación) o <strong>PER</strong>
            (permanece en el grado). Se listan los estudiantes que, con el último nivel
            registrado de cada competencia, <strong>no alcanzarían la promoción</strong> de grado.
        </li>
        <li>
            <strong>Se cuenta por ÁREA, no por competencias sueltas.</strong> La norma pregunta
            cuántas <em>áreas</em> cumplen cada condición. En un área de 5 competencias «la mitad»
            son 3; en una de 3, son 2; en un área de una sola competencia, esa única.
        </li>
        <li>
            <strong>La exigencia depende del grado.</strong> En los <strong>grados finales de
            ciclo</strong> (2.º, 4.º y 6.º de primaria; 2.º y 5.º de secundaria) la promoción pide
            además <strong>B o superior en todas las competencias</strong>: una sola «C» manda a
            recuperación. En los <strong>intermedios</strong> basta con la mitad o más en B o
            superior <em>en cada área</em>, y se admiten «C» en el resto.
        </li>
        <li>
            <strong>1.º de primaria</strong> tiene promoción automática: nunca aparece en la lista.
            Si tiene notas por debajo de A se lista aparte, como seguimiento pedagógico.
        </li>
        <li>
            <strong>Qué notas entran.</strong> De cada competencia, su <strong>último nivel de logro
            registrado</strong> hasta este bimestre, como lo hace el SIAGIE: si no se evaluó en este
            bimestre, cuenta la del bimestre anterior en que se registró, y el desglose dice de cuál.
            En el <strong>último bimestre del año</strong> solo cuentan sus propias notas. Entran las
            competencias <strong>bloqueadas</strong>, incluidas las <strong>notas extraordinarias</strong>,
            sin áreas exoneradas. <strong>No cuentan</strong> las competencias transversales (por
            norma) ni los <strong>talleres</strong>, porque la UGEL no los aprobó y no están en el SIAGIE.
        </li>
        <li>
            <strong>Seguro o proyectado.</strong> Quedan <strong>pendientes</strong> las competencias que
            todavía no se evaluaron en ningún bimestre, y la norma cuenta «la mitad» sobre
            <em>todas</em> las del área. Por eso la situación se prueba contra lo pendiente:
            <strong>Seguro</strong> = no alcanzaría la promoción ni con AD en todo lo que falta;
            <strong>Proyectado</strong> = lo pendiente todavía puede salvarlo. «Seguro» no es
            definitivo: las notas de los bimestres siguientes reemplazan a las de hoy.
        </li>
        <?php if (!$res['cobertura']['completa']): ?>
            <li>
                <strong>Proyección parcial.</strong> Hay <?= (int) $res['cobertura']['parciales'] ?>
                estudiante<?= $res['cobertura']['parciales'] !== 1 ? 's' : '' ?> con competencias de su
                plan todavía sin calificar. La franja de cada uno dice cuántas tiene evaluadas y
                cuántas pendientes.
            </li>
        <?php endif; ?>
        <li>
            Cada grado se ordena poniendo primero la permanencia, y dentro, a quien acumula más
            competencias en C. Debajo de cada estudiante van sus competencias <strong>no
            aprobatorias</strong> con el área, el curso, el literal, la nota y el docente que la
            registró: es lo que hay que remontar, no la cuenta de la regla.
        </li>
        <li>
            Escala:
            <?php $partes = [];
            foreach (escala_rangos() as $lit => $rango) {
                $partes[] = '<strong>' . e($lit) . '</strong> ' . e($rango) . ' (' . e(descripcion_literal($lit)) . ')';
            } ?>
            <?= implode(' &middot; ', $partes) ?>.
            En primaria aprueban <strong>AD</strong> y <strong>A</strong>; en secundaria, también
            <strong>B</strong>.
        </li>
        <li>
            No confundir con <strong>«Promedio en C»</strong> de los cuadros estadísticos: aquélla
            cuenta a quien tiene el promedio general por debajo de <?= (int) NOTA_MIN_B ?>.
        </li>
    </ul>
</div>
