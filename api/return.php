<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    api_error('Method Not Allowed', 405, ['allow' => 'POST']);
}

// Returning an item should be admin-only (matches UI behavior)
require_api_admin();

$b = read_json_body();
$item_id = (int)($b['item_id'] ?? 0);
$condition_returned = trim($b['condition_returned'] ?? '');
$return_notes = trim($b['return_notes'] ?? '');

$errors = [];
if ($item_id <= 0) { $errors[] = 'Please select an item to return.'; }
if ($condition_returned === '') { $errors[] = 'Please select the condition when returned.'; }
if (!empty($errors)) { api_error('Validation failed', 422, ['errors' => $errors]); }

$sql = "UPDATE items SET status='available', returned=1, date_returned=NOW(), condition_returned=?, notes=CASE WHEN notes IS NULL OR notes='' THEN ? ELSE CONCAT(notes,'\n\n[Returned ',NOW(),'] Condition: ',?, IF(?<>'', CONCAT(' | Notes: ', ?), '')) END, taken_by=NULL, taken_by_user_id=NULL, department=NULL, expected_return_date=NULL, updated_at=NOW() WHERE id=? AND status='checked_out'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sssssi', $condition_returned, $return_notes, $condition_returned, $return_notes, $return_notes, $item_id);
if (!($stmt->execute() && $stmt->affected_rows > 0)) {
    $stmt->close();
    api_error('Unable to return the item. Ensure it is currently checked out.', 409);
}
$stmt->close();

$details = json_encode([
    'condition_returned' => $condition_returned,
    'notes' => $return_notes,
]);
write_activity($conn, 'return', 'item', $item_id, $details);

// Return the updated item
$stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned FROM items WHERE id = ?");
$stmt->bind_param('i', $item_id);
$stmt->execute();
$res = $stmt->get_result();
$item = $res->fetch_assoc();
$stmt->close();

api_json(['returned' => $item]);
