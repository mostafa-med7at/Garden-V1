<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

startSession();
if (currentUser()) { redirect('index.php'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT u.*, r.name AS role_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id'         => $user['id'],
                'full_name'  => $user['full_name'],
                'email'      => $user['email'],
                'role_id'    => $user['role_id'],
                'role_name'  => $user['role_name'],
                'membership' => $user['membership_status'],
                'karma'      => $user['karma_points'],
                'points'     => $user['community_points'],
                'credits'    => $user['seed_bank_credits'],
            ];
            loadPermissions($user['role_id']);
            auditLog('login', 'auth', 'users', $user['id'], 'User logged in');
            redirect('index.php');
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">🌱</div>
    <h1><?= APP_NAME ?></h1>
    <p class="auth-sub">Sign in to your garden account</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" class="form-control"
                   value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>

    <p class="auth-footer">
        Don't have an account? <a href="<?= APP_URL ?>/auth/register.php">Register</a>
    </p>

    <div class="demo-creds">
        <strong>Demo accounts</strong><br>
        admin@garden.com / password<br>
        warden@garden.com / password<br>
        alice@garden.com / password (plot owner)<br>
        bob@garden.com / password (member)
    </div>
</div>
</body>
</html>
