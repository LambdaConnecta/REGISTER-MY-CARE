<?php
/**
 * Register My Care — Login Page
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$error  = '';
$next   = htmlspecialchars($_GET['next'] ?? '/dashboard.php', ENT_QUOTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $pdo  = getPDO();
        $user = attemptLogin($pdo, $email, $password);
        if ($user) {
            populateSession($user);
            auditLog($pdo, $user['organisation_id'], $user['id'], 'login', 'User signed in');
            redirect($next ?: '/dashboard.php');
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Sign In — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="min-h-screen bg-gradient-to-br from-teal-900 via-teal-800 to-cyan-900 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/10 backdrop-blur mb-4">
        <i class="fa fa-heart-pulse text-white text-2xl"></i>
      </div>
      <h1 class="text-2xl font-extrabold text-white"><?= APP_NAME ?></h1>
      <p class="text-teal-300 text-sm mt-1">Care Management Platform</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <h2 class="text-xl font-bold text-gray-800 mb-6">Sign in to your account</h2>

      <?php if ($error): ?>
        <div class="bg-red-50 border border-red-300 text-red-700 rounded-xl px-4 py-3 mb-5 text-sm font-medium flex items-center gap-2">
          <i class="fa fa-circle-exclamation"></i> <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="next" value="<?= $next ?>">

        <div class="mb-4">
          <label class="block text-sm font-semibold text-gray-700 mb-1.5">Email Address</label>
          <input type="email" name="email" required autocomplete="email"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100 transition"
                 placeholder="you@organisation.com"/>
        </div>

        <div class="mb-6">
          <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password</label>
          <div class="relative">
            <input type="password" name="password" id="pw" required autocomplete="current-password"
                   class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100 transition pr-10"
                   placeholder="••••••••"/>
            <button type="button" onclick="togglePw()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
              <i class="fa fa-eye text-sm" id="pw-icon"></i>
            </button>
          </div>
        </div>

        <button type="submit"
                class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl transition text-sm flex items-center justify-center gap-2">
          <i class="fa fa-right-to-bracket"></i> Sign In
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-gray-100 text-center">
        <a href="/auth/google_login.php"
           class="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-teal-600 font-medium transition">
          <i class="fab fa-google text-red-500"></i> Sign in with Google
        </a>
      </div>
    </div>

    <p class="text-center text-teal-400 text-xs mt-6">
      <?= APP_AUTHOR ?> &middot; v<?= APP_VERSION ?>
    </p>
  </div>

  <script>
    function togglePw() {
      const pw = document.getElementById('pw');
      const ic = document.getElementById('pw-icon');
      pw.type = pw.type === 'password' ? 'text' : 'password';
      ic.className = pw.type === 'password' ? 'fa fa-eye text-sm' : 'fa fa-eye-slash text-sm';
    }
  </script>
</body>
</html>
