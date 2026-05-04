<?php
// modules/land/leases.php — Fn 5: Lease Renewal & Eviction Workflow
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Lease Management';
$db        = getDB();

// Admin: expire overdue leases
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_expiry']) && $user['role_name'] === 'admin') {
    $expired = $db->query("UPDATE leases SET status='expired' WHERE status='active' AND end_date < CURDATE()")->rowCount();
    // free up plots
    $db->query("UPDATE plots p JOIN leases l ON l.plot_id=p.id SET p.status='available' WHERE l.status='expired'");
    auditLog('lease_expiry_run', 'land', 'leases', null, "$expired leases expired");
    setFlash('info', "$expired lease(s) marked expired and plots freed.");
    header('Location: leases.php'); exit;
}

// Member: pay / renew lease
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew'])) {
    $leaseId = (int)$_POST['lease_id'];
    $method  = $_POST['payment_method'];
    $stmt    = $db->prepare("SELECT l.*, p.area_sqm, p.soil_quality FROM leases l JOIN plots p ON l.plot_id=p.id WHERE l.id=? AND l.user_id=?");
    $stmt->execute([$leaseId, $user['id']]);
    $lease = $stmt->fetch();
    if ($lease) {
        $fee    = calculateRentalFee($lease['area_sqm'], $lease['soil_quality'], $user['membership']);
        $newEnd = date('Y-m-d', strtotime($lease['end_date'] . ' +1 year'));
        // Record payment
        $db->prepare("INSERT INTO billing_transactions (lease_id,user_id,amount,payment_method,status) VALUES (?,?,?,'$method','paid')")
           ->execute([$leaseId, $user['id'], $fee['total_fee']]);
        // Renew lease dates & status
        $db->prepare("UPDATE leases SET end_date=?, status='active' WHERE id=?")->execute([$newEnd, $leaseId]);
        auditLog('lease_renewed', 'land', 'leases', $leaseId, "Renewed to $newEnd, £{$fee['total_fee']}");
        setFlash('success', "Lease renewed to $newEnd. Payment of £{$fee['total_fee']} recorded.");
    }
    header('Location: leases.php'); exit;
}

// Admin: terminate lease
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['terminate']) && $user['role_name'] === 'admin') {
    $leaseId = (int)$_POST['lease_id'];
    $db->prepare("UPDATE leases SET status='terminated' WHERE id=?")->execute([$leaseId]);
    $db->prepare("UPDATE plots p JOIN leases l ON l.plot_id=p.id SET p.status='available' WHERE l.id=?")->execute([$leaseId]);
    auditLog('lease_terminated', 'land', 'leases', $leaseId, 'Admin terminated lease');
    setFlash('warning', 'Lease terminated. Plot is now available.');
    header('Location: leases.php'); exit;
}

// Load leases
if ($user['role_name'] === 'admin' || $user['role_name'] === 'warden') {
    $leases = $db->query("
        SELECT l.*, p.plot_code, p.area_sqm, p.soil_quality, u.full_name, u.email,
               DATEDIFF(l.end_date, CURDATE()) AS days_left
        FROM leases l JOIN plots p ON l.plot_id=p.id JOIN users u ON l.user_id=u.id
        ORDER BY l.status, l.end_date ASC
    ")->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT l.*, p.plot_code, p.area_sqm, p.soil_quality, u.full_name, u.email,
               DATEDIFF(l.end_date, CURDATE()) AS days_left
        FROM leases l JOIN plots p ON l.plot_id=p.id JOIN users u ON l.user_id=u.id
        WHERE l.user_id=? ORDER BY l.end_date DESC
    ");
    $stmt->execute([$user['id']]);
    $leases = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>📄 Lease Management</h1>
    <p>View, renew, and manage plot leases. The system auto-expires unpaid leases.</p>
</div>

<div class="page-actions">
    <?php if ($user['role_name'] === 'admin'): ?>
    <form method="POST" style="display:inline">
        <button name="run_expiry" value="1" class="btn btn-warning"
                data-confirm="Mark all overdue leases as expired?">⚙️ Run Expiry Check</button>
    </form>
    <a href="lease_create.php" class="btn btn-primary">+ New Lease</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">Leases (<?= count($leases) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Plot</th><th>Member</th><th>Start</th><th>Expires</th><th>Days Left</th><th>Fee/yr</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($leases as $l):
                $fee = calculateRentalFee($l['area_sqm'], $l['soil_quality'], $user['membership']);
                $daysLeft = (int)$l['days_left'];
                $urgent   = $daysLeft >= 0 && $daysLeft <= 30;
            ?>
            <tr>
                <td><strong><?= e($l['plot_code']) ?></strong></td>
                <td><?= e($l['full_name']) ?><br><small class="text-muted"><?= e($l['email']) ?></small></td>
                <td><?= date('d M Y', strtotime($l['start_date'])) ?></td>
                <td><?= date('d M Y', strtotime($l['end_date'])) ?></td>
                <td>
                    <?php if ($daysLeft < 0): ?>
                        <span class="badge badge-danger">Overdue</span>
                    <?php elseif ($urgent): ?>
                        <span class="badge badge-warning">⚠️ <?= $daysLeft ?>d</span>
                    <?php else: ?>
                        <?= $daysLeft ?> days
                    <?php endif; ?>
                </td>
                <td>£<?= number_format($fee['total_fee'], 2) ?></td>
                <td>
                    <?php $sb=['active'=>'success','expired'=>'danger','terminated'=>'secondary','grace_period'=>'warning'][$l['status']]??'secondary'; ?>
                    <span class="badge badge-<?= $sb ?>"><?= e($l['status']) ?></span>
                </td>
                <td style="white-space:nowrap">
                    <?php if ($l['status']==='active' && ($l['user_id']==$user['id'] || $user['role_name']==='admin')): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="lease_id" value="<?= $l['id'] ?>">
                        <select name="payment_method" class="form-control" style="display:inline;width:auto;height:32px;font-size:13px;padding:2px 6px">
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank</option>
                            <option value="cash">Cash</option>
                        </select>
                        <button name="renew" value="1" class="btn btn-sm btn-primary">Renew</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($user['role_name']==='admin' && $l['status']==='active'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="lease_id" value="<?= $l['id'] ?>">
                        <button name="terminate" value="1" class="btn btn-sm btn-danger"
                                data-confirm="Terminate this lease? The plot will be freed.">Terminate</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
