<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->query('SELECT id, title, message, created_at FROM site_announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['announcement' => $announcement ?: null], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('Announcement endpoint failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['announcement' => null, 'error' => 'Announcements are temporarily unavailable.']);
}
