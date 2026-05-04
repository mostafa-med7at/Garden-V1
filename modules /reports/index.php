<?php
// modules/reports/index.php — Report Generation Module (e): PDF & on-screen reports
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user = requireLogin();
if (!in_array($user['role_name'], ['admin', 'warden'])) { redirect('index.php'); }
$pageTitle = 'Reports';
$db = getDB();

$report = $_GET['report'] ?? '';
$from   = $_GET['from'] ?? date('Y-m-01');
$to     = $_GET['to']   ?? date('Y-m-d');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📊 Reports</h1>
    <p>Generate and print reports for leases, members, tools, and finances.</p>
</div>

<!-- Report Selector -->
<div class="card mb-2">
    <div class="card-body" style="padding:.75rem 1rem">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label class="small fw-semibold">Report Type</label>
                <select name="report" id="report-type" class="form-control" required>
                    <option value="">— Select a report —</option>
                    <option value="members"     <?= $report==='members'     ?'selected':''?>>👥 Members List</option>
                    <option value="leases"      <?= $report==='leases'      ?'selected':''?>>📋 Active Leases</option>
                    <option value="billing"     <?= $report==='billing'     ?'selected':''?>>💰 Billing Summary</option>
                    <option value="tools"       <?= $report==='tools'       ?'selected':''?>>🔧 Tool Utilisation</option>
                    <option value="incidents"   <?= $report==='incidents'   ?'selected':''?>>⚠️ Incidents Log</option>
                    <option value="volunteers"  <?= $report==='volunteers'  ?'selected':''?>>🙋 Volunteer Hours</option>
                    <option value="waitlist"    <?= $report==='waitlist'    ?'selected':''?>>⏳ Waitlist</option>
                    <option value="audit"       <?= $report==='audit'       ?'selected':''?>>🔍 Audit Trail</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="small fw-semibold">From</label>
                <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="small fw-semibold">To</label>
                <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="height:38px">📊 Generate</button>
            <?php if ($report): ?>
                <button type="button" class="btn btn-secondary" style="height:38px" onclick="printReport()">🖨️ Print / PDF</button>
                <a href="export.php?report=<?= urlencode($report) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn btn-secondary" style="height:38px">📥 Export CSV</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$report): ?>
<!-- Report Cards Overview -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-top:1rem">
    <?php
    $cards = [
        ['members',    '👥', 'Members List',       'All registered users and their roles'],
        ['leases',     '📋', 'Active Leases',       'Current plot rentals and expiry dates'],
        ['billing',    '💰', 'Billing Summary',     'Payments, outstanding fees, totals'],
        ['tools',      '🔧', 'Tool Utilisation',    'Reservations, damage, maintenance needs'],
        ['incidents',  '⚠️', 'Incidents Log',       'Reported incidents and resolution status'],
        ['volunteers', '🙋', 'Volunteer Hours',      'Member service hours this period'],
        ['waitlist',   '⏳', 'Waitlist',             'Members waiting for a plot'],
        ['audit',      '🔍', 'Audit Trail',          'System-wide action log'],
    ];
    foreach ($cards as [$key, $icon, $title, $desc]):
    ?>
    <a href="?report=<?= $key ?>" style="text-decoration:none">
        <div class="card" style="cursor:pointer;transition:transform .15s,box-shadow .15s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div class="card-body" style="text-align:center;padding:1.5rem 1rem">
                <div style="font-size:2rem;margin-bottom:.5rem"><?= $icon ?></div>
                <strong><?= $title ?></strong>
                <p class="text-sm text-muted" style="margin-top:.35rem"><?= $desc ?></p>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php else: ?>

<!-- ═══════════════ REPORT OUTPUT ═══════════════ -->
<div id="report-output" class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><?php
            $titles = ['members'=>'👥 Members List','leases'=>'📋 Active Leases','billing'=>'💰 Billing Summary','tools'=>'🔧 Tool Utilisation','incidents'=>'⚠️ Incidents Log','volunteers'=>'🙋 Volunteer Hours','waitlist'=>'⏳ Waitlist','audit'=>'🔍 Audit Trail'];
            echo $titles[$report] ?? 'Report';
        ?></span>
        <span class="text-sm text-muted">Period: <?= e($from) ?> → <?= e($to) ?></span>
    </div>

    <?php if ($report === 'members'): ?>
        <?php
        $rows = $db->query("SELECT u.id, u.full_name, u.email, u.phone, r.name AS role_name, u.membership_status, u.community_points, u.karma_points, u.is_active, u.created_at FROM users u JOIN roles r ON u.role_id=r.id ORDER BY r.id, u.full_name")->fetchAll();
        ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Membership</th><th>Points</th><th>Status</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= e($r['phone'] ?: '—') ?></td>
                <td><span class="badge badge-info"><?= e($r['role_name']) ?></span></td>
                <td><?= e($r['membership_status']) ?></td>
                <td>🏅<?= $r['community_points'] ?> / ⭐<?= $r['karma_points'] ?></td>
                <td><?= $r['is_active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>' ?></td>
                <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="9"><strong>Total: <?= count($rows) ?> users</strong></td></tr></tfoot>
        </table>
        </div>

    <?php elseif ($report === 'leases'): ?>
        <?php
        $rows = $db->prepare("SELECT l.*, p.plot_code, p.area_sqm, u.full_name, u.email FROM leases l JOIN plots p ON l.plot_id=p.id JOIN users u ON l.user_id=u.id WHERE l.start_date <= ? AND l.end_date >= ? ORDER BY l.end_date");
        $rows->execute([$to, $from]); $rows = $rows->fetchAll();
        $totalFees = array_sum(array_column($rows, 'total_fee'));
        ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Plot</th><th>Leaseholder</th><th>Email</th><th>Area m²</th><th>Start</th><th>End</th><th>Fee £</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $days = (int)((strtotime($r['end_date'])-time())/86400); ?>
            <tr>
                <td><strong><?= e($r['plot_code']) ?></strong></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= $r['area_sqm'] ?></td>
                <td><?= $r['start_date'] ?></td>
                <td><?= $r['end_date'] ?> <?= $days < 30 ? '<span class="badge badge-warning">expiring soon</span>' : '' ?></td>
                <td>£<?= number_format($r['total_fee'],2) ?></td>
                <td><span class="badge badge-<?= ['active'=>'success','expired'=>'danger','terminated'=>'secondary','grace_period'=>'warning'][$r['status']]??'info' ?>"><?= $r['status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="6"><strong>Total Leases: <?= count($rows) ?></strong></td><td colspan="2"><strong>Total Fees: £<?= number_format($totalFees,2) ?></strong></td></tr></tfoot>
        </table>
        </div>

    <?php elseif ($report === 'billing'): ?>
        <?php
        $rows = $db->prepare("SELECT bt.*, u.full_name, u.email, p.plot_code FROM billing_transactions bt JOIN users u ON bt.user_id=u.id JOIN leases l ON bt.lease_id=l.id JOIN plots p ON l.plot_id=p.id WHERE bt.payment_date BETWEEN ? AND ? ORDER BY bt.payment_date DESC");
        $rows->execute([$from.' 00:00:00', $to.' 23:59:59']); $rows = $rows->fetchAll();
        $paid = array_sum(array_map(fn($r)=> $r['status']==='paid' ? $r['amount'] : 0, $rows));
        $pend = array_sum(array_map(fn($r)=> $r['status']==='pending' ? $r['amount'] : 0, $rows));
        ?>
        <div style="display:flex;gap:1rem;padding:1rem;flex-wrap:wrap">
            <div class="stat-card"><span class="stat-value">£<?= number_format($paid,2) ?></span><span class="stat-label">Collected</span></div>
            <div class="stat-card accent-amber"><span class="stat-value">£<?= number_format($pend,2) ?></span><span class="stat-label">Pending</span></div>
            <div class="stat-card accent-coral"><span class="stat-value"><?= count($rows) ?></span><span class="stat-label">Transactions</span></div>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Member</th><th>Plot</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['plot_code']) ?></td>
                <td>£<?= number_format($r['amount'],2) ?></td>
                <td><?= e($r['payment_method']) ?></td>
                <td><span class="badge badge-<?= ['paid'=>'success','pending'=>'warning','overdue'=>'danger','refunded'=>'info'][$r['status']]??'secondary' ?>"><?= $r['status'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php elseif ($report === 'tools'): ?>
        <?php $rows = $db->query("SELECT t.*, COUNT(r.id) AS total_res, SUM(CASE WHEN r.status='completed' THEN 1 ELSE 0 END) AS completed_res, SUM(CASE WHEN r.status='overdue' THEN 1 ELSE 0 END) AS overdue_res FROM tools t LEFT JOIN tool_reservations r ON t.id=r.tool_id GROUP BY t.id ORDER BY t.total_usage_hours DESC")->fetchAll(); ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Tool</th><th>Status</th><th>Usage hrs</th><th>Total Reservations</th><th>Completed</th><th>Overdue</th><th>Maintenance</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><span class="badge badge-<?= ['available'=>'success','checked_out'=>'warning','in_repair'=>'info','decommissioned'=>'secondary','missing'=>'danger'][$r['status']]??'secondary' ?>"><?= e($r['status']) ?></span></td>
                <td><?= number_format($r['total_usage_hours'],1) ?> / <?= number_format($r['maintenance_threshold_hours'],0) ?>h</td>
                <td><?= $r['total_res'] ?></td>
                <td><?= $r['completed_res'] ?></td>
                <td><?= $r['overdue_res'] ? '<span class="badge badge-danger">'.$r['overdue_res'].'</span>' : '0' ?></td>
                <td><?= $r['needs_maintenance'] ? '<span class="badge badge-danger">⚠️ Due</span>' : '<span class="badge badge-success">OK</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php elseif ($report === 'incidents'): ?>
        <?php
        $rows = $db->prepare("SELECT i.*, u.full_name AS reporter, u2.full_name AS resolver FROM incidents i JOIN users u ON i.reported_by=u.id LEFT JOIN users u2 ON i.resolved_by=u2.id WHERE i.reported_at BETWEEN ? AND ? ORDER BY i.reported_at DESC");
        $rows->execute([$from.' 00:00:00', $to.' 23:59:59']); $rows = $rows->fetchAll();
        ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Title</th><th>Location</th><th>Severity</th><th>Reported By</th><th>Status</th><th>Resolved By</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= date('d M Y', strtotime($r['reported_at'])) ?></td>
                <td><?= e($r['title']) ?></td>
                <td><?= e($r['location'] ?: '—') ?></td>
                <td><span class="badge badge-<?= ['low'=>'info','medium'=>'warning','high'=>'danger','critical'=>'danger'][$r['severity']] ?>"><?= $r['severity'] ?></span></td>
                <td><?= e($r['reporter']) ?></td>
                <td><span class="badge badge-<?= ['open'=>'danger','in_process'=>'warning','resolved'=>'success'][$r['status']] ?>"><?= $r['status'] ?></span></td>
                <td><?= e($r['resolver'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="7"><strong>Total: <?= count($rows) ?> incidents</strong></td></tr></tfoot>
        </table>
        </div>

    <?php elseif ($report === 'volunteers'): ?>
        <?php
        $rows = $db->prepare("SELECT u.full_name, u.email, SUM(sh.hours_logged) AS total_hours, COUNT(sh.id) AS entries, GROUP_CONCAT(DISTINCT sh.month_year ORDER BY sh.month_year SEPARATOR ', ') AS months FROM service_hours sh JOIN users u ON sh.user_id=u.id WHERE sh.logged_at BETWEEN ? AND ? AND sh.status='approved' GROUP BY u.id ORDER BY total_hours DESC");
        $rows->execute([$from.' 00:00:00', $to.' 23:59:59']); $rows = $rows->fetchAll();
        $required = MONTHLY_SERVICE_HOURS;
        ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Member</th><th>Email</th><th>Total Hours</th><th>Entries</th><th>Months Active</th><th>vs Requirement</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $met = $r['total_hours'] >= $required; ?>
            <tr>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><strong><?= number_format($r['total_hours'],2) ?>h</strong></td>
                <td><?= $r['entries'] ?></td>
                <td class="text-sm"><?= e($r['months']) ?></td>
                <td><?= $met ? '<span class="badge badge-success">✅ Met</span>' : '<span class="badge badge-danger">❌ Short</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php elseif ($report === 'waitlist'): ?>
        <?php $rows = $db->query("SELECT w.*, u.full_name, u.email, u.community_points, u.residency_months FROM waitlist w JOIN users u ON w.user_id=u.id ORDER BY w.priority_score DESC")->fetchAll(); ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Position</th><th>Member</th><th>Email</th><th>Priority Score</th><th>Community Pts</th><th>Residency (mo)</th><th>Status</th><th>Joined</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><strong>#<?= $i+1 ?></strong></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><strong><?= number_format($r['priority_score'],2) ?></strong></td>
                <td><?= $r['community_points'] ?></td>
                <td><?= $r['residency_months'] ?></td>
                <td><span class="badge badge-<?= ['waiting'=>'warning','notified'=>'info','accepted'=>'success','declined'=>'danger'][$r['status']]??'secondary' ?>"><?= $r['status'] ?></span></td>
                <td><?= date('d M Y', strtotime($r['joined_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php elseif ($report === 'audit'): ?>
        <?php
        $rows = $db->prepare("SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON al.user_id=u.id WHERE al.logged_at BETWEEN ? AND ? ORDER BY al.logged_at DESC LIMIT 500");
        $rows->execute([$from.' 00:00:00', $to.' 23:59:59']); $rows = $rows->fetchAll();
        ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-sm"><?= date('d M Y H:i', strtotime($r['logged_at'])) ?></td>
                <td><?= e($r['full_name'] ?: 'System') ?></td>
                <td><code><?= e($r['action_type']) ?></code></td>
                <td><span class="badge badge-info"><?= e($r['module'] ?: '—') ?></span></td>
                <td class="text-sm"><?= e(substr($r['description'] ?: '', 0, 80)) ?></td>
                <td class="text-sm text-muted"><?= e($r['ip_address'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="6"><strong><?= count($rows) ?> entries (max 500)</strong></td></tr></tfoot>
        </table>
        </div>
    <?php endif; ?>

</div><!-- /report-output -->
<?php endif; ?>

<style>
@media print {
    .page-header p, form, .page-actions, nav, .sidebar { display:none!important; }
    #report-output { box-shadow:none; border:none; }
    .btn { display:none!important; }
}
</style>

<script>
function printReport() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
