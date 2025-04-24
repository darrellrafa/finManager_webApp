<?php
// config.php - Database configuration with error handling

// Error reporting (for debugging - remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database credentials
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');  // Change this to your database username
define('DB_PASSWORD', '');      // Change this to your database password
define('DB_NAME', 'finmanager');

// Application settings
define('APP_NAME', 'finmanager');
define('CURRENCY_SYMBOL', 'Rp');
define('CURRENCY_CODE', 'IDR');

// Attempt to connect to MySQL database with error checking
$conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($conn === false){
    // Show detailed error message for debugging
    die("ERROR: Could not connect to database. " . mysqli_connect_error() . 
        "<br><br>Please check:<br>" .
        "1. MySQL server is running<br>" .
        "2. Database '" . DB_NAME . "' exists<br>" .
        "3. Username '" . DB_USERNAME . "' is correct<br>" .
        "4. Password is correct<br>" .
        "5. User has proper permissions");
}

// Set character set to utf8mb4 to handle special characters
mysqli_set_charset($conn, "utf8mb4");

// Optional: Check if all required tables exist
$required_tables = ['users', 'wallets', 'expenses', 'income', 'budgets', 'recurring_transactions'];
$missing_tables = [];

foreach ($required_tables as $table) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($result) == 0) {
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "<div style='background: #fee; padding: 10px; border: 1px solid #f00; margin: 10px;'>";
    echo "<h3>Warning: Missing database tables</h3>";
    echo "<p>The following tables are missing: " . implode(', ', $missing_tables) . "</p>";
    echo "<p>Please run the SQL setup script to create these tables.</p>";
    echo "</div>";
}
?>