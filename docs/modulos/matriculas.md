# Módulo: Matrículas y apoderados

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.

## Módulo de matrículas (sesión 9)

### Propósito
Registro y gestión integral de matrículas: wizard de 3 pasos (estudiante →
apoderado → documentos), detalle, activación/desactivación, notas externas
para traslados de entrada y retorno de grado (caso especial).

### Migración — `database/migrations/012_modulo_matriculas.sql`
> El spec pedía `009`, pero `009` ya estaba ocupado dos veces
> (`009_inasistencias`, `009_token_acceso_matriculas`). Se usó el siguiente libre.
> Es idempotente (MariaDB 10.4 soporta `ADD COLUMN IF NOT EXISTS` y se usan
> bloques condicionales por `information_schema`).

Cambios sobre el esquema REAL (verificado contra la BD, difiere del spec):
- **roles:** `secretaria` → renombrado a `secretaria_academica` (nombre/descripcion
  actualizados); **nuevo** rol `secretaria_administrativa`. Los FK son por `id`,
  así que renombrar el `codigo` no rompe `usuarios`.
- **matriculas:** se agregaron `tipo ENUM('continuador','nuevo','trasladado')`
  (DEFAULT 'continuador' → las 528 filas existentes quedaron como 'continuador'),
  `serie_recibo VARCHAR(30)`. `anio_id` YA EXISTÍA (no se recreó).
- **matriculas.estado:** el enum real era
  `('registrada','pendiente_documentos','observada','aprobada','retirada')`.
  Se **AMPLIÓ** agregando `'pendiente','activo','desactivado'` que usa este módulo.
  La demo del I Bimestre sigue en `'aprobada'` (intacta). Mapa conceptual del
  módulo: nace `pendiente` → `activo` (al aprobar) / `desactivado` (al trasladar).
- **vinculo_familiar.tipo_vinculo:** el enum real era `('padre','madre','apoderado')`.
  Se amplió a los **14 tipos** (sin tildes en BD: `tio`,`tia`,…; la vista los
  muestra con tilde). Conserva el UNIQUE `(estudiante_id, tipo_vinculo)`.
- **boletas_publicas:** se agregó `activa TINYINT(1) DEFAULT 1` (para 7.3).
- **matriculas — UNIQUE relajado:** `uq_estudiante_anio (estudiante_id, anio_id)`
  se reemplazó por un índice NO único `idx_estudiante_anio`. **Por qué:** el
  retorno de grado exige DOS matrículas del mismo estudiante/año (oficial +
  operativa). La protección anti-duplicados del flujo normal se mantiene a nivel
  de aplicación (`MatriculaModel::existeMatricula()` + validación en el controlador).

Tablas nuevas: `documentos_matricula` (UNIQUE matricula+tipo_documento),
`notas_externas` (UNIQUE matricula+periodo+competencia), `retornos_grado`.

### Modelos
- **`app/Models/MatriculaModel.php`** — `listar(filtros)`/`contar(filtros)` (con
  paginación por `limit`/`offset`), `findById`, `existeMatricula`, `crear` (siempre
  `estado='pendiente'`), `cambiarEstado` (deja traza en `observaciones`; si `activo`
  setea `aprobado_por`/`fecha_aprobacion`), `sugerirSeccion` (sección con MENOS
  matrículas activas del grado), `seccionAnioAnterior` (para continuador),
  `crearEstudianteConPersona` (reutiliza persona/estudiante si el DNI ya existe),
  documentos (`getDocumentos`, `registrarDocumento` idempotente con ON DUPLICATE),
  notas externas (`getNotasExternas`, `registrarNotaExterna`), y auxiliares de
  filtros (`listarAnios`, `listarGrados`, `listarSecciones`).
- **`app/Models/ApoderadoModel.php`** — `buscarPorDni` (incluye sus vínculos),
  `findById`, `crear` (persona+apoderado en transacción; reutiliza por DNI),
  `vincularEstudiante` (idempotente por el UNIQUE), `getHijos(apoderadoId, anioId)`,
  `contarHijosActivos` (regla máx 3), `getVinculos(estudianteId)`,
  `desactivarUsuarioDeEstudiante` (apaga el login del apoderado al trasladar).

### Controladores (namespace `Matricula\`)
- **`MatriculaController`** — `requireRole(['admin','registro_academico',
  'secretaria_academica','secretaria_administrativa'])`. Métodos: `index`, `create`,
  `store`, `apoderado`, `storeApoderado`, `documentos`, `storeDocumentos`, `show`,
  `activar`, `desactivar`, `notasExternas`, `storeNotasExternas`.
  - `activar`/`desactivar` hacen `requireRole(['admin','registro_academico'])`
    EXTRA dentro del método (las secretarías NO pueden cambiar estado).
  - `activar` valida ≥1 apoderado vinculado.
  - `desactivar` (transacción): `estado='desactivado'` + `tipo='trasladado'`,
    desactiva el usuario del apoderado y pone `boletas_publicas.activa=0` del
    periodo activo.
  - `store` crea estudiante si es nuevo, evita duplicado por año, resuelve sección
    (sugerida o posteada), serie de recibo OBLIGATORIA, y redirige al paso 2.
  - Tipos de vínculo y catálogo de documentos viven como constantes del controlador.
- **`RetornoGradoController`** — `requireRole(['admin','registro_academico',
  'director_ebr'])`. `create`/`store`. Crea matrícula operativa en grado inferior
  (`estado='activo'`), inserta en `retornos_grado` y **transfiere las calificaciones**
  de la oficial a la operativa con `INSERT IGNORE` (preserva competencia/periodo).

### Rutas (`routes/web.php`)
Las literales (`/matriculas/crear`) y los sub-recursos (`/{id}/apoderado`,
`/{id}/documentos`, `/{id}/activar`, `/{id}/desactivar`, `/{id}/notas-externas`,
`/{id}/retorno`) se registran ANTES del patrón genérico `GET /matriculas/{id}`
(que va al FINAL) para que el router no capture `crear` como `{id}`.

> Las rutas antiguas `/secretaria/matriculas` y `/director/matriculas` apuntaban a
> controladores que NUNCA existieron (placeholders). Este módulo NO las usa; el
> dashboard ahora enlaza a `/matriculas`.

### Vistas (`resources/views/matriculas/`, layout `app`)
`index` (filtros: año/grado/sección/estado/tipo/búsqueda + paginación de 25),
`crear` (wizard paso 1), `apoderado` (paso 2), `documentos` (paso 3), `show`
(detalle con cards), `notas-externas`, `retorno`. SASS: `pages/_matriculas.scss`
(importado en `app.scss`) con `.wizard-steps`, `.matricula-badge`, `.apoderado-card`,
`.documento-checklist`, `.busqueda-dni`, `.mat-filtros`, `.mat-paginacion`,
`.form-check`. Reutiliza `.card`, `.info-grid`, `.tabla-notas`, `.form-grid`, `.badge`.

### Integraciones con módulos existentes
- **7.1 Orden de mérito** (`Director\OrdenMeritoController`): en los métodos de
  ranking/conteo, `m.estado='aprobada'` pasó a `m.estado IN ('aprobada','activo')`
  y se EXCLUYE la matrícula oficial de un retorno activo
  (`m.id NOT IN (SELECT matricula_oficial_id FROM retornos_grado WHERE estado='activo')`).
  Así el estudiante compite en su grado OPERATIVO. Las queries de descubrimiento de
  grados (otro espaciado) NO se tocaron.
- **7.2 Calificaciones** (`Docente\CalificacionController::getAlumnosSeccion`):
  `m.estado='aprobada'` → `m.estado IN ('aprobada','activo') AND m.tipo != 'trasladado'`.
  Incluye estudiantes en retorno (operativa = 'activo' en esa sección) y excluye
  trasladados. **Nota:** el spec tenía una contradicción (regla "trasladado SIEMPRE
  aparece en calificaciones" vs. 7.2 "excluir trasladado"); se siguió la instrucción
  explícita 7.2. Sin impacto en la demo (todas las filas son 'aprobada'/'continuador').
- **7.3 Boletas públicas:** `desactivar()` hace `UPDATE boletas_publicas SET activa=0`
  del periodo activo (columna `activa` añadida en la migración).
- **7.4 Dashboard:** la card "Matrículas" ahora apunta a `/matriculas` y es visible
  para `admin`, `registro_academico`, `secretaria_academica`, `secretaria_administrativa`.
  `DashboardController` envía a esas secretarías al dashboard (ven sus cards).

### Reglas de negocio aplicadas
- Toda matrícula nace `pendiente`; solo admin/registro_academico activan/desactivan.
- Serie de recibo obligatoria siempre. Sección sugerida pero confirmable.
- Máx 3 apoderados por estudiante; máx 3 estudiantes activos por apoderado/año.
- Continuador → solo `recibo_pago`; nuevo → todos los documentos.
- Notas externas solo para `tipo='nuevo'`.

## Consolidación de estados de matrícula (IMPLEMENTADO)

> Antes el enum tenía `'aprobada'` y `'activo'` como SINÓNIMOS de "matrícula
> vigente" (bug latente: el módulo activaba a `'activo'`, pero boleta, boleta
> pública, asistencia, conducta, panel del padre, año académico y parte de orden
> de mérito filtraban solo por `'aprobada'` → un alumno `'activo'` quedaba
> INVISIBLE). Se eliminó `'activo'`; la activación pasa a `'aprobada'`.

### Estados finales: SOLO TRES
| Estado | Significado | Reglas |
|--------|-------------|--------|
| `aprobada` ("Aprobado") | Estudiante correctamente matriculado, sin pendientes | Cuenta para TODO (boleta, orden de mérito, notas) |
| `pendiente` | Documentos/observaciones pendientes | Matrícula incompleta; el motivo lista los faltantes. **SÍ se califica y SÍ recibe boleta, pero NO entra al orden de mérito** (12/08/2026) |
| `desactivado` | No matriculado por algún motivo (**motivo obligatorio**) | Apaga login del apoderado; SIN orden de mérito; SIN boleta |

El motivo visible vive en `matriculas.motivo_estado` (**TEXT**); `observaciones`
queda como traza de auditoría histórica. Se muestra junto al badge en `index` y `show`.

> ⚠️ **El `estado` mueve el ORDEN DE MÉRITO desde el 12/08/2026.** Los tres actos que lo
> cambian —`activar`, `desactivar` y `guardarDocumentos` al desmarcar un documento
> entregado— entran o sacan al estudiante del snapshot de los bimestres cerrados y **no
> publicados**, avisando del puesto que ocupaba y del arrastre. Van en transacción y
> llaman a `OrdenMeritoModel::sincronizarRosterPorMatricula`. Los rosters de EVALUACIÓN
> no cambiaron: siguen filtrando solo por `tipo`. Detalle en
> `docs/modulos/orden-merito.md` §7.1.

### Migración — `017_estados_matricula_consolidacion.sql`
> El plan original pedía `013`, pero `013`–`016` ya estaban ocupados (reaperturas,
> limpieza_estados, desempates, traslados). Se usó `017`. Idempotente.
- `ADD COLUMN IF NOT EXISTS motivo_estado TEXT NULL AFTER estado`.
- `UPDATE matriculas SET estado='aprobada' WHERE estado='activo'` (0 filas, sin pérdida).
- `MODIFY estado ENUM('pendiente','aprobada','desactivado') NOT NULL DEFAULT 'pendiente'`.

### Cambios de código
- **`MatriculaModel::cambiarEstado($id, $estado, $usuarioId, ?string $motivo = null)`**:
  guarda `motivo_estado` (`null` lo limpia); traza en `observaciones`; setea
  `aprobado_por`/`fecha_aprobacion` cuando el estado es `'aprobada'`. `listar()`
  ahora selecciona `m.motivo_estado` (`findById` ya usa `m.*`).
- **`MatriculaController`**: `activar()` → `'aprobada'` + motivo `null`;
  `desactivar()` → **motivo OBLIGATORIO** desde el POST (rechaza vacío) y lo pasa a
  `cambiarEstado`; el caso de re-pendiente anota los requisitos faltantes como motivo.
- **`'activo'` eliminado** de todas las queries de matrícula: `MatriculaModel`
  (sugerirSeccion), `ApoderadoModel`, `CalificacionModel`, `OrdenMeritoModel`,
  `ControlOperativoModel`, `OrdenMeritoController`, `Docente\CalificacionController`,
  `RetornoGradoController` (la operativa nace `'aprobada'`), `TrasladoController`
  (valida `=== 'aprobada'` y pasa el motivo del traslado a la baja).
  > OJO: `retornos_grado.estado='activo'`, `periodos.estado IN('activo','cerrado')`,
  > `anios/usuarios/areas.estado='activo'` son OTRAS columnas — NO se tocaron.
- **Vistas** `matriculas/index.php` y `show.php`: label `'aprobada' => 'Aprobado'`,
  filtro de estado actualizado, `<textarea name="motivo" required>` en el form de
  desactivar, y `.matricula-motivo` bajo el badge. SASS en `_matriculas.scss`
  (`.matricula-motivo`, `.mat-desactivar-form`; enum de badges reducido a 3 estados).

## Alta provisional sin DNI — estudiante en trámite (02/07/2026)

> Un padre matricula a su hijo pero no dejó el DNI ni los documentos de traslado,
> y el docente ya necesita calificarlo. Se permite dar de alta al estudiante SIN
> DNI real con un **código provisional**; la matrícula nace `pendiente` (ya
> calificable) y se regulariza antes de activar. Commit `24099b4` en `dev`.
> **Sin migración** (el código cabe en `personas.dni`).

### La premisa que lo hace de bajo riesgo (dos hechos del sistema)
- **Calificar NO exige `aprobada`:** `Docente\CalificacionController::getAlumnosSeccion`
  trae a TODOS los matriculados de la sección (`aprobada`, `pendiente` e incluso
  `desactivado`); el único excluido es `tipo='trasladado'` + casos de retorno. Una
  matrícula `pendiente` YA aparece en la grilla del docente.
- **`pendiente` no contamina documentos oficiales:** boleta exige `aprobada`+bloqueo
  y orden de mérito exige `aprobada`. El provisional queda fuera hasta regularizar.

### Código provisional — formato y punto único de verdad
- Formato **`P` + 7 dígitos** (`P0000001`, `P0000002`…). Cabe en `personas.dni`
  (`varchar(8) NOT NULL UNIQUE`) e es inconfundible con un DNI real (8 dígitos
  numéricos). El primero será `P0000001` (0 provisionales en BD al implementar).
- **`es_dni_provisional(?string $dni): bool`** en `app/Helpers/helpers.php` —
  verdadero si empieza con `P`. Lo usan controlador y vistas para distinguirlo.
- **`MatriculaModel::generarDniProvisional()`** — `MAX(dni) WHERE dni LIKE 'P%'` +1
  (ancho fijo → el MAX lexicográfico == máximo numérico).
- **`MatriculaModel::crearEstudianteProvisional(array $datos)`** — persona +
  estudiante en transacción, con **reintento ante colisión** del código por
  concurrencia (el UNIQUE de `personas.dni` rechaza el duplicado, SQLSTATE 23000 →
  regenera, máx 5). Los datos NO deben incluir `dni` (lo asigna el método).

### Alta (`MatriculaController::store`, rama `provisional=1`)
- Ignora el DNI; exige **apellido paterno + nombres** (materno/fecha/sexo opcionales).
- **Serie de recibo OPCIONAL** en el alta provisional (el padre no dejó recibo); se
  sigue exigiendo antes de activar (la reclama `pendientesParaActivar`).
- Crea la matrícula `pendiente` con `motivo_estado = "Registro provisional —
  pendiente de DNI y documentos"`. Sección sugerida igual que el alta normal.
- La rama redirige y termina ANTES de la validación normal (con DNI), que queda
  intacta. Roles: los 4 del módulo pueden crear provisional.

### Candado de aprobación (integridad)
- `pendientesParaActivar()` agrega, si `es_dni_provisional($matricula['dni'])`:
  *"Reemplazar el DNI provisional por el DNI real"*. Como `activar()` bloquea con
  la lista no vacía, **un DNI falso NUNCA llega a `aprobada`** (ni a boleta/mérito).
  Aparece como requisito faltante en `show.php`.

### Regularización (cuando llega el DNI real)
- Se reemplaza el código por el DNI real desde **"Editar datos"** del detalle
  (`actualizarEstudiante`, que ya valida 8 dígitos). Al hacerlo, el candado se
  levanta solo.
- **Colisión:** si el DNI real ya pertenece a OTRA persona (el alumno ya existía),
  `dniEnUsoPorOtra` **rechaza y avisa** para resolución manual (mensaje mejorado;
  la fusión de registros es manual, NO automática).

### Frontend
- `crear.php`: toggle **"El estudiante aún no tiene DNI (registro provisional)"**
  (checkbox `name="provisional"`) + `data-dni-group`. `create()` inyecta
  `page_scripts=['matriculas']`.
- `resources/js/matriculas.js` (→ `gulp build`): al marcar, oculta el campo DNI
  (`disabled` → no se envía) y relaja la obligatoriedad de DNI y serie. El servidor
  es la autoridad (el form es `novalidate`); el JS es solo UX.
- Badge **"Provisional"** en `show.php` (junto al DNI) y **"prov."** en `index.php`;
  estilo `.badge-provisional` en `_matriculas.scss` (ámbar de estado, no wayfinding).

### Pendiente menor (no implementado)
- La búsqueda del index por texto que empiece con `P…` cae en la rama de nombre y
  no matchea el código provisional. Ajuste chico en `construirFiltros` si se pide.

## Exoneraciones en el detalle de matrícula (07/07/2026)

> `/matriculas/{id}` ahora MUESTRA las exoneraciones vigentes del alumno y
> permite REGISTRAR nuevas desde ahí (antes solo existía `/admin/exoneraciones`
> por sección, y el detalle no las mencionaba — caso real: PEÑA PILLACA 3°A
> primaria, exonerada de Ed. Religiosa desde el 24/05, invisible en su detalle).

- **Card "Exoneraciones"** en `show.php` (entre Notas externas y Constancia de
  traslado): SOLO consulta — lista vigentes (área/subárea — motivo — fecha —
  registrador) via `ExoneracionModel::getVigentesPorMatricula()`. Visible para
  todos los roles con acceso al detalle.
- **Registro — REUBICADO (09/07/2026):** el formulario ya NO vive en la card del
  grid (columnas de ~300px: el `form-grid--2` colapsaba, el select desbordaba y
  la card quedaba desproporcionada). Ahora es la fila **"Exonerar de área"** en
  la card "Gestión de la matrícula" (`.mat-accion` + formulario desplegable
  `.mat-exonerar-form`, mismo patrón disclosure que Desactivar/Editar datos:
  `data-exonerar-{form,control,toggle,cancel}` en `matriculas.js`; sin JS el
  form se ve abierto). Solo `puedeGestionar` (= admin/RA, la card entera está
  gated) y con `opcionesExoneracion` no vacías. El select de
  `getOpcionesParaSeccion()` postea a `POST /matriculas/{id}/exonerar` →
  `Admin\ExoneracionController::registrarDesdeMatricula()` — reusa parseo,
  **candado de notas vivas** (`tieneNotasVivas`, 07/07) y `registrar()`; usa el
  `anio_id` de la matrícula y vuelve al detalle. Las secretarías no ven el form
  y el controlador les rechaza el POST por rol.
- **Revocar** sigue SOLO en `/admin/exoneraciones/{seccion}` (el form desplegable
  enlaza "Revocar en Exoneraciones").

### Exonerar a un alumno QUE YA TIENE NOTAS (05/08/2026) — deroga el candado del 07/07

> **Caso real que lo motivó:** una estudiante con calificaciones en un bimestre
> **cerrado** y otro **abierto** solicita la exoneración. Hasta hoy no tenía salida.

El candado del 07/07 (`tieneNotasVivas` → rechazo) dejaba el caso sin solución, porque:

- mira **TODO EL AÑO**, así que limpiar el bimestre abierto no bastaba mientras el
  cerrado conservara una sola nota; y
- las del bimestre cerrado **no se pueden borrar**: `periodoEstaBloqueado` bloquea todo
  periodo cerrado, y reabrirlo lo dejaría sin poder re-cerrarse (guard de evaluación
  incompleta). El "elimina las notas primero" que pedía el mensaje **no era ejecutable**.

**Regla nueva:** el aviso se conserva pero es **franqueable con confirmación explícita**
(`confirmar_notas`, casilla en los dos formularios; sin ella el POST se rechaza, así que
el clic accidental sigue protegido). Punto único: `ExoneracionController::confirmacionDeNotasVivas()`.

**Decisión del usuario: la exoneración manda sobre las notas, en LOS CUATRO BIMESTRES**
—incluidos los ya cursados y aprobados—. `ExoneracionModel::inyectarEnAreas` sobrescribe
siempre con `EXO`; ya no distingue si había notas.

- **Las notas NO se borran de `calificaciones`**, solo dejan de mostrarse. Eso hace la
  exoneración **reversible** (al revocarla reaparecen) y evita tener que reabrir un
  bimestre cerrado, cosa hoy imposible.
- El **docente** ve `EXO` sin input en su grilla (comportamiento que ya existía).
- ⚠️ **La boleta de un bimestre YA ENTREGADO cambia hacia atrás** (decía la nota, pasa a
  decir EXO) y **el acta que ya se subió al SIAGIE conserva la nota**: esa divergencia se
  gestiona fuera de SIGA.
- ✅ El **snapshot de orden de mérito NO se toca** (es inmutable): el puesto de ese
  bimestre sigue calculado con la nota que hubo, y **nadie más se mueve de puesto**.
- ✅ **El ORDEN DE MÉRITO excluye las áreas exoneradas** (decisión del usuario, mismo día).
  Sin ese filtro el documento decía `EXO` mientras el ranking seguía promediando la nota.
  Las dos queries de `OrdenMeritoModel` que calculan promedio llevan un `NOT EXISTS` sobre
  `exoneraciones` que cubre las dos formas de exonerar —por **área** (`a.id` ya viene
  resuelta con `COALESCE`, así que alcanza a sus subáreas) y por **subárea** suelta—.
  **NO toca los snapshots guardados: es el cálculo EN VIVO.** Ver `orden-merito.md`.

## Notas autorizadas por dirección para SIAGIE (14/07/2026)

> El SIAGIE exige la nota de cada competencia evaluada para TODA la sección y no
> deja cerrar con celdas vacías. Un alumno con ausencia justificada (salud,
> accidente, viaje) queda correctamente SIN nota en SIGA (omisión con motivo
> `ausencia_justificada`) → su celda del SIAGIE queda en blanco. Dirección ordena
> consignar una nota para poder cerrar en el SIAGIE. Se resuelve con un **"informe
> aparte"** que alimenta SOLO el export (no toca boleta ni orden de mérito).

- **Tabla** `notas_autorizadas_siagie` (migración 040): `matricula+competencia+periodo
  → nota_literal + conclusión + resolución`, UNIQUE. Modelo `NotaAutorizadaSiagieModel`.
- **Pantalla** `/matriculas/{id}/notas-siagie` (solo admin/RA; card resumen en `show`).
  Muestra, por bimestre, las competencias AUTORIZABLES y las ya registradas.
- **Candado de elegibilidad** (`esElegible`, validado en servidor): la competencia
  debe tener una **omisión registrada** del alumno (CUALQUIER motivo — basta que el
  docente la haya marcado, no se exige `ausencia_justificada`), estar **bloqueada** y
  **sin calificación viva**. `competenciasElegibles` además oculta las ya autorizadas.
- **Conclusión** obligatoria según la escala vigente (`conclusion_es_obligatoria`:
  primaria B/C, secundaria C). La resolución de dirección es obligatoria.
- **Export:** `LlenadorSiagie` rellena con ella SOLO la celda en blanco de una
  competencia bloqueada; la nota real de `calificaciones` siempre tiene precedencia.
  Se reporta aparte y suma `resumen['autorizadas']`. Ver `docs/modulos/export-siagie.md`.
- **Informe imprimible** `/matriculas/{id}/notas-siagie/informe` (layout `print`):
  respaldo físico con firma del director EBR vigente. SASS `pages/_notas-siagie-informe`.
- **Rutas** literales/sub-recursos ANTES del patrón `{id}` (invariante del router).
- **Retorno de grado:** la evaluación (omisiones, elegibles, nota autorizada) vive
  en la matrícula OPERATIVA. El controlador resuelve la "matrícula de evaluación"
  (`matriculaEvaluacion`: operativa si hay retorno) y opera ahí; la card del detalle
  (que se ve en la OFICIAL) apunta a la operativa. El export une fuentes con
  `boletaContexto`, así procesa la oficial y encuentra la nota de la operativa.
- **NO toca:** `calificaciones`, `bloqueos_competencia`, boleta, orden de mérito.
- OJO: en secundaria, la exoneración de Ética y Valores se registra contra el
  área **Tutoría (TOE)** (id 24) — así aparece rotulada la opción en el select
  (nombre interno; la boleta la muestra como "Ética y Valores"). Ver
  `docs/modulos/calificaciones.md`.

## Tipo `retirado` — estudiante que ya no asiste (22/07/2026)

> Un estudiante deja de asistir pero la familia NO tramita el traslado oficial
> (no hay constancia ni IE destino; guarda la esperanza de que regrese). Hasta la
> migración 045 el único marcador de "abandono" era `tipo='trasladado'`, que exige
> el trámite oficial → un desactivado por otro motivo seguía apareciendo en las
> grillas del docente/tutor. `retirado` cubre el hueco: excluir de evaluación sin
> constancia.

- **Semántica de `matriculas.tipo`** (los "no calificables" son siempre `desactivado`):

  | estado | tipo | ¿se califica? |
  |---|---|---|
  | aprobada / pendiente | continuador·nuevo | ✅ Sí |
  | desactivado (deuda u otro motivo, **sigue asistiendo**) | continuador·nuevo | ✅ Sí |
  | desactivado, **ya no asiste** | `retirado` | ❌ No |
  | desactivado, traslado oficial | `trasladado` | ❌ No |

- **Exclusión de los 9 rosters de evaluación** (`!= 'trasladado'` →
  `NOT IN ('trasladado','retirado')`): `Docente\CalificacionController::getAlumnosSeccion`,
  `ConductaModel` (×5), `CalificacionModel` (resumen/validación de bloqueo),
  `TransversalModel`, `Docente\TutoriaController`.
- **NO se tocan** los usos de `trasladado` en boleta (`BoletaController`): un retirado
  es `desactivado` no-trasladado → cae en **BORRADOR forzado** (sin QR/firma), NO en
  el trato OFICIAL del trasladado. Tampoco la nómina de *ver* boletas del docente
  (`PanelController:713/801`) — verla no es calificar. Orden de mérito y SIAGIE ya
  excluyen `desactivado`.
- **Acción reversible** en "Gestión de la matrícula" (`show.php`, rol admin/RA):
  **"Marcar como retirado"** (`POST /matriculas/{id}/retirar` → `retirar()`), solo
  sobre `desactivado` de tipo continuador/nuevo; guarda el tipo real en
  `tipo_anterior`. Inversa **"Revertir"** (`/revertir-retiro` → `revertirRetiro()`)
  restaura `tipo_anterior` (el estado sigue `desactivado`). `activar()` también
  restaura el tipo al reactivar por completo (condición extendida a
  `IN ('trasladado','retirado')`).
- **Migración 045** (`MODIFY tipo ENUM(...,'retirado')`, idempotente). `tipo_anterior`
  NO incluye `retirado` (nunca se revierte hacia él). Badge `matricula-badge--retirado`.

## Los KPIs de `/matriculas/resumen` cuentan ESTUDIANTES (02/09/2026)

> **Estado: EN PRODUCCIÓN desde el 02/09/2026** (merge `11c5b79`). Sin migración.

Los cinco chips del resumen contaban `estado='aprobada'`, o sea **matrículas
oficiales**. Ahora cuentan **estudiantes del colegio**, que es la pregunta que la
pantalla dice responder.

> ⚠️ **El ancla del retorno de grado cambió el mismo día, más tarde.** Estos chips
> nacieron usando `roster_evaluacion('m')`, que **conserva la matrícula operativa** y
> por tanto ubicaba al estudiante en el **grado inferior**. Desde el bloque
> «Toda la pantalla se ancla en la matrícula oficial» (más abajo) el universo es
> `matriculas_vigentes('m')` + `matricula_documento('m')`. **Todo lo demás de esta
> sección sigue vigente**, y las cifras no cambiaron.

**El universo son todas las matrículas del año SIN filtrar por `estado`**:

- `pendiente` es el estado en que **nace** toda matrícula.
- `desactivado` es una baja administrativa (deuda) de alguien que **sigue
  asistiendo**.
- Se excluye a quien ya no está: `tipo IN ('trasladado','retirado')`.

El usuario pidió excluir solo trasladados; se le preguntó por `retirado` —que
existe desde la migración 045 y significa «ya no asiste, sin traslado oficial»— y
**decidió excluirlo también**, que es lo coherente con los otros nueve rosters.

| Chip | Qué cuenta |
|---|---|
| Estudiantes | el universo completo |
| Secciones | `COUNT(DISTINCT seccion_id)` del universo |
| Promedio por sección | estudiantes / secciones, **entero** |
| Matrículas desactivadas | `estado='desactivado'` dentro del universo |
| Matrículas pendientes | `estado='pendiente'` dentro del universo |
| Trasladados y retirados | **fuera del conteo**, se publica para poder explicarlo |

El último chip sigue el precedente de `_stats-competencia.php` («Exonerados ·
fuera del conteo»): si el total no cuadra con lo que alguien recuerda, la pantalla
lo dice en vez de parecer un error.

### Dos defectos que esto arregló de paso

- 🔴 **Doble conteo por RETORNO DE GRADO.** `getResumen()` no excluía ninguna de
  las dos matrículas del retorno, mientras `getCuadroMatricula()` —en la **misma
  página**— sí excluye la operativa: las dos mitades daban totales distintos del
  mismo año. Lo resuelve el helper, que trae las exclusiones de retorno gratis.
  Hay **1 retorno real** en el año 2026, así que el caso está medido, no supuesto.
- **`Secciones` contaba secciones con al menos un APROBADO**, no secciones con
  estudiantes, y el promedio heredaba el sesgo al alza.

### Lo que NO cambió, a propósito

**Los gráficos siguen con `estado='aprobada'`.** Describen la foto oficial de la
matrícula, que es otra pregunta: los KPIs responden «cuántos estudiantes hay» y
los gráficos «cómo quedó la matrícula aprobada». Hay un aserto que vigila que
ambas cosas sigan conviviendo.

> ⚠️ Esta frase se escribió cuando los gráficos **tampoco tenían ancla de retorno**.
> Sigue siendo cierta en cuanto al UNIVERSO (`estado='aprobada'`), pero desde el
> bloque de abajo los gráficos **sí comparten el ancla** con los chips y con el
> cuadro. Lo que separa a las dos mitades de la pantalla es el universo, no el ancla.

### ⚠️ `kpis` tiene un segundo consumidor

`Admin\CuadrosEstadisticosController` pinta estos mismos KPIs en `/admin/cuadros`.
**Se pueden AÑADIR claves; renombrar o quitar las históricas** (`aprobadas`,
`pendientes`, `desactivadas`, `secciones`, `promedio_seccion`) **rompe ese tablero
sin error**: la vista pinta un hueco. Hay un aserto que lee las claves que esa
vista consume y comprueba que todas existan.

### ~~Pendiente: `retirado` huérfano~~ — CERRADO el 02/09/2026

Estaba huérfano en los **dos** artefactos de la pantalla, y los dos se arreglaron:
el pie «Por tipo de matrícula» (`$tipoOrden` en `resumen.php`, que lo descartaba en
silencio) y el cuadro de abajo. Detalle en la sección siguiente.

## El cuadro de matrícula por grado — que cuadre (02/09/2026)

> **Estado: en `dev`, sin desplegar.** Sin migración.

La tabla final de `/matriculas/resumen`, que además **se imprime** y va al comité
directivo. Tenía tres defectos de cuadre; dos de ellos nadie los había visto.

### A. Faltaba la columna `retirado`

El enum de `matriculas.tipo` tiene **cuatro** valores desde la migración 045
(`SHOW COLUMNS` lo confirma), pero el cuadro sumaba tres: **Primaria 3.º daba 48
sobre un total de 49**. Ahora son cuatro columnas y suman siempre.

Se descartó fusionar `trasladado` y `retirado` bajo un rótulo común: la 045 los
distingue a propósito —el primero es traslado oficial con constancia e IE destino;
el segundo es «dejó de venir» sin trámite, y es reversible— y esa diferencia es la
que decide si se le puede readmitir.

### B. 🔴 La exclusión del retorno era una TERCERA variante

`retorno-grado.md` fija dos criterios INVERSOS, y el cuadro no usaba ninguno:
**copió la forma del criterio DOCUMENTO y le pegó el `WHERE estado = 'activo'` del
criterio EVALUACIÓN.**

```sql
-- Lo que había:  ... FROM retornos_grado WHERE estado = 'activo')   ← híbrido
-- Lo que toca:   ... FROM retornos_grado)                           ← criterio DOCUMENTO
```

Con un retorno **`revertido`** ese híbrido no excluía **ninguna** de las dos
matrículas: el estudiante contaba dos veces. Y la fila fantasma no caía en
cualquier sitio — `revertir()` deja la operativa como `tipo='continuador'`,
`estado='desactivado'` **en el grado inferior**, así que aparecía un alumno
inexistente engordando justo la columna que el comité lee como morosidad.

Era **latente**: el único retorno real está `activo`, así que hoy nadie lo veía.
Y hay una ironía que conviene recordar: al arreglar los KPIs (más arriba)
`getResumen()` pasó a deduplicar los revertidos vía `roster_evaluacion()`, de modo
que el desajuste entre las dos mitades de la misma página **no se eliminó, se
invirtió y se mudó al caso `revertido`**.

**El cuadro es ahora un consumidor declarado del criterio DOCUMENTO**, igual que
`BoletaPublicaModel`. Hay dos asertos: uno exige la forma canónica y otro exige que
**no haya vuelto** ningún `WHERE estado` al subselect.

### C. Género: se queda con M y F

Decisión del usuario: **no se añade columna «Sin dato»**, se respeta el acuerdo
previo. Lo único que cambia es que la nota al pie **da la cifra** («hay 508
estudiantes sin sexo registrado») en vez de advertir en abstracto que M + F puede
ser menor. La cobertura real es del **4,9 %** (26 de 535).

### La fragilidad estructural que lo causaba

Las celdas del parcial estaban escritas **a mano en tres sitios** y la lista `$cols`
solo alimentaba los acumuladores: añadir una columna eran **cinco puntos** distintos
y olvidarse de uno no daba ningún error, solo una tabla descuadrada. Es exactamente
como `retirado` se quedó fuera.

Ahora **todo se deriva de `$grupos`**: los encabezados de grupo con su `colspan`, los
`<th>`, las celdas de las tres filas, los acumuladores y el `colspan` de la banda de
nivel. **Añadir una columna es añadir una entrada.**

### Verificación

`verif_matriculas_resumen.php` sube a **34 asertos**. Los del cuadro comprueban la
PROPIEDAD —que las columnas de un grupo sumen su total—, no una lista de tipos, para
que un quinto valor del enum también salte.

🔴 **La rama `revertido` se simula con transacción y ROLLBACK**, porque en la base no
existe ningún retorno revertido y sin eso el arreglo B quedaría sin probar. El
verificador comprueba además, con la consulta vieja escrita a mano, que **el filtro
anterior sí duplicaba** (535 en vez de 534): un aserto que solo se ha visto pasar no
prueba nada. Y confirma que el ROLLBACK dejó el retorno como estaba.

**Cabe en papel**: 11 columnas miden 702 px sobre los 718 px útiles de un A4 portrait
a 10px. No hizo falta tocar el SASS.

### Recomendación no aplicada

El mismo desajuste de `retirado` sigue en **el filtro de la grilla**
(`matriculas/index.php`) y en **la nómina imprimible** (`nomina-imprimir.php`, cuyo
controlador **sí** calcula el retirado pero no lo pinta). Fuera del alcance pedido.

### Verificación

`database/verificaciones/verif_matriculas_resumen.php` — **20 asertos**, solo
lectura. Contrasta los cinco KPIs contra un `COUNT` **escrito a mano** (no
derivado del helper: si saliera de la misma fuente no probaría nada), comprueba
que el promedio sea entero, que las claves de `/admin/cuadros` sigan existiendo y
—el que muerde— que **contar filas y contar estudiantes distintos dé lo mismo**,
que es lo que se pone en rojo si alguien quita el helper y vuelve el doble conteo.

## Toda `/matriculas/resumen` se ancla en la matrícula OFICIAL (02/09/2026)

> **Estado: en `dev`, sin desplegar.** Sin migración.

Cierra el tercer y último desajuste de esta pantalla. Lo pidió el usuario mirando la
vista: *«todas las estadísticas que listan secciones deben mostrar SOLO MATRÍCULAS
OFICIALES; se está tomando las secciones con matrículas operativas»*.

### El defecto: los 5 gráficos no tenían NINGUNA de las dos exclusiones

`getResumen()` tiene seis consultas. Los **cinco gráficos** (por grado, por sección,
varones/mujeres por sección, por tipo y por género) no aplicaban **ni el criterio
EVALUACIÓN ni el DOCUMENTO**. Como la operativa nace `estado='aprobada'`, el
estudiante en retorno **contaba dos veces** en los cinco, y la fila fantasma caía en
el **grado inferior**.

Medido contra el retorno real de 2026 (oficial `id 190` en 2°B Prim ↔ operativa
`id 692` en 1°B Prim):

| Serie | Antes | Ahora |
|---|---|---|
| Por grado · 1.º Primaria | **42** | **41** (2.º Primaria sigue en 37) |
| Por sección · 1°B Prim | **20** | **19** (2°B Prim sigue en 19) |
| Por tipo · continuador | **517** | **516** |
| Por género · sin dato | **496** | **495** |
| Total de las cuatro series | **521** | **520** = el chip `aprobadas` |

### Y la página se contradecía consigo misma

Las tres mitades usaban **tres anclas distintas** del mismo año:

| Bloque | Ancla que tenía | Dónde ubicaba al estudiante en retorno |
|---|---|---|
| Chips | `roster_evaluacion()` → conserva la **operativa** | 1°B Primaria |
| 5 gráficos | ninguna | **en las dos a la vez** |
| Cuadro por grado | criterio DOCUMENTO | 2°B Primaria |

Ahora las tres usan **DOCUMENTO**. Los chips pasan a
`matriculas_vigentes('m')` + `matricula_documento('m')`, y **ninguna cifra suya
cambió** (523 estudiantes · 23 secciones · promedio 23 · 520 aprobadas · 3
pendientes): lo que cambia es de qué sección se le cuenta, que hoy solo se notaría
si la operativa fuera la única matrícula de su aula.

**Por qué la oficial y no la operativa:** esta pantalla no evalúa a nadie,
**describe** la matrícula del año, y el grado al que el estudiante *pertenece* es el
oficial. Es la misma pregunta que responde el cuadro impreso que va al comité.

### El punto único, que es lo que impide la recaída

La línea DOCUMENTO estaba escrita **a mano en tres sitios**, y este cambio habría
sumado **cinco copias más**. En su lugar nace `matricula_documento()` en
`app/Helpers/helpers.php`, gemelo de `roster_evaluacion()`, y se migran las tres
copias existentes: `BoletaPublicaModel` (era la constante privada
`SQL_EXCLUIR_OPERATIVA`, ahora el método `sqlExcluirOperativa()`), el **token
público** de `BoletaController` y `getCuadroMatricula()`.

Aparece además `matriculas_vigentes()` —el filtro `tipo NOT IN
('trasladado','retirado')`— porque los chips necesitan combinarlo con el ancla
DOCUMENTO, no con la de evaluación. **`roster_evaluacion()` pasa a COMPONERSE desde
él, con salida byte-idéntica**: es lo que impide que las dos definiciones del mismo
filtro se separen.

### Verificación

**Nuevo: `database/verificaciones/verif_matricula_documento.php`** — 20 asertos,
gemelo de `verif_roster_evaluacion.php`. Comprueba el texto emitido y el alias, que
**nadie fuera de `helpers.php` repita la condición** (con espacios normalizados: la
copia del cuadro estaba partida en tres líneas), que los consumidores declarados sí
llamen al helper, y que `roster_evaluacion()` **componga** en vez de copiar.

🔴 Los dos que muerden: el **anti-híbrido** (la condición no lleva `WHERE estado`) y
el de **comportamiento**, que simula un retorno `revertido` con transacción +
ROLLBACK y exige que el helper excluya una matrícula **mientras el híbrido escrito a
mano no excluye ninguna** — la prueba de que el aserto no es vacuo.

`verif_matriculas_resumen.php` sube a **44 asertos**. Los nuevos exigen que las
cuatro series sumen lo mismo **y que ese mismo sea el chip `aprobadas`** (antes: 521
contra 520), que el desglose de sexo cierre sección a sección, y que cada retorno
entre por su matrícula **oficial**.

⚠️ Sus consultas de control pasaron del criterio EVALUACIÓN al DOCUMENTO. Siguen
escritas a mano a propósito: si salieran de los helpers no probarían nada.

**Sin cambios en vistas, JS ni SASS**: la forma de `$chartData` no cambia.


## Notas del COLEGIO DE ORIGEN (10/09/2026) — informativas, nunca en la boleta

> Migración `057` (`notas_externas.area_id`). Punto único: `NotaExternaModel`.
> Rutas: `GET|POST /matriculas/{id}/notas-externas` (RA) y
> `GET /docente/notas-origen/{matricula}` (docente, solo lectura).

### La regla del colegio

El marco legal dice que el colegio destino debe incorporar lo que el estudiante trae. **El
COCIAP acota esa regla:** el Informe de Progreso que emite **tras** el traslado lleva
**solo las calificaciones cursadas aquí**. Lo del colegio anterior es **informativo**, y su
público son los **docentes con carga en su sección**, que necesitan saber con qué llega.

⚠️ **Por eso `BoletaModel` NO lee `notas_externas`, y no debe leerla.** Que estas notas no
alcancen la boleta **no es un mecanismo a medio hacer: es el comportamiento pedido.** El
plan del 05/08 llegó a catalogar esa tabla como «mecanismo muerto» y proponía borrarla
(`docs/modulos/registro-retroactivo-notas.md`, D7): esa decisión quedó **invertida**.

### NO confundir con el otro mecanismo

| | Colegio de **origen** | Notas **nuestras** no registradas |
|---|---|---|
| De quién es la nota | del colegio anterior | del COCIAP, quedó en el cuaderno del docente |
| Boleta y SIAGIE | **NO** | **SÍ** |
| Escala | **literal** puro (AD/A/B/C) | **numérica** 00-20, literal derivado |
| Dónde vive | `notas_externas` | `calificaciones` (`extraordinaria=1`) |
| Por dónde entra | ficha de matrícula | `/rectificaciones/matricula/{id}` |

**Ninguna detección automática decide cuál es cuál: lo elige quien registra.** Las dos
entradas son explícitas y cada una avisa de la existencia de la otra.

### Por qué no sirve ningún flag para detectar el caso

Medido el 10/09/2026 en la BD:

- `matriculas.tipo` **no distingue**: de los 6 estudiantes que llegaron con un bimestre
  cerrado por delante, **3 son `nuevo` y 3 `continuador`**.
- `tipo_matricula = 'traslado_entrada'` **miente**: **173 filas, y las 173 tienen B1
  completo** (el flag se puso mal en la carga masiva del 19/05).

⚠️ **Consecuencia práctica: la card de notas de origen ya NO exige `tipo === 'nuevo'`.**
Ese candado —que estuvo en `matriculas/show.php` hasta hoy— dejaba la pantalla fuera del
alcance de **la mitad de los casos reales**, y en todo el año activo solo hay **5**
matrículas `nuevo`. **No reintroducirlo, ni sustituirlo por `traslado_entrada`.**

### Mapeo de área: OPCIONAL a propósito

`area_id` (NULL por defecto) enlaza una nota de origen con un área de **nuestro** plan, y
sirve **solo para resaltarle la fila al docente que dicta esa área**. Es opcional porque el
plan curricular del otro colegio no tiene por qué coincidir con el nuestro: **obligar a
traducirlo dejaría fuera lo que no tenga equivalente**. Sin mapeo la fila se ve igual;
simplemente no se resalta.

Los nombres de área, competencia y periodo siguen en **texto libre** por el mismo motivo.

### Qué ve el docente

El informe **completo**, agrupado por periodo, con las filas de su(s) área(s) resaltadas.
No se recorta por área: recortar escondería justo lo que no tiene equivalente aquí.

**Guarda:** hay que tener **carga ACTIVA en la sección de esa matrícula**; si no,
`notFound()` — ni siquiera se confirma que la matrícula exista. Probadas las dos ramas.

### El aviso

Al guardar, `crearParaDocentesDeSeccion` deja **una notificación por docente** de la
sección (18 en la sección medida), no una por nota. Va **fuera de la transacción del lote**
a propósito: que falle el aviso no puede tumbar un registro ya válido. Ver
`docs/modulos/notificaciones.md`.

### Puntos únicos y captura en lote

`NotaExternaModel` es el punto único; `MatriculaModel::getNotasExternas` y
`registrarNotaExterna` quedan como **delegadores** (no se rompió su interfaz pública). El
formulario captura **varias filas por envío** —el informe de origen llega como un documento
entero— y las filas en blanco se descartan sin error.

⚠️ **`registrarLote` NO abre transacción: la owna quien llama.** PDO no anida, y abrirla en
el modelo impedía envolver el lote desde fuera —empezando por el verificador, que escribe y
hace rollback—. Mismo criterio que `escribirExtraordinaria`.

### Verificación

`database/verificaciones/verif_notas_origen.php`. La comprobación central es **negativa**:
registrar notas de origen **no altera ni una celda de la boleta** (29 comparadas) ni el
orden de mérito (47 filas). **Si algún día una de estas notas aparece en una boleta, ese
script tiene que ponerse rojo.**

### Importar la currícula del COCIAP (10/09/2026)

> Migración **`059`**. `NotaExternaModel::curriculaParaImportar()`.
> Ruta: la misma, con `?importar=1&periodos[]=&areas[]=`.

Transcribir a mano el informe del colegio anterior son **27-29 competencias POR BIMESTRE**, y
quien llega en el III trae dos. Como la mayoría de colegios peruanos sigue el **Currículo
Nacional del MINEDU** —el mismo que usa el COCIAP—, se traen las nuestras ya escritas y RA
solo pone las notas.

**Se apoya en `CalificacionModel::estructuraCompetenciasSeccion()`**, que ya resolvía las
áreas con subáreas y añadía las transversales. No se escribió consulta nueva.

- **Sin subáreas** (Aritmética, Plan Lector, Química…): son organización **interna** del
  COCIAP y el colegio de origen pudo repartirse de otra forma. Se importa el **área** y la
  **competencia**; atribuirle una división que no usa sería inventarle datos.
- **`nombre_completo`**, la redacción oficial del currículo nacional: es la que más
  probablemente coincida con el informe que trae el estudiante.
- **Varios bimestres a la vez** y **elección de áreas**, porque no todo colegio dicta lo mismo.
- **Importar NO escribe nada**: solo **pre-rellena las filas del formulario de siempre**, que
  siguen editables. Guardar sigue siendo el POST. Por eso el **camino manual sobrevive
  intacto** — es literalmente el mismo formulario, en blanco — y sirve para una currícula
  extranjera.
- Lo **ya registrado se excluye**: importar dos veces no duplica filas en pantalla.

⚠️ **`areasDeLaSeccion()` ahora deriva de `curriculaParaImportar()`**, no de una consulta
propia. Si el `<select>` de mapeo ofreciera un juego de áreas distinto del que importa el
importador, una fila importada apuntaría a un área que el select no tiene y **el mapeo —y con
él el resaltado al docente— se perdería al guardar**. Efecto colateral querido: el select ya
incluye las transversales, que la consulta por cargas no traía.

#### 🔴 La migración `059`: el área entra en la clave única

La UNIQUE era `(matricula_id, periodo_nombre, competencia_nombre)` y el alta usa
`ON DUPLICATE KEY UPDATE`. El COCIAP **evalúa la misma competencia del MINEDU en dos cursos**:

```
"Resuelve problemas de cantidad."         Matemática + Taller de Razonamiento Matemático
"Resuelve problemas de regularidad…"      Matemática + Taller de Pre-Cálculo
```

Con la clave vieja, la segunda fila **pisaba a la primera en silencio**: importar 29
guardaba 27. Afectaba a **4 de los 6 estudiantes reales** (2-3 colisiones cada uno; primaria
0). ⚠️ **No lo trajo el importador: ya pasaba al teclear a mano.** Añadir `area_nombre` a la
clave deja **0 colisiones**; la tabla estaba vacía en los dos entornos, así que no arrastró
datos.

#### 🔴 El criterio de «fila vacía» cambió, y no era opcional

Antes se omitía la fila con los **cuatro** campos vacíos. Una fila importada llega con
periodo, área y competencia llenos y **la nota vacía**, así que importar 58 y llenar 20
**reventaba el guardado en la fila 21** — y es el caso NORMAL: el informe de origen no trae
todas las competencias del plan.

Ahora: **sin `nota_literal`, la fila se omite**; con nota, los otros tres son obligatorios.
Las omitidas **se cuentan y se dicen** en el mensaje de éxito, porque una omisión silenciosa
parecería pérdida de datos.

#### Importar sin elegir nada AVISA (11/09/2026)

Pulsar «Traer competencias» sin marcar bimestre —o sin ninguna área— recargaba la pantalla
**idéntica**, y se leía como un botón roto. Ahora hay guarda en las **dos capas**, como el
resto del proyecto:

- **Servidor** (`MatriculaController::notasExternas`): `Session::flash('warning', …)` y
  `redirect()` a la misma ruta. Cubre los dos casos con mensajes distintos, y es la que
  manda: el POST de guardado nunca dependió del JS y esto tampoco.
- **Cliente** (`notas-externas.js`): no deja enviar el formulario y pinta un `.form-error`
  junto al botón. ⚠️ El mensaje se **crea desde el JS**, no se deja oculto en la vista, para
  no depender de que un `[hidden]` le gane al CSS de su contenedor — eso ya falló una vez en
  este proyecto (ver `docs/modulos/ui.md`). Los botones «Marcar todas» / «Ninguna» cambian
  las casillas **por código**, que NO dispara `change`: por eso el aviso también escucha el
  click.

#### Una sola lectura de la currícula y de los bimestres (11/09/2026)

Dos duplicaciones que traía la primera versión del importador, ambas medidas:

- **La currícula se leía DOS VECES por carga**: una para la card y otra dentro de
  `areasDeLaSeccion()`, que deriva de ella. Es la consulta más cara de la pantalla.
  `curriculaParaImportar()` ahora **memoriza por matrícula** en la instancia del modelo
  (no en propiedad estática: dos matrículas del mismo proceso siguen leyendo cada una la
  suya). Medido con `SHOW SESSION STATUS LIKE 'Questions'`: 1.ª llamada 2 consultas, 2.ª y
  `areasDeLaSeccion()` **0**, y otra matrícula vuelve a leer sus 2.
- **Los bimestres del año** salían de **dos consultas SQL idénticas escritas a mano** en el
  propio controlador. Ahora es **una** llamada a `AnioAcademicoModel::getPeriodos()`, que ya
  existía y los devuelve ordenados por número. ⚠️ Quedan **otras dos** consultas de periodos
  inline en `MatriculaController` (las de notas autorizadas SIAGIE), anteriores a este
  trabajo y no tocadas: si alguien las unifica, este es el método al que deben ir.

### Dos botones en la ficha y orden de la currícula (18/09/2026)

- **Ficha `/matriculas/{id}`: card «Registrar notas fuera del registro del docente»** con
  `<details>` nativos (sin JS) cuyo `summary` es el botón:
  «Registrar notas de I bimestre (solo informativo)» despliega la card de notas de origen
  (movida adentro sin cambios), y «Registrar notas de bimestres cerrados (Boleta
  SIAGIE-SIGACOCIAP)» despliega los bimestres con competencias sin nota, cada uno con su
  enlace a la grilla en lote. Este segundo solo lo ve admin/RA.
  **Tercer panel (18/09/2026):** «Registrar nota autorizada solo para SIAGIE (no va a la
  boleta)». Es la antigua card suelta de notas autorizadas por dirección (migración 040),
  movida adentro **sin cambiar datos ni enlaces**. Solo admin/RA. Como antes, sale también
  para un trasladado: en ese caso la card muestra **solo** este panel.
  - **Sin filtro por tipo ni estado** (decisión del usuario; ningún flag detecta el caso),
    **salvo el trasladado de SALIDA** (`tipo='trasladado'`): ahí la card no sale.
  - Los totales salen de `RectificacionModel::insertablesPorPeriodo`, que es el **mismo
    universo que la grilla** (ordinarias + transversales). ⚠️ El «Calificar todo el
    bimestre (N)» de `/rectificaciones/matricula/{id}` cuenta solo las ordinarias
    (preexistente): los dos números pueden diferir en las transversales.
- **`/docente/notas-origen/{id}` ordena según la currícula** de la sección
  (`NotaExternaModel::ordenarSegunCurricula`, sobre `curriculaParaImportar()`): área por
  `area_id` o, si falta, por el nombre del importador; competencia por su posición en el
  área. Lo que no calza con el plan va **al final**. No filtra nada, y «Tu área» sigue.
  Medido con la 693: 23 filas en orden del plan, y EPT y Religión (del otro colegio) al final.

### La competencia se recortaba a 120 caracteres (21/09/2026, migración 061)

🔴 **`competencia_nombre` era `VARCHAR(120)` y el `sql_mode` del servidor NO es estricto**:
MariaDB **recortaba el exceso en silencio**, sin error. El importador llena la competencia con
`competencias.nombre_completo` (TEXT), y **7 competencias del plan pasan de 120** (la más larga,
185). El docente veía la competencia cortada en `/docente/notas-origen/{id}` (fila 152 de la
693: «…biodiversidad, Tierra y Uni»).

- **Migración `061`**: la columna pasa a `VARCHAR(255)`. Sigue en la UNIQUE `uq_nota_externa`
  (~1630 bytes de 3072).
- **Reparación:** `database/reparar_notas_externas_truncadas.php` (simula por defecto,
  `--confirmar` aplica; aborta si la 061 no está). Completa el nombre solo si **una única**
  competencia del plan empieza por el texto recortado; lo ambiguo lo lista y no lo toca.
- **Rechazar, no recortar:** `NotaExternaModel::MAX_PERIODO/AREA/COMPETENCIA/COLEGIO` son
  **exactamente** el ancho de cada columna. `storeNotasExternas` rechaza lo que no cabe y el
  formulario usa las mismas constantes en su `maxlength`. `verif_notas_origen.php` §7f compara
  constantes con `information_schema` e ida y vuelta con la competencia más larga.
- ⚠️ **Cualquier otra columna de texto del repo tiene el mismo riesgo**: sin modo estricto, un
  texto largo se guarda cortado y nadie se entera. No se auditó el resto de tablas.

### La ficha sin `<details>`: datos a la vista, acción aparte (21/09/2026)

Deroga la presentación de «Dos botones en la ficha» (18/09): los tres paneles plegables de
«Registrar notas fuera del registro del docente» se deshicieron según la regla nueva de
`docs/modulos/ui.md` («Secciones de CONTENIDO VARIABLE»). **No cambia ningún dato ni guarda de
rol**, solo dónde y cuándo se pinta cada cosa:

- **Notas del colegio de origen**: card propia a todo el ancho, **solo si hay notas** (y no es
  un trasladado de salida). Acción en la cabecera: «Agregar o corregir notas» (quien matricula).
  Muestra además «Procede de …» si se anotó el colegio.
- **Notas autorizadas para SIAGIE**: card propia, **solo si hay notas** y admin/RA. Acciones:
  «Gestionar» e «Informe imprimible». Sigue saliendo para un trasladado.
- **Registrar notas fuera del registro del docente**: solo acciones, una fila por sección que
  aún no tiene datos, y los bimestres con competencias sin nota («Calificar todo el bimestre
  (N)» + «Ver detalle por competencia»). Si no queda ninguna acción, la card no sale.
- **Exoneraciones**: solo si hay alguna (su registro sigue en «Gestión de la matrícula»).
- SASS: `.mat-seccion-ancha` sustituye al `grid-column` de `.mat-llegada`; los estilos
  `__panel/__boton/__contenido` del `<details>` se retiraron.

