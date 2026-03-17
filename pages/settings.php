<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$pdo       = getPDO();
$orgId     = (int)$_SESSION['organisation_id'];
$pageTitle = 'Settings';

// Self-heal new columns
$newCols = array(
    "ALTER TABLE `organisations` ADD COLUMN `website`        VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `contact_name`   VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `company_number` VARCHAR(50)  DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `vat_number`     VARCHAR(50)  DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `bank_name`      VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `bank_sort_code` VARCHAR(20)  DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `bank_account`   VARCHAR(30)  DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `active_modules` VARCHAR(500) DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `phone`          VARCHAR(30)  DEFAULT NULL",
    "ALTER TABLE `organisations` ADD COLUMN `address`        TEXT         DEFAULT NULL",
);
foreach ($newCols as $sql) {
    try { $pdo->exec($sql); } catch(Exception $e) {}
}

// All available modules
$allModules = array(
    'visits'      => 'Visits & Scheduling',
    'medications' => 'Medications & MAR',
    'rota'        => 'Rota Management',
    'handover'    => 'Handovers',
    'incidents'   => 'Incident Reporting',
    'holiday'     => 'Holiday Requests',
    'policies'    => 'Policies & Documents',
    'messages'    => 'Internal Messaging',
    'invoices'    => 'Invoices',
    'reports'     => 'Reports & Exports',
);

try {
    $org = $pdo->prepare("SELECT * FROM organisations WHERE id=?");
    $org->execute(array($orgId));
    $org = $org->fetch();
} catch(Exception $e){ $org = array(); }

$activeModulesList = array();
if (!empty($org['active_modules'])) {
    $activeModulesList = explode(',', $org['active_modules']);
}

// ── Logo upload ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    validateCSRF();
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $f    = $_FILES['logo'];
        $mime = @mime_content_type($f['tmp_name']);
        $allowed = array('image/jpeg','image/png','image/gif','image/svg+xml','image/webp');
        if (!in_array($mime, $allowed)) { setFlash('error','Invalid image type.'); }
        elseif ($f['size'] > 2*1024*1024) { setFlash('error','Max 2 MB.'); }
        else {
            $exts = array('image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/svg+xml'=>'svg','image/webp'=>'webp');
            $ext  = $exts[$mime];
            $dir  = __DIR__ . '/../uploads/logos/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); file_put_contents($dir.'.htaccess', "Options -Indexes\n"); }
            $fn = 'org_' . $orgId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . $fn)) {
                $pdo->prepare("UPDATE organisations SET logo_path=? WHERE id=?")->execute(array('uploads/logos/'.$fn, $orgId));
                setFlash('success','Company logo updated.');
            } else { setFlash('error','Could not save logo. Check folder permissions.'); }
        }
    } else { setFlash('error','Please select an image file.'); }
    header('Location: settings.php'); exit;
}

// ── Remove logo ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_logo'])) {
    validateCSRF();
    $logo = $org['logo_path'] ?? null;
    if ($logo) {
        @unlink(__DIR__ . '/../' . $logo);
        $pdo->prepare("UPDATE organisations SET logo_path=NULL WHERE id=?")->execute(array($orgId));
        setFlash('success','Logo removed.');
    }
    header('Location: settings.php'); exit;
}

// ── Save all settings ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    validateCSRF();
    $name = trim($_POST['org_name'] ?? '');
    if (!$name) { setFlash('error','Organisation name is required.'); header('Location: settings.php'); exit; }

    // Active modules
    $mods = array();
    if (!empty($_POST['modules']) && is_array($_POST['modules'])) {
        foreach ($_POST['modules'] as $m) {
            if (isset($allModules[$m])) $mods[] = $m;
        }
    }

    $fields = array(
        'name'           => $name,
        'email'          => trim($_POST['org_email']      ?? ''),
        'phone'          => trim($_POST['org_phone']      ?? ''),
        'address'        => trim($_POST['org_address']    ?? ''),
        'website'        => trim($_POST['org_website']    ?? ''),
        'contact_name'   => trim($_POST['contact_name']   ?? ''),
        'company_number' => trim($_POST['company_number'] ?? ''),
        'vat_number'     => trim($_POST['vat_number']     ?? ''),
        'bank_name'      => trim($_POST['bank_name']      ?? ''),
        'bank_sort_code' => trim($_POST['bank_sort_code'] ?? ''),
        'bank_account'   => trim($_POST['bank_account']   ?? ''),
        'active_modules' => implode(',', $mods),
    );

    $sets = implode(',', array_map(function($k){ return "`$k`=?"; }, array_keys($fields)));
    $vals = array_values($fields);
    $vals[] = $orgId;

    try {
        $pdo->prepare("UPDATE organisations SET $sets WHERE id=?")->execute($vals);
        $_SESSION['org_name'] = $name;
        addAuditLog($pdo, 'UPDATE_SETTINGS', 'organisations', $orgId, 'Settings updated');
        setFlash('success','Settings saved successfully.');
    } catch(Exception $e){ setFlash('error','Error: '.$e->getMessage()); }
    header('Location: settings.php'); exit;
}

include __DIR__ . '/../includes/header.php';
$logoPath = $org['logo_path'] ?? null;
$logoFull = $logoPath ? __DIR__ . '/../' . $logoPath : null;
?>

<div class="max-w-3xl mx-auto space-y-5">

<!-- ── Company Logo ──────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-5 py-4 flex items-center gap-2">
    <i class="fa fa-image text-white text-lg"></i>
    <div>
      <h3 class="text-white font-extrabold">Company Logo</h3>
      <p class="text-teal-200 text-xs mt-0.5">Appears on all reports, exports and the sidebar</p>
    </div>
  </div>
  <div class="p-5">
    <?php if ($logoPath && $logoFull && file_exists($logoFull)): ?>
    <div class="flex items-center gap-4 mb-5 p-4 bg-teal-50 border border-teal-200 rounded-2xl">
      <img src="/<?= h($logoPath) ?>" alt="Company logo"
           class="h-16 w-auto object-contain rounded-xl border border-gray-200 bg-white p-2 shadow-sm">
      <div class="flex-1">
        <p class="text-sm font-extrabold text-teal-800">Logo uploaded ✓</p>
        <p class="text-xs text-gray-500 mt-0.5">Shown in sidebar and on all exported documents</p>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="remove_logo" value="1">
        <button type="submit" onclick="return confirm('Remove logo?')"
                class="text-xs text-red-600 hover:text-red-800 border border-red-200 px-3 py-1.5 rounded-xl font-semibold bg-red-50 hover:bg-red-100 transition">
          <i class="fa fa-trash mr-1"></i>Remove
        </button>
      </form>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="upload_logo" value="1">
      <div class="border-2 border-dashed border-teal-300 rounded-2xl p-6 text-center cursor-pointer hover:bg-teal-50 transition mb-3"
           onclick="document.getElementById('logoFile').click()">
        <i class="fa fa-cloud-arrow-up text-3xl text-teal-400 mb-2 block"></i>
        <p class="text-sm font-semibold text-teal-700">Click to select company logo</p>
        <p class="text-xs text-gray-400 mt-1">PNG, JPG, SVG, WebP · Max 2 MB</p>
        <span id="lprev" class="hidden mt-2 text-xs font-bold text-teal-700 block"></span>
      </div>
      <input type="file" id="logoFile" name="logo" accept="image/*" class="hidden"
             onchange="var n=this.files[0]?this.files[0].name:'';document.getElementById('lprev').textContent=n;document.getElementById('lprev').classList.toggle('hidden',!n)">
      <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 rounded-xl text-sm transition">
        <i class="fa fa-upload mr-1"></i>Upload Logo
      </button>
    </form>
  </div>
</div>

<!-- ── Main Settings Form ─────────────────────────────────────────────── -->
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="save_settings" value="1">

<!-- Organisation Details -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center gap-2">
    <i class="fa fa-building text-teal-500 text-lg"></i>
    <h3 class="font-extrabold text-gray-700">Organisation Details</h3>
  </div>
  <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
      <label class="block text-xs font-bold text-gray-600 mb-1">Organisation Name <span class="text-red-400">*</span></label>
      <input type="text" name="org_name" required value="<?= h($org['name'] ?? '') ?>"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">Contact Name</label>
      <input type="text" name="contact_name" value="<?= h($org['contact_name'] ?? '') ?>"
             placeholder="Main contact person"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">Email</label>
      <input type="email" name="org_email" value="<?= h($org['email'] ?? '') ?>"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">Phone</label>
      <input type="text" name="org_phone" value="<?= h($org['phone'] ?? '') ?>"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">
        <i class="fa fa-globe text-teal-400 mr-1"></i>Company Website
      </label>
      <input type="text" name="org_website" value="<?= h($org['website'] ?? '') ?>"
             placeholder="https://www.yourcompany.co.uk"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-xs font-bold text-gray-600 mb-1">Address</label>
      <textarea name="org_address" rows="2"
                class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none resize-none"><?= h($org['address'] ?? '') ?></textarea>
    </div>
  </div>
</div>

<!-- Company Registration -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center gap-2">
    <i class="fa fa-registered text-blue-500 text-lg"></i>
    <h3 class="font-extrabold text-gray-700">Company Registration</h3>
  </div>
  <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">
        <i class="fa fa-building-columns text-blue-400 mr-1"></i>Company Number
      </label>
      <input type="text" name="company_number" value="<?= h($org['company_number'] ?? '') ?>"
             placeholder="e.g. 12345678"
             class="w-full border rounded-xl px-3 py-2.5 text-sm font-mono focus:border-blue-400 focus:outline-none">
      <p class="text-xs text-gray-400 mt-0.5">Companies House registration number</p>
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">
        <i class="fa fa-receipt text-blue-400 mr-1"></i>VAT Number
      </label>
      <input type="text" name="vat_number" value="<?= h($org['vat_number'] ?? '') ?>"
             placeholder="e.g. GB 123 4567 89"
             class="w-full border rounded-xl px-3 py-2.5 text-sm font-mono focus:border-blue-400 focus:outline-none">
    </div>
  </div>
</div>

<!-- Bank Details -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center gap-2">
    <i class="fa fa-landmark text-purple-500 text-lg"></i>
    <div>
      <h3 class="font-extrabold text-gray-700">Bank Details</h3>
      <p class="text-xs text-gray-400">Used on invoices and payment documentation</p>
    </div>
  </div>
  <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="sm:col-span-3">
      <label class="block text-xs font-bold text-gray-600 mb-1">
        <i class="fa fa-building-columns text-purple-400 mr-1"></i>Bank Name
      </label>
      <input type="text" name="bank_name" value="<?= h($org['bank_name'] ?? '') ?>"
             placeholder="e.g. Barclays, Lloyds, NatWest"
             class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-purple-400 focus:outline-none">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1">Sort Code</label>
      <input type="text" name="bank_sort_code" value="<?= h($org['bank_sort_code'] ?? '') ?>"
             placeholder="20-70-15"
             class="w-full border rounded-xl px-3 py-2.5 text-sm font-mono focus:border-purple-400 focus:outline-none">
    </div>
    <div class="sm:col-span-2">
      <label class="block text-xs font-bold text-gray-600 mb-1">Account Number</label>
      <input type="text" name="bank_account" value="<?= h($org['bank_account'] ?? '') ?>"
             placeholder="30288934"
             class="w-full border rounded-xl px-3 py-2.5 text-sm font-mono focus:border-purple-400 focus:outline-none">
    </div>
  </div>
  <div class="px-5 pb-4">
    <div class="bg-purple-50 border border-purple-200 rounded-xl p-3 text-xs text-purple-700">
      <i class="fa fa-lock text-purple-400 mr-1"></i>
      Bank details are stored securely and only visible to administrators. They appear on invoice exports.
    </div>
  </div>
</div>

<!-- Workforce Snapshot -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center gap-2">
    <i class="fa fa-chart-pie text-amber-500 text-lg"></i>
    <div>
      <h3 class="font-extrabold text-gray-700">Workforce Snapshot</h3>
      <p class="text-xs text-gray-400">Live figures pulled from your database</p>
    </div>
  </div>
  <div class="p-5">
    <?php
    $numCarers = 0; $numSUs = 0;
    try { $q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE organisation_id=? AND is_active=1"); $q->execute(array($orgId)); $numCarers=(int)$q->fetchColumn(); } catch(Exception $e){}
    try { $q=$pdo->prepare("SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1"); $q->execute(array($orgId)); $numSUs=(int)$q->fetchColumn(); } catch(Exception $e){}
    ?>
    <div class="grid grid-cols-2 gap-4">
      <div class="bg-teal-50 border border-teal-200 rounded-2xl p-4 text-center">
        <i class="fa fa-users text-teal-500 text-2xl mb-2 block"></i>
        <div class="text-3xl font-extrabold text-teal-700"><?= $numCarers ?></div>
        <div class="text-xs font-bold text-teal-600 uppercase tracking-wider mt-1">Active Carers / Staff</div>
        <a href="/pages/staff.php" class="text-xs text-teal-500 hover:underline mt-1 block">Manage Staff →</a>
      </div>
      <div class="bg-purple-50 border border-purple-200 rounded-2xl p-4 text-center">
        <i class="fa fa-user-nurse text-purple-500 text-2xl mb-2 block"></i>
        <div class="text-3xl font-extrabold text-purple-700"><?= $numSUs ?></div>
        <div class="text-xs font-bold text-purple-600 uppercase tracking-wider mt-1">Active Service Users</div>
        <a href="/pages/service_users.php" class="text-xs text-purple-500 hover:underline mt-1 block">Manage SUs →</a>
      </div>
    </div>
    <?php
    // Subscription info
    $subPlan = $org['subscription_plan'] ?? 'free';
    $subLimit = (int)($org['subscription_su_limit'] ?? 2);
    $subExp   = $org['subscription_expires_at'] ?? null;
    $tierColors = array('free'=>'gray','basic'=>'teal','standard'=>'blue','unlimited'=>'purple');
    $tc = $tierColors[$subPlan] ?? 'gray';
    ?>
    <div class="mt-4 bg-<?= $tc ?>-50 border border-<?= $tc ?>-200 rounded-2xl p-4 flex items-center gap-3">
      <i class="fa fa-star text-<?= $tc ?>-500 text-xl"></i>
      <div class="flex-1">
        <div class="text-sm font-extrabold text-<?= $tc ?>-800">
          <?= ucfirst($subPlan) ?> Plan
          <?php if ($subPlan !== 'free'): ?>
          <span class="text-xs font-normal text-<?= $tc ?>-600 ml-1">
            · <?= $numSUs ?>/<?= $subLimit >= 999 ? '∞' : $subLimit ?> SUs used
          </span>
          <?php endif; ?>
        </div>
        <?php if ($subExp && $subPlan !== 'free'): ?>
        <div class="text-xs text-<?= $tc ?>-600 mt-0.5">Expires: <?= date('d M Y', strtotime($subExp)) ?></div>
        <?php endif; ?>
      </div>
      <a href="/pages/subscription.php" class="text-xs bg-white border border-<?= $tc ?>-300 text-<?= $tc ?>-700 px-3 py-1.5 rounded-xl font-bold hover:bg-<?= $tc ?>-100 transition">
        <?= $subPlan === 'free' ? 'Upgrade' : 'Manage' ?>
      </a>
    </div>
  </div>
</div>

<!-- Active Modules -->
<div class="bg-white rounded-2xl shadow overflow-hidden mb-5">
  <div class="bg-gray-50 border-b px-5 py-3 flex items-center gap-2">
    <i class="fa fa-toggle-on text-green-500 text-lg"></i>
    <div>
      <h3 class="font-extrabold text-gray-700">Active Modules</h3>
      <p class="text-xs text-gray-400">Toggle which features are enabled for your organisation</p>
    </div>
  </div>
  <div class="p-5">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <?php
    $moduleIcons = array(
        'visits'      => array('fa-route',                'teal'),
        'medications' => array('fa-pills',               'blue'),
        'rota'        => array('fa-calendar-week',       'purple'),
        'handover'    => array('fa-handshake',           'indigo'),
        'incidents'   => array('fa-triangle-exclamation','red'),
        'holiday'     => array('fa-umbrella-beach',      'amber'),
        'policies'    => array('fa-file-shield',         'teal'),
        'messages'    => array('fa-comments',            'blue'),
        'invoices'    => array('fa-file-invoice',        'green'),
        'reports'     => array('fa-chart-bar',           'gray'),
    );
    foreach ($allModules as $key => $label):
        $checked  = empty($activeModulesList) || in_array($key, $activeModulesList);
        $ico      = isset($moduleIcons[$key]) ? $moduleIcons[$key][0] : 'fa-circle';
        $col      = isset($moduleIcons[$key]) ? $moduleIcons[$key][1] : 'teal';
    ?>
    <label class="flex items-center gap-3 p-3 border rounded-2xl cursor-pointer transition
                  <?= $checked ? 'bg-green-50 border-green-300' : 'bg-gray-50 border-gray-200 hover:border-gray-300' ?>">
      <div class="relative flex-shrink-0">
        <input type="checkbox" name="modules[]" value="<?= $key ?>"
               <?= $checked ? 'checked' : '' ?>
               class="sr-only peer module-toggle" id="mod_<?= $key ?>">
        <div class="w-11 h-6 bg-gray-200 peer-checked:bg-green-500 rounded-full transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:w-5 after:h-5 after:transition-all peer-checked:after:translate-x-5"></div>
      </div>
      <i class="fa <?= $ico ?> text-<?= $col ?>-500 text-sm w-4 text-center flex-shrink-0"></i>
      <span class="text-sm font-semibold text-gray-700"><?= $label ?></span>
    </label>
    <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-400 mt-3">
      <i class="fa fa-circle-info mr-1"></i>
      All modules are saved with the rest of your settings below. Disabling a module hides it from the navigation.
    </p>
  </div>
</div>

<!-- Save button -->
<div class="flex justify-end">
  <button type="submit"
          class="bg-teal-600 hover:bg-teal-700 text-white font-extrabold px-8 py-3 rounded-2xl text-sm shadow-lg shadow-teal-200 transition flex items-center gap-2">
    <i class="fa fa-floppy-disk text-lg"></i>Save All Settings
  </button>
</div>

</form>

<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-700">
  <i class="fa fa-circle-info text-blue-500 mr-1"></i>
  Your company logo, bank details and registration numbers appear on all generated invoices and reports.<br>
  The footer of every exported document includes:
  <strong>"Document enabled by Register My Care · info@registermycare.org · www.registermycare.org · Created and designed by Dr. Andrew Ebhoma"</strong>
</div>

</div>

<script>
// Toggle label style on change
document.querySelectorAll('.module-toggle').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var lbl = this.closest('label');
        if (this.checked) {
            lbl.classList.remove('bg-gray-50','border-gray-200');
            lbl.classList.add('bg-green-50','border-green-300');
        } else {
            lbl.classList.remove('bg-green-50','border-green-300');
            lbl.classList.add('bg-gray-50','border-gray-200');
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
