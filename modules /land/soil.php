<?php
// modules/land/soil.php — Fn 3: Soil Health Lifecycle Tracker
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Soil Health Tracker';
$db        = getDB();

// Get user's plot (if plot owner)
$myPlots = $db->prepare("SELECT p.* FROM plots p JOIN leases l ON l.plot_id=p.id WHERE l.user_id=? AND l.status='active'");
$myPlots->execute([$user['id']]);
$myPlots = $myPlots->fetchAll();

// Admin/warden see all plots
if (in_array($user['role_name'], ['admin','warden'])) {
    $allPlots = $db->query("SELECT * FROM plots ORDER BY plot_code")->fetchAll();
} else {
    $allPlots = $myPlots;
}

$selectedPlotId = (int)($_GET['plot_id'] ?? ($allPlots[0]['id'] ?? 0));

// Save new soil event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $plotId      = (int)$_POST['plot_id'];
    $eventType   = $_POST['event_type'];
    $fertType    = trim($_POST['fertilizer_type'] ?? '');
    $ph          = $_POST['ph_level'] !== '' ? (float)$_POST['ph_level'] : null;
    $crop        = trim($_POST['crop_name'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $atRisk      = isSoilAtRisk($ph) ? 1 : 0;

    $stmt = $db->prepare("
        INSERT INTO soil_events (plot_id, user_id, event_type, fertilizer_type, ph_level, crop_name, notes, is_at_risk)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$plotId, $user['id'], $eventType, $fertType, $ph, $crop, $notes, $atRisk]);
    auditLog('soil_event_added', 'land', 'soil_events', (int)$db->lastInsertId(), "Plot $plotId: $eventType");

    if ($atRisk) {
        setFlash('warning', "⚠️ Soil pH $ph is outside the safe range (" . SOIL_PH_MIN . "–" . SOIL_PH_MAX . "). Consider amending your soil.");
    } else {
        setFlash('success', 'Soil event recorded successfully.');
    }
    header("Location: soil.php?plot_id=$plotId");
    exit;
}

// Load events for selected plot
$events = [];
if ($selectedPlotId) {
    $stmt = $db->prepare("
        SELECT se.*, u.full_name FROM soil_events se
        JOIN users u ON se.user_id = u.id
        WHERE se.plot_id = ?
        ORDER BY se.recorded_at DESC
    ");
    $stmt->execute([$selectedPlotId]);
    $events = $stmt->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🌱 Soil Health Tracker</h1>
    <p>Record fertilizer use, pH levels, and crop rotations. The system flags soil at risk of depletion.</p>
</div>

<?php if ($allPlots): ?>

<!-- Plot selector -->
<div class="card mb-2">
    <div class="card-body" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
        <label style="font-weight:600">Viewing plot:</label>
        <form method="GET">
            <select name="plot_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($allPlots as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id']==$selectedPlotId?'selected':'' ?>>
                        <?= e($p['plot_code']) ?> (<?= e($p['area_sqm']) ?>m², <?= e($p['soil_quality']) ?> soil)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Add event form -->
<div class="card mb-2">
    <div class="card-header">Record Soil Event</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="plot_id" value="<?= $selectedPlotId ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Event type</label>
                    <select name="event_type" class="form-control" required onchange="toggleFields(this.value)">
                        <option value="fertilizer">Fertilizer applied</option>
                        <option value="ph_test">pH test</option>
                        <option value="crop_rotation">Crop rotation</option>
                        <option value="amendment">Soil amendment</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group" id="ph-group">
                    <label>pH level (optional)</label>
                    <input type="number" name="ph_level" class="form-control"
                           step="0.1" min="0" max="14" placeholder="e.g. 6.5">
                    <p class="form-hint">Safe range: <?= SOIL_PH_MIN ?>–<?= SOIL_PH_MAX ?></p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" id="fert-group">
                    <label>Fertilizer type</label>
                    <input type="text" name="fertilizer_type" class="form-control" placeholder="e.g. Fish emulsion, NPK 10-10-10">
                </div>
                <div class="form-group" id="crop-group">
                    <label>Crop (for rotation records)</label>
                    <input type="text" name="crop_name" class="form-control" placeholder="e.g. Tomatoes, Beans">
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any additional observations..."></textarea>
            </div>
            <button type="submit" name="add_event" value="1" class="btn btn-primary">Save Soil Event</button>
        </form>
    </div>
</div>

<!-- History -->
<div class="card">
    <div class="card-header">Soil Event History</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Event</th><th>Fertilizer</th><th>pH</th><th>Crop</th><th>Recorded by</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if ($events): ?>
                <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($ev['recorded_at'])) ?></td>
                    <td><?= e(str_replace('_',' ',$ev['event_type'])) ?></td>
                    <td><?= e($ev['fertilizer_type'] ?? '—') ?></td>
                    <td>
                        <?php if ($ev['ph_level'] !== null): ?>
                            <span class="badge badge-<?= $ev['is_at_risk']?'danger':'success' ?>"><?= e($ev['ph_level']) ?></span>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td><?= e($ev['crop_name'] ?? '—') ?></td>
                    <td><?= e($ev['full_name']) ?></td>
                    <td>
                        <?php if ($ev['is_at_risk']): ?>
                            <span class="badge badge-danger">⚠️ At risk</span>
                        <?php else: ?>
                            <span class="badge badge-success">Healthy</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:var(--gray-600);padding:2rem">No soil events recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
    <div class="alert alert-info">You don't have an active plot lease. <a href="waitlist.php">Join the waitlist</a> to get a plot.</div>
<?php endif; ?>

<script>
function toggleFields(type) {
    // Show/hide relevant fields based on event type
    document.getElementById('fert-group').style.display = ['fertilizer','amendment'].includes(type) ? '' : 'none';
    document.getElementById('crop-group').style.display = type === 'crop_rotation' ? '' : 'none';
    document.getElementById('ph-group').style.display   = type === 'ph_test'       ? '' : '';
}
toggleFields('fertilizer');
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
