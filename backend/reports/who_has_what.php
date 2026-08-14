<?php
require_once __DIR__ . '/../config.php';
require_login();
include __DIR__ . '/../includes/header.php';

// Get all checked out items grouped by person
$checkedOutItems = [];
$sql = "SELECT 
    taken_by,
    department,
    COUNT(*) as item_count,
    STRING_AGG(item_name || ' (' || COALESCE(serial_number, 'No Serial') || ')', ', ' ORDER BY item_name) as items_list,
    MIN(date_taken) as first_checkout,
    MAX(date_taken) as last_checkout,
    COUNT(CASE WHEN expected_return_date IS NOT NULL AND DATE(expected_return_date) <= CURRENT_DATE THEN 1 END) as overdue_count
FROM items 
WHERE status = 'checked_out' 
GROUP BY taken_by, department 
ORDER BY item_count DESC, taken_by";

$stmt = $conn->query($sql);
$checkedOutItems = $stmt->fetchAll();

// Get detailed breakdown for selected person
$selectedPerson = $_GET['person'] ?? '';
$personItems = [];
if ($selectedPerson) {
    $stmt = $conn->prepare("SELECT 
        id, item_name, serial_number, category, description, 
        date_taken, expected_return_date,
        CASE WHEN expected_return_date IS NOT NULL AND DATE(expected_return_date) <= CURRENT_DATE THEN true ELSE false END as is_overdue
    FROM items 
    WHERE status = 'checked_out' AND taken_by = ? 
    ORDER BY date_taken DESC");
    $stmt->execute([$selectedPerson]);
    $personItems = $stmt->fetchAll();
}
?>

<style>
.person-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    margin-bottom: 1rem;
}

.person-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.person-card.has-overdue {
    border-left: 4px solid #dc2626;
}

.person-card.normal {
    border-left: 4px solid #059669;
}

.overdue-badge {
    background: linear-gradient(135deg, #dc2626, #ef4444);
}

.normal-badge {
    background: linear-gradient(135deg, #059669, #10b981);
}

.item-detail-card {
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    transition: all 0.2s ease;
}

.item-detail-card:hover {
    background: #e9ecef;
}

.item-detail-card.overdue {
    background: #fef2f2;
    border-left: 3px solid #dc2626;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">
        <i class="bi bi-people me-2"></i>Who Has What - Checked Out Items
    </h1>
    <div class="d-flex gap-2">
        <a href="<?php echo BASE_PATH; ?>items/items.php?status=checked_out" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list me-1"></i>View All Checked Out
        </a>
        <a href="<?php echo BASE_PATH; ?>reports/activity.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history me-1"></i>Activity Log
        </a>
    </div>
</div>

<?php if (empty($checkedOutItems)): ?>
    <div class="alert alert-info text-center">
        <i class="bi bi-info-circle me-2"></i>
        No items are currently checked out.
    </div>
<?php else: ?>

<div class="row">
    <div class="col-lg-6">
        <h5 class="mb-3">
            <i class="bi bi-person-lines-fill me-2"></i>People with Checked Out Items
        </h5>
        
        <?php foreach ($checkedOutItems as $person): ?>
        <div class="card person-card <?php echo $person['overdue_count'] > 0 ? 'has-overdue' : 'normal'; ?>">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="card-title mb-1">
                            <i class="bi bi-person-circle me-2"></i>
                            <?php echo htmlspecialchars($person['taken_by']); ?>
                        </h6>
                        <div class="text-muted small mb-2">
                            <i class="bi bi-building me-1"></i>
                            <?php echo htmlspecialchars($person['department']); ?>
                        </div>
                        <div class="d-flex gap-2 mb-2">
                            <span class="badge <?php echo $person['overdue_count'] > 0 ? 'overdue-badge' : 'normal-badge'; ?>">
                                <?php echo $person['item_count']; ?> item<?php echo $person['item_count'] != 1 ? 's' : ''; ?>
                            </span>
                            <?php if ($person['overdue_count'] > 0): ?>
                            <span class="badge bg-danger">
                                <?php echo $person['overdue_count']; ?> overdue
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-calendar3 me-1"></i>
                            First: <?php echo date('M j, Y', strtotime($person['first_checkout'])); ?>
                            <?php if ($person['first_checkout'] != $person['last_checkout']): ?>
                            | Last: <?php echo date('M j, Y', strtotime($person['last_checkout'])); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <a href="?person=<?php echo urlencode($person['taken_by']); ?>" 
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="col-lg-6">
        <?php if ($selectedPerson): ?>
        <h5 class="mb-3">
            <i class="bi bi-box me-2"></i>Items held by <?php echo htmlspecialchars($selectedPerson); ?>
        </h5>
        
        <?php foreach ($personItems as $item): ?>
        <div class="card item-detail-card <?php echo $item['is_overdue'] ? 'overdue' : ''; ?>">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="card-title mb-1">
                            <?php echo htmlspecialchars($item['item_name']); ?>
                            <?php if ($item['is_overdue']): ?>
                            <span class="badge bg-danger ms-2">OVERDUE</span>
                            <?php endif; ?>
                        </h6>
                        <div class="text-muted small">
                            <div>
                                <i class="bi bi-hash me-1"></i>
                                Serial: <?php echo htmlspecialchars($item['serial_number'] ?: 'N/A'); ?>
                            </div>
                            <div>
                                <i class="bi bi-tag me-1"></i>
                                <?php echo htmlspecialchars($item['category']); ?>
                            </div>
                            <?php if ($item['description']): ?>
                            <div>
                                <i class="bi bi-info-circle me-1"></i>
                                <?php echo htmlspecialchars($item['description']); ?>
                            </div>
                            <?php endif; ?>
                            <div class="mt-2">
                                <i class="bi bi-calendar-check me-1"></i>
                                Taken: <?php echo date('M j, Y g:i A', strtotime($item['date_taken'])); ?>
                            </div>
                            <?php if ($item['expected_return_date']): ?>
                            <div>
                                <i class="bi bi-calendar-x me-1"></i>
                                Expected: <?php echo date('M j, Y g:i A', strtotime($item['expected_return_date'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <a href="<?php echo BASE_PATH; ?>items/return_item.php?item_id=<?php echo $item['id']; ?>" 
                           class="btn btn-sm btn-success">
                            <i class="bi bi-box-arrow-in-down-left me-1"></i>Return
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="mt-3">
            <a href="?" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Overview
            </a>
        </div>
        
        <?php else: ?>
        <div class="alert alert-info text-center">
            <i class="bi bi-hand-index me-2"></i>
            Click "Details" next to a person's name to see what items they have checked out.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>