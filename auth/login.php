<?php
require_once __DIR__ . '/../config.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Username and password are required.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT id, name, email, password_hash, role FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                login_user($user['id'], $user['name'], $user['role']);
                // Log successful login
                write_activity($conn, 'login', 'user', $user['id'], ['username' => $user['name']]);
                $_SESSION['success'] = 'Welcome, ' . $user['name'] . '!';
                $_SESSION['just_logged_in'] = true; // Flag for greeting display
                header('Location: ' . BASE_PATH . 'index.php');
                exit;
            } else {
                $errors[] = 'Invalid credentials.';
                // Log failed login attempt
                write_activity($conn, 'login_failed', 'user', 0, ['username' => $username]);
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3 text-center">Login</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<div class="d-flex justify-content-center">
<form method="post" class="card p-4 text-center mx-auto" style="max-width: 420px; width: 100%;">
  <div class="mb-3">
    <label class="form-label w-100 text-center">Username</label>
    <input type="text" name="username" class="form-control text-center mx-auto" required>
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Password</label>
    <input type="password" name="password" class="form-control text-center mx-auto" required>
  </div>
  <div class="d-flex justify-content-end gap-2">
    <button type="submit" class="btn btn-primary">Login</button>
  </div>
</form>
</div>
<div class="mt-2 text-center">
  <?php if (ALLOW_PUBLIC_SIGNUP): ?>
    <a href="<?php echo BASE_PATH; ?>auth/signup.php">Don't have an account? Sign up</a>
    <span class="mx-2">|</span>
  <?php endif; ?>
  <a href="<?php echo BASE_PATH; ?>auth/request_reset.php">Forgot password?</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
