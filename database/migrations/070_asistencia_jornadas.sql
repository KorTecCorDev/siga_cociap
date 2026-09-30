-- ============================================================
-- 070_asistencia_jornadas.sql
-- LISTA DEL DÍA (✓ asistió), DÍAS NO LECTIVOS y JUSTIFICACIÓN SIEMPRE CON MOTIVO.
--
-- CONTEXTO (30/09/2026, decisiones del usuario). En la asistencia por fechas un día
-- sin incidencia era AMBIGUO: «asistió» o «nadie pasó lista» pesaban igual en la
-- boleta, y un olvido no se podía detectar. Además se podían guardar FJ/TJ SIN
-- motivo (el autoguardado iba antes de elegirlo).
--
--   asistencia_jornadas         la LISTA DEL DÍA de una sección en un bimestre. Con
--                               ella, todo estudiante sin incidencia ese día ASISTIÓ
--                               (✓); sin ella el día está «Sin tomar». Cualquier marca
--                               la crea; también el botón «Pasar lista». Es la misma
--                               entidad que abrirá el ingreso por QR en 2027
--                               (origen = 'qr').
--                               ⚠️ UNIQUE (seccion, PERIODO, fecha): los bimestres SE
--                               SOLAPAN (III hasta el 09/10, IV desde el 05/10).
--   asistencia_dias_no_lectivos feriados y suspensiones, para TODO el colegio. Un día
--                               no lectivo no se marca ni se exige al bloquear.
--   chk_justificada_con_motivo  FJ/TJ SIEMPRE con motivo. Las que hoy no lo tienen
--                               pasan a F/T (decisión del usuario: BD de desarrollo)
--                               y su fila se recuenta y DESCONFIRMA.
--
-- RELLENO: toda sección de un bimestre por fechas recibe su lista (origen =
-- 'migracion') en cada lunes a viernes ya TRANSCURRIDO (hasta AYER) del bimestre:
-- equivale a lo que se suponía hasta hoy. ⚠️ En producción cubre hasta la víspera
-- del despliegue.
--
-- ⚠️ EN PRODUCCIÓN, ANTES de aplicar: correr el PREVIEW (FJ/TJ que pasarán a F/T).
--
-- Idempotente. Ejecutar después de 069_asistencia_por_fechas.sql.
-- ============================================================

SET NAMES utf8mb4;

-- ── PREVIEW (solo lectura; correr PRIMERO en producción) ──────────────────
--   SELECT x.id, x.matricula_id, x.periodo_id, x.fecha, x.tipo
--     FROM asistencia_incidencias x
--    WHERE x.tipo IN ('FJ','TJ') AND x.motivo_id IS NULL;

-- 1) Lista del día.
CREATE TABLE IF NOT EXISTS asistencia_jornadas (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    seccion_id  SMALLINT UNSIGNED NOT NULL,
    periodo_id  SMALLINT UNSIGNED NOT NULL,
    fecha       DATE              NOT NULL,
    origen      ENUM('manual','qr','migracion') NOT NULL DEFAULT 'manual',
    tomada_por  INT UNSIGNED      NULL DEFAULT NULL,
    tomada_en   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seccion_periodo_fecha (seccion_id, periodo_id, fecha),
    KEY idx_periodo (periodo_id),
    KEY idx_fecha (fecha),
    KEY idx_tomada_por (tomada_por),
    CONSTRAINT asistencia_jornadas_ibfk_1 FOREIGN KEY (seccion_id) REFERENCES secciones (id),
    CONSTRAINT asistencia_jornadas_ibfk_2 FOREIGN KEY (periodo_id) REFERENCES periodos  (id),
    CONSTRAINT asistencia_jornadas_ibfk_3 FOREIGN KEY (tomada_por) REFERENCES usuarios  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Días no lectivos (todo el colegio).
CREATE TABLE IF NOT EXISTS asistencia_dias_no_lectivos (
    fecha          DATE          NOT NULL,
    motivo         VARCHAR(160)  NOT NULL,
    registrado_por INT UNSIGNED  NOT NULL,
    registrado_en  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (fecha),
    KEY idx_registrado_por (registrado_por),
    CONSTRAINT asistencia_dias_no_lectivos_ibfk_1 FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) FJ/TJ SIN motivo → F/T. Primero se anotan las filas (matrícula, periodo)
--    afectadas, para recontarlas y desconfirmarlas después.
DROP TEMPORARY TABLE IF EXISTS tmp_070_afectadas;
CREATE TEMPORARY TABLE tmp_070_afectadas AS
    SELECT DISTINCT matricula_id, periodo_id
      FROM asistencia_incidencias
     WHERE tipo IN ('FJ','TJ') AND motivo_id IS NULL;

SELECT x.id, x.matricula_id, x.periodo_id, x.fecha, x.tipo AS tipo_antes,
       IF(x.tipo = 'FJ', 'F', 'T') AS tipo_despues
  FROM asistencia_incidencias x
 WHERE x.tipo IN ('FJ','TJ') AND x.motivo_id IS NULL;

UPDATE asistencia_incidencias
   SET tipo = IF(tipo = 'FJ', 'F', 'T'), modificado_en = NOW()
 WHERE tipo IN ('FJ','TJ') AND motivo_id IS NULL;

-- 3b) Recuento (mismo conteo que AsistenciaModel::recalcularContadores) y
--     desconfirmación: los contadores SIEMPRE son el conteo de las fechas.
UPDATE inasistencias i
  INNER JOIN tmp_070_afectadas a ON a.matricula_id = i.matricula_id AND a.periodo_id = i.periodo_id
  INNER JOIN (
        SELECT x.matricula_id, x.periodo_id,
               SUM(x.tipo = 'F') AS f, SUM(x.tipo = 'FJ') AS fj,
               SUM(x.tipo = 'T') AS t, SUM(x.tipo = 'TJ') AS tj
          FROM asistencia_incidencias x
         GROUP BY x.matricula_id, x.periodo_id
  ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
   SET i.faltas = c.f, i.faltas_justificadas = c.fj,
       i.tardanzas = c.t, i.tardanzas_justificadas = c.tj,
       i.modificado_en = NOW(), i.confirmado_en = NULL, i.confirmado_por = NULL
 WHERE i.extraordinaria = 0;

DROP TEMPORARY TABLE IF EXISTS tmp_070_afectadas;

-- 4) FJ/TJ SIEMPRE con motivo (el CHECK de la 069 ya impide motivo en F/T).
SET @falta_chk := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_incidencias'
                     AND CONSTRAINT_NAME = 'chk_justificada_con_motivo');
SET @sql := IF(@falta_chk = 1,
    'ALTER TABLE asistencia_incidencias ADD CONSTRAINT chk_justificada_con_motivo CHECK (tipo IN (''F'',''T'') OR motivo_id IS NOT NULL)',
    'SELECT "070: chk_justificada_con_motivo ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Relleno: lista 'migracion' en cada lunes a viernes YA TRANSCURRIDO (hasta
--    ayer) de cada bimestre por fechas, para cada sección de su año. INSERT IGNORE
--    por la clave única: re-ejecutar no duplica ni pisa listas reales.
INSERT IGNORE INTO asistencia_jornadas (seccion_id, periodo_id, fecha, origen, tomada_por)
WITH RECURSIVE dias AS (
    SELECT p.id AS periodo_id, p.anio_id, p.fecha_inicio AS fecha,
           LEAST(p.fecha_fin, CURDATE() - INTERVAL 1 DAY) AS tope
      FROM periodos p
     WHERE p.asistencia_por_fechas = 1
       AND p.fecha_inicio <= CURDATE() - INTERVAL 1 DAY
    UNION ALL
    SELECT d.periodo_id, d.anio_id, d.fecha + INTERVAL 1 DAY, d.tope
      FROM dias d
     WHERE d.fecha < d.tope
)
SELECT s.id, d.periodo_id, d.fecha, 'migracion', NULL
  FROM dias d
  INNER JOIN secciones s ON s.anio_id = d.anio_id
 WHERE WEEKDAY(d.fecha) < 5;

-- 6) Verificación.
--    a) Listas por periodo y origen.
SELECT periodo_id, origen, COUNT(*) AS listas, MIN(fecha) AS desde, MAX(fecha) AS hasta
  FROM asistencia_jornadas GROUP BY periodo_id, origen ORDER BY periodo_id, origen;
--    b) Debe dar 0: justificadas sin motivo.
SELECT COUNT(*) AS justificadas_sin_motivo
  FROM asistencia_incidencias WHERE tipo IN ('FJ','TJ') AND motivo_id IS NULL;
--    c) Debe dar 0: contadores distintos del conteo de fechas (bimestres por fechas).
SELECT COUNT(*) AS contadores_incoherentes
  FROM inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
  LEFT JOIN (
        SELECT matricula_id, periodo_id,
               SUM(tipo = 'F') f, SUM(tipo = 'FJ') fj, SUM(tipo = 'T') t, SUM(tipo = 'TJ') tj
          FROM asistencia_incidencias GROUP BY matricula_id, periodo_id
  ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
 WHERE i.extraordinaria = 0
   AND (i.faltas <> COALESCE(c.f, 0) OR i.faltas_justificadas <> COALESCE(c.fj, 0)
     OR i.tardanzas <> COALESCE(c.t, 0) OR i.tardanzas_justificadas <> COALESCE(c.tj, 0));
