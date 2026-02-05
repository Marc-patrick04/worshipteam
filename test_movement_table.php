<?php
// Test script to check and create the singer_movement_history table
require_once 'includes/config.php';

try {
    // Check if table exists
    $result = $pdo->query("SELECT EXISTS (
        SELECT FROM information_schema.tables
        WHERE table_name = 'singer_movement_history'
    )");
    $tableExists = $result->fetchColumn();

    if (!$tableExists) {
        echo "Creating singer_movement_history table...<br>";

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

        echo "✅ Table created successfully!<br>";
    } else {
        echo "✅ Table already exists.<br>";
    }

    // Test the table by inserting some sample data if no data exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM singer_movement_history");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        echo "No movement data found. Adding some test data...<br>";

        // Get some groups and singers to create test movements
        $groups = $pdo->query("SELECT id, name FROM groups LIMIT 2")->fetchAll();
        $singers = $pdo->query("SELECT id, full_name FROM singers LIMIT 3")->fetchAll();

        if (count($groups) >= 2 && count($singers) >= 1) {
            // Add a test movement
            $stmt = $pdo->prepare("
                INSERT INTO singer_movement_history (singer_id, from_group_id, to_group_id, movement_date, movement_type, notes)
                VALUES (?, ?, ?, CURRENT_DATE, 'assigned', 'Test assignment')
            ");
            $stmt->execute([$singers[0]['id'], null, $groups[0]['id']]);

            echo "✅ Test movement data added.<br>";
        }
    } else {
        echo "Found $count movement records.<br>";
    }

    echo "<br><a href='admin/groups.php'>Go to Groups Admin</a>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
