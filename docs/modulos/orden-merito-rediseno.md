# Rediseño del Orden de mérito y Ranking por sección — IMPLEMENTADO (25-26/07/2026)

> **ESTADO: las 6 fases están EN PRODUCCIÓN desde el 04/08/2026** (deploy `de449e2`).
> Implementadas y probadas en navegador el 26/07/2026.
> *(Hasta el 17/08 esta línea decía «en la rama `dev` — pendiente de deploy a `main`»:
> se escribió al implementar y nadie volvió a ella al desplegar.)* Este archivo se conserva como
> registro del DISEÑO aprobado; el estado vigente del módulo vive en
> `orden-merito.md` y el avance con fecha en `ESTADO.md`. Las secciones 1-5 de
> abajo son el plan tal como se aprobó; la **§8 (al final) registra lo que
> realmente se construyó y en qué se desvió del plan**. Ante cualquier
> discrepancia, mandan la §8 y el código.
>
> Regla transversal: **cambios mínimos y localizados**. Reutilizar lo existente
> (`calcularFilasRanking`, `aplicarDesempate`, el cierre que ya congela el snapshot,
> la compuerta `periodos_publicacion`, `omisiones_criterio`). NO romper: B1 ya
> congelado (528, caso especial), la Fase A (filtro por tipo), el candado 046, ni la
> boleta/SIAGIE.

## 1. Objetivo

Reglas de comparación más justas (solo notas bloqueadas, alerta de evaluación
incompleta con gestión de motivos), Ética y Valores en el promedio de secundaria, y
**publicación unificada de boletas + orden de mérito** por nivel. El cierre pasa a
exigir que todo esté resuelto y, como hoy, oficializa (congela) el mérito.

## 2. Decisiones aprobadas (25/07/2026)

| # | Decisión | Detalle |
|---|---|---|
| P1 | **Cascada de desempate** | `promedio_exacto → menos C → menos B → más AD → más 15-16 → más 16 → DESEMPATE MANUAL`. Se quita el apellido; orden de presentación estable por `matricula_id`. |
| P2 | **Cálculo en vivo** | Solo cuenta calificaciones **bloqueadas** (`bloqueos_competencia`, origen `docente` **o** `cierre`). |
| P3 | **Cierre + publicación** | **Cerrar OFICIALIZA** el mérito (congela snapshot, como hoy) pero exige antes: 0 desempates + 0 competencias sin bloquear + 0 alertas de N. **Publicar** (por nivel, fecha/hora) libera **boletas + mérito juntos**. Familias ven **orden de mérito (grado) + ranking por sección**. Reapertura → rectificado. |
| P4 | **Alerta de N desigual** | Alerta por alumno con menos competencias que la **moda de su sección**, tras descontar exoneraciones. Detalla **qué competencias y criterios** están en blanco; se resuelve **registrando el motivo** de cada blanco. Bloquea el **cierre**. Diferencias **entre secciones** (primaria) se **aceptan**. |
| P5 | **Ética y Valores** | Entra al promedio del mérito en **secundaria** (reemplaza a Ed. Religiosa; excepción para C57/área 24). Exonerados compiten con N reducido, sin alerta. Solo mérito. |

El **roster general NO cambia**: sigue `tipo NOT IN ('trasladado','retirado')` + anclaje
de retornos (Fase A). B1 permanece congelado como caso especial.

## 3. Diseño técnico por decisión

### P1 — Cascada de desempate (quirúrgico)
- **`OrdenMeritoModel::rankingGradoLive` / `rankingPorSeccionLive`**: en el `ORDER BY`,
  reemplazar `p.apellido_paterno, p.apellido_materno, p.nombres` por `m.id`.
- `aplicarDesempate` / `tuplaLiteral` **no cambian**: la irreducibilidad ya se decide con
  los 5 conteos (C, B, AD, alto, 16); tras `num_16` ya cae a manual. El apellido hoy solo
  ordena la presentación → pasa a `matricula_id` (neutro, determinista).
- El snapshot congelado no se ve afectado (guarda puestos ya resueltos).

### P2 — Cálculo en vivo = solo bloqueadas
- Añadir a `rankingGradoLive`, `rankingPorSeccionLive` y `calcularFilasRanking`
  (vía sus dos sub-queries) el join:
  ```sql
  INNER JOIN bloqueos_competencia bc
    ON bc.carga_id = cal.carga_id AND bc.competencia_id = cal.competencia_id
   AND bc.periodo_id = cal.periodo_id
  ```
  Sin filtrar `bc.origen` (cuenta docente y cierre).
- Alinear `gradosConRanking` (rama viva) y `gradosConEmpatesPendientes` al mismo universo.
- Efecto: en bimestre **activo** el ranking en vivo solo refleja lo ya bloqueado
  (provisional sobre lo confirmado). En cerrado no cambia (todo bloqueado).

### P5 — Ética y Valores en el promedio (secundaria)
- El filtro `a.tipo NOT IN ('transversal','tutoria')` excluye el área 24 (Ética, C57).
  Cambiar a: excluir transversales siempre, y excluir tutoría **salvo** el área de Ética.
  Punto único: constante/helper `AREA_ETICA_SECUNDARIA = 24` usada por las 3 queries del
  ranking + `getConteosGrado`.
- La Tutoría TOE de primaria (área 23, 0 competencias) no aporta; las transversales
  siguen fuera. Solo C57 entra.
- **Exonerados** (tabla `exoneraciones`, por área): su exoneración ya les quita esa área
  del roster de notas → promedio sobre lo evaluado, sin alerta (P4).
- **No toca** boleta ni SIAGIE (Ética ya sale ahí por su propio camino).

### P4 — Alerta de evaluación incompleta (con gestión de motivos)
- **Definición:** para cada alumno del roster, `N_alumno` = competencias con nota
  bloqueada; `N_seccion` = moda de competencias evaluadas en su **sección**;
  `exoneradas_alumno` = competencias de áreas exoneradas. Hay alerta si
  `N_alumno < N_seccion - exoneradas_alumno` (le faltan notas NO justificadas por exoneración).
- **Comparación por SECCIÓN** (respeta P4a: las secciones difieren legítimamente).
- **Detalle:** la alerta lista **qué competencias y qué criterios** están en blanco por
  alumno (no solo el conteo).
- **Resolución = registrar el motivo** de cada blanco, reutilizando **`omisiones_criterio`**
  (enum `ausencia_injustificada/justificada/abandono/no_aplico`). La alerta se satisface
  cuando todo blanco tiene motivo. *A decidir en la fase:* si una competencia entera sin
  ningún criterio evaluado se cubre marcando cada criterio, o requiere un motivo a nivel
  competencia (posible migración menor).
- **Nuevo método** `OrdenMeritoModel::alertasEvaluacionIncompleta(int $periodoId, ?int $nivelId)`
  → alumnos en alerta con su detalle de competencias/criterios en blanco sin motivo.
- **Chequeo en `ControlOperativoController::index`**: nuevo item junto a "empates" y
  "competencias sin bloquear", con enlace a la gestión de motivos.

### P3 — Cierre que oficializa + publicación unificada

**Cierre (global) — refuerzo de prerequisitos.** `PeriodoController::cerrar` **mantiene**
su `registrarRanking` (ya congela el snapshot). Se **añade** a su validación previa
(donde hoy corre `gradosConEmpatesPendientes`):
- competencias sin bloquear = 0 (reusar `ControlOperativoModel::competenciasSinBloquear`),
- alertas de N sin motivo = 0 (`alertasEvaluacionIncompleta`).
Si algo falta, aborta el cierre con el detalle. **No hay tabla ni acción de "aprobar
mérito" separada: el cierre la absorbe.** El candado 046 **no cambia** (sigue con
`fuePublicado`; reabrir tras publicar → rectificado; reabrir antes de publicar → se
regenera el oficial al re-cerrar, como hoy).

**Publicación unificada (por nivel).** La compuerta `periodos_publicacion` (044) pasa a
gobernar la visibilidad de **boletas Y orden de mérito** juntas:
- **`ControlOperativoController::publicar/programar/despublicar`**: sin cambios de
  mecánica; el mismo acto ahora también gobierna el mérito (no requiere código nuevo en
  el modelo de publicación, solo que los lectores del mérito consulten la compuerta).
- **Docente/claustro** (`Docente\OrdenMeritoController`): el gate pasa de
  `estado='cerrado'` a **"periodo publicado para el nivel"** (`periodosPublicados`).
- **Familias:** nuevas superficies `/padre/orden-merito` (ranking por **grado**) y
  `/padre/ranking-seccion` (por **sección**), visibles solo si el periodo está publicado
  para el nivel del hijo. Reusan `rankingGrado`/`rankingPorSeccion` + `periodosPublicados`.
  Enlace desde `/padre/notas`.

**El snapshot se genera GLOBAL al cerrar** (como hoy); la compuerta **por nivel** filtra
solo la *visibilidad*. No hace falta parametrizar `generarSnapshot` por nivel.

## 4. Detalles finos — RESUELTOS (25/07/2026)

1. **Alerta de N:** detalla competencias/criterios en blanco + **gestión de motivos** vía
   `omisiones_criterio`. (Reemplaza el "aceptar con motivo" a nivel alumno.)
2. **Punto de bloqueo:** las alertas (y desempates y bloqueos) se resuelven **antes de
   CERRAR**; el cierre aborta si algo falta. Cerrar oficializa el mérito.
3. **Vista de familias:** **orden de mérito (grado) + ranking por sección**, páginas
   propias bajo `/padre`, enlazadas desde `/padre/notas`, bajo la compuerta de publicación.
4. **Ética técnica:** excepción por área (`AREA_ETICA_SECUNDARIA=24`), no por `tipo`.
   Candado 046 sin cambios.

## 5. Plan de fases (cada una verificable y aislada)

- **Fase 1 — Cascada + cálculo en vivo (P1, P2).** Solo `OrdenMeritoModel` (ORDER BY +
  join bloqueos). Verificar con scripts en `database/verificaciones/` (B1 sin cambios de
  puestos; en vivo cuenta solo bloqueadas). Bajo riesgo.
- **Fase 2 — Ética en el mérito (P5).** Excepción de filtro (área 24/C57) en las 3 queries
  + `getConteosGrado`. Verificar N y promedios de secundaria; exonerados sin alerta.
- **Fase 3 — Alerta de N + gestión de motivos (P4).** `alertasEvaluacionIncompleta` con
  detalle competencia/criterio + UI de motivos (`omisiones_criterio`) + chequeo en Centro
  de control. Decidir aquí si hace falta motivo a nivel competencia (posible migración menor).
- **Fase 4 — Cierre reforzado (P3, parte 1).** `PeriodoController::cerrar` valida alertas
  de N + competencias sin bloquear (además de desempates) antes de congelar. Sin migración.
- **Fase 5 — Publicación unificada (P3, parte 2).** El mérito se vuelve visible bajo la
  compuerta 044; gate del claustro pasa a "publicado".
- **Fase 6 — Vista de familias (P3, parte 3).** `/padre/orden-merito` + `/padre/ranking-seccion`
  (ranking del grado y por sección) bajo la compuerta.

Dependencias: F4 depende de F3 (la alerta es prerrequisito del cierre); F5 y F6 usan el
snapshot ya existente y la compuerta. F1, F2 son independientes y de menor riesgo → primero.

## 6. Invariantes nuevos (a agregar a CLAUDE.md al implementar)

- **Cerrar exige orden de mérito íntegro:** 0 desempates + 0 competencias sin bloquear +
  0 alertas de N. El cierre **oficializa** (congela) el snapshot; no hay acto separado.
- **Publicar libera boletas Y orden de mérito juntos**, por nivel, bajo la compuerta 044.
  Claustro y familias ven el mérito solo cuando está publicado para su nivel.
- **Mérito en vivo cuenta solo competencias bloqueadas.** Ética (C57/área 24) cuenta en
  secundaria; exonerados compiten con su N reducido sin alerta.
- **Alerta de N = evaluación incompleta por sección** (menos competencias que la moda,
  descontando exoneradas), resuelta registrando el motivo de cada blanco (`omisiones_criterio`).

## 7. Riesgos y compatibilidad (del plan original)
- **B1 congelado (528):** el cierre no se re-ejecuta sobre B1; su snapshot no se toca. NO
  correr `backfill` en prod (revertiría a 519).
- **Candado 046 intacto:** al no separar la aprobación, no hay que re-anclarlo; sigue con
  `fuePublicado`. Menos superficie de cambio, menos riesgo.
- **Orden de bloqueo vs alerta:** el cierre fuerza el bloqueo de competencias pendientes;
  por eso la alerta de N debe evaluarse sobre lo que el docente ya trabajó y la resolución
  (motivos) ocurre en el Centro de control **antes** de cerrar. Precisar la UX en la Fase 3.
- **Sin migración mayor:** se eliminó la tabla `orden_merito_aprobacion`. Solo una posible
  migración menor en la Fase 3 si se opta por motivo a nivel competencia.

---

## 8. LO QUE SE IMPLEMENTÓ (25-26/07/2026) — manda esta sección

Desplegado (a más tardar en la v1.0.1, 08/09/2026; corregido el 22/09 — la cabecera se quedó en «dev» al desplegar), sin migración nueva. Un commit por fase; los scripts de verificación
viven en `database/verificaciones/`.

| Fase | Commit | Qué quedó |
|---|---|---|
| F1 cascada + en vivo | `b316df1` | `ORDER BY … m.id` (sin apellidos) y `INNER JOIN bloqueos_competencia` en las 4 queries del cálculo en vivo. Verif: `verif_fase1_rediseno_merito.php` |
| F2 Ética | `e7ba896` | Constante `AREA_ETICA_NOMBRE_BOLETA` en `helpers.php` + excepción en las 2 queries del ranking en vivo |
| F3 alerta de N | `1289c69` | `ControlOperativoModel::alertasEvaluacionIncompleta` + chequeo en `/admin/control` |
| F4 cierre reforzado | `04e555f` | `PeriodoController::cerrar` aborta si hay evaluación incompleta |
| F5 publicación unificada | `da37350` | Gate del claustro = compuerta 044 |
| **F5b candado de reapertura** | `07c308f` | **No estaba en el plan.** Ver abajo |
| F6 vista de familias | `dbba287` | `/padre/orden-merito` y `/padre/ranking-seccion` |
| Fixes posteriores | `af72ac7`, `6a08ccd`, `ac7a105` | Ver abajo |

### Desviaciones respecto del plan

1. **F2 identifica Ética por `nombre_boleta`, no por id.** El plan decía
   `AREA_ETICA_SECUNDARIA = 24`; el id del área puede diferir entre entornos, así que
   la constante es `AREA_ETICA_NOMBRE_BOLETA = 'Ética y Valores'` (la migración 035 lo
   sella idéntico en local y prod).
2. **F3 no compara "moda de competencias por sección".** Resultó más preciso comparar
   por CRITERIO: un criterio que otros compañeros de la sección sí tienen con nota y al
   alumno le falta, sin omisión ni exoneración. Así la alerta ya nace con el detalle
   competencia+criterio que el plan pedía, sin necesidad de una migración nueva.
   La gestión de motivos usa el flujo de `omisiones_criterio` que ya existía.
3. **F4 NO valida "0 competencias sin bloquear".** El plan (P3) lo exigía; se omitió a
   propósito porque el cierre las fuerza mecánicamente (y ese camino maneja las
   transversales). Lo que sí exige acción humana previa —registrar la nota o la
   omisión— es lo que se validó. **Queda como diferencia consciente con la §6.**
4. **F5b (nueva).** Al reabrir un bimestre publicado su estado deja de ser `'cerrado'`,
   así que `debeUsarSnapshot` caía al cálculo en vivo y el claustro veía un documento
   distinto del entregado (en local B1: 520 alumnos contra las 528 del oficial). Ahora
   manda el snapshot si el periodo está cerrado **o** si ya fue publicado
   (`fuePublicado`, migración 046), memoizado por periodo. Obligatorio junto con esto:
   `gradosConEmpatesPendientes` pasó a `rankingGradoLive`, porque si no, al RE-cerrar
   leería el snapshot viejo y no vería los empates que introdujeron las rectificaciones.
   Verif: `verif_fase5b_rediseno_merito.php`.
5. **Alerta informativa en bimestres cerrados** (`af72ac7`). Es crítica y bloquea
   mientras el bimestre está abierto; una vez cerrado el documento ya salió, así que
   baja a `'informativo'` y deja de sumar al contador de incidencias (si no, B1 mostraba
   13 casos críticos para siempre). En la misma tanda: la alerta filtra
   `ca.estado = 'activa'`, como el resto del sistema — una carga dada de baja conserva
   sus criterios y generaba alertas que nadie podía resolver.
6. **Retorno de grado en las superficies de familias** (`6a08ccd`, `ac7a105`).
   `getHijo` devuelve siempre la matrícula OFICIAL, pero el alumno compite con la
   OPERATIVA. `PanelController::contextoMerito` recorre las fuentes de `boletaContexto`
   y se queda con la que aparece en el ranking; los rótulos usan ESE grado/sección
   (integridad: no mezclar "2°" sobre una tabla de 1°). En `/padre/notas`, además, se
   indexa por `competencia_id` (con retorno llegaban 44 filas en vez de 22), gana la
   matrícula operativa y se descartan los criterios sin nota.
7. **Familias ven la lista completa**, con nombre y promedio de cada alumno, igual que
   el claustro (decisión del usuario 26/07 sobre la alternativa de mostrar solo el
   puesto del hijo). El ranking por sección muestra solo la sección del hijo.

### Efectos colaterales aceptados

- Durante la reapertura de un bimestre publicado, **el director también ve el oficial
  congelado** en `/director/orden-merito`. El efecto de las correcciones se observa al
  re-cerrar, en la versión rectificada de `/admin/control`.
- El filtro de criterios sin nota de `/padre/notas` aplica a todos los alumnos, no solo
  a los de retorno. Medido antes de aplicarlo: 0,51% de los criterios (30 de 5877 en una
  muestra de 60 alumnos) y ninguna competencia que hoy tenga tabla se queda sin ella.

### Scripts destructivos asegurados en la misma sesión

- `verif_fase_b_orden_merito.php` **borraba el snapshot oficial de B1** con un DELETE
  ciego (su paso 4 "autolimpieza", escrito el 24/07 cuando la tabla estaba vacía y
  obsoleto al día siguiente con la Fase C). Ahora corre en transacción con rollback,
  aborta si detecta el entorno de producción y reproduce el escenario "sin oficial"
  dentro de la transacción — antes su primera aserción estaba en letra muerta.
- `backfill_orden_merito.php` salta los periodos con snapshot oficial YA PUBLICADO
  (candado 046) salvo `--forzar`. Protege el 528 de B1 en cualquier entorno sin romper
  su uso legítimo de setup.
