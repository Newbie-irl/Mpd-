<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may view this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

/**
 * Runs a COUNT/SUM-style scalar query and returns 0 instead of crashing if
 * the table doesn't exist yet (handy while orders/bookings are still being
 * built).
 */
function safeScalar(PDO $pdo, string $sql): float {
    try {
        return (float) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Runs a SELECT and returns an empty array instead of crashing if the
 * table/columns don't exist yet.
 */
function safeRows(PDO $pdo, string $sql): array {
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

$totalProducts   = (int) safeScalar($pdo, "SELECT COUNT(*) FROM products");
$totalOrders     = (int) safeScalar($pdo, "SELECT COUNT(*) FROM orders");
$totalDeliveries = (int) safeScalar($pdo, "SELECT COUNT(*) FROM deliveries");
$totalCustomers  = (int) safeScalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'customer'");

// ---- Revenue ----
$totalRevenue = safeScalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM orders");
$todayRevenue = safeScalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at) = CURDATE()");
$monthRevenue = safeScalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");

// ---- Delivery status breakdown ----
$deliveryStatusCounts = array_fill_keys(['pending', 'out_for_delivery', 'delivered', 'failed'], 0);
foreach (safeRows($pdo, "SELECT status, COUNT(*) AS c FROM deliveries GROUP BY status") as $row) {
    if (isset($deliveryStatusCounts[$row['status']])) {
        $deliveryStatusCounts[$row['status']] = (int) $row['c'];
    }
}

// ---- Products running low on stock (paginated) ----
$lowStockPerPage = 5;
$lowStockTotal   = (int) safeScalar($pdo, "SELECT COUNT(*) FROM products WHERE stock <= 5");
$lowStockPages   = max(1, (int) ceil($lowStockTotal / $lowStockPerPage));
$lowStockPage    = (int) ($_GET['lsp'] ?? 1);
$lowStockPage    = max(1, min($lowStockPage, $lowStockPages));
$lowStockOffset  = ($lowStockPage - 1) * $lowStockPerPage;
$lowStock = safeRows($pdo,
    "SELECT name, stock FROM products WHERE stock <= 5
     ORDER BY stock ASC LIMIT {$lowStockPerPage} OFFSET {$lowStockOffset}"
);

// ---- Most recent orders (paginated) ----
$recentOrdersPerPage = 5;
$recentOrdersTotal   = (int) safeScalar($pdo, "SELECT COUNT(*) FROM orders");
$recentOrdersPages   = max(1, (int) ceil($recentOrdersTotal / $recentOrdersPerPage));
$recentOrdersPage    = (int) ($_GET['rop'] ?? 1);
$recentOrdersPage    = max(1, min($recentOrdersPage, $recentOrdersPages));
$recentOrdersOffset  = ($recentOrdersPage - 1) * $recentOrdersPerPage;
$recentOrders = safeRows($pdo,
    "SELECT o.id, o.total_amount, o.status, o.created_at, u.full_name
     FROM orders o
     JOIN users u ON o.user_id = u.id
     ORDER BY o.created_at DESC
     LIMIT {$recentOrdersPerPage} OFFSET {$recentOrdersOffset}"
);

// ---- Sales trend: last 7 days (including days with zero orders) ----
$salesRows = safeRows($pdo,
    "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS t
     FROM orders
     WHERE created_at >= (CURDATE() - INTERVAL 6 DAY)
     GROUP BY DATE(created_at)
     ORDER BY d ASC"
);
$salesByDate = [];
foreach ($salesRows as $row) {
    $salesByDate[$row['d']] = (float) $row['t'];
}
$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} day"));
    $last7Days[] = ['date' => $date, 'total' => $salesByDate[$date] ?? 0.0];
}
$maxDayTotal = max(1, max(array_column($last7Days, 'total')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MPD Electrical Supply &amp; Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Dashboard-specific styles are embedded here directly (same
      approach used on deliveries.php) so this page always renders correctly
      even if style.css is out of date or served from a stale cache.
    -->
    <style>
        :root {
            --color-emerald-500: #10b981;
            --color-emerald-600: #059669;
            --color-rose-500: #f43f5e;
            --color-rose-600: #e11d48;
            --color-sky-500: #0ea5e9;
            --color-sky-600: #0284c7;
            --font-mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 1.125rem;
            line-height: 1;
            vertical-align: middle;
        }

        .dash-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem;
        }
        .dash-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .dash-header-bento .field-hint { color: #64748b; font-size: 0.8rem; }
        .dh-user { display: flex; align-items: center; gap: 0.6rem; background-color: #f1f5f9; padding: 0.4rem 0.9rem 0.4rem 0.4rem; border-radius: 9999px; }
        .dh-avatar { width: 2rem; height: 2rem; border-radius: 9999px; background-color: #fbbf24; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .dh-user-name { font-size: 0.8rem; font-weight: 600; color: #0f172a; line-height: 1.1rem; }
        .dh-user-role { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        /* Revenue highlight banner */
        .revenue-banner {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 1rem; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }
        .revenue-block { display: flex; flex-direction: column; gap: 0.15rem; }
        .revenue-block + .revenue-block { border-left: 1px solid rgba(255,255,255,0.12); padding-left: 1rem; }
        .revenue-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; display: flex; align-items: center; gap: 0.35rem; }
        .revenue-label .material-symbols-outlined { font-size: 1rem; color: #fbbf24; }
        .revenue-value { font-size: 1.6rem; font-weight: 700; color: #ffffff; font-family: var(--font-mono); }
        .revenue-value.accent { color: #fbbf24; }

        /* Stat cards (clickable) */
        .stats-grid-v2 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        a.stat-card-v2 { text-decoration: none; }
        .stat-card-v2 {
            background-color: #ffffff; border-radius: 1rem; padding: 1.1rem 1.25rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: flex; flex-direction: column;
            gap: 0.35rem; transition: box-shadow 0.15s ease-in-out, transform 0.15s ease-in-out;
        }
        a.stat-card-v2:hover .stat-card-v2 { box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); transform: translateY(-2px); }
        a.stat-card-v2:hover { display: block; }
        .stat-card-v2-top { display: flex; align-items: center; justify-content: space-between; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .stat-card-v2-top .material-symbols-outlined { color: #f59e0b; font-size: 1.1rem; }
        .stat-card-v2-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 2rem; }
        .stat-card-v2-sub { font-size: 0.75rem; color: #64748b; }
        .stat-card-v2-link { font-size: 0.7rem; color: #f59e0b; font-weight: 600; display: flex; align-items: center; gap: 0.1rem; }

        /* Two-column panels */
        .admin-panels-v2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; margin-bottom: 1.25rem; }
        .admin-panel-v2 { background-color: #ffffff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
        .admin-panel-v2-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .admin-panel-v2-head h2 { font-size: 1.05rem; color: #0f172a; }
        .panel-view-all { font-size: 0.75rem; font-weight: 600; color: #f59e0b; }

        .low-stock-row { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0.75rem; border-radius: 0.75rem; background-color: #fff7ed; border: 1px solid #fde68a; margin-bottom: 0.5rem; }
        .low-stock-row:last-child { margin-bottom: 0; }
        .low-stock-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #78350f; font-size: 0.85rem; }
        .low-stock-name .material-symbols-outlined { color: #f59e0b; font-size: 1.1rem; }
        .low-stock-badge { background-color: #f59e0b; color: #451a03; font-weight: 700; font-size: 0.7rem; padding: 0.2rem 0.6rem; border-radius: 9999px; }

        .low-stock-pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid #f1f5f9; }
        .low-stock-pagination .page-info { font-size: 0.72rem; color: #64748b; font-weight: 600; }
        .low-stock-pagination .page-nav { display: flex; gap: 0.4rem; }
        .low-stock-pagination a.page-btn, .low-stock-pagination span.page-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 1.8rem; height: 1.8rem; border-radius: 9999px;
            background-color: #f1f5f9; color: #334155; text-decoration: none;
        }
        .low-stock-pagination a.page-btn:hover { background-color: #f59e0b; color: #451a03; }
        .low-stock-pagination span.page-btn.disabled { opacity: 0.4; pointer-events: none; }
        .low-stock-pagination .material-symbols-outlined { font-size: 1rem; }

        /* Delivery status mini breakdown + sales trend */
        .widgets-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 1.25rem; }
        .delivery-mini { display: flex; flex-direction: column; gap: 0.6rem; }
        .delivery-mini-row { display: flex; align-items: center; justify-content: space-between; padding: 0.55rem 0.8rem; border-radius: 0.75rem; background-color: #f8fafc; }
        .delivery-mini-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; font-weight: 600; color: #334155; }
        .delivery-mini-dot { width: 8px; height: 8px; border-radius: 9999px; }
        .delivery-mini-dot.pending { background-color: #f59e0b; }
        .delivery-mini-dot.out_for_delivery { background-color: var(--color-sky-500); }
        .delivery-mini-dot.delivered { background-color: var(--color-emerald-500); }
        .delivery-mini-dot.failed { background-color: var(--color-rose-500); }
        .delivery-mini-count { font-family: var(--font-mono); font-weight: 700; color: #0f172a; }

        .sales-chart-v2 { display: flex; align-items: flex-end; gap: 0.6rem; height: 160px; padding-top: 0.5rem; }
        .sales-chart-v2-col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 0.4rem; }
        .sales-chart-v2-bar-track { width: 100%; max-width: 34px; height: 100%; display: flex; align-items: flex-end; }
        .sales-chart-v2-bar { width: 100%; border-radius: 0.4rem 0.4rem 0 0; background-color: #fbbf24; transition: opacity 0.15s ease-in-out; min-height: 3px; }
        .sales-chart-v2-col:hover .sales-chart-v2-bar { opacity: 0.75; }
        .sales-chart-v2-day { font-size: 0.7rem; color: #64748b; font-weight: 600; }
        .sales-chart-v2-amt { font-size: 0.62rem; color: #94a3b8; font-family: var(--font-mono); }

        .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.65rem; border-radius: 9999px; text-transform: capitalize; }

        @media (max-width: 900px) {
            .stats-grid-v2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .admin-panels-v2 { grid-template-columns: 1fr; }
            .widgets-grid { grid-template-columns: 1fr; }
            .revenue-banner { grid-template-columns: 1fr; }
            .revenue-block + .revenue-block { border-left: none; padding-left: 0; border-top: 1px solid rgba(255,255,255,0.12); padding-top: 0.75rem; }
        }
    </style>
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Admin</div>
            <nav>
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="products.php">Products</a>
                <a href="orders.php">Orders</a>
                <a href="deliveries.php">Deliveries</a>
                <a href="riders.php">Riders</a>
                <a href="sales.php">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <!-- Header bento -->
            <div class="dash-header-bento">
                <div>
                    <h1>Dashboard</h1>
                    <p class="field-hint">Here's what's happening across the shop today, <?= date('F j, Y') ?>.</p>
                </div>
                <div class="dh-user">
                    <div class="dh-avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1))) ?></div>
                    <div>
                        <div class="dh-user-name">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        <div class="dh-user-role">Admin</div>
                    </div>
                </div>
            </div>

            <!-- Revenue banner -->
            <div class="revenue-banner">
                <div class="revenue-block">
                    <span class="revenue-label"><span class="material-symbols-outlined">payments</span>Total Revenue</span>
                    <span class="revenue-value accent">&#8369;<?= number_format($totalRevenue, 2) ?></span>
                </div>
                <div class="revenue-block">
                    <span class="revenue-label"><span class="material-symbols-outlined">today</span>Today</span>
                    <span class="revenue-value">&#8369;<?= number_format($todayRevenue, 2) ?></span>
                </div>
                <div class="revenue-block">
                    <span class="revenue-label"><span class="material-symbols-outlined">calendar_month</span>This Month</span>
                    <span class="revenue-value">&#8369;<?= number_format($monthRevenue, 2) ?></span>
                </div>
            </div>

            <!-- Stat cards (clickable) -->
            <section class="stats-grid-v2">
                <a class="stat-card-v2" href="products.php">
                    <div class="stat-card-v2-top"><span>Products</span><span class="material-symbols-outlined">inventory_2</span></div>
                    <div class="stat-card-v2-value"><?= $totalProducts ?></div>
                    <div class="stat-card-v2-sub"><?= $lowStockTotal ?> running low</div>
                    <div class="stat-card-v2-link">View all <span class="material-symbols-outlined" style="font-size:0.9rem;">chevron_right</span></div>
                </a>
                <a class="stat-card-v2" href="orders.php">
                    <div class="stat-card-v2-top"><span>Orders</span><span class="material-symbols-outlined">receipt_long</span></div>
                    <div class="stat-card-v2-value"><?= $totalOrders ?></div>
                    <div class="stat-card-v2-sub">&#8369;<?= number_format($totalRevenue, 2) ?> lifetime</div>
                    <div class="stat-card-v2-link">View all <span class="material-symbols-outlined" style="font-size:0.9rem;">chevron_right</span></div>
                </a>
                <a class="stat-card-v2" href="deliveries.php">
                    <div class="stat-card-v2-top"><span>Deliveries</span><span class="material-symbols-outlined">local_shipping</span></div>
                    <div class="stat-card-v2-value"><?= $totalDeliveries ?></div>
                    <div class="stat-card-v2-sub"><?= $deliveryStatusCounts['out_for_delivery'] ?> en route &middot; <?= $deliveryStatusCounts['pending'] ?> pending</div>
                    <div class="stat-card-v2-link">View all <span class="material-symbols-outlined" style="font-size:0.9rem;">chevron_right</span></div>
                </a>
                <a class="stat-card-v2" href="customers.php">
                    <div class="stat-card-v2-top"><span>Customers</span><span class="material-symbols-outlined">group</span></div>
                    <div class="stat-card-v2-value"><?= $totalCustomers ?></div>
                    <div class="stat-card-v2-sub">Registered accounts</div>
                    <div class="stat-card-v2-link">View all <span class="material-symbols-outlined" style="font-size:0.9rem;">chevron_right</span></div>
                </a>
            </section>

            <!-- Low stock + Recent orders -->
            <div class="admin-panels-v2">

                <section class="admin-panel-v2" id="low-stock">
                    <div class="admin-panel-v2-head">
                        <h2>Low Stock</h2>
                        <a href="products.php" class="panel-view-all">Manage products</a>
                    </div>
                    <?php if (empty($lowStock)): ?>
                        <p class="empty-state">Nothing running low right now.</p>
                    <?php else: ?>
                        <?php foreach ($lowStock as $item): ?>
                            <div class="low-stock-row">
                                <span class="low-stock-name">
                                    <span class="material-symbols-outlined">warning</span>
                                    <?= htmlspecialchars($item['name']) ?>
                                </span>
                                <span class="low-stock-badge"><?= (int) $item['stock'] ?> left</span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($lowStockPages > 1): ?>
                            <div class="low-stock-pagination">
                                <span class="page-info">Page <?= $lowStockPage ?> of <?= $lowStockPages ?></span>
                                <div class="page-nav">
                                    <?php if ($lowStockPage > 1): ?>
                                        <a class="page-btn" href="?lsp=<?= $lowStockPage - 1 ?>#low-stock" aria-label="Previous page">
                                            <span class="material-symbols-outlined">chevron_left</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn disabled"><span class="material-symbols-outlined">chevron_left</span></span>
                                    <?php endif; ?>
                                    <?php if ($lowStockPage < $lowStockPages): ?>
                                        <a class="page-btn" href="?lsp=<?= $lowStockPage + 1 ?>#low-stock" aria-label="Next page">
                                            <span class="material-symbols-outlined">chevron_right</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn disabled"><span class="material-symbols-outlined">chevron_right</span></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="admin-panel-v2" id="recent-orders">
                    <div class="admin-panel-v2-head">
                        <h2>Recent Orders</h2>
                        <a href="orders.php" class="panel-view-all">View all</a>
                    </div>
                    <?php if (empty($recentOrders)): ?>
                        <p class="empty-state">No orders yet.</p>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr><th>#</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>#<?= (int) $order['id'] ?></td>
                                        <td><?= htmlspecialchars($order['full_name']) ?></td>
                                        <td>&#8369;<?= number_format($order['total_amount'], 2) ?></td>
                                        <td><span class="status-badge status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></td>
                                        <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($recentOrdersPages > 1): ?>
                            <div class="low-stock-pagination">
                                <span class="page-info">Page <?= $recentOrdersPage ?> of <?= $recentOrdersPages ?></span>
                                <div class="page-nav">
                                    <?php if ($recentOrdersPage > 1): ?>
                                        <a class="page-btn" href="?rop=<?= $recentOrdersPage - 1 ?>#recent-orders" aria-label="Previous page">
                                            <span class="material-symbols-outlined">chevron_left</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn disabled"><span class="material-symbols-outlined">chevron_left</span></span>
                                    <?php endif; ?>
                                    <?php if ($recentOrdersPage < $recentOrdersPages): ?>
                                        <a class="page-btn" href="?rop=<?= $recentOrdersPage + 1 ?>#recent-orders" aria-label="Next page">
                                            <span class="material-symbols-outlined">chevron_right</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="page-btn disabled"><span class="material-symbols-outlined">chevron_right</span></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

            </div>

            <!-- Delivery status breakdown + 7-day sales trend -->
            <div class="widgets-grid">

                <section class="admin-panel-v2">
                    <div class="admin-panel-v2-head">
                        <h2>Delivery Status</h2>
                        <a href="deliveries.php" class="panel-view-all">Manage</a>
                    </div>
                    <div class="delivery-mini">
                        <div class="delivery-mini-row">
                            <span class="delivery-mini-label"><span class="delivery-mini-dot pending"></span>Pending</span>
                            <span class="delivery-mini-count"><?= $deliveryStatusCounts['pending'] ?></span>
                        </div>
                        <div class="delivery-mini-row">
                            <span class="delivery-mini-label"><span class="delivery-mini-dot out_for_delivery"></span>Out for Delivery</span>
                            <span class="delivery-mini-count"><?= $deliveryStatusCounts['out_for_delivery'] ?></span>
                        </div>
                        <div class="delivery-mini-row">
                            <span class="delivery-mini-label"><span class="delivery-mini-dot delivered"></span>Delivered</span>
                            <span class="delivery-mini-count"><?= $deliveryStatusCounts['delivered'] ?></span>
                        </div>
                        <div class="delivery-mini-row">
                            <span class="delivery-mini-label"><span class="delivery-mini-dot failed"></span>Failed</span>
                            <span class="delivery-mini-count"><?= $deliveryStatusCounts['failed'] ?></span>
                        </div>
                    </div>
                </section>

                <section class="admin-panel-v2">
                    <div class="admin-panel-v2-head">
                        <h2>Sales — Last 7 Days</h2>
                        <a href="sales.php" class="panel-view-all">Full report</a>
                    </div>
                    <?php if ($totalOrders === 0): ?>
                        <p class="empty-state">No sales data yet.</p>
                    <?php else: ?>
                        <div class="sales-chart-v2">
                            <?php foreach ($last7Days as $day): ?>
                                <?php $heightPct = $maxDayTotal > 0 ? max(3, round(($day['total'] / $maxDayTotal) * 100)) : 3; ?>
                                <div class="sales-chart-v2-col" title="&#8369;<?= number_format($day['total'], 2) ?>">
                                    <span class="sales-chart-v2-amt">&#8369;<?= number_format($day['total'], 0) ?></span>
                                    <div class="sales-chart-v2-bar-track">
                                        <div class="sales-chart-v2-bar" style="height: <?= $heightPct ?>%"></div>
                                    </div>
                                    <span class="sales-chart-v2-day"><?= date('D', strtotime($day['date'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>
    </div>

</body>
</html>