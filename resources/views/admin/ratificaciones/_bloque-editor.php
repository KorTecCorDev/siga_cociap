<?php
/**
 * Un bloque del editor de plantillas de ratificación. Lo pinta el servidor para
 * los bloques guardados y, con `$clave = '__K__'` dentro de un <template>, lo
 * clona `ratificacion-plantilla.js` para los nuevos.
 *
 * Los campos van como `bloques[clave][...]` (el orden del formulario es el
 * orden del documento) y la imagen nueva como `imagen[clave]`. La tabla se
 * edita en celdas sin nombre y viaja serializada en `bloques[clave][tabla_json]`.
 *
 * @var string $clave  clave del bloque en el formulario
 * @var array  $b      { tipo, texto?, filas?, imagen_archivo?, imagen_tamano? }
 */
use App\Models\RatificacionPlantillaModel as Plantilla;

$campo  = static fn(string $n): string => 'bloques[' . $clave . '][' . $n . ']';
$tipo   = $b['tipo'];
$filas  = $b['filas'] ?? [['', ''], ['', '']];
$actual = (string) ($b['imagen_archivo'] ?? '');
?>
<div class="ratif-bloque" data-bloque>
    <div class="ratif-bloque__cabecera">
        <span class="ratif-bloque__tipo"><?= e(Plantilla::BLOQUES[$tipo]) ?></span>
        <div class="ratif-bloque__acciones">
            <button type="button" class="btn btn--secondary btn--sm" data-mover="-1" aria-label="Subir bloque">↑</button>
            <button type="button" class="btn btn--secondary btn--sm" data-mover="1" aria-label="Bajar bloque">↓</button>
            <button type="button" class="btn btn--danger btn--sm" data-quitar>Quitar</button>
        </div>
    </div>
    <input type="hidden" name="<?= $campo('tipo') ?>" value="<?= e($tipo) ?>">

    <?php if ($tipo === 'parrafo'): ?>
        <textarea name="<?= $campo('texto') ?>" class="form-input" rows="5"
                  maxlength="<?= Plantilla::PARRAFO_MAX ?>" required
                  aria-label="Texto del párrafo"><?= e((string) ($b['texto'] ?? '')) ?></textarea>

    <?php elseif ($tipo === 'tabla'): ?>
        <div class="ratif-tabla-editor" data-tabla-editor
             data-max-filas="<?= Plantilla::TABLA_FILAS_MAX ?>" data-max-columnas="<?= Plantilla::TABLA_COLUMNAS_MAX ?>">
            <div class="tabla-notas-wrapper">
                <table class="ratif-tabla-editor__tabla">
                    <tbody>
                        <?php foreach ($filas as $i => $fila): ?>
                            <tr<?= $i === 0 ? ' class="ratif-tabla-editor__cabecera"' : '' ?>>
                                <?php foreach ($fila as $celda): ?>
                                    <td><input type="text" class="form-input" maxlength="<?= Plantilla::CELDA_MAX ?>"
                                               value="<?= e((string) $celda) ?>" aria-label="Celda"></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-muted">La primera fila es la cabecera de la tabla.</p>
            <div class="ratif-tabla-editor__acciones">
                <button type="button" class="btn btn--secondary btn--sm" data-tabla="+fila">+ Fila</button>
                <button type="button" class="btn btn--secondary btn--sm" data-tabla="-fila">− Fila</button>
                <button type="button" class="btn btn--secondary btn--sm" data-tabla="+columna">+ Columna</button>
                <button type="button" class="btn btn--secondary btn--sm" data-tabla="-columna">− Columna</button>
            </div>
            <input type="hidden" name="<?= $campo('tabla_json') ?>" value="" data-tabla-json>
        </div>

    <?php elseif ($tipo === 'imagen'): ?>
        <div class="ratif-imagen-editor">
            <img class="ratif-imagen-editor__vista" alt="" data-imagen-vista
                 <?= $actual !== '' ? 'src="' . url('admin/ratificaciones/plantillas/imagen/' . rawurlencode($actual)) . '"' : 'hidden' ?>>
            <input type="hidden" name="<?= $campo('imagen_actual') ?>" value="<?= e($actual) ?>">
            <div class="ratif-imagen-editor__campos">
                <label class="form-label" for="img-<?= e($clave) ?>"><?= $actual !== '' ? 'Cambiar imagen' : 'Imagen' ?> (PNG o JPG, hasta 2 MB)</label>
                <input type="file" id="img-<?= e($clave) ?>" name="imagen[<?= e($clave) ?>]" class="form-input"
                       accept="image/png,image/jpeg" data-imagen-archivo<?= $actual === '' ? ' required' : '' ?>>
                <label class="form-label" for="tam-<?= e($clave) ?>">Tamaño</label>
                <select id="tam-<?= e($clave) ?>" name="<?= $campo('imagen_tamano') ?>" class="form-select">
                    <?php foreach (Plantilla::TAMANOS as $valor => $etiqueta): ?>
                        <option value="<?= $valor ?>"<?= ($b['imagen_tamano'] ?? 'media') === $valor ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

    <?php else: ?>
        <p class="text-sm text-muted">Lo que sigue empieza en una hoja nueva.</p>
    <?php endif; ?>
</div>
