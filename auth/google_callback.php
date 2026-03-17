<?php
/**
 * Register My Care — Google OAuth Callback
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if (!$code || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    setFlash('error', 'OAuth authentication failed. Please try again.');
    header('Location: /index.php'); exit;
}
unset($_SESSION['oauth_state']);

$tokenResp = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => ['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded',
               'content'=>http_build_query(['code'=>$code,'client_id'=>GOOGLE_CLIENT_ID,
               'client_secret'=>GOOGLE_CLIENT_SECRET,'redirect_uri'=>GOOGLE_REDIRECT_URI,'grant_type'=>'authorization_code'])]
]));
if (!$tokenResp) { setFlash('error','Could not connect to Google.'); header('Location: /index.php'); exit; }
$token = json_decode($tokenResp, true);
if (empty($token['access_token'])) { setFlash('error','Google login failed.'); header('Location: /index.php'); exit; }

$uiResp = @file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, stream_context_create([
    'http'=>['header'=>'Authorization: Bearer '.$token['access_token']]
]));
if (!$uiResp) { setFlash('error','Could not fetch Google profile.'); header('Location: /index.php'); exit; }
$gUser = json_decode($uiResp, true);
$email = strtolower(trim($gUser['email'] ?? ''));
if (!$email) { setFlash('error','No email from Google.'); header('Location: /index.php'); exit; }

$pdo  = getPDO();
$stmt = $pdo->prepare("SELECT u.*,o.name AS org_name FROM users u JOIN organisations o ON u.organisation_id=o.id WHERE u.email=? AND u.is_active=1 LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) { setFlash('error','No account found for '.$email.'. Contact your administrator.'); header('Location: /index.php'); exit; }

populateSession($user);
auditLog($pdo, $user['organisation_id'], $user['id'], 'login', 'Google OAuth sign-in');
header('Location: /dashboard.php'); exit;
