<?php
require_once __DIR__ . '/auth.php';

// Backward-compatible route: old transaction links now point to the history page.
$query = $_SERVER['QUERY_STRING'] ?? '';

header('Location: reports.php' . ($query !== '' ? '?' . $query : ''));
exit;
