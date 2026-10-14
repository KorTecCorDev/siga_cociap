<?php
/**
 * Plantillas del documento de RATIFICACIÓN por bloques (migración 080,
 * 14/10/2026) — `Admin\RatificacionPlantillaController::index`.
 * Ver docs/modulos/ratificaciones.md.
 *
 * Una plantilla por año y tipo (ratificado / no ratificado). Admin, RA y las
 * secretarías la editan; Dirección la ve en solo lectura. El editor es un solo
 * formulario: el orden de los bloques en la página es el orden del documento.
 *
 * @var array      $anios
 * @var array|null $anio
 * @var string     $tipo          ratificado | no_ratificado
 * @var array|null $plantilla     vigente: { id, version, titulo, congelada_en, emisiones }
 * @var array      $bloques       RatificacionPlantillaModel::bloquesParaDocumento() (datos de ejemplo)
 * @var string     $huella        huella de la vigente ('' si no hay)
 * @var array|null $anterior      plantilla del año anterior, si este año no tiene
 * @var bool       $puedeEscribir
 */
use App\Models\RatificacionPlantillaModel as Plantilla;

$anioId  = $anio !== null ? (int) $anio['id'] : 0;
$urlBase = url('admin/ratificaciones/plantillas');
$congelada = $plantilla !== null && $plantilla['congelada_en'] !== null;
?>

<div class="page-header">
    <a href="<?= url('admin/ratificaciones') ?>?anio_id=<?= $anioId ?>" class="btn btn--secondary btn--sm">← Ratificación</a>
    <div>
        <h1 class="page-title">Plantillas del documento</h1>
        <p class="page-subtitle">
            <?= $anio !== null ? e((string) $anio['anio']) . ' · ' : '' ?>
            El texto que se imprime en el documento de ratificación de cada estudiante.
        </p>
    </div>
    <?php if ($plantilla !== null): ?>
        <a href="<?= url('admin/ratificaciones/plantillas/' . (int) $plantilla['id'] . '/vista-previa') ?>"
           class="btn btn--secondary btn--sm" target="_blank" rel="noopener">Vista previa</a>
    <?php endif; ?>
</div>

<?php if ($anio === null): ?>
    <div class="empty-state"><p>No hay años académicos registrados.</p></div>
<?php else: ?>

<?php if (count($anios) > 1): ?>
    <form method="GET" action="<?= $urlBase ?>" class="card mb-md">
        <div class="card__body form-inline">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <label class="form-label" for="anio_id">Año</label>
            <select id="anio_id" name="anio_id" class="form-select">
                <?php foreach ($anios as $a): ?>
                    <option value="<?= (int) $a['id'] ?>"<?= (int) $a['id'] === $anioId ? ' selected' : '' ?>><?= e((string) $a['anio']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn--secondary btn--sm">Ver</button>
        </div>
    </form>
<?php endif; ?>

<nav class="consulta-ejes" aria-label="Tipo de documento">
    <?php foreach (Plantilla::TIPOS as $valor => $etiqueta): ?>
        <a class="consulta-eje<?= $valor === $tipo ? ' consulta-eje--activo' : '' ?>"
           href="<?= $urlBase ?>?anio_id=<?= $anioId ?>&amp;tipo=<?= $valor ?>"
           <?= $valor === $tipo ? 'aria-current="page"' : '' ?>><?= e($etiqueta) ?></a>
    <?php endforeach; ?>
</nav>

<p class="text-sm text-muted mb-md">
    <?php if ($plantilla === null): ?>
        Todavía no hay plantilla para los estudiantes <?= e(mb_strtolower(Plantilla::TIPOS[$tipo])) ?>s de <?= e((string) $anio['anio']) ?>.
    <?php elseif ($congelada): ?>
        Versión <?= (int) $plantilla['version'] ?>. Ya se emitieron documentos con ella: al guardar, esos documentos
        no cambian y los siguientes salen con la versión nueva.
    <?php else: ?>
        Versión <?= (int) $plantilla['version'] ?>. Aún no se emitieron documentos con ella.
    <?php endif; ?>
</p>

<?php if ($puedeEscribir && $plantilla === null && $anterior !== null): ?>
    <form method="POST" action="<?= url('admin/ratificaciones/plantillas/duplicar') ?>" class="card mb-md">
        <div class="card__body form-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="anio_id" value="<?= $anioId ?>">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <span class="text-sm">Puedes partir de la plantilla de <?= e((string) $anterior['anio']) ?> y ajustarla.</span>
            <button type="submit" class="btn btn--secondary btn--sm">Copiar la de <?= e((string) $anterior['anio']) ?></button>
        </div>
    </form>
<?php endif; ?>

<?php if ($puedeEscribir): ?>
    <?php
    // Sin plantilla, el editor arranca con un párrafo vacío.
    $bloquesEditor = $bloques !== [] ? $bloques : [['tipo' => 'parrafo', 'texto' => '']];

    // El parcial se incluye dentro de una función: sus variables ($tipo, sus
    // bucles…) no deben pisar las de esta vista. Sin este aislamiento, el `$i`
    // de las filas de una tabla repetía la clave del bloque siguiente y el
    // formulario perdía la tabla.
    $pintarBloque = static function (string $clave, array $b): void {
        include __DIR__ . '/_bloque-editor.php';
    };
    ?>
    <form method="POST" action="<?= url('admin/ratificaciones/plantillas/guardar') ?>"
          enctype="multipart/form-data" class="card ratif-editor" data-ratif-editor
          data-max-bloques="<?= Plantilla::BLOQUES_MAX ?>">
        <div class="card__body">
            <?= csrf_field() ?>
            <input type="hidden" name="anio_id" value="<?= $anioId ?>">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <input type="hidden" name="huella" value="<?= e($huella) ?>">

            <label class="form-label" for="titulo">Título del documento <span class="text-danger">*</span></label>
            <input type="text" id="titulo" name="titulo" class="form-input"
                   value="<?= e((string) ($plantilla['titulo'] ?? '')) ?>"
                   placeholder="CONSTANCIA DE RATIFICACIÓN DE MATRÍCULA"
                   minlength="<?= Plantilla::TITULO_MIN ?>" maxlength="<?= Plantilla::TITULO_MAX ?>" required>

            <div class="ratif-ayuda text-sm text-muted">
                El membrete, los datos del estudiante<?= $tipo === 'no_ratificado' ? ', el motivo' : '' ?>,
                la fecha y la firma ya forman parte del documento. En los párrafos puedes usar
                <code>**texto**</code> para negrita y estas variables:
                <?php foreach (Plantilla::VARIABLES as $var => $desc): ?>
                    <code>{<?= e($var) ?>}</code> <?= e($desc) ?><?= $var !== array_key_last(Plantilla::VARIABLES) ? ' ·' : '.' ?>
                <?php endforeach; ?>
            </div>

            <div class="ratif-bloques" data-bloques>
                <?php foreach (array_values($bloquesEditor) as $n => $b): ?>
                    <?php $pintarBloque('b' . ($n + 1), $b); ?>
                <?php endforeach; ?>
            </div>

            <div class="ratif-editor__agregar">
                <span class="text-sm text-muted">Agregar:</span>
                <?php foreach (Plantilla::BLOQUES as $valor => $etiqueta): ?>
                    <button type="button" class="btn btn--secondary btn--sm" data-agregar="<?= $valor ?>">+ <?= e($etiqueta) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="criterios-form__acciones">
                <button type="submit" class="btn btn--primary">Guardar plantilla</button>
            </div>
        </div>
    </form>

    <?php foreach (array_keys(Plantilla::BLOQUES) as $valor): ?>
        <template data-plantilla-bloque="<?= $valor ?>">
            <?php $pintarBloque('__K__', ['tipo' => $valor]); ?>
        </template>
    <?php endforeach; ?>

<?php elseif ($plantilla === null): ?>
    <div class="empty-state"><p>Sin plantilla.</p></div>
<?php else: ?>
    <?php // Solo lectura (Dirección): el contenido a la vista, con los datos de ejemplo. ?>
    <div class="card">
        <div class="card__body ratif-lectura">
            <p class="form-section-title"><?= e($plantilla['titulo']) ?></p>
            <?php foreach ($bloques as $b): ?>
                <?php if ($b['tipo'] === 'parrafo'): ?>
                    <p><?= $b['html'] ?></p>
                <?php elseif ($b['tipo'] === 'tabla' && $b['filas'] !== []): ?>
                    <div class="tabla-notas-wrapper">
                        <table class="tabla-notas">
                            <thead><tr><?php foreach ($b['filas'][0] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($b['filas'], 1) as $fila): ?>
                                    <tr><?php foreach ($fila as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($b['tipo'] === 'imagen'): ?>
                    <img class="ratif-imagen-editor__vista" alt=""
                         src="<?= url('admin/ratificaciones/plantillas/imagen/' . rawurlencode($b['imagen_archivo'])) ?>">
                <?php else: ?>
                    <p class="text-sm text-muted">— Salto de página —</p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>
