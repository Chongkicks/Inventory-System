<?php

include 'db.php';
require_once __DIR__ . '/../includes/security.php';

// Login accepts only POST requests with a fresh CSRF token.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
    header("Location:../frontend/login.html?msg=csrf");
    exit;
}

// Keep username/email clean, but never sanitize the password before verification.
$username = security_clean_text($_POST['username'] ?? '', 80);
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header("Location:../frontend/login.html?msg=missing");
    exit;
}

// Allow login with either username or email.
$stmt = $conn->prepare("SELECT userID, fullName, username, password, role FROM users WHERE (username = ? OR email = ?) AND dateDeleted IS NULL LIMIT 1");
$stmt->bind_param("ss", $username, $username);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

// On success, store only the user fields needed by auth helpers.
if ($user && password_verify($password, $user['password'])) {
    $_SESSION['userID'] = $user['userID'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['fullName'] = $user['fullName'] ?: $user['username'];
    $_SESSION['role'] = in_array($user['role'] ?? '', ['admin', 'staff'], true) ? $user['role'] : 'staff';

    header("Location:../index.php");
    exit;
}

header("Location:../frontend/login.html?msg=invalid");
exit;

?>
