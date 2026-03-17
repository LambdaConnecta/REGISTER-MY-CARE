<?php
/**
 * Register My Care — All Visits
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/../includes/header.php';
requireAdmin();
$pdo   = getPDO();
$orgId = currentOrgId();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = paginateOffset($page, $perPage);

$filterStaff  = (int)($_GET['staff']  ?? 0);
$filterSU     = (int)($_GET['su']     ?? 0);
$filterStatus = $_GET['status']  ?? '';
$filterDate   = $_GET['date']    ?? '';

$where  = ['v.organisation_id = ?'];
$params = [$orgId];

if ($filterStaff)  { $where[] = 'v.carer_id = ?';          $params[] = $filterStaff; }
if ($filterSU)     { $where[] = 'v.service_user_id = ?';   $params[] = $filterSU; }
if ($filterStatus) { $where[] = 'v.status = ?';             $params[] = $filterStatus; }
if ($filterDate)   { $where[] = 'v.visit_date = ?';         $params[] = $filterDate; }

$sql = "SELECT v.*,
        su.first_name sf, su.last_name sl,
        COALESCE(u.first_name,'Unassigned') uf, COALESCE(u.last_name,'') ul
        FROM visits v
        JOIN service_users su ON v.service_user_id = su.id
        LEFT JOIN users u ON v.carer_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY v.visit_date DESC, v.start_time DESC
        LIMIT $perPage OFFSET $offset";

$visits = [];
$total  = 0;
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $visits = $stmt->fetchAll();
    $cntSql = "SELECT COUNT(*) FROM visits v WHERE " . implode(' AND ', $where);
    $cntStmt = $pdo->prepare($cntSql); $cntStmt->execute($params); $total = (int)$cntStmt->fetchColumn();
} catch (Exception $e) {}

$statusColours = ['Scheduled'=>'bg-blue-100 text-blue-700','In Progress'=>'bg-yellow-100 text-yellow-700','Completed'=>'bg-green-100 text-green-700','Missed'=>'bg-red-100 text-red-700','Cancelled'=>'bg-gray-100 text-gray-600'];
?>
<div class="max-w-7xl mx-auto px-4 py-6">
  <?= renderFlash($_flash) ?>
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-extrabold text-gray-800">All Visits</h1>
    <a href="/pages/rota.php" class="btn-primary text-sm"><i class="fa fa-calendar"></i> Manage Rota</a>
  </div>

  <!-- Filters -->
  <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
    <input type="date" name="date" value="<?= h($filterDate) ?>" class="border rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-teal-500"/>
    <select name="status" class="border rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-teal-500">
      <option value="">All Statuses</option>
      <?php foreach(['Scheduled','In Progress','Completed','Missed','Cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="col-span-2 sm:col-span-1 bg-teal-600 text-white font-semibold rounded-xl px-4 py-2 text-sm hover:bg-teal-700 transition">Filter</button>
    <a href="/pages/visits.php" class="text-center text-sm text-gray-500 hover:text-gray-700 py-2">Clear</a>
  </form>

  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-6 py-3 border-b border-gray-100 text-xs text-gray-400 font-semibold"><?= $total ?> visit<?= $total!=1?'s':'' ?></div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="border-b border-gray-100 bg-gray-50">
          <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Date</th>
          <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Time</th>
          <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Service User</th>
          <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Carer</th>
          <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($visits)): ?>
            <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400 text-sm">No visits found.</td></tr>
          <?php else: foreach($visits as $v): $sc=$statusColours[$v['status']]??'bg-gray-100 text-gray-600'; ?>
          <tr class="hover:bg-gray-50 transition">
            <td class="px-4 py-3 font-medium text-gray-800"><?= fmtDate($v['visit_date']) ?></td>
            <td class="px-4 py-3 text-gray-600 font-mono text-xs"><?= h($v['start_time']) ?>–<?= h($v['end_time']) ?></td>
            <td class="px-4 py-3 font-semibold text-gray-800"><?= h($v['sf'].' '.$v['sl']) ?></td>
            <td class="px-4 py-3 text-gray-600"><?= h($v['uf'].' '.$v['ul']) ?></td>
            <td class="px-4 py-3"><span class="text-xs font-bold px-2.5 py-1 rounded-full <?= $sc ?>"><?= h($v['status']) ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
