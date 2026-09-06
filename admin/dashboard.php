<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may view this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

/**
 * Runs a COUNT query and returns 0 instead of crashing if the table
 * doesn't exist yet (handy while orders/bookings are still being built).
 */
function safeCount(PDO $pdo, string $sql): int {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

$totalProducts   = safeCount($pdo, "SELECT COUNT(*) FROM products");
$totalOrders     = safeCount($pdo, "SELECT COUNT(*) FROM orders");
$totalDeliveries = safeCount($pdo, "SELECT COUNT(*) FROM deliveries");
$totalCustomers  = safeCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'customer'");

// Products running low on stock
$lowStock = [];
try {
    $lowStock = $pdo->query(
        "SELECT name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    // products table not created yet — ignore
}

// Most recent orders
$recentOrders = [];
try {
    $recentOrders = $pdo->query(
        "SELECT o.id, o.total_amount, o.status, o.created_at, u.full_name
         FROM orders o
         JOIN users u ON o.user_id = u.id
         ORDER BY o.created_at DESC
         LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    // orders table not created yet — ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../index.php?logout=1">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Dashboard</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <section class="stats-grid">
                <div class="stat-card">
                    <span class="stat-value"><?= $totalProducts ?></span>
                    <span class="stat-label">Products</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= $totalOrders ?></span>
                    <span class="stat-label">Orders</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= $totalDeliveries ?></span>
                    <span class="stat-label">Deliveries</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?= $totalCustomers ?></span>
                    <span class="stat-label">Customers</span>
                </div>
            </section>

            <div class="admin-panels">

                <section class="admin-panel">
                    <h2>Low Stock</h2>
                    <?php if (empty($lowStock)): ?>
                        <p class="empty-state">Nothing running low right now.</p>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr><th>Product</th><th>Stock</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lowStock as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td><span class="low-stock-badge"><?= (int) $item['stock'] ?> left</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="admin-panel">
                    <h2>Recent Orders</h2>
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
                    <?php endif; ?>
                </section>

            </div>

        </main>
    </div>

</body>
</html>