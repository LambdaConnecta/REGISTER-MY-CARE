<?php
/**
 * Register My Care — MAR Chart (Medication Administration Record)
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/../includes/header.php';
$pdo   = getPDO();
$orgId = currentOrgId();
$uid   = currentUserId();

$month  = (int)($_GET['month'] ?? date('n'));
$year   = (int)($_GET['year']  ?? date('Y'));
$suId   = (int)($_GET['su']    ?? 0);

$month = max(1, min(12, $month));
$year  = max(2020, min((int)date('Y') + 1, $year));

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Get service users with medications
$serviceUsers = [];
try {
    $q = $pdo->prepare("SELECT DISTINCT su.id, su.first_name, su.last_name FROM service_users su JOIN medications m ON m.service_user_id=su.id WHERE su.organisation_id=? AND su.is_active=1 ORDER BY su.last_name");
    $q->execute([$orgId]); $serviceUsers = $q->fetchAll();
} catch (Exception $e) {}

if (!$suId && !empty($serviceUsers)) $suId = $serviceUsers[0]['id'];

$meds    = [];
$marRows = [];
if ($suId) {
    try {
        $q = $pdo->prepare("SELECT * FROM medications WHERE service_user_id=? AND organisation_id=? ORDER BY name"); $q->execute([$suId, $orgId]); $meds = $q->fetchAll();
        $q = $pdo->prepare("SELECT * FROM mar_records WHERE service_user_id=? AND organisation_id=? AND MONTH(administered_at)=? AND YEAR(administered_at)=?"); $q->execute([$suId, $orgId, $month, $year]); $rows = $q->fetchAll();
        foreach($rows as $r) $marRows[$r['medication_id']][date('j', strtotime($r['administered_at']))] = $r['status'];
    } catch (Exception $e) {}
}

$monthName = date('F', mktime(0,0,0,$month,1,$year));
?>
<div class="max-w-full mx-auto px-4 py-6">
  <?= renderFlash($_flash) ?>
  <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <h1 class="text-2xl font-extrabold text-gray-800">MAR Chart</h1>
    <form method="GET" class="flex flex-wrap gap-2 items-center">
      <select name="su" class="border rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-teal-500">
        <?php foreach($serviceUsers as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $suId===$s['id']?'selected':'' ?>><?= h($s['first_name'].' '.$s['last_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="month" class="border rounded-xl px-3 py-2 text-sm">
        <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option><?php endfor; ?>
      </select>
      <input type="number" name="year" value="<?= $year ?>" min="2020" max="<?= date('Y')+1 ?>" class="border rounded-xl px-3 py-2 text-sm w-24"/>
      <button type="submit" class="bg-teal-600 text-white font-semibold rounded-xl px-4 py-2 text-sm hover:bg-teal-700 transition">View</button>
    </form>
  </div>

  <?php if (empty($meds)): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-14 text-center text-gray-400 text-sm">
      <?= $suId ? 'No medications recorded for this service user.' : 'Select a service user.' ?>
    </div>
  <?php else: ?>
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-auto">
    <div class="px-6 py-4 border-b border-gray-100 font-bold text-gray-800"><?= $monthName.' '.$year ?></div>
    <table class="text-xs min-w-max">
      <thead>
        <tr class="border-b border-gray-100 bg-gray-50">
          <th class="px-4 py-3 text-left font-semibold text-gray-500 sticky left-0 bg-gray-50 min-w-48">Medication</th>
          <?php for($d=1;$d<=$daysInMonth;$d++): ?>
            <th class="px-2 py-3 font-semibold text-gray-400 text-center w-8"><?= $d ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach($meds as $med): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-800 sticky left-0 bg-white">
            <?= h($med['name']) ?>
            <?php if(!empty($med['dose'])): ?><div class="text-gray-400 text-xs"><?= h($med['dose']) ?></div><?php endif; ?>
          </td>
          <?php for($d=1;$d<=$daysInMonth;$d++): $s=$marRows[$med['id']][$d]??null; ?>
          <td class="px-1 py-3 text-center">
            <?php if($s==='Given'): ?><span class="text-green-600 font-bold">✓</span>
            <?php elseif($s==='Refused'): ?><span class="text-red-500 font-bold">✗</span>
            <?php elseif($s==='Withheld'): ?><span class="text-amber-500 font-bold">W</span>
            <?php else: ?><span class="text-gray-200">·</span><?php endif; ?>
          </td>
          <?php endfor; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="px-6 py-3 border-t border-gray-100 text-xs text-gray-400 flex gap-4">
      <span><strong class="text-green-600">✓</strong> Given</span>
      <span><strong class="text-red-500">✗</strong> Refused</span>
      <span><strong class="text-amber-500">W</strong> Withheld</span>
      <span><strong class="text-gray-300">·</strong> Not due</span>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
