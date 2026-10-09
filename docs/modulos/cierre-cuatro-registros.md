# Plan — Los cuatro registros del bimestre y el contrato del cierre

> 🔴 **REPLANTEADO el 09/10/2026 — leer §0 antes que el resto.** El plan del 04/08
> sigue válido en su diagnóstico, pero el usuario **derogó la D2** («una sola fecha») y
> amplió el alcance. El orden y las fases vigentes son las de §0.

## 0. Plan vigente (09/10/2026)

### Decisiones del usuario (09/10/2026 — CERRADAS, no re-preguntar)
- **Dos fechas por ROL** (deroga la D2 del 04/08):
  - **Auxiliares** (conducta y asistencia): fecha propia, **anterior**.
  - **Docentes** (académicas, TIC/GAMA y **todo lo del tutor**: conducta etapa 2,
    conclusiones y cierre de transversales): `limite_notas`, la fecha final.
  - **El tutor NO tiene plazo extra**: un tutor es también docente de otras secciones,
    y un plazo mayor lo llevaría a registrar sus notas el último día mientras sus
    colegas esperan.
- **Las transversales del tutor quedan bajo la fecha docente** (es la F4 de abajo). Hay
  que avisar antes a los tutores.
- **La conducta se sigue pudiendo bloquear antes de `fecha_fin`** (la asistencia no).
- **Después de «Bloquear y aprobar» nadie registra ni edita.** Para corregir: desbloquear
  desde `/director/bloqueos`, o una **RECTIFICACIÓN de conducta y asistencia** por RA/admin,
  con auditoría, como la de notas. Lectura aplicada: vencida la fecha de los auxiliares,
  RA/admin tampoco registran por la vía normal (quedan el bloqueo forzado y la rectificación).

### Fases vigentes (cada una desplegable sola, en este orden)
| Fase | Qué | Migración | Estado |
|---|---|---|---|
| F0 | Correcciones urgentes: ruta vieja de conclusión retirada; `guardarConclusionAlumno` con carga propia y plazo; conclusión obligatoria en el servidor al aprobar | no | **HECHA en `dev` (09/10/2026)** — `verif_guardas_conclusion.php` |
| F1 | Punto único del plazo `EdicionPeriodoModel` (las 5 copias de la regla + las 3 de «días para el cierre»; corrige el «0 días» tras vencer) | no | pendiente |
| F2 | Coherencia: semáforo del auxiliar con `bimestreTerminado`; transversales del tutor bajo la compuerta; forzados solo con el periodo `activo`; comentarios vencidos | no | pendiente |
| F3 | Guard del cierre: asistencia bloqueada y conducta en sus dos etapas, todas las secciones del año | no | pendiente |
| F4 | `periodos.limite_auxiliares` (NULL = vale `limite_notas`); `fecha_fin ≤ limite_auxiliares ≤ limite_notas` | **sí** | pendiente |
| F5 | Rectificación de conducta y asistencia (RA/admin, motivo + auditoría) | **sí** | pendiente — **diseño a cerrar con el usuario** (qué se rectifica en conducta y en asistencia por fechas, tabla de auditoría, etapa 2 ya cerrada, boleta publicada) |

- La F3 vieja (guard de sección vacía de asistencia) ya la cubren el 100 % confirmado y
  «cada estudiante, cada día» (29/09 y 09/10/2026): no se implementa.
- La F4 vieja (transversales bajo la compuerta) pasa a la F2 nueva.

> **Estado: PLAN APROBADO EN SUS DECISIONES, SIN IMPLEMENTAR.** Redactado el
> **04/08/2026**. Se ejecutaba **después de cerrar y publicar el II Bimestre**, para que
> el primer bimestre bajo las reglas nuevas fuera el **III**.
> ✅ **ESA CONDICIÓN YA SE CUMPLIÓ: B2 se cerró el 10/08 y se publicó el 13-14/08/2026,
> así que el plan está DESBLOQUEADO desde el 14/08.** Sigue sin implementar.
> ⚠️ Empezar por **F1 (punto único de «carga dueña»)**: es prerrequisito duro y lo comparten
> la regla del periodo final (tope **05/10/2026**) y el panel de transversales diferido.
> Estado vivo: `docs/ESTADO.md`. Runbook del cierre: `docs/runbooks/cierre-de-bimestre.md`.

## 1. De dónde sale este plan

El usuario planteó la regla del colegio:

> *"El registro de asistencia tiene el mismo rango que el registro de competencias
> transversales, competencias académicas y conducta; estos 4 registros deben estar
> correctamente aprobados y bloqueados para luego recién proceder a cerrar el bimestre."*

Al verificarla contra el código aparecieron tres cosas:

1. La premisa de partida estaba **invertida**: el registro de asistencia no exige el
   bimestre cerrado, exige que esté **abierto** y dentro de `limite_notas`
   (`AsistenciaModel::periodoEditable`). Cerrar lo deja en solo lectura para siempre.
2. Lo que realmente ocurría: **`limite_notas` de B2 venció el 04/08 a las 04:00**, así
   que la captura se cerró sola con el bimestre todavía `activo`.
3. **El hallazgo grave:** B2 tenía **0 filas en `inasistencias` y 0 secciones
   bloqueadas** (B1: 528 y 23). Cerrar y publicar así habría mandado a las familias
   asistencia en ceros — un dato **falso**, no ausente.

> ⚠️ **La corrección de B2 NO es parte de este plan.** Se resuelve a mano ahora
> (ampliar `limite_notas` → registrar las 23 secciones → bloquearlas), antes de la
> Fase 4 del runbook. Este documento es para que no vuelva a pasar.

## 2. Estado actual medido (04/08/2026)

La política que el colegio describe como **una** hoy está implementada **cuatro veces,
en tres regímenes distintos**:

| Registro | Compuerta temporal | ¿El cierre la exige? | ¿El cierre la fuerza? |
|---|---|---|---|
| Competencias académicas | `CalificacionModel::periodoEstaBloqueado` — cerrado **o** `limite_notas` vencido | no | **sí** (`bloquearCompetenciasPendientes`) |
| Competencias transversales | **ninguna** — `TutoriaController` no consulta fecha ni estado | no | **sí** (`crearCierresTransversalesPendientes`) |
| Conducta | `ConductaModel` (≈`:703`) — `activo` **y** dentro de `limite_notas` | **no** | **no** |
| Asistencia | `AsistenciaModel::periodoEditable` (`:419`) — `activo` **y** dentro de `limite_notas` | **no** | **no** |

Dos consecuencias:

- **Conducta y asistencia están fuera del contrato del cierre**: pueden quedarse sin
  bloquear indefinidamente y el bimestre cierra igual.
- Los dos regímenes con fecha **no coinciden entre sí**: ante un periodo `pendiente`,
  `periodoEstaBloqueado` responde "no bloqueado" y `periodoEditable` responde
  "no editable".

## 3. Decisiones cerradas (usuario, 04/08/2026 — no re-preguntar)

| # | Decisión | Elegido |
|---|---|---|
| D1 | Cierre con conducta/asistencia sin bloquear | **EXIGIR: abortar el cierre** y listar lo que falta |
| D2 | Fecha límite | **ÚNICA para los 4 registros**, lectura unificada en un punto único. **Sin migración** |
| D3 | Transversales | **Sí, igualarla**: pasa a respetar la compuerta temporal |
| D4 | Bloquear una sección de asistencia sin ninguna fila | **Rechazar con aviso** |
| D5 | Universo canónico de secciones del guard (F2) | **TODAS las secciones del año**, sin filtrar por tutor ni por nómina |
| D6 | Etapas de conducta que exige el guard (F2) | **Las dos**: `ra_bloqueado_en` **y** `tutor_cerrado_en` |

Notas de alcance derivadas:

- D1 aplica **solo a conducta y asistencia**. Académicas y transversales **siguen
  forzándose** en el cierre — es la válvula de escape para el docente que nunca bloquea,
  y no se toca.
- D4 se pide **solo para asistencia**. Conducta queda como está (su vacío no produce un
  cero engañoso, sino una casilla vacía).

## 4. Diseño

### F1 — Punto único de la compuerta temporal

Hoy la regla "¿se puede editar este periodo?" está escrita **cinco veces**: tres en PHP
(`CalificacionModel:288`, `AsistenciaModel:419`, `ConductaModel:703`) y dos como columna
calculada en SQL (`AsistenciaModel:50`, `ConductaModel:53`).

> **No confundir con lecturas que NO son compuerta** y quedan fuera del refactor:
> `AnioAcademicoModel:617` (ranking de docentes más rápidos: usa `limite_notas` como
> referencia de margen, con fallback a `fecha_fin`), `BloqueoController:143` y
> `PanelController:115` (cuenta de días restantes para mostrar en pantalla). Ninguna
> decide si se puede escribir.

**Propuesta:** un modelo dedicado, siguiendo el idioma ya establecido por
`PublicacionBoletaModel` (punto único de la compuerta 044):

```
app/Models/EdicionPeriodoModel.php
  esEditable(int $periodoId): bool
  motivoNoEditable(int $periodoId): ?string   // 'cerrado' | 'plazo_vencido' | 'no_iniciado'
```

- Los 4 modelos pasan a delegar. **No se replica la regla en SQL**: las columnas
  calculadas `editable` de `listarPeriodosActivos` se sustituyen por post-proceso en PHP
  sobre el mismo resultado, para que exista **una sola implementación**.
- `motivoNoEditable` existe para que la UI diga *por qué* no se puede escribir. El
  incidente de B2 fue difícil de diagnosticar precisamente porque la pantalla no
  distinguía "bimestre cerrado" de "plazo vencido".

> ✅ **Riesgo de los periodos `pendiente`: VERIFICADO Y DESCARTADO (04/08/2026).**
> Unificar obliga a elegir una semántica para `pendiente`; la correcta es **no editable**
> (aún no empieza), que es lo que ya hacen conducta y asistencia, y adoptarla cambia en
> teoría el comportamiento de académicas. En la práctica **no cambia nada alcanzable**:
> `periodoEstaBloqueado` solo lo consume `Docente\CalificacionController` (8 llamadas,
> ningún otro archivo); 7 reciben el periodo de `getPeriodoActivo()`, que es
> `WHERE p.estado = 'activo'` (`:1139`), y el selector de bimestre del docente ofrece
> solo `estado IN ('activo','cerrado')` (`:62-69`). Las 2 restantes (`:957`, `:1035`)
> derivan el periodo de `criterios.periodo_id`, y un criterio solo nace bajo el periodo
> activo. Un `pendiente` nunca llega a la función.

### F2 — Guard del cierre (D1)

En `Director\PeriodoController::cerrar`, un **tercer guard** junto a los de empates y
evaluación incompleta, y **antes** de abrir la transacción:

- **Universo (D5): TODAS las secciones del año del periodo**, sin filtrar por tutor ni por
  nómina. Es el criterio estricto: ninguna sección puede quedar invisible al contrato.
- Falta bloqueo si **asistencia** no tiene `cierre_id`, o si **conducta** no tiene
  `cierre_id` **o** le falta `tutor_cerrado_en` (D6: se exigen las dos etapas).
- Abortar nombrando **registro + secciones** (no un conteo suelto: el mensaje tiene que
  decir a dónde ir).

> ⚠️ **D5 obliga a SQL nuevo — corrige una suposición de la primera versión del plan.**
> Se creía reutilizables `ConductaModel::getResumenSeccionesPorPeriodo` y su gemelo de
> `AsistenciaModel`, pero **ninguno recorre el universo canónico**: conducta hace
> `INNER JOIN usuarios` por `s.tutor_id` y exige `s.tutor_id IS NOT NULL` (`:363-373`);
> asistencia exige `s.estado_nomina = 'aprobada'` (`:388`).
> **Recomendación:** query propia para el guard y **no tocar** los dos resúmenes — los
> consume la UI del panel de bloqueos (`BloqueoController:174-175`) y ampliarles el
> universo pintaría secciones sin tutor con el nombre en `null`. Decidir al implementar.

> 📊 **Medido en local el 04/08/2026:** las 23 secciones del año tienen tutor **y** nómina
> aprobada, así que hoy los tres universos coinciden y la diferencia es **latente**.
> Antes de activar el guard conviene repetir la medición **en producción**.

### F3 — Guard de sección vacía (D4)

En `AsistenciaModel::bloquearRA`, antes del INSERT: si la sección no tiene ninguna fila
en `inasistencias` para ese periodo, rechazar con
*"Esta sección no tiene ninguna incidencia registrada. Si de verdad no hubo faltas ni
tardanzas, guarda las filas en cero y vuelve a bloquear."*

**Este es el guard que habría evitado el incidente de B2.**

> ⚠️ **NO reusar `getProgresoPorSeccion` → `registrados` como fuente del guard** (era la
> idea original). Esa query filtra las matrículas por `m.estado = 'aprobada'` (`:77`), y
> el invariante del repo dice que los rosters de evaluación **sí** incluyen `desactivado`
> y `pendiente` (solo excluyen `trasladado`/`retirado`). Una sección cuyas únicas
> incidencias fueran de alumnos `desactivado` daría `registrados = 0` y el guard
> **rechazaría un bloqueo legítimo**. Contar filas de `inasistencias` de la sección
> directamente.

> ✅ **Viabilidad confirmada:** `AsistenciaModel::guardar` (`:172`) es un upsert que
> persiste filas con los 4 contadores en cero, y el índice ya las cuenta como registro
> consciente del operador (`:59-64`). El mensaje del guard es ejecutable tal cual: la
> sección sin incidencias reales guarda ceros y bloquea.

> 📝 **Al implementar, reescribir el comentario de `AsistenciaModel:304-307`**, que hoy
> documenta la decisión CONTRARIA: *"Sin fila = 0 incidencias (estado valido), asi que NO
> exige completitud"*. D4 la revierte; si el comentario queda, miente.

### F4 — Transversales bajo la compuerta (D3)

`Docente\TutoriaController` pasa a consultar `EdicionPeriodoModel` en sus endpoints de
escritura, igual que los otros tres.

> ⚠️ **Requiere aviso previo a los tutores.** Hoy registran transversales sin límite de
> fecha; ponerles uno sin avisar los sorprende a mitad de bimestre. **Coordinar la
> comunicación antes de desplegar esta fase** — es la única del plan con impacto directo
> en el trabajo diario de un docente.

## 5. Orden de ejecución

`F1` → `F3` → `F2` → `F4`.

- **F1 primero**: las demás se apoyan en el punto único.
- **F3 antes que F2**: sin el guard de sección vacía, el guard del cierre se puede
  satisfacer bloqueando secciones vacías — se cumpliría la forma y no el fondo.
- **F4 al final**: es la única que depende de una comunicación externa.

## 6. Qué NO hace este plan

- **No cambia `limite_notas` a varias fechas** (D2): sigue siendo una sola. Sin migración.
- **No convierte académicas/transversales en "exigir"**: se siguen forzando.
- **No valida retroactivamente.** B1 y B2 cerraron (o cerrarán) bajo las reglas viejas;
  el primer bimestre con el contrato nuevo es **B3**. No se reabre nada.
- **No toca el orden de mérito, la boleta ni la compuerta de publicación.**

## 7. Verificación

- Script en `database/verificaciones/` que, para un periodo dado, liste las secciones sin
  bloqueo de cada uno de los 4 registros — el mismo dato que verá el guard. Solo lectura.
- Prueba del guard del cierre en local con una sección desbloqueada a propósito, dentro
  de **transacción con ROLLBACK** (regla del repo: ningún script de `database/` borra lo
  que no creó).
  - ⚠️ **En local `cierres_asistencia` está VACÍA** (0 filas, ni siquiera B1: la tabla
    nació con la migración 043 aplicada el 22/07 y los bloqueos de B1 se hicieron en
    prod). El escenario "bloqueado" hay que **construirlo** dentro de la transacción;
    no se puede partir de datos existentes. Conducta sí sirve de referencia: B1 local
    tiene las 23 secciones con las dos etapas.
- Comprobar que `EdicionPeriodoModel` devuelve lo mismo que las 4 implementaciones
  actuales para los periodos 1-4 **antes** de borrarlas — es un refactor de
  equivalencia, igual que el de la card de empates.

## 8. Por qué importa más allá del síntoma

Es el **mismo patrón** que produjo los empates fantasma del Centro de Control: una regla
de negocio copiada en varios módulos que se desincroniza en silencio. Ahí el resultado
fue una card que no se limpiaba nunca; aquí, un registro oficial vacío que iba a llegar
a las familias como ceros. En los dos casos el síntoma no es un error visible sino **un
dato creíble y falso**, que es el modo de fallo más caro de este sistema.

Ver `docs/modulos/orden-merito.md` §"PUNTO ÚNICO de qué empates faltan" y
`docs/modulos/admin.md`.
