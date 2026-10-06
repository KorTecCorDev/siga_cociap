# Sesión que no hace perder el trabajo + borradores de formularios

> **Estado: fases 1 (sesión), 2 (borradores + rectificación en lote) y 3 (rectificación por
> competencia, extraordinaria individual, notas SIAGIE) implementadas en `dev` y probadas en
> navegador (06/10/2026), sin desplegar.** Migración `074`, aplicada solo en local. Fase 4 en
> curso; ver `docs/ESTADO.md`.
> Módulos relacionados: `calificaciones.md` (rectificación), `matriculas.md` (notas de origen).

## 1. Por qué existe

Registro Académico transcribe notas desde boletas físicas o escaneadas, una por una, y pasa
**más de 10 minutos leyendo sin pulsar nada** (a veces horas, esperando al docente). La sesión
vence a los 10 minutos **sin peticiones**, y al pulsar «Guardar» con la sesión vencida se perdía
**todo** —también el respaldo `lote_old`, que vivía en la misma sesión— y el login mandaba al
dashboard.

## 2. Fase 1 — la sesión

Tiempo: `config/app.php` → `session_timeout` (600). Del mismo valor salen el **aviso** (al
faltar 60 s) y la **renovación silenciosa** (al faltar 120 s); ver `resources/js/sesion.js`.

### 2.1 Piezas
| Pieza | Qué hace |
|---|---|
| `Core\Session` | Vence por inactividad (lo decide SIEMPRE el servidor). Guarda `_ultima_pantalla` y `_volver`, `fijarUltimaActividad($hace)`, `noContarComoActividad()`. |
| `Core\View::render` | Cada página completa del layout `app` anota `_ultima_pantalla`. |
| `Auth\SesionController` | `POST /sesion/renovar`, `GET /sesion/estado`, `GET /sesion/expirada`. **Sin modelos en el constructor:** renovar y estado no abren MySQL (medido: 0 conexiones). |
| `resources/js/sesion.js` | Aviso, renovación silenciosa por actividad humana, cierre vía `/sesion/expirada`. Solo en el layout `app`. |
| `helpers.php` | `ruta_actual_app()`, `volver_valido()` (validación anti redirección abierta). |

### 2.2 Reglas (decisiones del usuario, 06/10/2026)
- **El regreso tras el login vive EN LA SESIÓN, nunca en la URL.** En la URL era editable
  (llevaba a otra pantalla) y exponía datos (`matricula=698`). Se anota la última pantalla
  mostrada; al vencer pasa a la sesión anónima del login (`_volver`), sobrevive si el login queda
  abierto >10 min, y se usa UNA vez. `/sesion/expirada` **no recibe parámetros**.
- **Mensajes genéricos**: el login dice lo de siempre («Tu sesión cerró por inactividad. Vuelve a
  ingresar.»); el modal, «No se pudo continuar. Vuelve a ingresar.»; los JSON de `/sesion/*` no
  llevan mensaje. Nunca se explica el flujo interno.
- **Cuenta como actividad la acción humana real**: teclas, campos, clics, toques, rueda
  (`isTrusted`). **Mover el mouse NO.** Con el aviso abierto, solo el botón / Escape / clic fuera.
- **Cambiar de ventana o pestaña (Ctrl+Tab, Alt+Tab) TAMBIÉN cuenta**: la tecla modificadora llega
  a la página antes de que el navegador intercepte el atajo. Se ofreció filtrarla y el usuario
  decidió dejarlo así. Un cronómetro que marca ~10 s de más tras un Ctrl+Tab es esto, no un bug.
- **Las vistas imprimibles (layout `print`) no tienen aviso ni redirección**: una boleta
  proyectada se queda en pantalla. Para exponer, la versión imprimible; no excepciones a la regla.

### 2.3 Tres fallos que dieron forma al diseño (no reintroducir)
1. 🔴 **La sesión no vencía nunca.** La primera versión, al llegar a cero, visitaba `/login`. El
   servidor vence con `> timeout` (un segundo después del contador); esa visita **renovaba** la
   sesión y `showLogin` devolvía a la pantalla. Por eso: el navegador **consulta**
   (`/sesion/estado`, que restaura la marca previa y no renueva) y **cierra** con
   `/sesion/expirada`, que destruye la sesión solo si el servidor confirma la inactividad.
2. **Renovar reiniciaba el reloj al RENOVAR, no al ACTUAR.** Con 10 min, la PC quedaba abierta
   hasta ~18 min. Por eso la renovación silenciosa manda **`hace`** (segundos desde la última
   acción) y el servidor fija la marca ahí (`fijarUltimaActividad`: entre 0 y el timeout, nunca
   antes de la marca previa). Cada acción renueva una sola vez (`accionRenovada`).
3. **`volver` se perdía en el propio login** (se recalculaba como `/login`, que se rechaza). Hoy
   el destino no se recalcula: viaja en la sesión.

### 2.4 Seguridad
- Sin renovación automática: solo la dispara una persona (botón o actividad `isTrusted`).
- `volver_valido()`: solo rutas internas con juego de caracteres cerrado; rechaza `//`, `\`,
  esquemas, `..`, CR/LF, arreglos (`volver[]`) y `/login`, `/logout`, `/sesion/*`, `/borradores/*`.
- `/sesion/expirada` no expulsa a quien trabaja (exige inactividad confirmada en servidor).
- Corregido de paso: `config('app.session_timeout')` siempre devolvía el valor por defecto
  (`config()` no admite puntos); hoy `Session::timeout()` lee `session_timeout`.
- ⚠️ Preexistente, fuera de este trabajo: la **sesión única por usuario no se aplica**
  (`UsuarioModel::tokenValido()` nunca se llama). Tarea aparte; ver `docs/ESTADO.md`.

### 2.5 Probar sin esperar 10 minutos
Bajar `session_timeout` en local (p. ej. 60: aviso a los 30 s, renovación a los 45 s) y
**restaurar 600 antes de cualquier commit**. Reconstruir cada prueba con el log de Apache
(`/sesion/renovar`, `/sesion/estado`, `/sesion/expirada`). En Git Bash, `MSYS_NO_PATHCONV=1`
al pasar rutas `/…` a curl (si no, las convierte en `C:/Program Files/Git/…`).

## 3. Borradores de formularios

🔴 **Regla central: un borrador NUNCA toca tablas oficiales y se elimina SOLO tras guardar con
éxito** (sin botón «Descartar», sin vencimiento: decisión del usuario). Guardar una
extraordinaria asegura el bloqueo y la nota sale en boleta y SIAGIE al instante; por eso lo
tecleado vive aparte hasta pulsar «Guardar».

### 3.1 Piezas (fase 2)
| Pieza | Qué hace |
|---|---|
| Migración `074` | `borradores_formulario`: `usuario_id` y `matricula_id` con FK **CASCADE**, `tipo`, `clave`, `datos` (JSON), `revision`. UNIQUE (usuario, tipo, clave). |
| `BorradorModel` | PUNTO ÚNICO. `TIPOS` (lista blanca, se habilitan por fase), `clave()` (solo enteros), `contextoValido()`, `obtener`, `guardar` (con revisión), `deOtros` (nombre y fecha, nunca datos), `eliminar`. |
| `BorradorController` | `POST /borradores/guardar`, el ÚNICO endpoint. No hay ruta para LEER: lee el GET de cada formulario, para el usuario de la sesión. |
| `resources/js/borrador.js` | Genérico: `<form data-borrador-tipo data-borrador-ctx-* data-borrador-revision>`. Guarda 1,5 s tras teclear, al salir de un campo y en `pagehide`; muestra `[data-borrador-estado]`. **Deja de guardar al enviar** (si no, el guardado de salida recrearía el borrador que el POST acaba de borrar). |

### 3.2 Cómo se restaura
**Lo pinta el servidor, no el JS.** El GET mapea el borrador a la misma estructura que ya
repintaba lo escrito tras un rechazo de validación (`$old` / `lote_old`), así cada valor pasa
por `e()`. Si hay un rechazo (flash), es más reciente y GANA sobre el borrador. Valores de filas
que ya no existen (el borrador quedó viejo) se ignoran porque la vista solo pinta las filas
vigentes.

### 3.3 Seguridad (S1-S11 del plan)
- Dueño SIEMPRE de la sesión; el cliente manda `tipo` + ids, **nunca la clave**; el contexto se
  valida (matrícula existente y periodo de SU año — no repite la regla «rectificable», que
  exige el POST oficial).
- CSRF, rol por tipo, `Throttle` 60/min por usuario, tope 256 KB, JSON válido; se guarda el
  JSON **re-codificado**, no el texto crudo.
- 409 si la revisión no calza (dos pestañas/equipos): no se pisa; el JS deja de guardar y pide
  recargar. Respuestas sin mensajes; el contenido del borrador nunca va al log.

### 3.4 Formularios
| Formulario | Tipo | Estado |
|---|---|---|
| Rectificación en lote (`/rectificaciones/extraordinaria/lote`) | `rect_lote` | ✅ fase 2 |
| Rectificación por competencia (`/rectificaciones/editar`) | `rect_competencia` | ✅ fase 3 |
| Extraordinaria individual (`/rectificaciones/extraordinaria`) | `rect_extraordinaria` | ✅ fase 3 |
| Notas autorizadas SIAGIE (`/matriculas/{id}/notas-siagie`) | `notas_siagie` | ✅ fase 3 |
| Notas del colegio de origen | — | fase 4 |

### 3.5 Detalles de la fase 3
- **Avisos en un parcial**: `shared/_borrador-avisos.php` («Se recuperó tu borrador…» y «X tiene
  un borrador…»), usado por todas las vistas con borrador.
- **Clave con prefijos explícitos** (`m5-ca7-co9-p2`): `carga` y `competencia` comparten inicial.
- **La carga se valida contra el AÑO de la matrícula, no contra su sección**: en un retorno de
  grado el bimestre se cursa en otra sección.
- **Extraordinaria individual**: no tenía `$old` (un rechazo borraba lo escrito); ahora el
  borrador lo devuelve a los campos.
- **Notas SIAGIE**: la pantalla tiene **un formulario por bimestre**, así que el borrador va por
  matrícula + periodo y `borrador.js` maneja varios formularios por página. La matrícula de la
  clave es la `identidad()` del retorno de grado (la misma del URL y del POST).

### 3.6 Verificación
`database/verificaciones/verif_borradores.php` (transacción + rollback): clave, contexto en
sus dos ramas, revisión y conflicto, aislamiento entre usuarios, **8 tablas oficiales sin
cambios**, FK CASCADE, y que el POST elimina el borrador DESPUÉS del commit y en ningún otro
punto.
