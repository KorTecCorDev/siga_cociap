-- ============================================================
-- 068_confirmacion_conducta_asistencia.sql
-- «CONFIRMAR» en conducta y asistencia: borrador autoguardado vs. dato confirmado.
--
-- CONTEXTO (29/09/2026, repaso del módulo auxiliares, decisión B2 del usuario).
-- El registro de conducta y de asistencia pasa al flujo de los docentes: cada
-- cambio se AUTOGUARDA como borrador y el botón «Confirmar» le da el visto bueno.
-- Lo no confirmado queda «al aire»: no cuenta en boleta, cuadros, tutor, bloqueo
-- ni vía extraordinaria. Editar algo confirmado lo DESCONFIRMA. Confirmar no es el
-- visto final: ese sigue siendo «Bloquear y aprobar».
--
-- Hasta hoy nada marcaba qué era oficial: toda lectura asumía que una fila
-- guardada era definitiva, cierto solo porque el guardado exigía la fila entera.
--
--   CONDUCTA   → tabla nueva `conducta_confirmaciones` (una fila por estudiante y
--                bimestre). Va aparte porque las respuestas son una fila POR
--                CRITERIO. Existe la fila ⟺ el registro está confirmado.
--   ASISTENCIA → columnas `confirmado_en` / `confirmado_por` en `inasistencias`
--                (ya es una fila por estudiante y bimestre). NULL = borrador.
--
-- BACKFILL: todo lo guardado ANTES de esta migración se guardó con el flujo viejo,
-- que exigía la fila completa, así que es un dato confirmado de hecho.
--   - Conducta: se confirma cada estudiante-bimestre con EXACTAMENTE sus N
--     respuestas a criterios vigentes de su año y nivel. Uno incompleto NO se
--     confirma (no se inventa una nota) y el paso 5 lo lista.
--   - Asistencia: se confirman todas las filas existentes.
-- 🔴 El backfill corre SOLO cuando la tabla o la columna se crean aquí. Volver a
--    ejecutar el archivo más tarde confirmaría BORRADORES reales: por eso va
--    condicionado a que la estructura no existiera.
--
-- Idempotente. Ejecutar después de 067_criterios_conducta_por_anio.sql.
-- En PRODUCCIÓN: a mano, ANTES del push a `main` (el código nuevo lee estas
-- columnas en boleta, cuadros y bloqueo).
-- ============================================================

-- 1) CONDUCTA: ¿existe ya la tabla? (decide si corre el backfill)
SET @conducta_nueva := (SELECT COUNT(*) = 0 FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'conducta_confirmaciones');

CREATE TABLE IF NOT EXISTS conducta_confirmaciones (
    id             INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    matricula_id   INT      UNSIGNED NOT NULL,
    periodo_id     SMALLINT UNSIGNED NOT NULL,
    confirmado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmado_por INT      UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_confirmacion (matricula_id, periodo_id),
    KEY idx_periodo (periodo_id),
    KEY idx_confirmado_por (confirmado_por),
    CONSTRAINT conducta_confirmaciones_ibfk_1 FOREIGN KEY (matricula_id)   REFERENCES matriculas (id),
    CONSTRAINT conducta_confirmaciones_ibfk_2 FOREIGN KEY (periodo_id)     REFERENCES periodos   (id),
    CONSTRAINT conducta_confirmaciones_ibfk_3 FOREIGN KEY (confirmado_por) REFERENCES usuarios   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) CONDUCTA: backfill de los registros COMPLETOS (solo si la tabla es nueva).
--    Completo = respuestas a criterios vigentes del año del periodo y del nivel
--    de la sección = total de esos criterios (misma condición que
--    `ConductaModel::criteriosDelAnio`). Fecha y usuario: los del último guardado.
INSERT INTO conducta_confirmaciones (matricula_id, periodo_id, confirmado_en, confirmado_por)
SELECT x.matricula_id, x.periodo_id, x.ultimo_en, x.ultimo_por
FROM (
    SELECT r.matricula_id, r.periodo_id,
           MAX(COALESCE(r.modificado_en, r.registrado_en)) AS ultimo_en,
           CAST(SUBSTRING_INDEX(GROUP_CONCAT(r.registrado_por
                ORDER BY COALESCE(r.modificado_en, r.registrado_en) DESC, r.id DESC), ',', 1)
                AS UNSIGNED) AS ultimo_por,
           COUNT(*) AS respondidos,
           (SELECT COUNT(*) FROM criterios_conducta k2
             WHERE k2.eliminado_en IS NULL AND k2.anio_id = p.anio_id
               AND (k2.nivel_id IS NULL OR k2.nivel_id = g.nivel_id)) AS total
    FROM conducta_respuestas r
    INNER JOIN periodos   p ON p.id = r.periodo_id
    INNER JOIN matriculas m ON m.id = r.matricula_id
    INNER JOIN secciones  s ON s.id = m.seccion_id
    INNER JOIN grados     g ON g.id = s.grado_id
    INNER JOIN criterios_conducta k
            ON k.id = r.criterio_id
           AND k.eliminado_en IS NULL AND k.anio_id = p.anio_id
           AND (k.nivel_id IS NULL OR k.nivel_id = g.nivel_id)
    WHERE @conducta_nueva = 1
    GROUP BY r.matricula_id, r.periodo_id, p.anio_id, g.nivel_id
) x
WHERE x.total > 0 AND x.respondidos = x.total;

-- 3) ASISTENCIA: columnas de confirmación (backfill solo si se crean aquí).
SET @asistencia_nueva := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inasistencias'
                            AND COLUMN_NAME = 'confirmado_en');
SET @sql := IF(@asistencia_nueva = 1,
    'ALTER TABLE inasistencias
        ADD COLUMN confirmado_en  DATETIME     NULL DEFAULT NULL AFTER modificado_en,
        ADD COLUMN confirmado_por INT UNSIGNED NULL DEFAULT NULL AFTER confirmado_en,
        ADD KEY idx_confirmado_por (confirmado_por),
        ADD CONSTRAINT inasistencias_ibfk_4 FOREIGN KEY (confirmado_por) REFERENCES usuarios (id)',
    'SELECT "068: inasistencias.confirmado_en ya existe, no se toca (ni se re-confirma)" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) ASISTENCIA: backfill (solo si las columnas son nuevas).
UPDATE inasistencias
   SET confirmado_en  = COALESCE(modificado_en, registrado_en),
       confirmado_por = registrado_por
 WHERE @asistencia_nueva = 1
   AND confirmado_en IS NULL;

-- 5) Verificación.
--    a) Conducta: confirmados por bimestre vs. estudiantes con respuestas.
SELECT r.periodo_id,
       COUNT(DISTINCT r.matricula_id)                AS con_respuestas,
       COUNT(DISTINCT c.matricula_id)                AS confirmados,
       COUNT(DISTINCT r.matricula_id)
         - COUNT(DISTINCT c.matricula_id)            AS sin_confirmar
  FROM conducta_respuestas r
  LEFT JOIN conducta_confirmaciones c
         ON c.matricula_id = r.matricula_id AND c.periodo_id = r.periodo_id
 GROUP BY r.periodo_id;

--    b) Conducta: los que quedaron SIN confirmar (revisar a mano; deberían ser 0).
SELECT r.matricula_id, r.periodo_id, COUNT(*) AS respondidos
  FROM conducta_respuestas r
  LEFT JOIN conducta_confirmaciones c
         ON c.matricula_id = r.matricula_id AND c.periodo_id = r.periodo_id
 WHERE c.id IS NULL
 GROUP BY r.matricula_id, r.periodo_id;

--    c) Asistencia: filas por bimestre y cuántas confirmadas.
SELECT periodo_id, COUNT(*) AS filas, SUM(confirmado_en IS NOT NULL) AS confirmadas
  FROM inasistencias
 GROUP BY periodo_id;
