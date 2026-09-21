# ESTADO vivo del proyecto

> Único lugar donde se registran pendientes, migraciones y planes con fecha.
> Actualizar aquí (no en CLAUDE.md). Última revisión: **21/09/2026**.
> **Versión desplegada: v1.0.1** (`config/app.php` + tag anotado `v1.0.1`).


## ✅ PRUEBAS EN NAVEGADOR DE LA EXTRAORDINARIA — LAS 6 PASADAS (21/09/2026)

Cerradas el 21/09/2026 en la **laptop** (`PROBOOK450`, migraciones hasta la `060`, ids del
checklist resueltos a nombre y contenido antes de empezar). Las 4 de lectura las condujo
Claude sobre una sesión de admin ya abierta; para las 2 restantes el usuario inició sesión
(docente ZAMBRANO y luego admin). **Queda pendiente el merge a `main`**, que espera a que
el usuario lo indique.

**Con sesión DOCENTE (ZAMBRANO EDINZON ALEX, DNI 77170080):**
- [x] `/docente/calificaciones/271`, competencia 46: **agregar un criterio sin notas** →
      «Ver resumen» se bloquea (`btn-ver-resumen--bloqueado`, `aria-disabled`,
      `tabindex=-1`, y `pointer-events:none` computado: `elementFromPoint` devuelve el
      padre, no el enlace). **Eliminarlo** → sin recargar desaparece el bloque y el botón
      se rehabilita (comprobado con una marca en `window` que sobrevive). Consola limpia.
      ⚠️ El aserto **«Próximo pendiente apunta a él» NO se pudo juzgar aquí** y se probó en
      carga 20 / competencia 56, que arranca sin banner: al agregar, el banner **nace
      apuntando al criterio nuevo**; al eliminar, **desaparece** sin recargar. El motivo
      está en el pendiente del banner y las omisiones, más abajo.
- [x] Docente **20** (carga 157), historial `/docente/calificaciones/157/historial/1`: sin
      columna «Calificación extraordinaria» (solo PARTICIPACIÓN · EVALUACIÓN · REV.
      ACTIVIDADES), fila `19 | ÑIQUEN PAJUELO … | — | — | — | 14 | A | —` y la tarjeta
      informativa debajo de la tabla. Verificado renderizando el **controlador real** con
      sesión de docente simulada, y confirmado por `/consulta-notas/1/carga/157`, que usa
      el mismo partial `consulta-notas/_tabla.php`.

**Con sesión ADMIN o RA:**
- [x] `/rectificaciones/editar?matricula=181&carga=157&competencia=9&periodo=1` → **sin**
      la casilla (0 menciones de «extraordinari» en la página). Con `matricula=690` →
      **con** ella y con su tarjeta de leyenda.
- [x] `/rectificaciones/matricula/198` (BELTRAN TARAZONA GABRIELA NIKOLE, **la única
      matrícula del sistema** con una competencia bloqueada en el III sin su nota, y 43
      huecos en bimestres cerrados): 4 tablas de I y II, y **«III Bimestre» aparece 0
      veces**. Lo impone el `per.estado = 'cerrado'` de `getCompetenciasInsertables`.
- [x] `/director/bloqueos?periodo_id=3`: desbloqueada la **única** del III (bloqueo `8972`,
      carga 20 / competencia 46 / ZAMBRANO, 0 extraordinarias) → flash de éxito, sin la
      alerta de la guarda, tab a «0/593». **Y sin daño colateral:** 0 cierres transversales
      anulados (la sección 13 no tenía ninguno vivo en el III) y 0 criterios
      desconfirmados. La fila se restauró **idéntica** por SQL —id, `bloqueado_por=13`,
      `origen='docente'`, `bloqueado_en='2026-09-18 10:41:11'`—, porque re-bloquear por el
      panel habría dejado id nuevo, admin como autor y fecha de hoy.
      La rama que rechaza no tiene caso real en el III (se probó por código con la 157/9 de B1).
- [x] `/consulta-notas/1/criterios` → 2342 filas de criterios y **0 ocurrencias de
      «extraordinari»** en todo el HTML, teniendo B1 **68 criterios extraordinarios vivos**.
      Los ordinarios de esos mismos pares sí se listan: el negativo no es vacío.

⚠️ **Dos trampas del entorno, ya pagadas, para quien repita estas pruebas:**
- **`confirm()` congela la extensión de Chrome.** En el borrado de criterios se neutralizó
  inyectando un `<script>` en el mundo de la página; el diálogo nativo, sin parche, **se
  auto-descarta como «cancelar» y la acción no ocurre EN SILENCIO**. En el panel de
  bloqueos el parche no estuvo disponible (el clasificador de modo automático deniega
  tocar el JS de la página), así que hubo que pulsar «Aceptar» a mano.
- **En la tarjeta transversal el clic sintético no llega al botón** aunque
  `elementFromPoint` devuelva el botón correcto. Se disparó el handler real con
  `b.click()` desde la página; lo único que no se ejercitó fue la entrega del clic.


## ⏭ PARA RETOMAR EN EL ESCRITORIO (16/09/2026, turno tarde en la laptop)

Sustituye al traspaso del 11/09. `dev` queda pusheado con el cierre de notificaciones,
**15 commits por delante de `main`** (v1.0.1). **El merge NO se hizo.**

### 1. Antes de abrir nada en el escritorio
1. `git pull` en `dev`. **No hace falta `gulp build`**: `app.css` y los JS compilados van
   en el commit.
2. ⚠️ **Aplicar en la BD LOCAL del escritorio `057` → `058` → `059` → `060`**, las que
   falten. Cada máquina tiene su copia, y la `060` es de hoy: **sin ella la bandeja y el
   comunicado revientan** (columna `comunicado_id` y tabla `comunicados`). Comprobar:
   `SHOW TABLES LIKE 'comunicados'`.
3. Correr `verif_notificaciones.php` (37), `verif_notas_origen.php` (32) y
   `verif_extraordinaria_lote.php`. Referencia de la batería completa en la laptop:
   **39 de 39 en verde desde el 17/09/2026.** El rojo que se daba por normal,
   `verif_estructura_boleta`, era del verificador: su esperado ignoraba el bloqueo, y sus
   verdes no discriminaban. Reescrito y probado con mutantes (ver
   `database/verificaciones/README.md`).
4. Los ids de las pruebas de abajo son **de la BD de la laptop**. Si la del escritorio
   difiere, buscar equivalentes antes de concluir que algo falla.

### 2. Checklist de navegador — lo YA PROBADO (16/09, laptop, con sesión)
Pasaron sin observaciones:
- **§0 preparación** · **§1 notificaciones completas**: comunicados con las 4 casillas y
  combinados, validaciones, historial con «leído por X de N», docente (marcar una, todas,
  «Ver detalle» queda leída), **director con sesión** (campana, marcar leída, 403 al
  redactar y al historial), refresco de avisos repetidos y móvil.
- **§2 notas del colegio de origen**: importador, guardas en cliente y servidor, filas sin
  nota omitidas, competencia compartida por dos áreas, vista del docente con sus dos ramas,
  y **no aparecen en la boleta**.
- **§3 extraordinaria en lote**: literal en vivo, conclusión por nivel, transversales,
  motivo, transacción, **sí aparecen en la boleta**, mérito B1 intacto.
- **§4 chips de procedencia**: los 3 chips en `/matriculas/693`, `/matriculas/693/notas-externas`,
  `/consulta-notas/2/carga/295`, y con la docente SAARA SOTELO (id 6) en `/notificaciones`,
  `/docente/notas-origen/693` y `/docente/calificaciones/295/historial/2`.

🔴 **Dos vistas NO probadas, a sabiendas:** la grilla del docente
(`/docente/calificaciones/{carga}`) y el resumen de competencia
(`/docente/calificaciones/{carga}/resumen/{comp}`). Solo muestran el bimestre ACTIVO y en
local **no hay ninguna extraordinaria en el III Bimestre** (están en I y II). Pintan el
chip con el mismo partial que `/consulta-notas`, que sí pasó: **riesgo bajo, pero no es
cero**. Probarlas exige crear una extraordinaria sobre una competencia bloqueada del III.

⚠️ La primera versión del checklist daba **nombres de archivo de vista** en vez de URLs
(`matriculas/show` → 404). Corregido arriba; si se reutiliza el checklist, usar URLs.

### 3. Lo que FALTA probar (en este orden)
**§5 — Dirección solo ve bimestres CERRADOS en `/admin/cuadros`** (en la laptop: I y II
cerrados = ids 1 y 2; III activo = id 3). Es la primera vez que `/admin/cuadros` se abre
con sesión de director.
- [ ] Director: el selector solo ofrece bimestres cerrados; el activo no aparece.
- [ ] Director: las series de evolución terminan en el último cerrado (sin caída final).
- [ ] Director: `/admin/cuadros/imprimir?periodo_id=3` → **404**; con `periodo_id=2` funciona.
- [ ] Director: `/admin/cuadros?periodo_id=3` no muestra el activo.
- [ ] Admin y RA: siguen viendo el III **en vivo**, como antes.
- [ ] Director: `/consulta-notas/3/seccion/{s}/asistencia` sigue en vivo (excepción que se conserva).

**§6 — 404 limpios** (con admin; una sola página, sin barra duplicada):
- [ ] `/matriculas/999999/notas-siagie/informe`
- [ ] `/matriculas/999999/retorno`
- [ ] `/matriculas/999999/trasladar`
- [ ] `/traslados/999999/imprimir`

**§7 — Regresión general**
- [ ] Login y dashboard con cada rol; la campana solo para docente, RA, admin y directores.
- [ ] Un docente registra y guarda una nota por el flujo normal.
- [ ] Boleta digital e imprimible de un alumno cualquiera, igual que en producción.
- [ ] Orden de mérito de un grado, igual que en producción.
- [ ] `/rectificaciones` de un alumno sin casos especiales.
- [ ] Consola del navegador sin errores en todo el recorrido.

### 4. Despliegue (solo con §5–§7 en verde)
1. **`057`, `058`, `059`, `060` y `061` en PRODUCCIÓN, a mano y ANTES del merge.** El
   auto-deploy publica código, no repara datos. Ensayadas dos veces seguidas en BD
   desechable (057–059 el 11/09, 060 el 16/09). La `061` (21/09) se aplicó dos veces
   seguidas en la BD local de la laptop. **Tras la `061`**, correr
   `php database/reparar_notas_externas_truncadas.php` (simula) y luego con
   `--confirmar`: completa las competencias que MariaDB recortó a 120 caracteres.
2. `chore(release): v1.0.2`, **preguntar antes del merge** `--no-ff` a `main`, tag anotado
   y push.
3. Al desplegar: cabeceras de `notificaciones.md` y `usuarios-direccion.md`, y registrar el
   deploy aquí.

### 5. Qué entró hoy (16/09) — cierre del módulo de notificaciones
Detalle en `docs/modulos/notificaciones.md`. **Migración `060`** (`comunicados` +
`notificaciones.comunicado_id`).
- **Defectos arreglados:** «Ver detalle» podía no marcar la leída (`keepalive`) · título
  sin validar en servidor · el select ofrecía secciones del año planificado, que daban
  «No hay destinatarios» · texto fijo «Registro Académico registró…» · avisos a docentes
  inactivos.
- **Decisiones del usuario:** dirección **recibe** comunicados y marca leídas solo SU
  bandeja (excepción acotada, en `usuarios-direccion.md`) · destino *Personal
  administrativo* · destinos **combinables**, deduplicados y sin el emisor · avisos
  repetidos se **refrescan** · **historial de enviados** para todos los emisores.
- **Verificador propio** `verif_notificaciones.php` (37); sus asertos salieron de
  `verif_notas_origen.php`.
- **Diferido sin decisión:** paginación o limpieza de la bandeja (hoy, las 100 más recientes).

⚠️ Al empezar la sesión había un cambio sin commit en `public/js/anio-academico.js`;
`gulp build` lo regeneró desde su fuente (sin cambios) y quedó igual a `HEAD`. Si aquel
cambio se hizo a mano en el escritorio, **allí sigue**; en la laptop se perdió.

## 🆕 EXTRAORDINARIA SOLO EN CERRADOS + GUARDA DE DESBLOQUEO — EN `dev` (18/09/2026, tarde)

Dos observaciones del usuario. Sin migración. Detalle en `calificaciones.md` («Solo
bimestres cerrados, guarda de desbloqueo y fuera de las grillas»).
- La extraordinaria **solo** se registra en bimestres **cerrados** (antes bastaba con
  «bloqueada», y se colaba en el activo).
- **No se desbloquea** una competencia con extraordinarias (individual y transversal abortan;
  liberar los bloqueos del cierre las conserva). Motivo medido: al desbloquear,
  `calcularPromedio` mezclaba la nota de RA con las del docente sin aviso.
- El criterio extraordinario **ya no se pinta como criterio** en ninguna pantalla
  (`criterios_ordinarios()`); en la rectificación, solo al alumno que lo tiene.
- **Dato revertido en LOCAL:** la extraordinaria de prueba **1460** (431/271/46, III activo):
  criterio 6137 eliminado, fila de `calificaciones` borrada, auditoría 1460 conservada.
- Verificado: 15 comprobaciones propias (dos ramas por guarda), render de 4 pantallas con el
  controlador real y **batería completa en 39 de 39**.
- ✅ Pruebas en navegador: **las 6 pasadas el 21/09/2026**, al inicio de este archivo.
- 🔴 **Decisión bloqueante para la REGLA DEL PERIODO FINAL (tope 05/10):** su válvula era la
      extraordinaria ANTES del cierre, y ahora solo existe DESPUÉS. Aviso en la sección de esa
      regla en `calificaciones.md`.
- [ ] **No existe un flujo para REVERTIR una extraordinaria** (solo se corrige). La guarda de
      desbloqueo lo hace visible: hoy la única salida es a mano.
- [ ] Sin decidir: en la rectificación, el alumno CON extraordinaria sigue viendo también los
      criterios ordinarios vacíos; si RA los llena, mezcla. Preguntado al usuario.
- [ ] `.col-criterio--extraordinario` (`_rectificaciones.scss`) quedó sin uso.
- [ ] 🆕 **El banner «Próximo pendiente» IGNORA LAS OMISIONES** (hallado el 21/09/2026 al
      probar el criterio sin notas; **es ANTERIOR a la extraordinaria**, no una regresión).
      `getNotasExistentes` (`CalificacionController.php:1450`) solo lee
      `calificaciones_criterio`, y la vista compara ese conteo contra los alumnos no
      exonerados (`calificaciones.php:70`): una **falta justificada** registrada en
      `omisiones_criterio` no cuenta nunca. Un criterio con TODOS los alumnos resueltos
      —notas + omisiones— se queda como pendiente para siempre y el contador dice
      «24 de 25» cuando no falta nadie. Caso vivo: criterio **5514** (carga 271,
      competencia 46, III Bimestre), 24 notas + 1 omisión = 25 alumnos, confirmado desde
      el 18/09, y el banner apunta a él de forma permanente. **Por eso el aserto del banner
      no se pudo probar en la carga 271.** NO contamina «Ver resumen»: ese guard es
      `competenciaListaParaResumen`, que mira `confirmado_en` y no cuenta notas. Decidir si
      el conteo debe sumar las omisiones (y, si sí, revisar también el «X de Y» del criterio).

## 🆕 AJUSTES TRAS PROBAR EXTRAORDINARIA Y RECTIFICACIÓN — EN `dev` (18/09/2026)

Ocho observaciones de las pruebas en navegador (§A-§B del checklist del 17/09). Sin
migración. Detalle en `calificaciones.md` («Ajustes tras las pruebas…»), `orden-merito.md`
(rectificado) y `matriculas.md` (dos botones y orden de la currícula).

🔴 **Lo que parecía un fallo de boleta y de mérito era PÉRDIDA DE LO ESCRITO.** La
rectificación §B1 de la 181 quedó auditada **17→17** (fila 1461): el rechazo por falta de
conclusión repintó las notas de la BD y el reenvío guardó la vieja. Arreglado en las dos
capas. **Hay que REPETIR §B1** (bajar los 3 criterios de la 181 a 10), y la fila 1461 queda
como traza de la prueba fallida.

- [ ] **Probar en navegador:** §B1 de nuevo · rechazo del servidor que conserva lo escrito
      (editar y lote) · lote con conclusión opcional y el input nuevo · ficha
      `/matriculas/693` con los dos botones (y una matrícula `trasladado` sin la card) ·
      `/docente/notas-origen/693` · grilla del docente con extraordinaria en el bimestre
      activo (caso 431/271/46) · borrar un criterio vacío con los demás confirmados ·
      `/admin/control/1/orden-merito-rectificado`.
- **Probado en Chrome con sesión admin (18/09):** rechazo del servidor que conserva lo
      escrito (editar y lote), conclusión en vivo, input del lote, ficha con los TRES paneles
      (el tercero es la antigua card de notas autorizadas SIAGIE) y trasladado con solo el
      tercero, notas de origen en orden de la currícula, rectificado con resaltado. **NO
      probado:** guardar §B1, grilla del docente y eliminar criterio (piden sesión docente).
- ✅ **§B1 repetida en Chrome con sesión admin (18/09, tarde):** conclusión obligatoria en
      vivo en sus dos ramas (A → no, C → sí), rechazo del servidor que vuelve con 10/10/10 y el
      motivo, un solo flash, y guardado **17→10** (fila **1541**; el 6115 quedó vacío). Boleta
      B1 de la 181 con C y su conclusión; en el rectificado la 181 sale resaltada, 1.º → 4.º.
- ✅ **Grilla y resumen del docente (carga 271, comp 46) renderizados con el controlador
      REAL** y la sesión simulada del docente 13, sin avisos PHP: el criterio 6137 no se pinta,
      la card informativa sí (en `comp-46` y en el resumen), y «Próximo pendiente» apunta a un
      criterio ordinario (5514). Admin **no** puede abrir esa grilla (carga ajena).
- ➡️ Lo que queda (grilla en pantalla y borrar un criterio vacío) está en «PENDIENTE
      INMEDIATO» al inicio de este archivo.
- Verificación sin sesión (18/09): `php -l`, `gulp build`, 6 verificadores en verde
  (lote, origen, notificaciones, estructura de boleta, universo del mérito, roster) y 10
  comprobaciones de render con datos reales.

**Pendientes detectados, NO arreglados (fuera de alcance):**
- [x] ~~El criterio 6115 vacío y editable al rectificar a cualquier compañero~~ — resuelto el
      18/09 (tarde): `getDetalleCompetencia` solo lo ofrece a quien tiene nota en él.
- [ ] El alta **individual** (`rectificaciones/extraordinaria`) sigue perdiendo lo escrito
      tras un rechazo del servidor, como la rectificación antes del arreglo.
- [ ] Flash duplicado también en `dashboard/index.php`, `docente/inicio.php` y
      `admin/boletas-publicas/index.php`.

## 🔴 DATOS PERSONALES SERVIDOS DESDE `public/` — EN `dev` (17/09/2026)

Detalle y medición en `docs/infraestructura.md` § «Datos personales servidos desde
`public/`». En una línea: `public/matriculados.csv` (528 estudiantes con DNI, apoderado,
domicilio y celular) y `public/hash.php` (contraseña en texto plano) estaban versionados y
el `.htaccess` no negaba `.csv`. Salen del repo, entran al `.gitignore` y los dos
`.htaccess` los niegan.

**Pendiente del usuario (NO lo hace el deploy):**
- [ ] Comprobar en producción qué responden `/matriculados.csv` y `/hash.php`.
- [ ] **Borrarlos a mano del servidor**: el auto-deploy solo los retira al mergear a `main`.
- [ ] Repo de GitHub **era público** → pasarlo a privado; el historial conserva los archivos.
- [ ] Decidir si se reescribe el historial (y, si el colegio lo exige, el aviso por la
      Ley 29733 de protección de datos personales).

## 🆕 IMPORTAR LA CURRÍCULA + CATEGORÍAS DE PROCEDENCIA — EN `dev` (10/09/2026)

Segundo bloque del mismo día, sobre el módulo de notas del colegio de origen. **Añade la
migración `059`**, que se suma a la cola de la `057` y la `058`: **ninguna de las tres está
en producción**.

### Importar la currícula

Transcribir a mano el informe del colegio anterior eran **27-29 competencias POR BIMESTRE**.
Ahora se traen las del plan de su sección, eligiendo bimestres y áreas. Se apoya en
`estructuraCompetenciasSeccion()`, que ya existía: no se escribió consulta nueva.

- **Sin subáreas** (son organización interna del COCIAP) y con el **`nombre_completo`** de la
  competencia, que es la redacción oficial del MINEDU.
- **Importar no escribe nada**: pre-rellena las filas del formulario de siempre, que siguen
  editables. **El camino manual para una currícula extranjera queda intacto.**
- `areasDeLaSeccion()` pasa a **derivar** de `curriculaParaImportar()`: si el select ofreciera
  otras áreas que el importador, el mapeo se perdería al guardar.

### 🔴 Dos cosas que había que arreglar sí o sí

**1. La UNIQUE perdía filas en silencio (migración `059`).** El COCIAP evalúa la misma
competencia del MINEDU en dos cursos —«Resuelve problemas de cantidad» en Matemática y en
Taller de Razonamiento Matemático—, así que con
`(matricula_id, periodo_nombre, competencia_nombre)` y `ON DUPLICATE KEY UPDATE` importar 29
guardaba 27. Afectaba a **4 de los 6 estudiantes reales**. ⚠️ **Ya pasaba al teclear a mano**:
el importador solo lo volvía masivo. Con `area_nombre` en la clave: **0 colisiones**. La tabla
estaba vacía en los dos entornos.

**2. El guardado rechazaba las filas sin nota.** Se omitía solo la fila con los CUATRO campos
vacíos, así que una fila importada (periodo, área y competencia llenos, nota vacía) hacía
**fallar el guardado en la fila 21 de 58** — el caso normal, porque el informe de origen no
trae todas las competencias. Ahora **sin nota se omite**, y las omitidas **se cuentan en el
mensaje** para que no sea silencioso.

### Categorías de procedencia

Los tres mecanismos que producen notas fuera del registro del docente —extraordinaria,
colegio de origen y autorizadas para SIAGIE— llevan **chip de categoría** desde un punto
único (`PROCEDENCIAS_NOTA` en `helpers.php`), en **7 vistas**.

- **La categoría es DERIVABLE**: cada mecanismo vive en su propia tabla. Sin migración y sin
  tocar las extraordinarias existentes.
- 🔴 **El color NO distingue: distinguen el nombre y el icono.** Los tres comparten el borde
  punteado y **solo la extraordinaria conserva el ámbar**, la única que llega a la boleta. Es
  lo que exige el sistema de wayfinding (rojo/ámbar son de estado; los 4 colores de concepto
  ya tienen dueño). **No añadir colores a esta familia.**
- `.extra-badge` **se conserva como alias y su aspecto no cambió**: las 3 vistas que ya lo
  usaban ahora sacan el texto del punto único. Su definición duplicada en
  `_rectificaciones.scss` se retiró.

### Verificación

`verif_notas_origen.php` sube a **33 comprobaciones**, con dos bloques nuevos: el **7c**
prueba la `059` (importar N competencias guarda N filas y la competencia compartida por dos
áreas conserva las dos) y el **7d** el punto único de categorías (iconos distintos, SVG
existentes, un solo ámbar). Los otros dos verificadores siguen en verde.

Render con datos reales: sin importar salen **6 filas**; importando 2 bimestres de la 694,
**58 filas con su área ya preseleccionada** más 3 en blanco.

🔴 **Sin verificar (necesita sesión):** el envío real del formulario GET de importación, el
JS de marcar/desmarcar áreas, y cómo se lee el chip en la grilla del docente.

### Correcciones de la revisión previa al despliegue (11/09/2026)

Salieron de revisar el propio diff antes de commitear. Ninguna cambia el comportamiento
esperado del módulo; las tres primeras están medidas.

1. **La currícula se leía dos veces por carga de pantalla** (la card y `areasDeLaSeccion()`,
   que deriva de ella). Memorizada por matrícula en `NotaExternaModel`. Medido con
   `SHOW SESSION STATUS LIKE 'Questions'`: 2 consultas → **0** en la segunda lectura, y otra
   matrícula sigue leyendo las suyas.
2. **Dos consultas SQL idénticas de bimestres**, escritas a mano en el controlador, pasan a
   **una** llamada a `AnioAcademicoModel::getPeriodos()`, que ya existía. Quedan otras dos
   inline en esa clase, **preexistentes** (notas autorizadas SIAGIE), sin tocar.
3. **Importar sin marcar bimestre o sin áreas ya no es un silencio**: guarda en servidor
   (flash `warning` + redirect) **y** en cliente. Las dos ramas probadas con
   `render_guarda.php`; la rama que deja pasar sigue rindiendo sus filas.
4. Limpiezas del propio diff: clave muerta `area_tipo`, el `unset` del partial
   `_procedencia-chip.php` antes del `return` temprano, y el salto de línea final del JS.

🔴 **Y un defecto PREEXISTENTE que la revisión destapó, ya arreglado**: cuatro sitios
llamaban `$this->view('shared/404')` —lo que CLAUDE.md prohíbe por su nombre— en
`MatriculaController`, `RetornoGradoController` y `TrasladoController` (dos). `shared/404.php`
es una página HTML completa, así que el layout la anidaba **dentro de otra página**. Ahora
usan `notFound()`, que es el punto único. **Quedan 0 usos** de la forma prohibida en `app/`.
Va en commit aparte porque no nació en este trabajo.

## 🆕 REGISTRO DE CALIFICACIONES DE BIMESTRES CERRADOS — EN `dev` (10/09/2026)

**Lleva DOS migraciones (`057` y `058`), aplicadas SOLO EN LOCAL.** Hay que aplicarlas a
mano en producción **ANTES** del merge a `main`, como se hizo con la `044` y la `045`.
Nada de esto está desplegado.

### La decisión que reordenó todo

El colegio precisó la regla y **partió en DOS lo que se trataba como una sola cosa**, con
destinos **opuestos**:

| | Notas del **colegio de origen** | Notas **nuestras** no registradas |
|---|---|---|
| Caso | trasladado que llega de otra IE | matriculado tarde (deudor con prórroga, matrícula provisional) |
| ¿Boleta y SIAGIE? | **NO** — el Informe de Progreso lleva solo lo cursado aquí | **SÍ**, como cualquier nota |
| Quién las ve | solo el **docente** con carga en su sección | familia, boleta, acta |
| Escala | **literal** puro | **numérica** 00-20, literal derivado |
| Dónde vive | `notas_externas` (+ `area_id`, migración `057`) | `calificaciones`, vía calificación **extraordinaria** |

🔴 **Eso DEROGA el plan del 05/08** (`docs/modulos/registro-retroactivo-notas.md`), que
mandaba las dos cosas a la boleta con una nota al pie. Caen sus decisiones **D2, D4, D6 y
D7** y se cierra sola su pregunta abierta del SIAGIE. **`calificaciones_retroactivas` no
se construye**, y con ella **la migración `049` queda LIBERADA**: hueco permanente, no
reutilizar el número. Su cabecera ya lo dice; su análisis se conserva porque sigue siendo
válido y caro de rehacer. La **F1** de aquel plan (asistencia en guion, en producción desde
el 07/08) es independiente y no se tocó.

### Lo que se descubrió midiendo, y que cambió el diseño

- **La mitad del pedido ya estaba en producción.** La calificación extraordinaria
  (migración `042`, desde el 16/07) ya hacía numérico→literal, conclusión obligatoria por
  nivel, motivo, auditoría y exclusión del mérito. **Le faltaba captura en lote, no motor.**
- **Las 275 extraordinarias son UN solo evento administrativo** (Ética B1, migración `050`):
  un motivo idéntico, una competencia, un periodo. No es uso orgánico de la función.
- **`notas_externas` no era un mecanismo muerto.** Que la boleta no la lea —lo que el plan
  del 05/08 usó para justificar su borrado— resulta ser **el comportamiento pedido**.
- **Ningún flag detecta el caso.** `matriculas.tipo`: de los 6 casos reales **3 son `nuevo`
  y 3 `continuador`**. `tipo_matricula='traslado_entrada'`: **173 filas y las 173 con B1
  completo**. Por eso **lo elige RA al registrar**, con dos entradas explícitas.
- **La card de notas de origen exigía `tipo === 'nuevo'`** y por eso no existía para la
  mitad de los casos reales (en todo el año hay **5** matrículas `nuevo`). Candado retirado.

### Qué entró

- **Extraordinaria EN LOTE** (sin migración): grilla del bimestre completo, literal en vivo,
  conclusión que se revela sola, motivo común, todo en UNA transacción.
  **PUNTO ÚNICO nuevo: `RectificacionController::escribirExtraordinaria`** — extraído del
  alta individual, ahora las dos vías escriben por él.
- **Notas del colegio de origen**: migración `057` (`area_id` NULL = mapeo opcional para el
  resaltado), `NotaExternaModel` como punto único, captura en lote, card sin candado de
  `tipo`, y vista de solo lectura del docente con guarda de carga activa.
- **Módulo de notificaciones**: migración `058`, campana con contador en la barra, bandeja
  con leída/no leída, y comunicados de admin/RA. **`alertas` NO se tocó** (va tutor→padre,
  0 filas, otro público).
- **Las COMPETENCIAS TRANSVERSALES entran en el lote (añadido el 10/09 tras probarlo él en
  el navegador y preguntar dónde se registraban).** Eran **12 celdas vacías** — 2 por nivel
  × 6 estudiantes — mientras 23 compañeros de la 690 sí las tenían.
  - 🔴 **La razón de su exclusión era FALSA en bimestres cerrados.** Decía «una fila cruda
    no llega a boleta»; medido con escritura + rollback, **sí llega**: la agregación del
    tutor solo exige bloqueo + cierre vigente, y en un bimestre cerrado ya se cumplen.
    La exclusión **sigue en pie para el alta individual** y el bimestre en curso.
  - **Carga dueña DERIVADA del dato** (la que ya evalúa esa transversal en la sección), no
    elegida a dedo; si nadie la trabajó, la competencia no se ofrece.
  - 🔴 **La conclusión de una transversal NO vive en `calificaciones`** sino en
    `conclusiones_transversales`. Por eso `escribirExtraordinaria` recibe
    `bool $esTransversal` y bifurca. Guardarla mal la dejaría registrada e INVISIBLE, y en
    primaria una transversal en B o C exige conclusión.

### Verificación

Dos scripts nuevos, ambos escriben y hacen **rollback** (no dejan filas):

- `verif_extraordinaria_lote.php` — 12 comprobaciones. Sobre la matrícula 690: +25 notas,
  las 25 dejan de ser insertables, **el ranking de B1 no mueve ni una posición** (está
  publicado y bajo el candado `046`), la boleta gana 25 celdas y ninguna previa cambia.
- `verif_notas_origen.php` — 15 comprobaciones. La central es **negativa**: registrar notas
  de origen **no altera ni una celda de boleta** ni el mérito. Incluye las dos ramas de la
  guarda del docente, el aislamiento entre bandejas y que dirección no emite comunicados.

Las 4 vistas nuevas se renderizaron con datos reales (23 comprobaciones más).

🔴 **Lo que NO se pudo verificar y hay que mirar con sesión abierta:** que el JS corra de
verdad (literal en vivo, conclusión que aparece, campana que se actualiza sin recargar), el
flujo completo con redirects y CSRF, y el aspecto de la campana en la barra.

## 🟢 RELEASE v1.0.1 — DESPLEGADA EN PRODUCCIÓN (08/09/2026)

Merge `dev` → `main` con **15 commits** (02/09 → 08/09). **Sin migraciones**: lo único que
entra en `database/` son verificadores, que son de solo lectura. El auto-deploy de código
basta — no hay dato que reparar a mano.

**La versión se marca en DOS sitios**, como en la v1.0.0: `config/app.php` (`'version'`) y
un **tag anotado** de git sobre el commit de merge en `main`. ⚠️ `config('version')` **no
lo lee nadie todavía**: hoy es documental.

### Qué se desplegó

- **Cuadros/A4** (este trabajo): el imprimible deja de heredar los estilos de pantalla de
  sus tablas y la hoja deja de aplastarse. Los 11 gráficos pasan de imprimirse al 40 % del
  papel a hacerlo al 100 % desde un móvil.
- **Layout `print` (raíz compartida, 14 documentos)**: la hoja A4 simulada no se encoge y
  los botones del documento vuelven a ser pulsables en móvil.
- **Cuadros/pantalla** (07/09): responsive de `/admin/cuadros` medido con iframe.
- **Estudiantes en riesgo** (04-07/09): sección nueva, desglose de competencias en C, banda
  de magnitud y el par mérito ↔ riesgo.
- **El tablero pasa al motor oficial del mérito** (`ce2502c`) — cambio de MODELO, no solo
  CSS: `OrdenMeritoModel::statsPorGrado` sustituye a un ranking paralelo con seis reglas de
  menos.
- **Matrículas** (02/09): `/matriculas/resumen` anclado entero en la matrícula oficial y el
  cuadro por grado deja de duplicar los retornos revertidos.

### Pendiente de la release

🔴 **Comprobar en producción lo que no pudo medirse en local**: vista previa de impresión
(Ctrl+P), el PDF generado desde un móvil frente al de escritorio, y —por el cambio en la
raíz compartida— una boleta imprimible, una constancia y el reporte de mérito en móvil.
## 🟡 DIRECCIÓN SOLO VE BIMESTRES CERRADOS (08/09/2026, 2.º del día)

En `dev`, **sin desplegar**. **Sin migración.** Detalle completo en
`docs/modulos/usuarios-direccion.md` § «Dirección solo ve bimestres CERRADOS».

El plan estaba aprobado desde esa misma mañana y se construyó el mismo día. Extiende al
**bimestre entero** la «regla del dato oficial»: un director podía abrir `/admin/cuadros`
sobre el bimestre **activo**, a medio llenar, y ver porcentajes, rankings y una línea de
tendencia con la misma pinta que los del bimestre cerrado. Y la serie de evolución lo
incluía: `$bimestresComparables` solo descarta un bimestre si **algún nivel tiene CERO**,
así que en cuanto los dos tienen algo el último punto cae en picado.

### Qué entró

- **`helpers.php`**: `solo_bimestres_cerrados()`, `periodos_cerrados()` y
  `ultimo_periodo_cerrado()`, junto a `ROLES_DIRECCION`. 🔴 **Primer helper de punto único
  del archivo que lee la SESIÓN** — los seis anteriores emiten fragmentos SQL. Es una regla
  de **audiencia**, no de datos, y el docblock lo dice.
- **`Admin\CuadrosEstadisticosController`**: `periodosVisibles()`, `elegirPeriodo()` y
  `serieIds()`. `index()` cae al último cerrado **con banner**; `imprimir()` responde
  **404** (el A4 va firmado con el sello del Director EBR). Antes `?periodo_id=3` funcionaba
  en las dos entradas **sin validar nada**.
- **`_chart-data.php`**: corte de G2, G7 y G11 **por ids** (solo G2 expone `estado`), en el
  **eje de las series** y nunca en la fuente (G6 la comparte con G7 y desaparecería). Las
  tres notas de lectura llevan coletilla condicional.
- **`admin/cuadros/index.php`**: banner `.alert alert--info` y estado vacío explicativo.
- `admin` y `registro_academico` **no cambian**: `serieIds` es `null` para ellos y la lista
  sigue trayendo el activo. Esa es la mitad que se rompe en silencio, y tiene sus asertos.

### Correcciones al plan que dejó por escrito

- `verif_direccion_superficies.php:443` **no era un aserto** (es una clave del array que se
  renderiza); el que exige la celda del bimestre activo en conducta es **`:1062-1078`**.
- `getPeriodos()` **no lo comparte `/consulta-notas`**: esa pantalla tiene su propio SQL
  inline. Lo comparte `/admin/control`.
- Las copias de la comprobación «cerrado» son **16-17 en PHP con 7 redacciones**, no 5.

### Medido

**35 de 36 verificadores en verde**, y los 30 asertos del bloque nuevo también. El único
rojo es `verif_direccion_superficies.php` con 5 fallos **preexistentes**, reproducidos uno
a uno en una copia limpia de `HEAD` (`git archive`) contra la misma base.

### 🔴 Pendiente que este trabajo destapó (preexistente, sin arreglar)

**Con el bimestre a la vista sin calificaciones, G2 se calcula pero no tiene dónde
dibujarse.** `admin/cuadros/index.php:146` cambia la sección entera de calificaciones por
un estado vacío, y el `<div id="chart-evolucion">` vive dentro; pero `$chartData['evolucion']`
sí existe, porque sale de los bimestres anteriores. Arrastra su tabla de valores y su nota
de lectura, en pantalla y en papel. **Para Dirección desaparece con este cambio** (ya no ve
el bimestre abierto); para `admin` y RA sigue. Arreglarlo es decidir si la evolución ANUAL
debe vivir dentro de la sección del BIMESTRE. Detalle en `docs/modulos/usuarios-direccion.md`.

⚠️ **La migración 056 lo destapó, no lo causó**: sin ella el verificador abortaba antes del
render y estos 5 asertos no llegaban a correr nunca.

### Pendiente

- 🔴 **Sin probar en navegador**, y sería la primera vez que una superficie de Dirección se
  abre **con sesión de director** (id 35 `director_ebr`, id 41 `director_academico`).
- ⚠️ **La BD del escritorio no es la de la laptop**: III Bimestre con **0** calificaciones
  aquí frente a 222 allí, así que el «desplome falso» no es observable en este equipo; por
  eso el corte de las series se mide con fuente sintética.
- ✅ **Migración 056 aplicada en la BD local del escritorio** el 08/09/2026 (idempotente;
  10 criterios de conducta, 10 con código). No toca producción.

## 🟡 CUADROS — responsive del A4 imprimible (08/09/2026)

En `dev`, **sin desplegar**. Sin migración. Commits `53f0faa` (cuadros) y `3a1de82`
(raíz compartida del layout print). Detalle en `docs/modulos/ui.md` § «Un documento
imprimible que reutiliza una tabla de pantalla» y en `docs/modulos/usuarios-direccion.md`
§ «El imprimible A4».

**Decisión del usuario: «visor fiel del documento».** La hoja conserva sus 718 px siempre;
lo que se adapta es el visor. El PDF desde un móvil tiene que ser idéntico al de
escritorio. **No se reflowea el A4** — la lectura cómoda ya la da `/admin/cuadros`.

**Síntoma reportado:** «encabezados desbordados en las tablas, tamaño de letra en los
encabezados y títulos de las tablas». Eran **dos defectos independientes**:

1. **La cabecera no heredaba el tamaño de su tabla.** `_tables.scss` declara `font-size`
   sobre `.tabla-notas th` / `.tabla-resumen th` con (0,1,1); el A4 lo fijaba en el
   `<table>`. Las **seis** tablas imprimían el cuerpo a 8-10 px y la cabecera a 12 px.
   **Segunda aparición de la misma fuga**: el 07/09 se corrigió para una sola celda y no
   se generalizó al `thead`.
2. **El desborde lo causaba que la hoja se aplastaba**, no la tipografía. Medido: la fila
   de cabecera pide **600 px** contra 702 útiles — **a 718 px no desbordaba nada**; el
   desborde empezaba por debajo de ~677 px de viewport, así que **también afectaba a
   tablets**. (La estimación previa con métricas de fuente daba 432 px y ~509: se quedó
   corta. Una aritmética de anchos no sustituye a una medición.)

**Efecto que no se veía venir:** con la hoja fluida, **desde un móvil los 11 gráficos
salían impresos al 43 % del ancho del papel**, porque `cuadros.js` corre antes que
`print-fit.js` y Frappe medía el contenedor sin ajustar.

🔴 **`print-fit.js` no podía arreglar la ventana estrecha y no se tocó por eso**: el
`<meta viewport>` es un mecanismo **exclusivamente móvil**. Cambiar su `screen.width` por
`innerWidth` —que era mi propuesta inicial— no habría servido **y habría roto el móvil en
horizontal**.

### Verificado

- 36 verificadores en verde; `verif_direccion_superficies` pasa de 147 a **171 asertos**.
- 11 asertos nuevos de CSS servido + 1 de cobertura sobre el HTML renderizado (toda tabla
  del A4 debe llevar una clase que `.cuadros-print` dimensione: la red que faltaba).
- Dos vigilan la **causa**: que nadie vuelva a fijar la cabecera en px, y que `min-width`
  y `max-width` de la hoja sean el mismo número.
- **Probados con mutantes** (6, incluidas las dos ramas de la guarda de `print-fit.js`),
  con `app.css` y `print-fit.js` restaurados byte a byte por `md5`.

### Pendiente

- ✅ **MEDIDO EN NAVEGADOR** (A/B contra el `app.css` de `066c2e3`, hoja real del II
  Bimestre servida con `php -S`). A 371 px: hoja **294 → 718 px**, tablas que desbordan
  **34/122 → 0/122**, los 11 gráficos **278 px (40 % del papel) → 702 px (100 %)**, `th`
  **12 px → el de su tabla**. En escritorio (1024/1280) **nada cambia** y el documento sale
  632 px más corto. Detalle en `ui.md` § «Línea base medida».
- 🔴 **FALTA lo que exige un dispositivo real o sesión iniciada**: la **vista previa de
  impresión** (Ctrl+P) y **comparar el PDF generado desde un móvil con el de escritorio**,
  que es el criterio de aceptación de la decisión «visor fiel». El botón se midió con la
  escala simulada (16,6 → 35,6 px físicos), no en un teléfono.
- ⚠️ **La captura de pantalla de la extensión hace timeout en este entorno**; las
  mediciones numéricas sí funcionan.
- 🔴 **La raíz compartida toca los 14 imprimibles.** Hay que reabrir en móvil y en ventana
  estrecha: boleta imprimible, constancia de traslado y reporte de mérito. Todo el cambio
  va en `@media screen`, así que la vista previa de impresión debe salir **idéntica**.
- **NO implementado, detectado y reportado**: el panel de literales
  (`director/anios/_panel-bimestre.php`) entra al A4 **sin ninguna regla de papel** —
  título a 16 px en un documento cuyo `h2` es 13 px y cuyas tablas son de 8 px, donut de
  120 px, grises `#94a3b8`. Es la fuga de mayor superficie que queda. Se dejó fuera por
  alcance: cambia el aspecto de un bloque entero y merece revisión visual propia.
- Menor: `.cuadros-kpi__n` (1,1rem) y `.cuadros-banda__n` (1,4rem) son las dos únicas
  medidas del A4 en `rem`; se moverían si alguien tocara `html{font-size:14px}`.
- Menor: `body.boleta-body.doc-landscape` conserva solo `max-width: 297mm` (hoy **sin
  consumidor**, documentado); si algún día se usa, querrá su `min-width` igual que el
  vertical.


## 🟡 CUADROS — responsive de la pantalla (07/09/2026)

En `dev`, **sin desplegar**. Sin migración. Detalle en `docs/modulos/ui.md`
§ «Responsive: lo que se midió antes de tocar nada».

**Método**: iframe de ancho fijo dentro de la propia página (dispara las media queries de
verdad). ⚠️ `resize_window` de la extensión **reporta éxito y NO cambia el viewport** en
este entorno: con él se mide el escritorio creyendo medir un móvil.

**DOS DIAGNÓSTICOS MÍOS QUE LA MEDICIÓN DESMINTIÓ** (y por eso no se tocó nada de eso):
- «la página desborda en tablet» → era **1 px de redondeo** (749 vs 748), 0 elementos fuera;
- «los gráficos no se readaptan al rotar» → **sí lo hacen**: Frappe 1.6.2 registra `resize`
  y `orientationchange` y funcionan (SVG 289 → 783 px). Solo su `ResizeObserver` interno no
  actúa. **No se añadió listener propio**: duplicaría lo que la librería ya hace.

**Lo que sí fallaba** (medido a 371 px → después):
- tabla de riesgo: suelo 950 px / 2,8× de scroll → **700 px / 2,2×**;
- barra de filtros: **333 px de alto (44 % de pantalla) → 194 px**;
- chips de nivel y grado: se apilaban → **scroll lateral** (mismo bloque que `.tabs`);
- tipografía de escritorio en móvil (banda 2,4 rem, KPI 1,6 rem) → reducida;
- 🔴 **`.form-inline` se usaba SIN EXISTIR**: la clase estaba en el marcado del selector
  de bimestre desde que nació el tablero y **no había ni una regla** en todo el SASS.

**Ninguna columna se oculta y no hay tarjetas** (decisión del usuario; las tarjetas ya se
descartaron el 25/08 para la grilla de notas). Se reduce el aire, no la información.

**Señal de scroll** en `.tabla-notas-wrapper`: sombra por gradientes `local`+`scroll`, sin
JS. ⚠️ **Solo funciona si las celdas del borde no tienen `background`** —hoy se cumple—;
si un día se les da fondo, la señal desaparece en silencio.

**Breakpoints NO unificados, a propósito.** 640 px es el del sistema (48 usos), pero el repo
tiene 760/761/900/820/600/560/540/480/400/360 escritos a mano, casi todos de un solo uso.
Los de 760/761 de `_cuadros.scss` gobiernan la rejilla de gráficos y funcionan: migrarlos
sería un cambio de maquetación disfrazado de limpieza. Queda como deuda en `ui.md`.

**Verificación**: 36 verificadores en verde; `verif_direccion_superficies` gana 3 asertos
sobre el **CSS servido** (ninguna prueba de servidor ve una media query).
⚠️ **Un aserto de CSS compilado no puede asumir el orden de las declaraciones**: el primero
buscaba `.cuadros-riesgo__chips{flex-wrap:nowrap` y **falló con la regla correcta ya
compilada**, porque autoprefixer emite `{-ms-flex-wrap:nowrap;flex-wrap:nowrap;…}`. Van con
regex sobre el cuerpo de la regla.

## 🟡 CUADROS — desglose de las competencias en C (07/09/2026)

En `dev`, **sin desplegar**. Sin migración. Detalle en
`docs/modulos/usuarios-direccion.md` § «Desglose de las competencias en C».

Cada estudiante de la lista trae ahora **qué competencias tiene en C**: área, curso,
competencia, nota y **docente**. Plegado en pantalla, suelto en el A4. Decisión del
usuario: solo las C, en las dos superficies, con docente.

- Punto único: **`OrdenMeritoModel::detalleCompetenciasC()`**, UNA consulta con `IN` para
  todos los grados. `getStatsCierre` pasa de 26 ms a **40 ms** en B1.
- Replica los filtros de la NOTA del mérito, **no** los del ALUMNO (`ROSTER_MERITO` y el
  anclaje ya los resolvió el ranking; repetirlos daría descuadres falsos).
- El **docente** sale de `cal.carga_id`: seguro porque dentro de este universo hay **cero**
  competencias con dos cargas (fuera hay 1 072, y 1 052 notas en cargas inactivas).
- **Guard del descuadre**: el snapshot solo guarda agregados, así que en bimestre cerrado
  la fila está congelada y el desglose va en vivo. Si no cuadran, `detalle_c` queda en
  NULL y la vista lo dice, en vez de pintar dos cifras que se contradicen. Hoy cuadran
  **195 de 195**.

🔴 **El aserto estuvo CIEGO y quedo documentado.** La primera version trataba ese NULL
como legitimo y **no detecto ninguno de los dos mutantes** probados: el guard que protege
la pantalla enmascaraba los bugs que el verificador existe para cazar. El umbral es CERO a
proposito; si falla, distinguir rectificacion legitima de replica rota y **no relajarlo**.
⚠️ El mutante de extraordinarias **no es observable**: las 275 de B1 tienen todas nota > 10.

**Peso.** 778 filas en B1 (429 en B2), ~+9 hojas de A4. El HTML se fue a 1,5 MB y **el
62 % eran espacios de indentacion**: el bucle interno se emite SIN SANGRAR y el imprimible
queda en **816 KB** (desde 425 KB). No hay minificador de HTML en el pipeline.
⚠️ `.cuadros-top__bloque--riesgo` pasa a `page-break-inside: auto` en el A4: con el
desglose un grado ya no cabe en una hoja y el `avoid` dejaria media pagina en blanco.

**Verificacion**: 36 verificadores en verde; `verif_cuadros_merito_motor` gana el aserto
del desglose (probado con mutante, ver arriba) y `verif_direccion_superficies` otros tres
(el desglose esta en las dos superficies, se pliega solo en pantalla y **el A4 sigue sin un
solo `<details>`**, y las filas de desglose no cuentan como estudiantes).
⚠️ **SIN PROBAR EN NAVEGADOR**: la extension de Chrome se desconecto. Falta abrir
`/admin/cuadros?periodo_id=1`, desplegar, filtrar y revisar el A4.

## 🟡 CUADROS — «Estudiantes en riesgo» se ve y se puede recorrer (07/09/2026)

En `dev`, **sin desplegar**. Sin migración. Detalle en
`docs/modulos/usuarios-direccion.md` § «Rediseño de la sección».

La sección nació el 04/09 correcta y **inencontrable**: bloque 4 de 7, detrás de
dos tablas y dos gráficos, sin decir cuántos eran (había que sumar once
`<caption>` a mano) y sin forma de ubicar a una persona entre **118 filas en 10
grados** (B1; B2 son 77 en 8; B3, 0).

**Qué entra**
- **Banda de magnitud** con total, grados con casos, % del alumnado evaluado y
  caso más alto. En pantalla y en el A4 —es dato, no control—. Azul
  institucional `$brand-dark`; **el A4 la imprime PLANA** (con
  `print-color-adjust: exact`, sin esa contraparte cada informe salía con un
  rectángulo macizo de tinta).
- **Filtrado en cliente** (`resources/js/cuadros-riesgo.js`, nuevo): buscador +
  chips de nivel + chips de grado, en conjunción. Normalizador NFD reutilizado de
  `nomina.js` —«nunez» encuentra a «NÚÑEZ»—. Cuarto estado vacío para «tu filtro
  no encontró a nadie», contador `role="status"`.
- **Perfil AD/A/B/C por estudiante.** `num_a` se **deriva por resta** en
  `OrdenMeritoModel::statsPorGrado`: cero consultas, y sale igual por las dos
  rutas del ranking (comprobado en B2, que lee del snapshot).
- **Índice de anclas** de toda la página (`.cuadros-indice`, solo pantalla), con
  el conteo en el chip de riesgo. `.dash-grupo__titulo` gana `scroll-margin-top`
  porque el topbar es `sticky`.
- **Fin de la colisión de rótulos**: la columna «En riesgo» del bloque de
  Calificaciones pasa a **«Promedio en C»** (`index.php`, `imprimir.php` y
  `director/anios/_panel-bimestre.php`). En B2 decían **0** y **77** bajo el mismo
  nombre, a media pantalla de distancia, separadas solo por un pie.
- Punto único nuevo: **`riesgo_resumen()`** en `helpers.php` —lo llaman el índice,
  la banda y el verificador—.

**La regla de «nada de semáforos» NO se derogó**, se precisó: el peso visual es de
la SECCIÓN, las filas siguen neutras. Su motivo es **normativo** en el listado de
inasistencias y solo analógico en el de riesgo, aunque compartan clases.

**Tres fallos que solo aparecieron en navegador** (ninguna prueba de servidor los
ve, y quedan anotados en `docs/modulos/ui.md`):
1. 🔴 **`[hidden]` no ocultaba nada.** La regla del navegador es (0,0,1) y un
   `display:flex` de clase es (0,1,0): la barra de filtros tenía el atributo
   puesto y se veía igual —o sea, toda la defensa de «sin JS no hay controles
   muertos» era **inerte**—, y los chips de grado de otro nivel tampoco se
   ocultaban. Hacen falta `.cuadros-riesgo__filtros[hidden]` y
   `.orden-chip[hidden]` explícitos. Hay aserto sobre el CSS **servido**.
2. **Las columnas bailaban entre tablas**: cada una medía las suyas según el largo
   de sus nombres (medido: «Sección» en x=529 y en x=624). `table-layout: fixed`
   + anchos en **porcentaje** (en px cuadraban en pantalla y desbordaban el A4).
3. **El nombre se imprimía a 14 px con la fila a 8 px.** La celda pasó a
   `th scope="row"` (nueve columnas) y la regla que la disfraza de `td` (0,3,2) le
   gana a `.cuadros-print .cuadros-top` (0,2,0): cada fila se partía en dos.

**Verificación** · `verif_cuadros_merito_motor.php` gana el aserto del perfil
(`AD+A+B+C = competencias`, `A >= 0`), probado con mutante;
`verif_direccion_superficies.php` gana 9 asertos (banda en las dos superficies y
cuadrando con las filas, controles solo en pantalla, barra `hidden`, script fuera
del `if ($chartData)`, índice sin anclas rotas y sin imprimirse, «En riesgo» ya no
aparece, y las dos reglas `[hidden]` del CSS servido), probado con mutante.
Recorrido real en navegador sobre B1/B2/B3, pantalla y A4, con un banco
autocontenido: 10/10 tablas alineadas, 0 desbordes, foco de teclado visible, A4 en
700 px de 718 y sin ningún control impreso.
⚠️ **Falta abrirlo CON SESIÓN en `/admin/cuadros`**: el banco monta el partial real
con datos reales, pero no la página entera —ni el índice conviviendo con los
gráficos, ni `/director/periodos/2/stats`, que comparte `_panel-bimestre.php`—.

## 🟡 CUADROS — el informe A4 deja de depender del cursor (04/09/2026, 2.º del día)

En `dev`, **sin desplegar**. Sin migración. Detalle en
`docs/modulos/usuarios-direccion.md` § «El papel no tiene cursor».

Lo planteó el usuario mirando la vista: *«los valores que aparecen solo al pasar el
cursor… es importante que los reportes impresos tengan toda la información»*.

### Qué entró

1. 🔴 **El A4 imprimía 11 gráficos y solo UNO dejaba sus números legibles** (el `pie`
   del embudo, que escribe su leyenda dentro del SVG). Los otros diez salían mudos y
   **ninguno tenía al lado una tabla con esos valores**: Frappe deja las cifras solo
   en el tooltip, que no se imprime, no existe en móvil y no aparece para quien
   navega con teclado.
2. **Tabla de valores por gráfico**, partial único `_tabla-grafico.php`: plegada en
   `<details>` en pantalla, **suelta en el A4**. 🔴 Un `<details>` cerrado **no
   imprime su contenido** —el mismo motivo por el que el explorador de criterios
   tiene vista aparte—; hay aserto que lo vigila, porque es el fallo que saldría en
   blanco sin ningún error. La tabla va **fuera** de `.cuadros-print__chart`, que
   lleva `page-break-inside: avoid`.
3. **La unidad sale del JavaScript.** El sufijo (`% en logro`, ` faltas`…) estaba a
   mano en `cuadros.js`; que la tabla lo repitiera habría sido la enésima regla
   duplicada. Ahora viaja con el dato (`$chartData[...]['unidad']`) y el JS la lee.
   Lo mismo con las **notas de lectura**, que estaban en `index.php` y el papel no
   imprimía. ⚠️ `$chartData` **no cambia de forma**: la normalización de sus tres
   estructuras vive en `$chartTablas`, aparte, porque `cuadros.js` y el verificador
   validan la forma actual.
4. **Lo demás que el papel perdía**: los KPIs «Esperan al tutor» y «Esperan al
   auxiliar» (que llegaban al A4 **solo** por la leyenda del embudo, y ese gráfico
   no se registra si la suma es cero), «Estudiantes con conducta calificada», y el
   `X de Y no cumplen` del mapa de calor, que ahora se imprime bajo el porcentaje.

### Verificado

- `verif_direccion_superficies.php` gana 6 asertos: cada gráfico con su tabla, los
  valores **celda a celda contra el mismo JSON** que dibuja el gráfico, cero
  `<details>` en el A4, ninguna tabla dentro del bloque no partible, y los KPIs y
  notas del papel. **El primer intento de ese aserto de coherencia era inerte**
  —comprobaba que el número «apareciera en la página», y en 300 KB de HTML casi
  cualquier cifra aparece—; se rehízo asociando cada tabla a su gráfico por posición.
- **Probado con mutantes**: celda alterada, celda extra, fila perdida y `<details>`
  reintroducido hacen fallar los asertos, y los dos listados no se contaminan.
- Corregido de paso un fallo latente del propio verificador: `$nGraficos` se definía
  dentro del `if` del JSON, así que un bimestre sin datos habría dado «variable
  indefinida» justo en el escenario menos probado.
- Los 15 verificadores del módulo, en verde.
- **Coste medido**: 95 filas nuevas en 11 tablas ≈ **1,5 hojas más** de A4 (de ~295 KB
  a ~377 KB de HTML). Decisión del usuario: que crezca.

### Pendiente

- ✅ **La Parte 3 ya está probada en navegador** (04/09/2026), que era lo más frágil:
  al mover la unidad al dato se tocaron **los 11 gráficos** y ningún test de servidor
  prueba que sigan dibujándose. Se ejecutaron `frappe-charts.min.js` y `cuadros.js`
  **de verdad**, desde el mismo origen, contra el **JSON real de II Bimestre**
  (11 gráficos) montado sobre `/login` —toda vista exige sesión y no puedo escribir
  contraseñas—. Resultado: **11 de 11 dibujan** su SVG a ancho completo, y el tooltip
  lee la unidad **desde el dato** en las cuatro rutas distintas de Frappe: barra
  simple (`48 faltas`), apilada de 4 series (`63 estudiantes AD…`), línea
  (`83.3% en logro`) y **sin unidad → número desnudo** (`186 Sin justificar`),
  que es la rama que el `|| ''` tiene que cubrir.
- ✅ **La pantalla real y la hoja A4, revisadas con sesión iniciada** (04/09/2026,
  `?periodo_id=2`, el bimestre con los 11 gráficos):
  - El `<details>` abre y cierra, y al abrir muestra la tabla con **la unidad como
    subtítulo de columna** (`% en logro`) y la nota de lectura debajo.
  - El mapa de calor **no desborda** (tabla y contenedor miden lo mismo, el documento
    no tiene scroll horizontal): cada celda es el porcentaje en negrita con el
    `4/22` debajo, y `—` donde no hay caso.
  - «Estudiantes en riesgo»: una tarjeta por grado con su `(2 de 36)`, ordenada por
    número de C descendente.
  - El A4: **0 `<details>`**, 11 gráficos con sus 11 SVG dibujados, **0 tablas dentro
    de `.cuadros-print__chart`**, los KPIs de tutor/auxiliar y el texto de conducta
    calificada presentes, y **95 filas** repartidas en las 11 tablas — el mismo coste
    que se midió. En el gráfico de criterios se ve el efecto buscado: el eje solo dice
    `C9, C10, C1…` y la tabla de al lado trae el texto completo de cada norma.
- ⚠️ **Error preexistente en `/admin/cuadros`, NO de este cambio** (comprobado con
  A/B el 04/09/2026): `NotFoundError: removeChild` desde el `ResizeObserver` interno
  de `frappe-charts.min.js`, **dos veces en cada carga**. Se restauró el
  `public/js/cuadros.js` de `HEAD` y **salta idéntico**, así que ya estaba en `main`.
  No impide que ningún gráfico se dibuje. Queda anotado, sin arreglar: tocarlo es
  entrar en la librería vendorizada y no es el alcance de este trabajo.

## 🟡 CUADROS — «Estudiantes en riesgo» + el mérito del tablero pasa al motor oficial (04/09/2026)

En `dev`, **sin desplegar**. Sin migración. Detalle en
`docs/modulos/usuarios-direccion.md` y en `docs/modulos/orden-merito.md`.

Lo pidió el usuario como una sección nueva en `/admin/cuadros`. Al explorar apareció
debajo un defecto de fondo, y eligió corregirlo entero en vez de rodearlo.

### Qué entró

1. 🔴 **El bloque «Orden de mérito» del tablero no usaba el orden de mérito.** Salía de
   `AnioAcademicoModel::getRankingGrado`, un ranking paralelo con **seis reglas de
   menos**: sin exigir competencias bloqueadas, sin excluir extraordinarias ni áreas
   exoneradas, con la TOE entera en vez de solo Ética, y sin `ROSTER_MERITO`, anclaje de
   retorno ni cascada de desempate. Alimentaba **tres pantallas**: `/admin/cuadros`, su
   A4 y `/director/periodos/{id}/stats` (+ el modal de cierre).
   **Medido en B3 (abierto):** 1.º primaria anunciaba un 1.er puesto con **22**
   competidores donde el mérito tiene **0** (nada bloqueado aún), y el último puesto de
   1.º secundaria salía **12.50** contra los **15.00** reales. En B2 (cerrado) las dos
   fuentes coincidían en los 11 grados: **por eso el defecto no tenía síntoma** para
   quien mirara un bimestre ya cerrado.
2. **Punto único nuevo `OrdenMeritoModel::statsPorGrado`** (el modelo dueño), sobre
   `gradosConRanking` + `rankingGrado`, ambos snapshot-aware. **Cero consultas nuevas.**
   `getStatsCierre` queda como fachada con la misma firma —sus tres consumidores no se
   tocaron— y se **borran** sus dos privados, para que no quede la copia latente.
3. **Sección «Estudiantes en riesgo»**: todos los que acumulan **3 C o más**
   (`RIESGO_MIN_C`), por grado, sin tope, resaltando el número de C. Pantalla + A4, con
   partial compartido. `num_c` ya venía calculado: **no cuesta ninguna consulta**.
4. **Cambio visible, y es la corrección:** al principio de un bimestre los grados sin
   nada bloqueado desaparecen del bloque de mérito. El vacío de
   `director/anios/_grados.php` pasa de «aún no hay calificaciones» a «aún no hay
   competencias **bloqueadas**»: con notas puestas y sin bloquear, el texto viejo mandaba
   a buscar el problema al sitio equivocado.

### Verificado

- **Nuevo `verif_cuadros_merito_motor.php`** (solo lectura, corre en prod): contrasta la
  fachada contra `rankingGrado` grado a grado y periodo a periodo, y prohíbe que
  `AnioAcademicoModel` vuelva a exponer `promedio_general` u ordenar por promedio.
  ⚠️ Prohíbe el RANKING, no el promedio: `getResumenBimestre` sigue promediando por
  estudiante a propósito. **El primer aserto que escribí confundía las dos cosas y
  acusaba a esa métrica legítima** — se corrigió el aserto, no el código.
- `verif_direccion_superficies.php` gana 3 asertos de la sección nueva. De paso **se
  arregló uno viejo que iba a empezar a mentir**: contaba `cuadros-top__caption` sueltos
  por la página, y las tablas nuevas reutilizan esa clase. Ahora cuenta dentro de cada
  tabla. Probado con mutantes: quitar un `<caption>` o un `<thead>` de cualquiera de los
  dos listados lo hace fallar, y **ninguno contamina al otro**.
- Verdes tras el cambio: los 8 verificadores del mérito, los 3 de dirección,
  `verif_roster_evaluacion` y `verif_matricula_documento`.
- **Coste medido** de `getStatsCierre`: B1 26 ms · B2 21 ms · B3 30 ms. No hizo falta
  memoizar.
- Los tres estados vacíos de la sección, ejercitados (sin ranking / con ranking y sin
  casos / con casos).

### Pendiente

- ✅ **Visto en navegador el 04/09/2026**, con sesión iniciada y en `?periodo_id=2`:
  «Estudiantes en riesgo» sale con una tarjeta por grado, su `(2 de 36)` y las columnas
  Estudiante · Sección · Puesto · Promedio · **C** · Competencias, ordenada por número de
  C descendente. También está en el A4. Las cifras ya las contrastaba
  `verif_cuadros_merito_motor.php` contra `rankingGrado`, grado a grado.
- Nada pendiente de verificación. Falta **desplegar**.

## 🟡 TODA `/matriculas/resumen` SE ANCLA EN LA MATRÍCULA OFICIAL (02/09/2026, 3.º del día)

En `dev`, **sin desplegar**. Sin migración. Detalle en `docs/modulos/matriculas.md` y en
`docs/modulos/retorno-grado.md`.

Lo pidió el usuario mirando la vista: las estadísticas que listan secciones estaban
tomando la **matrícula operativa**. Cierra el desajuste que los dos bloques anteriores
de hoy dejaron abierto — de hecho lo **invirtieron y lo mudaron**, según decía la ironía
anotada abajo.

### Qué entró

1. 🔴 **Los 5 gráficos no tenían NINGUNA de las dos exclusiones de retorno.** Como la
   operativa nace `estado='aprobada'`, el estudiante en retorno **contaba dos veces** en
   los cinco y la fila fantasma caía en el **grado inferior**. Medido: 1.º Primaria daba
   **42** en vez de 41, 1°B Prim **20** en vez de 19, continuador **517** en vez de 516,
   y las cuatro series sumaban **521** mientras el chip `aprobadas` decía **520**.
2. **La página usaba TRES anclas distintas del mismo año**: los chips
   `roster_evaluacion()` (conserva la operativa → 1°B), los gráficos ninguna (las dos a la
   vez) y el cuadro DOCUMENTO (→ 2°B). Ahora las tres usan DOCUMENTO. **Ninguna cifra de
   los chips cambió** (523 · 23 · 23 · 520 · 3): cambia de qué sección se le cuenta.
3. **Punto único `matricula_documento()`** en `helpers.php`, gemelo de
   `roster_evaluacion()`. La línea estaba a mano en tres sitios y este cambio habría
   sumado cinco copias más; se migran las tres (`BoletaPublicaModel`, token público,
   `getCuadroMatricula`). Nace también `matriculas_vigentes()`, y **`roster_evaluacion()`
   pasa a componerse desde él con salida byte-idéntica**.

### Verificado

- **Nuevo `verif_matricula_documento.php`** (20 asertos). Los dos que muerden: el
  **anti-híbrido** y el de **comportamiento**, que simula un retorno `revertido` con
  transacción + ROLLBACK y exige que el helper excluya una matrícula mientras el híbrido
  escrito a mano no excluye ninguna.
- `verif_matriculas_resumen.php` sube de 36 a **44 asertos**: las cuatro series suman lo
  mismo y ese mismo es el chip `aprobadas`; el desglose de sexo cierra sección a sección;
  cada retorno entra por su oficial.
- **Regresión de boletas medida, no supuesta**: el lote, el hub de tokens y las secciones
  de los 4 bimestres dan huella MD5 idéntica antes y después de migrar
  `BoletaPublicaModel`. `verif_retorno_grado.php`, `verif_roster_evaluacion.php` y
  `verif_roster_asistencia.php` en verde sin tocarlos, y **la batería entera (34
  verificadores) en verde**.
- **Falta verlo con sesión en el navegador** — junto con el bloque de abajo.

## 🟡 EL CUADRO DE MATRÍCULA CUADRA (02/09/2026)

En `dev`, **sin desplegar**. Sin migración. La tabla final de `/matriculas/resumen`, que
además se imprime y va al comité. Detalle en `docs/modulos/matriculas.md`.

### Qué entró

1. **Columna `retirado`** — el enum tiene cuatro tipos desde la 045 y el cuadro sumaba
   tres: **Primaria 3.º daba 48 sobre 49**. Cierra el pendiente que quedó abierto esta
   misma mañana, junto con su gemelo (el pie «Por tipo de matrícula», que descartaba
   `retirado` en silencio).
2. 🔴 **La exclusión del retorno era un HÍBRIDO de los dos criterios** — copiaba la forma
   del criterio DOCUMENTO con el `WHERE estado = 'activo'` del de EVALUACIÓN. Con un
   retorno **`revertido`** no excluía ninguna de las dos matrículas: el estudiante contaba
   **dos veces**, y la fila fantasma caía en el **grado inferior** como
   `continuador`/`desactivado`, engordando justo la columna que se lee como morosidad.
   **Latente** (el único retorno real está `activo`), así que no lo había visto nadie.
   ⚠️ Ironía a recordar: el arreglo de los KPIs de esta mañana hizo que `getResumen()`
   deduplique los revertidos, así que el desajuste entre las dos mitades de la página
   **no se eliminó — se invirtió y se mudó al caso `revertido`**. Cerrado del todo por el
   bloque de arriba, que ancla la pantalla entera en la oficial.
3. **Las celdas del parcial se derivan de una sola lista.** Estaban escritas a mano en
   tres sitios y añadir una columna eran cinco puntos: olvidarse de uno no daba error,
   solo una tabla descuadrada. Es literalmente cómo `retirado` se quedó fuera.
4. **Género se queda con M y F** (decisión del usuario). Solo cambia que la nota al pie da
   la cifra —508 sin sexo registrado— en vez de advertirlo en abstracto.

### Verificado

- `verif_matriculas_resumen.php` sube de 20 a **34 asertos**; el cuadro no tenía ninguno.
  Las 11 filas cuadran por tipo y por estado, y el TOTAL GENERAL da 534 por ambos ejes.
- 🔴 **La rama `revertido` se simula con transacción y ROLLBACK** —no existe en la base—,
  y se comprueba con la consulta vieja escrita a mano que **el filtro anterior sí
  duplicaba** (535 en vez de 534). Un aserto que solo se ha visto pasar no prueba nada.
- **Cabe en papel**: 702 px de tabla sobre los 718 px útiles del A4 portrait. Sin tocar SASS.
- **Falta verlo con sesión**, y sobre todo **en papel de verdad**.

## 🟢 DIRECTORES SIN BOTONES + KPIs DE MATRÍCULA (02/09/2026)

**DESPLEGADO el 02/09/2026** (merge `11c5b79`). Sin migración. Salió de tres ajustes pedidos sobre
`director/cargas/seccion/{id}`, `/matriculas/resumen` y `admin/control`.

### Qué entró

1. 🔴 **Nueve controles que un director veía y que le devolvían 403.** El servidor
   estaba **completo y correcto** (los 30 métodos guardados): no había agujero de
   seguridad, solo la promesa de una acción que el sistema luego negaba. **Cinco
   de los nueve no eran específicos de dirección** — rompían igual para las dos
   secretarías o para `registro_academico`—, así que el gate correcto no siempre
   es `$puedeEscribir`: hay que mirar el `requireRole` del DESTINO.
2. **El bug 1 no estaba donde parecía.** `director/cargas/seccion.php` ya estaba
   correcta; el botón «Reemplazos» abre el HISTORIAL, que es lectura, y **el
   usuario decidió conservarlo** al saberlo. La fuga estaba un nivel más abajo:
   el «+ Nuevo reemplazo» que ese historial pintaba.
3. **Bloque 6 nuevo en `verif_direccion_solo_lectura.php`** — el aserto invertido.
   El bloque 4 solo comprobaba que las vistas **que ya usan** `$puedeEscribir` lo
   reciban, así que no podía ver una vista que nunca adoptó el flag. El nuevo
   deriva de `routes/web.php` qué rutas cierran a dirección y qué vistas alcanza
   un director, y cruza ambas: **50 rutas cerradas de 204, 30 vistas, 0 fugas.**
4. **Los KPIs de `/matriculas/resumen` cuentan ESTUDIANTES**, no matrículas
   aprobadas: universo `roster_evaluacion()` sin filtrar por `estado`. De paso
   arregla el **doble conteo por retorno de grado** (las dos mitades de la misma
   página daban totales distintos) y que «Secciones» contara secciones con
   aprobados. Promedio **entero**, por decisión del usuario.
5. **`.resumen-kpi` se retira**: era una de **cinco** copias de la tarjeta de
   cifra. La vista migra a `.stats-comp__kpi`, el único componente global. Mismo
   movimiento que el de los banners. Se corrige además el `page-header`, que metía
   el back-link dentro del `<div>` sin clase y rompía la convención de las otras
   79 vistas.

### Verificado

- **`verif_matriculas_resumen.php` nuevo, 20 asertos**, contra datos reales del
  año 2026: **523 estudiantes · 23 secciones · promedio 23 · 3 pendientes · 11
  fuera del conteo**. Contrasta con un `COUNT` escrito a mano, no derivado del
  helper. Hay **1 retorno de grado real** en el año, así que el aserto del doble
  conteo mide un caso vivo.
- **`verif_direccion_solo_lectura.php` probado en SUS DOS RAMAS**: reintroduciendo
  el gate del botón «Resolver», el bloque 6 lo señala con archivo, línea y método
  destino; al restaurarlo, verde. Un aserto que solo se ha visto pasar no prueba nada.
- **Los 9 verificadores del área en verde.**
- **Navegador, sin sesión**: los KPIs reales renderizados a 320 / 640 / 1120 px.
- **Falta el flujo con sesión de DIRECTOR** — es lo único que estas sondas no
  alcanzan, y es justo el rol del que va el lote.

### Pendiente que este trabajo destapó

~~**`retirado` está huérfano en `/matriculas/resumen`**~~ — **CERRADO el 02/09/2026**, ver el bloque del cuadro. Texto original:: el pie «Por tipo de
matrícula» lo descarta en silencio (`$tipoOrden` no se actualizó tras la migración
045) y `getCuadroMatricula()` no tiene columna para él, así que
`t_nuevo + t_cont + t_tras ≠ total`. **Fuera de este lote**: cambia lo que se ve.

## 🟢 BANNERS DE AVISO — COMPONENTE ÚNICO (02/09/2026)

**DESPLEGADO el 02/09/2026** (merge `5c353f1`). Sin migración. Salió de un bug de responsive reportado en el
banner de auditoría de `/consulta-notas/{p}/seccion/{s}/transversales`, y resultó ser el
sistema entero: **47 banners en 29 vistas**. Detalle en `docs/modulos/ui.md`.

### Qué entró

1. **`display: flex` era la causa, y afectaba a los dos sistemas.** Flex *blockifica* el
   contenido: cada `<strong>` es un ítem y cada tramo de texto suelto es un ítem **anónimo**,
   así que una frase con un `<strong>` en medio salía en tres columnas. Medido en Chrome a
   340 px: ítems en `x=204/340/431`, alturas `143/42/80 px`. **A 1100 px también estaba
   roto**, solo que menos obvio. Los que se veían bien lo hacían **por casualidad** (un solo
   nodo de texto = un solo ítem). Ahora el banner es de flujo y el icono sale de él.
2. 🔴 **Había TRES declarantes, y el que mandaba no era el componente.** Además de
   `components/_alerts.scss` y del `.flash` de `components/_cards.scss`, **`pages/_auth.scss`
   tenía una copia entera de `.alert` sin selector de página**; como `app.scss` la importa
   después, ganaba **en toda la app**, no solo en login. El `app.css` traía dos bloques
   `.alert{…}`. Mismo fallo que el de `.tabla-leyenda` en `verif_zona_resultado.php`.
3. **`.flash` pasa a ser ALIAS de `.alert`** (mismo ruleset, `.alert, .flash`). Repara los
   47 banners **sin renombrar nada**. Los 31 `.flash` cambian de aspecto —12 px en vez de
   14, padding 16 en vez de 24, y **ganan borde e icono**—: es la estandarización pedida.
4. **Icono automático por variante en todos** (decisión del usuario). Obligó a borrar
   **9 glifos a mano** (`✓ ⚠ ⚡ ✅`) en 8 vistas: la guarda `:has()` solo ve **elementos**,
   así que un carácter suelto no la activa y el banner salía con **dos** iconos.
5. **`alert__accion` se muda al componente** desde `pages/_registro-cierre.scss`, donde
   dependía de `margin-left: auto` (solo funciona dentro de flex). Ahora cae en su propia
   línea, alineado a la derecha. ⚠️ **Cambio visible en los 3 banners con botón.**

### Verificado

- **`verif_banners_aviso.php` nuevo, 24 asertos en verde.** Mide el CSS **servido** y el
  marcado de las 29 vistas; comprueba la **propiedad** (que el contenedor no vuelva a ser
  flex ni grid), no valores de padding o color. Su barrido cuenta **47 banners** por su
  cuenta. Incluye el aserto que barre las vistas buscando glifos a mano.
- **Los 7 verificadores que auditan `app.css` siguen en verde** tras tocar CSS compartido.
- **Navegador, sin sesión** — los 6 casos reales inyectados con el `app.css` compilado a
  340 px y 700 px: el de transversales, el de `conducta.php` (dos `<strong>` y un `<br>`,
  el peor), el de `director-ebr` (con enlace), el de `asistencia` (icono manual + botón,
  que era el riesgo de regresión), el de `actas_siagie` (con `<ul>` dentro) y el flash de
  sesión del layout. **Cero desbordes**, icono anclado a la primera línea y el automático
  correctamente suprimido donde hay uno manual. La altura del banner reportado baja de
  143 px a 89 px a 340 px.
- **Falta el flujo real con sesión**, y en especial **`/login`**: es la única vista pública
  y sus alertas cambian al borrar la copia de `_auth.scss`.

### Decisiones abiertas que deja

1. **Renombrar los 31 `.flash` a `.alert`** — cosmético y con cambio visual cero gracias al
   alias, así que se puede hacer por lotes o nunca. ⚠️ **Tiene un bloqueante que hay que
   decidir ANTES:** `resources/js/auth.js` autocierra `.alert--success` y `.alert--warning`
   **en todas las páginas**, así que al renombrar, los mensajes de sesión empezarían a
   desaparecer solos. Hoy los `.flash` no se autocierran.
2. 🆕 **BUG INDEPENDIENTE que este trabajo destapó: el mismo mensaje flash se pinta DOS
   VECES.** `layouts/app.php:58-75` ya pinta `$flash_success`/`$flash_error`/`$flash_warning`
   (globales que inyecta `BaseController::view()`), y **siete vistas los repintan por su
   cuenta**: `dashboard/index.php`, `docente/inicio.php`, `rectificaciones/{index,matricula,
   editar,extraordinaria}.php` y `admin/boletas-publicas/index.php`. No es un problema de
   CSS y arreglarlo **cambia lo que se ve**, así que queda fuera de este lote. (`auth/login.php`
   no cuenta: usa el layout `auth`, que no pinta flashes.)

## 🟢 ESTADÍSTICAS POR COMPETENCIA (01/09/2026)

**DESPLEGADO el 02/09/2026** (merge `5c353f1`), junto con la barra en rejilla del punto 8. Sin migración. Bloque de contadores encima de la tabla de
alumnos en cuatro pantallas: el resumen del docente
(`/docente/calificaciones/{carga}/resumen/{competencia}`),
`/consulta-notas/{p}/carga/{c}`, el historial del docente de un bimestre cerrado y el
panel de tutoría (`/docente/tutoria/{periodo}`, con un bloque por cada transversal).
Detalle y decisiones en `docs/modulos/calificaciones.md`.

### Qué entró

1. **`nota_es_aprobatoria()` + `LITERALES_APROBATORIOS` en `helpers.php`** — punto único
   del corte de aprobación, que **depende del nivel** (primaria AD+A · secundaria
   AD+A+B). Es la primera regla del repositorio que ramifica por nivel en la
   calificación, y **no es** la métrica «en logro» de `getResumenBimestre()` (AD+A en los
   dos niveles). Hay una línea en CLAUDE.md junto al invariante de la escala.
2. **`stats_competencia()`** — función pura sobre el `$alumnos` que ya está en memoria:
   evaluados, sin evaluar, aprobados, desaprobados y la distribución AD/A/B/C.
   **Cero consultas nuevas** y sin volver a disparar el N+1 de `getResumenCompetencia`.
3. **`resources/views/shared/_stats-competencia.php`** + componente SASS
   `components/_stats-competencia.scss`. CSS puro, sin Frappe y sin JS: se ve con
   JavaScript desactivado.
4. **3 puntos de inclusión para 4 pantallas** — el historial del docente lo recibe a
   través de `consulta-notas/_tabla.php`, que ya compartía. Tutoría pinta **un bloque
   por transversal** (TIC y GAMA) y entrega los datos con el prefijo `stats*` para no
   pisar sus propias variables; 🔴 el parcial **se limpia entre vueltas del bucle**, o
   la segunda competencia repetiría las cifras de la primera sin que se note.
5. **Los colores son los del chip `.nota-literal`, no una paleta aparte** — cada tramo de
   la barra toma del chip **sus dos valores**: el fondo para el relleno y el color de
   texto para el borde (como `.nota-numeral`), y la leyenda lleva el chip de verdad. Hay
   un aserto que compara los **ocho** valores en el `app.css` compilado.
6. 🆕 **Segunda pasada de legibilidad** (mismo día, tras revisarlo en pantalla): la
   **cantidad y el porcentaje** eran indistinguibles —mismo color, tamaño y peso— y ahora
   van en elementos separados, con la cantidad en negrita y el porcentaje en gris entre
   paréntesis, en cuadrícula para que los números queden en columna; y **la barra no
   tenía bordes visibles**, así que el marco de `$border-color` se sustituyó por el borde
   de color de cada tramo, que además marca las divisiones. ⚠️ La barra va **sin `gap`**
   a propósito: con separación, los anchos en `%` sumarían más de 100 y flex encogería
   los tramos, falseando las proporciones.
7. 🆕 **El contorno se abría en las cuatro esquinas** — el `overflow: hidden` del
   contenedor RECORTA el borde recto del tramo siguiendo su curva, no lo redondea.
   Arreglado dando `border-radius` a los tramos de los extremos, que ahora trazan ellos
   la curva. Comprobado a 5 aumentos en los tres casos (cuatro tramos con uno de 3,7 %,
   un único tramo al 100 % y dos tramos), con aserto sobre el CSS compilado porque es un
   defecto de 8 px que no se ve a tamaño real.
8. 🆕 **02/09 — LA BARRA SE REHIZO COMO REJILLA DE FRACCIONES.** El punto 7 dejó un bug:
   las dos reglas de los extremos usaban el **atajo** `border-radius` y un tramo único al
   100 % casa con **las dos** —misma especificidad, gana la última entera, y el atajo
   reemplaza las cuatro esquinas—, así que salía **recto por la izquierda**
   (`TL:0px BL:0px`). El aserto no lo vio porque solo comprobaba que las reglas
   *mencionaran* `border-radius`. Se pasó a `display: grid` con `minmax(0, X.Xfr)`, que
   además **deroga la prohibición del `gap` del punto 6** (la rejilla descuenta los huecos
   antes de repartir: proporciones exactas, medido a 1094 px y a 260 px) y **elimina el
   hueco de fondo del redondeo**. Cada tramo lleva ahora **su cifra exacta con decimal
   dentro** (`A 82.1%`), con dos umbrales de ancho (≥ 25 % siempre; 8–25 % solo ≥ 900 px
   de ventana; < 8 % nunca) verificados sin recortes en 8 anchos de 320 a 1400 px.
   Verificador: **23 → 40 asertos**. Detalle en `docs/modulos/calificaciones.md`.
   🔴 **HALLAZGO DE BUILD, vale para todo el repo:** `clean-css` 4.2.3 —el minificador de
   `gulp build`— **no conoce `@container` y lo borra EN SILENCIO**, sin fallar la tarea.
   Por eso los umbrales son una media query sobre la ventana y no una consulta de
   contenedor sobre el tramo, que es lo que el caso pide. `clean-css` 5.3.3 sí la
   conserva y su **única** otra diferencia sobre las 265 KB de `app.css` es entrecomillar
   los `url()` (medido con un diff de las dos salidas). **Subir el minificador está sin
   decidir** — ver la lista de pendientes.

### Verificado

- `verif_stats_competencia.php` en verde (**40 asertos** desde el 02/09) sobre **50
  competencias reales de los dos niveles**: los tres cuadres del universo, el contador de
  evaluados contra un `COUNT` independiente, el render del parcial, el corte por nivel en
  **sus dos ramas**, el `unset` entre vueltas del bucle, los colores atados a
  `.nota-literal` y —desde el 02/09— el reparto en `fr`, el decimal de la etiqueta, las
  dos bandas de umbral y que la media query llegue al CSS **servido**.
- 🆕 **02/09 — la barra rehecha, en navegador y con el parcial REAL.** Siete repartos
  (100 % en A, 100 % en AD, dos tramos 82.1/17.9, cuatro tramos con uno de 3,7 %, tramo
  diminuto al inicio, 4 × 25 % y un caso de primaria) renderizados con el parcial de
  verdad dentro de `.app-main` + `.card` y medidos con `getBoundingClientRect`:
  **cero etiquetas recortadas** en 320 · 375 · 641 · 768 · 899 · 900 · 1200 · 1400 px, en
  los dos estados de la media query, y las proporciones exactas al píxel en todos.
  **Sigue faltando el flujo real con sesión.**
- **Navegador, sin sesión** — las vistas se renderizaron a un HTML estático y se
  abrieron con el `app.css` compilado: `docente/resumen-competencia.php` y
  `consulta-notas/carga.php` **con datos reales** (carga 289, 27 alumnos, 24 aprobados
  de 27 en secundaria), el bloque dentro de su card y la tabla intacta debajo; más el
  bloque suelto a 320, 360, 768 y 1360 px con los casos «todos en C» y «nadie evaluado
  todavía». También `docente/tutoria.php`, donde se confirmó que los **dos bloques
  (TIC 21A/6B y GAMA 19A/8B) muestran cifras distintas** —la prueba de que la limpieza
  entre vueltas funciona— y que los chips de la leyenda son los mismos que pinta la
  tabla. **Falta el flujo real con sesión** (roles, redirects y la guarda de cada
  pantalla), que es lo único que estas sondas no pueden tocar.

### Lo que dejó medido

- **4 exonerados con nota viva** en la base. Es el caso que obliga a sacarlos del
  universo: exonerar no borra las notas, así que sin el filtro sumarían como aprobados
  mientras su boleta dice `EXO`.
- **Hay notas cuya matrícula ya no pertenece a la sección de la carga** (carga 118,
  matrícula 692): un cambio de sección deja la nota donde se cursó, y
  `getResumenCompetencia` las excluye correctamente. No es un defecto; era un aserto de
  control mal escrito.

### Decisión abierta — subir `clean-css` (02/09/2026)

**Sin decidir, no bloquea nada.** `clean-css` 4.2.3 (vía `gulp-clean-css` 4.3.0) **borra
en silencio toda regla `@container`** al minificar: `gulp build` termina en verde y la
regla no llega al navegador. No es exclusivo de este componente — **le pasará a cualquier
CSS moderno que se escriba de aquí en adelante**, y no avisa.

Medido el 02/09: `clean-css` 5.3.3 la conserva, y sobre las **265 KB** de `app.css` su
única otra diferencia son las **comillas en los `url()`** (`url(../x.svg)` →
`url("../x.svg")`), que es equivalente. El cambio sería un `overrides` en `package.json`,
porque `gulp-clean-css` no tiene versión que traiga clean-css 5.

Si se hace, **la barra de competencias gana**: sus dos umbrales de porcentaje y la media
query de 900 px se sustituyen por **una** `@container (min-width: 60px)` sobre el tramo,
que mide el ancho que de verdad importa en vez de deducirlo de la ventana. Hoy funciona
porque el bloque siempre vive en una card a todo el ancho de `.app-main`; **si alguien lo
metiera en una columna estrecha, las etiquetas se recortarían** y la media query no podría
enterarse.
## 🟢 CUADROS — conducta y asistencia ampliadas + roster con punto único (27/08/2026)

Lote grande en `dev`, **con verificación de navegador ya hecha** (no queda como
el lote del 26/08, que se mergeó sin abrir una pantalla).

### Qué entró

1. **`/admin/cuadros` pasa de 5 a 12 gráficos + 2 tablas**, reorganizado en dos
   secciones (Conducta / Asistencia) con **tres pestañas cada una**. Detalle y
   decisiones en `docs/modulos/usuarios-direccion.md`.
2. **Componente global de pestañas** — `components/_tabs.scss` + `js/tabs.js`.
   Cierra la deuda que `_consulta-notas.scss` llevaba declarada. Los otros
   cuatro conmutadores **siguen sin migrar**. Ver `docs/modulos/ui.md`.
3. **5 métodos nuevos de modelo** (2 en `ConductaModel`, 3 en `AsistenciaModel`).
   El controlador sigue sin un solo `SELECT`.
4. **`roster_evaluacion()` en `helpers.php`** — punto único de las 3 condiciones
   del roster, que estaban copiadas en **9 consultas**, `getAlumnosSeccion`
   incluida. Verificador nuevo: `verif_roster_evaluacion.php`.
5. **`admin/conducta/seccion.php` deja de rotular `C{$i+1}` posicional** — era la
   única vista que no migró con la 056, y `verif_conducta_criterios.php` no la
   cubría. Ahora sí.

### Verificado

- **31 verificadores en verde.** El único fallo, `verif_criterios_filtros_cascada`,
  **es previo a este lote** (comprobado con `git stash`): sus 2 asertos del salto
  de bimestre en `/consulta-notas/criterios` fallaban ya con el árbol limpio.
  **Queda abierto y no es de aquí.**
- **Navegador (27/08)** renderizando la vista real con datos reales a un HTML
  temporal en `public/` (borrado después). Comprobado en **B1 legado, B2 completo
  y B3 activo casi vacío**: dibujado perezoso (gráfico de panel oculto nace a
  1102 px, no a 0), teclado ←/→ con wrap, memoria de pestaña por bimestre,
  0 errores JS, sin scroll horizontal de página.
- **Imprimible A4**: hoja de 718 px, los **11 gráficos a 702 px**, sin pestañas ni
  paneles ocultos, y ni la matriz (23×10) ni el listado (203 filas) desbordan.
  Con esto se cierra el pendiente «el imprimible en papel nunca se ha probado».
- **Contraste del mapa de calor medido en navegador**: 14,1 / 12,3 / 4,6 / 4,8 —
  los 5 escalones en AA. El nivel más intenso salía a **2,4:1** por una regla de
  especificidad que ninguna prueba de servidor ve.
- Cifras contrastadas contra SQL independiente: conducta B1 `AD240 A228 B39 C9`
  (516 con literal), criterios B2 `C9 74,0 %` · `C10 53,9 %` · `C4 3,3 %`,
  asistencia B2 `F 371 · T 625`. Difieren del SQL crudo (528 / 373) **en exactamente
  el filtro de roster**, comprobado fila a fila.

### 🆕 28/08 — dos correcciones de lectura, verificadas en navegador

1. **«Estudiantes con más faltas» pasa a UNA TABLA POR SECCIÓN** (23 bloques con
   borde, cada uno con su `<caption>` y su `<thead>`). Con 180 filas seguidas el
   encabezado de columnas se iba de pantalla. Aserto nuevo en el verificador.
   **El A4 mejora de paso**: bloques de 263 px máx. contra 1047 px de hoja útil,
   así que ninguna sección se parte, y el listado baja de ~5 hojas a **4**.
2. **Arreglado el hover del mapa de calor.** Estaba con `background: inherit`, que
   toma el fondo del `<tr>` —inexistente—: la celda quedaba transparente y el
   número **blanco** de n4 **desaparecía** al pasar el cursor. Ahora cada escalón
   tiene su par de hover. Medido en navegador: los 6 escalones **≥ 4,5:1**, y los
   dos críticos suben de 4,59/4,83 a **5,81/6,47**. Tabla completa en
   `docs/modulos/usuarios-direccion.md`.

### 🆕 28/08 — cada card del dashboard con su propio icono

Tres cards usaban el icono de otra: **Cuadros estadísticos** llevaba la medalla
de Orden de mérito, **Criterios de evaluación** el lápiz de Rectificación y
**Consulta de notas** la lupa de Buscar estudiante. El usuario aportó
`stats.svg`, `criterios.svg` y `notas.svg` (mismo set Solar) y se asignaron.

Con eso **los tres glifos prestados recuperan un único significado**, y de paso
se resuelve una contradicción del wayfinding: la medalla y la lupa ya se usaban
en `_docente-panel.scss` para mérito y para buscar, así que el mismo icono decía
dos cosas según el panel. Esos usos del SASS **no se tocaron**.

Cambio: 3 literales en `resources/views/dashboard/index.php`. Verificado en
navegador con el CSS compilado (los 3 a 36 px, ningún `<img>` roto, trazo igual
al de sus vecinos) y con **dos asertos nuevos** en
`verif_direccion_superficies.php`: ninguna card comparte icono y todos existen en
disco. Las dos ramas de la guarda probadas.

**Queda un duplicado**: `users-group-rounded.svg` en «Secciones y Tutores» y
«Ranking por sección». No hay icono para sustituirlo y no se pidió; está en la
lista de excepciones toleradas del verificador.
### Pendiente

- **Papel de verdad**: el A4 está medido en pantalla, no impreso. Con el cambio
  del 28/08 T2 ya no se parte (cada sección es un bloque que cabe en una hoja);
  queda ver en papel el reparto de los 23 bloques entre las 4 páginas.
- **Regresión de `getAlumnosSeccion`**: `verif_roster_asistencia` y
  `verif_roster_evaluacion` lo cubren contra consultas de control escritas a mano,
  pero conviene abrir `/docente/calificaciones` de una carga real y ver el mismo
  número de alumnos.
- `/director/periodos/{id}/stats` sigue teniendo dos consumidores de
  `_panel-bimestre.php`: mirarla al probar.

## 🟡 USUARIOS DE DIRECCIÓN — EN `dev`, SIN PROBAR EN NAVEGADOR (24/08/2026)

Las **7 fases están implementadas** y las **5 verificaciones automáticas en
verde**, pero **nadie ha abierto todavía una sola pantalla**. Reglas, decisiones y
gotchas del módulo: **`docs/modulos/usuarios-direccion.md`**.

### 🔴 BLOQUEANTE ANTES DEL MERGE A `main`

1. **Aplicar la migración `055_rol_director_academico.sql` en PRODUCCIÓN a mano**,
   como la 044 y la 045. Es un `INSERT` de catálogo, idempotente, sin cambio de
   esquema. En LOCAL ya está aplicada (rol id 9). Verificación:
   `SELECT COUNT(*) FROM roles;` debe dar **9**.
2. **`public/css/app.css` va regenerado** en este lote. Si hay conflicto al
   mergear, **no resolverlo a mano**: tomar un lado y `npx gulp build`.

### PENDIENTE — pruebas en navegador (no se hizo ninguna)

Orden sugerido; los puntos 1 y 4 son los que más fácil se rompen:

1. **`/docente/horario/imprimir`** con un docente real — es la prueba de que la
   extracción de `HorarioModel` no cambió nada. Debe salir **idéntico** al de
   antes (grilla alineada, colores, horas/sem y total).
2. ~~**Crear un usuario con rol Director académico**~~ **HECHO EN LOCAL el
   24/08/2026:** ESPINOZA JULIE CAROL, DNI `00000001`, usuario 41. **En PROD
   sigue sin existir** y hay que decidir allí el DNI y el nombre reales.
   *(Al crearlo cayó `verif_rol_director_academico`: afirmaba «0 usuarios con el
   rol nuevo», una aserción que caducaba por diseño en cuanto se daba este paso.
   Sustituida por una que no caduca: ningún NO-Director EBR figura como firmante
   vigente.)*
3. Como director: las **10 cards** · `/matriculas` sin «+ Nueva matrícula» ·
   `/director/bloqueos` sin botones · la boleta desde una matrícula.
4. **Como `admin` y como Registro Académico: que SÍ vean todos sus botones.** Es
   la rama de la guarda que se rompe sin avisar (ya pasó una vez en esta sesión:
   el flag del Centro de Control se insertó en la rama equivocada).
5. `/admin/cuadros` · `/consulta-notas/{p}/seccion/{s}/asistencia` · el eje por
   docente · `/director/cargas/seccion/{id}/horario`.
6. **Explorador de criterios** (`/consulta-notas/{p}/criterios`, añadido el
   24/08 después de las 7 fases): árbol sección → carga, tabla por carga, sus
   4 filtros, el imprimible y la card nueva — el dashboard del director pasa de
   10 a **11 cards**. En LOCAL renderiza 2 353 criterios en B1 y 2 731 en B2
   (1 988 académicos + **743 transversales**), contrastados contra SQL
   independiente; **B3 sale vacío a propósito** y lo explica en pantalla.
   El usuario **ya lo vio en navegador** y de ahí salieron tres correcciones
   (el `&mdash;` escapado, la lectura en tabla y la retirada del buscador).
   Falta probar: **las dos cabeceras fijas** al hacer scroll dentro de una
   sección larga —es lo más frágil del lote— y el **imprimible en papel**.
   🆕 **25/08 — cascada de los 4 filtros, sin probar en navegador.** Nivel
   recorta Grado; Nivel+Grado recortan Sección; los tres recortan Docente por
   pertenencia. De paso se corrigió que el grado se identificaba por
   `grados.numero`, que **colisiona entre niveles** (`?grado=1` mezclaba
   primaria con secundaria). Servidor verificado con
   `verif_criterios_filtros_cascada.php`; **el JS de la cascada no lo ha visto
   nadie en un navegador todavía**. Detalle en `docs/modulos/usuarios-direccion.md`.
7. 🆕 **26/08 — gráficos de `/admin/cuadros` + su imprimible A4, sin probar en
   navegador.** Cinco gráficos con Frappe Charts 1.6.2 (ya vendorizado; **no se
   añadió ninguna librería**) y la ruta nueva `/admin/cuadros/imprimir`. Servidor
   verificado con `verif_direccion_superficies.php`, ampliado con cuatro
   aserciones: coherencia cruzada `getEvolucionAnual` ↔ `getResumenBimestre`
   celda a celda, paralelismo de las series, validez del JSON que consume
   `cuadros.js`, y render real de la vista A4.
   ✅ **El usuario confirmó en navegador (26/08) que los gráficos se dibujan
   correctamente** en `/admin/cuadros`. **Falta todavía**: (a) el **imprimible en
   papel** — es el primer gráfico que este repo imprime, no hay precedente, y el
   `max-width: 718px` de `.cuadros-print` existe porque Frappe le escribe al SVG
   un `width` en px al instanciarlo; (b) que `/director/periodos/{id}/stats` siga
   idéntica, porque `_panel-bimestre.php` **ahora tiene dos consumidores**.
   Detalle en `docs/modulos/usuarios-direccion.md`.
   🆕 **25/08 — chip con el `codigo_minedu`** delante del nombre de cada
   competencia, en pantalla y en el imprimible. Falta verlo en navegador y en
   papel (que el chip no descuadre la columna ni parta la fila).
   🆕 **25/08 — el selector de bimestre auto-aplica y el salto LIMPIA los 4
   filtros** (deroga el «conservando los filtros» del 24/08), más una guarda que
   descarta el filtro que no exista en el catálogo del periodo. Con esto queda
   **cerrado** el pendiente del filtro que sobrevivía al salto de bimestre.
   Falta en navegador: que al cambiar de bimestre no se recargue dos veces y que
   **Aplicar siga filtrando** con el bimestre sin tocar — es la rama que se
   rompe sin avisar.
   🆕 **26/08 — VERIFICADO EN NAVEGADOR POR EL USUARIO.** El eje por docente y el
   explorador de criterios dejaron de ser dos botones sueltos del `page-header`:
   ahora hay un **conmutador de 3 pestañas** (Secciones · Docentes · Criterios) y
   **un solo selector de bimestre**, común a las tres y por ENCIMA de la barra.
   Con esto quedan **cerrados** los pendientes de arriba sobre el selector
   (auto-aplicar, el salto que limpia los 4 filtros y que Aplicar siga filtrando):
   el usuario los recorrió y confirmó que funcionan. Detalle en
   `docs/modulos/consulta-notas-ampliada.md` §10.
   De paso salió una **causa raíz que afectaba a las 79 vistas con `page-header`**:
   `.page-title { flex: 1 }` no alineaba nada porque el `h1` cuelga de un `<div>`
   de `display:block`, que no es contenedor flex. Regla nueva en
   `pages/_dashboard.scss`; el porqué en `docs/modulos/ui.md`. **Sigue sin probar
   en navegador el resto de headers con acciones** que esa regla mueve:
   `/matriculas`, `/padre/notas`, `/director/orden-merito-periodo/...` y
   `/admin/actas-siagie`.
   🆕 **25/08 — nomenclatura de las DOS caras de las transversales.** Los
   promedios del tutor y el registro del docente se llamaban igual en 3
   pantallas; ahora son «Promedios de Competencias Transversales» y
   «Competencias Transversales — Registro del docente», y `seccion.php` lleva
   encabezados de grupo. Verificado en `verif_consulta_notas_ampliada.php` (F5).
   Detalle en `docs/modulos/transversales-visibilidad-tutor.md` §7. Falta verlo
   en navegador: **se prueba en B1 o B2**, donde las 23 secciones tienen cierre
   transversal vigente; **en B3 la tarjeta no sale** —0 cierres— y eso es
   correcto, no un fallo del renombrado.
   🆕 **25/08 — asistencia de la sección: bug + partial compartido.** La vista
   decía «Registro en curso» SIEMPRE (leía `bloqueado_en`, clave inexistente;
   la columna es `ra_bloqueado_en`) — con 23 cierres vigentes en B1 y 23 en B2
   mostrando lo contrario. La tabla pasa a ser un partial único compartido con
   Registro Académico (`admin/asistencia/_tabla-incidencias.php`), con totales
   y leyenda F/FJ/T/TJ en las DOS vistas, y el imprimible oficial abierto a
   Dirección. Verificado en `verif_asistencia_partial_compartido.php` (render
   real de los dos modos). **Falta en navegador, y esto es lo delicado del
   lote: que RA siga GUARDANDO** en `/admin/asistencia/{id}` —el partial
   alimenta a `asistencia.js` y un gancho perdido no da error visible— y que
   siga pudiendo **bloquear y aprobar**. La vista del director se prueba en
   B1/B2, que tienen cierre. Detalle en `docs/modulos/admin.md`.
   🔶 **Decisión abierta, planteada al usuario y sin responder:** al filtrar por
   UNA sección, ¿se conserva el acordeón de sección o se pinta en plano,
   ahorrando un nivel visual? Ver `docs/modulos/usuarios-direccion.md`.

### PENDIENTE — 5 recomendaciones medidas, NO implementadas

Se informaron y **no** se ejecutaron, por la regla de alcance del proyecto:

- **`resources/sass/components/_dashboard.scss` es un archivo MUERTO**: no se
  importa desde `app.scss` ni desde ningún otro SCSS, pero contiene una copia
  idéntica de `.competencia-card` (incluido el chip `&__codigo`). La vigente es
  la de `pages/_dashboard.scss`. Riesgo real: alguien edita la copia muerta,
  recompila y no ve ningún cambio. Detectado el 25/08 al unificar el chip de
  código de competencia; borrarlo es un `rm`, pero conviene confirmar antes que
  nada más lo referencie.
- **`&--secretaria` en `_admin.scss` es un rol fósil**: `secretaria_academica` y
  `secretaria_administrativa` salen pintadas con el **color de docente**. 2 líneas.
- **La descripción del rol `director_ebr` en la BD ahora es FALSA** — dice
  «Supervisión de su nivel educativo» y se decidió que los tres ven los dos
  niveles. Un `UPDATE` de una fila.
- **La consulta de periodos (`activo`+`cerrado`) está copiada a mano en 3
  controladores** (`ConsultaNotas`, `Bloqueo`, `OrdenMerito`) teniendo el método
  en `ControlOperativoModel::getPeriodos()`. Los Cuadros ya lo reusan; los otros
  tres no. Es la misma familia de fallo que este repo arrastra.
- **`MatriculaController::nominaImprimir` NO se abrió a los directores** — no se
  discutió. Decidir si la nómina imprimible entra en «grilla completa».

### PENDIENTE — bug preexistente, sigue abierto

- **La card «Usuarios» del dashboard se le muestra a `registro_academico`**
  (`dashboard/index.php`), pero `Admin\UsuarioController` exige `admin` → **403
  seguro**. Detectado el 24/08 al inventariar el dashboard; no se tocó porque está
  fuera del alcance de este módulo. (El caso gemelo de la card de bloqueos SÍ se
  corrigió, sumando RA al controlador.)

### Al cerrar: `verif_horario_modelo.php` es TEMPORAL

Reconstruye el algoritmo VIEJO desde `git HEAD` para contrastarlo con el nuevo.
En cuanto esto se mergee a `main`, HEAD traerá el código nuevo y el contraste
dejará de probar nada. **Es un verificador de la migración, no permanente**:
retirarlo o reescribirlo tras el merge.

## 🔵 PLAN — FLUJO PROPIO PARA LOS AUXILIARES (planteado 25/08/2026, SIN implementar)

Requisitos del usuario, tal como los dio. **Nada de esto está construido.**

1. Los auxiliares **no tienen formación técnica** y se les complican los
   aplicativos web.
2. **Usan el celular mucho más que una computadora.**
3. Su **nombre debe aparecer en el espacio de firma**.
4. Mismo flujo que el docente: marcan el dato de cada criterio, ven sus notas
   finales, y **bloquean y aprueban** para confirmar.

### Lo que ya existe y hay que reusar (medido el 25/08/2026)

- 🔴 **El rol `auxiliar_academico` NO EXISTE**: la tabla `roles` tiene 9 y ninguno
  es auxiliar. **Hoy el trabajo del auxiliar lo hace `registro_academico`** — así
  está escrito en el código (`BloqueoController`: *«el auxiliar académico (hoy
  Registro Académico)»*) y hay un TODO en `Admin\AsistenciaController`.
- **El CONCEPTO sí está modelado**: conducta tiene dos etapas
  (`cierres_conducta.ra_bloqueado_en` = etapa 1 «auxiliar», `tutor_cerrado_en` =
  etapa 2 «tutor»), y `/admin/cuadros` ya cuenta `pend_auxiliar` («Esperan al
  auxiliar»). El flujo del punto 4 **no se diseña desde cero**: se le pone rol
  propio a una etapa que ya existe.
- **La línea de firma ya está**: `admin/{asistencia,conducta}/imprimir.php` traen
  dos bloques, «Auxiliar Responsable» y «Personal de Registro Académico», con la
  línea EN BLANCO. El punto 3 es rellenarla — y el dato **ya está disponible**
  (`$cierre['ra_nombre']`), sin esperar al rol nuevo.
- ⚠️ **Incoherencia a resolver al hacerlo**: el mismo documento nombra a la misma
  persona con dos cargos. La traza dice «bloqueado y aprobado por X **(Registro
  Académico)**» y encima hay dos líneas de firma distintas. Hoy es ambiguo en cuál
  de las dos firma X.

### ¿Repetir el N° como última columna? — NO (respondido el 25/08/2026)

El auxiliar lleva un registro manual, filtra justificaciones y luego transcribe a
SIGACOCIAP; el miedo es **saltar de fila** al llegar al extremo derecho. Es un
problema real, pero repetir el N° es una solución de PAPEL aplicada a una pantalla:

- **`.tabla-notas` ya tiene `col-num` y `col-nombre` en `position: sticky`**
  (`components/_tables.scss`), con sombra en el borde. Al desplazarse en horizontal
  **el número y el nombre se quedan pegados**: la identidad de la fila nunca se
  pierde, así que la columna repetida no añade información.
- **Y empeora el problema donde más duele.** Ancho actual de la tabla editable:
  40 (N°) + 200 (nombre) + 4×64 (contadores) + 150 (acción) = **646 px**. En un
  móvil de ~390 px, con 240 px ocupados por las dos columnas fijas, **se ven 2 de
  los 4 contadores a la vez**. Repetir el N° lo lleva a 686 px.

Lo que sí ataca la causa:

1. ❌ **Layout de tarjeta en móvil** — **DESCARTADO por el usuario el 25/08/2026.**
   La grilla se mantiene a propósito: es lo que da accesibilidad útil al auxiliar
   que ya domina la herramienta o que viene de subir su asistencia en **SIAGIE**,
   donde el formato es de rejilla. No re-proponerlo.
2. ✅ **Resaltar la fila enfocada** — **IMPLEMENTADO el 25/08/2026** (commit
   `137676b`). Barra azul en la columna N°, que es sticky y por tanto sigue en
   pantalla con la tabla desplazada. Canal separado del fondo, que sigue diciendo
   el estado del dato. Incluye `scroll-margin-block` para que el teclado virtual
   no tape la fila recién enfocada. Ver `docs/modulos/admin.md`.
3. 🔵 **Entrada por estudiante** (buscar/elegir uno y registrar sus 4 contadores en
   una pantalla), que es como se transcribe desde un cuaderno: alumno por alumno.
   **Sin decidir.** Es la única de las tres que sigue abierta, y la que más se
   acerca al requisito 2 sin renunciar a la grilla: convivirían como dos entradas
   a lo mismo.

⚠️ Cualquiera de las tres toca `admin/asistencia/_tabla-incidencias.php`, que desde
el 25/08/2026 es **partial compartido con la consulta de Dirección**: el cambio se
vería en las dos pantallas. Ver `docs/modulos/admin.md`.

## 🏁 CIERRE DE LA VERSIÓN 1.0 — 22/08/2026

Auditoría de cierre completa (código, BD y documentación) y corrección de sus
tres hallazgos accionables. **La batería del repo quedó en 21/21 por primera vez.**
El detalle del alcance de la versión está en `CHANGELOG.md`; aquí solo el saldo.

**LO QUE LA AUDITORÍA MIDIÓ EN VERDE** (medición ejecutada, no leída de este archivo):
214 archivos PHP sin error de sintaxis · **195 rutas** con el invariante de orden
intacto · **83/83** rutas POST validando CSRF · **33/33** controladores con guard de
acceso · **0** puntos de inyección SQL (las 30 interpolaciones son listas blancas,
enteros acotados o literales fijos) · **0** salidas sin escapar de 1 996 impresiones
en vistas · **0** marcas `TODO`/`FIXME` reales · el invariante de la boleta
(`INNER JOIN bloqueos_competencia` en `getBoletaAlumno`) intacto.

**H1 — LOS TRES ROJOS DE LA BATERÍA ERAN DEL VERIFICADOR, NO DEL SISTEMA.** Es el
hallazgo que más costaba ignorar: una red que cría falsos positivos deja de leerse.
- 🔎 **CAUSA DOMINANTE, y es la de siempre: una regla copiada fuera de su punto
  único.** `verif_estructura_boleta` y `verif_asistencia_boleta` tenían **cada uno su
  copia** de la compuerta de publicación, y era **media regla** —filtraban por
  `primera_publicacion_en IS NOT NULL`—, mientras `periodosPublicados()` corta por
  `publica_en <= ahora` y **ni siquiera mira ese sello**. La divergencia estuvo
  **latente** mientras todas las publicaciones fueron INMEDIATAS (con ellas ambas
  ramas coinciden) y se activó al vencer la primera **PROGRAMADA** (B2, 13-14/08).
  Ahora los dos **preguntan al modelo** en vez de replicar la regla.
- `verif_asistencia_boleta` replicaba además **2 de las 3** condiciones de
  `sin_registro`: le faltaba `tieneRegistroUnion`, que nació para no imprimir
  «0 faltas» de un bimestre no cursado. Daba por hecho que el bimestre en curso
  tiene asistencia registrada, y **B3 no tiene ni una fila**.
- `verif_nomina_docente_render` trataba «no es el último publicado» como «sin
  publicar» (acusaba a B1, publicado desde el 22/07) y comparaba **por subcadena**:
  `'I Bimestre'` casaba dentro de `'II Bimestre'`. Ahora usa
  `periodosConAlgunNivelPublicado()`, acota al rótulo y compara con frontera de letra.
- ✅ **Control ejecutado**, para no confundir «arreglado» con «silenciado»: la fuente
  nueva **discrimina** viajando en el tiempo con el parámetro `$ahora` (un minuto
  antes y después de la hora de publicación del nivel del alumno), la regla vieja
  **pierde B2**, y el matching con frontera **sigue detectando una fuga real**.
- ⚠️ Se añadió un **AVISO explícito** (no fallo) cuando el bimestre en curso no tiene
  asistencia: ese paso pasa pero **no discrimina**, y decirlo vale más que un verde
  que no significa nada.

**H2 — DIEZ RUTAS VIVAS HACIA TRES CONTROLADORES QUE NO EXISTEN.** `Secretaria\
MatriculaController`, `Director\MatriculaController` y `Director\SeccionController`.
No reventaban (el router comprueba `class_exists` y devuelve 404 limpio), pero eran
superficie registrada en producción y **4 figuraban como POST sin CSRF** en cualquier
auditoría. Retiradas.
- 🔎 **De paso apareció una entrada DOBLEMENTE muerta** en
  `AuthController::redirigirPorRol`: `'secretaria' => url('secretaria/matriculas')`.
  Su destino era una de esas rutas inexistentes **y su clave no corresponde a ningún
  rol real** —los códigos son `secretaria_academica` y `secretaria_administrativa`—,
  así que la rama **nunca llegó a ejecutarse**. Ambos roles caen en el dashboard por
  el fallback, que es un destino válido.

**H3 — DOS UMBRALES DE LA ESCALA FUERA DEL INVENTARIO DOCUMENTADO.**
`AnioAcademicoModel` calculaba «en riesgo» y «nº de C» con un **11 hardcodeado**, en
el mismo método que 30 líneas antes ya interpolaba `NOTA_MIN_AD/A/B`. La excepción de
`CLAUDE.md` solo acota los umbrales a mano a las **dos consultas de
`OrdenMeritoModel`**, así que estos dos no estaban advertidos en ninguna parte.
Sustituidos por `NOTA_MIN_B`, **verificando que es una identidad** (ambas consultas
devuelven exactamente lo mismo). Hoy no mordía; el colegio ya movió la escala una vez
(10/06) y el panel del Director habría seguido midiendo con la vieja en silencio.

**H4 — 8 estilos en línea estáticos** (de 29 `style=`; los otros 21 inyectan un valor
calculado y son legítimos). **NO se tocaron:** cosméticos y en páginas de impresión
aisladas. Quedan como deuda menor.

**H5 — LA COPIA LOCAL NO ESTABA DONDE ESTE ARCHIVO DECÍA.** El repaso del 17/08
registró «copia local al día» con `snap_b2 = 520`; la medición del 22/08 sobre esa
misma base da **524 filas**, con `generado_en` del 10/08 17:28 (anterior a la
sincronización de roster del 11/08). No es un defecto del sistema, pero **ese marcador
no sirve como referencia**: ninguna cifra de producción debería citarse sin re-medirla
allí. Las conclusiones de CÓDIGO sí valen para prod, porque el código es el mismo.

## 🔴 REVISIÓN DEL 17/08/2026 — EL CANDADO 046 SE CERRÓ SOLO Y NADIE LO ANOTÓ

Repaso completo de pendientes contra el repo y la BD. **Copia local al día** (huella
`siga_cociap` · `root@localhost` · PROBOOK450 · MariaDB 10.4.32; marcadores
`m050=275 · m048=0 · snap_b1=528 · snap_b2=520 · rectificado=0`). **`dev` y `main`
idénticos en `481bbe7`**, árbol limpio, nada sin pushear, **sin migración pendiente**.
Última actividad de código: **12/08**.

**1. B2 ESTÁ PUBLICADO Y SU SNAPSHOT OFICIAL YA ES INMUTABLE.** Las dos filas de
`periodos_publicacion` conservan `primera_publicacion_en` en **NULL** —la publicación fue
PROGRAMADA, no inmediata— pero sus `publica_en` **ya vencieron**: primaria el **13/08
19:00** y secundaria el **14/08 19:00**. Como `fuePublicado()` es
`primera_publicacion_en IS NOT NULL OR publica_en <= NOW()`, el candado 046 está **activo
desde el 13/08 19:00**, sin que nadie pulsara nada.
- Las familias **ya ven** las boletas de B2 desde esas horas.
- El snapshot oficial de B2 (**520 filas**) **no se puede volver a escribir**: toda
  rectificación va desde ahora a `orden_merito_rectificado` (hoy **0 filas**), visible solo
  en `/admin/control`.
- **La ventana barata de corrección se cerró.** La regla del roster `aprobada` del 12/08
  entró con un día de margen; un caso equivalente que aparezca hoy ya no tiene arreglo en
  el oficial.
- Las entradas escritas el 10 y el 11/08 hablan de esa hora **en futuro**. Quedan corregidas
  en su sitio (ver "Cierre de B2 — SECUENCIA CORRECTA" y el evento del 11/08).

**2. QUEDAN 49 DÍAS PARA EL 05/10** (`periodos.fecha_inicio` de B4, verificado; B3 `activo`
con `limite_notas = 2026-10-16 04:00`). Lo que tiene que estar arriba antes:

| # | Pendiente | Estado |
|---|---|---|
| 2.1 | **El periodo final exige todas las competencias** (regla del 10/08, 4 decisiones cerradas) | sin escribir |
| 2.2 | **F1 — punto único de "carga dueña"**, prerrequisito duro de 2.1 | sin escribir |
| 2.3 | **Los 4 registros del bimestre** (plan del 04/08, 6 decisiones cerradas) | **DESBLOQUEADO desde el 14/08** |
| 2.4 | **Cola del logro anual** (324 pares sin nota en B4 → guion) | la resuelve 2.1 |

**F1 (2.2) es el cuello de botella real:** 2.1, 2.3 y el panel de transversales diferido
dependen los tres de él. El plan de los 4 registros se había diferido a "después de cerrar
y publicar B2", y eso **ya ocurrió**.

**3. LA PREMISA F0 DEL PLAN DE NOTAS RETROACTIVAS (`049`) CADUCÓ.** Detalle y cifras en su
entrada de Pendientes de desarrollo. En una línea: los 5 mecanismos ya **no** están en 0
filas —la migración **050** metió 275 extraordinarias de un bimestre **publicado**—, así que
"la unificación no arrastra datos" dejó de ser cierto.

**4. TRES PENDIENTES DE DESARROLLO CAMBIARON DE ESTADO, NO DE CONTENIDO:** la **opción B**
del hueco del guard de empates queda **desbloqueada** (se difería a "después del cierre de
B2"); los **dos husos horarios en prod** siguen medidos y **no abordados**; y el refactor
del **retorno de grado escrito a mano en ~15 sitios** sigue sin dueño.

**5. PENDIENTES OPERATIVOS SIN MOVIMIENTO:** horarios reales de 1.º A secundaria, la decisión
del colegio sobre regenerar el ranking de B1 (**ahora más cara: B1 está publicado y bajo
candado**), la validación en móvil del botón "✕ Cerrar", y los **12 alumnos de B1 con blancos
sin motivo** que hacen de reabrir B1 una puerta de un solo sentido.

**6. TRES DATOS VERIFICADOS HOY EN LA BD, sin cambios respecto de lo documentado:**
**0 usuarios con rol Padre** (la superficie de familias sigue oscura), **9 bloques basura**
en `bloques_horario` y el **`alias_boleta` del área 14 todavía en `(Ética y Valores)`** en
vez de NULL. Ninguno muerde hoy; los tres siguen abiertos.
- ⚠️ **Etiquetas obsoletas corregidas en este repaso:** `/consulta-notas` ampliada y el fix
  de `NOW()`/timezone figuraban como **"SIN DESPLEGAR"** en sus cabeceras. Los dos están en
  producción (`dev == main`; las 5 rutas de consulta-notas están en `routes/web.php`). Los
  eventos posteriores de este mismo archivo ya los daban por desplegados: eran las cabeceras
  las que no se actualizaron.

## ✅ DEUDA DE DOCUMENTACIÓN — BLOQUE CERRADO EL 17/08/2026

Los 3 pendientes de documentación se cerraron atacando la causa, no la etiqueta.

**DC-1 — `CLAUDE.md` anunciaba como plan sin implementar lo que ya corría.** De sus 5 filas
con `(PLAN, sin implementar)`, **3 eran falsas** (boleta con todas las competencias, consulta
de notas ampliada y transversales: las tres en producción desde el 05-07/08), 1 parcial
(registro retroactivo: su F1 ya está desplegada) y solo 1 correcta. Verificado comprobando que
los 5 commits de deploy citados son ancestros de `origin/main`, no fiándose de este archivo.
- 🔎 **CAUSA RAÍZ, y por eso no bastaba corregir las etiquetas:** una tabla que **enruta por
  TEMA** cargaba un **ESTADO**, que caduca en cada despliegue. Peor: la línea 201 del propio
  `CLAUDE.md` ya ordenaba que «pendientes, migraciones y planes con fecha se registran SOLO
  en `docs/ESTADO.md`». **El archivo incumplía su propia regla de mantenimiento.**
- **Arreglo (decisión del usuario):** el estado sale de la tabla. Las filas describen el tema
  y nada más, con un aviso explícito que prohíbe reintroducirlo. El estado se responde aquí.

**DC-2 — cuatro docs de módulo llevaban su estado de nacimiento**, uno más de los tres
detectados en el barrido: se sumó `orden-merito-rediseno.md`, que decía «en la rama `dev` —
pendiente de deploy a `main`» llevando en producción desde el **04/08** (`de449e2`).
Corregidos también `boleta-competencias-completas.md` (decía SIN DESPLEGAR y que faltaba el
checklist de impresión, cerrado el 10/08), `consulta-notas-ampliada.md` (título y cuerpo) y
el título de `transversales-visibilidad-tutor.md`. Más dos marcas internas: la F1 de
`registro-retroactivo-notas.md` y la condición de arranque de `cierre-cuatro-registros.md`,
que ya se cumplió.
- 🔎 **EL PATRÓN ES UN HUECO DE PROCESO, no seis descuidos:** los seis casos dicen «en `dev`»
  o «sin desplegar». **El doc se escribe al IMPLEMENTAR y nadie vuelve a él al DESPLEGAR** —
  el deploy actualiza este archivo (que tiene sección Git) y jamás la cabecera del módulo.
  Iba a repetirse con cada función futura, así que se añadió la regla a las **Reglas de
  mantenimiento de la red** de `CLAUDE.md`.

**DC-3 — el plan de cambio de sección se portó a `docs/modulos/cambio-seccion.md`** y ganó su
fila en la tabla. Vivía solo en memoria, sin versionar. Se verificó contra el código que la
función **sigue sin existir** (0 rutas, sin `CambioSeccionModel`, sin tablas
`cambios_seccion*`, sin ningún `UPDATE` de `matriculas.seccion_id`), así que el plan vale
íntegro. **Su migración se renumeró de la `039` a la `053`:** la 039 la ocupó
`039_areas_codigo_siagie.sql` el 12/07, tres días después de escribirse el plan.
- **Por qué merecía versionarse:** guarda una intuición que no es derivable del código — los
  bloqueos son **por carga, no por alumno**, así que un `UPDATE seccion_id` a secas hace
  **reaparecer en la boleta** las notas de la sección origen y **duplica** la competencia si
  el destino ya calificó.
- **Queda UNA decisión abierta** (la única del plan): qué hacer al revertir si la sección
  destino ya cargó notas — archivar simétricamente o descartar.

## ✅ PENDIENTES OPERATIVOS — BLOQUE CERRADO ENTERO EL 17/08/2026

Los 8 pendientes operativos se revisaron uno a uno contra la BD. **Los 8 quedaron cerrados
el mismo día: no queda ninguno vivo.** Es la primera vez que esta sección queda en cero.
Detalle en cada entrada de la sección "Pendientes operativos"; aquí solo el saldo.

| # | Pendiente | Cómo cerró |
|---|---|---|
| 1 | Digitar horarios reales en prod | ✅ **Ya estaba hecho.** 0 áreas huérfanas; las 43 cargas sin horario propio están cubiertas por la carga dueña de su área (regla general, no defecto) |
| 2 | Regenerar o no el ranking de B1 | ✅ **Decidido: NO se regenera.** El snapshot de 528 filas es definitivo |
| 3 | Validar en móvil el botón «✕ Cerrar» | ✅ **Probado por el usuario en móvil real: funciona.** Era el único que no se podía cerrar desde el servidor |
| 4 | Los 12 alumnos de B1 con blancos | ✅ **Medidos y nombrados.** 1 blanco cada uno, no 69. **No se pueden pre-resolver** con B1 cerrado: queda como restricción documentada de reapertura |
| 5 | Talleres sin hoja en el SIAGIE | ✅ **Bloqueado fuera de SIGA** (UGEL Huaraz), decisión firme. Alcance re-medido: **1332 notas**, no 321 |
| 6 | Limpiar datos de ensayo en local | ✅ **Nada que limpiar**, y la receta se retiró: sus ids apuntaban ya a datos REALES |
| 7 | Alias huérfano del área 14 | ✅ **Migración `052`, aplicada y verificada en LOS DOS ENTORNOS** el mismo día |
| 8 | Re-subir firma/sello del Director | ✅ **No era un pendiente**: nota condicional, reclasificada |

**Los dos hallazgos que justificaron medir antes de ejecutar:**

1. 🔴 **La receta de limpieza del #6 habría borrado datos reales.** Anclaba por id, y las
   resincronizaciones desde producción reciclaron esos ids: la «exoneración de ensayo»
   es hoy la exoneración **real** de la matrícula 530, y la «sección sembrada» es 1.º A de
   Secundaria con 240 respuestas de un bimestre cerrado y publicado. **Un `DELETE` por id no
   falla cuando el id cambia de dueño: acierta sobre la fila equivocada.**
2. ⚠️ **Dos de mis mediciones del #1 dieron 0 y eran falsas** — la segunda por hacer
   `JOIN areas ON ca.area_id`, que descarta en silencio las cargas colgadas de una
   **subárea**. La trampa del `COALESCE` que `CLAUDE.md` documenta para competencias
   **vale igual para cargas**.

✅ **NO QUEDA NADA DE ESTE BLOQUE.** La migración `052` se aplicó en producción el mismo
**17/08/2026 a las 12:12:34**, con la huella del servidor y el PASO 4 en conexión nueva
capturados allí (deploy `481bbe7 → 0d7c030` primero, script por SSH después). Los ocho
pendientes operativos quedan cerrados y sin cola.

## ✅ CICLO CERRADO EL 12/08/2026 — el mérito exige matrícula aprobada

**Regla del 12/08: al orden de mérito solo entran las matrículas `estado='aprobada'`.**
Decisión del usuario, con las 6 preguntas de alcance cerradas (ver
`docs/modulos/orden-merito.md` §7.1). **Sin migración.** Los dos actos del despliegue
—el auto-deploy publica CÓDIGO, no repara DATOS— quedaron hechos el mismo día:

1. ✅ `dev` → `main` (merge `7de64b5`): el cambio del roster, el arreglo del log del
   script de reconciliación, las verificaciones y la documentación.
2. ✅ **`sincronizar_roster_snapshot.php --confirmar` en PRODUCCIÓN**, salida capturada.

Se hizo **antes de publicar B2** (primaria 13/08 19:00, secundaria 14/08 19:00), que era
el tope: pasada esa hora el candado 046 vuelve inmutable el oficial y los tres alumnos se
habrían quedado dentro del documento que reciben las familias.

**Resultado en prod, idéntico al ensayo local — B2 de 523 → 520 filas:**

| Matrícula | Alumno | Grado | Puesto que ocupaba |
|---|---|---|---|
| `#696` | MORALES YANAC, Yeremi Miguel | 3.º Secundaria | 43° |
| `#693` | RIMAC CIRIACO, Azahí Fernanda | 5.º Secundaria | 41° |
| `#695` | GONZALEZ RIBERA, Jeanfranco Nuriel | 5.º Secundaria | 44° |

Las tres `estado='pendiente'`, dos de ellas por «Registro provisional — pendiente de DNI».
**11 compañeros** cambiaron de puesto (9 en 5.º, 2 en 3.º) y **ningún primer puesto se
movió**: la media beca no se vio afectada. B1 intacto (528 filas, publicado).

> ⚠️ **Al leer esa salida:** el arrastre por matrícula solo aparece en la que dispara la
> reescritura (`#696`, «2 compañeros»); las otras dos se informan con su puesto pero sin
> arrastre, porque `escribirOficial` reescribe el periodo entero de una vez. El total real
> es 11. Las tres quedaron en el log de la aplicación.

**Sigue vivo:** un `pendiente` se califica, recibe boleta y su evaluación incompleta
**sigue abortando el cierre** (`alertasEvaluacionIncompleta` no cambió) — simplemente no
tiene puesto. Si alguna de esas tres matrículas se regulariza, aprobarla la **reincorpora
sola** al mérito de los bimestres cerrados y no publicados, avisando del puesto.

## ⏱️ CÓMO RETOMAR EN OTRA MÁQUINA (escrito el 10/08/2026)

⚠️ **La BD local de cada equipo es independiente y se resincroniza a mano.** Antes de creer
una sola cifra, correr esto y comparar con la columna "esperado":

```sql
SELECT DATABASE() db, USER() usr, @@hostname host, @@version_compile_os so;
SELECT numero, estado, limite_notas FROM periodos ORDER BY numero;
SELECT (SELECT COUNT(*) FROM calificaciones WHERE extraordinaria = 1)            AS m050,
       (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name LIKE '\_bkp%')           AS m048,
       (SELECT COUNT(*) FROM orden_merito_snapshot WHERE periodo_id = 2)         AS snap_b2,
       (SELECT COUNT(*) FROM periodos_publicacion WHERE periodo_id = 2)          AS publicado_b2;
```

| Marcador | Esperado si la copia está al día |
|---|---|
| `m050` (extraordinarias de Ética) | **275** |
| `m048` (tablas `_bkp`) | **0** |
| `snap_b2` (snapshot oficial de B2) | **520** desde el 12/08 (era 524 → 523 con la reconciliación del 11/08 → 520 al exigir matrícula aprobada). Si da 523, falta correr `sincronizar_roster_snapshot.php --confirmar` |
| `publicado_b2` (filas de publicación) | **2** (un nivel cada una) |
| Estados de periodo | B1 `cerrado` · B2 `cerrado` · **B3 `activo`** · B4 `pendiente` |
| `SELECT COUNT(*) FROM roles` | **9** desde la migración **055**. Si da 8, falta aplicarla y el Director académico no existe |
| `SELECT COUNT(*) FROM criterios_conducta WHERE codigo IS NOT NULL` | **10** desde la migración **056**. Si da 0, el mapa de calor de `/admin/cuadros` sale sin códigos `C1..C10` |

*(Los cuatro primeros y estos dos, medidos de nuevo en local el 04/09/2026: 275 · 0 ·
520 · 2 · 9 · 10, con `limite_notas` de B3 en `2026-10-16`.)*

**Si `snap_b2` da 0 o B3 sigue `pendiente`, la copia es ANTERIOR al 10/08** y no sirve para
medir nada de lo de abajo: resincronizar desde producción primero.

### Estado del repo al cerrar la sesión del 04/09/2026

`dev` = **`ce2502c`**, pusheado. **`main` se quedó atrás: hay código en `dev` SIN
desplegar** (los dos bloques de CUADROS del 04/09, arriba del todo, más lo del 02/09).
**Sin migración pendiente**: nada de lo del 04/09 toca el esquema, así que una copia de
BD que ya pasara la tabla de marcadores **sirve tal cual**.

**Tras `git pull origin dev` en el otro equipo, en este orden:**

1. **No hace falta `gulp build`.** `public/css/app.css` y `public/js/cuadros.js` van
   compilados EN el commit. ⚠️ Si `app.css` da conflicto al mergear, no se resuelve a
   mano: se toma un lado y se recompila (es un archivo generado; ver la nota de la
   sección Git).
2. **Comprobar la BD** con el bloque de marcadores de arriba antes de creerse ninguna
   cifra — la copia del otro equipo puede ser más vieja.
3. **Correr los dos verificadores del trabajo nuevo**, que son de solo lectura:
   ```
   php database/verificaciones/verif_cuadros_merito_motor.php
   php database/verificaciones/verif_direccion_superficies.php
   ```
   El primero debe decir `TODO OK — 0 fallo(s)`; el segundo, `== FASES 4-7 EN VERDE ==`.
   Si el primero falla en un bimestre ABIERTO, sospechar de la BD antes que del código:
   compara el tablero contra `rankingGrado` y ambos dependen de qué haya bloqueado.
4. **XAMPP**: si algo falla con `SQLSTATE[HY000] [2002]`, es que MySQL no está
   levantado, no un bug. Pasó al abrir la sesión del 04/09 y costó un diagnóstico.

**Lo que quedó sin hacer y NO es un olvido:** desplegar (merge `dev` → `main`), que se
pregunta siempre antes.

> ✅ **Reparación de datos APLICADA EN PRODUCCIÓN el 11/08/2026** (`sincronizar_roster_snapshot.php
> --confirmar`, salida capturada allá): ESCUDERO TORRES `#456` salió del oficial de B2
> —ocupaba el **puesto 30° de 1.º Secundaria**, 42 compañeros cambiaron de puesto— y el
> snapshot quedó en **523 filas**, idéntico al ensayo local. Con eso B2 volvió a ser
> rectificable y el orden refleja las 3 rectificaciones de 4.º de primaria.

**Lo primero que toca al retomar (revisado el 04/09/2026):**

1. **Decidir el despliegue** de todo lo acumulado en `dev` (CUADROS del 04/09 y lo del
   02/09). Es lo único que separa a `main` de `dev`.
2. El siguiente hito con fecha: la **regla del periodo final** (tope **05/10/2026**),
   con sus 4 decisiones ya cerradas. ⚠️ Sería la **5.ª copia** de «carga dueña»:
   extraer el punto único ANTES de implementarla.
3. **Usuarios de Dirección sigue sin probarse en navegador CON UN DIRECTOR.** El
   04/09 se recorrió `/admin/cuadros` y su A4 en navegador, pero **con sesión de
   administrador**, así que no dice nada del acceso de los tres roles directivos.
   Sigue faltando el DNI y el nombre del **Director académico de prueba**; sin ese
   dato el rol no se puede ejercitar. Es dato del colegio: preguntar antes de crearlo.

## Migraciones
- 🆕 **`056_codigo_criterios_conducta`** (25/08): añade `criterios_conducta.codigo`
  (VARCHAR(8) NULL) y lo siembra como `CONCAT('C', orden)`. **APLICADA EN LOCAL,
  PENDIENTE EN PRODUCCIÓN** — el auto-deploy publica código, no repara datos: hay
  que ejecutarla a mano tras el push, o `getCriterios()` fallará al pedir una
  columna que allí no existe.
  - **Por qué**: las grillas rotulan `C1`, `C2`… y ese código se calculaba a mano
    como `$i + 1` en dos vistas. Un código POSICIONAL se corre entero si alguien
    reordena o borra un criterio, y los registros ya impresos y firmados dejan de
    cuadrar sin ningún error visible. Segundo motivo: `getCriterios($nivelId)`
    filtra por nivel, así que en cuanto exista un criterio por nivel la misma
    posición significaría criterios distintos en primaria y en secundaria.
  - **No cambia nada de lo ya impreso**: medido antes de escribirla, los 10
    criterios vigentes tienen `orden` 1..10 **sin huecos**, así que `C{posición}`
    y `C{orden}` daban el mismo valor. `verif_conducta_criterios.php` ancla esa
    coincidencia y avisará el día que se rompa a propósito.
  - **Idempotente** y con fallback en código: la columna admite NULL y
    `ConductaModel::getCriterios()` cae a la posición si un criterio nace sin
    código, que es como se rotulaba antes.
- **`054_revertir_anulacion_constancia_traslado`** (22/08): corrección de DATOS (no toca
  esquema). Devuelve a `vigente` la constancia de traslado **N° 052-2026-CAVVG-DA** (4.º A
  de secundaria → IEP LAS AMERICAS SCHOOL, 07/07/2026) y deja sus tres campos `anulado_*`
  en NULL, de modo que la fila queda como una constancia que nunca se anuló. El traslado
  está consumado y el libro oficial vuelve a decirlo.
  - **NO toca la matrícula** —sigue `desactivado` + `trasladado`— ni `calificaciones`,
    `bloqueos_competencia`, `inasistencias`, `conducta`, `orden_merito_snapshot` o
    `boletas_publicas`. La boleta pública se queda con `activa = 0`: al trasladado se le
    omite el QR **a propósito** (su token está muerto) y reactivarla publicaría un enlace
    que lleva a «no encontrado».
  - 🔎 **La tabla `traslados` NO participa en el flujo de la boleta** — 0 referencias en
    `BoletaModel`, `BoletaPublicaModel` y `Boleta\BoletaController` (verificado el 22/08).
    Lo que decide el trato es la pareja `matriculas.estado` + `matriculas.tipo`, y el
    trasladado consumado ya recibe su **última boleta OFICIAL de archivo** (con firma, sin
    QR, ignorando la compuerta 044 por ser documento administrativo de staff). Un
    `desactivado` por otra causa caería en BORRADOR forzado, sin firma.
  - **Anclaje:** DNI del estudiante + `correlativo`. **Nunca** por `traslados.id` ni
    `matricula_id` (difieren entre entornos), y **nunca** por `numero_constancia`, que
    lleva «N°» no-ASCII y resolvería 0 filas en silencio — lección de la 050.
  - **Tres guards duros en el WHERE**, probados uno a uno en sus ramas de aborto con
    ROLLBACK: correlativo libre entre vigentes (una constancia anulada **libera** su
    número y `correlativoDisponible()` permite reusarlo a mano), matrícula todavía
    trasladada, e idempotencia (segunda corrida = 0 filas).
  - ✅ **APLICADA EN LOCAL el 22/08/2026** — huella del PASO 0: `siga_cociap` ·
    `root@localhost` · `KORTECCORPC` · **MariaDB 10.4.32** · Win64. Verificado allí:
    `matriculas.updated_at` **sin moverse** (07/07 09:49:26, la hora de la baja original),
    0 correlativos duplicados, libro del año con sus 6 constancias vigentes, 25 notas del
    I Bimestre y snapshot de 528 filas intactos, y la batería del repo en **18/21** sin
    rojos nuevos.
  - ✅ **APLICADA EN PRODUCCIÓN el 22/08/2026**, por SSH, en el servidor `br-asc-web1308`
    (el mismo host donde se aplicó la `052`). Recorrido completo: **ensayo con ROLLBACK**
    (`SIMULACION CORRECTA`, 1 fila afectada) → corrida con `--confirmar` → **COMMIT** →
    PASO 4 en **conexión nueva**: `RESULTADO: APLICADA. La constancia esta VIGENTE y la
    matricula intacta.`
    - Ese resultado final es en sí mismo la verificación: el script **sale con código 1**
      si el veredicto no es `PUEDE_REACTIVARSE`, si las filas afectadas no son
      exactamente 1, si el cambio dejara correlativos duplicados, o si al releer en
      conexión nueva la constancia no está `vigente` **y** la matrícula no sigue
      `desactivado` + `trasladado`. Que imprima `APLICADA` implica las cuatro cosas.
  - **El correlativo no estaba reusado en prod.** Era el único punto que podía diferir de
    local (una constancia anulada **libera** su número y `correlativoDisponible()` permite
    reusarlo a mano); el veredicto `PUEDE_REACTIVARSE` del ensayo lo descartó allí mismo.
    En local tampoco se reutilizó: las siguientes tomaron 53 y 54.
  - ★ **VÍA: `database/aplicar_054_revertir_anulacion.php`, por SSH — NO phpMyAdmin.**
    Simula por defecto (ensayo real + ROLLBACK); `--confirmar` aplica. El `.sql` tiene
    veredicto y UPDATE como sentencias sueltas, así que pegarlo entero ejecuta el cambio
    **aunque el veredicto salga en rojo** — lección de la 048.
  - La **`053` está RESERVADA** para `cambio_seccion` (ver `docs/modulos/cambio-seccion.md`),
    por eso esta corrección toma la `054`.
- **`052_alias_huerfano_etica_secundaria`** (17/08): corrección de DATOS (no toca esquema).
  Pone en NULL el `alias_boleta` «(Ética y Valores)» del área **Ed. Religiosa de
  SECUNDARIA**. Es el **paso 3 del plan de encendido de Ética del 07/07**, que este archivo
  daba por ejecutado el 05/08 y **nunca se ejecutó** (verificado el 17/08 sobre la copia
  local ya sincronizada con prod).
  ✅ **APLICADA EN LOS DOS ENTORNOS EL 17/08/2026**, con la salida capturada en cada uno
  (local por la mañana; **PRODUCCIÓN a las 12:12:34** hora de Lima, vía SSH).
  - **Evidencia capturada EN PROD** — huella del PASO 0: `u761410128_siga_cociap` ·
    `u761410128_ktcdev@localhost` · `br-asc-web1308.main-hosting.eu` · **MariaDB
    11.8.8-log** · Linux · `/var/lib/mysql/`, o sea la misma huella que se capturó el 10/08
    con el snapshot de B2. **Es lo que convierte esto en una verificación de PROD y no de
    una copia** (regla de la 048).
  - **Recorrido completo allí:** ensayo previo a las **12:08** con ROLLBACK (`PUEDE_LIMPIARSE`,
    1 fila, idempotencia 0, y la conexión nueva confirmando que el alias volvía) → corrida
    definitiva con `--confirmar` a las **12:12** → **COMMIT** → PASO 4 **en conexión nueva**:
    `alias_actual=NULL`, veredicto `YA_LIMPIO`, áreas con alias **3 → 2**, y el área 24
    (Tutoría TOE) intacta con su `nombre_boleta='Ética y Valores'` +
    `alias_boleta='(Educación Religiosa)'` y sus 11 cargas. `codigo_siagie` siguió en `035`.
  - **El pendiente era real en prod:** allí también estaba el alias puesto y las mismas 3
    áreas con alias, o sea que el paso 3 del plan de encendido tampoco se había ejecutado
    en producción. No era una divergencia de la copia local.
  - 🔎 **El `NOT EXISTS` se comporta igual en MariaDB 11.8 que en 10.4** — verificado en el
    ensayo sobre la propia producción, no supuesto. Era la duda legítima que dejó la 050:
    un ensayo local prueba la LÓGICA, no el plan del optimizador.
  - ★ **VÍA: `database/aplicar_052_alias_huerfano.php`, por SSH — NO phpMyAdmin.**
    Simula por defecto (ensayo real + ROLLBACK) y escribe con `--confirmar`, igual que
    `sincronizar_roster_snapshot.php`. **Evita las tres trampas ya documentadas:** imprime
    la **huella del servidor** (la lección de la 048: la salida del veredicto es idéntica en
    los dos entornos y no prueba dónde se ejecutó), el contador sale del propio UPDATE (en
    phpMyAdmin `ROW_COUNT()` da 0), y no depende de que phpMyAdmin haya seleccionado la base
    correcta. Y sobre todo **aborta de verdad**: pegar el `.sql` entero ejecuta el PASO 2
    aunque el PASO 1 salga en rojo, porque son sentencias sueltas — que es exactamente lo
    que pasó al aplicar la 048.
  - ✅ **PROBADO EN SUS CUATRO RAMAS EN LOCAL (17/08/2026)**, siguiendo la regla de que una
    guarda nueva se prueba bloqueando **y** dejando pasar:
    1. `YA_LIMPIO` → no toca nada, sale 0 (idempotencia).
    2. `PUEDE_LIMPIARSE` sin flag → aplica, mide, **revierte**; la conexión nueva confirma
       que el alias volvió.
    3. `PUEDE_LIMPIARSE --confirmar` → COMMIT; la conexión nueva confirma que persistió.
    4. `NO_LIMPIAR_TIENE_CARGAS` → **aborta con exit 1 incluso con `--confirmar`**. Se probó
       creando una carga temporal sobre el área y borrándola después; local quedó verificada
       de vuelta en su línea base (433 cargas, 2 alias, snapshot de B2 en 520).
  - **Impacto real: cosmético.** El área tiene **0 cargas y 0 notas**, así que ese alias no
    se imprime en ninguna boleta. Lo que corrige es la **divergencia entre el dato y lo que
    la documentación afirma**, en el catálogo que `/admin/actas-siagie/vinculos` existe para
    no esconder.
  - **Anclada por `nombre` + `nivel_id`, NUNCA por id** (el id 14 es de esta copia). El
    matcher del nombre va en **ASCII** (`LIKE 'Educaci_n Religiosa'`) para no depender de
    que el cliente mande la tilde en UTF-8 — ese fallo ya ocurrió de verdad al ensayar la
    **050**.
  - **Guard duro:** `NOT EXISTS` sobre `cargas_academicas`. Si esa área llegara a tener
    cargas, su alias dejaría de ser huérfano y la migración es un no-op — coherente con el
    invariante de `CLAUDE.md` de que debe seguir **sin cargas**.
  - **NO toca** el área de Ed. Religiosa de PRIMARIA (que se dicta con normalidad) ni el
    área de **Tutoría (TOE)**, cuyo par `nombre_boleta='Ética y Valores'` +
    `alias_boleta='(Educación Religiosa)'` **sí** es el vínculo válido de la asignatura.
    Tampoco toca `codigo_siagie` (sigue en `035`, el vínculo `035-EREL`), `tipo` ni
    `activa` — el área **se queda activa**: desactivarla se probó y se descartó el 10/08.
  - Idempotente (2.ª corrida = 0 filas, verificado) y reversible con el PASO 4. Ensayada en
    transacción con ROLLBACK antes de aplicarla, con el control de que primaria y TOE no se
    mueven.
- **`051_limpieza_bloqueos_transversales_fantasma`** (06/08): corrección de DATOS (no toca
  esquema). Borra los bloqueos transversales que el **cierre forzado** creó en **B2** sobre
  cargas que ningún docente puede bloquear: **46 en 23 cargas TOE + 84 en 42 cargas
  no-dueñas** de secciones unidocentes = **130**, con **CERO olvidos reales**.
  ✅ **APLICADA EN PRODUCCIÓN EL 06/08/2026**, después del deploy `cf8bdb2` que subió el
  fix F1 (el orden se respetó: sin F1 arriba, el siguiente cierre los recrearía).
  **NO aplicada en local a propósito**: allí siguen los 130 para poder reproducir el
  escenario.
  - **Evidencia capturada EN PROD** (huella `u761410128_siga_cociap` · Linux ·
    **MariaDB 11.8.8**): PASO 1.b **46/23 + 84/42 y C_OLVIDO_REAL sin ninguna fila** ·
    1.c **0 notas / 0 criterios** colgando · 1.d 690 y 23 → PASO 2 **"130 filas
    eliminadas"** + COMMIT → PASO 3 en conexión nueva: **0/0/0**, 690 y 23 **intactos**,
    **0** notas sin bloqueo y B1 en **774**.
  - 🔎 **UNA HIPÓTESIS QUE LOS HECHOS DESMINTIERON.** Antes de aplicarla se advirtió que
    en prod podía no haber **nada** que borrar, razonando que B2 seguía ABIERTO y que los
    fantasmas los crea el cierre. **FALSO: estaban los 130, exactamente los mismos que en
    local.** **Lección: el estado ACTUAL de un periodo no dice nada sobre los procesos que
    ya corrieron sobre él; eso solo lo responden los datos.**
    - 🔴 **CORREGIDO EL 07/08/2026 — LA EXPLICACIÓN QUE SE DIO AQUÍ ERA FALSA.** Se
      concluyó que "en prod el cierre forzado de B2 sí llegó a correr y el bimestre se
      reabrió después". **No fue el cierre: fue el HITO A.** Medido con local ya
      sincronizada con prod: `periodos.boletas_aprobadas_en` de B2 =
      **`2026-08-05 20:09:33`**, `estado='activo'`, **sin ninguna fila de B2 en
      `orden_merito_snapshot`** y **sin reaperturas posteriores al 16/06** (reabrir exige
      motivo y SIEMPRE deja fila en `reaperturas_periodo`). **B2 nunca se ha cerrado.**
    - **Por qué el error era fácil:** `bloquearCompetenciasPendientes` tiene **dos**
      llamadores, no uno — `cerrar()` y `aprobarBoletasBimestre()` (**Hito A**). El Hito A
      fuerza los MISMOS bloqueos con `origen='cierre'` pero **deja el periodo `activo`**, y
      `anularAprobacionBoletas` lo revierte **sin tocar los bloqueos** (lo dice su propio
      comentario). Cronología que encaja al segundo: Hito A el **05/08 20:09** → nacen los
      130 → F1 a producción el **06/08** (`cf8bdb2`) → la `051` los limpia.
    - **La conclusión operativa NO cambia** (F1 antes que la 051, y los 130 fuera), pero la
      secuencia documentada vigilaba la puerta equivocada: **pulsar el Hito A también los
      recreaba**. Con F1 en prod ya no. El runbook incorpora el Hito A en su **Fase 0.2**,
      con la consulta que distingue un Hito A de un cierre real.
  - ⚠️ **`SELECT ROW_COUNT()` DEVUELVE 0 EN phpMyAdmin y NO significa que el DELETE
    fallara.** Ejecuta las sentencias por separado, así que el contador ya no refleja al
    DELETE. La cifra buena es la del propio DELETE (**"130 filas eliminadas"**) y quien
    manda es el PASO 3. Pasó tal cual al aplicarla. **Vale para toda migración futura que
    copie este patrón.**
  - **Aborta** si aparece un solo `C_OLVIDO_REAL` (sería un bloqueo legítimo) o si alguna
    de esas cargas tiene notas o criterios transversales colgando.
  - Ancla el periodo por `numero = 2` + año activo, **nunca por `id`**. **B1 no se toca**
    (decisión del usuario), aunque 84 de sus forzadas sean el mismo defecto.
    Idempotente. Reversible con el PASO 4.
  - Ver `docs/modulos/transversales-visibilidad-tutor.md` §5.
- **`050_etica_b1_extraordinaria`** (06/08): registra **15 (literal A) como CALIFICACIÓN
  EXTRAORDINARIA** de Ética y Valores en el **I Bimestre** a los **275** estudiantes de
  secundaria **que cursaron B1**.
  ✅ **APLICADA EN PRODUCCIÓN el 06/08/2026 a las 20:01:10**, con la verificación posterior
  capturada ALLÍ (la regla que dejó la 048). **NO aplicada en local**, a propósito: local
  queda como copia del estado previo hasta la próxima sincronización.
  - **Evidencia en prod:** 11 criterios · 275 notas de criterio · 275 calificaciones · 275
    marcadas extraordinarias · **0** con nota distinta de 15 · 275 filas de auditoría;
    `MOTIVO OK` (370 caracteres / 378 bytes, sin mojibake); snapshot oficial de B1 **intacto
    en 528 / puestos 1-72 / 11 grados / 23 secciones**; **0** notas que entren al mérito; e
    integridad en **0 · 0 · 0** (ninguna matrícula con dos notas, ninguna nota en carga
    ajena a su sección, ninguna sin bloqueo).
  - **Contraste cruzado independiente:** los pares *bloqueados y vacíos* de B1 bajaron
    **exactamente 11** (890 → 879 con la consulta sin filtrar; equivale a **116 → 105** con
    la definición correcta del runbook). Los 11 pares de Ética dejaron de estar vacíos.
  - **PROCEDIMIENTO QUE FUNCIONÓ, y conviene repetir:** PASO 1 en tres envíos de solo
    lectura → **ENSAYO EN LA PROPIA PRODUCCIÓN** con `START TRANSACTION … ROLLBACK`
    (mismas cifras, sin escribir) → confirmación de que el rollback limpió (0·0·0) y de que
    las 4 tablas son **InnoDB** → envío definitivo idéntico terminado en `COMMIT` →
    verificación **en conexión nueva** (lo único que prueba que el COMMIT persistió; las
    SELECT de dentro del bloque no lo prueban).
  - **Por qué existe:** en B1 nadie evaluó Ética —los tutores no remitieron a tiempo— y por
    acuerdo de dirección se consignó 15 (A) uniforme, **ya cargado a mano en el SIAGIE** en
    las dos competencias de Ed. Religiosa (vínculo `035-EREL`). Esto alinea SIGA con el acta.
  - 🔴 **HALLAZGO GRAVE — B1 SE CERRÓ CON ÉTICA BLOQUEADA Y VACÍA.** El **20/07/2026 entre
    la 01:44:33 y la 01:45:33** se bloqueó la competencia en las **11 secciones** de
    secundaria (`origen='docente'`, 11 filas en 60 segundos) **con CERO notas**, y horas
    después se cerró B1. El sistema quedó afirmando que Ética estaba evaluada y terminada.
    **Ningún indicador del cierre podía detectarlo:** el **termómetro** cuenta pares *con
    notas y sin bloqueo*, así que un par **sin notas** nunca aparece (por eso B1 daba 0); y
    la **alerta de evaluación incompleta** solo aflora un criterio cuando algún compañero de
    sección ya tiene nota en él, así que si **nadie** la tiene no hay con qué comparar.
    Es un punto ciego real del contrato del cierre → refuerza el plan de los 4 registros.
  - **Universo = 275, anclado en "tiene ≥1 nota en B1"**, no en `tipo` ni en ids. Coincide
    **exactamente** con los 275 de secundaria del snapshot oficial de B1, o sea reproduce el
    roster que se congeló. Desglose: 1.º 72 · 2.º 52 · 3.º 47 · 4.º 55 · 5.º 49. Deja fuera
    a **693, 694, 695 y 696** (llegaron entre junio y julio, 0 notas en B1): darles nota
    sería inventarles un bimestre. 0 exonerados en secundaria.
  - **EL SNAPSHOT DE B1 NO SE MUEVE**, por tres vías: `OrdenMeritoModel` filtra
    `extraordinaria = 0` en sus 2 queries; el oficial es inmutable (candado 046); y los
    lectores usan el snapshot, no el vivo. **Verificado en el ensayo: 528 filas, puestos
    1-72, 11 grados, 23 secciones, y 0 notas nuevas entrarían al mérito.**
  - **Replica el flujo real** de `guardarExtraordinaria` (leído línea por línea): criterio
    único por carga (11) → nota de criterio → calificación con `extraordinaria=1` → fila de
    auditoría con el **motivo**, que es el único registro permanente de por qué existen.
    Firma **Registro Académico**, resuelto por rol.
  - ✅ **AUDITADA Y ENDURECIDA EL 06/08/2026, antes de aplicarla en prod.** Se contrastó
    contra `RectificacionModel::esInsertable` (la guarda que el flujo real evalúa alumno
    por alumno) y contra el esquema real. **Los 4 arreglos son no-op en local** —las tres
    variantes del universo dan **275**— así que **no invalidan el ensayo**: solo cierran
    huecos que en PROD sí pueden morder.
    - **El filtro de exoneraciones era GLOBAL por matrícula** y sacaba del universo, sin
      decirlo, a quien estuviera exonerado de **cualquier** área. El código acota por
      `area_id`/`subarea_id` **+ `anio_id`**; la migración ahora también. En local no mordía
      (las 2 exoneraciones vivas son de PRIMARIA, área 5), pero una sola exoneración de
      secundaria en prod habría bajado el universo a 274 sin explicación.
    - **No excluía la matrícula OFICIAL de un retorno de grado activo.** Registrar una nota
      es EVALUAR → Regla A: se evalúa en la **operativa**. Es el mismo anclaje de los 9
      rosters y de `alertasEvaluacionIncompleta`. Hoy el único retorno es de primaria, así
      que no mordía; con un retorno en secundaria habría escrito la nota en la sección donde
      la estudiante ya no cursa, y la boleta **suma las dos fuentes**.
    - **El `uq_nota` NO protege del doble registro**: su clave es (matrícula, **carga**,
      periodo, competencia), así que una sección con dos cargas activas del área daría dos
      notas al mismo alumno sin violar nada (`cargas_academicas` no tiene UNIQUE KEY). El
      PASO 1 exige ahora 1 carga por sección y el PASO 3 lo verifica después.
    - **No verificaba los bloqueos.** La boleta solo muestra competencias **bloqueadas**;
      una carga sin bloqueo habría recibido la nota sin mostrarla — la migración
      "funcionando" sin cumplir su objetivo. Medido en local: 11 cargas, 11 secciones,
      11 bloqueos, 0 sin bloquear.
    - **Firmante:** el anclaje del usuario ahora exige `estado = 'activo'` (un RA dado de
      baja con id menor habría firmado las 275 filas). Y el PASO 1 suma un **1.e** que lista
      con motivo a los excluidos por las guardas nuevas: en prod hay que **leerlo**, cada
      fila es un alumno que cursó B1 y no recibirá la nota.
    - **Re-ensayada entera tras los cambios** (`START TRANSACTION … ROLLBACK`): mismas
      cifras que el ensayo original —11 criterios · 275 · 275 · 275, `MOTIVO OK`, snapshot
      en 528/1-72/11/23— más los 3 checks de integridad nuevos en **0**, y local en 0 tras
      el rollback.
    - ✅ **DESCARTADO UN EFECTO COLATERAL QUE PARECÍA REAL:** el criterio nuevo NO agrava
      los **12 blancos sin motivo de B1** (que hoy impedirían re-cerrarlo si se reabriera).
      `alertasEvaluacionIncompleta` filtra `cr.extraordinario = 0`, así que el criterio
      extraordinario le es invisible; los 4 alumnos que llegaron después de B1 no se suman.
      Verificado en el código, no supuesto.
    - **Efecto medible esperado en el runbook:** los **11 pares bloqueados-y-vacíos de
      Ética** dejan de serlo → el conteo de B1 baja de **116 a 105**. El termómetro de
      bloqueos no se mueve (esas cargas ya estaban bloqueadas).
  - 🔎 **HALLAZGO DEL DÍA DE LA APLICACIÓN — prod y local NO corren la misma MariaDB:**
    local **10.4.32**, prod **11.8.8** (visto con la huella nueva del PASO 1.0). Un ensayo
    en local prueba la LÓGICA, no el plan del optimizador. Por eso el PASO 2 pasó a
    ejecutarse **siempre envuelto en transacción**, también en prod: son 4 INSERT
    dependientes y un fallo a mitad dejaría criterios y notas de criterio sin calificación.
    El patrón `NOT EXISTS (SELECT 1 FROM (SELECT * FROM tabla) x)` se comportó igual en
    11.8 — verificado en el ensayo sobre prod, no supuesto.
  - 🔎 **LA LECCIÓN DE TRAZABILIDAD DE LA 048, RESUELTA:** ningún conteo de DATOS distingue
    local de prod (local es copia fiel: mismas 28 270 notas de B2, mismos ids). La huella
    tiene que ser del **SERVIDOR** — `DATABASE()`, `USER()`, `@@hostname`,
    `@@version_compile_os`, `@@datadir` — y ahora es el **PASO 1.0** de la migración.
    Local: `siga_cociap` · `root@localhost` · **Win64**. Prod: `u761410128_siga_cociap` ·
    Linux · Hostinger.
  - ⚠️ **phpMyAdmin IGNORA `USE`:** reselecciona la base según el contexto de la página. Si
    la pestaña SQL cuelga de `information_schema`, todo se ejecuta allí y las consultas
    fallan con `#1109 Tabla desconocida`. Pasó durante esta aplicación. Se arregla entrando
    a la base desde el panel izquierdo y se comprueba con `SELECT DATABASE()`. No es un
    riesgo de escritura: el bloque falla en su primera sentencia y la transacción queda
    vacía.
  - ✅ **ENSAYADA ENTERA EN LOCAL con `START TRANSACTION … ROLLBACK`** (no hay DDL, a
    diferencia de la 048): **11 criterios · 275 notas de criterio · 275 calificaciones,
    todas marcadas y ninguna distinta de 15 · 275 filas de auditoría**, y local quedó en 0
    tras el rollback. **Reversible** con el DELETE acotado del PASO 4.
  - 🔴 **EFECTO VISIBLE INMEDIATO:** B1 está **publicado**, así que al aplicarla la boleta
    digital por token de B1 muestra el 15 a las familias. **No existe forma de registrar el
    dato y diferir su visibilidad.** Decisión del usuario: no hace falta reimprimir el papel
    de B1; basta con que salga en la boleta que se entregue en B2 (documento anual).
  - ⚠️ **Dos trampas encontradas AL ENSAYARLA**, ambas corregidas y anotadas en el archivo:
    - **Anclar el rol por `= 'Registro Académico'` falla si el cliente no envía la tilde en
      UTF-8** → el anclaje resuelve NULL y la migración inserta 0 filas sin decir por qué.
      Pasó de verdad en el primer ensayo. Ahora usa `LIKE 'Registro Acad%'` (ASCII).
    - **El detector de mojibake del motivo daba FALSO POSITIVO:** `motivo` es
      `utf8mb4_unicode_ci`, la colación que equipara **Ã con A** —la misma que hacía Ñ ≡ N
      en el orden alfabético—, así que `INSTR` encontraba la primera 'a'. La comparación va
      ahora **sobre bytes** (`CONVERT(... USING binary)`). Probado en ambos sentidos.
- **`048_limpieza_backups_conducta_541`** (05/08): retira las dos tablas de respaldo que
  dejó la limpieza quirúrgica de conducta del 24/07 (`_bkp_conducta_resp_541` con 10 filas
  y `_bkp_calif_conducta_541` con 0). **La condición acordada se cumplió**: la conducta de
  B2 de su sección (18) está cerrada en sus DOS etapas — cierre id 33, `ra_bloqueado_en`
  24/07 16:14 y `tutor_cerrado_en` 31/07 12:32, sin anular.
  Trae **PASO 1 de verificación (solo lectura) que debe devolver `PUEDE_BORRARSE`**, el
  `DROP` y una verificación posterior. Idempotente (`IF EXISTS`).
  🔴 **IRREVERSIBLE y NO probable con rollback**: `DROP TABLE` es DDL y MySQL hace commit
  implícito. Por eso el archivo insiste en exportar antes (phpMyAdmin → Exportar las 2
  tablas; `mysqldump` solo si hay shell).
  ✅ **APLICADA EN LOS DOS ENTORNOS el 06/08/2026, con evidencia del PASO 3 en cada uno.**
  Se aplicó **primero en LOCAL y después en PROD** (ver la lección de trazabilidad, abajo).
  - **LOCAL** — PASO 3 en **0 filas**; esquema en 51 tablas y **ninguna empieza por `_`**.
    Sin daño colateral: la conducta de la 541 conserva su calificación de **B1 (`AD`)**,
    sus respuestas siguen en 0 (lo esperado desde la limpieza del 24/07), el cierre 33
    intacto y el volumen global sin moverse (5240 respuestas · 1052 calificaciones · 46
    cierres, todos vivos). Al re-ejecutar el PASO 1 sigue dando `PUEDE_BORRARSE` y la 2.ª
    consulta falla con `Table doesn't exist`: **la señal esperada de que el PASO 2 corrió**.
  - **PROD** — el PASO 1 devolvió 1 fila, `PUEDE_BORRARSE`, matrícula 541 / DNI 63361405
    (RODRIGUEZ MENDEZ, GUSTAVO CHRISTIAN), sección 18, `trasladado`, cierre id 33,
    `ra_bloqueado_en` 2026-07-24 16:14:04 y `tutor_cerrado_en` 2026-07-31 12:32:54, sin
    anular. **La constancia dio 10 y 0 filas**, o sea que allí los respaldos seguían vivos
    hasta ese momento; los dos `DROP` se ejecutaron sin error y el **PASO 3 devolvió 0
    filas**.
  - ⚠️ **LECCIÓN DE TRAZABILIDAD — SE MATERIALIZÓ, no es hipotética.** La salida del PASO 1
    es **idéntica en local y en prod** (local es copia de prod: mismos ids y mismas fechas
    al segundo), así que **no sirve para saber contra qué entorno se ejecutó**. Por eso la
    primera corrida se dio por hecha en prod cuando en realidad había caído en **local**, y
    se detectó por un camino indirecto: local ya no tenía las tablas y la constancia de la
    corrida siguiente devolvió 10 y 0 —prueba de que ESE entorno aún las tenía—.
    **Regla: una migración solo se da por aplicada en un entorno cuando se captura su
    PASO 3 ALLÍ**; el veredicto previo no identifica el entorno. Aplica a la `049` y a
    todas las que vengan.
  - ⚠️ **El archivo se ejecutó ENTERO de una pasada** en phpMyAdmin (PASO 1 + 2 + 3 en la
    misma corrida), que es exactamente lo que su propia cabecera advierte que no hay que
    hacer. Salió bien **porque el veredicto era verde**; con un `NO_BORRAR` el `DROP` se
    habría ejecutado igual. La advertencia sigue en pie para la próxima.
  - **Endurecimiento del PASO 1 (06/08, commits `221440f` y `df186f2`), hecho ANTES de
    aplicarla:** juzgaba por `matriculas.id = 541`, contra la regla de anclar por DNI. Un
    id que apuntara a otro estudiante habría devuelto un veredicto **válido sobre la
    sección equivocada**, y un id inexistente devolvía 0 filas, que se lee como "no aplica"
    en vez de "detente". Ahora exige `id AND dni`, devuelve la identidad junto al veredicto
    y define 0 filas como aborto. Además: `ORDER BY anio DESC` en el año activo (`LIMIT 1`
    era no determinista), `LIKE '\_bkp%'` escapado en el PASO 3 (el guion bajo es comodín)
    y el aviso de que **el PASO 1 no protege al PASO 2** — son sentencias sueltas y pegar
    el archivo entero ejecuta el `DROP` igual; no es automatizable porque DDL hace commit
    implícito.
  - **Auditoría previa (06/08, solo lectura):** 0 claves foráneas apuntando a los
    respaldos, **ningún código de la aplicación los lee** (`_bkp` solo aparece en el
    propio `.sql` y en dos docs) y ninguna otra tabla del esquema empieza con `_`. El
    borrado no podía romper nada en runtime: 10 filas, 16 KB.
  - **Aclaración que costó una falsa alarma:** la 541 **sí** tiene una fila viva en
    `calificaciones_conducta`, pero es del **I Bimestre** (literal AD, 23/05). La limpieza
    del 24/07 fue de B2, y por eso `_bkp_calif_conducta_541` estaba en 0. Quedó anotado en
    la cabecera del `.sql` para que nadie repita la investigación.
- **`047_retorno_grado_asistencia_solapada`** (05/08): corrección de DATOS (no toca
  esquema). Borra la fila de `inasistencias` que quedó en la matrícula **OFICIAL** de
  un retorno de grado cuando la **OPERATIVA** ya tiene fila del mismo bimestre. Con
  el solape, `getDelBimestreUnion` —que SUMA las dos fuentes— mostraba el **doble de
  inasistencias** en la boleta (caso real: 4 faltas en vez de 2 en B2). Ancla por el
  vínculo `retornos_grado`, **no por ids**; se autolimita al solape y exige
  `p.fecha_fin >= r.fecha_retorno`, así que **no puede** tocar un bimestre anterior al
  retorno. Idempotente (verificada con 2 corridas: 1 fila y luego 0). Probada
  ejecutando el archivo real en transacción con ROLLBACK.
  **APLICADA EN LOCAL Y PROD** (local verificado el 05/08: `inasistencias` pasó de 1053
  a 1052 y la fila de la matrícula oficial en B2 ya no existe; **PROD el 05/08/2026,
  confirmado por el usuario**, antes del merge `dev`→`main` que desplegó el lote de
  boleta/borradores/exoneraciones).
  Ver `docs/modulos/retorno-grado.md`.
- **`046_orden_merito_inmutable`** (24/07): Fase B del rediseño del orden de mérito.
  Additiva: `periodos_publicacion.primera_publicacion_en` (marca monotónica de primera
  publicación, backfill de lo ya publicado) + tabla nueva `orden_merito_rectificado`
  (versión no oficial del ranking). No altera datos existentes. Idempotente
  (`ADD COLUMN IF NOT EXISTS` + `CREATE TABLE IF NOT EXISTS`). **APLICADA EN LOCAL
  Y PROD** (prod el 25/07/2026, importada a mano por phpMyAdmin ANTES del merge
  `dev`→`main` que desplegó el código de las Fases A y B). Ver
  `docs/modulos/orden-merito.md`.
- **`045_matriculas_tipo_retirado`** (22/07): agrega `'retirado'` al enum
  `matriculas.tipo`. Marca al estudiante que YA NO ASISTE pero no se trasladó
  oficialmente (sin constancia ni IE destino; la familia espera que regrese). Los
  9 rosters de evaluación (calificaciones, conducta ×5, resumen/bloqueo,
  transversales, tutoría) pasan de `!= 'trasladado'` a
  `NOT IN ('trasladado','retirado')`; boleta/mérito/SIAGIE NO cambian (un retirado
  es desactivado no-trasladado → BORRADOR, fuera de mérito/export). Reversible vía
  `tipo_anterior`. **EN PROD** (22/07, importada a mano ANTES del merge `dev`→`main`
  `10d6d51`). Idempotente (`MODIFY`). Ver `docs/modulos/matriculas.md`.
- **`044_periodos_publicacion`** (21/07): COMPUERTA DE PUBLICACIÓN DE BOLETAS.
  Crea `periodos_publicacion` (periodo × nivel → `publica_en`, con suspensión
  reversible por reapertura y despublicación manual definitiva con motivo) y
  hace el **backfill retroactivo obligatorio** de todo bimestre ya cerrado.
  Cerrar un bimestre ya NO publica sus boletas. Idempotente (verificada con
  2 corridas). Regla completa en `docs/modulos/boletas.md`.
  **APLICADA EN LOCAL Y PROD.** En prod se importó a mano (phpMyAdmin) el
  **22/07/2026**, ANTES del merge `dev`→`main` que desplegó el código — así el
  código nuevo nunca corrió sin su tabla. Backfill verificado (B1 sigue visible).
- ✅ **LOS DOS ENTORNOS AL DÍA HASTA LA `051` (verificado en local el 07/08/2026).** La 047
  el 05/08, la 048 el 06/08 en ambos, la 051 en prod el 06/08 y en local el 07/08. La
  **`050`, que estuvo un día solo en prod, YA ESTÁ TAMBIÉN EN LOCAL**: el usuario
  resincronizó la copia. **Huella medida en local** (`siga_cociap` · `root@localhost` ·
  PROBOOK450 · MariaDB 10.4.32 · Win64): **275 extraordinarias de nota 15 en B1** · 11
  criterios extraordinarios · 275 filas de auditoría · B1 pasó de 12 047 a **12 322**
  calificaciones (+275 exactas) · **0 tablas `_bkp`** (048) · transversales de B2 en **690
  `docente` y 0 `cierre`** (051).
  - ⚠️ **Queda obsoleta la regla "una medición local de Ética en B1 da 0 y no es un
    error"** — ahora da 275 en los dos entornos. **Antes de reportar cualquier divergencia
    entre local y la documentación, comprobar la frescura de la copia con un marcador de
    migración**: el 07/08 se reportó como "error de documentación" un `limite_notas` de
    B2 distinto, y era simplemente que la copia local llevaba dos días de retraso. El valor
    bueno es el que dice el doc: **`2026-08-04 23:59`**.
  🔴 **La `049` quedó LIBERADA el 10/09/2026 y es un HUECO PERMANENTE: no reutilizar ese
  número.** Estaba reservada para `calificaciones_retroactivas`, del plan de registro
  retroactivo, y ese plan quedó **derogado**: la funcionalidad se construyó con otro diseño
  y otras dos migraciones, la **`057`** (`notas_externas.area_id`) y la **`058`**
  (`notificaciones`). Se deja el hueco a propósito, porque reciclar el número haría que las
  entradas viejas de este archivo —que hablan de la `049` como «la del registro
  retroactivo»— apuntaran a otra cosa.
  ⚠️ **la 050 y la 051 se numeraron antes que la 049 a propósito**: son independientes y
  corrían primero. Al aplicarlas, el orden lo manda la dependencia, no el número: la `051`
  exigía que el fix F1 estuviera **antes** en producción, y así se hizo (deploy `cf8bdb2`
  y después la migración).
- **LOCAL y PROD: al día hasta la `045`.** En prod: 038-043 el 20/07/2026, 044 y
  045 el 22/07/2026, 034-037 el 09/07/2026. En local la `043` (`cierres_asistencia`) se
  había saltado al aplicarse suelta; se corrió el **22/07/2026** (estructura
  verificada idéntica a la migración) y local quedó igualado a prod.
  Con esto quedan desbloqueados en prod: reprocesar las actas SIAGIE de
  4°A/4°B B1 (ver Pendientes operativos) y la calificación extraordinaria.
- **`043_cierres_asistencia`** (17/07): crea `cierres_asistencia` (una sola
  etapa: RA bloquea; anulable con traza). Soporte del historial de bimestres y
  del imprimible oficial de Conducta/Asistencia (ver `docs/modulos/admin.md`).
- **`042_calificacion_extraordinaria`** (16/07): `criterios.extraordinario`,
  `calificaciones.extraordinaria` y `rectificaciones_calificacion.tipo`.
  Soporte de la CALIFICACIÓN EXTRAORDINARIA: RA registra nota (con motivo) a un
  alumno sin calificación en competencia cerrada/bloqueada, desde Rectificación.
  Va a boleta y SIAGIE; NO cuenta en el orden de mérito. Idempotente; verificada
  end-to-end en local (25 checks, Inglés 4°A C2 B1). Ver
  `docs/modulos/calificaciones.md` y `docs/modulos/orden-merito.md`.
- **`041_areas_codigo_siagie_primaria`** (16/07): puebla `areas.codigo_siagie`
  para PRIMARIA (los códigos NO son los de secundaria: Inglés `0003`, COMU `0005`,
  PPSS `067`; transversales `0006,0007`; CAST SEGNL y Tutoría sin código a
  propósito). Habilita el fallback por posición del exportador SIAGIE también en
  primaria (causa raíz de las actas 4°A/4°B B1 con Inglés en blanco). Además
  FORMALIZA el rename de Inglés C1 primaria al nombre oficial CN (aplicado a mano
  el 14/07 en local+prod; en ambas es no-op, corrige solo en setups desde cero).
  Idempotente; validada con `--simular` sobre el acta real de 4°A B1 (reporte
  byte-idéntico pre/post migración). Ver `docs/modulos/export-siagie.md`.
- **`040_notas_autorizadas_siagie`** (14/07): crea `notas_autorizadas_siagie`
  (matricula+competencia+periodo → literal + conclusión + resolución, UNIQUE).
  "Informe aparte" de notas que dirección autoriza para un alumno NO evaluado por
  ausencia justificada, VÁLIDAS SOLO PARA EL SIAGIE (no tocan `calificaciones`,
  boleta ni orden de mérito). El export las usa solo para rellenar la celda en
  blanco de una competencia bloqueada. Idempotente. Ver
  `docs/modulos/export-siagie.md` y `docs/modulos/matriculas.md`.
- **`039_areas_codigo_siagie`** (12/07): agrega `areas.codigo_siagie` y lo puebla
  para SECUNDARIA (mapeo hoja→área del exportador SIAGIE; transversales `0006,0007`).
  Corrige el `nombre_siagie` erróneo del Taller Raz. Mat. Primaria queda NULL a
  propósito (mantiene su matching global validado). Idempotente. Ver
  `docs/modulos/export-siagie.md`.
- Migraciones más recientes (034-037): `034_purga_docente_duplicada`,
  `035_area_etica_boleta`, `036_competencia_etica_valores` (crea C57, interruptor
  de Ética), `037_consolidar_docentes_duplicados`. Todas en LOCAL y PROD.
- **`038_matriculas_traslado_entrada_pendiente`** (09/07): corrige 6 matrículas
  mal registradas en el registro masivo. 4 pasan a `pendiente` (para exigir
  documentos); de esas, 3 además a `tipo='nuevo'` (traslado de entrada) y 1 se
  mantiene `continuador`. Ancla por DNI + año activo + guarda `estado='aprobada'`
  (portable e idempotente). Verificada en local (4 filas; reintento 0/0). NO
  escribe motivo_estado. **APLICADA EN PROD** (20/07/2026, dentro del lote 038-043;
  ver el punto "LOCAL y PROD: al día" y la sección Git).
- Orden completo de setup desde cero: ver `docs/infraestructura.md`.
- OJO al crear un año académico nuevo: `getOrCreateConfiguracion` inserta
  `duracion_hora_min = 50` por defecto; el año 2026 usa 45.

## Pendientes de desarrollo
- ✅ **Rediseño 2 del orden de mérito — EN PRODUCCIÓN (deploy del 04/08/2026, `de449e2`).**
  Implementado y probado el 26/07. Las 6 fases + una fase extra (F5b) y varios fixes; **sin
  migración nueva**, así que el deploy fue merge + push sin tocar la BD de prod.
  Qué hace cada fase, las desviaciones respecto del plan y los efectos colaterales
  aceptados: `docs/modulos/orden-merito-rediseno.md` **§8** (manda esa sección, no las
  §1-5, que son el plan original). Estado vigente del módulo: `orden-merito.md`.
  Diferencia consciente con el diseño: el cierre **no** valida "0 competencias sin
  bloquear" (P3) porque él mismo las fuerza.
  - Se desplegó el 04/08/2026 con las dos condiciones duras **en verde** (ver "Cierre de
    B2 — SECUENCIA CORRECTA").
- **Efecto colateral del guard P4 (VIGENTE EN PRODUCCIÓN desde el 04/08/2026) — REABRIR
  UN BIMESTRE YA CERRADO ES UNA PUERTA DE UN SOLO SENTIDO.** `cerrar()` exige
  ahora `alertasEvaluacionIncompleta = 0`, y esa alerta se evalúa sobre bimestres
  `activo`. Un bimestre que se cerró ANTES de que existiera el guard puede no
  cumplirlo: **B1 tiene hoy 12 alumnos con blancos sin motivo**, así que reabrirlo lo
  dejaría imposible de re-cerrar hasta resolverlos uno a uno (nota u omisión desde el
  módulo del docente). No es un defecto —es la regla funcionando— pero es una
  restricción que antes no existía y que está activa en producción desde el 04/08.
  Antes de reabrir B1 (p. ej. para una rectificación), medir primero con
  `alerta_evaluacion_incompleta.sql` cambiando a `@periodo := 1`.
- **La superficie de mérito para familias entra OSCURA (medido el 04/08/2026):** en
  prod hay **0 usuarios con rol Padre** (35 docentes, 1 admin, 1 registro académico,
  1 Director EBR). `/padre/orden-merito` y `/padre/ranking-seccion` (fase 6) solo son
  alcanzables por admin/RA, que están en el `requireRole` del controlador. Baja el
  riesgo del deploy, pero significa que **la parte más nueva del lote no la va a
  estrenar nadie** hasta que exista el módulo de logins para apoderados.
- **Compuerta de publicación: EN PRODUCCIÓN desde el 22/07/2026** (migración 044
  + merge `dev`→`main` `dca4023`). Cerrar ya no publica; se publica por nivel con
  fecha/hora desde `/admin/control`. Regla, decisiones y verificación en
  `docs/modulos/boletas.md`. El diseño viejo de `docs/decisiones-diferidas.md`
  (`periodos.publicado`) quedó OBSOLETO: no alcanzaba un booleano.
  - ✅ **~~Pendiente relacionado~~ — CERRADO EL 10/08/2026. La decisión #9 queda
    CANCELADA y la afirmación que la sostenía era FALSA.** Este pendiente decía que el
    **logro anual** usaba "último bimestre cerrado" y debía exigir **año académico
    cerrado**. El código **nunca** hizo eso: `BoletaModel::getUltimoBimestreDelAnio` toma
    el periodo de **mayor `numero`** y exige que **ese** esté cerrado (su comentario lo
    dice literal: *"NO es el último bimestre CERRADO"*). Estuvo mal documentado desde el
    21/07 en este archivo y en `docs/modulos/boletas.md`.
    - **Regla definitiva del usuario (10/08/2026):** el logro anual **solo se activa y
      muestra el logro del IV Bimestre**. Es el comportamiento vigente → **nada que
      implementar**. Anclaje por **último periodo del año** (no por el número 4 literal) y
      disparador **B4 cerrado**, sin exigir publicado. Sin fuga: `literal_final` se calcula
      sobre datos ya filtrados por la compuerta 044 (verificado en el código).
    - ✅ **La cola que quedaba abierta se cerró el MISMO DÍA con una regla de negocio
      nueva** (ver abajo): no se toca el cálculo del anual, se **exige plan completo en el
      periodo final**.
- **EL PERIODO FINAL EXIGE TODAS LAS COMPETENCIAS — REGLA DE NEGOCIO APROBADA, SIN
  IMPLEMENTAR (10/08/2026). Fecha tope: antes del 05/10/2026** (inicio del IV Bimestre).
  Regla completa, cifras y trampas: **`docs/modulos/calificaciones.md`** §"REGLA DE
  NEGOCIO — autonomía del docente y periodo final".
  - **En B1-B3 el docente es autónomo** (elige qué competencias evalúa, mínimo una, y puede
    cambiarlas entre bimestres). **En el ÚLTIMO periodo pierde esa autonomía:** la carga no
    está completa hasta tener **todas** sus competencias académicas **y transversales**.
  - **Decisiones cerradas (no re-preguntar):** universo = propias + transversales de la
    carga; **basta ≥1 nota** por competencia (los huecos por alumno los sigue cubriendo
    `alertasEvaluacionIncompleta`, son reglas complementarias); se hace cumplir **en dos
    sitios** (impedir el bloqueo vacío **y** abortar el cierre); **válvula = REGISTRO
    ACADÉMICO con motivo específico**, no el director.
  - **Nace para cerrar el punto ciego del logro anual:** como el anual sale solo del último
    periodo, una competencia no evaluada allí dejaba al alumno sin logro anual pese a
    haberla cursado todo el año.
  - **Dimensionado con B2 como ensayo:** **61 pares vacíos de 1283** y **39 cargas de 398
    (9.8 %)** incompletas — **0 transversales vacías**, todo académico y concentrado en
    Personal Social de primaria. El "mínimo una" **ya se cumple al 100 %** (los únicos
    ceros son las 12 TOE de primaria, con 0 competencias por diseño).
  - 🔴 **El guard de transversales sería la QUINTA copia de la regla de "carga dueña"**
    (la cuarta divergente creó los 130 fantasmas) → **extraer el punto único, F1 del plan
    de los 4 registros, ANTES de escribirlo**.
  - ⚠️ **Contar por `competencias.area_id` da cifras falsas** (las de subárea tienen
    `area_id` NULL: 1283 → 1020, 263 pares perdidos en silencio). Pasó al medir esta regla.
- **LOS 4 REGISTROS DEL BIMESTRE Y EL CONTRATO DEL CIERRE — PLAN APROBADO, SIN
  IMPLEMENTAR (04/08/2026).** Se ejecuta **después de cerrar y publicar B2**, para que
  el primer bimestre bajo las reglas nuevas sea B3. Plan completo con fases, riesgos y
  preguntas abiertas: **`docs/modulos/cierre-cuatro-registros.md`**.
  - **Origen:** al verificar la regla del colegio ("los 4 registros aprobados y
    bloqueados antes de cerrar") se descubrió que **conducta y asistencia están fuera
    del contrato del cierre** (ni se exigen ni se fuerzan), y que la compuerta temporal
    está escrita **5 veces en 3 regímenes distintos** (3 en PHP + 2 columnas SQL) —
    transversales no tiene ninguna.
  - **Decisiones cerradas del usuario (no re-preguntar):** D1 el cierre **EXIGE**
    (aborta) conducta y asistencia bloqueadas —académicas y transversales se siguen
    forzando—; D2 `limite_notas` sigue siendo **una sola fecha**, sin migración;
    D3 transversales **sí** pasa a respetar la compuerta; D4 **no** se puede bloquear
    una sección de asistencia sin ninguna fila registrada; **D5** (04/08) el universo del
    guard es **TODAS las secciones del año** (sin filtrar por tutor ni por nómina);
    **D6** (04/08) conducta se exige con **las dos etapas** (`ra_bloqueado_en` **y**
    `tutor_cerrado_en`).
  - **Sin migración.** Orden: F1 punto único → F3 guard de sección vacía → F2 guard del
    cierre → F4 transversales (esta última **exige avisar antes a los tutores**).
  - **Revisión del plan (04/08, mismo día):** el riesgo de los periodos `pendiente` en F1
    quedó **descartado con evidencia** (un `pendiente` nunca llega a
    `periodoEstaBloqueado`); D5 **obliga a SQL nuevo** para el guard (los dos resúmenes
    existentes no recorren el universo canónico); F3 **no puede** reusar
    `getProgresoPorSeccion` (filtra `m.estado='aprobada'`); y en local
    `cierres_asistencia` está **vacía**, así que el escenario de prueba hay que
    construirlo. Detalle en el doc del plan.
- ✅ **BOLETA CON TODAS LAS COMPETENCIAS DEL PLAN — EN PRODUCCIÓN (implementada y
  verificada en local el 05/08/2026, desplegada ese mismo día en `c8681da`). Sin
  migración.** La boleta lista **todas** las
  competencias que la sección dicta, tengan o no nota, con **guion** donde no hay dato.
  Qué se construyó, trampas y cifras: **`docs/modulos/boleta-competencias-completas.md`
  §10** (manda esa sección; §1-§7 son el plan original). Regla del módulo en
  `docs/modulos/boletas.md`.
  - **El universo son las CARGAS ACTIVAS de la sección**, y eso produce **solas** las
    tres exclusiones que pidió el usuario, sin ninguna excepción hardcodeada: sin
    Ed. Religiosa en secundaria (la evalúa Ética y Valores), y 5.º sin Arte y Cultura ni
    EPT. **Regalo medido:** el Taller de Pre-Cálculo solo se dicta en 5.º, así que
    tampoco sale en 1.º-4.º. Primaria: 0 huecos, y ahí Ed. Religiosa **sí** se muestra.
  - **Decisiones del usuario (no re-preguntar):** en un retorno de grado el plan sale de
    la matrícula **OPERATIVA**; la conclusión descriptiva **también lleva guion**; aplica
    a la boleta **impresa y digital** (la digital no necesitó cambios).
  - **Resultado:** primaria 27 competencias/9 áreas · secundaria 1.º-4.º 29/12 · 5.º
    27/11. El nº de filas ya **no varía entre alumnos de la misma sección**. Equivalencia
    probada sobre **1943 filas de nota, 0 perdidas**; retorno #1 probado (0 perdidas).
    Verificación: `verif_plan_completo_boleta.php` (solo lectura, corre en prod).
  - ⚠️ **Regresión que vigila la verificación:** los **exonerados** perdían el `EXO` (con
    el esqueleto sembrado, `inyectarEnAreas` caía siempre en su rama `else`). Corregido.
  - ⚠️ **Defecto preexistente corregido de paso:** las vistas separaban el bloque
    transversal buscando `'transversal'`, pero el área de **secundaria** se rotula
    `Comp. Transv.` → en secundaria nunca se movía al final ni recibía su estilo (y
    quedaba **antes** de Ética, `orden 90`). Ahora se detecta por `'transv'`.
  - ⚠️ **Contrapartida del universo por cargas:** un área sin carga por olvido
    desaparecería del documento en silencio. El bloque 1 de la verificación lo vigila.
  - **La CONDUCTA también lleva guion** en sus celdas vacías (05/08). Numeral y
    conclusión no le aplican —es siempre literal—, así que van con guion permanente.
  - ✅ **EL SELLO DEL DIRECTOR YA NO APARECE EN BORRADOR NI EN VISTA PREVIA
    (07/08/2026, en `dev`, una línea, sin migración y sin SASS).** Decisión del usuario al
    revisar la boleta digital: **jamás** en versiones provisionales. Solo el sello; el resto
    del pie no se toca. Detalle en `docs/modulos/boletas.md`.
    - **Estaba registrado como hueco conocido y DIFERIDO** ("si se quiere que el borrador
      digital tampoco muestre el sello, es un ajuste aparte"). Hoy se decidió y se hizo.
    - **Incumplía un contrato ya escrito:** el docblock de `archivarBorrador` define
      `$vistaPrevia = true` como *"sin QR y sin imagen de firma del director"*, y en el
      mismo `digital.php` el **QR sí lo respetaba**. Omisión puntual, no criterio distinto.
    - ⚠️ **El alcance era mayor que el documentado:** la nota lo achacaba a los
      desactivados, pero la entrada más expuesta es la **boleta digital del docente**
      (`vistaPrevia` incluye `estadoBoletaDePeriodo(...) !== 'oficial'`) → con el bimestre
      sin cerrar, **todos** los docentes veían el sello en un documento provisional.
    - **La imprimible ya estaba bien:** son dos assets distintos (`firma_path` en
      `alumno.php`, `sello_path` en `digital.php`) y cada vista pinta uno solo.
    - **Barrido hecho:** los otros 7 documentos con firma o sello (nóminas, actas,
      constancia, horario, informe SIAGIE, resumen) **no tienen modo borrador**.
  - **SEÑAL DE BORRADOR — PUNTO ÚNICO (05/08).** La marca de agua la pinta el
    DOCUMENTO (`boleta/_marca-borrador.php`, incluido por `boleta/alumno.php` al recibir
    `$vistaPrevia`), no los wrappers. **Corrige una regresión del mismo día:** al quitar
    el banner, la marca quedó en el wrapper de la vista previa de RA y en el del ZIP, y
    la **boleta impresa del docente** (`/docente/boleta/{id}/imprimir`, botón de la
    nómina) se quedó **sin ninguna señal**. El texto es único (`BOLETA_LEYENDA_BORRADOR`
    en `helpers.php`) y lo comparte la digital; cambia la forma, no el mensaje.
    Verificado en las **7 entradas** (4 en borrador → 1 marca cada una; 3 oficiales → 0).
    - **La boleta DIGITAL también lleva marca (05/08), y es control de fuga:** una captura
      de pantalla o una foto al monitor sacaba notas de un bimestre sin cerrar **sin nada
      que dijera que son provisionales**. Variante `--pantalla`: **fija en el viewport**
      (la digital se recorre con scroll; anclada al contenido dejaría sin marcar justo las
      capturas de la zona de notas) y **dimensionada en `vw` con `clamp()`**, porque los
      `pt` de A4 desbordarían ~3× en un móvil y darían scroll horizontal. Medido: a `12vw`
      proyecta 205 px en una pantalla de 320 px (+115 de margen). Opacidad 0.10.
      `pointer-events: none` es crítico en táctil. **No impide la captura: la etiqueta.**
  - **DESCARGA DE BORRADORES EN ZIP (05/08, pedido del usuario).** Botón
    **📄 Borradores** por sección → `/admin/boletas-publicas/{id}/archivar-borrador`.
    Mismo mecanismo que Archivar (un PDF por alumno, carpetas `NIVEL/GRADO_SECCION`)
    pero con el documento de la vista previa: umbral `'todos'`, sin QR ni firma, con
    marca de agua **dentro de cada PDF** y sufijo `_BORRADOR` en archivo y ZIP. **Sin
    guard de bimestre cerrado**: existe para el bimestre abierto. Su destino es el
    **Drive institucional**, para recoger el visto bueno de los docentes antes de
    cerrar. Verificado en servidor (3 boletas → 3 marcas, 0 QR, ZIP correcto) y que el
    modo Archivar sigue intacto.
    ✅ **PROBADO EN NAVEGADOR POR EL USUARIO EL 10/08/2026: TODO CORRECTO.** Cubrió las
    tres pruebas: sección chica (Primaria 2.º A), sección grande con el peor caso de
    contenido (Secundaria 4.º A, que contiene la matrícula **556**) y el caso de uso real
    sobre el **bimestre ABIERTO** (B3). ZIP descargado, carpetas `NIVEL/GRADO_SECCION`,
    sufijo `_BORRADOR`, marca de agua presente y **0 QR / 0 sello** en los PDFs.
    Ver `docs/modulos/boletas.md`.
  - **BANNER DE BORRADOR ELIMINADO (05/08).** En la vista previa de RA las firmas se
    fueron a una segunda hoja (visto en Secundaria 4.º A): el banner costaba **~6 mm**,
    dos filas de tabla. Queda como única señal la **marca de agua diagonal**, reforzada
    de `#555`/8% a `#3f3f3f`/16% — el documento **se imprime en papel con el bimestre
    abierto**, así que la señal debe sobrevivir a la impresora. Decisión del usuario
    sobre 4 alternativas. Ver `docs/modulos/boletas.md`.
  - ✅ **~~PENDIENTE — checklist de impresión en navegador~~ — RESUELTO EL 10/08/2026.**
    Se probó en papel y **secundaria NO cabía**: el bloque de firmas caía a una segunda
    hoja (primaria sí entraba). **Arreglado con un cambio de una línea de SASS** — la
    conclusión descriptiva pasa de **2 líneas a 1** (`-webkit-line-clamp`), lo que
    devuelve ~15.8mm en Secundaria 4.º A. Validado en papel por el usuario. Modelo de
    alturas, cifras y el seeder del peor caso: **`docs/modulos/boletas.md`**.
    - **El alto no lo fijaban las filas ni solo las conclusiones**, sino
      `max(nombre, literal, conclusión)` por fila. A 2 líneas mandaba la conclusión
      (4.46mm); a 1 línea cae a 2.43mm y el techo lo pone el literal (3.58mm). **Recortar
      más allá de una línea no devuelve nada** — el siguiente margen es el ANCHO de la
      columna de nombre (27 de 59 competencias lo parten en 2 líneas).
    - **La 556 no era el peor caso**: tiene 7 filas con conclusión. El peor real son **18**
      y hay **85 alumnos con 10 o más**. Por eso se calibró con un **seeder del peor caso
      posible** (29 filas, todas en C, conclusiones de 500 caracteres, 4 bimestres y logro
      anual) en vez de contra una boleta real. `database/seeds/seed_peor_caso_boleta.php`,
      reversible; **aplicado y revertido el 10/08**, con la BD verificada de vuelta.
    - **Dato que quita culpa al recorte:** **2 891 de 2 901 conclusiones (99.7 %) ya no
      cabían en 2 líneas** (promedio 155 caracteres, máximo 500). El papel siempre mostró
      un fragmento; ahora es más corto. El texto completo vive en la boleta digital.
- ✅ **LAS 4 REAPERTURAS DEL PANEL DE BLOQUEOS EXIGEN EL BIMESTRE ACTIVO — EN PRODUCCIÓN
  (06/08/2026, commits `213abc0` y `2122345`, desplegados el mismo día en `83c87f5`).**
  Probado en local por el usuario antes del deploy. Sin migración y sin SASS (reusa
  `.btn:disabled`). Detalle en `docs/modulos/admin.md`.
  - **El defecto:** con el bimestre **cerrado**, los 4 botones destructivos del panel
    (`desbloquear` competencia, `reabrirTransversal`, `reabrirConducta`,
    `reabrirAsistencia`) funcionaban **sin dar error** y sin validar el estado del
    periodo. Solo `limpiarBloqueosCierre` lo exigía.
  - **Por qué importaba:** `periodoEditable`/`periodoEstaBloqueado` cortan por
    `estado='cerrado'` **sin mirar el bloqueo**, así que reabrir NO habilita a nadie a
    corregir; y mientras tanto **el dato desaparece del documento** en 3 de los 4 casos
    (boleta = solo competencias bloqueadas · `getTransversalesAgregadas` exige cierre
    vigente · `ConductaModel::getParaPeriodo` devuelve `null` sin él). Quedaba una
    competencia invisible en la boleta que **nadie podía reparar sin reabrir**.
  - ⚠️ **La ASISTENCIA es la excepción MEDIDA, no supuesta:** `getDelBimestre` lee
    `inasistencias` sin mirar el cierre → ahí **no se pierde nada de la boleta**. Se
    bloquea igual (nadie podría registrar), pero **su mensaje no promete un daño que no
    ocurre**. Cada llamada pasa SU aviso; por eso el guard recibe el texto por parámetro.
  - **Punto único:** `BloqueoController::abortarSiPeriodoCerrado`. Los botones quedan
    **inertes con el motivo en el `title`** (no desaparecen: se ve POR QUÉ no se puede).
    Conducta tenía **DOS** botones "Reabrir" (`pendiente_tutor` y `cerrada`): los dos.
  - **Los botones de AVANCE se dejan intactos a propósito** (bloquear competencia /
    transversal / etapa 1 / etapa 2 / asistencia): no destruyen nada y son la vía para
    recomponer lo que haya quedado sin bloqueo por un desbloqueo anterior al fix.
  - **Contexto operativo que lo motivó:** el usuario necesita poder desbloquear una
    competencia si un docente se equivocó. Con B2 **activo** eso funciona, pero hace falta
    que `limite_notas` esté vigente o el docente no podrá editar aunque se desbloquee.
    *(Estaba vencido el 04/08 23:59; **ampliado a `2026-08-11 04:00`**, medido el 10/08 —
    la vía es `/director/anios/1`.)*
  - **La ventana barata para corregir es entre CERRAR y PUBLICAR:** ahí reabrir → corregir
    → re-cerrar todavía actualiza el snapshot **oficial** del mérito. Tras publicar, el
    candado 046 lo congela y la corrección va a `orden_merito_rectificado` (no oficial).
    Medido: B2 **no tiene ninguna fila** en `periodos_publicacion`.
- ✅ **DESBLOQUEO GRANULAR DE TRANSVERSALES EN EL PANEL DEL DIRECTOR — EN PRODUCCIÓN
  (deploy `cf8bdb2`, 06/08/2026). Sin migración.** Detalle:
  **`docs/modulos/admin.md` §"Transversales: los dos niveles"**.
  - **El hueco:** las transversales **no son filas del panel académico**
    (`getCompetenciasPorPeriodo` une por el área de la CARGA), así que para reabrir una
    TIC/GAMA mal aprobada había que **desbloquear una competencia ACADÉMICA** de la misma
    carga: la sacaba a ella de la boleta, liberaba **las dos** transversales de golpe y
    obligaba a re-aprobar todo. Y si la carga no tenía académicas bloqueadas —permitido:
    64 cargas de B2 bloquean transversales primero— **no había vía ninguna**.
  - ⚠️ **El botón que parecía servir, no servía:** "Desbloquear" del bloque de
    transversales solo llamaba a `anularCierreVigente` (el cierre del TUTOR), sin tocar
    los bloqueos por carga. **Renombrado a "Anular cierre"** y el texto del bloque ahora
    distingue explícitamente los dos niveles. Solo texto: la lógica no cambió.
  - **Granularidad por COMPETENCIA** (decisión del usuario): TIC y "Aprendizaje autónomo"
    se liberan por separado, desde un `<details>` colapsado por sección (carga · docente ·
    competencia · origen · nº de notas · acción). Sin JS.
  - **Guards:** el anclaje exige `a.tipo='transversal'` (un `bloqueo_id` académico aborta,
    probado), exige el **bimestre activo** con el mismo punto único del 06/08, y **anula
    el cierre del tutor** porque el agregado promedia solo lo bloqueado.
  - **Probado en transacción:** liberar TIC deja *Aprendizaje autónomo* bloqueada,
    conserva las **44 notas**, no toca las 2 académicas de la carga y anula el cierre;
    rollback limpio.
- ✅ **TRANSVERSALES: LAS 4 FASES EN PRODUCCIÓN (deploy `cf8bdb2`, 06/08/2026), con la
  migración 051 aplicada después.**
  Qué se construyó y con qué cifras:
  **`docs/modulos/transversales-visibilidad-tutor.md` §5** (manda esa sección).
  - **F3 — el tutor ya no espera a ciegas.** La tabla de promedios se pinta SIEMPRE, con
    badge `Provisional` y en solo lectura mientras falten cargas; debajo, el resumen de
    **qué cargas aprobaron sus transversales y qué docente las lleva** (deroga la regla de
    privacidad del 14/06/2026 — se expone área, docente y estado; **nunca** notas ajenas
    ni DNI). Solo se listan las cargas que APORTAN (`total_comp > 0`).
    🔴 **El guard de escritura está en el SERVIDOR** (`guardarConclusion`), que no
    comprobaba `$listo`: ocultar el textarea habría sido cosmético.
  - **F4 — el cierre transversal se desacopla de las académicas.** `estadoCargasSeccion`
    cuenta solo transversales (numerador y denominador se mueven juntos). Las académicas
    no participan del promedio que se congela, así que exigirlas hacía esperar por notas
    que no cambiaban el resultado. **Contrapartida aceptada:** cerrar antes alarga la
    ventana en la que un desbloqueo académico anula el cierre en cascada (B2 ya llevaba
    48 anulaciones sobre 71).
  - ⚠️ **Se revisaron los OTROS DOS consumidores de `estadoCargasSeccion`**
    (`BloqueoController` y la card del dashboard docente): no se rompen —preguntan lo
    mismo— pero **sus textos pasaban a mentir** y se ajustaron a «competencias
    transversales». Un «X de Y» a secas se leía como el total de la sección.
  - **Probado construyendo el estado provisional en transacción** (en local no existe:
    B2 está cerrado y todo bloqueado): liberar una carga deja `30/28`, el guard **rechaza
    el POST**, la tabla conserva sus 24 alumnos y el resumen dice `14 de 15`; rollback a
    `30/30`. Y `calificaciones.md` tenía una línea **falsa desde antes** —decía que
    `estadoCargasSeccion` contaba «solo competencias PROPIAS»— ya corregida.
  - ⚠️ **Dato que matiza F3 sin cambiar la decisión:** el promedio provisional **sí se
    mueve** (34 de 48 celdas con 12 de 15 cargas sin aprobar), pero **el literal no llegó
    a cambiar** mientras quedara alguna carga aportando, ni en primaria ni en secundaria.
    El riesgo es real en el promedio; en B2 no se materializó en el literal.
  - 🔴 **LA SECUENCIA CHOCA CON EL CIERRE DE B2:** `F1 a producción → aplicar la 051 →
    CERRAR B2`. Los fantasmas los crea el cierre forzado, así que **si B2 se cierra antes
    de que el fix esté en prod, nacen fantasmas nuevos**; y si la 051 se aplica con el
    código viejo arriba, el siguiente cierre los recrea.
  - ⚠️ **PUEDE QUE EN PROD NO HAYA NADA QUE BORRAR, y es válido.** Las cifras están
    medidas en **local**, donde B2 figura `cerrado`; en **prod B2 seguía ABIERTO**. Si
    nunca se cerró allí, los 130 no llegaron a nacer y el PASO 1 dará **0 filas**. Manda
    el PASO 1 en prod, no las cifras del doc. Lo que protege de verdad es F1.
  - **F1** (`AnioAcademicoModel::bloquearCompetenciasPendientes`, bloque 2): añade las dos
    exclusiones del formulario. **Prueba dura, no inspección:** vaciados los 820 bloqueos
    transversales de B2 en transacción y recreados con el SQL nuevo → **690 en vez de 820,
    exactamente 130 menos**, 0 en TOE y 0 en no-dueña; rollback limpio.
  - **La regla de "carga dueña" queda en CUATRO sitios** (decisión: cuarto sitio
    documentado, **no** helper compartido — no se toca el gate del tutor, que es delicado).
    Los cuatro llevan comentario cruzado que los nombra: formulario, `estadoCargasSeccion`,
    `cargaDuenaTransversales` y el cierre forzado.
  - **F2 = migración `051`** (datos, no esquema), **escrita y ensayada, NO aplicada en
    ningún entorno**. Aborta si aparece un solo `C_OLVIDO_REAL` o si hay notas/criterios
    colgando. Ensayo en local con `ROLLBACK` y verificación *dentro* de la transacción:
    borró **130**, dejó el aviso en **0/0**, con los **690** de docente y los **23** cierres
    vigentes intactos; B1 siguió en **774**.
  - **Verificación:** `database/verificaciones/verif_transversales_fantasma.php` (solo
    lectura, corre en prod). Su bloque de **equivalencia de universos** es el que impide
    que el defecto vuelva: hoy da **345 = 345**, antes del fix 410 contra 345. Mientras la
    051 no se aplique, el script **falla a propósito** en el bloque 1.
  - **Dos afirmaciones del plan corregidas al medirlas:** de las 774 forzadas de B1,
    **84 son este mismo defecto** (no todas son "modelo viejo"); y la transversal es la
    última en **13 de 23** secciones, no en las 23 — **F4 solo daría tiempo útil a 4**
    (47 h, 29 h, 11 h, 3 h).
  - Diagnóstico original del defecto (sigue vigente como contexto):
  - **El aviso de `/admin/control` en B2 es FALSO.** Dice que 130 competencias en 65
    cargas de **23 docentes** quedaron sin bloquear "porque el docente no las había
    bloqueado". Clasificadas las 65: **23 son cargas TOE** (el formulario NO adjunta
    transversales a la carga de tutoría, decisión del 07/07) y **42 son cargas no-dueñas
    de secciones unidocentes** (las TIC/GAMA se adjuntan una vez por área, en la dueña).
    **Olvidos reales: CERO.** 23+42 = 65 cargas × 2 = 130, cuadra exacto.
  - **Causa raíz:** `AnioAcademicoModel::bloquearCompetenciasPendientes` (bloque 2)
    recorre **TODAS las cargas activas** sin aplicar las dos exclusiones que sí aplica
    `CalificacionController:507-514`. **Misma regla, dos implementaciones divergentes.**
  - ⚠️ **Ya mordió antes y se parcheó del lado equivocado:** el comentario de
    `estadoCargasSeccion` documenta que las no-dueña inflaban el numerador (53/41) y
    habilitaban las conclusiones antes de tiempo. Se arregló **el conteo**, no el origen.
  - **Impacto acotado:** NO contamina el promedio agregado (una carga fantasma no tiene
    notas) ni infla ya el gate del tutor. El daño es de **confianza**: acusa a 23 docentes
    en un panel de dirección. Sospecha por verificar: podría explicar parte de las **48
    anulaciones sobre 71 cierres transversales** de B2, vía la cascada de desbloqueo.
  - **B1 no se toca** (774 forzadas allí son del modelo viejo, carga única del tutor).
  - ✅ **DECISIONES CERRADAS (06/08/2026, no re-preguntar):** **(1)** los 130 fantasmas de
    B2 **se borran** con una migración de DATOS **`051`** (la `049` sigue reservada al
    registro retroactivo) — el PASO 1 aborta si aparece un solo caso de "olvido real" o si
    alguna de esas cargas tiene notas transversales colgando, y **F1 va antes o en el
    mismo despliegue**, o el siguiente cierre los recrea. **(2)** El tutor **solo mira**
    hasta tener el promedio final: nada de conclusiones sobre un parcial, y el guard va
    **en servidor** (`guardarConclusion` hoy NO comprueba `$listo`, así que ocultar el
    textarea sería cosmético). **(3)** El resumen del tutor **sí muestra el nombre de la
    carga y del docente**: esto **DEROGA** la regla de `tutoria.php:55` ("no se expone el
    detalle por carga ni el nombre de otros docentes"), nacida el **14/06/2026** en
    `73838d1`. Al implementar hay que **reescribir ese comentario**, o el código afirmará
    lo contrario de lo que hace. La protección del DNI del mismo lote **no se toca**.
- **PROPUESTA "BLOQUEAR TRANSVERSALES ANTES QUE LAS ACADÉMICAS" — EVALUADA (06/08/2026):
  ya es posible y no destraba nada por sí sola.** `bloquear()` es por competencia y admite
  transversales, sin guard de orden: **64 cargas de B2 (16%) ya lo hacen**. Lo que frena
  al tutor es que el gate cuenta académicas + transversales y que `tutoria.php:98`
  **oculta la tabla de promedios entera** hasta que todo esté bloqueado. Y la ganancia
  sería nula: en las 23 secciones de B2 la última transversal llegó **40-144 h DESPUÉS**
  que la última académica. El acoplamiento sí es gratuito (`getPromediosSeccion` filtra
  `tipo='transversal'`), así que desacoplar el gate es correcto — pero el valor está en
  **mostrar promedios parciales**, no en el orden de bloqueo.
- 🔴 **`notFound()` NO EXISTÍA — BUG PREEXISTENTE CORREGIDO (07/08/2026, en `dev`).**
  Varios controladores llamaban `$this->notFound()` sin que estuviera definido en
  ninguna parte: `Router` y `RectificacionController` tenían el suyo, ambos **privados**
  y por tanto inalcanzables. Efecto real medido: en **local** reventaba con
  `Call to undefined method` y en **producción** el blindaje global lo capturaba como
  excepción y devolvía la página de error **genérica** — nunca un 404.
  - **No se notó durante meses** porque los únicos caminos que lo invocaban exigían un
    periodo inexistente. Los **gates D3 de `/consulta-notas`** fueron los primeros en
    dispararlo de verdad, y ahí saltó.
  - **Punto único:** `BaseController::notFound(): never` — `http_response_code(404)` +
    `require` de `shared/404.php` + `exit`. Se eliminó el privado de
    `RectificacionController` (obligatorio: un `private` en la hija choca con el
    `protected` de la base y da fatal error de compatibilidad de acceso).
  - ⚠️ **Corrige de paso un segundo defecto latente:** aquel usaba `$this->view('shared/404')`
    y esa vista es una **página HTML completa**, así que el layout la anidaba dentro de
    otra. Ahora es `require` directo, como el Router. Verificado: HTTP 404, **un solo
    `<!DOCTYPE>`**.
  - **Auditoría de alcance:** se revisaron los **34 controladores** buscando llamadas
    `$this->metodo()` inexistentes. **0 casos más.** Convención registrada en `CLAUDE.md`.
- ✅ **`/consulta-notas` CON TRANSVERSALES Y CONDUCTA — EN PRODUCCIÓN. Sin migración, sin
  métodos de modelo nuevos.** Implementado en `dev` el **07/08/2026** y desplegado en los
  lotes posteriores. ⚠️ **La etiqueta «SIN DESPLEGAR» de esta cabecera quedó vieja y se
  corrigió el 17/08/2026** (verificado: `dev == main` y las 5 rutas están en
  `routes/web.php`).
  Qué se construyó y con qué cifras:
  **`docs/modulos/consulta-notas-ampliada.md` §9** (manda esa sección).
  - **Las tres fases juntas:** crudo transversal dentro de cada carga, agregado
    transversal por sección y conducta por sección, las dos últimas con ruta propia de
    5 segmentos (registradas **antes** que la de 4).
  - 🔴 **CORRECCIÓN AL PLAN — en B1 el crudo por carga NO existe, y es correcto.** El plan
    pedía verificar «23 cargas en B1»; da **0**, porque allí regía el modelo viejo (carga
    única del tutor) y esas 23 cargas están hoy `inactiva`, fuera del alcance de
    `getCompetenciasPorPeriodo`. **El crudo por docente nace en B2**; para B1 el valor es
    el agregado.
  - 🔴 **El bloqueo NO es señal de contenido:** 820 bloqueos sobre 410 cargas por bimestre
    (cascada del cierre forzado). Sin el `EXISTS` de calificaciones se pintarían **410
    bloques vacíos en B1** y 65 en B2. El helper exige bloqueo **Y** notas.
  - **Gate D3 verificado:** B3 oculta las dos entradas (nada cerrado) y sus rutas
    responden 404 — ocultar el enlace no basta, la URL queda en marcadores.
  - **El roster se reusa de `ConductaModel::getEstudiantesParaTutor`** a propósito: es el
    canónico con las exclusiones de retorno, y duplicar ese filtro es como nacieron los
    bugs de asistencia del 04/08.
  - **Verificación:** `verif_consulta_notas_ampliada.php` contrasta contra **las fuentes
    de la boleta** (`getPromediosMatricula` y `getParaPeriodo`): **2086 celdas y 1048
    filas, 0 divergencias**, con B1 (legado) y B2 (modelo nuevo) en la misma corrida.
  - Plan original y decisiones D1-D3: **`docs/modulos/consulta-notas-ampliada.md`**.
  - ✅ **PROBADO EN NAVEGADOR POR EL USUARIO (07/08/2026): todas las pruebas pasaron.**
    Cubrió, en local y en prod: el aviso de incidencias de B2 en 0 (F1+051), el desplegable
    granular de TIC/GAMA con sus botones inertes en bimestre cerrado, la vista del tutor en
    estado *Provisional* con el resumen de cargas, la card del dashboard docente, las tres
    fases de `/consulta-notas` (incluida la comprobación de que **B1 no pinta bloques
    transversales crudos**) y los gates D3 devolviendo 404 en B3.
  - **Las dos ausencias son estructurales, no un olvido de la vista.** Las transversales
    no las puede alcanzar `getCompetenciasPorPeriodo`: une competencia↔carga por el área
    de la CARGA, y las transversales cuelgan de un área propia (`tipo='transversal'`,
    ids 9 y 21) — **el vínculo transversal↔carga no existe en el esquema**, se resuelve
    por nivel. La conducta no vive en `calificaciones` (4 tablas propias) y su ciclo es
    por SECCIÓN en dos etapas. Invisible hoy: **B2 tiene 17 078 notas transversales**.
  - **Decisiones cerradas (no re-preguntar):** D1 las **dos** caras de las transversales
    (crudo por carga + agregado por sección); D2 la conducta entra **dentro** de
    `/consulta-notas` en solo lectura, **sin** ampliar los roles de `/admin/conducta`
    (tiene escritura); D3 **solo lo oficial** (cierre vigente / las dos etapas).
  - **Cero métodos de modelo nuevos y sin migración** — verificado con sonda:
    `getResumenCompetencia` funciona igual sobre una competencia transversal, así que el
    crudo se pinta con el `_tabla.php` que ya existe.
  - 🔴 **Dos trampas medidas:** el **bloqueo NO es señal de contenido** en transversales
    (820 bloqueos / 410 cargas por bimestre, pero solo 23 cargas con notas en B1 → copiar
    el criterio actual pintaría 410 bloques vacíos); y **B1 y B2 no comparten modelo de
    conducta** (B1 legado: 528 literales y 0 respuestas). `getEstudiantesParaTutor` ya
    resuelve las dos, marcando `es_legado`.
  - **Cierra un hueco de roles real:** `director_general` y `director_ebr` no tienen hoy
    ninguna forma de ver conducta ni el agregado transversal.
- ⛔ **NOTAS DE BIMESTRES CERRADOS PARA QUIEN LLEGÓ DESPUÉS — PLAN DEROGADO Y SUSTITUIDO
  EL 10/09/2026. NO IMPLEMENTAR NADA DE LO QUE SIGUE EN ESTA ENTRADA.** La funcionalidad se
  construyó, pero con **otro diseño**: la regla del colegio partió el caso en DOS mecanismos
  con destinos opuestos (colegio de origen = informativo, nunca en boleta · notas nuestras
  no registradas = calificación extraordinaria, sí en boleta). Ver el bloque
  **«REGISTRO DE CALIFICACIONES DE BIMESTRES CERRADOS»** al principio de este archivo, y
  `matriculas.md` · `calificaciones.md` · `notificaciones.md`.
  - 🔴 **`calificaciones_retroactivas` NO se construye y la migración `049` queda LIBERADA**
    (hueco permanente: no reutilizar el número). Las que sí entraron son la `057` y la `058`.
  - Caen las decisiones **D2, D4, D6 y D7**, y su pregunta abierta del SIAGIE se cierra sola.
  - La **F1** (asistencia en guion) es independiente y **sigue en producción** desde el 07/08.
  - **Lo que queda abajo se conserva como ANÁLISIS**, que sigue siendo válido y caro de
    rehacer (los 45 usos de `nota_numerica`, el universo de los 6 casos, el estado de la
    extraordinaria). **Su plan de implementación, no.**

- ~~**NOTAS DE BIMESTRES CERRADOS PARA QUIEN LLEGÓ DESPUÉS — PLAN DE IMPLEMENTACIÓN LISTO,
  SIN IMPLEMENTAR (05/08/2026).**~~ (derogado, ver arriba) Plan completo con fases, archivos y SQL:
  **`docs/modulos/registro-retroactivo-notas.md`** (empezar por §6 **F0**).
  - **Lleva migración `049`** (tabla `calificaciones_retroactivas` + `DROP notas_externas`)
    → al desplegar hay que aplicarla a mano en prod ANTES del merge, como la 044 y la 045.
  - 🔴 **F0 es BLOQUEANTE y de solo lectura:** contar en PROD las 5 tablas de los
    mecanismos a unificar. Si alguna trae filas, la migración cambia y el `DROP` deja de
    ser seguro.
  - **F1 (asistencia en guion) es independiente y desplegable sola**, sin migración.
    **No desplegar F3 sin F4**: RA registraría notas que no aparecen en ningún documento.
  - **El caso existe: 6 estudiantes** con notas de B2 y ninguna de B1 (690, 691, 693,
    694, 695, 696; llegaron entre el 08/06 y el 13/07). ⚠️ **`matriculas.tipo` no sirve
    para detectarlos** —la mitad son `continuador`—; el anclaje es la ausencia de notas.
    No confundir con el lote `traslado_entrada` del 19/05 (~180 matrículas con B1
    completo, flag mal puesto).
  - **Buena parte ya está resuelta:** la **calificación extraordinaria** (migración 042,
    EN PROD desde el 16/07) ya permite registrar nota en competencia de bimestre cerrado
    a cualquier alumno sin nota, con motivo, y ofrece 26-28 de las ~27-29 competencias
    del plan (faltan solo las transversales). Falta: **literal** (pide numeral),
    **captura en lote** (hoy es de una en una, 26-28 pasadas) y **trazabilidad del origen**.
  - **Decisiones cerradas:** literal puro con **numeral en guion** (no se inventa el
    número); captura en **grilla** por alumno y bimestre; la boleta **declara** el origen
    con una nota al pie; asistencia del bimestre no cursado en **guion**.
  - ✅ **F1 HECHA — la boleta ya NO imprime `0 faltas` de un bimestre no cursado
    (07/08/2026, en `dev`, SIN MIGRACIÓN y sin SASS).** `sin_registro` gana un tercer
    motivo: que **nadie haya registrado** la asistencia de ese alumno. Punto nuevo
    `AsistenciaModel::tieneRegistroUnion`, consumido por `BoletaModel::armar` en último
    lugar para que `||` corte en corto y no consulte cuando el umbral ya dijo que no.
    Detalle y cifras en `docs/modulos/boletas.md`.
    - **Universo medido: 18 pares** (matrícula, bimestre) sin fila en bimestres
      cerrados/activos; **la unión neutraliza 2** → **16 celdas** pasan de `0` a guion:
      los **6 que llegaron tarde** en B1 y **10 trasladados/retirados** en B2.
      **El Total anual no se mueve** (esas columnas aportaban 0).
    - ⚠️ **La UNIÓN era imprescindible, y por poco no se ve:** en el retorno #1 la fila de
      B1 vive en la oficial (190) y la de B2 en la operativa (692). Preguntando matrícula
      por matrícula, esa boleta habría salido en guion en **los dos** bimestres teniendo
      datos. Verificado en los dos sentidos.
    - ⚠️ **Sin fila ≠ sin incidencias**, y esto NO era deducible del código: `guardar()`
      es un upsert **AJAX fila por fila** y el cierre de sección **no exige completitud**
      ("sin fila = 0 incidencias", dice su comentario). Como de hecho el registro sí
      escribe fila por alumno aunque vaya en cero (**197** en B1, **173** en B2), esas
      conservan su `0`. **Hubo que medirlo, no deducirlo.**
    - **Contraste que prueba la precisión del cambio:** la 694 pasa a guion en B1 (no lo
      cursó) y **conserva su `0` en B2**, donde sí tiene fila registrada sin incidencias.
    - Verificación: `database/verificaciones/verif_asistencia_sin_registro.php` (solo
      lectura, corre en prod). Su **bloque 2** es el antirregresión.
  - ⚠️ **Prohibido `nota_numerica NULL` en `calificaciones`:** 45 usos en 11 archivos, de
    los que **26 son promedios, umbrales o desempates** que un NULL altera EN SILENCIO.
    De ahí que el plan proponga tabla aparte unida al leer (patrón ya probado en el
    retorno de grado y en `notas_autorizadas_siagie`).
  - **Decisiones del 05/08 (D6-D9):** la **extraordinaria y el registro retroactivo SE
    UNIFICAN** (un solo punto de entrada; el flujo de la extraordinaria se retira);
    **`notas_externas` se elimina** (su función la absorbe el proceso nuevo); las
    **transversales se registran OBLIGATORIAMENTE**; conducta y asistencia **opcionales**.
    - **La unificación no arrastra datos:** medido en local, los 5 mecanismos están en
      **0 filas** (extraordinarias, criterios extraordinarios, rectificaciones
      extraordinarias, `notas_externas`, `notas_autorizadas_siagie`). 🔴 **Verificar esas
      5 cifras en PROD antes de tocar nada**: si allí hay extraordinarias, se migran en la
      misma migración.
      - 🔴 **PREMISA CADUCADA EL 06/08, DETECTADA EL 17/08/2026: YA NO SON 0.** La migración
        **050** —aplicada en los DOS entornos el 06-07/08, o sea DESPUÉS de escribirse esta
        línea— dejó **275 calificaciones extraordinarias · 11 criterios extraordinarios ·
        275 rectificaciones `tipo='extraordinaria'`**. Medido en local el 17/08;
        `notas_externas` y `notas_autorizadas_siagie` **sí siguen en 0**.
      - **Qué cambia del plan:** la decisión D6 («la extraordinaria y el registro retroactivo
        SE UNIFICAN; el flujo de la extraordinaria se retira») ya **no es gratuita**. Esas
        275 notas son de **B1, que está PUBLICADO**: las familias las están viendo. Retirar
        el flujo sin migrarlas las dejaría huérfanas. El `DROP notas_externas` **sí sigue
        siendo seguro** (0 filas).
      - **F0 sigue siendo BLOQUEANTE, pero ahora su respuesta esperada NO es 0:** contar las
        5 cifras en PROD antes de escribir la `049` y **presupuestar la migración de datos
        que el plan daba por innecesaria**.
    - **Modelo unificado:** `nota_literal` SIEMPRE + `nota_numerica` **NULL** cuando viene
      de otro colegio (boleta: `— / A`) y con número cuando es evaluación real nuestra.
    - **Transversales:** hoy quedan fuera del insertable porque se muestran AGREGADAS
      desde el cierre del tutor y una fila cruda no llega a boleta. La tabla aparte lo
      resuelve sola (se une al leer, sin pasar por la agregación) — pero hay que evitar
      que se dupliquen cuando el alumno sí tiene agregación.
  - **Abierto (diferido por el usuario):** si van al SIAGIE. No bloquea F1 ni F2; conviene
    resolverlo antes de F4.
- ✅ **EXONERAR A UN ALUMNO QUE YA TIENE NOTAS — EN PRODUCCIÓN (implementado el
  05/08/2026, desplegado ese mismo día en `c8681da`), SIN MIGRACIÓN.** Deroga el candado
  del 07/07, que dejaba sin salida el caso real
  (estudiante con notas en un bimestre CERRADO y otro abierto): miraba todo el año y las
  notas del cerrado no se pueden borrar (`periodoEstaBloqueado`), así que su "elimina las
  notas primero" **no era ejecutable**. Ahora el aviso es **franqueable con confirmación
  explícita** (`confirmar_notas`). Regla completa en `docs/modulos/matriculas.md`.
  - **Decisión del usuario: EXO en los CUATRO bimestres**, incluidos los ya cursados.
    Las notas **no se borran** (reversible; al revocar reaparecen). Probado en transacción
    con rollback: `B1=A B2=A` pasa a `EXO EXO EXO EXO`, anual EXO, 4 notas intactas en BD
    y snapshot de B1 en 528 filas.
  - ⚠️ Asumido: la boleta de un bimestre ya entregado cambia hacia atrás y el acta del
    SIAGIE conserva la nota (divergencia a gestionar fuera de SIGA).
  - ✅ **RESUELTO el mismo día: el ORDEN DE MÉRITO excluye las áreas exoneradas**
    (decisión del usuario). `NOT EXISTS` sobre `exoneraciones` en las 2 queries que
    calculan promedio; cubre exoneración por área y por subárea. **Los snapshots guardados
    NO se tocan** (el de B1 sigue en 528 filas).
  - **CASO REAL YA REGISTRADO EN LOCAL POR EL USUARIO (05/08, 19:39):** NOLASCO ALVARADO,
    YURIANA (matrícula **530**, 5.º B primaria), exonerada de Ed. Religiosa con 3 notas
    ya puestas — 1 en B1 **cerrado** y 2 en B2. Verificado end-to-end: su boleta muestra
    **EXO en los 4 bimestres** y anual EXO, las 3 notas siguen vivas en la BD, y en el
    mérito su promedio de B2 baja de **13.38 a 13.21** sin que **cambie ni un puesto** en
    su grado (39 alumnos). Su puesto congelado de B1 (34, promedio 12.17) queda intacto.
- ✅ **DESBLOQUEAR UNA ACADÉMICA YA NO ARRASTRA LAS TRANSVERSALES — EN PRODUCCIÓN
  (implementado y probado el 07/08/2026, desplegado el 10/08 en `945ba91`). Sin migración,
  sin SASS.** Decisión del usuario: prima la **granularidad** sobre el clic de menos.
  Detalle en `docs/modulos/admin.md`.
  - ⚠️ **Se desplegó ANTES del cierre de B2, revirtiendo la decisión del 07/08** («el merge
    espera al cierre; no mover el panel que se usa durante el cierre»). El panel de bloqueos
    quedó tocado en el mismo tramo en que se usa para cerrar — riesgo asumido a cambio de
    poder desbloquear sin arrastrar transversales durante las últimas correcciones de B2.
  - `BloqueoController::desbloquear` pasa de 3 efectos a 2: se retira
    `liberarTransversalesDeCarga`; **se conserva** la anulación del cierre del tutor.
  - **Por qué se retira:** su motivo —que las transversales quedarían "inalcanzables"—
    murió el 06/08 con el desbloqueo granular. Mantenerlo obligaba al docente a re-aprobar
    TIC/GAMA que nadie tocó y **bajaba el gate del tutor**, que no podía re-cerrar hasta
    que el docente actuara. Medido en el contraste: el gate caía de **16/16 a 14/16**.
  - **Por qué se conserva la anulación del cierre:** el promedio transversal NO cambia
    (`getPromediosSeccion` solo lee bloqueos transversales), pero **la conclusión
    descriptiva del tutor puede dejar de ser precisa** si cambian las notas. Criterio
    pedagógico del usuario. Ahora es barato: con los bloqueos intactos el tutor re-cierra
    de inmediato.
  - `liberarTransversalesDeCarga` queda **DORMIDO** (0 llamadores), no borrado.
  - **Verificación:** `verif_desbloqueo_sin_cascada.php` (escribe, transacción + ROLLBACK,
    guard de prod). **7 bloques en verde**, incluido el contraste que reproduce la cascada
    vieja y comprueba que rompía el gate.
  - ✅ **Corregidos de paso dos comentarios FALSOS** en `CalificacionController`: decían que
    las transversales "se bloquean junto con la última competencia propia (Variante 1)",
    cuando el docblock de `bloquear()` dice que desde el II Bimestre **cada competencia se
    bloquea por separado** y ese empaquetado se retiró. Las otras 4 menciones a "Variante 1"
    son correctas —nombran el MODELO (las transversales viven en la carga de cada docente)—
    y no se tocaron.

  #### 📋 CHECKLIST DE PRUEBAS EN NAVEGADOR — PENDIENTE (guardada el 07/08/2026)

  > Escrita para ejecutarla en **el setup de casa**. Todo lo automatizable ya está en
  > verde; esto cubre lo único que los scripts no ven: el render y el flujo real.

  **PASO 0 — antes de nada, comprobar la frescura de la BD de esa máquina.**
  ⚠️ **La BD local de cada equipo es independiente.** La de la oficina se sincronizó con
  prod el 07/08 (trae la `050`). Si la de casa está atrasada, las cifras de abajo no
  cuadran y **no es un bug**. Marcadores:

  ```sql
  SELECT (SELECT COUNT(*) FROM calificaciones WHERE extraordinaria = 1)              AS m050_espera_275,
         (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name LIKE '\_bkp%')             AS m048_espera_0,
         (SELECT COUNT(*) FROM bloqueos_competencia bc
            JOIN competencias c ON c.id = bc.competencia_id
            JOIN areas a ON a.id = c.area_id AND a.tipo = 'transversal'
           WHERE bc.periodo_id = 2 AND bc.origen = 'cierre')                         AS m051_espera_0;
  ```

  **PASO 1 — la batería automática (un comando, todo en verde en la oficina):**
  ```bash
  php database/verificaciones/verif_desbloqueo_sin_cascada.php   # 7 bloques, incluye el contraste
  php database/verificaciones/verif_transversales_fantasma.php   # 345 = 345, 690 intactos
  php database/verificaciones/verif_asistencia_sin_registro.php  # F1
  ```

  **BLOQUE 1 — el desbloqueo académico ya no arrastra transversales**
  1. `/director/bloqueos` con **B2** → desbloquear una competencia **académica** de una
     carga que tenga TIC/GAMA bloqueadas. El `confirm` debe avisar que las transversales
     NO se tocan.
  2. En la pestaña **Competencias transversales**, abrir el desplegable de esa sección:
     sus **TIC/GAMA siguen bloqueadas**.
  3. Entrar como el **tutor** de esa sección: la tabla de promedios debe seguir
     **habilitada** (no en badge *Provisional*) y debe poder **cerrar sin esperar al
     docente**. ← *es la mejora principal; antes quedaba bloqueado.*

  **BLOQUE 2 — el cierre del tutor sí se anuló**
  4. En el panel, esa sección debe aparecer **sin cierre vigente** (con el botón *Cerrar*
     disponible).

  **BLOQUE 3 — la granularidad sigue intacta**
  5. Liberar **una** transversal desde el desplegable: la otra queda bloqueada y las
     académicas de la carga no se tocan.

  **BLOQUE 4 — el docente**
  6. Como docente de esa carga: la competencia desbloqueada **editable**, sus TIC/GAMA en
     **solo lectura**.

  **BLOQUE 5 — deuda anterior, aprovechando el turno (P1 #7)** ✅ **HECHO 10/08/2026**
  7. `/admin/boletas-publicas/{id}` → botón **📄 Borradores**: comprobar que el **ZIP
     descarga bien en el navegador**. Era lo único que quedaba del P1.

  ⚠️ **Actualización 10/08:** el lote **ya está en producción** (`945ba91`), así que esta
  checklist dejó de ser una condición previa al deploy y pasó a ser **verificación de lo
  que ya corre en prod**.
  ✅ **CHECKLIST COMPLETA — el BLOQUE 5 se probó el 10/08/2026 y salió correcto**, así que
  ya no queda ningún punto de esta lista sin ejecutar.
  - **El botón es SIEMPRE por sección** (`?seccion_id=N` en la vista del periodo): desde la
    UI no hay forma de lanzar el lote completo. ⚠️ La ruta **sin** `seccion_id` sí existe y
    procesaría las ~524 matrículas del periodo en una sola pestaña — html2pdf renderiza en
    el cliente, así que es la vía rápida a colgar el navegador. No enlazarla nunca.
- **PANEL DE TRANSVERSALES COMPLETO + PUNTO ÚNICO DE "CARGA DUEÑA" — DIFERIDO AL AÑO
  ACADÉMICO SIGUIENTE (decisión del usuario, 07/08/2026).** El gestor de bloqueos
  transversales solo muestra lo aprobado y bloqueado (`getBloqueosTransversalesPorPeriodo`
  arranca `FROM bloqueos_competencia`), y debería mostrar todo diferenciado por estado como
  el panel académico. **Análisis completo y medido en
  `docs/decisiones-diferidas.md`** — no re-derivarlo. En una línea: sería la **quinta copia**
  de la regla de carga dueña (la cuarta divergente creó los 130 fantasmas), **hoy no
  aportaría información** (en B2 las 690 filas del universo están todas en un mismo estado)
  y **en B1 mentiría** (sus 1052 notas viven en 23 cargas `inactiva` del modelo viejo,
  y el panel nuevo escondería los 130 fantasmas que B1 conserva). Toca
  `estadoCargasSeccion`, el gate del cierre del tutor. **Va junto con la F1 del plan de los
  4 registros**, que es un punto único sobre el mismo territorio.
- ✅ **EL MySQL DE PROD CORRE EN UTC (5 h ADELANTADO) Y DOS CONSULTAS LO IGNORABAN —
  HALLADO Y CORREGIDO EL 10/08/2026, SIN MIGRACIÓN y sin SASS. EN PRODUCCIÓN** desde el
  2.º deploy de ese mismo día (`992a350` → `9d3207d`, ver Eventos con fecha). ⚠️ **La
  etiqueta «SIN DESPLEGAR» de esta cabecera quedó vieja y se corrigió el 17/08/2026.**
  - **El arreglo:** las dos consultas pasan a recibir el "ahora" **como parámetro
    preparado calculado en PHP** (`date('Y-m-d H:i:s')` con `America/Lima`), que es el
    patrón que ya seguía `PublicacionBoletaModel` y que su docblock documentaba. Dos
    líneas de SQL y sus comentarios; ninguna interfaz pública cambia.
  - **Verificación:** `database/verificaciones/verif_flag_editable_timezone.php`
    (transacción + ROLLBACK, guard de prod). Los dos flags siguen al guard real en las
    **cuatro fronteras** del `limite_notas`, y su **paso 2 fuerza la sesión MySQL a UTC**
    para reproducir producción: allí el `NOW()` viejo dice **NO editable** donde el guard
    real y el flag nuevo dicen **editable**. Si el `NOW()` viejo no llegara a diferir, el
    script lo declara **fallo de control** — sin ese paso no probaría nada, porque en
    local el desfase es 0.
  - ⚠️ **En local NO se reproduce** (XAMPP va en hora del sistema): cualquier prueba
    manual del flag en local da lo mismo antes y después del fix.
  Huella capturada en prod: `NOW()` marcó **2026-08-11 01:51** cuando en Perú eran las
  **20:51 del 10**. En local el desfase es **0** (XAMPP va en hora del sistema), así que
  esto **no se reproduce en local**.
  - **Los guards de escritura están BIEN**: `CalificacionModel::periodoEstaBloqueado`
    (`strtotime() < time()`), `ConductaModel::periodoEditable` y
    `PublicacionBoletaModel::ahora()` resuelven el "ahora" en **PHP** con `America/Lima`.
    El docblock de `PublicacionBoletaModel` ya advertía la trampa por escrito.
  - **Las dos que no:** `AsistenciaModel.php:50` y `ConductaModel.php:53` calculan su
    columna `editable` con `NOW() <= p.limite_notas` **en SQL**. En prod eso cierra la
    ventana **5 horas antes** que el guard real: durante ese rato la UI muestra el
    bimestre como no editable mientras el servidor **sí** aceptaría la escritura.
  - **Sentido del error: conservador** (nunca abre lo que debería estar cerrado), y por
    eso ha pasado desapercibido. Pero es incoherencia entre lo que la pantalla dice y lo
    que el sistema hace, justo en el patrón que ya mordió el 04/08 con la asistencia de
    B2 («el plazo venció sin que nadie lo notara»).
  - ⚠️ **Al fijar un `limite_notas` a primera hora de la madrugada el margen se come
    entero**: un límite a las 04:00 de Lima deja la UI de asistencia/conducta en "no
    editable" desde las 23:00 del día anterior.
  - **Regla para cualquier consulta o script nuevo:** el "ahora" de estos criterios se
    resuelve en PHP, o en SQL con `UTC_TIMESTAMP() - INTERVAL 5 HOUR` (Perú no aplica
    horario de verano). **Nunca `NOW()`.** Aplicado ya en
    `database/verificaciones/verif_post_cierre_bimestre.sql`, cuyo bloque 0 devuelve el
    `desfase_horas` del entorno.
  - 🔎 **Barrido hecho al corregirlo (10/08):** son las **únicas dos** comparaciones de
    `limite_notas` contra `NOW()` en `app/`. El resto de criterios temporales de plazos ya
    se resolvían en PHP.
  - 🔴 **CONSECUENCIA MAYOR, MEDIDA Y NO ABORDADA: EN PROD CONVIVEN DOS HUSOS DENTRO DE LA
    MISMA TABLA.** Las columnas con `DEFAULT CURRENT_TIMESTAMP` las sella el **motor**, o
    sea en **UTC** (verificado en la migración 044: `periodos_publicacion.creado_en`; y en
    la 023: `orden_merito_snapshot.generado_en`), mientras que las fechas que teclea un
    humano o calcula PHP —`publica_en`, `limite_notas`— están en **hora de Lima**.
    - **Efecto inmediato en la lectura de lo capturado hoy:** el `generado_en` del snapshot
      de B2 (**17:28:00**) y el `creado_en` de la publicación (**17:32:01**) son **UTC** →
      en hora de Perú fueron las **12:28** y las **12:32**. Ninguna conclusión de esta
      sesión cambia (ambas salen del mismo reloj y el orden relativo se mantiene), pero
      **las horas de auditoría no se leen como hora local**.
    - **Regla:** antes de comparar una columna sellada por el motor contra una hora
      calculada en PHP —o de mostrarla a un usuario— convertirla. Corregir el huso de esas
      columnas es un trabajo aparte, **no evaluado**: son muchas tablas y cambiaría el
      significado de datos ya escritos.
- **Staging `dev.sigacociap.net`** (diferido): subdominio alimentado por `dev`,
  BD propia, secretos fuera del repo.
- **Modo mantenimiento** (diferido, opcional): pantalla 503 + lista blanca staff.
- **CSP:** pasada dedicada — auditar estilos inline (`style="--pct:..."`) y el QR
  antes de aplicar `Content-Security-Policy`.
- ~~**Limpieza menor:** `.gitignore` + `AuthMiddleware`~~ **CERRADO (verificado
  29/07/2026).** Las reglas obsoletas de `public/assets/img/firmas/` ya no están en
  el `.gitignore` (solo queda `/storage/firmas/*.png`, que es la correcta), y
  `AuthMiddleware` **se eliminó** en el commit `eb0e9cf` (20/06/2026): la carpeta
  `app/Middleware/` ya no existe. La auth sigue siendo por controlador — invariante
  registrado en `CLAUDE.md` (Convenciones de código).
- **Nómina detallada admin/RA — etapa 2** (resumen estadístico); la etapa 1
  (nómina imprimible global con filtros) está implementada. Ver `docs/modulos/admin.md`.
- **Búsqueda del index de matrículas** no matchea códigos provisionales `P…`
  (cae en la rama de nombre). Ajuste chico en `construirFiltros` si se pide.
- **"Reemplazar docente" en sección unidocente** no actualiza `secciones.tutor_id`
  ni opera sobre todas las cargas del tutor → el entrante pierde `es_aula`
  (vista consolidada, Tutoría/Conducta).
- **Recreos:** no modelados (hoy son el hueco entre bloques). Primaria tiene 2 y
  secundaria 1 en horas distintas; chocan con el eje de fila única del imprimible.
- **Limpieza de `bloques_horario` (no urgente, hallazgo 29/07/2026):** la config de 2026
  tiene **9 bloques basura** con duración de 1 minuto y horarios imposibles (01:00-01:01,
  02:02-02:03, 03:02-03:03…), **todos con 0 sesiones**; y desde el arreglo del solape de
  DPCC quedó huérfano el bloque `15:45-17:20`. Nada de esto afecta a nadie hoy — barrerlo
  cuando se toque el módulo de horarios.
- **Logins para apoderados** (módulo diferido, análisis de impacto ya hecho):
  alta que reuse persona, soporte multi-hijo (`getHijo` LIMIT 1; 84 apoderados con
  >1 hijo), arreglar `desactivarUsuarioDeEstudiante`, política de contraseñas.
- **Módulo de suspensiones/disciplina** (diferido): principios de diseño fijados
  en `docs/decisiones-diferidas.md` — NUNCA manejarlas con estado `desactivado`.
- **Boletas de matrículas desactivadas por vías internas: EN PRODUCCIÓN
  (merge a `main` 08-09/07/2026)** — desactivados por deuda/baja: BORRADOR
  forzado; trasladados consumados vía gestión: última boleta OFICIAL con
  estructura anual completa; buscador de nómina docente ampliado; token público
  intacto. Regla completa en `docs/modulos/boletas.md`. Incluye la reubicación
  del registro de exoneraciones a "Gestión de la matrícula"
  (`docs/modulos/matriculas.md`).

## Compuerta de publicación de boletas — EN PRODUCCIÓN (22/07/2026)

> Cerrar un bimestre **ya no publica** sus boletas a las familias. Publicar es un
> acto separado, **por nivel y con fecha/hora**, desde `/admin/control`.
> Migración **044** aplicada en LOCAL y **PROD** (prod el 22/07/2026, importada a
> mano antes del merge que desplegó el código). El backfill retroactivo fue
> dentro de la migración; B1 verificado visible tras el deploy.
>
> **La regla completa, el modelo de datos, la matriz de reapertura, los 4 puntos
> de lectura y la verificación viven en `docs/modulos/boletas.md`** (sección
> "Compuerta de publicación de boletas"). No duplicar aquí.

Resumen de lo que cambió, para orientarse:
- Nueva tabla `periodos_publicacion` + `PublicacionBoletaModel` (punto único).
- `armar()` suma el umbral **`'archivo'`**: mismo corte de datos que `'oficial'`
  pero ignora la compuerta, para que RA pueda **imprimir antes de la reunión**
  de entrega (era la decisión que quedaba abierta; se cerró el 21/07).
- La compuerta oculta el bimestre **completo** (notas, asistencia, conducta y la
  columna), no solo las notas.
- `cerrar()` solo **restaura** publicaciones suspendidas por una reapertura;
  nunca crea publicaciones nuevas.
- Despublicar a mano **marca** la fila (motivo + autor auditados), no la borra.

~~**Sin resolver (fuera de alcance, decisión #9):** el **logro anual** sigue usando
"último bimestre cerrado" y debería exigir **año académico cerrado**.~~
✅ **CERRADO EL 10/08/2026: la decisión #9 se CANCELA y su premisa era falsa** — el código
siempre usó el ÚLTIMO periodo del año, no el último cerrado. Regla definitiva del usuario:
el logro anual se activa **solo con el IV Bimestre**. Ver "Compuerta de publicación" en
Pendientes de desarrollo y `docs/modulos/boletas.md`.

## Ética y Valores (Educación Religiosa) — plan de encendido (07/07/2026)

> SOLO SECUNDARIA — no tocar nada de primaria. Diseño completo en
> `docs/modulos/calificaciones.md` (sección "Ética y Valores"). Código en `main`
> (deploy 08/07) y **migraciones 035/036 YA aplicadas en PROD (09/07)** → el
> interruptor (C57) está encendido en producción. La fase de datos por UI de abajo
> queda como referencia histórica del encendido.

**Fase de datos en PROD (la ejecuta RA/admin por la UI, en este orden):**
1. Crear las **11 cargas TOE de secundaria** (área 24, docente = tutor vigente
   de cada sección, horas reales de tutoría 1-2h). Verificar duplicados antes
   (`cargas_academicas` sin UNIQUE KEY).
2. Currículum → área 24: `nombre_boleta = 'Ética y Valores'`,
   `alias_boleta = '(Educación Religiosa)'`. Verificar `nombre_siagie` NULL.
   → **empaquetado en migración `035_area_etica_boleta`** (el `nombre_siagie`
   NO se toca ahí; se decide al construir el exportador SIAGIE de secundaria).
3. Currículum → área 14 (Ed. Religiosa secundaria): **quitar** el alias huérfano
   "(Ética y Valores)" (nunca se imprimió: el área no tiene cargas ni notas).
4. Exoneraciones de religión: registrarlas **contra el área 24** (motivo:
   "Exoneración de Educación Religiosa"). El candado nuevo impide exonerar si
   ya hay notas vivas.
5. **Interruptor (al final):** crear la competencia del área 24 —
   `codigo=C57`, nombre_corto "Actúa con valores éticos y conciencia moral",
   nombre_completo "Actúa con valores éticos según los principios de su
   conciencia moral en situaciones concretas de la vida escolar y comunitaria."
   Al existir, la card aparece sola a los 11 tutores.
   → **empaquetado en migración `036_competencia_etica_valores`** (correr
   DESPUÉS de 035; en local resultó id 127).

**Operación:** criterios libres del tutor (flujo normal); exonerados = fila EXO
sin input (ya genérico); la sección de transversales NO aparece en la carga TOE
(exclusión nueva). Hito A fuerza bloqueos del tutor como a cualquier docente.

**Comunicación (colegio):** comunicado escrito en la PRIMERA entrega de boletas
del II Bim (área oficial evaluada por su dimensión de conciencia moral, a cargo
del tutor; derecho de exoneración disponible). NO diferir a fin de año.

~~**Datos de ensayo en LOCAL**~~ 🔴 **RECETA DE BORRADO RETIRADA EL 17/08/2026 — YA NO
QUEDA NADA QUE LIMPIAR Y EJECUTARLA HABRÍA DESTRUIDO DATOS REALES.**

Aquí vivía un `DELETE` anclado por **ids auto-incrementales** (carga 416, exoneración 2,
sección 13, cierre de conducta 25) para retirar los datos sembrados en la demo del 08/07.
Las resincronizaciones de la copia local desde producción (07, 10 y 12/08) **borraron el
ensayo y reciclaron esos ids con registros reales**. Medido el 17/08:

| El texto decía | Qué es hoy en esa fila |
|---|---|
| carga `416` — 1.º A sec., ensayo de Ética | **Tutoría (TOE) de 2.º A de PRIMARIA**, área 23, activa |
| exoneración `id=2` — matrícula 198, «ENSAYO LOCAL» | **La exoneración REAL de la matrícula 530** (NOLASCO ALVARADO, YURIANA), el caso verificado end-to-end el 05/08 |
| sección `13` — 510 respuestas sembradas | **1.º A de Secundaria**, con **240 respuestas reales** de un B2 cerrado y publicado |
| cierre de conducta `id=25` | Pertenece a la **sección 20** (4.º A Sec), no a la 13 |

**No existe ninguna exoneración con motivo «ENSAYO»:** las únicas dos vivas son reales, de
primaria y área 5 — exactamente las que contó la auditoría de la migración 050.

🔎 **LECCIÓN, y es la del endurecimiento de la 048 otra vez:** un `DELETE` anclado por id
**no falla** cuando el id cambia de dueño; borra en silencio el registro equivocado. Una
receta de limpieza que sobreviva a una resincronización tiene que anclarse en algo que
identifique al DATO —un motivo, un marcador, una fecha— nunca en su id. Lo único que salvó
esto fue medir antes de ejecutar.

La competencia **C57** (área 24) nunca fue ensayo: la crea la migración `036`. No borrarla.

## Exportación SIAGIE (implementada 03/07 — B1 cerrado en prod el 20/07)
- **B1 COMPLETO subido al SIAGIE sin rebotes (20/07/2026, confirmado por el
  usuario):** todas las notas del I Bimestre (primaria y secundaria) se llenaron
  por este flujo y el SIAGIE aceptó los archivos. Esto valida end-to-end el
  pipeline y cierra los pendientes de "piloto de re-importación", "verificar
  end-to-end" y "reprocesar actas de primaria". Lo que sigue son mejoras
  (automatización del lote) y los diferidos de secundaria, no correcciones.
- **Módulo web "Actas SIAGIE" (12/07):** UI para admin/RA (subir → previsualizar
  con resolución de identidad → confirmar → descargar). Flujo efímero, una
  sección por vez (primaria y secundaria). Las libs se movieron de
  `scripts/siagie/lib/` a `app/Siagie/` (namespace `App\Siagie\`, autocargable) y
  la orquestación del CLI se extrajo a `app/Siagie/LlenadorSiagie.php` (CLI =
  wrapper delgado). Detalle en `docs/modulos/export-siagie.md`.
- **Cambio de sección sin tramitar — detección (12/07):** el módulo detecta si una
  fila `sin_match` es un alumno que SIGA tiene en OTRA sección del mismo grado y
  permite resolverlo por DNI (escribe sus notas reales, marcado como cruce en el
  reporte). Ver `docs/modulos/export-siagie.md`.
- **PENDIENTE — trámite de "cambio de sección" en SIGA (evaluar):** hoy no existe;
  la matrícula fija `seccion_id` al crear y no hay `UPDATE`. Mover un alumno a
  mitad de bimestre es delicado (sus `calificaciones` cuelgan de las `cargas` de
  la sección vieja). Por ahora el módulo SIAGIE solo lo detecta/resuelve en el
  acta; la reconciliación real en SIGA queda como decisión de diseño futura.
- **Piloto de re-importación: SUPERADO.** El B1 completo se re-importó al SIAGIE
  sin rebotes (20/07); los shared strings anexados fueron aceptados, así que el
  fallback previsto en `docs/modulos/export-siagie.md` no hizo falta.
- **Discrepancia de catálogo — Inglés C1: RESUELTA (histórico).** Renombrada al
  nombre oficial CN (con "oralmente") directo en BD local+prod el 14/07;
  formalizada en la migración `041` (16/07, no-op donde ya está corregida). Las
  actas de primaria llenadas ANTES del 14/07 (4°A/4°B B1) salieron con Inglés en
  blanco y ya fueron reprocesadas dentro del cierre de B1 del 20/07. Diagnóstico
  completo en `docs/modulos/export-siagie.md`.
- **`codigo_siagie` de primaria: POBLADO** (migración `041`, 16/07) con los
  códigos del archivo RegNotas real de 4°A B1. El fallback por posición ya
  opera en ambos niveles; una discrepancia de nombre futura ya no deja la
  columna muda.
- **Variante SECUNDARIA — IMPLEMENTADA (12/07), B1 operativo.** Verificada con
  nóminas reales (S1A, S5B). NL literal confirmado; diferenciación por área
  (migración 039) → MATE (4/4, sin choque con talleres) e Inglés (por posición)
  ya se llenan. Detalle en `docs/modulos/export-siagie.md`.
- **EXCEPCIONES DE HOJA — IMPLEMENTADAS (27/07/2026, en `dev`, sin migración).**
  Reglas del colegio confirmadas por el usuario; viven en
  `LlenadorSiagie::EXCEPCIONES_HOJA` (se descartó la tabla de datos: son reglas
  curriculares estables, no configuración por bimestre). Regla completa en
  `docs/modulos/export-siagie.md` §"Excepciones de hoja".
  - **`035-EREL` ← Ética y Valores, TODOS los grados de secundaria.** El área
    Ed. Religiosa tiene 0 cargas; evalúa el tutor. La nota se DUPLICA en las 2
    columnas. Exonerados → EXO sin traducción (la exoneración ya está contra esa área).
  - **`032-ETRA` ← GAMA (CT4), SOLO 5°.** En 5° no se dicta EPT (0 cargas; verificado:
    1°=3, 2°=2, 3°=2, 4°=2, 5°=0); sus horas las ocupa el Taller de Pre-Cálculo, que
    **no se reporta al SIAGIE** (decisión del colegio). GAMA queda escrita 2 veces en
    5°: hoja `0007` + hoja `032`.
  - ⚠️ **id 57 = GAMA; código C57 = Ética (id 127).** Las reglas anclan por
    `nombre_boleta`/`codigo_minedu`, nunca por id.
  - **VERIFICADO CON ACTA REAL DE 5° (29/07/2026, `S5B.xlsx`) — pendiente CERRADO.**
    El libro **sí trae la hoja**; su tab real es **`032-ETRA`** (la doc decía
    `032-EPT`, que era una abreviatura asumida — irrelevante para el código, que
    matchea por el código `032` del tab). Tiene **una sola columna**, así que GAMA no
    se duplica ahí. Su leyenda es **`01 = Gestiona proyectos de emprendimiento
    económico o social`**, o sea la competencia de **EPT (C53)**, NO la de GAMA:
    **la excepción es NECESARIA**, sin ella esa columna saldría en blanco en silencio.
    `CT4` resuelve a una sola competencia, así que no cae en la degradación segura.
    Detalle en `docs/modulos/export-siagie.md`. Sigue siendo buena práctica correr
    `--simular` sobre la primera acta de B2 antes de subirla.
- **VÍNCULOS Y COBERTURA — IMPLEMENTADO (28/07/2026, en `dev`, sin migración).**
  Etapa 1 del gestor de vínculos SIGA↔SIAGIE. Detalle en
  `docs/modulos/export-siagie.md` §"Vínculos y cobertura".
  - **`/admin/actas-siagie/vinculos`** (solo lectura): áreas con notas y SIN destino,
    vínculos configurados, excepciones de hoja resueltas y colisiones de código.
    La tabla parte de `areas`, NO de `calificaciones`: un vínculo existe aunque el
    bimestre no tenga notas (si no, Ética y Ed. Religiosa desaparecían justo cuando
    hacía falta auditarlas). El índice de hojas ocupadas va por **nivel + código**:
    sin el nivel, la regla `035` de secundaria marcaba como reemplazada a la
    **Ed. Religiosa de PRIMARIA**, que se llena con normalidad (381 notas en B1).
    **Primaria no se toca en nada** — verificado en los 6 grados.
  - **`codigo_siagie` editable en Currículo** (antes solo por migración) con guardas
    de formato y de colisión → **activar un taller que el SIAGIE ya reconozca ya no
    necesita despliegue**.
  - **Hallazgo medido:** en B1 se perdieron **321 notas bloqueadas** de talleres
    (Raz. Mat. 272 + Pre-Cálculo 49) que nunca llegaron al acta, en silencio. En B2
    ya van 24 del Taller de Raz. Mat.
  - ⚠️ **BLOQUEO DE FONDO (29/07/2026): los talleres NO tienen hoja en el SIAGIE.**
    Al ir a asignarles el `codigo_siagie` se verificó, leyendo los dos RegNotas
    reales de B1 (`S1A.xlsx` de 1°A y `S5B.xlsx` de 5°B), que **ambos libros traen
    las MISMAS 15 hojas y ninguna es de taller** — y 1°A es una sección donde SÍ se
    dicta el Taller de Raz. Mat. **Asignar el código no resolvería nada: no hay hoja
    que llenar.** Lo que falta no está en SIGA sino en el **plan de estudios
    registrado en el SIAGIE** → es una gestión del colegio ante SIAGIE/UGEL, no un
    cambio de código. Alcance (local, B1 completo; confirmar en prod): Raz. Mat.
    = 1° a 5°, 11 secciones, 273 notas; Pre-Cálculo = 5° A y B, 49 notas.
    - 📈 **RE-MEDIDO EL 17/08/2026 — EL ALCANCE SE MULTIPLICÓ POR CUATRO AL CERRARSE B2.**
      Ya no son las 321 notas de B1: son **1332 notas** que no llegan al SIAGIE.
      **Taller de Raz. Matemático 1133** (11 secciones · B1 272 · B2 861) y **Taller de
      Pre-Cálculo 199** (2 secciones · B1 49 · B2 150). Los dos siguen con
      `codigo_siagie` en NULL, que es lo correcto mientras no exista la hoja.
      **La decisión NO cambia** —Raz. Mat. se dará de alta cuando la UGEL apruebe, y
      Pre-Cálculo no se reporta— pero el volumen que espera esa aprobación sí, y crecerá
      otro tanto con B3 y B4.
    **CAUSA RAÍZ Y DECISIONES (29/07/2026, usuario):** hay una **aprobación de talleres
    PENDIENTE en la UGEL de Huaraz** y por eso el SIAGIE no habilita esas hojas.
    **Taller de Raz. Mat. → SE DARÁ DE ALTA (sí o sí se registrará en el SIAGIE):**
    cuando la UGEL apruebe, el RegNotas traerá su hoja y bastará teclear su
    `codigo_siagie` en Currículo, sin despliegue; hasta entonces sus notas viven solo
    en SIGA y **no son un olvido que perseguir**. **Taller de Pre-Cálculo → NO se
    reporta** (decisión firme). La opción "área anfitriona" (etapa 2) queda descartada
    de hecho. Detalle en `docs/modulos/export-siagie.md`.
  **Diferido:**
  - **Taller SIN hoja propia** (reportar bajo un área anfitriona): es el caso
    peligroso — sus 3 competencias son homónimas de Matemática (C54↔C44, C55↔C47,
    C56↔C45) y exigiría invertir la regla "ante homónimos gana la competencia de la
    hoja", que es la que hoy protege el llenado de Matemática e Inglés. Requeriría el
    gestor de vínculos completo (etapa 2, columna→competencia).
  - **Selector de talleres por nómina** (efímero, sin flag persistente) — etapa 3.

## Rediseño del orden de mérito (COMPLETADO — 25/07/2026)

> Plan aprobado por el usuario. 3 fases, en `dev`. Reglas de negocio confirmadas:
> (1) el ranking permanece por `tipo` (fuera solo `trasladado`/`retirado`); (2) el
> snapshot OFICIAL es inmutable una vez que el periodo **estuvo publicado** (compuerta
> 044, monotónico, a nivel de periodo — B1 se publicó con ambos niveles a la vez);
> (3) cierres/reaperturas/rectificaciones posteriores a la publicación generan una
> versión **rectificada NO oficial**, nunca tocan el oficial.

- **Fase A — filtro por `tipo` (HECHA, `dev`, 24/07):** `OrdenMeritoModel` pasó los 5
  `estado='aprobada'` a `tipo NOT IN ('trasladado','retirado')`; anclaje de retorno
  intacto; verificado (pendientes entran, trasladado/retirado fuera, retorno OK, B1 sin
  empates nuevos). Docs: `orden-merito.md` §7.1 + invariante en CLAUDE.md.
  **Commit `c81a963`; EN PROD (merge `dev`→`main` `68968bb`, 25/07/2026).**
- **Fase B — inmutabilidad + versión no oficial (HECHA, `dev`, 24/07):** migración
  **046** additiva (`periodos_publicacion.primera_publicacion_en` con backfill + tabla
  `orden_merito_rectificado`); `PublicacionBoletaModel::fuePublicado()` (monotónico);
  `OrdenMeritoModel::registrarRanking()` (punto único con candado) +
  `generarSnapshotRectificado()` + `calcularFilasRanking()` (refactor) + lectores;
  `cerrar` y rectificación migrados a `registrarRanking`; card + vista de solo lectura
  en `/admin/control`. Verificado en local (candado: con oficial presente + B1
  publicado, la 1ª llamada YA rechaza tocar el oficial → rectificado; oficial
  intacto; limpieza restauró snapshot vacío). **Migración 046 aplicada en LOCAL
  Y PROD (25/07); commit `bf31526`; EN PROD (merge `68968bb`, 25/07/2026).**
  Gulp NO requerido (reusa clases).
- **Fase C — reconstrucción de B1 (HECHA, EN PROD 25/07/2026):** el usuario decidió
  el roster por REGLA (no por el documento de dirección): **todos los estudiantes con
  calificaciones bloqueadas/aprobadas en B1, SIN filtro de tipo**, conservando el anclaje
  de retornos y la exclusión de áreas transversal/tutoría. Resultado: **snapshot oficial
  de B1 = 528 filas** (roster en vivo con filtro de tipo daba 520/519; la regla reincorpora
  8 `trasladado` + `541` `retirado`, todos continuadores con notas B1 completas y
  bloqueadas). "Bloqueadas y aprobadas" no cambia el universo (0 calificaciones de mérito
  B1 sin bloqueo). El único alumno realmente integrado en B2 (1, sin notas B1) queda fuera
  por construcción. **0 empates pendientes** con el roster de 528 (verificado con la cascada
  real `aplicarDesempate`). Snapshot generado por script one-off (SIN filtro de tipo) e
  importado a mano por phpMyAdmin (DELETE 519 previas + INSERT 528; `filas=528, mn=1,
  mx=72`). **Caso especial de B1: la regla GENERAL del código NO cambió** (sigue filtrando
  por tipo, Fase A). El candado 046 protege el oficial (B1 publicado → rectificaciones
  futuras van a `orden_merito_rectificado`). ⚠️ **NO correr `backfill_orden_merito.php`
  en prod** (usa `generarSnapshot` con filtro de tipo → sobrescribiría el 528 por 519).

## Pendientes operativos (usuario / colegio)
- **Alumno retirado (feature del 22/07, migración 045):** marcado como `retirado`
  en prod ✓ (22/07). **Limpieza quirúrgica de conducta B2 HECHA en prod (24/07)**:
  matrícula 541 (DNI 63361405, sección A, `conducta_cerrada=0` verificado), 10 filas
  de `conducta_respuestas` del II Bim eliminadas; `calificaciones_conducta` no tenía
  fila (0). Notas académicas intactas. Respaldo reversible vía tablas
  `_bkp_conducta_resp_541` / `_bkp_calif_conducta_541` (dejadas en prod como red de
  seguridad → **borrarlas tras el cierre de conducta de la sección A**).
  - **ACTUALIZACIÓN 04/08/2026 — la 541 YA NO ES `retirado`: es `trasladado`**
    (su traslado se consumó; `tipo_anterior='continuador'` intacto). El único
    `retirado` que queda en prod es la **357** (HUAMAN VIENRICH). Reparto actual de
    `matriculas.tipo`: 520 continuador · 9 trasladado · 5 nuevo · 1 retirado.
    - **No mueve nada del mérito:** ambos tipos están excluidos del roster en vivo, y
      el snapshot oficial de B1 está congelado en 528 (los 10 reincorporados de la
      Fase C son exactamente estas 10 matrículas: 191, 281, 307, 308, 333, 357, 541,
      581, 613, 646). 518 en vivo + 10 = 528 ✓.
    - **Sí cambia su BOLETA:** como `trasladado` la 541 pasa a calificar para la
      última boleta **OFICIAL** con estructura anual completa vía gestión, donde como
      `retirado` salía forzada a BORRADOR. Ver `docs/modulos/boletas.md`.
  - ✅ **CERRADO — los backups YA NO EXISTEN en prod (migración 048, 06/08/2026).** La
    condición se verificó el 04/08 (conducta de B2 con 23 cierres, la sección 18 entre
    ellos) y el `DROP` se ejecutó el 06/08 tras un PASO 1 que devolvió `PUEDE_BORRARSE`
    con la identidad completa. **En LOCAL tampoco existen ya** (medido el 06/08). Detalle
    en la migración 048, arriba.
- ✅ **ASISTENCIA DE B2 — REGISTRADA Y BLOQUEADA EN PROD (05/08/2026). Ya NO bloquea el
  cierre.** El usuario amplió `limite_notas` y capturó las 23 secciones entre el 04/08
  16:29 y el 05/08 00:01. **Verificado el 05/08** sobre la copia local sincronizada:
  **525 filas** en `inasistencias` de B2 contra un roster canónico de **524** →
  **0 huecos**; **23 de 23 secciones** con cierre vivo, sin duplicados; 352 alumnos con
  alguna incidencia y 173 en cero absoluto (que es el dato válido "registrado sin
  incidencias", distinto de "sin registro"). `verif_roster_asistencia.php` da **OK** en
  sus 3 bloques. Los 7 cierres anulados entre las 18:10 y las 23:52 corresponden a
  rehacer las secciones 1-6 tras aplicar el fix del roster.
  - ⚠️ **Al terminar, `limite_notas` quedó en `2026-08-04 23:59` → `periodoEditable(2)`
    es `false` otra vez.** Cualquier corrección de asistencia, conducta o notas de B2
    exige volver a ampliar el plazo (y eso reabre la calificación docente: re-medir el
    termómetro antes de cerrar).
  - 🔴 **SECUELA ABIERTA — DOBLE CONTEO EN LA BOLETA DE BALTAZAR PINTO, SHALOM CRISTEL
    (matrícula oficial 190 / operativa 692, retorno #1 del 21/06/2026).** Ambas matrículas
    quedaron con `faltas=2` en B2, y `getDelBimestreUnion` **suma las dos fuentes** → su
    boleta muestra **4 faltas** en vez de 2. Verificado ejecutando el modelo real
    (`UNION B1 -> 2` correcto, `UNION B2 -> 4` incorrecto).
    - **Origen:** la fila de la 190 se escribió el 04/08 a las **16:40:06**, con el roster
      VIEJO todavía activo. Rehacer la sección con el roster nuevo no la borró.
    - **La UI NO puede corregirlo:** con el roster nuevo la 190 no está en la grilla y
      `matriculaEnRoster` rechaza toda escritura sobre ella (403). Solo por SQL.
    - **REGLA (confirmada por el usuario el 05/08):** todo registro va a la matrícula
      **OPERATIVA**; el documento se emite con la **OFICIAL**. El corte es por bimestre:
      la fila de **B1 en la 190 es CORRECTA** (el retorno es del 21/06 y B1 cierra el
      16/06) y **no se toca**; la de **B2 sobra**.
    - **ACCIÓN en prod (antes de cerrar/publicar B2): aplicar la migración
      `047_retorno_grado_asistencia_solapada.sql`**, que trae PREVIEW, el DELETE
      acotado al solape y las consultas de verificación. Correr el PREVIEW primero:
      debe devolver exactamente 1 fila.
    - Las otras **11 filas fuera del roster son todas de B1** (trasladados/retirados),
      cada una de una sola matrícula → no duplican nada. No tocarlas.
    - Detalle de la trampa y consulta de guardia permanente: memoria
      `project_retorno_grado_doble_conteo`.
- **RETORNO DE GRADO — REGLA A IMPLEMENTADA (05/08/2026, en `dev`, SIN MIGRACIÓN).**
  Se evalúa en la matrícula **operativa**, se documenta con la **oficial**, y los
  datos **no se copian ni se mueven**. Doc completo: `docs/modulos/retorno-grado.md`.
  - **F1 — `BoletaPublicaModel` conoce el retorno.** Dos constantes privadas
    (`SQL_EXCLUIR_OPERATIVA`, `SQL_TIENE_BLOQUEOS`) aplicadas a las 3 consultas que
    alimentan índice, vista previa, impresión masiva y archivar. Corrige un defecto
    **preexistente desde el 21/06**: en B1 el estudiante salía **dos veces**
    (517→**516**) y en B2 **desaparecía** de su sección oficial (2° B: 18→**19**).
    El contador de 1° B en B1 pasó de mentir (19 aprobables / 18 generadas) a 18/18.
  - **F2 — candado del bimestre en curso + fin de la copia.** El retorno se bloquea
    si la oficial ya tiene notas, criterios u omisiones en un periodo `activo`
    (`evaluacionEnBimestreActivo`, en `create()` y `store()`). Se eliminó el
    `INSERT IGNORE` que duplicaba todas las calificaciones; asistencia y conducta
    de los bimestres **activos** ahora se **MUEVEN** a la operativa. El retorno real
    del 21/06 **pasa** el candado (no tenía nada en B2).
  - **F3 — token único.** `BoletaController::resolveToken` rechaza (404) el token de
    una matrícula operativa. Medido: **1 token de 531** deja de resolver, y nunca se
    generó boleta ni se consultó con él. Los QR ya emitidos no se ven afectados: se
    anclan a la matrícula IDENTIDAD, que en un retorno es siempre la oficial.
  - **Verificación:** `database/verificaciones/verif_retorno_grado.php` (solo lectura,
    corre en prod). Su bloque 1 prueba la **equivalencia** con la lógica anterior:
    B1 `viejo=517 nuevo=516 (sale 692)`, B2 `viejo=518 nuevo=518 (sale 692, entra 190)`,
    **0 matrículas ajenas afectadas**. El bloque 5 seguirá FALLANDO hasta que se
    aplique el `DELETE` de F0 en prod — es la señal de que hace su trabajo.
  - **Decisiones del usuario (no re-preguntar):** la copia de B1 **NO se borra** (es la
    base probatoria del snapshot de mérito publicado: sin ella el promedio 12.05 deja
    de ser reproducible); el token de la operativa se da de baja; la Regla A rige de
    aquí en adelante y **no se corrige el snapshot de B1**, que queda con la
    estudiante en 1° B por el candado 046.
  - **Pendiente relacionado:** la regla del retorno está escrita a mano en ~15 sitios.
    Unificarla en un punto único es un refactor con nombre propio, fuera de este lote.

  **Diagnóstico original (04/08/2026):** `inasistencias` tenía **528 filas en B1 y 0 en
  B2**; `cierres_asistencia`, 23 secciones en B1 y **0 en B2**. Cerrar y publicar así
  habría mandado a las familias asistencia **en ceros**, que es un dato FALSO, no ausente
  (la boleta pinta una columna por bimestre cerrado y suma lo que encuentre).
  - **Causa de que no se pudiera registrar:** `limite_notas` de B2 = **04/08/2026
    04:00**, ya vencido. El bimestre sigue `activo`, pero `AsistenciaModel::periodoEditable`
    exige `activo` **Y** estar dentro del plazo → la captura se cerró sola.
    ⚠️ **El registro de asistencia NO requiere el bimestre cerrado — requiere lo
    contrario.** Cerrarlo lo deja en solo lectura para siempre.
  - **Mismo plazo corta también notas y conducta** (`CalificacionModel::periodoEstaBloqueado`,
    `ConductaModel`), no solo asistencia.
  - **Secuencia ejecutada:** ampliar `limite_notas` desde `/director/anios/1` →
    desplegar el fix del roster → registrar las incidencias de las 23 secciones en
    `/admin/asistencia` → bloquear cada sección. **Cumplida.**
  - Que esto no se repita es el objeto del plan `docs/modulos/cierre-cuatro-registros.md`.
- ✅ **ROSTER DE ASISTENCIA ≠ ROSTER DE NOTAS — EN PRODUCCIÓN (commit `de449e2`, pusheado
  el 04/08/2026).** `/admin/asistencia` filtraba `m.estado='aprobada'` e ignoraba `tipo` y el
  retorno de grado, así que **los `pendiente` y `desactivado` no aparecían en la grilla**:
  nadie podía registrarles faltas y su boleta salía con 0 inasistencias (dato falso). A
  la vez mostraba la matrícula **oficial de un retorno activo**, o sea el grado donde la
  estudiante ya no está. Es el mismo arreglo que conducta recibió el 09/07 y que a
  asistencia se le quedó pendiente. Detalle en `docs/modulos/admin.md`.
  - **Impacto medido:** 6 matrículas entran (todas `pendiente`: 220, 470, 696, 690, 695,
    693) y 1 sale (la 190, oficial del retorno #1). **Las 6 quedaron registradas en B2**,
    o sea el fix cumplió su objetivo. `verif_roster_asistencia.php` (solo lectura, corre
    en prod) da OK en las 23 secciones.
  - ⚠️ **El orden se respetó a medias y dejó cola:** el registro empezó a las 16:29 con el
    roster VIEJO; a las 18:10 se anularon las secciones 1-6 y se rehicieron con el nuevo.
    Rehacer NO borra las filas que el roster nuevo dejó fuera → de ahí el doble conteo de
    la matrícula 190 (ver la entrada de asistencia de B2, arriba). **Lección: al cambiar
    un roster, rehacer no basta; hay que barrer las filas huérfanas.**
  - Decisión del usuario: aplica a **todos los periodos, incluidos los bloqueados**, así
    que el imprimible oficial de B1 se recalcula con el roster nuevo. Sin migración.
- ✅ **DECISIÓN CERRADA — ÉTICA Y VALORES ENTRA al orden de mérito en TODA secundaria,
  5.º incluido (05/08/2026). IMPLEMENTADO en `dev`, sin migración.**
  - **Razón:** Ética **no es tutoría**. Es la nota del área-curso *Educación Religiosa de
    secundaria*, que no tiene cargas propias (área 14: 0 cargas, 0 notas) y la evalúa el
    tutor por su carga TOE. Sin la excepción, **el mismo curso pesaba en primaria y no en
    secundaria** — una asimetría solo técnica.
  - **Deroga la regla del 04/08 que la sacaba de 5.º.** Aquella listaba «Ética y Valores»
    y «Educación Religiosa» como áreas distintas, siendo la misma. Además Ética **sí se
    dicta en 5.º** (50 notas en B2, bloqueadas), así que excluirla solo de ese grado
    habría exigido una excepción por grado hardcodeada en el SQL.
  - **Qué se tocó:** las 2 queries de `OrdenMeritoModel` (excepción por `nombre_boleta`);
    `verif_universo_merito.php` (Ética sale de las prohibidas de 5.º, sus 2 consultas
    replican la excepción y se añadió un **guard anti-duplicado** de Ed. Religiosa para
    los 5 grados); comentario de `ControlOperativoModel` (su filtro **ya** incluía Ética
    → convergió solo). `alerta_evaluacion_incompleta.sql` no necesitó cambios.
  - **Impacto medido con el MOTOR REAL (no solo promedio):** primaria **0 cambios**;
    secundaria mueve 29/18/7/9/13 puestos por grado (1.º a 5.º) con salto máximo **3**;
    **ningún primer puesto cambia** → la media beca no se altera. Tras el cambio:
    **0 empates pendientes** y **0 alumnos con evaluación incompleta** en B2.
    - ⚠️ La medición del 04/08 (76 puestos, salto 9, un primer puesto cambiando) era
      **incorrecta**: ordenaba solo por promedio y resolvía el área con `comp.area_id` en
      vez de `COALESCE(sa.area_id, comp.area_id)`, descartando las áreas con subáreas.
  - **B1 intacto por tres vías:** 0 notas de Ética; snapshot publicado e inmutable
    (candado 046); los lectores usan el snapshot (528 filas), no el cálculo en vivo.
  - **Alias del área 14 limpiado por el usuario** el 05/08 (`alias_boleta` de
    «(Ética y Valores)» a NULL): cierra el paso 3 del plan de encendido del 07/07 y
    elimina la ambigüedad que originó la regla errónea.
  - ✅ **~~Refuerzo recomendado: desactivar el área *Ed. Religiosa* de secundaria~~ —
    PROBADO Y DESCARTADO EL 10/08/2026 (decisión del usuario). EL ÁREA SE QUEDA `activa`.**
    La idea era que, contando ya Ética en el mérito, una carga sobre esa área haría contar
    el **mismo curso dos veces**. Se aplicó en local, se midió el efecto completo y se
    revirtió: **no compensa**.
    - **Qué se midió con el área en `activa = 0`** (0 cargas, 0 notas, 0 criterios, 0
      exoneraciones, así que ningún dato se movió): el universo del mérito siguió **OK**,
      las boletas intactas (**1965 filas de nota, 0 perdidas**) y los exonerados
      conservaron su `EXO`.
    - **Lo que sí cambiaba, y es el motivo de descartarlo:**
      1. **Desaparece de `/admin/actas-siagie/vinculos`** — esa pantalla incluye áreas
         inactivas solo si tienen notas (`WHERE a.activa = 1 OR notas > 0`) y esta tiene 0.
         Se perdía la fila donde se audita el vínculo **`035-EREL`**, que es justo lo que
         esa pantalla existe para no esconder.
      2. **`verif_plan_completo_boleta.php` da un rojo FALSO**: su bloque 1 filtra
         `a.activa = 1`, así que el área sale del catálogo y sus 5 exclusiones esperadas
         (`Secundaria|1..5|Educación Religiosa`) quedan sin cumplir. La exclusión sigue
         siendo cierta en la boleta — cambia el motivo, no el resultado.
      3. **Rompía el espejo local↔prod** en un dato de configuración.
    - **La protección que queda es DETECTIVA, no estructural:** el guard anti-duplicado de
      `verif_universo_merito.php` **falla (exit 1)** en cuanto esa área empiece a aportar
      al mérito. Detecta después en vez de impedir antes, y hay que correrlo a mano. Es un
      riesgo asumido a conciencia: el invariante de `CLAUDE.md` («debe seguir **sin
      cargas**») sigue siendo la regla, y ahora es la ÚNICA.
    - ⚠️ **Si alguien vuelve a plantearlo, no repetir el experimento: está hecho.** Y si
      aun así se desactiva, el rojo del punto 2 es **esperado**, no una regresión.
    - **El `alias_boleta` del área 14 sigue siendo `(Ética y Valores)`** pese a que este
      documento lo daba por limpiado a NULL el 05/08. Es inocuo (área sin cargas: nunca se
      imprime), pero el dato no coincide con lo escrito.
  - **Esto NO alinea SIGA con el SIAGIE** y no lo pretende: quedan 3 divergencias (GAMA
    va al acta y no al mérito; los 2 talleres cuentan en el mérito y no tienen hoja).
- ✅ **FORMATO OFICIAL EN TODAS LAS BOLETAS — EN PRODUCCIÓN (corregido el 04/08/2026,
  desplegado el 05/08 en `c8681da`).** La regla de formato del 09/07 (las 4 columnas de bimestre siempre) se había
  aplicado solo a `/boleta/ver/{token}` y a la boleta del trasladado: la **impresión masiva**
  (`/admin/boletas-publicas/{id}/boletas-alumno`), el **ZIP de archivo** y la **digital de
  familias** llamaban a `armar()` sin el 4.º parámetro y colapsaban columnas. El papel que
  RA firma y entrega salía con **1 columna** mientras la misma boleta por QR salía con 4,
  siendo el mismo componente `boleta/alumno.php`. Ahora **las 9 entradas** pasan
  `estructuraCompleta = true`. Detalle en `docs/modulos/boletas.md`.
  - **No es una fuga:** el flag gobierna la estructura, no los datos. Verificado con
    `verif_estructura_boleta.php` (solo lectura, corre en prod): con `'oficial'` hay 4
    columnas y solo aportan notas los bimestres cerrados **y** publicados, aunque B2 ya
    tenga notas. Su paso 3 compara los datos con y sin el flag y exige que sean idénticos.
  - **Decidirlo antes de imprimir B2:** si ya se entregó papel de B1 con una columna, el de
    B2 saldrá con formato distinto al de B1. Sin migración.
  - **La TABLA DE ASISTENCIA también** (mismo día, tras revisar las 4 vistas donde se
    dibuja): siempre 4 columnas, en boleta oficial y digital. Cada columna lleva
    `sin_registro`, que se pinta con **guion apagado** —nunca `0`— cuando el bimestre es
    `pendiente` o no corresponde al umbral. Cuando es `true` **no se consulta** la
    asistencia, así que la columna vacía no sale de datos que ese umbral no debe ver. Se
    añadió `.bd-asistencia__num--pendiente` al SASS de la digital, que no tenía ningún
    estado para "sin dato" y habría pintado ceros. `admin/asistencia/imprimir.php` y
    `seccion.php` NO se tocan: son otro documento (alumnos × contadores de una sección).
  - **EMITIR el documento oficial ahora exige el bimestre CERRADO.** En
    `/admin/boletas-publicas/{id}`, "🖨 Boletas" y "🗂 Archivar" se condicionaban solo a
    "hay ≥1 competencia bloqueada", así que se podía imprimir un lote entero **con la
    columna del bimestre vacía**. Dos capas: botones inertes con aviso en la vista +
    guard en `boletasAlumno()`/`archivar()` (son rutas GET que quedan en marcadores). La
    **vista previa NO se bloquea** —es la herramienta para decidir el Hito A— ni el
    enlace por token de cada alumno. Criterio en `periodoEsOficial()`, vía
    `boleta_estado_bimestre()`. ⚠️ Es **cerrado, no publicado** (`'archivo'` ignora la
    044 a propósito), y el **Hito A tampoco habilita** (da `'borrador'`, no `'oficial'`).
  - **En el índice `/admin/boletas-publicas`, los bimestres `pendiente` no se abren:**
    tarjeta inerte con badge "No iniciado" + guard en `porPeriodo()`. El **activo** sigue
    accesible (ahí vive la vista previa). Hubo que añadir `.bp-periodo-card.is-disabled`
    (el `.btn.is-disabled` existente exige la clase `.btn`) y `p.estado` a la query.
- ✅ **ASISTENCIA EN LA VISTA PREVIA DE BOLETAS — EN PRODUCCIÓN (corregido el 04/08/2026,
  posterior al deploy `de449e2`; desplegado el 05/08 en `c8681da`).** En
  `/admin/boletas-publicas/{id}/vista-previa` no aparecía la asistencia del bimestre en
  curso pese a tener las secciones bloqueadas: el cuadro se filtraba por
  `periodos.estado='cerrado'`, y **bloquear el registro de una sección
  (`cierres_asistencia`) NO cierra el bimestre**. La asistencia era además el único de
  los tres bloques por periodo que no honraba la excepción de la vista previa (notas y
  conducta sí). Ahora usa `periodoAportaNotas`, el mismo umbral de las notas.
  - Decisiones del usuario: alcance `'todos'` **y `'borrador'`**; los bimestres
    `pendiente` se pintan apagados (`--pendiente`, guion) en vez de con ceros; el total
    **suma el bimestre en curso**.
  - **`'oficial'` y `'archivo'` NO cambian** (equivalencia exacta verificada): las
    familias y el impreso siguen viendo solo bimestres cerrados —y publicados, en
    `'oficial'`—. Verificado con `verif_asistencia_boleta.php`, que simula el Hito A en
    transacción con ROLLBACK. Sin migración, sin Gulp (la clase CSS ya existía).
- ✅ **ORDEN ALFABÉTICO: LA Ñ IBA ANTES QUE LA N — EN PRODUCCIÓN (corregido y desplegado
  el 04/08/2026, `de449e2`).** Detectado por el usuario en la grilla de 4° A primaria (ÑIQUEN PAJUELO
  salía antes que NOLASCO REYES). Causa: las columnas de `personas` son
  `utf8mb4_unicode_ci`, que equipara Ñ ≡ N. Arreglado con `COLLATE utf8mb4_spanish_ci`
  en los **30 `ORDER BY`** de 19 archivos, con punto único `COLLATE_ES` /
  `orden_alfabetico()` en `helpers.php`. Detalle y alternativas descartadas en
  `docs/modulos/ui.md`.
  - **Impacto:** 58 personas con Ñ, pero **solo 4° A de primaria** cambia de orden entre
    las 23 secciones. Actas SIAGIE y orden de mérito **no se ven afectados** (el matcher
    normaliza Ñ→N en PHP; el mérito ya no desempata por apellido).
  - **NO se cambió la colación de las columnas** a propósito: rompería la búsqueda
    tolerante a la ñ (hoy "NUNUVERO" encuentra a NUÑUVERO) y arriesga
    `Illegal mix of collations`. Sin migración.
  - Fue en el **mismo deploy** que el roster de asistencia (decisión del usuario).
- ~~**Validar en móvil real** el botón "✕ Cerrar" de documentos en ventana nueva~~
  ✅ **PROBADO POR EL USUARIO EN MÓVIL REAL EL 17/08/2026 — FUNCIONA. Pendiente CERRADO.**
  El botón cierra la pestaña y no se acumulan al abrir varias boletas seguidas.
  - Era el último pendiente operativo que quedaba vivo, y **el único que no se podía cerrar
    desde el servidor**: lo que se prueba es el comportamiento de `window.close()` en el
    navegador del alumno o del apoderado, y eso solo lo dice un teléfono real.
  - No hace falta volver a probarlo salvo que cambie el mecanismo de apertura en ventana
    nueva de los documentos.
- ~~**Digitar horarios reales en prod**~~ ✅ **CERRADO EL 17/08/2026 — YA ESTABA HECHO Y
  NADIE LO ANOTÓ. Áreas huérfanas: 0.** Decía que faltaban los 11 cursos de 1.º A de
  secundaria (que quedaron «sin horario propio» tras la migración 031) y las áreas sin
  bloques reales tras la 030 (CyT/Matemática de primaria 4.º-6.º, Arte y Cultura de 1.º A
  de primaria).
  - **Medido:** hay **43 cargas activas sin horario propio**, pero las **43 están cubiertas
    por otra carga de su MISMA área en la MISMA sección** — que es la *regla general*
    documentada en `docs/modulos/horarios.md` («una carga puede existir sin horario
    propio»: el horario del área vive en la carga dueña), **no un defecto**. Son las
    subáreas de Matemática y CyT de primaria 1.º-3.º y la subárea Economía de Ciencias
    Sociales en las 11 secciones de secundaria.
  - **Ninguna área se quedó sin ningún bloque en ninguna sección**, que es la condición que
    este pendiente vigilaba. 1.º A de secundaria tiene su horario.
  - ⚠️ **DOS MEDICIONES FALSAS ANTES DE LLEGAR AL NÚMERO BUENO, las dos por el mismo
    descuido:** un `HAVING` sobre una subconsulta no agrupada dio **0**; y después un
    `INNER JOIN areas ON ca.area_id` volvió a dar **0**, porque **una carga puede colgar de
    una SUBÁREA y entonces `ca.area_id` es NULL**. Es la trampa que `CLAUDE.md` ya advierte
    para las competencias, y vale igual para las cargas: **el área se resuelve con
    `COALESCE(sa.area_id, ca.area_id)`**, nunca con un join directo.
- ~~**Solape de CLEMENTE ANGELES (DPCC, lunes)**~~ **RESUELTO EN PROD EL 29/07/2026**
  (corregido por el usuario desde la UI; confirmó que el horario quedó bien). Se deja el
  diagnóstico porque el patrón puede repetirse. El dato anterior tenía las secciones
  invertidas. Real:
  **5° B 14:40-16:10** (correcto) vs **1° C 15:45-17:20** (bloque nº111, 95 min, FUERA
  de la grilla) → se pisan **25 min**. Son **dos** solapes: el docente y también la
  **sección 1° C**, que a esa hora tiene Matemática con BUENO. **Horario correcto
  (usuario):** DPCC 1° C = lunes **16:35-17:20** + jueves 13:10-13:55; 5° B = lunes
  14:40-16:10. El jueves y 5° B ya están bien → **la corrección es UNA sesión**: mover
  el lunes de 1° C al bloque 16:35-17:20. Franja destino verificada libre para sección y
  docente. Al guardar, `horas_semanales` baja de 3 a **2**, igualando a las otras 10
  cargas de DPCC (90 min); eso es lo correcto, no una pérdida. Se hace **por la UI**
  (`/director/cargas` → editar la carga), no por SQL. Detalle completo en
  `docs/modulos/horarios.md`.
- **Orden de mérito: RECONSTRUCCIÓN DE B1 HECHA Y VERIFICADA EN PROD (29/07/2026).**
  El snapshot oficial de B1 se reconstruyó el 25/07 (Fase C, ver "Rediseño del orden de
  mérito" abajo) y el **check quedó cerrado el 29/07**: la firma en prod da
  **528 filas / puestos 1-72 / 11 grados / 23 secciones** y los **10 reincorporados**
  (8 `trasladado` + 2 `retirado`: matrículas 333, 308, **357**, **541**, 581, 191, 613,
  307, 646, 281) salen cada uno en su puesto de grado y de sección. Eran 10, no 9 —
  la 357 (HUAMAN VIENRICH) también es `retirado`.
  Los lectores del snapshot (`OrdenMeritoModel::rankingGradoDesdeSnapshot` y
  `rankingPorSeccionDesdeSnapshot`) unen `matriculas` solo para llegar a la persona:
  **no re-filtran por `tipo` ni por `estado`**, por eso los reincorporados se pintan.
  ⚠️ No correr `backfill_orden_merito.php` en prod (desde el 26/07 tiene guard
  propio, pero la advertencia sigue valiendo para versiones desplegadas antes).
- **Cierre de B2 — SECUENCIA CORRECTA (fijada el 27/07/2026).** Los dos prerrequisitos
  del cierre (F4) NO se comportan igual, así que el orden importa:
  **docentes terminan de calificar y bloquear → deploy del rediseño 2 → medir →
  resolver → cerrar.**
  - 🎉🎉 **B2 CERRADO Y PUBLICADO EN PRODUCCIÓN EL 10/08/2026, Y B3 ABIERTO. CICLO
    COMPLETO.** Confirmado por el usuario: los cuatro pasos salieron bien —cerrar B2,
    abrir B3, imprimir y validar el papel con el código nuevo ya desplegado (`992a350`),
    y publicar B2 por nivel—.
    - ⚠️ **EL CANDADO 046 SE ACTIVA AL PUBLICAR… PERO NO SIEMPRE EN EL ACTO** (matiz
      medido en el código el 10/08/2026 — **la afirmación anterior de esta línea, «ya está
      activo», era prematura**). `fuePublicado()` es
      `primera_publicacion_en IS NOT NULL OR publica_en <= NOW()`, y el sello
      `primera_publicacion_en` **solo se escribe cuando la publicación es INMEDIATA**
      (`publicar()` lo pone a NULL si `publica_en` es futuro, y el `COALESCE` del upsert
      nunca lo rellena después). **Una publicación PROGRAMADA a futuro deja el snapshot
      oficial todavía corregible** hasta que llegue su hora, y ahí el candado se activa
      **solo, sin que nadie pulse nada**.
      - 🔴 **MEDIDO EN PRODUCCIÓN EL 10/08/2026: LA PUBLICACIÓN DE B2 ESTÁ PROGRAMADA Y EL
        CANDADO 046 NO ESTÁ ACTIVO.** Las dos filas tienen `primera_publicacion_en` en
        **NULL**: nivel 1 (primaria) al **13/08 19:00** y nivel 2 (secundaria) al
        **14/08 19:00**, creadas a las 17:32. Idéntico al ensayo local (mismo segundo:
        copia fiel). **Las familias TODAVÍA NO VEN las boletas de B2** — las verán solas al
        llegar esas horas, sin que nadie pulse nada.
      - **El candado se cierra solo el 13/08 19:00** (hora de Lima), con la primera fila que
        vence. Hasta ese momento el snapshot oficial de B2 **aún se puede modificar**.
      - ✅ **SUPERADO POR EL RELOJ — VERIFICADO EL 17/08/2026: LAS DOS FECHAS YA VENCIERON Y
        EL CANDADO ESTÁ ACTIVO.** `primera_publicacion_en` sigue en **NULL** en las dos filas
        (no se rellena nunca en una publicación programada), pero `publica_en <= NOW()` basta:
        `fuePublicado(2)` es **`true`** desde el **13/08 19:00**. Las familias ven B2, el
        snapshot oficial de **520 filas** es inmutable y `orden_merito_rectificado` sigue en
        **0**. **Todo lo que dicen en futuro los dos puntos de arriba ya es pasado.**
      - ⚠️ **La vía NO es reabrir: es la RECTIFICACIÓN.** Con B3 ya abierto, `reabrir`
        aborta (la segunda puerta de un solo sentido). `RectificacionModel` sí opera sobre
        bimestres cerrados y regenera el ranking; como `fuePublicado(2)` es **`false`**,
        `registrarRanking` escribe el **OFICIAL**. Pasada esa hora, la misma acción irá a
        `orden_merito_rectificado` (visible solo en `/admin/control`).
      - **Que la publicación sea programada y escalonada es intencional** —primaria entrega
        un día antes que secundaria, y el modelo lo documenta—, pero tiene un efecto que no
        estaba escrito: **retrasa también el candado**, no solo la visibilidad.
    - 🔴 **QUEDA UNA VERIFICACIÓN SIN CAPTURAR, y ahora importa más que antes:** las cifras
      del snapshot de B2 **en producción** no se recogieron en la sesión. El espejo local
      predecía **524 filas / 11 grados / 23 secciones / puestos 1-72** y **0 bloqueos con
      `origen='cierre'`**. Conviene confirmarlo allí de una vez —es solo lectura— porque
      con el candado puesto una discrepancia ya **no se puede corregir en el oficial**.
      Vale la regla de trazabilidad de la 048: una operación solo se da por verificada en
      el entorno donde se capturó su salida.
    - ✅ **`limite_notas` de B3 YA ESTÁ FIJADO — verificado en PROD el 10/08/2026:
      `2026-10-16 04:00`**, con B3 `activo` del **10/08 al 09/10**. Estaba en NULL al
      abrirlo (con NULL `periodoEstaBloqueado` devuelve `false`: los docentes registran
      **sin** fecha límite) y se corrigió. El plazo vence **7 días después** del fin del
      bimestre, que es el margen para terminar de calificar.
      - ⚠️ **Las 04:00 son una hora mala mientras viva el bug de `NOW()`** (ver Pendientes
        de desarrollo): la UI de asistencia y conducta se apagará desde las **23:00 del
        15/10**, 5 horas antes que el guard real. No impide escribir por el resto de vías,
        pero contradice lo que la pantalla dice.
    - **Antes de esto hubo un cierre EN LOCAL** (mismo día): se cerró B2 en la copia y se
      dio por hecho en producción. Se detectó al preguntarlo explícitamente y se rehizo
      donde correspondía. Es exactamente la trampa de la 048 —la salida es idéntica en los
      dos entornos— y se resolvió resincronizando local desde prod y midiendo de nuevo.
  - 🎉 **B2 CERRADO EN LOCAL EL 10/08/2026 (ensayo) — el cierre salió limpio y sin
    incidencias.** El
    snapshot **OFICIAL** de B2 quedó en **524 filas / 11 grados / 23 secciones / puestos
    1-72**, exactamente lo que había predicho el simulacro, y `orden_merito_rectificado`
    sigue en **0**. B1 intacto en 528.
    - **Cero bloqueos forzados y cero cierres transversales creados:** el cierre no generó
      **ninguna incidencia**. Consecuencia medible y no supuesta: **el "hueco del guard de
      empates" no aplicó a este cierre**, porque el paso que amplía el universo fue un
      no-op y el conjunto validado por los guards es idéntico al congelado.
    - **Secuencia que se siguió:** vencer `limite_notas` (quedó en `2026-08-10 11:50`) →
      re-medir las 4 condiciones → verificar conducta y asistencia a mano (Fase 3.5, que
      el cierre NO valida) → cerrar. Antes se descartó la única duda abierta: los 61 pares
      bloqueados y vacíos son **autonomía legítima del docente**, porque B2 no es el
      periodo final (ver la regla nueva, arriba).
    - ⚠️ **B2 NO está publicado** (`periodos_publicacion` solo tiene las 2 filas de B1), así
      que sigue **reversible**: el candado 046 no se activa hasta publicar.
    - 🔴 **ABRIR B3 ES LA OTRA PUERTA DE UN SOLO SENTIDO, y no estaba documentada.** Solo
      existen tres transiciones de estado (`abrir`, `cerrar`, `reabrir`) y **ninguna vuelve
      a `pendiente`**; `reabrir` aborta si ya hay otro bimestre activo. Así que **en cuanto
      se abra B3 se pierde la posibilidad de reabrir B2**, y la única salida sería cerrar un
      B3 vacío, que forzaría bloqueos sobre todas las cargas del año y escribiría un
      snapshot espurio. **Validar el papel ANTES de abrir B3.** B3 arrancó el 03/08.
  - ✅ **RE-MEDICIÓN DEL 10/08/2026, ANTES DE CERRAR (copia local resincronizada con prod
    ese mismo día) — LAS CUATRO CONDICIONES EN VERDE.** Termómetro **0**
    (el periodo 2 no aparece en la consulta 1.1) · alerta de evaluación incompleta
    **0 estudiantes** (`ControlOperativoModel::alertasEvaluacionIncompleta(2)`) · empates
    **0 grados** (`OrdenMeritoModel::gradosConEmpatesPendientes(2)`) · conducta **23/23**
    y asistencia **23/23** vivas, sin dobles. `fuePublicado(2)` = **`false`** y
    `periodos_publicacion` sigue con solo las 2 filas de B1 → el cierre escribiría el
    **OFICIAL** y B2 es reversible hasta que se publique. Snapshot de B1 intacto
    (528 filas, puestos 1-72) y 0 rectificados. Marcadores de frescura de la copia:
    050 = **275** · 048 = **0** · 051 = **0**.
    - 🟢 **`limite_notas` de B2 ya NO está vencido: `2026-08-11 04:00`.** El plazo está
      **abierto**, así que la edición docente sigue viva y **las cuatro cifras son un piso
      móvil hasta esa hora**. Repetir la medición inmediatamente antes de pulsar Cerrar.
    - 🔄 **Hay calificación en curso:** B2 pasó de 28 270 a **28 282 calificaciones**
      (+12) y ganó **27 bloqueos** entre el 07 y el 10/08, el último movimiento el
      **10/08 a las 10:16**. Casi todo es de la matrícula **556** (Secundaria 4.º A: CyT,
      Matemática, Inglés y Comunicación), más 693 y 644 en Inglés de 5.º B. El patrón
      —notas nuevas sobre pares que ya estaban bloqueados, más bloqueos nuevos— encaja con
      **desbloquear → registrar → re-bloquear**, que es justo para lo que se amplió el
      plazo. Los bloqueos siguen **todos con `origen='docente'`** (0 forzados por cierre).
    - ⚠️ **556 es a la vez el peor caso de la prueba de impresión en papel** (Fase 5.5):
      acaba de recibir notas nuevas, así que su boleta **cambió después** de que se midiera
      su alto. Es la que hay que mirar primero al probar el A4.
  - ✅ **RE-MEDICIÓN COMPLETA DEL 07/08/2026 (local ya sincronizada con prod, con la 050
    incluida) — LAS CUATRO CONDICIONES DURAS EN VERDE. B2 SIGUE SIN CERRARSE.**

    | Condición del runbook | Valor | Cómo se midió |
    |---|---|---|
    | Termómetro de bloqueos B2 | **0** | la consulta 1.1 no devuelve fila del periodo 2 |
    | Alerta de evaluación incompleta B2 | **0 estudiantes** | `ControlOperativoModel::alertasEvaluacionIncompleta(2)` |
    | Empates pendientes B2 | **0 grados** | `OrdenMeritoModel::gradosConEmpatesPendientes(2)` |
    | Conducta / asistencia (Fase 3.5) | **23/23 y 23/23**, 0 dobles | las 3 consultas dan 0 filas |

    - **`fuePublicado(2)` = `false`** → `registrarRanking` escribirá **OFICIAL**, no
      rectificado: el candado 046 no muerde y **B2 es reversible hasta que se publique**.
      `periodos_publicacion` solo tiene las 2 filas de B1 (22/07).
    - **B1 conserva sus 12 alumnos con blancos** — la `050` **no los movió**, tal como
      predecía el análisis previo (`alertasEvaluacionIncompleta` filtra
      `cr.extraordinario = 0`). Verificado, no supuesto.
    - **La lista 1.1-bis (61 pares bloqueados y vacíos) quedó REVISADA y CERRADA**: ninguna
      ÁREA está del todo vacía y la única competencia con la firma de Ética —`Escribe
      diversos tipos de textos en inglés`, primaria— **la declaró NO EVALUADA la docente**
      (confirmado por el usuario). Detalle y discriminador en el runbook, Fase 1.1-bis.
    - **Falta solo lo humano:** revisar en papel (Fase 5.5) y pulsar Cerrar.
  - ✅ **MEDICIÓN DEL 04/08/2026 (BD local sincronizada con PROD ese día) — LAS DOS
    CONDICIONES DURAS EN VERDE:**

    | Condición | Valor | Antes |
    |---|---|---|
    | Termómetro de bloqueos | **B1 = 0 · B2 = 0** | B2 = 102 (28/07) |
    | Alerta de evaluación incompleta B2 | **0 alumnos / 0 blancos** | 19/19 (27/07) |
    | Empates pendientes B2 | **1 grado: Secundaria 4°** | sin medir |
    | Empates pendientes B1 | 0 | 0 |

    - B2 tiene **28 270 calificaciones y 1 283 bloqueos, TODOS con `origen='docente'`**
      (ninguno forzado por cierre) → los docentes cerraron su parte en la fecha.
    - La alerta se midió por **dos vías coincidentes**: el SQL de
      `database/verificaciones/alerta_evaluacion_incompleta.sql` y el método PHP real
      `ControlOperativoModel::alertasEvaluacionIncompleta(2)`.
    - El empate de **Secundaria 4°** se midió **con el código de `dev`** (rediseño 2)
      contra datos de prod → **es lo que se verá después del deploy**, no un piso móvil.
      Se resuelve en la Fase 3 del runbook, DESPUÉS de mergear.
    - ⚠️ **Con el termómetro en 0, el hueco del guard de empates no muerde** (ver abajo):
      el universo validado y el congelado coinciden.
    - **El empate de Secundaria 4° lo resolvió el usuario el 04/08 a las 10:36** →
      B2 queda con **0 empates reales**. Al resolverlo afloró el bug de la card
      (ver el punto siguiente).
  - 🐞 **CARD DE EMPATES DE `/admin/control` — FANTASMAS IRRESOLUBLES (CORREGIDO el
    04/08/2026).** La card mostraba empates que ya estaban resueltos —o que nunca
    existieron— y no se limpiaba nunca.
    - **Causa:** el Centro de Control tenía su **propia copia de la cascada**
      (`ControlOperativoModel::detectarGruposIrreducibles`, nacida el 08/06 a las 08:36)
      que se quedó congelada en la tupla de 3 conteos (`num_c|num_b|num_ad`) y nunca
      incorporó los criterios de regularidad alta `num_alto`/`num_16`, que el motor
      real ganó **ese mismo día a las 12:59** (`d41c548`). Dos meses divergiendo.
    - **Por qué era irresoluble:** la pantalla donde se resuelve
      (`/director/orden-merito/{periodo}/desempate/{grado}`) usa el motor REAL, que no
      considera empatados a esos alumnos → nunca los ofrecía. Caso medido: Secundaria 4°
      B2, grupo {464, 652} — misma tupla de 3, pero `num_16` = 7 vs 2, así que el motor
      real los ordena solo (puestos 30 y 31).
    - **Alcance medido antes del arreglo:** B1 mostraba **7 grados** "pendientes" con
      **0 reales** (sus 14 desempates estaban resueltos desde junio); B2 mostraba 6 con
      0 reales.
    - **NO era una regresión del lote:** el método era byte-idéntico al de `origin/main`
      → el bug estaba **vivo en producción desde el 08/06/2026**. Tampoco afectaba a
      ningún dato: el guard del cierre, el snapshot y la boleta siempre usaron el motor
      real. Era un problema de confianza en la UI.
    - **Arreglo (opción A):** se borró la copia; `empatesPendientes` ahora **delega** en
      `OrdenMeritoModel::gradosConEmpatesPendientesDetalle`, punto único nuevo que el
      guard del cierre también consume (vía el wrapper de strings, cuyo contrato NO
      cambió). Se eliminó de paso la dependencia huérfana `DesempateMeritoModel` del
      Centro de Control. Sin migración; sin cambios de SASS.
    - **Verificación:** `database/verificaciones/verif_empates_card_control.php`
      (transacción + ROLLBACK). Retira temporalmente las resoluciones para tener
      empates de verdad y comprueba que card y motor real coinciden en grados **y**
      conteos: B1 → 3 grados / 4 grupos, B2 → 4 grados / 6 grupos; con las
      resoluciones puestas, ambos 0. Rollback verificado (14 y 7 resoluciones,
      42 filas de detalle).
  - 📋 **RUNBOOK EJECUTABLE: `docs/runbooks/cierre-de-bimestre.md`** (29/07/2026).
    Fases 0-6 con checklists, las consultas de prod ya probadas (termómetro, desglose
    por docente, verificación post-cierre), criterios de aborto y prohibiciones. Escrito
    para B2 y reutilizable en B3/B4 cambiando `@periodo`. **El día del cierre, seguir
    ese documento** en vez de reconstruir la secuencia de memoria.
  - **FECHA DURA: el cierre de notas de los docentes es el 31/07/2026** (dato del
    usuario, 28/07). **Decisión del 28/07: NO medir todavía** la alerta de evaluación
    incompleta ni perseguir docentes — se les deja terminar. Medir **después del
    31/07**, cuando las cifras dejen de ser un piso móvil. La herramienta está lista y
    no requiere edición (`alerta_evaluacion_incompleta.sql` ya trae `@periodo := 2`).
  - La **alerta de evaluación incompleta es estable**: su cálculo no mira
    `bloqueos_competencia` (depende de criterios con nota, cargas activas, omisiones y
    exoneraciones). Se puede medir HOY contra prod y el trabajo de resolverla —registrar
    la nota o la omisión desde el módulo del docente— vale igual antes o después del
    deploy. Herramienta: `database/verificaciones/alerta_evaluacion_incompleta.sql`
    (phpMyAdmin, solo lectura; el Centro de control ya la muestra pero está en `dev`).
  - Los **empates NO son estables**: P2 del rediseño 2 reduce el universo del cálculo en
    vivo a competencias BLOQUEADAS, así que cambian con el deploy; y una resolución se
    ancla al conjunto EXACTO de matrículas (`grupo_clave`), de modo que si el grupo
    cambia deja de cubrirlo y el empate reaparece. **Resolver empates va DESPUÉS del
    deploy y con todo bloqueado** (al cerrar, el propio cierre fuerza los bloqueos, así
    que el universo converge). Se consultan en `/director/orden-merito/{periodo}`, que
    ya lista los bimestres `activo` y está en prod desde el 17/06.
  - **Al 27/07 no hay ninguna decisión de desempate tomada para B2** (confirmado por el
    usuario): el bimestre no se ha cerrado, así que no hay trabajo que rehacer.
  - **OJO — LOCAL NO SIRVE para dimensionar esto:** B2 en local tiene **77
    calificaciones y 7 criterios** (B1 tiene 12 049 y 2 398). Los "19 alumnos de 4° A" y
    el "empate de Secundaria 1°" que se miden en local son artefactos del dataset de
    pruebas — el empate son 22 alumnos con `N=1`, una sola competencia calificada. Toda
    cifra de bloqueadores de B2 debe salir de PRODUCCIÓN.
  - La alerta **solo aflora un criterio cuando algún compañero de la sección ya tiene
    nota en él**: lo que se mida a mitad de bimestre es un PISO, no un total.
  - **TERMÓMETRO DE BLOQUEOS — medido en PROD el 28/07/2026: B1 = 0, B2 = 102.**
    *(SUPERADO: el 04/08 da B2 = 0 — ver la medición de arriba. Se conserva la
    definición porque es la consulta que hay que repetir en cada cierre.)*
    Cuenta pares carga+competencia **con notas y sin fila en `bloqueos_competencia`**
    (`LEFT JOIN … WHERE bc.id IS NULL`, agrupado por `periodo_id`). Es el indicador de
    "listos para cerrar": **cuando dé 0, los docentes terminaron.** También es un piso
    (no ve lo aún no calificado), y tiene variante que desglosa por docente/sección
    (join a `cargas_academicas` + `usuarios`/`personas`) para saber a quién apurar.
    El **B1 = 0 confirma de forma independiente** que el snapshot oficial de 528 no
    arrastra notas sin bloqueo → P2 del rediseño 2 no le mueve un puesto.
  - ⚠️ **HUECO DEL GUARD DE EMPATES (hallazgo del 28/07/2026 — NO corregido, por
    decisión).** En `Director/PeriodoController::cerrar` el guard de empates corre en
    `:124`, pero `bloquearCompetenciasPendientes` está en `:155` y `registrarRanking`
    en `:173`. Como `gradosConEmpatesPendientes` (`OrdenMeritoModel:666`) y
    `calcularFilasRanking` (`:417`) hacen `INNER JOIN bloqueos_competencia`, **el guard
    valida un universo más chico que el que se congela**: cerrar con pares sin bloquear
    puede PETRIFICAR empates que nadie vio. Lo que `orden-merito-rediseno.md` llama
    "diferencia consciente" (el cierre no valida P3 porque él mismo fuerza los bloqueos)
    es justamente el origen del hueco: forzarlos DESPUÉS de validar hace que el conjunto
    validado no sea el congelado.
    - **Gravedad baja y REVERSIBLE mientras B2 no se publique:** sin publicación el
      candado 046 no se activa, `registrarRanking` sigue escribiendo el OFICIAL y basta
      reabrir → resolver → re-cerrar (costo: las boletas vuelven a BORRADOR). La ventana
      irreversible se abre al **publicar**.
    - **Decisión (28/07): opción C — no se toca el código.** Regla operativa:
      **exigir que el termómetro dé 0 ANTES de pulsar Cerrar**; con 0 el hueco no
      existe. Se descartó A (guard previo "0 sin bloquear"): mataría la válvula de
      escape del bloqueo forzado, útil si un docente de licencia nunca bloquea.
    - **Pendiente para DESPUÉS del cierre de B2 — opción B:** mover el guard de empates
      a después del bloqueo forzado, dentro de la transacción y con rollback. Es la
      corrección estructural correcta; no se estrena bajo presión en el cierre.
      - ✅ **DESBLOQUEADA (17/08/2026): B2 se cerró el 10/08 y se publicó el 13-14/08**, así
        que la condición que la difería ya se cumplió. **Sigue sin implementar.** El cierre
        de B2 no la necesitó —0 bloqueos forzados, capturado en prod—, pero eso fue el
        dataset, no el diseño: lo que hoy suple al guard es una **regla operativa humana**
        («termómetro en 0 antes de pulsar Cerrar»), no código. Hacerla **antes de cerrar B3**.
- ~~**Retorno de grado de BALTAZAR SHALOM CRISTEL — BLOQUEARÁ EL CIERRE DE B2.**~~
  **RESUELTO PARA B2 (verificado el 04/08/2026):** la alerta de evaluación incompleta
  de B2 da **0** y la matrícula **692 ya no aparece** en el detalle por alumno. El
  riesgo que se anticipó abajo no llegó a materializarse como bloqueo del cierre.
  - ⚠️ **En B1 SIGUE ABIERTO y ahora tiene consecuencia:** B1 arroja **12 alumnos**
    con blancos sin motivo (692 entre ellos, con 69 blancos). Mientras B1 esté
    **cerrado** la alerta ahí es solo informativa (fix `af72ac7`), pero el guard P4
    ya está en producción (04/08) → **si alguna vez se REABRE B1, no se podrá volver a cerrar**
    hasta resolver esos 12. Tenerlo presente antes de reabrir B1 para una
    rectificación. Ver "Efecto colateral del guard P4" en Pendientes de desarrollo.
    - ✅ **MEDIDOS UNO A UNO EL 17/08/2026 con el motor real
      (`alertasEvaluacionIncompleta(1)`) — y salen MUCHO más baratos de lo que decía esta
      línea: son 12 alumnos con UN blanco cada uno, 12 en total.** El «692 con 69 blancos»
      quedó obsoleto (era la medición del 27/07, previa al filtro `ca.estado='activa'` del
      fix `af72ac7`). **B2 y B3 dan 0.**

      | Matrícula | Alumno | Ubicación |
      |---|---|---|
      | 692 | BALTAZAR PINTO, Shalom Cristel | Primaria 1.º B |
      | 548 | PANTOJA LAZARO, Jaziel Joaquin | Primaria 2.º B |
      | 424 | MAGUIÑA SALAZAR, Joseft Paulo | Primaria 3.º A |
      | 504 | PEÑA PILLACA, Vannya Maurina | Primaria 3.º A |
      | 259 | DIEGO LOPEZ, Adrian Abraham | Primaria 4.º A |
      | 690 | ÑIQUEN PAJUELO, Xoana Antonella | Primaria 4.º A |
      | 339 | GALICIA MENDOZA, Nayara Aeysha | Primaria 4.º B |
      | 691 | RAMIREZ HUAMAN, Itzel Samantha | Primaria 5.º B |
      | 696 | MORALES YANAC, Yeremi Miguel | Secundaria 3.º A |
      | 694 | SANTAMARIA RODRIGUEZ, Jakeline | Secundaria 3.º A |
      | 695 | GONZALEZ RIBERA, Jeanfranco Nuriel | Secundaria 5.º B |
      | 693 | RIMAC CIRIACO, Azahí Fernanda | Secundaria 5.º B |

    - 🔴 **NO SE PUEDEN PRE-RESOLVER, y conviene saberlo antes de intentarlo:** B1 está
      cerrado, así que `periodoEstaBloqueado` rechaza registrar la nota o la omisión; y la
      **calificación extraordinaria tampoco sirve**, porque `alertasEvaluacionIncompleta`
      filtra `cr.extraordinario = 0` y no vería el criterio (es lo mismo que se verificó
      con la 050: sus 275 notas no movieron esta alerta). **La única secuencia posible es
      reabrir → resolver los 12 → re-cerrar**, y reabrir es la puerta de un solo sentido.
    - **7 de los 12 son los que llegaron con el año empezado** (690, 691, 692, 693, 694,
      695, 696): los mismos del plan de registro retroactivo. Resolver aquel plan
      resolvería más de la mitad de esta lista.
  - Se conserva el diagnóstico completo porque el patrón (evaluación registrada en las
    cargas del grado oficial en vez de las del operativo) puede repetirse en B3/B4.

  **Diagnóstico original (26-27/07/2026):**
  Matrícula oficial 190 (Primaria 2° B, la de SIAGIE) + operativa 692 (1° B, donde
  CURSA); retorno activo desde el 21/06/2026. La evaluó la docente de 1° B, pero **esa
  evaluación no existe en las cargas de 1° B**: los promedios se registraron en las
  cargas de 2° B repitiendo la misma nota en cada criterio para no alterar el promedio
  (la 190 tiene 122 criterios así; la 692 tiene los 22 promedios y CERO criterios).
  Consecuencia: la alerta de evaluación incompleta le marca los criterios de 1° B en
  blanco y con la F4 eso **aborta el cierre** mientras el bimestre esté abierto. Hay que
  registrarle la nota o la omisión en las cargas de 1° B antes de cerrar B2, o repetir el
  mismo procedimiento a conciencia. **NO es un duplicado de matrícula: no borrar la 692.**
  Decisión del usuario (26/07): la alerta se deja como está (solo informa) y se resuelve
  operativamente.
  - **Cifras reales medidas el 27/07/2026** (las de "80 blancos" que decía antes esta
    entrada quedaron obsoletas al filtrar `ca.estado = 'activa'` en el fix `af72ac7`):
    en **B1** son **69 blancos** (matrícula 692), y como B1 está cerrado la alerta ahí es
    **informativa**, no bloqueante. En **B2** la alerta **todavía NO lo marca**: solo
    aflora un criterio cuando algún compañero de su sección ya tiene nota en él, y 1° B
    apenas ha calificado el II Bim. **El riesgo sigue en pie** — irá apareciendo conforme
    la docente de 1° B avance, y para cuando toque cerrar B2 estará completo. Volver a
    medir con `ControlOperativoModel::alertasEvaluacionIncompleta(2)` antes de cerrar.
- **Re-subir firma/sello del Director EBR** solo si se recrea el entorno
  (se pierden únicamente si se borra el directorio externo `~/siga_uploads/`).
  - ℹ️ **No es un pendiente: es una nota condicional** (reclasificada el 17/08/2026). No hay
    nada que hacer mientras `~/siga_uploads/` exista; el auto-deploy no lo toca.
- ~~**Decisión del colegio pendiente:** regenerar (o no) el ranking B1~~ ✅ **DECIDIDO EL
  17/08/2026: NO SE REGENERA. Pendiente CERRADO.** Estaba abierta desde el **10/06**, cuando
  los umbrales literales cambiaron (AD pasó de 17 a 18) y se dejaron sin tocar los
  desempates `num_alto IN (15,16)` y `num_16`.
  - **Decisión del usuario:** B1 ya se entregó a las familias con esos puestos; el snapshot
    oficial de **528 filas es definitivo**.
  - **La decisión llega cuando ya casi no había alternativa:** B1 está publicado desde el
    22/07, así que el candado 046 hace **inmutable** su snapshot oficial. Regenerar ya no
    podía tocar el documento entregado — habría escrito una versión no oficial en
    `orden_merito_rectificado`, visible solo en `/admin/control`.
  - **Los desempates `num_alto`/`num_16` se quedan como están.** Si algún día se tocan, no
    afectan a B1 retroactivamente: sus lectores usan el snapshot, no el cálculo en vivo.

## Eventos con fecha
- ✅ **11/08/2026 — EN PRODUCCIÓN: la guarda de roster ya no bloquea la versión
  RECTIFICADA de un bimestre publicado.** Salió de probar en PROD el deploy del mismo día.
  **Sin migración.**
  - **Síntoma:** se rectificaron 3 notas de B2 (4.º primaria B, 15:10–15:13) y el orden de
    mérito siguió mostrando el anterior. Las notas **sí** se guardaron; lo que no corrió fue
    el ranking — el snapshot seguía con **524 filas y `generado_en` 10/08 17:28** (la hora
    del cierre) y `orden_merito_rectificado` vacío.
  - **Causa:** la divergencia de roster ANTERIOR al código (ESCUDERO TORRES #456, trasladada
    el 10/08 18:06, 38 min después del cierre) → `registrarRanking` devolvió
    `'roster_cambiado'`. Es la guarda funcionando, no un fallo.
  - ✅ **Reparación de datos: basta `sincronizar_roster_snapshot.php --confirmar`.**
    **NO hay que rehacer las rectificaciones** —el orden documentado antes lo pedía porque
    se escribió suponiendo que aún no se habían hecho—: como las notas ya están corregidas
    en `calificaciones`, el script las recoge al regenerar. Ensayado sobre la copia de prod:
    524 → 523 filas, y 4.º primaria pasó de tres empatados en 17.08 a **17.08 / 17.04 /
    17.00** en los puestos 1-3 (mismos puestos), con **0 empates pendientes** en B2.
  - 🐛 **Bug encontrado de paso y corregido:** `registrarRanking` evaluaba la guarda de
    roster **antes** de la rama del candado 046, así que en un periodo publicado con roster
    divergente no escribía nada. **B1 diverge por diseño** (528 filas contra 517 del motor),
    de modo que desde el deploy toda rectificación suya devolvía `'roster_cambiado'` y pedía
    «regularizar la matrícula» — lo contrario de lo que hay que hacer en B1. Arreglado
    invirtiendo el orden de las dos ramas. **`PeriodoController::cerrar` no cambia** (no pasa
    el flag, así que nunca pasó por la guarda).
  - ⚠️ **No era solo B1:** el mismo bug habría alcanzado a **B2 después del 13/08 19:00**, en
    cuanto se registrara un traslado posterior a la publicación.
  - **Verificación:** paso 6 nuevo en `verif_roster_snapshot_traslado.php` (**20 en verde**);
    los 7 verificadores del módulo siguen pasando.
- ✅ **11/08/2026 — EN PRODUCCIÓN, código y datos: el roster del snapshot deja de moverse
  por accidente.** Decisión del usuario: **B1 se queda como está (528 filas, 11
  trasladados/retirados dentro) y la regla nueva —excluir a quien se traslade o retire—
  rige DESDE B2.** El porqué: la publicación siempre cae después de activar el bimestre
  siguiente, así que el documento llega a las familias cuando el alumno ya se fue.
  **Sin migración.**
  - **B1 no necesitó nada:** `fuePublicado(1)=true` → el candado 046 lo protege solo.
    Verificado. Solo lo rompería invocar `generarSnapshot`/`backfill` a mano.
  - **Lo arreglado:** `generarSnapshot` recalculaba el ROSTER, no solo las notas, borrando
    y reinsertando el periodo ENTERO. Ahora: (1) `registrarRanking(..., $exigirMismoRoster)`
    ABORTA la reescritura si el roster cambió y avisa —lo usa la rectificación—; (2)
    `sincronizarRosterPorMatricula()` es el **punto único** que sí lo mueve, llamado desde
    los **4 sitios** que tocan `matriculas.tipo` (traslado, retiro, activación, reversión).
    Detalle y decisiones en `docs/modulos/orden-merito.md`.
  - **Caso testigo medido:** ESCUDERO TORRES (1.º sec C) cursó B2 completo y se trasladó
    38 min después del cierre. Salió del oficial de B2 al rectificarse 3 notas de Educación
    Física de **4.º de primaria**, arrastrando a 42 compañeros de puesto. B2 pasó de 524 a
    523 filas. Sigue en el B1 publicado, puesto 39.
  - **Verificación:** `verif_roster_snapshot_traslado.php` (transacción + ROLLBACK, guard de
    prod), **20 comprobaciones en verde** (16 al nacer + las 4 del paso 6, añadidas ese mismo
    día con el arreglo del candado; ver el evento siguiente). Los 8 verificadores del módulo
    que ya existían siguen pasando.
  - ⏰ **VENTANA:** `fuePublicado(2)` pasa a `true` **solo por el paso del tiempo** el
    **13/08 19:00** (`publica_en` de primaria). El candado es por PERIODO, no por nivel: esa
    hora bloquea también el oficial de secundaria. Después, toda corrección de B2 va a
    `orden_merito_rectificado`.
    - ✅ **VENTANA CERRADA — verificado el 17/08/2026.** Ocurrió tal cual y sin intervención:
      `fuePublicado(2)` es `true` desde esa hora. Toda corrección de B2 va ya a
      `orden_merito_rectificado` (aún en 0 filas).
  - 🔧 **`database/sincronizar_roster_snapshot.php`** — recoge las divergencias ANTERIORES
    al código, que ningún acto futuro tocaría. **Producción está justo en ese estado**
    (B2 con 524 filas incluyendo a una alumna ya `trasladado`), así que **sin este script el
    deploy dejaba B2 sin poder rectificarse**. Corre en prod, simula por defecto,
    `--confirmar` para aplicar.
  - ~~**ORDEN OBLIGATORIO EN PRODUCCIÓN:** desplegar → script → rehacer las 3
    rectificaciones.~~ **Superado el 11/08 por los hechos: ver el evento siguiente.**
- ✅ **11/08/2026 — EN PRODUCCIÓN: el reporte imprimible del orden de mérito
  pasó a UNA HOJA FIRMABLE POR SECCIÓN. El usuario lo aprobó como MODELO OFICIAL del
  documento** (decisión cerrada; ver `docs/modulos/orden-merito.md` §"El reporte
  imprimible"). Solo presentación —vista + SASS + el
  `bodyClass` del controlador—: **no se tocó ninguna consulta, ni el modelo, ni qué se
  muestra**. Sin migración.
  - **A4 apaisado → A4 VERTICAL.** Cada hoja es un documento autónomo: 1 hoja por grado
    (firman Director EBR + todos sus tutores) + **1 hoja por SECCIÓN** (Director EBR + el
    tutor de esa sección), agrupadas por grado. Antes, las secciones de un grado iban
    apiladas en una sola hoja con una línea de firma suelta cada una.
  - **Medido en Chrome sobre el documento real de B1 y B2 (no estimado):** 34 hojas
    (11 grados + 23 secciones), 80 bloques de firma, **0 hojas en blanco** al final.
    Caben **55 alumnos por hoja**; 10 de los 11 grados entran enteros y solo **1.º de
    secundaria (72)** usa dos hojas. ⚠️ 4.º de secundaria (55 en B1) mide **278.1mm de
    los 281mm útiles**: el tope está al límite, y por encima la tabla continúa en una
    segunda hoja (degradación limpia).
  - La columna «Distinción» (44mm, vacía en el 95 % de las filas) es ahora un distintivo
    junto al nombre; no se perdió información.
  - **Efecto lateral evitado a propósito:** `.reporte-footer` lo comparten asistencia,
    conducta y el acta de desempates; el recorte del espacio de firma (18→15mm) va bajo
    el scope nuevo **`.merito-doc`**, así que esos tres documentos quedan intactos.
  - Detalle y trampas en `docs/modulos/orden-merito.md` §"El reporte imprimible".
  - **Pendiente:** decidir el merge a `main`.
- ✅ **11/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `9d3207d` a `6b48964`**
  (merge `--no-ff`, autorizado por el usuario). **RANKING POR SECCIÓN PARA STAFF + DOS
  FUGAS DE LA COMPUERTA CERRADAS.** Lote construido en la sesión del 10-11/08.
  **6 commits, 16 archivos, SIN MIGRACIÓN.**
  - **Verificado antes de mergear:** `php -l` de **todos** los PHP del lote y las **5
    verificaciones en verde** (`verif_nomina_docente_render`, `verif_ranking_seccion_staff`,
    `verif_merito_nomina_compuerta`, `verif_roster_asistencia`,
    `verif_flag_editable_timezone`), más la sonda de render **repetida sobre el árbol ya
    mergeado**.
  - **`/director/ranking-seccion[/{periodo}]`** para admin, RA y los dos directores.
    Detalle y decisiones en `docs/modulos/orden-merito.md` §Visibilidad. Reutiliza
    `rankingPorSeccion()` (snapshot-aware) y la **vista del docente parametrizada**, en vez
    de copiarla. Verificación: `verif_ranking_seccion_staff.php` (**B1 528=528 · B2
    524=524**).
  - 🔴 **FUGA 1 — la nómina del docente enseñaba el mérito NO PUBLICADO.** Resolvía el
    puesto con "último bimestre **cerrado**". Punto único nuevo
    `PublicacionBoletaModel::ultimoPeriodoPublicadoPorNivel()`, **por NIVEL** (la compuerta
    lo es). Verificación: `verif_merito_nomina_compuerta.php`.
  - **El buscador de la nómina lista solo `aprobada`** (decisión del usuario): 525 → 521.
    **Los ROSTERS no se tocan** — notas, asistencia y conducta siguen incluyendo
    `pendiente` y `desactivado`, que es el invariante y lo que arregló el fix del 04/08.
    Antirregresión: `verif_roster_asistencia.php` **OK** tras el cambio.
  - 🔴 **REGRESIÓN PROPIA, DETECTADA POR EL USUARIO Y CORREGIDA ANTES DEL DEPLOY: el panel
    de BOLETA desapareció de todas las cards de la nómina.** Al eliminar la variable
    `$bimestre` del controlador, el array de la vista seguía leyéndola en
    `'bimestreCerrado' => $bimestre[...] ?? null`; **el `??` suprime el aviso de variable
    indefinida**, la clave quedó en `null` y `$hayBoletaVisible` pasó a `false`. Nunca
    llegó a producción.
    - **Causa de fondo:** una sola variable servía a **dos reglas distintas** —el mérito
      (bajo la compuerta 044) y la boleta del docente (que NO pasa por ella: su regla es
      `boleta_estado_bimestre`)—. Ahora son `$publicados` y `$ultimoCerrado`, con un
      comentario que prohíbe volver a fusionarlas.
    - **Nace `verif_nomina_docente_render.php`, la primera verificación que RENDERIZA una
      vista real** (simula la sesión del docente, ejecuta el controlador, examina el HTML).
      **Control ejecutado**: con el fallo reintroducido cae de **2080 paneles a 0** y el
      HTML de **1 413 206 a 650 006 bytes**.
    - ⚠️ **REGLA NUEVA, aplicable a todo el repo:** `?? null` sobre una **variable** (no
      sobre un índice de un array que ya existe) convierte un error en un **silencio**. Al
      tocar un controlador, revisar **todas** las claves que entrega a su vista, no solo
      las editadas. `php -l` y las verificaciones de MODELO no ven esto.
- ✅ **10/08/2026 (2.º deploy del día) — `origin/main` pasó de `992a350` a `9d3207d`**
  (commit de merge `--no-ff`, autorizado por el usuario). **3 commits de contenido, SIN
  MIGRACIÓN.**
  - **Único código de runtime que entra:** el fix del flag `editable` en
    `AsistenciaModel` y `ConductaModel` (dos consultas dejan de usar `NOW()`). El resto
    son dos verificaciones nuevas —que no corren en runtime— y documentación.
  - **Qué se arregla en prod:** con el motor en UTC la UI de asistencia y conducta
    apagaba la edición **5 horas antes** que el guard real. Importa ahora porque **B3 está
    abierto** y su `limite_notas` es `2026-10-16 04:00`: con el bug, la pantalla se habría
    apagado la noche del **15/10**.
  - **Verificado antes de mergear:** `php -l` de los dos modelos sobre el árbol ya
    mergeado, `verif_flag_editable_timezone.php` en verde (incluido su paso de control con
    la sesión en UTC) y el diff contra `origin/main` sin nada en `database/migrations/`.
  - **Riesgo bajo, y la razón es medible:** el cambio solo puede alterar el flag en la
    franja de las 5 horas previas al `limite_notas`. Fuera de esa ventana, el resultado es
    idéntico al anterior.
- ✅ **10/08/2026 — CIERRE, ENTREGA Y PUBLICACIÓN DEL II BIMESTRE. Ciclo completo.**
  B2 cerrado y publicado en producción, B3 abierto y activo para los docentes, y la boleta
  corregida para caber en una hoja A4 (deploy `992a350`). Es el primer bimestre que recorre
  entero el flujo nuevo: compuerta de publicación, snapshot oficial inmutable y las dos
  puertas de un solo sentido documentadas en el runbook.
  - ✅ **SNAPSHOT DE B2 CAPTURADO EN PRODUCCIÓN EL 10/08/2026 — COINCIDE CON LO PREDICHO:
    524 filas / puestos 1-72 / 11 grados / 23 secciones**, `generado_en`
    **2026-08-10 17:28:00**. Huella del servidor capturada en la misma corrida
    (`u761410128_siga_cociap` · `u761410128_ktcdev@127.0.0.1` ·
    `br-asc-web1308.main-hosting.eu` · **MariaDB 11.8.8-log** · Linux · `/var/lib/mysql/`),
    que es lo que la convierte en una verificación de PROD y no de una copia.
    - ⚠️ **`generado_en` es el MISMO SEGUNDO en local y en prod**, así que esa cifra
      **por sí sola no distingue los entornos** — es otra vez la trampa de la 048, y sin
      el bloque 0 la captura no habría probado nada. La copia local es fiel porque se
      resincronizó desde prod después del cierre.
    - ✅ **CERO BLOQUEOS FORZADOS, capturado en PROD el 10/08:** los bloqueos de B2 son
      **593 académicos + 690 transversales, TODOS con `origen='docente'`** (1283 en total,
      que cuadra con los 1283 medidos allí el 04/08). Ninguno con `origen='cierre'`.
      - **Consecuencia confirmada en producción, no solo predicha en local:** el **hueco
        del guard de empates NO aplicó a este cierre**. El paso que amplía el universo
        (`bloquearCompetenciasPendientes`) fue un **no-op**, así que el conjunto que
        validaron los guards es exactamente el que se congeló en el snapshot.
      - Los 690 transversales confirman además que la **051 sigue haciendo su trabajo** en
        prod: 0 fantasmas recreados por este cierre (era el riesgo si F1 no hubiera estado
        arriba antes).
    - ✅ **LOS DOS PENDIENTES INMEDIATOS DEL CIERRE QUEDAN CERRADOS (10/08/2026).** Los
      cuatro bloques se capturaron **en producción**, con huella, y los cuatro salieron
      como se predijo: snapshot 524/1-72/11/23 · 0 bloqueos forzados · publicación
      programada (candado aún abierto) · `limite_notas` de B3 en `2026-10-16 04:00`.
      ✅ **Y el BLOQUE 5 de la checklist de navegador (ZIP de borradores) se probó ese
      mismo día con éxito**, así que del cierre de B2 no queda ningún pendiente abierto.
    - 🛠 **Herramienta lista (10/08/2026): `database/verificaciones/verif_post_cierre_bimestre.sql`**
      — solo lectura, 8 bloques autocontenidos para phpMyAdmin, periodo anclado por
      **número + año activo**. Cubre las dos cosas de una pasada, más el estado real del
      candado 046. **Su bloque 0 es la huella del servidor**: sin él la captura no prueba
      en qué entorno se tomó (lección de la 048). Validada en local, donde da
      **524 / 1-72 / 11 / 23**, 0 empates petrificados, 0 sin puesto de sección, **0
      bloqueos `origen='cierre'`** (593 académicos + 690 transversales, todos `docente`)
      y 0 rectificados. Reutilizable en B3/B4 cambiando `@num` y los `@esp_*`.
  - **Siguiente hito del calendario:** **05/10/2026**, inicio del IV Bimestre — fecha tope
    de la regla del periodo final (ver Pendientes de desarrollo).
- ~~**31/07/2026 — CIERRE DE NOTAS DE LOS DOCENTES (II Bimestre).**~~ **CUMPLIDA.**
  Era la fecha límite para que terminaran de calificar y **bloquear**. Medido el
  **04/08/2026**: termómetro de bloqueos **B2 = 0** (1 283 bloqueos, todos con
  `origen='docente'`) y alerta de evaluación incompleta **B2 = 0**. Los docentes
  cumplieron. Siguiente paso de la secuencia: **deploy** → resolver el empate de
  Secundaria 4° → cerrar. Detalle en "Cierre de B2 — SECUENCIA CORRECTA", en
  Pendientes operativos, y el procedimiento en
  `docs/runbooks/cierre-de-bimestre.md`.
- **08/07/2026 — Capacitación docente (PLAN CERRADO):** demos proyectadas desde
  el entorno de desarrollo; práctica de docentes en producción = trabajo REAL del
  II Bim; sin backup/restore. Dos turnos: primaria 12:30pm-2:00pm, secundaria
  7:30pm-9:00pm. Detalle en `docs/decisiones-diferidas.md`.

## Git

- 🟡 **04/09/2026 — PUSH A `dev`, NO ES UN DEPLOY. `origin/dev` pasó de `6e54c9b` a
  `ce2502c`.** Un solo commit (19 archivos, +1670/−165) porque los dos bloques de
  CUADROS tocan los mismos archivos y separarlos exigía partir hunks a mano.
  **`main` NO se movió: sigue sin desplegarse.** Sin migraciones.
  - **Qué entra:** el tablero de Dirección pasa al motor oficial del mérito
    (`OrdenMeritoModel::statsPorGrado`), la sección «Estudiantes en riesgo», y las
    tablas de valores que quitan al A4 su dependencia del tooltip.
  - **Verificado antes del push:** `verif_cuadros_merito_motor.php` (nuevo) en
    `TODO OK` y `verif_direccion_superficies.php` en verde, **repetidos después** de
    revertir el `$nivelId` de abajo.
  - ✅ **Cierra un pendiente del deploy del 02/09**, que decía «tampoco se ha visto
    `/admin/cuadros`»: el 04/09 se recorrió en navegador, pantalla y A4. ⚠️ Pero
    **con sesión de ADMINISTRADOR**, así que lo que sigue sin probarse es el acceso
    de los tres roles directivos, no esta pantalla.
  - 🔴 **Se dejó fuera del commit un `?int $nivelId = null` que se había añadido a dos
    métodos de `ConductaModel`**: su docblock decía «filtro de `/admin/cuadros`», pero
    **nadie lo llamaba** —la única aparición en todo el repo era la declaración— y
    ningún verificador ejercitaba la rama filtrada. Se revirtió el archivo a `HEAD`.
    Los otros `nivelId` del archivo (`getCriterios`, `totalCriterios`) son
    preexistentes y sí se usan. Si algún día hace falta ese filtro, entra junto con
    quien lo llame y su aserto.

- ✅ **02/09/2026 (2.º del día) — DEPLOY. `origin/main` pasó de `c138851` a `11c5b79`**
  (merge `--no-ff`, pedido por el usuario). **4 commits, SIN MIGRACIONES.**
  - **Qué entra:** las 9 fugas de UX de dirección (botones que devolvían 403), el
    bloque 6 nuevo del verificador, y los KPIs de `/matriculas/resumen` contando
    estudiantes en vez de matrículas aprobadas —con el doble conteo por retorno de
    grado corregido—.
  - **Verificado antes del merge:** 9 verificadores en verde sobre `main` ya
    mergeado; el bloque 6 probado en SUS DOS RAMAS; los KPIs contra datos reales
    (523 estudiantes, 1 retorno de grado que ya no se duplica) y en navegador a
    320/640/1120 px.
  - 🔴 **NADA se abrió con SESIÓN DE DIRECTOR, que es justo el rol del que trata el
    lote.** La prueba es estática y no sustituye recorrer las pantallas:
    `director/cargas/seccion/{id}`, `admin/control?periodo_id={id}`,
    `director/reemplazos/{id}` y `/matriculas`. Tampoco se ha visto `/admin/cuadros`,
    que comparte `kpis` con el resumen.
  - Sigue sin verse con sesión el cambio de aspecto de los 31 `.flash` del deploy
    anterior y el botón de `alert__accion` que bajó de línea en tres banners.
- ✅ **02/09/2026 — DEPLOY. `origin/main` pasó de `2b74be0` a `5c353f1`**
  (merge `--no-ff`, pedido por el usuario). **7 commits, SIN MIGRACIONES**: el lote
  es de vistas, SASS, verificadores y documentación.
  - **Qué entra:** las estadísticas por competencia del 01/09, la barra de literales
    rehecha como rejilla de fracciones, y el banner de aviso unificado (47 banners en
    29 vistas, tres declarantes fundidos en uno).
  - **Verificado antes del merge:** `verif_stats_competencia` (40 asertos),
    `verif_banners_aviso` (24, nuevo) y los 7 que auditan `app.css`, todos en verde
    sobre `main` ya mergeado. En navegador: la barra en 8 anchos de 320 a 1400 px sin
    recortes, los 6 casos reales de banner a 340 y 700 px sin desbordes, y **`/login`**
    —la única vista pública, y la que más cambia al borrar la copia de `.alert` de
    `pages/_auth.scss`— con sus tres alertas correctas.
  - ⚠️ **Sin verificar con sesión.** Ninguna pantalla interna se abrió autenticada;
    aplica a los tres puntos del lote. Lo más visible a revisar en producción: los 31
    `.flash` cambian de tamaño y ganan borde e icono, y el botón de `alert__accion`
    baja de línea en tres banners.
  - **El archivo `-` de la raíz sigue existiendo**: el mensaje del merge se pasó con
    `-F <ruta real>`. Nunca `-F -`.
- ✅ **28/08/2026 — DEPLOY. `origin/main` pasó de `1b18e2c` a `2b74be0`**
  (merge `--no-ff`, autorizado por el usuario). **37 commits de contenido,
  SIN MIGRACIONES NUEVAS**: la 055 y la 056 ya estaban aplicadas a mano en
  producción antes del merge (confirmado con el usuario), así que el push no
  requirió tocar la base de datos.
  - **Qué entra:** el lote de Usuarios de Dirección (24/08), que llevaba cuatro
    días esperando en `dev`, el explorador de criterios, el conmutador de 3
    ejes de consulta-notas, el partial de asistencia compartido, el arreglo de
    `.page-header`, y lo del 27-28/08: punto único del roster de evaluación,
    `/admin/cuadros` con 12 gráficos y 3 tablas, el componente global de
    pestañas y los iconos propios del dashboard.
  - **Estado al desplegar:** 31 verificadores en verde, por primera vez en el
    lote — `verif_criterios_filtros_cascada` llevaba días rojo por dos asertos
    CADUCOS del refactor de los 3 ejes, no por un defecto.
  - ⚠️ **Trampa nueva, y costó un susto: `git merge -F -` NO lee stdin.**
    Hay un archivo llamado `-` en la raíz del repo (sin versionar, del
    14/05/2026, con una salida de SASS fallida dentro) y git lo tomó como
    fichero de mensaje: el commit de merge nació con un error de compilación
    por asunto. Se corrigió con `--amend -F <ruta>` ANTES del push. El CSS
    nunca estuvo roto —`app.css` de `main` es byte a byte el de `dev`—, pero
    el mensaje lo aparentaba. **Para mensajes largos, `-F <ruta real>`; nunca
    `-F -`. Y conviene borrar ese archivo `-`.**
  - **Sin verificar en producción todavía:** el imprimible A4 en papel y los
    `page-header` de `/matriculas`, `/padre/notas` y `/admin/actas-siagie`.
    Tampoco se abrió `/docente/calificaciones` tras el cambio de
    `getAlumnosSeccion` — lo cubren dos verificadores contra consultas de
    control escritas a mano, pero conviene mirarlo en prod.
- `dev` = rama de trabajo; `main` = producción (auto-deploy en Hostinger).
  **Preguntar SIEMPRE antes de mergear `dev` → `main`.**
- `dev` y `main` sincronizados el 20/07/2026 (ff `8ae3d08..567b7f9`): lote
  SIAGIE completo (módulo web Actas + secundaria + notas autorizadas por
  dirección), calificación extraordinaria, historial de conducta/asistencia
  con imprimible (migr. 043), vista legado B1 de conducta (admin + banner del
  tutor) y selector de bimestre en /admin/conducta. Migraciones 038-043 ya
  en prod.
- **22/07/2026 — deploy de la compuerta de publicación:** migración 044 importada
  a mano en prod y merge `dev`→`main` (`567b7f9..dca4023`, fast-forward). Arrastra
  también el fix SIAGIE de código de 14 dígitos (`e06f49e`). `dev` y `main`
  quedaron sincronizados.
- **25/07/2026 — deploy del rediseño del orden de mérito (Fases A y B):**
  migración 046 importada a mano en prod (phpMyAdmin) ANTES del merge, y merge
  `dev`→`main` (`10d6d51..68968bb`, fast-forward). Arrastra las migraciones
  045/044 que ya estaban en prod más las Fases A (filtro por `tipo`) y B
  (inmutabilidad del oficial + versión rectificada) verificadas en local, y los
  scripts de verificación (`database/verificaciones/`). `dev` y `main`
  sincronizados en `68968bb`. **Queda la Fase C** (reconstrucción de B1 contra el
  documento oficial, depende del documento del usuario).
- **26/07/2026 — `dev` pusheado en `cce5d90`, 17 commits por delante de `main`.**
  Contiene TODO el rediseño 2 del orden de mérito (F1-F6 + F5b + fixes) y el
  aseguramiento de los dos scripts destructivos. Probado en navegador por el usuario
  con dos usuarios padre de prueba (creados por SQL y ya borrados). **NO desplegado: el
  usuario no autorizó el merge a `main`.** No hay migración pendiente para este lote.
- **29/07/2026 — foto verificada: `dev` en `25599ba`, `main` en `68968bb`,
  28 commits por delante.** El lote acumulado es: rediseño 2 del orden de mérito +
  excepciones de hoja SIAGIE (Ética→EREL, GAMA→EPT de 5°) + vínculos/cobertura con
  `codigo_siagie` editable. **Sigue SIN migración pendiente** → el deploy es merge +
  push, sin tocar la BD de prod. **NO autorizado todavía**; va después del 31/07
  (cierre de notas de los docentes) y ANTES de medir/resolver empates.
- **04/08/2026 — foto verificada: `dev` en `c8c218d` (= `origin/dev`), `origin/main`
  en `68968bb`, 39 commits por delante.** Suma al lote del 29/07 el **semáforo de las
  cards del dashboard docente** (`05ac6ea`: gris "todavía no te toca" / ámbar / verde,
  en Transversales y Conducta) y el merge de `origin/dev`. Árbol de trabajo LIMPIO.
  **`origin/main` es ancestro directo de `dev` → el merge sería fast-forward.**
  Sigue SIN migración pendiente. Verificado el mismo día: `gulp build` reproduce
  `public/css/app.css` y `public/js/` **idénticos** a lo commiteado (sin CSS
  desincronizado), y los 29 archivos PHP del lote pasan `php -l`.
  - ⚠️ **`main` LOCAL estaba 6 commits detrás de `origin/main`.** Un
    `git checkout main && git merge dev` sin actualizar antes NO da el fast-forward
    esperado. **RESUELTO el 04/08:** se puso `main` al día (`10d6d51`→`68968bb`) y se
    mergeó `dev` por fast-forward → **`main` local en `0e250d1`, SIN PUSHEAR**
    (producción sigue en `68968bb`; el auto-deploy no se disparó).
  - **04/08 (después del merge local) — `dev` en `495cb3d`:** suma el fix de la card
    de empates de `/admin/control` (ver "CARD DE EMPATES" en Pendientes operativos).
    `main` local quedó **un commit por detrás de `dev`** → hay que repetir el
    fast-forward antes de pushear. Sigue sin migración.
  - **Verificado contra la copia fresca de prod (04/08):** las 4 verificaciones del
    mérito pasan (fase A 6/6 · fase B candado 046 + rollback · fase 1 snapshot 528 ·
    fase 5b control discrimina 518≠528) y **no dejan rastro** — tras correrlas el
    snapshot sigue en 528 filas, 0 rectificados y 14 desempates.
- **04/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `68968bb` a `de449e2`.**
  Entró a producción el lote acumulado: rediseño 2 del orden de mérito completo,
  excepciones de hoja SIAGIE, vínculos/cobertura con `codigo_siagie` editable, semáforo
  de las cards del dashboard docente, fix de la card de empates, fix del orden
  alfabético con la Ñ y **el fix del roster de asistencia** (que habilitó la captura de
  B2 esa misma tarde). Sin migración.
- **05/08/2026 — foto verificada: `dev` = `origin/dev` = `fae5481`, `origin/main` =
  `de449e2` → 4 commits SIN desplegar.** Árbol limpio. Son `7e40a3d` (la asistencia de
  la boleta usa el mismo umbral que las notas), `c2865e2` (formato oficial —las 4
  columnas— en las 9 entradas de boleta), `ab3966e` (los bimestres `pendiente` no se
  abren desde el índice) y `fae5481` (docs + **la exclusión de Ética del mérito**).
  Sigue sin migración → el deploy sería merge + push.
  - **Sobre Ética:** el commit `fae5481` la EXCLUÍA del mérito, pero la decisión se cerró
    al revés el 05/08 y ya está implementada encima. El lote a desplegar deja el
    comportamiento final: **Ética CUENTA en toda secundaria**. Producción, que nunca
    recibió la exclusión, converge con el estado correcto al desplegarse el lote.
  - ⚠️ **`main` LOCAL quedó en `0e250d1`, por DETRÁS de `origin/main`.** Es la trampa de
    siempre: actualizar `main` antes de mergear, o el fast-forward no sale.
- **05/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `de449e2` a `c8681da`**
  (20:02). **41 commits**, 0 conflictos, árbol limpio. Verificado antes de mergear:
  sintaxis de los PHP del lote, scripts de verificación en verde y sin archivos sensibles
  en el diff. Entró a producción: la **boleta con todas las competencias del plan** y
  guion donde no hay dato (conducta incluida), la **Regla A del retorno de grado**
  (F1-F3), **Ética y Valores en el mérito de toda secundaria**, la **señal de borrador
  como punto único** + marca de agua en la digital, la **descarga de borradores en ZIP**,
  **exonerar a un alumno que ya tiene notas** con las áreas exoneradas fuera del mérito,
  el **formato oficial (4 columnas) en las 9 entradas** de boleta y la **asistencia con el
  mismo umbral que las notas**. La migración **047 se aplicó en prod ANTES del merge**
  (confirmada por el usuario).
  - ⚠️ **Este deploy NO fue fast-forward: se hizo con COMMIT DE MERGE** (`c8681da`, padres
    `de449e2` + `9eb13b9`). Consecuencia permanente: **`main` tiene un commit que `dev` no
    contiene**, así que las dos ramas ya no comparten una historia lineal aunque su ÁRBOL
    coincida. **No hay que "arreglarlo" trayendo `main` de vuelta a `dev`.**
- **06/08/2026 — foto verificada: `dev` = `origin/dev` = `95877bb`, `origin/main` =
  `c8681da`.** `dev` va **1 commit por delante** y ese commit es **solo SQL + docs** (la
  migración 048 y la actualización de estado): **no hay código pendiente de desplegar**.
  Árbol limpio.
  - 🐞 **Incidente del `git pull` (06/08):** se ejecutó `git pull origin main` estando en
    `dev`. Como `pull.rebase = false` (config global de Git for Windows), arrancó un merge
    de `main` dentro de `dev` que quedó **a medias** — `MERGE_HEAD` presente, índice sin
    conflictos y árbol idéntico a `dev`, porque `main` no aporta contenido. Se resolvió con
    `git merge --abort`, sin pérdida. **Estando en `dev`, `git pull` a secas**:
    `branch.dev.merge` ya apunta a `refs/heads/dev`.
- **06/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `c8681da` a `83c87f5`** (merge
  commit, como el del 05/08). **19 commits**, árbol de `main` idéntico al de `dev`.
  - **Lo único que cambia de comportamiento en prod es el guard de las 4 reaperturas**
    del panel de bloqueos: de los 11 archivos del lote, solo **2 son código**
    (`BloqueoController.php` y `bloqueos/index.php`). El resto son las migraciones **048 y
    050 —ya aplicadas en prod, viajan como archivos inertes—** y documentación (los 3
    planes nuevos, el runbook y este ESTADO).
  - **Riesgo bajo por construcción:** el fix es **defensivo**, solo IMPIDE acciones que
    antes destruían datos en silencio; ninguna acción que ya funcionaba deja de hacerlo.
    Por eso se desplegó **antes** de cerrar B2 pese a tocar el panel que se usa durante el
    cierre.
  - **Verificado antes de mergear:** `main` local **sí** estaba al día con `origin/main`
    (la trampa recurrente no mordió esta vez), `php -l` en los 2 archivos, 0 archivos
    sensibles en el diff, **0 cambios en SASS/JS** (no hacía falta `gulp build` ni había
    riesgo de CSS desincronizado) y **ninguna migración pendiente de aplicar**.
  - **La migración `051`** (limpieza de los 130 bloqueos fantasma) sigue **planificada, no
    escrita**, y depende de que antes se implemente F1 del plan de transversales.
    *(Superado: se escribió, se aplicó en prod el 06/08 y en local el 07/08.)*
- **07/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `31b136c` a `2242ec7`** (commit
  de merge, como los del 05 y 06/08). **5 commits**, autorizados por el usuario tras haber
  probado el lote en navegador ese mismo día.
  - **Qué entró:** `/consulta-notas` con transversales y conducta (las 3 fases) y el fix de
    **`notFound()`**, que hasta ahora **no existía**: varios controladores lo llamaban sin
    estar definido, así que en producción ningún 404 llegaba a mostrarse (caía en la página
    de error genérica). Más la limpieza de `CLAUDE.md` y la documentación del lote.
  - **Verificado ANTES de pushear:** `main` local estaba **82 commits detrás** de
    `origin/main` (la trampa recurrente, esta vez enorme) y se puso al día con un
    fast-forward limpio; el **árbol de `main` quedó idéntico al de `dev`** (`git diff main
    dev` vacío); `php -l` limpio en los 8 PHP del lote; **0 archivos sensibles** en el diff;
    **0 cambios en SASS/JS** → no hacía falta `gulp build`. **Sin migración pendiente.**
  - **Riesgo bajo por construcción:** de los 13 archivos, los únicos que cambian
    comportamiento fuera de `/consulta-notas` son `BaseController` (gana `notFound`) y
    `RectificacionController` (pierde el suyo, privado — era obligatorio: un `private` en la
    hija choca con el `protected` de la base y da fatal error de compatibilidad de acceso).
    **Nada toca el camino del cierre de bimestre.**
- **07/08/2026 — SEGUNDO DEPLOY DEL DÍA: `origin/main` pasó de `2242ec7` a `c8fa4fd`**
  (commit de merge). **6 commits**, 8 archivos, de los que solo **3 son código**:
  `AsistenciaModel`, `BoletaModel` y `boleta/digital.php`. Sin migración, sin SASS/JS.
  - **Qué entró:** **F1** (la asistencia de un bimestre sin registro sale en guion) y el
    **sello del director fuera de borrador y vista previa**, más la documentación del día
    (Hito A en el runbook, señal 1.1-bis por competencia, corrección de la causa de los
    fantasmas).
  - **Validado por el usuario en navegador ANTES del merge**, en 4 bloques: el caso 694
    (guion en B1 y `0` en B2), el control 556 sin cambios, el retorno #1 con sus dos
    bimestres con dato, y el pie de la boleta digital sin sello y sin descuadre.
  - **Batería previa, toda en verde:** `verif_asistencia_sin_registro` (nuevo),
    `verif_asistencia_boleta`, `verif_estructura_boleta`, `verif_plan_completo_boleta`
    (1965 filas de nota, 0 perdidas) y `verif_retorno_grado`. Árbol de `main` idéntico al
    de `dev`; `php -l` limpio; 0 archivos sensibles.
  - **`main` local SÍ estaba al día esta vez** (se había puesto al corriente en el primer
    deploy del día), así que la trampa recurrente no mordió.
- **10/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `c8fa4fd` a `945ba91`** (commit
  de merge, 01:29). **7 commits, 9 archivos**, de los que **4 son código**:
  `BloqueoController`, `CalificacionController`, `TransversalModel` y
  `bloqueos/index.php`. Sin migración, sin SASS/JS.
  - **Qué entró:** la **retirada de la cascada de desbloqueo** (desbloquear una académica
    ya no arrastra las transversales de la carga), el fix de la URL de fallback de
    `config/app.php` (`siga_cociap` con guion bajo), la verificación
    `verif_desbloqueo_sin_cascada.php` y la documentación del día (el diferimiento del
    panel de transversales y la checklist de pruebas de navegador).
  - ⚠️ **Revierte la decisión del 07/08 de esperar al cierre de B2 para mergear.** El panel
    de bloqueos se movió justo en el tramo en que se usa para cerrar; a cambio, las
    correcciones de última hora de B2 ya no obligan a re-aprobar TIC/GAMA que nadie tocó.
  - **Estado tras el deploy (verificado el 10/08):** `dev` (`bcbae78`) está **íntegramente
    contenido en `main`** — `git rev-list --left-right --count origin/main...dev` da
    `7 0` → **nada pendiente de desplegar y ninguna migración pendiente de aplicar**.

- **10/08/2026 — SEGUNDO DEPLOY DEL DÍA: `origin/main` pasó de `945ba91` a `992a350`**
  (commit de merge). **6 commits, 7 archivos**, de los que **uno solo es código**:
  `_boleta.scss` con su `app.css` recompilado. Sin migración.
  - **Qué entró:** la **conclusión descriptiva de la boleta pasa a una línea**, que es lo
    que devuelve los ~15.8mm que hacían caer el bloque de firmas a una segunda hoja en
    secundaria. Más el **seeder del peor caso** (script CLI, no toca runtime) y la
    documentación del día: el cierre de B2, la regla del periodo final, el logro anual y
    el orden de mérito en boleta como decisión diferida.
  - **Validado en papel por el usuario ANTES del merge**, contra el peor caso posible
    generado por el seeder (29 filas, todas en C, conclusiones de 500 caracteres, los 4
    bimestres y logro anual), no contra una boleta real.
  - **Verificado antes de pushear:** `main` local **sí** estaba al día con `origin/main`
    (la trampa recurrente no mordió), `php -l` limpio, **0 archivos sensibles** en el diff,
    **`gulp build` reproduce `public/css/app.css` byte a byte** con lo commiteado y el
    árbol de `main` quedó idéntico al de `dev`.
  - ⚠️ **El deploy era condición para IMPRIMIR, no para cerrar.** Producción venía con la
    conclusión a dos líneas: sin este lote, las boletas de secundaria de B2 se habrían
    entregado con las firmas en una segunda hoja.
- **17/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `481bbe7` a `0d7c030`** (commit de
  merge, autorizado por el usuario). **2 commits, 4 archivos, CERO código de runtime.**
  - **Qué entró:** la migración **`052`** (alias huérfano de Ética en secundaria), su
    aplicador `database/aplicar_052_alias_huerfano.php`, y la documentación del cierre
    íntegro del bloque de pendientes operativos.
  - **El deploy fue el MEDIO, no el fin.** Lo que había que hacer era una reparación de
    DATOS en producción, y el script vive en el repo: sin desplegarlo antes, el `php
    database/...` responde `Could not open input file` porque el auto-deploy borra todo lo
    no versionado. **El auto-deploy publica CÓDIGO, no repara DATOS** — el segundo acto fue
    correr el script por SSH, y sin él el deploy no habría cambiado nada.
  - **Verificado antes de pushear:** el lote **no toca `app/`, `routes/`, `public/`,
    `core/`, `config/` ni `resources/`** (comprobado con el diff contra `origin/main`), así
    que no hacía falta `gulp build` ni había riesgo de CSS desincronizado; `php -l` limpio
    en el único PHP del lote; árbol de `main` idéntico al de `dev`.
  - ⚠️ **El trabajo se había hecho estando en `main`.** Se movió a `dev` con un `checkout`
    tras comprobar que `git diff main dev` estaba vacío (árboles idénticos), así que los
    cambios sin commitear viajaron sin conflicto. **La rama de trabajo es `dev`**: conviene
    verificarlo ANTES de empezar a editar, no al ir a commitear.
  - **Los 2 commits van separados por contenido:** `1709abe` (`fix(areas)`: migración +
    aplicador) y `9986899` (`docs(estado)`: el cierre del bloque operativo).
- **22/08/2026 — DEPLOY EJECUTADO: `origin/main` pasó de `0d7c030` a `ae7295f`** (merge
  `--no-ff`, autorizado por el usuario). **6 commits, 11 archivos, CERO código de runtime.**
  - **Qué entró:** la migración **`054`** (la constancia de traslado `052` vuelve a estar
    vigente) con su aplicador `database/aplicar_054_revertir_anulacion.php`, su entrada en
    el control de migraciones, y los **5 commits de documentación** que llevaban sin
    desplegar desde el 17/08 (cierre del bloque de deuda documental, la tabla de la red
    enrutando por tema y el plan de cambio de sección versionado).
  - **Mismo patrón que el 17/08: el deploy es el MEDIO.** Lo que había que hacer era una
    corrección de DATOS en producción, y el script vive en el repo — sin desplegarlo antes,
    el `php database/...` responde `Could not open input file`, porque el auto-deploy borra
    todo lo no versionado. El segundo acto fue correr el script por SSH.
  - **Verificado antes de pushear:** el diff contra `origin/main` **no toca `app/`,
    `core/`, `routes/`, `resources/`, `public/` ni `config/`** (comprobado con
    `git diff --name-only`), así que no hacía falta `gulp build` ni había riesgo de CSS
    desincronizado. `php -l` limpio, batería del repo en **18/21** (los 3 rojos son los
    falsos positivos ya diagnosticados, no regresiones).
  - **`main` local estaba AL DÍA con `origin/main`** antes del merge — verificado con
    `git rev-list --left-right --count`. Es el gotcha del 04/08, que esta vez no se dio.
  - **Los 2 commits propios van separados por tema:** `e68b3f7` (`fix(traslados)`: migración
    + aplicador) y `88634b8` (`docs(estado)`: el registro en el control de migraciones).

## Scripts que escriben en la BD — cuidado (26-27/07/2026)
- **`database/verificaciones/verif_fase_b_orden_merito.php` BORRABA el snapshot oficial
  de B1.** Su paso 4 "autolimpieza" hacía `DELETE` ciego de `orden_merito_snapshot` y
  `orden_merito_rectificado` del periodo 1. Se escribió el 24/07, cuando B1 no tenía
  snapshot, y quedó obsoleto al día siguiente con la Fase C. **Destruyó las 528 filas en
  LOCAL el 26/07.** Se intentó reconstruirlas (misma firma, neutralizando temporalmente
  los 8 `trasladado` con notas B1 y regenerando), pero **esa reconstrucción NO quedó
  persistida** — ver el punto siguiente. Ahora: corre dentro de una transacción
  con ROLLBACK, aborta si detecta el archivo de secretos de producción, y reproduce el
  escenario "sin oficial" dentro de la transacción (antes su primera aserción no probaba
  nada, porque con un oficial presente la llamada devolvía `'rectificado'`).
- **LOCAL tuvo el snapshot oficial de B1 VACÍO — RESUELTO el 27/07/2026.**
  `orden_merito_snapshot` y `orden_merito_rectificado` habían quedado en **0 filas**
  (PROD conservó siempre las 528). No era un fallo del código: sin filas
  `debeUsarSnapshot()` cae al cálculo en vivo de forma limpia y local mostraba **518
  alumnos** en B1. Lo que sí rompía era la CONFIANZA EN LAS PRUEBAS
  (`verif_fase5b` daba un **OK falso** en su paso 2, comparando el vivo contra sí mismo).
  **Reconstruido con `database/reconstruir_snapshot_b1.php`** (ver abajo): 528 filas,
  11 grados, 23 secciones, puestos 1-72, 0 empates pendientes. Los **14 desempates
  resueltos** nunca se perdieron — son lo único no derivable por cálculo.
  - **Fidelidad verificada antes de escribir** (los 3 cambios del rediseño 2 son
    inocuos para B1): 0 de las 12 047 calificaciones de B1 carecen de bloqueo (P2 no
    mueve promedios), hay 0 notas de Ética en B1 (P5 no aplica) y el ranking completo
    calculado con el `ORDER BY` de hoy (`m.id`) vs. el del 25/07 (apellidos) da
    **exactamente los mismos puestos** — los 14 desempates cubren todos los grupos
    irreducibles, así que el apellido nunca llegaba a dirimir. Tampoco hubo
    rectificaciones posteriores (solo 2 extraordinarias, fuera del mérito por diseño).
  - **Corrección de cifras (medidas el 27/07):** el roster en vivo de B1 da hoy **518**
    (no "520/519") y la regla Fase C reincorpora **10**, no 9: los 8 `trasladado`, la
    541 y además la **357 (HUAMAN VIENRICH CATALEYA)**, que también es `retirado`.
    518 + 10 = 528, y la firma (528 filas, puestos 1-72) coincide con la de prod.
  - `backfill_orden_merito.php` NO sirve para B1 (regla general → 518).
- **`database/backfill_orden_merito.php`** ahora salta los periodos con snapshot oficial
  ya PUBLICADO salvo `--forzar`.
- **`database/reconstruir_snapshot_b1.php` (nuevo, 27/07):** reconstruye el oficial de
  B1 con la regla ESPECIAL de la Fase C (roster sin filtro de tipo). Guardas: aborta si
  detecta el archivo de secretos de PROD; **simula por defecto** (`--confirmar` para
  escribir); transacción; y antes del COMMIT verifica la FIRMA del documento
  (528 filas / puestos 1-72 / 0 empates / 0 sin puesto de sección) — si no coincide,
  ROLLBACK y aborta, prefiriendo dejar local sin snapshot antes que grabar un documento
  distinto del de producción. Idempotente (verificado con 2 corridas). Reutiliza la
  cascada del modelo por reflexión; solo duplica el SQL del roster, a propósito: meter
  la regla Fase C dentro de `OrdenMeritoModel` abriría la puerta a generar rankings sin
  filtro de tipo por accidente.
- **`verif_fase_a_orden_merito.php` leía el ranking snapshot-aware (corregido 27/07).**
  Escrito el 24/07, un día ANTES de la Fase C. Al volver a existir el snapshot de B1
  —cuyo roster incluye trasladados y retirados por la regla especial— sus casos 541 y
  308 salían "EN RANKING" contra su expectativa "FUERA". No era un fallo del código:
  en PROD reportaba lo mismo desde el 25/07, y en local el snapshot vacío lo hacía pasar
  por la razón equivocada. Ahora lee `rankingGradoLive` por reflexión (igual que
  `verif_fase5b` y `gradosConEmpatesPendientes`): lo que la Fase A verifica es el FILTRO
  del roster en vivo, no el documento congelado. Los 6 casos vuelven a coincidir.
- **Regla general:** ningún script de `database/` debe "limpiar" con DELETE lo que no
  creó. Si escribe para probar, que use transacción + rollback.
