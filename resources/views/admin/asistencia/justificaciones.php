<?php
/**
 * Asistencia — FALTAS Y JUSTIFICACIONES de una sección (09/10/2026). Solo
 * lectura: las FJ/TJ registradas por el auxiliar, resumidas en KPIs, por
 * estudiante (con sus fechas) y por motivo.
 *
 * 🔴 UN VOCABULARIO (13/10/2026): «Faltas justificadas» (FJ con el motivo
 * principal) · «Cuentan como falta» (FJ con otro motivo) · «Tardanzas
 * justificadas». La pantalla dice lo que cada justificación VALE, nunca la marca
 * «FJ» junto a su efecto «F»: el antiguo «Detalle por fecha» lo hacía y la mitad
 * de sus filas decía FJ y F a la vez. La leyenda y la tabla por motivo son
 * parciales compartidos con el reporte del auxiliar.
 *
 * Desde el 13/10/2026 abre con el «SEGUIMIENTO DE FALTAS»: los estudiantes con
 * `AsistenciaModel::UMBRAL_SEGUIMIENTO_FALTAS` faltas o más (las que cuentan como
 * F), con el apoderado responsable y su celular. Lo calcula
 * `AsistenciaModel::seguimientoFaltas`; esta vista solo lo pinta.
 *
 * AUTORIZADA = FJ con el MOTIVO PRINCIPAL (ancla) del bimestre: es la que cuenta
 * como FJ; con otro motivo cuenta como F. La regla la aplica el modelo
 * (`AsistenciaModel::resumenJustificaciones`), no esta vista.
 *
 * Bimestre editable: el BORRADOR. Historial o sección bloqueada: solo lo OFICIAL.
 * Sin aviso ni marcas de confirmación (13/10/2026, decisión del usuario): la
 * confirmación se ve y se hace en la grilla.
 *
 * @var array       $seccion          { id, grado_nombre, seccion_nombre, nivel_nombre }
 * @var array|null  $periodoVer       periodo mostrado o null
 * @var array       $periodosNav      pestañas del historial (+ 'cierre')
 * @var bool        $soloLectura      periodo no editable (historial)
 * @var array|null  $cierre           cierre vigente del periodo mostrado
 * @var bool        $porFechas        bimestre por fechas (los demás no tienen motivos)
 * @var array|null  $resumen          AsistenciaModel::resumenJustificaciones()
 * @var string|null $motivoPrincipal  nombre del ancla del bimestre
 * @var array       $seguimiento      AsistenciaModel::seguimientoFaltas()
 * @var array       $contactos        NominaModel::apoderadoResponsable() [mid => {nombre, telefono}]
 */

$sid     = (int) $seccion['id'];
$pidVer  = $periodoVer ? (int) $periodoVer['id'] : 0;
$diasSemana = [1 => 'Lun.', 'Mar.', 'Mié.', 'Jue.', 'Vie.', 'Sáb.', 'Dom.'];
// «Lun. 08/09» a partir de AAAA-MM-DD.
$fechaCorta = static function (string $f) use ($diasSemana): string {
    $ts = strtotime($f);
    return $diasSemana[(int) date('N', $ts)] . ' ' . date('d/m', $ts);
};
$motivosTabla = $resumen['motivos'] ?? [];
// Las tres categorías, en el orden de KPIs y columnas. La pastilla de cada una
// la dibuja `_pastilla-categoria.php`.
$categorias   = ['fj_autorizadas' => 'Faltas justificadas', 'fj_como_f' => 'Cuentan como falta', 'tj' => 'Tardanzas justificadas'];
?>

<div class="page-header">
    <a href="<?= url('admin/asistencia/' . $sid . ($pidVer ? '?periodo=' . $pidVer : '')) ?>"
       class="btn btn--secondary btn--sm">← Tabla</a>
    <div>
        <h1 class="page-title">
            Faltas y justificaciones — <?= e($seccion['grado_nombre']) ?> <?= e($seccion['seccion_nombre']) ?>
        </h1>
        <p class="page-subtitle">
            <?= e($seccion['nivel_nombre']) ?>
            <?php if ($periodoVer): ?>
                · <strong><?= e($periodoVer['nombre_display']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($periodosNav)): ?>
    <nav class="periodo-tabs" aria-label="Bimestres">
        <?php foreach ($periodosNav as $p):
            $esActual = (int) $p['id'] === $pidVer; ?>
            <a href="<?= url('admin/asistencia/' . $sid . '/justificaciones?periodo=' . (int) $p['id']) ?>"
               class="periodo-tab<?= $esActual ? ' periodo-tab--activa' : '' ?>"
               <?= $esActual ? 'aria-current="page"' : '' ?>>
                <span class="periodo-tab__nombre"><?= e($p['nombre_display']) ?></span>
                <?php if ((bool) $p['editable']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--curso">En curso</span>
                <?php elseif ($p['cierre']): ?>
                    <span class="periodo-tab__estado periodo-tab__estado--bloqueado">Aprobado</span>
                <?php else: ?>
                    <span class="periodo-tab__estado">Sin cierre</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if (!$periodoVer): ?>
    <div class="empty-state">
        <p>No hay periodo abierto. Selecciona un bimestre del historial.</p>
    </div>
<?php elseif (!$porFechas): ?>
    <div class="empty-state">
        <p>Este bimestre se registró solo con números: no tiene fechas ni motivos de justificación.</p>
    </div>
<?php elseif ($resumen['kpis']['total'] === 0 && $seguimiento === []): ?>
    <div class="empty-state">
        <p>No hay faltas en seguimiento ni justificaciones registradas en este bimestre.</p>
    </div>
<?php else:
    $k = $resumen['kpis']; ?>

    <?php // SEGUIMIENTO DE FALTAS (13/10/2026): primero, porque comunicarse con la
          // familia es la tarea principal del auxiliar. «Datos a la vista»: sin
          // nadie en el umbral, el bloque no se pinta. ?>
    <?php if ($seguimiento !== []): ?>
        <h2 class="asistencia-anexo-titulo">Seguimiento de faltas</h2>
        <p class="text-sm text-muted just-seccion__nota">
            Estudiantes con <?= (int) \App\Models\AsistenciaModel::UMBRAL_SEGUIMIENTO_FALTAS ?> o más faltas
            en el bimestre: las faltas sin justificar y las que cuentan como falta.
        </p>
        <div class="tabla-notas-wrapper">
            <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
                <thead>
                    <tr>
                        <th class="tr-num">N&deg;</th>
                        <th class="tr-nombre">Apellidos y Nombres</th>
                        <th class="tr-anexo-tipo">Faltas</th>
                        <?php foreach (['f' => 'Sin justificar', 'fj_como_f' => 'Cuentan como falta'] as $cat => $titulo): ?>
                            <th class="tr-anexo-cifra">
                                <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                                <span class="just-seccion__rotulo"><?= e($titulo) ?></span>
                            </th>
                        <?php endforeach; ?>
                        <th class="tr-anexo-fecha">Última falta</th>
                        <th class="tr-anexo-apoderado">Apoderado</th>
                        <th class="tr-anexo-celular">Celular</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seguimiento as $s):
                        $ap  = $contactos[(int) $s['matricula_id']] ?? null;
                        $tel = $ap['telefono'] ?? null; ?>
                        <tr>
                            <td class="tr-num"><?= (int) $s['num'] ?></td>
                            <td class="tr-nombre"><?= e($s['nombre']) ?></td>
                            <td class="tr-anexo-tipo"><?= (int) $s['faltas'] ?></td>
                            <td class="tr-anexo-cifra"><?= (int) $s['f'] ?></td>
                            <td class="tr-anexo-cifra"><?= (int) $s['fj_como_f'] ?></td>
                            <td class="tr-anexo-fecha"><?= e($fechaCorta($s['ultima'])) ?></td>
                            <td class="tr-anexo-apoderado">
                                <?= $ap !== null && $ap['nombre'] !== '' ? e($ap['nombre']) : '<span class="text-muted">Sin apoderado registrado</span>' ?>
                            </td>
                            <td class="tr-anexo-celular">
                                <?php if ($tel !== null): ?>
                                    <?php // href solo con dígitos y «+»: el número se guarda con espacios o guiones. ?>
                                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $tel)) ?>" class="just-seccion__tel"><?= e($tel) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">Sin celular registrado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($k['total'] === 0): ?>
        <div class="empty-state">
            <p>No hay justificaciones registradas en este bimestre.</p>
        </div>
    <?php else: ?>

    <h2 class="asistencia-anexo-titulo">Justificaciones</h2>

    <?php // UN VOCABULARIO en toda la pantalla (13/10/2026): una pastilla (la de la
          // grilla) y un texto (lo que vale). Nunca la marca «FJ» junto a su efecto
          // «F»: la mitad de las FJ del III llevaban motivo verbal y la misma fila
          // decía FJ y F a la vez. ?>
    <div class="cuadros-kpis just-seccion__kpis">
        <?php foreach ($categorias as $cat => $titulo): ?>
            <div class="cuadros-kpi">
                <span class="cuadros-kpi__n"><?= (int) $k[$cat] ?></span>
                <span class="cuadros-kpi__t">
                    <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?> <?= e($titulo) ?>
                </span>
            </div>
        <?php endforeach; ?>
        <div class="cuadros-kpi">
            <span class="cuadros-kpi__n"><?= (int) $k['estudiantes'] ?></span>
            <span class="cuadros-kpi__t">Estudiantes con justificaciones</span>
        </div>
    </div>

    <?php require VIEW_PATH . '/admin/asistencia/_leyenda-justificaciones.php'; ?>

    <?php // Por estudiante (13/10/2026): une el antiguo «Por estudiante» y el
          // «Detalle por fecha». Cada fecha sale UNA vez, en la columna de lo que
          // cuenta. ?>
    <div class="tabla-notas-wrapper">
        <table class="tabla-notas asistencia-tabla tabla-anexo just-seccion__tabla">
            <thead>
                <tr>
                    <th class="tr-num">N&deg;</th>
                    <th class="tr-nombre">Apellidos y Nombres</th>
                    <?php foreach ($categorias as $cat => $titulo): ?>
                        <th class="tr-anexo-fechas">
                            <?php require VIEW_PATH . '/admin/asistencia/_pastilla-categoria.php'; ?>
                            <span class="just-seccion__rotulo"><?= e($titulo) ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen['estudiantes'] as $r): ?>
                    <tr>
                        <td class="tr-num"><?= (int) $r['num'] ?></td>
                        <td class="tr-nombre"><?= e($r['nombre']) ?></td>
                        <?php foreach (array_keys($categorias) as $cat): ?>
                            <td class="tr-anexo-fechas">
                                <?php require VIEW_PATH . '/admin/asistencia/_celda-fechas.php'; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="asistencia-anexo-titulo">Por motivo</h2>
    <?php require VIEW_PATH . '/admin/asistencia/_tabla-motivos.php'; ?>
    <?php endif; ?>
<?php endif; ?>
