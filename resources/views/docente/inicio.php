<?php
/**
 * Dashboard del docente.
 * @var array|null $periodo
 * @var bool  $tieneAula   es tutor(a) de aula (unidocente de alguna seccion)
 * @var bool  $soloAula    el aula es TODA su carga (rotulo "Mi aula")
 * @var string|null $aula  etiqueta del aula (ej. "1° A") cuando tiene aula
 * @var array $chips       chips de identidad combinables [{tipo, texto}]
 * @var int   $nAreasAula  cantidad de areas distintas del aula
 * @var int   $nCargas
 * @var int   $avance        % aprobado solo de cargas academicas (card Mis cargas)
 * @var int   $avanceTotal   % de TODAS las responsabilidades (academicas + tutoria + conducta)
 * @var int   $sumTotal,$sumBloq
 * @var int   $completas,$sinCriterios
 * @var int|null $diasCierre
 * @var array $pendientes
 * @var array|null $tutoria
 * @var array|null $conducta
 * @var array|null $riesgoTutor  {seccion, periodo, resumen} (solo tutores)
 * @var array $niveles
 * @var array $nominaResumen
 * @var int   $totalNomina
 * @var array $horario
 * @var array $auth_user
 */

// Estado del widget "días para el cierre"
if ($diasCierre === null)      { $diasMod = 'muted'; $diasTxt = '—';  $diasSub = 'Sin fecha límite'; }
elseif ($diasCierre < 0)       { $diasMod = 'muted'; $diasTxt = abs($diasCierre); $diasSub = 'días desde el cierre'; }
elseif ($diasCierre <= 3)      { $diasMod = 'err';   $diasTxt = $diasCierre; $diasSub = 'días para el cierre'; }
elseif ($diasCierre <= 7)      { $diasMod = 'warn';  $diasTxt = $diasCierre; $diasSub = 'días para el cierre'; }
else                           { $diasMod = 'ok';    $diasTxt = $diasCierre; $diasSub = 'días para el cierre'; }

$avEstado      = $avance >= 100 ? 'completo' : ($avance > 0 ? 'parcial' : 'vacio');
$avEstadoTotal = $avanceTotal >= 100 ? 'completo' : ($avanceTotal > 0 ? 'parcial' : 'vacio');
?>

<?php
$saludo = match($auth_user['sexo'] ?? null) {
    'M'     => 'Bienvenido',
    'F'     => 'Bienvenida',
    default => 'Bienvenido(a)',
};
?>
<div class="welcome">
    <h1>
        <?= $saludo ?>, <?= e(nombre_corto($auth_user['nombres'] ?? '', $auth_user['apellido_paterno'] ?? '')) ?>
        <img src="<?= url('assets/icons/hand-saludo.svg') ?>" class="welcome__wave" alt="" aria-hidden="true">
    </h1>
    <p>
        <?php foreach (($chips ?? []) as $chip): ?>
            <span class="badge badge--ident badge--ident-<?= e($chip['tipo']) ?>"><?= e($chip['texto']) ?></span>
        <?php endforeach; ?>
        <?php if ($periodo): ?>
            · <span class="badge badge--periodo"><?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?></span>
        <?php else: ?>
            · <span class="badge badge--warning">Sin periodo activo</span>
        <?php endif; ?>
    </p>
    <?php if (!empty($niveles)): ?>
        <p class="welcome__niveles">
            <?= count($niveles) > 1 ? 'Niveles' : 'Nivel' ?>:
            <?= e(implode(' · ', array_column($niveles, 'nombre'))) ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($flash_success): ?>
    <div class="flash flash--success"><?= e($flash_success) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="dpanel-kpis">
    <div class="dpanel-kpi">
        <span class="dpanel-kpi__num"><?= $soloAula ? $nAreasAula : $nCargas ?></span>
        <span class="dpanel-kpi__label"><?= $soloAula ? 'Áreas a mi cargo' : 'Cargas asignadas' ?></span>
    </div>
    <div class="dpanel-kpi">
        <span class="dpanel-kpi__num dpanel-kpi__num--<?= $avEstadoTotal ?>"><?= $avanceTotal ?>%</span>
        <span class="dpanel-kpi__label">Avance del bimestre</span>
    </div>
    <div class="dpanel-kpi">
        <span class="dpanel-kpi__num <?= $sinCriterios > 0 ? 'dpanel-kpi__num--err' : '' ?>"><?= $sinCriterios ?></span>
        <span class="dpanel-kpi__label">Cargas sin criterios</span>
    </div>
    <div class="dpanel-kpi">
        <span class="dpanel-kpi__num dpanel-kpi__num--<?= $diasMod ?>"><?= $diasTxt ?></span>
        <span class="dpanel-kpi__label"><?= $diasSub ?></span>
    </div>
</div>

<div class="dpanel-grid">

    <!-- Card: Mis cargas -->
    <a href="<?= url('docente/mis-cargas') ?>" class="card dpanel-card dpanel-card--cargas">
        <div class="dpanel-card__head">
            <h2 class="card__title"><?= $soloAula ? 'Mi aula — ' . e($aula) : 'Mis cargas académicas' ?></h2>
            <span class="badge badge--activo"><?= $soloAula ? $nAreasAula . ' áreas' : $nCargas . ' cargas' ?></span>
        </div>
        <div class="carga-progreso">
            <div class="carga-progreso__track">
                <div class="carga-progreso__fill carga-progreso__fill--<?= $avEstado ?>"
                     style="--pct: <?= $avance ?>%"></div>
            </div>
            <div class="carga-progreso__meta">
                <span><?= $sumBloq ?>/<?= $sumTotal ?> competencias aprobadas</span>
                <span class="carga-progreso__valor carga-progreso__valor--<?= $avEstado ?>"><?= $avance ?>%</span>
            </div>
        </div>
        <p class="dpanel-card__sub">
            <?= $completas ?> completas
            <?php if ($sinCriterios > 0): ?>
                · <span class="text-danger"><?= $sinCriterios ?> sin criterios</span>
            <?php endif; ?>
        </p>
    </a>

    <!-- Card: Competencias Transversales (solo tutores; antes "Tutoría" —
         renombrada el 07/07/2026 porque Tutoría ahora es una carga académica
         más: la card de Ética y Valores en el área TOE) -->
    <?php if (!empty($tutoria)): ?>
        <?php
        if ($tutoria['cierre']) {
            $tEstado = 'cerrado';
            $tTexto  = 'Cerrado el ' . fechaLima($tutoria['cierre']['cerrado_en'], 'd/m/Y');
        } elseif ($tutoria['listo']) {
            $tEstado = 'disponible';
            $tTexto  = $tutoria['pendientes'] > 0
                ? 'Disponible — ' . $tutoria['pendientes'] . ' conclusión(es) pendiente(s)'
                : 'Disponible para cerrar';
        } else {
            $tEstado = 'progreso';
            // Desde el 06/08/2026 el contador es SOLO de competencias
            // transversales (las academicas dejaron de condicionar el cierre
            // del tutor), asi que se nombra: un "X de Y" a secas se leia como
            // el total de la seccion y ya no lo es.
            $tTexto  = 'Transversales bloqueadas ' . $tutoria['bloqueadas']
                     . ' de ' . $tutoria['total'];
        }
        // Semaforo de estado del dashboard docente (ver docs/modulos/ui.md):
        // gris = todavia no depende de ti · ambar = te toca · verde = terminado.
        // "Disponible para cerrar" sigue en ambar a proposito: mientras quede
        // una accion del tutor, la card no esta cerrada.
        $tBadge = match ($tEstado) {
            'cerrado'    => 'activo',
            'disponible' => 'warning',
            default      => 'espera',
        };
        ?>
        <a href="<?= url('docente/tutoria') ?>" class="card dpanel-card dpanel-card--tutoria dpanel-card--<?= $tEstado ?>">
            <div class="dpanel-card__head">
                <h2 class="card__title">Competencias Transversales — <?= e($tutoria['seccion']['grado_nombre']) ?> <?= e($tutoria['seccion']['nombre']) ?></h2>
            </div>
            <p class="dpanel-card__sub">Revisa los promedios TIC/GAMA, registra las conclusiones y cierra el bimestre de tu sección.</p>
            <span class="badge badge--<?= $tBadge ?>"><?= e($tTexto) ?></span>
        </a>
    <?php endif; ?>

    <!-- Card: Conducta (solo tutores) -->
    <?php if (!empty($conducta)): ?>
        <?php
        if ($conducta['cerrado']) {
            $cEstado = 'cerrado';
            $cTexto  = 'Cerrada el ' . fechaLima($conducta['cierre']['tutor_cerrado_en'], 'd/m/Y');
        } elseif ($conducta['cierre']) {
            $cEstado = 'disponible';
            $cTexto  = 'Disponible';
        } else {
            $cEstado = 'progreso';
            $cTexto  = 'En espera';
        }
        // Mismo semaforo que Competencias Transversales. "En espera" = RA aun no
        // bloqueo, el tutor no puede registrar nada todavia -> gris.
        $cBadge = match ($cEstado) {
            'cerrado'    => 'activo',
            'disponible' => 'warning',
            default      => 'espera',
        };
        ?>
        <a href="<?= url('docente/conducta') ?>" class="card dpanel-card dpanel-card--conducta dpanel-card--<?= $cEstado ?>">
            <div class="dpanel-card__head">
                <h2 class="card__title">Conducta — <?= e($conducta['seccion']['grado_nombre']) ?> <?= e($conducta['seccion']['nombre']) ?></h2>
            </div>
            <p class="dpanel-card__sub">Revisa la nota de los auxiliares, agrega tu nota y cierra la conducta del bimestre.</p>
            <span class="badge badge--<?= $cBadge ?>"><?= e($cTexto) ?></span>
        </a>
    <?php endif; ?>

    <!-- Card: Acompañamiento pedagógico (solo tutores; 23/09/2026). Cifras del
         ULTIMO bimestre PUBLICADO de su nivel (compuerta 044). El badge es gris
         a proposito: es informacion, no una accion pendiente del tutor, asi
         que no entra en el semaforo ambar/verde de las demas cards. -->
    <?php if (!empty($riesgoTutor)):
        $rs = $riesgoTutor['resumen']; ?>
        <a href="<?= url('docente/tutoria/acompanamiento') ?>" class="card dpanel-card dpanel-card--riesgo">
            <div class="dpanel-card__head">
                <h2 class="card__title">Acompañamiento pedagógico — <?= e($riesgoTutor['seccion']['grado_nombre']) ?> <?= e($riesgoTutor['seccion']['nombre']) ?></h2>
            </div>
            <?php if ($riesgoTutor['periodo'] === null): ?>
                <p class="dpanel-card__sub">Estudiantes de tu sección que no alcanzarían la promoción de grado, con su desglose por área y docente.</p>
                <span class="badge badge--espera">Aún no hay bimestre publicado</span>
            <?php else: ?>
                <p class="dpanel-card__sub">
                    <?= e($riesgoTutor['periodo']['nombre_display']) ?> &middot;
                    <?= (int) $rs['total'] ?> de <?= (int) $rs['evaluados'] ?> no alcanzaría<?= (int) $rs['total'] !== 1 ? 'n' : '' ?> la promoción
                    &middot; <?= (int) $rs['permanencia'] ?> permanecería<?= (int) $rs['permanencia'] !== 1 ? 'n' : '' ?> en el grado
                    <?php if ((int) $rs['seguimiento'] > 0): ?>
                        &middot; <?= (int) $rs['seguimiento'] ?> en seguimiento
                    <?php endif; ?>
                </p>
                <span class="badge badge--espera">
                    <?php // Cero casos con la proyección parcial NO es «todos serían
                          // promovidos»: ver `_sin-casos.php`. ?>
                    <?php if ((int) $rs['total'] > 0): ?>
                        Ver informe de tu sección
                    <?php elseif ($rs['cobertura']['completa'] && (int) $rs['sin_datos'] === 0): ?>
                        Todos serían promovidos
                    <?php else: ?>
                        Proyección parcial
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

    <!-- Card: Nómina de matriculados + Orden de mérito (acceso publico) -->
    <div class="card dpanel-card dpanel-card--nomina dpanel-card--acciones">
        <div class="dpanel-card__head">
            <h2 class="card__title">Nómina de matriculados</h2>
            <span class="badge badge--activo"><?= $totalNomina ?> estudiantes</span>
        </div>
        <p class="dpanel-card__sub">
            <?php
            $porNivel = [];
            foreach ($nominaResumen as $r) { $porNivel[$r['nivel_nombre']] = ($porNivel[$r['nivel_nombre']] ?? 0) + (int) $r['n']; }
            $partes = [];
            foreach ($porNivel as $nom => $n) { $partes[] = e($nom) . ': ' . $n; }
            echo $partes ? implode(' · ', $partes) : 'Sin matriculados en tus niveles.';
            ?>
        </p>
        <div class="dpanel-card__acciones">
            <a href="<?= url('docente/nomina') ?>" class="dpanel-card__accion dpanel-card__accion--nomina">
                <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                Ver nómina →
            </a>
            <a href="<?= url('docente/orden-merito') ?>" class="dpanel-card__accion dpanel-card__accion--merito">
                <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                Orden de mérito →
            </a>
            <a href="<?= url('docente/ranking-seccion') ?>" class="dpanel-card__accion dpanel-card__accion--seccion">
                <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                Ranking por sección →
            </a>
        </div>
    </div>

</div>

<div class="dpanel-grid">

    <!-- Pendientes -->
    <div class="card dpanel-panel dpanel-panel--pendientes">
        <div class="card__header"><h2 class="card__title">Pendientes</h2></div>
        <div class="card__body">
            <?php if (empty($pendientes)): ?>
                <p class="empty-state">Todo al día. No tienes cargas pendientes.</p>
            <?php else: ?>
                <ul class="dpanel-pend">
                    <?php foreach ($pendientes as $p): ?>
                        <li class="dpanel-pend__item">
                            <a href="<?= url('docente/calificaciones/' . $p['id']) ?>">
                                <span class="dpanel-pend__nombre"><?= e($p['nombre']) ?></span>
                                <span class="dpanel-pend__sec"><?= e($p['seccion']) ?></span>
                            </a>
                            <span class="badge <?= $p['critico'] ? 'badge--error' : 'badge--warning' ?>"><?= e($p['motivo']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mi horario -->
    <div class="card dpanel-panel dpanel-panel--horario">
        <div class="card__header dpanel-panel__head">
            <h2 class="card__title">Mi horario</h2>
            <?php if (!empty($horario)): ?>
                <a href="<?= url('docente/horario/imprimir') ?>" target="_blank" rel="noopener"
                   class="btn btn--secondary btn--sm">
                    <span class="btn-icon btn-icon--print" aria-hidden="true"></span>
                    Imprimir
                </a>
            <?php endif; ?>
        </div>
        <div class="card__body">
            <?php if (empty($horario)): ?>
                <p class="empty-state">Sin horario registrado.</p>
            <?php else: ?>
                <?php
                $dias = ['lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles', 'jueves' => 'Jueves', 'viernes' => 'Viernes'];
                $porDia = [];
                foreach ($horario as $h) { $porDia[$h['dia_semana']][] = $h; }
                ?>
                <div class="dpanel-horario">
                    <?php foreach ($dias as $key => $label): ?>
                        <?php if (empty($porDia[$key])) continue; ?>
                        <div class="dpanel-horario__dia">
                            <h3 class="dpanel-horario__titulo"><?= $label ?></h3>
                            <?php foreach ($porDia[$key] as $b): ?>
                                <div class="dpanel-horario__bloque">
                                    <span class="dpanel-horario__hora"><?= substr($b['hora_inicio'], 0, 5) ?>–<?= substr($b['hora_fin'], 0, 5) ?></span>
                                    <span class="dpanel-horario__area"><?= e($b['area_nombre']) ?></span>
                                    <span class="dpanel-horario__sec"><?= e($b['grado_nombre']) ?> <?= e($b['seccion_nombre']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
