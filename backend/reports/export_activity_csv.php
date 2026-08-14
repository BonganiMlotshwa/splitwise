<?php
require_once __DIR__ . '/../config.php';
require_login();

$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Helper: resolve item name
function resolve_item_name_for_export($conn, $entity_type, $entity_id){
  if (($entity_type ?? '') !== 'item' || empty($entity_id)) return '';
  $id = (int)$entity_id;
  try {
    $stmt = $conn->prepare('SELECT item_name FROM items WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? (string)$row['item_name'] : '';
  } catch (Exception $e) {
    return '';
  }
}

// Helper: format details in user-friendly way
function format_details_for_export(array $e){
  $action = $e['action'] ?? '';
  $raw = $e['details'] ?? '';
  $data = null;
  if (is_array($raw)) { $data = $raw; }
  elseif (is_string($raw)) { $data = json_decode($raw, true); }

  // If not JSON, just return as-is
  if (!is_array($data)) { return (string)$raw; }

  switch ($action) {
    case 'checkout': {
      $taken = $data['taken_by'] ?? null;
      $dept = $data['department'] ?? null;
      $erd  = $data['expected_return_date'] ?? null;
      $parts = [];
      if ($taken) { $parts[] = 'Checked out by ' . $taken; }
      if ($dept)  { $parts[] = 'Department: ' . $dept; }
      if ($erd)   { $parts[] = 'Expected return: ' . date('Y-m-d H:i', strtotime($erd)); }
      return implode('; ', $parts) ?: 'Checked out';
    }
    case 'add_item': {
      $p = [];
      if (!empty($data['serial_number'])) { $p[] = 'Serial: ' . $data['serial_number']; }
      if (!empty($data['category']))      { $p[] = 'Category: ' . $data['category']; }
      if (!empty($data['description']))   { $p[] = 'Description: ' . $data['description']; }
      return 'Added item' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'edit_item': {
      $before = $data['before'] ?? [];
      $after  = $data['after'] ?? [];
      $fields = ['item_name','serial_number','category','description','status','taken_by','department','expected_return_date','date_returned'];
      $changes = [];
      foreach ($fields as $f) {
        $b = is_array($before) ? ($before[$f] ?? null) : null;
        $a = is_array($after)  ? ($after[$f] ?? null)  : null;
        if ($b != $a) {
          if (in_array($f, ['expected_return_date','date_returned'], true)) {
            $b = $b ? date('Y-m-d H:i', strtotime($b)) : $b; 
            $a = $a ? date('Y-m-d H:i', strtotime($a)) : $a;
          }
          $fname = ucwords(str_replace('_',' ',$f));
          $changes[] = $fname . ': ' . ($b ?: 'empty') . ' → ' . ($a ?: 'empty');
        }
      }
      return empty($changes) ? 'Edited item' : ('Edited item - ' . implode('; ', $changes));
    }
    case 'return_item':
    case 'return': {
      $p = [];
      if (!empty($data['condition_returned'])) { $p[] = 'Condition: ' . $data['condition_returned']; }
      if (!empty($data['notes']))              { $p[] = 'Notes: ' . $data['notes']; }
      return 'Returned' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'permanent_assignment': {
      $p = [];
      if (!empty($data['assigned_to'])) { $p[] = 'Assigned to: ' . $data['assigned_to']; }
      if (!empty($data['department'])) { $p[] = 'Department: ' . $data['department']; }
      if (!empty($data['ftm_pin'])) { $p[] = 'FTM PIN: ' . $data['ftm_pin']; }
      if (!empty($data['assigned_by'])) { $p[] = 'Assigned by: ' . $data['assigned_by']; }
      return 'Permanently assigned' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'login': {
      $username = $data['username'] ?? '';
      return 'User logged in' . ($username ? ' - Username: ' . $username : '');
    }
    case 'change_password': {
      return 'Password changed';
    }
    default: {
      // Generic formatting: key=value pairs
      $pairs = [];
      foreach ($data as $k=>$v) {
        if (is_array($v)) continue;
        if (in_array($k, ['expected_return_date','date_returned'], true)) { 
          $v = $v ? date('Y-m-d H:i', strtotime($v)) : $v; 
        }
        $fname = ucwords(str_replace('_',' ',$k));
        $pairs[] = $fname . ': ' . $v;
      }
      return empty($pairs) ? '' : implode('; ', $pairs);
    }
  }
}

$logFile = dirname(__DIR__) . '/logs/activity-' . $date . '.log';
$entries = [];
if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $ln) {
        $row = json_decode($ln, true);
        if (is_array($row)) { $entries[] = $row; }
    }
    usort($entries, function($a,$b){ return strcmp($a['ts'] ?? '', $b['ts'] ?? ''); }); // chronological
    if ($q !== '') {
        $entries = array_values(array_filter($entries, function($e) use ($q){
            $hay = strtolower(json_encode($e));
            return strpos($hay, strtolower($q)) !== false;
        }));
    }
}

$filename = 'activity_' . $date . '_' . date('His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['Timestamp','User','Action','Entity','Item Name','Details','IP Address']);

foreach ($entries as $e) {
    $itemName = resolve_item_name_for_export($conn, $e['entity_type'] ?? '', $e['entity_id'] ?? null);
    $formattedDetails = format_details_for_export($e);
    
    fputcsv($out, [
        $e['ts'] ? date('Y-m-d H:i:s', strtotime($e['ts'])) : '',
        $e['user_name'] ?? '',
        ucwords(str_replace('_', ' ', $e['action'] ?? '')),
        ($e['entity_type'] ?? '') . (isset($e['entity_id']) ? ' #' . $e['entity_id'] : ''),
        $itemName,
        $formattedDetails,
        $e['ip'] ?? '',
    ]);
}

fclose($out);
exit;
