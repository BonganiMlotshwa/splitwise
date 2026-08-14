<?php
require_once __DIR__ . '/../config.php';

$errors = [];
$info = '';

// Ensure table exists (PostgreSQL syntax)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        $errors[] = 'Please enter your email or your full name.';
    } else {
        // Find user by email or by exact name match
        $user = null;
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $conn->prepare('SELECT id, name, email FROM users WHERE email=?');
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        } else {
            $stmt = $conn->prepare('SELECT id, name, email FROM users WHERE name = ?');
            $stmt->execute([$identifier]);
            // If multiple users share the same name, pick none to avoid ambiguity
            $users = $stmt->fetchAll();
            if (count($users) === 1) {
                $user = $users[0];
            }
        }

        // To avoid user enumeration, show generic message regardless
        $info = 'If that email exists, we have generated a reset link.';

        if ($user) {
            $user_id = (int)$user['id'];
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            $stmt = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?,?,?)');
            $stmt->execute([$user_id, $token, $expires]);

            // Build absolute link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = rtrim(BASE_PATH, '/');
            $link = $scheme . '://' . $host . $base . '/reset_password.php?token=' . urlencode($token);

            // Try to send email if SMTP is configured
            $sent = false;
            if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
                $subject = SITE_NAME . ' — Password Reset';
                $html = '<p>Hello ' . htmlspecialchars($user['name']) . ',</p>'
                      . '<p>We received a request to reset your password. Click the link below to reset it. This link expires in 60 minutes.</p>'
                      . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>'
                      . '<p>If you did not request this, you can ignore this email.</p>';
                $text = "Hello {$user['name']},\n\nUse the link below to reset your password (expires in 60 minutes):\n{$link}\n\nIf you did not request this, ignore this email.";
                $sent = send_mail($user['email'], $subject, $html, $text);
            }

            if ($sent) {
                $_SESSION['success'] = 'If that email exists, a reset link has been sent.';
            } else {
                // Dev fallback: show link on page
                $_SESSION['success'] = 'Reset link generated. Use it within 60 minutes (shown below for development).';
                $_SESSION['reset_preview_link'] = $link;
            }
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3 text-center">Forgot Password</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<?php if ($info): ?>
  <div class="alert alert-info"><?php echo htmlspecialchars($info); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['reset_preview_link'])): ?>
  <div class="alert alert-secondary">Dev link: <a href="<?php echo $_SESSION['reset_preview_link']; ?>">Reset your password</a></div>
  <?php unset($_SESSION['reset_preview_link']); ?>
<?php endif; ?>
<div class="d-flex justify-content-center">
<form method="post" class="card p-4 text-center mx-auto" style="max-width: 420px; width: 100%;">
  <?php echo csrf_field(); ?>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Email or Full Name</label>
    <input type="text" name="identifier" class="form-control text-center mx-auto" required>
  </div>
  <div class="d-flex justify-content-center gap-2">
    <button type="submit" class="btn btn-primary">Send reset link</button>
  </div>
</form>
</div>
<div class="mt-2 text-center">
  <a href="<?php echo BASE_PATH; ?>auth/login.php">Back to login</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
