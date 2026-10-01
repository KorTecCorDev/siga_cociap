# Módulo AUXILIAR ACADÉMICO

> **ESTADO: DESPLEGADO en v1.0.5 (30/09/2026).** Migraciones `065` a `070` aplicadas a mano
> en producción antes del push. Fases F0–F6, repaso final y seis rondas de ajustes.
> **01/10/2026, desplegado sin cambio de versión:** planilla con siglas F/FJ/T/TJ, nombres
> completos ajustados al ancho y leyenda sin «En blanco = asistió» (§6). Lo que queda tras
> el despliegue está en `docs/ESTADO.md`: usuarios reales de los auxiliares, panel del tutor
> y feriados.
>
> Este doc sustituye al plan que vivía fuera del repo
> (`~/.claude/plans/buen-d-a-en-el-floofy-minsky.md`, solo en la máquina de la
> oficina). **Todo lo necesario para retomar está aquí.** Las decisiones de §2 y §3
> están CERRADAS: no se vuelven a preguntar.

## 1. Qué es

El auxiliar académico registra por bimestre, en las secciones que tiene a cargo:
(1) la **conducta** (10 criterios Sí/No por estudiante) y (2) las **incidencias de
asistencia** (F, FJ, T, TJ). Antes lo tecleaba Registro Académico (RA) a partir de lo
que le entregaban los auxiliares. Ahora cada auxiliar tiene su usuario, registra y
**bloquea** solo SUS secciones (asignación por bimestre), tiene un panel propio y
accede a documentos (nómina, planilla, docentes y horario).

Son usuarios con **poca destreza digital, mayormente en el celular**. Queda
preparado el terreno para la asistencia por QR (2027), sin construirla (§8).

Retoma y cierra el «PLAN — FLUJO PROPIO PARA LOS AUXILIARES» del 25/08/2026
(`docs/ESTADO.md`).

## 2. Decisiones del usuario (28/09/2026 — CERRADAS)

| # | Decisión |
|---|---|
| D1 | El **propio auxiliar** da «Bloquear y aprobar» (etapa 1 de conducta y bloqueo de asistencia). |
| D2 | Asignación **por bimestre**, que se **hereda sola** al siguiente; **un auxiliar por sección**. |
| D3 | **RA conserva** todo lo que hacía (respaldo, cualquier sección). Admin también. |
| D4 | Asignan **admin y RA**. Solo se asigna el **bimestre activo**; los cerrados quedan fijos. |
| D5 | Nómina de matriculados = **la del docente** (N°, nombres, apoderado, celular, tutor), **solo de sus secciones**. |
| D6 | Planilla de asistencia manual = **planilla en blanco por días**, con el formato del modelo `REGISTRO_ASISTENCIA_PRIMARIA_2026.xlsx`; mismo formato en secundaria; días **en blanco O con las fechas hábiles del mes** (elige el auxiliar). |
| D7 | Excel = **plantilla limpia + `app/Siagie/XlsxQuirurgico.php`** (sin dependencias). PDF = layout `print`. |
| D8 | Nómina de docentes: nombres, DNI, celular, correo, áreas y secciones que dicta, tutoría. El auxiliar ve **solo a los docentes de sus secciones**. |
| D9 | La planilla y la nómina de docentes también son para **admin, RA y Dirección** (todo el colegio). |
| D10 | Panel de inicio **al estilo del dashboard docente** (KPIs + cards), con Conducta y Asistencia **por separado**, Nómina de matriculados y documentos. |
| D11 | **Entrada por estudiante** como segunda entrada (convive con la grilla), para **asistencia y conducta**. Como la pantalla es compartida, RA y admin también la tienen. |
| D12 | Firmas en los imprimibles: «Auxiliar Responsable» = el auxiliar asignado en ESE bimestre; «Personal de Registro Académico» = el RA que bloqueó, si fue RA; si no, el único RA activo; si hubiera más de uno, en blanco. |
| D13 | Reciben comunicados (campana) con un **nuevo destino «Auxiliares académicos»**. |
| D14 | Criterios de conducta **versionados por año**, como **fase final** de este plan. |
| D15 | El usuario saca del repo el xlsx con datos reales; Claude no lo toca. |
| D16 | El auxiliar ve el **horario de cada sección a su cargo**. |

**Técnicas (aprobadas con el plan):**
- **T1 — reusar las pantallas de RA** (`/admin/conducta`, `/admin/asistencia`), sin
  controladores paralelos. Si se mueve una vista, se mueve para todos.
- **T2 — tabla «desde bimestre»**: `auxiliar_secciones(seccion_id, desde_periodo_id,
  auxiliar_id NULL, asignado_por, asignado_en, UNIQUE(seccion_id, desde_periodo_id))`.
  El vigente en P es la fila de mayor `periodos.numero` ≤ P del mismo año.
  `auxiliar_id NULL` = «sin auxiliar desde este bimestre».
- **T3 — punto único del permiso**: `AuxiliarSeccionModel::puedeRegistrar(usuario,
  seccionId, periodoId)` (admin/RA → siempre; auxiliar → solo si es el vigente de ESA
  sección en ESE periodo; cualquier otro rol → nunca). Constante `ROL_AUXILIAR` en
  `helpers.php`: nunca se escribe el código del rol a mano.

## 3. Decisiones extra (28/09/2026 — CERRADAS)

- **Auxiliar que es apoderado:** se PERMITE asignarle la sección de su hijo/a, pero
  `/admin/auxiliares` lo AVISA (`seccionesConHijos`).
- **Nuevo usuario con un DNI ya registrado** (p. ej. un apoderado): reutiliza la
  persona y jala sus datos; solo se completan los campos vacíos (`crearSobrePersona`).
- **Pruebas:** POR FASE (cortas, con las dos ramas de cada guarda) + UN repaso final
  con todos los roles. El **bloqueo REAL en pantalla + las firmas del imprimible** se
  prueban en el repaso final (hoy solo están probados por consola con rollback).
- **F2b (entrada por estudiante):** solo sirve para REGISTRAR. Si la sección está
  bloqueada, no hay bimestre editable o se pide un historial, devuelve a la grilla
  con aviso. «Guardar y siguiente» = siguiente de la lista alfabética; tras el
  último, vuelve a la grilla. Acceso: botón en la CABECERA de cada grilla (NO se toca
  `_tabla-incidencias.php`, compartido con Dirección). Conducta incluye «Marcar Sí»
  reusando `autollenarSi()`.
- **F3 (panel):** saludo + KPIs + cards Conducta y Asistencia por sección + aviso si
  no hay secciones. Asistencia tiene **color propio** `$card-asistencia-*` (oliva
  `#4d7c0f`), también en el hub de `/director/bloqueos` (antes tomaba prestado el
  naranja de Nómina). **Semáforo por sección:** ámbar «Registrados X de N» /
  «Listo para bloquear» (conducta completa), verde «Bloqueada el dd/mm/aaaa», gris
  = no puede actuar (fuera de plazo / sin estudiantes).
- **F4 se divide** en F4a (nómina de matriculados + horario) → F4b (nómina de
  docentes) → F4c (planilla), cada una probada antes de la siguiente.
- **Card «Nómina de matriculados»** (naranja): una fila por sección con dos acciones,
  «Nómina» y «Horario» (precedente: Mérito y Ranking cuelgan de la card Nómina del
  docente). Se rotula **«matriculados»**, no «estudiantes» (ver §6).
- **Planilla (F4c), formato ya decidido:** fila 9 = SEMANA 1..5 (siempre); fila 10 =
  n.º de día (`%02d`); fila 11 = «L · Mar. · Mié. · J · V». SEMANA 1 = la del primer
  día hábil del mes. Se numera **TODO el mes** (no se recorta al bimestre). «MES: X»
  en **N6** (negrita). **C4** (título + año) y **C6** (nivel) están VACÍAS en la
  plantilla porque `XlsxQuirurgico` no sobrescribe celdas con valor. Algoritmo en §7.
- **Acceso de padres (v1.1):** fuera de este plan; anotado aparte.
- **F4c (planilla de asistencia manual), 28/09/2026 — MODIFICA D6 y D9:** la planilla
  (Excel y PDF) es **SOLO para admin, RA y Dirección**. El auxiliar **NO** la tiene
  (ni card en su panel ni por URL → 403): se busca que registre en el sistema y deje
  poco a poco el papel; si la necesita, la pide a los directivos. Además: tutor y
  auxiliar vigente van **bajo BIMESTRE** (AD5–AD6 + valor al lado, celdas nuevas); en
  modo «en blanco» las filas 10–11 y «MES:» quedan **vacías**; los meses que se ofrecen
  son **los del bimestre activo**; se elige en una **página de selección**
  `/documentos/planilla-asistencia` (sección, modo, mes, PDF/Excel) con card en el
  dashboard.
- **F4c — REDISEÑO de la planilla (28/09/2026, tras ver la primera versión).** Objetivo
  del usuario: máxima calidad de presentación en PDF y Excel y **comodidad para
  escribir a mano** (letra no pequeña). PDF y Excel con el MISMO diseño:
  - **Encabezado:** recuadro institucional (escudo + UNASAM, lema, colegio) a la
    izquierda y título + nivel centrados; debajo, una **franja de casillas a lo ancho**:
    GRADO Y SECCIÓN · BIMESTRE · MES · TUTOR(A) · AUXILIAR (rótulo pequeño, valor
    11 pt en negrita; casilla vacía = se escribe a mano; en blanco, MES vacío).
  - **Filas según la sección:** estudiantes + hasta 3 libres, y el alto se ajusta para
    llenar la hoja (tope 9 mm); siempre UNA hoja.
  - **Totales con las siglas DEL SISTEMA: F · FJ · T · TJ** (30/09/2026; deroga la
    F · J · T · U del SIAGIE del 28/09). El papel se transcribe en el sistema, que usa
    F/FJ/T/TJ desde la asistencia por fechas, y la «J» chocaba con la de jueves en la
    cabecera de días. ~~**Asistió = casilla en blanco.**~~ **Derogado el 01/10/2026:** la
    leyenda ya NO dice «En blanco = asistió». Cada auxiliar o miembro de la comunidad
    educativa llama lista a su manera, y la planilla no impone una regla para marcar la
    asistencia. La leyenda va impresa bajo la grilla, solo con las 4 siglas.
    PUNTO ÚNICO: `PlanillaAsistenciaModel::LEYENDA`. El PDF la lee directo; en el Excel,
    las celdas `AC8:AF8` y `A46` están VACÍAS en la plantilla (como `L1`/`L3`) y
    `generarExcel()` las escribe desde la constante: `XlsxQuirurgico` no sobrescribe
    celdas con valor, a propósito. Protegido en `verif_rol_auxiliar.php`.
  - **Días que no son del mes** (modo con fechas): sombreados en gris claro.
  - **Sin marca de agua.** Pie: código CAVVG/HZ/año · lema · fecha de impresión.
  - **Excel:** se REDISEÑA la plantilla (con Excel, por automatización) con 35 filas; el
    generador fija el alto de fila y oculta las sobrantes.
- **F4b (nómina de docentes), 28/09/2026:** admin, RA y Dirección entran por una
  **card propia** del dashboard («Nómina de docentes», icono `folder-2.svg`); el
  documento va **por nivel** (bloques Primaria/Secundaria, alfabético dentro de cada
  uno, filtro Todos/nivel; un docente de los dos niveles sale en ambos bloques con las
  cargas de cada uno). El **DNI solo para admin, RA y Dirección**. El auxiliar tiene
  **card propia** (índigo `$card-docentes-*`, mismo icono) y ve a los docentes que
  dictan o son tutores en sus secciones, **con TODAS sus cargas**. Áreas con subárea
  se imprimen **«Área (subárea, subárea)»**; las secciones con las mismas áreas se
  juntan en una línea. La carga **TOE no se lista** como área (la cubre la columna
  Tutoría: toda carga TOE es del tutor, lo vigila `verif_rol_auxiliar`).

## 4. Qué está construido (por fase, con commits en `dev`)

| Fase | Commit | Qué |
|---|---|---|
| F0 | `dcd2c9e` | Plantilla limpia `resources/plantillas/registro-asistencia.xlsx` (aprobada por el usuario). |
| fix | `2d01a15` | Desactivar/trasladar a un estudiante solo apaga las cuentas con rol `padre` (antes apagaba las de 3 docentes apoderados). `verif_desactivar_solo_padre`. |
| F1 | `27e294e` | Migraciones **065** (rol) y **066** (`auxiliar_secciones`), `ROL_AUXILIAR`, `AuxiliarSeccionModel`, `/admin/auxiliares` (admin + RA), aterrizaje en `/auxiliar/inicio`, reutilización de persona por DNI. |
| F2a | `208b75e` | El auxiliar entra a `/admin/conducta` y `/admin/asistencia`; **cada método** pasa por `puedeRegistrar`; `ConductaController::guardar` exige el roster (faltaba desde siempre); firmas D12 vía `firmasDelRegistro` (traza con el rol REAL). |
| F2b | `48f7731` | `GET /admin/{conducta,asistencia}/{id}/estudiante`; `registro-estudiante.js` (sin fetch propio: llama al `guardarFila` global); `_registro-estudiante.scss`; `asistencia.js::guardarFila` devuelve true/false. |
| F3 | `2b7a5f9` | `Auxiliar\PanelController::inicio` + `auxiliar/inicio.php`; token `$card-asistencia-*`; textos de los índices para el auxiliar sin secciones. |
| refactor | `3973107` | KPI «días para el cierre» en el parcial `shared/_kpi-dias-cierre.php` (docente + auxiliar). |
| refactor | `04be180` | Las 5 copias a mano del filtro de retorno (híbrido) → `matricula_documento()` + sección 6 de `verif_matricula_documento`. |
| refactor | `27871c9` | `NominaModel` (nómina de matriculados) y `HorarioModel::documentoSeccion` (horario de sección), extraídos de los controladores docente y de Dirección. A/B: 72 documentos idénticos. |
| F4a | `d11ccc1` | `/auxiliar/nomina/{seccion}/imprimir` y `/auxiliar/horario/{seccion}` + card «Nómina de matriculados» en el panel. |
| F4b | `bae54d3` | `NominaDocenteModel` + `Documentos\DocumentoController::nominaDocentes` + cards (dashboard y panel del auxiliar). |
| F4c | (2 commits tras `bae54d3`) | `XlsxQuirurgico` (alto/ocultar filas, pie, lectura de filas autocerradas) y la planilla: `PlanillaAsistenciaModel`, plantilla rediseñada, selección + PDF + Excel, card del dashboard (solo admin/RA/Dirección). |

**Mapa de archivos del módulo:**
- BD: `database/migrations/065_rol_auxiliar_academico.sql`, `066_auxiliar_secciones.sql`.
- Modelos: `AuxiliarSeccionModel` (vigentesSql · vigente · seccionesDe · puedeRegistrar
  · listarParaPeriodo · seccionesConHijos · asignar · firmasDelRegistro), `NominaModel`,
  `HorarioModel::documentoSeccion`.
- Controladores: `Admin\AuxiliarController` (asignar), `Admin\{Conducta,Asistencia}Controller`
  (registro + estudiante), `Auxiliar\PanelController` (inicio · nominaImprimir · horario ·
  `exigirSeccionPropia`).
- Vistas: `admin/auxiliares/index.php`, `admin/{conducta,asistencia}/estudiante.php`,
  `auxiliar/inicio.php`, `shared/_kpi-dias-cierre.php`. Reutiliza sin cambios
  `docente/nomina-imprimir.php` y `director/horario-seccion.php`.
- JS: `auxiliares.js`, `usuario-dni.js`, `registro-estudiante.js`.
- SASS: `pages/_registro-estudiante.scss`; bloques en `pages/_docente-panel.scss`
  (`.dpanel-card--asistencia`, `.aux-secciones`, `.aux-seccion*`, `__accion--horario`),
  `pages/_admin.scss` (avatar y hub de bloqueos), `components/_cards.scss`
  (`badge--ident-auxiliar`), `base/_variables.scss` (`$card-asistencia-*`).
- Verificadores: `verif_rol_auxiliar.php` (el principal: rol, rutas, CSS, guardas por
  método, reuso sin copias, `puedeRegistrar` en sus dos ramas, herencia, asignación,
  firmas), `verif_usuario_reusa_persona.php`, `verif_desactivar_solo_padre.php`; se
  ajustaron `verif_asistencia_partial_compartido.php` (método `estudiante` restringido a
  quien registra) y `verif_matricula_documento.php` (§6). **Batería: 50/50.**

**Rutas del módulo:** `GET /admin/auxiliares` · `POST /admin/auxiliares/{seccion_id}/asignar`
· `GET /auxiliar/inicio` · `GET /auxiliar/nomina/{seccion_id}/imprimir` ·
`GET /auxiliar/horario/{seccion_id}` · `GET /admin/{conducta,asistencia}/{id}/estudiante`.

## 5. Datos de prueba en local (oficina)

- Auxiliares: **47** ESPINOZA PARDAVÉ, Margarita (DNI 40367535) → primaria 1.° A, 1.° B,
  2.° A, 2.° B (secciones 1–4) desde el III Bimestre; **48** BRUNO TORRES, Mila → secundaria
  1.° A, 1.° B, 1.° C, 2.° A, 3.° A, 3.° B (13–16, 18, 19); **65** LOPEZ, Edgar → **sin
  secciones** (sirve para probar la rama vacía).
- Bimestre activo: III (id 3), límite `2026-10-16`. 1 solo RA activo (id 40), admin id 1.
- ⚠️ La BD de casa puede no tener estos usuarios ni las migraciones 065/066: comprobarlo
  antes de probar (ver la memoria «medir contra copia fresca»).

## 6. Reglas y trampas descubiertas (no obvias en el código)

- **El panel enlaza cada sección con `?periodo=` explícito.** Sin él, con el bimestre
  fuera de plazo la grilla no tiene periodo que mostrar y le da 403 al auxiliar; con él,
  abre en solo lectura.
- **«Estudiantes a mi cargo» ≠ «matriculados».** El KPI cuenta el roster de evaluación
  (`roster_evaluacion()`, incluye `pendiente`); la card Nómina cuenta la NÓMINA (solo
  `aprobada`, excluye solo `trasladado`, así que un `retirado` sí figura). Pueden diferir
  por sección (medido: 20/19, 18/19). Es correcto y está rotulado distinto a propósito.
- **Nunca identificar niveles por id** (`[1, 2]` a mano): salen de las propias secciones.
  Lo vigila `verif_rol_auxiliar`.
- **Firmas D12** salen de `AuxiliarSeccionModel::firmasDelRegistro` (punto único), con
  el rol REAL de quien bloqueó.
- **El «híbrido» de retorno de grado ya no existe en `app/`** (commit `04be180`): toda
  exclusión de la operativa usa `matricula_documento()`. La sección 6 de
  `verif_matricula_documento` barre el código. Ver `docs/modulos/retorno-grado.md`.
- **Entorno local:**
  - **BrowserSync REPITE los clics** en todas las pestañas abiertas en la misma página
    (ghost mode): en local, un clic en «Nómina» parece abrir dos ventanas. No es un bug
    (comprobado con `window.opener.name`); en producción se abre una.
  - En la máquina de la oficina **no existe `storage/firmas/`**: el sello del Director
    EBR sale roto (404) en TODO documento y para todos los roles. Es del entorno.
  - **Muchos archivos son CRLF**: `Edit` los respeta; con scripts, revisar antes.
  - `$this->input()` es solo POST; para GET, `$this->query()`.
  - **XAMPP trae `;extension=zip` COMENTADO** en `C:\xampp\php\php.ini` (el mismo que
    usa Apache): sin él no se genera ningún xlsx en local (planilla ni Actas SIAGIE).
    `verif_rol_auxiliar` marca SKIP; para probar el Excel por consola:
    `php -d extension=zip database/verificaciones/verif_rol_auxiliar.php`.
  - `XlsxQuirurgico` guarda su copia junto al archivo que abre (`ruta.tmp_siagie`):
    **nunca abrir la plantilla directamente**, siempre una copia única en temporales.
- **Plantilla de la planilla (rediseño 28/09/2026) — trampas al construirla con Excel:**
  - Columnas: A = N° · B:C = nombres (combinadas por fila) · **D..AB = 25 días** ·
    AC..AF = F J T U · AG = N°. Filas: 1-3 encabezado · 5-6 franja (valores en A6, C6,
    D6, K6, Y6) · 8-10 cabecera · **11-45 estudiantes** · 46 leyenda. Título en L1,
    nivel en L3. (El algoritmo de días de §7 sigue valiendo; cambian las columnas.)
  - **Excel imprime las columnas estrechas más anchas de lo que miden en pantalla**:
    sin calibrar, la hoja salía al 86 % (letra pequeña). Los anchos se construyeron
    con un factor 0,84 y se comprobó que imprime al **100 %** (zoom máximo que cabe
    en 1 página, medido por COM). Si se cambian anchos, volver a medir.
  - **Por COM, un Excel en español interpreta los códigos del pie en su idioma**:
    `&D` (fecha) se perdía. El pie se escribió directamente en el XML con los códigos
    estándar (`&L`, `&C`, `&R`, `&D`).
  - Las filas espaciadoras vacías (4 y 7) quedan como `<row .../>` autocerrado: hizo
    aflorar un defecto de `XlsxQuirurgico::leerCeldas()` (se comía la fila siguiente),
    ya corregido.
  - El formato condicional del gris va sin funciones (`=($D$6<>"")*(D$9="")`): con
    `Y()`/`AND()` depende del idioma de Excel.
  - El PDF (`_planilla-asistencia.scss`) repite estas medidas; su alto de fila sale de
    `PlanillaAsistenciaModel::altoFilaMm()` en décimas (`planilla-print--alto-N`).
  - **Nombre que no cabe — ESTUDIANTES (decisión del usuario, 01/10/2026; deroga la
    abreviatura del 28/09):** salen **completos, nunca abreviados**, con letra menor.
    Excel: la celda trae «reducir hasta ajustar». PDF: **la letra MÁS GRANDE que
    cabe** (pedido del usuario, 01/10/2026), medida en el ancho real
    (`data-ajustar-texto="9"` → `print-fit.js`). La búsqueda es binaria, al décimo de
    punto, con tope en la base de 9 pt (los nombres cortos NO crecen), mínimo de 6 pt
    y 1 px de holgura para que el redondeo de la impresión no recorte la última letra.
    El escalón por largo (> `MAX_NOMBRE` 36 → 8 pt · > 42 → 7,5 · > 46 → 7) queda
    solo como respaldo sin JS. Contar caracteres no basta: nombres del mismo
    largo necesitan letras distintas (con 44, JACHILLA entra en 8 pt y ALMENDRADES
    necesita 7,5). Medido el 01/10 en la BD local: 29 de 523 pasan de 36 y el más
    largo tiene 47 (entra en 7 pt).
  - **Nombre que no cabe — FRANJA (tutor y auxiliar, 28/09/2026, sigue vigente):**
    apellidos + primer nombre + inicial del segundo («SANTAMARIA RODRIGUEZ,
    JAKELINE E.»). Punto único `PlanillaAsistenciaModel::nombreQueCabe()`, con topes
    `MAX_TUTOR` 32 · `MAX_AUXILIAR` 26. Si aun así no cabe, se reduce la letra. En el
    PDF se usa el mismo ajuste exacto, con tope en 11 pt (`data-ajustar-texto="11"`);
    en el Excel, «reducir hasta ajustar». 🔴 **Hasta el 01/10
    el PDF la CORTABA** con puntos suspensivos: «PALOMINO VILLANUEVA, ROG…», visto en
    producción. Con 11 pt la casilla AUXILIAR solo admite unos 25 caracteres. La casilla
    ya no lleva `text-overflow: ellipsis`.
  - `.alert` declara `display:block`: el `hidden` va en un envoltorio sin clase.
  - Los verificadores deben FIJAR su escenario dentro de la transacción: la BD local
    trae asignaciones reales.

## 7. PENDIENTE — lo que falta, en orden

### F4b — Nómina de docentes (HECHA 28/09/2026; decisiones en §3)
Construida: `NominaDocenteModel`, `Documentos\DocumentoController::nominaDocentes`,
`GET /documentos/nomina-docentes[?nivel=]`, `documentos/nomina-docentes.php`, cards en el
dashboard y en el panel del auxiliar. Lo de abajo es el plan original, ya resuelto.
**Qué pide el plan (D8, D9):** `/documentos/nomina-docentes` (PDF, layout `print`) con
docente, DNI, celular, correo, áreas × secciones (desde `cargas_academicas` activas) y
tutoría (`secciones.tutor_id`). Auxiliar: solo los docentes con carga o tutoría en SUS
secciones; admin, RA y Dirección: todos. Un controlador `Documentos\DocumentoController`
para la nómina de docentes y la planilla, con guardas por método.

**Datos ya verificados (28/09):** el celular está en `personas.telefono` y el correo en
`personas.correo` (NO existe `email`); los **35 docentes activos tienen los dos
completos**.

**Preguntas que hay que hacerle al usuario ANTES de codificar (no están decididas):**
1. **Por dónde entran admin, RA y Dirección** a la nómina de docentes y a la planilla
   (D9): ¿cards nuevas en `dashboard/index.php`? Cada card necesita un **icono propio**
   (regla: dos cards nunca comparten icono; lo vigila `verif_direccion_superficies`).
2. **Orden y agrupación** del documento: ¿alfabético por docente? ¿agrupado por nivel?
   ¿cómo se listan las áreas × secciones de un docente con muchas cargas?
3. **Filtro** para admin/RA/Dirección: ¿todo el colegio de una vez o por nivel?
4. **El DNI** es un dato sensible (la nómina de matriculados lo omite a propósito):
   confirmar que va en la de docentes (D8 dice que sí) y para qué roles.
5. En el panel del auxiliar: ¿card propia «Nómina de docentes» (icono y color nuevos) o
   acción dentro de otra card?

### F4c — Planilla de asistencia manual (Excel + PDF) (HECHA 28/09/2026; decisiones y rediseño en §3, trampas en §6)
Construida: `PlanillaAsistenciaModel` (días, meses del bimestre, cabecera, `generarExcel`
sobre una COPIA de la plantilla), `Documentos\DocumentoController::planillaAsistencia`
(selección) y `::planillaGenerar`, `GET /documentos/planilla-asistencia[/generar]`, vistas
`documentos/planilla-asistencia{,-imprimir}.php`, `planilla-asistencia.js`,
`pages/_planilla-asistencia.scss`, card en el dashboard (`folder-check.svg`). Solo admin,
RA y Dirección. `XlsxQuirurgico` ganó dos añadidos opcionales (estilo de una celda
modelo al crear una celda; reemplazo acotado al pie). Lo de abajo es el plan original.
**Qué pide el plan (D6, D7, D9):**
`/documentos/planilla-asistencia/{seccion}?modo=blanco|mes&mes=N&formato=pdf|xlsx`.
Cabecera del modelo (UNASAM, colegio, nivel, grado, sección, bimestre, tutor, auxiliar
vigente), N°, apellidos y nombres (`roster_evaluacion` + `orden_alfabetico`), 25
columnas de día, F/FJ/T/TJ, N° repetido y pie con código y fecha. PDF = vista `print`
A4 horizontal; Excel = plantilla F0 + `XlsxQuirurgico::escribir()`. La plantilla trae
**30 filas** (la sección más grande hoy tiene 28): si un roster las supera, el Excel NO
trunca, **avisa**; el PDF no tiene límite. Acceso: auxiliar (sus secciones), admin, RA
y Dirección.

**Algoritmo de días (prototipado y verificado 2026–2040):**
```php
const COLUMNAS_DIA = ['F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T',
                      'U','V','W','X','Y','Z','AA','AB','AC','AD'];
const ABREV = [1 => 'L', 2 => 'Mar.', 3 => 'Mié.', 4 => 'J', 5 => 'V'];
function diasPlanilla(int $anio, int $mes): array {
    $dia = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
    while ((int) $dia->format('N') > 5) $dia = $dia->modify('+1 day');      // 1.er día hábil
    $lunes1 = $dia->modify('-' . ((int) $dia->format('N') - 1) . ' days');  // lunes de SEMANA 1
    $out = [];
    for ($d = $dia; (int) $d->format('n') === $mes; $d = $d->modify('+1 day')) {
        $n = (int) $d->format('N'); if ($n > 5) continue;
        $semana = intdiv((int) $lunes1->diff($d)->days, 7) + 1;              // nunca > 5
        $out[] = ['semana' => $semana, 'indice' => ($semana - 1) * 5 + ($n - 1),
                  'dia' => (int) $d->format('j'), 'abrev' => ABREV[$n]];
    }
    return $out;   // columna = COLUMNAS_DIA[indice]
}
```
Plantilla: columnas de día F..AD (25) = 5 semanas × (L, Mar., Mié., J, V); fila 9
«SEMANA n» (ya viene en la plantilla), fila 10 número, fila 11 abreviatura; «MES: X» en
N6; «REGISTRO AUXILIAR DE ASISTENCIA - {año}» en C4 y «NIVEL {PRIMARIA|SECUNDARIA}» en C6.

**Antes de codificar:** revisar la plantilla celda por celda (filas de estudiantes,
dónde van N°/nombres/tutor/auxiliar/pie) y **preguntar** lo que no esté decidido: en
`modo=blanco`, ¿las filas 10–11 van vacías?; ¿qué meses se ofrecen (los del bimestre)?;
el nombre del archivo; el diseño del PDF. Luego, la card «Planilla» en el panel.

### F5 — Comunicados (D13) (HECHA 28/09/2026)
Hecho tal como se planeó abajo; `verif_notificaciones` crea un auxiliar de prueba en su
transacción y prueba el destino en sus dos ramas (activo recibe, inactivo no) y que
«Todos los docentes» no lo incluye.
`NotificacionModel`: añadir `ROL_AUXILIAR` a `ROLES_RECEPTORES` (con eso aparece la
campana en su navbar); `DESTINO_AUXILIARES = 'auxiliares'` en `DESTINOS` y su rama en
`destinatariosDeComunicado`. `comunicados.destinos` es `varchar(100)` → sin migración.
Leer antes `docs/modulos/notificaciones.md`.

### F6 — Criterios de conducta por año (D14) — HECHA (28/09/2026)
Construido: migración **067** (`anio_id` sembrado por el año de las respuestas,
`modificado_en/_por`), punto único `ConductaModel::criteriosDelAnio()` (las 4 subconsultas
de completitud toman el año de la SECCIÓN; `getCriterios`/`totalCriterios` aceptan un año,
por defecto el activo; la consulta de notas pasa el año DEL PERIODO porque abre años
cerrados), `CriterioConductaModel` (todas las reglas en `validarEdicion`),
`Admin\CriterioConductaController` (guardas por método), `/admin/conducta/criterios`
(rutas ANTES de `/admin/conducta/{id}`), botón «Criterios» en `/admin/conducta`, card de
Dirección, aviso en conducta si el año no tiene criterios. Verificador
`verif_criterios_conducta_anio.php` (35 comprobaciones). A/B de `ConductaModel` antes y
después: 249 consultas idénticas.
**Observación preexistente (no es de la F6):** `_auth.scss` deja a `.form-input` un
`padding-left: 40px` GLOBAL (el hueco del icono del login); en esta pantalla se corrige de
forma local. Probablemente afecta a otros formularios (p. ej. el mensaje de comunicados).

**Decisiones cerradas (28/09/2026):**
- **Alcance:** versionado de datos (`anio_id`) + **pantalla de gestión**.
- **Editan admin y RA**; **Dirección los ve en solo lectura**.
- **Con respuestas registradas en ese año, solo se corrige la redacción**: no se
  agregan, retiran, reordenan ni cambian de nivel. Sin respuestas, edición completa.
- **Se registra quién y cuándo corrigió** (`modificado_en`, `modificado_por`), visible.
- **Entrada:** botón «Criterios» en la cabecera de `/admin/conducta` (admin/RA) y card
  propia «Criterios de conducta» en el dashboard de Dirección.
- **Año nuevo:** botón «Copiar del año anterior» en un año sin criterios; mientras no
  tenga, la pantalla de conducta lo avisa.
- **Nivel por criterio:** Ambos / Primaria / Secundaria (la columna `nivel_id` ya existe).

Lo que sigue es el análisis previo:
Hoy `criterios_conducta` no tiene año, y el «vigente» (`eliminado_en IS NULL`) está
copiado en ~6 consultas de `ConductaModel`: borrar un criterio alteraría la completitud
de bimestres pasados. Diseño previsto: `anio_id` en `criterios_conducta` (backfill 2026)
y un punto único `ConductaModel::criteriosDelAnio` por el que pasen todas las consultas.
**Antes de empezar, hacer SUS preguntas**: quién edita los criterios, si hace falta una
pantalla de gestión, copia desde el año anterior. Hoy no existe UI para ellos.

### Textos que aún dicen «Registro Académico» donde ahora puede actuar el auxiliar
Revisarlos en el repaso final (medido el 28/09, sin tocar):
`Docente\ConductaTutorController` (≈ líneas 128 y 187: «cuando Registro Académico
bloquee…»), `Director\BloqueoController` (≈ 886: «Registro Académico puede corregir y
volver a bloquear»), `ConductaModel` (≈ 497). Decidir con el usuario el texto neutro.

### Observación preexistente — RESUELTA el 29/09/2026 (sin commit aún)
Si un guardado de asistencia FALLABA (403 o sin conexión), `asistencia.js` devolvía los
números al último valor guardado y se perdía lo tecleado. **Decisión del usuario:
mantener lo tecleado y marcar la celda en error.** Hecho: `marcarErrorFila()` marca en
rojo (`.asistencia-input--error`) las celdas que no se guardaron; la fila sigue ámbar;
teclear de nuevo quita el rojo y el aviso de la fila. Probado en grilla y por estudiante
(sin conexión simulada, rechazo simulado y guardado real).

### Repaso del 29/09/2026 — ajustes pedidos por el usuario (sin commit aún)
Hecho y probado en el navegador:
- **Conducta, botón Sí/No (4.2):** la opción elegida se «camuflaba» (el `:hover` (0,3,0)
  le ganaba al `--activo` (0,2,0): fondo gris con texto blanco), y en el celular el hover
  queda pegado tras tocar. Ahora el hover no toca al activo y solo existe con
  `@media (hover: hover)`.
- **Grilla de conducta (3.2):** sin resaltado de fila al pasar el cursor (anulado SOLO en
  `.conducta-grilla`; el `tr:hover` global de `.tabla-notas` sigue en las demás grillas).
- **Vista por estudiante, conducta y asistencia (4.1):** chip «N.° n» (= N° de la grilla)
  junto al nombre y, en conducta, la nota con su literal en grande y con color.
- **Nómina de docentes (6):** **A4 VERTICAL** (solo este documento; horario y planilla
  siguen horizontales, decisión del usuario). Cada docente es una ficha de dos filas:
  contacto arriba; abajo la **TUTORÍA primero** (rótulo + barra, naranja oscuro) y luego
  las cargas con **cada sección en su recuadro, nombrada entera** (nunca «1.° A-B»: el
  usuario no quiere que se confunda la sección). Regla de blanco y negro: el color nunca
  es la única señal; bordes y texto, no fondos. El auxiliar ve en la cabecera «Docentes de
  tus secciones» en vez de «Todos los niveles». `NominaDocenteModel` devuelve listas
  (`areas`, `secciones`, `tutoria`) en vez de texto unido.

Hecho, **falta verlo con sesión** (la sesión de Chrome se cerró):
- **Panel del auxiliar (5):** la card «Nómina de matriculados» pasa a UNA línea por
  sección, como Conducta y Asistencia; los botones Nómina/Horario quedan lado a lado
  (`nowrap`) y lo que se acomoda en una card angosta es el texto del nombre. El usuario
  rechazó el scrollbar.

### 🔴 DECIDIDO, SIN IMPLEMENTAR — Conducta con autoguardado + «Confirmar» (B2, 29/09/2026)
El usuario eligió **B2**: el mismo flujo de los docentes (autoguardado de cada ✓/✗ como
BORRADOR + botón **Confirmar** por estudiante; editar desconfirma; «Bloquear y aprobar»
exige todos confirmados). Motivo del usuario: poder cambiar este módulo a futuro sin
problemas, con foco en el uso y procesamiento correcto de los datos.

**Por qué no es solo de botones:** hoy ninguna marca dice qué es oficial; todas las
lecturas asumen «si hay alguna respuesta, la nota es Sí ÷ total × 20», cierto solo porque
el guardado exige los 10 criterios. Con borradores, 4 «Sí» darían 8 (C) en boleta.
Medido: II Bimestre 524/524 completos, III 42/42, 0 a medias, 0 a criterios retirados.

**Diseño propuesto (esperando 4 respuestas del usuario antes de codificar):**
- Migración 068: tabla `conducta_confirmaciones` (matricula_id, periodo_id,
  confirmado_en, confirmado_por; UNIQUE por estudiante y bimestre). Backfill: se
  confirma todo estudiante-bimestre con exactamente sus N respuestas vigentes; los
  incompletos NO se confirman y se informan. En prod, ANTES del merge.
- Punto único de lectura «confirmadas» en `ConductaModel` para: `getParaBoleta(Union)`,
  `getParaPeriodo`, `getEstudiantesParaTutor`, `completitudSeccion`,
  `getProgresoConductaPorSeccion`, `getDistribucionLiteralesAnual`,
  `getIncumplimientoCriterios`, `sqlAdmiteExtraordinaria`,
  `registrarLiteralExtraordinario`, y `getEstudiantesParaRegistro` cuando lo usan las
  grillas de SOLO LECTURA (tutor `ConductaTutorController:133`, Dirección
  `ConsultaNotasController:492`). Existencia (cualquier fila): `tieneRespuestas`,
  `getRegistroLegado`.
- `RetornoGradoController:188` debe mover también `conducta_confirmaciones`.
- Escritura: tocar ✓/✗ = upsert de UNA respuesta + borrar confirmación (misma
  transacción); Confirmar = las 10 respuestas + confirmación en una transacción; Marcar
  Sí = guarda borrador. Mismas guardas de hoy (puedeRegistrar, periodo editable, no
  bloqueada, roster, criterio vigente del año y nivel).
- Verificador nuevo con las dos ramas (borrador NO aparece en boleta/cuadros/tutor/
  completitud; confirmado SÍ). Invariante nuevo en CLAUDE.md.

**Respuestas del usuario (29/09/2026, CERRADAS):**
1. Lo no confirmado queda «al aire» aunque esté autoguardado: no cuenta en nada. Lo
   confirmado se guarda y, al bloquear y aprobar (también si se FUERZA el bloqueo), se
   vuelve oficial y visible. La vía extraordinaria mira solo lo confirmado. **Confirmar es
   el visto bueno de quien registra, no el visto final**: el final sigue siendo aprobar y
   bloquear.
2. Las confirmaciones se mantienen (también al reabrir el director) hasta que se detecta
   un cambio: editar desconfirma.
3. Vista por estudiante: DOS botones, «← Anterior» y «Confirmar y siguiente →» (el
   usuario descartó la variante de tres). En la grilla: «Confirmar todo».
4. **ASISTENCIA TAMBIÉN** lleva autoguardado + «Confirmar», con el mismo flujo.
5. **Bloqueo con pendientes (opción b):** el auxiliar (y RA) necesita el 100 %
   confirmado para «Bloquear y aprobar»; el **director puede forzar** el bloqueo y los
   no confirmados quedan fuera (guion en boleta).
6. **Asistencia en la boleta pasa a exigir el bloqueo**, como conducta: sin cierre
   vigente, guion. (Hoy `getDelBimestre` no mira `cierres_asistencia`: CAMBIA.)
7. Migración **068** escrita y aplicada en la BD local por el usuario (29/09/2026).

Inventario de ASISTENCIA (29/09): `AsistenciaModel` es el único lector de
`inasistencias` (más el traslado de `RetornoGradoController:188`). Oficiales:
`getDelBimestre(Union)`, `getAcumuladoAnual(Union)`, `tieneRegistroUnion` (guion en
boleta), `sqlSinRegistro` + `admiteExtraordinaria` (vía extraordinaria),
`getProgresoPorSeccion`, `getIncidenciasPorSeccion`, `getTopIncidenciasPorSeccion`,
`getEvolucionIncidenciasAnual`. Editor: `getEstudiantesConIncidencias`. ⚠️ Diferencias
con conducta: hoy la asistencia sale en la boleta **sin** exigir el bloqueo
(`getDelBimestre` no mira `cierres_asistencia`), y su bloqueo **no** exige completitud
(sin fila = sin registro = guion).

### Otras decisiones abiertas del repaso del 29/09
- **Textos «Registro Académico»** (punto 2) — ✅ RESUELTO el 29/09/2026. Decisión del
  usuario: los textos que dicen QUIÉN registra o bloquea van en forma **impersonal**
  («cuando se bloquee y apruebe…», «Ya se puede corregir y volver a bloquear»). Si el
  hecho ya ocurrió, se muestra el **nombre real** de quien lo hizo (`ra_nombre`; la vista
  del tutor pasó a `getCierreDetalle`). Así no quedan desactualizados y no se equivocan
  cuando RA bloquea como respaldo o fuerza el bloqueo. SE MANTIENEN los que siguen siendo
  ciertos: asignación de secciones, desbloqueo, correcciones y vía extraordinaria (solo
  RA/admin). Además: la columna Nota dice «Nota del auxiliar», y se pasaron a tuteo, con
  tildes, «Consulte…» (controlador, vista y la copia de `ConductaModel::cerrarTutor`).
- **`.form-input` con 40 px a la izquierda en todo el sistema** — ✅ RESUELTO el
  29/09/2026 con la opción A: el hueco del icono se aplica solo en `.login-form .form-input`.
  El resto del bloque de `_auth.scss` sigue siendo global, así que ningún campo cambia de
  alto. Los parches locales que ya compensaban ese hueco (`_criterios-conducta.scss`)
  quedan redundantes pero no hacen daño.

> 🔜 **PENDIENTE INMEDIATO (desde el 29/09/2026):** el repaso final y el cierre, en forma de
> lista de chequeo, están en `docs/ESTADO.md` → «RETOMAR AQUÍ (30/09/2026)». Empezar por
> ahí; lo de abajo es el detalle.

### Repaso final con todos los roles (antes del merge)
- **Auxiliar:** panel; registrar por grilla y por estudiante en el celular; **bloquear de
  verdad** en pantalla; **imprimible con firmas** (Auxiliar Responsable + RA, D12); los 4
  documentos; horario; sección ajena por URL → 403 en registro, documentos y horario.
- **RA y admin:** siguen viendo y pudiendo todo; asignan en `/admin/auxiliares`; la
  entrada por estudiante también les sirve.
- **Director:** documentos en lectura (D9); el hub de `/director/bloqueos` con Asistencia
  en oliva; puede desbloquear lo que bloqueó un auxiliar.
- **Tutor:** la etapa 2 de conducta sigue intacta tras un bloqueo del auxiliar.
- **Docente:** su panel, su nómina y su horario, sin cambios (hubo refactors: A/B por
  consola ya hecho).

### Cierre del módulo
1. Actualizar este doc (cabecera = desplegado), `docs/modulos/admin.md` (conducta y
   asistencia ahora también las registra el auxiliar), `docs/ESTADO.md` (registro del
   deploy en la sección Git) y `docs/decisiones-diferidas.md` (asistencia por QR 2027,
   §8).
2. **Producción: migraciones 065, 066 y 067 a mano ANTES del push a `main`.** La 067
   cambia consultas de conducta de uso diario (completitud, boleta): sin ella, el código
   nuevo falla al no encontrar `criterios_conducta.anio_id`.
3. Crear los usuarios reales de los auxiliares. Datos necesarios: **DNI, apellidos,
   nombres, sexo** (obligatorios), **celular y correo** (recomendados), y la asignación
   de secciones de primaria y secundaria (según el modelo, primaria = 3 auxiliares con 4
   secciones cada uno).
4. Merge `dev` → `main`: **solo cuando el usuario lo pida.**

## 8. Preparado para la asistencia por QR (2027 — NO se construye ahora)

- La asignación auxiliar ↔ sección por bimestre (F1) es exactamente el alcance que
  usará el escáner.
- `inasistencias` sigue siendo el **consolidado oficial** que lee la boleta. ~~En 2027,
  una tabla diaria (`asistencia_diaria`) + `justificaciones` alimentarían esos 4
  contadores por un punto único de escritura (`AsistenciaModel::guardar`).~~ **Superado
  por la asistencia por fechas (migraciones 069 y 070):** la tabla diaria ya existe
  (`asistencia_incidencias`), el escáner escribiría por `AsistenciaModel::marcarDia` y los
  contadores seguirían saliendo de `recalcularContadores`, sin tocar boleta ni cierres.
- El QR del estudiante **NUNCA** reusará `matriculas.token_acceso` (abre la boleta):
  tendrá un identificador propio.
- **El diseño acordado el 30/09/2026** (presunción invertida, hora límite, cierre del
  ingreso, suplantación) vive en `docs/decisiones-diferidas.md` § «Asistencia por fechas
  — lo que queda para después». Leerlo allí; no se duplica aquí.
