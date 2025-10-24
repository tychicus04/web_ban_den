<?php
// Database Configuration
$config = [
    'host' => 'localhost',
    'dbname' => 'u350721386_activeCMSECOM',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
];

// Create PDO connection
try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>