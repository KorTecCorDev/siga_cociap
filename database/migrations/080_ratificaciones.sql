-- ============================================================
-- 080_ratificaciones.sql
-- RATIFICACIÓN DE MATRÍCULA (módulo nuevo, plan del 13/10/2026; decisiones
-- cerradas el 14/10/2026). Ver docs/modulos/ratificaciones.md.
--
-- Dirección decide, al final del año, qué estudiantes continúan el año
-- siguiente. Admin, RA y las dos secretarías REGISTRAN esa decisión; el sistema
-- emite un documento A4 con QR de autenticidad. Cinco tablas nuevas:
--
--   ratificacion_plantillas  cuerpo del documento por AÑO y TIPO, versionado.
--                            Emitir CONGELA la versión (`congelada_en`); editar
--                            una congelada crea la versión siguiente.
--   ratificacion_bloques     bloques de una plantilla: párrafo, tabla, imagen o
--                            salto de página. Sin HTML guardado (enfoque D).
--   ratificacion_motivos     catálogo de motivos de NO ratificación por año, con
--                            el párrafo oficial que redacta Dirección.
--   ratificaciones           la DECISIÓN de cada matrícula. Sin fila = pendiente
--                            (el olvido nunca produce una carta negativa).
--   ratificacion_emisiones   cada documento emitido: copia el texto del motivo
--                            y del detalle (editar el catálogo no reescribe lo
--                            entregado) y lleva su PROPIO token de QR (NUNCA
--                            `matriculas.token_acceso`, que abre la boleta).
--
-- Integridad en la BD, no solo en el modelo:
--   · `no_ratificado` EXIGE motivo; `ratificado` NO lleva motivo ni detalle
--     (chk_ratif_decision_motivo).
--   · A lo sumo UNA emisión VIGENTE por decisión: `vigente` es 1 o NULL y entra
--     en un UNIQUE (NULL no choca), como `asistencia_motivos.es_principal`.
--     El CHECK la ata a `anulado_en` para que no diverjan.
--   · Cada bloque trae el dato de su tipo (chk_ratb_tipo_dato); la tabla, JSON
--     válido (chk_ratb_tabla_json).
--
-- NO TOCA DATOS NI TABLAS EXISTENTES: solo CREA. Se revierte con DROP de las
-- cinco (en orden inverso por las FK). Idempotente.
-- Ejecutar después de 079_rectificaciones_registros.sql.
-- ⚠️ En producción: aplicar a mano ANTES del merge a main y crear
-- `~/siga_uploads/ratificacion/` (imágenes de las plantillas).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ratificacion_plantillas (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    anio_id       SMALLINT UNSIGNED NOT NULL,
    tipo          ENUM('ratificado','no_ratificado') NOT NULL,
    version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    titulo        VARCHAR(150)      NOT NULL,
    congelada_en  DATETIME          NULL DEFAULT NULL,
    creado_por    INT UNSIGNED      NOT NULL,
    creado_en     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratp_anio_tipo_version (anio_id, tipo, version),
    KEY idx_ratp_usuario (creado_por),
    CONSTRAINT fk_ratp_anio    FOREIGN KEY (anio_id)    REFERENCES anios_academicos (id),
    CONSTRAINT fk_ratp_usuario FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratificacion_bloques (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    plantilla_id    INT UNSIGNED      NOT NULL,
    orden           SMALLINT UNSIGNED NOT NULL,
    tipo            ENUM('parrafo','tabla','imagen','salto') NOT NULL,
    texto           TEXT              NULL DEFAULT NULL,
    tabla_json      LONGTEXT          NULL DEFAULT NULL,
    imagen_archivo  VARCHAR(100)      NULL DEFAULT NULL,
    imagen_tamano   ENUM('pequena','media','completa') NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratb_plantilla_orden (plantilla_id, orden),
    CONSTRAINT fk_ratb_plantilla FOREIGN KEY (plantilla_id) REFERENCES ratificacion_plantillas (id),
    CONSTRAINT chk_ratb_tabla_json CHECK (tabla_json IS NULL OR JSON_VALID(tabla_json)),
    CONSTRAINT chk_ratb_tipo_dato CHECK (
           (tipo = 'parrafo' AND texto IS NOT NULL)
        OR (tipo = 'tabla'   AND tabla_json IS NOT NULL)
        OR (tipo = 'imagen'  AND imagen_archivo IS NOT NULL AND imagen_tamano IS NOT NULL)
        OR  tipo = 'salto'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratificacion_motivos (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    anio_id        SMALLINT UNSIGNED NOT NULL,
    nombre         VARCHAR(100)      NOT NULL,
    texto_oficial  TEXT              NOT NULL,
    activo         TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratm_anio_nombre (anio_id, nombre),
    CONSTRAINT fk_ratm_anio FOREIGN KEY (anio_id) REFERENCES anios_academicos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratificaciones (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    matricula_id    INT UNSIGNED  NOT NULL,
    decision        ENUM('ratificado','no_ratificado') NOT NULL,
    motivo_id       INT UNSIGNED  NULL DEFAULT NULL,
    detalle         VARCHAR(500)  NULL DEFAULT NULL,
    registrado_por  INT UNSIGNED  NOT NULL,
    registrado_en   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_por INT UNSIGNED  NULL DEFAULT NULL,
    actualizado_en  DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rat_matricula (matricula_id),
    KEY idx_rat_motivo (motivo_id),
    KEY idx_rat_registrado (registrado_por),
    KEY idx_rat_actualizado (actualizado_por),
    CONSTRAINT fk_rat_matricula   FOREIGN KEY (matricula_id)    REFERENCES matriculas (id),
    CONSTRAINT fk_rat_motivo      FOREIGN KEY (motivo_id)       REFERENCES ratificacion_motivos (id),
    CONSTRAINT fk_rat_registrado  FOREIGN KEY (registrado_por)  REFERENCES usuarios (id),
    CONSTRAINT fk_rat_actualizado FOREIGN KEY (actualizado_por) REFERENCES usuarios (id),
    CONSTRAINT chk_ratif_decision_motivo CHECK (
           (decision = 'no_ratificado' AND motivo_id IS NOT NULL)
        OR (decision = 'ratificado'    AND motivo_id IS NULL AND detalle IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratificacion_emisiones (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ratificacion_id  INT UNSIGNED NOT NULL,
    plantilla_id     INT UNSIGNED NOT NULL,
    decision         ENUM('ratificado','no_ratificado') NOT NULL,
    motivo_texto     TEXT         NULL DEFAULT NULL,
    detalle_texto    VARCHAR(500) NULL DEFAULT NULL,
    token            CHAR(32)     NOT NULL,
    vigente          TINYINT(1)   NULL DEFAULT 1,
    emitido_por      INT UNSIGNED NOT NULL,
    emitido_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_por      INT UNSIGNED NULL DEFAULT NULL,
    anulado_en       DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_token (token),
    UNIQUE KEY uq_rate_vigente (ratificacion_id, vigente),
    KEY idx_rate_plantilla (plantilla_id),
    KEY idx_rate_emitido (emitido_por),
    KEY idx_rate_anulado (anulado_por),
    CONSTRAINT fk_rate_ratificacion FOREIGN KEY (ratificacion_id) REFERENCES ratificaciones (id),
    CONSTRAINT fk_rate_plantilla    FOREIGN KEY (plantilla_id)    REFERENCES ratificacion_plantillas (id),
    CONSTRAINT fk_rate_emitido      FOREIGN KEY (emitido_por)     REFERENCES usuarios (id),
    CONSTRAINT fk_rate_anulado      FOREIGN KEY (anulado_por)     REFERENCES usuarios (id),
    CONSTRAINT chk_rate_vigente CHECK (
           (vigente = 1     AND anulado_en IS NULL     AND anulado_por IS NULL)
        OR (vigente IS NULL AND anulado_en IS NOT NULL AND anulado_por IS NOT NULL)
    ),
    CONSTRAINT chk_rate_motivo CHECK (decision = 'ratificado' OR motivo_texto IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cierre con SHOW y no SELECT FROM information_schema: phpMyAdmin toma la base
-- de la ULTIMA sentencia SELECT del archivo y corre TODO el import en ella (#1044).
-- Ver docs/infraestructura.md, "Migraciones: reglas para que importen en phpMyAdmin".
SHOW TABLES LIKE 'ratificacion%';
