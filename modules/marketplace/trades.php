<?php
// modules/marketplace/trades.php — Fn 24: Flash Trade, Fn 25: Karma, Fn 26: Allergen Guard, Fn 28: Quality Rating
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/constants.php';
$user      = requireLogin();
$pageTitle = 'Harvest Marketplace';
$db        = getDB();

// Fn 24: Create flash trade post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_trade'])) {
    $title    = trim($_POST['title']);
    $desc     = trim($_POST['description']);
    $qty      = trim($_POST['quantity']);
    $cat      = trim($_POST['allergen_category'] ?? '');
    $hours    = max(1, (int)($_POST['expiry_hours'] ?? 2));
    $expires  = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    $allergen = isAllergen($cat) ? 1 : 0;

    $db->prepare("INSERT INTO flash_trades (seller_id,title,description,quantity,allergen_flag,allergen_category,expires_at) VALUES (?,?,?,?,?,?,?)")
       ->execute([$user['id'],$title,$desc,$qty,$allergen,$cat,$expires]);
    auditLog('trade_created','marketplace','flash_trades',(int)$db->lastInsertId(),$title);
    setFlash('success','Trade posted! It expires in '.$hours.' hour(s).');
    header('Location: trades.php'); exit;
}

// Fn 24: Claim a trade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_trade'])) {
    $tradeId = (int)$_POST['trade_id'];
    $stmt    = $db->prepare("SELECT * FROM flash_trades WHERE id=? AND status='active' AND expires_at > NOW() AND seller_id != ?");
    $stmt->execute([$tradeId, $user['id']]); $trade = $stmt->fetch();
    if ($trade) {
        $db->prepare("UPDATE flash_trades SET status='claimed', claimed_by=?, claimed_at=NOW() WHERE id=?")->execute([$user['id'],$tradeId]);
        auditLog('trade_claimed','marketplace','flash_trades',$tradeId,"Claimed by user {$user['id']}");
        setFlash('success','Trade claimed! Arrange pickup with the seller.');
    } else {
        setFlash('danger','This trade is no longer available.');
    }
    header('Location: trades.php'); exit;
}

// Fn 24: Cancel own trade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_trade'])) {
    $tradeId = (int)$_POST['trade_id'];
    $db->prepare("UPDATE flash_trades SET status='cancelled' WHERE id=? AND seller_id=?")->execute([$tradeId,$user['id']]);
    setFlash('info','Trade cancelled.'); header('Location: trades.php'); exit;
}

// Fn 25: Donate produce (karma points)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['donate'])) {
    $produce  = trim($_POST['produce_name']);
    $qty      = trim($_POST['donate_qty']);
    $spoiled  = isset($_POST['is_spoiled']) ? 1 : 0;
    if ($spoiled) {
        $db->prepare("INSERT INTO donations (donor_id,produce_name,quantity,karma_points_awarded,is_rejected,rejection_reason) VALUES (?,?,?,0,1,'Spoiled or unusable')")
           ->execute([$user['id'],$produce,$qty]);
        setFlash('danger','Donation rejected — spoiled produce cannot be added. No karma points awarded.');
    } else {
        $karma = calculateKarmaPoints($qty);
        $db->prepare("INSERT INTO donations (donor_id,produce_name,quantity,karma_points_awarded) VALUES (?,?,?,?)")
           ->execute([$user['id'],$produce,$qty,$karma]);
        $db->prepare("UPDATE users SET karma_points=karma_points+? WHERE id=?")->execute([$karma,$user['id']]);
        $_SESSION['user']['karma'] = ($_SESSION['user']['karma'] ?? 0) + $karma;
        auditLog('donation_made','marketplace','donations',null,"$produce: +$karma karma");
        setFlash('success',"Thanks for donating $produce! You earned $karma karma points ⭐");
    }
    header('Location: trades.php'); exit;
}

// Fn 28: Submit quality rating
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_trade'])) {
    $tradeId = (int)$_POST['trade_id'];
    $rating  = max(1, min(5, (int)$_POST['rating']));
    $notes   = trim($_POST['rating_notes'] ?? '');
    // Only claimer can rate
    $chk = $db->prepare("SELECT id FROM flash_trades WHERE id=? AND claimed_by=?");
    $chk->execute([$tradeId,$user['id']]);
    if ($chk->fetch()) {
        try {
            $db->prepare("INSERT INTO produce_ratings (trade_id,rater_id,rating,notes) VALUES (?,?,?,?)")
               ->execute([$tradeId,$user['id'],$rating,$notes]);
            setFlash('success','Rating submitted. Thank you!');
        } catch (Exception $e) { setFlash('warning','You have already rated this trade.'); }
    }
    header('Location: trades.php'); exit;
}

// Auto-expire old trades
$db->query("UPDATE flash_trades SET status='expired' WHERE status='active' AND expires_at <= NOW()");

// Load active trades
$activeTrades = $db->query("
    SELECT t.*, u.full_name AS seller_name,
           TIMESTAMPDIFF(MINUTE, NOW(), t.expires_at) AS mins_left
    FROM flash_trades t JOIN users u ON t.seller_id=u.id
    WHERE t.status='active' AND t.expires_at > NOW()
    ORDER BY t.expires_at ASC
")->fetchAll();

// My trades (all statuses)
$myTrades = $db->prepare("
    SELECT t.*, c.full_name AS claimer_name,
           (SELECT AVG(rating) FROM produce_ratings WHERE trade_id=t.id) AS avg_rating
    FROM flash_trades t LEFT JOIN users c ON t.claimed_by=c.id
    WHERE t.seller_id=? ORDER BY t.created_at DESC LIMIT 10
");
$myTrades->execute([$user['id']]); $myTrades = $myTrades->fetchAll();

// Trades I claimed — eligible for rating
$claimedByMe = $db->prepare("
    SELECT t.*, u.full_name AS seller_name,
           (SELECT id FROM produce_ratings WHERE trade_id=t.id AND rater_id=?) AS already_rated
    FROM flash_trades t JOIN users u ON t.seller_id=u.id
    WHERE t.claimed_by=? AND t.status='claimed'
    ORDER BY t.claimed_at DESC LIMIT 5
");
$claimedByMe->execute([$user['id'],$user['id']]); $claimedByMe = $claimedByMe->fetchAll();

// Donation history
$donations = $db->prepare("SELECT * FROM donations WHERE donor_id=? ORDER BY donated_at DESC LIMIT 10");
$donations->execute([$user['id']]); $donations = $donations->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1>🥕 Harvest Marketplace</h1>
    <p>Flash-trade perishable produce, donate for karma points, and rate what you receive.</p>
</div>

<div class="page-actions">
    <button class="btn btn-primary" onclick="toggleSection('post-trade')">+ Post a Trade</button>
    <button class="btn btn-secondary" onclick="toggleSection('donate-form')">❤️ Donate Produce</button>
    <a href="advice.php" class="btn btn-secondary">💬 Advice Board</a>
</div>

<!-- My karma summary -->
<div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:1rem">
    <div class="stat-card">
        <span class="stat-value">⭐ <?= (int)$_SESSION['user']['karma'] ?></span>
        <span class="stat-label">My karma points</span>
    </div>
    <div class="stat-card accent-blue">
        <span class="stat-value"><?= count($activeTrades) ?></span>
        <span class="stat-label">Active trades now</span>
    </div>
    <?php $myDonations = $db->prepare("SELECT COUNT(*),COALESCE(SUM(karma_points_awarded),0) FROM donations WHERE donor_id=? AND is_rejected=0"); $myDonations->execute([$user['id']]); $dRow=$myDonations->fetch(PDO::FETCH_NUM); ?>
    <div class="stat-card accent-amber">
        <span class="stat-value"><?= $dRow[0] ?></span>
        <span class="stat-label">My donations</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= (int)$dRow[1] ?></span>
        <span class="stat-label">Karma earned total</span>
    </div>
</div>

<!-- Rate claimed trades -->
<?php foreach ($claimedByMe as $ct): if (!$ct['already_rated']): ?>
<div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
    <span>You claimed <strong><?= e($ct['title']) ?></strong> from <?= e($ct['seller_name']) ?>. Rate the quality:</span>
    <form method="POST" style="display:flex;gap:.5rem;align-items:center">
        <input type="hidden" name="trade_id" value="<?= $ct['id'] ?>">
        <select name="rating" class="form-control" style="width:auto;height:32px;font-size:13px">
            <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
            <option value="4">⭐⭐⭐⭐ Good</option>
            <option value="3" selected>⭐⭐⭐ Average</option>
            <option value="2">⭐⭐ Poor</option>
            <option value="1">⭐ Very poor</option>
        </select>
        <input type="text" name="rating_notes" class="form-control" style="width:140px" placeholder="Notes...">
        <button name="rate_trade" value="1" class="btn btn-primary btn-sm">Submit Rating</button>
    </form>
</div>
<?php endif; endforeach; ?>

<!-- Post trade form -->
<div id="post-trade" style="display:none" class="card mb-2">
    <div class="card-header">Post a Flash Trade</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>What are you offering?</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. 5kg ripe tomatoes">
                </div>
                <div class="form-group">
                    <label>Quantity / amount</label>
                    <input type="text" name="quantity" class="form-control" placeholder="e.g. 5kg, 3 bunches">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Allergen category (if any)</label>
                    <select name="allergen_category" class="form-control">
                        <option value="">None</option>
                        <?php foreach (ALLERGEN_CATEGORIES as $a): ?><option value="<?= e($a) ?>"><?= e($a) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Expires in (hours)</label>
                    <select name="expiry_hours" class="form-control">
                        <option value="1">1 hour</option>
                        <option value="2" selected>2 hours</option>
                        <option value="4">4 hours</option>
                        <option value="8">8 hours</option>
                        <option value="24">24 hours</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description / pickup instructions</label>
                <textarea name="description" class="form-control" rows="2" placeholder="e.g. Pickup from my plot A-01 before 6pm today..."></textarea>
            </div>
            <button name="create_trade" value="1" class="btn btn-primary">Post Trade</button>
        </form>
    </div>
</div>

<!-- Donation form -->
<div id="donate-form" style="display:none" class="card mb-2">
    <div class="card-header">❤️ Donate Produce (Earn Karma — Fn 25)</div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Produce name</label>
                    <input type="text" name="produce_name" class="form-control" required placeholder="e.g. Courgettes">
                </div>
                <div class="form-group">
                    <label>Quantity (include unit, e.g. 2 kg)</label>
                    <input type="text" name="donate_qty" class="form-control" required placeholder="e.g. 2 kg, 10 apples">
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:normal">
                    <input type="checkbox" name="is_spoiled" value="1">
                    This produce is spoiled or unusable (no karma awarded)
                </label>
            </div>
            <button name="donate" value="1" class="btn btn-primary">Donate &amp; Earn Karma</button>
        </form>
        <p class="form-hint mt-1">You earn <?= KARMA_PER_KG ?> karma points per kg donated.</p>
    </div>
</div>

<!-- Active trades -->
<div class="card mb-2">
    <div class="card-header">🔥 Active Flash Trades</div>
    <?php if ($activeTrades): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Produce</th><th>Qty</th><th>Seller</th><th>Allergen</th><th>Expires in</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($activeTrades as $t):
                $minsLeft = (int)$t['mins_left'];
                $urgent   = $minsLeft <= 30;
            ?>
            <tr>
                <td>
                    <strong><?= e($t['title']) ?></strong>
                    <?php if ($t['description']): ?><br><span class="text-sm text-muted"><?= e(substr($t['description'],0,60)) ?></span><?php endif; ?>
                </td>
                <td><?= e($t['quantity'] ?: '—') ?></td>
                <td><?= e($t['seller_name']) ?></td>
                <td>
                    <?php if ($t['allergen_flag']): ?>
                        <span class="badge badge-warning">⚠️ <?= e($t['allergen_category']) ?></span>
                    <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $urgent?'danger':'warning' ?>">
                        <?= $minsLeft >= 60 ? floor($minsLeft/60).'h '.($minsLeft%60).'m' : $minsLeft.'m' ?>
                    </span>
                </td>
                <td>
                    <?php if ($t['seller_id'] != $user['id']): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                        <button name="claim_trade" value="1" class="btn btn-sm btn-primary"
                                data-confirm="Claim this trade? You agree to collect it promptly.">Claim</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">
                        <button name="cancel_trade" value="1" class="btn btn-sm btn-secondary"
                                data-confirm="Cancel this trade?">Cancel</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="card-body"><p class="text-muted">No active trades right now. Be the first to post!</p></div>
    <?php endif; ?>
</div>

<!-- My trades history -->
<div class="card mb-2">
    <div class="card-header">My Posted Trades</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Qty</th><th>Posted</th><th>Claimed by</th><th>Status</th><th>Rating</th></tr></thead>
            <tbody>
            <?php if ($myTrades): foreach ($myTrades as $t): ?>
            <tr>
                <td><?= e($t['title']) ?></td>
                <td><?= e($t['quantity'] ?: '—') ?></td>
                <td><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
                <td><?= e($t['claimer_name'] ?? '—') ?></td>
                <td><span class="badge badge-<?= ['active'=>'success','claimed'=>'info','expired'=>'secondary','cancelled'=>'secondary'][$t['status']]??'secondary' ?>"><?= e($t['status']) ?></span></td>
                <td><?= $t['avg_rating'] ? str_repeat('⭐',round($t['avg_rating'])).' ('.number_format($t['avg_rating'],1).')' : '—' ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-muted" style="text-align:center;padding:1.5rem">No trades posted yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Donations history -->
<div class="card">
    <div class="card-header">My Donations</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Produce</th><th>Quantity</th><th>Karma awarded</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if ($donations): foreach ($donations as $d): ?>
            <tr>
                <td><?= e($d['produce_name']) ?></td>
                <td><?= e($d['quantity']) ?></td>
                <td><?= $d['is_rejected'] ? '<span class="text-muted">0</span>' : '<span class="badge badge-success">+'.e($d['karma_points_awarded']).'</span>' ?></td>
                <td><?= $d['is_rejected'] ? '<span class="badge badge-danger">Rejected</span>' : '<span class="badge badge-success">Accepted</span>' ?></td>
                <td><?= date('d M Y', strtotime($d['donated_at'])) ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-muted" style="text-align:center;padding:1.5rem">No donations yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>function toggleSection(id){var el=document.getElementById(id);if(el)el.style.display=el.style.display==='none'?'':'none';}</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
