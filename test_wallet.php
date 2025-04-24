<?php
// test_wallet.php - Simplified wallet page for troubleshooting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Display all errors and debugging information
echo "<h1>Wallet Page Debug</h1>";
echo "<h2>1. Session Status</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

// Include config
if (file_exists("config.php")) {
    require_once "config.php";
    echo "<h2>2. Database Connection</h2>";
    if ($conn) {
        echo "<p style='color: green;'>✓ Connected to database</p>";
    } else {
        echo "<p style='color: red;'>✗ Database connection failed: " . mysqli_connect_error() . "</p>";
        exit();
    }
} else {
    echo "<p style='color: red;'>✗ config.php file not found!</p>";
    exit();
}

// Check if user is logged in
echo "<h2>3. Login Status</h2>";
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo "<p style='color: red;'>✗ User not logged in</p>";
    echo "<p><a href='login.php'>Go to login page</a></p>";
    exit();
} else {
    echo "<p style='color: green;'>✓ User is logged in</p>";
}

// Check if user exists in database
echo "<h2>4. User Verification</h2>";
if (isset($_SESSION["id"])) {
    $sql = "SELECT * FROM users WHERE id = ?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            echo "<p style='color: green;'>✓ User found in database</p>";
            echo "<p>User ID: " . $user['id'] . "</p>";
            echo "<p>Username: " . $user['username'] . "</p>";
        } else {
            echo "<p style='color: red;'>✗ User ID " . $_SESSION["id"] . " not found in database</p>";
            echo "<p><a href='quick_fix.php'>Click here to fix</a></p>";
            exit();
        }
        mysqli_stmt_close($stmt);
    } else {
        echo "<p style='color: red;'>✗ Database query error: " . mysqli_error($conn) . "</p>";
        exit();
    }
} else {
    echo "<p style='color: red;'>✗ No user ID in session</p>";
    exit();
}

// Try to fetch wallets
echo "<h2>5. Fetching Wallets</h2>";
$sql = "SELECT * FROM wallets WHERE user_id = ?";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        echo "<p style='color: green;'>✓ Query executed successfully</p>";
        echo "<p>Number of wallets found: " . mysqli_num_rows($result) . "</p>";
        
        if (mysqli_num_rows($result) > 0) {
            echo "<table border='1'>";
            echo "<tr><th>ID</th><th>Name</th><th>Balance</th><th>Currency</th></tr>";
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . $row['name'] . "</td>";
                echo "<td>" . $row['balance'] . "</td>";
                echo "<td>" . $row['currency'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No wallets found for this user</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Query execution failed: " . mysqli_error($conn) . "</p>";
    }
    mysqli_stmt_close($stmt);
} else {
    echo "<p style='color: red;'>✗ Failed to prepare query: " . mysqli_error($conn) . "</p>";
}

// Add a test wallet form
echo "<h2>6. Add Test Wallet</h2>";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["create_test_wallet"])) {
    $sql = "INSERT INTO wallets (user_id, name, balance, currency, is_default) VALUES (?, 'Test Wallet', 1000.00, '$', 1)";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        if (mysqli_stmt_execute($stmt)) {
            echo "<p style='color: green;'>✓ Test wallet created successfully!</p>";
            echo "<p><a href='test_wallet.php'>Refresh page</a></p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to create test wallet: " . mysqli_error($conn) . "</p>";
        }
        mysqli_stmt_close($stmt);
    }
}
?>

<form method="post">
    <button type="submit" name="create_test_wallet">Create Test Wallet</button>
</form>

<h2>7. Links</h2>
<p><a href="wallets.php">Try regular wallets page</a></p>
<p><a href="quick_fix.php">Quick fix script</a></p>
<p><a href="logout.php">Logout</a></p>