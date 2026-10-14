# Ratificación de matrícula

> **Estado (13/10/2026): PLAN APROBADO, SIN IMPLEMENTAR.** Implementación prevista en la
> siguiente sesión, en `dev`. **No desplegar antes de cerrar el III y abrir el IV (18-19/10/2026).**
> Al implementar, esta cabecera y el plan de abajo pasan a describir lo construido.


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

## Fases
1. **Migración 080** + modelos + decisiones por sección + catálogo de motivos.
2. **Plantillas por bloques** (editor, imágenes, versión, duplicar del año anterior, vista previa).
3. **Emisión**: documento individual A4, anulación, QR y página de verificación.
4. **Lote por sección** (PDF/ZIP) + **aviso al matricular**.
5. **Documentación**:
   - `docs/modulos/ratificaciones.md` (nuevo);
   - fila nueva en la tabla de CLAUDE.md (módulo nuevo);
   - `docs/ESTADO.md` (migración 080 pendiente en prod; se AVISA, no se ejecuta).

Commits separados por tema, en español y sin tildes.

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
