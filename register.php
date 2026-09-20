<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /trade.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($username) < 3)                       $errors[] = 'Username must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Invalid email address.';
    if (!preg_match('/^[0-9]{9}$/', $phone))         $errors[] = 'Phone must be 9 digits after +254.';
    if (strlen($password) < 6)                       $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)                      $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            require_once __DIR__ . '/api/db.php';

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? OR phone = ? LIMIT 1');
            $stmt->execute([$username, $email, '254' . $phone]);
            if ($stmt->fetch()) {
                $errors[] = 'Username, email, or phone is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, email, phone, password, balance, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
                $stmt->execute([$username, $email, '254' . $phone, $hash]);
                header('Location: /login.php?registered=1');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error. Please try again.';
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
  <title>Sign Up — pandapesa</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#060a10;}
    .logo-font{font-family:'Space Grotesk',sans-serif;}
    .input-field{width:100%;background:rgba(17,24,39,.8);border:1px solid #374151;border-radius:.75rem;padding:.75rem 1rem;font-size:.875rem;color:#fff;transition:border-color .2s;}
    .input-field::placeholder{color:#4b5563;}
    .input-field:focus{outline:none;border-color:#22c55e;}
    .input-field-wrap{display:flex;background:rgba(17,24,39,.8);border:1px solid #374151;border-radius:.75rem;overflow:hidden;transition:border-color .2s;}
    .input-field-wrap:focus-within{border-color:#22c55e;}
    .prefix-box{background:rgba(55,65,81,.5);padding:0 .75rem;display:flex;align-items:center;color:#9ca3af;font-size:.875rem;font-family:monospace;font-weight:600;border-right:1px solid #374151;}
  </style>
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-8">
<div class="w-full max-w-sm">
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-2.5 mb-3">
      <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center font-bold text-white logo-font">P</div>
      <span class="text-xl font-bold text-white logo-font">pandapesa</span>
    </div>
    <p class="text-sm text-gray-500">Create your trading account</p>
  </div>

  <div class="bg-gray-900/60 border border-gray-800 rounded-2xl p-6">
    <?php if (!empty($errors)): ?>
      <div class="bg-red-500/10 border border-red-500/40 text-red-400 text-xs rounded-xl px-4 py-3 mb-4 space-y-1">
        <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Username</label>
        <input type="text" name="username" required placeholder="e.g. trader254" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" class="input-field">
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Email</label>
        <input type="email" name="email" required placeholder="you@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="input-field">
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Phone (M-Pesa)</label>
        <div class="input-field-wrap">
          <span class="prefix-box">🇰🇪 +254</span>
          <input type="tel" name="phone" required placeholder="712345678" maxlength="9" pattern="[0-9]{9}" inputmode="numeric"
            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,9)"
            class="flex-1 bg-transparent px-4 py-3 text-sm text-white placeholder-gray-600 focus:outline-none font-mono">
        </div>
        <p class="text-[11px] text-gray-600 mt-1">Enter 9 digits after 254 (e.g. 712345678)</p>
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Password</label>
        <input type="password" name="password" required placeholder="Min 6 characters" class="input-field">
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Confirm Password</label>
        <input type="password" name="confirm_password" required placeholder="••••••••" class="input-field">
      </div>
      <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-black font-bold py-3 rounded-xl text-sm transition-colors mt-2">Create Account</button>
    </form>
  </div>

  <p class="text-center text-sm text-gray-500 mt-5">Already have an account? <a href="/login.php" class="text-green-400 font-medium hover:underline">Sign In</a></p>
</div>
</body>
</html>