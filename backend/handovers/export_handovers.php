<?php
require_once __DIR__ . '/../config.php';
require_login();

// Use main database connection
global $conn;
if (!$conn) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: ' . BASE_PATH . 'handovers/handovers.php');
    exit;
}

// Load DomPDF if needed
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

$format = $_GET['format'] ?? 'csv';
$issuer = trim($_GET['issuer'] ?? '');

// Build query with optional issuer filter
$whereClause = '';
$params = [];
if (!empty($issuer)) {
    $whereClause = 'WHERE h.issued_by = ?';
    $params = [$issuer];
}

$sql = "SELECT h.*, hd.device_name, hd.serial_number
        FROM handovers h 
        LEFT JOIN handover_devices hd ON h.id = hd.handover_id
        $whereClause
        ORDER BY h.date_issued DESC, h.created_at DESC, hd.id";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

if ($format === 'csv') {
    $filename = 'equipment_handovers_' . date('Y-m-d');
    if (!empty($issuer)) {
        $filename .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($issuer));
    }
    $filename .= '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers (all caps as requested)
    fputcsv($output, ['DATE', 'EMPLOYEE NAME', 'FTM PIN', 'DEPARTMENT', 'DEVICE NAME', 'SERIAL NUMBER', 'ISSUED BY']);
    
    // Data rows - one row per device
    foreach ($results as $row) {
        fputcsv($output, [
            date('Y-m-d', strtotime($row['date_issued'])),
            $row['employee_name'],
            $row['ftm_pin'],
            $row['department'],
            $row['device_name'] ?? '',
            $row['serial_number'] ?? '',
            $row['issued_by']
        ]);
    }
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    // Check if vendor autoload exists
    if (!file_exists($autoloadPath)) {
        $_SESSION['error'] = 'PDF export requires Composer dependencies. Please run: composer install';
        header('Location: ' . BASE_PATH . 'handovers/handovers.php');
        exit;
    }
    
    try {
        // Use DomPDF
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new \Dompdf\Dompdf($options);
        
        $filename = 'equipment_handovers_' . date('Y-m-d');
        if (!empty($issuer)) {
            $filename .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($issuer));
        }
        $filename .= '.pdf';
        
        // PDF Header
        $title = 'EQUIPMENT HANDOVERS REPORT';
        if (!empty($issuer)) {
            $title .= ' - ' . strtoupper($issuer);
        }
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { 
                    size: A4 landscape; 
                    margin: 15mm; 
                }
                body { 
                    font-family: Arial, sans-serif; 
                    font-size: 8px; 
                    margin: 0; 
                    padding: 0;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 15px; 
                    border-bottom: 2px solid #059669;
                    padding-bottom: 10px;
                }
                .header h1 { 
                    color: #059669; 
                    font-size: 14px; 
                    margin: 0 0 5px 0; 
                    font-weight: bold;
                }
                .header p { 
                    margin: 2px 0; 
                    color: #666; 
                    font-size: 9px;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    margin-top: 5px; 
                }
                th { 
                    background-color: #059669; 
                    color: white; 
                    padding: 6px 3px; 
                    text-align: center; 
                    font-weight: bold; 
                    border: 1px solid #333; 
                    font-size: 7px;
                    line-height: 1.2;
                }
                td { 
                    padding: 4px 3px; 
                    border: 1px solid #666; 
                    text-align: center; 
                    font-size: 7px;
                    line-height: 1.1;
                    vertical-align: middle;
                }
                tr:nth-child(even) { 
                    background-color: #f9f9f9; 
                }
                .signature-col { 
                    width: 60px; 
                    height: 25px;
                    border: 1px solid #333;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 15px; 
                    font-size: 7px; 
                    color: #666; 
                    border-top: 1px solid #ccc;
                    padding-top: 8px;
                }
                .date-col { width: 60px; }
                .name-col { width: 80px; }
                .pin-col { width: 50px; }
                .dept-col { width: 70px; }
                .device-col { width: 80px; }
                .serial-col { width: 70px; }
                .issued-col { width: 80px; }
            </style>
        </head>
        <body>
        
        <div class="header">
            <h1>' . htmlspecialchars($title) . '</h1>
            <p>Generated on: ' . date('F j, Y \a\t g:i A') . ' | Total Records: ' . count($results) . '</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th class="date-col">DATE</th>
                    <th class="name-col">EMPLOYEE<br>NAME</th>
                    <th class="pin-col">FTM<br>PIN</th>
                    <th class="dept-col">DEPARTMENT</th>
                    <th class="device-col">DEVICE<br>NAME</th>
                    <th class="serial-col">SERIAL<br>NUMBER</th>
                    <th class="signature-col">EMPLOYEE<br>SIGNATURE</th>
                    <th class="signature-col">MANAGER<br>SIGNATURE</th>
                    <th class="issued-col">ISSUED BY</th>
                </tr>
            </thead>
            <tbody>';
        
        // Data rows
        foreach ($results as $row) {
            $html .= '<tr>
                <td class="date-col">' . htmlspecialchars(date('Y-m-d', strtotime($row['date_issued']))) . '</td>
                <td class="name-col">' . htmlspecialchars($row['employee_name']) . '</td>
                <td class="pin-col">' . htmlspecialchars($row['ftm_pin']) . '</td>
                <td class="dept-col">' . htmlspecialchars($row['department']) . '</td>
                <td class="device-col">' . htmlspecialchars($row['device_name'] ?? '') . '</td>
                <td class="serial-col">' . htmlspecialchars($row['serial_number'] ?? '') . '</td>
                <td class="signature-col">&nbsp;</td>
                <td class="signature-col">&nbsp;</td>
                <td class="issued-col">' . htmlspecialchars($row['issued_by']) . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
        </table>
        
        <div class="footer">
            <p><strong>FTM IT PROPERTY RECORDS</strong> - Equipment Handover Report</p>
            <p>This document serves as official record of equipment handovers and requires appropriate signatures for validation.</p>
        </div>
        
        </body>
        </html>';
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        
        // Output the PDF
        $dompdf->stream($filename, array('Attachment' => true));
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'PDF generation failed: ' . $e->getMessage();
        header('Location: ' . BASE_PATH . 'handovers/handovers.php');
        exit;
    }
}

// If not CSV or PDF, redirect back
header('Location: ' . BASE_PATH . 'handovers/handovers.php');
exit;
?>