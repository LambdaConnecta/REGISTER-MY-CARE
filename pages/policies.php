<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Policies';

$uploadDir = __DIR__.'/../uploads/policies/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); file_put_contents($uploadDir.'.htaccess',"Options -Indexes\n"); }

// Admin: upload policy
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_policy'])) {
    validateCSRF();
    $title    = trim($_POST['title']    ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $version  = trim($_POST['version']  ?? '1.0');
    $review   = $_POST['review_date']   ?? null;
    if (!$title) { setFlash('error','Policy title required.'); header('Location: policies.php'); exit; }
    if (empty($_FILES['policy_file']['name']) || $_FILES['policy_file']['error']!==UPLOAD_ERR_OK) {
        setFlash('error','Please select a file to upload.'); header('Location: policies.php'); exit;
    }
    $f    = $_FILES['policy_file'];
    $ext  = strtolower(pathinfo(basename($f['name']),PATHINFO_EXTENSION));
    $mime = @mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
    $fn   = 'policy_'.$orgId.'_'.time().'.'.$ext;
    if ($f['size'] > 50*1024*1024) { setFlash('error','File too large (max 50MB).'); header('Location: policies.php'); exit; }
    if (move_uploaded_file($f['tmp_name'],$uploadDir.$fn)) {
        try {
            $pdo->prepare("INSERT INTO policies (organisation_id,title,category,file_name,file_original,file_size,file_type,version,review_date,uploaded_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$orgId,$title,$category,$fn,basename($f['name']),$f['size'],$mime,$version,$review?:null,$uid]);
            setFlash('success','"'.$title.'" uploaded successfully.');
        } catch(Exception $e){ setFlash('error','DB error: '.$e->getMessage()); }
    } else { setFlash('error','Could not save file. Check uploads/policies/ permissions.'); }
    header('Location: policies.php'); exit;
}

// Admin: delete policy
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_policy'])) {
    validateCSRF();
    $pid = (int)$_POST['policy_id'];
    try {
        $p = $pdo->prepare("SELECT file_name FROM policies WHERE id=? AND organisation_id=?");
        $p->execute([$pid,$orgId]); $p=$p->fetch();
        if ($p) {
            @unlink($uploadDir.$p['file_name']);
            $pdo->prepare("DELETE FROM policies WHERE id=? AND organisation_id=?")->execute([$pid,$orgId]);
            setFlash('success','Policy deleted.');
        }
    } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
    header('Location: policies.php'); exit;
}

$policies = [];
$cats = ['General','Health & Safety','Safeguarding','Medication','Data Protection','Infection Control','HR & Staffing','Emergency Procedures','Other'];
try {
    $q = $pdo->prepare("SELECT p.*, u.first_name, u.last_name FROM policies p LEFT JOIN users u ON p.uploaded_by=u.id WHERE p.organisation_id=? ORDER BY p.category, p.title");
    $q->execute([$orgId]); $policies=$q->fetchAll();
} catch(Exception $e){ setFlash('error','Error loading policies: '.$e->getMessage()); }

$grouped = [];
foreach ($policies as $p) { $grouped[$p['category']][] = $p; }
include __DIR__ . '/../includes/header.php';
?>
<div class="flex flex-col md:flex-row gap-5">

<?php if ($isAdmin): ?>
<!-- Upload panel -->
<div class="md:w-80 flex-shrink-0">
    <div class="bg-white rounded-2xl shadow overflow-hidden sticky top-20">
        <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-5 py-4">
            <h3 class="text-white font-extrabold"><i class="fa fa-upload mr-2"></i>Upload Policy</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="p-4">
            <?= csrfField() ?>
            <input type="hidden" name="upload_policy" value="1">
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Policy Title *</label>
                <input type="text" name="title" required placeholder="e.g. Medication Administration Policy" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div class="mb-3"><label class="block text-xs font-bold text-gray-600 mb-1">Category</label>
                <select name="category" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <?php foreach ($cats as $c): ?><option><?=$c?></option><?php endforeach; ?>
                </select></div>
            <div class="grid grid-cols-2 gap-2 mb-3">
                <div><label class="block text-xs font-bold text-gray-600 mb-1">Version</label>
                    <input type="text" name="version" value="1.0" placeholder="1.0" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
                <div><label class="block text-xs font-bold text-gray-600 mb-1">Review Date</label>
                    <input type="date" name="review_date" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            </div>
            <div class="mb-3 bg-blue-50 border border-blue-200 rounded-xl p-3">
                <label class="block text-xs font-bold text-blue-700 mb-1.5"><i class="fa fa-file mr-1"></i>Select File *</label>
                <input type="file" name="policy_file" accept="*/*" required
                    class="text-xs text-gray-600 w-full file:mr-2 file:py-1.5 file:px-2.5 file:rounded-lg file:border-0 file:bg-teal-600 file:text-white file:text-xs hover:file:bg-teal-700 cursor-pointer">
                <p class="text-xs text-blue-500 mt-1">All file types · Max 50MB</p>
            </div>
            <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 rounded-xl text-sm transition">
                <i class="fa fa-upload mr-1"></i>Upload Policy
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Policy list -->
<div class="flex-1 min-w-0">
    <h2 class="text-xl font-extrabold text-gray-800 mb-4"><i class="fa fa-file-shield text-teal-500 mr-2"></i>
        <?= $isAdmin ? 'Manage Policies' : 'Company Policies' ?>
        <span class="text-sm font-normal text-gray-400 ml-2"><?=count($policies)?> document<?=count($policies)!==1?'s':''?></span>
    </h2>
    <?php if (empty($policies)): ?>
    <div class="bg-white rounded-2xl shadow p-10 text-center text-gray-400">
        <div class="text-4xl mb-2">📋</div>
        <p><?=$isAdmin?'Upload your first policy document using the form.':'No policies have been uploaded yet.'?></p>
    </div>
    <?php else: ?>
    <?php foreach ($grouped as $cat => $catPolicies): ?>
    <div class="mb-5">
        <h3 class="text-xs font-extrabold text-gray-400 uppercase tracking-wider mb-2 px-1"><?=h($cat)?></h3>
        <div class="bg-white rounded-2xl shadow overflow-hidden divide-y divide-gray-100">
        <?php foreach ($catPolicies as $p):
            $size = $p['file_size']>0 ? ($p['file_size']>1048576 ? round($p['file_size']/1048576,1).' MB' : round($p['file_size']/1024).' KB') : '';
            $_ft=$p['file_type']??'';$_fn=strtolower($p['file_original']??'');
            if(strpos($_ft,'pdf')!==false||substr($_fn,-4)==='.pdf'){$ico='fa-file-pdf text-red-500';}
            elseif(strpos($_ft,'image/')===0){$ico='fa-file-image text-green-500';}
            elseif(strpos($_ft,'word')!==false||preg_match('/\.docx?$/',$_fn)){$ico='fa-file-word text-blue-500';}
            elseif(strpos($_ft,'spreadsheet')!==false||preg_match('/\.xlsx?$/',$_fn)){$ico='fa-file-excel text-emerald-500';}
            else{$ico='fa-file text-gray-400';}
        ?>
        <div class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition">
            <div class="w-9 h-9 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                <i class="fa <?=$ico?> text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-gray-800 truncate text-sm"><?=h($p['title'])?></div>
                <div class="text-xs text-gray-400 flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                    <span>v<?=h($p['version']??'1.0')?></span>
                    <?php if ($size): ?><span><?=$size?></span><?php endif; ?>
                    <?php if ($p['review_date']): ?><span>Review: <?=date('d M Y',strtotime($p['review_date']))?></span><?php endif; ?>
                    <?php if ($p['first_name']): ?><span>By: <?=h($p['first_name'].' '.$p['last_name'])?></span><?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <!-- View (everyone can read) -->
                <a href="/uploads/policies/<?=rawurlencode($p['file_name'])?>" target="_blank"
                   class="text-xs bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 px-2.5 py-1.5 rounded-lg font-semibold transition flex items-center gap-1">
                    <i class="fa fa-eye text-xs"></i>Read
                </a>
                <?php if ($isAdmin): ?>
                <!-- Admin: download -->
                <a href="/uploads/policies/<?=rawurlencode($p['file_name'])?>" download="<?=rawurlencode($p['file_original'])?>"
                   class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-2.5 py-1.5 rounded-lg font-semibold transition flex items-center gap-1">
                    <i class="fa fa-download text-xs"></i>
                </a>
                <!-- Admin: delete -->
                <form method="POST" onsubmit="return confirm('Delete this policy?')">
                    <?=csrfField()?>
                    <input type="hidden" name="delete_policy" value="1">
                    <input type="hidden" name="policy_id" value="<?=$p['id']?>">
                    <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 px-2.5 py-1.5 rounded-lg font-semibold transition">
                        <i class="fa fa-trash text-xs"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
