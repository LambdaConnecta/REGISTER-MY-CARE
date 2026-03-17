<?php
/**
 * Register My Care — Configuration Template
 * Created and designed by Dr. Andrew Ebhoma
 *
 * INSTRUCTIONS:
 *   1. Copy this file:  cp includes/config.example.php includes/config.php
 *   2. Fill in all values below
 *   3. NEVER commit includes/config.php to version control
 */

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'regmycar_rmc');          // Your database name
define('DB_USER', 'regmycar_rmcuser');      // Your DB username
define('DB_PASS', 'YOUR_DB_PASSWORD');      // Your DB password  ← CHANGE THIS

// ── Application ───────────────────────────────────────────────────────────────
define('APP_NAME',    'Register My Care');
define('APP_VERSION', '2.0');
define('APP_AUTHOR',  'Created and designed by Dr. Andrew Ebhoma');
define('APP_URL',     'https://registermycare.org');  // ← Your domain
define('APP_EMAIL',   'info@registermycare.org');     // ← Your email

// ── Google OAuth (optional — for "Sign in with Google") ──────────────────────
// Create credentials at: https://console.developers.google.com/
define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://registermycare.org/auth/google_callback.php');

// ── Subscription Tiers ────────────────────────────────────────────────────────
define('FREE_PLAN_SU_LIMIT',    2);     // Max service users on free plan
define('TIER_BASIC_PRICE',    100);     // £/month
define('TIER_STANDARD_PRICE', 200);
define('TIER_UNLIMITED_PRICE',400);
define('TIER_BASIC_MAX',       10);     // Max SUs on Basic
define('TIER_STANDARD_MAX',    20);

// ── Legacy alias ──────────────────────────────────────────────────────────────
define('PREMIUM_PRICE_GBP',   100);

// ── Visit GPS radius (metres) ─────────────────────────────────────────────────
define('VISIT_LOCATION_RADIUS', 500);
