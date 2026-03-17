<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (!defined('TIER_BASIC_PRICE'))     define('TIER_BASIC_PRICE',    100);
if (!defined('TIER_STANDARD_PRICE'))  define('TIER_STANDARD_PRICE', 200);
if (!defined('TIER_UNLIMITED_PRICE')) define('TIER_UNLIMITED_PRICE',400);
if (!defined('TIER_BASIC_MAX'))       define('TIER_BASIC_MAX',       10);
if (!defined('TIER_STANDARD_MAX'))    define('TIER_STANDARD_MAX',    20);
if (!defined('FREE_PLAN_SU_LIMIT'))   define('FREE_PLAN_SU_LIMIT',    2);
requireLogin();
$pdo    = getPDO();
$orgId  = (int)$_SESSION['organisation_id'];
$isAdm  = isAdmin();
$pageTitle = 'Subscription & Plans';

// Self-heal columns
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2"); } catch(Exception $e){}
try { $col=$pdo->query("SHOW COLUMNS FROM `organisations` LIKE 'subscription_plan'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `organisations` MODIFY COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free'"); } } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `cardholder_name` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_ref` VARCHAR(100) NOT NULL,
  `tier_requested` VARCHAR(30) DEFAULT 'basic',
  `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `payment_requests` ADD COLUMN `tier_requested` VARCHAR(30) DEFAULT 'basic'"); } catch(Exception $e){}

try { $org=$pdo->prepare("SELECT * FROM organisations WHERE id=?"); $org->execute(array($orgId)); $org=$org->fetch(); } catch(Exception $e){ $org=array(); }

$plan    = $org['subscription_plan'] ?? 'free';
$expires = $org['subscription_expires_at'] ?? null;
$suLimit = (int)($org['subscription_su_limit'] ?? FREE_PLAN_SU_LIMIT);
$suUsed  = 0;
try { $q=$pdo->prepare("SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1"); $q->execute(array($orgId)); $suUsed=(int)$q->fetchColumn(); } catch(Exception $e){}

$daysLeft = null; $isExpired = false; $demoDaysLeft = null;
if ($plan === 'demo' && $expires) {
    $demoDaysLeft = max(0,(int)ceil((strtotime($expires)-time())/86400));
    $isExpired = ($demoDaysLeft <= 0);
} elseif ($expires && $plan !== 'free') {
    $daysLeft = (int)round((strtotime($expires)-time())/86400);
    $isExpired = ($daysLeft < 0);
}

$tiers = array(
    'demo'      => array('label'=>'7-Day Demo',  'price'=>0,                    'su_limit'=>5,               'color'=>'orange','icon'=>'fa-clock-rotate-left','desc'=>'All features free for 7 days'),
    'free'      => array('label'=>'Free',        'price'=>0,                    'su_limit'=>FREE_PLAN_SU_LIMIT,'color'=>'gray', 'icon'=>'fa-leaf',            'desc'=>'Up to 2 service users'),
    'basic'     => array('label'=>'Basic',       'price'=>TIER_BASIC_PRICE,     'su_limit'=>TIER_BASIC_MAX,  'color'=>'teal',  'icon'=>'fa-seedling',        'desc'=>'Up to 10 service users'),
    'standard'  => array('label'=>'Standard',    'price'=>TIER_STANDARD_PRICE,  'su_limit'=>TIER_STANDARD_MAX,'color'=>'blue', 'icon'=>'fa-star',            'desc'=>'Up to 20 service users'),
    'unlimited' => array('label'=>'Unlimited',   'price'=>TIER_UNLIMITED_PRICE, 'su_limit'=>999,             'color'=>'purple','icon'=>'fa-infinity',        'desc'=>'Unlimited service users'),
);

// ── POST: Activate demo ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['start_demo'])) {
    validateCSRF();
    if ($isAdm && ($plan==='free' || ($plan==='demo' && $isExpired))) {
        $exp = date('Y-m-d H:i:s', strtotime('+7 days'));
        try {
            $pdo->prepare("UPDATE organisations SET subscription_plan='demo',subscription_tier='demo',subscription_expires_at=?,subscription_su_limit=5 WHERE id=?")
                ->execute(array($exp,$orgId));
            addAuditLog($pdo,'START_DEMO','organisations',$orgId,'7-day demo activated');
            setFlash('success','Your 7-day demo is now active! All features unlocked until '.date('d M Y',strtotime($exp)).'.');
        } catch(Exception $e){ setFlash('error','Could not start demo: '.$e->getMessage()); }
    }
    header('Location: subscription.php'); exit;
}

// ── POST: Payment request ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_payment'])) {
    validateCSRF();
    $tier  = $_POST['plan_tier'] ?? 'basic';
    $tdata = isset($tiers[$tier]) ? $tiers[$tier] : null;
    $name  = trim($_POST['cardholder_name'] ?? '');
    if ($tdata && !in_array($tier,array('free','demo')) && $name) {
        $ref = 'RMC-'.strtoupper(substr(md5($orgId.time()),0,8));
        try {
            $pdo->prepare("INSERT INTO payment_requests (organisation_id,cardholder_name,amount,payment_ref,tier_requested,status) VALUES(?,?,?,?,?,'Pending')")
                ->execute(array($orgId,$name,(float)$tdata['price'],$ref,$tier));
            addAuditLog($pdo,'PAYMENT_REQUEST','payment_requests',$orgId,"Tier:$tier Ref:$ref");
            setFlash('success',"Payment request submitted! Ref: <strong>$ref</strong>. Transfer £{$tdata['price']} to Barclays A/c: 30288934 SC: 20-70-15. We will activate your {$tdata['label']} plan within 2 hours.");
        } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
    } else { setFlash('error','Please complete all fields.'); }
    header('Location: subscription.php'); exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-5">

<?php /* ── Alert banners ── */ ?>
<?php if ($plan==='demo' && $isExpired): ?>
<div class="bg-red-50 border-2 border-red-400 rounded-2xl p-4 flex items-center gap-3">
  <i class="fa fa-hourglass-end text-red-500 text-2xl flex-shrink-0"></i>
  <div><div class="font-extrabold text-red-800">Your 7-day demo has expired</div>
  <div class="text-sm text-red-700 mt-0.5">Upgrade to a paid plan to continue. Your data is safe.</div></div>
</div>
<?php elseif ($plan==='demo' && $demoDaysLeft!==null && $demoDaysLeft<=2): ?>
<div class="bg-amber-50 border-2 border-amber-400 rounded-2xl p-4 flex items-center gap-3">
  <i class="fa fa-clock text-amber-500 text-2xl flex-shrink-0"></i>
  <div><div class="font-extrabold text-amber-800">Demo expires in <?= $demoDaysLeft ?> day<?= $demoDaysLeft!==1?'s':''?>!</div>
  <div class="text-sm text-amber-700 mt-0.5">Upgrade below to keep all your features and data.</div></div>
</div>
<?php elseif (!$isExpired && $daysLeft!==null && $daysLeft<=14 && $plan!=='free'): ?>
<div class="bg-amber-50 border border-amber-300 rounded-2xl p-4 flex items-center gap-3">
  <i class="fa fa-triangle-exclamation text-amber-500 text-xl flex-shrink-0"></i>
  <div><div class="font-extrabold text-amber-800">Subscription expires in <?= $daysLeft ?> day<?= $daysLeft!==1?'s':''?></div>
  <div class="text-sm text-amber-700 mt-0.5">Renew now to avoid interruption.</div></div>
</div>
<?php elseif ($isExpired && !in_array($plan,array('free','demo'))): ?>
<div class="bg-red-50 border-2 border-red-400 rounded-2xl p-4 flex items-center gap-3">
  <i class="fa fa-circle-xmark text-red-500 text-2xl flex-shrink-0"></i>
  <div><div class="font-extrabold text-red-800">Subscription Expired</div>
  <div class="text-sm text-red-700 mt-0.5">Expired <?= date('d M Y',strtotime($expires)) ?>. Renew to restore full access.</div></div>
</div>
<?php endif; ?>

<?php /* ── Current plan card ── */ ?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-5 py-4 flex items-center justify-between flex-wrap gap-3">
    <div>
      <h2 class="text-white font-extrabold text-lg">Your Subscription</h2>
      <p class="text-teal-200 text-xs mt-0.5"><?= h($org['name'] ?? '') ?></p>
    </div>
    <span class="bg-white/20 text-white text-xs font-bold px-3 py-1.5 rounded-xl flex items-center gap-1.5">
      <i class="fa <?= $tiers[$plan]['icon'] ?? 'fa-leaf' ?>"></i>
      <?= isset($tiers[$plan]) ? $tiers[$plan]['label'] : ucfirst($plan) ?> Plan
      <?php if ($plan==='demo' && $demoDaysLeft!==null): ?>
        · <?= $demoDaysLeft ?> day<?= $demoDaysLeft!==1?'s':''?> left
      <?php elseif ($expires && $plan!=='free' && $plan!=='demo'): ?>
        · Expires <?= date('d M Y',strtotime($expires)) ?>
      <?php endif; ?>
    </span>
  </div>
  <div class="p-5">
    <div class="grid grid-cols-3 gap-4 mb-4">
      <div class="text-center bg-gray-50 rounded-xl p-3">
        <div class="text-2xl font-extrabold text-teal-600"><?= $suUsed ?></div>
        <div class="text-xs text-gray-500 mt-0.5">SUs Active</div>
      </div>
      <div class="text-center bg-gray-50 rounded-xl p-3">
        <div class="text-2xl font-extrabold text-gray-700"><?= $suLimit>=999?'∞':$suLimit ?></div>
        <div class="text-xs text-gray-500 mt-0.5">SU Limit</div>
      </div>
      <div class="text-center bg-gray-50 rounded-xl p-3">
        <div class="text-2xl font-extrabold <?= in_array($plan,array('free','demo'))?'text-gray-400':'text-green-600' ?>">
          £<?= isset($tiers[$plan])?$tiers[$plan]['price']:0 ?>
        </div>
        <div class="text-xs text-gray-500 mt-0.5"><?= in_array($plan,array('free','demo'))?'Free':'/month' ?></div>
      </div>
    </div>
    <?php if ($suLimit<999): ?>
    <div>
      <div class="flex justify-between text-xs mb-1">
        <span class="text-gray-500">Service User Usage</span>
        <span class="font-bold <?= $suUsed>=$suLimit?'text-red-600':'text-gray-700' ?>"><?= $suUsed ?>/<?= $suLimit ?></span>
      </div>
      <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all <?= $suUsed>=$suLimit?'bg-red-500':($suUsed/max(1,$suLimit)>0.8?'bg-amber-400':'bg-teal-500') ?>"
             style="width:<?= min(100,round($suUsed/max(1,$suLimit)*100)) ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($plan==='demo' && !$isExpired): ?>
    <div class="mt-3">
      <div class="flex justify-between text-xs mb-1"><span class="text-orange-600 font-bold">Demo Time Remaining</span><span class="font-bold text-orange-700"><?= $demoDaysLeft ?>/7 days</span></div>
      <div class="h-2.5 bg-orange-100 rounded-full overflow-hidden">
        <div class="h-full bg-orange-400 rounded-full" style="width:<?= round($demoDaysLeft/7*100) ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isAdm): ?>

<?php /* ── 7-Day Demo Tier ── */ ?>
<?php if ($plan==='free' || ($plan==='demo' && $isExpired)): ?>
<div class="bg-gradient-to-br from-orange-500 to-amber-500 rounded-3xl shadow-xl overflow-hidden">
  <div class="p-6">
    <div class="flex items-start gap-5 flex-wrap">
      <div class="flex-1 min-w-0">
        <div class="inline-flex items-center gap-2 bg-white/20 text-white text-xs font-bold px-3 py-1 rounded-full mb-3">
          <i class="fa fa-clock-rotate-left"></i>FREE TRIAL
        </div>
        <h3 class="text-white font-extrabold text-2xl mb-2">7-Day Demo — Try Everything Free</h3>
        <p class="text-orange-100 text-sm mb-4">
          Experience the full power of Register My Care with no payment required. All modules unlocked for 7 days. Your data is saved if you upgrade.
        </p>
        <div class="grid grid-cols-2 gap-2 mb-5">
          <?php foreach (array(
            array('fa-user-nurse','Up to 5 Service Users'),
            array('fa-pills','Medication & MAR Charts'),
            array('fa-route','Visit Scheduling & Rota'),
            array('fa-shield-halved','Staff Compliance Docs'),
            array('fa-triangle-exclamation','Incident Reporting'),
            array('fa-chart-bar','Full Report Exports'),
            array('fa-handshake','Handovers & Messages'),
            array('fa-file-invoice','Invoice Management'),
          ) as $f): ?>
          <div class="flex items-center gap-2 text-sm text-white">
            <i class="fa <?= $f[0] ?> text-orange-200 w-4 text-center flex-shrink-0"></i>
            <?= $f[1] ?>
          </div>
          <?php endforeach; ?>
        </div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="start_demo" value="1">
          <button type="submit" onclick="return confirm('Activate your free 7-day demo now?')"
                  class="inline-flex items-center gap-3 bg-white text-orange-600 hover:bg-orange-50 font-extrabold px-7 py-3.5 rounded-2xl text-base shadow-xl transition">
            <i class="fa fa-play text-orange-500 text-sm"></i>
            Activate Free Demo Now
          </button>
        </form>
        <p class="text-orange-200 text-xs mt-3">No credit card · No commitment · Cancel anytime</p>
      </div>
      <div class="flex-shrink-0 text-center">
        <div class="w-32 h-32 bg-white/15 rounded-3xl flex flex-col items-center justify-center border-2 border-white/30 mx-auto">
          <div class="text-6xl font-extrabold text-white leading-none">7</div>
          <div class="text-white font-extrabold text-sm uppercase tracking-widest mt-1">Days</div>
          <div class="text-orange-200 text-xs mt-0.5">100% Free</div>
        </div>
        <div class="text-orange-200 text-xs mt-3">Expires automatically<br>No hidden charges</div>
      </div>
    </div>
  </div>
  <div class="bg-black/10 px-6 py-3 flex items-center gap-3">
    <i class="fa fa-circle-info text-orange-200 text-sm"></i>
    <p class="text-orange-100 text-xs">Demo includes up to 5 service users. Upgrade at any time to keep your data and increase limits.</p>
  </div>
</div>
<?php else: ?>
<?php /* Active demo — show upgrade prompt with different styling */ ?>
<?php if ($plan==='demo' && !$isExpired): ?>
<div class="bg-orange-50 border-2 border-orange-300 rounded-2xl p-5">
  <div class="flex items-center gap-3 mb-2">
    <i class="fa fa-clock-rotate-left text-orange-500 text-2xl"></i>
    <div>
      <div class="font-extrabold text-orange-800 text-lg">Demo Active — <?= $demoDaysLeft ?> day<?= $demoDaysLeft!==1?'s':''?> remaining</div>
      <div class="text-sm text-orange-700">Upgrade before <?= date('d M Y',strtotime($expires)) ?> to keep all your data and remove limits.</div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php /* ── Plan Cards ── */ ?>
<div>
  <h3 class="font-extrabold text-gray-800 text-xl mb-1">Paid Plans</h3>
  <p class="text-gray-500 text-sm mb-4">All plans include full feature access. Activated within 2 hours of bank transfer receipt.</p>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
  <?php
  $planCards  = array('basic','standard','unlimited');
  $cardCfg = array(
    'basic'    =>array('bdr'=>'border-teal-300',  'bg'=>'bg-teal-50',  'hdr'=>'bg-teal-600',  'btn'=>'bg-teal-600 hover:bg-teal-700',  'chk'=>'text-teal-500','pop'=>false),
    'standard' =>array('bdr'=>'border-blue-400',  'bg'=>'bg-blue-50',  'hdr'=>'bg-blue-600',  'btn'=>'bg-blue-600 hover:bg-blue-700',  'chk'=>'text-blue-500','pop'=>true),
    'unlimited'=>array('bdr'=>'border-purple-300','bg'=>'bg-purple-50','hdr'=>'bg-purple-700','btn'=>'bg-purple-700 hover:bg-purple-800','chk'=>'text-purple-500','pop'=>false),
  );
  $feats = array(
    'basic'    =>array('Up to 10 service users','All clinical modules','Visit scheduling & rota','MAR & medication charts','Staff compliance docs','Email support'),
    'standard' =>array('Up to 20 service users','Everything in Basic','Priority support','Advanced analytics','Compliance tracking','Audit log exports'),
    'unlimited'=>array('Unlimited service users','Everything in Standard','Dedicated account manager','Custom onboarding','99.9% SLA guarantee','Bulk data exports'),
  );
  foreach ($planCards as $tk):
    $td  = $tiers[$tk];
    $cfg = $cardCfg[$tk];
    $cur = ($plan===$tk && !$isExpired);
  ?>
  <div class="border-2 <?= $cfg['bdr'] ?> <?= $cfg['bg'] ?> rounded-3xl overflow-hidden flex flex-col <?= $cfg['pop']?'ring-2 ring-offset-2 ring-blue-400 shadow-xl':'' ?>">
    <?php if ($cfg['pop']): ?><div class="<?= $cfg['hdr'] ?> text-white text-center text-xs font-extrabold py-2 tracking-widest uppercase">⭐ Most Popular</div><?php endif; ?>
    <?php if ($cur): ?><div class="bg-green-500 text-white text-center text-xs font-extrabold py-1.5">✓ Your Current Plan</div><?php endif; ?>
    <div class="p-5 flex-1 flex flex-col">
      <div class="mb-4">
        <div class="flex items-center gap-2 mb-1">
          <i class="fa <?= $td['icon'] ?> text-<?= $td['color'] ?>-600"></i>
          <span class="font-extrabold text-xl text-gray-800"><?= $td['label'] ?></span>
        </div>
        <div class="text-gray-800 text-3xl font-extrabold">£<?= $td['price'] ?><span class="text-sm font-normal text-gray-500">/month</span></div>
        <div class="text-xs text-gray-500 mt-0.5"><?= $td['desc'] ?></div>
      </div>
      <ul class="space-y-2 mb-5 flex-1">
        <?php foreach ($feats[$tk] as $f): ?>
        <li class="flex items-start gap-2 text-xs text-gray-700">
          <i class="fa fa-check <?= $cfg['chk'] ?> flex-shrink-0 mt-0.5"></i><?= $f ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <button onclick="openPay('<?= $tk ?>','<?= $td['label'] ?>',<?= $td['price'] ?>)"
              <?= $cur?'disabled':'' ?>
              class="<?= $cfg['btn'] ?> text-white font-extrabold py-3 rounded-2xl text-sm transition w-full disabled:opacity-50 disabled:cursor-default">
        <?= $cur?'Current Plan':'Select '.h($td['label']) ?>
      </button>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php /* ── Payment form ── */ ?>
<div id="payForm" class="hidden bg-white rounded-3xl shadow-2xl border-2 border-teal-300 overflow-hidden">
  <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-5 py-4 flex items-center justify-between">
    <div>
      <h3 class="text-white font-extrabold" id="pfTitle">Upgrade Plan</h3>
      <p class="text-teal-200 text-xs mt-0.5">Bank transfer · Activated within 2 hours</p>
    </div>
    <button onclick="closePay()" class="text-white/60 hover:text-white text-2xl font-bold">&times;</button>
  </div>
  <div class="p-5">
    <div class="bg-teal-50 border border-teal-200 rounded-2xl p-4 mb-5">
      <p class="text-xs font-extrabold text-teal-800 mb-3"><i class="fa fa-building-columns mr-1"></i>Make transfer to:</p>
      <div class="grid grid-cols-3 gap-3 text-center">
        <div class="bg-white rounded-xl p-3 border border-teal-100"><div class="text-xs text-teal-500 font-bold mb-1">Bank</div><div class="font-extrabold text-teal-800">Barclays</div></div>
        <div class="bg-white rounded-xl p-3 border border-teal-100"><div class="text-xs text-teal-500 font-bold mb-1">Account No.</div><div class="font-extrabold text-teal-800 font-mono">30288934</div></div>
        <div class="bg-white rounded-xl p-3 border border-teal-100"><div class="text-xs text-teal-500 font-bold mb-1">Sort Code</div><div class="font-extrabold text-teal-800 font-mono">20-70-15</div></div>
      </div>
      <p class="text-xs text-teal-700 text-center mt-2">Use your organisation name as the payment reference</p>
    </div>
    <div class="bg-gray-50 rounded-2xl p-4 mb-5 flex items-center justify-between">
      <div><div class="text-xs text-gray-400 font-semibold">Selected Plan</div><div class="font-extrabold text-gray-800 text-lg" id="pfPlan">—</div></div>
      <div class="text-right"><div class="text-xs text-gray-400 font-semibold">Monthly Amount</div><div class="font-extrabold text-3xl text-teal-700">£<span id="pfPrice">0</span></div></div>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="submit_payment" value="1">
      <input type="hidden" name="plan_tier" id="pfTier" value="">
      <div class="mb-4">
        <label class="block text-xs font-bold text-gray-600 mb-1">Account Holder Name <span class="text-red-400">*</span></label>
        <input type="text" name="cardholder_name" required placeholder="Full name on the bank account"
               class="w-full border rounded-xl px-3 py-3 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-extrabold py-3.5 rounded-2xl text-sm transition">
        <i class="fa fa-paper-plane mr-1.5"></i>Confirm Payment Request
      </button>
      <p class="text-xs text-gray-400 text-center mt-3">
        Plan activated within 2 hours of receipt. Questions? <a href="mailto:info@registermycare.org" class="text-teal-600 underline">info@registermycare.org</a>
      </p>
    </form>
  </div>
</div>

<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-700">
  <i class="fa fa-circle-info text-blue-400 mr-1"></i>
  All subscriptions are activated manually after bank transfer is received. Contact <strong>info@registermycare.org</strong> for urgent requests or to request an invoice.
</div>

<?php endif; // isAdmin ?>
</div>

<script>
function openPay(tier,label,price){
  document.getElementById('pfTier').value=tier;
  document.getElementById('pfPlan').textContent=label+' Plan';
  document.getElementById('pfPrice').textContent=price;
  document.getElementById('pfTitle').textContent='Upgrade to '+label;
  var el=document.getElementById('payForm');
  el.classList.remove('hidden');
  setTimeout(function(){el.scrollIntoView({behavior:'smooth',block:'center'});},60);
}
function closePay(){ document.getElementById('payForm').classList.add('hidden'); }
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
