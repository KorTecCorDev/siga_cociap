# Ratificación de matrícula

> **Estado (17/10/2026): DESPLEGADO en producción** (merge `bf885dd`; Fases 1 a 5, probado en
> Chrome con admin y con director). La migración 080 se aplicó en prod antes del merge. Se
> adelantó al 19/10 previsto por decisión del usuario. Pendientes en `docs/ESTADO.md`. Lo construido se describe en **«Cómo funciona»**; la sección «Fases»
> queda como bitácora de la construcción.


## Contexto
Dirección decide, al final del año, qué estudiantes continúan el año siguiente.
- Ratificados → **Constancia de Ratificación de matrícula**.
- No ratificados → **Documento informativo** con un motivo específico.
- El contenido cambia cada año (texto, tablas, imágenes, diseño) y el colegio debe poder actualizarlo sin un programador.

No existe nada previo en código ni en docs.

## Decisiones del usuario (13/10/2026)
| Tema | Decisión |
|---|---|
| Contenido variable | **D**: esqueleto fijo en código + cuerpo editable por bloques, por año. Sin HTML guardado. |
| Quién registra | **Admin/Secretaría**. Los directores conservan su SOLO LECTURA (ven, no escriben). |
| Entrega | **Impreso A4 + QR** de autenticidad. Individual y en lote por sección. |
| Efecto en matrícula | **Aviso** al matricular el año siguiente a un no ratificado. No bloquea. |
| Universo | Matrículas vigentes del año, **sin trasladados/retirados ni 5.º de secundaria**. En retorno de grado, la matrícula oficial. |
| Motivo | **Catálogo por año** (párrafo oficial redactado por Dirección) + **detalle opcional** por estudiante. |
| QR | Solo **autenticidad**: estudiante, año, fecha de emisión y estado (vigente/anulado). Nunca el tipo ni el motivo. |
| Numeración | **No** hay correlativo; solo la fecha de emisión. |
| Apoderado en el documento (14/10) | Variable **`{apoderado}`** = el apoderado **RESPONSABLE** del estudiante (`vinculo_familiar.es_responsable = 1`; el vínculo es del estudiante, no de la matrícula). Al 14/10 los 474 estudiantes del universo tenían exactamente uno. |
| Datos al reimprimir (14/10) | Las variables (estudiante, DNI, grado, apoderado…) se **leen al imprimir**, NO se congelan en la emisión: si cambia un dato, la reimpresión sale con el nuevo. Solo el texto del motivo y del detalle se copia al emitir. |

## Cómo funciona (lo construido)

### Reglas de negocio (invariantes del módulo)
1. **Tres estados**: `pendiente` (sin fila) / `ratificado` / `no_ratificado`. El olvido nunca
   produce una carta negativa: un pendiente no recibe documento, ni individual ni en lote.
2. **`no_ratificado` exige motivo** (modelo + CHECK `chk_ratif_decision_motivo`); un
   `ratificado` no lleva motivo ni detalle. El motivo es del MISMO año; uno retirado solo lo
   conserva quien ya lo tenía.
3. **Universo** — PUNTO ÚNICO `RatificacionModel::sqlUniverso()`: matrículas del año en
   CUALQUIER estado, menos trasladados y retirados (`matriculas_vigentes()`), la operativa de un
   retorno (`matricula_documento()`) y 5.º de secundaria (`niveles.codigo='sec' AND
   grados.numero=5`, nunca por id).
4. **Un documento emitido es inmutable.** Emitir congela la plantilla vigente (`congelada_en`);
   editarla después crea la versión siguiente y la emisión conserva la suya. El texto oficial del
   motivo y el detalle se COPIAN a la emisión. Las demás variables (estudiante, DNI, grado,
   apoderado…) se LEEN al imprimir: una reimpresión sale con los datos corregidos.
5. **Una sola emisión vigente por decisión** (`vigente` 1/NULL + UNIQUE). Cambiar la decisión
   con documento emitido exige confirmación (`confirmar_anulacion=1`) y lo ANULA en la misma
   transacción. `emitir`, `anular` y `guardarDecision` toman el MISMO candado (`FOR UPDATE`
   sobre la fila de `ratificaciones`): no se cruzan.
6. **El token del QR es propio de la emisión** (32 hex, `random_bytes`). **NUNCA
   `matriculas.token_acceso`**, que abre la boleta (lo barre el verificador).
7. **La verificación pública solo dice si el documento es auténtico**: nombre, año, fecha de
   emisión y vigente/anulado. Nunca DNI, tipo ni motivo. Token desconocido o mal formado: la
   misma respuesta (404).

### Quién hace qué
- **Escriben** (decisiones, motivos, plantillas, emitir, anular): `RatificacionModel::ROLES_REGISTRAN`
  = admin, registro_academico, secretaria_academica, secretaria_administrativa.
- **Ven** (`ROLES_VEN`): los anteriores + `ROLES_DIRECCION` en solo lectura. Dirección también
  **imprime y descarga el lote** de lo ya emitido (es lectura).
- Acceso mixto como `AsistenciaController`: el constructor admite `ROLES_VEN` y cada método de
  escritura empieza con `requireRole(ROLES_REGISTRAN)` + `validateCsrf()`. No entra en
  `verif_direccion_solo_lectura.php` (que exige admin + RA): lo vigila `verif_ratificaciones.php`
  §4, que falla si nace un método público sin clasificar.

### Base de datos — migración `080_ratificaciones.sql` (solo CREA tablas)
| Tabla | Qué guarda |
|---|---|
| `ratificacion_plantillas` | (año, tipo, versión) UNIQUE, título, `congelada_en`. La vigente es la de versión más alta. |
| `ratificacion_bloques` | orden UNIQUE por plantilla; tipo `parrafo`/`tabla`/`imagen`/`salto` con su dato (CHECK `chk_ratb_tipo_dato`, JSON válido). |
| `ratificacion_motivos` | catálogo por año (UNIQUE año + nombre), `texto_oficial`, `activo` (se retira, nunca DELETE). |
| `ratificaciones` | una decisión por matrícula (UNIQUE), motivo, detalle ≤ 500, quién y cuándo. |
| `ratificacion_emisiones` | plantilla usada, copia del motivo y del detalle, `token` UNIQUE, `vigente` 1/NULL, emitido/anulado por y en. |

### Diseño del documento: CONTRATO y COMUNICADO (15/10/2026)
Decisión del usuario: el documento de un ratificado es un **contrato** de reserva de matrícula,
que da garantía a las dos partes; el de un no ratificado es un **comunicado** (carta).
- **Cabecera** tomada del informe de Acompañamiento pedagógico (`.cuadros-print__head`): logo a
  la izquierda, institución (ente rector, nombre oficial, códigos modular —**según el nivel**— y
  de local, resoluciones), **QR de verificación arriba a la derecha** y regla negra de 2 px.
  Debajo, el lema del año. Todo en negro y grises. NO se copia su letra de 10-11 px: este
  documento lo lee y lo firma una familia (cuerpo a 10,5 pt).
- **Título** = el de la plantilla, sin subtítulo fijo (lo redacta Dirección).
- **Cuadro de datos** (rótulo gris + valor):
  - contrato: EL COLEGIO (representado por el Director EBR, con su cargo y nombre), EL APODERADO
    (responsable: nombre, DNI, parentesco) y EL ESTUDIANTE (nombre, DNI, grado, sección, nivel, año);
  - comunicado: APODERADO(A) y ESTUDIANTE.
  - **Sin apoderado responsable registrado** (1 estudiante al 15/10), sus datos salen en blanco
    para llenarlos a mano: el documento NO se bloquea.
- **Cuerpo** por bloques; en el comunicado, el **motivo** en un recuadro gris con filete negro.
- **Cierre** (lugar, fecha y firmas, `break-inside: avoid`: no se separan entre hojas):
  - contrato: DOS firmas lado a lado: «Por el colegio» (firma + sello del Director EBR vigente en
    la FECHA DE EMISIÓN) y «El apoderado» (línea, nombre y DNI impresos);
  - comunicado: solo la del Director, centrada.
- **Un solo ejemplar** por PDF: si el colegio necesita dos, imprime dos veces.
- **Marca de agua** (VISTA PREVIA gris / ANULADA roja): `position: fixed`, NO `absolute`. Al
  imprimir, Chrome repite un elemento fijo en CADA hoja, centrado; con `absolute` había una sola
  marca al 46 % del documento entero y en uno de varias hojas caía partida en el corte. En
  pantalla queda centrada en la ventana. ⚠️ Si un contenedor padre recibiera `transform` o
  `filter`, la marca dejaría de anclarse a la hoja.
- Estilo propio en `_ratificacion.scss` (`.ratif-doc`), **independiente de `_constancia.scss`**:
  tocar la constancia de traslado no mueve este documento.
- Recomendación para las plantillas: en un contrato, no terminar con un salto de página, para
  que las firmas compartan hoja con la última cláusula.

### Contenido por bloques (enfoque D)
- **Párrafo**: texto plano; `renderParrafo()` escapa PRIMERO y luego aplica `**negrita**` y las
  variables `{estudiante}` `{dni}` `{grado}` `{seccion}` `{nivel}` `{anio}` `{anio_siguiente}`
  `{apoderado}` (= el apoderado RESPONSABLE), `{apoderado_dni}` y `{parentesco}` (etiqueta de
  `MatriculaController::tiposVinculo()`). Una variable desconocida no se puede guardar.
- **Tabla**: la primera fila es la cabecera. **Imagen**: PNG/JPEG de hasta 2 MB, validado por
  contenido, en tres tamaños. **Salto de página**: `break-before: page`.
- Versiones, «Duplicar del año anterior» (por número de año) y vista previa con datos de
  ejemplo y marca de agua, sin firma, sello ni QR.
- Las imágenes viven en `config('ratificacion_img_path')`: `~/siga_uploads/ratificacion/` en
  prod, `storage/ratificacion/` en local (ignorada por git). Se sirven **con login** por
  `RatificacionPlantillaController@imagen` (nombre estricto + `realpath` + `nosniff`).

### Pantallas y rutas
| Ruta | Qué es | Acceso |
|---|---|---|
| `GET /admin/ratificaciones` | secciones con contadores; con `seccion_id`, la decisión de cada estudiante y su documento | ver |
| `POST /admin/ratificaciones/{matricula_id}/decision` | registrar o cambiar la decisión | escribir |
| `POST /admin/ratificaciones/{matricula_id}/emitir` · `/anular` | emitir o anular un documento | escribir |
| `POST /admin/ratificaciones/seccion/{id}/emitir` | emitir a los decididos sin documento; si falta una plantilla, emite al resto y lo informa | escribir |
| `GET /admin/ratificaciones/emision/{id}/imprimir` | A4 de la MISMA emisión (mismo QR); una anulada sale con el sello ANULADA, sin firma ni sello | ver |
| `GET /admin/ratificaciones/seccion/{id}/lote` | ZIP con un PDF por documento VIGENTE (html2pdf + JSZip) | ver |
| `GET /admin/ratificaciones/motivos` (+ crear, editar, retirar, reactivar) | catálogo por año | ver / escribir |
| `GET /admin/ratificaciones/plantillas` (+ guardar, duplicar, vista previa, imagen) | editor por bloques | ver / escribir |
| `GET /ratificacion/verificar/{token}` | verificación PÚBLICA del QR (`RatificacionVerificacionController`, layout `digital`) | público |

Entrada: card en `resources/views/dashboard/index.php` (no hay menú lateral).

### Aviso al matricular
`MatriculaController::create` llama a `RatificacionModel::avisoNoRatificado($estudianteId,
$anioActivo['anio'])`: emisión VIGENTE `no_ratificado` del año anterior **por número de año**.
`matriculas/crear.php` pinta un `alert alert--warning` (sin glifo) con el año del documento y
su fecha de emisión. **No bloquea** la matrícula. Ver también `docs/modulos/matriculas.md`.

### Gotchas (vistos al construir)
- **Un parcial incluido en un bucle se aísla en una función.** `_bloque-editor.php` y cada
  documento del lote se incluyen dentro de un `static function`: sueltos, sus variables pisan
  las del siguiente (así se perdía la tabla del bloque siguiente).
- **html2pdf**: márgenes POR HOJA en `ratificacion-lote.js` (en el ítem solo darían margen a la
  primera y a la última) y `pagebreak: avoid-all + css + legacy`. html2canvas pinta con estilos
  de PANTALLA: lo que solo debe verse en pantalla se anula dentro de `.ratif-lote-item`.
- El layout `print` no carga `page_scripts`: el QR se genera con un script en la propia vista,
  una sola vez por página (`RATIF_QR_SCRIPT_LOADED`).
- **Con BrowserSync** la URL impresa bajo el QR sale `//localhost:3000/…` (reescribe enlaces);
  PHP la genera completa. En la oficina no hay `storage/firmas/`: firma y sello no se ven en local.

### Textos
Los mensajes siguen la regla «no revelan el flujo». Los párrafos oficiales y los motivos los
redacta Dirección; el sistema no inventa ninguna redacción legal.

## Fases — plan de implementación y avance (aprobado el 14/10/2026)

Reglas del plan: un commit por fase (Conventional Commits, español sin tildes); `php -l` +
`verif_ratificaciones.php` al cerrar cada fase; **me detengo entre fases y aviso**; prueba en
Chrome con la sesión del usuario, diciendo con QUÉ rol se probó.

### Decisiones del usuario del 14/10 (se suman a las del 13/10)
| Tema | Decisión |
|---|---|
| Quién escribe | `admin`, `registro_academico`, `secretaria_academica`, `secretaria_administrativa` (`RatificacionModel::ROLES_REGISTRAN`). `ROLES_DIRECCION` solo ve (`ROLES_VEN`). Rige para decisiones, motivos y plantillas. |
| Universo | Fuera trasladados, retirados, la operativa de un retorno y 5.º de secundaria. **Todos los estados** (pendiente/aprobada/desactivado). Punto único `RatificacionModel::sqlUniverso()`. |
| Emisión | Botón **«Emitir»** explícito (POST individual o por sección, en transacción). Imprimir es un GET que **reimprime la MISMA emisión** (mismo token). |
| Cambio con emisión vigente | `confirm()` que lista cada campo antes → después; en la MISMA transacción se anula la emisión vigente. El servidor exige `confirmar_anulacion=1` (ya construido en la Fase 1). |
| Verificación pública | Nombre completo, año, fecha de emisión y estado (vigente/anulado). **Nunca DNI, tipo ni motivo.** |
| Apoderado | Variable `{apoderado}` = el **responsable** (ver tabla de decisiones de arriba). |
| Datos al reimprimir | Se leen al imprimir; solo el texto del motivo y del detalle se congela en la emisión. |

### Correcciones al plan del 13/10 (historial: ya incorporadas en «Cómo funciona»)
1. `config('ratificacion_img_path')` — clave plana; `config()` no admite puntos. Se detecta por el
   home de Hostinger, como `siagie_tmp_path`: en prod `~/siga_uploads/ratificacion/`, en local
   `storage/ratificacion/` (en `.gitignore`). El controlador crea la carpeta al subir la primera.
2. **No hay menú lateral**: la entrada es una card en `resources/views/dashboard/index.php`.
3. La verificación pública **no** va en `Admin\RatificacionController` (la auth está en su
   constructor): va en un controlador propio en la raíz, `app/Controllers/RatificacionVerificacionController.php`,
   como `FirmaController` / `BoletaPublicaController`. Ruta `GET /ratificacion/verificar/{token}`.
4. Las imágenes de plantilla se sirven **con login** por `RatificacionPlantillaController@imagen`
   (PNG o JPG, nombre estricto, `realpath`, `nosniff`); no por `FirmaController`, que es público y solo PNG.
5. 5.º de secundaria = `niveles.codigo = 'sec' AND grados.numero = 5`, nunca por id.
6. Año anterior/siguiente por **número** (`anios_academicos.anio ± 1`); los ids tienen huecos.
   La BD local solo tiene 2026: el aviso al matricular se prueba con el verificador (año temporal).
7. `archivar-boletas.js` existe solo en `public/js/`: el JS nuevo va en `resources/js/` (gulp) y se
   reutiliza el PATRÓN, no el archivo.
8. **`verif_direccion_solo_lectura.php` NO se tocó**: exige que la escritura sea solo admin + RA, y
   aquí escriben también las secretarías. Se siguió el precedente de `AsistenciaController`:
   constantes propias en el modelo y verificador propio (`verif_ratificaciones.php` §4, que falla
   si nace un método público sin clasificar como lectura o escritura).
   **Sí** se actualizó `verif_direccion_superficies.php` (lista exacta de cards de Dirección).

### ✅ Fase 1 — Migración 080 + decisiones + motivos (commit `c8ffa1a`)
- `database/migrations/080_ratificaciones.sql`: 5 tablas, idempotente. Además del plan: columna
  `vigente` 1/NULL con UNIQUE(ratificacion_id, vigente) = **una sola emisión vigente**;
  `actualizado_por`; UNIQUE(anio_id, nombre) en motivos; UNIQUE(plantilla_id, orden) en bloques;
  CHECKs `chk_ratif_decision_motivo` (ratificado ⇒ sin motivo ni detalle), `chk_rate_vigente`,
  `chk_rate_motivo`, `chk_ratb_tipo_dato`, `chk_ratb_tabla_json`.
- `RatificacionModel` (universo, `guardarDecision` con anulación confirmada, `anularEmision`),
  `RatificacionMotivoModel` (por año; se retira, nunca DELETE), `Admin\RatificacionController`,
  vistas `index.php` y `motivos.php`, `resources/js/ratificacion.js`, `_ratificacion.scss`, card del dashboard.
- Probada en Chrome con **admin**. Corregido en la prueba: el error volvía con ancla a la fila y
  el mensaje quedaba fuera de vista (con error ahora vuelve arriba); `scroll-margin-top` en filas.

### ✅ Fase 2 — Plantillas por bloques (commit `2a8c8af`)
- `RatificacionPlantillaModel`: PUNTO ÚNICO de versiones (`guardar`: reescribe la no congelada,
  crea la versión siguiente sobre la congelada; `huella` sha256 para «sin cambios» y para no pisar
  a quien guardó entretanto), validación (`validarBloques`, límites en constantes), render seguro
  (`renderParrafo`: escapa primero, luego `**negrita**` y variables; variable desconocida = error),
  `duplicarDelAnioAnterior`, `bloquesParaDocumento`, `varsEjemplo`.
- `Admin\RatificacionPlantillaController`: `index`, `guardar` (multipart: valida subidas por
  contenido, mueve los archivos solo tras validar y los borra si el guardado falla), `duplicar`,
  `vistaPrevia`, `imagen`.
- Vistas `plantillas.php` (pestañas por tipo con `.consulta-eje`; Dirección ve el contenido sin
  editor), `_bloque-editor.php` (⚠️ se incluye SOLO dentro de una función: suelto, su `$i` pisaba
  la clave del bloque siguiente y se perdía la tabla — lo vigila el verificador), `documento.php`
  (A4, layout `print`, esqueleto de la constancia de traslado; hoy solo como vista previa).
- `resources/js/ratificacion-plantilla.js`; no se puede quitar el último bloque.
- Probada en Chrome con **admin** (guardar, sin cambios, versión congelada → v2, imagen falsa
  rechazada, rutas de imagen 302 sin sesión y 404 con `../`).
- **Pendiente de probar por el usuario:** Ctrl+P en la vista previa (que el salto corte la hoja) y
  la pantalla con un **director** (contenido visible, sin editor). Tampoco se probó aún con un
  director la Fase 1 (sin formularios; POST → 403).

### ✅ Fase 3 — Emisión, documento A4, anulación y verificación pública (commit `0b8f530`, 15/10/2026)
Decisiones del usuario del 15/10: **firma + sello** del Director EBR; **«Emitir sección» emite lo
que se pueda** y avisa cuántos quedaron sin emitir y qué plantilla falta; **Dirección puede
imprimir** un documento ya emitido (es lectura; emitir y anular siguen siendo de `ROLES_REGISTRAN`).
- `RatificacionModel`: `emitir()`, `emitirSeccion()` (una transacción; salta los tipos sin
  plantilla con `hayPlantilla()`), núcleo privado `emitirEnTransaccion()` (candado `FOR UPDATE`
  sobre la decisión y sobre la plantilla vigente, la congela, copia `motivo_texto` y
  `detalle_texto`, token `bin2hex(random_bytes(16))`), `anular()`, `emisionParaDocumento()`
  (variables y apoderado responsable leídos AL IMPRIMIR), `varsDeEmision()`, `porToken()`.
  `guardarDecision()` toma ahora el MISMO candado y rechaza si la emisión cambió entretanto.
- `Admin\RatificacionController`: `emitir`, `emitirSeccion`, `anular` (escritura) e `imprimir`
  (lectura, `GET /admin/ratificaciones/emision/{id}/imprimir`: la MISMA emisión, mismo QR; una
  anulada sale con el sello ANULADA y sin firma ni sello). Código modular por nivel.
- `RatificacionVerificacionController@verificar` (`GET /ratificacion/verificar/{token}`, sin
  login, layout `digital`, vista `ratificacion/verificar.php`): nombre, año, fecha de emisión y
  vigente/anulado (+ fecha de anulación). Token desconocido o mal formado: mismo mensaje, 404.
- `documento.php`: firma + sello (`.ratif-doc__firmas`) y QR local (`qrcode.min.js`, script en
  la vista porque el layout `print` no carga `page_scripts`; guarda `RATIF_QR_SCRIPT_LOADED`
  para el lote de la Fase 4).
- Pantalla de la sección: «Emitir documento» por fila, «Emitir documentos de la sección (N)»,
  y con documento: fecha, «Imprimir» y «Anular» (confirm).
- `verif_ratificaciones.php` §7 (20 asertos) + §4 con los métodos nuevos. Encontró un bug real
  (`[]` literal pasado por referencia en `emitir()`).
- Probada en Chrome con **admin** (oficina): emitir, imprimir, QR vigente, anular, QR anulado,
  reimpresión con ANULADA, emitir sección, cambio de decisión con emisión (confirm antes →
  después + anulación).
- ⚠️ En local **no hay firma ni sello**: `storage/firmas/` no existe en la oficina, las `<img>`
  salen con su ruta correcta pero 404. Se ven en prod (`~/siga_uploads/firmas/`).
- ⚠️ Con BrowserSync la URL impresa bajo el QR sale `//localhost:3000/...`: es su reescritura de
  enlaces del proxy; PHP la genera completa (`http://…`, comprobado contra Apache directo).
- ✅ El usuario probó el 15/10 el Ctrl+P del documento emitido y todo el módulo con un
  **director** (Fases 1-3): correcto.

### ✅ Fase 4 — Lote por sección + aviso al matricular (commit `7ebcc71`, 15/10/2026)
- **Lote** `GET /admin/ratificaciones/seccion/{id}/lote?anio_id=` (`RatificacionController@lote`,
  LECTURA: Dirección también). Solo emisiones VIGENTES de la sección dentro del universo
  (`RatificacionModel::emisionesVigentesDeSeccion()`, orden alfabético). `imprimir` y `lote`
  comparten `datosDocumento()`. Vista `lote.php` (cada documento se incluye en su PROPIO ámbito,
  la lección de `_bloque-editor.php`) + `resources/js/ratificacion-lote.js` (patrón de
  `archivar-boletas.js`). ZIP `NIVEL_GRADO_SECCION_RATIFICACION_AÑO.zip`, un PDF por
  estudiante `APELLIDOS_NOMBRES.pdf` (conserva tildes y Ñ). Botón «Descargar documentos en PDF
  (N)» en la pantalla de la sección cuando hay emitidos.
  - html2pdf con márgenes POR HOJA (`[15, 17.5]` mm, ítem de 175 mm de ancho útil) y
    `pagebreak: avoid-all + css + legacy`: no parte párrafos ni tablas y respeta el salto.
    Con margen en el ítem solo la 1.ª y la última hoja salían con margen.
  - html2canvas pinta con estilos de PANTALLA: la línea punteada del salto se anula dentro de
    `.ratif-lote-item`.
- **Aviso al matricular** (`MatriculaController::create` → `matriculas/crear.php`):
  `RatificacionModel::avisoNoRatificado($estudianteId, $anioActivo['anio'])` busca una emisión
  VIGENTE `no_ratificado` del año anterior **por número**. Banner `alert alert--warning`, sin
  glifo, NO bloquea. Dice qué pasa (no fue ratificado, año del documento y fecha de emisión).
- `verif_ratificaciones.php`: §7 suma 5 asertos (lote solo vigentes; aviso en año+1 por número,
  no en el mismo ni en +2, no con anulada, no a un ratificado) y `lote` en la lista de lectura.
- Probado en Chrome con sesión de **director** (la que estaba abierta): el lote generó el ZIP
  (interceptado dentro de un iframe, sin descargar), 1 PDF de 3 hojas con márgenes, QR y sin
  la línea punteada. El banner del alta se probó renderizando la vista con datos de ejemplo
  (la BD local solo tiene 2026) y su regla, con el verificador.
- ✅ El usuario descargó el ZIP de verdad el 15/10: correcto. Queda para producción el aviso al
  matricular para 2027 a un no ratificado.

### ✅ Fase 5 — Documentación (15/10/2026)
Este doc describe lo construido («Cómo funciona», «Impacto, despliegue y verificación»);
`docs/modulos/matriculas.md` documenta el aviso del alta; `docs/infraestructura.md`, la carpeta
de imágenes fuera del repo; `docs/ESTADO.md`, los pasos de despliegue. CLAUDE.md ya tenía la
fila de la tabla (no se tocó). El usuario probó el 15/10 la descarga real del ZIP: correcta.

### Recomendaciones anotadas (NO autorizadas, solo para el informe final)
- Acción en lote «marcar pendientes como ratificados» en una sección.
- Poder devolver una decisión a «pendiente».
- Arreglar el aserto de `verif_direccion_superficies.php` que busca `private const ROLES_MATRICULAN`
  (es `public` desde el commit 3452567, 06/10): **falla también en HEAD**, no es de este módulo.
- Aviso al salir del editor de plantillas con cambios sin guardar.

## Impacto, despliegue y verificación
- **Impacto en lo existente: BAJO.** La 080 solo CREA tablas (se revierte con `DROP`). Fuera del
  módulo solo se tocaron el alta de matrícula (aviso no bloqueante), la card del dashboard y
  `verif_direccion_superficies.php` (lista de cards de Dirección).
- **Despliegue** (pasos en `docs/ESTADO.md`): aplicar la **080 en producción ANTES del merge**
  (la card aparece en el dashboard y, sin las tablas, la pantalla falla); crear
  `~/siga_uploads/ratificacion/` con permisos de escritura. Desplegado el 17/10/2026.
- **Verificación**: `php database/verificaciones/verif_ratificaciones.php` (transacción +
  ROLLBACK, sin DELETE; años temporales 2099/2100). Cubre universo, CHECK, decisiones con
  anulación confirmada, plantillas (validación, XSS, versiones, huella, duplicar), emisión
  (congelado, copia del motivo, token propio, lote con plantilla faltante), anulación,
  verificación pública, lote y aviso al matricular, y las guardas de los dos controladores.
