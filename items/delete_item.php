<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid item ID.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

// Load for activity details
$stmt = $conn->prepare('SELECT id, item_name FROM items WHERE id=?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    $_SESSION['error'] = 'Item not found.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

try {
    $stmt = $conn->prepare('DELETE FROM items WHERE id=?');
    $stmt->execute([$id]);
    write_activity($conn, 'delete_item', 'item', $id, json_encode(['item_name' => $item['item_name']]));
    $_SESSION['success'] = 'Item deleted.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Failed to delete item: ' . $e->getMessage();
}

header('Location: ' . BASE_PATH . 'items/items.php');
exit;
