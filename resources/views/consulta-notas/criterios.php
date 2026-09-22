<?php
/**
 * Explorador de criterios de evaluacion (solo lectura).
 *
 * DOS niveles de cascada — SECCION -> CARGA (area + docente) — y dentro de la
 * carga una TABLA competencia/criterio/descripcion. El tercer acordeon (una
 * lista por competencia) se retiro el 24/08/2026: con 17 cargas y ~119
 * criterios por seccion, y nombres de criterio de 70 caracteres de media, la
 * lista vertical era un muro. La tabla se escanea por columnas.
 *
 * ⚠️ La descripcion NO se anuncia cuando falta: 2233 de 2731 criterios de B2
 * (el 82 %) no la tienen, y escribir "Sin descripcion" 2233 veces era la mitad
 * del ruido de la pantalla. El dato vive en el contador de cada carga.
 *
 * @var array  $periodo   { id, nombre_display, anio, estado }
 * @var array  $periodos  selector de bimestre
 * @var array  $secciones arbol ya podado y contado por el controlador
 * @var int    $total
 * @var int    $conDescripcion
 * @var array  $niveles      [id => nombre]      catalogos de los selectores
 * @var array  $grados       [grado_id => { etiqueta, nivel_id, ... }]
 * @var array  $seccionesCat [seccion_id => { etiqueta, nivel_id, grado_id, ... }]
 * @var array  $docentes     [docente_id => { nombre, secciones[] }]
 * @var array  $filtros      { nivel, grado, seccion, docente } — grado es grado_id
 * @var bool   $abrirTodo       cabe entero: se despliega solo
 * @var bool   $abrirSecciones  demasiado: solo secciones abiertas
 *
 * ⚠️ NO hay buscador de texto, y es deliberado: el director no conoce los
 * criterios —los redactan los docentes—, asi que escribir su texto no es una
 * forma de navegar que el pueda usar. Se recorre por lo que SI conoce.
 */
$hayFiltro = (bool) array_filter($filtros);

// Los filtros vigentes viajan al imprimible: se imprime lo que se esta viendo.
$qs = array_filter($filtros);
?>

<div class="page-header">
    <a href="<?= url('consulta-notas?periodo_id=' . (int) $periodo['id']) ?>" class="btn btn--secondary btn--sm">&larr; Consulta de notas</a>
    <div>
        <h1 class="page-title">Criterios de evaluacion</h1>
        <p class="page-subtitle">
            <?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?> &middot; solo lectura.
            Muestra los criterios de las competencias aprobadas y bloqueadas por su docente.
        </p>
    </div>
    <?php if ($total > 0): ?>
        <a class="btn btn--secondary" target="_blank" rel="noopener"
           href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/criterios/imprimir' . ($qs ? '?' . http_build_query($qs) : '')) ?>">Imprimir</a>
    <?php endif; ?>
</div>

<?php $ejeActivo = 'criterios'; ?>
<?php require VIEW_PATH . '/consulta-notas/_nav.php'; ?>

<div class="card mb-md">
    <div class="card__body">
        <form method="GET" action="<?= url('consulta-notas/' . (int) $periodo['id'] . '/criterios') ?>" class="criterios-filtros">
            <?php // ⚠️ El BIMESTRE ya no es un campo de este formulario (26/08/2026):
                  // es el ambito comun de las tres pestañas y vive en la card de
                  // `_nav.php`, encima de la barra. Aqui solo quedan los filtros que
                  // pertenecen a ESTA pestaña. El periodo viaja en el `action`, asi
                  // que Aplicar y Limpiar siguen operando sobre el bimestre correcto. ?>
            <div class="criterios-filtros__campo">
                <label class="form-label" for="nivel">Nivel</label>
                <select id="nivel" name="nivel" class="form-input">
                    <option value="0">Todos</option>
                    <?php foreach ($niveles as $id => $nom): ?>
                        <option value="<?= (int) $id ?>" <?= (int) $filtros['nivel'] === (int) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="criterios-filtros__campo">
                <label class="form-label" for="grado">Grado</label>
                <select id="grado" name="grado" class="form-input">
                    <option value="0">Todos</option>
                    <?php foreach ($grados as $id => $g): ?>
                        <option value="<?= (int) $id ?>" data-nivel-id="<?= (int) $g['nivel_id'] ?>"
                            <?= (int) $filtros['grado'] === (int) $id ? 'selected' : '' ?>><?= e($g['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="criterios-filtros__campo criterios-filtros__campo--ancho">
                <label class="form-label" for="seccion">Seccion</label>
                <select id="seccion" name="seccion" class="form-input">
                    <option value="0">Todas</option>
                    <?php foreach ($seccionesCat as $id => $s): ?>
                        <option value="<?= (int) $id ?>"
                            data-nivel-id="<?= (int) $s['nivel_id'] ?>" data-grado-id="<?= (int) $s['grado_id'] ?>"
                            <?= (int) $filtros['seccion'] === (int) $id ? 'selected' : '' ?>><?= e($s['etiqueta']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="criterios-filtros__campo criterios-filtros__campo--ancho">
                <label class="form-label" for="docente">Docente</label>
                <select id="docente" name="docente" class="form-input">
                    <option value="0">Todos</option>
                    <?php foreach ($docentes as $id => $d): ?>
                        <?php // Las secciones donde dicta: la cascada lo muestra si AL MENOS
                              // una sobrevive a los otros filtros. Ver criterios.js. ?>
                        <option value="<?= (int) $id ?>" data-secciones="<?= e(implode(',', $d['secciones'])) ?>"
                            <?= (int) $filtros['docente'] === (int) $id ? 'selected' : '' ?>><?= e($d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="criterios-filtros__acciones">
                <button type="submit" class="btn btn--primary">Aplicar</button>
                <?php if ($hayFiltro): ?>
                    <a class="btn btn--secondary" href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/criterios') ?>">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($secciones)): ?>
    <div class="empty-state">
        <?php if ($hayFiltro): ?>
            <p>No hay criterios registrados para esa combinacion en este bimestre.</p>
        <?php else: ?>
            <p>
                Todavia no hay criterios que mostrar en <?= e($periodo['nombre_display']) ?>.
                Esta pantalla muestra unicamente los criterios de competencias que su docente
                ya aprobo y bloqueo; en un bimestre en curso es normal que aun no haya ninguna.
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="criterios-barra">
        <p class="criterios-resumen">
            <strong><?= (int) $total ?></strong> criterio(s) &middot;
            <strong><?= (int) $conDescripcion ?></strong> con descripcion &middot;
            <strong><?= count($secciones) ?></strong> seccion(es)
            <?php if ($hayFiltro && !$abrirTodo): ?>
                <span class="criterios-resumen__aviso">Selecciona una seccion o un docente para verlos desplegados.</span>
            <?php elseif (!$hayFiltro): ?>
                <span class="criterios-resumen__aviso">Elige seccion o docente arriba, o abre una seccion.</span>
            <?php endif; ?>
        </p>
        <div class="criterios-barra__acciones">
            <button type="button" class="btn btn--secondary btn--sm" data-arbol="expandir">Expandir todo</button>
            <button type="button" class="btn btn--secondary btn--sm" data-arbol="contraer">Contraer todo</button>
        </div>
    </div>

    <div class="criterios-arbol" id="criterios-arbol">
        <?php foreach ($secciones as $s): ?>
            <details class="criterios-seccion"<?= ($abrirTodo || $abrirSecciones) ? ' open' : '' ?>>
                <summary class="criterios-seccion__cab">
                    <span class="criterios-seccion__nombre">
                        <?= e($s['grado_nombre'] . ' ' . $s['seccion_nombre']) ?>
                        <span class="criterios-seccion__nivel"><?= e($s['nivel_nombre']) ?></span>
                    </span>
                    <span class="criterios-seccion__meta">
                        <?= (int) $s['n_cargas'] ?> carga(s) &middot; <?= (int) $s['n_criterios'] ?> criterio(s)
                    </span>
                </summary>

                <?php foreach ($s['cargas'] as $c): ?>
                    <?php // El separador va como CARACTER, no como entidad: esta cadena
                          // pasa por el marcador, que escapa. Ver consulta-notas/seccion.php.
                          $area = $c['subarea_nombre']
                              ? $c['area_nombre'] . ' — ' . $c['subarea_nombre']
                              : $c['area_nombre']; ?>
                    <details class="criterios-carga"<?= $abrirTodo ? ' open' : '' ?>>
                        <summary class="criterios-carga__cab">
                            <span class="criterios-carga__id">
                                <span class="criterios-carga__area"><?= e($area) ?></span>
                                <span class="criterios-carga__docente"><?= e($c['docente']) ?></span>
                            </span>
                            <span class="criterios-carga__meta">
                                <span class="criterios-chip"><?= (int) $c['n_criterios'] ?> criterio(s)</span>
                                <span class="criterios-chip criterios-chip--desc"><?= (int) $c['n_con_desc'] ?> con descripcion</span>
                            </span>
                        </summary>

                        <div class="criterios-carga__cuerpo">
                            <table class="criterios-tabla">
                                <thead>
                                    <tr>
                                        <th class="col-comp">Competencia</th>
                                        <th class="col-crit">Criterio</th>
                                        <th class="col-desc">Descripcion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($c['competencias'] as $comp): ?>
                                        <?php $filas = (int) $comp['n_criterios']; $i = 0; ?>
                                        <?php foreach ($comp['criterios'] as $cr): ?>
                                            <?php $desc = trim((string) $cr['descripcion']); ?>
                                            <tr>
                                                <?php if ($i === 0): ?>
                                                    <td class="col-comp" rowspan="<?= $filas ?>">
                                                        <?php // El codigo va DELANTE: los nombres de competencia llegan a
                                                              // 185 caracteres, y un chip al final del parrafo no sirve de
                                                              // ancla. Delante se alinea entre filas y la columna se escanea.
                                                              // La clase es la del PROYECTO (`competencia-card__codigo`, ya en
                                                              // otras 5 vistas): el chip de codigo de competencia es uno solo
                                                              // en todo el sistema. Su `margin-right` ya asume esta posicion. ?>
                                                        <?php if (!empty($comp['codigo'])): ?>
                                                            <span class="competencia-card__codigo"><?= e($comp['codigo']) ?></span>
                                                        <?php endif; ?>
                                                        <?= e($comp['nombre']) ?>
                                                        <?php if (!empty($comp['es_transversal'])): ?>
                                                            <span class="badge badge--info">transversal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                                <td class="col-crit">
                                                    <?= e($cr['nombre']) ?>
                                                </td>
                                                <td class="col-desc"><?= $desc === '' ? '' : e($desc) ?></td>
                                            </tr>
                                            <?php $i++; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <a class="criterios-carga__ver"
                               href="<?= url('consulta-notas/' . (int) $periodo['id'] . '/carga/' . (int) $c['carga_id']) ?>">Ver las notas de esta carga &rarr;</a>
                        </div>
                    </details>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
