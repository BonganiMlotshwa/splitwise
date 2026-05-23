<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$action = $_GET['action'] ?? 'view';
$success = '';
$error = '';

// Find duplicates
$duplicates = [];
try {
    $sql = "
        SELECT 
            item_name,
            serial_number,
            COUNT(*) as count,
            STRING_AGG(id::text, ',' ORDER BY id) as ids
        FROM items
        WHERE serial_number IS NOT NULL 
          AND serial_number != '' 
          AND UPPER(serial_number) != 'N/A'
        GROUP BY item_name, serial_number
        HAVING COUNT(*) > 1
        ORDER BY count DESC, item_name
    ";
    $stmt = $conn->query($sql);
    $duplicates = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error finding duplicates: ' . $e->getMessage();
}

// Delete duplicates (keep oldest)
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleted = 0;
    try {
        foreach ($duplicates as $dup) {
            $ids = explode(',', $dup['ids']);
            // Keep the first (oldest) ID, delete the rest
            $keepId = array_shift($ids);
            
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare("DELETE FROM items WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $deleted += count($ids);
            }
        }
        
        write_activity($conn, 'delete_duplicates', 'item', 0, "Deleted $deleted duplicate items");
        $_SESSION['success'] = "Successfully deleted $deleted duplicate items!";
        header('Location: ' . BASE_PATH . 'items/manage_duplicates.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error deleting duplicates: ' . $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<h1 class="h4 mb-3">Manage Duplicate Items</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h5 class="card-title">About Duplicates</h5>
        <p class="card-text">
            This tool finds items with the same <strong>Item Name</strong> and <strong>Serial Number</strong>.
            When duplicates are found, the oldest entry (lowest ID) will be kept, and newer duplicates will be deleted.
        </p>
        <p class="card-text">
            <strong>Note:</strong> Items with serial number "N/A" or empty serial numbers are excluded from duplicate detection,
            as these typically represent different physical items.
        </p>
    </div>
</div>

<?php if (empty($duplicates)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> No duplicate items found! Your database is clean.
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <strong>Found <?= count($duplicates) ?> sets of duplicate items</strong>
        <br>Total duplicate entries: <?= array_sum(array_column($duplicates, 'count')) - count($duplicates) ?>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Serial Number</th>
                    <th>Count</th>
                    <th>IDs</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($duplicates as $dup): ?>
                    <?php
                    $ids = explode(',', $dup['ids']);
                    $keepId = $ids[0];
                    $deleteIds = array_slice($ids, 1);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($dup['item_name']) ?></td>
                        <td><?= htmlspecialchars($dup['serial_number'] ?? '(none)') ?></td>
                        <td><span class="badge bg-warning"><?= $dup['count'] ?></span></td>
                        <td>
                            <span class="badge bg-success" title="Will be kept"><?= $keepId ?></span>
                            <?php foreach ($deleteIds as $delId): ?>
                                <span class="badge bg-danger" title="Will be deleted"><?= $delId ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <a href="<?= BASE_PATH ?>items/items.php?q=<?= urlencode($dup['item_name']) ?>" 
                               class="btn btn-sm btn-outline-primary" target="_blank">
                                View Items
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <h5 class="card-title text-danger">Delete All Duplicates</h5>
            <p class="card-text">
                This will delete <strong><?= array_sum(array_column($duplicates, 'count')) - count($duplicates) ?> duplicate items</strong>.
                The oldest entry (lowest ID) for each duplicate set will be kept.
            </p>
            <form method="POST" action="<?= BASE_PATH ?>items/manage_duplicates.php?action=delete" 
                  onsubmit="return confirm('Are you sure you want to delete all duplicate items? This cannot be undone!');">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Delete All Duplicates
                </button>
                <a href="<?= BASE_PATH ?>items/items.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="mt-3">
    <a href="<?= BASE_PATH ?>items/items.php" class="btn btn-outline-secondary">← Back to Items</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
