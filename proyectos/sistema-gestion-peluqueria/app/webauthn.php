<?php
// =====================================================================
//  WebAuthn (huella / biométrico) en PHP puro
//  - Decodificador CBOR mínimo
//  - Extracción de la clave pública COSE (ES256 / RS256) -> PEM
//  - Verificación de la firma de aserción con OpenSSL
//  Sin librerías externas. Pensado para autenticadores de plataforma
//  (Windows Hello, Touch ID, huella de Android) sobre localhost.
// =====================================================================
declare(strict_types=1);

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $txt): string
{
    $txt = strtr($txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) $txt .= str_repeat('=', 4 - $pad);
    return base64_decode($txt) ?: '';
}

// Identidad de la parte confiante, derivada del host actual
function webauthn_rp_id(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host);   // sin puerto
}
function webauthn_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ------------------------------------------------------------------
//  CBOR (subconjunto suficiente para attestationObject y la clave COSE)
// ------------------------------------------------------------------
function cbor_decode(string $data, int &$off = 0)
{
    $b = ord($data[$off++]);
    $major = $b >> 5;
    $info = $b & 0x1f;

    $readLen = function (int $info) use ($data, &$off): int {
        if ($info < 24) return $info;
        if ($info === 24) return ord($data[$off++]);
        if ($info === 25) { $v = unpack('n', substr($data, $off, 2))[1]; $off += 2; return $v; }
        if ($info === 26) { $v = unpack('N', substr($data, $off, 4))[1]; $off += 4; return $v; }
        if ($info === 27) { $hi = unpack('N', substr($data, $off, 4))[1]; $lo = unpack('N', substr($data, $off + 4, 4))[1]; $off += 8; return ($hi << 32) | $lo; }
        throw new RuntimeException('CBOR longitud no soportada');
    };

    switch ($major) {
        case 0: return $readLen($info);                 // unsigned int
        case 1: return -1 - $readLen($info);            // negative int
        case 2: $n = $readLen($info); $s = substr($data, $off, $n); $off += $n; return $s; // bytes
        case 3: $n = $readLen($info); $s = substr($data, $off, $n); $off += $n; return $s; // text
        case 4: $n = $readLen($info); $a = []; for ($i = 0; $i < $n; $i++) $a[] = cbor_decode($data, $off); return $a;
        case 5: $n = $readLen($info); $m = []; for ($i = 0; $i < $n; $i++) { $k = cbor_decode($data, $off); $v = cbor_decode($data, $off); $m[$k] = $v; } return $m;
        default: throw new RuntimeException('CBOR tipo no soportado: ' . $major);
    }
}

// ------------------------------------------------------------------
//  ASN.1 / DER — para armar la clave pública en formato PEM
// ------------------------------------------------------------------
function der_len(int $n): string
{
    if ($n < 128) return chr($n);
    $out = '';
    while ($n > 0) { $out = chr($n & 0xff) . $out; $n >>= 8; }
    return chr(0x80 | strlen($out)) . $out;
}
function der_tlv(int $tag, string $val): string { return chr($tag) . der_len(strlen($val)) . $val; }
function der_int(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;
    return der_tlv(0x02, $bytes);
}
function der_seq(string $v): string { return der_tlv(0x30, $v); }
function der_bitstr(string $v): string { return der_tlv(0x03, "\x00" . $v); }
function der_oid(string $raw): string { return der_tlv(0x06, $raw); }
function der_pem(string $spki): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// Convierte una clave COSE (mapa) en PEM. Devuelve [pem, alg(-7|-257)]
function cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;

    if ($kty === 2) { // EC2 (ES256)
        $x = $cose[-2]; $y = $cose[-3];
        $oidEc  = der_oid("\x2a\x86\x48\xce\x3d\x02\x01");         // 1.2.840.10045.2.1
        $oidP256 = der_oid("\x2a\x86\x48\xce\x3d\x03\x01\x07");    // 1.2.840.10045.3.1.7
        $algId = der_seq($oidEc . $oidP256);
        $point = "\x04" . $x . $y;
        $spki = der_seq($algId . der_bitstr($point));
        return [der_pem($spki), -7];
    }
    if ($kty === 3) { // RSA (RS256)
        $n = $cose[-1]; $e = $cose[-2];
        $oidRsa = der_oid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01");  // 1.2.840.113549.1.1.1
        $algId = der_seq($oidRsa . "\x05\x00");
        $rsaKey = der_seq(der_int($n) . der_int($e));
        $spki = der_seq($algId . der_bitstr($rsaKey));
        return [der_pem($spki), -257];
    }
    throw new RuntimeException('Tipo de clave COSE no soportado (kty=' . var_export($kty, true) . ')');
}

// ------------------------------------------------------------------
//  Parsers de authenticatorData
// ------------------------------------------------------------------
function parse_auth_data(string $authData): array
{
    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCount = unpack('N', substr($authData, 33, 4))[1];
    $out = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount, 'credId' => null, 'cose' => null];

    if ($flags & 0x40) { // AT: attested credential data presente
        $off = 37;
        $off += 16; // AAGUID
        $credLen = unpack('n', substr($authData, $off, 2))[1]; $off += 2;
        $out['credId'] = substr($authData, $off, $credLen); $off += $credLen;
        $coseOff = $off;
        $out['cose'] = cbor_decode($authData, $coseOff);
    }
    return $out;
}

// ------------------------------------------------------------------
//  Ceremonias
// ------------------------------------------------------------------
function webauthn_nuevo_challenge(): string
{
    $ch = random_bytes(32);
    $_SESSION['webauthn_challenge'] = b64url_encode($ch);
    return $_SESSION['webauthn_challenge'];
}

// Verifica el clientDataJSON (tipo, challenge y origin)
function webauthn_check_client_data(string $clientDataJSON, string $tipoEsperado): bool
{
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) return false;
    if (($cd['type'] ?? '') !== $tipoEsperado) return false;
    $chSesion = $_SESSION['webauthn_challenge'] ?? '';
    if (!hash_equals($chSesion, $cd['challenge'] ?? '')) return false;
    if (($cd['origin'] ?? '') !== webauthn_origin()) return false;
    return true;
}

// Registro: valida y devuelve [credentialId(b64url), publicKeyPem]
function webauthn_verificar_registro(string $clientDataJSON, string $attestationObjectB64): array
{
    if (!webauthn_check_client_data($clientDataJSON, 'webauthn.create')) {
        throw new RuntimeException('Datos del cliente inválidos.');
    }
    $att = cbor_decode(b64url_decode($attestationObjectB64));
    $authData = $att['authData'];
    $parsed = parse_auth_data($authData);

    if (!hash_equals(substr($parsed['rpIdHash'], 0, 32), hash('sha256', webauthn_rp_id(), true))) {
        throw new RuntimeException('rpId no coincide.');
    }
    if (!($parsed['flags'] & 0x01)) throw new RuntimeException('Usuario no presente.');
    if (!$parsed['cose']) throw new RuntimeException('No se recibió la clave pública.');

    [$pem] = cose_to_pem($parsed['cose']);
    return [b64url_encode($parsed['credId']), $pem];
}

// Aserción (login): verifica la firma con la clave guardada
function webauthn_verificar_asercion(string $clientDataJSON, string $authenticatorDataB64, string $signatureB64, string $publicKeyPem): bool
{
    if (!webauthn_check_client_data($clientDataJSON, 'webauthn.get')) return false;

    $authData = b64url_decode($authenticatorDataB64);
    if (strlen($authData) < 37) return false;
    $flags = ord($authData[32]);
    if (!($flags & 0x01)) return false; // user present
    $rpIdHash = substr($authData, 0, 32);
    if (!hash_equals($rpIdHash, hash('sha256', webauthn_rp_id(), true))) return false;

    $signedData = $authData . hash('sha256', $clientDataJSON, true);
    $sig = b64url_decode($signatureB64);
    $ok = openssl_verify($signedData, $sig, $publicKeyPem, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}
