<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $item_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['item_ids'] ?? []))));
    $condition_returned = trim($_POST['condition_returned'] ?? '');
    $return_notes = trim($_POST['return_notes'] ?? '');

    if (empty($item_ids)) $errors[] = 'Please select at least one item to return.';
    if ($condition_returned === '') $errors[] = 'Please select the condition when returned.';

    if (empty($errors)) {
        try {
            // Build the return note
            $returnNote = "[Returned " . date('Y-m-d H:i:s') . "] Condition: " . $condition_returned;
            if ($return_notes !== '') {
                $returnNote .= " | Notes: " . $return_notes;
            }

            $conn->beginTransaction();
            $returnedIds = [];
            $currentItem = $conn->prepare("SELECT notes FROM items WHERE id = ?");
            $stmt = $conn->prepare("UPDATE items SET 
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
                    WHERE id=? AND status='checked_out'");

            foreach ($item_ids as $item_id) {
                $currentItem->execute([$item_id]);
                $item = $currentItem->fetch();
                if (!$item) continue;

                $updatedNotes = !empty($item['notes']) ? $item['notes'] . "\n\n" . $returnNote : $returnNote;
                $stmt->execute([$condition_returned, $updatedNotes, $item_id]);
                if ($stmt->rowCount() > 0) $returnedIds[] = $item_id;
            }

            if (!empty($returnedIds)) {
                foreach ($returnedIds as $item_id) {
                    $details = json_encode([
                        'condition_returned' => $condition_returned,
                        'notes' => $return_notes,
                    ]);
                    write_activity($conn, 'return', 'item', $item_id, $details);
                }
                $conn->commit();
                $skipped = count($item_ids) - count($returnedIds);
                $_SESSION['success'] = count($returnedIds) . ' item(s) returned successfully!' . ($skipped > 0 ? ' ' . $skipped . ' item(s) were skipped because they are no longer checked out.' : '');
                header('Location: ' . BASE_PATH . 'items/items.php');
                exit;
            } else {
                $conn->rollBack();
                $errors[] = 'Unable to return the selected item(s). Ensure they are currently checked out.';
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
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
<?php echo csrf_field(); ?>
<div class="mb-3">
      <label class="form-label">Items to Return <span id="itemCountBadge" class="badge text-bg-primary ms-2" style="display:none;"></span></label>
      <div class="item-picker-wrap">
        <div class="input-group mb-2">
          <input type="search" id="itemSearch" class="form-control"
                 placeholder="Search by name, serial number, person, or department..." autocomplete="off">
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
            <?php if (empty($checked_out)): ?>
              <div class="list-group-item text-muted text-center py-3">
                <i class="bi bi-inbox"></i><br>
                <small>No checked out items to return.<br>All items are available or permanently assigned.</small>
              </div>
            <?php else: ?>
              <?php foreach ($checked_out as $it): ?>
                <?php
                  $label = $it['item_name'];
                  $desc = trim($it['description'] ?? '');
                  $sn = trim($it['serial_number'] ?? '');
                  if ($desc !== '') { $label .= " - " . $desc; }
                  if ($sn !== '') { $label .= " (" . $sn . ")"; }
                  if (!empty($it['category'])) { $label .= " [" . $it['category'] . "]"; }
                  $label .= " - #" . (int)$it['id'];
                  if ($it['taken_by']) { $label .= " - with: " . $it['taken_by']; }
                  $selected = ($preselected_item && $it['id'] == $preselected_item['id']) ? '1' : '0';
                  $searchBlob = strtolower($it['item_name'] . ' ' . ($it['description'] ?? '') . ' ' . ($it['serial_number'] ?? '') . ' ' . ($it['category'] ?? '') . ' ' . ($it['taken_by'] ?? '') . ' ' . ($it['department'] ?? '') . ' ' . $it['id']);
                ?>
                <button type="button" class="list-group-item list-group-item-action item-option d-flex justify-content-between align-items-center"
                        data-id="<?php echo (int)$it['id']; ?>"
                        data-selected="<?php echo $selected; ?>"
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
    <?php if ($preselected_item): ?>
    <div class="form-text text-primary mt-2">
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
  const doneBtn = document.getElementById('doneBtn');
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

  doneBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    close();
    input.blur();
    // Focus on the next form field (Condition)
    const conditionField = form.querySelector('select[name="condition_returned"]');
    if (conditionField) {
      setTimeout(() => conditionField.focus(), 100);
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
