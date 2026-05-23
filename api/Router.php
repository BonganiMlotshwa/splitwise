<?php
namespace App;

class Router {
    private $routes = [];
    protected $db;
    protected $authController;
    protected $itemController;

    public function __construct($db) {
        $this->db = $db;
        $this->initializeControllers();
    }

    protected function initializeControllers() {
        $this->authController = new \App\Controllers\AuthController($this->db);
        $this->itemController = new \App\Controllers\ItemController($this->db);
    }

    public function getAuthController() {
        return $this->authController;
    }

    public function getItemController() {
        return $this->itemController;
    }

    public function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }

    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = str_replace('/splitwise/api', '', $path); // Remove base path
        $path = rtrim($path, '/');
        
        // Handle OPTIONS for CORS preflight
        if ($method === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            exit;
        }

        // Find matching route
        $route = $this->findMatchingRoute($method, $path);
        
        if (!$route) {
            http_response_code(404);
            echo json_encode(['error' => 'Not Found']);
            return;
        }

        // Extract route parameters
        $params = $this->extractRouteParams($route['path'], $path);
        
        // Call the handler
        try {
            $response = call_user_func_array($route['handler'], $params);
            
            // If the handler didn't return a response, assume it handled the output
            if ($response !== null) {
                header('Content-Type: application/json');
                echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]);
        }
    }

    private function findMatchingRoute($method, $path) {
        foreach ($this->routes as $route) {
            // Check HTTP method
            if ($route['method'] !== $method) {
                continue;
            }

            // Convert route path to regex
            $pattern = $this->convertRouteToRegex($route['path']);
            
            // Check if path matches
            if (preg_match($pattern, $path, $matches)) {
                return $route;
            }
        }
        return null;
    }

    private function convertRouteToRegex($route) {
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $route);
        
        // Convert route parameters to named capture groups
        $pattern = preg_replace('/\{([^\/]+)\}/', '(?P<$1>[^\/]+)', $pattern);
        
        // Add start and end anchors
        return '/^' . $pattern . '$/';
    }

    private function extractRouteParams($route, $path) {
        $params = [];
        
        // Split both paths
        $routeParts = explode('/', trim($route, '/'));
        $pathParts = explode('/', trim($path, '/'));
        
        foreach ($routeParts as $i => $part) {
            if (isset($pathParts[$i]) && $part !== $pathParts[$i]) {
                // This is a parameter
                if (preg_match('/\{(.*)\}/', $part, $matches)) {
                    $params[] = $pathParts[$i];
                }
            }
        }
        
        return $params;
    }
}
