<?php
// modules/resources/tools.php — Fn 10-16: Tool State Machine, Usage Trigger, Reservations, Damage, Inventory, Media, Penalties
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Tool Library';
$db        = getDB();

// ── Fn 10: Change tool state ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_state']) && hasPermission('resources','edit')) {
    $toolId   = (int)$_POST['tool_id'];
    $newState = $_POST['new_state'];
    $notes    = trim($_POST['state_notes'] ?? '');
    $stmt     = $db->prepare("SELECT status FROM tools WHERE id=?");
    $stmt->execute([$toolId]); $old = $stmt->fetchColumn();
    $db->prepare("UPDATE tools SET status=? WHERE id=?")->execute([$newState, $toolId]);
    $db->prepare("INSERT INTO tool_state_log (tool_id,changed_by,old_status,new_status,notes) VALUES (?,?,?,?,?)")
       ->execute([$toolId,$user['id'],$old,$newState,$notes]);
    auditLog('tool_state_changed','resources','tools',$toolId,"$old → $newState");
    setFlash('success',"Tool state updated to: $newState");
    header('Location: tools.php'); exit;
}

// ── Fn 12: Reserve tool ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reserve'])) {
    $toolId    = (int)$_POST['tool_id'];
    $slotDate  = $_POST['slot_date'];
    $slotStart = $_POST['slot_start'];
    $slotEnd   = $_POST['slot_end'];
    // Conflict check
    $conflict = $db->prepare("
        SELECT id FROM tool_reservations
        WHERE tool_id=? AND slot_date=? AND status='confirmed'
          AND NOT (slot_end <= ? OR slot_start >= ?)
    ");
    $conflict->execute([$toolId, $slotDate, $slotStart, $slotEnd]);
    if ($conflict->fetch()) {
        setFlash('danger','That time slot is already booked. Choose another slot.');
    } else {
        $due = $slotDate . ' ' . $slotEnd;
        $db->prepare("INSERT INTO tool_reservations (tool_id,user_id,slot_date,slot_start,slot_end,due_date,status) VALUES (?,?,?,?,?,?,'confirmed')")
           ->execute([$toolId,$user['id'],$slotDate,$slotStart,$slotEnd,$due]);
        $db->prepare("UPDATE tools SET status='checked_out' WHERE id=?")->execute([$toolId]);
        $db->prepare("INSERT INTO tool_state_log (tool_id,changed_by,old_status,new_status,notes) VALUES (?,?,?,?,?)")
           ->execute([$toolId,$user['id'],'available','checked_out','Reserved by member']);
        auditLog('tool_reserved','resources','tool_reservations',(int)$db->lastInsertId(),"Tool $toolId reserved for $slotDate");
        setFlash('success','Tool reserved successfully!');
    }
    header('Location: tools.php'); exit;
}

// ── Cancel reservation ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    $resId = (int)$_POST['reservation_id'];
    $stmt = $db->prepare("SELECT tool_id FROM tool_reservations WHERE id=? AND user_id=? AND status='confirmed'");
    $stmt->execute([$resId, $user['id']]);
    if ($toolId = $stmt->fetchColumn()) {
        $db->prepare("UPDATE tool_reservations SET status='cancelled' WHERE id=?")->execute([$resId]);
        $db->prepare("UPDATE tools SET status='available' WHERE id=?")->execute([$toolId]);
        $db->prepare("INSERT INTO tool_state_log (tool_id,changed_by,old_status,new_status,notes) VALUES (?,?,?,?,?)")
           ->execute([$toolId,$user['id'],'checked_out','available','Reservation cancelled']);
        auditLog('reservation_cancelled', 'resources', 'tool_reservations', $resId, "Cancelled reservation $resId");
        setFlash('success', 'Reservation cancelled.');
    }
    header('Location: tools.php'); exit;
}

// ── Reschedule reservation ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule_reservation'])) {
    $resId     = (int)$_POST['reservation_id'];
    $slotDate  = $_POST['slot_date'];
    $slotStart = $_POST['slot_start'];
    $slotEnd   = $_POST['slot_end'];
    
    $stmt = $db->prepare("SELECT tool_id FROM tool_reservations WHERE id=? AND user_id=? AND status='confirmed'");
    $stmt->execute([$resId, $user['id']]);
    if ($toolId = $stmt->fetchColumn()) {
        $conflict = $db->prepare("
            SELECT id FROM tool_reservations
            WHERE tool_id=? AND slot_date=? AND status='confirmed' AND id!=?
              AND NOT (slot_end <= ? OR slot_start >= ?)
        ");
        $conflict->execute([$toolId, $slotDate, $resId, $slotStart, $slotEnd]);
        if ($conflict->fetch()) {
            setFlash('danger', 'That time slot is already booked. Choose another slot.');
        } else {
            $due = $slotDate . ' ' . $slotEnd;
            $db->prepare("UPDATE tool_reservations SET slot_date=?, slot_start=?, slot_end=?, due_date=? WHERE id=?")
               ->execute([$slotDate, $slotStart, $slotEnd, $due, $resId]);
            auditLog('reservation_rescheduled', 'resources', 'tool_reservations', $resId, "Rescheduled to $slotDate");
            setFlash('success', 'Reservation rescheduled successfully!');
        }
    }
    header('Location: tools.php'); exit;
}

// ── Fn 16: Return tool & calculate penalty ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_tool'])) {
    $resId  = (int)$_POST['reservation_id'];
    $stmt   = $db->prepare("SELECT r.*, t.name AS tool_name FROM tool_reservations r JOIN tools t ON r.tool_id=t.id WHERE r.id=? AND r.user_id=?");
    $stmt->execute([$resId,$user['id']]); $res = $stmt->fetch();
    if ($res) {
        $now     = date('Y-m-d H:i:s');
        $penalty = calculateLatePenalty($res['due_date'], $now);
        $db->prepare("UPDATE tool_reservations SET status='completed', returned_at=NOW() WHERE id=?")->execute([$resId]);
        $db->prepare("UPDATE tools SET status='available', total_usage_hours=total_usage_hours+? WHERE id=?")->execute([1, $res['tool_id']]);
        $db->prepare("INSERT INTO tool_state_log (tool_id,changed_by,old_status,new_status,notes) VALUES (?,?,?,?,?)")
           ->execute([$res['tool_id'],$user['id'],'checked_out','available','Returned']);
        // Fn 11: usage-based maintenance trigger
        $toolData = $db->prepare("SELECT * FROM tools WHERE id=?");
        $toolData->execute([$res['tool_id']]); $tool = $toolData->fetch();
        if ($tool['total_usage_hours'] >= $tool['maintenance_threshold_hours']) {
            $db->prepare("UPDATE tools SET needs_maintenance=1 WHERE id=?")->execute([$res['tool_id']]);
        }
        if ($penalty['days_late'] > 0) {
            $db->prepare("INSERT INTO tool_penalties (reservation_id,user_id,days_late,penalty_type,fine_amount,service_hours) VALUES (?,?,?,?,?,?)")
               ->execute([$resId,$user['id'],$penalty['days_late'],'fine',$penalty['fine_amount'],$penalty['service_hours']]);
            auditLog('tool_returned_late','resources','tool_penalties',null,"{$penalty['days_late']} days late — £{$penalty['fine_amount']}");
            setFlash('warning',"Tool returned {$penalty['days_late']} day(s) late. Fine: £{$penalty['fine_amount']}");
        } else {
            auditLog('tool_returned','resources','tool_reservations',$resId,'On time');
            setFlash('success','Tool returned on time. Thank you!');
        }
    }
    header('Location: tools.php'); exit;
}

// ── Fn 13: Report damage ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_damage'])) {
    $toolId = (int)$_POST['damage_tool_id'];
    $desc   = trim($_POST['damage_desc']);
    $db->prepare("INSERT INTO damage_reports (tool_id,reported_by,description,status) VALUES (?,?,?,'pending')")
       ->execute([$toolId,$user['id'],$desc]);
    $db->prepare("UPDATE tools SET status='in_repair' WHERE id=?")->execute([$toolId]);
    $db->prepare("INSERT INTO tool_state_log (tool_id,changed_by,old_status,new_status,notes) VALUES (?,?,?,?,?)")
       ->execute([$toolId,$user['id'],'checked_out','in_repair','Damage reported']);
    auditLog('damage_reported','resources','damage_reports',(int)$db->lastInsertId(),"Tool $toolId damage report");
    setFlash('warning','Damage report submitted. Admin will review.');
    header('Location: tools.php'); exit;
}

// ── Fn 13: Admin review damage ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_damage']) && $user['role_name']==='admin') {
    $repId   = (int)$_POST['report_id'];
    $type    = $_POST['damage_type'];
    $fee     = (float)($_POST['repair_fee'] ?? 0);
    $exempt  = ($type==='natural_wear') ? 1 : 0;
    $db->prepare("UPDATE damage_reports SET damage_type=?,repair_fee=?,is_exempt=?,admin_reviewed_by=?,reviewed_at=NOW(),status='reviewed' WHERE id=?")
       ->execute([$type,$fee,$exempt,$user['id'],$repId]);
    $stmt = $db->prepare("SELECT tool_id FROM damage_reports WHERE id=?");
    $stmt->execute([$repId]); $toolId2 = $stmt->fetchColumn();
    $db->prepare("UPDATE tools SET status='available' WHERE id=?")->execute([$toolId2]);
    auditLog('damage_reviewed','resources','damage_reports',$repId,"$type, fee: $fee");
    setFlash('success','Damage report reviewed. Tool returned to available.');
    header('Location: tools.php'); exit;
}

// ── Add tool (admin) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tool']) && $user['role_name']==='admin') {
    $db->prepare("INSERT INTO tools (name,description,maintenance_threshold_hours,media_links) VALUES (?,?,?,?)")
       ->execute([trim($_POST['tool_name']),trim($_POST['tool_desc']),(float)$_POST['threshold'],trim($_POST['media_links']??'')]);
    auditLog('tool_added','resources','tools',(int)$db->lastInsertId(),$_POST['tool_name']);
    setFlash('success','Tool added to library.');
    header('Location: tools.php'); exit;
}

// Load data
$tools    = $db->query("SELECT * FROM tools ORDER BY status, name")->fetchAll();
$myRes    = $db->prepare("SELECT r.*, t.name AS tool_name FROM tool_reservations r JOIN tools t ON r.tool_id=t.id WHERE r.user_id=? AND r.status='confirmed' ORDER BY r.slot_date");
$myRes->execute([$user['id']]); $myRes = $myRes->fetchAll();
$penalties= $db->prepare("SELECT tp.*, r.tool_id, t.name AS tool_name FROM tool_penalties tp JOIN tool_reservations r ON tp.reservation_id=r.id JOIN tools t ON r.tool_id=t.id WHERE tp.user_id=? AND tp.status='pending'");
$penalties->execute([$user['id']]); $penalties = $penalties->fetchAll();
$damageRep= $db->query("SELECT dr.*, t.name AS tool_name, u.full_name AS reporter FROM damage_reports dr JOIN tools t ON dr.tool_id=t.id JOIN users u ON dr.reported_by=u.id ORDER BY dr.reported_at DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🔧 Tool Library</h1>
    <p>Reserve tools, track their status, report damage, and manage returns.</p>
</div>

<div class="page-actions">
    <?php if ($user['role_name']==='admin'): ?>
    <button class="btn btn-primary" onclick="toggleSection('add-tool-form')">+ Add Tool</button>
    <?php endif; ?>
    <a href="consumables.php" class="btn btn-secondary">📦 Consumables</a>
    <a href="penalties.php" class="btn btn-secondary">⚠️ Penalties</a>
</div>

<!-- My reservations -->
<?php if ($myRes): ?>
<div class="card mb-2">
    <div class="card-header">My Active Reservations</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tool</th><th>Date</th><th>Time</th><th>Due</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($myRes as $r): ?>
            <tr>
                <td><strong><?= e($r['tool_name']) ?></strong></td>
                <td><?= date('d M Y', strtotime($r['slot_date'])) ?></td>
                <td><?= e($r['slot_start']) ?> – <?= e($r['slot_end']) ?></td>
                <td><?= date('d M Y H:i', strtotime($r['due_date'])) ?></td>
                <td style="white-space:nowrap">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                        <button name="return_tool" value="1" class="btn btn-sm btn-primary"
                                data-confirm="Confirm return of <?= e($r['tool_name']) ?>?">Return Tool</button>
                    </form>
                    <button class="btn btn-sm btn-secondary" onclick="toggleSection('reschedule-form-<?= $r['id'] ?>')">Reschedule</button>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                        <button name="cancel_reservation" value="1" class="btn btn-sm btn-outline-danger"
                                data-confirm="Cancel this reservation?">Cancel</button>
                    </form>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="damage_tool_id" value="<?= $r['tool_id'] ?>">
                        <button class="btn btn-sm btn-danger" onclick="document.getElementById('damage-form-<?= $r['tool_id'] ?>').style.display='block';return false">Report Damage</button>
                    </form>
                </td>
            </tr>
            <!-- Reschedule sub-form -->
            <tr id="reschedule-form-<?= $r['id'] ?>" style="display:none;background:var(--gray-100)">
                <td colspan="5">
                    <form method="POST" style="display:flex;gap:.5rem;padding:.5rem;align-items:flex-end">
                        <input type="hidden" name="reservation_id" value="<?= $r['id'] ?>">
                        <div class="form-group" style="margin:0">
                            <label class="small fw-semibold text-muted">New Date</label>
                            <input type="date" name="slot_date" class="form-control" value="<?= e($r['slot_date']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="small fw-semibold text-muted">Start</label>
                            <input type="time" name="slot_start" class="form-control" value="<?= e($r['slot_start']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0">
                            <label class="small fw-semibold text-muted">End</label>
                            <input type="time" name="slot_end" class="form-control" value="<?= e($r['slot_end']) ?>" required>
                        </div>
                        <button name="reschedule_reservation" value="1" class="btn btn-warning">Save New Time</button>
                    </form>
                </td>
            </tr>
            <!-- Damage report sub-form -->
            <tr id="damage-form-<?= $r['tool_id'] ?>" style="display:none;background:var(--gray-100)">
                <td colspan="5">
                    <form method="POST" style="display:flex;gap:.5rem;padding:.5rem;align-items:flex-end">
                        <input type="hidden" name="damage_tool_id" value="<?= $r['tool_id'] ?>">
                        <div class="form-group" style="margin:0;flex:1">
                            <input type="text" name="damage_desc" class="form-control" placeholder="Describe the damage..." required>
                        </div>
                        <button name="report_damage" value="1" class="btn btn-danger">Submit Report</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Pending penalties -->
<?php if ($penalties): ?>
<div class="alert alert-warning">
    ⚠️ You have <?= count($penalties) ?> pending penalty(ies).
    <?php foreach ($penalties as $p): ?>
        <strong><?= e($p['tool_name']) ?></strong>: <?= $p['days_late'] ?> days late — Fine: £<?= number_format($p['fine_amount'],2) ?> or <?= $p['service_hours'] ?>h community service.
    <?php endforeach; ?>
    <a href="penalties.php" class="btn btn-sm btn-warning" style="margin-left:.5rem">View Penalties</a>
</div>
<?php endif; ?>

<!-- Add tool form (admin) -->
<div id="add-tool-form" style="display:none" class="card mb-2">
    <div class="card-header">Add Tool to Library</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Tool name</label>
                    <input type="text" name="tool_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Maintenance threshold (hours)</label>
                    <input type="number" name="threshold" class="form-control" value="50" min="1" required>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="tool_desc" class="form-control">
            </div>
            <div class="form-group">
                <label>Media links (Fn 15 — comma-separated URLs)</label>
                <input type="text" name="media_links" class="form-control" placeholder="https://youtube.com/..., https://...">
            </div>
            <button name="add_tool" value="1" class="btn btn-primary">Add Tool</button>
        </form>
    </div>
</div>

<!-- Tool cards -->
<div class="card">
    <div class="card-header">All Tools (<?= count($tools) ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Tool</th><th>Status</th><th>Usage hrs</th><th>Maintenance</th><th>Reserve</th><th>State change (admin)</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tools as $t):
                $statusBadge = ['available'=>'success','checked_out'=>'warning','in_repair'=>'info','decommissioned'=>'secondary','missing'=>'danger'][$t['status']]??'secondary';
                $links = $t['media_links'] ? array_filter(array_map('trim', explode(',', $t['media_links']))) : [];
            ?>
            <tr>
                <td>
                    <strong><?= e($t['name']) ?></strong><br>
                    <span class="text-sm text-muted"><?= e($t['description'] ?: '') ?></span>
                    <?php if ($links): ?>
                        <br>
                        <?php foreach ($links as $link): ?>
                            <a href="<?= e($link) ?>" target="_blank" class="btn btn-sm btn-secondary" style="margin-top:3px;font-size:11px">📎 Guide</a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= $statusBadge ?>"><?= e(str_replace('_',' ',$t['status'])) ?></span></td>
                <td>
                    <?= number_format($t['total_usage_hours'],1) ?> / <?= number_format($t['maintenance_threshold_hours'],0) ?>h
                    <div class="progress mt-1" style="width:80px">
                        <?php $pct = min(100, ($t['total_usage_hours']/$t['maintenance_threshold_hours'])*100); ?>
                        <div class="progress-bar <?= $pct>=100?'danger':($pct>=80?'warning':'') ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </td>
                <td><?= $t['needs_maintenance'] ? '<span class="badge badge-danger">⚠️ Service needed</span>' : '<span class="badge badge-success">OK</span>' ?></td>
                <td>
                    <?php if ($t['status']==='available'): ?>
                    <button class="btn btn-sm btn-primary" onclick="toggleSection('res-<?= $t['id'] ?>')">Reserve</button>
                    <div id="res-<?= $t['id'] ?>" style="display:none;margin-top:.5rem">
                        <form method="POST">
                            <input type="hidden" name="tool_id" value="<?= $t['id'] ?>">
                            <input type="date" name="slot_date" class="form-control" style="margin-bottom:4px" value="<?= date('Y-m-d') ?>" required>
                            <div style="display:flex;gap:4px">
                                <input type="time" name="slot_start" class="form-control" value="09:00" required>
                                <input type="time" name="slot_end"   class="form-control" value="12:00" required>
                            </div>
                            <button name="reserve" value="1" class="btn btn-sm btn-primary" style="margin-top:4px;width:100%">Confirm</button>
                        </form>
                    </div>
                    <?php else: echo '<span class="text-muted text-sm">Not available</span>'; endif; ?>
                </td>
                <td>
                    <?php if ($user['role_name']==='admin'): ?>
                    <button class="btn btn-sm btn-secondary" onclick="toggleSection('state-<?= $t['id'] ?>')">Change State</button>
                    <div id="state-<?= $t['id'] ?>" style="display:none;margin-top:.5rem">
                        <form method="POST">
                            <input type="hidden" name="tool_id" value="<?= $t['id'] ?>">
                            <select name="new_state" class="form-control" style="margin-bottom:4px">
                                <option value="available">Available</option>
                                <option value="checked_out">Checked Out</option>
                                <option value="in_repair">In Repair</option>
                                <option value="decommissioned">Decommissioned</option>
                                <option value="missing">Missing</option>
                            </select>
                            <input type="text" name="state_notes" class="form-control" placeholder="Notes..." style="margin-bottom:4px">
                            <button name="change_state" value="1" class="btn btn-sm btn-warning" style="width:100%">Update</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Damage reports (admin) -->
<?php if ($user['role_name']==='admin' && $damageRep): ?>
<div class="card mt-2">
    <div class="card-header">🔨 Damage Reports (Admin Review)</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tool</th><th>Reported by</th><th>Description</th><th>Status</th><th>Review</th></tr></thead>
            <tbody>
            <?php foreach ($damageRep as $dr): ?>
            <tr>
                <td><?= e($dr['tool_name']) ?></td>
                <td><?= e($dr['reporter']) ?></td>
                <td><?= e(substr($dr['description'],0,60)) ?></td>
                <td><span class="badge badge-<?= ['pending'=>'warning','reviewed'=>'success','resolved'=>'info'][$dr['status']]??'secondary' ?>"><?= e($dr['status']) ?></span></td>
                <td>
                    <?php if ($dr['status']==='pending'): ?>
                    <form method="POST" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="report_id" value="<?= $dr['id'] ?>">
                        <select name="damage_type" class="form-control" style="width:auto;height:32px;font-size:13px">
                            <option value="natural_wear">Natural wear (exempt)</option>
                            <option value="negligence">Negligence (charge fee)</option>
                        </select>
                        <input type="number" name="repair_fee" placeholder="Fee £" step="0.01" min="0" value="0" style="width:80px;padding:4px;border:1px solid var(--gray-400);border-radius:4px;font-size:13px">
                        <button name="review_damage" value="1" class="btn btn-sm btn-primary">Review</button>
                    </form>
                    <?php else: ?>
                        <span class="text-muted text-sm"><?= e($dr['damage_type']??'—') ?> / £<?= number_format($dr['repair_fee'],2) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleSection(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
