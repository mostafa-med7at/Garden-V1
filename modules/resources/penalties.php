<?php
// modules/resources/penalties.php — Fn 16: Late Return Penalty Engine
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Tool Penalties';
$db        = getDB();

// Admin: resolve penalty
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve']) && $user['role_name']==='admin') {
    $penId    = (int)$_POST['penalty_id'];
    $type     = $_POST['penalty_type'];
    $hours    = (float)($_POST['service_hours'] ?? 0);
    $db->prepare("UPDATE tool_penalties SET penalty_type=?,service_hours=?,status='served' WHERE id=?")->execute([$type,$hours,$penId]);
    // If community service, add service hours
    if ($type==='community_service_hours') {
        $userId = $db->prepare("SELECT user_id FROM tool_penalties WHERE id=?");
        $userId->execute([$penId]); $uid = $userId->fetchColumn();
        $month = date('Y-m');
        $db->prepare("INSERT INTO service_hours (user_id,hours_logged,activity_description,month_year,status) VALUES (?,?,?,?,'approved')")
           ->execute([$uid,$hours,'Community service (tool penalty)',$month]);
    }
    auditLog('penalty_resolved','resources','tool_penalties',$penId,"Type: $type");
    setFlash('success','Penalty resolved.');
    header('Location: penalties.php'); exit;
}

if ($user['role_name']==='admin') {
    $penalties = $db->query("
        SELECT tp.*, t.name AS tool_name, u.full_name, u.email, r.due_date, r.returned_at
        FROM tool_penalties tp
        JOIN tool_reservations r ON tp.reservation_id=r.id
        JOIN tools t ON r.tool_id=t.id
        JOIN users u ON tp.user_id=u.id
        ORDER BY tp.issued_at DESC
    ")->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT tp.*, t.name AS tool_name, u.full_name, r.due_date, r.returned_at
        FROM tool_penalties tp
        JOIN tool_reservations r ON tp.reservation_id=r.id
        JOIN tools t ON r.tool_id=t.id
        JOIN users u ON tp.user_id=u.id
        WHERE tp.user_id=? ORDER BY tp.issued_at DESC
    ");
    $stmt->execute([$user['id']]); $penalties = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>⚠️ Tool Return Penalties</h1>
    <p>Late returns incur a fine of £<?= LATE_FINE_PER_DAY ?>/day or <?= LATE_SERVICE_HOURS_PER_DAY ?>h community service per day late.</p>
</div>
<div class="page-actions"><a href="tools.php" class="btn btn-secondary">← Tools</a></div>

<div class="card">
    <div class="card-header">Penalties</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Member</th><th>Tool</th><th>Days Late</th><th>Fine</th><th>Service hrs</th><th>Status</th><?= $user['role_name']==='admin'?'<th>Resolve</th>':'' ?></tr></thead>
            <tbody>
            <?php if ($penalties): foreach ($penalties as $p): ?>
            <tr>
                <td><?= e($p['full_name']) ?></td>
                <td><?= e($p['tool_name']) ?></td>
                <td><span class="badge badge-danger"><?= $p['days_late'] ?> days</span></td>
                <td>£<?= number_format($p['fine_amount'],2) ?></td>
                <td><?= number_format($p['service_hours'],1) ?>h</td>
                <td><span class="badge badge-<?= ['pending'=>'warning','paid'=>'success','served'=>'success','waived'=>'secondary'][$p['status']]??'secondary' ?>"><?= e($p['status']) ?></span></td>
                <?php if ($user['role_name']==='admin' && $p['status']==='pending'): ?>
                <td>
                    <form method="POST" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="penalty_id" value="<?= $p['id'] ?>">
                        <select name="penalty_type" class="form-control" style="width:auto;height:30px;font-size:13px">
                            <option value="fine">Collect Fine (£<?= number_format($p['fine_amount'],2) ?>)</option>
                            <option value="community_service_hours">Community Service (<?= $p['service_hours'] ?>h)</option>
                        </select>
                        <input type="hidden" name="service_hours" value="<?= $p['service_hours'] ?>">
                        <button name="resolve" value="1" class="btn btn-sm btn-primary">Resolve</button>
                    </form>
                </td>
                <?php elseif ($user['role_name']==='admin'): ?><td>—</td><?php endif; ?>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--gray-600)">No penalties on record.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
