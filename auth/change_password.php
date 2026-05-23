<?php
require_once __DIR__ . '/../config.php';
require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($old_password === '' || $new_password === '' || $confirm_password === '') {
        $errors[] = 'All fields are required.';
    }
    if (strlen($new_password) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }
    if ($new_password !== $confirm_password) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (empty($errors)) {
        try {
            $uid = (int)($_SESSION['user_id']);
            // Fetch current hash
            $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id=?');
            $stmt->execute([$uid]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($old_password, $row['password_hash'])) {
                $errors[] = 'Old password is incorrect.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare('UPDATE users SET password_hash=? WHERE id=?');
                $stmt->execute([$new_hash, $uid]);
                write_activity($conn, 'change_password', 'user', $uid, 'Password updated via change_password');
                $_SESSION['success'] = 'Password changed successfully.';
                header('Location: ' . BASE_PATH . 'index.php');
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to update password: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3 text-center">Change Password</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<div class="d-flex justify-content-center">
<form method="post" class="card p-4 text-center mx-auto" style="max-width: 420px; width: 100%;">
  <div class="mb-3">
    <label class="form-label w-100 text-center">Old Password</label>
    <input type="password" name="old_password" class="form-control text-center mx-auto" required>
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">New Password</label>
    <input type="password" name="new_password" class="form-control text-center mx-auto" required minlength="6">
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Confirm New Password</label>
    <input type="password" name="confirm_password" class="form-control text-center mx-auto" required minlength="6">
  </div>
  <div class="d-flex justify-content-center gap-2">
    <button type="submit" class="btn btn-primary">Change Password</button>
  </div>
</form>
</div>
<div class="mt-2 text-center">
  <a href="<?php echo BASE_PATH; ?>index.php">Back to Dashboard</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
