-- ---------------------------------------------------------------------
-- 7.124.0 — La apariencia del salón: el color, la letra y la paleta
--
-- El salón elige UN color y el sistema deriva el resto. Todo eso vive en
-- una sola columna JSON de `configuracion`, la tabla de una fila: es UNA
-- decisión —un color— más el resultado del cálculo, no cuarenta y dos
-- datos que alguien cargue uno por uno.
--
-- Re-ejecutable y sin tocar datos: `IF NOT EXISTS` deja la columna como
-- está si ya se corrió. Correrlo dos veces da exactamente lo mismo.
-- ---------------------------------------------------------------------

ALTER TABLE `configuracion`
  ADD COLUMN IF NOT EXISTS `tema_colores` LONGTEXT DEFAULT NULL AFTER `mail_desde`;
