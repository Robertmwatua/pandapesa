<?php
require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/db.php';
$cfg = require __DIR__ . '/megapay-config.php';

const MIN_DEPOSIT = 150;

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

// Record the deposit attempt first so we have an id to use as our reference.
$stmt = $pdo->prepare('INSERT INTO deposits (user_id, amount, status) VALUES (?, ?, "pending")');
$stmt->execute([$userId, $amount]);
$depositId = (int)$pdo->lastInsertId();

$payload = [
    'api_key'   => $cfg['api_key'],
    'email'     => $cfg['email'],
    'amount'    => $amount,
    'msisdn'    => $phone,
    'reference' => 'PP-DEP-' . $depositId,
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => $cfg['api_base'] . '/initiatestk',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

$data = json_decode($response, true);

if ($curlError || $httpCode < 200 || $httpCode >= 300 || ($data['success'] ?? null) != '200') {
    $pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$depositId]);
    $message = $curlError ?: ($data['massage'] ?? $data['message'] ?? 'MegaPay request failed.');
    error_log('MegaPay STK push failed (deposit ' . $depositId . '): ' . ($curlError ?: $response));
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$megapayReference = $data['transaction_request_id'] ?? null;

if (!$megapayReference) {
    $pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$depositId]);
    error_log('MegaPay STK push returned no transaction_request_id (deposit ' . $depositId . '): ' . $response);
    echo json_encode(['success' => false, 'error' => 'MegaPay did not return a tracking reference.']);
    exit;
}

$pdo->prepare('UPDATE deposits SET reference = ? WHERE id = ?')->execute([$megapayReference, $depositId]);

echo json_encode([
    'success'   => true,
    'message'   => 'STK push sent. Enter your M-Pesa PIN to complete the deposit of KES ' . number_format($amount, 2) . '.',
    'reference' => $depositId,
]);
