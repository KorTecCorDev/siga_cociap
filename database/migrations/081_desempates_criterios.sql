-- 081_desempates_criterios.sql
-- Criterios aplicados al resolver un empate irreducible del orden de merito.
--
-- Hasta hoy la resolucion guardaba solo un motivo en texto libre: el criterio
-- con el que se decidio (notas por competencia, asistencia, conducta...) no
-- quedaba como dato, y el acta no podia listarlo. Desde el 17/10/2026 quien
-- resuelve MARCA los criterios que aplico y ademas escribe el motivo.
--
-- La lista de criterios validos es FIJA EN CODIGO (decision del usuario,
-- 17/10/2026): `DesempateMeritoModel::CRITERIOS`. Esta tabla solo guarda los
-- marcados; no lleva CHECK con la lista para que cambiarla pida un deploy y no
-- una migracion.
--
-- ADITIVA: no toca desempates_merito ni desempates_merito_orden. Las
-- resoluciones anteriores quedan sin criterios (la pantalla y el acta lo dicen).
-- Re-resolver un grupo reemplaza sus criterios, igual que su orden.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS desempates_merito_criterios (
    desempate_id INT UNSIGNED NOT NULL,
    criterio     VARCHAR(30)  NOT NULL,   -- clave de DesempateMeritoModel::CRITERIOS
    PRIMARY KEY (desempate_id, criterio),
    CONSTRAINT fk_descrit_desempate FOREIGN KEY (desempate_id)
        REFERENCES desempates_merito (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cierre con SHOW y no SELECT FROM information_schema: phpMyAdmin toma la base
-- de la ULTIMA sentencia SELECT del archivo y corre TODO el import en ella (#1044).
-- Ver docs/infraestructura.md, "Migraciones: reglas para que importen en phpMyAdmin".
SHOW COLUMNS FROM desempates_merito_criterios;
