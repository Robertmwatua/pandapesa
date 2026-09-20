<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/api/db.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT username, balance FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

$type     = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id = ? AND status = "completed"');
$stmt->execute([$userId]);
$totalDeposits = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id = ? AND status = "completed"');
$stmt->execute([$userId]);
$totalWithdrawals = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(payout),0) FROM trades WHERE user_id = ? AND result = 'win'");
$stmt->execute([$userId]);
$tradeWinnings = (float)$stmt->fetchColumn();

$sql = "
    SELECT * FROM (
        SELECT 'deposit' AS type, amount, reference, status, created_at
        FROM deposits WHERE user_id = ?
        UNION ALL
        SELECT 'withdrawal' AS type, -amount AS amount, NULL AS reference, status, created_at
        FROM withdrawals WHERE user_id = ?
        UNION ALL
        SELECT IF(result = 'win', 'trade_win', 'trade_loss') AS type,
               IF(result = 'win', payout, -stake) AS amount,
               CONCAT('Trade #', id) AS reference,
               'completed' AS status,
               created_at
        FROM trades WHERE user_id = ? AND result IN ('win','loss')
    ) t
    WHERE 1=1
";
$params = [$userId, $userId, $userId];

if ($type !== '') {
    $sql .= ' AND type = ?';
    $params[] = $type;
}
if ($dateFrom !== '') {
    $sql .= ' AND created_at >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND created_at <= ?';
    $params[] = $dateTo;
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'deposit'    => 'Deposit',
    'withdrawal' => 'Withdrawal',
    'trade_win'  => 'Trade Win',
    'trade_loss' => 'Trade Loss',
    'bonus'      => 'Bonus',
];
$statusColors = [
    'completed' => 'bg-green-500/10 text-green-400 border-green-500/30',
    'pending'   => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
    'failed'    => 'bg-red-500/10 text-red-400 border-red-500/30',
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Transactions — pandapesa</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #060a10; }
.font-display { font-family: 'Space Grotesk', sans-serif; }
.font-mono { font-family: 'JetBrains Mono', monospace; }
::-webkit-scrollbar { width:3px; }
::-webkit-scrollbar-track { background:#0f1520; }
::-webkit-scrollbar-thumb { background:#253550; border-radius:2px; }
</style>
</head>
<body class="min-h-screen text-gray-100">

<!-- HEADER -->
<header class="bg-gray-900/80 backdrop-blur-lg border-b border-gray-800 sticky top-0 z-40">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
        <a href="/" class="flex items-center gap-2.5 flex-shrink-0">
                        <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-lg flex items-center justify-center font-bold text-white text-sm font-display">P</div>
                        <span class="font-display font-bold text-white text-base hidden sm:inline">pandapesa</span>
        </a>

        <div class="flex items-center gap-2 text-sm">
            <div class="bg-gray-800/80 border border-gray-700 rounded-full px-3 py-1 flex items-center gap-2">
                <div class="w-4 h-4 rounded-full bg-yellow-500 flex items-center justify-center text-[8px] font-bold text-black">K</div>
                <span class="font-mono text-yellow-400 text-xs font-semibold"><?= number_format((float)$user['balance'], 2) ?></span>
            </div>
            <a href="/" class="text-xs text-gray-500 hover:text-green-400 border border-gray-700 rounded-lg px-3 py-1.5 transition-colors hidden sm:inline-flex">Trade →</a>
            <a href="/logout.php" class="text-xs text-gray-500 hover:text-red-400 px-2 py-1.5">Logout</a>
        </div>
    </div>

    <!-- Tabs -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 flex gap-1 overflow-x-auto">
        <a href="/profile.php" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap border-transparent text-gray-500 hover:text-gray-300">
            Profile
        </a>
        <a href="/transactions.php" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap border-green-500 text-green-400">
            Transactions
        </a>
        <a href="/" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 whitespace-nowrap sm:hidden">
            Trade
        </a>
    </div>
</header>

<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/themes/dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
<style>
.flatpickr-calendar{background:#0f1520!important;border:1px solid #1e2d44!important;border-radius:12px!important;box-shadow:0 20px 60px rgba(0,0,0,.7)!important;font-family:'Plus Jakarta Sans',sans-serif!important}
.flatpickr-calendar.hasTime .flatpickr-time{border-top:1px solid #1e2d44!important}
.flatpickr-months .flatpickr-month,.flatpickr-weekdays,span.flatpickr-weekday{background:#0a0e17!important;color:#7b8ca8!important}
.flatpickr-months .flatpickr-prev-month,.flatpickr-months .flatpickr-next-month{fill:#7b8ca8!important}
.flatpickr-months .flatpickr-prev-month:hover svg,.flatpickr-months .flatpickr-next-month:hover svg{fill:#00c853!important}
.flatpickr-day{color:#e8edf5!important;border-radius:6px!important}
.flatpickr-day:hover,.flatpickr-day:focus{background:#1e2d44!important;border-color:#1e2d44!important}
.flatpickr-day.selected,.flatpickr-day.startRange,.flatpickr-day.endRange{background:#00c853!important;border-color:#00c853!important;color:#000!important;font-weight:700!important}
.flatpickr-day.today{border-color:#253550!important;color:#00c853!important}
.flatpickr-day.flatpickr-disabled{color:#2a3a55!important}
.flatpickr-time{background:#0f1520!important;border-radius:0 0 0 0!important}
.flatpickr-time input,.flatpickr-time .flatpickr-am-pm{color:#e8edf5!important;background:#0f1520!important;font-family:'JetBrains Mono',monospace!important;font-size:16px!important;font-weight:600!important}
.flatpickr-time input:hover,.flatpickr-time .flatpickr-am-pm:hover,.flatpickr-time input:focus,.flatpickr-time .flatpickr-am-pm:focus{background:#1e2d44!important}
.flatpickr-time .numInputWrapper span.arrowUp::after{border-bottom-color:#7b8ca8!important}
.flatpickr-time .numInputWrapper span.arrowDown::after{border-top-color:#7b8ca8!important}
.flatpickr-time .flatpickr-time-separator{color:#4a5d7a!important}
.flatpickr-current-month .flatpickr-monthDropdown-months{background:#0f1520!important;color:#e8edf5!important}
.numInputWrapper:hover{background:#1e2d44!important}
.flatpickr-current-month input.cur-year{color:#e8edf5!important}
.dt-input-wrap{position:relative;display:flex;align-items:center}
.dt-input-wrap svg{position:absolute;left:10px;color:#4a5d7a;pointer-events:none}
.dt-trigger{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:8px 10px 8px 32px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#e5e7eb;cursor:pointer;min-width:168px;transition:border-color .2s;white-space:nowrap}
.dt-trigger:focus,.dt-trigger.active{outline:none;border-color:#00c853;color:#fff}
.dt-trigger::placeholder{color:#4b5563}
.dt-arrow{color:#374151;font-size:16px;padding:0 2px;user-select:none;padding-top:18px}
.filter-clear{display:inline-flex;align-items:center;gap:4px;padding:8px 12px;border-radius:8px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#f87171;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap;height:36px}
.filter-clear:hover{background:rgba(239,68,68,.18);color:#fca5a5}
.filter-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:4px;background:#1e2d44;border:1px solid #253550;color:#7b8ca8;font-size:11px;font-weight:500}
.filter-chip strong{color:#e8edf5;font-weight:600}
.shortcut-bar{display:flex;flex-wrap:wrap;gap:5px;padding:8px 10px 10px;border-top:1px solid #1e2d44;background:#0a0e17;border-radius:0 0 12px 12px}
.shortcut-btn{padding:3px 9px;border-radius:4px;border:1px solid #253550;background:#0f1520;color:#7b8ca8;font-size:10px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;letter-spacing:.3px}
.shortcut-btn:hover{border-color:#00c853;color:#00c853}
</style>

<div class="mb-6">
  <h1 class="text-2xl font-display font-bold text-white mb-1">My Transactions</h1>
  <p class="text-sm text-gray-500">Your complete transaction history</p>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Total Deposits</div><div class="text-base font-bold text-green-400 font-mono">KES <?= number_format($totalDeposits, 2) ?></div></div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Total Withdrawals</div><div class="text-base font-bold text-red-400 font-mono">KES <?= number_format($totalWithdrawals, 2) ?></div></div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Trade Winnings</div><div class="text-base font-bold text-emerald-400 font-mono">KES <?= number_format($tradeWinnings, 2) ?></div></div>
  <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"><div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Current Balance</div><div class="text-base font-bold text-yellow-400 font-mono">KES <?= number_format((float)$user['balance'], 2) ?></div></div>
</div>

<div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-4">
  <form method="GET" id="filterForm">
    <div class="flex flex-wrap items-end gap-3">

      <div class="flex flex-col gap-1">
        <label class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">Type</label>
        <select name="type" onchange="document.getElementById('filterForm').submit()"
          class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:outline-none focus:border-green-500 h-[36px]">
          <option value="" <?= $type === '' ? 'selected' : '' ?>>All Types</option>
          <option value="deposit"    <?= $type === 'deposit' ? 'selected' : '' ?>>Deposits</option>
          <option value="withdrawal" <?= $type === 'withdrawal' ? 'selected' : '' ?>>Withdrawals</option>
          <option value="trade_win"  <?= $type === 'trade_win' ? 'selected' : '' ?>>Trade Wins</option>
          <option value="trade_loss" <?= $type === 'trade_loss' ? 'selected' : '' ?>>Trade Losses</option>
          <option value="bonus"      <?= $type === 'bonus' ? 'selected' : '' ?>>Bonuses</option>
        </select>
      </div>

      <div class="flex flex-col gap-1">
        <label class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">From</label>
        <div class="dt-input-wrap">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <input type="text" id="dtFrom" name="date_from" class="dt-trigger" placeholder="Pick date &amp; time" value="<?= h($dateFrom) ?>" readonly>
        </div>
      </div>

      <div class="dt-arrow">→</div>

      <div class="flex flex-col gap-1">
        <label class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold">To</label>
        <div class="dt-input-wrap">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <input type="text" id="dtTo" name="date_to" class="dt-trigger" placeholder="Pick date &amp; time" value="<?= h($dateTo) ?>" readonly>
        </div>
      </div>

      <button type="submit" class="h-[36px] px-5 rounded-lg bg-green-500 hover:bg-green-400 text-black text-sm font-bold transition-colors mt-auto">Apply</button>

      <?php if ($type !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
      <a href="/transactions.php" class="filter-clear">Clear</a>
      <?php endif; ?>

      <div class="ml-auto text-xs text-gray-500 font-mono self-end pb-1"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></div>
    </div>
  </form>

  </div>

<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
  <div class="overflow-x-auto hidden md:block">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-xs text-gray-500 uppercase border-b border-gray-800">
          <th class="px-5 py-3 text-left font-semibold">Type</th>
          <th class="px-5 py-3 text-right font-semibold">Amount</th>
          <th class="px-5 py-3 text-left font-semibold">Reference</th>
          <th class="px-5 py-3 text-center font-semibold">Status</th>
          <th class="px-5 py-3 text-right font-semibold">Date &amp; Time</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="px-5 py-12 text-center text-gray-600"><div class="mb-2 text-3xl opacity-30">📭</div><div>No transactions yet</div></td></tr>
        <?php else: foreach ($rows as $r): $amt = (float)$r['amount']; ?>
        <tr class="border-b border-gray-800/60 last:border-0 hover:bg-gray-800/30">
          <td class="px-5 py-3"><?= h($typeLabels[$r['type']] ?? $r['type']) ?></td>
          <td class="px-5 py-3 text-right font-mono <?= $amt >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $amt >= 0 ? '+' : '' ?><?= number_format($amt, 2) ?></td>
          <td class="px-5 py-3 text-gray-400"><?= h($r['reference'] ?? '—') ?></td>
          <td class="px-5 py-3 text-center"><span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold border <?= $statusColors[$r['status']] ?? 'bg-gray-500/10 text-gray-400 border-gray-500/30' ?>"><?= h(ucfirst($r['status'])) ?></span></td>
          <td class="px-5 py-3 text-right text-gray-500 font-mono text-xs"><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="md:hidden divide-y divide-gray-800/60">
    <?php if (empty($rows)): ?>
    <div class="px-5 py-12 text-center text-gray-600"><div class="mb-2 text-3xl opacity-30">📭</div><div>No transactions yet</div></div>
    <?php else: foreach ($rows as $r): $amt = (float)$r['amount']; ?>
    <div class="px-5 py-3 flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-medium"><?= h($typeLabels[$r['type']] ?? $r['type']) ?></div>
        <div class="text-[11px] text-gray-500 font-mono"><?= h(date('Y-m-d H:i', strtotime($r['created_at']))) ?></div>
      </div>
      <div class="text-right">
        <div class="font-mono text-sm <?= $amt >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $amt >= 0 ? '+' : '' ?><?= number_format($amt, 2) ?></div>
        <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold border <?= $statusColors[$r['status']] ?? 'bg-gray-500/10 text-gray-400 border-gray-500/30' ?>"><?= h(ucfirst($r['status'])) ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  </div>

<script>
(function(){
  var sharedCfg = {
    enableTime: true, time_24hr: true,
    dateFormat: 'Y-m-d H:i', minuteIncrement: 1,
    allowInput: false, disableMobile: true,
  };

  var fpFrom = flatpickr('#dtFrom', Object.assign({}, sharedCfg, {
    defaultDate: <?= $dateFrom !== '' ? "'" . h($dateFrom) . "'" : 'null' ?>,
    onChange: function(sel){ if(sel[0]) fpTo.set('minDate', sel[0]); }
  }));

  var fpTo = flatpickr('#dtTo', Object.assign({}, sharedCfg, {
    defaultDate: <?= $dateTo !== '' ? "'" . h($dateTo) . "'" : 'null' ?>,
    onChange: function(sel){ if(sel[0]) fpFrom.set('maxDate', sel[0]); }
  }));

  // Quick-range shortcuts
  var RANGES = [
    { l:'Last 30 min', f:function(){ var t=new Date(),s=new Date(t-30*60e3); fpFrom.setDate(s); fpTo.setDate(t); } },
    { l:'Last 1 hr',   f:function(){ var t=new Date(),s=new Date(t-3600e3); fpFrom.setDate(s); fpTo.setDate(t); } },
    { l:'Today',       f:function(){ var d=new Date(); fpFrom.setDate(new Date(d.getFullYear(),d.getMonth(),d.getDate(),0,0)); fpTo.setDate(new Date(d.getFullYear(),d.getMonth(),d.getDate(),23,59)); } },
    { l:'Yesterday',   f:function(){ var d=new Date(Date.now()-864e5); fpFrom.setDate(new Date(d.getFullYear(),d.getMonth(),d.getDate(),0,0)); fpTo.setDate(new Date(d.getFullYear(),d.getMonth(),d.getDate(),23,59)); } },
    { l:'Last 7 days', f:function(){ fpFrom.setDate(new Date(Date.now()-7*864e5)); fpTo.setDate(new Date()); } },
    { l:'This month',  f:function(){ var d=new Date(); fpFrom.setDate(new Date(d.getFullYear(),d.getMonth(),1,0,0)); fpTo.setDate(new Date()); } },
  ];

  function appendShortcuts(fp){
    var bar = document.createElement('div');
    bar.className = 'shortcut-bar';
    RANGES.forEach(function(r){
      var b = document.createElement('button');
      b.type='button'; b.className='shortcut-btn'; b.textContent=r.l;
      b.addEventListener('click', r.f);
      bar.appendChild(b);
    });
    fp.calendarContainer.appendChild(bar);
  }

  appendShortcuts(fpFrom);
  appendShortcuts(fpTo);
})();
</script>

</main>

<footer class="border-t border-gray-800/60 mt-12 py-5">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center space-y-2">
        <p class="text-[11px] text-gray-500 leading-relaxed">
            pandapesa is licensed and regulated in the Commonwealth of The Bahamas under licence number <span class="font-mono text-gray-400">BHA-0023-1873201</span>. The company has met all regulatory and compliance standards required to operate across all its services.
        </p>
        <p class="text-xs text-gray-600">
            &copy; 2026 pandapesa. All rights reserved.
        </p>
    </div>
</footer>

</body>
</html>
