# UI/UX: wayfinding, dashboard y componentes

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.

## Mejoras de UI/UX (sesión 3)

### Sticky columns en tablas docente
- **`/docente/calificaciones/...`** — columnas N° y Apellidos congeladas al hacer
  scroll horizontal (`.col-num` sticky left:0, `.col-nombre` sticky left:40px)
- **`/docente/calificaciones/.../resumen/...`** — mismo patrón; además:
  - `.col-criterio` min-width:80px, `.col-conclusion` min-width responsivo (200/260/320px)
  - `.fila-pendiente` conserva su background naranja en celdas sticky
  - `.conclusion-texto` reemplaza el inline `style="font-size:12px"`
- Ambas tablas viven dentro de `.tabla-notas-wrapper` (overflow-x:auto)

### Componentes SASS nuevos/extendidos
- **`_buttons.scss`** — `.btn-group { display:inline-flex; gap:$spacing-sm }` reutilizable
- **`_tables.scss`** — `.tabla-notas-wrapper`, `.tabla-resumen` con sticky columns y
  `.conclusion-texto`
- **`_admin.scss`** — `.usuario-avatar` (círculo con iniciales coloreado por rol),
  `.td-usuario`, `.td-acciones`, `.form-grid`, `.form-section-title`, `.form-actions`,
  `.select-rol`, `.text-danger`, `.text-sm`, `.fila-inactiva`
- **`_boleta-digital.scss`** — todo el sistema de diseño de la boleta digital (BEM `.bd-`)

## Fixes importantes aplicados (sesión 4)
- **Comillas tipográficas en PHP** — `resources/views/admin/secciones/index.php`
  tenía comillas U+201D (`"`) en atributos HTML del botón de asignación en lugar de
  comillas ASCII U+0022 (`"`). El parser HTML las trata como texto, no como delimitadores
  de atributo → todos los `data-*` quedaban rotos → `SyntaxError` en el browser.
  **Diagnóstico:** `node -e "const src=require('fs').readFileSync('file.php','utf8');
  for(let i=0;i<src.length;i++){const c=src.charCodeAt(i);if(c===0x201C||c===0x201D)
  console.log('linea',src.substring(0,i).split('\\n').length,src[i]);}"` 
  **Fix:** `src.replace(/[""]/g, '"')` y reescribir el archivo.
- **`data-label` con guillemets** — el atributo usaba `«»` literales que `e()` no escapa.
  Corregido a `data-label="<?= e($s['grado_numero'] . $s['seccion_nombre']) ?>"` 
  (formato `1A`, `2B`, etc. — simple y sin caracteres especiales).
- **Fuentes Inter 404** — `@font-face` usaba rutas absolutas con `/siga-cociap/public/`.
  Corregido a rutas relativas `../assets/fonts/inter/` en `_typography.scss`.

## Sistema de color "wayfinding" (16/06/2026)

> Color FIJO por concepto en todo el sistema, como ayuda de orientación: hay
> docentes que trabajan en varios colegios y se saturan con mucha información;
> el color fijo deja que ubiquen el acceso sin leer.

- **Tokens en `resources/sass/base/_variables.scss`** (bloque "Wayfinding del dashboard
  docente"). Cada concepto tiene 3 variantes: `-line` (borde vivo), `-bg` (fondo tenue),
  `-ink` (título oscuro legible):
  | Concepto | `-line` | `-bg` | `-ink` |
  |---|---|---|---|
  | **Académicas / Mis cargas** (`$card-cargas-*`) | `#1e6fa8` azul | `#eef5fb` | `#1a5a8c` |
  | **Transversales / Tutoría** (`$card-tutoria-*`) | `#0d9488` teal | `#ecfbf8` | `#0f766e` |
  | **Conducta** (`$card-conducta-*`) | `#7c3aed` púrpura | `#f5f0fe` | `#6d28d9` |
  | **Nómina** (`$card-nomina-*`) | `#e07b1a` naranja | `#fef3e2` | `#b45309` |
- **REGLA:** rojo (`$color-error`) y ámbar (`$color-warning`) quedan RESERVADOS para los
  badges de estado (error/advertencia); NUNCA se usan como identidad de un acceso.
- Combinación azul↔naranja + teal/púrpura: bien diferenciable con daltonismo.
- Aplicado en `/docente/inicio` y `/director/bloqueos`. Usar estos mismos colores en
  futuras vistas para el mismo concepto.

### El GLIFO también es fijo por concepto (28/08/2026)

El mismo razonamiento del color vale para el icono: si dos cards comparten glifo,
el atajo de «ubicarlo sin leer» deja de funcionar.

🔴 **REGLA: dos cards del dashboard NUNCA comparten icono.** Cuando una card nueva
no encuentre un icono que le pegue, **se añade uno**; no se toma prestado el del
vecino. Los iconos viven en `public/assets/icons/` y son del set Solar (SVG Repo):
`viewBox="0 0 24 24"`, `fill="none"`, trazo `#1C274C` de `1.5` con puntas
redondeadas. Se pintan con un `<img>` a 36 px, así que el SVG lleva su propio
color: **no se recolorean con `currentColor`**.

Pasaba con **tres pares** a la vez, todos por la misma causa —cards que nacieron
después y reusaron lo que había—:

| Card | Tomaba prestado de | Ahora |
|---|---|---|
| Cuadros estadísticos | Orden de mérito (`medal-ribbon-star`) | `stats.svg` |
| Criterios de evaluación | Rectificación de notas (`edit-pen`) | `criterios.svg` |
| Consulta de notas | Buscar estudiante (`lupa-look`) | `notas.svg` |

⚠️ **La medalla era el caso grave**: `_docente-panel.scss:156` ya la usa como
wayfinding de mérito (`.page-title--wf`), así que el mismo glifo significaba
«mérito» en el panel del docente y «cuadros» en el del admin — el sistema se
contradecía a sí mismo. Igual con la lupa, que `_docente-panel.scss:298` usa para
buscar. **Esos dos usos del SASS no se tocaron: eran los correctos.**

Hay dos asertos en `verif_direccion_superficies.php` que lo sostienen: ninguna
card comparte icono (con una lista explícita de excepciones toleradas) y todo
icono referenciado existe en disco — **un nombre mal escrito no da error**, pinta
un `<img>` roto que nadie ve hasta abrir el dashboard.

**Excepción viva:** `users-group-rounded.svg` sigue en «Secciones y Tutores» y
«Ranking por sección». Al resolverla, quitarla de `$duplicadosOk` en el
verificador y la lista queda vacía.

## Dashboard del docente — cards de acceso (16/06/2026)

- **`/docente/inicio`** (`Docente\PanelController::index`) tiene 4 cards en `.dpanel-grid`:
  **Mis cargas académicas** (azul), **Tutoría — {grado}{sección}** (teal), **Conducta —
  {grado}{sección}** (púrpura) y **Nómina de matriculados** (naranja). Tutoría y Conducta
  solo aparecen si el docente es tutor del año activo (`$tutoria`/`$conducta` no nulos).
- `PanelController` ahora inyecta `ConductaModel` y calcula `$conducta` (mismo origen que
  usaba `mis-cargas`: `ConductaModel::getCierreVigente`).
- **Se eliminaron las cards largas** (`.tutoria-card`) de Tutoría y Conducta de
  `/docente/mis-cargas`; `CalificacionController::misCargas` ya NO calcula
  `$tutoria`/`$conducta` (y se quitaron de ese controlador `TransversalModel`/`ConductaModel`
  que quedaron sin uso). La vista solo lista cargas.
- **SASS** en `pages/_docente-panel.scss`: modificadores `.dpanel-card--{cargas,tutoria,
  conducta,nomina}` (borde + título por color). Todas las cards van sobre **fondo blanco**;
  el color de concepto vive solo en el borde izquierdo y el título.
- **CORRECCIÓN (31/07/2026):** este doc afirmaba que el fondo tenue (`-bg`) se pintaba
  cuando la card llevaba estado activo. **No es cierto hoy**: las clases
  `.dpanel-card--{cerrado,disponible,progreso}` que la vista sigue emitiendo **no tienen
  ninguna regla** ni en `pages/_docente-panel.scss` ni en `public/css/app.css` (verificado
  sobre el CSS compilado). Son ganchos inertes. Las reglas `&--progreso/&--disponible/
  &--cerrado` que existen en `pages/_dashboard.scss:929` pertenecen a `.tutoria-card`, la
  card larga que se retiró de `/docente/mis-cargas`. **En `/docente/inicio` el ÚNICO
  elemento que comunica estado es el badge** (ver sección del semáforo, más abajo).

## Nómina del docente — buscador destacado + íconos de sección (22/06/2026)

`/docente/nomina` (`Docente\PanelController::nomina`, vista `resources/views/docente/nomina.php`).
- **El buscador es la acción principal y va PRIMERO**, destacado: card `.nomina-buscar`
  (borde de marca + acento lateral `$brand-accent` + fondo `$brand-light` + sombra),
  campo con ícono de lupa (patrón `.buscador__campo`/`.buscador__icono`/`.buscador__input`),
  `autofocus`. El panel **Imprimir nómina** baja al final como acción secundaria
  (`.nomina-imprimir-card`: fondo gris, borde punteado).
- **Cada sección lleva su ícono de título** (`.nomina-seccion-ico`, mask + currentColor):
  Buscar → `lupa-look.svg`; Imprimir → `printer.svg`.
- El `h1` lleva el ícono de concepto wayfinding (ver sección siguiente).
- SASS en `pages/_docente-panel.scss`. JS `public/js/nomina.js` intacto (todo por ID).

### Quién sale en el buscador — SOLO matrículas `aprobada` (10/08/2026)

Decisión del usuario. El buscador pasó de listar `aprobada + pendiente + desactivado`
a listar **solo `aprobada`**, el mismo criterio que ya usaba la nómina imprimible.
Medido: de **525 a 521** (salen las 3 matrículas `pendiente` del año y 1 `retirado`;
los `trasladado` ya estaban fuera).

🔴 **Esto NO saca a nadie de la evaluación, y la distinción es el punto entero:**

| | Quién aparece |
|---|---|
| **Rosters** (grilla de notas, asistencia, conducta, transversales) | `aprobada` + `pendiente` + `desactivado` — **sin cambios** |
| **Buscador de la nómina** (consulta) y nómina imprimible | solo `aprobada` |

- Un roster es donde se **escribe**: excluir de ahí a un `pendiente` significa que
  nadie puede calificarlo, que su boleta sale con `0 faltas` —dato falso, no ausente—
  y que su evaluación incompleta **abortaría el cierre**. Es el invariante de
  `CLAUDE.md` y el motivo del fix del 04/08. **Aquí solo se oculta una card de
  resultado**: ningún dato cambia.
- **Verificado con `verif_roster_asistencia.php` tras el cambio: OK**, y su bloque 3
  sigue listando a las 3 `pendiente` (696, 695, 693) como parte del roster.
- ⚠️ **Contrapartida conocida:** un `desactivado` por DEUDA sí se califica y ya no
  saldrá en el buscador. Hoy no muerde —los 11 desactivados del año son
  trasladados/retirados, fuera de la evaluación—, pero es el precio de filtrar por
  estado en esta pantalla.

## Wayfinding en el h1 de cada vista del docente (22/06/2026)

> Continuidad card→vista: el `h1` de cada vista a la que lleva una card del
> dashboard del docente lleva el MISMO glifo que la card, pequeño (`1.05em`) y
> antes del texto, tintando SOLO el ícono con el tono `-ink` del concepto (texto
> neutro = subtil). Refuerza el [sistema wayfinding por color](#sistema-de-color-wayfinding-16062026).

- **Punto único de verdad** en `pages/_docente-panel.scss`, junto al mapa de las
  cards (`.dpanel-card--*`): clase base `.page-title--wf` (icono vía `::before`,
  mask con `var(--wf-icon)` tintado con `var(--wf-ink)`) + modificadores por concepto.
- **Mapa concepto → ícono → color** (los `h1` y las cards comparten glifo):
  | Concepto | Modificador h1 | Ícono | `-ink` |
  |---|---|---|---|
  | Mis cargas | `page-title--cargas` | `book-bookmark` | azul |
  | Tutoría | `page-title--tutoria` | `users-group-rounded` | teal |
  | Conducta | `page-title--conducta` | `smile` | púrpura |
  | Nómina | `page-title--nomina` | `childs-students` | naranja |
  | Orden de mérito | `page-title--merito` | `medal-ribbon-star` | naranja (familia Nómina) |
  | Ranking por sección | `page-title--ranking` | `ver-resumen` | naranja (familia Nómina) |
- Aplicado en `mis-cargas`, `tutoria`, `conducta`, `nomina`, `orden-merito`
  (selector compartido: el modificador se elige por `$rutaBase`) y las dos vistas
  de periodo de mérito/ranking.
- **Colisión de íconos resuelta**: `users-group-rounded` quedó EXCLUSIVO de Tutoría.
  La card Nómina y su acción "Ver nómina" usan `childs-students`; "Buscar estudiante"
  usa `lupa-look`.
- **REGLA de coexistencia de colores (opción A — por rol/forma):** el color de
  CONCEPTO (wayfinding) vive solo en el chrome (cards + `h1`); el color de SECCIÓN
  (`--sec-*`, paleta por letra A-F en `_dashboard.scss`) vive en chips/anclas
  (`.seccion-ancla`). Comparten varios hex (Conducta púrpura == Sección B,
  Tutoría teal == Sección C), pero NO se confunden porque cada sistema vive en
  una forma/posición distinta. NUNCA pintar un `h1` con color de sección ni un
  chip de sección con color de concepto.

## Mis cargas — ancla de sección monocroma + jerarquía de grado (24/06/2026)

En `/docente/mis-cargas` (vista `mis-cargas.php`, SASS `pages/_dashboard.scss`):
- **Ancla de sección**: la **letra es el identificador** (única dentro del grado,
  por eso va grande y sola). Pasó a **monocromo** en la familia "Mis cargas" (azul
  `$card-cargas-*`); se quitó la paleta por letra del ancla y el grado/nivel
  repetidos. Render `"Sección A"` (rótulo + recuadro de la letra, sin duplicar la
  letra). La paleta por letra `.seccion-ancla--{letra}` (`--sec-*`) se **ELIMINÓ**
  (24/06): el acordeón del ranking (`.merito-seccion-acordeon` en
  `/docente/ranking-seccion`) era su único consumidor y ahora usa el MISMO monocromo
  azul de "Mis cargas" (`$card-cargas-*`). Distinción de sección = la LETRA, nunca
  color por letra (confunde con el wayfinding por concepto).
- **Bloque de grado** (`.card--grado` + `.grado-head`): se diferencia por
  **jerarquía tipográfica** (nivel como antetítulo + grado en grande), NO por color
  (los grados son secuenciales; un tinte por grado competiría con el azul de la
  página).
- **Ranking por sección estandarizado (24/06):** `ranking-seccion-periodo.php` ya no
  aplica `seccion-ancla--{letra}`; `.merito-seccion-acordeon` (`pages/_docente-panel.scss`)
  usa `$card-cargas-*` fijo. Misma lectura que mis-cargas (letra = identificador).

## Documentos en ventana nueva — botón "Cerrar" autocerrable (02/07/2026)

> **Bug corregido:** todos los documentos que abren en ventana aparte (boletas,
> reportes A4, nóminas) tenían un botón **"Volver"** que, en móvil, en vez de
> regresar a la ventana original **creaba una copia** del origen y las pestañas
> se **acumulaban** → lentitud en celulares. Reemplazado por **"✕ Cerrar"** que
> cierra la ventana de verdad. Commit `8d56103` (ya en `dev` y `main`).

### Causa raíz
- Los documentos se abren con `<a target="_blank" rel="noopener">` → la pestaña la
  crea el NAVEGADOR (no un script). Una pestaña recién abierta con `_blank` tiene
  `history.length === 1`, así que el "Volver" (`history.back()`) no aplicaba y caía
  a su *fallback*: **navegar al `document.referrer`** → una copia del listado de
  origen. La pestaña original seguía viva debajo. Cada documento abierto desde ahí
  dejaba otra pestaña `_blank` → acumulación.
- `window.close()` (que el *fallback* también intentaba) está **bloqueado** por el
  navegador cuando `window.opener` es `null`, y `rel="noopener"` justamente lo
  anula. Por eso "Cerrar" solo funciona si controlamos **cómo se abre** la ventana.

### Solución (Opción A — decidida por el usuario)
Una ventana **abierta por script** (`window.open`) SÍ es autocerrable con
`window.close()` desde su propio botón, aunque no se conserve el handle. Todas las
páginas son del **mismo origen** (el riesgo de `noopener` aquí es nulo).

- **Origen — interceptor global en `resources/js/app.js`** (cargado por
  `layouts/app.php` en toda vista interna): delegación de clic que captura
  `a[target="_blank"]` **del mismo origen**, hace `e.preventDefault()` +
  `window.open(href, '_blank')`. Respeta clic-medio/ctrl/cmd/shift/alt (abrir en
  2.º plano a voluntad), ignora `href="#"`, `download`, `mailto:`/`tel:`/`javascript:`
  y enlaces externos. **NO edita ninguno de los ~15 enlaces**; `target="_blank"`
  queda como *fallback* sin-JS. Hoy TODOS los `_blank` internos son exactamente
  estos documentos, así que el interceptor global == el conjunto objetivo.
- **Destino — botón "✕ Cerrar"** (reemplaza "Volver") en los **dos únicos** layouts
  de documento:
  - `layouts/print.php` (`.btn-boleta--cerrar`, id `btnCerrarDoc`) — cubre boleta
    imprimible, traslado, orden de mérito, desempates, nómina detallada, cuadro
    resumen, horario, y las 3 de admin boletas (`vista-previa`/`boletas-alumno`/`archivar`).
  - `boleta/digital.php` (id `bdCerrar`, ícono X) — boleta digital.
- **JS de cierre** en `print-fit.js` y `boleta-digital.js`: `window.close()` y, si
  quedó bloqueado (ventana abierta a mano, no por script), *fallback*
  `history.back()` → referrer del mismo origen → `base-url`.
- **SASS:** modificador `.btn-boleta--volver` → `.btn-boleta--cerrar` en
  `pages/_boleta.scss` (misma apariencia). Recompilar con `gulp build`.

### Alcance verificado
- Inventario de destinos: **todos** caen en `layouts/print.php` o
  `layouts/digital.php` (el botón solo vivía en esos 2 sitios) → el cambio es
  centralizado. No hay otras vistas de impresión con back propio.
- **Pendiente de validar en móvil real** (Chrome Android / Safari iOS): abrir
  varias boletas seguidas y confirmar que "✕ Cerrar" cierra la pestaña y no se
  acumulan. Es comportamiento de ventanas del navegador, no simulable por CLI.

## Card del tutor renombrada: "Competencias Transversales" (07/07/2026)

La card del dashboard docente que abre `/docente/tutoria` (conclusiones + cierre
de TIC/GAMA) pasó de titularse "Tutoría — {grado} {sección}" a **"Competencias
Transversales — {grado} {sección}"** (`docente/inicio.php`), y su subtítulo dejó
de repetir el nombre. Motivo: con Ética y Valores, "Tutoría (TOE)" es ahora una
carga académica más en mis-cargas y el rótulo viejo era ambiguo. La página
destino ya se titulaba "Tutoría — Competencias Transversales" (sin cambios); la
clase `.dpanel-card--tutoria` y el color teal de wayfinding se conservan.

## Semáforo de estado de las cards del tutor (31/07/2026)

> Las cards **Competencias Transversales** y **Conducta** de `/docente/inicio` pintaban
> de ÁMBAR todo lo que no estuviera cerrado, así que "todavía no depende de ti" y "te
> toca registrar" se veían idénticos. Ahora el color distingue **de quién depende la
> acción**.

**REGLA — tres estados, un solo significado por color:**

| Color | Badge | Significado | Transversales | Conducta |
|---|---|---|---|---|
| **Gris** | `badge--espera` | **No depende del tutor todavía** | `Bloqueadas X de Y` (faltan docentes de área) | `En espera` (RA no bloqueó) |
| **Ámbar** | `badge--warning` | **Le toca al tutor** | `Disponible — N conclusión(es) pendiente(s)` · `Disponible para cerrar` | `Disponible` |
| **Verde** | `badge--activo` | **Terminado** | `Cerrado el dd/mm/aaaa` | `Cerrada el dd/mm/aaaa` |

- **Punto único:** `$tBadge` / `$cBadge` (dos `match()` en `resources/views/docente/inicio.php`,
  junto al cálculo de `$tEstado`/`$cEstado`). El badge se elige ahí, nunca inline en el HTML.
- **`badge--espera`** (`components/_cards.scss`) es NUEVO y separado de `badge--sin-notas`
  aunque compartan fondo `#f1f5f9`: significan cosas distintas y el texto va un paso más
  oscuro (`#475569` vs `#64748b`) porque en 11px el gris claro queda al límite del
  contraste AA.
- **"Disponible para cerrar" se queda en ÁMBAR a propósito.** El verde tiene UN solo
  significado: "esta card ya no me pide nada". Si se pintara verde al terminar de
  registrar, el docente asumiría que acabó y dejaría el bimestre sin cerrar (y el KPI
  "Avance del bimestre" nunca llegaría a 100%, porque cuenta `cierre`/`cerrado`, no los
  registros — ver `PanelController::index`).
- **NO usar rojo para "todavía no te toca"** (evaluado y descartado el 31/07/2026):
  1. invierte la jerarquía de atención — el rojo jala la vista hacia la única card donde
     el docente **no puede actuar**, y deja la accionable por debajo;
  2. es el estado NORMAL al abrir cada bimestre, así que todo tutor vería sus dos cards
     en rojo sin haber hecho nada mal → el rojo se desgasta;
  3. en esa misma pantalla el rojo YA significa urgencia (`dpanel-kpi__num--err` en
     "Cargas sin criterios" y en `diasCierre <= 3`, `badge--error` en Pendientes).
- **Los textos NO cambiaron** — solo el color. La lógica de estados
  (`PanelController::index`, líneas de `$tutoria`/`$conducta`) quedó intacta.

### Asimetría conocida (deuda consciente)
En **Conducta**, el ámbar (`Disponible`) NO distingue "aún no registro ninguna nota" de
"ya registré todo, solo falta cerrar", porque **ese dato no existe**: `PanelController`
solo trae `cierre` y `cerrado`. Para diferenciarlo haría falta un método nuevo en
`ConductaModel` que cuente matrículas del roster con `nota_tutor IS NULL`
(`completitudSeccion()` NO sirve: mide las respuestas del auxiliar, no la nota del tutor).
Se decidió **no implementarlo por ahora** (31/07/2026). Transversales sí distingue, vía
`$tutoria['pendientes']`, pero ambos sub-casos comparten el ámbar por la regla de arriba.

## Modales: cerrar descarta lo no guardado (04/08/2026)

**Síntoma (detectado probando `/admin/curriculum`):** se abría "Editar área", se
tecleaba algo, se pulsaba **Cancelar** y al volver a abrir el modal seguían los valores
tecleados — como si el cambio se hubiera guardado, cuando no se envió nada a la BD.

**Causa:** `Modal.cerrar()` (`resources/js/app.js`) solo ocultaba el overlay. Los modales
se renderizan **una sola vez** con los valores de la BD en el atributo `value`; al
escribir se cambia la **propiedad** del control, no el atributo, así que el DOM conserva
lo tecleado indefinidamente. No era un fallo de servidor: la BD nunca se tocó.

**Arreglo:** `Modal.cerrar()` hace `form.reset()` sobre cada `<form>` del overlay, dentro
de `cleanup()` (no al iniciar el cierre, para que los campos no parpadeen durante la
animación de salida). `reset()` devuelve cada control a su valor por defecto, que es
exactamente el que pintó el servidor.

**Por qué es seguro tocarlo en el `Modal` genérico** — inventario completo de los 7
modales del sistema al momento del cambio:

| Modal | Origen de sus valores | Efecto del reset |
|---|---|---|
| Currículo: área, subárea, competencia | servidor (`value="..."`) | **corrige el bug** |
| `modalFechas`, `modalReabrir` (`anio-academico.js`) | JS, reescritos en CADA apertura | ninguno |
| `modalTutor` (`secciones.js`) | JS: `sel.innerHTML` se rehace en CADA apertura | ninguno |
| `modalCierre` (`director/anios/show.php`) | sin formulario | ninguno |

El envío AJAX de `modalTutor` llama a `Modal.cerrar()` y acto seguido a
`window.location.reload()`, así que el reset no puede revertir nada ya guardado.

⚠️ **Regla para modales nuevos:** si un modal se puebla por JS, debe hacerlo en **cada**
apertura, nunca una sola vez al cargar la página. `Modal.cerrar` asume esa invariante.

## Orden alfabético en español: la Ñ va después de la N (04/08/2026)

**Síntoma (detectado en la grilla de notas de 4° A primaria):** ÑIQUEN PAJUELO aparecía
**antes** que NOLASCO REYES.

**Causa:** las columnas de `personas` son `utf8mb4_unicode_ci`, colación que equipara
**Ñ ≡ N** en el nivel primario de comparación. Al comparar "ÑIQUEN" con "NOLASCO" la Ñ
pesa como N, empatan en la primera letra y decide la segunda: **I < O**, así que ÑIQUEN
salía primero. En el alfabeto español la Ñ es letra propia y va **después** de la N, o
sea NOLASCO → ÑIQUEN. No era un fallo del roster ni de la grilla: era el `ORDER BY`.

**Arreglo:** `COLLATE utf8mb4_spanish_ci` en el ordenamiento, con punto único en
`app/Helpers/helpers.php`:

```php
const COLLATE_ES = 'COLLATE utf8mb4_spanish_ci';
function orden_alfabetico(string $alias = 'p', int $campos = 3): string

// uso:  ORDER BY " . orden_alfabetico('p') . "
//       ORDER BY n.id, g.numero, " . orden_alfabetico('per') . "
```

Aplicado a los **30 `ORDER BY`** de 19 archivos: grillas de notas/asistencia/conducta,
tutoría, boletas públicas, nóminas, exportación SIAGIE, usuarios, apoderados,
exoneraciones, rectificaciones y Centro de Control.

**Por qué NO se cambió la colación de las columnas** (la alternativa obvia, que habría
arreglado los 30 sitios con un `ALTER TABLE`): la colación no gobierna solo el orden,
también el `=` y el `LIKE`. Hoy, gracias a `unicode_ci`, buscar "NUNUVERO" encuentra a
**NUÑUVERO** — y la gente teclea sin ñ. Además arrastra riesgo de
`Illegal mix of collations` contra columnas de otras tablas que siguen en `unicode_ci`.
El `COLLATE` en el `ORDER BY` cambia el orden y **nada más**.

`spanish_ci` y no `spanish2_ci`: la segunda trata CH y LL como letras independientes,
criterio que la RAE abandonó en 1994.

**Impacto real medido:** 58 personas tienen Ñ en su nombre, pero comparando las 23
secciones con ambas colaciones **solo cambia 4° A de primaria** — en las demás la Ñ está
en medio del apellido y no compite contra una N en la misma posición.

**Lo que NO se ve afectado:**
- **Actas SIAGIE:** `MatcherEstudiantes::normalizar` mapea `'Ñ' => 'N'` y casa por código
  o por nombre normalizado **en PHP**, nunca por posición de fila.
- **Orden de mérito:** los puestos ya no se desempatan por apellido (la cascada termina
  en `m.id`), así que el snapshot de B1 no se mueve.

⚠️ **Regla para consultas nuevas:** todo `ORDER BY` sobre nombres de personas usa
`orden_alfabetico()`. Nunca ordenar por `p.apellido_paterno` a secas ni por un alias
`CONCAT(...)` — el alias hereda la colación de la columna y reintroduce el problema (era
el caso de `ControlOperativoModel::alertasEvaluacionIncompleta`, que ordenaba por `alumno`).


## Zona de resultado: el hover no puede borrarla (25/08/2026)

`.col-resultado` marca las columnas **calculadas** (promedio, nota final, literal)
para que no se confundan con las de origen. Vive en `components/_tables.scss` y la
usan **seis vistas**: `consulta-notas/{conducta,transversales,_tabla}` y
`docente/{conducta,resumen-competencia,tutoria}`.

- 🔴 **Su fondo era `#f8fafc`, el MISMO valor literal que `$bg-secondary`**, que es
  el color del hover de fila. Al pasar por una fila, toda ella tomaba ese gris y la
  zona de resultado **desaparecía** — justo cuando se está señalando la fila, y
  justo la función para la que la clase existe.
- **La solución es el ESCALÓN, no un color**: en hover la zona sube un tono
  (`#eef2f7`, el que ya usa `thead .col-resultado`) y la diferencia relativa se
  conserva en los dos estados. No entra ningún color nuevo al sistema.
- ⚠️ **Va por ESPECIFICIDAD, no por orden**: `.tabla-{notas,resumen} tr:hover td`
  es **(0,2,2)** y la regla de la zona es **(0,3,2)**. Y se escriben **las dos
  familias** de tabla porque cada una define su propio hover; un
  `tr:hover .col-resultado` suelto (0,2,1) no bastaría.
- **La zona de resultado CIERRA la fila.** En `consulta-notas/conducta.php` el
  orden pasó a `N° | Nombre | Sí/total | ┃ Nota auxiliar | Nota tutor | Final |
  Literal`: el separador `col-resultado--inicio` abre un bloque que no debe quedar
  interrumpido por una columna suelta a su derecha. La regla vale para **las dos
  ramas** de esa vista (el bimestre legado también abre su zona con el separador).
- Verificado en `database/verificaciones/verif_zona_resultado.php`, que mide la
  **propiedad** —que el escalón sobreviva al hover— y no un color concreto: fijar
  el valor convertiría cualquier retoque de la paleta en un fallo.

### `.tabla-pie`: el pie de grilla, formalizado (25/08/2026)

Contenedor estándar de lo que va **debajo** de una tabla: leyendas, notas al pie,
totales en texto. Dentro va `.tabla-pie__leyenda` con sus `__item`.

- 🔴 **VA FUERA DE `.tabla-notas-wrapper`, NUNCA DENTRO.** El wrapper es el área de
  scroll (`overflow-x: auto`). Un pie metido ahí falla de **tres** formas a la vez:
  se **desplaza** con la tabla al hacer scroll horizontal y se va de la pantalla,
  queda **pegado al borde** sin margen, y si la grilla está en un `.card` —que
  lleva `overflow: hidden` con esquinas redondeadas— sale **recortado** por la
  curva. Los tres pasaron en la grilla de conducta.
- El pie es **hermano** del wrapper, no su hijo:
  `<div class="tabla-notas-wrapper"><table>…</table></div>` + `<div class="tabla-pie">`.
- `--suelto` para cuando no hay card alrededor (quita borde y fondo).

## `.page-header`: cómo se alinean las acciones a la derecha (26/08/2026)

El `page-header` es el encabezado de **79 vistas**. Su convención de marcado es:

```html
<div class="page-header">
    <a class="btn btn--secondary btn--sm">← Volver</a>   <!-- back-link -->
    <div>                                                <!-- SIN CLASE: titulo + subtitulo -->
        <h1 class="page-title">…</h1>
        <p class="page-subtitle">…</p>
    </div>
    <div class="btn-group">…</div>                       <!-- acciones, a la derecha -->
</div>
```

- **El `<div>` SIN CLASE es el que crece.** `pages/_dashboard.scss` lleva
  `.page-header > div:not([class]) { flex: 1 1 auto; min-width: 0 }`. Es lo que empuja
  a la derecha lo que venga detrás: botones, badges o un selector de periodo.
- 🔴 **`.page-title { flex: 1 }` NO hace ese trabajo y no hay que creérselo.** En 72 de
  las 79 vistas el `h1` cuelga del `<div>`, que es `display:block`, así que **no es un
  item flex y su `flex-grow` no aplica**. Durante meses ningún hijo directo del header
  creció: las acciones se pegaban al subtítulo y, con subtítulos largos, envolvían a una
  línea propia **a la izquierda**. Se detectó en `/consulta-notas` y afectaba también a
  matrículas, orden de mérito, `padre/notas` y actas SIAGIE.
- **La regla se conserva igualmente**: hay 7 vistas donde el `h1` sí es hijo directo del
  header, y en `docente/mis-cargas.php` y `padre/inicio.php` ese `flex: 1` está **vivo**
  y es lo único que alinea sus badges. Es inerte —no dañino— en las otras 72.
- ⚠️ **Si un header necesita DOS bloques, el segundo lleva clase.** Dos `<div>` sin clase
  se reparten el ancho a partes iguales y el segundo queda flotando a mitad de fila. Para
  botones, la clase es `.btn-group` (`components/_buttons.scss`), el agrupador oficial.
  `admin/actas_siagie/index.php` era el único caso del repo y se corrigió así.
- ⚠️ `resources/sass/components/_dashboard.scss` contiene una **copia muerta** de
  `.page-header` / `.page-title`: `app.scss` no lo importa. Editar esa copia no cambia
  nada en pantalla — la buena es `pages/_dashboard.scss`.

## `.dash-grupo`: el bloque de sección, no solo su rótulo (26/08/2026)

El patrón «rótulo + lista» de las páginas internas es **un componente de dos piezas**
(`pages/_dashboard.scss:67-82`) y casi nadie copia la primera:

```scss
.dash-grupo   { margin-bottom: $spacing-xl; &:last-child { margin-bottom: 0 } }
.dash-grupo__titulo { margin: 0 0 $spacing-md; … }   // ← margin-top: 0
```

- 🔴 **El cierre de la sección lo da el CONTENEDOR, no el rótulo.** Como
  `__titulo` no lleva `margin-top`, dos bloques con el rótulo suelto quedan a **0 px**:
  la lista de arriba cierra en 0 y el h2 de abajo abre en 0. Pasó en
  `consulta-notas/seccion.php`, donde «ÁREAS Y CARGAS» salía pegado a la última tarjeta
  de «Registros de la sección». Se arregló envolviendo cada bloque en
  `<section class="dash-grupo" aria-labelledby="…">`, sin tocar una línea de SASS.
- El `:last-child { margin-bottom: 0 }` es la otra mitad que se pierde al copiar solo el
  rótulo: sin él, la última sección arrastra hueco muerto al pie de la página.
- El mismo arreglo se aplicó a `admin/cuadros/index.php` (5 bloques) el mismo día, y ahí
  el defecto era **otro**: el `mb-lg` sí estaba vivo, pero el hueco lo ponía la clase del
  CONTENIDO (`.tabla-responsive mb-lg`), que **solo existe en la rama con datos**. En las
  dos ramas `empty-state` —bimestre sin calificaciones, grado sin ranking— la separación
  caía a **0 px**, justo en la pantalla que se mira cuando algo falta.
  🔴 **La separación entre bloques no puede colgar de una rama del contenido.** Con el
  contenedor es la misma con datos y sin ellos.
- Al envolver, los `mb-*` que **cerraban** bloque se quitan (si no, 24 + 32 = 56 px); los
  **internos** —los que separan piezas dentro del bloque, como los KPIs de su párrafo—
  se conservan. `dashboard/index.php` fue siempre el modelo: ya usaba el contenedor.

### ⚠️ Una utilidad `.mb-*` puede estar MUERTA y no notarse

En `consulta-notas/seccion.php` el hueco se pedía con `<ul class="consulta-cargas mb-lg">` y **nunca se
aplicó**. `.mb-lg` (`pages/_dashboard.scss:1215`) y `.consulta-cargas`
(`pages/_consulta-notas.scss:36`) tienen la **misma especificidad (0,1,0)**, y
`.consulta-cargas` declara el margen inferior **dentro de un shorthand**
(`margin: $spacing-md 0 0`). Manda el orden de `app.scss`, donde
`pages/consulta-notas` (`:43`) va **después** de `pages/dashboard` (`:25`) → gana el `0`.

- **No hay síntoma en el código**: la clase está escrita en la vista y la regla está en el
  SASS. Solo se ve en el CSS compilado — `grep -o '\.mb-lg{[^}]*}' public/css/app.css` y
  comparar la **posición** (`grep -bo`) con la del selector del componente.
- **Regla: si existe el componente, usar el componente, no el utilitario.** Un `.mb-*`
  sobre un elemento que ya define ese lado del margen es una apuesta al orden de imports.
- Mismo patrón de fallo que el `.page-title { flex: 1 }` de arriba: una regla de layout
  presente en el código, inerte en pantalla, durante meses.

---

### `.tabla-pie__leyenda`: una sola leyenda para las grillas de datos

Explica bajo la tabla lo que las cabeceras solo dicen con `title` — un tooltip **no
existe en móvil ni para quien navega con teclado**, que es justo donde trabajan los
auxiliares. Vive en `components/_tables.scss`, con las tablas.

- La usan el partial de asistencia (F/FJ/T/TJ) y la grilla de conducta (las tres
  notas y el `Sí / total`). Nació como `.asistencia-leyenda` el mismo día y se
  extrajo al raíz **antes de que hubiera una segunda copia**.
- 🔴 **NO se llama `.tabla-leyenda`, y el motivo importa.** Ese nombre se usó unas
  horas y **ya estaba ocupado**: `pages/_registro-cierre.scss` lo tiene para una
  `<table>` de los imprimibles (`admin/conducta/imprimir.php`). Como ese parcial se
  importa **después**, el `display:flex` de la de pantalla caía sobre la tabla del
  papel y el `font-size: 6.5pt` del papel sobre las leyendas de pantalla: rompía en
  las **dos** direcciones y sin ningún error visible. Antes de bautizar una clase,
  buscarla en TODO `resources/sass/` — y buscarla también anidada (`&__x`), que es
  como se escapó `competencia-card__codigo` el mismo día.
- Cada página aporta solo su modificador (p. ej. `--registrada` en asistencia).
  Si una hoja de `pages/` vuelve a declarar `display`/`gap`/`color` para una
  leyenda propia, es la copia que se quería evitar.
- ⚠️ **Esto NO gobierna todas las clases `*-leyenda` del sistema**: las de boleta
  impresa, horario y el donut de bloqueos son de otro contexto y no se unifican.

### La misma regla, aplicada a los GRÁFICOS (04/09/2026)

«Un tooltip no existe en móvil ni para quien navega con teclado» vale igual para
el tooltip de Frappe Charts — y allí es peor, porque **es el único sitio donde
están los valores**. En `/admin/cuadros` el A4 imprimía once gráficos y solo uno
dejaba sus números legibles.

- **Regla: un gráfico nunca es la única fuente de un número.** Va acompañado de
  su tabla de valores (`_tabla-grafico.php`), plegada en pantalla y desplegada en
  papel. Los gráficos **se añaden, no sustituyen** — ya era la regla del módulo
  para las tablas, y aquí se aplica en el sentido inverso.
- 🔴 **Un `<details>` cerrado NO IMPRIME SU CONTENIDO.** Es la trampa de plegar
  algo que también va al papel: sale una hoja en blanco sin ningún error. Ya
  costó una vista imprimible aparte en el explorador de criterios; aquí se
  resuelve con un flag `$abierta` en el partial compartido.
- **Lo que se plegue en pantalla y se imprima necesita un aserto.** Ninguna
  prueba de servidor ve que un bloque no se imprimió: el verificador comprueba
  que el imprimible no emita ni un `<details>`.
- El mismo criterio se aplicó al `title` de una celda (`12 de 28 no cumplen` →
  se pinta bajo el porcentaje). Un `title` puede acompañar, nunca ser la única
  fuente.


## El chip de código, y su modificador `--solo` (25/08/2026)

`.competencia-card__codigo` es **el** chip de código del sistema. Su nombre está
anclado a `competencia-card` por historia, pero 4 de sus 6 usos ya estaban fuera de
ese bloque: el proyecto lo trata como global.

Marca el código de **competencias** (`codigo_minedu`: C1…C57) y el de **criterios
de conducta** (`criterios_conducta.codigo`, migración 056), que son numeraciones
distintas y no se cruzan — cada una vive en su pantalla.

- **`--solo`**: el chip lleva `margin-right` porque normalmente va **delante de un
  nombre**. Cuando el código va solo —una cabecera de columna— ese margen lo
  descentra. El modificador lo quita y aprieta el relleno.
- ⚠️ **Al usarlo en una cabecera, revisar el `min-width` de la columna**: el chip
  trae su propio relleno. En la grilla de conducta hubo que subir
  `th.conducta-th-crit` de 56 a 64 px, o el chip de `C10` tocaba los bordes.
- ⚠️ **Buscarlo en el SCSS por su bloque padre.** Está escrito como `&__codigo`
  anidado dentro de `.competencia-card`, así que `grep "competencia-card__codigo"`
  sobre `resources/sass/` **no lo encuentra** — y hay una copia idéntica en
  `components/_dashboard.scss`, que **no se importa desde ningún sitio**. La
  vigente es la de `pages/_dashboard.scss`.

### El literal se muestra, no se insinúa con el color

En la grilla Sí/No de conducta la nota mostraba solo el **numeral**, con el literal
reducido a la clase de color (`nota-numeral--a`). Un color no es legible para quien
no distingue esos tonos, y el **imprimible oficial ya estampa `17 (A)`**: la
pantalla decía menos que el papel.

Ahora hay **dos columnas**: Nota y Literal, como en `/conducta`. 🔴 El literal se
**sumó**, no sustituyó al numeral — hay un aserto que falla si alguien «simplifica»
quitando cualquiera de los dos, y otro que comprueba sobre el **DOM** que son dos
`<td>` y no dos `<span>` en la misma celda (contar clases no distingue una cosa de
la otra).

Las dos van marcadas con **`col-resultado`**, la zona de resultado del sistema: son
columnas **calculadas** a partir de los Sí/No, igual que el promedio y el literal de
las demás grillas. ⚠️ Al aplicarla hubo que **retirar el `border-left` manual** que
`conducta-th-nota` traía para separarse de los criterios: `col-resultado--inicio` ya
dibuja ese separador, y mantener los dos daba doble línea.

## Pestañas dentro de una pantalla — COMPONENTE GLOBAL (27/08/2026)

Existe por fin un componente de pestañas reutilizable, y con él se cierra la deuda
que `pages/_consulta-notas.scss` llevaba declarada: *«antes de escribir un CUARTO
conmutador, extraer un componente global»*. Eran cuatro (`.consulta-eje`,
`.periodo-tab`, `.curr-sidebar__tab`, `.bloqueos-tabcard`) y `/admin/cuadros`
habría sido el quinto.

- **`resources/sass/components/_tabs.scss`** — `.tabs` / `.tab` / `.tab-panel`.
  El aspecto es la tira con subrayado de `.consulta-eje`, la más sobria de las
  cuatro y la que ya se lee como «navegación dentro de la pantalla».
- **`resources/js/tabs.js`** — comportamiento calcado de `bloqueos.js`, el único
  tabs accesible que había.

### Cuál usar

| Si… | Usa |
|---|---|
| cada pestaña es **otra URL** | `.consulta-eje` (enlaces, sin JS) |
| muestran/ocultan **paneles de la misma página** | **este componente** |

### Marcado

El **servidor** decide la pestaña inicial, así que sin JavaScript la página sigue
siendo correcta: se ve el panel que el servidor dejó visible, simplemente no se
puede cambiar.

```html
<div class="tabs" role="tablist" data-tabs="conducta"
     data-tabs-memoria="cuadros.tab.conducta.2">
  <button class="tab tab--activa" role="tab" id="tab-x"
          data-tab="x" aria-controls="panel-x" aria-selected="true">X</button>
</div>
<div id="panel-x" class="tab-panel" role="tabpanel" data-panel="x"
     aria-labelledby="tab-x">…</div>
```

- **SIEMPRE hay una pestaña activa.** Es la diferencia deliberada con el hub de
  `/director/bloqueos`, que nace colapsado y cuyo segundo clic cierra el panel:
  allí el detalle es opcional y caro; aquí un grupo sin nada visible sería una
  sección en blanco.
- Teclado: ←/→/Home/End, con *roving tabindex* (el Tab entra y sale del grupo de
  una vez; recorrer siete pestañas a base de Tab para llegar al contenido es hostil).
- `data-tabs-memoria` es **opcional**. La clave la elige quien renderiza, porque
  solo él sabe de qué depende: en el tablero lleva el id del bimestre, para que la
  pestaña recordada no se mezcle entre bimestres. `localStorage` va en `try/catch`:
  que la memoria falle no puede dejar las pestañas sin funcionar.

### 🔴 Gráficos dentro de una pestaña: el fallo que hay que conocer

Instanciar un SVG (Frappe Charts) dentro de un contenedor con `hidden` lo mide a
**0 px** y nace roto **sin ningún error en consola**. Por eso el componente emite
**`tabs:mostrado`** sobre el panel al mostrarlo (burbujea, `detail = {grupo, nombre}`)
y quien dibuje gráficos se suscribe para dibujar **perezosamente**:

- al cargar, todo contenedor **visible** (`offsetParent !== null`);
- al recibir el evento, los que acaban de hacerse visibles;
- `data-dibujado="1"` impide repetir.

`tabs.js` no sabe nada de gráficos y `cuadros.js` no sabe nada de pestañas: se
hablan solo por ese evento. **El imprimible no carga `tabs.js`**: todos los paneles
están visibles y todo se dibuja al cargar, por la rama de «contenedor visible».

Hay asertos en `verif_direccion_superficies.php` para las tres formas de romperlo
sin que se note: una pestaña sin panel, ningún o más de un `aria-selected="true"`
por grupo, y —la más silenciosa— **una serie calculada sin contenedor donde
dibujarse**. Esa última cazó de verdad la evolución de conducta desapareciendo del
bimestre en curso, justo cuando más sirve.

### Migración pendiente

Los cuatro conmutadores anteriores **siguen sin migrar** (tocan módulos ajenos al
cambio que trajo el componente). Ya no hay que extraer nada: hay que migrarlos.

## Banners de aviso — COMPONENTE ÚNICO (02/09/2026)

Un solo componente para todo aviso del sistema: `resources/sass/components/_alerts.scss`.
**47 banners en 29 vistas.**

### 🔴 El fallo que cerró: `display: flex` sobre una frase

`.flash` y `.alert` eran los dos `display: flex; align-items: center` **sin `flex-wrap`**.
El contenido de un banner es **una frase**, y flex la *blockifica*: cada `<strong>` pasa a
ser un ítem propio y **cada tramo de texto contiguo pasa a ser un ítem anónimo**. Esto

```html
<div class="flash flash--info">
    Texto… Cerrado el <strong>02/09/2026</strong> por Juan Pérez.
</div>
```

no salía como una frase sino como **tres columnas** en fila. Medido en Chrome con un banner
de 340 px: los ítems caían en `x=204 / x=340 / x=431`, con alturas `143 / 42 / 80 px` que
`align-items: center` además descuadraba entre sí. **A 1100 px también estaba roto**, solo
que menos obvio: la frase salía partida en tres bloques separados por huecos.

Los banners que se veían bien lo hacían **por casualidad**: su contenido era un único nodo
de texto, o sea un solo ítem anónimo. Bastaba con meter un `<strong>` para romperlos.

**Un banner es un párrafo: su display es de flujo.** El icono sale del flujo
(`position: absolute`) hacia un hueco reservado con `padding-left` — no con `gap`, porque
el `gap` solo separa el primer renglón y las líneas siguientes se meterían bajo el icono.

### Había TRES declarantes del mismo componente

Y el que mandaba no era el componente:

| Dónde | Qué era |
|---|---|
| `components/_alerts.scss` | el componente de verdad (icono, borde, `:has()`) |
| `components/_cards.scss` | `.flash`, un segundo banner con otra letra y otro padding |
| **`pages/_auth.scss`** | **una copia entera de `.alert`**, sin selector de página que la acotara |

`app.scss` importa `pages/auth` (línea 26) **después** de `components/alerts` (línea 15),
así que esa copia ganaba **en toda la aplicación, no solo en login** — el `app.css`
compilado traía dos bloques `.alert{…}`. Le faltaban la variante `--info` y los iconos, y
cambiaba `align-items` a `flex-start` para todos. Es el mismo fallo que ancla
`verif_zona_resultado.php` con `.tabla-leyenda`.

⚠️ **`components/cards` se importa DESPUÉS de `components/alerts`**: si alguien devuelve un
`.flash` a `_cards.scss`, gana por orden y reimpone el `display: flex`. Hay aserto.

### Cuál usar

| Si el banner es… | Marcado |
|---|---|
| una frase, con o sin `<strong>`/`<a>` dentro | `<div class="alert alert--info">` y el texto suelto |
| título + cuerpo | `<strong class="alert__titulo">` + el texto |
| cuerpo con lista o varios párrafos | `<p>` / `<ul>` como hijos directos |
| lleva un icono que dice algo que la variante no | un `.btn-icon` como hijo directo |
| lleva un botón | `class="btn … alert__accion"` |

```html
<div class="alert alert--info">
    <span class="btn-icon btn-icon--locked" aria-hidden="true"></span>
    Asistencia <strong>bloqueada por Registro Académico</strong> el 28/08/2026.
    <a href="…" class="btn btn--secondary btn--sm alert__accion">🖨 Imprimir registro</a>
</div>
```

- **El `<span>` que envuelve el texto es OPCIONAL.** Era obligatorio de hecho —sin él la
  frase se partía— y ya no lo es. Los dos banners que lo traían (`consulta-notas/asistencia.php`,
  `docente/tutoria.php`) eran los únicos bien construidos del proyecto; se conservan.
- **NUNCA un glifo a mano** (`✓ ⚠ ⚡ ✅`). La variante ya pinta su icono, y la guarda
  `:has()` que evita el duplicado **solo ve elementos**: un carácter suelto no la activa y
  el banner sale con **dos** iconos. Había nueve; se borraron. Hay aserto que barre las
  29 vistas para que no vuelvan.
- **`alert__accion` cae en su propia línea**, alineado a la derecha. No se usa
  `float: right` para recuperar la fila en pantalla ancha: el flotante se ancla a la
  **última** línea del párrafo, así que en un banner de tres líneas el botón acababa a
  media altura y pegado al texto.

### `.flash` es un ALIAS deprecado

Comparte el mismo ruleset que `.alert` (`.alert, .flash { … }`), no es un segundo
componente: fundir los selectores impide que vuelvan a divergir. **Marcado nuevo usa
`.alert`.** Los 31 `.flash` que quedan son renombrado cosmético, con **cambio visual cero**,
y por eso se puede hacer por lotes o nunca.

⚠️ **La migración tiene un bloqueante**, y hay que decidirlo ANTES: `resources/js/auth.js`
autocierra `.alert--success` y `.alert--warning` **en todas las páginas**. Renombrar los
`.flash` haría que los mensajes de sesión empezaran a desaparecer solos. Ver `docs/ESTADO.md`.

### Qué NO es este componente

`.alerta-item` (`padre/alertas.php`) y `.alerta-empate` (`director/orden-merito-periodo.php`)
son otros bloques, de `pages/_dashboard.scss`. Un `grep 'class="alert'` los captura **por
prefijo** e inflaba el inventario de 47 a 55. Hay aserto que delimita el alcance.

### Verificación

`database/verificaciones/verif_banners_aviso.php` — **24 asertos**, sin base de datos: mide
el CSS **servido** y el marcado de las 29 vistas. Comprueba la **propiedad** (que el
contenedor no vuelva a ser flex ni grid), no valores concretos de padding o color.

⚠️ **Su propio regex tuvo un fallo que conviene conocer**: copiar el `(?:^|[};])` de
`verif_zona_resultado.php` y usarlo con `preg_match_all` **se come una regla de cada dos**
—el prefijo consume el `}` de cierre y la siguiente se queda sin delimitador—. Allí
funciona porque se usa con `preg_match`, una sola regla. El aserto acusaba al CSS de algo
que no pasaba.

---

## Índice de anclas de una página larga (07/09/2026)

`/admin/cuadros` acumuló seis bloques, doce gráficos y cuatro tablas largas. La
única forma de saber que existía «Estudiantes en riesgo» —cuarto bloque, detrás
de dos tablas y dos gráficos— era desplazarse hasta toparse con él.

Componente: `.cuadros-indice` (`pages/_cuadros.scss`), una tira de anclas que
**reutiliza `.orden-chip`** en vez de inventar un chip nuevo.

- **Las entradas se ARMAN en PHP, no se escriben a mano en el HTML.** En esa
  página «Reaperturas» es condicional, y un ancla a un `id` que la página no
  emitió es un enlace que no lleva a ninguna parte. Hay aserto: cada `href="#x"`
  debe tener su `id="x"` en el mismo documento.
- **No se imprime.** En papel el índice sería una lista de enlaces muertos.
- 🔴 **El destino necesita `scroll-margin-top`.** El topbar es `position: sticky`
  y mide `$topbar-height` (56 px): sin margen de desplazamiento, saltar a un
  ancla deja el rótulo justo debajo de la barra y parece que el enlace llevó al
  sitio equivocado. Vive en `.dash-grupo__titulo` (`pages/_dashboard.scss`), que
  es quien lleva el `id`. Solo afecta al salto por ancla, así que las vistas sin
  anclas no cambian.

⚠️ **`.orden-chip` tiene ahora dos consumidores** — `/matriculas` (sobre `<a>`,
ordena la tabla) y `/admin/cuadros` (sobre `<button>` en los filtros de riesgo, y
sobre `<a>` en este índice). Un `<button>` no hereda la fuente de la página y
trae `cursor: default`; el reset va en un selector `button.orden-chip` acotado a
propósito, para que los usos sobre `<a>` no lo vean. **No moverlo dentro de
`.orden-chip`**: allí un `font-family` reabre la puerta a que un shorthand `font`
pise el `font-size` del componente.

## El atributo `hidden` no siempre oculta (07/09/2026)

🔴 **`[hidden] { display: none }` vive en la hoja del NAVEGADOR con especificidad
(0,0,1).** Cualquier `display: flex` / `inline-flex` / `grid` escrito sobre una
clase es (0,1,0) **y le gana**. El elemento lleva el atributo, el JS lo pone y lo
quita correctamente, y se sigue viendo. No hay error en ninguna parte.

Se pagó dos veces el mismo día en la sección «Estudiantes en riesgo»:

- `.cuadros-riesgo__filtros` (`display: flex`) nace `hidden` desde el servidor
  justo para que, si el JS no carga, no queden en pantalla un buscador que no
  busca y unos chips que no filtran. **La defensa entera era inerte**, y el fallo
  solo se habría visto el día que fallara el JS.
- `.orden-chip` (`inline-flex`): el JS ocultaba los chips de los grados de otro
  nivel y se seguían viendo los once.

**Regla: si un elemento se oculta con `hidden` y su clase declara un `display`,
hay que escribir la variante `[hidden]` explícita.** Un `<tr>` o un `<div>` sin
`display` propio funcionan solos; el problema aparece justo en los componentes.

**Y necesita aserto sobre el CSS SERVIDO**, no sobre el marcado: desde PHP el
HTML se ve perfecto. Está en `verif_direccion_superficies.php`, con el mismo
método que `verif_banners_aviso.php`.

## Responsive: lo que se midió antes de tocar nada (07/09/2026)

Auditoría de `/admin/cuadros` en pantallas pequeñas. **El método importa**: se midió con un
**iframe de ancho fijo** dentro de la propia página, porque un iframe dispara las media
queries según su propio ancho. `resize_window` de la extensión de Chrome **reporta éxito y
no cambia el viewport** en este entorno — con él se mide el escritorio creyendo medir un
móvil.

### Dos diagnósticos que la medición desmintió

- 🔴 **«La página desborda en tablet»: NO.** Lo que parecía scroll horizontal a 748 px era
  **1 px de redondeo** (749 vs 748) con **0 elementos** realmente fuera. Antes de perseguir
  un desborde, comprobar si algún elemento se sale de verdad.
- 🔴 **«Los gráficos no se readaptan al rotar»: SÍ se readaptan.** Frappe Charts 1.6.2
  registra `resize` y `orientationchange` por instancia, y funcionan (medido: el SVG pasa
  de 289 a 783 px). Lo que **no** actúa es su `ResizeObserver` interno — coherente con el
  `NotFoundError: removeChild` ya documentado—, así que un cambio de *contenedor* sin
  cambio de *ventana* no redibuja. **No añadir un listener de `resize` propio**: duplicaría
  el trabajo que la librería ya hace bien.

### Lo que sí fallaba, y cómo se arregló

| Defecto | Antes (371 px) | Después |
|---|---|---|
| Tabla de riesgo | suelo de 950 px → 2,8× de scroll | 700 px → 2,2× |
| Barra de filtros | 333 px de alto (44 % de pantalla) | 194 px |
| Chips de nivel y grado | se apilaban en varias filas | scroll lateral |
| `.form-inline` | **usada en el marcado sin existir en el SASS** | definida |

**Ninguna columna se oculta y no hay layout de tarjeta** (decisión del usuario; las tarjetas
ya se habían descartado el 25/08/2026 para la grilla de notas). Lo que se reduce es el aire:
relleno y tamaño de letra.

**Los chips copian el bloque de `.tabs`** (`components/_tabs.scss`), que ya resolvía esto:
`nowrap` + `overflow-x: auto` + `scrollbar-width: none`. Tercer consumidor del mismo patrón
junto con `.cuadros-indice`.

### Señal de que una tabla continúa (`.tabla-notas-wrapper`)

Sombra en los bordes que aparece y desaparece sola, sin JS: dos capas de gradiente `local`
(se desplazan con el contenido) tapando a dos `scroll` (fijas al marco).

⚠️ **Solo funciona si las celdas del borde NO tienen `background` propio**: el gradiente se
pinta en el wrapper y cualquier fondo de celda lo tapa. Hoy se ve porque solo el `thead` y
la columna sticky tienen fondo. Si algún día se le da fondo a las últimas columnas, esta
señal desaparece **en silencio**.

### Deuda anotada: los breakpoints no están unificados

**640 px es el breakpoint del sistema** (48 usos; el único presente en navbar, tabs, tables
y `.app-main`). Pero el repo tiene además 760, 761, 900, 820, 600, 560, 540, 480, 400 y 360
escritos a mano, casi todos de **un solo uso**. `_cuadros.scss` usa tres (640, 760 y 761) y
es el único archivo con `761px`.

**No se unificaron a propósito**: los de 760/761 gobiernan la rejilla de gráficos y el panel
de literales, funcionan, y migrarlos a 640 metería dos columnas de gráfico en tablet. Sería
un cambio de maquetación disfrazado de limpieza. No hay variables SASS de breakpoint; si
algún día se crean, este es el inventario.

### Un aserto de CSS compilado no puede asumir el orden de las declaraciones

⚠️ **Autoprefixer reordena y añade propiedades.** Un aserto que buscaba
`.cuadros-riesgo__chips{flex-wrap:nowrap` **falló con la regla correcta ya compilada**,
porque gulp emite `{-ms-flex-wrap:nowrap;flex-wrap:nowrap;…}`. Estos asertos van con
**regex sobre el cuerpo de la regla** (`\{[^}]*propiedad`), nunca con la declaración
literal pegada a la llave.

## Un documento imprimible que reutiliza una tabla de pantalla (08/09/2026)

Auditoría del A4 de `/admin/cuadros/imprimir`. **Síntoma reportado:** «encabezados
desbordados en las tablas, tamaño de letra en los encabezados y títulos de las tablas».
Resultaron ser **dos defectos independientes** que se manifestaban juntos.

### 🔴 La regla general: en un imprimible, el `th` necesita `font-size: inherit`

`.cuadros-print` fijaba el tamaño en el `<table>`, pero `components/_tables.scss` declara
`font-size`, `color`, `font-weight` y `white-space` **directamente sobre**
`.tabla-notas th` y `.tabla-resumen th`, con especificidad (0,1,1). **Un elemento con
declaración propia no hereda la de su tabla**: las seis tablas del informe imprimían el
cuerpo a 8-10 px y **la cabecera a 12 px**, en gris `#475569` y con `white-space: nowrap`.

⚠️ **Es la segunda vez que aparece esta misma fuga.** El 07/09/2026 se corrigió para UNA
celda (`.cuadros-top--riesgo tbody th.col-nombre`, que imprimía a 14 px sobre una fila de
8 px) y **no se generalizó al `thead`**. La corrección de ahora es una sola regla
`.cuadros-print thead th { font-size: inherit; … }` —especificidad (0,1,2): la mínima que
gana a la fuga y no le gana a los overrides del propio bloque, que son (0,2,1) y (0,2,2)—.

**`/admin/cuadros/imprimir` es la única de las 14 vistas del layout `print` que reutiliza
los componentes de tabla de pantalla.** Las otras trece definen su tabla desde cero y por
eso nunca sufrieron esto. Compartir partials entre pantalla y papel es correcto —los datos
no divergen— pero **obliga a un bloque de reseteo explícito**, y ese reseteo se había ido
escribiendo a trocitos: `min-width` de `.col-nombre`, el chip naranja de código, el
denominador de la matriz a 9 px dentro de celdas de 8 px, los grises `#94a3b8` a 8 px.

### El desborde no lo causaba la tipografía: la hoja se aplastaba

La estimación con métricas Arial daba **432 px** de ancho mínimo para la fila de cabecera
de la tabla de 9 columnas; **medido en el navegador son 600 px**, y eso cambia el alcance:
el desborde empezaba por debajo de **~677 px de viewport**, no ~509. **Afectaba también a
tablets en vertical.** Lo que sí se confirmó es que **a 718 px no desbordaba nada**: la causa
no era la tipografía sino que `.cuadros-print` solo tenía `max-width` y se comprimía.

⚠️ **Una aritmética de anchos con métricas de fuente es una estimación, no una medición.**
Se quedó 168 px corta (432 contra 600) porque no cuenta lo que aportan los `td`, el
reparto del algoritmo de tabla ni el redondeo. Sirve para decidir *si hay un problema*,
nunca para fijar el umbral en el que aparece.

🔴 **`print-fit.js` no podía arreglarlo, y conviene entender por qué antes de tocarlo:**
reescribe el `<meta viewport>`, que es un **mecanismo exclusivamente móvil**. Chrome,
Firefox y Safari de escritorio lo ignoran. Cambiar su `screen.width` por `innerWidth` no
habría arreglado la ventana estrecha **y habría roto el móvil en horizontal**. La solución
es CSS: `min-width` igual al `max-width`.

### El efecto que no se veía venir: el PDF de los móviles

Con la hoja fluida, **los 11 gráficos salían impresos al 40 % del ancho del papel desde un
móvil** (medido: 278 px de 702). `layouts/print.php` carga `cuadros.js` dentro del `$content`, **antes** que
`print-fit.js`, y su `barrer()` es síncrono: Frappe medía el contenedor (311 px en un móvil
de 371) y le escribía ese `width` al SVG antes de que el viewport se ajustara. **Un ancho
fijo elimina la dependencia de orden entre los dos scripts sin tocar ninguno de los dos.**

Aserto que lo vigila: `min-width` y `max-width` de la hoja deben ser **el mismo número**.
Si divergen, vuelve el defecto en silencio.

### El visor del layout `print` (raíz compartida de los 14 imprimibles)

Dos correcciones en `_boleta.scss` + `print-fit.js`, del mismo día y por la misma causa:

1. **`body.boleta-body` gana `min-width: 210mm`** junto a su `max-width`. Con solo el techo,
   en una ventana estrecha el marco de la hoja simulada dibujaba 340 px mientras el
   contenido se salía por la derecha: **la vista previa mentía sobre dónde cae el corte de
   página**, justo lo que su propio comentario pide evitar. En móvil no cambia nada
   (`anchoNatural()` ya medía ~794 px para todos). ⚠️ Efecto de borde aceptado: con la hoja
   más ancha que la ventana, el `auto` del margen se resuelve a 0 y la hoja se pega a la
   izquierda — comportamiento estándar de un bloque *over-constrained* en LTR.
2. **Los botones `✕ / 🖨` compensan la escala del visor.** Cuando `print-fit.js` encoge la
   hoja en un móvil, el navegador dibuja toda la página al ~47 % y los botones quedaban en
   **~16 px físicos**, por debajo del mínimo de 24 px de WCAG 2.5.8 (AA).

   🔴 **No se podía arreglar con una media query**, y esto es lo que hay que recordar: en
   cuanto `print-fit` reescribe el `<meta viewport>` a 794, **el viewport CSS *es* 794** y un
   `max-width: 640px` no dispara en el móvil. `(pointer: coarse)` tampoco sirve: el problema
   no es el puntero, es el factor de zoom, que **ninguna media query expone**. Así que la
   escala la publica quien la conoce —`print-fit.js`, en una custom property— y el CSS la
   invierte con `transform`. El documento no se toca: los controles no son el documento.

   ⚠️ **La propiedad se publica en una rama y se limpia en la otra, y las dos hacen falta.**
   Si se calculara siempre, en escritorio `screen.width` vale 1920 contra una hoja de 794 y
   los botones saldrían **encogidos** a 0,41. Si no se limpiara al volver a caber, un
   teléfono que rota se quedaría con los botones agrandados.

⚠️ **Al escribir asertos sobre `transform`, `flex` o cualquier propiedad prefijada, el
mutante tiene que borrar LAS DOS declaraciones.** Un mutante que solo quitaba `transform:`
dejó vivo el `-webkit-transform:` y el aserto pasó — el aserto era correcto, el mutante no.

### Línea base medida (II Bimestre, datos reales, iframe a 371 px)

Método: la hoja real volcada con el **mismo controlador** (`componerBloques` por reflexión,
saltándose el constructor que exige sesión) y servida con `php -S`, con el `app.css` de
`066c2e3` al lado para el A/B. 11 gráficos, 122 tablas, 0 avisos.

| a 371 px | antes | después |
|---|---|---|
| ancho de la hoja | **294 px** (aplastada) | **718 px** |
| tablas que desbordan | **34 de 122** | **0 de 122** |
| desborde máximo | **314 px** | 0 |
| los 11 gráficos (SVG) | **278 px = 40 % del papel** | **702 px = 100 %** |
| `th` de las 6 tablas | **12 px** sobre cuerpos de 8-10 px | igual que su tabla |
| color del `th` | `#475569` | `#111` |
| botón `🖨` (móvil de 371) | **16,6 px físicos** | **35,6 px** |

Barrido de anchos, desborde de la tabla de calificaciones fuera de la hoja:
**371 px → 314 · 500 px → 185 · 768 px → 0 · 1024 px → 0**. Después: **0 en los cuatro**.

**En escritorio (1024 y 1280) no cambia nada**, que es el criterio de no-regresión del
papel: hoja 718, SVG 702, 0 desbordes antes y después. Lo único que se mueve es que el
documento sale **632 px más corto** (26 371 → 25 739), porque las cabeceras dejan de ser
un 50 % más grandes que su contenido.

⚠️ **La captura de pantalla de la extensión hace *timeout* en este entorno**, incluso con
una página ligera y una pestaña recién creada (`Page.captureScreenshot timed out`). No es
la página y no sirve reintentar: es distinto del «captura en blanco por encima de ~7 000 px»
ya documentado. **Las mediciones numéricas por `getBoundingClientRect` y `getComputedStyle`
sí funcionan**, y son las que prueban el cambio.


## Chip de PROCEDENCIA de una nota (10/09/2026)

> PUNTO ÚNICO: `PROCEDENCIAS_NOTA` en `app/Helpers/helpers.php`.
> Markup: `resources/views/shared/_procedencia-chip.php`.
> Estilos: `resources/sass/components/_procedencia.scss`.

Conviven **tres** mecanismos que producen notas fuera del registro ordinario del docente, y
hasta hoy solo uno llevaba marca, así que se confundían — sobre todo en la ficha de
matrícula, donde sus cards son vecinas:

| Categoría | Dónde vive | A dónde va la nota |
|---|---|---|
| **Calificación extraordinaria** | `calificaciones.extraordinaria = 1` | Boleta **y** SIAGIE. No al mérito. |
| **Notas del colegio de origen** | `notas_externas` | **Solo al docente.** Nunca a la boleta. |
| **Autorizada para SIAGIE** | `notas_autorizadas_siagie` | **Solo al acta.** Ni boleta ni mérito. |

⚠️ **La categoría es DERIVABLE, no se guarda.** Cada mecanismo vive en su propia tabla. Si
algún día alguien propone una columna `categoria`, es señal de que dos mecanismos se están
mezclando en una misma tabla.

### 🔴 EL COLOR NO DISTINGUE: distinguen el NOMBRE y el ICONO

Los tres chips comparten forma —el **borde punteado**, que ya significaba «esto no salió de
tu registro»— y **solo la extraordinaria conserva el ámbar**, porque es la única que llega a
la boleta y por tanto pide atención.

Es lo que exige el sistema de color de arriba: **rojo y ámbar son de ESTADO** y los cuatro
colores de concepto (azul cargas, teal tutoría, púrpura conducta, naranja nómina) **ya tienen
dueño**. Inventar dos tintes más habría chocado con él: el índigo que quedaba libre se
confunde con el púrpura de Conducta a tamaño de chip. **No añadir colores a esta familia.**

Los tres iconos (`edit-pen`, `social-city`, `doc-add`) son **distintos entre sí** y no
coinciden con ninguna card del dashboard — la misma regla del glifo fijo por concepto. Lo
comprueba `verif_notas_origen.php` §7d, que además verifica que los SVG existen.

### Dónde se pinta

`.extra-badge` **se conserva como alias** y su aspecto **no cambió ni un pixel**: era el
nombre que ya usaban `docente/calificaciones.php`, `docente/resumen-competencia.php` y
`consulta-notas/_tabla.php`, que ahora sacan el texto del punto único en vez de tenerlo a
mano. El chip se pinta además en la ficha de matrícula (con la línea de **destino**), en las
dos vistas de notas de origen y en la bandeja de notificaciones.

**El icono solo donde hay sitio** (cards y cabeceras); en las grillas densas el chip va con el
nombre corto a 10px, como el badge de siempre. La variable `$procIcono` lo decide.

## Páginas de error (403 / 404 / 500): sueltas, con su propio CSS (21/09/2026)

Son **documentos HTML completos** que se pintan con `require` directo, **sin layout**:
`BaseController::notFound()`, `BaseController::forbidden()`, `Router::notFound()` y
`render_error_page()`. Por eso **no cargan `app.css`**: enlazan `public/css/errores.css`, que
sale de una **entrada SASS propia**, `resources/sass/errores.scss` (el `gulpfile` compila
`app.scss` y `errores.scss`).

- 🔴 **Nunca `$this->view('shared/403')`.** Así estaba `requireRole`: la página de error
  quedaba **anidada dentro de `layouts/app.php`** (dos `<!DOCTYPE>`, el menú lateral
  alrededor) y en móvil se veía rota. Mismo bug que ya tuvo el 404.
- Antes llevaban el CSS en un `<style>` dentro del PHP, sin media query. Ahora, a ≤480px,
  el gutter baja a 16px, el código a 64px y el botón ocupa el ancho.
- `errores.scss` **no importa `base/variables` a propósito**: es la página de emergencia y no
  debe romperse si cambia la paleta de la app.
- Medido en Chrome el 21/09: 403 real (sesión de docente en `/rectificaciones`) con un solo
  `<!DOCTYPE>` y sin sidebar; en iframe de 375px, código a 64px y sin scroll horizontal.

## QUÉ DICE UN AVISO — mecánica y política fuera (22/09/2026)

Regla de redacción para **todo banner, flash y toast** del sistema, nacida de un barrido
completo (47 banners en 29 vistas + los mensajes de acción de 34 controladores).

**Un aviso dice QUÉ PASÓ y QUÉ HACER.** Fuera de él:

1. **Mecánica interna.** Cuántas notificaciones creó el sistema, cuántas filas descartó por su
   cuenta, nombres de tablas, de columnas o de algoritmos («la cascada de desempate»), o que
   algo «se guarda en la auditoría». El lector no lo acciona y envejece mal: el aviso del lote
   de extraordinarias prometía que «el docente lo verá», y desde el 21/09 eso es **falso** para
   las reservadas a dirección.
2. **Política interna que su lector no puede accionar.** Al docente no se le explican las
   reglas de boleta ni de orden de mérito de las notas del colegio de origen: se le dice que
   son informativas y que no tiene que hacer nada con ellas.
3. **La segunda copia dentro de la MISMA pantalla.** Entre pantallas distintas sí puede
   repetirse: cada una se abre suelta.

**Sí se dice**, y no se recortó:
- El acuse de que la acción funcionó («12 notas registradas.»).
- Los conteos de **lo que el propio usuario escribió** («Se omitieron 4 filas sin nota»): una
  omisión silenciosa parece pérdida de datos.
- La información que esa audiencia necesita aunque sea una regla del colegio (la **media beca**
  del 1.º del grado, en las vistas de docentes y de familias).
- Que a un rol de solo lectura se le diga que no puede hacer algo y a quién acudir.

Anclado en `verif_banners_aviso.php`: los asertos comprueban que las frases retiradas **no han
vuelto**, no una redacción concreta.

## Secciones de CONTENIDO VARIABLE: «datos a la vista, acción aparte» (21/09/2026)

**Regla de UI/UX para toda sección nueva cuyo contenido depende de si hay datos** (pedida por
el usuario como criterio permanente). Nació en `/matriculas/{id}`.

1. **Con datos:** la sección es una **card visible y abierta**, con sus datos a la vista y sus
   **acciones en la cabecera** (`card__header card__header--between` dentro del `card__body`,
   como la card «Estudiante»; `flex-wrap` para que las acciones bajen de línea en móvil).
2. **Sin datos:** la card **no se pinta**. Lo que queda es su **botón de acción**, agrupado con
   los de otras secciones vacías en una card de registro. Nada de cards vacías con un
   empty-state que ocupan sitio para decir «no hay nada».
3. **Nunca datos detrás de un `<details>` cerrado.** Esconde lo que el usuario vino a ver y,
   además, un `<details>` cerrado **no se imprime** (ver «Un `<details>` cerrado NO IMPRIME SU
   CONTENIDO» más arriba).
4. Una sección que **siempre** tiene contenido (datos del estudiante, apoderados) no entra en
   la regla: se pinta siempre.

**Aplicación en `/matriculas/{id}`:** «Notas del colegio de origen» y «Notas autorizadas para
SIAGIE (dirección)» salieron de sus `<details>` a cards propias a todo el ancho
(`.mat-seccion-ancha`), solo con datos. «Registrar notas fuera del registro del docente» quedó
como lista de acciones (`.mat-llegada__lista`), una fila por sección sin datos, más los bimestres
con competencias sin nota. «Exoneraciones» se pinta solo si hay alguna. Traslado y retorno ya
cumplían la regla.

