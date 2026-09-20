<?php
// Copy this file to db.php for local dev, or set these same names as
// Azure App Service Application Settings in production — either way
// PHP picks them up via getenv().
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'pandapesa';
$DB_USER = getenv('DB_USER') ?: 'pandapesa_user';
$DB_PASS = getenv('DB_PASS') ?: 'changeme';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('DB connection failed.');
}
