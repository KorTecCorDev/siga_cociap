-- ============================================================
-- 074_asistencia_incidencias_checks.sql
-- REPONE los dos CHECK de `asistencia_incidencias` que faltan en PRODUCCIÓN.
--
-- CONTEXTO (05/10/2026). En la copia de producción del 05/10/2026 la tabla no
-- tiene NINGUNO de sus dos CHECK, aunque las migraciones 069 y 070 los crean:
--
--   chk_motivo_solo_justificada  (069, dentro del CREATE TABLE)
--                                F/T NUNCA llevan motivo.
--   chk_justificada_con_motivo   (070, ALTER TABLE preparado)
--                                FJ/TJ SIEMPRE llevan motivo.
--
-- Sin los CHECK la regla solo la aplica `AsistenciaModel::marcarDia`: un INSERT
-- directo podría guardar una FJ sin motivo y la boleta la contaría como
-- justificada. Hoy hay 0 filas que los violen (copia de producción: 554 filas).
--
-- 🔴 POR QUÉ ESTA MIGRACIÓN NO USA `PREPARE` (a diferencia de las demás).
-- En el phpMyAdmin de Hostinger, `PREPARE` de un `ALTER TABLE ... ADD CONSTRAINT
-- ... CHECK` falla con «#1044 Acceso denegado ... a la base de datos
-- 'information_schema'». La primera versión de esta 074 lo hacía y falló así dos
-- veces. Es el mismo patrón de la 070, y es el CHECK que falta en producción.
-- Los PREPARE que añaden columnas o FOREIGN KEY (067, 068, 069, 073) sí funcionan.
-- Por eso aquí va un ALTER TABLE DIRECTO.
--
-- ⚠️ NO es idempotente en el sentido estricto: es UNA sola sentencia con los dos
-- CHECK, así que se crean los dos o ninguno. Re-ejecutarla da
-- «#1826 Duplicate CHECK constraint name» y NO cambia nada. No se usa
-- `ADD CONSTRAINT IF NOT EXISTS` porque solo existe en MariaDB.
--
-- NO se vuelve a correr la 070: además del CHECK recuenta y DESCONFIRMA filas.
--
-- ⚠️ EN PRODUCCIÓN: correr el PREVIEW primero. Si da algo > 0, el ALTER fallaría
-- (sin cambiar nada): corregir esas filas antes (no lo hace esta migración).
--
-- Ejecutar después de 073_retornos_grado_tramo.sql, con la base del sistema
-- seleccionada en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción). Debe dar 0 y 0. ──
--   SELECT SUM(tipo IN ('F','T')   AND motivo_id IS NOT NULL) AS viola_motivo_solo_justificada,
--          SUM(tipo IN ('FJ','TJ') AND motivo_id IS NULL)     AS viola_justificada_con_motivo
--     FROM asistencia_incidencias;

-- 1) Los dos CHECK, en UNA sola sentencia (los dos o ninguno).
ALTER TABLE asistencia_incidencias
    ADD CONSTRAINT chk_motivo_solo_justificada CHECK (motivo_id IS NULL OR tipo IN ('FJ', 'TJ')),
    ADD CONSTRAINT chk_justificada_con_motivo  CHECK (tipo IN ('F', 'T') OR motivo_id IS NOT NULL);

-- 2) Verificación (en otro envío también vale): deben salir las DOS filas.
--   SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
--     AND CONSTRAINT_TYPE = 'CHECK';
