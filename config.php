<?php
/**
 * Database Configuration
 *
 * SECURITY WARNINGS:
 * 1. The database password is currently EMPTY - This is a CRITICAL security risk!
 * 2. Please set a strong password for your database in production
 * 3. Consider using .env file for sensitive configuration
 * 4. Never commit database credentials to version control
 *
 * To generate a strong password, use:
 * - Linux/Mac: openssl rand -base64 32
 * - Or use online password generator
 */

// Load environment configuration if available
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    $config = [
        'host' => $env['DB_HOST'] ?? 'localhost',
        'dbname' => $env['DB_NAME'] ?? 'u350721386_activeCMSECOM',
        'username' => $env['DB_USERNAME'] ?? 'root',
        'password' => $env['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4'
    ];
} else {
    // Fallback to hardcoded values (NOT RECOMMENDED for production)
    // TODO: Create .env file from .env.example and set proper credentials
    $config = [
        'host' => 'localhost',
        'dbname' => 'u350721386_activeCMSECOM',
        'username' => 'root',
        'password' => '', // ⚠️ SECURITY WARNING: SET A STRONG PASSWORD!
        'charset' => 'utf8mb4'
    ];
}

// Create PDO connection
try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
        PDO::ATTR_PERSISTENT => false, // Don't use persistent connections
    ];
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
} catch (PDOException $e) {
    // Don't expose database errors in production
    error_log("Database connection failed: " . $e->getMessage());
    die("Unable to connect to database. Please contact support.");
}

/**
 * Helper function for backward compatibility with admin area
 * Some admin files use getDBConnection() instead of $pdo
 */
function getDBConnection() {
    global $pdo;
    return $pdo;
}
?>