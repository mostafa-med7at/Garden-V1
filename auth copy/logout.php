<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
startSession();
if ($u = currentUser()) {
    auditLog('logout', 'auth', 'users', $u['id'], 'User logged out');
}
session_destroy();
header('Location: ' . APP_URL . '/auth/login.php');
exit;
