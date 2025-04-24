<?php
// diagnostic.php - Debug script to check database and session
session_start();

require_once "config.php";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Database and Session Diagnostic</h1>
    
    <h2>1. Session Information</h2>
    <pre><?php 
    if (isset($_SESSION)) {
        print_r($_SESSION);
    } else {
        echo "No session data found.";
    }
    ?></pre>
    
    <h2>2. Database Connection</h2>
    <?php
    if ($conn) {
        echo "<p class='success'>✓ Database connection successful</p>";
    } else {
        echo "<p class='error'>✗ Database connection failed: " . mysqli_connect_error() . "</p>";
        exit();
    }
    ?>
    
    <h2>3. Users Table</h2>
    <?php
    $sql = "SELECT id, username, email FROM users";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            echo "<table border='1'><tr><th>ID</th><th>Username</th><th>Email</th></tr>";
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr><td>{$row['id']}</td><td>{$row['username']}</td><td>{$row['email']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>No users found in database</p>";
        }
    } else {
        echo "<p class='error'>Error querying users: " . mysqli_error($conn) . "</p>";
    }
    ?>
    
    <h2>4. Wallets Table</h2>
    <?php
    $sql = "SELECT w.id, w.user_id, w.name, w.balance, w.currency, w.is_default, u.username 
            FROM wallets w 
            LEFT JOIN users u ON w.user_id = u.id";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            echo "<table border='1'><tr><th>ID</th><th>User ID</th><th>Username</th><th>Name</th><th>Balance</th><th>Currency</th><th>Default</th></tr>";
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr><td>{$row['id']}</td><td>{$row['user_id']}</td><td>{$row['username']}</td><td>{$row['name']}</td><td>{$row['balance']}</td><td>{$row['currency']}</td><td>{$row['is_default']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='info'>No wallets found in database</p>";
        }
    } else {
        echo "<p class='error'>Error querying wallets: " . mysqli_error($conn) . "</p>";
    }
    ?>
    
    <h2>5. Check for Logged In User</h2>
    <?php
    if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
        echo "<p class='success'>✓ User is logged in</p>";
        
        if (isset($_SESSION["id"])) {
            $sql = "SELECT * FROM users WHERE id = ?";
            if ($stmt = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) == 1) {
                    echo "<p class='success'>✓ User exists in database</p>";
                } else {
                    echo "<p class='error'>✗ User with ID {$_SESSION['id']} does not exist in database</p>";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            echo "<p class='error'>✗ Session ID not set</p>";
        }
    } else {
        echo "<p class='error'>✗ User not logged in</p>";
    }
    ?>
    
    <h2>6. Table Structure</h2>
    <?php
    $sql = "DESCRIBE wallets";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td><td>{$row['Extra']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>Error describing wallets table: " . mysqli_error($conn) . "</p>";
    }
    ?>
    
    <h2>Actions to Fix Issues</h2>
    <p>To fix issues, you can:</p>
    <ol>
        <li><a href="login.php">Login with existing user</a></li>
        <li><a href="register.php">Register a new user</a></li>
        <li><a href="quick_login.php">Quick login (for testing)</a></li>
    </ol>
</body>
</html>