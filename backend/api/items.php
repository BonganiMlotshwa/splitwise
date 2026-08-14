<?php
require_once __DIR__ . '/bootstrap.php';

// Routing by method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Enforce login for all items endpoints
require_api_login();

// POST /api/items -> create (require login)
if ($method === 'POST') {
    require_api_login();
    $b = read_json_body();
    $item_name = trim($b['item_name'] ?? '');
    $serial_number = trim($b['serial_number'] ?? '');
    $category = trim($b['category'] ?? '');
    $description = trim($b['description'] ?? '');
    if ($item_name === '') { api_error('Item name is required.', 422); }
    // Duplicate detection: exact match across primary fields
    $sqlDup = "SELECT id, item_name, serial_number, category, description FROM items
               WHERE item_name = ?
                 AND COALESCE(serial_number,'') = ?
                 AND COALESCE(category,'') = ?
                 AND COALESCE(description,'') = ?
               ORDER BY id DESC
               LIMIT 5";
    $dupStmt = $conn->prepare($sqlDup);
    $sn = $serial_number; $cat = $category; $desc = $description;
    $dupStmt->bind_param('ssss', $item_name, $sn, $cat, $desc);
    $dupStmt->execute();
    $dupRes = $dupStmt->get_result();
    $dups = [];
    while ($row = $dupRes->fetch_assoc()) { $dups[] = $row; }
    $dupStmt->close();
    $confirm = !empty($b['confirm_duplicate']);
    if (!empty($dups) && !$confirm) {
        api_error('Duplicate item exists. Set confirm_duplicate to true to add anyway.', 409, ['duplicates' => $dups]);
    }
    $sql = "INSERT INTO items (item_name, serial_number, category, description, status, returned, created_at) VALUES (?,?,?,?,?,0,NOW())";
    $stmt = $conn->prepare($sql);
    $status = 'available';
    $stmt->bind_param('sssss', $item_name, $serial_number, $category, $description, $status);
    if (!$stmt->execute()) { api_error('Failed to add item', 500, ['detail' => $stmt->error]); }
    $new_id = $stmt->insert_id;
    $stmt->close();
    $details = json_encode([
        'serial_number' => $serial_number,
        'category' => $category,
        'description' => $description,
    ]);
    write_activity($conn, 'add_item', 'item', $new_id, $details);
    // Return created item
    $stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned FROM items WHERE id = ?");
    $stmt->bind_param('i', $new_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $item = $res->fetch_assoc();
    $stmt->close();
    api_json(['created' => $item], 201);
}

// PUT /api/items?id= -> update (require admin)
if ($method === 'PUT' || $method === 'PATCH') {
    require_api_admin();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { api_error('Missing id', 400); }
    // Load before for activity diff
    $stmt = $conn->prepare('SELECT id, item_name, serial_number, category, description, status, returned FROM items WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$before) { api_error('Not Found', 404); }
    $b = read_json_body();
    $item_name = trim($b['item_name'] ?? $before['item_name']);
    $serial_number = trim($b['serial_number'] ?? ($before['serial_number'] ?? ''));
    $category = trim($b['category'] ?? ($before['category'] ?? ''));
    $description = trim($b['description'] ?? ($before['description'] ?? ''));
    $status = $b['status'] ?? $before['status'];
    if ($item_name === '') { api_error('Item name is required.', 422); }
    if ($status !== 'available' && $status !== 'checked_out') { $status = 'available'; }
    if ($status === 'checked_out') {
        $sql = "UPDATE items SET item_name=?, serial_number=?, category=?, description=?, status=?, returned=0, date_returned=NULL, updated_at=NOW() WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssssi', $item_name, $serial_number, $category, $description, $status, $id);
    } else {
        $sql = "UPDATE items SET item_name=?, serial_number=?, category=?, description=?, status=?, updated_at=NOW() WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssssi', $item_name, $serial_number, $category, $description, $status, $id);
    }
    if (!$stmt->execute()) { api_error('Failed to update', 500, ['detail' => $stmt->error]); }
    $stmt->close();
    // Log activity
    $details = json_encode(['before' => $before, 'after' => [
        'item_name' => $item_name,
        'serial_number' => $serial_number,
        'category' => $category,
        'description' => $description,
        'status' => $status,
    ]]);
    write_activity($conn, 'edit_item', 'item', $id, $details);
    // Return updated
    $stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned FROM items WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $item = $res->fetch_assoc();
    $stmt->close();
    api_json(['updated' => $item]);
}

// DELETE /api/items?id= -> delete (require admin)
if ($method === 'DELETE') {
    require_api_admin();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { api_error('Missing id', 400); }
    // Load for activity details
    $stmt = $conn->prepare('SELECT id, item_name FROM items WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) { api_error('Not Found', 404); }
    $stmt = $conn->prepare('DELETE FROM items WHERE id=?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) { api_error('Failed to delete', 500, ['detail' => $stmt->error]); }
    $stmt->close();
    write_activity($conn, 'delete_item', 'item', $id, json_encode(['item_name' => $item['item_name']]));
    api_json(['deleted' => $id]);
}

// If id is provided, return single item
if (isset($_GET['id']) && $_GET['id'] !== '') {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned FROM items WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $item = $res->fetch_assoc();
    $stmt->close();
    if (!$item) { api_error('Not Found', 404); }
    api_json(['item' => $item]);
}

// List with filters/pagination (mirrors items.php behavior)
$statusFilter = qp('status', '');
$q = qp('q', '');
$sort = qp('sort', 'id');
$dir = strtolower(qp('dir', 'desc'));
$page = max(1, (int)qp('page', 1));
$perPage = (int)qp('per_page', 25);
if ($perPage <= 0 || $perPage > 200) { $perPage = 25; }

$sortable = [
    'id','item_name','serial_number','category','description','taken_by','department','date_taken','expected_return_date','date_returned','status'
];
if (!in_array($sort, $sortable, true)) { $sort = 'id'; }
if (!in_array($dir, ['asc','desc'], true)) { $dir = 'desc'; }

$where = [];
$bindTypes = '';
$bindValues = [];
if ($statusFilter === 'available' || $statusFilter === 'checked_out') {
    $where[] = 'status = ?';
    $bindTypes .= 's';
    $bindValues[] = $statusFilter;
}
if ($q !== '') {
    $where[] = "(item_name LIKE ? OR serial_number LIKE ? OR category LIKE ? OR description LIKE ? OR taken_by LIKE ? OR department LIKE ?)";
    $bindTypes .= 'ssssss';
    $like = '%' . $q . '%';
    array_push($bindValues, $like, $like, $like, $like, $like, $like);
}
$whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));

// Count
$countSql = "SELECT COUNT(*) c FROM items" . $whereSql;
$countStmt = $conn->prepare($countSql);
if (!empty($bindValues)) {
    $params = array_merge([$bindTypes], $bindValues);
    $refs = [];
    foreach ($params as $k => &$v) { $refs[$k] = &$v; }
    call_user_func_array([$countStmt, 'bind_param'], $refs);
}
$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$sql = "SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned
        FROM items" . $whereSql . " ORDER BY `" . $conn->real_escape_string($sort) . "` " . strtoupper($dir) . " LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!empty($bindValues)) {
    $extTypes = $bindTypes . 'ii';
    $l = $perPage; $o = $offset;
    $extValues = array_merge($bindValues, [$l, $o]);
    $params = array_merge([$extTypes], $extValues);
    $refs = [];
    foreach ($params as $k => &$v) { $refs[$k] = &$v; }
    call_user_func_array([$stmt, 'bind_param'], $refs);
} else {
    $l = $perPage; $o = $offset;
    $stmt->bind_param('ii', $l, $o);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

api_json([
    'items' => $items,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $totalRows,
        'total_pages' => $totalPages,
    ],
    'sort' => [
        'by' => $sort,
        'dir' => $dir,
    ],
    'filters' => [
        'status' => $statusFilter,
        'q' => $q,
    ],
]);
