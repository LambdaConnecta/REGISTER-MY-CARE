<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['super_admin'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
if (!defined('TIER_BASIC_PRICE'))     define('TIER_BASIC_PRICE',    100);
if (!defined('TIER_STANDARD_PRICE'))  define('TIER_STANDARD_PRICE', 200);
if (!defined('TIER_UNLIMITED_PRICE')) define('TIER_UNLIMITED_PRICE',400);
if (!defined('TIER_BASIC_MAX'))       define('TIER_BASIC_MAX',       10);
if (!defined('TIER_STANDARD_MAX'))    define('TIER_STANDARD_MAX',    20);
if (!defined('FREE_PLAN_SU_LIMIT'))   define('FREE_PLAN_SU_LIMIT',    2);
$pdo   = getPDO();
$orgId = (int)($_GET['id'] ?? 0);
if (!$orgId) { header('Location: index.php'); exit; }
$org = null;
try { $s=$pdo->prepare("SELECT * FROM organisations WHERE id=?"); $s->execute(array($orgId)); $org=$s->fetch(); } catch(Exception $e){}
if (!$org) { header('Location: index.php'); exit; }
$tab = $_GET['tab'] ?? 'staff';
$staff=$sus=$visits=$payments=array();
try { $s=$pdo->prepare("SELECT * FROM users WHERE organisation_id=? AND is_active=1 ORDER BY last_name"); $s->execute(array($orgId)); $staff=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT * FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name"); $s->execute(array($orgId)); $sus=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT v.*,CONCAT(su.first_name,' ',su.last_name) AS su_name,CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS carer_name FROM visits v JOIN service_users su ON v.service_user_id=su.id LEFT JOIN users u ON v.carer_id=u.id WHERE v.organisation_id=? ORDER BY v.visit_date DESC LIMIT 50"); $s->execute(array($orgId)); $visits=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->query("SELECT pr.*,o.name AS org_name FROM payment_requests pr JOIN organisations o ON pr.organisation_id=o.id WHERE pr.organisation_id={$orgId} ORDER BY pr.created_at DESC LIMIT 20"); $payments=$s->fetchAll(); } catch(Exception $e){}
$plan = $org['subscription_plan'] ?? 'free';
$exp  = $org['subscription_expires_at'] ?? null;
$tierLabels = array('free'=>'Free','basic'=>'Basic','standard'=>'Standard','unlimited'=>'Unlimited');
$tierLabel  = isset($tierLabels[$plan]) ? $tierLabels[$plan] : ucfirst($plan);
$n = htmlspecialchars($org['name'] ?? 'Unknown');
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $n ?> — Super Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>body{font-family:system-ui,sans-serif}</style>
</head>
<body class="bg-gray-950 min-h-screen text-white">
<div class="bg-gray-900 border-b border-gray-800 px-5 py-4 flex items-center gap-4">
  <a href="index.php" class="text-gray-400 hover:text-white w-8 h-8 rounded-lg bg-gray-800 flex items-center justify-center flex-shrink-0">
    <i class="fa fa-arrow-left text-sm"></i>
  </a>
  <div class="flex-1 min-w-0">
    <h1 class="font-extrabold text-lg truncate"><?= $n ?></h1>
    <div class="flex items-center gap-2 text-xs mt-0.5">
      <span class="text-gray-400">Plan:</span>
      <span class="<?= $plan==='unlimited'?'text-purple-400':($plan==='standard'?'text-blue-400':($plan==='basic'?'text-teal-400':'text-gray-400')) ?> font-bold"><?= $tierLabel ?></span>
      <?php if ($exp && $plan !== 'free'): ?>
      <span class="text-gray-600">&bull;</span>
      <span class="text-gray-500">Expires: <?= date('d M Y', strtotime($exp)) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <a href="index.php?edit=<?= $orgId ?>" class="bg-teal-700 hover:bg-teal-600 text-white px-3 py-1.5 rounded-xl text-xs font-bold transition">
    <i class="fa fa-pen mr-1"></i>Change Plan
  </a>
</div>
<div class="max-w-5xl mx-auto px-4 py-6">
  <!-- Org info card -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-gray-800 rounded-2xl p-4 text-center"><div class="text-2xl font-extrabold text-teal-400"><?= count($staff) ?></div><div class="text-xs text-gray-400">Staff</div></div>
    <div class="bg-gray-800 rounded-2xl p-4 text-center"><div class="text-2xl font-extrabold text-purple-400"><?= count($sus) ?></div><div class="text-xs text-gray-400">Service Users</div></div>
    <div class="bg-gray-800 rounded-2xl p-4 text-center"><div class="text-2xl font-extrabold text-blue-400"><?= count($visits) ?></div><div class="text-xs text-gray-400">Recent Visits</div></div>
    <div class="bg-gray-800 rounded-2xl p-4 text-center"><div class="text-2xl font-extrabold text-amber-400"><?= count($payments) ?></div><div class="text-xs text-gray-400">Payments</div></div>
  </div>
  <!-- Tabs -->
  <div class="flex gap-1 mb-5 border-b border-gray-800 overflow-x-auto">
    <?php foreach(array('staff'=>'Staff','service_users'=>'Service Users','visits'=>'Visits','payments'=>'Payments') as $t=>$l): ?>
    <a href="?id=<?= $orgId ?>&tab=<?= $t ?>" class="px-4 py-2.5 text-sm font-semibold whitespace-nowrap border-b-2 transition
       <?= $tab===$t?'border-teal-500 text-teal-400':'border-transparent text-gray-500 hover:text-gray-300' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <!-- Tab content -->
  <?php if ($tab==='staff'): ?>
  <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-800 text-sm">
      <thead class="bg-gray-800"><tr>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Name</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Email</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Role</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Job</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-800">
      <?php foreach($staff as $s): ?>
      <tr class="hover:bg-gray-800/40">
        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?></td>
        <td class="px-4 py-3 text-gray-400"><?= htmlspecialchars($s['email'] ?? '—') ?></td>
        <td class="px-4 py-3"><span class="<?= ($s['role']==='Admin')?'bg-amber-900/40 text-amber-400':'bg-gray-700 text-gray-300' ?> px-2 py-0.5 rounded-full text-xs font-bold"><?= htmlspecialchars($s['role'] ?? 'Staff') ?></span></td>
        <td class="px-4 py-3 text-teal-400 text-xs"><?= htmlspecialchars($s['job_category'] ?? '—') ?></td>
      </tr>
      <?php endforeach; if(empty($staff)): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No staff.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($tab==='service_users'): ?>
  <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-800 text-sm">
      <thead class="bg-gray-800"><tr>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Name</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Date of Birth</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">NHS No.</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Funding</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-800">
      <?php foreach($sus as $su): ?>
      <tr class="hover:bg-gray-800/40">
        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($su['last_name'].', '.$su['first_name']) ?></td>
        <td class="px-4 py-3 text-gray-400 text-xs"><?= $su['date_of_birth'] ? date('d M Y', strtotime($su['date_of_birth'])) : '—' ?></td>
        <td class="px-4 py-3 text-gray-400"><?= htmlspecialchars($su['nhs_number'] ?? '—') ?></td>
        <td class="px-4 py-3 text-teal-400 text-xs"><?= htmlspecialchars($su['funding_type'] ?? '—') ?></td>
      </tr>
      <?php endforeach; if(empty($sus)): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No service users.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($tab==='visits'): ?>
  <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-800 text-sm">
      <thead class="bg-gray-800"><tr>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Date</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Service User</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Carer</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-800">
      <?php foreach($visits as $v):
        $vc = array('Completed'=>'text-green-400','Missed'=>'text-red-400','In Progress'=>'text-blue-400');
        $cls = isset($vc[$v['status']]) ? $vc[$v['status']] : 'text-amber-400';
      ?>
      <tr class="hover:bg-gray-800/40">
        <td class="px-4 py-3 text-gray-300"><?= date('d/m/Y', strtotime($v['visit_date'])) ?></td>
        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($v['su_name'] ?? '—') ?></td>
        <td class="px-4 py-3 text-gray-400"><?= htmlspecialchars(trim($v['carer_name'] ?? '—') ?: '—') ?></td>
        <td class="px-4 py-3 font-semibold <?= $cls ?>"><?= htmlspecialchars($v['status'] ?? 'Scheduled') ?></td>
      </tr>
      <?php endforeach; if(empty($visits)): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No visits.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="bg-gray-900 rounded-2xl border border-gray-800 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-800 text-sm">
      <thead class="bg-gray-800"><tr>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Date</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Cardholder</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Amount</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Ref</th>
        <th class="px-4 py-3 text-left text-xs font-bold text-gray-400 uppercase">Status</th>
      </tr></thead>
      <tbody class="divide-y divide-gray-800">
      <?php foreach($payments as $p):
        $sc=array('Pending'=>'text-amber-400','Confirmed'=>'text-green-400','Failed'=>'text-red-400');
        $cls=isset($sc[$p['status']])?$sc[$p['status']]:'text-gray-400';
      ?>
      <tr class="hover:bg-gray-800/40">
        <td class="px-4 py-3 text-gray-400 text-xs"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($p['cardholder_name'] ?? '—') ?></td>
        <td class="px-4 py-3 text-amber-300 font-bold">£<?= number_format((float)$p['amount'],2) ?></td>
        <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= htmlspecialchars($p['payment_ref'] ?? '—') ?></td>
        <td class="px-4 py-3 font-semibold <?= $cls ?>"><?= htmlspecialchars($p['status']) ?></td>
      </tr>
      <?php endforeach; if(empty($payments)): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No payments.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body></html>
