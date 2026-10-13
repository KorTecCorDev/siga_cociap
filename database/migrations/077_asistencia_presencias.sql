-- ============================================================
-- 077_asistencia_presencias.sql
-- ✓ ASISTIÓ POR ESTUDIANTE: los dos modos de trabajo del auxiliar.
--
-- CONTEXTO (09/10/2026, decisiones del usuario). Hasta la 070, «✓ asistió» NO era
-- un dato por estudiante: era «la sección tiene su lista del día» + «sin
-- incidencia», y CUALQUIER marca tomaba la lista de toda la sección. Un auxiliar
-- que registra uno por uno no podía marcar a un estudiante sin afirmar que todos
-- los demás asistieron.
--
--   asistencia_presencias   el ✓ de UN estudiante en UN día. Lo escribe el menú de
--                           la celda («✓ Asistió»). Nunca convive con una
--                           incidencia del mismo día (lo garantiza `marcarDia` en
--                           su transacción; lo comprueba
--                           verif_asistencia_presencias.php). Base de los
--                           escaneos del QR de 2027 (origen = 'qr').
--
-- La LISTA DEL DÍA (asistencia_jornadas) se queda y cambia de sentido: «todos los
-- que no tienen marca propia asistieron». Ninguna marca individual la toma.
--
-- NO TOCA DATOS: las listas e incidencias existentes siguen significando lo mismo
-- y ningún contador se recalcula (los contadores solo cuentan F/FJ/T/TJ).
--
-- Idempotente. Ejecutar después de 076_fj_motivo_principal.sql.
-- ⚠️ En producción: aplicar a mano ANTES del merge a main.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS asistencia_presencias (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matricula_id   INT UNSIGNED      NOT NULL,
    periodo_id     SMALLINT UNSIGNED NOT NULL,
    fecha          DATE              NOT NULL,
    origen         ENUM('manual','qr') NOT NULL DEFAULT 'manual',
    registrado_por INT UNSIGNED      NULL DEFAULT NULL,
    registrado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Un ✓ por estudiante y día (como `uq_matricula_fecha` de las incidencias).
    UNIQUE KEY uq_matricula_fecha (matricula_id, fecha),
    KEY idx_matricula_periodo (matricula_id, periodo_id),
    KEY idx_periodo_fecha (periodo_id, fecha),
    KEY idx_registrado_por (registrado_por),
    CONSTRAINT asistencia_presencias_ibfk_1 FOREIGN KEY (matricula_id)   REFERENCES matriculas (id),
    CONSTRAINT asistencia_presencias_ibfk_2 FOREIGN KEY (periodo_id)     REFERENCES periodos   (id),
    CONSTRAINT asistencia_presencias_ibfk_3 FOREIGN KEY (registrado_por) REFERENCES usuarios   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobación (solo lectura): la tabla existe y arranca vacía.
SELECT COUNT(*) AS presencias FROM asistencia_presencias;
