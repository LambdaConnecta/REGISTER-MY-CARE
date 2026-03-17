<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo     = getPDO();
$orgId   = (int)$_SESSION['organisation_id'];
$uid     = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Rota & Assignments';

// ── Ensure visits table has required columns ──────────────────────────
try {
    $pdo->exec("ALTER TABLE `visits`
        ADD COLUMN `visit_date`        DATE DEFAULT NULL,
        ADD COLUMN `carer_id`          INT UNSIGNED DEFAULT NULL,
        ADD COLUMN `status`            VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
        ADD COLUMN `actual_start_time` TIME DEFAULT NULL,
        ADD COLUMN `actual_end_time`   TIME DEFAULT NULL,
        ADD COLUMN `notes`             TEXT DEFAULT NULL");
} catch(Exception $e) {}

// ── Upload dirs ───────────────────────────────────────────────────────
$careLogDir = __DIR__.'/../uploads/care_logs/';
if (!is_dir($careLogDir)) { mkdir($careLogDir,0755,true); file_put_contents($careLogDir.'.htaccess',"Options -Indexes\n"); }

$CARE_TYPES = [
    'Personal Care','Medication Administration','Nutrition & Hydration',
    'Moving & Handling','Physiotherapy Support','Occupational Therapy',
    'Emotional Support','Social Engagement','Continence Care',
    'Wound Care / Dressing','Dementia Care','End of Life Care',
    'Community Outing','Domestic Support','Night Care','Other',
];
$MOODS = ['Happy','Calm','Anxious','Sad','Confused','Unwell','Agitated'];

// ── Week navigation ───────────────────────────────────────────────────
$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
$weekStart = date('Y-m-d', strtotime($weekStart));
$weekEnd   = date('Y-m-d', strtotime($weekStart.' +6 days'));

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $act = $_POST['act'] ?? '';

    // Admin: create a rota visit/assignment
    if ($act === 'create_visit' && $isAdmin) {
        $suId      = (int)($_POST['su_id'] ?? 0);
        $staffIds  = array_filter(array_map('intval', (array)($_POST['carer_ids'] ?? [])));
        $visitDate = $_POST['visit_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime   = $_POST['end_time']   ?? '';
        $notes     = trim($_POST['notes'] ?? '');
        $repeat    = $_POST['repeat'] ?? 'none'; // none, daily, weekly

        if (!$suId || !$visitDate || !$startTime || empty($staffIds)) {
            setFlash('error', 'Please fill in service user, date, start time, and assign at least one staff member.');
            header("Location: rota.php?week=$weekStart"); exit;
        }

        // Build dates to create
        $dates = [$visitDate];
        if ($repeat === 'daily') {
            $d = new DateTime($visitDate);
            $end = new DateTime($weekEnd);
            while ($d < $end) { $d->modify('+1 day'); $dates[] = $d->format('Y-m-d'); }
        } elseif ($repeat === 'weekly') {
            for ($w=1; $w<=3; $w++) {
                $dates[] = date('Y-m-d', strtotime($visitDate." +$w weeks"));
            }
        }

        $created = 0;
        try {
            $stmt = $pdo->prepare("INSERT INTO visits
                (organisation_id, service_user_id, carer_id, visit_date, start_time, end_time, notes, status)
                VALUES (?,?,?,?,?,?,?,'Scheduled')");

            foreach ($dates as $vd) {
                foreach ($staffIds as $staffId) {
                    $stmt->execute([$orgId, $suId, $staffId, $vd, $startTime, $endTime, $notes]);
                    $created++;
                }
            }
            setFlash('success', "$created visit".($created!==1?'s':'')." created successfully.");
        } catch (Exception $e) {
            setFlash('error', 'Could not create visit: '.$e->getMessage());
        }
        header("Location: rota.php?week=$weekStart"); exit;
    }

    // Admin: delete a visit
    if ($act === 'delete_visit' && $isAdmin) {
        $vId = (int)($_POST['visit_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM visits WHERE id=? AND organisation_id=?")->execute([$vId,$orgId]);
            setFlash('success', 'Visit removed from rota.');
        } catch (Exception $e) { setFlash('error', 'Error.'); }
        header("Location: rota.php?week=$weekStart"); exit;
    }

    // Staff: log care from rota
    if ($act === 'log_care_rota') {
        $suId     = (int)($_POST['su_id'] ?? 0);
        $vId      = (int)($_POST['visit_id'] ?? 0) ?: null;
        $careType = trim($_POST['care_type'] ?? 'General Care');
        $notes    = trim($_POST['care_notes'] ?? '');
        $duration = (int)($_POST['duration_mins'] ?? 0) ?: null;
        $mood     = trim($_POST['mood'] ?? '') ?: null;
        $logDate  = $_POST['log_date'] ?: date('Y-m-d');

        if (!$notes || !$suId) { setFlash('error','Service user and notes are required.'); header("Location: rota.php?week=$weekStart"); exit; }

        try {
            $pdo->prepare("INSERT INTO care_logs (organisation_id,service_user_id,staff_id,visit_id,log_date,care_type,notes,duration_mins,mood) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$uid,$vId,$logDate,$careType,$notes,$duration,$mood]);
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
            setFlash('success','Care log saved.');
        } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
        header("Location: rota.php?week=$weekStart"); exit;
    }
}

// ── Load rota visits ──────────────────────────────────────────────────
$rotaData = [];
$loadError = '';
try {
    if ($isAdmin) {
        $q = $pdo->prepare("SELECT v.*,
            DATE(v.visit_date) AS vdate,
            CONCAT(su.first_name,' ',su.last_name) AS su_name,
            su.id AS su_id,
            su.address AS su_address,
            CONCAT(u.first_name,' ',u.last_name) AS staff_name,
            u.id AS staff_id
            FROM visits v
            JOIN service_users su ON su.id = v.service_user_id AND su.organisation_id = ?
            LEFT JOIN users u ON u.id = v.carer_id AND u.organisation_id = ?
            WHERE v.organisation_id = ?
              AND v.visit_date BETWEEN ? AND ?
            ORDER BY v.visit_date, v.start_time");
        $q->execute([$orgId, $orgId, $orgId, $weekStart, $weekEnd]);
    } else {
        $q = $pdo->prepare("SELECT v.*,
            DATE(v.visit_date) AS vdate,
            CONCAT(su.first_name,' ',su.last_name) AS su_name,
            su.id AS su_id,
            su.address AS su_address,
            su.allergies,
            (SELECT COUNT(*) FROM care_logs cl WHERE cl.visit_id=v.id AND cl.staff_id=?) AS log_count
            FROM visits v
            JOIN service_users su ON su.id = v.service_user_id
            WHERE v.organisation_id = ?
              AND v.carer_id = ?
              AND v.visit_date BETWEEN ? AND ?
            ORDER BY v.visit_date, v.start_time");
        $q->execute([$uid, $orgId, $uid, $weekStart, $weekEnd]);
    }
    $allVisits = $q->fetchAll();
    foreach ($allVisits as $v) {
        $rotaData[$v['vdate']][] = $v;
    }
} catch (Exception $e) {
    $loadError = $e->getMessage();
}

// ── Load staff list (for admin create form) ──────────────────────────
$staffList = [];
$suList    = [];
if ($isAdmin) {
    try {
        $q = $pdo->prepare("SELECT id, first_name, last_name, job_category FROM users WHERE organisation_id=? AND is_active=1 ORDER BY last_name,first_name");
        $q->execute([$orgId]); $staffList = $q->fetchAll();
    } catch(Exception $e){}
    try {
        $q = $pdo->prepare("SELECT id, first_name, last_name FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name,first_name");
        $q->execute([$orgId]); $suList = $q->fetchAll();
    } catch(Exception $e){}
} else {
    try {
        $q = $pdo->prepare("SELECT id, first_name, last_name FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name,first_name");
        $q->execute([$orgId]); $suList = $q->fetchAll();
    } catch(Exception $e){}
}

$days = [];
$d = new DateTime($weekStart);
for ($i = 0; $i < 7; $i++) { $days[] = $d->format('Y-m-d'); $d->modify('+1 day'); }

$statusColors = [
    'Scheduled'   => 'bg-blue-100 text-blue-700 border-blue-200',
    'In Progress' => 'bg-amber-100 text-amber-700 border-amber-200',
    'Completed'   => 'bg-green-100 text-green-700 border-green-200',
    'Missed'      => 'bg-red-100 text-red-700 border-red-200',
];

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Header + week nav ──────────────────────────────────────────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h2 class="text-xl font-extrabold text-gray-800">Rota &amp; Assignments</h2>
        <p class="text-sm text-gray-500"><?= date('d M',strtotime($weekStart)) ?> – <?= date('d M Y',strtotime($weekEnd)) ?></p>
    </div>
    <div class="flex items-center gap-2">
        <a href="?week=<?= date('Y-m-d',strtotime($weekStart.' -7 days')) ?>"
           class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">← Prev</a>
        <a href="?week=<?= date('Y-m-d',strtotime('monday this week')) ?>"
           class="bg-teal-50 border border-teal-200 text-teal-700 rounded-xl px-3 py-2 text-xs font-bold">This Week</a>
        <a href="?week=<?= date('Y-m-d',strtotime($weekStart.' +7 days')) ?>"
           class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">Next →</a>
    </div>
</div>

<?php if ($loadError): ?>
<div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-4 text-sm text-red-700">
    <i class="fa fa-triangle-exclamation mr-2"></i>
    <strong>Database issue:</strong> <?= h($loadError) ?><br>
    <span class="text-xs">Please run migration_v17.sql in phpMyAdmin then reload this page.</span>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ── Admin: Create Rota / Assign Staff ──────────────────────────── -->
<div class="bg-white rounded-2xl shadow mb-5 overflow-hidden">
    <button onclick="toggleSection('create-visit')"
            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-gray-50 transition">
        <div class="flex items-center gap-2">
            <i class="fa fa-calendar-plus text-teal-500 text-lg"></i>
            <span class="font-extrabold text-gray-800">Create Rota / Assign Staff</span>
        </div>
        <i class="fa fa-chevron-down text-gray-400 transition-transform" id="arrow-create-visit"></i>
    </button>
    <div id="create-visit" class="hidden border-t">
        <div class="bg-teal-50/50 px-5 py-3 border-b">
            <p class="text-xs text-teal-700 font-semibold">
                <i class="fa fa-info-circle mr-1"></i>
                You can assign one or more staff members to one or more service users. Each staff+SU combination creates a separate visit entry.
            </p>
        </div>
        <form method="POST" class="p-5">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="create_visit">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
                <!-- Service User -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Service User *</label>
                    <select name="su_id" required class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">— Select Service User —</option>
                        <?php foreach ($suList as $su): ?>
                        <option value="<?= $su['id'] ?>"><?= h($su['last_name'].', '.$su['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Date -->
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Date *</label>
                    <input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <!-- Repeat -->
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Repeat</label>
                    <select name="repeat" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="none">No repeat</option>
                        <option value="daily">Daily (rest of week)</option>
                        <option value="weekly">Weekly (4 weeks)</option>
                    </select>
                </div>
                <!-- Start time -->
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Start Time *</label>
                    <input type="time" name="start_time" required value="09:00"
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <!-- End time -->
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">End Time *</label>
                    <input type="time" name="end_time" required value="10:00"
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <!-- Notes -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Visit Notes / Instructions</label>
                    <input type="text" name="notes" placeholder="e.g. Morning personal care + medication"
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
            </div>

            <!-- Staff assignment - multi-select -->
            <div class="mb-5">
                <label class="block text-xs font-bold text-gray-600 mb-2">
                    Assign Staff * <span class="text-gray-400 font-normal">(tick one or more)</span>
                </label>
                <?php if (empty($staffList)): ?>
                <p class="text-sm text-gray-400 italic">No staff found. Add staff members first.</p>
                <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 max-h-52 overflow-y-auto border rounded-xl p-3 bg-gray-50">
                    <?php foreach ($staffList as $s): ?>
                    <label class="flex items-center gap-2 cursor-pointer hover:bg-white rounded-lg p-2 transition">
                        <input type="checkbox" name="carer_ids[]" value="<?= $s['id'] ?>"
                               class="rounded text-teal-600 focus:ring-teal-500">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">
                                <?= h($s['first_name'].' '.$s['last_name']) ?>
                            </div>
                            <?php if ($s['job_category']): ?>
                            <div class="text-xs text-gray-400"><?= h($s['job_category']) ?></div>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <button type="submit"
                    class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition">
                <i class="fa fa-calendar-plus mr-1"></i>Create Rota Entry
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<!-- ── Staff: Standalone care log ────────────────────────────────── -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
    <button onclick="toggleSection('staff-quick-log')"
            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-gray-50 transition">
        <div class="flex items-center gap-2">
            <i class="fa fa-pen-to-square text-teal-500"></i>
            <span class="font-extrabold text-gray-800">Write a Care Log</span>
        </div>
        <i class="fa fa-chevron-down text-gray-400" id="arrow-staff-quick-log"></i>
    </button>
    <div id="staff-quick-log" class="hidden border-t">
        <form method="POST" enctype="multipart/form-data" class="p-5">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="log_care_rota">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Service User *</label>
                    <select name="su_id" required class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">— Select —</option>
                        <?php foreach($suList as $su): ?><option value="<?= $su['id'] ?>"><?= h($su['last_name'].', '.$su['first_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Date</label>
                    <input type="date" name="log_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Type of Care</label>
                    <select name="care_type" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <?php foreach($CARE_TYPES as $ct): ?><option><?= $ct ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Mood</label>
                    <select name="mood" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">—</option>
                        <?php foreach($MOODS as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Care Notes *</label>
                    <textarea name="care_notes" required rows="3" placeholder="What care did you provide?"
                              class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Attach Document (optional)</label>
                    <div class="flex gap-3">
                        <input type="text" name="doc_title" placeholder="Document title..." class="flex-1 border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <label class="cursor-pointer bg-white border rounded-xl px-3 py-2 text-sm flex items-center gap-2 hover:bg-gray-50">
                            <i class="fa fa-paperclip text-teal-500"></i>
                            <span id="qlog-fn">File</span>
                            <input type="file" name="care_doc" accept="*/*" class="hidden"
                                   onchange="document.getElementById('qlog-fn').textContent=this.files[0]?.name.substring(0,15)||'File'">
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
<?php endif; ?>

<!-- ── Weekly grid ───────────────────────────────────────────────── -->
<div class="space-y-4">
<?php foreach ($days as $day):
    $dayVisits = $rotaData[$day] ?? [];
    $isToday   = $day === date('Y-m-d');
    $dayLabel  = date('D', strtotime($day));
    $dayNum    = date('d M', strtotime($day));
    $totalDay  = count($dayVisits);
    $doneDay   = count(array_filter($dayVisits, fn($v)=>($v['status']??'')=='Completed'));
?>
<div class="bg-white rounded-2xl shadow overflow-hidden <?= $isToday ? 'ring-2 ring-teal-400' : '' ?>">
    <div class="px-5 py-3 flex items-center justify-between border-b <?= $isToday ? 'bg-teal-600' : 'bg-gray-50' ?>">
        <div class="flex items-center gap-2">
            <span class="font-extrabold text-base <?= $isToday ? 'text-white' : 'text-gray-700' ?>"><?= $dayLabel ?></span>
            <span class="<?= $isToday ? 'text-teal-200 text-sm' : 'text-gray-500 text-xs' ?>"><?= $dayNum ?></span>
            <?php if ($isToday): ?><span class="text-xs bg-white/20 text-white px-2 py-0.5 rounded-full font-bold">Today</span><?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($totalDay > 0 && $doneDay > 0): ?>
            <span class="text-xs <?= $isToday?'text-teal-200':'text-green-600' ?>"><?= $doneDay ?>/<?= $totalDay ?> done</span>
            <?php endif; ?>
            <span class="text-xs <?= $isToday ? 'text-teal-200' : 'text-gray-400' ?>"><?= $totalDay ?> visit<?= $totalDay!==1?'s':'' ?></span>
        </div>
    </div>

    <?php if (empty($dayVisits)): ?>
    <div class="px-5 py-4 text-xs text-gray-400 italic">
        No visits scheduled.
        <?php if ($isAdmin): ?><a href="#" onclick="document.getElementById('create-visit').classList.remove('hidden');window.scrollTo(0,0);return false;" class="text-teal-600 font-semibold underline ml-1">Create one ↑</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-50">
    <?php foreach ($dayVisits as $v):
        $sc = $statusColors[$v['status'] ?? 'Scheduled'] ?? 'bg-gray-100 text-gray-600 border-gray-200';
    ?>
    <div>
        <div class="px-4 py-3 flex items-center gap-3">
            <!-- Time -->
            <div class="text-center min-w-[52px] flex-shrink-0">
                <div class="text-xs font-bold text-gray-700"><?= h($v['start_time']??'') ?></div>
                <div class="text-[10px] text-gray-400"><?= h($v['end_time']??'') ?></div>
            </div>
            <!-- SU info -->
            <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold text-gray-800 truncate"><?= h($v['su_name']??'') ?></div>
                <?php if ($isAdmin && !empty($v['staff_name'])): ?>
                <div class="text-xs text-teal-600 font-semibold truncate">
                    <i class="fa fa-user-nurse mr-1"></i><?= h($v['staff_name']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($v['notes'])): ?>
                <div class="text-xs text-gray-400 truncate"><?= h($v['notes']) ?></div>
                <?php endif; ?>
                <?php if (!empty($v['su_address'])): ?>
                <div class="text-xs text-gray-400 truncate">📍 <?= h($v['su_address']) ?></div>
                <?php endif; ?>
                <?php if (!$isAdmin && !empty($v['log_count'])): ?>
                <div class="text-xs text-teal-600 font-semibold"><?= $v['log_count'] ?> log<?= $v['log_count']>1?'s':'' ?> written</div>
                <?php endif; ?>
            </div>
            <!-- Actions -->
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <span class="text-xs border px-2 py-0.5 rounded-full font-bold <?= $sc ?>"><?= h($v['status']??'Scheduled') ?></span>

                <?php if (!$isAdmin): ?>
                <button onclick="toggleSection('rl-<?= $v['id'] ?>')"
                        class="text-xs bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 px-2.5 py-1 rounded-xl font-bold transition">
                    <i class="fa fa-pen"></i>
                </button>
                <?php else: ?>
                <form method="POST" onsubmit="return confirm('Remove this visit from rota?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="act" value="delete_visit">
                    <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                    <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 px-2.5 py-1 rounded-xl font-bold transition">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Staff care log form (staff only) -->
        <?php if (!$isAdmin): ?>
        <div id="rl-<?= $v['id'] ?>" class="hidden bg-teal-50/60 border-t border-teal-100">
            <form method="POST" enctype="multipart/form-data" class="p-4">
                <?= csrfField() ?>
                <input type="hidden" name="act" value="log_care_rota">
                <input type="hidden" name="su_id" value="<?= $v['su_id'] ?>">
                <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                <input type="hidden" name="log_date" value="<?= $day ?>">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Care Type</label>
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
                        <input type="number" name="duration_mins" placeholder="45"
                               class="w-full border rounded-xl px-3 py-2 text-xs focus:border-teal-500 focus:outline-none">
                    </div>
                    <div class="col-span-2 sm:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Care Notes *</label>
                        <textarea name="care_notes" required rows="3" placeholder="What care did you provide? Any observations?"
                                  class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
                    </div>
                    <div class="col-span-2 sm:col-span-4">
                        <label class="block text-xs font-bold text-gray-600 mb-1">Attach Document (optional)</label>
                        <div class="flex gap-2">
                            <input type="text" name="doc_title" placeholder="Document title..."
                                   class="flex-1 border rounded-xl px-3 py-2 text-xs bg-white focus:border-teal-500 focus:outline-none">
                            <label class="cursor-pointer bg-white border rounded-xl px-3 py-2 text-xs flex items-center gap-1 hover:bg-gray-50">
                                <i class="fa fa-paperclip text-teal-500"></i>
                                <span id="rfn-<?= $v['id'] ?>">File</span>
                                <input type="file" name="care_doc" accept="*/*" class="hidden"
                                       onchange="document.getElementById('rfn-<?= $v['id'] ?>').textContent=this.files[0]?.name.substring(0,12)||'File'">
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-teal-600 text-white font-bold px-4 py-2 rounded-xl text-xs hover:bg-teal-700 transition">
                        <i class="fa fa-floppy-disk mr-1"></i>Save
                    </button>
                    <button type="button" onclick="toggleSection('rl-<?= $v['id'] ?>')"
                            class="bg-gray-100 text-gray-600 font-bold px-4 py-2 rounded-xl text-xs transition">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    const arrow = document.getElementById('arrow-'+id);
    el.classList.toggle('hidden');
    if (arrow) arrow.style.transform = el.classList.contains('hidden') ? '' : 'rotate(180deg)';
    if (!el.classList.contains('hidden')) el.scrollIntoView({behavior:'smooth',block:'nearest'});
}
</script>
<style>
.ai-btn-sm{display:inline-flex;align-items:center;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:white;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;border:none;cursor:pointer;gap:3px;transition:opacity .15s;white-space:nowrap;}
.ai-btn-sm:hover{opacity:.85;}
#rotaAIModal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center;padding:16px;}
#rotaAIModal.open{display:flex;}
#rotaAIBox{background:white;border-radius:20px;width:100%;max-width:580px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.3);}
</style>
<div id="rotaAIModal">
  <div id="rotaAIBox">
    <div style="background:linear-gradient(135deg,#7c3aed,#4f46e5);padding:15px 20px;border-radius:20px 20px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <div class="flex items-center gap-2">
        <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;"><i class="fa fa-wand-magic-sparkles text-white text-sm"></i></div>
        <div>
          <div class="text-white font-extrabold text-sm">AI Care Note Assistant</div>
          <div class="text-purple-200 text-xs" id="rotaAISub">Drafting professional care note</div>
        </div>
      </div>
      <button onclick="closeRotaAI()" class="text-white/70 hover:text-white text-xl font-bold leading-none">&times;</button>
    </div>
    <div class="p-4 overflow-y-auto flex-1">
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">Key points / what care was provided <span class="text-red-400">*</span></label>
        <textarea id="rotaAIPrompt" rows="3" placeholder="e.g. Morning personal care, service user was upset. Refused breakfast. Took medication at 10am. Fall risk — reported to senior."
          class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none resize-none"></textarea>
      </div>
      <button onclick="runRotaAI()" id="rotaAIBtn" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-extrabold py-2.5 rounded-xl text-sm mb-4 flex items-center justify-center gap-2 transition">
        <i class="fa fa-wand-magic-sparkles"></i> Generate Professional Care Note
      </button>
      <div id="rotaAIOut" style="display:none;">
        <div class="flex items-center justify-between mb-1">
          <span class="text-xs font-bold text-gray-600">AI Draft — review before saving</span>
          <button onclick="useRotaAI()" class="text-xs bg-purple-600 text-white font-bold px-3 py-1.5 rounded-xl hover:bg-purple-700"><i class="fa fa-check mr-1"></i>Use This</button>
        </div>
        <div id="rotaAIText" class="border border-gray-200 rounded-xl p-3 text-sm text-gray-700 bg-gray-50 whitespace-pre-wrap min-h-16"></div>
        <p class="text-xs text-gray-400 mt-2">Always review AI output. You are responsible for accuracy of care records.</p>
      </div>
    </div>
  </div>
</div>
<script>
var _rotaAIField=null, _rotaAISU='';
function rotaAILog(fieldId,suName){
  _rotaAIField=document.getElementById(fieldId);
  _rotaAISU=suName;
  document.getElementById('rotaAISub').textContent='Care note for '+(suName||'service user');
  document.getElementById('rotaAIPrompt').value=_rotaAIField?_rotaAIField.value:'';
  document.getElementById('rotaAIOut').style.display='none';
  document.getElementById('rotaAIText').textContent='';
  document.getElementById('rotaAIModal').classList.add('open');
  setTimeout(function(){document.getElementById('rotaAIPrompt').focus();},100);
}
function closeRotaAI(){document.getElementById('rotaAIModal').classList.remove('open');}
function useRotaAI(){if(_rotaAIField){_rotaAIField.value=document.getElementById('rotaAIText').textContent;closeRotaAI();}}
async function runRotaAI(){
  var p=document.getElementById('rotaAIPrompt').value.trim();
  if(!p){alert('Please describe the care provided first.');return;}
  var btn=document.getElementById('rotaAIBtn');
  btn.disabled=true;btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Generating...';
  document.getElementById('rotaAIOut').style.display='none';
  try{
    var r=await fetch('https://api.anthropic.com/v1/messages',{method:'POST',headers:{'Content-Type':'application/json','anthropic-version':'2023-06-01'},
      body:JSON.stringify({model:'claude-sonnet-4-20250514',max_tokens:600,
        system:'You are a UK care worker writing a professional care log entry. Write in first person past tense, factual, concise, person-centred. CQC-compliant. Do not add details not given. Output only the care note text, no preamble.',
        messages:[{role:'user',content:'Service user: '+_rotaAISU+'. Write a professional care log:
'+p}]})});
    var d=await r.json();var t='';
    if(d.content)d.content.forEach(function(b){if(b.type==='text')t+=b.text;});
    else t='AI unavailable. Please write manually.';
    document.getElementById('rotaAIText').textContent=t;
    document.getElementById('rotaAIOut').style.display='block';
  }catch(e){document.getElementById('rotaAIText').textContent='AI unavailable. Please write manually.';document.getElementById('rotaAIOut').style.display='block';}
  btn.disabled=false;btn.innerHTML='<i class="fa fa-wand-magic-sparkles"></i> Generate Professional Care Note';
}
document.getElementById('rotaAIModal').addEventListener('click',function(e){if(e.target===this)closeRotaAI();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeRotaAI();});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
