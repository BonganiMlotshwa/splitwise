<?php
namespace App\Controllers;

use App\Traits\ApiResponse;

class BaseController {
    use ApiResponse;

    protected $db;
    protected $requestData;
    protected $queryParams;

    public function __construct($db) {
        $this->db = $db;
        $this->requestData = $this->parseRequestData();
        $this->queryParams = $_GET;
    }

    /**
     * Authenticate the incoming request
     * 
     * @return array|false User data if authenticated, false otherwise
     */
    protected function authenticateRequest() {
        if (empty($_SESSION['user_id'])) {
            $this->sendResponse($this->errorResponse('Authentication required', 401));
            return false;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? 'Unknown',
            'role' => $_SESSION['user_role'] ?? 'user'
        ];
    }

    /**
     * Require admin privileges for the current request
     * 
     * @return void
     */
    protected function requireAdmin() {
        $user = $this->authenticateRequest();
        if (!$user || ($user['role'] !== 'admin')) {
            $this->sendResponse($this->errorResponse('Insufficient permissions', 403));
        }
    }

    /**
     * Parse and validate request data
     * 
     * @return array Parsed request data
     */
    protected function parseRequestData(): array {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->sendResponse($this->errorResponse('Invalid JSON data', 400));
            }
            return $data ?? [];
        }
        
        return $_POST;
    }

    /**
     * Get a query parameter with type validation
     * 
     * @param string $key Parameter name
     * @param string $type Expected type (int, string, bool, array)
     * @param mixed $default Default value if parameter is not set
     * @return mixed
     */
    protected function getQueryParam(string $key, string $type = 'string', $default = null) {
        $value = $this->queryParams[$key] ?? $default;
        
        if ($value === null) {
            return $default;
        }
        
        switch (strtolower($type)) {
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'array':
                return is_array($value) ? $value : [$value];
            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Validate required fields in the request data
     * 
     * @param array $fields List of required field names
     * @return array|false Array of validated data or false if validation fails
     */
    protected function validateRequiredFields(array $fields) {
        $missing = [];
        $data = [];
        
        foreach ($fields as $field) {
            if (!isset($this->requestData[$field]) || $this->requestData[$field] === '') {
                $missing[] = $field;
            } else {
                $data[$field] = $this->requestData[$field];
            }
        }
        
        if (!empty($missing)) {
            $this->sendResponse($this->errorResponse(
                'Missing required fields: ' . implode(', ', $missing),
                400,
                ['missing_fields' => $missing]
            ));
            return false;
        }
        
        return $data;
    }
}
