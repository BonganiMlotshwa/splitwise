<?php
require_once __DIR__ . '/../config.php';
require_login();

// Use main database connection
global $conn;
if (!$conn) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: ' . BASE_PATH . 'index.php');
    exit;
}

// Get issuer from URL parameter
$issuer = trim($_GET['issuer'] ?? '');
if (empty($issuer)) {
    $_SESSION['error'] = 'No issuer specified.';
    header('Location: ' . BASE_PATH . 'handovers/handover_summary.php');
    exit;
}

// Pagination and search
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build query for handovers by specific issuer
$whereClause = 'WHERE h.issued_by = ?';
$params = [$issuer];

if (!empty($search)) {
    $whereClause .= " AND (h.employee_name ILIKE ? OR h.ftm_pin ILIKE ? OR h.department ILIKE ? OR hd.device_name ILIKE ? OR hd.serial_number ILIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Get handovers with devices for this issuer
$sql = "SELECT h.*, hd.device_name, hd.serial_number
        FROM handovers h 
        LEFT JOIN handover_devices hd ON h.id = hd.handover_id 
        $whereClause
        ORDER BY h.date_issued DESC, h.created_at DESC, hd.id ASC 
        LIMIT " . ($perPage * 10) . " OFFSET $offset";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$handovers = $stmt->fetchAll();

// Get total count for pagination
$countSql = "SELECT COUNT(DISTINCT h.id) FROM handovers h LEFT JOIN handover_devices hd ON h.id = hd.handover_id $whereClause";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Get summary stats for this issuer
$statsSql = "SELECT COUNT(DISTINCT h.id) as handover_count,
                    COUNT(hd.id) as device_count,
                    MIN(h.date_issued) as first_handover,
                    MAX(h.date_issued) as last_handover
             FROM handovers h 
             LEFT JOIN handover_devices hd ON h.id = hd.handover_id 
             WHERE h.issued_by = ?";
$statsStmt = $conn->prepare($statsSql);
$statsStmt->execute([$issuer]);
$stats = $statsStmt->fetch();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2><i class="bi bi-person-badge me-2"></i>Handovers by <?php echo htmlspecialchars($issuer); ?></h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?php echo BASE_PATH; ?>handovers/handover_summary.php">Equipment Handovers</a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <?php echo htmlspecialchars($issuer); ?>
                </li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="<?php echo BASE_PATH; ?>handovers/handover_summary.php" class="btn btn-outline-secondary me-2">
            <i class="bi bi-arrow-left me-1"></i>Back to Handovers
        </a>
        <div class="btn-group me-2" role="group">
            <a href="<?php echo BASE_PATH; ?>handovers/export_handovers.php?format=csv&issuer=<?php echo urlencode($issuer); ?>" class="btn btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>CSV
            </a>
            <a href="<?php echo BASE_PATH; ?>handovers/export_handovers.php?format=pdf&issuer=<?php echo urlencode($issuer); ?>" class="btn btn-outline-danger">
                <i class="bi bi-filetype-pdf me-1"></i>PDF
            </a>
        </div>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addHandoverModal">
            <i class="bi bi-plus-circle me-1"></i>Add Handover
        </button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-primary"><?php echo number_format($stats['handover_count']); ?></h5>
                <p class="card-text text-muted">Total Handovers</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-success"><?php echo number_format($stats['device_count']); ?></h5>
                <p class="card-text text-muted">Total Devices</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-info">
                    <?php echo $stats['first_handover'] ? date('Y-m-d', strtotime($stats['first_handover'])) : 'N/A'; ?>
                </h5>
                <p class="card-text text-muted">First Handover</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-warning">
                    <?php echo $stats['last_handover'] ? date('Y-m-d', strtotime($stats['last_handover'])) : 'N/A'; ?>
                </h5>
                <p class="card-text text-muted">Last Handover</p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Stats -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="d-flex">
            <input type="hidden" name="issuer" value="<?php echo htmlspecialchars($issuer); ?>">
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
        <span class="text-muted">Showing: <span id="recordCount"><?php echo number_format($totalRecords); ?></span> handover records</span>
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
                        <?php if (is_admin()): ?>
                        <th width="100">ACTIONS</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($handovers)): ?>
                        <tr>
                            <td colspan="<?php echo is_admin() ? '7' : '6'; ?>" class="text-center py-4 text-muted">
                                No handover records found for <?php echo htmlspecialchars($issuer); ?>
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
                            <?php if (is_admin()): ?>
                            <td>
                                <button type="button" 
                                        class="btn btn-sm btn-outline-primary me-1 edit-handover-btn"
                                        data-handover-id="<?php echo $handover['id']; ?>"
                                        title="Edit handover">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="<?php echo BASE_PATH; ?>handovers/handovers.php?delete=<?php echo $handover['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to delete this handover record?')"
                                   title="Delete handover">
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

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav aria-label="Handover details pagination" class="mt-4">
    <ul class="pagination justify-content-center">
        <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?issuer=<?php echo urlencode($issuer); ?>&page=<?php echo $page-1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">Previous</a>
            </li>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?issuer=<?php echo urlencode($issuer); ?>&page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?issuer=<?php echo urlencode($issuer); ?>&page=<?php echo $page+1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">Next</a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- Add Handover Modal (pre-filled with issuer) -->
<div class="modal fade" id="addHandoverModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Equipment Handover</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="<?php echo BASE_PATH; ?>handovers/handovers.php">
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
                            <option value="BONGANI MLOTSHWA" <?php echo $issuer === 'BONGANI MLOTSHWA' ? 'selected' : ''; ?>>BONGANI MLOTSHWA</option>
                            <option value="LINDOKUHLE MAKHANYA" <?php echo $issuer === 'LINDOKUHLE MAKHANYA' ? 'selected' : ''; ?>>LINDOKUHLE MAKHANYA</option>
                            <option value="MAKABONGWE MKHONTA" <?php echo $issuer === 'MAKABONGWE MKHONTA' ? 'selected' : ''; ?>>MAKABONGWE MKHONTA</option>
                            <option value="MBONGISENI NKAMBULE" <?php echo $issuer === 'MBONGISENI NKAMBULE' ? 'selected' : ''; ?>>MBONGISENI NKAMBULE</option>
                            <option value="NKOSIKHONA DLUDLU" <?php echo $issuer === 'NKOSIKHONA DLUDLU' ? 'selected' : ''; ?>>NKOSIKHONA DLUDLU</option>
                            <option value="NOTHANDO MOTSA" <?php echo $issuer === 'NOTHANDO MOTSA' ? 'selected' : ''; ?>>NOTHANDO MOTSA</option>
                            <option value="SIBONGAKONKE MAMBA" <?php echo $issuer === 'SIBONGAKONKE MAMBA' ? 'selected' : ''; ?>>SIBONGAKONKE MAMBA</option>
                            <option value="THABO DLAMINI" <?php echo $issuer === 'THABO DLAMINI' ? 'selected' : ''; ?>>THABO DLAMINI</option>
                            <option value="<?php echo strtoupper(htmlspecialchars($_SESSION['user_name'] ?? 'ADMIN')); ?>" <?php echo $issuer === strtoupper($_SESSION['user_name'] ?? 'ADMIN') ? 'selected' : ''; ?>>
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

<script>
// Auto-uppercase input fields
document.addEventListener('DOMContentLoaded', function() {
    const uppercaseInputs = document.querySelectorAll('input[style*="text-transform: uppercase"]');
    uppercaseInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });
    
    // Add device functionality
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
                const colCount = tableBody.querySelector('tr:not(.no-results-row)')?.children.length || 7;
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
    
    // Remove device functionality
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-device')) {
            const row = e.target.closest('.device-row');
            row.remove();
            deviceCount--;
            updateRemoveButtons();
        }
    });
    
    function updateRemoveButtons() {
        const removeButtons = document.querySelectorAll('.remove-device');
        removeButtons.forEach(btn => {
            btn.disabled = removeButtons.length <= 1;
        });
    }
    
    // Initialize remove buttons state
    updateRemoveButtons();
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>