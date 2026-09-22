-- ============================================================
-- 057_notas_externas_area.sql
-- Mapeo OPCIONAL de una nota del colegio de origen a un area NUESTRA.
--
-- CONTEXTO (regla del colegio, decidida el 10/09/2026)
--   Un estudiante trasladado trae el Informe de Progreso de su colegio
--   anterior. Esas notas NO entran en la boleta del COCIAP: la boleta
--   lleva solo lo cursado aqui. Son INFORMATIVAS, y su publico son los
--   docentes con carga en la seccion del estudiante, que necesitan saber
--   con que llega.
--
--   `notas_externas` ya guardaba exactamente eso (nombres en texto libre
--   + literal AD/A/B/C + colegio de origen) y la boleta nunca la leyo.
--   Lo que le faltaba no era esquema, era una superficie de lectura.
--
-- QUE ANADE ESTA MIGRACION Y POR QUE ES NULLABLE
--   `area_id` permite enlazar una nota de origen con un area de NUESTRO
--   plan, para RESALTARLE al docente las filas de su propia carga. Es
--   OPCIONAL a proposito: el plan curricular del colegio de origen no
--   tiene por que coincidir con el nuestro, y obligar a traducirlo
--   dejaria fuera lo que no tenga equivalente. Sin mapeo, la fila se
--   sigue viendo; simplemente no se resalta.
--
--   NO se anade columna numerica: el caso 1 es LITERAL puro. El numeral
--   es del caso 2 (notas propias no registradas), que va por la
--   calificacion extraordinaria y ya vive en `calificaciones`.
--
-- Idempotente: MariaDB soporta ADD COLUMN / ADD KEY IF NOT EXISTS.
-- ============================================================

ALTER TABLE notas_externas
    ADD COLUMN IF NOT EXISTS area_id SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'area de NUESTRO plan a la que corresponde; NULL = sin mapear'
        AFTER competencia_nombre,
    ADD KEY IF NOT EXISTS idx_area (area_id);
