<?php
/**
 * @var array      $cargas
 * @var array|null $periodo      bimestre seleccionado (activo por defecto)
 * @var array      $periodos     bimestres del año vigente (activo + cerrados)
 * @var bool       $esHistorico  el bimestre elegido NO es el activo (solo lectura)
 * @var bool       $tieneAula  es tutor(a) de aula (unidocente de alguna seccion)
 * @var string|null $aula      etiqueta del aula (ej. "1° A") cuando tiene aula
 */
?>

<div class="page-header">
    <a href="<?= url('docente/inicio') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <h1 class="page-title page-title--wf page-title--cargas">Mis cargas académicas</h1>
    <?php if ($tieneAula): ?>
        <span class="badge badge--aula">
            <?= e(rol_aula(auth()['sexo'] ?? null)) ?> — <?= e($aula) ?> Primaria
        </span>
    <?php endif; ?>
    <?php if ($periodo): ?>
        <span class="badge <?= $esHistorico ? 'badge--warning' : 'badge--activo' ?>">
            <?= e($periodo['nombre_display'] ?? 'Periodo activo') ?>
            — <?= e($periodo['anio']) ?>
            <?= $esHistorico ? ' · solo lectura' : '' ?>
        </span>
    <?php else: ?>
        <span class="badge badge--warning">Sin periodo activo</span>
    <?php endif; ?>
    <?php if ($periodo && !empty($cargas)): ?>
        <a href="<?= url('docente/registro-auxiliar?periodo_id=' . (int) $periodo['id']) ?>"
           class="btn btn--registro btn--sm">
            <span class="btn-icon btn-icon--registro" aria-hidden="true"></span>
            Registro auxiliar
        </a>
    <?php endif; ?>
</div>

<?php if (count($periodos) > 1): ?>
    <div class="card mb-md">
        <div class="card__body">
            <form method="GET" action="<?= url('docente/mis-cargas') ?>" class="cargas-periodo">
                <label class="form-label" for="periodo_id">Bimestre</label>
                <select name="periodo_id" id="periodo_id"
                        class="form-select cargas-periodo__select" onchange="this.form.submit()">
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"
                            <?= $periodo && (int) $periodo['id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nombre_display']) ?> <?= e($p['anio']) ?>
                            (<?= $p['estado'] === 'cerrado' ? 'cerrado' : 'activo' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm text-muted cargas-periodo__hint">
                    Elige un bimestre cerrado para revisar tus notas en solo lectura.
                </p>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($cargas)): ?>
    <div class="empty-state">
        <p>No tienes cargas académicas asignadas para este año.</p>
        <p>Comunícate con el administrador.</p>
    </div>

<?php else: ?>

    <?php
    // Avance GLOBAL de la carga = competencias propias del area + transversales
    // TIC/GAMA que el docente registra en su propia carga. Devuelve
    // [bloqueadas, total, pct, estado]. En una carga de tipo transversal
    // (modelo antiguo del tutor) sus competencias YA son las transversales, por
    // lo que NO se vuelven a sumar (evitaria un doble conteo).
    $avanceCarga = function (array $c): array {
        $esTransversal = ($c['area_tipo'] ?? '') === 'transversal';
        $total      = (int) ($c['total_competencias']      ?? 0);
        $bloqueadas = (int) ($c['competencias_bloqueadas'] ?? 0);
        if (!$esTransversal) {
            $total      += (int) ($c['total_transversales']      ?? 0);
            $bloqueadas += (int) ($c['transversales_bloqueadas'] ?? 0);
        }
        $pct    = $total > 0 ? (int) round($bloqueadas / $total * 100) : 0;
        $estado = $pct >= 100 ? 'completo' : ($pct > 0 ? 'parcial' : 'vacio');
        return [$bloqueadas, $total, $pct, $estado];
    };

    // Sub-avance SOLO de transversales: [bloqueadas, total, estado] o NULL.
    // NULL en cargas transversales (la barra principal ya las representa) o si
    // el nivel no define transversales.
    $transAvance = function (array $c): ?array {
        if (($c['area_tipo'] ?? '') === 'transversal') {
            return null;
        }
        $total = (int) ($c['total_transversales'] ?? 0);
        if ($total === 0) {
            return null;
        }
        $bloqueadas = (int) ($c['transversales_bloqueadas']   ?? 0);
        $criterios  = (int) ($c['transversales_con_criterios'] ?? 0);
        $estado     = $bloqueadas >= $total
            ? 'completo'
            : (($bloqueadas > 0 || $criterios > 0) ? 'progreso' : 'pendiente');
        return [$bloqueadas, $total, $estado];
    };

    // Devuelve [clase_badge, texto_badge] según el estado GLOBAL de la carga
    // (propias + transversales). "Bloqueada" solo cuando TODO esta aprobado.
    $estadoBadge = function (array $c) use ($avanceCarga): array {
        [$bloqueadas, $total] = $avanceCarga($c);
        $esTransversal = ($c['area_tipo'] ?? '') === 'transversal';
        $conCriterios  = (int) ($c['competencias_con_criterios'] ?? 0)
            + ($esTransversal ? 0 : (int) ($c['transversales_con_criterios'] ?? 0));

        if ($total === 0 || ($bloqueadas === 0 && $conCriterios === 0)) {
            return ['badge--error',   'Sin criterios'];
        }
        if ($bloqueadas === $total) {
            return ['badge--activo',  'Bloqueada <span class="badge__icono" aria-hidden="true"></span>'];
        }
        if ($bloqueadas > 0) {
            return ['badge--warning', 'Parcial'];
        }
        return ['badge--warning', 'Pendiente'];
    };

    // Pill de transversales con contador "X/Y" (ej. 1/2, 2/2). Devuelve
    // [modificador, texto] o NULL si no aplica (carga transversal / sin TIC-GAMA).
    $transBadge = function (array $c) use ($transAvance): ?array {
        $av = $transAvance($c);
        if ($av === null) {
            return null;
        }
        [$bloqueadas, $total, $estado] = $av;
        $icono = $estado === 'completo'
            ? ' <span class="carga-transversal__icono" aria-hidden="true"></span>'
            : '';
        return [$estado, 'Transversales ' . $bloqueadas . '/' . $total . $icono];
    };

    // Destino del clic en una carga: en bimestre activo va al flujo editable;
    // en un bimestre cerrado (historico) va a la grilla de SOLO LECTURA.
    $cargaUrl = function ($id) use ($esHistorico, $periodo) {
        $id = (int) $id;
        return $esHistorico
            ? url('docente/calificaciones/' . $id . '/historial/' . (int) $periodo['id'])
            : url('docente/calificaciones/' . $id);
    };

    // Destino de la card de AREA (unidocente, area con subareas): una sola
    // pantalla con todas las subareas + transversales. Historico -> solo lectura.
    $areaUrl = function ($seccionId, $areaId) use ($esHistorico, $periodo) {
        $base = 'docente/calificaciones/area/' . (int) $seccionId . '/' . (int) $areaId;
        return $esHistorico
            ? url($base . '/historial/' . (int) $periodo['id'])
            : url($base);
    };

    // Agrupar: nivel+grado → seccion_id → area_id → cargas[]
    $agrupadas = [];
    foreach ($cargas as $carga) {
        $ng = $carga['nivel_nombre'] . ' — ' . $carga['grado_nombre'];
        $agrupadas[$ng][$carga['seccion_id']][$carga['area_id']][] = $carga;
    }

    // ¿El grupo es el AULA del docente? Solo si es el TUTOR de una seccion
    // es_unidocente (es_aula). Un especialista (Ingles, Ed. Fisica) que dicta en
    // una seccion unidocente NO es su aula. Sirve para destacar el encabezado
    // como "Mi aula" y para presentar las areas con subareas como una sola card.
    $grupoEsAula = function (array $secciones): bool {
        foreach ($secciones as $areas) {
            foreach ($areas as $areaCargas) {
                foreach ($areaCargas as $c) {
                    if (!empty($c['es_aula'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    };

    // Primera carga del grupo (para leer grado/seccion/nivel del encabezado).
    $primeraDelGrupo = function (array $secciones): ?array {
        foreach ($secciones as $areas) {
            foreach ($areas as $areaCargas) {
                return $areaCargas[0];
            }
        }
        return null;
    };

    // El AULA va primero; el resto (caso mixto: cargas de especialista en otras
    // secciones) conserva su orden natural por nivel/grado.
    $aulaGrupos  = [];
    $otrosGrupos = [];
    foreach ($agrupadas as $grupo => $secciones) {
        if ($grupoEsAula($secciones)) {
            $aulaGrupos[$grupo] = $secciones;
        } else {
            $otrosGrupos[$grupo] = $secciones;
        }
    }
    $agrupadas = $aulaGrupos + $otrosGrupos;
    ?>

    <?php foreach ($agrupadas as $grupo => $secciones): ?>
        <?php
        $esAula = $grupoEsAula($secciones);
        // El grado se diferencia por jerarquia tipografica: nivel como
        // antetitulo + grado en grande. Sin color (los grados son secuenciales).
        $pc = $primeraDelGrupo($secciones);
        ?>
        <div class="card card--grado mb-md">
            <div class="card__header">
                <?php if ($esAula): ?>
                    <div class="grado-head">
                        <span class="grado-head__eyebrow"><?= e($pc['nivel_nombre']) ?></span>
                        <h2 class="card__title grado-head__title">
                            Mi aula — <?= e(trim($pc['grado_nombre'] . ' ' . $pc['seccion_nombre'])) ?>
                        </h2>
                    </div>
                    <span class="badge badge--aula"><?= e(rol_aula(auth()['sexo'] ?? null)) ?></span>
                <?php else: ?>
                    <div class="grado-head">
                        <span class="grado-head__eyebrow"><?= e($pc['nivel_nombre']) ?></span>
                        <h2 class="card__title grado-head__title"><?= e($pc['grado_nombre']) ?></h2>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card__body">
                <div class="cargas-grid">

                    <?php foreach ($secciones as $seccionId => $areas): ?>
                        <?php if (!$esAula):
                            // Ancla de seccion: la LETRA es el identificador (unica
                            // dentro del grado), por eso va grande y SOLA. La letra
                            // no se repite en texto; el grado ya lo da el encabezado
                            // del bloque y la palabra "Seccion" solo rotula el glifo.
                            $primerArea = reset($areas);
                            $secC       = $primerArea[0];
                            $secLetra   = mb_strtoupper(mb_substr((string) ($secC['seccion_nombre'] ?? ''), 0, 1));
                        ?>
                            <div class="seccion-ancla">
                                <span class="seccion-ancla__rotulo">Sección</span>
                                <span class="seccion-ancla__letra"><?= e($secLetra ?: '?') ?></span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($areas as $areaId => $areaCargas): ?>
                            <?php
                            $primeraC = $areaCargas[0];
                            $esGrupo  = count($areaCargas) > 1;
                            ?>

                            <?php if ($esGrupo && $esAula): ?>

                                <?php
                                // UNIDOCENTE: el area con subareas se presenta como UNA card
                                // (ej. "Matematica") que abre la vista de area unificada, en
                                // vez de una sub-item por subarea. Avance AGREGADO sobre las
                                // subarea-cargas + transversales (contadas una vez, A.3).
                                $aBloq = $aTot = $aConCrit = 0;
                                $trTot = $trBloq = $trCrit = 0;
                                foreach ($areaCargas as $cc) {
                                    [$b, $t] = $avanceCarga($cc);
                                    $aBloq    += $b;
                                    $aTot     += $t;
                                    $aConCrit += (int) ($cc['competencias_con_criterios'] ?? 0);
                                    $trTot    += (int) ($cc['total_transversales'] ?? 0);
                                    $trBloq   += (int) ($cc['transversales_bloqueadas'] ?? 0);
                                    $trCrit   += (int) ($cc['transversales_con_criterios'] ?? 0);
                                }
                                $aPct    = $aTot > 0 ? (int) round($aBloq / $aTot * 100) : 0;
                                $aEstado = $aPct >= 100 ? 'completo' : ($aPct > 0 ? 'parcial' : 'vacio');
                                if ($aTot === 0 || ($aBloq === 0 && $aConCrit === 0 && $trCrit === 0)) {
                                    $aBadgeClase = 'badge--error';  $aBadgeTexto = 'Sin criterios';
                                } elseif ($aBloq === $aTot) {
                                    $aBadgeClase = 'badge--activo';
                                    $aBadgeTexto = 'Bloqueada <span class="badge__icono" aria-hidden="true"></span>';
                                } elseif ($aBloq > 0) {
                                    $aBadgeClase = 'badge--warning'; $aBadgeTexto = 'Parcial';
                                } else {
                                    $aBadgeClase = 'badge--warning'; $aBadgeTexto = 'Pendiente';
                                }
                                ?>
                                <a href="<?= $areaUrl($seccionId, $areaId) ?>"
                                   class="carga-item carga-item--aula <?= $periodo ? '' : 'carga-item--disabled' ?>">

                                    <div class="carga-item__nombre">
                                        <?= e($primeraC['area_nombre']) ?>
                                    </div>

                                    <span class="badge <?= $aBadgeClase ?> carga-item__badge">
                                        <?= $aBadgeTexto ?>
                                    </span>

                                    <div class="carga-item__horas">
                                        <?= count($areaCargas) ?> subáreas
                                    </div>

                                    <div class="carga-progreso">
                                        <div class="carga-progreso__track">
                                            <div class="carga-progreso__fill carga-progreso__fill--<?= $aEstado ?>"
                                                 style="--pct: <?= $aPct ?>%"></div>
                                        </div>
                                        <div class="carga-progreso__meta">
                                            <span><?= $aBloq ?>/<?= $aTot ?> aprobadas</span>
                                            <span class="carga-progreso__valor carga-progreso__valor--<?= $aEstado ?>">
                                                <?= $aPct ?>%
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($trTot > 0): ?>
                                        <?php
                                        $trEstado = $trBloq >= $trTot
                                            ? 'completo'
                                            : (($trBloq > 0 || $trCrit > 0) ? 'progreso' : 'pendiente');
                                        $trIcono = $trEstado === 'completo'
                                            ? ' <span class="carga-transversal__icono" aria-hidden="true"></span>'
                                            : '';
                                        ?>
                                        <span class="carga-transversal carga-transversal--<?= $trEstado ?>">
                                            Transversales <?= $trBloq ?>/<?= $trTot ?><?= $trIcono ?>
                                        </span>
                                    <?php endif; ?>

                                </a>

                            <?php elseif ($esGrupo): ?>

                                <div class="carga-area <?= $esAula ? 'carga-area--aula' : '' ?>">
                                    <div class="carga-area__header">
                                        <span class="carga-area__nombre">
                                            <?= e($primeraC['area_nombre']) ?>
                                        </span>
                                    </div>
                                    <div class="carga-area__items">
                                        <?php foreach ($areaCargas as $carga): ?>
                                            <?php [$bloqueadas, $total, $pct, $estado] = $avanceCarga($carga); ?>
                                            <?php [$badgeClase, $badgeTexto] = $estadoBadge($carga); ?>
                                            <a href="<?= $cargaUrl($carga['id']) ?>"
                                               class="carga-item <?= $periodo ? '' : 'carga-item--disabled' ?>">

                                                <?php if ($esAula && !empty($carga['competencia_corto'])): ?>
                                                    <?php if (!empty($carga['competencia_codigo'])): ?>
                                                        <span class="carga-item__tag"><?= e($carga['competencia_codigo']) ?></span>
                                                    <?php endif; ?>
                                                    <div class="carga-item__nombre">
                                                        <?= e($carga['competencia_corto']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="carga-item__nombre">
                                                        <?= e($carga['subarea_nombre'] ?? $carga['nombre_display']) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <span class="badge <?= $badgeClase ?> carga-item__badge">
                                                    <?= $badgeTexto ?>
                                                </span>

                                                <div class="carga-item__horas">
                                                    <?= (int) $carga['horas_semanales'] > 0
                                                        ? e($carga['horas_semanales']) . ' hrs/semana'
                                                        : '—' ?>
                                                </div>

                                                <div class="carga-progreso">
                                                    <div class="carga-progreso__track">
                                                        <div class="carga-progreso__fill carga-progreso__fill--<?= $estado ?>"
                                                             style="--pct: <?= $pct ?>%"></div>
                                                    </div>
                                                    <div class="carga-progreso__meta">
                                                        <span><?= $bloqueadas ?>/<?= $total ?> aprobadas</span>
                                                        <span class="carga-progreso__valor carga-progreso__valor--<?= $estado ?>">
                                                            <?= $pct ?>%
                                                        </span>
                                                    </div>
                                                </div>

                                                <?php if ($tr = $transBadge($carga)): ?>
                                                    <?php [$trClase, $trTexto] = $tr; ?>
                                                    <span class="carga-transversal carga-transversal--<?= $trClase ?>">
                                                        <?= $trTexto ?>
                                                    </span>
                                                <?php endif; ?>

                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            <?php else: ?>

                                <?php
                                $carga = $areaCargas[0];
                                [$bloqueadas, $total, $pct, $estado] = $avanceCarga($carga);
                                [$badgeClase, $badgeTexto] = $estadoBadge($carga);
                                ?>
                                <a href="<?= $cargaUrl($carga['id']) ?>"
                                   class="carga-item <?= $esAula ? 'carga-item--aula' : '' ?> <?= $periodo ? '' : 'carga-item--disabled' ?>">

                                    <div class="carga-item__nombre">
                                        <?= e($carga['nombre_display']) ?>
                                    </div>

                                    <span class="badge <?= $badgeClase ?> carga-item__badge">
                                        <?= $badgeTexto ?>
                                    </span>

                                    <div class="carga-item__horas">
                                        <?= (int) $carga['horas_semanales'] > 0
                                            ? e($carga['horas_semanales']) . ' hrs/semana'
                                            : '—' ?>
                                    </div>

                                    <div class="carga-progreso">
                                        <div class="carga-progreso__track">
                                            <div class="carga-progreso__fill carga-progreso__fill--<?= $estado ?>"
                                                 style="--pct: <?= $pct ?>%"></div>
                                        </div>
                                        <div class="carga-progreso__meta">
                                            <span><?= $bloqueadas ?>/<?= $total ?> aprobadas</span>
                                            <span class="carga-progreso__valor carga-progreso__valor--<?= $estado ?>">
                                                <?= $pct ?>%
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($tr = $transBadge($carga)): ?>
                                        <?php [$trClase, $trTexto] = $tr; ?>
                                        <span class="carga-transversal carga-transversal--<?= $trClase ?>">
                                            <?= $trTexto ?>
                                        </span>
                                    <?php endif; ?>

                                </a>

                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; // fin empty($cargas) ?>
