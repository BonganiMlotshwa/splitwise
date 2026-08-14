<?php
require_once __DIR__ . '/../config.php';
require_login();

$report = $_GET['report'] ?? '';
$format = $_GET['format'] ?? '';
if ($report && $format) {
    $params = $_GET;
    unset($params['report'], $params['format']);
    $qs = http_build_query($params);
    if ($report === 'items' && $format === 'csv') {
        header('Location: ' . BASE_PATH . 'reports/export_csv.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }
    if ($report === 'items' && $format === 'pdf') {
        header('Location: ' . BASE_PATH . 'reports/export_pdf.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }
    if ($report === 'activity' && $format === 'csv') {
        header('Location: ' . BASE_PATH . 'reports/export_activity_csv.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }
    if ($report === 'activity' && $format === 'pdf') {
        header('Location: ' . BASE_PATH . 'reports/export_activity_pdf.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3">Export</h1>
<form method="get" class="card p-3" action="<?php echo BASE_PATH; ?>reports/export.php">
  <div class="row g-3">
    <div class="col-sm-6 col-md-4">
      <label class="form-label">Report</label>
      <select class="form-select" name="report" id="report-select" required>
        <option value="" hidden>Select report</option>
        <option value="items" <?php echo ($report==='items')?'selected':''; ?>>Items</option>
        <option value="activity" <?php echo ($report==='activity')?'selected':''; ?>>Activity Log</option>
      </select>
    </div>
    <div class="col-sm-6 col-md-4">
      <label class="form-label">Format</label>
      <select class="form-select" name="format" id="format-select" required>
        <option value="" hidden>Select format</option>
        <option value="csv" <?php echo ($format==='csv')?'selected':''; ?>>CSV</option>
        <option value="pdf" <?php echo ($format==='pdf')?'selected':''; ?>>PDF</option>
      </select>
    </div>
  </div>

  <div id="section-items" class="mt-3" style="display:none;">
    <div class="row g-3">
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <option value="available" <?php echo (($_GET['status'] ?? '')==='available')?'selected':''; ?>>Available</option>
          <option value="checked_out" <?php echo (($_GET['status'] ?? '')==='checked_out')?'selected':''; ?>>Checked Out</option>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Sort</label>
        <select class="form-select" name="sort">
          <?php foreach (['id','item_name','serial_number','category','description','taken_by','department','date_taken','expected_return_date','date_returned','status'] as $col): ?>
            <option value="<?php echo $col; ?>" <?php echo (($_GET['sort'] ?? '')===$col)?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$col)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Direction</label>
        <select class="form-select" name="dir">
          <option value="asc" <?php echo (($_GET['dir'] ?? '')==='asc')?'selected':''; ?>>Ascending</option>
          <option value="desc" <?php echo (($_GET['dir'] ?? 'desc')==='desc')?'selected':''; ?>>Descending</option>
        </select>
      </div>
    </div>
  </div>

  <div id="section-activity" class="mt-3" style="display:none;">
    <div class="row g-3">
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Date</label>
        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($_GET['date'] ?? date('Y-m-d')); ?>">
      </div>
      <div class="col-sm-6 col-md-3">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
      </div>
    </div>
  </div>

  <div class="mt-3">
    <button class="btn btn-primary" type="submit">Export</button>
  </div>
</form>
<script>
(function(){
  const report = document.getElementById('report-select');
  const secItems = document.getElementById('section-items');
  const secAct = document.getElementById('section-activity');
  function sync(){
    const v = report.value;
    secItems.style.display = (v==='items') ? '' : 'none';
    secAct.style.display = (v==='activity') ? '' : 'none';
  }
  report.addEventListener('change', sync);
  sync();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
