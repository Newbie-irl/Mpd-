<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage deliveries
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$validStatuses = ['pending', 'out_for_delivery', 'delivered', 'failed'];

// Delivery time slots (must match the options offered at checkout)
$timeSlots = [
    'morning'   => 'Morning (8:00 AM - 12:00 PM)',
    'afternoon' => 'Afternoon (12:00 PM - 4:00 PM)',
    'evening'   => 'Evening (4:00 PM - 8:00 PM)',
];

// Display labels + CSS classes. The classes reuse the existing status-badge
// styles (out_for_delivery -> "processing", delivered -> "completed",
// failed -> "cancelled") so no new CSS is needed.
$statusLabels = [
    'pending'          => 'Pending',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'failed'           => 'Failed',
];
$statusClasses = [
    'pending'          => 'pending',
    'out_for_delivery' => 'processing',
    'delivered'        => 'completed',
    'failed'           => 'cancelled',
];

$errors  = [];
$success = '';

// ---- Update delivery details ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id            = (int) ($_POST['id'] ?? 0);
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $riderName     = trim($_POST['rider_name'] ?? '');
    $status        = $_POST['status'] ?? '';
    $scheduledDate = $_POST['scheduled_date'] ?? '';
    $preferredTime = $_POST['preferred_time'] ?? '';
    $notes         = trim($_POST['notes'] ?? '');

    if ($recipientName === '') {
        $errors[] = "Recipient name is required.";
    }
    if (!in_array($status, $validStatuses, true)) {
        $errors[] = "Please choose a valid status.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "UPDATE deliveries
             SET recipient_name = :recipient_name, address = :address,
                 contact_number = :contact_number, rider_name = :rider_name,
                 status = :status, scheduled_date = :scheduled_date,
                 preferred_time = :preferred_time, notes = :notes
             WHERE id = :id"
        );
        $stmt->execute([
            'recipient_name' => $recipientName,
            'address'        => $address !== '' ? $address : null,
            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
            'rider_name'     => $riderName !== '' ? $riderName : null,
            'status'         => $status,
            'scheduled_date' => $scheduledDate !== '' ? $scheduledDate : null,
            'preferred_time' => array_key_exists($preferredTime, $timeSlots) ? $preferredTime : null,
            'notes'          => $notes !== '' ? $notes : null,
            'id'             => $id,
        ]);
        $success = "Delivery updated.";
    }
}

// ---- Delete ----
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM deliveries WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['delete']]);
    header('Location: deliveries.php');
    exit;
}

// ---- Optional status filter ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

// ---- Load delivery being edited (if any) ----
$editDelivery = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editDelivery = $stmt->fetch();
}

// ---- Load deliveries (with order + customer info) ----
$sql = "SELECT d.*, o.total_amount, o.created_at AS order_date, u.full_name, u.email
        FROM deliveries d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON o.user_id = u.id";
$params = [];
if ($filter !== '') {
    $sql .= " WHERE d.status = :status";
    $params['status'] = $filter;
}
$sql .= " ORDER BY d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Deliveries - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Admin</div>
          <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="products.php">Products</a>
    <a href="orders.php">Orders</a>
    <a href="deliveries.php" class="active">Deliveries</a>
</nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Deliveries</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <p class="field-hint">Delivery coverage: Baliuag, Bulacan only.</p>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($editDelivery): ?>
                <section class="admin-panel">
                    <h2>Edit Delivery &mdash; Order #<?= (int) $editDelivery['order_id'] ?></h2>

                    <form action="deliveries.php" method="POST" class="admin-form">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= (int) $editDelivery['id'] ?>">

                        <div class="form-group">
                            <label for="recipient_name">Recipient Name</label>
                            <input type="text" id="recipient_name" name="recipient_name" required
                                   value="<?= htmlspecialchars($editDelivery['recipient_name']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="address">Delivery Address</label>
                            <textarea id="address" name="address" rows="2" placeholder="e.g. Poblacion, Baliuag, Bulacan"><?= htmlspecialchars($editDelivery['address'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="contact_number">Contact Number</label>
                                <input type="text" id="contact_number" name="contact_number"
                                       value="<?= htmlspecialchars($editDelivery['contact_number'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="rider_name">Assigned Rider</label>
                                <input type="text" id="rider_name" name="rider_name"
                                       value="<?= htmlspecialchars($editDelivery['rider_name'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <?php foreach ($validStatuses as $s): ?>
                                        <option value="<?= $s ?>" <?= $editDelivery['status'] === $s ? 'selected' : '' ?>><?= $statusLabels[$s] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="scheduled_date">Scheduled Date</label>
                                <input type="date" id="scheduled_date" name="scheduled_date"
                                       value="<?= htmlspecialchars($editDelivery['scheduled_date'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="preferred_time">Preferred Delivery Time</label>
                            <select id="preferred_time" name="preferred_time">
                                <option value="">&mdash;</option>
                                <?php foreach ($timeSlots as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"
                                        <?= ($editDelivery['preferred_time'] ?? '') === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($editDelivery['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Update Delivery</button>
                            <a href="deliveries.php<?= $filter ? '?status=' . urlencode($filter) : '' ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="admin-panel">
                <div class="filter-tabs">
                    <a href="deliveries.php" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
                    <?php foreach ($validStatuses as $s): ?>
                        <a href="deliveries.php?status=<?= $s ?>" class="<?= $filter === $s ? 'active' : '' ?>"><?= $statusLabels[$s] ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($deliveries)): ?>
                    <p class="empty-state">No deliveries <?= $filter ? 'with this status' : 'yet' ?>.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Recipient / Address</th>
                                <th>Contact</th>
                                <th>Preferred Time</th>
                                <th>Rider</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $delivery): ?>
                                <tr>
                                    <td>
                                        #<?= (int) $delivery['order_id'] ?>
                                        <div class="field-hint">&#8369;<?= number_format($delivery['total_amount'], 2) ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($delivery['full_name']) ?>
                                        <div class="field-hint"><?= htmlspecialchars($delivery['email']) ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($delivery['recipient_name']) ?>
                                        <?php if ($delivery['address']): ?>
                                            <div class="field-hint"><?= htmlspecialchars($delivery['address']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $delivery['contact_number'] ? htmlspecialchars($delivery['contact_number']) : '&mdash;' ?></td>
                                    <td><?= !empty($delivery['preferred_time']) && isset($timeSlots[$delivery['preferred_time']]) ? htmlspecialchars($timeSlots[$delivery['preferred_time']]) : '&mdash;' ?></td>
                                    <td>
                                        <?php if ($delivery['rider_name']): ?>
                                            <?= htmlspecialchars($delivery['rider_name']) ?>
                                        <?php else: ?>
                                            <span class="empty-state">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $statusClasses[$delivery['status']] ?>">
                                            <?= $statusLabels[$delivery['status']] ?>
                                        </span>
                                    </td>
                                    <td><?= $delivery['scheduled_date'] ? date('M j, Y', strtotime($delivery['scheduled_date'])) : '&mdash;' ?></td>
                                    <td class="admin-table-actions">
                                        <a href="deliveries.php?edit=<?= (int) $delivery['id'] ?>" class="btn-edit">Edit</a>
                                        <a href="deliveries.php?delete=<?= (int) $delivery['id'] ?>" class="btn-danger"
                                           onclick="return confirm('Delete this delivery record? This cannot be undone.');">Delete</a>
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