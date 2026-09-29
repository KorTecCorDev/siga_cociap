-- ============================================================
-- 067_criterios_conducta_por_anio.sql
-- Criterios de conducta VERSIONADOS POR AÑO + registro de correcciones.
--
-- CONTEXTO (28/09/2026, F6 del módulo auxiliares, decisión D14). Los criterios
-- no tenían año: eran una lista única para siempre. Cambiar o retirar uno para
-- el año siguiente habría alterado la completitud («registrados X de N») y la
-- nota de todos los bimestres ya cerrados. Desde aquí cada año tiene SUS
-- criterios; las respuestas ya apuntan al criterio por id, así que el pasado
-- queda intacto.
--
-- Reglas (decisiones del usuario, ver docs/modulos/auxiliares.md §7 F6):
--   - Sin respuestas en ese año: edición completa (agregar, retirar, reordenar,
--     nivel). Con respuestas: SOLO corregir la redacción, y se registra quién
--     y cuándo (`modificado_en`, `modificado_por`).
--   - Un año nuevo recibe sus criterios copiando los del anterior (botón).
--
-- SEMBRADO DEL AÑO: se toma del DATO, no de la fecha: el año de las respuestas
-- del criterio (vía su periodo). Un criterio sin respuestas cae al año activo.
--
-- Idempotente: cada columna y restricción se añade solo si no existe, y el
-- sembrado solo toca filas sin año.
-- Ejecutar después de 066_auxiliar_secciones.sql.
-- ============================================================

-- 1) Columna anio_id (NULL mientras se siembra).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'criterios_conducta'
                  AND COLUMN_NAME = 'anio_id');
SET @sql := IF(@existe = 0,
    'ALTER TABLE criterios_conducta ADD COLUMN anio_id SMALLINT UNSIGNED NULL AFTER id',
    'SELECT "067: anio_id ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Columnas de la corrección de redacción.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'criterios_conducta'
                  AND COLUMN_NAME = 'modificado_en');
SET @sql := IF(@existe = 0,
    'ALTER TABLE criterios_conducta
        ADD COLUMN modificado_en  DATETIME     NULL DEFAULT NULL AFTER creado_en,
        ADD COLUMN modificado_por INT UNSIGNED NULL DEFAULT NULL AFTER modificado_en',
    'SELECT "067: modificado_en ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Sembrado: el año de sus respuestas; si no tiene, el año activo.
UPDATE criterios_conducta k
   SET k.anio_id = COALESCE(
        (SELECT MIN(p.anio_id)
           FROM conducta_respuestas r
           INNER JOIN periodos p ON p.id = r.periodo_id
          WHERE r.criterio_id = k.id),
        (SELECT a.id FROM anios_academicos a WHERE a.estado = 'activo' ORDER BY a.anio LIMIT 1))
 WHERE k.anio_id IS NULL;

-- 4) anio_id obligatorio, con índice y FK. Si el sembrado dejó alguna fila sin
--    año (no había año activo), el ALTER falla y lo dice: no se fuerza nada.
SET @nulos := (SELECT COUNT(*) FROM criterios_conducta WHERE anio_id IS NULL);
SET @sql := IF(@nulos = 0,
    'ALTER TABLE criterios_conducta MODIFY anio_id SMALLINT UNSIGNED NOT NULL',
    'SELECT "067: HAY CRITERIOS SIN AÑO, revisar antes de seguir" AS error');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'criterios_conducta'
                  AND CONSTRAINT_NAME = 'criterios_conducta_ibfk_3');
SET @sql := IF(@existe = 0,
    'ALTER TABLE criterios_conducta
        ADD KEY idx_anio (anio_id),
        ADD CONSTRAINT criterios_conducta_ibfk_3 FOREIGN KEY (anio_id) REFERENCES anios_academicos (id),
        ADD KEY idx_modificado_por (modificado_por),
        ADD CONSTRAINT criterios_conducta_ibfk_4 FOREIGN KEY (modificado_por) REFERENCES usuarios (id)',
    'SELECT "067: las FK ya existen, no se tocan" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Verificación: ningún criterio sin año; vigentes por año.
SELECT anio_id, COUNT(*) AS criterios, SUM(eliminado_en IS NULL) AS vigentes
  FROM criterios_conducta
 GROUP BY anio_id;
