<?php
session_start();
require_once 'config/database.php';

// Simple logout handler: visiting index.php?logout=1 ends the session
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

    <!--
        NOTE: This header/nav/footer is written inline for now.
        Once includes/header.php, navbar.php, and footer.php exist,
        this markup should move there and be pulled in with require_once
        so every page shares the same layout.
    -->
    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical Supply & Services</div>
        <ul class="nav-links">
    <li><a href="index.php">Home</a></li>
    <li><a href="pages/products.php">Products</a></li>
    <li><a href="pages/cart.php">Cart</a></li>

    <?php if (isset($_SESSION['user_id'])): ?>
        <li><a href="pages/orders.php">My Orders</a></li>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li><a href="admin/dashboard.php">Admin Panel</a></li>
        <?php endif; ?>
        <li class="nav-welcome">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></li>
        <li><a href="index.php?logout=1">Logout</a></li>
    <?php else: ?>
        <li><a href="login.php">Login</a></li>
        <li><a href="register.php">Register</a></li>
    <?php endif; ?>
</ul>
            </ul>
        </nav>
    </header>

    <main>
        <section class="hero">
            <h1>MPD Electrical Supply & Services</h1>
            <p>Quality electrical supplies and reliable service, all in one place.</p>
            <a href="pages/products.php" class="btn-primary">Browse Products</a>
        </section>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>