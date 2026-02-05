<?php
// Database setup for PostgreSQL
require_once 'includes/config.php';

try {
    // Use the configured PDO connection

    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'viewer' CHECK (role IN ('admin', 'viewer')),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create singers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS singers (
            id SERIAL PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            voice_category VARCHAR(20) NOT NULL CHECK (voice_category IN ('Soprano', 'Alto', 'Tenor', 'Bass')),
            voice_level VARCHAR(20) NOT NULL CHECK (voice_level IN ('Good', 'Normal')),
            status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create groups table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS groups (
            id SERIAL PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            service_date DATE NOT NULL DEFAULT CURRENT_DATE,
            service_order INT NOT NULL DEFAULT 1,
            is_published BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INT REFERENCES users(id)
        )
    ");

    // Create group_assignments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_assignments (
            id SERIAL PRIMARY KEY,
            group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            singer_id INT NOT NULL REFERENCES singers(id) ON DELETE CASCADE,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(group_id, singer_id)
        )
    ");

    // Create logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id SERIAL PRIMARY KEY,
            user_id INT REFERENCES users(id),
            action VARCHAR(255) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create landing_images table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS landing_images (
            id SERIAL PRIMARY KEY,
            image_path VARCHAR(255) NOT NULL,
            is_active BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create monthly_schedule table for group planning
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS monthly_schedule (
            id SERIAL PRIMARY KEY,
            group_id INT NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            service_date DATE NOT NULL,
            service_time VARCHAR(20) NOT NULL DEFAULT '1st Service' CHECK (service_time IN ('1st Service', '2nd Service', '3rd Service')),
            status VARCHAR(20) NOT NULL DEFAULT 'Scheduled' CHECK (status IN ('Scheduled', 'Confirmed', 'Cancelled')),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create singer_movement_history table to track singer movements between groups
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS singer_movement_history (
            id SERIAL PRIMARY KEY,
            singer_id INT NOT NULL REFERENCES singers(id) ON DELETE CASCADE,
            from_group_id INT REFERENCES groups(id) ON DELETE SET NULL,
            to_group_id INT REFERENCES groups(id) ON DELETE SET NULL,
            movement_date DATE NOT NULL,
            movement_type VARCHAR(20) NOT NULL CHECK (movement_type IN ('assigned', 'removed', 'transferred')),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Insert default admin user (password: admin123) if not exists
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role)
        SELECT ?, ?, 'admin'
        WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = ?)
    ");
    $stmt->execute(['admin', $adminPassword, 'admin']);

    // Insert test singers for demonstration (34 singers with various voice categories and levels)
   

    

    echo "Database setup completed successfully!<br>";
    echo "Default admin login: username 'admin', password 'admin123'<br>";
  
    echo "<a href='index.php'>Go to main site</a>";

} catch (PDOException $e) {
    echo "Database setup failed: " . $e->getMessage();
}
?>
