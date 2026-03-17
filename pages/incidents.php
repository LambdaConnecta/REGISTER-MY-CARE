<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();

// Self-heal incidents columns
try { $pdo->exec("ALTER TABLE `incidents` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `incidents` ADD COLUMN `file_original` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
// Fix severity/status if ENUM
try { $col=$pdo->query("SHOW COLUMNS FROM `incidents` LIKE 'severity'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `incidents` MODIFY COLUMN `severity` VARCHAR(20) NOT NULL DEFAULT 'Low'"); } } catch(Exception $e){}
try { $col=$pdo->query("SHOW COLUMNS FROM `incidents` LIKE 'status'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `incidents` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'Open'"); } } catch(Exception $e){}
// Fix job_category ENUM -> VARCHAR
try { $col=$pdo->query("SHOW COLUMNS FROM `users` LIKE 'job_category'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `job_category` VARCHAR(80) DEFAULT NULL"); } } catch(Exception $e){}
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Incidents';

$uploadDir = __DIR__.'/../uploads/incidents/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); file_put_contents($uploadDir.'.htaccess',"Options -Indexes\n"); }

// Submit incident
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_incident'])) {
    validateCSRF();
    $suId     = (int)($_POST['service_user_id']??0) ?: null;
    $cat      = trim($_POST['category'] ?? 'General');
    $desc     = trim($_POST['description'] ?? '');
    $action   = trim($_POST['action_taken'] ?? '');
    $sev      = in_array($_POST['severity']??'',['Low','Medium','High','Critical']) ? $_POST['severity'] : 'Medium';
    if (!$desc) { setFlash('error','Please describe the incident.'); header('Location: incidents.php'); exit; }
    $fn = null; $orig = null;
    if (!empty($_FILES['incident_file']['name']) && $_FILES['incident_file']['error']===UPLOAD_ERR_OK) {
        $f   = $_FILES['incident_file'];
        $ext = strtolower(pathinfo(basename($f['name']),PATHINFO_EXTENSION));
        $fn  = 'inc_'.$orgId.'_'.$uid.'_'.time().'.'.$ext;
        $orig = basename($f['name']);
        if ($f['size']>50*1024*1024) { setFlash('error','File too large (max 50MB).'); header('Location: incidents.php'); exit; }
        if (!move_uploaded_file($f['tmp_name'],$uploadDir.$fn)) { $fn=null; $orig=null; }
    }
    try {
        $pdo->prepare("INSERT INTO incidents (organisation_id,reported_by,service_user_id,incident_date,category,description,action_taken,severity,file_name,file_original,status,created_at) VALUES(?,?,?,NOW(),?,?,?,?,?,?,'Open',NOW())")
            ->execute([$orgId,$uid,$suId,$cat,$desc,$action,$sev,$fn,$orig]);
        setFlash('success','Incident report submitted.');
    } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
    header('Location: incidents.php'); exit;
}

// Admin close incident
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['close_incident'])) {
    validateCSRF();
    $iid = (int)$_POST['incident_id'];
    try { $pdo->prepare("UPDATE incidents SET status='Closed' WHERE id=? AND organisation_id=?")->execute([$iid,$orgId]); setFlash('success','Incident closed.'); } catch(Exception $e){}
    header('Location: incidents.php'); exit;
}

$incidents = [];
try {
    $where = $isAdmin ? "i.organisation_id=?" : "i.organisation_id=? AND i.reported_by=?";
    $params = $isAdmin ? [$orgId] : [$orgId,$uid];
    $q = $pdo->prepare("SELECT i.*, u.first_name ff, u.last_name fl, su.first_name sf, su.last_name sl FROM incidents i JOIN users u ON i.reported_by=u.id LEFT JOIN service_users su ON i.service_user_id=su.id WHERE $where ORDER BY FIELD(i.status,'Open','Under Review','Closed'), i.created_at DESC");
    $q->execute($params); $incidents=$q->fetchAll();
} catch(Exception $e){ setFlash('error','Error loading incidents: '.$e->getMessage()); }

$suList = [];
try { $q=$pdo->prepare("SELECT id,first_name,last_name FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name"); $q->execute([$orgId]); $suList=$q->fetchAll(); } catch(Exception $e){}

$cats = ['Fall / Accident','Medication Error','Safeguarding Concern','Challenging Behaviour','Health Deterioration','Near Miss','Abuse / Neglect','Property Damage','Visit Concern','Other'];
include __DIR__ . '/../includes/header.php';
?>
<div class="flex flex-col md:flex-row gap-5">

<!-- Report form -->
<div class="md:w-80 flex-shrink-0">
    <div class="bg-white rounded-2xl shadow overflow-hidden sticky top-20">
        <div class="bg-gradient-to-r from-red-600 to-red-700 px-5 py-4">
            <h3 class="text-white font-extrabold"><i class="fa fa-triangle-exclamation mr-2"></i>Report Incident</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-4">
            <?= csrfField() ?>
            <input type="hidden" name="submit_incident" value="1">
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Service User (optional)</label>
                <select name="service_user_id" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <option value="">Not specific to one person</option>
                    <?php foreach ($suList as $su): ?><option value="<?=$su['id']?>"><?=h($su['last_name'].', '.$su['first_name'])?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Category</label>
                <select name="category" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <?php foreach ($cats as $c): ?><option><?=$c?></option><?php endforeach; ?>
                </select></div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Severity</label>
                <div class="grid grid-cols-4 gap-1">
                <?php foreach (['Low'=>'green','Medium'=>'amber','High'=>'orange','Critical'=>'red'] as $s=>$col): ?>
                <label class="text-center cursor-pointer">
                    <input type="radio" name="severity" value="<?=$s?>" class="sr-only peer" <?=$s==='Medium'?'checked':''?>>
                    <div class="py-1.5 rounded-lg text-xs font-bold border-2 border-gray-200 peer-checked:border-<?=$col?>-500 peer-checked:bg-<?=$col?>-50 peer-checked:text-<?=$col?>-700 text-gray-500 transition"><?=$s?></div>
                </label>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Description *</label>
                <textarea name="description" rows="4" required placeholder="What happened? When, where, who was involved..."
                    class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea></div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Immediate Action Taken</label>
                <textarea name="action_taken" rows="2" placeholder="What did you do?"
                    class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea></div>
            <div class="mb-4 bg-blue-50 border border-blue-200 rounded-xl p-3">
                <label class="block text-xs font-bold text-blue-700 mb-1.5"><i class="fa fa-paperclip mr-1"></i>Attach Document (optional)</label>
                <input type="file" name="incident_file" accept="*/*"
                    class="text-xs text-gray-600 w-full file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:bg-red-600 file:text-white file:text-xs hover:file:bg-red-700 cursor-pointer">
                <p class="text-xs text-blue-500 mt-1">All file types supported · Max 50MB</p>
            </div>
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 rounded-xl text-sm transition">
                <i class="fa fa-triangle-exclamation mr-1"></i>Submit Incident Report
            </button>
        </form>
    </div>
</div>

<!-- Incidents list -->
<div class="flex-1 min-w-0">
    <h2 class="text-xl font-extrabold text-gray-800 mb-4">
        <i class="fa fa-list text-red-500 mr-2"></i><?=$isAdmin?'All Incident Reports':'My Incident Reports'?>
        <span class="text-sm font-normal text-gray-400 ml-2"><?=count($incidents)?> record<?=count($incidents)!==1?'s':''?></span>
    </h2>
    <?php if (empty($incidents)): ?>
    <div class="bg-white rounded-2xl shadow p-10 text-center text-gray-400"><div class="text-4xl mb-2">✅</div><p>No incidents reported.</p></div>
    <?php else: ?>
    <div class="space-y-4">
    <?php foreach ($incidents as $i):
        $sevCls=($i['severity']==='Critical')?'bg-red-100 text-red-700 border-red-300':(($i['severity']==='High')?'bg-orange-100 text-orange-700 border-orange-200':(($i['severity']==='Medium')?'bg-amber-100 text-amber-700 border-amber-200':'bg-gray-100 text-gray-600 border-gray-200'));
        $statusCls=($i['status']==='Closed')?'bg-green-100 text-green-700':(($i['status']==='Under Review')?'bg-blue-100 text-blue-700':'bg-amber-100 text-amber-700');
    ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-bold px-2.5 py-1 rounded-full border <?=$sevCls?>"><?=$i['severity']?></span>
                    <span class="text-xs font-semibold bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full"><?=h($i['category'])?></span>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?=$statusCls?>"><?=$i['status']?></span>
                </div>
                <div class="text-sm font-semibold text-gray-700 mt-1.5">
                    Reported by <?=h($i['ff'].' '.$i['fl'])?>
                    <?php if ($i['sf']): ?> · Re: <?=h($i['sf'].' '.$i['sl'])?><?php endif; ?>
                </div>
                <div class="text-xs text-gray-400"><?=date('d M Y H:i',strtotime($i['incident_date']))?></div>
            </div>
            <?php if ($isAdmin && $i['status']==='Open'): ?>
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="close_incident" value="1">
                <input type="hidden" name="incident_id" value="<?=$i['id']?>">
                <button type="submit" class="text-xs bg-green-50 hover:bg-green-100 text-green-700 border border-green-200 px-3 py-1.5 rounded-xl font-bold transition">Mark Closed</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="bg-gray-50 rounded-xl p-3 text-sm text-gray-700 leading-relaxed mb-2"><?=h($i['description'])?></div>
        <?php if ($i['action_taken']): ?>
        <div class="text-xs text-gray-500 bg-blue-50 rounded-xl p-2.5 mb-2">
            <span class="font-bold text-blue-700">Action taken: </span><?=h($i['action_taken'])?>
        </div>
        <?php endif; ?>
        <?php if ($i['file_original']): ?>
        <a href="/uploads/incidents/<?=rawurlencode($i['file_name'])?>" target="_blank"
           class="inline-flex items-center gap-1.5 text-xs text-blue-600 hover:underline font-semibold bg-blue-50 border border-blue-200 px-3 py-1.5 rounded-xl">
            <i class="fa fa-paperclip"></i>Attached: <?=h($i['file_original'])?>
        </a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
