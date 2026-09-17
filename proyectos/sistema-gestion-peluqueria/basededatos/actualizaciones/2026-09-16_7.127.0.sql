-- =====================================================================
-- SGP 7.127.0 — La compra refleja la factura del proveedor: descuento,
--               IVA por renglón, la cuota que vence y su nota de crédito
-- =====================================================================
--
-- Una compra ES la factura del proveedor, y el modelo la tenía a medias:
-- cantidad y precio por renglón, nada más. Lo que el papel dice y acá
-- no cabía:
--
--   · el DESCUENTO del comprobante (`compra.descuento`, un monto): sin
--     él el total del sistema no coincidía con el de la factura, y el
--     saldo con el proveedor quedaba de más;
--   · el IVA de cada renglón (`detalle_compra.tasa_iva`): toda factura
--     paraguaya lo desglosa en exentas / 5 % / 10 %, y se guarda en el
--     renglón —no se lee del producto— por el mismo criterio que
--     `detalle_factura.tasa_iva`: es lo que se facturó ese día, y el
--     producto puede cambiar de tasa después;
--   · la NOTA DE CRÉDITO del proveedor (`compra_nota_credito`): el
--     documento con el que él descuenta lo que cobró de más o lo que se
--     le devolvió. Baja el saldo de la compra como un pago, pero no es un
--     pago: no salió un guaraní de ninguna caja.
--
-- Y una corrección: `fn_compra_vencimiento` devolvía la PRIMERA cuota
-- aunque ya estuviera pagada, así que una compra en tres cuotas con la
-- primera al día seguía «vencida» para siempre. Ahora devuelve la primera
-- que todavía no está cubierta, y dos funciones nuevas dicen cuál es y
-- cuánto le falta —que es lo que el pago propone—.
--
-- Lo que se deduce y por eso es función y no columna:
--
--   · fn_compra_bruto(compra)            la suma de los renglones
--   · fn_compra_total(compra)            bruto − descuento
--   · fn_compra_pagado(compra)           lo aplicado por pagos vigentes
--   · fn_compra_acreditado(compra)       lo que descuentan las notas vigentes
--   · fn_compra_saldo(compra)            total − pagado − acreditado
--   · fn_compra_cuota_pendiente(compra)  el nro de la primera cuota no cubierta
--   · fn_compra_cuota_falta(compra)      cuánto le falta a esa cuota
--   · fn_compra_vencimiento(compra)      la fecha de esa cuota, o la de la
--                                        condición si no hay cuotas
--
-- Re-ejecutable y sin tocar datos: cada paso mira `information_schema`
-- antes de actuar. El IVA de los renglones que ya estaban se toma del
-- producto UNA sola vez, al crear la columna.
--
--     docker exec sgp_app sh -c 'mysql --skip-ssl -hbd -uroot -p"$DB_PASSWORD" \
--       --default-character-set=utf8mb4 peluqueria_bd \
--       < basededatos/actualizaciones/2026-09-16_7.127.0.sql'
--
-- Al terminar: `docker exec sgp_app php artisan sgp:diagnostico --produccion`
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. El descuento de la factura del proveedor
-- ---------------------------------------------------------------------
SET @hay := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'compra'
                AND column_name = 'descuento');
SET @sql := IF(@hay = 0,
  'ALTER TABLE compra ADD COLUMN descuento DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER nro_factura_proveedor',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @hay := (SELECT COUNT(*) FROM information_schema.check_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'compra'
                AND constraint_name = 'chk_compra_descuento');
SET @sql := IF(@hay = 0,
  'ALTER TABLE compra ADD CONSTRAINT chk_compra_descuento CHECK (descuento >= 0)',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 2. El IVA de cada renglón, tomado del producto una sola vez
-- ---------------------------------------------------------------------
SET @hay := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'detalle_compra'
                AND column_name = 'tasa_iva');
SET @sql := IF(@hay = 0,
  'ALTER TABLE detalle_compra ADD COLUMN tasa_iva TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER precio_unitario',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- Sólo la primera vez: después, lo que dice el renglón es lo que se cargó.
SET @sql := IF(@hay = 0,
  'UPDATE detalle_compra dc JOIN producto p ON p.id_producto = dc.id_producto SET dc.tasa_iva = p.tasa_iva',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @hay := (SELECT COUNT(*) FROM information_schema.check_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'detalle_compra'
                AND constraint_name = 'chk_dc_iva');
SET @sql := IF(@hay = 0,
  'ALTER TABLE detalle_compra ADD CONSTRAINT chk_dc_iva CHECK (tasa_iva IN (0,5,10))',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 3. La nota de crédito del proveedor
-- ---------------------------------------------------------------------
-- Se ANULA, no se borra: `activo` + `anulado_motivo`, como
-- `movimiento_caja`. Una nota anulada deja de descontar y sigue en la
-- ficha de la compra, que es la historia de esa deuda.
CREATE TABLE IF NOT EXISTS `compra_nota_credito` (
  `id_nota` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_compra` int(10) unsigned NOT NULL,
  `id_usuario` int(10) unsigned NOT NULL,
  `nro_documento` varchar(30) NOT NULL,
  `fecha` date NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `motivo` varchar(300) NOT NULL,
  `archivo` varchar(120) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `anulado_motivo` varchar(200) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_nota`),
  KEY `ix_cnc_compra` (`id_compra`),
  KEY `ix_cnc_usuario` (`id_usuario`),
  CONSTRAINT `fk_cnc_compra` FOREIGN KEY (`id_compra`) REFERENCES `compra` (`id_compra`) ON UPDATE CASCADE,
  CONSTRAINT `fk_cnc_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON UPDATE CASCADE,
  CONSTRAINT `chk_cnc_monto` CHECK (`monto` > 0),
  CONSTRAINT `chk_cnc_nro` CHECK (CHAR_LENGTH(TRIM(`nro_documento`)) > 0),
  CONSTRAINT `chk_cnc_anulacion` CHECK (
    (`activo` = 1 AND `anulado_motivo` IS NULL) OR (`activo` = 0 AND `anulado_motivo` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Las funciones
-- ---------------------------------------------------------------------
DELIMITER $$

DROP FUNCTION IF EXISTS fn_compra_bruto $$
CREATE FUNCTION fn_compra_bruto(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  -- La suma de los renglones, antes del descuento del comprobante.
  DECLARE v_bruto DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(ROUND(cantidad * precio_unitario, 2)), 0) INTO v_bruto
    FROM detalle_compra WHERE id_compra = p_id_compra;
  RETURN v_bruto;
END $$

DROP FUNCTION IF EXISTS fn_compra_total $$
CREATE FUNCTION fn_compra_total(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  -- Lo que dice el comprobante: los renglones menos su descuento. Nunca
  -- por debajo de cero — la pantalla no deja cargar un descuento mayor
  -- que el bruto, pero la función no tiene por qué confiar en eso.
  DECLARE v_desc DECIMAL(12,2) DEFAULT 0;
  SELECT COALESCE(descuento, 0) INTO v_desc FROM compra WHERE id_compra = p_id_compra;
  RETURN GREATEST(fn_compra_bruto(p_id_compra) - v_desc, 0);
END $$

DROP FUNCTION IF EXISTS fn_compra_pagado $$
CREATE FUNCTION fn_compra_pagado(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  -- Lo aplicado a esta compra por pagos vigentes. `monto_aplicado` y no el
  -- monto del pago: un pago puede cubrir varias compras.
  DECLARE v_pagado DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(d.monto_aplicado), 0) INTO v_pagado
    FROM detalle_pago_proveedor d
    JOIN pago_proveedor pp ON pp.id_pago_proveedor = d.id_pago_proveedor
   WHERE d.id_compra = p_id_compra AND pp.id_estado_pago_proveedor = 1;
  RETURN v_pagado;
END $$

DROP FUNCTION IF EXISTS fn_compra_acreditado $$
CREATE FUNCTION fn_compra_acreditado(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  -- Lo que descuentan las notas de crédito vigentes del proveedor. Baja el
  -- saldo como un pago, pero NO es un pago: no salió de ninguna caja, y
  -- por eso no entra en `fn_compra_pagado` ni en ningún arqueo.
  DECLARE v_acred DECIMAL(14,2) DEFAULT 0;
  SELECT COALESCE(SUM(monto), 0) INTO v_acred
    FROM compra_nota_credito WHERE id_compra = p_id_compra AND activo = 1;
  RETURN v_acred;
END $$

DROP FUNCTION IF EXISTS fn_compra_saldo $$
CREATE FUNCTION fn_compra_saldo(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  RETURN fn_compra_total(p_id_compra)
       - fn_compra_pagado(p_id_compra)
       - fn_compra_acreditado(p_id_compra);
END $$

DROP FUNCTION IF EXISTS fn_compra_cuota_pendiente $$
CREATE FUNCTION fn_compra_cuota_pendiente(p_id_compra INT UNSIGNED)
RETURNS SMALLINT UNSIGNED
READS SQL DATA
BEGIN
  -- La primera cuota que lo pagado (y lo acreditado) todavía no cubre.
  --
  -- **Qué cuota cubre cada pago NO se guarda**: se deduce por orden, como
  -- se paga una deuda — lo que entra va a la cuota más vieja. Guardarlo
  -- sería una columna derivada, y además obligaría a repartir a mano un
  -- pago que cubre una cuota y media. NULL sin cuotas, o con todas
  -- cubiertas.
  DECLARE v_cubierto DECIMAL(14,2) DEFAULT 0;
  DECLARE v_nro SMALLINT UNSIGNED DEFAULT NULL;

  SET v_cubierto = fn_compra_pagado(p_id_compra) + fn_compra_acreditado(p_id_compra);

  SELECT x.nro_cuota INTO v_nro
    FROM (SELECT nro_cuota, SUM(monto) OVER (ORDER BY nro_cuota) AS acum
            FROM compra_cuota WHERE id_compra = p_id_compra) x
   WHERE x.acum > v_cubierto
   ORDER BY x.nro_cuota LIMIT 1;

  RETURN v_nro;
END $$

DROP FUNCTION IF EXISTS fn_compra_cuota_falta $$
CREATE FUNCTION fn_compra_cuota_falta(p_id_compra INT UNSIGNED)
RETURNS DECIMAL(14,2)
READS SQL DATA
BEGIN
  -- Cuánto le falta a la cuota pendiente: lo acumulado hasta ella menos
  -- lo cubierto. Es lo que el pago propone en vez del saldo entero. NULL
  -- si no hay cuota pendiente.
  DECLARE v_cubierto DECIMAL(14,2) DEFAULT 0;
  DECLARE v_acum DECIMAL(14,2) DEFAULT NULL;
  DECLARE v_nro SMALLINT UNSIGNED DEFAULT NULL;

  SET v_nro = fn_compra_cuota_pendiente(p_id_compra);
  IF v_nro IS NULL THEN
    RETURN NULL;
  END IF;

  SET v_cubierto = fn_compra_pagado(p_id_compra) + fn_compra_acreditado(p_id_compra);
  SELECT COALESCE(SUM(monto), 0) INTO v_acum
    FROM compra_cuota WHERE id_compra = p_id_compra AND nro_cuota <= v_nro;

  RETURN GREATEST(v_acum - v_cubierto, 0);
END $$

DROP FUNCTION IF EXISTS fn_compra_vencimiento $$
CREATE FUNCTION fn_compra_vencimiento(p_id_compra INT UNSIGNED)
RETURNS DATE
READS SQL DATA
BEGIN
  -- Cuándo vence lo que se debe: la cuota pendiente si hay cuotas —**no la
  -- primera**, que con la primera al día dejaba la compra «vencida» para
  -- siempre—, o la fecha más la condición de compra si no las hay.
  DECLARE v_venc DATE DEFAULT NULL;
  DECLARE v_nro SMALLINT UNSIGNED DEFAULT NULL;

  SET v_nro = fn_compra_cuota_pendiente(p_id_compra);
  IF v_nro IS NOT NULL THEN
    SELECT fecha_vencimiento INTO v_venc
      FROM compra_cuota WHERE id_compra = p_id_compra AND nro_cuota = v_nro;
    RETURN v_venc;
  END IF;

  -- Con cuotas y todas cubiertas, lo que quede debiendo —un redondeo— vence
  -- con la última.
  SELECT MAX(fecha_vencimiento) INTO v_venc
    FROM compra_cuota WHERE id_compra = p_id_compra;
  IF v_venc IS NOT NULL THEN
    RETURN v_venc;
  END IF;

  SELECT DATE(c.fecha) + INTERVAL cv.dias_credito DAY INTO v_venc
    FROM compra c
    JOIN condicion_venta cv ON cv.id_condicion_venta = c.id_condicion_venta
   WHERE c.id_compra = p_id_compra;

  RETURN v_venc;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 5. Las vistas: la cuenta con el proveedor dice descuento, nota y cuota
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_cuenta_proveedor AS
SELECT c.id_compra,
       p.id_proveedor,
       pp.nombre AS proveedor,
       c.fecha,
       c.nro_factura_proveedor,
       cv.nombre AS condicion,
       cv.dias_credito,
       fn_compra_vencimiento(c.id_compra) AS vencimiento,
       fn_compra_bruto(c.id_compra) AS bruto,
       c.descuento,
       fn_compra_total(c.id_compra) AS total,
       fn_compra_pagado(c.id_compra) AS pagado,
       fn_compra_acreditado(c.id_compra) AS acreditado,
       fn_compra_saldo(c.id_compra) AS saldo,
       fn_compra_cuotas(c.id_compra) AS cuotas,
       fn_compra_cuota_pendiente(c.id_compra) AS cuota_pendiente,
       fn_compra_cuota_falta(c.id_compra) AS cuota_falta,
       IF(fn_compra_saldo(c.id_compra) > 0 AND fn_compra_vencimiento(c.id_compra) < CURDATE(), 1, 0) AS vencida
  FROM compra c
  JOIN proveedor p ON p.id_proveedor = c.id_proveedor
  JOIN persona pp ON pp.id_persona = p.id_persona
  JOIN condicion_venta cv ON cv.id_condicion_venta = c.id_condicion_venta
 WHERE c.id_estado_compra = 2;

CREATE OR REPLACE VIEW vw_compra_resumen AS
SELECT c.id_compra,
       c.fecha,
       pp.nombre AS proveedor,
       TRIM(CONCAT_WS(' ', pu.nombre, pu.apellido)) AS registro,
       ec.nombre AS estado,
       fn_compra_bruto(c.id_compra) AS bruto,
       c.descuento,
       fn_compra_total(c.id_compra) AS total,
       fn_compra_acreditado(c.id_compra) AS acreditado,
       c.observaciones
  FROM compra c
  JOIN proveedor pr ON pr.id_proveedor = c.id_proveedor
  JOIN persona pp ON pp.id_persona = pr.id_persona
  JOIN usuario u ON u.id_usuario = c.id_usuario
  JOIN persona pu ON pu.id_persona = u.id_persona
  JOIN estado_compra ec ON ec.id_estado_compra = c.id_estado_compra;

-- El IVA incluido, desglosado como en la factura de venta: el descuento
-- del comprobante se reparte proporcional entre los renglones, así el
-- gravado de cada tasa suma el total. Es la misma cuenta que
-- `vw_factura_impuestos`, del otro lado del mostrador.
CREATE OR REPLACE VIEW vw_compra_impuestos AS
SELECT c.id_compra,
       ROUND(SUM(CASE WHEN dc.tasa_iva = 10 THEN ROUND(dc.cantidad * dc.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) > 0,
                  (SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) - c.descuento)
                  / SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)), 1), 2) AS gravado_10,
       ROUND(SUM(CASE WHEN dc.tasa_iva = 10 THEN ROUND(dc.cantidad * dc.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) > 0,
                  (SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) - c.descuento)
                  / SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)), 1) / 11, 2) AS iva_10,
       ROUND(SUM(CASE WHEN dc.tasa_iva = 5 THEN ROUND(dc.cantidad * dc.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) > 0,
                  (SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) - c.descuento)
                  / SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)), 1), 2) AS gravado_5,
       ROUND(SUM(CASE WHEN dc.tasa_iva = 5 THEN ROUND(dc.cantidad * dc.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) > 0,
                  (SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) - c.descuento)
                  / SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)), 1) / 21, 2) AS iva_5,
       ROUND(SUM(CASE WHEN dc.tasa_iva = 0 THEN ROUND(dc.cantidad * dc.precio_unitario, 2) ELSE 0 END)
             * IF(SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) > 0,
                  (SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)) - c.descuento)
                  / SUM(ROUND(dc.cantidad * dc.precio_unitario, 2)), 1), 2) AS exentas,
       fn_compra_total(c.id_compra) AS total
  FROM compra c
  JOIN detalle_compra dc ON dc.id_compra = c.id_compra
 GROUP BY c.id_compra, c.descuento;
