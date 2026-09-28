<?php
session_start();
require_once '../config/database.php';

// Only logged-in riders may view this page — no other admin pages exist
// under this role, this list plus rider-order-details.php is the whole thing.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rider') {
    header('Location: ../login.php');
    exit;
}

$riderId = (int) $_SESSION['user_id'];

$statusLabels = [
    'pending'          => 'Pending',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'failed'           => 'Failed',
    'cancelled'        => 'Cancelled',
];
$statusClasses = [
    'pending'          => 'pending',
    'out_for_delivery' => 'processing',
    'delivered'        => 'completed',
    'failed'           => 'cancelled',
    'cancelled'        => 'cancelled',
];

// ---- Optional status filter ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !array_key_exists($filter, $statusLabels)) {
    $filter = '';
}

// ---- Load MY assigned orders only ----
$sql = "SELECT d.id AS delivery_id, d.status, d.recipient_name, d.scheduled_date,
               o.id AS order_id, o.total_amount, o.created_at
        FROM deliveries d
        JOIN orders o ON d.order_id = o.id
        WHERE d.rider_id = :rider_id";
$params = ['rider_id' => $riderId];
if ($filter !== '') {
    $sql .= " AND d.status = :status";
    $params['status'] = $filter;
}
$sql .= " ORDER BY FIELD(d.status, 'out_for_delivery', 'pending', 'failed', 'delivered', 'cancelled'), d.scheduled_date ASC, o.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - MPD Electrical Supply &amp; Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Rider</div>
            <nav>
                <a href="rider-dashboard.php" class="active">My Orders</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>My Orders</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <section class="admin-panel">
                <div class="filter-tabs">
                    <a href="rider-dashboard.php" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <a href="rider-dashboard.php?status=<?= $value ?>" class="<?= $filter === $value ? 'active' : '' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($orders)): ?>
                    <p class="empty-state">No orders <?= $filter ? 'with this status' : 'assigned to you yet' ?>.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Recipient</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>#<?= (int) $order['order_id'] ?></td>
                                    <td><?= htmlspecialchars($order['recipient_name']) ?></td>
                                    <td>&#8369;<?= number_format($order['total_amount'], 2) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $statusClasses[$order['status']] ?>">
                                            <?= $statusLabels[$order['status']] ?>
                                        </span>
                                    </td>
                                    <td><?= $order['scheduled_date'] ? date('M j, Y', strtotime($order['scheduled_date'])) : '&mdash;' ?></td>
                                    <td class="admin-table-actions">
                                        <a href="rider-order-details.php?id=<?= (int) $order['order_id'] ?>" class="btn-edit">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

        </main>
    </div>

</body>
</html>