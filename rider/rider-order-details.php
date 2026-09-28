<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rider') {
    header('Location: ../login.php');
    exit;
}

$riderId = (int) $_SESSION['user_id'];

$validStatuses = ['pending', 'out_for_delivery', 'delivered', 'failed'];
$statusLabels = [
    'pending'          => 'Pending',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'failed'           => 'Failed',
    'cancelled'        => 'Cancelled',
];

$orderId = (int) ($_GET['id'] ?? 0);

// ---- Update status OR notes (two separate actions) ----
// Status and notes used to share one action, so changing the status posted an
// empty notes field and wiped the saved notes. They're separate now, each one
// only touches its own column.
// The WHERE clause always includes rider_id = :rider_id, so a rider can
// never update a delivery that isn't assigned to them, even by guessing an
// order id in the URL/form. Cancelled deliveries are locked (admin-only).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['update_status', 'update_notes'], true)) {
    $postOrderId = (int) ($_POST['order_id'] ?? 0);
    $stmt = null;

    if ($_POST['action'] === 'update_status') {
        $status = $_POST['status'] ?? '';
        if (in_array($status, $validStatuses, true)) {
            $stmt = $pdo->prepare(
                "UPDATE deliveries d
                 JOIN orders o ON o.id = d.order_id
                 SET d.status = :status
                 WHERE o.id = :order_id AND d.rider_id = :rider_id AND d.status <> 'cancelled'"
            );
            $stmt->execute([
                'status'   => $status,
                'order_id' => $postOrderId,
                'rider_id' => $riderId,
            ]);
            $doneMessage = "Order #{$postOrderId} updated to " . $statusLabels[$status] . ".";
        }
    } else {
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $pdo->prepare(
            "UPDATE deliveries d
             JOIN orders o ON o.id = d.order_id
             SET d.notes = :notes
             WHERE o.id = :order_id AND d.rider_id = :rider_id AND d.status <> 'cancelled'"
        );
        $stmt->execute([
            'notes'    => $notes !== '' ? $notes : null,
            'order_id' => $postOrderId,
            'rider_id' => $riderId,
        ]);
        $doneMessage = "Notes for order #{$postOrderId} saved.";
    }

    if ($stmt !== null) {
        // MySQL reports 0 affected rows when the new value equals the old one,
        // so check ownership separately instead of trusting rowCount() alone.
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = $doneMessage;
        } else {
            $check = $pdo->prepare("SELECT status FROM deliveries WHERE order_id = :order_id AND rider_id = :rider_id");
            $check->execute(['order_id' => $postOrderId, 'rider_id' => $riderId]);
            $row = $check->fetch();
            if (!$row) {
                $_SESSION['error'] = "That order isn't assigned to you.";
            } elseif ($row['status'] === 'cancelled') {
                $_SESSION['error'] = "This delivery was cancelled and can no longer be updated.";
            } else {
                $_SESSION['success'] = "No changes to save.";
            }
        }
    }

    header('Location: rider-order-details.php?id=' . $postOrderId);
    exit;
}

// ---- Load the order, scoped to a delivery assigned to me ----
$stmt = $pdo->prepare(
    "SELECT o.*, u.full_name, u.email,
            d.recipient_name, d.address, d.contact_number,
            d.preferred_time, d.scheduled_date, d.status AS delivery_status, d.notes
     FROM orders o
     JOIN users u ON o.user_id = u.id
     JOIN deliveries d ON d.order_id = o.id
     WHERE o.id = :id AND d.rider_id = :rider_id"
);
$stmt->execute(['id' => $orderId, 'rider_id' => $riderId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: rider-dashboard.php');
    exit;
}

// ---- Load line items ----
$items = [];
try {
    $stmt = $pdo->prepare(
        "SELECT oi.quantity, oi.price, p.name
         FROM order_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = :id"
    );
    $stmt->execute(['id' => $orderId]);
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
    <title>Order #<?= (int) $order['id'] ?> - MPD Electrical Supply &amp; Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Rider</div>
            <nav>
                <a href="rider-dashboard.php">My Orders</a>
            </nav>
            <div class="admin-navbar-actions">
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
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-error"><?= htmlspecialchars($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <p><a href="rider-dashboard.php">&larr; Back to My Orders</a></p>

            <section class="admin-panel order-details-grid">

                <div class="order-details-card">
                    <h2>Customer</h2>
                    <p><?= htmlspecialchars($order['full_name']) ?></p>
                    <p class="field-hint"><?= htmlspecialchars($order['email']) ?></p>
                </div>

                <div class="order-details-card">
                    <h2>Delivery</h2>
                    <p><?= htmlspecialchars($order['recipient_name']) ?></p>
                    <?php if ($order['address']): ?>
                        <p class="field-hint"><?= nl2br(htmlspecialchars($order['address'])) ?></p>
                    <?php endif; ?>
                    <?php if ($order['contact_number']): ?>
                        <p class="field-hint"><?= htmlspecialchars($order['contact_number']) ?></p>
                    <?php endif; ?>
                    <?php if ($order['preferred_time']): ?>
                        <p class="field-hint">Preferred time: <?= htmlspecialchars(ucfirst($order['preferred_time'])) ?></p>
                    <?php endif; ?>
                    <?php if ($order['scheduled_date']): ?>
                        <p class="field-hint">Scheduled: <?= date('M j, Y', strtotime($order['scheduled_date'])) ?></p>
                    <?php endif; ?>
                </div>

                <div class="order-details-card">
                    <h2>Status</h2>
                    <form method="POST" action="rider-order-details.php?id=<?= (int) $order['id'] ?>" class="status-form">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <select name="status">
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= $s ?>" <?= $order['delivery_status'] === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-edit">Update</button>
                    </form>
                    <div class="form-group" style="margin-top: 0.75rem;">
                        <label for="notes">Notes</label>
                        <form method="POST" action="rider-order-details.php?id=<?= (int) $order['id'] ?>" class="admin-form">
                            <input type="hidden" name="action" value="update_notes">
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <textarea id="notes" name="notes" rows="2"
                                      placeholder="e.g. left with guard, customer not home"><?= htmlspecialchars($order['notes'] ?? '') ?></textarea>
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">Save Notes</button>
                            </div>
                        </form>
                    </div>
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