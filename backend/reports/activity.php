<?php
require_once __DIR__ . '/../config.php';
require_login();

// Handle download request
if (isset($_GET['download'])) {
    $downloadDate = trim($_GET['download']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $downloadDate)) {
        $logDir = dirname(__DIR__) . '/logs';
        $downloadFile = $logDir . '/activity-' . $downloadDate . '.log';
        if (is_file($downloadFile)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="activity-' . $downloadDate . '.log"');
            header('Content-Length: ' . filesize($downloadFile));
            readfile($downloadFile);
            exit;
        }
    }
    // If download fails, redirect back
    header('Location: ' . BASE_PATH . 'reports/activity.php');
    exit;
}

include __DIR__ . '/../includes/header.php';

// Inputs
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

// Resolve item name by ID with simple in-request cache
function resolve_item_name($conn, $entity_type, $entity_id){
  if (($entity_type ?? '') !== 'item' || empty($entity_id)) return '';
  static $cache = [];
  $id = (int)$entity_id;
  if (isset($cache[$id])) return $cache[$id];
  try {
    $stmt = $conn->prepare('SELECT item_name FROM items WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $name = $row ? (string)$row['item_name'] : '';
    $cache[$id] = $name;
    return $name;
  } catch (Exception $e) {
    return '';
  }
}
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if ($perPage <= 0 || $perPage > 500) { $perPage = 50; }

// Use the same path as config.php uses for logs
$logDir = dirname(__DIR__) . '/logs';
$logFile = $logDir . '/activity-' . $date . '.log';
$entries = [];
$totalRows = 0;

if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    
    // Parse JSON lines to arrays, ignore bad lines
    foreach ($lines as $ln) {
        $row = json_decode($ln, true);
        if (is_array($row)) { $entries[] = $row; }
    }
    
    // Reverse chronological (latest first)
    usort($entries, function($a,$b){ return strcmp($b['ts'] ?? '', $a['ts'] ?? ''); });
    // Optional text filter against concatenated fields
    if ($q !== '') {
        $entries = array_values(array_filter($entries, function($e) use ($q){
            $hay = strtolower(json_encode($e));
            return strpos($hay, strtolower($q)) !== false;
        }));
    }
    $totalRows = count($entries);
    $offset = ($page - 1) * $perPage;
    $entries = array_slice($entries, $offset, $perPage);
} else {
    $entries = [];
    $totalRows = 0;
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_dt($v){
  if (!$v) return '';
  $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
  if ($ts === false) return (string)$v;
  return date('Y-m-d H:i', $ts);
}
function render_details(array $e){
  $action = $e['action'] ?? '';
  $raw = $e['details'] ?? '';
  $data = null;
  if (is_array($raw)) { $data = $raw; }
  elseif (is_string($raw)) { $data = json_decode($raw, true); }

  // If not JSON, just return as-is (escaped later)
  if (!is_array($data)) { return h((string)$raw); }

  // Helpers
  $kv = function($k){ return ucwords(str_replace('_',' ',$k)); };

  switch ($action) {
    case 'checkout': {
      $taken = $data['taken_by'] ?? null;
      $dept = $data['department'] ?? null;
      $erd  = $data['expected_return_date'] ?? null;
      $parts = [];
      if ($taken) { $parts[] = 'Checked out by ' . h($taken); }
      if ($dept)  { $parts[] = '(Dept: ' . h($dept) . ')'; }
      if ($erd)   { $parts[] = 'Expected return: ' . h(fmt_dt($erd)); }
      return implode(' ', $parts) ?: 'Checked out';
    }
    case 'add_item': {
      $p = [];
      if (!empty($data['serial_number'])) { $p[] = 'SN ' . h($data['serial_number']); }
      if (!empty($data['category']))      { $p[] = 'Category ' . h($data['category']); }
      if (!empty($data['description']))   { $p[] = 'Description ' . h($data['description']); }
      return 'Added item' . (empty($p) ? '' : ': ' . implode(', ', $p));
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
            $b = $b ? fmt_dt($b) : $b; $a = $a ? fmt_dt($a) : $a;
          }
          $changes[] = h($kv($f)) . ': ' . h((string)$b) . ' → ' . h((string)$a);
        }
      }
      return empty($changes) ? 'Edited item' : ('Edited item — ' . implode('; ', $changes));
    }
    case 'return_item':
    case 'return': {
      $p = [];
      if (!empty($data['condition_returned'])) { $p[] = 'Condition: ' . h($data['condition_returned']); }
      if (!empty($data['notes']))              { $p[] = 'Notes: ' . h($data['notes']); }
      return 'Returned' . (empty($p) ? '' : ' — ' . implode('; ', $p));
    }
    default: {
      // Generic one-liner: key=value pairs
      $pairs = [];
      foreach ($data as $k=>$v) {
        if (is_array($v)) continue;
        if (in_array($k, ['expected_return_date','date_returned'], true)) { $v = fmt_dt($v); }
        $pairs[] = h($kv($k)) . '=' . h((string)$v);
      }
      return empty($pairs) ? '' : implode(', ', $pairs);
    }
  }
}
?>
<h1 class="h4 mb-3">Activity Log</h1>

<form class="row g-2 align-items-end mb-3" method="get" action="<?php echo BASE_PATH; ?>reports/activity.php">
  <div class="col-auto">
    <label class="form-label">Date</label>
    <input type="date" class="form-control" name="date" value="<?php echo h($date); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label">Search</label>
    <input type="text" class="form-control" name="q" placeholder="Filter text..." value="<?php echo h($q); ?>">
  </div>
  <div class="col-auto">
    <label class="form-label">Per Page</label>
    <select class="form-select" name="per_page">
      <?php foreach ([25,50,100,200,500] as $pp): ?>
        <option value="<?php echo $pp; ?>" <?php echo $perPage==$pp?'selected':''; ?>><?php echo $pp; ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-primary" type="submit">Apply</button>
  </div>
  <div class="col-auto ms-auto">
    <?php if (is_file($logFile)): ?>
      <a class="btn btn-outline-secondary" href="<?php echo BASE_PATH; ?>reports/activity.php?download=<?php echo h($date); ?>" download>Download Raw</a>
    <?php endif; ?>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-striped align-middle">
    <thead>
      <tr>
        <th style="width: 200px;">Timestamp</th>
        <th>User</th>
        <th>Action</th>
        <th>Entity</th>
        <th>Item</th>
        <th>Details</th>
        <th class="text-muted">IP</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($entries)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No entries for this selection.</td></tr>
      <?php else: foreach ($entries as $e): ?>
        <tr>
          <td><?php echo h($e['ts'] ?? ''); ?></td>
          <td><?php echo h(($e['user_name'] ?? 'User') . (isset($e['user_id']) ? " (#{$e['user_id']})" : '')); ?></td>
          <td><span class="badge text-bg-secondary"><?php echo h($e['action'] ?? ''); ?></span></td>
          <td><?php echo h(($e['entity_type'] ?? '') . (isset($e['entity_id']) ? " #{$e['entity_id']}" : '')); ?></td>
          <td><?php echo h(resolve_item_name($conn, $e['entity_type'] ?? null, $e['entity_id'] ?? null)); ?></td>
          <td class="small text-wrap" style="max-width: 520px; white-space: normal; "><?php echo render_details($e); ?></td>
          <td class="text-muted small"><?php echo h($e['ip'] ?? ''); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

<?php
// Pagination links
$base = [ 'date' => $date, 'q' => $q === '' ? null : $q, 'per_page' => $perPage ];
$build = function($p) use ($base){
  $params = array_filter(array_merge($base, ['page' => $p]), function($v){ return $v !== null && $v !== ''; });
  return BASE_PATH . 'reports/activity.php?' . http_build_query($params);
};
?>
<div class="d-flex justify-content-between align-items-center mt-2">
  <div class="text-muted small">Showing <?php echo $totalRows ? ((($page-1)*$perPage)+1) : 0; ?>–<?php echo min($page*$perPage, $totalRows); ?> of <?php echo $totalRows; ?></div>
  <nav>
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo $build(max(1,$page-1)); ?>">Prev</a></li>
      <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
      <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>"><a class="page-link" href="<?php echo $build(min($totalPages,$page+1)); ?>">Next</a></li>
    </ul>
  </nav>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
