<?php
require_once __DIR__ . '/../config.php';
require_login();

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid handover ID']);
    exit;
}

$handoverId = (int)$_GET['id'];

// Use main database connection
global $conn;
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    // Get handover data
    $stmt = $conn->prepare("SELECT * FROM handovers WHERE id = ?");
    $stmt->execute([$handoverId]);
    $handover = $stmt->fetch();
    
    if (!$handover) {
        echo json_encode(['success' => false, 'error' => 'Handover not found']);
        exit;
    }
    
    // Get devices for this handover
    $stmt = $conn->prepare("SELECT device_name, serial_number FROM handover_devices WHERE handover_id = ? ORDER BY id");
    $stmt->execute([$handoverId]);
    $devices = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'handover' => $handover,
        'devices' => $devices
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>