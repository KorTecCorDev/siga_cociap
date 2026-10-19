<?php
/**
 * Nómina de matriculados — buscador en vivo + impresión por sección.
 * Sin DNI: dato sensible de consulta restringida.
 * @var array  $alumnos          filas planas (matriculados aprobados)
 * @var array  $secciones        [{seccion_id, nivel_nombre, grado_nombre, seccion_nombre, n}]
 * @var int    $total
 * @var bool   $tieneOrdenMerito si algun nivel tiene ya merito PUBLICADO
 * @var array  $bimestresMerito  [{nivel_nombre, bimestre}] — uno por nivel, porque
 *                               la compuerta 044 publica cada nivel por separado y
 *                               puede haber dos bimestres vigentes a la vez. Cada
 *                               alumno trae ademas `merito_visible` (su nivel ya
 *                               publicado) y `puesto`.
 * @var string  $estadoBoleta    estado del bimestre activo: registro|borrador|oficial
 * @var ?string $bimestreActivo  nombre del bimestre activo
 * @var ?string $bimestreCerrado nombre del ultimo bimestre cerrado (boleta oficial)
 */

// Que boleta puede ver el docente: BORRADOR si RA aprobo el bimestre activo
// (Hito A); si no, la del ultimo bimestre cerrado: OFICIAL si ya se publico a
// su nivel, BORRADOR si aun no (por fila, `boleta_oficial`). Si no hay
// ninguna, no se muestra el panel de boleta.
$boletaBorrador   = ($estadoBoleta ?? 'registro') === 'borrador';
$hayBoletaVisible = $boletaBorrador || !empty($bimestreCerrado);
$boletaEtiqueta   = $boletaBorrador
    ? 'Borrador · ' . ($bimestreActivo ?? '')
    : 'Oficial · ' . ($bimestreCerrado ?? '');
?>

<div class="page-header">
    <a href="<?= url('docente/inicio') ?>" class="btn btn--secondary btn--sm">← Volver</a>
    <div>
        <h1 class="page-title page-title--wf page-title--nomina">Nómina de matriculados</h1>
        <p class="page-subtitle"><?= $total ?> estudiantes aprobados en tus niveles</p>
    </div>
</div>

<?php if (empty($alumnos)): ?>
    <div class="empty-state"><p>No hay matriculados aprobados en los niveles donde tienes cargas.</p></div>
<?php else: ?>

<!-- Buscador de estudiantes — accion principal, destacada -->
<div class="card nomina-buscar mb-md">
    <div class="card__body">
        <div class="buscador">
            <label class="form-label nomina-buscar__titulo" for="nomina-buscador">
                <span class="nomina-seccion-ico nomina-seccion-ico--buscar" aria-hidden="true"></span>
                Buscar estudiante
            </label>
            <div class="buscador__campo nomina-buscar__campo">
                <svg class="buscador__icono" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M20 20l-3.2-3.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input type="search" id="nomina-buscador" class="form-input buscador__input"
                       placeholder="Buscar por apellidos o nombres…" autocomplete="off" autofocus>
            </div>
            <p class="text-sm text-muted" id="nomina-hint">Escribe para buscar entre <?= $total ?> estudiantes.</p>
            <p class="text-sm text-muted" id="nomina-sin-resultados" hidden>Sin coincidencias.</p>
            <p class="text-sm text-muted">
                <?php if (!$tieneOrdenMerito): ?>
                    Aún no hay orden de mérito publicado.
                <?php elseif (count($bimestresMerito) === 1): ?>
                    Orden de mérito vigente: <?= e($bimestresMerito[0]['bimestre']) ?>
                <?php else: ?>
                    Orden de mérito vigente:
                    <?php foreach ($bimestresMerito as $i => $bm): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= e($bm['bimestre']) ?> (<?= e($bm['nivel_nombre']) ?>)
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<!-- Resultados (ocultos hasta buscar) -->
<div class="buscador-resultados" id="nomina-resultados" hidden>
    <?php foreach ($alumnos as $a): ?>
        <?php
        $nombre  = $a['apellido_paterno'] . ' ' . $a['apellido_materno'] . ', ' . $a['nombres'];
        $inicial = mb_strtoupper(mb_substr($a['apellido_paterno'] ?: $a['nombres'], 0, 1));
        $tutorLabel = match ($a['tutor_sexo'] ?? null) {
            'M'     => 'Tutor',
            'F'     => 'Tutora',
            default => 'Tutor(a)',
        };
        // Desactivado: su boleta se sirve SIEMPRE como borrador (sin QR ni
        // firma), aunque el bimestre esté cerrado — el panel lo refleja por fila.
        // Cerrado pero AUN NO PUBLICADO a su nivel (compuerta 044, 19/10/2026):
        // tambien borrador, igual que la boleta que abre el enlace.
        $esDesactivado = ($a['estado'] === 'desactivado');
        $sinPublicar   = !$boletaBorrador && empty($a['boleta_oficial']);
        $claseBorrador = ($boletaBorrador || $esDesactivado || $sinPublicar);
        $etiquetaFila  = ($esDesactivado || $sinPublicar)
            ? 'Borrador · ' . ($boletaBorrador ? ($bimestreActivo ?? '') : ($bimestreCerrado ?? ''))
            : $boletaEtiqueta;
        ?>
        <div class="buscador-item card nomina-fila" data-buscar="<?= e(mb_strtolower($nombre)) ?>" hidden>
            <div class="buscador-item__body">
                <div class="buscador-item__avatar"><?= e($inicial) ?></div>
                <div class="buscador-item__info">
                    <div class="buscador-item__nombre">
                        <?= e($nombre) ?>
                        <?php if ($a['estado'] !== 'aprobada'): ?>
                            <span class="matricula-badge matricula-badge--<?= e($a['estado']) ?>">
                                <?= $esDesactivado ? 'Desactivado' : 'Pendiente' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="buscador-item__sub"><?= $tutorLabel ?>: <?= $a['tutor_nombre'] !== '' ? e($a['tutor_nombre']) : 'sin asignar' ?></div>
                    <div class="buscador-item__sub">Apoderado: <?= $a['apoderado_nombre'] !== '' ? e($a['apoderado_nombre']) : '—' ?></div>
                    <div class="buscador-item__sub">Cel: <?= $a['apoderado_telefono'] ? e($a['apoderado_telefono']) : '—' ?></div>
                </div>
                <div class="nomina-derecha">
                    <div class="buscador-item__ubicacion">
                        <div class="buscador-item__lugar"><?= e($a['grado_nombre'] . ' ' . $a['seccion_nombre']) ?></div>
                        <div class="buscador-item__nivel"><?= e($a['nivel_nombre']) ?></div>
                        <?php if (!$tieneOrdenMerito || empty($a['merito_visible'])): ?>
                            <div class="buscador-item__puesto buscador-item__puesto--vacio">Sin orden de mérito</div>
                        <?php elseif ($a['puesto'] !== null): ?>
                            <div class="buscador-item__puesto">Puesto <?= (int) $a['puesto'] ?>.° del grado</div>
                        <?php else: ?>
                            <div class="buscador-item__puesto buscador-item__puesto--vacio">Sin puesto aún</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($a['tiene_boleta']) && $hayBoletaVisible): ?>
                        <div class="nomina-boleta-panel<?= $claseBorrador ? ' nomina-boleta-panel--borrador' : '' ?>">
                            <span class="nomina-boleta-panel__label"><?= $claseBorrador ? 'Borrador' : 'Boleta' ?></span>
                            <div class="nomina-boleta-panel__botones">
                                <a class="nomina-boleta-btn" target="_blank" rel="noopener"
                                   href="<?= url('docente/boleta/' . (int) $a['matricula_id']) ?>"
                                   title="Ver boleta digital"
                                   aria-label="Ver boleta digital de <?= e($nombre) ?>">
                                    <span class="nomina-boleta-btn__ico nomina-boleta-btn__ico--ver" aria-hidden="true"></span>
                                </a>
                                <a class="nomina-boleta-btn" target="_blank" rel="noopener"
                                   href="<?= url('docente/boleta/' . (int) $a['matricula_id'] . '/imprimir') ?>"
                                   title="Boleta para imprimir (A4)"
                                   aria-label="Imprimir boleta de <?= e($nombre) ?>">
                                    <span class="nomina-boleta-btn__ico nomina-boleta-btn__ico--print" aria-hidden="true"></span>
                                </a>
                            </div>
                            <span class="nomina-boleta-panel__estado"><?= e($etiquetaFila) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Imprimir nomina de una seccion — accion secundaria -->
<div class="card nomina-imprimir-card">
    <div class="card__body">
        <label class="form-label nomina-imprimir-card__titulo" for="nomina-seccion">
            <span class="nomina-seccion-ico nomina-seccion-ico--print" aria-hidden="true"></span>
            Imprimir nómina de una sección
        </label>
        <div class="nomina-imprimir">
            <select id="nomina-seccion" class="form-input">
                <option value="">Selecciona una sección…</option>
                <?php foreach ($secciones as $s): ?>
                    <option value="<?= (int) $s['seccion_id'] ?>">
                        <?= e($s['nivel_nombre']) ?> · <?= e($s['grado_nombre']) ?> — Sección <?= e($s['seccion_nombre']) ?> (<?= (int) $s['n'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <a id="nomina-imprimir-btn" class="btn btn--primary is-disabled"
               aria-disabled="true" href="#" target="_blank" rel="noopener"
               data-base="<?= url('docente/nomina') ?>">
                <span class="btn-icon btn-icon--print" aria-hidden="true"></span>
                Imprimir
            </a>
        </div>
    </div>
</div>

<?php endif; ?>
