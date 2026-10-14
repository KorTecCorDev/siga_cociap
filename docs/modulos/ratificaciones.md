# Ratificación de matrícula

> **Estado (14/10/2026): EN CONSTRUCCIÓN en `dev` — Fases 1 y 2 HECHAS y en `origin/dev`;
> faltan las Fases 3, 4 y 5.** Ver **«Fases — plan de implementación y avance»** más abajo:
> es el plan vivo (lo construido, las correcciones al plan del 13/10 y el detalle de lo que
> falta). **Sin desplegar. No desplegar antes de cerrar el III y abrir el IV (18-19/10/2026).**
> La migración 080 solo está aplicada en la BD local de la oficina.


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

## Hechos del repo que condicionan el diseño
- No hay dependencias PHP (ni `composer.json` ni `vendor/`). El PDF lo hace el navegador: `window.print()` para un documento, `html2pdf` + `jszip` para lotes (`public/js/archivar-boletas.js`).
- **NUNCA CSS inline**: todo el diseño va en SASS. Por eso no se usa WYSIWYG.
- Modelo de documento por estudiante: `resources/views/traslados/constancia.php` + layout `print` (`resources/views/layouts/print.php`, `public/js/print-fit.js`).
- Firma y sello: `DirectorEbrModel::getVigenteEnFecha($anioId, $fecha)`, pintados como en `boleta/alumno.php:394-403`.
- Subida de imágenes: patrón de `DirectorEbrController::subirImagen()` (`getimagesize`, 2 MB, `move_uploaded_file`). Se sirven como en `FirmaController` (regex de nombre + `realpath`).
- Archivos fuera del repo (`~/siga_uploads/`): el auto-deploy borra todo lo no versionado.
- QR local con `qrcode.min.js` (patrón `data-qr-url` de `boleta/alumno.php:338-369`).
- La próxima migración libre es la **080**.

## Diseño

### Reglas de negocio (invariantes del módulo)
1. **Tres estados**: `pendiente` (sin fila) / `ratificado` / `no_ratificado`. El olvido nunca produce una carta negativa: un estudiante pendiente no recibe documento.
2. **`no_ratificado` exige motivo**, por modelo y por `CHECK` en BD.
3. **Un documento emitido es inmutable.**
   - Emitir congela la plantilla de su año y tipo (`congelada_en`).
   - Editar una plantilla congelada crea una **versión nueva**; las emisiones anteriores apuntan a la suya.
   - La emisión guarda una copia del texto del motivo y del detalle (editar el catálogo no reescribe cartas ya entregadas).
4. **Cambiar la decisión de un estudiante con documento emitido** obliga a ANULAR la emisión (`anulado_en`). El QR de una emisión anulada dice «anulado».
5. **El token del QR es propio de la emisión** (32 hex, `random_bytes`). **NUNCA `matriculas.token_acceso`**, que abre la boleta.

### Migración `database/migrations/080_ratificaciones.sql`
Idempotente y con la cabecera estándar. Cierra con `SHOW TABLES`, nunca con un SELECT a `information_schema`.

| Tabla | Campos |
|---|---|
| `ratificacion_plantillas` | id, anio_id, tipo ENUM('ratificado','no_ratificado'), version, titulo, congelada_en NULL, creado_por, creado_en. UNIQUE(anio_id, tipo, version). |
| `ratificacion_bloques` | id, plantilla_id, orden, tipo ENUM('parrafo','tabla','imagen','salto'), texto TEXT NULL (párrafo), tabla_json LONGTEXT NULL con CHECK `json_valid`, imagen_archivo NULL, imagen_tamano ENUM('pequena','media','completa') NULL. |
| `ratificacion_motivos` | id, anio_id, nombre, texto_oficial TEXT, activo. |
| `ratificaciones` | id, matricula_id UNIQUE, decision ENUM('ratificado','no_ratificado'), motivo_id NULL, detalle VARCHAR(500) NULL, registrado_por, registrado_en, actualizado_en. CHECK (decision='ratificado' OR motivo_id IS NOT NULL). |
| `ratificacion_emisiones` | id, ratificacion_id, plantilla_id, decision, motivo_texto, detalle_texto, token CHAR(32) UNIQUE, emitido_por, emitido_en, anulado_por NULL, anulado_en NULL. |

### Contenido por bloques (el núcleo del enfoque D)
- **Esqueleto fijo** en la vista:
  - membrete y logo;
  - título de la plantilla;
  - datos del estudiante (nombre, DNI, grado/sección actual, año siguiente);
  - [cuerpo por bloques];
  - motivo (solo en no ratificado);
  - lugar y fecha;
  - firma y sello del Director EBR;
  - QR.
- **Párrafo**: texto plano. Se escapa con `e()` y después se aplica un marcado mínimo: `**negrita**` y las variables `{estudiante}`, `{dni}`, `{grado}`, `{seccion}`, `{nivel}`, `{anio}` y `{anio_siguiente}`. Se reemplazan sobre texto ya escapado, así que no hay XSS posible.
- **Tabla**: editor de filas y columnas. La primera fila es la cabecera. Celdas de texto plano escapadas.
- **Imagen**: PNG o JPEG validado con `getimagesize`, máximo 2 MB. Se guarda en `config('app')['ratificacion_img_path']`, en `~/siga_uploads/ratificacion/` (prod) y `storage/ratificacion/` (local). Se sirve por una ruta con autenticación al estilo de `FirmaController`. Solo admite tres tamaños, con clases SASS.
- **Salto de página**: `break-before: page`.
- Botón **«Duplicar plantilla del año anterior»**, para no reescribir desde cero cada año.
- **Vista previa** con datos de ejemplo, sin firma ni QR (como `$vistaPrevia` en la boleta).

### Capas (MVP)
- **Modelos** (`app/Models/`):
  - `RatificacionModel`: universo, decisiones, emisiones, anulación y consulta por token. Es el PUNTO ÚNICO del universo: reutiliza `matriculas_vigentes()` / `matricula_documento()` de `helpers.php` y la exclusión de `tipo IN ('trasladado','retirado')`. Se verifican sus firmas exactas al implementar.
  - `RatificacionPlantillaModel`: plantillas, versiones, bloques y congelado.
  - `RatificacionMotivoModel`.
- **Controladores**:
  - `app/Controllers/Admin/RatificacionController.php` y `RatificacionPlantillaController.php`. Escritura guardada POR MÉTODO con `ROLES_ESCRIBEN`; `ROLES_DIRECCION` puede ver. CSRF en todo POST. `notFound()` / `forbidden()`.
  - Verificación pública: `RatificacionController@verificar`, que valida el token con la regex `^[a-f0-9]{32}$`.
- **Vistas** (`resources/views/admin/ratificaciones/`):
  - `index` (por sección: estado de cada estudiante, marcar ratificado / no ratificado + motivo, contadores);
  - `plantillas` (editor de bloques);
  - `motivos`;
  - `documento.php` (A4, layout `print`);
  - `lote.php` (por sección, html2pdf + jszip);
  - `verificar.php` (pública, solo autenticidad).
- **Rutas** (`routes/web.php`): las literales ANTES que las de `{param}`. Pública: `GET /ratificacion/verificar/{token}`.
- **SASS**: `resources/sass/pages/_ratificacion.scss` importado en `app.scss`, con `@page` A4 como `_boleta.scss`. Gulp ya corre, no se compila a mano.
- **JS**: `resources/js/ratificacion-plantilla.js` (agregar, ordenar y quitar bloques; editor de tabla que serializa a JSON en un input oculto). El lote reutiliza el patrón de `archivar-boletas.js`.

### Aviso al matricular (toca el módulo de matrículas, mínimo)
- En el alta de matrícula, si el estudiante tiene en el año anterior una `ratificacion` con `no_ratificado` y una emisión vigente, se muestra un banner de aviso no bloqueante.
- Leer antes `docs/modulos/matriculas.md` y la sección de banners de `docs/modulos/ui.md`.
- El texto dice QUÉ pasa, nunca la regla interna.

### Textos
Los mensajes siguen la regla «no revelan el flujo». Los párrafos oficiales los redacta Dirección; el sistema no inventa ninguna redacción legal.

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

### Correcciones al plan del 13/10 (verificadas contra el repo; prevalecen sobre lo de arriba)
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

### ✅ Fase 2 — Plantillas por bloques (commit de esta sesión, ver `git log`)
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

### ⏳ Fase 3 — Emisión, documento A4, anulación y verificación pública (SIGUIENTE)
- `RatificacionModel::emitir()` individual y por sección, en transacción: bloquea `FOR UPDATE` la
  plantilla vigente del año y tipo (el mismo candado que usa `RatificacionPlantillaModel::guardar`),
  la **congela** (`congelada_en`), copia `motivo_texto` (texto oficial del motivo) y `detalle_texto`,
  token `bin2hex(random_bytes(16))` (**NUNCA `matriculas.token_acceso`**). Solo con decisión
  registrada (pendiente = sin documento) y sin otra emisión vigente (UNIQUE `uq_rate_vigente`).
  Sin plantilla del tipo para el año → mensaje claro, no emite.
- `anular()` (acción explícita, con `confirm`) y `porToken()`.
- Documento: reusar `documento.php` con datos reales — variables desde la matrícula **oficial**
  (estudiante, DNI, grado, sección, nivel, año, año siguiente) y `{apoderado}` = responsable
  (`vinculo_familiar.es_responsable = 1`, por `estudiante_id`), **leídas al imprimir**. Código
  modular según el nivel (como `TrasladoController::imprimir`). Firma y sello:
  `DirectorEbrModel::getVigenteEnFecha($anioId, fecha_emision)`; cargo por sexo. QR como
  `boleta/alumno.php` (`data-qr-url` + `qrcode.min.js` local). Sello «ANULADA» como
  `.constancia-anulada-sello` y sin firma si está anulada. El layout `print` no carga
  `page_scripts`: revisar cómo inyecta el QR la boleta antes de escribirlo.
- `RatificacionVerificacionController@verificar` + vista `verificar.php`: regex `^[a-f0-9]{32}$`;
  token desconocido → misma respuesta neutra; `noindex`; muestra solo lo de la tabla de decisiones.
- Pantalla de la sección: botones «Emitir» (fila y sección), «Imprimir» (GET) y «Anular».
- Verificador: emitir congela; editar tras emitir crea versión y la emisión vieja conserva la suya;
  el texto del motivo queda copiado aunque se edite el catálogo; token anulado/desconocido; las
  guardas de los métodos nuevos (añadirlos a `$escritura`/`$lectura` del §4).

### ⏳ Fase 4 — Lote por sección + aviso al matricular
- `lote.php` + `resources/js/ratificacion-lote.js` con el patrón html2pdf + jszip de
  `public/js/archivar-boletas.js`; solo emisiones vigentes.
- Aviso: leer antes `docs/modulos/matriculas.md` y § banners de `docs/modulos/ui.md`.
  `RatificacionModel::avisoNoRatificado($estudianteId, $anioActivo)` (año anterior por número,
  emisión vigente `no_ratificado`); `MatriculaController::create()` pasa el dato y
  `matriculas/crear.php` pinta `alert alert--warning`, sin glifos (revisar el autocierre de
  `auth.js`). El texto dice QUÉ pasa, no la regla. No bloquea.

### ⏳ Fase 5 — Documentación
Este doc pasa a describir lo construido (cabecera y secciones de diseño); `docs/ESTADO.md`
(080 pendiente en prod; carpeta de imágenes); CLAUDE.md ya tiene la fila (no se toca).

### Recomendaciones anotadas (NO autorizadas, solo para el informe final)
- Acción en lote «marcar pendientes como ratificados» en una sección.
- Poder devolver una decisión a «pendiente».
- Arreglar el aserto de `verif_direccion_superficies.php` que busca `private const ROLES_MATRICULAN`
  (es `public` desde el commit 3452567, 06/10): **falla también en HEAD**, no es de este módulo.
- Aviso al salir del editor de plantillas con cambios sin guardar.

## Esfuerzo e impacto
- **Esfuerzo estimado:** de 4 a 6 sesiones de trabajo, entre 15 y 25 h en total contando las pruebas en Chrome y los ajustes del A4.
  - Fase 2 (editor de bloques) es la más grande: unas 6 a 9 h.
  - Fase 4 (lote + aviso) es la que más se puede recortar.
- **Archivos:** unos 17 nuevos y unos 7 modificados:
  - `routes/web.php`;
  - `app.scss`;
  - `config/app.php` (ruta de imágenes);
  - el menú lateral;
  - `MatriculaController` y la vista del alta (aviso);
  - `CLAUDE.md` y `docs/ESTADO.md`.
- **Impacto en lo existente:** BAJO.
  - La migración 080 solo CREA tablas y no altera ninguna existente, así que se revierte con `DROP`.
  - El único flujo existente que se toca es el alta de matrícula, y solo para mostrar un aviso que no bloquea.
- **En producción:** aplicar la 080 a mano y crear `~/siga_uploads/ratificacion/`.
- **Calendario:** desarrollar en `dev` y desplegar **después de cerrar el III y abrir el IV (18-19/10)**. Ruta crítica real: Dirección debe entregar los textos y el catálogo de motivos de 2026.

## Verificación
- `database/verificaciones/verif_ratificaciones.php` (transacción + ROLLBACK, sin DELETE de datos ajenos). Comprueba que:
  - el universo excluye a trasladados, retirados, 5.º de secundaria y la matrícula operativa de un retorno;
  - el CHECK impide un `no_ratificado` sin motivo;
  - emitir congela la plantilla y editarla crea una versión;
  - la emisión conserva su texto aunque se edite el motivo;
  - un token desconocido o anulado no revela nada.
- `php -l` en cada archivo PHP nuevo o tocado.
- Prueba en Chrome (local):
  - registrar decisiones;
  - armar una plantilla con párrafo, tabla e imagen;
  - emitir, imprimir el A4 y descargar el lote;
  - escanear la URL del QR;
  - anular la emisión;
  - matricular en el año siguiente a un no ratificado y ver el aviso.
- Revisar que la boleta, las matrículas y el panel de bloqueos siguen funcionando igual (regresión).
