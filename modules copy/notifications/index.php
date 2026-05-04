<?php
// modules/notifications/index.php — Email / Notification Module (f)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user = requireLogin();
if ($user['role_name'] !== 'admin') { redirect('index.php'); }
$pageTitle = 'Email & Notifications';
$db = getDB();

// ── Send email helper (uses PHP mail() — configure sendmail in XAMPP php.ini for real emails) ──
function sendNotification(array $db_ref, int $recipientId, string $subject, string $body, string $type = 'general'): bool {
    global $db;
    // Fetch recipient email
    $stmt = $db->prepare("SELECT email, full_name FROM users WHERE id=? AND is_active=1");
    $stmt->execute([$recipientId]);
    $recipient = $stmt->fetch();
    if (!$recipient) return false;

    $to      = $recipient['email'];
    $name    = $recipient['full_name'];
    $headers = implode("\r\n", [
        'From: ' . APP_NAME . ' <noreply@garden.local>',
        'Reply-To: noreply@garden.local',
        'Content-Type: text/html; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: PHP/' . phpversion(),
    ]);

    $htmlBody = buildEmailTemplate($name, $subject, $body);
    $sent = @mail($to, $subject, $htmlBody, $headers);

    // Log notification in DB
    $db->prepare("INSERT INTO notifications_log (user_id, subject, body, type, sent_at, status) VALUES (?,?,?,?,NOW(),?)")
       ->execute([$recipientId, $subject, strip_tags($body), $type, $sent ? 'sent' : 'failed']);

    return $sent;
}

function buildEmailTemplate(string $name, string $subject, string $body): string {
    $appName = APP_NAME;
    $appUrl  = APP_URL;
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Inter, Arial, sans-serif; background:#f5f5f5; margin:0; padding:20px; }
    .container { max-width:600px; margin:0 auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.1); }
    .header { background:#2d6a4f; padding:24px 32px; color:#fff; }
    .header h1 { margin:0; font-size:1.3rem; }
    .header p { margin:4px 0 0; opacity:.8; font-size:.9rem; }
    .content { padding:28px 32px; color:#333; line-height:1.6; }
    .content h2 { color:#2d6a4f; margin-top:0; }
    .footer { background:#f0f0f0; padding:16px 32px; text-align:center; font-size:.8rem; color:#888; }
    .btn { display:inline-block; padding:10px 22px; background:#2d6a4f; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>🌱 {$appName}</h1>
      <p>Community Garden Management System</p>
    </div>
    <div class="content">
      <h2>{$subject}</h2>
      <p>Dear {$name},</p>
      {$body}
      <p style="margin-top:24px"><a href="{$appUrl}" class="btn">Visit Garden Portal</a></p>
    </div>
    <div class="footer">
      This email was sent by {$appName}. Please do not reply to this address.
    </div>
  </div>
</body>
</html>
HTML;
}

// ── Create notifications_log table if not exists ──
$db->exec("CREATE TABLE IF NOT EXISTS notifications_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT,
    type VARCHAR(50) DEFAULT 'general',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('sent','failed','pending') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)");

// ── Action: Send custom notification ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_custom'])) {
    $recipients = $_POST['recipients'] ?? 'all';
    $subject    = trim($_POST['subject']);
    $body       = trim($_POST['body']);
    $roleId     = (int)($_POST['role_filter'] ?? 0);

    if ($subject && $body) {
        // Build recipient list
        if ($recipients === 'all') {
            $ustmt = $db->query("SELECT id FROM users WHERE is_active=1");
        } elseif ($recipients === 'role' && $roleId) {
            $ustmt = $db->prepare("SELECT id FROM users WHERE role_id=? AND is_active=1");
            $ustmt->execute([$roleId]);
        } elseif ($recipients === 'single') {
            $uid = (int)$_POST['single_user_id'];
            $ustmt = $db->prepare("SELECT id FROM users WHERE id=? AND is_active=1");
            $ustmt->execute([$uid]);
        } else {
            $ustmt = $db->query("SELECT id FROM users WHERE is_active=1");
        }
        $uids   = $ustmt->fetchAll(PDO::FETCH_COLUMN);
        $sentOk = 0;
        foreach ($uids as $uid) {
            if (sendNotification($db, $uid, $subject, "<p>$body</p>", 'custom')) $sentOk++;
        }
        auditLog('notifications_sent', 'admin', 'notifications_log', null, "Custom: $subject → $sentOk sent");
        setFlash('success', "Notification sent to $sentOk recipient(s).");
    } else {
        setFlash('danger', 'Subject and body are required.');
    }
    header('Location: index.php'); exit;
}

// ── Action: Send waitlist notification ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_waitlist'])) {
    $uid = (int)$_POST['waitlist_user_id'];
    $plotCode = trim($_POST['plot_code']);
    $subject  = "Good news — A plot is available! ($plotCode)";
    $body     = "<p>A garden plot (<strong>$plotCode</strong>) has become available and you're next on the waitlist! Please log in to the portal to accept or decline within 48 hours.</p>";
    $sent = sendNotification($db, $uid, $subject, $body, 'waitlist');
    if ($sent) {
        $db->prepare("UPDATE waitlist SET status='notified', notified_at=NOW() WHERE user_id=?")->execute([$uid]);
        auditLog('waitlist_notified', 'admin', 'waitlist', $uid, "Notified about $plotCode");
        setFlash('success', 'Waitlist notification sent.');
    } else {
        setFlash('danger', 'Email could not be delivered. Check XAMPP mail config.');
    }
    header('Location: index.php'); exit;
}

// ── Action: Send pest alert to neighbours ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_pest_alert'])) {
    $reportId = (int)$_POST['report_id'];
    $prpt = $db->prepare("SELECT pr.*, p.plot_code FROM pest_reports pr JOIN plots p ON pr.plot_id=p.id WHERE pr.id=?");
    $prpt->execute([$reportId]); $pest = $prpt->fetch();
    if ($pest && $pest['is_transmissible']) {
        // Notify all active plot owners
        $owners = $db->query("SELECT DISTINCT u.id FROM users u JOIN leases l ON u.id=l.user_id WHERE l.status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $subject = "⚠️ Pest Alert: {$pest['pest_type']} reported near plot {$pest['plot_code']}";
        $body    = "<p>A transmissible pest/disease has been reported on plot <strong>{$pest['plot_code']}</strong>.</p><p><strong>Type:</strong> {$pest['pest_type']}<br><strong>Severity:</strong> {$pest['severity']}</p><p>Please inspect your plot and report any signs immediately.</p>";
        $sentOk = 0;
        foreach ($owners as $uid) {
            if (sendNotification($db, $uid, $subject, $body, 'pest_alert')) $sentOk++;
        }
        auditLog('pest_alert_sent', 'admin', 'pest_reports', $reportId, "Alert sent to $sentOk plot owners");
        setFlash('success', "Pest alert sent to $sentOk plot owners.");
    } else {
        setFlash('warning', 'Pest report not found or not marked as transmissible.');
    }
    header('Location: index.php'); exit;
}

// ── Load data ────────────────────────────────────────────────
$roles         = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$allUsers      = $db->query("SELECT id, full_name, email, role_id FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();
$waitlistItems = $db->query("SELECT w.*, u.full_name, u.email FROM waitlist w JOIN users u ON w.user_id=u.id WHERE w.status='waiting' ORDER BY w.priority_score DESC LIMIT 10")->fetchAll();
$pestAlerts    = $db->query("SELECT pr.*, p.plot_code, u.full_name FROM pest_reports pr JOIN plots p ON pr.plot_id=p.id JOIN users u ON pr.reported_by=u.id WHERE pr.is_transmissible=1 AND pr.status='open' ORDER BY pr.reported_at DESC")->fetchAll();
$recentLogs    = $db->query("SELECT nl.*, u.full_name FROM notifications_log nl LEFT JOIN users u ON nl.user_id=u.id ORDER BY nl.sent_at DESC LIMIT 50")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>📧 Email &amp; Notifications</h1>
    <p>Send emails to members, manage waitlist alerts, and track notification history.</p>
</div>

<!-- Quick Action Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="border-left:4px solid var(--green-dark)">
        <div class="card-body">
            <strong>📢 Custom Broadcast</strong>
            <p class="text-sm text-muted" style="margin:.5rem 0">Send a custom message to all or selected members.</p>
            <button class="btn btn-primary btn-sm" onclick="toggleSection('custom-form')">Compose</button>
        </div>
    </div>
    <div class="card" style="border-left:4px solid #e6ab00">
        <div class="card-body">
            <strong>⏳ Waitlist Alerts</strong>
            <p class="text-sm text-muted" style="margin:.5rem 0"><?= count($waitlistItems) ?> member(s) waiting. Notify when a plot opens.</p>
            <button class="btn btn-warning btn-sm" onclick="toggleSection('waitlist-form')">Notify</button>
        </div>
    </div>
    <div class="card" style="border-left:4px solid var(--coral)">
        <div class="card-body">
            <strong>🐛 Pest Alerts</strong>
            <p class="text-sm text-muted" style="margin:.5rem 0"><?= count($pestAlerts) ?> open transmissible pest report(s).</p>
            <button class="btn btn-danger btn-sm" onclick="toggleSection('pest-form')">Send Alert</button>
        </div>
    </div>
</div>

<!-- Custom Notification Form -->
<div id="custom-form" style="display:none" class="card mb-2">
    <div class="card-header">📢 Compose Custom Notification</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Send To</label>
                    <select name="recipients" id="recipient-type" class="form-control" onchange="updateRecipientFields()">
                        <option value="all">All active members</option>
                        <option value="role">By role</option>
                        <option value="single">Single user</option>
                    </select>
                </div>
                <div class="form-group" id="role-field" style="display:none">
                    <label>Role</label>
                    <select name="role_filter" class="form-control">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="user-field" style="display:none">
                    <label>User</label>
                    <select name="single_user_id" class="form-control">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> &lt;<?= e($u['email']) ?>&gt;</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Subject <span class="text-danger">*</span></label>
                <input type="text" name="subject" class="form-control" placeholder="Email subject…" required>
            </div>
            <div class="form-group">
                <label>Message Body <span class="text-danger">*</span></label>
                <textarea name="body" class="form-control" rows="5" placeholder="Write your message here…" required></textarea>
            </div>
            <button name="send_custom" value="1" class="btn btn-primary" data-confirm="Send this notification?">📤 Send Now</button>
            <button type="button" class="btn btn-secondary" onclick="toggleSection('custom-form')">Cancel</button>
        </form>
    </div>
</div>

<!-- Waitlist Notification Form -->
<div id="waitlist-form" style="display:none" class="card mb-2">
    <div class="card-header">⏳ Notify Waitlist Member of Available Plot</div>
    <div class="card-body">
        <?php if ($waitlistItems): ?>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Waitlist Member</label>
                    <select name="waitlist_user_id" class="form-control">
                        <?php foreach ($waitlistItems as $w): ?>
                            <option value="<?= $w['user_id'] ?>">#<?= $w['id'] ?> — <?= e($w['full_name']) ?> (Score: <?= $w['priority_score'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Available Plot Code</label>
                    <input type="text" name="plot_code" class="form-control" placeholder="e.g. A-01" required>
                </div>
            </div>
            <button name="notify_waitlist" value="1" class="btn btn-warning">📧 Send Waitlist Email</button>
            <button type="button" class="btn btn-secondary" onclick="toggleSection('waitlist-form')">Cancel</button>
        </form>
        <?php else: ?>
            <p class="text-muted">No members currently on the waitlist.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Pest Alert Form -->
<div id="pest-form" style="display:none" class="card mb-2">
    <div class="card-header">🐛 Send Pest Alert to All Plot Owners</div>
    <div class="card-body">
        <?php if ($pestAlerts): ?>
        <form method="POST">
            <div class="form-group">
                <label>Pest Report</label>
                <select name="report_id" class="form-control">
                    <?php foreach ($pestAlerts as $p): ?>
                        <option value="<?= $p['id'] ?>">Plot <?= e($p['plot_code']) ?> — <?= e($p['pest_type']) ?> (<?= $p['severity'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button name="send_pest_alert" value="1" class="btn btn-danger" data-confirm="Send pest alert to all active plot owners?">🚨 Send Alert</button>
            <button type="button" class="btn btn-secondary" onclick="toggleSection('pest-form')">Cancel</button>
        </form>
        <?php else: ?>
            <p class="text-muted">No open transmissible pest reports.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Notification Log -->
<div class="card">
    <div class="card-header">📋 Notification Log <span class="badge badge-info" style="margin-left:.5rem"><?= count($recentLogs) ?> recent</span></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Time</th><th>Recipient</th><th>Type</th><th>Subject</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
            <tr>
                <td class="text-sm"><?= date('d M Y H:i', strtotime($log['sent_at'])) ?></td>
                <td><?= e($log['full_name'] ?? '—') ?></td>
                <td><span class="badge badge-info"><?= e($log['type']) ?></span></td>
                <td><?= e(substr($log['subject'], 0, 60)) ?></td>
                <td>
                    <?php if ($log['status'] === 'sent'): ?>
                        <span class="badge badge-success">✅ Sent</span>
                    <?php elseif ($log['status'] === 'failed'): ?>
                        <span class="badge badge-danger">❌ Failed</span>
                    <?php else: ?>
                        <span class="badge badge-warning">⏳ Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recentLogs): ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding:2rem">No notifications sent yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-info" style="margin-top:1rem">
    <strong>ℹ️ Email Setup:</strong> Emails are sent using PHP's built-in <code>mail()</code>. For real delivery on XAMPP:
    open <code>php.ini</code>, set <code>SMTP = smtp.gmail.com</code>, <code>smtp_port = 587</code>, and install
    <a href="https://github.com/PHPMailer/PHPMailer" target="_blank">PHPMailer</a> for authenticated SMTP.
</div>

<script>
function toggleSection(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
function updateRecipientFields() {
    var type = document.getElementById('recipient-type').value;
    document.getElementById('role-field').style.display = type === 'role'   ? '' : 'none';
    document.getElementById('user-field').style.display = type === 'single' ? '' : 'none';
}
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
