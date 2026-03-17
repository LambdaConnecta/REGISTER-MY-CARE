<?php
/**
 * Register My Care — Google OAuth Login
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/../includes/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
