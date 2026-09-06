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

// ---- Build product query ----
$sql = "SELECT * FROM products WHERE 1=1";
$params = [];

if ($filter !== '') {
    $sql .= " AND category = :category";
    $params['category'] = $filter;
}

if ($search !== '') {
    $sql .= " AND name LIKE :search";
    $params['search'] = '%' . $search . '%';
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical</div>
<ul class="nav-links">
    <li><a href="../index.php">Home</a></li>
    <li><a href="products.php">Products</a></li>
    <li><a href="cart.php">Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?></a></li>

    <?php if (isset($_SESSION['user_id'])): ?>
        <li><a href="orders.php">My Orders</a></li>
        <li class="nav-welcome">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></li>
        <li><a href="../index.php?logout=1">Logout</a></li>
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
        <?php endif; ?>

    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>