<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$pdo    = getPDO();
$orgId  = (int)$_SESSION['organisation_id'];
$pageTitle = 'Reports & Exports';

try { $org=$pdo->prepare("SELECT * FROM organisations WHERE id=?"); $org->execute(array($orgId)); $org=$org->fetch(); } catch(Exception $e){ $org=array('name'=>''); }
$orgName = $org['name'] ?? 'Organisation';
$orgNameU = strtoupper($orgName);

// ── Build report rows (shared for all formats) ────────────────────────
function buildRows($pdo, $orgId, $type, $from, $to) {
    $rows = array(); $title = ''; $headers = array();

    if ($type === 'visits') {
        $title = 'Visits Report';
        $headers = array('Service User','Carer','Date','Sched. Start','Sched. End','Actual Start','Actual End','Status','Notes');
        try {
            $q = $pdo->prepare("SELECT su.first_name sf,su.last_name sl,
                COALESCE(u.first_name,'') uf,COALESCE(u.last_name,'') ul,
                v.visit_date,v.start_time,v.end_time,
                COALESCE(v.actual_start_time,'') astart,COALESCE(v.actual_end_time,'') aend,
                v.status,COALESCE(v.notes,'') notes
                FROM visits v
                JOIN service_users su ON v.service_user_id=su.id
                LEFT JOIN users u ON v.carer_id=u.id
                WHERE v.organisation_id=? AND v.visit_date BETWEEN ? AND ?
                ORDER BY v.visit_date,v.start_time");
            $q->execute(array($orgId,$from,$to));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array($r['sl'].', '.$r['sf'],trim($r['uf'].' '.$r['ul']),
                    date('d/m/Y',strtotime($r['visit_date'])),$r['start_time'],$r['end_time'],
                    $r['astart'],$r['aend'],$r['status'],$r['notes']);
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'service_users') {
        $title = 'Service Users Report';
        $headers = array('Name','NHS No.','Date of Birth','Funding','Address','Emergency Contact','GP');
        try {
            $q=$pdo->prepare("SELECT * FROM service_users WHERE organisation_id=? AND is_active=1 ORDER BY last_name");
            $q->execute(array($orgId));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array($r['last_name'].', '.$r['first_name'],$r['nhs_number']??'',
                    ($r['date_of_birth']?date('d/m/Y',strtotime($r['date_of_birth'])):''),
                    $r['funding_type']??'',$r['address']??'',$r['emergency_contact']??'',$r['gp_details']??'');
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'staff') {
        $title = 'Staff Report';
        $headers = array('Name','Email','Role','Job Category','Phone','NI Number','DBS Update Service','Date Joined');
        try {
            $q=$pdo->prepare("SELECT * FROM users WHERE organisation_id=? AND is_active=1 ORDER BY last_name");
            $q->execute(array($orgId));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array($r['last_name'].', '.$r['first_name'],$r['email']??'',$r['role']??'Staff',
                    $r['job_category']??'',$r['phone']??'',$r['ni_number']??'',
                    isset($r['dbs_on_update'])?($r['dbs_on_update']?'Yes':'No'):'',
                    ($r['created_at']?date('d/m/Y',strtotime($r['created_at'])):''));
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'incidents') {
        $title = 'Incidents Report';
        $headers = array('Date','Reported By','Service User','Category','Severity','Status','Description','Action Taken');
        try {
            $q=$pdo->prepare("SELECT i.*,u.first_name ff,u.last_name fl,
                COALESCE(su.first_name,'') sf,COALESCE(su.last_name,'') sl
                FROM incidents i JOIN users u ON i.reported_by=u.id
                LEFT JOIN service_users su ON i.service_user_id=su.id
                WHERE i.organisation_id=? AND DATE(i.incident_date) BETWEEN ? AND ?
                ORDER BY i.incident_date DESC");
            $q->execute(array($orgId,$from,$to));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array(date('d/m/Y H:i',strtotime($r['incident_date'])),$r['ff'].' '.$r['fl'],
                    trim($r['sf'].' '.$r['sl']),$r['category'],$r['severity'],$r['status'],
                    $r['description'],$r['action_taken']??'');
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'compliance') {
        $title = 'Compliance & Documents Report';
        $headers = array('Staff Name','Job Category','Document','Category','Issue Date','Expiry Date','Status','Days Until Expiry');
        try {
            $q=$pdo->prepare("SELECT u.first_name,u.last_name,u.job_category,
                sd.title,sd.doc_category,sd.issue_date,sd.expiry_date
                FROM staff_documents sd JOIN users u ON sd.staff_id=u.id
                WHERE sd.organisation_id=? ORDER BY u.last_name,sd.doc_category,sd.expiry_date");
            $q->execute(array($orgId));
            foreach ($q->fetchAll() as $r) {
                $status='Valid'; $days='';
                if ($r['expiry_date']) {
                    $diff=(int)round((strtotime($r['expiry_date'])-time())/86400);
                    $days=$diff;
                    if ($diff<0) $status='EXPIRED';
                    elseif ($diff<=30) $status='Expiring Soon';
                }
                $rows[] = array($r['last_name'].', '.$r['first_name'],$r['job_category']??'',
                    $r['title'],$r['doc_category'],
                    ($r['issue_date']?date('d/m/Y',strtotime($r['issue_date'])):''),
                    ($r['expiry_date']?date('d/m/Y',strtotime($r['expiry_date'])):'No Expiry'),
                    $status,$days);
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'mar') {
        $title = 'MAR Chart Report';
        $headers = array('Service User','Medication','Dose','Route','Frequency','Date','Time','Given By','Status','Notes');
        try {
            $q=$pdo->prepare("SELECT su.first_name sf,su.last_name sl,m.name mname,m.dose,m.route,m.frequency,
                me.entry_date,me.entry_time,COALESCE(u.first_name,'') uf,COALESCE(u.last_name,'') ul,
                me.status,COALESCE(me.notes,'') notes
                FROM mar_entries me JOIN medications m ON me.medication_id=m.id
                JOIN service_users su ON me.service_user_id=su.id
                LEFT JOIN users u ON me.recorded_by=u.id
                WHERE me.organisation_id=? AND me.entry_date BETWEEN ? AND ?
                ORDER BY me.entry_date,su.last_name");
            $q->execute(array($orgId,$from,$to));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array($r['sl'].', '.$r['sf'],$r['mname'],$r['dose'],$r['route'],$r['frequency'],
                    date('d/m/Y',strtotime($r['entry_date'])),$r['entry_time']??'',
                    trim($r['uf'].' '.$r['ul']),$r['status'],$r['notes']);
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }
    if ($type === 'invoices') {
        $title = 'Invoices Report';
        $headers = array('Invoice No.','Client','Service Period','Issue Date','Due Date','Status','Subtotal','VAT','Total');
        try {
            $q=$pdo->prepare("SELECT * FROM invoices WHERE organisation_id=? ORDER BY created_at DESC");
            $q->execute(array($orgId));
            foreach ($q->fetchAll() as $r) {
                $rows[] = array($r['invoice_number'],$r['client_name'],$r['service_period']??'',
                    date('d/m/Y',strtotime($r['issue_date'])),
                    ($r['due_date']?date('d/m/Y',strtotime($r['due_date'])):''),
                    $r['status'],'£'.number_format((float)$r['subtotal'],2),
                    '£'.number_format((float)$r['vat_amount'],2),'£'.number_format((float)$r['total'],2));
            }
        } catch(Exception $e){ $rows[] = array('Error: '.$e->getMessage()); }
    }

    return array('title'=>$title,'headers'=>$headers,'rows'=>$rows);
}

// ── Export handler ────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $tok = trim($_GET['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $tok)) die('Invalid security token. Go back and try again.');

    $type   = $_GET['export'] ?? '';
    $fmt    = $_GET['fmt']    ?? 'csv';
    $from   = $_GET['from']   ?? date('Y-m-01');
    $to     = $_GET['to']     ?? date('Y-m-d');

    $data   = buildRows($pdo, $orgId, $type, $from, $to);
    $title  = $data['title'];
    $hdrs   = $data['headers'];
    $rows   = $data['rows'];
    $fnBase = $orgNameU.'_'.$type.'_'.$from.'_'.$to;
    $stamp  = date('d/m/Y H:i');
    $brand  = 'Register My Care | www.registermycare.org | info@registermycare.org | Created and designed by Dr. Andrew Ebhoma';

    // ── CSV ──────────────────────────────────────────────────────────
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fnBase.'.csv"');
        header('Cache-Control: no-cache');
        $out = fopen('php://output','w');
        fputcsv($out, array('ORGANISATION: '.$orgNameU));
        fputcsv($out, array('Report: '.$title));
        fputcsv($out, array('Date Range: '.$from.' to '.$to));
        fputcsv($out, array('Generated: '.$stamp));
        fputcsv($out, array('Generated by: '.$brand));
        fputcsv($out, array(''));
        fputcsv($out, $hdrs);
        foreach ($rows as $row) fputcsv($out, $row);
        fputcsv($out, array(''));
        fputcsv($out, array('--- End of Report ---'));
        fputcsv($out, array($brand));
        fclose($out); exit;
    }

    // ── Word (.doc) ──────────────────────────────────────────────────
    if ($fmt === 'word') {
        header('Content-Type: application/msword; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fnBase.'.doc"');
        header('Cache-Control: no-cache');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">';
        echo '<head><meta charset="UTF-8"><title>'.$title.'</title>';
        echo '<style>
          body{font-family:Calibri,Arial,sans-serif;margin:30px;font-size:11pt;color:#1e293b}
          h1{color:#0f766e;font-size:18pt;margin:0 0 4px}
          h2{color:#0f766e;font-size:13pt;margin:0 0 2px}
          .meta{font-size:9pt;color:#64748b;margin:2px 0}
          table{border-collapse:collapse;width:100%;margin-top:16px}
          th{background:#0f766e;color:white;padding:7px 10px;font-size:9pt;text-align:left;border:1px solid #0d9488}
          td{padding:6px 10px;font-size:9.5pt;border:1px solid #e2e8f0;vertical-align:top}
          tr:nth-child(even) td{background:#f8fafc}
          .footer{border-top:1px solid #e2e8f0;margin-top:24px;padding-top:10px;font-size:8pt;color:#94a3b8;text-align:center}
        </style></head><body>';
        echo '<h1>'.htmlspecialchars($orgNameU).'</h1>';
        echo '<h2>'.htmlspecialchars($title).'</h2>';
        echo '<p class="meta">Date range: '.htmlspecialchars($from).' to '.htmlspecialchars($to).' &nbsp;|&nbsp; Generated: '.$stamp.'</p>';
        echo '<table><thead><tr>';
        foreach ($hdrs as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        if (empty($rows)) { echo '<tr><td colspan="'.count($hdrs).'" style="text-align:center;color:#94a3b8;font-style:italic">No records found for this date range.</td></tr>'; }
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) echo '<td>'.htmlspecialchars($cell).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<div class="footer">'.htmlspecialchars($brand).'</div>';
        echo '</body></html>'; exit;
    }

    // ── PDF (printable HTML) ─────────────────────────────────────────
    if ($fmt === 'pdf') {
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($orgNameU) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Segoe UI,Calibri,Arial,sans-serif;font-size:10pt;color:#1e293b;background:white}
  .page{padding:24px 30px}
  .header{background:linear-gradient(135deg,#0f766e,#115e59);color:white;padding:20px 28px;border-radius:10px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
  .hdr-left h1{font-size:20pt;font-weight:900;letter-spacing:-0.5px}
  .hdr-left h2{font-size:12pt;font-weight:700;opacity:.85;margin-top:4px}
  .hdr-right{text-align:right;font-size:9pt;opacity:.75;line-height:1.7}
  table{width:100%;border-collapse:collapse;margin-top:4px}
  th{background:#0f766e;color:white;padding:7px 10px;font-size:8.5pt;text-align:left;border:1px solid #0d9488;white-space:nowrap}
  td{padding:6px 10px;font-size:9pt;border:1px solid #e2e8f0;vertical-align:top;word-break:break-word}
  tr:nth-child(even) td{background:#f0fdfa}
  .no-data{text-align:center;padding:20px;color:#94a3b8;font-style:italic}
  .footer{margin-top:18px;border-top:1px solid #e2e8f0;padding-top:10px;font-size:7.5pt;color:#94a3b8;text-align:center}
  .print-btn{position:fixed;bottom:20px;right:20px;background:#0f766e;color:white;border:none;padding:12px 20px;border-radius:12px;font-size:12pt;font-weight:700;cursor:pointer;box-shadow:0 4px 20px rgba(15,118,110,.4);z-index:999}
  @media print{.print-btn,.no-print{display:none!important}body{font-size:9pt}.header{border-radius:0}table{page-break-inside:auto}tr{page-break-inside:avoid}}
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div class="hdr-left">
      <h1><?= htmlspecialchars($orgNameU) ?></h1>
      <h2><?= htmlspecialchars($title) ?></h2>
    </div>
    <div class="hdr-right">
      Date range: <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?><br>
      Generated: <?= $stamp ?><br>
      Register My Care
    </div>
  </div>

  <table>
    <thead>
      <tr><?php foreach ($hdrs as $h) echo '<th>'.htmlspecialchars($h).'</th>'; ?></tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
      <tr><td colspan="<?= count($hdrs) ?>" class="no-data">No records found for this date range.</td></tr>
      <?php else: foreach ($rows as $row): ?>
      <tr><?php foreach ($row as $cell) echo '<td>'.htmlspecialchars($cell).'</td>'; ?></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="footer"><?= htmlspecialchars($brand) ?></div>
</div>
<button class="print-btn" onclick="window.print()">🖨️ Print / Save PDF</button>
<script>
  // Auto-trigger print dialog after short delay so page renders
  setTimeout(function(){ window.print(); }, 600);
</script>
</body></html>
<?php exit;
    }
}

// ── Page render ───────────────────────────────────────────────────────
// Live stats
$stats = array('sus'=>0,'staff'=>0,'visits_today'=>0,'open_incidents'=>0,'expired_docs'=>0,'expiring_docs'=>0);
$statQ = array(
    'sus'           => "SELECT COUNT(*) FROM service_users WHERE organisation_id=? AND is_active=1",
    'staff'         => "SELECT COUNT(*) FROM users WHERE organisation_id=? AND is_active=1",
    'visits_today'  => "SELECT COUNT(*) FROM visits WHERE organisation_id=? AND visit_date=CURDATE()",
    'open_incidents'=> "SELECT COUNT(*) FROM incidents WHERE organisation_id=? AND LOWER(status)='open'",
    'expired_docs'  => "SELECT COUNT(*) FROM staff_documents WHERE organisation_id=? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()",
    'expiring_docs' => "SELECT COUNT(*) FROM staff_documents WHERE organisation_id=? AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)",
);
foreach ($statQ as $key => $sql) { try { $q=$pdo->prepare($sql); $q->execute(array($orgId)); $stats[$key]=(int)$q->fetchColumn(); } catch(Exception $e){} }

$token = h(csrfToken());

include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto space-y-5">

<!-- ── Live Stats ─────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
<?php
$statCards = array(
    array('Service Users',  $stats['sus'],             'fa-user-nurse',           'teal'),
    array('Staff',          $stats['staff'],            'fa-users',                'blue'),
    array('Visits Today',   $stats['visits_today'],    'fa-route',                'purple'),
    array('Open Incidents', $stats['open_incidents'],  'fa-triangle-exclamation', 'amber'),
    array('Expired Docs',   $stats['expired_docs'],    'fa-file-circle-xmark',    'red'),
    array('Expiring ≤30d',  $stats['expiring_docs'],   'fa-clock',                'orange'),
);
$cc = array(
    'teal'  =>array('bg-teal-50',  'border-teal-200',  'text-teal-700',  'text-teal-600',  'text-teal-400'),
    'blue'  =>array('bg-blue-50',  'border-blue-200',  'text-blue-700',  'text-blue-600',  'text-blue-400'),
    'purple'=>array('bg-purple-50','border-purple-200','text-purple-700','text-purple-600','text-purple-400'),
    'amber' =>array('bg-amber-50', 'border-amber-200', 'text-amber-700', 'text-amber-600', 'text-amber-400'),
    'red'   =>array('bg-red-50',   'border-red-200',   'text-red-700',   'text-red-600',   'text-red-400'),
    'orange'=>array('bg-orange-50','border-orange-200','text-orange-700','text-orange-600','text-orange-400'),
);
foreach ($statCards as $s):
    $c = $cc[$s[3]]; ?>
<div class="<?= $c[0] ?> border <?= $c[1] ?> rounded-2xl p-3 text-center">
  <i class="fa <?= $s[2] ?> <?= $c[4] ?> text-xl mb-1 block"></i>
  <div class="text-2xl font-extrabold <?= $c[3] ?>"><?= $s[1] ?></div>
  <div class="text-[10px] font-bold <?= $c[2] ?> uppercase tracking-wider leading-tight mt-0.5"><?= $s[0] ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Date Range ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-4">
  <div class="flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Date From</label>
      <input type="date" id="from" value="<?= date('Y-m-01') ?>"
             class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100 focus:outline-none transition">
    </div>
    <div>
      <label class="block text-xs font-bold text-gray-500 mb-1.5 uppercase tracking-wider">Date To</label>
      <input type="date" id="to" value="<?= date('Y-m-d') ?>"
             class="border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100 focus:outline-none transition">
    </div>
    <div class="flex gap-2 pb-0.5">
      <button onclick="setRange('month')"     class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold transition">This Month</button>
      <button onclick="setRange('lastmonth')" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold transition">Last Month</button>
      <button onclick="setRange('year')"      class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold transition">This Year</button>
    </div>
  </div>
</div>

<!-- ── Export Grid ────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
  <div class="bg-gradient-to-r from-teal-700 to-teal-600 px-5 py-4">
    <h3 class="text-white font-extrabold text-base"><i class="fa fa-download mr-2"></i>Download Reports</h3>
    <p class="text-teal-200 text-xs mt-0.5">Choose a report and download format — all files include your organisation header and RMC branding</p>
  </div>

  <div class="p-5 space-y-3">
  <?php
  $exports = array(
    array('visits',        'Visits Report',            'fa-route',                'teal',   'All visits with carers, times and status for the selected period'),
    array('service_users', 'Service Users',            'fa-user-nurse',           'purple', 'All active service users — NHS numbers, funding, emergency contacts'),
    array('staff',         'Staff List',               'fa-users',                'blue',   'Full team list — DBS status, NI numbers, roles, job categories'),
    array('incidents',     'Incidents Report',         'fa-triangle-exclamation', 'red',    'All incidents with severity, status, description and actions taken'),
    array('compliance',    'Compliance & Documents',   'fa-shield-halved',        'green',  'Staff documents with issue/expiry dates and current status'),
    array('mar',           'MAR Chart',                'fa-pills',                'amber',  'Medication administration records — doses, times, given by'),
    array('invoices',      'Invoices',                 'fa-file-invoice-dollar',  'indigo', 'All invoices — clients, amounts, VAT, status summary'),
  );
  $bc = array(
    'teal'  =>array('bg-teal-50',  'border-teal-200',  'text-teal-800',  'text-teal-600',  'text-teal-500'),
    'purple'=>array('bg-purple-50','border-purple-200','text-purple-800','text-purple-600','text-purple-500'),
    'blue'  =>array('bg-blue-50',  'border-blue-200',  'text-blue-800',  'text-blue-600',  'text-blue-500'),
    'red'   =>array('bg-red-50',   'border-red-200',   'text-red-800',   'text-red-600',   'text-red-500'),
    'green' =>array('bg-green-50', 'border-green-200', 'text-green-800', 'text-green-600', 'text-green-500'),
    'amber' =>array('bg-amber-50', 'border-amber-200', 'text-amber-800', 'text-amber-600', 'text-amber-500'),
    'indigo'=>array('bg-indigo-50','border-indigo-200','text-indigo-800','text-indigo-600','text-indigo-500'),
  );
  foreach ($exports as $ex):
    $c = $bc[$ex[3]];
  ?>
  <div class="border <?= $c[1] ?> rounded-2xl overflow-hidden">
    <div class="<?= $c[0] ?> px-4 py-3 flex items-center gap-3">
      <div class="w-9 h-9 bg-white rounded-xl shadow-sm flex items-center justify-center flex-shrink-0">
        <i class="fa <?= $ex[2] ?> <?= $c[4] ?> text-base"></i>
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-extrabold <?= $c[2] ?> text-sm"><?= $ex[1] ?></div>
        <div class="text-xs <?= $c[3] ?> mt-0.5"><?= $ex[4] ?></div>
      </div>
    </div>
    <!-- Download format buttons -->
    <div class="bg-white border-t <?= $c[1] ?> px-4 py-3 flex flex-wrap gap-2 items-center">
      <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Download as:</span>
      <button onclick="doExport('<?= $ex[0] ?>','csv')"
        class="flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl transition shadow-sm">
        <i class="fa fa-file-csv text-xs"></i> CSV
      </button>
      <button onclick="doExport('<?= $ex[0] ?>','pdf')"
        class="flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl transition shadow-sm">
        <i class="fa fa-file-pdf text-xs"></i> PDF / Print
      </button>
      <button onclick="doExport('<?= $ex[0] ?>','word')"
        class="flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3.5 py-2 rounded-xl transition shadow-sm">
        <i class="fa fa-file-word text-xs"></i> Word
      </button>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<!-- Branding note -->
<div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 text-xs text-gray-500 text-center">
  <i class="fa fa-circle-info text-gray-400 mr-1"></i>
  Every exported file is branded with <strong class="text-gray-700"><?= h($orgName) ?></strong> as header.<br>
  Footer on all documents: <em class="text-teal-700">"Document enabled by Register My Care · info@registermycare.org · www.registermycare.org · Created and designed by Dr. Andrew Ebhoma"</em>
</div>

</div>

<script>
function doExport(type, fmt) {
  var from = document.getElementById('from').value;
  var to   = document.getElementById('to').value;
  if (!from || !to) { alert('Please select a date range first.'); return; }
  var url = '?export='+type+'&fmt='+fmt+'&from='+from+'&to='+to+'&csrf_token=<?= $token ?>';
  if (fmt === 'pdf') {
    window.open(url, '_blank'); // Open PDF in new tab for print
  } else {
    window.location.href = url; // Download file directly
  }
}
function setRange(p){
  var n=new Date(), f, t;
  if(p==='month'){ f=new Date(n.getFullYear(),n.getMonth(),1); t=n; }
  else if(p==='lastmonth'){ f=new Date(n.getFullYear(),n.getMonth()-1,1); t=new Date(n.getFullYear(),n.getMonth(),0); }
  else{ f=new Date(n.getFullYear(),0,1); t=n; }
  document.getElementById('from').value=f.toISOString().slice(0,10);
  document.getElementById('to').value=t.toISOString().slice(0,10);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
