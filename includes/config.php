<?php
session_start();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'fwcm_complaints';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $bootstrap = new mysqli($dbHost, $dbUser, $dbPass);
    $bootstrap->set_charset('utf8mb4');
    $bootstrap->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $bootstrap->select_db($dbName);

    $bootstrap->multi_query(<<<SQL
CREATE TABLE IF NOT EXISTS departments (
 id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS users (
 id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL, email VARCHAR(150) NOT NULL UNIQUE,
 matric_number VARCHAR(50) UNIQUE, department_id INT NULL, password_hash VARCHAR(255) NOT NULL,
 role ENUM('student','admin','department') NOT NULL DEFAULT 'student', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS tickets (
 id INT AUTO_INCREMENT PRIMARY KEY, ref_number VARCHAR(30) NOT NULL UNIQUE, student_id INT NOT NULL,
 category VARCHAR(50) NOT NULL, topic VARCHAR(100) NOT NULL, department_id INT NULL,
 subject VARCHAR(180) NOT NULL, description TEXT NOT NULL, status ENUM('Submitted','In Progress','Resolved','Closed') DEFAULT 'Submitted',
 resolution TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS ticket_updates (
 id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT NULL, message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
SQL
    );
    while ($bootstrap->more_results() && $bootstrap->next_result()) {}

    $deptNames = ['Academic Affairs','Student Affairs','Registry','ICT Support','Accounts','Library','Health Services'];
    foreach ($deptNames as $name) {
        $stmt = $bootstrap->prepare("INSERT IGNORE INTO departments(name) VALUES (?)"); $stmt->bind_param('s',$name); $stmt->execute();
    }
    $adminHash = password_hash('Admin@123', PASSWORD_DEFAULT);
    $stmt = $bootstrap->prepare("INSERT IGNORE INTO users(full_name,email,password_hash,role) VALUES ('System Administrator','admin@fwcm.edu.ng',?, 'admin')");
    $stmt->bind_param('s',$adminHash); $stmt->execute();
    $deptUserHash = password_hash('Dept@123', PASSWORD_DEFAULT);
    $deptRows = $bootstrap->query('SELECT id,name FROM departments')->fetch_all(MYSQLI_ASSOC);
    foreach ($deptRows as $dept) {
        $slug = strtolower(preg_replace('/[^a-z]+/i','.',trim($dept['name'])));
        $slug = trim($slug,'.') ?: 'department';
        $email = $slug . '@fwcm.edu.ng';
        $stmt = $bootstrap->prepare("INSERT IGNORE INTO users(full_name,email,department_id,password_hash,role) VALUES (?, ?, ?, ?, 'department')");
        $stmt->bind_param('ssis',$dept['name'],$email,$dept['id'],$deptUserHash);
        $stmt->execute();
    }
    $stmt->close();

    $conn = $bootstrap;
} catch (Throwable $e) {
    die('<h2>Database configuration error</h2><p>Start MySQL in XAMPP, then refresh this page.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>');
}

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf_token() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_verify() { if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) { http_response_code(403); die('Invalid security token. Please refresh and try again.'); } }
function require_login($roles = []) { if (empty($_SESSION['user'])) { header('Location: /flat-able-lite/dist/login.php'); exit; } if ($roles && !in_array($_SESSION['user']['role'],$roles,true)) { http_response_code(403); die('Access denied.'); } }
function redirect($url) { header('Location: ' . $url); exit; }
function flash($key, $value = null) { if ($value !== null) { $_SESSION['flash'][$key]=$value; return; } $v=$_SESSION['flash'][$key]??null; unset($_SESSION['flash'][$key]); return $v; }
function generate_ref($conn) { do { $ref='FWCM-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3))); $stmt=$conn->prepare('SELECT id FROM tickets WHERE ref_number=? LIMIT 1'); $stmt->bind_param('s',$ref); $stmt->execute(); $exists=$stmt->get_result()->num_rows>0; } while($exists); return $ref; }
