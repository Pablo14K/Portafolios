<?php
// =====================================================================
//  Conexión PDO a MySQL / MariaDB
// =====================================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<h2 style="font-family:sans-serif">No se pudo conectar a la base de datos</h2>';
        echo '<p style="font-family:sans-serif">Verificá que MySQL de XAMPP esté encendido y que la base '
            . '<code>' . DB_NAME . '</code> esté importada.</p>';
        echo '<pre style="font-family:monospace;color:#993535">' . htmlspecialchars($e->getMessage()) . '</pre>';
        exit;
    }
    return $pdo;
}

// Helpers cortos de consulta ------------------------------------------------

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function fetch_val(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}
