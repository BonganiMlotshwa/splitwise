<?php
require_once '../config.php';
require_login();

// Simple API endpoint for testing
header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'message' => 'FTM IT Property Management API',
    'user' => current_user(),
    'timestamp' => date('c')
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>