<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$pdo    = getPDO();
$orgId  = (int)$_SESSION['organisation_id'];
$uid    = (int)$_SESSION['user_id'];
$pageTitle = 'Staff Management';

// ── Self-heal tables ──────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `address` TEXT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `date_of_birth` DATE DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `ni_number` VARCHAR(20) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `dbs_on_update` TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `dbs_check_date` DATE DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `emergency_contact` VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `notes` TEXT DEFAULT NULL"); } catch(Exception $e){}

try { $pdo->exec("CREATE TABLE IF NOT EXISTS `staff_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `doc_category`    VARCHAR(80)  NOT NULL DEFAULT 'Other',
  `title`           VARCHAR(255) NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `file_type`       VARCHAR(120) DEFAULT '',
  `issue_date`      DATE DEFAULT NULL,
  `expiry_date`     DATE DEFAULT NULL,
  `notes`           VARCHAR(500) DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_staff` (`organisation_id`,`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

$docDir = __DIR__ . '/../uploads/staff_docs/';
try { $col=$pdo->query("SHOW COLUMNS FROM `users` LIKE 'job_category'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `job_category` VARCHAR(80) DEFAULT NULL"); } } catch(Exception $e){}
try { $col=$pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetch(); if($col && strpos(strtolower($col['Type']),'enum')!==false){ $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'Staff'"); } } catch(Exception $e){}
if (!is_dir($docDir)) { mkdir($docDir, 0755, true); file_put_contents($docDir . '.htaccess', "Options -Indexes\n"); }

$docCategories = array(
    'DBS Certificate', 'Enhanced DBS', 'Right to Work', 'Passport / ID',
    'NI Letter', 'Training Certificate', 'Manual Handling', 'First Aid',
    'Medication Competency', 'Fire Safety', 'Safeguarding', 'GDPR Training',
    'Food Hygiene', 'Driving Licence', 'Vehicle Insurance', 'Contract',
    'Reference', 'Appraisal', 'Disciplinary Record', 'Other'
);

$jobCategories = array(
    'Care Worker', 'Senior Care Worker', 'Support Worker', 'Team Leader',
    'Registered Manager', 'Deputy Manager', 'Nurse', 'Healthcare Assistant',
    'Administrator', 'Driver', 'Other'
);

$msg = ''; $msgType = 'success';
$activeStaffId = (int)($_GET['staff_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'profile';

// ── POST handlers ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $act = $_POST['action'] ?? '';

    // ── Add staff ──
    if ($act === 'add_staff') {
        $fn   = trim($_POST['first_name'] ?? '');
        $ln   = trim($_POST['last_name']  ?? '');
        $em   = trim($_POST['email']      ?? '');
        $role = $_POST['role']            ?? 'Staff';
        $jcat = trim($_POST['job_category'] ?? '');
        $pass = trim($_POST['password']   ?? '') ?: 'Welcome1!';
        if ($fn && $ln && $em) {
            $hash = password_hash($pass, PASSWORD_BCRYPT, array('cost'=>10));
            try {
                $pdo->prepare("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,role,job_category,is_active) VALUES (?,?,?,?,?,?,?,1)")
                    ->execute(array($orgId,$fn,$ln,$em,$hash,$role,$jcat));
                $newId = (int)$pdo->lastInsertId();
                addAuditLog($pdo, 'ADD_STAFF', 'users', $newId, "$fn $ln ($em)");
                setFlash('success', "Staff member $fn $ln added.");
            } catch(Exception $e){ setFlash('error','Could not add: '.$e->getMessage()); }
        } else { setFlash('error','First name, last name and email are required.'); }
        header('Location: staff.php'); exit;
    }

    // ── Edit staff profile ──
    if ($act === 'edit_staff') {
        $sid  = (int)$_POST['staff_id'];
        $fields = array(
            'first_name'       => trim($_POST['first_name'] ?? ''),
            'last_name'        => trim($_POST['last_name'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'role'             => $_POST['role'] ?? 'Staff',
            'job_category'     => trim($_POST['job_category'] ?? ''),
            'phone'            => trim($_POST['phone'] ?? ''),
            'address'          => trim($_POST['address'] ?? ''),
            'date_of_birth'    => $_POST['date_of_birth'] ?: null,
            'ni_number'        => trim($_POST['ni_number'] ?? ''),
            'dbs_on_update'    => isset($_POST['dbs_on_update']) ? (int)$_POST['dbs_on_update'] : 0,
            'dbs_check_date'   => $_POST['dbs_check_date'] ?: null,
            'emergency_contact'=> trim($_POST['emergency_contact'] ?? ''),
            'notes'            => trim($_POST['notes'] ?? ''),
        );
        if (!empty($_POST['new_password'])) {
            $fields['password_hash'] = password_hash($_POST['new_password'], PASSWORD_BCRYPT, array('cost'=>10));
        }
        $sets = implode(',', array_map(function($k){ return "`$k`=?"; }, array_keys($fields)));
        $vals = array_values($fields);
        $vals[] = $sid; $vals[] = $orgId;
        try {
            $pdo->prepare("UPDATE users SET $sets WHERE id=? AND organisation_id=?")->execute($vals);
            addAuditLog($pdo, 'EDIT_STAFF', 'users', $sid, 'Profile updated');
            setFlash('success', 'Staff profile updated.');
        } catch(Exception $e){ setFlash('error','Could not update: '.$e->getMessage()); }
        header('Location: staff.php?staff_id='.$sid.'&tab=profile'); exit;
    }

    // ── Deactivate staff ──
    if ($act === 'deactivate_staff') {
        $sid = (int)$_POST['staff_id'];
        try {
            $pdo->prepare("UPDATE users SET is_active=0 WHERE id=? AND organisation_id=?")->execute(array($sid,$orgId));
            addAuditLog($pdo,'DEACTIVATE_STAFF','users',$sid,'');
            setFlash('success','Staff member deactivated.');
        } catch(Exception $e){ setFlash('error','Could not deactivate.'); }
        header('Location: staff.php'); exit;
    }

    // ── Upload document ──
    if ($act === 'upload_doc') {
        $sid     = (int)$_POST['doc_staff_id'];
        $cat     = trim($_POST['doc_category'] ?? 'Other');
        $title   = trim($_POST['doc_title']    ?? '');
        $idate   = $_POST['doc_issue_date']   ?: null;
        $edate   = $_POST['doc_expiry_date']  ?: null;
        $dnotes  = trim($_POST['doc_notes']   ?? '');
        if (!empty($_FILES['doc_file']['name']) && $title) {
            $orig = basename($_FILES['doc_file']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $safe = 'staff_' . $sid . '_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $docDir . $safe)) {
                try {
                    $pdo->prepare("INSERT INTO staff_documents (organisation_id,staff_id,doc_category,title,file_name,file_original,file_size,file_type,issue_date,expiry_date,notes,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute(array($orgId,$sid,$cat,$title,$safe,$orig,$_FILES['doc_file']['size'],$_FILES['doc_file']['type'],$idate,$edate,$dnotes?:null,$uid));
                    addAuditLog($pdo,'UPLOAD_STAFF_DOC','staff_documents',$sid,"$cat: $title");
                    setFlash('success','Document uploaded.');
                } catch(Exception $e){ @unlink($docDir.$safe); setFlash('error','DB error: '.$e->getMessage()); }
            } else { setFlash('error','Upload failed. Check folder permissions.'); }
        } else { setFlash('error','Please provide a title and file.'); }
        header('Location: staff.php?staff_id='.$sid.'&tab=docs'); exit;
    }

    // ── Delete document ──
    if ($act === 'delete_doc') {
        $did = (int)$_POST['doc_id'];
        $sid = (int)$_POST['doc_staff_id'];
        try {
            $d = $pdo->prepare("SELECT file_name FROM staff_documents WHERE id=? AND organisation_id=?");
            $d->execute(array($did,$orgId)); $d = $d->fetch();
            if ($d) { @unlink($docDir . $d['file_name']); }
            $pdo->prepare("DELETE FROM staff_documents WHERE id=? AND organisation_id=?")->execute(array($did,$orgId));
            setFlash('success','Document deleted.');
        } catch(Exception $e){ setFlash('error','Could not delete.'); }
        header('Location: staff.php?staff_id='.$sid.'&tab=docs'); exit;
    }
}

// ── Load staff list ───────────────────────────────────────────────────
$staffList = array();
try {
    $q = $pdo->prepare("SELECT u.*,
        (SELECT COUNT(*) FROM staff_documents WHERE staff_id=u.id AND organisation_id=?) AS doc_count,
        (SELECT COUNT(*) FROM staff_documents WHERE staff_id=u.id AND organisation_id=? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()) AS expired_docs
        FROM users u WHERE u.organisation_id=? AND u.is_active=1 ORDER BY u.last_name,u.first_name");
    $q->execute(array($orgId,$orgId,$orgId));
    $staffList = $q->fetchAll();
} catch(Exception $e){}

// ── Load selected staff ───────────────────────────────────────────────
$selectedStaff = null;
$staffDocs = array();
if ($activeStaffId) {
    try {
        $q = $pdo->prepare("SELECT * FROM users WHERE id=? AND organisation_id=? AND is_active=1");
        $q->execute(array($activeStaffId,$orgId));
        $selectedStaff = $q->fetch();
    } catch(Exception $e){}
    try {
        $q = $pdo->prepare("SELECT * FROM staff_documents WHERE staff_id=? AND organisation_id=? ORDER BY doc_category,expiry_date");
        $q->execute(array($activeStaffId,$orgId));
        $staffDocs = $q->fetchAll();
    } catch(Exception $e){}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-5 flex-col lg:flex-row">

<!-- ── LEFT: Staff list ───────────────────────────────────────────── -->
<div class="w-full lg:w-72 flex-shrink-0">

  <!-- Add staff -->
  <div class="bg-white rounded-2xl shadow overflow-hidden mb-4">
    <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-4 py-3 flex items-center justify-between">
      <h3 class="text-white font-extrabold text-sm"><i class="fa fa-users mr-1.5"></i>Staff Management</h3>
      <button onclick="document.getElementById('addStaffModal').style.display='flex'"
              class="bg-white/20 hover:bg-white/30 text-white text-xs font-bold px-2.5 py-1 rounded-lg transition">
        <i class="fa fa-plus mr-1"></i>Add
      </button>
    </div>
    <div class="p-2 space-y-1 max-h-96 overflow-y-auto">
    <?php if (empty($staffList)): ?>
      <p class="text-xs text-gray-400 text-center py-4">No staff added yet.</p>
    <?php endif; ?>
    <?php foreach ($staffList as $s):
        $isActive = ($s['id'] == $activeStaffId);
        $hasExpired = (int)$s['expired_docs'] > 0;
    ?>
      <a href="?staff_id=<?= $s['id'] ?>&tab=<?= $activeTab ?>"
         class="flex items-center gap-2.5 px-2.5 py-2 rounded-xl transition
         <?= $isActive ? 'bg-teal-50 border border-teal-200' : 'hover:bg-gray-50' ?>">
        <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center text-xs font-extrabold
             <?= ($s['role']==='Admin') ? 'bg-amber-100 text-amber-800' : 'bg-teal-100 text-teal-800' ?>">
          <?= strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1)) ?>
        </div>
        <div class="min-w-0 flex-1">
          <div class="text-xs font-bold text-gray-800 truncate"><?= h($s['first_name'].' '.$s['last_name']) ?></div>
          <div class="text-[10px] text-gray-400 truncate"><?= h($s['job_category'] ?? $s['role']) ?></div>
        </div>
        <div class="flex-shrink-0 flex flex-col items-end gap-0.5">
          <?php if ($hasExpired): ?>
          <span class="w-2 h-2 bg-red-500 rounded-full" title="Expired documents"></span>
          <?php endif; ?>
          <span class="text-[10px] text-gray-300"><?= $s['doc_count'] ?>d</span>
        </div>
      </a>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- Compliance summary -->
  <?php
  $totalDocs = 0; $expiredDocs = 0; $expiringSoon = 0;
  foreach ($staffList as $s) { $totalDocs += (int)$s['doc_count']; $expiredDocs += (int)$s['expired_docs']; }
  try {
      $q = $pdo->prepare("SELECT COUNT(*) FROM staff_documents WHERE organisation_id=? AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)");
      $q->execute(array($orgId)); $expiringSoon = (int)$q->fetchColumn();
  } catch(Exception $e){}
  ?>
  <div class="bg-white rounded-2xl shadow p-4">
    <h4 class="text-xs font-extrabold text-gray-600 uppercase tracking-widest mb-3">Compliance Overview</h4>
    <div class="space-y-2">
      <div class="flex items-center justify-between"><span class="text-xs text-gray-500">Total Documents</span><span class="text-sm font-bold text-gray-800"><?= $totalDocs ?></span></div>
      <div class="flex items-center justify-between"><span class="text-xs text-gray-500">Expired</span><span class="text-sm font-bold <?= $expiredDocs>0?'text-red-600':'text-gray-400' ?>"><?= $expiredDocs ?></span></div>
      <div class="flex items-center justify-between"><span class="text-xs text-gray-500">Expiring ≤30 days</span><span class="text-sm font-bold <?= $expiringSoon>0?'text-amber-600':'text-gray-400' ?>"><?= $expiringSoon ?></span></div>
    </div>
  </div>

</div>

<!-- ── RIGHT: Staff detail ─────────────────────────────────────────── -->
<div class="flex-1 min-w-0">

<?php if (!$selectedStaff): ?>
<div class="bg-white rounded-2xl shadow p-10 text-center">
  <i class="fa fa-user-nurse text-5xl text-gray-200 block mb-4"></i>
  <p class="text-gray-500 font-semibold">Select a staff member from the list</p>
  <p class="text-xs text-gray-400 mt-1">or click <strong>+ Add</strong> to add a new member</p>
</div>

<?php else: ?>

<!-- Staff header -->
<div class="bg-gradient-to-r from-teal-700 to-teal-600 rounded-2xl shadow-xl mb-4 overflow-hidden">
  <div class="px-5 py-4 flex items-center gap-4 flex-wrap">
    <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center text-white font-extrabold text-xl flex-shrink-0">
      <?= strtoupper(substr($selectedStaff['first_name'],0,1).substr($selectedStaff['last_name'],0,1)) ?>
    </div>
    <div class="flex-1">
      <div class="text-xl font-extrabold text-white"><?= h($selectedStaff['first_name'].' '.$selectedStaff['last_name']) ?></div>
      <div class="text-teal-200 text-sm"><?= h($selectedStaff['job_category'] ?? $selectedStaff['role']) ?></div>
      <div class="text-teal-300 text-xs mt-0.5"><?= h($selectedStaff['email'] ?? '') ?></div>
    </div>
    <div class="flex gap-2 flex-shrink-0">
      <span class="bg-white/15 text-white text-xs font-bold px-3 py-1.5 rounded-xl">
        <i class="fa fa-file mr-1"></i><?= count($staffDocs) ?> docs
      </span>
      <?php if ($selectedStaff['dbs_on_update']): ?>
      <span class="bg-green-500/30 border border-green-400/40 text-green-200 text-xs font-bold px-3 py-1.5 rounded-xl">
        <i class="fa fa-shield-halved mr-1"></i>DBS Update
      </span>
      <?php endif; ?>
    </div>
  </div>
  <!-- Tabs -->
  <div class="flex border-t border-white/20">
    <?php foreach (array('profile'=>array('fa-user','Profile'),'docs'=>array('fa-folder-open','Documents'),'compliance'=>array('fa-shield-halved','Compliance')) as $t=>$d):
        $isAct = ($activeTab===$t); ?>
    <a href="?staff_id=<?= $activeStaffId ?>&tab=<?= $t ?>"
       class="flex items-center gap-1.5 px-5 py-3 text-xs font-bold transition
       <?= $isAct ? 'bg-white text-teal-700' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
      <i class="fa <?= $d[0] ?>"></i><?= $d[1] ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── TAB: PROFILE ─────────────────────────────────────────────────── -->
<?php if ($activeTab === 'profile'): ?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center justify-between">
    <h4 class="font-extrabold text-gray-700 text-sm"><i class="fa fa-pencil mr-1.5 text-teal-500"></i>Edit Profile</h4>
  </div>
  <form method="POST" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="edit_staff">
    <input type="hidden" name="staff_id" value="<?= $selectedStaff['id'] ?>">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">First Name *</label>
        <input type="text" name="first_name" required value="<?= h($selectedStaff['first_name']) ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Last Name *</label>
        <input type="text" name="last_name" required value="<?= h($selectedStaff['last_name']) ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Email *</label>
        <input type="email" name="email" required value="<?= h($selectedStaff['email'] ?? '') ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Role</label>
        <select name="role" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
          <option <?= ($selectedStaff['role']==='Staff')?'selected':'' ?>>Staff</option>
          <option <?= ($selectedStaff['role']==='Admin')?'selected':'' ?>>Admin</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Job Category</label>
        <select name="job_category" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
          <?php foreach ($jobCategories as $jc): ?>
          <option <?= (($selectedStaff['job_category']??'')===$jc)?'selected':'' ?>><?= $jc ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Phone</label>
        <input type="text" name="phone" value="<?= h($selectedStaff['phone'] ?? '') ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Date of Birth</label>
        <input type="date" name="date_of_birth" value="<?= h($selectedStaff['date_of_birth'] ?? '') ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">NI Number</label>
        <input type="text" name="ni_number" placeholder="AB 12 34 56 C" value="<?= h($selectedStaff['ni_number'] ?? '') ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none font-mono">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Emergency Contact</label>
        <input type="text" name="emergency_contact" value="<?= h($selectedStaff['emergency_contact'] ?? '') ?>"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
    </div>

    <div class="mb-4">
      <label class="block text-xs font-bold text-gray-600 mb-1">Address</label>
      <textarea name="address" rows="2" class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"><?= h($selectedStaff['address'] ?? '') ?></textarea>
    </div>

    <!-- DBS on update service -->
    <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4">
      <div class="flex items-center gap-3 flex-wrap">
        <div class="flex-1">
          <div class="font-extrabold text-blue-800 text-sm">
            <i class="fa fa-shield-halved text-blue-500 mr-1.5"></i>DBS on the Update Service?
          </div>
          <div class="text-xs text-blue-600 mt-0.5">The DBS Update Service allows employers to check a DBS certificate is still current at any time.</div>
        </div>
        <select name="dbs_on_update" class="border-2 border-blue-300 bg-white rounded-xl px-4 py-2.5 text-sm font-extrabold focus:border-blue-500 focus:outline-none text-blue-800 min-w-[100px]">
          <option value="0" <?= (!$selectedStaff['dbs_on_update']) ? 'selected' : '' ?>>No</option>
          <option value="1" <?= ($selectedStaff['dbs_on_update'])  ? 'selected' : '' ?>>Yes</option>
        </select>
      </div>
      <div class="mt-3 pt-3 border-t border-blue-200">
        <label class="block text-xs font-bold text-blue-700 mb-1.5">
          <i class="fa fa-calendar-check mr-1"></i>Date Check on Update Service
        </label>
        <input type="date" name="dbs_check_date"
               value="<?= h($selectedStaff['dbs_check_date'] ?? '') ?>"
               class="w-full sm:w-auto border-2 border-blue-200 bg-white rounded-xl px-3 py-2 text-sm font-semibold text-blue-800 focus:border-blue-500 focus:outline-none">
        <p class="text-xs text-blue-500 mt-1">Date the employer last checked the DBS certificate on the Update Service</p>
      </div>
    </div>
    </div>

    <div class="mb-4">
      <label class="block text-xs font-bold text-gray-600 mb-1">Internal Notes</label>
      <textarea name="notes" rows="2" placeholder="Any internal HR notes..."
                class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none resize-none"><?= h($selectedStaff['notes'] ?? '') ?></textarea>
    </div>

    <div class="mb-4 border-t pt-4">
      <label class="block text-xs font-bold text-gray-600 mb-1">New Password <span class="text-gray-400 font-normal">(leave blank to keep current)</span></label>
      <input type="password" name="new_password" placeholder="Enter new password to change..."
             class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none max-w-sm">
    </div>

    <div class="flex gap-3">
      <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-extrabold px-6 py-2.5 rounded-xl text-sm transition">
        <i class="fa fa-save mr-1"></i>Save Changes
      </button>
      <form method="POST" onsubmit="return confirm('Deactivate this staff member?')" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="deactivate_staff">
        <input type="hidden" name="staff_id" value="<?= $selectedStaff['id'] ?>">
        <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 font-bold px-4 py-2.5 rounded-xl text-sm transition">
          <i class="fa fa-user-slash mr-1"></i>Deactivate
        </button>
      </form>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── TAB: DOCUMENTS ───────────────────────────────────────────────── -->
<?php if ($activeTab === 'docs'): ?>

<!-- Upload form -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-4">
  <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-5 py-3">
    <h4 class="text-white font-extrabold text-sm"><i class="fa fa-upload mr-1.5"></i>Upload Compliance Document</h4>
    <p class="text-purple-200 text-xs mt-0.5">All formats accepted — PDF, Word, Excel, images, scans</p>
  </div>
  <form method="POST" enctype="multipart/form-data" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upload_doc">
    <input type="hidden" name="doc_staff_id" value="<?= $activeStaffId ?>">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
      <div class="sm:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Document Title *</label>
        <input type="text" name="doc_title" required placeholder="e.g. Enhanced DBS Certificate 2024"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Category</label>
        <select name="doc_category" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-purple-400 focus:outline-none">
          <?php foreach ($docCategories as $cat): ?>
          <option><?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Issue Date</label>
        <input type="date" name="doc_issue_date"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Expiry Date</label>
        <input type="date" name="doc_expiry_date"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Notes</label>
        <input type="text" name="doc_notes" placeholder="e.g. Enhanced level, workforce update ref"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-purple-400 focus:outline-none">
      </div>
    </div>
    <div class="mb-4">
      <label class="block text-xs font-bold text-gray-600 mb-1">File * (any format)</label>
      <input type="file" name="doc_file" required accept="*/*"
             class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:outline-none">
    </div>
    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition">
      <i class="fa fa-upload mr-1"></i>Upload Document
    </button>
  </form>
</div>

<!-- Documents list -->
<?php if (empty($staffDocs)): ?>
<div class="bg-white rounded-2xl shadow p-10 text-center">
  <i class="fa fa-folder-open text-4xl text-gray-200 block mb-3"></i>
  <p class="text-gray-500">No documents uploaded yet for this staff member.</p>
</div>
<?php else: ?>
<div class="space-y-3">
<?php
$byCategory = array();
foreach ($staffDocs as $d) {
    $byCategory[$d['doc_category']][] = $d;
}
foreach ($byCategory as $cat => $docs): ?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gray-50 border-b px-4 py-2.5">
    <h5 class="font-extrabold text-xs text-gray-600 uppercase tracking-widest"><?= h($cat) ?> (<?= count($docs) ?>)</h5>
  </div>
  <div class="divide-y divide-gray-50">
  <?php foreach ($docs as $d):
    $isExpired = ($d['expiry_date'] && strtotime($d['expiry_date']) < time());
    $expiringSoon = ($d['expiry_date'] && !$isExpired && strtotime($d['expiry_date']) < strtotime('+30 days'));
    $ext = strtolower(pathinfo($d['file_original'], PATHINFO_EXTENSION));
    $iconMap = array('pdf'=>'fa-file-pdf text-red-500','doc'=>'fa-file-word text-blue-500','docx'=>'fa-file-word text-blue-500','xls'=>'fa-file-excel text-green-500','xlsx'=>'fa-file-excel text-green-500','png'=>'fa-file-image text-pink-500','jpg'=>'fa-file-image text-pink-500','jpeg'=>'fa-file-image text-pink-500');
    $icon = isset($iconMap[$ext]) ? $iconMap[$ext] : 'fa-file text-gray-400';
  ?>
  <div class="flex items-start gap-3 px-4 py-3 <?= $isExpired ? 'bg-red-50' : ($expiringSoon ? 'bg-amber-50' : '') ?>">
    <i class="fa <?= $icon ?> text-xl flex-shrink-0 mt-0.5"></i>
    <div class="flex-1 min-w-0">
      <div class="font-bold text-gray-800 text-sm"><?= h($d['title']) ?></div>
      <div class="text-xs text-gray-400 mt-0.5 flex flex-wrap gap-2">
        <?php if ($d['issue_date']): ?>
        <span><i class="fa fa-calendar-check mr-0.5 text-green-400"></i>Issued: <?= date('d/m/Y',strtotime($d['issue_date'])) ?></span>
        <?php endif; ?>
        <?php if ($d['expiry_date']): ?>
        <span class="<?= $isExpired?'font-bold text-red-600':($expiringSoon?'font-bold text-amber-600':'') ?>">
          <i class="fa fa-calendar-xmark mr-0.5 <?= $isExpired?'text-red-400':($expiringSoon?'text-amber-400':'text-gray-300') ?>"></i>
          Expires: <?= date('d/m/Y',strtotime($d['expiry_date'])) ?>
          <?php if ($isExpired): ?><span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full ml-1">EXPIRED</span><?php endif; ?>
          <?php if ($expiringSoon): ?><span class="bg-amber-400 text-white text-[10px] px-1.5 py-0.5 rounded-full ml-1">EXPIRING</span><?php endif; ?>
        </span>
        <?php endif; ?>
        <?php if ($d['notes']): ?><span class="text-gray-400"><?= h($d['notes']) ?></span><?php endif; ?>
      </div>
      <div class="text-xs text-gray-400"><?= h($d['file_original']) ?> &bull; <?= round($d['file_size']/1024) ?>KB</div>
    </div>
    <div class="flex gap-1.5 flex-shrink-0">
      <a href="../uploads/staff_docs/<?= rawurlencode($d['file_name']) ?>" target="_blank"
         class="text-xs bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-100 px-2.5 py-1.5 rounded-lg transition">
        <i class="fa fa-download"></i>
      </a>
      <form method="POST" onsubmit="return confirm('Delete this document?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_doc">
        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
        <input type="hidden" name="doc_staff_id" value="<?= $activeStaffId ?>">
        <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100 px-2.5 py-1.5 rounded-lg">
          <i class="fa fa-trash"></i>
        </button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── TAB: COMPLIANCE ──────────────────────────────────────────────── -->
<?php if ($activeTab === 'compliance'): ?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-5 py-3">
    <h4 class="text-white font-extrabold text-sm"><i class="fa fa-clipboard-check mr-1.5"></i>Compliance Summary</h4>
  </div>
  <div class="p-5">
    <!-- DBS Status -->
    <div class="bg-<?= $selectedStaff['dbs_on_update'] ? 'green' : 'gray' ?>-50 border border-<?= $selectedStaff['dbs_on_update'] ? 'green' : 'gray' ?>-200 rounded-2xl p-4 mb-4 flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl <?= $selectedStaff['dbs_on_update'] ? 'bg-green-100' : 'bg-gray-100' ?> flex items-center justify-center flex-shrink-0">
        <i class="fa fa-shield-halved <?= $selectedStaff['dbs_on_update'] ? 'text-green-600' : 'text-gray-400' ?> text-lg"></i>
      </div>
      <div>
        <div class="font-extrabold text-sm <?= $selectedStaff['dbs_on_update'] ? 'text-green-800' : 'text-gray-700' ?>">DBS Update Service</div>
        <div class="text-sm <?= $selectedStaff['dbs_on_update'] ? 'text-green-700 font-bold' : 'text-gray-500' ?>">
          <?= $selectedStaff['dbs_on_update'] ? 'YES — Enrolled on DBS Update Service' : 'No — Not on DBS Update Service' ?>
        </div>
        <?php if (!empty($selectedStaff['dbs_check_date'])): ?>
        <div class="text-xs text-blue-700 mt-1 flex items-center gap-1">
          <i class="fa fa-calendar-check text-blue-400"></i>
          Last checked on Update Service: <strong><?= date('d M Y', strtotime($selectedStaff['dbs_check_date'])) ?></strong>
          <?php
            $daysSince = (int)floor((time() - strtotime($selectedStaff['dbs_check_date'])) / 86400);
            if ($daysSince > 365): ?>
            <span class="ml-1 bg-red-100 text-red-600 font-bold px-1.5 py-0.5 rounded-full text-xs">Overdue (<?= $daysSince ?>d ago)</span>
          <?php elseif ($daysSince > 270): ?>
            <span class="ml-1 bg-amber-100 text-amber-700 font-bold px-1.5 py-0.5 rounded-full text-xs">Due soon</span>
          <?php else: ?>
            <span class="ml-1 bg-green-100 text-green-700 font-bold px-1.5 py-0.5 rounded-full text-xs"><?= $daysSince ?>d ago</span>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="text-xs text-gray-400 mt-1"><i class="fa fa-calendar mr-1"></i>No check date recorded</div>
        <?php endif; ?>
      </div>
    </div>
    <!-- Document status grid -->
    <?php
    $complianceItems = array(
        'DBS Certificate' => 'fa-id-card text-blue-500',
        'Enhanced DBS' => 'fa-shield-halved text-purple-500',
        'Right to Work' => 'fa-passport text-teal-500',
        'Manual Handling' => 'fa-person-walking text-orange-500',
        'Safeguarding' => 'fa-children text-pink-500',
        'First Aid' => 'fa-kit-medical text-red-500',
        'Medication Competency' => 'fa-pills text-green-500',
        'Fire Safety' => 'fa-fire-extinguisher text-amber-500',
    );
    $docsByCategory = array();
    foreach ($staffDocs as $d) { $docsByCategory[$d['doc_category']][] = $d; }
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <?php foreach ($complianceItems as $item => $icon):
        $has = isset($docsByCategory[$item]) && !empty($docsByCategory[$item]);
        $doc = $has ? $docsByCategory[$item][0] : null;
        $expired = $doc && $doc['expiry_date'] && strtotime($doc['expiry_date']) < time();
        $soon    = $doc && $doc['expiry_date'] && !$expired && strtotime($doc['expiry_date']) < strtotime('+30 days');
        $bgColor = !$has ? 'bg-gray-50 border-gray-200' : ($expired ? 'bg-red-50 border-red-200' : ($soon ? 'bg-amber-50 border-amber-200' : 'bg-green-50 border-green-200'));
        $statusIcon = !$has ? 'fa-circle-xmark text-gray-300' : ($expired ? 'fa-circle-xmark text-red-500' : ($soon ? 'fa-clock text-amber-500' : 'fa-circle-check text-green-500'));
    ?>
    <div class="<?= $bgColor ?> border rounded-xl p-3 flex items-center gap-3">
      <i class="fa <?= $icon ?> text-lg flex-shrink-0"></i>
      <div class="flex-1 min-w-0">
        <div class="text-xs font-bold text-gray-700"><?= $item ?></div>
        <?php if ($doc): ?>
        <div class="text-xs text-gray-500 mt-0.5">
          <?= $expired ? '<span class="text-red-600 font-bold">EXPIRED ' . date('d/m/Y',strtotime($doc['expiry_date'])) . '</span>' : ($soon ? '<span class="text-amber-600 font-bold">Exp: ' . date('d/m/Y',strtotime($doc['expiry_date'])) . '</span>' : ($doc['expiry_date'] ? 'Exp: '.date('d/m/Y',strtotime($doc['expiry_date'])) : 'No expiry set')) ?>
        </div>
        <?php else: ?>
        <div class="text-xs text-gray-400">Not uploaded</div>
        <?php endif; ?>
      </div>
      <i class="fa <?= $statusIcon ?> text-sm flex-shrink-0"></i>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-4 text-center">
      <a href="?staff_id=<?= $activeStaffId ?>&tab=docs"
         class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm transition">
        <i class="fa fa-upload"></i>Upload Documents
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; // end selectedStaff ?>
</div>
</div>

<!-- ── ADD STAFF MODAL ──────────────────────────────────────────────── -->
<div id="addStaffModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(0,0,0,.6);backdrop-filter:blur(4px)">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-6 py-4 flex items-center justify-between">
      <h3 class="text-white font-extrabold text-base"><i class="fa fa-user-plus mr-2"></i>Add New Staff Member</h3>
      <button onclick="document.getElementById('addStaffModal').style.display='none'"
              class="text-white/60 hover:text-white text-2xl font-bold leading-none">&times;</button>
    </div>
    <form method="POST" class="p-6">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_staff">
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">First Name *</label>
          <input type="text" name="first_name" required class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Last Name *</label>
          <input type="text" name="last_name" required class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
        </div>
      </div>
      <div class="mb-3">
        <label class="block text-xs font-bold text-gray-600 mb-1">Email *</label>
        <input type="email" name="email" required class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none">
      </div>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Role</label>
          <select name="role" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
            <option>Staff</option><option>Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Job Category</label>
          <select name="job_category" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
            <?php foreach ($jobCategories as $jc): ?><option><?= $jc ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-4">
        <label class="block text-xs font-bold text-gray-600 mb-1">Temporary Password</label>
        <input type="text" name="password" placeholder="Default: Welcome1!" value="Welcome1!"
               class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none font-mono">
        <p class="text-xs text-gray-400 mt-0.5">Staff should change this on first login.</p>
      </div>
      <div class="flex gap-3">
        <button type="submit" class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-extrabold py-2.5 rounded-xl text-sm transition">
          <i class="fa fa-user-plus mr-1"></i>Add Staff Member
        </button>
        <button type="button" onclick="document.getElementById('addStaffModal').style.display='none'"
                class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold px-5 rounded-xl text-sm transition">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
