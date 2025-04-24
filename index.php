<?php
// index.php - Main dashboard page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Fetch total expenses
$sql = "SELECT SUM(amount) as total_expense FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ?";
$start_date = date('Y-m-01'); // First day of current month
$end_date = date('Y-m-t'); // Last day of current month

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_array($result);
    $total_expense = $row['total_expense'] ?: 0;
}

// Fetch total income
$sql = "SELECT SUM(amount) as total_income FROM income WHERE user_id = ? AND date BETWEEN ? AND ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_array($result);
    $total_income = $row['total_income'] ?: 0;
}

// Fetch expense by category for pie chart
$sql = "SELECT category, SUM(amount) as category_total FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ? GROUP BY category";
$expense_categories = [];
$category_totals = [];

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_array($result)){
        $expense_categories[] = $row['category'];
        $category_totals[] = $row['category_total'];
    }
}

// Fetch budgets and their spent amounts
$sql = "SELECT b.id, b.category, b.amount as budget_amount, b.icon, COALESCE(SUM(e.amount), 0) as spent_amount 
        FROM budgets b 
        LEFT JOIN expenses e ON b.category = e.category AND e.date BETWEEN b.start_date AND b.end_date AND e.user_id = b.user_id 
        WHERE b.user_id = ? AND ? BETWEEN b.start_date AND b.end_date 
        GROUP BY b.id, b.category, b.amount, b.icon";

$budgets = [];

if($stmt = mysqli_prepare($conn, $sql)){
    $current_date = date('Y-m-d');
    mysqli_stmt_bind_param($stmt, "is", $_SESSION["id"], $current_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_array($result)){
        $budgets[] = $row;
    }
}

// Calculate month-over-month changes
$previous_month_start = date('Y-m-01', strtotime('-1 month'));
$previous_month_end = date('Y-m-t', strtotime('-1 month'));

// Previous month expense
$sql = "SELECT SUM(amount) as prev_expense FROM expenses WHERE user_id = ? AND date BETWEEN ? AND ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $previous_month_start, $previous_month_end);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_array($result);
    $prev_expense = $row['prev_expense'] ?: 0;
}

// Previous month income
$sql = "SELECT SUM(amount) as prev_income FROM income WHERE user_id = ? AND date BETWEEN ? AND ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $previous_month_start, $previous_month_end);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_array($result);
    $prev_income = $row['prev_income'] ?: 0;
}

// Calculate percentages
$expense_change_pct = ($prev_expense > 0) ? (($total_expense - $prev_expense) / $prev_expense) * 100 : 0;
$income_change_pct = ($prev_income > 0) ? (($total_income - $prev_income) / $prev_income) * 100 : 0;

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
    <title>Dashboard - finmanager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="./styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    .header {
        margin-bottom: 2rem;
    }

    .header h1 {
        font-size: 1.75rem;
        font-weight: 600;
    }

    /* Dashboard Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 1.5rem;
    }

    .card {
        background-color: var(--card-bg);
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        padding: 1.5rem;
    }

    .expense-card, .income-card {
        grid-column: span 4;
    }

    .expense-chart-card {
        grid-column: span 7;
    }

    .budgets-card {
        grid-column: span 5;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }

    .card-header h2 {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .date-range {
        font-size: 0.8rem;
        color: var(--secondary-text);
    }

    .card-actions {
        display: flex;
    }

    .card-actions button {
        background: none;
        border: none;
        cursor: pointer;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background-color 0.2s;
    }

    .card-actions button:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }

    .amount {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .change {
        font-size: 0.9rem;
        font-weight: 500;
        display: flex;
        align-items: center;
    }

    .change.increase {
        color: var(--increase-color);
    }

    .change.decrease {
        color: var(--decrease-color);
    }

    .change i {
        margin-left: 0.35rem;
        margin-right: 0.5rem;
    }

    .change span {
        color: var(--secondary-text);
        font-weight: normal;
    }

    /* Chart Container */
    .chart-container {
        position: relative;
        height: 240px;
        width: 100%;
    }

    .chart-center-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
    }

    .total-amount {
        font-size: 1.75rem;
        font-weight: 700;
    }

    .total-label {
        font-size: 0.9rem;
        color: var(--secondary-text);
    }

    /* Budget Styles */
    .budget-list {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .budget-item {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .budget-info {
        display: flex;
        align-items: center;
        margin-bottom: 0.25rem;
    }

    .budget-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background-color: #f7fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.75rem;
        font-size: 0.9rem;
    }

    .budget-name {
        font-weight: 500;
    }

    .budget-progress {
        display: flex;
        align-items: center;
    }

    .progress-bar {
        flex-grow: 1;
        height: 8px;
        background-color: var(--progress-bg);
        border-radius: 4px;
        margin-right: 0.75rem;
        overflow: hidden;
    }

    .progress {
        height: 100%;
        border-radius: 4px;
    }

    .grocery .progress {
        background-color: var(--progress-grocery);
    }

    .dining .progress {
        background-color: var(--progress-dining);
    }

    .entertainment .progress {
        background-color: var(--progress-entertainment);
    }

    .clothing .progress {
        background-color: var(--progress-clothing);
    }

    .budget-percentage {
        font-weight: 500;
        width: 50px;
        text-align: right;
    }

    .budget-remaining {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .small-text {
        font-size: 0.8rem;
        color: var(--secondary-text);
    }

    .remaining-amount {
        font-weight: 500;
    }

    /* Responsive Styles */
    @media (max-width: 1200px) {
        .dashboard-grid {
            grid-template-columns: repeat(1, 1fr);
        }
        
        .expense-card, .income-card, .budgets-card, .expense-chart-card {
            grid-column: span 1;
        }
    }

    @media (max-width: 768px) {
        .app-container {
            flex-direction: column;
        }
        
        .sidebar {
            width: 100%;
            height: auto;
            position: relative;
            padding: 1rem;
        }
        
        .main-content {
            margin-left: 0;
            padding: 1rem;
        }
        
        .logo-container {
            margin-bottom: 1rem;
        }
        
        .sidebar-nav {
            margin-top: 1rem;
        }
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
            <div class="header">
                <h1>Hello, <?php echo htmlspecialchars($_SESSION["username"]); ?> 👋</h1>
            </div>
            
            <div class="dashboard-grid">
                <div class="card expense-card">
                    <div class="card-header">
                        <h2>Total expense</h2>
                        <p class="date-range">From 1-<?php echo date('d/m/Y'); ?></p>
                    </div>
                    <div class="card-content">
                        <div class="amount">Rp <?php echo formatIDR($total_expense); ?></div>
                        <div class="change <?php echo ($expense_change_pct >= 0) ? 'increase' : 'decrease'; ?>">
                            <?php echo ($expense_change_pct >= 0) ? '+' : ''; ?><?php echo number_format($expense_change_pct, 1); ?>% 
                            <i class="fas <?php echo ($expense_change_pct >= 0) ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                            <span>vs last month</span>
                        </div>
                    </div>
                </div>
                
                <div class="card income-card">
                    <div class="card-header">
                        <h2>Total income</h2>
                        <p class="date-range">From 1-<?php echo date('d/m/Y'); ?></p>
                    </div>
                    <div class="card-content">
                        <div class="amount">Rp <?php echo formatIDR($total_income); ?></div>
                        <div class="change <?php echo ($income_change_pct >= 0) ? 'increase' : 'decrease'; ?>">
                            <?php echo ($income_change_pct >= 0) ? '+' : ''; ?><?php echo number_format($income_change_pct, 1); ?>% 
                            <i class="fas <?php echo ($income_change_pct >= 0) ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                            <span>vs last month</span>
                        </div>
                    </div>
                </div>
                
                <div class="card budgets-card">
                    <div class="card-header">
                        <h2>Budgets</h2>
                        <div class="card-actions">
                            <button class="expand-btn"><i class="fas fa-expand-alt"></i></button>
                            <button class="menu-btn"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="budget-list">
                            <?php foreach ($budgets as $budget): 
                                $spent = $budget['spent_amount'];
                                $total = $budget['budget_amount'];
                                $remaining = $total - $spent;
                                $percentage = ($spent / $total) * 100;
                                
                                // Determine the CSS class for the category icon
                                $category_class = strtolower(explode(' ', $budget['category'])[0]);
                            ?>
                            <div class="budget-item">
                                <div class="budget-info">
                                    <div class="budget-icon <?php echo $category_class; ?>"><?php echo $budget['icon']; ?></div>
                                    <div class="budget-name"><?php echo htmlspecialchars($budget['category']); ?></div>
                                </div>
                                <div class="budget-progress">
                                    <div class="progress-bar">
                                        <div class="progress" style="width: <?php echo number_format($percentage, 1); ?>%"></div>
                                    </div>
                                    <div class="budget-percentage"><?php echo number_format($percentage, 1); ?>%</div>
                                </div>
                                <div class="budget-remaining">
                                    <div class="small-text">Remaining</div>
                                    <div class="remaining-amount">Rp <?php echo formatIDR($remaining); ?>/Rp <?php echo formatIDR($total); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if(empty($budgets)): ?>
                            <div class="empty-state">
                                <p>No budgets set for this month</p>
                                <a href="budgets.php" class="btn-add-budget">Create Budget</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card expense-chart-card">
                    <div class="card-header">
                        <h2>Monthly expenses</h2>
                        <div class="card-actions">
                            <button class="expand-btn"><i class="fas fa-expand-alt"></i></button>
                            <button class="menu-btn"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="chart-container">
                            <canvas id="expenseChart"></canvas>
                            <div class="chart-center-text">
                                <div class="total-amount">Rp <?php echo formatIDR($total_expense); ?></div>
                                <div class="total-label">Total</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Setup doughnut chart data
    const expenseCtx = document.getElementById('expenseChart').getContext('2d');
    
    // Define chart data from PHP variables
    const expenseCategories = <?php echo json_encode($expense_categories ?: ['No Data']); ?>;
    const categoryTotals = <?php echo json_encode($category_totals ?: [1]); ?>;
    
    // Color scheme
    const colors = [
        'rgba(255, 159, 127, 0.8)',  // Peach
        'rgba(254, 219, 149, 0.8)',  // Light yellow
        'rgba(239, 171, 211, 0.8)',  // Pink
        'rgba(135, 212, 190, 0.8)',  // Teal
        'rgba(133, 193, 233, 0.8)',  // Light blue
        'rgba(195, 206, 254, 0.8)'   // Lavender
    ];
    
    // Create the chart
    const expenseChart = new Chart(expenseCtx, {
        type: 'doughnut',
        data: {
            labels: expenseCategories,
            datasets: [{
                data: categoryTotals,
                backgroundColor: colors,
                borderWidth: 0,
                borderRadius: 5
            }]
        },
        options: {
            cutout: '70%',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    borderColor: '#ddd',
                    borderWidth: 1,
                    cornerRadius: 10,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const total = context.chart.getDatasetMeta(0).total;
                            const percentage = Math.round((value / total) * 100);
                            return `Rp ${value.toLocaleString('id-ID')} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
    </script>
</body>
</html>