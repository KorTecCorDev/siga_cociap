-- ============================================================
-- 071_tutor_periodo.sql
-- TUTOR DEL BIMESTRE: se congela al CERRAR cada bimestre.
--
-- CONTEXTO (01/10/2026, decisiones del usuario). `secciones.tutor_id` es el
-- tutor de HOY y no tenía historial: cambiar de tutor a mitad de año reescribía
-- hacia atrás el nombre y la FIRMA del tutor en las boletas y actas de los
-- bimestres ya cerrados (que el tutor nuevo no evaluó).
--
--   secciones_tutor_periodo  quién era el tutor de cada sección AL CERRAR cada
--                            bimestre. Lo escribe SOLO el cierre del bimestre
--                            (`TutorPeriodoModel::congelarPeriodo`).
--                            ⚠️ INMUTABLE: el UNIQUE + INSERT IGNORE conservan el
--                            PRIMER congelado; reabrir y re-cerrar no lo pisa.
--                            tutor_id NULL = la sección no tenía tutor al cerrar.
--
-- Los DOCUMENTOS de un bimestre (boleta, acta de mérito, acompañamiento) nombran
-- al tutor congelado; sin fila (bimestre en curso) nombran al actual. Las
-- FUNCIONES del tutor siguen saliendo de `secciones.tutor_id`.
--
-- RELLENO: los bimestres ya CERRADOS del año activo reciben el tutor actual.
-- ⚠️ EN PRODUCCIÓN, ANTES de aplicar: correr el PREVIEW. Si devuelve filas, esa
-- sección tuvo otro tutor en un bimestre cerrado y su fila se corrige a mano
-- después del relleno.
--
-- Idempotente. Ejecutar después de 070_asistencia_jornadas.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción) ──────────────────
-- Cierres vigentes hechos por un DOCENTE que no es el tutor actual de la sección.
--   SELECT 'transversales' AS cierre, ct.periodo_id, ct.seccion_id, s.tutor_id, ct.cerrado_por AS docente
--     FROM cierres_transversales ct
--     JOIN secciones s ON s.id = ct.seccion_id
--     JOIN usuarios u  ON u.id = ct.cerrado_por
--     JOIN roles r     ON r.id = u.rol_id AND r.codigo = 'docente'
--    WHERE ct.anulado_en IS NULL AND ct.cerrado_por <> COALESCE(s.tutor_id, 0)
--   UNION ALL
--   SELECT 'conducta', cc.periodo_id, cc.seccion_id, s.tutor_id, cc.tutor_cerrado_por
--     FROM cierres_conducta cc
--     JOIN secciones s ON s.id = cc.seccion_id
--     JOIN usuarios u  ON u.id = cc.tutor_cerrado_por
--     JOIN roles r     ON r.id = u.rol_id AND r.codigo = 'docente'
--    WHERE cc.anulado_en IS NULL AND cc.tutor_cerrado_por <> COALESCE(s.tutor_id, 0);

-- 1) Tutor congelado por sección y bimestre.
CREATE TABLE IF NOT EXISTS secciones_tutor_periodo (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    seccion_id     SMALLINT UNSIGNED NOT NULL,
    periodo_id     SMALLINT UNSIGNED NOT NULL,
    tutor_id       INT UNSIGNED      NULL DEFAULT NULL,
    congelado_en   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    congelado_por  INT UNSIGNED      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seccion_periodo (seccion_id, periodo_id),
    KEY idx_periodo (periodo_id),
    KEY idx_tutor (tutor_id),
    KEY idx_congelado_por (congelado_por),
    CONSTRAINT secciones_tutor_periodo_ibfk_1 FOREIGN KEY (seccion_id)    REFERENCES secciones (id),
    CONSTRAINT secciones_tutor_periodo_ibfk_2 FOREIGN KEY (periodo_id)    REFERENCES periodos  (id),
    CONSTRAINT secciones_tutor_periodo_ibfk_3 FOREIGN KEY (tutor_id)      REFERENCES usuarios  (id),
    CONSTRAINT secciones_tutor_periodo_ibfk_4 FOREIGN KEY (congelado_por) REFERENCES usuarios  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Relleno: bimestres CERRADOS del año activo con el tutor actual
--    (congelado_por NULL = vino de esta migración, no de un cierre).
INSERT IGNORE INTO secciones_tutor_periodo (seccion_id, periodo_id, tutor_id, congelado_por)
SELECT s.id, p.id, s.tutor_id, NULL
FROM periodos p
INNER JOIN anios_academicos a ON a.id = p.anio_id AND a.estado = 'activo'
INNER JOIN secciones s        ON s.anio_id = p.anio_id
WHERE p.estado = 'cerrado';
