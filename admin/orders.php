<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage orders
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Order status comes from deliveries.status (single source of truth).
// Valid statuses, labels and CSS classes are shared with the other pages.
require_once '../config/delivery_status.php';

/**
 * Checks whether a table exists (and is queryable) without crashing the
 * page — used so optional features (order items, deliveries) quietly turn
 * themselves off if those tables aren't there yet, same spirit as the
 * safeCount() helper on dashboard.php.
 */
function tableExists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Same idea but for a specific column on a table that does exist —
 * used for optional columns like orders.payment_method.
 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $pdo->query("SELECT $column FROM $table LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasOrderItems    = tableExists($pdo, 'order_items');
$hasPaymentMethod = columnExists($pdo, 'orders', 'payment_method');

// NOTE: there is intentionally no status update here anymore. Status is
// managed on the Deliveries page (deliveries.status is the single source of truth).

// ---- Delete ----
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['delete']]);
    header('Location: orders.php');
    exit;
}

// ---- Filters ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

$allowedDateFilters = ['', 'today', 'tomorrow', 'week', 'custom'];
$dateFilter = $_GET['date'] ?? '';
if (!in_array($dateFilter, $allowedDateFilters, true)) {
    $dateFilter = '';
}
$customStart = $_GET['start_date'] ?? '';
$customEnd   = $_GET['end_date'] ?? '';

$search = trim($_GET['q'] ?? '');

// ---- Build the orders query ----
$selectExtra = '';
if ($hasOrderItems) {
    $selectExtra .= ", (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS item_count";
}
// Status = the delivery's status. An order with no delivery row yet counts as pending.
$selectExtra .= ", d.id AS delivery_id, COALESCE(d.status, 'pending') AS delivery_status";

$sql = "SELECT o.*, u.full_name, u.email $selectExtra
        FROM orders o
        JOIN users u ON o.user_id = u.id
        LEFT JOIN deliveries d ON d.order_id = o.id";

$where  = [];
$params = [];

if ($filter !== '') {
    $where[] = "COALESCE(d.status, 'pending') = :status";
    $params['status'] = $filter;
}

if ($dateFilter === 'today') {
    $where[] = "DATE(o.created_at) = :date_start";
    $params['date_start'] = date('Y-m-d');
} elseif ($dateFilter === 'tomorrow') {
    $where[] = "DATE(o.created_at) = :date_start";
    $params['date_start'] = date('Y-m-d', strtotime('+1 day'));
} elseif ($dateFilter === 'week') {
    $where[] = "DATE(o.created_at) BETWEEN :date_start AND :date_end";
    $params['date_start'] = date('Y-m-d');
    $params['date_end']   = date('Y-m-d', strtotime('+7 day'));
} elseif ($dateFilter === 'custom' && $customStart !== '' && $customEnd !== '') {
    $where[] = "DATE(o.created_at) BETWEEN :date_start AND :date_end";
    $params['date_start'] = $customStart;
    $params['date_end']   = $customEnd;
}

if ($search !== '') {
    $where[] = "(u.full_name LIKE :q1 OR u.email LIKE :q2 OR o.id LIKE :q3)";
    $searchTerm = '%' . $search . '%';
    $params['q1'] = $searchTerm;
    $params['q2'] = $searchTerm;
    $params['q3'] = $searchTerm;
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY o.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allFiltered = $stmt->fetchAll();

// ---- Stats for the summary cards + filter pill counts (based on the
// currently filtered list, same convention used on Products/Deliveries) ----
$statCounts = array_fill_keys($validStatuses, 0);
$totalSales = 0.0;
foreach ($allFiltered as $o) {
    if (isset($statCounts[$o['delivery_status']])) {
        $statCounts[$o['delivery_status']]++;
    }
    if ($o['delivery_status'] !== 'cancelled') {
        $totalSales += (float) $o['total_amount'];
    }
}
$totalCount     = count($allFiltered);
$nonCancelled   = $totalCount - $statCounts['cancelled'];
$avgOrderValue  = $nonCancelled > 0 ? $totalSales / $nonCancelled : 0.0;
$needsAttention = $statCounts['pending'] + $statCounts['out_for_delivery'];

// ---- Pagination ----
$perPage    = 10;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;
$orders     = array_slice($allFiltered, $offset, $perPage);

// Helper to build a query string that preserves the current filters while
// changing one param (e.g. page number).
function orders_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'orders.php' . ($qs !== '' ? '?' . $qs : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - MPD Electrical Supply & Services</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Duplicated here (as on Deliveries/Products/Dashboard) so this
      page keeps its look even if style.css is stale, partial, or cached.
      Safe to delete once style.css is confirmed to contain the same
      classes.
    -->
    <style>
        :root {
            --color-emerald-600: #059669;
            --color-rose-600: #e11d48;
            --color-sky-600: #0284c7;
            --font-mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 1.125rem;
            line-height: 1;
            vertical-align: middle;
        }

        /* ---- Header bento ---- */
        .orders-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem;
        }
        .orders-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .orders-header-bento .field-hint { margin-top: 0; }
        .dh-user { display: flex; align-items: center; gap: 0.6rem; background-color: #f1f5f9; padding: 0.4rem 0.9rem 0.4rem 0.4rem; border-radius: 9999px; }
        .dh-avatar { width: 2rem; height: 2rem; border-radius: 9999px; background-color: #fbbf24; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .dh-user-name { font-size: 0.8rem; font-weight: 600; color: #0f172a; line-height: 1.1rem; }
        .dh-user-role { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        /* ---- Stat cards ---- */
        .stats-grid-v2 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .stat-card-v2 { background-color: #ffffff; border-radius: 1rem; padding: 1.1rem 1.25rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: flex; flex-direction: column; gap: 0.35rem; }
        .stat-card-v2-top { display: flex; align-items: center; justify-content: space-between; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .stat-card-v2-top .material-symbols-outlined { color: #f59e0b; font-size: 1.1rem; }
        .stat-card-v2-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 2rem; }
        .stat-card-v2-sub { font-size: 0.75rem; color: #64748b; font-family: var(--font-mono); }
        .stat-card-v2-danger .stat-card-v2-top, .stat-card-v2-danger .stat-card-v2-value { color: #be123c; }
        .stat-card-v2-danger .stat-card-v2-top .material-symbols-outlined { color: #be123c; }

        /* ---- Toolbar: status pills + search + date select ---- */
        .orders-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
        .filter-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 9999px; background-color: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 600; transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out; }
        .pill:hover { background-color: #e2e8f0; }
        .pill-dot { width: 7px; height: 7px; border-radius: 9999px; flex-shrink: 0; display: inline-block; }
        .pill-dot-pending { background-color: #f59e0b; }
        .pill-dot-processing { background-color: var(--color-sky-600); }
        .pill-dot-completed { background-color: var(--color-emerald-600); }
        .pill-dot-cancelled { background-color: var(--color-rose-600); }
        .pill-dot-all { background-color: #0f172a; }
        .pill-count { font-family: var(--font-mono); font-size: 0.65rem; font-weight: 700; background-color: #ffffff; color: #475569; padding: 0.05rem 0.4rem; border-radius: 9999px; }
        .pill-active { background-color: #fbbf24; color: #0f172a; }
        .pill-active .pill-count { background-color: rgba(255, 255, 255, 0.5); color: #0f172a; }
        .pill-active:hover { background-color: #f59e0b; }

        .toolbar-right { display: flex; align-items: stretch; gap: 0.5rem; flex-wrap: wrap; }
        .search-box { position: relative; min-width: 240px; }
        .search-box .search-icon { position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem; color: #94a3b8; pointer-events: none; }
        .search-box input { width: 100%; height: 2.25rem; padding: 0 0.75rem 0 2.1rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; }
        .search-box input:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }
        .date-select-wrap { position: relative; }
        .date-select { height: 2.25rem; padding: 0 1.8rem 0 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; color: #0f172a; appearance: none; cursor: pointer; }
        .date-select:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }
        .date-select-wrap .material-symbols-outlined { position: absolute; right: 0.6rem; top: 50%; transform: translateY(-50%); font-size: 1rem; color: #94a3b8; pointer-events: none; }
        .custom-date-row { display: flex; align-items: center; gap: 0.4rem; }
        .custom-date-row input[type="date"] { height: 2.25rem; padding: 0 0.6rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.8rem; background-color: #f1f5f9; }

        /* ---- Status badges + inline update form ---- */
        .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.65rem; border-radius: 9999px; text-transform: capitalize; margin-bottom: 0.4rem; }
        .status-dot { width: 6px; height: 6px; border-radius: 9999px; flex-shrink: 0; }
        .status-dot-pending { background-color: #f59e0b; }
        .status-dot-processing { background-color: var(--color-sky-600); }
        .status-dot-completed { background-color: var(--color-emerald-600); }
        .status-dot-cancelled { background-color: var(--color-rose-600); }
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-processing { background-color: #e0f2fe; color: #0369a1; }
        .status-completed { background-color: #d1fae5; color: #065f46; }
        .status-cancelled { background-color: #ffe4e6; color: #be123c; }
        .status-form { display: flex; flex-direction: column; gap: 0.35rem; align-items: flex-start; }
        .status-form-row { display: flex; gap: 0.4rem; }
        .status-form select { height: 2rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.8rem; padding: 0 0.4rem; background-color: #ffffff; }

        .item-count-chip { font-size: 0.7rem; color: #64748b; font-family: var(--font-mono); }
        .payment-chip { display: inline-block; font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 0.4rem; background-color: #f1f5f9; color: #0f172a; text-transform: capitalize; }
        .delivery-link { display: inline-flex; align-items: center; gap: 0.2rem; font-size: 0.75rem; color: #0369a1; font-weight: 600; }
        .delivery-link .material-symbols-outlined { font-size: 0.95rem; }

        .admin-table tbody tr:nth-child(even) { background-color: #f8fafc; }
        .admin-table tbody tr:hover { background-color: #e0f2fe; }
        .admin-table tbody tr.row-urgent { box-shadow: inset 3px 0 0 0 #f59e0b; }
        .row-urgent-tag { display: inline-flex; align-items: center; gap: 0.2rem; font-size: 0.65rem; font-weight: 700; color: #92400e; background-color: #fef3c7; padding: 0.1rem 0.4rem; border-radius: 0.4rem; margin-top: 0.25rem; }
        .row-urgent-tag .material-symbols-outlined { font-size: 0.8rem; }

        .table-footer { padding: 0.85rem 1rem; background-color: #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; font-size: 0.8rem; color: #64748b; border-radius: 0 0 1rem 1rem; }
        .pagination { display: flex; align-items: center; gap: 0.25rem; }
        .page-nav-btn { height: 2rem; padding: 0 0.65rem; border-radius: 0.6rem; background-color: #ffffff; color: #475569; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: inline-flex; align-items: center; gap: 0.2rem; font-size: 0.8rem; font-weight: 600; }
        .page-nav-btn:hover { color: #0f172a; }
        .page-nav-btn.disabled { opacity: 0.4; pointer-events: none; }
        .page-btn { width: 2rem; height: 2rem; border-radius: 0.6rem; background-color: #ffffff; color: #0f172a; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; }
        .page-btn:hover { background-color: #e2e8f0; }
        .page-btn-active { background-color: #fbbf24; color: #0f172a; }
        .page-btn-active:hover { background-color: #fbbf24; }
        .page-ellipsis { padding: 0 0.25rem; color: #94a3b8; }

        @media (max-width: 900px) {
            .orders-header-bento { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .orders-toolbar { flex-direction: column; align-items: stretch; }
            .search-box input { width: 100%; }
            .table-footer { flex-direction: column; align-items: stretch; gap: 0.75rem; }
            .pagination { justify-content: center; }
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

            <!-- Header bento -->
            <div class="orders-header-bento">
                <div>
                    <h1>Orders</h1>
                    <p class="field-hint">Track customer orders. Status is managed in Deliveries.</p>
                </div>
                <div class="dh-user">
                    <div class="dh-avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1))) ?></div>
                    <div>
                        <div class="dh-user-name">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        <div class="dh-user-role">Admin</div>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <section class="stats-grid-v2">
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Total Orders</span>
                        <span class="material-symbols-outlined">receipt_long</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $totalCount ?></div>
                    <div class="stat-card-v2-sub"><?= (int) $statCounts['delivered'] ?> delivered</div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Total Sales</span>
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                    <div class="stat-card-v2-value">&#8369;<?= number_format($totalSales, 2) ?></div>
                    <div class="stat-card-v2-sub">Excludes cancelled orders</div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Avg Order Value</span>
                        <span class="material-symbols-outlined">trending_up</span>
                    </div>
                    <div class="stat-card-v2-value">&#8369;<?= number_format($avgOrderValue, 2) ?></div>
                    <div class="stat-card-v2-sub">Per non-cancelled order</div>
                </div>
                <div class="stat-card-v2 <?= $needsAttention > 0 ? 'stat-card-v2-danger' : '' ?>">
                    <div class="stat-card-v2-top">
                        <span>Needs Attention</span>
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $needsAttention ?></div>
                    <div class="stat-card-v2-sub"><?= (int) $statCounts['pending'] ?> pending &middot; <?= (int) $statCounts['out_for_delivery'] ?> out for delivery</div>
                </div>
            </section>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <section class="admin-panel">

                <!-- Filter pills + search + date select -->
                <div class="orders-toolbar">
                    <div class="filter-pills">
                        <a href="<?= htmlspecialchars(orders_url(['status' => null, 'page' => null])) ?>" class="pill <?= $filter === '' ? 'pill-active' : '' ?>">
                            <span class="pill-dot pill-dot-all"></span>
                            All <span class="pill-count"><?= (int) $totalCount ?></span>
                        </a>
                        <?php foreach ($validStatuses as $s): ?>
                            <a href="<?= htmlspecialchars(orders_url(['status' => $s, 'page' => null])) ?>" class="pill <?= $filter === $s ? 'pill-active' : '' ?>">
                                <span class="pill-dot pill-dot-<?= $statusClasses[$s] ?>"></span>
                                <?= $statusLabels[$s] ?>
                                <span class="pill-count"><?= (int) $statCounts[$s] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="toolbar-right">
                        <div class="search-box">
                            <span class="material-symbols-outlined search-icon">search</span>
                            <input type="text" id="orderSearch" placeholder="Search order #, customer, email..." value="<?= htmlspecialchars($search) ?>">
                        </div>

                        <form method="GET" id="dateFilterForm" class="date-select-wrap">
                            <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
                            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                            <select name="date" id="dateFilterSelect" class="date-select" onchange="this.form.submit()">
                                <option value="" <?= $dateFilter === '' ? 'selected' : '' ?>>All Dates</option>
                                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today (<?= date('M j') ?>)</option>
                                <option value="tomorrow" <?= $dateFilter === 'tomorrow' ? 'selected' : '' ?>>Tomorrow (<?= date('M j', strtotime('+1 day')) ?>)</option>
                                <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="custom" <?= $dateFilter === 'custom' ? 'selected' : '' ?>>Custom Range...</option>
                            </select>
                            <span class="material-symbols-outlined">calendar_today</span>
                        </form>
                    </div>
                </div>

                <?php if ($dateFilter === 'custom'): ?>
                    <form method="GET" class="custom-date-row" style="margin-bottom: 1rem;">
                        <input type="hidden" name="date" value="custom">
                        <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
                        <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($customStart) ?>" required>
                        <span class="field-hint">to</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($customEnd) ?>" required>
                        <button type="submit" class="btn-primary" style="padding: 0.4rem 1rem;">Apply</button>
                    </form>
                <?php endif; ?>

                <?php if (empty($orders)): ?>
                    <p class="empty-state">No orders match these filters.</p>
                <?php else: ?>
                    <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <?php if ($hasPaymentMethod): ?><th>Payment</th><?php endif; ?>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                    $isUrgent = in_array($order['delivery_status'], ['pending', 'out_for_delivery'], true)
                                        && strtotime($order['created_at']) <= strtotime('-3 days');
                                ?>
                                <tr class="<?= $isUrgent ? 'row-urgent' : '' ?>">
                                    <td>
                                        #<?= (int) $order['id'] ?>
                                        <?php if ($hasOrderItems && isset($order['item_count'])): ?>
                                            <div class="item-count-chip"><?= (int) $order['item_count'] ?> item<?= (int) $order['item_count'] === 1 ? '' : 's' ?></div>
                                        <?php endif; ?>
                                        <?php if ($isUrgent): ?>
                                            <div class="row-urgent-tag"><span class="material-symbols-outlined">schedule</span> 3+ days</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($order['full_name']) ?>
                                        <div class="field-hint"><?= htmlspecialchars($order['email']) ?></div>
                                    </td>
                                    <td>&#8369;<?= number_format($order['total_amount'], 2) ?></td>
                                    <?php if ($hasPaymentMethod): ?>
                                        <td>
                                            <?php if (!empty($order['payment_method'])): ?>
                                                <span class="payment-chip"><?= htmlspecialchars($order['payment_method']) ?></span>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="status-badge status-<?= $statusClasses[$order['delivery_status']] ?>">
                                            <span class="status-dot status-dot-<?= $statusClasses[$order['delivery_status']] ?>"></span>
                                            <?= $statusLabels[$order['delivery_status']] ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                                    <td class="admin-table-actions">
                                        <a href="order-details.php?id=<?= (int) $order['id'] ?>" class="btn-edit">View</a>
                                        <?php if (!empty($order['delivery_id'])): ?>
                                            <a href="deliveries.php?edit=<?= (int) $order['delivery_id'] ?>" class="delivery-link" title="Update status in Deliveries">
                                                <span class="material-symbols-outlined">local_shipping</span> Update Status
                                            </a>
                                        <?php endif; ?>
                                        <a href="orders.php?delete=<?= (int) $order['id'] ?>" class="btn-danger"
                                           onclick="return confirm('Delete this order? This cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="table-footer">
                        <div>
                            Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $perPage, $totalCount) ?></strong> of <strong><?= (int) $totalCount ?></strong> orders
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <a class="page-nav-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(orders_url(['page' => max(1, $page - 1)])) ?>">
                                <span class="material-symbols-outlined">chevron_left</span> Previous
                            </a>

                            <?php
                                $windowStart = max(1, $page - 1);
                                $windowEnd   = min($totalPages, $page + 1);
                            ?>
                            <?php if ($windowStart > 1): ?>
                                <a class="page-btn" href="<?= htmlspecialchars(orders_url(['page' => 1])) ?>">1</a>
                                <?php if ($windowStart > 2): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <a class="page-btn <?= $p === $page ? 'page-btn-active' : '' ?>" href="<?= htmlspecialchars(orders_url(['page' => $p])) ?>"><?= $p ?></a>
                            <?php endfor; ?>

                            <?php if ($windowEnd < $totalPages): ?>
                                <?php if ($windowEnd < $totalPages - 1): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                                <a class="page-btn" href="<?= htmlspecialchars(orders_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                            <?php endif; ?>

                            <a class="page-nav-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(orders_url(['page' => min($totalPages, $page + 1)])) ?>">
                                Next <span class="material-symbols-outlined">chevron_right</span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <script>
        // Search submits to the server (so it works together with the
        // status filter, date filter, and pagination) after a short pause.
        (function () {
            var input = document.getElementById('orderSearch');
            if (!input) return;

            var timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                var value = input.value;
                timer = setTimeout(function () {
                    var url = new URL(window.location.href);
                    if (value.trim() === '') {
                        url.searchParams.delete('q');
                    } else {
                        url.searchParams.set('q', value.trim());
                    }
                    url.searchParams.delete('page');
                    window.location.href = url.toString();
                }, 500);
            });
        })();
    </script>

</body>
</html>