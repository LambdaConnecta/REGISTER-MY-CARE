<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo    = getPDO();
$orgId  = (int)$_SESSION['organisation_id'];
$uid    = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$suId   = (int)($_GET['id'] ?? 0);

// ── Self-heal tables & columns ────────────────────────────────────────
try { $pdo->exec("ALTER TABLE `service_users` ADD COLUMN `council_id` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `service_users` ADD COLUMN `nhs_trust_id` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `service_users` ADD COLUMN `funding_type` VARCHAR(100) NOT NULL DEFAULT ''"); } catch(Exception $e){}

// Medical history with file support
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `su_medical_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisation_id` INT UNSIGNED NOT NULL,
    `service_user_id` INT UNSIGNED NOT NULL,
    `entry_type` VARCHAR(100) NOT NULL DEFAULT 'General',
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT,
    `file_name` VARCHAR(255) DEFAULT NULL,
    `file_original` VARCHAR(255) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT 0,
    `file_type` VARCHAR(100) DEFAULT '',
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `entry_date` DATE DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `su_medical_history` ADD COLUMN `file_name` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `su_medical_history` ADD COLUMN `file_original` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `su_medical_history` ADD COLUMN `file_size` INT UNSIGNED DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `su_medical_history` ADD COLUMN `file_type` VARCHAR(100) DEFAULT ''"); } catch(Exception $e){}

// su_documents with written content support
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `su_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisation_id` INT UNSIGNED NOT NULL,
    `service_user_id` INT UNSIGNED NOT NULL,
    `doc_type` VARCHAR(100) NOT NULL DEFAULT 'Other',
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT,
    `file_name` VARCHAR(255) DEFAULT NULL,
    `file_original` VARCHAR(255) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT 0,
    `file_type` VARCHAR(100) DEFAULT '',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `su_documents` ADD COLUMN `content` LONGTEXT"); } catch(Exception $e){}

// Care plans
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `care_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisation_id` INT UNSIGNED NOT NULL,
    `service_user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `plan_type` VARCHAR(100) NOT NULL DEFAULT 'General',
    `content` LONGTEXT,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `review_date` DATE DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

// Care plan documents (multiple per plan)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `care_plan_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisation_id` INT UNSIGNED NOT NULL,
    `care_plan_id` INT UNSIGNED NOT NULL,
    `service_user_id` INT UNSIGNED NOT NULL,
    `doc_type` VARCHAR(100) NOT NULL DEFAULT 'Care Plan',
    `title` VARCHAR(255) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_original` VARCHAR(255) NOT NULL,
    `file_size` INT UNSIGNED DEFAULT 0,
    `file_type` VARCHAR(100) DEFAULT '',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `k_org_cp` (`organisation_id`,`care_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

// Ensure care_logs table exists
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `care_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organisation_id` INT UNSIGNED NOT NULL,
    `service_user_id` INT UNSIGNED NOT NULL,
    `staff_id` INT UNSIGNED DEFAULT NULL,
    `care_type` VARCHAR(100) NOT NULL DEFAULT 'General',
    `mood` VARCHAR(50) DEFAULT NULL,
    `notes` LONGTEXT,
    `log_date` DATE DEFAULT NULL,
    `duration_mins` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

// Load service user
$su = null;
try {
    $q = $pdo->prepare("SELECT su.*, c.name AS council_name, n.name AS nhs_trust_name
        FROM service_users su
        LEFT JOIN uk_councils   c ON c.id = su.council_id
        LEFT JOIN nhs_hospitals n ON n.id = su.nhs_trust_id
        WHERE su.id=? AND su.organisation_id=?");
    $q->execute([$suId, $orgId]);
    $su = $q->fetch();
} catch(Exception $e) {
    try {
        $q = $pdo->prepare("SELECT * FROM service_users WHERE id=? AND organisation_id=?");
        $q->execute([$suId, $orgId]);
        $su = $q->fetch();
    } catch(Exception $e2) {}
}
if (!$su) { setFlash('error','Service user not found.'); header('Location: service_users.php'); exit; }

$pageTitle = h($su['first_name'].' '.$su['last_name']).' — Profile';

// Upload dirs
$mhDocDir = __DIR__ . '/../uploads/su_medical/';
$suDocDir  = __DIR__ . '/../uploads/su_docs/';
$cpDocDir  = __DIR__ . '/../uploads/care_plan_docs/';
foreach ([$mhDocDir, $suDocDir, $cpDocDir] as $dir) {
    if (!is_dir($dir)) { mkdir($dir, 0755, true); file_put_contents($dir.'.htaccess',"Options -Indexes\n"); }
}

// ── POST actions ──────────────────────────────────────────────────────
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $act = $_POST['action'] ?? '';

    // ── Add medical history ───
    if ($act === 'add_med_history') {
        $title   = trim($_POST['mh_title']   ?? '');
        $type    = trim($_POST['mh_type']    ?? 'General');
        $content = trim($_POST['mh_content'] ?? '');
        $edate   = $_POST['mh_entry_date'] ?: date('Y-m-d');
        if (!$title) { setFlash('error','Title is required.'); header('Location: su_profile.php?id='.$suId.'&tab=medical'); exit; }

        $fname = $forig = $fsize = $ftype = null;
        if (!empty($_FILES['mh_file']['name'])) {
            $orig = basename($_FILES['mh_file']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $safe = 'mh_'.$suId.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
            if (move_uploaded_file($_FILES['mh_file']['tmp_name'], $mhDocDir.$safe)) {
                $fname = $safe; $forig = $orig;
                $fsize = $_FILES['mh_file']['size'];
                $ftype = $_FILES['mh_file']['type'];
            }
        }
        try {
            $pdo->prepare("INSERT INTO su_medical_history (organisation_id,service_user_id,entry_type,title,content,file_name,file_original,file_size,file_type,recorded_by,entry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$type,$title,$content,$fname,$forig,$fsize,$ftype,$uid,$edate]);
            setFlash('success','Medical history entry added.');
        } catch(Exception $e){ setFlash('error','Could not save: '.$e->getMessage()); }
        header('Location: su_profile.php?id='.$suId.'&tab=medical'); exit;
    }

    // ── Delete medical history ───
    if ($act === 'del_med_history') {
        $mid = (int)($_POST['mh_id'] ?? 0);
        try {
            $d = $pdo->prepare("SELECT file_name FROM su_medical_history WHERE id=? AND organisation_id=?");
            $d->execute([$mid,$orgId]); $d=$d->fetch();
            if ($d && $d['file_name']) @unlink($mhDocDir.$d['file_name']);
            $pdo->prepare("DELETE FROM su_medical_history WHERE id=? AND organisation_id=?")->execute([$mid,$orgId]);
            setFlash('success','Entry deleted.');
        } catch(Exception $e){ setFlash('error','Could not delete.'); }
        header('Location: su_profile.php?id='.$suId.'&tab=medical'); exit;
    }

    // ── Add care plan ───
    if ($act === 'add_care_plan') {
        $title   = trim($_POST['cp_title']  ?? '');
        $ptype   = trim($_POST['cp_type']   ?? 'General');
        $content = trim($_POST['cp_content']?? '');
        $rdate   = $_POST['cp_review_date'] ?: null;
        $status  = trim($_POST['cp_status'] ?? 'Active');
        if (!$title) { setFlash('error','Care plan title required.'); header('Location: su_profile.php?id='.$suId.'&tab=careplans'); exit; }
        try {
            $pdo->prepare("INSERT INTO care_plans (organisation_id,service_user_id,title,plan_type,content,status,review_date,created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$title,$ptype,$content,$status,$rdate,$uid]);
            $cpId = (int)$pdo->lastInsertId();
            // Handle multiple file uploads
            if (!empty($_FILES['cp_files']['name'][0])) {
                foreach ($_FILES['cp_files']['name'] as $fi => $fname) {
                    if (empty($fname)) continue;
                    $orig = basename($fname);
                    $ext  = strtolower(pathinfo($orig,PATHINFO_EXTENSION));
                    $safe = 'cp_'.$cpId.'_'.time().'_'.$fi.'.'.$ext;
                    if (move_uploaded_file($_FILES['cp_files']['tmp_name'][$fi], $cpDocDir.$safe)) {
                        $pdo->prepare("INSERT INTO care_plan_documents (organisation_id,care_plan_id,service_user_id,doc_type,title,file_name,file_original,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$orgId,$cpId,$suId,'Care Plan',$orig,$safe,$orig,$_FILES['cp_files']['size'][$fi],$_FILES['cp_files']['type'][$fi],$uid]);
                    }
                }
            }
            setFlash('success','Care plan created.');
        } catch(Exception $e){ setFlash('error','Could not create: '.$e->getMessage()); }
        header('Location: su_profile.php?id='.$suId.'&tab=careplans'); exit;
    }

    // ── Edit care plan ───
    if ($act === 'edit_care_plan') {
        $cpId   = (int)($_POST['cp_id']??0);
        $title  = trim($_POST['cp_title']  ?? '');
        $ptype  = trim($_POST['cp_type']   ?? '');
        $content= trim($_POST['cp_content']?? '');
        $rdate  = $_POST['cp_review_date'] ?: null;
        $status = trim($_POST['cp_status'] ?? 'Active');
        try {
            $pdo->prepare("UPDATE care_plans SET title=?,plan_type=?,content=?,status=?,review_date=?,updated_at=NOW() WHERE id=? AND organisation_id=?")
                ->execute([$title,$ptype,$content,$status,$rdate,$cpId,$orgId]);
            // Handle additional file uploads
            if (!empty($_FILES['cp_files']['name'][0])) {
                foreach ($_FILES['cp_files']['name'] as $fi => $fname) {
                    if (empty($fname)) continue;
                    $orig = basename($fname);
                    $ext  = strtolower(pathinfo($orig,PATHINFO_EXTENSION));
                    $safe = 'cp_'.$cpId.'_'.time().'_'.$fi.'.'.$ext;
                    if (move_uploaded_file($_FILES['cp_files']['tmp_name'][$fi], $cpDocDir.$safe)) {
                        $pdo->prepare("INSERT INTO care_plan_documents (organisation_id,care_plan_id,service_user_id,doc_type,title,file_name,file_original,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$orgId,$cpId,$suId,'Care Plan',$orig,$safe,$orig,$_FILES['cp_files']['size'][$fi],$_FILES['cp_files']['type'][$fi],$uid]);
                    }
                }
            }
            setFlash('success','Care plan updated.');
        } catch(Exception $e){ setFlash('error','Could not update: '.$e->getMessage()); }
        header('Location: su_profile.php?id='.$suId.'&tab=careplans'); exit;
    }

    // ── Delete care plan ───
    if ($act === 'del_care_plan') {
        $cpId = (int)($_POST['cp_id']??0);
        try {
            $docs = $pdo->prepare("SELECT file_name FROM care_plan_documents WHERE care_plan_id=? AND organisation_id=?");
            $docs->execute([$cpId,$orgId]);
            foreach ($docs->fetchAll() as $d) @unlink($cpDocDir.$d['file_name']);
            $pdo->prepare("DELETE FROM care_plan_documents WHERE care_plan_id=? AND organisation_id=?")->execute([$cpId,$orgId]);
            $pdo->prepare("DELETE FROM care_plans WHERE id=? AND organisation_id=?")->execute([$cpId,$orgId]);
            setFlash('success','Care plan deleted.');
        } catch(Exception $e){ setFlash('error','Could not delete.'); }
        header('Location: su_profile.php?id='.$suId.'&tab=careplans'); exit;
    }

    // ── Delete care plan document ───
    if ($act === 'del_cp_doc') {
        $did = (int)($_POST['doc_id']??0);
        try {
            $d = $pdo->prepare("SELECT file_name FROM care_plan_documents WHERE id=? AND organisation_id=?");
            $d->execute([$did,$orgId]); $d=$d->fetch();
            if ($d) @unlink($cpDocDir.$d['file_name']);
            $pdo->prepare("DELETE FROM care_plan_documents WHERE id=? AND organisation_id=?")->execute([$did,$orgId]);
            setFlash('success','Document removed.');
        } catch(Exception $e){ setFlash('error','Could not delete.'); }
        header('Location: su_profile.php?id='.$suId.'&tab=careplans'); exit;
    }

    // ── Upload / Write document ───
    if ($act === 'upload_doc' || $act === 'write_doc') {
        $docType  = trim($_POST['doc_type']  ?? 'Other');
        $docTitle = trim($_POST['doc_title'] ?? '');
        $docContent = trim($_POST['doc_content'] ?? '');
        if (!$docTitle) { setFlash('error','Document title required.'); header('Location: su_profile.php?id='.$suId.'&tab=docs'); exit; }

        $fname = $forig = $fsize = $ftype = null;
        if (!empty($_FILES['doc_file']['name'])) {
            $orig = basename($_FILES['doc_file']['name']);
            $ext  = strtolower(pathinfo($orig,PATHINFO_EXTENSION));
            $safe = 'su_'.$suId.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $suDocDir.$safe)) {
                $fname=$safe; $forig=$orig; $fsize=$_FILES['doc_file']['size']; $ftype=$_FILES['doc_file']['type'];
            } else { setFlash('error','Upload failed. Check server permissions.'); header('Location: su_profile.php?id='.$suId.'&tab=docs'); exit; }
        }
        if (!$fname && !$docContent) { setFlash('error','Please write content or upload a file.'); header('Location: su_profile.php?id='.$suId.'&tab=docs'); exit; }
        try {
            $pdo->prepare("INSERT INTO su_documents (organisation_id,service_user_id,doc_type,title,content,file_name,file_original,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$suId,$docType,$docTitle,$docContent,$fname,$forig,$fsize,$ftype,$uid]);
            setFlash('success','Document saved.');
        } catch(Exception $e){ setFlash('error','DB error: '.$e->getMessage()); }
        header('Location: su_profile.php?id='.$suId.'&tab=docs'); exit;
    }

    // ── Delete document ───
    if ($act === 'del_doc') {
        $did = (int)($_POST['doc_id']??0);
        try {
            $d = $pdo->prepare("SELECT file_name FROM su_documents WHERE id=? AND organisation_id=?");
            $d->execute([$did,$orgId]); $d=$d->fetch();
            if ($d && $d['file_name']) @unlink($suDocDir.$d['file_name']);
            $pdo->prepare("DELETE FROM su_documents WHERE id=? AND organisation_id=?")->execute([$did,$orgId]);
            setFlash('success','Document deleted.');
        } catch(Exception $e){ setFlash('error','Could not delete.'); }
        header('Location: su_profile.php?id='.$suId.'&tab=docs'); exit;
    }

}

// ── Query data ────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'overview';
$medTypes  = ['General','Diagnosis','Allergy','Medication','Surgery','Mental Health','Chronic Condition','Vaccination','Referral','Other'];
$cpTypes   = ['General','Personal Care','Dementia','Palliative','Mental Health','Physical Disability','Sensory','Nutritional','Mobility','Behaviour Support','Other'];
$docTypes  = ['Medical History','Care Plan','Risk Assessment','Mental Capacity Assessment','DNACPR','Lasting Power of Attorney','GP Letter','Hospital Discharge','Referral','Consent Form','Incident Report','Risk Assessment','Safeguarding','Personal Preferences','End of Life','Medication Review','Other'];

// Medical history
$medHistory = [];
try {
    $q=$pdo->prepare("SELECT mh.*,u.first_name AS recorded_by_name FROM su_medical_history mh LEFT JOIN users u ON mh.recorded_by=u.id WHERE mh.service_user_id=? AND mh.organisation_id=? ORDER BY mh.entry_date DESC,mh.created_at DESC");
    $q->execute([$suId,$orgId]); $medHistory=$q->fetchAll();
} catch(Exception $e){}

// Care plans
$carePlans = [];
try {
    $q=$pdo->prepare("SELECT cp.*,u.first_name AS created_by_name FROM care_plans cp LEFT JOIN users u ON cp.created_by=u.id WHERE cp.service_user_id=? AND cp.organisation_id=? ORDER BY cp.created_at DESC");
    $q->execute([$suId,$orgId]); $carePlans=$q->fetchAll();
} catch(Exception $e){}

// Care plan docs (indexed by plan id)
$cpDocMap = [];
try {
    $q=$pdo->prepare("SELECT * FROM care_plan_documents WHERE service_user_id=? AND organisation_id=? ORDER BY created_at ASC");
    $q->execute([$suId,$orgId]);
    foreach ($q->fetchAll() as $d) $cpDocMap[$d['care_plan_id']][] = $d;
} catch(Exception $e){}

// SU documents
$suDocs = [];
try {
    $q=$pdo->prepare("SELECT sd.*,u.first_name AS uploader FROM su_documents sd LEFT JOIN users u ON sd.uploaded_by=u.id WHERE sd.service_user_id=? AND sd.organisation_id=? ORDER BY sd.created_at DESC");
    $q->execute([$suId,$orgId]); $suDocs=$q->fetchAll();
} catch(Exception $e){}

// Overview: recent visits
$recentVisits = [];
try {
    $q=$pdo->prepare("SELECT v.*,COALESCE(u.first_name,'') fn,COALESCE(u.last_name,'') ln FROM visits v LEFT JOIN users u ON v.carer_id=u.id WHERE v.service_user_id=? AND v.organisation_id=? ORDER BY v.visit_date DESC, v.start_time DESC LIMIT 10");
    $q->execute([$suId,$orgId]); $recentVisits=$q->fetchAll();
} catch(Exception $e){}

// Medications
$meds = [];
try {
    $q=$pdo->prepare("SELECT * FROM medications WHERE service_user_id=? AND organisation_id=? AND is_active=1 ORDER BY name");
    $q->execute([$suId,$orgId]); $meds=$q->fetchAll();
} catch(Exception $e){}

// Care logs
$careLogs = [];
try {
    $q=$pdo->prepare("SELECT cl.*,CONCAT(u.first_name,' ',u.last_name) AS staff_name FROM care_logs cl LEFT JOIN users u ON cl.staff_id=u.id WHERE cl.service_user_id=? AND cl.organisation_id=? ORDER BY cl.log_date DESC,cl.created_at DESC LIMIT 20");
    $q->execute([$suId,$orgId]); $careLogs=$q->fetchAll();
} catch(Exception $e){}

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Profile header ─────────────────────────────────────────────── -->
<div class="bg-gradient-to-r from-teal-700 to-teal-600 rounded-2xl p-5 mb-5 flex items-center gap-4 shadow">
  <div class="w-16 h-16 rounded-2xl bg-white/20 flex items-center justify-center flex-shrink-0 text-3xl text-white font-extrabold">
    <?= strtoupper(substr($su['first_name']??'?',0,1).substr($su['last_name']??'',0,1)) ?>
  </div>
  <div class="flex-1 min-w-0">
    <h1 class="text-white font-extrabold text-xl leading-tight"><?= h($su['first_name'].' '.$su['last_name']) ?></h1>
    <div class="flex flex-wrap gap-2 mt-1.5 text-xs">
      <?php if (!empty($su['nhs_number'])): ?>
      <span class="bg-white/20 text-white px-2.5 py-1 rounded-full"><i class="fa fa-id-card mr-1"></i>NHS: <?= h($su['nhs_number']) ?></span>
      <?php endif; ?>
      <?php if (!empty($su['date_of_birth'])): ?>
      <span class="bg-white/20 text-white px-2.5 py-1 rounded-full"><i class="fa fa-birthday-cake mr-1"></i><?= date('d M Y',strtotime($su['date_of_birth'])) ?></span>
      <?php endif; ?>
      <?php if (!empty($su['funding_type'])): ?>
      <span class="bg-white/20 text-white px-2.5 py-1 rounded-full"><i class="fa fa-pound-sign mr-1"></i><?= h($su['funding_type']) ?></span>
      <?php endif; ?>
      <span class="bg-<?= $su['is_active']?'green':'red' ?>-500/40 text-white px-2.5 py-1 rounded-full font-bold"><?= $su['is_active']?'Active':'Inactive' ?></span>
    </div>
  </div>
  <a href="service_users.php" class="text-white/70 hover:text-white text-sm flex items-center gap-1 flex-shrink-0">
    <i class="fa fa-arrow-left"></i><span class="hidden sm:inline">Back</span>
  </a>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────────── -->
<div class="flex gap-1 flex-wrap mb-5 bg-white rounded-2xl shadow p-1.5">
<?php
$tabs = [
  ['overview',   'fa-house',           'Overview'],
  ['medical',    'fa-heart-pulse',     'Medical History'],
  ['careplans',  'fa-clipboard-list',  'Care Plans'],
  ['docs',       'fa-folder-open',     'Documents'],
  ['carelogs',   'fa-clipboard-check', 'Care Logs'],
];
foreach ($tabs as $t):
  $active = ($activeTab===$t[0]);
?>
<a href="su_profile.php?id=<?= $suId ?>&tab=<?= $t[0] ?>"
   class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold transition <?= $active?'bg-teal-600 text-white shadow':'text-gray-500 hover:bg-gray-100' ?>">
  <i class="fa <?= $t[1] ?>"></i><span class="hidden sm:inline"><?= $t[2] ?></span>
</a>
<?php endforeach; ?>
</div>

<?php if ($flash = getFlash()): ?>
<div class="mb-4 px-4 py-3 rounded-2xl font-semibold text-sm <?= $flash['type']==='success'?'bg-green-50 text-green-800 border border-green-200':'bg-red-50 text-red-800 border border-red-200' ?>">
  <i class="fa <?= $flash['type']==='success'?'fa-check-circle text-green-500':'fa-circle-exclamation text-red-500' ?> mr-2"></i><?= h($flash['msg']) ?>
</div>
<?php endif; ?>

<?php if ($activeTab === 'overview'): ?>
<!-- ══ OVERVIEW ════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Personal details -->
  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="bg-gradient-to-r from-teal-600 to-teal-500 px-5 py-3">
      <h3 class="text-white font-extrabold text-sm"><i class="fa fa-user mr-2"></i>Personal Details</h3>
    </div>
    <div class="p-4 space-y-2">
      <?php foreach (['address'=>'Address','phone'=>'Phone','email'=>'Email','emergency_contact'=>'Emergency Contact','gp_details'=>'GP Details'] as $k=>$lbl): ?>
      <?php if (!empty($su[$k])): ?>
      <div class="flex gap-2 text-sm"><span class="text-gray-400 font-bold w-36 flex-shrink-0"><?= $lbl ?></span><span class="text-gray-700"><?= h($su[$k]) ?></span></div>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!empty($su['council_name'])): ?>
      <div class="flex gap-2 text-sm"><span class="text-gray-400 font-bold w-36 flex-shrink-0">Council</span><span class="text-gray-700"><?= h($su['council_name']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
  <!-- Medications -->
  <div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="bg-gradient-to-r from-purple-600 to-purple-500 px-5 py-3">
      <h3 class="text-white font-extrabold text-sm"><i class="fa fa-pills mr-2"></i>Current Medications (<?= count($meds) ?>)</h3>
    </div>
    <?php if (empty($meds)): ?>
    <div class="p-8 text-center text-gray-400 text-sm"><i class="fa fa-pills text-3xl block mb-2 text-gray-200"></i>No medications recorded</div>
    <?php else: ?>
    <div class="divide-y divide-gray-50">
      <?php foreach ($meds as $m): ?>
      <div class="px-4 py-3 flex items-start gap-2">
        <i class="fa fa-pills text-purple-400 mt-0.5 flex-shrink-0"></i>
        <div><div class="font-bold text-sm text-gray-800"><?= h($m['name']) ?></div>
        <div class="text-xs text-gray-400"><?= h($m['dose']??'') ?> <?= h($m['route']??'') ?> — <?= h($m['frequency']??'') ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <!-- Recent visits -->
  <div class="bg-white rounded-2xl shadow overflow-hidden lg:col-span-2">
    <div class="bg-gradient-to-r from-blue-600 to-blue-500 px-5 py-3">
      <h3 class="text-white font-extrabold text-sm"><i class="fa fa-route mr-2"></i>Recent Visits</h3>
    </div>
    <?php if (empty($recentVisits)): ?>
    <div class="p-8 text-center text-gray-400 text-sm"><i class="fa fa-route text-3xl block mb-2 text-gray-200"></i>No visits recorded</div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs font-bold text-gray-500">
          <tr><th class="px-4 py-2 text-left">Date</th><th class="px-4 py-2 text-left">Carer</th><th class="px-4 py-2 text-left">Time</th><th class="px-4 py-2 text-left">Actual</th><th class="px-4 py-2 text-left">Status</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($recentVisits as $v):
            $sc=['Completed'=>'bg-green-100 text-green-700','Scheduled'=>'bg-blue-100 text-blue-700','Missed'=>'bg-red-100 text-red-700','Cancelled'=>'bg-gray-100 text-gray-500'];
            $vc=$sc[$v['status']??'']??'bg-gray-100 text-gray-600';
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2.5 font-semibold"><?= $v['visit_date']?date('d M',strtotime($v['visit_date'])):'' ?></td>
            <td class="px-4 py-2.5"><?= h(trim($v['fn'].' '.$v['ln'])) ?: '<span class="text-gray-300">—</span>' ?></td>
            <td class="px-4 py-2.5 text-gray-500"><?= $v['start_time']??'' ?> – <?= $v['end_time']??'' ?></td>
            <td class="px-4 py-2.5 text-gray-500"><?= $v['actual_start_time']??'' ?> <?= $v['actual_end_time']?'– '.$v['actual_end_time']:'' ?></td>
            <td class="px-4 py-2.5"><span class="<?= $vc ?> px-2 py-0.5 rounded-full text-xs font-bold"><?= h($v['status']??'') ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($activeTab === 'medical'): ?>
<!-- ══ MEDICAL HISTORY ═════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="bg-white rounded-2xl shadow mb-5 overflow-hidden">
  <div class="bg-gradient-to-r from-red-600 to-red-700 px-5 py-4 flex items-center gap-3">
    <i class="fa fa-plus-circle text-white text-lg"></i>
    <div><h3 class="text-white font-extrabold">Add Medical History Entry</h3>
    <p class="text-red-200 text-xs mt-0.5">Write notes and/or attach any document (PDF, Word, image, etc.)</p></div>
  </div>
  <form method="POST" enctype="multipart/form-data" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_med_history">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
      <div class="sm:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Title <span class="text-red-400">*</span></label>
        <input type="text" name="mh_title" required placeholder="e.g. Type 2 Diabetes Diagnosis"
               class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-red-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Date of Entry</label>
        <input type="date" name="mh_entry_date" value="<?= date('Y-m-d') ?>"
               class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-red-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Category</label>
        <select name="mh_type" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-white focus:border-red-400 focus:outline-none">
          <?php foreach ($medTypes as $mt): ?><option><?= h($mt) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Attach Document <span class="text-gray-400 font-normal">(optional — all formats accepted)</span></label>
        <input type="file" name="mh_file" accept="*/*"
               class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:outline-none text-gray-600">
        <p class="text-xs text-gray-400 mt-1">PDF, Word (.doc/.docx), Excel, images, scanned docs — any format</p>
      </div>
    </div>
    <div class="mb-4">
      <div class="flex items-center justify-between mb-1">
        <label class="block text-xs font-bold text-gray-600">Notes / Details <span class="text-gray-400 font-normal">(optional if uploading a file)</span></label>
        <button type="button" onclick="aiAssist('mh_content','medical history entry')" class="ai-btn"><i class="fa fa-wand-magic-sparkles mr-1"></i>AI Assist</button>
      </div>
      <textarea name="mh_content" id="mh_content" rows="5" placeholder="Enter detailed medical history notes, diagnoses, treatment plans, observations..."
                class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-red-400 focus:outline-none resize-y"></textarea>
    </div>
    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-extrabold px-6 py-2.5 rounded-xl text-sm transition">
      <i class="fa fa-plus mr-1"></i>Add Medical History Entry
    </button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($medHistory)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
  <i class="fa fa-heart-pulse text-5xl text-gray-200 block mb-3"></i>
  <p class="text-gray-500 font-semibold">No medical history entries yet.</p>
  <?php if ($isAdmin): ?><p class="text-xs text-gray-400 mt-1">Use the form above to add the first entry.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($medHistory as $mh):
  $ext = $mh['file_original'] ? strtolower(pathinfo($mh['file_original'],PATHINFO_EXTENSION)) : '';
  $iconMap = ['pdf'=>'fa-file-pdf text-red-500','doc'=>'fa-file-word text-blue-500','docx'=>'fa-file-word text-blue-500','xls'=>'fa-file-excel text-green-500','xlsx'=>'fa-file-excel text-green-500','jpg'=>'fa-file-image text-pink-400','jpeg'=>'fa-file-image text-pink-400','png'=>'fa-file-image text-pink-400'];
  $fIco = isset($iconMap[$ext]) ? $iconMap[$ext] : 'fa-file text-gray-400';
?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="p-4 flex items-start gap-3">
    <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0 mt-0.5">
      <i class="fa fa-notes-medical text-red-500"></i>
    </div>
    <div class="flex-1 min-w-0">
      <div class="flex items-start justify-between gap-2 flex-wrap">
        <div>
          <div class="font-extrabold text-gray-800"><?= h($mh['title']) ?></div>
          <div class="flex flex-wrap gap-1.5 mt-1">
            <span class="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full font-bold"><?= h($mh['entry_type']) ?></span>
            <span class="text-xs text-gray-400"><?= $mh['entry_date']?date('d M Y',strtotime($mh['entry_date'])):'' ?></span>
            <span class="text-xs text-gray-400">&bull; <?= h($mh['recorded_by_name']??'Unknown') ?></span>
          </div>
        </div>
        <?php if ($isAdmin): ?>
        <form method="POST" onsubmit="return confirm('Delete this entry?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="del_med_history">
          <input type="hidden" name="mh_id" value="<?= $mh['id'] ?>">
          <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($mh['content']): ?>
      <div class="mt-3 bg-gray-50 rounded-xl p-3 text-sm text-gray-700 whitespace-pre-line leading-relaxed"><?= h($mh['content']) ?></div>
      <?php endif; ?>
      <?php if ($mh['file_name']): ?>
      <div class="mt-2 flex items-center gap-2">
        <i class="fa <?= $fIco ?> text-lg"></i>
        <a href="../uploads/su_medical/<?= rawurlencode($mh['file_name']) ?>" target="_blank"
           class="text-sm text-blue-600 hover:underline font-semibold"><?= h($mh['file_original']) ?></a>
        <span class="text-xs text-gray-400">(<?= round(($mh['file_size']??0)/1024) ?>KB)</span>
        <a href="../uploads/su_medical/<?= rawurlencode($mh['file_name']) ?>" download
           class="ml-auto text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-100 px-2.5 py-1 rounded-xl"><i class="fa fa-download"></i> Download</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'careplans'): ?>
<!-- ══ CARE PLANS ══════════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="bg-white rounded-2xl shadow mb-5 overflow-hidden">
  <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-5 py-4 flex items-center gap-3">
    <i class="fa fa-clipboard-list text-white text-lg"></i>
    <div><h3 class="text-white font-extrabold">Create New Care Plan</h3>
    <p class="text-blue-200 text-xs mt-0.5">Write the care plan and attach multiple supporting documents</p></div>
  </div>
  <form method="POST" enctype="multipart/form-data" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_care_plan">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
      <div class="sm:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Plan Title <span class="text-red-400">*</span></label>
        <input type="text" name="cp_title" required placeholder="e.g. Personal Care Plan 2025"
               class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-blue-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Plan Type</label>
        <select name="cp_type" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-white focus:border-blue-400 focus:outline-none">
          <?php foreach ($cpTypes as $ct): ?><option><?= h($ct) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Status</label>
        <select name="cp_status" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-white focus:border-blue-400 focus:outline-none">
          <option>Active</option><option>Under Review</option><option>Completed</option><option>On Hold</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Review Date</label>
        <input type="date" name="cp_review_date" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-blue-400 focus:outline-none">
      </div>
    </div>
    <div class="mb-4">
      <div class="flex items-center justify-between mb-1">
        <label class="block text-xs font-bold text-gray-600">Care Plan Content</label>
        <button type="button" onclick="aiAssist('cp_content','care plan')" class="ai-btn"><i class="fa fa-wand-magic-sparkles mr-1"></i>AI Assist</button>
      </div>
      <textarea name="cp_content" id="cp_content" rows="7" placeholder="Write the full care plan — goals, needs, interventions, outcomes, review schedule, person-centred preferences..."
                class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-blue-400 focus:outline-none resize-y"></textarea>
    </div>
    <!-- Multiple file upload -->
    <div class="mb-5 bg-blue-50 border border-blue-200 rounded-2xl p-4">
      <label class="block text-xs font-bold text-blue-700 mb-2"><i class="fa fa-paperclip mr-1"></i>Attach Supporting Documents <span class="text-blue-400 font-normal">(optional — select multiple)</span></label>
      <input type="file" name="cp_files[]" accept="*/*" multiple
             class="w-full border border-blue-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none text-gray-600">
      <p class="text-xs text-blue-500 mt-1.5">You can select multiple files at once. All formats accepted — PDF, Word, Excel, images, scanned documents, etc.</p>
    </div>
    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-extrabold px-7 py-3 rounded-xl text-sm transition">
      <i class="fa fa-plus mr-1.5"></i>Create Care Plan
    </button>
  </form>
</div>
<?php endif; ?>

<?php if (empty($carePlans)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
  <i class="fa fa-clipboard-list text-5xl text-gray-200 block mb-3"></i>
  <p class="text-gray-500 font-semibold">No care plans yet.</p>
  <?php if ($isAdmin): ?><p class="text-xs text-gray-400 mt-1">Use the form above to create the first care plan.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="space-y-4">
<?php foreach ($carePlans as $cp):
  $sc=['Active'=>'bg-green-100 text-green-700','Under Review'=>'bg-amber-100 text-amber-700','Completed'=>'bg-blue-100 text-blue-700','On Hold'=>'bg-gray-100 text-gray-600'];
  $scc=$sc[$cp['status']]??'bg-gray-100 text-gray-600';
  $cpdocs=$cpDocMap[$cp['id']]??[];
?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <!-- Plan header -->
  <div class="flex items-start gap-3 p-4 border-b border-gray-100">
    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0 mt-0.5">
      <i class="fa fa-clipboard-list text-blue-600"></i>
    </div>
    <div class="flex-1 min-w-0">
      <div class="flex items-start justify-between gap-2 flex-wrap">
        <div>
          <div class="font-extrabold text-gray-800"><?= h($cp['title']) ?></div>
          <div class="flex flex-wrap gap-1.5 mt-1">
            <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-bold"><?= h($cp['plan_type']) ?></span>
            <span class="<?= $scc ?> text-xs px-2 py-0.5 rounded-full font-bold"><?= h($cp['status']) ?></span>
            <span class="text-xs text-gray-400">&bull; <?= date('d M Y',strtotime($cp['created_at'])) ?> by <?= h($cp['created_by_name']??'Unknown') ?></span>
            <?php if ($cp['review_date']): ?>
            <span class="text-xs text-amber-600 font-semibold"><i class="fa fa-clock mr-0.5"></i>Review: <?= date('d M Y',strtotime($cp['review_date'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="flex gap-1.5 flex-shrink-0">
          <button onclick="toggleEdit(<?= $cp['id'] ?>)" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-pencil"></i></button>
          <form method="POST" onsubmit="return confirm('Delete this care plan and all its documents?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="del_care_plan">
            <input type="hidden" name="cp_id" value="<?= $cp['id'] ?>">
            <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-trash"></i></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Plan content -->
  <?php if ($cp['content']): ?>
  <div class="px-4 py-3 bg-gray-50 text-sm text-gray-700 whitespace-pre-line leading-relaxed"><?= h($cp['content']) ?></div>
  <?php endif; ?>

  <!-- Attached documents -->
  <?php if (!empty($cpdocs)): ?>
  <div class="px-4 py-3 border-t border-gray-100">
    <div class="text-xs font-bold text-gray-500 mb-2 uppercase tracking-wide"><i class="fa fa-paperclip mr-1"></i>Attached Documents (<?= count($cpdocs) ?>)</div>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($cpdocs as $d):
        $dext=strtolower(pathinfo($d['file_original'],PATHINFO_EXTENSION));
        $dico=['pdf'=>'fa-file-pdf text-red-500','doc'=>'fa-file-word text-blue-500','docx'=>'fa-file-word text-blue-500','xls'=>'fa-file-excel text-green-500','xlsx'=>'fa-file-excel text-green-500','jpg'=>'fa-file-image text-pink-400','jpeg'=>'fa-file-image text-pink-400','png'=>'fa-file-image text-pink-400'];
        $di=isset($dico[$dext])?$dico[$dext]:'fa-file text-gray-400';
      ?>
      <div class="flex items-center gap-1.5 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2">
        <i class="fa <?= $di ?> text-sm"></i>
        <a href="../uploads/care_plan_docs/<?= rawurlencode($d['file_name']) ?>" target="_blank"
           class="text-xs text-blue-700 hover:underline font-semibold max-w-[120px] truncate"><?= h($d['file_original']) ?></a>
        <span class="text-xs text-gray-400"><?= round(($d['file_size']??0)/1024) ?>KB</span>
        <a href="../uploads/care_plan_docs/<?= rawurlencode($d['file_name']) ?>" download class="text-blue-500 hover:text-blue-700 ml-1"><i class="fa fa-download text-xs"></i></a>
        <?php if ($isAdmin): ?>
        <form method="POST" onsubmit="return confirm('Remove this document?')" class="inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="del_cp_doc">
          <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
          <button type="submit" class="text-red-400 hover:text-red-600 ml-1"><i class="fa fa-times text-xs"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Inline edit form (hidden) -->
  <?php if ($isAdmin): ?>
  <div id="editForm<?= $cp['id'] ?>" style="display:none;" class="border-t border-blue-100 bg-blue-50 p-4">
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_care_plan">
      <input type="hidden" name="cp_id" value="<?= $cp['id'] ?>">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-bold text-gray-600 mb-1">Title</label>
          <input type="text" name="cp_title" value="<?= h($cp['title']) ?>" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Type</label>
          <select name="cp_type" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-blue-400 focus:outline-none">
            <?php foreach ($cpTypes as $ct): ?><option <?= $ct===$cp['plan_type']?'selected':'' ?>><?= h($ct) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Status</label>
          <select name="cp_status" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-blue-400 focus:outline-none">
            <?php foreach (['Active','Under Review','Completed','On Hold'] as $s): ?><option <?= $s===$cp['status']?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Review Date</label>
          <input type="date" name="cp_review_date" value="<?= h($cp['review_date']??'') ?>" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">Content</label>
        <textarea name="cp_content" rows="5" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-blue-400 focus:outline-none resize-y"><?= h($cp['content']??'') ?></textarea>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">Add More Documents <span class="text-gray-400 font-normal">(any format, select multiple)</span></label>
        <input type="file" name="cp_files[]" accept="*/*" multiple class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:outline-none">
      </div>
      <div class="flex gap-2">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 py-2 rounded-xl text-sm"><i class="fa fa-save mr-1"></i>Save Changes</button>
        <button type="button" onclick="toggleEdit(<?= $cp['id'] ?>)" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-5 py-2 rounded-xl text-sm">Cancel</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'docs'): ?>
<!-- ══ DOCUMENTS ══════════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="bg-white rounded-2xl shadow mb-5 overflow-hidden">
  <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-5 py-4 flex items-center gap-3">
    <i class="fa fa-folder-open text-white text-lg"></i>
    <div><h3 class="text-white font-extrabold">Add Document</h3>
    <p class="text-purple-200 text-xs mt-0.5">Write a document or upload any file — or both</p></div>
  </div>
  <form method="POST" enctype="multipart/form-data" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upload_doc">

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
      <div class="sm:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Document Title <span class="text-red-400">*</span></label>
        <input type="text" name="doc_title" required placeholder="e.g. Risk Assessment June 2025, GP Referral Letter, LPA Certificate..."
               class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-purple-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Document Category</label>
        <select name="doc_type" class="w-full border rounded-xl px-3 py-2.5 text-sm bg-white focus:border-purple-400 focus:outline-none">
          <?php foreach ($docTypes as $dt): ?><option><?= h($dt) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Written content -->
    <div class="mb-4">
      <div class="flex items-center justify-between mb-1">
        <label class="block text-xs font-bold text-gray-600">Written Content <span class="text-gray-400 font-normal">(leave blank if uploading a file only)</span></label>
        <button type="button" onclick="aiAssist('doc_content','document')" class="ai-btn"><i class="fa fa-wand-magic-sparkles mr-1"></i>AI Assist</button>
      </div>
      <textarea name="doc_content" id="doc_content" rows="8" placeholder="Write or paste the full document content here — risk assessments, preferences, notes, referral letters, assessments..."
                class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-purple-400 focus:outline-none resize-y"></textarea>
    </div>

    <!-- File upload -->
    <div class="bg-purple-50 border border-purple-200 rounded-2xl p-4 mb-4">
      <label class="block text-xs font-bold text-purple-700 mb-2"><i class="fa fa-upload mr-1"></i>Upload File <span class="text-purple-400 font-normal">(optional — all formats accepted)</span></label>
      <input type="file" name="doc_file" accept="*/*"
             class="w-full border border-purple-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none text-gray-600">
      <p class="text-xs text-purple-500 mt-1.5">PDF, Word (.doc/.docx), Excel (.xls/.xlsx), images (JPG/PNG), scanned documents — any format. Max size governed by server PHP settings.</p>
    </div>

    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-extrabold px-7 py-3 rounded-xl text-sm transition">
      <i class="fa fa-save mr-1.5"></i>Save Document
    </button>
  </form>
</div>
<?php endif; ?>

<!-- Filter bar -->
<?php if (!empty($suDocs)): ?>
<div class="bg-white rounded-2xl shadow p-3 mb-4 flex flex-wrap gap-2 items-center">
  <span class="text-xs font-bold text-gray-500">Filter:</span>
  <button onclick="filterDocs('')" class="filter-btn active-filter text-xs px-3 py-1.5 rounded-xl font-bold bg-purple-600 text-white">All (<?= count($suDocs) ?>)</button>
  <?php
  $dtCounts = [];
  foreach ($suDocs as $d) $dtCounts[$d['doc_type']] = ($dtCounts[$d['doc_type']]??0)+1;
  foreach ($dtCounts as $dt => $cnt):
  ?>
  <button onclick="filterDocs('<?= h($dt) ?>')" class="filter-btn text-xs px-3 py-1.5 rounded-xl font-bold bg-gray-100 text-gray-600 hover:bg-purple-100 hover:text-purple-700"><?= h($dt) ?> (<?= $cnt ?>)</button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($suDocs)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
  <i class="fa fa-folder-open text-5xl text-gray-200 block mb-3"></i>
  <p class="text-gray-500 font-semibold">No documents saved yet.</p>
  <?php if ($isAdmin): ?><p class="text-xs text-gray-400 mt-1">Use the form above to write or upload documents.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="space-y-3" id="docList">
<?php foreach ($suDocs as $d):
  $ext = $d['file_original'] ? strtolower(pathinfo($d['file_original'],PATHINFO_EXTENSION)) : '';
  $iconMap=['pdf'=>'fa-file-pdf text-red-500','doc'=>'fa-file-word text-blue-500','docx'=>'fa-file-word text-blue-500','xls'=>'fa-file-excel text-green-500','xlsx'=>'fa-file-excel text-green-500','jpg'=>'fa-file-image text-pink-400','jpeg'=>'fa-file-image text-pink-400','png'=>'fa-file-image text-pink-400'];
  $fIco=isset($iconMap[$ext])?$iconMap[$ext]:'fa-file text-gray-400';
  $hasContent = !empty($d['content']);
  $hasFile    = !empty($d['file_name']);
?>
<div class="doc-item bg-white rounded-2xl shadow overflow-hidden" data-type="<?= h($d['doc_type']) ?>">
  <div class="flex items-start gap-3 p-4">
    <div class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0 mt-0.5">
      <i class="fa <?= $hasFile?$fIco:'fa-file-lines text-purple-500' ?> text-lg"></i>
    </div>
    <div class="flex-1 min-w-0">
      <div class="flex items-start justify-between gap-2 flex-wrap">
        <div>
          <div class="font-extrabold text-gray-800"><?= h($d['title']) ?></div>
          <div class="flex flex-wrap gap-1.5 mt-1">
            <span class="bg-purple-100 text-purple-700 text-xs px-2 py-0.5 rounded-full font-bold"><?= h($d['doc_type']) ?></span>
            <?php if ($hasFile): ?><span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-bold"><i class="fa fa-paperclip mr-0.5"></i>File attached</span><?php endif; ?>
            <?php if ($hasContent): ?><span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full font-bold"><i class="fa fa-file-lines mr-0.5"></i>Written content</span><?php endif; ?>
            <span class="text-xs text-gray-400"><?= date('d M Y',strtotime($d['created_at'])) ?></span>
            <span class="text-xs text-gray-400">&bull; <?= h($d['uploader']??'Unknown') ?></span>
          </div>
        </div>
        <div class="flex gap-1.5 flex-shrink-0">
          <?php if ($hasFile): ?>
          <a href="../uploads/su_docs/<?= rawurlencode($d['file_name']) ?>" target="_blank"
             class="text-xs bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-eye"></i></a>
          <a href="../uploads/su_docs/<?= rawurlencode($d['file_name']) ?>" download
             class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-download"></i></a>
          <?php endif; ?>
          <?php if ($hasContent): ?>
          <button onclick="this.closest('.doc-item').querySelector('.doc-content').style.display=this.closest('.doc-item').querySelector('.doc-content').style.display==='none'?'block':'none'"
                  class="text-xs bg-green-50 hover:bg-green-100 text-green-700 border border-green-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-file-lines"></i> View</button>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
          <form method="POST" onsubmit="return confirm('Delete this document?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="del_doc">
            <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
            <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 px-2.5 py-1.5 rounded-xl"><i class="fa fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($hasFile): ?>
      <div class="mt-1.5 text-xs text-gray-400"><?= h($d['file_original']) ?> (<?= round(($d['file_size']??0)/1024) ?>KB)</div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($hasContent): ?>
  <div class="doc-content border-t border-gray-100 px-4 py-3 bg-gray-50 text-sm text-gray-700 whitespace-pre-line leading-relaxed" style="display:none;">
    <?= h($d['content']) ?>
    <div class="mt-3 flex gap-2">
      <button onclick="printDoc(<?= $d['id'] ?>)" class="text-xs bg-purple-100 hover:bg-purple-200 text-purple-700 px-3 py-1.5 rounded-xl font-bold"><i class="fa fa-print mr-1"></i>Print</button>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<!-- Hidden print areas -->
<?php foreach ($suDocs as $d): if (empty($d['content'])) continue; ?>
<div id="print<?= $d['id'] ?>" style="display:none">
  <h2><?= h($su['first_name'].' '.$su['last_name']) ?> — <?= h($d['title']) ?></h2>
  <p><strong>Category:</strong> <?= h($d['doc_type']) ?> | <strong>Date:</strong> <?= date('d M Y',strtotime($d['created_at'])) ?></p>
  <hr>
  <pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;"><?= h($d['content']) ?></pre>
  <hr><p style="font-size:11px;color:#888;">Register My Care | <?= h($orgName??'') ?> | <?= date('d/m/Y H:i') ?></p>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($activeTab === 'carelogs'): ?>
<!-- ══ CARE LOGS ══════════════════════════════════════════════════ -->
<!-- Info banner directing to visits/rota -->
<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-5 flex items-start gap-3">
  <i class="fa fa-info-circle text-blue-500 text-lg mt-0.5 flex-shrink-0"></i>
  <div>
    <p class="text-sm font-extrabold text-blue-800">Care logs are written from the Visits or Rota page</p>
    <p class="text-xs text-blue-600 mt-1">Staff write care logs during or after visits. Use <strong>My Visits</strong> or <strong>Rota</strong> to add new care notes with AI Assist.</p>
    <div class="flex gap-2 mt-2">
      <a href="my_visits.php" class="text-xs bg-blue-600 text-white font-bold px-3 py-1.5 rounded-xl hover:bg-blue-700"><i class="fa fa-route mr-1"></i>My Visits</a>
      <a href="rota.php" class="text-xs bg-white text-blue-600 border border-blue-300 font-bold px-3 py-1.5 rounded-xl hover:bg-blue-50"><i class="fa fa-calendar-days mr-1"></i>Rota</a>
    </div>
  </div>
</div>

<?php if (empty($careLogs)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
  <i class="fa fa-clipboard-check text-5xl text-gray-200 block mb-3"></i>
  <p class="text-gray-500 font-semibold">No care logs recorded yet.</p>
  <p class="text-xs text-gray-400 mt-1">Use the form above to add the first care log.</p>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($careLogs as $cl): ?>
<div class="bg-white rounded-2xl shadow p-4 flex items-start gap-3">
  <div class="w-9 h-9 rounded-xl bg-green-100 flex items-center justify-center flex-shrink-0">
    <i class="fa fa-clipboard-check text-green-600"></i>
  </div>
  <div class="flex-1 min-w-0">
    <div class="flex items-start justify-between gap-2 flex-wrap">
      <div>
        <span class="font-bold text-gray-800 text-sm"><?= h($cl['care_type']??'Care Visit') ?></span>
        <?php if (!empty($cl['mood'])): ?><span class="ml-2 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full"><?= h($cl['mood']) ?></span><?php endif; ?>
      </div>
      <span class="text-xs text-gray-400"><?= isset($cl['log_date'])?date('d M Y',strtotime($cl['log_date'])):'' ?></span>
    </div>
    <?php if (!empty($cl['notes'])): ?>
    <p class="text-sm text-gray-600 mt-1"><?= h($cl['notes']) ?></p>
    <?php endif; ?>
    <p class="text-xs text-gray-400 mt-1">By <?= h($cl['staff_name']??'Unknown') ?><?php if (!empty($cl['duration_mins'])): ?> · <?= $cl['duration_mins'] ?> min<?php endif; ?></p>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.ai-btn{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:white;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;border:none;cursor:pointer;transition:opacity .15s;white-space:nowrap;}
.ai-btn:hover{opacity:.85;}
.ai-btn:disabled{opacity:.5;cursor:not-allowed;}
#aiModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;}
#aiModal.open{display:flex;}
#aiModalBox{background:white;border-radius:20px;width:100%;max-width:600px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 25px 60px rgba(0,0,0,.3);}
#aiModalHead{background:linear-gradient(135deg,#7c3aed,#4f46e5);padding:16px 20px;border-radius:20px 20px 0 0;display:flex;align-items:center;justify-content:space-between;}
#aiModalBody{padding:16px;flex:1;overflow-y:auto;}
#aiOutput{min-height:120px;border:1px solid #e5e7eb;border-radius:12px;padding:12px;font-size:13px;line-height:1.6;white-space:pre-wrap;background:#f9fafb;}
</style>

<!-- AI Modal -->
<div id="aiModal">
  <div id="aiModalBox">
    <div id="aiModalHead">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center"><i class="fa fa-wand-magic-sparkles text-white"></i></div>
        <div>
          <div class="text-white font-extrabold text-sm">AI Writing Assistant</div>
          <div class="text-purple-200 text-xs" id="aiModalSub">Helping you write professional care notes</div>
        </div>
      </div>
      <button onclick="closeAI()" class="text-white/70 hover:text-white text-xl font-bold leading-none">&times;</button>
    </div>
    <div id="aiModalBody">
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">What do you want to write about? <span class="text-red-400">*</span></label>
        <textarea id="aiPrompt" rows="3"
          placeholder="e.g. Assisted with morning personal care. Service user was in good spirits, ate breakfast well. Remind about medication at 9am..."
          class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none resize-none"></textarea>
      </div>
      <div class="flex gap-2 mb-4">
        <button onclick="runAI()" id="aiRunBtn" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-extrabold py-2.5 rounded-xl text-sm transition flex items-center justify-center gap-2">
          <i class="fa fa-wand-magic-sparkles"></i> Generate Professional Draft
        </button>
      </div>
      <div id="aiOutputWrap" style="display:none;">
        <div class="flex items-center justify-between mb-1">
          <span class="text-xs font-bold text-gray-600">AI Draft — review and edit as needed</span>
          <button onclick="copyToField()" class="text-xs bg-purple-600 text-white font-bold px-3 py-1.5 rounded-xl hover:bg-purple-700"><i class="fa fa-check mr-1"></i>Use This</button>
        </div>
        <div id="aiOutput"></div>
        <p class="text-xs text-gray-400 mt-2">Always review AI output. Edit before saving to ensure accuracy.</p>
      </div>
    </div>
  </div>
</div>

<script>
var _aiTarget = null;
var _aiType   = '';

function aiAssist(fieldId, docType) {
  _aiTarget = document.getElementById(fieldId);
  _aiType   = docType;
  var existing = _aiTarget ? _aiTarget.value.trim() : '';
  document.getElementById('aiModalSub').textContent = 'Writing ' + docType + ' for <?= h(($su['first_name']??'').' '.($su['last_name']??'')) ?>';
  document.getElementById('aiPrompt').value = existing;
  document.getElementById('aiOutputWrap').style.display = 'none';
  document.getElementById('aiOutput').textContent = '';
  document.getElementById('aiModal').classList.add('open');
  setTimeout(function(){ document.getElementById('aiPrompt').focus(); }, 100);
}

function closeAI() {
  document.getElementById('aiModal').classList.remove('open');
}

function copyToField() {
  var text = document.getElementById('aiOutput').textContent;
  if (_aiTarget && text) {
    _aiTarget.value = text;
    closeAI();
  }
}

async function runAI() {
  var prompt = document.getElementById('aiPrompt').value.trim();
  if (!prompt) { alert('Please describe what you want to write about first.'); return; }
  var btn = document.getElementById('aiRunBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating...';
  document.getElementById('aiOutputWrap').style.display = 'none';

  var suName = '<?= h(($su['first_name']??'').' '.($su['last_name']??'')) ?>';
  var systemPrompt = 'You are a professional UK care home administrator writing formal care documentation. Write in a clear, professional, person-centred style appropriate for CQC inspection. Use first and third person appropriately. Keep to factual observations. Do not add fictional details beyond what is provided. For care logs: use past tense, be specific about care provided. For care plans: use structured format with goals, interventions and outcomes. For medical history: be clinical and precise. Output only the document text with no preamble, headers like "Here is..." or meta-commentary.';
  var userMsg = 'Service User: ' + suName + '
Document type: ' + _aiType + '
Key information / what to write about:
' + prompt + '

Write a professional ' + _aiType + ' based on the above.';

  try {
    var resp = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'anthropic-version': '2023-06-01', 'anthropic-beta': 'output-128k-2025-02-19' },
      body: JSON.stringify({
        model: 'claude-sonnet-4-20250514',
        max_tokens: 1000,
        system: systemPrompt,
        messages: [{ role: 'user', content: userMsg }]
      })
    });
    var data = await resp.json();
    var text = '';
    if (data.content && data.content.length > 0) {
      data.content.forEach(function(b) { if (b.type === 'text') text += b.text; });
    } else if (data.error) {
      text = 'Error: ' + (data.error.message || 'AI unavailable');
    }
    document.getElementById('aiOutput').textContent = text;
    document.getElementById('aiOutputWrap').style.display = 'block';
  } catch(e) {
    document.getElementById('aiOutput').textContent = 'AI service unavailable. Please write manually.';
    document.getElementById('aiOutputWrap').style.display = 'block';
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="fa fa-wand-magic-sparkles"></i> Generate Professional Draft';
}

// Close modal on background click
document.getElementById('aiModal').addEventListener('click', function(e) {
  if (e.target === this) closeAI();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAI(); });

function toggleEdit(id){
  var f=document.getElementById('editForm'+id);
  f.style.display=f.style.display==='none'?'block':'none';
}
function filterDocs(type){
  document.querySelectorAll('.doc-item').forEach(function(el){
    el.style.display=(!type||el.dataset.type===type)?'':'none';
  });
  document.querySelectorAll('.filter-btn').forEach(function(b){
    b.classList.remove('bg-purple-600','text-white');
    b.classList.add('bg-gray-100','text-gray-600');
  });
  event.target.classList.add('bg-purple-600','text-white');
  event.target.classList.remove('bg-gray-100','text-gray-600');
}
function printDoc(id){
  var w=window.open('','_blank','width=800,height=600');
  w.document.write('<html><head><title>Document</title><style>body{font-family:Arial,sans-serif;padding:30px;font-size:13px;}pre{white-space:pre-wrap;}@media print{button{display:none}}</style></head><body>');
  w.document.write(document.getElementById('print'+id).innerHTML);
  w.document.write('<br><button onclick="window.print()">Print</button></body></html>');
  w.document.close();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
