<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pageTitle = 'Messages';

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_msg'])) {
    validateCSRF();
    $toId      = (int)($_POST['to_id'] ?? 0);
    $subject   = trim($_POST['subject'] ?? '(No subject)');
    $body      = trim($_POST['body']    ?? '');
    $broadcast = isset($_POST['broadcast']) ? 1 : 0;
    if (!$body) { setFlash('error','Message body cannot be empty.'); header('Location: messages.php'); exit; }
    try {
        if ($broadcast) {
            $pdo->prepare("INSERT INTO staff_messages (organisation_id,from_id,to_id,is_broadcast,subject,body,created_at) VALUES(?,?,NULL,1,?,?,NOW())")->execute([$orgId,$uid,$subject,$body]);
        } else {
            if (!$toId) { setFlash('error','Please select a recipient.'); header('Location: messages.php?compose=1'); exit; }
            $pdo->prepare("INSERT INTO staff_messages (organisation_id,from_id,to_id,is_broadcast,subject,body,created_at) VALUES(?,?,?,0,?,?,NOW())")->execute([$orgId,$uid,$toId,$subject,$body]);
        }
        setFlash('success','Message sent successfully.');
    } catch (Exception $e) { setFlash('error','Could not send: '.$e->getMessage()); }
    header('Location: messages.php'); exit;
}

// Mark as read
if (isset($_GET['read'])) {
    $mid = (int)$_GET['read'];
    try { $pdo->prepare("UPDATE staff_messages SET is_read=1 WHERE id=? AND (to_id=? OR is_broadcast=1)")->execute([$mid,$uid]); } catch(Exception $e){}
    header("Location: messages.php?view=$mid"); exit;
}

// Delete (sender side)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_msg'])) {
    validateCSRF();
    $mid = (int)$_POST['msg_id'];
    try { $pdo->prepare("UPDATE staff_messages SET deleted_by_sender=1 WHERE id=? AND from_id=?")->execute([$mid,$uid]); } catch(Exception $e){}
    setFlash('success','Message deleted.');
    header('Location: messages.php?tab=sent'); exit;
}

$tab = $_GET['tab'] ?? 'inbox';

// Inbox: messages TO me or broadcasts (not from me)
$inbox = [];
try {
    $q = $pdo->prepare("SELECT m.*, u.first_name ff, u.last_name fl FROM staff_messages m JOIN users u ON m.from_id=u.id WHERE m.organisation_id=? AND (m.to_id=? OR m.is_broadcast=1) AND m.from_id!=? ORDER BY m.created_at DESC");
    $q->execute([$orgId,$uid,$uid]); $inbox=$q->fetchAll();
} catch(Exception $e){}

// Sent: messages FROM me
$sent = [];
try {
    $q = $pdo->prepare("SELECT m.*, u.first_name tf, u.last_name tl FROM staff_messages m LEFT JOIN users u ON m.to_id=u.id WHERE m.organisation_id=? AND m.from_id=? AND m.deleted_by_sender=0 ORDER BY m.created_at DESC");
    $q->execute([$orgId,$uid]); $sent=$q->fetchAll();
} catch(Exception $e){}

// View single message
$viewMsg = null;
if (isset($_GET['view'])) {
    $mid = (int)$_GET['view'];
    try {
        $q = $pdo->prepare("SELECT m.*, u.first_name ff, u.last_name fl, t.first_name tf, t.last_name tl FROM staff_messages m JOIN users u ON m.from_id=u.id LEFT JOIN users t ON m.to_id=t.id WHERE m.id=? AND m.organisation_id=?");
        $q->execute([$mid,$orgId]); $viewMsg=$q->fetch();
        if ($viewMsg && ($viewMsg['to_id']==$uid || $viewMsg['is_broadcast'])) {
            $pdo->prepare("UPDATE staff_messages SET is_read=1 WHERE id=?")->execute([$mid]);
        }
    } catch(Exception $e){}
}

// All staff for compose
$staffList = [];
try {
    $q = $pdo->prepare("SELECT id,first_name,last_name,job_category FROM users WHERE organisation_id=? AND is_active=1 AND id!=? ORDER BY last_name,first_name");
    $q->execute([$orgId,$uid]); $staffList=$q->fetchAll();
} catch(Exception $e){}

$unread = count(array_filter($inbox, fn($m)=>!$m['is_read']));
include __DIR__ . '/../includes/header.php';
?>
<div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-5">
    <h2 class="text-xl font-extrabold text-gray-800 flex items-center gap-2">
        <i class="fa fa-comments text-teal-500"></i>Messages
        <?php if ($unread>0): ?><span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full font-bold"><?=$unread?> unread</span><?php endif; ?>
    </h2>
    <button onclick="document.getElementById('composeModal').classList.remove('hidden')"
            class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2.5 rounded-xl text-sm flex items-center gap-2 transition self-start">
        <i class="fa fa-pen-to-square"></i>Compose
    </button>
</div>

<?php if ($viewMsg): ?>
<!-- View message -->
<div class="bg-white rounded-2xl shadow mb-5 overflow-hidden">
    <div class="bg-gray-50 px-5 py-4 border-b flex items-center justify-between">
        <h3 class="font-extrabold text-gray-800"><?= h($viewMsg['subject']) ?></h3>
        <a href="messages.php" class="text-xs text-gray-400 hover:text-gray-600">← Back</a>
    </div>
    <div class="p-5">
        <div class="flex items-center justify-between text-sm text-gray-500 mb-4 pb-4 border-b border-gray-100">
            <div>
                <span class="font-semibold text-gray-700">From:</span> <?= h($viewMsg['ff'].' '.$viewMsg['fl']) ?>
                <?php if ($viewMsg['is_broadcast']): ?><span class="ml-2 text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Broadcast to all staff</span><?php elseif ($viewMsg['tf']): ?>
                · <span class="font-semibold text-gray-700">To:</span> <?= h($viewMsg['tf'].' '.$viewMsg['tl']) ?><?php endif; ?>
            </div>
            <div class="text-xs"><?= date('d M Y H:i', strtotime($viewMsg['created_at'])) ?></div>
        </div>
        <div class="text-gray-700 whitespace-pre-wrap text-sm leading-relaxed"><?= h($viewMsg['body']) ?></div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <a href="?tab=inbox" class="px-4 py-2.5 text-sm font-semibold border-b-2 <?=$tab==='inbox'?'border-teal-500 text-teal-700':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fa fa-inbox mr-1"></i>Inbox
        <?php if ($unread>0): ?><span class="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full ml-1"><?=$unread?></span><?php endif; ?>
    </a>
    <a href="?tab=sent" class="px-4 py-2.5 text-sm font-semibold border-b-2 <?=$tab==='sent'?'border-teal-500 text-teal-700':'border-transparent text-gray-500 hover:text-gray-700'?>">
        <i class="fa fa-paper-plane mr-1"></i>Sent
    </a>
</div>

<?php $list = $tab==='inbox' ? $inbox : $sent; ?>
<div class="bg-white rounded-2xl shadow overflow-hidden">
    <?php if (empty($list)): ?>
    <div class="p-10 text-center text-gray-400">
        <div class="text-4xl mb-2"><?= $tab==='inbox'?'📬':'📤' ?></div>
        <p><?= $tab==='inbox'?'No messages in your inbox.':'You have not sent any messages yet.' ?></p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-100">
    <?php foreach ($list as $m):
        $isUnread = ($tab==='inbox' && !$m['is_read']);
        if ($tab==='inbox') { $who = h(($m['ff']??'').' '.($m['fl']??'')); $icon='fa-user'; }
        else { $who = $m['is_broadcast'] ? 'All Staff (Broadcast)' : h(($m['tf']??'').' '.($m['tl']??'Unknown')); $icon='fa-paper-plane'; }
    ?>
    <div class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition <?=$isUnread?'bg-blue-50/40':''?>">
        <div class="w-9 h-9 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0 mt-0.5">
            <i class="fa <?=$icon?> text-teal-600 text-sm"></i>
        </div>
        <div class="flex-1 min-w-0 cursor-pointer" onclick="location='?<?=$tab==='inbox'?'read':'view'?>=<?=$m['id']?>&tab=<?=$tab?>'">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-<?=$isUnread?'extrabold':'semibold'?> text-gray-800 truncate"><?=$who?></span>
                <span class="text-xs text-gray-400 flex-shrink-0"><?=date('d M H:i',strtotime($m['created_at']))?></span>
            </div>
            <div class="text-sm <?=$isUnread?'font-bold text-gray-800':'text-gray-600'?> truncate"><?=h($m['subject'])?></div>
            <div class="text-xs text-gray-400 truncate"><?=h(substr($m['body'],0,80))?></div>
        </div>
        <?php if ($isUnread): ?><span class="w-2.5 h-2.5 bg-blue-500 rounded-full flex-shrink-0 mt-2"></span><?php endif; ?>
        <?php if ($tab==='sent'): ?>
        <form method="POST" class="flex-shrink-0">
            <?= csrfField() ?>
            <input type="hidden" name="delete_msg" value="1">
            <input type="hidden" name="msg_id" value="<?=$m['id']?>">
            <button type="submit" onclick="return confirm('Delete this message?')" class="text-red-400 hover:text-red-600 text-xs p-1"><i class="fa fa-trash"></i></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Compose Modal -->
<div id="composeModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.5)">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-y-auto" style="max-height:90vh">
        <div class="bg-gradient-to-r from-teal-600 to-teal-700 px-5 py-4 flex items-center justify-between rounded-t-2xl">
            <h3 class="text-white font-extrabold"><i class="fa fa-pen-to-square mr-2"></i>New Message</h3>
            <button onclick="document.getElementById('composeModal').classList.add('hidden')" class="text-teal-200 hover:text-white text-xl font-bold leading-none">&times;</button>
        </div>
        <form method="POST" class="p-5">
            <?= csrfField() ?>
            <input type="hidden" name="send_msg" value="1">
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-700 mb-1.5">Send To</label>
                <div class="flex items-center gap-3 mb-2">
                    <label class="flex items-center gap-2 cursor-pointer text-sm font-medium">
                        <input type="checkbox" name="broadcast" id="broadcastChk" onchange="toggleBroadcast()"
                               class="w-4 h-4 rounded text-teal-600">
                        Send to ALL staff (Broadcast)
                    </label>
                </div>
                <select name="to_id" id="toSelect" class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none bg-white">
                    <option value="">— Select recipient —</option>
                    <?php foreach ($staffList as $s): ?>
                    <option value="<?=$s['id']?>"><?=h($s['last_name'].', '.$s['first_name'])?> <?=$s['job_category']?'('. h($s['job_category']).')':''?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-700 mb-1.5">Subject</label>
                <input type="text" name="subject" placeholder="Message subject..." required
                    class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none">
            </div>
            <div class="mb-5">
                <label class="block text-xs font-bold text-gray-700 mb-1.5">Message</label>
                <textarea name="body" rows="5" required placeholder="Type your message here..."
                    class="w-full border rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 rounded-xl text-sm transition">
                    <i class="fa fa-paper-plane mr-1"></i>Send Message
                </button>
                <button type="button" onclick="document.getElementById('composeModal').classList.add('hidden')"
                        class="px-5 border border-gray-200 rounded-xl text-sm hover:bg-gray-50">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function toggleBroadcast() {
    var chk=document.getElementById('broadcastChk'), sel=document.getElementById('toSelect');
    sel.disabled=chk.checked; if(chk.checked) sel.value='';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
