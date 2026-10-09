<?php
/**
 * Panel de tutoría — conclusiones y cierre transversal del bimestre.
 *
 * @var array      $seccion       { id, nombre, grado_nombre, nivel_codigo, ... }
 * @var array      $periodos      bimestres del año activo
 * @var array      $periodoSel    bimestre seleccionado
 * @var array      $estadoCargas  { total, bloqueadas, cargas[] }
 * @var array|null $cierre        cierre vigente o null
 * @var bool       $listo         todas las cargas bloqueadas
 * @var string|null $motivoPlazo  null = dentro del plazo de los docentes; si no, por qué
 *                                no se puede registrar (EdicionPeriodoModel, 09/10/2026)
 * @var array      $competencias  TIC/GAMA del nivel
 * @var array      $alumnos
 * @var array      $promedios     [matricula_id => [competencia_id => nota]]
 * @var array      $conclusiones  [matricula_id => [competencia_id => texto]]
 * @var array      $replicas      [matricula_id => [competencia_id => {literal, destinos[]}]]
 *                                réplicas del acta SIAGIE que exigen conclusión (02/10/2026)
 * @var array      $textosReplica [matricula_id => [area_id => texto]]
 */

$nivel    = $seccion['nivel_codigo'] === 'prim' ? 'primaria' : 'secundaria';
$cerrado  = $cierre !== null;
$pid      = (int) $periodoSel['id'];
$enPlazo  = $motivoPlazo === null;
?>

<div class="page-header">
    <a href="<?= url('docente/inicio') ?>"
       class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title page-title--wf page-title--tutoria">Tutoría — Competencias Transversales</h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?> —
            <?= e($seccion['grado_nombre']) ?> —
            Sección <?= e($seccion['nombre']) ?>
        </p>
    </div>
</div>

<!-- Selector de bimestre -->
<div class="tutoria-bimestres">
    <?php foreach ($periodos as $p): ?>
        <a href="<?= url('docente/tutoria/' . $p['id']) ?>"
           class="tutoria-bimestres__item<?= (int) $p['id'] === $pid ? ' tutoria-bimestres__item--activo' : '' ?>">
            <?= e($p['nombre_display']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Estado del bimestre transversal -->
<?php if (!$cerrado && !$enPlazo): ?>
    <div class="flash flash--warning">
        <?= $motivoPlazo === \App\Models\EdicionPeriodoModel::MOTIVO_PLAZO_VENCIDO
            ? 'El plazo para registrar en este bimestre venció: las conclusiones quedan en solo lectura.'
            : 'Este bimestre no está disponible para registrar: las conclusiones quedan en solo lectura.' ?>
    </div>
<?php endif; ?>
<?php if ($cerrado): ?>
    <div class="flash flash--success">
        Bimestre transversal cerrado el
        <strong><?= fechaLima($cierre['cerrado_en'], 'd/m/Y H:i') ?></strong>
        por <?= e($cierre['cerrado_por_nombre']) ?>.
        TIC y GAMA ya aparecen en las boletas de la sección.
    </div>
<?php elseif (!$listo): ?>
    <?php
    // Detalle por carga CON el nombre del docente (decisión 06/08/2026). Esto
    // DEROGA la regla que rigió aquí desde el 14/06/2026 (commit 73838d1), que
    // solo exponía el avance agregado por considerar sensible el detalle: el
    // tutor no podía saber a quién esperar, y esperar a ciegas era el problema.
    // Alcance de lo que se expone: área/carga, docente y si sus transversales
    // están aprobadas. NADA de notas de otras áreas ni datos personales — la
    // protección del DNI de aquel mismo lote NO se toca.
    $pct = $estadoCargas['total'] > 0
        ? round($estadoCargas['bloqueadas'] / $estadoCargas['total'] * 100, 2)
        : 0;

    // Solo las cargas que APORTAN transversales (total_comp > 0). Las de tutoría
    // y las no dueñas de una sección unidocente valen 0 a propósito: listarlas
    // haría creer al tutor que espera por alguien que nunca va a aportar.
    $cargasAporte = array_values(array_filter(
        $estadoCargas['cargas'],
        static fn(array $c): bool => (int) $c['total_comp'] > 0
    ));
    $cargasListas = count(array_filter(
        $cargasAporte,
        static fn(array $c): bool => (int) $c['comp_bloqueadas'] >= (int) $c['total_comp']
    ));
    ?>
    <div class="flash flash--warning">
        <span class="btn-icon btn-icon--wait" aria-hidden="true"></span>
        <span>
            Aún no puedes cerrar ni registrar conclusiones: faltan cargas por
            aprobar sus competencias transversales, así que el promedio todavía
            puede cambiar. Abajo puedes ver los promedios provisionales.
        </span>
    </div>

    <div class="card mb-lg">
        <div class="card__header">
            <h2 class="card__title">
                Cargas con transversales aprobadas
                — <?= $cargasListas ?> de <?= count($cargasAporte) ?>
            </h2>
        </div>
        <div class="card__body">
            <div class="carga-progreso">
                <div class="carga-progreso__track">
                    <div class="carga-progreso__fill carga-progreso__fill--parcial"
                         style="--pct: <?= $pct ?>%"></div>
                </div>
                <div class="carga-progreso__meta">
                    <span>Competencias transversales aprobadas en la sección</span>
                    <span class="carga-progreso__valor carga-progreso__valor--parcial"><?= number_format($pct, 2) ?>%</span>
                </div>
            </div>

            <?php if (!empty($cargasAporte)): ?>
                <ul class="tutoria-cargas">
                    <?php foreach ($cargasAporte as $c): ?>
                        <?php $ok = (int) $c['comp_bloqueadas'] >= (int) $c['total_comp']; ?>
                        <li class="tutoria-cargas__item">
                            <span class="tutoria-cargas__area"><?= e($c['nombre_display'] ?? '') ?></span>
                            <span class="tutoria-cargas__docente"><?= e($c['docente_nombre'] ?? 'Sin docente') ?></span>
                            <span class="carga-transversal carga-transversal--<?= $ok ? 'completo' : 'pendiente' ?>">
                                <?= $ok
                                    ? '✓ Aprobadas'
                                    : (int) $c['comp_bloqueadas'] . ' de ' . (int) $c['total_comp'] ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($enPlazo): ?>
    <div class="flash flash--info">
        Todas las cargas de la sección están aprobadas. Revisa los promedios y
        registra las conclusiones descriptivas: puedes escribir una para cualquier
        alumno; las marcadas como requeridas (primaria: B y C · secundaria: C)
        son obligatorias para cerrar el bimestre transversal.
    </div>
<?php endif; ?>

<!-- Tabla de promedios TIC/GAMA -->
<?php
// La tabla se pinta SIEMPRE desde el 06/08/2026 (antes exigía $listo || $cerrado).
// En estado provisional es de SOLO LECTURA: muestra el promedio de las cargas ya
// aprobadas y un guion donde todavía no hay aporte. Escribir conclusiones se
// habilita cuando todas las cargas aportaron — el guard vive en el servidor
// (TutoriaController::guardarConclusion), esto es solo su cara visible.
$editable = !$cerrado && $listo && $enPlazo;
?>
<?php if (empty($alumnos)): ?>
    <div class="empty-state"><p>No hay alumnos matriculados en la sección.</p></div>
<?php else: ?>

<div class="card mb-lg">
    <div class="card__header">
        <h2 class="card__title">
            Promedios C. Transversales — <?= e($periodoSel['nombre_display']) ?>
            <?php if (!$listo && !$cerrado): ?>
                <span class="carga-transversal carga-transversal--progreso">Provisional</span>
            <?php endif; ?>
        </h2>
    </div>

    <?php
    // Estadisticas por competencia. Aqui la tabla lleva DOS competencias
    // (TIC y GAMA) en columnas, asi que va un bloque por cada una, titulado;
    // en las otras pantallas el titulo lo pone la card y no hace falta.
    //
    // El parcial recibe los datos con el prefijo `stats*` para no pisar
    // `$alumnos` de esta vista, que se sigue usando en la tabla de abajo, y se
    // limpia solo entre vueltas.
    //
    // Las transversales NO se exoneran (ver docs/modulos/consulta-notas-ampliada.md),
    // por eso la lista de exonerados va vacia y no se consulta.
    foreach ($competencias as $comp):
        $cid = (int) $comp['id'];

        $statsAlumnos = [];
        foreach ($alumnos as $al) {
            $mid  = (int) $al['matricula_id'];
            $nota = $promedios[$mid][$cid] ?? null;
            $statsAlumnos[] = [
                'matricula_id' => $mid,
                'promedio'     => $nota,
                'literal'      => $nota !== null ? nota_a_literal((int) $nota, $nivel) : null,
            ];
        }

        $statsExonerados = [];
        $statsNivel      = $seccion['nivel_codigo'];
        $statsTitulo     = $comp['nombre_corto'] ?? ($comp['codigo_minedu'] ?? '');

        require VIEW_PATH . '/shared/_stats-competencia.php';
    endforeach;
    ?>
    <div class="tabla-notas-wrapper">
        <table class="tabla-resumen tutoria-tabla">
            <thead>
                <tr>
                    <th class="col-num" rowspan="2">N°</th>
                    <th class="col-nombre" rowspan="2">Apellidos y nombres</th>
                    <?php foreach ($competencias as $comp): ?>
                        <th class="th-competencia col-resultado col-resultado--inicio text-center"
                            colspan="2" title="<?= e($comp['nombre_completo']) ?>">
                            <?= e($comp['nombre_corto'] ?? $comp['codigo_minedu']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="col-conclusion" rowspan="2">Conclusiones descriptivas</th>
                </tr>
                <tr>
                    <?php foreach ($competencias as $comp): ?>
                        <th class="col-numeral col-resultado col-resultado--inicio text-center"
                            title="Promedio de las cargas bloqueadas (calculado automáticamente)">Promedio numeral</th>
                        <th class="col-literal col-resultado text-center">Literal</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnos as $i => $alumno): ?>
                    <?php $matId = (int) $alumno['matricula_id']; ?>
                    <tr>
                        <td class="col-num"><?= $i + 1 ?></td>
                        <td class="col-nombre"><?= e($alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'] . ', ' . $alumno['nombres']) ?></td>

                        <?php foreach ($competencias as $comp): ?>
                            <?php
                            $cid     = (int) $comp['id'];
                            $nota    = $promedios[$matId][$cid] ?? null;
                            $literal = $nota !== null ? nota_a_literal((int) $nota, $nivel) : null;
                            ?>
                            <td class="col-numeral col-resultado col-resultado--inicio text-center">
                                <?php if ($nota !== null): ?>
                                    <span class="nota-numeral nota-numeral--<?= strtolower($literal) ?>">
                                        <?= fmt_nota((int) $nota) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-literal col-resultado text-center">
                                <?php if ($literal !== null): ?>
                                    <span class="nota-literal nota-literal--<?= strtolower($literal) ?>">
                                        <?= $literal ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>

                        <td class="col-conclusion">
                            <?php $algunTexto = false; ?>
                            <?php foreach ($competencias as $comp): ?>
                                <?php
                                $cid         = (int) $comp['id'];
                                $nota        = $promedios[$matId][$cid] ?? null;
                                $literal     = $nota !== null ? nota_a_literal((int) $nota, $nivel) : null;
                                $obligatoria = $literal !== null
                                    && conclusion_es_obligatoria($literal, $nivel);
                                $texto       = $conclusiones[$matId][$cid] ?? '';
                                $nombreComp  = $comp['nombre_corto'] ?? ($comp['codigo_minedu'] ?? '');
                                if ($texto !== '') { $algunTexto = true; }
                                ?>
                                <?php if ($editable): ?>
                                    <?php // El tutor puede registrar conclusión a CUALQUIER alumno.
                                          // Las OBLIGATORIAS (B/C prim, C sec) nacen abiertas y sin toggle:
                                          // hay que llenarlas. Las OPCIONALES siempre llevan toggle para
                                          // mostrar/ocultar el textarea, tengan o no texto; nacen colapsadas
                                          // si están vacías y abiertas si ya traen una conclusión. ?>
                                    <?php
                                    $concluId  = 'concl-' . $matId . '-' . $cid;
                                    $colapsada = !$obligatoria && $texto === '';
                                    $conToggle = !$obligatoria; // las opcionales SIEMPRE se pueden ocultar
                                    ?>
                                    <div class="tutoria-conclusion<?= $colapsada ? ' tutoria-conclusion--colapsada' : '' ?>">
                                        <?php if ($conToggle): ?>
                                            <button type="button"
                                                    class="btn btn--<?= $colapsada ? 'secondary' : 'primary' ?> btn--sm tutoria-conclusion__toggle<?= $texto !== '' ? ' tutoria-conclusion__toggle--con-texto' : '' ?>"
                                                    data-target="<?= $concluId ?>"
                                                    aria-expanded="<?= $colapsada ? 'false' : 'true' ?>"
                                                    title="Mostrar u ocultar la conclusión descriptiva (<?= e($nombreComp) ?>)"
                                                    data-label-abrir="✎ <?= e($nombreComp) ?>"
                                                    data-label-cerrar="✕ <?= e($nombreComp) ?>"><?= $colapsada ? '✎' : '✕' ?> <?= e($nombreComp) ?></button>
                                        <?php endif; ?>
                                        <div class="tutoria-conclusion__campo"<?= $colapsada ? ' hidden' : '' ?>>
                                            <label class="tutoria-conclusion__label" for="<?= $concluId ?>">
                                                <?= e($nombreComp) ?>
                                                <?php if ($obligatoria): ?>
                                                    <small class="obligatorio">* Requerida (<?= $literal ?>)</small>
                                                <?php else: ?>
                                                    <small class="text-muted">(opcional)</small>
                                                <?php endif; ?>
                                            </label>
                                            <textarea
                                                id="<?= $concluId ?>"
                                                class="form-input textarea-conclusion-transversal"
                                                rows="2"
                                                maxlength="500"
                                                data-matricula-id="<?= $matId ?>"
                                                data-competencia-id="<?= $cid ?>"
                                                data-obligatorio="<?= $obligatoria ? '1' : '0' ?>"
                                                placeholder="<?= $obligatoria ? '* Obligatoria' : 'Conclusión opcional' ?>"><?= e($texto) ?></textarea>
                                        </div>
                                    </div>
                                <?php elseif ($texto !== ''): ?>
                                    <p class="conclusion-texto">
                                        <strong><?= e($nombreComp) ?>:</strong>
                                        <?= e($texto) ?>
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!$editable && !$algunTexto): ?>
                                <span class="text-muted text-sm">
                                    <?= $cerrado
                                        ? '— sin conclusión'
                                        : '— se habilita al aprobar todas las cargas' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($editable): ?>
        <div class="resumen-footer tutoria-footer">
            <button class="btn btn--primary" id="btn-guardar-conclusiones-trans"
                    data-periodo-id="<?= $pid ?>">
                <span class="btn-icon btn-icon--save" aria-hidden="true"></span>
                Guardar conclusiones
            </button>
            <button class="btn btn--success" id="btn-cerrar-transversal"
                    data-periodo-id="<?= $pid ?>" disabled
                    title="Primero guarda las conclusiones">
                <span class="btn-icon btn-icon--upload" aria-hidden="true"></span>
                Aprobar y Bloquear
            </button>
            <span id="tutoria-status"></span>
        </div>
    <?php endif; ?>
</div>

<?php
// RÉPLICAS DEL ACTA SIAGIE (02/10/2026, migración 072). En 5.º de secundaria la
// nota de GAMA también llena las actas de otras áreas (EQUIVALENCIAS_ACTA_SIAGIE).
// Cuando su conclusión es OBLIGATORIA, el tutor escribe una por área destino.
// Solo con promedio definitivo: en estado provisional el literal puede cambiar.
// Los textareas comparten clase con los de arriba, así que el mismo botón los
// guarda y el mismo control de obligatorias los exige.
$nombresComp = array_column($competencias, 'nombre_corto', 'id');
$filasReplica = [];
if ($replicas !== [] && ($listo || $cerrado)) {
    foreach ($alumnos as $al) {
        $mid = (int) $al['matricula_id'];
        foreach ($replicas[$mid] ?? [] as $compId => $req) {
            $filasReplica[] = ['alumno' => $al, 'comp_id' => (int) $compId, 'req' => $req];
        }
    }
}
?>
<?php if ($filasReplica !== []): ?>
<div class="card mb-lg">
    <div class="card__header">
        <h2 class="card__title">Conclusiones descriptivas requeridas</h2>
    </div>
    <div class="card__body">
        <p class="text-sm text-muted">
            En el acta del SIAGIE, la nota de estas competencias también se registra en
            otras áreas. Escribe una conclusión para cada área: se usa solo en el acta,
            no sale en la boleta.
        </p>
    </div>
    <div class="tabla-notas-wrapper">
        <table class="tabla-resumen tutoria-tabla">
            <thead>
                <tr>
                    <th class="col-num">N°</th>
                    <th class="col-nombre">Apellidos y nombres</th>
                    <th class="col-literal col-resultado text-center">Competencia</th>
                    <th class="col-conclusion">Conclusiones descriptivas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filasReplica as $i => $fila): ?>
                    <?php
                    $alumno  = $fila['alumno'];
                    $matId   = (int) $alumno['matricula_id'];
                    $literal = $fila['req']['literal'];
                    $nombreFuente = $nombresComp[$fila['comp_id']] ?? '';
                    ?>
                    <tr>
                        <td class="col-num"><?= $i + 1 ?></td>
                        <td class="col-nombre"><?= e($alumno['apellido_paterno'] . ' ' . $alumno['apellido_materno'] . ', ' . $alumno['nombres']) ?></td>
                        <td class="col-literal col-resultado text-center">
                            <?= e($nombreFuente) ?>
                            <span class="nota-literal nota-literal--<?= strtolower($literal) ?>"><?= $literal ?></span>
                        </td>
                        <td class="col-conclusion">
                            <?php foreach ($fila['req']['destinos'] as $d): ?>
                                <?php
                                $areaId = (int) $d['area_id'];
                                $texto  = $textosReplica[$matId][$areaId] ?? '';
                                ?>
                                <?php if ($editable): ?>
                                    <?php $replicaId = 'concl-rep-' . $matId . '-' . $areaId; ?>
                                    <div class="tutoria-conclusion">
                                        <div class="tutoria-conclusion__campo">
                                            <label class="tutoria-conclusion__label" for="<?= $replicaId ?>">
                                                <?= e($d['area_nombre']) ?>
                                                <small class="obligatorio">* Requerida (<?= $literal ?>)</small>
                                            </label>
                                            <textarea
                                                id="<?= $replicaId ?>"
                                                class="form-input textarea-conclusion-transversal"
                                                rows="2"
                                                maxlength="500"
                                                data-matricula-id="<?= $matId ?>"
                                                data-area-id="<?= $areaId ?>"
                                                data-obligatorio="1"
                                                placeholder="* Obligatoria"><?= e($texto) ?></textarea>
                                        </div>
                                    </div>
                                <?php elseif ($texto !== ''): ?>
                                    <p class="conclusion-texto">
                                        <strong><?= e($d['area_nombre']) ?>:</strong>
                                        <?= e($texto) ?>
                                    </p>
                                <?php else: ?>
                                    <p class="conclusion-texto text-muted">
                                        <strong><?= e($d['area_nombre']) ?>:</strong>
                                        — sin conclusión
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>
