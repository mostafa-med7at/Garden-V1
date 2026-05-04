<?php
// admin/users.php — User Manipulation Module (b): List, Search, Add, Update, Delete
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
$user = requireLogin();
if ($user['role_name'] !== 'admin') { redirect('index.php'); }
$pageTitle = 'User Management';
$db = getDB();

// ── Add User ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $fullName = trim($_POST['full_name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $roleId   = (int)$_POST['role_id'];
    $phone    = trim($_POST['phone'] ?? '');
    $membership = $_POST['membership_status'] ?? 'standard';

    if ($fullName && $email && $password && $roleId) {
        // Check email uniqueness
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            setFlash('danger', 'A user with that email already exists.');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (full_name, email, password_hash, role_id, phone, membership_status) VALUES (?,?,?,?,?,?)")
               ->execute([$fullName, $email, $hash, $roleId, $phone, $membership]);
            auditLog('user_created', 'admin', 'users', (int)$db->lastInsertId(), "Created: $email");
            setFlash('success', "User '{$fullName}' created successfully.");
        }
    } else {
        setFlash('danger', 'Please fill in all required fields.');
    }
    header('Location: users.php'); exit;
}

// ── Update User ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $uid        = (int)$_POST['user_id'];
    $fullName   = trim($_POST['full_name']);
    $email      = trim($_POST['email']);
    $roleId     = (int)$_POST['role_id'];
    $phone      = trim($_POST['phone'] ?? '');
    $membership = $_POST['membership_status'] ?? 'standard';
    $isActive   = isset($_POST['is_active']) ? 1 : 0;

    // Check email uniqueness excluding this user
    $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->execute([$email, $uid]);
    if ($check->fetch()) {
        setFlash('danger', 'That email is already used by another user.');
    } else {
        $db->prepare("UPDATE users SET full_name=?, email=?, role_id=?, phone=?, membership_status=?, is_active=? WHERE id=?")
           ->execute([$fullName, $email, $roleId, $phone, $membership, $isActive, $uid]);
        // Optionally update password
        if (!empty($_POST['new_password'])) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
        }
        auditLog('user_updated', 'admin', 'users', $uid, "Updated: $email");
        setFlash('success', "User '{$fullName}' updated.");
    }
    header('Location: users.php'); exit;
}

// ── Delete / Deactivate User ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_user'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid === $user['id']) {
        setFlash('danger', 'You cannot deactivate your own account.');
    } else {
        $db->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$uid]);
        auditLog('user_deactivated', 'admin', 'users', $uid, 'Deactivated');
        setFlash('warning', 'User account deactivated.');
    }
    header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = (int)$_POST['user_id'];
    if ($uid === $user['id']) {
        setFlash('danger', 'You cannot delete your own account.');
    } else {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        auditLog('user_deleted', 'admin', 'users', $uid, 'Permanently deleted');
        setFlash('success', 'User permanently deleted.');
    }
    header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_user'])) {
    $uid = (int)$_POST['user_id'];
    $db->prepare("UPDATE users SET is_active=1 WHERE id=?")->execute([$uid]);
    auditLog('user_reactivated', 'admin', 'users', $uid, 'Reactivated');
    setFlash('success', 'User account reactivated.');
    header('Location: users.php'); exit;
}

// ── Search & List ────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$roleFilter = (int)($_GET['role'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
}
if ($roleFilter) {
    $sql .= " AND u.role_id = ?";
    $params[] = $roleFilter;
}
if ($statusFilter === 'active')   { $sql .= " AND u.is_active = 1"; }
if ($statusFilter === 'inactive') { $sql .= " AND u.is_active = 0"; }
$sql .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$roles = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();

// Stats
$totalUsers  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

// Fetch single user for edit modal
$editUser = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editUser = $s->fetch();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>👥 User Management</h1>
    <p>Add, search, edit roles, and manage member accounts.</p>
</div>

<!-- Stats -->
<div class="stats-row" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <span class="stat-value"><?= $totalUsers ?></span>
        <span class="stat-label">Total Users</span>
    </div>
    <div class="stat-card accent-blue">
        <span class="stat-value"><?= $activeUsers ?></span>
        <span class="stat-label">Active</span>
    </div>
    <div class="stat-card accent-coral">
        <span class="stat-value"><?= $totalUsers - $activeUsers ?></span>
        <span class="stat-label">Inactive</span>
    </div>
    <div class="stat-card accent-amber">
        <span class="stat-value"><?= count($roles) ?></span>
        <span class="stat-label">Roles</span>
    </div>
</div>

<div class="page-actions">
    <button class="btn btn-primary" onclick="toggleSection('add-user-form')">+ Add User</button>
</div>

<!-- Add User Form -->
<div id="add-user-form" style="display:none" class="card mb-2">
    <div class="card-header">➕ Add New User</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role <span class="text-danger">*</span></label>
                    <select name="role_id" class="form-control" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Membership</label>
                    <select name="membership_status" class="form-control">
                        <option value="standard">Standard</option>
                        <option value="premium">Premium</option>
                        <option value="senior">Senior</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+44 ...">
                </div>
            </div>
            <button name="add_user" value="1" class="btn btn-primary">Create User</button>
            <button type="button" class="btn btn-secondary" onclick="toggleSection('add-user-form')">Cancel</button>
        </form>
    </div>
</div>

<!-- Search & Filter -->
<div class="card mb-2">
    <div class="card-body" style="padding:.75rem 1rem">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label class="small fw-semibold">Search</label>
                <input type="text" name="q" class="form-control" placeholder="Name, email or phone…" value="<?= e($search) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="small fw-semibold">Role</label>
                <select name="role" class="form-control">
                    <option value="">All roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $roleFilter == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="small fw-semibold">Status</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height:38px">🔍 Search</button>
            <?php if ($search || $roleFilter || $statusFilter): ?>
                <a href="users.php" class="btn btn-secondary" style="height:38px">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        Users
        <?php if ($search || $roleFilter || $statusFilter): ?>
            <span class="badge badge-info" style="margin-left:.5rem"><?= count($users) ?> results</span>
        <?php else: ?>
            <span class="badge badge-success" style="margin-left:.5rem"><?= count($users) ?> total</span>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Membership</th>
                    <th>Points</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr style="<?= !$u['is_active'] ? 'opacity:.55' : '' ?>">
                <td><?= $u['id'] ?></td>
                <td>
                    <strong><?= e($u['full_name']) ?></strong>
                    <?php if ($u['id'] == $user['id']): ?>
                        <span class="badge badge-info" style="font-size:10px">You</span>
                    <?php endif; ?>
                </td>
                <td><?= e($u['email']) ?></td>
                <td><?= e($u['phone'] ?: '—') ?></td>
                <td>
                    <?php
                    $roleBadge = ['admin'=>'danger','warden'=>'warning','plot_owner'=>'success','member'=>'info','guest'=>'secondary'];
                    $rb = $roleBadge[$u['role_name']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?= $rb ?>"><?= e($u['role_name']) ?></span>
                </td>
                <td><?= e($u['membership_status']) ?></td>
                <td>🏅 <?= (int)$u['community_points'] ?> / ⭐ <?= (int)$u['karma_points'] ?></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm text-muted"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                <td style="white-space:nowrap">
                    <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-secondary">✏️ Edit</a>

                    <?php if ($u['id'] != $user['id']): ?>
                        <?php if ($u['is_active']): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button name="deactivate_user" value="1" class="btn btn-sm btn-warning"
                                data-confirm="Deactivate <?= e($u['full_name']) ?>?">🚫 Deactivate</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button name="reactivate_user" value="1" class="btn btn-sm btn-success">✅ Reactivate</button>
                        </form>
                        <?php endif; ?>

                        <form method="POST" style="display:inline">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button name="delete_user" value="1" class="btn btn-sm btn-danger"
                                data-confirm="PERMANENTLY delete <?= e($u['full_name']) ?>? This cannot be undone.">🗑️ Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- Inline Edit Row -->
            <?php if ($editUser && $editUser['id'] == $u['id']): ?>
            <tr style="background:var(--gray-100)">
                <td colspan="10">
                    <div style="padding:.75rem">
                        <strong>✏️ Edit User: <?= e($editUser['full_name']) ?></strong>
                        <form method="POST" style="margin-top:.75rem">
                            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?= e($editUser['full_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= e($editUser['email']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?= e($editUser['phone'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Role</label>
                                    <select name="role_id" class="form-control">
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= $r['id'] ?>" <?= $editUser['role_id'] == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Membership</label>
                                    <select name="membership_status" class="form-control">
                                        <?php foreach (['standard','premium','senior'] as $ms): ?>
                                            <option value="<?= $ms ?>" <?= $editUser['membership_status'] === $ms ? 'selected' : '' ?>><?= ucfirst($ms) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>New Password <span class="text-muted">(leave blank to keep)</span></label>
                                    <input type="password" name="new_password" class="form-control" minlength="6">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                                    &nbsp;Account Active
                                </label>
                            </div>
                            <button name="update_user" value="1" class="btn btn-primary">💾 Save Changes</button>
                            <a href="users.php" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endif; ?>

            <?php endforeach; ?>
            <?php if (!$users): ?>
            <tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleSection(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
// Confirm dialogs
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn) {
        if (!confirm(btn.dataset.confirm)) e.preventDefault();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
