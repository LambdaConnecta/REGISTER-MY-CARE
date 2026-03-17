<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();

// Self-heal handovers columns
try { $pdo->exec("ALTER TABLE `handovers` ADD COLUMN `shift_end` VARCHAR(10) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `handovers` ADD COLUMN `no_further_visits` TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `handovers` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Handover';

// Submit handover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_handover'])) {
    validateCSRF();
    $toId    = (int)($_POST['to_staff_id'] ?? 0) ?: null;
    $suId    = (int)($_POST['service_user_id'] ?? 0) ?: null;
    $noMore  = isset($_POST['no_further_visits']) ? 1 : 0;
    $content = trim($_POST['content'] ?? '');
    $shiftEnd = trim($_POST['shift_end'] ?? '');
    if (!$content) { setFlash('error','Please write handover notes.'); header('Location: handover.php'); exit; }
    try {
        $pdo->prepare("INSERT INTO handovers (organisation_id,from_staff_id,to_staff_id,service_user_id,handover_date,shift_end,no_further_visits,content,created_at) VALUES(?,?,?,?,CURDATE(),?,?,?,NOW())")
            ->execute([$orgId,$uid,$toId,$suId,$shiftEnd?:null,$noMore,$content]);
        setFlash('success','Handover submitted successfully.');
    } catch (Exception $e) { setFlash('error','Error: '.$e->getMessage()); }
    header('Location: handover.php'); exit;
}

// Mark as read
if (isset($_GET['read'])) {
    try { $pdo->prepare("UPDATE handovers SET is_read=1 WHERE id=? AND organisation_id=?")->execute([(int)$_GET['read'],$orgId]); } catch(Exception $e){}
    header('Location: handover.php?tab=inbox'); exit;
}

$tab = $_GET['tab'] ?? 'inbox';

// Incoming handovers (to me or all staff)
$incoming = [];
try {
    $q = $pdo->prepare("SELECT h.*, u.first_name ff, u.last_name fl, su.first_name sf, su.last_name sl FROM handovers h JOIN users u ON h.from_staff_id=u.id LEFT JOIN service_users su ON h.service_user_id=su.id WHERE h.organisation_id=? AND (h.to_staff_id=? OR h.to_staff_id IS NULL) AND h.from_staff_id!=? ORDER BY h.created_at DESC");
    $q->execute([$orgId,$uid,$uid]); $incoming=$q->fetchAll();
} catch(Exception $e){}

// My submitted handovers
$outgoing = [];
try {
    $q = $pdo->prepare("SELECT h.*, u.first_name tf, u.last_name tl, su.first_name sf, su.last_name sl FROM handovers h LEFT JOIN users u ON h.to_staff_id=u.id LEFT JOIN service_users su ON h.service_user_id=su.id WHERE h.organisation_id=? AND h.from_staff_id=? ORDER BY h.created_at DESC");
    $q->execute([$orgId,$uid]); $outgoing=$q->fetchAll();
} catch(Exception $e){}

// If admin, show all
$allHandovers = [];
if ($isAdmin) {
    try {
        $q = $pdo->prepare("SELECT h.*, u.first_name ff, u.last_name fl, t.first_name tf, t.last_name tl, su.first_name sf, su.last_name sl FROM handovers h JOIN users u ON h.from_staff_id=u.id LEFT JOIN users t ON h.to_staff_id=t.id LEFT JOIN service_users su ON h.service_user_id=su.id WHERE h.organisation_id=? ORDER BY h.created_at DESC LIMIT 100");
        $q->execute([$orgId]); $allHandovers=$q->fetchAll();
    } catch(Exception $e){}
}

// Staff + service users for form
$staffList = []; $suList = [];
try { $q=$pdo->prepare("SELECT id,first_name,last_name,job_category FROM users WHERE organisation_id=? AND is_active=1 AND id!=? ORDER BY last_name"); $q->execute([$orgId,$uid]); $staffList=$q->fetchAll(); } catch(Exception $e){}
try { $q=$pdo->prepare("SELECT id,first_name,last_name FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name"); $q->execute([$orgId]); $suList=$q->fetchAll(); } catch(Exception $e){}

$unread = count(array_filter($incoming,fn($h)=>!$h['is_read']));
include __DIR__ . '/../includes/header.php';
?>
<div class="flex flex-col md:flex-row gap-4">

<!-- Left: New handover form -->
<div class="md:w-80 flex-shrink-0">
    <div class="bg-white rounded-2xl shadow overflow-hidden sticky top-20">
        <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-5 py-4">
            <h3 class="text-white font-extrabold"><i class="fa fa-handshake mr-2"></i>Write Handover</h3>
        </div>
        <form method="POST" class="p-4">
            <?= csrfField() ?>
            <input type="hidden" name="submit_handover" value="1">
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-600 mb-1">Hand Over To</label>
                <select name="to_staff_id" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <option value="">All staff (General handover)</option>
                    <?php foreach ($staffList as $s): ?><option value="<?=$s['id']?>"><?=h($s['last_name'].', '.$s['first_name'])?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-600 mb-1">Regarding Service User (optional)</label>
                <select name="service_user_id" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <option value="">Not specific to one person</option>
                    <?php foreach ($suList as $su): ?><option value="<?=$su['id']?>"><?=h($su['last_name'].', '.$su['first_name'])?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-600 mb-1">Shift End Time</label>
                <input type="time" name="shift_end" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
            </div>
            <div class="mb-3">
                <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700">
                    <input type="checkbox" name="no_further_visits" class="w-4 h-4 rounded text-amber-600">
                    <span class="text-amber-700">No further visits today</span>
                </label>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-bold text-gray-600 mb-1">Handover Notes *</label>
                <textarea name="content" rows="6" required
                    placeholder="Describe what happened during your shift, any concerns, outstanding tasks, medication notes, changes in service user condition..."
                    class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
            </div>
            <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 rounded-xl text-sm transition">
                <i class="fa fa-paper-plane mr-1"></i>Submit Handover
            </button>
        </form>
    </div>
</div>

<!-- Right: Handover list -->
<div class="flex-1 min-w-0">
    <?php if ($isAdmin): ?>
    <!-- Admin sees all -->
    <h3 class="font-extrabold text-gray-800 mb-3 flex items-center gap-2"><i class="fa fa-list text-teal-500"></i>All Handovers</h3>
    <?php if (empty($allHandovers)): ?>
    <div class="bg-white rounded-2xl shadow p-8 text-center text-gray-400"><div class="text-3xl mb-2">🤝</div><p>No handovers yet.</p></div>
    <?php else: ?>
    <div class="space-y-3">
    <?php foreach ($allHandovers as $h): ?>
    <div class="bg-white rounded-2xl shadow p-4">
        <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex-1 min-w-0">
                <div class="font-bold text-gray-800 text-sm"><?=h($h['ff'].' '.$h['fl'])?> <span class="text-gray-400 font-normal">→</span> <?= $h['tf'] ? h($h['tf'].' '.$h['tl']) : '<span class="text-blue-600">All Staff</span>' ?></div>
                <?php if ($h['sf']): ?><div class="text-xs text-teal-600 font-semibold mt-0.5">Re: <?=h($h['sf'].' '.$h['sl'])?></div><?php endif; ?>
                <?php if ($h['no_further_visits']): ?><span class="inline-block text-xs bg-amber-100 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full mt-1">No further visits</span><?php endif; ?>
            </div>
            <div class="text-xs text-gray-400 flex-shrink-0"><?=date('d M Y H:i',strtotime($h['created_at']))?></div>
        </div>
        <p class="text-sm text-gray-600 bg-gray-50 rounded-xl p-3 leading-relaxed"><?=h($h['content'])?></p>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Staff tabs -->
    <div class="flex gap-1 mb-4 border-b border-gray-200">
        <a href="?tab=inbox" class="px-4 py-2.5 text-sm font-semibold border-b-2 <?=$tab==='inbox'?'border-teal-500 text-teal-700':'border-transparent text-gray-500'?>">
            Inbox <?php if ($unread>0): ?><span class="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full ml-1"><?=$unread?></span><?php endif; ?>
        </a>
        <a href="?tab=sent" class="px-4 py-2.5 text-sm font-semibold border-b-2 <?=$tab==='sent'?'border-teal-500 text-teal-700':'border-transparent text-gray-500'?>">Submitted</a>
    </div>
    <?php $list = $tab==='inbox' ? $incoming : $outgoing; ?>
    <?php if (empty($list)): ?>
    <div class="bg-white rounded-2xl shadow p-8 text-center text-gray-400"><div class="text-3xl mb-2">🤝</div><p><?=$tab==='inbox'?'No incoming handovers.':'You have not submitted any handovers yet.'?></p></div>
    <?php else: ?>
    <div class="space-y-3">
    <?php foreach ($list as $h):
        $isUnread = ($tab==='inbox' && !$h['is_read']);
    ?>
    <div class="bg-white rounded-2xl shadow p-4 <?=$isUnread?'border-l-4 border-l-blue-400':''?>">
        <div class="flex items-start justify-between gap-3 mb-2">
            <div>
                <?php if ($tab==='inbox'): ?>
                <div class="font-bold text-gray-800 text-sm <?=$isUnread?'text-blue-800':''?>"><?=h($h['ff'].' '.$h['fl'])?></div>
                <?php else: ?>
                <div class="font-bold text-gray-800 text-sm">To: <?= $h['tf'] ? h($h['tf'].' '.$h['tl']) : 'All Staff' ?></div>
                <?php endif; ?>
                <?php if ($h['sf']): ?><div class="text-xs text-teal-600 font-semibold">Re: <?=h($h['sf'].' '.$h['sl'])?></div><?php endif; ?>
                <?php if ($h['shift_end']): ?><div class="text-xs text-gray-400">Shift ended: <?=h($h['shift_end'])?></div><?php endif; ?>
                <?php if ($h['no_further_visits']): ?><span class="inline-block text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full mt-1">No further visits</span><?php endif; ?>
            </div>
            <div class="text-right flex-shrink-0">
                <div class="text-xs text-gray-400"><?=date('d M Y H:i',strtotime($h['created_at']))?></div>
                <?php if ($isUnread): ?><a href="?read=<?=$h['id']?>" class="text-xs text-blue-600 hover:underline block mt-1">Mark read</a><?php endif; ?>
            </div>
        </div>
        <p class="text-sm text-gray-600 bg-gray-50 rounded-xl p-3 leading-relaxed"><?=h($h['content'])?></p>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
