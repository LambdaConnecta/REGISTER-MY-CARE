<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Safety fallbacks if constants not yet defined
if (!defined('TIER_BASIC_PRICE'))     define('TIER_BASIC_PRICE',    100);
if (!defined('TIER_STANDARD_PRICE'))  define('TIER_STANDARD_PRICE', 200);
if (!defined('TIER_UNLIMITED_PRICE')) define('TIER_UNLIMITED_PRICE',400);
if (!defined('TIER_BASIC_MAX'))       define('TIER_BASIC_MAX',       10);
if (!defined('TIER_STANDARD_MAX'))    define('TIER_STANDARD_MAX',    20);
if (!defined('FREE_PLAN_SU_LIMIT'))   define('FREE_PLAN_SU_LIMIT',    2);


function getOrgSubscription($orgId) {
    $pdo  = getPDO();
    $plan = 'free'; $cnt = 0; $expires = null; $suLimit = FREE_PLAN_SU_LIMIT;
    try {
        $r = $pdo->prepare("SELECT subscription_plan, subscription_expires_at, subscription_su_limit FROM organisations WHERE id=?");
        $r->execute([$orgId]); $r = $r->fetch();
        $plan    = $r['subscription_plan']       ?? 'free';
        $expires = $r['subscription_expires_at'] ?? null;
        $suLimit = (int)($r['subscription_su_limit'] ?? FREE_PLAN_SU_LIMIT);
    } catch (Exception $e) {}
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1");
        $c->execute([$orgId]); $cnt = (int)$c->fetchColumn();
    } catch (Exception $e) {}

    $isFree    = ($plan === 'free');
    $isPaid    = !$isFree;
    $isExpired = false;
    $daysLeft  = null;
    if ($isPaid && $expires) {
        $expDt    = new DateTime($expires);
        $now      = new DateTime();
        $diff     = $now->diff($expDt);
        $daysLeft = (int)$diff->format('%r%a');
        $isExpired = ($daysLeft < 0);
    }
    $effectiveLimit = ($isPaid && !$isExpired) ? $suLimit : FREE_PLAN_SU_LIMIT;
    $atLimit = ($cnt >= $effectiveLimit);

    $tierData = array(
        'basic'     => array('price' => TIER_BASIC_PRICE,     'label' => 'Basic',     'su_desc' => '3-10 service users'),
        'standard'  => array('price' => TIER_STANDARD_PRICE,  'label' => 'Standard',  'su_desc' => '11-20 service users'),
        'unlimited' => array('price' => TIER_UNLIMITED_PRICE, 'label' => 'Unlimited', 'su_desc' => 'Unlimited service users'),
    );
    $tier = isset($tierData[$plan]) ? $tierData[$plan] : array('price' => 0, 'label' => 'Free', 'su_desc' => 'Up to 2 service users');

    return array(
        'plan'           => $plan,
        'is_free'        => $isFree,
        'is_premium'     => $isPaid && !$isExpired,
        'is_paid'        => $isPaid,
        'is_expired'     => $isExpired,
        'expires_at'     => $expires,
        'days_left'      => $daysLeft,
        'active_su_count'=> $cnt,
        'su_limit'       => $effectiveLimit,
        'limit'          => $effectiveLimit,
        'at_limit'       => $atLimit,
        'price'          => $tier['price'],
        'tier_label'     => $tier['label'],
        'tier_su_desc'   => $tier['su_desc'],
    );
}

function enforceSULimit($orgId) {
    $sub = getOrgSubscription($orgId);
    if ($sub['at_limit']) {
        setFlash('error', 'Service user limit reached. Please upgrade your plan.');
        header('Location: /pages/subscription.php'); exit;
    }
}
