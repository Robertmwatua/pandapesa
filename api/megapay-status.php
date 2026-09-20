<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'failed', 'error' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/db.php';
$cfg = require __DIR__ . '/megapay-config.php';

$userId    = $_SESSION['user_id'];
$depositId = (int)($_GET['ref'] ?? 0);

$stmt = $pdo->prepare('SELECT id, amount, status, reference FROM deposits WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$depositId, $userId]);
$deposit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deposit) {
    echo json_encode(['status' => 'failed', 'error' => 'Deposit not found.']);
    exit;
}

if ($deposit['status'] === 'completed') {
    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    echo json_encode(['status' => 'completed', 'balance' => (float)$stmt->fetchColumn()]);
    exit;
}

if ($deposit['status'] === 'failed') {
    echo json_encode(['status' => 'failed']);
    exit;
}

// Still pending — ask MegaPay directly.
$payload = [
    'api_key'                => $cfg['api_key'],
    'email'                  => $cfg['email'],
    'transaction_request_id' => $deposit['reference'],
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => $cfg['api_base'] . '/transactionstatus',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($httpCode < 200 || $httpCode >= 300) {
    // Transient upstream hiccup — stay pending, the client will poll again.
    echo json_encode(['status' => 'pending']);
    exit;
}

$data = json_decode($response, true);
$mpStatus = strtolower($data['TransactionStatus'] ?? '');

if ($mpStatus === 'completed') {
    $pdo->beginTransaction();
    try {
        // Row-lock and re-check so two overlapping poll requests can't double-credit.
        $stmt = $pdo->prepare("SELECT status FROM deposits WHERE id = ? FOR UPDATE");
        $stmt->execute([$depositId]);
        $current = $stmt->fetchColumn();

        if ($current === 'pending') {
            $pdo->prepare('UPDATE deposits SET status = "completed" WHERE id = ?')->execute([$depositId]);
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$deposit['amount'], $userId]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('MegaPay settlement failed (deposit ' . $depositId . '): ' . $e->getMessage());
        echo json_encode(['status' => 'pending']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    echo json_encode(['status' => 'completed', 'balance' => (float)$stmt->fetchColumn()]);
    exit;
}

if ($mpStatus === '' || strpos($mpStatus, 'pending') !== false || strpos($mpStatus, 'process') !== false) {
    echo json_encode(['status' => 'pending']);
    exit;
}

// Any other terminal status (Failed, Cancelled, etc.)
$pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$depositId]);
echo json_encode(['status' => 'failed']);
