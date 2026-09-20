<?php
// No real credentials here on purpose — this file is committed. Real values
// come from environment variables: Azure App Service Application Settings in
// production, or a local shell export (see local.env.sh, gitignored) in dev.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'pandapesa';
$DB_USER = getenv('DB_USER') ?: 'pandapesa_user';
$DB_PASS = getenv('DB_PASS') ?: '';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Azure Database for MySQL enforces TLS; local dev MySQL/MariaDB doesn't
// need or offer it, so only request SSL for a real remote host.
if ($DB_HOST !== 'localhost' && $DB_HOST !== '127.0.0.1') {
    $caBundle = '/etc/ssl/certs/ca-certificates.crt';
    if (is_readable($caBundle)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
    }
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        $options
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('DB connection failed.');
}