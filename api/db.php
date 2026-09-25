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

// Enforce administrator bans for every signed-in user page/API that loads this
// shared bootstrap. The admin session is separate and is not affected.
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $banCheck = $pdo->prepare('SELECT banned FROM users WHERE id = ? LIMIT 1');
    $banCheck->execute([(int)$_SESSION['user_id']]);
    $isBanned = $banCheck->fetchColumn();

    if ($isBanned === false || (int)$isBanned === 1) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (strpos($requestPath, '/api/') === 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'This account is suspended. Contact support.']);
            exit;
        }

        header('Location: /login.php' . ($isBanned !== false ? '?banned=1' : ''));
        exit;
    }
}
