<?php
require_once __DIR__ . '/../config.php';
require_login();

// Use main database connection
global $conn;

// Handle form submission for adding new application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_application') {
    $application_ref_no = strtoupper(trim($_POST['application_ref_no'] ?? ''));
    $applied_date = trim($_POST['applied_date'] ?? '');
    $applicant_name = strtoupper(trim($_POST['applicant_name'] ?? ''));
    $dept = strtoupper(trim($_POST['dept'] ?? ''));
    $ftm_pin = strtoupper(trim($_POST['ftm_pin'] ?? ''));
    $item_name = strtoupper(trim($_POST['item_name'] ?? ''));
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $job_card_no = strtoupper(trim($_POST['job_card_no'] ?? ''));
    $status_tracking = trim($_POST['status_tracking'] ?? 'pending');
    $urgency = trim($_POST['urgency'] ?? 'normal');
    $allocation_date = trim($_POST['allocation_date'] ?? '') ?: null;
    $purpose = trim($_POST['purpose'] ?? '');
    
    $errors = [];
    if (empty($application_ref_no)) $errors[] = 'Reference number is required';
    if (empty($applied_date)) $errors[] = 'Application date is required';
    if (empty($applicant_name)) $errors[] = 'Applicant name is required';
    if (empty($dept)) $errors[] = 'Department is required';
    if (empty($item_name)) $errors[] = 'Item name is required';
    
    // Check if reference number already exists
    if (!empty($application_ref_no)) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM applications WHERE application_ref_no = ?");
        $stmt->execute([$application_ref_no]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Reference number already exists. Please use a different reference number.';
        }
    }
    
    if (empty($errors)) {
        try {
            // Insert application record
            $stmt = $conn->prepare("
                INSERT INTO applications (application_ref_no, applied_date, applicant_name, dept, ftm_pin, 
                                        job_card_no, item_name, quantity, purpose, urgency, status_tracking, allocation_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$application_ref_no, $applied_date, $applicant_name, $dept, $ftm_pin, 
                           $job_card_no, $item_name, $quantity, $purpose, $urgency, $status_tracking, $allocation_date]);
            
            $_SESSION['success'] = 'Application ' . $application_ref_no . ' submitted successfully!';
            header('Location: ' . BASE_PATH . 'reports/received_applications.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Handle delete action
if (isset($_GET['delete']) && is_admin()) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = 'Application record deleted successfully!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting record: ' . $e->getMessage();
    }
    $view = $_GET['view'] ?? 'handed_over';
    header('Location: ' . BASE_PATH . 'reports/received_applications.php?view=' . urlencode($view));
    exit;
}

// Handle form submission for editing application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_application') {
    $application_id = (int)($_POST['application_id'] ?? 0);
    $applied_date = trim($_POST['applied_date'] ?? '');
    $applicant_name = strtoupper(trim($_POST['applicant_name'] ?? ''));
    $dept = strtoupper(trim($_POST['dept'] ?? ''));
    $ftm_pin = strtoupper(trim($_POST['ftm_pin'] ?? ''));
    $item_name = strtoupper(trim($_POST['item_name'] ?? ''));
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $job_card_no = strtoupper(trim($_POST['job_card_no'] ?? ''));
    $status_tracking = trim($_POST['status_tracking'] ?? 'pending');
    $urgency = trim($_POST['urgency'] ?? 'normal');
    $allocation_date = trim($_POST['allocation_date'] ?? '') ?: null;
    $purpose = trim($_POST['purpose'] ?? '');
    
    $errors = [];
    if ($application_id <= 0) $errors[] = 'Invalid application ID';
    if (empty($applied_date)) $errors[] = 'Application date is required';
    if (empty($applicant_name)) $errors[] = 'Applicant name is required';
    if (empty($dept)) $errors[] = 'Department is required';
    if (empty($item_name)) $errors[] = 'Item name is required';
    
    if (empty($errors)) {
        try {
            // Update application record
            $stmt = $conn->prepare("
                UPDATE applications 
                SET applied_date = ?, applicant_name = ?, dept = ?, ftm_pin = ?, 
                    job_card_no = ?, item_name = ?, quantity = ?, purpose = ?, 
                    urgency = ?, status_tracking = ?, allocation_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$applied_date, $applicant_name, $dept, $ftm_pin, 
                           $job_card_no, $item_name, $quantity, $purpose, 
                           $urgency, $status_tracking, $allocation_date, $application_id]);
            
            $_SESSION['success'] = 'Application updated successfully!';
            header('Location: ' . BASE_PATH . 'reports/received_applications.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

$view = $_GET['view'] ?? 'handed_over';
if (!in_array($view, ['handed_over', 'approved', 'all'], true)) {
    $view = 'handed_over';
}
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));

$applications = [];
$totalRows = 0;
$dbError = null;
$stats = ['approved' => 0, 'handed_over' => 0, 'pending' => 0];

if (!$conn) {
    $dbError = 'Cannot connect to the database.';
} else {
    try {
        // Check if applications table exists, if not create a simple placeholder
        $stmt = $conn->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'applications')");
        $tableExists = $stmt->fetchColumn();
        
        if (!$tableExists) {
            // Create a simple applications table for future use
            $conn->exec("
                CREATE TABLE IF NOT EXISTS applications (
                    id SERIAL PRIMARY KEY,
                    applicant_name VARCHAR(255),
                    status_tracking VARCHAR(100) DEFAULT 'pending',
                    allocation_date DATE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
        
        $statSql = "SELECT
            COUNT(*) FILTER (WHERE LOWER(TRIM(status_tracking)) = 'approved') AS approved,
            COUNT(*) FILTER (WHERE LOWER(TRIM(status_tracking)) IN ('allocated', 'completed')
                OR allocation_date IS NOT NULL) AS handed_over,
            COUNT(*) FILTER (WHERE LOWER(TRIM(status_tracking)) = 'pending') AS pending
            FROM applications";
        $stats = $conn->query($statSql)->fetch() ?: $stats;

        $where = [];
        $params = [];

        if ($view === 'approved') {
            $where[] = "LOWER(TRIM(status_tracking)) = 'approved'";
        } elseif ($view === 'handed_over') {
            $where[] = "(LOWER(TRIM(status_tracking)) IN ('allocated', 'completed') OR allocation_date IS NOT NULL)";
        } else {
            $where[] = "LOWER(TRIM(status_tracking)) IN ('approved', 'allocated', 'completed')";
        }

        if ($search !== '') {
            $where[] = "(application_ref_no ILIKE ? OR applicant_name ILIKE ? OR dept ILIKE ?
                OR item_name ILIKE ? OR ftm_pin ILIKE ? OR job_card_no ILIKE ? OR status_tracking ILIKE ?)";
            $term = '%' . $search . '%';
            $params = array_merge($params, array_fill(0, 7, $term));
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM applications {$whereSql}");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $listSql = "SELECT id, applied_date, application_ref_no, job_card_no, ftm_pin,
            applicant_name, dept, item_name, quantity, purpose, status_tracking,
            allocation_date, remarks, created_at, updated_at
            FROM applications {$whereSql}
            ORDER BY COALESCE(allocation_date, applied_date, created_at::date) DESC NULLS LAST, id DESC
            LIMIT {$perPage} OFFSET {$offset}";
        $listStmt = $conn->prepare($listSql);
        $listStmt->execute($params);
        $applications = $listStmt->fetchAll();
    } catch (PDOException $e) {
        $dbError = 'Database error: ' . $e->getMessage();
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_date($v) {
    if (!$v) {
        return '—';
    }
    $ts = strtotime((string)$v);
    return $ts ? date('Y-m-d', $ts) : h((string)$v);
}

function status_badge(string $status): string {
    $s = strtolower(trim($status));
    $map = [
        'pending' => 'warning',
        'approved' => 'info',
        'allocated' => 'success',
        'completed' => 'primary',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
    ];
    $class = $map[$s] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . h(ucfirst($s)) . '</span>';
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.stat-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    transition: transform 0.2s ease;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-card.approved { border-left: 4px solid #0ea5e9; }
.stat-card.handed { border-left: 4px solid #059669; }
.stat-card.pending { border-left: 4px solid #d97706; }
#receivedTable thead th {
    background: #113F67;
    color: #fff;
    white-space: nowrap;
    vertical-align: middle;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">
        <i class="bi bi-inbox me-2"></i>Received Applications
    </h1>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addApplicationModal">
            <i class="bi bi-plus-circle me-1"></i>Add Application
        </button>
        <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-box me-1"></i>Property Items
        </a>
        <a href="<?php echo BASE_PATH; ?>reports/who_has_what.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i>Who Has What
        </a>
    </div>
</div>

<p class="text-muted mb-4">
    Equipment requests from the Applications system — approved and handed over to staff (inventory view).
</p>

<?php if ($dbError): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i><?php echo h($dbError); ?>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card stat-card handed h-100">
            <div class="card-body">
                <div class="text-muted small">Handed over / allocated</div>
                <div class="fs-3 fw-bold text-success"><?php echo (int)$stats['handed_over']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card approved h-100">
            <div class="card-body">
                <div class="text-muted small">Approved (awaiting handover)</div>
                <div class="fs-3 fw-bold text-info"><?php echo (int)$stats['approved']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card pending h-100">
            <div class="card-body">
                <div class="text-muted small">Still pending</div>
                <div class="fs-3 fw-bold text-warning"><?php echo (int)$stats['pending']; ?></div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'handed_over' ? 'active' : ''; ?>"
           href="?view=handed_over&amp;q=<?php echo urlencode($search); ?>">Handed over</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'approved' ? 'active' : ''; ?>"
           href="?view=approved&amp;q=<?php echo urlencode($search); ?>">Approved</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'all' ? 'active' : ''; ?>"
           href="?view=all&amp;q=<?php echo urlencode($search); ?>">All received</a>
    </li>
</ul>

<form class="row g-2 align-items-end mb-3" method="get">
    <input type="hidden" name="view" value="<?php echo h($view); ?>">
    <div class="col-md-5">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" placeholder="Ref no, name, item, dept, PIN..."
               value="<?php echo h($search); ?>">
    </div>
    <div class="col-auto">
        <label class="form-label">Per page</label>
        <select class="form-select" name="per_page">
            <?php foreach ([25, 50, 100] as $pp): ?>
            <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="?view=<?php echo urlencode($view); ?>" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="receivedTable">
                <thead>
                    <tr>
                        <th>Ref no</th>
                        <th>Applied</th>
                        <th>Applicant</th>
                        <th>Dept</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Handed over</th>
                        <th>FTM PIN</th>
                        <th>Job card</th>
                        <?php if (is_admin()): ?>
                        <th width="100">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($applications)): ?>
                    <tr>
                        <td colspan="<?php echo is_admin() ? '11' : '10'; ?>" class="text-center text-muted py-4">
                            No applications found for this view.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($applications as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo h($row['application_ref_no'] ?? ''); ?></td>
                        <td><?php echo fmt_date($row['applied_date'] ?? null); ?></td>
                        <td><?php echo h($row['applicant_name'] ?? ''); ?></td>
                        <td><span class="badge bg-secondary"><?php echo h($row['dept'] ?? ''); ?></span></td>
                        <td><?php echo h($row['item_name'] ?? ''); ?></td>
                        <td><?php echo (int)($row['quantity'] ?? 1); ?></td>
                        <td><?php echo status_badge($row['status_tracking'] ?? ''); ?></td>
                        <td><?php echo fmt_date($row['allocation_date'] ?? null); ?></td>
                        <td><?php echo h($row['ftm_pin'] ?? '—'); ?></td>
                        <td><?php echo h($row['job_card_no'] ?? '—'); ?></td>
                        <?php if (is_admin()): ?>
                        <td>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-primary me-1 edit-application-btn"
                                    data-application-id="<?php echo $row['id']; ?>"
                                    title="Edit application">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="?delete=<?php echo $row['id']; ?>&view=<?php echo urlencode($view); ?>" 
                               class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Are you sure you want to delete this application record?')"
                               title="Delete application">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3" aria-label="Applications pagination">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php
        $base = '?view=' . urlencode($view) . '&q=' . urlencode($search) . '&per_page=' . $perPage . '&page=';
        for ($p = 1; $p <= $totalPages; $p++):
            if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2):
        ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
            <a class="page-link" href="<?php echo $base . $p; ?>"><?php echo $p; ?></a>
        </li>
        <?php
            elseif (abs($p - $page) === 3):
                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            endif;
        endfor;
        ?>
    </ul>
</nav>
<p class="text-center text-muted small mt-2">
    Showing <?php echo count($applications); ?> of <?php echo $totalRows; ?> record(s)
</p>
<?php endif; ?>

<?php endif; ?>

<!-- Add Application Modal -->
<div class="modal fade" id="addApplicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_application">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reference Number <span class="text-danger">*</span></label>
                            <input type="text" name="application_ref_no" class="form-control" required 
                                   placeholder="Enter reference number" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application Date <span class="text-danger">*</span></label>
                            <input type="date" name="applied_date" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Applicant Name <span class="text-danger">*</span></label>
                            <input type="text" name="applicant_name" class="form-control" required 
                                   placeholder="Enter full name" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <input type="text" name="dept" class="form-control" required 
                                   placeholder="Enter department" style="text-transform: uppercase;"
                                   list="departmentList">
                            <datalist id="departmentList">
                                <option value="IT">
                                <option value="ERP">
                                <option value="HR">
                                <option value="FINANCE">
                                <option value="OPERATIONS">
                                <option value="MANAGEMENT">
                                <option value="ADMINISTRATION">
                                <option value="MARKETING">
                                <option value="SALES">
                            </datalist>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">FTM PIN</label>
                            <input type="text" name="ftm_pin" class="form-control" 
                                   placeholder="Enter FTM PIN (optional)" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Item Requested <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" class="form-control" required 
                                   placeholder="Enter item name" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" required 
                                   min="1" value="1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Job Card Number</label>
                            <input type="text" name="job_card_no" class="form-control" 
                                   placeholder="Enter job card number (optional)" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status_tracking" class="form-control">
                                <option value="pending" selected>Pending</option>
                                <option value="approved">Approved</option>
                                <option value="allocated">Allocated</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Urgency</label>
                            <select name="urgency" class="form-control">
                                <option value="low">Low</option>
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Handed Over Date</label>
                            <input type="date" name="allocation_date" class="form-control" 
                                   placeholder="Leave empty if not handed over yet">
                        </div>
                        <div class="col-md-6">
                            <!-- Empty column for layout balance -->
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purpose / Remarks</label>
                        <textarea name="purpose" class="form-control" rows="3" 
                                  placeholder="Brief description of purpose or any remarks (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Application Modal -->
<div class="modal fade" id="editApplicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editApplicationForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_application">
                    <input type="hidden" name="application_id" id="edit_application_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Application Date <span class="text-danger">*</span></label>
                            <input type="date" name="applied_date" id="edit_applied_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Applicant Name <span class="text-danger">*</span></label>
                            <input type="text" name="applicant_name" id="edit_applicant_name" class="form-control" required 
                                   placeholder="Enter full name" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department <span class="text-danger">*</span></label>
                            <input type="text" name="dept" id="edit_dept" class="form-control" required 
                                   placeholder="Enter department" style="text-transform: uppercase;"
                                   list="departmentListEdit">
                            <datalist id="departmentListEdit">
                                <option value="IT">
                                <option value="ERP">
                                <option value="HR">
                                <option value="FINANCE">
                                <option value="OPERATIONS">
                                <option value="MANAGEMENT">
                                <option value="ADMINISTRATION">
                                <option value="MARKETING">
                                <option value="SALES">
                            </datalist>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">FTM PIN</label>
                            <input type="text" name="ftm_pin" id="edit_ftm_pin" class="form-control" 
                                   placeholder="Enter FTM PIN (optional)" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Item Requested <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" id="edit_item_name" class="form-control" required 
                                   placeholder="Enter item name" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="edit_quantity" class="form-control" required 
                                   min="1" value="1">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Job Card Number</label>
                            <input type="text" name="job_card_no" id="edit_job_card_no" class="form-control" 
                                   placeholder="Enter job card number (optional)" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status_tracking" id="edit_status_tracking" class="form-control">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="allocated">Allocated</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Urgency</label>
                            <select name="urgency" id="edit_urgency" class="form-control">
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Handed Over Date</label>
                            <input type="date" name="allocation_date" id="edit_allocation_date" class="form-control" 
                                   placeholder="Leave empty if not handed over yet">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Purpose / Remarks</label>
                        <textarea name="purpose" id="edit_purpose" class="form-control" rows="3" 
                                  placeholder="Brief description of purpose or any remarks (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-uppercase input fields
document.addEventListener('DOMContentLoaded', function() {
    const uppercaseInputs = document.querySelectorAll('input[style*="text-transform: uppercase"]');
    uppercaseInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });
    
    // Edit application functionality
    const editButtons = document.querySelectorAll('.edit-application-btn');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const applicationId = this.getAttribute('data-application-id');
            loadApplicationData(applicationId);
        });
    });
    
    function loadApplicationData(applicationId) {
        // Find the row data
        const row = document.querySelector(`[data-application-id="${applicationId}"]`).closest('tr');
        const cells = row.querySelectorAll('td');
        
        // Extract data from the row
        const refNo = cells[0].textContent.trim();
        const appliedDate = cells[1].textContent.trim();
        const applicantName = cells[2].textContent.trim();
        const dept = cells[3].querySelector('.badge').textContent.trim();
        const itemName = cells[4].textContent.trim();
        const quantity = cells[5].textContent.trim();
        const status = cells[6].querySelector('.badge').textContent.trim().toLowerCase();
        const handedOver = cells[7].textContent.trim();
        const ftmPin = cells[8].textContent.trim();
        const jobCard = cells[9].textContent.trim();
        
        // Populate the edit form
        document.getElementById('edit_application_id').value = applicationId;
        document.getElementById('edit_applied_date').value = formatDateForInput(appliedDate);
        document.getElementById('edit_applicant_name').value = applicantName;
        document.getElementById('edit_dept').value = dept;
        document.getElementById('edit_item_name').value = itemName;
        document.getElementById('edit_quantity').value = quantity;
        document.getElementById('edit_status_tracking').value = status;
        document.getElementById('edit_ftm_pin').value = ftmPin === '—' ? '' : ftmPin;
        document.getElementById('edit_job_card_no').value = jobCard === '—' ? '' : jobCard;
        document.getElementById('edit_allocation_date').value = formatDateForInput(handedOver);
        
        // Show the modal
        const editModal = new bootstrap.Modal(document.getElementById('editApplicationModal'));
        editModal.show();
    }
    
    function formatDateForInput(dateStr) {
        if (!dateStr || dateStr === '—') return '';
        try {
            const date = new Date(dateStr);
            return date.toISOString().split('T')[0];
        } catch (e) {
            return '';
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
