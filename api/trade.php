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

// Winners keep a fixed 75% profit on their stake. The remaining 25% of the
// profit split is recorded in the winners_pool for manual distribution.
const WIN_PROFIT_SHARE = 0.75;

// Real-money outcomes are forced by the user's lifetime deposits. Users who
// have deposited below this threshold are always losers; users at or above
// it can win, and the win/loss direction is decided by the chart move.
const WIN_DEPOSIT_THRESHOLD = 1000;

$userId = $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

function currentBalance(PDO $pdo, int $userId): float {
    $stmt = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

function lifetimeDeposits(PDO $pdo, int $userId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    return (float)$stmt->fetchColumn();
}

// Returns ['won' => bool, 'payout' => float, 'house_cut' => float].
// Winners always take a fixed 75% profit on the stake; the remaining 25% of
// the profit split is held in the winners_pool for manual distribution to
// winning users. Losers forfeit their full stake.
function forcedOutcome(float $stake, float $entryRate, float $exitRate, bool $expired, float $lifetimeDeposits): array {
    $rateDiff = $exitRate - $entryRate;
    $directionUp = $rateDiff > 0;

    // Expiry / crash is always a loss, regardless of deposits or direction.
    if ($expired) {
        return ['won' => false, 'payout' => 0.0, 'house_cut' => 0.0];
    }

    // Users who have not met the deposit threshold are always losers.
    if ($lifetimeDeposits < WIN_DEPOSIT_THRESHOLD) {
        return ['won' => false, 'payout' => 0.0, 'house_cut' => 0.0];
    }

    // A winner needs a favourable chart move; otherwise it is a loss.
    if (!$directionUp) {
        return ['won' => false, 'payout' => 0.0, 'house_cut' => 0.0];
    }

    // Profit = stake * rateDiff, capped at MAX_MULT.
    $rawProfit = $stake * $rateDiff;
    $profit = min($rawProfit, $stake * (MAX_MULT - 1.0));

    $payout    = $stake + $profit * WIN_PROFIT_SHARE;
    $house_cut = $profit * (1.0 - WIN_PROFIT_SHARE);

    return ['won' => true, 'payout' => $payout, 'house_cut' => $house_cut];
}

switch ($action) {

    case 'balance':
        echo json_encode([
            'balance' => currentBalance($pdo, $userId),
            'deposited' => lifetimeDeposits($pdo, $userId),
            'won_threshold' => WIN_DEPOSIT_THRESHOLD,
        ]);
        break;

    case 'eligibility': {
        echo json_encode([
            'deposited' => lifetimeDeposits($pdo, $userId),
            'threshold' => WIN_DEPOSIT_THRESHOLD,
        ]);
        break;
    }

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
            $stmt = $pdo->prepare('SELECT stake, entry_rate, exit_rate, payout, house_cut, result FROM trades WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$tradeId, $userId]);
            $trade = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trade) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Trade not found.']);
                break;
            }

            // A previous response may have been lost after the transaction
            // committed. Return its stored outcome without crediting twice.
            if ($trade['result'] !== 'pending') {
                $deposited = lifetimeDeposits($pdo, $userId);
                $newBalance = currentBalance($pdo, $userId);
                $pdo->commit();
                echo json_encode([
                    'balance' => $newBalance,
                    'result' => $trade['result'],
                    'payout' => (float)$trade['payout'],
                    'house_cut' => (float)$trade['house_cut'],
                    'won_threshold' => WIN_DEPOSIT_THRESHOLD,
                    'deposited' => $deposited,
                    'exit_rate' => (float)$trade['exit_rate'],
                    'already_resolved' => true,
                ]);
                break;
            }

            $stake     = (float)$trade['stake'];
            $entryRate = (float)$trade['entry_rate'];

            $lifetimeDeposits = lifetimeDeposits($pdo, $userId);
            $outcome = forcedOutcome($stake, $entryRate, $exitRate, $expired, $lifetimeDeposits);
            $payout    = $outcome['payout'];
            $house_cut = $outcome['house_cut'];
            $result    = $outcome['won'] ? 'win' : 'loss';

            $pdo->prepare('UPDATE trades SET exit_rate = ?, payout = ?, house_cut = ?, result = ? WHERE id = ?')
                ->execute([$exitRate, $payout, $house_cut, $result, $tradeId]);

            if ($payout > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$payout, $userId]);
            }

            if ($house_cut > 0) {
                $pdo->prepare('INSERT INTO winners_pool (trade_id, user_id, amount, paid_out) VALUES (?, ?, ?, 0)')
                    ->execute([$tradeId, $userId, $house_cut]);
            }

            $newBalance = currentBalance($pdo, $userId);
            $pdo->commit();
            echo json_encode([
                'balance'      => $newBalance,
                'result'       => $result,
                'payout'       => $payout,
                'house_cut'    => $house_cut,
                'won_threshold'=> WIN_DEPOSIT_THRESHOLD,
                'deposited'    => $lifetimeDeposits,
            ]);
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
