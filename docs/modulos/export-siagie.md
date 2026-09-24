# Módulo: Exportación de notas al SIAGIE (llenado de Excel oficiales)

> Implementado el 03/07/2026 (fase CLI) y el 12/07/2026 (módulo web para
> admin/RA). Vuelca los promedios literales por competencia + conclusiones
> descriptivas de SIGA a los Excel que el SIAGIE exporta por sección+bimestre —
> el registro oficial ante UGEL-MINEDU que Registro académico llenaba a mano.
> Primaria operativa; secundaria pendiente.

## Invariantes (lo que NO se puede romper)

- **El archivo se RE-SUBE al SIAGIE** → preservación byte-a-byte: SOLO se
  insertan valores en celdas (edición quirúrgica del XML del xlsx vía
  `ZipArchive`); JAMÁS reescribir el libro con una librería (protección con
  contraseña, hoja oculta `Parametros`, estilos y validaciones deben quedar
  intactos).
- **Jamás rellenar a ciegas:** sin match exacto único (código o nombre), la
  fila queda intacta y va al reporte para resolución manual. Nunca se
  sobreescribe una celda que ya tiene valor.
- **Solo lo oficial:** competencia BLOQUEADA + bimestre CERRADO (el archivo
  completo se rechaza si el periodo no está `cerrado` en SIGA). Transversales:
  promedio agregado con cierre vigente del tutor (vía `getBoletaAlumno`).
- **`estudiantes.codigo_estudiante` = código SIAGIE (14 dígitos, col. B):** se
  persiste tras el primer match por nombre SOLO si está vacío; un valor
  distinto es conflicto reportado, nunca se pisa. **Solo se persiste si tiene
  formato `^\d{14}$`** (mutación durable que se vuelve la llave de matcheo de
  los próximos bimestres): un código con otro formato NO se registra (la nota sí
  se escribe) y sale una advertencia en el reporte. Guard replicado en
  `guardarCodigoSiagie` como defensa en profundidad. Además, si un mismo código
  aparece REPETIDO en el roster de SIGA (no hay UNIQUE en la columna), el matcher
  NO matchea por código esa fila → la marca `ambiguo` para resolución manual (no
  se escribe la nota al alumno equivocado).
- El original se reemplaza in-place (decisión del usuario, para que el SIAGIE
  no rebote el archivo) pero SIEMPRE tras: escribir a temporal → verificar
  celda por celda → respaldar en `scripts/siagie/backup/{fecha}/`.

## Uso

```
php scripts/siagie/llenar-siagie.php --simular <archivo.xlsx|carpeta>   # SIEMPRE primero
php scripts/siagie/llenar-siagie.php <archivo.xlsx|carpeta>             # escritura real
```
Genera `{nombre}_reporte_{fecha}.txt` junto a cada archivo: matching,
celdas escritas, blancos con motivo, advertencias.

## Piezas

- `app/Siagie/XlsxQuirurgico.php` — lector/escritor quirúrgico del xlsx (shared
  strings: reusa índices o anexa `<si>` actualizando count/uniqueCount; celdas de
  plantilla ya existen vacías con estilo y `t="s"`). Namespace `App\Siagie\`,
  autocargable (movido desde `scripts/siagie/lib/` el 12/07/2026).
- `app/Siagie/MatcherEstudiantes.php` — normalización (mayúsculas, translitera
  tildes/Ñ→N, elimina todo lo que no sea `[A-Z0-9 espacio]`) y clasificación:
  `match_codigo` → `match_nombre` (exacto y único) → `ambiguo` /
  `conflicto_codigo` / `sin_match` (con sugerencia ≥80% solo informativa).
- `app/Siagie/LlenadorSiagie.php` — **orquestación compartida** (CLI + web).
  `analizar()` decide TODO sin tocar disco ni BD (es el preview); admite
  `$resoluciones` (fila→estudiante_id) para la **resolución manual de identidad**
  → estado `match_manual` con guardas (dentro del roster, sin cruce, sin
  conflicto de código). `escribirVerificado()` + `persistirCodigos()`. Con
  `$resoluciones` vacío es byte-idéntico al comportamiento automático (CLI).
- `app/Models/SiagieExportModel.php` — datos: `resolverDestino` (desde la hoja
  `Parametros`: nivel/año/B{n}/'1A'), `estudiantesDeSeccion` (aprobadas, excluye
  operativas de retorno activo), `notasOficiales` (boletaContexto +
  getBoletaAlumno → en retorno las notas de la operativa se escriben en la fila
  de la sección OFICIAL), `competenciasExoneradas`, `competenciasDelNivel`,
  `guardarCodigoSiagie`.
- `scripts/siagie/llenar-siagie.php` — CLI, wrapper delgado del `LlenadorSiagie`
  con su política propia: backup + reemplazo in-place del original (lote por
  carpeta con `--simular`).

## Módulo web — Actas SIAGIE (admin / registro_academico)

`app/Controllers/Admin/ActasSiagieController.php` + vistas
`resources/views/admin/actas_siagie/{index,preview,resultado}.php`. Tile en el
dashboard (grupo *Evaluación y reportes*). Rutas `/admin/actas-siagie[...]`.

- **Flujo EFÍMERO en dos pasos, una sección por vez:** subir → `previsualizar`
  (analiza sin escribir, muestra reporte + selectores de identidad) → `confirmar`
  (re-analiza con resoluciones, escribe, verifica, persiste códigos) →
  `resultado` (descarga del acta llenada + reporte `.txt`).
- **El xlsx subido vive en un temporal** (config `siagie_tmp_path`; local
  `storage/tmp/siagie`, prod `~/siga_uploads/siagie_tmp`) entre ambos pasos y se
  borra al confirmar. Barrido por TTL (30 min) en cada visita al índice.
- **NO reemplaza in-place** (a diferencia del CLI): produce una copia llenada
  que se streamea para descargar y re-subir al SIAGIE.
- **Resolución de identidad segura:** el usuario solo elige un alumno EXISTENTE
  del roster por DNI; la nota siempre sale de SIGA (nunca se teclea). Guardas en
  el controlador (whitelist del roster) y en el servicio (roster + cruce +
  conflicto de código). `conflicto_codigo` NO se resuelve aquí → enlace a
  corregir en la matrícula. Notas faltantes / columnas sin mapear → enlaces a
  Consulta de notas y Currículo (arreglo durable en SIGA).
- **Cambio de sección sin tramitar:** SIGA no tiene trámite de cambio de sección
  (la matrícula fija `seccion_id` al crear). Si una fila `sin_match` es un alumno
  que SIGA tiene en OTRA sección del mismo grado, el servicio lo **detecta**
  (`estudiantesDeOtrasSecciones` + `anotarOtraSeccion`, match por nombre único) y
  lo ofrece en el selector bajo *"Otras secciones del grado"*, marcado con aviso.
  Al resolverlo, se escriben SUS notas reales de SIGA y la resolución queda como
  `match_manual` con `cruce_seccion=true` y detalle *"CAMBIO DE SECCIÓN sin
  tramitar"* (auditoría). El auto-match sigue acotado a la sección; **lo que se
  escribe en las celdas no cambia** (la detección solo agrega pistas), así el CLI
  conserva su salida de celdas — solo gana líneas informativas en el reporte.
- **Seguridad:** `requireRole(['admin','registro_academico'])`, CSRF en POST,
  token de job atado a sesión, validación de subida (extensión, tamaño, firma
  ZIP `PK`). Único efecto en BD: `guardarCodigoSiagie` (solo si estaba vacío).

## Vínculos y cobertura (28/07/2026)

`GET /admin/actas-siagie/vinculos` — pantalla de **diagnóstico, solo lectura**
(`ActasSiagieController::vinculos`). Responde una pregunta que antes nadie podía
hacerse: **¿qué notas de SIGA NO están llegando al acta oficial?**

- **Por qué existe:** un área sin `codigo_siagie` se omite del acta **en silencio**.
  Así se perdieron los talleres de secundaria en B1 — medido: **321 notas
  bloqueadas** (Raz. Mat. 272 + Pre-Cálculo 49) que nunca llegaron al SIAGIE, sin
  un solo aviso en el reporte.
- **Muestra, por bimestre:** áreas con notas y sin destino (con enlace directo a
  Currículo para asignarles código), los **vínculos configurados**, las
  **excepciones de hoja** ya resueltas —para que no vivan solo dentro del código— y
  las **colisiones de código**.
- **La tabla de vínculos parte de `areas`, NO de `calificaciones`** (`vinculosDeAreas`):
  un vínculo existe aunque el bimestre no tenga notas todavía. Si se listaran solo las
  áreas con notas, no se podría auditar la configuración — Ética y Ed. Religiosa
  desaparecían de la vista justo cuando hacía falta verlas.
- **Estados del vínculo** (`ActasSiagieController::estadoVinculo`): `vinculada`,
  `vinculada + excepción` (tiene hoja propia y ADEMÁS recibe otra: Transversales con
  `0006,0007` + la `032` en 5°), `recibe por excepción` (Ética con la `035`),
  `reemplazada [en N°]` (su hoja la llena otra área: Ed. Religiosa y EPT de 5°),
  `sin destino` (tiene notas y no llega al acta → rojo) y `no se exporta` (sin código
  y sin notas → gris, situación legítima).
  - El índice de hojas ocupadas se clavetea por **nivel + código**: las excepciones son
    por nivel, y sin el nivel en la clave la regla `035` de secundaria marcaba como
    reemplazada a la Ed. Religiosa de **primaria**, que se llena con normalidad.
- Las excepciones las resuelve `LlenadorSiagie::excepcionesDeclaradas()`, que usa el
  MISMO método privado que el llenado real: lo que se ve en pantalla es exactamente
  lo que se aplicará.

### `codigo_siagie` editable en Currículo

El campo dejó de ser exclusivo de las migraciones (039/041): se edita en
**Currículo → Editar área → "Codigo de hoja SIAGIE"**. Con esto, **activar un taller
que el SIAGIE ya reconozca no necesita despliegue** — basta asignarle el código de su
hoja. Dos guardas en `CurriculumController::guardarArea`:

- **Formato** `^\d{2,4}(,\d{2,4})*$` (admite compuestos como `0006,0007`).
- **Colisión:** ninguna otra área del mismo nivel puede usar ese código
  (`CurriculumModel::areaConCodigoSiagie`). Importa porque `areaPorCodigoSiagie`
  resuelve con `FIND_IN_SET … LIMIT 1`: dos áreas con el mismo código harían que la
  hoja se asignara a una **arbitraria** y el acta se llenara con el área equivocada,
  sin ningún aviso.

**Sigue diferido** el caso del taller *sin* hoja propia (reportar bajo un área
anfitriona), que es el peligroso: sus competencias son homónimas de Matemática y
exigiría invertir la regla "ante homónimos gana la competencia de la hoja".
Los pares reales (medidos el 29/07/2026) son **C54↔C44** (cantidad) y **C55↔C47**
(gestión de datos) del Taller de Raz. Mat., y **C56↔C45** (regularidad) del Taller de
**Pre-Cálculo** — no del de Raz. Mat., que tiene 2 competencias, no 3.

> **Homónimas entre NIVELES: eso es diseño, no deuda.** Una competencia con la misma
> definición existe una vez en primaria y otra en secundaria, con `codigo_minedu`
> propio (13 casos medidos: C4/C28, CT2/CT4, C1/C41, C21/C44…), **a propósito**: así se
> puede cambiar la descripción de un nivel sin arrastrar al otro (regla confirmada por
> el usuario el 29/07/2026). Por eso **todo emparejamiento va acotado al nivel** —
> `areaPorCodigoSiagie($nivelId, …)` y el índice de hojas por nivel+código. Nunca
> unificar filas homónimas de distinto nivel.
>
> **Gotcha al consultar el catálogo:** las competencias de un área `con_subareas`
> cuelgan de la **subárea** y tienen `competencias.area_id = NULL` (23 de 59 filas).
> Un `INNER JOIN areas ON a.id = c.area_id` las descarta **en silencio** y hace parecer
> que Matemática de secundaria no tiene competencias. Usar
> `LEFT JOIN subareas` + `COALESCE(a.nivel_id, a2.nivel_id)`.

### ⚠️ Los talleres NO tienen hoja en el SIAGIE (medido el 29/07/2026)

Al ir a asignarles el `codigo_siagie` apareció el obstáculo de fondo: **las actas
reales del SIAGIE no traen ninguna hoja de taller.** Verificado leyendo los dos
RegNotas reales de B1 que conservamos, `S1A.xlsx` (1°A) y `S5B.xlsx` (5°B): ambos
libros tienen **exactamente las mismas 15 hojas** —`Generalidades`, `Parametros`,
`0001-ART Y CULT`, `0002-CAST SEGNL`, `0004-CIENC TEC`, `0010-DESARR PCC`,
`014-CCSS`, `017-COMU`, `031-EFIS`, `032-ETRA`, `035-EREL`, `057-INGL`, `063-MATE`,
`0006-DESEN TIC`, `0007-GEST AUTO`— y **ninguna corresponde a un taller**. 1°A es
justamente una sección donde SÍ se dicta el Taller de Raz. Mat., así que no es una
casualidad de muestra.

**Consecuencia: asignar un `codigo_siagie` al taller no resolvería nada hoy** — no
hay hoja `NNN-…` que llenar. El vínculo que falta no está en SIGA sino en el
**plan de estudios registrado en el SIAGIE**: si el taller no está dado de alta ahí,
el portal no genera su hoja. **No es un problema de código ni de configuración de
SIGA; es una gestión del colegio ante el SIAGIE/UGEL.**

Alcance real (medido en local, donde B1 está completo; la cifra fina debe
confirmarse en prod):

| Área | Grados | Secciones | Notas B1 |
|---|---|---|---|
| Taller de Razonamiento Matemático | 1° a 5° | 11 | 273 |
| Taller de Pre-Cálculo | 5° | 2 (A, B) | 49 |

🔴 **ACTUALIZACIÓN 24/09/2026 — la UGEL NO aprobó los talleres.** Contradice la expectativa de
abajo sobre el Taller de Razonamiento Matemático: este año **ningún** taller está en el SIAGIE
(no se pueden registrar sus notas). Consecuencias en SIGA: los dos talleres pasaron a
`areas.tipo = 'taller'` (migración 064) y **solo cuentan para la situación final en los años y
grados que la UGEL apruebe** (tabla `talleres_aprobacion`, se marca en Currículo; en 2026, ninguno);
siguen en la boleta y en el mérito del colegio. El
«sin destino» rojo de la pantalla de vínculos ya puede distinguirse por el tipo. **El vínculo
de los talleres con las actas queda PENDIENTE por decisión del usuario (24/09/2026)**: todavía
no hay un código de hoja de talleres en el Excel de actas del SIAGIE. Ver `docs/modulos/usuarios-direccion.md` § «Talleres».

**CAUSA RAÍZ Y DECISIONES — confirmadas por el usuario el 29/07/2026:**

- **Por qué no hay hoja:** existe una **aprobación de talleres PENDIENTE en la UGEL de
  Huaraz**. Mientras ese trámite no se resuelva, el SIAGIE no habilita las hojas de los
  talleres en los Excel. **No es un error de configuración ni de SIGA.**
- **Taller de Razonamiento Matemático → SE DARÁ DE ALTA (opción 1).** Sí o sí tendrá
  que registrarse en el SIAGIE. Cuando la UGEL apruebe, el RegNotas traerá su hoja y
  bastará **teclear su `codigo_siagie` en Currículo** (sin despliegue). Hasta entonces
  sus notas viven solo en SIGA — **no es un olvido que perseguir cada bimestre.**
- **Taller de Pre-Cálculo → NO se reporta (opción 2).** Decisión firme, coherente con
  que las horas que ocupa (las de EPT en 5°) tampoco se reportan. Sus 49 notas de B1
  quedan solo en SIGA, a propósito.
- **La opción 3 (área anfitriona) queda descartada de hecho:** si el taller tendrá hoja
  propia, no hace falta el caso peligroso de las competencias homónimas.

Nota para la pantalla de vínculos: mientras un área con notas no tenga código se
pinta **`sin destino` (rojo)**. Con las decisiones de arriba, **ese rojo es hoy un
falso positivo esperado en los dos talleres**: uno espera el trámite de la UGEL y el
otro no se exporta por decisión. Distinguir "olvido" de "decisión" pediría marcar el
área como *no exportable* (columna nueva); no implementado, anotado como mejora.

## Estructura del Excel SIAGIE (réplica verificada)

- UN libro por **grado+sección+bimestre**. Hoja `Parametros` (oculta) =
  identidad máquina (nivel B0/B1…, año, periodo B1..B4, grado, sección) →
  autodetección; `Generalidades` legible; una hoja por ÁREA con código SIAGIE
  (0005-COMU, 063-MATE, …, transversales 0006-DESEN TIC y 0007-GEST AUTO).
- Hoja de área: F1-2 cabecera (competencias 01..N × columnas **NL** +
  **Conclusión**); F3+ estudiantes (A=ID SIAGIE interno — NO es DNI,
  B=código 14 dígitos, C="APELLIDOS, NOMBRES"); leyenda al pie
  (`01 = nombre de la competencia`).
- El mapeo columna→competencia SIGA es por la LEYENDA normalizada contra
  `competencias.nombre_completo` del nivel (1 candidata → mapea). Cuando queda
  **ambigua (>1)** o **sin match (0)**, entra la resolución POR ÁREA (ver
  "Resolución por área"). Áreas sin equivalente (CAST SEGNL) quedan en blanco.
- La plantilla valida conclusiones a **10–500 caracteres** (dataValidation);
  fuera de rango se escribe igual pero con advertencia fuerte en el reporte.

## Casos especiales (reglas del 03/07/2026)

- EXO → celdas omitidas (el SIAGIE las bloquea de todos modos), reportado.
- "No se evaluó" (bloqueo sin notas) → en blanco, reportado.
- **Nota autorizada por dirección (14/07/2026):** si la celda quedaría en blanco por
  ausencia justificada pero dirección registró una nota autorizada para esa
  competencia (tabla `notas_autorizadas_siagie`, migración 040), el llenador la usa
  para rellenar SOLO esa celda vacía (literal + conclusión). Precedencia: la nota
  oficial de `calificaciones` SIEMPRE gana; la autorizada solo cubre el blanco. Se
  reporta aparte ("NOTAS AUTORIZADAS POR DIRECCIÓN — no evaluado") y suma la clave
  `resumen['autorizadas']`. Nunca pisa una celda con valor. `LlenadorSiagie` la
  lee vía `SiagieExportModel::notasAutorizadas()` en la rama `$nota === null`
  (une fuentes con `boletaContexto`, igual que `notasOficiales`: en RETORNO de
  grado la nota vive en la OPERATIVA y el export procesa la OFICIAL). La
  registran admin/RA desde `/matriculas/{id}/notas-siagie` (candado: omisión
  registrada de cualquier motivo + competencia bloqueada + sin nota real). Ver
  `docs/modulos/matriculas.md`.
- Retorno de grado → notas de la matrícula operativa en la fila de la sección
  oficial (viable: las competencias son por NIVEL, no por grado).
- Alumno de SIGA sin fila en el Excel (o viceversa) → reporte, sin acción.

## Resolución por área (secundaria 12/07/2026; primaria 16/07/2026)

Estructura idéntica en ambos niveles; el mismo módulo los procesa. Verificado
con nóminas reales (S1A, S5B; primaria 4°A). Puntos propios:

- **NL = literal `A,AD,B,C`** (confirmado por el `dataValidation` real, igual que
  primaria). El módulo escribe `nota_a_literal()` — no numérico.
- **Diferenciación por área (`areas.codigo_siagie`, migración 039).** El tab de
  cada hoja es `{codigo}-{ABREV}` (`063-MATE`, `057-INGL`; transversales
  `0006,0007`). El matcher resuelve la hoja→área por ese código
  (`areaPorCodigoSiagie`, `FIND_IN_SET`) y, SOLO para columnas ambiguas o sin
  match, re-mapea DENTRO de esa área:
  - **Homónimos Matemática vs Talleres:** "Resuelve problemas de cantidad" existe
    en Matemática (C44) y en el Taller Raz. Mat. (C54) con el MISMO nombre. La
    resolución por área se queda con la de la hoja (Matemática) e **ignora la del
    taller**. NO se renombran competencias (curricularmente son la misma; renombrar
    rompería el export futuro del taller y las boletas de SIGA).
  - **Inglés (leyenda abreviada):** el SIAGIE dice "Se comunica oralmente"; SIGA
    "…en Inglés como lengua extranjera" → 0 match de texto. Se asigna **por
    posición** (col 01→competencia de orden 1, etc.) dentro del área Inglés.
- **Primaria poblada (16/07/2026, migración 041):** códigos tomados del RegNotas
  real de 4°A B1. NO coinciden con secundaria: Inglés `0003` (sec. `057`),
  Comunicación `0005` (sec. `017`), Personal Social `067` (sec. no existe; su
  análogo DPCC es `0010`); transversales `0006,0007`. CAST SEGNL (hoja `0002`)
  y Tutoría quedan SIN código a propósito (nunca son destino). Validado con
  `--simular` sobre el acta real de 4°A B1: reporte byte-idéntico pre/post
  (el código solo actúa cuando el match de texto falla).
  - **Lección Inglés C1 primaria (motivo del poblado):** el SIAGIE dice
    "Se comunica ORALMENTE en inglés…"; SIGA la tenía sin "oralmente" → 0 match
    de texto y, sin `codigo_siagie`, sin fallback por posición → columna 01
    "sin equivalente en SIGA" y las actas 4°A/4°B B1 salieron con Inglés en
    blanco pese a tener notas bloqueadas. Renombrada al nombre oficial CN el
    14/07 (formalizado en la 041); con el código poblado, una discrepancia
    futura se llena por posición en vez de quedar muda.
- **Talleres (Raz. Mat., Pre-Cálculo):** SIN `codigo_siagie` (no tienen hoja en el
  SIAGIE todavía) → nunca son destino. **Diferido:** cuando se aprueben, un
  **selector por nómina** (sin flag persistente) dejará que RA elija incluirlos; y
  se define cómo llegan (hoja propia = trivial por área; área anfitriona = mapeo).
  - Hoy los protegen DOS capas, y conviene saber que la segunda es circunstancial:
    (1) sin `codigo_siagie`, y (2) sus tres competencias son HOMÓNIMAS de las de
    Matemática (C54↔C44, C55↔C47, C56↔C45), así que caen en la rama de ambigüedad y
    el desempate por área las descarta. Si un taller recibiera una competencia con
    nombre ÚNICO en el nivel, el mapeo por texto la tomaría **sin comprobar el área**
    (una sola candidata ⇒ se asigna) y podría colarse en una hoja ajena.

## Excepciones de hoja (27/07/2026)

Casos donde el área que el SIAGIE espera en una hoja **no es la que evalúa esa
competencia en SIGA**. Se aplican DESPUÉS de resolver la hoja y ANTES del mapeo por
leyenda: el texto acertaría el área "oficial" —que no tiene cargas— y dejaría el acta
en blanco. Declaradas en `LlenadorSiagie::EXCEPCIONES_HOJA` (decisión: regla en el
llenador, no tabla de datos — son reglas curriculares estables, no configuración por
bimestre). El reporte marca cada hoja afectada con `[EXCEPCIÓN: …]`.

- **`035-EREL` ← Ética y Valores, TODOS los grados de secundaria.** El área Educación
  Religiosa (id 14, código `035`) tiene 2 competencias y **0 cargas**: nadie la
  califica. Quien evalúa esa dimensión es el tutor, en el área de tutoría cuyo
  `nombre_boleta` es `Ética y Valores` (competencia única, `codigo_minedu` **C57**).
  Su nota **se DUPLICA en las dos columnas** de EREL, con la misma conclusión.
  Exonerados de religión: están registrados contra esa misma área, así que
  `competenciasExoneradas` los detecta solo y la celda sale **EXO** sin traducción.
- **`032-ETRA` ← GAMA, SOLO 5° de secundaria.** En 5° **no se dicta** Educación para el
  Trabajo (0 cargas; sus horas las ocupa el Taller de Pre-Cálculo, que no se reporta
  al SIAGIE por decisión del colegio). Esa acta lleva la competencia transversal
  **GAMA** (`codigo_minedu` **CT4**, *Gestiona su aprendizaje de manera autónoma*):
  promedio final + conclusión del tutor, vía `getTransversalesAgregadas`. En 1°-4° EPT
  se dicta normalmente y la hoja **no se toca**. GAMA queda escrita **dos veces** en
  5°: en su hoja transversal `0007` y en la `032` (comportamiento esperado).
  - **VERIFICADO contra un acta real de 5° (29/07/2026, `S5B.xlsx`, lectura pura del
    zip).** Los tres datos que faltaban:
    1. El libro **sí trae la hoja**, y su tab real es **`032-ETRA`** (no `032-EPT`,
       que era la abreviatura que asumía esta doc). Da igual para el código: la
       excepción matchea por el CÓDIGO del tab (`explode('-', $hoja, 2)[0]` → `032`),
       nunca por la abreviatura.
    2. La hoja tiene **una sola competencia** (columna `01`, con NL + conclusión), así
       que GAMA **no se duplica** aquí — a diferencia de EREL, que tiene 2 columnas.
    3. Su leyenda es **`01 = Gestiona proyectos de emprendimiento económico o social`**,
       es decir la competencia de **EPT (C53, área 15)**, NO la de GAMA. **Por lo tanto
       la excepción es NECESARIA, no solo inocua:** sin ella el mapeo por texto daría
       C53, que en 5° no tiene cargas, y la columna saldría en blanco en silencio.
       (La versión anterior de esta doc contemplaba el escenario inverso —que el SIAGIE
       rotulara la columna con la leyenda de GAMA—; el acta real muestra que no ocurre.)
  - Verificado además que la regla no puede pisar notas reales: EPT (área 15) tiene
    cargas activas en 1°(3), 2°(2), 3°(2) y 4°(2), y **0 en 5°**. Y `codigo_minedu`
    `CT4` resuelve a **una sola** competencia (id 57), así que la excepción se aplica
    y no cae en la degradación segura.
- **⚠️ Nunca anclar por id.** El id **57 es GAMA**, mientras que el código **C57 es
  Ética** (id 127). Las reglas localizan la competencia por `nombre_boleta`
  (constante `AREA_ETICA_NOMBRE_BOLETA`) o por `codigo_minedu`, nunca por id.
- **Degradación segura:** si la competencia de una regla no se identifica de forma
  ÚNICA (área ausente o renombrada, con ≠1 competencia, o `codigo_minedu` duplicado),
  la excepción NO se aplica, la hoja vuelve a su mapeo normal y el reporte emite
  `EXCEPCION NO APLICADA`. Antes escribir una celda en blanco que una nota adivinada.

## Validado (03/07/2026, local, 1°A B1)

472 celdas (440 NL + 32 conclusiones) escritas y verificadas una a una;
literales cruzados contra BD; 22 códigos persistidos; segunda corrida
idempotente (matchea por código, 0 escrituras); protección/merges intactos.

## Estado y pendientes

**B1 cerrado (20/07/2026):** todo el I Bimestre (primaria y secundaria) se subió
al SIAGIE por este flujo sin rebotes. Con eso quedaron superados el piloto de
re-importación, la verificación end-to-end y el reprocesamiento de las actas de
primaria pre-14/07. La discrepancia de Inglés C1 primaria y el poblado de
`codigo_siagie` de primaria se resolvieron antes (14-16/07, migración 041).

**Excepciones de hoja implementadas (27/07/2026):** Ética→EREL (todos los grados de
secundaria) y GAMA→EPT (solo 5°). Verificadas contra la BD con los 10 casos de
frontera (aplica / no aplica por grado, nivel y hoja; primaria intacta).
**Verificación con acta real de 5° HECHA el 29/07/2026** (`S5B.xlsx`): el libro trae
la hoja —tab real **`032-ETRA`**—, con **una sola** columna cuya leyenda es la de EPT
(*Gestiona proyectos de emprendimiento económico o social*), lo que confirma que la
excepción es **necesaria** para que esa columna no salga en blanco. Detalle en la
sección "Excepciones de hoja". Queda como buena práctica correr `--simular` sobre la
primera acta de B2 antes de subirla.

Pendientes vivos (ver `docs/ESTADO.md`): para secundaria, selector de talleres
(diferido); y la aspiración de exportación por lote ("solo subir las nóminas") — hoy
el flujo es una sección por vez.
