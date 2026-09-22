# PLAN — Registro retroactivo de calificaciones en bimestres cerrados

> # ⛔ PLAN DEROGADO Y SUSTITUIDO EL 10/09/2026 — NO IMPLEMENTAR
>
> **Se conserva por su análisis, que sigue siendo válido y caro de rehacer** (la medida
> de los 45 usos de `nota_numerica`, el universo de los 6 casos, el estado de la
> extraordinaria). **Lo que NO vale ya es su diseño.**
>
> **Qué lo derogó:** el colegio precisó la regla y partió en DOS lo que este plan trataba
> como una sola cosa, con destinos **opuestos**:
>
> | | Notas del **colegio de origen** | Notas **nuestras** no registradas |
> |---|---|---|
> | ¿Van a la boleta? | **NO** — el Informe de Progreso del COCIAP lleva solo lo cursado aquí | **SÍ**, como cualquier nota |
> | ¿Quién las ve? | solo el **docente** con carga en su sección | familia, boleta y SIAGIE |
> | Escala | **literal** puro | **numérica** 00-20, literal derivado |
> | Dónde vive | `notas_externas` (+ `area_id`, migración **`057`**) | `calificaciones`, vía calificación **extraordinaria** |
> | Doc | `matriculas.md` | `calificaciones.md` |
>
> **Decisiones de este plan que caen:**
> - **D2** (literal puro con numeral en guion) → el caso 2 es numérico; el caso 1 no tiene celda de boleta.
> - **D4** (nota al pie «calificaciones convalidadas del colegio de origen») → **muere**: nada convalidado llega a la boleta.
> - **D6** (unificar extraordinaria + retroactivo y retirar el flujo de la extraordinaria) → **innecesaria**: bajo la partición, la extraordinaria **es** el caso 2. Se le añadió captura en LOTE y se dejó el motor intacto.
> - **D7** (`notas_externas` desaparece) → **invertida**: su esquema es exactamente el caso 1, y que la boleta no la lea —lo que este plan llamó «mecanismo muerto»— resulta ser **el comportamiento pedido**.
> - **§7.1 (¿van al SIAGIE?)** → **se cierra sola**: las de origen no son nuestras y no van al acta; las del caso 2 ya se exportan.
> - **`calificaciones_retroactivas` NO se construye**, y con ella la migración **`049` queda liberada** (hueco permanente; las nuevas fueron la `057` y la `058`).
>
> **Lo que SÍ sobrevivió, y sigue en producción:** la **F1** (asistencia de un bimestre sin
> registro en guion, desplegada el 07/08/2026) es independiente de todo esto y no se toca.
>
> **Dónde está ahora la funcionalidad:** `docs/modulos/matriculas.md` (notas del colegio de
> origen), `docs/modulos/calificaciones.md` (extraordinaria en lote) y
> `docs/modulos/notificaciones.md` (el aviso al docente).

---

> **Estado original: PLAN DE IMPLEMENTACIÓN LISTO, SIN IMPLEMENTAR** (05/08/2026). Escrito para
> retomarse en frío. **Empezar por §6 F0**, que es bloqueante y solo lectura.
> Decisiones cerradas en §4; lo único abierto (SIAGIE) en §7, y no bloquea F1-F3.
> Módulos relacionados: `calificaciones.md`, `boletas.md`, `matriculas.md`,
> `orden-merito.md`, `export-siagie.md`.

## 1. Qué se pide

Un estudiante puede llegar al colegio con uno o más bimestres ya **cerrados**. Hoy su
boleta muestra esos bimestres vacíos y no hay forma de incorporar lo que trae del colegio
de origen. Se pide poder **registrar calificaciones literales por competencia en
bimestres cerrados**, con estas condiciones del usuario:

- **Literales** (AD/A/B/C), porque es lo que entrega el colegio de origen.
- **Libres por competencia**: el docente de origen pudo trabajar competencias distintas
  de las que trabajó el nuestro, así que se llenan solo las que correspondan.
- **Para cualquier estudiante sin calificaciones en un bimestre cerrado**, no solo para
  nuevos o trasladados.
- **Con motivo.**

## 2. Evidencia medida (05/08/2026, datos reales)

**El caso existe hoy: 6 estudiantes** con notas de B2 y ninguna de B1.

| Matrícula | Alumno | Grado | Registro | `tipo` |
|---|---|---|---|---|
| 690 | ÑIQUEN XOANA | 4.º A | 08/06 | nuevo |
| 691 | RAMIREZ ITZEL | 5.º B | 08/06 | **continuador** |
| 693 | RIMAC AZAHI | 5.º B | 30/06 | nuevo |
| 694 | SANTAMARIA JAKELINE | 3.º A | 02/07 | nuevo |
| 695 | GONZALEZ JEANFRANCO | 5.º B | 13/07 | **continuador** |
| 696 | MORALES YEREMI | 3.º A | 13/07 | **continuador** |

⚠️ **`matriculas.tipo` NO sirve para detectarlos**: la mitad figura como `continuador`.
El anclaje fiable es **la ausencia de notas en el periodo cerrado**, que además es
exactamente el criterio que pidió el usuario ("cualquier estudiante sin calificaciones").

⚠️ **No confundir con el lote `traslado_entrada` del 19/05**: ~180 matrículas llevan ese
`tipo_matricula` pero tienen B1 completo. Son alumnos de siempre con el flag mal puesto
(la migración `038` corrigió 6 de ellas). **No son casos de este plan.**

### Qué hace hoy el sistema con ellos (medido con los modelos reales)

| Aspecto | Comportamiento | ¿Correcto? |
|---|---|---|
| Notas de B1 en la boleta | columna completa con guiones | ✅ (desde el 05/08) |
| Orden de mérito B2 | compiten normal (puestos 46, 32, 40, 44, 22, 43) | ✅ es por bimestre |
| Snapshot oficial de B1 | los 6 fuera | ✅ |
| Guard del cierre (`alertasEvaluacionIncompleta` B2) | **0 alertas** | ✅ no bloquean |
| Conducta de B1 | guion | ✅ |
| **Asistencia de B1** | **imprime `0 faltas` como cifra** | 🔴 **dato FALSO** |
| `notas_externas` | tabla vacía, UI existente, **la boleta no la lee** | 🟠 mecanismo muerto |

## 3. Lo que YA está resuelto (y por qué no basta)

**La CALIFICACIÓN EXTRAORDINARIA (migración `042`, en producción desde el 16/07) cubre
más de lo que parecía.** Ver `calificaciones.md` §"Calificación extraordinaria". Permite a
admin/RA registrar nota en una competencia **de bimestre cerrado**, a un alumno **sin nota
previa**, con **motivo obligatorio**, y la nota va a boleta y SIAGIE pero **no** al orden
de mérito.

Probado contra los casos reales: `getCompetenciasInsertables` ofrece **26 de 27**
competencias (primaria 4.º A), **28 de 29** (secundaria 3.º A) y **26 de 27** (5.º B).
La diferencia son las **transversales**, excluidas a propósito (van por la agregación del
tutor).

O sea: **el punto 4 del pedido —"cualquier estudiante sin calificaciones", con motivo— ya
está implementado y desplegado.** Lo que falta es lo demás:

| Falta | Por qué |
|---|---|
| **Literal** | La extraordinaria pide un numeral 00-20. El colegio de origen entrega literales. |
| **Captura en lote** | El alta es de UNA competencia por vez (`?matricula=&carga=&competencia=&periodo=`). Un alumno que llega necesita **26-28 pasadas** por el formulario. |
| **Trazabilidad del origen** | La nota entra como propia; no queda registro de que se cursó en otra institución. |

## 4. Decisiones cerradas (no re-preguntar)

| # | Decisión |
|---|---|
| D1 | **Asistencia de un bimestre no cursado → GUION**, nunca `0`. Mismo criterio ya aceptado para los bimestres sin registrar. |
| D2 | **Literal puro; el numeral va en guion.** No se inventa un número: nadie certificó que una "A" de otro colegio sea 16. En primaria no cambia nada (solo muestra literal); en secundaria la columna N.Num. sale con guion. |
| D3 | **Captura en GRILLA por alumno y bimestre**: una pantalla con todas las competencias del plan de su sección, se llenan solo las que correspondan y el resto quedan vacías. Un motivo común para toda la carga. |
| D4 | **La boleta lo declara**: nota al pie del tipo "I Bimestre: calificaciones convalidadas del colegio de origen". Sin eso, el guion no distingue "no estaba" de "no le calificaron". |
| D5 | Se pide **motivo**, y el universo es **cualquier alumno sin calificaciones en el bimestre cerrado** (no solo nuevos/trasladados). |
| D6 | **La calificación EXTRAORDINARIA y el registro retroactivo SE UNIFICAN** en un solo mecanismo y un solo punto de entrada. No pueden convivir dos caminos para "alumno sin nota en bimestre cerrado". |
| D7 | **`notas_externas` desaparece**: su función la absorbe este proceso. Está vacía y su UI nunca llegó a la boleta, así que no hay datos que migrar. |
| D8 | **Las competencias TRANSVERSALES se registran OBLIGATORIAMENTE** en la grilla, igual que las demás. |
| D9 | **Conducta y asistencia del bimestre convalidado: OPCIONALES.** Se pueden registrar, pero no se exigen para dar por completa la carga. ⚠️ Confirmar al implementar esa fase si "opcional" significa *campo no obligatorio en la grilla* (lectura asumida) o *funcionalidad diferida*. |

### Estado de partida de la unificación (medido el 05/08/2026, LOCAL)

```
calificaciones extraordinarias ....... 0
criterios extraordinarios ............ 0
rectificaciones tipo extraordinaria .. 0
notas_externas ....................... 0
notas_autorizadas_siagie ............. 0
```

**Ningún mecanismo tiene datos**, así que la unificación no arrastra migración de
información. 🔴 **VERIFICAR ESTAS CINCO CIFRAS EN PRODUCCIÓN antes de tocar nada**: si en
prod hubiera extraordinarias registradas, habría que migrarlas al modelo nuevo en la misma
migración, no después.

## 5. Arquitectura: dónde viven estas notas

**Decisión de diseño propuesta: TABLA APARTE que la boleta une al leer. NO filas en
`calificaciones` con `nota_numerica NULL`.**

Medido: `nota_numerica` se usa en **45 lugares de 11 archivos**, y **26 de esos usos son
promedios, umbrales o desempates** (`AVG`, `>= NOTA_MIN_AD`, `BETWEEN`…). Un `NULL` ahí no
falla: **cambia el resultado en silencio** — altera denominadores de promedios, y las
comparaciones con NULL dan falso sin avisar. Contaminaría orden de mérito, promedios de
área, alertas del cierre y export.

**Precedente exacto del proyecto:** `notas_autorizadas_siagie` (migración `040`) ya es una
tabla paralela para notas que **no** deben tocar `calificaciones`. Y el **retorno de
grado** ya probó el patrón de "varias fuentes unidas al leer" (`boletaContexto`).

### El modelo unificado (D6)

Una sola tabla y un solo punto de entrada, con la nota **literal siempre** y el **numeral
opcional**. Así los dos casos caben sin `NULL` en `calificaciones`:

| Caso | `nota_literal` | `nota_numerica` | En la boleta |
|---|---|---|---|
| Viene de otro colegio (o bimestre no cursado) | AD/A/B/C | **NULL** | `— / A` (D2) |
| Evaluación real de NUESTRO colegio que no se registró (lo que hoy hace la extraordinaria) | derivado | 00-20 | `16 / A` |

El flujo actual de la extraordinaria (criterio `extraordinario` + fila en `calificaciones`)
**se retira como punto de entrada**: la grilla pasa a ser el único. Lo que hoy vive en
`CriterioModel::obtenerOCrearExtraordinario`, `RectificacionController@extraordinaria` y
sus guardas queda obsoleto — retirarlo o dejarlo dormido es parte de F2.

⚠️ **Queda un tercer mecanismo fuera de esta unificación, a propósito:**
`notas_autorizadas_siagie` (migración `040`), que existe **solo para el acta SIAGIE** y no
toca la boleta. Se decide junto con la pregunta del SIAGIE (§7.1, diferida por el
usuario). Si esa respuesta es "sí van al acta", los tres mecanismos deberían quedar en uno.

## 6. PLAN DE IMPLEMENTACIÓN

> Orden pensado para que **ningún paso intermedio deje el sistema roto** y para que lo que
> ya tiene valor se pueda desplegar sin esperar al resto. F1 es independiente: no depende
> de la migración ni de la grilla.

### F0 · Verificación previa en PRODUCCIÓN (bloqueante, solo lectura)

Antes de escribir una línea, correr en prod:

```sql
SELECT 'extraordinarias',    COUNT(*) FROM calificaciones WHERE extraordinaria = 1
UNION ALL SELECT 'criterios_extra',  COUNT(*) FROM criterios WHERE extraordinario = 1
UNION ALL SELECT 'rect_extra',       COUNT(*) FROM rectificaciones_calificacion WHERE tipo = 'extraordinaria'
UNION ALL SELECT 'notas_externas',   COUNT(*) FROM notas_externas
UNION ALL SELECT 'notas_autoriz',    COUNT(*) FROM notas_autorizadas_siagie;
```

- **Todo en 0** → el plan procede tal cual.
- **Alguna > 0** → **PARAR**. Hay que añadir a la migración el traslado de esas filas al
  modelo nuevo, y `DROP TABLE notas_externas` deja de ser seguro.

### F1 · Asistencia con guion — ✅ **EN PRODUCCIÓN desde el 07/08/2026** (deploy `c8fa4fd`), SIN MIGRACIÓN

> Se hizo tal cual el plan (los dos archivos, el método por unión, la vista intacta).
> Resultado y cifras en **`docs/modulos/boletas.md`**, sección "El tercer motivo: sin FILA
> no es lo mismo que en CERO". Verificación:
> `database/verificaciones/verif_asistencia_sin_registro.php`.
>
> **Universo medido: 18 pares sin fila, de los que la unión neutraliza 2 → 16 celdas pasan
> de `0` a guion** (6 que llegaron tarde en B1 + 10 trasladados/retirados en B2). El Total
> anual no se movió y las 40 filas de muestra "en cero real" conservaron su `0`.
>
> ⚠️ **Lo que el plan no anticipaba:** `guardar()` es un upsert **AJAX fila por fila**, así
> que la fila solo existe si alguien tocó a ese alumno, y el cierre de sección **no exige
> completitud**. El efecto colateral por tanto NO era deducible del código: hubo que
> medirlo. Salió acotado, pero la próxima fase que dependa de "hay fila" debe medir igual.

**Contenido original del plan:**

**Problema:** la boleta imprime `0 faltas` en un bimestre que el alumno no cursó.
`AsistenciaModel::getDelBimestre` devuelve ceros cuando **no hay fila**, y `armar()` no
distingue ese caso del cero real.

| Archivo | Cambio |
|---|---|
| `app/Models/AsistenciaModel.php` | método nuevo `tieneRegistroUnion(array $ids, int $periodoId): bool` (`EXISTS` sobre `inasistencias`) |
| `app/Models/BoletaModel.php` | en el loop de asistencia, `$sinRegistro` suma `|| !$this->asistenciaModel->tieneRegistroUnion($fuentes, $pa['id'])` |

La vista **no se toca**: ya pinta guion cuando `sin_registro` es `true`.

⚠️ **Medir antes el efecto colateral:** el guion aparecerá también donde una sección
entera se quedó sin registrar (hoy imprime ceros igual de falsos). Es deseable, pero hay
que saber a cuántas boletas afecta antes de desplegarlo.
**Verificación:** los 6 casos pasan a guion en B1; un alumno con registro real conserva
sus cifras; el Total anual no cambia (ya sumaba solo bimestres con registro).

### F2 · Modelo de datos unificado — *migración `049`*

```sql
CREATE TABLE IF NOT EXISTS calificaciones_retroactivas (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matricula_id   INT UNSIGNED     NOT NULL,
    periodo_id     SMALLINT UNSIGNED NOT NULL,
    competencia_id SMALLINT UNSIGNED NOT NULL,
    nota_literal   ENUM('AD','A','B','C') NOT NULL,   -- SIEMPRE
    nota_numerica  TINYINT UNSIGNED NULL,             -- NULL = viene de otro colegio
    motivo         VARCHAR(50)      NOT NULL,
    colegio_origen VARCHAR(200)     NULL,
    observacion    TEXT             NULL,
    registrado_por INT UNSIGNED     NOT NULL,
    registrado_en  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_retro (matricula_id, periodo_id, competencia_id),
    KEY idx_periodo (periodo_id)
);
DROP TABLE IF EXISTS notas_externas;   -- D7, verificada vacía en F0
```

- **Idempotente** (`IF NOT EXISTS` / `IF EXISTS`), como las anteriores.
- **`motivo`**: lista cerrada en PHP (constante tipo `MOTIVOS`, patrón de
  `OmisionCriterioModel`), no ENUM en BD — así añadir un motivo no exige migración.
  Arranca con `ingreso_posterior`, `convalidacion_traslado`, `no_registrado_a_tiempo`.
- **Retirada de la extraordinaria (D6): DORMIR, NO BORRAR.** Se comentan sus 2 rutas en
  `routes/web.php` (patrón ya usado con las boletas públicas) y se quitan sus enlaces de
  la UI. El código de `CriterioModel`/`RectificacionController` **se conserva intacto**:
  borrarlo tocaría el panel del docente, la consulta de notas y el SIAGIE, que hoy
  funcionan. Se documenta como dormido.
- Modelo nuevo `app/Models/CalificacionRetroactivaModel.php`.
- También se retira la UI de `notas_externas`: rutas `/matriculas/{id}/notas-externas`,
  `MatriculaController::notasExternas`/`storeNotasExternas`, `getNotasExternas`,
  `registrarNotaExterna` y la vista `matriculas/notas-externas.php`.

### F3 · Captura en grilla (admin/RA)

- Rutas: `GET|POST /matriculas/{id}/notas-retroactivas` (+ `?periodo=`), junto a las
  demás de matrícula. `requireRole(['admin','registro_academico'])` + `validateCsrf` en
  el POST.
- Entrada desde la **ficha de matrícula** (`matriculas/show.php`), donde hoy está el
  enlace a notas externas.
- La grilla se arma con **`CalificacionModel::estructuraCompetenciasSeccion()`** —ya
  existe, ya agrupa por área y **ya incluye transversales** (D8)—, filtrando las
  competencias que **sí** tienen nota en `calificaciones`.
- Un `<select>` AD/A/B/C por competencia (vacío = no se registra), más motivo, colegio de
  origen y observación para toda la carga. Guardado en **una transacción**.
- **Guards (re-chequeo en el POST, no solo en la vista):** periodo **cerrado**;
  competencia **sin nota** en `calificaciones`; competencia **del plan de su sección**;
  alumno **no exonerado** del área; matrícula del año activo.

⚠️ **Las transversales exigen camino propio.** Hoy `getCompetenciasInsertables` las
excluye porque una fila cruda en `calificaciones` no llega a la boleta: se muestran
**agregadas** desde el cierre del tutor (`getTransversalesAgregadas`, que promedia cargas
bloqueadas y exige `cierres_transversales` vigente). Un alumno que llega no tiene nada que
promediar. **El modelo de F2 lo resuelve solo**: al unirse en la lectura, la nota
transversal entra directa sin pasar por la agregación — por eso D8 es viable.
🔴 **Guarda obligatoria:** si el alumno ya tiene fila agregada de esa transversal, la
retroactiva **no** debe duplicarla (gana la agregada, que es la evaluación real).

### F4 · Lectura en la boleta

- `BoletaModel::armar` incorpora la fuente nueva **después** de las notas reales y
  **antes** de las exoneraciones, superponiéndola sobre el esqueleto del plan (mismo
  mecanismo que ya usan las notas del retorno).
- Celda: `nota` = `nota_numerica` (puede ser `null` → la vista ya pinta guion, hecho el
  05/08) y `literal` = `nota_literal`. **No** se deriva el numeral del literal (D2).
- **Nota al pie (D4)** cuando la boleta contenga alguna retroactiva: texto por bimestre,
  del tipo *"I Bimestre: calificaciones convalidadas del colegio de origen"*.
- Se aplica igual a la **boleta digital** (mismo `$areas`).
- ⚠️ Resolver **§7.1 (SIAGIE) antes de esta fase**: si esas notas no deben ir al acta, el
  export tiene que ignorar la fuente nueva explícitamente, y eso se decide aquí.

### F5 · Guards, mérito y verificación

- Confirmar con verificación que la fuente nueva **no** entra al orden de mérito (el
  snapshot de un bimestre publicado es inmutable por el candado `046`) ni mueve
  `alertasEvaluacionIncompleta` ni el conteo de aprobables del lote de boletas.
- Script `database/verificaciones/verif_notas_retroactivas.php` (solo lectura, corre en
  prod) con los bloques de §9.

### Orden de commits previsto

1. `feat(boleta): la asistencia de un bimestre sin registro sale en guion` (F1)
2. `feat(db): migracion 049 …` (F2, tabla + drop)
3. `feat(notas): registro retroactivo de calificaciones en bimestres cerrados` (F3)
4. `feat(boleta): la boleta muestra las calificaciones retroactivas` (F4)
5. `test(verificaciones): …` (F5) + `docs(notas): …`

### Qué se puede desplegar por separado

**F1 sola es desplegable** y corrige un dato falso que hoy se imprime. F2+F3 sin F4 dejan
el registro guardado pero invisible: **no desplegar F3 sin F4**, o RA registrará notas que
no aparecen en ningún documento.

## 7. Preguntas abiertas

**Cerradas el 05/08/2026** (ver D6-D9): unificar extraordinaria + retroactivo; eliminar
`notas_externas`; transversales obligatorias; conducta y asistencia opcionales.

1. 🔶 **¿Estas notas van al SIAGIE? — DIFERIDA por el usuario ("lo analizamos después").**
   El alumno no estaba matriculado aquí en ese bimestre, así que el acta probablemente no
   debe llevarlas. Afecta a `SiagieExportModel` y decide de paso si
   `notas_autorizadas_siagie` se absorbe en la unificación o sobrevive aparte.
   **No bloquea F1 ni F2**: la tabla nace sin exportarse y añadir el export después es
   aditivo. Sí conviene resolverla **antes de F4**, para no rehacer la lectura.
2. **¿"Opcional" en conducta y asistencia (D9) es campo no obligatorio o fase diferida?**
   Se asume lo primero; confirmar al llegar a esa fase.

## 8. Riesgos e invariantes en juego

- **`nota_numerica` NULL en `calificaciones`: prohibido** (26 usos silenciosamente
  afectados). Es la razón de la tabla aparte.
- **Snapshot de mérito inmutable** (candado `046`): registrar notas de B1 **no** puede
  regenerar el ranking de B1, que ya está publicado.
- **Fila en `calificaciones` ⟺ nota viva** (invariante de CLAUDE.md): la tabla nueva no
  debe romperlo escribiendo ahí.
- **Boleta = solo competencias bloqueadas**: la fuente nueva entra por un camino distinto
  y hay que asegurar que no se cuela en el conteo de aprobables ni en el lote de boletas.
- El **guion de D2** en el numeral convive con el guion de "sin dato" introducido el
  05/08: en secundaria una fila convalidada se verá `— / A`, que es justo lo que se busca.

## 9. Verificación prevista

`database/verificaciones/verif_notas_retroactivas.php` (solo lectura, corre en prod),
sobre los 6 casos reales:

| # | Comprueba |
|---|---|
| 1 | La **asistencia** de un bimestre sin registro sale en **guion**, y un alumno con registro real conserva sus cifras (F1). |
| 2 | La boleta muestra el **literal** de las retroactivas y el **numeral en guion** cuando no hay número (D2). |
| 3 | **Ninguna nota real desaparece** ni se duplica al unir la fuente nueva (equivalencia, como en `verif_retorno_grado.php`). |
| 4 | El **ranking del bimestre cerrado no cambia ni una posición**, y el snapshot oficial sigue intacto (candado `046`). |
| 5 | `alertasEvaluacionIncompleta` **sigue en 0** y el conteo de aprobables del lote de boletas no se mueve. |
| 6 | Una transversal con agregación **no** se duplica con la retroactiva (guarda de F3). |

Además, en cada fase: `php -l` de lo tocado y `npx gulp build` si toca SASS (F3/F4).

## 10. Estado del repo al escribir este plan

`dev` = `62d996c`, **21 commits por delante de `origin/main`** (`de449e2`), árbol limpio.
Ese lote pendiente **no lleva migración**; este plan sí (la `049`), así que al desplegar
habrá que aplicarla a mano en prod **antes** del merge, como se hizo con la `044` y la
`045`. Última migración aplicada: `047`.
