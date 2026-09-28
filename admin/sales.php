<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may view the sales report
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// ---- Date range preset ----
// 'completed' counts only finalized sales; 'all' counts anything not cancelled
// (i.e. pending + processing + completed), useful for a "gross" view.
$range        = $_GET['range'] ?? '30';
$statusFilter = $_GET['status'] ?? 'completed';

$validRanges   = ['7', '30', '90', '365', 'all'];
$validStatuses = ['completed', 'all'];
if (!in_array($range, $validRanges, true)) {
    $range = '30';
}
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'completed';
}

$rangeLabels = [
    '7'   => 'Last 7 Days',
    '30'  => 'Last 30 Days',
    '90'  => 'Last 90 Days',
    '365' => 'Last 12 Months',
    'all' => 'All Time',
];

$endDate = new DateTime('tomorrow'); // exclusive upper bound, covers all of "today"
if ($range === 'all') {
    $startDate = null;
} else {
    $startDate = (new DateTime())->modify("-{$range} days");
}

// Shared WHERE clause + params for every query below
$where  = ($statusFilter === 'completed') ? "o.status = 'completed'" : "o.status != 'cancelled'";
$params = [];
if ($startDate) {
    $where .= " AND o.created_at >= :start AND o.created_at < :end";
    $params['start'] = $startDate->format('Y-m-d H:i:s');
    $params['end']   = $endDate->format('Y-m-d H:i:s');
}

// ---- Summary stats ----
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            COALESCE(AVG(o.total_amount), 0) AS avg_order_value
     FROM orders o
     WHERE $where"
);
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(oi.quantity), 0) AS items_sold
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     WHERE $where"
);
$stmt->execute($params);
$itemsSold = (int) $stmt->fetchColumn();

$avgItemsPerOrder = $summary['total_orders'] > 0 ? $itemsSold / $summary['total_orders'] : 0;

// ---- Previous-period comparison ----
// Skipped for "All Time" (there's no "before all time") and for a custom
// range shorter than a day (nothing meaningful to compare against).
$prevSummary   = null;
$comparisonLabel = '';
if ($range !== 'all' && $startDate !== null) {
    $prevEnd   = clone $startDate;
    $prevStart = (clone $startDate)->modify("-{$range} days");

    $prevWhere  = ($statusFilter === 'completed') ? "o.status = 'completed'" : "o.status != 'cancelled'";
    $prevWhere .= " AND o.created_at >= :start AND o.created_at < :end";
    $prevParams = [
        'start' => $prevStart->format('Y-m-d H:i:s'),
        'end'   => $prevEnd->format('Y-m-d H:i:s'),
    ];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount), 0) AS total_revenue, COUNT(*) AS total_orders FROM orders o WHERE $prevWhere");
    $stmt->execute($prevParams);
    $prevSummary = $stmt->fetch();
    $comparisonLabel = 'vs previous ' . $rangeLabels[$range];
}

function pct_change($current, $previous): ?float
{
    if ($previous == 0) {
        return $current > 0 ? null : 0.0; // null signals "new activity, no baseline"
    }
    return (($current - $previous) / $previous) * 100;
}

// ---- Sales trend ----
// Daily bars for shorter ranges, monthly bars once the range gets long
// enough that daily bars would be unreadable (90 days or more / all time).
$groupByMonth = in_array($range, ['90', '365', 'all'], true);

if ($groupByMonth) {
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS period,
                COALESCE(SUM(o.total_amount), 0) AS revenue,
                COUNT(*) AS order_count
         FROM orders o
         WHERE $where
         GROUP BY period
         ORDER BY period ASC"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT DATE(o.created_at) AS period,
                COALESCE(SUM(o.total_amount), 0) AS revenue,
                COUNT(*) AS order_count
         FROM orders o
         WHERE $where
         GROUP BY period
         ORDER BY period ASC"
    );
}
$stmt->execute($params);
$trendRaw = $stmt->fetchAll();

// Fill in the gaps so every day/month in the range renders a bar (even a
// zero-height one) instead of the chart collapsing down to just the one
// or two days that happened to have sales.
$trend = [];
if ($groupByMonth) {
    $revenueByPeriod = [];
    foreach ($trendRaw as $row) {
        $revenueByPeriod[$row['period']] = $row;
    }
    if ($range === 'all' && !empty($trendRaw)) {
        $cursor = new DateTime($trendRaw[0]['period'] . '-01');
        $last   = new DateTime(end($trendRaw)['period'] . '-01');
    } else {
        $cursor = clone $startDate;
        $last   = (clone $endDate)->modify('-1 second');
    }
    while ($cursor <= $last) {
        $key = $cursor->format('Y-m');
        $trend[] = $revenueByPeriod[$key] ?? ['period' => $key, 'revenue' => 0, 'order_count' => 0];
        $cursor->modify('+1 month');
    }
} else {
    $revenueByPeriod = [];
    foreach ($trendRaw as $row) {
        $revenueByPeriod[$row['period']] = $row;
    }
    if ($startDate) {
        $cursor = clone $startDate;
        $last   = (clone $endDate)->modify('-1 day');
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $trend[] = $revenueByPeriod[$key] ?? ['period' => $key, 'revenue' => 0, 'order_count' => 0];
            $cursor->modify('+1 day');
        }
    } else {
        $trend = $trendRaw; // "all time" + daily grouping shouldn't normally happen, but just in case
    }
}

$maxRevenue = 0;
foreach ($trend as $point) {
    $maxRevenue = max($maxRevenue, (float) $point['revenue']);
}
// Thin out x-axis labels once there are too many bars to label legibly.
$labelStep = (int) max(1, ceil(count($trend) / 12));

// ---- Best-selling products ----
$stmt = $pdo->prepare(
    "SELECT p.id, p.name, p.category,
            SUM(oi.quantity) AS units_sold,
            SUM(oi.quantity * oi.price) AS revenue
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     JOIN products p ON oi.product_id = p.id
     WHERE $where
     GROUP BY p.id, p.name, p.category
     ORDER BY units_sold DESC
     LIMIT 10"
);
$stmt->execute($params);
$topProducts = $stmt->fetchAll();
$maxProductRevenue = 0;
foreach ($topProducts as $p) {
    $maxProductRevenue = max($maxProductRevenue, (float) $p['revenue']);
}

// ---- Sales by category ----
$stmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(p.category, ''), 'Uncategorized') AS category,
            SUM(oi.quantity) AS units_sold,
            SUM(oi.quantity * oi.price) AS revenue
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     JOIN products p ON oi.product_id = p.id
     WHERE $where
     GROUP BY category
     ORDER BY revenue DESC"
);
$stmt->execute($params);
$byCategory = $stmt->fetchAll();
$maxCategoryRevenue = 0;
foreach ($byCategory as $c) {
    $maxCategoryRevenue = max($maxCategoryRevenue, (float) $c['revenue']);
}

// Helper to build a query string that preserves the current filters while
// changing/adding a param.
function sales_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'sales.php' . ($qs !== '' ? '?' . $qs : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report - MPD Electrical Supply & Services</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Duplicated here (as on the other admin pages) so this page
      keeps its look even if style.css is stale, partial, or cached. Safe
      to delete once style.css is confirmed to contain the same classes.
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

        .sales-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem;
        }
        .sales-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .sales-header-bento .field-hint { margin-top: 0; }
        .dh-user { display: flex; align-items: center; gap: 0.6rem; background-color: #f1f5f9; padding: 0.4rem 0.9rem 0.4rem 0.4rem; border-radius: 9999px; }
        .dh-avatar { width: 2rem; height: 2rem; border-radius: 9999px; background-color: #fbbf24; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .dh-user-name { font-size: 0.8rem; font-weight: 600; color: #0f172a; line-height: 1.1rem; }
        .dh-user-role { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        .filter-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 9999px; background-color: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 600; transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out; }
        .pill:hover { background-color: #e2e8f0; }
        .pill-active { background-color: #fbbf24; color: #0f172a; }
        .pill-active:hover { background-color: #f59e0b; }

        .sales-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
        .toolbar-right { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
        .count-select-wrap { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #475569; }
        .count-select-wrap select { height: 2.25rem; padding: 0 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; color: #0f172a; }
        .count-select-wrap select:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }


        /* ---- Stat cards + comparison ---- */
        .stats-grid-v2 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin: 1.25rem 0; }
        .stat-card-v2 { background-color: #ffffff; border-radius: 1rem; padding: 1.1rem 1.25rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: flex; flex-direction: column; gap: 0.35rem; }
        .stat-card-v2-top { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .stat-card-v2-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 2rem; }
        .stat-card-v2-sub { font-size: 0.75rem; color: #64748b; font-family: var(--font-mono); }
        .compare-chip { display: inline-flex; align-items: center; gap: 0.15rem; font-size: 0.72rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 0.4rem; }
        .compare-chip .material-symbols-outlined { font-size: 0.85rem; }
        .compare-up { background-color: #d1fae5; color: #065f46; }
        .compare-down { background-color: #ffe4e6; color: #be123c; }
        .compare-flat { background-color: #f1f5f9; color: #64748b; }

        /* ---- Sales trend chart ---- */
        .sales-chart-container { display: grid; grid-template-columns: 56px 1fr; gap: 0.5rem; height: 220px; margin-top: 0.5rem; }
        .sales-chart-yaxis { display: flex; flex-direction: column; justify-content: space-between; text-align: right; font-size: 0.7rem; color: #94a3b8; font-family: var(--font-mono); padding-bottom: 1.4rem; }
        .sales-chart-plot { position: relative; background-image: repeating-linear-gradient(to bottom, #e2e8f0 0, #e2e8f0 1px, transparent 1px, transparent 25%); }
        .sales-chart { display: flex; align-items: flex-end; gap: 3px; height: 100%; padding-bottom: 1.4rem; position: relative; }
        .sales-bar-col { flex: 1 1 0; min-width: 3px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; position: relative; }
        .sales-bar { width: 100%; max-width: 22px; border-radius: 0.25rem 0.25rem 0 0; background-color: #fbbf24; transition: background-color 0.15s ease-in-out; min-height: 2px; }
        .sales-bar-col:hover .sales-bar { background-color: #f59e0b; }
        .sales-bar-label { position: absolute; bottom: -1.3rem; font-size: 0.62rem; color: #94a3b8; white-space: nowrap; transform: rotate(-35deg); transform-origin: top right; }
        .sales-chart-empty-note { font-size: 0.75rem; color: #94a3b8; margin-top: 0.5rem; }

        /* ---- Bars behind table rows ---- */
        .rank-bar-cell { position: relative; }
        .rank-bar-track { position: absolute; left: 0; top: 0; bottom: 0; background-color: #fef3c7; z-index: 0; }
        .rank-bar-cell > * { position: relative; z-index: 1; }

        .admin-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        @media (max-width: 1100px) { .admin-panels { grid-template-columns: 1fr; } }
        .admin-table tbody tr:nth-child(even) { background-color: #f8fafc; }
        .admin-table tbody tr:hover { background-color: #e0f2fe; }

        @media (max-width: 900px) {
            .sales-header-bento { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .sales-toolbar { flex-direction: column; align-items: stretch; }
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
                <a href="sales.php" class="active">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <!-- Header bento -->
            <div class="sales-header-bento">
                <div>
                    <h1>Sales Report</h1>
                    <p class="field-hint">
                        <?= htmlspecialchars($rangeLabels[$range]) ?>
                    </p>
                </div>
                <div class="dh-user">
                    <div class="dh-avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1))) ?></div>
                    <div>
                        <div class="dh-user-name">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        <div class="dh-user-role">Admin</div>
                    </div>
                </div>
            </div>

            <section class="admin-panel">
                <div class="sales-toolbar">
                    <div class="filter-pills">
                        <?php foreach ($rangeLabels as $value => $label): ?>
                            <a href="<?= htmlspecialchars(sales_url(['range' => $value])) ?>"
                               class="pill <?= $range === $value ? 'pill-active' : '' ?>"><?= $label ?></a>
                        <?php endforeach; ?>
                    </div>

                    <div class="toolbar-right">
                        <form method="GET" action="sales.php" class="count-select-wrap">
                            <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                            <label for="status">Count:</label>
                            <select id="status" name="status" onchange="this.form.submit()">
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed orders only</option>
                                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All orders (excluding cancelled)</option>
                            </select>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Summary stats with previous-period comparison -->
            <section class="stats-grid-v2">
                <?php
                    $revChange = $prevSummary ? pct_change((float) $summary['total_revenue'], (float) $prevSummary['total_revenue']) : null;
                    $ordChange = $prevSummary ? pct_change((float) $summary['total_orders'], (float) $prevSummary['total_orders']) : null;
                    function render_compare_chip(?float $pct): string
                    {
                        if ($pct === null) {
                            return '<span class="compare-chip compare-flat">New activity</span>';
                        }
                        $dir = $pct > 0.05 ? 'up' : ($pct < -0.05 ? 'down' : 'flat');
                        $icon = $dir === 'up' ? 'trending_up' : ($dir === 'down' ? 'trending_down' : 'trending_flat');
                        $sign = $pct > 0 ? '+' : '';
                        return '<span class="compare-chip compare-' . $dir . '"><span class="material-symbols-outlined">' . $icon . '</span>' . $sign . number_format($pct, 1) . '%</span>';
                    }
                ?>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">Total Revenue</div>
                    <div class="stat-card-v2-value">&#8369;<?= number_format($summary['total_revenue'], 2) ?></div>
                    <?php if ($prevSummary): ?>
                        <div><?= render_compare_chip($revChange) ?></div>
                        <div class="stat-card-v2-sub"><?= htmlspecialchars($comparisonLabel) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">Orders</div>
                    <div class="stat-card-v2-value"><?= (int) $summary['total_orders'] ?></div>
                    <?php if ($prevSummary): ?>
                        <div><?= render_compare_chip($ordChange) ?></div>
                        <div class="stat-card-v2-sub"><?= htmlspecialchars($comparisonLabel) ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">Average Order Value</div>
                    <div class="stat-card-v2-value">&#8369;<?= number_format($summary['avg_order_value'], 2) ?></div>
                    <div class="stat-card-v2-sub">Per order</div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">Items Sold</div>
                    <div class="stat-card-v2-value"><?= $itemsSold ?></div>
                    <div class="stat-card-v2-sub"><?= number_format($avgItemsPerOrder, 1) ?> avg per order</div>
                </div>
            </section>

            <section class="admin-panel">
                <h2>Sales Trend &mdash; <?= htmlspecialchars($rangeLabels[$range]) ?></h2>

                <?php if (empty($trend) || $maxRevenue == 0): ?>
                    <p class="empty-state">No sales in this period.</p>
                <?php else: ?>
                    <div class="sales-chart-container">
                        <div class="sales-chart-yaxis">
                            <span>&#8369;<?= number_format($maxRevenue, 0) ?></span>
                            <span>&#8369;<?= number_format($maxRevenue / 2, 0) ?></span>
                            <span>&#8369;0</span>
                        </div>
                        <div class="sales-chart-plot">
                            <div class="sales-chart">
                                <?php foreach ($trend as $i => $point):
                                    $heightPct = $maxRevenue > 0 ? ($point['revenue'] / $maxRevenue) * 100 : 0;
                                    $periodLabel = $groupByMonth
                                        ? date('M Y', strtotime($point['period'] . '-01'))
                                        : date('M j', strtotime($point['period']));
                                    $showLabel = $i % $labelStep === 0 || $i === count($trend) - 1;
                                ?>
                                    <div class="sales-bar-col" title="<?= htmlspecialchars($periodLabel) ?>: &#8369;<?= number_format($point['revenue'], 2) ?> (<?= (int) $point['order_count'] ?> orders)">
                                        <div class="sales-bar" style="height: <?= max(1, $heightPct) ?>%;"></div>
                                        <?php if ($showLabel): ?>
                                            <span class="sales-bar-label"><?= htmlspecialchars($periodLabel) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <p class="sales-chart-empty-note">Hover a bar for exact figures. Days with no sales still show as empty bars so the timeline stays proportional.</p>
                <?php endif; ?>
            </section>

            <div class="admin-panels">

                <section class="admin-panel">
                    <h2>Best-Selling Products</h2>
                    <?php if (empty($topProducts)): ?>
                        <p class="empty-state">No product sales in this period.</p>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Units Sold</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topProducts as $i => $product): ?>
                                    <?php $barPct = $maxProductRevenue > 0 ? ($product['revenue'] / $maxProductRevenue) * 100 : 0; ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td class="rank-bar-cell">
                                            <div class="rank-bar-track" style="width: <?= $barPct ?>%"></div>
                                            <?= htmlspecialchars($product['name']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($product['category'] ?: '—') ?></td>
                                        <td><?= (int) $product['units_sold'] ?></td>
                                        <td>&#8369;<?= number_format($product['revenue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-panel">
                    <h2>Sales by Category</h2>
                    <?php if (empty($byCategory)): ?>
                        <p class="empty-state">No product sales in this period.</p>
                    <?php else: ?>
                        <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Units Sold</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byCategory as $cat): ?>
                                    <?php $barPct = $maxCategoryRevenue > 0 ? ($cat['revenue'] / $maxCategoryRevenue) * 100 : 0; ?>
                                    <tr>
                                        <td class="rank-bar-cell">
                                            <div class="rank-bar-track" style="width: <?= $barPct ?>%"></div>
                                            <?= htmlspecialchars($cat['category']) ?>
                                        </td>
                                        <td><?= (int) $cat['units_sold'] ?></td>
                                        <td>&#8369;<?= number_format($cat['revenue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

        </main>
    </div>

</body>
</html>