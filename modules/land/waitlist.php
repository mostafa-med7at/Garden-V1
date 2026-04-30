<?php
// modules/land/waitlist.php — Fn 4: Plot Waitlist & Priority
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Plot Waitlist';
$db        = getDB();

// Join waitlist action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join'])) {
    $chk = $db->prepare("SELECT id FROM waitlist WHERE user_id=?");
    $chk->execute([$user['id']]);
    if ($chk->fetch()) {
        setFlash('warning', 'You are already on the waitlist.');
    } else {
        $score = calculatePriorityScore($user['points'], 0); // residency from user record
        $stmt  = $db->prepare("INSERT INTO waitlist (user_id, priority_score) VALUES (?,?)");
        $stmt->execute([$user['id'], $score]);
        auditLog('waitlist_joined', 'land', 'waitlist', (int)$db->lastInsertId());
        setFlash('success', 'You have joined the waitlist. Priority score: ' . $score);
    }
    header('Location: waitlist.php'); exit;
}

// Admin: notify top member when plot available
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_top']) && $user['role_name']==='admin') {
    $top = $db->query("SELECT w.*, u.full_name, u.email FROM waitlist w JOIN users u ON w.user_id=u.id WHERE w.status='waiting' ORDER BY w.priority_score DESC LIMIT 1")->fetch();
    if ($top) {
        $db->prepare("UPDATE waitlist SET status='notified', notified_at=NOW() WHERE id=?")->execute([$top['id']]);
        setFlash('success', "Notified {$top['full_name']} ({$top['email']}) — highest priority.");
    }
    header('Location: waitlist.php'); exit;
}

// Member response to notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond'])) {
    $response = $_POST['respond'];
    $stmt     = $db->prepare("UPDATE waitlist SET status=? WHERE user_id=?");
    $stmt->execute([$response, $user['id']]);
    if ($response === 'declined') {
        setFlash('info', 'You declined. The next member in the queue will be notified.');
    }
    header('Location: waitlist.php'); exit;
}

// Load waitlist (sorted by priority)
$waitlistItems = $db->query("
    SELECT w.*, u.full_name, u.email, u.community_points, u.residency_months
    FROM waitlist w JOIN users u ON w.user_id=u.id
    ORDER BY w.priority_score DESC
")->fetchAll();

// My waitlist entry
$myEntry = null;
foreach ($waitlistItems as $item) {
    if ($item['user_id'] == $user['id']) { $myEntry = $item; break; }
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>📋 Plot Waitlist</h1>
    <p>Members are ranked by priority score (60% community points + 40% residency months).</p>
</div>

<div class="page-actions">
    <?php if (!$myEntry): ?>
        <form method="POST" style="display:inline">
            <button type="submit" name="join" value="1" class="btn btn-primary">Join Waitlist</button>
        </form>
    <?php elseif ($myEntry['status'] === 'notified'): ?>
        <div class="alert alert-success" style="display:inline-flex;align-items:center;gap:1rem;margin:0">
            🎉 A plot is available for you!
            <form method="POST" style="display:inline">
                <button type="submit" name="respond" value="accepted" class="btn btn-primary btn-sm">Accept Plot</button>
            </form>
            <form method="POST" style="display:inline">
                <button type="submit" name="respond" value="declined" class="btn btn-secondary btn-sm">Decline</button>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert-info" style="margin:0">
            You are on the waitlist. Position: <strong>#<?= array_search($myEntry, $waitlistItems) + 1 ?></strong>
            | Priority score: <strong><?= $myEntry['priority_score'] ?></strong>
            | Status: <span class="badge badge-info"><?= e($myEntry['status']) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($user['role_name'] === 'admin'): ?>
        <form method="POST" style="display:inline">
            <button type="submit" name="notify_top" value="1" class="btn btn-warning"
                    data-confirm="Notify the highest-priority member that a plot is available?">
                📣 Notify Top Member
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">Waitlist (<?= count($waitlistItems) ?> members)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>Member</th><th>Community Points</th>
                    <th>Residency (months)</th><th>Priority Score</th>
                    <th>Joined</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($waitlistItems): ?>
                <?php foreach ($waitlistItems as $i => $w): ?>
                <tr <?= $w['user_id']==$user['id'] ? 'style="background:var(--green-pale)"' : '' ?>>
                    <td><strong><?= $i+1 ?></strong></td>
                    <td><?= e($w['full_name']) ?></td>
                    <td><?= e($w['community_points']) ?></td>
                    <td><?= e($w['residency_months']) ?></td>
                    <td>
                        <strong><?= e($w['priority_score']) ?></strong>
                        <?php if ($i === 0): ?><span class="badge badge-success">Top</span><?php endif; ?>
                    </td>
                    <td><?= date('d M Y', strtotime($w['joined_at'])) ?></td>
                    <td>
                        <?php $sb=['waiting'=>'secondary','notified'=>'warning','accepted'=>'success','declined'=>'danger'][$w['status']]??'secondary'; ?>
                        <span class="badge badge-<?= $sb ?>"><?= e($w['status']) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--gray-600)">No members on the waitlist.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
