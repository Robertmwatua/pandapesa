<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/api/db.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, username, email, phone, balance, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$profileError = '';
$profileSuccess = '';
$passwordError = '';
$passwordSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');

        if (strlen($username) < 3) {
            $profileError = 'Username must be at least 3 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Invalid email address.';
        } elseif (!preg_match('/^[0-9]{9}$/', $phone)) {
            $profileError = 'Phone must be 9 digits after +254.';
        } else {
            $fullPhone = '254' . $phone;
            $stmt = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1');
            $stmt->execute([$username, $email, $fullPhone, $userId]);
            if ($stmt->fetch()) {
                $profileError = 'Username, email, or phone is already in use.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ?');
                $stmt->execute([$username, $email, $fullPhone, $userId]);
                $_SESSION['username'] = $username;
                $user['username'] = $username;
                $user['email'] = $email;
                $user['phone'] = $fullPhone;
                $profileSuccess = 'Profile updated.';
            }
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $passwordError = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $passwordError = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $passwordError = 'New passwords do not match.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $passwordSuccess = 'Password updated.';
        }
    }
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id = ? AND status = "completed"');
$stmt->execute([$userId]);
$totalDeposits = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE user_id = ? AND result IN ('win','loss')");
$stmt->execute([$userId]);
$totalTrades = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE user_id = ? AND result = 'win'");
$stmt->execute([$userId]);
$winTrades = (int)$stmt->fetchColumn();

$winRate = $totalTrades > 0 ? round(($winTrades / $totalTrades) * 100) : 0;
$initials = strtoupper(substr($user['username'], 0, 2));
$memberSince = date('M Y', strtotime($user['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile — pandapesa</title>
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
        <a href="/profile.php" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap border-green-500 text-green-400">
            Profile
        </a>
        <a href="/transactions.php" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap border-transparent text-gray-500 hover:text-gray-300">
            Transactions
        </a>
        <a href="/" class="px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 whitespace-nowrap sm:hidden">
            Trade
        </a>
    </div>
</header>

<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">

<!-- Hero card with account summary -->
<div class="bg-gradient-to-br from-gray-900 to-gray-900/50 border border-gray-800 rounded-2xl p-6 mb-6">
    <div class="flex items-center gap-4 mb-6">
        <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl flex items-center justify-center text-2xl font-bold text-white font-display">
            <?= htmlspecialchars($initials, ENT_QUOTES) ?>
        </div>
        <div>
            <h1 class="text-xl font-display font-bold text-white"><?= htmlspecialchars($user['username'], ENT_QUOTES) ?></h1>
            <p class="text-sm text-gray-500">Member since <?= htmlspecialchars($memberSince, ENT_QUOTES) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Balance</div>
            <div class="text-lg font-bold text-yellow-400 font-mono">KES <?= number_format((float)$user['balance'], 2) ?></div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Total Deposits</div>
            <div class="text-lg font-bold text-green-400 font-mono">KES <?= number_format($totalDeposits, 2) ?></div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Total Trades</div>
            <div class="text-lg font-bold text-white font-mono"><?= $totalTrades ?></div>
        </div>
        <div class="bg-gray-800/50 border border-gray-700/50 rounded-xl p-4">
            <div class="text-[10px] uppercase tracking-wider text-gray-500 font-semibold mb-1">Win Rate</div>
            <div class="text-lg font-bold text-white font-mono"><?= $winRate ?>%</div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Account Details -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-display font-semibold text-white">Account Details</h2>
            <p class="text-xs text-gray-500 mt-0.5">Update your account information</p>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="update_profile">

            <?php if ($profileError): ?>
                <div class="bg-red-500/10 border border-red-500/40 text-red-400 text-xs rounded-xl px-4 py-3"><?= htmlspecialchars($profileError, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($profileSuccess): ?>
                <div class="bg-green-500/10 border border-green-500/40 text-green-400 text-xs rounded-xl px-4 py-3"><?= htmlspecialchars($profileSuccess, ENT_QUOTES) ?></div>
            <?php endif; ?>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Username</label>
                <input type="text" name="username" required value="<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>"
                    class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-green-500">
            </div>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Email</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>"
                    class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-green-500">
            </div>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Phone (M-Pesa)</label>
                <div class="flex bg-gray-800/80 border border-gray-700 rounded-xl overflow-hidden focus-within:border-green-500 transition-colors">
                    <span class="bg-gray-700/50 px-3 flex items-center text-gray-400 text-sm font-mono font-semibold border-r border-gray-700">🇰🇪 +254</span>
                    <input type="tel" name="phone" required maxlength="9" inputmode="numeric"
                        value="<?= htmlspecialchars(preg_replace('/^254/', '', $user['phone']), ENT_QUOTES) ?>"
                        oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,9)"
                        class="flex-1 bg-transparent px-4 py-2.5 text-sm text-white focus:outline-none font-mono">
                </div>
            </div>

            <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-black font-bold py-2.5 rounded-xl text-sm transition-colors mt-2">
                Save Changes
            </button>
        </form>
    </div>

    <!-- Change Password -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-display font-semibold text-white">Change Password</h2>
            <p class="text-xs text-gray-500 mt-0.5">Keep your account secure with a strong password</p>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="change_password">

            <?php if ($passwordError): ?>
                <div class="bg-red-500/10 border border-red-500/40 text-red-400 text-xs rounded-xl px-4 py-3"><?= htmlspecialchars($passwordError, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($passwordSuccess): ?>
                <div class="bg-green-500/10 border border-green-500/40 text-green-400 text-xs rounded-xl px-4 py-3"><?= htmlspecialchars($passwordSuccess, ENT_QUOTES) ?></div>
            <?php endif; ?>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Current Password</label>
                <input type="password" name="current_password" required
                    class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-green-500">
            </div>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">New Password</label>
                <input type="password" name="new_password" required minlength="6"
                    class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-green-500">
                <p class="text-[11px] text-gray-600 mt-1">Min 6 characters</p>
            </div>

            <div>
                <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="6"
                    class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-green-500">
            </div>

            <button type="submit" class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-white font-bold py-2.5 rounded-xl text-sm transition-colors mt-2">
                Update Password
            </button>
        </form>
    </div>
</div>

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
