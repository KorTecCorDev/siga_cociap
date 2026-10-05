-- ============================================================
-- 072_conclusiones_replica_acta.sql
-- CONCLUSIONES DE RÉPLICA para el acta SIAGIE.
--
-- CONTEXTO (02/10/2026, decisiones del usuario). En 5.º de secundaria la nota
-- de GAMA se REPLICA en el acta: llena su hoja transversal y además las de
-- Educación para el Trabajo (032) y Arte y Cultura (0001), áreas que no se
-- dictan en ese grado (`EQUIVALENCIAS_ACTA_SIAGIE`, helpers.php). Hasta hoy la
-- conclusión del tutor se copiaba igual en todas, y un texto escrito sobre
-- «Gestiona su aprendizaje» quedaba bajo el rótulo de otra área.
--
--   conclusiones_replica_acta  UNA conclusión por estudiante, bimestre y ÁREA
--                              DESTINO de la réplica. Se repite en todas las
--                              columnas (competencias) de esa área en el acta.
--
-- Reglas (ver `ConclusionReplicaModel` y `replicas_conclusion_acta()`):
--   · Solo se piden cuando la conclusión de la nota FUENTE es OBLIGATORIA
--     (secundaria: GAMA en C). Con nota aprobatoria se replica la de GAMA y
--     estas filas no se leen.
--   · NO salen en la boleta: son solo para el acta.
--   · Las escriben el tutor (antes de cerrar transversales) y Registro
--     Académico en el lote extraordinario.
--   · Si faltan, el llenador escribe la de la fuente y lo avisa en el reporte.
--
-- `area_id` es el área DESTINO (la del acta), no la de la nota.
--
-- Idempotente. Ejecutar después de 071_tutor_periodo.sql. Sin relleno: al
-- 02/10/2026 ningún estudiante de 5.º tiene GAMA en C en I ni II Bimestre.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS conclusiones_replica_acta (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id    INT UNSIGNED      NOT NULL,
    periodo_id      SMALLINT UNSIGNED NOT NULL,
    area_id         SMALLINT UNSIGNED NOT NULL,
    conclusion      TEXT              NOT NULL,
    registrado_por  INT UNSIGNED      NOT NULL,
    registrado_en   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modificado_en   DATETIME          NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mat_periodo_area (matricula_id, periodo_id, area_id),
    KEY idx_periodo (periodo_id),
    KEY idx_area (area_id),
    KEY idx_registrado_por (registrado_por),
    CONSTRAINT fk_crep_matricula FOREIGN KEY (matricula_id)   REFERENCES matriculas (id),
    CONSTRAINT fk_crep_periodo   FOREIGN KEY (periodo_id)     REFERENCES periodos   (id),
    CONSTRAINT fk_crep_area      FOREIGN KEY (area_id)        REFERENCES areas      (id),
    CONSTRAINT fk_crep_usuario   FOREIGN KEY (registrado_por) REFERENCES usuarios   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
