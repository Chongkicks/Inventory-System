<?php

// Backward-compatible redirect for old links to the admin users page.
$query = $_SERVER['QUERY_STRING'] ?? '';

header('Location: admin/users.php' . ($query !== '' ? '?' . $query : ''));
exit;

?>
