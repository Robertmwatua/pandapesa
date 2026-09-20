<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/db.php';
$cfg = require __DIR__ . '/payhero-config.php';

const MIN_DEPOSIT = 50;

$userId = $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

$amount = (float)($input['amount'] ?? 0);
$phone  = trim($input['phone'] ?? '');

if (!preg_match('/^254\d{9}$/', $phone)) {
    echo json_encode(['success' => false, 'error' => 'Invalid phone format (254XXXXXXXXX)']);
    exit;
}
if ($amount < MIN_DEPOSIT) {
    echo json_encode(['success' => false, 'error' => "Minimum deposit is KES " . MIN_DEPOSIT]);
    exit;
}

$stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$username = $stmt->fetchColumn() ?: 'Customer';

// Record the deposit attempt first so we have an id to use as our external_reference.
$stmt = $pdo->prepare('INSERT INTO deposits (user_id, amount, status) VALUES (?, ?, "pending")');
$stmt->execute([$userId, $amount]);
$depositId = (int)$pdo->lastInsertId();

$payload = [
    'amount'             => $amount,
    'phone_number'       => $phone,
    'channel_id'         => $cfg['channel_id'],
    'provider'           => $cfg['provider'],
    'network_code'       => $cfg['network_code'],
    'customer_name'      => $username,
    'external_reference' => 'PP-DEP-' . $depositId,
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => $cfg['api_base'] . '/payments',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: ' . $cfg['basic_auth'],
    ],
]);
$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($curlError || $httpCode < 200 || $httpCode >= 300) {
    $pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$depositId]);
    $data = json_decode($response, true);
    $message = $curlError ?: ($data['message'] ?? $data['error'] ?? 'PayHero request failed.');
    error_log('PayHero STK push failed (deposit ' . $depositId . '): ' . ($curlError ?: $response));
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$data = json_decode($response, true);
$payheroReference = $data['reference'] ?? null;

if (!$payheroReference) {
    $pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$depositId]);
    error_log('PayHero STK push returned no reference (deposit ' . $depositId . '): ' . $response);
    echo json_encode(['success' => false, 'error' => 'PayHero did not return a tracking reference.']);
    exit;
}

$pdo->prepare('UPDATE deposits SET reference = ? WHERE id = ?')->execute([$payheroReference, $depositId]);

echo json_encode([
    'success'   => true,
    'message'   => 'STK push sent. Enter your M-Pesa PIN to complete the deposit of KES ' . number_format($amount, 2) . '.',
    'reference' => $depositId,
]);
