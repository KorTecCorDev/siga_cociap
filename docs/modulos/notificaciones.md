# Notificaciones — bandeja interna

> **Estado: CERRADO en `dev` el 16/09/2026 y PROBADO en navegador con sesión** (docente,
> RA, admin y director) en la BD local de la laptop. Migraciones `058` y `060` **solo en
> LOCAL**: ninguna en producción y sin merge a `main`. Despliegue en `docs/ESTADO.md`.
> Módulos relacionados: `matriculas.md` (el evento que la estrena), `usuarios-direccion.md`.

## 1. Por qué existe

Nació de un caso concreto: **las notas del colegio de origen no salen en la boleta**
(regla del colegio, ver `matriculas.md`), así que un docente **no tendría forma de
enterarse de que existen**. Registrarlas sin avisar es registrarlas para nadie.

Ya que hacía falta el canal, se construyó completo: la misma bandeja transporta
**comunicados** que admin / Registro Académico redactan al personal del colegio.

## 2. Por qué NO se reusó `alertas`

`alertas` ya existía y estaba vacía, así que la tentación era obvia. No sirve:

| | `alertas` | `notificaciones` |
|---|---|---|
| Dirección | tutor → **padre** | sistema o RA → **personal del colegio** |
| Columnas | `tutor_id`, `matricula_id` | `usuario_id` (destinatario) |
| Quién le escribe | **nadie** (0 filas, ningún INSERT en el código) | el evento de notas de origen y los comunicados |
| Quién la lee | solo `/padre/alertas` | la bandeja y la campana |

Son otro emisor, otro receptor y otro esquema. **`alertas` se deja intacta** —la sigue
leyendo `PanelController::alertas`— y `verif_notificaciones.php` comprueba en cada pasada
que no se ha tocado.

## 3. Modelo de datos — migraciones `058` y `060`

**`058` · `notificaciones`** (una fila POR DESTINATARIO): `usuario_id`, `tipo`, `origen`
(`sistema`|`comunicado`), `titulo`, `mensaje`, `enlace`, `emisor_id` (NULL si la generó
el sistema), `comunicado_id` (060), `leida_en` (DATETIME NULL), `created_at`.
`KEY idx_bandeja (usuario_id, leida_en, created_at)`.

**`060` · `comunicados`** (una fila POR ENVÍO): `emisor_id`, `titulo`, `mensaje`,
`destinos` (claves de `NotificacionModel::DESTINOS` separadas por coma), `seccion_id`
(solo con el destino `seccion`), `created_at`. Nació para el historial: sin ella no había
forma fiable de saber qué filas eran «el mismo comunicado» (agrupar por emisor + título +
fecha se parte si el envío cruza el cambio de segundo) ni a qué grupos se mandó.
Idempotente con `information_schema` + sentencia preparada, como la `059`.

Decisiones que conviene no deshacer:

- **`tipo` y `destinos` son VARCHAR, no ENUM.** Las listas cerradas viven en
  `NotificacionModel::TIPOS` y `::DESTINOS`: **añadir uno no exige migración**.
- **`leida_en` es una fecha, no un booleano.** Guarda además *cuándo* se leyó.
- **Avisos repetidos se REFRESCAN, no se duplican** (16/09/2026). Si RA vuelve a guardar
  las notas de origen de la misma matrícula, `crearParaDocentesDeSeccion` busca un aviso
  **sin leer** del mismo `tipo` + `enlace` y le actualiza texto y fecha. **Si el docente
  ya lo leyó, se crea uno nuevo**: es información nueva para él. Se eligió frente a
  «agrupar al mostrar» porque así la campana y la bandeja cuentan lo mismo sin lógica extra.

## 4. Roles

Constantes en `NotificacionModel`; no se listan códigos a mano en ningún otro sitio:

- **`ROLES_RECEPTORES`** = `docente`, `registro_academico`, `admin` y
  **`...ROLES_DIRECCION`** (desde el 16/09/2026). `padre` queda fuera: hay **0 usuarios
  con ese rol** y su superficie sigue oscura. Dirección **no recibe avisos del sistema**
  (no tiene cargas), solo comunicados.
- **`ROLES_EMISORES`** = `admin`, `registro_academico`. **Deliberadamente NO incluye
  ninguno de `ROLES_DIRECCION`**: los tres directores son SOLO LECTURA (invariante de
  `CLAUDE.md`). El gate de escritura va **por método**, no en el constructor.
- ⚠️ **Excepción acotada:** `leer` y `leerTodas` **no llevan gate de rol**, así que
  dirección marca leídas **las de su propia bandeja**. Es estado personal, no dato
  académico; sin ello su campana nunca bajaría a cero. Registrada también en
  `usuarios-direccion.md`.
- **`ROLES_ADMINISTRATIVOS`** = `registro_academico`, `admin`: el destino «Personal
  administrativo».

## 5. La campana

El contador vive en `BaseController::view()`, junto a `auth_user` y los flashes, **porque
lo necesita el LAYOUT en todas las páginas** y este proyecto **no tiene middleware** (ver
Convenciones de `CLAUDE.md`: `AuthMiddleware` se eliminó y no se reintroduce).

⚠️ **El global es `null` para quien no recibe notificaciones, y un entero (0 incluido)
para quien sí.** El layout pinta la campana según ese `isset`, no según el número. Es un
`COUNT` servido por `idx_bandeja` y solo se ejecuta si hay sesión.

## 6. Superficies

| Ruta | Quién | Qué |
|---|---|---|
| `GET /notificaciones` | cualquier autenticado | su bandeja; no leídas primero (100 más recientes) |
| `POST /notificaciones/leer` | el dueño (dirección incluida) | marca UNA; JSON con el contador |
| `POST /notificaciones/leer-todas` | el dueño (dirección incluida) | marca todas |
| `GET|POST /notificaciones/comunicado` | `ROLES_EMISORES` | redactar y enviar |
| `GET /notificaciones/enviados` | `ROLES_EMISORES` | historial de TODOS los comunicados, con «leído por X de N» |

**Aislamiento:** `marcarLeida` filtra por `usuario_id` además del `id`, así que **nadie
puede marcar la notificación de otro aunque adivine el id**.

**«Ver detalle» marca leída** con un `fetch` con `keepalive: true`: el enlace navega en el
mismo clic y sin `keepalive` el navegador podía cancelar la petición.

**La bandeja tiene «← Dashboard»** como primer hijo de `.page-header` (21/09/2026), el mismo
patrón de `consulta-notas/index.php`.

**Colegio de origen en el aviso de notas de origen (21/09/2026).** `storeNotasExternas`
escribe «Procede de: <colegio>.» **dentro del `mensaje`** cuando se anotó el colegio (es
opcional). Se eligió escribirlo y no derivarlo al pintar: la bandeja solo pinta título y
mensaje y no sabe de módulos. Consecuencia aceptada: **las notificaciones anteriores no lo
llevan**, y si luego se corrige el colegio en `notas_externas`, el aviso conserva el de
entonces. En el detalle (`/docente/notas-origen/{id}`) el colegio pasó del subtítulo a un
`info-item` propio; si no se anotó, no se pinta.

### Destinos del comunicado — casillas COMBINABLES

*Todos los docentes* · *Docentes de una sección* · *Dirección* · *Personal administrativo*.
`NotificacionModel::destinatariosDeComunicado()` **une** los grupos marcados,
**deduplica por usuario** y **excluye a quien envía** (lo que mandó lo ve en el historial).
Solo usuarios `activo`. El select de secciones ofrece **solo las del año activo**
(`seccionesParaComunicado()`): antes usaba `SeccionModel::listarConTutor()`, que incluye
el año planificado, y esas secciones daban siempre «No hay destinatarios».

El envío (`enviarComunicado`) va en UNA transacción: fila en `comunicados` + una
notificación por destinatario con su `comunicado_id`. Validación en los dos lados: título
y mensaje obligatorios, **título ≤ 150 en servidor**, al menos un destino, y sección si se
marcó ese destino.

**Historial:** lo ven **todos los emisores**, no solo el autor, para no mandar dos veces
lo mismo.

## 7. Punto de extensión

Para notificar desde otro módulo:
`NotificacionModel::crearParaDocentesDeSeccion($matriculaId, $tipo, $titulo, $mensaje, $enlace)`
— un aviso por docente activo, no uno por dato, y refresca el sin leer del mismo
`tipo` + `enlace`. Añade el `tipo` nuevo a `TIPOS` (no hace falta migración) y llama desde
el controlador, **fuera de la transacción del dato**: que falle un aviso no puede tumbar
un registro ya válido.

## 8. Verificación

`database/verificaciones/verif_notificaciones.php` (16/09/2026, **37 comprobaciones**),
escribe en transacción y termina en rollback: aviso uno por docente, las DOS ramas del
refresco (sin leer → refresca; leído → crea), inactivos fuera, aislamiento entre bandejas,
`alertas` intacta, roles (dirección recibe y no emite), unión de destinos sin duplicados
ni emisor, envío enlazado e historial con leídos, secciones del año activo y los gates
del controlador por método.

Antes estos asertos vivían en `verif_notas_origen.php` (bloques 5, 6, 7 y 7b), donde el
módulo quedaba sin verificador propio; se mudaron y aquel script sigue en verde.

## 9. Prueba en navegador y pendientes

**Probado con sesión el 16/09/2026** (BD local de la laptop), sin observaciones: comunicados
con cada casilla y combinados, validaciones de cliente y servidor, historial con leídos y
XSS escapado; docente (marcar una sin recargar, marcar todas, «Ver detalle» queda leída);
director (campana, marcar leída, 403 al redactar y al historial); refresco de avisos
repetidos; vista móvil; chip de procedencia en la bandeja.

Pendiente:
1. **`058` y `060` en PRODUCCIÓN, a mano y ANTES del merge** (van en la cola
   `057`→`060`; ver `docs/ESTADO.md`).
2. Al desplegar, actualizar la cabecera de este doc.

**Diferido (no se implementa sin decisión):** paginación o limpieza de la bandeja (hoy
muestra las 100 más recientes).
