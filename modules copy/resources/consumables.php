<?php
// modules/resources/consumables.php — Fn 14: Consumable Inventory Monitor
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Consumables Inventory';
$db        = getDB();

// Add/update stock (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock']) && $user['role_name']==='admin') {
    if ($_POST['consumable_id'] === 'new') {
        $db->prepare("INSERT INTO consumables (name,unit,stock_level,reorder_threshold) VALUES (?,?,?,?)")
           ->execute([trim($_POST['name']),$_POST['unit'],(float)$_POST['stock_add'],(float)$_POST['threshold']]);
    } else {
        $cid = (int)$_POST['consumable_id'];
        $db->prepare("UPDATE consumables SET stock_level=stock_level+?, alert_sent=0 WHERE id=?")->execute([(float)$_POST['stock_add'],$cid]);
    }
    setFlash('success','Stock updated.'); header('Location: consumables.php'); exit;
}

// Use consumable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['use_item'])) {
    $cid    = (int)$_POST['consumable_id'];
    $amount = (float)$_POST['amount_used'];
    $stmt   = $db->prepare("SELECT * FROM consumables WHERE id=?");
    $stmt->execute([$cid]); $item = $stmt->fetch();
    if ($item && $item['stock_level'] >= $amount) {
        $newLevel = $item['stock_level'] - $amount;
        $db->prepare("UPDATE consumables SET stock_level=? WHERE id=?")->execute([$newLevel,$cid]);
        $db->prepare("INSERT INTO consumable_usage_log (consumable_id,used_by,amount_used) VALUES (?,?,?)")->execute([$cid,$user['id'],$amount]);
        auditLog('consumable_used','resources','consumable_usage_log',null,"{$item['name']}: -{$amount}{$item['unit']}");
        // Fn 14: check reorder threshold
        if ($newLevel <= $item['reorder_threshold'] && !$item['alert_sent']) {
            $db->prepare("UPDATE consumables SET alert_sent=1 WHERE id=?")->execute([$cid]);
            auditLog('reorder_alert','resources','consumables',$cid,"REORDER ALERT: {$item['name']} at $newLevel {$item['unit']}");
            setFlash('warning',"⚠️ Reorder alert: {$item['name']} is at $newLevel {$item['unit']} — below threshold of {$item['reorder_threshold']}. Admins notified.");
        } else {
            setFlash('success',"Used $amount {$item['unit']} of {$item['name']}.");
        }
    } else { setFlash('danger','Insufficient stock.'); }
    header('Location: consumables.php'); exit;
}

$consumables = $db->query("SELECT * FROM consumables ORDER BY (stock_level <= reorder_threshold) DESC, name")->fetchAll();
$usageLog    = $db->query("SELECT ul.*, c.name AS item_name, c.unit, u.full_name FROM consumable_usage_log ul JOIN consumables c ON ul.consumable_id=c.id JOIN users u ON ul.used_by=u.id ORDER BY ul.used_at DESC LIMIT 20")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>📦 Consumables Inventory</h1>
    <p>Track shared supplies like fertilizer and mulch. Alerts fire automatically when stock hits the reorder threshold.</p>
</div>

<div class="page-actions">
    <?php if ($user['role_name']==='admin'): ?>
    <button class="btn btn-primary" onclick="toggleSection('add-form')">+ Add / Restock</button>
    <?php endif; ?>
    <a href="tools.php" class="btn btn-secondary">← Tools</a>
</div>

<!-- Reorder alerts -->
<?php foreach ($consumables as $c): if ($c['stock_level'] <= $c['reorder_threshold'] && $c['alert_sent']): ?>
<div class="alert alert-warning">
    ⚠️ <strong>REORDER NEEDED:</strong> <?= e($c['name']) ?> — only <?= number_format((float)$c['stock_level'],2) ?> <?= e($c['unit']) ?> remaining (threshold: <?= $c['reorder_threshold'] ?>).
</div>
<?php endif; endforeach; ?>

<!-- Add form (admin) -->
<div id="add-form" style="display:none" class="card mb-2">
    <div class="card-header">Add / Restock Consumable</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Item</label>
                    <select name="consumable_id" class="form-control">
                        <option value="new">+ New item</option>
                        <?php foreach ($consumables as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (current: <?= $c['stock_level'] ?> <?= $c['unit'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>New item name (if new)</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Organic Fertilizer">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Amount to add</label>
                    <input type="number" name="stock_add" class="form-control" step="0.1" min="0.1" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit" class="form-control"><option>kg</option><option>bags</option><option>units</option><option>rolls</option><option>litres</option></select>
                </div>
                <div class="form-group">
                    <label>Reorder threshold</label>
                    <input type="number" name="threshold" class="form-control" step="0.1" value="5">
                </div>
            </div>
            <button name="add_stock" value="1" class="btn btn-primary">Update Stock</button>
        </form>
    </div>
</div>

<!-- Inventory table -->
<div class="card mb-2">
    <div class="card-header">Current Stock</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Item</th><th>Stock</th><th>Reorder at</th><th>Status</th><th>Use</th></tr></thead>
            <tbody>
            <?php foreach ($consumables as $c):
                $low = $c['stock_level'] <= $c['reorder_threshold'];
                $pct = $c['reorder_threshold'] > 0 ? min(100, ($c['stock_level'] / ($c['reorder_threshold']*3)) * 100) : 100;
            ?>
            <tr>
                <td><strong><?= e($c['name']) ?></strong></td>
                <td>
                    <?= number_format((float)$c['stock_level'],2) ?> <?= e($c['unit']) ?>
                    <div class="progress mt-1" style="width:100px">
                        <div class="progress-bar <?= $low?'danger':''; ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </td>
                <td><?= $c['reorder_threshold'] ?> <?= e($c['unit']) ?></td>
                <td><?= $low ? '<span class="badge badge-danger">⚠️ Low stock</span>' : '<span class="badge badge-success">OK</span>' ?><?= $c['alert_sent']?'<br><span class="text-sm text-muted">Alert sent</span>':'' ?></td>
                <td>
                    <form method="POST" style="display:flex;gap:4px;align-items:center">
                        <input type="hidden" name="consumable_id" value="<?= $c['id'] ?>">
                        <input type="number" name="amount_used" step="0.1" min="0.1" max="<?= $c['stock_level'] ?>" value="1" style="width:65px;padding:4px;border:1px solid var(--gray-400);border-radius:4px;font-size:13px">
                        <button name="use_item" value="1" class="btn btn-sm btn-secondary">Use</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Usage log -->
<div class="card">
    <div class="card-header">Recent Usage Log</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Item</th><th>Amount used</th><th>By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($usageLog as $ul): ?>
            <tr>
                <td><?= e($ul['item_name']) ?></td>
                <td><?= number_format((float)$ul['amount_used'],2) ?> <?= e($ul['unit']) ?></td>
                <td><?= e($ul['full_name']) ?></td>
                <td><?= date('d M Y H:i', strtotime($ul['used_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>function toggleSection(id){var el=document.getElementById(id);if(el)el.style.display=el.style.display==='none'?'':'none';}</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
