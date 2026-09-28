# Módulo AUXILIAR ACADÉMICO

> **ESTADO (28/09/2026, fin del turno tarde): EN CONSTRUCCIÓN en `dev` — NO desplegado.**
> Hechas y probadas: F0, F1, F2a, F2b, F3 y F4a (+ 3 refactors previos a la F4).
> **Sigue la F4b (nómina de docentes).** Pendientes: F4b, F4c, F5, F6, el repaso
> final con todos los roles y el cierre (todo en §7). Merge a `main`: solo cuando el
> usuario lo pida.
> 🔴 **Al desplegar: aplicar A MANO en producción las migraciones `065` y `066`
> ANTES del push a `main`** (ya aplicadas en local).
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
  - `.alert` declara `display:block`: el `hidden` va en un envoltorio sin clase.
  - Los verificadores deben FIJAR su escenario dentro de la transacción: la BD local
    trae asignaciones reales.

## 7. PENDIENTE — lo que falta, en orden

### F4b — Nómina de docentes (SIGUIENTE)
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

### F4c — Planilla de asistencia manual (Excel + PDF)
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

### F5 — Comunicados (D13)
`NotificacionModel`: añadir `ROL_AUXILIAR` a `ROLES_RECEPTORES` (con eso aparece la
campana en su navbar); `DESTINO_AUXILIARES = 'auxiliares'` en `DESTINOS` y su rama en
`destinatariosDeComunicado`. `comunicados.destinos` es `varchar(100)` → sin migración.
Leer antes `docs/modulos/notificaciones.md`.

### F6 — Criterios de conducta por año (D14)
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

### Observación preexistente (decidir con el usuario, no es del módulo)
Si un guardado de asistencia FALLA (403 o sin conexión), `asistencia.js` devuelve los
números al último valor guardado y **se pierde lo que se había tecleado**. Pasa igual en
la grilla desde antes.

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
2. **Producción: migraciones 065 y 066 a mano ANTES del push a `main`.**
3. Crear los usuarios reales de los auxiliares. Datos necesarios: **DNI, apellidos,
   nombres, sexo** (obligatorios), **celular y correo** (recomendados), y la asignación
   de secciones de primaria y secundaria (según el modelo, primaria = 3 auxiliares con 4
   secciones cada uno).
4. Merge `dev` → `main`: **solo cuando el usuario lo pida.**

## 8. Preparado para la asistencia por QR (2027 — NO se construye ahora)

- La asignación auxiliar ↔ sección por bimestre (F1) es exactamente el alcance que
  usará el escáner.
- `inasistencias` sigue siendo el **consolidado oficial** que lee la boleta. En 2027,
  una tabla diaria (`asistencia_diaria`) + `justificaciones` alimentarían esos 4
  contadores por un punto único de escritura (`AsistenciaModel::guardar`), sin tocar la
  boleta ni los cierres.
- El QR del estudiante **NUNCA** reusará `matriculas.token_acceso` (abre la boleta):
  tendrá un identificador propio.
- Registrar estos principios en `docs/decisiones-diferidas.md` al cerrar el módulo.
