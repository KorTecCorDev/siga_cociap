<?php
/**
 * @var array $matricula
 * @var array $vinculos
 * @var array $documentos
 * @var array $notasExternas
 * @var array $tiposVinculo
 * @var array|null $retorno
 * @var array|null $traslado    última constancia de traslado (o null)
 * @var bool  $puedeGestionar
 * @var array $exoneraciones        exoneraciones VIGENTES de la matrícula
 * @var array $opcionesExoneracion  áreas/subáreas exonerables (solo gestión)
 * @var array $pendientes  requisitos faltantes para activar (vacío = completa)
 */
$traslado = $traslado ?? null;
$pendientes = $pendientes ?? [];
$mid = (int) $matricula['id'];

$labelEstado = fn(string $e): string => match($e) {
    'pendiente'   => 'Pendiente',
    'aprobada'    => 'Aprobado',
    'desactivado' => 'Desactivado',
    default       => ucfirst($e),
};
$esActivo = $matricula['estado'] === 'aprobada';
$labelDoc = [
    'recibo_pago' => 'Recibo de pago', 'certificado_estudios' => 'Certificado de estudios',
    'boleta_siagie' => 'Boleta SIAGIE', 'ficha_matricula_siagie' => 'Ficha de matrícula SIAGIE',
    'dni_estudiante' => 'DNI del estudiante', 'dni_padre' => 'DNI del padre',
    'dni_madre' => 'DNI de la madre', 'dni_apoderado' => 'DNI del apoderado',
];
?>

<div class="page-header">
    <a href="<?= url('matriculas') ?>" class="btn btn--secondary btn--sm">← Matrículas</a>
    <div>
        <h1 class="page-title"><?= e($matricula['nombre_completo']) ?></h1>
        <p class="page-subtitle">
            <span class="matricula-badge matricula-badge--<?= e($matricula['estado']) ?>"><?= $labelEstado($matricula['estado']) ?></span>
            <span class="matricula-badge matricula-badge--<?= e($matricula['tipo']) ?>"><?= ucfirst(e($matricula['tipo'])) ?></span>
            <?php if (!empty($matricula['motivo_estado'])): ?>
            <span class="matricula-motivo">Motivo: <?= e($matricula['motivo_estado']) ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="mat-detalle-grid">

    <!-- Datos del estudiante -->
    <div class="card">
        <div class="card__body">
            <div class="card__header card__header--between">
                <p class="form-section-title">Estudiante</p>
                <?php if ($puedeGestionar): ?>
                <div class="mat-editar__control" data-editar-control hidden>
                    <button type="button" class="btn btn--secondary btn--sm" data-editar-toggle>Editar datos</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="info-grid">
                <div class="info-item"><span class="info-item__label">DNI</span><span class="info-item__value"><?= e($matricula['dni']) ?><?php if (es_dni_provisional($matricula['dni'])): ?> <span class="badge-provisional" title="Código temporal — reemplázalo por el DNI real para poder activar la matrícula">Provisional</span><?php endif; ?></span></div>
                <div class="info-item"><span class="info-item__label">Sexo</span><span class="info-item__value"><?= $matricula['sexo'] === 'F' ? 'Femenino' : ($matricula['sexo'] === 'M' ? 'Masculino' : '—') ?></span></div>
                <div class="info-item"><span class="info-item__label">Nivel / Grado</span><span class="info-item__value"><?= e(($matricula['nivel_nombre'] ?? '—') . ' · ' . ($matricula['grado_nombre'] ?? '')) ?></span></div>
                <div class="info-item"><span class="info-item__label">Sección</span><span class="info-item__value"><?= e($matricula['seccion_nombre'] ?? '—') ?></span></div>
                <div class="info-item"><span class="info-item__label">Año académico</span><span class="info-item__value"><?= e((string) $matricula['anio']) ?></span></div>
                <div class="info-item"><span class="info-item__label">Serie del recibo</span><span class="info-item__value"><?= e($matricula['serie_recibo'] ?? '—') ?></span></div>
                <div class="info-item"><span class="info-item__label">Fecha de registro</span><span class="info-item__value"><?= $matricula['fecha_registro'] ? fecha_es($matricula['fecha_registro']) : '—' ?></span></div>
            </div>

            <?php if ($puedeGestionar): ?>
            <!-- Editar datos personales (inline, disclosure). Mejora progresiva:
                 sin JS el formulario se ve abierto; matriculas.js lo colapsa. -->
            <form method="POST" action="<?= url('matriculas/' . $mid . '/estudiante') ?>"
                  class="mat-editar" data-editar-form>
                <?= csrf_field() ?>
                <p class="form-section-title">Editar datos del estudiante</p>
                <div class="mat-editar__grid">
                    <div class="form-group">
                        <label class="form-label" for="ed_apellido_paterno">Apellido paterno <span class="text-danger">*</span></label>
                        <input type="text" id="ed_apellido_paterno" name="apellido_paterno" class="form-input"
                               maxlength="60" required value="<?= e($matricula['apellido_paterno']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ed_apellido_materno">Apellido materno <span class="text-danger">*</span></label>
                        <input type="text" id="ed_apellido_materno" name="apellido_materno" class="form-input"
                               maxlength="60" required value="<?= e($matricula['apellido_materno']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ed_nombres">Nombres <span class="text-danger">*</span></label>
                        <input type="text" id="ed_nombres" name="nombres" class="form-input"
                               maxlength="100" required value="<?= e($matricula['nombres']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ed_dni">DNI <span class="text-danger">*</span></label>
                        <input type="text" id="ed_dni" name="dni" class="form-input"
                               maxlength="8" pattern="\d{8}" required value="<?= e($matricula['dni']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ed_fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="ed_fecha_nacimiento" name="fecha_nacimiento" class="form-input"
                               value="<?= e((string) ($matricula['fecha_nacimiento'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ed_sexo">Sexo</label>
                        <select id="ed_sexo" name="sexo" class="form-input">
                            <option value="">Sin especificar</option>
                            <option value="M" <?= $matricula['sexo'] === 'M' ? 'selected' : '' ?>>Masculino</option>
                            <option value="F" <?= $matricula['sexo'] === 'F' ? 'selected' : '' ?>>Femenino</option>
                        </select>
                    </div>
                </div>
                <p class="resumen-nota">
                    Estos datos pertenecen al estudiante y se aplican a <strong>todos sus años
                    académicos</strong>. El DNI debe ser único en el sistema.
                </p>
                <div class="btn-group">
                    <button type="button" class="btn btn--secondary btn--sm" data-editar-cancel hidden>Cancelar</button>
                    <button type="submit" class="btn btn--primary btn--sm">Guardar cambios</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Apoderados -->
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Apoderados</p>
            <?php if (empty($vinculos)): ?>
                <div class="empty-state"><p>Sin apoderados vinculados.</p></div>
            <?php else: foreach ($vinculos as $v): ?>
                <div class="apoderado-card <?= $v['es_responsable'] ? 'apoderado-card--responsable' : '' ?>">
                    <div class="apoderado-card__head">
                        <span class="apoderado-card__nombre"><?= e($v['nombre_completo']) ?></span>
                        <span class="matricula-badge matricula-badge--continuador"><?= e($tiposVinculo[$v['tipo_vinculo']] ?? $v['tipo_vinculo']) ?></span>
                    </div>
                    <div class="apoderado-card__meta">
                        DNI <?= e($v['dni']) ?><?= $v['telefono'] ? ' · Tel. ' . e($v['telefono']) : '' ?><?= $v['es_responsable'] ? ' · Responsable' : '' ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
            <div class="btn-group">
                <?php if ($puedeMatricular): ?>
                <a href="<?= url('matriculas/' . $mid . '/apoderado') ?>" class="btn btn--secondary btn--sm">Gestionar apoderados</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Documentos -->
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Documentos</p>
            <?php if (empty($documentos)): ?>
                <div class="empty-state"><p>Sin documentos registrados.</p></div>
            <?php else: ?>
                <div class="documento-checklist">
                    <?php foreach ($documentos as $d): ?>
                    <div class="documento-checklist__item">
                        <div class="documento-checklist__check"><?= (int) $d['entregado'] === 1 ? '✅' : '⬜' ?></div>
                        <div>
                            <div class="documento-checklist__nombre"><?= e($labelDoc[$d['tipo_documento']] ?? $d['tipo_documento']) ?></div>
                            <?php if (!empty($d['observacion'])): ?>
                                <span class="apoderado-card__meta"><?= e($d['observacion']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="btn-group">
                <?php if ($puedeMatricular): ?>
                <a href="<?= url('matriculas/' . $mid . '/documentos') ?>" class="btn btn--secondary btn--sm">Editar documentos</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // ── Secciones de CONTENIDO VARIABLE — regla «datos a la vista, acción aparte»
    // (21/09/2026, ver docs/modulos/ui.md). Una sección CON datos es una card
    // abierta con sus acciones en la cabecera; SIN datos no se pinta, y queda
    // solo su botón en la card «Registrar notas fuera del registro del docente».
    // Nunca datos detrás de un <details> cerrado (tampoco se imprimen).
    //
    // Notas del colegio de origen — regla del colegio (10/09/2026): NO entran
    // en la boleta del COCIAP; son informativas para los docentes con carga en
    // su sección. Sin filtro por tipo ni estado (ningún flag detecta al que
    // llegó tarde), salvo el trasladado de SALIDA ($registraLlegadaTarde).
    $verOrigen = !empty($registraLlegadaTarde) && !empty($notasExternas);
    $verSiagie = $puedeGestionar && !empty($notasAutSiagie);
    $midNS     = (int) ($matNotasSiagie ?? $mid);

    // Botones de la card de registro: solo los de secciones que aún no tienen
    // datos (las que sí, llevan su acción en su propia cabecera).
    $puedeRegistrarOrigen   = !empty($registraLlegadaTarde) && $puedeMatricular && empty($notasExternas);
    $puedeRegistrarExtra    = !empty($registraLlegadaTarde) && $puedeGestionar;
    $puedeRegistrarSiagie   = $puedeGestionar && empty($notasAutSiagie);
    $verRegistro = $puedeRegistrarOrigen || $puedeRegistrarExtra || $puedeRegistrarSiagie;
    ?>

    <?php if ($verOrigen): ?>
    <div class="card mat-seccion-ancha">
        <div class="card__body">
            <div class="card__header card__header--between">
                <p class="form-section-title">
                    Notas del colegio de origen
                    <?php $proc = PROCEDENCIA_ORIGEN; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                </p>
                <?php if ($puedeMatricular): ?>
                <div class="btn-group">
                    <a href="<?= url('matriculas/' . $mid . '/notas-externas') ?>" class="btn btn--secondary btn--sm">
                        Agregar o corregir notas
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php // El "a dónde va" sale del punto único (PROCEDENCIAS_NOTA): es
                  // lo que de verdad separa a las secciones vecinas de esta ficha. ?>
            <p class="proc-destino"><?= e(procedencia_nota(PROCEDENCIA_ORIGEN)['destino']) ?></p>
            <?php if (!empty($notasExternas[0]['colegio_origen'])): ?>
                <p class="text-sm">Procede de <strong><?= e($notasExternas[0]['colegio_origen']) ?></strong></p>
            <?php endif; ?>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead><tr><th>Área (origen)</th><th>Competencia</th><th>Periodo</th><th class="text-center">Nota</th><th>Área nuestra</th></tr></thead>
                    <tbody>
                        <?php foreach ($notasExternas as $n): ?>
                        <tr>
                            <td class="text-sm"><?= e($n['area_nombre']) ?></td>
                            <td class="text-sm"><?= e($n['competencia_nombre']) ?></td>
                            <td class="text-sm"><?= e($n['periodo_nombre']) ?></td>
                            <td class="text-center"><span class="matricula-badge matricula-badge--nuevo"><?= e($n['nota_literal']) ?></span></td>
                            <td class="text-sm text-muted">
                                <?= e($n['area_mapeada_boleta'] ?: $n['area_mapeada'] ?: 'Sin mapear') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($verSiagie): ?>
    <?php // Nota que dirección autoriza para un alumno NO evaluado: va SOLO al
          // acta SIAGIE. Sale también para un trasladado (antes, card suelta). ?>
    <div class="card mat-seccion-ancha">
        <div class="card__body">
            <div class="card__header card__header--between">
                <p class="form-section-title">
                    Notas autorizadas para SIAGIE (dirección)
                    <?php $proc = PROCEDENCIA_SIAGIE; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                </p>
                <div class="btn-group">
                    <a href="<?= url('matriculas/' . $midNS . '/notas-siagie') ?>" class="btn btn--secondary btn--sm">Gestionar notas autorizadas</a>
                    <a href="<?= url('matriculas/' . $midNS . '/notas-siagie/informe') ?>" target="_blank" class="btn btn--secondary btn--sm">Informe imprimible</a>
                </div>
            </div>
            <p class="proc-destino"><?= e(procedencia_nota(PROCEDENCIA_SIAGIE)['destino']) ?></p>
            <div class="tabla-notas-wrapper">
                <table class="tabla-notas">
                    <thead><tr><th>Bimestre</th><th>Competencia</th><th class="text-center">Nota</th></tr></thead>
                    <tbody>
                    <?php foreach ($notasAutSiagie as $na): ?>
                        <tr>
                            <td class="text-sm"><?= e($na['periodo_nombre']) ?></td>
                            <td class="text-sm"><?= e($na['competencia_nombre']) ?></td>
                            <td class="text-center"><span class="matricula-badge matricula-badge--nuevo"><?= e($na['nota_literal']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($verRegistro): ?>
    <div class="card mat-seccion-ancha">
        <div class="card__body">
            <p class="form-section-title">Registrar notas fuera del registro del docente</p>
            <p class="text-muted text-sm">
                Las notas del colegio anterior son solo informativas; las de bimestres
                cerrados son nuestras y van a la boleta y al SIAGIE; la nota autorizada por
                dirección va solo al SIAGIE.
            </p>
            <ul class="mat-llegada__lista">
                <?php if ($puedeRegistrarOrigen): ?>
                <li class="mat-llegada__item">
                    <span>
                        Notas del colegio de origen
                        <?php $proc = PROCEDENCIA_ORIGEN; $procIcono = false; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                    </span>
                    <a href="<?= url('matriculas/' . $mid . '/notas-externas') ?>" class="btn btn--secondary btn--sm">
                        Registrar notas de origen
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($puedeRegistrarExtra): ?>
                    <?php foreach ($pendientesExtra as $pe): ?>
                    <li class="mat-llegada__item">
                        <span>
                            <strong><?= e($pe['periodo_nombre']) ?></strong>
                            — <?= (int) $pe['total'] ?> competencia(s) sin nota
                            <?php $proc = PROCEDENCIA_EXTRAORDINARIA; $procIcono = false; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                        </span>
                        <a href="<?= url('rectificaciones/extraordinaria/lote?matricula=' . $mid . '&periodo=' . (int) $pe['periodo_id']) ?>"
                           class="btn btn--primary btn--sm">
                            Calificar todo el bimestre (<?= (int) $pe['total'] ?>)
                        </a>
                    </li>
                    <?php endforeach; ?>
                    <li class="mat-llegada__item">
                        <span>
                            Notas de bimestres cerrados
                            <?php if (empty($pendientesExtra)): ?>
                                <span class="text-muted text-sm">— ninguna competencia sin nota</span>
                            <?php endif; ?>
                        </span>
                        <a href="<?= url('rectificaciones/matricula/' . $mid) ?>" class="btn btn--secondary btn--sm">
                            Ver detalle por competencia
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($puedeRegistrarSiagie): ?>
                <li class="mat-llegada__item">
                    <span>
                        Nota autorizada solo para SIAGIE (no va a la boleta)
                        <?php $proc = PROCEDENCIA_SIAGIE; $procIcono = false; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                    </span>
                    <a href="<?= url('matriculas/' . $midNS . '/notas-siagie') ?>" class="btn btn--secondary btn--sm">
                        Registrar nota autorizada
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Exoneraciones (áreas/subáreas sin evaluación este año) — SOLO consulta:
         el registro vive como acción desplegable en "Gestión de la matrícula".
         Sin exoneraciones no se pinta (regla «datos a la vista», 21/09/2026). -->
    <?php if (!empty($exoneraciones)): ?>
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Exoneraciones</p>
            <div class="info-grid">
                <?php foreach ($exoneraciones as $ex): ?>
                <div class="info-item">
                    <span class="info-item__label">
                        <?= e($ex['area_nombre'] . ($ex['subarea_nombre'] ? ' — ' . $ex['subarea_nombre'] : '')) ?>
                    </span>
                    <span class="info-item__value"><?= e($ex['motivo']) ?></span>
                    <span class="text-sm text-muted">
                        Registrada el <?= e(date('d/m/Y', strtotime($ex['registrado_en']))) ?>
                        por <?= e($ex['registrado_por_nombre']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Constancia de traslado -->
    <?php if ($traslado): ?>
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Constancia de traslado</p>
            <div class="info-grid">
                <div class="info-item"><span class="info-item__label">N° de constancia</span><span class="info-item__value"><?= e($traslado['numero_constancia']) ?></span></div>
                <div class="info-item"><span class="info-item__label">Estado</span>
                    <span class="info-item__value">
                        <span class="matricula-badge matricula-badge--<?= $traslado['estado'] === 'anulado' ? 'desactivado' : 'continuador' ?>">
                            <?= $traslado['estado'] === 'anulado' ? 'Anulada' : 'Vigente' ?>
                        </span>
                    </span>
                </div>
                <div class="info-item"><span class="info-item__label">IE destino</span><span class="info-item__value"><?= e($traslado['ie_destino_nombre']) ?></span></div>
                <div class="info-item"><span class="info-item__label">Fecha</span><span class="info-item__value"><?= fecha_es($traslado['fecha_constancia']) ?></span></div>
            </div>
            <?php // Los DATOS del traslado (arriba) los ve cualquiera que entre a la
                  // matricula. Estos dos enlaces NO: `TrasladoController` exige admin/RA
                  // en el constructor, asi que a directores y secretarias les devolvia
                  // 403. El dato se queda; el acceso al modulo de traslados, no. ?>
            <?php if ($puedeGestionar): ?>
            <div class="btn-group">
                <a href="<?= url('traslados/' . $traslado['id'] . '/imprimir') ?>" target="_blank" rel="noopener"
                   class="btn btn--secondary btn--sm">Imprimir constancia</a>
                <a href="<?= url('traslados') ?>" class="btn btn--secondary btn--sm">Ver registro</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Retorno de grado -->
    <?php if ($retorno): ?>
    <?php $retornoActivo = ($retorno['estado'] ?? 'activo') === 'activo'; ?>
    <div class="card">
        <div class="card__body">
            <p class="form-section-title">Retorno de grado</p>
            <div class="retorno-aviso">
                <?php if ($retornoActivo): ?>
                    Asiste al grado operativo <strong><?= e($retorno['grado_destino'] ?? '—') ?></strong>
                    desde el <?= fecha_es($retorno['fecha_retorno']) ?>.
                    <br>Motivo: <?= e($retorno['motivo']) ?>
                <?php else: ?>
                    <strong>Revertido.</strong> El estudiante volvió a su grado oficial
                    el <?= fecha_es($retorno['fecha_reversion'] ?? $retorno['fecha_retorno']) ?>.
                    Estuvo en el grado operativo <strong><?= e($retorno['grado_destino'] ?? '—') ?></strong>
                    desde el <?= fecha_es($retorno['fecha_retorno']) ?>.
                    <br>Motivo del retorno: <?= e($retorno['motivo']) ?>
                    <?php if (!empty($retorno['motivo_reversion'])): ?>
                    <br>Motivo de la reversión: <?= e($retorno['motivo_reversion']) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($retornoActivo && $puedeGestionar): ?>
            <div class="btn-group form-actions">
                <a href="<?= url('matriculas/' . $mid . '/retorno/revertir') ?>" class="btn btn--danger">
                    Revertir retorno (volver al grado oficial)
                </a>
            </div>
            <?php elseif (!$retornoActivo && $puedeGestionar && !empty($retorno['matricula_operativa_id'])): ?>
            <div class="btn-group form-actions">
                <a href="<?= url('rectificaciones/matricula/' . (int) $retorno['matricula_operativa_id']) ?>" class="btn btn--secondary">
                    Rectificar notas del grado operativo
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Boleta (consulta / impresión) ─────────────────────────── -->
<!-- Visible en cualquier estado. Trasladado consumado: su ULTIMA boleta OFICIAL
     (solo bimestres cerrados, con firma, sin QR — el token está muerto). Otras
     desactivadas (deuda/baja): SIEMPRE borrador, jamás documento oficial. -->
<div class="card mb-md">
    <div class="card__body">
        <p class="form-section-title">Boleta</p>
        <p class="text-sm text-muted mb-sm">Consulta la boleta del estudiante en pantalla o imprímela en formato físico A4.</p>
        <?php if ($matricula['estado'] === 'desactivado' && $matricula['tipo'] === 'trasladado'): ?>
            <p class="text-sm text-muted mb-sm">Estudiante trasladado: se emite su última boleta <strong>oficial</strong> (solo bimestres cerrados, con firma y sin código QR).</p>
        <?php elseif ($matricula['estado'] === 'desactivado'): ?>
            <p class="text-sm text-muted mb-sm">Matrícula desactivada: la boleta se emite solo como <strong>borrador</strong> (sin QR ni firma) y no constituye documento oficial.</p>
        <?php endif; ?>
        <?php if (!empty($tieneBoleta)): ?>
        <div class="btn-group">
            <a href="<?= url('matriculas/' . $matricula['id'] . '/boleta') ?>" target="_blank" rel="noopener"
               class="btn btn--secondary">Ver boleta digital</a>
            <a href="<?= url('matriculas/' . $matricula['id'] . '/boleta/imprimir') ?>" target="_blank" rel="noopener"
               class="btn btn--secondary">
                <span class="btn-icon btn-icon--print" aria-hidden="true"></span>
                Imprimir boleta
            </a>
        </div>
        <?php else: ?>
        <?php // Sin bimestre publicable con competencias bloqueadas: visibles pero
              // inertes, con el motivo (21/09/2026). Misma regla que la ruta. ?>
        <div class="btn-group">
            <span class="btn btn--secondary is-disabled" aria-disabled="true">Ver boleta digital</span>
            <span class="btn btn--secondary is-disabled" aria-disabled="true">
                <span class="btn-icon btn-icon--print" aria-hidden="true"></span>
                Imprimir boleta
            </span>
        </div>
        <p class="text-sm text-muted">Aún no tiene calificaciones oficiales.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ── Gestión de la matrícula (operaciones diferenciadas) ────── -->
<?php if ($puedeGestionar): ?>
<div class="card mb-lg">
    <div class="card__body">
        <p class="form-section-title">Gestión de la matrícula</p>

        <?php if (!$esActivo): ?>
            <?php if (!empty($pendientes)): ?>
            <div class="mat-pendientes">
                <p class="mat-pendientes__titulo">Para activar, falta completar:</p>
                <ul class="mat-pendientes__list">
                    <?php foreach ($pendientes as $pend): ?>
                    <li><?= e($pend) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="mat-accion mat-accion--safe">
                <div class="mat-accion__info">
                    <span class="mat-accion__titulo">Activar matrícula</span>
                    <span class="mat-accion__desc">Cumple todos los requisitos. Al activarla contará para boletas, notas y orden de mérito.</span>
                </div>
                <div class="mat-accion__control">
                    <form method="POST" action="<?= url('matriculas/' . $mid . '/activar') ?>"
                          onsubmit="return confirm('¿Activar esta matrícula?')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--primary">Activar matrícula</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($matricula['estado'] === 'desactivado'
                      && in_array($matricula['tipo'], ['continuador', 'nuevo'], true)): ?>
            <!-- Marcar como retirado: el estudiante ya no asiste (sin traslado
                 oficial) y no debe calificarse. Excluye de calificaciones,
                 conducta y transversales. Reversible. Ver migración 045. -->
            <div class="mat-accion mat-accion--danger">
                <div class="mat-accion__info">
                    <span class="mat-accion__titulo">Marcar como retirado (ya no asiste)</span>
                    <span class="mat-accion__desc">El estudiante deja de aparecer en calificaciones, conducta y transversales. Úsalo cuando ya no asiste pero no hay traslado oficial. Es reversible.</span>
                </div>
                <div class="mat-accion__control">
                    <form method="POST" action="<?= url('matriculas/' . $mid . '/retirar') ?>"
                          onsubmit="return confirm('¿Marcar como retirado? El estudiante dejará de aparecer en calificaciones y conducta. Podrás revertirlo.')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--danger">Marcar retirado</button>
                    </form>
                </div>
            </div>
            <?php elseif ($matricula['estado'] === 'desactivado' && $matricula['tipo'] === 'retirado'): ?>
            <div class="mat-accion mat-accion--safe">
                <div class="mat-accion__info">
                    <span class="mat-accion__titulo">Retirado — ya no asiste</span>
                    <span class="mat-accion__desc">Está excluido de calificaciones, conducta y transversales. Puedes revertirlo si retoma la asistencia.</span>
                </div>
                <div class="mat-accion__control">
                    <form method="POST" action="<?= url('matriculas/' . $mid . '/revertir-retiro') ?>"
                          onsubmit="return confirm('¿Revertir el retiro? El estudiante volverá a aparecer en calificaciones y conducta.')">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--primary">Revertir retiro</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Desactivar (requiere motivo) — el motivo se despliega al pulsar
                 "Desactivar". Mejora progresiva: sin JS, el formulario se ve abierto. -->
            <div class="mat-accion mat-accion--danger">
                <div class="mat-accion__info">
                    <span class="mat-accion__titulo">Desactivar matrícula</span>
                    <span class="mat-accion__desc">Conserva el tipo, pero desactiva el acceso del apoderado y oculta sus boletas públicas. Requiere un motivo.</span>
                </div>
                <div class="mat-accion__control" data-desactivar-control hidden>
                    <button type="button" class="btn btn--danger" data-desactivar-toggle>Desactivar</button>
                </div>
                <form method="POST" action="<?= url('matriculas/' . $mid . '/desactivar') ?>"
                      class="mat-desactivar-form" data-desactivar-form
                      onsubmit="return confirm('¿Desactivar esta matrícula? Se desactivará el acceso del apoderado y sus boletas públicas.')">
                    <?= csrf_field() ?>
                    <label class="form-label" for="motivo_desactivar">Motivo de la desactivación <span class="text-danger">*</span></label>
                    <textarea id="motivo_desactivar" name="motivo" class="form-input" rows="2"
                              required placeholder="Indica por qué se desactiva la matrícula"></textarea>
                    <div class="btn-group">
                        <button type="button" class="btn btn--secondary" data-desactivar-cancel hidden>Cancelar</button>
                        <button type="submit" class="btn btn--danger">Confirmar baja</button>
                    </div>
                </form>
            </div>

            <!-- Trasladar de colegio -->
            <div class="mat-accion mat-accion--danger">
                <div class="mat-accion__info">
                    <span class="mat-accion__titulo">Trasladar de colegio</span>
                    <span class="mat-accion__desc">Genera la constancia de traslado a otra institución educativa y da de baja al estudiante.</span>
                </div>
                <div class="mat-accion__control">
                    <a href="<?= url('matriculas/' . $mid . '/trasladar') ?>" class="btn btn--danger">Trasladar</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Exonerar de área: registra una exoneración vigente del año. El
             formulario se despliega al pulsar "Exonerar" (mismo disclosure que
             Desactivar; sin JS se ve abierto). El candado de notas vivas y el
             rol los valida el servidor (Admin\ExoneracionController). -->
        <?php if (!empty($opcionesExoneracion)): ?>
        <div class="mat-accion">
            <div class="mat-accion__info">
                <span class="mat-accion__titulo">Exonerar de área</span>
                <span class="mat-accion__desc">Registra una exoneración vigente para este año (ej. Educación Religiosa): el área queda sin evaluación para el estudiante. Si ya tiene notas en el área, se puede registrar igual, pero esas calificaciones dejarán de mostrarse en la boleta y saldrá EXO en los cuatro bimestres.</span>
            </div>
            <div class="mat-accion__control" data-exonerar-control hidden>
                <button type="button" class="btn btn--secondary" data-exonerar-toggle>Exonerar</button>
            </div>
            <form method="POST" action="<?= url('matriculas/' . $mid . '/exonerar') ?>"
                  class="mat-exonerar-form" data-exonerar-form>
                <?= csrf_field() ?>
                <label class="form-label" for="area_subarea">Área / Subárea exonerada <span class="text-danger">*</span></label>
                <select id="area_subarea" name="area_subarea" class="form-input" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($opcionesExoneracion as $op): ?>
                        <option value="<?= e($op['value']) ?>"><?= e($op['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label" for="motivo_exo">Motivo</label>
                <input type="text" id="motivo_exo" name="motivo" class="form-input"
                       placeholder="Ej. Solicitud escrita del apoderado" maxlength="255">
                <?php // Obligatoria SOLO si el alumno ya tiene notas del año en esa
                      // area; el servidor lo comprueba y sin ella rechaza el registro. ?>
                <label class="form-check">
                    <input type="checkbox" name="confirmar_notas" value="1">
                    <span>
                        Si ya tiene calificaciones en esa área, confirmo que
                        <strong>dejarán de mostrarse</strong> en su boleta —incluidas las
                        de bimestres cerrados y entregados— y que saldrá <strong>EXO</strong>
                        en los cuatro bimestres. Las notas no se borran: si se revoca la
                        exoneración, reaparecen.
                    </span>
                </label>
                <div class="btn-group">
                    <button type="button" class="btn btn--secondary" data-exonerar-cancel hidden>Cancelar</button>
                    <button type="submit" class="btn btn--primary">Registrar exoneración</button>
                    <a href="<?= url('admin/exoneraciones/' . (int) $matricula['seccion_id']) ?>"
                       class="btn btn--secondary">Revocar en Exoneraciones</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Retorno de grado: la operación más delicada. Se oculta si ya hay un
             retorno activo (en ese caso, la reversión se ofrece en su tarjeta). -->
        <?php if (!($retorno && ($retorno['estado'] ?? 'activo') === 'activo')): ?>
        <div class="mat-accion mat-accion--critico">
            <div class="mat-accion__info">
                <span class="mat-accion__titulo">⚠ Retorno de grado</span>
                <span class="mat-accion__desc">Caso especial y auditable: crea una matrícula operativa en un grado inferior; el estudiante asiste y compite en ese grado. La matrícula oficial se conserva. Requiere un motivo.</span>
            </div>
            <div class="mat-accion__control">
                <a href="<?= url('matriculas/' . $mid . '/retorno') ?>" class="btn btn--danger">Retorno de grado</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Rectificación de calificaciones: corrige notas ya cerradas o
             bloqueadas con auditoría obligatoria (módulo aparte). -->
        <div class="mat-accion">
            <div class="mat-accion__info">
                <span class="mat-accion__titulo">
                    Rectificar o completar calificaciones
                    <?php $proc = PROCEDENCIA_EXTRAORDINARIA; $procIcono = true; require VIEW_PATH . '/shared/_procedencia-chip.php'; ?>
                </span>
                <span class="proc-destino"><?= e(procedencia_nota(PROCEDENCIA_EXTRAORDINARIA)['destino']) ?></span>
                <span class="mat-accion__desc">Dos operaciones en la misma pantalla. <strong>Rectificar:</strong> corrige notas de bimestres aprobados o competencias bloqueadas (regenera el orden de mérito del bimestre). <strong>Completar:</strong> registra las calificaciones que le faltan a un estudiante matriculado después del cierre — una a una, o el bimestre entero en una sola grilla.</span>
            </div>
            <div class="mat-accion__control">
                <a href="<?= url('rectificaciones/matricula/' . $mid) ?>" class="btn btn--secondary">Abrir calificaciones</a>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>
