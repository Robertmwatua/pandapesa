<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /trade.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Enter your email/phone and password.';
    } else {
        try {
            require_once __DIR__ . '/api/db.php';

            $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE email = ? OR phone = ? OR username = ? LIMIT 1');
            $stmt->execute([$login, $login, $login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                session_regenerate_id(true);
                header('Location: /trade.php');
                exit;
            } else {
                $error = 'Invalid credentials.';
            }
        } catch (PDOException $e) {
            $error = 'Server error. Try again.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Login — pandapesa</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#060a10;}
    .logo-font{font-family:'Space Grotesk',sans-serif;}
    .input-field{width:100%;background:rgba(17,24,39,.8);border:1px solid #374151;border-radius:.75rem;padding:.75rem 1rem;font-size:.875rem;color:#fff;transition:border-color .2s;}
    .input-field::placeholder{color:#4b5563;}
    .input-field:focus{outline:none;border-color:#22c55e;}
  </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4">
<div class="w-full max-w-sm">
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-2.5 mb-3">
      <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center font-bold text-white logo-font">P</div>
      <span class="text-xl font-bold text-white logo-font">pandapesa</span>
    </div>
    <p class="text-sm text-gray-500">Sign in to your trading account</p>
  </div>

  <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-6">
    <?php if (!empty($error)): ?>
      <div class="bg-red-500/10 border border-red-500/40 text-red-400 text-xs rounded-xl px-4 py-3 mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['registered'])): ?>
      <div class="bg-green-500/10 border border-green-500/40 text-green-400 text-xs rounded-xl px-4 py-3 mb-4">Account created. Sign in below.</div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Email or Phone</label>
        <input type="text" name="email" required placeholder="e.g. 254712345678" autocomplete="username" class="input-field">
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Password</label>
        <input type="password" name="password" required placeholder="••••••••" autocomplete="current-password" class="input-field">
      </div>
      <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-black font-bold py-3 rounded-xl text-sm transition-colors mt-2">Sign In</button>
    </form>
  </div>

  <p class="text-center text-sm text-gray-500 mt-5">Don't have an account? <a href="/register.php" class="text-green-400 font-medium hover:underline">Sign Up</a></p>
  <p class="text-center text-[11px] text-gray-700 mt-6"><a href="/admin/login.php" class="hover:text-gray-500">Admin portal →</a></p>
</div>
</body>
</html>