-- ============================================================
-- 073_retornos_grado_tramo.sql
-- RETORNO DE GRADO: TRAMO explícito de bimestres cursados en la operativa.
--
-- CONTEXTO (05/10/2026, decisión del usuario). Hasta hoy cada consulta DEDUCÍA
-- en qué matrícula cursó el estudiante cada bimestre («la que tiene notas en
-- ese periodo»), y cada una lo hacía distinto. Al revertir el retorno #1 la
-- deducción falló en cadena:
--   - la estudiante desaparecía del lote de boletas del II;
--   - salía dos veces en la situación final del III;
--   - los cuadros perdían sus notas del II;
--   - y 22 promedios del I, COPIADOS a la operativa por el INSERT IGNORE
--     anterior al 05/08/2026, hacían creer que el I se cursó en 1.° B.
--
-- Desde esta migración el tramo se GUARDA y no se deduce:
--
--   periodo_desde_id  primer bimestre cursado en la OPERATIVA. Lo fija store()
--                     (el primer bimestre sin cerrar al registrar el retorno).
--   periodo_hasta_id  último bimestre cursado en la OPERATIVA. Lo fija
--                     revertir() (el último cerrado del tramo). NULL mientras
--                     el retorno está ACTIVO (tramo abierto) y también en un
--                     retorno revertido que NO llegó a cubrir ningún bimestre
--                     completo (tramo vacío).
--
-- REGLA ÚNICA (la emite `RetornoGradoModel`, nunca a mano):
--   la operativa cubre el bimestre P  ⟺  P.numero >= desde.numero
--       AND (estado = 'activo' OR (hasta IS NOT NULL AND P.numero <= hasta.numero))
--
-- RELLENO de los retornos existentes, a partir de los datos PROPIOS de la
-- operativa (evaluación por criterio, omisiones, asistencia y conducta; NO los
-- promedios a secas, que son justo lo que el INSERT IGNORE copió):
--   desde = primer bimestre con datos de la operativa (si no tiene ninguno, el
--           bimestre en curso a la fecha del retorno);
--   hasta = (solo revertidos) último bimestre con datos de la operativa.
-- Retorno #1 (05/10/2026): desde = II, hasta = II.
--
-- ⚠️ EN PRODUCCIÓN: correr el PREVIEW PRIMERO y comprobar el tramo de cada
-- retorno contra lo que se sabe de él. «Un retorno por matrícula y año» ya lo
-- garantizan los UNIQUE existentes (matricula_oficial_id, matricula_operativa_id).
--
-- Idempotente. Ejecutar después de 072_conclusiones_replica_acta.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción) ──────────────────
--   SELECT r.id, r.estado, r.fecha_retorno, r.matricula_oficial_id, r.matricula_operativa_id,
--          (SELECT GROUP_CONCAT(p.numero ORDER BY p.numero) FROM periodos p
--            WHERE p.anio_id = mo.anio_id AND (
--                  EXISTS (SELECT 1 FROM calificaciones_criterio cc JOIN criterios k ON k.id = cc.criterio_id
--                           WHERE cc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
--               OR EXISTS (SELECT 1 FROM omisiones_criterio oc JOIN criterios k ON k.id = oc.criterio_id
--                           WHERE oc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
--               OR EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = mo.id AND i.periodo_id = p.id)
--               OR EXISTS (SELECT 1 FROM conducta_respuestas c WHERE c.matricula_id = mo.id AND c.periodo_id = p.id)
--          )) AS bimestres_con_datos_en_la_operativa
--     FROM retornos_grado r JOIN matriculas mo ON mo.id = r.matricula_operativa_id;

-- 1) Columnas del tramo (solo si no existen).
SET @tramo_nuevo := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retornos_grado'
                       AND COLUMN_NAME = 'periodo_desde_id');
SET @sql := IF(@tramo_nuevo = 1,
    'ALTER TABLE retornos_grado
        ADD COLUMN periodo_desde_id SMALLINT UNSIGNED NULL DEFAULT NULL AFTER fecha_retorno,
        ADD COLUMN periodo_hasta_id SMALLINT UNSIGNED NULL DEFAULT NULL AFTER fecha_reversion,
        ADD KEY idx_retorno_desde (periodo_desde_id),
        ADD KEY idx_retorno_hasta (periodo_hasta_id),
        ADD CONSTRAINT fk_retorno_periodo_desde FOREIGN KEY (periodo_desde_id) REFERENCES periodos (id),
        ADD CONSTRAINT fk_retorno_periodo_hasta FOREIGN KEY (periodo_hasta_id) REFERENCES periodos (id)',
    'SELECT "073: el tramo ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Relleno de `desde` (solo filas sin tramo).
UPDATE retornos_grado r
INNER JOIN matriculas mo ON mo.id = r.matricula_operativa_id
SET r.periodo_desde_id = COALESCE(
    (SELECT p.id FROM periodos p
      WHERE p.anio_id = mo.anio_id AND (
            EXISTS (SELECT 1 FROM calificaciones_criterio cc JOIN criterios k ON k.id = cc.criterio_id
                     WHERE cc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
         OR EXISTS (SELECT 1 FROM omisiones_criterio oc JOIN criterios k ON k.id = oc.criterio_id
                     WHERE oc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
         OR EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = mo.id AND i.periodo_id = p.id)
         OR EXISTS (SELECT 1 FROM conducta_respuestas c WHERE c.matricula_id = mo.id AND c.periodo_id = p.id))
      ORDER BY p.numero LIMIT 1),
    (SELECT p.id FROM periodos p
      WHERE p.anio_id = mo.anio_id AND p.fecha_fin >= r.fecha_retorno
      ORDER BY p.numero LIMIT 1))
WHERE r.periodo_desde_id IS NULL;

-- 3) Relleno de `hasta` (solo revertidos sin tramo cerrado).
UPDATE retornos_grado r
INNER JOIN matriculas mo ON mo.id = r.matricula_operativa_id
SET r.periodo_hasta_id =
    (SELECT p.id FROM periodos p
      WHERE p.anio_id = mo.anio_id AND (
            EXISTS (SELECT 1 FROM calificaciones_criterio cc JOIN criterios k ON k.id = cc.criterio_id
                     WHERE cc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
         OR EXISTS (SELECT 1 FROM omisiones_criterio oc JOIN criterios k ON k.id = oc.criterio_id
                     WHERE oc.matricula_id = mo.id AND k.periodo_id = p.id AND k.eliminado_en IS NULL)
         OR EXISTS (SELECT 1 FROM inasistencias i WHERE i.matricula_id = mo.id AND i.periodo_id = p.id)
         OR EXISTS (SELECT 1 FROM conducta_respuestas c WHERE c.matricula_id = mo.id AND c.periodo_id = p.id))
      ORDER BY p.numero DESC LIMIT 1)
WHERE r.estado = 'revertido' AND r.periodo_hasta_id IS NULL;

-- 4) `desde` obligatorio: si alguna fila quedó sin tramo, esto FALLA a propósito
--    (revisar ese retorno a mano antes de seguir).
ALTER TABLE retornos_grado MODIFY periodo_desde_id SMALLINT UNSIGNED NOT NULL;

-- 5) Verificación.
SELECT r.id, r.estado, pd.nombre_display AS desde, ph.nombre_display AS hasta
FROM retornos_grado r
INNER JOIN periodos pd ON pd.id = r.periodo_desde_id
LEFT  JOIN periodos ph ON ph.id = r.periodo_hasta_id;
