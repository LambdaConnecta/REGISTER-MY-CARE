<?php
/**
 * Register My Care — Helper Functions
 * Created and designed by Dr. Andrew Ebhoma
 */

/** HTML-escape shorthand */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Flash message helpers */
function setFlash(string $type, string $msg): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Redirect shorthand */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Format a date string nicely */
function fmtDate(?string $d, string $fmt = 'd M Y'): string {
    if (!$d) return '—';
    try { return (new DateTime($d))->format($fmt); } catch (Exception $e) { return $d; }
}

/** Format a datetime */
function fmtDatetime(?string $d): string {
    return fmtDate($d, 'd M Y H:i');
}

/** Return initials from full name */
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $i = strtoupper(substr($parts[0] ?? '', 0, 1));
    if (count($parts) > 1) $i .= strtoupper(substr(end($parts), 0, 1));
    return $i ?: '?';
}

/** Pagination helper — returns offset */
function paginateOffset(int $page, int $perPage): int {
    return max(0, ($page - 1) * $perPage);
}

/** Safe file upload — returns ['ok'=>true,'path'=>...'name'=>...] or ['ok'=>false,'error'=>...] */
function safeUpload(array $file, string $destDir, array $allowedMimes = [], int $maxBytes = 10485760): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error code ' . ($file['error'] ?? '?')];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'File too large (max ' . round($maxBytes / 1048576, 1) . ' MB)'];
    }
    $mime = mime_content_type($file['tmp_name']);
    if ($allowedMimes && !in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'File type not allowed: ' . $mime];
    }
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $dest     = rtrim($destDir, '/') . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to move uploaded file'];
    }
    return ['ok' => true, 'path' => $dest, 'name' => $safeName, 'original' => $file['name'], 'size' => $file['size'], 'mime' => $mime];
}

/** Write to audit log */
function auditLog(PDO $pdo, int $orgId, ?int $userId, string $action, string $details = ''): void {
    try {
        $pdo->prepare(
            "INSERT INTO audit_log (organisation_id, user_id, action, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$orgId, $userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

/** Format bytes to human-readable size */
function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/** Get unread message count for a user */
function getUnreadMessageCount(PDO $pdo, int $orgId, int $userId): int {
    try {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_messages
             WHERE organisation_id = ?
               AND (to_id = ? OR (is_broadcast = 1 AND from_id != ?))
               AND is_read = 0"
        );
        $q->execute([$orgId, $userId, $userId]);
        return (int)$q->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/** Build a Bootstrap/Tailwind-style alert from flash data */
function renderFlash(array $flash): string {
    if (empty($flash)) return '';
    $type = $flash['type'] ?? 'info';
    $msg  = h($flash['msg'] ?? '');
    $colours = [
        'success' => 'bg-green-50 border-green-400 text-green-800',
        'error'   => 'bg-red-50   border-red-400   text-red-800',
        'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
        'info'    => 'bg-blue-50  border-blue-400  text-blue-800',
    ];
    $cls = $colours[$type] ?? $colours['info'];
    return "<div class=\"border rounded-xl px-4 py-3 mb-4 text-sm font-medium {$cls}\">{$msg}</div>";
}
