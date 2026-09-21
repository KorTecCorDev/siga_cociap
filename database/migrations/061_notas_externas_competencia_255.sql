-- ============================================================
-- 061_notas_externas_competencia_255.sql
-- `notas_externas.competencia_nombre` pasa de VARCHAR(120) a VARCHAR(255).
--
-- EL PROBLEMA (medido el 21/09/2026, no es teorico)
--   El importador de curricula (e47e87c) llena la competencia con
--   `competencias.nombre_completo`, que es TEXT: 7 competencias del plan
--   pasan de 120 caracteres (la mas larga tiene 185). El `sql_mode` del
--   servidor NO es estricto, asi que MariaDB RECORTA el exceso EN SILENCIO
--   al guardar: la fila 152 de local (matricula 693) quedo como
--   "... biodiversidad, Tierra y Uni". El docente veia la competencia
--   cortada en /docente/notas-origen/{id}.
--
-- EL ARREGLO
--   255 cubre la mas larga con margen. La columna esta en la UNIQUE
--   `uq_nota_externa` (migracion 059): con 255 la clave ocupa ~1630 bytes
--   de los 3072 permitidos (ROW_FORMAT=Dynamic), asi que cabe.
--   El servidor ademas RECHAZA un nombre de mas de 255 en vez de dejar que
--   se recorte (`NotaExternaModel::MAX_COMPETENCIA`).
--
--   Las filas YA recortadas no se arreglan aqui: las repara
--   `database/reparar_notas_externas_truncadas.php` (con --aplicar).
--
-- Idempotente: MODIFY a la misma definicion no cambia nada.
-- ============================================================

ALTER TABLE notas_externas
    MODIFY competencia_nombre VARCHAR(255) NOT NULL;
