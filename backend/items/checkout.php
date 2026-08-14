<?php
require_once __DIR__ . '/../config.php';
require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
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
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['item_ids'] ?? []))));
        if (empty($item_ids)) $errors[] = 'Please select at least one item.';
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
                $checkedOutIds = [];
                $conn->beginTransaction();
                if ($expected_return_date === '') {
                    $sql = "UPDATE items SET status='checked_out', returned=false, taken_by=?, department=?, date_taken=NOW(), expected_return_date=NULL, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
                    $stmt = $conn->prepare($sql);
                    foreach ($item_ids as $item_id) {
                        $stmt->execute([$taken_by, $department, $item_id]);
                        if ($stmt->rowCount() > 0) $checkedOutIds[] = $item_id;
                    }
                } else {
                    $sql = "UPDATE items SET status='checked_out', returned=false, taken_by=?, department=?, date_taken=NOW(), expected_return_date=?, date_returned=NULL, updated_at=NOW() WHERE id=? AND status='available'";
                    $stmt = $conn->prepare($sql);
                    foreach ($item_ids as $item_id) {
                        $stmt->execute([$taken_by, $department, $expected_return_date, $item_id]);
                        if ($stmt->rowCount() > 0) $checkedOutIds[] = $item_id;
                    }
                }
                if (!empty($checkedOutIds)) {
                    foreach ($checkedOutIds as $item_id) {
                        $details = json_encode([
                            'taken_by' => $taken_by,
                            'department' => $department,
                            'expected_return_date' => $expected_return_date,
                        ]);
                        write_activity($conn, 'checkout', 'item', $item_id, $details);
                    }
                    $conn->commit();
                    $skipped = count($item_ids) - count($checkedOutIds);
                    $_SESSION['success'] = count($checkedOutIds) . ' item(s) checked out successfully!' . ($skipped > 0 ? ' ' . $skipped . ' item(s) were skipped because they are no longer available.' : '');
                    header('Location: ' . BASE_PATH . 'items/items.php');
                    exit;
                } else {
                    $conn->rollBack();
                    $errors[] = 'Unable to check out the selected item(s). Ensure they are available.';
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
            if ($conn->inTransaction()) $conn->rollBack();
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
  <?php echo csrf_field(); ?>
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
      <label class="form-label">Select Items <span id="itemCountBadge" class="badge text-bg-primary ms-2" style="display:none;"></span></label>
      <div class="item-picker-wrap">
        <div class="input-group mb-2">
          <input type="search" id="itemSearch" class="form-control"
                 placeholder="Search by name, serial number, category, or ID..." autocomplete="off">
          <button type="button" class="btn btn-outline-secondary" id="clearSearchBtn" title="Clear search">
            <i class="bi bi-x"></i>
          </button>
        </div>
        <div id="selectedItems" class="form-control d-flex flex-wrap gap-2 align-items-center selected-items-box"></div>
        <div id="itemDropdown" class="list-group position-absolute w-100 shadow-sm item-picker-menu d-none" style="max-width: 100%;">
          <div class="sticky-top bg-white p-2 border-bottom d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">
              <i class="bi bi-check-all"></i> Select All
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="selectVisibleBtn">
              <i class="bi bi-check2-square"></i> Select Visible
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">
              <i class="bi bi-x-circle"></i> Deselect All
            </button>
            <button type="button" class="btn btn-sm btn-success ms-auto" id="doneBtn">
              <i class="bi bi-check-lg"></i> Done
            </button>
            <div class="ms-2 text-muted small pt-2" id="listStats"></div>
          </div>
          <div id="itemListContainer">
            <?php if (empty($available)): ?>
              <div class="list-group-item text-muted text-center py-3">
                <i class="bi bi-inbox"></i><br>
                <small>No available items to check out.<br>All items are either checked out or permanently assigned.</small>
              </div>
            <?php else: ?>
              <?php foreach ($available as $it): ?>
                <?php
                  $label = $it['item_name'];
                  $desc = trim($it['description'] ?? '');
                  $sn = trim($it['serial_number'] ?? '');
                  if ($desc !== '') { $label .= " - " . $desc; }
                  if ($sn !== '') { $label .= " (" . $sn . ")"; }
                  if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
                  $label .= " - #" . (int)$it['id'];
                  $searchBlob = strtolower($it['item_name'] . ' ' . ($it['description'] ?? '') . ' ' . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' ' . $it['id']);
                ?>
                <button type="button" class="list-group-item list-group-item-action item-option d-flex justify-content-between align-items-center"
                        data-id="<?php echo (int)$it['id']; ?>"
                        data-label="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>"
                        data-text="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
                  <span><?php echo htmlspecialchars($label); ?></span>
                  <i class="bi bi-check-circle-fill text-success" style="display:none;"></i>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div id="itemSearchNoMatch" class="list-group-item text-muted text-center py-3 d-none">
            <small>No matching items found. Try a different search term.</small>
          </div>
        </div>
      </div>
      <div id="selectedItemInputs"></div>
      <small class="form-text text-muted">
        <i class="bi bi-info-circle"></i> 
        <strong>Search & Select:</strong> Type to filter • <strong>Click</strong> to toggle • <strong>Shift+Click</strong> to select range • <strong>Ctrl+Click</strong> to multi-select •
        Use buttons above the list to select all, visible items, or deselect
      </small>
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
    <label class="form-label">Taken By <span class="text-danger">*</span></label>
    <?php
      $taken_by_val = $_POST['taken_by'] ?? '';
      // Query employees from DB; fall back to distinct taken_by history if table not available
      $peopleList = [];
      try {
          $pr = $conn->query("SELECT name FROM employees WHERE active = true ORDER BY name");
          $peopleList = $pr ? $pr->fetchAll(PDO::FETCH_COLUMN) : [];
      } catch (Throwable $e) {}
      if (empty($peopleList)) {
          try {
              $pr = $conn->query("SELECT DISTINCT taken_by FROM items WHERE taken_by IS NOT NULL AND taken_by <> '' ORDER BY taken_by");
              $peopleList = $pr ? $pr->fetchAll(PDO::FETCH_COLUMN) : [];
          } catch (Throwable $e) {}
      }
    ?>
    <input type="text" name="taken_by" class="form-control" required
           list="taken_by_list" placeholder="Select or type a name"
           value="<?php echo htmlspecialchars($taken_by_val); ?>">
    <datalist id="taken_by_list">
      <?php foreach ($peopleList as $p): ?>
        <option value="<?php echo htmlspecialchars($p, ENT_QUOTES); ?>">
      <?php endforeach; ?>
    </datalist>
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
    const newItemInputs = document.querySelectorAll('#new_item_section input[required], #new_item_section input[name^="new_"]');

    function toggleSections() {
      if (existingRadio.checked) {
        existingSection.classList.remove('d-none');
        newSection.classList.add('d-none');
        // Remove required from new item fields
        newItemInputs.forEach(input => {
          if (input.name === 'new_item_name' || input.name === 'new_category') {
            input.required = false;
          }
        });
      } else {
        existingSection.classList.add('d-none');
        newSection.classList.remove('d-none');
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

function setupItemPicker(config) {
  const input = document.getElementById(config.inputId || 'itemSearch');
  const dropdown = document.getElementById(config.dropdownId || 'itemDropdown');
  const selectedBox = document.getElementById(config.selectedId || 'selectedItems');
  const inputBox = document.getElementById(config.inputsId || 'selectedItemInputs');
  const noMatch = document.getElementById(config.noMatchId || 'itemSearchNoMatch');
  const countBadge = document.getElementById('itemCountBadge');
  const clearBtn = document.getElementById('clearSearchBtn');
  const selectAllBtn = document.getElementById('selectAllBtn');
  const selectVisibleBtn = document.getElementById('selectVisibleBtn');
  const deselectAllBtn = document.getElementById('deselectAllBtn');
  const listStats = document.getElementById('listStats');
  const form = input ? input.closest('form') : null;
  if (!input || !dropdown || !selectedBox || !inputBox || !form) return;

  const selected = new Map();
  let lastClicked = null;

  const render = () => {
    selectedBox.innerHTML = '';
    inputBox.innerHTML = '';
    
    if (countBadge) {
      if (selected.size > 0) {
        countBadge.textContent = selected.size + ' selected';
        countBadge.style.display = 'inline-block';
      } else {
        countBadge.style.display = 'none';
      }
    }

    if (selected.size === 0) {
      const empty = document.createElement('span');
      empty.className = 'text-muted';
      empty.textContent = 'No items selected.';
      selectedBox.appendChild(empty);
      return;
    }

    selected.forEach((label, id) => {
      const chip = document.createElement('span');
      chip.className = 'badge text-bg-primary d-inline-flex align-items-center gap-2 text-wrap item-chip';
      const text = document.createElement('span');
      text.textContent = label;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn-close btn-close-white';
      remove.setAttribute('aria-label', 'Remove item');
      remove.addEventListener('click', (e) => {
        e.preventDefault();
        selected.delete(id);
        const option = dropdown.querySelector('[data-id="' + CSS.escape(id) + '"]');
        if (option) {
          option.classList.remove('active');
          option.querySelector('i')?.style.setProperty('display', 'none');
        }
        render();
        updateStats();
      });
      chip.appendChild(text);
      chip.appendChild(remove);
      selectedBox.appendChild(chip);

      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = config.fieldName || 'item_ids[]';
      hidden.value = id;
      inputBox.appendChild(hidden);
    });
  };

  const updateStats = () => {
    const total = dropdown.querySelectorAll('.item-option').length;
    const visible = Array.from(dropdown.querySelectorAll('.item-option')).filter(opt => !opt.classList.contains('d-none')).length;
    const selectedVisible = Array.from(dropdown.querySelectorAll('.item-option.active')).filter(opt => !opt.classList.contains('d-none')).length;
    if (listStats) listStats.textContent = `${selectedVisible}/${visible} visible, ${selected.size}/${total} total`;
  };

  const filter = () => {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    dropdown.querySelectorAll('.item-option').forEach((opt) => {
      const text = (opt.getAttribute('data-text') || opt.textContent || '').toLowerCase();
      const match = q === '' || text.indexOf(q) !== -1;
      opt.classList.toggle('d-none', !match);
      if (match) shown++;
    });
    if (noMatch) noMatch.classList.toggle('d-none', shown !== 0);
    updateStats();
  };

  const open = () => {
    filter();
    dropdown.classList.remove('d-none');
  };

  const close = () => dropdown.classList.add('d-none');

  const selectOption = (opt, multi = false) => {
    const id = opt.dataset.id;
    if (multi) {
      if (selected.has(id)) {
        selected.delete(id);
        opt.classList.remove('active');
        opt.querySelector('i')?.style.setProperty('display', 'none');
      } else {
        selected.set(id, opt.dataset.label);
        opt.classList.add('active');
        opt.querySelector('i')?.style.setProperty('display', 'inline-block');
      }
    } else {
      selected.set(id, opt.dataset.label);
      opt.classList.add('active');
      opt.querySelector('i')?.style.setProperty('display', 'inline-block');
    }
    lastClicked = opt;
    render();
    updateStats();
  };

  const selectRange = (from, to) => {
    const options = Array.from(dropdown.querySelectorAll('.item-option:not(.d-none)'));
    const fromIdx = options.indexOf(from);
    const toIdx = options.indexOf(to);
    if (fromIdx === -1 || toIdx === -1) return;
    
    const start = Math.min(fromIdx, toIdx);
    const end = Math.max(fromIdx, toIdx);
    
    for (let i = start; i <= end; i++) {
      const opt = options[i];
      const id = opt.dataset.id;
      if (!selected.has(id)) {
        selected.set(id, opt.dataset.label);
        opt.classList.add('active');
        opt.querySelector('i')?.style.setProperty('display', 'inline-block');
      }
    }
    render();
    updateStats();
  };

  const selectAll = () => {
    dropdown.querySelectorAll('.item-option').forEach((opt) => {
      const id = opt.dataset.id;
      selected.set(id, opt.dataset.label);
      opt.classList.add('active');
      opt.querySelector('i')?.style.setProperty('display', 'inline-block');
    });
    render();
    updateStats();
  };

  const selectVisible = () => {
    dropdown.querySelectorAll('.item-option:not(.d-none)').forEach((opt) => {
      const id = opt.dataset.id;
      selected.set(id, opt.dataset.label);
      opt.classList.add('active');
      opt.querySelector('i')?.style.setProperty('display', 'inline-block');
    });
    render();
    updateStats();
  };

  const deselectAll = () => {
    selected.clear();
    dropdown.querySelectorAll('.item-option').forEach((opt) => {
      opt.classList.remove('active');
      opt.querySelector('i')?.style.setProperty('display', 'none');
    });
    render();
    updateStats();
  };

  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.item-option');
    if (opt) {
      e.preventDefault();
      if (e.shiftKey && lastClicked) {
        selectRange(lastClicked, opt);
      } else if (e.ctrlKey || e.metaKey) {
        selectOption(opt, true);
      } else {
        selectOption(opt, true);
      }
    }
  });

  selectAllBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    selectAll();
  });

  selectVisibleBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    selectVisible();
  });

  deselectAllBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    deselectAll();
  });

  const doneBtn = document.getElementById('doneBtn');
  doneBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    close();
    input.blur();
    // Focus on the next form field (Taken By)
    const takenByField = form.querySelector('select[name="taken_by"]');
    if (takenByField) {
      setTimeout(() => takenByField.focus(), 100);
    }
  });

  input.addEventListener('focus', open);
  input.addEventListener('input', () => {
    open();
    lastClicked = null;
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const first = Array.from(dropdown.querySelectorAll('.item-option')).find((opt) => !opt.classList.contains('d-none'));
      if (first) {
        selectOption(first, true);
      }
      e.preventDefault();
    }
  });

  clearBtn?.addEventListener('click', () => {
    input.value = '';
    input.focus();
    filter();
    lastClicked = null;
  });

  selectedBox.addEventListener('click', () => input.focus());
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.item-picker-wrap')) close();
  });

  form.addEventListener('submit', (e) => {
    if (selected.size === 0 && (!config.shouldRequire || config.shouldRequire())) {
      e.preventDefault();
      input.focus();
      open();
    }
  });
  
  render();
  updateStats();
}

    setupItemPicker({ shouldRequire: () => existingRadio.checked });
  })();
</script>

<style>
  .item-picker-wrap { position: relative; }
  .item-picker-menu { 
    max-height: 400px; 
    overflow-y: auto; 
    z-index: 1030; 
    top: auto;
    left: 0;
    right: 0;
  }
  .item-picker-menu .sticky-top {
    z-index: 1031;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }
  .selected-items-box { 
    min-height: 42px; 
    height: auto; 
    cursor: text; 
    background-color: #f8f9fa;
  }
  .item-chip { 
    max-width: 100%; 
    white-space: normal; 
    text-align: left;
    font-weight: 500;
  }
  .item-option {
    border-left: 3px solid transparent;
    transition: all 0.15s ease;
  }
  .item-option:hover {
    background-color: #e9ecef;
    border-left-color: #0d6efd;
  }
  .item-option.active {
    background-color: #e7f1ff;
    border-left-color: #0d6efd;
  }
  .item-option i {
    font-size: 1.1em;
  }
  #itemCountBadge {
    font-size: 0.85em;
    animation: slideIn 0.2s ease;
  }
  @keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
  }
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
