# Módulo: Administración (usuarios, secciones, Director EBR, panel de bloqueos)

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.

## Módulo de gestión de usuarios (sesión 3)
- **Rutas:** `GET/POST /admin/usuarios`, `/admin/usuarios/crear`, `/{id}/editar`, `/{id}/estado`
- **Rol requerido:** solo `admin`
- **Controlador:** `app/Controllers/Admin/UsuarioController.php`
- **Vistas:** `resources/views/admin/usuarios/` (index.php, crear.php, editar.php)
- **SASS:** `resources/sass/pages/_admin.scss`

### Operaciones del CRUD
- **index** — tabla con avatar de iniciales coloreado por rol, badges de rol/estado,
  último acceso, botones Editar y Activar/Desactivar
- **crear** — formulario en grid responsivo (1→2→3 col); secciones: datos personales + acceso
- **editar** — igual al crear con valores precargados; contraseña opcional (vacío = no cambia)
- **toggleEstado** — alterna activo/inactivo con `IF(estado='activo','inactivo','activo')`

### Protecciones del servidor
- No puedes desactivar tu propia cuenta
- No puedes cambiar tu rol si eres el único admin activo
- No puedes desactivar al último admin activo
- DNI único: `existeDni()` excluye el propio ID al editar

### Nuevos métodos en UsuarioModel
`findById`, `listarRoles`, `existeDni`, `crearConPersona`, `actualizarConPersona`,
`toggleEstado`, `contarPorRolCodigo`

### Convenciones del formulario de usuario
- Apellidos y nombres se almacenan en `mb_strtoupper()` (mayúsculas)
- Correo y teléfono se almacenan como `NULL` si el campo viene vacío
- Contraseña: bcrypt cost=12, mínimo 8 caracteres

## Módulo de secciones y tutores (sesión 4)
- **Rutas:** `GET /admin/secciones`, `POST /admin/secciones/{id}/tutor`
- **Rol requerido:** solo `admin`
- **Controlador:** `app/Controllers/Admin/SeccionController.php`
- **Modelo:** `app/Models/SeccionModel.php`
- **Vista:** `resources/views/admin/secciones/index.php`
- **JS fuente:** `resources/js/secciones.js` → compilado a `public/js/secciones.js`

### Características del módulo
- Tabla de secciones agrupada por nivel (separador de fila por nivel)
- Botón "Asignar" / "Cambiar" abre modal con select dinámico
- El select filtra docentes: solo muestra disponibles (sin tutoría en otra sección del año activo)
- La carga transversal se crea/actualiza/desactiva automáticamente al asignar/quitar tutor
- `SeccionModel::listarDocentes()` incluye subquery `tutor_seccion_id` para filtrar disponibles
- `SeccionController` serializa docentes como JSON (`$docentesJson`) para el select dinámico
- Los datos JSON se embeben en un `<div id="modalTutorData" data-docentes="...">` en la vista
- El JS lee el JSON y reconstruye el `<select>` cada vez que se abre el modal

### Fuentes Inter (@font-face)
- Las rutas en `_typography.scss` usan path relativo `../assets/fonts/inter/`
  (relativo al CSS compilado en `public/css/`). NO usar rutas absolutas con
  `/siga-cociap/public/...` — no funcionan en todos los entornos.

## Panel de bloqueos del director — hub de 3 tabs (16/06/2026)

> `/director/bloqueos` pasó de ser un scroll único a un **hub** con 3 cards-tab que
> separan los tres tipos de bloqueo. Mismo color wayfinding (académicas=azul,
> transversales=teal, conducta=púrpura).

### Estructura de la vista (`resources/views/director/bloqueos/index.php`)
- Selector de periodo (igual) + `.bloqueos-hub` con 3 `.bloqueos-tabcard` (mini-stat
  `X/Y` + barra + %). **Sin detalle hasta hacer clic.**
- Tres `<section class="bloqueos-panel" data-panel="..." hidden>`:
  - **academicas** — donut + widgets + ranking + tablas por sección (TODO lo que existía,
    preservado intacto, solo envuelto en el panel).
  - **transversales** — tabla por sección (cierre del tutor) **+ desplegable por carga**
    (ver "Los dos niveles" abajo).
  - **conducta** — tabla NUEVA (ver abajo).
- **JS** (`resources/js/bloqueos.js`): tabs sin recargar; clic muestra un panel y oculta
  los demás; segundo clic colapsa; recuerda el último tab **por periodo** en
  `sessionStorage` (`bloqueos.tab.{periodoId}`) para no perder contexto tras un
  POST→redirect. El acordeón de secciones académicas se mantiene.
- **SASS** en `pages/_admin.scss`: `.bloqueos-hub`, `.bloqueos-tabcard--*` (tokens
  wayfinding; fondo tenue solo en `--activa`), `.bloqueos-panel`, `.td-acciones-conducta`.

### Transversales: LOS DOS NIVELES (06/08/2026)

El bloque de transversales gobierna **dos cosas distintas**, y confundirlas costaba una
reapertura innecesaria de todo el bimestre:

| Nivel | Tabla | Qué impide | Acción en el panel |
|---|---|---|---|
| **1. Bloqueo por carga** | `bloqueos_competencia` | Que **el DOCENTE** edite sus notas de TIC/GAMA | **Liberar** (desplegable por sección) |
| **2. Cierre por sección** | `cierres_transversales` | Que el agregado llegue a la **boleta** | **Anular cierre** / **Cerrar** |

⚠️ **"Anular cierre" NO desbloquea a ningún docente.** Llama a `anularCierreVigente` y
nada más; su nombre anterior era *Desbloquear*, que inducía justo al error contrario
(renombrado el 06/08/2026 junto con el texto explicativo del bloque).

**Por qué el nivel 1 no existía hasta el 06/08/2026.** Las transversales **no son filas
del panel académico**: `getCompetenciasPorPeriodo` une competencia↔carga por el área de la
CARGA, y ellas cuelgan de un área propia (`tipo='transversal'`). La única vía era la
**cascada**: desbloquear una competencia ACADÉMICA de la misma carga, que
(a) sacaba también esa académica de la boleta, (b) liberaba **las dos** transversales de
golpe y (c) obligaba al docente a re-aprobar todo. Y si la carga no tenía ninguna
académica bloqueada —estado alcanzable, porque bloquear transversales primero está
permitido y 64 cargas de B2 lo hicieron— **no había vía ninguna**.

- **Ruta:** `POST /director/bloqueos/transversal-competencia/{bloqueo_id}/liberar`
  → `BloqueoController::liberarTransversalCompetencia`. Prefijo propio para no chocar con
  `/transversal/{seccion_id}/…`, donde el parámetro es otra cosa.
- **Granularidad por COMPETENCIA** (decisión del usuario): TIC y "Aprendizaje autónomo"
  (GAMA) se liberan por separado. Medido: liberar una deja la otra bloqueada, conserva
  las notas y no toca las académicas de la carga.
- **El anclaje EXIGE `a.tipo = 'transversal'`** (INNER JOIN): este endpoint no puede
  usarse para desbloquear una académica. Verificado: un `bloqueo_id` académico resuelve
  `null` y aborta.

### LA CASCADA SE RETIRÓ — desbloquear una académica ya no toca las transversales (07/08/2026)

> **Decisión del usuario:** *"en caso se desbloquee alguna competencia académica solo debe
> desbloquear esa competencia… no importa si tenemos que hacer un clic más, lo importante
> es la granularidad"*. **EN `dev`, SIN DESPLEGAR** — el merge espera al cierre de B2.

`BloqueoController::desbloquear` hacía **tres** cosas. Ahora hace **dos**:

| # | Efecto | Estado |
|---|---|---|
| 1 | quitar el bloqueo pedido | se mantiene |
| 2 | `liberarTransversalesDeCarga` — borrar los TIC/GAMA de la carga | 🔴 **RETIRADO** |
| 3 | `anularCierreVigente` — anular el cierre del tutor de la sección | **se mantiene** |

**Por qué se retira el 2.** Su motivo era el de arriba: las transversales no eran filas del
panel y quedarían *"bloqueadas e inalcanzables"*. **Ese motivo murió el 06/08** con el
desbloqueo granular. Mantenerlo tenía dos costos: obligaba al **docente** a re-aprobar
TIC/GAMA que nadie había tocado y —lo importante— **bajaba el numerador de
`estadoCargasSeccion`, así que el TUTOR no podía re-cerrar hasta que el docente actuara**.
Medido en el contraste de la verificación: el gate caía de **16/16 a 14/16**.

**Por qué se mantiene el 3.** Aunque el promedio transversal **no cambia** —
`getPromediosSeccion` solo lee bloqueos de competencias transversales, y una académica no
lo es—, si cambian las notas del estudiante **la conclusión descriptiva del tutor puede
dejar de ser precisa**. Es criterio pedagógico, no técnico. Y ahora es barato: con los
bloqueos intactos el gate sigue cuadrando y el tutor **revisa y re-cierra de inmediato,
sin depender de nadie**.

- `TransversalModel::liberarTransversalesDeCarga` queda **DORMIDO** (sin llamadores) en vez
  de borrarse, siguiendo el patrón del repo. Su docblock advierte que no se reintroduzca
  sin decidirlo de nuevo.
- **Textos actualizados**: el mensaje de éxito y el `confirm` de la vista anticipan que las
  transversales NO se tocan y dicen dónde reabrirlas.
- **La regla del tutor NO cambia**: si alguna transversal no está aprobada, sigue sin poder
  cerrar. El gate es el mismo; solo dejamos de romperlo por accidente.
- **Verificación:** `database/verificaciones/verif_desbloqueo_sin_cascada.php` — escribe,
  pero todo en transacción con ROLLBACK y con guard de secretos de producción. 7 bloques:
  transversales intactas · gate inmóvil · cierre anulado · solo cae la competencia pedida ·
  re-bloqueo sin duplicados (`uq_bloqueo`) · rollback limpio · y un **contraste que
  reproduce la cascada vieja** y comprueba que el gate caía. Si ese contraste dejara de
  mostrar la caída, el cambio habría perdido su justificación.
- ⚠️ **Cifra que conviene no repetir mal:** de las 48 anulaciones sobre 71 cierres de B2,
  **solo 2 vinieron de este camino**; las otras 46 son `limpiarBloqueosCierre`. La
  documentación sugería que la cascada explicaba buena parte de ellas — medido el 07/08, no.
- **ANULA el cierre del tutor**, igual que la cascada: `getTransversalesAgregadas` exige
  cierre vigente y promedia solo lo bloqueado, así que dejarlo en pie mostraría a las
  familias un promedio que ya no se corresponde con lo bloqueado.
- **Solo lista cargas CON algún bloqueo transversal.** Sale de los datos y evita
  reescribir la regla de "carga dueña" por quinta vez: si una carga no aparece, no hay
  nada que liberar en ella.
- **UI:** `<details>` nativo colapsado, sin JS — 23 secciones × ~16 cargas abiertas serían
  cientos de filas. Columnas: carga · docente · competencia · **origen** (docente /
  cierre forzado) · nº de notas que vuelven a ser editables · acción. SASS:
  `.bloqueos-transversales` en `pages/_admin.scss`.
- Modelo: `TransversalModel::getBloqueosTransversalesPorPeriodo` (una sola consulta para
  todo el periodo, agrupada en el controlador; nada de N+1 por sección).

### Conducta en el panel del director (gestión nueva)
- **Dos etapas** (igual que el flujo real): **auxiliar académico** registra/bloquea
  (etapa 1) → **tutor** cierra (etapa 2). Hoy la etapa 1 la hace el rol
  `registro_academico`, pero en la UI se etiqueta **"auxiliar académico"** (rol futuro;
  NO se creó el rol todavía).
- `$conducta[]` con `estado` ∈ `pendiente_auxiliar` (rojo) / `pendiente_tutor` (ámbar) /
  `cerrada` (verde) + columna "Calificados X/Y".
- **El director tiene control total:** forzar etapa 1, forzar etapa 2, o **reabrir**
  (anula con traza). Forzar etapa 1 RESPETA la regla de negocio (exige todos los
  estudiantes calificados; el botón se deshabilita y `bloquearRA` lo revalida en servidor).
  ~~Reabrir es libre.~~ **Reabrir exige el bimestre ACTIVO** (06/08/2026, ver abajo).

### No se desbloquea una competencia con calificaciones extraordinarias (18/09/2026)

Segunda guarda, después de `abortarSiPeriodoCerrado`: `desbloquear` y
`liberarTransversalCompetencia` abortan con los nombres de los alumnos
(`abortarSiHayExtraordinarias`), y `limpiarBloqueosCierre` **conserva** esas competencias
y lo dice en el mensaje. Sin ella, la nota de RA se mezclaba con la del docente en
`calcularPromedio`. Punto único y motivo en `calificaciones.md` («Solo bimestres cerrados,
guarda de desbloqueo…»).

### Las 4 reaperturas del panel exigen el bimestre reabierto (06/08/2026)

**Punto único: `BloqueoController::abortarSiPeriodoCerrado`**, que consumen las cuatro
acciones destructivas del panel: `desbloquear` (competencia), `reabrirTransversal`,
`reabrirConducta` y `reabrirAsistencia`. Antes **ninguna** validaba el estado del periodo
—solo `limpiarBloqueosCierre` lo hacía—, así que con el bimestre cerrado el botón
funcionaba y no daba error.

**Por qué importa: con el bimestre cerrado la acción no sirve y en 3 de los 4 casos
destruye la vista del documento.** `periodoEditable`/`periodoEstaBloqueado` cortan por
`estado='cerrado'` sin mirar el bloqueo, así que nadie puede corregir después; y mientras
tanto el dato desaparece de la boleta:

| Acción | ¿Sale de la boleta? | Mecanismo |
|---|---|---|
| Desbloquear competencia | **Sí** | la boleta pinta solo competencias con bloqueo (+ cascada que libera TIC/GAMA y anula el cierre transversal) |
| Reabrir transversal (sección) | **Sí** | `getTransversalesAgregadas` corta si no hay cierre vigente |
| Liberar transversal (carga) | **Sí** | la competencia sale del promedio agregado, y además anula el cierre |
| Reabrir conducta | **Sí** | `ConductaModel::getParaPeriodo` devuelve `null` (campo `visible`) |
| Reabrir asistencia | **No** | `getDelBimestre` lee `inasistencias` sin mirar el cierre |

⚠️ **Cada llamada pasa SU mensaje**: el efecto no es idéntico y el aviso de asistencia no
debe prometer una pérdida de datos que no ocurre. En la vista los botones quedan
**inertes con el motivo en el `title`** (no desaparecen: se ve POR QUÉ no se puede),
reusando `.btn:disabled` de `components/_buttons.scss` — sin SASS nuevo.

**Los botones de AVANCE se dejan intactos a propósito** (Bloquear competencia, Bloquear
transversal, Bloquear etapa 1, Cerrar etapa 2, Bloquear asistencia): no destruyen nada y
son la vía para recomponer algo que haya quedado sin bloqueo por un desbloqueo anterior.

**La vía correcta con un bimestre cerrado sigue siendo reabrirlo**
(`PeriodoController::reabrir`) — con su coste: ver el guard P4 en `docs/ESTADO.md`.
- **`ConductaModel::getResumenSeccionesPorPeriodo(int $periodoId)`** — espejo del de
  transversales (sección + tutor + estado de las 2 etapas del cierre), enriquecido con
  completitud reusando `getProgresoConductaPorSeccion`. Solo secciones del año del periodo
  CON tutor (la etapa 2 lo exige).
- **`Director\BloqueoController`**: inyecta `ConductaModel`; `index()` arma
  `$conducta`/`$conductaStats`/`$transStats` (todas inicializadas ANTES de los `if`, nunca
  indefinidas en la vista); métodos `bloquearConducta`/`cerrarConducta`/`reabrirConducta`
  (reusan `bloquearRA`/`cerrarTutor`/`anularCierre`) + helper privado `nivelIdDeSeccion`.
- **Rutas** (registradas ANTES de `/director/bloqueos/{id}/desbloquear`):
  `POST /director/bloqueos/conducta/{seccion_id}/{bloquear|cerrar|reabrir}`.

## Módulo Director EBR — historial de cargo (sesión 7)

### Tabla `director_ebr_historial`
```sql
id, usuario_id, anio_id, desde DATE, hasta DATE NULL,
asignado_por, asignado_en, firma_path VARCHAR(255), sello_path VARCHAR(255)
```
- `hasta = NULL` significa vigente. Un registro por periodo de cargo.
- `firma_path` / `sello_path`: ruta relativa a `public/` de los PNG (excluidos de Git).
- Al asignar nuevo director: cierra el registro vigente (`hasta = desde_nuevo - 1 día`)
  e inserta el nuevo. Transacción garantiza atomicidad.

### `DirectorEbrModel` — métodos clave
- `getVigenteEnFecha(int $anioId, ?string $fecha = null): ?array` — director en una fecha
  (NULL = hoy). Retorna `nombre_completo`, `firma_path`, `sello_path`.
- `asignar(...): int` — retorna ID del nuevo registro (necesario para subir imágenes).
- `actualizarImagenes(int $id, ?string $firma, ?string $sello): bool`
- `getHistorialPorAnio(int $anioId): array`

### `Admin\DirectorEbrController`
- Rutas: `GET /admin/director-ebr`, `POST /admin/director-ebr/{anio_id}/asignar`,
  `POST /admin/director-ebr/{id}/imagenes`
- Solo rol `admin`.
- Validación de PNG con `\getimagesize()` (NO `exif_imagetype()` — ext-exif deshabilitada
  en XAMPP). Límite 2 MB. Almacena en `public/assets/img/firmas/` (excluido de Git).
- Elimina el archivo anterior al reemplazar imagen.

### Uso en documentos
- **`OrdenMeritoController::imprimir()`** llama `getVigenteEnFecha($anioId)` sin fecha
  (siempre hoy — el documento se firma en el momento de impresión).
- **`Boleta\BoletaController::buildBoletaData()`** y **`Admin\BoletaPublicaController::buildBoletaData()`**
  incluyen `directorEbr` en su array de retorno.
- **`BoletaPublicaController` público** (sin login) también inyecta `DirectorEbrModel`.

### Firma y sello en vistas
| Vista | Elemento visible | CSS |
|-------|-----------------|-----|
| Boleta imprimible A4 | Firma PNG + nombre | `boleta-footer__espacio-firma` (18mm fijo) |
| Reporte orden de mérito A4 | Firma PNG + nombre | `reporte-footer__espacio-firma` (18mm fijo) |
| Boleta digital (pantalla) | Sello PNG | `bd-footer__img-area` (44px) + `.bd-solo-pantalla` |
| Boleta digital (al imprimir) | Firma PNG + nombre | `bd-footer__img-area` (14mm) + `.bd-solo-impresion` |

### Técnica de alineación de líneas de firma
Todos los bloques de firma (con y sin imagen) tienen un contenedor de **altura fija**:
- Print: `boleta-footer__espacio-firma` / `reporte-footer__espacio-firma` — 18mm
- Digital: `bd-footer__img-area` — 44px pantalla / 14mm print
- La imagen se ancla al fondo con `align-items: flex-end; justify-content: center`.
- El bloque sin imagen tiene el contenedor vacío de la misma altura → líneas al mismo nivel.
- `bd-footer__line` pasa a `height: 0` (solo dibuja el borde); el espacio lo provee `__img-area`.

### Reporte orden de mérito — footer dinámico
- Clase dedicada `.reporte-footer` en `_reporte-merito.scss` (no reutiliza `.boleta-footer`).
- `flex-wrap: wrap; justify-content: space-around; flex: 0 0 30%` por bloque → máx 3 por fila.
  4ª firma: nueva fila centrada. Soporta hasta 6 firmas (2 filas de 3).
- Firmas: Director EBR + 1 tutor por sección del grado (dinámico desde `$tutores`).
- `$infoConteos` muestra solo el número de áreas (no competencias — varían por docente).

## Nómina detallada admin/RA (22/06/2026)

> Reporte de matrículas para el comité, en 2 etapas. Documentado desde la
> memoria de sesión al crear la red (03/07/2026).

- **Etapa 1 (implementada):** nómina imprimible GLOBAL con filtros, agrupada por
  sección. NO toca la nómina del docente (`/docente/nomina`), que es otra vista.
- **Etapa 2 (pendiente):** resumen estadístico.
- **Retorno R3:** un alumno con retorno de grado activo aparece en su matrícula
  oficial Y en la operativa (comportamiento esperado en este reporte).

## Conducta: grilla de criterios en SOLO LECTURA para el tutor (07/07/2026)

El tutor puede consultar la matriz Si/No que registraron los auxiliares (RA),
ademas de la nota derivada que ya veia en su panel:

- **Ruta:** `GET /docente/conducta/{periodo_id}/criterios`
  (`Docente\ConductaTutorController::criterios`). Boton "Ver criterios
  evaluados por los auxiliares (lectura)" en `/docente/conducta` — solo
  se renderiza dentro del branch con cierre vigente.
- **Gate:** identico a la etapa 2 — visible SOLO con la conducta de la seccion
  BLOQUEADA Y APROBADA por RA (`getCierreVigente`). Sin cierre → redirect con
  mensaje. Ambos niveles (el guard `seccionTutor` es agnostico al nivel).
- **Vista:** `docente/conducta-criterios.php` — espejo de
  `admin/conducta/seccion.php` en su estado bloqueado (mismas clases
  `.conducta-grilla`/`.cc-btn` deshabilitadas), SIN formularios ni JS; la nota
  RA se calcula en servidor (Si/total x 20, `PHP_ROUND_HALF_UP`), no via JS.
- **Solo lectura por diseño:** la vista no expone ningun POST y los endpoints
  de escritura de conducta siguen gateados a admin/registro_academico.
- **B1 legado** (literal directo, sin matriz): estado vacio explicativo.

## Conducta: roster igual al del docente al calificar (09/07/2026)

El registro de conducta (RA/auxiliares y grilla del tutor) debe listar EL MISMO
roster que el docente ve al ingresar notas (`getAlumnosSeccion`): todos los
matriculados de la seccion — aprobada, **pendiente** (recien matriculado) y
**desactivado por baja administrativa/deuda** (sigue asistiendo) — con el UNICO
excluido siendo el traslado de salida (`tipo='trasladado'`), mas las exclusiones
de retorno de grado (oficial en retorno activo / operativa revertida).

- **Antes:** las 4 queries de `ConductaModel` filtraban `m.estado='aprobada'`, que
  dejaba fuera pendientes y desactivados-no-trasladados que el docente SI califica.
- **Ahora (paridad total con el docente):** `m.tipo != 'trasladado'` +
  `m.id NOT IN (retornos_grado oficiales activos / operativos revertidos)` en las
  CUATRO queries, que deben moverse juntas o la compuerta de cierre queda
  inconsistente:
  - `getEstudiantesParaRegistro` (grilla de RA),
  - `getProgresoConductaPorSeccion` (indice + panel del director),
  - `completitudSeccion` (compuerta "todos calificados" de `bloquearRA`),
  - `getEstudiantesParaTutor` (grilla del tutor).
- **Seguro contra NULL:** `matriculas.tipo` es `NOT NULL DEFAULT 'continuador'`
  (sin NULLs), asi que `tipo != 'trasladado'` no descarta filas por accidente.
- **Sin migracion.** Solo cambia el filtro SQL del roster; `getParaBoleta` y demas
  lecturas por matricula no se tocan (la boleta muestra la conducta segun el
  cierre, independiente del estado — coherente con boletas de desactivados).

## Asistencia: roster igual al del docente al calificar (04/08/2026)

Mismo cambio que conducta hizo el 09/07 — a asistencia se le habia quedado pendiente
casi un mes. `/admin/asistencia/{id}?periodo={pid}` lista ahora EL MISMO roster que
`getAlumnosSeccion`.

- **Antes:** `getEstudiantesConIncidencias` filtraba `m.estado='aprobada'` y **no**
  miraba `tipo` ni el retorno de grado. Divergia en los dos sentidos:
  - dejaba **fuera** a `pendiente` y `desactivado` (deuda), que SI asisten → nadie
    podia registrarles faltas y su boleta salia con **0 inasistencias**: un dato
    falso, no ausente;
  - dejaba **dentro** al trasladado/retirado y a la matricula **oficial de un
    retorno activo** — el grado donde la estudiante ya no esta (su asistencia de B1
    se registro ahi, en el grado equivocado).
- **Ahora:** `m.tipo NOT IN ('trasladado','retirado')` + las dos exclusiones de
  `retornos_grado`, sin filtro de estado. Se movieron **las dos** queries juntas:
  - `getEstudiantesConIncidencias` (grilla **y** imprimible oficial),
  - `getProgresoPorSeccion` (barra del indice + `esperados` de
    `getResumenSeccionesPorPeriodo`, que alimenta el panel del director).
  Si solo se cambiara la primera, el avance mentiria: `esperados` contaria un
  universo distinto al que ve el operador.
- **Guard del endpoint:** `AsistenciaModel::matriculaEnRoster` + su chequeo en
  `Admin\AsistenciaController::guardar`. La grilla ya no pinta a los excluidos, pero
  una pestaña abierta desde antes del cambio si podria enviarlos.
- **Seguro contra NULL:** las dos columnas de `retornos_grado` son `NOT NULL` y su
  `estado` es `enum('activo','revertido')`, asi que los `NOT IN` no descartan filas
  por accidente.
- **Aplica a TODOS los periodos, incluidos los ya bloqueados** (decision del usuario,
  04/08): el imprimible de un bimestre cerrado se recalcula con el roster nuevo. Se
  eligio contra la alternativa de congelar el roster viejo en los periodos con cierre
  vigente, porque hacia convivir dos rosters en el mismo modulo. **Medir el impacto en
  prod antes de desplegar** con `verif_roster_asistencia.php` (bloque 3).
- **La boleta NO cambia:** `getDelBimestreUnion`/`getAcumuladoAnualUnion` van por
  matricula, no por roster. Las filas de `inasistencias` de matriculas fuera del
  roster (11 en local) conservan su dato historico y siguen sumando; lo unico que
  pierden es la edicion por grilla.
- **Sin migracion.** Verificado con `database/verificaciones/verif_roster_asistencia.php`:
  23/23 secciones coinciden con la grilla de notas y los `esperados` cuadran.

## Conducta y Asistencia: historial por bimestre + imprimible oficial (17/07/2026)

Historial de lectura de los registros aprobados y bloqueados en `/admin/conducta`
y `/admin/asistencia`, con copia imprimible firmable. Migracion `043_cierres_asistencia`.

### Selector de bimestre en el INDICE de conducta (20/07/2026)
- `GET /admin/conducta?periodo={pid}`: select en el page-header (patron de
  `.control-selector`, clase propia `.conducta-periodo-selector`, GET +
  `onchange=submit`) con el periodo editable "(en curso)" + los `cerrado`
  "(cerrado)"; los `pendiente` no se listan y un `?periodo=` invalido redirige
  con error. En historial: el progreso/cierres de las cards se calculan para
  ese periodo (`getProgresoConductaPorSeccion` es por-periodo), las cards SIN
  cierre muestran "Sin cierre" (sin barra de progreso) y los enlaces llevan
  `?periodo=` para caer en la vista de seccion en solo lectura.

### Selector de bimestre (ambas vistas de seccion)
- `GET /admin/{conducta|asistencia}/{id}?periodo={pid}`: pestañas `.periodo-tabs`
  con los periodos del año activo **excepto los `pendiente`** (futuros, sin datos).
  Badges: "En curso" (editable) / "Aprobado" (cierre vigente) / "Sin cierre".
- Sin `?periodo=` el comportamiento es el previo (periodo editable). Un periodo
  NO editable se muestra en **solo lectura**: sin toolbar, sin botones de guardar,
  sin `page_scripts` (la nota RA de conducta se calcula en servidor, patron de
  `docente/conducta-criterios.php`); asistencia muestra los contadores como texto.
- **Bimestre legado en conducta (B1, 20/07/2026):** si el periodo en solo lectura
  no tiene matriz de respuestas pero SI literales directos en
  `calificaciones_conducta` (modelo anterior a la migracion 021), la vista muestra
  una tabla simple nombre + literal (`ConductaModel::getLiteralesLegado`, mismo
  roster que el registro; badge `.nota-literal`; alumnos sin registro = "—").
  El imprimible sigue BLOQUEADO para el legado (sin matriz no hay registro
  oficial de criterios que imprimir). Sin literales ni matriz → empty-state.
- **Panel del tutor en bimestre legado (20/07/2026):** `docente/conducta` con
  `legadoInfo` (`ConductaModel::getRegistroLegado` — quien registro y ultimo
  `registrado_en`; null si el periodo tiene matriz o no hay literales, y el
  filtro `literal IS NOT NULL` evita falsos positivos con filas B2+ que solo
  llevan nota_tutor): banner unico "registradas por X el FECHA, los criterios
  se implementaron el siguiente bimestre" en lugar de los alerts de
  aprobado/cerrado, boton "Ver criterios" oculto, tabla de notas intacta.
  La deteccion va ANTES del gate de cierre: una seccion legado SIN cierre
  vigente (caso real: seccion 23 en B1) ve banner + tabla, no la pantalla
  "pendiente de registro".

### Cierre de asistencia (nuevo — tabla `cierres_asistencia`)
- Una sola etapa (espejo parcial de `cierres_conducta`): RA "Bloquear y aprobar"
  via `POST /admin/asistencia/{id}/bloquear`. **SIN precondicion de completitud**:
  fila ausente en `inasistencias` = 0 incidencias (estado valido).
- El cierre vigente es `anulado_en IS NULL` (sin UNIQUE; `getCierreVigente` antes
  de insertar). `guardar()` rechaza edicion con cierre vigente (403), ademas del
  gate de `periodoEditable`.
- Desbloqueo SOLO desde el panel del director (con traza `anulado_por/motivo`).

### Imprimible oficial (layout print, A4 portrait)
- `GET /admin/{conducta|asistencia}/{id}/imprimir/{periodo_id}` — **gate: cierre
  vigente obligatorio** (sin cierre redirige con error). Conducta ademas exige
  matriz de respuestas (B1 legado de literal directo NO imprime; el boton se
  oculta en la vista).
- Estructura: `.boleta-header` (membrete + **fecha de impresion**), `.reporte-titulo`,
  `.tabla-registro` (criterios ✓/✗ + nota RA en conducta; 4 contadores en
  asistencia), leyenda de criterios, traza del cierre (quien/cuando) y
  `.reporte-footer` con DOS lineas de firma EN BLANCO tituladas
  **"Auxiliar Responsable"** y **"Personal de Registro Académico"** (el rol
  auxiliar_academico aun no existe como usuario; se firma a mano).
- SASS: `resources/sass/pages/_registro-cierre.scss` (pantalla + print).

### Panel del director (`/director/bloqueos`)
- 4.ª tabcard **Asistencia** (naranja `$card-nomina-*`) con su panel: tabla de
  secciones (registrados/esperados, estado, fecha) y acciones
  `POST /director/bloqueos/asistencia/{seccion_id}/{bloquear|reabrir}`
  (`AsistenciaModel::getResumenSeccionesPorPeriodo`, sin requisito de tutor).

## Asistencia: la tabla de incidencias es UN partial compartido (25/08/2026)

`resources/views/admin/asistencia/_tabla-incidencias.php` es la **fuente única** de
la tabla de incidencias. La usan las dos pantallas que muestran ese registro:

| Vista | Modo | Quién |
|---|---|---|
| `admin/asistencia/seccion.php` | editable + solo lectura (historial) | RA / admin |
| `consulta-notas/asistencia.php` | solo lectura | los tres directores |

Antes eran **dos tablas distintas para el mismo dato**: RA con `asistencia-tabla` y
la consulta reimplementada con `tabla-resumen` + `text-center`, sin ancho fijo en los
contadores. El modo solo lectura **no se inventó** para esto: ya existía en la vista
de RA para el historial de bimestres cerrados.

- 🔴 **EL MODO EDITABLE ALIMENTA A `public/js/asistencia.js`**, que engancha por
  `.asistencia-fila`, `.asistencia-input`, `.asistencia-guardar`, `.asistencia-status`
  y los `data-matricula-id` / `data-periodo-id` / `data-csrf`. Renombrar cualquiera de
  esos rompe el guardado de RA **en silencio**: el JS deja de encontrar las filas y no
  hay error visible. `verif_asistencia_partial_compartido.php` renderiza el partial de
  verdad en los dos modos y comprueba los ganchos **leyéndolos del propio `.js`**, para
  no fijar aquí una lista que se quede vieja.
- **Los `data-*` solo se emiten en modo editable.** En solo lectura no hay script que
  los lea, y así no se siembra el token CSRF en una página que nunca escribe.
- **Totales y leyenda salen del partial**, así que los tienen las dos vistas por
  construcción. Los totales cuadran el registro antes de bloquearlo; la leyenda
  explica F/FJ/T/TJ, que hasta ahora solo se explicaban con `title` — un tooltip que
  no existe en móvil ni con teclado.
- **`AsistenciaModel::totalesIncidencias()` es el punto único** y recibe el roster ya
  cargado, no consulta otra vez: el total debe ser el de **las filas que se pintan**.
  `AsistenciaModel::CAMPOS` es el orden canónico de los 4 contadores.
- **`asistencia-td-valor` no tenía estilo**: en solo lectura los números quedaban a la
  izquierda bajo una cabecera centrada. Ahora centra y usa `tabular-nums`.
- El **imprimible** (`admin/asistencia/imprimir.php`) sigue aparte a propósito: es
  layout `print` con `.tabla-registro`, otro medio y otras restricciones.

### El imprimible se abrió a Dirección (25/08/2026)

`Admin\AsistenciaController` pasa al patrón de acceso mixto de
`ControlOperativoController`: **el constructor admite el superconjunto**
(`ROLES_REGISTRAN` + `ROLES_DIRECCION`) y **cada método se valida por separado**.

- Solo `imprimir()` queda abierto a los directores — imprimir es una lectura y el
  documento ya existe. `index`, `seccion`, `bloquear` y `guardar` llevan
  `requireRole(self::ROLES_REGISTRAN)` como primera sentencia.
- ⚠️ **La constante se llama `ROLES_REGISTRAN`, no `ROLES_ESCRIBEN`**, porque cubre
  también `index` y `seccion`, que son lectura del panel de RA: llamarlas escritura
  sería mentir. Por eso este controlador **no** entra en el plan de
  `verif_direccion_solo_lectura.php` (que valida los 7 con `ROLES_ESCRIBEN`); lo
  cubre `verif_asistencia_partial_compartido.php`, que además falla si nace un
  método público nuevo sin decidir su rol.
- ⚠️ Camino de error conocido: si `imprimir()` falla su gate (sin cierre vigente)
  redirige a `/admin/asistencia`, que para un director es 403. No es alcanzable desde
  la UI —el botón solo aparece con cierre vigente— pero está ahí.

### Fila enfocada: canal propio, separado del estado del dato (25/08/2026)

Nace del flujo de los auxiliares: transcriben su cuaderno en el celular y, con la
tabla desplazada a la derecha, **saltar de fila** es un error caro.

- ❌ **Se descartó repetir el N° como última columna.** `col-num` y `col-nombre` ya
  son `sticky`, así que la identidad de la fila **no se pierde** al desplazarse; y
  añadir columna empeora justo lo que duele: la tabla mide **646 px** y en un móvil
  de ~390 px, con 240 px de columnas fijas, **solo se ven 2 de los 4 contadores**.
- 🔴 **DOS CANALES INDEPENDIENTES.** El **fondo** dice el estado del DATO (verde =
  guardado, ámbar = sin guardar); la **barra en la columna N°** dice dónde está el
  FOCO. Si el foco usara también el fondo, mientras se escribe habría que tapar el
  ámbar de «sin guardar» —la señal que no se puede perder— o al revés. **No añadir
  `background` a la regla de `:focus-within`**: hay un aserto que lo impide.
- La barra vive en `col-num`, que es **sticky**: sigue en pantalla aunque se esté
  escribiendo en TJ. Mismo recurso que `.fila-pendiente` en `tabla-resumen`, y el
  mismo azul (`$brand-mid`) con que ya se marca el foco del propio input — el
  naranja se descartó por confundirse con el ámbar de `--con-cambios`.
- 🔴 **VA POR ESPECIFICIDAD, NO POR ORDEN.** `.tabla-notas tr:hover .col-num` es
  **(0,3,1)** y ganaba a `.asistencia-fila--registrada td.col-num` **(0,2,1)**: al
  pasar el ratón, una fila registrada **perdía su verde por completo** (defecto
  preexistente, arreglado aquí). Las reglas se anclan a `.asistencia-tabla` y
  doblan la clase de fila → **(0,4,1)**. El hover vive en OTRO parcial, así que
  confiar en el orden de `@import` no bastaba. Si se sacan de ahí, vuelve el fallo.
- **`scroll-margin-block` en `.asistencia-input`**: el teclado virtual tapa la mitad
  inferior de la pantalla y la fila recién enfocada quedaba pegada al borde. Va en
  el input porque es el elemento que recibe el foco y al que el navegador desplaza.
- En la vista de Dirección **no se activa nunca**: en solo lectura el partial no
  pinta inputs, y sin nada enfocable no hay `:focus-within`.

## Conducta: código de criterio y grilla Sí/No compartida (25/08/2026)

- **`criterios_conducta.codigo`** (migración **056**). Las grillas rotulan sus
  columnas `C1`, `C2`… y ese código se calculaba a mano como `$i + 1` en **dos**
  vistas, a punto de ser tres. Ahora `ConductaModel::getCriterios()` lo devuelve
  como un campo más, con **fallback posicional** si un criterio nace sin código.
- 🔴 **Por qué una columna y no seguir con la posición.** Si se reordena o se borra
  un criterio de en medio, **todos los códigos siguientes se corren** y los
  registros ya impresos y firmados dejan de cuadrar, sin error visible. Y hay un
  segundo motivo: `getCriterios($nivelId)` **filtra por nivel**; hoy los 10
  criterios son globales, pero en cuanto exista uno por nivel la misma posición
  significaría criterios distintos en primaria y en secundaria.
- **La migración no cambió nada de lo impreso**: medido antes de escribirla, los 10
  criterios vigentes tienen `orden` 1..10 **sin huecos**, así que `C{posición}` y
  `C{orden}` daban el mismo valor. El verificador ancla esa coincidencia.
- **La grilla Sí/No la comparten TUTOR y DIRECCIÓN.** `/consulta-notas/{p}/seccion/{s}/conducta/criterios`
  **reusa `docente/conducta-criterios.php`**, que ya existía y ya era solo lectura
  —su docblock la declaraba «espejo de `admin/conducta/seccion.php` en su estado
  bloqueado»—. Escribir otra habría sido la tercera copia de la misma grilla.
  Lo único que cambia es el chrome: `$volverUrl` y `$tituloClase`, con default
  para el tutor. La vista **no sabe quién la mira**.
- Mismo gate que la pantalla de conducta: las **dos etapas** cumplidas y sin
  anular, o 404 — esconder el enlace no basta, la URL queda en marcadores.

## Los 4 contadores de asistencia son INDEPENDIENTES (27/08/2026)

Nunca estuvo escrito en ningún doc y es lo primero que se malinterpreta al leer
`inasistencias`: **`faltas` y `faltas_justificadas` NO son un total y su
subconjunto**. Son cuatro columnas paralelas y disjuntas:

| Columna | UI | Significado |
|---|---|---|
| `faltas` | `F` | faltas **sin justificación** |
| `faltas_justificadas` | `FJ` | faltas justificadas |
| `tardanzas` | `T` | tardanzas **sin justificación** |
| `tardanzas_justificadas` | `TJ` | tardanzas justificadas |

🔴 **«Sin justificación» = la columna tal cual. NUNCA restar.** La prueba está en
los datos: hay **159 filas con `faltas_justificadas > faltas`** y **78 con
`tardanzas_justificadas > tardanzas`**, imposible si una contuviera a la otra.
Nada en el código valida `FJ <= F`, y no debe hacerlo.

No existe motivo, documento ni fecha de justificación: lo único auditable es
`registrado_por` / `registrado_en` / `modificado_en` de la fila entera.

## Estadística de conducta y asistencia para Dirección (27/08/2026)

`/admin/cuadros` pasó de medir solo el **avance del proceso** (cuántas secciones
cerraron) a medir también el **resultado**. Métodos nuevos, cada uno en su modelo
—el controlador COMPONE, no calcula—:

| Modelo | Método | Qué devuelve |
|---|---|---|
| `ConductaModel` | `getDistribucionLiteralesAnual($anioId)` | AD/A/B/C por nivel y por bimestre, con `pct_logro` |
| `ConductaModel` | `getIncumplimientoCriterios($periodoId)` | `%` de «No cumple» por criterio, global y por sección |
| `AsistenciaModel` | `getIncidenciasPorSeccion($periodoId)` | los 4 contadores + `con_faltas`, `sin_incidencias` |
| `AsistenciaModel` | `getTopIncidenciasPorSeccion($periodoId, $tope)` | estudiantes con más faltas/tardanzas, por sección |
| `AsistenciaModel` | `getEvolucionIncidenciasAnual($anioId)` | los 4 contadores por bimestre |

Decisiones que NO son evidentes en el código:

- 🔴 **`getDistribucionLiteralesAnual` NO calcula el literal**: trae los
  ingredientes en crudo y llama a `componerLiteral()`, el mismo método que usan la
  boleta y el panel del padre. Ya había **tres** copias de esa aritmética en el
  repo (`componerLiteral`, el bloque PHP de `getEstudiantesParaTutor` y
  `resources/js/conducta.js`); una cuarta habría divergido sin síntoma. Además es
  lo único que trata bien el **I Bimestre**, que es legado (0 respuestas, literal
  directo) y del que `componerLiteral()` ya se ocupa.
- **Nada se filtra por `cierres_conducta`.** El tablero es de monitoreo y tiene que
  servir con el bimestre en curso a medio llenar; la visibilidad en boleta es otra
  pregunta y la responden `getParaBoleta`/`getParaPeriodo`. Se rotula en pantalla.
- **Los estudiantes sin literal quedan fuera del denominador.** Contarlos como C
  convertiría «todavía no lo han calificado» en un mal resultado.
- **El corte del top incluye los empates del último puesto**, para no elegir entre
  ex aequo por apellido en un listado que señala personas. Se probó la variante
  «los N valores distintos más altos» y se **descartó**: en una sección con la
  incidencia repartida el tercer valor distinto es un 1 y la lista pasa de 3 a 8
  personas (250 filas frente a 180). Una fila por estudiante, no dos listas.

### 🔴 «Estudiantes con más faltas» es INFORMATIVO, y así debe presentarse

Decisión del colegio, no técnica: **la normativa vigente ya no contempla el retiro
automático por exceso de inasistencias**. Cualquier decisión se sustenta y evalúa
caso por caso. Por eso ese bloque:

- **no tiene umbral** (se estudió ponerlo en 5 faltas y se rechazó),
- **no usa rojo ni ámbar** — en este sistema significan estado de un proceso, y
  aquí no hay proceso que señalar, hay personas,
- **no dice «riesgo» ni «alerta»**, y lleva la advertencia escrita en la pantalla
  y en el papel.

Si alguien «mejora» esto añadiendo un semáforo, está reintroduciendo una regla que
el colegio derogó.
