<?php
session_start();
require_once '../config/database.php';

// Only logged-in admins may manage products
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$errors  = [];
$success = '';

$uploadDir = __DIR__ . '/../assets/images/products/';
if (!is_dir($uploadDir)) {
    error_clear_last();
    $created = @mkdir($uploadDir, 0755, true);
    if (!$created && !is_dir($uploadDir)) {
        $osError = error_get_last()['message'] ?? 'unknown error';
        $errors[] = "Could not create the product image upload folder ($uploadDir). "
                  . "OS said: $osError. Check that the 'MPD' folder isn't read-only "
                  . "and that your user account has write permission to it.";
    }
}

// ---- Add / Update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id          = $_POST['id'] ?? '';
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = $_POST['price'] ?? '';
    $stock       = $_POST['stock'] ?? '';
    $category    = trim($_POST['category'] ?? '');
    $imageName   = $_POST['existing_image'] ?? '';

    if ($name === '') {
        $errors[] = "Product name is required.";
    }
    if (!is_numeric($price) || $price < 0) {
        $errors[] = "Enter a valid price.";
    }
    if (!is_numeric($stock) || $stock < 0) {
        $errors[] = "Enter a valid stock quantity.";
    }

    // Image is optional — only replace it if a new file was chosen
    if (!empty($_FILES['image']['name'])) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            $errors[] = "Image must be a JPG, PNG, or WEBP file.";
        } elseif ($_FILES['image']['size'] > 3 * 1024 * 1024) {
            $errors[] = "Image must be smaller than 3MB.";
        } else {
            $newImageName = uniqid('prod_') . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $newImageName)) {
                // Replacing an existing image on edit — remove the old file
                if ($imageName && file_exists($uploadDir . $imageName)) {
                    unlink($uploadDir . $imageName);
                }
                $imageName = $newImageName;
            } else {
                $errors[] = "Failed to upload image. Check folder permissions.";
            }
        }
    }

    if (empty($errors)) {
        if ($id !== '') {
            $stmt = $pdo->prepare(
                "UPDATE products
                 SET name = :name, description = :description, price = :price,
                     stock = :stock, category = :category, image = :image
                 WHERE id = :id"
            );
            $stmt->execute([
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'stock'       => $stock,
                'category'    => $category,
                'image'       => $imageName,
                'id'          => (int) $id,
            ]);
            $success = "Product updated.";
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO products (name, description, price, stock, category, image)
                 VALUES (:name, :description, :price, :stock, :category, :image)"
            );
            $stmt->execute([
                'name'        => $name,
                'description' => $description,
                'price'       => $price,
                'stock'       => $stock,
                'category'    => $category,
                'image'       => $imageName,
            ]);
            $success = "Product added.";
        }
    }
}

// ---- Delete ----
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    $stmt = $pdo->prepare("SELECT image FROM products WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $toDelete = $stmt->fetch();

    if ($toDelete) {
        try {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
            $stmt->execute(['id' => $id]);

            // Only remove the image file once the DB row is actually gone
            if ($toDelete['image'] && file_exists($uploadDir . $toDelete['image'])) {
                unlink($uploadDir . $toDelete['image']);
            }
        } catch (PDOException $e) {
            // Foreign key violation (error code 23000) means this product
            // appears in one or more existing orders — deleting it would
            // corrupt that order history, so MySQL blocks it. Redirect
            // back with a friendly explanation instead of crashing.
            if ($e->getCode() === '23000') {
                $_SESSION['delete_error'] =
                    "This product can't be deleted because it's part of one or more existing orders. "
                    . "Deleting it would break that order history. "
                    . "If you no longer want it for sale, consider setting its stock to 0 instead.";
            } else {
                throw $e;
            }
        }
    }

    header('Location: products.php');
    exit;
}

// ---- Load product being edited (if any) ----
$editProduct = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editProduct = $stmt->fetch();
}

// ---- Category list (for filter pills + the add/edit form's datalist) ----
// Pulled unfiltered so pill counts and autocomplete stay stable regardless
// of whatever search/filter is currently applied to the table below.
$categoryRows = $pdo->query(
    "SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') AS category, COUNT(*) AS c
     FROM products
     GROUP BY category
     ORDER BY category ASC"
)->fetchAll();
$allCategories = array_column($categoryRows, 'category');

// ---- Filters ----
$categoryFilter = trim($_GET['category'] ?? '');
$search         = trim($_GET['q'] ?? '');

// ---- Sorting ----
$sortableColumns = ['name', 'category', 'price', 'stock', 'created_at'];
$sort = $_GET['sort'] ?? 'created_at';
if (!in_array($sort, $sortableColumns, true)) {
    $sort = 'created_at';
}
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}

// ---- Build the products query ----
$sql = "SELECT * FROM products";
$where  = [];
$params = [];

if ($categoryFilter !== '') {
    if ($categoryFilter === 'Uncategorized') {
        $where[] = "(category IS NULL OR TRIM(category) = '')";
    } else {
        $where[] = "category = :category";
        $params['category'] = $categoryFilter;
    }
}

if ($search !== '') {
    $where[] = "(name LIKE :q1 OR category LIKE :q2 OR description LIKE :q3)";
    $searchTerm = '%' . $search . '%';
    $params['q1'] = $searchTerm;
    $params['q2'] = $searchTerm;
    $params['q3'] = $searchTerm;
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY $sort $dir";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allFiltered = $stmt->fetchAll();

// ---- Stats for the summary cards (based on the currently filtered list) ----
$totalCount      = count($allFiltered);
$totalStockUnits = 0;
$inventoryValue  = 0.0;
$lowStockCount   = 0;
$criticalCount   = 0;
foreach ($allFiltered as $p) {
    $totalStockUnits += (int) $p['stock'];
    $inventoryValue  += (float) $p['price'] * (int) $p['stock'];
    if ((int) $p['stock'] <= 5) {
        $lowStockCount++;
    }
    if ((int) $p['stock'] <= 2) {
        $criticalCount++;
    }
}

// ---- Pagination ----
$perPage    = 8;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;
$products   = array_slice($allFiltered, $offset, $perPage);

// Helper to build a query string that preserves the current filters while
// changing one param (e.g. page number or sort column).
function products_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return 'products.php' . ($qs !== '' ? '?' . $qs : '');
}

// Helper for sortable column headers: figures out the next sort direction
// and renders the little up/down arrow for whichever column is active.
function sort_link(string $column, string $label, string $currentSort, string $currentDir): string
{
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $url = htmlspecialchars(products_url(['sort' => $column, 'dir' => $nextDir, 'page' => null]));
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = '<span class="material-symbols-outlined sort-arrow">'
               . ($currentDir === 'asc' ? 'arrow_upward' : 'arrow_downward')
               . '</span>';
    }
    return "<a href=\"$url\" class=\"sort-link\">" . htmlspecialchars($label) . $arrow . "</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - MPD Electrical Supply & Services</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Duplicated here (as on Deliveries/Dashboard) so this page keeps
      its look even if style.css is stale, partial, or cached. Safe to
      delete once style.css is confirmed to contain the same classes.
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

        /* ---- Header bento (matches Deliveries/Dashboard) ---- */
        .products-header-bento {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; background-color: #ffffff; border-radius: 1rem;
            padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            margin-bottom: 1.25rem;
        }
        .products-header-bento h1 { font-size: 1.5rem; line-height: 2rem; color: #0f172a; margin-bottom: 0.35rem; }
        .products-header-bento .field-hint { margin-top: 0; }
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

        /* ---- Toolbar: category pills + search ---- */
        .products-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem; }
        .filter-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .pill { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.4rem 0.85rem; border-radius: 9999px; background-color: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 600; transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out; }
        .pill:hover { background-color: #e2e8f0; }
        .pill-count { font-family: var(--font-mono); font-size: 0.65rem; font-weight: 700; background-color: #ffffff; color: #475569; padding: 0.05rem 0.4rem; border-radius: 9999px; }
        .pill-active { background-color: #fbbf24; color: #0f172a; }
        .pill-active .pill-count { background-color: rgba(255, 255, 255, 0.5); color: #0f172a; }
        .pill-active:hover { background-color: #f59e0b; }

        .search-box { position: relative; min-width: 260px; }
        .search-box .search-icon { position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem; color: #94a3b8; pointer-events: none; }
        .search-box input { width: 100%; height: 2.25rem; padding: 0 0.75rem 0 2.1rem; border: 1px solid #cbd5e1; border-radius: 0.75rem; font-size: 0.85rem; background-color: #f1f5f9; }
        .search-box input:focus { outline: none; border-color: #fbbf24; background-color: #ffffff; }

        /* ---- Sortable table headers ---- */
        .sort-link { display: inline-flex; align-items: center; gap: 0.2rem; color: inherit; text-decoration: none; }
        .sort-link:hover { color: #0f172a; }
        .sort-arrow { font-size: 0.85rem !important; color: #f59e0b; }

        .admin-table tbody tr:nth-child(even) { background-color: #f8fafc; }
        .admin-table tbody tr:hover { background-color: #e0f2fe; }

        /* ---- Low stock badges ---- */
        .low-stock-badge { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 9999px; margin-left: 0.35rem; }
        .low-stock-badge.critical { background-color: #ffe4e6; color: #be123c; }
        .low-stock-badge.warning { background-color: #fef3c7; color: #92400e; }

        .product-thumb { width: 44px; height: 44px; border-radius: 0.6rem; object-fit: cover; background-color: #f1f5f9; }
        .product-thumb-placeholder { display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: #94a3b8; text-align: center; }
        .date-added { font-size: 0.75rem; color: #64748b; font-family: var(--font-mono); }

        /* ---- Image upload + preview ---- */
        .image-upload-row { display: flex; align-items: center; gap: 1rem; }
        .image-preview-box { width: 64px; height: 64px; border-radius: 0.75rem; overflow: hidden; background-color: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px dashed #cbd5e1; }
        .image-preview-box img { width: 100%; height: 100%; object-fit: cover; }
        .image-preview-box .material-symbols-outlined { color: #94a3b8; font-size: 1.5rem; }

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
            .products-header-bento { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .products-toolbar { flex-direction: column; align-items: stretch; }
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
                <a href="products.php" class="active">Products</a>
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
            <div class="products-header-bento">
                <div>
                    <h1>Products</h1>
                    <p class="field-hint">Manage your catalog, stock levels, and pricing.</p>
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
                        <span>Total Products</span>
                        <span class="material-symbols-outlined">inventory_2</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $totalCount ?></div>
                    <div class="stat-card-v2-sub"><?= count($allCategories) ?> categories</div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Total Stock Units</span>
                        <span class="material-symbols-outlined">package_2</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $totalStockUnits ?></div>
                    <div class="stat-card-v2-sub">Units on hand</div>
                </div>
                <div class="stat-card-v2">
                    <div class="stat-card-v2-top">
                        <span>Inventory Value</span>
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                    <div class="stat-card-v2-value">&#8369;<?= number_format($inventoryValue, 2) ?></div>
                    <div class="stat-card-v2-sub">Price &times; stock</div>
                </div>
                <div class="stat-card-v2 <?= $lowStockCount > 0 ? 'stat-card-v2-danger' : '' ?>">
                    <div class="stat-card-v2-top">
                        <span>Low Stock</span>
                        <span class="material-symbols-outlined">warning</span>
                    </div>
                    <div class="stat-card-v2-value"><?= (int) $lowStockCount ?></div>
                    <div class="stat-card-v2-sub"><?= (int) $criticalCount ?> critically low (&le;2)</div>
                </div>
            </section>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['delete_error'])): ?>
                <div class="alert alert-error"><?= htmlspecialchars($_SESSION['delete_error']) ?></div>
                <?php unset($_SESSION['delete_error']); ?>
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

            <section class="admin-panel">
                <h2><?= $editProduct ? 'Edit Product' : 'Add New Product' ?></h2>

                <form action="products.php" method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editProduct): ?>
                        <input type="hidden" name="id" value="<?= (int) $editProduct['id'] ?>">
                        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editProduct['image'] ?? '') ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Product Name</label>
                        <input type="text" id="name" name="name" required
                               value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="category">Category</label>
                        <input type="text" id="category" name="category" list="categoryOptions"
                               placeholder="e.g. Wiring, Switches, Lighting"
                               value="<?= htmlspecialchars($editProduct['category'] ?? '') ?>">
                        <datalist id="categoryOptions">
                            <?php foreach ($allCategories as $cat): ?>
                                <?php if ($cat !== 'Uncategorized'): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </datalist>
                        <p class="field-hint">Start typing to reuse an existing category and avoid duplicates like "Wiring" vs "wiring".</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="price">Price (&#8369;)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required
                                   value="<?= htmlspecialchars($editProduct['price'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="stock">Stock</label>
                            <input type="number" id="stock" name="stock" min="0" required
                                   value="<?= htmlspecialchars($editProduct['stock'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="image">Product Image</label>
                        <div class="image-upload-row">
                            <div class="image-preview-box" id="imagePreviewBox">
                                <?php if ($editProduct && $editProduct['image']): ?>
                                    <img id="imagePreviewImg" src="../assets/images/products/<?= htmlspecialchars($editProduct['image']) ?>" alt="Preview">
                                <?php else: ?>
                                    <span class="material-symbols-outlined" id="imagePreviewIcon">image</span>
                                    <img id="imagePreviewImg" src="" alt="Preview" style="display:none;">
                                <?php endif; ?>
                            </div>
                            <div>
                                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                                <p class="field-hint">JPG, PNG, or WEBP. Max 3MB.</p>
                                <?php if ($editProduct && $editProduct['image']): ?>
                                    <p class="field-hint">Current image: <?= htmlspecialchars($editProduct['image']) ?> (leave blank to keep it)</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary"><?= $editProduct ? 'Update Product' : 'Add Product' ?></button>
                        <?php if ($editProduct): ?>
                            <a href="products.php" class="btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="admin-panel">
                <div class="products-toolbar">
                    <div class="filter-pills">
                        <a href="<?= htmlspecialchars(products_url(['category' => null, 'page' => null])) ?>" class="pill <?= $categoryFilter === '' ? 'pill-active' : '' ?>">
                            All <span class="pill-count"><?= array_sum(array_column($categoryRows, 'c')) ?></span>
                        </a>
                        <?php foreach ($categoryRows as $row): ?>
                            <a href="<?= htmlspecialchars(products_url(['category' => $row['category'], 'page' => null])) ?>" class="pill <?= $categoryFilter === $row['category'] ? 'pill-active' : '' ?>">
                                <?= htmlspecialchars($row['category']) ?> <span class="pill-count"><?= (int) $row['c'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="search-box">
                        <span class="material-symbols-outlined search-icon">search</span>
                        <input type="text" id="productSearch" placeholder="Search name, category, description..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <h2>All Products (<?= (int) $totalCount ?>)</h2>

                <?php if (empty($products)): ?>
                    <p class="empty-state">No products match these filters.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th><?= sort_link('name', 'Name', $sort, $dir) ?></th>
                                <th><?= sort_link('category', 'Category', $sort, $dir) ?></th>
                                <th><?= sort_link('price', 'Price', $sort, $dir) ?></th>
                                <th><?= sort_link('stock', 'Stock', $sort, $dir) ?></th>
                                <th><?= sort_link('created_at', 'Date Added', $sort, $dir) ?></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php
                                    $stockVal = (int) $product['stock'];
                                    $isCritical = $stockVal <= 2;
                                    $isLow = $stockVal <= 5;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($product['image']): ?>
                                            <img src="../assets/images/products/<?= htmlspecialchars($product['image']) ?>"
                                                 alt="<?= htmlspecialchars($product['name']) ?>" class="product-thumb">
                                        <?php else: ?>
                                            <div class="product-thumb product-thumb-placeholder">No image</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($product['name']) ?></td>
                                    <td><?= htmlspecialchars($product['category'] ?: '—') ?></td>
                                    <td>&#8369;<?= number_format($product['price'], 2) ?></td>
                                    <td>
                                        <?= $stockVal ?>
                                        <?php if ($isLow): ?>
                                            <span class="low-stock-badge <?= $isCritical ? 'critical' : 'warning' ?>"><?= $isCritical ? 'Critical' : 'Low' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="date-added"><?= date('M j, Y', strtotime($product['created_at'])) ?></td>
                                    <td class="admin-table-actions">
                                        <a href="products.php?edit=<?= (int) $product['id'] ?>" class="btn-edit">Edit</a>
                                        <a href="products.php?delete=<?= (int) $product['id'] ?>" class="btn-danger"
                                           onclick="return confirm('Delete this product? This cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="table-footer">
                        <div>
                            Showing <strong><?= $offset + 1 ?></strong> to <strong><?= min($offset + $perPage, $totalCount) ?></strong> of <strong><?= (int) $totalCount ?></strong> products
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <a class="page-nav-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => max(1, $page - 1)])) ?>">
                                <span class="material-symbols-outlined">chevron_left</span> Previous
                            </a>

                            <?php
                                $windowStart = max(1, $page - 1);
                                $windowEnd   = min($totalPages, $page + 1);
                            ?>
                            <?php if ($windowStart > 1): ?>
                                <a class="page-btn" href="<?= htmlspecialchars(products_url(['page' => 1])) ?>">1</a>
                                <?php if ($windowStart > 2): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <a class="page-btn <?= $p === $page ? 'page-btn-active' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => $p])) ?>"><?= $p ?></a>
                            <?php endfor; ?>

                            <?php if ($windowEnd < $totalPages): ?>
                                <?php if ($windowEnd < $totalPages - 1): ?><span class="page-ellipsis">&hellip;</span><?php endif; ?>
                                <a class="page-btn" href="<?= htmlspecialchars(products_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                            <?php endif; ?>

                            <a class="page-nav-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => min($totalPages, $page + 1)])) ?>">
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
        // Search submits to the server (so it works together with
        // category filter, sorting, and pagination) after a short pause.
        (function () {
            var input = document.getElementById('productSearch');
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

        // Live preview of the chosen product image before upload.
        (function () {
            var fileInput = document.getElementById('image');
            var img = document.getElementById('imagePreviewImg');
            var icon = document.getElementById('imagePreviewIcon');
            if (!fileInput || !img) return;

            fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                    img.style.display = 'block';
                    if (icon) icon.style.display = 'none';
                };
                reader.readAsDataURL(file);
            });
        })();
    </script>

</body>
</html>