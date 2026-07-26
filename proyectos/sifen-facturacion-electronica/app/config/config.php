<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Configuracion central PHP. Lee variables de entorno para base de datos, correo, storage y URL del microservicio SIFEN Node.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


// Raíz absoluta del proyecto. Si bootstrap.php no la definió (caso muy raro), la
// derivamos: config.php está en app/config/, así que la raíz es dos niveles arriba.
$appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

// Convierte una ruta (de .env o default) a ABSOLUTA, anclando las relativas a la
// raíz del proyecto. Así "./storage/xml" funciona igual sin importar el CWD, que
// en cPanel/Apache no es la raíz del proyecto (a diferencia de `php -S`).
$absDir = static function (?string $val, string $rel) use ($appRoot): string {
    $val  = ($val !== null && $val !== '') ? $val : $rel;
    $norm = str_replace('\\', '/', $val);
    if (preg_match('#^(/|[A-Za-z]:/)#', $norm)) {
        return $val; // ya es absoluta (Unix /... o Windows C:/...)
    }
    return $appRoot . '/' . ltrim(preg_replace('#^\./#', '', $norm), '/');
};

return [
    'db' => [
        'dsn'      => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST','127.0.0.1'), env('DB_PORT','3306'), env('DB_NAME','facturacion_sifen')),
        'user'     => env('DB_USER','root'),
        'password' => env('DB_PASS',''),
    ],
    'mail' => [
        'transport'  => env('MAIL_TRANSPORT','smtp'),
        'host'       => env('MAIL_HOST','smtp.gmail.com'),
        'port'       => (int)env('MAIL_PORT','587'),
        'username'   => env('MAIL_USERNAME',''),
        'password'   => env('MAIL_PASSWORD',''),
        'from_email' => env('MAIL_FROM_EMAIL',''),
        'from_name'  => env('MAIL_FROM_NAME','Facturación Electrónica'),
        'encryption' => env('MAIL_ENCRYPTION','tls'),
        'email_dir'  => $absDir(env('STORAGE_EMAIL_DIR'), 'storage/emails'),
    ],
    'storage' => [
        'xml_dir'   => $absDir(env('STORAGE_XML_DIR'),   'storage/xml'),
        'kude_dir'  => $absDir(env('STORAGE_KUDE_DIR'),  'storage/kude'),
        'email_dir' => $absDir(env('STORAGE_EMAIL_DIR'), 'storage/emails'),
        'log_dir'   => $absDir(env('STORAGE_LOG_DIR'),   'storage/logs'),
        'cert_dir'  => $absDir(env('STORAGE_CERT_DIR'),  'storage/certs'),
    ],
    'sifen' => [
        // mock = genera/firma/aprueba localmente sin SIFEN real (sin valor fiscal).
        // test/prod = envía el XML firmado al Web Service oficial de la DNIT.
        'mode'         => env('SIFEN_MODE','mock'),
        'test_label'   => '',
        'namespace'    => 'http://ekuatia.set.gov.py/sifen/xsd',

        // Carpetas donde el motor PHP guarda el XML firmado y el certificado demo.
        'xml_dir'      => $absDir(env('STORAGE_XML_DIR'),  'storage/xml'),
        'cert_dir'     => $absDir(env('STORAGE_CERT_DIR'), 'storage/certs'),

        // Web Services oficiales SIFEN (solo se usan en modo test/prod).
        'url_recep_de' => env('SIFEN_URL_RECEP_DE',''),
        'url_cons_de'  => env('SIFEN_URL_CONS_DE',''),
        'url_cons_ruc' => env('SIFEN_URL_CONS_RUC',''),

        // Certificado del contribuyente (modo test/prod). Si está vacío, se usa un
        // certificado DEMO autogenerado (solo válido en modo mock, sin valor fiscal).
        'cert_p12_path'        => env('SIFEN_CERT_P12_PATH',''),
        'cert_p12_password'    => env('SIFEN_CERT_P12_PASSWORD',''),
        'cert_pem_path'        => env('SIFEN_CERT_PEM_PATH',''),
        'private_key_pem_path' => env('SIFEN_PRIVATE_KEY_PEM_PATH',''),
        'private_key_password' => env('SIFEN_PRIVATE_KEY_PASSWORD',''),
        'ca_bundle_path'       => env('SIFEN_CA_BUNDLE_PATH',''),

        // Bases de URL del QR (Manual SIFEN v150 cap. 13.5).
        'qr_test_base' => 'https://ekuatia.set.gov.py/consultas-test/qr?',
        'qr_prod_base' => 'https://ekuatia.set.gov.py/consultas/qr?',

        // CSC de respaldo (si emitter_settings no lo tiene). Otorgado por la DNIT.
        'id_csc' => env('SIFEN_ID_CSC','0001'),
        'csc'    => env('SIFEN_CSC',''),
    ],
];
