<?php
// modules/reports/export.php — CSV Export for all report types
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user = requireLogin();
if (!in_array($user['role_name'], ['admin', 'warden'])) { redirect('index.php'); }

$report = $_GET['report'] ?? '';
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');
$db     = getDB();

$rows    = [];
$headers = [];
$filename = "report_{$report}_" . date('Ymd') . '.csv';

switch ($report) {

    case 'members':
        $headers = ['ID','Full Name','Email','Phone','Role','Membership','Community Points','Karma Points','Status','Joined'];
        $stmt = $db->query("SELECT u.id, u.full_name, u.email, u.phone, r.name, u.membership_status, u.community_points, u.karma_points, IF(u.is_active,'Active','Inactive'), u.created_at FROM users u JOIN roles r ON u.role_id=r.id ORDER BY r.id, u.full_name");
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'leases':
        $headers = ['Plot','Leaseholder','Email','Area m²','Start','End','Base Fee £','Total Fee £','Status'];
        $stmt = $db->prepare("SELECT p.plot_code, u.full_name, u.email, p.area_sqm, l.start_date, l.end_date, l.base_fee, l.total_fee, l.status FROM leases l JOIN plots p ON l.plot_id=p.id JOIN users u ON l.user_id=u.id WHERE l.start_date<=? AND l.end_date>=? ORDER BY l.end_date");
        $stmt->execute([$to, $from]);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'billing':
        $headers = ['Date','Member','Email','Plot','Amount £','Method','Status','Notes'];
        $stmt = $db->prepare("SELECT bt.payment_date, u.full_name, u.email, p.plot_code, bt.amount, bt.payment_method, bt.status, bt.notes FROM billing_transactions bt JOIN users u ON bt.user_id=u.id JOIN leases l ON bt.lease_id=l.id JOIN plots p ON l.plot_id=p.id WHERE bt.payment_date BETWEEN ? AND ? ORDER BY bt.payment_date DESC");
        $stmt->execute([$from.' 00:00:00', $to.' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'tools':
        $headers = ['Tool','Status','Usage Hours','Threshold Hours','Total Reservations','Completed','Overdue','Needs Maintenance'];
        $stmt = $db->query("SELECT t.name, t.status, t.total_usage_hours, t.maintenance_threshold_hours, COUNT(r.id), SUM(CASE WHEN r.status='completed' THEN 1 ELSE 0 END), SUM(CASE WHEN r.status='overdue' THEN 1 ELSE 0 END), IF(t.needs_maintenance,'Yes','No') FROM tools t LEFT JOIN tool_reservations r ON t.id=r.tool_id GROUP BY t.id ORDER BY t.total_usage_hours DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'incidents':
        $headers = ['Date','Title','Location','Severity','Reported By','Status','Resolved By','Resolved At'];
        $stmt = $db->prepare("SELECT i.reported_at, i.title, i.location, i.severity, u.full_name, i.status, u2.full_name, i.resolved_at FROM incidents i JOIN users u ON i.reported_by=u.id LEFT JOIN users u2 ON i.resolved_by=u2.id WHERE i.reported_at BETWEEN ? AND ? ORDER BY i.reported_at DESC");
        $stmt->execute([$from.' 00:00:00', $to.' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'volunteers':
        $headers = ['Member','Email','Total Hours','Entries','Status vs Requirement'];
        $stmt = $db->prepare("SELECT u.full_name, u.email, SUM(sh.hours_logged), COUNT(sh.id), IF(SUM(sh.hours_logged)>=?,'Met','Short') FROM service_hours sh JOIN users u ON sh.user_id=u.id WHERE sh.logged_at BETWEEN ? AND ? AND sh.status='approved' GROUP BY u.id ORDER BY SUM(sh.hours_logged) DESC");
        $stmt->execute([MONTHLY_SERVICE_HOURS, $from.' 00:00:00', $to.' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    case 'waitlist':
        $headers = ['Position','Member','Email','Priority Score','Community Points','Residency Months','Status','Joined'];
        $stmt = $db->query("SELECT u.full_name, u.email, w.priority_score, u.community_points, u.residency_months, w.status, w.joined_at FROM waitlist w JOIN users u ON w.user_id=u.id ORDER BY w.priority_score DESC");
        $all = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($all as $i => $r) array_unshift($r, $i + 1); // prepend position
        $rows = $all;
        break;

    case 'audit':
        $headers = ['Time','User','Action','Module','Target Table','Target ID','Description','IP'];
        $stmt = $db->prepare("SELECT al.logged_at, COALESCE(u.full_name,'System'), al.action_type, al.module, al.target_table, al.target_id, al.description, al.ip_address FROM audit_log al LEFT JOIN users u ON al.user_id=u.id WHERE al.logged_at BETWEEN ? AND ? ORDER BY al.logged_at DESC LIMIT 5000");
        $stmt->execute([$from.' 00:00:00', $to.' 23:59:59']);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        break;

    default:
        redirect('modules/reports/index.php');
}

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

$out = fopen('php://output', 'w');
// BOM for Excel UTF-8
fwrite($out, "\xEF\xBB\xBF");

// Title row
fputcsv($out, [APP_NAME . ' — ' . ucfirst($report) . ' Report', 'Period: ' . $from . ' to ' . $to, 'Generated: ' . date('d M Y H:i')]);
fputcsv($out, []);

// Headers
fputcsv($out, $headers);

// Data
foreach ($rows as $row) {
    fputcsv($out, array_map(fn($v) => $v ?? '', $row));
}

fputcsv($out, []);
fputcsv($out, ['Total rows: ' . count($rows)]);
fclose($out);
exit;
