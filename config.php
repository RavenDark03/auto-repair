<?php
// Database Credentials
define('DB_HOST', 'mysql-ravendark.alwaysdata.net');
define('DB_USER', 'ravendark');
define('DB_PASS', 'Password#1234');
define('DB_NAME', 'ravendark_auto-repair');

// Site Configuration
define('BASE_URL', 'http://ravendark.alwaysdata.net/');

// Create Connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check Connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>
