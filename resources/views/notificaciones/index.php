<?php
/**
 * Bandeja de notificaciones del usuario en sesión.
 *
 * Dos orígenes conviven en la misma lista (migración 058): las que genera el
 * SISTEMA por un evento —hoy, que a un estudiante de tu sección le registraron
 * las notas de su colegio de origen— y los COMUNICADOS que redacta Registro
 * Académico. Las no leídas van primero y se marcan solas al abrirlas.
 *
 * @var array $notificaciones filas de NotificacionModel::paraUsuario()
 * @var int   $noLeidas       cuántas sin leer
 * @var bool  $puedeEmitir    si el rol puede redactar comunicados
 */
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Notificaciones</h1>
        <p class="page-subtitle">
            <?php if ($noLeidas > 0): ?>
                Tienes <strong><?= (int) $noLeidas ?></strong>
                <?= $noLeidas === 1 ? 'notificación sin leer' : 'notificaciones sin leer' ?>.
            <?php else: ?>
                No tienes notificaciones sin leer.
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-group">
        <?php if ($puedeEmitir): ?>
            <a href="<?= url('notificaciones/enviados') ?>" class="btn btn--secondary btn--sm">
                Comunicados enviados
            </a>
            <a href="<?= url('notificaciones/comunicado') ?>" class="btn btn--primary btn--sm">
                Nuevo comunicado
            </a>
        <?php endif; ?>
        <?php if ($noLeidas > 0): ?>
            <form method="POST" action="<?= url('notificaciones/leer-todas') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--secondary btn--sm">Marcar todas como leídas</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($notificaciones)): ?>
    <div class="empty-state">
        <p>Todavía no tienes notificaciones.</p>
    </div>
<?php else: ?>
    <ul class="notif-lista">
        <?php foreach ($notificaciones as $n):
            $sinLeer = $n['leida_en'] === null;
            $tipo    = (string) $n['tipo'];
        ?>
        <li class="notif<?= $sinLeer ? ' notif--sin-leer' : '' ?>"
            data-notif-id="<?= (int) $n['id'] ?>">
            <div class="notif__cabecera">
                <span class="notif__tipo notif__tipo--<?= e($tipo) ?>">
                    <?= e($n['origen'] === 'comunicado' ? 'Comunicado' : 'Aviso del sistema') ?>
                </span>
                <?php
                // Chip de PROCEDENCIA: cuando el aviso habla de notas, dice de
                // cual de los tres mecanismos vienen. Un comunicado no tiene
                // procedencia, y el partial no pinta nada si la clave no existe.
                $procDeTipo = [
                    App\Models\NotificacionModel::TIPO_NOTAS_ORIGEN => PROCEDENCIA_ORIGEN,
                ];
                if (isset($procDeTipo[$tipo])):
                    $proc = $procDeTipo[$tipo];
                    require VIEW_PATH . '/shared/_procedencia-chip.php';
                endif;
                ?>
                <?php if ($sinLeer): ?>
                    <span class="notif__punto" aria-label="Sin leer" title="Sin leer"></span>
                <?php endif; ?>
                <span class="notif__fecha">
                    <?= e(fecha_es(substr((string) $n['created_at'], 0, 10))) ?>
                </span>
            </div>

            <p class="notif__titulo"><?= e($n['titulo']) ?></p>
            <p class="notif__mensaje"><?= nl2br(e($n['mensaje'])) ?></p>

            <div class="notif__pie">
                <?php if (!empty($n['emisor'])): ?>
                    <span class="notif__emisor">Enviado por <?= e($n['emisor']) ?></span>
                <?php endif; ?>
                <?php if (!empty($n['enlace'])): ?>
                    <a href="<?= url($n['enlace']) ?>" class="btn btn--secondary btn--sm"
                       data-notif-abrir>Ver detalle</a>
                <?php endif; ?>
                <?php if ($sinLeer): ?>
                    <button type="button" class="btn btn--secondary btn--sm" data-notif-leer>
                        Marcar como leída
                    </button>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
