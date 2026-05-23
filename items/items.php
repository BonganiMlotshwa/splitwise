<?php
require_once __DIR__ . '/../config.php';
require_login();

// Handle bulk delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && is_admin()) {
    $selectedIds = $_POST['selected_items'] ?? [];
    if (!empty($selectedIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $stmt = $conn->prepare("DELETE FROM items WHERE id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $deletedCount = $stmt->rowCount();
            
            write_activity($conn, 'bulk_delete', 'item', 0, "Deleted $deletedCount items: " . implode(',', $selectedIds));
            $_SESSION['success'] = "Successfully deleted $deletedCount item(s)!";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to delete items: ' . $e->getMessage();
        }
        header('Location: ' . BASE_PATH . 'items/items.php');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';

// Parameters
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'id';
$dir = strtolower(isset($_GET['dir']) ? trim($_GET['dir']) : 'desc');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if ($perPage <= 0 || $perPage > 200) { $perPage = 25; }

// Whitelist sort columns and directions
$sortable = [
  'id','item_name','serial_number','category','description','taken_by','department','date_taken','expected_return_date','date_returned','status'
];
if (!in_array($sort, $sortable, true)) { $sort = 'id'; }
if (!in_array($dir, ['asc','desc'], true)) { $dir = 'desc'; }

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
  $where[] = "(
    LOWER(item_name) LIKE LOWER(?) OR 
    LOWER(serial_number) LIKE LOWER(?) OR 
    LOWER(category) LIKE LOWER(?) OR 
    LOWER(description) LIKE LOWER(?) OR 
    LOWER(taken_by) LIKE LOWER(?) OR 
    LOWER(department) LIKE LOWER(?) OR
    CAST(id AS TEXT) LIKE ? OR
    LOWER(notes) LIKE LOWER(?) OR
    LOWER(status) LIKE LOWER(?)
  )";
  $bindTypes .= 'sssssssss';
  $like = '%' . $q . '%';
  array_push($bindValues, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
$whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));

// Count for pagination
$countSql = "SELECT COUNT(*) c FROM items" . $whereSql;
$countStmt = $conn->prepare($countSql);
$countStmt->execute($bindValues);
$totalRows = (int)($countStmt->fetch()['c'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// Fetch items
$sql = "SELECT id, item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned
        FROM items" . $whereSql . " ORDER BY " . $sort . " " . strtoupper($dir) . " LIMIT ? OFFSET ?";

// Bind with dynamic params (+ two integers for limit/offset)
$stmt = $conn->prepare($sql);
$allParams = array_merge($bindValues, [$perPage, $offset]);
$stmt->execute($allParams);
$items = $stmt->fetchAll();

// Check for duplicates (admin only)
$duplicateCount = 0;
$duplicateSets = 0;
if (is_admin()) {
    try {
        $dupSql = "
            SELECT COUNT(*) as sets,
                   SUM(count) - COUNT(*) as total_duplicates
            FROM (
                SELECT COUNT(*) as count
                FROM items
                WHERE serial_number IS NOT NULL 
                  AND serial_number != '' 
                  AND UPPER(serial_number) != 'N/A'
                GROUP BY item_name, serial_number
                HAVING COUNT(*) > 1
            ) as dup_counts
        ";
        $dupStmt = $conn->query($dupSql);
        $dupResult = $dupStmt->fetch();
        $duplicateSets = (int)($dupResult['sets'] ?? 0);
        $duplicateCount = (int)($dupResult['total_duplicates'] ?? 0);
    } catch (Exception $e) {
        // Ignore errors
    }
}
?>
<h1 class="h4 mb-3">Items</h1>

<?php if (is_admin() && $duplicateCount > 0): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong><i class="bi bi-exclamation-triangle"></i> Duplicates Found!</strong>
    <br>
    Found <?= $duplicateSets ?> sets of duplicate items (<?= $duplicateCount ?> duplicate entries total).
    <a href="<?= BASE_PATH ?>items/manage_duplicates.php" class="alert-link">Click here to review and remove duplicates</a>.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
  <div class="btn-group" role="group">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-outline-secondary btn-sm<?php echo $statusFilter===''?' active':''; ?>">All</a>
    <a href="<?php echo BASE_PATH; ?>items/items.php?status=available" class="btn btn-outline-success btn-sm<?php echo $statusFilter==='available'?' active':''; ?>">Available</a>
    <a href="<?php echo BASE_PATH; ?>items/items.php?status=checked_out" class="btn btn-outline-warning btn-sm<?php echo $statusFilter==='checked_out'?' active':''; ?>">Checked Out</a>
    <a href="<?php echo BASE_PATH; ?>items/items.php?status=permanently_assigned" class="btn btn-outline-info btn-sm<?php echo $statusFilter==='permanently_assigned'?' active':''; ?>">Permanently Assigned</a>
  </div>

  <?php if (is_admin()): ?>
  <div class="btn-group" role="group">
    <button type="button" class="btn btn-outline-danger btn-sm" id="bulkDeleteBtn" disabled onclick="confirmBulkDelete()">
      <i class="bi bi-trash"></i> Delete Selected (<span id="selectedCount">0</span>)
    </button>
  </div>
  <?php endif; ?>

  <form class="ms-auto d-flex" method="get" action="<?php echo BASE_PATH; ?>items/items.php" role="search">
    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Search items (name, serial, category, description, person, department, ID, notes, status)..." value="<?php echo htmlspecialchars($q); ?>" />
    <button class="btn btn-sm btn-primary ms-2" type="submit">Search</button>
  </form>
</div>

<form method="post" id="bulkActionForm">
  <input type="hidden" name="bulk_action" value="delete">
<div class="table-responsive">
  <table class="table table-striped table-bordered align-middle" style="white-space: normal; word-break: break-word;">
    <thead>
      <tr>
        <?php if (is_admin()): ?>
        <th class="text-center" style="width: 40px;">
          <input type="checkbox" id="selectAll" title="Select All">
        </th>
        <?php endif; ?>
        <?php
          // Helper to build sort links preserving filters
          function sort_link($label, $col, $currentSort, $currentDir, $statusFilter, $q) {
            $nextDir = ($currentSort === $col && strtolower($currentDir) === 'asc') ? 'desc' : 'asc';
            $params = [
              'sort' => $col,
              'dir' => $nextDir
            ];
            if ($statusFilter !== '') { $params['status'] = $statusFilter; }
            if ($q !== '') { $params['q'] = $q; }
            $qs = http_build_query($params);
            $icon = '';
            if ($currentSort === $col) { $icon = strtolower($currentDir)==='asc' ? '▲' : '▼'; }
            return '<a href="'.BASE_PATH.'items/items.php?'.$qs.'" class="text-decoration-none">'.htmlspecialchars($label).' '.($icon ?: '').'</a>';
          }
        ?>
        <th class="text-center fw-bold"><?php echo sort_link('ID','id',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Item Name','item_name',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Serial','serial_number',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Category','category',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Description','description',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Taken By','taken_by',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Department','department',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Date Taken','date_taken',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Expected Return','expected_return_date',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Date Returned','date_returned',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold"><?php echo sort_link('Status','status',$sort,$dir,$statusFilter,$q); ?></th>
        <th class="text-center fw-bold">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="<?php echo is_admin() ? '13' : '12'; ?>" class="text-center text-muted">No items found.</td></tr>
      <?php else: foreach ($items as $it): ?>
        <tr>
          <?php if (is_admin()): ?>
          <td class="text-center">
            <input type="checkbox" class="item-checkbox" name="selected_items[]" value="<?php echo (int)$it['id']; ?>">
          </td>
          <?php endif; ?>
          <td><?php echo (int)$it['id']; ?></td>
          <td><?php echo htmlspecialchars($it['item_name']); ?></td>
          <td>
            <?php
              $sn = trim($it['serial_number'] ?? '');
              if ($sn !== '') {
                  $snU = strtoupper(preg_replace('/\s+/', '', $sn));
                  if (preg_match('/^FTM\d+$/', $snU)) { $snU = preg_replace('/^FTM(\d+)$/','FTM-$1',$snU); }
                  if (!preg_match('/^FTM-\d+$/', $snU)) { $snU = $sn; } // fallback to original if non-standard
                  echo htmlspecialchars($snU);
              }
            ?>
          </td>
          <td><?php echo htmlspecialchars($it['category'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
          <!-- status moved to end -->
          <td><?php echo htmlspecialchars($it['taken_by'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['department'] ?? ''); ?></td>
          <td><?php echo $it['date_taken'] ? date('Y-m-d H:i', strtotime($it['date_taken'])) : ''; ?></td>
          <td>
            <?php
              $erd = $it['expected_return_date'] ?? '';
              if (!empty($erd) && $erd !== '0000-00-00 00:00:00') {
                  echo date('Y-m-d H:i', strtotime($erd));
              }
            ?>
          </td>
          <td>
            <?php
              $dr = $it['date_returned'] ?? '';
              if (!empty($dr) && $dr !== '0000-00-00 00:00:00') {
                  echo date('Y-m-d H:i', strtotime($dr));
              }
            ?>
          </td>
          <td>
            <?php if ($it['status'] === 'available'): ?>
              <span class="badge text-bg-success">available</span>
            <?php elseif ($it['status'] === 'checked_out'): ?>
              <span class="badge text-bg-warning">checked out</span>
            <?php elseif ($it['status'] === 'permanently_assigned'): ?>
              <span class="badge text-bg-info">permanently assigned</span>
            <?php else: ?>
              <span class="badge text-bg-secondary"><?php echo htmlspecialchars($it['status']); ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (is_admin()): ?>
              <a href="<?php echo BASE_PATH; ?>items/edit_item.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
              <a href="<?php echo BASE_PATH; ?>items/delete_item.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item?');">Delete</a>
            <?php else: ?>
              <span class="text-muted small">No actions</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</form>

<script>
// Select all checkbox functionality
document.getElementById('selectAll')?.addEventListener('change', function() {
  const checkboxes = document.querySelectorAll('.item-checkbox');
  checkboxes.forEach(cb => cb.checked = this.checked);
  updateBulkDeleteButton();
});

// Update button when individual checkboxes change
document.querySelectorAll('.item-checkbox').forEach(cb => {
  cb.addEventListener('change', updateBulkDeleteButton);
});

function updateBulkDeleteButton() {
  const checked = document.querySelectorAll('.item-checkbox:checked');
  const count = checked.length;
  document.getElementById('selectedCount').textContent = count;
  document.getElementById('bulkDeleteBtn').disabled = count === 0;
}

function confirmBulkDelete() {
  const checked = document.querySelectorAll('.item-checkbox:checked');
  const count = checked.length;
  if (count === 0) return;
  
  if (confirm(`Are you sure you want to delete ${count} item(s)? This action cannot be undone!`)) {
    document.getElementById('bulkActionForm').submit();
  }
}
</script>
<?php
  // Pagination controls
  $baseParams = [];
  if ($statusFilter !== '') { $baseParams['status'] = $statusFilter; }
  if ($q !== '') { $baseParams['q'] = $q; }
  if ($sort) { $baseParams['sort'] = $sort; $baseParams['dir'] = $dir; }
  if ($perPage !== 25) { $baseParams['per_page'] = $perPage; }
  $makeUrl = function($p) use ($baseParams) {
    $params = array_merge($baseParams, ['page' => $p]);
    return BASE_PATH . 'items/items.php?' . http_build_query($params);
  };
?>
<div class="d-flex justify-content-between align-items-center mt-2">
  <div class="text-muted small">
    Showing <?php echo $totalRows ? ($offset+1) : 0; ?>–<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?>
  </div>
  <nav>
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
        <a class="page-link" href="<?php echo $makeUrl(max(1,$page-1)); ?>">Prev</a>
      </li>
      <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
      <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
        <a class="page-link" href="<?php echo $makeUrl(min($totalPages,$page+1)); ?>">Next</a>
      </li>
    </ul>
  </nav>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
