<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/subscription.php';
requireLogin();

$pdo      = getPDO();
$orgId    = (int)($_SESSION['organisation_id'] ?? 0);
$uid2     = (int)($_SESSION['user_id'] ?? 0);
$_flash   = getFlash();
$_isAdmin = isAdmin();
$_cur     = basename($_SERVER['PHP_SELF'], '.php');
$_orgName   = strtoupper($_SESSION['org_name'] ?? '');

// FIXED: session keys first_name/last_name (was the nonexistent 'name' key)
$_firstName = $_SESSION['first_name'] ?? '';
$_lastName  = $_SESSION['last_name']  ?? '';
$_fullName  = trim("$_firstName $_lastName");
$_initials  = strtoupper(substr($_firstName,0,1) . substr($_lastName,0,1));

// Job category: load from DB since it's not in session
$_jobCat = $_isAdmin ? 'Administrator' : 'Care Staff';
try {
    $jc = $pdo->prepare("SELECT job_category FROM users WHERE id = ? LIMIT 1");
    $jc->execute([$uid2]);
    $jcRow = $jc->fetch();
    if ($jcRow && !empty($jcRow['job_category'])) $_jobCat = $jcRow['job_category'];
} catch (Exception $e) {}

// Org logo
$_orgLogo = null;
try {
    $r = $pdo->prepare("SELECT logo_path FROM organisations WHERE id = ?");
    $r->execute([$orgId]);
    $r = $r->fetch();
    $_orgLogo = $r['logo_path'] ?? null;
} catch (Exception $e) {}

// Unread badges for staff
$_unreadMsg = 0; $_unreadHO = 0;
if (!$_isAdmin) {
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM staff_messages WHERE organisation_id=? AND (to_id=? OR is_broadcast=1) AND is_read=0"); $x->execute([$orgId,$uid2]); $_unreadMsg=(int)$x->fetchColumn(); } catch(Exception $e){}
    try { $x=$pdo->prepare("SELECT COUNT(*) FROM handovers WHERE organisation_id=? AND to_staff_id=? AND is_read=0"); $x->execute([$orgId,$uid2]); $_unreadHO=(int)$x->fetchColumn(); } catch(Exception $e){}
}

function navLink($href, $icon, $label, $cur, $badge=0) {
    $active = (basename($href, '.php') === $cur || basename(parse_url($href,PHP_URL_PATH),'.php') === $cur);
    $base   = 'flex items-center gap-3 px-3 py-2.5 mx-2 rounded-xl text-sm font-medium transition-all ';
    $cls    = $active ? $base.'bg-white/15 text-white font-semibold' : $base.'text-teal-100/70 hover:bg-white/10 hover:text-white';
    $ico    = 'fa '.$icon.' w-4 text-center '.($active?'text-teal-300':'text-teal-500/60');
    $bdg    = $badge>0 ? "<span class='ml-auto bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full font-bold min-w-[18px] text-center leading-none'>".(int)$badge."</span>" : '';
    return '<a href="'.$href.'" class="'.$cls.'" onclick="if(window.innerWidth<768)closeSidebar()"><i class="'.$ico.'"></i><span class="flex-1">'.htmlspecialchars($label).'</span>'.$bdg.'</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> — Register My Care</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{font-family:'Plus Jakarta Sans',sans-serif;overflow-x:hidden}
#sidebar{width:256px;height:100vh;position:fixed;top:0;left:0;z-index:40;display:flex;flex-direction:column;background:linear-gradient(180deg,#042f2e 0%,#0f4c45 60%,#052e16 100%);transition:transform .25s cubic-bezier(.4,0,.2,1)}
#sb-top{flex-shrink:0}
#sb-nav{flex:1 1 0;overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent}
#sb-nav::-webkit-scrollbar{width:3px}
#sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:2px}
#sb-bot{flex-shrink:0}
.main-wrap{margin-left:256px;display:flex;flex-direction:column;min-height:100vh}
@media(max-width:767px){
  #sidebar{transform:translateX(-100%)}
  #sidebar.open{transform:translateX(0)}
  .main-wrap{margin-left:0!important}
  #overlay{display:block}
  #overlay.active{opacity:1;pointer-events:all}
  #hbtn{display:flex!important}
}
@media(min-width:768px){
  #sidebar{transform:translateX(0)!important}
  #hbtn{display:none!important}
  #overlay{display:none!important}
}
#overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:39;opacity:0;pointer-events:none;transition:opacity .25s}
.nav-sec{padding:.4rem 1rem .15rem;font-size:.58rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#5eead4;opacity:.55;margin-top:.25rem}
main{flex:1;animation:pgup .2s ease}
@keyframes pgup{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
.logo3d{perspective:400px}
.logo3d img{transition:transform .4s;transform-style:preserve-3d}
.logo3d:hover img{transform:rotateY(18deg) rotateX(-8deg) scale(1.08)}
@media print{aside,header,.noprint{display:none!important}main{margin:0!important}}
</style>
</head>
<body class="bg-slate-100">

<div id="overlay" onclick="closeSidebar()"></div>

<!-- ── Sidebar ────────────────────────────────────────────────── -->
<aside id="sidebar">
  <div id="sb-top" class="px-3 pt-4 pb-3 border-b border-white/10">
    <!-- Logo + app name -->
    <div class="flex items-center gap-2.5 mb-3">
      <div class="logo3d flex-shrink-0">
        <img src="/assets/logo.svg" alt="" class="w-9 h-9 drop-shadow-2xl" onerror="this.style.display='none'">
      </div>
      <div>
        <div class="font-extrabold text-sm text-white leading-tight">Register My Care</div>
        <div class="text-[9px] text-teal-400 font-bold tracking-widest uppercase">Digital Care Platform</div>
      </div>
    </div>
    <!-- Org panel -->
    <div class="bg-white/8 border border-white/10 rounded-xl px-3 py-2 mb-2.5" style="background:rgba(255,255,255,.07)">
      <?php if ($_orgLogo && file_exists(__DIR__.'/../'.$_orgLogo)): ?>
      <img src="/<?= htmlspecialchars($_orgLogo) ?>" alt="" class="h-5 object-contain mb-1 opacity-75 max-w-full">
      <?php endif; ?>
      <div class="text-[8px] text-teal-500 font-bold uppercase tracking-widest mb-0.5">Organisation</div>
      <div class="text-xs font-extrabold text-white uppercase tracking-wide truncate leading-tight"><?= htmlspecialchars($_orgName) ?></div>
    </div>
    <!-- Sign out -->
    <a href="/auth/logout.php" onclick="return confirm('Sign out of Register My Care?')" class="flex items-center gap-2 w-full bg-white/5 hover:bg-red-500/15 border border-white/10 hover:border-red-400/30 text-teal-200 hover:text-red-300 transition rounded-xl px-3 py-2 text-xs font-semibold group">
      <div class="w-5 h-5 rounded-md bg-white/10 group-hover:bg-red-500/20 flex items-center justify-center flex-shrink-0 transition">
        <i class="fa fa-arrow-right-from-bracket text-[10px]"></i>
      </div>
      <span class="flex-1">Sign Out</span>
      <span class="text-[9px] text-teal-600 truncate max-w-[60px]"><?= htmlspecialchars($_fullName) ?></span>
    </a>
  </div>

  <!-- Nav links -->
  <div id="sb-nav" class="py-1.5">
    <?php if ($_isAdmin): ?>
    <p class="nav-sec">Overview</p>
    <?= navLink('/dashboard.php','fa-gauge','Dashboard',$_cur) ?>
    <p class="nav-sec">People</p>
    <?= navLink('/pages/service_users.php','fa-user-nurse','Service Users',$_cur) ?>
    <?= navLink('/pages/staff.php','fa-users','Staff Management',$_cur) ?>
    <p class="nav-sec">Scheduling</p>
    <?= navLink('/pages/rota.php','fa-calendar-week','Rota & Assignments',$_cur) ?>
    <?= navLink('/pages/visits.php','fa-route','All Visits',$_cur) ?>
    <p class="nav-sec">Clinical</p>
    <?= navLink('/pages/medications.php','fa-pills','Medications',$_cur) ?>
    <?= navLink('/pages/mar_chart.php','fa-clipboard-list','MAR Chart',$_cur) ?>
    <p class="nav-sec">Communication</p>
    <?= navLink('/pages/messages.php','fa-comments','Messages',$_cur) ?>
    <?= navLink('/pages/handover.php','fa-handshake','Handover',$_cur) ?>
    <?= navLink('/pages/incidents.php','fa-triangle-exclamation','Incidents',$_cur) ?>
    <?= navLink('/pages/holiday.php','fa-umbrella-beach','Holiday',$_cur) ?>
    <p class="nav-sec">Documents</p>
    <?= navLink('/pages/policies.php','fa-file-shield','Policies',$_cur) ?>
    <p class="nav-sec">Finance & Admin</p>
    <?= navLink('/pages/invoices.php','fa-file-invoice','Invoices',$_cur) ?>
    <?= navLink('/pages/reports.php','fa-chart-bar','Reports',$_cur) ?>
    <?= navLink('/pages/audit_log.php','fa-shield-halved','Audit Log',$_cur) ?>
    <?= navLink('/pages/subscription.php','fa-star','Subscription',$_cur) ?>
    <?= navLink('/pages/settings.php','fa-gear','Settings',$_cur) ?>
    <?php else: ?>
    <p class="nav-sec">My Work</p>
    <?= navLink('/dashboard.php','fa-gauge','Dashboard',$_cur) ?>
    <?= navLink('/pages/my_visits.php','fa-house-medical','My Visits',$_cur) ?>
    <?= navLink('/pages/my_day.php','fa-clock-rotate-left','My Day Summary',$_cur) ?>
    <?= navLink('/pages/mar_chart.php','fa-pills','Medication Record',$_cur) ?>
    <?= navLink('/pages/rota.php','fa-calendar-week','My Rota',$_cur) ?>
    <p class="nav-sec">Communication</p>
    <?= navLink('/pages/messages.php','fa-comments','Messages',$_cur,$_unreadMsg) ?>
    <?= navLink('/pages/handover.php','fa-handshake','Handover',$_cur,$_unreadHO) ?>
    <?= navLink('/pages/incidents.php','fa-triangle-exclamation','Incidents',$_cur) ?>
    <?= navLink('/pages/holiday.php','fa-umbrella-beach','Holiday',$_cur) ?>
    <p class="nav-sec">Documents</p>
    <?= navLink('/pages/policies.php','fa-file-shield','Policies',$_cur) ?>
    <?php endif; ?>
    <div class="h-2"></div>
    <!-- User card -->
    <div class="mx-2 mb-2 rounded-xl px-3 py-2 flex items-center gap-2" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07)">
      <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-extrabold <?= $_isAdmin?'bg-amber-400 text-amber-900':'bg-teal-400 text-teal-900' ?>">
        <?php $pts=explode(' ',trim('U')); echo strtoupper(substr($pts[0],0,1).substr(end($pts),0,1)); ?>
      </div>
      <div class="min-w-0 flex-1">
        <div class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($_fullName) ?></div>
        <div class="text-[10px] text-teal-400 truncate"><?= htmlspecialchars($_jobCat) ?></div>
      </div>
      <span class="text-[9px] <?= $_isAdmin?'bg-amber-400/20 text-amber-300 border-amber-400/30':'bg-teal-400/20 text-teal-300 border-teal-400/30' ?> border px-1.5 py-0.5 rounded-md font-bold"><?= $_isAdmin?'Admin':'Staff' ?></span>
    </div>
  </div>

  <div id="sb-bot" class="px-4 py-2 border-t border-white/8" style="background:rgba(0,0,0,.2)">
    <p class="text-[10px] text-teal-600 text-center font-medium"><?= APP_NAME ?> v<?= APP_VERSION ?></p>
  </div>
</aside>

<!-- ── Main wrapper ───────────────────────────────────────────── -->
<div class="main-wrap">

  <!-- Top bar -->
  <header class="bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between sticky top-0 z-30 noprint shadow-sm">
    <div class="flex items-center gap-3 min-w-0">
      <button id="hbtn" onclick="openSidebar()" style="display:none"
              class="w-9 h-9 rounded-xl bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition flex-shrink-0">
        <i class="fa fa-bars text-gray-600"></i>
      </button>
      <div class="min-w-0">
        <h1 class="text-sm font-bold text-teal-900 leading-tight truncate"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
        <p class="text-[10px] font-extrabold text-teal-500 uppercase tracking-widest leading-tight truncate"><?= htmlspecialchars($_orgName) ?></p>
      </div>
    </div>
    <div class="flex items-center gap-2 flex-shrink-0">
      <?php if ($_isAdmin): try { $sub=getOrgSubscription($orgId); if(!$sub['is_premium']): ?>
      <a href="/pages/subscription.php" class="hidden sm:flex items-center gap-1 text-xs bg-amber-50 text-amber-700 border border-amber-200 px-2.5 py-1.5 rounded-lg hover:bg-amber-100 transition">
        <i class="fa fa-star text-amber-500"></i><?= $sub['active_su_count'] ?>/<?= FREE_PLAN_SU_LIMIT ?> SU
      </a>
      <?php endif; } catch(Exception $e){} endif; ?>
      <span class="text-xs text-gray-400 hidden md:block"><?= date('d M Y') ?></span>
    </div>
  </header>

  <!-- Flash message -->
  <?php if ($_flash): ?>
  <div id="flash" class="mx-4 mt-4 p-3 rounded-xl border text-sm flex items-start justify-between noprint
       <?= $_flash['type']==='success'?'bg-green-50 border-green-300 text-green-800':($_flash['type']==='error'?'bg-red-50 border-red-300 text-red-800':'bg-blue-50 border-blue-300 text-blue-800') ?>">
    <div class="flex items-start gap-2">
      <i class="fa <?= $_flash['type']==='success'?'fa-circle-check text-green-500':($_flash['type']==='error'?'fa-circle-xmark text-red-500':'fa-circle-info text-blue-500') ?> mt-0.5 flex-shrink-0"></i>
      <span><?= htmlspecialchars($_flash['message']) ?></span>
    </div>
    <button onclick="document.getElementById('flash').remove()" class="ml-4 text-xl font-bold opacity-40 hover:opacity-80 leading-none flex-shrink-0">&times;</button>
  </div>
  <?php endif; ?>

  <main class="flex-1 p-4 md:p-6">
