-- DOCUMENTACION DEL ARCHIVO
-- Que hace: Esquema MySQL inicial. Crea tablas de clientes, facturas, items, colas, logs y emisor.
-- Donde se usa: importar una vez en MySQL/XAMPP para crear la base facturacion_sifen.
-- Mas detalle: docs/DOCUMENTACION_TECNICA_COMPLETA.md y docs/GUIA_COMENTARIOS_CODIGO.md.

-- ============================================================
-- SIFEN v150 - BASE DE DATOS COMPLETA
-- Importa este archivo una sola vez en phpMyAdmin
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

DROP TABLE IF EXISTS email_log;
DROP TABLE IF EXISTS email_queue;
DROP TABLE IF EXISTS fe_queue;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS personas;
DROP TABLE IF EXISTS emitter_settings;
DROP TABLE IF EXISTS tipos_documento_identidad;
DROP TABLE IF EXISTS ciudades;
DROP TABLE IF EXISTS departamentos;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- CATÁLOGOS
-- ============================================================

CREATE TABLE departamentos (
  codigo VARCHAR(2)  NOT NULL,
  nombre VARCHAR(60) NOT NULL,
  PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ciudades (
  codigo              VARCHAR(5)  NOT NULL,
  codigo_departamento VARCHAR(2)  NOT NULL,
  nombre              VARCHAR(80) NOT NULL,
  PRIMARY KEY (codigo),
  CONSTRAINT fk_ciudad_dep FOREIGN KEY (codigo_departamento) REFERENCES departamentos(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tipos_documento_identidad (
  codigo     TINYINT     NOT NULL,
  descripcion VARCHAR(50) NOT NULL,
  PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PERSONAS (1FN, 2FN, 3FN)
-- nombre1 y apellido1 son obligatorios
-- nombre2 y apellido2 son opcionales
-- ============================================================

CREATE TABLE personas (
  id                BIGINT       NOT NULL AUTO_INCREMENT,
  nombre1           VARCHAR(60)  NOT NULL,
  nombre2           VARCHAR(60)  NULL,
  apellido1         VARCHAR(60)  NOT NULL,
  apellido2         VARCHAR(60)  NULL,
  tipo_documento_id TINYINT      NOT NULL DEFAULT 1,
  numero_documento  VARCHAR(20)  NOT NULL,
  telefono          VARCHAR(30)  NULL,
  celular           VARCHAR(30)  NULL,
  email             VARCHAR(120) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_persona_doc (tipo_documento_id, numero_documento),
  CONSTRAINT fk_persona_tipo_doc FOREIGN KEY (tipo_documento_id) REFERENCES tipos_documento_identidad(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_personas AS
SELECT
  id,
  TRIM(CONCAT_WS(' ', nombre1, IFNULL(nombre2,''), apellido1, IFNULL(apellido2,''))) AS nombre_completo,
  nombre1, nombre2, apellido1, apellido2,
  tipo_documento_id, numero_documento,
  telefono, celular, email
FROM personas;

-- ============================================================
-- CLIENTES
-- ============================================================

CREATE TABLE customers (
  id                          BIGINT       NOT NULL AUTO_INCREMENT,
  persona_id                  BIGINT       NULL,
  naturaleza_receptor         TINYINT      NOT NULL DEFAULT 1,
  tipo_operacion              TINYINT      NOT NULL DEFAULT 1,
  codigo_pais                 VARCHAR(3)   NOT NULL DEFAULT 'PRY',
  descripcion_pais            VARCHAR(50)  NOT NULL DEFAULT 'Paraguay',
  tipo_contribuyente          TINYINT      NULL,
  ruc                         VARCHAR(8)   NULL,
  dv                          VARCHAR(1)   NULL,
  tipo_documento_identidad_id TINYINT      NULL,
  numero_documento            VARCHAR(20)  NULL,
  nombre_razon_social         VARCHAR(255) NOT NULL,
  nombre_fantasia             VARCHAR(255) NULL,
  direccion                   VARCHAR(255) NULL,
  numero_casa                 VARCHAR(10)  NULL,
  codigo_departamento         VARCHAR(2)   NULL,
  codigo_ciudad               VARCHAR(5)   NULL,
  telefono                    VARCHAR(30)  NULL,
  celular                     VARCHAR(30)  NULL,
  email                       VARCHAR(120) NULL,
  codigo_cliente              VARCHAR(20)  NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_ruc (ruc, dv),
  CONSTRAINT fk_customer_persona     FOREIGN KEY (persona_id)                  REFERENCES personas(id),
  CONSTRAINT fk_customer_tipo_doc_id FOREIGN KEY (tipo_documento_identidad_id) REFERENCES tipos_documento_identidad(codigo),
  CONSTRAINT fk_customer_ciudad      FOREIGN KEY (codigo_ciudad)               REFERENCES ciudades(codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EMISOR
-- ============================================================

CREATE TABLE emitter_settings (
  id                              BIGINT       NOT NULL AUTO_INCREMENT,
  ambiente                        VARCHAR(10)  NOT NULL DEFAULT 'test',
  ruc                             VARCHAR(8)   NOT NULL,
  dv                              VARCHAR(1)   NOT NULL,
  tipo_contribuyente              TINYINT      NOT NULL DEFAULT 2,
  tipo_regimen                    VARCHAR(2)   NULL,
  razon_social                    VARCHAR(255) NOT NULL,
  nombre_fantasia                 VARCHAR(255) NULL,
  direccion                       VARCHAR(255) NOT NULL,
  numero_casa                     VARCHAR(10)  NOT NULL DEFAULT '0',
  codigo_departamento             VARCHAR(2)   NOT NULL,
  descripcion_departamento        VARCHAR(60)  NOT NULL,
  codigo_distrito                 VARCHAR(4)   NULL,
  descripcion_distrito            VARCHAR(60)  NULL,
  codigo_ciudad                   VARCHAR(5)   NOT NULL,
  descripcion_ciudad              VARCHAR(80)  NOT NULL,
  telefono                        VARCHAR(50)  NOT NULL,
  email                           VARCHAR(120) NOT NULL,
  codigo_actividad_economica      VARCHAR(8)   NOT NULL,
  descripcion_actividad_economica VARCHAR(255) NOT NULL,
  timbrado_numero                 VARCHAR(8)   NOT NULL,
  timbrado_inicio                 DATE         NOT NULL,
  timbrado_fin                    DATE         NOT NULL,
  establecimiento                 VARCHAR(3)   NOT NULL DEFAULT '001',
  punto                           VARCHAR(3)   NOT NULL DEFAULT '001',
  id_csc                          VARCHAR(4)   NULL,
  csc                             VARCHAR(64)  NULL,
  info_adicional_kude             TEXT         NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FACTURAS
-- ============================================================

CREATE TABLE invoices (
  id                              BIGINT        NOT NULL AUTO_INCREMENT,
  customer_id                     BIGINT        NOT NULL,
  tipo_documento                  TINYINT       NOT NULL DEFAULT 1,
  descripcion_tipo_documento      VARCHAR(40)   NOT NULL DEFAULT 'Factura electrónica',
  numero                          VARCHAR(7)    NOT NULL,
  serie                           VARCHAR(2)    NULL,
  establecimiento                 VARCHAR(3)    NOT NULL DEFAULT '001',
  punto                           VARCHAR(3)    NOT NULL DEFAULT '001',
  fecha_emision                   DATETIME      NOT NULL,
  fecha_firma                     DATETIME      NULL,
  tipo_emision                    TINYINT       NOT NULL DEFAULT 1,
  descripcion_tipo_emision        VARCHAR(20)   NOT NULL DEFAULT 'Normal',
  tipo_transaccion                TINYINT       NOT NULL DEFAULT 2,
  descripcion_tipo_transaccion    VARCHAR(40)   NOT NULL DEFAULT 'Prestación de servicios',
  tipo_impuesto                   TINYINT       NOT NULL DEFAULT 1,
  descripcion_tipo_impuesto       VARCHAR(20)   NOT NULL DEFAULT 'IVA',
  moneda                          VARCHAR(3)    NOT NULL DEFAULT 'PYG',
  descripcion_moneda              VARCHAR(20)   NOT NULL DEFAULT 'Guaraní',
  condicion_operacion             TINYINT       NOT NULL DEFAULT 1,
  descripcion_condicion_operacion VARCHAR(20)   NOT NULL DEFAULT 'Contado',
  indicador_presencia             TINYINT       NOT NULL DEFAULT 1,
  descripcion_indicador_presencia VARCHAR(30)   NOT NULL DEFAULT 'Operación presencial',
  descripcion                     VARCHAR(255)  NULL,
  subtotal_exenta    DECIMAL(18,8) NOT NULL DEFAULT 0,
  subtotal_exonerada DECIMAL(18,8) NOT NULL DEFAULT 0,
  subtotal_5         DECIMAL(18,8) NOT NULL DEFAULT 0,
  subtotal_10        DECIMAL(18,8) NOT NULL DEFAULT 0,
  total_descuento    DECIMAL(18,8) NOT NULL DEFAULT 0,
  total_anticipo     DECIMAL(18,8) NOT NULL DEFAULT 0,
  redondeo           DECIMAL(18,8) NOT NULL DEFAULT 0,
  total_neto         DECIMAL(18,8) NOT NULL DEFAULT 0,
  iva_5              DECIMAL(18,8) NOT NULL DEFAULT 0,
  iva_10             DECIMAL(18,8) NOT NULL DEFAULT 0,
  total_iva          DECIMAL(18,8) NOT NULL DEFAULT 0,
  base_gravada_5     DECIMAL(18,8) NOT NULL DEFAULT 0,
  base_gravada_10    DECIMAL(18,8) NOT NULL DEFAULT 0,
  total_base_gravada DECIMAL(18,8) NOT NULL DEFAULT 0,
  total              DECIMAL(18,8) NOT NULL DEFAULT 0,
  estado_pago  VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
  fe_emitida   TINYINT(1)   NOT NULL DEFAULT 0,
  fe_estado    VARCHAR(30)  NULL,
  fe_cdc       VARCHAR(44)  NULL,
  fe_xml_path  VARCHAR(255) NULL,
  fe_kude_path VARCHAR(255) NULL,
  fe_error     TEXT         NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ÍTEMS
-- ============================================================

CREATE TABLE invoice_items (
  id                         BIGINT        NOT NULL AUTO_INCREMENT,
  invoice_id                 BIGINT        NOT NULL,
  codigo                     VARCHAR(20)   NOT NULL,
  descripcion                VARCHAR(255)  NOT NULL,
  unidad_codigo              VARCHAR(5)    NOT NULL DEFAULT '77',
  unidad_descripcion         VARCHAR(10)   NOT NULL DEFAULT 'UNI',
  cantidad                   DECIMAL(18,8) NOT NULL,
  precio_unitario            DECIMAL(18,8) NOT NULL,
  descuento_item             DECIMAL(18,8) NOT NULL DEFAULT 0,
  descuento_global_item      DECIMAL(18,8) NOT NULL DEFAULT 0,
  anticipo_item              DECIMAL(18,8) NOT NULL DEFAULT 0,
  anticipo_global_item       DECIMAL(18,8) NOT NULL DEFAULT 0,
  afectacion_iva             TINYINT       NOT NULL DEFAULT 1,
  descripcion_afectacion_iva VARCHAR(30)   NOT NULL DEFAULT 'Gravado IVA',
  proporcion_iva             DECIMAL(8,4)  NOT NULL DEFAULT 100,
  tasa_iva                   DECIMAL(5,2)  NOT NULL DEFAULT 10,
  total_linea                DECIMAL(18,8) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PAGOS
-- ============================================================

CREATE TABLE payments (
  id                    BIGINT        NOT NULL AUTO_INCREMENT,
  invoice_id            BIGINT        NOT NULL,
  tipo_pago             TINYINT       NOT NULL DEFAULT 1,
  descripcion_tipo_pago VARCHAR(30)   NOT NULL DEFAULT 'Efectivo',
  monto                 DECIMAL(18,8) NOT NULL,
  moneda                VARCHAR(3)    NOT NULL DEFAULT 'PYG',
  descripcion_moneda    VARCHAR(20)   NOT NULL DEFAULT 'Guaraní',
  tipo_cambio           DECIMAL(18,8) NULL,
  fecha_pago            DATETIME      NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- COLA FE
-- ============================================================

CREATE TABLE fe_queue (
  id               BIGINT       NOT NULL AUTO_INCREMENT,
  invoice_id       BIGINT       NOT NULL,
  estado           VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
  intentos         INT          NOT NULL DEFAULT 0,
  ultimo_error     TEXT         NULL,
  cdc              VARCHAR(44)  NULL,
  xml_path         VARCHAR(255) NULL,
  kude_path        VARCHAR(255) NULL,
  qr_text          TEXT         NULL,
  sifen_track_id   VARCHAR(100) NULL,
  sifen_respuesta  JSON         NULL,
  payload_json     JSON         NULL,
  fecha_creacion   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_proceso    DATETIME     NULL,
  fecha_aprobacion DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fe_queue_invoice (invoice_id),
  CONSTRAINT fk_fe_queue_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- COLA CORREOS
-- ============================================================

CREATE TABLE email_queue (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  invoice_id    BIGINT       NOT NULL,
  cliente_email VARCHAR(120) NOT NULL,
  tipo          VARCHAR(40)  NOT NULL,
  asunto        VARCHAR(255) NOT NULL,
  cuerpo_html   MEDIUMTEXT   NOT NULL,
  adjunto_1     VARCHAR(255) NULL,
  adjunto_2     VARCHAR(255) NULL,
  adjunto_3     VARCHAR(255) NULL,
  estado        VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
  intentos      INT          NOT NULL DEFAULT 0,
  ultimo_error  TEXT         NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME     NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_email_queue_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LOG CORREOS
-- ============================================================

CREATE TABLE email_log (
  id           BIGINT       NOT NULL AUTO_INCREMENT,
  invoice_id   BIGINT       NOT NULL,
  destinatario VARCHAR(120) NOT NULL,
  asunto       VARCHAR(255) NOT NULL,
  transport    VARCHAR(20)  NOT NULL,
  artefacto    VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_email_log_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRIGGERS
-- ============================================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_invoices_ai_enqueue_fe $$
CREATE TRIGGER trg_invoices_ai_enqueue_fe
AFTER INSERT ON invoices FOR EACH ROW
BEGIN
  IF NEW.estado_pago = 'PAGADO' AND NEW.fe_emitida = 0 THEN
    INSERT IGNORE INTO fe_queue (invoice_id) VALUES (NEW.id);
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_invoices_au_enqueue_fe $$
CREATE TRIGGER trg_invoices_au_enqueue_fe
AFTER UPDATE ON invoices FOR EACH ROW
BEGIN
  IF NEW.estado_pago = 'PAGADO' AND OLD.estado_pago <> 'PAGADO' AND NEW.fe_emitida = 0 THEN
    INSERT IGNORE INTO fe_queue (invoice_id) VALUES (NEW.id);
  END IF;
END $$

DELIMITER ;

-- ============================================================
-- DATOS INICIALES — CATÁLOGOS
-- ============================================================

INSERT INTO tipos_documento_identidad (codigo, descripcion) VALUES
  (1,'Cédula paraguaya'),(2,'Pasaporte'),(3,'Cédula extranjera'),
  (4,'Carnet de residencia'),(5,'Innominado'),(6,'Tarjeta diplomática');

INSERT INTO departamentos (codigo, nombre) VALUES
  ('01','Concepción'),('02','San Pedro'),('03','Cordillera'),
  ('04','Guairá'),('05','Caaguazú'),('06','Caazapá'),
  ('07','Itapúa'),('08','Misiones'),('09','Paraguarí'),
  ('10','Alto Paraná'),('11','CAPITAL'),('12','Ñeembucú'),
  ('13','Amambay'),('14','Canindeyú'),('15','Presidente Hayes'),
  ('16','Boquerón'),('17','Alto Paraguay');

INSERT INTO ciudades (codigo, codigo_departamento, nombre) VALUES
  ('1','11','ASUNCION'),('2','03','CAACUPE'),('3','02','SAN PEDRO'),
  ('4','01','CONCEPCION'),('5','07','ENCARNACION'),('6','10','CIUDAD DEL ESTE'),
  ('7','13','PEDRO JUAN CABALLERO'),('8','05','CORONEL OVIEDO'),
  ('9','04','VILLARRICA'),('10','08','SAN JUAN BAUTISTA'),
  ('11','11','LUQUE'),('12','11','SAN LORENZO'),
  ('13','11','FERNANDO DE LA MORA'),('14','11','LAMBARE'),
  ('15','11','CAPIATA');

-- ============================================================
-- DATOS DEL EMISOR (tus datos reales de empresa van aquí)
-- ============================================================

INSERT INTO emitter_settings (
  ambiente, ruc, dv, tipo_contribuyente, tipo_regimen,
  razon_social, nombre_fantasia,
  direccion, numero_casa,
  codigo_departamento, descripcion_departamento,
  codigo_ciudad, descripcion_ciudad,
  telefono, email,
  codigo_actividad_economica, descripcion_actividad_economica,
  timbrado_numero, timbrado_inicio, timbrado_fin,
  establecimiento, punto,
  id_csc, csc,
  info_adicional_kude
) VALUES (
  'test', '80012345', '6', 2, '1',
  'Vieloy Sistemas', 'Vieloy Sistemas',
  'Av. España', '1234',
  '11', 'CAPITAL',
  '1', 'ASUNCION',
  '021 123456', 'facturacion@example.test',
  '62010', 'SERVICIOS INFORMÁTICOS',
  '12345678', '2026-01-01', '2026-12-31',
  '001', '001',
  '0001', 'ABCD0000000000000000000000000001',
  'Documento de prueba - sin valor fiscal'
);

-- ============================================================
-- CLIENTE DE EJEMPLO
-- ============================================================

INSERT INTO personas (nombre1, nombre2, apellido1, apellido2, tipo_documento_id, numero_documento, telefono, celular, email)
VALUES ('Juan', 'Carlos', 'Pérez', 'González', 1, '5502969', '021 555111', '0981 111222', 'juan.perez@gmail.com');

INSERT INTO customers (
  persona_id, naturaleza_receptor, tipo_operacion, codigo_pais, descripcion_pais,
  tipo_contribuyente, ruc, dv,
  nombre_razon_social, direccion, numero_casa,
  codigo_departamento, codigo_ciudad,
  telefono, celular, email, codigo_cliente
) VALUES (
  1, 1, 1, 'PRY', 'Paraguay',
  2, '5502969', '8',
  'Juan Carlos Pérez González', 'Av. San Martín', '456',
  '11', '1',
  '021 555111', '0981 111222', 'juan.perez@gmail.com', 'CLI001'
);

-- ============================================================
-- CANCELACIONES E INUTILIZACIONES (Manual SIFEN cap. 11)
-- Integrado desde 02_cancelaciones.sql — idempotente (IF NOT EXISTS).
-- Sin "USE": se importa sobre la base que elijas en phpMyAdmin.
-- ============================================================

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS cancelado_at           DATETIME     NULL AFTER fe_error,
    ADD COLUMN IF NOT EXISTS motivo_cancelacion     VARCHAR(500) NULL AFTER cancelado_at,
    ADD COLUMN IF NOT EXISTS evento_cancelacion_id  VARCHAR(20)  NULL AFTER motivo_cancelacion,
    ADD COLUMN IF NOT EXISTS sifen_track_id         VARCHAR(50)  NULL AFTER evento_cancelacion_id;

CREATE TABLE IF NOT EXISTS invoice_events (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id        BIGINT NOT NULL,
    tipo              ENUM('CANCELACION','INUTILIZACION') NOT NULL,
    cdc_afectado      VARCHAR(44) NOT NULL,
    id_evento         VARCHAR(20) NOT NULL,
    motivo            TEXT NOT NULL,
    xml_evento_path   VARCHAR(500) NULL,
    respuesta_sifen   JSON NULL,
    estado_sifen      ENUM('PENDIENTE','APROBADO','RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    codigo_respuesta  VARCHAR(10) NULL,
    track_id          VARCHAR(50) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice (invoice_id),
    INDEX idx_cdc (cdc_afectado),
    INDEX idx_id_evento (id_evento),
    CONSTRAINT fk_invoice_events_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
