<?php
require_once __DIR__ . '/../config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid item ID.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, item_name, serial_number, category, description, status FROM items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
    $_SESSION['error'] = 'Item not found.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

// Load activity log from DB first, then supplement with JSON flat-file log
$dbLogs = [];
try {
    $ls = $conn->prepare(
        "SELECT actor_user_id, action, entity_type, entity_id, details, created_at
         FROM activity_log
         WHERE entity_type = 'item' AND entity_id = ?
         ORDER BY created_at DESC
         LIMIT 200"
    );
    $ls->execute([$id]);
    $dbLogs = $ls->fetchAll();
} catch (Throwable $e) { /* activity_log table may not exist */ }

// Also scan flat JSON log files for entries matching this item
$jsonLogs = [];
$logDir = __DIR__ . '/../logs';
if (is_dir($logDir)) {
    foreach (glob($logDir . '/activity_*.json') as $logFile) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) continue;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) continue;
            $eId = (int)($entry['entity_id'] ?? $entry['item_id'] ?? 0);
            if ($eId !== $id) continue;
            $jsonLogs[] = $entry;
        }
    }
    usort($jsonLogs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
}

include __DIR__ . '/../includes/header.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Item History: <?php echo h($item['item_name']); ?></h1>
  <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary btn-sm">← Back to Items</a>
</div>

<div class="card mb-3 p-3">
  <div class="row g-2 small">
    <div class="col-auto"><span class="text-muted">ID:</span> #<?php echo $id; ?></div>
    <?php if ($item['serial_number']): ?><div class="col-auto"><span class="text-muted">Serial:</span> <?php echo h($item['serial_number']); ?></div><?php endif; ?>
    <?php if ($item['category']): ?><div class="col-auto"><span class="text-muted">Category:</span> <?php echo h($item['category']); ?></div><?php endif; ?>
    <?php if ($item['description']): ?><div class="col-auto"><span class="text-muted">Description:</span> <?php echo h($item['description']); ?></div><?php endif; ?>
    <div class="col-auto"><span class="text-muted">Status:</span> <span class="badge bg-<?php echo $item['status'] === 'available' ? 'success' : ($item['status'] === 'checked_out' ? 'warning text-dark' : 'secondary'); ?>"><?php echo h($item['status']); ?></span></div>
  </div>
</div>

<?php if (!empty($dbLogs)): ?>
<h5>Activity Log (Database)</h5>
<div class="table-responsive mb-4">
  <table class="table table-sm table-striped">
    <thead class="table-dark"><tr><th>Date/Time</th><th>Action</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($dbLogs as $log): ?>
      <tr>
        <td class="text-nowrap"><?php echo h($log['created_at']); ?></td>
        <td><code><?php echo h($log['action']); ?></code></td>
        <td><small><?php
          $d = $log['details'];
          if (is_string($d)) {
              $decoded = json_decode($d, true);
              echo $decoded ? h(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : h($d);
          } else {
              echo h((string)$d);
          }
        ?></small></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif (empty($jsonLogs)): ?>
<div class="alert alert-info">No activity records found for this item.</div>
<?php endif; ?>

<?php if (!empty($jsonLogs)): ?>
<h5>Activity Log (File-based)</h5>
<div class="table-responsive">
  <table class="table table-sm table-striped">
    <thead class="table-dark"><tr><th>Timestamp</th><th>Action</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($jsonLogs as $entry): ?>
      <tr>
        <td class="text-nowrap"><?php echo h($entry['timestamp'] ?? ''); ?></td>
        <td><code><?php echo h($entry['action'] ?? $entry['event'] ?? ''); ?></code></td>
        <td><small><?php
          unset($entry['timestamp'], $entry['action'], $entry['event'], $entry['entity_id'], $entry['item_id'], $entry['entity_type']);
          echo h(json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ?></small></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
