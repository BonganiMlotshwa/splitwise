<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Return current session user info
    api_json(['user' => current_user()]);
}

if ($method !== 'POST') {
    api_error('Method Not Allowed', 405, ['allow' => 'GET, POST']);
}

$body = read_json_body();
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    api_error('Username and password are required.', 422);
}

$stmt = $conn->prepare('SELECT id, name, email, password_hash, role FROM users WHERE name = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password_hash'])) {
    login_user($user['id'], $user['name'], $user['role']);
    // Log successful API login
    write_activity($conn, 'login', 'user', $user['id'], ['username' => $user['name'], 'source' => 'api']);
    api_json(['ok' => true, 'user' => current_user()]);
}

// Log failed API login attempt
write_activity($conn, 'login_failed', 'user', 0, ['username' => $username, 'source' => 'api']);
api_error('Invalid credentials.', 401);
