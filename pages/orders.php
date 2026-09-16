<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$validStatuses = ['pending', 'processing', 'completed', 'cancelled'];

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

// ---- Reorder: add a past order's items back into the cart ----
if (isset($_GET['reorder'])) {
    $orderId = (int) $_GET['reorder'];

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

// ---- Status filter ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

// ---- Load ALL of this customer's orders (used for the stat chips) ----
$stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = :user_id ORDER BY created_at DESC");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$allOrders = $stmt->fetchAll();

$statusCounts = array_fill_keys($validStatuses, 0);
$totalSpent   = 0.0;
foreach ($allOrders as $o) {
    if (isset($statusCounts[$o['status']])) {
        $statusCounts[$o['status']]++;
    }
    if ($o['status'] !== 'cancelled') {
        $totalSpent += (float) $o['total_amount'];
    }
}
$totalOrdersCount = count($allOrders);

// ---- Apply the filter for what actually gets displayed ----
$orders = $filter === ''
    ? $allOrders
    : array_values(array_filter($allOrders, fn($o) => $o['status'] === $filter));

// ---- Load line items for the visible orders in one query ----
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

$statusLabels = [
    'pending'    => 'Pending',
    'processing' => 'Processing',
    'completed'  => 'Completed',
    'cancelled'  => 'Cancelled',
];
$statusHints = [
    'pending'    => 'Awaiting confirmation',
    'processing' => 'Being prepared for delivery',
    'completed'  => 'Delivered / picked up',
    'cancelled'  => 'This order was cancelled',
];

$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - MPD Electrical Supply &amp; Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Page-specific styles are embedded here directly (same approach
      used on the admin pages) so this page always renders correctly even if
      style.css is out of date or served from a stale cache.
    -->
    <style>
        :root {
            --font-mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 1.125rem;
            line-height: 1;
            vertical-align: middle;
        }

        .orders-header-bento {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            background-color: #ffffff; border-radius: 1rem; padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); margin-bottom: 1.25rem;
        }
        .orders-header-bento h1 { font-size: 1.5rem; color: #0f172a; margin-bottom: 0.2rem; }
        .orders-header-bento p { color: #64748b; font-size: 0.85rem; }

        .orders-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .order-stat-card { background-color: #ffffff; border-radius: 1rem; padding: 1.1rem 1.25rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: flex; flex-direction: column; gap: 0.35rem; }
        .order-stat-top { display: flex; align-items: center; justify-content: space-between; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .order-stat-top .material-symbols-outlined { color: #fbbf24; font-size: 1.1rem; }
        .order-stat-value { font-size: 1.65rem; font-weight: 700; color: #0f172a; }
        .order-stat-value.accent { color: #f59e0b; }
        .order-stat-sub { font-size: 0.75rem; color: #64748b; }

        .orders-toolbar { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1rem; }
        .status-pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 9999px; background-color: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 600; transition: background-color 0.15s ease-in-out; }
        .status-pill:hover { background-color: #e2e8f0; }
        .status-pill.active { background-color: #fbbf24; color: #0f172a; }
        .status-pill-count { font-family: var(--font-mono); font-size: 0.65rem; font-weight: 700; background-color: #ffffff; color: #475569; padding: 0.05rem 0.4rem; border-radius: 9999px; }
        .status-pill.active .status-pill-count { background-color: rgba(255,255,255,0.5); color: #0f172a; }

        .orders-panel { background-color: #ffffff; border-radius: 1rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); overflow: hidden; }
        .order-row { display: grid; grid-template-columns: 90px 1fr 130px 160px 130px 140px; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; }
        .order-row:last-child { border-bottom: none; }
        .order-row:hover { background-color: #f8fafc; }
        .order-row-head { background-color: #f8fafc; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }

        .order-code { font-family: var(--font-mono); font-weight: 700; color: #f59e0b; }
        .order-items-summary summary { cursor: pointer; color: #0369a1; font-weight: 600; font-size: 0.85rem; list-style: none; display: flex; align-items: center; gap: 0.25rem; }
        .order-items-summary summary::-webkit-details-marker { display: none; }
        .order-items-summary summary .material-symbols-outlined { font-size: 1rem; transition: transform 0.15s ease-in-out; }
        .order-items-summary[open] summary .material-symbols-outlined { transform: rotate(90deg); }
        .order-items-list { margin-top: 0.4rem; padding-left: 1rem; font-size: 0.8rem; line-height: 1.4rem; color: #64748b; }

        .order-total { font-family: var(--font-mono); font-weight: 700; color: #0f172a; }

        .order-status-cell { display: flex; flex-direction: column; gap: 0.15rem; }
        .order-status-hint { font-size: 0.68rem; color: #94a3b8; }

        .order-date { font-size: 0.82rem; color: #334155; }

        .order-actions { display: flex; justify-content: flex-end; gap: 0.4rem; }
        .btn-mini { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; font-weight: 600; padding: 0.35rem 0.7rem; border-radius: 0.6rem; text-decoration: none; }
        .btn-mini .material-symbols-outlined { font-size: 0.95rem; }
        .btn-mini-danger { background-color: #ffe4e6; color: #be123c; }
        .btn-mini-danger:hover { background-color: #fecdd3; }
        .btn-mini-primary { background-color: #fef3c7; color: #78350f; }
        .btn-mini-primary:hover { background-color: #fde68a; }

        .orders-empty { text-align: center; padding: 3rem 1.5rem; color: #64748b; }
        .orders-empty .material-symbols-outlined { font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block; }
        .orders-empty a { color: #f59e0b; font-weight: 600; }

        @media (max-width: 900px) {
            .orders-header-bento { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .orders-stats { grid-template-columns: 1fr; }
            .order-row { grid-template-columns: 1fr; gap: 0.35rem; }
            .order-row-head { display: none; }
            .order-actions { justify-content: flex-start; }
        }
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

        <div class="orders-header-bento">
            <div>
                <h1>My Orders</h1>
                <p>Track and manage everything you've ordered from us.</p>
            </div>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (empty($allOrders)): ?>
            <div class="orders-panel">
                <div class="orders-empty">
                    <span class="material-symbols-outlined">inventory_2</span>
                    You haven't placed any orders yet.<br>
                    <a href="products.php">Start shopping &rarr;</a>
                </div>
            </div>
        <?php else: ?>

            <!-- Stat chips -->
            <div class="orders-stats">
                <div class="order-stat-card">
                    <div class="order-stat-top"><span>Total Orders</span><span class="material-symbols-outlined">receipt_long</span></div>
                    <div class="order-stat-value"><?= $totalOrdersCount ?></div>
                    <div class="order-stat-sub"><?= $statusCounts['pending'] + $statusCounts['processing'] ?> active</div>
                </div>
                <div class="order-stat-card">
                    <div class="order-stat-top"><span>Total Spent</span><span class="material-symbols-outlined">payments</span></div>
                    <div class="order-stat-value accent">&#8369;<?= number_format($totalSpent, 2) ?></div>
                    <div class="order-stat-sub">Excludes cancelled orders</div>
                </div>
                <div class="order-stat-card">
                    <div class="order-stat-top"><span>Pending</span><span class="material-symbols-outlined">schedule</span></div>
                    <div class="order-stat-value"><?= $statusCounts['pending'] ?></div>
                    <div class="order-stat-sub">Awaiting confirmation</div>
                </div>
            </div>

            <!-- Filter pills -->
            <div class="orders-toolbar">
                <a href="orders.php" class="status-pill <?= $filter === '' ? 'active' : '' ?>">
                    All <span class="status-pill-count"><?= $totalOrdersCount ?></span>
                </a>
                <?php foreach ($validStatuses as $s): ?>
                    <a href="orders.php?status=<?= $s ?>" class="status-pill <?= $filter === $s ? 'active' : '' ?>">
                        <?= $statusLabels[$s] ?> <span class="status-pill-count"><?= $statusCounts[$s] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Orders list -->
            <div class="orders-panel">
                <div class="order-row order-row-head">
                    <div>Order</div>
                    <div>Items</div>
                    <div>Total</div>
                    <div>Status</div>
                    <div>Date</div>
                    <div></div>
                </div>

                <?php if (empty($orders)): ?>
                    <div class="orders-empty">
                        <span class="material-symbols-outlined">filter_alt_off</span>
                        No <?= htmlspecialchars($statusLabels[$filter] ?? '') ?> orders.
                    </div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <div class="order-row">
                            <div class="order-code">#<?= (int) $order['id'] ?></div>

                            <div>
                                <?php $items = $itemsByOrder[$order['id']] ?? []; ?>
                                <?php if (empty($items)): ?>
                                    <span class="empty-state">No items</span>
                                <?php else: ?>
                                    <details class="order-items-summary">
                                        <summary>
                                            <span class="material-symbols-outlined">chevron_right</span>
                                            <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>
                                        </summary>
                                        <ul class="order-items-list">
                                            <?php foreach ($items as $item): ?>
                                                <li><?= (int) $item['quantity'] ?>&times; <?= htmlspecialchars($item['name']) ?> (&#8369;<?= number_format($item['price'], 2) ?>)</li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>

                            <div class="order-total">&#8369;<?= number_format($order['total_amount'], 2) ?></div>

                            <div class="order-status-cell">
                                <span class="status-badge status-<?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status'])) ?></span>
                                <span class="order-status-hint"><?= htmlspecialchars($statusHints[$order['status']] ?? '') ?></span>
                            </div>

                            <div class="order-date"><?= date('M j, Y', strtotime($order['created_at'])) ?></div>

                            <div class="order-actions">
                                <?php if ($order['status'] === 'pending'): ?>
                                    <a href="orders.php?cancel=<?= (int) $order['id'] ?>" class="btn-mini btn-mini-danger"
                                       onclick="return confirm('Cancel this order?');">
                                        <span class="material-symbols-outlined">close</span> Cancel
                                    </a>
                                <?php elseif (in_array($order['status'], ['completed', 'cancelled'], true)): ?>
                                    <a href="orders.php?reorder=<?= (int) $order['id'] ?>" class="btn-mini btn-mini-primary">
                                        <span class="material-symbols-outlined">replay</span> Reorder
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply &amp; Services. All rights reserved.</p>
    </footer>

</body>
</html>