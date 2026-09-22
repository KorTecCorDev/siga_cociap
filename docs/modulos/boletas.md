# Módulo: Boletas

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.
>
> **OJO — lectura cronológica:** las secciones antiguas (sesiones 2/3/6) describen
> rutas por id y por código que YA NO EXISTEN; la sección
> "Boleta: documento único por token (24/06/2026)" las supersede. Rutas vigentes:
> público por token (`/boleta/digital/{token}`, `/boleta/ver/{token}`), interno
> docente (`/docente/boleta/{id}[/imprimir]`) e interno gestión
> (`/matriculas/{id}/boleta[/imprimir]`). Ante duda, `routes/web.php` manda.

## Módulo de boleta imprimible (sesión 2)
- **Ruta:** `GET /boleta/{matricula_id}/{periodo_id}`
- **Roles con acceso:** admin, director_general, director_ebr, registro_academico, secretaria, padre
- **Restricción padre:** solo puede ver la boleta de su propio hijo (403 en otro caso)
- **Layout:** `resources/views/layouts/print.php` — sin navbar, sin flash, solo `app.css`
- **Vista:** `resources/views/boleta/alumno.php`
- **Estilos:** `resources/sass/pages/_boleta.scss`
- **Impresora objetivo:** RICOH MP4054 PCL6 — margen `@page: 0.5cm` por todos los lados
- **IMPORTANTE:** La boleta solo muestra competencias cuyo docente haya aprobado/bloqueado.
  `CalificacionModel::getBoletaAlumno()` hace INNER JOIN con `bloqueos_competencia`.

### Decisiones de diseño de la boleta imprimible
- **Conclusión descriptiva:** columna integrada de 60mm en la misma fila de la
  competencia (estilo SIAGIE). CSS `line-clamp: 3` con puntos suspensivos nativos.
  El texto completo se guarda en BD; el truncado es solo presentación CSS.
- **Subárea:** se antepone al nombre de la competencia para áreas `con_subareas`
  (ej: `Aritmética — C23. Resuelve problemas...`). Las áreas-curso no llevan prefijo.
- **Primaria:** muestra solo literal (AD/A/B/C); **Secundaria:** nota numérica + literal.
- **Pie de página:** DOS firmas — Tutor(a) de Aula y Director(a) E.B.R. (sesión 7).
  Se eliminó "Padre/Madre/Tutor(a)". Las líneas se alinean con `boleta-footer__espacio-firma`
  de 18mm fijo en ambos bloques (firma PNG anclada al fondo con `align-items: flex-end`).
- **buildBoletaData():** lógica de carga de datos extraída a método privado compartido
  entre `ver()` (imprimible) y `verDigital()` (digital). Incluye `directorEbr` con
  `firma_path` y `sello_path` del Director EBR vigente.

## Módulo de boleta digital (sesión 3)
- **Ruta:** `GET /boleta/digital/{matricula_id}/{periodo_id}`
- **Mismos roles y restricción de padre** que la boleta imprimible.
- **Layout:** `resources/views/layouts/digital.php` — sin navbar, carga `boleta-digital.js`
- **Vista:** `resources/views/boleta/digital.php`
- **Estilos:** `resources/sass/pages/_boleta-digital.scss`
- **JS:** `public/js/boleta-digital.js` — acordeones, QR (Google Charts API), toast PDF

### Características de la boleta digital
- **Mobile-first, responsive** — 3 breakpoints: < 640px, 640-959px, ≥ 960px
- **Conclusiones descriptivas completas** — sin `line-clamp`, texto íntegro visible
- **Cards expandibles** por área curricular — colapsadas por defecto en móvil
- **QR de verificación** — generado en cliente con `qrcode.min.js` (librería local, sin terceros)
- **Botón PDF** — abre diálogo de impresión del navegador con instrucción "Guardar como PDF"
- **Print A4 portrait** — `@media print` expande todos los acordeones automáticamente
- **BEM prefix `.bd-`** en todos los elementos del componente
- **Logros con color semántico:** AD=verde, A=azul, B=naranja, C=rojo con borde izquierdo
- **`beforeprint` event** expande acordeones al usar Ctrl+P nativo
- **Pie de página:** DOS firmas — Tutor(a) de Aula y Director(a) E.B.R. (sesión 7).
  Pantalla: sello PNG del director (`.bd-solo-pantalla`). Al imprimir: firma PNG + nombre
  (`.bd-solo-impresion`). Alineación con `bd-footer__img-area` de 44px/14mm fijo.
- **IMPORTANTE — orden de rutas:** la ruta literal `/boleta/digital/...` debe registrarse
  ANTES del patrón `/boleta/{matricula_id}/...` en `routes/web.php`, o el router la captura
  primero con parámetros incorrectos.

## Módulo de boletas públicas con código de acceso (sesión 6)

### Propósito
Durante el I Bimestre NO se dará acceso con login a los ~1000 padres.
En su lugar: el admin genera boletas por bimestre, cada una con un
**código de acceso único**, se imprimen con el código + QR y se entregan
físicamente. El padre consulta la **boleta digital pública** (sin login)
ingresando ese código o escaneando el QR.

### Compatibilidad con lo existente
Este módulo NO reemplaza nada. Reutiliza la infraestructura ya construida:
- **Reutiliza** `CalificacionModel::getBoletaAlumno()` (ya filtra por
  `bloqueos_competencia` — solo muestra competencias aprobadas).
- **Reutiliza** `buildBoletaData()` y la vista `resources/views/boleta/digital.php`
  ya existente de la sesión 3 — la boleta pública renderiza el mismo
  componente `.bd-` pero a través de una ruta sin autenticación.
- **No toca** las rutas `/boleta/{id}/{id}` ni `/boleta/digital/{id}/{id}`
  existentes (esas siguen siendo para usuarios autenticados / padres).
- El acceso público es una **capa nueva y paralela**, no una modificación.

### Base de datos — nueva tabla
`database/migrations/005_boletas_publicas.sql`
```sql
CREATE TABLE IF NOT EXISTS boletas_publicas (
    id               INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    matricula_id     INT UNSIGNED NOT NULL,
    periodo_id       SMALLINT UNSIGNED NOT NULL,
    codigo_acceso    VARCHAR(30) NOT NULL UNIQUE,
    veces_consultada INT UNSIGNED NOT NULL DEFAULT 0,
    ultima_consulta  DATETIME NULL,
    generada_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generada_por     INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_matricula_periodo (matricula_id, periodo_id),
    FOREIGN KEY (matricula_id) REFERENCES matriculas(id),
    FOREIGN KEY (periodo_id)   REFERENCES periodos(id),
    FOREIGN KEY (generada_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
Formato del código: `COCIAP-2026-B1-XXXXXX` (XXXXXX = 6 alfanuméricos
mayúsculos aleatorios, sin caracteres ambiguos: sin O/0/I/1/L).
Insertar en orden de ejecución SQL después de `004_limpiar_datos_semilla.sql`.

### Modelo nuevo
`app/Models/BoletaPublicaModel.php` extiende `BaseModel`:
- `generarCodigo(int $anio, int $numBimestre): string` — código único verificado
- `generarMasivo(int $periodoId, int $usuarioId): int` — INSERT IGNORE para
  todas las matrículas `aprobada` con ≥1 calificación bloqueada en el periodo
- `getPorPeriodo(int $periodoId): array` — lista con estudiante, grado, sección
- `getPorCodigo(string $codigo): ?array` — busca por código; si existe
  incrementa `veces_consultada` y setea `ultima_consulta`; retorna
  `matricula_id` + `periodo_id` para reutilizar `getBoletaAlumno()`

### Controlador admin
`app/Controllers/Admin/BoletaPublicaController.php`
hereda `BaseController`, `requireRole(['admin','registro_academico'])`:
- `index()` — `GET /admin/boletas-publicas` — selector de periodos
- `porPeriodo($periodoId)` — `GET /admin/boletas-publicas/{periodo_id}`
- `generar($periodoId)` — `POST /admin/boletas-publicas/{periodo_id}/generar`
- `imprimir($periodoId)` — `GET /admin/boletas-publicas/{periodo_id}/imprimir`
  usa `layouts/print.php`, una boleta por página con código + QR visibles

### Controlador público (SIN login)
`app/Controllers/BoletaPublicaController.php` hereda `BaseController`
pero **NO** llama `requireAuth()` ni `requireRole()`:
- `formulario()` — `GET /boleta-publica` — campo para ingresar código
- `consultar()` — `POST /boleta-publica/consultar` — valida código,
  reutiliza `CalificacionModel::getBoletaAlumno()`, renderiza la boleta
  digital pública; código inválido → mensaje de error

### Rutas (routes/web.php)
```php
// Boletas públicas SIN login — registrar ANTES de las rutas /boleta/{id}
$router->get('/boleta-publica',           'BoletaPublicaController@formulario');
$router->post('/boleta-publica/consultar','BoletaPublicaController@consultar');
// Admin
$router->get('/admin/boletas-publicas',                       'Admin\BoletaPublicaController@index');
$router->get('/admin/boletas-publicas/{periodo_id}',          'Admin\BoletaPublicaController@porPeriodo');
$router->post('/admin/boletas-publicas/{periodo_id}/generar', 'Admin\BoletaPublicaController@generar');
$router->get('/admin/boletas-publicas/{periodo_id}/imprimir', 'Admin\BoletaPublicaController@imprimir');
```
**IMPORTANTE — orden de rutas:** igual que la lección de sesión 3 con
`/boleta/digital`, las rutas literales `/boleta-publica` deben ir ANTES
que cualquier patrón `/boleta/{matricula_id}/...` para que el router no
capture "publica" como parámetro.

### AuthMiddleware
Agregar `/boleta-publica` y `/boleta-publica/consultar` al array de rutas
públicas en `app/Middleware/AuthMiddleware.php` (junto a `/login`, etc.).

### Vistas
- `resources/views/admin/boletas-publicas/index.php` — selector periodos (layout app)
- `resources/views/admin/boletas-publicas/periodo.php` — tabla + botón generar (layout app)
- `resources/views/admin/boletas-publicas/imprimir.php` — `layouts/print.php`,
  una boleta por página, código + QR visibles, page-break-after
- `resources/views/boleta-publica/formulario.php` — `layouts/digital.php`,
  diseño institucional simple, logo COCIAP, campo código + botón
- `resources/views/boleta-publica/boleta.php` — `layouts/digital.php`,
  reutiliza el componente `.bd-` de `boleta/digital.php` (boleta completa)

### Estilos
Reutilizar `_boleta-digital.scss` (componente `.bd-`). Solo agregar lo mínimo
para el formulario de código en `_boleta-digital.scss` o un parcial nuevo
`resources/sass/pages/_boleta-publica.scss` importado en `app.scss`.
NUNCA CSS inline en PHP (convención del proyecto).

### Reglas de negocio
- Primaria: solo literal (AD/A/B/C). Secundaria: numeral + literal.
- Solo competencias con docente que aprobó/bloqueó (ya lo garantiza
  `getBoletaAlumno()` con su INNER JOIN a `bloqueos_competencia`).
- Código permanente (no se regenera). Toda la boleta visible (no resumen).
- QR generado con `qrcode.min.js` LOCAL (nunca servicios de terceros — la
  mención original a Google Charts quedó obsoleta). El QR apunta a
  `/boleta-publica` con el código.
- Vista pública sin sesión, sin navbar, sin datos de otros alumnos.
- CSRF con `$this->validateCsrf()` en `POST /boleta-publica/consultar`.


## Boleta: documento único por token (24/06/2026)

> Consolidación de la boleta como **documento oficial único** (digital + imprimible)
> servido SIEMPRE por token. Se retiraron las rutas anónimas por id (enumerables),
> se unificó el ensamblado en un solo modelo y el código tecleado quedó dormido.

### Builder único — `app/Models/BoletaModel.php`
- `armar(int $matriculaId, int $periodoId, bool $soloOficiales = false): ?array` —
  **fusiona las 3 copias** que vivían en `Boleta\BoletaController`,
  `BoletaPublicaController` (público) y `Admin\BoletaPublicaController`. Punto ÚNICO
  de verdad del documento (agregación, transversales, exoneraciones, conducta,
  asistencia, tutor, directorEbr). Es PURO: data in → array out; la autorización
  vive en los entry points.
- **`soloOficiales=true` → solo bimestres `cerrado`** (regla de familias: el BORRADOR
  de Hito A nunca se expone al público). Lo usan las rutas por token. `false` (docente,
  salida masiva admin) muestra todos los periodos.
- **El builder duplicado del controlador público (dormido) NO se tocó** (queda con su
  propia copia para resurrección; sus rutas están comentadas).

### Render y QR únicos — `Boleta\BoletaController::render()`
- Entry points DELGADOS: cada uno decide quién + qué periodo, y delega en `render()`.
- **El QR sale SIEMPRE de `urlBoletaToken()`** (token de la matrícula IDENTIDAD) — una
  sola fuente, fin de las 6 variantes que causaban el bug del QR. La boleta se ancla a
  la identidad (`$data['alumno']['matricula_id']`) para coincidir en retorno de grado.

### Direccionamiento (invariante de seguridad)
- **Cero rutas ANÓNIMAS por id.** Se **borraron** `GET /boleta/{id}/{periodo}` (`ver`) y
  `GET /boleta/digital/{id}/{periodo}` (`verDigital`) — eran enumerables.
- **Público (familias):** SIEMPRE por token, solo oficiales:
  `GET /boleta/digital/{token}` (`verDigitalToken`) y `GET /boleta/ver/{token}` (`verToken`).
- **Interno (docente/admin):** autenticado por id + alcance (`/docente/boleta/{id}[/imprimir]`),
  puede ver BORRADOR. Por estar tras login + 403 por alcance NO es enumerable → se queda por id.
- `padre/notas` enlaza por token (su controlador resuelve el token vía `getOCrearToken`).
- **(02/07/2026, commit `8dcff8a`)** `matriculas/show` YA NO enlaza por token: usa el
  flujo INTERNO (`GET /matriculas/{id}/boleta[/imprimir]` →
  `verDigitalMatricula`/`verImprimirMatricula`, roles admin/registro_academico/
  secretaría_academica/secretaria_administrativa) para que gestión vea el BORRADOR,
  igual que el docente. La pública por token sigue mostrando SOLO lo oficial.

### Formato: las CUATRO columnas de bimestre siempre (26/07/2026)
`/boleta/ver/{token}` se arma con `estructuraCompleta = true`, así que dibuja los cuatro
bimestres del año aunque estén vacíos — el formato oficial del documento, la misma regla
que ya aplicaba la boleta del trasladado (regla de formato 09/07/2026). Antes colapsaba
las columnas a las publicadas y la familia recibía en B1 una boleta de una sola columna.

Los **datos** no cambian: el guard de `armar()` sigue exigiendo bimestre oficial **y**
publicado, así que II, III y IV quedan vacías hasta que se publiquen. Esto **no debilita
la compuerta 044**: con la estructura anual fija las columnas están todo el año y una
vacía no informa de nada. Colapsarlas era justo lo que delataba el cierre, porque solo
aparecía columna cuando había datos.

> Pendiente decidir: la boleta **digital** por token sigue colapsando columnas (es
> mobile-first), igual que el bloque de asistencia, que tiene su propio filtro.

### Tracking de visitas — `matriculas.token_consultas` (migración `028`)
- `028_boleta_token_tracking.sql`: `token_consultas INT` + `token_ultima_consulta DATETIME`,
  con **backfill** desde `boletas_publicas.veces_consultada` (preserva el histórico B1).
- `BoletaPublicaModel::registrarVisitaToken(int $matriculaId)` reescrito: `UPDATE matriculas`
  (ya NO toca `boletas_publicas`). **Cuenta TODO acceso por token** (escaneo de QR o portal
  del padre, digital o impreso). El token es por estudiante → conteo por identidad.
- `BoletaPublicaModel::getOCrearToken(int $matriculaId): string` — token hex-32 permanente,
  get-or-create idempotente.

### Salida masiva (sobrevive, re-apuntada a token)
- `Admin\BoletaPublicaController::{vistaPrevia,boletasAlumno,archivar}` iteran
  `getMatriculasAprobadasParaBoleta` (NO `getPorPeriodo`/código) y arman con
  `BoletaModel::armar` + `urlBoletaToken`. **Independientes del código.**
- Hub `porPeriodo` + vista `periodo.php`: ahora token-céntricos
  (`getEstudiantesParaPeriodo` con `token_consultas`); botones de impresión gated por
  `total_aprobables > 0`. `index.php` cuenta boletas oficiales (no códigos).

### Código tecleado — DORMIDO (conservado para reactivar)
- **Borradas** las rutas: público `/boleta-publica` + `/consultar`; admin `/{per}/generar`,
  `/{per}/actualizar`, `/{per}/imprimir` (hoja de códigos). **Quedan comentadas en
  `routes/web.php` con el snippet para reactivarlas.**
- **Conservado intacto** (sin ruta): `BoletaPublicaController` (público), métodos
  `generar`/`actualizar`/`imprimir` del admin, vistas `boleta-publica/*` e `imprimir.php`,
  tabla `boletas_publicas` y `BoletaPublicaModel::{generarMasivo,getPorCodigo,...}`.
- La generación de **token** (`generar-tokens`) sigue activa.

## Logro anual = nota del último bimestre, solo al cerrarlo (02/07/2026)

> **Bug corregido:** el chip "Anual" (logro final del año) de las boletas mostraba
> el **promedio de los bimestres cerrados** y aparecía apenas se cerraba CUALQUIER
> bimestre — p. ej. con B1/B2 cerrados y B3 activo, mostraba un "anual" que en
> realidad era el promedio de B1-B2. Debe ser la **nota del ÚLTIMO bimestre del año**
> (el 4.º) y aparecer **solo al cerrar ese último bimestre**.

### Causa raíz
- `BoletaModel::armar(soloOficiales=true)` (boleta por token/QR de familias) filtra
  los periodos a `estado='cerrado'` (`getPeriodosDelAnio`), así que `$periodos` NO
  eran los 4 del año sino "los cerrados hasta ahora".
- `buildAreasConBimestres` calculaba `literal_final` promediando sobre ESOS periodos
  (`array_column($periodos,'id')`). Con un solo bimestre cerrado, el chequeo "tiene
  nota en todos los periodos" pasaba trivialmente y el promedio de 1-2 bimestres se
  mostraba como logro anual. El comentario "solo cuando los 4 bimestres tienen nota"
  reflejaba la INTENCIÓN, no el código.

### Regla nueva (decisiones del usuario)
1. **Logro anual = literal de la nota del ÚLTIMO bimestre del año** (mayor `numero`),
   NO un promedio y NO el último bimestre CERRADO (modelo por competencias: el nivel
   alcanzado al final del año).
2. **Solo aparece al cerrar ese último bimestre**; mientras no, el chip "Anual" = `—`.
3. **"Último bimestre" es dinámico** por `MAX(numero)` (no hardcodea 4).
4. **Aplica a TODAS las boletas** (builder único): digital, imprimible, token e interna.

### Implementación (sin migración, sin cambio de vista)
- `BoletaModel::getUltimoBimestreDelAnio(int $anioId): ?array` — `ORDER BY numero
  DESC LIMIT 1` (id/numero/estado).
- `armar()` deriva `$ultimoBimestreId` + `$ultimoCerrado = estado==='cerrado'` y los
  pasa a `buildAreasConBimestres(..., $ultimoBimestreId, $ultimoCerrado)`.
- `buildAreasConBimestres`: `literal_final = $comp['bimestres'][$ultimoBimestreId]
  ['literal']` **solo si `$ultimoCerrado`** y hay nota; si no → `null`. Se eliminó el
  promedio y la variable `$periodIds`.
- **Duplicado dormido** `BoletaPublicaController::buildAreasConBimestres` (código
  tecleado dormido, sin rutas) parcheado con la MISMA regla para paridad futura.
- **EXO intacto:** `ExoneracionModel::inyectarEnAreas` setea su propio
  `literal_final='EXO'` DESPUÉS y respeta el ya calculado.
- **Retroactivo nulo:** al 02/07/2026 ningún año está completo (el IV bimestre nunca
  se cerró), así que no había logro anual correcto previo — el fix solo elimina el
  valor erróneo. La vista `boleta/{digital,alumno}.php` ya lee `literal_final` (sin
  cambios).

## Boleta — asistencia por cada bimestre cerrado + Total (02/07/2026)

> **Bug corregido:** la tabla de asistencia de la boleta mostraba solo el bimestre
> activo (el último cerrado) con datos. En la imprimible las demás columnas salían
> "—"; en la digital solo había `[{bim} | Acum. anual]`. Debía mostrar **una columna
> por cada bimestre CERRADO** (todos los registrados) **+ una columna Total** con las
> sumas.

### Causa
- `BoletaModel::armar()` entregaba `asistencia = { bimestre: getDelBimestreUnion(
  $periodoId), anual: getAcumuladoAnualUnion($periodoId) }` — SOLO el periodo activo
  + un acumulado, sin desglose por bimestre.
- `boleta/alumno.php` YA tenía el andamiaje de columnas por periodo, pero solo podía
  llenar la del activo (el resto "—") porque el builder no daba datos por periodo.

### Regla nueva (decisiones del usuario)
1. **Una columna por cada bimestre CERRADO** (todos los registrados) + **Total**.
2. **Solo cerrados** en TODAS las boletas (familias e interna del docente),
   independiente de `$soloOficiales`.
3. **Total = suma de los bimestres mostrados** (NO `getAcumuladoAnual` por `numero<=`,
   que podría incluir un bimestre no mostrado si uno intermedio se reabriera).

### Implementación (sin migración, sin cambio de esquema)
- `BoletaModel::armar()`: nueva estructura
  `asistencia = ['bimestres' => [ ['id','numero','datos'=>counters], … ], 'total' => sumas]`.
  Itera `getPeriodosDelAnio($anioId, true)` (cerrados, siempre) y acumula el total.
  Reemplaza las claves `bimestre`/`anual`.
- Vistas `boleta/digital.php` y `boleta/alumno.php`: la tabla itera
  `$asistencia['bimestres']` (una columna por bimestre, cada una con SU dato) + columna
  **Total** = `$asistencia['total']`. El guard pasa a `!empty($asistencia['bimestres'])`
  (sin cerrados → la tabla no se muestra). La imprimible dejó de pintar "—".
- SASS `_boleta-digital.scss`: `table-layout: auto`, `.bd-asistencia__scroll`
  (overflow-x en móvil, hasta 4 bim + Total) y `--total` con borde izquierdo.
- **Consumidores:** solo esas 2 vistas + el builder (verificado). El
  `BoletaPublicaController` dormido NO arma asistencia → no se toca. Los métodos
  `AsistenciaModel::getAcumuladoAnual*` quedan sin uso desde la boleta (se conservan).

## Asistencia: mismo umbral que las notas (04/08/2026) — deroga la regla 2 de arriba

> **Síntoma:** en `/admin/boletas-publicas/{id}/vista-previa` no aparecía la asistencia
> del bimestre en curso, pese a tener las secciones aprobadas y bloqueadas en
> `/admin/asistencia`. **No era un problema de datos:** la asistencia se filtraba por
> `periodos.estado = 'cerrado'`, y bloquear el registro de una sección
> (`cierres_asistencia`) **no cierra el bimestre**. Son dos actos distintos.

### La asimetría de fondo
`armar()` aplicaba la excepción de la vista previa (`$datos === 'todos'`) en dos de los
tres bloques que dependen del periodo, y la asistencia era el único que no:

| Bloque | Antes, con `'todos'` |
|---|---|
| Notas | aporta siempre (`periodoAportaNotas` → `true`) |
| Conducta | no se filtra |
| **Asistencia** | **`estado = 'cerrado'`, sin excepción** |

Resultado: la misma hoja mostraba las notas y la conducta de B2 pero no su asistencia,
justo en la herramienta con la que RA decide el Hito A.

### Regla nueva (decisiones del usuario, 04/08/2026)
1. La asistencia usa **el mismo umbral que las notas**: `periodoAportaNotas($p, $datos,
   $publicados)`. Se acabó el criterio propio. **Deroga la regla 2 de la sección
   anterior** ("solo cerrados en TODAS las boletas").
2. Alcance: `'todos'` **y `'borrador'`**. Si una boleta de docente ya muestra las notas
   del bimestre con Hito A, debe mostrar también su asistencia.
3. Los bimestres sin dato se pintan **apagados** (`--pendiente`, guion) en lugar de
   ceros: un `0` se lee como "no faltó ningún día", y ahí no hay dato que mostrar.
4. El **total suma el bimestre en curso** (solo los que tienen registro). En vista previa
   es, por tanto, provisional.

> ⚠️ Esta sección describe **qué bimestres APORTAN datos**. La **estructura** (cuántas
> columnas se dibujan) se corrigió después, el mismo día: son **siempre las 4**. Ver
> "La tabla de asistencia también es estructura anual" más abajo.

### Lo que NO cambió — verificado modo por modo
| Modo | Quién lo usa | Regla | ¿Cambia? |
|---|---|---|---|
| `oficial` | familias (token, digital, /padre/notas) | cerrado **+ publicado** (044) | **no** |
| `archivo` | impresión masiva de staff | cerrado | **no** |
| `borrador` | boleta de docente/admin (`requireRole(['docente','admin'])`) | cerrado **o** activo con Hito A | sí |
| `todos` | **solo** `Admin\BoletaPublicaController@vistaPrevia` | todos los bimestres | sí |

La equivalencia en `oficial`/`archivo` es exacta porque `boleta_estado_bimestre()`
devuelve `'oficial'` **si y solo si** el periodo está `cerrado` — el mismo conjunto que
daba el filtro viejo.

### Implementación
- `BoletaModel::armar()`: itera `getPeriodosDelAnio($anioId)` (todos) y descarta con
  `periodoAportaNotas`. Cada columna suma `sin_registro` (bool) al array; el total solo
  acumula las que tienen registro.
- `boleta/alumno.php`: la celda usa `--pendiente` + `&ndash;` cuando `sin_registro`.
  **`boleta/digital.php` NO se tocó**: se arma con `'oficial'` o `'borrador'`, umbrales
  que nunca dejan pasar un bimestre `pendiente`.
- El SASS ya tenía `.boleta-asistencia__num--pendiente` ("Bimestres sin datos aún") sin
  ningún consumidor desde su creación; este cambio lo conecta. Sin Gulp: ya estaba
  compilado en `public/css/app.css`.
- Verificación: `database/verificaciones/verif_asistencia_boleta.php` (transacción +
  ROLLBACK; **simula el Hito A** del bimestre activo, porque sin él el caso `'borrador'`
  daría lo mismo que `'archivo'` y la aserción no probaría nada).

## Boletas de matrículas desactivadas — vías internas (09/07/2026)

> Antes NINGUNA vía mostraba la boleta de una matrícula `desactivado` (traslado,
> deuda, etc.): los tres resolvers filtraban `estado <> 'desactivado'`. Los datos
> siempre persistieron (`armar()` es puro). Decisión cerrada con el usuario
> (08-09/07/2026); implementado el 09/07/2026.

### Regla
- **Gestión** (`/matriculas/{id}/boleta[/imprimir]`, roles actuales: admin, RA y
  ambas secretarías — los directores quedaron explícitamente FUERA): ve e imprime
  la boleta de CUALQUIER matrícula, incluidas desactivadas y trasladadas.
- **TRASLADADO consumado (`estado='desactivado' AND tipo='trasladado'`) — regla
  refinada 09/07/2026:** su boleta vía gestión es **exclusivamente OFICIAL** — el
  alumno ya tuvo su última boleta oficial y sus notas jamás cambiarán aquí. Se
  sirve con `armar(soloOficiales=true, estructuraCompleta=true)`: **estructura
  anual completa** (las 4 columnas de bimestres, regla de formato de abajo) con
  **DATOS solo de bimestres CERRADOS** (los no cerrados van como columnas
  vacías); **sin banner, CON firma del director y SIN QR** (opción `sinQr` de
  `render()`: el token está muerto y un QR impreso dirigiría a "no encontrado").
  La conducta también se filtra a bimestres cerrados. El periodo se ancla al
  último bimestre CERRADO con notas (`periodoPublicableConNotas(...,
  soloCerrados=true)`); **sin cerrados → 404** (nunca tuvo boleta oficial; su
  documento de salida es la constancia de traslado). Las notas parciales del
  bimestre en curso al momento del traslado quedan FUERA (nunca fueron oficiales).
- **REGLA DE FORMATO (09/07/2026): la boleta mantiene SIEMPRE la estructura
  anual completa** — todas las columnas de bimestres del año; los filtros
  (`soloOficiales`) aplican a los DATOS insertados, no a las columnas.
  `armar()` la implementa con el parámetro `estructuraCompleta`. **Deuda
  técnica consciente:** la boleta por token de familias sigue en el
  comportamiento histórico (columnas colapsadas a cerrados,
  `estructuraCompleta=false`) — el usuario decidió limitarlo al modo trasladado
  por ahora; al migrar el token a la regla, revisar que conducta/notas no
  oficiales no se filtren (el guard de datos ya existe en `armar`).
- **Docente** (`/docente/boleta/{id}[/imprimir]`): ve la boleta de todos los de su
  grilla — `aprobada`, `pendiente` y `desactivado`. Los `tipo='trasladado'` dan
  **403** (nuevo filtro explícito `m.tipo <> 'trasladado'` en `resolverBoletaDocente`;
  antes los cubría de facto el filtro de estado).
- **Invariante — jamás versión OFICIAL de un desactivado NO trasladado** (deuda,
  baja administrativa): `vistaPrevia = true` forzada en toda vía interna (banner
  BORRADOR, sin QR, sin firma en la imprimible), incluso con bimestre cerrado —
  sigue matriculado de facto y sus notas están en flujo. El trasladado es la
  EXCEPCIÓN deliberada (ver regla refinada arriba): registro histórico cerrado.
- **Token público y panel del padre: SIN CAMBIOS.** `resolveToken` conserva su
  `estado <> 'desactivado'` (el QR impreso de un trasladado da 404). El documento
  oficial de un trasladado sigue siendo la constancia de traslado.
- Las matrículas `pendiente` NO se fuerzan a borrador (siguen la regla del periodo,
  como siempre); su exclusión de documentos oficiales la garantizan la salida
  masiva y el orden de mérito (filtran `aprobada`), no la boleta interna.

### Implementación
- `Boleta\BoletaController`: `resolverBoletaDocente` y `resolverBoletaGestion` ya
  no excluyen `desactivado`; retornan `array{periodo_id, estado_matricula[, tipo]}`.
  `optsBoletaGestion()` decide el render de gestión: trasladado →
  `{soloOficiales, vistaPrevia=false, sinQr}`; otro desactivado → vistaPrevia
  forzada; resto → regla normal del periodo. `render()` acepta `sinQr` (vacía
  `url_boleta`; las vistas ya omiten el QR con url vacía, sin tocarlas).
- `matriculas/show.php`: la card "Boleta" dejó de estar gated por `$esActivo`
  (visible en cualquier estado); nota diferenciada: trasladado → "última boleta
  oficial"; otro desactivado → "se emite solo como borrador".
- **Nómina docente** (`Docente\PanelController` + `docente/nomina.php`):
  - `getMatriculados(..., bool $soloAprobadas = true)`: el buscador en vivo pasa
    `false` → incluye `pendiente` y `desactivado` (espejo de la grilla); la
    **nómina IMPRIMIBLE** (`nominaImprimir`) usa el default → **solo `aprobada`**
    (documento oficial SIAGIE, no relajar). El SELECT proyecta `m.estado`.
  - El selector de impresión (`$secciones`) solo cuenta filas `aprobada` (si no,
    inflaría los conteos del documento oficial).
  - Card del buscador: badge `.matricula-badge--pendiente/--desactivado` (reusa el
    global de `_matriculas.scss`) junto al nombre; para desactivados el panel de
    boleta se marca `--borrador` y su etiqueta dice siempre "Borrador · {bim}".
  - `getNominaResumen` (dashboard) y `nomina.js` sin cambios (`data-buscar` intacto).

### ✅ RESUELTO (07/08/2026) — el SELLO no aparece en borrador ni en vista previa

> Estuvo registrado como *"nota conocida, preexistente"* desde el lote de matrículas
> desactivadas, con la salida abierta: *"si se quiere que el borrador digital tampoco
> muestre/imprima el sello, es un ajuste aparte"*. **El usuario lo decidió el 07/08/2026
> al revisar la boleta digital: el sello del Director EBR no debe aparecer JAMÁS en
> versiones BORRADOR o vista previa.** Solo el sello; el resto del pie no se toca.

**El defecto:** `digital.php` pintaba `sello_path` **sin gate de `$vistaPrevia`**, mientras
que la imprimible (`alumno.php`) sí suprimía su `firma_path`. Son **dos assets distintos**
del Director EBR y cada vista pinta uno solo, así que el hueco quedó en una sola línea.

**Incumplía un contrato ya escrito:** el docblock de `archivarBorrador` define
`$vistaPrevia = true` como *"sin QR y sin imagen de firma del director"*. Y en el mismo
`digital.php` el **QR sí lo respetaba** (`!empty($url_boleta) && !$vistaPrevia`) — era una
omisión puntual, no un criterio distinto.

**Alcance real, que era mayor de lo que sugería la nota:** la entrada más expuesta no eran
los desactivados sino la **boleta digital del docente** (`verDigitalDocente`), donde
`vistaPrevia = estado === 'desactivado' || estadoBoletaDePeriodo(...) !== 'oficial'`. Con el
bimestre sin cerrar eso es `true` **para todos los alumnos**, así que cualquier docente que
abriera la digital veía el sello en un documento provisional.

**El arreglo:** un término más en el mismo `if` (`!$vistaPrevia &&`). El contenedor
`.bd-footer__img-area` se conserva **vacío** para que el pie no cambie de alto entre la
vista previa y el definitivo — mismo criterio que `boleta-footer__espacio-firma` en la
imprimible. No hizo falta ninguna regla `@media print`: si el flag está puesto, el `<img>`
**no se emite**, así que la impresión y el PDF quedan cubiertos por construcción.

⚠️ **Los demás documentos con sello o firma NO se tocaron** y no comparten el problema:
nóminas, actas de desempate, reporte de mérito, horarios, resumen de matrícula, informe
SIAGIE y constancia de traslado **no tienen modo borrador** (son definitivos). La
constancia ya usaba el mismo criterio con `!$anulada`. Barrido completo hecho el 07/08.

## Compuerta del Hito A — la nota aparece solo tras la aprobación de RA (09/07/2026)

> **Bug corregido:** un docente bloqueaba su competencia del II Bimestre (aún en
> `registro`, sin Hito A) y esa nota YA aparecía en la boleta interna (docente y
> gestión) — sin banner BORRADOR, porque el ancla era el I Bimestre cerrado. La
> boleta INTERNA se armaba con `armar(soloOficiales=false)`, que insertaba datos de
> TODO periodo con competencia bloqueada, sin mirar el estado del bimestre. La
> compuerta del Hito A ahora rige en todos los procesos de boleta.

### Regla (punto único de verdad: `boleta_estado_bimestre`)
Un bimestre aporta NOTAS a la boleta según su estado de boleta (`helpers.php`):
- **`oficial`** (cerrado) → familias.
- **`borrador`** (activo + `boletas_aprobadas_en`, Hito A) → interno.
- **`registro`** (activo sin Hito A) → NO aporta notas (aunque el docente ya haya
  bloqueado su competencia).

`cerrado` implica que el Hito A quedó registrado: el cierre
(`PeriodoController::cerrar` → `AnioAcademicoModel::marcarBoletasAprobadas`) setea
`boletas_aprobadas_en` con `COALESCE(..., NOW())`. Por eso la compuerta de familias
sigue siendo "solo cerrado" (estrictamente posterior al Hito A) sin contradecir la
regla. (Anomalía histórica: B1 local quedó `cerrado` con `boletas_aprobadas_en=NULL`
por ser previo a la migración 025; `boleta_estado_bimestre` lo trata como `oficial`
por `estado='cerrado'`, así que muestra correctamente.)

### `armar()` — parámetro `$datos` (reemplaza el bool `$soloOficiales`)
`armar(int $matriculaId, int $periodoId, string $datos = 'oficial', bool $estructuraCompleta = false)`:
- **`'oficial'`** → solo bimestres `cerrado` (familias: token, salida masiva con QR).
- **`'borrador'`** → `cerrado` o `borrador` (interno: docente, gestión no trasladado);
  un bimestre en `registro` queda como **columna vacía** (estructura completa).
- **`'todos'`** → incluye `registro` (ÚNICA excepción: vista previa de RA).
- El guard por periodo vive en el helper privado `BoletaModel::periodoAportaNotas`;
  la conducta se filtra con el mismo conjunto de periodos que aportan.
- `getPeriodosDelAnio` ahora también trae `boletas_aprobadas_en` (lo necesita el guard).

### Mapa de umbrales por entry point
- **Familias (`'oficial'`):** token (`verToken`/`verDigitalToken`), salida masiva con
  QR (`Admin\BoletaPublicaController::boletasAlumno` y `archivar` — antes usaban el
  default y filtraban solo notas; ahora `'oficial'`, coherente con lo que resuelve el
  QR). El export SIAGIE ya exige `cerrado` (no usa `armar`).
- **Interno (`'borrador'`):** docente (`verDigital/ImprimirDocente`) y gestión no
  trasladado (`optsBoletaGestion`). El trasladado sigue `'oficial'`+`estructuraCompleta`.
- **Excepción (`'todos'`):** `Admin\BoletaPublicaController::vistaPrevia` — herramienta
  de RA para decidir el Hito A; staff, sin QR, marcada BORRADOR. Muestra el bimestre
  en `registro` a propósito.
- `Padre\PanelController::notas` (F4 último cerrado) no usa `armar`; ya solo oficial.

### Verificado end-to-end
Sembrando una competencia bloqueada + criterio confirmado en B2 (registro): docente y
gestión NO la ven; tras simular el Hito A (`boletas_aprobadas_en`) SÍ la ven con banner
BORRADOR; el token de familias nunca la ve; la vista previa de RA sí la muestra
(excepción); la salida masiva solo muestra lo cerrado con QR. El guard anti-fantasma de
`getBoletaAlumno` (migración 033) sigue exigiendo criterio vivo y confirmado.

## Compuerta de publicación de boletas (21/07/2026, migración 044)

> **Bug de negocio corregido:** poner un bimestre en `cerrado` publicaba sus boletas
> a las familias AL INSTANTE. Pero las boletas se entregan en **reuniones oficiales**
> y primaria se entrega, por lo general, **un día antes** que secundaria: el colegio
> necesita cerrar (congelar notas, generar el snapshot de mérito, exportar al SIAGIE)
> sin que las familias vean nada todavía.

### Regla
**Cerrar NUNCA publica.** Publicar es un acto separado de RA/admin, **por NIVEL** y
con **fecha/hora** (inmediata o programada). Alcance = las 3 superficies de familias:
boleta por token, boleta digital y `/padre/notas`.

### Modelo de datos — `periodos_publicacion`
`(periodo_id, nivel_id, publica_en, suspendida_en, despublicada_en, despublicada_por,
motivo_despublicacion, publicado_por, creado_en)`, UNIQUE `(periodo_id, nivel_id)`.

| Fila | Significado |
|---|---|
| sin fila | no publicado |
| `publica_en` futuro | **programado** (invisible hasta la hora exacta) |
| `publica_en` pasado | **publicado** |
| `suspendida_en` | suspendido por reapertura — **REVERSIBLE** |
| `despublicada_en` | retirado a mano — **DEFINITIVO** (solo republicar a mano lo revive) |

Un solo mecanismo cubre publicar y programar **sin cron**: la condición se evalúa al
leer. La fila **no se borra** al despublicar (se perdería el motivo y el autor): se
marca, igual que `anulado_en`/`motivo_anulacion` en `cierres_conducta`/`cierres_asistencia`.

**Backfill retroactivo obligatorio** en la migración: todo bimestre ya `cerrado` queda
publicado en todos los niveles, con `publica_en = COALESCE(boletas_aprobadas_en, NOW())`.
Sin esto el deploy oculta B1 a TODAS las familias — la regresión más grave posible.

### Punto único de verdad — `PublicacionBoletaModel`
Ningún otro archivo consulta la tabla (mismo criterio que `boleta_estado_bimestre`).
`periodosPublicados($anioId, $nivelId, $ahora)` devuelve el set `[periodo_id => true]`
de lo visible: `publica_en <= $ahora AND suspendida_en IS NULL AND despublicada_en IS NULL`.

**Zona horaria — riesgo resuelto por diseño:** `$ahora` lo calcula **PHP**
(`config('timezone') = 'America/Lima'`, aplicado en `public/index.php`) y viaja como
parámetro preparado. **`NOW()` de MySQL nunca interviene en la lectura**: el huso de
producción (Hostinger) es desconocido y suele ser UTC, así que una publicación
programada a las 18:00 se dispararía 5 horas antes.

**`fuePublicado($periodoId)` + `primera_publicacion_en` (migración 046):** además de
la lectura de visibilidad, la tabla ancla la INMUTABILIDAD del orden de mérito. La
columna `primera_publicacion_en` es una marca monotónica (la sella `publicar()` con
`COALESCE`, una sola vez) que sobrevive a reaperturas y despublicaciones. `fuePublicado`
es un candado a nivel de periodo — lo consume `OrdenMeritoModel::registrarRanking`, no
la boleta. Detalle en `docs/modulos/orden-merito.md`.

### El corte `'oficial'` / `'archivo'` (decisión del usuario)
`armar()` suma un cuarto umbral. **Mismo corte de datos** (solo bimestres cerrados);
lo único que cambia es si se respeta la publicación:

| `$datos` | Quién | Compuerta |
|---|---|---|
| `'oficial'` | familias EN LÍNEA: `verToken`, `verDigitalToken`, `/padre/notas` | **respeta** |
| `'archivo'` | STAFF: salida masiva impresa/ZIP, boleta del trasladado | **ignora** |
| `'borrador'` | docente y gestión | n/a (no mira publicación) |
| `'todos'` | vista previa de RA | n/a |

**El porqué:** RA **imprime las boletas ANTES** de la reunión de entrega; con
`'oficial'` saldrían en blanco. La compuerta protege el **acceso en línea**, no la
impresión del colegio. El QR que va impreso sí respeta la compuerta: al escanearlo el
día de la entrega, la publicación ya está vigente.

### La compuerta oculta el bimestre COMPLETO
No solo las notas: cuando aplica, un bimestre no publicado tampoco aporta
**asistencia** ni **conducta**, y **no arma columna** (con columnas colapsadas). Si no,
la familia vería la asistencia de un bimestre cuyas notas siguen ocultas, delatando que
ya cerró. Hasta la 044 ambos conjuntos coincidían (cerrado == visible) y el filtro no
hacía falta.

### Puntos de lectura (4)
1. `BoletaModel::armar()` + `periodoAportaNotas()` — reciben el set publicado del nivel.
2. `BoletaModel::getAlumno()` — proyecta `n.id AS nivel_id` (la compuerta es por nivel).
3. `BoletaController::resolveToken()` — ancla al último bimestre **PUBLICADO**, no al
   último cerrado. Cerrar B2 no cambia lo que ve la familia: sigue viendo B1 hasta que
   RA publique. Sin ningún publicado con notas cae al primer periodo del año (boleta
   vacía, comportamiento histórico previo al primer cierre — no hay pantalla nueva).
4. `Padre\PanelController::getPeriodoVigentePadre($nivelId)` — se resuelve DESPUÉS de
   conocer al hijo, porque la publicación es por nivel; `getHijo` proyecta `n.id`.

### Matriz de reapertura
| Acción | Efecto |
|---|---|
| Cerrar | NO publica. Solo **restaura** una publicación que una reapertura había suspendido |
| Publicar / Programar | solo si el bimestre está `cerrado` |
| Reabrir | `suspendida_en = ahora` en todos los niveles — reversible |
| Volver a cerrar | `suspendida_en = NULL` → restaura la publicación previa |
| Despublicar a mano | `despublicada_en` + motivo → **no revive** al re-cerrar |

`Director\PeriodoController::cerrar()/reabrir()` entran en sus transacciones existentes
(mismo PDO singleton).

### UI y roles
Tercer paso en `/admin/control` (Centro de Control), después del Hito A y del cierre.
Estado por nivel + Publicar ahora / Programar / Retirar. Publican **`admin` y
`registro_academico`**; `director_general` y `director_ebr` ven el estado pero no
operan — **validado en el método** (`guardPublicacion`), no ocultando el botón.
Rutas POST con `validateCsrf()`: `/admin/control/{periodo_id}/{publicar,programar,despublicar}`.

### Procesos que NO cambian
SIAGIE, orden de mérito y su snapshot, rectificaciones, retorno de grado, boleta del
docente y de gestión: todos siguen mirando solo `cerrado`. Los trasladados **ignoran**
la compuerta (boleta archivada administrativa; el alumno ya no tiene vínculo).

### Verificado end-to-end (19 checks + 8 de render)
B1 sigue visible tras migrar · despublicar primaria no toca secundaria · `'archivo'`,
`'borrador'` y `'todos'` siguen trayendo las notas · republicar revive lo retirado ·
lo programado es invisible hasta la hora exacta · reabrir oculta y re-cerrar restaura ·
lo retirado a mano sigue oculto tras re-cerrar · asistencia/conducta/columna ocultas
con la compuerta y presentes en `'archivo'`. Superficie del token comprobada por HTTP
real con la compuerta encendida y apagada, por nivel.

## La conclusión descriptiva pasa a UNA línea — cabe en una hoja (10/08/2026)

> **El problema:** con el plan completo de competencias, la boleta de **secundaria**
> empujaba el bloque de **firmas** a una segunda hoja. Primaria entraba bien.
> Detectado por el usuario al revisar la matrícula 556 (Secundaria 4.º A).

**El cambio es de una línea de SASS** (`_boleta.scss`): `-webkit-line-clamp` de **2 a 1**
y su respaldo `max-height` de `11.5pt` a `5.75pt`. Nada más: mismas columnas, mismo
contenido, misma estructura del modelo oficial. Lo único que cambia es cuántos caracteres
se ven antes del corte. **Validado en papel por el usuario.**

### El modelo de alturas (medido, no estimado)
Una fila mide `max(nombre, literal, conclusión)`:

| Celda | Alto |
|---|---|
| Nombre de competencia (5.5pt, interlínea 1.25) | 1 línea **3.02mm** · 2 líneas **5.45mm** |
| Literal / nota (7.5pt) | **3.58mm** (fijo) |
| Conclusión (5pt, interlínea 1.15) | 2 líneas **4.46mm** · 1 línea **2.43mm** |

- A 2 líneas la conclusión **manda**; a 1 línea cae por debajo del literal y el techo pasa
  a ponerlo éste. En Secundaria 4.º A hay **18 competencias con nombre de una sola línea**
  → **18 × 0.88mm ≈ 15.8mm** recuperados, que es justo lo que ocupa el pie de firmas.
- 🔴 **NO tiene sentido recortar más allá de una línea.** Por debajo de 2.43mm la
  conclusión ya no fija el alto de ninguna fila: seguir cortando caracteres **no devuelve
  ni un milímetro**.
- **El siguiente margen, si algún día hace falta:** el **ancho** de la columna de nombre.
  **27 de 59 competencias superan los 46 caracteres** que entran en una línea de 45mm y
  por eso parten el nombre en dos. Repartir ancho entre nombre (48mm) y conclusión (~22mm
  por bimestre) valdría ~30mm. Después, el relleno de las filas de área (12 en secundaria,
  ~4.8mm) y por último el espacio de firma (12mm, ya recortado una vez desde 15mm).

### El dato que justifica cortar sin culpa
**2 891 de 2 901 conclusiones reales (99.7 %) ya no cabían en 2 líneas.** Promedio **155
caracteres**, máximo **500**; en 2 líneas entraban ~48. O sea que el papel **siempre**
mostró un fragmento cortado a media frase — el cambio lo acorta, no lo estrena. El texto
completo vive en la **boleta digital**, que se recorre con scroll y no tiene límite de hoja.

⚠️ Los puntos suspensivos los pinta **solo** `-webkit-line-clamp`. El respaldo `max-height`
—el que aplica html2canvas en el PDF de *Archivar*— corta **sin** ellos.

### Cómo se midió: el seeder del peor caso
`database/seeds/seed_peor_caso_boleta.php` construye el documento **más alto que el modelo
puede producir** y permite calibrar contra papel en vez de estimar:

- Una matrícula real de **Secundaria 4.º A** (29 filas / 12 áreas, con columna de nota) y
  otra de **Primaria 3.º B** (27 / 9, sin nota y con la conclusión más ancha) — el máximo
  de cada nivel.
- Por cada competencia del plan **× los 4 bimestres**: nota **C** (la que obliga conclusión
  en ambos niveles), conclusión de **500 caracteres** y su **bloqueo** (la boleta solo
  muestra competencias bloqueadas). Además crea los cierres transversales que falten y
  **cierra B3 y B4**, único modo de que pinten sus columnas y se active el logro anual.
- El universo de competencias sale de **la misma consulta del cierre forzado**, con las dos
  exclusiones de "carga dueña" — así se llena cada fila del documento sin crear una quinta
  copia de esa regla.
- **Reversible:** vuelca a JSON todo lo que va a pisar y `--revertir` lo restaura; guarda
  los ids de cada bloqueo y cierre que crea para borrar **solo lo suyo**. Aborta si detecta
  el archivo de secretos de producción y si ya hay un seed sin revertir.
- ⚠️ **Ojo al leer su salida:** informa **pares (carga, competencia)** —65 y 41—, no filas
  del documento. La boleta agrupa por competencia, así que las transversales adjuntas a
  varias cargas colapsan en una fila. Las filas reales son **29 y 27**.

### ~~Pendiente relacionado~~ — CERRADO EL 10/08/2026
🔴 **Esta nota era FALSA y estuvo vigente desde el 21/07.** Decía que el logro anual
"sigue usando el último bimestre cerrado" y que debía exigir **año académico cerrado**
(decisión #9). El código nunca hizo eso: desde el fix del 02/07 (sección "Logro anual"
arriba) `getUltimoBimestreDelAnio` toma el periodo de **mayor `numero`** y exige que
**ese** esté cerrado — su propio comentario dice *"NO es el último bimestre CERRADO"*.

**Regla confirmada por el usuario el 10/08/2026 (definitiva):** el logro anual **solo se
activa y muestra el logro obtenido en el IV Bimestre**. Es exactamente el comportamiento
vigente, así que **no hay nada que implementar y la decisión #9 queda cancelada**.
- **Anclaje: el ÚLTIMO periodo del año**, no el número 4 literal (decisión del usuario).
  Si un año se configurara por trimestres (`periodos.tipo` lo admite), sería el III y la
  regla sigue teniendo sentido sin tocar código. Anclar al 4 haría que un año de 3
  periodos no activara nunca el anual, y el fallo sería **silencioso**.
- **Disparador: B4 CERRADO** (no "publicado"). El impreso de staff (umbral `'archivo'`)
  muestra el anual en cuanto B4 cierra, que es coherente con el motivo por el que existe
  ese umbral. Decisión del usuario.
- ✅ **Sin fuga a las familias, verificado en el código el 10/08:** `literal_final` se
  calcula sobre `$datosPorPeriodo`, que **ya pasó por `periodoAportaNotas`**. Con
  `'oficial'`, un B4 cerrado y **sin publicar** no aporta datos → no hay entrada de
  bimestre → `literal_final = null`. La compuerta 044 gobierna el anual sin código extra.

🟡 **ABIERTO — qué pasa si una competencia no tiene nota en el IV Bimestre.** Hoy el anual
sale en **guion** aunque la competencia tenga notas en B1-B3. El usuario lo considera un
caso que **no debería existir**: *"el IV bimestre sí o sí tiene que tener un promedio final
en todas las competencias"*. Queda como decisión de diseño pendiente, a analizar antes de
que B4 empiece (**05/10/2026**).
- **Dimensionado el 10/08 usando B2 como ensayo** (si B2 fuera el último bimestre):
  **324 pares (alumno, competencia)** tendrían anual en guion pese a haber sido evaluados
  en B1 — sobre 12 121 pares de B1, o sea **2.7 %**. **El 61 % se concentra en Personal
  Social de primaria** (199 pares); le siguen Matemática (48) y CyT (42) del mismo nivel.
- **La causa no es un defecto:** desde el cambio del 05/08 es **legítimo** que una
  competencia no se trabaje en un bimestre (la boleta pinta guion). La pregunta es si en
  el ÚLTIMO bimestre eso debe seguir siendo legítimo, o si B4 exige plan completo.
- **No confundir con la alerta de evaluación incompleta:** esa compara alumnos **dentro**
  de una sección (a él le falta lo que su compañero sí tiene). Aquí faltaría la
  competencia para **toda** la sección, que es justo el punto ciego del cierre.

## Fixes importantes aplicados (sesión 3)
- `CalificacionModel::getBoletaAlumno()` ahora hace INNER JOIN con
  `bloqueos_competencia` — la boleta solo muestra notas que el docente aprobó.
  Antes mostraba todas las notas guardadas aunque no estuvieran bloqueadas.
- Eliminado `003_bloqueos_competencia.sql.sql` (nombre con extensión duplicada).
- Orden de rutas en `routes/web.php`: `/boleta/digital/{id}/{id}` debe ir ANTES
  de `/boleta/{id}/{id}` para que el router no capture "digital" como parámetro.

## Formato oficial en TODAS las vistas de boleta (04/08/2026)

> **Síntoma:** `/admin/boletas-publicas/{id}/boletas-alumno?seccion_id={id}` —la impresión
> masiva, o sea el papel que RA firma, sella y entrega— salía con **una sola columna** de
> bimestre, mientras la misma boleta abierta por QR (`/boleta/ver/{token}`) salía con las
> **cuatro** del formato oficial. Mismo documento, dos formatos.

### Causa
La **regla de formato del 09/07/2026** (estructura anual completa) se aplicó al token y a
la boleta del trasladado, pero **no se propagó** a la impresión masiva ni al ZIP de
archivo: llamaban a `armar($mat, $per, 'archivo')` **sin el 4.º parámetro**, así que caían
en `estructuraCompleta = false` y `colapsarColumnas` (`BoletaModel:106`) recortaba las
columnas a los bimestres cerrados. No hay plantilla propia: `boletas-alumno.php:20` hace
`include boleta/alumno.php`, el mismo componente que el token.

Medido antes del arreglo (solo B1 cerrado): token **4** columnas · impresión masiva **1** ·
ZIP **1** · trasladado **4** · vista previa y boleta docente **4** (por su umbral, no por
el flag) · digital de familias **1**.

### Regla nueva
**Las 9 entradas de boleta pasan `estructuraCompleta = true`.** La estructura anual es una
propiedad del DOCUMENTO, no del umbral de datos, así que se pide explícitamente incluso
donde el umbral (`'todos'`, `'borrador'`) ya no colapsaba — si mañana cambia el umbral, el
formato no se mueve.

| Entrada | Umbral | Antes | Ahora |
|---|---|---|---|
| `/boleta/ver/{token}` | oficial | 4 | 4 |
| `/boleta/digital/{token}` | oficial | **1** | **4** |
| `/docente/boleta/{id}` y `/imprimir` | borrador | 4 | 4 (explícito) |
| Boleta de gestión — trasladado | archivo | 4 | 4 |
| Boleta de gestión — resto | borrador | 4 | 4 (explícito) |
| **Impresión masiva** | archivo | **1** | **4** |
| **ZIP de archivo** | archivo | **1** | **4** |
| Vista previa de RA | todos | 4 | 4 (explícito) |

### Por qué abrir columnas NO es una fuga
`colapsarColumnas` gobierna la ESTRUCTURA; los DATOS los sigue filtrando
`periodoAportaNotas` por periodo. Verificado en `verif_estructura_boleta.php`: con
`'oficial'` la boleta muestra 4 columnas y **solo aporta notas del bimestre cerrado y
publicado**, aunque el bimestre en curso ya tenga notas en la BD. Su paso 3 es el control:
compara los bimestres con datos **con y sin** el flag y exige que sean los mismos — si
difirieran, el flag estaría filtrando datos y no solo formato.

> ⚠️ **Hasta el 17/09/2026 esa verificación NO discriminaba con datos reales**: todo lo
> cerrado estaba publicado, no había Hito A en curso y nada bloqueado en el bimestre activo,
> así que los cuatro umbrales daban lo mismo. Su **sección 4** fuerza ahora, en transacción
> + ROLLBACK, los tres estados que los separan, y está probada con mutantes. Detalle en
> `database/verificaciones/README.md`.

Es además el argumento original de la regla del 09/07 (`BoletaController:68-70`): con la
estructura anual fija, una columna vacía **no revela** si el bimestre cerró. *Colapsarlas
era justo lo que lo delataba* — y la digital de familias, que es el destino del QR, era la
que seguía colapsando.

### La tabla de asistencia también es estructura anual (04/08/2026)
La primera versión de este cambio dejó la asistencia fuera: la boleta salía con 4 columnas
de notas y 1 de asistencia. **Corregido el mismo día** — la tabla de asistencia es parte
del modelo oficial igual que la de notas, así que **siempre dibuja una columna por bimestre
del año**, en las cuatro vistas y en los cuatro umbrales.

Cada columna lleva `sin_registro` (bool). Es `true` por **tres** motivos distintos que se
pintan igual, con **guion apagado**:

| Motivo | Ejemplo |
|---|---|
| El bimestre aún no puede tener registro | B3/B4 `pendiente` |
| No corresponde a este umbral | bimestre cerrado **no publicado**, o el bimestre en curso bajo `'oficial'` |
| **Nadie registró la asistencia de ese alumno** (07/08/2026) | quien llegó DESPUÉS del bimestre, o un trasladado que ya no lo cursó |

**Nunca un `0`**: un cero se lee como "no faltó ningún día" y sería un dato inventado.
Además, con guiones el lector no intenta cuadrar la suma de las columnas con el **Total**,
que solo acumula las que sí tienen registro.

Cuando `sin_registro` es `true` **no se consulta la asistencia**: la columna vacía no puede
salir de datos que ese umbral no debe ver.

#### El tercer motivo: sin FILA no es lo mismo que en CERO (F1, 07/08/2026)

`AsistenciaModel::getDelBimestre` devuelve los 4 contadores en **cero** cuando no hay fila
en `inasistencias`, y `armar()` no distinguía ese caso del cero real → la boleta de un
alumno que llegó en julio afirmaba **`0 faltas` del I Bimestre**, que no cursó. Dato
**falso**, no ausente (medido en la matrícula 694).

Lo resuelve **`AsistenciaModel::tieneRegistroUnion(array $ids, int $periodoId): bool`**
(`EXISTS` sobre `inasistencias`), sumado como tercer término de `$sinRegistro`. Va **el
último** a propósito: `||` corta en corto, así que la consulta extra no se ejecuta cuando el
umbral ya dijo que no hay nada que mostrar.

- ⚠️ **Es por UNIÓN, y no es un detalle.** En un retorno de grado la asistencia queda
  repartida por bimestre entre la oficial y la operativa. Preguntando matrícula por
  matrícula, el retorno #1 habría salido en guion en **los dos** bimestres pese a tener
  datos: su fila de B1 vive en la oficial (190) y la de B2 en la operativa (692).
- **Sin fila ≠ sin incidencias.** El registro escribe una fila por alumno aunque vaya en
  cero (medido: **197** filas así en B1 y **173** en B2), y esas **conservan su `0`**. Es
  la distinción que da sentido al cambio. ⚠️ Ojo: `guardar()` es un upsert **AJAX fila por
  fila**, así que la fila solo existe si alguien tocó a ese alumno; y el cierre de sección
  **no exige completitud** (su propio comentario dice "sin fila = 0 incidencias"). Por eso
  el universo hay que medirlo, no suponerlo.
- **Universo medido (07/08/2026):** **18 pares** (matrícula, bimestre) sin fila en
  bimestres cerrados/activos; **2 los neutraliza la unión** (692 en B1, 190 en B2), así que
  **16 celdas** pasan de `0` a guion: los **6 que llegaron tarde** en B1 y **10
  trasladados/retirados** en B2. **El Total anual no se mueve** — esas columnas aportaban 0.
- Verificación: `database/verificaciones/verif_asistencia_sin_registro.php` (solo lectura,
  corre en prod). Su **bloque 2** es el que impide la regresión: comprueba que ninguna fila
  en cero real se convierta en guion.
- **La vista no se tocó** (ya pintaba guion) y **no hubo SASS**: las clases existían.

- `boleta/alumno.php` → `.boleta-asistencia__num--pendiente` (ya existía en el SASS).
- `boleta/digital.php` → `.bd-asistencia__num--pendiente`, **añadido** en
  `_boleta-digital.scss`: la digital no tenía ningún estado para "sin dato" y habría
  pintado ceros.
- **No se tocan** `admin/asistencia/imprimir.php` ni `admin/asistencia/seccion.php`: son
  otro documento (alumnos × contadores de UNA sección y UN bimestre), no la asistencia
  anual del alumno.

## La boleta muestra TODAS las competencias del plan (05/08/2026)

> **Regla:** el documento lista **todas las competencias que la sección realmente
> dicta**, tengan o no calificación, con **guion** donde no hay dato. Antes las filas se
> construían A PARTIR DE LAS NOTAS: una competencia sin calificar no existía en el papel,
> y el número de filas **variaba entre alumnos de la misma sección**.

**El universo son las CARGAS ACTIVAS de la sección**, no el catálogo del nivel. De ahí
salen solas, sin ninguna excepción escrita a mano, las exclusiones del colegio:
Ed. Religiosa no aparece en secundaria (0 cargas — la evalúa **Ética y Valores** por la
carga TOE del tutor); 5.º no lleva Arte y Cultura ni EPT; el Taller de Pre-Cálculo solo
se dicta en 5.º. En **primaria sí** se muestra Ed. Religiosa (12 cargas).

- Punto de entrada: `CalificacionModel::estructuraCompetenciasSeccion()`, sembrado por
  `BoletaModel::buildAreasConBimestres()` **antes** de las notas — eso fija además el
  orden de áreas y competencias (`a.orden, comp.orden`).
- **No filtrar por `a.tipo`** en el esqueleto: Ética vive en un área `tipo='tutoria'` y
  el filtro del orden de mérito la borraría de toda la secundaria.
- Las **transversales van en consulta aparte**: su área tiene 0 cargas, así que la vía de
  las cargas no las traería, y el bloque debe salir aunque el tutor no haya cerrado.
- **Ninguna nota puede desaparecer:** el loop de notas sigue creando la fila si no está
  en el plan (carga desactivada tras evaluar, o la otra matrícula de un retorno).
- En un **retorno de grado** el plan sale de la matrícula **operativa** (se evalúa donde
  se cursa); la boleta se sigue rotulando con la oficial.
- ⚠️ Los **exonerados** conservan `EXO`: `ExoneracionModel::inyectarEnAreas()` tuvo que
  aprender a escribirlo sobre una entrada ya sembrada (antes solo lo hacía al crearla).
- ⚠️ El bloque transversal se detecta por **`'transv'`**, no `'transversal'`: en
  secundaria el área se rotula `Comp. Transv.`.

**La CONDUCTA sigue la misma regla:** sus celdas vacías llevan guion. Las columnas de
numeral y de conclusión no le aplican (la conducta es siempre literal), así que van con
guion permanente en vez de en blanco.

Verificación: `database/verificaciones/verif_plan_completo_boleta.php`. Detalle completo,
trampas y cifras: `docs/modulos/boleta-competencias-completas.md` §10.

### El banner de BORRADOR se eliminó — la señal es la marca de agua (05/08/2026)

La vista previa de RA (`/admin/boletas-publicas/{id}/vista-previa`) llevaba un banner
ámbar encima de cada boleta. Ocupaba **~6 mm de alto, dos filas de tabla**, y con el plan
completo de competencias eso empujó **las firmas a una segunda hoja** (visto en
Secundaria 4.º A). La restricción del documento es UNA hoja A4, así que la señalización
no puede costar alto.

Queda como única señal la **marca de agua diagonal** de `vista-previa.php`
(`position: fixed`, se repite en cada página impresa), **reforzada** de `#555` al 8% a
`#3f3f3f` al 16%. El refuerzo importa porque este documento **sí se imprime en papel con
el bimestre todavía abierto**: la señal tiene que sobrevivir a la impresora, no solo
verse en pantalla. Es texto, no un `background`, así que ningún driver lo descarta por
"no imprimir fondos".

⚠️ **Techo del refuerzo:** la marca va con `z-index` **por encima** del contenido; subir
más la opacidad empieza a estorbar la lectura de la tabla de notas. Si alguna vez hace
falta más contraste, el camino es mandarla detrás del contenido, no subir el porcentaje.

##### PUNTO ÚNICO: la señal la pinta el DOCUMENTO, no quien lo muestra

**La marca de agua vive en `resources/views/boleta/_marca-borrador.php`**, y la incluye
`boleta/alumno.php` con solo recibir `$vistaPrevia`. Así la obtienen por el mismo camino
**todas** las entradas que emiten el documento en borrador:

| Entrada | Marca |
|---|---|
| `/admin/boletas-publicas/{id}/vista-previa` (RA) | 1 por boleta |
| `/admin/boletas-publicas/{id}/archivar-borrador` (ZIP) | 1 por PDF |
| `/docente/boleta/{id}/imprimir` (nómina docente) | 1 |
| `/docente/boleta/{id}` (**digital**, nómina docente) | 1 fija en pantalla |
| Documento oficial (token, impresión masiva, ZIP de archivo, digital publicada) | **ninguna** |

⚠️ **Regresión que esto corrigió (05/08/2026):** al eliminar el banner, la marca quedó en
los *wrappers* (el de la vista previa de RA y el del ZIP). La **boleta impresa del
docente** no pasa por ninguno de los dos, así que se quedó **sin ninguna señal de
borrador** — justo el documento que un tutor puede imprimir con el bimestre abierto.
Mientras la señal dependa de quién muestra el documento, cada entrada nueva vuelve a
poder olvidarla; por eso ahora viaja **con el documento**.

- El documento se envuelve en **`.boleta-doc`** (`position: relative`), que es el ancla de
  la marca: una por boleta. La marca es `position: absolute`, **nunca `fixed`** — con
  varias boletas apiladas se superpondrían todas en el mismo punto del viewport, y en el
  ZIP `html2canvas` (que captura un contenedor por boleta) no la capturaría.
- **El mensaje es único de verdad:** `BOLETA_LEYENDA_BORRADOR` en `helpers.php`. Lo usan
  la marca de agua y el aviso de la boleta **digital**. Lo que cambia entre formatos es el
  ANCLAJE, nunca el texto.

##### La digital también lleva marca (05/08/2026): es control de fuga, no decoración

**Motivo:** una captura de pantalla o una foto al monitor sacaba **notas de un bimestre
sin cerrar sin nada que dijera que son provisionales**, y esa imagen podía circular como
si fuera un resultado oficial. La marca **no impide la captura: la etiqueta.**

Variante `boleta-watermark--pantalla`, con dos diferencias respecto a la de papel:

- **`position: fixed`, no anclada al contenido.** La digital es un documento largo que se
  recorre con scroll: si la marca se moviera con él, las capturas de la zona de notas
  —justo las que importan— saldrían sin marcar. Fija en el viewport, **cualquier captura
  la incluye**.
- **Tamaños en `vw` con `clamp()`, no en `pt`.** Los `pt` están calibrados para A4
  (200 mm de largo) y en un móvil de 360 px desbordarían unas 3 veces, provocando
  **scroll horizontal**. Medido con la fuente real, la palabra a `12vw` proyecta 205 px en
  320 px de ancho (+115 de margen), 255 px en 390 px y 496 px en tablet; la leyenda a
  `3vw` entra incluso en 320 px (+53 px). El `clamp` corta el crecimiento en monitores
  grandes para no desbordar el documento (`.bd`, `max-width: 800px`).
- Opacidad **0.10** (frente a 0.15 en papel): una pantalla retroiluminada hace pesar más
  el mismo gris, y aquí se lee sobre tarjetas blancas.

⚠️ `pointer-events: none` (en la clase base) es **crítico en táctil**: sin él la marca
interceptaría los toques y el scroll en el centro de la pantalla. Queda por encima de los
botones flotantes (`z-index` 100), inevitable si ha de verse sobre las tarjetas, que
tienen fondo blanco opaco; al 10 % no los estorba.

### La marca de agua NO CABÍA en la hoja al imprimir (medido)

La marca lleva dos líneas — `BORRADOR` + la leyenda *"Vista previa · no constituye
documento oficial"*— y **en impresión usa tamaños propios**. El motivo es una medición,
no una preferencia: con la fuente real (Arial Bold, vía `imagettfbbox`), a **140 pt con
12 pt de tracking** la palabra mide **317 mm de largo**, y rotada −30° proyecta **275 mm**
sobre los **190 mm útiles** del A4. Se perdían ~85 mm: **el papel salía con la palabra
cortada** (dos o tres letras por lado). En pantalla no se notaba porque el viewport es
más ancho que una hoja.

| Tamaño | Largo | Proyección (×cos 30°) | vs 190 mm útiles |
|---|---|---|---|
| 140 pt / 12 pt (antes, impresión) | 317 mm | 275 mm | **−85 mm, se corta** |
| 110 pt / 8 pt | 246 mm | 213 mm | −23 mm, se corta |
| **90 pt / 6 pt (actual, impresión)** | 200 mm | **173 mm** | **+17 mm** ✓ |
| Leyenda 16 pt | 174 mm | 151 mm | +39 mm ✓ |

> Los 190 mm eran los útiles con el margen de 1 cm. Desde el ajuste de `@page boleta`
> (8 mm) son **194 mm**, así que la holgura real es mayor (+21 mm). La tabla se conserva
> con el número conservador.

La holgura de 17 mm existe para absorber la diferencia entre el motor del navegador y la
medición de GD. **En pantalla no se tocó nada** (130 pt / 18 pt): ahí se lee entera.

**Intensidad en papel (ajustada el 05/08/2026 tras verla impresa):** `#1f1f1f` al **15 %**.
Se usa color casi negro con opacidad baja, no un gris claro a secas: el color define el
trazo y la opacidad gobierna cuánta tinta cae sobre la tabla. El gris efectivo es
`255 − (255 − color) × opacidad` → ~`#DD`, un 13 % de tinta. Venía del 22 % (~19 %), que
se leía bien pero **competía con las notas al imprimir en blanco y negro**. El 15 % es el
suelo razonable: por debajo vuelve el riesgo de que una impresora en modo ahorro se coma
la señal, que es justo lo que motivó reforzarla.

🔁 **Si se cambia el texto de la leyenda o el tamaño, hay que recalcular** — el límite es
la **proyección**, no el largo: `proyección = largo × cos 30°`, y el ancho de la palabra
crece con el tracking, que se aplica a cada letra.

`$vistaPrevia` sigue gobernando lo demás (sin QR, sin imagen de firma del director).

### Descargar los BORRADORES en ZIP (05/08/2026)

`GET /admin/boletas-publicas/{periodo_id}/archivar-borrador[?seccion_id=N]` →
`archivarBorrador()`. Botón **📄 Borradores** en la tarjeta de cada sección.

Mismo mecanismo que **Archivar** (html2pdf + JSZip, un PDF por alumno en carpetas
`NIVEL/GRADO_SECCION`) pero con el documento de la **vista previa**. Nació para subir las
boletas al **Drive institucional y recoger el visto bueno de los docentes antes de cerrar
el bimestre**. Comparte la vista `admin/boletas-publicas/archivar.php`, que distingue los
dos modos con `$esBorrador`.

Diferencias con Archivar, y solo estas:

| | Archivar | Borradores |
|---|---|---|
| Umbral de datos | `'archivo'` (solo cerrados) | **`'todos'`** (incluye el bimestre en curso) |
| Guard de bimestre cerrado | **sí** | **no** — existe justamente para el bimestre abierto |
| QR y firma del director | sí | no (`vistaPrevia = true`) |
| Marca de agua | no | **sí, dentro de cada PDF** |
| Nombre | `APELLIDOS_NOMBRES.pdf` | `APELLIDOS_NOMBRES_BORRADOR.pdf` |
| ZIP | `..._II_BIMESTRE.zip` | `..._II_BIMESTRE_BORRADOR.zip` |

✅ **PROBADO EN NAVEGADOR EL 10/08/2026 (usuario), y con esto se cierra la única deuda que
arrastraba desde el 05/08** — hasta entonces solo estaba verificado en servidor. Se cubrió
sección chica (Primaria 2.º A), sección grande con el peor caso de contenido (Secundaria
4.º A, la de la matrícula **556**) y el caso de uso real sobre el **bimestre ABIERTO**:
descarga correcta, carpetas `NIVEL/GRADO_SECCION`, sufijo `_BORRADOR`, marca de agua en
cada PDF y **0 QR / 0 sello del director**.

⚠️ **El botón existe SOLO por sección** (la vista del periodo siempre pasa `?seccion_id=N`).
La ruta sin ese parámetro es válida y procesaría **todas** las matrículas del periodo
(~524) en una sola pestaña: html2pdf renderiza en el CLIENTE, así que es la vía directa a
colgar el navegador. No enlazarla.

⚠️ **La marca de agua va DENTRO del item, no en el wrapper.** `html2canvas` captura un
contenedor por boleta (`.boleta-archivo-item`), y un `position: fixed` de fuera **no
entra en la captura**: los PDFs saldrían sin marca. De ahí la variante
`.boleta-watermark--inline` (`position: absolute`) y el `position: relative` del item —
sin ese ancla habría **una sola marca para todo el lote** en vez de una por boleta.

⚠️ **El ZIP usa el MISMO tamaño que el papel (90 pt), sin excepción.** Hubo una —130 pt,
para "compensar" que `.archivo-items-wrap` mide 297 mm— y era justo la que **rompía la
marca: salía cortada en los PDF**.

Lo que recorta **no es la proyección del texto rotado sino su largo SIN rotar**: lleva
`white-space: nowrap`, así que si el div es más ancho que su contenedor se sale del
bounding box que captura `html2canvas`, y el wrap además tiene `overflow: hidden`.

| Tamaño | Largo sin rotar | % del contenedor (297 mm) |
|---|---|---|
| 130 pt / 12 pt (la excepción) | 296 mm | **100 % → se cortaba** |
| **90 pt / 6 pt (actual)** | 200 mm | **67 %** (33 % de margen) |

Medio milímetro de holgura por lado no sobrevive a la diferencia entre el motor del
navegador y la medición. Con el tamaño único la marca ocupa ~58 % del ancho del PDF, algo
menos que en la impresión directa (~89 %) porque el contenedor es más ancho que la hoja:
es el precio de tener **un solo modelo**, y sale completa siempre.

**El sufijo `_BORRADOR` del nombre no es cosmético:** estos PDFs conviven en el Drive con
los definitivos y el nombre tiene que decir cuál es cuál sin abrir el archivo.

### Ajuste de página para que las firmas no se partan (05/08/2026)

Con el plan completo de competencias el documento quedó al límite: en Secundaria 4.º A
**el nombre y el cargo de los firmantes caían en una segunda hoja**. Se recuperaron 8 mm
sin tocar ni un dato del contenido:

| Ajuste | Gana |
|---|---|
| `@page boleta { margin: 8mm }` (era 1 cm) | **4 mm** de alto (y 4 de ancho) |
| `.boleta-footer__espacio-firma` 15 mm → 12 mm | 3 mm |
| `.boleta-footer` `margin-top` 2 mm → 1 mm | 1 mm |

- **Página con nombre**, igual que `@page paisaje`: el margen reducido se aplica **solo a
  la boleta** (`body.boleta-body { page: boleta }`) y no toca a los demás documentos que
  comparten el layout print (constancias, actas, asistencia). 8 mm sigue por encima del
  área no imprimible de una impresora de oficina (~5-6 mm).
- El `padding` de la hoja simulada **en pantalla** bajó a 8 mm también: si no, la vista
  previa mentiría sobre dónde cae el corte de página.
- `.boleta-footer` lleva ahora **`page-break-inside: avoid`**: una boleta con la línea de
  firma en una hoja y el nombre del firmante en la siguiente no es un documento firmable.
  Si algún día no cupiera, el bloque caerá entero a la hoja siguiente — que es la señal
  visible de que hay que recortar más.
- `__firma-img` pasa a `max-height: 12mm`. Antes eran **16 mm dentro de un contenedor de
  15 mm**: la firma del director se salía 1 mm hacia arriba, sobre la tabla.

## Emitir el documento oficial exige el bimestre CERRADO (04/08/2026)

En `/admin/boletas-publicas/{id}` los botones **🖨 Boletas** y **🗂 Archivar** se
condicionaban solo a `total_aprobables > 0` ("hay ≥1 competencia bloqueada"). Pero tener
competencias bloqueadas **no** significa que el bimestre haya cerrado, y el umbral
`'archivo'` solo deja pasar bimestres cerrados: se podía imprimir un lote entero de
boletas oficiales **con la columna del bimestre vacía**. Fallo silencioso — el papel sale
bien formado y está vacío justo donde importa.

### Regla
Emitir el documento oficial (impresión masiva y ZIP) exige `periodos.estado = 'cerrado'`.
La **vista previa NO** se bloquea: es la herramienta con la que RA decide el Hito A, y
para eso existe. El enlace *Ver boleta ↗* de cada alumno tampoco: va al token, que
respeta la compuerta por su cuenta y no produce papel.

### Dos capas
- **Vista** (`admin/boletas-publicas/periodo.php`): botones visibles pero inertes
  (`is-disabled` + `aria-disabled` + `tabindex="-1"`, patrón ya usado en
  `docente/nomina.php`) y aviso ámbar explicando el motivo. Se dejan a la vista, no se
  ocultan, para que se entienda **por qué** no se puede.
- **Servidor**: `boletasAlumno()` y `archivar()` abortan con `redirectWithError`. Son
  rutas `GET` que se abren en pestaña nueva, así que la URL queda en historial y
  marcadores; sin guard bastaba reabrirla para generar el lote vacío.

Criterio en un punto único: `BoletaPublicaController::periodoEsOficial()`, que delega en
`boleta_estado_bimestre()` en vez de comparar el string a mano. Obligó a ampliar
`getPeriodo()`, que no traía `estado` ni `boletas_aprobadas_en`.

> ⚠️ **La condición es CERRADO, no publicado.** `'archivo'` ignora la compuerta 044 a
> propósito porque RA imprime **antes** de la reunión de entrega; exigir publicación
> rompería ese flujo.
>
> ⚠️ **El Hito A tampoco habilita la emisión.** `boleta_estado_bimestre` devuelve
> `'borrador'` con Hito A y solo `'oficial'` (= cerrado) abre la puerta. Verificado: con
> el Hito A simulado sobre el bimestre activo, `periodoEsOficial()` sigue dando `false`.

### Extensión al índice: los bimestres `pendiente` no se abren (04/08/2026)
Mismo patrón de dos capas en `/admin/boletas-publicas`. Un bimestre que aún no ha
iniciado no tiene nada que gestionar —ni siquiera vista previa—, así que su tarjeta se
ve pero **no navega** (`is-disabled` + `aria-disabled` + `tabindex="-1"`, flecha oculta,
badge `--espera` "No iniciado"), y `porPeriodo()` aborta con `redirectWithError` si se
llega por URL. El bimestre **activo** sigue accesible: ahí vive la vista previa.

Dos cosas que **no** se pudieron reutilizar y hubo que añadir:
- `.btn.is-disabled` exige la clase `.btn` en el selector y la tarjeta no lo es → se
  añadió `.bp-periodo-card.is-disabled` en `_boleta-publica.scss`.
- `.badge` sin modificador queda **sin color** (la clase base solo aporta la forma) → se
  usa `badge--espera`, que ya existía para el estado "todavía no te toca".

También hubo que sumar `p.estado` a la query de `index()`, que no lo traía.

> 🐛 **Preexistente, sin corregir:** la vista usa `badge badge--success` para el conteo de
> boletas y ese modificador **no existe** en el SASS (el equivalente se llama `--activo`),
> así que ese badge sale sin fondo. Fuera del alcance de este cambio.

## Estudiante SIN calificaciones oficiales: aviso y botones inertes (21/09/2026)

Antes, `/matriculas/{id}/boleta[/imprimir]` y `/docente/boleta/{id}[/imprimir]` devolvían
**404** cuando el alumno no tenía ningún bimestre publicable (cerrado, o activo con Hito A)
con competencias bloqueadas, y la ficha de matrícula ofrecía los botones igual. El 404
decía «no existe», y la matrícula sí existe.

- **Regla, PUNTO ÚNICO:** `BoletaModel::periodoPublicableConNotas` (antes privada en
  `BoletaController`). La leen las dos rutas internas **y** `MatriculaController::show`,
  con el mismo corte del trasladado (solo cerrados). Si la ficha y la ruta leyeran reglas
  distintas, un botón activo podría llevar al aviso.
- **Ruta:** sin periodo → `BoletaController::sinCalificaciones()`: página suelta
  `boleta/sin-calificaciones.php`, **HTTP 200**, con `errores.css` y el botón Cerrar de los
  documentos (`.btn-boleta--cerrar` + `print-fit.js`). Matrícula **inexistente** en gestión:
  sigue siendo **404** (`notFound()`); fuera de alcance del docente: **403**.
- **Botones:** la ficha (`matriculas/show.php`) los pinta **visibles e inertes**
  (`.btn.is-disabled`, `aria-disabled`, sin `href`) con «Aún no tiene calificaciones
  oficiales.». `/padre/notas` hace lo mismo cuando `$areas` está vacío (su empty-state ya da
  el motivo). La nómina del docente ya los ocultaba (`tiene_boleta`), sin cambios.
- ⚠️ **Diferencia conocida, no tocada:** las rutas **por token** (`/boleta/digital|ver/{token}`)
  NO pasan por esta regla: sin notas pintan la **boleta vacía** (4 columnas en «—»). Es el
  documento público y su formato anual es fijo; no se cambió sin pedirlo.
- Verificador: `verif_boleta_sin_calificaciones.php` (solo lectura; compara la regla con un
  control escrito a mano en las 537 matrículas del año, las dos ramas).

