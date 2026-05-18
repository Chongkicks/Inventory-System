<?php
require_once __DIR__ . '/../includes/auth.php';

// User management is admin-only.
require_login('../frontend/login.html');

include __DIR__ . '/../backend/db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_admin('../index.php');

require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/realtime-client.php';
require_once __DIR__ . '/../includes/security.php';

// Escape helper for table/form output.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Convert database role values into readable labels.
function user_role_label($role)
{
    return $role === 'admin' ? 'Admin' : 'Staff';
}

// Build search and pagination state for the users table.
$usersPerPage = 8;
$usersPageRequest = pagination_current_page($_GET['users_page'] ?? 1);
$userSearch = trim($_GET['user_search'] ?? '');
$currentUserId = auth_user_id();
$userConditions = ['dateDeleted IS NULL'];
$userParams = [];
$userTypes = '';

// Search matches visible user fields.
if ($userSearch !== '') {
    $userConditions[] = '(fullName LIKE ? OR username LIKE ? OR email LIKE ? OR role LIKE ?)';
    $searchPattern = '%' . $userSearch . '%';
    $userParams = [$searchPattern, $searchPattern, $searchPattern, $searchPattern];
    $userTypes = 'ssss';
}

$userWhere = implode(' AND ', $userConditions);

// Count users first so pagination can clamp bad page numbers.
if (!empty($userParams)) {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE {$userWhere}");
    $countStmt->bind_param($userTypes, ...$userParams);
    $countStmt->execute();
    $totalUsers = (int) $countStmt->get_result()->fetch_assoc()['total'];
} else {
    $totalUsers = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE {$userWhere}")->fetch_assoc()['total'];
}

[$usersPage, $usersTotalPages, $usersOffset] = pagination_page_state($usersPageRequest, $totalUsers, $usersPerPage);

$usersSql = "SELECT userID, fullName, username, email, role, dateCreated FROM users WHERE {$userWhere} ORDER BY role ASC, fullName ASC, username ASC LIMIT {$usersPerPage} OFFSET {$usersOffset}";

// Reuse the same filters for the actual page query.
if (!empty($userParams)) {
    $usersStmt = $conn->prepare($usersSql);
    $usersStmt->bind_param($userTypes, ...$userParams);
    $usersStmt->execute();
    $users = $usersStmt->get_result();
} else {
    $users = $conn->query($usersSql);
}

$usersPagerQuery = pagination_query_params(['users_page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketsmart Inventory System | Users</title>
    <link rel="stylesheet" href="../frontend/style.css?v=20260518-1">
</head>
<body class="users-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Marketsmart Inventory System</p>
            <h1>Users</h1>
            <p class="muted">Manage admin and staff access</p>
        </div>
        <nav aria-label="Primary">
            <a class="nav-link" href="../index.php">Dashboard</a>
            <a class="nav-link" href="../index.php?stock=1">Stock</a>
            <a class="nav-link" href="add-product.php">Add Products</a>
            <a class="nav-link is-active" href="users.php" aria-current="page">Users</a>
            <a class="nav-link" href="../backend/reports.php">History</a>
            <a class="nav-link profile-link" href="../profile.php">
                <span class="profile-avatar" aria-hidden="true"><?= e(auth_user_initials()) ?></span>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="../backend/logout.php" data-logout-link>Logout</a>
        </nav>
    </header>

    <main>
        <section class="users-grid">
            <div class="panel users-list-panel">
                <div class="panel-header users-list-header">
                    <div>
                        <p class="eyebrow">Users</p>
                        <h2>Manage Users</h2>
                    </div>
                    <form class="product-search" action="users.php" method="GET" aria-label="Search users" data-live-search-form>
                        <input type="hidden" name="users_page" value="1">
                        <label class="search-field">
                            <span>Search</span>
                            <input type="search" name="user_search" value="<?= e($userSearch) ?>" placeholder="Name, email, username, or role" autocomplete="off" data-live-search-input>
                        </label>
                        <div class="search-actions">
                            <button type="submit" class="button secondary">Search</button>
                            <?php if ($userSearch !== ''): ?>
                                <a class="button secondary" href="<?= e(pagination_url('users.php', ['users_page' => 1], 'users')) ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="table-wrap">
                    <table class="users-table" id="users">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while ($user = $users->fetch_assoc()): ?>
                                    <?php
                                    $userId = (int) $user['userID'];
                                    $updateFormId = 'update-user-' . $userId;
                                    $deleteConfirmMessage = 'Delete user "' . $user['username'] . '"?';
                                    $isCurrentUser = $userId === $currentUserId;
                                    ?>
                                    <tr>
                                        <td>
                                            <input form="<?= e($updateFormId) ?>" type="text" name="fullName" value="<?= e($user['fullName']) ?>" required>
                                        </td>
                                        <td>
                                            <input form="<?= e($updateFormId) ?>" type="text" name="username" value="<?= e($user['username']) ?>" required>
                                        </td>
                                        <td>
                                            <input form="<?= e($updateFormId) ?>" type="email" name="email" value="<?= e($user['email']) ?>" required>
                                        </td>
                                        <td>
                                            <?php if ($isCurrentUser): ?>
                                                <span class="role-pill role-admin"><?= e(user_role_label($user['role'])) ?></span>
                                                <input form="<?= e($updateFormId) ?>" type="hidden" name="role" value="<?= e($user['role']) ?>">
                                            <?php else: ?>
                                                <select form="<?= e($updateFormId) ?>" name="role" required>
                                                    <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(date('M d, Y', strtotime($user['dateCreated']))) ?></td>
                                        <td>
                                            <div class="actions users-actions">
                                                <form id="<?= e($updateFormId) ?>" action="../backend/user-actions.php" method="POST" data-ajax-form>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="userID" value="<?= $userId ?>">
                                                    <button type="submit" class="button secondary">Save</button>
                                                </form>
                                                <?php if ($isCurrentUser): ?>
                                                    <button type="button" class="button secondary danger" disabled>Delete</button>
                                                <?php else: ?>
                                                    <form action="../backend/user-actions.php" method="POST" onsubmit="return confirm(<?= e(json_encode($deleteConfirmMessage)) ?>);" data-ajax-form>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="userID" value="<?= $userId ?>">
                                                        <button type="submit" class="button secondary danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="empty-state" colspan="6">No users found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?= pagination_render('users.php', 'users_page', $usersPage, $totalUsers, $usersPerPage, $usersPagerQuery, 'users', 'users') ?>
            </div>
        </section>
    </main>

    <?php realtime_client_scripts(); ?>
    <script src="../frontend/nav.js?v=20260518-4"></script>
</body>
</html>
