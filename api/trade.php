<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

require_once __DIR__ . '/db.php';

// Mirrors api/settings.php's trade config — kept in sync manually since settings.php
// echoes JSON directly and can't be required for its values.
const MIN_STAKE = 10;
const MAX_STAKE = 50000;
const MAX_MULT  = 5.0;

$userId = $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

function currentBalance(PDO $pdo, int $userId): float {
    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

switch ($action) {

    case 'balance':
        echo json_encode(['balance' => currentBalance($pdo, $userId)]);
        break;

    case 'place': {
        $type      = $input['type'] ?? '';
        $stake     = (float)($input['stake'] ?? 0);
        $entryRate = (float)($input['entry_rate'] ?? 0);

        if (!in_array($type, ['buy', 'sell'], true)) {
            echo json_encode(['error' => 'Invalid trade type.']);
            break;
        }
        if ($stake < MIN_STAKE || $stake > MAX_STAKE) {
            echo json_encode(['error' => 'Invalid stake amount.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $balance = $stmt->fetchColumn();

            if ($balance === false || (float)$balance < $stake) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Insufficient balance.']);
                break;
            }

            $newBalance = (float)$balance - $stake;
            $pdo->prepare('UPDATE users SET balance = ? WHERE id = ?')->execute([$newBalance, $userId]);
            $pdo->prepare('INSERT INTO trades (user_id, type, stake, entry_rate, result) VALUES (?, ?, ?, ?, "pending")')
                ->execute([$userId, $type, $stake, $entryRate]);
            $tradeId = (int)$pdo->lastInsertId();

            $pdo->commit();
            echo json_encode(['balance' => $newBalance, 'trade_id' => $tradeId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            echo json_encode(['error' => 'Could not place trade.']);
        }
        break;
    }

    case 'cancel': {
        $tradeId = (int)($input['trade_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT stake FROM trades WHERE id = ? AND user_id = ? AND result = 'pending' FOR UPDATE");
            $stmt->execute([$tradeId, $userId]);
            $stake = $stmt->fetchColumn();

            if ($stake === false) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Trade not found or already resolved.']);
                break;
            }

            $pdo->prepare('DELETE FROM trades WHERE id = ?')->execute([$tradeId]);
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$stake, $userId]);
            $newBalance = currentBalance($pdo, $userId);

            $pdo->commit();
            echo json_encode(['balance' => $newBalance]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            echo json_encode(['error' => 'Could not cancel trade.']);
        }
        break;
    }

    case 'resolve': {
        $tradeId  = (int)($input['trade_id'] ?? 0);
        $exitRate = (float)($input['exit_rate'] ?? 0);
        $expired  = !empty($input['expired']);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT stake, entry_rate FROM trades WHERE id = ? AND user_id = ? AND result = 'pending' FOR UPDATE");
            $stmt->execute([$tradeId, $userId]);
            $trade = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trade) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Trade not found or already resolved.']);
                break;
            }

            $stake     = (float)$trade['stake'];
            $entryRate = (float)$trade['entry_rate'];
            $rateDiff  = $exitRate - $entryRate;

            // Letting the timer expire (or a crash through zero) is always a loss,
            // even if the rate happened to be favourable at that instant — matches
            // the client's demo-mode settlement rule (must cash out or hit autosell).
            if ($expired) {
                $payout = 0.0;
            } else {
                $rawPayout = $rateDiff > 0 ? $stake * (1 + $rateDiff) : 0.0;
                $payout = min($rawPayout, $stake * MAX_MULT);
            }
            $result = $payout > 0 ? 'win' : 'loss';

            $pdo->prepare('UPDATE trades SET exit_rate = ?, payout = ?, result = ? WHERE id = ?')
                ->execute([$exitRate, $payout, $result, $tradeId]);

            if ($payout > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$payout, $userId]);
            }

            $newBalance = currentBalance($pdo, $userId);
            $pdo->commit();
            echo json_encode(['balance' => $newBalance, 'result' => $result, 'payout' => $payout]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            echo json_encode(['error' => 'Could not resolve trade.']);
        }
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
