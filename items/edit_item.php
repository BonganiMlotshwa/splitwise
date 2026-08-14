<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$errors = [];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid item ID.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

// Load item
$stmt = $conn->prepare('SELECT id, item_name, serial_number, category, description, status, returned FROM items WHERE id=?');
$stmt->execute([$id]);
$item = $stmt->fetch();
if (!$item) {
    $_SESSION['error'] = 'Item not found.';
    header('Location: ' . BASE_PATH . 'items/items.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_name = trim($_POST['item_name'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'available';

    if ($item_name === '') $errors[] = 'Item name is required.';
    if ($status !== 'available' && $status !== 'checked_out') $status = 'available';

    if (empty($errors)) {
        try {
            if ($status === 'checked_out') {
                // If marking as checked out via edit, ensure return date is cleared and returned flag reset
                $sql = "UPDATE items SET item_name=?, serial_number=?, category=?, description=?, status=?, returned=false, date_returned=NULL, updated_at=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$item_name, $serial_number, $category, $description, $status, $id]);
            } else {
                $sql = "UPDATE items SET item_name=?, serial_number=?, category=?, description=?, status=?, updated_at=NOW() WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$item_name, $serial_number, $category, $description, $status, $id]);
            }
            // Log activity
            $details = json_encode([
                'before' => $item,
                'after' => [
                    'item_name' => $item_name,
                    'serial_number' => $serial_number,
                    'category' => $category,
                    'description' => $description,
                    'status' => $status,
                ],
            ]);
            write_activity($conn, 'edit_item', 'item', $id, $details);
            $_SESSION['success'] = 'Item updated successfully!';
            header('Location: ' . BASE_PATH . 'items/items.php');
            exit;
        } catch (Exception $e) {
            $errors[] = "Failed to update: {$e->getMessage()}";
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3">Edit Item #<?php echo $id; ?></h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<form method="post" class="card p-3">
  <?php echo csrf_field(); ?>
  <div class="mb-3">
    <label class="form-label">Item Name *</label>
    <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label">Serial Number</label>
    <input type="text" name="serial_number" class="form-control" value="<?php echo htmlspecialchars($item['serial_number'] ?? ''); ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Category</label>
    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($item['category'] ?? ''); ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Description</label>
    <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($item['description'] ?? ''); ?>" placeholder="e.g., 5m, 10m; Wired/Wireless; Model">
    <div class="form-text">Short descriptor to differentiate similar items (length/spec/model).</div>
  </div>
  <div class="mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="available" <?php echo $item['status']==='available'?'selected':''; ?>>Available</option>
      <option value="checked_out" <?php echo $item['status']==='checked_out'?'selected':''; ?>>Checked Out</option>
    </select>
  </div>
  <div class="d-flex justify-content-end gap-2">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Save Changes</button>
  </div>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
