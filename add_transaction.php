<?php
// add_transaction.php - Add new transaction page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Define variables and initialize with empty values
$type = $amount = $category = $description = $wallet_id = $date = "";
$type_err = $amount_err = $category_err = $wallet_err = $date_err = "";
$success_message = $budget_message = "";

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

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Validate transaction type
    if(empty(trim($_POST["type"]))){
        $type_err = "Please select a transaction type.";
    } else {
        $type = trim($_POST["type"]);
        if($type != "expense" && $type != "income"){
            $type_err = "Invalid transaction type.";
        }
    }
    
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
    
    // Check input errors before inserting in database
    if(empty($type_err) && empty($amount_err) && empty($category_err) && empty($wallet_err) && empty($date_err)){
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        $success = true;
        
        try {
            // Insert transaction based on type
            if($type == "expense"){
                $sql = "INSERT INTO expenses (user_id, wallet_id, amount, category, description, date) VALUES (?, ?, ?, ?, ?, ?)";
            } else {
                $sql = "INSERT INTO income (user_id, wallet_id, amount, source, description, date) VALUES (?, ?, ?, ?, ?, ?)";
            }
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "iidsss", $_SESSION["id"], $wallet_id, $amount, $category, $description, $date);
                
                if(!mysqli_stmt_execute($stmt)){
                    $success = false;
                    throw new Exception("Transaction could not be saved.");
                }
            } else {
                $success = false;
                throw new Exception("Something went wrong. Please try again later.");
            }
            
            // Update wallet balance
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
            
            // Check if this expense matches an active budget category
            if($type == "expense" && isset($active_budgets[$category])) {
                $budget_info = $active_budgets[$category];
                
                // Calculate current spending for this budget
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
                        
                        // Create budget message
                        if($remaining < 0) {
                            $budget_message = "Warning: You've exceeded your {$category} budget by Rp " . number_format(abs($remaining), 0, ',', '.');
                        } else {
                            $budget_message = "This expense has been tracked against your {$category} budget. Remaining: Rp " . number_format($remaining, 0, ',', '.');
                        }
                    }
                }
            }
            
            // If everything is OK, commit the transaction
            if($success){
                mysqli_commit($conn);
                $success_message = "Transaction added successfully!";
                
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

// Format currency for display
function formatIDR($amount) {
    return number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction - finmanager</title>
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
        
        .budget-suggestion {
            background-color: rgba(90, 103, 216, 0.05);
            border: 1px dashed rgba(90, 103, 216, 0.3);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
        }
        
        .budget-icon {
            margin-right: 1rem;
            font-size: 1.5rem;
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
        
        .transaction-types {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .transaction-type {
            flex: 1;
            padding: 1rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .transaction-type.active.expense {
            background-color: rgba(245, 101, 101, 0.1);
            border-color: var(--decrease-color);
            color: var(--decrease-color);
        }
        
        .transaction-type.active.income {
            background-color: rgba(72, 187, 120, 0.1);
            border-color: var(--increase-color);
            color: var(--increase-color);
        }
        
        .transaction-type-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--main-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .transaction-type-label {
            font-weight: 500;
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
        
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
        
        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
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
                <h1>Add Transaction</h1>
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
            <div class="alert <?php echo strpos($budget_message, 'Warning') !== false ? 'alert-warning' : 'alert-info'; ?>">
                <?php echo $budget_message; ?>
                <div class="action-buttons">
                    <a href="transactions.php" class="btn-secondary">View Transactions</a>
                    <a href="budgets.php" class="btn-secondary">View Budgets</a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if($type == "expense" && !empty($category) && !isset($active_budgets[$category]) && empty($budget_message)): ?>
            <div class="budget-suggestion">
                <div class="budget-icon"><i class="fas fa-lightbulb"></i></div>
                <div>
                    <strong>Budget Suggestion:</strong> You don't have a budget for "<?php echo htmlspecialchars($category); ?>". 
                    <a href="budgets.php?suggest=<?php echo urlencode($category); ?>">Create one now</a> to track your spending better.
                </div>
            </div>
            <?php endif; ?>

            <div class="transaction-form">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-section">
                        <div class="form-section-title">Transaction Type</div>
                        <div class="transaction-types">
                            <label class="transaction-type <?php echo $type == "expense" ? 'active expense' : ''; ?>" id="type-expense">
                                <input type="radio" name="type" value="expense" class="visually-hidden" <?php echo $type == "expense" ? 'checked' : ''; ?>>
                                <div class="transaction-type-icon expense-icon">
                                    <i class="fas fa-arrow-up"></i>
                                </div>
                                <div class="transaction-type-label">Expense</div>
                            </label>
                            
                            <label class="transaction-type <?php echo $type == "income" ? 'active income' : ''; ?>" id="type-income">
                                <input type="radio" name="type" value="income" class="visually-hidden" <?php echo $type == "income" ? 'checked' : ''; ?>>
                                <div class="transaction-type-icon income-icon">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                                <div class="transaction-type-label">Income</div>
                            </label>
                        </div>
                        <?php if(!empty($type_err)): ?>
                            <div class="error-text"><?php echo $type_err; ?></div>
                        <?php endif; ?>
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
                            <div class="form-group category-group">
                                <label class="form-label category-label">Category</label>
                                <select name="category" class="form-select category-select" id="category-select">
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
                            
                            <div class="form-group source-group" style="display: none;">
                                <label class="form-label">Source</label>
                                <select name="source" class="form-select">
                                    <option value="">Select a source</option>
                                    <?php foreach($income_sources as $source): ?>
                                        <option value="<?php echo $source; ?>" <?php echo $category == $source ? 'selected' : ''; ?>><?php echo $source; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
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
                    
                    <button type="submit" class="btn-submit">Save Transaction</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle between expense and income forms
        const typeExpense = document.getElementById('type-expense');
        const typeIncome = document.getElementById('type-income');
        const categoryGroup = document.querySelector('.category-group');
        const sourceGroup = document.querySelector('.source-group');
        const categoryLabel = document.querySelector('.category-label');
        const categorySelect = document.querySelector('.category-select');
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
        
        function updateTransactionType() {
            if (document.querySelector('input[name="type"]:checked').value === 'expense') {
                typeExpense.classList.add('active', 'expense');
                typeIncome.classList.remove('active', 'income');
                categoryGroup.style.display = 'block';
                sourceGroup.style.display = 'none';
                categoryLabel.textContent = 'Category';
                updateBudgetInfo();
            } else {
                typeIncome.classList.add('active', 'income');
                typeExpense.classList.remove('active', 'expense');
                categoryGroup.style.display = 'none';
                sourceGroup.style.display = 'block';
                budgetInfo.style.display = 'none';
            }
        }
        
        // Initial setup
        document.addEventListener('DOMContentLoaded', function() {
            // Set default type if none is selected
            if (!document.querySelector('input[name="type"]:checked')) {
                document.querySelector('input[name="type"][value="expense"]').checked = true;
            }
            updateTransactionType();
            
            // Add event listener for category select
            categorySelect.addEventListener('change', updateBudgetInfo);
        });
        
        // Event listeners
        typeExpense.addEventListener('click', function() {
            document.querySelector('input[name="type"][value="expense"]').checked = true;
            updateTransactionType();
        });
        
        typeIncome.addEventListener('click', function() {
            document.querySelector('input[name="type"][value="income"]').checked = true;
            updateTransactionType();
        });
    </script>
</body>
</html>