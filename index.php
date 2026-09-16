<?php
session_start();
require_once 'config/database.php';

// Cart item count (for nav badge) — matches every other page's logic
$cartCount = !empty($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;

// ---- Featured products (newest additions with stock available) ----
// Pulled straight from the same `products` table admin/products.php manages.
// No "featured" flag exists in the schema yet, so this shows the 4 most
// recently added in-stock items. If you'd rather feature specific items,
// add a `featured TINYINT(1)` column and filter on that instead.
$stmt = $pdo->prepare(
    "SELECT id, name, category, price, stock, image
     FROM products
     WHERE stock > 0
     ORDER BY created_at DESC
     LIMIT 4"
);
$stmt->execute();
$featuredProducts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!--
        NOTE: Homepage-specific styles are kept inline here for now, same
        pattern as admin/products.php — so this page keeps its look even
        if style.css doesn't have these classes yet. Move into style.css
        whenever convenient.
    -->
    <style>
        :root {
            --mpd-navy: #0f172a;
            --mpd-amber: #fbbf24;
            --mpd-amber-deep: #d68910;
            --mpd-slate: #475569;
            --mpd-slate-light: #64748b;
            --mpd-bg: #f8fafc;
            --mpd-line: #e2e8f0;
            --mpd-ok: #059669;
        }

        .home-hero {
            background: var(--mpd-navy);
            color: #ffffff;
            text-align: center;
            padding: 64px 24px 56px;
        }
        .home-hero h1 { font-size: 2.1rem; margin-bottom: 12px; }
        .home-hero p { color: rgba(255,255,255,0.72); max-width: 480px; margin: 0 auto 26px; }

        .home-section { max-width: 1100px; margin: 0 auto; padding: 56px 24px; }
        .home-section-head { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 28px; }
        .home-section-head h2 { font-size: 1.4rem; color: var(--mpd-navy); }
        .home-section-head p { color: var(--mpd-slate-light); font-size: 0.9rem; }
        .home-view-all { font-weight: 600; font-size: 0.85rem; color: var(--mpd-amber-deep); white-space: nowrap; }

        /* Featured products */
        .home-product-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
        .home-product-card { background: #ffffff; border: 1px solid var(--mpd-line); border-radius: 0.75rem; overflow: hidden; }
        .home-product-thumb { height: 120px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .home-product-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .home-product-thumb .no-image { font-size: 0.75rem; color: #94a3b8; }
        .home-product-body { padding: 14px 16px 16px; }
        .home-product-cat { font-size: 0.7rem; color: var(--mpd-amber-deep); font-weight: 700; text-transform: capitalize; margin-bottom: 4px; }
        .home-product-name { font-size: 0.92rem; font-weight: 600; color: var(--mpd-navy); margin-bottom: 8px; min-height: 2.4em; }
        .home-product-row { display: flex; align-items: center; justify-content: space-between; }
        .home-product-price { font-weight: 700; color: var(--mpd-navy); }
        .home-stock-ok { font-size: 0.7rem; color: var(--mpd-ok); font-weight: 600; }
        .home-stock-low { font-size: 0.7rem; color: #d97706; font-weight: 600; }
        .home-add-btn { display: block; width: 100%; margin-top: 12px; padding: 8px; border-radius: 0.5rem; background: var(--mpd-navy); color: #ffffff; font-weight: 600; font-size: 0.82rem; text-align: center; }
        .home-empty { color: var(--mpd-slate-light); font-size: 0.9rem; }

        /* Why choose us */
        .home-why-wrap { background: #ffffff; border-top: 1px solid var(--mpd-line); border-bottom: 1px solid var(--mpd-line); }
        .home-why-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
        .home-why-item h3 { font-size: 0.95rem; color: var(--mpd-navy); margin-bottom: 6px; }
        .home-why-item p { font-size: 0.85rem; color: var(--mpd-slate-light); }
        .home-why-icon { color: var(--mpd-amber-deep); font-size: 1.6rem; margin-bottom: 10px; display: block; }

        /* Steps */
        .home-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; counter-reset: homestep; }
        .home-step { position: relative; padding-left: 42px; }
        .home-step::before {
            counter-increment: homestep; content: counter(homestep);
            position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%;
            background: var(--mpd-navy); color: var(--mpd-amber); font-weight: 700; font-size: 0.82rem;
            display: flex; align-items: center; justify-content: center;
        }
        .home-step h3 { font-size: 0.92rem; color: var(--mpd-navy); margin-bottom: 4px; }
        .home-step p { font-size: 0.84rem; color: var(--mpd-slate-light); }

        /* Contact strip */
        .home-contact-strip {
            background: var(--mpd-navy); color: #ffffff; border-radius: 0.75rem;
            padding: 30px 34px; display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 20px;
        }
        .home-contact-strip h2 { font-size: 1.2rem; margin-bottom: 4px; }
        .home-contact-strip p { color: rgba(255,255,255,0.68); font-size: 0.85rem; }
        .home-contact-info { display: flex; gap: 24px; flex-wrap: wrap; font-size: 0.85rem; }
        .home-contact-info span.label { display: block; color: var(--mpd-amber); font-weight: 600; font-size: 0.72rem; margin-bottom: 2px; }

        /* Footer */
        .home-footer { background: var(--mpd-navy); color: rgba(255,255,255,0.75); padding: 44px 24px 20px; }
        .home-footer-grid { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 32px; padding-bottom: 28px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .home-footer-grid h4 { font-size: 0.85rem; color: #ffffff; margin-bottom: 12px; }
        .home-footer-grid p { font-size: 0.82rem; color: rgba(255,255,255,0.55); max-width: 260px; }
        .home-footer-col a { display: block; font-size: 0.82rem; color: rgba(255,255,255,0.62); margin-bottom: 8px; }
        .home-footer-col a:hover { color: var(--mpd-amber); }
        .home-footer-bottom { max-width: 1100px; margin: 0 auto; padding-top: 16px; display: flex; justify-content: space-between; font-size: 0.75rem; color: rgba(255,255,255,0.45); flex-wrap: wrap; gap: 8px; }

        @media (max-width: 900px) {
            .home-product-grid { grid-template-columns: repeat(2, 1fr); }
            .home-why-grid { grid-template-columns: repeat(2, 1fr); }
            .home-steps { grid-template-columns: 1fr; }
            .home-footer-grid { grid-template-columns: 1fr; }
        }
    </style>
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
    <li><a href="pages/cart.php">Cart<?= $cartCount > 0 ? ' (' . $cartCount . ')' : '' ?></a></li>

    <?php if (isset($_SESSION['user_id'])): ?>
        <li><a href="pages/orders.php">My Orders</a></li>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <li><a href="admin/dashboard.php">Admin Panel</a></li>
        <?php endif; ?>
        <li class="nav-welcome">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></li>
        <li><a href="logout.php">Logout</a></li>
    <?php else: ?>
        <li><a href="login.php">Login</a></li>
        <li><a href="register.php">Register</a></li>
    <?php endif; ?>
</ul>
        </nav>
    </header>

    <main>
        <section class="home-hero">
            <h1>MPD Electrical Supply & Services</h1>
            <p>Quality electrical supplies and reliable service, all in one place.</p>
            <a href="pages/products.php" class="btn-primary">Browse Products</a>
        </section>

        <!-- Featured products, pulled live from the products table -->
        <section class="home-section">
            <div class="home-section-head">
                <div>
                    <h2>Newest arrivals</h2>
                    <p>Fresh stock we've just added to the catalog.</p>
                </div>
                <a class="home-view-all" href="pages/products.php">View all products &rarr;</a>
            </div>

            <?php if (empty($featuredProducts)): ?>
                <p class="home-empty">No products available right now. Check back soon.</p>
            <?php else: ?>
                <div class="home-product-grid">
                    <?php foreach ($featuredProducts as $product): ?>
                        <?php $lowStock = (int) $product['stock'] <= 5; ?>
                        <div class="home-product-card">
                            <div class="home-product-thumb">
                                <?php if ($product['image']): ?>
                                    <img src="assets/images/products/<?= htmlspecialchars($product['image']) ?>"
                                         alt="<?= htmlspecialchars($product['name']) ?>">
                                <?php else: ?>
                                    <span class="no-image">No image</span>
                                <?php endif; ?>
                            </div>
                            <div class="home-product-body">
                                <?php if ($product['category']): ?>
                                    <div class="home-product-cat"><?= htmlspecialchars($product['category']) ?></div>
                                <?php endif; ?>
                                <div class="home-product-name"><?= htmlspecialchars($product['name']) ?></div>
                                <div class="home-product-row">
                                    <span class="home-product-price">&#8369;<?= number_format($product['price'], 2) ?></span>
                                    <span class="<?= $lowStock ? 'home-stock-low' : 'home-stock-ok' ?>">
                                        <?= $lowStock ? 'Only ' . (int) $product['stock'] . ' left' : 'In stock' ?>
                                    </span>
                                </div>
                                <a class="home-add-btn" href="pages/products.php">View in Products</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Why choose MPD -->
        <div class="home-why-wrap">
            <section class="home-section">
                <div class="home-section-head" style="margin-bottom:24px;">
                    <div>
                        <h2>Why customers choose MPD</h2>
                        <p>Not just a supply store — a partner for the whole job.</p>
                    </div>
                </div>
                <div class="home-why-grid">
                    <div class="home-why-item">
                        <span class="home-why-icon">&#9989;</span>
                        <h3>Genuine, tested stock</h3>
                        <p>Every item is sourced from certified suppliers and checked before it ships.</p>
                    </div>
                    <div class="home-why-item">
                        <span class="home-why-icon">&#128666;</span>
                        <h3>Same-area fast delivery</h3>
                        <p>Most orders in our service area arrive within 24 to 48 hours.</p>
                    </div>
                    <div class="home-why-item">
                        <span class="home-why-icon">&#128222;</span>
                        <h3>Real support, real people</h3>
                        <p>Questions about a part or a job? Talk to someone who knows the trade.</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- How ordering works -->
        <section class="home-section">
            <div class="home-section-head">
                <div>
                    <h2>Ordering with MPD</h2>
                    <p>From cart to delivery in three simple steps.</p>
                </div>
            </div>
            <div class="home-steps">
                <div class="home-step">
                    <h3>Add items to your cart</h3>
                    <p>Browse by category or search for the exact part you need.</p>
                </div>
                <div class="home-step">
                    <h3>Check out securely</h3>
                    <p>Pay via GCash, bank transfer, or cash on delivery.</p>
                </div>
                <div class="home-step">
                    <h3>Track your order</h3>
                    <p>Follow your delivery status anytime from My Orders.</p>
                </div>
            </div>
        </section>

        <!-- Contact strip -->
        <section class="home-section" style="padding-top:0;">
            <div class="home-contact-strip">
                <div>
                    <h2>Need help choosing the right part?</h2>
                    <p>Our team replies within the day, every day of the week.</p>
                </div>
                <div class="home-contact-info">
                    <div><span class="label">Call / Viber</span>0917 123 4567</div>
                    <div><span class="label">Email</span>support@mpdelectrical.ph</div>
                    <div><span class="label">Store hours</span>Mon&ndash;Sat, 8AM&ndash;6PM</div>
                </div>
            </div>
        </section>
    </main>

    <footer class="home-footer">
        <div class="home-footer-grid">
            <div>
                <div class="logo" style="margin-bottom:10px;">MPD Electrical Supply & Services</div>
                <p>Quality electrical supplies and dependable installation service for homes, businesses, and contractors.</p>
            </div>
            <div class="home-footer-col">
                <h4>Shop</h4>
                <a href="pages/products.php">All Products</a>
                <a href="pages/cart.php">Cart</a>
            </div>
            <div class="home-footer-col">
                <h4>Account</h4>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="pages/orders.php">My Orders</a>
                <?php else: ?>
                    <a href="login.php">Login</a>
                    <a href="register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="home-footer-bottom">
            <span>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</span>
        </div>
    </footer>

</body>
</html>