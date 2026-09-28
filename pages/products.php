<?php
session_start();
require_once '../config/database.php';

// ---- Cart item count (for nav badge) ----
$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;

// ---- Categories for the filter bar ----
$categories = $pdo->query(
    "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ---- Optional category filter ----
$filter = $_GET['category'] ?? '';
if ($filter !== '' && !in_array($filter, $categories, true)) {
    $filter = '';
}

// ---- Optional search ----
$search = trim($_GET['search'] ?? '');

// ---- Build product query (shared WHERE for both the count and the page) ----
$where  = "WHERE 1=1";
$params = [];

if ($filter !== '') {
    $where .= " AND category = :category";
    $params['category'] = $filter;
}

if ($search !== '') {
    $where .= " AND name LIKE :search";
    $params['search'] = '%' . $search . '%';
}

// ---- Total count (for the pager) ----
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products $where");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();

// ---- Pagination ----
$perPage    = 10;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;

// ---- Load this page of products ----
$sql = "SELECT * FROM products $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Helper to build a query string that preserves the current filters while
// changing one param (e.g. page number).
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!--
      NOTE: Pagination styles are embedded here directly (same approach used
      on the admin pages) so they render correctly even if style.css doesn't
      define them for the storefront layout.
    -->
    <style>
        .product-pagination {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem; margin-top: 2rem; padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        .product-pagination-info { font-size: 0.85rem; color: #64748b; }
        .product-pagination-nav { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; }
        .product-page-btn, .product-page-nav-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 2.25rem; height: 2.25rem; padding: 0 0.7rem; border-radius: 9999px;
            background-color: #f1f5f9; color: #334155; text-decoration: none;
            font-size: 0.85rem; font-weight: 600; border: 1px solid transparent;
            transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out;
        }
        .product-page-btn:hover, .product-page-nav-btn:hover:not(.disabled) {
            background-color: #fbbf24; color: #451a03;
        }
        .product-page-btn.active {
            background-color: #f59e0b; color: #451a03; cursor: default;
        }
        .product-page-nav-btn.disabled {
            opacity: 0.4; pointer-events: none;
        }
        .product-page-ellipsis { color: #94a3b8; padding: 0 0.15rem; font-size: 0.85rem; }

        @media (max-width: 640px) {
            .product-pagination { flex-direction: column; align-items: stretch; text-align: center; }
            .product-pagination-nav { justify-content: center; }
        }
    </style>
</head>
<body>

    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical Supply & Services</div>
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

        <div class="storefront-header">
            <h1>Products</h1>
            <form action="products.php" method="GET" class="search-form">
                <?php if ($filter !== ''): ?>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($filter) ?>">
                <?php endif; ?>
                <input type="text" name="search" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-secondary">Search</button>
            </form>
        </div>

        <?php if (!empty($categories)): ?>
            <div class="filter-tabs">
                <a href="products.php<?= $search ? '?search=' . urlencode($search) : '' ?>" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="products.php?category=<?= urlencode($cat) ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                       class="<?= $filter === $cat ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <p class="empty-state">No products found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</p>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <?php if ($product['image']): ?>
                            <img src="../assets/images/products/<?= htmlspecialchars($product['image']) ?>"
                                 alt="<?= htmlspecialchars($product['name']) ?>" class="product-card-img">
                        <?php else: ?>
                            <div class="product-card-img product-card-img-placeholder">No image</div>
                        <?php endif; ?>

                        <div class="product-card-body">
                            <?php if ($product['category']): ?>
                                <span class="product-category"><?= htmlspecialchars($product['category']) ?></span>
                            <?php endif; ?>
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <?php if ($product['description']): ?>
                                <p class="product-desc"><?= htmlspecialchars($product['description']) ?></p>
                            <?php endif; ?>
                            <div class="product-card-footer">
                                <span class="product-price">&#8369;<?= number_format($product['price'], 2) ?></span>
                                <?php if ($product['stock'] <= 0): ?>
                                    <span class="status-badge status-cancelled">Out of Stock</span>
                                <?php elseif ($product['stock'] <= 5): ?>
                                    <span class="low-stock-badge"><?= (int) $product['stock'] ?> left</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($product['stock'] > 0): ?>
                                <form action="cart.php" method="POST" class="add-to-cart-form">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="number" name="quantity" value="1" min="1" max="<?= (int) $product['stock'] ?>">
                                    <button type="submit" class="btn-primary">Add to Cart</button>
                                </form>
                            <?php else: ?>
                                <button class="btn-secondary" disabled>Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="product-pagination">
                    <div class="product-pagination-info">
                        Showing <strong><?= $offset + 1 ?></strong>&ndash;<strong><?= min($offset + $perPage, $totalCount) ?></strong> of <strong><?= $totalCount ?></strong> products
                    </div>
                    <div class="product-pagination-nav">
                        <a class="product-page-nav-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => max(1, $page - 1)])) ?>" aria-label="Previous page">&lsaquo;</a>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a class="product-page-btn <?= $p === $page ? 'active' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => $p])) ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <a class="product-page-nav-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(products_url(['page' => min($totalPages, $page + 1)])) ?>" aria-label="Next page">&rsaquo;</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>