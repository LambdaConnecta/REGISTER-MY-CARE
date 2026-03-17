<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$pdo   = getPDO();
$orgId = (int)$_SESSION['organisation_id'];
$uid   = (int)$_SESSION['user_id'];
$pageTitle = 'Invoices';

// ── Self-heal ─────────────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`organisation_id` INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,`client_name` VARCHAR(255) NOT NULL DEFAULT '',
  `client_address` TEXT DEFAULT NULL,`client_email` VARCHAR(255) DEFAULT NULL,
  `service_period` VARCHAR(120) DEFAULT NULL,`issue_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'Draft',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,`vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,`total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,`auto_generated` TINYINT(1) NOT NULL DEFAULT 0,
  `gen_period_from` DATE DEFAULT NULL,`gen_period_to` DATE DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),KEY `k_org`(`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `invoice_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`invoice_id` INT UNSIGNED NOT NULL,
  `description` VARCHAR(500) NOT NULL,`quantity` DECIMAL(10,2) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,`line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_type` VARCHAR(30) NOT NULL DEFAULT 'auto',
  PRIMARY KEY (`id`),KEY `k_inv`(`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
foreach(array(
    "ALTER TABLE `invoices` ADD COLUMN `auto_generated` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `invoices` ADD COLUMN `gen_period_from` DATE DEFAULT NULL",
    "ALTER TABLE `invoices` ADD COLUMN `gen_period_to` DATE DEFAULT NULL",
    "ALTER TABLE `invoices` ADD COLUMN `client_email` VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE `invoices` ADD COLUMN `service_period` VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE `invoices` ADD COLUMN `due_date` DATE DEFAULT NULL",
    "ALTER TABLE `invoice_lines` ADD COLUMN `line_type` VARCHAR(30) NOT NULL DEFAULT 'auto'",
) as $s){ try{$pdo->exec($s);}catch(Exception $e){} }

// Org
$org = array();
try{ $q=$pdo->prepare("SELECT * FROM organisations WHERE id=?"); $q->execute(array($orgId)); $r=$q->fetch(); if($r) $org=$r; }catch(Exception $e){}

function nextInvNo($pdo,$orgId){
    try{ $q=$pdo->prepare("SELECT COUNT(*)+1 FROM invoices WHERE organisation_id=?"); $q->execute(array($orgId)); return 'INV-'.date('Y').'-'.str_pad((int)$q->fetchColumn(),4,'0',STR_PAD_LEFT); }catch(Exception $e){ return 'INV-'.date('YmdHis'); }
}
function saveAutoInv($pdo,$orgId,$uid,$d,$lines){
    $sub=0; foreach($lines as $l){ if(!empty($l['is_header'])) continue; $sub+=round((float)$l['qty']*(float)$l['price'],2); }
    $vatR=(float)$d['vat_rate']; $vatA=round($sub*($vatR/100),2); $tot=round($sub+$vatA,2);
    $pdo->prepare("INSERT INTO invoices(organisation_id,invoice_number,client_name,client_address,client_email,service_period,issue_date,due_date,status,subtotal,vat_rate,vat_amount,total,notes,auto_generated,gen_period_from,gen_period_to,created_by) VALUES(?,?,?,?,?,?,?,?,'Draft',?,?,?,?,?,1,?,?,?)")
        ->execute(array($orgId,$d['number'],$d['client'],$d['address'],$d['email'],$d['period'],$d['issue_date'],$d['due_date'],$sub,$vatR,$vatA,$tot,$d['notes'],$d['from'],$d['to'],$uid));
    $iid=(int)$pdo->lastInsertId();
    foreach($lines as $l){
        $hdr=!empty($l['is_header']); $qty=$hdr?0:(float)$l['qty']; $prc=$hdr?0:(float)$l['price'];
        $pdo->prepare("INSERT INTO invoice_lines(invoice_id,description,quantity,unit_price,line_total,line_type) VALUES(?,?,?,?,?,?)")
            ->execute(array($iid,$l['desc'],$qty,$prc,round($qty*$prc,2),$l['type']));
    }
    return $iid;
}

// ── POST: Auto-generate ───────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='auto_generate'){
    validateCSRF();
    $from=(string)($_POST['gen_from']??date('Y-m-01'));
    $to=(string)($_POST['gen_to']??date('Y-m-d'));
    $client=trim($_POST['client_name']??'');
    $address=trim($_POST['client_address']??'');
    $email=trim($_POST['client_email']??'');
    $hrRate=(float)($_POST['hourly_rate']??0);
    $visRate=(float)($_POST['visit_rate']??0);
    $vatRate=(float)($_POST['vat_rate']??0);
    $incMeds=!empty($_POST['include_meds']);
    $incExtra=!empty($_POST['include_extra']);
    $lines=array();
    try{
        $q=$pdo->prepare("SELECT su.id AS su_id,su.first_name AS sfn,su.last_name AS sln,COALESCE(u.first_name,'') AS cfn,COALESCE(u.last_name,'') AS cln,COUNT(v.id) AS visits,SUM(TIMESTAMPDIFF(MINUTE,COALESCE(v.actual_start_time,v.start_time),COALESCE(v.actual_end_time,v.end_time))) AS total_mins FROM visits v JOIN service_users su ON v.service_user_id=su.id LEFT JOIN users u ON v.carer_id=u.id WHERE v.organisation_id=? AND v.visit_date BETWEEN ? AND ? AND LOWER(COALESCE(v.status,'')) NOT IN ('cancelled','canceled') GROUP BY su.id,su.first_name,su.last_name,u.id,u.first_name,u.last_name ORDER BY su.last_name,su.first_name");
        $q->execute(array($orgId,$from,$to)); $rows=$q->fetchAll();
        if(!empty($rows)){
            $bySu=array();
            foreach($rows as $r){
                $k=(int)$r['su_id'];
                if(!isset($bySu[$k])) $bySu[$k]=array('name'=>trim($r['sfn'].' '.$r['sln']),'visits'=>0,'mins'=>0,'carers'=>array());
                $bySu[$k]['visits']+=(int)$r['visits']; $bySu[$k]['mins']+=(int)$r['total_mins'];
                $cn=trim($r['cfn'].' '.$r['cln']); if($cn && !in_array($cn,$bySu[$k]['carers'])) $bySu[$k]['carers'][]=$cn;
            }
            $lines[]=array('desc'=>'CARE VISITS: '.date('d M Y',strtotime($from)).' to '.date('d M Y',strtotime($to)),'qty'=>0,'price'=>0,'type'=>'heading','is_header'=>true);
            foreach($bySu as $d){
                $hrs=round($d['mins']/60,2); $cnt=(int)$d['visits'];
                $cars=!empty($d['carers'])?implode(', ',$d['carers']):'Unassigned';
                if($hrRate>0 && $hrs>0) $lines[]=array('desc'=>$d['name'].' - '.$cnt.' visit'.($cnt!==1?'s':'').', '.$hrs.'h (Staff: '.$cars.')','qty'=>$hrs,'price'=>$hrRate,'type'=>'visit');
                elseif($visRate>0 && $cnt>0) $lines[]=array('desc'=>$d['name'].' - '.$cnt.' visit'.($cnt!==1?'s':'').' (Staff: '.$cars.')','qty'=>$cnt,'price'=>$visRate,'type'=>'visit');
            }
        }
    }catch(Exception $e){}
    if($incMeds){
        try{ $q=$pdo->prepare("SELECT COUNT(*) FROM mar_entries WHERE organisation_id=? AND entry_date BETWEEN ? AND ? AND status='Given'"); $q->execute(array($orgId,$from,$to)); $mc=(int)$q->fetchColumn(); if($mc>0) $lines[]=array('desc'=>'Medication administration ('.$mc.' administrations)','qty'=>$mc,'price'=>0,'type'=>'medication'); }catch(Exception $e){}
    }
    if($incExtra){
        $descs=(array)($_POST['extra_desc']??array()); $qtys=(array)($_POST['extra_qty']??array()); $prices=(array)($_POST['extra_price']??array());
        foreach($descs as $i=>$dd){ $dd=trim($dd); if(!$dd) continue; $lines[]=array('desc'=>$dd,'qty'=>(float)($qtys[$i]??1),'price'=>(float)($prices[$i]??0),'type'=>'extra'); }
    }
    $billable=array(); foreach($lines as $l){ if(!empty($l['is_header'])) continue; if((float)($l['qty']??0)>0 && (float)($l['price']??0)>0) $billable[]=$l; }
    if(!$client){ setFlash('error','Please enter a client name.'); header('Location: invoices.php'); exit; }
    if(empty($billable)){ setFlash('error','No billable hours found. Ensure visits are recorded and enter an hourly or per-visit rate.'); header('Location: invoices.php'); exit; }
    $final=array(); foreach($lines as $l){ if(!empty($l['is_header'])){ $final[]=$l; break; } } foreach($billable as $l) $final[]=$l;
    try{
        $iid=saveAutoInv($pdo,$orgId,$uid,array('number'=>nextInvNo($pdo,$orgId),'client'=>$client,'address'=>$address,'email'=>$email,'period'=>date('d M',strtotime($from)).' – '.date('d M Y',strtotime($to)),'issue_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+30 days')),'vat_rate'=>$vatRate,'notes'=>'Auto-generated from care records. Payment due within 30 days.','from'=>$from,'to'=>$to),$final);
        addAuditLog($pdo,'AUTO_INVOICE','invoices',$iid,'Auto: '.$client);
        setFlash('success','Invoice generated and saved as Draft.');
        header('Location: invoices.php?view='.$iid); exit;
    }catch(Exception $e){ setFlash('error','DB error: '.$e->getMessage()); header('Location: invoices.php'); exit; }
}

// ── POST: Status ──────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_status'){
    validateCSRF();
    $iid=(int)($_POST['invoice_id']??0); $st=$_POST['status']??'Draft';
    if(!in_array($st,array('Draft','Sent','Paid','Overdue','Cancelled'))) $st='Draft';
    try{ $pdo->prepare("UPDATE invoices SET status=? WHERE id=? AND organisation_id=?")->execute(array($st,$iid,$orgId)); setFlash('success','Status updated.'); }catch(Exception $e){}
    header('Location: invoices.php?view='.$iid); exit;
}

// ── POST: Delete ──────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_invoice'){
    validateCSRF();
    $iid=(int)($_POST['invoice_id']??0);
    try{ $pdo->prepare("DELETE FROM invoice_lines WHERE invoice_id=?")->execute(array($iid)); $pdo->prepare("DELETE FROM invoices WHERE id=? AND organisation_id=?")->execute(array($iid,$orgId)); setFlash('success','Invoice deleted.'); }catch(Exception $e){}
    header('Location: invoices.php'); exit;
}

// ── Load data ─────────────────────────────────────────────────────────
$invoices=array();
try{ $q=$pdo->prepare("SELECT * FROM invoices WHERE organisation_id=? ORDER BY created_at DESC"); $q->execute(array($orgId)); $invoices=$q->fetchAll(); }catch(Exception $e){}
$viewInv=null; $viewLines=array();
if(!empty($_GET['view'])){
    $viewId=(int)$_GET['view'];
    try{ $q=$pdo->prepare("SELECT * FROM invoices WHERE id=? AND organisation_id=?"); $q->execute(array($viewId,$orgId)); $r=$q->fetch(); if($r) $viewInv=$r; }catch(Exception $e){}
    if($viewInv){ try{ $q=$pdo->prepare("SELECT * FROM invoice_lines WHERE invoice_id=? ORDER BY id"); $q->execute(array($viewId)); $viewLines=$q->fetchAll(); }catch(Exception $e){} }
    else{ header('Location: invoices.php'); exit; }
}
$visitCount=0;
try{ $q=$pdo->prepare("SELECT COUNT(*) FROM visits WHERE organisation_id=? AND LOWER(COALESCE(status,'')) NOT IN ('cancelled','canceled')"); $q->execute(array($orgId)); $visitCount=(int)$q->fetchColumn(); }catch(Exception $e){}

include __DIR__ . '/../includes/header.php';

// ══════════════════════════════════════════════════════════
// INVOICE VIEW
// ══════════════════════════════════════════════════════════
if($viewInv):
$sClrs=array('Draft'=>'#6b7280','Sent'=>'#2563eb','Paid'=>'#16a34a','Overdue'=>'#dc2626','Cancelled'=>'#9ca3af');
$sClr=isset($sClrs[$viewInv['status']])?$sClrs[$viewInv['status']]:'#6b7280';
$iDateStr=$viewInv['issue_date']?date('d M Y',strtotime($viewInv['issue_date'])):'—';
$dDateStr=$viewInv['due_date']?date('d M Y',strtotime($viewInv['due_date'])):'—';
$prdStr=$viewInv['service_period']?h($viewInv['service_period']):'—';
$orgLogoPath=isset($org['logo_path'])?$org['logo_path']:null;
?>
<style>
@media print{
  .no-print{display:none!important;}
  body,html{background:#fff!important;margin:0;padding:0;}
  #invPrint{box-shadow:none!important;border-radius:0!important;max-width:100%!important;margin:0!important;}
  header,nav,aside,.sidebar,footer{display:none!important;}
  main,#main-content{padding:0!important;margin:0!important;}
}
</style>

<!-- Responsive toolbar -->
<div class="no-print mb-4">
  <!-- Top row: back + status -->
  <div class="bg-white rounded-2xl shadow px-4 py-3 flex flex-wrap items-center gap-2 mb-2">
    <a href="invoices.php" class="flex items-center gap-1.5 text-sm font-bold text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-xl transition">
      <i class="fa fa-arrow-left text-xs"></i><span>All Invoices</span>
    </a>
    <span class="text-xs font-extrabold px-3 py-1.5 rounded-xl" style="background:<?=$sClr?>22;color:<?=$sClr?>;"><?=h($viewInv['status'])?></span>
    <div class="flex-1"></div>
    <form method="POST" class="flex items-center">
      <?=csrfField()?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="invoice_id" value="<?=$viewInv['id']?>">
      <select name="status" onchange="this.form.submit()" class="text-xs border border-gray-200 rounded-xl px-2.5 py-2 bg-white font-bold focus:outline-none">
        <?php foreach(array('Draft','Sent','Paid','Overdue','Cancelled') as $s): ?>
        <option <?=($viewInv['status']===$s)?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <!-- Download buttons row - responsive grid -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
    <button onclick="window.print()" class="flex items-center justify-center gap-2 bg-gray-800 hover:bg-gray-900 text-white font-bold py-3 rounded-2xl text-sm transition shadow">
      <i class="fa fa-print text-base"></i><span>Print / PDF</span>
    </button>
    <button onclick="dlPDF()" class="flex items-center justify-center gap-2 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-2xl text-sm transition shadow">
      <i class="fa fa-file-pdf text-base"></i><span>Save PDF</span>
    </button>
    <button onclick="dlWord()" class="flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-2xl text-sm transition shadow">
      <i class="fa fa-file-word text-base"></i><span>Word (.doc)</span>
    </button>
    <button onclick="dlCSV()" class="flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-2xl text-sm transition shadow">
      <i class="fa fa-file-csv text-base"></i><span>Spreadsheet</span>
    </button>
  </div>
</div>

<?php if($flash=getFlash()): ?>
<div class="no-print mb-4 px-4 py-3 rounded-2xl text-sm font-semibold <?=$flash['type']==='success'?'bg-green-50 text-green-800 border border-green-200':'bg-red-50 text-red-800 border border-red-200'?>"><?=h($flash['msg'])?></div>
<?php endif; ?>

<!-- ═══ INVOICE DOCUMENT ════════════════════════════════════════════ -->
<div id="invPrint" class="bg-white rounded-2xl shadow-xl overflow-hidden max-w-3xl mx-auto" style="font-family:'Plus Jakarta Sans',Calibri,Arial,sans-serif;">
  <!-- Gradient header -->
  <div style="background:linear-gradient(135deg,#0f766e,#0d9488);padding:clamp(20px,4vw,36px);display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:20px;">
    <div style="min-width:0;">
      <?php if($orgLogoPath && file_exists(__DIR__.'/../'.$orgLogoPath)): ?>
      <img src="/<?=h($orgLogoPath)?>" style="height:44px;object-fit:contain;display:block;margin-bottom:10px;">
      <?php endif; ?>
      <div style="color:#fff;font-size:clamp(16px,3vw,22px);font-weight:900;word-break:break-word;"><?=h($org['name']??'')?></div>
      <?php if(!empty($org['address'])): ?><div style="color:rgba(255,255,255,.7);font-size:12px;margin-top:4px;"><?=nl2br(h($org['address']))?></div><?php endif; ?>
      <?php if(!empty($org['email'])): ?><div style="color:rgba(255,255,255,.7);font-size:12px;"><?=h($org['email'])?></div><?php endif; ?>
      <?php if(!empty($org['phone'])): ?><div style="color:rgba(255,255,255,.7);font-size:12px;"><?=h($org['phone'])?></div><?php endif; ?>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <div style="color:rgba(255,255,255,.6);font-size:10px;letter-spacing:3px;text-transform:uppercase;margin-bottom:4px;">INVOICE</div>
      <div style="color:#fff;font-size:clamp(18px,4vw,26px);font-weight:900;"><?=h($viewInv['invoice_number'])?></div>
      <div style="display:inline-block;background:<?=$sClr?>;color:#fff;font-size:11px;font-weight:800;padding:4px 14px;border-radius:20px;margin-top:8px;letter-spacing:1px;"><?=strtoupper(h($viewInv['status']))?></div>
    </div>
  </div>
  <!-- Meta strip -->
  <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:14px clamp(16px,4vw,36px);display:flex;flex-wrap:wrap;gap:20px;">
    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Issue Date</div><div style="font-size:13px;font-weight:700;color:#1e293b;margin-top:2px;"><?=$iDateStr?></div></div>
    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Due Date</div><div style="font-size:13px;font-weight:700;color:#1e293b;margin-top:2px;"><?=$dDateStr?></div></div>
    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;">Service Period</div><div style="font-size:13px;font-weight:700;color:#1e293b;margin-top:2px;"><?=$prdStr?></div></div>
  </div>
  <div style="padding:clamp(16px,4vw,28px) clamp(16px,4vw,36px);">
    <!-- Bill To -->
    <div style="margin-bottom:24px;">
      <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px;">Bill To</div>
      <div style="font-size:clamp(14px,3vw,16px);font-weight:800;color:#1e293b;"><?=h($viewInv['client_name'])?></div>
      <?php if(!empty($viewInv['client_address'])): ?><div style="font-size:13px;color:#64748b;margin-top:4px;white-space:pre-line;"><?=h($viewInv['client_address'])?></div><?php endif; ?>
      <?php if(!empty($viewInv['client_email'])): ?><div style="font-size:12px;color:#64748b;"><?=h($viewInv['client_email'])?></div><?php endif; ?>
    </div>
    <!-- Line items - responsive table -->
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:20px;">
      <table style="width:100%;min-width:480px;border-collapse:collapse;">
        <thead>
          <tr style="background:linear-gradient(to right,#0f766e,#0d9488);">
            <th style="padding:10px 12px;text-align:left;color:#fff;font-size:12px;font-weight:700;">Description</th>
            <th style="padding:10px 12px;text-align:center;color:#fff;font-size:12px;font-weight:700;width:70px;">Qty/Hrs</th>
            <th style="padding:10px 12px;text-align:right;color:#fff;font-size:12px;font-weight:700;width:90px;">Rate</th>
            <th style="padding:10px 12px;text-align:right;color:#fff;font-size:12px;font-weight:700;width:100px;">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($viewLines as $i=>$ln):
          $isHdr=($ln['line_type']==='heading');
          $bg=$isHdr?'#f1f5f9':($i%2===0?'#fff':'#f8fafc');
        ?>
        <tr style="background:<?=$bg?>;">
          <?php if($isHdr): ?>
          <td colspan="4" style="padding:9px 12px;font-size:11px;font-weight:800;color:#0f766e;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;"><?=h($ln['description'])?></td>
          <?php else: ?>
          <td style="padding:8px 12px;font-size:13px;color:#374151;border-bottom:1px solid #f1f5f9;"><?=h($ln['description'])?></td>
          <td style="padding:8px 12px;text-align:center;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;"><?=number_format((float)$ln['quantity'],2)?></td>
          <td style="padding:8px 12px;text-align:right;font-size:13px;color:#374151;border-bottom:1px solid #f1f5f9;">&#163;<?=number_format((float)$ln['unit_price'],2)?></td>
          <td style="padding:8px 12px;text-align:right;font-size:13px;font-weight:700;color:#1e293b;border-bottom:1px solid #f1f5f9;">&#163;<?=number_format((float)$ln['line_total'],2)?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Totals -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
      <div style="min-width:min(260px,100%);">
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;"><span style="color:#64748b;">Subtotal</span><span style="font-weight:700;">&#163;<?=number_format((float)$viewInv['subtotal'],2)?></span></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;"><span style="color:#64748b;">VAT (<?=number_format((float)$viewInv['vat_rate'],1)?>%)</span><span style="font-weight:700;">&#163;<?=number_format((float)$viewInv['vat_amount'],2)?></span></div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:linear-gradient(135deg,#0f766e,#0d9488);border-radius:12px;margin-top:8px;">
          <span style="color:#fff;font-size:14px;font-weight:700;">TOTAL DUE</span>
          <span style="color:#fff;font-size:clamp(18px,4vw,22px);font-weight:900;">&#163;<?=number_format((float)$viewInv['total'],2)?></span>
        </div>
      </div>
    </div>
    <!-- Bank details -->
    <?php if(!empty($org['bank_name'])||!empty($org['bank_account'])): ?>
    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:14px;margin-bottom:14px;">
      <div style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Payment Details</div>
      <?php if(!empty($org['bank_name'])): ?><div style="font-size:13px;"><strong>Bank:</strong> <?=h($org['bank_name'])?></div><?php endif; ?>
      <?php if(!empty($org['bank_sort_code'])): ?><div style="font-size:13px;"><strong>Sort Code:</strong> <?=h($org['bank_sort_code'])?></div><?php endif; ?>
      <?php if(!empty($org['bank_account'])): ?><div style="font-size:13px;"><strong>Account No:</strong> <?=h($org['bank_account'])?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if(!empty($viewInv['notes'])): ?>
    <div style="background:#f8fafc;border-radius:10px;padding:12px;margin-bottom:12px;font-size:13px;color:#64748b;"><?=nl2br(h($viewInv['notes']))?></div>
    <?php endif; ?>
    <div style="border-top:1px solid #e2e8f0;padding-top:12px;font-size:11px;color:#94a3b8;text-align:center;">
      Generated by <strong style="color:#0f766e;">Register My Care</strong> &middot; www.registermycare.org &middot; Created and designed by Dr. Andrew Ebhoma
    </div>
  </div>
</div>

<!-- Delete -->
<div class="no-print mt-4 max-w-3xl mx-auto text-center">
  <form method="POST" onsubmit="return confirm('Permanently delete this invoice?')">
    <?=csrfField()?>
    <input type="hidden" name="action" value="delete_invoice">
    <input type="hidden" name="invoice_id" value="<?=$viewInv['id']?>">
    <button type="submit" class="text-xs text-red-400 hover:text-red-600 font-bold"><i class="fa fa-trash mr-1"></i>Delete Invoice</button>
  </form>
</div>

<!-- JS for downloads -->
<script>
var INV = <?php
$jd=array('num'=>h($viewInv['invoice_number']),'client'=>h($viewInv['client_name']),'addr'=>h($viewInv['client_address']??''),'period'=>h($viewInv['service_period']??''),'idate'=>$iDateStr,'ddate'=>$dDateStr,'status'=>h($viewInv['status']),'sub'=>number_format((float)$viewInv['subtotal'],2),'vat'=>number_format((float)$viewInv['vat_amount'],2),'vatR'=>number_format((float)$viewInv['vat_rate'],1),'total'=>number_format((float)$viewInv['total'],2),'org'=>h($org['name']??''),'lines'=>array(),'bank'=>array('name'=>h($org['bank_name']??''),'sort'=>h($org['bank_sort_code']??''),'acc'=>h($org['bank_account']??'')));
foreach($viewLines as $ln) $jd['lines'][]=array('desc'=>h($ln['description']),'qty'=>number_format((float)$ln['quantity'],2),'price'=>'£'.number_format((float)$ln['unit_price'],2),'total'=>'£'.number_format((float)$ln['line_total'],2),'type'=>$ln['line_type']);
echo json_encode($jd);
?>;

// ── PDF via print dialog (most reliable cross-browser) ────────────────
function dlPDF(){
  var w=window.open('','_blank','width=900,height=700');
  w.document.write(buildHtmlDoc(true));
  w.document.close();
  w.onload=function(){ w.focus(); w.print(); };
}

// ── Word .doc ─────────────────────────────────────────────────────────
function dlWord(){
  var html=buildHtmlDoc(false);
  var b=new Blob([html],{type:'application/msword;charset=utf-8'});
  triggerDl(URL.createObjectURL(b), INV.num+'.doc');
}

// ── CSV / Spreadsheet ─────────────────────────────────────────────────
function dlCSV(){
  var rows=[
    ['Register My Care — '+INV.org],
    ['Invoice',INV.num],['Client',INV.client],
    ['Period',INV.period],['Issue Date',INV.idate],['Due Date',INV.ddate],
    ['Status',INV.status],[''],
    ['Description','Qty/Hrs','Unit Rate','Total']
  ];
  INV.lines.forEach(function(l){
    rows.push([l.desc, l.type==='heading'?'':l.qty, l.type==='heading'?'':l.price, l.type==='heading'?'':l.total]);
  });
  rows.push([''],['Subtotal','','','£'+INV.sub],['VAT ('+INV.vatR+'%)','','','£'+INV.vat],
    ['TOTAL DUE','','','£'+INV.total],[''],
    ['Bank: '+INV.bank.name,' Sort: '+INV.bank.sort,' Acc: '+INV.bank.acc,''],
    [''],['Register My Care | www.registermycare.org | Created and designed by Dr. Andrew Ebhoma']);
  var csv=rows.map(function(r){return r.map(function(c){return '"'+(c||'').replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
  var b=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'});
  triggerDl(URL.createObjectURL(b), INV.num+'.csv');
}

function triggerDl(url,name){
  var a=document.createElement('a'); a.href=url; a.download=name;
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  setTimeout(function(){URL.revokeObjectURL(url);},2000);
}

function buildHtmlDoc(forPrint){
  var rows='';
  INV.lines.forEach(function(l){
    if(l.type==='heading'){
      rows+='<tr style="background:#f1f5f9"><td colspan="4" style="padding:9px 12px;font-weight:800;color:#0f766e;font-size:11pt;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0">'+l.desc+'</td></tr>';
    } else {
      rows+='<tr><td style="padding:8px 12px;font-size:10pt;border-bottom:1px solid #f1f5f9">'+l.desc+'</td><td style="text-align:center;padding:8px;font-size:10pt;border-bottom:1px solid #f1f5f9">'+l.qty+'</td><td style="text-align:right;padding:8px;font-size:10pt;border-bottom:1px solid #f1f5f9">'+l.price+'</td><td style="text-align:right;padding:8px;font-weight:700;font-size:10pt;border-bottom:1px solid #f1f5f9">'+l.total+'</td></tr>';
    }
  });
  var bank='';
  if(INV.bank.name||INV.bank.acc){
    bank='<div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:14px;margin-bottom:14px;">'
      +'<div style="font-size:11pt;font-weight:700;color:#0f766e;text-transform:uppercase;margin-bottom:6px;">Payment Details</div>'
      +(INV.bank.name?'<div style="font-size:10pt"><strong>Bank:</strong> '+INV.bank.name+'</div>':'')
      +(INV.bank.sort?'<div style="font-size:10pt"><strong>Sort Code:</strong> '+INV.bank.sort+'</div>':'')
      +(INV.bank.acc?'<div style="font-size:10pt"><strong>Account No:</strong> '+INV.bank.acc+'</div>':'')
      +'</div>';
  }
  var printBtn=forPrint?'<div style="text-align:center;margin:30px 0"><button onclick="window.print()" style="background:#0f766e;color:white;border:none;padding:12px 32px;border-radius:10px;font-size:13pt;cursor:pointer;font-weight:700">&#x1F5B6; Print / Save as PDF</button></div>':'';
  var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    +'<title>'+INV.num+' — '+INV.client+'</title>'
    +'<style>*{box-sizing:border-box}body{font-family:"Plus Jakarta Sans",Calibri,Arial,sans-serif;background:#f8fafc;margin:0;padding:20px}@page{margin:15mm}@media print{body{background:white;padding:0}.no-print{display:none!important}}</style></head><body>';
  html+=printBtn;
  html+='<div style="background:white;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);overflow:hidden;max-width:800px;margin:0 auto;">';
  // Header
  html+='<div style="background:linear-gradient(135deg,#0f766e,#0d9488);padding:28px 32px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">';
  html+='<div><div style="color:#fff;font-size:20pt;font-weight:900">'+INV.org+'</div></div>';
  html+='<div style="text-align:right"><div style="color:rgba(255,255,255,.6);font-size:9pt;letter-spacing:3px;text-transform:uppercase">INVOICE</div><div style="color:#fff;font-size:22pt;font-weight:900">'+INV.num+'</div></div>';
  html+='</div>';
  // Meta
  html+='<div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:14px 32px;display:flex;flex-wrap:wrap;gap:24px;">';
  html+='<div><div style="font-size:9pt;font-weight:700;color:#94a3b8;text-transform:uppercase">Issue Date</div><div style="font-size:12pt;font-weight:700;color:#1e293b">'+INV.idate+'</div></div>';
  html+='<div><div style="font-size:9pt;font-weight:700;color:#94a3b8;text-transform:uppercase">Due Date</div><div style="font-size:12pt;font-weight:700;color:#1e293b">'+INV.ddate+'</div></div>';
  html+='<div><div style="font-size:9pt;font-weight:700;color:#94a3b8;text-transform:uppercase">Service Period</div><div style="font-size:12pt;font-weight:700;color:#1e293b">'+INV.period+'</div></div>';
  html+='</div>';
  // Body
  html+='<div style="padding:24px 32px">';
  html+='<div style="margin-bottom:20px"><div style="font-size:9pt;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px">Bill To</div>';
  html+='<div style="font-size:15pt;font-weight:800;color:#1e293b">'+INV.client+'</div>';
  if(INV.addr) html+='<div style="font-size:10pt;color:#64748b;margin-top:4px;white-space:pre-line">'+INV.addr+'</div>';
  html+='</div>';
  // Table
  html+='<table style="width:100%;border-collapse:collapse;margin-bottom:16px">';
  html+='<thead><tr style="background:linear-gradient(to right,#0f766e,#0d9488)"><th style="padding:10px 12px;text-align:left;color:#fff;font-size:10pt">Description</th><th style="padding:10px 12px;text-align:center;color:#fff;font-size:10pt;width:70px">Qty/Hrs</th><th style="padding:10px 12px;text-align:right;color:#fff;font-size:10pt;width:90px">Rate</th><th style="padding:10px 12px;text-align:right;color:#fff;font-size:10pt;width:100px">Total</th></tr></thead>';
  html+='<tbody>'+rows+'</tbody></table>';
  // Totals
  html+='<div style="display:flex;justify-content:flex-end;margin-bottom:20px">';
  html+='<div style="min-width:240px">';
  html+='<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:11pt"><span style="color:#64748b">Subtotal</span><span style="font-weight:700">£'+INV.sub+'</span></div>';
  html+='<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:11pt"><span style="color:#64748b">VAT ('+INV.vatR+'%)</span><span style="font-weight:700">£'+INV.vat+'</span></div>';
  html+='<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:linear-gradient(135deg,#0f766e,#0d9488);border-radius:10px;margin-top:8px"><span style="color:#fff;font-size:12pt;font-weight:700">TOTAL DUE</span><span style="color:#fff;font-size:18pt;font-weight:900">£'+INV.total+'</span></div>';
  html+='</div></div>';
  html+=bank;
  html+='<div style="border-top:1px solid #e2e8f0;padding-top:12px;font-size:9pt;color:#94a3b8;text-align:center">Generated by <strong style="color:#0f766e">Register My Care</strong> &middot; www.registermycare.org &middot; info@registermycare.org &middot; Created and designed by Dr. Andrew Ebhoma</div>';
  html+='</div></div>';
  if(forPrint) html+=printBtn;
  html+='</body></html>';
  return html;
}
</script>

<?php else: // ════ LIST VIEW ════ ?>

<?php if($flash=getFlash()): ?>
<div class="mb-4 px-4 py-3 rounded-2xl text-sm font-semibold <?=$flash['type']==='success'?'bg-green-50 text-green-800 border border-green-200':'bg-red-50 text-red-800 border border-red-200'?>">
  <i class="fa <?=$flash['type']==='success'?'fa-check-circle text-green-500':'fa-circle-exclamation text-red-500'?> mr-2"></i><?=h($flash['msg'])?>
</div>
<?php endif; ?>

<!-- Stats -->
<?php $tot=0;$totVal=0;$paid=0;$drafts=0; foreach($invoices as $inv){$tot++;$totVal+=(float)$inv['total'];if($inv['status']==='Paid')$paid++;if($inv['status']==='Draft')$drafts++;} ?>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
  <div class="bg-white rounded-2xl shadow p-4 text-center"><div class="text-2xl font-extrabold text-gray-700"><?=$tot?></div><div class="text-xs font-bold text-gray-400 uppercase tracking-wide mt-1">Total</div></div>
  <div class="bg-white rounded-2xl shadow p-4 text-center"><div class="text-xl font-extrabold text-teal-600">&#163;<?=number_format($totVal,2)?></div><div class="text-xs font-bold text-gray-400 uppercase tracking-wide mt-1">Value</div></div>
  <div class="bg-white rounded-2xl shadow p-4 text-center"><div class="text-2xl font-extrabold text-green-600"><?=$paid?></div><div class="text-xs font-bold text-gray-400 uppercase tracking-wide mt-1">Paid</div></div>
  <div class="bg-white rounded-2xl shadow p-4 text-center"><div class="text-2xl font-extrabold text-amber-600"><?=$drafts?></div><div class="text-xs font-bold text-gray-400 uppercase tracking-wide mt-1">Drafts</div></div>
</div>

<!-- Auto-generate panel -->
<div class="bg-white rounded-2xl shadow border-2 border-blue-200 mb-5">
  <button type="button" onclick="panelToggle('autoPanel','autoChev')"
     class="w-full flex items-center gap-3 px-5 py-4 bg-gradient-to-r from-blue-700 to-blue-600 rounded-t-2xl text-left focus:outline-none">
    <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0"><i class="fa fa-bolt text-white text-lg"></i></div>
    <div class="flex-1 min-w-0">
      <div class="text-white font-extrabold">Auto-Generate Invoice from Care Hours</div>
      <div class="text-blue-200 text-xs mt-0.5"><?=$visitCount?> visit<?=$visitCount!==1?'s':''?> in system &middot; Auto-calculates hours per service user</div>
    </div>
    <i id="autoChev" class="fa fa-chevron-down text-white/70 flex-shrink-0 transition-transform duration-200"></i>
  </button>
  <div id="autoPanel" class="hidden">
    <form method="POST" class="p-5">
      <?=csrfField()?>
      <input type="hidden" name="action" value="auto_generate">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div><label class="lbl">Client / Funding Body <span class="text-red-400">*</span></label><input type="text" name="client_name" required placeholder="e.g. NHS Lancashire ICB, Surrey County Council" class="inp"></div>
        <div><label class="lbl">Client Email</label><input type="email" name="client_email" placeholder="accounts@council.gov.uk" class="inp"></div>
        <div><label class="lbl">Period From <span class="text-red-400">*</span></label><input type="date" name="gen_from" value="<?=date('Y-m-01')?>" required class="inp"></div>
        <div><label class="lbl">Period To <span class="text-red-400">*</span></label><input type="date" name="gen_to" value="<?=date('Y-m-d')?>" required class="inp"></div>
        <div class="sm:col-span-2"><label class="lbl">Client Address</label><textarea name="client_address" rows="2" placeholder="Billing address..." class="inp resize-none"></textarea></div>
      </div>
      <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4">
        <p class="text-xs font-extrabold text-blue-700 uppercase tracking-wider mb-3">Billing Rates</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="bg-white border-2 border-blue-300 rounded-xl p-3">
            <label class="block text-xs font-extrabold text-blue-700 mb-1"><i class="fa fa-clock mr-1"></i>Hourly Rate (&#163;) <span class="text-red-400">*</span></label>
            <input type="number" name="hourly_rate" min="0" step="0.01" placeholder="e.g. 22.50" class="w-full border border-blue-200 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:outline-none font-bold">
            <p class="text-xs text-blue-400 mt-1">x actual hours per service user</p>
          </div>
          <div class="bg-white border border-gray-200 rounded-xl p-3">
            <label class="block text-xs font-bold text-gray-500 mb-1">Per Visit Rate (&#163;)</label>
            <input type="number" name="visit_rate" min="0" step="0.01" placeholder="e.g. 30.00" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
            <p class="text-xs text-gray-400 mt-1">Fallback if hourly blank</p>
          </div>
          <div class="bg-white border border-gray-200 rounded-xl p-3">
            <label class="block text-xs font-bold text-gray-500 mb-1">VAT Rate (%)</label>
            <input type="number" name="vat_rate" value="0" min="0" max="100" step="0.1" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
            <p class="text-xs text-gray-400 mt-1">0 = exempt</p>
          </div>
        </div>
      </div>
      <div class="flex flex-wrap gap-3 mb-4">
        <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 hover:bg-blue-50 hover:border-blue-200 transition">
          <input type="checkbox" name="include_meds" value="1" class="w-4 h-4 accent-blue-600"><i class="fa fa-pills text-purple-500"></i> Include Medication Admin
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 hover:bg-blue-50 hover:border-blue-200 transition">
          <input type="checkbox" name="include_extra" value="1" onchange="document.getElementById('extraBlock').style.display=this.checked?'block':'none'" class="w-4 h-4 accent-blue-600"><i class="fa fa-plus-circle text-green-500"></i> Add Extra Lines
        </label>
      </div>
      <div id="extraBlock" style="display:none;" class="mb-4 border border-gray-200 rounded-2xl overflow-hidden">
        <div class="flex items-center justify-between bg-gray-50 px-4 py-2 border-b border-gray-100">
          <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">Additional Items</span>
          <button type="button" onclick="addRow()" class="text-xs text-blue-600 font-bold hover:text-blue-800"><i class="fa fa-plus mr-1"></i>Add Row</button>
        </div>
        <div class="grid grid-cols-12 px-3 py-1.5 text-xs font-bold text-gray-400 border-b border-gray-100 bg-gray-50">
          <div class="col-span-7">Description</div><div class="col-span-2 text-center">Qty</div><div class="col-span-2 text-right">&#163; Rate</div><div class="col-span-1"></div>
        </div>
        <div id="extraRows"></div>
      </div>
      <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-extrabold py-4 rounded-2xl text-sm transition flex items-center justify-center gap-2 shadow-lg shadow-blue-200">
        <i class="fa fa-bolt text-base"></i> Generate Invoice from Care Hours
      </button>
      <p class="text-xs text-center text-gray-400 mt-2">Saved as Draft &mdash; review and update status to Sent when ready to dispatch</p>
    </form>
  </div>
</div>

<!-- Invoice list -->
<div class="bg-white rounded-2xl shadow overflow-hidden">
  <div class="bg-gray-50 border-b border-gray-100 px-5 py-3 flex items-center gap-2">
    <i class="fa fa-file-invoice text-teal-500"></i>
    <h3 class="font-extrabold text-gray-700">All Invoices</h3>
    <span class="ml-1 text-xs bg-teal-100 text-teal-700 font-bold px-2 py-0.5 rounded-full"><?=$tot?></span>
  </div>
  <?php if(empty($invoices)): ?>
  <div class="p-12 text-center"><i class="fa fa-file-invoice text-5xl text-gray-200 block mb-3"></i><p class="text-gray-500 font-semibold">No invoices yet.</p><p class="text-xs text-gray-400 mt-1">Use Auto-Generate above to create your first invoice.</p></div>
  <?php else: ?>
  <div class="divide-y divide-gray-50">
  <?php
  $bdg=array('Draft'=>'bg-gray-100 text-gray-600','Sent'=>'bg-blue-100 text-blue-700','Paid'=>'bg-green-100 text-green-700','Overdue'=>'bg-red-100 text-red-700','Cancelled'=>'bg-gray-100 text-gray-400');
  foreach($invoices as $inv):
    $b=isset($bdg[$inv['status']])?$bdg[$inv['status']]:'bg-gray-100 text-gray-600';
  ?>
  <a href="invoices.php?view=<?=$inv['id']?>" class="flex items-center gap-3 px-5 py-4 hover:bg-teal-50/50 transition group">
    <div class="w-9 h-9 rounded-xl bg-indigo-100 flex items-center justify-center flex-shrink-0"><i class="fa fa-bolt text-indigo-500"></i></div>
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-1.5 flex-wrap">
        <span class="font-extrabold text-gray-800 text-sm"><?=h($inv['invoice_number'])?></span>
        <span class="<?=$b?> text-xs font-bold px-2 py-0.5 rounded-full"><?=h($inv['status'])?></span>
      </div>
      <div class="text-xs text-gray-400 mt-0.5 truncate"><?=h($inv['client_name'])?><?=$inv['service_period']?' &middot; '.h($inv['service_period']):''?></div>
    </div>
    <div class="text-right flex-shrink-0">
      <div class="font-extrabold text-gray-800">&#163;<?=number_format((float)$inv['total'],2)?></div>
      <div class="text-xs text-gray-400"><?=$inv['issue_date']?date('d M Y',strtotime($inv['issue_date'])):''?></div>
    </div>
    <i class="fa fa-chevron-right text-gray-300 group-hover:text-teal-500 flex-shrink-0 transition"></i>
  </a>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>
.lbl{display:block;font-size:11px;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
.inp{display:block;width:100%;border:1px solid #e5e7eb;border-radius:12px;padding:10px 13px;font-size:14px;background:white;transition:border-color .15s;box-sizing:border-box;}
.inp:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.1);outline:none;}
</style>
<script>
function panelToggle(id,ch){
  var e=document.getElementById(id), c=document.getElementById(ch);
  if(!e) return;
  var hidden=e.classList.contains('hidden');
  if(hidden){ e.classList.remove('hidden'); if(c) c.style.transform='rotate(180deg)'; }
  else      { e.classList.add('hidden');    if(c) c.style.transform=''; }
}
var rowN=0;
function addRow(){
  rowN++;
  var d=document.createElement('div');
  d.className='grid grid-cols-12 items-center px-3 py-2 border-b border-gray-50 last:border-0';
  d.innerHTML='<div class="col-span-7 pr-2"><input type="text" name="extra_desc[]" placeholder="Description..." class="w-full border-b border-gray-200 text-sm py-1 bg-transparent focus:border-blue-400 focus:outline-none"></div>'
    +'<div class="col-span-2 px-1"><input type="number" name="extra_qty[]" value="1" min="0" step="0.01" class="w-full border-b border-gray-200 text-sm text-center py-1 bg-transparent focus:outline-none"></div>'
    +'<div class="col-span-2 px-1"><input type="number" name="extra_price[]" min="0" step="0.01" placeholder="0.00" class="w-full border-b border-gray-200 text-sm text-right py-1 bg-transparent focus:outline-none"></div>'
    +'<div class="col-span-1 text-right"><button type="button" onclick="this.closest(\'div\').remove()" class="text-red-400 hover:text-red-600 px-1"><i class="fa fa-times text-xs"></i></button></div>';
  document.getElementById('extraRows').appendChild(d);
}
addRow();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
