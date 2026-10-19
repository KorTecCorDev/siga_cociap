<?php
/**
 * Vista: Reporte imprimible de Orden de Mérito — A4 vertical (portrait)
 *
 * Estructura por grado — cada hoja es un documento autónomo y firmable por sí solo:
 *   1 hoja  → Orden de mérito del grado (firman Director EBR + todos sus tutores)
 *   N hojas → Ranking por sección, UNA HOJA POR SECCIÓN
 *             (firman Director EBR + el tutor de esa sección)
 *
 * Las hojas van agrupadas por grado: mérito del grado y a continuación sus secciones.
 *
 * Desde el 19/10/2026 se imprime LO FILTRADO en el gestor: el controlador ya
 * trae solo los grados tocados y, en `por_seccion`, solo las secciones
 * elegidas; `$hojasGrado` / `$hojasSeccion` dicen qué hojas salen. La cabecera
 * es la del Acompañamiento pedagógico (`_cabecera-merito.php`); la estructura
 * firmable (MODELO OFICIAL, docs/modulos/orden-merito.md §373) no cambia.
 *
 * LA FIRMA NUNCA EMPIEZA UNA HOJA (regla del usuario, docs/modulos/ui.md §
 * «Firma y sello»; Fase 2 aplicada aquí el 19/10/2026): las firmas van pegadas
 * a la tabla y las ÚLTIMAS `$filasCierre` filas viajan con ellas en
 * `.merito-cierre`, un bloque indivisible. Si no caben, pasan juntas a la hoja
 * siguiente. Por eso cada tabla se pinta en dos trozos: el cuerpo y la cola.
 *
 * @var array      $periodo       { id, anio, nombre_display, numero, estado }
 * @var array      $ranking       [grado_id => { grado, conteos, general[], por_seccion{sec => []}, tutores }]
 * @var bool       $hojasGrado    imprimir la hoja de mérito de cada grado
 * @var bool       $hojasSeccion  imprimir una hoja por cada sección
 * @var string     $institucion
 * @var array|null $directorEbr   { nombre_completo, sexo, firma_path }
 * @var bool       $hayPendientes
 */
$hojasGrado   = $hojasGrado   ?? true;
$hojasSeccion = $hojasSeccion ?? true;
$impreso      = (new DateTime())->format('d/m/Y H:i');

// Filas que acompañan a las firmas en el bloque indivisible del cierre.
$filasCierre = 3;

$cargoDirector = match($directorEbr['sexo'] ?? null) {
    'F'     => 'Directora E.B.R.',
    'M'     => 'Director E.B.R.',
    default => 'Director(a) E.B.R.',
};

// Bloque de firma del Director EBR: idéntico en todas las hojas del documento.
$firmaDirector = [
    'nombre'     => $directorEbr['nombre_completo'] ?? null,
    'cargo'      => $cargoDirector,
    'firma_path' => $directorEbr['firma_path'] ?? null,
];

$cargoTutor = static fn(?array $tutor): string => match($tutor['sexo'] ?? null) {
    'M'     => 'Tutor de Aula',
    'F'     => 'Tutora de Aula',
    default => 'Tutor(a) de Aula',
};

/**
 * Pinta una tabla de mérito (o un trozo de ella).
 *   $conSeccion  hoja del GRADO: columna Secc. y distintivos de puesto.
 *   $conThead    solo el primer trozo lleva encabezado (se repite al partir hoja).
 *   $mod         clases extra: `--continua` (sigue en el cierre) y
 *                `--paridad-impar` (la cola arranca en fila par: la cebra sigue).
 * Las columnas se fijan con <colgroup> para que los dos trozos midan igual.
 */
$tabla = static function (array $filas, bool $conSeccion, bool $conThead, string $mod = ''): void {
    ?>
    <table class="tabla-merito <?= $mod ?>">
        <colgroup>
            <col class="tm-puesto">
            <col>
            <?php if ($conSeccion): ?><col class="tm-seccion"><?php endif; ?>
            <col class="tm-comp">
            <col class="tm-total">
            <col class="tm-promedio">
        </colgroup>
        <?php if ($conThead): ?>
            <thead>
                <tr>
                    <th class="tm-puesto">Puesto</th>
                    <th class="tm-nombre">Apellidos y Nombres</th>
                    <?php if ($conSeccion): ?><th class="tm-seccion">Secc.</th><?php endif; ?>
                    <th class="tm-comp">Comp.</th>
                    <th class="tm-total">Total</th>
                    <th class="tm-promedio">Promedio</th>
                </tr>
            </thead>
        <?php endif; ?>
        <tbody>
            <?php foreach ($filas as $est): ?>
                <?php $pos = $est['puesto']; ?>
                <tr class="<?= $pos <= 3 ? 'fila-merito--' . $pos : '' ?>">
                    <td>
                        <span class="medalla medalla--<?= $pos <= 3 ? $pos : 'n' ?>">
                            <?= $pos ?>°
                        </span>
                    </td>
                    <td class="tm-nombre">
                        <?= e($est['apellido_paterno'] . ' ' . $est['apellido_materno'] . ', ' . $est['nombres']) ?>
                        <?php if ($conSeccion): ?>
                            <?php if ($est['media_beca']): ?>
                                <span class="distincion-beca">Media Beca &mdash; 1° Puesto del Grado</span>
                            <?php elseif ($pos === 2): ?>
                                <span class="distincion-grado distincion-grado--2">2° Puesto del Grado</span>
                            <?php elseif ($pos === 3): ?>
                                <span class="distincion-grado distincion-grado--3">3° Puesto del Grado</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($conSeccion): ?><td><?= e($est['seccion_nombre']) ?></td><?php endif; ?>
                    <td class="tm-comp"><?= (int) $est['num_competencias'] ?></td>
                    <td class="tm-total"><?= (int) $est['total_notas'] ?></td>
                    <td>
                        <span class="promedio-val"><?= number_format((float) $est['promedio_general'], 2) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};

/** Bloque de firmas de una hoja. */
$firmas = static function (array $lista): void {
    ?>
    <footer class="reporte-footer">
        <?php foreach ($lista as $firma): ?>
            <div class="reporte-footer__bloque">
                <div class="reporte-footer__espacio-firma">
                    <?php if (!empty($firma['firma_path'])): ?>
                        <img src="<?= url($firma['firma_path']) ?>"
                             alt=""
                             aria-hidden="true"
                             class="reporte-footer__firma-img">
                    <?php endif; ?>
                </div>
                <div class="reporte-footer__linea"></div>
                <?php if (!empty($firma['nombre'])): ?>
                    <div class="reporte-footer__nombre"><?= e($firma['nombre']) ?></div>
                <?php endif; ?>
                <div class="reporte-footer__cargo"><?= e($firma['cargo']) ?></div>
            </div>
        <?php endforeach; ?>
    </footer>
    <?php
};

/**
 * Tabla completa + firmas: el cuerpo suelto y la cola (últimas $filasCierre
 * filas) en el bloque indivisible junto a las firmas.
 */
$tablaConCierre = static function (array $filas, bool $conSeccion, array $listaFirmas) use ($tabla, $firmas, $filasCierre): void {
    $cuerpo = array_slice($filas, 0, max(0, count($filas) - $filasCierre));
    $cola   = array_slice($filas, count($cuerpo));
    if ($cuerpo !== []) {
        $tabla($cuerpo, $conSeccion, true, 'tabla-merito--continua');
    }
    ?>
    <div class="merito-cierre">
        <?php if ($cola !== []) {
            $tabla($cola, $conSeccion, $cuerpo === [], count($cuerpo) % 2 === 1 ? 'tabla-merito--paridad-impar' : '');
        } ?>
        <?php $firmas($listaFirmas); ?>
    </div>
    <?php
};

// El salto va ANTES de cada hoja menos la primera: un salto al final del
// documento imprime una hoja en blanco de más.
$primeraHoja = true;
?>

<?php if (!empty($hayPendientes)): ?>
    <div class="reporte-aviso-pendiente">
        ⚠ Documento NO oficializable: existen empates sin resolver. Los puestos en disputa
        son provisionales hasta que la Dirección, Registro Académico o Administración los resuelvan.
    </div>
<?php endif; ?>

<?php foreach ($ranking as $data): ?>
    <?php
    $grado          = $data['grado'];
    $conteos        = $data['conteos'];
    $tutores        = $data['tutores'];
    $codModular     = ($grado['nivel_codigo'] ?? '') === 'sec' ? '1310044 - 0' : '1719525 - 0';
    $totalGeneral   = count($data['general']);
    $infoConteos    = $conteos['num_areas'] . ' área' . ($conteos['num_areas'] !== 1 ? 's' : '') . ' a promediar';
    // «4° — Secundaria — II Bimestre 2026»: la parte común del título de cada hoja.
    $tituloGrado    = ($grado['nombre_display'] ?? '') . ' — ' . ($grado['nivel_nombre'] ?? '')
                    . ' — ' . ($periodo['nombre_display'] ?? '') . ' ' . ($periodo['anio'] ?? '');

    // Hoja del grado: firma el Director EBR y todos los tutores del grado.
    $firmasGrado = [$firmaDirector];
    foreach ($tutores as $secNombre => $tutor) {
        $firmasGrado[] = [
            'nombre' => $tutor['nombre'] ?? null,
            'cargo'  => $cargoTutor($tutor) . ' — Secc. ' . $secNombre,
        ];
    }
    ?>

    <!-- ══════════════════════════════════════════════════════════
         HOJA: Orden de Mérito del grado
    ═══════════════════════════════════════════════════════════ -->
    <?php if ($hojasGrado): ?>
    <?php if (!$primeraHoja): ?><div class="boleta-salto-pagina"></div><?php endif; $primeraHoja = false; ?>
    <div class="reporte-pagina merito-doc">

        <?php
        $tituloHoja = 'Orden de mérito — ' . $tituloGrado;
        $metaHoja   = $infoConteos . ' · ' . $totalGeneral . ' estudiante' . ($totalGeneral !== 1 ? 's' : '');
        require VIEW_PATH . '/director/_cabecera-merito.php';
        ?>

        <?php if (empty($data['general'])): ?>
            <p class="reporte-vacio">Sin calificaciones registradas en este grado para el periodo seleccionado.</p>
        <?php endif; ?>

        <?php $tablaConCierre($data['general'], true, $firmasGrado); ?>

    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         HOJAS: Ranking por sección — UNA HOJA POR SECCIÓN
    ═══════════════════════════════════════════════════════════ -->
    <?php foreach ($hojasSeccion ? $data['por_seccion'] : [] as $secNombre => $estudiantes): ?>
        <?php
        $tutor        = $tutores[$secNombre] ?? null;
        $totalSeccion = count($estudiantes);
        $firmasSeccion = [
            $firmaDirector,
            [
                'nombre' => $tutor['nombre'] ?? null,
                'cargo'  => $cargoTutor($tutor) . ' — Secc. ' . $secNombre,
            ],
        ];
        ?>

        <?php if (!$primeraHoja): ?><div class="boleta-salto-pagina"></div><?php endif; $primeraHoja = false; ?>
        <?php // `--seccion`: 18-27 filas, así que su tabla lleva letra más grande
              // que la del grado (decisión del usuario, 19/10/2026). ?>
        <div class="reporte-pagina merito-doc merito-doc--seccion">

            <?php
            $tituloHoja = 'Ranking — Sección ' . $secNombre . ' — ' . $tituloGrado;
            $metaHoja   = $infoConteos . ' · ' . $totalSeccion . ' estudiante' . ($totalSeccion !== 1 ? 's' : '');
            require VIEW_PATH . '/director/_cabecera-merito.php';
            ?>

            <?php $tablaConCierre($estudiantes, false, $firmasSeccion); ?>

        </div>
    <?php endforeach; ?>

<?php endforeach; ?>
