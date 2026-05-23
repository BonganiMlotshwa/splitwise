<?php
// API bootstrap: JSON/CORS headers, utilities, and DB/session reuse
// Reuses existing app config and mysqli connection ($conn)

// Make sure sessions are available (config.php also starts session if needed)
require_once __DIR__ . '/../config.php';

// CORS: adjust origins as needed. For local dev, allow all.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Vary: Origin');

// Handle preflight quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// JSON response by default
header('Content-Type: application/json; charset=utf-8');

function api_json($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void {
    $payload = array_merge(['error' => $message], $extra);
    api_json($payload, $status);
}

function require_api_login(): void {
    if (empty($_SESSION['user_id'])) {
        api_error('Unauthorized', 401);
    }
}

function require_api_admin(): void {
    if (!is_admin()) {
        api_error('Forbidden', 403);
    }
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') { return []; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Helper to get query param with default
function qp(string $key, $default = null) {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

// Expose $conn (mysqli) from config.php
// $conn is already defined by config.php
if (!isset($conn) || !($conn instanceof mysqli)) {
    api_error('Database not initialized', 500);
}
