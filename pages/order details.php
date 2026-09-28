<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Order status = deliveries.status (single source of truth). Valid statuses,
// labels and badge classes are shared with the admin pages.
require_once '../config/delivery_status.php';

$orderId = (int) ($_GET['id'] ?? 0);

// ---- Cancel order (only while the delivery is still pending) ----
if (isset($_GET['cancel'])) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT d.id, d.status
             FROM deliveries d
             JOIN orders o ON o.id = d.order_id
             WHERE o.id = :id AND o.user_id = :user_id
             FOR UPDATE"
        );
        $stmt->execute(['id' => $orderId, 'user_id' => $_SESSION['user_id']]);
        $delivery = $stmt->fetch();

        if ($delivery && $delivery['status'] === 'pending') {
            $pdo->prepare("UPDATE deliveries SET status = 'cancelled' WHERE id = :id")
                ->execute(['id' => $delivery['id']]);

            $items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :id");
            $items->execute(['id' => $orderId]);
            $restock = $pdo->prepare("UPDATE products SET stock = stock + :qty WHERE id = :id");
            foreach ($items->fetchAll() as $item) {
                $restock->execute(['qty' => $item['quantity'], 'id' => $item['product_id']]);
            }

            $pdo->commit();
            $_SESSION['success'] = "Order #{$orderId} was cancelled.";
        } else {
            $pdo->rollBack();
            $_SESSION['success'] = "Order #{$orderId} can no longer be cancelled.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['success'] = "Sorry, we couldn't cancel that order. Please try again.";
    }

    header("Location: order-details.php?id={$orderId}");
    exit;
}

// ---- Reorder: add this order's items back into the cart ----
if (isset($_GET['reorder'])) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id AND user_id = :user_id");
    $stmt->execute(['id' => $orderId, 'user_id' => $_SESSION['user_id']]);
    $order = $stmt->fetch();

    if ($order) {
        $stmt = $pdo->prepare(
            "SELECT oi.product_id, oi.quantity
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = :id"
        );
        $stmt->execute(['id' => $orderId]);
        $reorderItems = $stmt->fetchAll();

        if (!empty($reorderItems)) {
            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            foreach ($reorderItems as $item) {
                $pid = (int) $item['product_id'];
                $qty = (int) $item['quantity'];
                $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
            }
            $_SESSION['success'] = "Items from order #{$orderId} were added to your cart.";
        } else {
            $_SESSION['success'] = "Those items are no longer available to reorder.";
        }
    }

    header('Location: cart.php');
    exit;
}

// ---- Load the order (must belong to the logged-in customer) ----
$stmt = $pdo->prepare(
    "SELECT o.*, COALESCE(d.status, 'pending') AS delivery_status,
            d.recipient_name, d.address, d.contact_number, d.preferred_time,
            d.rider_name, d.scheduled_date, d.notes
     FROM orders o
     LEFT JOIN deliveries d ON d.order_id = o.id
     WHERE o.id = :id AND o.user_id = :user_id"
);
$stmt->execute(['id' => $orderId, 'user_id' => $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// ---- Load every line item for this order ----
$stmt = $pdo->prepare(
    "SELECT oi.quantity, oi.price, p.id AS product_id, p.name, p.image
     FROM order_items oi
     JOIN products p ON oi.product_id = p.id
     WHERE oi.order_id = :id
     ORDER BY oi.id ASC"
);
$stmt->execute(['id' => $orderId]);
$items = $stmt->fetchAll();

$preferredTimeLabels = [
    'morning'   => 'Morning (8:00 AM - 12:00 PM)',
    'afternoon' => 'Afternoon (12:00 PM - 4:00 PM)',
    'evening'   => 'Evening (4:00 PM - 8:00 PM)',
];

// $statusLabels / $statusClasses come from config/delivery_status.php
$statusHints = [
    'pending'          => 'Awaiting confirmation',
    'out_for_delivery' => 'On its way to you',
    'delivered'        => 'Delivered',
    'failed'           => 'Delivery was unsuccessful. Please contact us.',
    'cancelled'        => 'This order was cancelled',
];

$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= (int) $order['id'] ?> - MPD Electrical Supply &amp; Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 1.125rem;
            line-height: 1;
            vertical-align: middle;
        }

        .order-details-back { display: inline-flex; align-items: center; gap: 0.3rem; color: #64748b; font-size: 0.85rem; font-weight: 600; text-decoration: none; margin-bottom: 1rem; }
        .order-details-back:hover { color: #0f172a; }

        .order-details-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem;
            background-color: #ffffff; border-radius: 1rem; padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); margin-bottom: 1.25rem; }
        .order-details-header h1 { font-size: 1.4rem; color: #0f172a; margin-bottom: 0.25rem; }
        .order-details-header .order-date { color: #64748b; font-size: 0.85rem; }
        .order-details-status { display: flex; flex-direction: column; align-items: flex-end; gap: 0.25rem; }
        .order-details-status .order-status-hint { font-size: 0.72rem; color: #94a3b8; }

        .order-details-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; align-items: start; }
        @media (max-width: 900px) { .order-details-grid { grid-template-columns: 1fr; } }

        .order-details-panel { background-color: #ffffff; border-radius: 1rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); padding: 1.25rem 1.5rem; }
        .order-details-panel h2 { font-size: 1rem; color: #0f172a; margin-bottom: 1rem; }

        .order-item-row { display: flex; align-items: center; gap: 0.9rem; padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
        .order-item-row:last-child { border-bottom: none; }
        .order-item-thumb { width: 56px; height: 56px; border-radius: 0.6rem; object-fit: cover; background-color: #f1f5f9; flex-shrink: 0; }
        .order-item-thumb-placeholder { width: 56px; height: 56px; border-radius: 0.6rem; background-color: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #cbd5e1; flex-shrink: 0; }
        .order-item-info { flex: 1; min-width: 0; }
        .order-item-name { font-weight: 600; color: #0f172a; font-size: 0.9rem; }
        .order-item-meta { font-size: 0.78rem; color: #64748b; margin-top: 0.15rem; }
        .order-item-total { font-family: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace; font-weight: 700; color: #0f172a; white-space: nowrap; }

        .order-items-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; margin-top: 0.25rem; border-top: 2px solid #f1f5f9; }
        .order-items-footer-label { color: #64748b; font-size: 0.85rem; }
        .order-items-footer-total { font-size: 1.25rem; font-weight: 700; color: #f59e0b; }

        .order-info-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
        .order-info-row:last-child { border-bottom: none; }
        .order-info-label { color: #64748b; }
        .order-info-value { color: #0f172a; font-weight: 600; text-align: right; }

        .order-details-actions { display: flex; gap: 0.6rem; margin-top: 1.25rem; }
    </style>
</head>
<body>

    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical Supply &amp; Services</div>
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="cart.php">Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?></a></li>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="orders.php">My Orders</a></li>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li><a href="../admin/dashboard.php">Admin Panel</a></li>
                    <?php endif; ?>
                    <li class="nav-welcome">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></li>
                    <li><a href="../logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="../login.php">Login</a></li>
                    <li><a href="../register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="storefront-main">

        <a href="orders.php" class="order-details-back">
            <span class="material-symbols-outlined">arrow_back</span> Back to My Orders
        </a>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="order-details-header">
            <div>
                <h1>Order #<?= (int) $order['id'] ?></h1>
                <div class="order-date">Placed on <?= date('F j, Y g:i A', strtotime($order['created_at'])) ?></div>
            </div>
            <div class="order-details-status">
                <span class="status-badge status-<?= $statusClasses[$order['delivery_status']] ?>"><?= htmlspecialchars($statusLabels[$order['delivery_status']]) ?></span>
                <span class="order-status-hint"><?= htmlspecialchars($statusHints[$order['delivery_status']] ?? '') ?></span>
                <?php if (!empty($order['scheduled_date']) && in_array($order['delivery_status'], ['pending', 'out_for_delivery'], true)): ?>
                    <span class="order-status-hint">Scheduled: <?= date('M j, Y', strtotime($order['scheduled_date'])) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="order-details-grid">

            <div class="order-details-panel">
                <h2>Items (<?= count($items) ?>)</h2>

                <?php if (empty($items)): ?>
                    <p class="empty-state">No items found for this order.</p>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <div class="order-item-row">
                            <?php if (!empty($item['image'])): ?>
                                <img src="../assets/images/products/<?= htmlspecialchars($item['image']) ?>"
                                     alt="<?= htmlspecialchars($item['name']) ?>" class="order-item-thumb">
                            <?php else: ?>
                                <div class="order-item-thumb-placeholder">
                                    <span class="material-symbols-outlined">inventory_2</span>
                                </div>
                            <?php endif; ?>
                            <div class="order-item-info">
                                <div class="order-item-name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="order-item-meta"><?= (int) $item['quantity'] ?> &times; &#8369;<?= number_format($item['price'], 2) ?></div>
                            </div>
                            <div class="order-item-total">&#8369;<?= number_format($item['quantity'] * $item['price'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>

                    <div class="order-items-footer">
                        <span class="order-items-footer-label">Order Total</span>
                        <span class="order-items-footer-total">&#8369;<?= number_format($order['total_amount'], 2) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="order-details-panel">
                <h2>Delivery Details</h2>

                <div class="order-info-row">
                    <span class="order-info-label">Recipient</span>
                    <span class="order-info-value"><?= htmlspecialchars($order['recipient_name'] ?? '—') ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label">Address</span>
                    <span class="order-info-value"><?= htmlspecialchars($order['address'] ?? '—') ?></span>
                </div>
                <div class="order-info-row">
                    <span class="order-info-label">Contact</span>
                    <span class="order-info-value"><?= htmlspecialchars($order['contact_number'] ?? '—') ?></span>
                </div>
                <?php if (!empty($order['preferred_time'])): ?>
                    <div class="order-info-row">
                        <span class="order-info-label">Preferred Time</span>
                        <span class="order-info-value"><?= htmlspecialchars($preferredTimeLabels[$order['preferred_time']] ?? $order['preferred_time']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['rider_name'])): ?>
                    <div class="order-info-row">
                        <span class="order-info-label">Rider</span>
                        <span class="order-info-value"><?= htmlspecialchars($order['rider_name']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order['notes'])): ?>
                    <div class="order-info-row">
                        <span class="order-info-label">Notes</span>
                        <span class="order-info-value"><?= htmlspecialchars($order['notes']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="order-details-actions">
                    <?php if ($order['delivery_status'] === 'pending'): ?>
                        <a href="order-details.php?id=<?= (int) $order['id'] ?>&cancel=1" class="btn-mini btn-mini-danger"
                           onclick="return confirm('Cancel this order?');">
                            <span class="material-symbols-outlined">close</span> Cancel Order
                        </a>
                    <?php elseif (in_array($order['delivery_status'], ['delivered', 'failed', 'cancelled'], true)): ?>
                        <a href="order-details.php?id=<?= (int) $order['id'] ?>&reorder=1" class="btn-mini btn-mini-primary">
                            <span class="material-symbols-outlined">replay</span> Reorder
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply &amp; Services. All rights reserved.</p>
    </footer>

</body>
</html>