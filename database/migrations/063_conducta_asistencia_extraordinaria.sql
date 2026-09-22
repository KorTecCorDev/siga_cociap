-- ============================================================
-- 063_conducta_asistencia_extraordinaria.sql
-- Conducta e inasistencias EXTRAORDINARIAS de un bimestre CERRADO.
--
-- POR QUE (pedido del usuario, 22/09/2026)
--   La calificacion extraordinaria completa las NOTAS de un bimestre cerrado
--   que no llegaron a ingresarse, y esas notas salen en la boleta. Pero la
--   boleta tambien lleva conducta e inasistencias por bimestre, y en un
--   bimestre cerrado no habia via para registrarlas: sus tres pantallas
--   exigen periodo editable y seccion sin cierre. La boleta salia con notas
--   y un guion en conducta y asistencia.
--
--   Registro Academico las ingresa ahora desde el LOTE de extraordinarias,
--   como dos filas mas de la grilla. Solo donde el alumno NO tiene registro:
--   la via extraordinaria nunca pisa un dato existente.
--
-- QUE AGREGA (en `inasistencias` y en `calificaciones_conducta`)
--   extraordinaria         TINYINT(1) NOT NULL DEFAULT 0
--       1 = la fila la creo Registro Academico por la via extraordinaria.
--       Mismo patron que `calificaciones.extraordinaria` (migracion 042).
--   motivo_extraordinaria  TEXT NULL
--       El motivo del lote (obligatorio en la pantalla). NULL en las filas
--       del flujo normal. Mismo tipo que `rectificaciones_calificacion.motivo`.
--
--   Quien y cuando ya estan en `registrado_por` / `registrado_en`.
--
-- CONDUCTA = LITERAL DIRECTO en `calificaciones_conducta.literal`
--   (decision del usuario). Es la columna que la boleta ya lee cuando no hay
--   matriz de criterios (`ConductaModel::componerLiteral`), y es como se
--   registro todo el I Bimestre. No hace falta tocar la boleta.
--
-- Las filas existentes quedan con extraordinaria = 0 (lo son del flujo normal).
--
-- Idempotente: cada columna se anade solo si no existe (mismo patron que la
-- 059, la 060 y la 062).
-- ============================================================

-- ── inasistencias.extraordinaria ────────────────────────────────
SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE inasistencias
             ADD COLUMN extraordinaria TINYINT(1) NOT NULL DEFAULT 0 AFTER tardanzas_justificadas',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'inasistencias'
      AND COLUMN_NAME  = 'extraordinaria'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── inasistencias.motivo_extraordinaria ─────────────────────────
SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE inasistencias
             ADD COLUMN motivo_extraordinaria TEXT NULL AFTER extraordinaria',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'inasistencias'
      AND COLUMN_NAME  = 'motivo_extraordinaria'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── calificaciones_conducta.extraordinaria ──────────────────────
SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE calificaciones_conducta
             ADD COLUMN extraordinaria TINYINT(1) NOT NULL DEFAULT 0 AFTER nota_tutor',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'calificaciones_conducta'
      AND COLUMN_NAME  = 'extraordinaria'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── calificaciones_conducta.motivo_extraordinaria ───────────────
SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE calificaciones_conducta
             ADD COLUMN motivo_extraordinaria TEXT NULL AFTER extraordinaria',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'calificaciones_conducta'
      AND COLUMN_NAME  = 'motivo_extraordinaria'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
