<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $condition_returned = trim($_POST['condition_returned'] ?? '');
    $return_notes = trim($_POST['return_notes'] ?? '');

    if ($item_id <= 0) $errors[] = 'Please select an item to return.';
    if ($condition_returned === '') $errors[] = 'Please select the condition when returned.';

    if (empty($errors)) {
        try {
            // Build the return note
            $returnNote = "[Returned " . date('Y-m-d H:i:s') . "] Condition: " . $condition_returned;
            if ($return_notes !== '') {
                $returnNote .= " | Notes: " . $return_notes;
            }
            
            // Get current notes
            $currentItem = $conn->prepare("SELECT notes FROM items WHERE id = ?");
            $currentItem->execute([$item_id]);
            $item = $currentItem->fetch();
            
            // Append to existing notes or create new
            $updatedNotes = '';
            if (!empty($item['notes'])) {
                $updatedNotes = $item['notes'] . "\n\n" . $returnNote;
            } else {
                $updatedNotes = $returnNote;
            }
            
            $sql = "UPDATE items SET 
                    status='available', 
                    returned=true, 
                    date_returned=NOW(), 
                    condition_returned=?, 
                    notes=?, 
                    taken_by=NULL, 
                    taken_by_user_id=NULL, 
                    department=NULL, 
                    expected_return_date=NULL, 
                    updated_at=NOW() 
                    WHERE id=? AND status='checked_out'";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$condition_returned, $updatedNotes, $item_id]);
            if ($stmt->rowCount() > 0) {
                // Log activity
                $details = json_encode([
                    'condition_returned' => $condition_returned,
                    'notes' => $return_notes,
                ]);
                write_activity($conn, 'return', 'item', $item_id, $details);
                $_SESSION['success'] = 'Item returned successfully!';
                header('Location: ' . BASE_PATH . 'items/items.php');
                exit;
            } else {
                $errors[] = 'Unable to return the item. Ensure it is currently checked out.';
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to return item: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';

// Get pre-selected item ID from URL
$preselected_item_id = (int)($_GET['item_id'] ?? 0);

// Load items that are checked out
$checked_out = [];
$res = $conn->query("SELECT id, item_name, serial_number, category, description, taken_by, department FROM items WHERE status='checked_out' ORDER BY item_name");
$checked_out = $res->fetchAll();

// If pre-selected item ID is provided, verify it exists and is checked out
$preselected_item = null;
if ($preselected_item_id > 0) {
    foreach ($checked_out as $item) {
        if ($item['id'] == $preselected_item_id) {
            $preselected_item = $item;
            break;
        }
    }
}
?>
<h1 class="h4 mb-3">Return Item</h1>

<?php if ($preselected_item): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  <strong>Returning item for:</strong> <?php echo htmlspecialchars($preselected_item['taken_by']); ?> 
  (<?php echo htmlspecialchars($preselected_item['department']); ?>)
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<form method="post" class="card p-3">
  <div class="mb-3">
    <label class="form-label">Item</label>
    <input type="search" id="itemSearch" class="form-control mb-2"
           placeholder="Type item name or serial number to filter…" autocomplete="off">
    <select name="item_id" id="itemSelect" class="form-select" required size="8"
            <?php echo $preselected_item ? 'style="background-color: #e3f2fd;"' : ''; ?>>
      <option value="">-- Select an item --</option>
      <?php foreach ($checked_out as $it): ?>
        <?php
          $label = $it['item_name'];
          $desc = trim($it['description'] ?? '');
          $sn = trim($it['serial_number'] ?? '');
          if ($desc !== '') { $label .= " — " . $desc; }
          if ($sn !== '') { $label .= " (" . $sn . ")"; }
          if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
          $label .= " — #" . (int)$it['id'];
          if ($it['taken_by']) {
              $label .= " — with: " . $it['taken_by'];
          }
          $selected = ($preselected_item && $it['id'] == $preselected_item['id']) ? 'selected' : '';
          $searchBlob = strtolower(
              $it['item_name'] . ' ' . ($it['description'] ?? '') . ' '
              . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' '
              . ($it['taken_by'] ?? '') . ' ' . ($it['department'] ?? '') . ' ' . $it['id']
          );
        ?>
        <option value="<?php echo (int)$it['id']; ?>" <?php echo $selected; ?>
                data-text="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
          <?php echo htmlspecialchars($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div id="itemSearchNoMatch" class="form-text text-danger d-none">No matching items. Try another name or serial number.</div>
    <small class="form-text text-muted">Type to filter, then pick from the list (or press Enter to select the first match).</small>
    <?php if ($preselected_item): ?>
    <div class="form-text text-primary">
      <i class="bi bi-check-circle me-1"></i>
      Item pre-selected from "Who Has What" report
    </div>
    <?php endif; ?>
  </div>
  <div class="mb-3">
    <label class="form-label">Condition When Returned</label>
    <select name="condition_returned" class="form-select" required>
      <option value="">-- Select Condition --</option>
      <option value="Excellent">Excellent - Like new</option>
      <option value="Good">Good - Minor wear and tear</option>
      <option value="Fair">Fair - Noticeable wear but functional</option>
      <option value="Poor">Poor - Significant wear or damage</option>
      <option value="Damaged">Damaged - Needs repair</option>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">Notes (Optional)</label>
    <textarea name="return_notes" rows="3" class="form-control" placeholder="Any additional notes about the return..."></textarea>
  </div>
  <div class="d-flex justify-content-end gap-2">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Mark as Returned</button>
  </div>
</form>

<script>
(function () {
  const input = document.getElementById('itemSearch');
  const select = document.getElementById('itemSelect');
  const noMatch = document.getElementById('itemSearchNoMatch');
  if (!input || !select) return;

  const filter = () => {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    for (const opt of select.options) {
      if (!opt.value) continue;
      const blob = (opt.getAttribute('data-text') || opt.textContent || '').toLowerCase();
      const match = q === '' || blob.indexOf(q) !== -1;
      opt.hidden = !match;
      if (match) shown++;
    }
    if (noMatch) noMatch.classList.toggle('d-none', shown !== 0);
    const sel = select.selectedOptions[0];
    if (sel && sel.hidden) select.value = '';
  };

  input.addEventListener('input', filter);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      for (const opt of select.options) {
        if (opt.value && !opt.hidden) {
          select.value = opt.value;
          break;
        }
      }
      e.preventDefault();
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
