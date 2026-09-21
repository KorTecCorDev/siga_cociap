# Módulo: Calificaciones, criterios y bloqueos

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.


## Estadísticas por competencia — contadores del resumen (01/09/2026)

> **Estado: EN PRODUCCIÓN desde el 02/09/2026** (merge `5c353f1`). Sin migración, sin métodos de modelo nuevos,
> sin consultas nuevas y sin JS. Bloque de cifras encima de la tabla de alumnos, en
> **cuatro** pantallas: el resumen del docente, `/consulta-notas/{p}/carga/{c}`, el
> historial del docente en un bimestre cerrado y el panel de tutoría
> (`/docente/tutoria/{periodo}`, un bloque por cada transversal).

### Qué muestra y con qué condición aparece

Evaluados · Sin evaluar · Aprobados · Desaprobados (las dos últimas con su %), más una
tarjeta de Exonerados cuando los hay, y una barra apilada AD·A·B·C con su leyenda.

**Basta con tener los criterios CONFIRMADOS.** No exige aprobar y bloquear: el bloque
vive donde ya se puede entrar, y las tres pantallas que lo incluyen tienen su propia
puerta (`competenciaListaParaResumen` en el docente, `bloqueo_id != null` en la
consulta). El parcial no añade ninguna guarda propia — no le corresponde decidir quién
ve qué.

### 🔴 Aprobado depende del NIVEL, y no es «en logro»

| | Aprueba | Desaprueba |
|---|---|---|
| **Primaria** | AD, A | B, C |
| **Secundaria** | AD, A, B | C |

Punto único: `LITERALES_APROBATORIOS` + `nota_es_aprobatoria()` en `helpers.php`, junto
a `NOTA_MIN_*`. Es la primera regla del repositorio que ramifica por nivel *en la
calificación*: `nota_a_literal()` sigue ignorando el nivel porque **la escala es la
misma en los dos**; lo que cambia es dónde está la línea del aprobado.

⚠️ **No unificar con «en logro» de `AnioAcademicoModel::getResumenBimestre()`**, que
cuenta AD+A en los dos niveles y alimenta `/admin/cuadros` y
`/director/periodos/{id}/stats`. Son dos preguntas distintas —«¿alcanzó el logro
esperado?» frente a «¿aprobó?»— y en secundaria dan números diferentes porque B aprueba.
La que se parece a una copia desactualizada de la otra no lo es.

### Las tres reglas del conteo, y por qué

1. **Evaluado ⟺ `promedio !== null`**, con `!==` y nunca con `empty()`: una nota 0 es un
   cero real. Equivale a «no tiene ningún criterio con nota» por el invariante *fila en
   `calificaciones` existe ⟺ el alumno tiene nota viva*.
2. **Los EXONERADOS salen del universo** (ni evaluados, ni sin evaluar, ni aprobados) y
   se muestran en su propia tarjeta para que el total cuadre a la vista. **Es
   obligatorio, no cosmético:** exonerar NO borra las notas anteriores —para que la
   exoneración sea reversible—, así que un exonerado puede traer `promedio` no nulo y sin
   el filtro sumaría como aprobado mientras su boleta dice `EXO`. Medido: **4 exonerados
   con nota** en la copia de agosto de 2026.
3. **Aprobados + desaprobados = EVALUADOS**, y los porcentajes van sobre esa base. Quien
   no tiene nota no es quien desaprobó; meterlo en el denominador inflaría el
   desaprobado con alumnos que nadie llegó a calificar.

Con `evaluados === 0` **no se pinta barra ni porcentajes**, solo el aviso: «0 %» con la
competencia sin calificar es un dato falso, no ausente — el mismo criterio que ya se
aplicó en la asistencia sin registro y en la boleta.

### Arquitectura

- **Cálculo:** `stats_competencia($alumnos, $exonerados, $nivelCodigo)` en
  `helpers.php`. Función pura, sin BD: recibe el `$alumnos` que **ya está en memoria**
  desde `getResumenCompetencia()`. Por eso el bloque no cuesta ni una consulta, y en
  particular **no vuelve a disparar el N+1** de ese método (una consulta por alumno ×
  criterio). Al ser pura, el verificador la mide directamente.
- **Render:** `resources/views/shared/_stats-competencia.php`. Solo pinta; si el
  universo es 0 no emite nada.
- **Puntos de inclusión (3, para 4 pantallas):** `docente/resumen-competencia.php`,
  `consulta-notas/_tabla.php` y `docente/tutoria.php`. El historial del docente lo
  recibe gratis porque ya delegaba en `_tabla.php`. Si alguna vez hiciera falta apagarlo
  en una de ellas, la bandera va en quien hace el `require`, no dentro del parcial.
- **Dos formas de entrada.** Por convención (`$alumnos`, `$exonerados`, `$nivelCodigo`)
  cuando la vista ya trabaja con UNA competencia; y con prefijo (`$statsAlumnos`,
  `$statsExonerados`, `$statsNivel`, `$statsTitulo`) cuando pinta VARIAS en una sola
  tabla y no puede pisar sus propias variables. Tutoría usa la segunda: arma el array
  por competencia desde `$promedios[matricula][competencia]`.
- 🔴 **El parcial hace `unset` de las cuatro variables con prefijo al terminar.** Se
  monta DENTRO DE UN BUCLE en `carga.php` y en `tutoria.php`: sin eso, la segunda
  competencia repetiría las cifras de la primera **y nadie lo notaría**, porque los
  números seguirían siendo plausibles. Hay aserto que lo sostiene.
- **El corte de aprobación sale del helper ya normalizado** (`$stats['aprobatorios']`).
  La vista no vuelve a resolver `prim`/`sec`: tutoría maneja el nivel como `'primaria'`
  y las otras como `'prim'`, y repetir la normalización habría caído del lado
  equivocado en una de las dos.
- **Estilos:** `resources/sass/components/_stats-competencia.scss` (componente, no
  página: lo comparten dos módulos), registrado en `app.scss`. `gulp build` obligatorio.
- Las **transversales** llevan el bloque igual que las demás: tienen promedio, literal y
  el mismo roster. El orden de mérito las excluye por otra razón (no compiten en el
  ranking), que aquí no aplica.

### 🔴 Los colores son EXACTAMENTE los de `.nota-literal`, y son OCHO

Cada tramo de la barra toma del chip que la tabla pinta dos centímetros más abajo
(`pages/_dashboard.scss:1281`) **sus dos valores**: el `background` para el relleno
(AD `#dbeafe`, A `#d1fae5`, B `#fef9c3`, C `#fee2e2`) y el `color` para el borde
(`#1d4ed8`, `#065f46`, `#854d0e`, `#991b1b`). Es el mismo recurso que la tabla usa en
`.nota-numeral`: relleno claro, contorno del tono fuerte. Y la leyenda no lleva un
cuadrito de color propio: lleva **el chip `.nota-literal` de verdad**.

Ese vínculo es el punto del bloque —el lector reconoce «el color de AD» sin leer la
leyenda— y costó dos vueltas: la primera versión pintaba el relleno con el color de
TEXTO, mucho más oscuro, y los dos azules no se leían como el mismo. **No** usar los
`--lit-*` de `_anio-academico.scss`, que además de más saturados **invierten AD y A**
(allí AD es verde y A azul): el mismo literal saldría de un color en la barra y de otro
en la celda de al lado. La coherencia local manda sobre la de `/admin/cuadros`.

**Hay un aserto que compara los OCHO valores contra el chip en el `app.css` compilado.**
Si alguien retoca `.nota-literal` y no la barra, el vínculo se rompería en silencio —los
dos seguirían siendo azules, pero ya no el mismo azul—.

### La barra es una REJILLA de fracciones (02/09/2026)

Nació como flex con anchos en `%` y se rehízo como `display: grid` con unidades `fr`.
El cambio lo disparó un bug, pero **quitó de en medio tres defectos de golpe**, y por eso
no hay que volver al reparto en porcentajes:

- 🔴 **El bug: un tramo al 100 % salía recto por la izquierda.** Los extremos trazaban la
  curva con `:first-child` / `:last-child`, las dos reglas escritas con el **atajo**
  `border-radius`. Un tramo único casa con **las dos**: misma especificidad, gana la
  última **entera** —y el atajo no se fusiona, **reemplaza las cuatro esquinas**—, así
  que se quedaba con `0 8px 8px 0`. Medido: `TL:0px TR:8px BR:8px BL:0px`. Ahora cada
  tramo es su propia píldora con su radio: **el caso del 100 % dejó de existir**.
  ⚠️ El aserto que había solo comprobaba que las dos reglas **mencionaran**
  `border-radius`, cosa que hacían, así que **dio verde a la barra rota**. Los asertos
  nuevos vigilan el síntoma (que no haya `:first-child`/`:last-child`), no la presencia.
- **El `gap` ahora SÍ conserva las proporciones**, y deroga la nota anterior que lo
  prohibía. Era cierto con flex y `%`: los tramos ya sumaban 100, el hueco desbordaba y
  flex los encogía. **La rejilla descuenta los `gap` ANTES de repartir las fracciones.**
  Medido a 1094 px y a 260 px: `18.5 / 55.6 / 22.2 / 3.7` idéntico en ambos.
- **Desaparece el hueco del redondeo.** Con `%` los porcentajes podían sumar 99,9 y el
  último tramo acababa ~1 px antes del borde (por eso el contenedor tenía `background`).
  Las fracciones siempre llenan exacto, sin falsear ninguna proporción.

La vista escribe `grid-template-columns` con `minmax(0, X.Xfr)` por tramo. El `minmax(0,…)`
y el `min-width: 0` del tramo son los que impiden que **la etiqueta de dentro imponga un
ancho mínimo**: la proporción manda sobre el texto, nunca al revés.

**22 px de alto** (antes 16): ahora el tramo lleva su cifra dentro.

### La cifra dentro del tramo, y por qué no siempre está

Cada tramo muestra su literal y su porcentaje **exacto, con el decimal** (`A 82.1%`,
nunca `A 82%`), con el **mismo formateador que la leyenda**: barra y leyenda no pueden
decir números distintos a dos centímetros de distancia. El color del texto es el
`--sc-*-ink` del literal, o sea el `color` del chip: el contraste ya estaba resuelto ahí.

Que un tramo la lleve **depende de si cabe**, y eso es un ancho en píxeles. La etiqueta
más larga (`AD 18.5%`) mide **58 px** contando el borde, y la barra ocupa el ancho de
`.app-main` (tope 1200 px, **sin barra lateral**) menos 82 px de rellenos. De ahí los dos
umbrales, que **los decide la vista** porque dependen del porcentaje:

| Porcentaje | Etiqueta | Por qué |
|---|---|---|
| ≥ 25 % | siempre | cabe hasta en una ventana de 320 px (254 × 0,25 = 63 px) |
| 8 – 25 % | solo ≥ 900 px de ventana | 818 × 0,08 = 65 px; sale con la clase `--media` y la enciende una media query |
| < 8 % | nunca | no cabría ni en escritorio; su cifra está en la leyenda, que lista **los cuatro** literales pase lo que pase |

Si no cupiera, el `overflow: hidden` del tramo la cortaría a media palabra. **Fallar hacia
«sin etiqueta» es lo correcto**: la leyenda nunca deja de dar el dato. Verificado sin
recortes en 320 · 375 · 641 · 768 · 899 · 900 · 1200 · 1400 px, en los dos estados de la
media query.

> ⚠️ **Esto querría ser `@container (min-width: 60px)` sobre el propio tramo**, que mide
> lo que hay que medir en vez de deducirlo de la ventana. **No se puede todavía:**
> `clean-css` 4.2.3 —el minificador que monta `gulp build`— **no conoce `@container` y se
> lo come EN SILENCIO**, sin fallar la tarea; el SASS se ve correcto en el repo y la regla
> no llega nunca al navegador. Comprobado el 02/09/2026. Hay un aserto que exige que la
> media query sustituta esté en el CSS **servido**, justo para que un borrado silencioso
> no vuelva a pasar desapercibido. Si algún día sube el minificador (`clean-css` 5.3.3 sí
> la conserva, y su única otra diferencia sobre las 265 KB de `app.css` es entrecomillar
> los `url()`), esta media query y los dos umbrales se sustituyen por **una** consulta de
> contenedor. La otra mitad de la regla vive en la vista: hay que tocar los dos sitios.

El contenedor de la barra **no lleva borde ni fondo propios**: el marco lo forman los
bordes de los tramos. Antes tenía uno de `$border-color` y, con los rellenos pasteles, no
se veía ni dónde acababa la barra ni dónde empezaba cada tramo.

### La cantidad y el porcentaje son datos distintos

En la leyenda van en elementos separados: la **cantidad** en negrita y `$text-primary`,
con `min-width` y alineada a la derecha para que unidades y decenas queden en columna
entre filas; el **porcentaje** en `$text-muted`, 11 px y entre paréntesis. Compartiendo
color, tamaño y peso —`21 · 77.8%`— se leían como un solo dato. Las tarjetas siguen el
mismo criterio: el porcentaje baja a su propia línea en gris, o se leía como parte del
corte de aprobación («(AD + A + B) · 70.4 %»).

La leyenda es una **cuadrícula con tope de columna** (`minmax(130px, 190px)` +
`justify-content: start`), no `flex-wrap`: con flex cada ítem medía lo que su contenido
y los números bailaban de una fila a otra; con `1fr` sin tope, en escritorio los cuatro
quedaban a 275 px unos de otros y dejaban de leerse como una sola leyenda.

### Verificación

`database/verificaciones/verif_stats_competencia.php` — solo lectura, corre en producción.
Sobre **50 competencias reales de los dos niveles**: los tres cuadres del universo, el
contraste del contador de evaluados contra un `COUNT` escrito a mano (no derivado del
helper), el render del parcial, y el corte por nivel **en sus dos ramas** (la misma
competencia da menos aprobados en primaria que en secundaria, y la diferencia es
exactamente el número de B).

Cubre además que **dos competencias seguidas no se contaminan** (el `unset` del bucle),
que **los ocho colores de la barra siguen siendo los del chip** en el CSS compilado y
que **cada tramo pintado lleva su clase de literal** — sin ella saldría sin borde y
ninguna prueba de servidor lo notaría.

Desde el 02/09/2026 son **40 asertos** e incluyen la barra rehecha: que la barra reparta
en `fr` y no en `%`, que **la etiqueta conserve el decimal** (`A 82.1%`, no `A 82%`), que
barra y leyenda digan la misma cifra, las **dos bandas de umbral** (el tramo grande
etiquetado siempre y el mediano con la clase `--media`), que el tramo por debajo del 8 %
se pinte **sin** etiqueta, que **ningún tramo dependa de `:first-child`/`:last-child`**
para su radio, y que **la media query llegue al CSS servido** —el aserto que atrapa el
borrado silencioso del minificador—.

Cuatro trampas que costaron una corrección cada una y no hay que repetir:

- 🔴 **Tras montar el parcial, `$statsAlumnos` YA NO EXISTE.** El propio parcial la
  `unset`ea al salir —a propósito, para no contaminar la siguiente vuelta del bucle—, así
  que un `$statsAlumnos[] = ...` para encadenar un segundo caso **no amplía el array: crea
  uno de un solo elemento**. El aserto del tramo < 8 % falló por esto la primera vez que
  se escribió, y el fallo parecía un bug del código. Cada caso reconstruye su array entero.
- **La muestra se pide nivel por nivel.** Con un solo `LIMIT` sobre el conjunto, primaria
  se llevaba las 40 filas y la mitad de los asertos no medía secundaria.
- **El `COUNT` de control filtra por la SECCIÓN DE LA CARGA.** Hay notas cuya matrícula
  ya no pertenece a esa sección —un cambio de sección a mitad de bimestre deja la nota
  donde se cursó— y el roster del modelo las excluye bien. Sin esa condición el control
  acusaba una divergencia inexistente (medido: carga 118, matrícula 692).
- **Las cargas con exoneración se añaden a la muestra a propósito.** Son dos en toda la
  base; al azar no salían nunca y el aserto que justifica la regla 2 pasaba en verde sin
  haber medido nada.

## REGLA DE NEGOCIO — autonomía del docente y periodo final (10/08/2026)

> Regla del colegio, confirmada por el usuario. **Aprobada, SIN IMPLEMENTAR.**
> Fecha tope: antes del **05/10/2026** (inicio del IV Bimestre).

**En los periodos NO finales (B1, B2, B3) el docente es AUTÓNOMO**: elige qué competencias
de su área-curso evalúa, con un **mínimo de una**. Puede cambiar de competencias entre un
bimestre y el siguiente — que evaluara una en B1 no lo obliga a repetirla en B2. Una
competencia sin nota en esos bimestres es **legítima** y la boleta la pinta con guion
(regla del 05/08/2026).

**En el PERIODO FINAL esa autonomía se pierde.** Una carga no está terminada al 100 %
mientras le falte **una sola** de sus competencias, y el universo son **las académicas
propias del área-curso MÁS las transversales que esa carga lleva**. El flujo no cambia:
las transversales las sigue registrando cada docente en su carga y aprobando el tutor.

- **"Periodo final" = el ÚLTIMO periodo del año** (mayor `numero`), el mismo anclaje que
  el logro anual — no el número 4 literal. Ver `docs/modulos/boletas.md`.
- **Basta ≥1 nota por competencia** para darla por evaluada (decisión del usuario). Los
  huecos POR ALUMNO los sigue cubriendo `alertasEvaluacionIncompleta`, que ya aborta el
  cierre: las dos reglas son **complementarias**, cada una cubre una dimensión distinta
  (la de competencia y la de estudiante) y ninguna sustituye a la otra.
- **Se hace cumplir en DOS sitios** (decisión del usuario): (1) **impedir el bloqueo** de
  una competencia vacía en el periodo final —el docente se entera en su propia pantalla,
  cuando todavía puede actuar— y (2) **abortar el cierre** como red de seguridad. Solo lo
  segundo llega tarde; solo lo primero deja pasar las competencias que nunca se bloquean,
  porque el cierre forzado las bloquea igual.
- **Válvula: REGISTRO ACADÉMICO, con motivo específico** (decisión del usuario; no el
  director). No hay que inventarla: es lo que ya hace la **calificación extraordinaria**
  (migración 042, en producción desde el 16/07), cuya fila de auditoría con el motivo es
  el único registro permanente de por qué esa nota existe. Escribir la regla en términos
  de **"RA con motivo"**, NO del nombre del flujo actual: el plan de registro retroactivo
  (migración `049`) unifica ese punto de entrada y retira el flujo de la extraordinaria.
  - 🔴 **CHOQUE ABIERTO (18/09/2026): la válvula ya no sirve tal cual.** Desde ese día la
    extraordinaria **solo** se registra en bimestres CERRADOS, y esta regla aborta el cierre
    del periodo final si falta una competencia: con las dos juntas, una competencia vacía
    del IV Bimestre no se podría cerrar nunca. El usuario decidió **resolverlo al
    implementar esta regla** (antes del 05/10). Opciones ya planteadas: excepción en el
    periodo final, o que su cierre admita competencias vacías y RA las complete después.

### Por qué existe: cierra el punto ciego del logro anual
El logro anual sale **solo del último periodo**. Sin esta regla, una competencia que el
docente decide no evaluar en el último bimestre deja al alumno **sin logro anual** en ella
aunque la haya cursado todo el año. Ver `docs/modulos/boletas.md`.

### Dimensionado con B2 como ensayo (medido el 10/08/2026)
Si B2 fuera el periodo final, el guard **abortaría el cierre**:

| Universo del periodo | Pares | Vacíos |
|---|---|---|
| Competencias académicas | 593 | **61** |
| Competencias transversales | 690 | **0** |
| **Total** | **1283** | **61** |

- **39 cargas de 398 (9.8 %)** quedarían incompletas. **Ninguna transversal está vacía**:
  el problema es enteramente académico y se concentra en **Personal Social de primaria**.
- El **"mínimo una" ya se cumple al 100 %**: no hay una sola área-curso académica sin
  alguna competencia evaluada en B2. Los únicos ceros son las **12 cargas de Tutoría (TOE)
  de primaria**, que tienen **0 competencias por diseño** (`tipo='tutoria'`) → quedan fuera
  del universo sin necesidad de excepción. La **TOE de secundaria sí entra**: tiene la
  competencia de Ética (C57) y no lleva transversales (exclusión del 07/07).

### ⚠️ Trampas al implementarlo (medidas, no supuestas)
1. 🔴 **El guard de transversales sería la QUINTA copia de la regla de "carga dueña"**, y
   la cuarta divergente creó los **130 bloqueos fantasma** de B2. En unidocentes las
   TIC/GAMA se registran **una sola vez, en la carga dueña**, así que la obligación solo
   puede recaer sobre ésa; una copia nueva y distinta exigiría transversales a cargas que
   nunca las reciben. **Extraer el punto único (F1 del plan de los 4 registros) ANTES de
   escribir este guard**, no después. Sitios actuales: `CalificacionController::calificaciones`,
   `TransversalModel::estadoCargasSeccion`, `CalificacionModel::cargaDuenaTransversales` y
   `AnioAcademicoModel::bloquearCompetenciasPendientes`.
2. ⚠️ **Contar por `competencias.area_id` da cifras FALSAS.** Las competencias de subárea
   tienen `area_id` NULL: el conteo bajaba de 1283 a 1020 y perdía 263 pares en silencio.
   Va siempre `COALESCE(c.area_id, (SELECT area_id FROM subareas WHERE id = c.subarea_id))`.
   Pasó al medir esta misma regla.
3. **El cierre forzado bloquea lo que el docente no bloqueó**, así que un guard puesto solo
   en el bloqueo del docente no ve nada: por eso hacen falta los dos puntos.

## Módulo soft-delete de criterios (sesión 7)
- **Migración:** `006_soft_delete_criterios.sql` — agrega `eliminado_en DATETIME NULL` y
  `eliminado_por INT UNSIGNED NULL FK→usuarios` a la tabla `criterios`.
- **Comportamiento:** eliminar un criterio con calificaciones hace soft-delete (el registro
  permanece en BD para auditoría). El promedio de la competencia se recalcula automáticamente.
- **Patrón de filtro:** todas las queries que leen criterios incluyen `AND eliminado_en IS NULL`
  (12 puntos actualizados en `CriterioModel`, `CalificacionModel` y `CalificacionController`).
- **`CriterioModel::eliminarConAuditoria(int $id, int $eliminadoPor): bool`** — método de borrado.
- **JS:** si el criterio tiene calificaciones, muestra confirm con advertencia y recarga la
  página tras el borrado para reflejar el promedio recalculado.

## Criterios — nombre máx 100 + descripción opcional (10/06/2026)
- **Migración:** `018_criterios_descripcion.sql` — `ADD COLUMN IF NOT EXISTS descripcion TEXT NULL`.
  La columna `nombre` sigue siendo VARCHAR(120): los 143 nombres existentes >100 quedan intactos.
- **Validación servidor:** `CalificacionController::CRITERIO_NOMBRE_MAX = 100`. `crearCriterio`
  y `renombrarCriterio` responden 422 con mensaje claro si el nombre excede — NUNCA truncar.
  Ambos endpoints aceptan `descripcion` opcional (vacía → NULL).
- **`CriterioModel::crear()`/`renombrar()`** reciben `?string $descripcion = null`.
- **UI docente** (`calificaciones.php` + `calificaciones.js`):
  - Contador en vivo `67/100` (`.contador-chars`, `--excedido` en rojo) en crear y editar.
  - Campo descripción opcional (textarea) en crear y editar.
  - Editor de criterio: si el nombre actual supera 100 (criterio antiguo), botón
    "↓ Mover a descripción" traslada el texto íntegro con un clic.
  - La descripción se muestra bajo el nombre (`.criterio-bloque__descripcion`).
- **Lectura:** resumen del docente → tooltip del `<th>` = nombre completo + descripción;
  vista del padre (`padre/notas.php`) → descripción como línea muted bajo el criterio
  (`.criterio-desc`). Queries actualizadas en `CalificacionModel` (resumen y padre).
- **SASS:** todo en `pages/_dashboard.scss` (OJO: `components/_dashboard.scss` NO está
  importado en `app.scss` — es código muerto, no agregar estilos ahí).

## Transversales por docente + cierre del tutor (10/06/2026)

> Desde el II Bimestre las competencias transversales (TIC/GAMA) las registra
> CADA docente en su propia carga. La carga transversal del tutor quedó
> DESACTIVADA (sus datos B1 siguen legibles). El tutor solo agrega conclusiones
> y cierra el bimestre desde `/docente/tutoria`.


### Registro por docente (Variante 1 de bloqueo)
- `formulario()` añade a cada carga la sección "Competencias Transversales"
  (`CriterioModel::getCompetenciasTransversalesConCriterios`) — mismo mecanismo
  de criterios/notas; flag `es_transversal` en la vista
  (`.competencia-card--transversal`, separador `.transversales-separador`).

### Agregación y boletas — REGLA ÚNICA
- `CalificacionModel::getBoletaAlumno()` EXCLUYE las filas crudas transversales
  (por el área de la COMPETENCIA, no de la carga) y agrega al final
  `getTransversalesAgregadas()`: **promedio de promedios por carga bloqueada**
  (cubre B1 = carga del tutor y B2+ = cargas de docentes), SOLO si existe
  cierre vigente. Conclusión desde `conclusiones_transversales`.
- Cubre automáticamente los 4 consumidores: boleta imprimible, digital,
  pública (admin y sin login) y `/padre/notas`.
- Orden de mérito y estadísticas siguen excluyendo transversales (filtran por
  el área de la competencia — las filas por docente no contaminan el ranking).

#### `/padre/notas` con retorno de grado (26/07/2026)
Las notas se leen por unión de las fuentes de `boletaContexto` (`[operativa, oficial]`),
así que una competencia calificada en AMBAS matrículas llegaba repetida: 44 filas en vez
de 22. `PanelController::notas` ahora indexa por `competencia_id` —el mismo modelo que
`BoletaModel::buildAreasConBimestres`— y **gana la PRIMERA fuente, la operativa**: manda
el grado que el alumno cursa. Si la competencia solo existe en la oficial, esa se usa y
no se pierde el dato.

Además **descarta los criterios sin nota**: `getBoletaAlumno` devuelve todos los
criterios definidos en la carga, tengan nota del alumno o no, y la vista los pinta como
`—`. En un retorno la operativa trae los criterios de la carga del grado oficial SIN
ninguna nota, así que sin ese filtro se veía una tabla entera de guiones. Impacto medido
en el resto de alumnos: 0,51% de los criterios (30 de 5877 en 60 alumnos) y ninguna
competencia se queda sin tabla.

### Vista del tutor (`Docente\TutoriaController`)
- Rutas: `GET /docente/tutoria[/{periodo_id}]`,
  `POST /docente/tutoria/{periodo_id}/conclusion`, `POST .../cerrar`.
- Card "Tutoría" DESTACADA en `/docente/mis-cargas` (`.tutoria-card`, 3 estados:
  `⏳ Transversales bloqueadas X de Y` / `✍ Disponible con N conclusiones
  pendientes` / `✅ Cerrado el {fecha}`). Solo tutores del año activo
  (`secciones.tutor_id`).
- Panel: selector de bimestre, tabla de promedios agregados TIC/GAMA, textarea
  de conclusión SOLO donde el literal la exige (B/C primaria, C secundaria).
- **La tabla de promedios se muestra SIEMPRE (06/08/2026), también con el
  bimestre a medias**, en dos estados: *provisional* (solo lectura, badge
  `Provisional`, guion donde aún no hay aporte) y *definitivo*. Mientras es
  provisional, el tutor ve además **qué cargas ya aprobaron sus transversales y
  el nombre de su docente** — esto DEROGA la regla del 14/06/2026 (`73838d1`)
  que solo exponía el avance agregado; se expone área/carga, docente y estado,
  nunca notas de otras áreas ni el DNI.
- **Escribir conclusiones exige el promedio DEFINITIVO**, y el guard está en
  `TutoriaController::guardarConclusion` (servidor), no solo en la vista: una
  conclusión redactada sobre un parcial puede terminar describiendo una nota que
  luego cambia. Medido en B2: el promedio provisional sí se mueve (34 de 48
  celdas con 12 de 15 cargas sin aprobar), pero el LITERAL no llegó a cambiar
  mientras quedara alguna carga aportando.
- `cerrar` valida en servidor: **todas las competencias TRANSVERSALES de la
  sección bloqueadas** + 0 conclusiones obligatorias pendientes.
  ⚠️ **Desde el 06/08/2026 las ACADÉMICAS ya no condicionan el cierre**: no
  participan del promedio que se congela (`getPromediosSeccion` filtra
  `tipo='transversal'`), así que exigirlas hacía esperar al tutor por notas que
  no cambiaban su resultado. Contrapartida aceptada: cerrar antes alarga la
  ventana en la que un desbloqueo académico anula el cierre en cascada.
  JS: `resources/js/tutoria.js`.

### Integración con reaperturas
- `BloqueoController::desbloquear`: al desbloquear una competencia propia LIBERA
  en cascada las TIC/GAMA de esa misma carga (`TransversalModel::liberarTransversalesDeCarga`,
  competencias en área `tipo='transversal'`) y ANULA el cierre vigente de la
  sección con traza — todo en una transacción. Las transversales se registran
  bajo la carga del docente pero NO aparecen como filas en el panel; sin la
  cascada quedaban bloqueadas e inalcanzables. Se repite el ciclo re-bloqueo→re-cierre.
- **`BloqueoController::liberarTransversalCompetencia` (06/08/2026): la vía DIRECTA.**
  Libera UNA competencia transversal de UNA carga desde el desplegable del panel, sin
  sacrificar ninguna académica. La cascada de arriba sigue existiendo (es correcta cuando
  se reabre una carga entera), pero ya no es la única puerta: usarla para corregir una
  TIC/GAMA obligaba a desbloquear una académica que no tenía ningún problema, y no servía
  en absoluto si la carga aún no tenía académicas bloqueadas. Detalle y guards en
  `docs/modulos/admin.md` §"Transversales: los dos niveles".
- `PeriodoController::reabrir`: YA NO libera bloqueos ni anula cierres
  automáticamente (ver "Origen del bloqueo" abajo). Solo reactiva el periodo y
  deja traza del motivo. La liberación de los bloqueos del cierre forzado es
  MANUAL desde el panel (`BloqueoController::limpiarBloqueosCierre`), que sí
  anula los cierres transversales de las secciones afectadas con traza.
- `SeccionModel::asignarTutor` YA NO crea/reactiva la carga transversal.

### Modelo `TransversalModel`
`getCompetencias(nivel)`, `getCierreVigente`, `cerrar`, `anularCierreVigente`,
`getPromediosMatricula/Seccion`, `getConclusiones*`, `guardarConclusion`,
`estadoCargasSeccion` (**solo competencias TRANSVERSALES** de cargas activas,
con la lógica de carga dueña; antes sumaba propias + transversales, y esta
línea decía "solo propias", que nunca fue cierto),
`conclusionesObligatoriasPendientes`, `seccionesConBloqueosDeCierre`,
`liberarTransversalesDeCarga`, `anularCierresDeSecciones`, `getSeccionDelTutor`.

## Origen del bloqueo y reapertura quirúrgica (15/06/2026)

> **Bug corregido:** al cerrar B1 se bloquean TODAS las competencias de cargas
> activas (con o sin notas). Antes, al reabrir un bimestre, `reabrir()` borraba
> TODO bloqueo sin notas — incluidas las competencias finalizadas-vacías
> intencionalmente (casilleros cedidos SIAGIE / áreas no usadas) → se
> "rehabilitaban". Ahora reabrir NO borra nada; la liberación es manual y
> distingue el origen del bloqueo.

### Migración `020_bloqueos_origen.sql`
- `bloqueos_competencia.origen ENUM('docente','cierre') NOT NULL DEFAULT 'docente'`.
- Backfill por el DEFAULT: TODAS las filas existentes (incl. I Bimestre) → `'docente'`.
- A futuro solo el cierre forzado escribe `'cierre'`.

### Quién escribe cada origen
- `AnioAcademicoModel::bloquearCompetenciasPendientes` (cierre forzado) → `'cierre'`.
- `CalificacionModel::bloquearCompetencia` (aprobación del docente Variante 1 y
  bloqueo manual del director) → `'docente'`.

### Comportamiento
- `PeriodoController::reabrir`: solo reactiva el periodo + traza (no toca bloqueos).
- `BloqueoController::limpiarBloqueosCierre` (`POST /director/bloqueos/limpiar-cierre`):
  botón MANUAL en el panel de bloqueos. Borra `origen='cierre'` del periodo
  (requiere el bimestre `activo`/reabierto), anula los cierres transversales de
  las secciones afectadas con traza. Los `origen='docente'` (incl. finalizadas
  sin notas) NUNCA se tocan.
- `AnioAcademicoModel::eliminarBloqueosDeCierre` reemplaza a `eliminarBloqueosSinNotas`.
- El panel muestra el conteo `stats['cierre_forzado']` y etiqueta "por cierre
  forzado" en cada fila bloqueada por el cierre.

## Botón de competencia: "No se evaluó" / "Ver resumen" (24/06/2026)

> En `/docente/calificaciones/{id}` el botón de cabecera de cada competencia
> tiene 4 estados. Resuelve el viejo **Catch-22**: para finalizar una competencia
> SIN notas (casillero cedido SIAGIE / área no usada) había que entrar al resumen,
> pero "Ver resumen" estaba bloqueado justo por no haber notas → inalcanzable.
> Implementado, pusheado (commit `6aaaf6d`) y en prod.

### Máquina de 4 estados (vista `calificaciones.php`)
Variables calculadas por competencia justo tras `$esTransversal` (líneas ~106):

| Estado | Condición | Botón |
|--------|-----------|-------|
| Sin criterios (académica) | `!$esTransversal && !$compBloqueada && !$tieneCriterios && !$bloqueado` | **"No se evaluó"** — habilitado, acción terminal con `confirm()` |
| En progreso | ≥1 criterio y **alguno pendiente** (`!$todosConfirmados`) | **"Ver resumen"** bloqueado (`.btn-ver-resumen--bloqueado`, tooltip) |
| Listo | ≥1 criterio y **TODOS confirmados** (`$todosConfirmados`) **o** aprobada (`$resumenAccesible`) | **"Ver resumen"** habilitado |
| No evaluada | bloqueada con `alumnos_calificados == 0` (`$sinNotasBloqueada`) | badge **"No evaluada"** + mensaje propio en el cuerpo |

> **Endurecimiento (30/06/2026):** "Listo" pasó de `≥1 criterio confirmado` a
> `TODOS los criterios confirmados`. Ver "Resumen = solo confirmados" más abajo.

### Regla central — el autosave NO desbloquea (y AHORA re-bloquea)
- **"Ver resumen" se desbloquea SOLO con el clic en "Confirmar"** (el submit por
  criterio, endpoint `/guardar`). Ese handler llama
  `CriterioModel::marcarConfirmado()` que sella `criterios.confirmado_en` (solo si
  está NULL, para conservar la marca de la primera confirmación).
- **El autosave (`/autosave`, en `blur`/paste) NUNCA sella `confirmado_en`** → no
  se puede llegar al resumen salteándose el filtro de omisión ayudándose del
  autoguardado. (El filtro de omisión sigue obligando el motivo por alumno en
  blanco vía el modal "Confirmar y guardar".)
- **(30/06/2026) AHORA cualquier cambio del criterio DESCONFIRMA**
  (`CriterioModel::desconfirmar`): autosave de una nota (set o blank), omisión
  (`guardarOmisiones`) o edición de nombre/descripción (`renombrarCriterio`)
  vuelven el criterio a "pendiente". Antes solo desconfirmaba un blanco sin
  motivo. Ver "Resumen = solo confirmados".
- Al ser una **columna persistida** (no estado de sesión como antes, que el JS
  quitaba y al recargar volvía), el desbloqueo **sobrevive al recargado**.

### El candado de aprobación
> **Actualizado 30/06/2026** (ver "Resumen = solo confirmados"): se retiró la
> "puerta blanda". "Ver resumen" exige AHORA todos los criterios confirmados, y
> `errorBloqueoCompetencia` añadió la misma puerta (`competenciaListaParaResumen`)
> ANTES del chequeo por alumno. **Aprobar** sigue exigiendo además ≥1 criterio +
> todos los alumnos con nota u omisión. El camino "No se evaluó" (sin criterios)
> se evalúa antes de la puerta y no se ve afectado.

### "No se evaluó" — acción
Reusa el endpoint existente `POST /docente/calificaciones/{carga}/bloquear/{comp}`
con `sin_calificaciones=1` (mismo backend que el botón "no se trabajó" del resumen,
`errorBloqueoCompetencia` lo acepta solo si NO hay criterios). JS en
`calificaciones.js` (`.btn-no-evaluo`, con `confirm()` irreversible → recarga).
Crea un bloqueo `origen='docente'` sin notas. **NO aparece en transversales**
(no se bloquean individualmente) **ni en periodo bloqueado** (acción de escritura).

### Migración `026_criterios_confirmado.sql`
- `criterios.confirmado_en DATETIME NULL` + `confirmado_por INT UNSIGNED NULL`.
  Idempotente (`ADD COLUMN IF NOT EXISTS`).
- **Backfill**: todo criterio vivo con ≥1 nota queda confirmado (preserva el
  acceso de quien ya estaba calificando; el distingo autosave/confirmar aplica
  solo a ediciones futuras). 2308 confirmados / 15 vacíos sin confirmar al aplicar.

### SASS / lectura del estado
- `getCriterios()` hace `SELECT *` → `confirmado_en` llega solo a cada criterio
  sin tocar el modelo. La vista calcula `$todosConfirmados` recorriendo
  `$competencia['criterios']` (30/06/2026: era `$tieneConfirmado` ≥1).
- `pages/_dashboard.scss`: `.btn-no-evaluo` (discreto, borde punteado, vira a
  ámbar en hover) + `.competencia-card__acciones` (reemplazó un `style=""` inline).

## Resumen = solo confirmados — confirmación como única verdad (30/06/2026)

> El resumen (`/docente/calificaciones/{id}/resumen/{id}`) y el promedio agregado
> SOLO reflejan criterios CONFIRMADOS. Se retiró la "puerta blanda". `confirmado_en`
> (por criterio) es la única fuente de verdad de "esto es oficial". Sin migración
> (reusa 026). Commit `5d08463` en `dev`.

### Las tres reglas (alineadas)
1. **Promedio agregado = solo criterios confirmados.** `CalificacionModel::calcularPromedio`
   y la query de descubrimiento de `recalcularPromedioSeccion` filtran
   `AND cr.confirmado_en IS NOT NULL`. Un criterio pendiente no cuenta para el promedio.
   **Existencia de la fila (DELETE de huérfanos) ≠ promedio:** el DELETE borra la fila de
   `calificaciones` (incluida su `conclusion_descriptiva`) si y solo si el alumno NO tiene
   **ninguna nota viva** en la competencia — mira solo `cr.eliminado_en IS NULL`, **NO**
   `confirmado_en`. Así: (a) si el alumno conserva alguna nota, la fila y su conclusión se
   conservan aunque un criterio quede momentáneamente desconfirmado tras editarlo (retiene
   su `nota_numerica` anterior hasta re-confirmar; ese promedio transitorio no se muestra:
   el resumen exige todos confirmados y el resto de consumidores leen solo bloqueadas);
   (b) si el alumno se queda sin nota (borró la nota, omisión sin nota, o se eliminó el
   único criterio), la fila se elimina con su conclusión — una conclusión sin calificación
   NO debe persistir ni dejar un promedio fantasma en el resumen.
   > Historia: el 30/06 el DELETE filtraba `confirmado_en` → borraba la fila de un alumno
   > que SÍ tenía nota desconfirmada (data-loss de la conclusión). Un parche que conservaba
   > la fila "si tiene conclusión" produjo el bug inverso (fila fantasma de un alumno YA
   > sin nota). La regla correcta y final es **fila = existe nota viva**.
2. **Resumen (entrar + mostrar) = ≥1 criterio y TODOS confirmados.**
   `CriterioModel::competenciaListaParaResumen(cargaId, compId, periodoId)` (≥1 vivo
   y 0 pendientes; un criterio vacío cuenta como pendiente). El guard de `resumen()`
   y el `resumenAccesible` del autosave/guardar/omisiones/renombrar la usan (reemplazó
   a `existeConfirmado`, conservado sin uso en estos sitios). `getResumenCompetencia`
   recibe `soloConfirmados` (true solo desde `resumen()`; los demás callers — validación
   de `guardar`, `errorBloqueoCompetencia`, ConsultaNotas, histórico — en false).
3. **Aprobar = todos confirmados.** `errorBloqueoCompetencia` añadió la puerta
   `competenciaListaParaResumen` ANTES del chequeo por alumno (evita que un retoque
   no confirmado se pierda silenciosamente del promedio bloqueado). El branch
   "No se evaluó" (sin criterios) se resuelve antes y no se ve afectado.

### Desconfirmado en cascada — CUALQUIER cambio del criterio
Regla: cualquier mutación del criterio tras confirmarlo lo vuelve "pendiente".
- `autosave`: tras escribir/borrar la nota, llama `desconfirmar($criterioId)`
  **incondicional** y ANTES de `recalcularPromedioSeccion` (así el recalc lo excluye).
- `guardarOmisiones`: también desconfirma (registrar/cambiar omisión = cambio de
  composición) y devuelve `resumenAccesible`.
- `renombrarCriterio` (nombre **y** descripción; incluye "Mover a descripción"):
  desconfirma + recalcula + devuelve `resumenAccesible`; el JS sincroniza "Ver
  resumen" tras el éxito. **Guard:** si la competencia ya está aprobada/bloqueada,
  el renombrado se RECHAZA (criterio INMUTABLE para el docente, parejo con
  `eliminarCriterio`); para corregir un typo hay que reabrir el bimestre.
- `crearCriterio`: el nuevo criterio nace pendiente; su handler JS **recarga la
  página**, así que la vista recalcula `$todosConfirmados` sin lógica extra.
  **Guard nuevo:** rechaza agregar criterios a una competencia bloqueada.
- `guardar` (Confirmar): **orden crítico** — `marcarConfirmado` va ANTES de
  `recalcularPromedioSeccion` (con promedio solo-confirmados, el sello debe existir
  para que el criterio entre al cálculo). Devuelve `resumenAccesible`.

### Frontend
- Vista `calificaciones.php`: el estado "Listo" calcula `$todosConfirmados`
  (recorre `confirmado_en` de cada criterio; antes era `$tieneConfirmado` ≥1).
- `resources/js/calificaciones.js`: `ejecutarGuardado` (Confirmar) y el handler de
  renombrar sincronizan vía `sincronizarBotonResumen(competenciaId,
  data.resumenAccesible)`; el autosave ya lo hacía. Recompilar con `gulp build`.

### Sin impacto retroactivo
Las competencias YA bloqueadas no se recalculan (no hay más edits), y el backfill de
026 selló todo criterio con notas → ninguna boleta/orden de mérito histórico cambia.
La completitud de transversales / piso de carga cuenta notas crudas en
`calificaciones_criterio` (no el promedio), así que no se altera.

## Calificaciones — feedback en vivo del docente (30/06/2026)

> Dos detalles de UX en `/docente/calificaciones/{carga}` que antes solo se
> reflejaban al recargar. Commit `3ceb300` (en `dev` y `main`). Solo front
> (`calificaciones.js` + `_dashboard.scss`), sin BD ni endpoints.

- **Chip "X de Y" (alumnos con nota guardada)** del criterio se actualiza sin
  recargar: `actualizarProgresoCriterio(form)` cuenta los `.input-nota` con
  `data-nota-inicial` no vacío (lo PERSISTIDO, no `value` → un autosave fallido no
  infla el conteo); total = nº de inputs (alumnos no exonerados, los `EXO` no
  renderizan input). Se llama en `autoguardarCelda` y `ejecutarGuardado`.
- **Punto "pendiente de confirmar"** (`.criterio-bloque__estado--pendiente`):
  círculo ámbar al inicio del `.criterio-bloque__header`, visible SOLO si
  `confirmado_en` es null. `actualizarEstadoCriterio(bloque, pendiente)` lo enciende
  al editar/omitir/renombrar (desconfirma) y lo apaga al Confirmar. Solo se renderiza
  en competencias editables (`!$compBloqueada && !$bloqueado`). Ámbar = acción
  pendiente; no choca con `--con-cambios` (ese pinta el borde durante el tipeo).

## Módulo de consulta de calificaciones — solo lectura (22/06/2026)

> Capa de SUPERVISIÓN read-only: ver el detalle criterio-a-criterio de notas ya
> oficiales, sin editar. La edición sigue en `/rectificaciones` (que audita).

- **Eje:** periodo → sección → área/carga → grilla criterio-a-criterio.
- **Alcance:** SOLO lo oficial (competencias con bloqueo), mismo criterio que la
  boleta. Bimestres activos y cerrados.
- **Controlador:** `app/Controllers/Consulta/ConsultaNotasController.php`
  (`requireRole(['admin','registro_academico','director_general','director_ebr'])`).
  5 métodos: `index` (selector de periodo + grid de secciones), `seccion`
  (áreas/cargas de la sección), `carga` (grillas read-only por competencia),
  y desde el **07/08/2026** `transversales` y `conducta`, los dos registros de
  nivel SECCIÓN que antes no llegaban aquí.
- **Sin métodos de modelo nuevos:** navega con
  `CalificacionModel::getCompetenciasPorPeriodo()` filtrando `bloqueo_id != null`
  en PHP, y arma el detalle con `getResumenCompetencia()` (+ omisiones/exonerados,
  igual que el resumen del docente).
- **Vistas:** `resources/views/consulta-notas/{index,seccion,carga,_tabla}.php`.
  `_tabla.php` es el parcial read-only (mismo lenguaje visual que el resumen del
  docente: `.tabla-resumen`, `.nota-numeral`, `.exo-badge`, `.omision-badge`…),
  SIN inputs ni botones; la conclusión se muestra como texto. `carga.php` lo
  `require VIEW_PATH . '/consulta-notas/_tabla.php'` por cada competencia.
- **Rutas:** `/consulta-notas`,
  `/consulta-notas/{periodo_id}/seccion/{seccion_id}`,
  `/consulta-notas/{periodo_id}/carga/{carga_id}`,
  `…/seccion/{seccion_id}/transversales` y `…/seccion/{seccion_id}/conducta`
  (las de 5 segmentos van **antes** que la de 4 en `routes/web.php`).

### Transversales y conducta en la consulta (07/08/2026)
Las dos ausencias eran **estructurales, no un olvido de la vista**:
`getCompetenciasPorPeriodo` une competencia↔carga por el **área de la CARGA**, y las
transversales cuelgan de un área propia (`tipo='transversal'`) — el vínculo
transversal↔carga no existe en el esquema, se resuelve por NIVEL. Y la conducta no vive
en `calificaciones` (4 tablas propias, ciclo por SECCIÓN en dos etapas).

- **Las dos caras de las transversales.** El **crudo por carga** se añade al final de
  `carga.php` con separador y `.competencia-card--transversal` (clases ya existentes);
  el **agregado por sección** es una vista nueva con el promedio que llega a la boleta
  más la conclusión del tutor.
- 🔴 **El BLOQUEO NO ES SEÑAL DE CONTENIDO** — hay **820 bloqueos sobre 410 cargas en
  cada bimestre** porque el cierre forzado los propaga en cascada. Copiar el criterio del
  resto de la pantalla ("mostrar lo que tenga bloqueo") pintaría **410 bloques vacíos en
  B1**. El helper `transversalesConContenido` exige bloqueo **Y** `EXISTS` de
  calificaciones. La condición no es opcional.
- ⚠️ **En B1 el crudo por carga NO EXISTE, y es correcto** (medido, corrige lo que
  suponía el plan): allí regía el modelo viejo —carga única del tutor— y esas 23 cargas
  están hoy en `estado='inactiva'`, así que la navegación nunca las alcanza. El crudo por
  docente es un concepto que **nace en B2**; para B1 el valor está en el agregado.
- **Gate D3 — solo lo oficial:** transversales exige **cierre vigente**; conducta, las
  **dos etapas** cumplidas y sin anular. Sin eso la tarjeta no se pinta **y la ruta
  responde 404** — ocultar el enlace no basta, la URL queda en marcadores.
- ⚠️ **B1 y B2 no comparten modelo de conducta:** B1 es legado (literal directo, **0
  respuestas**, 528 calificaciones) y B2+ deriva la nota de las respuestas Sí/No (5240
  respuestas). `getEstudiantesParaTutor` resuelve las dos y marca `es_legado`; la vista
  ramifica. Son caminos de código distintos: probar siempre los dos.
- **Sin métodos de modelo nuevos y sin migración.** El roster sale de
  `ConductaModel::getEstudiantesParaTutor` **a propósito** —es el roster canónico con las
  exclusiones de retorno— en vez de escribir otro SELECT: duplicar ese filtro a mano es
  como nacieron los bugs de asistencia del 04/08.
- **No se amplían los roles de `/admin/conducta`** (decisión D2): esa pantalla tiene
  escritura. Esto cierra un hueco real — `director_general` y `director_ebr` no tenían
  **ninguna** forma de ver conducta ni el agregado transversal.
- **Verificación:** `database/verificaciones/verif_consulta_notas_ampliada.php` (solo
  lectura, corre en prod). Contrasta el agregado contra `getPromediosMatricula` y los
  literales contra `getParaPeriodo`, que son **las fuentes de la boleta**: 2086 celdas y
  1048 filas comparadas, **0 divergencias**.
- **Entradas:** botón "Consultar notas (lectura)" en `/director/bloqueos`
  (lleva el periodo actual) y en `/rectificaciones`.
- **SASS:** `pages/_consulta-notas.scss` (solo las listas de navegación; el detalle
  reusa estilos existentes). Importado en `app.scss`.
- **Filtro por nivel del Director EBR: NO aplicado**, igual que `/director/bloqueos`
  (no existe mapeo usuario→nivel en el sistema). Si se requiere, es un añadido aparte.
- `getCompetenciasPorPeriodo()` ahora incluye `pu.apellido_materno AS docente_materno`
  para mostrar el nombre completo del docente (no rompe `/director/bloqueos`).

### Histórico del docente (F2) — bimestres cerrados en solo lectura
- **Selector de bimestre en `/docente/mis-cargas`**: `<select class="form-select">`
  con layout compacto `.cargas-periodo` (en `pages/_dashboard.scss`). Default = activo
  (comportamiento de siempre). `getCargas()` ya recibe `periodoId`.
- Si el bimestre elegido NO es el activo (`$esHistorico`), las cards enlazan a la
  vista read-only y el badge del header marca "· solo lectura".
- **Ruta:** `GET /docente/calificaciones/{carga_id}/historial/{periodo_id}`
  (5 segmentos: no colisiona con el patrón base de 3, el router ancla `^…$`).
  Registrada ANTES del patrón base por orden de lectura.
- **`CalificacionController::historial()`**: valida que la carga sea del docente
  (`validarCargaDocente`, filtra por usuario → solo SUS cargas), arma sus
  competencias bloqueadas del periodo y **reutiliza `consulta-notas/_tabla.php`**
  vía la vista `resources/views/docente/historial-carga.php`.
- El `formulario` editable sigue clavado al periodo activo (`getPeriodoActivo`);
  el histórico es una ruta paralela de solo lectura.

## Guardar criterio atómico + validación de omisión (26/06/2026)

> Fix del "promedio fantasma". Documentado desde la memoria de sesión al crear
> la red (03/07/2026).

- `guardar()` (el Confirmar por criterio) es **atómico**: valida la omisión en el
  SERVIDOR — un alumno en blanco sin motivo de omisión responde **422** y no
  guarda nada. El JS hace **un solo fetch** (antes eran dos: notas + omisiones,
  con estados intermedios inconsistentes).
- `recalcularPromedioSeccion` limpia los huérfanos en `calificaciones` (DELETE de
  filas sin nota viva; ver regla "fila ⟺ nota viva" en CLAUDE.md).
- **NUNCA usar `guardarNotasMasivas` dentro de una transacción** (maneja la suya).

## Ética y Valores (Educación Religiosa) — carga del tutor en TOE (07/07/2026)

> SOLO SECUNDARIA. El colegio no dicta Religión en secundaria (área 14: 0 cargas,
> 0 notas desde siempre), pero SIAGIE obliga a registrarla. Decisión: el tutor de
> cada sección califica "Ética y Valores" en una carga del área **Tutoría (TOE)**
> (id 24, `tipo='tutoria'`) — y esas notas alimentarán Ed. Religiosa en el export
> SIAGIE de secundaria (cuando exista). Plan de encendido en `docs/ESTADO.md`.

### Por qué el área tutoria (y no una nueva, ni la 14)
- La UGEL no autoriza crear un área "Ética y Valores"; manda usar Ed. Religiosa.
- Calificar EN el área 14 mostraría en boleta las competencias oficiales de
  Religión ("...amada por Dios...") evaluadas por tutores sin dictar el curso,
  y metería el área al orden de mérito (B1 se calculó sin ella).
- El área tutoria ya estaba **future-proof por datos**: el filtro de mis-cargas y
  el del dashboard la ocultan SOLO mientras no tenga competencias. Insertar la
  competencia es el interruptor de encendido. Además `tipo='tutoria'` queda
  excluido del orden de mérito por invariante (base de ranking consistente con B1).

### Doble nombre (mecanismo existente, cero código)
- `areas.nombre_boleta='Ética y Valores'` + `alias_boleta='(Educación Religiosa)'`
  → familias ven "Ética y Valores (Educación Religiosa)" en boleta y /padre/notas.
  Docentes/horarios siguen viendo "Tutoría (TOE)" (`areas.nombre`).
- La equivalencia con Ed. Religiosa se declara en el paréntesis DESDE EL PRIMER
  BIMESTRE (decisión: no diferir la revelación a fin de año). El alias huérfano
  que el área 14 tenía con el mismo texto se retira (nunca se imprimió).
- Competencia inventada C57 (el catálogo ya tiene precedentes no-CNEB: talleres
  C54-C56, subáreas de primaria): su nombre parafrasea la capacidad oficial de
  conciencia moral del área para que boleta interna y documento SIAGIE "rimen".

### Cambios de código (07/07/2026)
1. **La carga TOE no lleva transversales** (decisión del colegio):
   - `CalificacionController::formulario()` — `$adjuntarTransversales = false`
     si `area_tipo='tutoria'` (antes del branch de dueña unidocente).
   - `getCargas()` — los 3 CASE de contadores transversales (`total`,
     `bloqueadas`, `con_criterios`) devuelven 0 con `a.tipo='tutoria'`.
   - `PanelController::getCargasResumen()` — misma exclusión en sus 2 CASE
     (sin ella, la card del dashboard quedaba atascada esperando TIC/GAMA
     que nunca existirán en esa carga).
   - `TransversalModel::estadoCargasSeccion()` — la carga TOE queda FUERA del
     roster del cierre transversal (`a.tipo NOT IN ('transversal','tutoria')`).
     Sin esto, sumaba +3 al denominador (su propia C57 + 2 TIC/GAMA imposibles)
     y el tutor nunca podía cerrar: la card "Competencias Transversales", el
     panel /docente/tutoria, la validación de cerrar() y el panel del director
     consumen este método. La competencia propia de Ética NO condiciona el
     cierre transversal (ciclo de bloqueo propio, forzable por Hito A).
2. **Candado de exoneración:** `ExoneracionModel::tieneNotasVivas()` +
   guardia en `Admin\ExoneracionController::registrar()` — NO se registra una
   exoneración si el alumno tiene notas vivas del año en esa área/subárea
   (fila en `calificaciones`; la exoneración por área revisa también sus
   subáreas). Evita el estado mixto nota+EXO en grilla y boleta.

### Lo que YA existía y se reutiliza sin tocar
- **Grilla EXO genérica:** `formulario()` pasa `exonerados`
  (`ExoneracionModel::getActivasParaCarga`, match por área/subárea de la carga);
  la vista pinta `fila-exonerado` + `exo-badge` SIN input; `guardar()` y
  `errorBloqueoCompetencia` excluyen exonerados de la completitud. El tutor ve
  al exonerado marcado y no puede calificarlo — exactamente lo requerido.
- Boleta: `ExoneracionModel::inyectarEnAreas` pinta la fila EXO completa
  (posible porque el candado garantiza que un exonerado no tiene notas).
- Criterios libres, conclusiones por escala (secundaria: solo C), Hito A,
  rectificaciones: flujo estándar sin excepciones.

## Calificación extraordinaria — alta de nota por RA (16/07/2026)

> Migración `042_calificacion_extraordinaria.sql`. Permite a admin/RA registrar
> una nota a un alumno SIN calificación en una competencia cerrada/bloqueada,
> desde el módulo de Rectificación. Casos: alumno con omisiones en todos los
> criterios ("declarado en blanco con motivo") o competencia entera "No se
> evaluó". Decisiones del usuario: la nota ES REAL (boleta digital/impresa +
> export SIAGIE) pero NO cuenta en el orden de mérito; se registra en un
> criterio único cuya nota ES el promedio final del alumno.

### Mecánica (respeta todos los invariantes)

1. `CriterioModel::obtenerOCrearExtraordinario` crea (una sola vez por
   carga+competencia+periodo, `WHERE NOT EXISTS`) el criterio "Calificación
   extraordinaria": nace CONFIRMADO (el promedio agregado y el blindaje
   anti-fantasma de la 033 exigen criterio vivo confirmado) y con
   `criterios.extraordinario = 1`.
2. Nota del alumno en ese criterio → `calcularPromedio` (para el alumno sin
   otras notas, promedio = la nota) → `guardarNotaFinal` →
   `marcarCalificacionExtraordinaria` (`calificaciones.extraordinaria = 1`) →
   conclusión (obligatoria según literal+nivel, validación estándar) →
   auditoría `rectificaciones_calificacion` con `tipo='extraordinaria'` y el
   MOTIVO por alumno (obligatorio).
3. La boleta/SIAGIE la toman solos (`getBoletaAlumno`: bloqueo ya existente +
   criterio confirmado). El candado de `notas_autorizadas_siagie` ("sin nota
   real") bloquea automáticamente la doble vía.

### Guardas

- **Descubrimiento** (`RectificacionModel::getCompetenciasInsertables` /
  `esInsertable`): alumno SIN fila en `calificaciones` + **periodo CERRADO**
  (hasta el 18/09/2026 bastaba con «bloqueada»; ver «Solo bimestres cerrados»
  abajo) + carga activa de SU sección + NO exonerado del área/subárea +
  áreas `tipo <> 'transversal'` (las transversales van por la agregación del
  tutor; una fila cruda no llega a boleta). Re-chequeo en el POST.
- **El docente NO toca el criterio extraordinario en ningún estado** (incluida
  una reapertura): `guardar`, `autosave`, `guardarOmisiones`,
  `renombrarCriterio` y `eliminarCriterio` lo rechazan con 403
  (`CriterioModel::esExtraordinario`). En su panel el criterio se pinta solo
  lectura (badge `EXTRAORDINARIA · RA`, sin input, sin Confirmar/Editar/
  Eliminar).
- **El docente lo ve claramente diferenciado**: ~~el criterio lleva badge ámbar~~
  (derogado el 18/09/2026: **ya no se pinta como criterio en ninguna pantalla**)
  y un bloque informativo (`.extraordinaria-info`) con el detalle por alumno —
  nota, MOTIVO registrado, quién y cuándo — dejando claro que NO es parte de su
  registro ordinario (`getExtraordinariasDeCompetencia`).
- **No rompe puertas del docente**: nace confirmado (no bloquea
  `competenciaListaParaResumen`) y la completitud de aprobar mira el promedio
  por alumno (el extraordinario lo tiene).

### Interacciones

- **Orden de mérito: EXCLUIDA** — ver `docs/modulos/orden-merito.md`. El alta
  NO regenera snapshot (el ranking no cambia).
- Tras el alta la competencia es rectificable por el flujo normal (corregir la
  extraordinaria = rectificación estándar, con su propia auditoría).
- ~~El padre ve el criterio "Calificación extraordinaria"~~ en `/padre/notas`:
  desde el 18/09/2026 ve la nota de la competencia **sin** ese criterio.
- **Bimestre cerrado ⇒ la nota aparece AL INSTANTE en la boleta de la
  familia** (regla general de `soloOficiales`); el formulario lo advierte.
- Verificado end-to-end en local (16/07/2026, Inglés 4°A C2, 25 checks):
  boleta +1 fila, SIAGIE la exporta, ranking byte-idéntico, guardas activas.

- **Las notas del colegio de origen y las autorizadas para SIAGIE NO son esto.** Desde el
  10/09/2026 los tres mecanismos llevan chip de PROCEDENCIA desde un punto único
  (`PROCEDENCIAS_NOTA` en `helpers.php`); el `.extra-badge` de la extraordinaria pasó a
  salir de ahí sin cambiar de aspecto. Ver `docs/modulos/ui.md`.


### Captura EN LOTE (10/09/2026) — el mismo motor, otra pantalla

> Rutas `GET /rectificaciones/extraordinaria/lote?matricula=&periodo=` y
> `POST .../lote/guardar`. Sin migración: **no cambia nada del motor**.

**El problema era de UX, no de reglas.** El alta era de UNA competencia por vez, y un
alumno matriculado después del cierre necesita **25-27 altas**: 25-27 pasadas por el
formulario, con su ida y vuelta. Medido sobre los 6 casos reales: 25, 25, 25, 25, 27 y 27.

**Qué se añadió:** una grilla con todas las competencias insertables de UN bimestre,
agrupadas por área, con el literal en vivo, la conclusión que se revela sola donde el
nivel la exige, y **un motivo común** para todo el lote.

**PUNTO ÚNICO de escritura: `RectificacionController::escribirExtraordinaria`.** Se
extrajo del cuerpo de `guardarExtraordinaria` y ahora **las dos vías —individual y lote—
llaman al mismo método**. No abre transacción: la owna quien llama (el lote entero va en
UNA, o entra completo o no entra nada). Era el riesgo real del cambio: dos escrituras
copiadas a mano divergen sin síntoma, que es el patrón de fallo conocido de este repo.

**Guardas:** `esInsertable()` se re-chequea **fila por fila** en el POST —ir en lote no lo
salta—, más nota 0-20, conclusión obligatoria por `conclusionObligatoria($literal, $nivel)`
y motivo no vacío. Si falla una sola fila, **aborta el lote entero**.

⚠️ **Los umbrales de la escala NO se escriben en el JS.** `rectificaciones-lote.js` los
recibe en `data-*` desde las constantes de `helpers.php`, y la lista de literales que
exigen conclusión sale del mismo `conclusionObligatoria` que valida el POST. (El sibling
`rectificaciones.js` sí los tiene a mano, con un comentario que lo avisa; el código nuevo
no repite eso.)

✅ **Hueco CERRADO el 21/09/2026** (antes: «`guardarExtraordinaria` no crea la fila de
`bloqueos_competencia`»). Una competencia que nadie de la sección evaluó pierde su bloqueo
del cierre al limpiar los fantasmas, y la extraordinaria que RA le registraba quedaba
**invisible** en la boleta. Es justo el caso del alumno que trae del colegio de origen
competencias que aquí no se trabajaron. Ahora `escribirExtraordinaria` (punto único del alta
individual y del lote) llama a `CalificacionModel::asegurarBloqueoExtraordinaria`:
`INSERT IGNORE` con `origen='cierre'` (la extraordinaria solo nace en cerrados), no-op si ya
estaba bloqueada, y la limpieza de fantasmas no lo borra (`SIN_EXTRAORDINARIAS_BC`). El aviso
de la grilla ahora dice que se bloquea al guardar. Verificador:
`verif_extraordinaria_bloqueo.php` (llama al método REAL por reflexión; falla contra el
código anterior en 3 asertos).

**Verificación:** `database/verificaciones/verif_extraordinaria_lote.php` (escribe y hace
rollback). Sobre la matrícula 690: +25 en `calificaciones`, +25 marcadas
`extraordinaria=1`, +25 en la auditoría, las 25 dejan de ser insertables, **el ranking de
B1 no mueve ni una posición**, la boleta gana 25 celdas y **ninguna previa cambia**.

#### Las COMPETENCIAS TRANSVERSALES sí entran en el lote (10/09/2026)

**Y son la única parte donde el lote se aparta del alta individual.** El alta individual
las sigue excluyendo (`getCompetenciasInsertables`: `a.tipo <> 'transversal'`), y esa
exclusión **no se derogó**: sigue tal cual para el bimestre en curso.

**Por qué se levanta solo aquí.** La exclusión se justificaba con que «una fila cruda no
llega a boleta, porque las transversales se muestran AGREGADAS desde el cierre del tutor».
**Medido el 10/09/2026 con escritura + rollback: eso NO se cumple en un bimestre CERRADO.**
`getTransversalesAgregadas` promedia las cargas con **bloqueo** y solo exige un
`cierres_transversales` **vigente** — y en un bimestre cerrado las tres condiciones ya se
dan. La celda de la 690 pasó de vacía a `16 / A` con una sola fila. La razón documentada
vale cuando *no* hay cierre ni bloqueo; no cuando el bimestre ya se cerró.

**El hueco que cerraba:** cada nivel tiene **2** competencias transversales, así que los 6
estudiantes que llegaron tarde arrastraban **12 celdas vacías** en B1 mientras **23
compañeros** de la 690 sí las tenían.

**Los tres candados que lo hacen seguro** (`RectificacionModel::getTransversalesInsertables`):

1. **Periodo CERRADO** — el bimestre vivo del tutor no se toca.
2. **`cierres_transversales` VIGENTE** en su sección — sin él la nota quedaría registrada
   e **invisible**, que es peor que no registrarla.
3. **CARGA DUEÑA DERIVADA DEL DATO, nunca elegida a dedo**: la carga que ya aporta esa
   transversal al **resto de su sección** y que tiene bloqueo. Si nadie de la sección la
   trabajó, la competencia **no se ofrece**. En la práctica es **una sola carga por
   sección**, así que no hay ambigüedad. `esInsertableTransversal` rechaza cualquier otra
   carga —verificado— para que no se pueda colar por una ajena.

🔴 **LA TRAMPA, y el motivo de que esto lleve su propio camino de escritura: la CONCLUSIÓN
de una transversal NO vive en `calificaciones`.** La boleta la lee de
`conclusiones_transversales` (la escribe el tutor). Guardarla en
`calificaciones.conclusion_descriptiva` la dejaría **registrada e invisible**, y en primaria
una transversal en B o C **exige** conclusión. Por eso `escribirExtraordinaria` recibe
`bool $esTransversal` y enruta a `TransversalModel::guardarConclusion`. **Si algún día se
toca ese método, esa bifurcación es lo primero que hay que mirar.**

En la grilla, esas filas llevan el chip **«Transversal»** y una línea que dice de dónde
salen. Verificado en `verif_extraordinaria_lote.php` §5b (9 comprobaciones: las dos ramas
de la guarda, que la ordinaria sigue excluyéndolas, la conclusión en su tabla y la celda
—con su conclusión— saliendo en la boleta).

### Ajustes tras las pruebas en navegador (18/09/2026)

Salieron de probar el lote y la rectificación con sesión. **Ninguno cambia el motor.**

- 🔴 **La rectificación perdía lo escrito al rechazarse.** `guardar()` hacía
  `redirectWithError` y `editar()` se repintaba **con las notas de la BD**. Si se reenviaba
  (ya con la conclusión que pedía la C), se guardaba **la nota vieja, sin aviso**. Así falló la
  prueba §B1: una rectificación 17→10 de la 181 quedó auditada como **17→17** (fila 1461),
  y de ahí vinieron los falsos «la boleta y el mérito no reflejan la rectificación». La
  boleta estaba bien: lee en vivo.
  - Servidor: `volverConEntrada()` guarda la entrada en un flash (`rect_old` / `lote_old`) y
    el formulario la repinta. Cubre la rectificación y el lote. El alta individual **no**
    (pendiente, ver `ESTADO.md`).
  - Cliente: `rectificaciones.js` pone `required` en la conclusión en vivo según el literal
    del promedio. Los umbrales y los literales llegan en `data-*`
    (`RectificacionController::literalesConclusion`, que sale de `conclusionObligatoria`).
    **Ya no están escritos a mano en el JS.**
- **Lote: la conclusión es OPCIONAL con cualquier nota.** Aparece en cuanto se escribe una nota
  y solo es obligatoria donde la exige `conclusionObligatoria` (los desaprobados: primaria
  B/C, secundaria C). El servidor ya aceptaba conclusión con cualquier literal.
- **Lote: el input de nota es el del docente**: texto de 2 dígitos, solo dígitos al teclear y
  recorte a 0-20 con dos cifras al salir (como `calificaciones.js`). El servidor recorta
  igual que el del docente.
- **Grilla del docente: la extraordinaria ya no se pinta como un criterio.** Se oculta su
  `criterio-bloque` y en su lugar va la card informativa. Ese marcado vive ahora en el partial
  **`docente/_extraordinaria-info.php`**, que comparten grilla y resumen. «Próximo pendiente»
  y «Sin criterios aún» miran solo los criterios ordinarios. **Solo es presentación**: el
  criterio `extraordinario` sigue en los datos (promedio, boleta y SIAGIE lo leen), y
  `$tieneCriterios` / `$todosConfirmados` lo siguen contando (nace confirmado). Datos:
  `CalificacionController::extraordinariasPorCompetencia` (clave `carga-competencia`, válida
  también en la vista de área).
- **Eliminar un criterio vacío ya sincroniza «Ver resumen».** La rama sin notas no recarga la
  página, así que si el borrado era el único pendiente, el botón seguía bloqueado. Ahora
  `eliminarCriterio` devuelve `resumenAccesible` (`competenciaListaParaResumen`, como
  autosave, Confirmar y Renombrar) y el JS retira el banner «Próximo pendiente» si apuntaba a
  ese criterio.
- Flash duplicado en `/rectificaciones`, `/rectificaciones/matricula/{id}` y `editar`: esas
  vistas repetían lo que ya pinta el layout.

### Solo bimestres cerrados, guarda de desbloqueo y fuera de las grillas (18/09/2026)

Tres decisiones del usuario tras probar la extraordinaria en el bimestre activo.

**1. La extraordinaria solo se registra en bimestres CERRADOS.** Antes bastaba con que
la competencia estuviera **bloqueada**, y eso la abría en el bimestre activo. Se quitó el
`OR bloqueada` de `getCompetenciasInsertables` y `esInsertable` (alta individual, lote y
contador de la ficha; las transversales ya exigían cerrado). La **rectificación** de notas
existentes NO cambia: sigue admitiendo cerrado **o** bloqueada.

**2. No se desbloquea una competencia con extraordinarias.** Motivo medido en local: al
desbloquear, el alumno vuelve a la grilla del docente con los criterios ordinarios vacíos,
«Próximo pendiente» lo empuja a calificarlo y **`calcularPromedio` promedia TODOS los
criterios confirmados, el extraordinario incluido**: un 10 de RA más un 14 del docente da
12, en silencio, y la marca `extraordinaria=1` lo sigue sacando del mérito. Aunque (1)
impide que nazca en el activo, un cerrado puede **reabrirse** y desbloquearse después.
- PUNTO ÚNICO: `CalificacionModel::SIN_EXTRAORDINARIAS_BC` (condición sobre `bc`) y
  `alumnosConExtraordinaria()`, que mira el DATO vivo (`calificaciones.extraordinaria`),
  **no** la auditoría (que sobrevive a una reversión).
- `BloqueoController::desbloquear` y `liberarTransversalCompetencia` abortan con los
  nombres (`abortarSiHayExtraordinarias`). `limpiarBloqueosCierre` **conserva** esas
  competencias y lo informa (`eliminarBloqueosDeCierre` +
  `bloqueosDeCierreConExtraordinarias`); `seccionesConBloqueosDeCierre` usa la misma
  condición para no anular el cierre transversal de una sección a la que no se liberó nada.
- ⚠️ **No existe flujo para REVERTIR una extraordinaria**: hoy solo se corrige (rectificación
  estándar). Si hubiera que desbloquear una, la reversión es a mano (así se hizo con la 1460).

**3. El criterio extraordinario ya no se pinta como criterio en NINGUNA pantalla.**
PUNTO ÚNICO de presentación: `criterios_ordinarios()` en `helpers.php` — **solo
presentación, nunca para calcular**. Pantallas: grilla y resumen del docente, historial y
`/consulta-notas/{p}/carga/{c}` (`_tabla.php`), `/consulta-notas/{p}/criterios` y su
imprimible (`arbolCriterios`) y `/padre/notas`. La fila del alumno muestra «—» en los
criterios y su promedio; la explicación queda **solo en la tarjeta** (decisión del
usuario). Una competencia completada solo por RA se ve sin columnas de criterio pero con
su nota: `_tabla.php` sigue mirando la lista completa para no decir «sin calificaciones».
- **Rectificación:** el criterio extraordinario sale **solo al alumno que tiene nota en él**
  (filtro en `getDetalleCompetencia`, que también fija los ids que acepta `guardar`). Cierra
  el pendiente del criterio 6115, que salía vacío y editable para cualquier compañero.

Verificado: 15 comprobaciones propias con las dos ramas de cada guarda (la de liberar
bloqueos del cierre, forzada en transacción: con los datos reales ningún bloqueo del
cierre tiene extraordinarias), la guarda del controlador en sus dos ramas, las pantallas
generadas con el controlador real y la batería completa en 39 de 39.

### Listado en `/rectificaciones`: estudiantes SIN NINGUNA nota en un bimestre cerrado (21/09/2026)

`/rectificaciones` ya no es solo un buscador: lista a los estudiantes que no tienen **ninguna**
fila en `calificaciones` en un bimestre **cerrado** (en ninguna carga, transversales incluidas),
típicamente los que llegaron tarde. Filtros por GET, sin JS: bimestre (solo cerrados), nivel,
grado y sección; si llegan varios, **manda el más específico** (sección > grado > nivel), para
que un grado de otro nivel no dé un listado vacío que parezca real.

- ⚠️ **Por qué NO es «tiene alguna competencia insertable».** Fue lo primero que se midió y
  daba los **524 estudiantes (998 filas)**: el universo insertable incluye las competencias que
  el docente no evaluó a **nadie** de la sección. Con «lo que sus compañeros sí tienen» salían
  64. El usuario eligió el criterio estricto: hoy son **5 filas** (691, 694, 695 y 698 en el
  I Bimestre; 698 también en el II).
- **«Calificar (N)»** cuenta con el **mismo SQL** que la ficha y el lote, así que N coincide con
  las filas que abre el lote. Con N = 0 no se ofrece el botón.
- **Punto único de «insertable».** `sqlInsertables()` y `sqlTransversalesInsertables()` son
  ahora el único sitio con esas condiciones; los usan `getCompetenciasInsertables`,
  `getTransversalesInsertables` y el listado (`matriculasSinNotasEnCerrados`).
  **`esInsertable` era una COPIA a mano** de la consulta: ahora pregunta a
  `getCompetenciasInsertables`, como ya hacía `esInsertableTransversal`.
- Roster: `roster_evaluacion()`. Año académico activo.
- Verificador: `verif_rectificaciones_pendientes.php` (solo lectura): control a mano, conteo
  contra `insertablesPorPeriodo`, las dos ramas de `esInsertable` y los filtros.
  `verif_extraordinaria_lote.php` y `verif_roster_evaluacion.php` siguen verdes.

## Fixes importantes aplicados (sesión 2)
- `periodos.nombre_display` es la columna correcta (no `nombre`). Si ves
  `Unknown column 'p.nombre'` en queries de periodos, verificar esto.
- `guardarConclusionAlumno` en CalificacionController envuelto en try-catch para
  garantizar siempre respuesta JSON (antes devolvía HTML en excepciones).
- Seed `002_completar_sistema.sql` agrega el usuario padre (DNI 99999999) que
  faltaba en `usuarios` — sin él el padre no puede loguear.
- Competencias completas para primaria y secundaria en seed 002.
