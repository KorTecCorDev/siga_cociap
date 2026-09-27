<?php
/**
 * «Cómo leer este informe» (23/09/2026). Uno para la pantalla y el A4. En papel
 * va ANTES de las tablas: quien lee una hoja impresa no puede preguntar.
 *
 * 🔴 ES UNA LEYENDA DE LO QUE SE VE, NO UNA EXPLICACIÓN DEL CÁLCULO (decisión
 * del usuario, 27/09/2026). Lo leen los TUTORES: cada sigla, marca, dato de la
 * franja, columna y distintivo dice QUÉ significa, nunca cómo se obtuvo ni por
 * qué (sin causas internas: ver `docs/modulos/usuarios-direccion.md`). La regla
 * por grado ya la dice la cabecera de cada grado del listado.
 *
 * Las marcas y distintivos son las piezas REALES del informe (mismas clases),
 * y los umbrales y la escala salen de sus constantes: nada escrito a mano.
 *
 * @var array $riesgo
 */
$pendEj = 2;   // ejemplo de la marca «Depende de N pendientes»
?>
<div class="riesgo-leer">
    <p class="riesgo-leer__titulo">Cómo leer este informe</p>
    <div class="riesgo-leer__grupos">

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">Situación final (RVM N.º 094-2020-MINEDU)</h3>
            <dl class="riesgo-leer__leyenda">
                <dt><span class="riesgo-alumno__marca riesgo-alumno__marca--rr"><?= e(SITUACION_RR . ' · ' . situacion_rotulo(SITUACION_RR)) ?></span></dt>
                <dd>Necesita recuperación para ser promovido.</dd>
                <dt><span class="riesgo-alumno__marca riesgo-alumno__marca--per"><?= e(SITUACION_PER . ' · ' . situacion_rotulo(SITUACION_PER)) ?></span></dt>
                <dd>No sería promovido: repetiría el grado.</dd>
                <dt><span class="riesgo-alumno__marca riesgo-alumno__marca--pro"><?= e(SITUACION_PRO . ' · ' . situacion_rotulo(SITUACION_PRO)) ?></span></dt>
                <dd>Sería promovido (solo en el bloque de seguimiento).</dd>
                <dt><span class="riesgo-alumno__marca riesgo-alumno__marca--pro">Promoción automática</span></dt>
                <dd>1.º de primaria: siempre es promovido.</dd>
            </dl>
        </section>

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">Bloques del informe</h3>
            <dl class="riesgo-leer__leyenda riesgo-leer__leyenda--texto">
                <dt>En riesgo académico</dt>
                <dd>Estudiantes RR o PER.</dd>
                <dt>En seguimiento</dt>
                <dd>Promovidos con competencias bajas: en primaria, <?= (int) SEGUIMIENTO_MIN_B ?> o más
                    en B o <?= (int) SEGUIMIENTO_MIN_C ?> o más en C; en secundaria,
                    <?= (int) SEGUIMIENTO_MIN_C ?> o más en C.</dd>
                <dt>Acompañamiento pedagógico</dt>
                <dd>La suma de los dos bloques. Un estudiante está en uno solo.</dd>
            </dl>
        </section>

        <section class="riesgo-leer__grupo">
            <h3 class="riesgo-leer__h">Distintivos de cada competencia</h3>
            <dl class="riesgo-leer__leyenda">
                <dt><?php $efecto = EFECTO_PER; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Cuenta para la permanencia en el grado.</dd>
                <dt><?php $efecto = EFECTO_RR; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Causa la recuperación.</dd>
                <dt><?php $efecto = EFECTO_LIMITE; require VIEW_PATH . '/admin/cuadros/riesgo/_efecto.php'; ?></dt>
                <dd>Su área cumple justo: una C más la haría fallar.</dd>
                <dt class="riesgo-leer__sin">Sin distintivo</dt>
                <dd>No afecta la promoción.</dd>
            </dl>
        </section>

        <?php // Abre la 2.ª columna (en pantalla ancha y en el A4). ?>
        <section class="riesgo-leer__grupo riesgo-leer__grupo--col2">
            <h3 class="riesgo-leer__h">Ficha de cada estudiante</h3>
            <dl class="riesgo-leer__leyenda riesgo-leer__leyenda--texto">
                <dt>N de M competencias evaluadas</dt>
                <dd>Competencias con nota, de todas las de su plan.</dd>
                <dt>(N de bimestres anteriores)</dt>
                <dd>Cuántas de esas notas son de un bimestre anterior.</dd>
                <dt>(AD · A · B · C)</dt>
                <dd>Cuántas competencias tiene en cada nivel.</dd>
                <dt>N pendientes</dt>
                <dd>Competencias de su plan todavía sin nota.</dd>
                <dt><span class="riesgo-alumno__certeza riesgo-alumno__certeza--proyectada">Depende de <?= $pendEj ?> pendientes</span></dt>
                <dd>Su situación todavía puede cambiar con esas notas.</dd>
                <dt><span class="riesgo-alumno__motivo-rotulo">Motivo</span></dt>
                <dd>Por qué es RR o PER.</dd>
                <dt>Tabla</dt>
                <dd>Sus competencias no aprobatorias, con área y curso, literal, nota y docente.</dd>
                <dt>«&middot; I Bimestre»</dt>
                <dd>Junto a la competencia: la nota es de ese bimestre.</dd>
            </dl>
        </section>

    </div>
    <p class="riesgo-leer__pie">
        <strong>Escala:</strong>
        <?php // Chip de las calificaciones (`.nota-literal`), el mismo de la
              // consulta de notas y del resumen del docente (27/09/2026). ?>
        <?php foreach (escala_rangos() as $lit => $rango): ?>
            <span class="riesgo-leer__lit"><span class="nota-literal nota-literal--<?= e(strtolower($lit)) ?>"><?= e($lit) ?></span> <?= e($rango) ?> (<?= e(descripcion_literal($lit)) ?>)</span>
        <?php endforeach; ?>
        &middot; En primaria aprueban AD y A; en secundaria, también B.
    </p>
</div>
