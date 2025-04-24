<?php
// quick_login.php - Quick login script for testing
session_start();

require_once "config.php";

// First, let's check if user with ID 1 exists
$sql = "SELECT id, username FROM users WHERE id = 1";
$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $user = mysqli_fetch_assoc($result);
    
    // Log in as user ID 1
    $_SESSION["loggedin"] = true;
    $_SESSION["id"] = $user['id'];
    $_SESSION["username"] = $user['username'];
    
    echo "Successfully logged in as " . $user['username'] . " (ID: " . $user['id'] . ")";
    echo "<br><br>";
    echo '<a href="wallets.php">Go to Wallets</a>';
} else {
    // If user doesn't exist, create one
    $sql = "INSERT INTO users (username, password, email) VALUES ('testuser', ?, 'test@example.com')";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        $hashed_password = password_hash("password123", PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($stmt, "s", $hashed_password);
        
        if (mysqli_stmt_execute($stmt)) {
            $user_id = mysqli_insert_id($conn);
            
            // Log in as the new user
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $user_id;
            $_SESSION["username"] = "testuser";
            
            echo "Created and logged in as testuser (ID: " . $user_id . ")";
            echo "<br><br>";
            echo '<a href="wallets.php">Go to Wallets</a>';
        } else {
            echo "Error creating user: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($conn);
?>