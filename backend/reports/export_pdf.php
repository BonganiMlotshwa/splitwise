<?php
require_once __DIR__ . '/../config.php';
require_login();

// Optional filters similar to items.php
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'id';
$dir = strtolower(isset($_GET['dir']) ? trim($_GET['dir']) : 'asc');

$sortable = [
  'id','item_name','serial_number','category','description','taken_by','department','date_taken','expected_return_date','date_returned','status'
];
if (!in_array($sort, $sortable, true)) { $sort = 'id'; }
if (!in_array($dir, ['asc','desc'], true)) { $dir = 'asc'; }
// Dompdf autoload (Composer)
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

// Build WHERE and fetch data
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

// Build HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <style>
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 12px 0; }
    .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: top; }
    th { background: #f5f5f5; text-align: left; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
    .bg-available { background: #d1e7dd; color: #0f5132; }
    .bg-checked { background: #fff3cd; color: #664d03; }
  </style>
</head>
<body>
  <h1><?php echo htmlspecialchars(SITE_NAME); ?> — Items Export</h1>
  <div class="meta">Generated: <?php echo date('Y-m-d H:i'); ?></div>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Item Name</th>
        <th>Serial</th>
        <th>Category</th>
        <th>Description</th>
        <th>Taken By</th>
        <th>Department</th>
        <th>Date Taken</th>
        <th>Expected Return</th>
        <th>Date Returned</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10">No items found.</td></tr>
      <?php else: foreach ($rows as $it): ?>
        <tr>
          <td><?php echo (int)$it['id']; ?></td>
          <td><?php echo htmlspecialchars($it['item_name']); ?></td>
          <td><?php
              $sn = trim($it['serial_number'] ?? '');
              if ($sn !== '') {
                  $snU = strtoupper(preg_replace('/\s+/', '', $sn));
                  if (preg_match('/^FTM\d+$/', $snU)) { $snU = preg_replace('/^FTM(\d+)$/','FTM-$1',$snU); }
                  if (!preg_match('/^FTM-\d+$/', $snU)) { $snU = $sn; }
                  echo htmlspecialchars($snU);
              }
          ?></td>
          <td><?php echo htmlspecialchars($it['category'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['taken_by'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['department'] ?? ''); ?></td>
          <td><?php echo $it['date_taken'] ? date('Y-m-d H:i', strtotime($it['date_taken'])) : ''; ?></td>
          <td><?php echo $it['expected_return_date'] ? date('Y-m-d H:i', strtotime($it['expected_return_date'])) : ''; ?></td>
          <td><?php echo ($it['status']==='checked_out') ? '' : ($it['date_returned'] ? date('Y-m-d H:i', strtotime($it['date_returned'])) : ''); ?></td>
          <td>
            <?php if ($it['status'] === 'available'): ?>
              <span class="badge bg-available">Available</span>
            <?php elseif ($it['status'] === 'checked_out'): ?>
              <span class="badge bg-checked">Checked Out</span>
            <?php elseif ($it['status'] === 'permanently_assigned'): ?>
              <span class="badge" style="background: #cff4fc; color: #055160;">Permanently Assigned</span>
            <?php else: ?>
              <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $it['status']))); ?>
            <?php endif; ?>
          </td>
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

$filename = 'items_export_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
