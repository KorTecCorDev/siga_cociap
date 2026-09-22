-- ============================================================
-- 060_comunicados.sql
-- Historial de COMUNICADOS enviados (modulo de notificaciones, 058).
--
-- EL PROBLEMA
--   La 058 guarda una fila de `notificaciones` POR DESTINATARIO y nada
--   mas. No habia forma fiable de saber que filas son "el mismo
--   comunicado" (agrupar por emisor + titulo + fecha se parte si el envio
--   cruza el cambio de segundo) ni a que grupos se mando.
--
-- EL ARREGLO
--   * `comunicados`: una fila por ENVIO, con los destinos elegidos.
--     `destinos` son claves separadas por coma de
--     NotificacionModel::DESTINOS (docentes, seccion, direccion,
--     administrativo): lista cerrada en PHP, igual que `tipo` en la 058.
--   * `notificaciones.comunicado_id`: enlaza cada copia con su envio.
--     NULL en los avisos del sistema y en los comunicados anteriores a
--     esta migracion (0 en local y en produccion el 16/09/2026).
--
-- NO modifica la 058, que ya esta aplicada en local.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS y, para la columna, lectura de
-- information_schema + sentencia preparada (mismo patron que la 059).
-- Re-ejecutable sin dano.
-- ============================================================

CREATE TABLE IF NOT EXISTS comunicados (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    emisor_id   INT UNSIGNED NOT NULL COMMENT 'quien lo redacto (admin / registro academico)',
    titulo      VARCHAR(150) NOT NULL,
    mensaje     TEXT         NOT NULL,
    destinos    VARCHAR(100) NOT NULL COMMENT 'claves de NotificacionModel::DESTINOS separadas por coma',
    seccion_id  SMALLINT UNSIGNED DEFAULT NULL COMMENT 'solo si destinos incluye seccion',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fecha (created_at),
    KEY idx_emisor (emisor_id),
    KEY idx_seccion (seccion_id),
    CONSTRAINT fk_comunicado_emisor  FOREIGN KEY (emisor_id)  REFERENCES usuarios (id),
    CONSTRAINT fk_comunicado_seccion FOREIGN KEY (seccion_id) REFERENCES secciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paso 2: la columna de enlace, SOLO si no existe ya.
SET @add_col := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE notificaciones
             ADD COLUMN comunicado_id INT UNSIGNED DEFAULT NULL
                 COMMENT ''envio al que pertenece; NULL si la genero el sistema''
                 AFTER emisor_id,
             ADD KEY idx_comunicado (comunicado_id),
             ADD CONSTRAINT fk_notif_comunicado
                 FOREIGN KEY (comunicado_id) REFERENCES comunicados (id)',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'notificaciones'
      AND COLUMN_NAME  = 'comunicado_id'
);
PREPARE stmt FROM @add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
