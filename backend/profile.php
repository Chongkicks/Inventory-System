<?php
require_once 'auth.php';
include 'db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

// Redirect profile updates back to the profile page with a status message.
function redirect_profile(string $message): void
{
    auth_redirect('../profile.php', ['message' => $message]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_profile('Invalid profile update');
}

if (!csrf_is_valid()) {
    redirect_profile('Security token expired. Please try again.');
}

// Clean profile fields; password fields stay raw for password_verify/password_hash.
$userId = auth_user_id();
$fullName = security_clean_text($_POST['fullName'] ?? '', 80);
$username = security_clean_text($_POST['username'] ?? '', 80);
$email = security_clean_email($_POST['email'] ?? '');
$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($fullName === '' || $username === '' || $email === '') {
    redirect_profile('Please complete your profile details');
}

if (!security_valid_name($fullName)) {
    redirect_profile('Name contains invalid characters');
}

if (!security_valid_username($username)) {
    redirect_profile('Username must be 3-80 characters using letters, numbers, dot, dash, or underscore');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_profile('Please enter a valid email address');
}

$lookup = $conn->prepare("SELECT userID, password FROM users WHERE userID = ? AND dateDeleted IS NULL LIMIT 1");
$lookup->bind_param("i", $userId);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();

if (!$user) {
    redirect_profile('User not found');
}

// Ensure the new username/email does not belong to another active user.
$duplicate = $conn->prepare("SELECT userID FROM users WHERE (username = ? OR email = ?) AND userID <> ? AND dateDeleted IS NULL LIMIT 1");
$duplicate->bind_param("ssi", $username, $email, $userId);
$duplicate->execute();

if ($duplicate->get_result()->num_rows > 0) {
    redirect_profile('Username or email already exists');
}

$wantsPasswordChange = $newPassword !== '' || $confirmPassword !== '';

if ($wantsPasswordChange) {
    // Password changes require the current password and matching new password fields.
    if ($currentPassword === '' || !password_verify($currentPassword, $user['password'])) {
        redirect_profile('Current password is required to change password');
    }

    if ($newPassword !== $confirmPassword) {
        redirect_profile('New passwords do not match');
    }

    if (!security_valid_password($newPassword)) {
        redirect_profile('New password must be at least 6 characters');
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    db_call($conn, "CALL sp_updateProfilePassword(?, ?, ?, ?, ?)", "issss", [$userId, $fullName, $username, $email, $hashedPassword]);
} else {
    // Update profile details without touching the existing password hash.
    db_call($conn, "CALL sp_updateProfile(?, ?, ?, ?)", "isss", [$userId, $fullName, $username, $email]);
}

// Keep the current session display data in sync immediately.
$_SESSION['fullName'] = $fullName;
$_SESSION['username'] = $username;

require_once __DIR__ . '/pusher.php';
// Let admins know when a profile changes.
trigger_inventory_notification([
    'title' => 'Profile updated',
    'message' => $fullName . ' updated their profile.',
    'level' => 'info',
    'type' => 'profile_updated',
    'userID' => $userId,
    'audienceRoles' => ['admin']
], inventory_request_socket_id());

redirect_profile('Profile updated');
?>
