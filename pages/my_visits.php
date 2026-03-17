<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$pageTitle = 'My Visits';
$date  = $_GET['date'] ?? date('Y-m-d');

// ── Upload dirs ───────────────────────────────────────────────────────
$careLogDir = __DIR__.'/../uploads/care_logs/';
if (!is_dir($careLogDir)) { mkdir($careLogDir,0755,true); file_put_contents($careLogDir.'.htaccess',"Options -Indexes\n"); }

// ── Care types ────────────────────────────────────────────────────────
$CARE_TYPES = [
    'Personal Care','Medication Administration','Nutrition & Hydration',
    'Moving & Handling','Physiotherapy Support','Occupational Therapy',
    'Emotional Support','Social Engagement','Continence Care',
    'Wound Care / Dressing','Dementia Care','End of Life Care',
    'Community Outing','Domestic Support','Night Care','Other',
];
$MOODS = ['Happy','Calm','Anxious','Sad','Confused','Unwell','Agitated'];

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    validateCSRF();
    $act = $_POST['act'] ?? '';

    // Start visit
    if ($act==='start_visit') {
        $vId = (int)($_POST['visit_id']??0);
        try {
            $pdo->prepare("UPDATE visits SET status='In Progress', actual_start_time=NOW() WHERE id=? AND organisation_id=? AND carer_id=?")->execute([$vId,$orgId,$uid]);
            setFlash('success','Visit started.');
        } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
        header("Location: my_visits.php?date=$date"); exit;
    }

    // Log care
    if ($act==='log_care') {
        $vId     = (int)($_POST['visit_id']??0);
        $suId    = (int)($_POST['su_id']??0);
        $careType= trim($_POST['care_type']??'General Care');
        $notes   = trim($_POST['care_notes']??'');
        $duration= (int)($_POST['duration_mins']??0) ?: null;
        $mood    = trim($_POST['mood']??'') ?: null;
        $logDate = $_POST['log_date'] ?? $date;

        if (!$notes) { setFlash('error','Please write care notes before saving.'); header("Location: my_visits.php?date=$date"); exit; }

        try {
            $pdo->prepare("INSERT INTO care_logs (organisation_id,service_user_id,staff_id,visit_id,log_date,care_type,notes,duration_mins,mood) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$uid,$vId?:null,$logDate,$careType,$notes,$duration,$mood]);
            $logId = $pdo->lastInsertId();

            // Handle file upload
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

            // Mark visit complete if requested
            if (!empty($_POST['mark_complete']) && $vId) {
                $pdo->prepare("UPDATE visits SET status='Completed', actual_end_time=NOW() WHERE id=? AND organisation_id=?")->execute([$vId,$orgId]);
            }
            setFlash('success','Care log saved'.(!empty($_POST['mark_complete'])?', visit marked complete.':'.'));
        } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
        header("Location: my_visits.php?date=$date"); exit;
    }
}

// ── Load visits ───────────────────────────────────────────────────────
$visits = [];
try {
    $q = $pdo->prepare("SELECT v.*, CONCAT(su.first_name,' ',su.last_name) AS su_name,
        su.id AS su_id, su.address, su.allergies, su.date_of_birth,
        (SELECT COUNT(*) FROM care_logs cl WHERE cl.visit_id=v.id AND cl.staff_id=?) AS log_count
        FROM visits v
        JOIN service_users su ON su.id=v.service_user_id
        WHERE v.organisation_id=? AND v.carer_id=? AND DATE(v.visit_date)=?
        ORDER BY v.start_time");
    $q->execute([$uid,$orgId,$uid,$date]); $visits=$q->fetchAll();
} catch(Exception $e){ setFlash('error','Could not load visits.'); }

include __DIR__ . '/../includes/header.php';

$statusColors = [
    'Scheduled'   => 'bg-blue-100 text-blue-700 border-blue-200',
    'In Progress' => 'bg-amber-100 text-amber-700 border-amber-200',
    'Completed'   => 'bg-green-100 text-green-700 border-green-200',
    'Missed'      => 'bg-red-100 text-red-700 border-red-200',
];
?>
<!-- Date nav -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
    <div>
        <h2 class="text-xl font-extrabold text-gray-800">My Visits</h2>
        <p class="text-sm text-gray-500"><?= date('l, d F Y',strtotime($date)) ?> — <?= count($visits) ?> visit<?= count($visits)!==1?'s':'' ?></p>
    </div>
    <div class="flex items-center gap-2">
        <a href="?date=<?= date('Y-m-d',strtotime($date.' -1 day')) ?>" class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">← Prev</a>
        <input type="date" value="<?= $date ?>" onchange="location='?date='+this.value"
               class="border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
        <a href="?date=<?= date('Y-m-d',strtotime($date.' +1 day')) ?>" class="bg-white border rounded-xl px-3 py-2 text-sm hover:bg-gray-50 font-bold">Next →</a>
    </div>
</div>

<?php if (empty($visits)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
    <div class="text-5xl mb-3">📅</div>
    <h3 class="text-lg font-bold text-gray-600 mb-1">No visits scheduled for this date</h3>
    <p class="text-gray-400 text-sm">Check another date or contact your manager if you think this is incorrect.</p>
</div>
<?php else: ?>
<div class="space-y-4">
<?php foreach($visits as $v):
    $sc = $statusColors[$v['status']] ?? 'bg-gray-100 text-gray-600 border-gray-200';
    $canLog = in_array($v['status'],['In Progress','Scheduled','Completed']);
?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
    <!-- Visit header -->
    <div class="px-5 py-4 flex items-start justify-between gap-3 border-b">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-12 h-12 rounded-xl bg-teal-100 flex items-center justify-center text-teal-700 font-extrabold text-sm flex-shrink-0">
                <?= strtoupper(implode('',array_map(function($p){return substr($p,0,1);},explode(' ',$v['su_name'])))) ?>
            </div>
            <div class="min-w-0">
                <div class="font-extrabold text-gray-800 truncate"><?= h($v['su_name']) ?></div>
                <div class="text-xs text-gray-500"><?= h($v['start_time']) ?> – <?= h($v['end_time']) ?></div>
                <?php if ($v['address']): ?><div class="text-xs text-gray-400 truncate">📍 <?= h($v['address']) ?></div><?php endif; ?>
                <?php if ($v['allergies']): ?><div class="text-xs text-red-600 font-bold">⚠ <?= h($v['allergies']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="flex flex-col items-end gap-2 flex-shrink-0">
            <span class="text-xs border px-2.5 py-1 rounded-full font-bold <?= $sc ?>"><?= h($v['status']) ?></span>
            <?php if ($v['log_count']>0): ?>
            <span class="text-xs bg-teal-50 text-teal-600 border border-teal-100 px-2 py-0.5 rounded-full"><?= $v['log_count'] ?> log<?= $v['log_count']>1?'s':'' ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action buttons -->
    <div class="px-5 py-3 flex flex-wrap gap-2 bg-gray-50">
        <?php if ($v['status']==='Scheduled'): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="start_visit">
            <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                <i class="fa fa-play mr-1"></i>Start Visit
            </button>
        </form>
        <?php endif; ?>

        <?php if ($canLog): ?>
        <button onclick="toggleLogForm(<?= $v['id'] ?>)"
                class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
            <i class="fa fa-pen-to-square mr-1"></i>Log Care
        </button>
        <a href="su_profile.php?id=<?= $v['su_id'] ?>" class="bg-white hover:bg-gray-100 text-gray-700 border font-bold px-4 py-2 rounded-xl text-xs transition">
            <i class="fa fa-folder-open mr-1"></i>View Profile
        </a>
        <?php endif; ?>
    </div>

    <!-- Care log form (hidden by default) -->
    <?php if ($canLog): ?>
    <div id="log-form-<?= $v['id'] ?>" class="hidden border-t">
        <div class="bg-teal-50 px-5 py-3 border-b">
            <h4 class="font-extrabold text-teal-800 text-sm"><i class="fa fa-pen-to-square mr-2"></i>Log Care for <?= h($v['su_name']) ?></h4>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-5">
            <?= csrfField() ?>
            <input type="hidden" name="act" value="log_care">
            <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
            <input type="hidden" name="su_id" value="<?= $v['su_id'] ?>">
            <input type="hidden" name="log_date" value="<?= $date ?>">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Type of Care *</label>
                    <select name="care_type" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <?php foreach($CARE_TYPES as $ct): ?><option><?= $ct ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Service User Mood</label>
                    <select name="mood" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                        <option value="">— Select —</option>
                        <?php foreach($MOODS as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">Duration (mins)</label>
                    <input type="number" name="duration_mins" min="1" placeholder="e.g. 45"
                           class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
                </div>
                <div class="sm:col-span-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-gray-600">Care Notes * <span class="text-gray-400 font-normal">(describe what care you provided)</span></label>
                        <button type="button" onclick="aiCareLog('cn-<?= $v['id'] ?>','<?= addslashes(h($v['su_name'])) ?>')" class="ai-btn-sm"><i class="fa fa-wand-magic-sparkles mr-1"></i>AI Assist</button>
                    </div>
                    <textarea name="care_notes" id="cn-<?= $v['id'] ?>" required rows="4" placeholder="Describe the care provided, any observations, how the service user responded, any concerns or notable changes..."
                              class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
                </div>
            </div>

            <!-- Document upload section -->
            <div class="border border-dashed border-teal-300 rounded-2xl p-4 bg-teal-50/50 mb-4">
                <div class="text-xs font-extrabold text-teal-700 mb-3"><i class="fa fa-paperclip mr-1"></i>Attach Document (optional)</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Document Title</label>
                        <input type="text" name="doc_title" placeholder="e.g. Wound Care Chart"
                               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">File <span class="text-gray-400 font-normal">(all types)</span></label>
                        <label class="flex items-center gap-2 cursor-pointer border rounded-xl px-3 py-2 bg-white hover:bg-teal-50 transition">
                            <i class="fa fa-paperclip text-teal-500"></i>
                            <span id="fn-<?= $v['id'] ?>" class="text-sm text-gray-500">Choose file...</span>
                            <input type="file" name="care_doc" accept="*/*" class="hidden"
                                   onchange="document.getElementById('fn-<?= $v['id'] ?>').textContent=this.files[0]?.name||'Choose file...'">
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition">
                    <i class="fa fa-floppy-disk mr-1"></i>Save Care Log
                </button>
                <?php if ($v['status']!=='Completed'): ?>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="mark_complete" value="1" class="rounded">
                    <span class="font-semibold text-gray-700">Mark visit as completed</span>
                </label>
                <?php endif; ?>
                <button type="button" onclick="toggleLogForm(<?= $v['id'] ?>)"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold px-4 py-2.5 rounded-xl text-sm transition">Cancel</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.ai-btn-sm{display:inline-flex;align-items:center;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:white;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;border:none;cursor:pointer;gap:3px;transition:opacity .15s;white-space:nowrap;}
.ai-btn-sm:hover{opacity:.85;}
#aiModal2{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center;padding:16px;}
#aiModal2.open{display:flex;}
#aiModal2Box{background:white;border-radius:20px;width:100%;max-width:580px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.3);}
</style>
<div id="aiModal2">
  <div id="aiModal2Box">
    <div style="background:linear-gradient(135deg,#7c3aed,#4f46e5);padding:15px 20px;border-radius:20px 20px 0 0;display:flex;align-items:center;justify-content:space-between;">
      <div class="flex items-center gap-2">
        <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;"><i class="fa fa-wand-magic-sparkles text-white text-sm"></i></div>
        <div>
          <div class="text-white font-extrabold text-sm">AI Care Note Assistant</div>
          <div class="text-purple-200 text-xs" id="ai2Sub">Generating professional care notes</div>
        </div>
      </div>
      <button onclick="closeAI2()" class="text-white/70 hover:text-white text-xl font-bold leading-none">&times;</button>
    </div>
    <div class="p-4 overflow-y-auto flex-1">
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">What care did you provide? Key observations? <span class="text-red-400">*</span></label>
        <textarea id="ai2Prompt" rows="3" placeholder="e.g. Helped with wash and dress. Client was reluctant but eventually cooperative. Ate breakfast well. Reminded about afternoon medication."
          class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none resize-none"></textarea>
      </div>
      <button onclick="runAI2()" id="ai2Btn" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-extrabold py-2.5 rounded-xl text-sm mb-4 flex items-center justify-center gap-2 transition">
        <i class="fa fa-wand-magic-sparkles"></i> Generate Professional Care Note
      </button>
      <div id="ai2Out" style="display:none;">
        <div class="flex items-center justify-between mb-1">
          <span class="text-xs font-bold text-gray-600">AI Draft — review carefully before saving</span>
          <button onclick="useAI2()" class="text-xs bg-purple-600 text-white font-bold px-3 py-1.5 rounded-xl hover:bg-purple-700"><i class="fa fa-check mr-1"></i>Use This</button>
        </div>
        <div id="ai2Text" class="border border-gray-200 rounded-xl p-3 text-sm text-gray-700 bg-gray-50 whitespace-pre-wrap min-h-16"></div>
        <p class="text-xs text-gray-400 mt-2">Always review and edit AI output. You are responsible for accuracy of care records.</p>
      </div>
    </div>
  </div>
</div>

<script>
var _ai2Target = null, _ai2SU = '';
function aiCareLog(fieldId, suName) {
  _ai2Target = document.getElementById(fieldId);
  _ai2SU = suName;
  document.getElementById('ai2Sub').textContent = 'Care note for ' + suName;
  document.getElementById('ai2Prompt').value = _ai2Target ? _ai2Target.value : '';
  document.getElementById('ai2Out').style.display = 'none';
  document.getElementById('ai2Text').textContent = '';
  document.getElementById('aiModal2').classList.add('open');
  setTimeout(function(){ document.getElementById('ai2Prompt').focus(); },100);
}
function closeAI2(){ document.getElementById('aiModal2').classList.remove('open'); }
function useAI2(){
  if (_ai2Target) { _ai2Target.value = document.getElementById('ai2Text').textContent; closeAI2(); }
}
async function runAI2(){
  var prompt = document.getElementById('ai2Prompt').value.trim();
  if (!prompt) { alert('Please describe what care you provided first.'); return; }
  var btn = document.getElementById('ai2Btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating...';
  document.getElementById('ai2Out').style.display = 'none';
  try {
    var r = await fetch('https://api.anthropic.com/v1/messages',{method:'POST',headers:{'Content-Type':'application/json','anthropic-version':'2023-06-01'},
      body:JSON.stringify({model:'claude-sonnet-4-20250514',max_tokens:600,
        system:'You are a UK care worker writing a professional care log entry. Write in first person past tense, factual, concise, person-centred. CQC-compliant. Do not add details not given. Output only the care note text, no preamble.',
        messages:[{role:'user',content:'Service user: '+_ai2SU+'. Write a professional care log:
'+prompt}]})});
    var d = await r.json();
    var t = '';
    if (d.content) d.content.forEach(function(b){ if(b.type==='text') t+=b.text; });
    else t = 'AI unavailable. Please write manually.';
    document.getElementById('ai2Text').textContent = t;
    document.getElementById('ai2Out').style.display = 'block';
  } catch(e) {
    document.getElementById('ai2Text').textContent = 'AI unavailable. Please write manually.';
    document.getElementById('ai2Out').style.display = 'block';
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="fa fa-wand-magic-sparkles"></i> Generate Professional Care Note';
}
document.getElementById('aiModal2').addEventListener('click',function(e){if(e.target===this)closeAI2();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAI2();});
function toggleLogForm(id) {
    var el = document.getElementById('log-form-'+id);
    el.classList.toggle('hidden');
    if (!el.classList.contains('hidden')) el.scrollIntoView({behavior:'smooth',block:'nearest'});
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
