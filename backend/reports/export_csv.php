<?php
require_once __DIR__ . '/../config.php';
require_login();

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'id';
$dir = strtolower(isset($_GET['dir']) ? trim($_GET['dir']) : 'asc');

// Whitelist sort columns and directions
$sortable = [
  'id','item_name','serial_number','category','description','taken_by','department','date_taken','expected_return_date','date_returned','status'
];
if (!in_array($sort, $sortable, true)) { $sort = 'id'; }
if (!in_array($dir, ['asc','desc'], true)) { $dir = 'asc'; }

$filename = 'items_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
  'ID','Item Name','Serial Number','Category','Description','Status','Taken By','Department',
  'Date Taken','Expected Return','Date Returned','Condition Returned','Notes'
]);

// Build WHERE
$where = [];
$bindTypes = '';
$bindValues = [];
if ($statusFilter === 'available' || $statusFilter === 'checked_out' || $statusFilter === 'permanently_assigned') {
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

$sql = "SELECT id, item_name, serial_number, category, description, status, taken_by, department, date_taken, expected_return_date, date_returned, condition_returned, notes FROM items" . $whereSql . " ORDER BY " . $sort . " " . strtoupper($dir);

$stmt = $conn->prepare($sql);
$stmt->execute($bindValues);
$rows = $stmt->fetchAll();

foreach ($rows as $row) {
    // Format dates for better readability
    $dateTaken = $row['date_taken'] ? date('Y-m-d H:i', strtotime($row['date_taken'])) : '';
    $expectedReturn = $row['expected_return_date'] ? date('Y-m-d H:i', strtotime($row['expected_return_date'])) : '';
    $dateReturned = $row['date_returned'] ? date('Y-m-d H:i', strtotime($row['date_returned'])) : '';
    
    // Format status for better readability
    $status = ucwords(str_replace('_', ' ', $row['status']));
    
    // Clean up serial number formatting
    $serialNumber = $row['serial_number'];
    if ($serialNumber) {
        $snU = strtoupper(preg_replace('/\s+/', '', $serialNumber));
        if (preg_match('/^FTM\d+$/', $snU)) { 
            $serialNumber = preg_replace('/^FTM(\d+)$/', 'FTM-$1', $snU); 
        }
    }
    
    fputcsv($out, [
      $row['id'],
      $row['item_name'],
      $serialNumber,
      $row['category'],
      $row['description'],
      $status,
      $row['taken_by'],
      $row['department'],
      $dateTaken,
      $expectedReturn,
      $dateReturned,
      $row['condition_returned'],
      $row['notes'],
    ]);
}

fclose($out);
exit;
