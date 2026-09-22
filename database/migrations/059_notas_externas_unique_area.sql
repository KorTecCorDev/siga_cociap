-- ============================================================
-- 059_notas_externas_unique_area.sql
-- El AREA entra en la clave unica de las notas del colegio de origen.
--
-- EL PROBLEMA (medido el 10/09/2026, no es teorico)
--   La UNIQUE era (matricula_id, periodo_nombre, competencia_nombre) y el
--   alta usa ON DUPLICATE KEY UPDATE. El COCIAP evalua LA MISMA
--   competencia del MINEDU en DOS cursos distintos:
--
--     "Resuelve problemas de cantidad."
--        en Matematica  +  Taller de Razonamiento Matematico
--     "Resuelve problemas de regularidad, equivalencia y cambio."
--        en Matematica  +  Taller de Pre-Calculo
--
--   Con la clave vieja, la segunda fila PISA a la primera en silencio.
--   Afecta a 4 de los 6 estudiantes reales que llegaron con un bimestre
--   cerrado por delante (2-3 colisiones cada uno; en primaria, 0).
--
--   ⚠️ NO lo trae el importador de curricula: YA PASA HOY al teclear a
--   mano dos filas con la misma competencia en areas distintas. El
--   importador solo lo vuelve masivo y facil de reproducir.
--
-- EL ARREGLO
--   Anadir `area_nombre` a la clave. Verificado: deja 0 colisiones en los
--   6 estudiantes. La tabla es ROW_FORMAT=Dynamic y la clave ocupa ~1084
--   bytes de los 3072 permitidos, asi que cabe de sobra.
--
--   Se aplica sobre una tabla VACIA (0 filas en local y en produccion el
--   10/09/2026), asi que no hay riesgo de que un duplicado preexistente
--   impida crear el indice.
--
-- Idempotente: DROP INDEX no admite IF EXISTS de forma fiable en MariaDB
-- 10.4, asi que cada paso se decide leyendo information_schema y se
-- ejecuta con una sentencia preparada. Re-ejecutable sin dano.
-- ============================================================

-- Paso 1: quitar la clave vieja SOLO si sigue teniendo 3 columnas.
SET @drop_sql := (
    SELECT IF(
        COUNT(*) = 3,
        'ALTER TABLE notas_externas DROP INDEX uq_nota_externa',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'notas_externas'
      AND INDEX_NAME   = 'uq_nota_externa'
);
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Paso 2: crear la clave nueva SOLO si no existe ya.
SET @add_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE notas_externas
             ADD UNIQUE KEY uq_nota_externa
                 (matricula_id, periodo_nombre, area_nombre, competencia_nombre)',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'notas_externas'
      AND INDEX_NAME   = 'uq_nota_externa'
);
PREPARE stmt FROM @add_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
