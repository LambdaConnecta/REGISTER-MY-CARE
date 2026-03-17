<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/subscription.php';
requireLogin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$pageTitle = 'Service Users';
$isAdmin   = isAdmin();

// ── Self-healing: fix funding_type ENUM->VARCHAR and add missing columns ──
try {
    $pdo->exec("ALTER TABLE `service_users`
        MODIFY COLUMN `funding_type` VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN `council_id`   INT UNSIGNED DEFAULT NULL,
        ADD COLUMN `nhs_trust_id` INT UNSIGNED DEFAULT NULL");
} catch(Exception $e) {
    try { $pdo->exec("ALTER TABLE `service_users` MODIFY COLUMN `funding_type` VARCHAR(100) NOT NULL DEFAULT ''"); } catch(Exception $e2) {}
    try { $pdo->exec("ALTER TABLE `service_users` ADD COLUMN `council_id` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e2) {}
    try { $pdo->exec("ALTER TABLE `service_users` ADD COLUMN `nhs_trust_id` INT UNSIGNED DEFAULT NULL"); } catch(Exception $e2) {}
}

// ── Subscription check ────────────────────────────────────────────────
$sub = getOrgSubscription($orgId);

// ── POST Actions (admin only) ─────────────────────────────────────────
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add', 'edit'])) {
        $id        = (int)($_POST['su_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $dob       = $_POST['date_of_birth'] ?: null;
        $nhs       = trim($_POST['nhs_number'] ?? '');
        $funding   = trim($_POST['funding_type'] ?? '');
        $councilId = (int)($_POST['council_id'] ?? 0) ?: null;
        $nhsTrust  = (int)($_POST['nhs_trust_id'] ?? 0) ?: null;
        $address   = trim($_POST['address'] ?? '');
        $allergy   = trim($_POST['allergies'] ?? '');
        $emergency = trim($_POST['emergency_contact'] ?? '');
        $gp        = trim($_POST['gp_details'] ?? '');
        $hourly    = (float)($_POST['hourly_rate'] ?? 0);
        $lat       = $_POST['latitude']  ?: null;
        $lng       = $_POST['longitude'] ?: null;

        if (!$firstName || !$lastName) {
            setFlash('error', 'First and last name are required.');
            header('Location: service_users.php'); exit;
        }

        if ($action === 'add') {
            // Hard server-side block — cannot be bypassed
            if ($sub['at_limit'] && !$sub['is_premium']) {
                setFlash('error', 'Free plan limit of ' . FREE_PLAN_SU_LIMIT . ' service users reached. Please upgrade to continue.');
                header('Location: subscription.php'); exit;
            }
            try {
                $pdo->prepare("INSERT INTO service_users
                    (organisation_id,first_name,last_name,date_of_birth,nhs_number,funding_type,
                     council_id,nhs_trust_id,address,allergies,emergency_contact,gp_details,
                     hourly_rate,latitude,longitude,is_active,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())")
                    ->execute([$orgId,$firstName,$lastName,$dob,$nhs,$funding,
                               $councilId,$nhsTrust,$address,$allergy,$emergency,$gp,$hourly,$lat,$lng]);
                setFlash('success', 'Service user added successfully.');
            } catch (Exception $e) {
                setFlash('error', 'Could not add service user: '.$e->getMessage());
            }
        } else {
            try {
                $pdo->prepare("UPDATE service_users
                    SET first_name=?,last_name=?,date_of_birth=?,nhs_number=?,funding_type=?,
                        council_id=?,nhs_trust_id=?,address=?,allergies=?,emergency_contact=?,
                        gp_details=?,hourly_rate=?,latitude=?,longitude=?
                    WHERE id=? AND organisation_id=?")
                    ->execute([$firstName,$lastName,$dob,$nhs,$funding,
                               $councilId,$nhsTrust,$address,$allergy,$emergency,$gp,
                               $hourly,$lat,$lng,$id,$orgId]);
                setFlash('success', 'Service user updated.');
            } catch (Exception $e) {
                setFlash('error', 'Could not update: '.$e->getMessage());
            }
        }
        header('Location: service_users.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['su_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE service_users SET is_active=0 WHERE id=? AND organisation_id=?")
                ->execute([$id,$orgId]);
            setFlash('success', 'Service user deactivated.');
        } catch (Exception $e) { setFlash('error', 'Error: '.$e->getMessage()); }
        header('Location: service_users.php'); exit;
    }
}

// ── Redirect ?add=1 if at limit ───────────────────────────────────────
// Show modal instead of the add form
$showLimitModal = false;
if ($isAdmin && isset($_GET['add']) && $sub['at_limit'] && !$sub['is_premium']) {
    $showLimitModal = true;
}

// ── Load service users ────────────────────────────────────────────────
$sus = [];
try {
    $q = $pdo->prepare("SELECT su.*, c.name AS council_name, n.name AS nhs_trust_name
        FROM service_users su
        LEFT JOIN uk_councils  c ON c.id = su.council_id
        LEFT JOIN nhs_hospitals n ON n.id = su.nhs_trust_id
        WHERE su.organisation_id=? AND su.is_active=1
        ORDER BY su.last_name, su.first_name");
    $q->execute([$orgId]);
    $sus = $q->fetchAll();
} catch (Exception $e) {
    try {
        $q = $pdo->prepare("SELECT * FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name,first_name");
        $q->execute([$orgId]);
        $sus = $q->fetchAll();
    } catch (Exception $e2) {
        setFlash('error', 'Could not load service users: '.$e2->getMessage());
    }
}

$editSU = null;
if ($isAdmin && isset($_GET['edit']) && !($sub['at_limit'] && !$sub['is_premium'])) {
    try {
        $q = $pdo->prepare("SELECT * FROM service_users WHERE id=? AND organisation_id=?");
        $q->execute([(int)$_GET['edit'], $orgId]);
        $editSU = $q->fetch();
    } catch (Exception $e) {}
}

$councils  = [];
$nhsTrusts = [];
try { $councils  = $pdo->query("SELECT id, name FROM uk_councils ORDER BY name")->fetchAll(); } catch(Exception $e){}
try { $nhsTrusts = $pdo->query("SELECT id, name FROM nhs_hospitals ORDER BY name")->fetchAll(); } catch(Exception $e){}

$fundingTypes = ['Self-funded','Local Authority','NHS Continuing Healthcare','Personal Health Budget','Mixed','Other'];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($showLimitModal || ($sub['at_limit'] && !$sub['is_premium'] && $isAdmin)): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     SUBSCRIPTION LIMIT MODAL — shown when at/over free plan limit
     ══════════════════════════════════════════════════════════════════════ -->
<div id="limitModal"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background:rgba(0,0,0,0.6);backdrop-filter:blur(4px)">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden animate-bounce-in">

        <!-- Modal header -->
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-5 text-center relative">
            <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center mx-auto mb-3">
                <i class="fa fa-crown text-white text-3xl"></i>
            </div>
            <h2 class="text-xl font-extrabold text-white">Free Plan Limit Reached</h2>
            <p class="text-amber-100 text-sm mt-1">You have used all <?= FREE_PLAN_SU_LIMIT ?> free service user slots</p>
        </div>

        <!-- Modal body -->
        <div class="px-6 py-5">
            <p class="text-gray-700 text-sm text-center leading-relaxed mb-5">
                Your organisation has reached the <strong><?= FREE_PLAN_SU_LIMIT ?> service user</strong> limit on the
                <strong>Free Plan</strong>. To add more service users and unlock all features, please upgrade to
                <strong class="text-amber-600">Premium — £100/month</strong>.
            </p>

            <!-- Features list -->
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 space-y-2">
                <div class="text-xs font-extrabold text-amber-800 uppercase tracking-widest mb-2">Premium includes</div>
                <?php foreach ([
                    'Unlimited service users',
                    'Full reports &amp; CSV exports',
                    'Rota &amp; scheduling',
                    'Document management',
                    'Staff messaging &amp; handover',
                    'Care plan builder',
                    'Priority support',
                ] as $f): ?>
                <div class="flex items-center gap-2 text-sm text-amber-900">
                    <i class="fa fa-circle-check text-amber-500 flex-shrink-0"></i><?= $f ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- CTA buttons -->
            <div class="space-y-3">
                <a href="/pages/subscription.php"
                   class="w-full flex items-center justify-center gap-2 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-extrabold py-3.5 px-6 rounded-2xl text-sm transition shadow-lg">
                    <i class="fa fa-star"></i>
                    Upgrade to Premium — £100/month
                </a>

                <a href="mailto:info@registermycare.org?subject=Premium%20Upgrade%20Request&body=Hello%2C%20I%20would%20like%20to%20upgrade%20my%20organisation%20to%20the%20Premium%20plan.%20Please%20contact%20me%20to%20arrange%20payment.%0A%0AOrganisation%3A%20<?= rawurlencode($_SESSION['org_name']??'') ?>"
                   class="w-full flex items-center justify-center gap-2 bg-teal-600 hover:bg-teal-700 text-white font-extrabold py-3.5 px-6 rounded-2xl text-sm transition">
                    <i class="fa fa-envelope"></i>
                    Contact Us — info@registermycare.org
                </a>

                <button onclick="document.getElementById('limitModal').style.display='none'"
                        class="w-full flex items-center justify-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-600 font-bold py-3 px-6 rounded-2xl text-sm transition">
                    <i class="fa fa-xmark"></i>
                    Close
                </button>
            </div>

            <p class="text-center text-xs text-gray-400 mt-4">
                Questions? Email us at
                <a href="mailto:info@registermycare.org" class="text-teal-600 font-semibold hover:underline">
                    info@registermycare.org
                </a>
            </p>
        </div>
    </div>
</div>
<script>
// Auto-show modal if arriving via ?add=1 at limit
<?php if ($showLimitModal): ?>
document.getElementById('limitModal').style.display = 'flex';
<?php endif; ?>
// Close on backdrop click
document.getElementById('limitModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
<?php endif; ?>

<!-- ── Page header ─────────────────────────────────────────────────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <div>
        <h2 class="text-xl font-extrabold text-gray-800">Service Users</h2>
        <p class="text-sm text-gray-500 mt-0.5"><?= count($sus) ?> active service user<?= count($sus)!==1?'s':'' ?></p>
    </div>
    <?php if ($isAdmin): ?>
        <?php if ($sub['at_limit'] && !$sub['is_premium']): ?>
        <!-- At limit: button opens upgrade modal -->
        <button onclick="document.getElementById('limitModal').style.display='flex'"
                class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-4 py-2.5 rounded-xl text-sm transition flex items-center gap-2 self-start">
            <i class="fa fa-crown"></i>Upgrade to Add More
        </button>
        <?php else: ?>
        <!-- Under limit or premium: normal add button -->
        <a href="service_users.php?add=1"
           class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2.5 rounded-xl text-sm transition flex items-center gap-2 self-start">
            <i class="fa fa-plus"></i>Add Service User
        </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Free plan usage bar (admin only, free plan only) -->
<?php if ($isAdmin && !$sub['is_premium']): ?>
<div class="bg-white rounded-2xl shadow px-5 py-4 mb-5 flex items-center gap-4">
    <div class="flex-1">
        <div class="flex justify-between text-xs font-bold text-gray-600 mb-1.5">
            <span>Free Plan Usage</span>
            <span class="<?= $sub['at_limit'] ? 'text-red-600' : 'text-teal-600' ?>">
                <?= $sub['active_su_count'] ?> / <?= FREE_PLAN_SU_LIMIT ?> service users
            </span>
        </div>
        <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-2.5 rounded-full transition-all <?= $sub['at_limit'] ? 'bg-red-500' : 'bg-teal-500' ?>"
                 style="width:<?= min(100, round($sub['active_su_count'] / FREE_PLAN_SU_LIMIT * 100)) ?>%"></div>
        </div>
    </div>
    <?php if ($sub['at_limit']): ?>
    <a href="/pages/subscription.php"
       class="flex-shrink-0 bg-amber-500 hover:bg-amber-600 text-white font-bold px-3 py-2 rounded-xl text-xs transition flex items-center gap-1.5">
        <i class="fa fa-star"></i>Upgrade
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Add / Edit Form (only shown when under limit or premium) ─────── -->
<?php if ($isAdmin && (isset($_GET['add']) || $editSU) && (!$sub['at_limit'] || $sub['is_premium'])): ?>
<div class="bg-white rounded-2xl shadow mb-6 overflow-hidden">
    <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-5 py-4">
        <h3 class="text-white font-extrabold text-base">
            <?= $editSU ? 'Edit Service User' : 'Add New Service User' ?>
        </h3>
    </div>
    <form method="POST" class="p-5">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editSU ? 'edit' : 'add' ?>">
        <?php if ($editSU): ?><input type="hidden" name="su_id" value="<?= $editSU['id'] ?>"><?php endif; ?>

        <h4 class="text-xs font-extrabold text-teal-700 uppercase tracking-widest mb-3">Personal Details</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-5">
            <div><label class="block text-xs font-bold text-gray-600 mb-1">First Name *</label>
                <input type="text" name="first_name" required value="<?= h($editSU['first_name']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Last Name *</label>
                <input type="text" name="last_name" required value="<?= h($editSU['last_name']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Date of Birth</label>
                <input type="date" name="date_of_birth" value="<?= h($editSU['date_of_birth']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">NHS Number</label>
                <input type="text" name="nhs_number" value="<?= h($editSU['nhs_number']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Funding Type</label>
                <select name="funding_type" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                    <option value="">— Select —</option>
                    <?php foreach ($fundingTypes as $ft): ?>
                    <option value="<?= h($ft) ?>" <?= ($editSU['funding_type']??'')===$ft?'selected':'' ?>><?= $ft ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Hourly Rate (£)</label>
                <input type="number" step="0.01" name="hourly_rate" value="<?= h($editSU['hourly_rate']??'0') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
        </div>

        <h4 class="text-xs font-extrabold text-teal-700 uppercase tracking-widest mb-3">Council &amp; NHS Trust</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Local Council / Authority</label>
                <select name="council_id" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                    <option value="">— Select Council —</option>
                    <?php foreach ($councils as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($editSU['council_id']??'')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">NHS Trust / Hospital</label>
                <select name="nhs_trust_id" class="w-full border rounded-xl px-3 py-2 text-sm bg-white focus:border-teal-500 focus:outline-none">
                    <option value="">— Select NHS Trust —</option>
                    <?php foreach ($nhsTrusts as $n): ?>
                    <option value="<?= $n['id'] ?>" <?= ($editSU['nhs_trust_id']??'')==$n['id']?'selected':'' ?>><?= h($n['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>

        <h4 class="text-xs font-extrabold text-teal-700 uppercase tracking-widest mb-3">Address &amp; Clinical</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-5">
            <div class="sm:col-span-2 lg:col-span-3"><label class="block text-xs font-bold text-gray-600 mb-1">Address</label>
                <input type="text" name="address" value="<?= h($editSU['address']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Allergies</label>
                <input type="text" name="allergies" value="<?= h($editSU['allergies']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Emergency Contact</label>
                <input type="text" name="emergency_contact" value="<?= h($editSU['emergency_contact']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">GP Details</label>
                <input type="text" name="gp_details" value="<?= h($editSU['gp_details']??'') ?>"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Latitude (GPS)</label>
                <input type="text" name="latitude" value="<?= h($editSU['latitude']??'') ?>" placeholder="e.g. 51.5074"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-600 mb-1">Longitude (GPS)</label>
                <input type="text" name="longitude" value="<?= h($editSU['longitude']??'') ?>" placeholder="e.g. -0.1278"
                       class="w-full border rounded-xl px-3 py-2 text-sm focus:border-teal-500 focus:outline-none"></div>
        </div>

        <div class="flex gap-3">
            <button type="submit"
                    class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition">
                <?= $editSU ? 'Save Changes' : 'Add Service User' ?>
            </button>
            <a href="service_users.php"
               class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-xl text-sm transition">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Service Users Grid ─────────────────────────────────────────── -->
<?php if (empty($sus)): ?>
<div class="bg-white rounded-2xl shadow p-12 text-center">
    <div class="text-5xl mb-3">👤</div>
    <h3 class="text-lg font-bold text-gray-600 mb-1">No service users yet</h3>
    <?php if ($isAdmin): ?><p class="text-gray-400 text-sm">Click "Add Service User" to get started.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($sus as $su): ?>
<div class="bg-white rounded-2xl shadow overflow-hidden flex flex-col">
    <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-4 py-3 flex items-center gap-3">
        <div class="w-11 h-11 rounded-full bg-white/20 flex items-center justify-center text-white font-extrabold text-sm flex-shrink-0">
            <?= strtoupper(substr($su['first_name'],0,1).substr($su['last_name'],0,1)) ?>
        </div>
        <div class="min-w-0">
            <div class="font-extrabold text-white truncate"><?= h($su['first_name'].' '.$su['last_name']) ?></div>
            <div class="text-xs text-teal-200"><?= $su['date_of_birth'] ? date('d/m/Y',strtotime($su['date_of_birth'])) : 'DoB not recorded' ?></div>
        </div>
    </div>
    <div class="p-4 flex-1 space-y-1.5 text-xs">
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">NHS No.</span>
            <span class="font-semibold text-gray-700"><?= h($su['nhs_number']?:'—') ?></span></div>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Funding</span>
            <span class="font-semibold text-gray-700"><?= h($su['funding_type']?:'—') ?></span></div>
        <?php if (!empty($su['council_name'])): ?>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Council</span>
            <span class="font-semibold text-gray-700 truncate"><?= h($su['council_name']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($su['nhs_trust_name'])): ?>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">NHS Trust</span>
            <span class="font-semibold text-gray-700 truncate"><?= h($su['nhs_trust_name']) ?></span></div>
        <?php endif; ?>
        <?php if ($su['allergies']): ?>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Allergies</span>
            <span class="font-semibold text-amber-700">&#9888; <?= h($su['allergies']) ?></span></div>
        <?php endif; ?>
        <?php if ($su['address']): ?>
        <div class="flex gap-2"><span class="text-gray-400 w-20 flex-shrink-0">Address</span>
            <span class="text-gray-600 truncate"><?= h($su['address']) ?></span></div>
        <?php endif; ?>
    </div>
    <div class="px-4 pb-4 flex gap-2 flex-wrap">
        <a href="su_profile.php?id=<?= $su['id'] ?>"
           class="flex-1 text-center text-xs bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 px-3 py-2 rounded-xl font-bold transition">
            <i class="fa fa-folder-open mr-1"></i>Full Profile
        </a>
        <?php if ($isAdmin): ?>
        <a href="?edit=<?= $su['id'] ?>"
           class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-100 px-3 py-2 rounded-xl font-bold transition">
            <i class="fa fa-pen"></i>
        </a>
        <form method="POST" onsubmit="return confirm('Deactivate this service user?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="su_id" value="<?= $su['id'] ?>">
            <button type="submit"
                    class="text-xs bg-red-50 hover:bg-red-100 text-red-700 border border-red-100 px-3 py-2 rounded-xl font-bold transition">
                <i class="fa fa-trash"></i>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
