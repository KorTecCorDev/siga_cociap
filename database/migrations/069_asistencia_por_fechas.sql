-- ============================================================
-- 069_asistencia_por_fechas.sql
-- ASISTENCIA POR FECHAS + catálogo de MOTIVOS de justificación.
--
-- CONTEXTO (29/09/2026, decisión del usuario, opción A). Hasta hoy la asistencia
-- era 4 números por estudiante y bimestre (F, FJ, T, TJ) sin decir QUÉ DÍAS. Desde
-- el III Bimestre el auxiliar marca los días y los 4 contadores de
-- `inasistencias` SE CALCULAN de esas fechas: fechas y números nunca pueden
-- contradecirse, porque solo hay una fuente (`asistencia_incidencias`) y un único
-- punto que escribe los contadores (`AsistenciaModel::recalcularContadores`).
--
--   asistencia_motivos     catálogo con pantalla de gestión (admin y RA editan,
--                          Dirección lee). Un motivo usado se RETIRA, no se borra:
--                          sus registros y reportes se conservan.
--   asistencia_incidencias una fila por estudiante y DÍA (nunca falta y tardanza
--                          el mismo día). FJ/TJ llevan motivo (obligatorio al
--                          CONFIRMAR, no al marcar); F/T nunca lo llevan (CHECK).
--   periodos.asistencia_por_fechas
--                          1 = el bimestre se registra por fechas; 0 = histórico
--                          de solo números (I y II Bimestre 2026). Se ancla en el
--                          DATO (el estado del periodo al migrar), no en una fecha
--                          escrita en el código.
--
-- BIMESTRE EN CURSO guardado como números: una fila con los 4 contadores en 0
-- coincide con «cero fechas» y se queda como está. Una fila con algún contador
-- > 0 NO tiene fechas que la respalden: se DESCONFIRMA (queda «al aire», fuera de
-- la boleta) y el paso 6 la lista para que el auxiliar la registre por fecha. La
-- vía EXTRAORDINARIA (extraordinaria = 1) no se toca: es por diseño 4 números.
--
-- 🔴 Los ajustes de datos (pasos 3b y 5) corren SOLO cuando la columna
--    `asistencia_por_fechas` se crea aquí. Re-ejecutar el archivo más tarde NO
--    vuelve a marcar periodos ni a desconfirmar filas.
--
-- ⚠️ EN PRODUCCIÓN, ANTES de aplicar: correr el PREVIEW de abajo y revisar cuántas
--    filas del bimestre en curso quedarían desconfirmadas (hay que re-registrarlas).
--
-- Idempotente. Ejecutar después de 068_confirmacion_conducta_asistencia.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción) ──────────────────
--   SELECT i.id, i.matricula_id, p.nombre_display,
--          i.faltas, i.faltas_justificadas, i.tardanzas, i.tardanzas_justificadas
--     FROM inasistencias i
--     INNER JOIN periodos p ON p.id = i.periodo_id AND p.estado <> 'cerrado'
--    WHERE i.extraordinaria = 0
--      AND (i.faltas + i.faltas_justificadas + i.tardanzas + i.tardanzas_justificadas) > 0;

-- 1) Catálogo de motivos.
CREATE TABLE IF NOT EXISTS asistencia_motivos (
    id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo         VARCHAR(40)       NOT NULL,
    nombre         VARCHAR(120)      NOT NULL,
    orden          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retirado_en    DATETIME          NULL DEFAULT NULL,
    retirado_por   INT UNSIGNED      NULL DEFAULT NULL,
    creado_en      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por     INT UNSIGNED      NULL DEFAULT NULL,
    modificado_en  DATETIME          NULL DEFAULT NULL,
    modificado_por INT UNSIGNED      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_codigo (codigo),
    KEY idx_retirado_por (retirado_por),
    KEY idx_creado_por (creado_por),
    KEY idx_modificado_por (modificado_por),
    CONSTRAINT asistencia_motivos_ibfk_1 FOREIGN KEY (retirado_por)   REFERENCES usuarios (id),
    CONSTRAINT asistencia_motivos_ibfk_2 FOREIGN KEY (creado_por)     REFERENCES usuarios (id),
    CONSTRAINT asistencia_motivos_ibfk_3 FOREIGN KEY (modificado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Motivos iniciales (decisión del usuario, 29/09/2026). INSERT IGNORE por código:
-- re-ejecutar no los duplica ni pisa un nombre ya corregido en la pantalla.
INSERT IGNORE INTO asistencia_motivos (codigo, nombre, orden) VALUES
    ('escrita_autorizada', 'Justificación escrita autorizada', 1),
    ('verbal',             'Justificación verbal',             2);

-- 2) Incidencias por fecha.
CREATE TABLE IF NOT EXISTS asistencia_incidencias (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id   INT UNSIGNED      NOT NULL,
    periodo_id     SMALLINT UNSIGNED NOT NULL,
    fecha          DATE              NOT NULL,
    tipo           ENUM('F','FJ','T','TJ') NOT NULL,
    motivo_id      SMALLINT UNSIGNED NULL DEFAULT NULL,
    registrado_por INT UNSIGNED      NOT NULL,
    registrado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modificado_en  DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (id),
    -- Una incidencia por estudiante y día: no se puede faltar y llegar tarde.
    UNIQUE KEY uq_matricula_fecha (matricula_id, fecha),
    KEY idx_matricula_periodo (matricula_id, periodo_id),
    KEY idx_periodo (periodo_id),
    KEY idx_motivo (motivo_id),
    KEY idx_registrado_por (registrado_por),
    CONSTRAINT asistencia_incidencias_ibfk_1 FOREIGN KEY (matricula_id)   REFERENCES matriculas         (id),
    CONSTRAINT asistencia_incidencias_ibfk_2 FOREIGN KEY (periodo_id)     REFERENCES periodos           (id),
    CONSTRAINT asistencia_incidencias_ibfk_3 FOREIGN KEY (motivo_id)      REFERENCES asistencia_motivos (id),
    CONSTRAINT asistencia_incidencias_ibfk_4 FOREIGN KEY (registrado_por) REFERENCES usuarios           (id),
    -- El motivo es solo de las JUSTIFICADAS.
    CONSTRAINT chk_motivo_solo_justificada CHECK (motivo_id IS NULL OR tipo IN ('FJ','TJ'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) periodos.asistencia_por_fechas (ajuste de datos SOLO si la columna es nueva).
SET @por_fechas_nueva := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'periodos'
                            AND COLUMN_NAME = 'asistencia_por_fechas');
SET @sql := IF(@por_fechas_nueva = 1,
    'ALTER TABLE periodos ADD COLUMN asistencia_por_fechas TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT "069: periodos.asistencia_por_fechas ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b) Los bimestres YA CERRADOS son histórico de solo números.
UPDATE periodos SET asistencia_por_fechas = 0
 WHERE @por_fechas_nueva = 1 AND estado = 'cerrado';

-- 4) (sin paso: los contadores en 0 del bimestre en curso ya coinciden con «cero fechas»)

-- 5) Bimestre por fechas con números SIN fechas que los respalden: se desconfirman.
UPDATE inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
   SET i.confirmado_en = NULL, i.confirmado_por = NULL
 WHERE @por_fechas_nueva = 1
   AND i.extraordinaria = 0
   AND (i.faltas + i.faltas_justificadas + i.tardanzas + i.tardanzas_justificadas) > 0
   AND NOT EXISTS (SELECT 1 FROM asistencia_incidencias x
                    WHERE x.matricula_id = i.matricula_id AND x.periodo_id = i.periodo_id);

-- 6) Verificación.
--    a) Periodos y su modo.
SELECT id, numero, nombre_display, estado, asistencia_por_fechas FROM periodos ORDER BY anio_id, numero;
--    b) Motivos sembrados.
SELECT id, codigo, nombre, orden FROM asistencia_motivos ORDER BY orden;
--    c) Filas a RE-REGISTRAR por fecha (números sin fechas en un bimestre por fechas).
SELECT i.id, i.matricula_id, i.periodo_id,
       i.faltas, i.faltas_justificadas, i.tardanzas, i.tardanzas_justificadas,
       i.confirmado_en
  FROM inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
 WHERE i.extraordinaria = 0
   AND (i.faltas + i.faltas_justificadas + i.tardanzas + i.tardanzas_justificadas) > 0
   AND NOT EXISTS (SELECT 1 FROM asistencia_incidencias x
                    WHERE x.matricula_id = i.matricula_id AND x.periodo_id = i.periodo_id);
