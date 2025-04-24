<?php
// budgets.php - Budget management page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Process form submission to add/edit budget
$budget_id = $category = $amount = $icon = $start_date = $end_date = "";
$budget_err = "";
$success_message = "";

// Check if there's a suggestion from transaction page
$suggested_category = isset($_GET['suggest']) ? $_GET['suggest'] : '';

if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Check if we're editing an existing budget
    if(!empty($_POST["budget_id"])){
        $budget_id = $_POST["budget_id"];
    }
    
    // Validate category
    if(empty(trim($_POST["category"]))){
        $budget_err = "Please enter a category name.";
    } else{
        $category = trim($_POST["category"]);
    }
    
    // Validate amount
    if(empty(trim($_POST["amount"]))){
        $budget_err = "Please enter a budget amount.";
    } elseif(!is_numeric(trim($_POST["amount"])) || floatval(trim($_POST["amount"])) <= 0){
        $budget_err = "Please enter a valid positive amount.";
    } else{
        $amount = floatval(trim($_POST["amount"]));
    }
    
    // Validate icon
    if(empty(trim($_POST["icon"]))){
        $icon = "📊"; // Default icon
    } else{
        $icon = trim($_POST["icon"]);
    }
    
    // Validate dates
    if(empty(trim($_POST["start_date"]))){
        $budget_err = "Please enter a start date.";
    } else{
        $start_date = trim($_POST["start_date"]);
    }
    
    if(empty(trim($_POST["end_date"]))){
        $budget_err = "Please enter an end date.";
    } else{
        $end_date = trim($_POST["end_date"]);
        
        // Check if end date is after start date
        if(strtotime($end_date) < strtotime($start_date)){
            $budget_err = "End date must be after start date.";
        }
    }
    
    // If no errors, proceed with saving
    if(empty($budget_err)){
        if(empty($budget_id)){
            // Insert new budget
            $sql = "INSERT INTO budgets (user_id, category, amount, icon, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)";
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "isdsss", $_SESSION["id"], $category, $amount, $icon, $start_date, $end_date);
                
                if(mysqli_stmt_execute($stmt)){
                    $success_message = "Budget successfully added!";
                    
                    // Check if there are any recent expenses in this category
                    $last_budget_id = mysqli_insert_id($conn);
                    $sql = "SELECT COUNT(*) as count FROM expenses 
                            WHERE user_id = ? AND category = ? 
                            AND date BETWEEN ? AND ?";
                    
                    if($stmt2 = mysqli_prepare($conn, $sql)){
                        mysqli_stmt_bind_param($stmt2, "isss", $_SESSION["id"], $category, $start_date, $end_date);
                        
                        if(mysqli_stmt_execute($stmt2)){
                            $result = mysqli_stmt_get_result($stmt2);
                            if($row = mysqli_fetch_assoc($result)){
                                if($row['count'] > 0){
                                    $success_message .= " We found existing expenses in this category. They're now being tracked against your new budget.";
                                }
                            }
                        }
                        
                        mysqli_stmt_close($stmt2);
                    }
                } else{
                    $budget_err = "Something went wrong. Please try again later.";
                }
                
                mysqli_stmt_close($stmt);
            }
        } else {
            // Update existing budget
            $sql = "UPDATE budgets SET category = ?, amount = ?, icon = ?, start_date = ?, end_date = ? WHERE id = ? AND user_id = ?";
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "sdsssis", $category, $amount, $icon, $start_date, $end_date, $budget_id, $_SESSION["id"]);
                
                if(mysqli_stmt_execute($stmt)){
                    $success_message = "Budget successfully updated!";
                } else{
                    $budget_err = "Something went wrong. Please try again later.";
                }
                
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Handle delete request
if(isset($_GET["delete"]) && !empty($_GET["delete"])){
    $delete_id = $_GET["delete"];
    
    $sql = "DELETE FROM budgets WHERE id = ? AND user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $delete_id, $_SESSION["id"]);
        
        if(mysqli_stmt_execute($stmt)){
            header("location: budgets.php?deleted=1");
            exit();
        } else{
            $budget_err = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Handle edit request
if(isset($_GET["edit"]) && !empty($_GET["edit"])){
    $edit_id = $_GET["edit"];
    
    $sql = "SELECT * FROM budgets WHERE id = ? AND user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $edit_id, $_SESSION["id"]);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            
            if(mysqli_num_rows($result) == 1){
                $row = mysqli_fetch_array($result);
                
                $budget_id = $row["id"];
                $category = $row["category"];
                $amount = $row["amount"];
                $icon = $row["icon"];
                $start_date = $row["start_date"];
                $end_date = $row["end_date"];
            } else{
                // Redirect if budget not found
                header("location: budgets.php");
                exit();
            }
        } else{
            $budget_err = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Fetch expense categories without budgets for suggestions
$unbudgeted_expense_categories = [];
$sql = "SELECT DISTINCT category FROM expenses 
        WHERE user_id = ? AND category NOT IN 
        (SELECT category FROM budgets WHERE user_id = ? AND CURDATE() BETWEEN start_date AND end_date)
        ORDER BY category";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION["id"], $_SESSION["id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $unbudgeted_expense_categories[] = $row['category'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Fetch active budgets with spending data
$sql = "SELECT b.id, b.category, b.amount as budget_amount, b.icon, b.start_date, b.end_date, 
        COALESCE(SUM(e.amount), 0) as spent_amount 
        FROM budgets b 
        LEFT JOIN expenses e ON b.category = e.category AND e.date BETWEEN b.start_date AND b.end_date AND e.user_id = b.user_id 
        WHERE b.user_id = ? AND CURDATE() BETWEEN b.start_date AND b.end_date
        GROUP BY b.id, b.category, b.amount, b.icon, b.start_date, b.end_date
        ORDER BY b.end_date DESC";

$active_budgets = [];

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_array($result)){
        $active_budgets[] = $row;
    }
}

// Fetch past budgets
$sql = "SELECT b.id, b.category, b.amount as budget_amount, b.icon, b.start_date, b.end_date, 
        COALESCE(SUM(e.amount), 0) as spent_amount 
        FROM budgets b 
        LEFT JOIN expenses e ON b.category = e.category AND e.date BETWEEN b.start_date AND b.end_date AND e.user_id = b.user_id 
        WHERE b.user_id = ? AND CURDATE() > b.end_date
        GROUP BY b.id, b.category, b.amount, b.icon, b.start_date, b.end_date
        ORDER BY b.end_date DESC
        LIMIT 5";

$past_budgets = [];

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_array($result)){
        $past_budgets[] = $row;
    }
}

// Get spending data for suggested budget amount
$suggested_budget_amount = 0;
if(!empty($suggested_category)) {
    $sql = "SELECT AVG(amount) as avg_amount FROM expenses 
            WHERE user_id = ? AND category = ? 
            AND date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
            
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "is", $_SESSION["id"], $suggested_category);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                // Suggest monthly budget based on average spending
                $suggested_budget_amount = round($row['avg_amount'] * 1.1, -3); // Round to nearest thousand
                if($suggested_budget_amount < 100000) {
                    $suggested_budget_amount = 100000; // Minimum budget amount
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
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
    <title>Budgets & Goals - finmanager</title>
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
        
        .budgets-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .btn-add-budget {
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
        
        .budgets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .budget-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
        }
        
        .budget-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .budget-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .budget-icon {
            width: 32px;
            height: 32px;
            background-color: var(--main-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        
        .budget-name {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .budget-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .budget-action-btn {
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
        
        .budget-action-btn:hover {
            background-color: var(--main-bg);
        }
        
        .budget-amount {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        
        .budget-progress-container {
            margin-bottom: 0.75rem;
        }
        
        .budget-progress-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .budget-spent {
            color: var(--secondary-text);
        }
        
        .budget-remaining {
            font-weight: 500;
        }
        
        .progress-bar {
            height: 8px;
            background-color: var(--progress-bg);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            border-radius: 4px;
            background-color: var(--primary-color);
        }
        
        .budget-dates {
            color: var(--secondary-text);
            font-size: 0.85rem;
            margin-top: auto;
            padding-top: 1rem;
        }
        
        .past-budgets-section {
            margin-top: 3rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-color);
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
        
        .icon-selector {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .icon-option {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            background-color: var(--main-bg);
            font-size: 1.1rem;
        }
        
        .icon-option.selected {
            background-color: var(--primary-color);
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
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
        
        /* Progress colors based on percentage */
        .progress-low {
            background-color: var(--increase-color);
        }
        
        .progress-medium {
            background-color: #ecc94b;
        }
        
        .progress-high {
            background-color: var(--decrease-color);
        }
        
        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        /* Budget suggestions styles */
        .suggestions-container {
            margin-bottom: 2rem;
        }
        
        .suggestion-card {
            background-color: rgba(94, 114, 228, 0.05);
            border: 1px dashed rgba(94, 114, 228, 0.3);
            border-radius: var(--border-radius);
            padding: 1rem;
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .suggestion-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(94, 114, 228, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-right: 1rem;
        }
        
        .suggestion-content {
            flex-grow: 1;
        }
        
        .suggestion-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .suggestion-description {
            color: var(--secondary-text);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .suggestion-action {
            margin-left: 1rem;
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
                <a href="index.php" class="nav-item active">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                
                <!-- <a href="#" class="nav-item">
                    <i class="fas fa-thumbtack"></i>
                    <span>Pinned</span>
                </a> -->
                
                <a href="wallets.php" class="nav-item">
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
            <div class="budgets-header">
                <h1>Budgets & Goals</h1>
                <button class="btn-add-budget" id="openModalBtn">
                    <i class="fas fa-plus"></i>
                    <span>Add Budget</span>
                </button>
            </div>
            
            <?php if(isset($_GET["deleted"]) && $_GET["deleted"] == 1): ?>
            <div class="alert alert-success">Budget successfully deleted!</div>
            <?php endif; ?>
            
            <?php if(!empty($budget_err)): ?>
            <div class="alert alert-danger"><?php echo $budget_err; ?></div>
            <?php endif; ?>
            
            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if(!empty($unbudgeted_expense_categories)): ?>
            <div class="suggestions-container">
                <h2 class="section-title">Budget Suggestions</h2>
                
                <?php foreach($unbudgeted_expense_categories as $category): ?>
                <div class="suggestion-card">
                    <div class="suggestion-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <div class="suggestion-content">
                        <div class="suggestion-title">Create a budget for "<?php echo htmlspecialchars($category); ?>"</div>
                        <div class="suggestion-description">
                            You have expenses in this category but no budget to track them. Creating a budget will help you manage your spending better.
                        </div>
                    </div>
                    <div class="suggestion-action">
                        <button class="btn-add-budget" onclick="suggestBudget('<?php echo htmlspecialchars($category); ?>')">
                            <i class="fas fa-plus"></i>
                            <span>Add</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <h2 class="section-title">Active Budgets</h2>
            
            <?php if(count($active_budgets) > 0): ?>
            <div class="budgets-grid">
                <?php foreach($active_budgets as $budget): 
                    $spent = $budget['spent_amount'];
                    $total = $budget['budget_amount'];
                    $remaining = $total - $spent;
                    $percentage = min(100, ($spent / $total) * 100); // Cap at 100%
                    
                    // Determine progress color class
                    $progress_class = 'progress-low';
                    if($percentage >= 70 && $percentage < 90) {
                        $progress_class = 'progress-medium';
                    } else if($percentage >= 90) {
                        $progress_class = 'progress-high';
                    }
                ?>
                <div class="budget-card">
                    <div class="budget-card-header">
                        <div class="budget-title">
                            <div class="budget-icon"><?php echo htmlspecialchars($budget['icon']); ?></div>
                            <div class="budget-name"><?php echo htmlspecialchars($budget['category']); ?></div>
                        </div>
                        <div class="budget-actions">
                            <a href="?edit=<?php echo $budget['id']; ?>" class="budget-action-btn">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?delete=<?php echo $budget['id']; ?>" class="budget-action-btn" onclick="return confirm('Are you sure you want to delete this budget?');">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="budget-amount">Rp <?php echo formatIDR($total); ?></div>
                    
                    <div class="budget-progress-container">
                        <div class="budget-progress-info">
                            <div class="budget-spent">Spent: Rp <?php echo formatIDR($spent); ?></div>
                            <div class="budget-remaining">Rp <?php echo formatIDR($remaining); ?> left</div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress <?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="budget-dates">
                        <?php echo date('M d, Y', strtotime($budget['start_date'])); ?> - <?php echo date('M d, Y', strtotime($budget['end_date'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <p>You don't have any active budgets</p>
                <button class="btn-add-budget" id="openModalBtn2">Create your first budget</button>
            </div>
            <?php endif; ?>
            
            <?php if(count($past_budgets) > 0): ?>
            <div class="past-budgets-section">
                <h2 class="section-title">Past Budgets</h2>
                <div class="budgets-grid">
                    <?php foreach($past_budgets as $budget): 
                        $spent = $budget['spent_amount'];
                        $total = $budget['budget_amount'];
                        $percentage = min(100, ($spent / $total) * 100); // Cap at 100%
                        
                        // Determine progress color class
                        $progress_class = 'progress-low';
                        if($percentage >= 70 && $percentage < 90) {
                            $progress_class = 'progress-medium';
                        } else if($percentage >= 90) {
                            $progress_class = 'progress-high';
                        }
                    ?>
                    <div class="budget-card">
                        <div class="budget-card-header">
                            <div class="budget-title">
                                <div class="budget-icon"><?php echo htmlspecialchars($budget['icon']); ?></div>
                                <div class="budget-name"><?php echo htmlspecialchars($budget['category']); ?></div>
                            </div>
                        </div>
                        
                        <div class="budget-amount">Rp <?php echo formatIDR($total); ?></div>
                        
                        <div class="budget-progress-container">
                            <div class="budget-progress-info">
                                <div class="budget-spent">Spent: Rp <?php echo formatIDR($spent); ?></div>
                                <div class="budget-remaining"><?php echo number_format($percentage, 1); ?>%</div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress <?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="budget-dates">
                            <?php echo date('M d, Y', strtotime($budget['start_date'])); ?> - <?php echo date('M d, Y', strtotime($budget['end_date'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Budget Modal -->
    <div class="modal <?php echo (!empty($budget_err) || !empty($budget_id) || !empty($suggested_category)) ? 'active' : ''; ?>" id="budgetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <?php 
                    if(!empty($budget_id)) {
                        echo 'Edit Budget';
                    } elseif(!empty($suggested_category)) {
                        echo 'Create Budget for ' . htmlspecialchars($suggested_category);
                    } else {
                        echo 'Add New Budget';
                    }
                    ?>
                </h2>
                <button class="close-modal" id="closeModalBtn">&times;</button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="budget_id" value="<?php echo $budget_id; ?>">
                
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" value="<?php echo empty($category) && !empty($suggested_category) ? $suggested_category : $category; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" class="form-control" min="0" step="0.01" value="<?php echo empty($amount) && !empty($suggested_budget_amount) ? $suggested_budget_amount : $amount; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Icon</label>
                    <input type="text" name="icon" id="selectedIcon" class="form-control" value="<?php echo empty($icon) ? '📊' : $icon; ?>" readonly>
                    <div class="icon-selector">
                        <div class="icon-option <?php echo $icon === '📊' || empty($icon) ? 'selected' : ''; ?>" data-icon="📊">📊</div>
                        <div class="icon-option <?php echo $icon === '🍎' ? 'selected' : ''; ?>" data-icon="🍎">🍎</div>
                        <div class="icon-option <?php echo $icon === '🍽️' ? 'selected' : ''; ?>" data-icon="🍽️">🍽️</div>
                        <div class="icon-option <?php echo $icon === '🏠' ? 'selected' : ''; ?>" data-icon="🏠">🏠</div>
                        <div class="icon-option <?php echo $icon === '🚗' ? 'selected' : ''; ?>" data-icon="🚗">🚗</div>
                        <div class="icon-option <?php echo $icon === '👔' ? 'selected' : ''; ?>" data-icon="👔">👔</div>
                        <div class="icon-option <?php echo $icon === '🎉' ? 'selected' : ''; ?>" data-icon="🎉">🎉</div>
                        <div class="icon-option <?php echo $icon === '💊' ? 'selected' : ''; ?>" data-icon="💊">💊</div>
                        <div class="icon-option <?php echo $icon === '📚' ? 'selected' : ''; ?>" data-icon="📚">📚</div>
                        <div class="icon-option <?php echo $icon === '✈️' ? 'selected' : ''; ?>" data-icon="✈️">✈️</div>
                        <div class="icon-option <?php echo $icon === '🏋️' ? 'selected' : ''; ?>" data-icon="🏋️">🏋️</div>
                        <div class="icon-option <?php echo $icon === '🎮' ? 'selected' : ''; ?>" data-icon="🎮">🎮</div>
                        <div class="icon-option <?php echo $icon === '🎭' ? 'selected' : ''; ?>" data-icon="🎭">🎭</div>
                        <div class="icon-option <?php echo $icon === '💰' ? 'selected' : ''; ?>" data-icon="💰">💰</div>
                        <div class="icon-option <?php echo $icon === '💳' ? 'selected' : ''; ?>" data-icon="💳">💳</div>
                        <div class="icon-option <?php echo $icon === '🧾' ? 'selected' : ''; ?>" data-icon="🧾">🧾</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo empty($start_date) ? date('Y-m-01') : $start_date; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo empty($end_date) ? date('Y-m-t') : $end_date; ?>" required>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="cancelBtn">Cancel</button>
                    <button type="submit" class="btn-save">Save Budget</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('budgetModal');
            const openModalBtn = document.getElementById('openModalBtn');
            const openModalBtn2 = document.getElementById('openModalBtn2');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const iconOptions = document.querySelectorAll('.icon-option');
            const selectedIconInput = document.getElementById('selectedIcon');
            
            // Open modal
            if(openModalBtn) {
                openModalBtn.addEventListener('click', function() {
                    modal.classList.add('active');
                });
            }
            
            if(openModalBtn2) {
                openModalBtn2.addEventListener('click', function() {
                    modal.classList.add('active');
                });
            }
            
            // Close modal
            if(closeModalBtn) {
                closeModalBtn.addEventListener('click', function() {
                    modal.classList.remove('active');
                });
            }
            
            if(cancelBtn) {
                cancelBtn.addEventListener('click', function() {
                    modal.classList.remove('active');
                });
            }
            
            // Select icon
            iconOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remove selected class from all options
                    iconOptions.forEach(opt => opt.classList.remove('selected'));
                    
                    // Add selected class to clicked option
                    this.classList.add('selected');
                    
                    // Update hidden input value
                    selectedIconInput.value = this.getAttribute('data-icon');
                });
            });
        });
        
        // Function to suggest budget
        function suggestBudget(category) {
            // Set category in modal and open it
            const modal = document.getElementById('budgetModal');
            const categoryInput = document.querySelector('input[name="category"]');
            if(categoryInput) {
                categoryInput.value = category;
            }
            
            modal.classList.add('active');
        }
        
        <?php if(!empty($suggested_category)): ?>
        // Auto-open modal with suggested category
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('budgetModal');
            if(modal) {
                modal.classList.add('active');
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>