<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage riders
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// ---- Deactivate / remove a rider ----
// Riders with delivery history aren't hard-deleted (their deliveries still
// need to point somewhere sensible) — instead they're demoted so they can
// no longer log into the rider dashboard, and any deliveries currently
// assigned to them are unassigned.
// This is a POST (with a CSRF token) rather than a GET link, so a stray
// link/image on another page can't deactivate a rider.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $riderId = (int) ($_POST['rider_id'] ?? 0);

        $stmt = $pdo->prepare("UPDATE deliveries SET rider_id = NULL, rider_name = NULL WHERE rider_id = :id AND status IN ('pending', 'out_for_delivery')");
        $stmt->execute(['id' => $riderId]);

        $stmt = $pdo->prepare("UPDATE users SET role = 'customer' WHERE id = :id AND role = 'rider'");
        $stmt->execute(['id' => $riderId]);
    }

    header('Location: riders.php');
    exit;
}

// ---- Load riders with their current delivery load ----
$riders = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.created_at,
            COUNT(d.id) AS active_deliveries
     FROM users u
     LEFT JOIN deliveries d ON d.rider_id = u.id AND d.status IN ('pending', 'out_for_delivery')
     WHERE u.role = 'rider'
     GROUP BY u.id, u.full_name, u.email, u.created_at
     ORDER BY u.full_name ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riders - MPD Electrical Supply &amp; Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- NOTE: order-count-badge is embedded here too, same as customers.php,
         since style.css doesn't define it. -->
    <style>
        .inline-form { display: inline; margin: 0; }
        button.btn-danger { border: none; cursor: pointer; font: inherit; }
        .order-count-badge {
            background-color: #f1f5f9; color: #334155; font-weight: 600; font-size: 0.75rem;
            padding: 0.2rem 0.6rem; border-radius: 9999px;
        }
    </style>
</head>
<body>

    <div class="admin-layout">

        <header class="admin-navbar">
            <div class="logo">MPD Admin</div>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="products.php">Products</a>
                <a href="orders.php">Orders</a>
                <a href="deliveries.php">Deliveries</a>
                <a href="riders.php" class="active">Riders</a>
                <a href="sales.php">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Riders</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

            <p class="field-hint">Riders can only see and update the deliveries assigned to them — they don't get access to the rest of this admin panel. Rider accounts are created by the riders themselves at <code>rider/register.php</code>.</p>

            <section class="admin-panel">
                <div class="admin-panel-v2-head">
                    <h2>Active Riders</h2>
                </div>
                <?php if (empty($riders)): ?>
                    <p class="empty-state">No riders have signed up yet.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Active Deliveries</th>
                                <th>Added</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($riders as $rider): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rider['full_name']) ?></td>
                                    <td><?= htmlspecialchars($rider['email']) ?></td>
                                    <td><span class="order-count-badge"><?= (int) $rider['active_deliveries'] ?></span></td>
                                    <td><?= date('M j, Y', strtotime($rider['created_at'])) ?></td>
                                    <td class="admin-table-actions">
                                        <form method="POST" action="riders.php" class="inline-form"
                                              onsubmit="return confirm('Deactivate this rider? Their pending deliveries will be unassigned.');">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="rider_id" value="<?= (int) $rider['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <button type="submit" class="btn-danger">Deactivate</button>
                                        </form>
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