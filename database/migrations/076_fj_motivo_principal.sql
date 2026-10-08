-- ============================================================
-- 076_fj_motivo_principal.sql
-- UNA FALTA SOLO ES JUSTIFICADA CON EL MOTIVO PRINCIPAL (el «ancla»).
--
-- CONTEXTO (08/10/2026, regla del colegio y decisiones del usuario; ver
-- docs/modulos/confirmacion-y-asistencia-por-fechas.md, SÉPTIMA RONDA). En los
-- bimestres por fechas, una FJ cuenta en `faltas_justificadas` SOLO si su motivo
-- es el MOTIVO PRINCIPAL. Con cualquier otro (p. ej. la verbal) cuenta en
-- `faltas`. Las TARDANZAS no cambian: TJ suma todos los motivos. La MARCA del día
-- no se toca (sigue siendo FJ con su motivo).
--
--   asistencia_motivos.es_principal   el ANCLA: 1 en un único motivo, NULL en el
--                                     resto. UNIQUE = nunca dos anclas; el CHECK
--                                     impide que el ancla esté retirada. Que
--                                     siempre exista UNA lo garantiza el modelo
--                                     (`AsistenciaMotivoModel::hacerPrincipal` la
--                                     TRASLADA; no hay acción que la quite). Se
--                                     siembra en «Justificación escrita autorizada».
--   periodos.asistencia_motivo_principal_id
--                                     el ancla CONGELADA del bimestre al CERRARLO
--                                     (inmutable, como el tutor de la 071). NULL =
--                                     bimestre aún no cerrado: sigue al ancla del
--                                     catálogo. Cambiar el ancla NUNCA recuenta un
--                                     bimestre congelado: sus boletas no cambian.
--
-- Desde el código la regla la aplica el PUNTO ÚNICO
-- `AsistenciaModel::sqlConteoContadores` con `AsistenciaMotivoModel::anclaDelPeriodo`.
-- Esta migración RECUENTA lo ya guardado con la misma regla, SIN DESCONFIRMAR
-- (decisión del usuario: las marcas no cambian, solo dónde se cuentan). Solo
-- periodos con `asistencia_por_fechas = 1` y filas `extraordinaria = 0`.
--
-- ⚠️ EN PRODUCCIÓN, ANTES de aplicar:
--   1. Correr el PREVIEW (paso 0, se puede ejecutar suelto): motivos y filas
--      cuyos contadores van a cambiar.
--   2. Comprobar que «Justificación escrita autorizada» NO esté retirada (el
--      CHECK del paso 3 rechazaría el ancla y el import se detendría).
--   3. Comprobar si el bimestre afectado YA ESTÁ PUBLICADO para algún nivel:
--        SELECT * FROM periodos_publicacion WHERE periodo_id = <id del III>;
--      Si lo está, la boleta ya publicada CAMBIARÍA sus faltas: DETENERSE y
--      decidir con el usuario.
--
-- Idempotente (columnas, índice, CHECK y FK con guarda; el ancla solo se siembra
-- si no hay ninguna; re-ejecutar deja los mismos contadores).
-- Ejecutar después de 075_cambio_seccion.sql.
-- ============================================================

-- 0) PREVIEW (con el ancla inicial escrita a mano: corre aunque la migración
--    aún no se haya aplicado).
SELECT id, codigo, nombre, orden, retirado_en FROM asistencia_motivos ORDER BY orden, id;

SELECT i.matricula_id, i.periodo_id,
       i.faltas AS f_antes, c.f AS f_despues,
       i.faltas_justificadas AS fj_antes, c.fj AS fj_despues,
       i.confirmado_en IS NOT NULL AS confirmada,
       EXISTS (SELECT 1 FROM matriculas m
                 INNER JOIN cierres_asistencia z ON z.seccion_id = m.seccion_id
                        AND z.periodo_id = i.periodo_id AND z.anulado_en IS NULL
                WHERE m.id = i.matricula_id) AS seccion_bloqueada
  FROM inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
  INNER JOIN (
        SELECT x.matricula_id, x.periodo_id,
               SUM(x.tipo = 'F' OR (x.tipo = 'FJ' AND COALESCE(am.codigo, '') <> 'escrita_autorizada')) AS f,
               SUM(x.tipo = 'FJ' AND COALESCE(am.codigo, '') = 'escrita_autorizada') AS fj
          FROM asistencia_incidencias x
          LEFT JOIN asistencia_motivos am ON am.id = x.motivo_id
         GROUP BY x.matricula_id, x.periodo_id
  ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
 WHERE i.extraordinaria = 0
   AND (i.faltas <> c.f OR i.faltas_justificadas <> c.fj)
 ORDER BY i.periodo_id, i.matricula_id;

-- 1) asistencia_motivos.es_principal
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_motivos'
                 AND COLUMN_NAME = 'es_principal');
SET @sql := IF(@falta = 1,
    'ALTER TABLE asistencia_motivos ADD COLUMN es_principal TINYINT(1) NULL DEFAULT NULL AFTER orden',
    'SELECT "076: es_principal ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) UNIQUE: nunca dos anclas (los NULL no chocan entre sí).
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_motivos'
                 AND INDEX_NAME = 'uq_es_principal');
SET @sql := IF(@falta = 1,
    'ALTER TABLE asistencia_motivos ADD UNIQUE KEY uq_es_principal (es_principal)',
    'SELECT "076: uq_es_principal ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) CHECK: la marca solo vale 1, y el ancla nunca está retirada.
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'asistencia_motivos'
                 AND CONSTRAINT_NAME = 'chk_principal_vigente');
SET @sql := IF(@falta = 1,
    'ALTER TABLE asistencia_motivos ADD CONSTRAINT chk_principal_vigente CHECK (es_principal IS NULL OR (es_principal = 1 AND retirado_en IS NULL))',
    'SELECT "076: chk_principal_vigente ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Sembrar el ancla SOLO si no hay ninguna (re-ejecutar no pisa un cambio
--    hecho después desde la pantalla de Motivos).
SET @hay := (SELECT COUNT(*) FROM asistencia_motivos WHERE es_principal = 1);
UPDATE asistencia_motivos SET es_principal = 1
 WHERE codigo = 'escrita_autorizada' AND @hay = 0;
SET @ancla := (SELECT id FROM asistencia_motivos WHERE es_principal = 1);

-- 5) periodos.asistencia_motivo_principal_id (ancla congelada al cerrar).
SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'periodos'
                 AND COLUMN_NAME = 'asistencia_motivo_principal_id');
SET @sql := IF(@falta = 1,
    'ALTER TABLE periodos ADD COLUMN asistencia_motivo_principal_id SMALLINT UNSIGNED NULL DEFAULT NULL AFTER asistencia_por_fechas',
    'SELECT "076: asistencia_motivo_principal_id ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @falta := (SELECT COUNT(*) = 0 FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'periodos'
                 AND CONSTRAINT_NAME = 'fk_periodo_motivo_principal');
SET @sql := IF(@falta = 1,
    'ALTER TABLE periodos ADD CONSTRAINT fk_periodo_motivo_principal FOREIGN KEY (asistencia_motivo_principal_id) REFERENCES asistencia_motivos (id)',
    'SELECT "076: fk_periodo_motivo_principal ya existe, no se toca" AS aviso');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

--    Los bimestres por fechas YA CERRADOS quedan congelados con el ancla actual
--    (en local no hay ninguno).
UPDATE periodos SET asistencia_motivo_principal_id = @ancla
 WHERE estado = 'cerrado' AND asistencia_por_fechas = 1
   AND asistencia_motivo_principal_id IS NULL AND @ancla IS NOT NULL;

-- 6) RECUENTO con la regla nueva (misma que AsistenciaModel::sqlConteoContadores),
--    con el ancla de CADA bimestre. Sin tocar confirmado_en / confirmado_por.
UPDATE inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
  INNER JOIN (
        SELECT x.matricula_id, x.periodo_id,
               SUM(x.tipo = 'F' OR (x.tipo = 'FJ' AND COALESCE(x.motivo_id, 0) <> COALESCE(pp.asistencia_motivo_principal_id, @ancla, 0))) AS f,
               SUM(x.tipo = 'FJ' AND COALESCE(x.motivo_id, 0) = COALESCE(pp.asistencia_motivo_principal_id, @ancla, 0)) AS fj,
               SUM(x.tipo = 'T')  AS t,
               SUM(x.tipo = 'TJ') AS tj
          FROM asistencia_incidencias x
          INNER JOIN periodos pp ON pp.id = x.periodo_id
         GROUP BY x.matricula_id, x.periodo_id
  ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
   SET i.faltas = c.f, i.faltas_justificadas = c.fj,
       i.tardanzas = c.t, i.tardanzas_justificadas = c.tj,
       i.modificado_en = NOW()
 WHERE i.extraordinaria = 0
   AND (i.faltas <> c.f OR i.faltas_justificadas <> c.fj
     OR i.tardanzas <> c.t OR i.tardanzas_justificadas <> c.tj);

-- 7) Verificación.
--    a) Debe dar 1: exactamente un ancla, vigente.
SELECT COUNT(*) AS anclas_vigentes FROM asistencia_motivos
 WHERE es_principal = 1 AND retirado_en IS NULL;
--    b) Debe dar 0: contadores distintos del conteo con la regla nueva.
SELECT COUNT(*) AS contadores_incoherentes
  FROM inasistencias i
  INNER JOIN periodos p ON p.id = i.periodo_id AND p.asistencia_por_fechas = 1
  LEFT JOIN (
        SELECT x.matricula_id, x.periodo_id,
               SUM(x.tipo = 'F' OR (x.tipo = 'FJ' AND COALESCE(x.motivo_id, 0) <> COALESCE(pp.asistencia_motivo_principal_id, @ancla, 0))) AS f,
               SUM(x.tipo = 'FJ' AND COALESCE(x.motivo_id, 0) = COALESCE(pp.asistencia_motivo_principal_id, @ancla, 0)) AS fj,
               SUM(x.tipo = 'T') AS t, SUM(x.tipo = 'TJ') AS tj
          FROM asistencia_incidencias x
          INNER JOIN periodos pp ON pp.id = x.periodo_id
         GROUP BY x.matricula_id, x.periodo_id
  ) c ON c.matricula_id = i.matricula_id AND c.periodo_id = i.periodo_id
 WHERE i.extraordinaria = 0
   AND (i.faltas <> COALESCE(c.f, 0) OR i.faltas_justificadas <> COALESCE(c.fj, 0)
     OR i.tardanzas <> COALESCE(c.t, 0) OR i.tardanzas_justificadas <> COALESCE(c.tj, 0));

-- 8) Cierre con SHOW y no SELECT FROM information_schema: phpMyAdmin toma la base
-- de la ULTIMA sentencia SELECT del archivo y corre TODO el import en ella (#1044).
-- Ver docs/infraestructura.md, "Migraciones: reglas para que importen en phpMyAdmin".
SHOW COLUMNS FROM asistencia_motivos LIKE 'es_principal';
