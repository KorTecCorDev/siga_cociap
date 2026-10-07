-- ============================================================
-- 075_cambio_seccion.sql
-- CAMBIO DE SECCIÓN: el evento (con su R.D.) y el ARCHIVO de lo retirado.
--
-- CONTEXTO (07/10/2026, plan aprobado por el usuario; ver
-- docs/modulos/cambio-seccion.md). Un cambio de sección conserva la MISMA
-- matrícula (`UPDATE matriculas.seccion_id`). Lo que es de la matrícula viaja
-- solo; lo que es de la CARGA no puede viajar sin cambiar de dueño:
--
--   - Bimestres CERRADOS: las notas bloqueadas se QUEDAN en la carga de origen
--     (boleta, acta y mérito ya las leen de allí). No se archivan ni se tocan.
--   - Bimestre ABIERTO: no se toma NINGUNA nota (ni bloqueada). Lo vivo del
--     estudiante en las cargas de origen se COPIA aquí y se RETIRA de las tablas
--     vivas (si quedara, el bloqueo de origen lo filtraría a la boleta).
--     Destino califica de cero.
--   - Reversión: lo que registró destino se archiva con lado = 'destino' y lo
--     de origen se restaura. Nada se pierde.
--
-- `periodo_id` = desde qué bimestre rige el destino. Lo lee el PUNTO ÚNICO
-- `CambioSeccionModel::sqlSeccionDelPeriodo` (sección en la que se cursó cada
-- bimestre), nunca una consulta a mano.
--
-- La R.D. se emite FUERA de SIGA: aquí solo se registran su número y su fecha.
-- NUMERACIÓN COMPARTIDA con las constancias de traslado (decisión del usuario,
-- 07/10/2026): un correlativo por año académico, formato N° 000-{AÑO}-CAVVG-DA
-- (`TrasladoModel::formatearNumero`). Se admite CUALQUIER número libre; está
-- ocupado si lo usa una constancia 'vigente' o un cambio 'vigente' del mismo
-- año. Un cambio 'revertido' LIBERA su número (como una constancia anulada),
-- por eso NO hay UNIQUE sobre (anio_id, rd_correlativo): la unicidad la valida
-- el PUNTO ÚNICO `TrasladoModel::correlativoDisponible`, que mira las dos tablas.
--
-- Solo esquema. Idempotente (CREATE TABLE IF NOT EXISTS).
-- Ejecutar después de 074_borradores_formulario.sql.
-- ============================================================

SET NAMES utf8mb4;

-- 1) El evento.
CREATE TABLE IF NOT EXISTS cambios_seccion (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id        INT UNSIGNED      NOT NULL,
    anio_id             SMALLINT UNSIGNED NOT NULL,
    periodo_id          SMALLINT UNSIGNED NOT NULL,
    seccion_origen_id   SMALLINT UNSIGNED NOT NULL,
    seccion_destino_id  SMALLINT UNSIGNED NOT NULL,
    rd_correlativo      SMALLINT UNSIGNED NOT NULL,
    rd_numero           VARCHAR(60)       NOT NULL,
    rd_fecha            DATE              NOT NULL,
    motivo              TEXT              NOT NULL,
    estado              ENUM('vigente','revertido') NOT NULL DEFAULT 'vigente',
    movido_por          INT UNSIGNED      NOT NULL,
    movido_en           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revertido_por       INT UNSIGNED      NULL DEFAULT NULL,
    revertido_en        DATETIME          NULL DEFAULT NULL,
    motivo_reversion    TEXT              NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_matricula_estado (matricula_id, estado),
    KEY idx_periodo (periodo_id),
    KEY idx_rd (anio_id, rd_correlativo, estado),
    KEY idx_origen  (seccion_origen_id),
    KEY idx_destino (seccion_destino_id),
    CONSTRAINT fk_cs_matricula FOREIGN KEY (matricula_id)       REFERENCES matriculas (id),
    CONSTRAINT fk_cs_anio      FOREIGN KEY (anio_id)            REFERENCES anios_academicos (id),
    CONSTRAINT fk_cs_periodo   FOREIGN KEY (periodo_id)         REFERENCES periodos (id),
    CONSTRAINT fk_cs_origen    FOREIGN KEY (seccion_origen_id)  REFERENCES secciones (id),
    CONSTRAINT fk_cs_destino   FOREIGN KEY (seccion_destino_id) REFERENCES secciones (id),
    CONSTRAINT fk_cs_movido    FOREIGN KEY (movido_por)         REFERENCES usuarios (id),
    CONSTRAINT fk_cs_revertido FOREIGN KEY (revertido_por)      REFERENCES usuarios (id),
    CONSTRAINT chk_cs_secciones_distintas CHECK (seccion_origen_id <> seccion_destino_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Archivo: promedios por competencia (fila completa de `calificaciones`).
CREATE TABLE IF NOT EXISTS cambios_seccion_competencia (
    id                      INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    cambio_id               INT UNSIGNED      NOT NULL,
    lado                    ENUM('origen','destino') NOT NULL,
    carga_id                INT UNSIGNED      NOT NULL,
    competencia_id          SMALLINT UNSIGNED NOT NULL,
    periodo_id              SMALLINT UNSIGNED NOT NULL,
    nota_numerica           TINYINT UNSIGNED  NOT NULL,
    conclusion_descriptiva  TEXT              NULL,
    extraordinaria          TINYINT(1)        NOT NULL DEFAULT 0,
    registrado_en           DATETIME          NOT NULL,
    modificado_en           DATETIME          NULL,
    registrado_por          INT UNSIGNED      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_csc (cambio_id, lado, carga_id, competencia_id, periodo_id),
    KEY idx_carga (carga_id),
    CONSTRAINT fk_csc_cambio FOREIGN KEY (cambio_id) REFERENCES cambios_seccion (id) ON DELETE CASCADE,
    CONSTRAINT fk_csc_carga  FOREIGN KEY (carga_id)  REFERENCES cargas_academicas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Archivo: notas por criterio. El criterio va DENORMALIZADO (nombre, carga,
--    competencia, periodo): si luego se borra, la referencia se sigue leyendo y
--    la restauración lo omite con aviso.
CREATE TABLE IF NOT EXISTS cambios_seccion_criterio (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    cambio_id         INT UNSIGNED      NOT NULL,
    lado              ENUM('origen','destino') NOT NULL,
    criterio_id       INT UNSIGNED      NULL,
    carga_id          INT UNSIGNED      NOT NULL,
    competencia_id    SMALLINT UNSIGNED NOT NULL,
    periodo_id        SMALLINT UNSIGNED NOT NULL,
    criterio_nombre   VARCHAR(120)      NOT NULL,
    criterio_orden    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    nota              TINYINT UNSIGNED  NOT NULL,
    registrado_en     DATETIME          NOT NULL,
    modificado_en     DATETIME          NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cscr (cambio_id, lado, criterio_id),
    KEY idx_carga (carga_id),
    CONSTRAINT fk_cscr_cambio   FOREIGN KEY (cambio_id)   REFERENCES cambios_seccion (id) ON DELETE CASCADE,
    CONSTRAINT fk_cscr_criterio FOREIGN KEY (criterio_id) REFERENCES criterios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Archivo: omisiones por criterio (mismo denormalizado que el criterio).
CREATE TABLE IF NOT EXISTS cambios_seccion_omision (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    cambio_id         INT UNSIGNED      NOT NULL,
    lado              ENUM('origen','destino') NOT NULL,
    criterio_id       INT UNSIGNED      NULL,
    carga_id          INT UNSIGNED      NOT NULL,
    competencia_id    SMALLINT UNSIGNED NOT NULL,
    periodo_id        SMALLINT UNSIGNED NOT NULL,
    criterio_nombre   VARCHAR(120)      NOT NULL,
    motivo            ENUM('ausencia_injustificada','ausencia_justificada','abandono','no_aplico') NOT NULL,
    registrado_en     DATETIME          NOT NULL,
    registrado_por    INT UNSIGNED      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cso (cambio_id, lado, criterio_id),
    KEY idx_carga (carga_id),
    CONSTRAINT fk_cso_cambio   FOREIGN KEY (cambio_id)   REFERENCES cambios_seccion (id) ON DELETE CASCADE,
    CONSTRAINT fk_cso_criterio FOREIGN KEY (criterio_id) REFERENCES criterios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Verificación.
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'cambios_seccion%';
