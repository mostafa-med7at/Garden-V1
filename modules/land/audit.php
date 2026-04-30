<?php
// modules/land/audit.php — Fn 29: RBAC Management, Fn 30: System Audit Trail
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Admin Panel — RBAC & Audit Trail';
$db        = getDB();
if ($user['role_name'] !== 'admin') { setFlash('danger', 'Admin access required.'); redirect('index.php'); }

// Fn 29: Change user role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $targetId = (int)$_POST['user_id'];
    $newRole  = (int)$_POST['role_id'];
    if ($targetId === $user['id']) { setFlash('danger', "You can't change your own role."); header('Location: audit.php'); exit; }
    $db->prepare("UPDATE users SET role_id=? WHERE id=?")->execute([$newRole, $targetId]);
    auditLog('role_changed', 'admin', 'users', $targetId, "Role changed to role_id=$newRole");
    setFlash('success', 'User role updated.'); header('Location: audit.php'); exit;
}

// Fn 29: Toggle user active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $targetId = (int)$_POST['user_id'];
    if ($targetId === $user['id']) { setFlash('danger', "Can't deactivate yourself."); header('Location: audit.php'); exit; }
    $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$targetId]);
    auditLog('user_status_toggled', 'admin', 'users', $targetId, 'Active status toggled');
    setFlash('success', 'User status updated.'); header('Location: audit.php'); exit;
}

// Fn 30: Audit log filters
$filterAction = trim($_GET['action'] ?? '');
$filterUser   = trim($_GET['user_id'] ?? '');
$filterDate   = trim($_GET['date'] ?? '');
$filterModule = trim($_GET['module'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filterAction) { $where[] = 'al.action_type LIKE ?'; $params[] = "%$filterAction%"; }
if ($filterUser)   { $where[] = 'al.user_id = ?';        $params[] = (int)$filterUser; }
if ($filterDate)   { $where[] = 'DATE(al.logged_at) = ?';$params[] = $filterDate; }
if ($filterModule) { $where[] = 'al.module = ?';          $params[] = $filterModule; }

$whereStr = implode(' AND ', $where);
$auditLogs = $db->prepare("
    SELECT al.*, u.full_name FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $whereStr
    ORDER BY al.logged_at DESC LIMIT 200
");
$auditLogs->execute($params); $auditLogs = $auditLogs->fetchAll();

// Load users and roles for RBAC table
$allUsers = $db->query("
    SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id=r.id ORDER BY u.is_active DESC, r.id, u.full_name
")->fetchAll();
$allRoles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();

// Stats
$totalLogs    = $db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$logsToday    = $db->query("SELECT COUNT(*) FROM audit_log WHERE DATE(logged_at)=CURDATE()")->fetchColumn();
$failedLogins = $db->query("SELECT COUNT(*) FROM access_log WHERE is_valid=0")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🛡️ Admin Panel</h1>
    <p>Manage user roles (Fn 29) and review the permanent system audit trail (Fn 30).</p>
</div>

<!-- Stats -->
<div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem">
    <div class="stat-card"><span class="stat-value"><?= count($allUsers) ?></span><span class="stat-label">Total users</span></div>
    <div class="stat-card accent-blue"><span class="stat-value"><?= number_format((int)$totalLogs) ?></span><span class="stat-label">Audit log entries</span></div>
    <div class="stat-card accent-amber"><span class="stat-value"><?= $logsToday ?></span><span class="stat-label">Actions today</span></div>
    <div class="stat-card accent-coral"><span class="stat-value"><?= $failedLogins ?></span><span class="stat-label">Failed gate attempts</span></div>
</div>

<!-- ── Fn 29: RBAC — User Role Management ─────────────────── -->
<div class="card mb-2">
    <div class="module-header">
        <span class="module-icon">👥</span>
        <h2>Role-Based Access Control (Fn 29)</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Member</th><th>Email</th><th>Current Role</th><th>Gate Code</th><th>Status</th><th>Change Role</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($allUsers as $u): ?>
            <tr <?= !$u['is_active'] ? 'style="opacity:.55"' : '' ?>>
                <td><strong><?= e($u['full_name']) ?></strong></td>
                <td><?= e($u['email']) ?></td>
                <td>
                    <span class="badge role-<?= e($u['role_name']) ?> user-badge"><?= e($u['role_name']) ?></span>
                </td>
                <td><code><?= e($u['gate_code'] ?? '—') ?></code></td>
                <td>
                    <span class="badge badge-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <?php if ($u['id'] !== $user['id']): ?>
                    <form method="POST" style="display:flex;gap:4px">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <select name="role_id" class="form-control" style="height:30px;font-size:13px;width:auto">
                            <?php foreach ($allRoles as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $r['id']==$u['role_id']?'selected':'' ?>><?= e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button name="change_role" value="1" class="btn btn-sm btn-primary">Save</button>
                    </form>
                    <?php else: echo '<span class="text-muted text-sm">You</span>'; endif; ?>
                </td>
                <td>
                    <?php if ($u['id'] !== $user['id']): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button name="toggle_active" value="1" class="btn btn-sm btn-<?= $u['is_active']?'danger':'secondary' ?>"
                                data-confirm="<?= $u['is_active']?'Deactivate':'Activate' ?> this user?">
                            <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Fn 30: Audit Trail ─────────────────────────────────── -->
<div class="card">
    <div class="module-header">
        <span class="module-icon">📋</span>
        <h2>System Audit Trail (Fn 30)</h2>
    </div>

    <!-- Filters -->
    <div class="card-body" style="border-bottom:1px solid var(--gray-200);padding:.75rem 1.25rem">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1;min-width:140px">
                <label style="font-size:12px">Action type</label>
                <input type="text" name="action" class="form-control" value="<?= e($filterAction) ?>" placeholder="e.g. login, trade_created">
            </div>
            <div class="form-group" style="margin:0;flex:0 0 130px">
                <label style="font-size:12px">Module</label>
                <select name="module" class="form-control">
                    <option value="">All modules</option>
                    <?php foreach (['land','resources','volunteer','marketplace','auth','admin'] as $m): ?>
                    <option value="<?= $m ?>" <?= $m===$filterModule?'selected':'' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:0 0 160px">
                <label style="font-size:12px">User ID</label>
                <input type="number" name="user_id" class="form-control" value="<?= e($filterUser) ?>" placeholder="User ID">
            </div>
            <div class="form-group" style="margin:0;flex:0 0 150px">
                <label style="font-size:12px">Date</label>
                <input type="date" name="date" class="form-control" value="<?= e($filterDate) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="audit.php" class="btn btn-secondary btn-sm">Clear</a>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Timestamp</th><th>User</th><th>Module</th><th>Action</th><th>Target</th><th>Description</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php if ($auditLogs): ?>
                <?php foreach ($auditLogs as $log): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $log['id'] ?></td>
                    <td class="text-sm" style="white-space:nowrap"><?= date('d M Y H:i:s', strtotime($log['logged_at'])) ?></td>
                    <td class="text-sm"><?= e($log['full_name'] ?? 'System') ?></td>
                    <td><span class="badge badge-secondary" style="font-size:11px"><?= e($log['module'] ?? '—') ?></span></td>
                    <td>
                        <code style="font-size:12px;background:var(--gray-100);padding:1px 5px;border-radius:3px"><?= e($log['action_type']) ?></code>
                    </td>
                    <td class="text-sm text-muted">
                        <?= $log['target_table'] ? e($log['target_table']).'#'.e($log['target_id'] ?? '?') : '—' ?>
                    </td>
                    <td class="text-sm"><?= e(substr($log['description'] ?? '', 0, 60)) ?></td>
                    <td class="text-sm text-muted"><?= e($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--gray-600)">No log entries match your filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($auditLogs) >= 200): ?>
    <div class="card-body" style="border-top:1px solid var(--gray-200);text-align:center;color:var(--gray-600);font-size:13px">
        Showing latest 200 entries. Use filters to narrow results.
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
