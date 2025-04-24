<?php
// wallets.php - Wallet management page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Process form submission to add/edit wallet
$wallet_id = $name = $balance = $currency = $is_default = "";
$wallet_err = "";
$success_message = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Check if we're editing an existing wallet
    if(!empty($_POST["wallet_id"])){
        $wallet_id = $_POST["wallet_id"];
    }
    
    // Validate name
    if(empty(trim($_POST["name"]))){
        $wallet_err = "Please enter a wallet name.";
    } else{
        $name = trim($_POST["name"]);
    }
    
    // Validate balance
    if(!isset($_POST["balance"]) || $_POST["balance"] === ""){
        $wallet_err = "Please enter a balance.";
    } else{
        $balance = floatval($_POST["balance"]);
    }
    
    // Validate currency
    if(empty(trim($_POST["currency"]))){
        $currency = "Rp"; // Default currency changed to IDR
    } else{
        $currency = trim($_POST["currency"]);
    }
    
    // Check if wallet is default
    $is_default = isset($_POST["is_default"]) ? 1 : 0;
    
    // If no errors, proceed with saving
    if(empty($wallet_err)){
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // If this is set as default, unset any other defaults first
            if($is_default) {
                $sql = "UPDATE wallets SET is_default = 0 WHERE user_id = ?";
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
            
            if(empty($wallet_id)){
                // Insert new wallet
                $sql = "INSERT INTO wallets (user_id, name, balance, currency, is_default) VALUES (?, ?, ?, ?, ?)";
                
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "isdsi", $_SESSION["id"], $name, $balance, $currency, $is_default);
                    
                    if(mysqli_stmt_execute($stmt)){
                        $success_message = "Wallet successfully added!";
                    } else{
                        throw new Exception("Something went wrong. Please try again later.");
                    }
                    
                    mysqli_stmt_close($stmt);
                }
            } else {
                // Update existing wallet
                $sql = "UPDATE wallets SET name = ?, balance = ?, currency = ?, is_default = ? WHERE id = ? AND user_id = ?";
                
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "sdsiis", $name, $balance, $currency, $is_default, $wallet_id, $_SESSION["id"]);
                    
                    if(mysqli_stmt_execute($stmt)){
                        $success_message = "Wallet successfully updated!";
                    } else{
                        throw new Exception("Something went wrong. Please try again later.");
                    }
                    
                    mysqli_stmt_close($stmt);
                }
            }
            
            // If no default wallet exists, set the first one as default
            $sql = "SELECT COUNT(*) as count FROM wallets WHERE user_id = ? AND is_default = 1";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = mysqli_fetch_assoc($result);
                
                if($row['count'] == 0){
                    $sql = "UPDATE wallets SET is_default = 1 WHERE user_id = ? LIMIT 1";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
                    mysqli_stmt_execute($stmt);
                }
                
                mysqli_stmt_close($stmt);
            }
            
            // Commit transaction
            mysqli_commit($conn);
        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($conn);
            $wallet_err = $e->getMessage();
        }
    }
}

// Handle delete request
if(isset($_GET["delete"]) && !empty($_GET["delete"])){
    $delete_id = $_GET["delete"];
    
    // Check if this is the default wallet
    $is_default_wallet = false;
    $sql = "SELECT is_default FROM wallets WHERE id = ? AND user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $delete_id, $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $is_default_wallet = $row['is_default'] == 1;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Check if this is the only wallet
    $is_only_wallet = false;
    $sql = "SELECT COUNT(*) as count FROM wallets WHERE user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $is_only_wallet = $row['count'] == 1;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Don't allow deleting the only wallet
    if($is_only_wallet){
        header("location: wallets.php?error=cannot_delete_only");
        exit();
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Delete the wallet
        $sql = "DELETE FROM wallets WHERE id = ? AND user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "ii", $delete_id, $_SESSION["id"]);
            
            if(mysqli_stmt_execute($stmt)){
                // If we deleted the default wallet, set another one as default
                if($is_default_wallet){
                    $sql = "UPDATE wallets SET is_default = 1 WHERE user_id = ? LIMIT 1";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
                    mysqli_stmt_execute($stmt);
                }
                
                // Commit transaction
                mysqli_commit($conn);
                header("location: wallets.php?deleted=1");
                exit();
            } else{
                throw new Exception("Something went wrong");
            }
            
            mysqli_stmt_close($stmt);
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($conn);
        header("location: wallets.php?error=delete_failed");
        exit();
    }
}

// Handle edit request
if(isset($_GET["edit"]) && !empty($_GET["edit"])){
    $edit_id = $_GET["edit"];
    
    $sql = "SELECT * FROM wallets WHERE id = ? AND user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $edit_id, $_SESSION["id"]);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            
            if(mysqli_num_rows($result) == 1){
                $row = mysqli_fetch_array($result);
                
                $wallet_id = $row["id"];
                $name = $row["name"];
                $balance = $row["balance"];
                $currency = $row["currency"];
                $is_default = $row["is_default"];
            } else{
                // Redirect if wallet not found
                header("location: wallets.php");
                exit();
            }
        } else{
            $wallet_err = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Fetch all wallets
$sql = "SELECT w.*, 
        (SELECT SUM(amount) FROM expenses WHERE wallet_id = w.id) as total_expenses,
        (SELECT SUM(amount) FROM income WHERE wallet_id = w.id) as total_income
        FROM wallets w 
        WHERE w.user_id = ? 
        ORDER BY w.is_default DESC, w.name ASC";

$wallets = [];

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_array($result)){
        $wallets[] = $row;
    }
}

// Calculate total balance across all wallets
$total_balance = 0;
foreach($wallets as $wallet){
    $total_balance += $wallet['balance'];
}

mysqli_close($conn);

// Function to format numbers in IDR format
function formatIDR($number) {
    return number_format($number, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallets - finmanager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        :root {
            --primary-color: #5e72e4;
            --sidebar-bg: #ffffff;
            --main-bg: #f5f7fb;
            --card-bg: #ffffff;
            --text-color: #2d3748;
            --secondary-text: #718096;
            --border-color: #e2e8f0;
            --increase-color: #48bb78;
            --decrease-color: #f56565;
            --progress-bg: #f7fafc;
            --progress-grocery: #48bb78;
            --progress-dining: #ed8936;
            --progress-entertainment: #ed8936;
            --progress-clothing: #38b2ac;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --border-radius: 12px;
        }

        body {
            background-color: var(--main-bg);
            color: var(--text-color);
            font-size: 14px;
            line-height: 1.5;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .logo-container {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .logo-container i {
            font-size: 1.5rem;
            margin-right: 0.75rem;
        }

        .logo-text {
            font-weight: 700;
            font-size: 1.25rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            cursor: pointer;
        }

        .user-avatar {
            width: 30px;
            height: 30px;
            background-color: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.75rem;
        }

        .user-name {
            flex-grow: 1;
            font-weight: 500;
        }

        .sidebar-search, .sidebar-notifications {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background-color: var(--main-bg);
            border-radius: 50%;
            margin-right: 0.75rem;
            cursor: pointer;
        }

        .sidebar-nav {
            margin-top: 2rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--text-color);
            text-decoration: none;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            transition: background-color 0.2s;
        }

        .nav-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        .nav-item.active {
            background-color: rgba(94, 114, 228, 0.1);
            color: var(--primary-color);
            font-weight: 500;
        }

        .nav-item i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 2rem;
        }
        
        .wallets-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .total-balance {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .total-balance-label {
            color: var(--secondary-text);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .total-balance-amount {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .btn-add-wallet {
            padding: 0.5rem 1rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .wallets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .wallet-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .wallet-card.default::after {
            content: 'Default';
            position: absolute;
            top: 10px;
            right: -25px;
            background-color: var(--primary-color);
            color: white;
            padding: 0.25rem 2rem;
            transform: rotate(45deg);
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .wallet-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .wallet-name {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .wallet-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .wallet-action-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--secondary-text);
            transition: background-color 0.2s;
        }
        
        .wallet-action-btn:hover {
            background-color: var(--main-bg);
        }
        
        .wallet-balance {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        
        .wallet-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
        }
        
        .wallet-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .wallet-stat-label {
            color: var(--secondary-text);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        
        .wallet-stat-value {
            font-weight: 500;
        }
        
        .wallet-stat-value.income {
            color: var(--increase-color);
        }
        
        .wallet-stat-value.expense {
            color: var(--decrease-color);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            max-width: 500px;
            width: 100%;
            padding: 2rem;
            box-shadow: var(--shadow);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--secondary-text);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .form-check-input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .form-check-label {
            cursor: pointer;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .btn-cancel {
            padding: 0.5rem 1rem;
            background-color: var(--main-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
        }
        
        .btn-save {
            padding: 0.5rem 1rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
        }
        
        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
        }
        
        .alert-success {
            background-color: rgba(72, 187, 120, 0.1);
            color: var(--increase-color);
            border: 1px solid rgba(72, 187, 120, 0.3);
        }
        
        .alert-danger {
            background-color: rgba(245, 101, 101, 0.1);
            color: var(--decrease-color);
            border: 1px solid rgba(245, 101, 101, 0.3);
        }
        
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--secondary-text);
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        
        .empty-state p {
            margin-bottom: 1rem;
        }
        
        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        a {
            text-decoration: none;
            color: inherit;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="sidebar">
            <div class="logo-container">
                <i class="fas fa-wallet"></i>
                <span class="logo-text">finmanager</span>
            </div>
            
            <div class="user-menu">
                <div class="user-avatar"><?php echo substr($_SESSION["username"], 0, 1); ?></div>
                <div class="user-name"><?php echo htmlspecialchars($_SESSION["username"]); ?></div>
                <i class="fas fa-chevron-down"></i>
            </div>
            
            <div class="sidebar-search">
                <i class="fas fa-search"></i>
            </div>
            
            <div class="sidebar-notifications">
                <i class="fas fa-bell"></i>
            </div>
            
            <nav class="sidebar-nav">
                <a href="index.php" class="nav-item">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                
                <!-- <a href="#" class="nav-item">
                    <i class="fas fa-thumbtack"></i>
                    <span>Pinned</span>
                </a> -->
                
                <a href="wallets.php" class="nav-item active">
                    <i class="fas fa-wallet"></i>
                    <span>Wallets</span>
                </a>
                
                <a href="transactions.php" class="nav-item">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transactions</span>
                </a>
                
                <a href="budgets.php" class="nav-item">
                    <i class="fas fa-chart-pie"></i>
                    <span>Budgets & Goals</span>
                </a>
                
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-redo"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
        
        <div class="main-content">
            <div class="wallets-header">
                <h1>My Wallets</h1>
                <button class="btn-add-wallet" id="openModalBtn">
                    <i class="fas fa-plus"></i>
                    <span>Add Wallet</span>
                </button>
            </div>
            
            <?php if(isset($_GET["deleted"]) && $_GET["deleted"] == 1): ?>
            <div class="alert alert-success">Wallet successfully deleted!</div>
            <?php endif; ?>
            
            <?php if(isset($_GET["error"]) && $_GET["error"] == "cannot_delete_only"): ?>
            <div class="alert alert-danger">Cannot delete your only wallet. Please add another wallet first.</div>
            <?php endif; ?>
            
            <?php if(isset($_GET["error"]) && $_GET["error"] == "delete_failed"): ?>
            <div class="alert alert-danger">Failed to delete wallet. Please try again later.</div>
            <?php endif; ?>
            
            <?php if(!empty($wallet_err)): ?>
            <div class="alert alert-danger"><?php echo $wallet_err; ?></div>
            <?php endif; ?>
            
            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <div class="total-balance">
                <div class="total-balance-label">Total Balance</div>
                <div class="total-balance-amount">Rp <?php echo formatIDR($total_balance); ?></div>
            </div>
            
            <?php if(count($wallets) > 0): ?>
            <div class="wallets-grid">
                <?php foreach($wallets as $wallet): 
                    $total_expenses = $wallet['total_expenses'] ?: 0;
                    $total_income = $wallet['total_income'] ?: 0;
                ?>
                <div class="wallet-card <?php echo $wallet['is_default'] ? 'default' : ''; ?>">
                    <div class="wallet-card-header">
                        <div class="wallet-name"><?php echo htmlspecialchars($wallet['name']); ?></div>
                        <div class="wallet-actions">
                            <a href="?edit=<?php echo $wallet['id']; ?>" class="wallet-action-btn">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if(!$wallet['is_default']): ?>
                            <a href="?delete=<?php echo $wallet['id']; ?>" class="wallet-action-btn" onclick="return confirm('Are you sure you want to delete this wallet?');">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="wallet-balance"><?php echo $wallet['currency']; ?> <?php echo formatIDR($wallet['balance']); ?></div>
                    
                    <div class="wallet-stats">
                        <div class="wallet-stat">
                            <div class="wallet-stat-label">INCOME</div>
                            <div class="wallet-stat-value income">+Rp <?php echo formatIDR($total_income); ?></div>
                        </div>
                        <div class="wallet-stat">
                            <div class="wallet-stat-label">EXPENSE</div>
                            <div class="wallet-stat-value expense">-Rp <?php echo formatIDR($total_expenses); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <p>You don't have any wallets yet</p>
                <button class="btn-add-wallet" id="openModalBtn2">Add your first wallet</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Wallet Modal -->
    <div class="modal <?php echo (!empty($wallet_err) || !empty($wallet_id)) ? 'active' : ''; ?>" id="walletModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><?php echo empty($wallet_id) ? 'Add New Wallet' : 'Edit Wallet'; ?></h2>
                <button class="close-modal" id="closeModalBtn">&times;</button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="wallet_id" value="<?php echo $wallet_id; ?>">
                
                <div class="form-group">
                    <label>Wallet Name</label>
                    <input type="text" name="name" class="form-control" value="<?php echo $name; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Current Balance</label>
                    <input type="number" name="balance" class="form-control" step="0.01" value="<?php echo $balance; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Currency</label>
                    <select name="currency" class="form-control">
                        <option value="$" <?php echo $currency === '$' ? 'selected' : ''; ?>>$ (USD)</option>
                        <option value="€" <?php echo $currency === '€' ? 'selected' : ''; ?>>€ (EUR)</option>
                        <option value="£" <?php echo $currency === '£' ? 'selected' : ''; ?>>£ (GBP)</option>
                        <option value="¥" <?php echo $currency === '¥' ? 'selected' : ''; ?>>¥ (JPY)</option>
                        <option value="₹" <?php echo $currency === '₹' ? 'selected' : ''; ?>>₹ (INR)</option>
                        <option value="₽" <?php echo $currency === '₽' ? 'selected' : ''; ?>>₽ (RUB)</option>
                        <option value="₩" <?php echo $currency === '₩' ? 'selected' : ''; ?>>₩ (KRW)</option>
                        <option value="C$" <?php echo $currency === 'C$' ? 'selected' : ''; ?>>C$ (CAD)</option>
                        <option value="A$" <?php echo $currency === 'A$' ? 'selected' : ''; ?>>A$ (AUD)</option>
                        <option value="Rp" <?php echo $currency === 'Rp' ? 'selected' : ''; ?>>Rp (IDR)</option>
                    </select>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" name="is_default" id="is_default" class="form-check-input" <?php echo $is_default ? 'checked' : ''; ?>>
                    <label for="is_default" class="form-check-label">Set as default wallet</label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="cancelBtn">Cancel</button>
                    <button type="submit" class="btn-save">Save Wallet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript for modal functionality -->
    <script>
        // Modal elements
        const modal = document.getElementById('walletModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const openModalBtn2 = document.getElementById('openModalBtn2');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelBtn = document.getElementById('cancelBtn');

        // Function to open modal
        function openModal() {
            modal.classList.add('active');
        }

        // Function to close modal
        function closeModal() {
            modal.classList.remove('active');
            // Reset the URL to remove any edit parameters
            if (window.location.href.includes('?edit=')) {
                window.location.href = 'wallets.php';
            }
        }

        // Event listeners
        if (openModalBtn) {
            openModalBtn.addEventListener('click', openModal);
        }
        
        if (openModalBtn2) {
            openModalBtn2.addEventListener('click', openModal);
        }
        
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', closeModal);
        }
        
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }

        // Close modal when clicking outside of it
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    </script>
</body>
</html>