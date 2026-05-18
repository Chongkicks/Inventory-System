<?php
require_once 'auth.php';
include 'db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_admin('../index.php');
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

// Shared response for admin user actions.
function respond_users(string $message, bool $ok = false): void
{
    action_response($message, '../admin/users.php?' . http_build_query(['message' => $message]), $ok);
}

// Only allow known roles; anything else becomes staff.
function normalize_user_role($role): string
{
    return in_array($role, ['admin', 'staff'], true) ? $role : 'staff';
}

// Count active admins so the app never loses its last admin account.
function active_admin_count(mysqli $conn): int
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND dateDeleted IS NULL");

    return $result ? (int) $result->fetch_assoc()['total'] : 0;
}

// Small wrapper keeps notification setup out of the action branches.
function notify_user_change(array $payload): void
{
    require_once __DIR__ . '/pusher.php';
    trigger_inventory_notification($payload, inventory_request_socket_id());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_users('Invalid user action');
}

if (!csrf_is_valid()) {
    respond_users('Security token expired. Please try again.');
}

$action = security_clean_text($_POST['action'] ?? '', 20);

if ($action === 'update') {
    // Update an existing user's profile and role from the users table.
    $userId = security_int($_POST['userID'] ?? 0, 0);
    $fullName = security_clean_text($_POST['fullName'] ?? '', 80);
    $username = security_clean_text($_POST['username'] ?? '', 80);
    $email = security_clean_email($_POST['email'] ?? '');
    $role = normalize_user_role($_POST['role'] ?? 'staff');

    if ($userId <= 0 || $fullName === '' || $username === '' || $email === '') {
        respond_users('Invalid user details');
    }

    if (!security_valid_name($fullName)) {
        respond_users('Name contains invalid characters');
    }

    if (!security_valid_username($username)) {
        respond_users('Username must be 3-80 characters using letters, numbers, dot, dash, or underscore');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond_users('Please enter a valid email address');
    }

    $lookup = $conn->prepare("SELECT userID, role FROM users WHERE userID = ? AND dateDeleted IS NULL LIMIT 1");
    $lookup->bind_param("i", $userId);
    $lookup->execute();
    $targetUser = $lookup->get_result()->fetch_assoc();

    if (!$targetUser) {
        respond_users('User not found');
    }

    // Prevent the current admin from removing their own admin permission.
    if ($userId === auth_user_id() && $role !== 'admin') {
        respond_users('You cannot change your own admin role');
    }

    // Prevent demoting the last active admin.
    if ($targetUser['role'] === 'admin' && $role !== 'admin' && active_admin_count($conn) <= 1) {
        respond_users('At least one admin account is required');
    }

    $check = $conn->prepare("SELECT userID FROM users WHERE (username = ? OR email = ?) AND userID <> ? LIMIT 1");
    $check->bind_param("ssi", $username, $email, $userId);
    $check->execute();

    // Usernames and emails must stay unique across active and deleted records.
    if ($check->get_result()->num_rows > 0) {
        respond_users('Username or email already exists');
    }

    db_call($conn, "CALL sp_updateUser(?, ?, ?, ?, ?)", "issss", [$userId, $fullName, $username, $email, $role]);

    if ($userId === auth_user_id()) {
        $_SESSION['fullName'] = $fullName;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
    }

    notify_user_change([
        'title' => 'User updated',
        'message' => $fullName . ' profile access was updated.',
        'level' => 'info',
        'type' => 'user_updated',
        'userID' => $userId,
        'audienceRoles' => ['admin']
    ]);

    respond_users('User updated', true);
}

if ($action === 'delete') {
    // Soft-delete a user while protecting the current user and last admin.
    $userId = security_int($_POST['userID'] ?? 0, 0);

    if ($userId <= 0) {
        respond_users('Invalid user');
    }

    if ($userId === auth_user_id()) {
        respond_users('You cannot delete your own account');
    }

    $lookup = $conn->prepare("SELECT userID, role FROM users WHERE userID = ? AND dateDeleted IS NULL LIMIT 1");
    $lookup->bind_param("i", $userId);
    $lookup->execute();
    $targetUser = $lookup->get_result()->fetch_assoc();

    if (!$targetUser) {
        respond_users('User not found');
    }

    if ($targetUser['role'] === 'admin' && active_admin_count($conn) <= 1) {
        respond_users('At least one admin account is required');
    }

    db_call($conn, "CALL sp_deleteUser(?)", "i", [$userId]);

    notify_user_change([
        'title' => 'User deleted',
        'message' => 'A user account was removed.',
        'level' => 'danger',
        'type' => 'user_deleted',
        'userID' => $userId,
        'audienceRoles' => ['admin']
    ]);

    respond_users('User deleted', true);
}

respond_users('Invalid user action');
?>
