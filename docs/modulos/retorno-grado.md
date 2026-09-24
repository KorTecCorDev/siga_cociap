# Retorno de grado

> Un estudiante asiste a un grado **inferior** al que le corresponde en SIAGIE.
> Se le crea una **matrícula operativa** en el grado destino, vinculada con su
> **matrícula oficial** en `retornos_grado`. Implementado en
> `RetornoGradoController` + `retornos_grado`.

## REGLA A — dónde vive cada cosa (decidida el 05/08/2026)

| Aspecto | Bimestres **anteriores** al retorno | **Desde** el retorno |
|---|---|---|
| Notas (`calificaciones`, criterios) | matrícula **oficial** | matrícula **operativa** |
| Grilla de calificación, conducta, transversales | sección oficial | sección **operativa** |
| Asistencia | matrícula oficial | matrícula **operativa** |
| Orden de mérito | grado **oficial** | grado **operativo** |
| **Boleta y token público** | **SIEMPRE matrícula oficial** | **SIEMPRE matrícula oficial** |
| **Estudiantes en riesgo** (informe, banda del tablero) | **SIEMPRE matrícula oficial** | **SIEMPRE matrícula oficial** |

**Estudiantes en riesgo cuenta por la matrícula OFICIAL (23/09/2026, decisión del usuario:
«el retorno es un proceso interno del colegio»).** No se recalcula nada: las cifras son las
de la fila del mérito, que `statsPorGrado` **reubica** en el grado y la sección oficiales,
con la regla por nivel del nivel **oficial** y para todo retorno, activo o revertido. El
mérito (`mejor`, `peores`, `total`) sigue en el grado operativo, y la franja lo dice:
«Retorno de grado: se evalúa en 1.° B, puesto 42 de 42». Detalle en
`docs/modulos/usuarios-direccion.md` § «Retorno de grado en el informe».

**Principio rector: el vínculo `retornos_grado` es el puntero; los datos NO se
copian ni se mueven entre matrículas.** Cada bimestre queda donde se cursó, y la
boleta une las fuentes al leer (`CalificacionModel::boletaContexto` →
`identidad = oficial`, `fuentes = [operativa, oficial]`).

Se descartó la alternativa B (recalcular todo el año en el grado operativo):
obligaba a mantener datos duplicados o a que el mérito uniera fuentes, y dejaba
a la estudiante rankeada en un grado con notas obtenidas en otro.

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

⚠️ **No toda mención a `matricula_operativa_id ... WHERE estado = 'activo'` es el
híbrido.** `SiagieExportModel`, `Padre\PanelController` y `Docente\PanelController` la
usan legítimamente: son **listados operativos**, no el documento. El híbrido es
específicamente pegarle ese `WHERE` a la línea que decide **quién recibe documento o se
cuenta**.

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
2. Inserta el vínculo en `retornos_grado`.
3. **Mueve** (`UPDATE matricula_id`) `inasistencias`, `conducta_respuestas` y
   `calificaciones_conducta` **de los bimestres ACTIVOS** a la operativa. Son
   contadores por bimestre, no datos por criterio: no hay nada que convalidar.
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

## Las 5 uniones de la boleta y su semántica

`boletaContexto` alimenta cinco uniones con comportamientos **distintos** ante un
solape (dato en ambas matrículas para la misma clave):

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

## Reversión

`revertir()` marca el retorno como `revertido` y desactiva la operativa. **No
mueve ni borra datos**: la boleta de la oficial los sigue leyendo por unión.
Exige que los bimestres con notas en el operativo estén **cerrados**.

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
