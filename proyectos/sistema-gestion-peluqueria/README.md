# Sistema de Gestión para Peluquería (SPG)

> Sistema web de gestión integral para una peluquería de Luque (Paraguay): agenda,
> clientes, inventario, caja y portal de autogestión para el cliente final.

| | |
| --- | --- |
| **Rol** | Desarrollo full-stack — trabajo en pareja con Noelia Belén Villalba Marín |
| **Periodo** | 2026 — **en desarrollo, no terminado** |
| **Tipo** | Trabajo de Conclusión de Carrera — Ingeniería en Informática (Asunción, Paraguay) |
| **Stack** | PHP 8 sin frameworks · MySQL/MariaDB · HTML5 · CSS · Bootstrap 5 · JavaScript · WebAuthn |
| **Volumen** | ~4.900 líneas de PHP · 55 tablas · 17 vistas · 20 procedimientos · 27 funciones · 17 triggers |

## Contexto

Una peluquería de Luque gestionaba turnos, clientes y stock en papel y planillas
sueltas. Eso hacía difícil saber qué profesional estaba libre, qué productos
faltaban o cuánto se había facturado en el día, y no dejaba historial consultable
por cliente.

El encargo del TCC pedía resolverlo con una pila concreta —PHP, MySQL, HTML5, CSS
y Bootstrap— sin frameworks de PHP, sin Node.js y sin gestores de dependencias.
Toda la arquitectura está construida a mano dentro de esa restricción.

## Qué construí

Una aplicación web con arquitectura cliente-servidor y patrón MVC implementado
desde cero:

- **Front controller único** (`public/index.php`) con rutas del tipo
  `index.php?r=modulo/accion`, saneado de la ruta y despacho por convención de
  nombres (`clientes/index` → `clientes_index()`).
- **Control de acceso por roles** con cuatro perfiles —Propietaria, Gerente,
  Asistente y Cliente— y una matriz `rol_modulo` editable desde la interfaz, de
  forma que los permisos se cambian sin tocar código. La Propietaria actúa como
  superadmin.
- **Portal del cliente separado del panel de gestión**: al iniciar sesión, un
  usuario con rol Cliente es redirigido a su portal y nunca ve los módulos
  internos. Desde ahí reserva y cancela citas, consulta su historial y sus puntos,
  ve promociones y deja valoraciones.
- **Protección CSRF** validada de forma centralizada en cada POST, y regeneración
  de identificador de sesión en cada login.
- **Auditoría**: las acciones sensibles quedan registradas con usuario, módulo,
  entidad afectada y descripción.

Módulos: citas y agenda · clientes y fidelización · servicios · inventario y
proveedores · facturación y caja · reportes · personal · configuración · portal
del cliente.

## Decisiones técnicas

### La lógica de negocio vive en la base de datos

En lugar de recalcular importes y disponibilidad en PHP, el modelo delega en el
motor MySQL a través de funciones, vistas, procedimientos y triggers. El código
PHP los consume, no los duplica.

- **27 funciones** para los cálculos derivados: `fn_producto_stock`,
  `fn_factura_total`, `fn_cliente_puntos`, `fn_cliente_nivel`,
  `fn_verificar_disponibilidad`, `fn_caja_saldo`, `fn_comision_servicio`…
- **20 procedimientos** para las operaciones transaccionales:
  `sp_agendar_cita`, `sp_emitir_factura`, `sp_registrar_cobro`, `sp_abrir_caja` /
  `sp_cerrar_caja`, `sp_emitir_nota_credito`, `sp_registrar_movimiento_inventario`…
- **17 triggers** que mantienen la consistencia de stock, puntos y saldos sin
  depender de que la aplicación se acuerde de hacerlo.
- **17 vistas** para consultas de lectura frecuente: `vw_agenda_citas`,
  `vw_producto_bajo_stock`, `vw_demanda_por_hora`, `vw_servicios_mas_solicitados`.

La ventaja es que la integridad se garantiza en un único punto: cualquier cliente
de la base —la aplicación, un script o el propio gestor— obtiene los mismos
resultados. El esquema está normalizado hasta 3FN.

### Login biométrico con WebAuthn, implementado a mano

La restricción de no usar gestores de dependencias impedía recurrir a una librería
de WebAuthn, así que el soporte de huella (Windows Hello, Touch ID, huella de
Android) está escrito en PHP puro en `app/webauthn.php`:

- decodificador **CBOR** propio, el subconjunto necesario para leer el
  `attestationObject`;
- extracción de la clave pública **COSE** (ES256 / RS256) y conversión a PEM
  construyendo las estructuras **ASN.1/DER** a mano;
- verificación de la firma de aserción con OpenSSL, validando origen y RP ID.

### Recuperación de cuenta por correo

Cliente SMTP con STARTTLS para el envío de códigos de verificación y recuperación
de contraseña. Las credenciales se leen de variables de entorno (`SPG_MAIL_*`), no
del código.

## Estructura

```
app/
  config.php       Constantes y configuración (sobrescribible por entorno)
  db.php           PDO + helpers de consulta
  auth.php         Sesión, roles y matriz de permisos por módulo
  webauthn.php     WebAuthn en PHP puro (CBOR, COSE, ASN.1/DER)
  mail.php         Envío SMTP
  helpers.php      Escape, formato de moneda y fechas, CSRF, mensajes flash
  view.php         Render de vistas y definición del menú
  controllers/     Un archivo por módulo
  views/           Plantillas por pantalla
public/
  index.php        Front controller
  install.php      Alta de usuarios iniciales (se elimina tras instalar)
  assets/          CSS propio (paleta oro y neutros cálidos) y JS de WebAuthn
db/
  schema_3fn.sql   Esquema completo: tablas, vistas, funciones, procedimientos, triggers
docs/              Instructivo de instalación y justificación de herramientas
```

## Ejecutar en local

Requiere XAMPP (Apache + MySQL) con PHP 8.0 o superior.

```bash
# 1. Colocar el proyecto en htdocs
#    C:\xampp\htdocs\Sistema_Gestion_Peluqueria\

# 2. Importar el esquema desde phpMyAdmin
#    db/schema_3fn.sql  →  crea la base peluqueria_bd

# 3. Crear los usuarios iniciales
#    http://localhost/Sistema_Gestion_Peluqueria/public/install.php

# 4. Entrar
#    http://localhost/Sistema_Gestion_Peluqueria/public/index.php
```

Tras instalar, conviene borrar `public/install.php` y cambiar las contraseñas por
defecto. La conexión usa los valores de XAMPP (`root` sin contraseña) salvo que se
definan las variables `SPG_DB_*`.

Las credenciales de correo se leen del entorno (`SPG_MAIL_USERNAME`,
`SPG_MAIL_PASSWORD`); sin ellas, el envío de códigos de verificación no funciona.

## Herramientas utilizadas

El TCC fijaba la pila en su sección «Herramientas a utilizar», y el desarrollo la
respetó: PHP sin frameworks, sin Node.js y sin gestores de dependencias. Las
adiciones están justificadas en `docs/Herramientas_extras_utilizadas.docx`.

| Herramienta | Para qué | En el TCC |
| --- | --- | --- |
| **PHP 8** | Lógica de servidor y acceso a datos | ✅ |
| **MySQL / MariaDB** | Base de datos relacional; también la lógica de negocio | ✅ |
| **HTML5** | Estructura de las páginas | ✅ |
| **CSS** | Identidad visual propia (paleta oro y neutros cálidos) | ✅ |
| **Bootstrap 5** | Framework de interfaz | ✅ |
| **XAMPP** (Apache + MySQL) | Entorno de desarrollo y despliegue | ✅ |
| **Bootstrap Icons** | Iconografía | Extra, prescindible |
| **JavaScript propio** | Interacciones y llamada a WebAuthn | Extra, parcial |
| **WebAuthn / FIDO2** | Login biométrico; implementado a mano, sin librerías | Extra, opcional |
| **SMTP (Gmail)** | Verificación de cuenta y recuperación de contraseña | Extra, parcial |
| **Claude Code** | Asistencia en desarrollo | — |

## Estado

**El sistema no está terminado.** Los módulos de gestión y el portal del cliente
funcionan, el esquema de base de datos está completo y el control de acceso por
roles opera, pero el proyecto sigue en desarrollo dentro del marco del TCC.

## Pendiente de completar

- [ ] Capturas de las pantallas principales (agenda, caja, portal del cliente)
- [ ] Resultado medible: tiempo de reserva antes y después, nº de citas gestionadas
- [ ] Repartir con claridad qué módulos hizo cada integrante de la pareja
