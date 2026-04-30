<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

startSession();
if (currentUser()) { redirect('index.php'); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Name, email, and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db   = getDB();
        $chk  = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $gateCode = 'GATE' . strtoupper(substr(md5($email . time()), 0, 6));
            $hash     = password_hash($password, PASSWORD_DEFAULT);
            $stmt     = $db->prepare("
                INSERT INTO users (full_name, email, password_hash, phone, role_id, gate_code)
                VALUES (?, ?, ?, ?, 4, ?)
            ");
            $stmt->execute([$name, $email, $hash, $phone, $gateCode]);
            $success = 'Account created! Your gate code is: <strong>' . e($gateCode) . '</strong>. You can now <a href="login.php">sign in</a>.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">🌱</div>
    <h1>Create Account</h1>
    <p class="auth-sub"><?= APP_NAME ?></p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="">
        <div class="form-group">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" class="form-control"
                   value="<?= e($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" class="form-control"
                   value="<?= e($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone (optional)</label>
            <input type="text" id="phone" name="phone" class="form-control"
                   value="<?= e($_POST['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>
    <?php endif; ?>

    <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
</div>
</body>
</html>
