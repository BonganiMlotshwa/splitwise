<?php
require_once __DIR__ . '/../config.php';
require_login();

$errors = [];
$needConfirm = false;
$duplicates = [];

// Load categories (prefer categories table; fallback to distinct from items)
$categories = [];
try {
    $res = $conn->query("SELECT name FROM categories ORDER BY name");
    if ($res) {
        $categories = array_column($res->fetchAll(), 'name');
    }
} catch (Throwable $e) { /* ignore */ }
if (empty($categories)) {
    $res = $conn->query("SELECT DISTINCT category AS name FROM items WHERE category IS NOT NULL AND category<>'' ORDER BY category");
    if ($res) {
        $categories = array_column($res->fetchAll(), 'name');
    }
}
// Remove 'Tool' from dropdown options (case-insensitive)
$categories = array_values(array_filter($categories, fn($n) => strtolower(trim((string)$n)) !== 'tool'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($item_name === '') $errors[] = 'Item name is required.';

    // No special normalization/validation for serial number; accept as entered

    if (empty($errors)) {
        // Duplicate detection: exact match on all main fields (after trimming). Empty strings are treated as empty.
        $sqlDup = "SELECT id, item_name, serial_number, category, description FROM items
                   WHERE item_name = ?
                     AND COALESCE(serial_number,'') = ?
                     AND COALESCE(category,'') = ?
                     AND COALESCE(description,'') = ?
                   ORDER BY id DESC
                   LIMIT 5";
        $dupStmt = $conn->prepare($sqlDup);
        $dupStmt->execute([$item_name, $serial_number, $category, $description]);
        $duplicates = $dupStmt->fetchAll();

        if (!empty($duplicates) && empty($_POST['confirm_duplicate'])) {
            // Ask for confirmation instead of inserting immediately
            $needConfirm = true;
        }
    }

    if (empty($errors) && !$needConfirm) {
        try {
            $sql = "INSERT INTO items (item_name, serial_number, category, description, status, returned, created_at) VALUES (?,?,?,?,?,false,NOW())";
            $stmt = $conn->prepare($sql);
            $status = 'available';
            $stmt->execute([$item_name, $serial_number, $category, $description, $status]);
            $new_id = $conn->lastInsertId();
            // Log activity
            $details = json_encode([
                'serial_number' => $serial_number,
                'category' => $category,
                'description' => $description,
            ]);
            write_activity($conn, 'add_item', 'item', $new_id, $details);
            $_SESSION['success'] = 'Item added successfully!';
            header('Location: ' . BASE_PATH . 'items/items.php');
            exit;
        } catch (Exception $e) {
            $errors[] = "Failed to add item: {$e->getMessage()}";
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<h1 class="h4 mb-3">Add Item</h1>
<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){ echo '<li>'.htmlspecialchars($e).'</li>'; } ?></ul></div>
<?php endif; ?>
<?php if ($needConfirm): ?>
  <div class="alert alert-warning">
    <div class="fw-bold mb-1">Possible duplicate(s) found. Do you want to add this item again?</div>
    <div class="small text-muted mb-2">The following existing item(s) match all entered fields:</div>
    <ul class="mb-0">
      <?php foreach ($duplicates as $d): ?>
        <li>
          #<?php echo (int)$d['id']; ?> — <?php echo htmlspecialchars($d['item_name']); ?>
          <?php if (($d['serial_number'] ?? '') !== ''): ?>, Serial: <?php echo htmlspecialchars($d['serial_number']); ?><?php endif; ?>
          <?php if (($d['category'] ?? '') !== ''): ?>, Category: <?php echo htmlspecialchars($d['category']); ?><?php endif; ?>
          <?php if (($d['description'] ?? '') !== ''): ?>, Desc: <?php echo htmlspecialchars($d['description']); ?><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<form method="post" class="card p-3">
  <div class="mb-3 position-relative" id="itemNameGroup">
    <label class="form-label">Item Name *</label>
    <input type="text" name="item_name" id="item_name" class="form-control" autocomplete="off" required value="<?php echo htmlspecialchars($_POST['item_name'] ?? ''); ?>">
    <div id="item_suggestions" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000; display: none; max-height: 260px; overflow:auto;"></div>
  </div>
  <div class="mb-3">
    <label class="form-label">Serial Number</label>
    <input type="text" name="serial_number" id="serial_number" class="form-control" placeholder="Enter any serial (optional)" value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>">
    <div class="form-text">Optional. You can enter any format, e.g., FTM-123, SN-001, 12345, etc.</div>
  </div>
  <div class="mb-3">
    <label class="form-label">Category</label>
    <?php
      $cat_val = trim($_POST['category'] ?? '');
      $is_other = ($cat_val !== '' && !in_array($cat_val, $categories, true));
    ?>
    <select id="category_select" class="form-select" <?php echo $is_other ? '' : 'name="category"'; ?>>
      <option value="">-- Select Category --</option>
      <?php foreach ($categories as $c): $sel = ($cat_val === $c) ? 'selected' : ''; ?>
        <option value="<?php echo htmlspecialchars($c, ENT_QUOTES); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($c); ?></option>
      <?php endforeach; ?>
      <option value="__OTHER__" <?php echo $is_other ? 'selected' : ''; ?>>Other...</option>
    </select>
    <input type="text" id="category_input" class="form-control mt-2" placeholder="Enter category" <?php echo $is_other ? 'name="category"' : ''; ?> value="<?php echo htmlspecialchars($is_other ? $cat_val : ''); ?>" style="<?php echo $is_other ? '' : 'display:none;'; ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Description</label>
    <input type="text" name="description" id="description" class="form-control" placeholder="e.g., 5m, 3m, 1m; or color/model" value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>">
    <div class="form-text">Short descriptor such as size/length/spec that differentiates similar items.</div>
  </div>
  <?php if ($needConfirm): ?>
    <input type="hidden" name="confirm_duplicate" value="1">
  <?php endif; ?>
  <div class="d-flex justify-content-end gap-2">
    <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary"><?php echo $needConfirm ? 'Add Anyway' : 'Add Item'; ?></button>
  </div>
</form>
<script>
(function(){
  const BASE_PATH = <?php echo json_encode(BASE_PATH); ?>;
  const els = {
    name: document.getElementById('item_name'),
    serial: document.getElementById('serial_number'),
    catSelect: document.getElementById('category_select'),
    catInput: document.getElementById('category_input'),
    desc: document.getElementById('description'),
    box: document.getElementById('item_suggestions'),
    group: document.getElementById('itemNameGroup')
  };

  function debounce(fn, ms){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(this,args), ms); }; }
  function escapeHtml(s){ return s.replace(/[&<>"]/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c])); }

  async function fetchSuggestions(q){
    if (!q || q.length < 2) { hideBox(); return; }
    try {
      const url = BASE_PATH + 'api/items.php?q=' + encodeURIComponent(q) + '&per_page=8';
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      renderBox((data && data.items) ? data.items : []);
    } catch(e) { hideBox(); }
  }

  function renderBox(items){
    if (!items.length) { hideBox(); return; }
    els.box.innerHTML = items.map(it => {
      const name = escapeHtml(it.item_name || '');
      const sn = escapeHtml(it.serial_number || '');
      const cat = escapeHtml(it.category || '');
      const desc = escapeHtml(it.description || '');
      const meta = [sn?('SN: '+sn):'', cat?('Cat: '+cat):'', desc?('Desc: '+desc):''].filter(Boolean).join(' • ');
      return '<button type="button" class="list-group-item list-group-item-action" data-payload="'+
        encodeURIComponent(JSON.stringify({item_name:it.item_name||'', serial_number:it.serial_number||'', category:it.category||'', description:it.description||''}))+'">'
        + name + (meta?'<div class="small text-muted">'+meta+'</div>':'') + '</button>';
    }).join('');
    els.box.style.display = 'block';
  }

  function hideBox(){ els.box.style.display = 'none'; els.box.innerHTML=''; }

  const onType = debounce(()=> fetchSuggestions(els.name.value.trim()), 200);
  els.name.addEventListener('input', onType);
  els.name.addEventListener('focus', ()=> fetchSuggestions(els.name.value.trim()));
  document.addEventListener('click', (e)=>{
    if (!els.group.contains(e.target)) { hideBox(); }
  });
  els.box.addEventListener('click', (e)=>{
    const btn = e.target.closest('button[list-group-item]') || e.target.closest('.list-group-item');
    if (!btn) return;
    try {
      const payload = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
      els.name.value = payload.item_name || '';
      els.serial.value = payload.serial_number || '';
      // set category via select/input logic
      const cat = payload.category || '';
      if (cat && [...els.catSelect.options].some(o=>o.value===cat)) {
        // use select value
        els.catInput.style.display = 'none';
        els.catInput.name = '';
        els.catSelect.name = 'category';
        els.catSelect.value = cat;
      } else if (cat) {
        // show other input
        els.catSelect.value = '__OTHER__';
        els.catSelect.name = '';
        els.catInput.style.display = '';
        els.catInput.name = 'category';
        els.catInput.value = cat;
      }
      els.desc.value = payload.description || '';
      hideBox();
      els.serial.focus();
    } catch(_){}
  });

  // Category select logic
  if (els.catSelect) {
    els.catSelect.addEventListener('change', ()=>{
      if (els.catSelect.value === '__OTHER__') {
        els.catSelect.name = '';
        els.catInput.style.display = '';
        els.catInput.name = 'category';
        els.catInput.focus();
      } else {
        els.catSelect.name = 'category';
        els.catInput.name = '';
        els.catInput.style.display = 'none';
        els.catInput.value = '';
      }
    });
  }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
