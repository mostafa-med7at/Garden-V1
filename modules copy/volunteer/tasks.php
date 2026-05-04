<?php
// modules/volunteer/tasks.php — Fn 17: Communal Task Weighting, Fn 18: Service Hour Tracker
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Tasks & Volunteer Hours';
$db        = getDB();

// Admin: add task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task']) && $user['role_name']==='admin') {
    $pts = (int)$_POST['difficulty_score'] * 10;
    $db->prepare("INSERT INTO tasks (title,description,difficulty_score,points_reward,created_by) VALUES (?,?,?,?,?)")
       ->execute([trim($_POST['title']),trim($_POST['description']),(int)$_POST['difficulty_score'],$pts,$user['id']]);
    setFlash('success','Task created.'); header('Location: tasks.php'); exit;
}

// Claim task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim'])) {
    $taskId = (int)$_POST['task_id'];
    $db->prepare("UPDATE tasks SET status='in_progress', assigned_to=? WHERE id=? AND status='open'")->execute([$user['id'],$taskId]);
    setFlash('success','Task claimed! Complete it and log your hours.'); header('Location: tasks.php'); exit;
}

// Mark complete (verify)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_task'])) {
    $taskId   = (int)$_POST['task_id'];
    $compType = $_POST['completion_type'];
    $stmt     = $db->prepare("SELECT * FROM tasks WHERE id=? AND assigned_to=?");
    $stmt->execute([$taskId,$user['id']]); $task = $stmt->fetch();
    if ($task) {
        $pts  = $compType==='full' ? $task['points_reward'] : (int)($task['points_reward']*0.5);
        $stat = $compType==='full' ? 'completed' : 'partial';
        $db->prepare("UPDATE tasks SET status=?, completed_at=NOW() WHERE id=?")->execute([$stat,$taskId]);
        $db->prepare("INSERT INTO task_completions (task_id,user_id,completion_type,points_awarded) VALUES (?,?,?,?)")
           ->execute([$taskId,$user['id'],$compType,$pts]);
        $db->prepare("UPDATE users SET community_points=community_points+? WHERE id=?")->execute([$pts,$user['id']]);
        // Update session points
        $_SESSION['user']['points'] = ($_SESSION['user']['points'] ?? 0) + $pts;
        auditLog('task_completed','volunteer','task_completions',null,"Task $taskId: $compType ($pts pts)");
        setFlash('success',"Task marked $compType! You earned $pts community points.");
    }
    header('Location: tasks.php'); exit;
}

// Log service hours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_hours'])) {
    $hours = (float)$_POST['hours'];
    $desc  = trim($_POST['activity']);
    $month = date('Y-m');
    $db->prepare("INSERT INTO service_hours (user_id,hours_logged,activity_description,month_year,status) VALUES (?,?,?,?,'pending')")
       ->execute([$user['id'],$hours,$desc,$month]);
    auditLog('hours_logged','volunteer','service_hours',null,"$hours hrs: $desc");
    setFlash('info',"$hours hour(s) logged for admin review.");
    header('Location: tasks.php'); exit;
}

// Admin: approve/reject hours
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_hours']) && $user['role_name']==='admin') {
    $hId    = (int)$_POST['hour_id'];
    $action = $_POST['action'];
    $db->prepare("UPDATE service_hours SET status=?,reviewed_by=? WHERE id=?")->execute([$action,$user['id'],$hId]);
    if ($action==='rejected') {
        // Deduct from pending — no change needed; hours only count if approved
    }
    setFlash('success',"Hours ".($action==='approved'?'approved':'rejected')."."); header('Location: tasks.php'); exit;
}

// Data
$tasks    = $db->query("SELECT t.*, u.full_name AS assignee FROM tasks t LEFT JOIN users u ON t.assigned_to=u.id ORDER BY t.status='open' DESC, t.difficulty_score DESC")->fetchAll();
$month    = date('Y-m');
$myHoursStmt = $db->prepare("SELECT COALESCE(SUM(hours_logged),0) FROM service_hours WHERE user_id=? AND month_year=? AND status='approved'");
$myHoursStmt->execute([$user['id'],$month]); $myHoursApproved = (float)$myHoursStmt->fetchColumn();
$required = MONTHLY_SERVICE_HOURS;
$pendingHours = $db->query("SELECT sh.*, u.full_name FROM service_hours sh JOIN users u ON sh.user_id=u.id WHERE sh.status='pending' ORDER BY sh.logged_at DESC")->fetchAll();
$myHourLog = $db->prepare("SELECT * FROM service_hours WHERE user_id=? ORDER BY logged_at DESC LIMIT 10");
$myHourLog->execute([$user['id']]); $myHourLog = $myHourLog->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>📋 Tasks & Service Hours</h1>
    <p>Complete tasks to earn community points. Log service hours to maintain your monthly <?= $required ?>h requirement.</p>
</div>

<div class="page-actions">
    <?php if ($user['role_name']==='admin'): ?>
    <button class="btn btn-primary" onclick="toggleSection('add-task')">+ Create Task</button>
    <?php endif; ?>
    <a href="shifts.php" class="btn btn-secondary">📅 Shift Schedule</a>
    <a href="voting.php" class="btn btn-secondary">🗳️ Fund Voting</a>
</div>

<!-- Service hours status -->
<div class="card mb-2">
    <div class="card-header">📊 My Service Hours — <?= date('F Y') ?></div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
            <div>
                <div style="font-size:2rem;font-weight:700;color:var(--green-dark)"><?= number_format($myHoursApproved,1) ?> / <?= $required ?>h</div>
                <div class="text-muted text-sm">Approved hours this month</div>
            </div>
            <div style="flex:1;min-width:200px">
                <?php $pct = min(100,($myHoursApproved/$required)*100); ?>
                <div class="progress"><div class="progress-bar <?= $pct<50?'danger':($pct<100?'warning':'') ?>" style="width:<?= $pct ?>%"></div></div>
                <p class="text-sm text-muted mt-1"><?= $myHoursApproved >= $required ? '✅ Monthly requirement met!' : (number_format($required-$myHoursApproved,1).'h still needed') ?></p>
            </div>
        </div>
        <hr style="margin:1rem 0;border-color:var(--gray-200)">
        <form method="POST" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:0 0 120px">
                <label>Hours worked</label>
                <input type="number" name="hours" class="form-control" step="0.5" min="0.5" value="1" required>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label>Activity description</label>
                <input type="text" name="activity" class="form-control" placeholder="e.g. Weeding path A, compost turning" required>
            </div>
            <button name="log_hours" value="1" class="btn btn-primary">Log Hours</button>
        </form>
    </div>
</div>

<!-- Admin: pending hour reviews -->
<?php if ($user['role_name']==='admin' && $pendingHours): ?>
<div class="card mb-2">
    <div class="card-header">⏳ Pending Hour Approvals (<?= count($pendingHours) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Member</th><th>Hours</th><th>Activity</th><th>Month</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($pendingHours as $h): ?>
            <tr>
                <td><?= e($h['full_name']) ?></td>
                <td><?= $h['hours_logged'] ?>h</td>
                <td><?= e($h['activity_description']) ?></td>
                <td><?= e($h['month_year']) ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="hour_id" value="<?= $h['id'] ?>">
                        <button name="review_hours" value="1" class="btn btn-sm btn-primary" onclick="this.form.elements.action.value='approved'">✓ Approve</button>
                        <input type="hidden" name="action" value="">
                        <button name="review_hours" value="1" class="btn btn-sm btn-danger" onclick="this.form.elements.action.value='rejected'">✗ Reject</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Add task form -->
<div id="add-task" style="display:none" class="card mb-2">
    <div class="card-header">Create Task</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Task title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Difficulty score (1–5)</label>
                    <select name="difficulty_score" class="form-control">
                        <option value="1">1 — Easy (10 pts)</option>
                        <option value="2">2 — Light (20 pts)</option>
                        <option value="3" selected>3 — Moderate (30 pts)</option>
                        <option value="4">4 — Heavy (40 pts)</option>
                        <option value="5">5 — Hard (50 pts)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <button name="add_task" value="1" class="btn btn-primary">Create Task</button>
        </form>
    </div>
</div>

<!-- Tasks list -->
<div class="card">
    <div class="card-header">Available Tasks</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Task</th><th>Difficulty</th><th>Points</th><th>Status</th><th>Assigned to</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
            <tr>
                <td>
                    <strong><?= e($t['title']) ?></strong><br>
                    <span class="text-sm text-muted"><?= e($t['description']) ?></span>
                </td>
                <td>
                    <?php for ($i=1;$i<=5;$i++) echo $i<=$t['difficulty_score']?'⭐':'☆'; ?>
                </td>
                <td><span class="badge badge-success"><?= $t['points_reward'] ?> pts</span></td>
                <td>
                    <?php $tb=['open'=>'success','in_progress'=>'warning','partial'=>'info','completed'=>'secondary'][$t['status']]??'secondary'; ?>
                    <span class="badge badge-<?= $tb ?>"><?= e($t['status']) ?></span>
                </td>
                <td><?= e($t['assignee'] ?? '—') ?></td>
                <td style="white-space:nowrap">
                    <?php if ($t['status']==='open'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <button name="claim" value="1" class="btn btn-sm btn-primary">Claim</button>
                        </form>
                    <?php elseif ($t['status']==='in_progress' && $t['assigned_to']==$user['id']): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <select name="completion_type" class="form-control" style="display:inline;width:auto;height:30px;font-size:13px">
                                <option value="full">Fully done</option>
                                <option value="partial">Partially done</option>
                            </select>
                            <button name="complete_task" value="1" class="btn btn-sm btn-warning">Mark Done</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>function toggleSection(id){var el=document.getElementById(id);if(el)el.style.display=el.style.display==='none'?'':'none';}</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
