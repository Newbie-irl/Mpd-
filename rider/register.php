<?php
session_start();
require_once '../config/database.php';

// If already logged in, don't let them register again — bounce to the
// right place for their role.
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'rider') {
        header('Location: rider-dashboard.php');
    } elseif ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../index.php');
    }
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName        = trim($_POST['full_name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '') {
        $errors[] = "Full name is required.";
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email is required.";
    }
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = "An account with that email already exists.";
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, role, created_at)
             VALUES (:full_name, :email, :password, 'rider', NOW())"
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int) $pdo->lastInsertId();
        $_SESSION['user_id']   = $userId;
        $_SESSION['role']      = 'rider';
        $_SESSION['full_name'] = $fullName;

        header('Location: rider-dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Sign Up - MPD Electrical Supply &amp; Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

    <header>
        <nav class="navbar">
            <div class="logo">MPD Electrical Supply & Services</div>
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="../login.php">Login</a></li>
            </ul>
        </nav>
    </header>

    <main class="storefront-main" style="max-width: 480px; margin: 0 auto;">
        <h1>Become a Rider</h1>
        <p class="field-hint">Sign up to start receiving delivery assignments.</p>

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
            <form action="register.php" method="POST" class="admin-form">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Sign Up as Rider</button>
                </div>
            </form>
        </section>

        <p class="field-hint">Already have a rider account? <a href="../login.php">Log in</a>.</p>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> MPD Electrical Supply & Services. All rights reserved.</p>
    </footer>

</body>
</html>
