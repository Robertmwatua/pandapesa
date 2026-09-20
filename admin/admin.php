<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$adminUser = $_SESSION['admin_username'] ?? 'Admin';

// ── Pull live stats from the DB ──
$totalUsers = 0; $pendingWithdrawals = 0; $todayDeposits = 0;
try {
    require_once __DIR__ . '/../api/db.php';
    $totalUsers         = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pendingWithdrawals = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $todayDeposits      = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn();
} catch (PDOException $e) { /* tables may not exist yet — fine */ }
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
    <h1 class="text-2xl font-bold mb-6" style="font-family:'Space Grotesk'">Dashboard</h1>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Total Users</div>
        <div class="text-2xl font-bold text-blue-400"><?= number_format($totalUsers) ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Pending Withdrawals</div>
        <div class="text-2xl font-bold text-amber-400"><?= number_format($pendingWithdrawals) ?></div>
      </div>
      <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-5">
        <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Today's Deposits</div>
        <div class="text-2xl font-bold text-green-400">KES <?= number_format($todayDeposits, 2) ?></div>
      </div>
    </div>

    <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-6">
      <p class="text-gray-400 text-sm">Admin content goes here — users, transactions, settings, etc.</p>
    </div>
  </main>

</body>
</html>