-- ============================================================
-- 062_notas_externas_conclusion.sql
-- La nota del colegio de origen guarda su CONCLUSION DESCRIPTIVA.
--
-- POR QUE (pedido del usuario, 22/09/2026)
--   El Informe de Progreso del colegio anterior trae, junto al literal, la
--   conclusion descriptiva del docente de alla. Hasta hoy se transcribia el
--   literal y esa frase se perdia.
--
--   ES OPCIONAL PARA LOS CUATRO LITERALES (AD, A, B y C) y NUNCA bloquea el
--   guardado: aqui no rige la obligatoriedad por nivel del COCIAP (primaria
--   en B y C, secundaria en C). El motivo es que NO es una evaluacion
--   nuestra: se transcribe lo que el otro colegio emitio, y si su informe no
--   la trae no se puede inventar.
--
--   Las filas ya registradas quedan en NULL a proposito: no la trajimos.
--
-- Idempotente: la columna se anade solo si no existe, leyendo
-- information_schema y ejecutando con una sentencia preparada (mismo patron
-- que la 059 y la 060; MariaDB 10.4 no tiene ADD COLUMN IF NOT EXISTS
-- fiable en todos los contextos).
-- ============================================================

SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE notas_externas
             ADD COLUMN conclusion_descriptiva TEXT NULL AFTER nota_literal',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'notas_externas'
      AND COLUMN_NAME  = 'conclusion_descriptiva'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
