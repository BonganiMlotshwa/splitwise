<?php
require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
      body { padding-top: 56px; }
      @media (max-width: 991.98px) { /* collapsed navbar may be taller */
        body { padding-top: 56px; }
      }
      /* Fixed sidebar on desktop */
      @media (min-width: 768px) {
        .sidebar-fixed {
          position: fixed;
          top: 56px; /* height of fixed navbar */
          bottom: 60px; /* leave space for footer */
          left: 0;
          width: 240px; /* md: approx col-md-3 */
          overflow-y: auto;
          border-right: 1px solid #dee2e6;
          background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
          box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        main.with-sidebar { margin-left: 240px; margin-bottom: 60px; }
      }
      @media (min-width: 992px) {
        .sidebar-fixed { width: 220px; /* lg: approx col-lg-2 */ }
        main.with-sidebar { margin-left: 220px; margin-bottom: 60px; }
      }

      /* Sidebar hover effects */
      .list-group-item {
        border: none !important;
        border-radius: 8px !important;
        margin: 2px 8px !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        position: relative;
        overflow: hidden;
      }

      .list-group-item:not(.active):hover {
        background: linear-gradient(135deg, #059669, #10b981) !important;
        color: white !important;
        transform: translateX(8px) scale(1.02);
        box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3);
      }

      .list-group-item.active {
        background: linear-gradient(135deg, #1e3a8a, #3b82f6) !important;
        color: white !important;
        border-left: 4px solid #22c55e !important;
        transform: translateX(4px);
        box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
      }

      .list-group-item i {
        transition: all 0.3s ease;
      }

      .list-group-item:hover i {
        transform: scale(1.2) rotate(5deg);
      }

      .list-group-item.active i {
        transform: scale(1.1);
      }

      /* Sidebar section headers */
      .text-uppercase.text-muted {
        color: #1e3a8a !important;
        font-weight: 600 !important;
        font-size: 0.75rem !important;
        letter-spacing: 1px !important;
        margin-top: 1rem !important;
        margin-bottom: 0.5rem !important;
        padding-left: 1rem !important;
        position: relative;
      }

      .text-uppercase.text-muted::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        width: 3px;
        height: 16px;
        background: linear-gradient(135deg, #059669, #22c55e);
        border-radius: 2px;
        transform: translateY(-50%);
      }

      /* Logout button special styling */
      .list-group-item.text-danger:hover {
        background: linear-gradient(135deg, #dc2626, #ef4444) !important;
        color: white !important;
      }

      /* Smooth scrollbar for sidebar */
      .sidebar-fixed::-webkit-scrollbar {
        width: 6px;
      }

      .sidebar-fixed::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
      }

      .sidebar-fixed::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #059669, #1e3a8a);
        border-radius: 3px;
      }

      .sidebar-fixed::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #10b981, #3b82f6);
      }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo BASE_PATH; ?>index.php"><?php echo htmlspecialchars(SITE_NAME); ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarsExample" aria-controls="navbarsExample" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarsExample">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0"><!-- top navbar links removed; use sidebar --></ul>
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php if (empty($_SESSION['user_id'])): ?>
          <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>auth/login.php">Login</a></li>
        <?php else: ?>
          <?php if (basename($_SERVER['PHP_SELF']) === 'items.php'): ?>
            <!-- Action buttons for items page -->
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/add_item.php" style="background:#28a745;color:white;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.25rem;"><i class="bi bi-plus-circle"></i> Add</a></li>
            <?php if (is_admin()): ?>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/import_csv.php" style="background:#ffc107;color:#000;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.25rem;"><i class="bi bi-upload"></i> Import</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/manage_duplicates.php" style="background:#17a2b8;color:white;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.25rem;"><i class="bi bi-files"></i> Duplicates</a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/checkout.php" style="background:#007bff;color:white;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.25rem;"><i class="bi bi-box-arrow-up-right"></i> Check Out</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/return_item.php" style="background:#6c757d;color:white;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.25rem;"><i class="bi bi-box-arrow-in-down-left"></i> Return</a></li>
            <?php if (is_admin()): ?>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>items/assign_permanent.php" style="background:#343a40;color:white;padding:0.25rem 0.75rem;border-radius:0.25rem;margin-right:0.5rem;"><i class="bi bi-person-check"></i> Assign</a></li>
            <?php endif; ?>
          <?php endif; ?>
          <li class="nav-item">
            <span class="navbar-text" id="userGreeting" style="color:white;">
              Hello, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>
            </span>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <?php if (!empty($_SESSION['user_id'])): ?>
      <?php $current = basename($_SERVER['PHP_SELF']); ?>
      <aside class="col-md-3 col-lg-2 bg-light min-vh-100 py-3 sidebar-fixed">
        <div class="list-group list-group-flush">
          <a class="list-group-item list-group-item-action <?php echo $current==='index.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>index.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='items.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/items.php"><i class="bi bi-box me-2"></i>Items</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='permanently_assigned.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/permanently_assigned.php"><i class="bi bi-person-check-fill me-2"></i>Permanently Assigned</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='add_item.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/add_item.php"><i class="bi bi-plus-circle me-2"></i>Add Item</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='checkout.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/checkout.php"><i class="bi bi-box-arrow-up-right me-2"></i>Check Out</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='return_item.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/return_item.php"><i class="bi bi-box-arrow-in-down-left me-2"></i>Return</a>
          <?php if (is_admin()): ?>
          <a class="list-group-item list-group-item-action <?php echo $current==='assign_permanent.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>items/assign_permanent.php"><i class="bi bi-person-check me-2"></i>Assign Permanently</a>
          <?php endif; ?>
          <div class="mt-3 small text-uppercase text-muted px-3">Applications</div>
          <a class="list-group-item list-group-item-action <?php echo in_array($current, ['handovers.php', 'handover_summary.php', 'handover_details.php'])?'active':''; ?>" href="<?php echo BASE_PATH; ?>handovers/handover_summary.php"><i class="bi bi-hand-thumbs-up me-2"></i>Equipment Handovers</a>
          <div class="mt-3 small text-uppercase text-muted px-3">History</div>
          <a class="list-group-item list-group-item-action <?php echo $current==='activity.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>reports/activity.php"><i class="bi bi-clock-history me-2"></i>Activity Log</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='who_has_what.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>reports/who_has_what.php"><i class="bi bi-people me-2"></i>Who Has What</a>
          <a class="list-group-item list-group-item-action <?php echo $current==='received_applications.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>reports/received_applications.php"><i class="bi bi-inbox me-2"></i>Received Applications</a>
          <div class="mt-3 small text-uppercase text-muted px-3">Export</div>
          <a class="list-group-item list-group-item-action <?php echo $current==='export.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>reports/export.php"><i class="bi bi-arrow-down-square me-2"></i>Export</a>
          <div class="mt-3 small text-uppercase text-muted px-3">Account</div>
          <a class="list-group-item list-group-item-action <?php echo $current==='change_password.php'?'active':''; ?>" href="<?php echo BASE_PATH; ?>auth/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a>
          <a class="list-group-item list-group-item-action text-danger" href="<?php echo BASE_PATH; ?>auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
        </div>
      </aside>
    <?php endif; ?>
    <main class="<?php echo !empty($_SESSION['user_id']) ? 'col-md-9 col-lg-10 with-sidebar' : 'col-12'; ?> py-4">
<?php if ($msg = flash('success')): ?>
  <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
<?php if ($msg = flash('error')): ?>
  <div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>
