<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once __DIR__ . '/../api/db.php';
$adminUser = $_SESSION['admin_username'] ?? 'Admin';
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function adminH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function adminMoney(float $amount): string {
    return 'KES ' . number_format($amount, 2);
}
function adminDateFilter(string $column, string $period, string $from, string $to, array &$params): string {
    if ($period === 'today') {
        return "$column >= CURDATE() AND $column < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    }
    if ($period === '7d') {
        return "$column >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND $column < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    }
    if ($period === '30d') {
        return "$column >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND $column < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    }
    if ($period === 'custom' && $from !== '' && $to !== '') {
        $params[] = $from;
        $params[] = $to;
        return "$column >= ? AND $column < DATE_ADD(?, INTERVAL 1 DAY)";
    }
    return '1=1';
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['admin_csrf'], $token)) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Session verification failed. Refresh the page and try again.'];
        header('Location: /admin/admin.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'publish_announcement') {
        $title = trim((string)($_POST['announcement_title'] ?? ''));
        $message = trim((string)($_POST['announcement_message'] ?? ''));
        if ($title === '' || strlen($title) > 480 || $message === '' || strlen($message) > 8000) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Enter a title (up to 120 characters) and message (up to 2,000 characters).'];
            header('Location: /admin/admin.php');
            exit;
        }
        try {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE site_announcements SET is_active = 0 WHERE is_active = 1');
            $stmt = $pdo->prepare('INSERT INTO site_announcements (title, message, is_active, created_by) VALUES (?, ?, 1, ?)');
            $stmt->execute([$title, $message, (int)$_SESSION['admin_id']]);
            $pdo->commit();
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Announcement published. Users will see it on the trading page.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin announcement publish failed: ' . $e->getMessage());
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not publish announcement. Apply the announcements database migration first.'];
        }
        header('Location: /admin/admin.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'clear_announcement') {
        try {
            $pdo->exec('UPDATE site_announcements SET is_active = 0 WHERE is_active = 1');
            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'The site announcement was cleared.'];
        } catch (Throwable $e) {
            error_log('Admin announcement clear failed: ' . $e->getMessage());
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not clear announcement. Apply the announcements database migration first.'];
        }
        header('Location: /admin/admin.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'mark_withdrawal_paid') {
        $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
        $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
        if ($withdrawalId < 1 || $paymentReference === '' || strlen($paymentReference) > 100) {
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Enter the M-Pesa receipt/reference before marking this withdrawal paid.'];
            header('Location: /admin/admin.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT status FROM withdrawals WHERE id = ? FOR UPDATE');
            $stmt->execute([$withdrawalId]);
            $status = $stmt->fetchColumn();
            if ($status !== 'pending') {
                $pdo->rollBack();
                $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'This withdrawal is no longer pending.'];
            } else {
                $stmt = $pdo->prepare('UPDATE withdrawals SET status = "completed", payment_reference = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = "pending"');
                $stmt->execute([$paymentReference, (int)$_SESSION['admin_id'], $withdrawalId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Withdrawal status changed before it could be updated.');
                }
                $pdo->commit();
                $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Withdrawal marked paid and receipt recorded.'];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin withdrawal processing failed: ' . $e->getMessage());
            $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not update the withdrawal. Ensure the withdrawal processing migration has been applied.'];
        }
        header('Location: /admin/admin.php');
        exit;
    }

    $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Unknown admin action.'];
    header('Location: /admin/admin.php');
    exit;
}

$period = (string)($_GET['period'] ?? 'today');
if (!in_array($period, ['today', '7d', '30d', 'all', 'custom'], true)) $period = 'today';
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
if ($period === 'custom' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to)) {
    $period = 'today';
}
$periodLabel = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time', 'custom' => $from . ' to ' . $to][$period];

$statsLoaded = false;
$totalUsers = 0;
$currentWalletBalances = 0.0;
$periodDepositCount = 0;
$periodCompletedDeposits = 0.0;
$periodPendingDeposits = 0.0;
$periodPaidWithdrawalCount = 0;
$periodCompletedWithdrawals = 0.0;
$periodPendingWithdrawals = 0.0;
$periodTradeCount = 0;
$periodSettledStake = 0.0;
$periodSettledPayout = 0.0;
$allCompletedDeposits = 0.0;
$allCompletedWithdrawals = 0.0;
$allPendingWithdrawals = 0.0;
$allSettledStake = 0.0;
$allSettledPayout = 0.0;
$allOpenTradeStake = 0.0;
$pendingQueue = [];
$recentTransactions = [];

try {
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $currentWalletBalances = (float)$pdo->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();

    $depositParams = [];
    $depositWhere = adminDateFilter('d.created_at', $period, $from, $to, $depositParams);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_count, COALESCE(SUM(CASE WHEN d.status='completed' THEN d.amount ELSE 0 END),0) AS completed_amount, COALESCE(SUM(CASE WHEN d.status='pending' THEN d.amount ELSE 0 END),0) AS pending_amount FROM deposits d WHERE $depositWhere");
    $stmt->execute($depositParams);
    $depositStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $periodDepositCount = (int)$depositStats['total_count'];
    $periodCompletedDeposits = (float)$depositStats['completed_amount'];
    $periodPendingDeposits = (float)$depositStats['pending_amount'];

    $withdrawalParams = [];
    $withdrawalWhere = adminDateFilter('w.created_at', $period, $from, $to, $withdrawalParams);
    $stmt = $pdo->prepare("SELECT COUNT(CASE WHEN w.status='completed' THEN 1 END) AS paid_count, COALESCE(SUM(CASE WHEN w.status='completed' THEN w.amount ELSE 0 END),0) AS paid_amount, COALESCE(SUM(CASE WHEN w.status='pending' THEN w.amount ELSE 0 END),0) AS pending_amount FROM withdrawals w WHERE $withdrawalWhere");
    $stmt->execute($withdrawalParams);
    $withdrawalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $periodPaidWithdrawalCount = (int)$withdrawalStats['paid_count'];
    $periodCompletedWithdrawals = (float)$withdrawalStats['paid_amount'];
    $periodPendingWithdrawals = (float)$withdrawalStats['pending_amount'];

    $tradeParams = [];
    $tradeWhere = adminDateFilter('t.created_at', $period, $from, $to, $tradeParams);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS trade_count, COALESCE(SUM(t.stake),0) AS stakes, COALESCE(SUM(t.payout),0) AS payouts FROM trades t WHERE t.result IN ('win','loss') AND $tradeWhere");
    $stmt->execute($tradeParams);
    $periodTradeStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $periodTradeCount = (int)$periodTradeStats['trade_count'];
    $periodSettledStake = (float)$periodTradeStats['stakes'];
    $periodSettledPayout = (float)$periodTradeStats['payouts'];

    // These are all-time/current values used only for the overall ledger residual.
    $allCompletedDeposits = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='completed'")->fetchColumn();
    $allCompletedWithdrawals = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
    $allPendingWithdrawals = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $allTradeStats = $pdo->query("SELECT COALESCE(SUM(stake),0) AS stakes, COALESCE(SUM(payout),0) AS payouts FROM trades WHERE result IN ('win','loss')")->fetch(PDO::FETCH_ASSOC);
    $allSettledStake = (float)$allTradeStats['stakes'];
    $allSettledPayout = (float)$allTradeStats['payouts'];
    $allOpenTradeStake = (float)$pdo->query("SELECT COALESCE(SUM(stake),0) FROM trades WHERE result='pending'")->fetchColumn();

    // Always show every pending withdrawal, regardless of date filter, so requests are not hidden.
    $pendingQueue = $pdo->query("SELECT w.id, w.user_id, u.username, w.amount, w.payout_phone, w.created_at FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.status='pending' ORDER BY w.created_at ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

    $txParams = [];
    $depositTxWhere = adminDateFilter('d.created_at', $period, $from, $to, $txParams);
    $withdrawalTxParams = [];
    $withdrawalTxWhere = adminDateFilter('w.created_at', $period, $from, $to, $withdrawalTxParams);
    $recentSql = "SELECT * FROM (SELECT 'Deposit' AS kind, u.username, d.amount, d.status, d.created_at, d.reference, NULL AS phone FROM deposits d JOIN users u ON u.id=d.user_id WHERE $depositTxWhere UNION ALL SELECT 'Withdrawal' AS kind, u.username, w.amount, w.status, w.created_at, w.payment_reference AS reference, w.payout_phone AS phone FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE $withdrawalTxWhere) tx ORDER BY created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($recentSql);
    $stmt->execute(array_merge($txParams, $withdrawalTxParams));
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statsLoaded = true;
} catch (PDOException $e) {
    error_log('Admin financial reporting query failed: ' . $e->getMessage());
}

$periodNetCashFlow = $periodCompletedDeposits - $periodCompletedWithdrawals;
$periodTradeResult = $periodSettledStake - $periodSettledPayout;
$allLedgerResidual = ($allCompletedDeposits - $allCompletedWithdrawals) - $currentWalletBalances - $allPendingWithdrawals - $allOpenTradeStake;
$activeAnnouncement = null;
try {
    $activeAnnouncement = $pdo->query('SELECT id, title, message, created_at FROM site_announcements WHERE is_active=1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log('Admin announcement read failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Financial Overview — Pandapesa Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>body{font-family:'Plus Jakarta Sans',sans-serif;background:#0a0f17;}</style>
</head>
<body class="min-h-screen text-white">
  <header class="bg-gray-900/80 border-b border-gray-800 px-6 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center font-bold" style="font-family:'Space Grotesk'">P</div>
      <span class="font-bold" style="font-family:'Space Grotesk'">pandapesa · Admin</span>
    </div>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-gray-400">Signed in as <span class="text-blue-400 font-semibold"><?= adminH($adminUser) ?></span></span>
      <a href="/admin/logout.php" class="text-red-400 hover:text-red-300 font-medium">Logout</a>
    </div>
  </header>

  <main class="max-w-7xl mx-auto p-6">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold" style="font-family:'Space Grotesk'">Financial Overview</h1>
        <p class="text-sm text-gray-400 mt-1">Transaction totals for <?= adminH($periodLabel) ?></p>
      </div>
      <form method="GET" class="flex flex-wrap items-end gap-2 bg-gray-900/60 border border-gray-800 rounded-xl p-3">
        <label class="text-xs text-gray-400">Period
          <select name="period" class="block mt-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="today" <?= $period==='today'?'selected':'' ?>>Today</option>
            <option value="7d" <?= $period==='7d'?'selected':'' ?>>Last 7 days</option>
            <option value="30d" <?= $period==='30d'?'selected':'' ?>>Last 30 days</option>
            <option value="all" <?= $period==='all'?'selected':'' ?>>All time</option>
            <option value="custom" <?= $period==='custom'?'selected':'' ?>>Custom dates</option>
          </select>
        </label>
        <label class="text-xs text-gray-400">From
          <input type="date" name="from" value="<?= adminH($from) ?>" class="block mt-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </label>
        <label class="text-xs text-gray-400">To
          <input type="date" name="to" value="<?= adminH($to) ?>" class="block mt-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
        </label>
        <button class="bg-blue-600 hover:bg-blue-500 rounded-lg px-4 py-2 text-sm font-semibold">Apply</button>
      </form>
    </div>

    <?php if ($flash): ?>
      <div class="<?= $flash['type']==='success'?'bg-green-500/10 border-green-500/30 text-green-300':'bg-red-500/10 border-red-500/30 text-red-300' ?> border rounded-xl p-4 mb-5 text-sm"><?= adminH($flash['message']) ?></div>
    <?php endif; ?>
    <?php if (!$statsLoaded): ?>
      <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-xl p-4 mb-5 text-sm">Financial data could not be loaded. Check the database connection, required tables, and withdrawal processing migration.</div>
    <?php endif; ?>

    <section class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5 mb-6">
      <h2 class="text-lg font-semibold">Site-wide announcement</h2>
      <p class="text-xs text-gray-500 mt-1 mb-4">This message appears on the trading page for all visitors and logged-in users. It stays visible until you publish another message or clear it.</p>
      <?php if ($activeAnnouncement): ?>
        <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4 mb-4">
          <div class="text-xs uppercase tracking-wide text-blue-300 mb-1">Currently visible · <?= adminH($activeAnnouncement['created_at']) ?></div>
          <div class="font-semibold text-white"><?= adminH($activeAnnouncement['title']) ?></div>
          <div class="text-sm text-gray-300 mt-1 whitespace-pre-wrap"><?= adminH($activeAnnouncement['message']) ?></div>
          <form method="POST" class="mt-3" onsubmit="return confirm('Clear the announcement for everyone?');">
            <input type="hidden" name="csrf_token" value="<?= adminH($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="clear_announcement">
            <button class="bg-gray-700 hover:bg-gray-600 rounded-lg px-3 py-2 text-xs font-semibold">Clear announcement</button>
          </form>
        </div>
      <?php else: ?>
        <div class="text-sm text-gray-500 mb-4">No announcement is currently visible.</div>
      <?php endif; ?>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= adminH($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="action" value="publish_announcement">
        <label class="block text-xs text-gray-400">Title
          <input type="text" name="announcement_title" maxlength="120" required class="block mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white" placeholder="For example: Scheduled maintenance">
        </label>
        <label class="block text-xs text-gray-400">Message
          <textarea name="announcement_message" maxlength="2000" rows="3" required class="block mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white" placeholder="Write the message users should see..."></textarea>
        </label>
        <button class="bg-blue-600 hover:bg-blue-500 rounded-lg px-4 py-2 text-sm font-semibold">Publish to all users</button>
      </form>
    </section>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Completed deposits · <?= adminH($periodLabel) ?></div><div class="text-2xl font-bold text-green-400"><?= $statsLoaded ? adminMoney($periodCompletedDeposits) : '—' ?></div><div class="text-xs text-gray-500 mt-2"><?= number_format($periodDepositCount) ?> deposits recorded · pending <?= $statsLoaded ? adminMoney($periodPendingDeposits) : '—' ?></div></div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Paid withdrawals · <?= adminH($periodLabel) ?></div><div class="text-2xl font-bold text-red-400"><?= $statsLoaded ? adminMoney($periodCompletedWithdrawals) : '—' ?></div><div class="text-xs text-gray-500 mt-2"><?= number_format($periodPaidWithdrawalCount) ?> paid · pending <?= $statsLoaded ? adminMoney($periodPendingWithdrawals) : '—' ?></div></div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Net recorded cash flow · <?= adminH($periodLabel) ?></div><div class="text-2xl font-bold text-blue-400"><?= $statsLoaded ? adminMoney($periodNetCashFlow) : '—' ?></div><div class="text-xs text-gray-500 mt-2">Completed deposits minus paid withdrawals, before fees</div></div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Current player wallet balances</div><div class="text-2xl font-bold text-yellow-300"><?= $statsLoaded ? adminMoney($currentWalletBalances) : '—' ?></div><div class="text-xs text-gray-500 mt-2"><?= number_format($totalUsers) ?> user accounts</div></div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Settled trade result · <?= adminH($periodLabel) ?></div><div class="text-2xl font-bold <?= $periodTradeResult >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $statsLoaded ? adminMoney($periodTradeResult) : '—' ?></div><div class="text-xs text-gray-500 mt-2">Stakes minus payouts · <?= number_format($periodTradeCount) ?> resolved trades</div></div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5"><div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Estimated all-time ledger residual</div><div class="text-2xl font-bold text-amber-300"><?= $statsLoaded ? adminMoney($allLedgerResidual) : '—' ?></div><div class="text-xs text-gray-500 mt-2">Ledger estimate only; reconcile against MegaPay/bank</div></div>
    </div>

    <section class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5 mb-6">
      <div class="flex items-center justify-between gap-3 mb-4"><div><h2 class="text-lg font-semibold">Pending withdrawals</h2><p class="text-xs text-gray-500 mt-1">All dates · make the M-Pesa payment first, then record its receipt below.</p></div><span class="text-amber-300 text-sm font-semibold"><?= count($pendingQueue) ?> pending shown</span></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[900px]">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-800"><th class="py-3 pr-4">Requested</th><th class="py-3 pr-4">User</th><th class="py-3 pr-4">Amount</th><th class="py-3 pr-4">M-Pesa number</th><th class="py-3">Confirm payment</th></tr></thead>
          <tbody>
          <?php if (!$pendingQueue): ?><tr><td colspan="5" class="py-6 text-center text-gray-500">No pending withdrawals.</td></tr>
          <?php else: foreach ($pendingQueue as $w): ?>
            <tr class="border-b border-gray-800/70 align-top"><td class="py-4 pr-4 text-gray-400 whitespace-nowrap"><?= adminH($w['created_at']) ?></td><td class="py-4 pr-4 font-medium"><?= adminH($w['username']) ?> <span class="text-gray-500">#<?= (int)$w['user_id'] ?></span></td><td class="py-4 pr-4 text-amber-300 font-semibold whitespace-nowrap"><?= adminMoney((float)$w['amount']) ?></td><td class="py-4 pr-4 font-mono text-gray-200"><?= adminH($w['payout_phone'] ?: 'No phone recorded') ?></td><td class="py-3">
              <form method="POST" class="flex items-center gap-2" onsubmit="return confirm('Confirm you have sent this payment to the displayed M-Pesa number?');">
                <input type="hidden" name="csrf_token" value="<?= adminH($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="mark_withdrawal_paid">
                <input type="hidden" name="withdrawal_id" value="<?= (int)$w['id'] ?>">
                <input type="text" name="payment_reference" required maxlength="100" placeholder="M-Pesa receipt" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-xs text-white w-40">
                <button class="bg-green-600 hover:bg-green-500 rounded-lg px-3 py-2 text-xs font-semibold whitespace-nowrap">Mark paid</button>
              </form>
            </td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($pendingQueue) === 200): ?><p class="text-xs text-amber-300 mt-3">Only the oldest 200 pending requests are shown. Process some to reveal the next requests.</p><?php endif; ?>
    </section>

    <section class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5 mb-6">
      <h2 class="text-lg font-semibold mb-4">Transactions · <?= adminH($periodLabel) ?></h2>
      <div class="overflow-x-auto"><table class="w-full text-sm min-w-[760px]">
        <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-800"><th class="py-3 pr-4">Date</th><th class="py-3 pr-4">Type</th><th class="py-3 pr-4">User</th><th class="py-3 pr-4">Amount</th><th class="py-3 pr-4">Status</th><th class="py-3 pr-4">Phone</th><th class="py-3">Reference</th></tr></thead>
        <tbody>
        <?php if (!$recentTransactions): ?><tr><td colspan="7" class="py-6 text-center text-gray-500">No transactions in this date range.</td></tr>
        <?php else: foreach ($recentTransactions as $tx): ?>
          <tr class="border-b border-gray-800/70"><td class="py-3 pr-4 whitespace-nowrap text-gray-400"><?= adminH($tx['created_at']) ?></td><td class="py-3 pr-4"><?= adminH($tx['kind']) ?></td><td class="py-3 pr-4"><?= adminH($tx['username']) ?></td><td class="py-3 pr-4 font-mono"><?= adminMoney((float)$tx['amount']) ?></td><td class="py-3 pr-4"><?= adminH(ucfirst((string)$tx['status'])) ?></td><td class="py-3 pr-4 font-mono text-gray-400"><?= adminH($tx['phone'] ?: '—') ?></td><td class="py-3 font-mono text-xs text-gray-500"><?= adminH($tx['reference'] ?: '—') ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
      <p class="text-xs text-gray-500 mt-3">Showing up to 100 deposit and withdrawal records for this period.</p>
    </section>

    <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-200 mb-6"><strong>Accounting note:</strong> the ledger residual uses all-time completed deposits minus completed withdrawals, current player balances, pending withdrawals, and open trade stakes. It is not your live MegaPay/bank balance and excludes provider fees, chargebacks, and money movements not recorded here.</div>
  </main>
</body>
</html>
