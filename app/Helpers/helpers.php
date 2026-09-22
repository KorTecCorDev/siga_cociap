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

    $clave = str_starts_with(strtolower($nivelCodigo), 'prim') ? 'prim' : 'sec';

    return in_array($literal, LITERALES_APROBATORIOS[$clave], true);
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
 * Agregado de "estudiantes en riesgo" sobre lo que ya devolvió
 * `OrdenMeritoModel::statsPorGrado` (PUNTO ÚNICO, 07/09/2026).
 *
 * Es una función PURA: no consulta nada, solo recorre en memoria la lista de
 * grados que la vista ya recibió. Vive aquí, junto a `stats_competencia()`, y no
 * en el modelo ni en el controlador, porque la necesitan TRES sitios de
 * `/admin/cuadros` que no pueden llamarse entre sí:
 *   · el índice de anclas de `index.php`, que pinta la cifra ANTES de la sección,
 *   · la banda de `_estudiantes-riesgo.php`, compartida por pantalla y A4,
 *   · `verif_direccion_superficies.php`, que asevera contra la misma cuenta.
 * Sumarlo a mano en cada uno es exactamente la copia latente con la que ya
 * divergieron cuatro reglas en este repositorio.
 *
 * 🔴 EL DENOMINADOR SON TODOS LOS GRADOS CON RANKING, no solo los que tienen
 * casos: la pregunta es qué parte del alumnado EVALUADO está en riesgo, y
 * limitarla a los grados afectados infla el porcentaje sin avisar (en B1, 20 %
 * contra 20 %; en un bimestre con dos grados afectados de once, la diferencia
 * es de cinco veces).
 *
 * @param  array $porGrado  `$bloques['merito']['por_grado']` tal cual
 * @return array{total:int, evaluados:int, pct:int, grados:int, grados_total:int, max_c:int}
 */
function riesgo_resumen(array $porGrado): array
{
    $total = $evaluados = $grados = $maxC = 0;

    foreach ($porGrado as $g) {
        $evaluados += (int) ($g['total'] ?? 0);

        if (empty($g['en_riesgo'])) {
            continue;
        }

        $grados++;
        $total += count($g['en_riesgo']);

        foreach ($g['en_riesgo'] as $al) {
            $maxC = max($maxC, (int) $al['num_c']);
        }
    }

    return [
        'total'        => $total,
        'evaluados'    => $evaluados,
        // Sin evaluados no hay porcentaje: 0 % seria un dato FALSO, no ausente.
        'pct'          => $evaluados > 0 ? (int) round($total / $evaluados * 100) : 0,
        'grados'       => $grados,
        'grados_total' => count($porGrado),
        'max_c'        => $maxC,
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
