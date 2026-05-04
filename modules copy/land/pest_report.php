<?php
// modules/land/pest_report.php — Fn 6: Pest & Disease Alert, Fn 7: Compliance Audit
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Pest & Disease Reports';
$db        = getDB();

// Get user's active plot
$myPlotStmt = $db->prepare("SELECT p.* FROM plots p JOIN leases l ON l.plot_id=p.id WHERE l.user_id=? AND l.status='active' LIMIT 1");
$myPlotStmt->execute([$user['id']]);
$myPlot = $myPlotStmt->fetch();

// Submit pest report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_pest'])) {
    $plotId    = (int)$_POST['plot_id'];
    $pestType  = trim($_POST['pest_type']);
    $severity  = $_POST['severity'];
    $desc      = trim($_POST['description']);
    $transmit  = isset($_POST['is_transmissible']) ? 1 : 0;

    $stmt = $db->prepare("INSERT INTO pest_reports (plot_id, reported_by, pest_type, severity, is_transmissible, description) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$plotId, $user['id'], $pestType, $severity, $transmit, $desc]);
    $reportId = (int)$db->lastInsertId();

    auditLog('pest_reported', 'land', 'pest_reports', $reportId, "$pestType on plot $plotId");

    // Fn 6: If transmissible, identify neighbor plots and notify owners
    if ($transmit) {
        // Simple neighbor logic: plots in same letter group (A-xx, B-xx)
        $plotCode = $db->prepare("SELECT plot_code FROM plots WHERE id=?");
        $plotCode->execute([$plotId]);
        $code   = $plotCode->fetchColumn();
        $prefix = substr($code, 0, 1); // e.g. 'A'

        $neighbors = $db->prepare("
            SELECT DISTINCT u.id, u.full_name, u.email, p.plot_code
            FROM plots p
            JOIN leases l ON l.plot_id=p.id AND l.status='active'
            JOIN users u ON l.user_id=u.id
            WHERE p.id != ? AND p.plot_code LIKE ?
        ");
        $neighbors->execute([$plotId, $prefix . '%']);
        $neighborList = $neighbors->fetchAll();

        // In production: send email. Here we log notifications.
        foreach ($neighborList as $n) {
            auditLog('pest_neighbor_alert', 'land', 'pest_reports', $reportId,
                "Alert sent to {$n['full_name']} (plot {$n['plot_code']}) re transmissible {$pestType}");
        }

        $cnt = count($neighborList);
        setFlash('warning', "⚠️ Transmissible pest reported. $cnt neighboring plot owner(s) have been notified.");
    } else {
        setFlash('success', 'Pest/disease report submitted.');
    }
    header('Location: pest_report.php'); exit;
}

// Warden: submit inspection (Fn 7)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inspection']) && in_array($user['role_name'],['admin','warden'])) {
    $plotId  = (int)$_POST['insp_plot_id'];
    $notes   = trim($_POST['insp_notes']);
    $result  = $_POST['insp_result'];
    $violDet = trim($_POST['violation_details'] ?? '');
    $penalty = (float)($_POST['penalty_applied'] ?? 0);

    // Handle photo upload
    $photoPaths = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $uploadDir = APP_ROOT . '/assets/uploads/inspections/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
            if ($_FILES['photos']['error'][$i] === 0) {
                $ext  = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
                $name = 'insp_' . time() . '_' . $i . '.' . $ext;
                move_uploaded_file($tmp, $uploadDir . $name);
                $photoPaths[] = 'assets/uploads/inspections/' . $name;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO inspections (plot_id, warden_id, notes, photo_paths, result, violation_details, penalty_applied) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$plotId, $user['id'], $notes, json_encode($photoPaths), $result, $violDet, $penalty]);

    // Update plot compliance status
    $compStatus = ['pass'=>'compliant','warning'=>'warning','fail'=>'violation'][$result] ?? 'compliant';
    $db->prepare("UPDATE plots SET compliance_status=? WHERE id=?")->execute([$compStatus, $plotId]);

    auditLog('inspection_completed', 'land', 'inspections', (int)$db->lastInsertId(), "Plot $plotId: $result");
    setFlash('success', 'Inspection recorded. Plot compliance updated to: ' . $compStatus);
    header('Location: pest_report.php'); exit;
}

// Load data
$reports    = $db->query("SELECT r.*, p.plot_code, u.full_name FROM pest_reports r JOIN plots p ON r.plot_id=p.id JOIN users u ON r.reported_by=u.id ORDER BY r.reported_at DESC")->fetchAll();
$inspections= $db->query("SELECT i.*, p.plot_code, u.full_name AS warden_name FROM inspections i JOIN plots p ON i.plot_id=p.id JOIN users u ON i.warden_id=u.id ORDER BY i.inspected_at DESC")->fetchAll();
$allPlots   = $db->query("SELECT * FROM plots ORDER BY plot_code")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🐛 Pest, Disease & Compliance</h1>
    <p>Report infestations and record plot inspections. Transmissible pests trigger automatic neighbor alerts.</p>
</div>

<!-- Pest Report Form -->
<div class="card mb-2">
    <div class="card-header">Report Pest / Disease Infestation</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Affected plot</label>
                    <select name="plot_id" class="form-control" required>
                        <?php foreach ($allPlots as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($myPlot && $myPlot['id']==$p['id'])? 'selected':'' ?>>
                                <?= e($p['plot_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pest / disease name</label>
                    <input type="text" name="pest_type" class="form-control" required placeholder="e.g. Potato Blight, Aphids">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Severity</label>
                    <select name="severity" class="form-control">
                        <option value="low">Low — isolated, not spreading</option>
                        <option value="medium" selected>Medium — active infestation</option>
                        <option value="high">High — severe, rapid spread</option>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.5rem">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:normal">
                        <input type="checkbox" name="is_transmissible" value="1">
                        <strong>Highly transmissible?</strong> (will alert neighboring plots)
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Describe symptoms, affected area..."></textarea>
            </div>
            <button type="submit" name="report_pest" value="1" class="btn btn-danger">Submit Report</button>
        </form>
    </div>
</div>

<!-- Warden Inspection Form (Fn 7) -->
<?php if (in_array($user['role_name'],['admin','warden'])): ?>
<div class="card mb-2">
    <div class="card-header">📋 Record Plot Inspection (Warden Only)</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-row">
                <div class="form-group">
                    <label>Plot to inspect</label>
                    <select name="insp_plot_id" class="form-control" required>
                        <?php foreach ($allPlots as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['plot_code']) ?> (<?= e($p['compliance_status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Inspection result</label>
                    <select name="insp_result" class="form-control" required>
                        <option value="pass">Pass — compliant</option>
                        <option value="warning">Warning — minor issues</option>
                        <option value="fail">Fail — violation found</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Inspection notes</label>
                <textarea name="insp_notes" class="form-control" rows="3" required placeholder="Describe what was observed..."></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Violation details (if any)</label>
                    <input type="text" name="violation_details" class="form-control" placeholder="e.g. Overgrown weeds, abandoned equipment">
                </div>
                <div class="form-group">
                    <label>Penalty applied (£)</label>
                    <input type="number" name="penalty_applied" class="form-control" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="form-group">
                <label>Upload photos (optional)</label>
                <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
            </div>
            <button type="submit" name="submit_inspection" value="1" class="btn btn-warning">Save Inspection Record</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Pest Reports Table -->
<div class="card mb-2">
    <div class="card-header">Recent Pest/Disease Reports</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Plot</th><th>Pest/Disease</th><th>Severity</th><th>Transmissible?</th><th>Reported by</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
            <tr>
                <td><?= e($r['plot_code']) ?></td>
                <td><?= e($r['pest_type']) ?></td>
                <td><span class="badge badge-<?= ['low'=>'info','medium'=>'warning','high'=>'danger'][$r['severity']]?:'secondary' ?>"><?= e($r['severity']) ?></span></td>
                <td><?= $r['is_transmissible'] ? '<span class="badge badge-danger">Yes ⚠️</span>' : 'No' ?></td>
                <td><?= e($r['full_name']) ?></td>
                <td><?= date('d M Y', strtotime($r['reported_at'])) ?></td>
                <td><span class="badge badge-<?= ['open'=>'warning','investigating'=>'info','resolved'=>'success'][$r['status']]?:'secondary' ?>"><?= e($r['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Inspections Table -->
<div class="card">
    <div class="card-header">Inspection Records</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Plot</th><th>Warden</th><th>Result</th><th>Notes</th><th>Penalty</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php foreach ($inspections as $ins): ?>
            <tr>
                <td><?= e($ins['plot_code']) ?></td>
                <td><?= e($ins['warden_name']) ?></td>
                <td><span class="badge badge-<?= ['pass'=>'success','warning'=>'warning','fail'=>'danger'][$ins['result']]?:'secondary' ?>"><?= e($ins['result']) ?></span></td>
                <td><?= e(substr($ins['notes'],0,60)) ?><?= strlen($ins['notes'])>60?'...':'' ?></td>
                <td><?= $ins['penalty_applied']>0 ? '£'.number_format($ins['penalty_applied'],2) : '—' ?></td>
                <td><?= date('d M Y', strtotime($ins['inspected_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
