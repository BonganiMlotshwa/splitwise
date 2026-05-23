<?php
require_once __DIR__ . '/../config.php';
require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkout_type = trim($_POST['checkout_type'] ?? 'existing');
    $taken_by = trim($_POST['taken_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $expected_return_date = trim($_POST['expected_return_date'] ?? '');

    if ($taken_by === '') $errors[] = 'Please enter who is taking the item.';
    // Restrict department to only IT or ERP
    $allowedDepartments = ['IT','ERP'];
    if ($department === '' || !in_array($department, $allowedDepartments, true)) {
        $errors[] = 'Please select a valid department (IT or ERP).';
    }

    if ($checkout_type === 'existing') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id <= 0) $errors[] = 'Please select an item.';
    } else {
        // New item validation
        $item_name = trim($_POST['new_item_name'] ?? '');
        $serial_number = trim($_POST['new_serial_number'] ?? '');
        $category = trim($_POST['new_category'] ?? '');
        $description = trim($_POST['new_description'] ?? '');
        
        if ($item_name === '') $errors[] = 'Please enter the item name.';
        if ($category === '') $errors[] = 'Please enter the category.';
    }

    if (empty($errors)) {
        try {
            if ($checkout_type === 'existing') {
                // Existing item checkout
                if ($expected_return_date === '') {
                    // No date provided: store NULL
                    $sql = "UPDATE items SET status='checked_out', returned=false, taken_by=?, department=?, date_taken=NOW(), expected_return_date=NULL, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$taken_by, $department, $item_id]);
                } else {
                    // Date provided: store the given value
                    $sql = "UPDATE items SET status='checked_out', returned=false, taken_by=?, department=?, date_taken=NOW(), expected_return_date=?, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$taken_by, $department, $expected_return_date, $item_id]);
                }
                if ($stmt->rowCount() > 0) {
                    // Log activity
                    $details = json_encode([
                        'taken_by' => $taken_by,
                        'department' => $department,
                        'expected_return_date' => $expected_return_date,
                    ]);
                    write_activity($conn, 'checkout', 'item', $item_id, $details);
                    $_SESSION['success'] = 'Item checked out successfully!';
                    header('Location: ' . BASE_PATH . 'items/items.php');
                    exit;
                } else {
                    $errors[] = 'Unable to check out the item. Ensure it is available.';
                }
            } else {
                // Add new item and check it out immediately
                if ($expected_return_date === '') {
                    $sql = "INSERT INTO items (item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, 'checked_out', false, ?, ?, NOW(), NULL, NULL, NOW(), NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$item_name, $serial_number, $category, $description, $taken_by, $department]);
                } else {
                    $sql = "INSERT INTO items (item_name, serial_number, category, description, status, returned, taken_by, department, date_taken, expected_return_date, date_returned, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, 'checked_out', false, ?, ?, NOW(), ?, NULL, NOW(), NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$item_name, $serial_number, $category, $description, $taken_by, $department, $expected_return_date]);
                }
                
                $new_item_id = $conn->lastInsertId();
                
                // Log activity for new item creation and checkout
                $details = json_encode([
                    'action' => 'add_and_checkout',
                    'item_name' => $item_name,
                    'serial_number' => $serial_number,
                    'category' => $category,
                    'description' => $description,
                    'taken_by' => $taken_by,
                    'department' => $department,
                    'expected_return_date' => $expected_return_date,
                ]);
                write_activity($conn, 'add_and_checkout', 'item', $new_item_id, $details);
                $_SESSION['success'] = 'New item added and checked out successfully!';
                header('Location: ' . BASE_PATH . 'items/items.php');
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to process checkout: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';

// Load available items
$available = [];
$res = $conn->query("SELECT id, item_name, serial_number, category, description FROM items WHERE status='available' ORDER BY item_name");
$available = $res->fetchAll();
?>
<h1 class="h4 mb-3">Check Out Item</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>

<form method="post" class="card p-3">
  <!-- Checkout Type Toggle -->
  <div class="mb-4">
    <label class="form-label fw-bold">Checkout Type</label>
    <div class="btn-group w-100" role="group">
      <input type="radio" class="btn-check" name="checkout_type" id="existing_item" value="existing" checked>
      <label class="btn btn-outline-primary" for="existing_item">
        <i class="bi bi-box me-2"></i>Existing Item
      </label>
      
      <input type="radio" class="btn-check" name="checkout_type" id="new_item" value="new">
      <label class="btn btn-outline-success" for="new_item">
        <i class="bi bi-plus-circle me-2"></i>Add New Item & Check Out
      </label>
    </div>
  </div>

  <!-- Existing Item Section -->
  <div id="existing_item_section">
    <div class="mb-3">
      <label class="form-label">Select Item</label>
      <input type="search" id="itemSearch" class="form-control mb-2"
             placeholder="Type item name or serial number to filter…" autocomplete="off">
      <select name="item_id" id="itemSelect" class="form-select" size="8">
        <option value="">-- Select an item --</option>
        <?php foreach ($available as $it): ?>
          <?php
            $sn = trim($it['serial_number'] ?? '');
            $label = $it['item_name'];
            $desc = trim($it['description'] ?? '');
            if ($desc !== '') { $label .= " — " . $desc; }
            if ($sn !== '') { $label .= " (" . $sn . ")"; }
            if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
            $label .= " — #" . (int)$it['id'];
            $searchBlob = strtolower($it['item_name'] . ' ' . ($it['description'] ?? '') . ' ' . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' ' . $it['id']);
          ?>
          <option value="<?php echo (int)$it['id']; ?>" data-text="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>"><?php echo htmlspecialchars($label); ?></option>
        <?php endforeach; ?>
      </select>
      <div id="itemSearchNoMatch" class="form-text text-danger d-none">No matching items.</div>
    </div>
  </div>

  <!-- New Item Section -->
  <div id="new_item_section" class="d-none">
    <div class="alert alert-info">
      <i class="bi bi-info-circle me-2"></i>
      <strong>Add New Item:</strong> Fill in the details below. The item will be added to inventory and immediately checked out.
    </div>
    
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Item Name <span class="text-danger">*</span></label>
        <input type="text" name="new_item_name" class="form-control" placeholder="e.g., Wireless Mouse">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Serial Number</label>
        <input type="text" name="new_serial_number" class="form-control" placeholder="e.g., SN123456 or leave blank">
      </div>
    </div>
    
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Category <span class="text-danger">*</span></label>
        <input type="text" name="new_category" class="form-control" placeholder="e.g., Mouse, Keyboard, Cable" list="categories">
        <datalist id="categories">
          <?php
            // Get existing categories for suggestions
            $catRes = $conn->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != '' ORDER BY category");
            while ($cat = $catRes->fetch()) {
              echo '<option value="'.htmlspecialchars($cat['category']).'">';
            }
          ?>
        </datalist>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Description</label>
        <input type="text" name="new_description" class="form-control" placeholder="Brief description (optional)">
      </div>
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label">Taken By</label>
    <?php $taken_by_val = $_POST['taken_by'] ?? ''; ?>
    <select name="taken_by" class="form-select" required>
      <option value="">-- Select Person --</option>
      <?php
        $people = [
          'Boniswa Kunene','Nkosikhona Dludlu','Thabo Dlamini','Sibongakonke Mamba','Mbongiseni Nkambule',
          'Nothando Motsa','Bongani Mlotshwa','Sibusiso Zwane','Sibusiso Tsabedze','Nombulelo Simelane',
          'Makabongwe Mkhonta','Ntokozo Thwala','Khululiwe Motsa','Phikisile Maseko','Lindokuhle Makhaya'
        ];
        foreach ($people as $p) {
          $sel = ($taken_by_val === $p) ? 'selected' : '';
          echo '<option value="'.htmlspecialchars($p, ENT_QUOTES).'" '.$sel.'>'.htmlspecialchars($p).'</option>';
        }
      ?>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">Department</label>
    <select name="department" class="form-select" required>
      <option value="">-- Select Department --</option>
      <option value="IT">IT</option>
      <option value="ERP">ERP</option>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">Expected Return Date</label>
    <input type="datetime-local" name="expected_return_date" class="form-control">
  </div>
  <div class="d-flex justify-content-end gap-2">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Check Out</button>
  </div>
</form>
<script>
  (function(){
    // Toggle between existing and new item sections
    const existingRadio = document.getElementById('existing_item');
    const newRadio = document.getElementById('new_item');
    const existingSection = document.getElementById('existing_item_section');
    const newSection = document.getElementById('new_item_section');
    const itemSelect = document.getElementById('itemSelect');
    const newItemInputs = document.querySelectorAll('#new_item_section input[required], #new_item_section input[name^="new_"]');

    function toggleSections() {
      if (existingRadio.checked) {
        existingSection.classList.remove('d-none');
        newSection.classList.add('d-none');
        // Make existing item select required
        itemSelect.required = true;
        // Remove required from new item fields
        newItemInputs.forEach(input => {
          if (input.name === 'new_item_name' || input.name === 'new_category') {
            input.required = false;
          }
        });
      } else {
        existingSection.classList.add('d-none');
        newSection.classList.remove('d-none');
        // Remove required from existing item select
        itemSelect.required = false;
        itemSelect.value = '';
        // Make new item fields required
        newItemInputs.forEach(input => {
          if (input.name === 'new_item_name' || input.name === 'new_category') {
            input.required = true;
          }
        });
      }
    }

    existingRadio.addEventListener('change', toggleSections);
    newRadio.addEventListener('change', toggleSections);
    
    // Initialize
    toggleSections();

    // Existing item search functionality
    const input = document.getElementById('itemSearch');
    const select = document.getElementById('itemSelect');
    const noMatch = document.getElementById('itemSearchNoMatch');
    if (!input || !select) return;
    const filter = () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      for (const opt of select.options) {
        if (!opt.value) { // placeholder
          continue;
        }
        const blob = (opt.getAttribute('data-text') || opt.textContent || '').toLowerCase();
        const match = q === '' || blob.indexOf(q) !== -1;
        if (match) {
          opt.hidden = false;
          shown++;
        } else {
          opt.hidden = true;
        }
      }
      noMatch?.classList.toggle('d-none', shown !== 0);
      // If current selected is hidden, clear selection
      const selOpt = select.selectedOptions[0];
      if (selOpt && selOpt.hidden) { select.value = ''; }
    };
    input.addEventListener('input', filter);
    // Enter selects first visible option
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        for (const opt of select.options) {
          if (opt.value && !opt.hidden) { select.value = opt.value; break; }
        }
        e.preventDefault();
      }
    });
  })();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
