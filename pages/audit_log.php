<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$pageTitle = 'Audit Log';
$page  = max(1,(int)($_GET['page']??1));
$pp    = 50;
$offset= ($page-1)*$pp;

$logs  = [];
$total = 0;
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE organisation_id=?");
    $c->execute([$orgId]); $total=(int)$c->fetchColumn();
    $q = $pdo->prepare("SELECT al.*, u.first_name, u.last_name FROM audit_log al LEFT JOIN users u ON al.user_id=u.id WHERE al.organisation_id=? ORDER BY al.created_at DESC LIMIT ? OFFSET ?");
    $q->execute([$orgId,$pp,$offset]); $logs=$q->fetchAll();
} catch (Exception $e) { setFlash('error','Could not load audit log: '.$e->getMessage()); }

$pages = max(1,(int)ceil($total/$pp));
include __DIR__ . '/../includes/header.php';
?>
<div class="flex items-center justify-between mb-5">
    <div>
        <h2 class="text-xl font-extrabold text-gray-800">Audit Log</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> total entries</p>
    </div>
</div>

<div class="bg-white rounded-2xl shadow overflow-hidden">
    <?php if (empty($logs)): ?>
    <div class="p-10 text-center text-gray-400"><div class="text-4xl mb-2">📋</div><p>No audit entries yet.</p></div>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Date/Time</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">User</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Action</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Module</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Details</th>
            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">IP</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($logs as $l): ?>
        <tr class="hover:bg-gray-50">
            <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap"><?= date('d/m/Y H:i',strtotime($l['created_at'])) ?></td>
            <td class="px-4 py-2.5 font-medium text-gray-700 whitespace-nowrap"><?= h(($l['first_name']??'System').' '.($l['last_name']??'')) ?></td>
            <td class="px-4 py-2.5"><span class="text-xs bg-teal-50 text-teal-700 border border-teal-100 px-2 py-0.5 rounded-full font-semibold"><?= h($l['action']??'') ?></span></td>
            <td class="px-4 py-2.5 text-gray-500 text-xs"><?= h($l['module']??'') ?></td>
            <td class="px-4 py-2.5 text-gray-500 text-xs max-w-xs truncate"><?= h($l['details']??'') ?></td>
            <td class="px-4 py-2.5 text-gray-400 text-xs font-mono"><?= h($l['ip_address']??'') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <!-- Pagination -->
    <?php if ($pages>1): ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
        <span class="text-xs text-gray-500">Page <?=$page?> of <?=$pages?></span>
        <div class="flex gap-2">
            <?php if ($page>1): ?><a href="?page=<?=$page-1?>" class="px-3 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg font-semibold">← Prev</a><?php endif; ?>
            <?php if ($page<$pages): ?><a href="?page=<?=$page+1?>" class="px-3 py-1 text-xs bg-teal-600 text-white hover:bg-teal-700 rounded-lg font-semibold">Next →</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
