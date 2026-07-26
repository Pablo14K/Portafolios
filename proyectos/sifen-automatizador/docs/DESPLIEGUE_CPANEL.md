# Despliegue en cPanel — el sistema "vive" en el servidor

El automatizador queda publicado como un **endpoint HTTP** que recibe el `.txt`
de cualquier forma y siempre responde el mismo JSON. Tu sistema (vieloy.com u
otro) le manda las facturas por HTTP y recibe el CDC + links de descarga.

## 1. Requisitos

En cPanel → **Select PHP Version**: PHP **8.1+** con `openssl`, `dom`, `mbstring`.

## 2. Subir el proyecto

Subí la carpeta `sifen_automatizador/` (por ejemplo a `/home/usuario/sifen_automatizador`),
**fuera** de `public_html`. Solo la subcarpeta `public/` debe quedar expuesta a la web.

## 3. Apuntar un dominio/subdominio a `public/`

En cPanel → **Domains / Subdomains**, creá por ejemplo
`facturacion.tudominio.com` y poné como **Document Root**:

```
/home/usuario/sifen_automatizador/public
```

Así solo `index.php` y `descargar.php` son accesibles; el resto del código
(motor, .env, facturas) queda protegido fuera de la web.

## 4. Configurar el `.env`

```bash
cp .env.example .env
```

Editá `.env` y completá:
- Datos del emisor (`EMISOR_*`)
- Correo (`MAIL_*`)
- `APP_URL=https://facturacion.tudominio.com`
- `SIFEN_API_TOKEN=` con un valor largo y aleatorio (seguridad de la API)
- `SIFEN_MODE` (mock / test / prod) y el certificado si es test/prod

## 5. Permisos

Permiso **755** en: `entrada/ procesados/ errores/ salida/ logs/ certs/`

## 6. Listo — cómo enviar facturas

El endpoint acepta el `.txt` de **3 maneras** y siempre responde el mismo JSON.

### A) Subiendo el archivo (multipart)
```bash
curl -X POST https://facturacion.tudominio.com/ \
  -H "X-API-Token: TU_TOKEN" \
  -F "archivo=@factura.txt"
```

### B) Como campo de formulario
```bash
curl -X POST https://facturacion.tudominio.com/ \
  -H "X-API-Token: TU_TOKEN" \
  --data-urlencode "contenido=FAC|001|001|0000123|2026-06-21|1|PYG
CLI|RUC|80012345-6|Cliente S.A.|cliente@mail.com||
ITM|P1|Producto|1|100000|10"
```

### C) Como cuerpo crudo (text/plain)
```bash
curl -X POST https://facturacion.tudominio.com/ \
  -H "X-API-Token: TU_TOKEN" \
  -H "Content-Type: text/plain" \
  --data-binary @factura.txt
```

### Respuesta (siempre igual)
```json
{
  "ok": true,
  "facturas": [
    {
      "factura": 1,
      "cdc": "0180012345600100100001232...",
      "estado": "APROBADO",
      "track_id": "...",
      "xml_url": "https://facturacion.tudominio.com/descargar.php?f=<CDC>.xml",
      "kude_url": "https://facturacion.tudominio.com/descargar.php?f=<CDC>.pdf",
      "mail_enviado": true
    }
  ]
}
```

Si algo falla:
```json
{ "ok": false, "error": "Factura #1: no tiene ítems (ITM)." }
```

## 7. Integración desde PHP (vieloy.com)

```php
$ch = curl_init('https://facturacion.tudominio.com/');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['X-API-Token: TU_TOKEN', 'Content-Type: text/plain'],
    CURLOPT_POSTFIELDS =>
        "FAC|001|001|{$numero}|{$fecha}|1|PYG\n" .
        "CLI|RUC|{$ruc}-{$dv}|{$nombre}|{$email}||\n" .
        "ITM|{$codigo}|{$desc}|{$cant}|{$precio}|10",
    CURLOPT_RETURNTRANSFER => true,
]);
$respuesta = json_decode(curl_exec($ch), true);
// $respuesta['facturas'][0]['cdc'], ['kude_url'], etc.
```

---

## Alternativa: Cron + carpeta (sin HTTP)

Si preferís que vieloy.com simplemente deje archivos `.txt` en `entrada/`
(por FTP/disco compartido) en lugar de llamar al HTTP, podés usar el cron:

```
* * * * * /usr/bin/php /home/usuario/sifen_automatizador/bin/procesar.php >> /home/usuario/sifen_automatizador/logs/cron.log 2>&1
```

Ambos modos conviven: el HTTP responde al instante; el cron procesa lo que se
deje en la carpeta. Usá el que mejor se acople a tu flujo.
