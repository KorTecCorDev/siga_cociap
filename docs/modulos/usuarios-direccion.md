# Módulo: Usuarios de Dirección (supervisión en solo lectura)

> **Estado: EN `dev`, SIN DESPLEGAR ni PROBAR EN NAVEGADOR (24/08/2026).**
> Las 7 fases están implementadas y las 5 verificaciones automáticas en verde,
> pero **nadie ha abierto todavía una sola pantalla**. La migración **055** ya
> está aplicada en LOCAL (9 roles, `director_academico` = id 9) y sigue
> **pendiente en producción**.
> Después de las 7 fases se añadió el **explorador de criterios** (ver su sección):
> también sin probar en navegador.

## Qué es

Los **tres** cargos de dirección del colegio —Director General, Director EBR y
Director académico— tienen **exactamente las mismas atribuciones**: supervisión
en **SOLO LECTURA** sobre los dos niveles. No hay alcance por nivel y se decidió
que no lo habrá (no existe mapeo usuario→nivel en el sistema).

**Una sola diferencia, y es la razón por la que NO se unifican en un rol único:
solo el Director EBR puede ser asignado como FIRMANTE** de boletas, actas de
desempate y reporte de orden de mérito.

## Punto único: `ROLES_DIRECCION`

`app/Helpers/helpers.php`. Cualquier control de acceso que hable de «los
directores» se apoya en esta constante; **nunca se listan los códigos a mano**.

Nació porque estaban escritos a mano en **44 literales de 16 archivos**: sumar un
tercer director obligaba a tocarlos uno por uno, que es el patrón con el que ya
divergieron cuatro reglas en este repositorio.

### ⚠️ Las DOS excepciones deliberadas

Son el par que sostiene el invariante «solo el EBR firma». **No son un olvido y
no se deben “arreglar”**:

| Sitio | Función |
|---|---|
| `DirectorEbrModel::listarCandidatos()` (línea ~145) | **LISTA** los candidatos a firmante |
| `Admin\DirectorEbrController::asignar()` (línea ~81) | **REVALIDA** en servidor al asignar |

Si la constante se cuela en cualquiera de los dos, un Director General o
Académico podría quedar asignado como firmante de documentos oficiales.

### ⚠️ Trampa al buscar estos códigos

Buscar **siempre entre comillas**: la cadena `director_ebr` también es parte del
nombre de la tabla `director_ebr_historial` (11 apariciones en su modelo), y un
reemplazo masivo la corrompe en silencio.

### La copia que no puede leer la constante

`resources/sass/pages/_admin.scss` — el color del avatar. SASS no ve PHP. **Al
tocar `ROLES_DIRECCION`, revisar ahí también.** Los tres directores comparten un
único grupo ámbar.

## Cómo se retiró la escritura

El permiso vivía en el **constructor**. Como el director conserva la LECTURA, el
constructor sigue dejándolo entrar y **cada método de escritura lleva su propia
guarda** `requireRole(self::ROLES_ESCRIBEN)`. Mismo patrón que
`ControlOperativoController::ROLES_PUBLICAN`.

**30 métodos guardados** en 7 controladores (año y bimestres · cargas ·
reemplazo de docente · panel de bloqueos · desempates · hito A del cierre ·
retorno de grado).

Las vistas se pintan sin controles vía `$puedeEscribir`, pero **eso es UX, no
control de acceso**: la guarda real está en el método.

### Excepción acotada: marcar leídas SUS notificaciones (16/09/2026)

Desde el cierre del módulo de notificaciones, los tres directores **reciben
comunicados y tienen campana** (`NotificacionModel::ROLES_RECEPTORES` incluye
`...ROLES_DIRECCION`). `POST /notificaciones/leer` y `/leer-todas` **no llevan gate
de rol a propósito**: solo cambian el estado personal de **su propia bandeja** (el
modelo filtra por `usuario_id`), no un dato académico, y sin ellos su campana nunca
bajaría a cero. **Redactar sigue vedado** (`ROLES_EMISORES` no incluye dirección).
Lo comprueba `verif_notificaciones.php` §6 y §9. Ver `docs/modulos/notificaciones.md`.

### 🔴 La UX iba nueve botones por detrás (02/09/2026)

El servidor estaba **completo y correcto** —los 30 métodos guardados, verificador
incluido—, pero **nueve controles seguían pintándose** para un director y su
destino le devolvía un 403. Ninguno era un agujero de seguridad; los nueve eran
la promesa de una acción que el sistema luego negaba.

| Dónde | Control | Destino que cortaba |
|---|---|---|
| `admin/control/index.php` | **Resolver** (desempates) | `OrdenMeritoController@desempate` |
| `director/reemplazos/historial.php` | **+ Nuevo reemplazo** | `ReemplazoDocenteController@form` |
| `admin/control` (dato `tutores`) | Ir a secciones y tutores | `SeccionController` (`'admin'` a secas) |
| `matriculas/index.php` | Traslados · Nómina detallada | `TrasladoController` · `nominaImprimir` |
| `matriculas/show.php` | Imprimir constancia · Ver registro | `TrasladoController` |
| `admin/cuadros/index.php` | enlace a Asistencia | `AsistenciaController@index` |

**Cinco de los nueve no eran específicos de dirección**: rompían igual para las
dos secretarías o para `registro_academico`, que también entran a esas pantallas.
Por eso el gate correcto **no siempre es `$puedeEscribir`** — hay que mirar el
`requireRole` del DESTINO y usar el flag que le corresponda (`$puedeGestionar`
para traslados y nómina, `has_role('admin')` para secciones).

Dos decisiones de forma que conviene no deshacer:

- **«Reemplazos» de `director/cargas/seccion.php` NO se toca.** Abre el
  HISTORIAL, que es lectura, y la regla del colegio es que el director *sí*
  visualiza. La fuga estaba un nivel más abajo, en el botón que ese historial
  pintaba.
- **Cuando el gate deja una columna vacía, se oculta la COLUMNA.** En la tabla de
  empates el `<th>Acción</th>` también va bajo `$puedeEscribir`: un encabezado
  huérfano sigue prometiendo lo que no hay.

### El aserto que faltaba, y por qué no las vio

El bloque 4 de `verif_direccion_solo_lectura.php` pregunta *«¿las vistas que YA
USAN `$puedeEscribir` lo reciben?»*. Es consistencia **sobre las vistas ya
convertidas**, así que por construcción **no puede ver una vista que nunca adoptó
el flag** — el modo de fallo exacto de las nueve.

El bloque 6 (nuevo) hace la pregunta útil: *«¿alguna vista que un director puede
ver ofrece un control hacia una ruta que no puede abrir?»*. Lo deriva todo del
código —`routes/web.php` mapea ruta → `Controlador@metodo`— en vez de una lista a
mano que caducaría con el siguiente botón. Hoy: **50 rutas cerradas de 204, 30
vistas alcanzables, 0 fugas.**

⚠️ **Tres precisiones que costaron un falso positivo cada una**, y que hay que
conservar si alguien lo reescribe:

1. Una vista que **solo alcanzan los escritores** (un formulario de alta) no
   necesita gate interno: el director nunca llega a ella.
2. Hay que quedarse con la ruta de **mejor ajuste**. Si no, `/admin/asistencia`
   se lleva por delante a `/admin/asistencia/{id}/imprimir/{p}`, que es la de
   verdad y sí está permitida.
3. El gate se detecta con una **pila**, no mirando atrás N líneas: en
   `matriculas/show.php` el bloque protegido tiene 180 líneas con varios `if`
   anidados dentro, así que el `endif;` más cercano no dice nada.

Se probó en **sus dos ramas**: reintroduciendo el gate del botón «Resolver», el
aserto lo señala con archivo, línea y método destino; al restaurarlo, verde.

### Reasignaciones que trajo consigo

| Controlador | Antes | Ahora | Por qué |
|---|---|---|---|
| `Director\BloqueoController` | admin + 2 directores | **admin + registro_academico** + los 3 directores (lectura) | Sin RA, el panel con el que se opera cada cierre quedaba solo en `admin`. Además la card del dashboard ya se le ofrecía a RA y le devolvía **403** |
| `Director\ReemplazoDocenteController` | admin + director_general | **admin + registro_academico** + los 3 (lectura) | Mismo caso |
| `Admin\DirectorEbrController` | admin | **admin + registro_academico** | Registrar la firma del Director EBR no le corresponde al propio Director EBR |
| `Matricula\RetornoGradoController` | admin + RA + director_ebr | **admin + RA** | Era un permiso **huérfano**: para llegar al formulario había que pasar por `/matriculas/{id}`, que le daba 403 |

## 🔴 Matrículas: abrir el constructor NO alcanzaba

`MatriculaController` tenía **cuatro métodos de escritura sin guarda propia** que
confiaban solo en el constructor: `store` (crear matrícula), `storeApoderado`,
`storeDocumentos` y `storeNotasExternas`. Sumar los directores sin blindarlos les
habría dado **el alta de matrículas**.

Se guardaron con `ROLES_MATRICULAN`, que es **exactamente** la lista que tenía el
constructor antes: las secretarías conservan lo que ya hacían.

En la vista, `$puedeGestionar` (admin/RA) no servía para esos tres botones porque
las secretarías también los usan → nació `$puedeMatricular`.

## Navegación: el bucle que tenían

Hasta el 24/08/2026 los directores aterrizaban en `/director/anios`, que **no
enlaza a ningún otro módulo**, y cuyo botón «← Dashboard» los devolvía ahí mismo.
De los nueve módulos que tenían permitidos **alcanzaban uno**; el resto había que
escribirlo a mano en la barra de direcciones. (El único usuario director de
producción entró una vez, en mayo, y no volvió.)

Ahora aterrizan en `/dashboard` y ven **10 cards**. `/consulta-notas` no tenía
card ninguna —se llegaba solo desde bloqueos y rectificaciones—; ahora la tiene.

## Superficies nuevas

| Ruta | Qué |
|---|---|
| `/consulta-notas/{p}/seccion/{s}/asistencia` | 4.º registro del bimestre en lectura |
| `/consulta-notas/{p}/docentes` y `/docente/{id}` | **Eje por docente** (antes solo periodo→sección→carga) |
| `/director/cargas/seccion/{id}/horario` | **Grilla semanal por sección** (antes solo un string resumen) |
| `/admin/cuadros` | **Cuadros estadísticos** — los 5 registros en un tablero, con gráficos |
| `/admin/cuadros/imprimir` | **Versión A4** del tablero (layout `print`, con sello del Director EBR) |
| `/consulta-notas/{p}/criterios` [+ `/imprimir`] | **Explorador de criterios** — árbol sección → carga → competencia → criterio |

### La regla del dato oficial

> El director ve el **AVANCE** en vivo, pero **todo DATO que se le muestre debe
> estar aprobado y bloqueado**.

Consecuencia importante: **no se derogó ningún invariante**. Calificaciones,
transversales (crudo y agregado), conducta y orden de mérito **mantienen
exactamente los gates que ya tenían**. El «avance en vivo» no se construyó: ya
existía en el tablero de `/director/bloqueos`, que el director conserva en
lectura, más los contadores de Cuadros estadísticos.

**⚠️ La ASISTENCIA es la única excepción, y es deliberada**: se muestra EN VIVO,
sin exigir cierre. Una inasistencia ya ocurrió — no es una calificación sujeta a
aprobación del docente.

### `HorarioModel` — punto único del horario

La consulta vivía en un método **privado** de `Docente\PanelController` y el
armado de la grilla (puntos de corte, `rowspan`, color por ángulo áureo, horas
académicas) estaba inline en `horarioImprimir()`: ~130 líneas inalcanzables desde
cualquier otra pantalla. Se extrajo **sin cambio funcional** (contraste contra
`git HEAD`: 35/35 docentes idénticos) y la grilla vive en el parcial
`resources/views/shared/_horario-grilla.php`, que ambos ejes consumen.

### Criterios con descripción — el intento descartado

La descripción del criterio existía **solo como `title=`** de la cabecera de
columna: invisible en celular y al imprimir. El primer intento la listó en un
cuadro **debajo de la grilla**, dentro de `/consulta-notas/{p}/carga/{id}`.

**Se retiró el 24/08/2026, el mismo día, por decisión del usuario**: no resolvía
lo que hacía falta —ver **todos** los criterios organizados— y dejaba el dato
repartido en dos sitios. En la cabecera sigue el `title=`; el lugar del dato es
el explorador de abajo. *(El cuadro vivía en `consulta-notas/_tabla.php` con la
clase `criterios-descripcion`: si aparece de nuevo, es una reintroducción.)*

### Asistencia de la sección: la vista decía lo contrario de la verdad (25/08/2026)

`/consulta-notas/{p}/seccion/{s}/asistencia` preguntaba por `$cierre['bloqueado_en']`.
**Esa clave no existe**: la columna de `cierres_asistencia` se llama `ra_bloqueado_en`,
y `getCierreDetalle` hace `SELECT ca.*`. Como `empty()` no avisa de una clave ausente,
la condición caía siempre al `else` y la pantalla mostraba **«Registro en curso»
incluso en registros bloqueados y aprobados**. Medido: 23 cierres vigentes en B1 y 23
en B2 — las 23 secciones de ambos bimestres, todas mostrando lo contrario.

- **El estado pasa a `alert`**, con el mismo patrón (`alert--info` + `btn-icon--locked`)
  con que Registro Académico enseña ese mismo hecho. Era el dato más importante de la
  pantalla puesto en el elemento más débil: un `text-sm text-muted` que además mezclaba
  estado, conteo y una nota de a dónde ir para modificar.
- **Se muestra quién aprobó**: `getCierreDetalle` ya devolvía `ra_nombre` y la vista lo
  ignoraba. Sin cierre, un `alert--warning` avisa de que lo que se ve es el estado de
  este momento, no un registro aprobado.
- **La tabla es ahora el partial compartido con RA** — ver `docs/modulos/admin.md`,
  §«Asistencia: la tabla de incidencias es UN partial compartido». Consecuencia
  deliberada: la fila resaltada pasa a ser **la que SÍ tiene registro** (verde, el
  lenguaje del proyecto), donde antes esta vista resaltaba en ámbar las que no lo
  tenían. Una sola convención para las dos pantallas; la leyenda lo explica.
- **El director puede imprimir el registro oficial** cuando hay cierre vigente. Es la
  única puerta que se le abrió en `Admin\AsistenciaController`; los otros cuatro
  métodos siguen cerrados.

### Explorador de criterios (24/08/2026)

`/consulta-notas/{periodo}/criterios` — **tercer eje** de la consulta, junto al de
sección y al de docente: un árbol **sección → carga (área + docente) → competencia
→ criterio**, con la descripción de cada uno. **Sustituye** al cuadro de arriba:
aquel resolvía «que la descripción se vea» dentro de una carga, y lo que hacía
falta era ver **todos** los criterios organizados.

- **Mismo universo que el resto de la pantalla**: solo competencias con bloqueo
  (`competenciasOficiales`). Consecuencia asumida: **en un bimestre en curso sale
  vacío** (B3 tenía 0 de 1 el 24/08), y el estado vacío lo explica en vez de
  parecer roto.
- 🔴 **Las TRANSVERSALES no salen por la vía normal y hay que anexarlas aparte**:
  `getCompetenciasPorPeriodo` une competencia↔carga por el **área de la carga**, y
  las transversales cuelgan de un área propia. Son **743 de los 2 731 criterios de
  B2, el 27 %** — omitirlas dejaba una pantalla que parecía completa y no lo estaba.
  Se anexan con `transversalesConContenido()`, que además exige **contenido real**:
  sin ese EXISTS aparecerían los bloques fantasma del cierre forzado.
- **Un solo método de modelo nuevo**: `CriterioModel::getCriteriosPorPeriodo()`,
  que **no filtra por bloqueo a propósito** — el gate vive en el controlador, punto
  único. Es una consulta para todo el bimestre: recorrer las ~400 cargas llamando a
  `getCriterios()` sería un N+1 de 400 consultas por pantalla.
- **Colapsable con `<details>` nativo y filtro por GET**: cero JavaScript nuevo. El
  selector de bimestre manda `?periodo_id=` y el controlador salta a la ruta que
  toca conservando la búsqueda.
- **El imprimible es una vista aparte a propósito**: un `<details>` cerrado **no
  imprime su contenido**, así que reusar la pantalla habría producido hojas vacías.
- La entrada sin periodo (`/consulta-notas/criterios`, la card del dashboard) reusa
  `ControlOperativoModel::getPeriodoPorDefecto()`, el mismo punto único que Cuadros.

#### Rediseño de lectura, el mismo día

La primera versión tenía **tres** niveles de acordeón y una lista vertical por
competencia. Al verla, el usuario la describió como *«muy cansada de leer»*, y las
cifras le daban la razón: **17 cargas y ~119 criterios por sección**, «nombres» de
criterio que son **frases de 70 caracteres** (hasta 100) y competencias de hasta
**185**. Qué cambió:

- **Dos niveles, no tres**: sección → carga, y dentro **una tabla**
  (competencia con `rowspan` | criterio | descripción). Se escanea por columnas
  en vez de leer frases apiladas.
- 🔴 **Fuera los «Sin descripción»**: eran **2 233 de 2 731 (82 %)** — la mitad del
  ruido de la pantalla. La celda queda vacía y cada carga lleva su contador
  «N con descripción».
- **Cabeceras fijas**: la de sección (`top: 0`) y la de carga (`top: 54px`) se
  quedan pegadas al recorrer los criterios. El `thead` **no** es sticky a
  propósito: una carga tiene ~7 filas y una tercera cabecera solo comería pantalla.
- 🔴 **NO hay buscador de texto, y es deliberado.** Lo hubo unas horas y se retiró
  el mismo día: *«el director no sabe cuáles son los criterios, así que no puede ser
  una modalidad para la búsqueda»*. Los criterios los redacta cada docente; buscarlos
  por su redacción exige conocerlos de antemano. **La pantalla se recorre solo por lo
  que el director SÍ conoce: nivel, grado, sección y docente.** Si alguien propone
  «añadir un buscador», esta es la razón por la que no está.
- **Cuatro filtros estructurales.** Los catálogos se calculan **antes** de filtrar
  —si no, el propio filtro vaciaría su selector y no habría cómo volver atrás— y
  salen de las mismas filas, sin consulta extra.

#### Cascada de los cuatro filtros (25/08/2026)

Los cuatro selectores eran **listas planas e independientes**: con Nivel =
Secundaria, Grado seguía ofreciendo 6.º, Sección las 23 del colegio y Docente
los 35. Al arreglarlo apareció un fallo mayor debajo.

- 🔴 **EL GRADO SE IDENTIFICA POR `grados.id`, NUNCA POR `grados.numero`.** El
  número **colisiona entre niveles**: 1.º de primaria y 1.º de secundaria son
  los dos `1`. El catálogo se indexaba por número, así que **los 11 grados
  reales se fundían en 6 opciones** y `?grado=1` devolvía a la vez 1.ºA/B de
  primaria y 1.ºA/B/C de secundaria — **5 secciones de dos niveles**. El
  imprimible remataba estampando «1.º» sin decir de cuál. Por eso la etiqueta
  del selector lleva el nivel («1.º Secundaria»): con Nivel en «Todos» el
  nombre por sí solo tampoco distingue. Obligó a añadir `g.id AS grado_id` a
  `CalificacionModel::getCompetenciasPorPeriodo()` (aditivo; sus otros dos
  consumidores leen por clave nombrada).
- ⚠️ **UN DOCENTE NO PERTENECE A UN NIVEL**: **2 de los 35** con carga activa
  dictan en primaria **y** en secundaria. Su selector se recorta por
  **pertenencia** —aparece si al menos una de sus secciones sobrevive a los
  demás filtros—, y por eso cada opción viaja con su **conjunto** de secciones
  (`data-secciones`). Con un atributo único («el nivel del docente») esos 2
  desaparecerían del selector en uno de los dos niveles y **sin ningún síntoma**.
- **La cascada es DESCENDENTE**: nivel → grado → sección → docente. Elegir un
  docente **no** recorta los de arriba (decisión explícita, no un olvido).
- **Se resuelve en el cliente** (`resources/js/criterios.js`), sin recarga y sin
  un segundo bloque de datos: el nivel y el grado de cada sección ya viajan en
  sus propias `<option>`, y el mapa se lee de ahí. Va **fuera** del guard de
  `#criterios-arbol` —el formulario existe también en la pantalla vacía, que es
  justo donde hace falta rectificar la combinación— y usa `option.hidden`, no
  `style.display`, que no es fiable para ocultar opciones.
- **Un filtro que queda inválido se limpia solo y en silencio** (vuelve a
  «Todos»), como ya hace `cargas.js` con las áreas. `recortar()` devuelve el
  valor **vigente** tras el posible reseteo: los eslabones siguientes tienen que
  encadenarse sobre ese valor, no sobre el que acaba de invalidarse.
- **Los catálogos siguen calculándose antes de filtrar**: la cascada solo decide
  qué se *ve*, nunca qué *existe*. Verificado en
  `database/verificaciones/verif_criterios_filtros_cascada.php`, que ejercita
  `arbolCriterios()` real por reflexión y contrasta contra SQL independiente
  (incluidas **las dos ramas** del grado ambiguo: 1.º primaria → 2 secciones,
  1.º secundaria → 3).

#### Cambiar de bimestre limpia la consulta (25/08/2026)

El selector de bimestre **auto-aplica** (`onchange="this.form.submit()"`, el
mismo patrón que las otras 9 vistas del repo) y **el salto limpia los cuatro
filtros**. Deroga la decisión del 24/08 de arrastrarlos.

- **Por qué se derogó**: los catálogos se recalculan por bimestre. B1 y B2 tienen
  catálogo idéntico (2 niveles · 11 grados · 23 secciones · 35 docentes), pero un
  bimestre **en curso** es mucho más pequeño —B3 tenía 1 de cada el 25/08—, así
  que arrastrar la consulta de B2 a B3 dejaba la pantalla vacía **con los
  selectores en «Todas»**: el `<option>` filtrado ya no se pintaba. Se cambia de
  bimestre para ver el bimestre, no para repetir la búsqueda.
- 🔴 **Se limpia SOLO cuando el bimestre CAMBIA** (`$destino !== $periodoId`). Si
  se limpiara en cada envío, el botón **Aplicar borraría los filtros que el
  usuario acaba de elegir** y la pantalla no volvería a filtrar nunca. Es la
  trampa de este cambio.
- **La regla vive en el SERVIDOR** (el redirect de `criterios()`); el `onchange`
  solo dispara el envío. Si la limpieza estuviera en el JS habría dos reglas para
  lo mismo. Sin JS, el botón Aplicar hace exactamente lo mismo.
- **Guarda de existencia en `arbolCriterios`**: además, cualquier filtro cuyo
  valor **no esté en el catálogo de ese periodo** se descarta, y el método
  devuelve los **filtros efectivos** (clave `filtros`) que la pantalla y el
  imprimible usan para marcar los selectores. Cierra el caso de la URL escrita a
  mano o el marcador guardado de otro bimestre. ⚠️ Valida **existencia, no
  coherencia**: que el grado pertenezca al nivel elegido lo resuelve la cascada
  del cliente, y duplicar esa regla aquí sería la enésima copia.
- **Caso residual conocido**: una URL a mano con dos valores que existen pero no
  casan (`?nivel=1&grado=7`, 1.º de *secundaria* bajo *primaria*) pasa la guarda
  de existencia; la cascada la limpia en el cliente al cargar, pero el servidor
  ya filtró con ella. Solo alcanzable escribiendo la URL.

#### Chip con el código de la competencia (25/08/2026)

La columna «Competencia» repite el **nombre completo** (hasta 185 caracteres) y
varias competencias del mismo área empiezan igual. Ahora cada una lleva delante
su **`codigo_minedu`** (C1…C57) como chip.

- **Va DELANTE del nombre, no detrás**: con nombres de 185 caracteres, un chip al
  final del párrafo no sirve de ancla. Delante se alinea entre filas y la columna
  se escanea de un vistazo. Misma decisión en pantalla y en el imprimible.
- **Sin casos borde**: las 59 competencias tienen código y los 59 son distintos
  (medido), así que el chip no puede salir vacío ni ambiguo. El `if` que lo
  envuelve es defensivo, porque la columna admite NULL.
- 🔴 **El chip es `.competencia-card__codigo`, el del PROYECTO — no uno propio.**
  Ese es el chip de código de competencia del sistema y ya se usa en otras 5
  vistas (`docente/calificaciones`, `docente/resumen-competencia`,
  `docente/historial-carga`, `padre/notas`, `consulta-notas/carga`). Su
  `margin-right` ya asume que va **delante** del nombre. El imprimible usa el
  mismo chip y solo le ajusta la métrica al A4 desde `.criterios-print`; colores
  y forma **no** se redefinen, para que un cambio del chip los alcance a todos.
  *(Nació un `criterios-chip--codigo` propio y se retiró el mismo día: se veía
  igual y habría divergido en el siguiente retoque.)*
- ⚠️ **Al buscar ese estilo, cuidado con dos trampas.** Está escrito como
  `&__codigo` anidado dentro de `.competencia-card`, así que **`grep
  "competencia-card__codigo"` sobre el SCSS no lo encuentra** — hay que buscar el
  bloque padre. Y existe una copia idéntica en `components/_dashboard.scss` que
  **no se importa desde ningún sitio**: editar esa no cambia nada en pantalla.
  La vigente es la de `pages/_dashboard.scss`.
- ⚠️ **La clave `codigo` se rellena en las DOS ramas del árbol**: las académicas
  desde `getCompetenciasPorPeriodo` (columna nueva `competencia_codigo`) y las
  transversales desde `transversalesConContenido`, que ya la traía. Se llaman
  igual para que la vista no tenga que saber de qué rama salió cada competencia.
  El verificador comprueba **cada rama por separado**: una de las dos podía
  quedarse sin código sin ningún síntoma visible.
- 🔴 **B1 NO sirve para probar la rama transversal**: allí las transversales
  cuelgan de 23 cargas `estado='inactiva'`, que el explorador excluye a
  propósito, y el árbol de ese periodo sale con **0** transversales. Por eso el
  verificador recorre **todos** los periodos (B2 aporta 690) y trata «la rama es
  observable» como una aserción más — si deja de haberlas, avisa en vez de pasar
  en verde sin haber probado nada.

- **Se despliega solo en cuanto acotas**: elegir una sección (~119 criterios) o un
  docente (~135) abre TODO para leerlo de corrido, porque el objetivo es *mostrar*,
  no esconder tras clics. El tope `CRITERIOS_ABRIR_TODO` (200) cubre esas dos
  unidades de trabajo y deja fuera el caso intermedio —un nivel entero son **1 325**
  criterios—, donde se abren las secciones y las cargas siguen plegadas.
- **JS mínimo y prescindible** (`resources/js/criterios.js`): solo los botones de
  expandir/contraer. El árbol es `<details>` nativo — sin el script la pantalla
  sigue siendo plenamente usable.
- El **imprimible hereda los filtros y los declara** en su cabecera: un listado
  parcial que no dice que lo es se lee como el listado completo.

### Cuadros estadísticos: compone, no calcula

🔴 `CuadrosEstadisticosController` **no tiene ni un `SELECT`**. Cada bloque llama
al método que ya existe (`MatriculaModel::getResumen`,
`AnioAcademicoModel::getResumenBimestre` / `getStatsCierre` / `getReaperturas`,
`ConductaModel::getResumenSeccionesPorPeriodo`,
`AsistenciaModel::getProgresoPorSeccion`, `OrdenMeritoModel::gradosConEmpatesPendientes`).
Si hace falta un indicador que no existe, **se añade al modelo que lo posee**.

> ⚠️ **Llamar al dueño no basta si el dueño tiene una copia de la regla.** Hasta
> el 04/09/2026 esta pantalla llamaba obedientemente a `getStatsCierre`… que
> calculaba su propio ranking en vez de pedírselo al orden de mérito. La regla de
> oro se cumplía a la letra y el número seguía siendo falso. Ver
> **El mérito de esta pantalla no era el mérito**, más abajo.

El selector de bimestre sale de `ControlOperativoModel::getPeriodos()` /
`getPeriodoPorDefecto()`: escribirlo aquí habría sido la **quinta** copia de esa
consulta en el repositorio.

### Gráficos del tablero (26/08/2026)

Cinco visualizaciones con **Frappe Charts 1.6.2**, que ya estaba vendorizado en
`public/js/frappe-charts.min.js` para `/matriculas/resumen`. No se añadió ninguna
librería: es SVG (se imprime a la resolución de la impresora, no a 96 dpi), tiene
líneas multi-serie y trae tooltips, y su CSS de tooltip (`.graph-svg-tip`) ya es
global en `_matriculas.scss`.

| # | Gráfico | De dónde sale | ¿Consulta nueva? |
|---|---|---|---|
| G1 | Donut AD/A/B/C, logro vs proceso, riesgo, histograma de C | `require` de `director/anios/_panel-bimestre.php` | no |
| G2 | Evolución del % en logro por bimestre | `AnioAcademicoModel::getEvolucionAnual()` | **sí** |
| G3 | Brecha por grado (1.er puesto vs último) | `merito.por_grado[].peores`, que se descartaba | no |
| G4 | Embudo del cierre de conducta | `conducta.{cerradas,pend_tutor,pend_auxiliar}` | no |
| G5 | Secciones con menor avance en conducta | `conducta_secciones`, el crudo que ya se consultaba | no |
| G6 | Distribución AD/A/B/C de conducta por nivel (apilado) | `ConductaModel::getDistribucionLiteralesAnual()` | **sí** |
| G7 | Evolución del % en logro de conducta | el mismo método | **sí** |
| G8 | Criterios de convivencia con mayor incumplimiento | `ConductaModel::getIncumplimientoCriterios()` | **sí** |
| G9 | Faltas sin justificar por sección | `AsistenciaModel::getIncidenciasPorSeccion()` | **sí** |
| G10 | Tardanzas sin justificar por sección | el mismo método | **sí** |
| G11 | Evolución anual de faltas y tardanzas | `AsistenciaModel::getEvolucionIncidenciasAnual()` | **sí** |
| G12 | Justificadas vs sin justificar, por nivel (apilado) | `getIncidenciasPorSeccion()`, plegado por nivel | **sí** |
| T1 | Mapa de calor: criterios × 23 secciones | `getIncumplimientoCriterios()` | **sí** |
| T2 | Estudiantes con más faltas, por sección | `AsistenciaModel::getTopIncidenciasPorSeccion()` | **sí** |

**Cuatro de los cinco no costaron una sola consulta**: la pantalla ya pedía a los
modelos mucho más de lo que pintaba y tiraba el resto (`por_grado`/`por_seccion`
de matrícula, `deg_*`/`hist`/`con_c` del resumen, `peores` del mérito, el detalle
por sección de conducta).

- **G1 se REUSA, no se repinta.** `_panel-bimestre.php` ya dibujaba estas reglas
  en `/director/periodos/{id}/stats`. Es portátil porque `.bimestre-panel` y
  `.bimestre-donut-bloque` están en la **raíz** de `_anio-academico.scss`, no
  anidados bajo `.stats-layout`. ⚠️ Tiene **dos consumidores**: al tocarlo, probar
  las dos pantallas.
- **`getEvolucionAnual` es GEMELO del bloque 1 de `getResumenBimestre`** — mismo
  universo y mismos umbrales, con `p.anio_id` en vez de `cal.periodo_id`. Se
  duplicó a propósito (un bucle serían 8 consultas por render, y el controlador
  acabaría decidiendo reglas de negocio), y lo que hace segura la duplicación es
  la aserción de **coherencia cruzada** del verificador: compara celda a celda
  ambos métodos contra datos reales, no contra el texto del SQL.
- **`$chartData` se arma en la VISTA** (`admin/cuadros/_chart-data.php`, partial
  compartido por la pantalla y el imprimible). No en el controlador: el
  verificador duplica a mano las transformaciones del controlador, pero
  **renderiza la vista de verdad**. Lo que vive en la vista queda cubierto; lo
  que se mueva al controlador crea una tercera copia.
- **Al eje X de G2 solo entran los bimestres COMPARABLES** (todos los niveles con
  notas). Rellenar con `0` el nivel que aún no arrancó dibuja un desplome que no
  ocurrió — pasó de verdad en B3, donde Secundaria tenía 72 notas y Primaria
  ninguna. Rellenar con `null` dependería de que Frappe no haga aritmética con
  los huecos; si los coerciona, el path se llena de `NaN` y desaparece la línea
  entera. **Sin verificar en navegador: no usar `null` sin comprobarlo antes.**
- **Dos paletas, y la distinción importa.** AD/A/B/C y el embudo de conducta son
  **estados** → verde/azul/ámbar/rojo. Niveles, grados y secciones son
  **categorías** → azul `#1e6fa8`, teal `#0d9488`, púrpura `#7c3aed`, naranja
  `#e07b1a`. Por eso aquí sí aparecen rojo y ámbar pese a la regla de
  `_variables.scss`, que los prohíbe **como identidad de categoría**.
- **Los gráficos se AÑADEN, nunca reemplazan las tablas**: accesibilidad, que
  Frappe es 100 % cliente (sin JS la página quedaría vacía) y que quitar tablas
  encoge el HTML hasta rozar el `strlen > 2000` del verificador.

#### Conducta y asistencia en pestañas (27/08/2026)

Al pasar de 5 a 12 gráficos, «Conducta y asistencia» se partió en **dos
`.dash-grupo`**, cada uno con **tres pestañas** del componente global `.tabs`
(ver `docs/modulos/ui.md`, que tiene el contrato y el gotcha de Frappe en panel
oculto). Ningún KPI anterior se perdió: se repartieron entre «Proceso de cierre»
y «Panorama».

| Sección | Pestañas |
|---|---|
| Conducta | Resultados (KPIs + G6 + G7) · Proceso de cierre (KPIs + G4 + G5) · Criterios (G8 + T1) |
| Asistencia | Panorama (KPIs + G11 + G12) · Comparativa por sección (G9 + G10) · Estudiantes con más faltas (T2) |

Reglas que costaron un fallo cada una y no hay que deshacer:

- **G7 (la evolución) va FUERA del `if` que exige dato del bimestre a la vista.**
  Es la serie histórica, y en el bimestre en curso —que aún no tiene dato propio—
  es justo cuando más sirve para comparar. Meterla dentro la hacía desaparecer del
  bimestre activo, con el JSON calculado y sin ningún `<div>` donde dibujarla. Lo
  cazó el aserto «cada gráfico tiene dónde dibujarse», que nació de ahí.
- **Sin registro NO se pintan las tarjetas de resultado.** «0 faltas» y «100 % sin
  incidencias» con la asistencia sin tomar son datos **falsos**, no ausentes — el
  mismo error que ya se corrigió una vez en la boleta. Igual con «En logro 0 · 0 %»
  en conducta.
- **El criterio de «bimestres comparables» está extraído a un closure** del
  partial y lo comparten G2, G7 y (en su variante) G11. Copiarlo habría sido otra
  regla duplicada.
- **T1 y T2 llevan su `.tabla-pie` FUERA del wrapper**, como hermano: dentro se
  desplaza con el scroll, pierde el margen y la `.card` lo recorta por su
  `overflow: hidden`.
- **El texto de la celda más intensa del mapa de calor va con especificidad
  `(0,2,1)`.** `.tabla-notas td { color: … }` le ganaba a la clase modificadora y
  el número salía azul oscuro sobre rojo saturado: **2,4:1 de contraste**. Ninguna
  prueba de servidor ve esto.
- **T2 es una tabla POR SECCIÓN**, no una sola con filas de grupo (28/08/2026).
  Con 180 filas seguidas el encabezado de columnas se iba de pantalla y los
  números dejaban de significar nada. Cada bloque lleva su `<caption>` —y no un
  `<hN>`: el contenedor es `<h3>` en pantalla y `<h2>` en el imprimible, así que
  cualquier nivel fijo saltaría uno en una de las dos vistas—. Hay aserto en el
  verificador: **una tabla por sección, cada una con `<caption>` y `<thead>`**.
  De paso el A4 mejora: cada bloque cabe en una hoja (263 px el mayor, útil
  1047 px), así que `page-break-inside: avoid` de verdad evita que una sección se
  parta, y el listado baja de ~5 hojas a 4.

### El mérito de esta pantalla no era el mérito (04/09/2026)

🔴 El bloque **Orden de mérito** —su tabla, el gráfico G3 de brecha y las cards de
`/director/periodos/{id}/stats`— salía de `AnioAcademicoModel::getRankingGrado`,
un ranking **paralelo** al oficial. Le faltaban seis reglas: no exigía
competencias **bloqueadas**, no excluía **extraordinarias** ni **áreas
exoneradas**, metía la **TOE entera** en vez de solo Ética, y no aplicaba
`ROSTER_MERITO`, el **anclaje de retorno** ni la **cascada de desempate**.

**Medido en la BD antes de migrar:**

| Bimestre | Grado | La pantalla decía | El orden de mérito |
|---|---|---|---|
| B3 **abierto** | 1.º primaria | 22 competidores, peor 8.00 | **0 competidores** |
| B3 **abierto** | 1.º secundaria | peor 12.50 | peor **15.00** |
| B2 **cerrado** | los 11 grados | idéntico | idéntico |

🔴 **Lo que mantuvo vivo el defecto fue que en bimestre CERRADO las dos fuentes
coinciden**: al cerrar está todo bloqueado y no hay nada que excluir. Quien
revisara un bimestre pasado veía las cifras correctas. El error solo existía en
el bimestre en curso — que es el que la pantalla abre por defecto y el único en
el que estos indicadores sirven para decidir algo.

**Cómo quedó:**

- El cálculo vive en **`OrdenMeritoModel::statsPorGrado`**, el modelo dueño del
  ranking. Devuelve por grado `mejor`, `peores`, `total` y `en_riesgo`, sobre
  `gradosConRanking` + `rankingGrado` (los dos snapshot-aware). **Ni una consulta
  nueva.**
- `AnioAcademicoModel::getStatsCierre` es hoy una **fachada**: conserva firma y
  shape, así que sus tres consumidores no se tocaron. Se **borraron** sus dos
  privados (`getRankingGrado`, `getGradosConCalificaciones`): dejarlos era dejar
  viva la copia.
- **Un solo recorrido de grados** para los dos bloques: `rankingGrado` no está
  memoizado, y pedirlo dos veces por grado duplicaba 11 consultas por render.
- **Cambio visible que NO es una regresión:** al principio de un bimestre los
  grados sin nada bloqueado desaparecen del bloque. Antes se anunciaba un «1.er
  puesto» que el orden de mérito no reconocía. Por eso el vacío de
  `director/anios/_grados.php` pasó de «aún no hay calificaciones» a **«aún no
  hay competencias bloqueadas»**: con notas puestas y sin bloquear, el texto
  viejo mandaba a buscar el problema al sitio equivocado.
- **Coste medido:** 21-30 ms por render (B1 26 ms, B2 21 ms, B3 30 ms). El
  cerrado lee snapshot y sale más barato que el abierto. No hizo falta memoizar.
- **Verificación:** `verif_cuadros_merito_motor.php` (solo lectura, corre en
  prod) contrasta la fachada contra `rankingGrado` grado a grado y periodo a
  periodo, y comprueba que `AnioAcademicoModel` **no vuelve a exponer
  `promedio_general` ni a ordenar por promedio**. ⚠️ Lo que prohíbe es el
  RANKING, no el promedio: `getResumenBimestre` sigue promediando por estudiante
  a propósito (ver abajo).

### Estudiantes en riesgo (04/09/2026)

Sección propia debajo de Orden de mérito, en pantalla y en el A4. Lista **a todos
los que acumulan 3 competencias en C o más** (`OrdenMeritoModel::RIESGO_MIN_C`),
por grado, resaltando el número de C. **Sin tope por grado** (decisión del
usuario): un grado aporta las filas que tenga, y un grado sin casos no aparece.

- **No cuesta ninguna consulta:** `num_c` ya lo calcula el ranking. Puesto,
  promedio y número de C de una fila salen de la **misma fila** del motor, así
  que no pueden contradecirse entre sí.
- Orden: **más C primero**; a igual número de C, primero el promedio más bajo.
- **Una tabla por grado**, con el rótulo en `<caption>` — mismo patrón que el
  listado de inasistencias (T2), por el mismo motivo: 118 filas seguidas (B1)
  dejan el encabezado de columnas fuera de pantalla.
- **Tres estados vacíos, no uno**, y los tres dicen su causa: sin ranking («aún
  no hay competencias bloqueadas»), con ranking y sin casos («ninguno acumula 3
  C»), y la sección **nunca desaparece** — si se ocultara, no habría forma de
  distinguir «no hay nadie en riesgo» de «se rompió».

🔴 **HAY DOS PREGUNTAS DISTINTAS Y YA NO COMPARTEN RÓTULO (07/09/2026).** La del
bloque de Calificaciones es el **promedio general** por debajo de `NOTA_MIN_B`,
contado por NIVEL (`getResumenBimestre`). La de esta sección es el **número de
C**, contado por GRADO sobre el universo del mérito. Medido en B2: **0** por
promedio contra **77** por número de C.

Hasta el 07/09/2026 las dos se llamaban **«En riesgo»** en la misma pantalla, a
media pantalla de distancia, y lo único que las separaba era un pie de tres
párrafos. Se resolvió **en el origen, no con una nota al pie**: aquella columna
pasó a llamarse **«Promedio en C»** (`index.php`, `imprimir.php` y
`director/anios/_panel-bimestre.php`, que también pinta
`/director/periodos/{id}/stats`). Se eligió el literal y no «Promedio bajo 11»
porque el 11 es `NOTA_MIN_B`: hardcodearlo en un rótulo lo desincroniza en
silencio si la escala se mueve.

**No unificar las cifras** — son preguntas legítimamente distintas. Dos
verificadores lo sostienen: `verif_cuadros_merito_motor.php` imprime las dos
juntas en cada corrida, y `verif_direccion_superficies.php` falla si la cadena
«En riesgo» vuelve a aparecer en el HTML (el rótulo vivo es «Estudiantes en
riesgo»).

**Volumen medido:** B1 → 118 filas en 10 grados; B2 → 77 en 8; el bloque mayor,
28 filas (1.º secundaria). Cabe holgado en la hoja A4 (~400 px de 1047 útiles).

⚠️ `.cuadros-top` pasó a tener **dos consumidores** (`_top-incidencias.php` y
`_estudiantes-riesgo.php`): al tocarlo, probar los dos. El `<table>` y el bloque
del segundo llevan el modificador **`--riesgo`**, que en el bloque es un
**marcador sin estilo a propósito**: es lo que permite al verificador contar «una
tabla por unidad» sin mezclar los dos listados. No borrarlo por parecer inerte.

### Rediseño de la sección: que se vea y que se pueda recorrer (07/09/2026)

La sección era correcta y **inencontrable**: bloque 4 de 7, detrás de dos tablas
y dos gráficos, sin decir en ninguna parte cuántos eran (había que sumar once
`<caption>` a mano) y sin forma de ubicar a una persona entre 118 filas.

**Banda de magnitud** (`.cuadros-riesgo__banda`) — total, grados con casos, % del
alumnado evaluado y el caso más alto. Va en pantalla **y en el A4**: es dato, no
control.

🔴 **EL PESO VISUAL ES DE LA SECCIÓN, NO DE LAS PERSONAS.** Las filas siguen
neutras: ni rojo, ni ámbar, ni semáforo por gravedad. La regla de
`_cuadros.scss` («hay personas, no procesos») **no se derogó** — se precisó: lo
prohibido es teñir a alguien por su gravedad, no dar jerarquía al bloque. Ojo al
revisarlo: el motivo de esa regla **no es el mismo en los dos consumidores**. En
inasistencias es **normativo** (no hay retiro automático por faltas); en riesgo
es por analogía, porque comparten clases CSS. La banda usa `$brand-dark`, el azul
institucional del topbar y del login: no es color de estado ni tono de
wayfinding, así que no le roba significado a nada.
🔴 **El A4 la imprime PLANA** (fondo blanco, borde negro). `.cuadros-print` lleva
`print-color-adjust: exact`, así que sin esa contraparte cada informe salía con
un rectángulo macizo de tinta azul marino.

**Filtrado en cliente** (`resources/js/cuadros-riesgo.js`): buscador por nombre,
sección o grado + chips de nivel + chips de grado, los tres en conjunción. El
buscador reutiliza el normalizador canónico de `nomina.js` (NFD sin diacríticos),
así que «nunez» encuentra a «NÚÑEZ» y al revés. Un grado sin filas visibles
oculta **su bloque entero** —dejarlo con el rótulo y el cuerpo vacío se lee como
un grado sin casos—, y los chips de grado se acotan al nivel elegido.

**Cuarto estado vacío**: «tu filtro no encontró a nadie», que no es ninguno de
los tres anteriores. El contador es `role="status"` para que el resultado del
filtro también exista para quien no ve desaparecer las filas.

🔴 **LA BARRA DE FILTROS NACE `hidden` Y LA DESTAPA EL JS.** Sin JS no quedan en
pantalla un buscador que no busca y unos chips que no filtran; el informe se
sigue leyendo entero, que es lo que el A4 demuestra que basta. Misma regla que
las pestañas.
⚠️ **Y eso necesitó una regla de CSS que no es obvia.** `[hidden] {display:none}`
vive en la hoja del NAVEGADOR con especificidad (0,0,1), y cualquier `display:
flex` sobre una clase es (0,1,0) y le gana: el atributo estaba puesto y el
elemento se veía igual. Le pasaba a la barra **y** a los chips (`.orden-chip` es
`inline-flex`). Hacen falta `.cuadros-riesgo__filtros[hidden]` y
`.orden-chip[hidden]` explícitos, y hay aserto sobre el CSS **servido** porque
desde PHP el defecto es invisible.

**Perfil AD/A/B/C por estudiante**, no solo el número de C: responde «qué tan
grave es» sin salir de la página. `num_a` **se deriva por resta** en
`OrdenMeritoModel::statsPorGrado` (los cuatro literales son disjuntos y
exhaustivos sobre 00-20) — cero consultas nuevas, y sale igual por las dos rutas
del ranking, en vivo y snapshot. Hay aserto de que `AD+A+B+C = competencias` y de
que A nunca es negativo: si esa premisa cayera, la tabla enseñaría un perfil
plausible y falso sin ningún error.
⚠️ La celda del nombre pasó a `th scope="row"` (nueve columnas: un lector de
pantalla necesita saber de quién es cada celda) y se disfraza de `td` dentro de
`--riesgo`. Esa regla **se colaba en el papel** —(0,3,2) le gana a
`.cuadros-print .cuadros-top` (0,2,0)— e imprimía el nombre a 14 px con la fila a
8 px, partiendo cada fila en dos. El bloque `.cuadros-print` la reescribe.

**Anchos en porcentaje + `table-layout: fixed`.** Con el reparto automático cada
tabla medía sus columnas según el largo de los nombres que le tocaron: medido en
B1, «Sección» caía en x=529 en 1.º de primaria y en x=624 en 2.º. Son diez tablas
apiladas que se leen como una sola. En porcentaje y no en px porque el mismo
marcado se pinta en ~1150 px en pantalla y en 718 px en el A4.

**Índice de anclas** (`.cuadros-indice`, solo pantalla): un chip por sección
realmente renderizada —Reaperturas es condicional— con el conteo en el de riesgo.
Resuelve el hallazgo de **toda** la página. Su destino es `.dash-grupo__titulo`,
que ganó `scroll-margin-top` porque el topbar es `sticky`: sin él, saltar a un
ancla deja el rótulo tapado por la barra.

⚠️ **`.orden-chip` tiene ahora dos consumidores** (`/matriculas` sobre `<a>`,
`/admin/cuadros` sobre `<button>` y sobre `<a>`). El reset de `<button>` va en un
selector `button.orden-chip` acotado a propósito: `/matriculas` no lo ve.

### El papel no tiene cursor (04/09/2026)

🔴 Frappe Charts deja los valores **solo en el tooltip**. En pantalla se leen
pasando el cursor; en una hoja impresa no existen. Medido antes de corregirlo:

| | |
|---|---|
| Gráficos en el A4 | **11** |
| Con sus números legibles en papel | **1** — el `pie` del embudo, cuya leyenda SVG escribe `Cerradas: 12` |
| Con una tabla al lado que tuviera esos valores | **0** |

Y la pérdida era más ancha que el hover: tres KPIs que la pantalla mostraba y el
papel no (**Esperan al tutor**, **Esperan al auxiliar**, **Estudiantes con
conducta calificada**), las **notas de lectura** de cada gráfico, y el
`X de Y no cumplen` del mapa de calor. Los dos primeros KPIs llegaban al papel
**solo** por la leyenda del embudo — y ese gráfico no se registra cuando la suma
es cero, así que el informe podía quedarse sin decir a quién se está esperando.

**Descartado por medición, no por gusto: `valuesOverPoints` no bastaba.** La
opción existe en la 1.6.2 y sirve para `bar` y `line`, pero en las **apiladas
solo etiqueta el último dataset con el acumulado** (G6 perdería AD/A/B/C y G12 el
desglose justificadas/sin justificar) y el `pie` ni la lee. Además
`truncateLegends` viene activo y recorta las etiquetas largas.

**Cómo quedó:**

- **`$chartTablas`**, en `_chart-data.php` junto a `$chartData`: normaliza las
  **tres formas** del dato (`values`, `datasets`, y el `mejor`/`peor` de la
  brecha) a una sola, más la columna de texto de los criterios.
  ⚠️ **`$chartData` NO cambia de forma**: `cuadros.js` lee `d.mejor`/`d.peor` y el
  verificador valida esa estructura exacta. La normalización vive aparte.
- **La unidad deja de estar en el JavaScript.** El sufijo (`% en logro`,
  ` faltas`…) estaba escrito a mano en `cuadros.js`; que la tabla lo repitiera
  habría sido otra regla duplicada. Ahora viaja con el dato y `cuadros.js` la lee
  (`unidad(d)`). Una sola fuente para gráfico, tooltip y papel.
- **Las notas también.** Estaban a mano en `index.php` y el A4 no las imprimía;
  ahora salen de `$chartTablas[...]['nota']` y las leen las dos vistas.
- **Partial único `_tabla-grafico.php`**: en pantalla dentro de un `<details>`
  cerrado («Ver valores»), en el A4 **suelta**.
  🔴 **Un `<details>` cerrado NO IMPRIME SU CONTENIDO** — el mismo motivo por el
  que el explorador de criterios tiene una vista imprimible aparte. Por eso el
  imprimible pasa `$abierta = true`, y hay aserto que lo vigila: es el fallo
  silencioso de esta entrega (saldría en blanco, sin ningún error).
- 🔴 **La tabla va FUERA de `.cuadros-print__chart`, como hermana.** Ese
  contenedor lleva `page-break-inside: avoid`: con la tabla dentro, el bloque
  entero saltaría a la hoja siguiente dejando media página en blanco.
- **El mapa de calor imprime su denominador**: `12/28` bajo el porcentaje. Las
  columnas suben de 26 a 34 px en el A4 — 120 px de la columna de sección más
  10×34 = **460 px de los 718** útiles, cabe de sobra.
- Un hueco en la tabla es **un guion, no un cero**: «0 faltas» y «no hay dato»
  son afirmaciones distintas, y en papel nadie puede preguntar cuál era.

**Coste medido:** 95 filas nuevas en 11 tablas ≈ **1,5 hojas más** de A4. El
usuario decidió que crezca: la prioridad es que el dato esté.

**Verificación** (en `verif_direccion_superficies.php`): cada gráfico tiene su
tabla; los valores **cuadran celda a celda con el mismo JSON** que dibuja el
gráfico (asociando cada tabla a su gráfico por posición — comprobar que el número
«aparezca en la página» sería un aserto inerte en un HTML de 300 KB); en el A4 no
hay ni un `<details>`, ninguna tabla cuelga del bloque no partible, y están los
KPIs y las notas. Probado con mutantes: celda alterada, celda extra, fila perdida
y `<details>` reintroducido lo hacen fallar.

#### 🔴 Contraste del mapa de calor — línea base medida

Los seis escalones, en estado normal y en hover, **medidos en navegador** sobre
`getComputedStyle` (no estimados). **Al tocar cualquier color, volver a medirlos.**

| | n0 | sd | n1 | n2 | n3 | n4 |
|---|---|---|---|---|---|---|
| normal | — | 13,98 | 14,11 | 12,31 | **4,59** | **4,83** |
| hover | 13,98 | 12,91 | 12,92 | 10,84 | **5,81** | **6,47** |

- **Nunca `background: inherit` en el hover.** Se probó: `inherit` toma el fondo
  del `<tr>`, que no tiene ninguno, la celda queda transparente y el número
  **blanco** de n4 desaparecía sobre el gris de la fila. Cada escalón tiene su
  par de hover explícito.
- **La dirección del paso no es uniforme, y es obligatorio que no lo sea:**
  n4 no puede aclararse (blanco sobre `#ef4444` cae a 3,8:1) y n3 no puede
  oscurecerse (texto oscuro sobre `#b45309` cae a 2,9:1). Lo que sí se conserva
  es que la fila entera cambia y que la escala sigue siendo monotónica dentro de
  ella.
- `sd` no aparece con los datos actuales (todas las secciones respondieron los 10
  criterios); su regla se comprobó forzando la clase en el navegador.

#### El imprimible A4

`print.php` **no procesa `$page_scripts`**, así que la librería y el JS se cargan
con `<script src>` a mano en la vista, como hace `boleta/alumno.php` con el QR.

🔴 `.cuadros-print` lleva **`max-width: 718px` y no es cosmético**: Frappe mide el
contenedor al instanciar y le escribe un `width` en px al SVG. Con un contenedor
fluido el gráfico nacería del ancho de la VENTANA y saldría cortado en la hoja.
718px ≈ 19 cm = A4 (21 cm) menos el margen de 1 cm por lado de `@page`.

**Desde el 08/09/2026 lleva también `min-width: 718px`, el mismo número.** El
`max-width` solo ponía un techo: en una ventana estrecha la hoja se compría igual, y
`print-fit.js` **no puede ayudar ahí** —reescribe el `<meta viewport>`, que es un
mecanismo *exclusivamente móvil*: el escritorio lo ignora—. Con el ancho fijo, además,
**desaparece una dependencia de orden entre scripts que rompía el PDF de los móviles**:
`cuadros.js` va dentro del `$content` y se ejecuta **antes** que `print-fit.js`, así que
Frappe medía el contenedor con el viewport todavía sin ajustar (311 px en un móvil de
371) y los 11 gráficos salían impresos al 43 % del ancho del papel. Ahora Frappe mide
702 px pase lo que pase. En impresión es inocuo: `@page boleta` deja 194 mm = 733 px.

## Verificación

```bash
php database/verificaciones/verif_roles_direccion.php          # punto único + las 2 excepciones
php database/verificaciones/verif_horario_modelo.php           # contraste contra git HEAD
php database/verificaciones/verif_rol_director_academico.php   # migración 055 + color
php database/verificaciones/verif_direccion_solo_lectura.php   # las 30 guardas + integridad de vistas
php database/verificaciones/verif_direccion_superficies.php    # fases 4-7, con render real
php database/verificaciones/verif_cuadros_merito_motor.php     # el merito del tablero es el oficial
```

Todas son de **solo lectura** y corren en producción.

> ⚠️ `verif_horario_modelo.php` reconstruye el algoritmo VIEJO desde `git HEAD`.
> Cuando estos cambios se mergeen a `main`, ese contraste dejará de tener sentido
> (HEAD ya traerá el código nuevo) — es un verificador **de la migración**, no
> permanente.

> **Actualización 07/09/2026 — banda sobre fondo claro.** Mérito azul
> (`$color-info-bg` + `$color-info`) y riesgo rojo (`$color-error-bg` +
> `$color-error`), acento de 5 px. Nacieron sobre `$brand-dark` y era un error
> de contraste DE ACENTO: azul sobre azul no se separaba, y el rojo había que
> aclararlo hasta perder el rojo. Contrastes medidos: 10,9:1 y 5,9:1.

## Desglose de las competencias en C (07/09/2026)

Cada estudiante de la lista trae una fila hermana con **qué competencias tiene en C**:
área, curso, competencia, nota y **docente responsable**. En pantalla va en un
`<details>` plegado; en el A4 va **suelto**. Decisión del usuario: solo las C, en las
dos superficies, y con docente.

- **Punto único**: `OrdenMeritoModel::detalleCompetenciasC()`. Vive en el modelo del
  mérito, no en `CalificacionModel`, porque **replica el universo del mérito**. Sacarlo
  de `getBoletaAlumno` —que incluye extraordinarias, tutoría entera y las transversales
  agregadas— listaría notas que la fila no cuenta.
- ⚠️ **Replica los filtros de la NOTA, no los del ALUMNO.** Van: bloqueo,
  `extraordinaria = 0`, transversal/tutoría salvo Ética, exoneraciones. **NO** van
  `ROSTER_MERITO` ni el anclaje de retorno: esos deciden quién compite y la lista de
  matrículas ya viene resuelta por el ranking. Volver a aplicarlos solo podría quitar
  filas de una matrícula ya seleccionada —justo una que viene del snapshot y hoy no
  pasaría el roster— y produciría un descuadre falso.
- **Una sola consulta** para todos los grados (`IN`), llamada al final de
  `statsPorGrado`. Coste medido: `getStatsCierre` pasa de 26 ms a 40 ms en B1.
- **El docente sale de `cal.carga_id`**, camino que el mérito no usa para calcular. Es
  seguro: dentro de este universo hay **cero** competencias con dos cargas (el JOIN a
  `bloqueos_competencia` fija la carga dueña). Fuera de él hay 1 072 duplicadas y 1 052
  notas en cargas inactivas.

🔴 **EL GUARD DEL DESCUADRE.** `orden_merito_snapshot` guarda **solo agregados**: en un
bimestre cerrado la fila viene congelada y el desglose se calcula en vivo. Si
`count(detalle) !== num_c`, el modelo deja `detalle_c` en **NULL** y la vista dice que no
puede mostrarlo, en vez de pintar dos cifras que se contradicen. `NULL` y `[]` significan
cosas distintas. Hoy cuadran **195 de 195** (B1 118, B2 77).

🔴 **EL ASERTO ESTUVO CIEGO Y HAY QUE SABER POR QUÉ.** La primera versión trataba ese
NULL como legítimo, y con eso no detectó **ninguno** de los dos mutantes probados (quitar
el filtro de extraordinarias y quitar el de transversales): una réplica rota devuelve otro
número de filas, el modelo lo convierte en NULL y el verificador lo daba por bueno — **el
guard que protege la pantalla enmascaraba justo los bugs que el verificador existe para
cazar**. El umbral es CERO a propósito. Si algún día falla, hay que distinguir si fue una
rectificación legítima tras el cierre o una réplica rota; **no relajar el umbral**.

⚠️ **El mutante de extraordinarias no es observable con los datos actuales**: las 275
extraordinarias de B1 tienen todas nota > 10, así que quitar ese filtro no cambia el
desglose de C. El filtro es correcto y necesario, pero esa rama no está ejercitada.

**Peso.** El desglose son 778 filas en B1 y 429 en B2 (~+9 hojas de A4). El HTML pasó de
425 KB a 1,5 MB, y **el 62 % de eso eran espacios de indentación**: el bucle interno se
repite 778 veces anidado ocho niveles. Se emite **sin sangrar** a propósito y el
imprimible baja a 816 KB. No hay minificador de HTML en el pipeline (gulp solo toca SASS
y JS), así que el único sitio donde se puede arreglar es la propia vista.

⚠️ **`.cuadros-top__bloque--riesgo` pasa a `page-break-inside: auto` en el A4.** El
`avoid` se midió cuando un grado eran ~28 filas (~400 px de 1047); con el desglose un
grado se pasa de hoja y el `avoid` lo empujaría entero, dejando media página en blanco.
Las FILAS conservan su `avoid`.

---

## Dirección solo ve bimestres CERRADOS (08/09/2026)

Aprobado y **construido el mismo día**. En `dev`, **sin desplegar**. Sin migración.
Extiende la «regla del dato oficial» de arriba al **bimestre entero**.

### Por qué

Un director podía abrir `/admin/cuadros` sobre el bimestre **activo**, que está a medio
llenar, y ver porcentajes, rankings y una línea de tendencia presentados igual que los del
bimestre cerrado.

🔴 **Y la serie de evolución lo incluía.** `$bimestresComparables` (`_chart-data.php`) solo
descarta un bimestre si **algún nivel tiene CERO**; en cuanto los dos tienen algo, el
último punto cae en picado — no porque baje el rendimiento, sino porque hay 22 notas de
11 081. Es el «desplome falso» que este tablero ya sufrió una vez.

### Decisiones (cerradas antes de escribir código)

1. **Exclusivo para `ROLES_DIRECCION`.** `admin` y `registro_academico` conservan intacto
   el comportamiento actual, **incluido ver el bimestre activo EN VIVO**: son quienes
   tienen que vigilar el avance.
2. Las **series anuales se cortan** en el último cerrado para el director.
3. El **imprimible A4 lleva la misma restricción**, y ahí **se niega con 404** en vez de
   caer al último cerrado: el A4 va firmado con el sello del Director EBR. Antes
   `?periodo_id=3` funcionaba sin validar nada.
4. **Sin excepciones dentro de la pantalla**: se ve hasta donde el selector deja.
   ⚠️ Esto **DEROGA para el director, EN ESTA SUPERFICIE**, la excepción de la asistencia
   en vivo documentada más arriba. La excepción sigue viva en
   `/consulta-notas/{p}/seccion/{s}/asistencia`, que el director conserva.
5. Sin ningún bimestre cerrado → **estado vacío explicativo** («todavía no hay ningún
   bimestre cerrado»), no el «No hay bimestres disponibles» genérico, que para Dirección
   sería falso: haberlos los hay.

### Qué se construyó

**1. `app/Helpers/helpers.php`, junto a `ROLES_DIRECCION`** — punto único:
`solo_bimestres_cerrados()`, `periodos_cerrados()`, `ultimo_periodo_cerrado()`.

🔴 **Es el primer helper de punto único del archivo que lee la SESIÓN**: los seis
anteriores (`orden_alfabetico`, `matriculas_vigentes`, `matricula_documento`,
`roster_evaluacion`…) emiten fragmentos SQL. Se aceptó a propósito, porque la pregunta que
resuelve es de **audiencia**, no de datos: no dice qué es un bimestre cerrado —eso lo dice
`periodos.estado`—, sino a quién se le puede enseñar uno que aún no lo está. El patrón es
`Padre\PanelController::getPeriodoVigentePadre()`, que ya resuelve «esta audiencia solo ve
cerrados» con un resolutor propio.

⚠️ **No viene a unificar las 16 comparaciones con `'cerrado'`** repartidas por `app/` y
`resources/views/` en 7 redacciones distintas. Viene a que la pregunta por la AUDIENCIA
tenga un solo dueño y no nazca la 17.ª copia inline.

**2. `Admin\CuadrosEstadisticosController`** — tres métodos privados nuevos:
`periodosVisibles()`, `elegirPeriodo()` y `serieIds()`. `index()` e `imprimir()` los usan.

- 🔴 **El controlador no puede contener la palabra `SELECT`** fuera de comentarios
  (`verif_direccion_superficies.php` lo mide sobre los *tokens* de PHP, **cadenas
  incluidas**): todo el corte es PHP sobre lo que ya devuelve el modelo.
- 🔴 **`ControlOperativoModel::getPeriodos()` NO se toca**: lo comparte `/admin/control`.
  *(El plan original decía que también `/consulta-notas`; es falso — esa pantalla tiene su
  propio SQL inline y su propio `getPeriodo()` privado. Lo que comparte con ella es
  `getPeriodoPorDefecto()`.)*
- ⚠️ **`getPeriodoPorDefecto()` no servía**: devuelve el activo y, sin él, `getPeriodos()[0]`,
  que con `ORDER BY anio DESC, numero ASC` es el **bimestre 1** — el más viejo del año, no
  el último cerrado.
- Con la restricción activa el `?periodo_id` **se valida contra la lista** y la fila se
  toma de ahí; sin ella, el camino es literalmente el de siempre (`getPeriodo()` resuelve
  cualquier id, esté o no en el selector).

**3. `resources/views/admin/cuadros/_chart-data.php`** — recibe `$serieIds` y corta
**tres gráficos y solo tres**: G2 `evolucion`, G7 `conductaEvolucion`, G11 `asisEvolucion`.

- ⚠️ **El corte viaja por IDS, no por `estado`**: solo G2 expone `estado` en su salida.
  Preguntar por el estado aquí funcionaría en uno de los tres y en los otros dos pasaría de
  largo **sin fallar**.
- ⚠️ **Va al EJE de las series, jamás a `$condLit`**: G6 comparte esa fuente con G7 y se
  recorta al bimestre a la vista, así que filtrar la fuente lo haría **desaparecer**.
- **`null` = sin filtro**, y es lo que reciben admin, RA y el verificador (que arma su
  propio `$bloques` y renderiza la vista de verdad). Un default restrictivo habría puesto
  en rojo sus asertos de series y habría cambiado el tablero de quien no debía cambiar.
- Las **tres notas de lectura** llevan coletilla condicional (`$notaSoloCerrados`): sin
  ella, un director lee «faltan bimestres» como un fallo del tablero.

**4. `resources/views/admin/cuadros/index.php`** — el `<select>` se restringe solo (se
alimenta de `$periodos`), más el banner `.alert alert--info` cuando se pidió un bimestre
abierto y el estado vacío explicativo.

### Verificación

`verif_direccion_superficies.php`, bloque «DIRECCION — solo bimestres cerrados». Los
métodos privados del controlador se ejercen por **reflexión** (`newInstanceWithoutConstructor`
+ inyección de `controlModel`), y el rol se simula poniendo `$_SESSION['auth_user']`, que
es lo único que mira `Session::hasRole()`.

**Las dos ramas de cada guarda**: por cada rol de `ROLES_DIRECCION` la lista trae solo
cerrados, el defecto es el **último** y un `?periodo_id` abierto no se resuelve; y para
`admin` y `registro_academico` la lista **sigue trayendo el activo**, el id abierto **sí**
se resuelve y `serieIds()` es `null`. Esa segunda mitad es la que se rompe en silencio.

🔴 **El corte de las series se mide con fuente SINTÉTICA a propósito.** Contra la base solo
prueba algo si el bimestre abierto tiene notas en los **dos** niveles; donde tenga 0,
`$bimestresComparables` ya lo descarta por su cuenta y el aserto pasaría sin ejercer nada.

### Medido

**Batería del repo: 35 de 36 verificadores en verde**, y los 30 asertos del bloque nuevo
también. El único rojo es `verif_direccion_superficies.php`, con 5 fallos **preexistentes**
—ver abajo—, reproducidos uno a uno en una copia limpia de `HEAD` contra la misma base.

### 🔴 Lo que este trabajo DESTAPÓ (preexistente, NO implementado)

**Con el bimestre a la vista sin calificaciones, G2 se calcula pero no tiene dónde
dibujarse.** `admin/cuadros/index.php:146` sustituye la sección entera de calificaciones
por un estado vacío cuando `$bloques['calificaciones']['niveles']` viene vacío — y ahí
dentro está el `<div id="chart-evolucion">`. Pero `$chartData['evolucion']` **sí existe**:
sale de los bimestres anteriores, que sí tienen datos. El JS busca un contenedor que la
vista no emitió. Lo mismo arrastra a su tabla de valores y a su nota de lectura, en
pantalla y en papel: **5 asertos en rojo**.

Estaba desde antes y no se veía porque el verificador abortaba antes de llegar (ver la
nota de la 056, abajo). **Para Dirección este cambio lo hace desaparecer** —ya no ve un
bimestre a medio llenar—, pero para `admin` y `registro_academico` sigue vivo y ahí se
queda: arreglarlo es decidir si la evolución ANUAL debe vivir dentro de la sección del
bimestre, que es otra pregunta.

⚠️ **La 056 destapó estos 5 fallos, no los causó.** A la BD del equipo de escritorio le
faltaba la migración `056_codigo_criterios_conducta.sql` y
`verif_direccion_superficies.php` moría en `getIncumplimientoCriterios()` **antes** del
render. Aplicada en local el 08/09/2026 (idempotente: 10 criterios, 10 con código).

### Pendiente

- 🔴 **Sin probar en navegador.** Sería la primera vez que una superficie de Dirección se
  abre **con sesión de director**: todas las pruebas del módulo se han hecho como
  administrador, y ese pendiente lleva tres deploys abierto.
- ⚠️ **La BD de este equipo no es la de la laptop**: allí el III Bimestre tenía 222
  calificaciones y aquí **0**, así que el antes/después del gráfico —el síntoma que motivó
  todo— no es observable aquí. Por eso el corte de las series se mide con fuente
  sintética.

### Fuera de alcance (decidido)

- `/consulta-notas/{p}/seccion/{s}/asistencia` sigue mostrando el bimestre activo.
- `/admin/control` admite directores y usa el mismo `getPeriodos()` sin restricción.
