-- =====================================================================
--  SPG - SISTEMA DE GESTION PARA PELUQUERIA
--  Base de datos unificada - VARIANTE EN 3FN ESTRICTA
--  MySQL 8.0+ / MariaDB 10.4+ (InnoDB)
--
--  Este script fusiona los dos esquemas previos del proyecto:
--    (A) peluqueria_bd.sql           modelo relacional normalizado
--    (B) peluqueria_bd_completo.sql  modelo derivado del diagrama de clases
--
--  Criterio de fusion
--  ------------------
--  Cuando las dos versiones modelaban lo mismo con nombres distintos se
--  conservo una sola tabla. La nomenclatura base es la de (B), porque es la
--  que ya esta documentada en el DER, en el diagrama de clases y en el
--  diagrama de objetos del TCC. De (A) se incorporo lo que faltaba:
--  facturacion fiscal, fidelizacion de clientes, calificaciones,
--  notificaciones, horarios del personal y el detalle de los pagos con
--  tarjeta o con banco.
--
--  Equivalencias resueltas (tabla de A  ->  tabla final)
--  -----------------------------------------------------
--    perfil                          -> rol
--    Usuarios + funcionarios         -> usuario
--    Personas                        -> se distribuyo en cliente, usuario y proveedor
--    perfil_has_Usuarios             -> usuario.id_rol (1:N)
--    funcionarios_has_Sucursales     -> usuario.id_sucursal (1:N)
--    Turnos                          -> turno_laboral (jornada del personal)
--    Asistencia                      -> asistencia (sin la fecha, ya esta en el turno)
--    niveles                         -> nivel
--    Clientes                        -> cliente
--    Proveedor                       -> proveedor
--    Servicios                       -> servicio
--    promociones                     -> descuento (con vigencia)
--    Servicios_has_promociones       -> servicio_descuento
--    productos                       -> producto
--    Citas                           -> cita (fecha + hora en una sola columna)
--    Citas_has_Servicios             -> cita_servicio
--    Calificaciones                  -> calificacion
--    notificaciones                  -> notificacion + tipo_notificacion
--    Compras                         -> compra
--    productos_has_Compras           -> detalle_compra
--    Movimientos                     -> tipo_movimiento_inventario
--    movimiento_stock (+ _has_prod)  -> movimiento_inventario
--    consumo_servicio                -> servicio_realizado + producto_utilizado
--    tipos_comprobante               -> tipo_comprobante
--    condiciones_venta               -> condicion_venta
--    factura_cab                     -> factura
--    factura_det                     -> detalle_factura
--    MetodoPago                      -> metodo_pago
--    Cobros + pagos                  -> cobro
--    pago_tarjeta                    -> cobro_tarjeta
--    pago_banco                      -> cobro_banco
--    movimientos_puntos              -> movimiento_punto
--    v_factura_totales               -> vw_factura_impuestos
--    v_cobros_total                  -> vw_factura_resumen (columna cobrado)
--
--  Cambios que tocan la documentacion ya entregada del TCC
--  -------------------------------------------------------
--  Las 31 tablas del DER siguen existiendo con el mismo nombre, pero estas
--  decisiones hay que reflejarlas en el DER y en el diagrama de clases:
--
--   1. producto.precio_unitario pasa a llamarse precio_venta, y se agrega
--      precio_costo (que en A era prd_monto_costo).
--   2. detalle_factura.cantidad pasa de INT a DECIMAL(10,2), porque hay
--      productos que se venden fraccionados.
--   3. compra.confirmada (booleano) se reemplaza por id_estado_compra, un
--      catalogo con Pendiente, Confirmada y Anulada.
--   4. movimiento_inventario.tipo ('ENTRADA'/'SALIDA') se reemplaza por
--      id_tipo_movimiento, el catalogo que en A se llamaba Movimientos. El
--      signo del catalogo dice si suma o resta.
--   5. estado_cita suma dos estados: En proceso y Ausente. El segundo permite
--      medir las ausencias, que la entrevista senala como el problema mas
--      frecuente. La columna bloquea_agenda reemplaza a la lista de IDs que
--      antes estaba cableada en la funcion de disponibilidad.
--   6. rol suma Gerente, que el TCC menciona pero el SQL no tenia, y Cliente,
--      para el acceso al portal.
--   7. servicio, producto y detalle_factura suman tasa_iva. Sin eso no se
--      puede discriminar el IVA en un comprobante ni en una nota de credito.
--   8. El descuento por linea (factura_det.det_descuento en A) no se conserva:
--      el descuento vive solo en factura_descuento, para no guardarlo dos veces.
--   9. Personas deja de ser superclase. Sus campos se reparten entre cliente,
--      usuario y proveedor, que es como ya lo modelaba el diagrama de clases.
--  10. Las dos relaciones N:M de A (perfil-Usuarios y funcionarios-Sucursales)
--      pasan a 1:N, porque un usuario tiene un rol y el alcance declara un
--      unico local.
--  11. El cotejamiento pasa de utf8mb4_0900_ai_ci a utf8mb4_unicode_ci: el
--      primero no existe en MariaDB, que es lo que corre en XAMPP.
--  12. sucursal, turno_laboral y asistencia vienen de A y exceden el alcance
--      declarado (un solo local, sin modulo de RRHH). Se dejan porque la
--      sucursal aporta los datos del emisor para la factura, pero conviene
--      decidir si entran al TCC o se quitan.
--
--  Que cambia en esta variante
--  ---------------------------
--  El esquema no guarda ningun dato que se pueda calcular. Se quitaron las 13
--  columnas derivadas y ahora cada valor sale de una funcion o de una vista:
--
--    columna que se quito            de donde sale ahora
--    ----------------------------    -----------------------------------------
--    detalle_factura.subtotal        cantidad * precio_unitario (vista)
--    detalle_compra.subtotal         cantidad * precio_unitario (vista)
--    factura.subtotal                fn_factura_subtotal()
--    factura.descuento_total         fn_factura_descuento()
--    factura.total                   fn_factura_total()
--    factura.fecha_vencimiento       fn_factura_vencimiento()
--    compra.total                    fn_compra_total()
--    producto.stock_actual           fn_producto_stock()
--    cliente.puntos                  fn_cliente_puntos()
--    cliente.id_nivel                fn_cliente_nivel()
--    cita.duracion_min               fn_cita_duracion()
--    caja.monto_final                fn_caja_saldo()
--    pago_personal.monto             fn_pago_personal_monto()
--
--  Ademas:
--    - factura.nro_comprobante era un valor compuesto (001-001-0000001). Se
--      reemplaza por nro_correlativo, que es atomico; el establecimiento y el
--      punto de expedicion ya estaban en timbrado. El numero formateado lo
--      arma fn_factura_nro().
--    - movimiento_caja pierde id_cobro y monto duplicados: ahora el cobro
--      guarda en que caja entro (cobro.id_caja) y movimiento_caja queda solo
--      para los movimientos manuales (retiros, gastos, ingresos varios).
--
--  Lo que si se conserva, porque no es dato derivado sino una foto historica:
--  detalle_factura.precio_unitario, detalle_factura.tasa_iva y
--  factura_descuento.monto_aplicado. Si manana cambia el precio de un servicio
--  o el valor de un descuento, el comprobante viejo no puede cambiar con el.
--
--  El costo de esta variante: leer el stock de un producto obliga a sumar todo
--  su libro de movimientos, y el total de una factura obliga a recorrer su
--  detalle. Con el volumen de un salon no se nota, pero es la contrapartida.
--
--  Modulos agregados sobre la version anterior
--  -------------------------------------------
--  categoria_servicio    el servicio ya tenia catalogo de precio pero no de
--                        tipo, y M8 promete reportes por tipo de servicio
--  usuario_servicio      que servicios sabe hacer cada profesional. Sin esto
--                        se podia agendar una keratina con quien solo corta
--  comision              cuanto le toca a cada profesional por servicio. Antes
--                        el porcentaje se escribia a mano en cada pago
--  ausencia_agenda       vacaciones, licencias, feriados y bloqueos puntuales.
--                        Sin esto la disponibilidad miente apenas alguien pide
--                        franco
--  cobro.id_cita         la sena de la cita. La entrevista marca el ausentismo
--                        como el problema mas frecuente, y la sena es la
--                        palanca del rubro para bajarlo
--  pago_proveedor        M6 dice "Gestion de Proveedores" y hasta ahora el
--                        modelo registraba que se compraba, pero no si se
--                        habia pagado
--
--  Convenciones
--  ------------
--  PK subrogada INT UNSIGNED AUTO_INCREMENT. Catalogos en tablas de busqueda
--  en lugar de ENUM. CHECK para dominios cerrados y montos no negativos.
--  FK con ON UPDATE CASCADE; ON DELETE CASCADE en los detalles, RESTRICT en
--  los maestros y SET NULL en las referencias opcionales. Cotejamiento
--  utf8mb4_unicode_ci por compatibilidad con MariaDB, que es el motor de XAMPP.
-- =====================================================================

DROP DATABASE IF EXISTS peluqueria_bd;
CREATE DATABASE peluqueria_bd
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE peluqueria_bd;
SET NAMES utf8mb4;

-- =====================================================================
--  1) TABLAS
-- =====================================================================

-- ---------------------------------------------------------------------
--  M9 - SEGURIDAD Y ADMINISTRACION
-- ---------------------------------------------------------------------

-- Rol  (fusiona "rol" de B con "perfil" de A)
CREATE TABLE rol (
  id_rol      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(50)  NOT NULL,
  descripcion VARCHAR(150) NULL,
  es_personal TINYINT(1)   NOT NULL DEFAULT 1,  -- 1 = personal del salon, 0 = cliente con acceso al portal
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_rol),
  UNIQUE KEY uq_rol_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sucursal  (el alcance del TCC declara un unico local; la tabla guarda sus
-- datos, que ademas son los del emisor en la facturacion)
CREATE TABLE sucursal (
  id_sucursal INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(120) NOT NULL,
  ruc         VARCHAR(20)  NULL,
  telefono    VARCHAR(20)  NULL,
  direccion   VARCHAR(200) NULL,
  ciudad      VARCHAR(60)  NULL,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_sucursal),
  UNIQUE KEY uq_sucursal_ruc (ruc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario  (fusiona "usuario" de B con "Usuarios" + "funcionarios" + los
-- datos personales que A guardaba en "Personas")
CREATE TABLE usuario (
  id_usuario     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_rol         INT UNSIGNED NOT NULL,
  id_sucursal    INT UNSIGNED NULL,
  username       VARCHAR(60)  NOT NULL,
  nombre         VARCHAR(80)  NOT NULL,
  apellido       VARCHAR(80)  NOT NULL,
  cedula         VARCHAR(20)  NULL,
  telefono       VARCHAR(20)  NULL,
  email          VARCHAR(120) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  fecha_ingreso  DATE         NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario),
  UNIQUE KEY uq_usuario_username (username),
  UNIQUE KEY uq_usuario_email (email),
  UNIQUE KEY uq_usuario_cedula (cedula),
  KEY idx_usuario_rol (id_rol),
  KEY idx_usuario_sucursal (id_sucursal),
  CONSTRAINT fk_usuario_rol FOREIGN KEY (id_rol) REFERENCES rol (id_rol)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_usuario_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal (id_sucursal)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_usuario_email CHECK (email LIKE '%_@_%.__%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Turno laboral  (era "Turnos" en A; se renombra para no confundirlo con la
-- cita, que en el habla local tambien se llama turno)
CREATE TABLE turno_laboral (
  id_turno    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_usuario  INT UNSIGNED NOT NULL,
  id_sucursal INT UNSIGNED NOT NULL,
  fecha       DATE         NOT NULL,
  hora_inicio TIME         NOT NULL,
  hora_fin    TIME         NOT NULL,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_turno),
  UNIQUE KEY uq_turno (id_usuario, fecha, hora_inicio),
  KEY idx_turno_sucursal (id_sucursal),
  KEY idx_turno_fecha (fecha),
  CONSTRAINT fk_turno_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_turno_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal (id_sucursal)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_turno_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asistencia  (la fecha sale del turno, por eso no se repite aca)
CREATE TABLE asistencia (
  id_asistencia       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_turno            INT UNSIGNED NOT NULL,
  id_usuario_registro INT UNSIGNED NULL,
  hora_entrada        TIME         NULL,
  hora_salida         TIME         NULL,
  motivo_ausencia     VARCHAR(200) NULL,
  horas_extras        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  observaciones       VARCHAR(300) NULL,
  PRIMARY KEY (id_asistencia),
  UNIQUE KEY uq_asistencia_turno (id_turno),
  KEY idx_asistencia_registro (id_usuario_registro),
  CONSTRAINT fk_asistencia_turno FOREIGN KEY (id_turno) REFERENCES turno_laboral (id_turno)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_asistencia_usuario FOREIGN KEY (id_usuario_registro) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_asistencia_extras CHECK (horas_extras >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M3 - SERVICIOS, PROMOCIONES Y DESCUENTOS
-- ---------------------------------------------------------------------

CREATE TABLE categoria_servicio (
  id_categoria_servicio INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre                VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id_categoria_servicio),
  UNIQUE KEY uq_categoria_servicio_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicio (
  id_servicio  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  id_categoria_servicio INT UNSIGNED NOT NULL,
  nombre       VARCHAR(100)     NOT NULL,
  descripcion  VARCHAR(255)     NULL,
  precio       DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  duracion_min INT              NOT NULL DEFAULT 0,
  tasa_iva     TINYINT UNSIGNED NOT NULL DEFAULT 10,
  activo       TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id_servicio),
  UNIQUE KEY uq_servicio_nombre (nombre),
  KEY idx_servicio_categoria (id_categoria_servicio),
  CONSTRAINT fk_servicio_categoria FOREIGN KEY (id_categoria_servicio) REFERENCES categoria_servicio (id_categoria_servicio)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_servicio_precio   CHECK (precio >= 0),
  CONSTRAINT chk_servicio_duracion CHECK (duracion_min >= 0),
  CONSTRAINT chk_servicio_iva      CHECK (tasa_iva IN (0, 5, 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Descuento  (fusiona "descuento" de B con "promociones" de A: una promocion
-- es un descuento con fecha de inicio y de fin)
CREATE TABLE descuento (
  id_descuento  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre        VARCHAR(80)   NOT NULL,
  descripcion   VARCHAR(300)  NULL,
  tipo          VARCHAR(20)   NOT NULL,
  valor         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  fecha_inicio  DATE          NULL,   -- NULL = sin fecha de inicio
  fecha_fin     DATE          NULL,   -- NULL = sin vencimiento
  activo        TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id_descuento),
  UNIQUE KEY uq_descuento_nombre (nombre),
  CONSTRAINT chk_descuento_tipo   CHECK (tipo IN ('PORCENTAJE', 'MONTO')),
  CONSTRAINT chk_descuento_valor  CHECK (valor >= 0),
  CONSTRAINT chk_descuento_fechas CHECK (fecha_fin IS NULL OR fecha_inicio IS NULL OR fecha_fin >= fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicios alcanzados por un descuento. Si un descuento no tiene filas aca,
-- se aplica sobre el total del comprobante.
CREATE TABLE servicio_descuento (
  id_servicio_descuento INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_servicio           INT UNSIGNED NOT NULL,
  id_descuento          INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_servicio_descuento),
  UNIQUE KEY uq_servicio_descuento (id_servicio, id_descuento),
  KEY idx_sd_descuento (id_descuento),
  CONSTRAINT fk_sd_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sd_descuento FOREIGN KEY (id_descuento) REFERENCES descuento (id_descuento)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Que servicios esta habilitado a realizar cada profesional. duracion_min
-- permite que una misma keratina lleve mas tiempo con alguien que recien
-- empieza. Si un profesional no tiene ninguna fila aca, el sistema no lo
-- restringe: recien cuando se le carga la primera habilitacion empieza a
-- controlarse que solo haga esos servicios.
CREATE TABLE usuario_servicio (
  id_usuario_servicio INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_usuario          INT UNSIGNED NOT NULL,
  id_servicio         INT UNSIGNED NOT NULL,
  duracion_min        INT          NULL,   -- pisa la duracion del catalogo
  activo              TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_usuario_servicio),
  UNIQUE KEY uq_usuario_servicio (id_usuario, id_servicio),
  KEY idx_us_servicio (id_servicio),
  CONSTRAINT fk_us_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_us_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_us_duracion CHECK (duracion_min IS NULL OR duracion_min > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comision del profesional. id_servicio en NULL significa "para todos los
-- servicios"; una fila con servicio concreto le gana a la general. Se guarda la
-- vigencia porque el porcentaje de hoy no puede cambiar lo que ya se liquido.
CREATE TABLE comision (
  id_comision   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_usuario    INT UNSIGNED  NOT NULL,
  id_servicio   INT UNSIGNED  NULL,
  tipo          VARCHAR(20)   NOT NULL,
  valor         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  vigente_desde DATE          NOT NULL,
  activo        TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id_comision),
  UNIQUE KEY uq_comision (id_usuario, id_servicio, vigente_desde),
  KEY idx_comision_servicio (id_servicio),
  CONSTRAINT fk_comision_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_comision_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_comision_tipo  CHECK (tipo IN ('PORCENTAJE', 'MONTO')),
  CONSTRAINT chk_comision_valor CHECK (valor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M1 - EXCEPCIONES DE AGENDA
-- ---------------------------------------------------------------------

CREATE TABLE tipo_ausencia (
  id_tipo_ausencia INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_tipo_ausencia),
  UNIQUE KEY uq_tipo_ausencia_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueo de agenda. Con id_usuario en NULL el bloqueo alcanza a todo el salon,
-- que es el caso de un feriado.
CREATE TABLE ausencia_agenda (
  id_ausencia      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_usuario       INT UNSIGNED NULL,
  id_tipo_ausencia INT UNSIGNED NOT NULL,
  fecha_inicio     DATETIME     NOT NULL,
  fecha_fin        DATETIME     NOT NULL,
  motivo           VARCHAR(200) NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_ausencia),
  KEY idx_ausencia_usuario (id_usuario, fecha_inicio),
  KEY idx_ausencia_tipo (id_tipo_ausencia),
  CONSTRAINT fk_ausencia_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ausencia_tipo FOREIGN KEY (id_tipo_ausencia) REFERENCES tipo_ausencia (id_tipo_ausencia)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_ausencia_rango CHECK (fecha_fin > fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M2 - CLIENTES Y FIDELIZACION
-- ---------------------------------------------------------------------

-- Nivel de fidelizacion. El porcentaje no se repite aca: cada nivel apunta al
-- descuento del catalogo que le corresponde.
CREATE TABLE nivel (
  id_nivel        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_descuento    INT UNSIGNED NULL,
  nombre          VARCHAR(60)  NOT NULL,
  visitas_minimas INT          NOT NULL DEFAULT 0,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_nivel),
  UNIQUE KEY uq_nivel_nombre (nombre),
  KEY idx_nivel_descuento (id_descuento),
  CONSTRAINT fk_nivel_descuento FOREIGN KEY (id_descuento) REFERENCES descuento (id_descuento)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_nivel_visitas CHECK (visitas_minimas >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cliente (
  id_cliente        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_usuario        INT UNSIGNED NULL,  -- acceso opcional al portal del cliente
  nombre            VARCHAR(80)  NOT NULL,
  apellido          VARCHAR(80)  NOT NULL,
  cedula            VARCHAR(20)  NULL,
  ruc               VARCHAR(20)  NULL,
  telefono          VARCHAR(20)  NULL,
  email             VARCHAR(120) NULL,
  fecha_nacimiento  DATE         NULL,
  fecha_registro    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones     VARCHAR(300) NULL,
  activo            TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_cliente),
  UNIQUE KEY uq_cliente_cedula (cedula),
  UNIQUE KEY uq_cliente_ruc (ruc),
  UNIQUE KEY uq_cliente_usuario (id_usuario),
  KEY idx_cliente_nombre (apellido, nombre),
  CONSTRAINT fk_cliente_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_cliente_email  CHECK (email IS NULL OR email LIKE '%_@_%.__%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preferencia_cliente (
  id_preferencia INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cliente     INT UNSIGNED NOT NULL,
  descripcion    VARCHAR(255) NOT NULL,
  fecha_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_preferencia),
  KEY idx_pref_cliente (id_cliente),
  CONSTRAINT fk_pref_cliente FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M1 - CITAS
-- ---------------------------------------------------------------------

CREATE TABLE estado_cita (
  id_estado_cita  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(40)  NOT NULL,
  bloquea_agenda  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = ocupa el horario del profesional
  PRIMARY KEY (id_estado_cita),
  UNIQUE KEY uq_estado_cita_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cita. La fecha y la hora van juntas en fecha_hora (en A estaban separadas).
-- La duracion sale de los servicios agendados: fn_cita_duracion().
CREATE TABLE cita (
  id_cita        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cliente     INT UNSIGNED NOT NULL,
  id_usuario     INT UNSIGNED NOT NULL,  -- profesional que atiende
  id_estado_cita INT UNSIGNED NOT NULL,
  fecha_hora     DATETIME     NOT NULL,
  fecha_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones  VARCHAR(300) NULL,
  PRIMARY KEY (id_cita),
  KEY idx_cita_cliente (id_cliente),
  KEY idx_cita_usuario (id_usuario, fecha_hora),
  KEY idx_cita_estado (id_estado_cita),
  KEY idx_cita_fecha (fecha_hora),
  CONSTRAINT fk_cita_cliente FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cita_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cita_estado FOREIGN KEY (id_estado_cita) REFERENCES estado_cita (id_estado_cita)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cita_servicio (
  id_cita_servicio INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cita          INT UNSIGNED NOT NULL,
  id_servicio      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_cita_servicio),
  UNIQUE KEY uq_cita_servicio (id_cita, id_servicio),
  KEY idx_cs_servicio (id_servicio),
  CONSTRAINT fk_cs_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_cs_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicio efectivamente realizado en una cita. Reemplaza a "consumo_servicio"
-- de A: los productos gastados se registran en producto_utilizado y la linea
-- facturada se enlaza con id_detalle_factura (la FK se agrega mas abajo,
-- cuando ya exista detalle_factura).
CREATE TABLE servicio_realizado (
  id_servicio_realizado INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cita               INT UNSIGNED NOT NULL,
  id_servicio           INT UNSIGNED NOT NULL,
  id_usuario            INT UNSIGNED NOT NULL,
  id_detalle_factura    INT UNSIGNED NULL,
  fecha_hora            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones         VARCHAR(300) NULL,
  PRIMARY KEY (id_servicio_realizado),
  KEY idx_sr_cita (id_cita),
  KEY idx_sr_servicio (id_servicio),
  KEY idx_sr_usuario (id_usuario),
  KEY idx_sr_detalle (id_detalle_factura),
  CONSTRAINT fk_sr_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sr_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sr_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calificacion del servicio (una por cita)
CREATE TABLE calificacion (
  id_calificacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cita         INT UNSIGNED NOT NULL,
  puntaje         TINYINT UNSIGNED NOT NULL,
  comentario      VARCHAR(300) NULL,
  fecha           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_calificacion),
  UNIQUE KEY uq_calificacion_cita (id_cita),
  CONSTRAINT fk_calificacion_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_calificacion_puntaje CHECK (puntaje BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M4 - INVENTARIO Y PROVEEDORES
-- ---------------------------------------------------------------------

CREATE TABLE categoria_producto (
  id_categoria INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id_categoria),
  UNIQUE KEY uq_categoria_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Producto. precio_costo viene de A (prd_monto_costo) y precio_venta reemplaza
-- al precio_unitario de B, que era el precio de venta.
CREATE TABLE producto (
  id_producto   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  id_categoria  INT UNSIGNED     NOT NULL,
  nombre        VARCHAR(100)     NOT NULL,
  descripcion   VARCHAR(255)     NULL,
  unidad_medida VARCHAR(20)      NOT NULL DEFAULT 'unidad',
  stock_minimo  DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  precio_costo  DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  precio_venta  DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  tasa_iva      TINYINT UNSIGNED NOT NULL DEFAULT 10,
  activo        TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (id_producto),
  KEY idx_producto_categoria (id_categoria),
  KEY idx_producto_nombre (nombre),
  CONSTRAINT fk_producto_categoria FOREIGN KEY (id_categoria) REFERENCES categoria_producto (id_categoria)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_producto_stkmin CHECK (stock_minimo >= 0),
  CONSTRAINT chk_producto_costo  CHECK (precio_costo >= 0),
  CONSTRAINT chk_producto_venta  CHECK (precio_venta >= 0),
  CONSTRAINT chk_producto_iva    CHECK (tasa_iva IN (0, 5, 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE proveedor (
  id_proveedor INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(120) NOT NULL,   -- razon social o nombre comercial
  contacto     VARCHAR(120) NULL,       -- persona de contacto
  ruc          VARCHAR(20)  NULL,
  telefono     VARCHAR(20)  NULL,
  email        VARCHAR(120) NULL,
  direccion    VARCHAR(255) NULL,
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_proveedor),
  UNIQUE KEY uq_proveedor_ruc (ruc),
  KEY idx_proveedor_nombre (nombre),
  CONSTRAINT chk_proveedor_email CHECK (email IS NULL OR email LIKE '%_@_%.__%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Producto consumido durante un servicio (no se factura: se descuenta del stock)
CREATE TABLE producto_utilizado (
  id_producto_utilizado INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_servicio_realizado INT UNSIGNED  NOT NULL,
  id_producto           INT UNSIGNED  NOT NULL,
  cantidad              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_producto_utilizado),
  UNIQUE KEY uq_prod_util (id_servicio_realizado, id_producto),
  KEY idx_pu_producto (id_producto),
  CONSTRAINT fk_pu_servicio_realizado FOREIGN KEY (id_servicio_realizado) REFERENCES servicio_realizado (id_servicio_realizado)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pu_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_pu_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado de la compra (A lo tenia como texto libre: PENDIENTE / CONFIRMADA)
CREATE TABLE estado_compra (
  id_estado_compra INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_estado_compra),
  UNIQUE KEY uq_estado_compra_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compra (
  id_compra        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_proveedor     INT UNSIGNED  NOT NULL,
  id_usuario       INT UNSIGNED  NOT NULL,
  id_estado_compra INT UNSIGNED  NOT NULL,
  id_condicion_venta INT UNSIGNED NOT NULL DEFAULT 1,  -- contado o credito del proveedor
  nro_factura_proveedor VARCHAR(20) NULL,
  fecha            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones    VARCHAR(300)  NULL,
  PRIMARY KEY (id_compra),
  KEY idx_compra_proveedor (id_proveedor),
  KEY idx_compra_usuario (id_usuario),
  KEY idx_compra_estado (id_estado_compra),
  KEY idx_compra_condicion (id_condicion_venta),
  KEY idx_compra_fecha (fecha),
  CONSTRAINT fk_compra_proveedor FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_compra_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_compra_estado FOREIGN KEY (id_estado_compra) REFERENCES estado_compra (id_estado_compra)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle de compra. Con PK propia (A usaba PK compuesta, que impedia repetir
-- el mismo producto en dos lineas de la misma compra).
CREATE TABLE detalle_compra (
  id_detalle_compra INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_compra         INT UNSIGNED  NOT NULL,
  id_producto       INT UNSIGNED  NOT NULL,
  cantidad          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  precio_unitario   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_detalle_compra),
  KEY idx_dc_compra (id_compra),
  KEY idx_dc_producto (id_producto),
  CONSTRAINT fk_dc_compra FOREIGN KEY (id_compra) REFERENCES compra (id_compra)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dc_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_dc_cantidad CHECK (cantidad > 0),
  CONSTRAINT chk_dc_precio   CHECK (precio_unitario >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipo de movimiento de stock (era "Movimientos" en A). El signo indica si el
-- movimiento suma (E) o resta (S) existencias.
CREATE TABLE tipo_movimiento_inventario (
  id_tipo_movimiento INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre             VARCHAR(60)  NOT NULL,
  signo              CHAR(1)      NOT NULL,
  activo             TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_tipo_movimiento),
  UNIQUE KEY uq_tipo_mov_nombre (nombre),
  CONSTRAINT chk_tipo_mov_signo CHECK (signo IN ('E', 'S'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro mayor de stock. Unifica movimiento_inventario (B) con movimiento_stock
-- y movimiento_stock_has_productos (A). Es la unica fuente del stock: la
-- existencia de un producto es la suma con signo de estas filas.
CREATE TABLE movimiento_inventario (
  id_movimiento      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_producto        INT UNSIGNED  NOT NULL,
  id_usuario         INT UNSIGNED  NOT NULL,
  id_tipo_movimiento INT UNSIGNED  NOT NULL,
  cantidad           DECIMAL(10,2) NOT NULL,
  precio_unitario    DECIMAL(12,2) NULL,
  referencia         VARCHAR(40)   NULL,   -- COM#12, FAC#33, SR#8, ...
  fecha              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones      VARCHAR(300)  NULL,
  PRIMARY KEY (id_movimiento),
  KEY idx_mi_producto (id_producto, fecha),
  KEY idx_mi_usuario (id_usuario),
  KEY idx_mi_tipo (id_tipo_movimiento),
  KEY idx_mi_referencia (referencia),
  CONSTRAINT fk_mi_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mi_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mi_tipo FOREIGN KEY (id_tipo_movimiento) REFERENCES tipo_movimiento_inventario (id_tipo_movimiento)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_mi_cantidad CHECK (cantidad > 0),
  CONSTRAINT chk_mi_precio   CHECK (precio_unitario IS NULL OR precio_unitario >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M5 - FACTURACION
-- ---------------------------------------------------------------------

-- Tipo de comprobante: la clasificacion de los documentos que emite el salon
-- (factura, boleta, ticket, nota de credito, nota de debito, ...).
--   signo            +1 suma en ventas, -1 resta (nota de credito), 0 no incide
--   requiere_origen   1 = el documento debe referenciar a la factura que corrige
CREATE TABLE tipo_comprobante (
  id_tipo_comprobante INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo              VARCHAR(5)   NOT NULL,
  nombre              VARCHAR(60)  NOT NULL,
  signo               TINYINT      NOT NULL DEFAULT 1,
  requiere_origen     TINYINT(1)   NOT NULL DEFAULT 0,
  activo              TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_tipo_comprobante),
  UNIQUE KEY uq_tipo_comprobante_codigo (codigo),
  UNIQUE KEY uq_tipo_comprobante_nombre (nombre),
  CONSTRAINT chk_tcomp_signo CHECK (signo IN (-1, 0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE condicion_venta (
  id_condicion_venta INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre             VARCHAR(40)  NOT NULL,
  dias_credito       INT          NOT NULL DEFAULT 0,
  activo             TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_condicion_venta),
  UNIQUE KEY uq_condicion_nombre (nombre),
  CONSTRAINT chk_condicion_dias CHECK (dias_credito >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timbrado: vigencia y rango habilitado por tipo de comprobante
CREATE TABLE timbrado (
  id_timbrado         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_sucursal         INT UNSIGNED NOT NULL,
  id_tipo_comprobante INT UNSIGNED NOT NULL,
  nro_timbrado        VARCHAR(20)  NOT NULL,
  establecimiento     CHAR(3)      NOT NULL DEFAULT '001',
  punto_expedicion    CHAR(3)      NOT NULL DEFAULT '001',
  fecha_inicio        DATE         NOT NULL,
  fecha_fin           DATE         NOT NULL,
  nro_desde           INT UNSIGNED NOT NULL DEFAULT 1,
  nro_hasta           INT UNSIGNED NOT NULL,
  activo              TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_timbrado),
  UNIQUE KEY uq_timbrado (nro_timbrado, id_tipo_comprobante, establecimiento, punto_expedicion),
  KEY idx_timbrado_sucursal (id_sucursal),
  KEY idx_timbrado_tipo (id_tipo_comprobante),
  CONSTRAINT fk_timbrado_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal (id_sucursal)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_timbrado_tipo FOREIGN KEY (id_tipo_comprobante) REFERENCES tipo_comprobante (id_tipo_comprobante)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_timbrado_fechas CHECK (fecha_fin >= fecha_inicio),
  CONSTRAINT chk_timbrado_rango  CHECK (nro_hasta >= nro_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estado_factura (
  id_estado_factura INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre            VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_estado_factura),
  UNIQUE KEY uq_estado_factura_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Factura. Fusiona "factura" (B) con "factura_cab" (A). No guarda importes: el
-- subtotal, el descuento y el total salen del detalle. El efecto de una nota de
-- credito lo da el signo del tipo de comprobante, no un importe negativo.
CREATE TABLE factura (
  id_factura          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_cliente          INT UNSIGNED  NOT NULL,
  id_cita             INT UNSIGNED  NULL,
  id_usuario          INT UNSIGNED  NOT NULL,
  id_tipo_comprobante INT UNSIGNED  NOT NULL,
  id_condicion_venta  INT UNSIGNED  NOT NULL,
  id_timbrado         INT UNSIGNED  NOT NULL,
  id_estado_factura   INT UNSIGNED  NOT NULL,
  id_factura_origen   INT UNSIGNED  NULL,   -- factura corregida por una NC o ND
  nro_correlativo     INT UNSIGNED  NOT NULL,  -- solo el correlativo; el establecimiento
                                               -- y el punto de expedicion salen del timbrado
  fecha_emision       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones       VARCHAR(300)  NULL,
  PRIMARY KEY (id_factura),
  UNIQUE KEY uq_factura_nro (id_timbrado, nro_correlativo),
  KEY idx_factura_cliente (id_cliente),
  KEY idx_factura_cita (id_cita),
  KEY idx_factura_usuario (id_usuario),
  KEY idx_factura_tipo (id_tipo_comprobante),
  KEY idx_factura_condicion (id_condicion_venta),
  KEY idx_factura_estado (id_estado_factura),
  KEY idx_factura_origen (id_factura_origen),
  KEY idx_factura_fecha (fecha_emision),
  CONSTRAINT fk_factura_cliente FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_factura_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_tipo FOREIGN KEY (id_tipo_comprobante) REFERENCES tipo_comprobante (id_tipo_comprobante)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_condicion FOREIGN KEY (id_condicion_venta) REFERENCES condicion_venta (id_condicion_venta)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_timbrado FOREIGN KEY (id_timbrado) REFERENCES timbrado (id_timbrado)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_estado FOREIGN KEY (id_estado_factura) REFERENCES estado_factura (id_estado_factura)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_factura_origen FOREIGN KEY (id_factura_origen) REFERENCES factura (id_factura)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_factura_correlativo CHECK (nro_correlativo > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle de factura. Cada linea es un servicio o un producto, nunca los dos
-- (regla que venia de factura_det en A). tasa_iva se copia del catalogo al
-- momento de facturar para que el comprobante no cambie si despues se edita el
-- servicio o el producto.
CREATE TABLE detalle_factura (
  id_detalle_factura INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  id_factura         INT UNSIGNED     NOT NULL,
  id_servicio        INT UNSIGNED     NULL,
  id_producto        INT UNSIGNED     NULL,
  cantidad           DECIMAL(10,2)    NOT NULL DEFAULT 1.00,
  precio_unitario    DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
  tasa_iva           TINYINT UNSIGNED NOT NULL DEFAULT 10,
  PRIMARY KEY (id_detalle_factura),
  KEY idx_df_factura (id_factura),
  KEY idx_df_servicio (id_servicio),
  KEY idx_df_producto (id_producto),
  CONSTRAINT fk_df_factura FOREIGN KEY (id_factura) REFERENCES factura (id_factura)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_df_servicio FOREIGN KEY (id_servicio) REFERENCES servicio (id_servicio)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_df_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_df_cantidad CHECK (cantidad > 0),
  CONSTRAINT chk_df_precio   CHECK (precio_unitario >= 0),
  CONSTRAINT chk_df_iva      CHECK (tasa_iva IN (0, 5, 10)),
  CONSTRAINT chk_df_item     CHECK (
       (id_servicio IS NOT NULL AND id_producto IS NULL)
    OR (id_servicio IS NULL     AND id_producto IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enlace del servicio realizado con la linea que lo facturo (evita facturar dos
-- veces el mismo trabajo). Se agrega aca porque detalle_factura ya existe.
ALTER TABLE servicio_realizado
  ADD CONSTRAINT fk_sr_detalle_factura FOREIGN KEY (id_detalle_factura)
  REFERENCES detalle_factura (id_detalle_factura)
  ON UPDATE CASCADE ON DELETE SET NULL;

-- Descuentos aplicados a un comprobante. monto_aplicado se guarda porque es una
-- foto: si despues cambia el valor del descuento, la factura vieja no cambia.
CREATE TABLE factura_descuento (
  id_factura_descuento INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_factura           INT UNSIGNED  NOT NULL,
  id_descuento         INT UNSIGNED  NOT NULL,
  monto_aplicado       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_factura_descuento),
  UNIQUE KEY uq_factura_descuento (id_factura, id_descuento),
  KEY idx_fd_descuento (id_descuento),
  CONSTRAINT fk_fd_factura FOREIGN KEY (id_factura) REFERENCES factura (id_factura)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fd_descuento FOREIGN KEY (id_descuento) REFERENCES descuento (id_descuento)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_fd_monto CHECK (monto_aplicado >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M5 - COBROS Y CAJA
-- ---------------------------------------------------------------------

-- Metodo de pago. El tipo decide que tabla de detalle corresponde: TARJETA usa
-- cobro_tarjeta, BANCO y CHEQUE usan cobro_banco.
CREATE TABLE metodo_pago (
  id_metodo_pago INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(40)  NOT NULL,
  tipo           VARCHAR(10)  NOT NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_metodo_pago),
  UNIQUE KEY uq_metodo_pago_nombre (nombre),
  CONSTRAINT chk_metodo_tipo CHECK (tipo IN ('EFECTIVO', 'TARJETA', 'BANCO', 'CHEQUE', 'OTRO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estado_cobro (
  id_estado_cobro INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_estado_cobro),
  UNIQUE KEY uq_estado_cobro_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cobro. Fusiona "cobro" (B) con "Cobros" + "pagos" (A): cada fila es un pago
-- contra una factura, asi que un pago mixto (parte efectivo, parte tarjeta) son
-- dos cobros de la misma factura. id_caja dice donde entro la plata, dato que no
-- se puede deducir despues.
CREATE TABLE cobro (
  id_cobro        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_factura      INT UNSIGNED  NULL,   -- cobro contra un comprobante
  id_cita         INT UNSIGNED  NULL,   -- o sena tomada al reservar la cita
  id_metodo_pago  INT UNSIGNED  NOT NULL,
  id_estado_cobro INT UNSIGNED  NOT NULL,
  id_usuario      INT UNSIGNED  NOT NULL,
  id_caja         INT UNSIGNED  NULL,   -- caja donde entro la plata
  fecha           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  monto           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  referencia      VARCHAR(100)  NULL,
  observaciones   VARCHAR(300)  NULL,
  PRIMARY KEY (id_cobro),
  KEY idx_cobro_factura (id_factura),
  KEY idx_cobro_cita (id_cita),
  KEY idx_cobro_metodo (id_metodo_pago),
  KEY idx_cobro_estado (id_estado_cobro),
  KEY idx_cobro_usuario (id_usuario),
  KEY idx_cobro_caja (id_caja),
  KEY idx_cobro_fecha (fecha),
  CONSTRAINT fk_cobro_factura FOREIGN KEY (id_factura) REFERENCES factura (id_factura)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cobro_metodo FOREIGN KEY (id_metodo_pago) REFERENCES metodo_pago (id_metodo_pago)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cobro_estado FOREIGN KEY (id_estado_cobro) REFERENCES estado_cobro (id_estado_cobro)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cobro_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_cobro_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_cobro_monto CHECK (monto >= 0),
  CONSTRAINT chk_cobro_destino CHECK (
       (id_factura IS NOT NULL AND id_cita IS NULL)
    OR (id_factura IS NULL     AND id_cita IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle del cobro con tarjeta (era pago_tarjeta, que colgaba de "pagos")
CREATE TABLE cobro_tarjeta (
  id_cobro_tarjeta  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cobro          INT UNSIGNED NOT NULL,
  marca             VARCHAR(30)  NULL,
  tipo_tarjeta      VARCHAR(30)  NOT NULL,
  cuotas            INT          NOT NULL DEFAULT 1,
  ultimos_4         CHAR(4)      NULL,
  nro_boleta        VARCHAR(60)  NULL,
  cod_autorizacion  VARCHAR(60)  NULL,
  PRIMARY KEY (id_cobro_tarjeta),
  UNIQUE KEY uq_cobro_tarjeta (id_cobro),
  CONSTRAINT fk_ct_cobro FOREIGN KEY (id_cobro) REFERENCES cobro (id_cobro)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_ct_cuotas CHECK (cuotas >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle del cobro por banco: cheque o transferencia (era pago_banco)
CREATE TABLE cobro_banco (
  id_cobro_banco INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cobro       INT UNSIGNED NOT NULL,
  banco          VARCHAR(80)  NOT NULL,
  nro_cheque     VARCHAR(40)  NULL,
  nro_operacion  VARCHAR(40)  NULL,
  fecha_emision  DATE         NULL,
  PRIMARY KEY (id_cobro_banco),
  UNIQUE KEY uq_cobro_banco (id_cobro),
  CONSTRAINT fk_cb_cobro FOREIGN KEY (id_cobro) REFERENCES cobro (id_cobro)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estado_caja (
  id_estado_caja INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(30)  NOT NULL,
  PRIMARY KEY (id_estado_caja),
  UNIQUE KEY uq_estado_caja_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE caja (
  id_caja        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_usuario     INT UNSIGNED  NOT NULL,
  id_estado_caja INT UNSIGNED  NOT NULL,
  fecha_apertura DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre   DATETIME      NULL,
  monto_inicial  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_caja),
  KEY idx_caja_usuario (id_usuario),
  KEY idx_caja_estado (id_estado_caja),
  CONSTRAINT fk_caja_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_caja_estado FOREIGN KEY (id_estado_caja) REFERENCES estado_caja (id_estado_caja)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_caja_inicial CHECK (monto_inicial >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movimiento_caja (
  id_movimiento_caja INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_caja            INT UNSIGNED  NOT NULL,
  tipo               VARCHAR(10)   NOT NULL,
  monto              DECIMAL(14,2) NOT NULL,
  fecha              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  concepto           VARCHAR(150)  NULL,
  PRIMARY KEY (id_movimiento_caja),
  KEY idx_mc_caja (id_caja),
  CONSTRAINT fk_mc_caja FOREIGN KEY (id_caja) REFERENCES caja (id_caja)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_mc_tipo  CHECK (tipo IN ('INGRESO', 'EGRESO')),
  CONSTRAINT chk_mc_monto CHECK (monto >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La caja ya existe, asi que se pueden cerrar los vinculos pendientes.
ALTER TABLE cobro
  ADD CONSTRAINT fk_cobro_caja FOREIGN KEY (id_caja) REFERENCES caja (id_caja)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE compra
  ADD CONSTRAINT fk_compra_condicion FOREIGN KEY (id_condicion_venta) REFERENCES condicion_venta (id_condicion_venta)
  ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
--  M6 - PAGOS A PROVEEDORES
-- ---------------------------------------------------------------------

CREATE TABLE estado_pago_proveedor (
  id_estado_pago_proveedor INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre                   VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_estado_pago_proveedor),
  UNIQUE KEY uq_estado_pago_prov_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pago hecho a un proveedor. No guarda el monto: sale de la suma de lo que se
-- imputo a cada compra, igual que el pago al personal.
CREATE TABLE pago_proveedor (
  id_pago_proveedor        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_proveedor             INT UNSIGNED NOT NULL,
  id_usuario               INT UNSIGNED NOT NULL,
  id_metodo_pago           INT UNSIGNED NOT NULL,
  id_estado_pago_proveedor INT UNSIGNED NOT NULL,
  id_caja                  INT UNSIGNED NULL,   -- de que caja salio la plata
  fecha                    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  referencia               VARCHAR(100) NULL,
  observaciones            VARCHAR(300) NULL,
  PRIMARY KEY (id_pago_proveedor),
  KEY idx_pprov_proveedor (id_proveedor),
  KEY idx_pprov_usuario (id_usuario),
  KEY idx_pprov_metodo (id_metodo_pago),
  KEY idx_pprov_estado (id_estado_pago_proveedor),
  KEY idx_pprov_caja (id_caja),
  CONSTRAINT fk_pprov_proveedor FOREIGN KEY (id_proveedor) REFERENCES proveedor (id_proveedor)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pprov_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pprov_metodo FOREIGN KEY (id_metodo_pago) REFERENCES metodo_pago (id_metodo_pago)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pprov_estado FOREIGN KEY (id_estado_pago_proveedor) REFERENCES estado_pago_proveedor (id_estado_pago_proveedor)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pprov_caja FOREIGN KEY (id_caja) REFERENCES caja (id_caja)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imputacion del pago a cada compra. Un pago puede cancelar varias compras y
-- una compra puede pagarse en cuotas, asi que la relacion es N a N.
CREATE TABLE detalle_pago_proveedor (
  id_detalle_pago_proveedor INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_pago_proveedor         INT UNSIGNED  NOT NULL,
  id_compra                 INT UNSIGNED  NOT NULL,
  monto_aplicado            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_detalle_pago_proveedor),
  UNIQUE KEY uq_dpprov (id_pago_proveedor, id_compra),
  KEY idx_dpprov_compra (id_compra),
  CONSTRAINT fk_dpprov_pago FOREIGN KEY (id_pago_proveedor) REFERENCES pago_proveedor (id_pago_proveedor)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dpprov_compra FOREIGN KEY (id_compra) REFERENCES compra (id_compra)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_dpprov_monto CHECK (monto_aplicado > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M5 - PAGOS AL PERSONAL
-- ---------------------------------------------------------------------

CREATE TABLE estado_pago_personal (
  id_estado_pago INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(40)  NOT NULL,
  PRIMARY KEY (id_estado_pago),
  UNIQUE KEY uq_estado_pago_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pago_personal (
  id_pago_personal    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_usuario          INT UNSIGNED  NOT NULL,  -- beneficiario
  id_usuario_registro INT UNSIGNED  NOT NULL,  -- quien lo registra
  id_estado_pago      INT UNSIGNED  NOT NULL,
  fecha               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  periodo             VARCHAR(40)   NULL,
  observaciones       VARCHAR(300)  NULL,
  PRIMARY KEY (id_pago_personal),
  KEY idx_pp_usuario (id_usuario),
  KEY idx_pp_usuario_reg (id_usuario_registro),
  KEY idx_pp_estado (id_estado_pago),
  CONSTRAINT fk_pp_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pp_usuario_registro FOREIGN KEY (id_usuario_registro) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_pp_estado FOREIGN KEY (id_estado_pago) REFERENCES estado_pago_personal (id_estado_pago)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE detalle_pago_personal (
  id_detalle_pago       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_pago_personal      INT UNSIGNED  NOT NULL,
  id_servicio_realizado INT UNSIGNED  NOT NULL,
  monto                 DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_detalle_pago),
  UNIQUE KEY uq_detalle_pago (id_servicio_realizado),
  KEY idx_dpp_pago (id_pago_personal),
  CONSTRAINT fk_dpp_pago FOREIGN KEY (id_pago_personal) REFERENCES pago_personal (id_pago_personal)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dpp_servicio_realizado FOREIGN KEY (id_servicio_realizado) REFERENCES servicio_realizado (id_servicio_realizado)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_dpp_monto CHECK (monto >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M2 - MOVIMIENTOS DE PUNTOS  (se define aca porque depende de factura)
-- ---------------------------------------------------------------------

-- Libro mayor de puntos. El saldo del cliente es la suma de estas filas y lo
-- devuelve fn_cliente_puntos(). No se guarda en ningun lado.
CREATE TABLE movimiento_punto (
  id_movimiento_punto INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cliente          INT UNSIGNED NOT NULL,
  id_factura          INT UNSIGNED NULL,
  tipo                VARCHAR(10)  NOT NULL,
  puntos              INT          NOT NULL,   -- positivo suma, negativo resta
  fecha               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  observaciones       VARCHAR(300) NULL,
  PRIMARY KEY (id_movimiento_punto),
  KEY idx_mp_cliente (id_cliente, fecha),
  KEY idx_mp_factura (id_factura),
  CONSTRAINT fk_mp_cliente FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_mp_factura FOREIGN KEY (id_factura) REFERENCES factura (id_factura)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_mp_tipo CHECK (
       (tipo = 'ACUMULA' AND puntos > 0)
    OR (tipo = 'CANJE'   AND puntos < 0)
    OR (tipo = 'AJUSTE'  AND puntos <> 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M7 - NOTIFICACIONES
-- ---------------------------------------------------------------------

CREATE TABLE tipo_notificacion (
  id_tipo_notificacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre               VARCHAR(60)  NOT NULL,
  destinatario         VARCHAR(10)  NOT NULL,  -- CLIENTE o INTERNO
  activo               TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id_tipo_notificacion),
  UNIQUE KEY uq_tipo_notif_nombre (nombre),
  CONSTRAINT chk_tipo_notif_dest CHECK (destinatario IN ('CLIENTE', 'INTERNO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notificacion. En A solo servia para recordar citas; aca cubre tambien las
-- alertas de stock y los avisos de promociones, que es lo que pide el modulo M7.
CREATE TABLE notificacion (
  id_notificacion      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_tipo_notificacion INT UNSIGNED NOT NULL,
  id_cliente           INT UNSIGNED NULL,   -- destinatario externo
  id_usuario           INT UNSIGNED NULL,   -- destinatario interno
  id_cita              INT UNSIGNED NULL,   -- contexto
  id_producto          INT UNSIGNED NULL,   -- contexto
  canal                VARCHAR(20)  NOT NULL DEFAULT 'SISTEMA',
  mensaje              VARCHAR(300) NULL,
  estado               VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE',
  fecha_generacion     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_envio          DATETIME     NULL,
  PRIMARY KEY (id_notificacion),
  KEY idx_notif_tipo (id_tipo_notificacion),
  KEY idx_notif_cliente (id_cliente),
  KEY idx_notif_usuario (id_usuario),
  KEY idx_notif_cita (id_cita),
  KEY idx_notif_producto (id_producto),
  KEY idx_notif_estado (estado),
  CONSTRAINT fk_notif_tipo FOREIGN KEY (id_tipo_notificacion) REFERENCES tipo_notificacion (id_tipo_notificacion)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_notif_cliente FOREIGN KEY (id_cliente) REFERENCES cliente (id_cliente)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notif_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notif_cita FOREIGN KEY (id_cita) REFERENCES cita (id_cita)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notif_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_notif_canal  CHECK (canal IN ('WHATSAPP', 'EMAIL', 'SMS', 'SISTEMA')),
  CONSTRAINT chk_notif_estado CHECK (estado IN ('PENDIENTE', 'ENVIADA', 'FALLIDA', 'LEIDA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  M9 - AUDITORIA
-- ---------------------------------------------------------------------

CREATE TABLE auditoria (
  id_auditoria   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_usuario     INT UNSIGNED NOT NULL,
  accion         VARCHAR(40)  NOT NULL,
  modulo         VARCHAR(40)  NOT NULL,
  tabla_afectada VARCHAR(60)  NOT NULL,
  id_registro    INT UNSIGNED NULL,
  fecha_hora     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detalle        VARCHAR(300) NULL,
  PRIMARY KEY (id_auditoria),
  KEY idx_aud_usuario (id_usuario),
  KEY idx_aud_fecha (fecha_hora),
  KEY idx_aud_tabla (tabla_afectada),
  CONSTRAINT fk_aud_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  2) DATOS DE CATALOGO
-- =====================================================================

INSERT INTO rol (id_rol, nombre, descripcion, es_personal) VALUES
  (1, 'Propietaria', 'Acceso total al sistema', 1),
  (2, 'Gerente',     'Opera todos los modulos, sin administracion de cuentas', 1),
  (3, 'Asistente',   'Operacion diaria: citas, servicios y cobros', 1),
  (4, 'Cliente',     'Acceso al portal del cliente', 0);

INSERT INTO estado_cita (id_estado_cita, nombre, bloquea_agenda) VALUES
  (1, 'Programada',   1),
  (2, 'Reprogramada', 1),
  (3, 'Cancelada',    0),
  (4, 'Atendida',     0),
  (5, 'En proceso',   1),
  (6, 'Ausente',      0);

INSERT INTO estado_factura (id_estado_factura, nombre) VALUES
  (1, 'Emitida'), (2, 'Anulada');

INSERT INTO estado_cobro (id_estado_cobro, nombre) VALUES
  (1, 'Registrado'), (2, 'Pendiente'), (3, 'Anulado');

INSERT INTO estado_pago_personal (id_estado_pago, nombre) VALUES
  (1, 'Registrado'), (2, 'Pendiente'), (3, 'Anulado'), (4, 'Revertido');

INSERT INTO estado_caja (id_estado_caja, nombre) VALUES
  (1, 'Abierta'), (2, 'Cerrada');

INSERT INTO estado_compra (id_estado_compra, nombre) VALUES
  (1, 'Pendiente'), (2, 'Confirmada'), (3, 'Anulada');

INSERT INTO metodo_pago (id_metodo_pago, nombre, tipo) VALUES
  (1, 'Efectivo',               'EFECTIVO'),
  (2, 'Tarjeta de debito',      'TARJETA'),
  (3, 'Tarjeta de credito',     'TARJETA'),
  (4, 'Transferencia bancaria', 'BANCO'),
  (5, 'Cheque',                 'CHEQUE'),
  (6, 'Billetera electronica',  'OTRO');

-- Tipos de comprobante: la clasificacion de documentos del modulo de
-- facturacion. La nota de credito resta (signo -1) y exige la factura de
-- origen; la nota de debito suma y tambien exige origen.
INSERT INTO tipo_comprobante (id_tipo_comprobante, codigo, nombre, signo, requiere_origen) VALUES
  (1, '01', 'Factura',           1, 0),
  (2, '02', 'Boleta de venta',   1, 0),
  (3, '03', 'Ticket',            1, 0),
  (4, '04', 'Autofactura',       1, 0),
  (5, '05', 'Nota de credito',  -1, 1),
  (6, '06', 'Nota de debito',    1, 1),
  (7, '07', 'Nota de remision',  0, 0),
  (8, '08', 'Recibo de dinero',  0, 0);

INSERT INTO condicion_venta (id_condicion_venta, nombre, dias_credito) VALUES
  (1, 'Contado', 0),
  (2, 'Credito', 30);

INSERT INTO tipo_movimiento_inventario (id_tipo_movimiento, nombre, signo) VALUES
  (1, 'Compra',                 'E'),
  (2, 'Consumo en servicio',    'S'),
  (3, 'Ajuste positivo',        'E'),
  (4, 'Ajuste negativo',        'S'),
  (5, 'Merma o vencimiento',    'S'),
  (6, 'Devolucion de cliente',  'E'),
  (7, 'Venta de producto',      'S'),
  (8, 'Devolucion a proveedor', 'S'),
  (9, 'Inventario inicial',     'E');

INSERT INTO tipo_notificacion (id_tipo_notificacion, nombre, destinatario) VALUES
  (1, 'Recordatorio de cita',  'CLIENTE'),
  (2, 'Confirmacion de cita',  'CLIENTE'),
  (3, 'Cancelacion de cita',   'CLIENTE'),
  (4, 'Promocion',             'CLIENTE'),
  (5, 'Alerta de stock minimo','INTERNO'),
  (6, 'Cierre de caja',        'INTERNO');

INSERT INTO categoria_servicio (id_categoria_servicio, nombre) VALUES
  (1, 'Corte'),
  (2, 'Coloracion'),
  (3, 'Tratamiento capilar'),
  (4, 'Peinado y brushing'),
  (5, 'Manicura y pedicura'),
  (6, 'Otros');

INSERT INTO estado_pago_proveedor (id_estado_pago_proveedor, nombre) VALUES
  (1, 'Registrado'), (2, 'Anulado');

INSERT INTO tipo_ausencia (id_tipo_ausencia, nombre) VALUES
  (1, 'Vacaciones'),
  (2, 'Licencia'),
  (3, 'Feriado'),
  (4, 'Bloqueo puntual');

INSERT INTO categoria_producto (id_categoria, nombre) VALUES
  (1, 'Tinturas y coloracion'),
  (2, 'Cuidado capilar'),
  (3, 'Insumos descartables'),
  (4, 'Herramientas y accesorios'),
  (5, 'Productos de reventa');

-- Descuentos de fidelizacion. Los porcentajes son valores de referencia:
-- conviene confirmarlos con la propietaria antes de la carga definitiva.
INSERT INTO descuento (id_descuento, nombre, descripcion, tipo, valor) VALUES
  (1, 'Nivel Plata',   'Descuento por nivel de fidelizacion Plata',   'PORCENTAJE',  5.00),
  (2, 'Nivel Oro',     'Descuento por nivel de fidelizacion Oro',     'PORCENTAJE', 10.00),
  (3, 'Nivel Platino', 'Descuento por nivel de fidelizacion Platino', 'PORCENTAJE', 15.00);

INSERT INTO nivel (id_nivel, id_descuento, nombre, visitas_minimas) VALUES
  (1, NULL, 'Bronce',   0),
  (2, 1,    'Plata',   10),
  (3, 2,    'Oro',     25),
  (4, 3,    'Platino', 50);

-- Datos del local. Reemplazar por los datos reales del contribuyente.
INSERT INTO sucursal (id_sucursal, nombre, ruc, telefono, direccion, ciudad) VALUES
  (1, 'Peluqueria (local unico)', '80000000-0', NULL, NULL, 'Luque');

-- Timbrados de ejemplo. Sin al menos un timbrado vigente por tipo de
-- comprobante, sp_emitir_factura no puede numerar el documento.
INSERT INTO timbrado (id_timbrado, id_sucursal, id_tipo_comprobante, nro_timbrado,
                      establecimiento, punto_expedicion, fecha_inicio, fecha_fin, nro_desde, nro_hasta) VALUES
  (1, 1, 1, '12345678', '001', '001', '2026-01-01', '2026-12-31', 1, 9999999),
  (2, 1, 5, '12345679', '001', '001', '2026-01-01', '2026-12-31', 1, 9999999);


-- =====================================================================
--  3) FUNCIONES
--  En esta variante las funciones no son un adorno: son el unico lugar
--  donde vive el calculo de los datos que antes estaban guardados.
-- =====================================================================
DELIMITER $$

-- ---------- Seguridad ----------

DROP FUNCTION IF EXISTS fn_es_personal $$
CREATE FUNCTION fn_es_personal (p_id_usuario INT UNSIGNED)
  RETURNS TINYINT(1)
  READS SQL DATA
BEGIN
  DECLARE v_es TINYINT(1) DEFAULT 0;
  SELECT r.es_personal INTO v_es
  FROM usuario u JOIN rol r ON r.id_rol = u.id_rol
  WHERE u.id_usuario = p_id_usuario;
  RETURN COALESCE(v_es, 0);
END $$

-- ---------- Citas ----------

-- Duracion de la cita: la suma de los servicios agendados. Si todavia no tiene
-- ninguno, se asume una hora.
DROP FUNCTION IF EXISTS fn_cita_duracion $$
CREATE FUNCTION fn_cita_duracion (p_id_cita INT UNSIGNED)
  RETURNS INT
  READS SQL DATA
BEGIN
  DECLARE v_dur INT DEFAULT 0;
  SELECT COALESCE(SUM(COALESCE(us.duracion_min, s.duracion_min)), 0) INTO v_dur
  FROM cita_servicio cs
  JOIN cita c     ON c.id_cita = cs.id_cita
  JOIN servicio s ON s.id_servicio = cs.id_servicio
  LEFT JOIN usuario_servicio us
         ON us.id_usuario = c.id_usuario AND us.id_servicio = s.id_servicio AND us.activo = 1
  WHERE cs.id_cita = p_id_cita;
  RETURN IF(v_dur > 0, v_dur, 60);
END $$

DROP FUNCTION IF EXISTS fn_verificar_disponibilidad $$
CREATE FUNCTION fn_verificar_disponibilidad (
    p_id_usuario      INT UNSIGNED,
    p_fecha_hora      DATETIME,
    p_duracion_min    INT,
    p_id_cita_excluir INT UNSIGNED)
  RETURNS TINYINT(1)
  READS SQL DATA
BEGIN
  DECLARE v_conflictos INT DEFAULT 0;
  DECLARE v_dur INT DEFAULT 60;
  SET v_dur = IF(p_duracion_min IS NULL OR p_duracion_min <= 0, 60, p_duracion_min);

  -- vacaciones, licencia, feriado o bloqueo puntual: la agenda esta cerrada
  IF EXISTS (SELECT 1 FROM ausencia_agenda a
              WHERE a.activo = 1
                AND (a.id_usuario = p_id_usuario OR a.id_usuario IS NULL)
                AND a.fecha_inicio < (p_fecha_hora + INTERVAL v_dur MINUTE)
                AND p_fecha_hora < a.fecha_fin) THEN
    RETURN 0;
  END IF;

  SELECT COUNT(*) INTO v_conflictos
  FROM cita c
  JOIN estado_cita ec ON ec.id_estado_cita = c.id_estado_cita
  WHERE c.id_usuario = p_id_usuario
    AND ec.bloquea_agenda = 1
    AND (p_id_cita_excluir IS NULL OR c.id_cita <> p_id_cita_excluir)
    AND c.fecha_hora < (p_fecha_hora + INTERVAL v_dur MINUTE)
    AND p_fecha_hora < (c.fecha_hora + INTERVAL fn_cita_duracion(c.id_cita) MINUTE);

  RETURN IF(v_conflictos = 0, 1, 0);
END $$

-- ---------- Inventario ----------

-- Existencia de un producto: la suma con signo de su libro de movimientos.
DROP FUNCTION IF EXISTS fn_producto_stock $$
CREATE FUNCTION fn_producto_stock (p_id_producto INT UNSIGNED)
  RETURNS DECIMAL(12,2)
  READS SQL DATA
BEGIN
  DECLARE v_stock DECIMAL(12,2) DEFAULT 0;
  SELECT COALESCE(SUM(CASE WHEN t.signo = 'E' THEN m.cantidad ELSE -m.cantidad END), 0)
    INTO v_stock
  FROM movimiento_inventario m
  JOIN tipo_movimiento_inventario t ON t.id_tipo_movimiento = m.id_tipo_movimiento
  WHERE m.id_producto = p_id_producto;
  RETURN v_stock;
END $$

DROP FUNCTION IF EXISTS fn_compra_total $$
CREATE FUNCTION fn_compra_total (p_id_compra INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_total DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(ROUND(cantidad * precio_unitario, 2)), 0) INTO v_total
  FROM detalle_compra WHERE id_compra = p_id_compra;
  RETURN v_total;
END $$

-- ---------- Clientes y fidelizacion ----------

DROP FUNCTION IF EXISTS fn_cliente_puntos $$
CREATE FUNCTION fn_cliente_puntos (p_id_cliente INT UNSIGNED)
  RETURNS INT
  READS SQL DATA
BEGIN
  DECLARE v_puntos INT DEFAULT 0;
  SELECT COALESCE(SUM(puntos), 0) INTO v_puntos
  FROM movimiento_punto WHERE id_cliente = p_id_cliente;
  RETURN v_puntos;
END $$

DROP FUNCTION IF EXISTS fn_cliente_visitas $$
CREATE FUNCTION fn_cliente_visitas (p_id_cliente INT UNSIGNED)
  RETURNS INT
  READS SQL DATA
BEGIN
  DECLARE v INT DEFAULT 0;
  SELECT COUNT(*) INTO v FROM cita
  WHERE id_cliente = p_id_cliente AND id_estado_cita = 4;
  RETURN v;
END $$

-- El nivel del cliente no se guarda: sale de sus visitas atendidas.
DROP FUNCTION IF EXISTS fn_cliente_nivel $$
CREATE FUNCTION fn_cliente_nivel (p_id_cliente INT UNSIGNED)
  RETURNS INT UNSIGNED
  READS SQL DATA
BEGIN
  DECLARE v_nivel INT UNSIGNED DEFAULT NULL;
  SELECT id_nivel INTO v_nivel
  FROM nivel
  WHERE activo = 1 AND visitas_minimas <= fn_cliente_visitas(p_id_cliente)
  ORDER BY visitas_minimas DESC
  LIMIT 1;
  RETURN v_nivel;
END $$

DROP FUNCTION IF EXISTS fn_cliente_descuento $$
CREATE FUNCTION fn_cliente_descuento (p_id_cliente INT UNSIGNED)
  RETURNS INT UNSIGNED
  READS SQL DATA
BEGIN
  DECLARE v_desc INT UNSIGNED DEFAULT NULL;
  SELECT id_descuento INTO v_desc
  FROM nivel WHERE id_nivel = fn_cliente_nivel(p_id_cliente);
  RETURN v_desc;
END $$

DROP FUNCTION IF EXISTS fn_descuento_monto $$
CREATE FUNCTION fn_descuento_monto (p_id_descuento INT UNSIGNED, p_base DECIMAL(14,2))
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_tipo   VARCHAR(20);
  DECLARE v_valor  DECIMAL(10,2) DEFAULT 0;
  DECLARE v_activo TINYINT(1)    DEFAULT 0;
  DECLARE v_ini    DATE;
  DECLARE v_fin    DATE;
  DECLARE v_monto  DECIMAL(14,2) DEFAULT 0;

  SELECT tipo, valor, activo, fecha_inicio, fecha_fin
    INTO v_tipo, v_valor, v_activo, v_ini, v_fin
  FROM descuento WHERE id_descuento = p_id_descuento;

  IF v_activo <> 1 THEN RETURN 0; END IF;
  IF v_ini IS NOT NULL AND CURRENT_DATE < v_ini THEN RETURN 0; END IF;
  IF v_fin IS NOT NULL AND CURRENT_DATE > v_fin THEN RETURN 0; END IF;

  IF v_tipo = 'PORCENTAJE' THEN
    SET v_monto = ROUND(p_base * v_valor / 100, 2);
  ELSE
    SET v_monto = v_valor;
  END IF;

  IF v_monto > p_base THEN SET v_monto = p_base; END IF;
  IF v_monto < 0 THEN SET v_monto = 0; END IF;
  RETURN v_monto;
END $$

-- ---------- Facturacion ----------

DROP FUNCTION IF EXISTS fn_factura_subtotal $$
CREATE FUNCTION fn_factura_subtotal (p_id_factura INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(ROUND(cantidad * precio_unitario, 2)), 0) INTO v
  FROM detalle_factura WHERE id_factura = p_id_factura;
  RETURN v;
END $$

DROP FUNCTION IF EXISTS fn_factura_descuento $$
CREATE FUNCTION fn_factura_descuento (p_id_factura INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(monto_aplicado), 0) INTO v
  FROM factura_descuento WHERE id_factura = p_id_factura;
  RETURN v;
END $$

DROP FUNCTION IF EXISTS fn_factura_total $$
CREATE FUNCTION fn_factura_total (p_id_factura INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  RETURN GREATEST(fn_factura_subtotal(p_id_factura) - fn_factura_descuento(p_id_factura), 0);
END $$

DROP FUNCTION IF EXISTS fn_factura_saldo $$
CREATE FUNCTION fn_factura_saldo (p_id_factura INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_cobrado DECIMAL(14,2) DEFAULT 0;
  DECLARE v_sena    DECIMAL(14,2) DEFAULT 0;

  SELECT COALESCE(SUM(monto), 0) INTO v_cobrado
  FROM cobro WHERE id_factura = p_id_factura AND id_estado_cobro = 1;

  -- la sena que el cliente dejo al reservar ya es plata cobrada de esa factura
  SELECT COALESCE(SUM(co.monto), 0) INTO v_sena
  FROM factura f
  JOIN cobro co ON co.id_cita = f.id_cita AND co.id_estado_cobro = 1
  WHERE f.id_factura = p_id_factura AND f.id_cita IS NOT NULL;

  RETURN fn_factura_total(p_id_factura) - v_cobrado - v_sena;
END $$

-- Vencimiento = fecha de emision + los dias que da la condicion de venta
DROP FUNCTION IF EXISTS fn_factura_vencimiento $$
CREATE FUNCTION fn_factura_vencimiento (p_id_factura INT UNSIGNED)
  RETURNS DATE
  READS SQL DATA
BEGIN
  DECLARE v_venc DATE DEFAULT NULL;
  SELECT DATE(f.fecha_emision) + INTERVAL cv.dias_credito DAY INTO v_venc
  FROM factura f
  JOIN condicion_venta cv ON cv.id_condicion_venta = f.id_condicion_venta
  WHERE f.id_factura = p_id_factura;
  RETURN v_venc;
END $$

-- Numero formateado: el establecimiento y el punto salen del timbrado, el
-- correlativo de la factura. Antes esto era una sola columna de texto.
DROP FUNCTION IF EXISTS fn_factura_nro $$
CREATE FUNCTION fn_factura_nro (p_id_factura INT UNSIGNED)
  RETURNS VARCHAR(20)
  READS SQL DATA
BEGIN
  DECLARE v_nro VARCHAR(20) DEFAULT NULL;
  SELECT CONCAT(t.establecimiento, '-', t.punto_expedicion, '-', LPAD(f.nro_correlativo, 7, '0'))
    INTO v_nro
  FROM factura f JOIN timbrado t ON t.id_timbrado = f.id_timbrado
  WHERE f.id_factura = p_id_factura;
  RETURN v_nro;
END $$

DROP FUNCTION IF EXISTS fn_timbrado_vigente $$
CREATE FUNCTION fn_timbrado_vigente (p_id_tipo_comprobante INT UNSIGNED, p_fecha DATE)
  RETURNS INT UNSIGNED
  READS SQL DATA
BEGIN
  DECLARE v_id INT UNSIGNED DEFAULT NULL;
  SELECT t.id_timbrado INTO v_id
  FROM timbrado t
  WHERE t.id_tipo_comprobante = p_id_tipo_comprobante
    AND t.activo = 1
    AND p_fecha BETWEEN t.fecha_inicio AND t.fecha_fin
  ORDER BY t.fecha_fin ASC, t.id_timbrado ASC
  LIMIT 1;
  RETURN v_id;
END $$

DROP FUNCTION IF EXISTS fn_siguiente_correlativo $$
CREATE FUNCTION fn_siguiente_correlativo (p_id_timbrado INT UNSIGNED)
  RETURNS INT UNSIGNED
  READS SQL DATA
BEGIN
  DECLARE v_desde  INT UNSIGNED DEFAULT 0;
  DECLARE v_hasta  INT UNSIGNED DEFAULT 0;
  DECLARE v_ultimo INT UNSIGNED DEFAULT 0;
  DECLARE v_sig    INT UNSIGNED DEFAULT 0;

  SELECT nro_desde, nro_hasta INTO v_desde, v_hasta
  FROM timbrado WHERE id_timbrado = p_id_timbrado;

  IF v_desde = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El timbrado indicado no existe.';
  END IF;

  SELECT COALESCE(MAX(nro_correlativo), 0) INTO v_ultimo
  FROM factura WHERE id_timbrado = p_id_timbrado;

  SET v_sig = IF(v_ultimo < v_desde, v_desde, v_ultimo + 1);

  IF v_sig > v_hasta THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El rango del timbrado esta agotado.';
  END IF;

  RETURN v_sig;
END $$

-- ---------- Caja y personal ----------

-- Saldo de caja: lo que habia al abrir, mas los cobros y senas que entraron en
-- esa caja, mas los ingresos manuales, menos los egresos y los pagos a
-- proveedores que salieron de ella.
DROP FUNCTION IF EXISTS fn_caja_saldo $$
CREATE FUNCTION fn_caja_saldo (p_id_caja INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_inicial DECIMAL(14,2) DEFAULT 0;
  DECLARE v_cobros  DECIMAL(14,2) DEFAULT 0;
  DECLARE v_ing     DECIMAL(14,2) DEFAULT 0;
  DECLARE v_egr     DECIMAL(14,2) DEFAULT 0;
  DECLARE v_prov    DECIMAL(14,2) DEFAULT 0;

  SELECT monto_inicial INTO v_inicial FROM caja WHERE id_caja = p_id_caja;

  SELECT COALESCE(SUM(monto), 0) INTO v_cobros
  FROM cobro WHERE id_caja = p_id_caja AND id_estado_cobro = 1;

  SELECT COALESCE(SUM(CASE WHEN tipo = 'INGRESO' THEN monto END), 0),
         COALESCE(SUM(CASE WHEN tipo = 'EGRESO'  THEN monto END), 0)
    INTO v_ing, v_egr
  FROM movimiento_caja WHERE id_caja = p_id_caja;

  SELECT COALESCE(SUM(fn_pago_proveedor_monto(pp.id_pago_proveedor)), 0) INTO v_prov
  FROM pago_proveedor pp
  WHERE pp.id_caja = p_id_caja AND pp.id_estado_pago_proveedor = 1;

  RETURN COALESCE(v_inicial, 0) + v_cobros + v_ing - v_egr - v_prov;
END $$

-- Comision del servicio realizado. El porcentaje ya no se pasa a mano: sale de
-- la tabla comision, tomando la regla vigente a la fecha en que se hizo el
-- trabajo. Una regla para ese servicio puntual le gana a la regla general.
DROP FUNCTION IF EXISTS fn_comision_servicio $$
CREATE FUNCTION fn_comision_servicio (p_id_servicio_realizado INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_usuario  INT UNSIGNED DEFAULT NULL;
  DECLARE v_servicio INT UNSIGNED DEFAULT NULL;
  DECLARE v_fecha    DATE;
  DECLARE v_precio   DECIMAL(12,2) DEFAULT 0;
  DECLARE v_tipo     VARCHAR(20)   DEFAULT NULL;
  DECLARE v_valor    DECIMAL(12,2) DEFAULT 0;

  SELECT sr.id_usuario, sr.id_servicio, DATE(sr.fecha_hora), s.precio
    INTO v_usuario, v_servicio, v_fecha, v_precio
  FROM servicio_realizado sr
  JOIN servicio s ON s.id_servicio = sr.id_servicio
  WHERE sr.id_servicio_realizado = p_id_servicio_realizado;

  IF v_usuario IS NULL THEN RETURN 0; END IF;

  SELECT c.tipo, c.valor INTO v_tipo, v_valor
  FROM comision c
  WHERE c.id_usuario = v_usuario
    AND (c.id_servicio = v_servicio OR c.id_servicio IS NULL)
    AND c.activo = 1
    AND c.vigente_desde <= v_fecha
  ORDER BY (c.id_servicio IS NULL) ASC, c.vigente_desde DESC
  LIMIT 1;

  IF v_tipo IS NULL THEN RETURN 0; END IF;
  IF v_tipo = 'PORCENTAJE' THEN
    RETURN ROUND(COALESCE(v_precio, 0) * v_valor / 100, 2);
  END IF;
  RETURN v_valor;
END $$

-- 1 si el profesional esta habilitado para ese servicio. Mientras no se le
-- cargue ninguna habilitacion, el sistema no lo restringe.
DROP FUNCTION IF EXISTS fn_puede_realizar $$
CREATE FUNCTION fn_puede_realizar (p_id_usuario INT UNSIGNED, p_id_servicio INT UNSIGNED)
  RETURNS TINYINT(1)
  READS SQL DATA
BEGIN
  DECLARE v_cargadas INT DEFAULT 0;
  DECLARE v_hab      INT DEFAULT 0;

  SELECT COUNT(*) INTO v_cargadas FROM usuario_servicio
   WHERE id_usuario = p_id_usuario AND activo = 1;
  IF v_cargadas = 0 THEN RETURN 1; END IF;

  SELECT COUNT(*) INTO v_hab FROM usuario_servicio
   WHERE id_usuario = p_id_usuario AND id_servicio = p_id_servicio AND activo = 1;
  RETURN IF(v_hab > 0, 1, 0);
END $$

-- Sena tomada al reservar la cita
DROP FUNCTION IF EXISTS fn_cita_sena $$
CREATE FUNCTION fn_cita_sena (p_id_cita INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(monto), 0) INTO v
  FROM cobro WHERE id_cita = p_id_cita AND id_estado_cobro = 1;
  RETURN v;
END $$

-- ---------- Proveedores ----------

DROP FUNCTION IF EXISTS fn_pago_proveedor_monto $$
CREATE FUNCTION fn_pago_proveedor_monto (p_id_pago INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(monto_aplicado), 0) INTO v
  FROM detalle_pago_proveedor WHERE id_pago_proveedor = p_id_pago;
  RETURN v;
END $$

-- Lo que todavia se le debe al proveedor por esa compra
DROP FUNCTION IF EXISTS fn_compra_saldo $$
CREATE FUNCTION fn_compra_saldo (p_id_compra INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v_pagado DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(d.monto_aplicado), 0) INTO v_pagado
  FROM detalle_pago_proveedor d
  JOIN pago_proveedor pp ON pp.id_pago_proveedor = d.id_pago_proveedor
  WHERE d.id_compra = p_id_compra AND pp.id_estado_pago_proveedor = 1;
  RETURN fn_compra_total(p_id_compra) - v_pagado;
END $$

DROP FUNCTION IF EXISTS fn_compra_vencimiento $$
CREATE FUNCTION fn_compra_vencimiento (p_id_compra INT UNSIGNED)
  RETURNS DATE
  READS SQL DATA
BEGIN
  DECLARE v_venc DATE DEFAULT NULL;
  SELECT DATE(c.fecha) + INTERVAL cv.dias_credito DAY INTO v_venc
  FROM compra c
  JOIN condicion_venta cv ON cv.id_condicion_venta = c.id_condicion_venta
  WHERE c.id_compra = p_id_compra;
  RETURN v_venc;
END $$

-- Deuda total con un proveedor: la suma de los saldos de sus compras confirmadas
DROP FUNCTION IF EXISTS fn_proveedor_saldo $$
CREATE FUNCTION fn_proveedor_saldo (p_id_proveedor INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(fn_compra_saldo(c.id_compra)), 0) INTO v
  FROM compra c
  WHERE c.id_proveedor = p_id_proveedor AND c.id_estado_compra = 2;
  RETURN v;
END $$

DROP FUNCTION IF EXISTS fn_pago_personal_monto $$
CREATE FUNCTION fn_pago_personal_monto (p_id_pago INT UNSIGNED)
  RETURNS DECIMAL(14,2)
  READS SQL DATA
BEGIN
  DECLARE v DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(monto), 0) INTO v
  FROM detalle_pago_personal WHERE id_pago_personal = p_id_pago;
  RETURN v;
END $$

DELIMITER ;

-- =====================================================================
--  4) TRIGGERS
--  Sin columnas derivadas ya no hacen falta los triggers que las mantenian.
--  Quedan solo los que cuidan reglas de negocio que el modelo no puede
--  expresar con una restriccion.
-- =====================================================================
DELIMITER $$

-- ---------- Citas ----------

DROP TRIGGER IF EXISTS trg_cita_bi $$
CREATE TRIGGER trg_cita_bi
BEFORE INSERT ON cita FOR EACH ROW
BEGIN
  IF fn_es_personal(NEW.id_usuario) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La cita debe asignarse a un usuario del personal.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cita_bu $$
CREATE TRIGGER trg_cita_bu
BEFORE UPDATE ON cita FOR EACH ROW
BEGIN
  IF fn_es_personal(NEW.id_usuario) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La cita debe asignarse a un usuario del personal.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_srealizado_bi $$
CREATE TRIGGER trg_srealizado_bi
BEFORE INSERT ON servicio_realizado FOR EACH ROW
BEGIN
  IF fn_es_personal(NEW.id_usuario) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El servicio debe registrarse a nombre de un usuario del personal.';
  END IF;

  IF fn_puede_realizar(NEW.id_usuario, NEW.id_servicio) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese profesional no esta habilitado para ese servicio.';
  END IF;
END $$

-- No se puede agendar un servicio con alguien que no lo sabe hacer
DROP TRIGGER IF EXISTS trg_citaserv_bi $$
CREATE TRIGGER trg_citaserv_bi
BEFORE INSERT ON cita_servicio FOR EACH ROW
BEGIN
  DECLARE v_usuario INT UNSIGNED DEFAULT NULL;
  SELECT id_usuario INTO v_usuario FROM cita WHERE id_cita = NEW.id_cita;

  IF fn_puede_realizar(v_usuario, NEW.id_servicio) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El profesional de la cita no esta habilitado para ese servicio.';
  END IF;
END $$

-- ---------- Inventario ----------

-- Antes el stock negativo lo frenaba un CHECK sobre producto.stock_actual. Sin
-- esa columna, el control se hace aca: se suma el libro y se compara.
DROP TRIGGER IF EXISTS trg_movinv_bi $$
CREATE TRIGGER trg_movinv_bi
BEFORE INSERT ON movimiento_inventario FOR EACH ROW
BEGIN
  DECLARE v_signo CHAR(1);
  DECLARE v_stock DECIMAL(12,2) DEFAULT 0;

  SELECT signo INTO v_signo FROM tipo_movimiento_inventario
   WHERE id_tipo_movimiento = NEW.id_tipo_movimiento;

  IF v_signo = 'S' THEN
    SELECT COALESCE(SUM(CASE WHEN t.signo = 'E' THEN m.cantidad ELSE -m.cantidad END), 0)
      INTO v_stock
    FROM movimiento_inventario m
    JOIN tipo_movimiento_inventario t ON t.id_tipo_movimiento = m.id_tipo_movimiento
    WHERE m.id_producto = NEW.id_producto;

    IF v_stock - NEW.cantidad < 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay stock suficiente para esa salida.';
    END IF;
  END IF;
END $$

-- Alerta de reposicion. Solo avisa una vez: si ya hay una notificacion
-- pendiente para ese producto, no genera otra.
DROP TRIGGER IF EXISTS trg_movinv_ai $$
CREATE TRIGGER trg_movinv_ai
AFTER INSERT ON movimiento_inventario FOR EACH ROW
BEGIN
  DECLARE v_stock  DECIMAL(12,2) DEFAULT 0;
  DECLARE v_minimo DECIMAL(10,2) DEFAULT 0;
  DECLARE v_nombre VARCHAR(100);
  DECLARE v_activo TINYINT(1) DEFAULT 0;

  SELECT nombre, stock_minimo, activo INTO v_nombre, v_minimo, v_activo
  FROM producto WHERE id_producto = NEW.id_producto;

  SET v_stock = fn_producto_stock(NEW.id_producto);

  IF v_activo = 1 AND v_stock <= v_minimo
     AND NOT EXISTS (SELECT 1 FROM notificacion
                      WHERE id_producto = NEW.id_producto
                        AND id_tipo_notificacion = 5
                        AND estado = 'PENDIENTE') THEN
    INSERT INTO notificacion (id_tipo_notificacion, id_producto, canal, mensaje, estado)
    VALUES (5, NEW.id_producto, 'SISTEMA',
            CONCAT('El producto ', v_nombre, ' quedo en ', v_stock,
                   ' (minimo ', v_minimo, '). Conviene reponer.'),
            'PENDIENTE');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_produtil_ai $$
CREATE TRIGGER trg_produtil_ai
AFTER INSERT ON producto_utilizado FOR EACH ROW
BEGIN
  DECLARE v_usuario INT UNSIGNED;
  SELECT id_usuario INTO v_usuario FROM servicio_realizado
   WHERE id_servicio_realizado = NEW.id_servicio_realizado;

  INSERT INTO movimiento_inventario (id_producto, id_usuario, id_tipo_movimiento, cantidad, referencia, observaciones)
  VALUES (NEW.id_producto, v_usuario, 2, NEW.cantidad,
          CONCAT('SR#', NEW.id_servicio_realizado), 'Consumo durante el servicio');
END $$

-- ---------- Fidelizacion ----------

-- El saldo de puntos no puede quedar negativo. Antes lo cuidaba un CHECK sobre
-- cliente.puntos; ahora se valida contra la suma del libro.
DROP TRIGGER IF EXISTS trg_movpunto_bi $$
CREATE TRIGGER trg_movpunto_bi
BEFORE INSERT ON movimiento_punto FOR EACH ROW
BEGIN
  IF fn_cliente_puntos(NEW.id_cliente) + NEW.puntos < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cliente no tiene tantos puntos para canjear.';
  END IF;
END $$

-- ---------- Facturacion ----------

DROP TRIGGER IF EXISTS trg_factura_bi $$
CREATE TRIGGER trg_factura_bi
BEFORE INSERT ON factura FOR EACH ROW
BEGIN
  DECLARE v_tipo_tim INT UNSIGNED;
  DECLARE v_ini      DATE;
  DECLARE v_fin      DATE;
  DECLARE v_desde    INT UNSIGNED;
  DECLARE v_hasta    INT UNSIGNED;
  DECLARE v_activo   TINYINT(1);
  DECLARE v_req      TINYINT(1) DEFAULT 0;

  SELECT id_tipo_comprobante, fecha_inicio, fecha_fin, nro_desde, nro_hasta, activo
    INTO v_tipo_tim, v_ini, v_fin, v_desde, v_hasta, v_activo
  FROM timbrado WHERE id_timbrado = NEW.id_timbrado;

  IF COALESCE(v_activo, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El timbrado no existe o no esta activo.';
  END IF;

  IF v_tipo_tim <> NEW.id_tipo_comprobante THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El timbrado no corresponde a ese tipo de comprobante.';
  END IF;

  IF DATE(NEW.fecha_emision) < v_ini OR DATE(NEW.fecha_emision) > v_fin THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La fecha de emision esta fuera de la vigencia del timbrado.';
  END IF;

  IF NEW.nro_correlativo < v_desde OR NEW.nro_correlativo > v_hasta THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El correlativo esta fuera del rango del timbrado.';
  END IF;

  SELECT requiere_origen INTO v_req FROM tipo_comprobante
   WHERE id_tipo_comprobante = NEW.id_tipo_comprobante;

  IF v_req = 1 AND NEW.id_factura_origen IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de comprobante necesita la factura de origen.';
  END IF;

  IF v_req = 0 AND NEW.id_factura_origen IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este tipo de comprobante no admite factura de origen.';
  END IF;
END $$

-- Lo unico que sigue haciendo el detalle: mover el stock cuando la linea es un
-- producto. Una venta lo descuenta, una nota de credito lo devuelve.
DROP TRIGGER IF EXISTS trg_detfactura_ai $$
CREATE TRIGGER trg_detfactura_ai
AFTER INSERT ON detalle_factura FOR EACH ROW
BEGIN
  DECLARE v_signo   TINYINT;
  DECLARE v_usuario INT UNSIGNED;

  IF NEW.id_producto IS NOT NULL THEN
    SELECT tc.signo, f.id_usuario INTO v_signo, v_usuario
    FROM factura f
    JOIN tipo_comprobante tc ON tc.id_tipo_comprobante = f.id_tipo_comprobante
    WHERE f.id_factura = NEW.id_factura;

    IF v_signo = 1 THEN
      INSERT INTO movimiento_inventario (id_producto, id_usuario, id_tipo_movimiento, cantidad, precio_unitario, referencia, observaciones)
      VALUES (NEW.id_producto, v_usuario, 7, NEW.cantidad, NEW.precio_unitario,
              CONCAT('FAC#', NEW.id_factura), 'Venta de producto facturada');
    ELSEIF v_signo = -1 THEN
      INSERT INTO movimiento_inventario (id_producto, id_usuario, id_tipo_movimiento, cantidad, precio_unitario, referencia, observaciones)
      VALUES (NEW.id_producto, v_usuario, 6, NEW.cantidad, NEW.precio_unitario,
              CONCAT('NC#', NEW.id_factura), 'Devolucion por nota de credito');
    END IF;
  END IF;
END $$

-- ---------- Caja ----------

DROP TRIGGER IF EXISTS trg_caja_bi $$
CREATE TRIGGER trg_caja_bi
BEFORE INSERT ON caja FOR EACH ROW
BEGIN
  IF NEW.id_estado_caja = 1
     AND EXISTS (SELECT 1 FROM caja WHERE id_usuario = NEW.id_usuario AND id_estado_caja = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese usuario ya tiene una caja abierta.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cobrotarjeta_bi $$
CREATE TRIGGER trg_cobrotarjeta_bi
BEFORE INSERT ON cobro_tarjeta FOR EACH ROW
BEGIN
  DECLARE v_tipo VARCHAR(10);
  SELECT mp.tipo INTO v_tipo
  FROM cobro c JOIN metodo_pago mp ON mp.id_metodo_pago = c.id_metodo_pago
  WHERE c.id_cobro = NEW.id_cobro;

  IF v_tipo <> 'TARJETA' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cobro no fue realizado con tarjeta.';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cobrobanco_bi $$
CREATE TRIGGER trg_cobrobanco_bi
BEFORE INSERT ON cobro_banco FOR EACH ROW
BEGIN
  DECLARE v_tipo VARCHAR(10);
  SELECT mp.tipo INTO v_tipo
  FROM cobro c JOIN metodo_pago mp ON mp.id_metodo_pago = c.id_metodo_pago
  WHERE c.id_cobro = NEW.id_cobro;

  IF v_tipo NOT IN ('BANCO', 'CHEQUE') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cobro no fue realizado por banco ni con cheque.';
  END IF;
END $$

-- ---------- Auditoria ----------

DROP TRIGGER IF EXISTS trg_factura_au $$
CREATE TRIGGER trg_factura_au
AFTER UPDATE ON factura FOR EACH ROW
BEGIN
  IF OLD.id_estado_factura <> 2 AND NEW.id_estado_factura = 2 THEN
    INSERT INTO auditoria (id_usuario, accion, modulo, tabla_afectada, id_registro, detalle)
    VALUES (COALESCE(@usuario_actual, NEW.id_usuario), 'ANULAR', 'Facturacion', 'factura', NEW.id_factura,
            CONCAT('Comprobante ', fn_factura_nro(NEW.id_factura), ' anulado.'));
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cobro_au $$
CREATE TRIGGER trg_cobro_au
AFTER UPDATE ON cobro FOR EACH ROW
BEGIN
  IF OLD.id_estado_cobro <> 3 AND NEW.id_estado_cobro = 3 THEN
    INSERT INTO auditoria (id_usuario, accion, modulo, tabla_afectada, id_registro, detalle)
    VALUES (COALESCE(@usuario_actual, NEW.id_usuario), 'ANULAR', 'Cobros', 'cobro', NEW.id_cobro,
            CONCAT('Cobro anulado. Monto: ', NEW.monto));
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pagoproveedor_au $$
CREATE TRIGGER trg_pagoproveedor_au
AFTER UPDATE ON pago_proveedor FOR EACH ROW
BEGIN
  IF OLD.id_estado_pago_proveedor <> 2 AND NEW.id_estado_pago_proveedor = 2 THEN
    INSERT INTO auditoria (id_usuario, accion, modulo, tabla_afectada, id_registro, detalle)
    VALUES (COALESCE(@usuario_actual, NEW.id_usuario), 'ANULAR', 'Proveedores', 'pago_proveedor', NEW.id_pago_proveedor,
            CONCAT('Pago a proveedor anulado. Monto: ', fn_pago_proveedor_monto(NEW.id_pago_proveedor)));
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pagopersonal_au $$
CREATE TRIGGER trg_pagopersonal_au
AFTER UPDATE ON pago_personal FOR EACH ROW
BEGIN
  IF OLD.id_estado_pago NOT IN (3, 4) AND NEW.id_estado_pago IN (3, 4) THEN
    INSERT INTO auditoria (id_usuario, accion, modulo, tabla_afectada, id_registro, detalle)
    VALUES (COALESCE(@usuario_actual, NEW.id_usuario_registro),
            IF(NEW.id_estado_pago = 4, 'REVERTIR', 'ANULAR'), 'Pagos', 'pago_personal', NEW.id_pago_personal,
            CONCAT('Pago al personal del periodo ', COALESCE(NEW.periodo, 'sin periodo')));
  END IF;
END $$

DELIMITER ;

-- =====================================================================
--  5) PROCEDIMIENTOS
-- =====================================================================
DELIMITER $$

-- ---------- Citas ----------

DROP PROCEDURE IF EXISTS sp_agendar_cita $$
CREATE PROCEDURE sp_agendar_cita (
    IN  p_id_cliente    INT UNSIGNED,
    IN  p_id_usuario    INT UNSIGNED,
    IN  p_fecha_hora    DATETIME,
    IN  p_duracion_min  INT,
    IN  p_observaciones VARCHAR(300),
    OUT p_id_cita       INT UNSIGNED)
BEGIN
  IF fn_verificar_disponibilidad(p_id_usuario, p_fecha_hora, p_duracion_min, NULL) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El profesional no esta disponible en ese horario.';
  END IF;

  INSERT INTO cita (id_cliente, id_usuario, id_estado_cita, fecha_hora, observaciones)
  VALUES (p_id_cliente, p_id_usuario, 1, p_fecha_hora, p_observaciones);
  SET p_id_cita = LAST_INSERT_ID();

  INSERT INTO notificacion (id_tipo_notificacion, id_cliente, id_cita, canal, mensaje, estado)
  VALUES (2, p_id_cliente, p_id_cita, 'WHATSAPP',
          CONCAT('Cita confirmada para el ', DATE_FORMAT(p_fecha_hora, '%d/%m/%Y a las %H:%i'), '.'),
          'PENDIENTE');
END $$

DROP PROCEDURE IF EXISTS sp_reprogramar_cita $$
CREATE PROCEDURE sp_reprogramar_cita (
    IN p_id_cita     INT UNSIGNED,
    IN p_nueva_fecha DATETIME)
BEGIN
  DECLARE v_usuario INT UNSIGNED DEFAULT NULL;

  SELECT id_usuario INTO v_usuario FROM cita WHERE id_cita = p_id_cita;

  IF v_usuario IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La cita no existe.';
  END IF;

  IF fn_verificar_disponibilidad(v_usuario, p_nueva_fecha, fn_cita_duracion(p_id_cita), p_id_cita) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El profesional no esta disponible en el nuevo horario.';
  END IF;

  UPDATE cita SET fecha_hora = p_nueva_fecha, id_estado_cita = 2 WHERE id_cita = p_id_cita;
END $$

DROP PROCEDURE IF EXISTS sp_cancelar_cita $$
CREATE PROCEDURE sp_cancelar_cita (IN p_id_cita INT UNSIGNED)
BEGIN
  DECLARE v_cliente INT UNSIGNED DEFAULT NULL;
  SELECT id_cliente INTO v_cliente FROM cita WHERE id_cita = p_id_cita;

  UPDATE cita SET id_estado_cita = 3 WHERE id_cita = p_id_cita;

  IF v_cliente IS NOT NULL THEN
    INSERT INTO notificacion (id_tipo_notificacion, id_cliente, id_cita, canal, mensaje, estado)
    VALUES (3, v_cliente, p_id_cita, 'WHATSAPP', 'Tu cita fue cancelada.', 'PENDIENTE');
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_generar_recordatorios $$
CREATE PROCEDURE sp_generar_recordatorios (IN p_horas INT)
BEGIN
  INSERT INTO notificacion (id_tipo_notificacion, id_cliente, id_cita, canal, mensaje, estado)
  SELECT 1, c.id_cliente, c.id_cita, 'WHATSAPP',
         CONCAT('Recordatorio: tu cita es el ', DATE_FORMAT(c.fecha_hora, '%d/%m/%Y a las %H:%i'), '.'),
         'PENDIENTE'
  FROM cita c
  JOIN estado_cita ec ON ec.id_estado_cita = c.id_estado_cita
  LEFT JOIN notificacion n ON n.id_cita = c.id_cita AND n.id_tipo_notificacion = 1
  WHERE ec.bloquea_agenda = 1
    AND c.fecha_hora BETWEEN NOW() AND (NOW() + INTERVAL p_horas HOUR)
    AND n.id_notificacion IS NULL;
END $$

-- ---------- Inventario ----------

DROP PROCEDURE IF EXISTS sp_registrar_movimiento_inventario $$
CREATE PROCEDURE sp_registrar_movimiento_inventario (
    IN p_id_producto        INT UNSIGNED,
    IN p_id_usuario         INT UNSIGNED,
    IN p_id_tipo_movimiento INT UNSIGNED,
    IN p_cantidad           DECIMAL(10,2),
    IN p_precio_unitario    DECIMAL(12,2),
    IN p_referencia         VARCHAR(40),
    IN p_observaciones      VARCHAR(300))
BEGIN
  INSERT INTO movimiento_inventario (id_producto, id_usuario, id_tipo_movimiento, cantidad, precio_unitario, referencia, observaciones)
  VALUES (p_id_producto, p_id_usuario, p_id_tipo_movimiento, p_cantidad, p_precio_unitario, p_referencia, p_observaciones);
END $$

DROP PROCEDURE IF EXISTS sp_confirmar_compra $$
CREATE PROCEDURE sp_confirmar_compra (
    IN p_id_compra  INT UNSIGNED,
    IN p_id_usuario INT UNSIGNED)
BEGIN
  DECLARE v_estado INT UNSIGNED DEFAULT NULL;
  SELECT id_estado_compra INTO v_estado FROM compra WHERE id_compra = p_id_compra;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La compra no existe.';
  END IF;

  IF v_estado = 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La compra ya fue confirmada.';
  END IF;

  INSERT INTO movimiento_inventario (id_producto, id_usuario, id_tipo_movimiento, cantidad, precio_unitario, referencia, observaciones)
  SELECT dc.id_producto, p_id_usuario, 1, dc.cantidad, dc.precio_unitario,
         CONCAT('COM#', p_id_compra), 'Entrada por compra confirmada'
  FROM detalle_compra dc WHERE dc.id_compra = p_id_compra;

  UPDATE producto p
    JOIN detalle_compra dc ON dc.id_producto = p.id_producto
     SET p.precio_costo = dc.precio_unitario
   WHERE dc.id_compra = p_id_compra;

  UPDATE compra SET id_estado_compra = 2 WHERE id_compra = p_id_compra;
END $$

-- ---------- Facturacion ----------

DROP PROCEDURE IF EXISTS sp_aplicar_descuento $$
CREATE PROCEDURE sp_aplicar_descuento (
    IN p_id_factura   INT UNSIGNED,
    IN p_id_descuento INT UNSIGNED)
BEGIN
  DECLARE v_base        DECIMAL(14,2) DEFAULT 0;
  DECLARE v_monto       DECIMAL(14,2) DEFAULT 0;
  DECLARE v_restringido INT DEFAULT 0;

  SELECT COUNT(*) INTO v_restringido FROM servicio_descuento WHERE id_descuento = p_id_descuento;

  IF v_restringido > 0 THEN
    SELECT COALESCE(SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 0) INTO v_base
    FROM detalle_factura df
    JOIN servicio_descuento sd ON sd.id_servicio = df.id_servicio AND sd.id_descuento = p_id_descuento
    WHERE df.id_factura = p_id_factura;
  ELSE
    SET v_base = fn_factura_subtotal(p_id_factura);
  END IF;

  SET v_monto = fn_descuento_monto(p_id_descuento, v_base);

  IF v_monto > 0 THEN
    INSERT INTO factura_descuento (id_factura, id_descuento, monto_aplicado)
    VALUES (p_id_factura, p_id_descuento, v_monto)
    ON DUPLICATE KEY UPDATE monto_aplicado = v_monto;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_emitir_factura $$
CREATE PROCEDURE sp_emitir_factura (
    IN  p_id_cliente          INT UNSIGNED,
    IN  p_id_cita             INT UNSIGNED,
    IN  p_id_usuario          INT UNSIGNED,
    IN  p_id_tipo_comprobante INT UNSIGNED,
    IN  p_id_condicion_venta  INT UNSIGNED,
    OUT p_id_factura          INT UNSIGNED)
BEGIN
  DECLARE v_timbrado  INT UNSIGNED DEFAULT NULL;
  DECLARE v_nro       INT UNSIGNED DEFAULT 0;
  DECLARE v_descuento INT UNSIGNED DEFAULT NULL;

  SET v_timbrado = fn_timbrado_vigente(p_id_tipo_comprobante, CURRENT_DATE);
  IF v_timbrado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay timbrado vigente para ese tipo de comprobante.';
  END IF;

  SET v_nro = fn_siguiente_correlativo(v_timbrado);

  INSERT INTO factura (id_cliente, id_cita, id_usuario, id_tipo_comprobante, id_condicion_venta,
                       id_timbrado, id_estado_factura, nro_correlativo)
  VALUES (p_id_cliente, p_id_cita, p_id_usuario, p_id_tipo_comprobante, p_id_condicion_venta,
          v_timbrado, 1, v_nro);
  SET p_id_factura = LAST_INSERT_ID();

  IF p_id_cita IS NOT NULL THEN
    INSERT INTO detalle_factura (id_factura, id_servicio, cantidad, precio_unitario, tasa_iva)
    SELECT p_id_factura, s.id_servicio, 1, s.precio, s.tasa_iva
    FROM cita_servicio cs
    JOIN servicio s ON s.id_servicio = cs.id_servicio
    WHERE cs.id_cita = p_id_cita;

    UPDATE servicio_realizado sr
      JOIN detalle_factura df
        ON df.id_factura = p_id_factura AND df.id_servicio = sr.id_servicio
       SET sr.id_detalle_factura = df.id_detalle_factura
     WHERE sr.id_cita = p_id_cita AND sr.id_detalle_factura IS NULL;
  END IF;

  SET v_descuento = fn_cliente_descuento(p_id_cliente);
  IF v_descuento IS NOT NULL THEN
    CALL sp_aplicar_descuento(p_id_factura, v_descuento);
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_emitir_nota_credito $$
CREATE PROCEDURE sp_emitir_nota_credito (
    IN  p_id_factura_origen INT UNSIGNED,
    IN  p_id_usuario        INT UNSIGNED,
    IN  p_motivo            VARCHAR(300),
    OUT p_id_nota           INT UNSIGNED)
BEGIN
  DECLARE v_cliente   INT UNSIGNED DEFAULT NULL;
  DECLARE v_signo     TINYINT DEFAULT 0;
  DECLARE v_condicion INT UNSIGNED DEFAULT 1;
  DECLARE v_timbrado  INT UNSIGNED DEFAULT NULL;
  DECLARE v_nro       INT UNSIGNED DEFAULT 0;

  SELECT f.id_cliente, f.id_condicion_venta, tc.signo
    INTO v_cliente, v_condicion, v_signo
  FROM factura f
  JOIN tipo_comprobante tc ON tc.id_tipo_comprobante = f.id_tipo_comprobante
  WHERE f.id_factura = p_id_factura_origen;

  IF v_cliente IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La factura de origen no existe.';
  END IF;

  IF v_signo <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se puede acreditar un comprobante de venta.';
  END IF;

  SET v_timbrado = fn_timbrado_vigente(5, CURRENT_DATE);
  IF v_timbrado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay timbrado vigente para notas de credito.';
  END IF;

  SET v_nro = fn_siguiente_correlativo(v_timbrado);

  INSERT INTO factura (id_cliente, id_cita, id_usuario, id_tipo_comprobante, id_condicion_venta,
                       id_timbrado, id_estado_factura, id_factura_origen, nro_correlativo, observaciones)
  VALUES (v_cliente, NULL, p_id_usuario, 5, v_condicion,
          v_timbrado, 1, p_id_factura_origen, v_nro, p_motivo);
  SET p_id_nota = LAST_INSERT_ID();

  INSERT INTO detalle_factura (id_factura, id_servicio, id_producto, cantidad, precio_unitario, tasa_iva)
  SELECT p_id_nota, df.id_servicio, df.id_producto, df.cantidad, df.precio_unitario, df.tasa_iva
  FROM detalle_factura df
  WHERE df.id_factura = p_id_factura_origen;

  INSERT INTO factura_descuento (id_factura, id_descuento, monto_aplicado)
  SELECT p_id_nota, fd.id_descuento, fd.monto_aplicado
  FROM factura_descuento fd
  WHERE fd.id_factura = p_id_factura_origen;
END $$

DROP PROCEDURE IF EXISTS sp_anular_factura $$
CREATE PROCEDURE sp_anular_factura (
    IN p_id_factura INT UNSIGNED,
    IN p_id_usuario INT UNSIGNED)
BEGIN
  DECLARE v_cobros INT DEFAULT 0;

  SELECT COUNT(*) INTO v_cobros FROM cobro
   WHERE id_factura = p_id_factura AND id_estado_cobro = 1;

  IF v_cobros > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Anule primero los cobros de esta factura.';
  END IF;

  SET @usuario_actual = p_id_usuario;
  UPDATE factura SET id_estado_factura = 2 WHERE id_factura = p_id_factura;
  SET @usuario_actual = NULL;
END $$

-- ---------- Cobros y caja ----------

-- El cobro queda atado a la caja abierta del usuario. Anular un cobro ya no
-- necesita movimiento compensatorio: al quedar en estado Anulado, fn_caja_saldo
-- deja de sumarlo.
DROP PROCEDURE IF EXISTS sp_registrar_cobro $$
CREATE PROCEDURE sp_registrar_cobro (
    IN  p_id_factura INT UNSIGNED,
    IN  p_id_metodo  INT UNSIGNED,
    IN  p_id_usuario INT UNSIGNED,
    IN  p_monto      DECIMAL(14,2),
    IN  p_referencia VARCHAR(100),
    OUT p_id_cobro   INT UNSIGNED)
BEGIN
  DECLARE v_estado INT UNSIGNED DEFAULT NULL;
  DECLARE v_signo  TINYINT DEFAULT 0;
  DECLARE v_saldo  DECIMAL(14,2) DEFAULT 0;
  DECLARE v_caja   INT UNSIGNED DEFAULT NULL;

  SELECT f.id_estado_factura, tc.signo
    INTO v_estado, v_signo
  FROM factura f
  JOIN tipo_comprobante tc ON tc.id_tipo_comprobante = f.id_tipo_comprobante
  WHERE f.id_factura = p_id_factura;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La factura no existe.';
  END IF;

  IF v_estado = 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La factura esta anulada.';
  END IF;

  IF v_signo <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ese tipo de comprobante no se cobra.';
  END IF;

  SET v_saldo = fn_factura_saldo(p_id_factura);
  IF p_monto > v_saldo THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El monto supera el saldo pendiente de la factura.';
  END IF;

  SELECT id_caja INTO v_caja FROM caja
   WHERE id_usuario = p_id_usuario AND id_estado_caja = 1
   ORDER BY id_caja DESC LIMIT 1;

  INSERT INTO cobro (id_factura, id_metodo_pago, id_estado_cobro, id_usuario, id_caja, monto, referencia)
  VALUES (p_id_factura, p_id_metodo, 1, p_id_usuario, v_caja, p_monto, p_referencia);
  SET p_id_cobro = LAST_INSERT_ID();
END $$

DROP PROCEDURE IF EXISTS sp_anular_cobro $$
CREATE PROCEDURE sp_anular_cobro (
    IN p_id_cobro   INT UNSIGNED,
    IN p_id_usuario INT UNSIGNED)
BEGIN
  DECLARE v_estado INT UNSIGNED DEFAULT NULL;

  SELECT id_estado_cobro INTO v_estado FROM cobro WHERE id_cobro = p_id_cobro;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cobro no existe.';
  END IF;

  IF v_estado = 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El cobro ya estaba anulado.';
  END IF;

  SET @usuario_actual = p_id_usuario;
  UPDATE cobro SET id_estado_cobro = 3 WHERE id_cobro = p_id_cobro;
  SET @usuario_actual = NULL;
END $$

DROP PROCEDURE IF EXISTS sp_abrir_caja $$
CREATE PROCEDURE sp_abrir_caja (
    IN  p_id_usuario    INT UNSIGNED,
    IN  p_monto_inicial DECIMAL(14,2),
    OUT p_id_caja       INT UNSIGNED)
BEGIN
  INSERT INTO caja (id_usuario, id_estado_caja, monto_inicial)
  VALUES (p_id_usuario, 1, p_monto_inicial);
  SET p_id_caja = LAST_INSERT_ID();
END $$

-- Cerrar la caja ya no guarda el monto final: el saldo se calcula cuando se lo
-- necesita, con fn_caja_saldo() o con vw_caja_resumen.
DROP PROCEDURE IF EXISTS sp_cerrar_caja $$
CREATE PROCEDURE sp_cerrar_caja (IN p_id_caja INT UNSIGNED)
BEGIN
  UPDATE caja
     SET id_estado_caja = 2,
         fecha_cierre   = NOW()
   WHERE id_caja = p_id_caja AND id_estado_caja = 1;
END $$

-- ---------- Pagos al personal ----------

-- Ya no recibe el porcentaje: cada servicio se valoriza con la comision vigente
-- del profesional que lo hizo.
DROP PROCEDURE IF EXISTS sp_registrar_pago_personal $$
CREATE PROCEDURE sp_registrar_pago_personal (
    IN  p_id_usuario          INT UNSIGNED,
    IN  p_id_usuario_registro INT UNSIGNED,
    IN  p_periodo             VARCHAR(40),
    OUT p_id_pago             INT UNSIGNED)
BEGIN
  INSERT INTO pago_personal (id_usuario, id_usuario_registro, id_estado_pago, periodo)
  VALUES (p_id_usuario, p_id_usuario_registro, 1, p_periodo);
  SET p_id_pago = LAST_INSERT_ID();

  INSERT INTO detalle_pago_personal (id_pago_personal, id_servicio_realizado, monto)
  SELECT p_id_pago, sr.id_servicio_realizado, fn_comision_servicio(sr.id_servicio_realizado)
  FROM servicio_realizado sr
  LEFT JOIN detalle_pago_personal d ON d.id_servicio_realizado = sr.id_servicio_realizado
  WHERE sr.id_usuario = p_id_usuario
    AND d.id_detalle_pago IS NULL;
END $$

DROP PROCEDURE IF EXISTS sp_revertir_pago_personal $$
CREATE PROCEDURE sp_revertir_pago_personal (
    IN p_id_pago    INT UNSIGNED,
    IN p_id_usuario INT UNSIGNED)
BEGIN
  SET @usuario_actual = p_id_usuario;
  UPDATE pago_personal SET id_estado_pago = 4 WHERE id_pago_personal = p_id_pago;
  DELETE FROM detalle_pago_personal WHERE id_pago_personal = p_id_pago;
  SET @usuario_actual = NULL;
END $$

-- ---------- Fidelizacion ----------

-- ---------- Sena de la cita ----------

-- La sena entra a la caja como cualquier cobro, pero no cuelga de una factura
-- sino de la cita. Cuando esa cita se factura, fn_factura_saldo la descuenta
-- sola: el cliente ya no la vuelve a pagar.
DROP PROCEDURE IF EXISTS sp_registrar_sena $$
CREATE PROCEDURE sp_registrar_sena (
    IN  p_id_cita    INT UNSIGNED,
    IN  p_id_metodo  INT UNSIGNED,
    IN  p_id_usuario INT UNSIGNED,
    IN  p_monto      DECIMAL(14,2),
    IN  p_referencia VARCHAR(100),
    OUT p_id_cobro   INT UNSIGNED)
BEGIN
  DECLARE v_estado INT UNSIGNED DEFAULT NULL;
  DECLARE v_caja   INT UNSIGNED DEFAULT NULL;

  SELECT id_estado_cita INTO v_estado FROM cita WHERE id_cita = p_id_cita;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La cita no existe.';
  END IF;

  IF p_monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La sena tiene que ser mayor que cero.';
  END IF;

  SELECT id_caja INTO v_caja FROM caja
   WHERE id_usuario = p_id_usuario AND id_estado_caja = 1
   ORDER BY id_caja DESC LIMIT 1;

  INSERT INTO cobro (id_factura, id_cita, id_metodo_pago, id_estado_cobro, id_usuario, id_caja, monto, referencia, observaciones)
  VALUES (NULL, p_id_cita, p_id_metodo, 1, p_id_usuario, v_caja, p_monto, p_referencia, 'Sena de reserva');
  SET p_id_cobro = LAST_INSERT_ID();
END $$

-- ---------- Pagos a proveedores ----------

-- Paga una compra confirmada, total o parcialmente. Si el usuario tiene una caja
-- abierta, la plata sale de ahi.
DROP PROCEDURE IF EXISTS sp_pagar_compra $$
CREATE PROCEDURE sp_pagar_compra (
    IN  p_id_compra  INT UNSIGNED,
    IN  p_id_metodo  INT UNSIGNED,
    IN  p_id_usuario INT UNSIGNED,
    IN  p_monto      DECIMAL(14,2),
    IN  p_referencia VARCHAR(100),
    OUT p_id_pago    INT UNSIGNED)
BEGIN
  DECLARE v_estado    INT UNSIGNED DEFAULT NULL;
  DECLARE v_proveedor INT UNSIGNED DEFAULT NULL;
  DECLARE v_saldo     DECIMAL(14,2) DEFAULT 0;
  DECLARE v_caja      INT UNSIGNED DEFAULT NULL;

  SELECT id_estado_compra, id_proveedor INTO v_estado, v_proveedor
  FROM compra WHERE id_compra = p_id_compra;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La compra no existe.';
  END IF;

  IF v_estado <> 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solo se paga una compra confirmada.';
  END IF;

  SET v_saldo = fn_compra_saldo(p_id_compra);

  IF p_monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El monto tiene que ser mayor que cero.';
  END IF;

  IF p_monto > v_saldo THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El monto supera el saldo pendiente de la compra.';
  END IF;

  SELECT id_caja INTO v_caja FROM caja
   WHERE id_usuario = p_id_usuario AND id_estado_caja = 1
   ORDER BY id_caja DESC LIMIT 1;

  INSERT INTO pago_proveedor (id_proveedor, id_usuario, id_metodo_pago, id_estado_pago_proveedor, id_caja, referencia)
  VALUES (v_proveedor, p_id_usuario, p_id_metodo, 1, v_caja, p_referencia);
  SET p_id_pago = LAST_INSERT_ID();

  INSERT INTO detalle_pago_proveedor (id_pago_proveedor, id_compra, monto_aplicado)
  VALUES (p_id_pago, p_id_compra, p_monto);
END $$

DROP PROCEDURE IF EXISTS sp_anular_pago_proveedor $$
CREATE PROCEDURE sp_anular_pago_proveedor (
    IN p_id_pago    INT UNSIGNED,
    IN p_id_usuario INT UNSIGNED)
BEGIN
  DECLARE v_estado INT UNSIGNED DEFAULT NULL;

  SELECT id_estado_pago_proveedor INTO v_estado
  FROM pago_proveedor WHERE id_pago_proveedor = p_id_pago;

  IF v_estado IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago no existe.';
  END IF;

  IF v_estado = 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El pago ya estaba anulado.';
  END IF;

  SET @usuario_actual = p_id_usuario;
  UPDATE pago_proveedor SET id_estado_pago_proveedor = 2 WHERE id_pago_proveedor = p_id_pago;
  SET @usuario_actual = NULL;
END $$

DROP PROCEDURE IF EXISTS sp_registrar_puntos $$
CREATE PROCEDURE sp_registrar_puntos (
    IN p_id_cliente    INT UNSIGNED,
    IN p_id_factura    INT UNSIGNED,
    IN p_tipo          VARCHAR(10),
    IN p_puntos        INT,
    IN p_observaciones VARCHAR(300))
BEGIN
  INSERT INTO movimiento_punto (id_cliente, id_factura, tipo, puntos, observaciones)
  VALUES (p_id_cliente, p_id_factura, p_tipo, p_puntos, p_observaciones);
END $$

DELIMITER ;

-- =====================================================================
--  6) VISTAS
--  Aca vive todo lo que antes eran columnas. Para el sistema PHP, consultar
--  vw_factura_resumen es equivalente a leer las viejas columnas de factura.
-- =====================================================================

-- Existencias calculadas desde el libro de movimientos
CREATE OR REPLACE VIEW vw_producto_stock AS
SELECT p.id_producto,
       p.nombre,
       cp.nombre AS categoria,
       p.unidad_medida,
       fn_producto_stock(p.id_producto) AS stock_actual,
       p.stock_minimo,
       p.precio_costo,
       p.precio_venta,
       p.activo
FROM producto p
JOIN categoria_producto cp ON cp.id_categoria = p.id_categoria;

CREATE OR REPLACE VIEW vw_producto_bajo_stock AS
SELECT id_producto,
       nombre,
       categoria,
       stock_actual,
       stock_minimo,
       (stock_minimo - stock_actual) AS faltante,
       precio_costo
FROM vw_producto_stock
WHERE activo = 1 AND stock_actual <= stock_minimo;

-- Lineas de factura con el subtotal calculado
CREATE OR REPLACE VIEW vw_detalle_factura AS
SELECT df.id_detalle_factura,
       df.id_factura,
       COALESCE(s.nombre, p.nombre) AS item,
       IF(df.id_servicio IS NOT NULL, 'Servicio', 'Producto') AS clase,
       df.cantidad,
       df.precio_unitario,
       df.tasa_iva,
       ROUND(df.cantidad * df.precio_unitario, 2) AS subtotal
FROM detalle_factura df
LEFT JOIN servicio s ON s.id_servicio = df.id_servicio
LEFT JOIN producto p ON p.id_producto = df.id_producto;

-- Cabecera del comprobante con todo lo que antes estaba guardado
CREATE OR REPLACE VIEW vw_factura_resumen AS
SELECT f.id_factura,
       f.fecha_emision,
       fn_factura_nro(f.id_factura) AS nro_comprobante,
       tc.nombre AS tipo_comprobante,
       tc.signo,
       CONCAT(cl.nombre, ' ', cl.apellido) AS cliente,
       cv.nombre AS condicion_venta,
       fn_factura_vencimiento(f.id_factura) AS fecha_vencimiento,
       ef.nombre AS estado,
       fn_factura_subtotal(f.id_factura)  AS subtotal,
       fn_factura_descuento(f.id_factura) AS descuento_total,
       fn_factura_total(f.id_factura)     AS total,
       (fn_factura_total(f.id_factura) * tc.signo) AS total_neto,
       (fn_factura_total(f.id_factura) - fn_factura_saldo(f.id_factura)) AS cobrado,
       fn_factura_saldo(f.id_factura)     AS saldo,
       fn_factura_nro(f.id_factura_origen) AS comprobante_origen
FROM factura f
JOIN tipo_comprobante tc ON tc.id_tipo_comprobante = f.id_tipo_comprobante
JOIN condicion_venta cv  ON cv.id_condicion_venta = f.id_condicion_venta
JOIN estado_factura ef   ON ef.id_estado_factura = f.id_estado_factura
JOIN cliente cl          ON cl.id_cliente = f.id_cliente;

-- IVA incluido, criterio paraguayo: con tasa 10 el impuesto es el monto sobre
-- 11, y con tasa 5 es el monto sobre 21. El descuento se prorratea.
CREATE OR REPLACE VIEW vw_factura_impuestos AS
SELECT f.id_factura,
       fn_factura_nro(f.id_factura) AS nro_comprobante,
       tc.nombre AS tipo_comprobante,
       tc.signo,
       ROUND(SUM(CASE WHEN df.tasa_iva = 10 THEN ROUND(df.cantidad * df.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(df.cantidad * df.precio_unitario, 2)) > 0,
                  (SUM(ROUND(df.cantidad * df.precio_unitario, 2)) - fn_factura_descuento(f.id_factura))
                  / SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 1), 2) AS gravado_10,
       ROUND(SUM(CASE WHEN df.tasa_iva = 10 THEN ROUND(df.cantidad * df.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(df.cantidad * df.precio_unitario, 2)) > 0,
                  (SUM(ROUND(df.cantidad * df.precio_unitario, 2)) - fn_factura_descuento(f.id_factura))
                  / SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 1) / 11, 2) AS iva_10,
       ROUND(SUM(CASE WHEN df.tasa_iva = 5 THEN ROUND(df.cantidad * df.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(df.cantidad * df.precio_unitario, 2)) > 0,
                  (SUM(ROUND(df.cantidad * df.precio_unitario, 2)) - fn_factura_descuento(f.id_factura))
                  / SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 1), 2) AS gravado_5,
       ROUND(SUM(CASE WHEN df.tasa_iva = 5 THEN ROUND(df.cantidad * df.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(df.cantidad * df.precio_unitario, 2)) > 0,
                  (SUM(ROUND(df.cantidad * df.precio_unitario, 2)) - fn_factura_descuento(f.id_factura))
                  / SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 1) / 21, 2) AS iva_5,
       ROUND(SUM(CASE WHEN df.tasa_iva = 0 THEN ROUND(df.cantidad * df.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(df.cantidad * df.precio_unitario, 2)) > 0,
                  (SUM(ROUND(df.cantidad * df.precio_unitario, 2)) - fn_factura_descuento(f.id_factura))
                  / SUM(ROUND(df.cantidad * df.precio_unitario, 2)), 1), 2) AS exentas,
       fn_factura_total(f.id_factura) AS total_comprobante
FROM factura f
JOIN tipo_comprobante tc ON tc.id_tipo_comprobante = f.id_tipo_comprobante
JOIN detalle_factura df  ON df.id_factura = f.id_factura
GROUP BY f.id_factura, tc.nombre, tc.signo;

CREATE OR REPLACE VIEW vw_compra_resumen AS
SELECT c.id_compra,
       c.fecha,
       pr.nombre AS proveedor,
       CONCAT(u.nombre, ' ', u.apellido) AS registro,
       ec.nombre AS estado,
       fn_compra_total(c.id_compra) AS total,
       c.observaciones
FROM compra c
JOIN proveedor pr     ON pr.id_proveedor = c.id_proveedor
JOIN usuario u        ON u.id_usuario = c.id_usuario
JOIN estado_compra ec ON ec.id_estado_compra = c.id_estado_compra;

CREATE OR REPLACE VIEW vw_agenda_citas AS
SELECT c.id_cita,
       c.fecha_hora,
       fn_cita_duracion(c.id_cita) AS duracion_min,
       CONCAT(cl.nombre, ' ', cl.apellido) AS cliente,
       cl.telefono,
       CONCAT(u.nombre, ' ', u.apellido)   AS profesional,
       ec.nombre AS estado,
       (SELECT GROUP_CONCAT(s.nombre ORDER BY s.nombre SEPARATOR ', ')
          FROM cita_servicio cs
          JOIN servicio s ON s.id_servicio = cs.id_servicio
         WHERE cs.id_cita = c.id_cita) AS servicios,
       c.observaciones
FROM cita c
JOIN cliente cl     ON cl.id_cliente = c.id_cliente
JOIN usuario u      ON u.id_usuario = c.id_usuario
JOIN estado_cita ec ON ec.id_estado_cita = c.id_estado_cita;

CREATE OR REPLACE VIEW vw_historial_cliente AS
SELECT cl.id_cliente,
       CONCAT(cl.nombre, ' ', cl.apellido) AS cliente,
       c.id_cita,
       sr.fecha_hora,
       s.nombre AS servicio,
       s.precio,
       CONCAT(u.nombre, ' ', u.apellido) AS profesional,
       fn_factura_nro(df.id_factura) AS nro_comprobante,
       cal.puntaje
FROM servicio_realizado sr
JOIN cita c     ON c.id_cita = sr.id_cita
JOIN cliente cl ON cl.id_cliente = c.id_cliente
JOIN servicio s ON s.id_servicio = sr.id_servicio
JOIN usuario u  ON u.id_usuario = sr.id_usuario
LEFT JOIN detalle_factura df ON df.id_detalle_factura = sr.id_detalle_factura
LEFT JOIN calificacion cal   ON cal.id_cita = c.id_cita;

-- Nivel y puntos calculados: reemplaza a cliente.id_nivel y cliente.puntos
CREATE OR REPLACE VIEW vw_cliente_fidelizacion AS
SELECT cl.id_cliente,
       CONCAT(cl.nombre, ' ', cl.apellido) AS cliente,
       cl.telefono,
       fn_cliente_visitas(cl.id_cliente) AS visitas,
       fn_cliente_puntos(cl.id_cliente)  AS puntos,
       n.nombre AS nivel,
       d.nombre AS descuento_del_nivel
FROM cliente cl
LEFT JOIN nivel n     ON n.id_nivel = fn_cliente_nivel(cl.id_cliente)
LEFT JOIN descuento d ON d.id_descuento = n.id_descuento
WHERE cl.activo = 1;

CREATE OR REPLACE VIEW vw_caja_resumen AS
SELECT ca.id_caja,
       CONCAT(u.nombre, ' ', u.apellido) AS responsable,
       ec.nombre AS estado,
       ca.fecha_apertura,
       ca.fecha_cierre,
       ca.monto_inicial,
       (SELECT COALESCE(SUM(co.monto), 0) FROM cobro co
         WHERE co.id_caja = ca.id_caja AND co.id_estado_cobro = 1) AS cobros,
       (SELECT COALESCE(SUM(mc.monto), 0) FROM movimiento_caja mc
         WHERE mc.id_caja = ca.id_caja AND mc.tipo = 'INGRESO') AS otros_ingresos,
       (SELECT COALESCE(SUM(mc.monto), 0) FROM movimiento_caja mc
         WHERE mc.id_caja = ca.id_caja AND mc.tipo = 'EGRESO') AS egresos,
       fn_caja_saldo(ca.id_caja) AS saldo
FROM caja ca
JOIN usuario u      ON u.id_usuario = ca.id_usuario
JOIN estado_caja ec ON ec.id_estado_caja = ca.id_estado_caja;

CREATE OR REPLACE VIEW vw_pago_personal_resumen AS
SELECT pp.id_pago_personal,
       pp.fecha,
       pp.periodo,
       CONCAT(u.nombre, ' ', u.apellido) AS beneficiario,
       ep.nombre AS estado,
       (SELECT COUNT(*) FROM detalle_pago_personal d WHERE d.id_pago_personal = pp.id_pago_personal) AS servicios,
       fn_pago_personal_monto(pp.id_pago_personal) AS monto
FROM pago_personal pp
JOIN usuario u              ON u.id_usuario = pp.id_usuario
JOIN estado_pago_personal ep ON ep.id_estado_pago = pp.id_estado_pago;

CREATE OR REPLACE VIEW vw_servicios_por_profesional AS
SELECT sr.id_servicio_realizado,
       sr.fecha_hora,
       CONCAT(u.nombre, ' ', u.apellido) AS profesional,
       s.nombre AS servicio,
       s.precio,
       IF(dpp.id_detalle_pago IS NULL, 0, 1) AS pagado
FROM servicio_realizado sr
JOIN usuario u  ON u.id_usuario = sr.id_usuario
JOIN servicio s ON s.id_servicio = sr.id_servicio
LEFT JOIN detalle_pago_personal dpp ON dpp.id_servicio_realizado = sr.id_servicio_realizado;

CREATE OR REPLACE VIEW vw_servicios_mas_solicitados AS
SELECT s.id_servicio,
       s.nombre AS servicio,
       cs.nombre AS categoria,
       COUNT(sr.id_servicio_realizado) AS veces_realizado,
       COALESCE(SUM(CASE WHEN sr.id_servicio_realizado IS NOT NULL THEN s.precio ELSE 0 END), 0) AS ingreso_generado
FROM servicio s
JOIN categoria_servicio cs ON cs.id_categoria_servicio = s.id_categoria_servicio
LEFT JOIN servicio_realizado sr ON sr.id_servicio = s.id_servicio
GROUP BY s.id_servicio, s.nombre, cs.nombre
ORDER BY veces_realizado DESC;

-- Cuenta corriente con proveedores: que compras quedan por pagar y cuales vencieron
CREATE OR REPLACE VIEW vw_cuenta_proveedor AS
SELECT c.id_compra,
       p.id_proveedor,
       p.nombre AS proveedor,
       c.fecha,
       c.nro_factura_proveedor,
       cv.nombre AS condicion,
       fn_compra_vencimiento(c.id_compra) AS vencimiento,
       fn_compra_total(c.id_compra) AS total,
       (fn_compra_total(c.id_compra) - fn_compra_saldo(c.id_compra)) AS pagado,
       fn_compra_saldo(c.id_compra) AS saldo,
       IF(fn_compra_saldo(c.id_compra) > 0
          AND fn_compra_vencimiento(c.id_compra) < CURRENT_DATE, 1, 0) AS vencida
FROM compra c
JOIN proveedor p        ON p.id_proveedor = c.id_proveedor
JOIN condicion_venta cv ON cv.id_condicion_venta = c.id_condicion_venta
WHERE c.id_estado_compra = 2;

-- Quien puede hacer que, con la comision que le corresponde hoy
CREATE OR REPLACE VIEW vw_habilitacion_profesional AS
SELECT CONCAT(u.nombre, ' ', u.apellido) AS profesional,
       s.nombre AS servicio,
       cs.nombre AS categoria,
       COALESCE(us.duracion_min, s.duracion_min) AS duracion_min,
       s.precio,
       (SELECT CONCAT(c.tipo, ' ', c.valor) FROM comision c
         WHERE c.id_usuario = u.id_usuario
           AND (c.id_servicio = s.id_servicio OR c.id_servicio IS NULL)
           AND c.activo = 1 AND c.vigente_desde <= CURRENT_DATE
         ORDER BY (c.id_servicio IS NULL) ASC, c.vigente_desde DESC
         LIMIT 1) AS comision_vigente
FROM usuario_servicio us
JOIN usuario u            ON u.id_usuario = us.id_usuario
JOIN servicio s           ON s.id_servicio = us.id_servicio
JOIN categoria_servicio cs ON cs.id_categoria_servicio = s.id_categoria_servicio
WHERE us.activo = 1;

-- Agenda cerrada: vacaciones, licencias, feriados y bloqueos
CREATE OR REPLACE VIEW vw_agenda_bloqueos AS
SELECT a.id_ausencia,
       COALESCE(CONCAT(u.nombre, ' ', u.apellido), 'Todo el salon') AS alcance,
       ta.nombre AS tipo,
       a.fecha_inicio,
       a.fecha_fin,
       a.motivo
FROM ausencia_agenda a
JOIN tipo_ausencia ta ON ta.id_tipo_ausencia = a.id_tipo_ausencia
LEFT JOIN usuario u   ON u.id_usuario = a.id_usuario
WHERE a.activo = 1;

CREATE OR REPLACE VIEW vw_demanda_por_hora AS
SELECT HOUR(c.fecha_hora) AS hora,
       COUNT(*) AS citas,
       SUM(CASE WHEN c.id_estado_cita = 4 THEN 1 ELSE 0 END) AS atendidas,
       SUM(CASE WHEN c.id_estado_cita = 6 THEN 1 ELSE 0 END) AS ausencias,
       SUM(CASE WHEN c.id_estado_cita = 3 THEN 1 ELSE 0 END) AS canceladas
FROM cita c
GROUP BY HOUR(c.fecha_hora)
ORDER BY hora;

-- =====================================================================
--  FIN DEL SCRIPT (variante 3FN estricta)
--  55 tablas sin una sola columna calculada | 86 claves foraneas | 50 CHECK
--  27 funciones | 17 triggers | 20 procedimientos | 17 vistas
-- =====================================================================
