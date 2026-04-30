<?php
// modules/volunteer/broadcast.php — Fn 20: Emergency Broadcaster
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Emergency Broadcast';
$db        = getDB();
if ($user['role_name'] !== 'admin') { setFlash('danger','Admin only.'); redirect('index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['broadcast'])) {
    $title       = trim($_POST['title']);
    $message     = trim($_POST['message']);
    $siteStatus  = $_POST['site_status'];
    $affectedRaw = trim($_POST['affected_plots'] ?? '');
    $affected    = $affectedRaw ? json_encode(array_map('trim', explode(',', $affectedRaw))) : null;

    if ($_POST['is_false_alarm'] ?? '' === '1') {
        // Log as false alarm, don't send
        $db->prepare("INSERT INTO broadcasts (admin_id,title,message,site_status,is_false_alarm) VALUES (?,?,?,?,1)")
           ->execute([$user['id'],$title,'FALSE ALARM: '.$message,$siteStatus]);
        auditLog('broadcast_false_alarm','volunteer','broadcasts',(int)$db->lastInsertId(),'Cancelled before send');
        setFlash('info','Marked as false alarm. No members notified.');
    } else {
        $db->prepare("INSERT INTO broadcasts (admin_id,title,message,affected_plots,site_status) VALUES (?,?,?,?,?)")
           ->execute([$user['id'],$title,$message,$affected,$siteStatus]);
        $broadId = (int)$db->lastInsertId();
        // Update affected plot statuses
        if ($siteStatus === 'closed' || $siteStatus === 'emergency') {
            if ($affectedRaw) {
                $codes = array_map('trim', explode(',', $affectedRaw));
                foreach ($codes as $code) {
                    $db->prepare("UPDATE plots SET status='maintenance' WHERE plot_code=?")->execute([$code]);
                }
            }
        }
        auditLog('broadcast_sent','volunteer','broadcasts',$broadId,"Status: $siteStatus");
        // In production: loop users and send email. Here we log it.
        $memberCount = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
        setFlash('success',"Broadcast sent to $memberCount members. Site status set to: $siteStatus");
    }
    header('Location: broadcast.php'); exit;
}

$broadcasts = $db->query("SELECT b.*, u.full_name FROM broadcasts b JOIN users u ON b.admin_id=u.id ORDER BY b.sent_at DESC LIMIT 20")->fetchAll();
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>📢 Emergency Site Broadcaster</h1>
    <p>Send immediate alerts to all garden members. Use responsibly.</p>
</div>

<div class="card mb-2" style="border-color:var(--coral)">
    <div class="card-header" style="background:#faece7">Send Broadcast</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Alert title</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Water Main Burst">
                </div>
                <div class="form-group">
                    <label>Site status</label>
                    <select name="site_status" class="form-control">
                        <option value="warning">⚠️ Warning</option>
                        <option value="emergency">🚨 Emergency</option>
                        <option value="closed">🔒 Site Closed</option>
                        <option value="normal">✅ Back to Normal</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea name="message" class="form-control" rows="3" required placeholder="Describe the situation and any instructions..."></textarea>
            </div>
            <div class="form-group">
                <label>Affected plots (comma-separated codes, optional)</label>
                <input type="text" name="affected_plots" class="form-control" placeholder="e.g. A-01, A-02, B-01">
            </div>
            <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                <button name="broadcast" value="1" class="btn btn-danger">🚨 Send to All Members</button>
                <label style="display:flex;align-items:center;gap:.5rem;font-weight:normal;cursor:pointer">
                    <input type="checkbox" name="is_false_alarm" value="1">
                    This was a false alarm — log but don't send
                </label>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Broadcast History</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Title</th><th>Status</th><th>Message</th><th>Sent by</th><th>False alarm?</th></tr></thead>
            <tbody>
            <?php foreach ($broadcasts as $b): ?>
            <tr>
                <td><?= date('d M Y H:i', strtotime($b['sent_at'])) ?></td>
                <td><strong><?= e($b['title']) ?></strong></td>
                <td><span class="badge badge-<?= ['normal'=>'success','warning'=>'warning','closed'=>'secondary','emergency'=>'danger'][$b['site_status']]??'secondary' ?>"><?= e($b['site_status']) ?></span></td>
                <td><?= e(substr($b['message'],0,80)) ?><?= strlen($b['message'])>80?'...':'' ?></td>
                <td><?= e($b['full_name']) ?></td>
                <td><?= $b['is_false_alarm'] ? '<span class="badge badge-warning">False alarm</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
