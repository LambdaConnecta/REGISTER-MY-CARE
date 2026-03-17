<?php
/**
 * Register My Care — Dashboard
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/includes/header.php';

$pdo    = getPDO();
$orgId  = currentOrgId();
$uid    = currentUserId();

// ── Stats ────────────────────────────────────────────────────────────────────
$suCount     = 0;
$staffCount  = 0;
$todayVisits = 0;
$pendingHols = 0;
$openInc     = 0;
$unreadMsgs  = getUnreadMessageCount($pdo, $orgId, $uid);

try {
    $suCount    = (int)$pdo->prepare("SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1")->execute([$orgId]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1"); $stmt->execute([$orgId]); $suCount = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE organisation_id=? AND is_active=1"); $stmt->execute([$orgId]); $staffCount = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE organisation_id=? AND visit_date=CURDATE()"); $stmt->execute([$orgId]); $todayVisits = (int)$stmt->fetchColumn();
    if ($_isAdmin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM holiday_requests WHERE organisation_id=? AND status='Pending'"); $stmt->execute([$orgId]); $pendingHols = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE organisation_id=? AND status='Open'"); $stmt->execute([$orgId]); $openInc = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) { /* tables may not exist yet */ }

// ── Today's visits for this staff member (or all if admin) ───────────────────
$todayRows = [];
try {
    if ($_isAdmin) {
        $q = $pdo->prepare("SELECT v.*,su.first_name sf,su.last_name sl,u.first_name uf,u.last_name ul
            FROM visits v JOIN service_users su ON v.service_user_id=su.id LEFT JOIN users u ON v.carer_id=u.id
            WHERE v.organisation_id=? AND v.visit_date=CURDATE() ORDER BY v.start_time LIMIT 20");
        $q->execute([$orgId]);
    } else {
        $q = $pdo->prepare("SELECT v.*,su.first_name sf,su.last_name sl
            FROM visits v JOIN service_users su ON v.service_user_id=su.id
            WHERE v.organisation_id=? AND v.carer_id=? AND v.visit_date=CURDATE() ORDER BY v.start_time LIMIT 20");
        $q->execute([$orgId, $uid]);
    }
    $todayRows = $q->fetchAll();
} catch (Exception $e) {}

$statusColours = [
    'Scheduled'  => 'bg-blue-100 text-blue-700',
    'In Progress' => 'bg-yellow-100 text-yellow-700',
    'Completed'  => 'bg-green-100 text-green-700',
    'Missed'     => 'bg-red-100 text-red-700',
    'Cancelled'  => 'bg-gray-100 text-gray-600',
];
?>

<div class="max-w-7xl mx-auto px-4 py-6">

  <!-- Flash -->
  <?= renderFlash($_flash) ?>

  <!-- Welcome -->
  <div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-800">
      Good <?= (date('H') < 12) ? 'morning' : ((date('H') < 17) ? 'afternoon' : 'evening') ?>,
      <?= h($_firstName) ?> 👋
    </h1>
    <p class="text-gray-500 text-sm mt-1"><?= date('l, j F Y') ?> &mdash; <?= h($_orgName) ?></p>
  </div>

  <!-- Stat Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-<?= $_isAdmin ? '5' : '3' ?> gap-4 mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Service Users</span>
        <span class="w-9 h-9 rounded-xl bg-teal-100 flex items-center justify-center text-teal-600"><i class="fa fa-users text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $suCount ?></div>
      <a href="/pages/service_users.php" class="text-xs text-teal-600 font-medium mt-1 inline-block hover:underline">View all →</a>
    </div>

    <?php if ($_isAdmin): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Staff</span>
        <span class="w-9 h-9 rounded-xl bg-violet-100 flex items-center justify-center text-violet-600"><i class="fa fa-id-badge text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $staffCount ?></div>
      <a href="/pages/staff.php" class="text-xs text-violet-600 font-medium mt-1 inline-block hover:underline">Manage →</a>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Today's Visits</span>
        <span class="w-9 h-9 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600"><i class="fa fa-calendar-day text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $todayVisits ?></div>
      <a href="/pages/rota.php" class="text-xs text-blue-600 font-medium mt-1 inline-block hover:underline">Rota →</a>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Messages</span>
        <span class="w-9 h-9 rounded-xl bg-cyan-100 flex items-center justify-center text-cyan-600"><i class="fa fa-envelope text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $unreadMsgs ?></div>
      <a href="/pages/messages.php" class="text-xs text-cyan-600 font-medium mt-1 inline-block hover:underline">Inbox →</a>
    </div>

    <?php if ($_isAdmin): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Pending Holidays</span>
        <span class="w-9 h-9 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600"><i class="fa fa-umbrella-beach text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $pendingHols ?></div>
      <a href="/pages/holiday.php" class="text-xs text-amber-600 font-medium mt-1 inline-block hover:underline">Review →</a>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Open Incidents</span>
        <span class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center text-red-600"><i class="fa fa-triangle-exclamation text-sm"></i></span>
      </div>
      <div class="text-3xl font-extrabold text-gray-800"><?= $openInc ?></div>
      <a href="/pages/incidents.php" class="text-xs text-red-600 font-medium mt-1 inline-block hover:underline">View →</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Today's Schedule -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="font-bold text-gray-800 flex items-center gap-2">
        <i class="fa fa-calendar-check text-teal-500"></i> Today's Schedule
      </h2>
      <a href="/pages/rota.php" class="text-sm text-teal-600 font-semibold hover:underline">Full Rota →</a>
    </div>

    <?php if (empty($todayRows)): ?>
      <div class="px-6 py-10 text-center text-gray-400">
        <i class="fa fa-calendar-xmark text-3xl mb-3 block"></i>
        <p class="text-sm">No visits scheduled for today.</p>
      </div>
    <?php else: ?>
      <div class="divide-y divide-gray-50">
        <?php foreach ($todayRows as $v):
          $statusCls = $statusColours[$v['status']] ?? 'bg-gray-100 text-gray-600';
          $suName = h($v['sf'] . ' ' . $v['sl']);
        ?>
        <div class="px-6 py-3 flex items-center gap-4 hover:bg-gray-50 transition">
          <div class="text-sm font-mono text-gray-500 w-28 flex-shrink-0"><?= h($v['start_time']) ?> – <?= h($v['end_time']) ?></div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-800 text-sm truncate"><?= $suName ?></div>
            <?php if ($_isAdmin && !empty($v['uf'])): ?>
              <div class="text-xs text-gray-400"><?= h($v['uf'] . ' ' . $v['ul']) ?></div>
            <?php endif; ?>
          </div>
          <span class="text-xs font-bold px-2.5 py-1 rounded-full <?= $statusCls ?>"><?= h($v['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
