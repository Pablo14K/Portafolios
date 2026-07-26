# Formato del archivo `.txt` de entrada

El automatizador recibe archivos de texto plano que **tu sistema** (cualquier
software con su propia base de datos) genera y le entrega. Cada archivo puede
contener **una o varias facturas**.

> El sistema externo solo se ocupa de **extraer sus datos y armar el `.txt`** en
> este formato. El automatizador hace el resto (XML, firma, QR, envío y PDF).

## Reglas generales

- Codificación: **UTF-8**.
- Una instrucción por línea. Los campos se separan con el carácter **`|`** (pipe).
- Las líneas que empiezan con **`#`** son comentarios (se ignoran).
- Las líneas en blanco se ignoran.
- Para separar una factura de la siguiente se usa una línea con sólo **`===`**.

## Tipos de línea

Solo se escriben **tres** tipos de línea. El **pago es automático** (no se escribe).

| Línea | Formato | Repetible |
|-------|---------|-----------|
| `FAC` | `FAC\|establecimiento\|punto\|numero\|fecha\|condicion\|moneda` | 1 por factura |
| `CLI` | `CLI\|tipo\|documento\|nombre\|email\|direccion\|telefono` | 1 por factura |
| `ITM` | `ITM\|codigo\|descripcion\|cantidad\|precio_unitario\|iva` | varias |

### Detalle de campos

**FAC**
- `establecimiento`, `punto`: 3 dígitos (se rellenan con ceros: `1` → `001`).
- `numero`: número de factura (7 dígitos; se rellena con ceros).
- `fecha`: `AAAA-MM-DD` o `AAAA-MM-DD HH:MM:SS`.
- `condicion`: `1` = contado, `2` = crédito.
- `moneda`: `PYG`, `USD`, etc. (por defecto `PYG`).

**CLI**
- `tipo`: `RUC` o `CI`.
  - Si es `RUC`, `documento` va como `RUC-DV` (ej. `80012345-6`).
  - Si es `CI`, `documento` es el nº de cédula, o `CF` para consumidor final.
- `nombre`: razón social o nombre del cliente (obligatorio).
- `email`: si se completa, se le envía el comprobante (PDF + XML). Si va vacío,
  esa factura **no envía correo** (igual se generan XML y PDF).
- `direccion`, `telefono`: opcionales.

**ITM**
- `codigo`: código interno del producto/servicio.
- `descripcion`: descripción del ítem.
- `cantidad`, `precio_unitario`: números (el precio es **IVA incluido**).
- `iva`: `10`, `5` o `0` (0 = operación exenta).

## El pago es automático (NO se escribe en el `.txt`)

No hace falta ninguna línea de pago. El sistema asigna **efectivo por el total**
(calculado desde los ítems). Así el archivo es más simple y se evitan errores.

> **Avanzado (opcional):** si en algún caso necesitás especificar el medio de pago
> o dividirlo en varios, podés agregar líneas `PAG|tipo` (sin monto). Tipos:
> `1`=efectivo, `2`=cheque, `3`=tarjeta crédito, `4`=tarjeta débito,
> `5`=transferencia, `6`=giro, `7`=billetera. Si hay varias, el total se reparte
> entre ellas. **No es necesario para el uso normal.**

## Lo que calcula el sistema (no se pone en el `.txt`)

- **El pago**: efectivo por el total, automáticamente (ver arriba).
- **El monto / total**: sumando los ítems (cantidad × precio, IVA incluido).
  Evita errores humanos de digitación.
- **Los totales fiscales** (gravadas, exentas, IVA 5/10, total general).
- **La cantidad de páginas del KuDE (PDF)**: si los ítems no entran en una hoja,
  el sistema continúa en hojas siguientes numeradas “X/Y” automáticamente.

## Ejemplo

```text
# Factura 1: cliente con RUC (el pago se asigna solo: efectivo por el total)
FAC|001|001|0000123|2026-06-21|1|PYG
CLI|RUC|80012345-6|Comercial Cliente S.A.|cliente@correo.com|Avda. 742|021555444
ITM|P001|Teclado mecanico|2|150000|10
ITM|P002|Mouse inalambrico|1|90000|10
===
# Factura 2: consumidor final (sin correo, pago automatico)
FAC|001|001|0000124|2026-06-21|1|PYG
CLI|CI|CF|Consumidor Final|||
ITM|L001|Libro|1|80000|10
```

## Qué pasa después

1. El automatizador toma el `.txt`, arma el XML SIFEN, lo firma, calcula el QR y
   lo envía a la DNIT (o lo aprueba localmente en modo `mock`).
2. Por cada factura aprobada deja en `salida/`:
   - `<CDC>.xml` — XML firmado.
   - `<CDC>.pdf` — KuDE (representación gráfica con QR).
   - un `<archivo>_<fecha>.json` con el resumen (CDC, estado, rutas).
3. El `.txt` original se mueve a `procesados/` (si todo salió bien) o a
   `errores/` (con un `.error.txt` explicando la falla).
