<?php
// transactions.php - Transactions list page
session_start();

// Check if the user is logged in, if not redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Define variables
$transactions = [];
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_period = isset($_GET['period']) ? $_GET['period'] : 'month';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;

// Determine date range based on filter
switch($filter_period) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'quarter':
        $month = date('n');
        $quarter = ceil($month / 3);
        $start_date = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 month'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'all':
        $start_date = '1970-01-01';
        $end_date = '2099-12-31';
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Base query parts
$select_expenses = "SELECT 'expense' as type, e.id, e.amount, e.category, e.description, e.date, w.name as wallet_name FROM expenses e JOIN wallets w ON e.wallet_id = w.id WHERE e.user_id = ? ";
$select_income = "SELECT 'income' as type, i.id, i.amount, i.source as category, i.description, i.date, w.name as wallet_name FROM income i JOIN wallets w ON i.wallet_id = w.id WHERE i.user_id = ? ";

// Add date range filter
$date_filter = "AND date BETWEEN ? AND ? ";

// Add search filter if provided
$search_filter = "";
if(!empty($search_term)) {
    $search_filter = "AND (description LIKE ? OR category LIKE ? OR source LIKE ?) ";
}

// Combine queries based on type filter
$params = [];
$types = "";

if($filter_type == 'all' || $filter_type == 'expense') {
    $query = $select_expenses . $date_filter;
    $types .= "iss";
    $params[] = $_SESSION["id"];
    $params[] = $start_date;
    $params[] = $end_date;
    
    if(!empty($search_term)) {
        $query .= $search_filter;
        $types .= "sss";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
}

if($filter_type == 'all') {
    $query .= " UNION ";
}

if($filter_type == 'all' || $filter_type == 'income') {
    $query2 = $select_income . $date_filter;
    $types .= "iss";
    $params[] = $_SESSION["id"];
    $params[] = $start_date;
    $params[] = $end_date;
    
    if(!empty($search_term)) {
        $query2 .= $search_filter;
        $types .= "sss";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if($filter_type == 'all') {
        $query .= $query2;
    } else {
        $query = $query2;
    }
}

// Add order and pagination
$query .= " ORDER BY date DESC, id DESC";

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM ($query) as combined_results";
$total_records = 0;

if($stmt = mysqli_prepare($conn, $count_query)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if($row = mysqli_fetch_assoc($result)) {
        $total_records = $row['total'];
    }
}

// Calculate pagination values
$total_pages = ceil($total_records / $per_page);
$offset = ($current_page - 1) * $per_page;

// Add limit to the main query
$query .= " LIMIT ?, ?";
$types .= "ii";
$params[] = $offset;
$params[] = $per_page;

// Execute the final query
if($stmt = mysqli_prepare($conn, $query)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while($row = mysqli_fetch_assoc($result)) {
        $transactions[] = $row;
    }
}

mysqli_close($conn);

// Function to format IDR currency
function formatIDR($amount) {
    return number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - finmanager</title>
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
        
        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .transaction-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .filter-option {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 0.9rem;
        }
        
        .filter-option.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .search-bar {
            flex-grow: 1;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            box-shadow: var(--shadow);
        }
        
        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-text);
        }
        
        .btn-add-transaction {
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
        
        .transaction-list {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .transaction-item {
            display: flex;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            align-items: center;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        .income-icon {
            color: var(--increase-color);
        }
        
        .expense-icon {
            color: var(--decrease-color);
        }
        
        .transaction-details {
            flex-grow: 1;
        }
        
        .transaction-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .transaction-subtitle {
            color: var(--secondary-text);
            font-size: 0.85rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .transaction-amount {
            font-weight: 600;
            text-align: right;
        }
        
        .amount-income {
            color: var(--increase-color);
        }
        
        .amount-expense {
            color: var(--decrease-color);
        }
        
        .transaction-date {
            color: var(--secondary-text);
            font-size: 0.85rem;
            text-align: right;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .page-item {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            font-size: 0.9rem;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .page-item.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--secondary-text);
        }
        
        .empty-state p {
            margin-bottom: 1rem;
        }
        
        h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .transaction-amount-container {
            text-align: right;
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
            <div class="transactions-header">
                <h1>Transactions</h1>
                <div class="transaction-actions">
                    <a href="add_transaction.php" class="btn-add-transaction">
                        <i class="fas fa-plus"></i>
                        <span>Add Transaction</span>
                    </a>
                    <?php if(count($transactions) > 0): ?>
                    <a href="edit_transaction.php" class="btn-add-transaction" id="editTransactionBtn" style="background-color: #4a5568;">
                        <i class="fas fa-edit"></i>
                        <span>Edit Transaction</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filter-bar">
                <div class="filter-group">
                    <a href="?type=all&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
                    <a href="?type=expense&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_type === 'expense' ? 'active' : ''; ?>">Expenses</a>
                    <a href="?type=income&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_type === 'income' ? 'active' : ''; ?>">Income</a>
                </div>
                
                <div class="filter-group">
                    <a href="?type=<?php echo $filter_type; ?>&period=week&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_period === 'week' ? 'active' : ''; ?>">Week</a>
                    <a href="?type=<?php echo $filter_type; ?>&period=month&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_period === 'month' ? 'active' : ''; ?>">Month</a>
                    <a href="?type=<?php echo $filter_type; ?>&period=quarter&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_period === 'quarter' ? 'active' : ''; ?>">Quarter</a>
                    <a href="?type=<?php echo $filter_type; ?>&period=year&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_period === 'year' ? 'active' : ''; ?>">Year</a>
                    <a href="?type=<?php echo $filter_type; ?>&period=all&search=<?php echo urlencode($search_term); ?>" class="filter-option <?php echo $filter_period === 'all' ? 'active' : ''; ?>">All Time</a>
                </div>
                
                <div class="search-bar">
                    <i class="fas fa-search search-icon"></i>
                    <form action="" method="GET">
                        <input type="hidden" name="type" value="<?php echo $filter_type; ?>">
                        <input type="hidden" name="period" value="<?php echo $filter_period; ?>">
                        <input type="text" name="search" class="search-input" placeholder="Search transactions..." value="<?php echo htmlspecialchars($search_term); ?>">
                    </form>
                </div>
            </div>
            
            <div class="transaction-list">
                <?php if(count($transactions) > 0): ?>
                    <?php foreach($transactions as $transaction): ?>
                        <div class="transaction-item" data-id="<?php echo $transaction['id']; ?>" data-type="<?php echo $transaction['type']; ?>">
                            <div class="transaction-icon <?php echo $transaction['type'] === 'income' ? 'income-icon' : 'expense-icon'; ?>">
                                <i class="fas <?php echo $transaction['type'] === 'income' ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
                            </div>
                            <div class="transaction-details">
                                <div class="transaction-title"><?php echo htmlspecialchars($transaction['description'] ?: 'No description'); ?></div>
                                <div class="transaction-subtitle">
                                    <span><?php echo htmlspecialchars($transaction['category']); ?></span>
                                    <span>&bull;</span>
                                    <span><?php echo htmlspecialchars($transaction['wallet_name']); ?></span>
                                </div>
                            </div>
                            <div class="transaction-amount-container">
                                <div class="transaction-amount <?php echo $transaction['type'] === 'income' ? 'amount-income' : 'amount-expense'; ?>">
                                    <?php echo $transaction['type'] === 'income' ? '+' : '-'; ?>Rp <?php echo formatIDR($transaction['amount']); ?>
                                </div>
                                <div class="transaction-date"><?php echo date('d M Y', strtotime($transaction['date'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No transactions found</p>
                        <a href="add_transaction.php" class="btn-add-transaction">Add your first transaction</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <a href="?type=<?php echo $filter_type; ?>&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo max(1, $current_page - 1); ?>" class="page-item <?php echo $current_page === 1 ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                
                <?php for($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                    <a href="?type=<?php echo $filter_type; ?>&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>" class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <a href="?type=<?php echo $filter_type; ?>&period=<?php echo $filter_period; ?>&search=<?php echo urlencode($search_term); ?>&page=<?php echo min($total_pages, $current_page + 1); ?>" class="page-item <?php echo $current_page === $total_pages ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Make transactions clickable for editing
        document.addEventListener('DOMContentLoaded', function() {
            // Select all transaction items
            const transactionItems = document.querySelectorAll('.transaction-item');
            const editBtn = document.getElementById('editTransactionBtn');
            
            // Add click event listener to each transaction
            transactionItems.forEach(item => {
                item.addEventListener('click', function() {
                    // Remove selected class from all items
                    transactionItems.forEach(el => el.classList.remove('selected'));
                    
                    // Add selected class to clicked item
                    this.classList.add('selected');
                    
                    // Get transaction data
                    const id = this.getAttribute('data-id');
                    const type = this.getAttribute('data-type');
                    
                    // Update edit button href
                    if (editBtn) {
                        editBtn.href = `edit_transaction.php?id=${id}&type=${type}`;
                    }
                });
            });
            
            // Style for selected items
            const style = document.createElement('style');
            style.textContent = `
                .transaction-item {
                    cursor: pointer;
                    transition: background-color 0.2s;
                }
                .transaction-item:hover {
                    background-color: rgba(0, 0, 0, 0.02);
                }
                .transaction-item.selected {
                    background-color: rgba(94, 114, 228, 0.05);
                    border-left: 3px solid var(--primary-color);
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>