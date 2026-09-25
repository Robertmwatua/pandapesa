<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$adminUser = $_SESSION['admin_username'] ?? 'Admin';

// ── All-time financial summary from the application ledger ──
$totalUsers = 0;
$completedDeposits = 0.0;
$completedWithdrawals = 0.0;
$pendingWithdrawals = 0.0;
$walletBalances = 0.0;
$settledStake = 0.0;
$settledPayout = 0.0;
$pendingTradeStake = 0.0;
$resolvedTrades = 0;
$statsLoaded = false;
try {
    require_once __DIR__ . '/../api/db.php';
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $completedDeposits = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='completed'")->fetchColumn();
    $completedWithdrawals = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
    $pendingWithdrawals = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $walletBalances = (float)$pdo->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();

    $tradeStats = $pdo->query("SELECT COUNT(*) AS trade_count, COALESCE(SUM(stake),0) AS stakes, COALESCE(SUM(payout),0) AS payouts FROM trades WHERE result IN ('win','loss')")->fetch(PDO::FETCH_ASSOC);
    $resolvedTrades = (int)$tradeStats['trade_count'];
    $settledStake = (float)$tradeStats['stakes'];
    $settledPayout = (float)$tradeStats['payouts'];
    $pendingTradeStake = (float)$pdo->query("SELECT COALESCE(SUM(stake),0) FROM trades WHERE result='pending'")->fetchColumn();
    $statsLoaded = true;
} catch (PDOException $e) {
    error_log('Admin finance dashboard query failed: ' . $e->getMessage());
}

$netCashFlow = $completedDeposits - $completedWithdrawals;
$netTradingResult = $settledStake - $settledPayout;
$ledgerResidual = $netCashFlow - $walletBalances - $pendingWithdrawals - $pendingTradeStake;
function adminMoney(float $amount): string {
    return 'KES ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard — pandapesa</title>
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
      <span class="text-gray-400">Signed in as <span class="text-blue-400 font-semibold"><?= htmlspecialchars($adminUser) ?></span></span>
      <a href="/admin/logout.php" class="text-red-400 hover:text-red-300 font-medium">Logout</a>
    </div>
  </header>

  <main class="max-w-6xl mx-auto p-6">
    <div class="mb-6">
      <h1 class="text-2xl font-bold" style="font-family:'Space Grotesk'">Financial Overview</h1>
      <p class="text-sm text-gray-400 mt-1">All-time totals recorded by Pandapesa</p>
    </div>

    <?php if (!$statsLoaded): ?>
      <div class="bg-red-500/10 border border-red-500/30 text-red-300 rounded-xl p-4 mb-6 text-sm">
        Financial data could not be loaded. Check the database connection and required tables.
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Completed deposits</div>
        <div class="text-2xl font-bold text-green-400"><?= $statsLoaded ? adminMoney($completedDeposits) : '—' ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Paid withdrawals</div>
        <div class="text-2xl font-bold text-red-400"><?= $statsLoaded ? adminMoney($completedWithdrawals) : '—' ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Net recorded cash flow</div>
        <div class="text-2xl font-bold text-blue-400"><?= $statsLoaded ? adminMoney($netCashFlow) : '—' ?></div>
        <div class="text-xs text-gray-500 mt-2">Completed deposits minus paid withdrawals, before fees</div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">User wallet balances</div>
        <div class="text-2xl font-bold text-yellow-300"><?= $statsLoaded ? adminMoney($walletBalances) : '—' ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Pending withdrawals</div>
        <div class="text-2xl font-bold text-amber-400"><?= $statsLoaded ? adminMoney($pendingWithdrawals) : '—' ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-2">Settled trade result</div>
        <div class="text-2xl font-bold <?= $netTradingResult >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $statsLoaded ? adminMoney($netTradingResult) : '—' ?></div>
        <div class="text-xs text-gray-500 mt-2">Settled stakes minus payouts · <?= number_format($resolvedTrades) ?> resolved trades</div>
      </div>
    </div>

    <section class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5 mb-6">
      <h2 class="text-base font-semibold mb-4">Ledger reconciliation</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div class="flex justify-between gap-4 text-gray-400"><span>Users with accounts</span><span class="text-white font-semibold"><?= number_format($totalUsers) ?></span></div>
        <div class="flex justify-between gap-4 text-gray-400"><span>Funds in pending trades</span><span class="text-white font-semibold"><?= $statsLoaded ? adminMoney($pendingTradeStake) : '—' ?></span></div>
        <div class="flex justify-between gap-4 text-gray-400"><span>Estimated ledger residual</span><span class="text-white font-semibold"><?= $statsLoaded ? adminMoney($ledgerResidual) : '—' ?></span></div>
      </div>
      <p class="text-xs text-gray-500 mt-4 leading-relaxed">Ledger residual = completed deposits − paid withdrawals − current wallet balances − pending withdrawals − pending trade stakes. It is an estimate from site records, not the live MegaPay or bank balance. It excludes provider fees, chargebacks, and any money movements not recorded in Pandapesa. Settled trade result is stakes minus payouts and excludes operating costs.</p>
    </section>

    <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 text-sm text-blue-200">
      <strong>Actual cash held:</strong> Check MegaPay or your bank directly. This app does not currently fetch the provider account balance.
    </div>
  </main>

</body>
</html>