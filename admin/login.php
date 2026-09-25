<?php
require_once __DIR__ . '/../api/session.php';
if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/admin.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        try {
           require_once __DIR__ . '/../api/db.php';
            $stmt = $pdo->prepare('SELECT id, username, password FROM admins WHERE email = ? OR username = ? LIMIT 1');
            $stmt->execute([$email, $email]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['admin_id']       = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                session_regenerate_id(true);
                header('Location: /admin/admin.php');
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — pandapesa</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>body{font-family:'Plus Jakarta Sans',sans-serif;background:#0a0f17;background-image:radial-gradient(ellipse at top,rgba(59,130,246,.08) 0%,transparent 50%);min-height:100vh;}</style>
</head>
<body class="flex items-center justify-center px-4">
<div class="w-full max-w-sm">
  <div class="text-center mb-8">
    <div class="inline-flex items-center justify-center w-14 h-14 bg-gradient-to-br from-blue-500/20 to-blue-600/20 border border-blue-500/30 rounded-2xl mb-3">
      <svg class="w-7 h-7 text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    </div>
    <h1 class="text-xl font-bold text-white" style="font-family:'Space Grotesk'">Admin Portal</h1>
    <p class="text-xs text-gray-500 mt-1 flex items-center justify-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>pandapesa · Restricted Access</p>
  </div>

  <div class="bg-gray-900/80 backdrop-blur border border-gray-800 rounded-2xl p-6 shadow-2xl">
    <?php if (!empty($error)): ?>
      <div class="bg-red-500/10 border border-red-500/40 text-red-400 text-xs rounded-xl px-4 py-3 mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Admin Email / Username</label>
        <input type="text" name="email" required placeholder="admin@..." autocomplete="username"
          class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500 transition-colors">
      </div>
      <div>
        <label class="block text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1.5">Password</label>
        <input type="password" name="password" required placeholder="••••••••" autocomplete="current-password"
          class="w-full bg-gray-800/80 border border-gray-700 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-600 focus:outline-none focus:border-blue-500 transition-colors">
      </div>
      <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-xl text-sm transition-colors mt-2 flex items-center justify-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
        Authenticate
      </button>
    </form>
  </div>

  <p class="text-center text-[11px] text-gray-700 mt-6"><a href="/login.php" class="hover:text-gray-500">← Back to user login</a></p>
</div>
</body>
</html>