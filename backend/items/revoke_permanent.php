<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid item ID.';
    header('Location: ' . BASE_PATH . 'items/permanently_assigned.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, item_name, taken_by FROM items WHERE id = ? AND status = 'permanently_assigned'");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    $_SESSION['error'] = 'Item not found or not permanently assigned.';
    header('Location: ' . BASE_PATH . 'items/permanently_assigned.php');
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE items SET
        status = 'available',
        taken_by = NULL,
        ftm_pin = NULL,
        taken_by_user_id = NULL,
        department = NULL,
        date_taken = NULL,
        expected_return_date = NULL,
        updated_at = NOW(),
        updated_by = ?
        WHERE id = ?");
    $stmt->execute([$_SESSION['user_id'] ?? null, $id]);

    write_activity($conn, 'revoke_permanent', 'item', $id, json_encode([
        'item_name' => $item['item_name'],
        'previously_assigned_to' => $item['taken_by'],
    ]));

    $_SESSION['success'] = 'Permanent assignment for "' . htmlspecialchars($item['item_name']) . '" has been revoked. Item is now available.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Failed to revoke assignment: ' . $e->getMessage();
}

header('Location: ' . BASE_PATH . 'items/permanently_assigned.php');
exit;
