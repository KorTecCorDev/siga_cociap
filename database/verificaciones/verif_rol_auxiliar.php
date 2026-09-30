<?php

/**
 * Verificación del rol AUXILIAR ACADÉMICO y de sus asignaciones por bimestre
 * (migraciones 065 y 066, 28/09/2026). Ver docs/modulos/auxiliares.md.
 *
 * Todo lo que escribe va dentro de UNA transacción que se revierte al final:
 * crea dos auxiliares de prueba y asignaciones, y no deja rastro. Nunca limpia
 * con DELETE lo que no creó.
 *
 * Uso: php database/verificaciones/verif_rol_auxiliar.php
 */

define('ROOT_PATH', dirname(__DIR__, 2));
define('CONFIG_PATH', ROOT_PATH . '/config');

require ROOT_PATH . '/app/Helpers/helpers.php';

spl_autoload_register(function (string $c): void {
    foreach (['Core\\' => '/core/', 'App\\Models\\' => '/app/Models/', 'App\\Siagie\\' => '/app/Siagie/'] as $pre => $base) {
        if (str_starts_with($c, $pre)) {
            $f = ROOT_PATH . $base . str_replace('\\', '/', substr($c, strlen($pre))) . '.php';
            if (is_file($f)) { require $f; }
        }
    }
});

date_default_timezone_set(config('timezone'));

$ok  = true;
$chk = function (string $t, bool $c) use (&$ok) {
    printf("  [%s] %s\n", $c ? 'OK ' : 'FAIL', $t);
    $ok = $ok && $c;
};

$m = new App\Models\AuxiliarSeccionModel();

// ── 1) Catálogo y constante ──────────────────────────────────────
echo "1) El rol existe y la constante apunta a él\n";
$rol = $m->queryOne("SELECT id, nombre, codigo, descripcion FROM roles WHERE codigo = ?", [ROL_AUXILIAR]);
$chk("existe el rol '" . ROL_AUXILIAR . "'", $rol !== null);
$chk("nombre = 'Auxiliar académico' (con tilde)", ($rol['nombre'] ?? '') === 'Auxiliar académico');
$dup = $m->queryOne("SELECT COUNT(*) AS n FROM roles GROUP BY codigo HAVING COUNT(*) > 1 LIMIT 1");
$chk('ningún código de rol duplicado', $dup === null);
$chk('existe la tabla auxiliar_secciones',
    $m->queryOne("SHOW TABLES LIKE 'auxiliar_secciones'") !== null);

// ── 2) Superficies que no pueden leer la constante ───────────────
echo "2) Aterrizaje, dashboard, rutas y CSS\n";
$src = fn(string $f): string => (string) file_get_contents(ROOT_PATH . '/' . $f);
// Cuerpo de un método (de `function x(` a su llave de cierre con 4 espacios).
$cuerpoDe = function (string $src, string $metodo): string {
    if (!preg_match('/function ' . $metodo . '\(.*?\n    \}\n/s', str_replace("\r\n", "\n", $src), $mm)) {
        return '';
    }
    return $mm[0];
};
$chk('AuthController::redirigirPorRol tiene destino para ROL_AUXILIAR',
    str_contains($src('app/Controllers/Auth/AuthController.php'), "ROL_AUXILIAR        => url('auxiliar/inicio')"));
$chk('DashboardController no lo deja caer a /login (bucle)',
    str_contains($src('app/Controllers/DashboardController.php'), "ROL_AUXILIAR => url('auxiliar/inicio')"));
$chk('rutas GET /admin/auxiliares y POST .../asignar registradas',
    str_contains($src('routes/web.php'), "'/admin/auxiliares',")
    && str_contains($src('routes/web.php'), "'/admin/auxiliares/{seccion_id}/asignar'"));
$chk('el CSS compilado trae el color del avatar del auxiliar',
    str_contains($src('public/css/app.css'), '.usuario-avatar--auxiliar_academico'));

// F3 — el aterrizaje EXISTE. Sin esta ruta, el auxiliar entraba a un 404 sin
// salida al iniciar sesión (así estuvo entre la F1 y la F3).
$chk('ruta GET /auxiliar/inicio registrada (aterrizaje del rol)',
    str_contains($src('routes/web.php'), "'/auxiliar/inicio',") && str_contains($src('routes/web.php'), "'Auxiliar\\PanelController@inicio'"));
$chk('el panel del auxiliar es SOLO para el auxiliar',
    str_contains($src('app/Controllers/Auxiliar/PanelController.php'), '$this->requireRole([ROL_AUXILIAR]);'));
// Wayfinding: Asistencia tiene color propio y ya no toma prestado el de Nómina
// (en el panel del auxiliar las dos cards son vecinas).
$css = $src('public/css/app.css');
$chk('Asistencia tiene color propio en el panel y en /director/bloqueos (no el naranja de Nómina)',
    str_contains($css, '.dpanel-card--asistencia') && str_contains($css, 'bloqueos-tabcard--asistencia{border-left-color:#4d7c0f}'));
$chk('el icono de la card Asistencia existe en disco',
    is_file(ROOT_PATH . '/public/assets/icons/calendar-add.svg'));
// F4a — nómina y horario de SUS secciones. Cada documento pasa por la guarda de
// sección (sin ella, cualquier auxiliar imprimiría la nómina —con celulares de
// apoderados— de todo el colegio cambiando el id en la URL).
$panel = $src('app/Controllers/Auxiliar/PanelController.php');
foreach (['nominaImprimir', 'horario'] as $met) {
    $chk("Auxiliar\\PanelController::{$met} exige que la sección sea suya",
        str_contains($cuerpoDe($panel, $met), '$this->exigirSeccionPropia('));
}
$chk('rutas de nómina y horario del auxiliar registradas',
    str_contains($src('routes/web.php'), "'/auxiliar/nomina/{seccion_id}/imprimir'")
    && str_contains($src('routes/web.php'), "'/auxiliar/horario/{seccion_id}'"));
// Reuso sin copia: la nómina y el horario salen de UN modelo cada uno, que usan
// también el docente y Dirección. Si un controlador vuelve a escribir su SQL, las
// dos nóminas (o los dos horarios) divergirán.
$chk('el panel docente delega la nómina en NominaModel (sin SQL propio)',
    !str_contains($cuerpoDe($src('app/Controllers/Docente/PanelController.php'), 'getMatriculados'), 'FROM matriculas'));
$chk('Dirección arma el horario de sección con HorarioModel::documentoSeccion',
    str_contains($src('app/Controllers/Director/CargaAcademicaController.php'), '->documentoSeccion('));
$chk('el panel del auxiliar no identifica niveles por id escrito a mano',
    !preg_match('/resumenPorSeccion\(\s*\[\s*\d/', $panel));

// F4b — nómina de docentes. Un solo método para todos los roles: el auxiliar
// entra, pero acotado a SUS secciones y sin DNI (decisiones del 28/09/2026).
$docs = $src('app/Controllers/Documentos/DocumentoController.php');
$nd   = $cuerpoDe($docs, 'nominaDocentes');
$chk('ruta GET /documentos/nomina-docentes registrada',
    str_contains($src('routes/web.php'), "'/documentos/nomina-docentes',"));
$chk('nominaDocentes guarda el rol en el método (admin, RA, Dirección y auxiliar)',
    str_contains($nd, '$this->requireRole([...self::ROLES_COLEGIO, ROL_AUXILIAR]);')
    && str_contains($docs, "ROLES_COLEGIO = ['admin', 'registro_academico', ...ROLES_DIRECCION]"));
$chk('nominaDocentes acota al auxiliar a sus secciones (403 si no tiene ninguna)',
    str_contains($nd, '$this->seccionesDelAuxiliar()') && str_contains($nd, '$this->forbidden()')
    && str_contains($cuerpoDe($docs, 'seccionesDelAuxiliar'), '->seccionesDe('));
$chk('el DNI no sale para el auxiliar', str_contains($nd, "'conDni'    => !\$esAuxiliar"));
$vistaNd = $src('resources/views/documentos/nomina-docentes.php');
$lineasDni = preg_grep('/\[\'dni\'\]|>DNI</', explode("\n", $vistaNd));
$chk('la vista pinta la columna DNI solo con $conDni (cabecera y celda)',
    count($lineasDni) === 2
    && count(array_filter($lineasDni, fn($l) => str_contains($l, '<?php if ($conDni): ?>'))) === 2);
$chk('card «Nómina de docentes» en el panel del auxiliar y en el dashboard',
    str_contains($src('resources/views/auxiliar/inicio.php'), "url('documentos/nomina-docentes')")
    && str_contains($src('resources/views/dashboard/index.php'), "'url' => 'documentos/nomina-docentes'"));
$chk('el CSS compilado trae la card y el filtro de la nómina de docentes',
    str_contains($css, '.dpanel-card--docentes') && str_contains($css, '.nomina-docentes__filtro'));
$chk('el icono de la nómina de docentes existe en disco',
    is_file(ROOT_PATH . '/public/assets/icons/folder-2.svg'));

// KPI «días para el cierre»: los umbrales viven SOLO en el parcial compartido.
// Una vista que vuelva a calcular `$diasMod` es una copia que divergirá.
foreach (['docente/inicio.php', 'auxiliar/inicio.php'] as $vista) {
    $v = $src('resources/views/' . $vista);
    $chk("{$vista} usa el parcial del KPI «días para el cierre», sin copia de umbrales",
        str_contains($v, "/shared/_kpi-dias-cierre.php'") && !str_contains($v, '$diasMod ='));
}

// ── 2b) Cada método que toca una sección pasa por puedeRegistrar ─
// Guarda estructural: el rol del auxiliar está en la lista de los que operan,
// así que un método SIN esta llamada le abriría TODAS las secciones.
echo "2b) Guardas por sección en los controladores de registro\n";
foreach (['Asistencia', 'Conducta'] as $mod) {
    $s = $src("app/Controllers/Admin/{$mod}Controller.php");
    // Conducta (29/09/2026): sus dos escrituras (`guardar` = borrador y
    // `confirmar`) pasan por UN guardián común, `escrituraValidada`; la guarda
    // vive ahí y cada escritura tiene que llamarlo.
    $escrituras = $mod === 'Conducta' ? ['guardar', 'confirmar'] : ['guardar'];
    foreach (['seccion', 'guardar', 'bloquear', 'imprimir', 'estudiante'] as $met) {
        $cuerpo = $cuerpoDe($s, $met);
        if ($mod === 'Conducta' && in_array($met, $escrituras, true)) {
            $cuerpo .= $cuerpoDe($s, 'escrituraValidada');
        }
        $chk("{$mod}Controller::{$met} verifica la sección del auxiliar",
            str_contains($cuerpo, '$this->puede('));
    }
    if ($mod === 'Conducta') {
        foreach ($escrituras as $met) {
            $chk("ConductaController::{$met} pasa por el guardián común escrituraValidada()",
                str_contains($cuerpoDe($s, $met), '$this->escrituraValidada()'));
        }
    }
    $chk("{$mod}Controller::index filtra las secciones del auxiliar",
        str_contains($cuerpoDe($s, 'index'), 'seccionesDe('));
    // La entrada por estudiante (F2b) es solo para registrar: con la sección
    // bloqueada devuelve a la grilla en vez de pintar controles que el
    // servidor rechazaría.
    $chk("{$mod}Controller::estudiante devuelve a la grilla si la sección está bloqueada",
        str_contains($cuerpoDe($s, 'estudiante'), 'getCierreVigente('));
}
// Reuso sin copia: la pantalla por estudiante guarda con el guardarFila() de
// conducta.js / asistencia.js. Si registro-estudiante.js hiciera su propio
// fetch, habría dos puntos de escritura en el cliente que divergirían.
$js = $src('resources/js/registro-estudiante.js');
$chk('registro-estudiante.js escribe con las funciones compartidas (confirmarFila / confirmarAsistencia / guardarFila), sin fetch propio',
    str_contains($js, 'confirmarFila(') && str_contains($js, 'confirmarAsistencia(')
    && str_contains($js, 'guardarFila(') && !str_contains($js, 'fetch('));
$chk('asistencia.js::guardarFila informa si guardó (lo usa «Guardar y siguiente»)',
    substr_count($src('resources/js/asistencia.js'), 'return true;') >= 1
    && substr_count($src('resources/js/asistencia.js'), 'return false;') >= 2);
$chk('las escrituras de conducta exigen el roster (en su guardián común)',
    str_contains($cuerpoDe($src('app/Controllers/Admin/ConductaController.php'), 'escrituraValidada'), 'matriculaEnRoster('));

// ── 2c) Nómina de docentes: datos (solo lectura) ─────────────────
echo "2c) Nómina de docentes: alcance del auxiliar y qué cuenta como carga\n";
$nomDoc = new App\Models\NominaDocenteModel();
$todo   = $nomDoc->documento();
$porNombre = function (array $doc): array {
    $out = [];
    foreach ($doc as $nid => $nivel) {
        foreach ($nivel['docentes'] as $d) { $out[$nid . '|' . $d['nombre']] = $d; }
    }
    return $out;
};
$todos = $porNombre($todo);
$chk('el documento del colegio trae docentes', count($todos) > 0);
$lineas = [];
foreach ($todos as $d) { foreach ($d['cargas'] as $c) { $lineas[] = implode('; ', $c['areas']); } }
$chk('ninguna carga de Tutoría (TOE) ni transversal se lista como área',
    !preg_grep('/Tutor[íi]a \(TOE\)|Transversal/i', $lineas));
// La carga TOE se omite porque la cubre la columna Tutoría: solo es cierto si
// cada carga TOE activa es del propio tutor de la sección.
$toe = $m->queryOne("SELECT COUNT(*) AS n FROM cargas_academicas ca
                     INNER JOIN areas a ON a.id = ca.area_id AND a.tipo = 'tutoria'
                     INNER JOIN secciones s ON s.id = ca.seccion_id
                     INNER JOIN anios_academicos an ON an.id = ca.anio_id AND an.estado = 'activo'
                     WHERE ca.estado = 'activa' AND NOT (ca.docente_id <=> s.tutor_id)");
$chk('toda carga TOE activa es del tutor de su sección (la columna Tutoría la cubre)', (int) $toe['n'] === 0);

$una = $m->queryOne("SELECT s.id FROM secciones s
                     INNER JOIN anios_academicos an ON an.id = s.anio_id AND an.estado = 'activo'
                     WHERE s.tutor_id IS NOT NULL ORDER BY s.id LIMIT 1");
if ($una) {
    $sid     = (int) $una['id'];
    $acotado = $porNombre($nomDoc->documento([$sid]));
    $enSec   = $m->query("SELECT DISTINCT docente_id AS id FROM cargas_academicas WHERE seccion_id = ? AND estado = 'activa'
                          UNION SELECT tutor_id FROM secciones WHERE id = ? AND tutor_id IS NOT NULL", [$sid, $sid]);
    $chk('auxiliar: solo docentes con carga o tutoría en su sección (subconjunto del colegio)',
        count($acotado) > 0 && count($acotado) < count($todos)
        && array_diff_key($acotado, $todos) === []);
    $iguales = true;
    foreach ($acotado as $k => $d) { $iguales = $iguales && $d === $todos[$k]; }
    $chk('auxiliar: cada docente sale con TODAS sus cargas (idéntico al del colegio)', $iguales);
    $chk('auxiliar: sin secciones propias no sale nadie', $nomDoc->documento([]) === []);
    $chk('auxiliar: los docentes acotados son a lo sumo los de la sección',
        count(array_unique(array_map(fn($k) => explode('|', $k, 2)[1], array_keys($acotado)))) <= count($enSec));
}

// ── 2d) Planilla de asistencia manual (F4c) ──────────────────────
echo "2d) Planilla de asistencia: solo admin/RA/Dirección, días y Excel\n";
$docs = $src('app/Controllers/Documentos/DocumentoController.php');
foreach (['planillaAsistencia', 'planillaGenerar'] as $met) {
    $cu = $cuerpoDe($docs, $met);
    // Decisión del 28/09/2026: el auxiliar NO tiene la planilla (que registre en
    // el sistema). Si alguien le suma ROL_AUXILIAR, esto lo caza.
    $chk("{$met} es SOLO para admin, RA y Dirección (sin el auxiliar)",
        str_contains($cu, '$this->requireRole(self::ROLES_COLEGIO);') && !str_contains($cu, 'ROL_AUXILIAR'));
}
$chk('rutas de la planilla registradas',
    str_contains($src('routes/web.php'), "'/documentos/planilla-asistencia',")
    && str_contains($src('routes/web.php'), "'/documentos/planilla-asistencia/generar',"));
$chk('el panel del auxiliar NO enlaza la planilla',
    !str_contains($src('resources/views/auxiliar/inicio.php'), 'planilla-asistencia'));
$chk('la planilla usa el roster de la grilla de asistencia',
    str_contains($cuerpoDe($docs, 'planillaGenerar'), '->getEstudiantesConIncidencias('));
$chk('el CSS compilado trae la planilla', str_contains($css, '.planilla-print__tabla'));

$P = App\Models\PlanillaAsistenciaModel::class;
$diasOk = true;
for ($an = 2026; $an <= 2040; $an++) {
    for ($me = 1; $me <= 12; $me++) {
        $ds  = $P::diasPlanilla($an, $me);
        $hab = 0;
        for ($d = 1; $d <= (int) date('t', mktime(0, 0, 0, $me, 1, $an)); $d++) {
            if ((int) date('N', mktime(0, 0, 0, $me, $d, $an)) <= 5) { $hab++; }
        }
        $idx = array_column($ds, 'indice');
        $diasOk = $diasOk && count($ds) === $hab && max(array_column($ds, 'semana')) <= 5
            && count(array_unique($idx)) === count($idx) && max($idx) <= 24 && $ds[0]['semana'] === 1;
    }
}
$chk('días 2026–2040: todos los hábiles, sin choques de columna, a lo sumo 5 semanas', $diasOk);
$oct = $P::diasPlanilla(2026, 10);   // 01/10/2026 es jueves
$chk('octubre 2026 empieza el jueves 1 en SEMANA 1 (columna G)',
    $oct[0]['dia'] === 1 && $oct[0]['abrev'] === 'J' && $P::COLUMNAS_DIA[$oct[0]['indice']] === 'G');
// Filas según la sección: hasta 3 libres mientras la fila no baje de 5 mm; nadie
// se queda fuera; alto con tope de 9 mm.
$chk('filas: 21 estudiantes → 24 filas; 25 → 27; 28 → 28 (sin libres, nadie fuera)',
    $P::filasGrilla(21) === 24 && $P::filasGrilla(25) === 27 && $P::filasGrilla(28) === 28);
$chk('alto de fila: llena la hoja con tope de 9 mm',
    $P::altoFilaMm(10) === $P::ALTO_MAX_MM && abs($P::altoFilaMm(27) - 136 / 27) < 0.001);
// Nombre que no cabe: primer nombre + inicial del segundo (decisión 28/09/2026).
$largo = 'SANTAMARIA RODRIGUEZ, JAKELINE ERLINDA MILAGROS';
$chk('nombre largo → primer nombre e inicial del segundo («JAKELINE E.»)',
    $P::nombreQueCabe($largo, $P::MAX_NOMBRE) === 'SANTAMARIA RODRIGUEZ, JAKELINE E.');
$chk('nombre que cabe, o con un solo nombre, no se toca',
    $P::nombreQueCabe('LOPEZ LUNA, GAEL EDIEL', $P::MAX_NOMBRE) === 'LOPEZ LUNA, GAEL EDIEL'
    && $P::nombreQueCabe('APELLIDOMUYLARGO OTROAPELLIDOLARGO, MARGARITA', 20)
        === 'APELLIDOMUYLARGO OTROAPELLIDOLARGO, MARGARITA');
$chk('las tildes y la ñ no se rompen al abreviar',
    $P::nombreQueCabe('NUÑEZ OSORIO, ÁNGEL ÉMILE', 10) === 'NUÑEZ OSORIO, ÁNGEL É.');
$chk('meses del periodo: de su mes de inicio al de fin',
    array_keys($P::mesesDelPeriodo(['fecha_inicio' => '2026-08-10', 'fecha_fin' => '2026-10-16']))
        === ['2026-08', '2026-09', '2026-10']);

$plan    = new App\Models\PlanillaAsistenciaModel();
$secPl   = $m->queryOne("SELECT s.id FROM secciones s
                         INNER JOIN anios_academicos an ON an.id = s.anio_id AND an.estado = 'activo'
                         WHERE s.estado_nomina = 'aprobada' AND s.tutor_id IS NOT NULL ORDER BY s.id LIMIT 1");
if (!class_exists('ZipArchive')) {
    // XAMPP trae `;extension=zip` comentado: sin ella no se puede abrir un xlsx
    // (tampoco las Actas SIAGIE). Se salta, no se da por bueno ni por malo.
    echo "  [SKIP] este PHP no tiene la extensión zip: no se puede probar el Excel\n";
} elseif ($secPl) {
    $sec     = $plan->seccion((int) $secPl['id']);
    $per     = ['nombre_display' => 'III Bimestre'];
    $aux     = ['nombre' => 'PRUEBA VERIF, Auxiliar'];
    $alumnos = ['PRIMERO PRUEBA, Uno', 'SEGUNDO PRUEBA, Dos', $largo];
    $md5     = md5_file($P::PLANTILLA);
    $leerXml = function (string $ruta): string {
        $z = new ZipArchive(); $z->open($ruta);
        $x = (string) $z->getFromName('xl/worksheets/sheet1.xml'); $z->close();
        return $x;
    };
    // Atributos del <row> de una fila (alto y si está oculta).
    $fila = fn(string $xml, int $n): string
        => preg_match('/<(?:\w+:)?row r="' . $n . '"([^>]*)>/', $xml, $mm) ? $mm[1] : '';

    // Modo «con fechas», con un año distinto al del pie de la plantilla.
    $sec2027 = array_merge($sec, ['anio' => 2027]);
    $ruta    = $plan->generarExcel($sec2027, $per, $aux, $alumnos, ['anio' => 2026, 'mes' => 10, 'nombre' => 'Octubre']);
    $x       = new App\Siagie\XlsxQuirurgico($ruta);
    $hoja    = $x->nombresDeHojas()[0];
    $c       = $x->leerCeldas($hoja);
    $xml     = $leerXml($ruta);
    $chk('Excel: título y nivel (L1 y L3)',
        ($c[1]['L'] ?? '') === 'REGISTRO AUXILIAR DE ASISTENCIA - 2027' && str_starts_with($c[3]['L'] ?? '', 'NIVEL '));
    $chk('Excel: franja de datos con sus rótulos (fila 5) y valores (fila 6)',
        ($c[5]['A'] ?? '') === 'GRADO Y SECCIÓN' && ($c[5]['K'] ?? '') === 'TUTOR(A)' && ($c[5]['Y'] ?? '') === 'AUXILIAR'
        && ($c[6]['A'] ?? '') === trim($sec['grado_nombre'] . ' ' . $sec['seccion_nombre'])
        && ($c[6]['C'] ?? '') === 'III Bimestre' && ($c[6]['D'] ?? '') === 'OCTUBRE'
        && ($c[6]['K'] ?? '') === $sec['tutor_nombre'] && ($c[6]['Y'] ?? '') === 'PRUEBA VERIF, Auxiliar');
    $chk('Excel: días de octubre (jueves 1 en G9/G10, sin días en D..F)',
        ($c[9]['G'] ?? '') === '01' && ($c[10]['G'] ?? '') === 'J' && !isset($c[9]['D']) && !isset($c[9]['F']));
    $chk('Excel: los días que no existen se sombrean solos (formato condicional sobre el MES)',
        str_contains($xml, '<conditionalFormatting sqref="D9:AB45">') && str_contains($xml, '($D$6&lt;&gt;"")*(D$9="")'));
    // Siglas DEL SISTEMA (30/09/2026; derogan la F/J/T/U del SIAGIE), escritas
    // desde LEYENDA, el mismo punto que usa el PDF.
    $chk('Excel: siglas del sistema F · FJ · T · TJ y leyenda bajo la grilla',
        ($c[8]['AC'] ?? '') === 'F' && ($c[8]['AD'] ?? '') === 'FJ' && ($c[8]['AE'] ?? '') === 'T'
        && ($c[8]['AF'] ?? '') === 'TJ' && str_contains($c[46]['A'] ?? '', 'FJ = Falta justificada')
        && str_contains($c[46]['A'] ?? '', 'TJ = Tardanza justificada') && !str_contains($c[46]['A'] ?? '', 'U = '));
    $chk('Excel y PDF leen la misma leyenda (LEYENDA = F/FJ/T/TJ)',
        array_keys($P::LEYENDA) === ['F', 'FJ', 'T', 'TJ']);
    $chk('Excel: estudiantes desde B11 en orden', ($c[11]['B'] ?? '') === $alumnos[0] && ($c[12]['B'] ?? '') === $alumnos[1]);
    $chk('Excel: el nombre que no cabe sale abreviado', ($c[13]['B'] ?? '') === 'SANTAMARIA RODRIGUEZ, JAKELINE E.');
    $filasG = $P::filasGrilla(count($alumnos));
    $chk("Excel: {$filasG} filas visibles con alto fijado; el resto ocultas",
        str_contains($fila($xml, 11), 'customHeight="1"') && !str_contains($fila($xml, 10 + $filasG), 'hidden')
        && str_contains($fila($xml, 11 + $filasG), 'hidden="1"') && str_contains($fila($xml, 45), 'hidden="1"'));
    $chk('Excel: el pie lleva el año del documento y la fecha de impresión (&D)',
        $x->pieContiene($hoja, 'CAVVG/HZ/2027') && !$x->pieContiene($hoja, 'CAVVG/HZ/2026')
        && $x->pieContiene($hoja, 'FECHA DE IMPRESIÓN: &D'));
    $chk('Excel: el pie de la plantilla trae el código a reemplazar',
        (new App\Siagie\XlsxQuirurgico($P::PLANTILLA))->pieContiene($hoja, 'CAVVG/HZ/2026'));
    @unlink($ruta);

    // Modo «en blanco»: sin días, sin MES, sin auxiliar.
    $ruta = $plan->generarExcel($sec, $per, null, $alumnos, null);
    $c    = (new App\Siagie\XlsxQuirurgico($ruta))->leerCeldas($hoja);
    $sinDias = true;
    foreach ($P::COLUMNAS_DIA as $col) { $sinDias = $sinDias && !isset($c[9][$col]) && !isset($c[10][$col]); }
    $chk('Excel en blanco: filas de día y MES vacíos, rótulos SEMANA intactos',
        $sinDias && !isset($c[6]['D']) && ($c[8]['D'] ?? '') === 'SEMANA 1');
    $chk('Excel en blanco: sin auxiliar, su casilla queda vacía', !isset($c[6]['Y']));
    @unlink($ruta);

    $excede = false;
    try { $plan->generarExcel($sec, $per, null, array_fill(0, $P::FILAS_EXCEL + 1, 'X'), null); }
    catch (RuntimeException $e) { $excede = true; }
    $chk('Excel: más de ' . $P::FILAS_EXCEL . ' estudiantes se rechaza (no trunca)', $excede);
    $chk('la plantilla quedó intacta', md5_file($P::PLANTILLA) === $md5
        && !is_file($P::PLANTILLA . '.tmp_siagie'));
    $chk('nombre del archivo: Registro_Asistencia_<grado><sección>_<Nivel>',
        $P::nombreArchivo(['grado_numero' => 1, 'seccion_nombre' => 'A', 'nivel_nombre' => 'Primaria'])
            === 'Registro_Asistencia_1A_Primaria');
}

// ── 3) Reglas de asignación (dentro de una transacción) ─────────
echo "3) Asignación por bimestre (transacción + rollback)\n";
$activo = $m->periodoActivo();
if ($activo === null) {
    echo "  [SKIP] no hay bimestre activo en esta base: no se pueden probar las asignaciones\n";
} else {
    $pActivo  = (int) $activo['id'];
    $anterior = $m->queryOne("SELECT id FROM periodos WHERE anio_id = ? AND numero < ? ORDER BY numero DESC LIMIT 1",
        [(int) $activo['anio_id'], (int) $activo['numero']]);
    $siguiente = $m->queryOne("SELECT id FROM periodos WHERE anio_id = ? AND numero > ? ORDER BY numero LIMIT 1",
        [(int) $activo['anio_id'], (int) $activo['numero']]);
    $secs = $m->query("SELECT id FROM secciones WHERE anio_id = ? ORDER BY id LIMIT 2", [(int) $activo['anio_id']]);
    $admin = $m->queryOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' LIMIT 1");
    $docente = $m->queryOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'docente' AND u.estado = 'activo' LIMIT 1");

    $filasAntes = (int) $m->queryOne("SELECT COUNT(*) AS n FROM auxiliar_secciones")['n'];

    $db = Core\Database::get();
    $db->beginTransaction();
    try {
        // Dos auxiliares de prueba.
        $crearAux = function (string $dni, string $nombre) use ($m, $rol): int {
            $m->execute("INSERT INTO personas (dni, apellido_paterno, apellido_materno, nombres, sexo)
                         VALUES (?, 'PRUEBA', 'VERIF', ?, 'F')", [$dni, $nombre]);
            $pid = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
            $m->execute("INSERT INTO usuarios (persona_id, rol_id, password_hash, estado)
                         VALUES (?, ?, 'x', 'activo')", [$pid, (int) $rol['id']]);
            return (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
        };
        $a = $crearAux('99000001', 'Auxiliar A');
        $b = $crearAux('99000002', 'Auxiliar B');
        [$s1, $s2] = [(int) $secs[0]['id'], (int) $secs[1]['id']];
        $por = (int) $admin['id'];
        $uA  = ['id' => $a, 'rol_codigo' => ROL_AUXILIAR];
        $uB  = ['id' => $b, 'rol_codigo' => ROL_AUXILIAR];
        $filasDe = fn(int $s): int => (int) $m->queryOne(
            "SELECT COUNT(*) AS n FROM auxiliar_secciones WHERE seccion_id = ?", [$s])['n'];

        $m->asignar($s1, $a, $por);
        $chk('asignar: A es el vigente en el bimestre activo', ($m->vigente($s1, $pActivo)['auxiliar_id'] ?? 0) === $a);
        if ($siguiente) {
            $chk('herencia: A sigue vigente en el bimestre siguiente sin nueva fila',
                ($m->vigente($s1, (int) $siguiente['id'])['auxiliar_id'] ?? 0) === $a);
        }
        if ($anterior) {
            $chk('historial: el bimestre anterior NO recibe la asignación',
                $m->vigente($s1, (int) $anterior['id']) === null);
        }
        $chk('seccionesDe(A) = solo su sección', $m->seccionesDe($a, $pActivo) === [$s1]);

        // puedeRegistrar: las DOS ramas.
        $chk('puedeRegistrar: A en su sección → SÍ', $m->puedeRegistrar($uA, $s1, $pActivo));
        $chk('puedeRegistrar: B en la sección de A → NO', !$m->puedeRegistrar($uB, $s1, $pActivo));
        $chk('puedeRegistrar: A en una sección ajena → NO', !$m->puedeRegistrar($uA, $s2, $pActivo));
        if ($anterior) {
            $chk('puedeRegistrar: A en un bimestre anterior a su asignación → NO',
                !$m->puedeRegistrar($uA, $s1, (int) $anterior['id']));
        }
        $chk('puedeRegistrar: Registro Académico en cualquier sección → SÍ',
            $m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'registro_academico'], $s2, $pActivo));
        $chk('puedeRegistrar: admin → SÍ', $m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'admin'], $s2, $pActivo));
        $chk('puedeRegistrar: docente → NO', !$m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'docente'], $s1, $pActivo));
        $chk('puedeRegistrar: director → NO', !$m->puedeRegistrar(['id' => 1, 'rol_codigo' => 'director_ebr'], $s1, $pActivo));

        // Reasignar dentro del mismo bimestre sobrescribe su fila.
        $m->asignar($s1, $b, $por);
        $chk('cambiar en el mismo bimestre sobrescribe (1 fila, vigente B)',
            $filasDe($s1) === 1 && ($m->vigente($s1, $pActivo)['auxiliar_id'] ?? 0) === $b);

        // Quitar sin nada heredado: vuelve a «sin fila».
        $m->asignar($s1, null, $por);
        $chk('quitar sin herencia previa no deja filas', $filasDe($s1) === 0 && $m->vigente($s1, $pActivo) === null);

        if ($anterior) {
            // Asignación histórica de un bimestre anterior (A), heredada por el activo.
            $m->execute("INSERT INTO auxiliar_secciones (seccion_id, desde_periodo_id, auxiliar_id, asignado_por, asignado_en)
                         VALUES (?, ?, ?, ?, ?)", [$s1, (int) $anterior['id'], $a, $por, date('Y-m-d H:i:s')]);
            $lista = array_column($m->listarParaPeriodo($pActivo), null, 'id');
            $chk('listarParaPeriodo marca la asignación como HEREDADA',
                $lista[$s1]['auxiliar_id'] === $a && $lista[$s1]['cambiado_aqui'] === false);

            $m->asignar($s1, $b, $por);
            $lista = array_column($m->listarParaPeriodo($pActivo), null, 'id');
            $chk('cambiar crea la fila del bimestre activo y la marca como propia',
                $filasDe($s1) === 2 && $lista[$s1]['cambiado_aqui'] === true);

            $m->asignar($s1, $a, $por);
            $chk('volver al heredado borra SOLO la fila del activo (queda la histórica)',
                $filasDe($s1) === 1 && ($m->vigente($s1, (int) $anterior['id'])['auxiliar_id'] ?? 0) === $a);
        }

        // Aviso de hijo/a en la sección: A pasa a ser apoderado de un estudiante
        // del roster de s1; B no tiene hijos. Las dos ramas.
        $alumno = $m->queryOne("SELECT m.estudiante_id FROM matriculas m
                                WHERE m.seccion_id = ? " . roster_evaluacion('m') . " LIMIT 1", [$s1]);
        if ($alumno) {
            $personaA = (int) $m->queryOne("SELECT persona_id FROM usuarios WHERE id = ?", [$a])['persona_id'];
            $m->execute("INSERT INTO apoderados (persona_id) VALUES (?)", [$personaA]);
            $apA = (int) $m->queryOne("SELECT LAST_INSERT_ID() AS id")['id'];
            // Tipo de vínculo libre para ese estudiante (UNIQUE estudiante+tipo).
            $col = $m->queryOne("SHOW COLUMNS FROM vinculo_familiar LIKE 'tipo_vinculo'");
            preg_match_all("/'([^']+)'/", (string) $col['Type'], $enum);
            $usados = array_column($m->query("SELECT tipo_vinculo FROM vinculo_familiar WHERE estudiante_id = ?",
                [(int) $alumno['estudiante_id']]), 'tipo_vinculo');
            $libre = array_values(array_diff($enum[1], $usados))[0] ?? null;
            if ($libre !== null) {
                $m->execute("INSERT INTO vinculo_familiar (apoderado_id, estudiante_id, tipo_vinculo, es_responsable)
                             VALUES (?, ?, ?, 0)", [$apA, (int) $alumno['estudiante_id'], $libre]);
                $hijos = $m->seccionesConHijos((int) $activo['anio_id']);
                $chk('aviso: A (apoderado de un alumno de la sección) queda marcado en ella',
                    in_array($s1, $hijos[$a] ?? [], true));
                $chk('aviso: A NO queda marcado en una sección sin hijos suyos',
                    !in_array($s2, $hijos[$a] ?? [], true));
                $chk('aviso: B (sin hijos) no aparece', !isset($hijos[$b]));
            } else {
                echo "  [SKIP] el alumno de prueba no tiene tipo de vínculo libre\n";
            }
        }

        // Firmas del registro (regla D12), con A como auxiliar vigente de s1.
        $m->asignar($s1, $a, $por);
        $ra = $m->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                         WHERE r.codigo = 'registro_academico' AND u.estado = 'activo'");
        $fA = $m->firmasDelRegistro($s1, $pActivo, $a);
        $chk('firmas: bloquea el auxiliar → la traza dice su rol real',
            $fA['bloqueo_rol'] === 'Auxiliar académico' && str_contains($fA['bloqueo_nombre'], 'PRUEBA'));
        $chk('firmas: «Auxiliar Responsable» = el auxiliar vigente de la sección',
            str_contains((string) $fA['auxiliar'], 'Auxiliar A'));
        $chk('firmas: línea de RA = el único RA activo, o en blanco si hay más de uno',
            count($ra) === 1 ? $fA['ra'] !== null : $fA['ra'] === null);
        if (count($ra) >= 1) {
            $fR = $m->firmasDelRegistro($s1, $pActivo, (int) $ra[0]['id']);
            $chk('firmas: bloquea RA (respaldo) → su nombre en la línea de RA y el auxiliar en la suya',
                $fR['ra'] === $fR['bloqueo_nombre'] && str_contains((string) $fR['auxiliar'], 'Auxiliar A'));
        }
        // El escenario se FIJA aquí, no se supone: la base puede traer auxiliares
        // reales en s2 (pasó el 28/09/2026, tras probar la pantalla en el navegador).
        $m->asignar($s2, null, $por);
        $fS = $m->firmasDelRegistro($s2, $pActivo, $a);
        $chk('firmas: sección sin auxiliar → «Auxiliar Responsable» en blanco', $fS['auxiliar'] === null);

        // Validaciones.
        $falla = function (callable $fn): bool {
            try { $fn(); return false; } catch (\DomainException $e) { return true; }
        };
        $chk('rechaza asignar a un usuario que no es auxiliar',
            $docente === null || $falla(fn() => $m->asignar($s2, (int) $docente['id'], $por)));
        $chk('rechaza una sección que no existe en el año activo',
            $falla(fn() => $m->asignar(999999, $a, $por)));

        $m->execute("UPDATE periodos SET estado = 'cerrado' WHERE id = ?", [$pActivo]);
        $chk('sin bimestre activo no se puede asignar', $falla(fn() => $m->asignar($s2, $a, $por)));
    } finally {
        $db->rollBack();
    }

    $filasDespues = (int) $m->queryOne("SELECT COUNT(*) AS n FROM auxiliar_secciones")['n'];
    $chk('el rollback no dejó rastro en auxiliar_secciones', $filasDespues === $filasAntes);
    $chk('el rollback devolvió el bimestre a activo', $m->periodoActivo() !== null);
}

echo $ok ? "\nTODO OK\n" : "\nHAY FALLAS\n";
exit($ok ? 0 : 1);
