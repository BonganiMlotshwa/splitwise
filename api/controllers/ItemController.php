<?php
namespace App\Controllers;

class ItemController extends BaseController {
    public function __construct($db) {
        parent::__construct($db);
    }

    /**
     * List all items with pagination and filtering
     * 
     * @return void
     */
    public function index() {
        $user = $this->authenticateRequest();
        
        // Get and validate query parameters
        $status = $this->getQueryParam('status', 'string', 'all');
        $search = $this->getQueryParam('q', 'string', '');
        $page = max(1, $this->getQueryParam('page', 'int', 1));
        $perPage = min(50, max(1, $this->getQueryParam('per_page', 'int', 10)));
        $offset = ($page - 1) * $perPage;

        // Build query conditions
        $where = [];
        $params = [];
        $types = '';

        if (in_array($status, ['available', 'checked_out'])) {
            $where[] = "i.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if (!empty($search)) {
            $where[] = "(i.name LIKE ? OR i.description LIKE ? OR i.serial_number LIKE ?)";
            $searchTerm = "%{$search}%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            $types .= 'sss';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM items i $whereClause";
            $stmt = $this->db->prepare($countSql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
            $totalPages = max(1, ceil($total / $perPage));

            // Add pagination to query
            $params[] = $perPage;
            $params[] = $offset;
            $types .= 'ii';

            // Get paginated items
            $sql = "SELECT i.*, 
                           u.username as checked_out_by_username,
                           c.checked_out_at,
                           c.expected_return_date
                    FROM items i
                    LEFT JOIN checkouts c ON i.id = c.item_id AND c.returned_at IS NULL
                    LEFT JOIN users u ON c.user_id = u.id
                    $whereClause
                    ORDER BY i.created_at DESC
                    LIMIT ? OFFSET ?";

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Format pagination data
            $pagination = [
                'total' => (int)$total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages
            ];

            $this->sendResponse($this->paginatedResponse($items, $pagination));
            
        } catch (\Exception $e) {
            $this->sendResponse($this->errorResponse('Failed to fetch items', 500));
        }
    }

    /**
     * Get a single item by ID
     * 
     * @param int $id Item ID
     * @return void
     */
    public function show($id) {
        $user = $this->authenticateRequest();
        
        try {
            $stmt = $this->db->prepare("
                SELECT i.*, 
                       u.username as checked_out_by_username,
                       c.checked_out_at,
                       c.expected_return_date
                FROM items i
                LEFT JOIN checkouts c ON i.id = c.item_id AND c.returned_at IS NULL
                LEFT JOIN users u ON c.user_id = u.id
                WHERE i.id = ?
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();

            if (!$item) {
                $this->sendResponse($this->errorResponse('Item not found', 404));
                return;
            }

            $this->sendResponse($this->successResponse($item));
            
        } catch (\Exception $e) {
            $this->sendResponse($this->errorResponse('Failed to fetch item', 500));
        }
    }

    /**
     * Create a new item
     * 
     * @return void
     */
    public function store() {
        $user = $this->authenticateRequest();
        
        // Validate required fields
        $required = ['name', 'serial_number', 'category'];
        $data = $this->validateRequiredFields($required);
        if ($data === false) return; // Validation failed

        try {
            // Check for duplicate serial number
            $checkStmt = $this->db->prepare("SELECT id FROM items WHERE serial_number = ?");
            $checkStmt->bind_param('s', $data['serial_number']);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows > 0) {
                $this->sendResponse($this->errorResponse('Item with this serial number already exists', 409));
                return;
            }

            // Insert new item
            $stmt = $this->db->prepare("
                INSERT INTO items (
                    name, 
                    serial_number, 
                    category, 
                    description,
                    status,
                    created_at, 
                    updated_at
                ) VALUES (?, ?, ?, ?, 'available', NOW(), NOW())
            ");
            
            $stmt->bind_param(
                'ssss',
                $data['name'],
                $data['serial_number'],
                $data['category'],
                $data['description'] ?? ''
            );

            if ($stmt->execute()) {
                $itemId = $this->db->insert_id;
                $this->sendResponse(
                    $this->successResponse(
                        ['id' => $itemId],
                        'Item created successfully',
                        201
                    )
                );
            } else {
                throw new \Exception('Failed to create item');
            }
            
        } catch (\Exception $e) {
            $this->sendResponse($this->errorResponse('Failed to create item: ' . $e->getMessage(), 500));
        }
    }

    /**
     * Update an existing item
     * 
     * @param int $id Item ID
     * @return void
     */
    public function update($id) {
        $user = $this->authenticateRequest();
        
        // Check if item exists and is not checked out
        $item = $this->getItemForUpdate($id);
        if ($item === null) return;
        
        // Get and validate update data
        $data = $this->requestData;
        if (empty($data)) {
            $this->sendResponse($this->errorResponse('No data provided for update', 400));
            return;
        }

        // Only allow specific fields to be updated
        $allowedFields = ['name', 'description', 'category', 'notes'];
        $updates = [];
        $params = [];
        $types = '';

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                $types .= 's';
            }
        }

        if (empty($updates)) {
            $this->sendResponse($this->errorResponse('No valid fields to update', 400));
            return;
        }

        try {
            // Add updated_by and updated_at
            $updates[] = 'updated_by = ?';
            $updates[] = 'updated_at = NOW()';
            $params[] = $user['id'];
            $types .= 'i';
            
            // Add item ID to params
            $params[] = $id;
            $types .= 'i';

            $sql = "UPDATE items SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $this->sendResponse($this->successResponse(
                    null,
                    'Item updated successfully'
                ));
            } else {
                throw new \Exception('Failed to update item');
            }
            
        } catch (\Exception $e) {
            $this->sendResponse($this->errorResponse('Failed to update item: ' . $e->getMessage(), 500));
        }
    }

    /**
     * Delete an item
     * 
     * @param int $id Item ID
     * @return void
     */
    public function destroy($id) {
        $user = $this->authenticateRequest();
        
        // Check if item exists and is not checked out
        $item = $this->getItemForUpdate($id);
        if ($item === null) return;

        try {
            $this->db->begin_transaction();
            
            // Delete related records first (if any)
            $this->db->query("DELETE FROM checkouts WHERE item_id = $id");
            
            // Then delete the item
            $stmt = $this->db->prepare("DELETE FROM items WHERE id = ?");
            $stmt->bind_param('i', $id);
            
            if ($stmt->execute()) {
                $this->db->commit();
                $this->sendResponse($this->successResponse(
                    null,
                    'Item deleted successfully'
                ));
            } else {
                throw new \Exception('Failed to delete item');
            }
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->sendResponse($this->errorResponse('Failed to delete item: ' . $e->getMessage(), 500));
        }
    }

    /**
     * Check out an item
     * 
     * @return void
     */
    public function checkout() {
        $user = $this->authenticateRequest();
        
        // Validate required fields
        $required = ['item_id', 'expected_return_date'];
        $data = $this->validateRequiredFields($required);
        if ($data === false) return; // Validation failed

        // Validate expected_return_date format
        $returnDate = \DateTime::createFromFormat('Y-m-d', $data['expected_return_date']);
        if (!$returnDate || $returnDate->format('Y-m-d') !== $data['expected_return_date']) {
            $this->sendResponse($this->errorResponse('Invalid return date format. Use YYYY-MM-DD', 400));
            return;
        }

        // Start transaction
        $this->db->begin_transaction();

        try {
            // Check if item exists and is available with FOR UPDATE lock
            $item = $this->getItemForUpdate($data['item_id'], true);
            if ($item === null) {
                $this->db->rollback();
                return;
            }

            if ($item['status'] !== 'available') {
                throw new \Exception('Item is not available for checkout');
            }

            // Update item status
            $updateStmt = $this->db->prepare("
                UPDATE items 
                SET status = 'checked_out', 
                    updated_at = NOW(),
                    updated_by = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param('ii', $user['id'], $data['item_id']);
            $updateStmt->execute();

            // Create checkout record
            $checkoutStmt = $this->db->prepare("
                INSERT INTO checkouts (
                    item_id, 
                    user_id, 
                    checked_out_at, 
                    expected_return_date,
                    notes
                ) VALUES (?, ?, NOW(), ?, ?)
            ");
            
            $notes = $data['notes'] ?? '';
            
            $checkoutStmt->bind_param(
                'isss',
                $data['item_id'],
                $user['id'],
                $data['expected_return_date'],
                $notes
            );
            
            if (!$checkoutStmt->execute()) {
                throw new \Exception('Failed to create checkout record');
            }

            $this->db->commit();
            
            $this->sendResponse($this->successResponse(
                ['checkout_id' => $this->db->insert_id],
                'Item checked out successfully',
                201
            ));

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->sendResponse($this->errorResponse($e->getMessage(), 400));
        }
    }

    /**
     * Return a checked out item
     * 
     * @return void
     */
    public function returnItem() {
        $user = $this->authenticateRequest();
        
        // Validate required fields
        $required = ['item_id'];
        $data = $this->validateRequiredFields($required);
        if ($data === false) return; // Validation failed

        // Start transaction
        $this->db->begin_transaction();

        try {
            // Check if item exists and is checked out with FOR UPDATE lock
            $item = $this->getItemForUpdate($data['item_id'], true);
            if ($item === null) {
                $this->db->rollback();
                return;
            }

            if ($item['status'] !== 'checked_out') {
                throw new \Exception('Item is not checked out');
            }

            // Get the active checkout record
            $checkoutStmt = $this->db->prepare("
                SELECT id 
                FROM checkouts 
                WHERE item_id = ? 
                AND returned_at IS NULL 
                ORDER BY checked_out_at DESC 
                LIMIT 1 FOR UPDATE
            ");
            $checkoutStmt->bind_param('i', $data['item_id']);
            $checkoutStmt->execute();
            $checkout = $checkoutStmt->get_result()->fetch_assoc();

            if (!$checkout) {
                throw new \Exception('No active checkout record found for this item');
            }

            // Update item status
            $updateStmt = $this->db->prepare("
                UPDATE items 
                SET status = 'available', 
                    updated_at = NOW(),
                    updated_by = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param('ii', $user['id'], $data['item_id']);
            $updateStmt->execute();

            // Update checkout record
            $returnStmt = $this->db->prepare("
                UPDATE checkouts 
                SET 
                    returned_at = NOW(), 
                    returned_by = ?,
                    condition_returned = ?,
                    notes = ?
                WHERE id = ?
            ");
            
            $condition = $data['condition'] ?? 'good';
            $notes = $data['notes'] ?? '';
            
            $returnStmt->bind_param(
                'issi',
                $user['id'],
                $condition,
                $notes,
                $checkout['id']
            );
            
            if (!$returnStmt->execute()) {
                throw new \Exception('Failed to update checkout record');
            }

            $this->db->commit();
            
            $this->sendResponse($this->successResponse(
                null,
                'Item returned successfully'
            ));

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->sendResponse($this->errorResponse($e->getMessage(), 400));
        }
    }

    /**
     * Helper method to get an item with proper locking for updates
     * 
     * @param int $itemId Item ID
     * @param bool $forUpdate Whether to use FOR UPDATE lock
     * @return array|null Item data or null if not found
     */
    private function getItemForUpdate($itemId, $forUpdate = false) {
        $lockClause = $forUpdate ? 'FOR UPDATE' : '';
        $sql = "
            SELECT i.*, c.id as checkout_id, c.user_id as checked_out_by
            FROM items i
            LEFT JOIN checkouts c ON i.id = c.item_id AND c.returned_at IS NULL
            WHERE i.id = ? 
            $lockClause
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();

        if (!$item) {
            $this->sendResponse($this->errorResponse('Item not found', 404));
            return null;
        }

        return $item;
    }
}