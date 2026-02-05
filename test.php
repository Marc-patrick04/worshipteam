<?php
require 'includes/config.php';
echo "MySQL connected successfully!";

// Test the fixed query
try {
    $groupCount = $pdo->query("SELECT COUNT(*) FROM groups WHERE is_published = true")->fetchColumn();
    echo "Query successful! Group count: $groupCount";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
