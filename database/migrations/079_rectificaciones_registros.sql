-- ============================================================
-- 079_rectificaciones_registros.sql
-- RECTIFICACIÓN DE CONDUCTA Y ASISTENCIA (F5 del plan de bloqueos, 13/10/2026).
--
-- CONTEXTO (decisiones del usuario, 09 y 13/10/2026; docs/modulos/
-- cierre-cuatro-registros.md §0). Después de «Bloquear y aprobar» nadie registra
-- ni edita la conducta (etapa 1) ni la asistencia. Para corregirlas sin reabrir,
-- RA/admin RECTIFICAN, con motivo obligatorio y traza, como en las notas:
--
--   rectificaciones_conducta    los Sí/No por criterio del auxiliar. Una fila por
--                               criterio que CAMBIÓ; la nota final se recalcula
--                               sola con la del tutor (`promediar`).
--   rectificaciones_asistencia  la marca de UN día (bimestres por fechas). Los
--                               contadores se recalculan con
--                               `AsistenciaModel::recalcularContadores`.
--
-- Una tabla POR REGISTRO (decisión del 13/10/2026): columnas tipadas con FK, como
-- `rectificaciones_calificacion`. Si el tutor ya cerró la etapa 2, la
-- rectificación solo deja esta traza: no reabre nada. En una boleta publicada se
-- ve al instante (la boleta se arma en vivo, igual que con las notas).
--
-- `marca_anterior = 'sin_tomar'`: con un bloqueo forzado, un día puede no tener
-- ninguna marca (ni incidencia, ni ✓ propio, ni lista de la sección).
--
-- NO TOCA DATOS. Idempotente. Ejecutar después de 078_periodos_limite_auxiliares.sql.
-- ⚠️ En producción: aplicar a mano ANTES del merge a main (el código nuevo lee
-- las tablas en cuanto se despliega).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS rectificaciones_conducta (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id       INT UNSIGNED      NOT NULL,
    periodo_id         SMALLINT UNSIGNED NOT NULL,
    criterio_id        SMALLINT UNSIGNED NOT NULL,
    respuesta_anterior TINYINT(1)        NOT NULL,
    respuesta_nueva    TINYINT(1)        NOT NULL,
    literal_anterior   VARCHAR(2)        NULL DEFAULT NULL,
    literal_nuevo      VARCHAR(2)        NULL DEFAULT NULL,
    motivo             TEXT              NOT NULL,
    rectificado_por    INT UNSIGNED      NOT NULL,
    rectificado_en     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rcond_matricula_periodo (matricula_id, periodo_id),
    KEY idx_rcond_criterio (criterio_id),
    KEY idx_rcond_periodo (periodo_id),
    KEY idx_rcond_usuario (rectificado_por),
    CONSTRAINT fk_rcond_matricula FOREIGN KEY (matricula_id)    REFERENCES matriculas (id),
    CONSTRAINT fk_rcond_periodo   FOREIGN KEY (periodo_id)      REFERENCES periodos (id),
    CONSTRAINT fk_rcond_criterio  FOREIGN KEY (criterio_id)     REFERENCES criterios_conducta (id),
    CONSTRAINT fk_rcond_usuario   FOREIGN KEY (rectificado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rectificaciones_asistencia (
    id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id       INT UNSIGNED      NOT NULL,
    periodo_id         SMALLINT UNSIGNED NOT NULL,
    fecha              DATE              NOT NULL,
    marca_anterior     ENUM('sin_tomar','asistio','F','FJ','T','TJ') NOT NULL,
    motivo_anterior_id SMALLINT UNSIGNED NULL DEFAULT NULL,
    marca_nueva        ENUM('asistio','F','FJ','T','TJ') NOT NULL,
    motivo_nuevo_id    SMALLINT UNSIGNED NULL DEFAULT NULL,
    motivo             TEXT              NOT NULL,
    rectificado_por    INT UNSIGNED      NOT NULL,
    rectificado_en     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rasis_matricula_periodo (matricula_id, periodo_id),
    KEY idx_rasis_periodo (periodo_id),
    KEY idx_rasis_motivo_ant (motivo_anterior_id),
    KEY idx_rasis_motivo_nuevo (motivo_nuevo_id),
    KEY idx_rasis_usuario (rectificado_por),
    CONSTRAINT fk_rasis_matricula  FOREIGN KEY (matricula_id)       REFERENCES matriculas (id),
    CONSTRAINT fk_rasis_periodo    FOREIGN KEY (periodo_id)         REFERENCES periodos (id),
    CONSTRAINT fk_rasis_motivo_ant FOREIGN KEY (motivo_anterior_id) REFERENCES asistencia_motivos (id),
    CONSTRAINT fk_rasis_motivo_nvo FOREIGN KEY (motivo_nuevo_id)    REFERENCES asistencia_motivos (id),
    CONSTRAINT fk_rasis_usuario    FOREIGN KEY (rectificado_por)    REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cierre con SHOW y no SELECT FROM information_schema: phpMyAdmin toma la base
-- de la ULTIMA sentencia SELECT del archivo y corre TODO el import en ella (#1044).
-- Ver docs/infraestructura.md, "Migraciones: reglas para que importen en phpMyAdmin".
SHOW TABLES LIKE 'rectificaciones_%';
