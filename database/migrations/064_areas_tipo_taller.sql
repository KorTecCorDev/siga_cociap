-- ============================================================
-- 064_areas_tipo_taller.sql
-- Los TALLERES pasan a ser una categoria propia de area.
--
-- POR QUE (decision del usuario, 24/09/2026)
--   Hasta hoy los dos talleres de secundaria estaban guardados como
--   `area_curso`, igual que un area curricular. Pero no son lo mismo: la
--   UGEL de Huaraz NO aprobo los talleres del colegio, asi que no existen en
--   el plan de estudios del SIAGIE (no tienen hoja en el RegNotas ni se
--   pueden registrar sus notas) y el SIAGIE calcula la situacion final
--   (PRO/RR/PER) sin ellos. La RVM 094-2020 (5.1.3 p. 11) solo cuenta los
--   talleres que forman parte del plan registrado.
--
-- QUE CAMBIA
--   · `areas.tipo` admite 'taller'.
--   · Los dos talleres pasan a 'taller'. Se identifican por NOMBRE, nunca por
--     id: los ids difieren entre entornos.
--   · Tabla nueva `talleres_aprobacion`: la aprobacion de la UGEL POR AÑO Y
--     POR GRADO (decision del usuario, 24/09/2026). Este año no se aprobo,
--     pero el siguiente puede aprobarse, y la UGEL puede aprobar un taller
--     solo para algunos grados. NO es una columna de `areas` porque las areas
--     son un catalogo SIN año y la situacion final se calcula EN VIVO:
--     marcar aprobado en 2027 reescribiria los informes de 2026.
--     Sin fila (o con `aprobado = 0`) el taller NO cuenta para la situacion
--     final de ese año y grado. Se marca en Curriculo, al editar el taller.
--     El grado que se mira es el de la matricula OFICIAL (retorno de grado).
--   · Tabla nueva `talleres_resolucion`: la Resolucion Directoral DEL COLEGIO
--     que crea el taller, una por taller y año (numero y fecha). Es el sustento
--     del colegio, no la aprobacion de la UGEL.
--
-- QUE NO CAMBIA
--   Todo el codigo excluye areas en NEGATIVO (`tipo NOT IN ('transversal',
--   'tutoria')`) y la estructura solo pregunta por 'con_subareas'. Un taller
--   sigue entrando igual que antes en boleta, orden de merito, cargas y
--   transversales. Solo la situacion final (`SituacionFinalModel`) mira la
--   aprobacion.
--
-- Idempotente: el MODIFY repite la lista completa, el UPDATE es estable y la
-- tabla se crea solo si no existe. Nace VACIA: ningun taller aprobado en 2026.
-- ============================================================

ALTER TABLE areas
    MODIFY tipo ENUM('area_curso','con_subareas','transversal','tutoria','taller')
    NOT NULL;

UPDATE areas a
INNER JOIN niveles n ON n.id = a.nivel_id
SET a.tipo = 'taller'
WHERE n.codigo = 'sec'
  AND a.nombre IN ('Taller de Razonamiento Matemático', 'Taller de Pre-Cálculo');

-- Aprobacion de la UGEL por taller, año y grado. `aprobado` se conserva en 0
-- (en vez de borrar la fila) para que quede quien la retiro y cuando.
CREATE TABLE IF NOT EXISTS talleres_aprobacion (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    area_id         SMALLINT UNSIGNED NOT NULL,
    anio_id         SMALLINT UNSIGNED NOT NULL,
    grado_id        TINYINT UNSIGNED  NOT NULL,
    aprobado        TINYINT(1)        NOT NULL DEFAULT 0,
    actualizado_por INT UNSIGNED      NOT NULL,
    actualizado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_taller_anio_grado (area_id, anio_id, grado_id),
    KEY idx_anio (anio_id),
    CONSTRAINT fk_taprob_area  FOREIGN KEY (area_id)         REFERENCES areas (id),
    CONSTRAINT fk_taprob_anio  FOREIGN KEY (anio_id)         REFERENCES anios_academicos (id),
    CONSTRAINT fk_taprob_grado FOREIGN KEY (grado_id)        REFERENCES grados (id),
    CONSTRAINT fk_taprob_user  FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resolucion Directoral DEL COLEGIO que crea el taller: UNA por taller y año
-- (decision del usuario, 24/09/2026). Es el SUSTENTO del colegio; lo que hace
-- que el taller cuente para la situacion final es la aprobacion de la UGEL de
-- `talleres_aprobacion`, grado por grado. Nace con numero y fecha; el cuerpo
-- de la resolucion se trabajara despues.
CREATE TABLE IF NOT EXISTS talleres_resolucion (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    area_id         SMALLINT UNSIGNED NOT NULL,
    anio_id         SMALLINT UNSIGNED NOT NULL,
    numero          VARCHAR(60)       NOT NULL COMMENT 'N.o de la Resolucion Directoral del colegio',
    fecha           DATE              NULL,
    actualizado_por INT UNSIGNED      NOT NULL,
    actualizado_en  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_taller_anio (area_id, anio_id),
    KEY idx_anio (anio_id),
    CONSTRAINT fk_tres_area FOREIGN KEY (area_id)         REFERENCES areas (id),
    CONSTRAINT fk_tres_anio FOREIGN KEY (anio_id)         REFERENCES anios_academicos (id),
    CONSTRAINT fk_tres_user FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
