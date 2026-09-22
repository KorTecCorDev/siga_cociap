-- ============================================================
-- 058_notificaciones.sql
-- Modulo de NOTIFICACIONES: bandeja interna por usuario.
--
-- Nace para un caso concreto y crece a caso general:
--   * SISTEMA: cuando Registro Academico registra las notas que un
--     estudiante trae de su colegio de origen, los docentes con carga
--     en su seccion tienen que enterarse. Esas notas NO van a la boleta
--     (regla del colegio), asi que sin un aviso el docente no sabria
--     nunca que existen.
--   * COMUNICADO: mensajes que admin / registro academico redactan a
--     los docentes (o a una seccion concreta).
--
-- POR QUE UNA TABLA NUEVA Y NO `alertas`:
--   `alertas` ya existe pero va TUTOR -> PADRE (tiene tutor_id y
--   matricula_id, y solo la lee /padre/alertas). Nadie le escribe y
--   tiene 0 filas. Es otra direccion y otro publico: se deja intacta.
--
-- `tipo` es VARCHAR y NO un ENUM a proposito: la lista cerrada vive en
-- PHP (NotificacionModel::TIPOS), asi que anadir un tipo nuevo no
-- exige una migracion. Mismo patron que los motivos de omision.
--
-- `leida_en` es DATETIME NULL en vez de un booleano: guarda ademas
-- CUANDO se leyo, que es gratis y sirve para auditar.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS, re-ejecutable sin dano.
-- ============================================================

CREATE TABLE IF NOT EXISTS notificaciones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id  INT UNSIGNED NOT NULL COMMENT 'destinatario',
    tipo        VARCHAR(40)  NOT NULL COMMENT 'lista cerrada en NotificacionModel::TIPOS',
    origen      ENUM('sistema','comunicado') NOT NULL DEFAULT 'sistema',
    titulo      VARCHAR(150) NOT NULL,
    mensaje     TEXT         NOT NULL,
    enlace      VARCHAR(255) DEFAULT NULL COMMENT 'ruta relativa a la que lleva la notificacion',
    emisor_id   INT UNSIGNED DEFAULT NULL COMMENT 'quien redacto el comunicado; NULL si la genero el sistema',
    leida_en    DATETIME     DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bandeja (usuario_id, leida_en, created_at),
    KEY idx_emisor (emisor_id),
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_notif_emisor  FOREIGN KEY (emisor_id)  REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
