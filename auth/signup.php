<?php
require_once __DIR__ . '/../config.php';

if (!ALLOW_PUBLIC_SIGNUP) {
    $_SESSION['error'] = 'New account registration is disabled. Contact your IT administrator.';
    header('Location: ' . BASE_PATH . 'auth/login.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($name === '') $errors[] = 'Name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is invalid.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            // Check unique email
            $stmt = $conn->prepare('SELECT id FROM users WHERE email=?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email already registered.';
            }

            if (empty($errors)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $role = 'user';
                $stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)');
                $stmt->execute([$name, $email, $hash, $role]);
                $new_id = $conn->lastInsertId();
                login_user($new_id, $name, $role);
                $_SESSION['success'] = 'Account created. Welcome, ' . $name . '!';
                header('Location: ' . BASE_PATH . 'index.php');
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to create account: ' . $e->getMessage();
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3 text-center">Sign Up</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<div class="d-flex justify-content-center">
<form method="post" class="card p-4 text-center mx-auto" style="max-width: 520px; width: 100%;">
  <div class="mb-3">
    <label class="form-label w-100 text-center">Name</label>
    <input type="text" name="name" class="form-control text-center mx-auto" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Email</label>
    <input type="email" name="email" class="form-control text-center mx-auto" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Password</label>
    <input type="password" name="password" class="form-control text-center mx-auto" required>
  </div>
  <div class="mb-3">
    <label class="form-label w-100 text-center">Confirm Password</label>
    <input type="password" name="password_confirm" class="form-control text-center mx-auto" required>
  </div>
  <div class="d-flex justify-content-center gap-2">
    <button type="submit" class="btn btn-primary">Create Account</button>
  </div>
</form>
</div>
<div class="mt-2 text-center">
  <a href="<?php echo BASE_PATH; ?>auth/login.php">Already have an account? Login</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
