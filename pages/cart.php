<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ---- Add to cart ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity  = max(1, (int) ($_POST['quantity'] ?? 1));

    $stmt = $pdo->prepare("SELECT id, stock FROM products WHERE id = :id");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();

    if ($product) {
        $currentQty = $_SESSION['cart'][$productId] ?? 0;
        $newQty = min($currentQty + $quantity, (int) $product['stock']);
        if ($newQty > 0) {
            $_SESSION['cart'][$productId] = $newQty;
            $_SESSION['success'] = "Added to cart.";
        }
    }

    header('Location: cart.php');
    exit;
}

// ---- Update quantity ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity  = (int) ($_POST['quantity'] ?? 0);

    if ($quantity <= 0) {
        unset($_SESSION['cart'][$productId]);
    } else {
        $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = :id");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();
        if ($product) {
            $_SESSION['cart'][$productId] = min($quantity, (int) $product['stock']);
        }
    }

    header('Location: cart.php');
    exit;
}

// ---- Remove item ----
if (isset($_GET['remove'])) {
    unset($_SESSION['cart'][(int) $_GET['remove']]);
    header('Location: cart.php');
    exit;
}

// ---- Clear cart ----
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit;
}

// ---- Load cart product details ----
$cartItems = [];
$grandTotal = 0;
$stockAdjusted = false;

if (!empty($_SESSION['cart'])) {
    $ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $foundProducts = $stmt->fetchAll();
    $foundIds = array_column($foundProducts, 'id');

    // Drop cart entries for products that no longer exist
    foreach ($ids as $id) {
        if (!in_array($id, $foundIds)) {
            unset($_SESSION['cart'][$id]);
        }
    }

    foreach ($foundProducts as $product) {
        $qty = $_SESSION['cart'][$product['id']];

        // Clamp to available stock in case it changed since being added
        if ($qty > $product['stock']) {
            $qty = max(0, (int) $product['stock']);
            $_SESSION['cart'][$product['id']] = $qty;
            $stockAdjusted = true;
        }

        if ($qty <= 0) {
            unset($_SESSION['cart'][$product['id']]);
            continue;
        }

        $lineTotal = $qty * $product['price'];
        $grandTotal += $lineTotal;

        $cartItems[] = [
            'product'    => $product,
            'quantity'   => $qty,
            'line_total' => $lineTotal,
        ];
    }
}

$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - MPD Electrical Supply & Services</title>
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
        <h1>Your Cart</h1>

        <p class="field-hint">We currently deliver within Baliuag, Bulacan only.</p>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if ($stockAdjusted): ?>
            <div class="alert alert-error">Some quantities were adjusted to match available stock.</div>
        <?php endif; ?>

        <?php if (empty($cartItems)): ?>
            <p class="empty-state">Your cart is empty. <a href="products.php">Browse products</a> to get started.</p>
        <?php else: ?>
            <table class="admin-table cart-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cartItems as $item): $product = $item['product']; ?>
                        <tr>
                            <td class="cart-product-cell">
                                <?php if ($product['image']): ?>
                                    <img src="../assets/images/products/<?= htmlspecialchars($product['image']) ?>"
                                         alt="<?= htmlspecialchars($product['name']) ?>" class="product-thumb">
                                <?php endif; ?>
                                <?= htmlspecialchars($product['name']) ?>
                            </td>
                            <td>&#8369;<?= number_format($product['price'], 2) ?></td>
                            <td>
                                <form method="POST" action="cart.php" class="status-form">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>"
                                           min="1" max="<?= (int) $product['stock'] ?>" class="qty-input">
                                    <button type="submit" class="btn-edit">Update</button>
                                </form>
                            </td>
                            <td>&#8369;<?= number_format($item['line_total'], 2) ?></td>
                            <td>
                                <a href="cart.php?remove=<?= (int) $product['id'] ?>" class="btn-danger">Remove</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="cart-summary">
                <a href="cart.php?clear=1" class="btn-secondary"
                   onclick="return confirm('Clear your entire cart?');">Clear Cart</a>
                <div class="cart-total">
                    <span>Total: &#8369;<?= number_format($grandTotal, 2) ?></span>
                    <a href="checkout.php" class="btn-primary">Proceed to Checkout</a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>