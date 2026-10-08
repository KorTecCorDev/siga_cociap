-- ============================================================
-- reparar_retorno_1_tramo.sql — corrección puntual del RETORNO #1 (05/10/2026)
-- Estudiante: BALTAZAR PINTO (oficial 190 en 2.° B, operativa 692 en 1.° B).
--
-- QUÉ PASÓ. En producción el retorno #1 se revirtió con el código ANTERIOR al
-- deploy del 05/10/2026 (después de aplicar la 073 y antes del push). Ese código
-- no conoce el tramo ni mueve el bimestre en curso, así que dejó:
--   - periodo_hasta_id = NULL → tramo VACÍO: el II se lee de la 190, que no tiene
--     notas del II, y la boleta pierde el bimestre entero;
--   - la asistencia del III en la 692, fuera de todo roster.
-- Un primer intento a mano por phpMyAdmin no quedó guardado (la transacción no
-- llegó al COMMIT en la misma conexión).
--
-- QUÉ HACE: exactamente lo que hace `RetornoGradoController::revertir()` hoy:
--   1. cierra el tramo en el último bimestre CERRADO desde «desde» (II);
--   2. devuelve a la 190 la asistencia y la conducta de los bimestres SIN cerrar,
--      «como borrador» (lo registró 1.° B cuando ella ya estaba en 2.° B).
--
-- Guardas medidas antes sobre la copia de producción del 05/10/2026: la 692 sin
-- evaluación en el III, la 190 sin filas propias del III/IV, sin cierres vigentes.
--
-- SEGUNDO CASO (08/10/2026, BD local de casa). Allí el retorno ya estaba revertido
-- con el código viejo cuando se aplicó la 073, y el relleno de la 073 tomó «el
-- último bimestre con cualquier dato en la operativa»: una fila de asistencia del
-- III (en ceros) dejó periodo_hasta_id = 3, un bimestre SIN CERRAR. Efecto: en el
-- III salía en 1.° B (asistencia, conducta y calificaciones) y faltaba en 2.° B.
-- El paso 1 corrige también ese 3 (revertir() nunca lo pondría: usa el último
-- CERRADO). Guardas medidas igual el 08/10: todo en cero. Lo detecta desde ese día
-- `verif_reversion_retorno.php` (tramo de un revertido en un bimestre sin cerrar).
--
-- IDEMPOTENTE: ejecutarlo dos veces no cambia nada. El paso «borrador» actúa sobre
-- las filas de la 692 ANTES de moverlas, así que nunca desconfirma lo que la
-- auxiliar de 2.° B confirme después.
--
-- phpMyAdmin: ejecutar TODO el archivo en UN SOLO envío (pestaña SQL o Importar).
-- Periodos 2026: id 2 = II Bimestre, id 3 = III, id 4 = IV.
-- ============================================================

START TRANSACTION;

-- 1) Fin del tramo: II Bimestre. Si quedó vacío por el código viejo (producción)
--    o en el III sin cerrar por el relleno de la 073 (BD local, 08/10/2026).
UPDATE retornos_grado
SET periodo_hasta_id = 2
WHERE id = 1
  AND estado = 'revertido'
  AND matricula_oficial_id = 190
  AND matricula_operativa_id = 692
  AND (periodo_hasta_id IS NULL OR periodo_hasta_id = 3);

-- 2) «Como borrador», sobre lo que todavía está en la 692 (III y IV).
DELETE FROM conducta_confirmaciones WHERE matricula_id = 692 AND periodo_id IN (3, 4);
UPDATE inasistencias SET confirmado_en = NULL, confirmado_por = NULL
WHERE matricula_id = 692 AND periodo_id IN (3, 4);

-- 3) Mover a la oficial los bimestres sin cerrar.
UPDATE inasistencias           SET matricula_id = 190 WHERE matricula_id = 692 AND periodo_id IN (3, 4);
UPDATE asistencia_incidencias  SET matricula_id = 190 WHERE matricula_id = 692 AND periodo_id IN (3, 4);
UPDATE conducta_respuestas     SET matricula_id = 190 WHERE matricula_id = 692 AND periodo_id IN (3, 4);
UPDATE conducta_confirmaciones SET matricula_id = 190 WHERE matricula_id = 692 AND periodo_id IN (3, 4);
UPDATE calificaciones_conducta SET matricula_id = 190 WHERE matricula_id = 692 AND periodo_id IN (3, 4);

COMMIT;

-- 4) Comprobación (después del COMMIT): hasta = 2 y nada del III/IV en la 692.
SELECT r.estado, r.periodo_desde_id, r.periodo_hasta_id,
       (SELECT COUNT(*) FROM inasistencias          WHERE matricula_id = 692 AND periodo_id IN (3, 4))
     + (SELECT COUNT(*) FROM asistencia_incidencias WHERE matricula_id = 692 AND periodo_id IN (3, 4))
     + (SELECT COUNT(*) FROM conducta_respuestas    WHERE matricula_id = 692 AND periodo_id IN (3, 4)) AS quedan_en_692,
       (SELECT COUNT(*) FROM inasistencias WHERE matricula_id = 190 AND periodo_id = 3) AS asistencia_III_en_190
FROM retornos_grado r WHERE r.id = 1;
