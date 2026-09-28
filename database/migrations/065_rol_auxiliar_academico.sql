-- 065_rol_auxiliar_academico.sql
-- Rol nuevo: Auxiliar académico.
--
-- CONTEXTO (28/09/2026). El auxiliar académico registra, por bimestre y para las
-- secciones que tiene a su cargo, la conducta (criterios Sí/No) y las incidencias
-- de asistencia (F, FJ, T, TJ), y da el «Bloquear y aprobar» de ambos. Hasta hoy
-- ese trabajo lo tecleaba Registro Académico transcribiendo lo que le entregaban
-- los auxiliares; RA lo SIGUE pudiendo hacer, como respaldo, en cualquier sección.
--
-- Las secciones de cada auxiliar NO salen de este rol: viven en
-- `auxiliar_secciones` (migración 066), por bimestre. Un auxiliar sin secciones
-- asignadas no puede registrar nada.
--
-- El control de acceso NO se lista a mano: sale de la constante ROL_AUXILIAR en
-- app/Helpers/helpers.php. Ver docs/modulos/auxiliares.md.
--
-- No toca esquema: es un INSERT de catálogo, igual que la 055. Este INSERT por sí
-- solo no da acceso a nada mientras ningún usuario tenga el rol.
--
-- Idempotente: `roles.codigo` tiene UNIQUE KEY; el NOT EXISTS lo hace re-ejecutable.
-- Ejecutar después de 064_areas_tipo_taller.sql.
--
-- IMPORTANTE: el nombre lleva tilde (académico). Ejecutar con conexión utf8mb4
-- (phpMyAdmin ya lo hace; por CLI usar `mysql --default-character-set=utf8mb4`).

SET NAMES utf8mb4;

INSERT INTO roles (nombre, codigo, descripcion)
SELECT 'Auxiliar académico', 'auxiliar_academico', 'Registra conducta y asistencia de sus secciones'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE codigo = 'auxiliar_academico');

-- Verificación (debe devolver 10 roles y 1 auxiliar_academico):
--   SELECT COUNT(*) AS roles_espera_10,
--          SUM(codigo = 'auxiliar_academico') AS espera_1
--   FROM roles;
