<?php
/**
 * Panel del auxiliar académico (28/09/2026) — `Auxiliar\PanelController::inicio`.
 * Estilo del panel docente (`dpanel-*`): saludo, KPIs y una card por módulo.
 * Documentos: Nómina de matriculados + Horario (F4a) y Nómina de docentes (F4b).
 *
 * @var array|null $periodo        bimestre activo {id, nombre_display, anio, editable, limite_notas}
 * @var array      $secciones      [{id, etiqueta, nivel, estudiantes, conducta{bloqueada,badge,texto}, asistencia{…}}]
 * @var int        $estudiantes    estudiantes a cargo (roster de evaluación)
 * @var int        $avConducta     % de secciones con la conducta bloqueada (0–100)
 * @var int        $avAsistencia   % de secciones con la asistencia bloqueada (0–100)
 * @var int|null   $diasCierre
 * @var array      $auth_user
 */

$nSec = count($secciones);
// Avance de bloqueo (%): verde completo, ámbar a medias, gris sin empezar. Misma
// regla que «Avance del bimestre» del panel docente.
$kpiAvance = static fn(int $pct): string => $pct >= 100 ? 'completo' : ($pct > 0 ? 'parcial' : 'vacio');

$saludo = match ($auth_user['sexo'] ?? null) {
    'M'     => 'Bienvenido',
    'F'     => 'Bienvenida',
    default => 'Bienvenido(a)',
};
$pid = $periodo ? (int) $periodo['id'] : 0;

// Las dos cards comparten estructura; cambia el módulo, el texto y el estado.
$cards = [
    'conducta' => [
        'titulo' => 'Conducta',
        'sub'    => 'Marca los criterios de cada estudiante y bloquea la sección al terminar.',
    ],
    'asistencia' => [
        'titulo' => 'Asistencia',
        'sub'    => 'Registra las faltas y tardanzas de cada estudiante y bloquea la sección al terminar.',
    ],
];
?>

<div class="welcome">
    <h1>
        <?= $saludo ?>, <?= e(nombre_corto($auth_user['nombres'] ?? '', $auth_user['apellido_paterno'] ?? '')) ?>
        <img src="<?= url('assets/icons/hand-saludo.svg') ?>" class="welcome__wave" alt="" aria-hidden="true">
    </h1>
    <p>
        <span class="badge badge--ident badge--ident-auxiliar"><?= e($auth_user['rol_nombre'] ?? 'Auxiliar académico') ?></span>
        <?php if ($periodo): ?>
            · <span class="badge badge--periodo"><?= e($periodo['nombre_display']) ?> <?= e($periodo['anio']) ?></span>
        <?php else: ?>
            · <span class="badge badge--warning">Sin periodo activo</span>
        <?php endif; ?>
    </p>
</div>

<?php if (!$periodo): ?>

    <div class="alert alert--warning">
        <span>
            No hay un bimestre en curso. Cuando se abra el siguiente, aquí verás las
            secciones a tu cargo.
        </span>
    </div>

<?php elseif ($nSec === 0): ?>

    <div class="alert alert--info">
        <span>
            Aún no tienes secciones asignadas en el <strong><?= e($periodo['nombre_display']) ?></strong>.
            Comunícate con Registro Académico para que te las asignen.
        </span>
    </div>

<?php else: ?>

    <!-- KPIs -->
    <div class="dpanel-kpis">
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num"><?= $nSec ?></span>
            <span class="dpanel-kpi__label"><?= $nSec === 1 ? 'Sección a mi cargo' : 'Secciones a mi cargo' ?></span>
        </div>
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num"><?= (int) $estudiantes ?></span>
            <span class="dpanel-kpi__label">Estudiantes a mi cargo</span>
        </div>
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num dpanel-kpi__num--<?= $kpiAvance($avConducta) ?>"><?= $avConducta ?>%</span>
            <span class="dpanel-kpi__label">Avance de conducta</span>
        </div>
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num dpanel-kpi__num--<?= $kpiAvance($avAsistencia) ?>"><?= $avAsistencia ?>%</span>
            <span class="dpanel-kpi__label">Avance de asistencia</span>
        </div>
        <?php // Umbrales en el punto único compartido con el panel docente. ?>
        <?php require VIEW_PATH . '/shared/_kpi-dias-cierre.php'; ?>
    </div>

    <div class="dpanel-grid">
        <?php foreach ($cards as $modulo => $card): ?>
            <?php // Card con varios destinos (uno por sección): no es un enlace entero,
                  // como la card de Nómina del docente (`--acciones`). ?>
            <div class="card dpanel-card dpanel-card--<?= $modulo ?> dpanel-card--acciones">
                <div class="dpanel-card__head">
                    <h2 class="card__title"><?= e($card['titulo']) ?></h2>
                </div>
                <p class="dpanel-card__sub"><?= e($card['sub']) ?></p>

                <ul class="aux-secciones">
                    <?php foreach ($secciones as $s): $est = $s[$modulo]; ?>
                        <li>
                            <?php // ?periodo= explícito: con el bimestre fuera de plazo la
                                  // grilla sin él no tiene periodo que mostrar (403 al
                                  // auxiliar); con él abre en solo lectura. ?>
                            <a href="<?= url('admin/' . $modulo . '/' . $s['id'] . '?periodo=' . $pid) ?>"
                               class="aux-seccion">
                                <span class="aux-seccion__nombre">
                                    <?= e($s['etiqueta']) ?>
                                    <span class="aux-seccion__nivel"><?= e($s['nivel']) ?></span>
                                </span>
                                <span class="badge badge--<?= e($est['badge']) ?>"><?= e($est['texto']) ?></span>
                                <span class="aux-seccion__ir" aria-hidden="true">→</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <?php // Nómina y horario de cada sección (F4a). Las cifras son las de la
              // NÓMINA (solo 'aprobada'), del mismo modelo que la imprime; por eso
              // se rotulan «matriculados» y no «estudiantes» (ese es el roster de
              // los KPIs, que suma a los 'pendiente'). El Horario cuelga de esta
              // card, como Mérito y Ranking en la card Nómina del docente. ?>
        <div class="card dpanel-card dpanel-card--nomina dpanel-card--acciones">
            <div class="dpanel-card__head">
                <h2 class="card__title">Nómina de matriculados</h2>
                <?php // «78 est.» (29/09/2026, pedido del usuario): con «matriculados»
                      // completo, título y etiqueta no cabían en una línea y la lista
                      // bajaba respecto de sus vecinas. ?>
                <span class="badge badge--activo"><?= array_sum(array_column($secciones, 'matriculados')) ?> est.</span>
            </div>
            <p class="dpanel-card__sub">La nómina y el horario de cada sección a tu cargo, listos para imprimir.</p>

            <ul class="aux-secciones">
                <?php foreach ($secciones as $s): ?>
                    <li class="aux-seccion aux-seccion--acciones">
                        <span class="aux-seccion__nombre">
                            <?= e($s['etiqueta']) ?>
                            <span class="aux-seccion__nivel"><?= e($s['nivel']) ?> · <?= (int) $s['matriculados'] ?><span class="aux-seccion__oculto"> matriculados</span></span>
                        </span>
                        <span class="aux-seccion__acciones">
                            <a href="<?= url('auxiliar/nomina/' . $s['id'] . '/imprimir') ?>" target="_blank" rel="noopener"
                               class="dpanel-card__accion dpanel-card__accion--nomina">
                                <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                                Nómina
                            </a>
                            <a href="<?= url('auxiliar/horario/' . $s['id']) ?>" target="_blank" rel="noopener"
                               class="dpanel-card__accion dpanel-card__accion--horario">
                                <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                                Horario
                            </a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php // Nómina de docentes (F4b): UN documento con los docentes de todas sus
              // secciones (sin DNI para el auxiliar). No es por sección: un solo botón. ?>
        <div class="card dpanel-card dpanel-card--docentes dpanel-card--acciones">
            <div class="dpanel-card__head">
                <h2 class="card__title">Nómina de docentes</h2>
            </div>
            <p class="dpanel-card__sub">Contacto, áreas y tutoría de los docentes de tus secciones.</p>
            <div class="dpanel-card__acciones">
                <a href="<?= url('documentos/nomina-docentes') ?>" target="_blank" rel="noopener"
                   class="dpanel-card__accion dpanel-card__accion--docentes">
                    <span class="dpanel-card__accion-ico" aria-hidden="true"></span>
                    Imprimir
                </a>
            </div>
        </div>
    </div>

<?php endif; ?>
