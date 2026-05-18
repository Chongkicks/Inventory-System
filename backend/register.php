<?php

include 'db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

// Registration uses a static HTML form, so validate CSRF here before reading data.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
    header("Location:../frontend/register.html?msg=csrf");
    exit;
}

// Clean user-editable text fields and keep passwords raw for hashing/comparison.
$fullName = security_clean_text($_POST['fullName'] ?? '', 80);
$username = security_clean_text($_POST['username'] ?? '', 80);
$email = security_clean_email($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($fullName === '' || $username === '' || $email === '' || $password === '') {
    header("Location:../frontend/register.html?msg=missing");
    exit;
}

if (!security_valid_name($fullName)) {
    header("Location:../frontend/register.html?msg=invalidName");
    exit;
}

if (!security_valid_username($username)) {
    header("Location:../frontend/register.html?msg=invalidUsername");
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location:../frontend/register.html?msg=invalidEmail");
    exit;
}

if (!security_valid_password($password)) {
    header("Location:../frontend/register.html?msg=weakPassword");
    exit;
}

if ($password !== $confirmPassword) {
    header("Location:../frontend/register.html?msg=passwordMismatch");
    exit;
}

// Block duplicates before calling the stored procedure to provide a friendly message.
$check = $conn->prepare("SELECT userID FROM users WHERE (username = ? OR email = ?) AND dateDeleted IS NULL LIMIT 1");
$check->bind_param("ss", $username, $email);
$check->execute();
$existing = $check->get_result();

if ($existing && $existing->num_rows > 0) {
    header("Location:../frontend/register.html?msg=exist");
    exit;
}

// New self-registered accounts start as staff.
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$role = 'staff';
$userId = db_call_insert_id($conn, "CALL sp_insertUser(?, ?, ?, ?, ?)", "sssss", [$fullName, $username, $email, $hashedPassword, $role]);

if ($userId > 0) {
    require_once __DIR__ . '/pusher.php';
    // Alert admins that a new staff account was created.
    trigger_inventory_notification([
        'title' => 'User registered',
        'message' => $fullName . ' registered a staff account.',
        'level' => 'info',
        'type' => 'user_registered',
        'userID' => $userId,
        'audienceRoles' => ['admin']
    ], inventory_request_socket_id());

    header("Location:../frontend/login.html?msg=registered");
    exit;
}

header("Location:../frontend/register.html?msg=error");
exit;

?>
