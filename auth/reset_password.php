<?php
require_once __DIR__ . '/../config.php';

$errors = [];
$info = '';
$success = '';

// Ensure tokens table exists (PostgreSQL syntax)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
      id SERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      token VARCHAR(128) NOT NULL,
      expires_at TIMESTAMP NOT NULL,
      used BOOLEAN NOT NULL DEFAULT FALSE,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_token ON password_reset_tokens(token)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_user ON password_reset_tokens(user_id)");
} catch (Exception $e) {
    // Table might already exist
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$valid = false;
$user = null;
$tokenRow = null;

if ($token !== '') {
    $stmt = $conn->prepare('SELECT t.id, t.user_id, t.expires_at, t.used, u.name, u.email FROM password_reset_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ?');
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();

    if ($tokenRow) {
        $now = time();
        $exp = strtotime($tokenRow['expires_at'] ?? '');
        if ($tokenRow['used'] === false && $exp !== false && $exp > $now) {
            $valid = true;
        } else {
            $errors[] = 'This reset link is invalid or has expired.';
        }
    } else {
        $errors[] = 'This reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (!$valid) {
        // Already has an error above
    } else {
        if (strlen($password) < 6) { $errors[] = 'Password must be at least 6 characters.'; }
        if ($password !== $password_confirm) { $errors[] = 'Passwords do not match.'; }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            // Update user password
            $uid = (int)$tokenRow['user_id'];

            $stmt = $conn->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $ok1 = $stmt->execute([$hash, $uid]);

            // Mark this token used and optionally invalidate others for this user
            $stmt = $conn->prepare('UPDATE password_reset_tokens SET used=TRUE WHERE id=? OR (user_id=? AND used=FALSE)');
            $tid = (int)$tokenRow['id'];
            $ok2 = $stmt->execute([$tid, $uid]);

            if ($ok1 && $ok2) {
                $_SESSION['success'] = 'Your password has been reset. Please log in with your new password.';
                header('Location: ' . BASE_PATH . 'auth/login.php');
                exit;
            } else {
                $errors[] = 'Failed to reset password. Please try again.';
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3 text-center">Reset Password</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<?php if (!$token): ?>
  <div class="alert alert-warning">This reset link is invalid. Please request a new one.</div>
  <div class="text-center"><a href="<?php echo BASE_PATH; ?>auth/request_reset.php" class="btn btn-outline-primary">Request reset</a></div>
<?php elseif (!$valid && empty($_POST)): ?>
  <div class="alert alert-warning">This reset link is invalid or has expired. Please request a new one.</div>
  <div class="text-center"><a href="<?php echo BASE_PATH; ?>auth/request_reset.php" class="btn btn-outline-primary">Request reset</a></div>
<?php else: ?>
  <div class="d-flex justify-content-center">
    <form method="post" class="card p-4 text-center mx-auto" style="max-width: 420px; width: 100%;">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <div class="mb-3">
        <label class="form-label w-100 text-center">New Password</label>
        <input type="password" name="password" class="form-control text-center mx-auto" required minlength="6">
      </div>
      <div class="mb-3">
        <label class="form-label w-100 text-center">Confirm Password</label>
        <input type="password" name="password_confirm" class="form-control text-center mx-auto" required minlength="6">
      </div>
      <div class="d-flex justify-content-center gap-2">
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
<?php endif; ?>
<div class="mt-2 text-center">
  <a href="<?php echo BASE_PATH; ?>auth/login.php">Back to login</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
