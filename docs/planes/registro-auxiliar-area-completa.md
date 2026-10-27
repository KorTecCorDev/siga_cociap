# Plan: registro auxiliar con matriz partida y registro del área completa

> **PARA EL TURNO TARDE (27/10/2026).** Este plan es la continuación de un trabajo que **ya
> existe en `dev` SIN COMMIT**. Antes de implementar, leer la sección **«Estado de partida»**
> (al final): lo hecho, las decisiones cerradas, las trampas medidas y las herramientas de
> prueba (con su código, porque el scratchpad de la sesión se pierde).
> Copiado al repo el 27/10/2026 para que viaje entre equipos. **Al terminar de implementarlo,
> pasar lo vigente a `docs/modulos/calificaciones.md` § Registro auxiliar y BORRAR este archivo**
> (los planes con fecha no se quedan en `docs/`: el estado vive en `ESTADO.md`).
> ⚠️ El trabajo «ya construido» que describe está **sin commit** en el equipo donde se hizo
> (la laptop del 27/10/2026, mañana): en otro equipo NO existe hasta que se haga commit y pull.

## Contexto

El registro auxiliar ya tiene el formato del colegio (REG-NUM + CD): una hoja por sección +
área, con las competencias de otras cargas en blanco. El usuario pide que el diseño también
sirva para el **área completa**, con todas las cargas y docentes, aunque haya muchos criterios.

Medido en la BD local, para un área completa:
- llega a **36 criterios** (Matemática de 2.º B de primaria, I Bimestre: 4 competencias, 4
  cargas), y a 30 en el II Bimestre;
- el tope teórico es 5 competencias × 10 criterios = 50;
- hay hasta 4 cargas por área (8 columnas de transversales) y hasta 28 estudiantes.

El ancho que necesitaría ese caso es 63 (N° + nombre) + 36×5 + 4×8 (logros) + 4×6,5
(resumen) + 8×9 (transversales) = **373 mm**. La hoja A4 horizontal útil mide **277 mm**: una
sola matriz no cabe.

Decisiones del usuario (27/10/2026):

| Tema | Decisión |
|---|---|
| Áreas anchas | **Mismo diseño, partido**: si el área cabe, una sola matriz como hoy; si no, se reparte en «partes» por **competencias enteras** (nunca se corta una) |
| Cada parte | Repite N° y nombres y dice «Parte X de Y» |
| Resumen y transversales | En la última parte, con el pie y las firmas |
| Área completa: quién | **Dirección, RA y admin**, desde `/consulta-notas` (solo lectura). El docente sigue viendo solo sus notas |
| Área completa: firmas | **Cada docente del área** + Dirección EBR + Dirección General |
| Alcance | **Las dos cosas**: matriz partida (sirve también al registro del docente) + registro del área completa |

## Enfoque

### 1. El armado de la hoja pasa a un Model compartido (no se duplica)

Hoy vive en métodos privados de `Docente\CalificacionController`. Lo van a usar dos
controladores, así que se muda a `app/Models/RegistroAuxiliarModel.php` (nuevo):

- **`hoja(array $meta, array $cargas, array $periodo): array`**: lo que hoy hace
  `hojaRegistroAuxiliar()`, sin cambios de lógica. Recibe la lista de cargas que llenan la
  hoja: en el registro del docente, sus cargas elegidas; en el del área, **todas** las de esa
  área y sección.
- **`bloquesBloqueadosDeCarga()`** se muda a este Model. El método privado del controlador
  queda como **delegado de una línea**, así que `historial()`, `historialArea()` y
  `procedencia()` no cambian. Necesita `CalificacionModel`, `OmisionCriterioModel` y
  `ExoneracionModel`.
- **`cargasDeSeccion(int $seccionId): array`** (nueva consulta): cargas activas de la sección
  con los mismos filtros que `getCargas()`:
  - sin la carga transversal antigua;
  - Tutoría/Ética solo si tiene competencias;
  - con el nombre del docente.
- **`partesMatriz(array $columnas, int $nTransversales): array`** y
  **`partesConclusiones(array $propias, int $nTransversales): array`**: funciones puras de
  reparto («empaquetado» goloso por competencias enteras).
  - **Matriz:**
    - presupuesto de 277 mm − 63 fijos;
    - cada competencia propia ocupa n×5 + 8 mm, y una en blanco 7 mm;
    - el bloque final (Resumen 6,5 mm × competencias + transversales 9 mm × columnas) debe
      caber en la última parte; si no cabe, va solo en una parte final.
  - **Conclusiones (CD):** como máximo 3 competencias por parte (logro de 14 mm + conclusión
    de al menos ~55 mm), y las transversales en la última.
  - Las medidas en mm se declaran como **constantes con un comentario que apunta al SASS**
    (`.ra-m__c-*`): si cambian allí, cambian aquí.

`Docente\CalificacionController::registroAuxiliarImprimir()` pasa a usar
`RegistroAuxiliarModel::hoja()` y conserva sus guardas: solo cargas propias y claves validadas.

### 2. Vistas: de `docente/` a `shared/registro-auxiliar/`, con partes

Los parciales `_registro-auxiliar-{cabecera,matriz,cd}.php` pasan a
`resources/views/shared/registro-auxiliar/`, porque los usan dos módulos. El documento
(`registro-auxiliar-imprimir.php`) también pasa a `shared/`, con una variable `$modo`
(`'docente'` | `'area'`).

- **Matriz:** un bucle sobre las partes de `partesMatriz()`. Cada parte es una tabla con
  `break-before: page` (salvo la primera) y repite:
  - la fila de identificación del `<thead>`, con «Parte X de Y» cuando hay más de una;
  - N° y nombres.

  La leyenda (competencias + tabla de criterios + escala) va **una sola vez**, en la primera
  parte. El pie (estadísticas, lugar y fecha, firmas) va **solo tras la última parte**.
- **CD:** el mismo esquema con `partesConclusiones()`.
- **Cabecera en modo área:** en vez de «Docente», la fila **Docentes**:
  «Apellidos, Nombres (Aritmética, Álgebra) · Apellidos, Nombres (Geometría, Razonamiento
  Matemático)».
- **Firmas en modo área:** una por cada docente distinto, más Dirección EBR y Dirección
  General. Con 6 firmas son dos filas de 3: en el pie, `.ra-firmas` vuelve a permitir el
  `wrap`, solo en este modo.
- **Transversales en modo área:** una columna por carga y competencia, rotulada con la
  subárea, o con el docente si dos cargas no tienen subárea. Es la misma regla actual
  (`$hayVariasTr`).

### 3. Registro del área completa (Dirección, RA, admin)

- **Ruta:** `GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/registro-auxiliar`,
  registrada **antes** del patrón de 4 segmentos, igual que `/transversales`.
  - Parámetro opcional `?area_id=` para imprimir una sola área.
  - Sin ese parámetro, imprime todas las áreas de la sección, cada una en hoja nueva.
- **`ConsultaNotasController::registroAuxiliar()`:**
  - hereda el `requireRole` del constructor (`admin`, `registro_academico`, `ROLES_DIRECCION`);
  - valida el periodo con `getPeriodo()` y la sección contra las cargas reales (404 si no hay);
  - agrupa `cargasDeSeccion()` por área → `RegistroAuxiliarModel::hoja()` por grupo;
  - usa el layout `print` con `doc-landscape` y el mismo documento compartido en
    `$modo = 'area'`.
- **Entrada:** un botón «Registro auxiliar de la sección» en la cabecera del grupo «Áreas y
  cargas» de `consulta-notas/seccion.php`, más un enlace «Registro auxiliar» por área en esa
  lista. Los dos abren en ventana nueva (`target="_blank"`, patrón del interceptor de `app.js`).

### 4. SASS (`resources/sass/pages/_registro-auxiliar.scss`) + `gulp build`

- `.ra-parte + .ra-parte { break-before: page; }`.
- El rótulo «Parte X de Y» va en la fila de identificación.
- En modo área, `.ra-firmas--varias { flex-wrap: wrap; }` y bloques al 30 %, como el
  `.reporte-footer` compartido.
- Sin `@page` nueva y sin CSS inline (la barra de estadísticas ya no se usa aquí).

### 5. Documentación

- `docs/modulos/calificaciones.md` § Registro auxiliar:
  - las partes y el presupuesto en mm;
  - el modo área (quién, ruta, firmas) y el Model compartido;
  - la medición de los 373 mm.
- `docs/ESTADO.md`: los pendientes de prueba.

## Archivos

- **Nuevos:**
  - `app/Models/RegistroAuxiliarModel.php`
  - `resources/views/shared/registro-auxiliar/{documento,_cabecera,_matriz,_cd}.php`
- **Modificados:**
  - `app/Controllers/Docente/CalificacionController.php`: delega en el Model; se quitan
    `hojaRegistroAuxiliar` e `indexarPorMatricula`.
  - `app/Controllers/Consulta/ConsultaNotasController.php`: `registroAuxiliar()`.
  - `routes/web.php`: una ruta.
  - `resources/views/consulta-notas/seccion.php`: los botones.
  - `resources/sass/pages/_registro-auxiliar.scss`
  - los docs.
- **Eliminados:** `resources/views/docente/_registro-auxiliar-{cabecera,matriz,cd}.php` y
  `docente/registro-auxiliar-imprimir.php` (pasan a `shared/`). Todos son archivos de esta
  misma sesión y ninguno está en git.

## Verificación

1. `php -l` de todo lo tocado y `gulp build`.
2. **No regresión del historial:** el arnés de solo lectura (scratchpad) compara la salida de
   `historial` y `historialArea` de las 3 cargas ya medidas antes y después del cambio:
   debe ser idéntica byte a byte.
3. **Registro del docente:**
   - repetir la comparación matriz ↔ historial (133 filas, 0 diferencias);
   - comprobar que las celdas en blanco siguen sin datos.
4. **Área completa:** con Matemática de 2.º B de primaria, I Bimestre (36 criterios), y
   Matemática de 6.º A, II Bimestre (30 criterios, 2 docentes):
   - las notas de cada competencia coinciden con `/consulta-notas/{p}/carga/{c}` de su carga;
   - aparecen «Parte 1 de N»…;
   - medir con Chrome headless (inyectando en una copia del HTML un script que escriba
     `scrollWidth` contra `clientWidth`) que **ninguna tabla desborda** el ancho de la hoja;
   - imprimir a PDF unas **3 veces** como máximo, con `timeout`, y revisar por hoja: ninguna
     fila partida, identificación y «Parte X de Y», pie con firmas solo tras la última parte,
     «Página X de Y».
5. **Permisos:** un docente que pide la ruta de consulta recibe 403 (constructor). Una sección
   inexistente o sin cargas da 404.
6. **Navegador:** pedir al usuario que pruebe con una sesión de Dirección o RA el botón de
   `/consulta-notas`, y con una sesión de docente el registro propio.

Casos de prueba de la BD local (ids medidos el 27/10/2026):

| Caso | Datos |
|---|---|
| Área más ancha (36 criterios, 1 docente) | Matemática de **2.º B** de primaria, **periodo 1**: sección **4**, cargas 123 Aritmética, 124 Álgebra, 126 Geometría, 127 Razonamiento Matemático (docente **26**) |
| Área repartida entre 2 docentes (30 criterios) | Matemática de **6.º A** de primaria, **periodo 2**: sección **11**, cargas 169 y 170 (docente **30**), 208 y 209 (docente **31**) |
| Usuarios para el modo área | admin = 1, director_ebr = 35, registro_academico = 40 |

---

# Estado de partida (TODO esto ya existe en `dev`, SIN COMMIT)

## Qué está construido

| Pieza | Archivo | Estado |
|---|---|---|
| Rutas | `routes/web.php`, después de `/docente/mis-cargas`: `GET /docente/registro-auxiliar/imprimir` (primero) y `GET /docente/registro-auxiliar` | hecho |
| Controlador (docente) | `app/Controllers/Docente/CalificacionController.php` | hecho |
| | — `registroAuxiliar()`: pantalla de selección | |
| | — `registroAuxiliarImprimir()`: agrupa por sección + área y arma las firmas | |
| | — `periodosRegistro()` y `periodoRegistroElegido()` | |
| | — `entradasRegistro()`: cards por carga; Mi aula = una card por área; estado de la card COPIADO de `mis-cargas.php` | |
| | — `hojaRegistroAuxiliar()` e `indexarPorMatricula()`: **se mudan al Model en este plan** | |
| | — se añadió `'id'` a `bloquesBloqueadosDeCarga()` | |
| | — imports nuevos: `DirectorEbrModel`, `UsuarioModel`, `Core\View` | |
| Model | `app/Models/UsuarioModel.php::nombreUnicoActivoPorRolCodigo()`: nombre si hay exactamente 1 usuario activo del rol; si no, null | hecho |
| Pantalla de selección | `resources/views/docente/registro-auxiliar.php`: cards `.carga-item` como `<label>` con check visible; «Marcar todas» por grupo; «Imprimir seleccionadas» / «Imprimir todas mis cargas» con `formtarget="_blank"` | hecho |
| JS | `resources/js/registro-auxiliar.js` → `public/js/registro-auxiliar.js` (gulp). Habilita el botón y controla el atajo por grupo | hecho |
| Documento | `resources/views/docente/registro-auxiliar-imprimir.php` (orquesta), `_registro-auxiliar-cabecera.php`, `_registro-auxiliar-matriz.php` (leyenda + tabla de criterios + matriz + pie), `_registro-auxiliar-cd.php` | hecho; **pasa a `shared/registro-auxiliar/` en este plan** |
| Botón de entrada | `resources/views/docente/mis-cargas.php`: «Registro auxiliar» en el `.page-header` | hecho |
| SASS | `resources/sass/pages/_registro-auxiliar.scss` (importado en `app.scss` tras `cuadros-riesgo`): `.ra-sel`, `.ra-card`, `.ra-print`, `.ra-cab`, `.ra-tag`, `.ra-leyenda`, `.ra-crit`, `.ra-m`, `.ra-cierre`, `.ra-stats`, `.ra-cd` | hecho |
| Docs | `docs/modulos/calificaciones.md` § «Registro auxiliar de calificaciones», `docs/modulos/ui.md` (nota de numeración), `docs/ESTADO.md` (entrada 🆕 al inicio) | hecho; **actualizar al implementar** |
| Eliminado | `resources/views/docente/_registro-auxiliar-tabla.php` (la versión vertical anterior) | hecho |

`git status` esperado: los archivos de arriba, más `public/css/app.css` recompilado y
`public/js/calificaciones.js` (este último solo difiere en fines de línea y ya estaba así
antes de la sesión). Además, sin versionar: `public/REG_AUX_PRIMARIA_2026.xlsm` y
`public/REG_AUX_SECUNDARIA_2026.xlsm` (⚠️ NO SUBIR) y 12 `.svg` de `public/assets/icons/`
que no son de esta tarea.

## Decisiones cerradas del usuario (no re-preguntar)

1. **Solo lo OFICIAL**: competencias bloqueadas (`bloqueos_competencia`), igual que el historial.
2. **Formato = modelo del colegio** (`REG_AUX_*_2026.xlsm`, hojas REG-NUM y CD):
   - una hoja por sección + área;
   - competencias como grupos de columnas (Cr1…CrN + Logro);
   - Resumen (literal) y Comp. transv.;
   - pie con «Proceso de logro» (matriculados, evaluados, no evaluados, exonerados si hay,
     aprobados, desaprobados) y «Niveles de logro» AD·A·B·C por competencia, más lugar y
     fecha y firmas;
   - la hoja CD de conclusiones, aparte.

   No se reproduce, porque el sistema no lo guarda: la «Evaluación diagnóstica», las
   «Unidades» ni el «Cuadro de capacidades y desempeños».
3. **En el registro del DOCENTE, las competencias de otra carga van EN BLANCO**, sean de otro
   docente o suyas sin marcar. Nunca se lee una nota de una carga que no esté en la hoja.
   - Tipos de columna: `propia` · `noeval` («No evaluada») · `pendiente` («Aún no aprobada») ·
     `otra` («Otra carga»).
4. **Selección del docente: una card por carga**; un área de Mi aula, una card con todas sus
   cargas. Al imprimir, las cargas marcadas de la misma área y sección se juntan.
5. **Firmas del docente**: Docente, Dirección EBR (titular vigente,
   `DirectorEbrModel::getVigenteEnFecha`) y Dirección General (el único usuario activo con
   ese rol). Sin usuario, solo el cargo. **Sin imagen de firma.**
6. **Conclusiones**: en hoja CD por área. Por estudiante, logro (número y literal) y
   conclusión de cada competencia propia. De las transversales, solo el logro, con la nota
   «La conclusión descriptiva de las competencias transversales la registra el tutor de la
   sección».
7. **Criterios de evaluación en TABLA** a todo el ancho (`.ra-crit`): una fila por competencia
   propia, una columna por criterio Cr1…CrN (los rótulos de la matriz), ancho fijo, letra de
   5,5 pt. La leyenda va en bloques apilados; las competencias del área en 2 columnas de texto.
8. **«Aprobados» usa la regla del sistema** (`nota_es_aprobatoria` / `stats_competencia`,
   primaria AD+A), NO la del Excel (≥ 11 en los dos niveles).
9. **Numeración continua** (`doc-landscape` → `@page paisaje` con `pie-paginacion`); **NO**
   `doc-lote`.
10. **Este plan**: matriz partida por competencias enteras; área completa para Dirección, RA
    y admin desde `/consulta-notas`; firmas de cada docente + EBR + General.

## Mediciones ya hechas (BD local, 27/10/2026)

- **Datos:** la matriz coincide con el historial en 133 filas de 3 casos, sin diferencias:
  - carga 163 de la docente 30 (C21), periodo 2;
  - área de aula `a1-6` de la docente 21 (C16, C17, C18);
  - carga 131 de la docente 27 (con exonerados).
- **Celdas en blanco:** 778, sin ningún dato.
- **Cards de selección:** idénticas a las de Mis cargas en 4 docentes (24, 21, 27, 29) y 3
  bimestres.
- **Impresión:** en 12 áreas de 18 a 24 estudiantes, con la tabla de criterios a 5,5 pt, el
  pie cabe en la hoja de la matriz en **7**. En las demás pasa entero a la hoja siguiente,
  identificado (aceptado por el usuario como costo de la tabla).
- **El área más ancha del registro del docente** (Comunicación de 1.º A: 9 + 6 + 5 criterios)
  cabe en el ancho.
- **Seguridad:** claves ajenas descartadas; 404 si no queda ninguna; un periodo inválido o
  pendiente cae al activo.

## 🔴 Trampas encontradas (no repetirlas)

- **El parcial `shared/_stats-competencia.php` define `$columnas`, `$stats`, `$pct`…** y pisa
  las variables de quien lo incluye. Hoy ya no se usa en el registro, pero si vuelve, sus
  nombres chocan. Por eso cada parcial se incluye desde un closure
  (`$pintarMatriz`, `$pintarConclusiones`, `$pintarExtraordinarias`).
- **Chrome NO repite un `<thead>` que ocupe ~¼ de la hoja.** Por eso el `<thead>` de la
  matriz lleva solo códigos y `Cr1…CrN`; los nombres van en la leyenda y en la tabla de
  criterios. Su primera fila (`.ra-m__id`) identifica el área en cada hoja de continuación.
- **El pie es indivisible** (`.ra-cierre`, `break-inside: avoid`) y va en **dos columnas**
  (estadísticas | identificación, lugar, fecha y firmas). Apilado medía ~50 mm y se iba solo.
  Las firmas, en una fila (`.ra-firmas` sin `wrap`); en modo área necesitan `wrap`.
- **`.reporte-footer` es compartido** (`_reporte-merito.scss`, lo usan asistencia, conducta y
  el acta de desempates). Los ajustes van bajo `.ra-cierre`, nunca en el componente.
- **En `thead`, la especificidad**: `.ra-m thead th` es (0,1,2); los grupos usan
  `thead .ra-m__grupo` (0,2,1) para no necesitar `!important`.
- **Columna de nombres a 58 mm**: con 46 mm la mitad partía en dos líneas.
- **Archivos con CRLF**: `CalificacionController.php`, `routes/web.php`, `mis-cargas.php`,
  `app.scss` y los `docs/*.md`. Al editar con scripts, conservar `\r\n`. La herramienta Edit
  lo respeta.
- **En este Windows, `chrome.exe --version` ABRE EL NAVEGADOR y cuelga el comando**: no usarlo.
- **El heredoc de Bash altera las barras invertidas** (`'Core\\'` → `'Core\'`): escribir los
  `.php` de prueba con la herramienta Write, no con `cat <<EOF`.
- **`pdftotext` devuelve Latin-1**: leer con `encoding='latin-1'`.
- **El `grep -vc $'\r$'` dio un falso aviso de CRLF**: para contar finales de línea, usar
  Python sobre bytes.
- **Cuidar la carga del equipo**: pocos `print-to-pdf`, cada uno con `timeout 120`.

## Herramientas de prueba (el scratchpad se pierde: recrearlas con Write)

### `render.php`: renderiza cualquier método del controlador sin login (solo lectura)

```php
<?php
// Uso: php render.php <usuario_id> <periodo_id> <metodo> [e1,e2|todas|carga|sec-area]
$root = 'C:/xampp/htdocs/siga_cociap';
define('ROOT_PATH', $root);
define('APP_PATH',  ROOT_PATH . '/app');
define('CORE_PATH', ROOT_PATH . '/core');
define('VIEW_PATH', ROOT_PATH . '/resources/views');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');
spl_autoload_register(function (string $class): void {
    $map = [
        'Core\\'             => CORE_PATH . '/',
        'App\\Controllers\\' => APP_PATH . '/Controllers/',
        'App\\Models\\'      => APP_PATH . '/Models/',
        'App\\Siagie\\'      => APP_PATH . '/Siagie/',
    ];
    foreach ($map as $p => $b) {
        if (str_starts_with($class, $p)) {
            $f = $b . str_replace('\\', '/', substr($class, strlen($p))) . '.php';
            if (file_exists($f)) { require_once $f; return; }
        }
    }
});
require_once CONFIG_PATH . '/app.php';
require_once APP_PATH . '/Helpers/helpers.php';
date_default_timezone_set(config('timezone'));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/siga_cociap/public/index.php';   // sin esto, url() arma rutas rotas
[$_x, $uid, $pid, $metodo] = $argv;
$sel = $argv[4] ?? 'todas';
$pdo = new PDO('mysql:host=localhost;dbname=siga_cociap;charset=utf8mb4', 'root', '');
$u = $pdo->query("SELECT u.id, u.persona_id, p.dni, p.nombres, p.apellido_paterno, p.apellido_materno, r.codigo AS rol_codigo, p.sexo
    FROM usuarios u JOIN personas p ON p.id = u.persona_id JOIN roles r ON r.id = u.rol_id WHERE u.id = " . (int) $uid)->fetch(PDO::FETCH_ASSOC);
$_SESSION = ['auth_user' => $u, '_csrf_token' => 'x'];
$_GET['periodo_id'] = $pid;
if ($sel === 'todas') { $_GET['todas'] = '1'; } else { $_GET['e'] = explode(',', $sel); }
$c = new App\Controllers\Docente\CalificacionController();
if ($metodo === 'historial') {
    $c->historial((string) $sel, (string) $pid);
} elseif ($metodo === 'historialArea') {
    [$sec, $area] = explode('-', $sel);
    $c->historialArea($sec, $area, (string) $pid);
} else {
    $c->$metodo();
}
```

**Para el modo área**, añadir una rama que instancie
`App\Controllers\Consulta\ConsultaNotasController` y llame a
`registroAuxiliar((string) $pid, (string) $seccionId)`, con `$_GET['area_id']` opcional.
Usar el usuario 40 (RA) o el 35 (Director EBR).

Ejemplos:
- `php render.php 30 2 registroAuxiliarImprimir c163 > r163.html`
- `php render.php 30 2 historial 163 > h163.html`
- `php render.php 21 2 historialArea 1-6`
- `php render.php 24 2 misCargas`

### `comparar2.php`: notas de la matriz contra el historial de la misma carga

- **Historial:** recorre cada `div.card.mb-lg` (código en `.competencia-card__codigo`) y sus
  filas de `table.tabla-resumen/tbody/tr`. Toma las celdas 2 a n−2: criterios, numeral y
  literal.
- **Registro:** toma la primera `table.ra-m` que no sea `.ra-cd__tabla`.
  - Reconstruye la posición de cada grupo desde `thead tr.ra-m__grupos th`, sumando los
    `colspan` y saltando las 2 primeras.
  - Los grupos que no son `--vacio`, `--res` ni `--tr` son competencias propias.
  - La columna de Resumen sale del orden de `th.ra-m__res` en `tr.ra-m__cols`.
  - En cada `tbody tr`, une criterios + logro + literal del resumen.
  - Normaliza las siglas de omisión `\b(AI|AJ|AR|NA)\b` → «—».
- **Comparación:** por código y nombre de estudiante. Imprime
  `competencias=… filas=… diferencias=…`.
- ⚠️ Con la matriz partida hay **varias** `table.ra-m`: recorrerlas todas y saltar en cada una
  la fila `tr.ra-m__id`.

### `hojas2.py`: resumen por hoja del PDF (`pdftotext -layout x.pdf x.txt`)

- Divide el texto por `\f` y lee en Latin-1.
- Por hoja:
  - rango de filas: líneas que empiezan con `\d{1,2}\s+[A-ZÁÉÍÓÚÑ]`;
  - marcas: MATRIZ (contiene «EVALUACI» y «REGISTRO AUXILIAR»), CD («CONCLUSIONES
    DESCRIPTIVAS»), thead («Apellidos y nombres»), stats («Proceso de logro») y FIRMAS
    («Direcci» y «EBR»);
  - pie: `P.gina (\d+) de (\d+)`;
  - área: `(?:rea curricular|Taller):\s*(.+?)\s{2,}`.
- Para comprobar la identificación de cada hoja: buscar
  `(Registro auxiliar|Conclusiones descriptivas) · (.+)` o la identificación del pie.

### Comandos

- **Imprimir a PDF:**
  `timeout 120 "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="X.pdf" "file:///X.html"`
- **Captura de pantalla:** igual, con
  `--hide-scrollbars --window-size=1200,1700 --screenshot="X.png"`, y luego leer el PNG.
- **Medir el desborde (nuevo en este plan):**
  - copiar el HTML;
  - inyectar antes de `</body>` un script que, por cada `table.ra-m`, escriba en
    `document.title` (o en un `<pre id="medida">`) su `scrollWidth`, su `clientWidth` y el
    ancho de su contenedor;
  - ejecutar `chrome --headless=new --dump-dom file:///copia.html` y buscar el resultado.
- **Lint:** `/c/xampp/php/php.exe -l <archivo>`.
- **Build:** `npx gulp build`. Las advertencias `darken`/`lighten` son de otros archivos.
- **BD:** `/c/xampp/mysql/bin/mysql.exe -uroot siga_cociap -e "…"` (solo lectura).

## Pendientes que siguen abiertos (además de este plan)

- [ ] Prueba en navegador con sesión de **docente**: selección, «Imprimir seleccionadas»,
  «Imprimir todas», ventana nueva y «✕ Cerrar», y que la pantalla de espera no quede fija.
- [ ] Prueba con sesión de **Dirección o RA** del modo área (este plan).
- [ ] Revisar a ojo una hoja impresa real (colores AD·A·B·C y legibilidad de la tabla de
  criterios a 5,5 pt).
- [ ] Opción ofrecida y **no decidida**: mover la tabla de criterios al final del área, para
  que el pie quepa más veces con la matriz. Preguntar solo si el usuario la retoma.
- [ ] Commit en `dev`, en Conventional Commits en español sin tildes. Desplegar SOLO
  preguntando antes de mergear `dev` → `main`.
- [ ] ⚠️ Recomendar mover los `.xlsm` fuera de `public/`: tienen las listas de estudiantes en
  hojas ocultas.
