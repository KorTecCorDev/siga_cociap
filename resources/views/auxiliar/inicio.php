<?php
/**
 * Panel del auxiliar académico (28/09/2026) — `Auxiliar\PanelController::inicio`.
 * Estilo del panel docente (`dpanel-*`): saludo, KPIs y una card por módulo.
 * Las cards de documentos y horarios llegan en la F4, cada una con su ruta.
 *
 * @var array|null $periodo        bimestre activo {id, nombre_display, anio, editable, limite_notas}
 * @var array      $secciones      [{id, etiqueta, nivel, estudiantes, conducta{bloqueada,badge,texto}, asistencia{…}}]
 * @var int        $estudiantes    estudiantes a cargo (roster de evaluación)
 * @var int        $bloqConducta   secciones con la conducta bloqueada
 * @var int        $bloqAsistencia secciones con la asistencia bloqueada
 * @var int|null   $diasCierre
 * @var array      $auth_user
 */

// Widget «días para el cierre»: MISMOS umbrales que `docente/inicio.php`
// (≤ 3 rojo, ≤ 7 ámbar). Si se cambian allí, cambiarlos aquí.
if ($diasCierre === null)  { $diasMod = 'muted'; $diasTxt = '—';             $diasSub = 'Sin fecha límite'; }
elseif ($diasCierre < 0)   { $diasMod = 'muted'; $diasTxt = abs($diasCierre); $diasSub = 'días desde el cierre'; }
elseif ($diasCierre <= 3)  { $diasMod = 'err';   $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }
elseif ($diasCierre <= 7)  { $diasMod = 'warn';  $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }
else                       { $diasMod = 'ok';    $diasTxt = $diasCierre;      $diasSub = 'días para el cierre'; }

$nSec = count($secciones);
// Avance de bloqueo: verde completo, ámbar a medias, gris sin empezar.
$kpiBloq = static fn(int $n): string => $nSec > 0 && $n >= $nSec ? 'completo' : ($n > 0 ? 'parcial' : 'vacio');

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
            <span class="dpanel-kpi__num dpanel-kpi__num--<?= $kpiBloq($bloqConducta) ?>"><?= $bloqConducta ?>/<?= $nSec ?></span>
            <span class="dpanel-kpi__label">Conducta bloqueada</span>
        </div>
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num dpanel-kpi__num--<?= $kpiBloq($bloqAsistencia) ?>"><?= $bloqAsistencia ?>/<?= $nSec ?></span>
            <span class="dpanel-kpi__label">Asistencia bloqueada</span>
        </div>
        <div class="dpanel-kpi">
            <span class="dpanel-kpi__num dpanel-kpi__num--<?= $diasMod ?>"><?= $diasTxt ?></span>
            <span class="dpanel-kpi__label"><?= $diasSub ?></span>
        </div>
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
    </div>

<?php endif; ?>
