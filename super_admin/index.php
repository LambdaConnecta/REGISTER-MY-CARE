<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['super_admin'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Safety constants
if (!defined('TIER_BASIC_PRICE'))     define('TIER_BASIC_PRICE',    100);
if (!defined('TIER_STANDARD_PRICE'))  define('TIER_STANDARD_PRICE', 200);
if (!defined('TIER_UNLIMITED_PRICE')) define('TIER_UNLIMITED_PRICE',400);
if (!defined('TIER_BASIC_MAX'))       define('TIER_BASIC_MAX',       10);
if (!defined('TIER_STANDARD_MAX'))    define('TIER_STANDARD_MAX',    20);
if (!defined('FREE_PLAN_SU_LIMIT'))   define('FREE_PLAN_SU_LIMIT',    2);

$pdo = getPDO();

// Self-heal: convert subscription_plan from ENUM to VARCHAR to support all tiers
try {
    $col = $pdo->query("SHOW COLUMNS FROM `organisations` LIKE 'subscription_plan'")->fetch();
    if ($col && strpos(strtolower($col['Type']), 'enum') !== false) {
        $pdo->exec("ALTER TABLE `organisations` MODIFY COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free'");
    }
} catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_notes` VARCHAR(500) DEFAULT NULL"); } catch(Exception $e){}

// Self-heal subscription columns
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_notes` VARCHAR(500) DEFAULT NULL"); } catch(Exception $e){}

// Tier definitions
$tierDefs = array(
    'free'      => array('label'=>'Free',      'price'=>0,                    'su_limit'=>FREE_PLAN_SU_LIMIT,'color'=>'gray',   'icon'=>'fa-leaf'),
    'basic'     => array('label'=>'Basic',     'price'=>TIER_BASIC_PRICE,     'su_limit'=>TIER_BASIC_MAX,    'color'=>'teal',   'icon'=>'fa-seedling'),
    'standard'  => array('label'=>'Standard',  'price'=>TIER_STANDARD_PRICE,  'su_limit'=>TIER_STANDARD_MAX, 'color'=>'blue',   'icon'=>'fa-star'),
    'unlimited' => array('label'=>'Unlimited', 'price'=>TIER_UNLIMITED_PRICE, 'su_limit'=>999,               'color'=>'purple', 'icon'=>'fa-infinity'),
);

$msg = ''; $msgType = 'success';

// ── Apply subscription tier ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_tier'])) {
    $oid      = (int)$_POST['org_id'];
    $tier     = $_POST['tier'] ?? 'free';
    $months   = max(1, (int)($_POST['months'] ?? 1));
    $notes    = trim($_POST['notes'] ?? '');
    if (!isset($tierDefs[$tier])) $tier = 'free';
    $tdata = $tierDefs[$tier];
    $suLimit  = $tdata['su_limit'];
    $expires  = ($tier !== 'free') ? date('Y-m-d H:i:s', strtotime("+{$months} months")) : null;
    try {
        $pdo->prepare("UPDATE organisations SET
            subscription_plan=?,
            subscription_tier=?,
            subscription_expires_at=?,
            subscription_su_limit=?,
            subscription_notes=?
            WHERE id=?")
            ->execute(array($tier, $tier, $expires, $suLimit, $notes ?: null, $oid));
        $orgName = '';
        $r = $pdo->prepare("SELECT name FROM organisations WHERE id=?");
        $r->execute(array($oid)); $r = $r->fetch();
        $orgName = $r['name'] ?? "ID $oid";
        if ($tier === 'free') {
            $msg = "Plan reset to Free for " . htmlspecialchars($orgName);
        } else {
            $msg = "Applied " . $tdata['label'] . " (£" . $tdata['price'] . "/mo, " . $months . " month" . ($months>1?'s':'') . ") to " . htmlspecialchars($orgName) . ". Expires: " . date('d M Y', strtotime($expires));
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage(); $msgType = 'error';
    }
    header('Location: index.php?msg=' . urlencode($msg) . '&mt=' . $msgType); exit;
}

// ── Confirm payment request ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $pid  = (int)$_POST['payment_id'];
    $oid  = (int)$_POST['org_id'];
    $tier = $_POST['payment_tier'] ?? 'basic';
    if (!isset($tierDefs[$tier])) $tier = 'basic';
    $tdata   = $tierDefs[$tier];
    $expires = date('Y-m-d H:i:s', strtotime('+1 month'));
    try {
        $pdo->prepare("UPDATE payment_requests SET status='Confirmed' WHERE id=?")->execute(array($pid));
        $pdo->prepare("UPDATE organisations SET
            subscription_plan=?,subscription_tier=?,
            subscription_expires_at=?,subscription_su_limit=?
            WHERE id=?")
            ->execute(array($tier,$tier,$expires,$tdata['su_limit'],$oid));
        $msg = "Payment confirmed — " . $tdata['label'] . " plan activated.";
    } catch (Exception $e) { $msg = "Error: " . $e->getMessage(); $msgType='error'; }
    header('Location: index.php?msg=' . urlencode($msg) . '&mt=' . $msgType); exit;
}

// ── Reject payment ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_payment'])) {
    $pid = (int)$_POST['payment_id'];
    try {
        $pdo->prepare("UPDATE payment_requests SET status='Failed' WHERE id=?")->execute(array($pid));
        $msg = "Payment request rejected."; $msgType='error';
    } catch (Exception $e) { $msg = "Error: " . $e->getMessage(); $msgType='error'; }
    header('Location: index.php?msg=' . urlencode($msg) . '&mt=' . $msgType); exit;
}

if (!$msg && isset($_GET['msg'])) { $msg = htmlspecialchars($_GET['msg']); $msgType = $_GET['mt'] ?? 'success'; }

// ── Load data ────────────────────────────────────────────────────────
$orgs = array();
try {
    $orgs = $pdo->query("SELECT o.*,
        (SELECT COUNT(*) FROM users WHERE organisation_id=o.id AND is_active=1) AS staff_count,
        (SELECT COUNT(*) FROM service_users WHERE organisation_id=o.id AND is_active=1) AS su_count
        FROM organisations o ORDER BY o.created_at DESC")->fetchAll();
} catch(Exception $e){}

$payments = array();
try {
    $payments = $pdo->query("SELECT pr.*,o.name AS org_name
        FROM payment_requests pr
        JOIN organisations o ON pr.organisation_id=o.id
        ORDER BY pr.created_at DESC LIMIT 50")->fetchAll();
} catch(Exception $e){}

$pending = array_filter($payments, function($p){ return $p['status']==='Pending'; });
$total   = count($orgs);
$byTier  = array('free'=>0,'basic'=>0,'standard'=>0,'unlimited'=>0);
foreach ($orgs as $o) {
    $t = $o['subscription_plan'] ?? 'free';
    if (!isset($byTier[$t])) $byTier[$t] = 0;
    $byTier[$t]++;
}
$totalSU = 0;
foreach ($orgs as $o) $totalSU += (int)$o['su_count'];

// For expand-tier modals: get selected org
$editOrg = null;
if (!empty($_GET['edit'])) {
    foreach ($orgs as $o) { if ($o['id'] == $_GET['edit']) { $editOrg = $o; break; } }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Super Admin — Register My Care</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{font-family:system-ui,sans-serif}
.tier-badge-free{background:#374151;color:#d1d5db;border:1px solid #4b5563}
.tier-badge-basic{background:rgba(20,184,166,.15);color:#2dd4bf;border:1px solid rgba(20,184,166,.35)}
.tier-badge-standard{background:rgba(59,130,246,.15);color:#60a5fa;border:1px solid rgba(59,130,246,.35)}
.tier-badge-unlimited{background:rgba(168,85,247,.15);color:#c084fc;border:1px solid rgba(168,85,247,.35)}
</style>
</head>
<body class="bg-gray-950 min-h-screen text-white">

<!-- Top bar -->
<div class="bg-gray-900 border-b border-gray-800 px-5 py-4 flex items-center justify-between sticky top-0 z-20">
  <div class="flex items-center gap-3">
    <div class="w-9 h-9 bg-red-600 rounded-xl flex items-center justify-center flex-shrink-0">
      <i class="fa fa-shield-halved text-white text-sm"></i>
    </div>
    <div>
      <h1 class="font-extrabold text-base">Super Admin</h1>
      <p class="text-gray-500 text-xs">Register My Care — Full Platform Access</p>
    </div>
  </div>
  <div class="flex items-center gap-3">
    <span class="text-gray-400 text-xs hidden sm:block">Signed in as <strong class="text-white"><?= htmlspecialchars($_SESSION['sa_user'] ?? 'admin') ?></strong></span>
    <a href="logout.php" class="text-xs bg-red-900/60 hover:bg-red-800 border border-red-700 text-red-300 px-3 py-1.5 rounded-xl font-bold transition">
      <i class="fa fa-arrow-right-from-bracket mr-1"></i>Logout
    </a>
  </div>
</div>

<div class="max-w-7xl mx-auto px-4 py-6">

<?php if ($msg): ?>
<div class="<?= $msgType==='success' ? 'bg-green-900/40 border-green-600 text-green-300' : 'bg-red-900/40 border-red-600 text-red-300' ?> border rounded-xl p-3 mb-5 text-sm">
  <i class="fa <?= $msgType==='success' ? 'fa-circle-check' : 'fa-circle-xmark' ?> mr-2"></i><?= $msg ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-blue-500 md:col-span-1">
    <div class="text-3xl font-extrabold text-blue-400"><?= $total ?></div>
    <div class="text-xs text-gray-400 mt-1">Companies</div>
  </div>
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-gray-500">
    <div class="text-2xl font-extrabold text-gray-300"><?= $byTier['free'] ?></div>
    <div class="text-xs text-gray-400 mt-1">Free</div>
  </div>
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-teal-500">
    <div class="text-2xl font-extrabold text-teal-400"><?= $byTier['basic'] ?></div>
    <div class="text-xs text-gray-400 mt-1">Basic</div>
  </div>
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-blue-400">
    <div class="text-2xl font-extrabold text-blue-400"><?= $byTier['standard'] ?></div>
    <div class="text-xs text-gray-400 mt-1">Standard</div>
  </div>
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-purple-500">
    <div class="text-2xl font-extrabold text-purple-400"><?= $byTier['unlimited'] ?></div>
    <div class="text-xs text-gray-400 mt-1">Unlimited</div>
  </div>
  <div class="bg-gray-800 rounded-2xl p-4 border-l-4 border-pink-500">
    <div class="text-2xl font-extrabold text-pink-400"><?= $totalSU ?></div>
    <div class="text-xs text-gray-400 mt-1">Service Users</div>
  </div>
</div>

<!-- Pending payments -->
<?php if (!empty($pending)): ?>
<div class="bg-amber-950/60 border border-amber-600 rounded-2xl mb-6 overflow-hidden">
  <div class="px-5 py-3 border-b border-amber-600/50 flex items-center gap-2">
    <i class="fa fa-bell text-amber-400"></i>
    <h2 class="font-extrabold text-amber-400">Pending Payment Requests (<?= count($pending) ?>)</h2>
  </div>
  <div class="divide-y divide-amber-900/50">
  <?php foreach ($pending as $p):
    // Try to guess tier from amount
    $amt = (float)($p['amount'] ?? 0);
    $guessedTier = 'basic';
    if ($amt >= TIER_UNLIMITED_PRICE) $guessedTier = 'unlimited';
    elseif ($amt >= TIER_STANDARD_PRICE) $guessedTier = 'standard';
  ?>
  <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3">
    <div>
      <div class="font-extrabold text-white"><?= htmlspecialchars(strtoupper($p['org_name'] ?? '')) ?></div>
      <div class="text-sm text-gray-300 mt-0.5">
        <span class="text-amber-300 font-bold">£<?= number_format((float)$p['amount'], 2) ?></span>
        &bull; Cardholder: <?= htmlspecialchars($p['cardholder_name'] ?? '—') ?>
        &bull; Ref: <code class="text-xs bg-gray-800 px-1.5 py-0.5 rounded"><?= htmlspecialchars($p['payment_ref'] ?? '') ?></code>
      </div>
      <div class="text-xs text-gray-500 mt-0.5"><?= date('d M Y H:i', strtotime($p['created_at'])) ?></div>
    </div>
    <form method="POST" class="flex flex-wrap items-center gap-2">
      <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
      <input type="hidden" name="org_id" value="<?= $p['organisation_id'] ?>">
      <select name="payment_tier" class="bg-gray-800 border border-gray-600 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none">
        <?php foreach ($tierDefs as $tk => $td): if ($tk==='free') continue; ?>
        <option value="<?= $tk ?>" <?= $tk===$guessedTier?'selected':'' ?>>
          <?= $td['label'] ?> — £<?= $td['price'] ?>/mo
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="confirm_payment" value="1"
              class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded-xl text-xs font-bold transition">
        <i class="fa fa-check mr-1"></i>Confirm & Activate
      </button>
      <button type="submit" name="reject_payment" value="1"
              onclick="return confirm('Reject this payment request?')"
              class="bg-gray-700 hover:bg-red-800 text-gray-300 hover:text-red-300 px-3 py-1.5 rounded-xl text-xs font-bold transition border border-gray-600 hover:border-red-700">
        <i class="fa fa-xmark mr-1"></i>Reject
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Companies table -->
<div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between">
    <h2 class="font-extrabold text-base">All Registered Companies</h2>
    <span class="text-xs text-gray-500"><?= $total ?> organisation<?= $total!==1?'s':'' ?></span>
  </div>
  <div class="overflow-x-auto">
  <table class="min-w-full divide-y divide-gray-800 text-sm">
    <thead class="bg-gray-800/60">
    <tr>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Company</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Staff / SU</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Current Plan</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Expires</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Apply Tier</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Details</th>
    </tr>
    </thead>
    <tbody class="divide-y divide-gray-800">
    <?php if (empty($orgs)): ?>
    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">No companies registered yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($orgs as $o):
        $plan    = $o['subscription_plan'] ?? 'free';
        $expires = $o['subscription_expires_at'] ?? null;
        $suLimit = (int)($o['subscription_su_limit'] ?? FREE_PLAN_SU_LIMIT);
        $suUsed  = (int)$o['su_count'];
        $isExpired = ($expires && strtotime($expires) < time() && $plan !== 'free');
        $daysLeft  = null;
        if ($expires && $plan !== 'free') {
            $daysLeft = (int)round((strtotime($expires) - time()) / 86400);
        }
        $tierBadge = 'tier-badge-' . (isset($tierDefs[$plan]) ? $plan : 'free');
        $tierLabel = isset($tierDefs[$plan]) ? $tierDefs[$plan]['label'] : ucfirst($plan);
    ?>
    <tr class="hover:bg-gray-800/40 transition">
      <td class="px-4 py-3">
        <div class="font-semibold text-white"><?= htmlspecialchars($o['name']) ?></div>
        <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($o['email'] ?? '') ?></div>
      </td>
      <td class="px-4 py-3">
        <div class="text-xs">
          <span class="text-teal-400 font-bold"><?= $o['staff_count'] ?></span>
          <span class="text-gray-600"> staff</span>
          &bull;
          <span class="text-purple-400 font-bold"><?= $suUsed ?></span>
          <span class="text-gray-600">/ <?= $suLimit >= 999 ? '∞' : $suLimit ?> SU</span>
        </div>
        <?php if ($suLimit < 999): ?>
        <div class="mt-1 h-1.5 bg-gray-700 rounded-full w-20 overflow-hidden">
          <div class="h-full rounded-full <?= $suUsed>=$suLimit ? 'bg-red-500' : 'bg-teal-500' ?>"
               style="width:<?= min(100, round($suUsed/max(1,$suLimit)*100)) ?>%"></div>
        </div>
        <?php endif; ?>
      </td>
      <td class="px-4 py-3">
        <span class="<?= $tierBadge ?> px-2 py-1 rounded-full text-xs font-bold">
          <?= $tierLabel ?>
        </span>
        <?php if ($isExpired): ?>
        <span class="ml-1 text-xs bg-red-900/50 text-red-400 border border-red-700 px-1.5 py-0.5 rounded-full">Expired</span>
        <?php elseif ($daysLeft !== null && $daysLeft <= 7): ?>
        <span class="ml-1 text-xs bg-amber-900/50 text-amber-400 border border-amber-700 px-1.5 py-0.5 rounded-full"><?= $daysLeft ?>d left</span>
        <?php endif; ?>
      </td>
      <td class="px-4 py-3 text-xs text-gray-400">
        <?php if ($expires && $plan !== 'free'): ?>
          <?= date('d M Y', strtotime($expires)) ?>
          <?php if ($daysLeft !== null): ?>
          <div class="<?= $daysLeft<0?'text-red-500':($daysLeft<=7?'text-amber-400':'text-gray-500') ?>"><?= $daysLeft<0 ? 'Expired '.abs($daysLeft).'d ago' : $daysLeft.' days left' ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-gray-600">—</span>
        <?php endif; ?>
      </td>
      <td class="px-4 py-3">
        <button onclick="openTierModal(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['name'])) ?>', '<?= $plan ?>')"
                class="bg-teal-700 hover:bg-teal-600 text-white px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
          <i class="fa fa-pen-to-square text-xs"></i>Set Tier
        </button>
      </td>
      <td class="px-4 py-3">
        <a href="company.php?id=<?= $o['id'] ?>" class="text-xs text-blue-400 hover:underline font-semibold">View →</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Payment history -->
<?php if (!empty($payments)): ?>
<div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-hidden mt-6">
  <div class="px-5 py-4 border-b border-gray-800">
    <h2 class="font-extrabold text-base">Recent Payment Requests</h2>
  </div>
  <div class="overflow-x-auto">
  <table class="min-w-full divide-y divide-gray-800 text-sm">
    <thead class="bg-gray-800/60"><tr>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Date</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Company</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Cardholder</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Amount</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Ref</th>
      <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
    </tr></thead>
    <tbody class="divide-y divide-gray-800">
    <?php foreach ($payments as $p):
        $sc = array('Pending'=>'bg-amber-900/40 text-amber-400 border-amber-700',
                    'Confirmed'=>'bg-green-900/40 text-green-400 border-green-700',
                    'Failed'=>'bg-red-900/40 text-red-400 border-red-700');
        $cls = isset($sc[$p['status']]) ? $sc[$p['status']] : 'bg-gray-700 text-gray-300 border-gray-600';
    ?>
    <tr class="hover:bg-gray-800/30">
      <td class="px-4 py-3 text-gray-400 text-xs"><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
      <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($p['org_name'] ?? '—') ?></td>
      <td class="px-4 py-3 text-gray-300"><?= htmlspecialchars($p['cardholder_name'] ?? '—') ?></td>
      <td class="px-4 py-3 font-bold text-amber-300">£<?= number_format((float)$p['amount'], 2) ?></td>
      <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars($p['payment_ref'] ?? '—') ?></td>
      <td class="px-4 py-3"><span class="<?= $cls ?> border px-2 py-0.5 rounded-full text-xs font-bold"><?= htmlspecialchars($p['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</div><!-- /max-w -->

<!-- ══ TIER MODAL ════════════════════════════════════════════════════ -->
<div id="tierModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(0,0,0,.75);backdrop-filter:blur(6px)">
  <div class="bg-gray-900 border border-gray-700 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">

    <!-- Header -->
    <div class="bg-gradient-to-r from-red-900 to-gray-900 px-6 py-5 flex items-center justify-between">
      <div>
        <h3 class="font-extrabold text-lg text-white"><i class="fa fa-crown text-amber-400 mr-2"></i>Apply Subscription Tier</h3>
        <p class="text-gray-400 text-xs mt-0.5" id="modalOrgName">—</p>
      </div>
      <button onclick="closeTierModal()" class="text-gray-500 hover:text-white text-2xl font-bold leading-none">&times;</button>
    </div>

    <!-- Tier selector -->
    <div class="px-6 pt-5 pb-2">
      <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-3">Select Plan</p>
      <div class="grid grid-cols-2 gap-3 mb-4">
        <?php foreach ($tierDefs as $tk => $td):
            $colors = array(
                'free'      => array('border'=>'border-gray-600','bg'=>'bg-gray-800','text'=>'text-gray-300','btn'=>'bg-gray-700 hover:bg-gray-600'),
                'basic'     => array('border'=>'border-teal-600','bg'=>'bg-teal-900/30','text'=>'text-teal-300','btn'=>'bg-teal-700 hover:bg-teal-600'),
                'standard'  => array('border'=>'border-blue-600','bg'=>'bg-blue-900/30','text'=>'text-blue-300','btn'=>'bg-blue-700 hover:bg-blue-600'),
                'unlimited' => array('border'=>'border-purple-600','bg'=>'bg-purple-900/30','text'=>'text-purple-300','btn'=>'bg-purple-700 hover:bg-purple-600'),
            );
            $c = $colors[$tk];
        ?>
        <label class="cursor-pointer block" onclick="selectTier('<?= $tk ?>')">
          <input type="radio" name="tier_select" value="<?= $tk ?>" class="hidden tier-radio" <?= $tk==='free'?'checked':'' ?>>
          <div class="tier-card <?= $c['border'] ?> <?= $c['bg'] ?> border-2 rounded-2xl p-3 text-center transition hover:opacity-90 selected-ring" id="tierCard_<?= $tk ?>">
            <i class="fa <?= $td['icon'] ?> <?= $c['text'] ?> text-xl block mb-1"></i>
            <div class="font-extrabold <?= $c['text'] ?> text-sm"><?= $td['label'] ?></div>
            <div class="text-gray-400 text-xs mt-0.5">
              <?= $td['price'] > 0 ? '£'.$td['price'].'/mo' : 'Free' ?>
            </div>
            <div class="text-gray-500 text-xs mt-0.5">
              <?php if ($td['su_limit'] >= 999): ?>Unlimited SUs<?php elseif ($tk==='basic'): ?>Up to <?= $td['su_limit'] ?> SUs<?php elseif ($tk==='free'): ?><?= $td['su_limit'] ?> SUs<?php else: ?>Up to <?= $td['su_limit'] ?> SUs<?php endif; ?>
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Form -->
    <form method="POST" class="px-6 pb-6">
      <input type="hidden" name="apply_tier" value="1">
      <input type="hidden" name="org_id" id="modalOrgId" value="">
      <input type="hidden" name="tier" id="modalTierInput" value="free">

      <div id="paidOptions" class="hidden">
        <div class="mb-3">
          <label class="block text-xs font-bold text-gray-400 mb-1.5">Duration (months)</label>
          <div class="flex gap-2">
            <?php foreach ([1,3,6,12] as $m): ?>
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="months" value="<?= $m ?>" <?= $m===1?'checked':'' ?> class="hidden month-radio">
              <div class="month-card border border-gray-600 rounded-xl text-center py-2 text-xs font-bold text-gray-300 hover:border-teal-500 hover:text-teal-300 transition" id="monthCard_<?= $m ?>"><?= $m ?>mo</div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mb-3 bg-gray-800 rounded-xl p-3 text-xs text-gray-400" id="expiryPreview">
          <i class="fa fa-calendar mr-1"></i>Expires: <strong class="text-white" id="expiryDate">—</strong>
        </div>
      </div>

      <div class="mb-4">
        <label class="block text-xs font-bold text-gray-400 mb-1.5">Notes (optional)</label>
        <input type="text" name="notes" placeholder="e.g. Manual activation, payment ref..."
               class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white focus:border-teal-500 focus:outline-none">
      </div>

      <div class="flex gap-3">
        <button type="submit"
                class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-extrabold py-3 rounded-xl text-sm transition">
          <i class="fa fa-check mr-1"></i>Apply Plan
        </button>
        <button type="button" onclick="closeTierModal()"
                class="bg-gray-700 hover:bg-gray-600 text-gray-300 font-bold px-5 rounded-xl text-sm transition">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<script>
var selectedTier = 'free';
var selectedMonths = 1;

function openTierModal(orgId, orgName, currentPlan) {
    document.getElementById('modalOrgId').value = orgId;
    document.getElementById('modalOrgName').textContent = orgName;
    selectTier(currentPlan || 'free');
    var m = document.getElementById('tierModal');
    m.style.display = 'flex';
}
function closeTierModal() {
    document.getElementById('tierModal').style.display = 'none';
}
function selectTier(tier) {
    selectedTier = tier;
    document.getElementById('modalTierInput').value = tier;
    var tiers = ['free','basic','standard','unlimited'];
    for (var i=0;i<tiers.length;i++) {
        var card = document.getElementById('tierCard_'+tiers[i]);
        if (card) {
            if (tiers[i] === tier) {
                card.style.opacity = '1';
                card.style.boxShadow = '0 0 0 3px rgba(255,255,255,0.25)';
            } else {
                card.style.opacity = '0.55';
                card.style.boxShadow = 'none';
            }
        }
    }
    var po = document.getElementById('paidOptions');
    if (tier === 'free') { po.classList.add('hidden'); }
    else { po.classList.remove('hidden'); updateExpiry(); }
}
function updateExpiry() {
    var mo = document.getElementsByName('months');
    for (var i=0;i<mo.length;i++) {
        if (mo[i].checked) { selectedMonths = parseInt(mo[i].value); break; }
    }
    var d = new Date();
    d.setMonth(d.getMonth() + selectedMonths);
    var opts = {day:'2-digit',month:'short',year:'numeric'};
    document.getElementById('expiryDate').textContent = d.toLocaleDateString('en-GB', opts);
    // Update month card highlights
    var cards = document.querySelectorAll('.month-card');
    cards.forEach(function(c){ c.style.borderColor=''; c.style.color=''; c.style.background=''; });
    var mcs = document.getElementsByName('months');
    for (var i=0;i<mcs.length;i++) {
        var mc = document.getElementById('monthCard_'+mcs[i].value);
        if (!mc) continue;
        if (mcs[i].checked) {
            mc.style.borderColor = '#14b8a6';
            mc.style.color = '#2dd4bf';
            mc.style.background = 'rgba(20,184,166,0.15)';
        }
    }
}
// Month radio clicks
document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'months') {
        var mcs = document.getElementsByName('months');
        for (var i=0;i<mcs.length;i++) { mcs[i].checked = (mcs[i].value === e.target.value); }
        updateExpiry();
    }
});
// Init
selectTier('free');
updateExpiry();

// Close on backdrop
document.getElementById('tierModal').addEventListener('click', function(e) {
    if (e.target === this) closeTierModal();
});
</script>
</body></html>
