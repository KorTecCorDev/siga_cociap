# Consulta de calificaciones — transversales y conducta

> **Estado: EN PRODUCCIÓN desde el 07/08/2026** (deploy `2242ec7`) — las tres fases.
> Sin migración y sin métodos de modelo nuevos, como prometía el plan.
> **Qué se construyó, las desviaciones y las cifras: §9, que manda sobre §5 y §7.**
> Amplía `/consulta-notas`
> —la capa de supervisión en solo lectura— con los dos registros que hoy no llegan
> a ella: las **competencias transversales** que registra cada docente y la
> **conducta** que cierran auxiliares/RA y el tutor.
> Módulo base: `app/Controllers/Consulta/ConsultaNotasController.php`.
> Reglas de los registros que se incorporan: `docs/modulos/calificaciones.md`
> (transversales) y `docs/modulos/admin.md` (conducta).

---

## 1. Diagnóstico — por qué faltan (no es un olvido de la vista)

### 1.1 Transversales: el JOIN no puede alcanzarlas

`CalificacionModel::getCompetenciasPorPeriodo` une competencia↔carga **por el área o
subárea DE LA CARGA**:

```sql
INNER JOIN competencias comp ON (
    (ca.subarea_id IS NOT NULL AND comp.subarea_id = ca.subarea_id)
    OR (ca.area_id IS NOT NULL AND ca.subarea_id IS NULL AND comp.area_id = ca.area_id)
)
```

Las transversales cuelgan de un **área propia** con `tipo='transversal'` (id **9**
primaria, **21** secundaria, 2 competencias cada una), que por definición nunca coincide
con el área de la carga del docente. **El vínculo transversal↔carga no existe en el
esquema**: se resuelve por NIVEL, que es como las busca
`CriterioModel::getCompetenciasTransversalesConCriterios`
(`WHERE a.tipo = 'transversal' AND a.nivel_id = ?`).

No es un filtro que se pueda relajar — es el mismo límite que ya mordió en el panel de
bloqueos, donde `calificaciones.md` dice que "se registran bajo la carga del docente pero
NO aparecen como filas en el panel".

**Volumen invisible hoy** (medido en local, copia de prod del 05/08):

| Periodo | Criterios | Notas crudas | Cargas |
|---|---|---|---|
| B1 | 46 | 1 050 | 23 (modelo viejo: carga del tutor) |
| B2 | 743 | **17 078** | 345 |

### 1.2 Conducta: no vive en `calificaciones`

Son **cuatro tablas propias** (`criterios_conducta`, `conducta_respuestas`,
`calificaciones_conducta`, `cierres_conducta`) y su ciclo es **por SECCIÓN y en dos
etapas** (RA bloquea → tutor cierra), no por carga. La jerarquía de la vista
(periodo → sección → carga) no tiene dónde colgarla salvo **a nivel de sección**.

### 1.3 Hueco de roles que esto cierra

`/consulta-notas` sirve a `admin`, `registro_academico`, `director_general` y
`director_ebr`. Pero `/admin/conducta` es admin+RA y `/docente/tutoria` es docente+admin
(y filtra por tutor). O sea que **hoy los dos roles de dirección no tienen NINGUNA forma
de ver conducta ni el agregado transversal**. Esta vista es su única capa de supervisión.

---

## 2. Decisiones cerradas del usuario (06/08/2026 — NO re-preguntar)

- **D1 — Transversales: las DOS caras.** El **crudo por carga** (lo que registró cada
  docente, criterio a criterio, dentro de su propia pantalla de carga) **y** el
  **agregado por sección** (promedio por competencia + conclusión del tutor + estado del
  cierre: lo que efectivamente llega a la boleta).
- **D2 — Conducta: entrada nueva DENTRO de `/consulta-notas`**, a nivel de sección y en
  solo lectura, reusando `ConductaModel`. **No** se enlaza a `/admin/conducta` ni se
  amplían los roles de esa pantalla: tiene botones de escritura y no es de solo lectura
  por diseño.
- **D3 — Alcance: SOLO LO OFICIAL**, coherente con lo que la pantalla ya promete
  ("muestra únicamente las notas oficiales"). Transversales: con **cierre vigente** del
  tutor. Conducta: con **las dos etapas** cumplidas y sin anular. Lo que está a medias no
  aparece.

---

## 3. Lo que hace este plan barato: cero métodos de modelo nuevos

Verificado con una sonda de solo lectura sobre datos reales (carga 45, competencia 26,
1.º A primaria, B2):

- ✅ **`CalificacionModel::getResumenCompetencia` funciona IGUAL con una competencia
  transversal**: devolvió 2 criterios y 22 alumnos, con las mismas claves
  (`matricula_id, …, promedio, conclusion_descriptiva, notas_criterios, literal`). Como
  `carga.php` itera bloques uniformes y delega en `_tabla.php`, **basta con añadir los
  bloques transversales al array `$competencias`** para que se pinten con el mismo
  lenguaje visual. Sin tocar el modelo.
- ✅ `TransversalModel::getPromediosSeccion` devuelve las filas **indexadas por matrícula,
  con las claves = `competencia_id`** (26, 27); `getConclusionesSeccion` y
  `getCierreVigente` completan el agregado (este último trae `cerrado_en` y
  `cerrado_por_nombre`).
- ✅ `ConductaModel::getEstudiantesParaTutor(seccion, periodo, totalCriterios)` ya trae
  todo lo necesario en una sola llamada: `nota_ra`, `nota_tutor`, `nota_final`,
  `literal_final`, `respondidos`, `si`, **`es_legado`** y `literal_legado`.
  `getCierreDetalle` da las dos etapas con nombres.

Esto mantiene la promesa original del módulo: *"reutiliza la capa de datos de
CalificacionModel y no introduce métodos de modelo nuevos"*.

---

## 4. Dos trampas medidas — leer antes de implementar

### 4.1 🔴 El BLOQUEO no es señal de que haya notas transversales

Hay **820 bloqueos transversales en CADA bimestre, sobre 410 cargas** — pero en B1 solo
**23 cargas** tienen notas. El bloqueo se propaga en cascada (cierre forzado), así que
copiar el criterio actual de la vista ("mostrar lo que tenga `bloqueo_id`") pintaría en
B1 **410 bloques vacíos**.

**Regla para F1:** un bloque transversal se incluye solo si está **bloqueado Y tiene al
menos un criterio vivo con notas**. La condición de contenido no es opcional.

### 4.2 🔴 B1 y B2 no comparten modelo de conducta

| Periodo | `conducta_respuestas` | `calificaciones_conducta` | Cierres completos |
|---|---|---|---|
| B1 | **0** | 528 | 23 |
| B2 | 5 240 | 524 | 23 |

B1 es el **bimestre legado** (anterior a la migración 021): solo literal, sin criterios.
No hace falta código nuevo — `getEstudiantesParaTutor` ya devuelve 22 filas en B1 y marca
`es_legado`, y existe `getLiteralesLegado`. **La vista tiene que ramificar por
`es_legado`**, como ya hace `/admin/conducta/{id}?periodo=`.

---

## 5. Fases

Orden recomendado: **F1 → F3 → F2**. F1 es la que el usuario pidió literalmente y la más
contenida; F3 cierra el hueco de roles; F2 es la que más superficie nueva añade.
Las tres son independientes y desplegables por separado. **Ninguna lleva migración.**

### F1 — Transversales crudas dentro de la carga

**Archivos:** `ConsultaNotasController::carga()` · `consulta-notas/carga.php`
· `consulta-notas/seccion.php` (contador).

1. En `carga()`, tras el bucle actual, resolver el **nivel** desde
   `$primera['nivel_id']` (`getCompetenciasPorPeriodo` ya lo devuelve) y pedir las
   transversales de esa carga.
2. Filtrar por las dos condiciones de §4.1 (bloqueo en `bloqueos_competencia` para
   `carga+competencia+periodo`, y ≥1 criterio con notas).
3. Para cada una, `getResumenCompetencia(cargaId, competenciaId, periodoId)` y
   empujarla al array `$competencias` con `'es_transversal' => true`.
4. En `carga.php`, separador `.transversales-separador` antes del primer bloque
   transversal y clase `.competencia-card--transversal` en su card. **Ambas clases ya
   existen** en `pages/_dashboard.scss` (líneas 1052 y 1100) — reusar, no duplicar.
5. Actualizar el contador "N competencia(s)" de la tarjeta de carga en `seccion.php`
   para que incluya las transversales, o el número mentirá.

### F2 — Agregado transversal por sección

**Ruta nueva:** `GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/transversales`
**Archivos:** `routes/web.php` · `ConsultaNotasController::transversales()` (nuevo)
· `consulta-notas/transversales.php` (nueva) · `consulta-notas/seccion.php` (tarjeta)
· `pages/_consulta-notas.scss` si hace falta.

1. Gate de D3: `TransversalModel::getCierreVigente(seccionId, periodoId)`; si es `null`,
   la tarjeta no se pinta y la ruta responde `notFound()` (no basta con ocultar el
   enlace: la ruta queda en marcadores, mismo criterio que los guards de boletas).
2. Datos: `getPromediosSeccion` + `getConclusionesSeccion` +
   `TransversalModel::getCompetencias($nivelId)` para los encabezados.
   ⚠️ Los promedios vienen **indexados por matrícula y con `competencia_id` como clave**
   — hay que cruzarlos con el roster, no asumir orden.
3. Vista: tabla alumnos × competencias (numeral + literal según nivel, vía
   `nota_a_literal()`, **nunca umbrales hardcodeados**), columna de conclusión, y pie con
   quién cerró y cuándo (`cerrado_por_nombre`, `cerrado_en`).

### F3 — Conducta por sección

**Ruta nueva:** `GET /consulta-notas/{periodo_id}/seccion/{seccion_id}/conducta`
**Archivos:** `routes/web.php` · `ConsultaNotasController::conducta()` (nuevo)
· `consulta-notas/conducta.php` (nueva) · `consulta-notas/seccion.php` (tarjeta).

1. Gate de D3: `getCierreDetalle(seccionId, periodoId)` con `ra_bloqueado_en` **y**
   `tutor_cerrado_en` no nulos y `anulado_en` nulo. Si no, `notFound()`.
2. Datos: `totalCriterios($nivelId)` → `getEstudiantesParaTutor(...)`; si las filas
   vienen con `es_legado`, pintar la tabla simple **nombre + literal**; si no, la grilla
   con `nota_ra` / `nota_tutor` / `nota_final` / `literal_final` y el detalle de criterios
   (`getCriterios($nivelId)`).
3. Reusar las clases de `pages/_conducta.scss` (`.conducta-grilla`) en su estado
   deshabilitado, como ya hace `docente/conducta-criterios.php`. Sin formularios ni JS.
4. Pie con las dos etapas: quién bloqueó y quién cerró, con fecha.

---

## 6. Invariantes a respetar (del CLAUDE.md y de los módulos tocados)

- **Solo lectura, sin excepciones.** Ni un `POST`, ni un input, ni un botón de acción.
  Para corregir está `/rectificaciones` (que audita) y las pantallas propias de cada
  registro. No ampliar `requireRole` de ningún controlador existente.
- **Rutas literales antes que patrones.** Las nuevas tienen 5 segmentos y no colisionan
  con `/consulta-notas/{periodo_id}/seccion/{seccion_id}` (4), pero registrarlas en el
  mismo bloque y en orden.
- **Nada de CSS inline en PHP.** Todo a `resources/sass/pages/_consulta-notas.scss`
  (el módulo ya tiene su parcial) y reusar antes de crear.
- **Comillas ASCII** en los atributos HTML de las vistas nuevas.
- **No reimplementar rosters.** `getPromediosSeccion` y `getEstudiantesParaTutor` ya
  aplican el roster correcto (incluidas las exclusiones de retorno de grado). Copiar el
  filtro a mano es exactamente como nacieron los bugs de asistencia del 04/08.
- **Escala:** literales siempre por `nota_a_literal()`.

---

## 7. Verificación (antes de dar por hecha cada fase)

Script de solo lectura en `database/verificaciones/`, ejecutable en prod:

1. **F1** — para cada carga con transversales bloqueadas y con notas, el nº de bloques que
   pintaría la vista == el nº de competencias transversales con criterios. En B1 debe dar
   **23 cargas**, no 410 (es la trampa §4.1 vigilada).
2. **F2** — para las 23 secciones, el agregado que muestra la vista coincide **alumno a
   alumno** con `TransversalModel::getPromediosMatricula`, que es la fuente que usa la
   boleta. Si divergen, la supervisión estaría mostrando algo distinto de lo entregado.
3. **F3** — el nº de filas de la grilla == el roster de conducta de esa sección, y
   `literal_final` coincide con `ConductaModel::getParaPeriodo` (la fuente de la boleta).
   Probar **B1 (legado) y B2** en la misma corrida: son caminos de código distintos.

---

## 9. LO QUE SE CONSTRUYÓ (07/08/2026). Manda sobre §5 y §7.

**Estado: en `dev`, sin desplegar. Sin migración.** Las tres fases entraron juntas.

### Desviaciones respecto del plan

- **F1 usa un helper propio, no `getCompetenciasTransversalesConCriterios`.**
  `ConsultaNotasController::transversalesConContenido(cargaIds, periodoId)` resuelve
  bloqueo **+ `EXISTS` de calificaciones** en una sola consulta y devuelve indexado por
  carga, así que sirve igual a `carga()` (una carga) y a `seccion()` (todas, para el
  contador) sin duplicar SQL ni hacer N+1. El detalle sigue armándose con
  `getResumenCompetencia`, como decía el plan — la sonda se confirmó en vivo: sobre una
  competencia transversal devuelve las mismas claves (1 criterio, 24 alumnos).
- **El roster sale de `ConductaModel::getEstudiantesParaTutor`**, también para F2. No hay
  un getter de roster genérico en el proyecto, y escribir otro SELECT habría duplicado el
  filtro de exclusiones de retorno — justo el origen de los bugs de asistencia del 04/08.
  Queda documentado en el propio método (`rosterSeccion`).

### 🔴 La corrección más importante: en B1 el crudo por carga NO existe

El plan pedía verificar que F1 diera **23 cargas en B1**. Da **0**, y es correcto: en B1
regía el modelo viejo —carga única del tutor— y esas 23 cargas están hoy en
`estado='inactiva'`, así que `getCompetenciasPorPeriodo` (que filtra `estado='activa'`)
nunca las alcanza. **El crudo por docente es un concepto que nace en B2.** Para B1 el
valor está en el agregado (F2), que sí funciona y compara sin divergencias.

### Cifras medidas

| | B1 | B2 | B3 |
|---|---|---|---|
| Cargas con bloqueo transversal | 410 | 410 | 2 |
| Cargas con CONTENIDO (lo que se pinta) | **0** | **345** | 2 |
| Bloques vacíos evitados | **410** | **65** | 0 |
| Secciones que ofrecen transversales | 23 | 23 | **0** |
| Secciones que ofrecen conducta | 23 (legado) | 23 | **0** |

B3 con las dos entradas en 0 es el **gate D3 funcionando**: nada cerrado, nada que ver.

### Verificación

`database/verificaciones/verif_consulta_notas_ampliada.php` — solo lectura, corre en prod,
acepta el número de bimestre. Lo que de verdad prueba es que **la supervisión y la boleta
dicen lo mismo**: contrasta el agregado contra `getPromediosMatricula` y los literales de
conducta contra `getParaPeriodo`, que son las fuentes del documento que recibe la familia.
**2086 celdas y 1048 filas comparadas, 0 divergencias**, con B1 (legado) y B2 (modelo
nuevo) en la misma corrida.

---

## 8. Fuera de alcance (consciente)

- **El índice `/consulta-notas` no cambia su CONTENIDO.** Sigue listando las secciones
  con ≥1 competencia bloqueada. Una sección que tuviera conducta cerrada y ninguna nota
  bloqueada no aparecería — hoy no existe ese caso, y meterlo obligaría a rehacer el
  conteo de la portada.
  > ⚠️ Su **navegación** sí cambió después (24/08 y 26/08/2026): ver §10.
- **Exoneraciones en el agregado transversal:** no se filtran. Las transversales no se
  exoneran; si algún día se pudiera, esta vista habría que revisarla.
- **Asistencia** no entra: es el cuarto registro del bimestre y tiene su propio
  imprimible oficial (`/admin/asistencia`). Si se decidiera sumarla, el sitio natural es
  otra tarjeta de sección con el mismo patrón de F3.

---

## 10. Los tres ejes de navegación (24/08 → 26/08/2026)

El módulo se recorre por **tres caminos sobre el mismo universo**: por SECCIÓN (el
índice), por DOCENTE (`/{p}/docentes`) y por CRITERIO (`/{p}/criterios`). Los dos
últimos nacieron el 24/08/2026 como dos `btn btn--secondary` sueltos al final del
`page-header` del índice.

**El 26/08/2026 pasaron a un conmutador.** Los dos botones tenían tres defectos que no
eran de estética:

1. **No representaban el eje activo** — el usuario no sabía en cuál de los tres estaba.
2. **Desaparecían al entrar** a Docentes o Criterios: saltar de uno al otro obligaba a
   volver al índice.
3. Como hijos sueltos del `page-header` pesaban lo mismo que el back-link, y **no se
   alineaban a la derecha** (causa raíz del layout: ver `docs/modulos/ui.md`).

### Punto único: `resources/views/consulta-notas/_nav.php`

Ese partial renderiza **las dos piezas de chrome del módulo, en este orden**:

1. la **card del selector de bimestre** — el ámbito, común a las tres pestañas;
2. el **conmutador de ejes** — Secciones · Docentes · Criterios.

Van juntas porque salen del **mismo mapa de rutas** (`$rutas`); en dos partials ese mapa
se escribiría dos veces y acabaría divergiendo. Las tres vistas lo montan justo debajo del
`page-header` y solo declaran `$ejeActivo` (`'secciones'` | `'docentes'` | `'criterios'`)
antes del `require`; el eje activo es un dato de la vista, no del Presenter.

- **Los back-links se quedan** (`← Dashboard`, `← Secciones`, `← Consulta de notas`): el
  back-link es la salida del módulo hacia arriba, el conmutador es movimiento lateral.
  Son dos gestos distintos.
- El botón **Imprimir** de `/{p}/criterios` sigue en el `page-header`: eso sí es una
  acción, no un eje.
- Estilo: `.consulta-ejes` / `.consulta-eje` en `resources/sass/pages/_consulta-notas.scss`.
  ⚠️ Es el **tercer** conmutador con CSS propio del repo (`.curr-sidebar__tab`,
  `.periodo-tab`). Antes de escribir un cuarto, extraer un componente global.

### El bimestre es ÁMBITO, no contenido de una pestaña (26/08/2026)

Antes el selector estaba en tres sitios distintos y con tres conductas: card **debajo** de
la barra en el índice, **inexistente** en Docentes, y **primer campo del formulario de
filtros** en Criterios. Desde el 26/08 hay uno solo, en la card de `_nav.php`.

- 🔴 **La card va ENCIMA de la barra de pestañas.** Debajo se leía como contenido de la
  pestaña Secciones cuando manda sobre las tres. Arriba = ámbito global; lo que queda
  debajo de la barra pertenece a la pestaña — en Criterios, su card de filtros.
- **El `action` del formulario es la ruta del eje ACTIVO**, así que cambiar de bimestre
  conserva la pestaña. En Secciones `periodo_id` es el parámetro real; en Docentes y
  Criterios viaja como query sobre la ruta vieja y el servidor redirige.
- **Punto único del salto: `ConsultaNotasController::saltarDePeriodo()`.** Lo usan las dos
  pestañas que llevan el periodo en la ruta. Nació al extraer el bloque que ya vivía dentro
  de `criterios()`; `index()` no lo necesita.
- **El placeholder `— Seleccionar periodo —` solo sale con `$periodo === null`** (el índice
  sin selección). En las otras dos el periodo siempre existe (`notFound()` si no) y sería
  una opción muerta.
- **Las pestañas solo se pintan si hay periodo**; la card siempre — es el control que hace
  falta para elegirlo.
- ⚠️ **Sigue vigente que cambiar de bimestre LIMPIA los cuatro filtros de Criterios**
  (regla del 25/08/2026): el redirect suelta la query. Y sacar el `<select>` de ese
  formulario no lo pone en riesgo — elegir el bimestre **ya seleccionado** no dispara
  `change`, así que el formulario no se envía.
- `<noscript>` con un botón **Cambiar**: sin JS no hay `onchange`, y Criterios funcionaba
  sin JS gracias al botón "Aplicar" del formulario que ahora ya no contiene el bimestre.
- **Las vistas profundas** (`seccion`, `carga`, `docente`, `conducta`, `transversales`,
  `asistencia`) **no llevan selector**: no son pestañas y cada una tiene sus propios
  parámetros de ruta.

## Extraordinarias: una sección al final de la carga (21/09/2026)

`/consulta-notas/{p}/carga/{c}` ya no explica las calificaciones extraordinarias bajo cada
competencia: van en **una sola sección al final de la página**, agrupada por competencia, que
incluye también las competencias sin tabla propia en la carga. Detalle y datos en
`docs/modulos/calificaciones.md` («La extraordinaria, en UNA sección al final de la carga»).

