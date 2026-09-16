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
// failed -> "cancelled") so no new CSS is needed for the badges themselves.
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
// Small icon per status, used in the stat cards
$statusIcons = [
    'pending'          => 'schedule',
    'out_for_delivery' => 'local_shipping',
    'delivered'        => 'task_alt',
    'failed'           => 'warning',
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

// ---- Status filter ----
$filter = $_GET['status'] ?? '';
if ($filter !== '' && !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

// ---- Date filter (Today / Tomorrow / This Week / Custom) ----
$allowedDateFilters = ['', 'today', 'tomorrow', 'week', 'custom'];
$dateFilter = $_GET['date'] ?? '';
if (!in_array($dateFilter, $allowedDateFilters, true)) {
    $dateFilter = '';
}
$customStart = $_GET['start_date'] ?? '';
$customEnd   = $_GET['end_date'] ?? '';

$periodLabels = [
    ''         => 'All Orders',
    'today'    => 'Today Orders',
    'tomorrow' => "Tomorrow's Orders",
    'week'     => "This Week's Orders",
    'custom'   => 'Selected Range',
];
$periodLabel = $periodLabels[$dateFilter];

// ---- Search term (also applied server-side so pagination stays correct) ----
$search = trim($_GET['q'] ?? '');

// ---- Load delivery being edited (if any) ----
$editDelivery = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editDelivery = $stmt->fetch();
}

// ---- Build the deliveries query ----
$sql = "SELECT d.*, o.total_amount, o.created_at AS order_date, u.full_name, u.email
        FROM deliveries d
        JOIN orders o ON d.order_id = o.id
        JOIN users u ON o.user_id = u.id";

$where  = [];
$params = [];

if ($filter !== '') {
    $where[] = "d.status = :status";
    $params['status'] = $filter;
}

if ($dateFilter === 'today') {
    $where[] = "d.scheduled_date = :date_start";
    $params['date_start'] = date('Y-m-d');
} elseif ($dateFilter === 'tomorrow') {
    $where[] = "d.scheduled_date = :date_start";
    $params['date_start'] = date('Y-m-d', strtotime('+1 day'));
} elseif ($dateFilter === 'week') {
    $where[] = "d.scheduled_date BETWEEN :date_start AND :date_end";
    $params['date_start'] = date('Y-m-d');
    $params['date_end']   = date('Y-m-d', strtotime('+7 day'));
} elseif ($dateFilter === 'custom' && $customStart !== '' && $customEnd !== '') {
    $where[] = "d.scheduled_date BETWEEN :date_start AND :date_end";
    $params['date_start'] = $customStart;
    $params['date_end']   = $customEnd;
}

if ($search !== '') {
    // Each LIKE clause needs its own uniquely-named placeholder — PDO does
    // not allow the same named parameter to be reused more than once in a
    // query when emulated prepares are turned off.
    $where[] = "(d.recipient_name LIKE :q1 OR d.address LIKE :q2 OR d.contact_number LIKE :q3
                 OR u.full_name LIKE :q4 OR u.email LIKE :q5 OR d.order_id LIKE :q6)";
    $searchTerm = '%' . $search . '%';
    $params['q1'] = $searchTerm;
    $params['q2'] = $searchTerm;
    $params['q3'] = $searchTerm;
    $params['q4'] = $searchTerm;
    $params['q5'] = $searchTerm;
    $params['q6'] = $searchTerm;
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allFiltered = $stmt->fetchAll();

// ---- Stats for the summary cards (based on the currently filtered list) ----
$statCounts = array_fill_keys($validStatuses, 0);
$statValue  = 0.0;
foreach ($allFiltered as $d) {
    if (isset($statCounts[$d['status']])) {
        $statCounts[$d['status']]++;
    }
    $statValue += (float) $d['total_amount'];
}
$totalCount = count($allFiltered);

// ---- Pagination ----
$perPage    = 8;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;
$deliveries = array_slice($allFiltered, $offset, $perPage);

// Helper to build a query string that preserves the current filters while
// changing one param (e.g. page number).
function deliveries_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'deliveries.php' . ($qs !== '' ? '?' . $qs : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries - MPD Electrical Supply &amp; Services</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: The rules below are duplicated here (instead of only living in
      style.css) so this page always renders correctly even if style.css on
      the server is out of date, was only partially uploaded, or is being
      served from a stale cache. If style.css is later confirmed to contain
      the same classes, this block can safely be deleted.
    -->
    <style>
        :root {
            --color-emerald-500: #10b981;
            --color-emerald-600: #059669;
            --color-rose-500: #f43f5e;
            --color-rose-600: #e11d48;
            --color-sky-500: #0ea5e9;
            --color-sky-600: #0284c7;
            --font-mono: ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 1.125rem;
            line-height: 1;
            vertical-align: middle;
        }
        .deliveries-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem;
        }
        .deliveries-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .deliveries-header-bento .field-hint { display: flex; align-items: center; gap: 0.25rem; margin-top: 0; }
        .deliveries-header-bento .field-hint strong { color: #0f172a; font-weight: 600; }
        .dh-pin { font-size: 1rem; color: #f59e0b; }
        .dh-user { display: flex; align-items: center; gap: 0.6rem; background-color: #f1f5f9; padding: 0.4rem 0.9rem 0.4rem 0.4rem; border-radius: 9999px; }
        .dh-avatar { width: 2rem; height: 2rem; border-radius: 9999px; background-color: #fbbf24; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0; }
        .dh-user-name { font-size: 0.8rem; font-weight: 600; color: #0f172a; line-height: 1.1rem; }
        .dh-user-role { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }

        .stats-grid-v2 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
        .stat-card-v2 { background-color: #ffffff; border-radius: 1rem; padding: 1.1rem 1.25rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); display: flex; flex-direction: column; gap: 0.35rem; }
        .stat-card-v2-top { display: flex; align-items: center; justify-content: space-between; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .stat-card-v2-top .material-symbols-outlined { color: #f59e0b; font-size: 1.1rem; }
        .stat-card-v2-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 2rem; }
        .stat-card-v2-value.stat-accent { color: #f59e0b; }
        .stat-card-v2-sub { font-size: 0.75rem; color: #64748b; font-family: var(--font-mono); }
        .stat-card-v2-danger .stat-card-v2-top, .stat-card-v2-danger .stat-card-v2-value { color: #be123c; }
        .stat-card-v2-danger .stat-card-v2-top .material-symbols-outlined { color: #be123c; }
        .stat-bar-track { width: 100%; height: 4px; border-radius: 9999px; background-color: #e2e8f0; overflow: hidden; margin-top: 0.25rem; }
        .stat-bar-fill { height: 100%; border-radius: 9999px; transition: width 0.3s ease-in-out; }
        .stat-bar-fill-amber { background-color: #fbbf24; }
        .stat-bar-fill-sky { background-color: var(--color-sky-600); }
        .stat-bar-fill-emerald { background-color: var(--color-emerald-600); }
        .stat-bar-fill-rose { background-color: var(--color-rose-600); }

        .deliveries-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
        .filter-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 9999px; background-color: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 600; transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out; }
        .pill:hover { background-color: #e2e8f0; }
        .pill-dot { width: 7px; height: 7px; border-radius: 9999px; flex-shrink: 0; display: inline-block; }
        .pill-dot-pending { background-color: #f59e0b; }
        .pill-dot-processing { background-color: var(--color-sky-500); }
        .pill-dot-completed { background-color: var(--color-emerald-500); }
        .pill-dot-cancelled { background-color: var(--color-rose-500); }
        .pill-dot-all { background-color: #0f172a; }
        .pill-count { font-family: var(--font-mono); font-size: 0.65rem; font-weight: 700; background-color: #ffffff; color: #475569; padding: 0.05rem 0.4rem; border-radius: 9999px; }
        .pill-active { background-color: #fbbf24; color: #0f172a; }
        .pill-active .pill-count { background-color: rgba(255, 255, 255, 0.5); color: #0f172a; }
        .pill-active:hover { background-color: #f59e0b; }

        .toolbar-right { display: flex; align-items: stretch; gap: 0.5rem; flex-wrap: wrap; }
        .search-box { position: relative; min-width: 260px; }
        .search-box .search-icon { position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem; color: #94a3b8; pointer-events: none; }
        .search-box input { width: 100%; height: 2.25rem; padding: 0 0.75rem 0 2.1rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; }
        .search-box input:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }
        .date-select-wrap { position: relative; }
        .date-select { height: 2.25rem; padding: 0 1.8rem 0 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; color: #0f172a; appearance: none; cursor: pointer; }
        .date-select:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }
        .date-select-wrap .material-symbols-outlined { position: absolute; right: 0.6rem; top: 50%; transform: translateY(-50%); font-size: 1rem; color: #94a3b8; pointer-events: none; }
        .custom-date-row { display: flex; align-items: center; gap: 0.4rem; }
        .custom-date-row input[type="date"] { height: 2.25rem; padding: 0 0.6rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.8rem; background-color: #f1f5f9; }

        .inline-ico { font-size: 0.9rem; color: #94a3b8; vertical-align: text-bottom; margin-right: 0.15rem; }
        .order-code { display: block; font-family: var(--font-mono); font-weight: 700; color: #f59e0b; font-size: 0.85rem; }
        .order-amount { display: block; font-family: var(--font-mono); font-weight: 700; color: #0f172a; }
        .landmark-hint { color: #f59e0b; font-weight: 600; }
        .time-chip { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 0.4rem; background-color: #f1f5f9; color: #0f172a; font-size: 0.75rem; font-weight: 600; }

        .rider-cell { display: flex; align-items: center; gap: 0.5rem; }
        .rider-cell .material-symbols-outlined { font-size: 1.15rem; color: #64748b; }
        .rider-cell.unassigned .material-symbols-outlined { color: #94a3b8; }
        .rider-name { font-weight: 600; font-size: 0.8rem; color: #0f172a; }
        .rider-name.unassigned { color: #94a3b8; font-weight: 500; }

        .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.65rem; border-radius: 9999px; text-transform: capitalize; }
        .status-dot { width: 6px; height: 6px; border-radius: 9999px; flex-shrink: 0; }
        .status-dot-pending { background-color: #f59e0b; }
        .status-dot-processing { background-color: var(--color-sky-600); animation: statusPulse 1.4s ease-in-out infinite; }
        .status-dot-completed { background-color: var(--color-emerald-600); }
        .status-dot-cancelled { background-color: var(--color-rose-600); }
        @keyframes statusPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

        .scheduled-date { font-weight: 600; color: #0f172a; font-size: 0.8rem; }
        .scheduled-sub { font-family: var(--font-mono); font-size: 0.7rem; color: #64748b; }
        .scheduled-sub.accent { color: #f59e0b; }

        .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 0.6rem; background-color: #f1f5f9; color: #64748b; transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out; }
        .icon-btn .material-symbols-outlined { font-size: 1.05rem; }
        .icon-btn-edit:hover { background-color: #e0f2fe; color: #0369a1; }
        .icon-btn-delete:hover { background-color: #ffe4e6; color: #be123c; }

        .admin-table tbody tr:nth-child(even) { background-color: #f8fafc; }
        .admin-table tbody tr:hover { background-color: #e0f2fe; }

        .table-footer { padding: 0.85rem 1rem; background-color: #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; font-size: 0.8rem; color: #64748b; border-radius: 0 0 1rem 1rem; }
        .table-footer-left { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
        .table-footer-sep { color: #94a3b8; }
        .table-footer-total { font-family: var(--font-mono); font-size: 0.75rem; }

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
            .deliveries-header-bento { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .deliveries-toolbar { flex-direction: column; align-items: stretch; }
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
                <a href="orders.php">Orders</a>
                <a href="deliveries.php" class="active">Deliveries</a>
                <a href="sales.php">Sales Report</a>
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <!-- Page header bento -->
            <div class="deliveries-header-bento">
                <div>
                    <h1>Deliveries</h1>
                    <p class="field-hint">
                        <span class="material-symbols-outlined dh-pin">location_on</span>
                        Delivery coverage: <strong>Baliuag, Bulacan only</strong>. Inter-barangay routes verified daily.
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

            <!-- Stat cards -->
            <?php
                $progressAll       = $totalCount > 0 ? round((($statCounts['out_for_delivery'] + $statCounts['delivered'] + $statCounts['failed']) / $totalCount) * 100) : 0;
                $progressEnRoute   = $totalCount > 0 ? round(($statCounts['out_for_delivery'] / $totalCount) * 100) : 0;
                $progressDelivered = $totalCount > 0 ? round(($statCounts['delivered'] / $totalCount) * 100) : 0;
                $progressFailed    = $totalCount > 0 ? round((($statCounts['pending'] + $statCounts['failed']) / $totalCount) * 100) : 0;
                $onTimeRate        = $statCounts['delivered'] > 0
                    ? round(($statCounts['delivered'] / max(1, $statCounts['delivered'] + $statCounts['failed'])) * 100, 1)
                    : 0;
            ?>
            <div class="stats-grid-v2">
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span><?= htmlspecialchars($periodLabel) ?></span>
                        <span class="material-symbols-outlined">local_shipping</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $totalCount ?></div>
                    <div class="stat-card-v2-sub">&#8369;<?= number_format($statValue, 2) ?> Total Vol</div>
                    <div class="stat-bar-track"><div class="stat-bar-fill stat-bar-fill-amber" style="width: <?= $progressAll ?>%"></div></div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>En Route</span>
                        <span class="material-symbols-outlined">navigation</span>
                    </div>
                    <div class="stat-card-v2-value stat-accent"><?= (int) $statCounts['out_for_delivery'] ?></div>
                    <div class="stat-card-v2-sub">Currently en route</div>
                    <div class="stat-bar-track"><div class="stat-bar-fill stat-bar-fill-sky" style="width: <?= $progressEnRoute ?>%"></div></div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Delivered</span>
                        <span class="material-symbols-outlined">task_alt</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $statCounts['delivered'] ?></div>
                    <div class="stat-card-v2-sub"><?= $onTimeRate ?>% on-time</div>
                    <div class="stat-bar-track"><div class="stat-bar-fill stat-bar-fill-emerald" style="width: <?= $progressDelivered ?>%"></div></div>
                </div>
                <div class="stat-card-v2 stat-card-v2-danger">
                    <div class="stat-card-v2-top">
                        <span>Pending / Failed</span>
                        <span class="material-symbols-outlined">warning</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) ($statCounts['pending'] + $statCounts['failed']) ?></div>
                    <div class="stat-card-v2-sub"><?= (int) $statCounts['pending'] ?> pending &middot; <?= (int) $statCounts['failed'] ?> failed</div>
                    <div class="stat-bar-track"><div class="stat-bar-fill stat-bar-fill-rose" style="width: <?= $progressFailed ?>%"></div></div>
                </div>
            </div>

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
                            <label for="notes">Notes / Landmark</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="e.g. Near Subic Elementary School"><?= htmlspecialchars($editDelivery['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Update Delivery</button>
                            <a href="<?= htmlspecialchars(deliveries_url(['edit' => null])) ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="admin-panel">

                <!-- Filter pills + search + date select -->
                <div class="deliveries-toolbar">
                    <div class="filter-pills">
                        <a href="<?= htmlspecialchars(deliveries_url(['status' => null, 'page' => null])) ?>" class="pill <?= $filter === '' ? 'pill-active' : '' ?>">
                            <span class="pill-dot pill-dot-all"></span>
                            All <span class="pill-count"><?= (int) $totalCount ?></span>
                        </a>
                        <?php foreach ($validStatuses as $s): ?>
                            <a href="<?= htmlspecialchars(deliveries_url(['status' => $s, 'page' => null])) ?>" class="pill <?= $filter === $s ? 'pill-active' : '' ?>">
                                <span class="pill-dot pill-dot-<?= $statusClasses[$s] ?>"></span>
                                <?= $statusLabels[$s] ?>
                                <span class="pill-count"><?= (int) $statCounts[$s] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="toolbar-right">
                        <div class="search-box">
                            <span class="material-symbols-outlined search-icon">search</span>
                            <input type="text" id="deliverySearch" placeholder="Search order #, customer, address..." value="<?= htmlspecialchars($search) ?>">
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

                <?php if (empty($deliveries)): ?>
                    <p class="empty-state">No deliveries match these filters.</p>
                <?php else: ?>
                    <div class="admin-table-wrapper">
                    <table class="admin-table" id="deliveriesTable">
                        <thead>
                            <tr>
                                <th>Order &amp; Amount</th>
                                <th>Customer</th>
                                <th>Recipient / Address</th>
                                <th>Contact</th>
                                <th>Preferred Time</th>
                                <th>Rider</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $delivery): ?>
                                <?php
                                    $orderCode  = '#MPD-' . str_pad((string) $delivery['order_id'], 5, '0', STR_PAD_LEFT);
                                    $riderName  = $delivery['rider_name'] ?? '';
                                    $isVan      = stripos($riderName, 'van') !== false;
                                    $vehicleIco = $riderName !== '' ? ($isVan ? 'local_shipping' : 'two_wheeler') : 'person_pin_circle';
                                ?>
                                <tr>
                                    <td>
                                        <span class="order-code"><?= htmlspecialchars($orderCode) ?></span>
                                        <span class="order-amount">&#8369;<?= number_format($delivery['total_amount'], 2) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($delivery['full_name']) ?>
                                        <div class="field-hint"><?= htmlspecialchars($delivery['email']) ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($delivery['recipient_name']) ?>
                                        <?php if ($delivery['address']): ?>
                                            <div class="field-hint">
                                                <span class="material-symbols-outlined inline-ico">location_on</span>
                                                <?= htmlspecialchars($delivery['address']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($delivery['notes'])): ?>
                                            <div class="field-hint landmark-hint"><?= htmlspecialchars($delivery['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($delivery['contact_number']): ?>
                                            <span class="material-symbols-outlined inline-ico">call</span>
                                            <?= htmlspecialchars($delivery['contact_number']) ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($delivery['preferred_time']) && isset($timeSlots[$delivery['preferred_time']])): ?>
                                            <span class="time-chip"><?= htmlspecialchars($timeSlots[$delivery['preferred_time']]) ?></span>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="rider-cell <?= $riderName === '' ? 'unassigned' : '' ?>">
                                            <span class="material-symbols-outlined"><?= $vehicleIco ?></span>
                                            <span class="rider-name <?= $riderName === '' ? 'unassigned' : '' ?>">
                                                <?= $riderName !== '' ? htmlspecialchars($riderName) : 'Unassigned' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $statusClasses[$delivery['status']] ?>">
                                            <span class="status-dot status-dot-<?= $statusClasses[$delivery['status']] ?>"></span>
                                            <?= $statusLabels[$delivery['status']] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="scheduled-date">
                                            <?= $delivery['scheduled_date'] ? date('M j, Y', strtotime($delivery['scheduled_date'])) : '&mdash;' ?>
                                        </div>
                                        <?php if ($delivery['status'] === 'out_for_delivery'): ?>
                                            <div class="scheduled-sub accent">En route</div>
                                        <?php elseif ($delivery['status'] === 'delivered'): ?>
                                            <div class="scheduled-sub">Delivered</div>
                                        <?php elseif ($delivery['status'] === 'failed'): ?>
                                            <div class="scheduled-sub">Attempt failed</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="admin-table-actions">
                                        <a href="<?= htmlspecialchars(deliveries_url(['edit' => $delivery['id']])) ?>" class="icon-btn icon-btn-edit" title="Edit Delivery">
                                            <span class="material-symbols-outlined">edit</span>
                                        </a>
                                        <a href="<?= htmlspecialchars(deliveries_url(['delete' => $delivery['id']])) ?>" class="icon-btn icon-btn-delete" title="Delete Delivery"
                                           onclick="return confirm('Delete this delivery record? This cannot be undone.');">
                                            <span class="material-symbols-outlined">delete</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="table-footer">
                        <div class="table-footer-left">
                            <span>Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $perPage, $totalCount) ?></strong> of <strong><?= (int) $totalCount ?></strong> deliveries</span>
                            <span class="table-footer-sep">&bull;</span>
                            <span class="table-footer-total">Total: &#8369;<?= number_format($statValue, 2) ?></span>
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <a class="page-nav-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(deliveries_url(['page' => max(1, $page - 1)])) ?>">
                                <span class="material-symbols-outlined">chevron_left</span> Previous
                            </a>

                            <?php
                                $windowStart = max(1, $page - 1);
                                $windowEnd   = min($totalPages, $page + 1);
                            ?>
                            <?php if ($windowStart > 1): ?>
                                <a class="page-btn" href="<?= htmlspecialchars(deliveries_url(['page' => 1])) ?>">1</a>
                                <?php if ($windowStart > 2): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <a class="page-btn <?= $p === $page ? 'page-btn-active' : '' ?>" href="<?= htmlspecialchars(deliveries_url(['page' => $p])) ?>"><?= $p ?></a>
                            <?php endfor; ?>

                            <?php if ($windowEnd < $totalPages): ?>
                                <?php if ($windowEnd < $totalPages - 1): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                                <a class="page-btn" href="<?= htmlspecialchars(deliveries_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                            <?php endif; ?>

                            <a class="page-nav-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(deliveries_url(['page' => min($totalPages, $page + 1)])) ?>">
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
        // Search submits to the server (so it works together with pagination
        // and the other filters) after a short pause in typing.
        (function () {
            var input = document.getElementById('deliverySearch');
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