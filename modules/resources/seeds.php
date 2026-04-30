<?php
// modules/resources/seeds.php — Fn 9: Seed Viability & Expiry Tracker
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Seed Bank';
$db        = getDB();

// Add seed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_seed']) && hasPermission('resources','create')) {
    $db->prepare("INSERT INTO seeds (name,variety,quantity_packets,stored_date,expiry_months,allergen_category,parent_plant_notes,added_by)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([
           trim($_POST['name']), trim($_POST['variety']), (int)$_POST['quantity_packets'],
           $_POST['stored_date'], (int)$_POST['expiry_months'],
           trim($_POST['allergen_category'] ?? ''), trim($_POST['parent_plant_notes'] ?? ''), $user['id']
       ]);
    auditLog('seed_added','resources','seeds',(int)$db->lastInsertId(),$_POST['name']);
    setFlash('success','Seed batch added to the bank.');
    header('Location: seeds.php'); exit;
}

// Run viability check — update statuses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_check'])) {
    $allSeeds = $db->query("SELECT * FROM seeds")->fetchAll();
    $updated  = 0;
    foreach ($allSeeds as $s) {
        $storedDate  = new DateTime($s['stored_date']);
        $now         = new DateTime();
        $ageMonths   = (int)$storedDate->diff($now)->days / 30.44;
        $expiryPct   = $ageMonths / $s['expiry_months'];

        if ($expiryPct >= 1) {
            $newStatus = 'expired';
        } elseif ($expiryPct >= 0.8) {
            $newStatus = 'nearing_expiry';
        } else {
            $newStatus = 'viable';
        }
        if ($newStatus !== $s['status'] && !in_array($s['status'],['flagged_for_testing','recommended_planting'])) {
            $db->prepare("UPDATE seeds SET status=? WHERE id=?")->execute([$newStatus, $s['id']]);
            $updated++;
        }
    }
    setFlash('info',"Viability check complete. $updated seed batch(es) updated.");
    header('Location: seeds.php'); exit;
}

// Pass germination test — recommend immediate planting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass_germ']) && hasPermission('resources','edit')) {
    $seedId = (int)$_POST['seed_id'];
    $db->prepare("UPDATE seeds SET status='recommended_planting' WHERE id=?")->execute([$seedId]);
    auditLog('seed_germ_passed','resources','seeds',$seedId,'Passed germination test');
    setFlash('success','Seed marked as passed germination — recommend immediate planting.');
    header('Location: seeds.php'); exit;
}

// Withdraw from seed bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw']) && hasPermission('resources','edit')) {
    $seedId = (int)$_POST['seed_id'];
    $qty    = (int)$_POST['withdraw_qty'];
    $stmt   = $db->prepare("SELECT * FROM seeds WHERE id=?");
    $stmt->execute([$seedId]); $seed = $stmt->fetch();
    if ($seed && $seed['quantity_packets'] >= $qty) {
        $db->prepare("UPDATE seeds SET quantity_packets=quantity_packets-? WHERE id=?")->execute([$qty,$seedId]);
        // Award seed bank credits
        $db->prepare("UPDATE users SET seed_bank_credits=seed_bank_credits+? WHERE id=?")->execute([$qty*2, $user['id']]);
        auditLog('seed_withdrawn','resources','seeds',$seedId,"$qty packets by user {$user['id']}");
        setFlash('success',"Withdrew $qty packet(s). You earned ".($qty*2)." seed credits!");
    } else {
        setFlash('danger','Not enough packets in stock.');
    }
    header('Location: seeds.php'); exit;
}

$seeds = $db->query("SELECT s.*, u.full_name AS added_by_name FROM seeds s LEFT JOIN users u ON s.added_by=u.id ORDER BY s.status, s.name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🌾 Seed Bank</h1>
    <p>Manage communal seed inventory. The system automatically flags batches nearing expiry for germination testing.</p>
</div>

<div class="page-actions">
    <?php if (hasPermission('resources','create')): ?>
        <button class="btn btn-primary" onclick="document.getElementById('add-form').style.display=document.getElementById('add-form').style.display==='none'?'':'none'">+ Add Seed Batch</button>
    <?php endif; ?>
    <form method="POST" style="display:inline">
        <button name="run_check" value="1" class="btn btn-secondary">🔍 Run Viability Check</button>
    </form>
    <a href="consumables.php" class="btn btn-secondary">📦 Consumables</a>
</div>

<!-- Add seed form -->
<div class="card mb-2" id="add-form" style="display:none">
    <div class="card-header">Add Seed Batch</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Seed name</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Tomato">
                </div>
                <div class="form-group">
                    <label>Variety</label>
                    <input type="text" name="variety" class="form-control" placeholder="e.g. Cherry Roma">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Packets in stock</label>
                    <input type="number" name="quantity_packets" class="form-control" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Date stored</label>
                    <input type="date" name="stored_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Shelf life (months)</label>
                    <input type="number" name="expiry_months" class="form-control" min="1" value="24" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Allergen category (if any)</label>
                    <select name="allergen_category" class="form-control">
                        <option value="">None</option>
                        <?php foreach (ALLERGEN_CATEGORIES as $a): ?>
                            <option value="<?= e($a) ?>"><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Parent plant notes</label>
                    <input type="text" name="parent_plant_notes" class="form-control" placeholder="Origin, characteristics...">
                </div>
            </div>
            <button type="submit" name="add_seed" value="1" class="btn btn-primary">Save Seed Batch</button>
        </form>
    </div>
</div>

<!-- Seed table -->
<div class="card">
    <div class="card-header">Seed Bank Inventory (<?= count($seeds) ?> batches)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Seed</th><th>Variety</th><th>Packets</th><th>Stored</th><th>Expiry</th><th>Age</th><th>Allergen</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($seeds as $s):
                $stored    = new DateTime($s['stored_date']);
                $now       = new DateTime();
                $ageMonths = round((int)$stored->diff($now)->days / 30.44, 1);
                $expiryDate= (clone $stored)->modify("+{$s['expiry_months']} months");
                $statusBadge=['viable'=>'success','nearing_expiry'=>'warning','expired'=>'danger','flagged_for_testing'=>'warning','recommended_planting'=>'info'][$s['status']]??'secondary';
            ?>
            <tr>
                <td><strong><?= e($s['name']) ?></strong></td>
                <td><?= e($s['variety'] ?: '—') ?></td>
                <td><?= (int)$s['quantity_packets'] ?></td>
                <td><?= date('d M Y', strtotime($s['stored_date'])) ?></td>
                <td><?= $expiryDate->format('d M Y') ?></td>
                <td><?= $ageMonths ?> mo</td>
                <td><?= $s['allergen_category'] ? '<span class="badge badge-warning">⚠️ '.e($s['allergen_category']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                <td>
                    <span class="badge badge-<?= $statusBadge ?>">
                        <?= $s['status']==='nearing_expiry'?'⚠️ nearing expiry':($s['status']==='recommended_planting'?'🌱 plant now':e(str_replace('_',' ',$s['status']))) ?>
                    </span>
                </td>
                <td style="white-space:nowrap">
                    <?php if ($s['status']==='nearing_expiry' && hasPermission('resources','edit')): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="seed_id" value="<?= $s['id'] ?>">
                        <button name="pass_germ" value="1" class="btn btn-sm btn-warning">✓ Germ Pass</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($s['quantity_packets']>0 && !in_array($s['status'],['expired'])): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="seed_id" value="<?= $s['id'] ?>">
                        <input type="number" name="withdraw_qty" value="1" min="1" max="<?= $s['quantity_packets'] ?>" style="width:55px;display:inline;padding:2px 4px;border:1px solid var(--gray-400);border-radius:4px;font-size:13px">
                        <button name="withdraw" value="1" class="btn btn-sm btn-secondary">Withdraw</button>
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
