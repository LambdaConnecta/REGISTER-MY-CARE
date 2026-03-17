<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$pageTitle = 'My Day Summary';
$date = $_GET['date'] ?? date('Y-m-d');

$CARE_TYPES = [
    'Personal Care','Medication Administration','Nutrition & Hydration',
    'Moving & Handling','Physiotherapy Support','Occupational Therapy',
    'Emotional Support','Social Engagement','Continence Care',
    'Wound Care / Dressing','Dementia Care','End of Life Care',
    'Community Outing','Domestic Support','Night Care','Other',
];
$MOODS = ['Happy','Calm','Anxious','Sad','Confused','Unwell','Agitated'];

$careLogDir = __DIR__.'/../uploads/care_logs/';
if (!is_dir($careLogDir)) { mkdir($careLogDir,0755,true); file_put_contents($careLogDir.'.htaccess',"Options -Indexes\n"); }

// ── POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    validateCSRF();
    $act  = $_POST['act']  ?? '';
    $suId = (int)($_POST['su_id'] ?? 0);

    if ($act==='log_care_day' && $suId) {
        $careType = trim($_POST['care_type']??'General Care');
        $notes    = trim($_POST['care_notes']??'');
        $duration = (int)($_POST['duration_mins']??0) ?: null;
        $mood     = trim($_POST['mood']??'') ?: null;
        $logDate  = $_POST['log_date'] ?? $date;

        if (!$notes) { setFlash('error','Please write care notes.'); header("Location: my_day.php?date=$date"); exit; }

        try {
            $pdo->prepare("INSERT INTO care_logs (organisation_id,service_user_id,staff_id,log_date,care_type,notes,duration_mins,mood) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$uid,$logDate,$careType,$notes,$duration,$mood]);
            $logId = $pdo->lastInsertId();

            if (!empty($_FILES['care_doc']['name']) && $_FILES['care_doc']['error']===UPLOAD_ERR_OK) {
                $f   = $_FILES['care_doc'];
                $ext = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
                $fn  = 'cl_'.$logId.'_'.time().'.'.$ext;
                $mime= @mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
                if (move_uploaded_file($f['tmp_name'],$careLogDir.$fn)) {
                    $pdo->prepare("INSERT INTO care_log_documents (care_log_id,organisation_id,title,file_name,file_original,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$logId,$orgId,trim($_POST['doc_title']??$f['name']),$fn,$f['name'],$f['size'],$mime,$uid]);
                }
            }
            setFlash('success','Care log added.');
        } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
        header("Location: my_day.php?date=$date"); exit;
    }
}

// ── Load visits for the day ───────────────────────────────────────────
$visits = [];
try {
    $q = $pdo->prepare("SELECT v.*, CONCAT(su.first_name,' ',su.last_name) AS su_name,
        su.id AS su_id, su.address, su.allergies,
        (SELECT COUNT(*) FROM care_logs cl WHERE cl.visit_id=v.id AND cl.staff_id=?) AS log_count
        FROM visits v
        JOIN service_users su ON su.id=v.service_user_id
        WHERE v.organisation_id=? AND v.carer_id=? AND DATE(v.visit_date)=?
        ORDER BY v.start_time");
    $q->execute([$uid,$orgId,$uid,$date]); $visits=$q->fetchAll();
} catch(Exception $e){}

// Load my care logs for the day
$myLogs = [];
try {
    $q = $pdo->prepare("SELECT cl.*, CONCAT(su.first_name,' ',su.last_name) AS su_name,
        (SELECT COUNT(*) FROM care_log_documents cld WHERE cld.care_log_id=cl.id) AS doc_count
        FROM care_logs cl
        JOIN service_users su ON su.id=cl.service_user_id
        WHERE cl.staff_id=? AND cl.organisation_id=? AND cl.log_date=?
        ORDER BY cl.created_at DESC");
    $q->execute([$uid,$orgId,$date]); $myLogs=$q->fetchAll();
} catch(Exception $e){}

// Load all active SUs for standalone log (not tied to visit)
$suList = [];
try {
    $q=$pdo->prepare("SELECT id, first_name, last_name FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name,first_name");
    $q->execute([$orgId]); $suList=$q->fetchAll();
} catch(Exception $e){}

$done  = count(array_filter($visits, fn($v)=>$v['status']==='Completed'));
$total = count($visits);

include __DIR__ . '/../includes/header.php';
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h2 class="text-xl font-extrabold text-gray-800">My Day Summary</h2>
        <p class="text-sm text-gray-500"><?= date('l, d F Y',strtotime($date)) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <a href="?date=<?= date('Y-m-d',strtotime($date.' -1 day')) ?>" class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">← Prev</a>
        <input type="date" value="<?= $date ?>" onchange="location='?date='+this.value" class="border rounded-xl px-3 py-2 text-sm focus:outline-none">
        <a href="?date=<?= date('Y-m-d',strtotime($date.' +1 day')) ?>" class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">Next →</a>
    </div>
</div>

<!-- Progress bar -->
<?php if ($total > 0): ?>
<div class="bg-white rounded-2xl shadow p-4 mb-5">
    <div class="flex justify-between text-sm font-bold text-gray-700 mb-2">
        <span>Today's Progress</span><span><?= $done ?>/<?= $total ?> visits completed</span>
    </div>
    <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-3 bg-teal-500 rounded-full transition-all" style="width:<?= $total>0?round($done/$total*100):0 ?>%"></div>
    </div>
    <div class="flex gap-4 mt-3 text-xs text-center">
        <div class="flex-1 bg-blue-50 rounded-xl py-2"><div class="font-black text-blue-700 text-lg"><?= count(array_filter($visits,fn($v)=>$v['status']==='Scheduled')) ?></div><div class="text-blue-500 font-bold">Scheduled</div></div>
        <div class="flex-1 bg-amber-50 rounded-xl py-2"><div class="font-black text-amber-700 text-lg"><?= count(array_filter($visits,fn($v)=>$v['status']==='In Progress')) ?></div><div class="text-amber-500 font-bold">In Progress</div></div>
        <div class="flex-1 bg-green-50 rounded-xl py-2"><div class="font-black text-green-700 text-lg"><?= $done ?></div><div class="text-green-500 font-bold">Completed</div></div>
        <div class="flex-1 bg-purple-50 rounded-xl py-2"><div class="font-black text-purple-700 text-lg"><?= count($myLogs) ?></div><div class="text-purple-500 font-bold">Care Logs</div></div>
    </div>
</div>
<?php endif; ?>

<!-- Write standalone care log -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
    <button onclick="toggleSection('standalone-log')" class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-gray-50 transition">
        <div class="flex items-center gap-2">
            <i class="fa fa-pen-to-square text-teal-500"></i>
            <span class="font-extrabold text-gray-800">Write a Care Log</span>
            <span class="text-xs text-gray-400 font-normal">(for any service user)</span>
        </div>
        <i class="fa fa-chevron-down text-gray-400" id="arrow-standalone-log"></i>
    </button>
    <div id="standalone-log" class="hidden border-t">
        <form method="POST" enctype="multipart/form-data" class="p-5">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="log_care_day">
            <input type="hidden" name="log_date" value="<?= $date ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Service User *</label>
                    <select name="su_id" required class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">— Select —</option>
                        <?php foreach($suList as $su): ?><option value="<?= $su['id'] ?>"><?= h($su['last_name'].', '.$su['first_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Type of Care</label>
                    <select name="care_type" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <?php foreach($CARE_TYPES as $ct): ?><option><?= $ct ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">SU Mood</label>
                    <select name="mood" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">— Select —</option>
                        <?php foreach($MOODS as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Duration (mins)</label>
                    <input type="number" name="duration_mins" min="1" placeholder="e.g. 30"
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Care Notes * <span class="text-gray-400 font-normal">(what care did you provide?)</span></label>
                    <textarea name="care_notes" required rows="4" placeholder="Describe the care provided, any observations, how the service user responded, any concerns or changes noticed..."
                              class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
                </div>
            </div>
            <!-- Document upload -->
            <div class="border border-dashed border-teal-300 rounded-2xl p-4 bg-teal-50/50 mb-4">
                <div class="text-xs font-extrabold text-teal-700 mb-3"><i class="fa fa-paperclip mr-1"></i>Attach Document (optional)</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><label class="block text-xs font-bold text-gray-600 mb-1">Document Title</label>
                        <input type="text" name="doc_title" placeholder="e.g. Care Plan Update" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none"></div>
                    <div><label class="block text-xs font-bold text-gray-600 mb-1">File (all types)</label>
                        <label class="flex items-center gap-2 cursor-pointer border rounded-xl px-3 py-2 bg-white hover:bg-teal-50 transition">
                            <i class="fa fa-paperclip text-teal-500"></i>
                            <span id="day-fn" class="text-sm text-gray-500">Choose file...</span>
                            <input type="file" name="care_doc" accept="*/*" class="hidden"
                                   onchange="document.getElementById('day-fn').textContent=this.files[0]?.name||'Choose file...'">
                        </label>
                    </div>
                </div>
            </div>
            <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition">
                <i class="fa fa-floppy-disk mr-1"></i>Save Care Log
            </button>
        </form>
    </div>
</div>

<!-- Today's visits with quick log per visit -->
<?php if (!empty($visits)): ?>
<h3 class="text-xs font-extrabold text-gray-500 uppercase tracking-widest mb-3">Today's Visits</h3>
<div class="space-y-3 mb-5">
<?php foreach($visits as $v):
    $sc=['Scheduled'=>'bg-blue-100 text-blue-700','In Progress'=>'bg-amber-100 text-amber-700','Completed'=>'bg-green-100 text-green-700','Missed'=>'bg-red-100 text-red-700'][$v['status']]??'bg-gray-100 text-gray-600';
?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-5 py-3 flex items-center gap-3 border-b">
        <div class="flex-1 min-w-0">
            <div class="font-bold text-gray-800"><?= h($v['su_name']) ?></div>
            <div class="text-xs text-gray-500"><?= h($v['start_time']) ?> – <?= h($v['end_time']) ?></div>
        </div>
        <span class="text-xs px-2.5 py-1 rounded-full font-bold <?= $sc ?>"><?= h($v['status']) ?></span>
        <?php if ($v['log_count']): ?><span class="text-xs text-teal-600 font-bold"><?= $v['log_count'] ?>✓</span><?php endif; ?>
        <button onclick="toggleSection('vlog-<?= $v['id'] ?>')"
                class="bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 px-3 py-1.5 rounded-xl text-xs font-bold transition">
            <i class="fa fa-pen mr-1"></i>Log
        </button>
    </div>
    <div id="vlog-<?= $v['id'] ?>" class="hidden">
        <form method="POST" enctype="multipart/form-data" class="p-4">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="log_care_day">
            <input type="hidden" name="su_id" value="<?= $v['su_id'] ?>">
            <input type="hidden" name="log_date" value="<?= $date ?>">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                <div class="col-span-2 sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Type of Care</label>
                    <select name="care_type" class="w-full border rounded-xl px-3 py-2 text-xs bg-white focus:border-teal-500 focus:outline-none">
                        <?php foreach($CARE_TYPES as $ct): ?><option><?= $ct ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Mood</label>
                    <select name="mood" class="w-full border rounded-xl px-3 py-2 text-xs bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">—</option>
                        <?php foreach($MOODS as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Mins</label>
                    <input type="number" name="duration_mins" placeholder="45" class="w-full border rounded-xl px-3 py-2 text-xs focus:border-teal-500 focus:outline-none">
                </div>
                <div class="col-span-2 sm:col-span-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Notes *</label>
                    <textarea name="care_notes" required rows="3" placeholder="What care did you provide? Any observations or concerns?"
                              class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
                </div>
                <div class="col-span-2 sm:col-span-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Attach Document (optional)</label>
                    <div class="flex gap-3">
                        <input type="text" name="doc_title" placeholder="Document title..." class="flex-1 border rounded-xl px-3 py-2 text-xs bg-white focus:border-teal-500 focus:outline-none">
                        <label class="cursor-pointer bg-white border rounded-xl px-3 py-2 text-xs flex items-center gap-1 hover:bg-gray-50">
                            <i class="fa fa-paperclip text-teal-500"></i>
                            <span id="vfn-<?= $v['id'] ?>">File</span>
                            <input type="file" name="care_doc" accept="*/*" class="hidden"
                                   onchange="document.getElementById('vfn-<?= $v['id'] ?>').textContent=this.files[0]?.name.substring(0,15)||'File'">
                        </label>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-teal-600 text-white font-bold px-4 py-2 rounded-xl text-xs transition hover:bg-teal-700"><i class="fa fa-floppy-disk mr-1"></i>Save</button>
                <button type="button" onclick="toggleSection('vlog-<?= $v['id'] ?>')" class="bg-gray-100 text-gray-600 font-bold px-4 py-2 rounded-xl text-xs transition">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- My logs for today -->
<?php if (!empty($myLogs)): ?>
<h3 class="text-xs font-extrabold text-gray-500 uppercase tracking-widest mb-3">My Care Logs for Today</h3>
<div class="space-y-3">
<?php foreach($myLogs as $log):
    $moodEmoji=['Happy'=>'😊','Calm'=>'😌','Anxious'=>'😟','Sad'=>'😢','Confused'=>'😕','Unwell'=>'🤒','Agitated'=>'😠'][$log['mood']??'']??'';
?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="px-5 py-3 flex items-center gap-3 bg-gray-50 border-b">
        <div class="flex-1">
            <span class="font-bold text-gray-800 text-sm"><?= h($log['su_name']) ?></span>
            <span class="ml-2 text-xs bg-teal-100 text-teal-700 px-2 py-0.5 rounded-full font-bold"><?= h($log['care_type']) ?></span>
            <?php if ($moodEmoji): ?><span class="ml-1 text-sm"><?= $moodEmoji ?></span><?php endif; ?>
            <?php if ($log['duration_mins']): ?><span class="ml-2 text-xs text-gray-400"><?= $log['duration_mins'] ?>min</span><?php endif; ?>
        </div>
        <?php if ($log['doc_count']): ?><span class="text-xs text-blue-600"><i class="fa fa-paperclip mr-1"></i><?= $log['doc_count'] ?></span><?php endif; ?>
        <span class="text-xs text-gray-400"><?= date('H:i',strtotime($log['created_at'])) ?></span>
    </div>
    <div class="px-5 py-3 text-sm text-gray-700 whitespace-pre-wrap"><?= h($log['notes']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($visits) && empty($myLogs)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
    <div class="text-5xl mb-3">📋</div>
    <h3 class="text-lg font-bold text-gray-600">No visits or logs for this date</h3>
    <p class="text-sm text-gray-400 mt-1">Use the "Write a Care Log" section above to add a standalone care record.</p>
</div>
<?php endif; ?>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    const arrow = document.getElementById('arrow-'+id);
    el.classList.toggle('hidden');
    if (arrow) arrow.style.transform = el.classList.contains('hidden') ? '' : 'rotate(180deg)';
    if (!el.classList.contains('hidden')) el.scrollIntoView({behavior:'smooth',block:'nearest'});
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
