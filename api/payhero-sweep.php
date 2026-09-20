<?php
// Aggregator safety net for PayHero — see the comment in trade.php where this
// is called. Reconciles any deposit stuck 'pending' because nobody was
// watching the deposit modal when it actually completed. Runs on every page
// load but self-throttles to once per minute across all visitors via a lock
// file, so concurrent traffic doesn't hammer PayHero's API.
header('Content-Type: application/json');

$cfgFile = __DIR__ . '/payhero-config.php';
if (!file_exists($cfgFile)) {
    echo json_encode(['status' => 'not_configured']);
    exit;
}

$lockFile = sys_get_temp_dir() . '/payhero_sweep.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 60) {
    echo json_encode(['status' => 'throttled']);
    exit;
}
touch($lockFile);

require_once __DIR__ . '/db.php';
$cfg = require $cfgFile;

$stmt = $pdo->query("SELECT id, user_id, amount, reference FROM deposits WHERE status = 'pending' AND reference IS NOT NULL ORDER BY created_at ASC LIMIT 20");
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$settled = 0;
foreach ($pending as $deposit) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $cfg['api_base'] . '/transaction-status?reference=' . urlencode($deposit['reference']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: ' . $cfg['basic_auth'],
        ],
    ]);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode < 200 || $httpCode >= 300) continue;

    $data = json_decode($response, true);
    $phStatus = $data['status'] ?? '';

    if ($phStatus === 'QUEUED') continue;

    if ($phStatus === 'SUCCESS') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM deposits WHERE id = ? FOR UPDATE");
            $stmt->execute([$deposit['id']]);
            if ($stmt->fetchColumn() === 'pending') {
                $pdo->prepare('UPDATE deposits SET status = "completed" WHERE id = ?')->execute([$deposit['id']]);
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$deposit['amount'], $deposit['user_id']]);
                $settled++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('PayHero sweep settlement failed (deposit ' . $deposit['id'] . '): ' . $e->getMessage());
        }
    } else {
        // Terminal non-success state (FAILED, CANCELLED, etc.)
        $pdo->prepare('UPDATE deposits SET status = "failed" WHERE id = ?')->execute([$deposit['id']]);
    }
}

echo json_encode(['status' => 'ok', 'checked' => count($pending), 'settled' => $settled]);
