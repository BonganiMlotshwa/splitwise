<?php
/**
 * FTM IT Property Records — central configuration
 *
 * Loaded by every PHP page. Database credentials come from environment
 * variables (set in docker-compose.yml) with local defaults below.
 *
 * Secrets live in .env (copy from .env.example). Never commit .env.
 *
 * Environment variables:
 *   DB_* — PostgreSQL connection
 *   ADMIN_PASSWORD — admin account password (synced to DB, not stored in code)
 *   ALLOW_PUBLIC_SIGNUP — false by default; blocks open self-registration
 *   OPENAI_API_KEY, CLAUDE_API_KEY — optional AI assistant keys
 *
 * On connect, lightweight schema migrations run automatically (no manual SQL
 * required for these columns):
 *   - items.ftm_pin — permanent assignment PIN on checkout items
 *   - applications.urgency — low | normal | high | urgent (default: normal)
 *
 * For full table creation (handovers, handover_devices, applications), run once:
 *   http://localhost:8000/setup_database.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Load key=value pairs from .env when not already set (e.g. local PHP without Docker).
 */
function load_env_file(string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $value = trim($value, "\"'");
        if ($name !== '' && getenv($name) === false) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

load_env_file(__DIR__ . '/.env');

// -----------------------------
// Auth & Activity Helpers
// -----------------------------
function current_user() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
    ];
}

function login_user($id, $name, $role='user') {
    $_SESSION['user_id'] = (int)$id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_role'] = $role;
    $_SESSION['last_activity'] = time();
}

function logout_user() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// Session timeout config (seconds)
define('SESSION_TIMEOUT_ADMIN', 30 * 60);  // 30 minutes
define('SESSION_TIMEOUT_USER', 45 * 60); // 45 minutes

function require_login() {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['error'] = 'Please login to continue.';
        header('Location: ' . BASE_PATH . 'auth/login.php');
        exit;
    }
    $role = $_SESSION['user_role'] ?? 'user';
    $timeout = ($role === 'admin') ? SESSION_TIMEOUT_ADMIN : SESSION_TIMEOUT_USER;
    $last = (int)($_SESSION['last_activity'] ?? 0);
    $now = time();
    
    if ($last && ($now - $last) > $timeout) {
        logout_user();
        $_SESSION['error'] = 'Session expired due to inactivity.';
        header('Location: ' . BASE_PATH . 'auth/login.php');
        exit;
    }
    
    $_SESSION['last_activity'] = $now;
}

function write_activity($conn, $action, $entity_type, $entity_id, $details=null) {
    $uid = $_SESSION['user_id'] ?? null;
    $uname = $_SESSION['user_name'] ?? null;
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $file = $logDir . '/activity-' . date('Y-m-d') . '.log';
    $entry = [
        'ts' => date('c'),
        'user_id' => $uid,
        'user_name' => $uname,
        'action' => $action,
        'entity_type' => $entity_type,
        'entity_id' => (int)$entity_id,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function is_admin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function require_admin() {
    if (!is_admin()) {
        $_SESSION['error'] = 'You do not have permission to perform this action.';
        header('Location: ' . BASE_PATH . 'index.php');
        exit;
    }
}

// Application paths and branding
define('BASE_PATH', '/');
define('SITE_NAME', 'FTM IT PROPERTY RECORDS');

// Main database (items, users, applications, handovers) — see docker-compose `postgres`
define('DB_SERVER', getenv('DB_SERVER') ?: 'postgres');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'postgres');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'ftm_it_property_records');
define('DB_PORT', getenv('DB_PORT') ?: '5432');

// Legacy secondary DB env vars (apps-postgres in compose); unused by current PHP pages
define('APP_DB_HOST', getenv('APP_DB_HOST') ?: 'apps-postgres');
define('APP_DB_PORT', getenv('APP_DB_PORT') ?: '5432');
define('APP_DB_NAME', getenv('APP_DB_NAME') ?: 'applications_db');
define('APP_DB_USER', getenv('APP_DB_USER') ?: 'postgres');
define('APP_DB_PASS', getenv('APP_DB_PASS') ?: '');

// Security: admin password from .env; public signup off unless explicitly enabled
define('ALLOW_PUBLIC_SIGNUP', filter_var(getenv('ALLOW_PUBLIC_SIGNUP') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// SMTP (email notifications; disabled by default)
define('SMTP_ENABLED', false);
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'user@example.com');
define('SMTP_PASS', '');
define('SMTP_FROM', 'no-reply@example.com');
define('SMTP_FROM_NAME', SITE_NAME);

// AI Assistant Configuration
define('AI_PROVIDER', 'ollama');
define('AI_ENABLED', true);
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: 'your-api-key-here');
define('OPENAI_MODEL', 'gpt-3.5-turbo');
define('OLLAMA_MODEL', 'tinyllama');
define('CLAUDE_API_KEY', getenv('CLAUDE_API_KEY') ?: '');
define('CLAUDE_MODEL', 'claude-3-haiku-20240307');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (DB_PASSWORD === '') {
    die('Database password not configured. Copy .env.example to .env and set DB_PASSWORD.');
}

// Create PostgreSQL connection using PDO
try {
    $dsn = "pgsql:host=" . DB_SERVER . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Alias used across the app (PDO)
    $conn = $pdo;

    // Auto-migrate missing columns on existing databases
    $migrations = [
        "items.ftm_pin" => "ALTER TABLE items ADD COLUMN ftm_pin VARCHAR(100) NULL",
        "applications.urgency" => "ALTER TABLE applications ADD COLUMN urgency VARCHAR(50) DEFAULT 'normal'",
    ];
    foreach ($migrations as $column => $sql) {
        [$table, $col] = explode('.', $column, 2);
        $exists = $pdo->query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = " . $pdo->quote($table) .
            " AND column_name = " . $pdo->quote($col)
        )->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $adminPassword = getenv('ADMIN_PASSWORD') ?: '';
    if ($adminPassword !== '') {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $markerFile = $logDir . '/.admin_password_hash';
        $marker = hash('sha256', $adminPassword);
        if (!is_file($markerFile) || trim((string)@file_get_contents($markerFile)) !== $marker) {
            $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
            $stmt->execute([$hash]);
            @file_put_contents($markerFile, $marker);
        }
    }
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

function flash($key) {
    if (!empty($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return '';
}

function send_mail($toEmail, $subject, $htmlBody, $textBody='') {
    if (!SMTP_ENABLED) { return false; }
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) { return false; }
    require_once $autoload;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        if ($mail->Port === 465) { $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; }
        else { $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
        return $mail->send();
    } catch (\Throwable $e) {
        return false;
    }
}
