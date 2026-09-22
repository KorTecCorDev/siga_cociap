# Infraestructura: producción, seguridad y despliegue

> Extraído VERBATIM de CLAUDE.md el 03/07/2026 (fase 1 de la red de documentación).
> Los invariantes globales y la tabla de enrutamiento viven en CLAUDE.md.

## Seguridad y estado REAL de producción (sesión 8 — endurecimiento)

> Esta sección refleja cómo quedó realmente el despliegue. (El plan original
> pre-despliegue, con subdominio y docroot a `public/`, quedó supersedido y se
> eliminó el 03/07/2026; sus datos aún útiles están en "Notas de despliegue".)

### Despliegue real (Hostinger)
- **Dominio:** `sigacociap.net` (se descartó el subdominio).
- **Ruta en servidor:** `~/domains/sigacociap.net/public_html` (es el repo; clon shallow → "grafted").
- **Document root:** `public_html` (NO `public/`). El `.htaccess` raíz reescribe todo a `public/`.
- **SSH:** `ssh -p 65002 u761410128@89.116.115.116`.
- **BD:** `u761410128_siga_cociap` / usuario `u761410128_ktcdev` (contraseña rotada).
- **Scripts de mantenimiento por SSH:** la sesión abre en `~`, así que **hay que entrar
  primero al repo** o `php database/...` responde `Could not open input file`:
  `cd ~/domains/sigacociap.net/public_html && php database/<script>.php`.
  El **PHP del CLI es 8.3.30** (verificado el 11/08/2026), o sea que soporta lo mismo que
  el sitio: no hace falta invocar un binario versionado tipo `php8.2`.

### El auto-deploy BORRA todo lo no versionado (CRÍTICO)
El Git auto-deploy de Hostinger hace un **checkout limpio** en cada push: elimina
archivos en `.gitignore` y carpetas no rastreadas. Por eso **todo secreto o archivo
subido debe vivir FUERA del repo** (`~/...`), nunca bajo `public_html/`.

### Credenciales de BD — fuera del repo
- Viven en `~/siga_secrets/database.php` (array de conexión, `chmod 600`).
- `config/database.php` es un **cargador versionado SIN secretos**: si existe el archivo
  externo lo usa, si no cae al fallback de XAMPP local. Ya NO se gitignora.

### Firmas/sello del Director EBR — fuera del repo
- Se guardan en `~/siga_uploads/firmas/` (config `firmas_path`, env-aware por `is_dir`).
  El directorio externo debe existir ANTES de subir (si no, cae al fallback local).
- Se sirven por la ruta pública `GET /firmas/{archivo}` (`FirmaController`, valida el
  nombre contra path traversal). La subida guarda en BD `firmas/{archivo}`.
- Ya NO se usa `public/assets/img/firmas/`.

### config/app.php — valores env-aware (no fijos)
- `debug`: `true` solo en hosts locales/privados (localhost, 127.*, 192.168.*, 10.*);
  `false` en producción y ante un `Host` inyectado (default seguro OFF).
- `app_url`: `https://sigacociap.net` si el host es `sigacociap.net`; `''` en local.
- `firmas_path`: `~/siga_uploads/firmas` en prod; `storage/firmas` en local.
- El manejo de errores se cablea en `public/index.php` según `debug`:
  producción → `display_errors=0` + `log_errors=1`; local → `display_errors=1`.

### .htaccess raíz — endurecido
- **Fuerza HTTPS** (301, loop-safe con `X-Forwarded-Proto` / puerto 443).
- **Cabeceras** (en `<IfModule mod_headers.c>`, que LiteSpeed sí procesa): HSTS,
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- **Niega** acceso directo a `.git`, carpetas internas (app/core/config/database/
  resources/storage) y extensiones sensibles (sql/md/log/lock/sh/yml/dist).

### Sesión endurecida (`core/Session.php`)
Cookie `Secure` (solo bajo HTTPS, detectado), `HttpOnly`, `SameSite=Lax`;
`session.use_strict_mode` + `use_only_cookies`. El login regenera el ID (anti-fijación).

### Vistas públicas
- **Rate limiting** en `POST /boleta-publica/consultar`: `Core\Throttle` (contadores
  en `storage/throttle/`), máx 15 intentos por IP cada 5 min → responde 429.
- **noindex** en layouts `digital` y `print` + `public/robots.txt` (`Disallow: /`).

### QR — TODO local
Todo el QR usa la librería local `qrcode.min.js` (boleta digital, footer A4, hoja de
códigos). **NO se usa `chart.googleapis.com` en ningún lado**; no se filtran códigos a
terceros. (Las menciones a Google Charts en este documento quedaron obsoletas.)

### 🔴 Datos personales servidos desde `public/` (17/09/2026)
`public/matriculados.csv` (**528 estudiantes**: DNI, nombre, DNI y nombre del apoderado,
domicilio y celular) y `public/hash.php` (una contraseña en texto plano, que imprime su
bcrypt al abrirlo) estaban **versionados**, así que el auto-deploy los publicaba bajo el
document root. La lista de extensiones negadas del `.htaccess` raíz no incluía `.csv`, y
`hash.php` no tenía regla propia: se servían al público.

- Los dos salen del repo (`git rm --cached`) y entran al `.gitignore`. El auto-deploy borra
  lo no versionado, así que el merge a `main` los retira del servidor — pero **conviene
  borrarlos a mano antes**, porque la exposición es inmediata.
- El `.htaccess` **raíz y el de `public/`** niegan ahora `.csv` y los scripts de uso único
  (`hash.php`, `import_matriculas.php`). **La app no genera ni consume `.csv`**
  (comprobado), así que no rompe nada. Medido en local: `/matriculados.csv` y `/hash.php`
  dan **403**, y `css`, `robots.txt` y el front controller siguen en 200.
- La contraseña filtrada **no coincide con ninguno de los 40 usuarios** de la copia local
  (comprobado con `password_verify`). Aun así no debe reutilizarse: está en el historial.
- ⚠️ **El repositorio de GitHub era PÚBLICO**, así que ambos archivos son legibles en el
  historial aunque se borren de `HEAD`. Poner el repo en privado corta esa vía; reescribir
  el historial es una decisión aparte.

### Seguridad — estado y pendientes
Las fases 3 y 4 del endurecimiento están CERRADAS y en prod (interpolaciones SQL
auditadas, entradas públicas validadas, subida de imágenes reforzada, `error_log`
fuera del docroot vía config `log_path`, permisos revisados). Los pendientes
vivos de seguridad (CSP, limpieza de `.gitignore`, destino de `AuthMiddleware`)
se rastrean en `docs/ESTADO.md`.

### Notas de despliegue (rescatadas del plan original, siguen vigentes)
- **Dumps de BD:** exportar SIEMPRE desde la BD viva (utf8mb4, con datos); NO usar
  los backups del repositorio (desactualizados). No volver a correr `migrations/`
  sobre un dump importado (ya las incluye).
- **Versión PHP:** el código exige PHP 8.2+ (`match`, `str_starts_with`, `never`);
  confirmar/fijar en hPanel si se recrea el hosting.
- **Impresora objetivo del colegio:** RICOH MP4054 PCL6 — probar la boleta A4 ahí
  ante cualquier cambio de layout de impresión.

## Orden de ejecución SQL (setup desde cero)
```
1. migrations/000_crear_base_de_datos.sql
2. migrations/siga_cociap.sql
3. migrations/002_criterios_calificaciones.sql
4. migrations/003_bloqueos_competencia.sql
5. migrations/004_limpiar_datos_semilla.sql
6. migrations/005_boletas_publicas.sql
7. migrations/006_soft_delete_criterios.sql
8. migrations/007_director_ebr_historial.sql
9. migrations/008_director_ebr_imagenes.sql
   (… 009-017 según database/migrations/)
10. migrations/018_criterios_descripcion.sql  ← criterios: descripción opcional
11. migrations/019_transversales_docente.sql  ← transversales por docente + cierre del tutor
12. migrations/020_bloqueos_origen.sql        ← origen del bloqueo (docente/cierre)
    (… 021-032 según database/migrations/: snapshot mérito 023, rectificaciones 024,
    criterios confirmado 026, token tracking 028, limpieza bloques falsos 030,
    sesiones cruzadas 031, área tutoría 032)
    (… 033-042 según database/migrations/: anti-fantasma 033, purga/consolidación
    docentes 034/037, Ética y Valores 035/036, traslado entrada 038,
    codigo_siagie secundaria 039 y primaria 041, notas autorizadas SIAGIE 040,
    calificación extraordinaria 042)
```
Estado de aplicación LOCAL/PROD: ver `docs/ESTADO.md`.

