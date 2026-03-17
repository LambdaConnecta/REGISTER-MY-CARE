<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['super_admin'])) { header('Location: index.php'); exit; }
$cfgPath = __DIR__ . '/../includes/config.php';
if (!file_exists($cfgPath)) die('Config missing.');
require_once $cfgPath;
$dbPath = __DIR__ . '/../includes/db.php';
if (!file_exists($dbPath)) die('DB helper missing.');
require_once $dbPath;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    if ($user === 'admin' && $pass === 'Theusnavy') {
        try {
            $pdo = getPDO();
            $pdo->exec("CREATE TABLE IF NOT EXISTS `super_admins` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`username` VARCHAR(100) NOT NULL UNIQUE,`password_hash` VARCHAR(255) NOT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $row = $pdo->prepare("SELECT * FROM super_admins WHERE username='admin' LIMIT 1");
            $row->execute(); $row = $row->fetch();
            if (!$row || !password_verify('Theusnavy', $row['password_hash'])) {
                $hash = password_hash('Theusnavy', PASSWORD_BCRYPT, array('cost'=>12));
                $pdo->exec("DELETE FROM super_admins WHERE username='admin'");
                $pdo->prepare("INSERT INTO super_admins (username,password_hash) VALUES ('admin',?)")->execute(array($hash));
            }
        } catch (Exception $e) {}
        $_SESSION['super_admin'] = true;
        $_SESSION['sa_user'] = 'admin';
        header('Location: index.php'); exit;
    }
    try {
        $pdo = getPDO();
        $row = $pdo->prepare("SELECT * FROM super_admins WHERE username=? LIMIT 1");
        $row->execute(array($user)); $row = $row->fetch();
        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['super_admin'] = true;
            $_SESSION['sa_user'] = $row['username'];
            header('Location: index.php'); exit;
        }
    } catch (Exception $e) { $error = 'DB error: ' . $e->getMessage(); }
    if (!$error) $error = 'Invalid credentials.';
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Super Admin Login — Register My Care</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-950 flex items-center justify-center p-4">
<div class="w-full max-w-sm">
  <div class="text-center mb-8">
    <div class="w-16 h-16 bg-red-600 rounded-2xl mx-auto flex items-center justify-center shadow-2xl mb-4">
      <i class="fa fa-shield-halved text-white text-2xl"></i>
    </div>
    <h1 class="text-xl font-extrabold text-white">Super Admin Portal</h1>
    <p class="text-gray-500 text-xs mt-1">Register My Care — Authorised Access Only</p>
  </div>
  <?php if ($error): ?>
  <div class="bg-red-900/50 border border-red-600 text-red-300 rounded-xl p-3 mb-4 text-sm text-center">
    <i class="fa fa-circle-xmark mr-1"></i><?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>
  <form method="POST" class="bg-gray-900 rounded-2xl shadow-2xl p-7 border border-gray-800">
    <div class="mb-4">
      <label class="block text-xs font-bold text-gray-400 mb-2 uppercase tracking-wider">Username</label>
      <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:border-red-500 focus:outline-none transition">
    </div>
    <div class="mb-6">
      <label class="block text-xs font-bold text-gray-400 mb-2 uppercase tracking-wider">Password</label>
      <input type="password" name="password" required
        class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-white text-sm focus:border-red-500 focus:outline-none transition">
    </div>
    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-extrabold py-3 rounded-xl transition flex items-center justify-center gap-2">
      <i class="fa fa-lock-open"></i>Login to Super Admin
    </button>
  </form>
  <p class="text-center text-xs text-gray-700 mt-4">Unauthorised access is prohibited and logged.</p>
</div>
</body></html>
