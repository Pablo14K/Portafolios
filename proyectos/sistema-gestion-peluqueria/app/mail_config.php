<?php
// =====================================================================
//  Configuración de correo saliente (SMTP)
//  Los valores se toman de variables de entorno SPG_MAIL_*. No se
//  hardcodean credenciales: en local, exportarlas antes de arrancar
//  Apache, o definirlas en la configuración del servidor.
//
//  Ejemplo (Linux/macOS):
//    export SPG_MAIL_USERNAME="tucuenta@gmail.com"
//    export SPG_MAIL_PASSWORD="tu-contraseña-de-aplicacion"
//
//  Para Gmail hace falta una "contraseña de aplicación" (no la del
//  correo), generada desde la configuración de seguridad de Google.
// =====================================================================
declare(strict_types=1);

define('MAIL_HOST', getenv('SPG_MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', (int)(getenv('SPG_MAIL_PORT') ?: 587));
define('MAIL_ENCRYPTION', getenv('SPG_MAIL_ENCRYPTION') ?: 'tls'); // tls (STARTTLS)
define('MAIL_USERNAME', getenv('SPG_MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('SPG_MAIL_PASSWORD') ?: '');
define('MAIL_FROM_EMAIL', getenv('SPG_MAIL_FROM_EMAIL') ?: '');
define('MAIL_FROM_NAME', getenv('SPG_MAIL_FROM_NAME') ?: 'Peluquería Luque');
