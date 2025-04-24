<?php
// quick_fix.php - Quick fix for session and database issues
session_start();

require_once "config.php";

echo "<h1>Quick Fix Script</h1>";

// Option 1: Fix session to match existing user
if (isset($_GET['action']) && $_GET['action'] == 'fix_session') {
    // Find the first user in the database
    $sql = "SELECT id, username FROM users LIMIT 1";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $_SESSION["loggedin"] = true;
        $_SESSION["id"] = $user['id'];
        $_SESSION["username"] = $user['username'];
        
        echo "<p style='color: green;'>✓ Session fixed! Logged in as: " . $user['username'] . " (ID: " . $user['id'] . ")</p>";
        echo "<p><a href='wallets.php'>Go to wallets page</a></p>";
    } else {
        echo "<p style='color: red;'>✗ No users found in database</p>";
        echo "<p><a href='?action=create_user'>Create a new user</a></p>";
    }
}
// Option 2: Create a new user
elseif (isset($_GET['action']) && $_GET['action'] == 'create_user') {
    $sql = "INSERT INTO users (username, password, email) VALUES ('testuser', ?, 'test@example.com')";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        $hashed_password = password_hash("password123", PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, "s", $hashed_password);
        
        if (mysqli_stmt_execute($stmt)) {
            $user_id = mysqli_insert_id($conn);
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $user_id;
            $_SESSION["username"] = "testuser";
            
            echo "<p style='color: green;'>✓ Created new user and logged in!</p>";
            echo "<p>Username: testuser</p>";
            echo "<p>Password: password123</p>";
            echo "<p><a href='wallets.php'>Go to wallets page</a></p>";
        } else {
            echo "<p style='color: red;'>✗ Error creating user: " . mysqli_error($conn) . "</p>";
        }
        mysqli_stmt_close($stmt);
    }
}
// Option 3: Clear session and start fresh
elseif (isset($_GET['action']) && $_GET['action'] == 'clear_session') {
    session_destroy();
    echo "<p style='color: green;'>✓ Session cleared!</p>";
    echo "<p><a href='login.php'>Go to login page</a></p>";
}
// Show options
else {
    echo "<h2>Choose an action:</h2>";
    echo "<ul>";
    echo "<li><a href='?action=fix_session'>Fix session to match existing user</a></li>";
    echo "<li><a href='?action=create_user'>Create a new test user</a></li>";
    echo "<li><a href='?action=clear_session'>Clear session and start fresh</a></li>";
    echo "</ul>";
    
    echo "<h2>Current Status:</h2>";
    echo "<p>Logged in: " . (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] ? "Yes" : "No") . "</p>";
    if (isset($_SESSION["id"])) {
        echo "<p>Session User ID: " . $_SESSION["id"] . "</p>";
        
        // Check if this user exists
        $sql = "SELECT * FROM users WHERE id = ?";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                echo "<p style='color: green;'>✓ User exists in database</p>";
            } else {
                echo "<p style='color: red;'>✗ User ID " . $_SESSION["id"] . " does not exist in database</p>";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    echo "<h2>Database Status:</h2>";
    
    // Check users table
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        echo "<p>Users in database: " . $row['count'] . "</p>";
    }
    
    // Check wallets table
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM wallets");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        echo "<p>Wallets in database: " . $row['count'] . "</p>";
    }
}
?>