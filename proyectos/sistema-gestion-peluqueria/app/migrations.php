<?php
// =====================================================================
//  Migraciones de funciones agregadas sobre la base del TCC.
//  Son tablas de apoyo (no forman parte del modelo 3FN entregado):
//  códigos de verificación/recuperación y credenciales biométricas.
//  Idempotente: se puede correr las veces que haga falta.
// =====================================================================
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function migrar(): void
{
    $pdo = db();

    // Códigos de verificación de cuenta y recuperación de contraseña
    $pdo->exec("CREATE TABLE IF NOT EXISTS token_seguridad (
        id_token       INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_usuario     INT UNSIGNED NOT NULL,
        tipo           VARCHAR(20)  NOT NULL,
        codigo         VARCHAR(10)  NOT NULL,
        expira_en      DATETIME     NOT NULL,
        usado          TINYINT(1)   NOT NULL DEFAULT 0,
        fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_token),
        KEY idx_tok_usuario (id_usuario, tipo),
        CONSTRAINT fk_tok_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
          ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Credenciales biométricas (WebAuthn) por usuario
    $pdo->exec("CREATE TABLE IF NOT EXISTS credencial_webauthn (
        id_credencial  INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_usuario     INT UNSIGNED NOT NULL,
        credential_id  VARCHAR(255) NOT NULL,
        public_key     TEXT         NOT NULL,
        contador       INT UNSIGNED NOT NULL DEFAULT 0,
        etiqueta       VARCHAR(80)  NULL,
        fecha_creacion DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_credencial),
        UNIQUE KEY uq_webauthn_credid (credential_id),
        KEY idx_webauthn_usuario (id_usuario),
        CONSTRAINT fk_webauthn_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
          ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Preferencia: ya se le preguntó al usuario por el login biométrico
    $pdo->exec("CREATE TABLE IF NOT EXISTS preferencia_usuario (
        id_usuario        INT UNSIGNED NOT NULL,
        biometrico_activo TINYINT(1)  NOT NULL DEFAULT 0,
        biometrico_pregunt TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id_usuario),
        CONSTRAINT fk_prefusr_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
          ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Módulos que ve cada rol (Tanda B: roles configurables)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rol_modulo (
        id_rol   INT UNSIGNED NOT NULL,
        modulo   VARCHAR(40)  NOT NULL,
        PRIMARY KEY (id_rol, modulo),
        CONSTRAINT fk_rolmod_rol FOREIGN KEY (id_rol) REFERENCES rol (id_rol)
          ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Siembra de permisos por defecto (solo si la tabla está vacía)
    $hay = (int)$pdo->query("SELECT COUNT(*) FROM rol_modulo")->fetchColumn();
    if ($hay === 0) {
        $def = [
            2 => ['citas', 'clientes', 'servicios', 'inventario', 'facturacion', 'reportes', 'personal'], // Gerente
            3 => ['citas', 'clientes', 'inventario', 'facturacion'],                                        // Asistente
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO rol_modulo (id_rol, modulo) VALUES (?,?)");
        foreach ($def as $rol => $mods) {
            foreach ($mods as $m) $ins->execute([$rol, $m]);
        }
        // La Propietaria (rol 1) es superadministradora: no necesita filas (siempre ve todo).
    }
}
