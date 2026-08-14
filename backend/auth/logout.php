<?php
require_once __DIR__ . '/../config.php';
// Log logout before session is destroyed so user info is present
if (!empty($_SESSION['user_id'])) {
    write_activity($conn, 'logout', 'user', (int)$_SESSION['user_id'], ['username' => ($_SESSION['user_name'] ?? null)]);
}
logout_user();
$_SESSION['success'] = 'Logged out.';
header('Location: ' . BASE_PATH . 'auth/login.php');
exit;
