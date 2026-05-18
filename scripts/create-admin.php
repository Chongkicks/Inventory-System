<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

include __DIR__ . '/../backend/db.php';

// CLI arguments let you reset a known admin account without editing the script.
$username = $argv[1] ?? 'admin';
$email = $argv[2] ?? 'admin@local.test';
$fullName = $argv[3] ?? 'System Admin';
$password = $argv[4] ?? ('Admin-' . bin2hex(random_bytes(4)));
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$lookup = $conn->prepare("SELECT userID FROM users WHERE username = ? OR email = ? LIMIT 1");
$lookup->bind_param("ss", $username, $email);
$lookup->execute();
$existing = $lookup->get_result()->fetch_assoc();

// Reuse an existing username/email when found; otherwise create a new admin row.
if ($existing) {
    $userId = (int) $existing['userID'];
    $stmt = $conn->prepare("UPDATE users SET fullName = ?, username = ?, email = ?, password = ?, role = 'admin', dateDeleted = NULL WHERE userID = ?");
    $stmt->bind_param("ssssi", $fullName, $username, $email, $hashedPassword, $userId);
    $stmt->execute();
    echo "Admin account reset." . PHP_EOL;
} else {
    $stmt = $conn->prepare("INSERT INTO users(fullName, username, email, password, role) VALUES (?, ?, ?, ?, 'admin')");
    $stmt->bind_param("ssss", $fullName, $username, $email, $hashedPassword);
    $stmt->execute();
    echo "Admin account created." . PHP_EOL;
}

echo "Username: " . $username . PHP_EOL;
echo "Email: " . $email . PHP_EOL;
echo "Password: " . $password . PHP_EOL;

?>
