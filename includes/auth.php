<?php
/**
 * Register My Care — Authentication & Authorisation
 * Created and designed by Dr. Andrew Ebhoma
 */

function requireLogin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: /index.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /dashboard.php');
        exit;
    }
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'Admin';
}

function isLoggedIn(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return !empty($_SESSION['user_id']);
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentOrgId(): int {
    return (int)($_SESSION['organisation_id'] ?? 0);
}

/**
 * Verify a plain-text or hashed password.
 * Supports legacy MD5 passwords and modern bcrypt.
 */
function verifyPassword(string $plain, string $hash): bool {
    if (password_verify($plain, $hash)) return true;
    // Legacy MD5 fallback (upgrade on next login)
    if (strlen($hash) === 32 && md5($plain) === $hash) return true;
    return false;
}

/**
 * Attempt login — returns user row on success, false on failure.
 */
function attemptLogin(PDO $pdo, string $email, string $password): array|false {
    $stmt = $pdo->prepare(
        "SELECT u.*, o.name AS org_name
         FROM users u
         JOIN organisations o ON u.organisation_id = o.id
         WHERE u.email = ? AND u.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (!verifyPassword($password, $user['password'])) return false;

    // Upgrade legacy hash silently
    if (strlen($user['password']) === 32) {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return $user;
}

/**
 * Populate $_SESSION after successful login.
 */
function populateSession(array $user): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['organisation_id'] = $user['organisation_id'];
    $_SESSION['role']            = $user['role'];
    $_SESSION['first_name']      = $user['first_name'];
    $_SESSION['last_name']       = $user['last_name'];
    $_SESSION['email']           = $user['email'];
    $_SESSION['org_name']        = $user['org_name'] ?? '';
}

/**
 * Check CSRF token (call on POST requests).
 */
function csrfCheck(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

/**
 * Generate & store a CSRF token for the current session.
 */
function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input field.
 */
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrfToken()) . '">';
}
