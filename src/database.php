<?php
/**
 * database.php - Database connection and initialization helper.
 * This file manages the MySQL database connection and ensures necessary tables exist.
 */

// Define database connection constants
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'mzansibuys');
define('DB_USER', getenv('DB_USER') ?: 'mb_user');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'secure_password');

/**
 * Establishes a PDO connection to the MySQL database.
 * Attempts to automatically create the database if it does not exist.
 * 
 * @return PDO The database connection object.
 * @throws Exception If the connection fails.
 */
function getDbConnection() {
    try {
        // Connect to the MySQL server without specifying the database first
        $dsnWithoutDb = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $db = new PDO($dsnWithoutDb, DB_USER, DB_PASS);
        
        // Configure PDO to throw exceptions on error
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Automatically create the database if it doesn't exist
        $db->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
        
        // Select the database
        $db->exec("USE `" . DB_NAME . "`;");
        
        // Configure default fetch mode
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $db;
    } catch (PDOException $e) {
        // Terminate execution if the database connection cannot be established.
        die("Database Connection failed: " . $e->getMessage() . " | Please verify that your MySQL server is running and user credentials ('" . DB_USER . "' / '" . DB_PASS . "') are correct.");
    }
}

/**
 * Initializes the database schema and populates seed data if necessary.
 */
function initDatabase() {
    $db = getDbConnection();
    
    // Create 'users' table to store account information.
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        is_verified INT DEFAULT 0,
        id_number VARCHAR(13) DEFAULT NULL,
        is_admin INT DEFAULT 0,
        is_banned INT DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4;");

    // Create 'items' table to store marketplace listings.
    $db->exec("CREATE TABLE IF NOT EXISTS items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        price DECIMAL(10,2) NOT NULL,
        category VARCHAR(255) DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'available',
        buyer_id INT DEFAULT NULL,
        image_path VARCHAR(500) DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4;");

    // Create 'reviews' table to track seller ratings
    $db->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seller_id INT NOT NULL,
        buyer_id INT NOT NULL,
        item_id INT NOT NULL,
        rating INT NOT NULL,
        FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    ) DEFAULT CHARSET=utf8mb4;");

    // Create 'reports' table to store user reports on purchases
    $db->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        reported_id INT NOT NULL,
        item_id INT DEFAULT NULL,
        reason VARCHAR(255) NOT NULL,
        details TEXT DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reported_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
    ) DEFAULT CHARSET=utf8mb4;");

    // Check if the 'users' table is empty to provide a default test account.
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        // Create a seed user: username 'seed_user', password 'password123'.
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
        $stmt->execute(['seed_user', $hash, 'seed_user@example.com']);
    }

    // Seed default admin account if not already present
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute(['admin']);
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, email, is_admin) VALUES (?, ?, ?, 1)");
        $stmt->execute(['admin', $hash, 'admin@mzansibuys.site']);
    }
}

// Run the initialization process when this file is included.
initDatabase();