<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/db.php';

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'request': {
        $amount = (float)($input['amount'] ?? 0);
        if ($amount <= 0) {
            echo json_encode(['error' => 'Invalid amount.']);
            break;
        }
        if ($amount < 10) {
            echo json_encode(['error' => 'Minimum withdrawal is KES 10.']);
            break;
        }

        $phone = preg_replace('/[\s()+-]/', '', trim((string)($input['phone'] ?? '')));
        if (preg_match('/^0[17]\d{8}$/', $phone)) {
            $phone = '254' . substr($phone, 1);
        }
        if (!preg_match('/^254[17]\d{8}$/', $phone)) {
            echo json_encode(['error' => 'Enter a valid Kenyan M-Pesa number.']);
            break;
        }

        $userId = $_SESSION['user_id'];

        $pdo->beginTransaction();
        try {
            // Lock the user's row to avoid races (same pattern as api/trade.php)
            $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $balance = $stmt->fetchColumn();

            if ($balance === false || (float)$balance < $amount) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Insufficient balance.']);
                break;
            }

            $newBalance = (float)$balance - $amount;

            // Deduct balance and record a pending withdrawal for manual/automated payout
            $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$newBalance, $userId]);
            $pdo->prepare('INSERT INTO withdrawals (user_id, amount, payout_phone, status, created_at) VALUES (?, ?, ?, "pending", NOW())')
                ->execute([$userId, $amount, $phone]);

            $pdo->commit();
            echo json_encode(['success' => true, 'balance' => $newBalance]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('withdraw.php error: ' . $e->getMessage());
            echo json_encode(['error' => 'Could not submit withdrawal.']);
        }

        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}

