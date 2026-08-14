<?php
require_once __DIR__ . '/../config.php';
require_login();

// Use main database connection instead of applications database
global $conn;
if (!$conn) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: ' . BASE_PATH . 'index.php');
    exit;
}

// Handle form submission for adding new handover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_handover') {
    verify_csrf();
    $date_issued = trim($_POST['date_issued'] ?? '');
    $employee_name = strtoupper(trim($_POST['employee_name'] ?? ''));
    $ftm_pin = strtoupper(trim($_POST['ftm_pin'] ?? ''));
    $department = strtoupper(trim($_POST['department'] ?? ''));
    $issued_by = strtoupper(trim($_POST['issued_by'] ?? ''));
    
    // Get devices data
    $device_names = $_POST['device_names'] ?? [];
    $serial_numbers = $_POST['serial_numbers'] ?? [];
    
    $errors = [];
    if (empty($date_issued)) $errors[] = 'Date is required';
    if (empty($employee_name)) $errors[] = 'Employee name is required';
    if (empty($ftm_pin)) $errors[] = 'FTM PIN is required';
    if (empty($department)) $errors[] = 'Department is required';
    if (empty($issued_by)) $errors[] = 'Issued by is required';
    
    // Validate devices - at least one device required
    $validDevices = [];
    for ($i = 0; $i < count($device_names); $i++) {
        $deviceName = strtoupper(trim($device_names[$i] ?? ''));
        $serialNumber = strtoupper(trim($serial_numbers[$i] ?? ''));
        
        if (!empty($deviceName) && !empty($serialNumber)) {
            $validDevices[] = ['device_name' => $deviceName, 'serial_number' => $serialNumber];
        }
    }
    
    if (empty($validDevices)) {
        $errors[] = 'At least one device with device name and serial number is required';
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Insert handover record
            $stmt = $conn->prepare("
                INSERT INTO handovers (date_issued, employee_name, ftm_pin, department, issued_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$date_issued, $employee_name, $ftm_pin, $department, $issued_by]);
            
            $handoverId = $conn->lastInsertId();
            
            // Insert devices
            $deviceStmt = $conn->prepare("
                INSERT INTO handover_devices (handover_id, device_name, serial_number)
                VALUES (?, ?, ?)
            ");
            
            foreach ($validDevices as $device) {
                $deviceStmt->execute([$handoverId, $device['device_name'], $device['serial_number']]);
            }
            
            $conn->commit();
            
            $_SESSION['success'] = 'Handover record with ' . count($validDevices) . ' device(s) added successfully!';
            header('Location: ' . BASE_PATH . 'handovers/handover_details.php?issuer=' . urlencode($issued_by));
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Handle form submission for editing handover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_handover') {
    verify_csrf();
    $handover_id = (int)($_POST['handover_id'] ?? 0);
    $date_issued = trim($_POST['date_issued'] ?? '');
    $employee_name = strtoupper(trim($_POST['employee_name'] ?? ''));
    $ftm_pin = strtoupper(trim($_POST['ftm_pin'] ?? ''));
    $department = strtoupper(trim($_POST['department'] ?? ''));
    $issued_by = strtoupper(trim($_POST['issued_by'] ?? ''));
    
    // Get devices data
    $device_names = $_POST['device_names'] ?? [];
    $serial_numbers = $_POST['serial_numbers'] ?? [];
    
    $errors = [];
    if ($handover_id <= 0) $errors[] = 'Invalid handover ID';
    if (empty($date_issued)) $errors[] = 'Date is required';
    if (empty($employee_name)) $errors[] = 'Employee name is required';
    if (empty($ftm_pin)) $errors[] = 'FTM PIN is required';
    if (empty($department)) $errors[] = 'Department is required';
    if (empty($issued_by)) $errors[] = 'Issued by is required';
    
    // Validate devices - at least one device required
    $validDevices = [];
    for ($i = 0; $i < count($device_names); $i++) {
        $deviceName = strtoupper(trim($device_names[$i] ?? ''));
        $serialNumber = strtoupper(trim($serial_numbers[$i] ?? ''));
        
        if (!empty($deviceName) && !empty($serialNumber)) {
            $validDevices[] = ['device_name' => $deviceName, 'serial_number' => $serialNumber];
        }
    }
    
    if (empty($validDevices)) {
        $errors[] = 'At least one device with device name and serial number is required';
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Update handover record
            $stmt = $conn->prepare("
                UPDATE handovers 
                SET date_issued = ?, employee_name = ?, ftm_pin = ?, department = ?, issued_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$date_issued, $employee_name, $ftm_pin, $department, $issued_by, $handover_id]);
            
            // Delete existing devices
            $stmt = $conn->prepare("DELETE FROM handover_devices WHERE handover_id = ?");
            $stmt->execute([$handover_id]);
            
            // Insert updated devices
            $deviceStmt = $conn->prepare("
                INSERT INTO handover_devices (handover_id, device_name, serial_number)
                VALUES (?, ?, ?)
            ");
            
            foreach ($validDevices as $device) {
                $deviceStmt->execute([$handover_id, $device['device_name'], $device['serial_number']]);
            }
            
            $conn->commit();
            
            $_SESSION['success'] = 'Handover record updated successfully with ' . count($validDevices) . ' device(s)!';
            header('Location: ' . BASE_PATH . 'handovers/handover_details.php?issuer=' . urlencode($issued_by));
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Handle delete action (POST only to prevent CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_handover' && is_admin()) {
    verify_csrf();
    $id = (int)($_POST['delete_id'] ?? 0);
    try {
        $conn->beginTransaction();
        
        // Delete devices first
        $stmt = $conn->prepare("DELETE FROM handover_devices WHERE handover_id = ?");
        $stmt->execute([$id]);
        
        // Delete handover
        $stmt = $conn->prepare("DELETE FROM handovers WHERE id = ?");
        $stmt->execute([$id]);
        
        $conn->commit();
        $_SESSION['success'] = 'Handover record deleted successfully!';
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Error deleting record: ' . $e->getMessage();
    }
    header('Location: ' . BASE_PATH . 'handovers/handovers.php');
    exit;
}

// Pagination and search
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build query
$whereClause = '';
$params = [];
if (!empty($search)) {
    $whereClause = "WHERE h.employee_name ILIKE ? OR h.ftm_pin ILIKE ? OR h.department ILIKE ? OR hd.device_name ILIKE ? OR hd.serial_number ILIKE ?";
    $searchTerm = "%$search%";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

// Get total count of handovers (not devices) - need JOIN for search
$countSql = "SELECT COUNT(DISTINCT h.id) FROM handovers h LEFT JOIN handover_devices hd ON h.id = hd.handover_id $whereClause";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Get handovers with devices - each device as separate row
$sql = "SELECT h.*, hd.device_name, hd.serial_number
        FROM handovers h 
        LEFT JOIN handover_devices hd ON h.id = hd.handover_id 
        $whereClause
        ORDER BY h.date_issued DESC, h.created_at DESC, hd.id ASC 
        LIMIT " . ($perPage * 10) . " OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$handovers = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-hand-thumbs-up me-2"></i>Equipment Handovers</h2>
    <div>
        <div class="btn-group me-2" role="group">
            <a href="<?php echo BASE_PATH; ?>handovers/export_handovers.php?format=csv" class="btn btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a href="<?php echo BASE_PATH; ?>handovers/export_handovers.php?format=pdf" class="btn btn-outline-danger">
                <i class="bi bi-filetype-pdf me-1"></i>PDF
            </a>
        </div>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addHandoverModal">
            <i class="bi bi-plus-circle me-1"></i>Add Handover
        </button>
    </div>
</div>

<!-- Search and Stats -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="d-flex">
            <input type="search" id="liveSearch" class="form-control me-2" 
                   placeholder="Search handovers..." value="<?php echo htmlspecialchars($search); ?>"
                   autocomplete="off">
            <button class="btn btn-outline-primary" type="button" id="clearSearch">
                <i class="bi bi-x-circle"></i>
            </button>
        </div>
        <small class="text-muted">Search by employee, PIN, department, device, or serial number</small>
    </div>
    <div class="col-md-6 text-end">
        <span class="text-muted">Total Records: <span id="recordCount"><?php echo number_format($totalRecords); ?></span></span>
    </div>
</div>

<!-- Handovers Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>DATE</th>
                        <th>EMPLOYEE NAME</th>
                        <th>FTM PIN</th>
                        <th>DEPARTMENT</th>
                        <th>DEVICE NAME</th>
                        <th>SERIAL NUMBER</th>
                        <th>ISSUED BY</th>
                        <?php if (is_admin()): ?>
                        <th width="100">ACTIONS</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($handovers)): ?>
                        <tr>
                            <td colspan="<?php echo is_admin() ? '8' : '7'; ?>" class="text-center py-4 text-muted">
                                No handover records found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($handovers as $handover): ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($handover['date_issued'])); ?></td>
                            <td><?php echo htmlspecialchars($handover['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($handover['ftm_pin']); ?></td>
                            <td><?php echo htmlspecialchars($handover['department']); ?></td>
                            <td><?php echo htmlspecialchars($handover['device_name'] ?? 'No device'); ?></td>
                            <td><?php echo htmlspecialchars($handover['serial_number'] ?? 'No serial'); ?></td>
                            <td><?php echo htmlspecialchars($handover['issued_by']); ?></td>
                            <?php if (is_admin()): ?>
                            <td>
                                <button type="button" 
                                        class="btn btn-sm btn-outline-primary me-1 edit-handover-btn"
                                        data-handover-id="<?php echo $handover['id']; ?>"
                                        title="Edit handover">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="" class="d-inline"
                                      onsubmit="return confirm('Delete this handover record?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_handover">
                                    <input type="hidden" name="delete_id" value="<?php echo (int)$handover['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete handover">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
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
<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Handovers pagination" class="mt-4">
    <ul class="pagination justify-content-center">
        <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">Previous</a>
            </li>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">Next</a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- Add Handover Modal -->
<div class="modal fade" id="addHandoverModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Equipment Handover</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_handover">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">DATE <span class="text-danger">*</span></label>
                            <input type="date" name="date_issued" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">EMPLOYEE NAME <span class="text-danger">*</span></label>
                            <input type="text" name="employee_name" class="form-control" required 
                                   placeholder="Enter employee full name" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">FTM PIN <span class="text-danger">*</span></label>
                            <input type="text" name="ftm_pin" class="form-control" required 
                                   placeholder="Enter FTM PIN" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">DEPARTMENT <span class="text-danger">*</span></label>
                            <input type="text" name="department" class="form-control" required 
                                   placeholder="Enter department name" style="text-transform: uppercase;"
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
                    
                    <!-- Devices Section -->
                    <div class="mb-3">
                        <label class="form-label">DEVICES <span class="text-danger">*</span> <small class="text-muted">(At least one device required)</small></label>
                        <div id="devicesContainer">
                            <div class="device-row row mb-2">
                                <div class="col-md-5">
                                    <input type="text" name="device_names[]" class="form-control" 
                                           placeholder="Device name" style="text-transform: uppercase;" required>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" name="serial_numbers[]" class="form-control" 
                                           placeholder="Serial number" style="text-transform: uppercase;" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-danger remove-device" disabled>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm" id="addDeviceBtn">
                            <i class="bi bi-plus-circle me-1"></i>Add Another Device
                        </button>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ISSUED BY <span class="text-danger">*</span></label>
                        <select name="issued_by" class="form-control" required style="text-transform: uppercase;">
                            <option value="">SELECT PERSON ISSUING EQUIPMENT</option>
                            <option value="BONGANI MLOTSHWA">BONGANI MLOTSHWA</option>
                            <option value="LINDOKUHLE MAKHANYA">LINDOKUHLE MAKHANYA</option>
                            <option value="MAKABONGWE MKHONTA">MAKABONGWE MKHONTA</option>
                            <option value="MBONGISENI NKAMBULE">MBONGISENI NKAMBULE</option>
                            <option value="NKOSIKHONA DLUDLU">NKOSIKHONA DLUDLU</option>
                            <option value="NOTHANDO MOTSA">NOTHANDO MOTSA</option>
                            <option value="SIBONGAKONKE MAMBA">SIBONGAKONKE MAMBA</option>
                            <option value="THABO DLAMINI">THABO DLAMINI</option>
                            <option value="<?php echo strtoupper(htmlspecialchars($_SESSION['user_name'] ?? 'ADMIN')); ?>" selected>
                                <?php echo strtoupper(htmlspecialchars($_SESSION['user_name'] ?? 'ADMIN')); ?>
                            </option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Handover</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Handover Modal -->
<div class="modal fade" id="editHandoverModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Equipment Handover</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="editHandoverForm">
                <?php echo csrf_field(); ?>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_handover">
                    <input type="hidden" name="handover_id" id="edit_handover_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">DATE <span class="text-danger">*</span></label>
                            <input type="date" name="date_issued" id="edit_date_issued" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">EMPLOYEE NAME <span class="text-danger">*</span></label>
                            <input type="text" name="employee_name" id="edit_employee_name" class="form-control" required 
                                   placeholder="Enter employee full name" style="text-transform: uppercase;">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">FTM PIN <span class="text-danger">*</span></label>
                            <input type="text" name="ftm_pin" id="edit_ftm_pin" class="form-control" required 
                                   placeholder="Enter FTM PIN" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">DEPARTMENT <span class="text-danger">*</span></label>
                            <input type="text" name="department" id="edit_department" class="form-control" required 
                                   placeholder="Enter department name" style="text-transform: uppercase;"
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
                    </div>
                    
                    <!-- Devices Section -->
                    <div class="mb-3">
                        <label class="form-label">DEVICES <span class="text-danger">*</span> <small class="text-muted">(At least one device required)</small></label>
                        <div id="editDevicesContainer">
                            <!-- Devices will be populated by JavaScript -->
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm" id="editAddDeviceBtn">
                            <i class="bi bi-plus-circle me-1"></i>Add Another Device
                        </button>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ISSUED BY <span class="text-danger">*</span></label>
                        <select name="issued_by" id="edit_issued_by" class="form-control" required style="text-transform: uppercase;">
                            <option value="">SELECT PERSON ISSUING EQUIPMENT</option>
                            <option value="BONGANI MLOTSHWA">BONGANI MLOTSHWA</option>
                            <option value="LINDOKUHLE MAKHANYA">LINDOKUHLE MAKHANYA</option>
                            <option value="MAKABONGWE MKHONTA">MAKABONGWE MKHONTA</option>
                            <option value="MBONGISENI NKAMBULE">MBONGISENI NKAMBULE</option>
                            <option value="NKOSIKHONA DLUDLU">NKOSIKHONA DLUDLU</option>
                            <option value="NOTHANDO MOTSA">NOTHANDO MOTSA</option>
                            <option value="SIBONGAKONKE MAMBA">SIBONGAKONKE MAMBA</option>
                            <option value="THABO DLAMINI">THABO DLAMINI</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Handover</button>
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
    
    // Add device functionality for Add modal
    let deviceCount = 1;
    
    // Live search functionality
    const liveSearch = document.getElementById('liveSearch');
    const clearSearch = document.getElementById('clearSearch');
    const tableBody = document.querySelector('table tbody');
    const recordCount = document.getElementById('recordCount');
    let allRows = [];
    
    // Store all table rows for filtering
    if (tableBody) {
        allRows = Array.from(tableBody.querySelectorAll('tr'));
    }
    
    function filterTable(searchTerm) {
        if (!tableBody || allRows.length === 0) return;
        
        const term = searchTerm.toLowerCase().trim();
        let visibleCount = 0;
        
        allRows.forEach(row => {
            // Skip if this is the no-results row
            if (row.classList.contains('no-results-row')) {
                return;
            }
            
            if (term === '') {
                row.style.display = '';
                visibleCount++;
            } else {
                // Get all text content from the row, excluding action buttons
                const cells = Array.from(row.querySelectorAll('td'));
                const searchableText = cells.slice(0, -1).map(cell => cell.textContent.toLowerCase()).join(' ');
                
                if (searchableText.includes(term)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
        });
        
        // Update record count
        if (recordCount) {
            recordCount.textContent = visibleCount.toLocaleString();
        }
        
        // Show "no results" message if needed
        let noResultsRow = tableBody.querySelector('.no-results-row');
        if (visibleCount === 0 && term !== '') {
            if (!noResultsRow) {
                const colCount = tableBody.querySelector('tr:not(.no-results-row)')?.children.length || 8;
                noResultsRow = document.createElement('tr');
                noResultsRow.className = 'no-results-row';
                noResultsRow.innerHTML = `<td colspan="${colCount}" class="text-center py-4 text-muted">
                    <i class="bi bi-search me-2"></i>No handovers found matching "${searchTerm}"
                </td>`;
                tableBody.appendChild(noResultsRow);
            }
        } else if (noResultsRow) {
            noResultsRow.remove();
        }
    }
    
    if (liveSearch) {
        // Filter as user types
        liveSearch.addEventListener('input', function() {
            filterTable(this.value);
        });
        
        // Handle Enter key
        liveSearch.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterTable(this.value);
            }
        });
    }
    
    if (clearSearch) {
        clearSearch.addEventListener('click', function() {
            liveSearch.value = '';
            filterTable('');
            liveSearch.focus();
        });
    }
    
    document.getElementById('addDeviceBtn').addEventListener('click', function() {
        deviceCount++;
        const container = document.getElementById('devicesContainer');
        const newRow = document.createElement('div');
        newRow.className = 'device-row row mb-2';
        newRow.innerHTML = `
            <div class="col-md-5">
                <input type="text" name="device_names[]" class="form-control" 
                       placeholder="Device name" style="text-transform: uppercase;">
            </div>
            <div class="col-md-5">
                <input type="text" name="serial_numbers[]" class="form-control" 
                       placeholder="Serial number" style="text-transform: uppercase;">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger remove-device">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(newRow);
        
        // Add uppercase functionality to new inputs
        newRow.querySelectorAll('input[style*="text-transform: uppercase"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
        
        updateRemoveButtons();
    });
    
    // Add device functionality for Edit modal
    let editDeviceCount = 0;
    
    document.getElementById('editAddDeviceBtn').addEventListener('click', function() {
        editDeviceCount++;
        const container = document.getElementById('editDevicesContainer');
        const newRow = document.createElement('div');
        newRow.className = 'device-row row mb-2';
        newRow.innerHTML = `
            <div class="col-md-5">
                <input type="text" name="device_names[]" class="form-control" 
                       placeholder="Device name" style="text-transform: uppercase;">
            </div>
            <div class="col-md-5">
                <input type="text" name="serial_numbers[]" class="form-control" 
                       placeholder="Serial number" style="text-transform: uppercase;">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger remove-device">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(newRow);
        
        // Add uppercase functionality to new inputs
        newRow.querySelectorAll('input[style*="text-transform: uppercase"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
        
        updateEditRemoveButtons();
    });
    
    // Remove device functionality
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-device')) {
            const row = e.target.closest('.device-row');
            const container = row.parentElement;
            row.remove();
            
            if (container.id === 'devicesContainer') {
                deviceCount--;
                updateRemoveButtons();
            } else if (container.id === 'editDevicesContainer') {
                editDeviceCount--;
                updateEditRemoveButtons();
            }
        }
    });
    
    function updateRemoveButtons() {
        const removeButtons = document.querySelectorAll('#devicesContainer .remove-device');
        removeButtons.forEach(btn => {
            btn.disabled = removeButtons.length <= 1;
        });
    }
    
    function updateEditRemoveButtons() {
        const removeButtons = document.querySelectorAll('#editDevicesContainer .remove-device');
        removeButtons.forEach(btn => {
            btn.disabled = removeButtons.length <= 1;
        });
    }
    
    // Edit handover functionality
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-handover-btn')) {
            const handoverId = e.target.closest('.edit-handover-btn').dataset.handoverId;
            loadHandoverForEdit(handoverId);
        }
    });
    
    function loadHandoverForEdit(handoverId) {
        // Show loading state
        const modal = new bootstrap.Modal(document.getElementById('editHandoverModal'));
        modal.show();
        
        // Fetch handover data
        fetch(`<?php echo BASE_PATH; ?>handovers/get_handover.php?id=${handoverId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    populateEditForm(data.handover, data.devices);
                } else {
                    alert('Error loading handover data: ' + (data.error || 'Unknown error'));
                    modal.hide();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading handover data: ' + error.message);
                modal.hide();
            });
    }
    
    function populateEditForm(handover, devices) {
        // Populate basic fields
        document.getElementById('edit_handover_id').value = handover.id;
        document.getElementById('edit_date_issued').value = handover.date_issued;
        document.getElementById('edit_employee_name').value = handover.employee_name;
        document.getElementById('edit_ftm_pin').value = handover.ftm_pin;
        document.getElementById('edit_department').value = handover.department;
        
        // Handle select dropdown for issued_by
        const issuedBySelect = document.getElementById('edit_issued_by');
        issuedBySelect.value = handover.issued_by;
        
        // If the value doesn't exist in the dropdown, add it as a new option
        if (issuedBySelect.value !== handover.issued_by) {
            const newOption = document.createElement('option');
            newOption.value = handover.issued_by;
            newOption.textContent = handover.issued_by;
            newOption.selected = true;
            issuedBySelect.appendChild(newOption);
        }
        
        // Clear and populate devices
        const container = document.getElementById('editDevicesContainer');
        container.innerHTML = '';
        editDeviceCount = 0;
        
        devices.forEach((device, index) => {
            editDeviceCount++;
            const newRow = document.createElement('div');
            newRow.className = 'device-row row mb-2';
            newRow.innerHTML = `
                <div class="col-md-5">
                    <input type="text" name="device_names[]" class="form-control" 
                           placeholder="Device name" style="text-transform: uppercase;"
                           value="${device.device_name}">
                </div>
                <div class="col-md-5">
                    <input type="text" name="serial_numbers[]" class="form-control" 
                           placeholder="Serial number" style="text-transform: uppercase;"
                           value="${device.serial_number}">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-danger remove-device" ${devices.length === 1 ? 'disabled' : ''}>
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(newRow);
            
            // Add uppercase functionality to new inputs
            newRow.querySelectorAll('input[style*="text-transform: uppercase"]').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });
        });
        
        updateEditRemoveButtons();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>