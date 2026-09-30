-- ============================================================
-- 066_auxiliar_secciones.sql
-- Qué auxiliar académico tiene a cargo cada sección, POR BIMESTRE.
--
-- CONTEXTO (28/09/2026). Las secciones de un auxiliar cambian a lo largo del
-- año: puede empezar con unas y terminar con otras. Todo lo que registra es por
-- bimestre, así que la asignación también lo es.
--
-- MODELO «DESDE BIMESTRE»: una fila dice «desde el bimestre X, la sección S la
-- tiene el auxiliar A» y vale hasta que otra fila posterior de la MISMA sección
-- la reemplace. El auxiliar vigente de S en el bimestre P es el de la fila con
-- el mayor `periodos.numero` <= P del mismo año (AuxiliarSeccionModel::vigente).
--   - La HERENCIA es automática: si en B4 nadie cambia nada, sigue el de B3.
--   - El HISTORIAL queda intacto: cada bimestre cerrado conserva su auxiliar,
--     que es el que firma sus documentos.
--   - `auxiliar_id NULL` = «sin auxiliar desde este bimestre».
--
-- Solo se asigna el bimestre ACTIVO (decisión del usuario, 28/09/2026): cambiarlo
-- dos veces dentro del mismo bimestre SOBRESCRIBE su fila (UNIQUE), porque el
-- responsable es quien la tiene al cerrar. Los bimestres cerrados no se tocan.
--
-- Un solo auxiliar por sección y bimestre: lo garantiza el UNIQUE.
--
-- `asignado_en` lo escribe PHP (America/Lima); el DEFAULT es solo un respaldo:
-- en producción la base corre en UTC.
-- Ejecutar después de 065_rol_auxiliar_academico.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS auxiliar_secciones (
    id               INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    seccion_id       SMALLINT UNSIGNED NOT NULL,
    desde_periodo_id SMALLINT UNSIGNED NOT NULL,
    auxiliar_id      INT      UNSIGNED NULL,
    asignado_por     INT      UNSIGNED NOT NULL,
    asignado_en      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seccion_desde (seccion_id, desde_periodo_id),
    KEY idx_desde_periodo (desde_periodo_id),
    KEY idx_auxiliar (auxiliar_id),
    KEY idx_asignado_por (asignado_por),
    CONSTRAINT auxiliar_secciones_ibfk_1 FOREIGN KEY (seccion_id)       REFERENCES secciones (id),
    CONSTRAINT auxiliar_secciones_ibfk_2 FOREIGN KEY (desde_periodo_id) REFERENCES periodos  (id),
    CONSTRAINT auxiliar_secciones_ibfk_3 FOREIGN KEY (auxiliar_id)      REFERENCES usuarios  (id),
    CONSTRAINT auxiliar_secciones_ibfk_4 FOREIGN KEY (asignado_por)     REFERENCES usuarios  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
