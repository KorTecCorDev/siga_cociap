-- ============================================================
-- 074_asistencia_incidencias_checks.sql
-- REPONE los dos CHECK de `asistencia_incidencias` que faltan en PRODUCCIÓN.
--
-- CONTEXTO (05/10/2026). En la copia de producción del 05/10/2026 la tabla no
-- tiene NINGUNO de sus dos CHECK, aunque las migraciones 069 y 070 los crean:
--
--   chk_motivo_solo_justificada  (069, dentro del CREATE TABLE)
--                                F/T NUNCA llevan motivo.
--   chk_justificada_con_motivo   (070, ALTER TABLE condicional)
--                                FJ/TJ SIEMPRE llevan motivo.
--
-- La tabla se creó por otra vía (su colación es utf8mb4_general_ci, la 069 la
-- crea en utf8mb4_unicode_ci) y la 069 la encontró ya hecha. Sin los CHECK la
-- regla solo la aplica `AsistenciaModel`: un INSERT directo podría guardar una
-- FJ sin motivo y la boleta la contaría como justificada. Hoy hay 0 filas que
-- los violen (medido en la copia de producción: 554 filas).
--
-- NO se vuelve a correr la 070: además del CHECK recuenta y DESCONFIRMA filas y
-- rellena listas del día. Esta migración SOLO añade los CHECK que falten.
--
-- ⚠️ EN PRODUCCIÓN: correr el PREVIEW primero. Si da algo > 0, el ALTER fallaría:
-- detenerse y corregir esas filas antes (no las corrige esta migración).
--
-- Idempotente. Ejecutar después de 073_retornos_grado_tramo.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción). Debe dar 0 y 0. ──
--   SELECT SUM(tipo IN ('F','T')   AND motivo_id IS NOT NULL) AS viola_motivo_solo_justificada,
--          SUM(tipo IN ('FJ','TJ') AND motivo_id IS NULL)     AS viola_justificada_con_motivo
--     FROM asistencia_incidencias;

-- 1) F/T nunca llevan motivo (el de la 069).
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
                 AND CONSTRAINT_NAME = 'chk_motivo_solo_justificada');
SET @sql := IF(@falta = 1,
    'ALTER TABLE asistencia_incidencias ADD CONSTRAINT chk_motivo_solo_justificada CHECK (motivo_id IS NULL OR tipo IN (''FJ'',''TJ''))',
    'SELECT "074: chk_motivo_solo_justificada ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) FJ/TJ siempre llevan motivo (el de la 070).
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
                 AND CONSTRAINT_NAME = 'chk_justificada_con_motivo');
SET @sql := IF(@falta = 1,
    'ALTER TABLE asistencia_incidencias ADD CONSTRAINT chk_justificada_con_motivo CHECK (tipo IN (''F'',''T'') OR motivo_id IS NOT NULL)',
    'SELECT "074: chk_justificada_con_motivo ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Verificación: deben salir las DOS filas.
SELECT CONSTRAINT_NAME
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
  AND CONSTRAINT_TYPE = 'CHECK'
ORDER BY CONSTRAINT_NAME;
