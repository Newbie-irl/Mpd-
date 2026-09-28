<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage orders
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$validStatuses = ['pending', 'processing', 'completed', 'cancelled'];
$id = (int) ($_GET['id'] ?? 0);

// ---- Update status from this page too ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $postId = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if (in_array($status, $validStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $postId]);
        $_SESSION['success'] = "Order #{$postId} status updated to " . ucfirst($status) . ".";
    }

    header('Location: order-details.php?id=' . $postId);
    exit;
}

// ---- Load the order ----
$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, u.email,
            d.recipient_name, d.address, d.contact_number,
            d.rider_name, d.status AS delivery_status
     FROM orders o
     JOIN users u ON o.user_id = u.id
     LEFT JOIN deliveries d ON d.order_id = o.id
     WHERE o.id = :id"
);
$stmt->execute(['id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ---- Load line items for this order ----
$items = [];
try {
    $stmt = $pdo->prepare(
        "SELECT oi.quantity, oi.price, p.name
         FROM order_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = :id"
    );
    $stmt->execute(['id' => $id]);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    // order_items table not created yet — items just won't show
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= (int) $order['id'] ?> - MPD Electrical Supply & Services</title>
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
                <a href="riders.php">Riders</a>
                <a href="sales.php">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Order #<?= (int) $order['id'] ?></h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <p><a href="orders.php">&larr; Back to Orders</a></p>

            <section class="admin-panel order-details-grid">

                <div class="order-details-card">
                    <h2>Customer</h2>
                    <p><?= htmlspecialchars($order['full_name']) ?></p>
                    <p class="field-hint"><?= htmlspecialchars($order['email']) ?></p>
                </div>

                <div class="order-details-card">
                    <h2>Delivery</h2>
                    <?php if ($order['recipient_name']): ?>
                        <p><?= htmlspecialchars($order['recipient_name']) ?></p>
                        <?php if ($order['address']): ?>
                            <p class="field-hint"><?= nl2br(htmlspecialchars($order['address'])) ?></p>
                        <?php endif; ?>
                        <?php if ($order['contact_number']): ?>
                            <p class="field-hint"><?= htmlspecialchars($order['contact_number']) ?></p>
                        <?php endif; ?>
                        <?php if ($order['rider_name']): ?>
                            <p class="field-hint">Rider: <?= htmlspecialchars($order['rider_name']) ?></p>
                        <?php endif; ?>
                        <?php if ($order['delivery_status']): ?>
                            <p class="field-hint">Delivery status: <?= htmlspecialchars(ucfirst($order['delivery_status'])) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="empty-state">No delivery info</span>
                    <?php endif; ?>
                </div>

                <div class="order-details-card">
                    <h2>Status</h2>
                    <form method="POST" action="order-details.php?id=<?= (int) $order['id'] ?>" class="status-form">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                        <select name="status">
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-edit">Update</button>
                    </form>
                    <p class="field-hint">Placed <?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></p>
                </div>

                <div class="order-details-card order-details-items">
                    <h2>Items</h2>
                    <?php if (empty($items)): ?>
                        <span class="empty-state">No items</span>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td><?= (int) $item['quantity'] ?></td>
                                        <td>&#8369;<?= number_format($item['price'], 2) ?></td>
                                        <td>&#8369;<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align:right;"><strong>Total</strong></td>
                                    <td><strong>&#8369;<?= number_format($order['total_amount'], 2) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>

            </section>

        </main>
    </div>

</body>
</html>