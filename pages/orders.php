<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// ---- Cancel order (only while still pending) ----
if (isset($_GET['cancel'])) {
    $orderId = (int) $_GET['cancel'];

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $orderId, 'user_id' => $_SESSION['user_id']]);
    $order = $stmt->fetch();

    if ($order && $order['status'] === 'pending') {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")
            ->execute(['id' => $orderId]);

        // Return the items to stock
        $items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
        $items->execute(['id' => $orderId]);
        $restock = $pdo->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id");
        foreach ($items->fetchAll() as $item) {
            $restock->execute(['qty' => $item['quantity'], 'id' => $item['product_id']]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Order #{$orderId} was cancelled.";
    }

    header('Location: orders.php');
    exit;
}

// ---- Load this customer's orders ----
$stmt = $pdo->prepare(
    "SELECT * FROM orders WHERE user_id = :user_id ORDER BY created_at DESC"
);
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$orders = $stmt->fetchAll();

// ---- Load line items for those orders in one query ----
$itemsByOrder = [];
if (!empty($orders)) {
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
}

$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical</div>
 <ul class="nav-links">
    <li><a href="../index.php">Home</a></li>
    <li><a href="products.php">Products</a></li>
    <li><a href="cart.php">Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?></a></li>

    <?php if (isset($_SESSION['user_id'])): ?>
        <li><a href="orders.php">My Orders</a></li>
        <li class="nav-welcome">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></li>
        <li><a href="../index.php?logout=1">Logout</a></li>
    <?php else: ?>
        <li><a href="../login.php">Login</a></li>
        <li><a href="../register.php">Register</a></li>
    <?php endif; ?>
</ul>
        </nav>
    </header>

    <main class="storefront-main">
        <h1>My Orders</h1>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <p class="empty-state">You haven't placed any orders yet. <a href="products.php">Start shopping</a>.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
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
                            <td><span class="status-badge status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></td>
                            <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                            <td>
                                <?php if ($order['status'] === 'pending'): ?>
                                    <a href="orders.php?cancel=<?= (int) $order['id'] ?>" class="btn-danger"
                                       onclick="return confirm('Cancel this order?');">Cancel</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>