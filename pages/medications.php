<?php
/**
 * Register My Care — Medications
 * Created and designed by Dr. Andrew Ebhoma
 */
require_once __DIR__ . '/../includes/header.php';
$pdo   = getPDO();
$orgId = currentOrgId();

$meds = [];
try {
    $stmt = $pdo->prepare("SELECT m.*,su.first_name sf,su.last_name sl FROM medications m JOIN service_users su ON m.service_user_id=su.id WHERE m.organisation_id=? AND su.is_active=1 ORDER BY sl,sf,m.name");
    $stmt->execute([$orgId]);
    $meds = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<div class="max-w-7xl mx-auto px-4 py-6">
  <?= renderFlash($_flash) ?>
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-extrabold text-gray-800">Medications</h1>
    <?php if ($_isAdmin): ?>
      <a href="/pages/su_profile.php" class="btn-primary text-sm"><i class="fa fa-plus"></i> Add via SU Profile</a>
    <?php endif; ?>
  </div>

  <?php if (empty($meds)): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-16 text-center text-gray-400">
      <i class="fa fa-pills text-4xl mb-4 block text-gray-200"></i>
      <p class="text-sm">No medications recorded yet.</p>
      <p class="text-xs mt-1">Add medications via a Service User's profile → Medical History tab.</p>
    </div>
  <?php else: ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Service User</th>
            <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Medication</th>
            <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Dose</th>
            <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Frequency</th>
            <th class="px-4 py-3 text-left text-xs text-gray-400 font-semibold uppercase tracking-wide">Route</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach($meds as $m): ?>
            <tr class="hover:bg-gray-50 transition">
              <td class="px-4 py-3 font-semibold text-gray-800"><?= h($m['sf'].' '.$m['sl']) ?></td>
              <td class="px-4 py-3 font-medium text-gray-800"><?= h($m['name']) ?></td>
              <td class="px-4 py-3 text-gray-600"><?= h($m['dose'] ?? '—') ?></td>
              <td class="px-4 py-3 text-gray-600"><?= h($m['frequency'] ?? '—') ?></td>
              <td class="px-4 py-3 text-gray-600"><?= h($m['route'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
