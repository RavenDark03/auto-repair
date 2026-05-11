<?php
require_once 'includes/db.php';

try {
    $pdo = Database::getInstance();
    echo "Database connected successfully!";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
