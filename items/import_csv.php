<?php
require_once __DIR__ . '/../config.php';
require_login();
require_admin();

$success = '';
$error = '';
$imported = 0;
$skipped = 0;
$duplicates_found = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $skip_duplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1';
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($file['tmp_name'], 'r');
        
        if ($handle !== false) {
            // Read header row
            $headers = fgetcsv($handle);
            
            if ($headers === false) {
                $error = 'CSV file is empty or invalid.';
            } else {
                // Normalize headers (trim and lowercase)
                $headers = array_map(function($h) {
                    return strtolower(trim($h));
                }, $headers);
                
                // Expected columns (flexible mapping)
                $columnMap = [
                    'item_name' => ['item_name', 'item name', 'item', 'name', 'product'],
                    'serial_number' => ['serial_number', 'serial number', 'serial', 'sn', 'serial_no'],
                    'category' => ['category', 'type', 'item_type'],
                    'description' => ['description', 'desc', 'details'],
                    'department' => ['department', 'dept', 'division'],
                    'status' => ['status', 'state'],
                    'taken_by' => ['taken_by', 'taken by', 'borrowed_by', 'user'],
                    'date_taken' => ['date_taken', 'date taken', 'checkout_date', 'taken_date'],
                    'expected_return_date' => ['expected_return_date', 'expected return', 'expected_return', 'return_date'],
                    'date_returned' => ['date_returned', 'date returned', 'returned_date'],
                    'condition_returned' => ['condition_returned', 'condition returned', 'condition', 'return_condition'],
                    'notes' => ['notes', 'remarks', 'comments']
                ];
                
                // Map CSV columns to database columns
                $mapping = [];
                foreach ($columnMap as $dbCol => $possibleNames) {
                    foreach ($possibleNames as $name) {
                        $index = array_search($name, $headers);
                        if ($index !== false) {
                            $mapping[$dbCol] = $index;
                            break;
                        }
                    }
                }
                
                // Check if at least item_name is present
                if (!isset($mapping['item_name'])) {
                    $error = 'CSV must contain an "item_name" column (or similar: item, name, product).';
                } else {
                    // Process rows
                    $rowNum = 1;
                    while (($row = fgetcsv($handle)) !== false) {
                        $rowNum++;
                        
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            $skipped++;
                            continue;
                        }
                        
                        // Extract data based on mapping
                        $itemName = isset($mapping['item_name']) ? trim($row[$mapping['item_name']]) : '';
                        
                        if (empty($itemName)) {
                            $errors[] = "Row $rowNum: Missing item_name";
                            $skipped++;
                            continue;
                        }
                        
                        $serialNumber = isset($mapping['serial_number']) ? trim($row[$mapping['serial_number']]) : null;
                        $category = isset($mapping['category']) ? trim($row[$mapping['category']]) : null;
                        $description = isset($mapping['description']) ? trim($row[$mapping['description']]) : null;
                        $department = isset($mapping['department']) ? trim($row[$mapping['department']]) : null;
                        $status = isset($mapping['status']) ? strtolower(trim($row[$mapping['status']])) : 'available';
                        $takenBy = isset($mapping['taken_by']) ? trim($row[$mapping['taken_by']]) : null;
                        $dateTaken = isset($mapping['date_taken']) ? trim($row[$mapping['date_taken']]) : null;
                        $expectedReturnDate = isset($mapping['expected_return_date']) ? trim($row[$mapping['expected_return_date']]) : null;
                        $dateReturned = isset($mapping['date_returned']) ? trim($row[$mapping['date_returned']]) : null;
                        $conditionReturned = isset($mapping['condition_returned']) ? trim($row[$mapping['condition_returned']]) : null;
                        $notes = isset($mapping['notes']) ? trim($row[$mapping['notes']]) : null;
                        
                        // Validate status
                        if (!in_array($status, ['available', 'checked_out'])) {
                            $status = 'available';
                        }
                        
                        // Clean up empty strings to NULL for dates
                        if ($dateTaken === '') $dateTaken = null;
                        if ($expectedReturnDate === '') $expectedReturnDate = null;
                        if ($dateReturned === '') $dateReturned = null;
                        if ($conditionReturned === '') $conditionReturned = null;
                        if ($notes === '') $notes = null;
                        if ($takenBy === '') $takenBy = null;
                        if ($serialNumber === '') $serialNumber = null;
                        if ($category === '') $category = null;
                        if ($description === '') $description = null;
                        if ($department === '') $department = null;
                        
                        // Check for duplicates based on item_name and serial_number
                        // Exclude items with N/A or empty serial numbers
                        $isDuplicate = false;
                        if ($skip_duplicates) {
                            try {
                                $dupCheck = $conn->prepare("
                                    SELECT id FROM items 
                                    WHERE item_name = ? 
                                    AND serial_number IS NOT NULL
                                    AND serial_number != ''
                                    AND UPPER(serial_number) != 'N/A'
                                    AND serial_number = ?
                                    LIMIT 1
                                ");
                                $dupCheck->execute([$itemName, $serialNumber]);
                                if ($dupCheck->fetch()) {
                                    $isDuplicate = true;
                                    $duplicates_found++;
                                    $skipped++;
                                    continue; // Skip this row
                                }
                            } catch (Exception $e) {
                                // If check fails, continue with import
                            }
                        }
                        
                        // Determine returned flag based on status and date_returned (must be boolean, not string)
                        $returned = ($dateReturned !== null && $dateReturned !== '');
                        
                        // Insert into database
                        try {
                            if ($conn instanceof PDO) {
                                // PostgreSQL (PDO) - use boolean true/false
                                $stmt = $conn->prepare("
                                    INSERT INTO items (
                                        item_name, serial_number, category, description, 
                                        department, status, returned, taken_by, 
                                        date_taken, expected_return_date, date_returned, 
                                        condition_returned, notes, created_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt->execute([
                                    $itemName, $serialNumber, $category, $description,
                                    $department, $status, $returned ? 'true' : 'false', $takenBy,
                                    $dateTaken, $expectedReturnDate, $dateReturned,
                                    $conditionReturned, $notes
                                ]);
                            } else {
                                // MySQL (mysqli)
                                $stmt = $conn->prepare("
                                    INSERT INTO items (
                                        item_name, serial_number, category, description, 
                                        department, status, returned, taken_by, 
                                        date_taken, expected_return_date, date_returned, 
                                        condition_returned, notes, created_at
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $returnedInt = $returned ? 1 : 0;
                                $stmt->bind_param('ssssssissssss', 
                                    $itemName, $serialNumber, $category, $description,
                                    $department, $status, $returnedInt, $takenBy,
                                    $dateTaken, $expectedReturnDate, $dateReturned,
                                    $conditionReturned, $notes
                                );
                                $stmt->execute();
                            }
                            
                            $imported++;
                            
                            // Log activity
                            write_activity($conn, 'csv_import', 'item', 0, "Imported: $itemName");
                            
                        } catch (Exception $e) {
                            $errors[] = "Row $rowNum: " . $e->getMessage();
                            $skipped++;
                        }
                    }
                    
                    $success = "Import completed! Imported: $imported, Skipped: $skipped" . 
                               ($duplicates_found > 0 ? ", Duplicates skipped: $duplicates_found" : "");
                }
            }
            
            fclose($handle);
        } else {
            $error = 'Failed to open CSV file.';
        }
    } else {
        $error = 'File upload error: ' . $file['error'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container mt-4">
    <h2>Import Items from CSV</h2>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-warning">
            <strong>Errors encountered:</strong>
            <ul>
                <?php foreach (array_slice($errors, 0, 10) as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
                <?php if (count($errors) > 10): ?>
                    <li><em>... and <?= count($errors) - 10 ?> more errors</em></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Upload CSV File</h5>
            
            <div class="alert alert-info">
                <strong>CSV Format Requirements:</strong>
                <ul>
                    <li>First row must contain column headers</li>
                    <li>Required column: <code>item_name</code> (or: item name, item, name, product)</li>
                    <li>Optional columns: <code>serial_number</code>, <code>category</code>, <code>description</code>, <code>department</code>, <code>status</code>, <code>taken_by</code>, <code>date_taken</code>, <code>expected_return_date</code>, <code>date_returned</code>, <code>condition_returned</code>, <code>notes</code></li>
                    <li>Status values: <code>available</code> or <code>checked_out</code> (defaults to available)</li>
                    <li>Date format: YYYY-MM-DD HH:MM:SS or any format recognized by PostgreSQL</li>
                </ul>
                
                <strong>Example CSV:</strong>
                <pre>item_name,serial_number,category,description,department,status,taken_by,date_taken
Laptop Dell XPS,SN-12345,Laptop,15-inch display,IT,checked_out,John Doe,2026-02-01 09:00:00
Monitor Samsung,SN-67890,Display,24-inch LED,Finance,available,,,</pre>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="csv_file" class="form-label">Select CSV File</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="skip_duplicates" name="skip_duplicates" value="1" checked>
                    <label class="form-check-label" for="skip_duplicates">
                        Skip duplicate items (based on item name and serial number, excludes N/A serial numbers)
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary">Import CSV</button>
                <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    
    <div class="mt-4">
        <a href="<?php echo BASE_PATH; ?>items/items.php" class="btn btn-outline-secondary">← Back to Items</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
