-- ============================================================
-- 074_borradores_formulario.sql
-- BORRADORES de los formularios de carga manual (autoguardado).
--
-- CONTEXTO (06/10/2026). Registro Académico transcribe notas desde boletas
-- físicas y puede pasar horas en un formulario. Se autoguarda lo tecleado en
-- esta tabla, POR USUARIO, para recuperarlo al volver (otro equipo, navegador
-- cerrado, sesión vencida).
--
-- 🔴 REGLA CENTRAL: un borrador NUNCA toca las tablas oficiales. Guardar una
-- extraordinaria asegura el bloqueo y la nota sale en boleta y SIAGIE al
-- instante; por eso lo tecleado vive AQUÍ hasta que se pulsa «Guardar». Se
-- elimina SOLO cuando ese guardado oficial sale bien (decisión del usuario:
-- sin botón «Descartar», sin vencimiento por días).
--
--   usuario_id    dueño del borrador (solo él lo ve; CASCADE al borrar usuario)
--   matricula_id  estudiante del formulario (CASCADE: datos de menores, Ley
--                 29733 — no quedan borradores huérfanos)
--   tipo          formulario (lista blanca en BorradorModel::TIPOS)
--   clave         contexto armado SIEMPRE por BorradorModel::clave() con
--                 enteros (matrícula, periodo, carga, competencia)
--   datos         JSON de los campos (tope en BorradorModel::MAX_BYTES)
--   revision      contador para detectar dos pestañas/equipos pisándose (409)
--
-- Idempotente: se puede correr dos veces.
-- ============================================================

CREATE TABLE IF NOT EXISTS borradores_formulario (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id     INT UNSIGNED NOT NULL,
    matricula_id   INT UNSIGNED NOT NULL,
    tipo           VARCHAR(40)  NOT NULL,
    clave          VARCHAR(120) NOT NULL,
    datos          MEDIUMTEXT   NOT NULL,
    revision       INT UNSIGNED NOT NULL DEFAULT 1,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_borrador_usuario (usuario_id, tipo, clave),
    KEY idx_borrador_contexto (tipo, clave),
    KEY idx_borrador_matricula (matricula_id),
    CONSTRAINT fk_borrador_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id)   ON DELETE CASCADE,
    CONSTRAINT fk_borrador_matricula FOREIGN KEY (matricula_id) REFERENCES matriculas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
