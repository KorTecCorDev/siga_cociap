<?php
/**
 * CHIPS DE SITUACIÓN DEL ESTUDIANTE — partial compartido (28/10/2026).
 * Catálogo: `SITUACIONES_ESTUDIANTE` (helpers.php). Reglas: docs/modulos/ui.md.
 *
 * Se incluye con variables sueltas para que la llamada quepa en una celda:
 *
 *     <?php $sitChips = $alumno['situaciones'] ?? []; require VIEW_PATH . '/shared/_situacion-chips.php'; ?>
 *     <?php $sitChips = [['clave' => $m['tipo']]]; require VIEW_PATH . '/shared/_situacion-chips.php'; ?>
 *
 * @var array $sitChips    lista de ['clave' => string, 'dato' => ?string]
 *                         (las filas de lectura la traen en `situaciones`,
 *                         de SituacionEstudianteModel::anotar)
 * @var bool  $sitImpreso  tamaño de documento impreso (registro auxiliar)
 *
 * Cada chip va precedido de un espacio: separa del nombre y de otro chip.
 */
$__impreso = !empty($sitImpreso);
foreach ($sitChips ?? [] as $__c):
    $__s = situacion_estudiante((string) ($__c['clave'] ?? ''), $__c['dato'] ?? null);
    if ($__s === null) {
        continue;
    }
    ?> <span class="matricula-badge matricula-badge--<?= e($__s['clase']) ?><?= $__impreso ? ' matricula-badge--impreso' : '' ?>"<?php if ($__s['titulo'] !== null): ?> title="<?= e($__s['titulo']) ?>"<?php endif; ?>><?= e($__s['texto']) ?></span><?php
endforeach;
// Se limpian para que un segundo require en la misma vista no herede nada.
unset($__impreso, $__c, $__s, $sitChips, $sitImpreso);
