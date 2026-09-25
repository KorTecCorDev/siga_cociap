<?php

/**
 * Helpers globales — SIGA-COCIAP
 * Funciones disponibles en toda la aplicación.
 */

/** Lee un valor de la configuración de la app */
function config(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require CONFIG_PATH . '/app.php';
    }
    return $config[$key] ?? $default;
}

/** Redirige a una URL y detiene la ejecución */
function redirect(string $url): never
{
    // Ruta relativa de app (/login, /dashboard…) → URL absoluta con base dinámica
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = url(ltrim($url, '/'));
    }
    header("Location: {$url}");
    exit;
}

/**
 * Formatea un datetime de la BD (guardado en hora Lima por la conexión).
 * Devuelve '—' si el valor es nulo o vacío.
 */
function fechaLima(?string $dt, string $formato = 'd/m/Y H:i'): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    return (new DateTime($dt))->format($formato);
}

/**
 * Nombre corto para mostrar en la interfaz (saludo, navbar): primer nombre +
 * apellido paterno. SOLO presentación del usuario en pantalla — NUNCA usar en
 * listas oficiales, firmas, reportes impresos ni boletas (esos requieren el
 * nombre completo legal).
 */
function nombre_corto(?string $nombres, ?string $apellidoPaterno = ''): string
{
    $primerNombre = explode(' ', trim($nombres ?? ''))[0];
    return trim($primerNombre . ' ' . trim($apellidoPaterno ?? ''));
}

/**
 * Etiqueta del docente de aula (unidocente) segun sexo. En primaria 1°-3° un
 * solo docente dicta TODAS las areas de su seccion y es su tutor: la interfaz
 * lo nombra "Tutor(a) de aula" para reflejar esa identidad de aula completa.
 */
function rol_aula(?string $sexo): string
{
    return match ($sexo) {
        'M'     => 'Tutor de aula',
        'F'     => 'Tutora de aula',
        default => 'Tutor(a) de aula',
    };
}

/**
 * ¿El DNI es un código PROVISIONAL? Un DNI real son 8 dígitos numéricos; el
 * alta provisional (estudiante sin DNI todavía) usa 'P' + 7 dígitos (P0000042).
 * Punto único de verdad para distinguirlos en controladores y vistas.
 */
function es_dni_provisional(?string $dni): bool
{
    return $dni !== null && $dni !== '' && strtoupper($dni[0]) === 'P';
}

/** Escapa HTML para prevenir XSS */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Genera el campo oculto CSRF para formularios */
function csrf_field(): string
{
    $token = \Core\Session::csrfToken();
    return "<input type=\"hidden\" name=\"_csrf_token\" value=\"{$token}\">";
}

/** Genera la URL base del proyecto */
function url(string $path = ''): string
{
    static $base = null;

    if ($base === null) {
        $appUrl = config('app_url');
        if (!empty($appUrl)) {
            // URL fija configurada (ej. IP LAN para pruebas en red local).
            // Tiene prioridad sobre la detección automática.
            $base = rtrim($appUrl, '/');
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            // Detecta el host real del request (incluye puerto si no es 80/443).
            // Cuando BrowserSync proxea, Apache recibe Host: localhost:3000
            // y PHP lo refleja aquí, manteniendo todas las URLs en el mismo origen.
            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'];
            $script   = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
            $basePath = rtrim(dirname($script), '/\\');
            $base     = $scheme . '://' . $host . $basePath;
        } else {
            // Fallback para contextos CLI o cuando $_SERVER no está disponible.
            $base = rtrim(config('url', 'http://localhost'), '/');
        }
    }

    $full = rtrim($base, '/') . '/' . ltrim($path, '/');

    // Cache-busting: si la ruta es un archivo estatico real, le anexa la fecha
    // de modificacion como ?v=... para que el navegador re-descargue al cambiar.
    return asset_version($full, $path);
}

/**
 * Cache-busting de assets estaticos.
 * Si $relPath apunta a un archivo real bajo public/ (css, js, imagenes,
 * fuentes, etc.) devuelve la URL con ?v=<filemtime>; asi el navegador y la
 * PWA instalada vuelven a descargar el archivo cada vez que cambia, sin que
 * el usuario tenga que limpiar la cache. Las rutas de la app (sin extension,
 * ej. /login, /boleta/123/1) se devuelven sin tocar.
 */
function asset_version(string $absoluteUrl, string $relPath): string
{
    static $cache = [];

    // Ya trae query string propia -> no interferir.
    if (str_contains($absoluteUrl, '?')) {
        return $absoluteUrl;
    }

    $rel = ltrim($relPath, '/');
    if ($rel === '') {
        return $absoluteUrl;
    }

    // Solo extensiones de archivos versionables. Las rutas de la app no tienen
    // extension, asi que se descartan aqui sin tocar el disco.
    static $exts = [
        'css', 'js', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'json', 'mp4', 'webm', 'pdf',
    ];
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $exts, true)) {
        return $absoluteUrl;
    }

    if (!array_key_exists($rel, $cache)) {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $file = $root . '/public/' . $rel;
        $cache[$rel] = is_file($file) ? filemtime($file) : null;
    }

    return $cache[$rel] === null
        ? $absoluteUrl
        : $absoluteUrl . '?v=' . $cache[$rel];
}

/** Genera la URL de un asset público (css, js, imágenes) */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** Formatea una nota (0-20) con cero a la izquierda: 5 → "05", 15 → "15" */
function fmt_nota(int|null $nota): string
{
    if ($nota === null) return '—';
    return sprintf('%02d', $nota);
}

/**
 * Umbrales de la escala literal — PUNTO ÚNICO DE VERDAD.
 * AD: 18-20 · A: 14-17 · B: 11-13 · C: 00-10.
 * Toda conversión (PHP o SQL interpolado) debe salir de estas constantes.
 */
const NOTA_MIN_AD = 18;
const NOTA_MIN_A  = 14;
const NOTA_MIN_B  = 11;

/**
 * Roles de DIRECCIÓN — PUNTO ÚNICO DE VERDAD.
 *
 * Los tres tipos de director tienen EXACTAMENTE las mismas atribuciones
 * (decisión del usuario, 24/08/2026): supervisión en SOLO LECTURA sobre los dos
 * niveles. No hay alcance por nivel — no existe mapeo usuario→nivel en el
 * sistema y se decidió que no lo habrá.
 *
 * Nació porque estos códigos estaban escritos a mano en 44 literales repartidos
 * por 16 archivos: sumar un tercer director obligaba a tocarlos uno por uno, que
 * es el patrón con el que ya divergieron cuatro reglas en este repositorio.
 * Cualquier control de acceso que hable de "los directores" se apoya en esta
 * constante; nunca se vuelve a listar los códigos a mano.
 *
 * ⚠️ DOS EXCEPCIONES DELIBERADAS, y NO son un olvido — son el par que sostiene
 * el invariante "solo el Director EBR firma":
 *   - `DirectorEbrModel::listarCandidatos()`  — LISTA los candidatos a firmante.
 *   - `Admin\DirectorEbrController::asignar()` — REVALIDA en servidor al asignar.
 * Las dos anclan a `'director_ebr'` en singular a propósito. Si esta constante
 * se cuela en cualquiera de ellas, un Director General o Académico podría quedar
 * asignado como firmante de boletas, actas y reportes de mérito.
 *
 * ⚠️ Al buscar estos códigos en el repositorio, hacerlo SIEMPRE entre comillas:
 * la cadena `director_ebr` también es parte del nombre de la tabla
 * `director_ebr_historial`, y un reemplazo masivo la corrompe en silencio.
 */
const ROLES_DIRECCION = ['director_general', 'director_ebr', 'director_academico'];

/**
 * PROCEDENCIA de una nota que NO salió del registro ordinario del docente.
 *
 * Conviven TRES mecanismos y hasta el 10/09/2026 solo uno llevaba marca, así
 * que se confundían entre sí — sobre todo en la ficha de matrícula, donde sus
 * cards son vecinas. Lo que de verdad los separa no es cómo se registran, sino
 * **A DÓNDE VA LA NOTA**, y eso es lo que dice cada `destino`.
 *
 * ⚠️ LA CATEGORÍA ES DERIVABLE, NO SE GUARDA. Cada mecanismo vive en su propia
 * tabla (`calificaciones.extraordinaria=1`, `notas_externas`,
 * `notas_autorizadas_siagie`), así que saber de cuál viene una nota no necesita
 * ninguna columna. Si algún día alguien propone una columna `categoria`,
 * es señal de que dos mecanismos se están mezclando en una misma tabla.
 *
 * ⚠️ EL COLOR NO DISTINGUE: distinguen el NOMBRE y el ICONO. Los tres chips
 * comparten forma (el borde punteado, que ya significa "esto no salió de tu
 * registro") y solo la extraordinaria conserva el ámbar, porque es la única que
 * llega a la boleta y por tanto pide atención. Es lo que exige la regla de
 * wayfinding (`docs/modulos/ui.md`): rojo y ámbar son de ESTADO, y los cuatro
 * colores de concepto (azul, teal, púrpura, naranja) ya tienen dueño.
 *
 * ⚠️ Los tres ICONOS deben ser distintos entre sí y no coincidir con los de las
 * cards del dashboard — misma regla del glifo fijo por concepto. Lo comprueba
 * `verif_notas_origen.php`.
 */
const PROCEDENCIA_EXTRAORDINARIA = 'extraordinaria';
const PROCEDENCIA_ORIGEN         = 'origen';
const PROCEDENCIA_SIAGIE         = 'siagie';

const PROCEDENCIAS_NOTA = [
    PROCEDENCIA_EXTRAORDINARIA => [
        'corto'   => 'EXTRAORDINARIA · RA',
        'nombre'  => 'Calificación extraordinaria',
        'icono'   => 'edit-pen',
        'destino' => 'Va a la boleta y al SIAGIE. No cuenta para el orden de mérito.',
        'ambar'   => true,
    ],
    PROCEDENCIA_ORIGEN => [
        'corto'   => 'COLEGIO DE ORIGEN',
        'nombre'  => 'Notas del colegio de origen',
        'icono'   => 'social-city',
        'destino' => 'Informativa: solo la ve el docente. NO aparece en la boleta.',
        'ambar'   => false,
    ],
    PROCEDENCIA_SIAGIE => [
        'corto'   => 'SIAGIE · DIRECCIÓN',
        'nombre'  => 'Autorizada para SIAGIE',
        'icono'   => 'doc-add',
        'destino' => 'Solo para el acta SIAGIE. No toca la boleta ni el orden de mérito.',
        'ambar'   => false,
    ],
];

/**
 * Datos de una procedencia. Devuelve null si la clave no existe, para que una
 * vista pueda decidir no pintar nada en vez de reventar.
 */
function procedencia_nota(string $clave): ?array
{
    return PROCEDENCIAS_NOTA[$clave] ?? null;
}

/**
 * Criterios que se PINTAN como criterio (columna, casilla o fila de la lista):
 * todos menos el `extraordinario` de RA. PUNTO ÚNICO de esa regla de
 * presentación (18/09/2026, decisión del usuario): la extraordinaria no forma
 * parte del registro del docente, así que en las grillas solo se explica con
 * la tarjeta `.extraordinaria-info`.
 *
 * ⚠️ SOLO PRESENTACIÓN. El criterio sigue en los datos: el promedio, la boleta,
 * SIAGIE y la puerta de aprobación lo leen. Nunca usar esto para calcular.
 * La rectificación no lo usa: allí el criterio sí se muestra, pero solo al
 * alumno que lo tiene (`RectificacionModel::getDetalleCompetencia`).
 */
function criterios_ordinarios(array $criterios): array
{
    return array_values(array_filter(
        $criterios,
        static fn(array $c): bool => empty($c['extraordinario'])
    ));
}

/**
 * ¿La audiencia que mira ahora mismo solo puede ver bimestres CERRADOS?
 * PUNTO ÚNICO DE VERDAD de esa pregunta.
 *
 * Es una regla de AUDIENCIA, no de datos: no dice qué es un bimestre cerrado
 * —eso lo dice `periodos.estado`—, sino a quién se le puede enseñar uno que aún
 * no lo está. Por eso vive aquí y no en un modelo, y por eso es el único helper
 * de punto único del archivo que lee la SESIÓN en vez de emitir SQL.
 *
 * Extiende al bimestre entero la "regla del dato oficial" de los usuarios de
 * Dirección (`docs/modulos/usuarios-direccion.md`): el director ve el AVANCE en
 * vivo, pero todo DATO que se le muestre debe estar aprobado y bloqueado. Un
 * bimestre a medio llenar da porcentajes, rankings y una línea de tendencia con
 * la misma pinta que los del bimestre cerrado, y la serie histórica lo dibuja
 * como un desplome que no ocurrió.
 *
 * 🔴 `admin` y `registro_academico` NO entran: son quienes tienen que vigilar el
 * avance del bimestre en curso, y para ellos no cambia nada.
 *
 * ⚠️ La comprobación de "cerrado" está copiada a mano en 16 sitios de `app/` y
 * `resources/views/` con 7 redacciones distintas. Esta función NO viene a
 * unificarlas: viene a que la pregunta por la AUDIENCIA tenga un solo dueño y no
 * nazca la 17.ª copia inline.
 */
function solo_bimestres_cerrados(): bool
{
    return has_role(ROLES_DIRECCION);
}

/**
 * Filtra una lista de periodos dejando solo los CERRADOS.
 *
 * Reindexa con `array_values()`: quien la consume la recorre en un `<select>` y
 * toma `[0]` como primer elemento, y `array_filter` conserva las claves
 * originales (un hueco en el índice 0 haría que `[0]` no exista).
 *
 * @param  array $periodos filas tal como las devuelve `ControlOperativoModel::getPeriodos()`
 * @return array las mismas filas, solo las de `estado === 'cerrado'`
 */
function periodos_cerrados(array $periodos): array
{
    return array_values(array_filter(
        $periodos,
        static fn(array $p): bool => ($p['estado'] ?? '') === 'cerrado'
    ));
}

/**
 * El ÚLTIMO bimestre cerrado: el de mayor `numero` dentro del año más reciente.
 *
 * ⚠️ NO vale `periodos_cerrados($periodos)[0]`. `getPeriodos()` ordena
 * `a.anio DESC, p.numero ASC`, así que el primer elemento es el bimestre **1**
 * del año más reciente — el más VIEJO del año, justo lo contrario. Tampoco vale
 * `getPeriodoPorDefecto()`, que devuelve el activo y cae en ese mismo `[0]`.
 *
 * El año se calcula aquí (máximo de los cerrados) en vez de confiar en el orden
 * de llegada: la función admite cualquier lista, no solo la de ese método.
 *
 * @param  array      $periodos filas con `anio`, `numero` y `estado`
 * @return array|null la fila, o `null` si no hay ningún bimestre cerrado
 */
function ultimo_periodo_cerrado(array $periodos): ?array
{
    $cerrados = periodos_cerrados($periodos);
    if (!$cerrados) {
        return null;
    }

    $anio = max(array_map(static fn(array $p): int => (int) ($p['anio'] ?? 0), $cerrados));

    $ultimo = null;
    foreach ($cerrados as $p) {
        if ((int) ($p['anio'] ?? 0) !== $anio) {
            continue;
        }
        if ($ultimo === null || (int) $p['numero'] > (int) $ultimo['numero']) {
            $ultimo = $p;
        }
    }

    return $ultimo;
}

/**
 * Colación de ordenamiento alfabético en ESPAÑOL — PUNTO ÚNICO DE VERDAD.
 *
 * Las columnas de `personas` son `utf8mb4_unicode_ci`, que equipara Ñ ≡ N: al
 * comparar "ÑIQUEN" con "NOLASCO" la Ñ pesa como N, decide la segunda letra
 * (I < O) y ÑIQUEN sale ANTES. En el alfabeto español la Ñ es letra propia y va
 * DESPUÉS de la N, así que lo correcto es NOLASCO → ÑIQUEN.
 *
 * Se aplica SOLO en el ORDER BY, nunca a la colación de las columnas: cambiarlas
 * afectaría también `=` y `LIKE`, y las búsquedas dejarían de encontrar a
 * NUÑUVERO cuando alguien escribe "NUNUVERO" (la ñ se omite al teclear).
 *
 * `spanish_ci` y no `spanish2_ci`: la segunda trata CH y LL como letras
 * independientes, criterio que la RAE abandonó en 1994.
 */
const COLLATE_ES = 'COLLATE utf8mb4_spanish_ci';

/**
 * Fragmento de ORDER BY para listar personas alfabéticamente en español.
 * Se interpola en SQL: es una constante del código, nunca entrada del usuario.
 *
 *   ORDER BY " . orden_alfabetico('p') . "
 *   ORDER BY n.id, g.numero, " . orden_alfabetico('per') . "
 *
 * @param string $alias  alias de la tabla `personas` en la consulta.
 * @param int    $campos 3 = paterno+materno+nombres (lo normal), 2 = sin
 *                       nombres, 1 = solo paterno. Para combinaciones raras,
 *                       interpolar COLLATE_ES a mano junto a cada columna.
 */
function orden_alfabetico(string $alias = 'p', int $campos = 3): string
{
    $columnas = array_slice(
        ['apellido_paterno', 'apellido_materno', 'nombres'],
        0,
        max(1, min(3, $campos))
    );

    return implode(', ', array_map(
        static fn(string $c): string => "{$alias}.{$c} " . COLLATE_ES,
        $columnas
    ));
}

/**
 * Filtro de matrículas VIGENTES — PUNTO ÚNICO.
 *
 * Fuera quien ya no está: el TRASLADO DE SALIDA abandonó el colegio y el
 * RETIRADO ya no asiste (sin traslado oficial; migración 045, reversible vía
 * `tipo_anterior`). Nadie más se excluye — en particular NO filtra por
 * `matriculas.estado`, que es una pregunta distinta.
 *
 * Es la primera de las tres condiciones de `roster_evaluacion()`, extraída
 * aparte porque también la necesita quien combina "estudiantes que siguen en
 * el colegio" con el criterio DOCUMENTO (`matricula_documento()`), que es el
 * ancla INVERSA a la de evaluación. La usan los chips de `/matriculas/resumen`.
 *
 * @param  string $alias alias de la tabla `matriculas` en la consulta
 * @return string la condición, ya con `AND` inicial, lista para interpolar
 */
function matriculas_vigentes(string $alias = 'm'): string
{
    return "AND {$alias}.tipo NOT IN ('trasladado', 'retirado')";
}

/**
 * Criterio DOCUMENTO del RETORNO DE GRADO — PUNTO ÚNICO de la condición SQL.
 *
 * La matrícula OPERATIVA de un retorno NUNCA figura en un documento ni en una
 * estadística: el documento se emite SIEMPRE con la OFICIAL (Regla A,
 * 05/08/2026). Vale igual para el retorno `activo` y para el `revertido`: en
 * ambos casos la boleta sale por la oficial.
 *
 * ⚠️ Es la exclusión INVERSA a la de `roster_evaluacion()`, que excluye la
 * OFICIAL para que el estudiante se califique en su grado operativo. No
 * confundirlas: aquí se lista quien RECIBE DOCUMENTO o SE CUENTA, allá quien
 * SE EVALÚA. Copiar la del lugar equivocado produce el defecto contrario.
 *
 * 🔴 VA SIN CONDICIÓN DE ESTADO, Y NO SE LE AÑADE NUNCA. Pegarle el
 * `WHERE estado = 'activo'` de la lista de evaluación produce el HÍBRIDO, que
 * es la tercera forma y es incorrecta: con un retorno `revertido` no excluye
 * NINGUNA de las dos matrículas y el estudiante cuenta dos veces, con la fila
 * fantasma cayendo en el GRADO INFERIOR como `continuador`/`desactivado`, que
 * es como `revertir()` deja la operativa. Estuvo vivo en el cuadro de
 * `/matriculas/resumen` hasta el 02/09/2026 (latente: el único retorno real
 * está `activo`). Lo vigila `verif_matricula_documento.php`.
 *
 * Consumidores: el lote de boletas y el hub de tokens (`BoletaPublicaModel`),
 * la resolución del token público (`BoletaController`), y toda
 * `/matriculas/resumen` — chips, los 5 gráficos y el cuadro por grado.
 *
 * @param  string $alias alias de la tabla `matriculas` en la consulta
 * @return string la condición, ya con `AND` inicial, lista para interpolar
 */
function matricula_documento(string $alias = 'm'): string
{
    return "AND {$alias}.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)";
}

/**
 * Filtro del ROSTER DE EVALUACIÓN — PUNTO ÚNICO de las condiciones SQL.
 *
 * Es el universo de "a quién se evalúa": la lista que ve el docente al calificar
 * y, por tanto, la que deben usar conducta, asistencia y sus contadores de
 * avance. Estaba copiado a mano en NUEVE consultas; se emite desde aquí igual
 * que `orden_alfabetico()`.
 *
 * Las tres condiciones y su porqué:
 *
 *  1. `tipo NOT IN ('trasladado','retirado')` — delegada en
 *     `matriculas_vigentes()`, que es su punto único: el TRASLADO DE SALIDA
 *     abandonó el colegio y el RETIRADO ya no asiste (sin traslado oficial;
 *     migración 045, reversible vía `tipo_anterior`). Nadie más se excluye.
 *  2-3. RETORNO DE GRADO — el estudiante tiene dos matrículas del mismo año y
 *     solo una se evalúa: mientras el retorno está `activo` se evalúa en la
 *     OPERATIVA (se excluye la oficial); tras `revertido` vuelve a la OFICIAL
 *     (se excluye la operativa).
 *
 * 🔴 NO filtra por `matriculas.estado` A PROPÓSITO. `pendiente` (el estado en
 * que NACE toda matrícula) y `desactivado` (baja administrativa por deuda)
 * SIGUEN ASISTIENDO y sí se califican. Filtrar por `estado='aprobada'` fue un
 * bug real: dejaba a esos alumnos fuera de la grilla de asistencia, nunca se
 * les creaba fila en `inasistencias` y su boleta salía con "0 inasistencias",
 * un dato FALSO en vez de ausente (04/08/2026).
 *
 * ⚠️ NO lo usan tres consultas, y no es un descuido:
 *  · `CalificacionModel` (resumen de competencia) añade
 *    `estado IN ('aprobada','pendiente')`.
 *  · `ControlOperativoModel` y `OrdenMeritoModel::ROSTER_MERITO` pertenecen al
 *    universo del ORDEN DE MÉRITO, que exige `estado='aprobada'` y tiene su
 *    propia excepción para la operativa revertida. Unificarlos rompería el
 *    invariante del mérito.
 *
 * @param  string $alias alias de la tabla `matriculas` en la consulta
 * @return string las tres condiciones, ya con `AND` inicial, listas para interpolar
 */
function roster_evaluacion(string $alias = 'm'): string
{
    // La primera condición sale de `matriculas_vigentes()`; las otras dos son
    // propias. El TEXTO EMITIDO no cambia ni un byte — `verif_roster_evaluacion.php`
    // lo compara literal, así que una recomposición desviada salta ahí.
    return matriculas_vigentes($alias) . "\n"
        . "              AND {$alias}.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')\n"
        . "              AND {$alias}.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido')";
}

/**
 * Marca del área de Ética y Valores (Educación Religiosa) — PUNTO ÚNICO.
 * Es la única área `tipo='tutoria'` que cuenta en el ORDEN DE MÉRITO
 * (reemplaza a Ed. Religiosa en secundaria; migración 035 la sella con este
 * `nombre_boleta`, idéntico en local y prod). Se identifica por el nombre y NO
 * por id (el id del área puede diferir entre entornos). El resto de la tutoría
 * (TOE) y las transversales siguen fuera del mérito.
 */
const AREA_ETICA_NOMBRE_BOLETA = 'Ética y Valores';

/**
 * Leyenda del documento EN BORRADOR — PUNTO ÚNICO del texto.
 * La usan la marca de agua de la boleta impresa (`boleta/_marca-borrador.php`,
 * que la reciben por igual la vista previa de RA, el ZIP de borradores y la
 * boleta del docente) y el aviso de la boleta digital. La FORMA cambia según el
 * formato —marca de agua en A4, aviso en la digital, que es de pantalla y no
 * cuesta alto de hoja—, pero el MENSAJE tiene que ser el mismo en todas.
 */
const BOLETA_LEYENDA_BORRADOR = 'Vista previa · no constituye documento oficial';

/** Convierte nota numérica (0-20) a literal. Misma escala en ambos niveles. */
function nota_a_literal(int $nota, string $nivel = 'secundaria'): string
{
    return match(true) {
        $nota >= NOTA_MIN_AD => 'AD',
        $nota >= NOTA_MIN_A  => 'A',
        $nota >= NOTA_MIN_B  => 'B',
        default              => 'C',
    };
}

/** Rangos numéricos de cada literal para leyendas (presentación) */
function escala_rangos(): array
{
    return [
        'AD' => sprintf('%02d–20', NOTA_MIN_AD),
        'A'  => sprintf('%02d–%02d', NOTA_MIN_A, NOTA_MIN_AD - 1),
        'B'  => sprintf('%02d–%02d', NOTA_MIN_B, NOTA_MIN_A - 1),
        'C'  => sprintf('00–%02d', NOTA_MIN_B - 1),
    ];
}

/** Descripción completa de la escala literal */
function descripcion_literal(string $literal): string
{
    return match($literal) {
        'AD' => 'Logro destacado',
        'A'  => 'Logro esperado',
        'B'  => 'En proceso',
        'C'  => 'En inicio',
        default => '—',
    };
}

/** Verifica si la conclusión descriptiva es obligatoria */
function conclusion_es_obligatoria(string $literal, string $nivel): bool
{
    if ($nivel === 'primaria') {
        return in_array($literal, ['B', 'C']);
    }
    return $literal === 'C'; // Secundaria solo en C
}


/**
 * Literales que cuentan como APROBADO — PUNTO ÚNICO DE VERDAD.
 *
 * El corte de aprobación depende del NIVEL, a diferencia de la escala literal
 * (`nota_a_literal()`), que es la misma en primaria y secundaria: lo que cambia
 * no es cómo se nombra la nota, sino dónde está la línea del aprobado.
 *
 * ⚠️ NO es la métrica «en logro» de `AnioAcademicoModel::getResumenBimestre()`,
 * que cuenta AD+A en los DOS niveles. Son dos preguntas distintas —«¿destacó o
 * alcanzó el logro esperado?» frente a «¿aprobó?»— y en secundaria dan números
 * diferentes porque B aprueba. NO unificarlas: no es una copia desactualizada.
 */
const LITERALES_APROBATORIOS = [
    'prim' => ['AD', 'A'],
    'sec'  => ['AD', 'A', 'B'],
];

/**
 * ¿El literal aprueba en ese nivel? Un alumno sin nota (`$literal === null`)
 * NO aprueba y NO desaprueba: no está evaluado, y se cuenta aparte.
 *
 * Acepta el código del nivel (`'prim'`/`'sec'`, que es lo que traen las vistas
 * en `$carga['nivel_codigo']`) y también los nombres largos que usa
 * `conclusion_es_obligatoria()` (`'primaria'`/`'secundaria'`), para que pasar
 * uno por el otro no falle en silencio hacia el lado permisivo.
 */
function nota_es_aprobatoria(?string $literal, string $nivelCodigo): bool
{
    if ($literal === null) {
        return false;
    }

    return in_array($literal, LITERALES_APROBATORIOS[nivel_clave($nivelCodigo)], true);
}

/**
 * Normaliza el código de nivel a la clave corta `'prim'` / `'sec'` — PUNTO ÚNICO.
 *
 * Las vistas traen `'prim'`/`'sec'` (`$carga['nivel_codigo']`) y
 * `conclusion_es_obligatoria()` usa los nombres largos `'primaria'`/`'secundaria'`.
 * Pasar uno por el otro no debe fallar en silencio hacia el lado permisivo, así
 * que todo lo que dependa del nivel entra por aquí en vez de repetir el
 * `str_starts_with`.
 */
function nivel_clave(string $nivelCodigo): string
{
    return str_starts_with(strtolower($nivelCodigo), 'prim') ? 'prim' : 'sec';
}

// ─────────────────────────────────────────────────────────────────────────────
// SITUACIÓN FINAL DEL ESTUDIANTE — regla del MINEDU (PUNTO ÚNICO)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Siglas de la situación final que exige el SIAGIE (RVM 00094-2020-MINEDU,
 * sub numeral 5.1.2.1, punto 5): promovido, requiere recuperación y permanece
 * en el grado.
 *
 * 🔴 «ESTUDIANTE EN RIESGO ACADÉMICO» = `RR` ∪ `PER`, es decir, TODO LO QUE NO
 * ES `PRO`. Esa es la definición del colegio desde el 23/09/2026 y deroga la
 * regla anterior por conteo global (primaria B+C ≥ 3 · secundaria C ≥ 3), que
 * no era normativa y daba otro conjunto: en el II Bimestre marcaba 157
 * estudiantes donde la norma marca 106, y ninguno de los dos es subconjunto del
 * otro.
 */
const SITUACION_PRO = 'PRO';
const SITUACION_RR  = 'RR';
const SITUACION_PER = 'PER';

/**
 * NO es una sigla del SIAGIE: es el hueco de datos. Un estudiante sin NINGUNA
 * competencia evaluada en el bimestre no tiene situación que proyectar, y
 * decir `RR` de él sería inventar un dato en contra. No cuenta como riesgo.
 */
const SITUACION_SIN_DATOS = 'ND';

/**
 * Tampoco es sigla del SIAGIE: en el PERIODO FINAL (último `numero` del año) la
 * situación ya no es una proyección sino la definitiva, y si al estudiante le
 * falta alguna competencia de su plan NO se afirma nada hasta completarla. Es
 * una red de seguridad: la regla del periodo final exige evaluarlas todas.
 * No cuenta como riesgo.
 */
const SITUACION_PENDIENTE = 'PEND';

/**
 * CERTEZA de la situación proyectada (24/09/2026). En B1-B3 el docente elige qué
 * competencias evalúa, así que casi todo estudiante tiene competencias
 * PENDIENTES, y la norma se formula sobre TODAS las del área. La certeza dice si
 * lo pendiente todavía puede cambiar el resultado:
 *   · `segura`     — no puede: ni con AD en todo lo pendiente (si está en riesgo)
 *                    ni con C en todo lo pendiente (si es PRO) cambia de lado;
 *   · `proyectada` — sí puede: el resultado depende de lo que falta evaluar.
 * Ver `situacion_final_proyectar()`.
 */
const CERTEZA_SEGURA     = 'segura';
const CERTEZA_PROYECTADA = 'proyectada';

/**
 * SEGUIMIENTO PEDAGÓGICO (24/09/2026) — umbrales. NO es riesgo: es la señal de
 * acompañamiento para quien SÍ sería promovido (o tiene promoción automática,
 * 1.º de primaria) pero acumula competencias bajas. Recicla, como señal
 * pedagógica, los umbrales de la primera versión del riesgo (23/09), que se
 * derogó como regla de PROMOCIÓN. Ver `seguimiento_pedagogico()`.
 */
const SEGUIMIENTO_MIN_B = 3;
const SEGUIMIENTO_MIN_C = 3;

/**
 * ¿El estudiante necesita SEGUIMIENTO pedagógico? — PUNTO ÚNICO de la regla
 * (decisión del usuario, 24/09/2026). Función PURA.
 *
 *  · primaria:   `SEGUIMIENTO_MIN_B` o más en B, **o** `SEGUIMIENTO_MIN_C` o más
 *                en C (cada literal por separado: 2 B + 1 C NO entra, a propósito);
 *  · secundaria: `SEGUIMIENTO_MIN_C` o más en C (la B aprueba en secundaria).
 *
 * Solo se pregunta de estudiantes PRO o con promoción automática: quien está en
 * riesgo (RR/PER) ya se atiende en su propio bloque, y nunca está en los dos.
 * Cuenta las mismas competencias que la situación final (último nivel
 * registrado, sin transversales).
 */
function seguimiento_pedagogico(int $numB, int $numC, string $nivelCodigo): bool
{
    if (nivel_clave($nivelCodigo) === 'prim') {
        return $numB >= SEGUIMIENTO_MIN_B || $numC >= SEGUIMIENTO_MIN_C;
    }
    return $numC >= SEGUIMIENTO_MIN_C;
}

/**
 * Cuántas competencias son «la mitad» de un área — PUNTO ÚNICO del redondeo.
 *
 * La norma fija la convención de forma explícita para los números impares
 * (numeral 5.1.3): «Cuando un área curricular tiene 5 competencias, se entiende
 * como "la mitad" a 3 competencias. Si el área tiene 3 competencias, se entiende
 * como "la mitad" a 2 competencias. En el caso de áreas curriculares con una sola
 * competencia, se considera esa única competencia del área.»
 *
 * Es exactamente `ceil(n / 2)`, y por tanto «más de la mitad» es `> ceil(n / 2)`,
 * leído con la misma convención. NUNCA usar `n / 2` a secas: en un área de 3
 * competencias daría 1,5 y «la mitad» pasaría a ser 2 solo por redondeo del
 * lenguaje, no por la norma.
 *
 * ⚠️ En este colegio hay áreas de UNA SOLA competencia (Educación para el
 * Trabajo, Taller de Pre-Cálculo y Ética y Valores): ahí «la mitad» es esa
 * única competencia y una «C» reprueba el área entera.
 */
function mitad_competencias(int $n): int
{
    return (int) ceil($n / 2);
}

/**
 * ¿La promoción de este grado es AUTOMÁTICA? Solo 1.º de primaria (cuadro del
 * literal A del sub numeral 5.1.3). Un estudiante de 1.º NUNCA está en riesgo
 * académico: no puede quedarse ni requerir recuperación.
 */
function grado_promocion_automatica(string $nivelCodigo, int $gradoNumero): bool
{
    return nivel_clave($nivelCodigo) === 'prim' && $gradoNumero === 1;
}

/**
 * ¿Es el grado FINAL de su ciclo? Primaria 2.º, 4.º y 6.º · Secundaria 2.º y 5.º.
 *
 * Es la bisagra de toda la regla: «el sistema educativo peruano está organizado
 * por ciclos y se espera que un estudiante haya avanzado un nivel en el
 * desarrollo de la competencia en ese tiempo. Por lo tanto, las condiciones
 * para la promoción […] tendrán requerimientos diferentes al culminar el grado
 * dependiendo de si este es el grado final del ciclo o es un grado intermedio»
 * (numeral 5.1.3, punto 1).
 *
 * 🔴 En los grados FINALES de ciclo la promoción exige «"B" en las demás
 * competencias», así que **una sola competencia en "C" impide la promoción**.
 * En los intermedios la norma admite «C» de forma explícita. Por eso el mismo
 * número de «C» significa cosas distintas según el grado.
 */
function grado_final_de_ciclo(string $nivelCodigo, int $gradoNumero): bool
{
    $finales = nivel_clave($nivelCodigo) === 'prim' ? [2, 4, 6] : [2, 5];

    return in_array($gradoNumero, $finales, true);
}

/**
 * SITUACIÓN FINAL de un estudiante — PUNTO ÚNICO de la regla del MINEDU.
 *
 * Norma: documento normativo aprobado por **RVM N° 00094-2020-MINEDU**,
 * **modificado por RVM N° 048-2024-MINEDU** (30/04/2024), que reescribió el
 * cuadro de Secundaria. El de Primaria sigue siendo el original de 2020. La
 * RM 474-2022-MINEDU NO la deroga: es la norma técnica del año escolar 2023.
 *
 * 🔴 SE CUENTA POR ÁREA, NO POR COMPETENCIAS SUELTAS. La pregunta de la norma
 * nunca es «cuántas C tiene» sino «cuántas ÁREAS cumplen tal condición». Un
 * conteo global no puede aproximarla.
 *
 * ```
 *                      PROMOCIÓN (al término del periodo lectivo)   PERMANENCIA
 *  prim 1.º            automática                                   no aplica
 *  prim 2.º/4.º/6.º    A o AD en la mitad+ de 4 áreas Y cero C      C en más de la mitad de 4 áreas
 *  prim 3.º/5.º        B o superior en la mitad+ de TODAS las áreas ídem
 *  sec  1.º/3.º/4.º    B o superior en la mitad+ de TODAS las áreas C en más de la mitad de 4+ áreas
 *  sec  2.º/5.º        A o AD en la mitad+ de 3 áreas Y cero C      C en la mitad o más de 4+ áreas
 * ```
 *
 * `RR` es el resto: «si no cumplen las condiciones de promoción o permanencia».
 * Se evalúa PRO → PER → RR; con estos umbrales las dos primeras son mutuamente
 * excluyentes, pero el orden se fija igual para que la función sea total.
 *
 * QUÉ NO ENTRA EN `$areas` (numeral 5.1.3, puntos 9 a 11), y lo garantiza quien
 * arma la lista, no esta función: las **competencias transversales** no cuentan
 * para la situación final; las competencias adicionales sin área curricular
 * tampoco; las organizadas en áreas y **los talleres SÍ**. Las **exoneradas**
 * tampoco, ni en el numerador ni en el denominador.
 *
 * ⚠️ DECISIÓN DE LECTURA (23/09/2026): la condición de permanencia de primaria
 * dice «"C" en más de la mitad […] **y "B" en las demás competencias**». Tomada
 * al pie de la letra, un alumno con «C» de sobra quedaría FUERA de la
 * permanencia, que es el absurdo contrario al que busca la norma. Es un
 * artefacto de redacción: se aplica solo la condición de «C», como el SIAGIE.
 *
 * ⚠️ UN ÁREA SIN NINGUNA COMPETENCIA EVALUADA NO SE PASA. El denominador son
 * las competencias con nivel de logro; quien arma `$areas` declara aparte la
 * cobertura. Ver `SituacionFinalModel`.
 *
 * @param  array  $areas  una entrada por área evaluada:
 *                        `['n' => int, 'n_ab' => int, 'n_b' => int, 'n_c' => int, 'nombre' => ?string]`
 *                        donde `n_ab` son las competencias en AD o A.
 * @return array{situacion:string, areas:int, areas_ab:int, areas_b:int,
 *               areas_c_mas:int, areas_c_mitad:int, c_total:int,
 *               automatica:bool, final_de_ciclo:bool, fallan:array, motivo:string}
 */
function situacion_final_analisis(array $areas, string $nivelCodigo, int $gradoNumero): array
{
    $prim  = nivel_clave($nivelCodigo) === 'prim';
    $final = grado_final_de_ciclo($nivelCodigo, $gradoNumero);

    $out = [
        'situacion'      => SITUACION_PRO,
        'areas'          => count($areas),
        'areas_ab'       => 0,
        'areas_b'        => 0,
        'areas_c_mas'    => 0,
        'areas_c_mitad'  => 0,
        'c_total'        => 0,
        'automatica'     => grado_promocion_automatica($nivelCodigo, $gradoNumero),
        'final_de_ciclo' => $final,
        'fallan'         => [],   // áreas sin la mitad en B o superior (grados intermedios)
        // Veredicto POR ÁREA (24/09/2026), del mismo bucle que decide la sigla:
        // lo lee `situacion_efecto_competencia()` para el chip de cada fila.
        'por_area'       => [],
        'motivo'         => '',
    ];

    // Sin un área evaluada no hay situación que determinar. Ni PRO (inventaría
    // una promoción con cero datos) ni RR (inventaría un dato EN CONTRA del
    // estudiante): se dice que no hay dato, y no cuenta como riesgo.
    //
    // 🔴 SE COMPRUEBA ANTES QUE LA PROMOCIÓN AUTOMÁTICA. Un
    // alumno de 1.º sin una sola competencia evaluada sigue sin tener nada que
    // proyectar: devolver PRO lo contaría como «evaluado» en los denominadores
    // del informe y un bimestre recién abierto mostraría 1.º al 100 % evaluado
    // sin una sola nota puesta.
    if ($areas === []) {
        $out['situacion'] = SITUACION_SIN_DATOS;
        $out['motivo']    = 'Sin competencias evaluadas en el bimestre.';
        return $out;
    }

    if ($out['automatica']) {
        $out['motivo'] = 'Promoción automática (1.º de primaria).';
        return $out;
    }

    foreach ($areas as $a) {
        $n     = (int) ($a['n'] ?? 0);
        $mitad = mitad_competencias($n);
        $ab    = (int) ($a['n_ab'] ?? 0);
        $b     = (int) ($a['n_b'] ?? 0);
        $c     = (int) ($a['n_c'] ?? 0);

        $out['c_total'] += $c;

        if ($ab >= $mitad)      { $out['areas_ab']++; }
        if ($ab + $b >= $mitad) { $out['areas_b']++; }
        else                    { $out['fallan'][] = (string) ($a['nombre'] ?? '—'); }
        if ($c >  $mitad)       { $out['areas_c_mas']++; }
        if ($c >= $mitad)       { $out['areas_c_mitad']++; }

        $out['por_area'][] = [
            'id'         => $a['id'] ?? null,
            'nombre'     => $a['nombre'] ?? null,
            'n' => $n, 'ab' => $ab, 'b' => $b, 'c' => $c, 'mitad' => $mitad,
            // Cuenta para las 4 áreas de la permanencia (mismo corte que abajo).
            'suma_per'   => (!$prim && $final) ? $c >= $mitad : $c > $mitad,
            'no_llega_b' => $ab + $b < $mitad,
            // Cumple justo: una competencia más en C la haría fallar.
            'limite'     => $ab + $b === $mitad,
            'falta_ab'   => $ab < $mitad,
        ];
    }

    // ── PROMOCIÓN ────────────────────────────────────────────────────────────
    $minAreasAb = $prim ? 4 : 3;   // primaria 2/4/6 exige 4 áreas; secundaria 2/5, tres
    $out['min_areas_ab'] = $minAreasAb;   // lo lee el chip de las filas (grados finales)
    $promovido  = $final
        ? ($out['areas_ab'] >= $minAreasAb && $out['c_total'] === 0)
        : ($out['areas_b']  === $out['areas']);

    if ($promovido) {
        $out['motivo'] = 'Cumple las condiciones de promoción.';
        return $out;
    }

    // ── PERMANENCIA ──────────────────────────────────────────────────────────
    // Secundaria 2.º y 5.º: «la mitad o más». El resto: «más de la mitad».
    $areasC = (!$prim && $final) ? $out['areas_c_mitad'] : $out['areas_c_mas'];
    $out['areas_per'] = $areasC;   // cuántas de las 4 de la permanencia (lo dice el motivo del RR)
    if ($areasC >= 4) {
        $out['situacion'] = SITUACION_PER;
        $out['motivo']    = sprintf(
            'Permanece en el grado: %d áreas con %s de sus competencias en C (la norma fija 4).',
            $areasC,
            (!$prim && $final) ? 'la mitad o más' : 'más de la mitad'
        );
        return $out;
    }

    // ── REQUIERE RECUPERACIÓN ────────────────────────────────────────────────
    $out['situacion'] = SITUACION_RR;
    $out['motivo']    = situacion_final_motivo($out, $minAreasAb);
    // Cuánto le falta para la permanencia (24/09/2026): el chip de sus filas
    // dice «Causa RR», así que la cercanía al PER se dice aquí, en texto.
    if ($areasC > 0) {
        $out['motivo'] .= sprintf(
            ' Reúne %d de las 4 áreas que llevarían a la permanencia (PER).',
            $areasC
        );
    }

    return $out;
}

/**
 * Por qué este estudiante no alcanza la promoción, en una línea legible.
 * Sale del MISMO análisis que decidió la situación, para que el texto no pueda
 * contradecir a la cifra de su propia fila.
 *
 * @param array $a el arreglo de `situacion_final_analisis()`, ya contado
 */
function situacion_final_motivo(array $a, int $minAreasAb): string
{
    if (!$a['final_de_ciclo']) {
        // Grados intermedios: se nombran las áreas que no llegan a la mitad en
        // B o superior. Son POCAS por definición (si fueran muchas sería PER).
        $faltan = array_slice($a['fallan'], 0, 4);
        $resto  = count($a['fallan']) - count($faltan);

        return sprintf(
            'Requiere recuperación: %d área(s) sin la mitad de sus competencias en B o superior (%s%s).',
            count($a['fallan']),
            implode(', ', $faltan),
            $resto > 0 ? sprintf(' y %d más', $resto) : ''
        );
    }

    // Grados FINALES de ciclo: la promoción pide dos cosas a la vez.
    $partes = [];
    if ($a['areas_ab'] < $minAreasAb) {
        $partes[] = sprintf(
            'solo %d área(s) con la mitad de sus competencias en A o AD (se exigen %d)',
            $a['areas_ab'],
            $minAreasAb
        );
    }
    if ($a['c_total'] > 0) {
        $partes[] = sprintf(
            '%d competencia(s) en C, y en un grado final de ciclo la promoción exige B o superior en todas',
            $a['c_total']
        );
    }

    return 'Requiere recuperación: ' . implode('; ', $partes) . '.';
}

/**
 * Situación proyectada de un estudiante CON SUS COMPETENCIAS PENDIENTES
 * (24/09/2026). Envoltorio PURO de `situacion_final_analisis()`: no cambia la
 * regla, la aplica tres veces.
 *
 *  1. La SIGLA sale de las competencias EVALUADAS: su último nivel registrado
 *     hasta el bimestre, como el SIAGIE (el arrastre lo hace el modelo; aquí
 *     llegan ya resueltas).
 *  2. Las COTAS se calculan contra el PLAN completo de cada área, que es sobre
 *     lo que la norma formula «la mitad»: mejor caso (lo pendiente = AD) y peor
 *     caso (lo pendiente = C).
 *  3. La CERTEZA compara la sigla con la cota que podría voltearla: un riesgo es
 *     `segura` si ni el mejor caso llega a PRO; una promoción, si ni el peor caso
 *     cae en riesgo.
 *
 * 🔴 LAS COTAS SON MONÓTONAS, y es lo que hace que la certeza tenga solo dos
 * valores: si la sigla es PRO, el mejor caso también lo es (rellenar con AD solo
 * suma competencias en A/AD, y «la mitad» del plan nunca supera a la de lo
 * evaluado más lo rellenado); si es riesgo, el peor caso también. Por eso no
 * existe «PRO con mejor caso en riesgo» ni al revés.
 *
 * ⚠️ POR QUÉ NO SE USA EL PLAN COMO DENOMINADOR DE LA SIGLA. Contar lo pendiente
 * como «ni B ni C» castiga la promoción y regala la permanencia a la vez (en B1
 * pasaba 192 estudiantes de PRO a RR): no es neutral, es otro sesgo.
 *
 * En el PERIODO FINAL no se proyecta: con alguna competencia pendiente la
 * situación es `SITUACION_PENDIENTE` y no se afirma nada.
 *
 * @param  array $areas  una entrada por área del PLAN del estudiante (también las
 *                       que no tienen ninguna evaluada, con `n = 0`):
 *                       `['nombre', 'n', 'n_ab', 'n_b', 'n_c', 'plan']`, donde
 *                       `plan` son las competencias que el área le dicta.
 * @return array  lo de `situacion_final_analisis()` sobre lo evaluado, más
 *                `certeza`, `mejor`, `peor`, `pendientes` y `areas_pendientes`.
 */
function situacion_final_proyectar(array $areas, string $nivelCodigo, int $gradoNumero, bool $periodoFinal = false): array
{
    $evaluadas = $mejor = $peor = $areasPend = [];
    $pendientes = 0;

    foreach ($areas as $a) {
        $n    = (int) ($a['n'] ?? 0);
        $plan = max($n, (int) ($a['plan'] ?? $n));
        $miss = $plan - $n;
        $base = ['id' => $a['id'] ?? null, 'nombre' => $a['nombre'] ?? null, 'n' => $plan,
                 'n_ab' => (int) ($a['n_ab'] ?? 0), 'n_b' => (int) ($a['n_b'] ?? 0), 'n_c' => (int) ($a['n_c'] ?? 0)];

        if ($n > 0) {
            $evaluadas[] = ['n' => $n] + $base;
        }
        if ($miss > 0) {
            $pendientes += $miss;
            $areasPend[] = (string) ($a['nombre'] ?? '—');
        }
        $mejor[] = ['n_ab' => $base['n_ab'] + $miss] + $base;
        $peor[]  = ['n_c'  => $base['n_c']  + $miss] + $base;
    }

    $out = situacion_final_analisis($evaluadas, $nivelCodigo, $gradoNumero) + [
        'certeza'          => null,
        'mejor'            => null,
        'peor'             => null,
        'pendientes'       => $pendientes,
        'areas_pendientes' => $areasPend,
    ];

    // Sin datos o promoción automática: no hay nada que acotar.
    if ($out['situacion'] === SITUACION_SIN_DATOS || $out['automatica']) {
        return $out;
    }

    if ($periodoFinal && $pendientes > 0) {
        $out['situacion'] = SITUACION_PENDIENTE;
        $out['motivo']    = sprintf(
            'Situación final pendiente: %d competencia(s) sin nivel de logro en el periodo final (%s).',
            $pendientes,
            implode(', ', $areasPend)
        );
        return $out;
    }

    $out['mejor'] = situacion_final_analisis($mejor, $nivelCodigo, $gradoNumero)['situacion'];
    $out['peor']  = situacion_final_analisis($peor,  $nivelCodigo, $gradoNumero)['situacion'];

    $out['certeza'] = situacion_es_riesgo($out['situacion'])
        ? (situacion_es_riesgo($out['mejor']) ? CERTEZA_SEGURA : CERTEZA_PROYECTADA)
        : (situacion_es_riesgo($out['peor'])  ? CERTEZA_PROYECTADA : CERTEZA_SEGURA);

    if (situacion_es_riesgo($out['situacion']) && $out['certeza'] === CERTEZA_PROYECTADA) {
        $out['motivo'] .= sprintf(
            ' Todavía puede alcanzar la promoción: depende de %d competencia(s) pendiente(s) (%s).',
            $pendientes,
            implode(', ', $areasPend)
        );
    }

    return $out;
}

/**
 * La situación final a secas. Azúcar sobre `situacion_final_analisis()` para
 * quien solo necesita la sigla (verificadores, pruebas).
 */
function situacion_final(array $areas, string $nivelCodigo, int $gradoNumero): string
{
    return situacion_final_analisis($areas, $nivelCodigo, $gradoNumero)['situacion'];
}

/**
 * Orden de una lista de estudiantes en riesgo dentro de su grado — PUNTO ÚNICO.
 *
 * Manda la gravedad: primero los que PERMANECEN en el grado, después los que
 * requieren recuperación; dentro de cada grupo, más competencias en C primero y
 * luego más en B.
 *
 * 🔴 EL DESEMPATE FINAL ES EL ORDEN ALFABÉTICO, y no se escribe aquí: `usort()`
 * es ESTABLE desde PHP 8.0, así que las filas empatadas conservan el orden en
 * que llegaron, y llegan ya ordenadas por `orden_alfabetico()` desde la
 * consulta del roster. Ordenar aquí por el nombre compuesto sería peor: heredaría
 * la colación de la columna, que equipara Ñ con N (ver `orden_alfabetico()`).
 */
function situacion_ordenar(array &$lista): void
{
    $peso = static fn(array $x): int => ($x['situacion'] ?? '') === SITUACION_PER ? 1 : 0;

    usort($lista, static fn(array $a, array $b): int =>
        [$peso($b), (int) $b['num_c'], (int) $b['num_b']]
        <=> [$peso($a), (int) $a['num_c'], (int) $a['num_b']]);
}

/**
 * EFECTO de una competencia en la situación final (24/09/2026): el chip de su
 * fila en el desglose. Nació porque el desglose lista TODAS las competencias
 * no aprobatorias y la norma decide POR ÁREA: en B2, en los grados
 * intermedios, solo el 54 % de esas filas eran de un área que causa el RR.
 *
 * PUNTO ÚNICO y función PURA: lee el veredicto del área que dejó
 * `situacion_final_analisis()` en `por_area` —el mismo bucle que decidió la
 * sigla—, así que el chip no puede contradecir a la situación.
 *
 * 🔴 EL CHIP RESPONDE A LA SITUACIÓN DEL ESTUDIANTE (decisión del usuario,
 * 24/09/2026). En los grados intermedios toda área que suma a PER también
 * causa RR, así que con una precedencia fija «PER > RR» 23 estudiantes RR de
 * B1 no tenían ni un «Causa RR» y se leían como PER. Ahora:
 *
 *  · estudiante PER → `EFECTO_PER` en las filas en C de las áreas que forman
 *                     sus 4 (prim y sec intermedio `c > mitad`; sec 2.º/5.º
 *                     `c ≥ mitad`). Nada más: su situación ya la dicen esas.
 *  · estudiante RR  → `EFECTO_RR`: intermedio, filas en C de un área sin la
 *                     mitad en B o superior; final de ciclo, toda fila en C, y
 *                     si faltan áreas con la mitad en A/AD, las B de esas
 *                     áreas. Cuánto le falta para PER va en el MOTIVO.
 *  · RR o PRO       → `EFECTO_LIMITE` (intermedio): área que cumple justo, una
 *                     C más la haría fallar.
 * Promoción automática, sin datos o situación pendiente: sin chip.
 *
 * @param array  $area      entrada de `por_area`
 * @param string $literal   literal de la fila (ya no aprobatoria)
 * @param array  $analisis  salida de `situacion_final_analisis()` / `_proyectar()`
 */
const EFECTO_PER    = 'per';
const EFECTO_RR     = 'rr';
const EFECTO_LIMITE = 'limite';

function situacion_efecto_competencia(array $area, string $literal, array $analisis): ?string
{
    $sit = (string) ($analisis['situacion'] ?? '');
    if (!empty($analisis['automatica'])) {
        return null;
    }
    if ($sit === SITUACION_PER) {
        return ($literal === 'C' && !empty($area['suma_per'])) ? EFECTO_PER : null;
    }
    if ($sit !== SITUACION_RR && $sit !== SITUACION_PRO) {
        return null;
    }
    if ($sit === SITUACION_PRO) {
        // Un promovido solo puede estar «en el límite» (grado intermedio).
        return (empty($analisis['final_de_ciclo']) && !empty($area['limite'])) ? EFECTO_LIMITE : null;
    }

    if (!empty($analisis['final_de_ciclo'])) {
        if ($literal === 'C') {
            return EFECTO_RR;
        }
        $faltanAb = (int) ($analisis['areas_ab'] ?? 0) < (int) ($analisis['min_areas_ab'] ?? 0);
        return ($literal === 'B' && $faltanAb && !empty($area['falta_ab'])) ? EFECTO_RR : null;
    }

    if ($literal === 'C' && !empty($area['no_llega_b'])) {
        return EFECTO_RR;
    }
    return !empty($area['limite']) ? EFECTO_LIMITE : null;
}

/**
 * Rótulo del chip de efecto. Desde el 24/09/2026 PER y RR van solo con la
 * sigla: el signo de alerta que las precede lo pinta la vista (`_efecto.php`).
 */
function situacion_efecto_rotulo(string $efecto): string
{
    return match ($efecto) {
        EFECTO_PER    => 'PER',
        EFECTO_RR     => 'RR',
        EFECTO_LIMITE => 'En el límite',
        default       => '',
    };
}

/**
 * ¿Esta situación final pone al estudiante en RIESGO ACADÉMICO? Todo lo que no
 * es promoción. PUNTO ÚNICO de la pregunta: ningún sitio compara con `'RR'` y
 * `'PER'` a mano.
 */
function situacion_es_riesgo(string $situacion): bool
{
    return $situacion === SITUACION_RR || $situacion === SITUACION_PER;
}

/**
 * Rótulo largo de una sigla, para pantalla y papel.
 */
function situacion_rotulo(string $situacion): string
{
    return match ($situacion) {
        SITUACION_PER        => 'Permanece en el grado',
        SITUACION_RR         => 'Requiere recuperación',
        SITUACION_SIN_DATOS  => 'Sin datos en el bimestre',
        SITUACION_PENDIENTE  => 'Situación final pendiente',
        default              => 'Promovido',
    };
}

/**
 * Contadores de una competencia a partir del resumen que YA está en memoria.
 *
 * No consulta la base de datos: recibe el `$alumnos` que devuelve
 * `CalificacionModel::getResumenCompetencia()` (con `promedio` y `literal` por
 * alumno) y la lista de matrículas exoneradas. Así el bloque de estadísticas de
 * las vistas no cuesta ni una consulta más — y en particular no vuelve a
 * disparar el N+1 de ese método.
 *
 * Tres reglas, y las tres tienen su motivo:
 *
 *  1. **Evaluado ⟺ `promedio !== null`**, comparado con `!==` y nunca con
 *     `empty()`: una nota 0 es un cero real, no un hueco. Equivale a "no tiene
 *     todos los criterios sin nota" por el invariante «fila en `calificaciones`
 *     existe ⟺ el alumno tiene nota viva».
 *  2. **Los EXONERADOS salen del universo** antes de contar nada. Exonerar NO
 *     borra las notas anteriores (para que la exoneración sea reversible), así
 *     que un exonerado puede traer `promedio` no nulo; sin este filtro sumaría
 *     como evaluado y como aprobado mientras su boleta muestra `EXO`.
 *  3. **Los porcentajes van sobre `evaluados`**, no sobre el roster: quien no
 *     tiene nota no es quien desaprobó, y mezclarlos inflaría el desaprobado.
 *
 * @param  array  $alumnos      filas con al menos `matricula_id`, `promedio`, `literal`
 * @param  array  $exonerados   matricula_ids exonerados (formato de `ExoneracionModel::getActivasParaCarga`)
 * @param  string $nivelCodigo  'prim' | 'sec'
 */
function stats_competencia(array $alumnos, array $exonerados, string $nivelCodigo): array
{
    $exoSet    = array_flip($exonerados);
    $literales = ['AD' => 0, 'A' => 0, 'B' => 0, 'C' => 0];

    $total = count($alumnos);
    $exo = $evaluados = $aprobados = 0;

    foreach ($alumnos as $alumno) {
        if (isset($exoSet[$alumno['matricula_id']])) {
            $exo++;
            continue;
        }

        if (($alumno['promedio'] ?? null) === null) {
            continue;
        }

        $evaluados++;

        $literal = $alumno['literal'];
        if (isset($literales[$literal])) {
            $literales[$literal]++;
        }

        if (nota_es_aprobatoria($literal, $nivelCodigo)) {
            $aprobados++;
        }
    }

    $universo     = $total - $exo;
    $desaprobados = $evaluados - $aprobados;
    $clave        = str_starts_with(strtolower($nivelCodigo), 'prim') ? 'prim' : 'sec';

    // Sin evaluados no hay porcentaje: 0 % seria un dato FALSO, no ausente.
    $pct = static fn(int $n): float => $evaluados > 0
        ? round($n / $evaluados * 100, 1)
        : 0.0;

    return [
        'total'        => $total,
        'exonerados'   => $exo,
        'universo'     => $universo,
        'evaluados'    => $evaluados,
        'no_evaluados' => $universo - $evaluados,
        'aprobados'    => $aprobados,
        'desaprobados' => $desaprobados,
        'literales'    => $literales,
        'aprobatorios' => LITERALES_APROBATORIOS[$clave],
        'pct'          => [
            'AD'           => $pct($literales['AD']),
            'A'            => $pct($literales['A']),
            'B'            => $pct($literales['B']),
            'C'            => $pct($literales['C']),
            'aprobados'    => $pct($aprobados),
            'desaprobados' => $pct($desaprobados),
        ],
    ];
}
/**
 * Agregado de "estudiantes en riesgo académico" sobre lo que ya devolvió
 * `SituacionFinalModel::porGrado()` (PUNTO ÚNICO, 07/09/2026).
 *
 * Es una función PURA: no consulta nada, solo recorre en memoria la lista de
 * grados que la vista ya recibió. Vive aquí, junto a `stats_competencia()`, y no
 * en el modelo ni en el controlador, porque la necesitan varios sitios que no
 * pueden llamarse entre sí:
 *   · la banda de `/admin/cuadros` (`_banda-riesgo.php`) y su índice de anclas,
 *   · la vista `/admin/cuadros/acompanamiento` y su A4 (vía `riesgo_estadisticas()`),
 *   · `verif_direccion_superficies.php`, que asevera contra la misma cuenta.
 * Sumarlo a mano en cada uno es exactamente la copia latente con la que ya
 * divergieron cuatro reglas en este repositorio.
 *
 * 🔴 EL DENOMINADOR SON TODOS LOS GRADOS EVALUADOS, no solo los que tienen
 * casos: la pregunta es qué parte del alumnado EVALUADO está en riesgo, y
 * limitarla a los grados afectados infla el porcentaje sin avisar.
 *
 * Desde el 23/09/2026 el riesgo es la SITUACIÓN FINAL proyectada, así que el
 * total se abre en sus dos siglas —`PER` (permanece en el grado) y `RR`
 * (requiere recuperación)— y se da también POR NIVEL: la regla del MINEDU es
 * distinta en cada uno, y una cifra global sola no dice qué se sumó.
 *
 * 🔴 `cobertura` NO ES DECORACIÓN. Un bimestre a medio calificar da una
 * proyección optimista —las áreas sin nota no cuentan— y sin declarar sobre
 * cuántas áreas se calculó, un «0 en riesgo» parece una buena noticia cuando es
 * un informe vacío. `parciales` son los ESTUDIANTES a quienes les falta alguna
 * área de SU plan (el suyo, ya descontadas sus exoneraciones), y `completa` es
 * falso en cuanto haya uno.
 *
 * @param  array $porGrado  la salida de `SituacionFinalModel::porGrado()` (o filtrada)
 * @return array{total:int, permanencia:int, recuperacion:int, evaluados:int,
 *               pct:int, grados:int, grados_total:int, max:int, sin_datos:int,
 *               seguimiento:int, cobertura:array, por_nivel:array}
 */
function riesgo_resumen(array $porGrado): array
{
    $total = $evaluados = $grados = $permanencia = $max = 0;
    $sinDatos = $seguimiento = 0;
    $porNivel = [];
    $cobMin    = null;
    $cobPlan   = 0;
    $parciales = 0;
    $seguros = $proPro = $pendFinal = 0;
    $incorporados = $trasladados = $retirados = 0;
    $periodoFinal = false;

    foreach ($porGrado as $g) {
        $n   = (int) ($g['evaluados'] ?? 0);
        $nid = (int) $g['grado']['nivel_id'];
        $porNivel[$nid] ??= [
            'nivel_id'    => $nid,
            'nombre'      => (string) $g['grado']['nivel_nombre'],
            'codigo'      => (string) $g['grado']['nivel_codigo'],
            'total'       => 0,
            'permanencia' => 0,
            'evaluados'   => 0,
        ];

        $evaluados  += $n;
        $sinDatos   += (int) ($g['sin_datos'] ?? 0);
        $seguimiento += count($g['seguimiento'] ?? []);
        $pendFinal  += count($g['pendiente_final'] ?? []);
        $periodoFinal = $periodoFinal || !empty($g['periodo_final']);
        $porNivel[$nid]['evaluados'] += $n;

        $cob = $g['cobertura'] ?? null;
        $proPro       += (int) ($cob['pro_proyectados'] ?? 0);
        $incorporados += (int) ($cob['incorporados'] ?? 0);
        $trasladados  += (int) ($cob['trasladados'] ?? 0);
        $retirados    += (int) ($cob['retirados'] ?? 0);
        if ($cob !== null && $cob['min'] !== null) {
            $cobMin      = $cobMin === null ? (int) $cob['min'] : min($cobMin, (int) $cob['min']);
            $cobPlan     = max($cobPlan, (int) $cob['plan']);
            $parciales  += (int) ($cob['parciales'] ?? 0);
        }

        if (empty($g['en_riesgo'])) {
            continue;
        }

        $grados++;
        $total += count($g['en_riesgo']);
        $porNivel[$nid]['total'] += count($g['en_riesgo']);

        foreach ($g['en_riesgo'] as $al) {
            $max = max($max, (int) $al['num_c']);
            if (($al['certeza'] ?? null) === CERTEZA_SEGURA) {
                $seguros++;
            }
            if (($al['situacion'] ?? '') === SITUACION_PER) {
                $permanencia++;
                $porNivel[$nid]['permanencia']++;
            }
        }
    }

    // Sin evaluados no hay porcentaje: 0 % seria un dato FALSO, no ausente.
    $pct = static fn(int $parte, int $de): int => $de > 0 ? (int) round($parte / $de * 100) : 0;

    foreach ($porNivel as &$niv) {
        $niv['pct'] = $pct($niv['total'], $niv['evaluados']);
    }
    unset($niv);

    return [
        'total'        => $total,
        'permanencia'  => $permanencia,
        'recuperacion' => $total - $permanencia,
        'evaluados'    => $evaluados,
        'pct'          => $pct($total, $evaluados),
        'grados'       => $grados,
        'grados_total' => count($porGrado),
        'max'          => $max,
        'sin_datos'    => $sinDatos,
        // Seguimiento pedagógico (24/09/2026): cifra PROPIA, nunca sumada a
        // `total` ni a `pct`: son promovidos, no estudiantes en riesgo.
        'seguimiento'  => $seguimiento,
        // Certeza (24/09/2026): de los `total` en riesgo, cuántos ya no pueden
        // salvarse aunque lo pendiente salga bien, y cuántos promovidos
        // todavía pueden caer. Ver `situacion_final_proyectar()`.
        'seguros'         => $seguros,
        'proyectados'     => $total - $seguros,
        'pro_proyectados' => $proPro,
        // Fuera del cálculo sin ser un hueco de datos (24/09/2026): quien se
        // incorporó después del bimestre y quien ya no pertenece al colegio
        // (cursó el bimestre y luego se trasladó o se retiró).
        'incorporados'    => $incorporados,
        'trasladados'     => $trasladados,
        'retirados'       => $retirados,
        // Periodo final: ahí ya no se proyecta. `definitiva` = sin pendientes.
        'pendiente_final' => $pendFinal,
        'periodo_final'   => $periodoFinal,
        'definitiva'      => $periodoFinal && $parciales === 0 && $pendFinal === 0,
        'cobertura'    => [
            'min'       => $cobMin ?? 0,
            'plan'      => $cobPlan,
            'parciales' => $parciales,
            'completa'  => $parciales === 0,
        ],
        'por_nivel'    => array_values($porNivel),
    ];
}

/**
 * Quiénes quedan FUERA del cálculo, en frases listas para pintar (24/09/2026).
 * PUNTO ÚNICO del texto: lo usan la banda del informe, la de `/admin/cuadros`,
 * el bloque por sección y el mensaje de «nadie en riesgo». Son tres grupos
 * distintos y no se mezclan:
 *   · sin ninguna competencia evaluada — pertenece al colegio y no tiene notas
 *     (hueco de datos: hace PARCIAL la proyección);
 *   · se incorporó después del bimestre — aún no pertenecía;
 *   · ya no pertenece — cursó el bimestre y luego se trasladó o se retiró.
 *
 * @param  array $res  salida de `riesgo_resumen()`
 * @return string[]    texto plano (la vista escapa)
 */
function riesgo_fuera_del_calculo(array $res): array
{
    $plural = static fn(int $n, string $s, string $p): string => $n === 1 ? $s : $p;
    $out = [];

    $sd = (int) ($res['sin_datos'] ?? 0);
    if ($sd > 0) {
        $out[] = "$sd sin ninguna competencia evaluada (fuera del cálculo)";
    }
    $inc = (int) ($res['incorporados'] ?? 0);
    if ($inc > 0) {
        $out[] = "$inc " . $plural($inc, 'se incorporó', 'se incorporaron')
               . ' después de este bimestre (fuera del cálculo)';
    }
    $tr = (int) ($res['trasladados'] ?? 0);
    $re = (int) ($res['retirados'] ?? 0);
    if ($tr + $re > 0) {
        $partes = [];
        if ($tr > 0) { $partes[] = "$tr " . $plural($tr, 'trasladado', 'trasladados'); }
        if ($re > 0) { $partes[] = "$re " . $plural($re, 'retirado', 'retirados'); }
        $out[] = ($tr + $re) . ' ' . $plural($tr + $re, 'ya no pertenece', 'ya no pertenecen')
               . ' al colegio (' . implode(' · ', $partes) . '), fuera del cálculo';
    }
    return $out;
}

/**
 * Recorta la lista de `SituacionFinalModel::porGrado()` a una selección de
 * SECCIONES, de
 * cualquier grado y nivel (23/09/2026; sustituye al filtro por grados: un grado
 * es la suma de sus secciones).
 *
 * Lo usan la vista de riesgo, su A4, el lote por tutor y el panel del tutor,
 * para que cada superficie describa EXACTAMENTE lo elegido. `$secciones` son
 * filas de `SeccionModel::seccionesDelAnio()` ya validadas: aquí solo se
 * comparan, nunca se interpolan. Lista vacía = todo.
 *
 * Por grado con alguna sección elegida se quedan solo las filas de riesgo y los
 * evaluados de esas secciones, y `evaluados` (denominador del riesgo) pasa a ser
 * su suma. `cobertura` y `sin_datos` se recomponen desde `cobertura_seccion`.
 *
 * Se cruza por (grado, NOMBRE de sección): el ranking en vivo no trae
 * `seccion_id`. La fila de un retorno de grado ya viene reubicada en su sección
 * OFICIAL (lo hace el modelo), así que cae sola en la sección que le corresponde.
 */
function riesgo_filtrar_secciones(array $porGrado, array $secciones): array
{
    if ($secciones === []) {
        return array_values($porGrado);
    }

    $elegidas = [];
    foreach ($secciones as $s) {
        $elegidas[(int) $s['grado_id']][(string) $s['nombre']] = true;
    }

    $out = [];
    foreach ($porGrado as $g) {
        $suyas = $elegidas[(int) $g['grado']['id']] ?? null;
        if ($suyas === null) {
            continue;
        }

        foreach (['en_riesgo', 'seguimiento', 'pendiente_final'] as $lista) {
            $g[$lista] = array_values(array_filter(
                $g[$lista] ?? [],
                static fn(array $al): bool => isset($suyas[(string) $al['seccion_nombre']])
            ));
        }
        $g['por_seccion'] = array_values(array_filter(
            $g['por_seccion'] ?? [],
            static fn(array $s): bool => isset($suyas[(string) $s['seccion_nombre']])
        ));
        $g['evaluados'] = array_sum(array_map(
            static fn(array $s): int => (int) $s['total'],
            $g['por_seccion']
        ));

        // La cobertura y los `sin_datos` se RECOMPONEN con las secciones
        // elegidas (24/09/2026): antes quedaban los del grado entero y el aviso
        // de proyección parcial podía contar más estudiantes que evaluados.
        $cob = ['min' => null, 'max' => 0, 'plan' => 0, 'parciales' => 0, 'pro_proyectados' => 0,
                'incorporados' => 0, 'trasladados' => 0, 'retirados' => 0];
        $sinDatos = 0;
        foreach ($g['cobertura_seccion'] ?? [] as $nombre => $c) {
            if (!isset($suyas[(string) $nombre])) {
                continue;
            }
            $sinDatos         += (int) $c['sin_datos'];
            $cob['parciales'] += (int) $c['parciales'];
            $cob['pro_proyectados'] += (int) ($c['pro_proyectados'] ?? 0);
            foreach (['incorporados', 'trasladados', 'retirados'] as $k) {
                $cob[$k] += (int) ($c[$k] ?? 0);
            }
            $cob['max']        = max($cob['max'],  (int) $c['max']);
            $cob['plan']       = max($cob['plan'], (int) $c['plan']);
            if ($c['min'] !== null) {
                $cob['min'] = $cob['min'] === null ? (int) $c['min'] : min($cob['min'], (int) $c['min']);
            }
        }
        $g['cobertura'] = $cob;
        $g['sin_datos'] = $sinDatos;

        $out[] = $g;
    }

    return $out;
}

/**
 * Un bloque de riesgo POR SECCIÓN (23/09/2026): PUNTO ÚNICO del informe que va
 * a cada tutor. Lo usan el lote de Dirección (`/admin/cuadros/acompanamiento/tutores`,
 * una sección por hoja) y el panel del tutor (una sola sección).
 *
 * Función PURA: recorta con `riesgo_filtrar_secciones()` y calcula con
 * `riesgo_estadisticas()`, así que cada bloque dice exactamente lo de su
 * sección. Una sección sin ranking en el bimestre sale con `evaluados = 0`, y
 * la vista lo distingue de «nadie llega al umbral».
 *
 * @param  array $secciones  filas de `SeccionModel::seccionesDelAnio()`
 * @return array<int, array{seccion:array, filtrado:array, stats:array}>
 */
function riesgo_por_seccion(array $porGrado, array $secciones): array
{
    $bloques = [];
    foreach ($secciones as $s) {
        $filtrado  = riesgo_filtrar_secciones($porGrado, [$s]);
        $bloques[] = [
            'seccion'  => $s,
            'filtrado' => $filtrado,
            'stats'    => riesgo_estadisticas($filtrado),
        ];
    }
    return $bloques;
}

/**
 * Texto del ALCANCE de un informe de riesgo (23/09/2026): «Primaria: 5°, 6° A ·
 * Secundaria: 1° B». Un grado con todas sus secciones elegidas se nombra entero;
 * si no, sección por sección. Sin selección: «Todos los niveles y grados».
 *
 * @param array $niveles  nivel → {nombre, grados: grado → {nombre, secciones[]}}
 * @param int[] $ids      secciones elegidas (ya validadas)
 */
function riesgo_alcance(array $niveles, array $ids): string
{
    if ($ids === []) {
        return 'Todos los niveles y grados';
    }

    $partes = [];
    foreach ($niveles as $niv) {
        $trozos = [];
        foreach ($niv['grados'] as $gr) {
            $todas = array_column($gr['secciones'], 'nombre', 'id');
            $sel   = array_intersect_key($todas, array_flip($ids));
            if ($sel === []) {
                continue;
            }
            $trozos[] = count($sel) === count($todas)
                ? $gr['nombre']
                : $gr['nombre'] . ' ' . implode(', ', $sel);
        }
        if ($trozos) {
            $partes[] = $niv['nombre'] . ': ' . implode(', ', $trozos);
        }
    }
    return implode(' · ', $partes);
}

/**
 * Estadísticas del informe de riesgo (23/09/2026). Función PURA sobre la salida
 * de `SituacionFinalModel::porGrado()` (ya filtrada si corresponde): cero consultas.
 *
 * Devuelve, además del `resumen` de `riesgo_resumen()`:
 *   · `grados`       casos, % y críticos por grado;
 *   · `secciones`    casos y % sobre los EVALUADOS de cada sección (`por_seccion`
 *                    del modelo, que sale del mismo ranking);
 *   · `areas`        por nivel: estudiantes distintos y notas no aprobatorias por área;
 *   · `competencias` por nivel: las 10 con más estudiantes;
 *   · `docentes`     por nivel: estudiantes distintos y notas B/C registradas;
 *   · `criticos`     los que PERMANECEN en el grado, con su grado.
 *
 * ⚠️ Área, competencia y docente cuentan SOLO a los estudiantes de la lista:
 * es «dónde se concentran los casos», no la distribución de notas del colegio.
 */
function riesgo_estadisticas(array $porGrado): array
{
    $pct = static fn(int $parte, int $de): int => $de > 0 ? (int) round($parte / $de * 100) : 0;

    $grados = $secciones = $criticos = [];
    $areas = $comps = $docentes = [];

    // Acumula una nota B/C en su fila de concentración (área, competencia o
    // docente). `est` es un conjunto: un estudiante cuenta una vez por fila.
    $sumar = static function (array &$mapa, string $clave, array $base, int $mid, string $lit): void {
        $mapa[$clave] ??= $base + ['est' => [], 'notas' => 0, 'B' => 0, 'C' => 0];
        $mapa[$clave]['est'][$mid] = true;
        $mapa[$clave]['notas']++;
        $mapa[$clave][$lit]++;
    };

    foreach ($porGrado as $g) {
        $gr    = $g['grado'];
        $nid   = (int) $gr['nivel_id'];
        $casos = count($g['en_riesgo']);

        $casosSec = $critSec = [];
        $crit = 0;
        foreach ($g['en_riesgo'] as $al) {
            $s = (string) $al['seccion_nombre'];
            $casosSec[$s] = ($casosSec[$s] ?? 0) + 1;
            // «De mayor atención» ya no es un umbral de conteo: son los que
            // PERMANECEN en el grado, la situación final más grave que existe.
            if (($al['situacion'] ?? '') === SITUACION_PER) {
                $crit++;
                $critSec[$s] = ($critSec[$s] ?? 0) + 1;
                $criticos[]  = $al + ['grado' => $gr];
            }
        }

        $grados[] = [
            'grado'     => $gr,
            'total'     => (int) ($g['evaluados'] ?? 0),
            'casos'     => $casos,
            'criticos'  => $crit,
            'cobertura' => $g['cobertura'] ?? null,
            'pct'       => $pct($casos, (int) ($g['evaluados'] ?? 0)),
        ];

        // Todas las secciones del ranking, tengan casos o no: una sección en
        // 0 también es un dato.
        foreach ($g['por_seccion'] ?? [] as $sec) {
            $s = (string) $sec['seccion_nombre'];
            $secciones[] = [
                'grado'          => $gr,
                'seccion_nombre' => $s,
                'total'          => (int) $sec['total'],
                'casos'          => $casosSec[$s] ?? 0,
                'criticos'       => $critSec[$s] ?? 0,
                'pct'            => $pct($casosSec[$s] ?? 0, (int) $sec['total']),
            ];
        }

        // El desglose sale de las MISMAS filas que decidieron la situación
        // (`SituacionFinalModel::componerFila`), así que no puede faltar ni
        // contradecir a su propia fila: ya no hace falta el guard del descuadre
        // que necesitaba el informe por agregados del mérito.
        foreach ($g['en_riesgo'] as $al) {
            $mid = (int) $al['matricula_id'];
            foreach ($al['detalle'] as $d) {
                $areas[$nid]    ??= [];
                $comps[$nid]    ??= [];
                $docentes[$nid] ??= [];

                $sumar($areas[$nid], $d['area'], ['nombre' => $d['area']], $mid, $d['literal']);
                $sumar($comps[$nid], $d['area'] . '|' . $d['competencia'], [
                    'nombre' => $d['competencia'], 'area' => $d['area'],
                    'curso'  => $d['curso'],       'codigo' => $d['codigo'],
                ], $mid, $d['literal']);
                $sumar($docentes[$nid], $d['docente'], ['nombre' => $d['docente']], $mid, $d['literal']);
            }
        }
    }

    // `est` pasa de conjunto a conteo, y se ordena: más estudiantes primero; a
    // igual, más notas; a igual, por nombre (orden estable y legible).
    $cerrar = static function (array $porNivel, int $tope = 0): array {
        $out = [];
        foreach ($porNivel as $nid => $filas) {
            $filas = array_map(static function (array $f): array {
                $f['estudiantes'] = count($f['est']);
                unset($f['est']);
                return $f;
            }, array_values($filas));

            usort($filas, static fn($a, $b) =>
                [$b['estudiantes'], $b['notas'], $a['nombre']] <=> [$a['estudiantes'], $a['notas'], $b['nombre']]);

            $out[$nid] = $tope > 0 ? array_slice($filas, 0, $tope) : $filas;
        }
        return $out;
    };

    // Permanencias: por nivel y grado; dentro, el mismo orden que la lista.
    usort($criticos, static fn($a, $b) =>
        [(int) $a['grado']['nivel_id'], (int) $a['grado']['numero'], (int) $b['num_c'], (int) $b['num_b']]
        <=> [(int) $b['grado']['nivel_id'], (int) $b['grado']['numero'], (int) $a['num_c'], (int) $a['num_b']]);

    return [
        'resumen'      => riesgo_resumen($porGrado),
        'grados'       => $grados,
        'secciones'    => $secciones,
        'areas'        => $cerrar($areas),
        'competencias' => $cerrar($comps, 10),
        'docentes'     => $cerrar($docentes),
        'criticos'     => $criticos,
    ];
}

/** Formatea una fecha en español peruano */
function fecha_es(string $fecha): string
{
    $meses = [
        1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',
        5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',
        9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'
    ];
    $ts = strtotime($fecha);
    return date('d', $ts) . ' de ' . $meses[(int)date('m', $ts)] . ' de ' . date('Y', $ts);
}

/** Retorna el usuario autenticado actual */
function auth(): ?array
{
    return \Core\Session::user();
}

/** Verifica si el usuario tiene un rol dado */
function has_role(string|array $roles): bool
{
    return \Core\Session::hasRole($roles);
}

/** Log de errores simple */
function log_error(string $mensaje, array $context = []): void
{
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje;
    if ($context) {
        $linea .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    // Ruta del log fuera del docroot en produccion (config 'log_path'). El
    // logging nunca debe tumbar la app: mkdir y escritura son defensivos, con
    // fallback silencioso si el directorio no es escribible.
    $destino = config('log_path') ?: (STORAGE_PATH . '/logs/siga.log');
    $dir     = dirname($destino);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @error_log($linea . PHP_EOL, 3, $destino);
}

/**
 * Estado de la boleta de un bimestre, DERIVADO del periodo (no es una columna):
 *   'registro' -> activo y boletas NO aprobadas       -> aun no hay boleta visible
 *   'borrador' -> activo y boletas aprobadas (Hito A)  -> vista previa (docentes)
 *   'oficial'  -> bimestre cerrado (Hito B)            -> oficial (docentes + padres)
 * La reapertura (cerrado -> activo conservando el flag) vuelve a 'borrador'.
 */
function boleta_estado_bimestre(?string $estadoPeriodo, ?string $boletasAprobadasEn): string
{
    if ($estadoPeriodo === 'cerrado') {
        return 'oficial';
    }
    if ($estadoPeriodo === 'activo' && !empty($boletasAprobadasEn)) {
        return 'borrador';
    }
    return 'registro';
}

/**
 * Renderiza una página de error genérica y detiene el flujo normal. La usa el
 * manejador global de errores en producción para no filtrar stack traces ni
 * errores de base de datos al usuario. Idempotente: nunca imprime dos veces.
 */
function render_error_page(int $code = 500): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    // Descarta cualquier salida parcial para que la página de error salga limpia.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($code);
    }

    $vista = VIEW_PATH . '/shared/500.php';
    if (is_file($vista)) {
        require $vista;
    } else {
        echo 'Ha ocurrido un error. Intenta de nuevo mas tarde.';
    }
}
