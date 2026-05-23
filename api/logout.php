<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Return whether a user is logged in
    $u = current_user();
    api_json(['logged_in' => (bool)$u['id'], 'user' => $u]);
}

if ($method !== 'POST') {
    api_error('Method Not Allowed', 405, ['allow' => 'GET, POST']);
}

logout_user();
api_json(['ok' => true]);
