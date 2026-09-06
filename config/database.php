<?php
/**
 * Database Configuration
 * -----------------------
 * Connects to MySQL using PDO.
 * Default values below match a fresh XAMPP install.
 * If you set a root password in MySQL/phpMyAdmin, update DB_PASS.
 */

// ---- Connection settings ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'mpd_ecommerce'); // Create this database in phpMyAdmin first
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP default is blank

// ---- Create PDO connection ----
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // return rows as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                    // use real prepared statements
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (PDOException $e) {
    // In production, log this instead of displaying it
    die("Database connection failed: " . $e->getMessage());
}