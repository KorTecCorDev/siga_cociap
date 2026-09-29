# Decisiones de diseño diferidas y planes cerrados

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.

## Asistencia por fechas — lo que queda para después (29/09/2026)

La asistencia por fechas (migración 069) se construyó con estas piezas FUERA de alcance,
por decisión del usuario:

- **Familias → v1.1.** Pidió que las familias vean las fechas de sus hijos, pero hoy no
  hay logins de padres y sus vistas no se pueden probar. Se construye cuando existan.
- **Calendario de feriados / vacaciones: NO.** Se marca cualquier lunes a viernes del
  bimestre; un feriado se puede marcar por error. Si hiciera falta, sería una tabla
  mantenida cada año.
- **Documento de sustento de la justificación → 2027**, junto a la asistencia por QR
  (§8 de `docs/modulos/auxiliares.md`). Hoy basta un motivo del catálogo.
- **Asistencia por QR (2027):** `asistencia_incidencias` ya ES la tabla diaria que ese
  plan preveía; el escáner escribiría por `AsistenciaModel::marcarDia` y los contadores
  seguirían saliendo de `recalcularContadores`, sin tocar boleta ni cierres.

## Orden de mérito EN LA BOLETA — descartado por ahora (10/08/2026)

> Idea del usuario: añadir el puesto en una abreviatura pequeña, con el ranking de
> sección, a un lado del documento. **Descartada el mismo día por él**, por la
> discrepancia que genera entre estudiantes. No se escribió ni una línea de código.
> Se anota solo para no re-derivar el análisis si vuelve a plantearse.

- **Dónde encajaría sin costo:** el bloque inferior (`.boleta-info`) ya tiene la tabla de
  asistencia con una columna por bimestre + Total; el puesto entra como dos filas de esa
  misma forma, o como bloque hermano junto al QR (**costo de alto ~0**). **Nunca** dentro
  de la tabla de competencias: el mérito es del alumno, no de la competencia.
- **El dato ya existe y está congelado:** `orden_merito_snapshot.puesto_grado` y
  `.puesto_seccion`. Hoy no hay lector por matrícula (`rankingGrado` devuelve el grado
  entero), habría que añadir uno chico contra el snapshot.
- 🔴 **Tres trampas medidas, que son la razón de anotar esto:**
  1. **El retorno de grado rompe la búsqueda directa.** El puesto vive bajo la matrícula
     **operativa**, no bajo la de identidad con la que se arma la boleta. Medido en B2:
     524 filas de snapshot contra 525 matrículas vivas, y la que falta es la **190**. Un
     lector por la matrícula del documento la dejaría sin puesto **teniéndolo**; hay que
     usar la misma unión de fuentes que el resto de la boleta.
  2. **La compuerta 044 ya lo cubre si se respeta el filtro de bimestres de las notas**
     (publicar libera boletas Y mérito juntos, por nivel) — pero el umbral `'archivo'`
     **ignora la compuerta a propósito**, así que el papel de RA mostraría el puesto de un
     bimestre cerrado y **aún no publicado**.
  3. **Habrá boletas sin puesto por razones distintas:** un trasladado/retirado sale del
     ranking vivo, pero el snapshot de B1 los reincorporó (regla de la Fase C) → el mismo
     alumno mostraría puesto en B1 y guion en B2. Es correcto y se lee como un error.
- **Sin decidir, si resucita:** en qué boletas aparece (todas / solo el papel de RA / solo
  gestión), si van los 4 bimestres o solo el vigente, qué se hace con quien no tiene
  puesto, y si el puesto 1 (media beca) se destaca.

## Panel de transversales completo + punto único de "carga dueña" — DIFERIDO AL AÑO ACADÉMICO SIGUIENTE (07/08/2026)

> **NO implementado, y a propósito.** Decisión del usuario: es un cambio estructural y su
> sitio natural es el **arranque del próximo año académico**, cuando tocar el gate del
> cierre del tutor no compite con un bimestre en curso. El análisis está hecho y **no hay
> que re-derivarlo**.

### Qué se pidió
Que el gestor de bloqueos/desbloqueos de transversales muestre **todas** las competencias
diferenciadas por estado —como ya hace el panel académico— y no solo las aprobadas y
bloqueadas.

### El diagnóstico (correcto)
`TransversalModel::getBloqueosTransversalesPorPeriodo` arranca `FROM bloqueos_competencia`
con `INNER JOIN`: **estructuralmente no puede mostrar otra cosa que lo bloqueado**. El panel
académico (`CalificacionModel::getCompetenciasPorPeriodo`) ya resuelve lo mismo al revés —
parte de `cargas_academicas`, hace `LEFT JOIN` al bloqueo y calcula `num_criterios` para
distinguir sus 4 estados.

### Por qué NO es una inversión simple — las tres complicaciones

1. **El universo transversal no es un join, es una regla escrita CUATRO VECES.** En lo
   académico competencia y carga se unen por `area_id`/`subarea_id`. En transversales **el
   vínculo no existe en el esquema**: se resuelve por nivel + dos exclusiones (fuera las
   cargas TOE; en unidocente solo la *carga dueña*). Los cuatro sitios están listados en
   `CalificacionController` (~línea 508). Construir el panel sería la **quinta copia**, y
   la cuarta copia divergente es exactamente la que creó los **130 bloqueos fantasma**.
2. **Hoy no aportaría información.** Medido el 07/08/2026: universo canónico = **345 cargas
   × 2 competencias = 690 filas**, y en **B2 las 690 están "bloqueada CON notas"**, 0 de
   cierre — o sea, **un solo estado**. El panel actual ya muestra esas mismas 690. El valor
   es **prospectivo**: aparece en B3, a mitad de bimestre, cuando haya pendientes de verdad.
3. 🔴 **En B1 MENTIRÍA, y además escondería los fantasmas.** Las **1052** notas
   transversales de B1 viven en **23 cargas `inactiva`** de un área que es ella misma
   `tipo='transversal'` (modelo viejo: una carga dedicada por sección). Un universo de
   cargas **activas** pintaría B1 como *690 filas "bloqueada SIN notas"* → se lee como
   "nadie evaluó transversales en B1", que es **falso**. Y como **B1 conserva los 130
   fantasmas** (la `051` solo limpió B2, por decisión), un panel construido sobre el
   universo canónico **los volvería invisibles**: hoy son las 130 filas de más que el panel
   sí muestra (820 en total), y son el único sitio donde se ven.

### Cómo hacerlo cuando se retome
- **Extraer primero** la regla de "carga dueña" a un punto único, y recién después escribir
  el panel sobre él. Al revés se crea la quinta copia y se repite el patrón de fallo.
- La query no puede ser una inversión simple: **universo `LEFT JOIN` bloqueos ∪ bloqueos
  FUERA del universo marcados como anomalía**, o se pierde la visibilidad de los fantasmas.
- Decidir qué hacer con los periodos **legado**: o el panel solo ofrece bimestres del modelo
  nuevo, o etiqueta B1 explícitamente como legado.
- ⚠️ El refactor toca **`TransversalModel::estadoCargasSeccion`**, que es el **gate del
  cierre del tutor** y está marcado como delicado. Esa es la razón de fondo del diferimiento.
- **Converge con el plan de los 4 registros del bimestre** (`docs/modulos/cierre-cuatro-registros.md`),
  cuya F1 es también un "punto único" sobre este mismo territorio: **conviene hacerlos
  juntos, en una sola tanda de pruebas.**
- 🔎 **Punto ciego latente que se descubrió de paso:** el panel ACADÉMICO tiene el mismo
  problema —un bloqueo sobre una carga `inactiva` desaparece de su lista—, solo que ahí
  nunca ha mordido. Si se toca uno, revisar el otro.

## Suspensiones / disciplina — decisión de diseño (02/07/2026)

> **NO implementado.** Solo se fija el PRINCIPIO de diseño para no cometer un error
> estructural cuando se construya. El colegio maneja faltas al reglamento con
> suspensiones (1 a 4 días, máx.) y, al extremo, expulsión. El registro de
> sanciones, la expulsión y el comportamiento de grilla se diseñarán JUNTOS como un
> módulo disciplinario propio (diferido por el usuario).

### El principio: las suspensiones NO se manejan con `desactivado`
- `desactivado` significa *"el estudiante YA NO está matriculado"* (baja
  administrativa / traslado de salida): apaga el login del apoderado
  (`desactivarUsuarioDeEstudiante`) y las boletas públicas de TODOS los periodos
  (`boletas_publicas.activa=0`), y lo saca del orden de mérito. Una suspensión es lo
  contrario: el alumno **sigue matriculado** y cumple una medida **temporal**.
- Una sanción exige lo que `desactivado` NO puede modelar: tipo de medida, duración
  (inicio/fin, nº días), falta/artículo del reglamento, autoridad que la impone,
  acta, estado (vigente/cumplida/anulada) e **historial acumulado** (varias sanciones
  por año). `desactivado` solo tiene UN `motivo_estado` de texto y un único estado.
- **Dominio propio, separado** del ciclo de vida de la matrícula. La **nota de
  conducta es INDEPENDIENTE**: una sanción NO toca la nota (el registro disciplinario
  va aparte).

### Requisito ya definido para el módulo futuro
- **El alumno suspendido debe DESAPARECER de la grilla de calificaciones por criterio**
  y reaparecer cuando vuelve a matrícula `Aprobado`. Esto es un comportamiento NUEVO:
  hoy `Docente\CalificacionController::getAlumnosSeccion` incluye `aprobada`,
  `pendiente` y `desactivado`; el ÚNICO que se cae de la grilla es `trasladado`.
  Implica un **estado temporal propio** (conceptualmente `suspendido`), **NO**
  `desactivado`.

### Tensiones a resolver ANTES de codificar (cuando se retome)
1. **Disparador:** el estado "suspendido" debe nacer del REGISTRO de la sanción
   (fechas/tipo/autoridad). Sin ese registro sería un estado huérfano — el mismo
   defecto que `desactivado`.
2. **Duración corta vs. grilla:** una suspensión de 1-4 días entrando/saliendo de la
   grilla genera huecos (si el docente califica ese día, el suspendido queda sin
   fila → recalificar al volver). Definir si "salir de la grilla" aplica a
   suspensiones cortas o solo a separaciones largas/indefinidas.
3. **No destruir lo registrado:** matrícula y notas persisten (la suspensión es
   temporal); solo se OCULTAN de la grilla y reaparecen intactas al volver.
- **Expulsión:** único caso donde el desenlace SÍ dispararía una baja de matrícula
  (`desactivado`/traslado), pero como CONSECUENCIA registrada aparte, no como el
  registro mismo. A decidir con el módulo completo.

### Interino aceptado (parche, no solución)
Hasta tener el módulo, se PUEDE usar la desactivación de matrícula como paño
temporal (baja reversible con motivo explícito, p. ej. `"Suspensión disciplinaria
N días (dd/mm–dd/mm)"`), **entendiendo sus límites**: (a) NO saca al alumno de la
grilla (sigue apareciendo para calificar); (b) apaga el login del apoderado y las
boletas de todos los periodos; (c) es manual y sin traza disciplinaria. Es un
parche consciente, no el comportamiento correcto.

## CAPACITACIÓN docentes 08/07/2026 — PLAN CERRADO (03/07/2026)

> Capacitación + presentación oficial de SIGACOCIAP. Estrategia debatida el
> 02-03/07; plan operativo CERRADO el 03/07. **No se construye nada nuevo** y
> la BD de producción NUNCA recibe datos de prueba.

### Plan final
- **Dos turnos:** primaria 12:30pm-2:00pm; secundaria 7:30pm-9:00pm (aprox.).
- **Demos del flujo completo** (aprobar → bloquear → cerrar bimestre → boleta):
  las proyecta el desarrollador desde su **entorno de desarrollo** (BD de
  desarrollo), nunca sobre producción. Los docentes no conocen ese entorno.
- **Práctica de los docentes en producción = TRABAJO REAL:** crean sus criterios
  y notas reales del II Bim. Sin notas de prueba → **sin backup/restore, sin
  ventana de mantenimiento**; primaria puede seguir digitando esa misma noche.
- **Boleta final demostrada con bimestres CERRADOS** (hoy solo el I Bim). El
  II Bim de producción permanece `activo` todo el día → cero fuga a familias.
- **Una sola URL para docentes** (producción). Si un docente aprueba/bloquea por
  error, se revierte con el desbloquear del director (cascada), sin restore.

### HALLAZGO técnico permanente — la fuga ocurre al CERRAR, no al bloquear
- La boleta pública usa `armar(..., soloOficiales=true)` que filtra periodos a
  `estado='cerrado'` (`BoletaModel::getPeriodosDelAnio`). Bloquear/aprobar
  competencias NO expone nada mientras el bimestre siga `activo`; ponerlo en
  `cerrado` lo expone al instante.

### Recomendación DIFERIDA — compuerta de publicación (C)
- Desacoplar "cerrar bimestre" (interno) de "publicar boletas a familias" (acto
  de dirección). **NO se construyó** (innecesaria para el taller con el plan final).
  **Retomar ANTES del cierre real del II Bim:** sin ella, cerrar publica al
  instante. También quedaron diferidos el modo mantenimiento (B) y el staging
  `dev.sigacociap.net`.
- ⚠️ **El diseño original de esta recomendación (flag `periodos.publicado` + un
  `AND` en `soloOficiales`) quedó OBSOLETO el 20/07/2026** y NO debe implementarse:
  `soloOficiales` ya no existe (lo reemplazó el parámetro `$datos` del Hito A) y un
  booleano no alcanza, porque la publicación es **por nivel y con fecha/hora**
  (primaria se entrega un día antes que secundaria). **Plan vigente en
  `docs/ESTADO.md` → "Compuerta de publicación de boletas".**

### Reencuadre de la fecha límite (guion del taller)
- Mostrar el flujo completo revela que las boletas se arman al instante → NO
  omitirlo; reencuadrar: el cuello de botella se movió a la **completitud de
  TODOS los docentes** (un docente tarde deja la boleta incompleta y congela a
  toda la sección — tutor y transversales dependen de que todos cierren), y
  **publicar es decisión institucional**, no consecuencia del último clic.
  Táctica: mostrar una boleta incompleta y el tablero de completitud X/Y.
