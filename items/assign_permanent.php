<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$errors = [];
$success = '';
$preSelectedIds = [];

// Check for pre-selected items from URL
if (isset($_GET['items'])) {
    $itemsParam = $_GET['items'];
    $preSelectedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $itemsParam)))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['item_ids'] ?? []))));
    $assigned_to = trim($_POST['assigned_to'] ?? '');
    $ftm_pin = trim($_POST['ftm_pin'] ?? '');
    $assigned_by = trim($_POST['assigned_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($item_ids)) $errors[] = 'Please select at least one item to assign.';
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

            $conn->beginTransaction();
            $assignedIds = [];
            $currentItem = $conn->prepare("SELECT notes FROM items WHERE id = ?");
            $stmt = $conn->prepare("UPDATE items SET 
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
                    WHERE id=?");

            foreach ($item_ids as $item_id) {
                $currentItem->execute([$item_id]);
                $item = $currentItem->fetch();
                if (!$item) continue;

                $updatedNotes = !empty($item['notes']) ? $item['notes'] . "\n\n" . $assignmentNote : $assignmentNote;
                $stmt->execute([$assigned_to, $ftm_pin, $userId, $department, $updatedNotes, $userId, $item_id]);
                if ($stmt->rowCount() > 0) $assignedIds[] = $item_id;
            }
            
            if (!empty($assignedIds)) {
                foreach ($assignedIds as $item_id) {
                    $details = json_encode([
                        'assigned_to' => $assigned_to,
                        'ftm_pin' => $ftm_pin,
                        'assigned_by' => $assigned_by,
                        'department' => $department,
                        'notes' => $notes,
                    ]);
                    write_activity($conn, 'permanent_assignment', 'item', $item_id, $details);
                }
                $conn->commit();
                $skipped = count($item_ids) - count($assignedIds);
                $_SESSION['success'] = count($assignedIds) . ' item(s) permanently assigned successfully!' . ($skipped > 0 ? ' ' . $skipped . ' item(s) could not be found.' : '');
                header('Location: ' . BASE_PATH . 'items/items.php');
                exit;
            } else {
                $conn->rollBack();
                $errors[] = 'Unable to assign the selected item(s). Please try again.';
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $errors[] = 'Failed to assign item: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';

// Load available items
$available = [];
$res = $conn->query("SELECT id, item_name, serial_number, category, description, status FROM items WHERE status IN ('available', 'checked_out') ORDER BY item_name");
$available = $res->fetchAll();

// Fetch unique people (taken_by) from items that have been assigned
$people = [];
$peopleRes = $conn->query("SELECT DISTINCT taken_by FROM items WHERE taken_by IS NOT NULL AND taken_by != '' ORDER BY taken_by");
$peopleRows = $peopleRes->fetchAll();
foreach ($peopleRows as $row) {
    if (!empty($row['taken_by'])) {
        $people[] = $row['taken_by'];
    }
}

// Add hardcoded staff list if not enough people found
if (count($people) < 5) {
    $staffList = [
        'Boniswa Kunene','Nkosikhona Dludlu','Thabo Dlamini','Sibongakonke Mamba','Mbongiseni Nkambule',
        'Nothando Motsa','Bongani Mlotshwa','Sibusiso Zwane','Sibusiso Tsabedze','Nombulelo Simelane',
        'Makabongwe Mkhonta','Ntokozo Thwala','Khululiwe Motsa','Phikisile Maseko','Lindokuhle Makhaya'
    ];
    $people = array_unique(array_merge($people, $staffList));
    sort($people);
}

// Fetch unique departments from items
$departments = [];
$deptRes = $conn->query("SELECT DISTINCT department FROM items WHERE department IS NOT NULL AND department != '' ORDER BY department");
$deptRows = $deptRes->fetchAll();
foreach ($deptRows as $row) {
    if (!empty($row['department'])) {
        $departments[] = $row['department'];
    }
}

// Add default departments if not enough found
if (count($departments) < 3) {
    $defaultDepts = ['IT', 'Finance', 'HR', 'Marketing', 'Operations', 'Management'];
    $departments = array_unique(array_merge($departments, $defaultDepts));
    sort($departments);
}
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
<?php echo csrf_field(); ?>
<div class="mb-3">
      <label class="form-label">Items to Assign <span id="itemCountBadge" class="badge text-bg-primary ms-2" style="display:none;"></span></label>
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
            <?php foreach ($available as $it): ?>
              <?php
                $label = $it['item_name'];
                $desc = trim($it['description'] ?? '');
                $sn = trim($it['serial_number'] ?? '');
                if ($desc !== '') { $label .= " - " . $desc; }
                if ($sn !== '') { $label .= " (" . $sn . ")"; }
                if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
                $label .= " - #" . (int)$it['id'];
                $statusBadge = $it['status'] === 'available' ? 'Available' : 'Checked Out';
                $label .= " - " . $statusBadge;
                $searchBlob = strtolower($it['item_name'] . ' ' . ($it['description'] ?? '') . ' ' . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' ' . $it['id']);
                $isPreSelected = in_array($it['id'], $preSelectedIds, true) ? '1' : '0';
              ?>
              <button type="button" class="list-group-item list-group-item-action item-option d-flex justify-content-between align-items-center"
                      data-id="<?php echo (int)$it['id']; ?>"
                      data-label="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>"
                      data-text="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>"
                      data-selected="<?php echo $isPreSelected; ?>">
                <span><?php echo htmlspecialchars($label); ?></span>
                <i class="bi bi-check-circle-fill text-success" style="display:none;"></i>
              </button>
            <?php endforeach; ?>
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
  
  <div class="mb-3">
    <label class="form-label">Assigned To (Employee Name) <span class="text-danger">*</span></label>
    <input type="text" name="assigned_to" class="form-control" placeholder="e.g., John Doe" list="peopleList" required autocomplete="off">
    <datalist id="peopleList">
      <?php foreach ($people as $p): ?>
        <option value="<?php echo htmlspecialchars($p); ?>">
      <?php endforeach; ?>
    </datalist>
    <small class="form-text text-muted">Enter the name of the person receiving this item (or select from suggestions)</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">FTM PIN <span class="text-danger">*</span></label>
    <input type="text" name="ftm_pin" class="form-control" placeholder="e.g., FTM-001 or 001" required>
    <small class="form-text text-muted">Enter the employee's FTM PIN (e.g., FTM-001, FTM-123)</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Department (Receiver's Department) <span class="text-danger">*</span></label>
    <input type="text" name="department" class="form-control" placeholder="e.g., IT, Finance, HR, Marketing" list="departmentList" required autocomplete="off">
    <datalist id="departmentList">
      <?php foreach ($departments as $d): ?>
        <option value="<?php echo htmlspecialchars($d); ?>">
      <?php endforeach; ?>
    </datalist>
    <small class="form-text text-muted">Enter the department of the person receiving the item (or select from suggestions)</small>
  </div>
  
  <div class="mb-3">
    <label class="form-label">Issued By <span class="text-danger">*</span></label>
    <?php
      $issuers = [];
      try {
          $ir = $conn->query("SELECT name FROM employees WHERE active = true ORDER BY name");
          $issuers = $ir ? $ir->fetchAll(PDO::FETCH_COLUMN) : [];
      } catch (Throwable $e) {}
    ?>
    <input type="text" name="assigned_by" class="form-control" list="issuerList" required
           placeholder="Select or type a name"
           value="<?php echo htmlspecialchars($_POST['assigned_by'] ?? ''); ?>">
    <datalist id="issuerList">
      <?php foreach ($issuers as $p): ?>
        <option value="<?php echo htmlspecialchars($p, ENT_QUOTES); ?>">
      <?php endforeach; ?>
    </datalist>
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
    listStats.textContent = `${selectedVisible}/${visible} visible, ${selected.size}/${total} total`;
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
    // Focus on the next form field (Assigned To)
    const assignedToField = form.querySelector('input[name="assigned_to"]');
    if (assignedToField) {
      setTimeout(() => assignedToField.focus(), 100);
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

  // Pre-select items
  dropdown.querySelectorAll('.item-option[data-selected="1"]').forEach((opt) => {
    selectOption(opt);
  });
  
  render();
  updateStats();
}

setupItemPicker({});
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
