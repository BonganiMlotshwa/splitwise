<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $assigned_to = trim($_POST['assigned_to'] ?? '');
    $ftm_pin = trim($_POST['ftm_pin'] ?? '');
    $assigned_by = trim($_POST['assigned_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($item_id <= 0) $errors[] = 'Please select an item to assign.';
    if ($assigned_to === '') $errors[] = 'Please enter the person this item is assigned to.';
    if ($ftm_pin === '') $errors[] = 'Please enter the FTM PIN.';
    if ($assigned_by === '') $errors[] = 'Please select who is making this assignment.';
    if ($department === '') $errors[] = 'Please enter the department.';

    if (empty($errors)) {
        try {
            // Get current user info
            $userId = $_SESSION['user_id'] ?? null;
            
            // Build assignment note
            $assignmentNote = "[Permanently Assigned " . date('Y-m-d H:i:s') . "] Assigned to: " . $assigned_to;
            $assignmentNote .= " | FTM PIN: " . $ftm_pin;
            $assignmentNote .= " | Assigned by: " . $assigned_by;
            if ($department !== '') {
                $assignmentNote .= " | Department: " . $department;
            }
            if ($notes !== '') {
                $assignmentNote .= " | Notes: " . $notes;
            }
            
            // Get current notes
            $currentItem = $conn->prepare("SELECT notes FROM items WHERE id = ?");
            $currentItem->execute([$item_id]);
            $item = $currentItem->fetch();
            
            // Append to existing notes
            $updatedNotes = '';
            if (!empty($item['notes'])) {
                $updatedNotes = $item['notes'] . "\n\n" . $assignmentNote;
            } else {
                $updatedNotes = $assignmentNote;
            }
            
            $sql = "UPDATE items SET 
                    status='permanently_assigned', 
                    returned=false, 
                    taken_by=?, 
                    ftm_pin=?,
                    taken_by_user_id=?, 
                    department=?, 
                    date_taken=NOW(), 
                    expected_return_date=NULL,
                    date_returned=NULL,
                    notes=?, 
                    updated_at=NOW(),
                    updated_by=?
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$assigned_to, $ftm_pin, $userId, $department, $updatedNotes, $userId, $item_id]);
            
            if ($stmt->rowCount() > 0) {
                // Log activity
                $details = json_encode([
                    'assigned_to' => $assigned_to,
                    'ftm_pin' => $ftm_pin,
                    'assigned_by' => $assigned_by,
                    'department' => $department,
                    'notes' => $notes,
                ]);
                write_activity($conn, 'permanent_assignment', 'item', $item_id, $details);
                $_SESSION['success'] = 'Item permanently assigned successfully!';
                header('Location: ' . BASE_PATH . 'items/items.php');
                exit;
            } else {
                $errors[] = 'Unable to assign the item. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to assign item: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';

// Load available items
$available = [];
$res = $conn->query("SELECT id, item_name, serial_number, category, description, status FROM items WHERE status IN ('available', 'checked_out') ORDER BY item_name");
$available = $res->fetchAll();
?>
<h1 class="h4 mb-3">Assign Item Permanently</h1>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> <strong>Permanent Assignment</strong><br>
    Use this to assign items that will not be returned (e.g., laptops assigned to employees, equipment for specific departments).
    These items will be marked as "permanently assigned" and won't appear in the return list.
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>

<form method="post" class="card p-3">
  <div class="mb-3">
    <label class="form-label">Item to Assign</label>
    <input type="search" id="itemSearch" class="form-control mb-2"
           placeholder="Type item name or serial number to filter…" autocomplete="off">
    <select name="item_id" id="itemSelect" class="form-select" required size="8">
      <option value="">-- Select an item --</option>
      <?php foreach ($available as $it): ?>
        <?php
          $label = $it['item_name'];
          $desc = trim($it['description'] ?? '');
          $sn = trim($it['serial_number'] ?? '');
          if ($desc !== '') { $label .= " — " . $desc; }
          if ($sn !== '') { $label .= " (" . $sn . ")"; }
          if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
          $label .= " — #" . (int)$it['id'];
          $statusBadge = $it['status'] === 'available' ? '✓ Available' : '⚠ Checked Out';
          $label .= " — " . $statusBadge;
          $searchBlob = strtolower(
              $it['item_name'] . ' ' . ($it['description'] ?? '') . ' '
              . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' ' . $it['id']
          );
        ?>
        <option value="<?php echo (int)$it['id']; ?>" data-text="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
          <?php echo htmlspecialchars($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div id="itemSearchNoMatch" class="form-text text-danger d-none">No matching items. Try another name or serial number.</div>
    <small class="form-text text-muted">Type to filter, then pick from the list (or press Enter to select the first match).</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Assigned To (Employee Name) <span class="text-danger">*</span></label>
    <input type="text" name="assigned_to" class="form-control" placeholder="e.g., John Doe" required>
    <small class="form-text text-muted">Enter the name of the person receiving this item</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">FTM PIN <span class="text-danger">*</span></label>
    <input type="text" name="ftm_pin" class="form-control" placeholder="e.g., FTM-001 or 001" required>
    <small class="form-text text-muted">Enter the employee's FTM PIN (e.g., FTM-001, FTM-123)</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Department (Receiver's Department) <span class="text-danger">*</span></label>
    <input type="text" name="department" class="form-control" placeholder="e.g., IT, Finance, HR, Marketing" required>
    <small class="form-text text-muted">Enter the department of the person receiving the item</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Issued By <span class="text-danger">*</span></label>
    <select name="assigned_by" class="form-select" required>
      <option value="">-- Select Person --</option>
      <?php
        $people = [
          'Boniswa Kunene','Nkosikhona Dludlu','Thabo Dlamini','Sibongakonke Mamba','Mbongiseni Nkambule',
          'Nothando Motsa','Bongani Mlotshwa','Sibusiso Zwane','Sibusiso Tsabedze','Nombulelo Simelane',
          'Makabongwe Mkhonta','Ntokozo Thwala','Khululiwe Motsa','Phikisile Maseko','Lindokuhle Makhaya'
        ];
        foreach ($people as $p) {
          echo '<option value="'.htmlspecialchars($p, ENT_QUOTES).'">'.htmlspecialchars($p).'</option>';
        }
      ?>
    </select>
    <small class="form-text text-muted">Select who is issuing/authorizing this assignment</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Notes (Optional)</label>
    <textarea name="notes" rows="3" class="form-control" placeholder="Any additional notes about this permanent assignment..."></textarea>
  </div>
  
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> <strong>Note:</strong> This item will be marked as permanently assigned and will not appear in the return list. 
    You can still edit or delete the item later if needed.
  </div>
  
  <div class="d-flex justify-content-end gap-2">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Assign Permanently</button>
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
