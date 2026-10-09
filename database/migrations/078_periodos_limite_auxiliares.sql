-- ============================================================
-- 078_periodos_limite_auxiliares.sql
-- FECHA LÍMITE PROPIA DE LOS AUXILIARES (F4 del plan de bloqueos, 09/10/2026).
--
-- CONTEXTO (decisiones del usuario, 09/10/2026; deroga la D2 del 04/08, «una sola
-- fecha»). El tutor cierra la conducta (etapa 2) sobre lo que registró el
-- auxiliar (etapa 1). Con una sola fecha, un auxiliar que bloquea a última hora
-- deja al tutor sin tiempo. Las fechas van POR ROL:
--
--   periodos.limite_notas        DOCENTES: académicas, TIC/GAMA y todo lo del
--                                tutor. Sin plazo extra para el tutor.
--   periodos.limite_auxiliares   AUXILIARES (y RA/admin por la vía normal):
--                                conducta etapa 1 y asistencia. ANTERIOR.
--
-- NULL = vale `limite_notas`: hasta que alguien la fije, NADA cambia. Regla de
-- lectura en un solo sitio: `EdicionPeriodoModel::limiteDe()`. Al guardar se
-- valida `fecha_fin ≤ limite_auxiliares ≤ limite_notas` (la asistencia solo se
-- bloquea desde el último día del bimestre).
--
-- NO TOCA DATOS. Idempotente. Ejecutar después de 077_asistencia_presencias.sql.
-- ⚠️ En producción: aplicar a mano ANTES del merge a main (el código nuevo lee
-- la columna en cuanto se despliega).
-- ============================================================

SET NAMES utf8mb4;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'periodos'
                 AND COLUMN_NAME = 'limite_auxiliares');
SET @sql := IF(@falta = 1,
    'ALTER TABLE periodos ADD COLUMN limite_auxiliares DATETIME NULL DEFAULT NULL AFTER limite_notas',
    'SELECT "078: limite_auxiliares ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cierre con SHOW y no SELECT FROM information_schema: phpMyAdmin toma la base
-- de la ULTIMA sentencia SELECT del archivo y corre TODO el import en ella (#1044).
-- Ver docs/infraestructura.md, "Migraciones: reglas para que importen en phpMyAdmin".
SHOW COLUMNS FROM periodos LIKE 'limite_auxiliares';
