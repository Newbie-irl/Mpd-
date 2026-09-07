<?php
session_start();
require_once '../config/database.php';

// Must be logged in to check out
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = 'checkout.php';
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$errors  = [];
$success = '';

// Delivery time slots the customer can choose from
$timeSlots = [
    'morning'   => 'Morning (8:00 AM - 12:00 PM)',
    'afternoon' => 'Afternoon (12:00 PM - 4:00 PM)',
    'evening'   => 'Evening (4:00 PM - 8:00 PM)',
];

/**
 * Reload the cart from the DB, clamping quantities to whatever stock is
 * actually available right now. Returns [$cartItems, $grandTotal].
 * Used both to render the summary and to re-validate right before placing
 * the order (stock can change between page load and submit).
 */
function loadCart(PDO $pdo, array &$cart): array {
    $cartItems  = [];
    $grandTotal = 0;

    if (empty($cart)) {
        return [$cartItems, $grandTotal];
    }

    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $foundProducts = $stmt->fetchAll();
    $foundIds = array_column($foundProducts, 'id');

    foreach ($ids as $id) {
        if (!in_array($id, $foundIds)) {
            unset($cart[$id]);
        }
    }

    foreach ($foundProducts as $product) {
        $qty = $cart[$product['id']];

        if ($qty > $product['stock']) {
            $qty = max(0, (int) $product['stock']);
            $cart[$product['id']] = $qty;
        }

        if ($qty <= 0) {
            unset($cart[$product['id']]);
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

    return [$cartItems, $grandTotal];
}

// ---- Place the order ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $preferredTime = $_POST['preferred_time'] ?? '';
    $notes         = trim($_POST['notes'] ?? '');

    if ($recipientName === '') {
        $errors[] = "Recipient name is required.";
    }
    if ($address === '') {
        $errors[] = "Delivery address is required.";
    }
    if ($contactNumber === '') {
        $errors[] = "Contact number is required.";
    }
    if (!array_key_exists($preferredTime, $timeSlots)) {
        $errors[] = "Please choose a preferred delivery time.";
    }

    [$cartItems, $grandTotal] = loadCart($pdo, $_SESSION['cart']);

    if (empty($cartItems)) {
        $errors[] = "Your cart is empty.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Re-check stock for every item right before committing, in case
            // it changed since the page was loaded.
            foreach ($cartItems as $item) {
                $stmt = $pdo->prepare("SELECT stock FROM products WHERE id = :id FOR UPDATE");
                $stmt->execute(['id' => $item['product']['id']]);
                $current = $stmt->fetch();
                if (!$current || $current['stock'] < $item['quantity']) {
                    throw new RuntimeException(
                        "Not enough stock for {$item['product']['name']}. Please update your cart."
                    );
                }
            }

            $stmt = $pdo->prepare(
                "INSERT INTO orders (user_id, total_amount, status, created_at)
                 VALUES (:user_id, :total_amount, 'pending', NOW())"
            );
            $stmt->execute([
                'user_id'      => $_SESSION['user_id'],
                'total_amount' => $grandTotal,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt  = $pdo->prepare(
                "INSERT INTO order_items (order_id, product_id, quantity, price)
                 VALUES (:order_id, :product_id, :quantity, :price)"
            );
            $stockStmt = $pdo->prepare(
                "UPDATE products SET stock = stock - :qty WHERE id = :id"
            );

            foreach ($cartItems as $item) {
                $itemStmt->execute([
                    'order_id'   => $orderId,
                    'product_id' => $item['product']['id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['product']['price'],
                ]);
                $stockStmt->execute([
                    'qty' => $item['quantity'],
                    'id'  => $item['product']['id'],
                ]);
            }

            $deliveryStmt = $pdo->prepare(
                "INSERT INTO deliveries (order_id, recipient_name, address, contact_number, preferred_time, status, notes)
                 VALUES (:order_id, :recipient_name, :address, :contact_number, :preferred_time, 'pending', :notes)"
            );
            $deliveryStmt->execute([
                'order_id'        => $orderId,
                'recipient_name'  => $recipientName,
                'address'         => $address,
                'contact_number'  => $contactNumber,
                'preferred_time'  => $preferredTime,
                'notes'           => $notes !== '' ? $notes : null,
            ]);

            $pdo->commit();

            $_SESSION['cart'] = [];
            $_SESSION['success'] = "Order #{$orderId} placed! We'll deliver it to you soon.";
            header('Location: orders.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// ---- Load cart for display ----
[$cartItems, $grandTotal] = loadCart($pdo, $_SESSION['cart']);
$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - MPD Electrical Supply & Services</title>
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
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li><a href="../admin/dashboard.php">Admin Panel</a></li>
        <?php endif; ?>
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
        <h1>Checkout</h1>

        <p class="field-hint">We currently deliver within Baliuag, Bulacan only.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
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
                            <td><?= (int) $item['quantity'] ?></td>
                            <td>&#8369;<?= number_format($item['line_total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="cart-summary">
                <a href="cart.php" class="btn-secondary">&larr; Edit Cart</a>
                <div class="cart-total">
                    <span>Total: &#8369;<?= number_format($grandTotal, 2) ?></span>
                </div>
            </div>

            <section class="admin-panel" style="margin-top: 1.5rem;">
                <h2>Delivery Details</h2>

                <form action="checkout.php" method="POST" class="admin-form">
                    <input type="hidden" name="action" value="place_order">

                    <div class="form-group">
                        <label for="recipient_name">Recipient Name</label>
                        <input type="text" id="recipient_name" name="recipient_name" required
                               value="<?= htmlspecialchars($_POST['recipient_name'] ?? $_SESSION['full_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="address">Delivery Address</label>
                        <textarea id="address" name="address" rows="2" required
                                  placeholder="e.g. Poblacion, Baliuag, Bulacan"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="text" id="contact_number" name="contact_number" required
                               value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="preferred_time">Preferred Delivery Time</label>
                        <select id="preferred_time" name="preferred_time" required>
                            <option value="">Select a time slot&hellip;</option>
                            <?php foreach ($timeSlots as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"
                                    <?= ($_POST['preferred_time'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes (optional)</label>
                        <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Place Order</button>
                    </div>
                </form>
            </section>

        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>