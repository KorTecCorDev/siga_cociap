<?php
/**
 * Redactar un COMUNICADO (migraciones 058 y 060).
 *
 * Solo admin / registro académico llegan aquí: los tres roles de dirección son
 * SOLO LECTURA y entran a su bandeja, pero no redactan. El gate real está en el
 * controlador, por método.
 *
 * Los destinos son CASILLAS COMBINABLES: el servidor une los destinatarios,
 * los deduplica y excluye a quien envía.
 *
 * @var array $secciones filas de NotificacionModel::seccionesParaComunicado()
 * @var array $destinos  NotificacionModel::DESTINOS (clave => etiqueta)
 */
use App\Models\NotificacionModel;

$ayudaDestino = [
    NotificacionModel::DESTINO_DOCENTES       => 'Llega a cada docente activo del colegio.',
    NotificacionModel::DESTINO_SECCION        => 'Solo quienes tienen carga activa en esa sección.',
    NotificacionModel::DESTINO_DIRECCION      => 'Los tres directores. Lo leen, no responden.',
    NotificacionModel::DESTINO_ADMINISTRATIVO => 'Registro Académico y administración.',
];
?>

<div class="page-header">
    <a href="<?= url('notificaciones') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title">Nuevo comunicado</h1>
        <p class="page-subtitle">
            Llega a la bandeja de cada destinatario y a la campana de su barra superior.
        </p>
    </div>
</div>

<form method="POST" action="<?= url('notificaciones/comunicado') ?>" class="card"
      data-comunicado-form>
    <div class="card__body">
        <?= csrf_field() ?>

        <p class="form-section-title">Destinatarios <span class="text-danger">*</span></p>
        <p class="text-sm text-muted">Puedes marcar varios: nadie recibe el comunicado dos veces.</p>
        <div class="form-group">
            <?php foreach ($destinos as $clave => $etiqueta): ?>
            <label class="notif-opcion">
                <input type="checkbox" name="destinos[]" value="<?= e($clave) ?>"
                       <?= $clave === NotificacionModel::DESTINO_DOCENTES ? 'checked' : '' ?>>
                <span>
                    <strong><?= e($etiqueta) ?></strong>
                    <span class="text-sm text-muted"><?= e($ayudaDestino[$clave] ?? '') ?></span>
                </span>
            </label>
            <?php endforeach; ?>
            <p class="text-sm text-danger notif-aviso" data-comunicado-error hidden></p>
        </div>

        <div class="form-group">
            <label class="form-label" for="seccion_id">Sección</label>
            <select id="seccion_id" name="seccion_id" class="form-input">
                <option value="">—</option>
                <?php foreach ($secciones as $s): ?>
                    <option value="<?= (int) $s['id'] ?>">
                        <?= e($s['nivel_nombre']) ?> ·
                        <?= e($s['grado_nombre']) ?> "<?= e($s['seccion_nombre']) ?>"
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="text-sm text-muted">Solo se usa si marcaste "Docentes de una sección". Secciones del año activo.</p>
        </div>

        <p class="form-section-title">Mensaje</p>
        <div class="form-group">
            <label class="form-label" for="titulo">Título <span class="text-danger">*</span></label>
            <input type="text" id="titulo" name="titulo" class="form-input"
                   maxlength="150" required
                   placeholder="P. ej. Cierre del III Bimestre">
        </div>
        <div class="form-group">
            <label class="form-label" for="mensaje">Mensaje <span class="text-danger">*</span></label>
            <textarea id="mensaje" name="mensaje" class="form-input" rows="6" required
                      placeholder="Escribe aqui el comunicado."></textarea>
        </div>

        <div class="btn-group form-actions">
            <a href="<?= url('notificaciones') ?>" class="btn btn--secondary">Cancelar</a>
            <button type="submit" class="btn btn--primary">Enviar comunicado</button>
        </div>
    </div>
</form>
