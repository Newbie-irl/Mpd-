<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage orders
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$validStatuses = ['pending', 'processing', 'completed', 'cancelled'];

// ---- Update status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $id     = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (in_array($status, $validStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Order #{$id} status updated to " . ucfirst($status) . ".";
        } else {
            $_SESSION['success'] = "No changes made to Order #{$id} (status was already " . ucfirst($status) . ", or the order wasn't found).";
        }
    }

    // NOTE: intentionally NOT re-appending the previous status filter here.
    // Redirecting back into the same filtered tab after a status change
    // makes the just-updated order disappear from view (since it no longer
    // matches that filter), which looks like the update never saved.
    header('Location: orders.php');
    exit;
}

// ---- Delete ----
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['delete']]);
    header('Location: orders.php');
    exit;
}

// ---- Optional status filter ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

// ---- Load orders (with customer name/email + delivery address) ----
// LEFT JOIN so orders without a matching delivery row still show up
if ($filter !== '') {
    $stmt = $pdo->prepare(
        "SELECT o.*, u.full_name, u.email,
                d.recipient_name, d.address, d.contact_number,
                d.rider_name, d.status AS delivery_status
         FROM orders o
         JOIN users u ON o.user_id = u.id
         LEFT JOIN deliveries d ON d.order_id = o.id
         WHERE o.status = :status
         ORDER BY o.created_at DESC"
    );
    $stmt->execute(['status' => $filter]);
    $orders = $stmt->fetchAll();
} else {
    $orders = $pdo->query(
        "SELECT o.*, u.full_name, u.email,
                d.recipient_name, d.address, d.contact_number,
                d.rider_name, d.status AS delivery_status
         FROM orders o
         JOIN users u ON o.user_id = u.id
         LEFT JOIN deliveries d ON d.order_id = o.id
         ORDER BY o.created_at DESC"
    )->fetchAll();
}

// ---- Load line items for the loaded orders in one query ----
// Wrapped defensively in case order_items hasn't been created yet
$itemsByOrder = [];
if (!empty($orders)) {
    try {
        $orderIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT oi.order_id, oi.quantity, oi.price, p.name
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id IN ($placeholders)"
        );
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll() as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }
    } catch (PDOException $e) {
        // order_items table not created yet — items just won't show
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Admin</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="products.php">Products</a>
                <a href="orders.php" class="active">Orders</a>
                <a href="deliveries.php">Deliveries</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../index.php?logout=1">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Orders</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <section class="admin-panel">
                <div class="filter-tabs">
                    <a href="orders.php" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
                    <?php foreach ($validStatuses as $s): ?>
                        <a href="orders.php?status=<?= $s ?>" class="<?= $filter === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($orders)): ?>
                    <p class="empty-state">No orders <?= $filter ? 'with this status' : 'yet' ?>.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Delivery</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>#<?= (int) $order['id'] ?></td>
                                    <td>
                                        <?= htmlspecialchars($order['full_name']) ?>
                                        <div class="field-hint"><?= htmlspecialchars($order['email']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($order['recipient_name']): ?>
                                            <?= htmlspecialchars($order['recipient_name']) ?>
                                            <?php if ($order['address']): ?>
                                                <div class="field-hint"><?= htmlspecialchars($order['address']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($order['contact_number']): ?>
                                                <div class="field-hint"><?= htmlspecialchars($order['contact_number']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="empty-state">No delivery info</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $items = $itemsByOrder[$order['id']] ?? []; ?>
                                        <?php if (empty($items)): ?>
                                            <span class="empty-state">No items</span>
                                        <?php else: ?>
                                            <details>
                                                <summary><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></summary>
                                                <ul class="order-items-list">
                                                    <?php foreach ($items as $item): ?>
                                                        <li><?= (int) $item['quantity'] ?>&times; <?= htmlspecialchars($item['name']) ?> (&#8369;<?= number_format($item['price'], 2) ?>)</li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td>&#8369;<?= number_format($order['total_amount'], 2) ?></td>
                                    <td>
                                        <form method="POST" action="orders.php<?= $filter ? '?status=' . urlencode($filter) : '' ?>" class="status-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                                            <select name="status">
                                                <?php foreach ($validStatuses as $s): ?>
                                                    <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-edit">Update</button>
                                        </form>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                                    <td>
                                        <a href="orders.php?delete=<?= (int) $order['id'] ?>" class="btn-danger"
                                           onclick="return confirm('Delete this order? This cannot be undone.');">Delete</a>
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