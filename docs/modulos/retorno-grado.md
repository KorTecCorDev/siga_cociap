# Retorno de grado

> Un estudiante asiste a un grado **inferior** al que le corresponde en SIAGIE.
> Se le crea una **matrícula operativa** en el grado destino, vinculada con su
> **matrícula oficial** en `retornos_grado`. Implementado en
> `RetornoGradoController` + `retornos_grado`.
>
> **Desde el 05/10/2026 (migración 073) el retorno guarda su TRAMO y tiene PUNTO ÚNICO de
> consulta: `app/Models/RetornoGradoModel.php`.** **Desplegado el 05/10/2026** (merge
> `c3d1bdc`, 073 aplicada en producción). En producción el retorno #1 se había revertido
> con el código viejo (tramo vacío): se corrige con `database/reparar_retorno_1_tramo.sql`,
> ver `docs/ESTADO.md`.

## El TRAMO y el punto único (05/10/2026, migración 073)

**Cada bimestre vive ENTERO en una sola de las dos matrículas, y cuál es lo dice el tramo
guardado, no se deduce de los datos.** Hasta esa fecha cada consulta deducía «lo cursó la
matrícula que tiene notas en ese periodo», cada una a su manera; al revertir el retorno #1
fallaron en cadena el lote de boletas (2.° B, II: 19 → 18), la situación final (dos filas
en el III), los cuadros (sin las notas del II), la boleta (plan del grado inferior) y las
notas autorizadas SIAGIE. Y los 22 promedios del I copiados por el antiguo `INSERT IGNORE`
hacían creer que el I se cursó en 1.° B.

| Columna | Qué es | Quién la fija |
|---|---|---|
| `periodo_desde_id` (NOT NULL) | primer bimestre cursado en la operativa | `store()`: el primer bimestre **sin cerrar** |
| `periodo_hasta_id` | último bimestre cursado en la operativa | `revertir()`: el último **cerrado** ≥ desde; **NULL** = tramo abierto (activo) o **vacío** (revertido sin completar ninguno) |

```
la operativa cubre el bimestre P  ⟺  P >= desde
     AND (estado = 'activo' OR (hasta IS NOT NULL AND P <= hasta))
```

**Las cuatro preguntas, cada una con su respuesta única** (nunca se escriben a mano):

| Pregunta | PHP | SQL |
|---|---|---|
| ¿Dónde se evalúa HOY? (grillas) | `matriculaDeHoy()` | `roster_evaluacion()` (`helpers.php`) |
| ¿Dónde cursó el bimestre P? (historia, cuadros, mérito, boleta) | `matriculaDelPeriodo()` | `sqlCursoElPeriodo()` · `sqlRosterDelPeriodo()` |
| ¿Con qué matrícula se documenta? | `identidad()` | `matricula_documento()` (`helpers.php`) |
| ¿Qué matrículas componen al estudiante? (existencia) | `fuentes()` | `sqlFuentes()` |

Para la interfaz: `sqlJoinRol()` + `sqlColumnasRol()` (rol `oficial`/`operativa` y estado,
activo **o revertido**), `nombresDelTramo()` y `bloqueoGestion()`.

- **Un retorno por matrícula y año** (decisión del usuario; ya lo imponían los UNIQUE):
  `store()` rechaza un segundo retorno, también si el primero se revirtió, y un retorno
  sobre la operativa.
- **Las copias antiguas del I en la 692 quedan inertes:** el I está fuera del tramo y se
  lee de la 190. No hace falta borrarlas.
- **Relleno de la 073** a partir de los datos PROPIOS de la operativa (criterios, omisiones,
  asistencia y conducta; nunca los promedios a secas, que son lo que el `INSERT IGNORE`
  copió). Retorno #1: desde = II, hasta = II. La migración trae un PREVIEW para correr
  primero en producción.

## REGLA A — dónde vive cada cosa (decidida el 05/08/2026)

| Aspecto | Bimestres **anteriores** al retorno | **Desde** el retorno |
|---|---|---|
| Notas (`calificaciones`, criterios) | matrícula **oficial** | matrícula **operativa** |
| Grilla de calificación, conducta, transversales | sección oficial | sección **operativa** |
| Asistencia | matrícula oficial | matrícula **operativa** |
| Orden de mérito | grado **oficial** | grado **operativo** |
| **Boleta y token público** | **SIEMPRE matrícula oficial** | **SIEMPRE matrícula oficial** |
| **Acompañamiento pedagógico** (informe y banda «Estudiantes en riesgo» del tablero) | **SIEMPRE matrícula oficial** | **SIEMPRE matrícula oficial** |
| **Vistas de SOLO LECTURA de notas** (registro auxiliar, historial del docente, `/consulta-notas`; 28/10/2026) | fila **oficial**, sus datos | fila **oficial**, datos **leídos de la operativa**; la operativa no sale nunca (ver § «Vistas de solo lectura») |

**El acompañamiento pedagógico cuenta por la matrícula OFICIAL (23/09/2026, decisión del usuario:
«el retorno es un proceso interno del colegio»).** No se recalcula nada: las cifras son las
de la fila del mérito, que `statsPorGrado` **reubica** en el grado y la sección oficiales,
con la regla por nivel del nivel **oficial** y para todo retorno, activo o revertido. El
mérito (`mejor`, `peores`, `total`) sigue en el grado operativo, y la franja lo dice:
«Retorno de grado: se evalúa en 1.° B, puesto 42 de 42». Detalle en
`docs/modulos/usuarios-direccion.md` § «Retorno de grado en el informe».

**Principio rector: el vínculo `retornos_grado` es el puntero; los datos NO se
copian ni se mueven entre matrículas.** Cada bimestre queda donde se cursó, y la
boleta lee cada bimestre de la matrícula que lo cursó (`RetornoGradoModel::matriculaDelPeriodo`;
`boletaContexto` → `identidad = oficial`, `evaluacion = matriculaDeHoy`).

Se descartó la alternativa B (recalcular todo el año en el grado operativo):
obligaba a mantener datos duplicados o a que el mérito uniera fuentes, y dejaba
a la estudiante rankeada en un grado con notas obtenidas en otro.

## Vistas de solo lectura: la fila es la OFICIAL (28/10/2026)

> **Estado: implementado en `dev`, sin desplegar.** Sin migración. Pruebas en `docs/ESTADO.md`.

**Decisión del usuario:** «solo es válida su matrícula real, nunca la operativa; todas sus
calificaciones deben aparecer en el registro auxiliar de su matrícula real. En todas». Rige en
**todas las vistas y documentos de solo lectura por bimestre**: registro auxiliar (docente y
área), historial del docente (carga, área, procedencia) y las pestañas de `/consulta-notas`.

**Regla.** El estudiante aparece **una sola vez**, en la sección de su matrícula **OFICIAL**. En
los bimestres de su **tramo**, sus datos se **leen** de la operativa: no se copian ni se mueven, así
que la Regla A sigue intacta (la boleta ya hacía esto mismo). Las competencias son del **nivel**
(`areas.nivel_id`) y un retorno no cambia de nivel, así que la C5 de 1° B es la misma C5 de 2° B.

**Dos presentaciones, la misma fuente** (decisiones del usuario del 28/10/2026):

| Qué | **Pantallas** (`/consulta-notas`, historial del docente) | **Registro auxiliar IMPRESO** (docente y área) |
|---|---|---|
| Logro y conclusión de cada competencia | en su fila: los de la operativa, en la carga **equivalente** (`CambioSeccionModel::cargasEquivalentes`: misma subárea si la hay; si no, la misma área), solo si está **bloqueada** | **fila VACÍA** (nombre, chip y «—») en la matriz y en la CD; todo va al **ANEXO** |
| Criterios (Cr1…CrN) | **una celda fundida** con la sección donde cursó («1° B», `cursado_en`) | en el anexo, **cada criterio de la carga de 1° B con su nota**, más su leyenda: regla del registro auxiliar, «todas las calificaciones de cada criterio» |
| Transversales | logro de la carga equivalente; el promedio y la conclusión del tutor, de la operativa | en el anexo del área (por carga); la hoja «Competencias transversales de la sección» deja la fila vacía y pone **al pie** lo que congeló el **tutor de la sección operativa**, con su nombre (`TutorPeriodoModel`) |
| Conducta y asistencia | de la operativa (`sqlDatosDelPeriodo`) | (el registro no las lleva) |
| Estadísticas | cuenta como **evaluada** con su nivel real | «No evaluados 1» (aceptado: su fila va vacía) |

- **Chip** «Retorno de grado» («a secas»): solo en los bimestres del tramo, en los dos casos.
- **Fuera del tramo**, sus propias notas, sin fundir ni anexo.
- **El anexo** (`_anexo-retorno.php`, `RegistroAuxiliarModel::anexoRetorno`) va en hoja nueva tras
  las conclusiones de cada área. Lleva el encabezado del documento sin la fila de docentes (cada
  carga dice «Registró: …»), una fila por competencia (Cr1…CrN, el par del Logro y la conclusión)
  y la leyenda «Criterios de evaluación de 1° B». Lee cada competencia con
  `getResumenCompetencia` **sin** el flag de lectura: su roster es el de evaluación, justo donde la
  operativa cursó. El documento vacía la fila con `vaciarTramo()`, solo en `hoja()`; las
  pantallas no pasan por ahí.

**Puntos únicos:**
- `RetornoGradoModel::sqlRosterLecturaDelPeriodo`: la operativa nunca entra al roster.
- `RetornoGradoModel::oficialesEnTramo()`: en PHP y en lote, qué oficiales están en su tramo y dónde cursaron.
- `RetornoGradoModel::sqlDatosDelPeriodo()`: en SQL, de qué matrícula se leen los datos de esa fila.
- `CalificacionModel::conDatosDelTramo()`: el logro por competencia.

**Consecuencia aceptada:** la sección **operativa** (1° B) ya no la lista en sus vistas de lectura,
así que su docente no la ve en su registro ni en su historial aunque la evaluó. Las grillas de
captura no cambian: allí se evalúa en la operativa, como siempre.

**Límites conocidos** (medidos el 28/10/2026 sobre el retorno #1: 0 casos de cada uno):
- **Escenario 6**: una competencia de la operativa **sin columna** en la sección oficial. Decisión
  del usuario: va en la columna «Sin carga». **No está implementado** porque no había ningún caso.
  `verif_roster_lectura.php` §9 **falla** si aparece uno.
- **Exoneraciones** de la operativa en el tramo: la fila usa la lista de la carga oficial.
- **Extraordinarias de RA** sobre la operativa: su logro sale (pantallas y anexo), pero no su
  detalle en la sección «Extraordinarias» del registro (que lista las de las cargas oficiales).
- **Omisiones**: van con los criterios. Si toda una competencia fue omisión, no hay logro y sale «—».

## Las dos exclusiones, que son INVERSAS entre sí

Es el error más fácil de cometer en este módulo. **Cada una tiene su PUNTO ÚNICO en
`app/Helpers/helpers.php`; ninguna se escribe a mano** (desde el 02/09/2026):

```sql
-- EVALUACIÓN  →  roster_evaluacion()      Se evalúa donde el estudiante ESTÁ:
AND m.tipo NOT IN ('trasladado', 'retirado')      -- ← matriculas_vigentes()
AND m.id NOT IN (SELECT matricula_oficial_id   FROM retornos_grado WHERE estado = 'activo')
AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado WHERE estado = 'revertido')

-- DOCUMENTO   →  matricula_documento()    Se emite con la OFICIAL, SIN condición de estado:
AND m.id NOT IN (SELECT matricula_operativa_id FROM retornos_grado)
```

Arriba se excluye la **oficial**; abajo la **operativa**. Confundirlas produce
exactamente los dos defectos del final de este documento.

| Criterio | Punto único | Quién lo usa |
|---|---|---|
| EVALUACIÓN | `roster_evaluacion()` | los rosters de calificaciones, conducta, transversales, tutoría y asistencia |
| DOCUMENTO | `matricula_documento()` | `BoletaPublicaModel` ×3 · token público (`BoletaController`) · **toda `/matriculas/resumen`: chips, los 5 gráficos y el cuadro por grado** |

`roster_evaluacion()` **compone** su primera condición desde `matriculas_vigentes()`, que
existe aparte porque los chips de `/matriculas/resumen` necesitan «estudiantes que siguen
en el colegio» combinado con el ancla **DOCUMENTO**, no con la de evaluación.

> 🔴 **Hay una tercera forma, y es la incorrecta: el híbrido.** El cuadro de
> `/matriculas/resumen` copió la línea DOCUMENTO y le pegó el `WHERE estado = 'activo'`
> de la de EVALUACIÓN. Con un retorno **`revertido`** ese híbrido no excluye NINGUNA
> de las dos matrículas y el estudiante cuenta dos veces — y la fila fantasma cae en
> el **grado inferior** como `continuador`/`desactivado`, que es como `revertir()`
> deja la operativa. Corregido el 02/09/2026; era latente porque el único retorno
> real está `activo`. **El `WHERE estado` es de la lista de arriba y de ninguna otra.**
>
> Ahora lo impide `database/verificaciones/verif_matricula_documento.php`, que además
> de comprobar el texto emitido **mide el comportamiento en las dos ramas**: simula un
> retorno `revertido` con transacción + rollback y exige que el helper excluya una
> matrícula mientras el híbrido escrito a mano no excluye ninguna.

**Las últimas 5 copias se retiraron el 28/09/2026.** Este doc decía que
`SiagieExportModel` (×2), `Padre\PanelController` y `Docente\PanelController` (×2)
usaban esa forma «legítimamente», como listados operativos. **No se sostenía:** sus
propios comentarios describen semántica DOCUMENTO («muestra la matrícula OFICIAL y oculta
la operativa», «figura en el archivo SIAGIE de su sección OFICIAL»). Daban el resultado
correcto **solo** porque también filtran `estado = 'aprobada'`, y `revertir()` deja la
operativa `desactivado`. La excepción era `Docente\PanelController::getMatriculados`
con `$soloAprobadas = false` (estado amplio, hoy sin llamadores), que **sí** contaba dos
veces al estudiante de un retorno revertido: medido, 2 filas contra 1.

Ahora las cinco usan `matricula_documento()`. Un A/B con el código real dio salida
idéntica en las 6 consultas, con el retorno activo y con el revertido simulado. La
**sección 6 de `verif_matricula_documento.php`** barre `app/` y falla si reaparece la
operativa filtrada por `estado = 'activo'`. Esa guarda **no** marca la exclusión con
`'revertido'` de `roster_evaluacion()`, que es legítima; hay un aserto que lo comprueba.

## Buscador de estudiantes (24/09/2026; revertidos desde el 05/10/2026)

`EstudianteModel::buscarEnAnioActivo` devuelve **una fila por matrícula**, así que un retorno
trae las dos matrículas, **activo o revertido**. Lleva el rol de cada una con
`RetornoGradoModel::sqlColumnasRol` (`retorno_rol`, `retorno_estado`), igual que
`MatriculaModel::listar` y la nómina. Quien pinta decide qué hacer con eso
(`data-retornos` del contenedor, en `buscador-estudiante.js`):

| Pantalla | Modo | Qué muestra |
|---|---|---|
| `/admin/buscar-estudiante` | `agrupar` (por defecto) | **Una tarjeta, la oficial**. Activo: «Retorno de grado: cursa en 1.° "B"». Revertido: «Retorno revertido el 05/10/2026 · cursó el II Bimestre en 1° "B"» |
| `/rectificaciones` | `separar` | **Las dos**, marcadas «Oficial» / «Operativa» (o «Operativa · revertido»): cada una guarda notas distintas (Regla A) |

Hasta el 05/10/2026 solo se conocía el retorno activo: tras revertir, el buscador mostraba
la operativa `desactivado` como una tarjeta más y se leía como una **baja por deuda** en
1.° B junto a la matrícula aprobada de 2.° B.

**Por qué no se agrupa también en Rectificación:** allí la tarjeta lleva a las notas de ESA
matrícula. Si se quitara la operativa, las notas posteriores al retorno quedarían inalcanzables
desde el buscador.

**El puesto sale de la matrícula que CURSÓ el último bimestre cerrado** (`matriculaDelPeriodo`):
puede ser cualquiera de las dos, y la tarjeta dice de qué grado es («Puesto 42.° en 1° "B"»).
El controlador añade el grado de la pareja a `puestosPorGrado` aunque esa fila no haya entrado
al `LIMIT`.

## Listado, nómina, detalle y guardas de gestión (05/10/2026)

- **Listado `/matriculas`**: la operativa (activa o revertida) es «Solo informativa» con
  «Ver oficial →»; la revertida lleva la insignia **«Operativa · revertido»**.
- **Nómina detallada**: la operativa revertida es fila informativa («Retorno de grado
  revertido · cursó aquí el II Bimestre; volvió a 2° B»), **no se marca como baja** aunque
  esté `desactivado` y cuenta como informativa en el resumen de la sección.
- **Detalle**: `show` redirige a la oficial **cualquier** operativa, también la revertida
  (antes solo la de un retorno activo).
- **Guardas en servidor** (`RetornoGradoModel::bloqueoGestion`): la operativa no se activa,
  desactiva, retira, revierte su retiro ni se traslada; la oficial de un retorno **activo**
  no se retira ni se traslada (primero se revierte). Las usan `MatriculaController` y
  `TrasladoController`.
- **Notas autorizadas SIAGIE**: se rotulan con la oficial y cada bimestre se registra en la
  matrícula que lo cursó. Antes, siempre en la operativa.

## Candado: NO se puede retornar a mitad de un bimestre ya evaluado

`RetornoGradoController::evaluacionEnBimestreActivo()` bloquea el retorno si la
matrícula oficial ya tiene, en un periodo `activo`, alguna fila en
`calificaciones`, `calificaciones_criterio` u `omisiones_criterio` (criterios
eliminados no cuentan). Se comprueba en `create()` (GET) y en `store()` (POST).

**Por qué:**
1. El corte del roster es **instantáneo**: los 9 rosters dejan de ver la oficial,
   así que el docente de origen pierde al estudiante a mitad de bimestre y sus
   criterios ya cargados quedan inalcanzables desde la grilla.
2. Los **criterios son de la sección**, no del alumno: los de la sección destino
   están vacíos para él y los de origen no existen allá. No hay convalidación
   automática — eso es el plan de cambio de sección, otro módulo.
3. La operativa quedaría con **promedios sin criterios que los respalden** → la
   alerta de evaluación incompleta la marca y el **guard P4 aborta el cierre**.

⚠️ **Se ancla en el DATO, no en la fecha: los bimestres SE SOLAPAN** (B1 termina
el 16/06 y B2 empieza el 01/06), así que "entre bimestres" no existe como
ventana de calendario.

*El retorno real del 21/06/2026 pasa este candado* — la oficial no tenía nada en
B2 ese día. Por eso salió limpio: por ausencia de datos, no por diseño.

## Candado: un retorno NUNCA cruza de nivel (24/09/2026)

Regla del usuario: **un estudiante de 1.º de secundaria jamás puede retornar a 6.º de primaria**.
El retorno es a un grado **inferior**, del **mismo nivel** y del **mismo año**. Ya lo imponían
`create()` (solo lista secciones con `g.nivel_id = ?` y `g.numero < ?`) y `store()` (valida lo
mismo en servidor). Desde el 24/09 lo vigila `verif_retorno_grado.php` en el DATO (0 retornos
que crucen de nivel, suban de grado o cambien de año) y en el CÓDIGO (los dos filtros siguen
ahí; cae el mutante que quita el de `store()`).

**Toda regla académica usa el grado de la matrícula OFICIAL**, jamás el de la operativa: la
situación final (regla por grado) y, desde el 24/09, también la aprobación de la UGEL de los
talleres. Ver `docs/modulos/usuarios-direccion.md` § «Talleres».

## Qué hace `store()` al crear el retorno

1. Crea la matrícula operativa en el grado destino (`estado='aprobada'`).
2. Inserta el vínculo en `retornos_grado` con `periodo_desde_id` = el primer bimestre sin
   cerrar (05/10/2026).
3. **Mueve** (`UPDATE matricula_id`) `inasistencias`, `asistencia_incidencias`,
   `conducta_respuestas`, `conducta_confirmaciones` y `calificaciones_conducta` **de los
   bimestres SIN CERRAR** a la operativa. Son contadores por bimestre, no datos por
   criterio: no hay nada que convalidar. Desde el 29/09/2026 (migraciones 068 y 069)
   viajan también las **fechas** de asistencia —los contadores son su conteo y deben
   vivir en la misma matrícula— y la **confirmación** de conducta —sin ella, lo
   confirmado llegaría como borrador y saldría de la boleta—.
   Los bimestres **cerrados no se tocan**.

**Mover, no copiar, es deliberado:** la unión de asistencia SUMA campo a campo,
así que dejar fila en ambas matrículas inflaría las faltas en silencio.

### Lo que este paso 3 hacía ANTES (hasta el 05/08/2026)

Un `INSERT IGNORE` que **copiaba TODAS las calificaciones** de la oficial a la
operativa, sin filtrar periodo y conservando el `carga_id` de la sección de
origen. Se eliminó. Causaba cuatro daños:

1. Dos fuentes de verdad (una rectificación desincroniza la copia en silencio).
2. `carga_id` ajeno: notas colgando de cargas de otra sección.
3. Copiaba `calificaciones` pero **no** `calificaciones_criterio` → promedios sin
   respaldo → alerta de evaluación incompleta.
4. Duplicaba al estudiante en el lote de boletas.

## Las uniones de la boleta: ya no se usan para los datos de un bimestre (05/10/2026)

Desde la 073 **la boleta no une fuentes para los datos de un bimestre**: notas, asistencia y
conducta se leen de `matriculaDelPeriodo()` (`BoletaModel::armar`,
`ConductaModel::getParaBoletaPorTramo`, el panel del padre y `SiagieExportModel`). Sin
fusión no hay precedencia que decidir ni suma que inflar. **Siguen por unión** solo las
preguntas del estudiante completo: exoneraciones y omisiones (`fuentes()`), y la existencia
(`sqlFuentes()`). `boletaContexto['evaluacion']` es `matriculaDeHoy()`: el plan del grado
donde el estudiante TERMINA el año (hasta el 05/10 era siempre la operativa, también tras
revertir).

Historia, hasta el 05/10/2026 —`boletaContexto` alimentaba cinco uniones con comportamientos
**distintos** ante un solape (dato en ambas matrículas para la misma clave)—:

| Unión | Semántica | Daño si solapan |
|---|---|---|
| `AsistenciaModel::getDelBimestreUnion` | **SUMA** campo a campo | 🔴 **Infla el contador** |
| `getAcumuladoAnualUnion` | **SUMA** | 🔴 Infla (hoy sin llamadores) |
| Calificaciones (`array_merge` en `BoletaModel`) | asigna por `[competencia][periodo]` | Gana la **oficial**; no duplica filas |
| `ConductaModel::getParaBoletaUnion` | `array_replace` por `periodo_id` | Gana la **oficial** |
| `ExoneracionModel::…Union` | clave `competencia_id` | Gana la **oficial** |
| `OmisionCriterioModel::…Union` | `array_unique` | Ninguno (idempotente) |

⚠️ La premisa declarada en el código —*"por bimestre solo una tiene datos, así que
la suma no infla"*— **no está garantizada por ningún UNIQUE**. Y donde gana la
oficial, la precedencia es la **inversa** a la Regla A. Por eso existe la
verificación de abajo.

🔴 **Todo lo que pregunte «¿a esta boleta le falta el dato?» debe mirar la UNIÓN,
no una matrícula.** Pasó el 22/09/2026 con la conducta y asistencia extraordinarias
(`calificaciones.md`): la operativa 692 no tenía filas en el I Bimestre, la oficial
190 sí, y ofrecer el alta habría inflado la suma. Para eso existe
`CalificacionModel::sqlFuentesBoleta($m)`, el gemelo SQL de `boletaContexto`.

## Verificación

`database/verificaciones/verif_retorno_grado.php` — solo lectura, corre en prod.

1. **Equivalencia** del listado de boletas nuevo vs. la lógica anterior: solo
   pueden cambiar las matrículas de retorno (prueba de no-regresión).
2. **Unicidad**: nadie dos veces por periodo; ninguna operativa listada.
3. **Presencia**: cada retorno con notas aparece, y en su sección **oficial**.
4. **Contadores**: `total_aprobables` cuadra con el lote real de cada sección.
5. **Solape de fuentes** por bimestre en las 5 uniones.

`database/verificaciones/verif_retorno_ciclo.php` (05/10/2026) — una transacción que nunca
confirma, con los controladores REALES. Prueba el ciclo completo sobre un estudiante de
prueba (retorno con el bimestre en curso → reversión en el mismo bimestre con tramo vacío →
reversión bloqueada con notas sin cerrar → reversión tras cerrar, con `hasta` = ese
bimestre → segundo retorno y retorno de otro nivel rechazados) y las guardas de gestión. En
cada estado, y sobre los retornos reales, exige que el estudiante salga **una vez por
bimestre y desde la matrícula que lo cursó** en mérito, alerta, situación final, lote y
boleta. Cae el mutante que devuelve el lote de `HEAD`.

## Reversión

`revertir()` marca el retorno como `revertido`, **cierra su tramo** (`periodo_hasta_id` = el
último bimestre cerrado ≥ desde, o NULL si no completó ninguno) y desactiva la operativa.
**Las notas no se mueven ni se borran**: la boleta de la oficial lee cada bimestre del
tramo desde la operativa.

**Desde el 05/10/2026** (caso real: retorno #1, la estudiante volvió a 2.° B al inicio del
III y se comunicó tarde) la reversión es el **espejo de `store()`**:

- **Mueve a la oficial la asistencia y la conducta de los bimestres SIN CERRAR**
  (`moverRegistrosDelBimestre`, que comparte con `store()`, y `TABLAS_DEL_BIMESTRE`). Antes
  se quedaban en la operativa, fuera de todo roster: en la sección oficial el estudiante
  salía «✓ asistió» en días que pasó en otra aula, sin conducta, y trababa el bloqueo del
  auxiliar.
  - Cada fila viaja **con su estado**: lo confirmado llega confirmado. **No hace falta
    bloquear ni aprobar antes de revertir**; el bloqueo es por sección y no viaja.
  - Casilla **«moverlos como borrador»**, para cuando la sección operativa registró datos
    de un estudiante que ya no estaba ahí: se borra la fila de `conducta_confirmaciones` y
    se anula `inasistencias.confirmado_en`. Las respuestas y las fechas se conservan.
  - No toma la lista del día de la sección oficial: eso afirmaría «asistió» para todos sus
    compañeros. Una falta que cae en un día «Sin tomar» espera a que se pase lista.
- **Guardas** (`bloqueoReversion`, en el GET y en el POST):
  1. evaluación de la operativa en un bimestre sin cerrar, en las **3 tablas** (antes solo
     `calificaciones`: un criterio con nota sin promedio pasaba);
  2. **cierre vigente** de conducta o de asistencia en la sección de origen o en la de
     destino: hay que reabrirlo antes;
  3. la oficial ya tiene filas propias en esos bimestres: la unión de la boleta sumaría.
- **La ventana de reversión es estrecha.** Los bimestres se solapan: basta una nota del
  bimestre siguiente en la operativa antes de que cierre el actual para que la guarda 1 la
  impida hasta que ese cierre también.
- La pantalla de confirmación lista como «se consolidan» solo los bimestres con **criterios**
  en la operativa. El retorno #1 arrastra 22 promedios del I copiados por el `INSERT IGNORE`
  anterior al 05/08, que se cursaron en la oficial.
- **La alerta de evaluación incompleta usa el anclaje por bimestre del mérito**
  (`RetornoGradoModel::sqlCursoElPeriodo`, punto único). Solo conocía el retorno `activo`:
  revertido, la operativa salía incompleta en cada criterio nuevo de su ex sección y
  **bloqueaba el cierre del bimestre de todo el colegio**, sin salida por la interfaz.

Protegido por `database/verificaciones/verif_reversion_retorno.php`. Ejecuta el
`revertir()` real en un subproceso que nunca confirma y prueba las dos ramas de cada guarda.

### 🔴 El tramo de un revertido termina en un bimestre CERRADO (08/10/2026)

**Por qué importa:**
- `sqlRosterDelPeriodo` (grillas de asistencia, conducta y calificaciones) da lo mismo que
  `roster_evaluacion()` en el bimestre en curso **solo si** `periodo_hasta_id` apunta a un
  bimestre cerrado. Es lo que pone `revertir()` (`finDelTramoAlRevertir`).
- Si apunta a uno sin cerrar, el bimestre en curso «se cursó» en la operativa:
  - el estudiante sale en la sección del grado inferior y **falta en la suya**;
  - la fila de la grilla no se puede guardar, porque `matriculaEnRoster` usa
    `roster_evaluacion()`.

**Caso real (BD local de casa, retorno #1, BALTAZAR PINTO):**
- La 073 se aplicó con el retorno ya revertido por el código viejo.
- Su relleno toma «el último bimestre con cualquier dato en la operativa». Una fila de
  asistencia del III en ceros dejó `hasta = III`, sin cerrar.
- Efecto: en el III salía en 1.° B y faltaba en 2.° B.
- Producción no lo tuvo: ahí se revirtió después de la 073 y se corrigió con
  `reparar_retorno_1_tramo.sql` (`hasta = 2`).
- Ese script corrige desde ahora también el caso `hasta = 3`.

**Guarda:** la sección 4 de `verif_reversion_retorno.php` revisa el **dato** de los retornos
reales: un activo tiene el tramo abierto, y un revertido lo cierra en un bimestre cerrado ≥
desde. Ya lo señalaban `verif_roster_asistencia.php` y `verif_roster_evaluacion.php`
(«1.° B sobra 692 · 2.° B falta 190»), pero por el síntoma, no por la causa.

## Historial de defectos (05/08/2026)

- **Doble conteo de asistencia.** Ambas matrículas con fila en B2 → la boleta
  mostraba **4 faltas en vez de 2**. Origen: se registró con el roster de
  asistencia viejo (que sí mostraba la oficial) y rehacer la sección con el
  roster nuevo no borró la fila. La UI no puede corregirlo —`matriculaEnRoster`
  rechaza escribir sobre la oficial (403)—, solo SQL.
- **Lote de boletas.** `BoletaPublicaModel` no conocía el retorno (0 menciones a
  `retornos_grado`): en **B1** el estudiante salía **dos veces** (517 filas / 516
  alumnos, con dos tokens) y en **B2 desaparecía** de su sección oficial (2° B
  mostraba 18 aprobables en vez de 19). Defecto **preexistente** desde el
  21/06/2026, no una regresión del deploy del 04/08.
