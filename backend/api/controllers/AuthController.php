<?php
namespace App\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController extends BaseController {
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';

    public function __construct($db) {
        parent::__construct($db);
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'your-secret-key';
    }

    public function login() {
        $data = $this->getRequestData();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            return $this->jsonResponse(['error' => 'Username and password are required'], 400);
        }

        $stmt = $this->db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $token = $this->generateJWT($user);
            return $this->jsonResponse([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'is_admin' => (bool)$user['is_admin']
                ]
            ]);
        }

        return $this->jsonResponse(['error' => 'Invalid credentials'], 401);
    }

    public function register() {
        $data = $this->getRequestData();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $email = $data['email'] ?? '';

        if (empty($username) || empty($password) || empty($email)) {
            return $this->jsonResponse(['error' => 'All fields are required'], 400);
        }

        // Check if user exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return $this->jsonResponse(['error' => 'Username or email already exists'], 400);
        }

        // Create user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $username, $hashedPassword, $email);
        
        if ($stmt->execute()) {
            $userId = $this->db->insert_id;
            $user = [
                'id' => $userId,
                'username' => $username,
                'is_admin' => false
            ];
            $token = $this->generateJWT($user);
            
            return $this->jsonResponse([
                'token' => $token,
                'user' => $user
            ], 201);
        }

        return $this->jsonResponse(['error' => 'Registration failed'], 500);
    }

    public function me() {
        $user = $this->authenticateRequest();
        if (!$user) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }
        return $this->jsonResponse(['user' => $user]);
    }

    private function generateJWT($user) {
        $issuedAt = time();
        $expire = $issuedAt + (60 * 60 * 24); // 24 hours

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => [
                'id' => $user['id'],
                'username' => $user['username']
            ]
        ];

        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);
    }

    public function authenticateRequest() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
                return (array)$decoded->data;
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }
}
