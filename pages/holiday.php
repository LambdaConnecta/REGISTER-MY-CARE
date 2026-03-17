<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();

// Self-heal holiday_requests columns
try { $pdo->exec("ALTER TABLE `holiday_requests` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `holiday_requests` ADD COLUMN `file_original` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `holiday_requests` ADD COLUMN `admin_notes` TEXT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `holiday_requests` ADD COLUMN `reviewed_by` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `holiday_requests` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL"); } catch(Exception $e){}
// Fix job_category ENUM -> VARCHAR
try { $col=$pdo->query("SHOW COLUMNS FROM `users` LIKE 'job_category'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `job_category` VARCHAR(80) DEFAULT NULL"); } } catch(Exception $e){}

$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Holiday Requests';

$uploadDir = __DIR__.'/../uploads/holiday_forms/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); file_put_contents($uploadDir.'.htaccess',"Options -Indexes\n"); }

// Staff submit request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    validateCSRF();
    $start  = $_POST['start_date'] ?? '';
    $end    = $_POST['end_date']   ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $days   = $start && $end ? max(1, (int)ceil((strtotime($end)-strtotime($start))/86400)+1) : 1;
    $fn = null; $orig = null;

    if (!$start || !$end) { setFlash('error','Please select start and end dates.'); header('Location: holiday.php'); exit; }

    if (!empty($_FILES['holiday_form']['name']) && $_FILES['holiday_form']['error']===UPLOAD_ERR_OK) {
        $f    = $_FILES['holiday_form'];
        $mime = @mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
        $ext  = strtolower(pathinfo(basename($f['name']),PATHINFO_EXTENSION));
        $fn   = 'holiday_'.$uid.'_'.time().'.'.$ext;
        $orig = basename($f['name']);
        if ($f['size'] > 20*1024*1024) { setFlash('error','File too large (max 20MB).'); header('Location: holiday.php'); exit; }
        if (!move_uploaded_file($f['tmp_name'], $uploadDir.$fn)) { $fn=null; $orig=null; }
    }

    try {
        $pdo->prepare("INSERT INTO holiday_requests (organisation_id,staff_id,start_date,end_date,days,reason,file_name,file_original,status,created_at) VALUES(?,?,?,?,?,?,?,?,'Pending',NOW())")
            ->execute([$orgId,$uid,$start,$end,$days,$reason,$fn,$orig]);
        setFlash('success','Holiday request submitted successfully.');
    } catch (Exception $e) { setFlash('error','Error: '.$e->getMessage()); }
    header('Location: holiday.php'); exit;
}

// Admin review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_request']) && $isAdmin) {
    validateCSRF();
    $rid    = (int)$_POST['request_id'];
    $status = in_array($_POST['status'],['Approved','Declined']) ? $_POST['status'] : 'Pending';
    $notes  = trim($_POST['admin_notes'] ?? '');
    try {
        $pdo->prepare("UPDATE holiday_requests SET status=?,admin_notes=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND organisation_id=?")
            ->execute([$status,$notes,$uid,$rid,$orgId]);
        setFlash('success','Request '.$status.'.');
    } catch (Exception $e) { setFlash('error','Error: '.$e->getMessage()); }
    header('Location: holiday.php'); exit;
}

if ($isAdmin) {
    $requests = [];
    try {
        $q = $pdo->prepare("SELECT r.*, u.first_name, u.last_name, u.job_category FROM holiday_requests r JOIN users u ON r.staff_id=u.id WHERE r.organisation_id=? ORDER BY FIELD(r.status,'Pending','Approved','Declined'), r.created_at DESC");
        $q->execute([$orgId]); $requests=$q->fetchAll();
    } catch(Exception $e){}
} else {
    $myRequests = [];
    try {
        $q = $pdo->prepare("SELECT * FROM holiday_requests WHERE organisation_id=? AND staff_id=? ORDER BY created_at DESC");
        $q->execute([$orgId,$uid]); $myRequests=$q->fetchAll();
    } catch(Exception $e){}
}
include __DIR__ . '/../includes/header.php';
?>
<?php if ($isAdmin): ?>
<!-- Admin view -->
<div class="flex items-center justify-between mb-5">
    <h2 class="text-xl font-extrabold text-gray-800"><i class="fa fa-umbrella-beach text-teal-500 mr-2"></i>Holiday Requests</h2>
    <?php $pending = array_filter($requests, fn($r)=>$r['status']==='Pending'); ?>
    <?php if (!empty($pending)): ?><span class="bg-amber-100 text-amber-700 border border-amber-200 px-3 py-1 rounded-full text-xs font-bold"><?=count($pending)?> Pending</span><?php endif; ?>
</div>
<?php if (empty($requests)): ?>
<div class="bg-white rounded-2xl shadow p-10 text-center text-gray-400"><div class="text-4xl mb-2">🏖️</div><p>No holiday requests yet.</p></div>
<?php else: ?>
<div class="space-y-4">
<?php foreach ($requests as $r):
    $sc = ($r['status']==='Approved') ? 'bg-green-100 text-green-700 border-green-200' : (($r['status']==='Declined') ? 'bg-red-100 text-red-700 border-red-200' : 'bg-amber-100 text-amber-700 border-amber-200');
?>
<div class="bg-white rounded-2xl shadow p-5">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
        <div>
            <div class="font-extrabold text-gray-800"><?=h($r['first_name'].' '.$r['last_name'])?> <span class="text-gray-400 font-normal text-sm"><?=h($r['job_category']??'')?></span></div>
            <div class="text-sm text-gray-600 mt-0.5">
                <i class="fa fa-calendar-range text-teal-400 mr-1"></i>
                <?=date('d M Y',strtotime($r['start_date']))?> — <?=date('d M Y',strtotime($r['end_date']))?>
                <span class="font-bold text-teal-700 ml-2"><?=$r['days']?> day<?=$r['days']>1?'s':''?></span>
            </div>
            <?php if ($r['reason']): ?><div class="text-sm text-gray-500 mt-1"><?=h($r['reason'])?></div><?php endif; ?>
            <?php if ($r['file_original']): ?>
            <a href="/uploads/holiday_forms/<?=rawurlencode($r['file_name'])?>" target="_blank"
               class="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline mt-2 font-semibold">
                <i class="fa fa-paperclip"></i><?=h($r['file_original'])?>
            </a>
            <?php endif; ?>
        </div>
        <span class="px-3 py-1 rounded-full text-xs font-bold border <?=$sc?>"><?=$r['status']?></span>
    </div>
    <?php if ($r['status']==='Pending'): ?>
    <form method="POST" class="flex flex-wrap items-end gap-3 pt-3 border-t border-gray-100">
        <?= csrfField() ?>
        <input type="hidden" name="review_request" value="1">
        <input type="hidden" name="request_id" value="<?=$r['id']?>">
        <div class="flex-1 min-w-48">
            <label class="block text-xs font-bold text-gray-600 mb-1">Admin Notes (optional)</label>
            <input type="text" name="admin_notes" placeholder="Add a note..." class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
        </div>
        <button type="submit" name="status" value="Approved" class="bg-green-600 hover:bg-green-700 text-white font-bold px-4 py-2 rounded-xl text-sm transition">
            <i class="fa fa-check mr-1"></i>Approve
        </button>
        <button type="submit" name="status" value="Declined" class="bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-2 rounded-xl text-sm transition">
            <i class="fa fa-xmark mr-1"></i>Decline
        </button>
    </form>
    <?php elseif ($r['admin_notes']): ?>
    <div class="text-xs text-gray-500 pt-2 border-t border-gray-100">Admin note: <?=h($r['admin_notes'])?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Staff view -->
<div class="max-w-2xl mx-auto">
<div class="bg-white rounded-2xl shadow overflow-hidden mb-6">
    <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-5 py-4">
        <h3 class="text-white font-extrabold"><i class="fa fa-umbrella-beach mr-2"></i>Request Holiday</h3>
    </div>
    <form method="POST" enctype="multipart/form-data" class="p-5">
        <?= csrfField() ?>
        <input type="hidden" name="submit_request" value="1">
        <div class="grid grid-cols-2 gap-3 mb-4">
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Start Date *</label>
                <input type="date" name="start_date" required class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">End Date *</label>
                <input type="date" name="end_date" required class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
        </div>
        <div class="mb-4">
            <label class="block text-xs font-bold text-gray-600 mb-1">Reason (optional)</label>
            <textarea name="reason" rows="3" placeholder="Brief reason for the request..."
                class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
        </div>
        <div class="mb-5 bg-blue-50 border border-blue-200 rounded-2xl p-4">
            <label class="block text-xs font-bold text-blue-700 mb-2"><i class="fa fa-upload mr-1"></i>Upload Holiday Request Form (optional)</label>
            <input type="file" name="holiday_form" accept="*/*"
                class="text-sm text-gray-600 w-full file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:bg-teal-600 file:text-white file:font-bold hover:file:bg-teal-700 cursor-pointer">
            <p class="text-xs text-blue-500 mt-1.5">Supports PDF, Word, images, and all document types · Max 20 MB</p>
        </div>
        <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl text-sm transition">
            <i class="fa fa-paper-plane mr-1"></i>Submit Holiday Request
        </button>
    </form>
</div>

<h3 class="font-extrabold text-gray-700 mb-3">My Requests</h3>
<?php if (empty($myRequests)): ?>
<div class="bg-white rounded-2xl shadow p-8 text-center text-gray-400"><p>No requests yet.</p></div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($myRequests as $r):
    $sc = ($r['status']==='Approved') ? 'bg-green-100 text-green-700 border-green-200' : (($r['status']==='Declined') ? 'bg-red-100 text-red-700 border-red-200' : 'bg-amber-100 text-amber-700 border-amber-200');
?>
<div class="bg-white rounded-2xl shadow p-4 flex items-start justify-between gap-3">
    <div>
        <div class="font-semibold text-gray-800"><?=date('d M Y',strtotime($r['start_date']))?> — <?=date('d M Y',strtotime($r['end_date']))?></div>
        <div class="text-xs text-gray-400 mt-0.5"><?=$r['days']?> day<?=$r['days']>1?'s':''?><?=$r['file_original']?' · <i class="fa fa-paperclip"></i> '.h($r['file_original']):'';?></div>
        <?php if ($r['admin_notes']): ?><div class="text-xs text-gray-600 mt-1 italic">"<?=h($r['admin_notes'])?>"</div><?php endif; ?>
    </div>
    <span class="px-2.5 py-1 rounded-full text-xs font-bold border <?=$sc?> flex-shrink-0"><?=$r['status']?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
