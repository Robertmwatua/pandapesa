<?php
require_once __DIR__ . '/db-connect.php';

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
