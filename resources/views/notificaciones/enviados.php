<?php
/**
 * Historial de COMUNICADOS enviados (migración 060).
 *
 * Lo ven TODOS los emisores (admin y registro académico), no solo el autor:
 * sirve para no mandar dos veces lo mismo. El gate está en el controlador.
 *
 * @var array $comunicados filas de NotificacionModel::comunicadosEnviados()
 * @var array $destinos    NotificacionModel::DESTINOS (clave => etiqueta)
 */
?>

<div class="page-header">
    <a href="<?= url('notificaciones') ?>" class="btn btn--secondary btn--sm">← Notificaciones</a>
    <div>
        <h1 class="page-title">Comunicados enviados</h1>
        <p class="page-subtitle">
            Todos los comunicados del colegio, con quién los envió y cuántos destinatarios ya los leyeron.
        </p>
    </div>
    <div class="btn-group">
        <a href="<?= url('notificaciones/comunicado') ?>" class="btn btn--primary btn--sm">
            Nuevo comunicado
        </a>
    </div>
</div>

<?php if (empty($comunicados)): ?>
    <div class="empty-state">
        <p>Todavía no se ha enviado ningún comunicado.</p>
    </div>
<?php else: ?>
    <ul class="notif-lista">
        <?php foreach ($comunicados as $c):
            $total  = (int) $c['destinatarios'];
            $leidos = (int) $c['leidos'];
        ?>
        <li class="notif notif-enviado">
            <div class="notif__cabecera">
                <span class="notif-enviado__destinos">
                    <?php foreach (explode(',', (string) $c['destinos']) as $clave):
                        if (!isset($destinos[$clave])) continue;
                        $etiqueta = $destinos[$clave];
                        if ($clave === App\Models\NotificacionModel::DESTINO_SECCION
                            && !empty($c['seccion_nombre'])) {
                            $etiqueta = 'Docentes de ' . $c['nivel_nombre'] . ' · '
                                . $c['grado_nombre'] . ' "' . $c['seccion_nombre'] . '"';
                        }
                    ?>
                        <span class="notif__tipo"><?= e($etiqueta) ?></span>
                    <?php endforeach; ?>
                </span>
                <span class="notif__fecha">
                    <?= e(fecha_es(substr((string) $c['created_at'], 0, 10))) ?>
                    · <?= e(substr((string) $c['created_at'], 11, 5)) ?>
                </span>
            </div>

            <p class="notif__titulo"><?= e($c['titulo']) ?></p>
            <p class="notif__mensaje"><?= nl2br(e($c['mensaje'])) ?></p>

            <div class="notif__pie">
                <span class="notif__emisor">
                    Enviado por <?= e($c['emisor'] ?: '—') ?>
                </span>
                <span class="notif-enviado__lectura">
                    Leído por <?= $leidos ?> de <?= $total ?>
                    <?= $total === 1 ? 'destinatario' : 'destinatarios' ?>
                </span>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
