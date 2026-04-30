<?php
// modules/land/compost.php — Fn 8: Compost Contribution Tracker
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Compost Tracker';
$db        = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_compost'])) {
    $amount = max(0, (float)$_POST['amount_kg']);
    $notes  = trim($_POST['notes'] ?? '');
    $db->prepare("INSERT INTO compost_contributions (user_id, amount_kg, notes) VALUES (?,?,?)")
       ->execute([$user['id'], $amount, $notes]);
    auditLog('compost_logged','land','compost_contributions',null,"$amount kg by user {$user['id']}");
    setFlash('success', $amount == 0 ? 'Zero contribution recorded.' : "Logged {$amount} kg of green waste. Thanks!");
    header('Location: compost.php'); exit;
}

// My total
$myTotal = $db->prepare("SELECT COALESCE(SUM(amount_kg),0) FROM compost_contributions WHERE user_id=?");
$myTotal->execute([$user['id']]);
$myTotal = (float)$myTotal->fetchColumn();

// Leaderboard
$leaderboard = $db->query("
    SELECT u.full_name, COALESCE(SUM(c.amount_kg),0) AS total_kg, COUNT(c.id) AS entries
    FROM users u LEFT JOIN compost_contributions c ON c.user_id=u.id
    GROUP BY u.id ORDER BY total_kg DESC LIMIT 10
")->fetchAll();

// My history
$history = $db->prepare("SELECT * FROM compost_contributions WHERE user_id=? ORDER BY contributed_at DESC LIMIT 20");
$history->execute([$user['id']]);
$history = $history->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>♻️ Compost Contribution Tracker</h1>
    <p>Log green waste you bring to the communal compost pile.</p>
</div>

<div class="stats-row" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card">
        <span class="stat-value"><?= number_format($myTotal,1) ?> kg</span>
        <span class="stat-label">My total contribution</span>
    </div>
    <div class="stat-card accent-amber">
        <span class="stat-value"><?= count($history) ?></span>
        <span class="stat-label">My log entries</span>
    </div>
    <div class="stat-card accent-blue">
        <?php $grandTotal = $db->query("SELECT COALESCE(SUM(amount_kg),0) FROM compost_contributions")->fetchColumn(); ?>
        <span class="stat-value"><?= number_format((float)$grandTotal,1) ?> kg</span>
        <span class="stat-label">Garden total</span>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

<div class="card">
    <div class="card-header">Log Contribution</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-group">
                <label>Amount of green waste (kg)</label>
                <input type="number" name="amount_kg" class="form-control" step="0.1" min="0" value="0" required>
                <p class="form-hint">Enter 0 to record a visit with no contribution.</p>
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Hedge clippings, vegetable scraps..."></textarea>
            </div>
            <button type="submit" name="log_compost" value="1" class="btn btn-primary">Log Contribution</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">🏆 Leaderboard (Top 10)</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>Member</th><th>Total (kg)</th><th>Entries</th></tr></thead>
            <tbody>
            <?php foreach ($leaderboard as $i => $row): ?>
            <tr <?= $row['full_name']===$user['full_name']?'style="background:var(--green-pale)"':'' ?>>
                <td><?= $i+1 ?><?= $i===0?' 🥇':($i===1?' 🥈':($i===2?' 🥉':'')) ?></td>
                <td><?= e($row['full_name']) ?></td>
                <td><strong><?= number_format((float)$row['total_kg'],1) ?></strong></td>
                <td><?= $row['entries'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<div class="card mt-2">
    <div class="card-header">My Contribution History</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Amount (kg)</th><th>Notes</th></tr></thead>
            <tbody>
            <?php if ($history): ?>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($h['contributed_at'])) ?></td>
                    <td><?= $h['amount_kg'] > 0 ? number_format((float)$h['amount_kg'],2).' kg' : '<span class="text-muted">0 (visit logged)</span>' ?></td>
                    <td><?= e($h['notes'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" style="text-align:center;padding:2rem;color:var(--gray-600)">No contributions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
