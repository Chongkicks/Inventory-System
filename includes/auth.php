<?php

require_once __DIR__ . '/session.php';

// Start the shared session before any auth helper reads user data.
inventory_start_session();

// Return the logged-in user id, or 0 when no user is authenticated.
function auth_user_id(): int
{
    return (int) ($_SESSION['userID'] ?? 0);
}

// Normalize session role so invalid/missing values cannot grant admin access.
function auth_user_role(): string
{
    $role = (string) ($_SESSION['role'] ?? 'staff');

    return in_array($role, ['admin', 'staff'], true) ? $role : 'staff';
}

// Human-readable role label used by page headers and profile views.
function auth_user_role_label(): string
{
    return auth_user_role() === 'admin' ? 'Admin' : 'Staff';
}

// Build a compact avatar label from the user's name or username.
function auth_user_initials(): string
{
    $name = trim((string) ($_SESSION['fullName'] ?? $_SESSION['username'] ?? 'User'));

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'U';
}

// Single place to check admin access.
function auth_is_admin(): bool
{
    return auth_user_role() === 'admin';
}

// Refresh session data from the database and log the user out if their account was removed.
function auth_sync_session_user(mysqli $conn): void
{
    $userId = auth_user_id();

    if ($userId <= 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT fullName, username, role FROM users WHERE userID = ? AND dateDeleted IS NULL LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['fullName'] = $user['fullName'] ?: $user['username'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = in_array($user['role'] ?? '', ['admin', 'staff'], true) ? $user['role'] : 'staff';
}

// Redirect helper used by auth guards.
function auth_redirect(string $path, array $params = []): void
{
    $query = http_build_query($params);
    $separator = str_contains($path, '?') ? '&' : '?';

    header('Location:' . $path . ($query !== '' ? $separator . $query : ''));
    exit;
}

// Stop page execution when the visitor is not logged in.
function require_login(string $loginPath): void
{
    if (auth_user_id() <= 0) {
        auth_redirect($loginPath);
    }
}

// Stop page execution when a non-admin opens an admin-only route.
function require_admin(string $redirectPath, string $message = 'Access denied. Admin account required.'): void
{
    if (!auth_is_admin()) {
        auth_redirect($redirectPath, ['message' => $message]);
    }
}

?>
