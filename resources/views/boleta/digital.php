<?php
/**
 * Vista: boleta digital — mobile-first, responsive, sin truncamiento.
 *
 * @var array  $alumno      { nombre_completo, dni, grado_nombre, seccion_nombre,
 *                            nivel_nombre, escala_boleta, anio_academico }
 * @var array  $periodos    [{ id, numero, nombre_display }, ...]
 * @var array  $areas       areas[nombre][comp_id]
 *                            = { nombre, bimestres[pid]{nota,literal,conclusion},
 *                                literal_final }
 * @var array  $conducta    [periodo_id => literal]  (vacío si no hay notas de conducta)
 * @var string $institucion
 * @var string $url_boleta  URL completa de esta vista para el QR
 */

$esSecundaria = ($alumno['escala_boleta'] === 'ambas');
$hoy          = (new DateTime())->format('d/m/Y');
$romanos      = ['I', 'II', 'III', 'IV'];

// Separa las competencias transversales para ubicarlas al final.
// "transv", no "transversal": en SECUNDARIA el area se rotula "Comp. Transv."
// (misma nota que en boleta/alumno.php).
$areasRegulares     = [];
$areasTransversales = [];
foreach ($areas as $_n => $_c) {
    if (stripos($_n, 'transv') !== false) {
        $areasTransversales[$_n] = $_c;
    } else {
        $areasRegulares[$_n] = $_c;
    }
}
$areasOrdenadas = array_merge($areasRegulares, $areasTransversales);
unset($_n, $_c);

// Borrador: vista previa del docente antes del cierre oficial del bimestre.
$vistaPrevia = $vistaPrevia ?? false;
?>

<!-- ══ CONTROLES FLOTANTES (solo pantalla) ══════════════════════ -->
<div class="bd-fab" role="toolbar" aria-label="Acciones de boleta">
    <button class="bd-fab__btn bd-fab__btn--primary"
            type="button"
            onclick="window.print()"
            aria-label="Imprimir boleta">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <polyline points="6,9 6,2 18,2 18,9"/>
            <path d="M6,18H4a2,2,0,0,1-2-2V11a2,2,0,0,1,2-2H20a2,2,0,0,1,2,2v5a2,2,0,0,1-2,2H18"/>
            <rect x="6" y="14" width="12" height="8"/>
        </svg>
        <span>Imprimir</span>
    </button>
    <button class="bd-fab__btn bd-fab__btn--secondary"
            type="button"
            id="btn-pdf"
            aria-label="Descargar como PDF">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M14,2H6A2,2,0,0,0,4,4V20a2,2,0,0,0,2,2H18a2,2,0,0,0,2-2V8Z"/>
            <polyline points="14,2 14,8 20,8"/>
            <line x1="12" y1="18" x2="12" y2="12"/>
            <polyline points="9,15 12,18 15,15"/>
        </svg>
        <span>PDF</span>
    </button>
    <a href="#"
       id="bdCerrar"
       class="bd-fab__btn bd-fab__btn--ghost"
       aria-label="Cerrar esta ventana">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        <span>Cerrar</span>
    </a>
</div>

<!-- ══ DOCUMENTO ════════════════════════════════════════════════ -->
<article class="bd"
         id="boleta-documento"
         data-nivel="<?= e($alumno['nivel_codigo'] ?? '') ?>">

    <?php if ($vistaPrevia): ?>
    <?php // Aviso en el flujo: explica en texto que esto no es oficial. ?>
    <div class="bd-borrador" role="note">
        <span class="bd-borrador__tag">BORRADOR</span>
        <span class="bd-borrador__msg"><?= e(BOLETA_LEYENDA_BORRADOR) ?></span>
    </div>
    <?php
    // MARCA DE AGUA, mismo parcial que la boleta impresa (punto único). Aquí
    // NO es decorativa: sin ella, una captura de pantalla o una foto al monitor
    // sacaba notas de un bimestre sin cerrar sin nada que dijera que son
    // provisionales. Variante `--pantalla`: fija en el viewport, porque este
    // documento se recorre con scroll y una marca anclada al contenido dejaría
    // sin marcar justo las capturas de la zona de notas.
    $marcaModificador = 'boleta-watermark--pantalla';
    include VIEW_PATH . '/boleta/_marca-borrador.php';
    ?>
    <?php endif; ?>

    <!-- ── HEADER INSTITUCIONAL ─────────────────────────────── -->
    <header class="bd-header">
        <div class="bd-header__logo-wrap">
            <img src="<?= url('assets/img/logo_cociap.png') ?>"
                 alt="Logo Colegio de Aplicación COCIAP"
                 class="bd-header__logo">
        </div>
        <div class="bd-header__info">
            <p class="bd-header__ugel">MINEDU · DRE Áncash · UGEL Huaraz</p>
            <h1 class="bd-header__colegio"><?= e($institucion ?? '') ?></h1>
            <p class="bd-header__modular">
                Cód. Modular: <?= $esSecundaria ? '1310044 - 0' : '1719525 - 0' ?>
            </p>
            <p class="bd-header__titulo">Informe de Progreso de las Competencias del Estudiante</p>
        </div>
        <div class="bd-header__meta">
            <span class="bd-header__anio"><?= e($alumno['anio_academico'] ?? '') ?></span>
            <time class="bd-header__fecha" datetime="<?= date('Y-m-d') ?>"><?= $hoy ?></time>
        </div>
    </header>

    <!-- ── DATOS DEL ESTUDIANTE ─────────────────────────────── -->
    <section class="bd-student" aria-label="Datos del estudiante">
        <div class="bd-student__field bd-student__field--nombre">
            <span class="bd-student__label">Apellidos y Nombres</span>
            <strong class="bd-student__value"><?= e($alumno['nombre_completo'] ?? '') ?></strong>
        </div>
        <div class="bd-student__field">
            <span class="bd-student__label">DNI</span>
            <strong class="bd-student__value"><?= e($alumno['dni'] ?? '') ?></strong>
        </div>
        <div class="bd-student__field">
            <span class="bd-student__label">Grado y Sección</span>
            <strong class="bd-student__value">
                <?= e($alumno['grado_nombre'] ?? '') ?> &mdash; <?= e($alumno['seccion_nombre'] ?? '') ?>
            </strong>
        </div>
        <div class="bd-student__field">
            <span class="bd-student__label">Nivel</span>
            <strong class="bd-student__value"><?= e($alumno['nivel_nombre'] ?? '') ?></strong>
        </div>
        <?php if (!empty($tutor['nombre'])): ?>
        <div class="bd-student__field">
            <span class="bd-student__label">
                <?= ($tutor['sexo'] ?? null) === 'F' ? 'Tutora' : 'Tutor' ?>
            </span>
            <strong class="bd-student__value"><?= e($tutor['nombre']) ?></strong>
        </div>
        <?php endif; ?>
    </section>

    <!-- ── LEYENDA DE ESCALA ─────────────────────────────────── -->
    <?php $rangos = escala_rangos(); ?>
    <div class="bd-legend" aria-label="Escala de calificaciones">
        <span class="bd-legend__title">Escala:</span>
        <div class="bd-legend__items">
            <span class="bd-legend__item bd-legend__item--ad">
                <strong>AD</strong>
                <span class="bd-legend__desc">Logro destacado (<?= $rangos['AD'] ?>)</span>
            </span>
            <span class="bd-legend__item bd-legend__item--a">
                <strong>A</strong>
                <span class="bd-legend__desc">Logro esperado (<?= $rangos['A'] ?>)</span>
            </span>
            <span class="bd-legend__item bd-legend__item--b">
                <strong>B</strong>
                <span class="bd-legend__desc">En proceso (<?= $rangos['B'] ?>)</span>
            </span>
            <span class="bd-legend__item bd-legend__item--c">
                <strong>C</strong>
                <span class="bd-legend__desc">En inicio (<?= $rangos['C'] ?>)</span>
            </span>
        </div>
    </div>

    <!-- ── CUERPO PRINCIPAL ─────────────────────────────────── -->
    <main class="bd-main">

        <!-- Estado vacío -->
        <?php if (empty($areas)): ?>
        <div class="bd-empty" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>No hay calificaciones registradas para este año académico.</p>
        </div>

        <?php else: ?>

        <!-- Áreas curriculares -->
        <section class="bd-areas" aria-label="Áreas curriculares y competencias">
            <?php
            $areaIndex       = 0;
            $dividerMostrado = false;
            foreach ($areasOrdenadas as $areaNombre => $competencias):
                $areaIndex++;
                $esTransversal = stripos($areaNombre, 'transv') !== false;   // ver nota arriba
                $areaId        = 'bd-area-' . $areaIndex;
                $areaBodyId    = $areaId . '-body';
            ?>
            <?php if ($esTransversal && !$dividerMostrado): $dividerMostrado = true; ?>
            <div class="bd-transversal-divider" aria-hidden="true">
                <span class="bd-transversal-divider__line"></span>
                <span class="bd-transversal-divider__label">Competencias Transversales</span>
                <span class="bd-transversal-divider__line"></span>
            </div>
            <?php endif; ?>
            <article class="bd-area <?= $esTransversal ? 'bd-area--transversal' : '' ?>"
                     id="<?= $areaId ?>">

                <?php
                $_n = mb_strtolower($areaNombre);
                if ($esTransversal) {
                    $_svg = '<path d="M12,2L2,7l10,5,10-5-10-5z"/><path d="M2,17l10,5,10-5"/><path d="M2,12l10,5,10-5"/>';
                } elseif (str_contains($_n, 'arte')) {
                    $_svg = '<path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/>';
                } elseif (str_contains($_n, 'razonamiento') || (str_contains($_n, 'taller') && str_contains($_n, 'raz'))) {
                    // Razonamiento matemático — bombilla / foco (idea, resolución)
                    // Cubre 'Taller de Razonamiento Matemático' y la forma abreviada 'Taller Raz. Matemático'
                    $_svg = '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/>';
                } elseif (str_contains($_n, 'cálculo') || str_contains($_n, 'calculo')) {
                    // Pre-cálculo — gráfica de función con ejes
                    $_svg = '<path d="M4 4v16h16"/><path d="M5 16c3 0 3-8 7-8s4 5 8 5"/>';
                } elseif (str_contains($_n, 'taller')) {
                    $_svg = '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M15 2v2M15 20v2M2 15h2M2 9h2M20 15h2M20 9h2M9 2v2M9 20v2"/>';
                } elseif (str_contains($_n, 'matem')) {
                    $_svg = '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="10" y2="10"/><line x1="14" y1="10" x2="16" y2="10"/><line x1="12" y1="9" x2="12" y2="11"/><line x1="8" y1="14" x2="10" y2="14"/><line x1="8" y1="18" x2="10" y2="18"/><line x1="14" y1="14" x2="16" y2="18"/>';
                } elseif (str_contains($_n, 'comunicac')) {
                    $_svg = '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>';
                } elseif (str_contains($_n, 'ingl')) {
                    $_svg = '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>';
                } elseif (str_contains($_n, 'ciencia')) {
                    $_svg = '<circle cx="12" cy="12" r="1"/><path d="M20.2 20.2c2.04-2.03.02-7.36-4.5-11.9-4.54-4.52-9.87-6.54-11.9-4.5-2.04 2.03-.02 7.36 4.5 11.9 4.54 4.52 9.87 6.54 11.9 4.5Z"/><path d="M15.7 15.7c4.52-4.54 6.54-9.87 4.5-11.9-2.03-2.04-7.36-.02-11.9 4.5-4.52 4.54-6.54 9.87-4.5 11.9 2.03 2.04 7.36.02 11.9-4.5Z"/>';
                } elseif (str_contains($_n, 'física') || str_contains($_n, 'fisica')) {
                    $_svg = '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>';
                } elseif (str_contains($_n, 'personal')) {
                    $_svg = '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>';
                } elseif (str_contains($_n, 'ciudadan') || str_contains($_n, 'cívica') || str_contains($_n, 'civica')) {
                    $_svg = '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>';
                } elseif (str_contains($_n, 'social')) {
                    $_svg = '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>';
                } elseif (str_contains($_n, 'religiosa')) {
                    $_svg = '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>';
                } elseif (str_contains($_n, 'trabajo')) {
                    $_svg = '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>';
                } else {
                    $_svg = '<path d="M2,3h6a4,4,0,0,1,4,4v14a3,3,0,0,0-3-3H2Z"/><path d="M22,3H16a4,4,0,0,0-4,4v14a3,3,0,0,1,3-3h7Z"/>';
                }
                ?>
                <button class="bd-area__header"
                        type="button"
                        aria-expanded="true"
                        aria-controls="<?= $areaBodyId ?>"
                        data-area-toggle>
                    <div class="bd-area__header-left">
                        <span class="bd-area__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <?= $_svg ?>
                            </svg>
                        </span>
                        <h2 class="bd-area__name"><?= e($areaNombre) ?></h2>
                    </div>
                    <div class="bd-area__header-right">
                        <span class="bd-area__count">
                            <?= count($competencias) ?>
                            competencia<?= count($competencias) !== 1 ? 's' : '' ?>
                        </span>
                        <span class="bd-area__chevron" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="18,15 12,9 6,15"/>
                            </svg>
                        </span>
                    </div>
                </button>

                <div class="bd-area__body"
                     id="<?= $areaBodyId ?>"
                     role="region"
                     aria-label="Competencias de <?= e($areaNombre) ?>">

                    <?php foreach ($competencias as $compId => $comp):
                        $literalFinal   = $comp['literal_final'];
                        $esExonerado    = !empty($comp['es_exonerado']);
                        $lf             = $literalFinal ? strtolower($literalFinal) : 'vacio';
                        $motivosOmision = $esExonerado ? [] : ($omisiones[(int) $compId] ?? []);
                    ?>
                    <div class="bd-competencia" data-logro="<?= $lf ?>">

                        <!-- Encabezado: nombre + logro anual -->
                        <div class="bd-competencia__header">
                            <h3 class="bd-competencia__nombre">
                                <?php if (!empty($comp['subarea_nombre'])): ?>
                                    <span class="bd-competencia__subarea"><?= e($comp['subarea_nombre']) ?></span>
                                <?php endif; ?>
                                <?= e(!empty($comp['subarea_nombre']) ? ($comp['competencia_texto'] ?? $comp['nombre_largo'] ?? $comp['nombre']) : ($comp['nombre_largo'] ?? $comp['nombre'])) ?>
                            </h3>
                            <?php if ($esExonerado): ?>
                            <div class="bd-logro bd-logro--exo"
                                 aria-label="Exonerado(a)">
                                <span class="bd-logro__label">Logro</span>
                                <span class="bd-logro__value">EXO</span>
                            </div>
                            <?php elseif ($literalFinal): ?>
                            <div class="bd-logro bd-logro--<?= $lf ?>"
                                 aria-label="Logro anual: <?= e($literalFinal) ?>">
                                <span class="bd-logro__label">Anual</span>
                                <span class="bd-logro__value"><?= e($literalFinal) ?></span>
                            </div>
                            <?php else: ?>
                            <div class="bd-logro bd-logro--vacio" aria-label="Logro anual pendiente">
                                <span class="bd-logro__label">Anual</span>
                                <span class="bd-logro__value">—</span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($esExonerado): ?>
                        <div class="bd-exo">
                            <span class="bd-exo__icono" aria-hidden="true">✓</span>
                            <span class="bd-exo__texto">
                                Alumno(a) exonerado(a) de esta área — no requiere evaluación.
                            </span>
                        </div>
                        <?php elseif (!$literalFinal && !empty($motivosOmision)): ?>
                        <div class="bd-omision">
                            <span class="bd-omision__icono" aria-hidden="true">ℹ</span>
                            <span class="bd-omision__texto">
                                No evaluado —
                                <?= e(implode(' / ', array_map(
                                    [\App\Models\OmisionCriterioModel::class, 'etiqueta'],
                                    $motivosOmision
                                ))) ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <!-- Chips de bimestre -->
                        <div class="bd-competencia__bimestres" role="list" aria-label="Notas por bimestre">
                            <?php foreach ($periodos as $p):
                                $num = (int) $p['numero'];
                                $rom = $romanos[$num - 1] ?? $num;
                                $b   = $comp['bimestres'][$p['id']] ?? null;
                                $lit = $b['literal'] ?? null;
                                $llc = $lit ? strtolower($lit) : 'vacio';
                            ?>
                            <div class="bd-bimestre bd-bimestre--<?= $llc ?>"
                                 role="listitem"
                                 aria-label="<?= $rom ?> Bimestre: <?= $lit ?? 'sin nota' ?>">
                                <span class="bd-bimestre__periodo"><?= $rom ?></span>
                                <?php if ($esSecundaria && $b && $b['nota'] !== null): ?>
                                <span class="bd-bimestre__nota">
                                    <?= str_pad((int) $b['nota'], 2, '0', STR_PAD_LEFT) ?>
                                </span>
                                <?php endif; ?>
                                <span class="bd-bimestre__literal <?= !$lit ? 'bd-bimestre__literal--empty' : '' ?>">
                                    <?= $lit ? e($lit) : '—' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Conclusiones descriptivas completas (sin truncar) -->
                        <?php
                        $hayConclusion = false;
                        foreach ($periodos as $p) {
                            $b = $comp['bimestres'][$p['id']] ?? null;
                            if ($b && !empty($b['conclusion'])) {
                                $hayConclusion = true;
                                break;
                            }
                        }
                        ?>
                        <?php if ($hayConclusion): ?>
                        <div class="bd-competencia__conclusiones"
                             aria-label="Conclusiones descriptivas">
                            <?php foreach ($periodos as $p):
                                $num = (int) $p['numero'];
                                $rom = $romanos[$num - 1] ?? $num;
                                $b   = $comp['bimestres'][$p['id']] ?? null;
                                if (empty($b['conclusion'])) continue;
                            ?>
                            <div class="bd-conclusion">
                                <span class="bd-conclusion__bimestre"><?= $rom ?> Bimestre</span>
                                <p class="bd-conclusion__texto"><?= e($b['conclusion']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endforeach; ?>

                </div><!-- /.bd-area__body -->
            </article>
            <?php endforeach; ?>
        </section>

        <?php endif; ?>

        <?php if (!empty($conducta)): ?>
        <section class="bd-conducta" aria-label="Conducta">
            <h2 class="bd-conducta__titulo">Conducta</h2>
            <div class="bd-conducta__fila">
                <?php foreach ($periodos as $p):
                    $clit = $conducta[$p['id']] ?? null;
                ?>
                    <div class="bd-conducta__celda <?= $clit ? 'bd-conducta__celda--' . strtolower($clit) : 'bd-conducta__celda--vacia' ?>">
                        <span class="bd-conducta__bimestre"><?= e($p['nombre_display']) ?></span>
                        <span class="bd-conducta__literal">
                            <?= $clit ? e($clit) : '—' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($asistencia['bimestres'])):
            $asisTotal = $asistencia['total'];
            $camposAsis = [
                'faltas'                 => 'Faltas',
                'faltas_justificadas'    => 'Faltas justificadas',
                'tardanzas'              => 'Tardanzas',
                'tardanzas_justificadas' => 'Tardanzas justificadas',
            ];
        ?>
        <section class="bd-asistencia" aria-label="Asistencia">
            <h2 class="bd-asistencia__titulo">Asistencia</h2>
            <div class="bd-asistencia__scroll">
            <table class="bd-asistencia__tabla">
                <thead>
                    <tr>
                        <th class="bd-asistencia__th-tipo">Tipo</th>
                        <?php foreach ($asistencia['bimestres'] as $bim): ?>
                            <th class="bd-asistencia__th-num"><?= ($romanos[$bim['numero'] - 1] ?? $bim['numero']) ?> Bim.</th>
                        <?php endforeach; ?>
                        <th class="bd-asistencia__th-num bd-asistencia__th--total">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($camposAsis as $clave => $etiqueta): ?>
                    <tr>
                        <td><?= $etiqueta ?></td>
                        <?php foreach ($asistencia['bimestres'] as $bim):
                            // Sin dato aun (bimestre futuro o que no corresponde a este
                            // umbral): guion apagado. Un 0 se leeria como "no falto
                            // ningun dia", que seria un dato inventado.
                            $sinRegistro = !empty($bim['sin_registro']);
                        ?>
                            <td class="bd-asistencia__num<?= $sinRegistro ? ' bd-asistencia__num--pendiente' : '' ?>">
                                <?= $sinRegistro ? '&ndash;' : (int) $bim['datos'][$clave] ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="bd-asistencia__num bd-asistencia__num--total"><?= (int) $asisTotal[$clave] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php
            // FECHAS de las incidencias (17/10/2026): por cada bimestre mostrado
            // QUE SE REGISTRÓ POR FECHAS (desde el III de 2026), cuándo ocurrió cada
            // una, agrupadas por el total en que CUENTAN (así cuadran con la tabla).
            // Los registrados solo por totales (I y II, vía extraordinaria) no se
            // listan: no tienen fechas que mostrar (decisión del usuario).
            $bimsConFechas = array_values(array_filter(
                $asistencia['bimestres'],
                static fn(array $b): bool => empty($b['sin_registro']) && $b['fechas'] !== null
            ));
            // Color de cada ficha = el de su tipo en la grilla de asistencia.
            $claseTipoFecha = [
                'faltas'                 => 'f',
                'faltas_justificadas'    => 'fj',
                'tardanzas'              => 't',
                'tardanzas_justificadas' => 'tj',
            ];
            ?>
            <?php if ($bimsConFechas !== []): ?>
            <div class="bd-asis-fechas">
                <h3 class="bd-asis-fechas__titulo">Fechas de inasistencias y tardanzas</h3>
                <?php foreach ($bimsConFechas as $bim): ?>
                    <div class="bd-asis-fechas__bimestre">
                        <span class="bd-asis-fechas__bim"><?= ($romanos[$bim['numero'] - 1] ?? $bim['numero']) ?> Bimestre</span>
                        <?php if (!array_filter($bim['fechas'])): ?>
                            <p class="bd-asis-fechas__nota">Sin inasistencias ni tardanzas.</p>
                        <?php else: ?>
                            <dl class="bd-asis-fechas__lista">
                                <?php foreach ($camposAsis as $clave => $etiqueta):
                                    if (empty($bim['fechas'][$clave])) { continue; }
                                    $tramos     = $bim['fechas'][$clave];
                                    $claseFecha = 'bd-asis-fechas__fecha bd-asis-fechas__fecha--' . $claseTipoFecha[$clave];
                                ?>
                                    <dt class="bd-asis-fechas__tipo"><?= e($etiqueta) ?> (<?= (int) $bim['datos'][$clave] ?>)</dt>
                                    <dd class="bd-asis-fechas__fechas">
                                        <?php require VIEW_PATH . '/boleta/_fechas-asistencia.php'; ?>
                                    </dd>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>


    <!-- ── FOOTER — FIRMAS ──────────────────────────────────── -->
    <footer class="bd-footer">

        <?php
        $bdCargoDirector = match($directorEbr['sexo'] ?? null) {
            'F'     => 'Directora E.B.R.',
            'M'     => 'Director E.B.R.',
            default => 'Director(a) E.B.R.',
        };
        ?>
        <div class="bd-footer__sig">
            <!-- El SELLO solo va en el documento definitivo. En vista previa o BORRADOR
                 se omite: un sello de direccion le da apariencia oficial a un documento
                 provisional, que es justo lo que la marca de agua intenta desmentir.
                 Mismo criterio que la firma de la boleta impresa (boleta/alumno.php) y
                 que el QR de aqui abajo. El contenedor se conserva vacio para que el
                 pie no cambie de alto entre la vista previa y el definitivo. -->
            <div class="bd-footer__img-area">
                <?php if (!$vistaPrevia && !empty($directorEbr['sello_path'])): ?>
                    <img src="<?= url($directorEbr['sello_path']) ?>"
                         alt="Sello <?= $bdCargoDirector ?>"
                         class="bd-footer__sello-img">
                <?php endif; ?>
            </div>
            <div class="bd-footer__line" role="presentation"></div>
            <p class="bd-footer__cargo"><?= $bdCargoDirector ?></p>
        </div>

    </footer>

    <?php if (!empty($url_boleta) && !$vistaPrevia): ?>
    <!-- ── QR de verificación (solo impresión / PDF) ────────── -->
    <section class="bd-qr" aria-hidden="true">
        <h2 class="bd-qr__titulo">Código QR</h2>
        <div class="bd-qr__contenido">
            <div class="bd-qr__code" id="bd-qr-code" data-url="<?= e($url_boleta) ?>"></div>
            <p class="bd-qr__label">Escanea el código QR para ver la boleta digital actualizada en línea, si tienes algún inconveniente comunícate con el área de secretaría.</p>
        </div>
    </section>
    <?php endif; ?>

</article>
