<?php
require_once __DIR__ . '/config.php';
require_login();
require_admin();

// This script will create all missing tables in the main database
echo "<h2>Database Setup</h2>";

global $conn;
if (!$conn) {
    die('<p style="color: red;">❌ Database connection failed!</p>');
}

echo "<p>✅ Connected to database successfully!</p>";

try {
    // Show current database info
    $stmt = $conn->query("SELECT current_database(), current_user, inet_server_addr(), inet_server_port()");
    $dbInfo = $stmt->fetch();
    echo "<p><strong>Database Info:</strong></p>";
    echo "<ul>";
    echo "<li>Database: " . htmlspecialchars($dbInfo['current_database']) . "</li>";
    echo "<li>User: " . htmlspecialchars($dbInfo['current_user']) . "</li>";
    echo "<li>Host: " . htmlspecialchars($dbInfo['inet_server_addr'] ?? 'localhost') . "</li>";
    echo "<li>Port: " . htmlspecialchars($dbInfo['inet_server_port'] ?? 'default') . "</li>";
    echo "</ul>";
    
    // Check existing tables
    $stmt = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>Existing Tables:</strong></p>";
    echo "<ul>";
    foreach ($existingTables as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }
    echo "</ul>";
    
    // Create handover tables
    echo "<h3>Creating Handover Tables...</h3>";
    
    $sql = "
        -- Main handovers table
        CREATE TABLE IF NOT EXISTS handovers (
            id SERIAL PRIMARY KEY,
            date_issued DATE NOT NULL,
            employee_name VARCHAR(255) NOT NULL,
            ftm_pin VARCHAR(50) NOT NULL,
            department VARCHAR(100) NOT NULL,
            issued_by VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- Devices table for multiple devices per handover
        CREATE TABLE IF NOT EXISTS handover_devices (
            id SERIAL PRIMARY KEY,
            handover_id INTEGER NOT NULL REFERENCES handovers(id) ON DELETE CASCADE,
            device_name VARCHAR(255) NOT NULL,
            serial_number VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- Applications table for equipment requests
        CREATE TABLE IF NOT EXISTS applications (
            id SERIAL PRIMARY KEY,
            applied_date DATE,
            application_ref_no VARCHAR(100),
            job_card_no VARCHAR(100),
            ftm_pin VARCHAR(50),
            applicant_name VARCHAR(255),
            dept VARCHAR(100),
            item_name VARCHAR(255),
            quantity INTEGER DEFAULT 1,
            purpose TEXT,
            urgency VARCHAR(50) DEFAULT 'normal',
            status_tracking VARCHAR(100) DEFAULT 'pending',
            allocation_date DATE,
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- Create indexes for better performance
        CREATE INDEX IF NOT EXISTS idx_handovers_date ON handovers(date_issued);
        CREATE INDEX IF NOT EXISTS idx_handovers_employee ON handovers(employee_name);
        CREATE INDEX IF NOT EXISTS idx_handovers_ftm_pin ON handovers(ftm_pin);
        CREATE INDEX IF NOT EXISTS idx_handovers_department ON handovers(department);
        CREATE INDEX IF NOT EXISTS idx_handover_devices_handover_id ON handover_devices(handover_id);
        CREATE INDEX IF NOT EXISTS idx_handover_devices_serial ON handover_devices(serial_number);
        CREATE INDEX IF NOT EXISTS idx_applications_status ON applications(status_tracking);
        CREATE INDEX IF NOT EXISTS idx_applications_date ON applications(applied_date);
        CREATE INDEX IF NOT EXISTS idx_applications_ref ON applications(application_ref_no);
    ";
    
    $conn->exec($sql);
    
    echo "<p style='color: green;'>✅ Tables created successfully!</p>";
    
    // Add missing columns to existing tables
    echo "<h3>Updating Table Schema...</h3>";
    
    try {
        // Check if urgency column exists in applications table
        $stmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'applications' AND column_name = 'urgency'");
        $urgencyExists = $stmt->fetchColumn();
        
        if (!$urgencyExists) {
            $conn->exec("ALTER TABLE applications ADD COLUMN urgency VARCHAR(50) DEFAULT 'normal'");
            echo "<p style='color: green;'>✅ Added urgency column to applications table</p>";
        } else {
            echo "<p style='color: blue;'>ℹ️ Urgency column already exists in applications table</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Schema update note: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Verify tables were created
    $stmt = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('handovers', 'handover_devices', 'applications') ORDER BY table_name");
    $newTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>New Tables Created:</strong></p>";
    echo "<ul>";
    foreach ($newTables as $table) {
        echo "<li style='color: green;'>" . htmlspecialchars($table) . "</li>";
    }
    echo "</ul>";
    
    // Test the handover tables
    echo "<h3>Testing Tables...</h3>";
    
    $conn->beginTransaction();
    
    // Test handover insert
    $stmt = $conn->prepare("INSERT INTO handovers (date_issued, employee_name, ftm_pin, department, issued_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([date('Y-m-d'), 'TEST USER', 'TEST123', 'IT', 'ADMIN']);
    $handoverId = $conn->lastInsertId();
    
    // Test device insert
    $stmt = $conn->prepare("INSERT INTO handover_devices (handover_id, device_name, serial_number) VALUES (?, ?, ?)");
    $stmt->execute([$handoverId, 'TEST DEVICE', 'TEST-001']);
    
    // Test query
    $stmt = $conn->prepare("SELECT h.*, hd.device_name, hd.serial_number FROM handovers h LEFT JOIN handover_devices hd ON h.id = hd.handover_id WHERE h.id = ?");
    $stmt->execute([$handoverId]);
    $testResult = $stmt->fetch();
    
    if ($testResult) {
        echo "<p style='color: green;'>✅ Tables are working correctly!</p>";
        echo "<p>Test record: " . htmlspecialchars($testResult['employee_name']) . " - " . htmlspecialchars($testResult['device_name']) . "</p>";
    }
    
    // Clean up test data
    $conn->exec("DELETE FROM handover_devices WHERE handover_id = $handoverId");
    $conn->exec("DELETE FROM handovers WHERE id = $handoverId");
    $conn->commit();
    
    echo "<p style='color: green;'>✅ Test data cleaned up successfully!</p>";
    
    echo "<h3>🎉 Database Setup Complete!</h3>";
    echo "<p><a href='handovers/handovers.php'>Go to Equipment Handovers</a></p>";
    echo "<p><a href='index.php'>Go to Dashboard</a></p>";
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>