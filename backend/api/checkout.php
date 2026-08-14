<?php
require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    api_error('Method Not Allowed', 405, ['allow' => 'POST']);
}

require_api_login();

$b = read_json_body();
$item_id = (int)($b['item_id'] ?? 0);
$taken_by = trim($b['taken_by'] ?? '');
$department = trim($b['department'] ?? '');
$expected_return_date = trim($b['expected_return_date'] ?? ''); // optional

$errors = [];
if ($item_id <= 0) { $errors[] = 'Please select an item.'; }
if ($taken_by === '') { $errors[] = 'Please enter who is taking the item.'; }
$allowedDepartments = ['IT','ERP'];
if ($department === '' || !in_array($department, $allowedDepartments, true)) {
    $errors[] = 'Please select a valid department (IT or ERP).';
}
if (!empty($errors)) { api_error('Validation failed', 422, ['errors' => $errors]); }

if ($expected_return_date === '') {
    $sql = "UPDATE items SET status='checked_out', returned=0, taken_by=?, department=?, date_taken=NOW(), expected_return_date=NULL, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $taken_by, $department, $item_id);
} else {
    $sql = "UPDATE items SET status='checked_out', returned=0, taken_by=?, department=?, date_taken=NOW(), expected_return_date=?, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssi', $taken_by, $department, $expected_return_date, $item_id);
}

if (!($stmt->execute() && $stmt->affected_rows > 0)) {
    $stmt->close();
    api_error('Unable to check out the item. Ensure it is available.', 409);
}
$stmt->close();

$details = json_encode([
    'taken_by' => $taken_by,
    'department' => $department,
    'expected_return_date' => $expected_return_date,
]);
write_activity($conn, 'checkout', 'item', $item_id, $details);

// Return updated item
$stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned FROM items WHERE id = ?");
$stmt->bind_param('i', $item_id);
$stmt->execute();
$res = $stmt->get_result();
$item = $res->fetch_assoc();
$stmt->close();

api_json(['checked_out' => $item]);
