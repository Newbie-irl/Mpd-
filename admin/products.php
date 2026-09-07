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

// ---- All products ----
$products = $pdo->query("SELECT * FROM products ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - MPD Electrical Supply & Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            </nav>
            <div class="admin-navbar-actions">
                <a href="../index.php">&larr; Back to site</a>
                <a href="../logout.php">Logout</a>
            </div>
        </header>

        <main class="admin-main">

            <div class="admin-topbar">
                <h1>Products</h1>
                <span>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>

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
                        <input type="text" id="category" name="category"
                               placeholder="e.g. Wiring, Switches, Lighting"
                               value="<?= htmlspecialchars($editProduct['category'] ?? '') ?>">
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
                        <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                        <?php if ($editProduct && $editProduct['image']): ?>
                            <p class="field-hint">Current image: <?= htmlspecialchars($editProduct['image']) ?> (leave blank to keep it)</p>
                        <?php endif; ?>
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
                <h2>All Products (<?= count($products) ?>)</h2>

                <?php if (empty($products)): ?>
                    <p class="empty-state">No products yet — add your first one above.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
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
                                        <?= (int) $product['stock'] ?>
                                        <?php if ($product['stock'] <= 5): ?>
                                            <span class="low-stock-badge">Low</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="admin-table-actions">
                                        <a href="products.php?edit=<?= (int) $product['id'] ?>" class="btn-edit">Edit</a>
                                        <a href="products.php?delete=<?= (int) $product['id'] ?>" class="btn-danger"
                                           onclick="return confirm('Delete this product? This cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

        </main>
    </div>

</body>
</html>