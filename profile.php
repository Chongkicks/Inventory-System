<?php
require_once __DIR__ . '/includes/auth.php';

// Require login before showing account details.
require_login('frontend/login.html');

include __DIR__ . '/backend/db.php';
auth_sync_session_user($conn);
require_login('frontend/login.html');
require_once __DIR__ . '/includes/realtime-client.php';
require_once __DIR__ . '/includes/security.php';

// Escape helper for profile fields.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Load the active user's profile from the database, not just the session.
$userId = auth_user_id();
$stmt = $conn->prepare("SELECT userID, fullName, username, email, role, dateCreated FROM users WHERE userID = ? AND dateDeleted IS NULL LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$profileUser = $stmt->get_result()->fetch_assoc();

// If the account was removed after login, send the browser back to login.
if (!$profileUser) {
    auth_redirect('frontend/login.html');
}

$isAdmin = auth_is_admin();
$roleLabel = auth_user_role_label();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketsmart Inventory System | Profile</title>
    <link rel="stylesheet" href="frontend/style.css?v=20260517-16">
</head>
<body class="profile-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Marketsmart Inventory System</p>
            <h1>Profile</h1>
            <p class="muted"><?= e($roleLabel) ?> account settings</p>
        </div>
        <nav aria-label="Primary">
            <a class="nav-link" href="index.php">Dashboard</a>
            <a class="nav-link" href="index.php?stock=1">Stock</a>
            <?php if ($isAdmin): ?>
                <a class="nav-link" href="admin/add-product.php">Add Products</a>
                <a class="nav-link" href="admin/users.php">Users</a>
            <?php endif; ?>
            <a class="nav-link" href="backend/reports.php">History</a>
            <a class="nav-link profile-link is-active" href="profile.php" aria-current="page">
                <span class="profile-avatar" aria-hidden="true"><?= e(auth_user_initials()) ?></span>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="backend/logout.php" data-logout-link>Logout</a>
        </nav>
    </header>

    <main class="profile-main">
        <section class="panel profile-panel">
            <div class="profile-summary">
                <div class="profile-avatar profile-avatar-large" aria-hidden="true"><?= e(auth_user_initials()) ?></div>
                <div>
                    <p class="eyebrow">My Profile</p>
                    <h2><?= e($profileUser['fullName'] ?: $profileUser['username']) ?></h2>
                    <p class="muted"><?= e($profileUser['email']) ?> · <?= e($roleLabel) ?></p>
                </div>
            </div>

            <form class="form-grid profile-form" action="backend/profile.php" method="POST">
                <?= csrf_field() ?>
                <label>
                    Full Name
                    <input type="text" name="fullName" value="<?= e($profileUser['fullName']) ?>" required>
                </label>
                <label>
                    Username
                    <input type="text" name="username" value="<?= e($profileUser['username']) ?>" required>
                </label>
                <label>
                    Email
                    <input type="email" name="email" value="<?= e($profileUser['email']) ?>" required>
                </label>
                <label>
                    Role
                    <input type="text" value="<?= e($roleLabel) ?>" disabled>
                </label>
                <label>
                    Current Password
                    <input type="password" name="current_password" autocomplete="current-password">
                </label>
                <label>
                    New Password
                    <input type="password" name="new_password" autocomplete="new-password">
                </label>
                <label>
                    Confirm New Password
                    <input type="password" name="confirm_password" autocomplete="new-password">
                </label>
                <div class="form-actions">
                    <button type="submit">Save Profile</button>
                </div>
            </form>
        </section>
    </main>
    <?php realtime_client_scripts(); ?>
    <script src="frontend/nav.js?v=20260518-4"></script>
</body>
</html>
