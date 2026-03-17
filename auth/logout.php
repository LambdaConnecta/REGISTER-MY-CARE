<?php
/**
 * Register My Care — Sign Out
 * Created and designed by Dr. Andrew Ebhoma
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Log the logout action before destroying session
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../includes/db.php';
        $pdo = getPDO();
        $pdo->prepare(
            "INSERT INTO audit_log (organisation_id, user_id, action, details, ip_address, created_at)
             VALUES (?, ?, 'logout', 'User signed out', ?, NOW())"
        )->execute([
            $_SESSION['organisation_id'] ?? null,
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Don't block logout if audit fails
    }
}

// Destroy the session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();

// Redirect to login
header('Location: /index.php');
exit;
