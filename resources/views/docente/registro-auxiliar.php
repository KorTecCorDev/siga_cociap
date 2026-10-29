<?php
/**
 * Registro auxiliar de calificaciones — pantalla de selección (27/10/2026).
 * El docente elige el bimestre y las cargas (o áreas de su aula) a imprimir; el
 * documento se abre en ventana nueva (`formtarget`, como la planilla de
 * asistencia). Solo entra lo OFICIAL: competencias aprobadas y bloqueadas.
 *
 * @var array $periodo   bimestre elegido
 * @var array $periodos  bimestres del año activo (activo + cerrados)
 * @var array $entradas  CalificacionController::entradasRegistro()
 */
$grupos = [];
foreach ($entradas as $en) {
    $titulo = $en['es_aula']
        ? 'Mi aula — ' . trim($en['grado_nombre'] . ' ' . $en['seccion_nombre'])
        : trim($en['grado_nombre'] . ' ' . $en['seccion_nombre']);
    $grupos[$en['nivel_nombre'] . '|' . $titulo][] = $en;
}
?>

<div class="page-header">
    <a href="<?= url('docente/mis-cargas?periodo_id=' . (int) $periodo['id']) ?>" class="btn btn--secondary btn--sm">← Mis cargas</a>
    <h1 class="page-title">Registro auxiliar de calificaciones</h1>
    <span class="badge <?= $periodo['estado'] === 'activo' ? 'badge--activo' : 'badge--warning' ?>">
        <?= e($periodo['nombre_display']) ?> — <?= e((string) $periodo['anio']) ?>
    </span>
</div>

<?php if (count($periodos) > 1): ?>
    <div class="card mb-md">
        <div class="card__body">
            <form method="GET" action="<?= url('docente/registro-auxiliar') ?>" class="cargas-periodo">
                <label class="form-label" for="periodo_id">Bimestre</label>
                <select name="periodo_id" id="periodo_id"
                        class="form-select cargas-periodo__select" onchange="this.form.submit()">
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $periodo['id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nombre_display']) ?> <?= e((string) $p['anio']) ?>
                            (<?= $p['estado'] === 'cerrado' ? 'cerrado' : 'activo' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($entradas)): ?>
    <div class="empty-state">
        <p>No tienes cargas académicas asignadas para este año.</p>
    </div>
<?php else: ?>
    <form method="GET" action="<?= url('docente/registro-auxiliar/imprimir') ?>" class="ra-sel" id="formRegistroAuxiliar">
        <input type="hidden" name="periodo_id" value="<?= (int) $periodo['id'] ?>">
        <p class="text-sm text-muted ra-sel__ayuda">
            Marca las cargas que quieres imprimir. Cada área de una sección sale en su propia hoja,
            con todas sus competencias: las de las cargas que marques llevan sus criterios y
            calificaciones aprobadas; las demás quedan en blanco.
        </p>

        <?php // Acciones ARRIBA de las cards y con el estilo del registro auxiliar
              // (27/10/2026): el docente reconoce el acceso sin leer. El id del
              // botón lo usa registro-auxiliar.js para habilitarlo. ?>
        <div class="card mb-md">
            <div class="card__body ra-sel__acciones">
                <button type="submit" formtarget="_blank" class="btn btn--registro" id="btnRaSeleccion" disabled>
                    <span class="btn-icon btn-icon--registro" aria-hidden="true"></span>
                    Imprimir seleccionadas
                </button>
                <button type="submit" name="todas" value="1" formtarget="_blank" class="btn btn--registro-linea">
                    <span class="btn-icon btn-icon--registro" aria-hidden="true"></span>
                    Imprimir todas mis cargas
                </button>
            </div>
        </div>

        <?php // Cards = las de Mis cargas (`.carga-item`), pero como <label> con su
              // casilla VISIBLE: es lo que dice que se puede marcar más de una (mismo
              // criterio que las casillas del Acompañamiento). ?>
        <?php foreach ($grupos as $clave => $lista):
            [$nivel, $titulo] = explode('|', $clave, 2);
            $esAula = !empty($lista[0]['es_aula']); ?>
            <div class="card card--grado mb-md" data-ra-grupo>
                <div class="card__header">
                    <div class="grado-head">
                        <span class="grado-head__eyebrow"><?= e($nivel) ?></span>
                        <h2 class="card__title grado-head__title"><?= e($titulo) ?></h2>
                    </div>
                    <button type="button" class="btn btn--secondary btn--sm" data-ra-marcar-grupo>Marcar todas</button>
                </div>
                <div class="card__body">
                    <div class="cargas-grid">
                        <?php foreach ($lista as $en):
                            [$badgeClase, $badgeTexto] = $en['badge']; ?>
                            <label class="carga-item ra-card<?= $esAula ? ' carga-item--aula' : '' ?>">
                                <span class="ra-card__cab">
                                    <input type="checkbox" class="ra-card__check" name="e[]"
                                           value="<?= e($en['clave']) ?>" data-ra-casilla>
                                    <span class="carga-item__nombre"><?= e($en['nombre']) ?></span>
                                </span>

                                <span class="badge <?= e($badgeClase) ?> carga-item__badge">
                                    <?= e($badgeTexto) ?>
                                    <?php if ($badgeClase === 'badge--activo'): ?><span class="badge__icono" aria-hidden="true"></span><?php endif; ?>
                                </span>

                                <span class="carga-item__horas">
                                    <?php if ($en['horas'] === null): ?>
                                        <?= count($en['cargas']) ?> subáreas
                                    <?php else: ?>
                                        <?= $en['horas'] > 0 ? (int) $en['horas'] . ' hrs/semana' : '—' ?>
                                    <?php endif; ?>
                                </span>

                                <span class="carga-progreso">
                                    <span class="carga-progreso__track">
                                        <span class="carga-progreso__fill carga-progreso__fill--<?= e($en['estado']) ?>"
                                              style="--pct: <?= (int) $en['pct'] ?>%"></span>
                                    </span>
                                    <span class="carga-progreso__meta">
                                        <span><?= (int) $en['bloqueadas'] ?>/<?= (int) $en['total'] ?> aprobadas</span>
                                        <span class="carga-progreso__valor carga-progreso__valor--<?= e($en['estado']) ?>">
                                            <?= (int) $en['pct'] ?>%
                                        </span>
                                    </span>
                                </span>

                                <?php if ($en['tr_estado'] !== null): ?>
                                    <span class="carga-transversal carga-transversal--<?= e($en['tr_estado']) ?>">
                                        Transversales <?= (int) $en['tr_bloqueadas'] ?>/<?= (int) $en['tr_total'] ?>
                                        <?php if ($en['tr_estado'] === 'completo'): ?><span class="carga-transversal__icono" aria-hidden="true"></span><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </form>
<?php endif; ?>
