<?php
require_once __DIR__ . '/../config.php';
require_login();
include __DIR__ . '/../includes/header.php';

// Fetch permanently assigned items
$sql = "SELECT 
    id,
    date_taken,
    taken_by as employee_name,
    ftm_pin,
    department,
    item_name as device_name,
    serial_number,
    notes
FROM items 
WHERE status = 'permanently_assigned' 
ORDER BY date_taken DESC";

$items = [];
try {
    $stmt = $conn->query($sql);
    $items = $stmt->fetchAll();
} catch (Exception $e) {
    $_SESSION['error'] = 'Error loading permanently assigned items: ' . $e->getMessage();
}

// Extract "Assigned by" from notes
function extract_assigned_by($notes) {
    if (empty($notes)) return 'N/A';
    // Look for "Assigned by: [name]" pattern in notes
    if (preg_match('/Assigned by:\s*([^|]+)/', $notes, $matches)) {
        return trim($matches[1]);
    }
    return 'N/A';
}
?>

<h1 class="h4 mb-3">Permanently Assigned Items</h1>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> These items have been permanently assigned to employees and are not expected to be returned.
</div>

<?php if (empty($items)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> No permanently assigned items found.
    </div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-striped table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Date</th>
                <th>Employee Name</th>
                <th>FTM PIN</th>
                <th>Department</th>
                <th>Device Name</th>
                <th>Serial Number</th>
                <th>Issued By</th>
                <?php if (is_admin()): ?>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo $item['date_taken'] ? date('Y-m-d', strtotime($item['date_taken'])) : 'N/A'; ?></td>
                <td><?php echo htmlspecialchars($item['employee_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['ftm_pin'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['department'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($item['device_name']); ?></td>
                <td><?php echo htmlspecialchars($item['serial_number'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars(extract_assigned_by($item['notes'])); ?></td>
                <?php if (is_admin()): ?>
                <td>
                    <a href="<?php echo BASE_PATH; ?>items/edit_item.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                    <a href="<?php echo BASE_PATH; ?>items/delete_item.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this item?');">Delete</a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="mt-3">
    <p class="text-muted">Total: <?php echo count($items); ?> permanently assigned item(s)</p>
</div>
<?php endif; ?>

<div class="mt-3">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">← Back to Items</a>
    <?php if (is_admin()): ?>
    <a href="<?php echo BASE_PATH; ?>items/assign_permanent.php" class="btn btn-primary">Assign New Item</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
