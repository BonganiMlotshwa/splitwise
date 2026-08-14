<?php
require_once __DIR__ . '/../config.php';
require_login();

$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    header('Content-Type: text/plain');
    http_response_code(500);
    echo "Dompdf is not installed. Please run Composer to install dompdf/dompdf.\n";
    echo "Example: composer require dompdf/dompdf\n";
    exit;
}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// Helper: resolve item name
function resolve_item_name_for_export_pdf($conn, $entity_type, $entity_id){
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
function format_details_for_export_pdf(array $e){
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
      if ($dept)  { $parts[] = 'Dept: ' . $dept; }
      if ($erd)   { $parts[] = 'Expected return: ' . date('Y-m-d H:i', strtotime($erd)); }
      return implode('; ', $parts) ?: 'Checked out';
    }
    case 'add_item': {
      $p = [];
      if (!empty($data['serial_number'])) { $p[] = 'SN: ' . $data['serial_number']; }
      if (!empty($data['category']))      { $p[] = 'Cat: ' . $data['category']; }
      if (!empty($data['description']))   { $p[] = 'Desc: ' . $data['description']; }
      return 'Added item' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'edit_item': {
      $before = $data['before'] ?? [];
      $after  = $data['after'] ?? [];
      $fields = ['item_name','serial_number','category','description','status','taken_by','department'];
      $changes = [];
      foreach ($fields as $f) {
        $b = is_array($before) ? ($before[$f] ?? null) : null;
        $a = is_array($after)  ? ($after[$f] ?? null)  : null;
        if ($b != $a) {
          $fname = ucwords(str_replace('_',' ',$f));
          $changes[] = $fname . ': ' . ($b ?: 'empty') . ' → ' . ($a ?: 'empty');
        }
      }
      return empty($changes) ? 'Edited item' : ('Edited - ' . implode('; ', array_slice($changes, 0, 2)));
    }
    case 'return_item':
    case 'return': {
      $p = [];
      if (!empty($data['condition_returned'])) { $p[] = 'Condition: ' . $data['condition_returned']; }
      if (!empty($data['notes']))              { $p[] = 'Notes: ' . substr($data['notes'], 0, 30) . (strlen($data['notes']) > 30 ? '...' : ''); }
      return 'Returned' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'permanent_assignment': {
      $p = [];
      if (!empty($data['assigned_to'])) { $p[] = 'To: ' . $data['assigned_to']; }
      if (!empty($data['department'])) { $p[] = 'Dept: ' . $data['department']; }
      if (!empty($data['assigned_by'])) { $p[] = 'By: ' . $data['assigned_by']; }
      return 'Permanently assigned' . (empty($p) ? '' : ' - ' . implode('; ', $p));
    }
    case 'login': {
      return 'User logged in';
    }
    case 'change_password': {
      return 'Password changed';
    }
    default: {
      // Generic formatting: key=value pairs (truncated for PDF)
      $pairs = [];
      foreach ($data as $k=>$v) {
        if (is_array($v)) continue;
        $fname = ucwords(str_replace('_',' ',$k));
        $pairs[] = $fname . ': ' . substr($v, 0, 20) . (strlen($v) > 20 ? '...' : '');
        if (count($pairs) >= 2) break; // Limit for PDF space
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
    usort($entries, function($a,$b){ return strcmp($a['ts'] ?? '', $b['ts'] ?? ''); });
    if ($q !== '') {
        $entries = array_values(array_filter($entries, function($e) use ($q){
            $hay = strtolower(json_encode($e));
            return strpos($hay, strtolower($q)) !== false;
        }));
    }
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <style>
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #222; }
    h1 { font-size: 16px; margin: 0 0 10px 0; }
    .meta { font-size: 10px; color: #555; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 5px 6px; vertical-align: top; }
    th { background: #f5f5f5; text-align: center; font-weight: bold; }
  </style>
</head>
<body>
  <h1><?php echo htmlspecialchars(SITE_NAME); ?> — Activity Log (<?php echo htmlspecialchars($date); ?>)</h1>
  <div class="meta">Generated: <?php echo date('Y-m-d H:i'); ?><?php echo $q!=='' ? (' — Filter: ' . htmlspecialchars($q)) : ''; ?></div>
  <table>
    <thead>
      <tr>
        <th>Timestamp</th>
        <th>User</th>
        <th>Action</th>
        <th>Item</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($entries)): ?>
        <tr><td colspan="5">No entries.</td></tr>
      <?php else: foreach ($entries as $e): ?>
        <tr>
          <td><?php echo $e['ts'] ? date('Y-m-d H:i', strtotime($e['ts'])) : ''; ?></td>
          <td><?php echo htmlspecialchars($e['user_name'] ?? 'User'); ?></td>
          <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $e['action'] ?? ''))); ?></td>
          <td><?php echo htmlspecialchars(resolve_item_name_for_export_pdf($conn, $e['entity_type'] ?? null, $e['entity_id'] ?? null)); ?></td>
          <td style="font-size: 10px;"><?php echo htmlspecialchars(format_details_for_export_pdf($e)); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'activity_' . $date . '_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
