<?php
/**
 * Asignación de secciones a los auxiliares académicos (28/09/2026).
 *
 * @var array|null $periodo        Bimestre activo {id, numero, nombre_display, anio}
 * @var array      $secciones      AuxiliarSeccionModel::listarParaPeriodo()
 * @var array      $conHijos       auxiliar_id => [seccion_id…] donde tiene un hijo/a
 * @var array      $auxiliaresJson [{ id, nombre, dni, seccionesHijo }]
 */

$nivelActual = null;
$sinAsignar  = count(array_filter($secciones, fn($s) => $s['auxiliar_id'] === null));
?>

<div class="page-header">
    <a href="<?= url('/') ?>" class="btn btn--secondary btn--sm">← Dashboard</a>
    <div>
        <h1 class="page-title">Auxiliares y secciones</h1>
        <p class="page-subtitle">
            <?php if ($periodo): ?>
                Asignaciones del <?= e($periodo['nombre_display']) ?> <?= e((string) $periodo['anio']) ?>
                · <?= count($secciones) ?> secciones
                <?php if ($sinAsignar > 0): ?>· <?= $sinAsignar ?> sin auxiliar<?php endif; ?>
            <?php else: ?>
                Sin bimestre activo
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$periodo): ?>
    <div class="alert alert--warning">
        <span>
            No hay un bimestre activo. Las secciones se asignan durante un bimestre abierto;
            vuelve a esta pantalla cuando se abra el siguiente.
        </span>
    </div>
<?php else: ?>

    <div class="alert alert--info">
        <span>
            Lo que asignes rige <strong>desde el <?= e($periodo['nombre_display']) ?></strong> y se
            mantiene en los bimestres siguientes hasta que lo cambies. Los bimestres ya cerrados
            conservan a su auxiliar, que es quien figura en sus documentos.
        </span>
    </div>

    <?php if (empty($auxiliaresJson)): ?>
        <div class="alert alert--warning">
            <span>
                Todavía no hay usuarios con el rol <strong>Auxiliar académico</strong>.
                Créalos primero en Usuarios.
            </span>
            <?php if (has_role('admin')): ?>
                <a href="<?= url('admin/usuarios/crear') ?>" class="btn btn--secondary btn--sm alert__accion">Crear usuario</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Sección</th>
                        <th>Auxiliar asignado/a</th>
                        <th>Rige desde</th>
                        <th class="text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($secciones as $s):
                    if ($s['nivel_nombre'] !== $nivelActual):
                        $nivelActual = $s['nivel_nombre'];
                ?>
                    <tr class="seccion-nivel-header">
                        <td colspan="4">
                            <span class="seccion-nivel-label"><?= e($s['nivel_nombre']) ?></span>
                        </td>
                    </tr>
                <?php endif; ?>
                    <tr>
                        <td>
                            <span class="seccion-nombre">
                                <?= e($s['grado_nombre']) ?> &ldquo;<?= e($s['seccion_nombre']) ?>&rdquo;
                            </span>
                        </td>
                        <td>
                            <?php if ($s['auxiliar_id'] !== null): ?>
                                <div class="tutor-celda">
                                    <div class="usuario-avatar usuario-avatar--<?= e(ROL_AUXILIAR) ?> tutor-avatar--sm">
                                        <?= e(mb_strtoupper(mb_substr($s['auxiliar_nombre'], 0, 1))) ?>
                                    </div>
                                    <div>
                                        <div class="td-usuario__nombre"><?= e($s['auxiliar_nombre']) ?></div>
                                        <div class="td-usuario__sub">DNI <?= e((string) $s['auxiliar_dni']) ?></div>
                                        <?php if (in_array($s['id'], $conHijos[$s['auxiliar_id']] ?? [], true)): ?>
                                            <span class="badge badge--warning">Tiene un hijo/a en esta sección</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="tutor-sin-asignar">Sin auxiliar asignado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <?php if ($s['desde_nombre'] === null): ?>
                                <span class="text-muted">—</span>
                            <?php elseif ($s['cambiado_aqui']): ?>
                                <?= e($s['desde_nombre']) ?>
                            <?php else: ?>
                                <?= e($s['desde_nombre']) ?> <span class="text-muted">(heredado)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <button type="button"
                                    class="btn btn--sm <?= $s['auxiliar_id'] !== null ? 'btn--secondary' : 'btn--primary' ?>"
                                    data-abrir-auxiliar
                                    data-seccion-id="<?= (int) $s['id'] ?>"
                                    data-auxiliar-id="<?= (int) ($s['auxiliar_id'] ?? 0) ?>"
                                    data-label="<?= e($s['grado_numero'] . $s['seccion_nombre']) ?>"
                                    data-nivel="<?= e($s['nivel_nombre']) ?>"
                                    <?= empty($auxiliaresJson) && $s['auxiliar_id'] === null ? 'disabled title="Primero crea usuarios con el rol Auxiliar académico"' : '' ?>>
                                <?= $s['auxiliar_id'] !== null ? 'Cambiar' : 'Asignar' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal de asignación de auxiliar -->
    <div class="modal-overlay" id="modalAuxiliar" hidden>
        <div class="modal-box">
            <div class="modal-header">
                <h2 class="modal-title">Asignar auxiliar</h2>
                <button type="button" class="modal-cerrar" data-modal-cerrar="modalAuxiliar" aria-label="Cerrar">✕</button>
            </div>
            <form method="POST" id="formAuxiliar" novalidate>
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="modal-seccion-info">
                        <span class="modal-seccion-label" id="modalAuxSeccionLabel"></span>
                        <span class="modal-nivel-badge" id="modalAuxNivelBadge"></span>
                    </div>

                    <label class="form-label" for="auxiliar_id">Auxiliar académico</label>
                    <select name="auxiliar_id" id="auxiliar_id" class="form-select">
                        <option value="">— Sin auxiliar —</option>
                    </select>
                    <div id="modalAuxiliarData"
                         data-auxiliares="<?= e(json_encode($auxiliaresJson, JSON_UNESCAPED_UNICODE)) ?>"
                         hidden></div>

                    <?php // `hidden` va en un envoltorio SIN clase: `.alert` declara
                          // display:block y le ganaría al [hidden] del navegador. ?>
                    <div id="modalAuxAvisoHijo" hidden>
                        <p class="alert alert--warning mt-2">
                            Este auxiliar tiene un hijo/a en esta sección. Puedes asignarla igual;
                            registrará también la conducta y la asistencia de su hijo/a.
                        </p>
                    </div>

                    <p class="text-sm text-muted mt-2">
                        Rige desde el <strong><?= e($periodo['nombre_display']) ?></strong>.
                        Un auxiliar puede tener varias secciones.
                    </p>
                </div>
                <div class="modal-footer">
                    <span id="modalAuxFeedback" class="modal-feedback"></span>
                    <button type="button" class="btn btn--secondary" data-modal-cerrar="modalAuxiliar">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn--primary">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>
