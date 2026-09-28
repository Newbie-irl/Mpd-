<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may view this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

/**
 * Runs a SELECT and returns an empty array instead of crashing if the
 * table/columns don't exist yet.
 */
function safeRows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

$search = trim($_GET['search'] ?? '');

/**
 * Pulls registered customers along with their order count and lifetime
 * spend. Falls back to a bare-bones query (id/full_name only) if the
 * users table doesn't have email/created_at yet, so this page never
 * hard-crashes while the schema is still evolving.
 */
function getCustomers(PDO $pdo, string $search): array {
    $where = "u.role = 'customer'";
    $params = [];
    if ($search !== '') {
        $where .= " AND (u.full_name LIKE :s OR u.email LIKE :s)";
        $params[':s'] = "%{$search}%";
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.email, u.created_at,
                    COUNT(o.id) AS order_count,
                    COALESCE(SUM(o.total_amount), 0) AS total_spent
             FROM users u
             LEFT JOIN orders o ON o.user_id = u.id
             WHERE {$where}
             GROUP BY u.id, u.full_name, u.email, u.created_at
             ORDER BY u.created_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Fallback for a leaner users table (no email/created_at yet)
        $where = "role = 'customer'";
        $params = [];
        if ($search !== '') {
            $where .= " AND full_name LIKE :s";
            $params[':s'] = "%{$search}%";
        }
        $stmt = $pdo->prepare(
            "SELECT id, full_name, NULL AS email, NULL AS created_at,
                    0 AS order_count, 0 AS total_spent
             FROM users
             WHERE {$where}
             ORDER BY id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

$customers = getCustomers($pdo, $search);
$totalCustomers = count($customers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - MPD Electrical Supply &amp; Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: styles embedded directly here, same approach as dashboard.php,
      so this page always renders correctly regardless of style.css cache.
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

        .dash-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem; flex-wrap: wrap;
        }
        .dash-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .dash-header-bento .field-hint { color: #64748b; font-size: 0.8rem; }

        .customers-search {
            display: flex; align-items: center; gap: 0.5rem;
            background-color: #f1f5f9; border-radius: 9999px;
            padding: 0.45rem 1rem;
        }
        .customers-search .material-symbols-outlined { color: #64748b; font-size: 1.1rem; }
        .customers-search input {
            border: none; background: transparent; outline: none;
            font-size: 0.85rem; color: #0f172a; width: 220px;
        }

        .admin-panel-v2 { background-color: #ffffff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
        .admin-panel-v2-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .admin-panel-v2-head h2 { font-size: 1.05rem; color: #0f172a; }
        .admin-panel-v2-head .field-hint { color: #64748b; font-size: 0.8rem; }

        .admin-table { width: 100%; border-collapse: collapse; }
        .admin-table th {
            text-align: left; font-size: 0.72rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.03em; color: #64748b; padding: 0.6rem 0.75rem; border-bottom: 1px solid #e2e8f0;
        }
        .admin-table td { padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; color: #0f172a; }
        .admin-table tr:last-child td { border-bottom: none; }

        .customer-name-cell { display: flex; align-items: center; gap: 0.6rem; }
        .customer-avatar {
            width: 2rem; height: 2rem; border-radius: 9999px; background-color: #fbbf24;
            color: #0f172a; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.8rem; flex-shrink: 0;
        }
        .customer-email { color: #64748b; font-size: 0.78rem; }
        .order-count-badge {
            background-color: #f1f5f9; color: #334155; font-weight: 600; font-size: 0.75rem;
            padding: 0.2rem 0.6rem; border-radius: 9999px;
        }
        .spend-value { font-family: var(--font-mono); font-weight: 600; }

        .empty-state { color: #64748b; font-size: 0.85rem; padding: 1.5rem 0; text-align: center; }

        @media (max-width: 700px) {
            .customers-search input { width: 140px; }
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
                <a href="riders.php">Riders</a>
                <a href="sales.php">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <!-- Header bento -->
            <div class="dash-header-bento">
                <div>
                    <h1>Customers</h1>
                    <p class="field-hint"><?= $totalCustomers ?> registered customer<?= $totalCustomers === 1 ? '' : 's' ?><?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>.</p>
                </div>
                <form method="get" class="customers-search">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" name="search" placeholder="Search name or email"
                           value="<?= htmlspecialchars($search) ?>">
                </form>
            </div>

            <section class="admin-panel-v2">
                <div class="admin-panel-v2-head">
                    <h2>Registered Customers</h2>
                </div>
                <?php if (empty($customers)): ?>
                    <p class="empty-state">
                        <?= $search !== '' ? 'No customers match your search.' : 'No registered customers yet.' ?>
                    </p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Orders</th>
                                <th>Lifetime Spend</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td>
                                        <div class="customer-name-cell">
                                            <div class="customer-avatar">
                                                <?= htmlspecialchars(strtoupper(substr($customer['full_name'] ?? '?', 0, 1))) ?>
                                            </div>
                                            <span><?= htmlspecialchars($customer['full_name'] ?? 'Unknown') ?></span>
                                        </div>
                                    </td>
                                    <td class="customer-email"><?= htmlspecialchars($customer['email'] ?? '—') ?></td>
                                    <td><span class="order-count-badge"><?= (int) $customer['order_count'] ?></span></td>
                                    <td class="spend-value">&#8369;<?= number_format((float) $customer['total_spent'], 2) ?></td>
                                    <td><?= $customer['created_at'] ? date('M j, Y', strtotime($customer['created_at'])) : '—' ?></td>
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