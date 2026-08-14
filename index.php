<?php
require_once __DIR__ . '/config.php';
require_login();
include __DIR__ . '/includes/header.php';

// One query for all item status counts
$counts = $conn->query("SELECT
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE status='available')           AS available,
    COUNT(*) FILTER (WHERE status='checked_out')         AS checked_out,
    COUNT(*) FILTER (WHERE status='permanently_assigned') AS permanent,
    COUNT(*) FILTER (WHERE status='checked_out'
        AND expected_return_date IS NOT NULL
        AND expected_return_date < CURRENT_DATE)         AS overdue
    FROM items")->fetch();
$total     = (int)($counts['total']     ?? 0);
$available = (int)($counts['available'] ?? 0);
$checked   = (int)($counts['checked_out'] ?? 0);
$permanent = (int)($counts['permanent'] ?? 0);
$overdue   = (int)($counts['overdue']   ?? 0);

$handovers = 0;
try {
    $handovers = (int)($conn->query("SELECT COUNT(*) FROM handovers")->fetchColumn() ?? 0);
} catch (Exception $e) {}

$applications = 0;
try {
    $applications = (int)($conn->query("SELECT COUNT(*) FROM applications")->fetchColumn() ?? 0);
} catch (Exception $e) {}

// Inventory summary by NAME (top 10 by total desc)
$nameRows = [];
$nameRes = $conn->query("SELECT COALESCE(NULLIF(TRIM(item_name),''),'(Unnamed)') AS item_name,
                                COUNT(*) AS total,
                                SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available
                         FROM items
                         GROUP BY item_name
                         ORDER BY total DESC, item_name ASC
                         LIMIT 10");
if ($nameRes) {
  $nameRows = $nameRes->fetchAll();
}

// Description breakdown for a selected item name
$breakName = isset($_GET['break_name']) ? trim($_GET['break_name']) : '';
if ($breakName === '' && !empty($nameRows)) {
  $breakName = $nameRows[0]['item_name'];
}
$descRows = [];
if ($breakName !== '') {
  // Use prepared statement to avoid any special char issues in name
  $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(description),''),'(No description)') AS description,
                                 COUNT(*) AS total,
                                 SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available
                          FROM items WHERE item_name = ?
                          GROUP BY description
                          ORDER BY total DESC, description ASC");
  $stmt->execute([$breakName]);
  $descRows = $stmt->fetchAll();
}
?>

<style>
.dashboard-card {
  border: none;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  background: linear-gradient(135deg, var(--card-bg-start), var(--card-bg-end));
  position: relative;
  overflow: hidden;
}

.dashboard-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: var(--card-accent);
}

.dashboard-card:hover {
  transform: translateY(-8px) scale(1.02);
  box-shadow: 0 12px 40px rgba(0,0,0,0.15);
}

/* Green, Navy Blue, and Red Color Scheme */
.dashboard-card.total { --card-bg-start: #1e3a8a; --card-bg-end: #1e40af; --card-accent: #22c55e; }
.dashboard-card.available { --card-bg-start: #059669; --card-bg-end: #10b981; --card-accent: #34d399; }
.dashboard-card.checked-out { --card-bg-start: #1e40af; --card-bg-end: #3b82f6; --card-accent: #22c55e; }
.dashboard-card.permanent { --card-bg-start: #166534; --card-bg-end: #16a34a; --card-accent: #4ade80; }
.dashboard-card.handovers { --card-bg-start: #7c3aed; --card-bg-end: #8b5cf6; --card-accent: #a78bfa; }
.dashboard-card.applications { --card-bg-start: #dc2626; --card-bg-end: #ef4444; --card-accent: #f87171; }

.stat-icon {
  font-size: 3rem;
  opacity: 0.8;
  transition: all 0.3s ease;
}

.dashboard-card:hover .stat-icon {
  transform: scale(1.1) rotate(5deg);
  opacity: 1;
}

.stat-number {
  font-size: 2.5rem;
  font-weight: 700;
  margin: 0;
  text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.stat-label {
  font-size: 0.9rem;
  font-weight: 500;
  opacity: 0.9;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* Quick Actions Card - New Style */
.quick-actions-card {
  background: linear-gradient(135deg, #1e3a8a 0%, #059669 100%);
  border: none;
  border-radius: 20px;
  box-shadow: 0 8px 32px rgba(30, 58, 138, 0.3);
  color: white;
  position: relative;
  overflow: hidden;
}

.quick-actions-card::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -50%;
  width: 100%;
  height: 100%;
  background: radial-gradient(circle, rgba(34, 197, 94, 0.1) 0%, transparent 70%);
  animation: pulse 4s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { transform: scale(1); opacity: 0.5; }
  50% { transform: scale(1.1); opacity: 0.8; }
}

.action-btn {
  background: rgba(255, 255, 255, 0.1);
  border: 2px solid rgba(255, 255, 255, 0.2);
  border-radius: 12px;
  color: white;
  padding: 1rem;
  text-decoration: none;
  transition: all 0.3s ease;
  backdrop-filter: blur(10px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  min-height: 100px;
}

.action-btn:hover {
  background: rgba(255, 255, 255, 0.2);
  border-color: rgba(34, 197, 94, 0.5);
  transform: translateY(-4px);
  box-shadow: 0 8px 25px rgba(0,0,0,0.2);
  color: white;
}

.action-btn i {
  font-size: 2rem;
  margin-bottom: 0.5rem;
}

.action-btn-text {
  font-size: 0.9rem;
  font-weight: 600;
  text-align: center;
  line-height: 1.2;
}

.info-card {
  border: none;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.08);
  transition: all 0.3s ease;
  background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
  border-left: 4px solid #059669;
}

.info-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.info-card .card-title {
  color: #1e3a8a;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.table-modern {
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.table-modern thead {
  background: linear-gradient(135deg, #1e3a8a, #059669);
  color: white;
}

.table-modern tbody tr {
  transition: all 0.2s ease;
}

.table-modern tbody tr:hover {
  background-color: rgba(5, 150, 105, 0.05);
  transform: scale(1.01);
}

.dashboard-title {
  background: linear-gradient(135deg, #1e3a8a, #059669);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  font-weight: 700;
  font-size: 2.5rem;
  margin-bottom: 2rem;
  text-align: center;
}

.badge.bg-primary {
  background: linear-gradient(135deg, #1e3a8a, #3b82f6) !important;
}

.badge.bg-success {
  background: linear-gradient(135deg, #059669, #10b981) !important;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.dashboard-card {
  animation: fadeInUp 0.6s ease forwards;
}

.dashboard-card:nth-child(1) { animation-delay: 0.1s; }
.dashboard-card:nth-child(2) { animation-delay: 0.2s; }
.dashboard-card:nth-child(3) { animation-delay: 0.3s; }
.dashboard-card:nth-child(4) { animation-delay: 0.4s; }
.dashboard-card:nth-child(5) { animation-delay: 0.5s; }

/* Balanced spacing for cards */
.stats-container {
  padding: 0 1rem;
}

@media (min-width: 768px) {
  .stats-container {
    padding: 0 2rem;
  }
}
</style>

<h1 class="dashboard-title">IT PROPERTY DASHBOARD</h1>

<div class="stats-container">
  <div class="row g-4 justify-content-center">
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card total text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Total Items</div>
              <div class="stat-number"><?php echo number_format($total); ?></div>
            </div>
            <i class="bi bi-box-seam stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card available text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Available</div>
              <div class="stat-number"><?php echo number_format($available); ?></div>
            </div>
            <i class="bi bi-check-circle stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card checked-out text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Checked Out</div>
              <div class="stat-number"><?php echo number_format($checked); ?></div>
            </div>
            <i class="bi bi-arrow-up-right-square stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card permanent text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Permanent</div>
              <div class="stat-number"><?php echo number_format($permanent); ?></div>
            </div>
            <i class="bi bi-person-check stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card handovers text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Handovers</div>
              <div class="stat-number"><?php echo number_format($handovers); ?></div>
            </div>
            <i class="bi bi-hand-thumbs-up stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
    
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="card dashboard-card applications text-white h-100">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="stat-label">Applications</div>
              <div class="stat-number"><?php echo number_format($applications); ?></div>
            </div>
            <i class="bi bi-file-earmark-text stat-icon"></i>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($overdue > 0): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mt-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong><?php echo $overdue; ?> item<?php echo $overdue > 1 ? 's are' : ' is'; ?> overdue for return.</strong>
    <a href="<?php echo BASE_PATH; ?>items/items.php?status=checked_out" class="alert-link ms-2">View overdue items &rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- Quick Actions Section -->
<div class="row g-4 my-4">
  <div class="col-lg-8">
    <div class="card quick-actions-card">
      <div class="card-body p-4">
        <h3 class="text-white mb-4 text-center">
          <i class="bi bi-lightning-charge me-2"></i>Quick Actions
        </h3>
        <div class="row g-3">
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>items/items.php" class="action-btn">
              <i class="bi bi-list-ul"></i>
              <span class="action-btn-text">View All Items</span>
            </a>
          </div>
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>items/add_item.php" class="action-btn">
              <i class="bi bi-plus-circle"></i>
              <span class="action-btn-text">Add New Item</span>
            </a>
          </div>
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>items/checkout.php" class="action-btn">
              <i class="bi bi-box-arrow-up-right"></i>
              <span class="action-btn-text">Check Out Item</span>
            </a>
          </div>
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>items/return_item.php" class="action-btn">
              <i class="bi bi-box-arrow-in-down-left"></i>
              <span class="action-btn-text">Return Item</span>
            </a>
          </div>
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>handovers/handover_summary.php" class="action-btn">
              <i class="bi bi-hand-thumbs-up"></i>
              <span class="action-btn-text">Equipment Handovers</span>
            </a>
          </div>
          <div class="col-lg-3 col-md-6">
            <a href="<?php echo BASE_PATH; ?>reports/received_applications.php" class="action-btn">
              <i class="bi bi-file-earmark-plus"></i>
              <span class="action-btn-text">Add Application</span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-lg-4">
    <div class="card info-card h-100">
      <div class="card-body p-4">
        <h5 class="card-title mb-3">
          <i class="bi bi-search me-2"></i>Quick Search
        </h5>
        <form method="get" action="<?php echo BASE_PATH; ?>items/items.php" role="search">
          <div class="input-group mb-3">
            <input type="search" name="q" class="form-control" 
                   placeholder="Search items..." />
            <button class="btn btn-success" type="submit">
              <i class="bi bi-search"></i>
            </button>
          </div>
          <input type="hidden" name="sort" value="id">
          <input type="hidden" name="dir" value="desc">
          <input type="hidden" name="per_page" value="25">
          <input type="hidden" name="page" value="1">
        </form>
        <div class="text-muted small">
          <i class="bi bi-info-circle me-1"></i>
          Search by name, serial, category, description, person, or department
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card info-card">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h5 class="card-title mb-0">
            <i class="bi bi-list-ul me-2"></i>Description Breakdown
          </h5>
          <form method="get" class="d-flex align-items-center" action="<?php echo BASE_PATH; ?>index.php">
            <label class="me-2 small text-muted">Item:</label>
            <select name="break_name" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width: 200px;">
              <?php foreach ($nameRows as $nr): ?>
                <option value="<?php echo htmlspecialchars($nr['item_name']); ?>" <?php echo ($nr['item_name'] === $breakName)?'selected':''; ?>>
                  <?php echo htmlspecialchars($nr['item_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-modern table-sm">
            <thead>
              <tr>
                <th>Description</th>
                <th class="text-end">Total</th>
                <th class="text-end">Available</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($descRows)): ?>
                <tr><td colspan="3" class="text-muted text-center py-4">No data available</td></tr>
              <?php else: foreach ($descRows as $dr): ?>
                <tr>
                  <td>
                    <i class="bi bi-tag me-2 text-muted"></i>
                    <?php echo htmlspecialchars($dr['description']); ?>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-primary"><?php echo number_format($dr['total']); ?></span>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-success"><?php echo number_format($dr['available']); ?></span>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
          <div class="text-muted small mt-2">
            <i class="bi bi-info-circle me-1"></i>
            Examples: HDMI Cable — 5m, 10m; Keyboard — Wired, Wireless; Mouse — Optical, Bluetooth
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card info-card">
      <div class="card-body p-4">
        <h5 class="card-title mb-3">
          <i class="bi bi-bar-chart me-2"></i>Top 10 Items by Quantity
        </h5>
        <div class="table-responsive">
          <table class="table table-modern table-sm">
            <thead>
              <tr>
                <th>Item Name</th>
                <th class="text-end">Total</th>
                <th class="text-end">Available</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($nameRows)): ?>
                <tr><td colspan="3" class="text-muted text-center py-4">No data available</td></tr>
              <?php else: foreach ($nameRows as $nr): ?>
                <tr>
                  <td>
                    <i class="bi bi-box me-2 text-muted"></i>
                    <?php echo htmlspecialchars($nr['item_name']); ?>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-primary"><?php echo number_format($nr['total']); ?></span>
                  </td>
                  <td class="text-end">
                    <span class="badge bg-success"><?php echo number_format($nr['available']); ?></span>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
          <div class="text-muted small mt-2">
            <i class="bi bi-lightbulb me-1"></i>
            Tip: Use consistent item names for accurate counts. Use Description field for variants
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>