<?php
// includes/header.php
// Usage: require_once ROOT . '/includes/header.php';
// Expects: $pageTitle (string), $user (from requireLogin or currentUser)
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <!-- Leaflet (plot map) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" defer></script>
</head>
<body>

<nav class="navbar">
    <a class="navbar-brand" href="<?= APP_URL ?>/index.php">🌱 <?= APP_NAME ?></a>
    <div class="navbar-links">
        <?php if ($user): ?>
            <?php $role = $user['role_name']; ?>

            <a href="<?= APP_URL ?>/modules/land/plots.php">Plots</a>
            <a href="<?= APP_URL ?>/modules/resources/tools.php">Tools</a>
            <a href="<?= APP_URL ?>/modules/resources/seeds.php">Seeds</a>
            <a href="<?= APP_URL ?>/modules/volunteer/tasks.php">Volunteer</a>
            <a href="<?= APP_URL ?>/modules/marketplace/trades.php">Marketplace</a>

            <?php if ($role === 'admin'): ?>
                <a href="<?= APP_URL ?>/modules/volunteer/broadcast.php" class="nav-alert">📢 Broadcast</a>
                <a href="<?= APP_URL ?>/modules/land/audit.php">Audit Log</a>
            <?php endif; ?>

            <div class="navbar-user">
                <span class="user-badge role-<?= e($role) ?>"><?= e($role) ?></span>
                <span><?= e($user['full_name']) ?></span>
                <span class="karma-pill">⭐ <?= (int)$user['karma'] ?></span>
                <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-sm btn-outline">Sign out</a>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible">
        <?= e($flash['msg']) ?>
        <button onclick="this.parentElement.remove()">×</button>
    </div>
<?php endif; ?>
