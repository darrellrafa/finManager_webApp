<?php
// edit_transaction.php - Edit transaction page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Define variables and initialize with empty values
$type = $id = $amount = $category = $description = $wallet_id = $date = $original_wallet_id = $original_amount = $original_category = "";
$type_err = $amount_err = $category_err = $wallet_err = $date_err = "";
$success_message = $budget_message = "";

// Function to format currency in IDR
function formatIDR($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Get available wallets for the user
$wallets = [];
$sql = "SELECT id, name, balance FROM wallets WHERE user_id = ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $wallets[] = $row;
        }
    }
}

// Get common categories
$expense_categories = ["Housing", "Transportation", "Food", "Utilities", "Insurance", "Healthcare", "Debt", "Personal", "Entertainment", "Clothing", "Education", "Gifts", "Savings", "Groceries", "Dining", "Other"];
$income_sources = ["Salary", "Freelance", "Investment", "Gift", "Sale", "Refund", "Other"];

// Get active budget categories for the user
$active_budgets = [];
$sql = "SELECT id, category, amount as budget_amount, icon FROM budgets 
        WHERE user_id = ? AND CURDATE() BETWEEN start_date AND end_date";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $active_budgets[$row['category']] = $row;
        }
    }
}

// Check if transaction ID is provided
if(isset($_GET["id"]) && isset($_GET["type"]) && !empty($_GET["id"]) && !empty($_GET["type"])){
    // Validate and sanitize input
    $id = trim($_GET["id"]);
    $type = trim($_GET["type"]);
    
    if($type != "expense" && $type != "income"){
        // Invalid transaction type, redirect to transactions page
        header("location: transactions.php");
        exit;
    }
    
    // Fetch transaction details based on type
    if($type == "expense"){
        $sql = "SELECT e.id, e.amount, e.category, e.description, e.date, e.wallet_id FROM expenses e 
                WHERE e.id = ? AND e.user_id = ?";
    } else {
        $sql = "SELECT i.id, i.amount, i.source as category, i.description, i.date, i.wallet_id FROM income i 
                WHERE i.id = ? AND i.user_id = ?";
    }
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $id, $_SESSION["id"]);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) == 1){
                $row = mysqli_fetch_assoc($result);
                
                // Store the original values for balance updates
                $original_wallet_id = $row["wallet_id"];
                $original_amount = $row["amount"];
                $original_category = $row["category"];
                
                // Set form values
                $amount = $row["amount"];
                $category = $row["category"];
                $description = $row["description"];
                $wallet_id = $row["wallet_id"];
                $date = $row["date"];
            } else {
                // Transaction not found, redirect to transactions page
                header("location: transactions.php");
                exit;
            }
        } else {
            // Error in query execution
            header("location: transactions.php");
            exit;
        }
    }
} else {
    // ID not provided, redirect to transactions page
    header("location: transactions.php");
    exit;
}

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Validate amount
    if(empty(trim($_POST["amount"]))){
        $amount_err = "Please enter an amount.";     
    } elseif(!is_numeric(trim($_POST["amount"])) || floatval(trim($_POST["amount"])) <= 0){
        $amount_err = "Please enter a valid positive amount.";
    } else{
        $amount = floatval(trim($_POST["amount"]));
    }
    
    // Validate category/source
    $category_field = $type == "expense" ? "category" : "source";
    if(empty(trim($_POST[$category_field]))){
        $category_err = "Please enter a " . ($type == "expense" ? "category" : "source") . ".";     
    } else{
        $category = trim($_POST[$category_field]);
    }
    
    // Validate wallet
    if(empty(trim($_POST["wallet_id"]))){
        $wallet_err = "Please select a wallet.";     
    } else{
        $wallet_id = trim($_POST["wallet_id"]);
        
        // Check if the wallet belongs to the user
        $wallet_valid = false;
        foreach($wallets as $wallet){
            if($wallet["id"] == $wallet_id){
                $wallet_valid = true;
                break;
            }
        }
        
        if(!$wallet_valid){
            $wallet_err = "Invalid wallet selection.";
        }
    }
    
    // Validate date
    if(empty(trim($_POST["date"]))){
        $date_err = "Please select a date.";     
    } else{
        $date = trim($_POST["date"]);
        if(!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)){
            $date_err = "Please enter a valid date in format YYYY-MM-DD.";
        }
    }
    
    // Get description
    $description = trim($_POST["description"]);
    
    // Check input errors before updating in database
    if(empty($amount_err) && empty($category_err) && empty($wallet_err) && empty($date_err)){
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        $success = true;
        
        try {
            // Revert the original transaction's effect on wallet balance
            if($original_wallet_id) {
                $revert_amount = $type == "expense" ? $original_amount : -$original_amount;
                $sql = "UPDATE wallets SET balance = balance + ? WHERE id = ? AND user_id = ?";
                
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "dii", $revert_amount, $original_wallet_id, $_SESSION["id"]);
                    
                    if(!mysqli_stmt_execute($stmt)){
                        $success = false;
                        throw new Exception("Could not update wallet balance.");
                    }
                } else {
                    $success = false;
                    throw new Exception("Something went wrong. Please try again later.");
                }
            }
            
            // Update transaction based on type
            if($type == "expense"){
                $sql = "UPDATE expenses SET amount = ?, category = ?, description = ?, date = ?, wallet_id = ? WHERE id = ? AND user_id = ?";
            } else {
                $sql = "UPDATE income SET amount = ?, source = ?, description = ?, date = ?, wallet_id = ? WHERE id = ? AND user_id = ?";
            }
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "dssssii", $amount, $category, $description, $date, $wallet_id, $id, $_SESSION["id"]);
                
                if(!mysqli_stmt_execute($stmt)){
                    $success = false;
                    throw new Exception("Transaction could not be updated.");
                }
            } else {
                $success = false;
                throw new Exception("Something went wrong. Please try again later.");
            }
            
            // Apply the new transaction effect on wallet balance
            $balance_change = $type == "expense" ? -$amount : $amount;
            $sql = "UPDATE wallets SET balance = balance + ? WHERE id = ? AND user_id = ?";
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "dii", $balance_change, $wallet_id, $_SESSION["id"]);
                
                if(!mysqli_stmt_execute($stmt)){
                    $success = false;
                    throw new Exception("Wallet balance could not be updated.");
                }
            } else {
                $success = false;
                throw new Exception("Something went wrong. Please try again later.");
            }
            
            // Check if this expense affects any budget
            if($type == "expense") {
                $budget_changes = [];
                
                // Check if original category had a budget
                if(isset($active_budgets[$original_category])) {
                    $budget_info = $active_budgets[$original_category];
                    
                    // Calculate current spending for the original budget
                    $sql = "SELECT COALESCE(SUM(amount), 0) as current_spent 
                            FROM expenses 
                            WHERE user_id = ? AND category = ? 
                            AND date BETWEEN (SELECT start_date FROM budgets WHERE id = ?) 
                            AND (SELECT end_date FROM budgets WHERE id = ?)";
                    
                    if($stmt = mysqli_prepare($conn, $sql)) {
                        mysqli_stmt_bind_param($stmt, "isii", $_SESSION["id"], $original_category, $budget_info['id'], $budget_info['id']);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        if($row = mysqli_fetch_assoc($result)) {
                            $spent = $row['current_spent'];
                            $budget = $budget_info['budget_amount'];
                            $remaining = $budget - $spent;
                            
                            $budget_changes[] = [
                                'category' => $original_category,
                                'icon' => $budget_info['icon'],
                                'remaining' => $remaining,
                                'budget' => $budget
                            ];
                        }
                    }
                }
                
                // Check if new category has a budget and is different from original
                if(isset($active_budgets[$category]) && $category != $original_category) {
                    $budget_info = $active_budgets[$category];
                    
                    // Calculate current spending for the new budget
                    $sql = "SELECT COALESCE(SUM(amount), 0) as current_spent 
                            FROM expenses 
                            WHERE user_id = ? AND category = ? 
                            AND date BETWEEN (SELECT start_date FROM budgets WHERE id = ?) 
                            AND (SELECT end_date FROM budgets WHERE id = ?)";
                    
                    if($stmt = mysqli_prepare($conn, $sql)) {
                        mysqli_stmt_bind_param($stmt, "isii", $_SESSION["id"], $category, $budget_info['id'], $budget_info['id']);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        if($row = mysqli_fetch_assoc($result)) {
                            $spent = $row['current_spent'];
                            $budget = $budget_info['budget_amount'];
                            $remaining = $budget - $spent;
                            
                            $budget_changes[] = [
                                'category' => $category,
                                'icon' => $budget_info['icon'],
                                'remaining' => $remaining,
                                'budget' => $budget
                            ];
                        }
                    }
                }
                
                // Create budget message if there are changes
                if(!empty($budget_changes)) {
                    $budget_message = "Budget Updates:<ul>";
                    foreach($budget_changes as $change) {
                        $status_class = $change['remaining'] < 0 ? 'warning' : 'info';
                        $budget_message .= "<li>" . $change['icon'] . " " . $change['category'] . ": ";
                        
                        if($change['remaining'] < 0) {
                            $budget_message .= "You've exceeded your budget by " . formatIDR(abs($change['remaining']));
                        } else {
                            $budget_message .= formatIDR($change['remaining']) . " remaining of " . formatIDR($change['budget']);
                        }
                        
                        $budget_message .= "</li>";
                    }
                    $budget_message .= "</ul>";
                }
            }
            
            // If everything is OK, commit the transaction
            if($success){
                mysqli_commit($conn);
                $success_message = "Transaction updated successfully!";
                
                // Redirect to transactions page if no budget message
                if(empty($budget_message)) {
                    header("location: transactions.php");
                    exit();
                }
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            // You can add error handling here if needed
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Transaction - finmanager</title>
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
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .btn-back {
            padding: 0.5rem 1rem;
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background-color: rgba(72, 187, 120, 0.1);
            border: 1px solid rgba(72, 187, 120, 0.3);
            color: var(--increase-color);
        }
        
        .alert-warning {
            background-color: rgba(236, 201, 75, 0.1);
            border: 1px solid rgba(236, 201, 75, 0.3);
            color: #b7791f;
        }
        
        .alert-info {
            background-color: rgba(90, 103, 216, 0.1);
            border: 1px solid rgba(90, 103, 216, 0.3);
            color: var(--primary-color);
        }
        
        .transaction-form {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
        }
        
        .form-section {
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid var(--border-color);
            font-size: 1rem;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(94, 114, 228, 0.1);
        }
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232d3748' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }
        
        .error-text {
            color: var(--decrease-color);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        .btn-submit {
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            font-size: 1rem;
            width: 100%;
        }
        
        .btn-submit:hover {
            background-color: #4c61d4;
        }
        
        .inline-form-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .btn-secondary {
            padding: 0.75rem 1.5rem;
            background-color: #f7fafc;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            font-size: 1rem;
            flex: 1;
            text-align: center;
            text-decoration: none;
        }
        
        .budget-tag {
            display: inline-block;
            background-color: rgba(90, 103, 216, 0.1);
            color: var(--primary-color);
            border-radius: 1rem;
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }
        
        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
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
                
                <a href="#" class="nav-item">
                    <i class="fas fa-thumbtack"></i>
                    <span>Pinned</span>
                </a>
                
                <a href="wallets.php" class="nav-item">
                    <i class="fas fa-wallet"></i>
                    <span>Wallets</span>
                </a>
                
                <a href="transactions.php" class="nav-item active">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transactions</span>
                </a>
                
                <a href="budgets.php" class="nav-item">
                    <i class="fas fa-chart-pie"></i>
                    <span>Budgets & Goals</span>
                </a>
                
                <a href="recurring.php" class="nav-item">
                    <i class="fas fa-redo"></i>
                    <span>Recurring</span>
                </a>
            </nav>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <h1>Edit Transaction</h1>
                <a href="transactions.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Transactions</span>
                </a>
            </div>
            
            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($budget_message)): ?>
            <div class="alert alert-info">
                <?php echo $budget_message; ?>
                <div class="action-buttons">
                    <a href="transactions.php" class="btn-secondary">View Transactions</a>
                    <a href="budgets.php" class="btn-secondary">View Budgets</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="transaction-form">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . "?id=" . $id . "&type=" . $type; ?>" method="post">
                    <div class="form-section">
                        <div class="form-section-title">Transaction Type</div>
                        <div class="transaction-type <?php echo $type === 'income' ? 'income-icon' : 'expense-icon'; ?>" style="font-weight: 500; color: <?php echo $type === 'income' ? 'var(--increase-color)' : 'var(--decrease-color)'; ?>;">
                            <i class="fas <?php echo $type === 'income' ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
                            <?php echo ucfirst($type); ?>
                        </div>
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                    </div>
                    
                    <div class="form-section">
                        <div class="form-section-title">Transaction Details</div>
                        <div class="inline-form-group">
                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" name="amount" class="form-input" value="<?php echo $amount; ?>" placeholder="0.00" required>
                                <?php if(!empty($amount_err)): ?>
                                    <div class="error-text"><?php echo $amount_err; ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-input" value="<?php echo empty($date) ? date('Y-m-d') : $date; ?>" required>
                                <?php if(!empty($date_err)): ?>
                                    <div class="error-text"><?php echo $date_err; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="inline-form-group">
                            <?php if($type == "expense"): ?>
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select" id="category-select" required>
                                    <option value="">Select a category</option>
                                    <?php foreach($expense_categories as $cat): ?>
                                        <option value="<?php echo $cat; ?>" <?php echo $category == $cat ? 'selected' : ''; ?> 
                                               <?php echo isset($active_budgets[$cat]) ? 'data-has-budget="true"' : ''; ?>>
                                            <?php echo $cat; ?>
                                            <?php if(isset($active_budgets[$cat])): ?> (Budgeted)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(!empty($category_err)): ?>
                                    <div class="error-text"><?php echo $category_err; ?></div>
                                <?php endif; ?>
                                
                                <div id="budget-info" class="budget-tag" style="display: none;"></div>
                            </div>
                            <?php else: ?>
                            <div class="form-group">
                                <label class="form-label">Source</label>
                                <select name="source" class="form-select" required>
                                    <option value="">Select a source</option>
                                    <?php foreach($income_sources as $source): ?>
                                        <option value="<?php echo $source; ?>" <?php echo $category == $source ? 'selected' : ''; ?>><?php echo $source; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(!empty($category_err)): ?>
                                    <div class="error-text"><?php echo $category_err; ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label class="form-label">Wallet</label>
                                <select name="wallet_id" class="form-select" required>
                                    <option value="">Select a wallet</option>
                                    <?php foreach($wallets as $wallet): ?>
                                        <option value="<?php echo $wallet["id"]; ?>" <?php echo $wallet_id == $wallet["id"] ? 'selected' : ''; ?>><?php echo htmlspecialchars($wallet["name"]); ?> (Rp <?php echo number_format($wallet["balance"], 0, ',', '.'); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if(!empty($wallet_err)): ?>
                                    <div class="error-text"><?php echo $wallet_err; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Description (Optional)</label>
                            <textarea name="description" class="form-textarea" rows="3"><?php echo $description; ?></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">Update Transaction</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Show budget information for budgeted categories
        const categorySelect = document.getElementById('category-select');
        const budgetInfo = document.getElementById('budget-info');
        
        // Budgeted categories data
        const budgetedCategories = {
            <?php foreach($active_budgets as $cat => $budget): ?>
            "<?php echo $cat; ?>": {
                id: <?php echo $budget['id']; ?>,
                amount: <?php echo $budget['budget_amount']; ?>,
                icon: "<?php echo $budget['icon']; ?>"
            },
            <?php endforeach; ?>
        };
        
        function updateBudgetInfo() {
            if(!categorySelect) return;
            
            const selectedCategory = categorySelect.value;
            if(selectedCategory && budgetedCategories[selectedCategory]) {
                const budget = budgetedCategories[selectedCategory];
                budgetInfo.textContent = `${budget.icon} This expense will be tracked against your budget of Rp <?php echo number_format(1000, 0, ',', '.'); ?>`.replace('1,000', formatNumber(budget.amount));
                budgetInfo.style.display = 'inline-block';
            } else {
                budgetInfo.style.display = 'none';
            }
        }
        
        function formatNumber(number) {
            return new Intl.NumberFormat('id-ID').format(number);
        }
        
        // Initial setup
        document.addEventListener('DOMContentLoaded', function() {
            if(categorySelect) {
                updateBudgetInfo();
                categorySelect.addEventListener('change', updateBudgetInfo);
            }
        });
    </script>
</body>
</html>